<?php
/**
 * api/apple-settings.php — Apple Reminders sync credentials (beta)
 *
 * GET    → current connection status (configured?, apple email, enabled
 *          lists, last synced)
 * POST   { appleEmail, appSpecificPassword } → tests the CalDAV connection,
 *          and only saves (encrypted) if the test succeeds. Returns the
 *          discovered Reminders list names.
 * DELETE → disconnect (clears stored credentials for this user)
 *
 * Credentials live in their own per-user file (not the general prefs.php
 * blob) so the encrypted password ciphertext doesn't flow through the
 * same broadly-fetched endpoint as stars/theme/settings.
 */
require_once '../config.php';
require_once '../lib/Encryption.php';
require_once '../lib/CalDAV.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isAuthenticated()) { jsonResponse(['error' => 'Not authenticated'], 401); }

$userId = $_SESSION['user']['id'] ?? '';
if (!$userId) { jsonResponse(['error' => 'User ID not found in session'], 400); }

$safeId  = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId);
$dataDir = dirname(__DIR__) . '/data';
$file    = $dataDir . '/apple_sync_' . $safeId . '.json';

function loadAppleSync(string $file): array {
    if (!file_exists($file)) return [];
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : [];
}

function saveAppleSync(string $file, string $dataDir, array $data): void {
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
        file_put_contents($dataDir . '/.htaccess', "Order deny,allow\nDeny from all\n");
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $data = loadAppleSync($file);
    jsonResponse([
        'configured'   => !empty($data['appleEmail']) && !empty($data['passwordEnc']),
        'appleEmail'   => $data['appleEmail'] ?? '',
        'enabledLists' => $data['enabledLists'] ?? [],
        'lastSyncedAt' => $data['lastSyncedAt'] ?? null,
    ]);
}

if ($method === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $email    = trim($body['appleEmail'] ?? '');
    $password = trim($body['appSpecificPassword'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'A valid Apple ID email is required'], 400);
    }
    if (!$password) {
        jsonResponse(['error' => 'App-specific password is required'], 400);
    }

    // Test the connection before saving anything — never store credentials
    // that don't actually work.
    $dav = new CalDAV($email, $password);
    if (!$dav->discover()) {
        $detail = $dav->getLastError();
        jsonResponse([
            'error' => 'Could not connect to iCloud. Check your Apple ID email and app-specific password.'
                . ($detail ? " Detail: $detail" : ''),
        ], 400);
    }

    $reminderLists = $dav->listReminderLists();

    $data = loadAppleSync($file);
    $data['appleEmail']   = $email;
    $data['passwordEnc']  = Encryption::encrypt($password);
    $data['enabledLists'] = $data['enabledLists'] ?? [];
    saveAppleSync($file, $dataDir, $data);

    jsonResponse([
        'success'       => true,
        'reminderLists' => array_map(fn($l) => $l['displayName'], $reminderLists),
    ]);
}

if ($method === 'DELETE') {
    if (file_exists($file)) unlink($file);
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
