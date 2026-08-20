# TaskStick Reminders (Mac helper app)

A small menu-bar app that syncs TaskStick's Google Tasks lists into Apple
Reminders. It exists because Apple removed Reminders from iCloud's CalDAV
protocol in 2019 — see `archive/apple-caldav-attempt-2026-08-19/README.md`
for the full story. EventKit (used here) is the only thing that still
reliably writes to modern Reminders, and it only runs on-device.

## Setup

1. In TaskStick → Settings → Apple Reminders, click **Enable Apple
   Reminders Sync**, then copy the token that appears.
2. Build the app (only needed once, or after pulling code changes):
   ```
   cd mac-helper
   ./build.sh
   ```
3. Open `mac-helper/build/TaskStickReminders.app` (double-click in
   Finder, or `open build/TaskStickReminders.app`). It has no Dock icon —
   look for a `◐` in the menu bar.
4. macOS will ask for Reminders access — allow it. If you miss the
   prompt, it's in System Settings → Privacy & Security → Reminders.
5. Click the `◐` menu bar icon → **Settings…**, paste the token from
   step 1, and Save. It syncs immediately, then automatically every 10
   minutes.

## Keeping it running

The app doesn't register itself as a Login Item automatically. To have
it start on login: System Settings → General → Login Items → **+** →
select `TaskStickReminders.app`.

## Notes for future changes

- Local state lives in `~/Library/Application Support/TaskStickReminders/`
  (`links.json` maps Google task IDs to Reminders identifiers — delete
  it to force a full re-push, e.g. if it gets out of sync).
- Config (server URL + token) is in `UserDefaults` under bundle ID
  `ai.tesh.taskstick.reminders` — `defaults read ai.tesh.taskstick.reminders`
  to inspect, `defaults delete ai.tesh.taskstick.reminders` to reset.
- Rebuilding re-signs ad-hoc (`codesign --sign -`) each time; this is
  fine for personal use — a stable bundle ID is what keeps Reminders
  permission persisted across rebuilds, not the signature itself.
- No Xcode project — `build.sh` calls `swiftc` directly and hand-builds
  the `.app` bundle structure. Only Xcode Command Line Tools are
  required, not full Xcode.
