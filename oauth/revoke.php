<?php
/**
 * OAuth 2.0 token revocation (RFC 7009). Revoking either token of a grant
 * revokes the whole grant (all its access and refresh tokens).
 */

require_once dirname(__DIR__) . '/mcp/lib/oauth.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') mcpJson(['error' => 'invalid_request'], 405);

$token = (string)($_POST['token'] ?? '');
$rec = null;
if (str_starts_with($token, 'tsk_rt_')) $rec = oauthGet('rt', $token);
elseif (str_starts_with($token, 'tsk_at_')) $rec = oauthGet('at', $token);

if ($rec) {
    $clientId = (string)($_POST['client_id'] ?? '');
    // Only the client the token was issued to may revoke it (when it says who it is).
    if ($clientId === '' || hash_equals($rec['clientId'], $clientId)) oauthRevokeFamily($rec['familyId']);
}
http_response_code(200);   // RFC 7009 §2.2: 200 even for unknown tokens
header('Cache-Control: no-store');
