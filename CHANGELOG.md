# Changelog

All notable changes to TaskStick, condensed from [`ISSUES.md`](ISSUES.md) (the full bug tracker / enhancement log, kept for detailed history). This project doesn't use version numbers — entries are grouped by date.

## Known open issues

- Double-click-to-edit has no visible affordance — no visual cue that task text is editable, awkward on mobile (BUG-002).
- Stars don't sync to Google Tasks' own `starred` field, only within this app (ENH-003).

## 2026-08-17

- **Fixed:** Follow-up's ◑ "return to original list" button silently did nothing — a Google Tasks API error came back as HTTP 200 (masking the failure), and the move function swallowed its own errors without telling its callers, so a misleading "success" toast always followed the real, briefly-visible error (BUG-008).
- **Fixed:** Due dates displayed one day earlier than what was actually set, in negative-UTC-offset timezones (US/Canada) — a display-only bug re-interpreting stored UTC midnight through the local timezone (BUG-009).
- **Added:** Subtasks can now be indented/outdented via buttons or drag (right to indent, left to outdent), created by indenting an existing task, and collapsed/expanded per-task with the state remembered across devices. Starring or Follow-up'ing a subtask now surfaces its whole family (parent + siblings) in the Priority card and moves the whole family together to Follow-up — previously moving a parent with subtasks to Follow-up risked losing the subtasks entirely, since Google Tasks cascade-deletes subtasks when their parent is deleted and the old logic only moved the one clicked task (ENH-037).
- **Improved:** Subtask chevron/count moved to the end of the row so a task's checkbox column stays aligned whether or not it has subtasks; tightened spacing so a parent and its subtasks read as one visual group (ENH-038).
- **Added:** Custom per-list background color, independent of the active theme (switching themes never clears it), with a one-click reset-all in Settings (ENH-039).
- **Improved:** Admin modal redesigned — wider and fully responsive (full-screen sheet on mobile), Users and Feedback split into tabs instead of one cramped stacked column, and a "Hide resolved" filter on Feedback (ENH-040).

## 2026-08-16

- Fixed a self-inflicted production outage: `mcp/config.php` required the whole main `config.php` to remove a duplicated OAuth secret, but that pulled in a `refreshAccessToken()` definition colliding with `mcp/index.php`'s own — a PHP fatal error that took the entire MCP server down (HTTP 500). Now loads just `config.local.php` directly.
- Set up this repo under git/GitHub; replaced 7 scattered deploy scripts with hardcoded credentials with a single `deploy.py` reading secrets from a git-ignored `deploy_local.py`.

## 2026-08-15

- **Fixed:** Reinstalling the PWA still showed the old icon — `manifest.json`, `index.html`, and `sw.js` had no explicit `Cache-Control`, so browsers served stale cached copies without ever re-checking the server. Added a `mod_headers` block forcing revalidation on all three.
- **Fixed:** PWA installs (Mac Dock, iPhone home screen) stuck on old code indefinitely, with no way to self-recover. Service worker registration now uses `updateViaCache: 'none'` and calls `update()` on every load; a new `api/version.php` + client-side polling shows an "Update now" banner on mismatch that clears caches and reloads.
- **Fixed:** MCP setup page's ChatGPT connector instructions were stale (OpenAI had changed the UI). Now links to OpenAI's own current docs instead of a hard-coded click path.
- **Added:** Settings and theme now persist server-side (`api/prefs.php`), so they sync across devices and survive storage clears — previously localStorage-only.
- **Improved:** Help modal is now fully responsive — wider 4-column grid on desktop, full-viewport sheet with sticky header on mobile.
- **Added:** Platform-specific PWA install instructions (Mac, Windows, iPhone) in the Help panel.

## 2026-08-14

- **Fixed:** PWA Dock icon not updating after icon changes — versioned icon URLs (`?v=2`) and bumped the service worker cache version to maximize future propagation; current staleness needed one manual reinstall.
- **Removed:** Backed out the Shared Tasks / Connected Users feature after live-testing surfaced it needing more design work. Code archived (not deleted) to [`archive/sharing-feature-2026-08-13/`](archive/sharing-feature-2026-08-13/) for a possible future revisit; live shared tasks were migrated back into Google Tasks first.

## 2026-08-13

- **Fixed:** Share icon on lists and tasks did nothing — an unescaped `JSON.stringify()` result embedded in an `onclick` HTML attribute broke the attribute at the first quote, silently truncating the handler.
- **Fixed:** "Grant Task Access" did nothing, and the board got stuck on empty skeleton cards when a user declined the Tasks OAuth scope — login incorrectly short-circuited before reaching Google, and the frontend never detected the missing-scope case.
- **Added:** Warning modal when Google's OAuth consent is completed without granting the Tasks scope, with a one-click way to re-launch consent.
- **Improved:** Admin and Send Feedback modals now follow the active theme instead of a hardcoded dark background that made text unreadable in light themes.
- **Rewrote:** Login-screen feature list to explicitly call out list/task creation, starring, due dates, Daily Ritual, and Google Tasks sync.
- Built (then archived the same week — see 2026-08-14) a full jointly-owned Shared Tasks feature: real-time shared lists/tasks stored in MySQL with full-parity edit permissions across participants.

## 2026-08-12

- **Added:** Daily Ritual list — a recurring checklist that lives as a real Google Tasks list and resets every completed task back to incomplete at local midnight.
- **Added:** Admin system — `hitesh.patel44@gmail.com` seeded as the first admin, with a Users view (promote/demote) and a Feedback view.
- **Added:** In-app feedback submission (bug / feature / suggestion) from the profile dropdown, stored in MySQL and reviewable by admins.
- **Rebranded:** Tasktical → TaskStick, including a new logo (stacked yellow Post-it notes with purple checkmarks) across the masthead, login screen, PWA icons, and legal pages.

## 2026-04-13

- **Added:** Theme picker moved into Settings; added Compact, Ocean, and Rose themes alongside Notebook and Modern.
- **Added:** "Tetris stacking" layout mode — packs cards into CSS columns to eliminate whitespace gaps.
- **Improved:** Task action icons (due date, notes, partial-complete, share, star, delete) moved below task text into a hover-revealed row, decluttering the default view.
- **Added:** Task sharing v1 — invite connections by email, share individual tasks/lists, Shared Tasks card (later replaced by the ENH-031/032 full-parity version and then archived).
- **Added:** Drag-and-drop reordering on the Priority (starred) and Follow-up cards.
- **Added:** Partial-complete button — moves a task to an auto-created "Follow-up" list with distinct amber styling.

## 2026-04-08

- **Added:** Settings panel with a "hide completed tasks" toggle, persisted to localStorage.
- **Improved:** Replaced the separate Sign Out button with a profile-photo dropdown menu (Settings, Sign out).
- **Fixed:** App icon was invisible against the dark masthead background — added a purple drop-shadow glow.

## 2026-04-02

- **Fixed:** Terms/privacy pages weren't deployed to production and used relative links that broke from the root domain.
- **Improved:** Login screen expanded to meet Google OAuth verification requirements — brand identity, feature description, explicit data-access notice, and legal links, all visible pre-login.
- **Rebranded:** Masthead to "TaskR" with a new prism icon and dark navy header; added a persistent footer with legal links.

## 2026-03-21

- **Fixed:** Task ordering was reversed compared to Google Tasks' own web UI — added ascending sort by `position`.
- Initial feature set: starring, auto-growing inline edit, drag-drop within and across lists, subtasks displayed under parents, due dates, mobile-responsive layout.
