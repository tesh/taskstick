<?php
/**
 * MCP Apps (io.modelcontextprotocol/ui) resource for the interactive board.
 * The view itself is plain HTML/CSS/JS in mcp/ui/board.html — no build step,
 * no external requests (it talks to the host only via postMessage).
 */

function mcpBoardResourceMeta(): array {
    return ['ui' => [
        'prefersBorder' => true,
        'csp' => ['connectDomains' => [], 'resourceDomains' => []],
    ]];
}

function mcpBoardResourceDescriptor(): array {
    return [
        'uri'         => MCP_UI_BOARD_URI,
        'name'        => 'taskstick-board',
        'title'       => 'TaskStick board',
        'description' => 'Interactive view of your task lists: complete, star and snooze tasks.',
        'mimeType'    => MCP_UI_MIME,
        '_meta'       => mcpBoardResourceMeta(),
    ];
}

function mcpBoardResourceContents(): array {
    return [
        'uri'      => MCP_UI_BOARD_URI,
        'mimeType' => MCP_UI_MIME,
        'text'     => (string)file_get_contents(dirname(__DIR__) . '/ui/board.html'),
        '_meta'    => mcpBoardResourceMeta(),
    ];
}
