<?php
/**
 * api/user-stats.php — Self-reported usage stats, shown to admins in the
 * Users view (list count, task counts). There's no server-side way to
 * read another user's Google Tasks — each account's data is only ever
 * accessible via that account's own OAuth session — so a user's stats
 * are only ever written by that same user, from what their own client
 * already has loaded, never fetched by an admin on their behalf.
 *
 * POST { listCount, activeTaskCount, completedTaskCount } → updates the
 *   CURRENT session user's own record in data/users.json. Always keyed
 *   by the authenticated session's own email — never a client-supplied
 *   one — so there's no way to report stats for a different user.
 */
require_once '../config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isAuthenticated()) { jsonResponse(['error' => 'Not authenticated'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'Method not allowed'], 405); }

$email = $_SESSION['user']['email'] ?? '';
if (!$email) { jsonResponse(['error' => 'User not found in session'], 400); }

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$listCount          = max(0, (int)($body['listCount']          ?? 0));
$activeTaskCount    = max(0, (int)($body['activeTaskCount']    ?? 0));
$completedTaskCount = max(0, (int)($body['completedTaskCount'] ?? 0));

updateUsers(function (array $users) use ($email, $listCount, $activeTaskCount, $completedTaskCount) {
    if (isset($users[$email])) {
        $users[$email]['list_count']           = $listCount;
        $users[$email]['active_task_count']    = $activeTaskCount;
        $users[$email]['completed_task_count'] = $completedTaskCount;
        $users[$email]['stats_updated_at']     = gmdate('c');
    }
    return $users;
});

jsonResponse(['success' => true]);
