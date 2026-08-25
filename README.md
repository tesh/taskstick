# TaskStick

A custom Google Tasks web app — the live instance runs at
[tasks.tesh.ai](https://tasks.tesh.ai), but this repo is a fully
self-hostable template: point it at your own domain and Google Cloud
project and it's yours. PHP + vanilla JS, no framework, no build step.
Includes an MCP server so AI assistants (Claude, ChatGPT, etc.) can read
and manage tasks directly.

> **License:** free to download and run for yourself; not free to
> redistribute modified, or to use commercially. See
> [`LICENSE.md`](LICENSE.md).

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

## Prerequisites

- A PHP 8+ host (shared hosting is fine — this is what the live instance
  runs on; no framework or Composer dependencies are required for the
  app itself).
- A MySQL 5.7+/8+ database (only used for feedback storage and the
  archived shared-tasks feature — the app works without it, see Storage
  below).
- A domain (or subdomain) you control, with HTTPS. Google's OAuth
  consent screen requires a real, verifiable domain — `localhost` works
  for local development only.
- A Google Cloud project with OAuth 2.0 credentials for the Tasks API.

## Setting up your own Google Cloud OAuth client

1. Go to the [Google Cloud Console](https://console.cloud.google.com/),
   create a new project, and enable the **Google Tasks API**.
2. Configure the OAuth consent screen (External user type) with your own
   app name, support email, and — once you've picked a domain — your
   homepage/privacy/terms URLs.
3. Create an **OAuth 2.0 Client ID** (Web application) with:
   - Authorized JavaScript origin: `https://yourdomain.example`
   - Authorized redirect URI: `https://yourdomain.example/auth/callback.php`
4. Copy the generated Client ID and Client Secret — you'll need them below.

While your app is in Google's "Testing" publishing status, refresh
tokens expire after 7 days and only the test users you list can sign
in. Submitting for verification (to lift both restrictions) requires a
demo video and scope justification — see
[`GOOGLE_VERIFICATION_GUIDE.md`](GOOGLE_VERIFICATION_GUIDE.md) for a full
walkthrough, written from the process of verifying the live
tasks.tesh.ai instance.

## Local setup

1. Copy `config.local.php.example` → `config.local.php` and fill in:
   - `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` — from the OAuth client you created above.
   - `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` — your MySQL host's credentials.
   - `ENCRYPTION_KEY` — any long random string (`openssl rand -base64 48`), used to encrypt Apple Reminders app-specific passwords at rest.
   - `ADMIN_SEED_EMAILS` — the Google account email(s) you want auto-promoted to admin on first login, e.g. `['you@example.com']`.
2. Add `GOOGLE_REDIRECT_URI` to your `config.local.php` (or as an env var), matching your own domain's `/auth/callback.php` — it must exactly match the redirect URI on your OAuth client. It defaults to the live site's URL, so this step is required for your own instance to sign in correctly.
3. `config.local.php` is loaded automatically by `config.php` if present, and is git-ignored — it never gets committed.
4. Serve the directory with any PHP server, e.g. `php -S localhost:8000`. (Note: Google OAuth requires HTTPS for anything other than `localhost`, so a plain HTTP tunnel to a remote dev box won't work for testing the sign-in flow — use `localhost` or a real HTTPS domain.)

## Deploying to production

The included [`deploy.py`](deploy.py) is a plain SFTP uploader — it
works against any host that offers SFTP (shared hosting, a VPS, etc.),
not just IONOS. There's no CI/CD; it's a straightforward "push these
files" script.

1. Copy `deploy_local.py.example` → `deploy_local.py` and fill in your real SFTP host/username/password and `REMOTE_ROOT` (the path on the server your webroot maps to). This file is git-ignored.
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
4. Set `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `DB_*` / `ENCRYPTION_KEY` / `ADMIN_SEED_EMAILS` on the server itself — either via a `config.local.php` you place there manually (never deployed via `deploy.py`, since it's git-ignored) or as real environment variables, since `config.php` falls back to `getenv()` for every secret.

After deploying, smoke-test: homepage loads (200), an unauthenticated API endpoint returns clean JSON rather than a raw PHP error, and — for anything touching `mcp/`, the OAuth flow, or `config.php` — the MCP endpoint responds correctly to a live `tools/list` / `tools/call`, since PHP fatal errors (e.g. duplicate function declarations across includes) won't show up in `php -l` and need an actual request simulation to catch.

## Customizing for your own instance

Before deploying publicly under your own name, you'll likely want to:

- Replace the placeholder contact details in [`privacy.html`](privacy.html) and [`terms.html`](terms.html) (`[Your Company or Name]`, `[Your City, State/Country]`, `you@example.com`, `yourdomain.example`) with your own.
- Update the branding in [`index.html`](index.html) and [`manifest.json`](manifest.json) (currently "Purple Pill Solutions") if you don't want to keep the original attribution.
- Swap the icons under [`icons/`](icons/) for your own artwork.

## License, contributions, and this being a personal project

This is a personal project shared for others to learn from and
self-host, not an actively developed open-source project looking for
contributions — issues and PRs may not get a response. See
[`LICENSE.md`](LICENSE.md) for what you may and may not do with the
code.

## Docs

- [`CHANGELOG.md`](CHANGELOG.md) — project history.
- [`ISSUES.md`](ISSUES.md) — the running bug/enhancement tracker kept during development.
- [`GOOGLE_VERIFICATION_GUIDE.md`](GOOGLE_VERIFICATION_GUIDE.md) — notes on Google OAuth app verification.
- [`help.html`](help.html) — in-app help content shown to end users.
