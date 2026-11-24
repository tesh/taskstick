<?php
// Stub: the MCP server lives in mcp-server/. IONOS serves /mcp/ from this real
// folder (root .htaccess rewrites into mcp-server/ don't apply to "mcp" on
// IONOS — see BUG-023), so forward to the real endpoint.
chdir(dirname(__DIR__) . '/mcp-server');
require dirname(__DIR__) . '/mcp-server/index.php';
