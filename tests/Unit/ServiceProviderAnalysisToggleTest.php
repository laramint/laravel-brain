<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;

/**
 * `laravel-brain.service_providers.enabled` has to mean "the provider tree is not walked", not
 * "the answer is thrown away". Both positions are exercised, and the off case asserts the absence
 * of the progress step as well as the absence of the output — a gate placed around the result
 * instead of around the walk would still emit the step it paid for.
 */
function analyzeDeferredFixtureWith(?bool $enabled): array
{
    $config = ['app' => ['name' => 'ToggleTest']];
    if ($enabled !== null) {
        $config['laravel-brain'] = ['service_providers' => ['enabled' => $enabled]];
    }

    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository($config));

    $steps = [];
    try {
        $result = (new ProjectAnalyzer)->analyze(
            fixture('deferred-providers-project'),
            function (string $event, array $data) use (&$steps): void {
                if (isset($data['step']) && is_string($data['step'])) {
                    $steps[] = $event.':'.$data['step'];
                }
            },
        );
    } finally {
        Container::setInstance(null);
    }

    return ['graph' => $result->fullGraph, 'steps' => $steps];
}

/** @return array<string, array<string, mixed>> provider FQCN => node data */
function providerNodeDataIn(Graph $graph): array
{
    $byFqcn = [];
    foreach ($graph->nodes() as $node) {
        if ($node->type === 'service_provider') {
            $byFqcn[(string) $node->data['fqcn']] = $node->data;
        }
    }

    return $byFqcn;
}

it('reads provider deferral by default', function () {
    ['graph' => $graph, 'steps' => $steps] = analyzeDeferredFixtureWith(null);

    expect($steps)->toContain('step:done:deferred_providers')
        ->and(providerNodeDataIn($graph))
        ->toHaveKey('App\Providers\SilentServiceProvider');
});

it('reads provider deferral when the switch is on', function () {
    ['graph' => $graph, 'steps' => $steps] = analyzeDeferredFixtureWith(true);

    expect($steps)->toContain('step:done:deferred_providers');

    $providers = providerNodeDataIn($graph);
    expect($providers['App\Providers\ReportServiceProvider'])
        ->toHaveKey('deferred', true)
        ->and($providers['App\Providers\SilentServiceProvider'])
        ->toHaveKey('deferredDefect', 'never-boots');

    $bootEdges = array_filter($graph->edges(), fn ($e) => $e->type === 'boots-deferred-provider');
    expect($bootEdges)->not->toBeEmpty();
});

it('does not walk the provider tree when the switch is off', function () {
    ['graph' => $graph, 'steps' => $steps] = analyzeDeferredFixtureWith(false);

    // Neither half of the step is emitted, because neither half happens.
    expect($steps)->not->toContain('step:start:deferred_providers')
        ->not->toContain('step:done:deferred_providers');

    $bootEdges = array_filter($graph->edges(), fn ($e) => $e->type === 'boots-deferred-provider');
    expect($bootEdges)->toBeEmpty();

    foreach (providerNodeDataIn($graph) as $data) {
        expect($data)->not->toHaveKey('deferred')
            ->not->toHaveKey('provides')
            ->not->toHaveKey('deferredDefect');
    }
});

it('leaves container bindings alone when provider deferral is off', function () {
    // The switch covers the deferral read only. Bindings are a separate walk with a separate
    // path setting, and a provider still reaches the graph through the binding it registers.
    ['graph' => $graph, 'steps' => $steps] = analyzeDeferredFixtureWith(false);

    expect($steps)->toContain('step:done:container_bindings')
        ->and(providerNodeDataIn($graph))
        ->toHaveKey('App\Providers\ReportServiceProvider');
});

it('ships the switch on in the published config', function () {
    // The code's own fallback and the published file are two separate defaults, and a
    // disagreement between them is invisible until someone publishes the config.
    $published = require __DIR__.'/../../config/laravel-brain.php';

    expect($published)->toHaveKey('service_providers')
        ->and($published['service_providers']['enabled'])->toBeTrue();
});
