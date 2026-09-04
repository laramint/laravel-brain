<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

/**
 * The cache-project fixture built into a graph.
 *
 * `$enabled` of null leaves the builder alone, which is the position that proves the default —
 * calling the setter with `true` would pass even if the default were off.
 */
function cacheProjectGraph(?bool $enabled = null): Graph
{
    static $graphs = [];

    $key = $enabled === null ? 'default' : ($enabled ? 'on' : 'off');

    if (! isset($graphs[$key])) {
        $root = fixture('cache-project');
        $routes = (new RouteAnalyzer)->analyze($root);
        $controllers = (new ControllerAnalyzer)->analyze($root, $routes);
        $traces = (new MethodTracer)->trace($controllers);

        $builder = new GraphBuilder;
        if ($enabled !== null) {
            $builder->setCacheOperationsEnabled($enabled);
        }

        $graphs[$key] = $builder->build(
            'cache-project',
            $routes,
            new MiddlewareRegistry([], [], []),
            $controllers,
            $traces,
            [],
            $root,
        );
    }

    return $graphs[$key];
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

it('detects cache operations by default, with nobody asking for them', function () {
    // The default position, exercised without touching the setter — calling it with `true`
    // would pass just as well if the default had been flipped to off.
    expect(cacheOpsOf('DashboardController::refresh'))->not->toBeEmpty();
});

it('attaches nothing anywhere once the feature is switched off', function () {
    $nodesWithOps = array_filter(
        cacheProjectGraph(enabled: false)->nodes(),
        fn ($node) => isset($node->data['cacheOps']),
    );

    expect($nodesWithOps)->toBeEmpty();
});

it('switched off means the detection never ran, not that its answers were dropped', function () {
    // The distinction the switch exists for. If it only filtered the collector's output, the
    // flow steps would still be carrying a `cache` payload on every cache call — the detection
    // would have happened and been paid for, and the switch would be a lie about cost.
    $carriers = [];

    $walk = function (array $steps) use (&$walk, &$carriers): void {
        foreach ($steps as $step) {
            if (isset($step['cache'])) {
                $carriers[] = $step['label'] ?? '?';
            }
            foreach (['then', 'else', 'body'] as $branch) {
                if (isset($step[$branch]) && is_array($step[$branch])) {
                    $walk($step[$branch]);
                }
            }
        }
    };

    foreach (cacheProjectGraph(enabled: false)->nodes() as $node) {
        $walk($node->data['flowSteps'] ?? []);
    }

    expect($carriers)->toBeEmpty();

    // A negative control: the same walk over the on graph finds them, so an empty result above
    // means the switch worked rather than that the walk looks in the wrong place.
    $carriers = [];
    foreach (cacheProjectGraph(enabled: true)->nodes() as $node) {
        $walk($node->data['flowSteps'] ?? []);
    }

    expect($carriers)->not->toBeEmpty();
});

it('charts the same flow either way, minus the cache typing', function () {
    // The switch turns a feature off; it must not quietly change what else the chart says.
    $stepCount = function (Graph $graph): int {
        $total = 0;
        foreach ($graph->nodes() as $node) {
            $total += count($node->data['flowSteps'] ?? []);
        }

        return $total;
    };

    expect($stepCount(cacheProjectGraph(enabled: false)))
        ->toBe($stepCount(cacheProjectGraph(enabled: true)));
});
