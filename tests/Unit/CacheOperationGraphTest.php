<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

function cacheProjectGraph(): Graph
{
    static $graph = null;

    if ($graph === null) {
        $root = fixture('cache-project');
        $routes = (new RouteAnalyzer)->analyze($root);
        $controllers = (new ControllerAnalyzer)->analyze($root, $routes);
        $traces = (new MethodTracer)->trace($controllers);

        $graph = (new GraphBuilder)->build(
            'cache-project',
            $routes,
            new MiddlewareRegistry([], [], []),
            $controllers,
            $traces,
            [],
            $root,
        );
    }

    return $graph;
}

/** @return array[] the cacheOps of the node whose id ends in $suffix */
function cacheOpsOf(string $suffix): array
{
    foreach (cacheProjectGraph()->nodes() as $node) {
        if (str_ends_with($node->id, $suffix)) {
            return $node->data['cacheOps'] ?? [];
        }
    }

    return [];
}

it('attaches every cache operation an action performs to its node', function () {
    $operations = cacheOpsOf('DashboardController::show');

    expect(array_column($operations, 'method'))->toBe(['remember', 'cache', 'get']);
});

it('reaches a cache call nested inside a branch', function () {
    // `Cache::store('redis')->get(...)` in show() is inside an `if`, so it exists only in the
    // step's `then` list. A collector that walked the top level alone would miss it.
    $operations = cacheOpsOf('DashboardController::show');
    $stores = array_column($operations, 'store', 'method');

    expect($stores['get'])->toBe('redis');
});

it('separates reads, writes, invalidations and locks on one action', function () {
    $operations = cacheOpsOf('DashboardController::refresh');

    expect(array_column($operations, 'kind'))
        ->toBe(['invalidate', 'write', 'write', 'invalidate', 'lock']);
});

it('reports one row for a key the same method forgets twice', function () {
    // refresh() forgets `dashboard:summary` twice. Two calls saying the same thing about the
    // same key are one fact, and a panel that repeats it teaches people to skim it.
    $forgets = array_filter(
        cacheOpsOf('DashboardController::refresh'),
        fn (array $op) => $op['key'] === 'dashboard:summary',
    );

    expect($forgets)->toHaveCount(1);
});

it('leaves a node that touches no cache without the key entirely', function () {
    // An empty array would render as a section heading with nothing under it.
    $node = null;
    foreach (cacheProjectGraph()->nodes() as $candidate) {
        if (str_ends_with($candidate->id, 'DashboardController::plain')) {
            $node = $candidate;
        }
    }

    expect($node)->not->toBeNull()
        ->and($node->data)->not->toHaveKey('cacheOps');
});

it('attributes a service cache call to the service, not to the action above it', function () {
    // dbQueries follow the call chain; cache operations do not. Hoisting ReportBuilder::build's
    // rememberForever onto every action that reaches it would put the same key on several
    // panels and lose the one method actually responsible for it.
    expect(array_column(cacheOpsOf('app_services_reportbuilder::build'), 'method'))
        ->toBe(['rememberForever'])
        ->and(array_column(cacheOpsOf('DashboardController::show'), 'method'))
        ->not->toContain('rememberForever');
});

it('survives the round trip through the graph JSON', function () {
    $decoded = json_decode(cacheProjectGraph()->toJson(), true);
    $withCache = array_filter($decoded['nodes'], fn (array $n) => isset($n['data']['cacheOps']));

    expect($withCache)->not->toBeEmpty();

    foreach ($withCache as $node) {
        foreach ($node['data']['cacheOps'] as $operation) {
            expect($operation)->toHaveKeys(['kind', 'method', 'key', 'keyKind', 'store', 'tags', 'ttl']);
        }
    }
});
