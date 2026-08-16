<?php
/**
 * api/admin.php — Admin-only endpoints
 *
 * GET  ?resource=users                                 — list all users who have ever logged in
 * POST { action:'toggle_admin', email }                — flip a user's admin flag
 * GET  ?resource=feedback                               — list submitted feedback
 * POST { action:'update_feedback_status', id, status }  — change a feedback item's status
 */
require_once '../config.php';
require_once '../feedback_db.php';

if (!isAuthenticated()) { jsonResponse(['error' => 'Not authenticated'], 401); }
if (!isAdmin())         { jsonResponse(['error' => 'Admin access required'], 403); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $resource = $_GET['resource'] ?? '';

    if ($resource === 'users') {
        $users = array_values(loadUsers());
        usort($users, fn($a, $b) => strcmp($b['last_seen'] ?? '', $a['last_seen'] ?? ''));
        jsonResponse(['users' => $users]);
    }

    if ($resource === 'feedback') {
        try {
            jsonResponse(['feedback' => feedbackList()]);
        } catch (Throwable $e) {
            jsonResponse(['error' => $e->getMessage()], 503);
        }
    }

    jsonResponse(['error' => 'Unknown resource'], 400);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

if ($action === 'toggle_admin') {
    $email = trim(strtolower($body['email'] ?? ''));
    if (!$email) { jsonResponse(['error' => 'Missing email'], 400); }
    $selfEmail = strtolower($_SESSION['user']['email'] ?? '');
    if ($email === $selfEmail) {
        jsonResponse(['error' => 'You cannot remove your own admin access'], 400);
    }
    $users = loadUsers();
    if (!isset($users[$email])) { jsonResponse(['error' => 'Unknown user'], 404); }
    $users[$email]['is_admin'] = empty($users[$email]['is_admin']);
    saveUsers($users);
    jsonResponse(['ok' => true, 'is_admin' => $users[$email]['is_admin']]);
}

if ($action === 'update_feedback_status') {
    $id     = (int)($body['id'] ?? 0);
    $status = $body['status'] ?? '';
    if (!$id || !in_array($status, ['new', 'reviewed', 'resolved'], true)) {
        jsonResponse(['error' => 'Invalid id or status'], 400);
    }
    try {
        feedbackUpdateStatus($id, $status);
        jsonResponse(['ok' => true]);
    } catch (Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 503);
    }
}

jsonResponse(['error' => 'Unknown action'], 400);
