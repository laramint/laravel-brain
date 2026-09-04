<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\BroadcastAnalyzer;
use LaraMint\LaravelBrain\Analysis\ChannelAnalyzer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

/**
 * @filamerce-covers \LaraMint\LaravelBrain\Graph\GraphBuilder
 */

/** The graph for the broadcast fixture, with channels and broadcasts both wired. */
function broadcastGraph(): object
{
    $project = fixture('broadcast-project');
    $channels = (new ChannelAnalyzer)->analyze($project);

    $builder = new GraphBuilder;
    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], [], []);
    $builder->addChannels($channels);
    $builder->addBroadcasts((new BroadcastAnalyzer(['app/Events']))->analyze($project), $channels);

    return $graph;
}

/** Edge labels leaving the given event. */
function channelsReachedBy(object $graph, string $short): array
{
    $from = 'event::App\\Events\\'.$short;
    $names = [];

    foreach ($graph->edges() as $edge) {
        if ($edge->source !== $from) {
            continue;
        }

        $target = $graph->getNode($edge->target);
        $names[] = $target?->data['name'] ?? $edge->target;
    }

    sort($names);

    return $names;
}

function broadcastData(object $graph, string $short): array
{
    return $graph->getNode('event::App\\Events\\'.$short)?->data['broadcast'] ?? [];
}

it('joins an event to the channel route that authorises it, across two spellings', function () {
    // The whole point of the pass. The event names `orders.{id}`, the route names
    // `orders.{orderId}`, and they are one channel — a string comparison would say otherwise.
    expect(channelsReachedBy(broadcastGraph(), 'OrderShipped'))->toBe(['orders.{orderId}']);
});

it('joins a presence channel and a plain one the same way', function () {
    $graph = broadcastGraph();

    expect(channelsReachedBy($graph, 'RoomJoined'))->toBe(['presence-room.{roomId}'])
        ->and(channelsReachedBy($graph, 'Announced'))->toBe(['announcements']);
});

it('draws no edge for a channel whose name is only known at runtime', function () {
    // Guessing which declared channel it meant would be the one thing worse than saying nothing.
    $graph = broadcastGraph();

    expect(channelsReachedBy($graph, 'ChannelNobodyCanRead'))->toBe([])
        ->and(broadcastData($graph, 'ChannelNobodyCanRead')['channels'][0])
        ->toMatchArray(['computed' => true, 'declared' => false, 'kind' => 'private']);
});

it('does not let a placeholder match a literal segment', function () {
    // `orders.summary` and `orders.{orderId}` are the same shape and the same length, and are
    // not the same channel. This is the case that discriminates the segment rule: a length check
    // alone lets it through, and a placeholder standing in for a literal would marry every
    // parameterised channel to every other.
    expect(channelsReachedBy(broadcastGraph(), 'OrderPinned'))->toBe([]);
});

it('does not let a placeholder on the event side swallow a declared literal', function () {
    // The mirror of the case above, and the one that actually exercises the rule: the event
    // names `{team}.updates` and `orders.updates` is declared. Only the second segment agrees.
    // Without the placeholder-against-literal check the first segment matches anything, and the
    // event is reported as broadcasting on a channel it may well never touch.
    expect(channelsReachedBy(broadcastGraph(), 'TeamFeedUpdated'))->toBe([]);
});

it('refuses a computed name even when its shape would fit a declared channel', function () {
    // `{scope}.{ref}` fits `{tenant}.{stream}` exactly, and that is not evidence: nothing literal
    // survived, so which channel the event meant is unknown. The guard is what stops a shape
    // coincidence being reported as a fact.
    $graph = broadcastGraph();

    expect(channelsReachedBy($graph, 'WhollyComputedChannel'))->toBe([])
        ->and(broadcastData($graph, 'WhollyComputedChannel')['channels'][0])
        ->toMatchArray(['name' => '{scope}.{ref}', 'computed' => true, 'declared' => false]);
});

it('carries what the event promises onto the node', function () {
    $data = broadcastData(broadcastGraph(), 'RoomJoined');

    expect($data['queued'])->toBeFalse()
        ->and($data['customPayload'])->toBeTrue()
        ->and($data['channels'][0])->toMatchArray([
            'name' => 'presence-room.{roomId}',
            'kind' => 'presence',
            'declared' => true,
        ]);
});
