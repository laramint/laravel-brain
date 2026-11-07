<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Storage\GraphStoreFactory;
use LaraMint\LaravelBrain\Storage\ScanPublisher;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Re-scans this Laravel application and persists a fresh dependency graph, replacing the last scan. Every other brain_* tool reads whatever was scanned last, not the current code — call this after code changes and before trusting their results.')]
#[IsReadOnly(false)]
#[IsIdempotent]
class RescanTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $memoryLimit = (string) $request->get('memoryLimit', config('laravel-brain.memory_limit', '1024M'));
        if ($memoryLimit === '-1' || preg_match('/^\d+[KMGT]?$/i', $memoryLimit) === 1) {
            ini_set('memory_limit', $memoryLimit);
        }

        if ($request->get('autoDiscover')) {
            config(['laravel-brain.auto_discover_routes' => true]);
        }

        $analyzer = new ProjectAnalyzer;

        // ProjectAnalyzer::analyze() echoes progress to stdout when no callback is given,
        // which would corrupt the MCP stdio transport. This must never be omitted.
        $result = $analyzer->analyze(base_path(), function (string $event, array $data): void {
            //
        });

        ScanPublisher::publish(GraphStoreFactory::make(), $result->manifestJson, $result->subgraphs);

        return Response::structured([
            'project' => $result->projectName,
            'analyzedAt' => $result->analyzedAt,
            'nodes' => $result->fullGraph->nodeCount(),
            'edges' => $result->fullGraph->edgeCount(),
            'routes' => $result->totalRoutes,
            'commands' => $result->totalCommands,
            'channels' => $result->totalChannels,
            'filamentResources' => $result->totalFilamentResources,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'memoryLimit' => $schema->string()
                ->description('Memory limit for this scan, e.g. 1024M, 2G or -1. Defaults to config laravel-brain.memory_limit.'),
            'autoDiscover' => $schema->boolean()
                ->description('Force auto-discover-routes mode for this scan (overrides config).'),
        ];
    }
}
