<?php

use LaraMint\LaravelBrain\Mcp\Tools\GetFileHistoryTool;
use Laravel\Mcp\Server\Tool;

// GetFileHistoryTool extends Laravel\Mcp\Server\Tool. laravel/mcp is an optional
// require-dev dependency (dropped entirely on Laravel < 11 in CI, since it needs
// symfony/process ^7.4.5|^8.0.5, which conflicts with the symfony/process ^6.x that
// Laravel < 11 itself requires) — so autoloading GetFileHistoryTool here would fatal
// wherever it isn't installed. Checking the *parent* class, not GetFileHistoryTool
// itself, is the load-bearing part: class_exists() on GetFileHistoryTool would autoload
// it and hit the same fatal before this check could return false.
if (! class_exists(Tool::class)) {
    return;
}

/**
 * @param  list<array<string, mixed>>  $nodes
 * @return array{meta: array<string, mixed>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
 */
function fileHistorySampleGraph(array $nodes): array
{
    return [
        'meta' => ['project' => 'demo'],
        'nodes' => $nodes,
        'edges' => [],
    ];
}

it('resolves a node id to its file path', function () {
    $graph = fileHistorySampleGraph([
        ['id' => 'service::OrderService::place', 'type' => 'service', 'label' => 'OrderService@place', 'data' => ['file' => '/app/Services/OrderService.php']],
    ]);

    expect(GetFileHistoryTool::resolveFilePath($graph, 'service::OrderService::place'))
        ->toBe('/app/Services/OrderService.php');
});

it('returns null for an unknown node id', function () {
    $graph = fileHistorySampleGraph([
        ['id' => 'service::OrderService::place', 'type' => 'service', 'label' => 'OrderService@place', 'data' => ['file' => '/app/Services/OrderService.php']],
    ]);

    expect(GetFileHistoryTool::resolveFilePath($graph, 'no-such-node'))->toBeNull();
});

it('returns null when the node has no file in its data', function () {
    $graph = fileHistorySampleGraph([
        ['id' => 'service::OrderService::place', 'type' => 'service', 'label' => 'OrderService@place', 'data' => []],
    ]);

    expect(GetFileHistoryTool::resolveFilePath($graph, 'service::OrderService::place'))->toBeNull();
});

it('returns null when the node has no data at all', function () {
    $graph = fileHistorySampleGraph([
        ['id' => 'service::OrderService::place', 'type' => 'service', 'label' => 'OrderService@place'],
    ]);

    expect(GetFileHistoryTool::resolveFilePath($graph, 'service::OrderService::place'))->toBeNull();
});

it('returns null from an empty graph', function () {
    expect(GetFileHistoryTool::resolveFilePath(fileHistorySampleGraph([]), 'anything'))->toBeNull();
});
