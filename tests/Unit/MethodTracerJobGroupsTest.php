<?php

use LaraMint\LaravelBrain\Analysis\MethodTracer;

/** Trace one method of the job-groups fixture and hand back the tracer with it. */
function traceShipments(MethodTracer $tracer, string $method): array
{
    $root = fixture('job-groups-project');

    return $tracer->traceMethod('App\Services\ShipmentDispatcher', $method, ['App\\' => [$root.'/app']], $root);
}

/** The jobs of every group the tracer recorded, keyed by region id. */
function groupsOf(MethodTracer $tracer): array
{
    return array_map(fn (array $group): array => $group['jobs'], $tracer->jobGroups);
}

it('records a Bus chain as one region, in dispatch order', function () {
    $tracer = new MethodTracer;
    traceShipments($tracer, 'chainThroughTheFacade');

    expect(groupsOf($tracer))->toBe([
        'App\Services\ShipmentDispatcher::chainThroughTheFacade#chain0' => [
            'App\Jobs\ChargeOrder',
            'App\Jobs\NotifyWarehouse',
        ],
    ]);
});

it('numbers two chains in one method apart', function () {
    $tracer = new MethodTracer;
    traceShipments($tracer, 'twoChainsInOneMethod');

    expect(array_keys($tracer->jobGroups))->toBe([
        'App\Services\ShipmentDispatcher::twoChainsInOneMethod#chain0',
        'App\Services\ShipmentDispatcher::twoChainsInOneMethod#chain1',
    ]);
});

it('records a batch, and the transaction it was dispatched inside, as separate regions', function () {
    $tracer = new MethodTracer;
    $edges = traceShipments($tracer, 'batchInsideATransaction');

    expect(groupsOf($tracer))->toBe([
        'App\Services\ShipmentDispatcher::batchInsideATransaction#batch0' => [
            'App\Jobs\ReindexOrder',
            'App\Jobs\NotifyWarehouse',
        ],
    ]);

    // The same jobs are inside the transaction the batch was dispatched from, which is a second
    // region over the same nodes — the two answers do not replace one another.
    $jobEdges = array_values(array_filter($edges, fn ($edge) => $edge->type === 'job'));

    expect($jobEdges)->toHaveCount(2);
    expect($jobEdges[0]->inTransaction)->toBeTrue();
    expect($jobEdges[0]->transactionId)->toBe('App\Services\ShipmentDispatcher::batchInsideATransaction#0');
});

it('names the head of a pending dispatch without dispatching it twice', function () {
    $tracer = new MethodTracer;
    $edges = traceShipments($tracer, 'chainOnAPendingDispatch');

    expect(groupsOf($tracer))->toBe([
        'App\Services\ShipmentDispatcher::chainOnAPendingDispatch#chain0' => [
            'App\Jobs\ChargeOrder',
            'App\Jobs\NotifyWarehouse',
        ],
    ]);

    // `dispatch(new ChargeOrder)` is a dispatch in its own right and the tracer records it. If
    // the group recorded it as well, the graph would hold the same edge twice.
    $charges = array_filter($edges, fn ($edge) => $edge->calleeFqcn === 'App\Jobs\ChargeOrder');

    expect($charges)->toHaveCount(1);
});

it('records the head of withChain, which no dispatch verb would report', function () {
    $tracer = new MethodTracer;
    $edges = traceShipments($tracer, 'chainOnTheJobItself');

    expect(groupsOf($tracer))->toBe([
        'App\Services\ShipmentDispatcher::chainOnTheJobItself#chain0' => [
            'App\Jobs\ShipOrder',
            'App\Jobs\NotifyWarehouse',
        ],
    ]);

    expect(array_map(fn ($edge) => $edge->calleeFqcn, array_filter($edges, fn ($edge) => $edge->type === 'job')))
        ->toBe(['App\Jobs\ShipOrder', 'App\Jobs\NotifyWarehouse']);
});

it('keeps the readable jobs of a chain and flags the method as dispatching an unknown one', function () {
    $tracer = new MethodTracer;
    traceShipments($tracer, 'chainWithAnEntryNobodyCanRead');

    expect(groupsOf($tracer))->toBe([
        'App\Services\ShipmentDispatcher::chainWithAnEntryNobodyCanRead#chain0' => ['App\Jobs\NotifyWarehouse'],
    ]);

    expect($tracer->unresolvedDispatchers())
        ->toContain('App\Services\ShipmentDispatcher::chainWithAnEntryNobodyCanRead');
});

it('records nothing for a domain method that happens to be called chain', function () {
    $tracer = new MethodTracer;
    $edges = traceShipments($tracer, 'aDomainMethodThatHappensToBeCalledChain');

    expect($tracer->jobGroups)->toBe([]);
    expect(array_filter($edges, fn ($edge) => $edge->type === 'job'))->toBe([]);
});

it('still reads the chain and batch forms of the older Bus fixture', function () {
    $root = fixture('laravel-project');
    $tracer = new MethodTracer;

    $edges = $tracer->traceMethod('App\Services\BusDispatcher', 'dispatchAll', ['App\\' => [$root.'/app']], $root);

    $jobTargets = array_map(fn ($edge) => $edge->calleeFqcn, array_filter($edges, fn ($edge) => $edge->type === 'job'));

    expect($jobTargets)
        ->toContain('App\Jobs\ShipOrder')
        ->toContain('App\Jobs\ChargeOrder')
        ->toContain('App\Jobs\NotifyWarehouse')
        ->toContain('App\Jobs\ReindexOrder');

    expect(array_keys($tracer->jobGroups))->toBe([
        'App\Services\BusDispatcher::dispatchAll#chain0',
        'App\Services\BusDispatcher::dispatchAll#batch0',
    ]);
});
