<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Mcp;

use LaraMint\LaravelBrain\Mcp\Resources\ManifestResource;
use LaraMint\LaravelBrain\Mcp\Resources\SubgraphResource;
use LaraMint\LaravelBrain\Mcp\Tools\FindUsagesTool;
use LaraMint\LaravelBrain\Mcp\Tools\GetAgentRulesTool;
use LaraMint\LaravelBrain\Mcp\Tools\GetContextTool;
use LaraMint\LaravelBrain\Mcp\Tools\GetFileHistoryTool;
use LaraMint\LaravelBrain\Mcp\Tools\GetGraphTool;
use LaraMint\LaravelBrain\Mcp\Tools\GetManifestTool;
use LaraMint\LaravelBrain\Mcp\Tools\GetRouteSecurityTool;
use LaraMint\LaravelBrain\Mcp\Tools\GetSubgraphTool;
use LaraMint\LaravelBrain\Mcp\Tools\RescanTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Laravel Brain')]
#[Version('1.0.0')]
#[Instructions('Query the last-scanned dependency graph of this Laravel application — routes, controllers, models, middleware, and their exposure/security classification. Call get-manifest first to see what has been scanned, then drill in with get-context or get-route-security. Call rescan after code changes, since every other tool reads whatever was scanned last, not the current code.')]
class BrainMcpServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        GetManifestTool::class,
        GetContextTool::class,
        FindUsagesTool::class,
        RescanTool::class,
        GetSubgraphTool::class,
        GetGraphTool::class,
        GetRouteSecurityTool::class,
        GetAgentRulesTool::class,
        GetFileHistoryTool::class,
    ];

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        ManifestResource::class,
        SubgraphResource::class,
    ];
}
