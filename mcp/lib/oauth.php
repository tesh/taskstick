<?php
/**
 * OAuth 2.1 authorization-server + resource-server helpers for the MCP server.
 *
 * Storage: one small JSON file per record under data/oauth/, keyed by a
 * SHA-256 of the secret (tokens and codes are never stored in plaintext).
 *   client_*.json  Dynamically registered clients (RFC 7591, deprecated but still used by Claude/ChatGPT fallbacks)
 *   cimd_*.json    Cached Client ID Metadata Documents
 *   code_*.json    Authorization codes (5 min)
 *   at_*.json      Access tokens (1 h)
 *   rt_*.json      Refresh tokens (180 days sliding, rotated on every use)
 *
 * Every function here is prefixed (mcp…/oauth…) so this file can be loaded
 * alongside the main app's config.php without name collisions.
 */

require_once dirname(__DIR__) . '/config.php';

// ═════════════════════════════════════════════════════════════════════════
// Generic helpers
// ═════════════════════════════════════════════════════════════════════════

function mcpB64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function mcpRandomToken(string $prefix, int $bytes = 32): string {
    return $prefix . mcpB64url(random_bytes($bytes));
}

function mcpJson($data, int $code = 200, array $headers = []): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    foreach ($headers as $h) header($h);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function mcpEnsureDir(string $dir): void {
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    // data/ already has a Deny-from-all .htaccess; add one here too in case
    // this directory is ever moved.
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Order deny,allow\nDeny from all\n");
}

function mcpSafeUserId(string $userId): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId);
}

/** Lowercase scheme+host, drop trailing slash — for resource comparisons. */
function mcpNormalizeUrl(string $url): string {
    $p = parse_url(trim($url));
    if (!$p || empty($p['scheme']) || empty($p['host'])) return '';
    $out = strtolower($p['scheme']) . '://' . strtolower($p['host']);
    if (!empty($p['port'])) $out .= ':' . $p['port'];
    $out .= rtrim($p['path'] ?? '', '/');
    return $out;
}

/** Does a client-supplied RFC 8707 resource identify this MCP server? */
function mcpResourceMatches(?string $resource): bool {
    if ($resource === null || $resource === '') return true; // tolerate clients that omit it
    $n = mcpNormalizeUrl($resource);
    return $n !== '' && ($n === mcpNormalizeUrl(MCP_RESOURCE_URL) || $n === mcpNormalizeUrl(MCP_PUBLIC_BASE));
}

// ═════════════════════════════════════════════════════════════════════════
// Record storage
// ═════════════════════════════════════════════════════════════════════════

function oauthPath(string $kind, string $key): string {
    return MCP_OAUTH_DIR . '/' . $kind . '_' . hash('sha256', $key) . '.json';
}

function oauthPut(string $kind, string $key, array $data): bool {
    mcpEnsureDir(MCP_OAUTH_DIR);
    $file = oauthPath($kind, $key);
    $tmp  = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return false;
    return rename($tmp, $file);
}

/** Returns the record, or null if missing/expired (expired records are deleted). */
function oauthGet(string $kind, string $key): ?array {
    $file = oauthPath($kind, $key);
    if (!is_file($file)) return null;
    $d = json_decode((string)file_get_contents($file), true);
    if (!is_array($d)) return null;
    if (!empty($d['exp']) && time() > $d['exp']) { @unlink($file); return null; }
    return $d;
}

function oauthDel(string $kind, string $key): void {
    @unlink(oauthPath($kind, $key));
}

/** Atomically fetch-and-delete (used for single-use authorization codes). */
function oauthTake(string $kind, string $key): ?array {
    $file = oauthPath($kind, $key);
    $claimed = $file . '.claimed.' . bin2hex(random_bytes(4));
    if (!@rename($file, $claimed)) return null;       // someone else already took it
    $d = json_decode((string)file_get_contents($claimed), true);
    @unlink($claimed);
    if (!is_array($d) || (!empty($d['exp']) && time() > $d['exp'])) return null;
    return $d;
}

/** Iterate stored records of one kind (small installs only — it's a glob). */
function oauthScan(string $kind): array {
    $out = [];
    foreach (glob(MCP_OAUTH_DIR . '/' . $kind . '_*.json') ?: [] as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) { $d['__file'] = $f; $out[] = $d; }
    }
    return $out;
}

/** Opportunistic cleanup of expired records (called ~2% of token requests). */
function oauthSweep(): void {
    foreach (glob(MCP_OAUTH_DIR . '/*.json') ?: [] as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (is_array($d) && !empty($d['exp']) && time() > $d['exp']) @unlink($f);
    }
    foreach (glob(MCP_OAUTH_DIR . '/*.tmp') ?: [] as $f) {
        if (filemtime($f) < time() - 600) @unlink($f);
    }
}

// ═════════════════════════════════════════════════════════════════════════
// Scopes
// ═════════════════════════════════════════════════════════════════════════

/** Filter a requested scope string to scopes we grant; empty → all. */
function oauthParseScopes(?string $scope): array {
    $req = preg_split('/\s+/', trim((string)$scope), -1, PREG_SPLIT_NO_EMPTY);
    $granted = array_values(array_intersect(MCP_SCOPES, $req));
    return $granted ?: MCP_SCOPES;
}

// ═════════════════════════════════════════════════════════════════════════
// Clients: CIMD (preferred), DCR (deprecated fallback)
// ═════════════════════════════════════════════════════════════════════════

function oauthIsCimdClientId(string $clientId): bool {
    $p = parse_url($clientId);
    return $p && ($p['scheme'] ?? '') === 'https' && !empty($p['host'])
        && !empty($p['path']) && $p['path'] !== '/'
        && !isset($p['fragment']) && !isset($p['user']) && !isset($p['pass']);
}

/** True if every resolved address for $host is public (SSRF guard). */
function oauthHostIsPublic(string $host, ?string &$pinnedIp = null): bool {
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = gethostbynamel($host) ?: [];
    }
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    $pinnedIp = $ips[0];
    return true;
}

/**
 * Fetch and validate a Client ID Metadata Document.
 * Returns the normalized client record or throws RuntimeException.
 */
function oauthFetchCimd(string $clientId): array {
    $cached = oauthGet('cimd', $clientId);
    if ($cached) return $cached;

    $p = parse_url($clientId);
    $host = $p['host'];
    $port = $p['port'] ?? 443;
    $ip = null;
    if (!oauthHostIsPublic($host, $ip)) throw new RuntimeException('Client metadata host is not reachable.');

    $body = '';
    $ch = curl_init($clientId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_RESOLVE        => ["$host:$port:$ip"],   // pin the vetted IP (no DNS rebinding)
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body) {
            $body .= $chunk;
            return strlen($body) > 20000 ? 0 : strlen($chunk);   // 20 KB cap
        },
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) throw new RuntimeException("Client metadata document returned HTTP $status.");
    $doc = json_decode($body, true);
    if (!is_array($doc)) throw new RuntimeException('Client metadata document is not valid JSON.');
    if (($doc['client_id'] ?? null) !== $clientId) throw new RuntimeException('Client metadata client_id does not match its URL.');
    $uris = $doc['redirect_uris'] ?? null;
    if (!is_array($uris) || !$uris) throw new RuntimeException('Client metadata has no redirect_uris.');

    $record = [
        'client_id'     => $clientId,
        'client_name'   => mb_substr((string)($doc['client_name'] ?? $host), 0, 80),
        'client_uri'    => is_string($doc['client_uri'] ?? null) ? $doc['client_uri'] : null,
        'logo_uri'      => is_string($doc['logo_uri'] ?? null) ? $doc['logo_uri'] : null,
        'redirect_uris' => array_values(array_filter($uris, 'is_string')),
        'auth_method'   => 'none',
        'type'          => 'cimd',
        'exp'           => time() + MCP_CIMD_CACHE_TTL,
    ];
    oauthPut('cimd', $clientId, $record);
    return $record;
}

/** Resolve any client_id to a client record, or null. */
function oauthResolveClient(string $clientId, ?string &$error = null): ?array {
    if ($clientId === '') { $error = 'Missing client_id.'; return null; }
    if (oauthIsCimdClientId($clientId)) {
        try { return oauthFetchCimd($clientId); }
        catch (RuntimeException $e) { $error = $e->getMessage(); return null; }
    }
    $c = oauthGet('client', $clientId);
    if (!$c) $error = 'Unknown client_id. Remove and re-add the connector in your AI app.';
    return $c;
}

function oauthIsLoopbackRedirect(string $uri): bool {
    $p = parse_url($uri);
    return $p && ($p['scheme'] ?? '') === 'http'
        && in_array(strtolower($p['host'] ?? ''), ['127.0.0.1', 'localhost', '[::1]', '::1'], true);
}

/**
 * Exact redirect URI match, except loopback http redirects, which match with
 * the port ignored (RFC 8252 §7.3; Claude Code and other native clients use
 * an ephemeral port on both 127.0.0.1 and localhost).
 */
function oauthRedirectAllowed(array $client, string $uri): bool {
    foreach ($client['redirect_uris'] as $registered) {
        if (hash_equals($registered, $uri)) return true;
        if (oauthIsLoopbackRedirect($registered) && oauthIsLoopbackRedirect($uri)) {
            $a = parse_url($registered); $b = parse_url($uri);
            if (strtolower($a['host']) === strtolower($b['host'])
                && ($a['path'] ?? '/') === ($b['path'] ?? '/')
                && ($a['query'] ?? '') === ($b['query'] ?? '')) return true;
        }
    }
    return false;
}

/** Validate a redirect URI offered at registration time. */
function oauthRedirectUriAcceptable(string $uri): bool {
    $p = parse_url($uri);
    if (!$p || empty($p['scheme']) || isset($p['fragment'])) return false;
    $scheme = strtolower($p['scheme']);
    if ($scheme === 'https' && !empty($p['host'])) return true;
    if (oauthIsLoopbackRedirect($uri)) return true;
    // Private-use URI schemes for native apps (RFC 8252 §7.1), e.g. cursor://anysphere.cursor-mcp/…
    if (in_array($scheme, ['http', 'javascript', 'data', 'file', 'vbscript', 'blob', 'about'], true)) return false;
    return (bool)preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme);
}

// ═════════════════════════════════════════════════════════════════════════
// Tokens
// ═════════════════════════════════════════════════════════════════════════

/**
 * Mint an access + refresh token pair for a grant.
 * $grant: userId, email, clientId, clientName, scope (array), familyId
 */
function oauthIssueTokens(array $grant): array {
    $access  = mcpRandomToken('tsk_at_');
    $refresh = mcpRandomToken('tsk_rt_');
    $now = time();
    $common = [
        'userId'     => $grant['userId'],
        'email'      => $grant['email'] ?? '',
        'clientId'   => $grant['clientId'],
        'clientName' => $grant['clientName'] ?? '',
        'scope'      => array_values($grant['scope']),
        'resource'   => MCP_RESOURCE_URL,
        'familyId'   => $grant['familyId'],
    ];
    oauthPut('at', $access,  $common + ['exp' => $now + MCP_ACCESS_TOKEN_TTL, 'createdAt' => $now]);
    oauthPut('rt', $refresh, $common + [
        'exp'       => $now + MCP_REFRESH_TOKEN_TTL,
        'createdAt' => $grant['createdAt'] ?? $now,
        'lastUsed'  => $now,
    ]);
    return [
        'access_token'  => $access,
        'token_type'    => 'Bearer',
        'expires_in'    => MCP_ACCESS_TOKEN_TTL,
        'refresh_token' => $refresh,
        'scope'         => implode(' ', $grant['scope']),
    ];
}

/** Delete every access/refresh token in a grant family. */
function oauthRevokeFamily(string $familyId): int {
    $n = 0;
    foreach (['at', 'rt'] as $kind) {
        foreach (oauthScan($kind) as $r) {
            if (($r['familyId'] ?? '') === $familyId) { @unlink($r['__file']); $n++; }
        }
    }
    return $n;
}

/** Resource-server check: returns the access-token record or null. */
function oauthVerifyAccessToken(string $token): ?array {
    if (!str_starts_with($token, 'tsk_at_')) return null;
    $r = oauthGet('at', $token);
    if (!$r) return null;
    // Audience binding (RFC 8707): only tokens minted for this MCP server.
    if (mcpNormalizeUrl($r['resource'] ?? '') !== mcpNormalizeUrl(MCP_RESOURCE_URL)) return null;
    return $r;
}

/** Active grants (one per family) for a user — for the setup page. */
function oauthListGrants(string $userId): array {
    $byFamily = [];
    foreach (oauthScan('rt') as $r) {
        if (($r['userId'] ?? '') !== $userId) continue;
        if (!empty($r['exp']) && time() > $r['exp']) continue;
        if (!empty($r['rotatedAt'])) continue;
        $f = $r['familyId'];
        if (!isset($byFamily[$f]) || ($r['lastUsed'] ?? 0) > ($byFamily[$f]['lastUsed'] ?? 0)) $byFamily[$f] = $r;
    }
    usort($byFamily, fn($a, $b) => ($b['lastUsed'] ?? 0) <=> ($a['lastUsed'] ?? 0));
    return array_values($byFamily);
}

// ═════════════════════════════════════════════════════════════════════════
// Google refresh-token store (shared with the old setup flow)
// ═════════════════════════════════════════════════════════════════════════

function mcpGoogleTokenFile(string $userId): string {
    return DATA_DIR . '/tokens_' . mcpSafeUserId($userId) . '.json';
}

function mcpSaveGoogleTokens(string $userId, string $email, string $refreshToken, string $accessToken = '', int $expiry = 0): bool {
    mcpEnsureDir(DATA_DIR);
    $data = [
        'refresh_token' => $refreshToken,
        'access_token'  => $accessToken,
        'token_expiry'  => $expiry,
        'saved_at'      => date('c'),
        'user_email'    => $email,
    ];
    return file_put_contents(mcpGoogleTokenFile($userId), json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function mcpHasGoogleTokens(string $userId): bool {
    $f = mcpGoogleTokenFile($userId);
    if (!is_file($f)) return false;
    $d = json_decode((string)file_get_contents($f), true);
    return !empty($d['refresh_token']);
}

// ═════════════════════════════════════════════════════════════════════════
// Discovery documents
// ═════════════════════════════════════════════════════════════════════════

/** $trailingSlash: describe the /mcp/ spelling (resource must match what the user entered). */
function mcpProtectedResourceMetadata(bool $trailingSlash = false): array {
    return [
        'resource'                 => MCP_RESOURCE_URL . ($trailingSlash ? '/' : ''),
        'authorization_servers'    => [MCP_ISSUER],
        'scopes_supported'         => MCP_SCOPES,
        'bearer_methods_supported' => ['header'],
        'resource_name'            => 'TaskStick (Google Tasks)',
        'resource_documentation'   => MCP_SETUP_URL,
        'resource_policy_uri'      => MCP_PUBLIC_BASE . '/privacy.html',
        'resource_tos_uri'         => MCP_PUBLIC_BASE . '/terms.html',
    ];
}

function mcpAuthorizationServerMetadata(): array {
    return [
        'issuer'                                => MCP_ISSUER,
        'authorization_endpoint'                => MCP_AUTHORIZE_URL,
        'token_endpoint'                        => MCP_TOKEN_URL,
        'registration_endpoint'                 => MCP_REGISTER_URL,
        'revocation_endpoint'                   => MCP_REVOKE_URL,
        'response_types_supported'              => ['code'],
        'response_modes_supported'              => ['query'],
        'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported'      => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        'scopes_supported'                      => array_merge(MCP_SCOPES, ['offline_access']),
        'client_id_metadata_document_supported' => true,
        'authorization_response_iss_parameter_supported' => true,
        'service_documentation'                 => MCP_SETUP_URL,
        'op_policy_uri'                         => MCP_PUBLIC_BASE . '/privacy.html',
        'op_tos_uri'                            => MCP_PUBLIC_BASE . '/terms.html',
    ];
}
