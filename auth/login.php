<?php
require_once '../config.php';

// Already logged in with full Tasks access → go home. If they're
// authenticated but missing Tasks access, fall through and let them
// go through Google's consent screen again to grant it.
if (isAuthenticated() && ($_SESSION['hasTasksAccess'] ?? true)) {
    header('Location: /');
    exit;
}

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
