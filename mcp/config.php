<?php
/**
 * MCP Server Configuration
 * tasks.tesh.ai — Google Tasks MCP Server
 *
 * SETUP: Each user visits https://tasks.tesh.ai/mcp/setup.php while
 * signed into tasks.tesh.ai to generate their personal API key.
 */

// Loads just the secret constants from config.local.php (git-ignored — see
// config.local.php.example) — not the whole main config.php, which defines
// its own refreshAccessToken() that would collide with this file's.
// require_once guards this even if the main config.php happens to have
// already loaded the same config.local.php earlier in this request.
$__localConfig = dirname(__DIR__) . '/config.local.php';
if (file_exists($__localConfig)) require_once $__localConfig;
unset($__localConfig);

// ─── Data directory ────────────────────────────────────────────────────────
// Shared with the main app. Per-user API key files and token files live here.
// Protected from web access by data/.htaccess (created on first write).
define('DATA_DIR', dirname(__DIR__) . '/data');

// ─── Google OAuth (same client as the main app) ────────────────────────────
define('MCP_GOOGLE_CLIENT_ID',     defined('GOOGLE_CLIENT_ID')     ? GOOGLE_CLIENT_ID     : (getenv('GOOGLE_CLIENT_ID') ?: ''));
define('MCP_GOOGLE_CLIENT_SECRET', defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : (getenv('GOOGLE_CLIENT_SECRET') ?: ''));
define('MCP_GOOGLE_TOKEN_URL',  'https://oauth2.googleapis.com/token');
define('MCP_GOOGLE_TASKS_BASE', 'https://tasks.googleapis.com/tasks/v1');

// ─── Server identity ──────────────────────────────────────────────────────
define('MCP_SERVER_NAME',      'tasks-tesh-ai');
define('MCP_SERVER_VERSION',   '1.1.0');
define('MCP_PROTOCOL_VERSION', '2024-11-05');
