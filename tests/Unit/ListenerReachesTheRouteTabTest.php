<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;

/**
 * @filamerce-covers \LaraMint\LaravelBrain\Graph\GraphBuilder
 */

/**
 * A route dispatches an event, and something listens for it. The listener has to be reachable
 * from that route's own tab — which is the point of showing listeners on the graphs that
 * dispatch the event, and the thing every unit test around it managed not to check.
 *
 * The tests either side of this one build their input by hand: the events tab is assembled from
 * hand-made definitions, and the listener edges are constructed directly. Neither runs a route
 * through a controller that dispatches, so an event that ended up with two node ids — one the
 * dispatch chain reached, one the listeners hung off — satisfied both while the route tab
 * quietly lost its listener.
 */
beforeEach(function () {
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository(['app' => ['name' => 'ListenerReach']]));
});

afterEach(function () {
    Container::setInstance(null);
});

function analysedDispatchRoute(): object
{
    return (new ProjectAnalyzer)->analyze(fixture('event-dispatch-route'), function () {});
}

it('puts the dispatched event on one node, not two', function () {
    $result = analysedDispatchRoute();

    $ids = [];

    foreach ($result->fullGraph->nodes() as $node) {
        if ($node->type === 'event' && ($node->data['fqcn'] ?? null) === 'App\\Events\\OrderPlaced') {
            $ids[] = $node->id;
        }
    }

    // Two ids for one class is the defect: the dispatch reaches one, the listeners hang off the
    // other, and no tab holds both.
    expect($ids)->toBe(['event::App\\Events\\OrderPlaced']);
});

it('keeps the listener reachable from the tab of the route that dispatches it', function () {
    $result = analysedDispatchRoute();
    $tab = $result->subgraphs['post-orders'] ?? null;

    expect($tab)->not->toBeNull('the fixture route has no tab');

    $types = [];

    foreach ($tab->nodes() as $node) {
        $types[$node->type][] = $node->data['fqcn'] ?? $node->id;
    }

    expect($types['event'] ?? [])->toContain('App\\Events\\OrderPlaced')
        ->and($types['listener'] ?? [])->toContain('App\\Listeners\\ChargeCard');
});
