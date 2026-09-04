<?php

use LaraMint\LaravelBrain\Analysis\CallChainEdge;
use LaraMint\LaravelBrain\Analysis\EventDefinition;
use LaraMint\LaravelBrain\Analysis\EventFacts;
use LaraMint\LaravelBrain\Analysis\QueueDeferral;
use LaraMint\LaravelBrain\Graph\GraphSplitter;

function eventsTab(array $events, array $listenerEdges = [], array $firedBy = [], bool $queueDefers = false): ?array
{
    return (new GraphSplitter)->buildEventsTab(
        new EventFacts($events, $listenerEdges, new QueueDeferral(defersByDefault: $queueDefers)),
        $listenerEdges,
        $firedBy,
        'test',
        '2026-01-01',
    );
}

function eventNode(array $tab, string $fqcn): array
{
    $node = $tab['graph']->getNode("event::{$fqcn}");

    if ($node === null) {
        throw new RuntimeException("no node for {$fqcn}");
    }

    return $node->data;
}

it('builds no tab when the application has no events', function () {
    expect(eventsTab([]))->toBeNull();
});

it('marks an event nothing listens to', function () {
    // The headline of the tab. Measured on a real application: 108 of 211 events have no
    // consumer at all, which no single route graph could have shown.
    $tab = eventsTab(['App\\Events\\Ignored' => new EventDefinition(fqcn: 'App\\Events\\Ignored')]);

    expect(eventNode($tab, 'App\\Events\\Ignored')['event']['orphan'])->toBeTrue();
});

it('counts the listeners attached to an event', function () {
    $tab = eventsTab(
        ['App\\Events\\Placed' => new EventDefinition(fqcn: 'App\\Events\\Placed')],
        [new CallChainEdge('App\\Events\\Placed', '__construct', 'App\\Listeners\\Notify', 'handle', 'listener')],
    );

    $event = eventNode($tab, 'App\\Events\\Placed')['event'];

    expect($event['listenerCount'])->toBe(1)
        ->and($event['orphan'])->toBeFalse();
});

it('links a listener back to the event it fires, so a chain is visible', function () {
    $tab = eventsTab(
        [
            'App\\Events\\Placed' => new EventDefinition(fqcn: 'App\\Events\\Placed'),
            'App\\Events\\Shipped' => new EventDefinition(fqcn: 'App\\Events\\Shipped'),
        ],
        [new CallChainEdge('App\\Events\\Placed', '__construct', 'App\\Listeners\\Notify', 'handle', 'listener')],
        ['App\\Listeners\\Notify' => ['App\\Events\\Shipped']],
    );

    $types = array_map(fn ($e): string => $e->type, $tab['graph']->edges());

    expect($types)->toContain('event-to-listener')->toContain('listener-to-event');
});

it('does not invent a second hop to an event it does not know', function () {
    $tab = eventsTab(
        ['App\\Events\\Placed' => new EventDefinition(fqcn: 'App\\Events\\Placed')],
        [new CallChainEdge('App\\Events\\Placed', '__construct', 'App\\Listeners\\Notify', 'handle', 'listener')],
        ['App\\Listeners\\Notify' => ['App\\Events\\NeverDeclared']],
    );

    $types = array_map(fn ($e): string => $e->type, $tab['graph']->edges());

    expect($types)->not->toContain('listener-to-event');
});

it('keeps an event named only by a listener edge', function () {
    // Without this the chain would end at a listener whose event is missing from the tab.
    $tab = eventsTab(
        ['App\\Events\\Placed' => new EventDefinition(fqcn: 'App\\Events\\Placed')],
        [new CallChainEdge('App\\Events\\Placed', '__construct', 'App\\Listeners\\Notify', 'handle', 'listener')],
    );

    $ids = array_map(fn ($n): string => $n->id, $tab['graph']->nodes());

    expect($ids)->toContain('listener::App\\Listeners\\Notify');
});
