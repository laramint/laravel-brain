<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Analysis\Reachability\EntryPoint;
use LaraMint\LaravelBrain\Analysis\Reachability\ReachabilityReport;
use LaraMint\LaravelBrain\Analysis\Reachability\UnreachedClass;
use LaraMint\LaravelBrain\Graph\GraphSplitter;

function reachabilityTabNodes(ReachabilityReport $report): array
{
    $tab = (new GraphSplitter)->buildReachabilityTab($report, 'proj', '2026-01-01T00:00:00Z');

    $byId = [];
    foreach ($tab['graph']->nodes() as $node) {
        $byId[$node->id] = $node;
    }

    return $byId;
}

function bootBrainConfig(array $overrides = []): void
{
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository([
        'app' => ['name' => 'Reachability'],
        'laravel-brain' => array_replace_recursive(
            require __DIR__.'/../../config/laravel-brain.php',
            $overrides,
        ),
    ]));
}

afterEach(function () {
    Container::setInstance(null);
});

it('lays the tab out as entry points, then what nothing reaches, then what it cannot follow', function () {
    $report = new ReachabilityReport(
        entryPoints: [new EntryPoint(EntryPoint::KIND_ROUTE, 'GET /orders', 'App\Http\Controllers\OrderController')],
        unreached: [
            new UnreachedClass('App\Jobs\ArchiveOrders', '/app/Jobs/ArchiveOrders.php', 'job'),
            new UnreachedClass('App\Providers\AppServiceProvider', '/app/Providers/AppServiceProvider.php', 'service_provider', [], true),
        ],
        classesDeclared: 3,
        classesReached: 1,
    );

    $nodes = reachabilityTabNodes($report);

    expect($nodes['reachability::entry-points']->label)->toBe('Entry points (1)')
        ->and($nodes['reachability::entry-points::route']->label)->toBe('Routes (1)')
        ->and($nodes['reachability::unreached']->label)->toBe('Nothing reaches these from an entry point (1)')
        ->and($nodes['reachability::unreached::job']->label)->toBe('Jobs (1)')
        ->and($nodes['reachability::unfollowed']->label)->toBe('Outside what the tracer follows (1)')
        ->and($nodes['reachability::unfollowed::service_provider']->label)->toBe('Service providers (1)');
});

it('carries the caveat onto every unreached node rather than leaving it in a heading', function () {
    // A reader clicks a class and gets a panel; if the qualification only lives on a group
    // heading three levels up, the panel says "nothing reaches this" and nothing else, which
    // is the reading that gets code deleted.
    $report = new ReachabilityReport(
        entryPoints: [],
        unreached: [new UnreachedClass(
            'App\Services\LegacyImporter',
            '/app/Services/LegacyImporter.php',
            'service',
            [UnreachedClass::REFERENCE_CONTAINER_BINDING],
        )],
        classesDeclared: 1,
        classesReached: 0,
    );

    $node = reachabilityTabNodes($report)['unreached::app_services_legacyimporter'];

    expect($node->data['unfollowableReferences'])->toBe([UnreachedClass::REFERENCE_CONTAINER_BINDING])
        ->and($node->data['note'])->toContain('not about whether the code runs')
        ->and($node->data['note'])->not->toContain('dead');
});

it('omits a section that has nothing in it', function () {
    $report = new ReachabilityReport(
        entryPoints: [new EntryPoint(EntryPoint::KIND_ROUTE, 'GET /orders')],
        unreached: [],
        classesDeclared: 1,
        classesReached: 1,
    );

    expect(reachabilityTabNodes($report))->not->toHaveKey('reachability::unreached');
});

it('builds no tab at all for a project with neither entry points nor classes', function () {
    $report = new ReachabilityReport([], [], 0, 0);

    expect((new GraphSplitter)->buildReachabilityTab($report, 'proj', '2026-01-01T00:00:00Z'))->toBeNull();
});

it('surfaces the tab under its own sidebar category', function () {
    $report = new ReachabilityReport(
        entryPoints: [new EntryPoint(EntryPoint::KIND_ROUTE, 'GET /orders')],
        unreached: [new UnreachedClass('App\Jobs\ArchiveOrders', '/app/Jobs/ArchiveOrders.php', 'job')],
        classesDeclared: 2,
        classesReached: 1,
    );

    $tab = (new GraphSplitter)->buildReachabilityTab($report, 'proj', '2026-01-01T00:00:00Z');

    expect($tab['id'])->toBe('reachability--inventory')
        ->and($tab['manifest']->category)->toBe('Reachability')
        ->and($tab['manifest']->label)->toBe('Reachability')
        ->and($tab['manifest']->routeCount)->toBe(1);
});

it('reports a real project end to end', function () {
    bootBrainConfig();

    $result = (new ProjectAnalyzer)->analyze(fixture('reachability-project'), function () {});
    $report = $result->reachability;

    $entryPointLabels = [];
    foreach ($report->entryPoints as $entryPoint) {
        $entryPointLabels[$entryPoint->kind][] = $entryPoint->label;
    }

    $unreached = [];
    foreach ($report->unreached as $class) {
        $unreached[$class->fqcn] = $class->unfollowableReferences;
    }

    expect($entryPointLabels)->toBe([
        EntryPoint::KIND_ROUTE => ['POST /orders'],
        EntryPoint::KIND_COMMAND => ['orders:sync'],
        EntryPoint::KIND_QUEUED_LISTENER => ['NotifyWarehouse'],
    ])
        // Dispatched from a traced service; the graph already knew about it.
        ->and($unreached)->not->toHaveKey('App\Jobs\SendReceipt')
        // Handled by an event the graph reaches, so not a root and not a finding.
        ->and($unreached)->not->toHaveKey('App\Listeners\LogOrder')
        ->and($unreached['App\Jobs\ArchiveOrders'])->toBe([])
        ->and($unreached['App\Jobs\RebuildIndex'])->toBe([UnreachedClass::REFERENCE_CONFIG])
        ->and($unreached['App\Services\LegacyImporter'])->toContain(UnreachedClass::REFERENCE_CONTAINER_BINDING)
        ->and($unreached['App\Support\BaseWorkflow'])->toBe([UnreachedClass::REFERENCE_INHERITED])
        ->and($unreached['App\Support\ReportRenderer'])->toBe([UnreachedClass::REFERENCE_CLASS_STRING])
        ->and($result->subgraphs)->toHaveKey('reachability--inventory');
});

it('builds no reachability tab when the feature is switched off', function () {
    bootBrainConfig(['reachability' => ['enabled' => false]]);

    $result = (new ProjectAnalyzer)->analyze(fixture('reachability-project'), function () {});

    expect($result->reachability)->toBeNull()
        ->and($result->subgraphs)->not->toHaveKey('reachability--inventory');
});
