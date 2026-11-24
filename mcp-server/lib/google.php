<?php
/**
 * Google Tasks API client + TaskStick prefs (stars / snooze) for the MCP server.
 *
 * Runs without a PHP session: the acting user comes from the OAuth access
 * token (mcpSetUser()), and their Google refresh token from
 * data/tokens_{userId}.json (written when they approved the connection).
 *
 * Stars and Snooze are TaskStick features, not Google ones — the Google Tasks
 * API has no "starred" field. They live in data/prefs_{userId}.json, the same
 * file the web app syncs, so changes made here show up in the app's Priority
 * card and snoozed sections on its next load.
 */

require_once __DIR__ . '/oauth.php';

$GLOBALS['mcp_user'] = null;

function mcpSetUser(array $tokenRecord): void {
    $GLOBALS['mcp_user'] = [
        'userId' => $tokenRecord['userId'],
        'email'  => $tokenRecord['email'] ?? '',
        'scope'  => $tokenRecord['scope'] ?? [],
        'client' => $tokenRecord['clientName'] ?? '',
    ];
}

function mcpUser(): array {
    if (!$GLOBALS['mcp_user']) throw new RuntimeException('No authenticated user.');
    return $GLOBALS['mcp_user'];
}

class McpToolError extends RuntimeException {}

// ═════════════════════════════════════════════════════════════════════════
// Google Tasks HTTP
// ═════════════════════════════════════════════════════════════════════════

function mcpLoadGoogleTokens(): array {
    $f = mcpGoogleTokenFile(mcpUser()['userId']);
    $t = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!$t || empty($t['refresh_token'])) {
        throw new McpToolError('TaskStick has lost access to this Google account. Disconnect and reconnect TaskStick in your AI app (or open ' . MCP_SETUP_URL . ').');
    }
    return $t;
}

function mcpRefreshGoogle(array &$t): bool {
    $ch = curl_init(MCP_GOOGLE_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => MCP_GOOGLE_CLIENT_ID,
            'client_secret' => MCP_GOOGLE_CLIENT_SECRET,
            'refresh_token' => $t['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $r = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($r['access_token'])) return false;
    $t['access_token'] = $r['access_token'];
    $t['token_expiry'] = time() + (int)($r['expires_in'] ?? 3600);
    file_put_contents(mcpGoogleTokenFile(mcpUser()['userId']), json_encode($t, JSON_PRETTY_PRINT), LOCK_EX);
    return true;
}

/**
 * Call the Google Tasks API. Returns decoded JSON (or [] for 204).
 * Throws McpToolError with a readable message on failure.
 */
function mcpGoogle(string $path, string $method = 'GET', ?array $body = null, array $query = []): array {
    static $tokens = null;
    if ($tokens === null) $tokens = mcpLoadGoogleTokens();
    if (empty($tokens['access_token']) || time() >= (int)($tokens['token_expiry'] ?? 0) - 60) {
        if (!mcpRefreshGoogle($tokens)) {
            throw new McpToolError('Google refused to renew access (the account may have revoked TaskStick). Reconnect TaskStick in your AI app.');
        }
    }

    $url = MCP_GOOGLE_TASKS_BASE . $path . ($query ? '?' . http_build_query($query) : '');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init($url);
        $headers = ['Authorization: Bearer ' . $tokens['access_token'], 'Accept: application/json'];
        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) throw new McpToolError('Could not reach Google Tasks. Try again in a moment.');
        if ($code === 401 && $attempt === 0 && mcpRefreshGoogle($tokens)) continue;
        if ($code === 204 || $resp === '') return [];
        $data = json_decode($resp, true) ?? [];
        if ($code >= 400) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $code);
            if ($code === 404) throw new McpToolError("Not found in Google Tasks ($msg). Check the list and task IDs — use tasks_list_tasklists / tasks_list_tasks to look them up.");
            if ($code === 400) throw new McpToolError("Google Tasks rejected the request: $msg");
            if ($code === 403) throw new McpToolError("Google Tasks denied access: $msg");
            if ($code === 429) throw new McpToolError('Google Tasks rate limit reached. Wait a few seconds and try again.');
            throw new McpToolError("Google Tasks error: $msg");
        }
        return $data;
    }
    throw new McpToolError('Google access token was rejected. Reconnect TaskStick.');
}

/** All task lists (cached per request). */
function mcpLists(bool $fresh = false): array {
    static $lists = null;
    if ($lists === null || $fresh) {
        $out = []; $page = null;
        do {
            $q = ['maxResults' => 100];
            if ($page) $q['pageToken'] = $page;
            $r = mcpGoogle('/users/@me/lists', 'GET', null, $q);
            foreach ($r['items'] ?? [] as $l) $out[] = $l;
            $page = $r['nextPageToken'] ?? null;
        } while ($page && count($out) < 500);
        $lists = $out;
    }
    return $lists;
}

/** Accept a list ID or an exact (case-insensitive) list title. Returns the list. */
function mcpResolveList(string $idOrTitle): array {
    $idOrTitle = trim($idOrTitle);
    if ($idOrTitle === '') throw new McpToolError('listId is required. Call tasks_list_tasklists to get list IDs.');
    $lists = mcpLists();
    foreach ($lists as $l) if (($l['id'] ?? '') === $idOrTitle) return $l;
    foreach ($lists as $l) if (mb_strtolower($l['title'] ?? '') === mb_strtolower($idOrTitle)) return $l;
    $names = implode(', ', array_map(fn($l) => '"' . ($l['title'] ?? '') . '"', array_slice($lists, 0, 20)));
    throw new McpToolError("No task list matches \"$idOrTitle\". Your lists: $names. Pass a list ID from tasks_list_tasklists.");
}

/** Every task in a list (follows pagination). */
function mcpListAllTasks(string $listId, bool $includeCompleted): array {
    $out = []; $page = null;
    do {
        $q = ['maxResults' => 100, 'showCompleted' => $includeCompleted ? 'true' : 'false', 'showHidden' => $includeCompleted ? 'true' : 'false'];
        if ($page) $q['pageToken'] = $page;
        $r = mcpGoogle('/lists/' . rawurlencode($listId) . '/tasks', 'GET', null, $q);
        foreach ($r['items'] ?? [] as $t) $out[] = $t;
        $page = $r['nextPageToken'] ?? null;
    } while ($page && count($out) < 1000);
    // Display order: parents by position, each followed by its subtasks.
    usort($out, fn($a, $b) => strcmp($a['position'] ?? '', $b['position'] ?? ''));
    $roots = []; $kids = [];
    foreach ($out as $t) {
        if (!empty($t['parent'])) $kids[$t['parent']][] = $t; else $roots[] = $t;
    }
    $ordered = [];
    foreach ($roots as $r) {
        $ordered[] = $r;
        foreach ($kids[$r['id']] ?? [] as $k) $ordered[] = $k;
        unset($kids[$r['id']]);
    }
    foreach ($kids as $orphans) foreach ($orphans as $k) $ordered[] = $k;   // parent completed/hidden
    return $ordered;
}

function mcpGetTask(string $listId, string $taskId): array {
    if (trim($taskId) === '') throw new McpToolError('taskId is required.');
    return mcpGoogle('/lists/' . rawurlencode($listId) . '/tasks/' . rawurlencode($taskId));
}

/** "2026-12-01" or RFC 3339 → Google's RFC 3339 (date-only semantics). null clears. */
function mcpNormalizeDue($due): ?string {
    if ($due === null || $due === '') return null;
    $due = trim((string)$due);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) return $due . 'T00:00:00.000Z';
    // Google Tasks only stores the calendar date, so keep the date the caller wrote.
    if (preg_match('/^(\d{4}-\d{2}-\d{2})T/', $due, $m)) return $m[1] . 'T00:00:00.000Z';
    $ts = strtotime($due);
    if ($ts === false) throw new McpToolError("Couldn't read due date \"$due\". Use YYYY-MM-DD, e.g. 2026-12-01.");
    return date('Y-m-d', $ts) . 'T00:00:00.000Z';
}

// ═════════════════════════════════════════════════════════════════════════
// TaskStick prefs (stars + snooze)
// ═════════════════════════════════════════════════════════════════════════

const MCP_PREFS_MAP_FIELDS = ['stars', 'snoozed', 'collapsed', 'taskCollapsed', 'snoozedCollapsed', 'listColors', 'settings'];

function mcpPrefsFile(): string {
    return DATA_DIR . '/prefs_' . mcpSafeUserId(mcpUser()['userId']) . '.json';
}

function mcpPrefs(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $f = mcpPrefsFile();
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    $cache = is_array($d) ? $d : [];
    $cache['stars']   = is_array($cache['stars'] ?? null) ? $cache['stars'] : [];
    $cache['snoozed'] = is_array($cache['snoozed'] ?? null) ? $cache['snoozed'] : [];
    return $cache;
}

/**
 * Locked read-modify-write of the prefs file, preserving every field the web
 * app owns and keeping map fields as JSON objects (never []), which the app
 * depends on (see api/prefs.php asMapObj()).
 */
function mcpUpdatePrefs(callable $mutator): void {
    $f = mcpPrefsFile();
    mcpEnsureDir(dirname($f));
    $fp = fopen($f, 'c+');
    if (!$fp) throw new McpToolError('Could not save TaskStick preferences.');
    flock($fp, LOCK_EX);
    try {
        $d = json_decode((string)stream_get_contents($fp), true);
        if (!is_array($d)) $d = [];
        foreach (['stars', 'snoozed'] as $k) if (!is_array($d[$k] ?? null)) $d[$k] = [];
        $d = $mutator($d);
        foreach (MCP_PREFS_MAP_FIELDS as $k) {
            if (array_key_exists($k, $d) && (is_array($d[$k]) && !$d[$k])) $d[$k] = new stdClass();
        }
        if (!isset($d['listOrder'])) $d['listOrder'] = [];
        $d['savedAt'] = date('c');
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function mcpSetStar(string $taskId, bool $on): void {
    mcpUpdatePrefs(function ($d) use ($taskId, $on) {
        if ($on) $d['stars'][$taskId] = true; else unset($d['stars'][$taskId]);
        return $d;
    });
}

function mcpSetSnooze(string $rootTaskId, bool $on): void {
    mcpUpdatePrefs(function ($d) use ($rootTaskId, $on) {
        if ($on) $d['snoozed'][$rootTaskId] = true; else unset($d['snoozed'][$rootTaskId]);
        return $d;
    });
}

function mcpForgetTask(string $taskId): void {
    mcpUpdatePrefs(function ($d) use ($taskId) {
        unset($d['stars'][$taskId], $d['snoozed'][$taskId]);
        return $d;
    });
}

// ═════════════════════════════════════════════════════════════════════════
// Normalized task shape (structuredContent)
// ═════════════════════════════════════════════════════════════════════════

/** Re-read prefs from disk (after a write in this same request). */
function mcpFreshPrefs(): array {
    $f = mcpPrefsFile();
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    return ['stars' => (array)($d['stars'] ?? []), 'snoozed' => (array)($d['snoozed'] ?? [])];
}

function mcpTaskOut(array $t, string $listId, ?string $listTitle = null, ?array $prefs = null): array {
    $prefs = $prefs ?? mcpPrefs();
    $id = $t['id'] ?? '';
    $parent = $t['parent'] ?? null;
    $root = $parent ?: $id;
    $done = ($t['status'] ?? '') === 'completed';
    $out = [
        'id'        => $id,
        'listId'    => $listId,
        'title'     => $t['title'] ?? '',
        'notes'     => $t['notes'] ?? null,
        'status'    => $done ? 'completed' : 'needsAction',
        'due'       => !empty($t['due']) ? substr($t['due'], 0, 10) : null,
        'completed' => !empty($t['completed']) ? $t['completed'] : null,
        'parentId'  => $parent,
        'position'  => $t['position'] ?? null,
        'starred'   => !empty($prefs['stars'][$id]),
        'snoozed'   => !$done && !empty($prefs['snoozed'][$root]),
        'updated'   => $t['updated'] ?? null,
    ];
    if ($listTitle !== null) $out['listTitle'] = $listTitle;
    if (!empty($t['webViewLink'])) $out['webViewLink'] = $t['webViewLink'];
    return $out;
}

/** One-line Markdown for a normalized task (text content for the model). */
function mcpTaskLine(array $t, bool $withList = false): string {
    $flags = '';
    if ($t['starred']) $flags .= ' ⭐';
    if ($t['snoozed']) $flags .= ' 💤';
    if ($t['due'])     $flags .= ' 📅 ' . $t['due'];
    if ($t['notes'])   $flags .= ' 📝';
    $box = $t['status'] === 'completed' ? '[x]' : '[ ]';
    $indent = $t['parentId'] ? '  ' : '';
    $list = $withList && !empty($t['listTitle']) ? ' — ' . $t['listTitle'] : '';
    return sprintf('%s- %s **%s**%s%s (id: `%s`)', $indent, $box, $t['title'] !== '' ? $t['title'] : 'Untitled', $flags, $list, $t['id']);
}
