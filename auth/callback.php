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

header('Location: /');
exit;
