<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\ContainerBindingRecord;
use LaraMint\LaravelBrain\Analysis\ContainerBindingRegistry;
use LaraMint\LaravelBrain\Analysis\FacadeRecord;
use LaraMint\LaravelBrain\Analysis\FacadeRegistry;
use LaraMint\LaravelBrain\Analysis\Reachability\ClassInventory;
use LaraMint\LaravelBrain\Analysis\Reachability\ClassStringIndex;
use LaraMint\LaravelBrain\Analysis\Reachability\DeclaredClass;
use LaraMint\LaravelBrain\Analysis\Reachability\EntryPoint;
use LaraMint\LaravelBrain\Analysis\Reachability\ReachabilityAnalyzer;
use LaraMint\LaravelBrain\Analysis\Reachability\ReachabilityReport;
use LaraMint\LaravelBrain\Analysis\Reachability\UnreachedClass;
use LaraMint\LaravelBrain\Graph\Edge;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\Node;

function declaredClass(string $fqcn, string $surface = 'class', string $parent = '', array $interfaces = [], array $traits = []): DeclaredClass
{
    return new DeclaredClass(
        fqcn: $fqcn,
        file: '/app/'.str_replace('\\', '/', $fqcn).'.php',
        surface: $surface,
        kind: ClassInventory::kindOf($fqcn, $surface),
        parent: $parent,
        interfaces: $interfaces,
        traits: $traits,
    );
}

/** @return array<string, UnreachedClass> */
function unreachedByFqcn(ReachabilityReport $report): array
{
    $byFqcn = [];
    foreach ($report->unreached as $class) {
        $byFqcn[$class->fqcn] = $class;
    }

    return $byFqcn;
}

it('reaches what a chain from an entry point arrives at, and nothing else', function () {
    $graph = new Graph;
    $graph->addNode(new Node('route::GET::/orders', 'route', 'GET /orders'));
    $graph->addNode(new Node('action::App\Http\Controllers\OrderController::index', 'action', 'index', [
        'fqcn' => 'App\Http\Controllers\OrderController',
    ]));
    $graph->addNode(new Node('app_services_orderservice::place', 'service', 'OrderService@place', [
        'fqcn' => 'App\Services\OrderService',
    ]));
    $graph->addEdge(new Edge('e0', 'route::GET::/orders', 'action::App\Http\Controllers\OrderController::index', '', 'flow'));
    $graph->addEdge(new Edge('e1', 'action::App\Http\Controllers\OrderController::index', 'app_services_orderservice::place', '', 'flow'));

    $inventory = ClassInventory::of([
        'App\Http\Controllers\OrderController' => declaredClass('App\Http\Controllers\OrderController'),
        'App\Services\OrderService' => declaredClass('App\Services\OrderService'),
        'App\Jobs\ArchiveOrders' => declaredClass('App\Jobs\ArchiveOrders'),
    ]);

    $report = (new ReachabilityAnalyzer)->analyze(
        $graph,
        [new EntryPoint(
            kind: EntryPoint::KIND_ROUTE,
            label: 'GET /orders',
            fqcn: 'App\Http\Controllers\OrderController',
            nodeIds: ['route::GET::/orders'],
        )],
        $inventory,
    );

    expect(array_keys(unreachedByFqcn($report)))->toBe(['App\Jobs\ArchiveOrders'])
        ->and($report->classesDeclared)->toBe(3)
        ->and($report->classesReached)->toBe(2);
});

it('counts an entry point class as reached even when no node carries its name', function () {
    // `$schedule->job(RebuildIndex::class)` builds a schedule node keyed by a hash of the
    // entry, carrying the target as a plain string and nothing the graph can key by class; if
    // nothing else dispatches the job there is no node for it anywhere. It is a root — the
    // scheduler runs it every hour — and reporting it as unreached would be plainly wrong.
    $graph = new Graph;
    $graph->addNode(new Node('schedule::'.md5('jobApp\Jobs\RebuildIndexhourly'), 'schedule', 'RebuildIndex (hourly)', [
        'type' => 'job',
        'target' => 'App\Jobs\RebuildIndex',
    ]));

    $report = (new ReachabilityAnalyzer)->analyze(
        $graph,
        [new EntryPoint(
            kind: EntryPoint::KIND_SCHEDULE,
            label: 'RebuildIndex (hourly)',
            fqcn: 'App\Jobs\RebuildIndex',
            nodeIds: ['schedule::'.md5('jobApp\Jobs\RebuildIndexhourly')],
        )],
        ClassInventory::of([
            'App\Jobs\RebuildIndex' => declaredClass('App\Jobs\RebuildIndex'),
        ]),
    );

    expect($report->unreached)->toBe([]);
});

it('names every reference it found and could not follow, so unreached is never read as dead', function () {
    // The distinction this whole tab rests on: each of these classes is reachable at runtime
    // through a mechanism that leaves no call for the tracer to follow, and each must arrive
    // at the reader carrying the reason.
    $bindings = new ContainerBindingRegistry;
    $bindings->add(new ContainerBindingRecord(
        abstractFqcn: 'App\Contracts\Importer',
        concreteFqcn: 'App\Services\LegacyImporter',
        providerFqcn: 'App\Providers\AppServiceProvider',
        kind: 'singleton',
    ));

    $facades = new FacadeRegistry;
    $facades->add(new FacadeRecord('App\Facades\Money', 'money', 'App\Support\MoneyManager'));

    $inventory = ClassInventory::of([
        'App\Services\LegacyImporter' => declaredClass('App\Services\LegacyImporter'),
        'App\Support\MoneyManager' => declaredClass('App\Support\MoneyManager'),
        'App\Jobs\RebuildIndex' => declaredClass('App\Jobs\RebuildIndex'),
        'App\Support\BaseWorkflow' => declaredClass('App\Support\BaseWorkflow', 'abstract_class'),
        'App\Services\OrderService' => declaredClass('App\Services\OrderService', parent: 'App\Support\BaseWorkflow'),
        'App\Support\ReportRenderer' => declaredClass('App\Support\ReportRenderer'),
        'App\Jobs\ArchiveOrders' => declaredClass('App\Jobs\ArchiveOrders'),
    ]);

    $graph = new Graph;
    $graph->addNode(new Node('app_services_orderservice::place', 'service', 'OrderService@place', [
        'fqcn' => 'App\Services\OrderService',
    ]));

    $report = (new ReachabilityAnalyzer)->analyze(
        $graph,
        [new EntryPoint(
            kind: EntryPoint::KIND_ROUTE,
            label: 'GET /orders',
            fqcn: 'App\Services\OrderService',
        )],
        $inventory,
        $bindings,
        $facades,
        ClassStringIndex::scan(fixture('reachability-project'), ['app']),
        ClassStringIndex::scan(fixture('reachability-project'), ['config']),
    );

    $byFqcn = unreachedByFqcn($report);

    expect($byFqcn['App\Services\LegacyImporter']->unfollowableReferences)
        ->toContain(UnreachedClass::REFERENCE_CONTAINER_BINDING)
        ->and($byFqcn['App\Support\MoneyManager']->unfollowableReferences)
        ->toContain(UnreachedClass::REFERENCE_FACADE)
        ->and($byFqcn['App\Jobs\RebuildIndex']->unfollowableReferences)
        ->toContain(UnreachedClass::REFERENCE_CONFIG)
        ->and($byFqcn['App\Support\BaseWorkflow']->unfollowableReferences)
        ->toContain(UnreachedClass::REFERENCE_INHERITED)
        ->and($byFqcn['App\Support\ReportRenderer']->unfollowableReferences)
        ->toContain(UnreachedClass::REFERENCE_CLASS_STRING)
        // The one class in the set that nothing names at all. If this ever picks up a
        // reference the hints have stopped discriminating and the report says nothing.
        ->and($byFqcn['App\Jobs\ArchiveOrders']->unfollowableReferences)->toBe([]);
});

it('files kinds the tracer has no edge for apart from the ones it does', function () {
    // A service provider is booted, an exception is thrown; neither is ever called, so their
    // absence from the graph is the expected outcome. Mixed in with the jobs they would be a
    // hundred non-findings burying the real ones.
    $inventory = ClassInventory::of([
        'App\Providers\AppServiceProvider' => declaredClass('App\Providers\AppServiceProvider'),
        'App\Exceptions\OrderFailed' => declaredClass('App\Exceptions\OrderFailed'),
        'App\Jobs\ArchiveOrders' => declaredClass('App\Jobs\ArchiveOrders'),
    ]);

    $report = (new ReachabilityAnalyzer)->analyze(new Graph, [], $inventory);

    expect(array_keys($report->unreachedByKind()))->toBe(['job'])
        ->and(array_keys($report->unreachedByKind(tracerBlind: true)))->toBe(['exception', 'service_provider']);
});

it('groups the unreached largest kind first', function () {
    // "17 jobs nothing dispatches" is the sentence the tab exists to answer, so the biggest
    // group has to be the first one a reader meets.
    $classes = [
        'App\Support\Lonely' => declaredClass('App\Support\Lonely'),
        'App\Jobs\A' => declaredClass('App\Jobs\A'),
        'App\Jobs\B' => declaredClass('App\Jobs\B'),
    ];

    $report = (new ReachabilityAnalyzer)->analyze(new Graph, [], ClassInventory::of($classes));

    expect(array_keys($report->unreachedByKind()))->toBe(['job', 'service']);
});

it('starts a walk from an entry point that has no node id, only a class', function () {
    // A queued listener is not a node the graph builds by name: its node id is a slug of the
    // FQCN and the method, which nothing outside GraphBuilder can construct. Finding it by
    // class is the only way, and without it everything a queued listener calls — for many
    // applications the whole asynchronous half — reports as reached by nothing.
    $graph = new Graph;
    $graph->addNode(new Node('app_listeners_notifywarehouse::handle', 'listener', 'NotifyWarehouse@handle', [
        'fqcn' => 'App\Listeners\NotifyWarehouse',
    ]));
    $graph->addNode(new Node('app_services_warehouseclient::notify', 'service', 'WarehouseClient@notify', [
        'fqcn' => 'App\Services\WarehouseClient',
    ]));
    $graph->addEdge(new Edge('e0', 'app_listeners_notifywarehouse::handle', 'app_services_warehouseclient::notify', '', 'flow'));

    $report = (new ReachabilityAnalyzer)->analyze(
        $graph,
        [new EntryPoint(
            kind: EntryPoint::KIND_QUEUED_LISTENER,
            label: 'NotifyWarehouse',
            fqcn: 'App\Listeners\NotifyWarehouse',
        )],
        ClassInventory::of([
            'App\Listeners\NotifyWarehouse' => declaredClass('App\Listeners\NotifyWarehouse'),
            'App\Services\WarehouseClient' => declaredClass('App\Services\WarehouseClient'),
        ]),
    );

    expect($report->unreached)->toBe([]);
});
