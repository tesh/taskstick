<?php
/**
 * api/share.php — Jointly-owned "Shared" tasks. Sharing a task or list MOVES
 * it out of Google Tasks and into our own database (see shared_tasks_db.php),
 * where every participant (owner included) has full parity: edit, complete,
 * delete, or change who it's shared with. Nothing here touches Google Tasks
 * again except the one-time delete of the original task(s) at share time.
 *
 * GET  — shared tasks the current user participates in
 * POST { action:'share',        taskId, listId, taskTitle, taskNotes, taskDue, sourceListTitle, shareWith:[{email,name}] }
 * POST { action:'share_list',   listId, listTitle, shareWith:[{email,name}] }
 * POST { action:'update',       id, title?, notes?, due? }
 * POST { action:'complete',     id, status }
 * POST { action:'set_starred',  id, starred }
 * POST { action:'set_participants', id, participants:[{email,name}] }
 * POST { action:'delete',       id }
 */
require_once '../config.php';
require_once '../shared_tasks_db.php';

if (!isAuthenticated()) { jsonResponse(['error' => 'Not authenticated'], 401); }

$userEmail = $_SESSION['user']['email'] ?? '';
$userName  = $_SESSION['user']['name']  ?? 'User';
if (!$userEmail) { jsonResponse(['error' => 'No user email in session'], 400); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        jsonResponse(['items' => sharedTaskListForUser($userEmail)]);
    } catch (Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 503);
    }
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

try {
    if ($action === 'share') {
        $taskId    = $body['taskId']    ?? '';
        $listId    = $body['listId']    ?? '';
        $taskTitle = trim($body['taskTitle'] ?? '');
        $taskNotes = $body['taskNotes'] ?? '';
        $taskDue   = $body['taskDue']   ?? '';
        $shareWith = $body['shareWith'] ?? [];
        $sourceListTitle = $body['sourceListTitle'] ?? null;

        if (!$taskId || !$listId || !$taskTitle || empty($shareWith)) {
            jsonResponse(['error' => 'taskId, listId, taskTitle and shareWith are required'], 400);
        }

        $participants = array_merge([['email' => $userEmail, 'name' => $userName]], $shareWith);
        $id = sharedTaskCreate(
            $taskTitle, $taskNotes, $taskDue ?: null,
            $userEmail, $userName, $sourceListTitle, $participants
        );

        // Move: remove the original from Google Tasks now that it lives here.
        googleApiRequest('/lists/' . urlencode($listId) . '/tasks/' . urlencode($taskId), 'DELETE');

        jsonResponse(['ok' => true, 'id' => $id]);
    }

    if ($action === 'share_list') {
        $listId    = $body['listId']    ?? '';
        $listTitle = trim($body['listTitle'] ?? '');
        $shareWith = $body['shareWith'] ?? [];

        if (!$listId || !$listTitle || empty($shareWith)) {
            jsonResponse(['error' => 'listId, listTitle and shareWith are required'], 400);
        }

        $participants = array_merge([['email' => $userEmail, 'name' => $userName]], $shareWith);

        $result = googleApiRequest('/lists/' . urlencode($listId) . '/tasks?' . http_build_query([
            'maxResults'    => 100,
            'showCompleted' => 'false',
            'showHidden'    => 'false',
        ]));
        $tasks = $result['items'] ?? [];

        $ids = [];
        foreach ($tasks as $t) {
            // Skip subtasks — only top-level tasks migrate for now.
            if (!empty($t['parent']) || empty($t['title'])) continue;
            $ids[] = sharedTaskCreate(
                $t['title'], $t['notes'] ?? '', $t['due'] ?? null,
                $userEmail, $userName, $listTitle, $participants
            );
            googleApiRequest('/lists/' . urlencode($listId) . '/tasks/' . urlencode($t['id']), 'DELETE');
        }

        jsonResponse(['ok' => true, 'ids' => $ids]);
    }

    if ($action === 'update') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { jsonResponse(['error' => 'id required'], 400); }
        $fields = [];
        if (array_key_exists('title', $body)) $fields['title'] = trim($body['title']);
        if (array_key_exists('notes', $body)) $fields['notes'] = $body['notes'];
        if (array_key_exists('due',   $body)) $fields['due']   = $body['due'] ?: null;
        sharedTaskUpdate($id, $userEmail, $fields);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'complete') {
        $id     = (int)($body['id'] ?? 0);
        $status = $body['status'] ?? '';
        if (!$id || !in_array($status, ['needsAction', 'completed'], true)) {
            jsonResponse(['error' => 'Invalid id or status'], 400);
        }
        sharedTaskUpdate($id, $userEmail, [
            'status'       => $status,
            'completed_at' => $status === 'completed' ? date('Y-m-d H:i:s') : null,
        ]);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'set_starred') {
        $id      = (int)($body['id'] ?? 0);
        $starred = !empty($body['starred']);
        if (!$id) { jsonResponse(['error' => 'id required'], 400); }
        sharedTaskUpdate($id, $userEmail, ['starred' => $starred ? 1 : 0]);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'set_participants') {
        $id           = (int)($body['id'] ?? 0);
        $participants = $body['participants'] ?? [];
        if (!$id) { jsonResponse(['error' => 'id required'], 400); }
        sharedTaskSetParticipants($id, $userEmail, $participants);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { jsonResponse(['error' => 'id required'], 400); }
        sharedTaskDelete($id, $userEmail);
        jsonResponse(['ok' => true]);
    }
} catch (SharedTaskAuthException $e) {
    jsonResponse(['error' => $e->getMessage()], 403);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 503);
}

jsonResponse(['error' => 'Unknown action'], 400);
