<?php

use LaraMint\LaravelBrain\Analysis\CallChainEdge;
use LaraMint\LaravelBrain\Analysis\EventDefinition;
use LaraMint\LaravelBrain\Analysis\EventFacts;
use LaraMint\LaravelBrain\Analysis\QueueDeferral;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\Node;

function facts(bool $queueDefers = false): EventFacts
{
    return new EventFacts(
        ['App\\Events\\Placed' => new EventDefinition(fqcn: 'App\\Events\\Placed', properties: ['order'])],
        [
            new CallChainEdge('App\\Events\\Placed', '__construct', 'App\\Listeners\\Notify', 'handle', 'listener'),
            new CallChainEdge('App\\Events\\Placed', '__construct', 'App\\Listeners\\Notify', 'handle', 'listener'),
        ],
        new QueueDeferral(defersByDefault: $queueDefers),
    );
}

function routeGraph(): Graph
{
    $graph = new Graph;
    $graph->addNode(new Node('event::App\\Events\\Placed', 'event', 'Placed', [
        'fqcn' => 'App\\Events\\Placed',
        'file' => '/app/Events/Placed.php',
        'flowSteps' => [['type' => 'call', 'label' => 'x()']],
    ]));
    $graph->addNode(new Node('controller::App\\Http\\Controller', 'controller', 'Controller', [
        'fqcn' => 'App\\Http\\Controller',
    ]));

    return $graph;
}

it('stamps an event node on a graph that is not the events tab', function () {
    // A route graph reaches an event the moment the request dispatches one, and the node stopped
    // at name-and-file. Measured on a real route: eight events, one setting off seven listeners
    // and two setting off nothing, none of it on the graph.
    $graph = routeGraph();
    facts()->stamp($graph);

    expect($graph->getNode('event::App\\Events\\Placed')->data['event']['listenerCount'])->toBe(1);
});

it('keeps the data the graph builder already put on the node', function () {
    // `updateNodeData` replaces a node's whole data array rather than merging into it, so writing
    // one key drops the file path and the flow steps — and a node with no file still renders,
    // which is what makes the loss silent.
    $graph = routeGraph();
    facts()->stamp($graph);

    $data = $graph->getNode('event::App\\Events\\Placed')->data;

    expect($data['file'])->toBe('/app/Events/Placed.php')
        ->and($data['flowSteps'])->toHaveCount(1)
        ->and($data['fqcn'])->toBe('App\\Events\\Placed');
});

it('leaves nodes of every other type alone', function () {
    $graph = routeGraph();

    expect(facts()->stamp($graph))->toBe(1)
        ->and($graph->getNode('controller::App\\Http\\Controller')->data)->not->toHaveKey('event');
});

it('counts a listener once when several edges name it', function () {
    expect(facts()->listenersFor('App\\Events\\Placed'))->toBe(['App\\Listeners\\Notify']);
});

it('treats an event it has never heard of as an orphan rather than failing', function () {
    // A route can dispatch an event kept outside the configured directories.
    expect(facts()->eventPayload('App\\Events\\Unknown')['orphan'])->toBeTrue();
});
