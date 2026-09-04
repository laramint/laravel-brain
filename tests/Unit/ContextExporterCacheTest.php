<?php

use LaraMint\LaravelBrain\Ai\ContextExporter;
use LaraMint\LaravelBrain\Storage\FileGraphStore;

/**
 * An export of one service node carrying the given cache operations.
 *
 * @param  array[]  $cacheOps
 */
function exportWithCacheOps(array $cacheOps): string
{
    $project = sys_get_temp_dir().'/lb_cache_'.bin2hex(random_bytes(6));
    mkdir($project.'/storage', 0o777, true);

    $store = new FileGraphStore($project.'/storage');
    $store->ensureSchema();
    $store->putManifest(json_encode(['project' => 'demo', 'analyzedAt' => '2026-01-01T00:00:00+00:00', 'tabs' => []]));
    $store->putSubgraph('tab-a', json_encode([
        'nodes' => [[
            'id' => 'service::Demo',
            'label' => 'ReportBuilder@build',
            'type' => 'service',
            'data' => ['id' => 'service::Demo', 'label' => 'ReportBuilder@build', 'type' => 'service', 'cacheOps' => $cacheOps],
        ]],
        'edges' => [],
    ]));

    return (new ContextExporter($store, $project))->export(nodeId: 'service::Demo');
}

/** @return array<string, mixed> */
function cacheOp(array $overrides = []): array
{
    return array_merge([
        'kind' => 'read',
        'method' => 'get',
        'key' => 'users.index',
        'keyKind' => 'literal',
        'store' => '',
        'tags' => [],
        'ttl' => null,
    ], $overrides);
}

it('tells an agent which operation kind a node performs and on which key', function () {
    // Source alone does not show that a returned value came from cache, or which other method
    // clears the key it writes — which is exactly what the model is missing when it edits one.
    $out = exportWithCacheOps([cacheOp(['kind' => 'invalidate', 'method' => 'forget', 'key' => 'users.index'])]);

    expect($out)->toContain('## Cache Operations')
        ->toContain('invalidate forget "users.index"')
        ->toContain('(in ReportBuilder@build)');
});

it('carries the TTL, store and tags into the export', function () {
    $out = exportWithCacheOps([cacheOp([
        'kind' => 'write',
        'method' => 'put',
        'key' => 'dashboard:summary',
        'store' => 'redis',
        'tags' => ['dashboard', 'reports'],
        'ttl' => 600,
    ])]);

    expect($out)->toContain('write put "dashboard:summary" tags=dashboard,reports store=redis ttl=600s');
});

it('states a computed key rather than leaving it out', function () {
    // Silence reads as "this method does not use the cache", which is a different fact and the
    // wrong one.
    $out = exportWithCacheOps([cacheOp(['method' => 'forget', 'kind' => 'invalidate', 'key' => '', 'keyKind' => 'computed'])]);

    expect($out)->toContain('invalidate forget (computed key)');
});

it('omits the key for an operation that has none', function () {
    $out = exportWithCacheOps([cacheOp(['kind' => 'invalidate', 'method' => 'flush', 'key' => '', 'keyKind' => 'none'])]);

    expect($out)->toContain('- invalidate flush (in ');
});

it('leaves the section out entirely when nothing touches the cache', function () {
    expect(exportWithCacheOps([]))->not->toContain('## Cache Operations');
});
