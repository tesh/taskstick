<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isAuthenticated()) {
    jsonResponse(['error' => 'Not authenticated'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Fetch all task lists
        $result = googleApiRequest('/users/@me/lists?maxResults=100');
        jsonResponse($result);

    case 'POST':
        // Create a new task list
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($body['title'])) {
            jsonResponse(['error' => 'title required'], 400);
        }
        $result = googleApiRequest('/users/@me/lists', 'POST', ['title' => trim($body['title'])]);
        jsonResponse($result);

    case 'PATCH':
        // Rename a task list
        $listId = $_GET['listId'] ?? '';
        if (!$listId) {
            jsonResponse(['error' => 'listId required'], 400);
        }
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $title = trim($body['title'] ?? '');
        if (!$title) {
            jsonResponse(['error' => 'title required'], 400);
        }
        $result = googleApiRequest(
            '/users/@me/lists/' . urlencode($listId),
            'PATCH',
            ['title' => $title]
        );
        if (!empty($result['error'])) {
            jsonResponse($result, 500);
        }
        jsonResponse(['success' => true, 'title' => $result['title'] ?? $title]);

    case 'DELETE':
        // Delete a task list (and all its tasks via the Google Tasks API)
        $listId = $_GET['listId'] ?? '';
        if (!$listId) {
            jsonResponse(['error' => 'listId required'], 400);
        }

        $result = googleApiRequest('/users/@me/lists/' . urlencode($listId), 'DELETE');
        // DELETE returns 204 No Content on success; googleApiRequest returns []
        if (!empty($result['error'])) {
            jsonResponse($result, 500);
        }
        jsonResponse(['success' => true]);

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
