<?php
/**
 * api/admin.php — Admin-only endpoints
 *
 * GET  ?resource=users                                 — list all users who have ever logged in
 * POST { action:'toggle_admin', email }                — flip a user's admin flag
 * GET  ?resource=feedback                               — list active (non-deleted) feedback
 * GET  ?resource=feedback_archive_md                    — download deleted feedback as a .md file
 * POST { action:'update_feedback_status', id, status }  — change a feedback item's status
 * POST { action:'delete_feedback', id }                 — soft-delete (archive) a feedback item
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

    if ($resource === 'feedback_archive_md') {
        try {
            $items = feedbackArchiveList();
        } catch (Throwable $e) {
            jsonResponse(['error' => $e->getMessage()], 503);
        }

        // A feedback description is free text from any authenticated user —
        // fence it so it can't forge fake "## Type — Name" entries or a
        // stray "---" that would visually corrupt the exported document's
        // structure. The fence uses more backticks than the longest run
        // already in the text, so the text can't itself break out of it.
        $fenceFor = function (string $text): string {
            preg_match_all('/`+/', $text, $m);
            $maxRun = 0;
            foreach ($m[0] as $run) { $maxRun = max($maxRun, strlen($run)); }
            $fence = str_repeat('`', max(3, $maxRun + 1));
            return $fence . "\n" . $text . "\n" . $fence;
        };

        $typeLabels = ['bug' => 'Bug', 'feature' => 'Feature', 'suggestion' => 'Suggestion'];
        $lines = ['# TaskStick Feedback Archive', '', 'Exported ' . gmdate('Y-m-d H:i') . ' UTC', ''];
        if (!$items) {
            $lines[] = '_No archived feedback yet._';
        }
        foreach ($items as $f) {
            $label = $typeLabels[$f['type']] ?? $f['type'];
            $lines[] = "## {$label} — {$f['submitter_name']} ({$f['submitter_email']})";
            $lines[] = "- Submitted: {$f['created_at']}";
            $lines[] = "- Deleted: {$f['deleted_at']}";
            $lines[] = "- Status when deleted: {$f['status']}";
            $lines[] = '';
            $lines[] = $fenceFor($f['description']);
            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
        }

        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="taskstick-feedback-archive-' . date('Y-m-d') . '.md"');
        echo implode("\n", $lines);
        exit;
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
    // The mutator's own isset() check (run under the lock) is the real
    // guard — this is just the source of truth for the response, since the
    // mutator can't itself distinguish "found and toggled" from "already
    // gone" other than by leaving $newValue unset.
    $newValue = null;
    updateUsers(function (array $users) use ($email, &$newValue) {
        if (isset($users[$email])) {
            $users[$email]['is_admin'] = empty($users[$email]['is_admin']);
            $newValue = $users[$email]['is_admin'];
        }
        return $users;
    });
    if ($newValue === null) { jsonResponse(['error' => 'Unknown user'], 404); }
    jsonResponse(['ok' => true, 'is_admin' => $newValue]);
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

if ($action === 'delete_feedback') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { jsonResponse(['error' => 'Invalid id'], 400); }
    try {
        feedbackSoftDelete($id);
        jsonResponse(['ok' => true]);
    } catch (Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 503);
    }
}

jsonResponse(['error' => 'Unknown action'], 400);
