<?php
/**
 * User Preferences API
 * Stores per-user preferences (stars, list order, collapsed lists, and
 * display settings/theme) server-side so they persist across browsers,
 * sessions, and devices instead of living only in localStorage.
 *
 * GET  /api/prefs.php          → returns { stars, listOrder, collapsed, taskCollapsed, settings }
 * POST /api/prefs.php          → saves body { stars, listOrder, collapsed, taskCollapsed, settings }
 */

require_once '../config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isAuthenticated()) {
    jsonResponse(['error' => 'Not authenticated'], 401);
}

// Build a safe per-user filename from the Google user ID
$userId   = $_SESSION['user']['id'] ?? '';
if (!$userId) {
    jsonResponse(['error' => 'User ID not found in session'], 400);
}
$safeId   = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId);
$dataDir  = dirname(__DIR__) . '/data';
$prefsFile = $dataDir . '/prefs_' . $safeId . '.json';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (!file_exists($prefsFile)) {
            // No prefs yet — return empty defaults
            jsonResponse(['stars' => new stdClass(), 'listOrder' => [], 'settings' => new stdClass()]);
        }
        $data = json_decode(file_get_contents($prefsFile), true);
        jsonResponse($data ?? ['stars' => new stdClass(), 'listOrder' => [], 'settings' => new stdClass()]);

    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Ensure data directory exists
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
            // Write .htaccess to block direct web access
            file_put_contents($dataDir . '/.htaccess', "Order deny,allow\nDeny from all\n");
        }

        // Validate shape before saving
        $prefs = [
            'stars'         => $body['stars']         ?? new stdClass(),
            'listOrder'     => $body['listOrder']      ?? [],
            'collapsed'     => $body['collapsed']      ?? new stdClass(),
            'taskCollapsed' => $body['taskCollapsed']  ?? new stdClass(),
            'settings'      => $body['settings']       ?? new stdClass(),
            'savedAt'       => date('c'),
        ];

        if (file_put_contents($prefsFile, json_encode($prefs)) === false) {
            jsonResponse(['error' => 'Could not save preferences'], 500);
        }
        jsonResponse(['success' => true]);

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
