<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\ContainerBindingAnalyzer;
use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Analysis\ServiceProviderAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

function deferredProviderGraph(): Graph
{
    $fixture = fixture('deferred-providers-project');

    $routes = (new RouteAnalyzer)->analyze($fixture);
    $controllers = (new ControllerAnalyzer)->analyze($fixture, $routes);
    $traces = (new MethodTracer)->trace($controllers, [], $fixture);

    $builder = new GraphBuilder;
    $graph = $builder->build(
        'deferred', $routes, new MiddlewareRegistry([], [], []), $controllers, $traces, [], $fixture, [],
        (new ContainerBindingAnalyzer)->analyze($fixture),
    );
    $builder->addServiceProviders((new ServiceProviderAnalyzer)->analyze($fixture));

    return $graph;
}

/** @return array<string, array<string, mixed>> provider FQCN => node data */
function deferredProviderNodes(Graph $graph): array
{
    $byFqcn = [];
    foreach ($graph->nodes() as $node) {
        if ($node->type === 'service_provider') {
            $byFqcn[(string) $node->data['fqcn']] = $node->data;
        }
    }

    return $byFqcn;
}

it('marks deferred providers and lists what each one provides', function () {
    $providers = deferredProviderNodes(deferredProviderGraph());

    expect($providers['App\Providers\ReportServiceProvider'])
        ->toHaveKey('deferred', true)
        ->toHaveKey('provides', ['App\Contracts\ReportBuilderInterface']);
});

it('wires resolving a provided service to the provider it would boot', function () {
    $edges = array_values(array_filter(
        deferredProviderGraph()->edges(),
        fn ($e) => $e->type === 'boots-deferred-provider',
    ));

    expect($edges)->not->toBeEmpty();

    $targets = array_unique(array_map(fn ($e) => $e->target, $edges));
    expect($targets)->toBe(['service_provider::app_providers_reportserviceprovider']);

    $sources = array_map(fn ($e) => $e->source, $edges);
    expect($sources)->toContain('interface::app_contracts_reportbuilderinterface')
        ->and($edges[0]->label)->toBe('resolving boots ReportServiceProvider');
});

it('draws no boot edge to an eagerly registered provider', function () {
    // LegacyDeferServiceProvider provides Ledger, and the graph has a Ledger node — but the
    // provider is eager, so it is already loaded before anything resolves Ledger. Saying
    // "resolving this boots that" would be false.
    $graph = deferredProviderGraph();

    $ledgerNodes = array_filter($graph->nodes(), fn ($n) => ($n->data['fqcn'] ?? null) === 'App\Support\Ledger');
    expect($ledgerNodes)->not->toBeEmpty();

    $legacyEdges = array_filter(
        $graph->edges(),
        fn ($e) => $e->type === 'boots-deferred-provider'
            && $e->target === 'service_provider::app_providers_legacydeferserviceprovider',
    );

    expect($legacyEdges)->toBeEmpty();
});

it('draws no boot edge for a service the deferred provider never promised', function () {
    // ClockServiceProvider binds ClockInterface but provides SystemClock, so no resolution of
    // ClockInterface registers it — which is the whole defect.
    $graph = deferredProviderGraph();

    $clockEdges = array_filter(
        $graph->edges(),
        fn ($e) => $e->type === 'boots-deferred-provider'
            && $e->target === 'service_provider::app_providers_clockserviceprovider',
    );

    expect($clockEdges)->toBeEmpty();
});

it('flags a deferred provider that provides nothing', function () {
    $providers = deferredProviderNodes(deferredProviderGraph());

    expect($providers['App\Providers\SilentServiceProvider'])
        ->toHaveKey('deferredDefect', 'never-boots')
        ->and($providers['App\Providers\SilentServiceProvider']['deferredDefectMessage'])
        ->toContain('never run');
});

it('flags a deferred provider whose provides() names something it does not bind', function () {
    $providers = deferredProviderNodes(deferredProviderGraph());

    expect($providers['App\Providers\ClockServiceProvider'])
        ->toHaveKey('deferredDefect', 'unbacked-provides')
        ->and($providers['App\Providers\ClockServiceProvider']['deferredDefectMessage'])
        ->toContain('SystemClock');
});

it('flags the pre-5.8 $defer property as the eager provider it now is', function () {
    $providers = deferredProviderNodes(deferredProviderGraph());

    expect($providers['App\Providers\LegacyDeferServiceProvider'])
        ->toHaveKey('deferred', false)
        ->toHaveKey('deferredDefect', 'legacy-defer');
});

it('leaves sound deferred providers unflagged', function () {
    $providers = deferredProviderNodes(deferredProviderGraph());

    expect($providers['App\Providers\ReportServiceProvider'])->not->toHaveKey('deferredDefect')
        // $defer next to DeferrableProvider is redundant, not broken.
        ->and($providers['App\Providers\AliasedLedgerServiceProvider'])->not->toHaveKey('deferredDefect')
        ->and($providers['App\Providers\DynamicProvidesServiceProvider'])->not->toHaveKey('deferredDefect')
        ->and($providers['App\Providers\EventTriggeredServiceProvider'])->not->toHaveKey('deferredDefect')
        ->and($providers['App\Providers\LoopBoundServiceProvider'])->not->toHaveKey('deferredDefect')
        ->and($providers['App\Providers\ClosureProvidesServiceProvider'])->not->toHaveKey('deferredDefect')
        ->and($providers['App\Providers\PartialProvidesServiceProvider'])->not->toHaveKey('deferredDefect');
});

it('shows the events that register an otherwise unreachable provider', function () {
    $providers = deferredProviderNodes(deferredProviderGraph());

    expect($providers['App\Providers\EventTriggeredServiceProvider'])
        ->toHaveKey('bootsOnEvent', ['App\Events\BillingRunStarted']);
});

it('adds no node for an eager provider that has nothing to report', function () {
    $providers = deferredProviderNodes(deferredProviderGraph());

    expect($providers)->not->toHaveKey('App\Providers\AppServiceProvider');
});
