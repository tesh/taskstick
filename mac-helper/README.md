# TaskStick Reminders (Mac helper app)

A small menu-bar app that syncs TaskStick's Google Tasks lists into Apple
Reminders. It exists because Apple removed Reminders from iCloud's CalDAV
protocol in 2019 — see `archive/apple-caldav-attempt-2026-08-19/README.md`
for the full story. EventKit (used here) is the only thing that still
reliably writes to modern Reminders, and it only runs on-device.

## Setup

1. Build the app (only needed once, or after pulling code changes):
   ```
   cd mac-helper
   ./build.sh
   ```
2. Open `mac-helper/build/TaskStickReminders.app` (double-click in
   Finder, or `open build/TaskStickReminders.app`). It has no Dock icon —
   look for a `◐` in the menu bar.
3. macOS will ask for Reminders access — click **Allow**, and let the
   dialog resolve on its own (don't force-quit the app while it's up —
   that can leave the permission stuck in a state where macOS won't
   re-prompt on the next launch; see Troubleshooting below if that
   happens).
4. In TaskStick → Settings → Apple Reminders, click **Enable Apple
   Reminders Sync**, then click **Open in TaskStick Reminders Helper** —
   this hands the token straight to the app (no copy/paste). It syncs
   immediately, then automatically every 10 minutes. If the button
   doesn't do anything, the app likely isn't running, or macOS hasn't
   registered its URL scheme yet — quit and relaunch the app once, or
   fall back to copying the token manually into the app's Settings
   window (`◐` → Settings…).

## Troubleshooting

- **"Check Reminders Permission…"** in the `◐` menu re-checks access and
  explains exactly what's wrong (not granted yet, denied, restricted) —
  use this first if sync silently isn't doing anything.
- **Permission stuck / app never re-prompts:** quit the app, then in
  Terminal:
  ```
  tccutil reset Reminders ai.tesh.taskstick.reminders
  ```
  and relaunch it fresh.
- **The "Open in Helper App" link does nothing:** the app needs to have
  been launched at least once since being (re)built for macOS to know
  about its `taskstickreminders://` URL scheme. Quit and reopen it, then
  try the link again.

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
