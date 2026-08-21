# tasks.tesh.ai — Bug Tracker & Enhancement Requests

> **Format for Claude:** Each item has a unique ID, type, status, date, and description.
> To update: change `status`, add `resolved` date and `fix` note. Append new items at the top of their section.
> Statuses: `open` | `in-progress` | `fixed` | `wontfix` | `pending` | `complete`

---

## 🐛 Bugs

### BUG-015 · fixed · 2026-08-21
**Title:** Loading scene fragments into a scattered, overlapping mess on re-sync
**Reported by:** Tesh (screenshot: cloud isolated on the far left, sticky notes split into disconnected clusters with a large gap, caption stranded on the right)
**Root cause:** `#board.tetris-mode` (Tetris stacking mode) switches `#board` to a CSS multi-column layout (`columns: 300px`). `renderSkeletons()` replaces `#board`'s `innerHTML` but never touches its `classList`, so if the user has Tetris mode on, the `tetris-mode` class survives from the previous `renderBoard()` call — meaning it's present on first load's skeleton render too, but only becomes visible once the loading scene has real content tall enough to fragment across column boundaries. The `.loading-scene` div had no `column-span`, so the browser split its content across columns instead of rendering it as one block, exactly like `.list-card`/`.priority-card` would without their own `break-inside: avoid` overrides.
**Fix:** Added `column-span: all; break-inside: avoid;` to `.loading-scene`, so it always renders as one full-width unfragmented block regardless of which mode class is on `#board`. Reproduced locally in a standalone test harness carrying the same `#board.tetris-mode` rule, confirmed the fragmentation, then confirmed the fix resolves it before deploying.
**Files:** `index.html`
**Resolved:** 2026-08-21

### BUG-014 · fixed · 2026-08-19
**Title:** Theme picker chip labels unreadable in Notebook and Rose themes
**Reported by:** Tesh (screenshot: "Modern"/"Compact"/"Ocean"/"Rose" chip text barely visible against Notebook's cream background)
**Root cause:** Same class of bug as BUG-013, one CSS rule that BUG-013's audit didn't catch — `.theme-chip`'s unselected state was `color: rgba(255,255,255,0.65)` on `background: rgba(255,255,255,0.07)`, designed for the old fixed-dark Settings modal, essentially invisible once the modal could have a light background.
**Fix:** `color: var(--text-primary)`. Background went through two iterations before landing correctly: `var(--input-bg, var(--card-bg))` first (broke again specifically on Notebook, whose `--input-bg` is literally `transparent`, not merely unset, so the fallback never applied), then `var(--bg-secondary)` (broke again specifically on Compact, where `--bg-secondary` and `--card-bg` happen to be identical). Landed on `color-mix(in srgb, var(--text-primary) 6%, transparent)` — mixing in a bit of the theme's own text color instead of picking a second surface token, since text color is guaranteed by definition to contrast against its own background in every theme, closing off this whole category of "which two tokens happen to collide in theme N" bug rather than fixing it theme-by-theme as reported.
**Files:** `index.html`
**Resolved:** 2026-08-19

### BUG-013 · fixed · 2026-08-19
**Title:** Settings dialog hangs off the page, isn't scrollable, and doesn't match the active theme; Admin/Feedback have the same inconsistency
**Reported by:** Tesh (screenshots: Settings rendered in a fixed dark-violet palette while Notebook theme was active; content cut off at the bottom with no way to scroll to it, the board behind the dialog scrolling instead)
**Root cause:** `.settings-modal` (shared by Settings, Admin, Feedback, and the scope-warning dialog) was hardcoded to a fixed dark palette designed before the app had multiple themes. When Admin/Feedback/scope-warning were later made theme-aware (ENH-028), the fix was a separate block of `#admin-overlay .settings-modal`-style ID-scoped overrides layered on top — Settings itself was never added to that list, so it silently kept the old hardcoded look. The base modal also had `overflow:hidden` with no internal scroll region at all, and no modal anywhere in the app locked background scroll, so long content just got clipped and the page behind a dialog could still scroll.
**Fix:** Moved theme-awareness and consistent sizing directly into the BASE `.settings-modal` family of rules (`width: min(640px, 94vw); max-height: 82vh;` plus a shared mobile full-viewport-sheet breakpoint), then deleted the now-redundant per-overlay-ID override blocks — a new theme or a new modal now needs zero extra CSS to stay consistent, instead of relying on someone remembering to add another override block. Added a `.settings-modal-body` scrollable wrapper (Settings/Feedback/scope-warning now use it; Admin already had its own per-tab scroll region from ENH-040 and didn't need it) and a shared `lockBodyScroll()`/`unlockBodyScroll()` pair wired into every modal's open/close function. Along the way, an audit turned up several more elements hardcoded for the old dark-only look that would have been low-contrast or invisible on light themes (Notebook, Rose) — "Reset all", the Apple Reminders section's inputs/hints/status banners, and the Admin/Beta badges (whose translucent-tint-over-variable-background approach measured as low as ~1.3:1 contrast on light themes) — all converted to theme-aware or solid theme-independent colors.
**Files:** `index.html`
**Resolved:** 2026-08-19

### BUG-012 · fixed · 2026-08-18
**Title:** Admin toggle switch nearly invisible in light themes
**Reported by:** Tesh (screenshot: Users tab, admin toggle unreadable)
**Root cause:** `.toggle-track`'s off-state (`rgba(255,255,255,0.15)`, near-white) was designed for the Settings modal's fixed dark background. Once the Admin modal became theme-aware (ENH-028), that same near-white track rendered against light theme card backgrounds (Notebook, Rose) — nearly invisible.
**Fix:** Added `#admin-overlay .toggle-switch input:not(:checked) + .toggle-track { background: rgba(128,128,128,0.35); border: 1px solid rgba(128,128,128,0.25); }` — a neutral mid-gray that reads on any theme, light or dark. Scoped with `:not(:checked)` specifically so it doesn't outrank (by ID specificity) the existing checked-state purple rule — an earlier version of this fix without that guard visually broke the "on" state too, caught via local browser testing before deploy.
**Files:** `index.html`
**Resolved:** 2026-08-18

### BUG-011 · fixed · 2026-08-18
**Title:** Priority card too narrow relative to other cards in Tetris stacking mode, at some window widths
**Reported by:** Tesh (screenshot: Priority/Follow-up narrower than Tasktical/Purple Pill list cards)
**Root cause:** In Tetris mode (`#board.tetris-mode`, CSS multi-column layout), `.list-card`'s override correctly sets `width: 100%; max-width: 100%;` to fill its column, but `.priority-card`'s override was missing `max-width: 100%` — so it stayed capped by the base (non-tetris) `.priority-card { max-width: 340px; }` rule whenever the browser rendered wider columns than 340px (which varies with window width, since `columns: 300px` is a target minimum, not a fixed size — matching "at some breakpoints").
**Fix:** Added the missing `max-width: 100%` to `#board.tetris-mode .priority-card`, matching `.list-card`'s existing tetris override.
**Files:** `index.html`
**Resolved:** 2026-08-18

### BUG-010 · fixed · 2026-08-18
**Title:** New user (Lola) never appeared in the Admin Users list despite using the app
**Reported by:** Tesh
**Root cause:** `registerUser()` (called on every login) and the admin `toggle_admin` action both did a plain read-modify-write on `data/users.json` — `loadUsers()` → mutate the in-memory array → `saveUsers()` — with no file locking. If two requests hit this close together (very plausible with several family members trying the app around the same time), the second writer could read the file before the first writer's save landed, then overwrite it with a version missing the first writer's new user entirely. Confirmed via the live `data/users.json`: only 4 of the expected 5 users were present, with no trace of the missing one — consistent with a lost write, not a display/filtering bug (ENH-027 had already confirmed the Users view itself is unfiltered).
**Fix:** New `updateUsers(callable $mutator): array` in `config.php` opens the file with `fopen(..., 'c+')`, takes an exclusive `flock()`, reads, lets the caller mutate the array, then truncates and rewrites before releasing the lock — serializing concurrent writers instead of racing them. `registerUser()` and `toggle_admin` both now go through it.
**Files:** `config.php`, `api/admin.php`
**Resolved:** 2026-08-18

### BUG-009 · fixed · 2026-08-17
**Title:** Due date displays one day earlier than what was set (ENH-004 resolved)
**Reported by:** Tesh (screenshot: date input showed 08/18/2026, badge showed "Aug 17")
**Root cause:** Due dates are stored as UTC midnight of the picked calendar date (`2026-08-18T00:00:00.000Z`). `formatDue()`/`isOverdue()` parsed that string with plain `new Date(dueStr)` and formatted/compared it in the browser's local timezone — in any negative-UTC-offset zone (US/Canada), UTC midnight on the 18th is still the 17th locally, shifting the displayed date back a day. The write path (the native `<input type="date">` → `input.value + 'T00:00:00.000Z'`) was already correct; this was purely a display-side bug.
**Fix:** New `parseDueLocal()` reads the Y/M/D digits straight off the stored string and constructs a *local* `Date` from those parts, sidestepping timezone conversion entirely instead of trying to correct for it. `formatDue()` and `isOverdue()` both use it now.
**Files:** `index.html`
**Resolved:** 2026-08-17

### BUG-008 · fixed · 2026-08-17
**Title:** Follow-up ◑ "Return to original list" silently did nothing
**Reported by:** Tesh (screenshot of a stuck Follow-up task, "Halifax Bereavement BER-631760")
**Root cause:** Two compounding bugs. (1) `api/tasks.php`'s POST/PATCH/DELETE handlers proxied Google's raw API response with a hardcoded 200 status — the same pattern BUG-003 already fixed in `lists.php` — so a failed Google Tasks call (e.g. the origin list ID captured in the task's `[taskstick-origin:...]` marker no longer being valid on Google's side) looked like success to the client. (2) `crossListMoveAPI()` caught its own errors internally and never told its callers it failed; `moveBackFromFollowUp()`/`markPartialComplete()` then unconditionally showed their own "success" toast right after `await`ing it, overwriting the real (briefly-visible) error toast, while the internal `loadAll(false)` revert silently put the task right back where it started — matching Tesh's report of a message flashing and the task staying in Follow-up.
**Also fixed:** The optimistic re-render happened before the API call resolved and updated the task's real ID, so a click immediately after a move could bind the wrong task ID into the rendered button.
**Fix:** Added the `if (!empty($result['error'])) jsonResponse($result, 500);` check (matching `lists.php`) to all three verbs in `api/tasks.php`. `crossListMoveAPI()` now returns `true`/`false` instead of swallowing failure; callers only show their success toast (and re-render to pick up the corrected task ID) when it actually returns `true`.
**Files:** `api/tasks.php`, `index.html`
**Resolved:** 2026-08-17

### BUG-007 · fixed · 2026-08-15
**Title:** Reinstalling the PWA still showed the old icon — manifest.json itself was stale, not just the app cache
**Reported by:** Tesh (deleted + reinstalled the Mac app per BUG-006's guidance; still got the old icon)
**Root cause:** None of `manifest.json`, `index.html`, or `sw.js` had explicit `Cache-Control` headers, so browsers fell back to heuristic caching (roughly 10% of file age per RFC 7234) — meaning Chrome could serve a *locally cached* copy of `manifest.json` without ever re-checking the server, even on a fresh reinstall. Since the cached manifest still pointed at the pre-versioning icon URLs, BUG-006's `?v=2` cache-busting never even got a chance to matter — the browser never asked for the new manifest that contained it.
**Fix:** Added a `mod_headers` block to `.htaccess` forcing `Cache-Control: no-cache, must-revalidate` on exactly those 3 files — browsers now always revalidate with the server before use (cheap 304 when unchanged, but guarantees any update is seen on the very next request). Verified via response headers post-deploy.
**Files:** `.htaccess`
**Resolved:** 2026-08-15

### BUG-006 · fixed · 2026-08-15
**Title:** PWA installs (Mac Dock, iPhone home screen) stuck on old code — icon, masthead, and already-removed share icons all stale
**Reported by:** Tesh
**Root cause:** Installed PWAs only get fresh content when their service worker re-checks and updates — and that check is browser/OS-scheduled, not something a deploy can push. iOS standalone (home-screen-launched) apps are especially unreliable about this; ENH-033's `taskstick-v3` network-first fix only takes effect *after* a client has actually picked up that version, so anyone still stuck on an older cache-first service worker (like Tesh's iPhone, apparently never updated since well before that fix) keeps serving fully stale HTML/JS/icons indefinitely with no way to self-recover.
**Fix:** Two parts. (1) Prevention going forward: `navigator.serviceWorker.register()` now passes `updateViaCache: 'none'` (stops the browser using its HTTP cache for `sw.js` itself) and calls `reg.update()` immediately on every load; new `api/version.php` returns `index.html`'s mtime (auto-changes every deploy, zero manual bumping) which the client polls (on load, every 2 min, and on tab/app foreground via `visibilitychange`) — on a mismatch it shows an "Update now" banner that unregisters all service workers, clears all caches, and reloads. (2) This is a bootstrapping fix, not a retroactive one — a client already stuck on old JS doesn't have this new check code yet, so it can't self-detect. Tesh still needed one manual reset (remove + re-add the Mac Dock and iPhone home-screen icons) to get onto the version that includes it; from that point forward the banner should catch future staleness automatically.
**Files:** `index.html`, `api/version.php`, `sw.js` (registration options only, cache strategy unchanged from ENH-033)
**Resolved:** 2026-08-15

### BUG-005 · fixed · 2026-08-15
**Title:** MCP setup page's ChatGPT instructions were stale
**Reported by:** Tesh ("doesn't correlate with the Settings menu")
**Root cause:** OpenAI has iterated ChatGPT's Developer Mode / connector UI multiple times since this page was written; the hard-coded "Settings → Apps → Add custom connector" steps no longer matched. Cross-checking several current sources found even they disagree on the exact click path (some say Settings, some say the `+`/tools icon in the chat composer) — the UI is genuinely still in flux/beta.
**Fix:** Rather than hard-code a path likely to go stale again, the ChatGPT tab now links directly to OpenAI's own current Developer Mode help article, with a brief "roughly: enable Developer mode under Settings → Apps, then add a custom connector from there or from the composer's +/tools icon" as a hedge, plus the URL/transport field reference unchanged (that part doesn't drift).
**Files:** `mcp/setup.php`
**Resolved:** 2026-08-15

### BUG-004 · fixed · 2026-08-13
**Title:** Share icon (list and task) did nothing — root cause of "share has no functionality"
**Reported by:** Tesh (screenshot: clicking 🔗 on both a list and a task produced no modal)
**Root cause:** `onclick="openShareModal('list','id','id',${JSON.stringify(list.title)},this)"` (and the two equivalent task-share call sites) embedded `JSON.stringify(...)`'s output — which is itself wrapped in `"..."` — raw inside an HTML attribute that is *also* delimited with `"`. The browser's HTML parser terminates the `onclick` attribute at the first quote from `JSON.stringify`'s output, truncating it into invalid/incomplete JS and scattering the rest of the title text as garbage attributes on the button. Every click silently no-op'd — this had nothing to do with the DB/connections work in ENH-031, it made the button itself non-functional from the start, which is almost certainly why sharing "had no functionality" in the first place.
**Fix:** Wrap with `escHtml()` — `${escHtml(JSON.stringify(list.title))}` — so the embedded quotes are HTML-entity-encoded (`&quot;`) and don't break out of the attribute; the browser decodes them back to a valid quoted JS string argument before executing. Applied at all 3 call sites (list share button, task share button ×2 render paths). Verified by simulating the template output — confirmed a single well-formed `onclick` attribute post-fix vs. a truncated one before.
**Files:** `index.html`
**Resolved:** 2026-08-13

### BUG-003 · fixed · 2026-08-13
**Title:** "Grant Task Access" did nothing; board stuck on empty skeleton cards when Tasks scope declined
**Reported by:** Tesh (live-tested with a second Google account)
**Root cause 1:** `auth/login.php` bailed out with `if (isAuthenticated()) { redirect home }` before ever reaching Google — and `isAuthenticated()` only checks for a session `access_token`, which a partial (Tasks-scope-declined) login already has. So clicking "Grant Task Access" just bounced straight back to `/` without ever showing Google's consent screen again.
**Root cause 2:** `checkAuth()` always called `loadAll()` regardless of `hasTasksAccess`, which called `/api/lists.php` → Google Tasks API → guaranteed failure without the scope. `api/lists.php` proxies Google's raw response with `jsonResponse($result)` at a hardcoded 200 status, so the frontend's `listsRes.status === 401` shortcut never fired; it fell into the generic catch block and left the loading skeleton cards on screen forever (never reaches `renderBoard()`).
**Fix:** `auth/login.php` now only skips re-launching OAuth when the user is authenticated **and** already has Tasks access (`isAuthenticated() && ($_SESSION['hasTasksAccess'] ?? true)`); missing-scope sessions fall through to Google's consent screen again. `checkAuth()` no longer calls `loadAll()`/`startPolling()` when `hasTasksAccess === false` — it renders a dedicated `renderNoTasksAccessBoard()` empty state (🔒 icon, explanation, "Grant Task Access" button) instead of leaving skeleton cards stuck.
**Files:** `auth/login.php`, `index.html`
**Resolved:** 2026-08-13

---

### BUG-002 · open · 2026-03-21
**Title:** Inline edit requires double-click but there is no visible affordance
**Reported by:** Testing session
**Description:** Double-clicking a task text opens the auto-growing textarea for inline editing.
Single-clicking does not edit. There is a `title="Double-click to edit"` tooltip but no visual cue
(e.g. pencil icon or hint text) to guide users. On mobile, double-tap is awkward.
**Steps to reproduce:** Single-click any task text — nothing happens.
**Expected:** Either single-click should open edit mode, or a visible edit icon/hint should appear.
**Fix:** —
**Resolved:** —

---

### BUG-001 · fixed · 2026-03-21
**Title:** Task ordering is reversed compared to Google Tasks
**Reported by:** Tesh (screenshot comparison)
**Description:** The app displayed tasks in the opposite order from the Google Tasks web UI.
The Google Tasks API returns tasks in DESCENDING `position` order (highest position first).
Google Tasks web UI displays them in ASCENDING order (lowest position = top of list).
The app was not sorting at all, so it rendered in the API's descending return order — reversed.
**Steps to reproduce:** Open tasks.tesh.ai and compare task order with tasks.google.com.
**Expected:** Same top-to-bottom order as Google Tasks.
**Fix:** Added `.sort(byPos)` (ascending by `position`: lower values first) to `activeTasks`
and `completedTasks` in `createListCard()` in `index.html`. Comparator:
`(a, b) => (a.position || '') > (b.position || '') ? 1 : -1`
Also corrects drag-drop `previous` task ID calculation since DOM order now matches GT.
**File:** `index.html` line ~1016
**Resolved:** 2026-03-21 · deployed 45,924 bytes to IONOS (second attempt — first attempt used wrong direction)

---

## ✨ Enhancement Requests

### ENH-048 · complete · 2026-08-21
**Title:** Replace the plain skeleton-card loader with a themed loading scene
**Requested by:** Tesh ("cool and funny pre-loader... provide a helpful message that it's loading tasks from the Google Tasks service")
**Design decision:** Iterated through 3 mockups (reviewed live as an HTML artifact) before converging on a combination: a cloud delivering tasks down a dashed path into sticky notes that pop in and draw/check their own checklist lines. Notes stay a fixed sticky-note yellow across all 5 themes — same precedent as the fixed-color logo mark — while the cloud, path, and progress-bar track pull from the active theme's `--card-bg`/`--card-border`/`--text-muted` tokens so it blends into Notebook, Modern, Compact, Ocean, and Rose alike.
**Fix:** Replaced `.skeleton-card`/`shimmer` CSS and `renderSkeletons()`'s `[1,2,3,4].map(() => skeleton-card)` with a single `.loading-scene`: a bobbing cloud, dashed delivery path with traveling tiles, a 2×2 grid of 4 sticky notes (each with 2–3 checklist lines, 1–2 checked), a progress bar, and a caption that rotates through 6 jokes about syncing with Google Tasks. Caption loop self-clears once the real board replaces the scene (checks for its own DOM node each tick rather than being torn down explicitly). Respects `prefers-reduced-motion`.
**Files:** `index.html`
**Resolved:** 2026-08-21

### ENH-047 · complete · 2026-08-19
**Title:** Replace CalDAV-based Apple Reminders sync with a native macOS helper app (EventKit)
**Requested by:** Tesh (after live testing ENH-042/043/044 showed pushed tasks never appearing anywhere — see those entries below)
**Root cause found:** Apple removed Reminders from iCloud's CalDAV protocol in iOS 13 / macOS Catalina (2019). `caldav.icloud.com` still answers PROPFIND/MKCALENDAR/PUT for backward compatibility (which is why Stage 1's discovery and the CalDAV connection test both appeared to work), but the modern Reminders app never reads from or writes to that store. Confirmed by a server-side round-trip check (a REPORT immediately after each PUT, added as a diagnostic) showing the server itself didn't have the "successfully pushed" items when read back — and independently corroborated by BusyCal, DAVx5, and Apple's own developer forums, all of which document the identical limitation for every third-party CalDAV reminders client, not just this app. This is exactly why the sibling Contacts app's CardDAV sync keeps working (Apple never deprecated CardDAV) while this did not, despite both looking like the same kind of integration.
**Fix:** New `mac-helper/` — a menu-bar macOS app (Swift, EventKit, hand-built `.app` bundle via `swiftc`, no Xcode project needed) that polls a new `api/apple-export.php` endpoint every 10 minutes and writes directly to Reminders using EventKit, the only API that still has real Reminders access. `api/apple-settings.php` rewritten: no more Apple ID + app-specific password — Settings now issues a random export token (`data/apple_export_tokens.json` registry) the helper authenticates with, and captures the session's Google `refresh_token` (encrypted at rest, same trick `mcp/setup.php` already uses for its own headless-client problem) so the helper can independently mint Google Tasks access tokens with no browser session involved. List selection UI unchanged. Completion status still pulls back Reminders → Google Tasks, now reported by the helper via `POST apple-export.php {action:'complete_tasks'}`.
**Also archived:** `lib/CalDAV.php`, the old `api/apple-sync.php` push endpoint, and `apple_sync_db.php` moved to `archive/apple-caldav-attempt-2026-08-19/` (with a README explaining why) rather than deleted — same precedent as the archived sharing feature. The `apple_reminder_links` MySQL table is left in place, unused.
**Files:** `mac-helper/` (new), `api/apple-export.php` (new), `api/apple-settings.php` (rewritten), `index.html`, `config.php`, `archive/apple-caldav-attempt-2026-08-19/` (moved files + README)
**Follow-up (same day):** First live test with a real token still created nothing — likely cause was an earlier local test-launch of the app getting force-killed mid Reminders-permission-dialog, which can leave macOS's TCC decision stuck without a proper re-prompt on later launches (this is a documented issue for ad-hoc-signed EventKit apps, not unique to this one). Added an explicit `EKEventStore.authorizationStatus` check with a real alert (deep-links to Privacy Settings) instead of an easy-to-miss menu-bar line, plus a "Check Reminders Permission…" menu item, and a `tccutil reset` troubleshooting step in `mac-helper/README.md`. Also addressed Tesh's separate UX complaint (manual token copy/paste being clunky): registered a `taskstickreminders://connect` URL scheme so Settings' "Open in Helper App" button hands the token to the app directly.
**Second follow-up (same day):** Still created nothing even with permissions fixed and the token correctly saved. Actual root cause: IONOS strips the `Authorization` header before it reaches PHP by default (commonly reserved for Apache's own Basic Auth) — confirmed directly with `curl` against the live server, bypassing the helper app entirely, so every request to `apple-export.php` was rejected before the token was ever checked, regardless of correctness. Fixed with an `.htaccess` `RewriteRule` re-exposing it as `HTTP_AUTHORIZATION`, plus a `getallheaders()` fallback in PHP. First real live sync succeeded right after (confirmed by Tesh: a reminder pushed from TaskStick appeared correctly in the "Dad's Probate" Reminders list). Also shipped, per Tesh's UX feedback: a downloadable `.zip` of the helper app (Settings → Apple Reminders) instead of requiring Terminal/`build.sh`; setup steps moved into the in-app Help modal instead of a private-repo README; the CalDAV/EventKit technical explainer trimmed out of the Settings copy; the app icon replaced with the real TaskStick Post-it mark; a Settings-window sizing bug (Save button could render past the visible area) fixed.
**Third follow-up (same day):** Extended to two-way creation — a reminder created directly in Apple Reminders (no Google task yet) now gets pushed up into Google Tasks too, not just completion status. New `POST apple-export.php {action:'create_tasks'}`, same auth/response pattern as the existing `complete_tasks` action. Not yet live-tested by Tesh.
**Status:** Deployed and confirmed working for the core push direction. EventKit confirmed to have no better/official alternative (researched: Apple provides no Reminders REST API — every third-party "API" found online is itself an EventKit wrapper). New create-direction and completion-pull-back still need Tesh's live confirmation.

### ENH-042 · superseded by ENH-047 · 2026-08-18
**Title:** Apple Reminders sync (beta) — Stage 1: connect + discover
**Requested by:** Tesh
**Description:** Sync tasks to Apple Reminders via iCloud, with Google Tasks treated as the trusted source. Modeled on the sibling Contacts app's proven Google/iCloud sync design (`contacts-app/backend/lib/CardDAV.php`, `Encryption.php`), adapted for CalDAV/VTODO (Reminders) instead of CardDAV/vCard (Contacts) — a "Reminders list" is a CalDAV calendar collection that supports the VTODO component, discovered the same way the Contacts app discovers address books.
**Design decisions (clarified with Tesh first):** One-way push (Google Tasks → Reminders) is authoritative for title/notes/due date/list membership; only completion status pulls back from Reminders, to avoid needing real conflict resolution. Sync runs automatically in the background rather than requiring a manual button, but self-throttled server-side to bound load on Apple's servers regardless of how many devices/tabs are open (arrives in Stage 2). Specific lists opt in rather than syncing everything. The Google↔Apple identifier mapping lives in a new MySQL table (arrives in Stage 2) — this app has no local table for tasks at all today, unlike the Contacts app's full local mirror.
**Why staged:** This is the single largest feature built this session, and its core risk — actual wire compatibility with Apple's iCloud CalDAV servers — can't be verified without a real Apple ID and app-specific password, which nothing in this environment has access to (and shouldn't: real user credentials aren't something this workflow handles directly). Built in 3 stages so the connectivity layer can be verified live by Tesh before push/pull logic is built on top of it.
**Fix (this stage):** New `lib/Encryption.php` (AES-256-CBC, ported from the Contacts app) and `lib/CalDAV.php` (new — PROPFIND/REPORT/PUT client for calendar-home-set discovery, VTODO listing/create/update/delete, and VTODO↔task field conversion). New `ENCRYPTION_KEY` config constant. New `api/apple-settings.php`: POST tests the CalDAV connection before saving anything (never stores credentials that don't work), returns discovered Reminders list names; GET returns connection status without ever exposing the encrypted password; DELETE disconnects. New Settings → "Apple Reminders (Beta)" section: connect form, connected-state banner with Change/Disconnect.
**Verification:** Two rounds of independent review caught and fixed 3 real correctness bugs before deploy — a discovery regex that could mis-extract the wrong URL from a shared PROPFIND response, a sequential-string-replace decode bug that could silently corrupt notes/titles containing a literal backslash followed by a bare "n" (e.g. a Windows file path), and PUT requests that ignored their HTTP response status (a rejected write would have looked like success). Verified independently of live Apple servers: `Encryption` round-trips correctly, and VTODO generation/parsing round-trips correctly including the specific backslash-escaping edge case, via direct PHP execution. The actual wire-level connection to iCloud's CalDAV servers is unverified — this is Tesh's job to test live once deployed.
**Files:** `lib/Encryption.php` (new), `lib/CalDAV.php` (new), `api/apple-settings.php` (new), `config.php`, `config.local.php.example`, `index.html`
**Status:** Stage 1 verified live by Tesh — connects and correctly discovers real Reminders lists. Continued in ENH-043 (Stage 2: push).

### ENH-046 · complete · 2026-08-19
**Title:** Per-user usage metrics in Admin Users view
**Requested by:** Tesh ("number of lists, number of tasks, and any other useful facts")
**Design decision:** TaskStick has no server-side way to read a user's Google Tasks except through that user's own OAuth session — an admin's session can't fetch another user's list/task data, and building a way to would mean a real backdoor into anyone's Google Tasks, not something to add for an admin convenience feature. Instead, each user's own client self-reports its own counts once per successful session load, the same way `data/users.json` already gets populated by each user's own login rather than by an admin enumerating accounts.
**Fix:** New `api/user-stats.php` (POST, always keyed by the authenticated session's own email, never client-supplied) writes `list_count`/`active_task_count`/`completed_task_count`/`stats_updated_at` onto that user's own `data/users.json` record via the existing locked `updateUsers()` helper. New client-side `reportUserStats()` computes these from `state.lists` (excluding subtasks, matching how counts are done everywhere else in the app) and fires once after a genuinely successful initial load — gated on `loadAll()`'s new return value so a failed or partial load can't overwrite a user's real stats with misleading zeros. Admin Users view shows the counts plus "as of" the last time that user had the app open; a user who hasn't loaded the app since this shipped shows no stats line rather than a broken one.
**Files:** `api/user-stats.php` (new), `index.html`
**Resolved:** 2026-08-19

### ENH-045 · complete · 2026-08-19
**Title:** Move "Download archive (.md)" to the bottom of the Admin Feedback panel
**Requested by:** Tesh
**Fix:** Moved below the feedback list itself instead of sitting at the top next to "Hide resolved" — a less-frequently-used export action reads better after the content it exports, not before it.
**Files:** `index.html`
**Resolved:** 2026-08-19

### ENH-044 · superseded by ENH-047 · 2026-08-19
**Title:** Auto-create the matching Apple Reminders list instead of requiring it to exist first
**Requested by:** Tesh (created a "Personal Tasks" list in Reminders and found tasks weren't syncing there)
**Description:** Sync previously required a Reminders list with the exact matching name to already exist on the device, or it failed with an error. Design change: create it automatically instead, with a clear note about this in the setup UI so it's not a surprise.
**Fix:** New `CalDAV::createReminderList(string $displayName)` in `lib/CalDAV.php` — issues an MKCALENDAR request (RFC 4791 §5.3.1) restricted to VTODO support, so the new collection shows up as a Reminders list rather than a calendar. Wired into `api/apple-sync.php`'s per-list loop: a list with no name match now gets created (and immediately used for that same sync run's task pushes) instead of erroring. Updated both the Apple Reminders section's description and the "Lists to sync" hint text to say so explicitly.
**Caveat:** Like the rest of this feature's CalDAV wire calls, MKCALENDAR has not been tested against a real iCloud server in this environment (no real Apple credentials available here) — reviewed for RFC/structural correctness and consistency with this file's other CalDAV methods, but genuinely unverified until tried live.
**Files:** `lib/CalDAV.php`, `api/apple-sync.php`, `index.html`
**Resolved:** 2026-08-19, pending live verification.

### ENH-043 · superseded by ENH-047 · 2026-08-18
**Title:** Apple Reminders sync (beta) — Stage 2: list selection + push
**Requested by:** Tesh (after confirming Stage 1 connects successfully: "I don't see any features that allow me to select the ability to sync specific lists")
**Description:** Choose which Google Tasks lists sync to Apple Reminders, and actually push their tasks there (create/update as VTODOs). Each enabled list is matched to an existing Apple Reminders list by exact name — sync doesn't create Reminders lists on your device, you create one with a matching name first.
**Fix:** New `apple_reminder_links` MySQL table (`apple_sync_db.php`) mapping `google_task_id` → `apple_uid`/etag, so a re-push updates the existing reminder instead of duplicating it. New `api/apple-sync.php`: `POST {force}` runs a push (title/notes/due date/status → VTODO), self-throttled to once per 10 minutes unless forced, so the automatic background trigger (piggybacked on the existing 2-minute poll, `triggerAutoAppleSync()`) doesn't hammer Apple's servers regardless of how many devices/tabs are open. `api/apple-settings.php` gained a `save_lists` action. Settings gained a list-picker checklist and a "Sync Now" button for manual/beta testing.
**Verification:** Two rounds of independent review caught and fixed 5 real bugs before deploy — most notably (a) every cross-list task move gets a new Google task ID (confirmed from this app's own `crossListMoveFamilyAPI`), which meant moved or deleted tasks left permanent orphaned "zombie" reminders in Apple Reminders with no cleanup path at all; fixed with a per-list reconciliation pass each sync run that removes reminders for tasks no longer present. And (b) the throttle check and the "claim" that stamps `lastSyncedAt` weren't atomic, so two genuinely concurrent requests (e.g. two open tabs/devices) could both pass the throttle and both push the same not-yet-linked task, creating a real duplicate reminder on Apple's side; fixed with the same locked-file pattern already used for `data/users.json` (new shared `updateJsonFile()` in `config.php`), verified with an actual 3-concurrent-process test, not just review. Also fixed: a redundant full Reminders-list fetch per enabled list (now fetched once per sync run), a client-side race in the list-selection checkboxes that could silently drop a rapid toggle with no visible error, and the same unchecked-HTTP-status gap Stage 1 fixed for creates/updates, this time in the delete path used by the new orphan cleanup.
**Known scope boundary:** Removing a list from the sync selection (or disconnecting entirely) doesn't clean up reminders already pushed from it — consistent with Disconnect's existing behavior, not fixed in this stage.
**Files:** `apple_sync_db.php` (new), `api/apple-sync.php` (new), `api/apple-settings.php`, `config.php`, `lib/CalDAV.php`, `index.html`
**Status:** Stage 2 complete, pending live verification. Stage 3 (pull-back completions) not yet built.

### ENH-041 · complete · 2026-08-18
**Title:** Group feedback by type; delete feedback items with an archive + downloadable export
**Requested by:** Tesh
**Description:** The Feedback tab was one flat list — wanted it organized into Bugs/Features/Suggestions sections. Also wanted a way to delete items from the view, but kept in an archived form that can be downloaded as a Markdown file if needed later, rather than being permanently erased.
**Fix:** New `deleted_at DATETIME NULL` column on the `feedback` table (soft-delete; try/catch'd `ALTER TABLE` for portability across MySQL/MariaDB versions, matching this table's existing auto-migrate pattern). `feedbackList()` now excludes soft-deleted rows; new `feedbackSoftDelete()` and `feedbackArchiveList()`. New admin.php action `delete_feedback` and resource `feedback_archive_md` (streams a generated Markdown document via `Content-Disposition: attachment`). Frontend: `renderAdminFeedback()` groups items by type into labeled sections (respecting the existing hide-resolved filter), each row gets a 🗑 delete button (confirms first), and a "Download archive (.md)" link sits next to "Hide resolved."
**Files:** `feedback_db.php`, `api/admin.php`, `index.html`
**Resolved:** 2026-08-18

### ENH-040 · complete · 2026-08-17
**Title:** Redesign Admin modal — tabs + wider/responsive layout + hide-resolved filter
**Requested by:** Tesh (screenshot: Users and Feedback both crammed into one small fixed-width popup, no way to hide resolved feedback)
**Description:** The Admin popup was too small to comfortably show both Users and Feedback stacked in one narrow column. Wanted a larger (but still responsive) dialog with separate tabs for each, plus a way to hide resolved feedback so it doesn't crowd the list.
**Fix:** Widened the modal to `min(640px, 94vw)` on desktop with a full-viewport sheet on mobile (same responsive pattern as the Help modal from ENH-035). Split Users/Feedback into tabs (`switchAdminTab()`) instead of stacked sections, with a sticky header+tab bar and only the active panel scrolling. Added a "Hide resolved" checkbox on the Feedback tab; fetched feedback is now kept in a module-level `adminFeedbackItems` array so the filter re-renders instantly without a re-fetch, and `updateFeedbackStatus()` updates that local copy too so marking something resolved while the filter is on removes it from view immediately.
**Files:** `index.html`
**Resolved:** 2026-08-17

### ENH-039 · complete · 2026-08-17
**Title:** Custom per-list background color
**Requested by:** Tesh
**Description:** A color picker on each list, independent of the active theme, so a list keeps its custom color no matter which of the 5 themes is selected — plus a one-click "reset all" instead of clearing colors list by list.
**Design decisions (clarified with Tesh first):** Only the card background changes — text/border/accent stay exactly as the active theme defines them. No separate named "theme" for customized colors (considered, then dropped in favor of the simpler independent-of-theme model, which already solves the "don't lose my colors when I switch themes" concern directly). Color button sits in the list header's icon row, next to delete/collapse.
**Fix:** New `state.listColors` (`{listId: '#hex'}`), synced via `api/prefs.php` (new `listColors` field, same pattern as the existing `taskCollapsed`/`collapsed`/`stars` fields). New 🎨 button opens a single shared popover (`#list-color-popover`, repositioned per click) with 12 preset swatches, a native `<input type="color">` for any color, and a per-list reset link. `createListCard()` applies the stored color as an inline `background-color` on every render, so it survives re-renders and persists across devices. Settings → Display gets a "Reset all" action clearing every list back to the theme default in one click.
**Files:** `api/prefs.php`, `index.html`
**Resolved:** 2026-08-17

### ENH-038 · complete · 2026-08-17
**Title:** Subtask chevron misaligned tasks with subtasks; too much whitespace between parent and subtasks
**Requested by:** Tesh (screenshot: tasks with subtasks had their numbered circle pushed right of tasks without subtasks)
**Description:** The collapse chevron + subtask count sat before the checkbox, so any task with subtasks had its number circle shifted right relative to tasks without — misaligned columns down the list. Also wanted tighter spacing between a parent and its subtasks so the group reads as visually connected rather than separate rows.
**Fix:** Moved the chevron/count to the end of `.task-row` (after the task text) so the checkbox/number column stays left-aligned regardless of whether a task has subtasks. Tightened subtask spacing: reduced vertical padding on subtask rows, removed the row-separator border between consecutive subtasks in the same group (restored only after the last one via a `:has()` sibling check), and pulled the first subtask closer to its parent.
**Files:** `index.html`
**Resolved:** 2026-08-17

### ENH-037 · complete · 2026-08-17
**Title:** Subtasks — indent/outdent, Priority + Follow-up family grouping, collapse, visual clarity
**Requested by:** Tesh
**Description:** Subtasks displayed but couldn't be created, indented, or outdented through the UI. Starring or Follow-up'ing a subtask ignored it entirely (excluded from Priority; and moving a parent to Follow-up risked cascade-deleting its subtasks, since Google Tasks deletes a task's subtasks when the parent is deleted and the old move logic only ever moved the single clicked task). Also requested: clearer nesting visuals and the ability to collapse a task's subtasks.
**Design decisions (clarified with Tesh first):** Indent/outdent via both buttons and drag (right to indent, left to outdent — 40px threshold). Starring a subtask does NOT auto-star its parent; the whole family (parent + subtasks) just displays together in Priority so context is visible, with each row showing its own real star state. Marking any family member (parent or a subtask) for Follow-up moves the whole family together, preserving the parent/child structure in the destination list. Subtasks default to expanded, with collapse state remembered per-task via the existing server-synced prefs mechanism.
**Fix:** `api/tasks.php` POST now accepts `parent`/`previous` as URL query params on create (Google Tasks requires them there, not in the body) — needed for indenting and for recreating subtasks under a new parent when a family moves lists. New `crossListMoveFamilyAPI()` (parent + N children, created together in the destination and deleted together from the source) replaces the old single-task-only cross-list move for the ◑ Follow-up feature, the drag-across-lists feature, and any future cross-list family move — with a best-effort rollback if a later step in the sequence fails before any originals are deleted, so a network blip results in "nothing changed" rather than a duplicated family. New `indentTask()`/`outdentTask()` call the existing `move` action with `parent` set or omitted. `createListCard()` computes each root's valid indent target (the nearest preceding task in true display order, walking up to its parent if that's itself a subtask, to avoid 2-level nesting) and a per-task subtask count/collapse state (`state.taskCollapsed`, synced via `api/prefs.php` alongside the existing `collapsed`/`stars`/`settings` fields). `renderPriorityCard()` now surfaces a starred subtask's whole family, including a completed parent if it still has an active starred child. `deleteTask()` now removes local subtasks when their parent is deleted, mirroring Google's cascade-delete instead of leaving orphaned subtask rows pointing at a parent that no longer exists. Visual: accent-tinted subtask rail (was the generic rule color), a bolded parent-with-subtasks label, and an always-visible chevron + count badge so a task's subtasks are obvious even while collapsed.
**Files:** `api/tasks.php`, `api/prefs.php`, `index.html`
**Resolved:** 2026-08-17

### ENH-036 · complete · 2026-08-15
**Title:** Display settings + theme not synced across devices, reset on storage clear
**Requested by:** Tesh
**Description:** Theme and the Settings toggles (hide completed, tetris stacking, alphabetical sort, all-tasks view, daily ritual) lived only in `localStorage`, so a new device started from defaults and any browser-storage clear (e.g. the PWA reinstalls from BUG-006/007) wiped them.
**Design call:** Extended the existing per-user JSON prefs file (`data/prefs_{userId}.json` via `api/prefs.php`) rather than introducing a database table — stars, list order, and collapsed state already use exactly this mechanism successfully, so this is the established, working pattern for this category of data, not a new one.
**Fix:** `api/prefs.php` now also stores/returns a `settings` object (the toggles plus theme, folded into one). Every setting setter (`setHideCompleted`, `setTetrisMode`, `setAlphabeticalSort`, `setAllTasksMode`, `setRitualEnabled` including its failure-revert path) and `applyTheme()` now call `savePrefs()` alongside their existing `localStorage` write. New `applyServerSettings()` applies the fetched settings/theme back on load (skips re-saving what it just loaded, via an `applyTheme(theme, skipSave)` flag) — called from `loadAll()`'s existing prefs-fetch block, so it follows the same "fetched on full load" timing already used for stars/collapsed/list order.
**Files:** `api/prefs.php`, `index.html`
**Resolved:** 2026-08-15

### ENH-035 · complete · 2026-08-15
**Title:** Make the Help modal responsive — it had grown too long to browse comfortably
**Requested by:** Tesh
**Description:** After adding the "Install as an App" and "AI Assistants" sections, the Help modal got noticeably longer. Wanted it mobile-responsive or resizable, whichever gives the better experience, so cards are either all visible or easy to scroll to.
**Design call:** Went with fully responsive rather than user-resizable — a drag-to-resize modal is an unusual pattern for content like this and doesn't work at all on mobile, whereas responsive sizing helps on every device automatically.
**Fix:** Desktop: widened the modal cap from 680px to 900px, letting the card grid fit 4 columns instead of 3 (same `repeat(auto-fill, minmax(190px,1fr))` grid, just more room), meaningfully shortening the scroll on wide screens. Mobile (`≤640px`): modal now fills the full viewport (`100dvh` with `100vh` fallback, no border-radius) instead of floating as a constrained card, maximizing scrollable space; the header stays `position: sticky` throughout. Verified visually at both a wide desktop width (4-column grid, most sections visible without scrolling) and 375px mobile (full-screen sheet, single-column, sticky header) by force-opening the modal via JS on an unauthenticated load.
**Files:** `index.html`
**Resolved:** 2026-08-15

### ENH-034 · complete · 2026-08-15
**Title:** PWA install instructions in Help panel (Mac, Windows, iPhone)
**Requested by:** Tesh
**Description:** After walking through the actual install/reinstall process together (see BUG-007), add proper platform-specific install instructions to the in-app Help panel so users don't have to ask.
**Fix:** New "Install as an App" section in the Help panel with 3 cards: Mac (Chrome — address bar install icon or ⋮ menu), Windows (Chrome/Edge — same pattern), iPhone/iPad (must use Safari — Share → Add to Home Screen; explicitly notes Chrome on iOS can't install apps, an easy mistake to make).
**Files:** `index.html`
**Resolved:** 2026-08-15

### ENH-033 · complete · 2026-08-14
**Title:** PWA Dock icon not updating after icon changes
**Requested by:** Tesh
**Description:** After the TaskStick rebrand, the app's icon in the macOS Dock (installed as a PWA via Chrome) still showed the old mark. Wanted to know if this is fixable so future icon changes propagate.
**Root cause:** Not a bug in the app — a known limitation of how Chrome installs PWAs on macOS. At install time Chrome bakes the manifest's icon into a native `.app` wrapper; neither Chrome nor macOS reliably re-fetches it later when the manifest icon changes. Icon updates for already-installed PWAs are unreliable to propagate automatically, unlike page content.
**Fix:** Versioned the icon URLs (`?v=2`) in `manifest.json`, the `<link rel="apple-touch-icon">`/favicon tags in `index.html`, and the service worker's pre-cache list (bumped `CACHE_VERSION` to `taskstick-v3`) — maximizes the chance future icon changes get picked up. For the *current* stale Dock icon, the reliable fix is a manual reinstall (remove from Dock + `chrome://apps` uninstall, then revisit tasks.tesh.ai and reinstall).
**Files:** `manifest.json`, `index.html`, `sw.js`
**Resolved:** 2026-08-14

### ENH-032 · complete · 2026-08-13/14
**Title:** Back out Shared Tasks / Connected Users feature (ENH-031) — archived, not deleted
**Requested by:** Tesh
**Description:** After live-testing ENH-031, Tesh decided the feature needs more design thought before shipping. Requested: remove the share icons and Connected Users from Settings, archive the code for a possible future revisit, and move the real data (his App Ideas tasks, shared during testing) back to Google Tasks.
**Fix:** Removed all sharing/connections UI, JS wiring, and CSS from `index.html` (share icons on lists/tasks, Settings → Connected Users section, share modal, Shared card, related help-panel entries and login-screen bullet). Removed `api/share.php`, `api/connections.php`, `shared_tasks_db.php` from the live server and moved them (verbatim, not reconstructed) into `archive/sharing-feature-2026-08-13/` along with a README documenting the full design, every removed HTML/CSS/JS block, and restoration notes. Left the `shared_tasks`/`shared_task_participants` MySQL tables in place (empty, harmless) rather than dropping them. Restored all 7 real shared tasks (6 from Tesh's "App Ideas" list, 1 from the test account's "Personal" list) back into Google Tasks via a one-time authenticated migration script, then deleted the script — confirmed via direct DB query that the table was empty afterward, so nothing was lost.
**Files:** `index.html`, `archive/sharing-feature-2026-08-13/*` (moved), MySQL (data migrated, tables kept)
**Resolved:** 2026-08-14

### ENH-031 · archived, see ENH-032 · 2026-08-13
**Title:** Real shared tasks/lists (jointly-owned, full parity, kept in sync)
**Requested by:** Tesh
**Description:** Replace the old one-way "FYI snapshot" share feature (share a task → recipient sees a copy, can mark their own copy done, never touches the real task) with true jointly-owned sharing: sharing a task or list moves it out of Google Tasks into a shared space; any participant (owner included) can edit, complete, delete, or change who it's shared with, with all attributes (title, notes, due date, completion, priority/star) kept in sync for everyone.
**Design decisions (clarified with Tesh first):**
- Storage: shared items live entirely in our own MySQL database (new `shared_tasks` / `shared_task_participants` tables), NOT mirrored into Google Tasks for either party — sidesteps the fact that Google's Tasks API has no cross-account sharing or push/webhook support. Personal (non-shared) tasks are completely untouched, still proxied live from Google Tasks as before.
- Structure: one unified "Shared" card per user (not per-connection), aggregating everything they're a participant in — matches the existing Follow-up/Ritual card pattern.
- Permissions: full parity — any participant can edit, complete/incomplete, delete (removes for everyone), or change the participant list.
- Multi-recipient: a task/list can be shared with several connected people at once; it's a single row with N participants, not per-person copies — one edit/complete syncs for all of them.
- Encryption: explicitly deferred — plain-text at rest for now, consistent with the rest of the app's trust model (revisit later if wanted).
**Fix:** New `db.php` (extracted shared PDO helper, also refactored `feedback_db.php` to use it) and `shared_tasks_db.php` (schema + CRUD, `SharedTaskAuthException` for 403s). Rewrote `api/share.php` entirely: `share`/`share_list` create DB rows and delete the migrated task(s) from Google Tasks via `googleApiRequest()`; `update`/`complete`/`set_starred`/`set_participants`/`delete` give full-parity management, each authorization-checked against `shared_task_participants`. Frontend: `state.sharedTasks` (renamed from `sharedWithMe`), fully rewritten `renderSharedTasksCard()`/`renderSharedTaskItem()` with the same actions as a regular task row (checkbox, star, due date, notes, inline title edit, delete) plus a "manage sharing" 🔗 button reusing the share modal pre-checked with current participants (`openManageParticipants()`). `doShare()` rewritten for the new payload shape and to reload lists + shared tasks after a share completes. Also fixed two related staleness bugs surfaced while building this: `renderPriorityCard()` was skipping its shared-card render entirely whenever nothing was starred (early return before the tail call — moved the call earlier); and `loadSharedTasks()` was only ever called on the very first page load, never on the 2-minute poll, so a connection's edits wouldn't show up without a full reload.
**Scoping notes for Tesh:** Sharing a whole list only migrates its active top-level tasks (not subtasks, not already-completed history) — the original (now emptier) Google Tasks list is left alone rather than auto-deleted, since deleting completed-task history as a side effect of sharing felt too destructive as a default. There's no drag-to-reorder within the Shared card yet (matches the old card's scope). No self-only "leave this share" action yet — leaving requires either deleting it (removes for everyone) or another participant removing you via "manage sharing."
**Files:** `db.php`, `feedback_db.php`, `shared_tasks_db.php`, `api/share.php`, `index.html`
**Resolved:** 2026-08-13 · not yet tested live with two real connected accounts — needs Tesh to verify end-to-end

### ENH-030 · complete · 2026-08-13
**Title:** Rewrite login-screen feature list
**Requested by:** Tesh
**Description:** Call out creating lists, adding tasks, and syncing with Google Tasks explicitly; add other important features.
**Fix:** Replaced the 4-bullet list with 6: create lists + add tasks, star/Priority, due dates & notes, Daily Ritual, sharing/Connections, and explicit Google Tasks sync.
**Files:** `index.html`
**Resolved:** 2026-08-13

### ENH-029 · complete · 2026-08-13
**Title:** OAuth Tasks-scope warning + re-consent flow
**Requested by:** Tesh
**Description:** Users can uncheck the Google Tasks permission on the OAuth consent screen and still complete sign-in, silently ending up with a broken app (no task access). Warn them clearly and give a second chance to grant it.
**Fix:** `auth/callback.php` now inspects the `scope` field Google returns from the token exchange (not just what we requested — what was actually granted) and stores `$_SESSION['hasTasksAccess']`. `api/auth-status.php` surfaces this as `user.hasTasksAccess` (defaults to `true` for sessions created before this fix, so existing logins aren't falsely flagged). Frontend shows a non-dismissible-by-backdrop warning modal on load when `hasTasksAccess === false`, explaining tasks won't be backed up/synced and most features won't work, with a "Grant Task Access" button (relaunches `/auth/login`, which re-shows Google's consent screen) and a "Continue with limited features" dismiss option.
**Files:** `auth/callback.php`, `api/auth-status.php`, `index.html`
**Resolved:** 2026-08-13

### ENH-028 · complete · 2026-08-13
**Title:** Admin/Feedback modal readability + theming
**Requested by:** Tesh
**Description:** Admin and Send Feedback modals were unreadable — several elements (`admin-row-name`, the type/status `<select>`, the feedback `<textarea>`) used `color: inherit`, which pulled in the active theme's *light-background* text color while the modal itself had a hardcoded dark-violet background (copied from the Settings modal, which has never been theme-aware). Tesh asked for these two modals to match the selected theme instead.
**Fix:** Added `#admin-overlay`/`#feedback-overlay`-scoped CSS overrides that swap the modal background/border/text colors to the theme's own CSS custom properties (`--card-bg`, `--card-border`, `--text-primary`, `--text-muted`, `--input-bg`, `--input-border`), rather than making the shared `.settings-modal` (used by Settings too) theme-aware — kept the blast radius to just the two reported modals. Removed the feedback textarea's conflicting inline `color:inherit`/background/border (inline styles beat stylesheet rules regardless of specificity). New `.modal-dismiss-btn` class (theme-aware) replaces a reused `.user-dropdown-item` that had the same hardcoded-white-text bug, used by the new scope-warning modal (ENH-029).
**Files:** `index.html`
**Resolved:** 2026-08-13

### ENH-027 · closed (no change needed) · 2026-08-13
**Title:** Admin Users list should show all users regardless of activity
**Requested by:** Tesh
**Description:** Wants to see every user who has ever logged in, even if not "currently active."
**Investigation:** Already the existing behavior — `GET /api/admin.php?resource=users` returns every record in `data/users.json` unfiltered, sorted by `last_seen` descending; there is no online/offline or recency-based exclusion anywhere in the code. "Last active"/"Joined" (added in ENH-025) are informational timestamps only, not a filter. No code change made; flagged to Tesh to confirm this matches what he's seeing once a second real user has logged in.
**Files:** none

### ENH-026 · complete · 2026-08-12
**Title:** Daily Ritual list (resets every task at local midnight)
**Requested by:** Tesh
**Description:** A recurring checklist, toggled on in Settings, that lives as a real Google Tasks list named "Daily Ritual" and shows as a board card (teal accent, 🔁 prefix). Any task checked off gets reset back to incomplete the next day, at midnight in the user's own timezone.
**Fix:** `setRitualEnabled()` finds-or-creates the "Daily Ritual" list (same pattern as Follow-up) and toggles `state.settings.ritualEnabled` (persisted to localStorage). `getDisplayLists()` hides the list entirely when the toggle is off and pins it near the top (with Follow-up) when alphabetical sort is on. `resetRitualIfNewDay()` runs after every `loadAll()` (initial load + 2-min poll): for each completed Ritual task whose Google Tasks `completed` timestamp's *local* calendar date isn't today, PATCHes it back to `needsAction` — pure client-side date comparison, no server cron needed. New Settings → Daily Ritual section with the toggle; new help-panel card explaining it.
**Files:** `index.html`
**Resolved:** 2026-08-12

### ENH-025 · complete · 2026-08-12
**Title:** Admin system (user roles + admin views)
**Requested by:** Tesh
**Description:** Let hitesh.patel44@gmail.com (and anyone he promotes) act as an Admin with access to admin-only views, starting with a Users list (toggle admin on/off for anyone who has ever logged in) and a Feedback view (see ENH-024).
**Fix:** `data/users.json` registry, upserted by `registerUser()` on every OAuth login (`auth/callback.php`) — seeds `is_admin: true` for emails in `ADMIN_SEED_EMAILS` (currently just hitesh.patel44@gmail.com) the first time they log in, otherwise just refreshes name/picture/last_seen. `isAdmin()` / `isAdminEmail()` helpers in `config.php`. New `api/admin.php`: `GET ?resource=users` (admin-only, 403 otherwise), `POST {action:'toggle_admin', email}`. `api/auth-status.php` now returns `user.isAdmin`. Frontend: "Admin" item in the profile dropdown (only rendered when `state.user.isAdmin`), new Admin modal reusing the Settings modal's CSS with a Users section (avatar, name, email, last-seen, admin toggle) and a Feedback section (see ENH-024).
**Files:** `config.php`, `auth/callback.php`, `api/admin.php`, `api/auth-status.php`, `index.html`
**Resolved:** 2026-08-12

### ENH-024 · complete · 2026-08-12
**Title:** User feedback submission (bug / feature request / suggestion)
**Requested by:** Tesh
**Description:** Any user can submit feedback (bug, feature request, or suggestion) from the profile dropdown; submissions are stored in a real MySQL database (Tesh's explicit choice over JSON-file storage) and reviewable by admins in the Admin → Feedback view.
**Fix:** New `feedback_db.php` — PDO/MySQL layer, auto-creates the `feedback` table (`id, type, description, submitter_email, submitter_name, status, created_at`) on first use. New `api/feedback.php` (`POST {type, description}`, authenticated users only). `api/admin.php` exposes `GET ?resource=feedback` and `POST {action:'update_feedback_status', id, status}`. Frontend: "Send Feedback" item in the profile dropdown opens a modal (type select + description textarea); Admin → Feedback lists all submissions with a status dropdown (New/Reviewed/Resolved). `config.php` now holds the IONOS MySQL credentials (`db5021177980.hosting-data.io` / `dbs16001460`); verified end-to-end with a temporary connectivity check (uploaded, tested `feedbackDb()` successfully creates the table, then removed) before wiring it in for real.
**Files:** `feedback_db.php`, `api/feedback.php`, `api/admin.php`, `config.php`, `index.html`
**Resolved:** 2026-08-12

### ENH-023 · complete · 2026-08-12
**Title:** Rebrand Tasktical to TaskStick, new logo
**Requested by:** Tesh
**Description:** Rename the app from "Tasktical" to "TaskStick" throughout (title, meta tags, manifest, masthead, login screen, help panel, legal pages, invite emails). Replace the old "PPS pill capsule" mark with a new logo: a stack of fanned yellow Post-it notes (List feature) with a checklist of purple checkmarks on the top note. Concept chosen after iterating with Tesh (rejected an all-purple pin/wand direction; he wanted a Post-it visual in authentic Post-it yellow).
**Fix:** New mark generated as SVG (violet checkmark strokes + gradient Post-it yellow, faceted stack of 3 notes) rendered to PNG. Replaced base64-embedded masthead + login-screen icon in `index.html`, updated `<title>`, `apple-mobile-web-app-title` meta, `alt` text, h2 login heading, feature blurb, help-panel version label. Updated `manifest.json` name/short_name/description. Regenerated `icons/favicon-32.png`, `icon-180.png`, `icon-192.png`, `icon-512.png`, `icon-512-maskable.png`. Replaced `brand-assets/tasktical-*.png` with `brand-assets/taskstick-*.png` (icon/logo/splash × light/dark). Removed unused legacy `icons/pill_transparent_512.png`. Text-swapped "Tasktical"→"TaskStick" in `terms.html`, `privacy.html`, `api/connections.php` (invite email), `GOOGLE_VERIFICATION_GUIDE.md`. Kept the internal `[tasktical-origin:...]` Follow-up task marker readable for backward compatibility (`ORIGIN_RE` now matches both `tasktical-origin` and `taskstick-origin`) while new writes use `taskstick-origin` — avoids breaking already-stored Follow-up tasks in Google Tasks.
**Files:** `index.html`, `manifest.json`, `terms.html`, `privacy.html`, `api/connections.php`, `icons/*`, `brand-assets/*`
**Resolved:** 2026-08-12

### ENH-018 · complete · 2026-04-13
**Title:** Move theme selector to Settings; add Compact, Ocean, Rose themes
**Requested by:** Tesh
**Description:** Remove the Notebook/Modern toggle from the header. Add a Display section in Settings with a theme chip picker (Notebook, Modern, Compact, Ocean, Rose). Themes use CSS variables so new ones are pure CSS.
**Fix:** Removed header theme-toggle button and toggleTheme()/old applyTheme(). Added THEMES constant array (5 entries). New applyTheme() sets body.theme-X class, calls renderThemePicker(). New renderThemePicker() renders chip buttons into #theme-picker div in Settings Display section. Three new themes (Compact, Ocean, Rose) defined as full CSS variable blocks. openSettings() now calls renderThemePicker() to sync chip active state.
**File:** index.html
**Resolved:** 2026-04-13

---

### ENH-017 · complete · 2026-04-13
**Title:** Tetris stacking layout mode
**Requested by:** Tesh
**Description:** New setting "Tetris stacking" that switches the board from flex-wrap rows to CSS columns layout, packing cards to fill vertical space and eliminating whitespace gaps.
**Fix:** Added setTetrisMode(val) which toggles .tetris-mode on #board and persists to state.settings.tetrisMode (localStorage). #board.tetris-mode uses CSS columns layout (300px column width, break-inside:avoid on cards). Applied on DOMContentLoaded and re-applied at end of renderBoard(). Checkbox in Settings Display section triggers setTetrisMode; openSettings() syncs checkbox to current state.
**File:** index.html
**Resolved:** 2026-04-13

---

### ENH-016 · complete · 2026-04-13
**Title:** Move task action icons below task text
**Requested by:** Tesh (screenshot showing crowded icons)
**Description:** All action buttons (due date, notes, partial-complete, share, star, delete) crowd the right side of the task text leaving no room. Move them to a .task-actions-row that slides in below the task text on hover.
**Fix:** Restructured renderTaskItem() and renderPriorityTaskItem() to have a .task-row (drag handle + checkbox + text only) followed by a .task-actions-row div containing all action buttons. .task-actions-row uses max-height/opacity CSS transition to slide in on .task-item:hover. Buttons in the row inherit full opacity within the row context.
**File:** index.html
**Resolved:** 2026-04-13

---

### ENH-015 · complete · 2026-04-13
**Title:** Task sharing — invite users, share tasks/lists, Shared Tasks card
**Requested by:** Tesh
**Description:** Full sharing system: (1) Settings → Connected Users section to invite by email and accept/decline invites. (2) Share button on tasks and list headers → multi-select modal of connections → shares task snapshot to recipient's Shared Tasks view. (3) Shared Tasks virtual card shows all tasks shared with current user. Any recipient completing a shared task marks it complete in the view. Backend: server-side JSON files (data/connections.json, data/shared_tasks.json). New PHP: api/connections.php, api/share.php.
**Fix:** New api/connections.php (invite/accept/decline/remove), api/share.php (share/complete/dismiss/unshare), data/.htaccess. index.html: new CSS, share modal HTML, settings Connected Users section, openShareModal/doShare/sendInvite/acceptInvite/declineInvite/removeConnection/renderSharedTasksCard/loadConnections/loadSharedTasks/checkInviteToken functions. loadAll() now fetches connections+shared on first load.
**Resolved:** 2026-04-13

---

### ENH-014 · complete · 2026-04-13
**Title:** Drag-and-drop on Priority and Follow-up lists
**Requested by:** Tesh
**Description:** Priority card (starred tasks) has no drag handles — add Sortable with localStorage order persistence. Follow-up list (a real Google Tasks list) uses the standard createListCard drag-and-drop already; needs special card styling.
**Fix:** renderPriorityCard() now sorts starredItems by localStorage 'tasks-priority-order', adds drag handles to renderPriorityTaskItem(), and initialises Sortable on #priority-task-list saving order to localStorage on drag end. Follow-up list auto-detected by title in createListCard() and styled with amber top border + ◑ prefix.
**Resolved:** 2026-04-13

---

### ENH-013 · complete · 2026-04-13
**Title:** Partially complete task — moves to Follow-up list
**Requested by:** Tesh
**Description:** Add a ◑ (half-circle) button to each task. When clicked, the task moves exclusively to a Google Tasks list named "Follow-up" (created automatically if it doesn't exist). Special amber styling distinguishes the Follow-up card on the board.
**Fix:** Added markPartialComplete() which finds/creates Follow-up list via /api/lists.php POST, then calls crossListMoveAPI(). Added .partial-btn CSS + button in renderTaskItem(). createListCard() detects title==='Follow-up' and adds .followup-card class.
**Resolved:** 2026-04-13

---

### ENH-012 · complete · 2026-04-08
**Title:** Settings menu with hide-completed-tasks toggle
**Requested by:** Tesh
**Description:** Add a settings panel accessible from the header with a "Hide completed tasks" toggle (and future preference settings). State persists in localStorage. When enabled, completed tasks are hidden from all lists without deleting them.
**Fix:** Added `state.settings` (persisted in `localStorage` as `tasks-settings`). Added `openSettings()`, `closeSettings()`, `setHideCompleted()` JS functions. Added settings modal HTML with pill-toggle switch. Modified `createListCard()` to filter out completed tasks and their subtasks when `hideCompleted` is true. Settings accessible via profile dropdown.
**File:** `index.html`
**Resolved:** 2026-04-08

---

### ENH-011 · complete · 2026-04-08
**Title:** Replace separate Sign out button with profile-photo dropdown menu
**Requested by:** Tesh
**Description:** The profile photo and Sign out button were misaligned in the header. Sign out should appear as a dropdown menu item when the profile photo is clicked, keeping the header clean.
**Fix:** Replaced `renderUserArea()` with a dropdown implementation. Avatar click toggles `.user-dropdown.open`. Dropdown shows user name, email, Settings button, and Sign out link. Document click listener closes dropdown. Added `.user-menu`, `.user-dropdown`, `.user-dropdown-item` CSS.
**File:** `index.html`
**Resolved:** 2026-04-08

---

### ENH-010 · complete · 2026-04-08
**Title:** Pill icon lost in dark masthead background — needs visibility fix
**Requested by:** Tesh
**Description:** The dark pill icon is invisible against the dark navy masthead. Need a glow or backdrop to make it legible at small sizes.
**Fix:** Added `filter: drop-shadow(0 0 6px rgba(160,80,255,0.75)) drop-shadow(0 0 14px rgba(120,60,220,0.45))` to `.logo-mark`. Purple glow matches brand palette and makes pill visible on dark backgrounds.
**File:** `index.html`
**Resolved:** 2026-04-08

---

### ENH-009 · complete · 2026-04-02
**Title:** Move terms/privacy pages to tasks.tesh.ai and update all links
**Requested by:** Tesh
**Description:** terms.html and privacy.html were not deployed to the tasks.tesh.ai server, and all cross-links used relative paths (`/terms.html`) which would resolve incorrectly from the root domain. Required absolute URLs for Google Cloud Console consent screen configuration.
**Fix:** Updated all relative `/terms.html` and `/privacy.html` links to absolute `https://tasks.tesh.ai/terms.html` and `https://tasks.tesh.ai/privacy.html` in index.html, terms.html, and privacy.html. Deployed all three files to `/tasks-app/` on IONOS.
**Files:** `index.html`, `terms.html`, `privacy.html`
**Resolved:** 2026-04-02

---

### ENH-008 · complete · 2026-04-02
**Title:** Enhance login screen for Google OAuth verification requirements
**Requested by:** Tesh (Google verification checklist)
**Description:** Google requires the pre-login homepage to: identify the app/brand, describe functionality, explain data access purpose, and link to privacy policy — all without requiring login.
**Fix:** Expanded login card in `index.html`: added "by Purple Pill Solutions" tagline, feature bullet list, explicit data-access notice explaining the `tasks` scope and what it does/doesn't access, Google account permissions link, and Terms of Service + Privacy Policy links below the sign-in button. Widened card to 480px. Added CSS for `.login-tagline`, `.login-features`, `.login-data-notice`, `.login-note a`.
**File:** `index.html`
**Resolved:** 2026-04-02

---

### ENH-007 · complete · 2026-04-02
**Title:** Rebrand masthead to TaskR with prism logo, add footer with legal links
**Requested by:** Tesh
**Description:** Three changes: (1) rename app to "TaskR" throughout the UI, (2) replace ✅ emoji in masthead with new prism icon and dark navy brand header (#0d0b1e), (3) add persistent footer with Terms of Service and Privacy Policy links.
**Fix:** Updated `index.html`: title → "TaskR — tesh.ai", header `--header-bg` → `#0d0b1e` for both themes, h1 replaced with inline base64 prism icon + "TaskR" wordmark, login card icon+heading updated, `<footer id="app-footer">` added before `</body>` with ToS + Privacy links, footer CSS added.
**File:** `index.html`
**Resolved:** 2026-04-02

---

### ENH-005 · pending · 2026-03-21
**Title:** Add subtask creation through the UI
**Requested by:** Tesh
**Description:** Currently, subtasks from Google Tasks are displayed correctly (indented under
parent) but there is no way to CREATE a subtask from within the app. The PHP backend also
does not pass a `parent` parameter when creating tasks via POST.
**Suggested implementation:**
- Add a "Add subtask" button (indented `+` icon) when hovering over a task
- Update `tasks.php` POST handler to accept and forward `parent` as a URL query param
  (Google Tasks API requires `parent` in the URL, not the body)
**Priority:** Medium

---

### ENH-004 · pending · 2026-03-21
**Title:** Due date timezone offset causes date to display one day early
**Reported by:** Testing session
**Description:** When setting a due date of 2026-03-28, the badge displays "Mar 27". The
Google Tasks API stores due dates as UTC midnight (`2026-03-28T00:00:00.000Z`), which
converts to March 27 in negative-offset timezones (US/Canada). The badge uses the raw
UTC date string without local timezone correction.
**Suggested fix:** Convert the UTC date to local date before formatting in `formatDue()`.
Example: `new Date(dueStr).toLocaleDateString(...)` instead of string slicing.
**Priority:** Low (cosmetic, off by one day)

---

### ENH-003 · pending · 2026-03-21
**Title:** Star status should sync to Google Tasks (not just localStorage)
**Requested by:** Tesh
**Description:** Stars are stored in `localStorage` under the key `tasks-stars`. This means:
- Stars are lost if the user clears browser storage
- Stars don't sync across devices or browsers
- Stars are not visible in any other Google Tasks client
**Suggested implementation:** Google Tasks has a `starred` field on tasks — the PHP PATCH
handler already accepts and forwards it. The frontend needs to:
1. Read `task.starred` from API on load and merge with `state.stars`
2. Save star state via PATCH instead of/in addition to localStorage
**Priority:** Medium

---

### ENH-002 · pending · 2026-03-21
**Title:** Drag-drop to create subtasks (drag onto parent)
**Requested by:** Tesh (implied by subtask feature)
**Description:** Allow dragging a task ON TOP of another task (not between) to make it a
subtask. Would use the existing `move` API endpoint with the `parent` parameter.
**Priority:** Low

---

### ENH-001 · pending · 2026-03-21
**Title:** "Cammy Bean meeting" left in Personal Tasks list after cross-list drag test
**Reported by:** Testing session
**Description:** During feature testing, "Cammy Bean meeting" was dragged from Tasks to
Personal Tasks. It was not moved back. This is a data hygiene note, not a code issue.
**Action needed:** Manually move "Cammy Bean meeting" back to the Tasks list in Google Tasks
or in the app.
**Priority:** Low (data cleanup)

---

## ✅ Completed / Closed

### FEAT-008 · complete · 2026-03-21 — View/edit due dates (stored in Google Tasks API)
### FEAT-007 · complete · 2026-03-21 — Subtasks indented under parent task
### FEAT-006 · complete · 2026-03-21 — Mobile-friendly responsive layout
### FEAT-005 · complete · 2026-03-21 — Drag-drop tasks across lists
### FEAT-004 · complete · 2026-03-21 — Drag-drop within list (order saved to Google Tasks API)
### FEAT-003 · complete · 2026-03-21 — Auto-growing textarea for inline editing
### FEAT-002 · complete · 2026-03-21 — Starred tasks displayed in bold
### FEAT-001 · complete · 2026-03-21 — Star tasks (stored in localStorage)

---

## 📋 How to Use This File

**To add a new bug:**
```
### BUG-NNN · open · YYYY-MM-DD
**Title:** Short description
**Reported by:** Name
**Description:** ...
**Steps to reproduce:** ...
**Expected:** ...
**Fix:** —
**Resolved:** —
```

**To add a new enhancement:**
```
### ENH-NNN · pending · YYYY-MM-DD
**Title:** Short description
**Requested by:** Name
**Description:** ...
**Suggested implementation:** ...
**Priority:** High | Medium | Low
```

**To close an item:** Change `open`/`pending` to `fixed`/`complete`, add fix note and resolved date.
