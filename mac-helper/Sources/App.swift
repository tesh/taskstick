// TaskStickReminders — menu-bar helper that syncs TaskStick (tasks.tesh.ai)
// to Apple Reminders using EventKit.
//
// Why this exists instead of a server-side CalDAV push: Apple removed
// Reminders from iCloud's CalDAV protocol in iOS 13 / macOS Catalina
// (2019). caldav.icloud.com still answers PROPFIND/MKCALENDAR/PUT for
// backward compatibility, but the modern Reminders app never reads from
// or writes to that store — every third-party CalDAV reminders client
// hits this same wall. EventKit is the only API that still has real
// Reminders access, and it only runs on-device — hence this app.
//
// Google Tasks is the source of truth for tasks it already knows about:
// title/notes/due date/list membership for those always flow server →
// Reminders, and only completion status flows back. A reminder created
// directly in Apple Reminders (no matching Google task yet) is the one
// exception — it gets pushed up to Google Tasks once, at creation, so
// both directions of "add a new task" work without needing real
// conflict resolution for anything already synced.

import Cocoa
import EventKit

// MARK: - Config

enum Config {
    private static let defaults = UserDefaults.standard
    private static let serverURLKey = "serverURL"
    private static let tokenKey = "exportToken"

    static var serverURL: String {
        get { defaults.string(forKey: serverURLKey) ?? "https://tasks.tesh.ai" }
        set { defaults.set(newValue, forKey: serverURLKey) }
    }

    static var exportToken: String {
        get { defaults.string(forKey: tokenKey) ?? "" }
        set { defaults.set(newValue, forKey: tokenKey) }
    }

    static var isConfigured: Bool { !exportToken.isEmpty }
}

// MARK: - Local link store (google task id -> reminder identifier)
// Equivalent of the server's old apple_reminder_links table, just kept
// locally now since the mapping only matters to this one Mac.

struct LinkEntry: Codable {
    var reminderId: String
    var listId: String
    var googleUpdated: String
    var lastKnownCompleted: Bool
}

final class LinkStore {
    private let fileURL: URL
    private var links: [String: LinkEntry] = [:]

    init() {
        let appSupport = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        let dir = appSupport.appendingPathComponent("TaskStickReminders", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        fileURL = dir.appendingPathComponent("links.json")
        load()
    }

    private func load() {
        guard let data = try? Data(contentsOf: fileURL),
              let decoded = try? JSONDecoder().decode([String: LinkEntry].self, from: data) else { return }
        links = decoded
    }

    private func save() {
        guard let data = try? JSONEncoder().encode(links) else { return }
        try? data.write(to: fileURL, options: .atomic)
    }

    func get(_ googleTaskId: String) -> LinkEntry? { links[googleTaskId] }
    func set(_ googleTaskId: String, _ entry: LinkEntry) { links[googleTaskId] = entry; save() }
    func remove(_ googleTaskId: String) { links.removeValue(forKey: googleTaskId); save() }

    /// Every locally-known task id for a given Google list, so the sync
    /// pass can tell which ones went missing (deleted/moved) server-side.
    func taskIds(forListId listId: String) -> [String] {
        links.filter { $0.value.listId == listId }.map { $0.key }
    }

    /// True if this Reminders item already came from (or was already
    /// pushed to) Google Tasks — used to find reminders a person created
    /// directly in Reminders, which have no link yet.
    func isReminderLinked(_ reminderId: String) -> Bool {
        links.values.contains { $0.reminderId == reminderId }
    }
}

// MARK: - TaskStick API models

struct ExportTask: Decodable {
    let id: String
    let title: String
    let notes: String?
    let due: String?
    let status: String
    let updated: String
}

struct ExportList: Decodable {
    let id: String
    let title: String
    let tasks: [ExportTask]
}

struct ExportResponse: Decodable {
    let lists: [ExportList]?
    let error: String?
}

struct CreatedTask: Decodable {
    let clientId: String
    let googleTaskId: String
    let updated: String
}

struct CreateTasksResponse: Decodable {
    let created: [CreatedTask]?
    let errors: [String]?
}

// MARK: - TaskStick API client

final class TaskStickClient {
    struct APIError: Error, LocalizedError {
        let message: String
        var errorDescription: String? { message }
    }

    private func request(path: String, method: String, body: [String: Any]? = nil) async throws -> Data {
        guard let url = URL(string: Config.serverURL + path) else {
            throw APIError(message: "Invalid server URL")
        }
        var req = URLRequest(url: url)
        req.httpMethod = method
        req.setValue("Bearer \(Config.exportToken)", forHTTPHeaderField: "Authorization")
        if let body = body {
            req.httpBody = try JSONSerialization.data(withJSONObject: body)
            req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        }
        let (data, response) = try await URLSession.shared.data(for: req)
        guard let http = response as? HTTPURLResponse else {
            throw APIError(message: "No response from server")
        }
        guard (200..<300).contains(http.statusCode) else {
            let msg = (try? JSONDecoder().decode([String: String].self, from: data))?["error"]
            throw APIError(message: msg ?? "Server returned HTTP \(http.statusCode)")
        }
        return data
    }

    func fetchExport() async throws -> [ExportList] {
        let data = try await request(path: "/api/apple-export.php", method: "GET")
        let decoded = try JSONDecoder().decode(ExportResponse.self, from: data)
        if let err = decoded.error { throw APIError(message: err) }
        return decoded.lists ?? []
    }

    func reportResult(pushed: Int, errors: [String]) async {
        _ = try? await request(path: "/api/apple-export.php", method: "POST", body: [
            "action": "report_result",
            "pushed": pushed,
            "errors": errors,
        ])
    }

    func reportCompletions(_ completions: [[String: String]]) async {
        guard !completions.isEmpty else { return }
        _ = try? await request(path: "/api/apple-export.php", method: "POST", body: [
            "action": "complete_tasks",
            "completions": completions,
        ])
    }

    /// Pushes reminders created directly in Apple Reminders (no Google
    /// Task yet) up to Google Tasks. Each item carries a clientId (the
    /// reminder's own identifier) so the response's created tasks can be
    /// matched back to the right EKReminder without guessing by title.
    func createTasks(_ creates: [[String: Any]]) async -> (created: [CreatedTask], errors: [String]) {
        guard !creates.isEmpty else { return ([], []) }
        guard let data = try? await request(path: "/api/apple-export.php", method: "POST", body: [
            "action": "create_tasks",
            "creates": creates,
        ]) else { return ([], ["Could not reach server to create new tasks"]) }
        let decoded = try? JSONDecoder().decode(CreateTasksResponse.self, from: data)
        return (decoded?.created ?? [], decoded?.errors ?? [])
    }
}

// MARK: - Sync engine

final class SyncEngine {
    private let store = EKEventStore()
    private let links = LinkStore()
    private let client = TaskStickClient()

    private(set) var lastSyncedAt: Date?
    private(set) var lastError: String?
    private var syncing = false

    func requestAccess() async -> Bool {
        if #available(macOS 14.0, *) {
            return (try? await store.requestFullAccessToReminders()) ?? false
        } else {
            return await withCheckedContinuation { cont in
                store.requestAccess(to: .reminder) { granted, _ in cont.resume(returning: granted) }
            }
        }
    }

    /// Finds a Reminders list (EKCalendar) by exact title, or creates one
    /// on the account's iCloud source — this is the step that never
    /// actually worked over CalDAV; EventKit does it natively and
    /// reliably.
    private func findOrCreateCalendar(title: String) throws -> EKCalendar {
        if let existing = store.calendars(for: .reminder).first(where: { $0.title == title }) {
            return existing
        }
        let calendar = EKCalendar(for: .reminder, eventStore: store)
        calendar.title = title
        let iCloudSource = store.sources.first(where: { $0.sourceType == .calDAV && $0.title.localizedCaseInsensitiveContains("iCloud") })
            ?? store.defaultCalendarForNewReminders()?.source
            ?? store.sources.first(where: { $0.sourceType == .local })
        guard let source = iCloudSource else {
            throw TaskStickClient.APIError(message: "No usable Reminders account source found")
        }
        calendar.source = source
        try store.saveCalendar(calendar, commit: true)
        return calendar
    }

    private func dueDateComponents(from iso: String?) -> DateComponents? {
        guard let iso = iso, iso.count >= 10 else { return nil }
        let ymd = iso.prefix(10).split(separator: "-")
        guard ymd.count == 3, let y = Int(ymd[0]), let m = Int(ymd[1]), let d = Int(ymd[2]) else { return nil }
        return DateComponents(year: y, month: m, day: d)
    }

    /// Reverse of dueDateComponents — Google Tasks due dates are always
    /// UTC midnight of a calendar date (see index.html's own due-date
    /// handling for the same convention).
    private func dueISOString(from components: DateComponents?) -> String? {
        guard let y = components?.year, let m = components?.month, let d = components?.day else { return nil }
        return String(format: "%04d-%02d-%02dT00:00:00.000Z", y, m, d)
    }

    private func fetchReminders(in calendar: EKCalendar) async -> [EKReminder] {
        let predicate = store.predicateForReminders(in: [calendar])
        return await withCheckedContinuation { cont in
            store.fetchReminders(matching: predicate) { reminders in
                cont.resume(returning: reminders ?? [])
            }
        }
    }

    @MainActor
    func performSync() async {
        guard !syncing, Config.isConfigured else { return }
        syncing = true
        defer { syncing = false }

        var pushed = 0
        var errors: [String] = []
        var completions: [[String: String]] = []
        var pendingCreates: [(reminder: EKReminder, listId: String, clientId: String, title: String, notes: String?, due: String?)] = []

        do {
            let lists = try await client.fetchExport()

            for list in lists {
                let calendar: EKCalendar
                do {
                    calendar = try findOrCreateCalendar(title: list.title)
                } catch {
                    errors.append("\"\(list.title)\": could not create Reminders list — \(error.localizedDescription)")
                    continue
                }

                var currentTaskIds: Set<String> = []

                for task in list.tasks {
                    currentTaskIds.insert(task.id)
                    let isCompleted = task.status == "completed"

                    if let link = links.get(task.id) {
                        // Already exists — reconcile completion status
                        // observed locally (Reminders → Google direction)
                        // before deciding whether to push an update.
                        if let reminder = store.calendarItem(withIdentifier: link.reminderId) as? EKReminder {
                            if reminder.isCompleted && !link.lastKnownCompleted {
                                completions.append(["listId": list.id, "taskId": task.id])
                                links.set(task.id, LinkEntry(reminderId: link.reminderId, listId: list.id, googleUpdated: task.updated, lastKnownCompleted: true))
                                continue // Google is about to know it's done; don't also overwrite the reminder from a stale server copy this pass.
                            }
                            if link.googleUpdated != task.updated {
                                reminder.title = task.title
                                reminder.notes = task.notes
                                reminder.dueDateComponents = dueDateComponents(from: task.due)
                                reminder.isCompleted = isCompleted
                                do {
                                    try store.save(reminder, commit: true)
                                    pushed += 1
                                    links.set(task.id, LinkEntry(reminderId: link.reminderId, listId: list.id, googleUpdated: task.updated, lastKnownCompleted: isCompleted))
                                } catch {
                                    errors.append("\"\(task.title)\": \(error.localizedDescription)")
                                }
                            }
                        } else {
                            // The reminder vanished on the Mac side (user
                            // deleted it locally) — drop the stale link so
                            // it gets recreated fresh below next pass.
                            links.remove(task.id)
                        }
                    } else {
                        let reminder = EKReminder(eventStore: store)
                        reminder.calendar = calendar
                        reminder.title = task.title
                        reminder.notes = task.notes
                        reminder.dueDateComponents = dueDateComponents(from: task.due)
                        reminder.isCompleted = isCompleted
                        do {
                            try store.save(reminder, commit: true)
                            pushed += 1
                            links.set(task.id, LinkEntry(reminderId: reminder.calendarItemIdentifier, listId: list.id, googleUpdated: task.updated, lastKnownCompleted: isCompleted))
                        } catch {
                            errors.append("\"\(task.title)\": \(error.localizedDescription)")
                        }
                    }
                }

                // Reconcile: a task this Mac previously pushed for this
                // list that Google no longer has (deleted, or moved —
                // Google issues a new task id on every cross-list move)
                // is stale; remove its reminder rather than leaving an
                // orphan behind forever.
                for staleId in links.taskIds(forListId: list.id) where !currentTaskIds.contains(staleId) {
                    if let link = links.get(staleId),
                       let reminder = store.calendarItem(withIdentifier: link.reminderId) as? EKReminder {
                        try? store.remove(reminder, commit: true)
                    }
                    links.remove(staleId)
                }

                // Discover reminders created directly in Reminders (not
                // pushed from Google Tasks, so no link exists yet) so
                // they flow into Google Tasks too, not just completions.
                for reminder in await fetchReminders(in: calendar) {
                    guard !links.isReminderLinked(reminder.calendarItemIdentifier) else { continue }
                    guard let title = reminder.title, !title.isEmpty else { continue }
                    pendingCreates.append((
                        reminder: reminder,
                        listId: list.id,
                        clientId: reminder.calendarItemIdentifier,
                        title: title,
                        notes: reminder.notes,
                        due: dueISOString(from: reminder.dueDateComponents)
                    ))
                }
            }

            if !pendingCreates.isEmpty {
                let payload: [[String: Any]] = pendingCreates.map { item in
                    var dict: [String: Any] = ["listId": item.listId, "clientId": item.clientId, "title": item.title]
                    if let notes = item.notes, !notes.isEmpty { dict["notes"] = notes }
                    if let due = item.due { dict["due"] = due }
                    return dict
                }
                let (created, createErrors) = await client.createTasks(payload)
                errors.append(contentsOf: createErrors)
                for result in created {
                    guard let match = pendingCreates.first(where: { $0.clientId == result.clientId }) else { continue }
                    links.set(result.googleTaskId, LinkEntry(
                        reminderId: match.reminder.calendarItemIdentifier,
                        listId: match.listId,
                        googleUpdated: result.updated,
                        lastKnownCompleted: match.reminder.isCompleted
                    ))
                    pushed += 1
                    if match.reminder.isCompleted {
                        completions.append(["listId": match.listId, "taskId": result.googleTaskId])
                    }
                }
            }

            await client.reportCompletions(completions)
            await client.reportResult(pushed: pushed, errors: errors)
            lastSyncedAt = Date()
            lastError = errors.first
        } catch {
            lastError = error.localizedDescription
            await client.reportResult(pushed: pushed, errors: [error.localizedDescription])
        }

        NotificationCenter.default.post(name: .taskStickSyncDidFinish, object: nil)
    }
}

extension Notification.Name {
    static let taskStickSyncDidFinish = Notification.Name("taskStickSyncDidFinish")
}

// MARK: - Settings window

final class SettingsWindowController: NSWindowController {
    private let serverField = NSTextField(string: Config.serverURL)
    private let tokenField = NSTextField(string: Config.exportToken)

    convenience init() {
        let window = NSWindow(
            contentRect: NSRect(x: 0, y: 0, width: 460, height: 240),
            styleMask: [.titled, .closable],
            backing: .buffered,
            defer: false
        )
        window.title = "TaskStick Reminders Settings"
        window.center()
        self.init(window: window)
        buildUI()
    }

    private func buildUI() {
        guard let contentView = window?.contentView else { return }

        let serverLabel = NSTextField(labelWithString: "TaskStick server URL:")
        let tokenLabel = NSTextField(labelWithString: "Setup token:")
        let saveButton = NSButton(title: "Save", target: self, action: #selector(save))
        saveButton.keyEquivalent = "\r"

        for view in [serverLabel, serverField, tokenLabel, tokenField, saveButton] {
            view.translatesAutoresizingMaskIntoConstraints = false
            contentView.addSubview(view)
        }

        NSLayoutConstraint.activate([
            serverLabel.topAnchor.constraint(equalTo: contentView.topAnchor, constant: 20),
            serverLabel.leadingAnchor.constraint(equalTo: contentView.leadingAnchor, constant: 20),

            serverField.topAnchor.constraint(equalTo: serverLabel.bottomAnchor, constant: 6),
            serverField.leadingAnchor.constraint(equalTo: contentView.leadingAnchor, constant: 20),
            serverField.trailingAnchor.constraint(equalTo: contentView.trailingAnchor, constant: -20),

            tokenLabel.topAnchor.constraint(equalTo: serverField.bottomAnchor, constant: 16),
            tokenLabel.leadingAnchor.constraint(equalTo: contentView.leadingAnchor, constant: 20),

            tokenField.topAnchor.constraint(equalTo: tokenLabel.bottomAnchor, constant: 6),
            tokenField.leadingAnchor.constraint(equalTo: contentView.leadingAnchor, constant: 20),
            tokenField.trailingAnchor.constraint(equalTo: contentView.trailingAnchor, constant: -20),

            saveButton.topAnchor.constraint(equalTo: tokenField.bottomAnchor, constant: 20),
            saveButton.trailingAnchor.constraint(equalTo: contentView.trailingAnchor, constant: -20),
            // Ties the window's height to the content instead of a fixed
            // guess, so the button can never end up clipped below the
            // visible area regardless of label/field sizing.
            contentView.bottomAnchor.constraint(equalTo: saveButton.bottomAnchor, constant: 20),
        ])
    }

    @objc private func save() {
        Config.serverURL = serverField.stringValue.trimmingCharacters(in: .whitespacesAndNewlines)
        Config.exportToken = tokenField.stringValue.trimmingCharacters(in: .whitespacesAndNewlines)
        window?.close()
        NotificationCenter.default.post(name: .taskStickSettingsSaved, object: nil)
    }
}

extension Notification.Name {
    static let taskStickSettingsSaved = Notification.Name("taskStickSettingsSaved")
}

// MARK: - App delegate / menu bar

@MainActor
final class AppDelegate: NSObject, NSApplicationDelegate {
    private var statusItem: NSStatusItem!
    private var settingsWindowController: SettingsWindowController?
    private let engine = SyncEngine()
    private var timer: Timer?

    private let syncNowItem = NSMenuItem(title: "Sync Now", action: #selector(syncNow), keyEquivalent: "")
    private let statusLineItem = NSMenuItem(title: "Not synced yet", action: nil, keyEquivalent: "")

    // Registering the Apple Event handler has to happen in
    // applicationWillFinishLaunching (before the app is fully up) — this
    // is the standard belt-and-suspenders alongside application(_:open:)
    // for custom URL schemes on macOS; some app configurations only
    // reliably receive one or the other.
    func applicationWillFinishLaunching(_ notification: Notification) {
        NSAppleEventManager.shared().setEventHandler(
            self,
            andSelector: #selector(handleGetURLEvent(_:withReplyEvent:)),
            forEventClass: AEEventClass(kInternetEventClass),
            andEventID: AEEventID(kAEGetURL)
        )
    }

    func applicationDidFinishLaunching(_ notification: Notification) {
        statusItem = NSStatusBar.system.statusItem(withLength: NSStatusItem.squareLength)
        if let icon = Bundle.main.image(forResource: "MenuBarIcon") {
            icon.size = NSSize(width: 18, height: 18)
            icon.isTemplate = false // keep the actual yellow/purple TaskStick colors, not a monochrome symbol
            statusItem.button?.image = icon
        } else {
            statusItem.button?.title = "◐" // fallback if the icon didn't get bundled
        }

        let menu = NSMenu()
        syncNowItem.target = self
        statusLineItem.isEnabled = false
        menu.addItem(syncNowItem)
        menu.addItem(statusLineItem)
        menu.addItem(.separator())
        menu.addItem(withTitle: "Settings…", action: #selector(openSettings), keyEquivalent: ",").target = self
        menu.addItem(withTitle: "Check Reminders Permission…", action: #selector(checkPermission), keyEquivalent: "").target = self
        menu.addItem(.separator())
        menu.addItem(withTitle: "Quit", action: #selector(NSApplication.terminate(_:)), keyEquivalent: "q")
        statusItem.menu = menu

        NotificationCenter.default.addObserver(self, selector: #selector(syncDidFinish), name: .taskStickSyncDidFinish, object: nil)
        NotificationCenter.default.addObserver(self, selector: #selector(settingsSaved), name: .taskStickSettingsSaved, object: nil)

        Task { await requestAccessAndMaybeSync() }

        timer = Timer.scheduledTimer(withTimeInterval: 600, repeats: true) { [weak self] _ in
            Task { @MainActor in await self?.engine.performSync() }
        }
    }

    /// Runs on launch, and again from "Check Reminders Permission…" —
    /// deliberately verbose (an actual alert, not just menu-bar text that's
    /// easy to miss) because a silent EventKit denial is exactly what made
    /// the CalDAV attempt so hard to debug earlier; this app shouldn't
    /// repeat that mistake.
    private func requestAccessAndMaybeSync() async {
        let status = EKEventStore.authorizationStatus(for: .reminder)
        let granted = await engine.requestAccess()

        if !granted {
            statusLineItem.title = "⚠️ Reminders access not granted"
            let alert = NSAlert()
            alert.messageText = "Reminders Access Needed"
            alert.informativeText = "TaskStick Reminders can't create or update reminders without access. Current status: \(Self.describe(status)).\n\nOpen System Settings → Privacy & Security → Reminders and make sure TaskStickReminders is turned on. If it's not listed at all, try quitting this app, running:\n\ntccutil reset Reminders ai.tesh.taskstick.reminders\n\nin Terminal, then relaunch it."
            alert.addButton(withTitle: "Open Privacy Settings")
            alert.addButton(withTitle: "OK")
            NSApp.activate(ignoringOtherApps: true)
            if alert.runModal() == .alertFirstButtonReturn {
                NSWorkspace.shared.open(URL(string: "x-apple.systempreferences:com.apple.preference.security?Privacy_Reminders")!)
            }
            return
        }

        if Config.isConfigured {
            await engine.performSync()
        } else {
            statusLineItem.title = "Not configured — open Settings"
        }
    }

    private static func describe(_ status: EKAuthorizationStatus) -> String {
        switch status {
        case .notDetermined: return "not yet asked"
        case .restricted:    return "restricted (e.g. parental controls)"
        case .denied:        return "denied"
        case .authorized:    return "authorized (legacy)"
        case .fullAccess:    return "full access"
        case .writeOnly:     return "write-only"
        @unknown default:    return "unknown"
        }
    }

    @objc private func checkPermission() {
        Task { await requestAccessAndMaybeSync() }
    }

    // MARK: URL scheme (taskstickreminders://connect?token=...&server=...)
    // — lets Settings hand the token straight to this app with one click
    // instead of copy/paste.

    func application(_ application: NSApplication, open urls: [URL]) {
        urls.forEach(handleIncomingURL)
    }

    @objc private func handleGetURLEvent(_ event: NSAppleEventDescriptor, withReplyEvent: NSAppleEventDescriptor) {
        guard let urlString = event.paramDescriptor(forKeyword: keyDirectObject)?.stringValue,
              let url = URL(string: urlString) else { return }
        handleIncomingURL(url)
    }

    private func handleIncomingURL(_ url: URL) {
        guard url.scheme == "taskstickreminders", url.host == "connect" else { return }
        let params = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems ?? []
        guard let token = params.first(where: { $0.name == "token" })?.value, !token.isEmpty else {
            statusLineItem.title = "⚠️ Link was missing a token"
            return
        }
        Config.exportToken = token
        if let server = params.first(where: { $0.name == "server" })?.value, !server.isEmpty {
            Config.serverURL = server
        }
        NSApp.activate(ignoringOtherApps: true)
        let alert = NSAlert()
        alert.messageText = "Connected to TaskStick"
        alert.informativeText = "Setup token received. Syncing now…"
        alert.addButton(withTitle: "OK")
        alert.runModal()
        Task { await requestAccessAndMaybeSync() }
    }

    @objc private func syncNow() {
        syncNowItem.title = "Syncing…"
        syncNowItem.isEnabled = false
        Task { await engine.performSync() }
    }

    @objc private func syncDidFinish() {
        syncNowItem.title = "Sync Now"
        syncNowItem.isEnabled = true
        if let err = engine.lastError {
            statusLineItem.title = "⚠️ \(err)"
        } else if let ts = engine.lastSyncedAt {
            let formatter = DateFormatter()
            formatter.timeStyle = .short
            statusLineItem.title = "Last synced \(formatter.string(from: ts))"
        }
    }

    @objc private func openSettings() {
        if settingsWindowController == nil {
            settingsWindowController = SettingsWindowController()
        }
        settingsWindowController?.window?.makeKeyAndOrderFront(nil)
        NSApp.activate(ignoringOtherApps: true)
    }

    @objc private func settingsSaved() {
        Task { await engine.performSync() }
    }
}

// MARK: - Entry point

@main
struct TaskStickRemindersApp {
    @MainActor
    static func main() {
        let app = NSApplication.shared
        app.setActivationPolicy(.accessory)
        let delegate = AppDelegate()
        app.delegate = delegate
        app.run()
    }
}
