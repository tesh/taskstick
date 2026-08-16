<?php
/**
 * api/version.php — lets the client detect a stale cached copy of itself.
 * Returns index.html's last-modified time, so it changes automatically on
 * every deploy with no manual version-bumping required. Always network —
 * never cached (matches the service worker's /api/ bypass rule).
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['version' => (string) filemtime(dirname(__DIR__) . '/index.html')]);
