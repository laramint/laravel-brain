<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;

/**
 * ProjectAnalyzer is where config() is read and pushed into the builder, so this is the only
 * level at which the config KEY itself is under test. Everything below it takes a bool.
 */
function analyzeCacheProject(array $brainConfig): Graph
{
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository([
        'app' => ['name' => 'CacheConfigTest'],
        'laravel-brain' => $brainConfig,
    ]));

    try {
        return (new ProjectAnalyzer)->analyze(fixture('cache-project'), function () {})->fullGraph;
    } finally {
        Container::setInstance(null);
    }
}

/** @return string[] the labels of every node the graph gave cacheOps to */
function nodesWithCacheOps(Graph $graph): array
{
    $labels = [];
    foreach ($graph->nodes() as $node) {
        if (! empty($node->data['cacheOps'])) {
            $labels[] = $node->label;
        }
    }

    return $labels;
}

it('detects cache operations when the config says nothing at all', function () {
    // A published config predating this feature has no key for it, and neither does an
    // application that never published one. Both must land on the default, which is on.
    expect(nodesWithCacheOps(analyzeCacheProject([])))->not->toBeEmpty();
});

it('detects cache operations when the config turns them on', function () {
    expect(nodesWithCacheOps(analyzeCacheProject(['cache_operations' => ['enabled' => true]])))
        ->not->toBeEmpty();
});

it('detects none when the config turns them off', function () {
    // Pins the key path as well as the behaviour: a rename of `cache_operations.enabled` on
    // either side of the read fails here rather than silently ignoring the switch.
    expect(nodesWithCacheOps(analyzeCacheProject(['cache_operations' => ['enabled' => false]])))
        ->toBeEmpty();
});

it('treats a falsy config value as off', function () {
    // env() hands back strings, and an operator writing LARAVEL_BRAIN_CACHE_OPERATIONS_ENABLED=0
    // means off. The cast at the read is what makes that true.
    expect(nodesWithCacheOps(analyzeCacheProject(['cache_operations' => ['enabled' => 0]])))
        ->toBeEmpty();
});
