# TaskStick

A custom Google Tasks web app, running at [tasks.tesh.ai](https://tasks.tesh.ai). PHP + vanilla JS, no framework, no build step. Includes an MCP server so AI assistants (Claude, ChatGPT, etc.) can read and manage tasks directly.

## Architecture

- **Frontend**: single-page app in [`index.html`](index.html) — vanilla JS, no bundler. Theming via CSS custom properties (Notebook, Modern, Compact, Ocean, Rose).
- **Backend**: plain PHP files under [`api/`](api/) and [`auth/`](auth/), no framework or router.
- **Auth**: Google OAuth 2.0 (authorization code flow with refresh tokens) against the Google Tasks API. See [`auth/`](auth/) and `googleApiRequest()` / `refreshAccessToken()` in [`config.php`](config.php).
- **Storage**:
  - Google Tasks API is the source of truth for tasks/lists themselves.
  - MySQL (via PDO, see [`db.php`](db.php)) for feedback submissions and the archived shared-tasks feature.
  - JSON files under `data/` (git-ignored, server-only) for per-user prefs, the admin registry, and MCP tokens/API keys.
- **PWA**: [`manifest.json`](manifest.json) + [`sw.js`](sw.js) service worker, network-first caching, [`api/version.php`](api/version.php) drives client-side update detection.
- **MCP server**: [`mcp/`](mcp/) is a Streamable HTTP JSON-RPC 2.0 server (raw PHP, no SDK) exposing task read/write tools to AI assistants. Auth is a per-user bearer API key (separate from the main app's session auth), generated at `/mcp/setup.php`.
- **Admin**: seeded from `ADMIN_SEED_EMAILS` in `config.php`. Admins can view feedback submissions and the user registry at `/api/admin.php`-backed views.

### Archived features

[`archive/`](archive/) holds features that were built, tested, and deliberately backed out but are worth keeping for reference — currently the shared-tasks (jointly-owned lists) feature, with its own README explaining why it was archived.

## Local setup

1. Copy `config.local.php.example` → `config.local.php` and fill in:
   - `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` — Google Cloud Console → APIs & Services → Credentials.
   - `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` — IONOS control panel → Hosting → Databases.
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
