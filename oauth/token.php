<?php
/**
 * OAuth 2.1 token endpoint.
 *   grant_type=authorization_code  (PKCE S256 required)
 *   grant_type=refresh_token       (refresh tokens rotate on every use)
 *
 * Accepts application/x-www-form-urlencoded (RFC 6749) and, leniently, JSON.
 * Client authentication: none (public clients — CIMD and most DCR clients),
 * client_secret_post or client_secret_basic (DCR clients that asked for a secret).
 */

require_once dirname(__DIR__) . '/mcp-server/lib/oauth.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') mcpJson(['error' => 'invalid_request', 'error_description' => 'Use POST.'], 405);

function tokenError(string $error, string $desc, int $code = 400): void {
    mcpJson(['error' => $error, 'error_description' => $desc], $code);
}

// ─── Parse body ───────────────────────────────────────────────────────────
$in = $_POST;
if (!$in && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
}

// ─── Client authentication ────────────────────────────────────────────────
$clientId = (string)($in['client_id'] ?? '');
$clientSecret = isset($in['client_secret']) ? (string)$in['client_secret'] : null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Basic\s+(.+)$/i', $authHeader, $m)) {
    $pair = base64_decode($m[1], true);
    if ($pair !== false && str_contains($pair, ':')) {
        [$bid, $bsec] = explode(':', $pair, 2);
        $clientId = urldecode($bid);
        $clientSecret = urldecode($bsec);
    }
}
if (!empty($in['client_assertion'])) {
    tokenError('invalid_client', 'private_key_jwt is not supported; use token_endpoint_auth_method "none".', 401);
}
if ($clientId === '') tokenError('invalid_client', 'client_id is required.', 401);

$cerr = null;
$client = oauthResolveClient($clientId, $cerr);
if (!$client) tokenError('invalid_client', $cerr ?: 'Unknown client.', 401);
if (($client['auth_method'] ?? 'none') !== 'none') {
    if ($clientSecret === null || empty($client['secret_hash'])
        || !hash_equals($client['secret_hash'], hash('sha256', $clientSecret))) {
        tokenError('invalid_client', 'Client authentication failed.', 401);
    }
}

if (mt_rand(1, 50) === 1) oauthSweep();

$grantType = (string)($in['grant_type'] ?? '');

// ─── authorization_code ───────────────────────────────────────────────────
if ($grantType === 'authorization_code') {
    $code     = (string)($in['code'] ?? '');
    $verifier = (string)($in['code_verifier'] ?? '');
    $redirect = (string)($in['redirect_uri'] ?? '');

    $rec = $code !== '' ? oauthTake('code', $code) : null;     // single use
    if (!$rec) tokenError('invalid_grant', 'Authorization code is invalid, expired, or already used.');
    if (!hash_equals($rec['clientId'], $clientId)) tokenError('invalid_grant', 'Code was issued to a different client.');
    if ($redirect !== '' && !hash_equals($rec['redirectUri'], $redirect)) tokenError('invalid_grant', 'redirect_uri does not match the authorization request.');
    if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) tokenError('invalid_grant', 'A valid code_verifier is required.');
    if (!hash_equals($rec['challenge'], mcpB64url(hash('sha256', $verifier, true)))) tokenError('invalid_grant', 'PKCE verification failed.');
    if (!mcpResourceMatches($in['resource'] ?? null)) tokenError('invalid_target', 'Tokens are only issued for ' . MCP_RESOURCE_URL);

    $tokens = oauthIssueTokens([
        'userId'     => $rec['userId'],
        'email'      => $rec['email'],
        'clientId'   => $clientId,
        'clientName' => $rec['clientName'] ?? ($client['client_name'] ?? ''),
        'scope'      => $rec['scope'],
        'familyId'   => mcpRandomToken('fam_', 12),
    ]);
    mcpJson($tokens);
}

// ─── refresh_token ────────────────────────────────────────────────────────
if ($grantType === 'refresh_token') {
    $rt = (string)($in['refresh_token'] ?? '');
    $rec = ($rt !== '' && str_starts_with($rt, 'tsk_rt_')) ? oauthGet('rt', $rt) : null;
    if (!$rec) tokenError('invalid_grant', 'Refresh token is invalid or expired. Reconnect TaskStick in your AI app.');
    if (!hash_equals($rec['clientId'], $clientId)) tokenError('invalid_grant', 'Refresh token was issued to a different client.');
    if (!mcpResourceMatches($in['resource'] ?? null)) tokenError('invalid_target', 'Tokens are only issued for ' . MCP_RESOURCE_URL);
    if (!mcpHasGoogleTokens($rec['userId'])) {
        oauthRevokeFamily($rec['familyId']);
        tokenError('invalid_grant', 'Google access for this account was removed. Reconnect TaskStick.');
    }

    if (!empty($rec['rotatedAt'])) {
        // Already rotated. A replay within a few seconds is almost always two
        // parallel refreshes from the same client — allow it. Anything later
        // looks like a stolen token: kill the whole grant (OAuth 2.1 §4.3.1).
        if (time() - $rec['rotatedAt'] > MCP_REFRESH_REUSE_GRACE) {
            oauthRevokeFamily($rec['familyId']);
            tokenError('invalid_grant', 'Refresh token was already used. Reconnect TaskStick in your AI app.');
        }
    } else {
        $rec['rotatedAt'] = time();
        $rec['exp'] = time() + MCP_REFRESH_REUSE_GRACE + 5;
        unset($rec['__file']);
        oauthPut('rt', $rt, $rec);
    }

    // Optional down-scoping
    $scope = $rec['scope'];
    if (!empty($in['scope'])) {
        $want = preg_split('/\s+/', trim((string)$in['scope']), -1, PREG_SPLIT_NO_EMPTY);
        $scope = array_values(array_intersect($scope, $want)) ?: $scope;
    }

    $tokens = oauthIssueTokens([
        'userId'     => $rec['userId'],
        'email'      => $rec['email'] ?? '',
        'clientId'   => $clientId,
        'clientName' => $rec['clientName'] ?? '',
        'scope'      => $scope,
        'familyId'   => $rec['familyId'],
        'createdAt'  => $rec['createdAt'] ?? time(),
    ]);
    mcpJson($tokens);
}

tokenError('unsupported_grant_type', 'Supported grant types: authorization_code, refresh_token.');
