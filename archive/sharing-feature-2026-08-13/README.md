# Archived: Shared Tasks / Connected Users feature

**Archived:** 2026-08-13, by Tesh's request, after live testing.
**Why:** Tesh wants to rethink the design before shipping it — not abandoned, just shelved.
**Status when archived:** Fully built and deployed (ENH-031 in ISSUES.md), including a real bug
fix (BUG-004: the share button's `onclick` was malformed HTML that silently ate every click —
fixed, then the whole feature was pulled minutes later per this request). The feature worked
end-to-end once BUG-004 was fixed.

## What it did

- "Connected Users" in Settings: invite by email, accept/decline, mutual connection.
- 🔗 share icon on every list card and task row.
- Sharing MOVED a task/list out of Google Tasks into a jointly-owned MySQL row
  (`shared_tasks` / `shared_task_participants`) — full parity: any participant could edit,
  complete, delete, or change who it's shared with; one row synced for everyone.
- Rendered as a single "🔗 Shared" card, same interaction model as a regular task row
  (checkbox, star, due date, notes, inline title edit, delete, "manage sharing").

## Files in this folder (moved here verbatim from the live project tree, not reconstructed)

- `api-share.php` — was `tasks-app-contents/api/share.php`
- `api-connections.php` — was `tasks-app-contents/api/connections.php`
- `shared_tasks_db.php` — was `tasks-app-contents/shared_tasks_db.php` (has the full DB schema
  inline — `shared_tasks` + `shared_task_participants` tables, auto-created via `CREATE TABLE
  IF NOT EXISTS`)

**Not moved here** (still active, shared with other features): `db.php` (generic `appDb()`
PDO helper, also used by `feedback_db.php`) — untouched, no changes needed if restoring.

## ⚠️ One gap in this archive

The JS half of the feature (render functions, all the shared-task CRUD handlers, connections
load/invite/accept/decline/remove, `checkInviteToken`) was removed from `index.html` in the
same session, working from a live SFTP copy — and the extracted JS text was sitting in a `/tmp`
scratch file that got cleared by an environment reset before it could be copied in here. I have
the exact text for the parts I personally authored this session (all the shared-task rendering/
CRUD functions), but **not** a verbatim copy of the pre-existing connections functions
(`sendInvite`, `acceptInvite`, `declineInvite`, `removeConnection`, `renderConnectionsInSettings`,
`loadConnections`, `checkInviteToken`) — those predate this session and I only ever viewed them
in fragments.

**If you want the exact original code back:** `index.html` is a Google Drive file with version
history — right-click it in the Drive web UI → "Manage versions" (or File → Version history →
See version history in Google Docs-style viewer) and look for a version from 2026-08-13 before
the archival edit. That will have the byte-exact original, including the connections functions.
The design/behavior notes below are enough to rebuild it from scratch either way.

## HTML/CSS removed from `index.html`

### `state` object (near top of `<script>`)
Removed these three lines from the `state = {...}` init:
```js
connections:  [],        // { email, name }[] — connected users
pendingRecv:  [],        // incoming invites waiting to accept
sharedTasks:  [],        // jointly-owned shared tasks (DB-backed; owner or participant)
```

### `loadAll()`
Removed (right after `resetRitualIfNewDay();`):
```js
// Shared tasks are jointly-owned — refresh every load (including polls)
// so edits from the other participant show up without a full reload.
loadSharedTasks();

// Connections + invite-token check only need a first full load
if (showSpinner) {
  loadConnections();
  checkInviteToken();
}
```

### `openSettings()`
Removed the trailing `loadConnections();` call (with its comment) after
`document.getElementById('settings-overlay').classList.add('open');`.

### `renderPriorityCard()`
Removed (right before `if (!starredItems.length) return;`):
```js
// Shared card doesn't depend on Priority having anything — render it
// regardless so it stays current even when nothing is starred.
renderSharedTasksCard();
```

### List card header — `list-share-btn` (in `createListCard()`)
```html
<button class="list-share-btn"
        onclick="openShareModal('list','${escHtml(list.id)}','${escHtml(list.id)}',${escHtml(JSON.stringify(list.title))},this)"
        title="Share this list">🔗</button>
```
Sat between the `task-count` span and the `list-delete-btn`.

### Task row — `share-task-btn` (in `renderTaskItem()` AND `renderPriorityTaskItem()`, identical in both)
```html
<button class="share-task-btn"
        onclick="openShareModal('task','${escHtml(task.id)}','${escHtml(listId)}',${escHtml(JSON.stringify(task.title))},this)"
        title="Share task">🔗</button>
```
Sat between the partial-complete (◑) button and the star button.

### Settings modal — "Connected Users" section
```html
<div class="settings-section">
  <div class="settings-section-title">Connected Users</div>
  <div id="connections-wrap"></div>
  <div class="invite-input-row" style="margin-top:10px">
    <input id="invite-email-input" class="invite-email-input"
           type="email" placeholder="someone@example.com"
           onkeydown="if(event.key==='Enter') sendInvite()" />
    <button id="invite-send-btn" class="invite-send-btn"
            onclick="sendInvite()">Invite</button>
  </div>
  <div style="font-family:var(--ui-font);font-size:0.68rem;color:rgba(255,255,255,0.3);margin-top:4px">
    They'll receive an email with a link to connect.
  </div>
</div>
```
Was the last section in the settings modal, right after "Daily Ritual".

### Share modal (whole standalone overlay, right before the footer)
```html
<!-- ── SHARE MODAL ── -->
<div class="share-overlay" id="share-overlay" onclick="if(event.target===this)closeShareModal()">
  <div class="share-modal" role="dialog" aria-label="Share">
    <div class="share-modal-header">
      <h3 id="share-modal-title">Share</h3>
      <button class="settings-close-btn" onclick="closeShareModal()" title="Close">✕</button>
    </div>
    <div class="share-modal-body">
      <div id="share-users-list"></div>
    </div>
    <div class="share-modal-footer">
      <button class="share-cancel-btn" onclick="closeShareModal()">Cancel</button>
      <button class="share-confirm-btn" onclick="doShare()">Share</button>
    </div>
  </div>
</div>
```

### Help panel — two cards under "Sharing & display" (renamed to just "Display" after removal)
```html
<div class="help-card">
  <div class="help-card-icon">🔗</div>
  <div class="help-card-name">Share Tasks</div>
  <div class="help-card-desc">Hover a task and click 🔗 to share it with a connected user. They see it in Shared Tasks.</div>
</div>
<div class="help-card">
  <div class="help-card-icon">👤</div>
  <div class="help-card-name">Connections</div>
  <div class="help-card-desc">Settings → Connected Users — invite colleagues by email to enable sharing.</div>
</div>
```

### Login-screen feature list
Removed bullet: `<li>Share lists and tasks with people you connect with</li>`

### CSS removed (selectors only)
`.list-share-btn` (+ theme overrides), `.share-task-btn` (+ `.task-actions-row .share-task-btn`
variants), `.list-card.shared-tasks-card` (+ theme override), `.shared-from-label`,
`.share-overlay`, `.share-modal*`, `.share-user-item*`, `.share-confirm-btn`, `.share-cancel-btn`,
`.share-no-connections`, `.invite-input-row`, `.invite-email-input*`, `.connection-chip*`,
`.invite-chip*`, `.invite-accept-btn`, `.invite-decline-btn`, `.no-connections-msg`,
`#board.tetris-mode #shared-tasks-card`, `#board.all-tasks-mode #shared-tasks-card`.
**Kept:** `.invite-send-btn` / `.invite-send-btn:hover` — reused by the Feedback submit button
and the scope-warning modal's "Grant Task Access" link, do NOT remove if restoring only sharing.

## Database

`shared_tasks` and `shared_task_participants` tables were left in place on the live MySQL
database (not dropped) — harmless if unused, and `shared_tasks_db.php`'s `CREATE TABLE IF NOT
EXISTS` means restoring the code just picks the tables back up as-is. Real data (Tesh's actual
App Ideas tasks + a test task from the second account) was migrated back to Google Tasks via a
one-time `_restore_shared_tasks.php` script (not archived — delete-after-use, safe to recreate
from `shared_tasks_db.php`'s `sharedTaskDelete()` + `googleApiRequest()` if ever needed again).
Confirmed via direct DB query: 0 rows remaining after the restore.

## Design notes for next time (from the ENH-031 write-up, worth reconsidering)

- Storage lives in our own DB, not mirrored to Google Tasks (Google's API has no cross-account
  sharing or push support) — this was a deliberate, discussed tradeoff, likely still right.
- One unified "Shared" card per user, not per-connection — also deliberate.
- Full parity permissions (any participant can edit/complete/delete/reshare).
- Multi-recipient: single row, N participants, one edit syncs for all — not per-person copies.
- Known gaps at archive time: sharing a list only migrates active top-level tasks (not
  subtasks, not completed history); no drag-reorder in the Shared card; no "leave this share
  for myself only" action (leaving = delete for everyone, or ask someone else to remove you).
- Encryption at rest was explicitly deferred, not forgotten.
