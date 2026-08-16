<?php
/**
 * api/feedback.php — Submit user feedback (bug / feature request / suggestion)
 *
 * POST { type: 'bug'|'feature'|'suggestion', description }
 */
require_once '../config.php';
require_once '../feedback_db.php';

if (!isAuthenticated()) { jsonResponse(['error' => 'Not authenticated'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'Method not allowed'], 405); }

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$type        = $body['type'] ?? '';
$description = trim($body['description'] ?? '');

if (!in_array($type, ['bug', 'feature', 'suggestion'], true)) {
    jsonResponse(['error' => 'Invalid feedback type'], 400);
}
if ($description === '') {
    jsonResponse(['error' => 'Description is required'], 400);
}

$email = $_SESSION['user']['email'] ?? '';
$name  = $_SESSION['user']['name']  ?? 'User';

try {
    feedbackCreate($type, $description, $email, $name);
    jsonResponse(['ok' => true]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 503);
}
