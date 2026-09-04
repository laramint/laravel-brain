<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

/**
 * An MCP server tool. `laravel/ai` accepts one of these straight from an agent's tools() and
 * wraps it in its own McpServerTool, so it is a tool for graph purposes without implementing
 * the SDK's Tool contract.
 */
class InventoryTool extends Tool
{
    protected string $description = 'Look up warehouse stock.';

    public function handle(Request $request): string
    {
        return '';
    }
}
