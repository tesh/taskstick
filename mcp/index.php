<?php
/**
 * tasks.tesh.ai — Google Tasks MCP Server
 * =========================================
 * Implements the Model Context Protocol (MCP) Streamable HTTP transport.
 * https://modelcontextprotocol.io
 *
 * Endpoint: https://tasks.tesh.ai/mcp/
 * Auth:     Authorization: Bearer <MCP_API_KEY>
 * Method:   POST, Content-Type: application/json
 *
 * Supported MCP methods:
 *   initialize            — handshake, return server capabilities
 *   notifications/initialized — acknowledge (no-op)
 *   tools/list            — return available tool definitions
 *   tools/call            — execute a tool
 */

require_once __DIR__ . '/config.php';

// ─── CORS & headers ──────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id');
header('Content-Type: application/json');
header('Cache-Control: no-store');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// ─── Authentication — per-user API keys ──────────────────────────────────────
// On IONOS shared hosting, Apache may strip HTTP_AUTHORIZATION.
// Fall back to REDIRECT_HTTP_AUTHORIZATION or getallheaders().
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';
if (!$authHeader && function_exists('getallheaders')) {
    $allHeaders = getallheaders();
    // Header names are case-insensitive
    foreach ($allHeaders as $k => $v) {
        if (strtolower($k) === 'authorization') {
            $authHeader = $v;
            break;
        }
    }
}

$providedKey = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $providedKey = trim($m[1]);
}

// Fallback: some clients (e.g. Claude.ai web connector) cannot set custom request headers,
// so also accept the key as a URL query parameter: ?key=mcp_xxx
if (!$providedKey && !empty($_GET['key'])) {
    $providedKey = trim($_GET['key']);
}

// Each user has their own key file: data/mcp_key_{safeKey}.json
// The file maps the key back to the Google user ID so we know whose tokens to load.
$safeKey = $providedKey ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $providedKey) : '';
$keyFile = $safeKey ? DATA_DIR . '/mcp_key_' . $safeKey . '.json' : '';
$keyData = ($keyFile && file_exists($keyFile))
         ? json_decode(file_get_contents($keyFile), true)
         : null;

if (!$keyData || empty($keyData['userId'])) {
    http_response_code(401);
    echo json_encode(rpcError(null, -32600,
        'Unauthorized: invalid or missing API key. '
        . 'Visit https://tasks.tesh.ai/mcp/setup.php while signed in to get your personal key.'));
    exit;
}

// Make the resolved user ID available globally so loadTokens()/saveTokens() know which file to use
$GLOBALS['mcp_user_id'] = preg_replace('/[^a-zA-Z0-9_-]/', '_', $keyData['userId']);

// ─── Parse request body ──────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$req = json_decode($raw, true);

if (!$req || !isset($req['jsonrpc']) || $req['jsonrpc'] !== '2.0') {
    http_response_code(400);
    echo json_encode(rpcError(null, -32600, 'Invalid JSON-RPC request'));
    exit;
}

$id     = $req['id']     ?? null;    // may be null for notifications
$method = $req['method'] ?? '';
$params = $req['params'] ?? [];

// ─── Route methods ───────────────────────────────────────────────────────────
switch ($method) {

    case 'initialize':
        echo json_encode(rpcOk($id, [
            'protocolVersion' => MCP_PROTOCOL_VERSION,
            'serverInfo' => [
                'name'    => MCP_SERVER_NAME,
                'version' => MCP_SERVER_VERSION,
            ],
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
        ]));
        break;

    case 'notifications/initialized':
        // Client is telling us it finished initialising — no response needed
        http_response_code(204);
        break;

    case 'tools/list':
        echo json_encode(rpcOk($id, ['tools' => toolDefinitions()]));
        break;

    case 'tools/call':
        $toolName = $params['name']      ?? '';
        $args     = $params['arguments'] ?? [];
        $result   = dispatchTool($toolName, $args);
        echo json_encode(rpcOk($id, $result));
        break;

    default:
        echo json_encode(rpcError($id, -32601, "Method not found: $method"));
        break;
}
exit;

// ═════════════════════════════════════════════════════════════════════════════
// JSON-RPC helpers
// ═════════════════════════════════════════════════════════════════════════════

function rpcOk($id, $result): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

function rpcError($id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

// MCP tool result helpers
function toolOk(string $text): array {
    return ['content' => [['type' => 'text', 'text' => $text]]];
}

function toolErr(string $message): array {
    return ['isError' => true, 'content' => [['type' => 'text', 'text' => $message]]];
}

// ═════════════════════════════════════════════════════════════════════════════
// Tool definitions (returned by tools/list)
// ═════════════════════════════════════════════════════════════════════════════

function toolDefinitions(): array {
    return [

        [
            'name'        => 'tasks_list_tasklists',
            'description' => 'List all Google Task lists for the authenticated user.',
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],

        [
            'name'        => 'tasks_list_tasks',
            'description' => 'List tasks in a specific Google Tasks list. Returns title, notes, due date, completion status, starred status, position, and subtask parent ID for each task.',
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'listId'           => ['type' => 'string', 'description' => 'The ID of the task list (from tasks_list_tasklists).'],
                    'includeCompleted' => ['type' => 'boolean', 'description' => 'Include completed tasks. Default: false.'],
                ],
                'required' => ['listId'],
            ],
        ],

        [
            'name'        => 'tasks_get_task',
            'description' => 'Get full details of a single task including its notes field (which may contain AI-generated suggestions).',
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'listId' => ['type' => 'string', 'description' => 'The task list ID.'],
                    'taskId' => ['type' => 'string', 'description' => 'The task ID.'],
                ],
                'required' => ['listId', 'taskId'],
            ],
        ],

        [
            'name'        => 'tasks_create_task',
            'description' => 'Create a new task in a Google Tasks list.',
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'listId' => ['type' => 'string', 'description' => 'The task list ID.'],
                    'title'  => ['type' => 'string', 'description' => 'Task title (required).'],
                    'notes'  => ['type' => 'string', 'description' => 'Task notes / description (optional).'],
                    'due'    => ['type' => 'string', 'description' => 'Due date in RFC 3339 format, e.g. 2026-04-01T00:00:00.000Z (optional).'],
                ],
                'required' => ['listId', 'title'],
            ],
        ],

        [
            'name'        => 'tasks_update_task',
            'description' => 'Update fields on an existing task. Only provided fields are changed.',
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'listId'  => ['type' => 'string', 'description' => 'The task list ID.'],
                    'taskId'  => ['type' => 'string', 'description' => 'The task ID.'],
                    'title'   => ['type' => 'string', 'description' => 'New title (optional).'],
                    'notes'   => ['type' => 'string', 'description' => 'Replace the full notes field (optional). Use tasks_add_ai_note to append instead.'],
                    'status'  => ['type' => 'string', 'enum' => ['needsAction', 'completed'], 'description' => 'Task status (optional).'],
                    'due'     => ['type' => ['string', 'null'], 'description' => 'Due date RFC 3339 string, or null to clear (optional).'],
                    'starred' => ['type' => 'boolean', 'description' => 'Star or unstar the task (optional).'],
                ],
                'required' => ['listId', 'taskId'],
            ],
        ],

        [
            'name'        => 'tasks_complete_task',
            'description' => 'Mark a task as completed.',
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'listId' => ['type' => 'string', 'description' => 'The task list ID.'],
                    'taskId' => ['type' => 'string', 'description' => 'The task ID.'],
                ],
                'required' => ['listId', 'taskId'],
            ],
        ],

        [
            'name'        => 'tasks_star_task',
            'description' => 'Star or unstar a task to flag it as high priority.',
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'listId'  => ['type' => 'string', 'description' => 'The task list ID.'],
                    'taskId'  => ['type' => 'string', 'description' => 'The task ID.'],
                    'starred' => ['type' => 'boolean', 'description' => 'true to star, false to unstar.'],
                ],
                'required' => ['listId', 'taskId', 'starred'],
            ],
        ],

        [
            'name'        => 'tasks_add_ai_note',
            'description' => 'Append an AI-generated suggestion or analysis to a task\'s notes field. '
                           . 'Use this when you have reviewed a task and want to record how you could help, '
                           . 'what context is relevant, or what the next step should be. '
                           . 'The note is timestamped and appended below any existing notes.',
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'listId' => ['type' => 'string', 'description' => 'The task list ID.'],
                    'taskId' => ['type' => 'string', 'description' => 'The task ID.'],
                    'note'   => ['type' => 'string', 'description' => 'The AI suggestion or analysis to append. Keep it concise and actionable.'],
                ],
                'required' => ['listId', 'taskId', 'note'],
            ],
        ],

        [
            'name'        => 'tasks_move_task',
            'description' => 'Move a task within a list (change its position) or make it a subtask of another task.',
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'listId'   => ['type' => 'string', 'description' => 'The task list ID.'],
                    'taskId'   => ['type' => 'string', 'description' => 'The task to move.'],
                    'previous' => ['type' => 'string', 'description' => 'ID of the task this should appear after (optional — omit to move to top).'],
                    'parent'   => ['type' => 'string', 'description' => 'ID of the parent task to nest under (optional — makes this a subtask).'],
                ],
                'required' => ['listId', 'taskId'],
            ],
        ],

    ];
}

// ═════════════════════════════════════════════════════════════════════════════
// Tool dispatcher
// ═════════════════════════════════════════════════════════════════════════════

function dispatchTool(string $name, array $args): array {
    switch ($name) {
        case 'tasks_list_tasklists': return tool_listTasklists();
        case 'tasks_list_tasks':     return tool_listTasks($args);
        case 'tasks_get_task':       return tool_getTask($args);
        case 'tasks_create_task':    return tool_createTask($args);
        case 'tasks_update_task':    return tool_updateTask($args);
        case 'tasks_complete_task':  return tool_completeTask($args);
        case 'tasks_star_task':      return tool_starTask($args);
        case 'tasks_add_ai_note':    return tool_addAiNote($args);
        case 'tasks_move_task':      return tool_moveTask($args);
        default:
            return toolErr("Unknown tool: $name");
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// Tool implementations
// ═════════════════════════════════════════════════════════════════════════════

function tool_listTasklists(): array {
    $result = googleRequest('/users/@me/lists?maxResults=20');
    if (isset($result['error'])) return toolErr($result['error']);

    $lists = $result['items'] ?? [];
    if (empty($lists)) return toolOk('No task lists found.');

    $lines = [];
    foreach ($lists as $list) {
        $lines[] = sprintf('- **%s** (id: `%s`)',
            $list['title'] ?? 'Untitled',
            $list['id']    ?? ''
        );
    }
    return toolOk("Task lists:\n" . implode("\n", $lines));
}

function tool_listTasks(array $args): array {
    $listId           = $args['listId']           ?? '';
    $includeCompleted = $args['includeCompleted']  ?? false;

    if (!$listId) return toolErr('listId is required.');

    $params = http_build_query([
        'maxResults'    => 100,
        'showCompleted' => $includeCompleted ? 'true' : 'false',
        'showHidden'    => $includeCompleted ? 'true' : 'false',
    ]);

    $result = googleRequest('/lists/' . urlencode($listId) . '/tasks?' . $params);
    if (isset($result['error'])) return toolErr($result['error']);

    $tasks = $result['items'] ?? [];
    if (empty($tasks)) return toolOk('No tasks found in this list.');

    // Sort ascending by position (same as tasks.tesh.ai display)
    usort($tasks, function ($a, $b) {
        $pa = $a['position'] ?? '';
        $pb = $b['position'] ?? '';
        return $pa > $pb ? 1 : ($pa < $pb ? -1 : 0);
    });

    $lines = ["Tasks in list (id: `$listId`):\n"];
    foreach ($tasks as $t) {
        $indent  = !empty($t['parent']) ? '  ' : '';
        $star    = !empty($t['starred']) ? ' ⭐' : '';
        $done    = ($t['status'] === 'completed') ? ' ✅' : '';
        $due     = !empty($t['due'])   ? ' 📅 ' . substr($t['due'], 0, 10) : '';
        $hasNote = !empty($t['notes']) ? ' 📝' : '';
        $lines[] = sprintf('%s- [%s] **%s**%s%s%s%s  *(id: `%s`)*',
            $indent,
            $t['status'] === 'completed' ? 'x' : ' ',
            $t['title']  ?? 'Untitled',
            $star, $done, $due, $hasNote,
            $t['id'] ?? ''
        );
    }
    return toolOk(implode("\n", $lines));
}

function tool_getTask(array $args): array {
    $listId = $args['listId'] ?? '';
    $taskId = $args['taskId'] ?? '';
    if (!$listId || !$taskId) return toolErr('listId and taskId are required.');

    $task = googleRequest('/lists/' . urlencode($listId) . '/tasks/' . urlencode($taskId));
    if (isset($task['error'])) return toolErr($task['error']);

    $lines = [
        '**Title:** ' . ($task['title']  ?? 'Untitled'),
        '**Status:** ' . ($task['status'] ?? 'unknown'),
        '**Starred:** ' . (!empty($task['starred']) ? 'Yes' : 'No'),
        '**Due:** ' . (isset($task['due']) ? substr($task['due'], 0, 10) : 'None'),
        '**Parent:** ' . ($task['parent'] ?? 'None (top-level)'),
        '**Position:** ' . ($task['position'] ?? 'unknown'),
        '**Task ID:** `' . ($task['id'] ?? '') . '`',
        '**List ID:** `' . $listId . '`',
        '',
        '**Notes:**',
        $task['notes'] ?? '*(none)*',
    ];
    return toolOk(implode("\n", $lines));
}

function tool_createTask(array $args): array {
    $listId = $args['listId'] ?? '';
    $title  = trim($args['title'] ?? '');
    if (!$listId) return toolErr('listId is required.');
    if (!$title)  return toolErr('title is required.');

    $body = ['title' => $title];
    if (!empty($args['notes'])) $body['notes'] = $args['notes'];
    if (!empty($args['due']))   $body['due']   = $args['due'];

    $result = googleRequest('/lists/' . urlencode($listId) . '/tasks', 'POST', $body);
    if (isset($result['error'])) return toolErr($result['error']);

    return toolOk(sprintf("Task created: **%s** (id: `%s`)", $result['title'] ?? $title, $result['id'] ?? ''));
}

function tool_updateTask(array $args): array {
    $listId = $args['listId'] ?? '';
    $taskId = $args['taskId'] ?? '';
    if (!$listId || !$taskId) return toolErr('listId and taskId are required.');

    $update = [];
    if (isset($args['title']))   $update['title']   = $args['title'];
    if (isset($args['notes']))   $update['notes']   = $args['notes'];
    if (isset($args['status']))  $update['status']  = $args['status'];
    if (array_key_exists('due', $args))     $update['due']     = $args['due'];  // null clears it
    if (isset($args['starred'])) $update['starred'] = (bool)$args['starred'];

    if (empty($update)) return toolErr('No fields to update were provided.');

    $result = googleRequest(
        '/lists/' . urlencode($listId) . '/tasks/' . urlencode($taskId),
        'PATCH',
        $update
    );
    if (isset($result['error'])) return toolErr($result['error']);

    return toolOk(sprintf("Task updated: **%s** (id: `%s`)", $result['title'] ?? $taskId, $result['id'] ?? $taskId));
}

function tool_completeTask(array $args): array {
    $listId = $args['listId'] ?? '';
    $taskId = $args['taskId'] ?? '';
    if (!$listId || !$taskId) return toolErr('listId and taskId are required.');

    $result = googleRequest(
        '/lists/' . urlencode($listId) . '/tasks/' . urlencode($taskId),
        'PATCH',
        ['status' => 'completed']
    );
    if (isset($result['error'])) return toolErr($result['error']);

    return toolOk(sprintf("Task marked complete: **%s** ✅", $result['title'] ?? $taskId));
}

function tool_starTask(array $args): array {
    $listId  = $args['listId']  ?? '';
    $taskId  = $args['taskId']  ?? '';
    $starred = $args['starred'] ?? true;
    if (!$listId || !$taskId) return toolErr('listId and taskId are required.');

    $result = googleRequest(
        '/lists/' . urlencode($listId) . '/tasks/' . urlencode($taskId),
        'PATCH',
        ['starred' => (bool)$starred]
    );
    if (isset($result['error'])) return toolErr($result['error']);

    $label = $starred ? 'starred ⭐' : 'unstarred';
    return toolOk(sprintf("Task %s: **%s**", $label, $result['title'] ?? $taskId));
}

function tool_addAiNote(array $args): array {
    $listId = $args['listId'] ?? '';
    $taskId = $args['taskId'] ?? '';
    $note   = trim($args['note'] ?? '');
    if (!$listId || !$taskId) return toolErr('listId and taskId are required.');
    if (!$note) return toolErr('note is required.');

    // Fetch current task to get existing notes
    $task = googleRequest('/lists/' . urlencode($listId) . '/tasks/' . urlencode($taskId));
    if (isset($task['error'])) return toolErr('Could not fetch task: ' . $task['error']);

    $existing = trim($task['notes'] ?? '');
    $timestamp = gmdate('Y-m-d H:i') . ' UTC';
    $aiBlock   = "---\n🤖 AI note ($timestamp):\n$note";

    $newNotes = $existing ? "$existing\n\n$aiBlock" : $aiBlock;

    $result = googleRequest(
        '/lists/' . urlencode($listId) . '/tasks/' . urlencode($taskId),
        'PATCH',
        ['notes' => $newNotes]
    );
    if (isset($result['error'])) return toolErr($result['error']);

    return toolOk(sprintf("AI note appended to: **%s**\n\nFull notes:\n%s", $result['title'] ?? $taskId, $newNotes));
}

function tool_moveTask(array $args): array {
    $listId   = $args['listId']   ?? '';
    $taskId   = $args['taskId']   ?? '';
    if (!$listId || !$taskId) return toolErr('listId and taskId are required.');

    $params = [];
    if (!empty($args['previous'])) $params['previous'] = $args['previous'];
    if (!empty($args['parent']))   $params['parent']   = $args['parent'];
    $qs = $params ? '?' . http_build_query($params) : '';

    $result = googleRequest(
        '/lists/' . urlencode($listId) . '/tasks/' . urlencode($taskId) . '/move' . $qs,
        'POST'
    );
    if (isset($result['error'])) return toolErr($result['error']);

    return toolOk(sprintf("Task moved: **%s** (id: `%s`)", $result['title'] ?? $taskId, $result['id'] ?? $taskId));
}

// ═════════════════════════════════════════════════════════════════════════════
// Google Tasks API client — reads tokens from tokens.json
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Load tokens for the currently authenticated user.
 * File: data/tokens_{safeUserId}.json
 * The user ID is set in $GLOBALS['mcp_user_id'] during request auth.
 */
function loadTokens(): array {
    $userId    = $GLOBALS['mcp_user_id'] ?? '';
    $tokenFile = DATA_DIR . '/tokens_' . $userId . '.json';
    if (!file_exists($tokenFile)) {
        throw new RuntimeException(
            'Tokens not found for your account. Visit https://tasks.tesh.ai/mcp/setup.php '
            . 'while signed in to complete setup.'
        );
    }
    $tokens = json_decode(file_get_contents($tokenFile), true);
    if (!$tokens || empty($tokens['refresh_token'])) {
        throw new RuntimeException(
            'Token file is missing or corrupt. Re-run setup at https://tasks.tesh.ai/mcp/setup.php'
        );
    }
    return $tokens;
}

/**
 * Save updated tokens back to the per-user token file.
 */
function saveTokens(array $tokens): void {
    $userId    = $GLOBALS['mcp_user_id'] ?? '';
    $tokenFile = DATA_DIR . '/tokens_' . $userId . '.json';
    file_put_contents($tokenFile, json_encode($tokens, JSON_PRETTY_PRINT));
}

/**
 * Refresh the access token using the stored refresh token.
 * Updates tokens.json in place.
 */
function refreshAccessToken(array &$tokens): bool {
    $ch = curl_init(MCP_GOOGLE_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => MCP_GOOGLE_CLIENT_ID,
            'client_secret' => MCP_GOOGLE_CLIENT_SECRET,
            'refresh_token' => $tokens['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!empty($response['access_token'])) {
        $tokens['access_token'] = $response['access_token'];
        $tokens['token_expiry'] = time() + ($response['expires_in'] ?? 3600);
        saveTokens($tokens);
        return true;
    }
    return false;
}

/**
 * Make an authenticated Google Tasks API request.
 * Handles auto-refresh of expired access tokens.
 */
function googleRequest(string $endpoint, string $method = 'GET', array $body = null): array {
    try {
        $tokens = loadTokens();
    } catch (RuntimeException $e) {
        return ['error' => $e->getMessage()];
    }

    // Refresh if expired (or within 60 seconds of expiry)
    if (empty($tokens['access_token']) ||
        (!empty($tokens['token_expiry']) && time() >= $tokens['token_expiry'] - 60)) {
        if (!refreshAccessToken($tokens)) {
            return ['error' => 'Access token expired and refresh failed. Re-run setup at /mcp/setup.php'];
        }
    }

    return doRequest(MCP_GOOGLE_TASKS_BASE . $endpoint, $method, $tokens['access_token'], $body);
}

/**
 * Low-level cURL request to Google Tasks API.
 * On 401, tries one automatic token refresh.
 */
function doRequest(string $url, string $method, string $accessToken, array $body = null, bool $retried = false): array {
    $ch = curl_init($url);

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    ];

    if ($body !== null) {
        $json = json_encode($body);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($json);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) return ['error' => 'Network error contacting Google Tasks API'];

    // 204 No Content (e.g. DELETE)
    if ($httpCode === 204) return ['success' => true];

    $decoded = json_decode($response, true) ?? ['error' => 'Invalid JSON response from Google'];

    // On 401, try refreshing once and retrying
    if ($httpCode === 401 && !$retried) {
        try {
            $tokens = loadTokens();
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
        if (refreshAccessToken($tokens)) {
            return doRequest($url, $method, $tokens['access_token'], $body, true);
        }
        return ['error' => 'Google API returned 401 and token refresh failed'];
    }

    return $decoded;
}
