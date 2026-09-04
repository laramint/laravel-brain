<?php

use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

/** Build the graph of one method of the job-groups fixture, regions stamped as a full run does. */
function shipmentGraph(string $method, ?GraphBuilder &$builder = null, bool $detectJobGroups = true): Graph
{
    $root = fixture('job-groups-project');
    $tracer = new MethodTracer(detectJobGroups: $detectJobGroups);
    $edges = $tracer->traceMethod('App\Services\ShipmentDispatcher', $method, ['App\\' => [$root.'/app']], $root);

    $builder = new GraphBuilder;
    $builder->setTransactionOpeners($tracer->transactionOpeners);
    $builder->setJobGroups(array_values($tracer->jobGroups));

    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], $edges, []);
    $builder->stampJobGroupRegions();

    return $graph;
}

/** The regions stamped on the node of a job, keyed by kind. */
function regionsOfJob(Graph $graph, string $label): array
{
    $node = null;

    foreach ($graph->nodes() as $candidate) {
        if ($candidate->type === 'job' && str_contains($candidate->label, $label)) {
            $node = $candidate;
        }
    }

    expect($node)->not->toBeNull();

    $regions = [];

    foreach ($node->data['regions'] ?? [] as $region) {
        $regions[$region['kind']] = $region;
    }

    return $regions;
}

it('numbers the members of a chain by the order they run in', function () {
    $graph = shipmentGraph('chainThroughTheFacade');

    expect(regionsOfJob($graph, 'ChargeOrder')['chain']['position'])->toBe(0);
    expect(regionsOfJob($graph, 'NotifyWarehouse')['chain']['position'])->toBe(1);
});

it('gives both members of a chain the same region id', function () {
    $graph = shipmentGraph('chainThroughTheFacade');

    expect(regionsOfJob($graph, 'ChargeOrder')['chain']['id'])
        ->toBe(regionsOfJob($graph, 'NotifyWarehouse')['chain']['id'])
        ->toBe('App\Services\ShipmentDispatcher::chainThroughTheFacade#chain0');
});

it('leaves the members of a batch unnumbered, because a batch states no order', function () {
    $graph = shipmentGraph('batchInsideATransaction');

    expect(regionsOfJob($graph, 'ReindexOrder')['batch']['position'])->toBeNull();
});

it('keeps a job in the transaction it was batched inside as well as in the batch', function () {
    $graph = shipmentGraph('batchInsideATransaction');

    $regions = regionsOfJob($graph, 'ReindexOrder');

    expect(array_keys($regions))->toContain('transaction', 'batch');
    expect($regions['transaction']['id'])->not->toBe($regions['batch']['id']);
});

it('draws the compensation path as a region of its own kind', function () {
    // The work after a rollback ran with the transaction already gone, so it is not part of it.
    $graph = shipmentGraph('compensatesAfterARollback');

    expect(array_keys(regionsOfJob($graph, 'ChargeOrder')))->toBe(['transaction']);
    expect(array_keys(regionsOfJob($graph, 'NotifyWarehouse')))->toBe(['rollback']);
});

it('calls a span a rollback once anything in it ran after the rollback', function () {
    // The same job is dispatched inside the transaction and again from the catch. Both calls are
    // in the one span — the whole try/catch sits inside the range `beginTransaction()` opened —
    // and only one kind can be drawn. Rollback is the one worth showing: it is the run that
    // happens after a failure, and a write there is not taken back with the rest.
    $graph = shipmentGraph('retriesTheSameJobAfterARollback');

    expect(array_keys(regionsOfJob($graph, 'ReindexOrder')))->toBe(['rollback']);
});

it('keeps every region a job was reached through, not just the first', function () {
    // ChargeOrder is chained in one method and dispatched inside a transaction in another. One
    // node, two regions: keeping a single one used to pair the id of one method's span with the
    // flag another method had set, and name a region after a transaction it was never in.
    $root = fixture('job-groups-project');
    $tracer = new MethodTracer;
    $edges = [];

    foreach (['chainThroughTheFacade', 'compensatesAfterARollback'] as $method) {
        $edges = [...$edges, ...$tracer->traceMethod('App\Services\ShipmentDispatcher', $method, ['App\\' => [$root.'/app']], $root)];
    }

    $builder = new GraphBuilder;
    $builder->setTransactionOpeners($tracer->transactionOpeners);
    $builder->setJobGroups(array_values($tracer->jobGroups));

    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], $edges, []);
    $builder->stampJobGroupRegions();

    $regions = regionsOfJob($graph, 'ChargeOrder');

    expect($regions['chain']['id'])->toBe('App\Services\ShipmentDispatcher::chainThroughTheFacade#chain0');
    expect($regions['transaction']['id'])->toBe('App\Services\ShipmentDispatcher::compensatesAfterARollback#0');
});

it('counts the place of a chain member the graph does not hold, so the rest keep their order', function () {
    $root = fixture('job-groups-project');
    $tracer = new MethodTracer;
    $edges = $tracer->traceMethod('App\Services\ShipmentDispatcher', 'chainThroughTheFacade', ['App\\' => [$root.'/app']], $root);

    $builder = new GraphBuilder;
    $builder->setJobGroups([[
        'id' => 'Probe::handle#chain0',
        'kind' => 'chain',
        // The first job is filtered out of this tab. Renumbering the survivors would say
        // NotifyWarehouse runs first, and it does not.
        'jobs' => ['App\Jobs\NotOnThisCanvas', 'App\Jobs\ChargeOrder', 'App\Jobs\NotifyWarehouse'],
    ]]);

    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], $edges, []);
    $builder->stampJobGroupRegions();

    expect(regionsOfJob($graph, 'ChargeOrder')['chain']['position'])->toBe(1);
    expect(regionsOfJob($graph, 'NotifyWarehouse')['chain']['position'])->toBe(2);
});

it('counts the place of a chain entry it could not name, end to end', function () {
    // The sibling of the test above, and the case that was wrong: there the member is filtered
    // out of the tab, here it could never be named at all. Both must leave the survivors saying
    // which position they actually run in — the whole point of drawing a chain as a sequence.
    $root = fixture('job-groups-project');
    $tracer = new MethodTracer;
    // Only this method. Tracing a second chain alongside it would put NotifyWarehouse at
    // position 1 of THAT group too, and the assertion would pass with the placeholder removed.
    $edges = $tracer->traceMethod('App\Services\ShipmentDispatcher', 'chainWithAnEntryNobodyCanRead', ['App\\' => [$root.'/app']], $root);

    $builder = new GraphBuilder;
    $builder->setJobGroups($tracer->jobGroups);

    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], $edges, []);
    $builder->stampJobGroupRegions();

    // `Bus::chain([$job, new NotifyWarehouse])` — the survivor is the SECOND job in that chain.
    expect(regionsOfJob($graph, 'NotifyWarehouse')['chain']['position'])->toBe(1);
});

it('does not stamp a region twice when the same group is stamped again', function () {
    $graph = shipmentGraph('chainThroughTheFacade', $builder);
    $builder->stampJobGroupRegions();

    $node = null;

    foreach ($graph->nodes() as $candidate) {
        if ($candidate->type === 'job' && str_contains($candidate->label, 'ChargeOrder')) {
            $node = $candidate;
        }
    }

    expect($node->data['regions'])->toHaveCount(1);
});

it('draws the transaction when chain and batch detection is switched off', function () {
    // The two are separate features with separate switches: the batch here comes from the
    // dispatch-site detector, the transaction from the scope collector, and turning the first
    // off must leave the second exactly as it was.
    $builder = null;
    $graph = shipmentGraph('batchInsideATransaction', $builder, detectJobGroups: false);

    expect(array_keys(regionsOfJob($graph, 'ReindexOrder')))->toBe(['transaction']);
});

it('draws the batch when detection is left on, on the same method', function () {
    // The other position of the same switch, on the same fixture: without this the test above
    // would pass just as well against a fixture that never had a batch in it.
    $graph = shipmentGraph('batchInsideATransaction');

    expect(array_keys(regionsOfJob($graph, 'ReindexOrder')))->toBe(['transaction', 'batch']);
});
