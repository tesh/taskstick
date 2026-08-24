<?php
require_once '../config.php';
require_once '../task_dates_db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isAuthenticated()) {
    jsonResponse(['error' => 'Not authenticated'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$listId = $_GET['listId'] ?? null;
$taskId = $_GET['taskId'] ?? null;

if (!$listId) {
    jsonResponse(['error' => 'listId required'], 400);
}

// Read body for POST/PATCH
$body = null;
if (in_array($method, ['POST', 'PATCH'])) {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
}

switch ($method) {
    case 'GET':
        // Get all tasks for a list (include completed and hidden for full display)
        $params = http_build_query([
            'maxResults'    => 100,
            'showCompleted' => 'true',
            'showHidden'    => 'true',
        ]);
        $result = googleApiRequest('/lists/' . urlencode($listId) . '/tasks?' . $params);
        // Best-effort: task-age tracking is a nice-to-have layered on top of
        // Google Tasks (the real source of truth), so a DB hiccup here must
        // never break viewing/creating tasks themselves.
        if (!empty($result['items'])) {
            try {
                $userEmail = $_SESSION['user']['email'] ?? '';
                $taskIds   = array_column($result['items'], 'id');
                taskDatesBackfillMissing($taskIds, $userEmail);
                $result['taskDates'] = taskDatesGetFor($taskIds, $userEmail);
            } catch (Throwable $e) {
                // Swallow — task list itself already loaded fine.
            }
        }
        jsonResponse($result);

    case 'POST':
        // Move task within or across lists: action=move
        if (!empty($body['action']) && $body['action'] === 'move') {
            if (!$taskId) jsonResponse(['error' => 'taskId required'], 400);
            $params = [];
            if (!empty($body['previous'])) $params['previous'] = $body['previous'];
            if (!empty($body['parent']))   $params['parent']   = $body['parent'];
            $qs = $params ? '?' . http_build_query($params) : '';
            $result = googleApiRequest(
                '/lists/' . urlencode($listId) . '/tasks/' . urlencode($taskId) . '/move' . $qs,
                'POST'
            );
            if (!empty($result['error'])) {
                jsonResponse($result, 500);
            }
            jsonResponse($result);
        }
        // Create a new task
        if (empty($body['title'])) {
            jsonResponse(['error' => 'title required'], 400);
        }
        $task = ['title' => trim($body['title'])];
        if (!empty($body['notes'])) $task['notes'] = $body['notes'];
        if (!empty($body['due']))   $task['due']   = $body['due'];
        if (!empty($body['status'])) $task['status'] = $body['status'];
        // Google Tasks requires parent/previous as URL query params on create, not body fields.
        $createParams = [];
        if (!empty($body['parent']))   $createParams['parent']   = $body['parent'];
        if (!empty($body['previous'])) $createParams['previous'] = $body['previous'];
        $createQs = $createParams ? '?' . http_build_query($createParams) : '';
        $result = googleApiRequest('/lists/' . urlencode($listId) . '/tasks' . $createQs, 'POST', $task);
        if (!empty($result['error'])) {
            jsonResponse($result, 500);
        }
        // This is the one moment we ever know a task's *real* creation
        // time — record it now, before it's just "some task we noticed."
        if (!empty($result['id'])) {
            try {
                taskDatesBackfillMissing([$result['id']], $_SESSION['user']['email'] ?? '');
            } catch (Throwable $e) {
                // Swallow — the task itself was created successfully either way.
            }
        }
        jsonResponse($result);

    case 'PATCH':
        // Update task fields
        if (!$taskId) jsonResponse(['error' => 'taskId required'], 400);
        $update = [];
        if (isset($body['title']))    $update['title']    = $body['title'];
        if (isset($body['notes']))    $update['notes']    = $body['notes'];
        if (isset($body['status']))   $update['status']   = $body['status'];
        if (array_key_exists('due', $body))     $update['due']     = $body['due'];   // null clears it
        if (isset($body['starred']))  $update['starred']  = (bool)$body['starred'];
        $result = googleApiRequest(
            '/lists/' . urlencode($listId) . '/tasks/' . urlencode($taskId),
            'PATCH',
            $update
        );
        if (!empty($result['error'])) {
            jsonResponse($result, 500);
        }
        jsonResponse($result);

    case 'DELETE':
        // Delete a task
        if (!$taskId) jsonResponse(['error' => 'taskId required'], 400);
        $result = googleApiRequest(
            '/lists/' . urlencode($listId) . '/tasks/' . urlencode($taskId),
            'DELETE'
        );
        if (!empty($result['error'])) {
            jsonResponse($result, 500);
        }
        jsonResponse(['success' => true]);

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
