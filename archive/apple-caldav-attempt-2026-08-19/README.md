# Apple Reminders sync — CalDAV attempt (archived 2026-08-19)

## Why this was archived

Built as ENH-042/043/044 (Stages 1–3, one-way push Google Tasks → Apple
Reminders over CalDAV, using `caldav.icloud.com` + an app-specific
password). Live-tested by Tesh: connection and list *discovery* worked,
but every push silently failed to appear anywhere — not in Reminders.app,
not on icloud.com, and a server-side round-trip check (a REPORT
immediately after each PUT) confirmed the writes weren't even landing on
Apple's server as real reminders.

Root cause, confirmed via research (BusyCal, DAVx5, and Apple's own
developer forums all document this independently): **Apple removed
Reminders from CalDAV starting with iOS 13 / macOS Catalina (2019).**
`caldav.icloud.com` still exists and still answers PROPFIND/MKCALENDAR/PUT
requests for backward compatibility, but the modern Reminders app no
longer reads from or writes to that store at all — it syncs through a
private, proprietary protocol instead. This is a real, permanent Apple
platform limitation, not a bug in this code, and it affects every
third-party CalDAV reminders client, not just this one.

This is exactly why the sibling Contacts app's CardDAV sync (`contacts-app/backend/lib/CardDAV.php`)
keeps working and this doesn't, despite looking like the same kind of
integration: Apple never deprecated CardDAV for Contacts, but did for
CalDAV-based Reminders specifically.

## What replaced it

A native macOS helper app (EventKit, which is the only API that still
has real Reminders access) — see `mac-helper/` at the repo root — paired
with a lightweight `api/apple-export.php` that the helper polls instead
of a server-side CalDAV push. `api/apple-settings.php` was rewritten
around this (export token instead of Apple ID + app-specific password);
`apple_sync_{userId}.json`'s shape changed accordingly (see that file's
own comments). The `apple_reminder_links` MySQL table these files used is
left in place, unused, rather than dropped — same precedent as the
archived sharing feature's tables.

## Files here

- `CalDAV.php` — the PROPFIND/REPORT/PUT/MKCALENDAR client
- `apple-sync.php` — the server-side push endpoint (self-throttled
  background trigger + manual "Sync Now")
- `apple_sync_db.php` — the Google-task-ID ↔ Apple-VTODO-UID link table
  helpers

All fully functional as *CalDAV* code — the bug is Apple's server, not
this implementation. Kept for reference in case Apple ever reverses this
(unlikely — it's been six years) or a future feature needs a working
CalDAV client to copy from.
