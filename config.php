<?php
// This file is committed to git — real secrets are never hardcoded here.
// They're loaded from config.local.php (git-ignored — lives only on the
// server and any machine you develop from) if present, else from
// environment variables. See config.local.php.example for the template.
$__localConfig = __DIR__ . '/config.local.php';
if (file_exists($__localConfig)) require $__localConfig;
unset($__localConfig);

// Google OAuth Configuration
if (!defined('GOOGLE_CLIENT_ID'))     define('GOOGLE_CLIENT_ID',     getenv('GOOGLE_CLIENT_ID')     ?: '');
if (!defined('GOOGLE_CLIENT_SECRET')) define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
define('GOOGLE_REDIRECT_URI',  'https://tasks.tesh.ai/auth/callback.php');

// Google API endpoints
define('GOOGLE_AUTH_URL',      'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL',     'https://oauth2.googleapis.com/token');
define('GOOGLE_TASKS_BASE',    'https://tasks.googleapis.com/tasks/v1');
define('GOOGLE_USERINFO_URL',  'https://www.googleapis.com/oauth2/v3/userinfo');

// OAuth scopes
define('GOOGLE_SCOPES', implode(' ', [
    'openid',
    'email',
    'profile',
    'https://www.googleapis.com/auth/tasks',
]));

// MySQL (feedback + shared-tasks-archive storage) — set real values in
// config.local.php, from the IONOS control panel: Hosting → Databases.
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: '');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: '');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');

// Used to encrypt secrets we store at rest (currently: each Apple
// Reminders sync user's Google refresh_token — see api/apple-export.php).
// Set a real random value in config.local.php — never reuse
// GOOGLE_CLIENT_SECRET or any other secret for this.
if (!defined('ENCRYPTION_KEY')) define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: '');

// Session config
//
// Both the cookie's own lifetime and gc_maxlifetime need to be a week, not
// just gc_maxlifetime — without an explicit cookie_lifetime, PHP sends a
// browser-session cookie (no Expires/Max-Age at all), which is exactly
// what was signing iOS PWA users out: iOS routinely kills a backgrounded
// PWA's process, and a plain session cookie doesn't survive that, unlike
// a real desktop browser where "closing the browser" is rare.
define('SESSION_LIFETIME_SECONDS', 7 * 24 * 60 * 60); // 1 week
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_lifetime', SESSION_LIFETIME_SECONDS);
ini_set('session.gc_maxlifetime', SESSION_LIFETIME_SECONDS);

// The cookie/gc_maxlifetime fix above wasn't enough on its own — shared
// hosts (IONOS included) typically run a system-level cron that sweeps the
// *default* session save path using php.ini's global gc_maxlifetime
// (often 20-30 minutes), completely ignoring this script's own ini_set()
// above, which only governs this script's own per-request probabilistic
// cleanup. Redirecting storage into our own data/ directory (already
// git-ignored and .htaccess-protected) means only OUR gc_maxlifetime ever
// decides when a session file dies.
$sessionSavePath = __DIR__ . '/data/sessions';
if (!is_dir($sessionSavePath)) mkdir($sessionSavePath, 0700, true);
ini_set('session.save_path', $sessionSavePath);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);

session_start();

// PHP only stamps a fresh cookie expiry when the session is first created,
// not on every request — so without this, "a week" would mean a week since
// login, not a week since you last actually used the app. Re-stamping the
// cookie on every authenticated request turns it into a rolling window:
// open the app at least once a week and you never see the login screen.
if (!empty($_SESSION['access_token'])) {
    $sessionCookieParams = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires'  => time() + SESSION_LIFETIME_SECONDS,
        'path'     => $sessionCookieParams['path'],
        'domain'   => $sessionCookieParams['domain'],
        'secure'   => $sessionCookieParams['secure'],
        'httponly' => $sessionCookieParams['httponly'],
        'samesite' => $sessionCookieParams['samesite'],
    ]);
}

/**
 * Make an authenticated request to the Google Tasks API.
 * Automatically refreshes the access token if expired.
 */
function googleApiRequest(string $endpoint, string $method = 'GET', array $body = null): array {
    if (empty($_SESSION['access_token'])) {
        http_response_code(401);
        return ['error' => 'Not authenticated'];
    }

    // Check token expiry and refresh if needed
    if (!empty($_SESSION['token_expiry']) && time() >= $_SESSION['token_expiry'] - 60) {
        if (!refreshAccessToken()) {
            http_response_code(401);
            return ['error' => 'Token refresh failed'];
        }
    }

    $url = GOOGLE_TASKS_BASE . $endpoint;
    $ch  = curl_init($url);

    $headers = [
        'Authorization: Bearer ' . $_SESSION['access_token'],
        'Accept: application/json',
    ];

    if ($body !== null) {
        $json = json_encode($body);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($json);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['error' => 'Network error'];
    }

    $decoded = json_decode($response, true) ?? [];

    // If 401, try one token refresh
    if ($httpCode === 401 && refreshAccessToken()) {
        return googleApiRequest($endpoint, $method, $body);
    }

    return $decoded;
}

/**
 * Refresh the access token using the stored refresh token.
 */
function refreshAccessToken(): bool {
    if (empty($_SESSION['refresh_token'])) return false;

    $ch = curl_init(GOOGLE_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'refresh_token' => $_SESSION['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!empty($response['access_token'])) {
        $_SESSION['access_token']  = $response['access_token'];
        $_SESSION['token_expiry']  = time() + ($response['expires_in'] ?? 3600);
        return true;
    }
    return false;
}

/**
 * Return a JSON response and exit.
 */
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

/**
 * Check if the user is authenticated.
 */
function isAuthenticated(): bool {
    return !empty($_SESSION['access_token']);
}

// Emails seeded as admin the first time they ever log in.
define('ADMIN_SEED_EMAILS', ['hitesh.patel44@gmail.com']);

const USERS_FILE = __DIR__ . '/data/users.json';

/**
 * Load the full user registry (data/users.json).
 */
function loadUsers(): array {
    if (!file_exists(USERS_FILE)) return [];
    $d = json_decode(file_get_contents(USERS_FILE), true);
    return is_array($d) ? $d : [];
}

function saveUsers(array $users): void {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Read-modify-write data/users.json under an exclusive file lock, so two
 * requests hitting it at once (e.g. two people logging in within moments
 * of each other) can't race — without this, the second writer could read
 * the file before the first writer's save landed, then overwrite it with
 * a version missing the first writer's new user entirely.
 */
function updateUsers(callable $mutator): array {
    $dataDir = dirname(USERS_FILE);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
        file_put_contents($dataDir . '/.htaccess', "Order deny,allow\nDeny from all\n");
    }

    $fp = fopen(USERS_FILE, 'c+');
    if (!$fp) return loadUsers();
    flock($fp, LOCK_EX);

    try {
        $contents = stream_get_contents($fp);
        $users = json_decode($contents, true);
        if (!is_array($users)) $users = [];

        $users = $mutator($users) ?? $users;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    return $users;
}

/**
 * Same locked read-modify-write pattern as updateUsers(), generalized for
 * any per-user JSON state file (currently: Apple Reminders sync config).
 * Kept deliberately generic rather than duplicating the lock/mkdir/write
 * boilerplate per feature.
 */
function updateJsonFile(string $file, callable $mutator): array {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
    }

    $fp = fopen($file, 'c+');
    if (!$fp) return [];
    flock($fp, LOCK_EX);

    try {
        $contents = stream_get_contents($fp);
        $data = json_decode($contents, true);
        if (!is_array($data)) $data = [];

        $data = $mutator($data) ?? $data;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    return $data;
}

/**
 * Upsert the current user's record on every login: creates it (seeding
 * is_admin for ADMIN_SEED_EMAILS) or refreshes name/picture/last_seen.
 */
function registerUser(string $email, string $name, string $picture): void {
    if (!$email) return;
    $now = gmdate('c');
    updateUsers(function (array $users) use ($email, $name, $picture, $now) {
        if (!isset($users[$email])) {
            $users[$email] = [
                'email'      => $email,
                'name'       => $name,
                'picture'    => $picture,
                'first_seen' => $now,
                'last_seen'  => $now,
                'is_admin'   => in_array($email, ADMIN_SEED_EMAILS, true),
            ];
        } else {
            $users[$email]['name']      = $name;
            $users[$email]['picture']   = $picture;
            $users[$email]['last_seen'] = $now;
        }
        return $users;
    });
}

/**
 * Is the given email an admin? Defaults to false for unknown users.
 */
function isAdminEmail(string $email): bool {
    if (!$email) return false;
    $users = loadUsers();
    return !empty($users[$email]['is_admin']);
}

/**
 * Is the currently-logged-in session user an admin?
 */
function isAdmin(): bool {
    return isAdminEmail($_SESSION['user']['email'] ?? '');
}
