<?php
/**
 * TaskStick — Google Tasks MCP server (Streamable HTTP)
 * =====================================================
 * Endpoint: https://tasks.tesh.ai/mcp/ (also /mcp) — code lives in mcp-server/, routed by the root .htaccess
 * Auth:     OAuth 2.1 bearer token (discovered via /.well-known/oauth-protected-resource)
 *
 * Dual-era server (see https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning):
 *  • Modern (2026-07-28): stateless. Every request carries
 *    _meta["io.modelcontextprotocol/protocolVersion"] and the MCP-Protocol-Version,
 *    Mcp-Method and Mcp-Name headers. server/discover is supported.
 *  • Legacy (2025-11-25, 2025-06-18, 2025-03-26, 2024-11-05): initialize handshake.
 *    We never mint Mcp-Session-Id — sessions are optional in those revisions,
 *    and every request is self-contained anyway.
 *
 * Responses are always single JSON objects (no SSE needed: nothing here streams).
 */

require_once __DIR__ . '/lib/tools.php';
require_once __DIR__ . '/lib/ui.php';

const MCP_ERR_PARSE = -32700, MCP_ERR_INVALID_REQUEST = -32600, MCP_ERR_METHOD = -32601,
      MCP_ERR_PARAMS = -32602, MCP_ERR_INTERNAL = -32603,
      MCP_ERR_HEADER_MISMATCH = -32020, MCP_ERR_UNSUPPORTED_VERSION = -32022;

// ─── Helpers ──────────────────────────────────────────────────────────────

/**
 * Connection log (data/oauth/mcp-requests.log, last ~300 lines): one line per
 * request with time, method, era, status, a short note and the client's
 * user agent. No tokens, no request bodies. Shown to admins on setup.php.
 */
function mcpLogRequest(int $status, string $note = ''): void {
    static $done = false;
    if ($done) return; $done = true;
    $line = json_encode([
        't'  => gmdate('c'),
        'm'  => $GLOBALS['__mcp_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? ''),
        'e'  => $GLOBALS['__mcp_era'] ?? '',
        'pv' => $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '',
        's'  => $status,
        'n'  => mb_substr($note, 0, 160),
        'u'  => $GLOBALS['mcp_user']['email'] ?? '',
        'ua' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 80),
        'p'  => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
        'a'  => !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? 1 : 0,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $f = MCP_OAUTH_DIR . '/mcp-requests.log';
    mcpEnsureDir(MCP_OAUTH_DIR);
    @file_put_contents($f, $line . "\n", FILE_APPEND | LOCK_EX);
    if (@filesize($f) > 200000) {
        $lines = @file($f) ?: [];
        @file_put_contents($f, implode('', array_slice($lines, -300)), LOCK_EX);
    }
}

function rpcSend(array $msg, int $status = 200, array $headers = []): void {
    mcpLogRequest($status, $msg['error']['message'] ?? '');
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $h) header($h);
    echo json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function rpcError($id, int $code, string $message, int $status = 200, $data = null): void {
    $err = ['code' => $code, 'message' => $message];
    if ($data !== null) $err['data'] = $data;
    $msg = ['jsonrpc' => '2.0', 'error' => $err];
    if ($id !== null) $msg['id'] = $id;
    rpcSend($msg, $status);
}

function reqHeader(string $name): ?string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) return $_SERVER[$key];
    if ($name === 'Authorization' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strcasecmp($k, $name) === 0) return $v;
    }
    return null;
}

/** Decode the =?base64?…?= sentinel used for non-ASCII header values. */
function decodeMcpHeader(string $v): string {
    if (preg_match('/^=\?base64\?(.*)\?=$/', $v, $m)) {
        $d = base64_decode($m[1], true);
        return $d === false ? "\0invalid" : $d;
    }
    return $v;
}

function authChallenge(int $status, string $error = '', string $desc = '', ?array $scopes = null): void {
    // Point at the metadata for whichever spelling the client used (/mcp or /mcp/),
    // since clients require the document's "resource" to match their URL exactly.
    $slash = str_ends_with(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/');
    $parts = ['resource_metadata="' . ($slash ? MCP_PRM_URL : rtrim(MCP_PRM_URL, '/')) . '"'];
    $parts[] = 'scope="' . implode(' ', $scopes ?? MCP_SCOPES) . '"';
    if ($error) $parts[] = 'error="' . $error . '"';
    if ($desc)  $parts[] = 'error_description="' . str_replace('"', "'", $desc) . '"';
    header('Content-Type: application/json');
    // Pass the status explicitly: PHP otherwise forces 401 for any WWW-Authenticate header.
    header('WWW-Authenticate: Bearer ' . implode(', ', $parts), true, $status);
    mcpLogRequest($status, ($error ?: 'no token') . ($desc ? ": $desc" : ''));
    echo json_encode(['jsonrpc' => '2.0', 'error' => [
        'code' => MCP_ERR_INVALID_REQUEST,
        'message' => $desc ?: 'Authorization required. Connect TaskStick in your AI app to sign in.',
    ]]);
    exit;
}

function serverInfo(): array {
    return [
        'name'       => MCP_SERVER_NAME,
        'title'      => MCP_SERVER_TITLE,
        'version'    => MCP_SERVER_VERSION,
        'websiteUrl' => MCP_PUBLIC_BASE,
        'icons'      => [['src' => MCP_PUBLIC_BASE . '/icons/icon-192.png', 'mimeType' => 'image/png', 'sizes' => ['192x192']]],
    ];
}

function serverInstructions(): string {
    return "TaskStick manages the user's Google Tasks.\n"
        . "- Lists → tasks: call tasks_list_tasklists for list IDs, then tasks_list_tasks; tasks_search finds a task when you don't know its list.\n"
        . "- Starred tasks form the user's Priority card (tasks_list_priority). Snoozed tasks are set aside without being completed and stay out of Priority.\n"
        . "- Use tasks_add_ai_note to record suggestions or next steps on a task instead of overwriting its notes.\n"
        . "- Due dates are date-only (YYYY-MM-DD). A list named \"Daily Ritual\" is a checklist the app resets every day.\n"
        . "- tasks_open_board shows an interactive board when the user wants to see and work through tasks visually.\n"
        . "- Ask before deleting; prefer completing.";
}

function serverCapabilities(bool $clientHasUi): array {
    $caps = ['tools' => ['listChanged' => false], 'resources' => ['listChanged' => false]];
    $caps['extensions'] = [MCP_UI_EXTENSION => ['mimeTypes' => [MCP_UI_MIME]]];
    return $caps;
}

// ─── CORS / method / origin ───────────────────────────────────────────────

$origin = reqHeader('Origin');
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, MCP-Protocol-Version, Mcp-Method, Mcp-Name, Mcp-Session-Id, Last-Event-ID, traceparent, tracestate');
header('Access-Control-Expose-Headers: WWW-Authenticate, MCP-Protocol-Version');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { mcpLogRequest(204, 'CORS preflight'); http_response_code(204); exit; }

// DNS-rebinding guard: a browser Origin must be https (any site — auth is a
// bearer token, never a cookie) or a local dev page.
if ($origin !== null && $origin !== '') {
    $o = parse_url($origin);
    $okOrigin = $o && (($o['scheme'] ?? '') === 'https'
        || (($o['scheme'] ?? '') === 'http' && in_array($o['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)));
    if (!$okOrigin) rpcError(null, MCP_ERR_INVALID_REQUEST, 'Origin not allowed.', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // No standalone SSE stream (legacy GET) and no sessions to DELETE.
    header('Allow: POST, OPTIONS');
    rpcError(null, MCP_ERR_INVALID_REQUEST, 'This MCP endpoint accepts POST only. Setup help: ' . MCP_SETUP_URL, 405);
}

// ─── Authentication ───────────────────────────────────────────────────────

// Old URL-embedded API keys (?key=…) were retired in v2.0. A valid OAuth
// token wins (the old query string is just ignored); without one, explain.
$keyNote = isset($_GET['key']) ? 'API keys in the URL are no longer supported. Remove this connector and add ' . MCP_RESOURCE_URL . ' again — you will sign in with Google.' : '';
$auth = reqHeader('Authorization') ?? '';
if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) $keyNote ? authChallenge(401, 'invalid_token', $keyNote) : authChallenge(401);
$tokenRec = oauthVerifyAccessToken($m[1]);
if (!$tokenRec) authChallenge(401, 'invalid_token', $keyNote ?: 'The access token is invalid or expired.');
mcpSetUser($tokenRec);
$scope = $tokenRec['scope'] ?? [];

// ─── Parse JSON-RPC ───────────────────────────────────────────────────────

$raw = (string)file_get_contents('php://input');
$req = json_decode($raw, true);
if (!is_array($req)) rpcError(null, MCP_ERR_PARSE, 'Parse error: body must be a JSON-RPC object.', 400);
if (array_is_list($req)) rpcError(null, MCP_ERR_INVALID_REQUEST, 'JSON-RPC batching is not supported; send one message per POST.', 400);
if (($req['jsonrpc'] ?? null) !== '2.0') rpcError(null, MCP_ERR_INVALID_REQUEST, 'Invalid JSON-RPC request.', 400);

$isRequest = array_key_exists('id', $req);
$id     = $req['id'] ?? null;
$method = (string)($req['method'] ?? '');
$params = is_array($req['params'] ?? null) ? $req['params'] : [];

$GLOBALS['__mcp_method'] = $method;

if ($method === '' && $isRequest) {
    // A JSON-RPC response from the client (legacy servers could receive these) — nothing to do.
    http_response_code(202); exit;
}

// Notifications (no id): accept and ignore (e.g. notifications/initialized).
if (!$isRequest) { mcpLogRequest(202, 'notification ' . $method); http_response_code(202); exit; }

// ─── Era detection & header validation ────────────────────────────────────

$meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
$modernVersion = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;
$headerVersion = reqHeader('MCP-Protocol-Version');

if ($method === 'initialize') {
    $era = 'legacy';
} elseif ($modernVersion !== null) {
    $era = 'modern';
    if (!is_string($modernVersion) || !in_array($modernVersion, MCP_MODERN_VERSIONS, true)) {
        rpcError($id, MCP_ERR_UNSUPPORTED_VERSION, 'Unsupported protocol version', 400, [
            'supported' => array_merge(MCP_MODERN_VERSIONS, MCP_LEGACY_VERSIONS),
            'requested' => $modernVersion,
        ]);
    }
    if ($headerVersion === null) rpcError($id, MCP_ERR_HEADER_MISMATCH, 'Header mismatch: MCP-Protocol-Version header is required.', 400);
    if ($headerVersion !== $modernVersion) rpcError($id, MCP_ERR_HEADER_MISMATCH, "Header mismatch: MCP-Protocol-Version '$headerVersion' does not match body '$modernVersion'.", 400);
    $hm = reqHeader('Mcp-Method');
    if ($hm === null || $hm !== $method) rpcError($id, MCP_ERR_HEADER_MISMATCH, 'Header mismatch: Mcp-Method header missing or does not match the body method.', 400);
    if (in_array($method, ['tools/call', 'resources/read', 'prompts/get'], true)) {
        $nameVal = $method === 'resources/read' ? ($params['uri'] ?? null) : ($params['name'] ?? null);
        $hn = reqHeader('Mcp-Name');
        if ($hn === null || decodeMcpHeader($hn) !== (string)$nameVal) {
            rpcError($id, MCP_ERR_HEADER_MISMATCH, 'Header mismatch: Mcp-Name header missing or does not match the body.', 400);
        }
    }
} else {
    $era = 'legacy';
    if ($headerVersion !== null && in_array($headerVersion, MCP_MODERN_VERSIONS, true)) {
        rpcError($id, MCP_ERR_HEADER_MISMATCH, "Header mismatch: MCP-Protocol-Version is $headerVersion but the request has no _meta protocol version.", 400);
    }
    if ($headerVersion !== null && !in_array($headerVersion, MCP_LEGACY_VERSIONS, true)) {
        rpcError($id, MCP_ERR_UNSUPPORTED_VERSION, 'Unsupported protocol version', 400, [
            'supported' => array_merge(MCP_MODERN_VERSIONS, MCP_LEGACY_VERSIONS), 'requested' => $headerVersion,
        ]);
    }
}

$GLOBALS['__mcp_era'] = $era;

$clientCaps = $era === 'modern'
    ? ($meta['io.modelcontextprotocol/clientCapabilities'] ?? [])
    : ($params['capabilities'] ?? []);
$clientHasUi = is_array($clientCaps) && isset($clientCaps['extensions'][MCP_UI_EXTENSION]);

/** Wrap a result for the active era. */
function finish($id, array $result, string $era, bool $cacheable = false, string $cacheScope = 'private', int $ttlMs = 300000): void {
    if ($era === 'modern') {
        $result = ['resultType' => 'complete'] + $result;
        $result['_meta'] = ($result['_meta'] ?? []) + ['io.modelcontextprotocol/serverInfo' => serverInfo()];
        if ($cacheable) { $result['ttlMs'] = $ttlMs; $result['cacheScope'] = $cacheScope; }
    }
    rpcSend(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result ?: new stdClass()],
        200, $era === 'modern' ? ['MCP-Protocol-Version: ' . MCP_MODERN_VERSIONS[0]] : []);
}

// ─── Dispatch ─────────────────────────────────────────────────────────────

switch ($method) {

    case 'initialize': {
        $requested = (string)($params['protocolVersion'] ?? '');
        $version = in_array($requested, MCP_LEGACY_VERSIONS, true) ? $requested : MCP_LEGACY_VERSIONS[0];
        finish($id, [
            'protocolVersion' => $version,
            'capabilities'    => serverCapabilities($clientHasUi),
            'serverInfo'      => serverInfo(),
            'instructions'    => serverInstructions(),
        ], $era);
    }

    case 'server/discover':
        finish($id, [
            'supportedVersions' => array_merge(MCP_MODERN_VERSIONS, MCP_LEGACY_VERSIONS),
            'capabilities'      => serverCapabilities($clientHasUi),
            'instructions'      => serverInstructions(),
        ], $era, true, 'public', 3600000);

    case 'ping':
        if ($era === 'modern') rpcError($id, MCP_ERR_METHOD, 'Method not found: ping (removed in 2026-07-28)', 404);
        finish($id, [], $era);

    case 'tools/list':
        // Varies by granted scope (read-only tokens see read tools only), hence private.
        finish($id, ['tools' => mcpToolsForScope($scope)], $era, true, 'private', 300000);

    case 'tools/call': {
        $name = (string)($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $tool = mcpFindTool($name);
        if (!$tool) rpcError($id, MCP_ERR_PARAMS, "Unknown tool: $name");
        if ($tool['write'] && !in_array('tasks.write', $scope, true)) {
            authChallenge(403, 'insufficient_scope',
                'This connection is read-only. Reconnect TaskStick and allow "Create and change tasks" to use ' . $name . '.',
                ['tasks.read', 'tasks.write']);
        }
        try {
            $result = ($tool['handler'])($args);
        } catch (McpToolError $e) {
            $result = mcpToolError($e->getMessage());
        } catch (Throwable $e) {
            error_log('[mcp] ' . $name . ': ' . $e->getMessage());
            $result = mcpToolError('Something went wrong running ' . $name . '. Try again; if it keeps failing, reconnect TaskStick.');
        }
        finish($id, $result, $era);
    }

    case 'resources/list':
        finish($id, ['resources' => [mcpBoardResourceDescriptor()]], $era, true, 'public', 3600000);

    case 'resources/templates/list':
        finish($id, ['resourceTemplates' => []], $era, true, 'public', 3600000);

    case 'resources/read': {
        $uri = (string)($params['uri'] ?? '');
        if ($uri !== MCP_UI_BOARD_URI) {
            // 2026-07-28 uses Invalid Params; earlier revisions used -32002.
            rpcError($id, $era === 'modern' ? MCP_ERR_PARAMS : -32002, "Resource not found: $uri", 200, ['uri' => $uri]);
        }
        finish($id, ['contents' => [mcpBoardResourceContents()]], $era, true, 'public', 3600000);
    }

    case 'prompts/list':
        finish($id, ['prompts' => []], $era, true, 'public', 3600000);

    case 'logging/setLevel':
        if ($era === 'legacy') finish($id, [], $era);
        // fall through: removed in modern

    default:
        rpcError($id, MCP_ERR_METHOD, "Method not found: $method", $era === 'modern' ? 404 : 200);
}
