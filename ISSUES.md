# tasks.tesh.ai — Bug Tracker & Enhancement Requests

> **Format for Claude:** Each item has a unique ID, type, status, date, and description.
> To update: change `status`, add `resolved` date and `fix` note. Append new items at the top of their section.
> Statuses: `open` | `in-progress` | `fixed` | `wontfix` | `pending` | `complete`

---

## 🐛 Bugs

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
