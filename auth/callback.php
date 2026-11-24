<?php
require_once '../config.php';

// Validate state
if (empty($_GET['state']) || empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $_GET['state'])) {
    header('Location: /?error=invalid_state');
    exit;
}
unset($_SESSION['oauth_state']);

// Check for errors from Google
if (!empty($_GET['error'])) {
    header('Location: /?error=' . urlencode($_GET['error']));
    exit;
}

if (empty($_GET['code'])) {
    header('Location: /?error=missing_code');
    exit;
}

// Exchange auth code for tokens
$ch = curl_init(GOOGLE_TOKEN_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $_GET['code'],
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 15,
]);

$tokenData = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($tokenData['access_token'])) {
    header('Location: /?error=token_exchange_failed');
    exit;
}

// Store tokens in session
$_SESSION['access_token']  = $tokenData['access_token'];
$_SESSION['refresh_token'] = $tokenData['refresh_token'] ?? null;
$_SESSION['token_expiry']  = time() + ($tokenData['expires_in'] ?? 3600);

// Google reports back which scopes were actually granted — the user can
// uncheck individual scopes on the consent screen, so this can be a subset
// of what we requested. Without the Tasks scope the app can't read/write
// or back up any tasks, so we flag that for the frontend to warn about.
$grantedScopes = $tokenData['scope'] ?? '';
$_SESSION['hasTasksAccess'] = str_contains($grantedScopes, 'https://www.googleapis.com/auth/tasks');

// Fetch user info
$ch = curl_init(GOOGLE_USERINFO_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $_SESSION['access_token']],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$userInfo = json_decode(curl_exec($ch), true);
curl_close($ch);

$_SESSION['user'] = [
    'id'      => $userInfo['sub']     ?? '',
    'name'    => $userInfo['name']    ?? 'User',
    'email'   => $userInfo['email']   ?? '',
    'picture' => $userInfo['picture'] ?? '',
];

registerUser($_SESSION['user']['email'], $_SESSION['user']['name'], $_SESSION['user']['picture']);

// If an MCP connection (or another flow) sent the user to sign in, keep the
// MCP server's stored Google token fresh too, then return them there.
// login.php only ever stores a validated same-site path here.
if (!empty($_SESSION['refresh_token']) && !empty($_SESSION['user']['id'])
    && file_exists(dirname(__DIR__) . '/data/tokens_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $_SESSION['user']['id']) . '.json')) {
    require_once dirname(__DIR__) . '/mcp-server/lib/oauth.php';
    mcpSaveGoogleTokens($_SESSION['user']['id'], $_SESSION['user']['email'], $_SESSION['refresh_token'],
        $_SESSION['access_token'], (int)$_SESSION['token_expiry']);
}

$returnTo = $_SESSION['login_return_to'] ?? '/';
unset($_SESSION['login_return_to']);
header('Location: ' . $returnTo);
exit;
