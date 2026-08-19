# TaskStick

A custom Google Tasks web app, running at [tasks.tesh.ai](https://tasks.tesh.ai). PHP + vanilla JS, no framework, no build step. Includes an MCP server so AI assistants (Claude, ChatGPT, etc.) can read and manage tasks directly.

## Features

- Google Tasks lists and tasks, live (no local task database — see Storage below)
- Subtasks: indent/outdent via button or drag, collapsible, grouped with their parent for Priority/Follow-up
- Priority card (starred tasks), Follow-up card, self-resetting Daily Ritual list
- Per-list custom background colors, independent of the active theme
- 5 themes (Notebook, Modern, Compact, Ocean, Rose), fully theme-aware including every modal
- User feedback capture + an Admin panel (Users with self-reported usage stats, Feedback grouped by type with soft-delete + Markdown export)
- MCP server so Claude/ChatGPT/etc. can read and manage tasks directly
- PWA (installable, offline-tolerant, auto-update detection)
- **Apple Reminders sync (beta, in progress)** — syncs Google Tasks → Apple Reminders via CalDAV, Google Tasks stays the trusted source. Built in 3 stages; 1 (connect) and 2 (push, auto-create missing lists) are live, 3 (pull completion status back) not started. This is the one area of the app not yet wire-tested against a real Apple server in a Claude session — treat any CalDAV-touching change here as needing live verification.

## Architecture

- **Frontend**: single-page app in [`index.html`](index.html) — vanilla JS, no bundler. Theming via CSS custom properties (Notebook, Modern, Compact, Ocean, Rose) — every modal/dialog shares one base theme-aware styling (`.settings-modal` and friends) rather than per-dialog overrides, so a new theme or dialog stays consistent automatically.
- **Backend**: plain PHP files under [`api/`](api/) and [`auth/`](auth/), no framework or router. Shared helpers in [`config.php`](config.php) and [`lib/`](lib/).
- **Auth**: Google OAuth 2.0 (authorization code flow with refresh tokens) against the Google Tasks API. See [`auth/`](auth/) and `googleApiRequest()` / `refreshAccessToken()` in [`config.php`](config.php).
- **Storage**:
  - Google Tasks API is the source of truth for tasks/lists themselves — **this app has no local database table for tasks at all**. Anything that needs to reference a specific task (e.g. the Apple Reminders sync mapping) links to it by Google's own task ID rather than mirroring the task data locally.
  - MySQL (via PDO, see [`db.php`](db.php)) for feedback submissions, the Apple Reminders `apple_reminder_links` mapping table (see [`apple_sync_db.php`](apple_sync_db.php)), and the archived shared-tasks feature.
  - JSON files under `data/` (git-ignored, server-only) for per-user prefs, the admin registry, MCP tokens/API keys, and Apple Reminders credentials (`data/apple_sync_{userId}.json`, AES-256-CBC-encrypted app-specific password — see [`lib/Encryption.php`](lib/Encryption.php)). Writes to these files use a locked read-modify-write (`updateJsonFile()`/`updateUsers()` in `config.php`) to avoid lost-write races between concurrent requests.
- **PWA**: [`manifest.json`](manifest.json) + [`sw.js`](sw.js) service worker, network-first caching, [`api/version.php`](api/version.php) drives client-side update detection.
- **MCP server**: [`mcp/`](mcp/) is a Streamable HTTP JSON-RPC 2.0 server (raw PHP, no SDK) exposing task read/write tools to AI assistants. Auth is a per-user bearer API key (separate from the main app's session auth), generated at `/mcp/setup.php`.
- **Apple Reminders sync**: [`lib/CalDAV.php`](lib/CalDAV.php) is a hand-rolled PROPFIND/REPORT/PUT/MKCALENDAR client for iCloud CalDAV (Reminders lists are calendar collections restricted to the VTODO component), modeled on a sibling Contacts app's proven CardDAV client. `api/apple-sync.php` runs the push (self-throttled, runs automatically in the background or on demand via "Sync Now"), `api/apple-settings.php` handles connecting/list selection.
- **Admin**: seeded from `ADMIN_SEED_EMAILS` in `config.php`. Admins get a tabbed Users/Feedback view at `/api/admin.php`-backed endpoints — Users shows self-reported per-account list/task counts (there's no way for an admin's own session to read another user's Google Tasks directly, so each account reports its own counts after its own load).

### Archived features

[`archive/`](archive/) holds features that were built, tested, and deliberately backed out but are worth keeping for reference — currently the shared-tasks (jointly-owned lists) feature, with its own README explaining why it was archived.

## Local setup

1. Copy `config.local.php.example` → `config.local.php` and fill in:
   - `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` — Google Cloud Console → APIs & Services → Credentials.
   - `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` — IONOS control panel → Hosting → Databases.
   - `ENCRYPTION_KEY` — any long random string (`openssl rand -base64 48`), used to encrypt Apple Reminders app-specific passwords at rest.
2. `config.local.php` is loaded automatically by `config.php` if present, and is git-ignored — it never gets committed.
3. Serve the directory with any PHP server, e.g. `php -S localhost:8000`.

## Deploying to production

Deploys are plain SFTP to IONOS shared hosting — there's no CI/CD.

1. Copy `deploy_local.py.example` → `deploy_local.py` and fill in the real SFTP host/username/password (IONOS control panel → Hosting → FTP & SFTP Access) and `REMOTE_ROOT`. This file is git-ignored.
2. `pip install paramiko` (only external dependency).
3. Deploy specific files after a change:
   ```
   python3 deploy.py api/feedback.php mcp/config.php
   ```
   Or everything git-tracks:
   ```
   python3 deploy.py --all
   ```
   Add `--dry-run` to preview without uploading.

After deploying, smoke-test: homepage loads (200), an unauthenticated API endpoint returns clean JSON rather than a raw PHP error, and — for anything touching `mcp/`, the OAuth flow, or `config.php` — the MCP endpoint responds correctly to a live `tools/list` / `tools/call`, since PHP fatal errors (e.g. duplicate function declarations across includes) won't show up in `php -l` and need an actual request simulation to catch.

## Working with this repo in Claude Code

- The repo is **private** — treat it accordingly.
- Claude commits automatically after each logical change, but always asks before pushing to GitHub or deploying to the live site.
- Never commit `config.local.php`, `deploy_local.py`, or anything under `data/` — see `.gitignore`.
- See [`CHANGELOG.md`](CHANGELOG.md) for project history, and [`ISSUES.md`](ISSUES.md) for the running bug/enhancement tracker.

## Docs

- [`GOOGLE_VERIFICATION_GUIDE.md`](GOOGLE_VERIFICATION_GUIDE.md) — notes on Google OAuth app verification.
- [`help.html`](help.html) — in-app help content shown to end users.
