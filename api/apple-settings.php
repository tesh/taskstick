<?php
/**
 * api/apple-settings.php — Apple Reminders sync credentials (beta)
 *
 * GET    → current connection status (configured?, apple email, enabled
 *          lists, last synced)
 * POST   { appleEmail, appSpecificPassword } → tests the CalDAV connection,
 *          and only saves (encrypted) if the test succeeds. Returns the
 *          discovered Reminders list names.
 * POST   { action: 'save_lists', enabledLists: [{id, title}, ...] } →
 *          updates which Google lists are enabled for sync, without
 *          touching stored credentials (no re-test needed).
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
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if (($body['action'] ?? '') === 'save_lists') {
        $enabledLists = $body['enabledLists'] ?? [];
        if (!is_array($enabledLists)) {
            jsonResponse(['error' => 'enabledLists must be an array'], 400);
        }
        // Keep only well-formed {id, title} entries — both fields are
        // needed downstream (id to fetch tasks, title to match/find the
        // Reminders list by name).
        $clean = [];
        foreach ($enabledLists as $l) {
            $id    = trim($l['id']    ?? '');
            $title = trim($l['title'] ?? '');
            if ($id && $title) $clean[] = ['id' => $id, 'title' => $title];
        }

        $notConfigured = false;
        $data = updateJsonFile($file, function (array $data) use ($clean, &$notConfigured) {
            if (empty($data['appleEmail']) || empty($data['passwordEnc'])) {
                $notConfigured = true;
                return $data;
            }
            $data['enabledLists'] = $clean;
            return $data;
        });
        if ($notConfigured) { jsonResponse(['error' => 'Connect an Apple ID first'], 400); }
        jsonResponse(['success' => true, 'enabledLists' => $data['enabledLists']]);
    }

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
    $encrypted     = Encryption::encrypt($password);

    updateJsonFile($file, function (array $data) use ($email, $encrypted) {
        $data['appleEmail']   = $email;
        $data['passwordEnc']  = $encrypted;
        $data['enabledLists'] = $data['enabledLists'] ?? [];
        return $data;
    });

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
