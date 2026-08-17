<?php
require_once '../config.php';

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
        $result = googleApiRequest('/lists/' . urlencode($listId) . '/tasks', 'POST', $task);
        if (!empty($result['error'])) {
            jsonResponse($result, 500);
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
