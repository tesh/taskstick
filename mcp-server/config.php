<?php
/**
 * MCP Server + OAuth Authorization Server configuration
 * tasks.tesh.ai — Google Tasks MCP Server
 *
 * Users connect by adding the MCP URL (MCP_RESOURCE_URL) to their AI client.
 * The client discovers the OAuth server automatically and the user signs in
 * with their TaskStick (Google) account — no keys to copy.
 *
 * Load order matters if a page also needs the main app: require the main
 * config.php FIRST, then this file (this file uses require_once for
 * config.local.php, which is a no-op if config.php already loaded it).
 */

$__localConfig = dirname(__DIR__) . '/config.local.php';
if (file_exists($__localConfig)) require_once $__localConfig;
unset($__localConfig);

// ─── Data directory ────────────────────────────────────────────────────────
// Shared with the main app; protected from web access by data/.htaccess.
if (!defined('DATA_DIR')) define('DATA_DIR', dirname(__DIR__) . '/data');
define('MCP_OAUTH_DIR', DATA_DIR . '/oauth');

// ─── Public URLs ───────────────────────────────────────────────────────────
// Override MCP_PUBLIC_BASE in config.local.php for a self-hosted instance.
if (!defined('MCP_PUBLIC_BASE')) define('MCP_PUBLIC_BASE', getenv('MCP_PUBLIC_BASE') ?: 'https://tasks.tesh.ai');

// The MCP endpoint's public URL. /mcp and /mcp/ both reach this server via
// rewrites in the root .htaccess (the code lives in mcp-server/), and
// discovery describes whichever spelling a client used; token audience
// checks accept both.
define('MCP_RESOURCE_URL', MCP_PUBLIC_BASE . '/mcp/');
define('MCP_ISSUER',       MCP_PUBLIC_BASE);   // OAuth issuer (RFC 8414 / RFC 9207)
// Served directly (not via /.well-known) because IONOS intercepts parts of
// /.well-known on its own. The WWW-Authenticate header points clients here.
define('MCP_PRM_URL',      MCP_PUBLIC_BASE . '/oauth/metadata.php?doc=prm&path=/mcp/');
define('MCP_AUTHORIZE_URL', MCP_PUBLIC_BASE . '/oauth/authorize.php');
define('MCP_TOKEN_URL',     MCP_PUBLIC_BASE . '/oauth/token.php');
define('MCP_REGISTER_URL',  MCP_PUBLIC_BASE . '/oauth/register.php');
define('MCP_REVOKE_URL',    MCP_PUBLIC_BASE . '/oauth/revoke.php');
define('MCP_SETUP_URL',     MCP_PUBLIC_BASE . '/mcp/setup.php');

// ─── OAuth scopes and token lifetimes ─────────────────────────────────────
define('MCP_SCOPES', ['tasks.read', 'tasks.write']);
define('MCP_ACCESS_TOKEN_TTL',  3600);            // 1 hour
define('MCP_REFRESH_TOKEN_TTL', 180 * 86400);     // 180 days, sliding (rotated on use)
define('MCP_AUTH_CODE_TTL',     300);             // 5 minutes
define('MCP_REFRESH_REUSE_GRACE', 60);            // seconds a just-rotated refresh token may be replayed (parallel refreshes)
define('MCP_CIMD_CACHE_TTL',    3600);

// ─── Google OAuth (same client as the main app) ────────────────────────────
define('MCP_GOOGLE_CLIENT_ID',     defined('GOOGLE_CLIENT_ID')     ? GOOGLE_CLIENT_ID     : (getenv('GOOGLE_CLIENT_ID') ?: ''));
define('MCP_GOOGLE_CLIENT_SECRET', defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : (getenv('GOOGLE_CLIENT_SECRET') ?: ''));
define('MCP_GOOGLE_TOKEN_URL',  getenv('MCP_GOOGLE_TOKEN_URL') ?: 'https://oauth2.googleapis.com/token');
define('MCP_GOOGLE_TASKS_BASE', getenv('MCP_GOOGLE_TASKS_BASE') ?: 'https://tasks.googleapis.com/tasks/v1');

// ─── Server identity ──────────────────────────────────────────────────────
define('MCP_SERVER_NAME',    'taskstick');
define('MCP_SERVER_TITLE',   'TaskStick');
define('MCP_SERVER_VERSION', '2.0.0');

// Modern = stateless, per-request _meta (2026-07-28+).
// Legacy = initialize handshake (2025-11-25 and earlier). Newest first.
define('MCP_MODERN_VERSIONS', ['2026-07-28']);
define('MCP_LEGACY_VERSIONS', ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05']);

// MCP Apps extension (interactive UI)
define('MCP_UI_EXTENSION', 'io.modelcontextprotocol/ui');
define('MCP_UI_MIME',      'text/html;profile=mcp-app');
define('MCP_UI_BOARD_URI', 'ui://taskstick/board.html');
