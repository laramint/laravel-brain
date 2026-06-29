<?php

use LaraMint\LaravelBrain\Analysis\MethodTracer;

function dynamicDispatcherEdges(MethodTracer $tracer, string $method): array
{
    $root = fixture('laravel-project');

    return $tracer->traceMethod('App\Services\DynamicDispatcher', $method, ['App\\' => [$root.'/app']], $root);
}

function jobTargets(array $edges): array
{
    return array_map(
        fn ($edge) => $edge->calleeFqcn,
        array_filter($edges, fn ($edge) => $edge->type === 'job'),
    );
}

it('reports a method that dispatches a job it cannot resolve statically', function () {
    $tracer = new MethodTracer;
    $edges = dynamicDispatcherEdges($tracer, 'run');

    // The literal dispatch is still resolved to a job edge.
    expect(jobTargets($edges))->toContain('App\Jobs\ProcessReport');
    // ...and the variable / non-literal dispatches flag the method as unresolved.
    expect($tracer->unresolvedDispatchers())->toContain('App\Services\DynamicDispatcher::run');
});

it('resolves the literal job of a partial chain and still flags the opaque entry', function () {
    $tracer = new MethodTracer;
    $edges = dynamicDispatcherEdges($tracer, 'chainOnly');

    expect(jobTargets($edges))->toContain('App\Jobs\ProcessReport');
    expect($tracer->unresolvedDispatchers())->toContain('App\Services\DynamicDispatcher::chainOnly');
});

it('flags a single Bus::dispatch of an opaque job', function () {
    $tracer = new MethodTracer;
    dynamicDispatcherEdges($tracer, 'busSingle');

    expect($tracer->unresolvedDispatchers())->toContain('App\Services\DynamicDispatcher::busSingle');
});

it('does not flag Livewire-style event dispatches or no-arg calls', function () {
    $tracer = new MethodTracer;
    dynamicDispatcherEdges($tracer, 'livewireEvents');

    expect($tracer->unresolvedDispatchers())->not->toContain('App\Services\DynamicDispatcher::livewireEvents');
});

it('never emits the unresolved marker as a real edge', function () {
    $tracer = new MethodTracer;
    $edges = dynamicDispatcherEdges($tracer, 'run');

    expect(array_filter($edges, fn ($e) => $e->type === MethodTracer::UNRESOLVED_DISPATCH))->toBe([]);
    expect(array_filter($edges, fn ($e) => $e->calleeFqcn === ''))->toBe([]);
});

it('does not flag a fully-resolved dispatcher as unresolved', function () {
    $root = fixture('laravel-project');
    $tracer = new MethodTracer;
    $tracer->traceMethod('App\Services\QueueDispatcher', 'run', ['App\\' => [$root.'/app']], $root);

    expect($tracer->unresolvedDispatchers())->not->toContain('App\Services\QueueDispatcher::run');
});

it('accumulates unresolved dispatchers across traceMethod calls', function () {
    $tracer = new MethodTracer;
    dynamicDispatcherEdges($tracer, 'run');
    dynamicDispatcherEdges($tracer, 'busSingle');

    expect($tracer->unresolvedDispatchers())
        ->toContain('App\Services\DynamicDispatcher::run')
        ->toContain('App\Services\DynamicDispatcher::busSingle');
});

it('resolves and flags a configured custom dispatch helper', function () {
    $configured = new MethodTracer(['dispatch_with_retries']);
    $edges = dynamicDispatcherEdges($configured, 'withRetries');

    expect(jobTargets($edges))->toContain('App\Jobs\ProcessReport');
    expect($configured->unresolvedDispatchers())->toContain('App\Services\DynamicDispatcher::withRetries');
});

it('ignores a custom dispatch helper that is not configured', function () {
    $default = new MethodTracer;
    $edges = dynamicDispatcherEdges($default, 'withRetries');

    expect(jobTargets($edges))->not->toContain('App\Jobs\ProcessReport');
    expect($default->unresolvedDispatchers())->not->toContain('App\Services\DynamicDispatcher::withRetries');
});
