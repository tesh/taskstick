<?php
// Public MCP endpoint stub: /mcp is rewritten here by the root .htaccess.
// Deliberately not named mcp.php: MultiViews would map /mcp/setup.php onto it.
// The server code lives in mcp-server/. (IONOS won't route /mcp into a
// subfolder, but rewrites to root-level files work — see BUG-023.)
chdir(__DIR__ . '/mcp-server');
require __DIR__ . '/mcp-server/index.php';
