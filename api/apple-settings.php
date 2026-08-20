<?php
/**
 * api/apple-settings.php — Apple Reminders sync setup (session-authenticated,
 * called from the browser). Pairs with api/apple-export.php, which the
 * native macOS helper app (mac-helper/) polls to do the actual EventKit
 * writing — see that file's header comment for why (Apple removed
 * Reminders from CalDAV in 2019; archive/apple-caldav-attempt-2026-08-19/
 * has the full story).
 *
 * GET    → { configured, exportToken, enabledLists, lastSyncedAt }
 * POST   { action: 'connect' } → captures this session's Google
 *          refresh_token (so the headless helper can mint its own access
 *          tokens later, same trick mcp/setup.php already uses for the
 *          MCP server) and issues an export token if one doesn't exist
 *          yet. Returns the token to paste into the helper app.
 * POST   { action: 'save_lists', enabledLists } → unchanged from the
 *          CalDAV version — still just which Google lists are enabled.
 * POST   { action: 'regenerate_token' } → issues a fresh export token,
 *          invalidating the old one (e.g. if it leaked).
 * DELETE → disconnect (clears stored credentials + revokes the token)
 */
require_once '../config.php';
require_once '../lib/Encryption.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isAuthenticated()) { jsonResponse(['error' => 'Not authenticated'], 401); }

$userId    = $_SESSION['user']['id']    ?? '';
$userEmail = $_SESSION['user']['email'] ?? '';
if (!$userId) { jsonResponse(['error' => 'User ID not found in session'], 400); }

$safeId  = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId);
$dataDir = dirname(__DIR__) . '/data';
$file    = $dataDir . '/apple_sync_' . $safeId . '.json';
$registryFile = $dataDir . '/apple_export_tokens.json';

function loadAppleSync(string $file): array {
    if (!file_exists($file)) return [];
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : [];
}

function newExportToken(): string {
    return bin2hex(random_bytes(24));
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $data = loadAppleSync($file);
    jsonResponse([
        'configured'     => !empty($data['exportToken']) && !empty($data['refreshTokenEnc']),
        'exportToken'    => $data['exportToken']  ?? null,
        'enabledLists'   => $data['enabledLists'] ?? [],
        'lastSyncedAt'   => $data['lastSyncedAt']   ?? null,
        'lastSyncResult' => $data['lastSyncResult'] ?? null,
    ]);
}

if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'save_lists') {
        $enabledLists = $body['enabledLists'] ?? [];
        if (!is_array($enabledLists)) {
            jsonResponse(['error' => 'enabledLists must be an array'], 400);
        }
        $clean = [];
        foreach ($enabledLists as $l) {
            $id    = trim($l['id']    ?? '');
            $title = trim($l['title'] ?? '');
            if ($id && $title) $clean[] = ['id' => $id, 'title' => $title];
        }

        $notConfigured = false;
        $data = updateJsonFile($file, function (array $data) use ($clean, &$notConfigured) {
            if (empty($data['exportToken']) || empty($data['refreshTokenEnc'])) {
                $notConfigured = true;
                return $data;
            }
            $data['enabledLists'] = $clean;
            return $data;
        });
        if ($notConfigured) { jsonResponse(['error' => 'Connect Apple Reminders sync first'], 400); }
        jsonResponse(['success' => true, 'enabledLists' => $data['enabledLists']]);
    }

    if ($action === 'connect' || $action === 'regenerate_token') {
        if (empty($_SESSION['refresh_token'])) {
            jsonResponse([
                'error' => 'Missing Google refresh token for this session — sign out and back in, then try connecting again.',
            ], 400);
        }

        // Read the current token (if any) before it's overwritten, purely
        // to drop it from the registry below — an old, possibly-leaked
        // token shouldn't keep working once a new one is issued.
        $oldToken = loadAppleSync($file)['exportToken'] ?? null;
        $token    = newExportToken();

        updateJsonFile($registryFile, function (array $registry) use ($token, $oldToken, $userId, $userEmail) {
            if ($oldToken) unset($registry[$oldToken]);
            $registry[$token] = ['userId' => $userId, 'userEmail' => $userEmail];
            return $registry;
        });

        $refreshTokenEnc = Encryption::encrypt($_SESSION['refresh_token']);
        $data = updateJsonFile($file, function (array $data) use ($token, $refreshTokenEnc) {
            $data['exportToken']     = $token;
            $data['refreshTokenEnc'] = $refreshTokenEnc;
            $data['enabledLists']    = $data['enabledLists'] ?? [];
            return $data;
        });

        jsonResponse(['success' => true, 'exportToken' => $token]);
    }

    jsonResponse(['error' => 'Unknown action'], 400);
}

if ($method === 'DELETE') {
    $data = loadAppleSync($file);
    if (!empty($data['exportToken'])) {
        updateJsonFile($registryFile, function (array $registry) use ($data) {
            unset($registry[$data['exportToken']]);
            return $registry;
        });
    }
    if (file_exists($file)) unlink($file);
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
