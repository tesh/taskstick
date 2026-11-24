<?php
require_once '../config.php';

/**
 * Optional ?return_to=/local/path — where to land after sign-in (used by the
 * MCP OAuth consent screen and the MCP setup page). Only same-site paths are
 * accepted, so this can't be abused as an open redirect.
 * Optional ?force=1 — go through Google even if already signed in (used when
 * we need a fresh refresh token or the Tasks scope).
 */
function safeReturnTo(?string $path): ?string {
    if (!$path || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')
        || preg_match('/[\x00-\x1f]/', $path)) return null;
    return $path;
}

$returnTo = safeReturnTo($_GET['return_to'] ?? null);
$force    = !empty($_GET['force']);

// Already logged in with full Tasks access → go where they were headed. If
// they're authenticated but missing Tasks access, fall through and let them
// go through Google's consent screen again to grant it.
if (!$force && isAuthenticated() && ($_SESSION['hasTasksAccess'] ?? true)) {
    header('Location: ' . ($returnTo ?? '/'));
    exit;
}

if ($returnTo) $_SESSION['login_return_to'] = $returnTo;
else unset($_SESSION['login_return_to']);

// Generate CSRF state token
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$authUrl = GOOGLE_AUTH_URL . '?' . http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => GOOGLE_SCOPES,
    'access_type'   => 'offline',
    'prompt'        => 'consent',
    'state'         => $state,
]);

header('Location: ' . $authUrl);
exit;
