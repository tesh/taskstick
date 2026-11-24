<?php
/**
 * OAuth 2.0 Dynamic Client Registration (RFC 7591).
 *
 * Deprecated by MCP 2026-07-28 in favour of Client ID Metadata Documents, but
 * still the fallback Claude, ChatGPT, Cursor and others use when they don't
 * pick CIMD — so it stays.
 */

require_once dirname(__DIR__) . '/mcp/lib/oauth.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') mcpJson(['error' => 'invalid_request', 'error_description' => 'Use POST.'], 405);

function regError(string $error, string $desc): void {
    mcpJson(['error' => $error, 'error_description' => $desc], 400);
}

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 20000) regError('invalid_client_metadata', 'Registration request too large.');
$m = json_decode($raw, true);
if (!is_array($m)) regError('invalid_client_metadata', 'Body must be a JSON object.');

$uris = $m['redirect_uris'] ?? null;
if (!is_array($uris) || !$uris || count($uris) > 10) regError('invalid_redirect_uri', 'Provide 1–10 redirect_uris.');
foreach ($uris as $u) {
    if (!is_string($u) || strlen($u) > 500 || !oauthRedirectUriAcceptable($u)) {
        regError('invalid_redirect_uri', 'Redirect URIs must be https, http loopback (127.0.0.1/localhost), or a private-use app scheme.');
    }
}

$method = $m['token_endpoint_auth_method'] ?? 'none';
if (!in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
    regError('invalid_client_metadata', 'token_endpoint_auth_method must be none, client_secret_post or client_secret_basic.');
}
$grantTypes = $m['grant_types'] ?? ['authorization_code', 'refresh_token'];
if (!is_array($grantTypes) || array_diff($grantTypes, ['authorization_code', 'refresh_token'])) {
    regError('invalid_client_metadata', 'Only authorization_code and refresh_token grants are supported.');
}
$responseTypes = $m['response_types'] ?? ['code'];
if (!is_array($responseTypes) || array_diff($responseTypes, ['code'])) {
    regError('invalid_client_metadata', 'Only the code response type is supported.');
}

$clientId = mcpRandomToken('tsk_client_', 18);
$now = time();
$name = is_string($m['client_name'] ?? null) ? mb_substr(trim($m['client_name']), 0, 80) : '';
if ($name === '') $name = parse_url($uris[0], PHP_URL_HOST) ?: 'MCP client';

$record = [
    'client_id'     => $clientId,
    'client_name'   => $name,
    'client_uri'    => is_string($m['client_uri'] ?? null) ? $m['client_uri'] : null,
    'redirect_uris' => array_values($uris),
    'auth_method'   => $method,
    'type'          => 'dcr',
    'createdAt'     => $now,
];
$response = [
    'client_id'                  => $clientId,
    'client_id_issued_at'        => $now,
    'client_name'                => $name,
    'redirect_uris'              => array_values($uris),
    'grant_types'                => array_values($grantTypes),
    'response_types'             => ['code'],
    'token_endpoint_auth_method' => $method,
    'scope'                      => implode(' ', MCP_SCOPES),
];
if (isset($m['application_type']) && in_array($m['application_type'], ['web', 'native'], true)) {
    $response['application_type'] = $m['application_type'];
}
if ($record['client_uri']) $response['client_uri'] = $record['client_uri'];

if ($method !== 'none') {
    $secret = mcpRandomToken('tsk_secret_', 32);
    $record['secret_hash'] = hash('sha256', $secret);
    $response['client_secret'] = $secret;
    $response['client_secret_expires_at'] = 0;
}

if (!oauthPut('client', $clientId, $record)) {
    mcpJson(['error' => 'server_error', 'error_description' => 'Could not store the registration.'], 500);
}
mcpJson($response, 201);
