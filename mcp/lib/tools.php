<?php
/**
 * TaskStick MCP tools: definitions (tools/list) and handlers (tools/call).
 *
 * Every tool returns both a readable Markdown summary (content) and machine-
 * readable data (structuredContent) matching its outputSchema.
 * Write tools are only listed/allowed when the token has the tasks.write scope.
 */

require_once __DIR__ . '/google.php';

// ═════════════════════════════════════════════════════════════════════════
// Schemas
// ═════════════════════════════════════════════════════════════════════════

function mcpTaskSchema(): array {
    $ns = ['type' => ['string', 'null']];
    return [
        'type' => 'object',
        'properties' => [
            'id'          => ['type' => 'string'],
            'listId'      => ['type' => 'string'],
            'listTitle'   => ['type' => 'string'],
            'title'       => ['type' => 'string'],
            'notes'       => $ns,
            'status'      => ['type' => 'string', 'enum' => ['needsAction', 'completed']],
            'due'         => $ns + ['description' => 'YYYY-MM-DD'],
            'completed'   => $ns,
            'parentId'    => $ns,
            'position'    => $ns,
            'starred'     => ['type' => 'boolean', 'description' => 'In TaskStick\'s Priority card'],
            'snoozed'     => ['type' => 'boolean', 'description' => 'Set aside in TaskStick without completing'],
            'updated'     => $ns,
            'webViewLink' => ['type' => 'string'],
        ],
        'required' => ['id', 'listId', 'title', 'status', 'starred', 'snoozed'],
    ];
}

function mcpListSchema(): array {
    return [
        'type' => 'object',
        'properties' => ['id' => ['type' => 'string'], 'title' => ['type' => 'string'], 'updated' => ['type' => ['string', 'null']]],
        'required' => ['id', 'title'],
    ];
}

function mcpObj(array $props, array $required = []): array {
    $s = ['type' => 'object', 'properties' => $props ?: new stdClass(), 'additionalProperties' => false];
    if ($required) $s['required'] = $required;
    return $s;
}

const MCP_P_LIST = ['type' => 'string', 'description' => 'Task list ID from tasks_list_tasklists (an exact list title also works).'];
const MCP_P_TASK = ['type' => 'string', 'description' => 'Task ID from tasks_list_tasks or tasks_search.'];

// ═════════════════════════════════════════════════════════════════════════
// Definitions
// ═════════════════════════════════════════════════════════════════════════

/** All tools, in a fixed order (deterministic tools/list). */
function mcpToolCatalog(): array {
    $task = mcpTaskSchema();
    $taskOut = mcpObj(['task' => $task], ['task']);
    $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    $w  = fn(bool $idem = false, bool $destr = false) => ['readOnlyHint' => false, 'destructiveHint' => $destr, 'idempotentHint' => $idem, 'openWorldHint' => false];

    return [
        // ── Read ──────────────────────────────────────────────────────────
        [
            'name' => 'tasks_list_tasklists', 'title' => 'List task lists', 'write' => false,
            'description' => 'List all of the user\'s Google Task lists with their IDs. Start here to find a listId.',
            'inputSchema' => mcpObj([]),
            'outputSchema' => mcpObj(['lists' => ['type' => 'array', 'items' => mcpListSchema()]], ['lists']),
            'annotations' => $ro + ['title' => 'List task lists'],
            'handler' => 'mcpToolListTasklists',
        ],
        [
            'name' => 'tasks_list_tasks', 'title' => 'List tasks in a list', 'write' => false,
            'description' => 'List the tasks in one list, in display order with subtasks under their parent. Shows title, due date, notes indicator, starred (Priority) and snoozed status, and IDs.',
            'inputSchema' => mcpObj([
                'listId'           => MCP_P_LIST,
                'includeCompleted' => ['type' => 'boolean', 'description' => 'Include completed tasks. Default false.'],
                'includeSnoozed'   => ['type' => 'boolean', 'description' => 'Include snoozed tasks. Default true.'],
            ], ['listId']),
            'outputSchema' => mcpObj([
                'list'   => mcpListSchema(),
                'tasks'  => ['type' => 'array', 'items' => $task],
                'counts' => mcpObj(['active' => ['type' => 'integer'], 'snoozed' => ['type' => 'integer'], 'completed' => ['type' => 'integer']]),
            ], ['list', 'tasks']),
            'annotations' => $ro + ['title' => 'List tasks in a list'],
            'handler' => 'mcpToolListTasks',
        ],
        [
            'name' => 'tasks_get_task', 'title' => 'Get a task', 'write' => false,
            'description' => 'Get one task in full, including its complete notes (which may contain earlier AI notes).',
            'inputSchema' => mcpObj(['listId' => MCP_P_LIST, 'taskId' => MCP_P_TASK], ['listId', 'taskId']),
            'outputSchema' => $taskOut,
            'annotations' => $ro + ['title' => 'Get a task'],
            'handler' => 'mcpToolGetTask',
        ],
        [
            'name' => 'tasks_search', 'title' => 'Search tasks', 'write' => false,
            'description' => 'Search task titles and notes across all lists (or one list). Case-insensitive; every word must match. Use when you don\'t know which list a task is in.',
            'inputSchema' => mcpObj([
                'query'            => ['type' => 'string', 'description' => 'Words to look for, e.g. "dentist" or "quarterly report".'],
                'listId'           => ['type' => 'string', 'description' => 'Optional: only search this list.'],
                'includeCompleted' => ['type' => 'boolean', 'description' => 'Also search completed tasks. Default false.'],
                'limit'            => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Max results. Default 25.'],
            ], ['query']),
            'outputSchema' => mcpObj([
                'query'     => ['type' => 'string'],
                'matches'   => ['type' => 'array', 'items' => $task],
                'truncated' => ['type' => 'boolean'],
            ], ['query', 'matches']),
            'annotations' => $ro + ['title' => 'Search tasks'],
            'handler' => 'mcpToolSearch',
        ],
        [
            'name' => 'tasks_list_priority', 'title' => 'List priority tasks', 'write' => false,
            'description' => 'List the user\'s Priority tasks — every starred, not-completed task across all lists (TaskStick\'s Priority card). Good for "what should I focus on?".',
            'inputSchema' => mcpObj([
                'includeSnoozed' => ['type' => 'boolean', 'description' => 'Include starred tasks that are snoozed. Default false.'],
            ]),
            'outputSchema' => mcpObj(['tasks' => ['type' => 'array', 'items' => $task]], ['tasks']),
            'annotations' => $ro + ['title' => 'List priority tasks'],
            'handler' => 'mcpToolPriority',
        ],
        [
            'name' => 'tasks_open_board', 'title' => 'Open task board', 'write' => false,
            'description' => 'Show the user an interactive TaskStick board (all lists or one list) they can tick off, star and snooze tasks in directly. Use when the user wants to see or work through their tasks visually. Also returns a text summary.',
            'inputSchema' => mcpObj([
                'listId' => ['type' => 'string', 'description' => 'Optional: open just this list. Omit for all lists.'],
            ]),
            'outputSchema' => mcpObj([
                'lists' => ['type' => 'array', 'items' => mcpObj([
                    'id' => ['type' => 'string'], 'title' => ['type' => 'string'],
                    'tasks' => ['type' => 'array', 'items' => $task],
                ], ['id', 'title', 'tasks'])],
                'canWrite'    => ['type' => 'boolean'],
                'generatedAt' => ['type' => 'string'],
            ], ['lists', 'canWrite', 'generatedAt']),
            'annotations' => $ro + ['title' => 'Open task board'],
            '_meta' => ['ui' => ['resourceUri' => MCP_UI_BOARD_URI, 'visibility' => ['model', 'app']]],
            'handler' => 'mcpToolOpenBoard',
        ],

        // ── Write ─────────────────────────────────────────────────────────
        [
            'name' => 'tasks_create_task', 'title' => 'Create a task', 'write' => true,
            'description' => 'Create a task in a list. Optionally make it a subtask (parentId), place it after another task (previousId), give it a due date, or star it for the Priority card.',
            'inputSchema' => mcpObj([
                'listId'     => MCP_P_LIST,
                'title'      => ['type' => 'string', 'description' => 'Task title.'],
                'notes'      => ['type' => 'string', 'description' => 'Optional notes / description.'],
                'due'        => ['type' => 'string', 'description' => 'Optional due date, YYYY-MM-DD (Google Tasks stores the date only).'],
                'parentId'   => ['type' => 'string', 'description' => 'Optional: ID of a task to nest this under as a subtask.'],
                'previousId' => ['type' => 'string', 'description' => 'Optional: ID of the sibling task this should come after. Omit to add at the top.'],
                'starred'    => ['type' => 'boolean', 'description' => 'Optional: star it (adds to Priority). Default false.'],
            ], ['listId', 'title']),
            'outputSchema' => $taskOut,
            'annotations' => $w() + ['title' => 'Create a task'],
            'handler' => 'mcpToolCreateTask',
        ],
        [
            'name' => 'tasks_update_task', 'title' => 'Update a task', 'write' => true,
            'description' => 'Change fields on a task; only the fields you pass change. To append to notes without losing them, use tasks_add_ai_note instead of replacing notes.',
            'inputSchema' => mcpObj([
                'listId'  => MCP_P_LIST,
                'taskId'  => MCP_P_TASK,
                'title'   => ['type' => 'string'],
                'notes'   => ['type' => 'string', 'description' => 'Replaces the entire notes field.'],
                'status'  => ['type' => 'string', 'enum' => ['needsAction', 'completed']],
                'due'     => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD, or null to clear.'],
                'starred' => ['type' => 'boolean'],
                'snoozed' => ['type' => 'boolean'],
            ], ['listId', 'taskId']),
            'outputSchema' => $taskOut,
            'annotations' => $w(true) + ['title' => 'Update a task'],
            'handler' => 'mcpToolUpdateTask',
        ],
        [
            'name' => 'tasks_complete_task', 'title' => 'Complete a task', 'write' => true,
            'description' => 'Mark a task completed (or pass completed=false to reopen it).',
            'inputSchema' => mcpObj([
                'listId' => MCP_P_LIST, 'taskId' => MCP_P_TASK,
                'completed' => ['type' => 'boolean', 'description' => 'Default true. false reopens the task.'],
            ], ['listId', 'taskId']),
            'outputSchema' => $taskOut,
            'annotations' => $w(true) + ['title' => 'Complete a task'],
            'handler' => 'mcpToolCompleteTask',
        ],
        [
            'name' => 'tasks_star_task', 'title' => 'Star a task', 'write' => true,
            'description' => 'Star or unstar a task. Starred tasks appear in TaskStick\'s Priority card and are shown in bold.',
            'inputSchema' => mcpObj(['listId' => MCP_P_LIST, 'taskId' => MCP_P_TASK, 'starred' => ['type' => 'boolean', 'description' => 'true to star, false to unstar.']], ['listId', 'taskId', 'starred']),
            'outputSchema' => $taskOut,
            'annotations' => $w(true) + ['title' => 'Star a task'],
            'handler' => 'mcpToolStarTask',
        ],
        [
            'name' => 'tasks_snooze_task', 'title' => 'Snooze a task', 'write' => true,
            'description' => 'Snooze (set aside without completing) or unsnooze a task. Snoozed tasks stay in their list, greyed out below active ones, and drop out of the Priority card. Snoozing a subtask snoozes its whole parent task family.',
            'inputSchema' => mcpObj(['listId' => MCP_P_LIST, 'taskId' => MCP_P_TASK, 'snoozed' => ['type' => 'boolean', 'description' => 'true to snooze, false to bring back.']], ['listId', 'taskId', 'snoozed']),
            'outputSchema' => $taskOut,
            'annotations' => $w(true) + ['title' => 'Snooze a task'],
            'handler' => 'mcpToolSnoozeTask',
        ],
        [
            'name' => 'tasks_add_ai_note', 'title' => 'Add an AI note', 'write' => true,
            'description' => 'Append a timestamped AI note below a task\'s existing notes — e.g. how you could help, relevant context, or the next step. Keep it short and actionable.',
            'inputSchema' => mcpObj(['listId' => MCP_P_LIST, 'taskId' => MCP_P_TASK, 'note' => ['type' => 'string', 'description' => 'The note to append.']], ['listId', 'taskId', 'note']),
            'outputSchema' => $taskOut,
            'annotations' => $w() + ['title' => 'Add an AI note'],
            'handler' => 'mcpToolAddAiNote',
        ],
        [
            'name' => 'tasks_move_task', 'title' => 'Move a task', 'write' => true,
            'description' => 'Reorder a task, nest it under another task (subtask), un-nest it, or move it to a different list.',
            'inputSchema' => mcpObj([
                'listId'            => MCP_P_LIST,
                'taskId'            => MCP_P_TASK,
                'parentId'          => ['type' => 'string', 'description' => 'Make it a subtask of this task. Omit (or empty) to make it top-level.'],
                'previousId'        => ['type' => 'string', 'description' => 'Put it after this sibling. Omit to move to the top.'],
                'destinationListId' => ['type' => 'string', 'description' => 'Move it to this list instead (ID or exact title).'],
            ], ['listId', 'taskId']),
            'outputSchema' => $taskOut,
            'annotations' => $w() + ['title' => 'Move a task'],
            'handler' => 'mcpToolMoveTask',
        ],
        [
            'name' => 'tasks_delete_task', 'title' => 'Delete a task', 'write' => true,
            'description' => 'Permanently delete a task (and its subtasks) from Google Tasks. Prefer tasks_complete_task unless the user asked to delete. Confirm with the user first.',
            'inputSchema' => mcpObj(['listId' => MCP_P_LIST, 'taskId' => MCP_P_TASK], ['listId', 'taskId']),
            'outputSchema' => mcpObj(['deleted' => ['type' => 'boolean'], 'taskId' => ['type' => 'string'], 'title' => ['type' => 'string']], ['deleted', 'taskId']),
            'annotations' => $w(true, true) + ['title' => 'Delete a task'],
            'handler' => 'mcpToolDeleteTask',
        ],
        [
            'name' => 'tasks_create_tasklist', 'title' => 'Create a list', 'write' => true,
            'description' => 'Create a new task list.',
            'inputSchema' => mcpObj(['title' => ['type' => 'string', 'description' => 'Name of the new list.']], ['title']),
            'outputSchema' => mcpObj(['list' => mcpListSchema()], ['list']),
            'annotations' => $w() + ['title' => 'Create a list'],
            'handler' => 'mcpToolCreateTasklist',
        ],
        [
            'name' => 'tasks_rename_tasklist', 'title' => 'Rename a list', 'write' => true,
            'description' => 'Rename an existing task list.',
            'inputSchema' => mcpObj(['listId' => MCP_P_LIST, 'title' => ['type' => 'string', 'description' => 'New name.']], ['listId', 'title']),
            'outputSchema' => mcpObj(['list' => mcpListSchema()], ['list']),
            'annotations' => $w(true) + ['title' => 'Rename a list'],
            'handler' => 'mcpToolRenameTasklist',
        ],
    ];
}

/** tools/list entries visible to this token (strips internal keys). */
function mcpToolsForScope(array $scope): array {
    $canWrite = in_array('tasks.write', $scope, true);
    $out = [];
    foreach (mcpToolCatalog() as $t) {
        if ($t['write'] && !$canWrite) continue;
        unset($t['write'], $t['handler']);
        $out[] = $t;
    }
    return $out;
}

function mcpFindTool(string $name): ?array {
    foreach (mcpToolCatalog() as $t) if ($t['name'] === $name) return $t;
    return null;
}

// ═════════════════════════════════════════════════════════════════════════
// Result helpers
// ═════════════════════════════════════════════════════════════════════════

function mcpToolResult(string $text, $structured): array {
    return ['content' => [['type' => 'text', 'text' => $text]], 'structuredContent' => $structured, 'isError' => false];
}

function mcpToolError(string $message): array {
    return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
}

function mcpArg(array $a, string $key, ...$aliases) {
    foreach (array_merge([$key], $aliases) as $k) if (array_key_exists($k, $a)) return $a[$k];
    return null;
}

function mcpStr(array $a, string $key, ...$aliases): string {
    $v = mcpArg($a, $key, ...$aliases);
    return is_scalar($v) ? trim((string)$v) : '';
}

function mcpBool(array $a, string $key, bool $default): bool {
    $v = $a[$key] ?? null;
    if ($v === null) return $default;
    if (is_string($v)) return in_array(strtolower($v), ['1', 'true', 'yes'], true);
    return (bool)$v;
}

/** Re-read a task and return it normalized, with fresh prefs. */
function mcpTaskResult(string $verb, string $listId, string $taskId, ?array $raw = null, ?string $extra = null): array {
    $raw = $raw ?? mcpGetTask($listId, $taskId);
    $t = mcpTaskOut($raw, $listId, null, mcpFreshPrefs());
    $text = $verb . ":\n" . mcpTaskLine($t) . ($extra ? "\n\n" . $extra : '');
    return mcpToolResult($text, ['task' => $t]);
}

// ═════════════════════════════════════════════════════════════════════════
// Read handlers
// ═════════════════════════════════════════════════════════════════════════

function mcpToolListTasklists(array $a): array {
    $lists = array_map(fn($l) => ['id' => $l['id'], 'title' => $l['title'] ?? '', 'updated' => $l['updated'] ?? null], mcpLists());
    if (!$lists) return mcpToolResult('No task lists found.', ['lists' => []]);
    $lines = array_map(fn($l) => sprintf('- **%s** (id: `%s`)', $l['title'], $l['id']), $lists);
    return mcpToolResult("Task lists:\n" . implode("\n", $lines), ['lists' => $lists]);
}

function mcpToolListTasks(array $a): array {
    $list = mcpResolveList(mcpStr($a, 'listId'));
    $withDone = mcpBool($a, 'includeCompleted', false);
    $withSnoozed = mcpBool($a, 'includeSnoozed', true);
    $tasks = []; $counts = ['active' => 0, 'snoozed' => 0, 'completed' => 0];
    foreach (mcpListAllTasks($list['id'], $withDone) as $raw) {
        $t = mcpTaskOut($raw, $list['id']);
        if ($t['status'] === 'completed') $counts['completed']++;
        elseif ($t['snoozed']) $counts['snoozed']++;
        else $counts['active']++;
        if ($t['snoozed'] && !$withSnoozed) continue;
        $tasks[] = $t;
    }
    $L = ['id' => $list['id'], 'title' => $list['title'] ?? '', 'updated' => $list['updated'] ?? null];
    if (!$tasks) return mcpToolResult("No tasks in **{$L['title']}**.", ['list' => $L, 'tasks' => [], 'counts' => $counts]);
    // Active first, then snoozed, then completed — like the app.
    $rank = fn($t) => $t['status'] === 'completed' ? 2 : ($t['snoozed'] ? 1 : 0);
    $groups = [[], [], []];
    foreach ($tasks as $t) $groups[$rank($t)][] = $t;
    $lines = ["**{$L['title']}** (id: `{$L['id']}`) — {$counts['active']} active, {$counts['snoozed']} snoozed" . ($withDone ? ", {$counts['completed']} completed" : '')];
    foreach ($groups as $i => $g) {
        if (!$g) continue;
        if ($i === 1) $lines[] = "\n_Snoozed:_";
        if ($i === 2) $lines[] = "\n_Completed:_";
        foreach ($g as $t) $lines[] = mcpTaskLine($t);
    }
    return mcpToolResult(implode("\n", $lines), ['list' => $L, 'tasks' => $tasks, 'counts' => $counts]);
}

function mcpToolGetTask(array $a): array {
    $list = mcpResolveList(mcpStr($a, 'listId'));
    $t = mcpTaskOut(mcpGetTask($list['id'], mcpStr($a, 'taskId')), $list['id'], $list['title'] ?? '');
    $lines = [
        '**' . ($t['title'] ?: 'Untitled') . '**',
        'Status: ' . ($t['status'] === 'completed' ? 'completed ✅' : 'open') . ($t['snoozed'] ? ' · snoozed 💤' : '') . ($t['starred'] ? ' · starred ⭐' : ''),
        'Due: ' . ($t['due'] ?? 'none'),
        'List: ' . ($list['title'] ?? '') . " (`{$list['id']}`)",
        'Parent: ' . ($t['parentId'] ? "`{$t['parentId']}`" : 'none (top-level)'),
        "Task ID: `{$t['id']}`",
        '', 'Notes:', $t['notes'] ?: '_(none)_',
    ];
    return mcpToolResult(implode("\n", $lines), ['task' => $t]);
}

function mcpToolSearch(array $a): array {
    $query = mcpStr($a, 'query');
    if ($query === '') throw new McpToolError('query is required.');
    $words = preg_split('/\s+/', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
    $limit = max(1, min(100, (int)($a['limit'] ?? 25)));
    $withDone = mcpBool($a, 'includeCompleted', false);
    $lists = mcpStr($a, 'listId') !== '' ? [mcpResolveList(mcpStr($a, 'listId'))] : mcpLists();

    $matches = []; $truncated = false;
    foreach ($lists as $l) {
        foreach (mcpListAllTasks($l['id'], $withDone) as $raw) {
            $hay = mb_strtolower(($raw['title'] ?? '') . "\n" . ($raw['notes'] ?? ''));
            foreach ($words as $w) if (!str_contains($hay, $w)) continue 2;
            if (count($matches) >= $limit) { $truncated = true; break 2; }
            $matches[] = mcpTaskOut($raw, $l['id'], $l['title'] ?? '');
        }
    }
    if (!$matches) return mcpToolResult("No tasks match \"$query\".", ['query' => $query, 'matches' => [], 'truncated' => false]);
    $text = count($matches) . " task(s) match \"$query\"" . ($truncated ? ' (more not shown — narrow the search)' : '') . ":\n"
          . implode("\n", array_map(fn($t) => mcpTaskLine(array_merge($t, ['parentId' => null]), true) . " · list `{$t['listId']}`", $matches));
    return mcpToolResult($text, ['query' => $query, 'matches' => $matches, 'truncated' => $truncated]);
}

function mcpToolPriority(array $a): array {
    $withSnoozed = mcpBool($a, 'includeSnoozed', false);
    $prefs = mcpPrefs();
    $out = [];
    if ($prefs['stars']) {
        foreach (mcpLists() as $l) {
            foreach (mcpListAllTasks($l['id'], false) as $raw) {
                if (empty($prefs['stars'][$raw['id']])) continue;
                $t = mcpTaskOut($raw, $l['id'], $l['title'] ?? '');
                if ($t['snoozed'] && !$withSnoozed) continue;
                $out[] = $t;
            }
        }
    }
    if (!$out) return mcpToolResult('No priority (starred) tasks right now.', ['tasks' => []]);
    $text = "Priority tasks (" . count($out) . "):\n"
          . implode("\n", array_map(fn($t) => mcpTaskLine(array_merge($t, ['parentId' => null]), true) . " · list `{$t['listId']}`", $out));
    return mcpToolResult($text, ['tasks' => $out]);
}

function mcpToolOpenBoard(array $a): array {
    $lists = mcpStr($a, 'listId') !== '' ? [mcpResolveList(mcpStr($a, 'listId'))] : mcpLists();
    $board = []; $lines = []; $total = 0;
    foreach ($lists as $l) {
        $tasks = array_map(fn($raw) => mcpTaskOut($raw, $l['id']), array_slice(mcpListAllTasks($l['id'], false), 0, 150));
        $board[] = ['id' => $l['id'], 'title' => $l['title'] ?? '', 'tasks' => $tasks];
        $active = count(array_filter($tasks, fn($t) => !$t['snoozed']));
        $stars  = count(array_filter($tasks, fn($t) => $t['starred'] && !$t['snoozed']));
        $total += $active;
        $lines[] = sprintf('- **%s**: %d active%s (id: `%s`)', $l['title'] ?? '', $active, $stars ? ", $stars starred" : '', $l['id']);
    }
    $text = "Opened the TaskStick board ($total active tasks):\n" . implode("\n", $lines)
          . "\n\nThe user can complete, star and snooze tasks directly in the board.";
    return mcpToolResult($text, [
        'lists' => $board,
        'canWrite' => in_array('tasks.write', mcpUser()['scope'], true),
        'generatedAt' => gmdate('c'),
    ]);
}

// ═════════════════════════════════════════════════════════════════════════
// Write handlers
// ═════════════════════════════════════════════════════════════════════════

function mcpToolCreateTask(array $a): array {
    $list = mcpResolveList(mcpStr($a, 'listId'));
    $title = mcpStr($a, 'title');
    if ($title === '') throw new McpToolError('title is required.');
    $body = ['title' => $title];
    if (mcpStr($a, 'notes') !== '') $body['notes'] = (string)$a['notes'];
    if (mcpStr($a, 'due') !== '') $body['due'] = mcpNormalizeDue($a['due']);
    $q = [];
    if (($p = mcpStr($a, 'parentId', 'parent')) !== '') $q['parent'] = $p;
    if (($p = mcpStr($a, 'previousId', 'previous')) !== '') $q['previous'] = $p;
    $created = mcpGoogle('/lists/' . rawurlencode($list['id']) . '/tasks', 'POST', $body, $q);
    if (mcpBool($a, 'starred', false)) mcpSetStar($created['id'], true);
    return mcpTaskResult('Created in **' . ($list['title'] ?? '') . '**', $list['id'], $created['id'], $created);
}

function mcpToolUpdateTask(array $a): array {
    $list = mcpResolveList(mcpStr($a, 'listId'));
    $taskId = mcpStr($a, 'taskId');
    $patch = [];
    if (array_key_exists('title', $a)) $patch['title'] = (string)$a['title'];
    if (array_key_exists('notes', $a)) $patch['notes'] = (string)$a['notes'];
    if (array_key_exists('status', $a)) {
        if (!in_array($a['status'], ['needsAction', 'completed'], true)) throw new McpToolError('status must be needsAction or completed.');
        $patch['status'] = $a['status'];
        if ($a['status'] === 'needsAction') $patch['completed'] = null;
    }
    if (array_key_exists('due', $a)) $patch['due'] = mcpNormalizeDue($a['due']);
    $hasPrefs = array_key_exists('starred', $a) || array_key_exists('snoozed', $a);
    if (!$patch && !$hasPrefs) throw new McpToolError('Nothing to update — pass at least one of title, notes, status, due, starred, snoozed.');

    $raw = $patch ? mcpGoogle('/lists/' . rawurlencode($list['id']) . '/tasks/' . rawurlencode($taskId), 'PATCH', $patch)
                  : mcpGetTask($list['id'], $taskId);
    if (array_key_exists('starred', $a)) mcpSetStar($raw['id'], mcpBool($a, 'starred', false));
    if (array_key_exists('snoozed', $a)) mcpSetSnooze($raw['parent'] ?? $raw['id'], mcpBool($a, 'snoozed', false));
    return mcpTaskResult('Updated', $list['id'], $raw['id'], $raw);
}

function mcpToolCompleteTask(array $a): array {
    $list = mcpResolveList(mcpStr($a, 'listId'));
    $done = mcpBool($a, 'completed', true);
    $patch = $done ? ['status' => 'completed'] : ['status' => 'needsAction', 'completed' => null];
    $raw = mcpGoogle('/lists/' . rawurlencode($list['id']) . '/tasks/' . rawurlencode(mcpStr($a, 'taskId')), 'PATCH', $patch);
    return mcpTaskResult($done ? 'Completed ✅' : 'Reopened', $list['id'], $raw['id'], $raw);
}

function mcpToolStarTask(array $a): array {
    $list = mcpResolveList(mcpStr($a, 'listId'));
    if (!array_key_exists('starred', $a)) throw new McpToolError('starred (true/false) is required.');
    $raw = mcpGetTask($list['id'], mcpStr($a, 'taskId'));
    $on = mcpBool($a, 'starred', true);
    mcpSetStar($raw['id'], $on);
    return mcpTaskResult($on ? 'Starred ⭐ (now in Priority)' : 'Unstarred', $list['id'], $raw['id'], $raw);
}

function mcpToolSnoozeTask(array $a): array {
    $list = mcpResolveList(mcpStr($a, 'listId'));
    if (!array_key_exists('snoozed', $a)) throw new McpToolError('snoozed (true/false) is required.');
    $raw = mcpGetTask($list['id'], mcpStr($a, 'taskId'));
    $on = mcpBool($a, 'snoozed', true);
    $root = $raw['parent'] ?? $raw['id'];
    mcpSetSnooze($root, $on);
    $extra = $root !== $raw['id'] ? 'This is a subtask, so its parent task family was ' . ($on ? 'snoozed' : 'unsnoozed') . " (parent `$root`)." : null;
    return mcpTaskResult($on ? 'Snoozed 💤' : 'Unsnoozed', $list['id'], $raw['id'], $raw, $extra);
}

function mcpToolAddAiNote(array $a): array {
    $list = mcpResolveList(mcpStr($a, 'listId'));
    $taskId = mcpStr($a, 'taskId');
    $note = mcpStr($a, 'note');
    if ($note === '') throw new McpToolError('note is required.');
    $task = mcpGetTask($list['id'], $taskId);
    $existing = trim($task['notes'] ?? '');
    $who = mcpUser()['client'] ? ' · ' . mcpUser()['client'] : '';
    $block = "---\n🤖 AI note (" . gmdate('Y-m-d H:i') . " UTC$who):\n$note";
    $raw = mcpGoogle('/lists/' . rawurlencode($list['id']) . '/tasks/' . rawurlencode($taskId), 'PATCH',
        ['notes' => $existing !== '' ? "$existing\n\n$block" : $block]);
    return mcpTaskResult('AI note added', $list['id'], $raw['id'], $raw);
}

function mcpToolMoveTask(array $a): array {
    $list = mcpResolveList(mcpStr($a, 'listId'));
    $taskId = mcpStr($a, 'taskId');
    $q = [];
    if (($p = mcpStr($a, 'parentId', 'parent')) !== '') $q['parent'] = $p;
    if (($p = mcpStr($a, 'previousId', 'previous')) !== '') $q['previous'] = $p;
    $destId = $list['id'];
    if (($d = mcpStr($a, 'destinationListId')) !== '') {
        $dest = mcpResolveList($d);
        if ($dest['id'] !== $list['id']) { $q['destinationTasklist'] = $dest['id']; $destId = $dest['id']; }
    }
    $raw = mcpGoogle('/lists/' . rawurlencode($list['id']) . '/tasks/' . rawurlencode($taskId) . '/move', 'POST', null, $q);
    return mcpTaskResult($destId !== $list['id'] ? 'Moved to another list' : 'Moved', $destId, $raw['id'] ?? $taskId, $raw ?: null);
}

function mcpToolDeleteTask(array $a): array {
    $list = mcpResolveList(mcpStr($a, 'listId'));
    $taskId = mcpStr($a, 'taskId');
    $task = mcpGetTask($list['id'], $taskId);
    mcpGoogle('/lists/' . rawurlencode($list['id']) . '/tasks/' . rawurlencode($taskId), 'DELETE');
    mcpForgetTask($taskId);
    $title = $task['title'] ?? '';
    return mcpToolResult("Deleted **$title** (id: `$taskId`).", ['deleted' => true, 'taskId' => $taskId, 'title' => $title]);
}

function mcpToolCreateTasklist(array $a): array {
    $title = mcpStr($a, 'title');
    if ($title === '') throw new McpToolError('title is required.');
    $l = mcpGoogle('/users/@me/lists', 'POST', ['title' => $title]);
    $L = ['id' => $l['id'], 'title' => $l['title'] ?? $title, 'updated' => $l['updated'] ?? null];
    return mcpToolResult("Created list **{$L['title']}** (id: `{$L['id']}`).", ['list' => $L]);
}

function mcpToolRenameTasklist(array $a): array {
    $list = mcpResolveList(mcpStr($a, 'listId'));
    $title = mcpStr($a, 'title');
    if ($title === '') throw new McpToolError('title is required.');
    $l = mcpGoogle('/users/@me/lists/' . rawurlencode($list['id']), 'PATCH', ['title' => $title]);
    $L = ['id' => $l['id'], 'title' => $l['title'] ?? $title, 'updated' => $l['updated'] ?? null];
    return mcpToolResult("Renamed **" . ($list['title'] ?? '') . "** to **{$L['title']}**.", ['list' => $L]);
}
