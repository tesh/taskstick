<?php
/**
 * api/apple-export.php — feeds the native macOS helper app (mac-helper/),
 * which does the actual Apple Reminders writing via EventKit. This
 * replaces the old CalDAV push approach (archive/apple-caldav-attempt-2026-08-19/) —
 * Apple removed Reminders from CalDAV in iOS 13/Catalina, so the only
 * thing that still reliably writes to modern Reminders is EventKit,
 * which only runs on-device.
 *
 * The Mac helper runs headless (a menu-bar background process, not a
 * browser tab), so there's no PHP session to authenticate it — auth is a
 * long-lived per-user bearer token instead (issued by apple-settings.php,
 * looked up in data/apple_export_tokens.json). Google Tasks access for
 * this same reason can't ride the session's access_token either; a
 * refresh_token captured at connect-time (identical trick to mcp/'s
 * tokens.json, which has the same headless-client problem) is stored
 * encrypted per-user and used to mint access tokens independently here.
 *
 * GET  → { lists: [ { id, title, tasks: [...] } ] } for this user's
 *        enabled lists, source-of-truth data straight from Google Tasks.
 * POST { action: 'report_result', pushed, errors } → the helper reports
 *        what it just did, purely so the web Settings UI's "Last synced"
 *        line stays meaningful even though the real work now happens
 *        entirely on the Mac.
 * POST { action: 'complete_tasks', completions: [{listId, taskId}] } →
 *        the one thing that flows Reminders → Google Tasks: the helper
 *        noticed these were checked off locally, so mark them complete
 *        at the source of truth.
 */
require_once '../config.php';
require_once '../lib/Encryption.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
set_time_limit(60);

function loadJsonFile(string $file): array {
    if (!file_exists($file)) return [];
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : [];
}

function refreshGoogleAccessToken(string $refreshToken): ?string {
    $ch = curl_init(GOOGLE_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $response['access_token'] ?? null;
}

function googleTasksRequest(string $accessToken, string $endpoint, string $method = 'GET', ?array $body = null): array {
    $ch = curl_init(GOOGLE_TASKS_BASE . $endpoint);
    $headers = ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'];
    if ($body !== null) {
        $json = json_encode($body);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true) ?? [];
}

// ---- Bearer token auth (no PHP session available — this is a headless
// background client, not a browser) ------------------------------------
// $_SERVER often doesn't have it at all on shared hosting (Authorization
// is commonly reserved for Apache's own Basic Auth, stripped before PHP
// sees it) — the .htaccess RewriteRule re-exposes it as HTTP_AUTHORIZATION,
// but getallheaders() is a second, independent path to the same header
// in case that rule isn't in effect for some reason (e.g. a host that
// ignores .htaccess overrides for RewriteRule E= flags).
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!$authHeader && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) { $authHeader = $value; break; }
    }
}
if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    jsonResponse(['error' => 'Missing bearer token'], 401);
}
$token = trim($m[1]);

$dataDir      = dirname(__DIR__) . '/data';
$registryFile = $dataDir . '/apple_export_tokens.json';
$registry     = loadJsonFile($registryFile);
$entry        = $registry[$token] ?? null;
if (!$entry || empty($entry['userId'])) {
    jsonResponse(['error' => 'Invalid or revoked token — reconnect in TaskStick Settings'], 401);
}

$userId  = $entry['userId'];
$safeId  = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId);
$file    = $dataDir . '/apple_sync_' . $safeId . '.json';
$data    = loadJsonFile($file);

if (empty($data['refreshTokenEnc'])) {
    jsonResponse(['error' => 'Not connected — reconnect in TaskStick Settings'], 400);
}

try {
    $refreshToken = Encryption::decrypt($data['refreshTokenEnc']);
} catch (Throwable $e) {
    jsonResponse(['error' => 'Could not decrypt stored credentials — reconnect in TaskStick Settings'], 500);
}

$accessToken = refreshGoogleAccessToken($refreshToken);
if (!$accessToken) {
    jsonResponse(['error' => 'Google token refresh failed — reconnect in TaskStick Settings'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $enabledLists = $data['enabledLists'] ?? [];
    $out = [];

    foreach ($enabledLists as $listInfo) {
        $listId    = $listInfo['id']    ?? '';
        $listTitle = $listInfo['title'] ?? '';
        if (!$listId || !$listTitle) continue;

        $result = googleTasksRequest($accessToken, '/lists/' . urlencode($listId) . '/tasks?' . http_build_query([
            'maxResults'    => 100,
            'showCompleted' => 'true',
            'showHidden'    => 'true',
        ]));

        $tasks = [];
        foreach (($result['items'] ?? []) as $t) {
            if (empty($t['title'])) continue;
            $tasks[] = [
                'id'      => $t['id'],
                'title'   => $t['title'],
                'notes'   => $t['notes'] ?? '',
                'due'     => $t['due']   ?? null,
                'status'  => $t['status'] ?? 'needsAction',
                'updated' => $t['updated'] ?? '',
            ];
        }

        $out[] = ['id' => $listId, 'title' => $listTitle, 'tasks' => $tasks];
    }

    jsonResponse(['lists' => $out]);
}

if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'report_result') {
        updateJsonFile($file, function (array $d) use ($body) {
            $d['lastSyncedAt']   = gmdate('c');
            $d['lastSyncResult'] = [
                'pushed' => (int)($body['pushed'] ?? 0),
                'errors' => is_array($body['errors'] ?? null) ? $body['errors'] : [],
            ];
            return $d;
        });
        jsonResponse(['success' => true]);
    }

    if ($action === 'complete_tasks') {
        $completions = $body['completions'] ?? [];
        $completed   = 0;
        $errors      = [];

        foreach ($completions as $c) {
            $listId = $c['listId'] ?? '';
            $taskId = $c['taskId'] ?? '';
            if (!$listId || !$taskId) continue;

            $result = googleTasksRequest(
                $accessToken,
                '/lists/' . urlencode($listId) . '/tasks/' . urlencode($taskId),
                'PATCH',
                ['status' => 'completed']
            );
            if (!empty($result['error'])) {
                $errors[] = "Could not mark task complete: " . (is_array($result['error']) ? ($result['error']['message'] ?? 'unknown error') : $result['error']);
            } else {
                $completed++;
            }
        }

        jsonResponse(['success' => true, 'completed' => $completed, 'errors' => $errors]);
    }

    jsonResponse(['error' => 'Unknown action'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
