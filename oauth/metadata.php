<?php
/**
 * Discovery documents, served via rewrites in the root .htaccess:
 *   /.well-known/oauth-authorization-server[/…]  → ?doc=as   (RFC 8414)
 *   /.well-known/oauth-protected-resource[/…]    → ?doc=prm  (RFC 9728)
 * Both are also reachable directly at /oauth/metadata.php?doc=…
 */

require_once dirname(__DIR__) . '/mcp/lib/oauth.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, MCP-Protocol-Version');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$doc = $_GET['doc'] ?? 'as';
// path = the suffix after the well-known name, e.g. "/mcp" or "/mcp/"
$data = $doc === 'prm' ? mcpProtectedResourceMetadata(($_GET['path'] ?? '') === '/mcp/') : mcpAuthorizationServerMetadata();

http_response_code(200);
header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');
echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
