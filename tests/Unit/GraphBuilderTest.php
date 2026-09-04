<?php

use LaraMint\LaravelBrain\Analysis\CallChainEdge;
use LaraMint\LaravelBrain\Analysis\ContainerBindingAnalyzer;
use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\ModelAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\Edge;
use LaraMint\LaravelBrain\Graph\GraphBuilder;
use LaraMint\LaravelBrain\Graph\Node;

it('builds a graph with nodes and edges from fixture project', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $middlewareRegistry = new MiddlewareRegistry([], [], []);
    $controllers = (new ControllerAnalyzer)->analyze(fixture('laravel-project'), $routes);
    $traces = (new MethodTracer)->trace($controllers);
    $modelFqcns = array_map(fn ($t) => $t->calleeFqcn, array_filter($traces, fn ($t) => $t->type === 'model'));
    $models = (new ModelAnalyzer)->analyze(fixture('laravel-project'), $modelFqcns);

    $graph = (new GraphBuilder)->build('test', $routes, $middlewareRegistry, $controllers, $traces, $models);

    expect($graph->nodeCount())->toBeGreaterThan(0);
    expect($graph->edgeCount())->toBeGreaterThan(0);
});

it('produces valid JSON output', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $middlewareRegistry = new MiddlewareRegistry([], [], []);
    $controllers = (new ControllerAnalyzer)->analyze(fixture('laravel-project'), $routes);
    $traces = (new MethodTracer)->trace($controllers);

    $modelFqcns = array_map(fn ($t) => $t->calleeFqcn, array_filter($traces, fn ($t) => $t->type === 'model'));
    $models = (new ModelAnalyzer)->analyze(fixture('laravel-project'), $modelFqcns);

    $graph = (new GraphBuilder)->build('test', $routes, $middlewareRegistry, $controllers, $traces, $models);

    expect($graph->toJson())
        ->json()
        ->toHaveKeys(['meta', 'nodes', 'edges'])
        ->meta->toBeArray()->toHaveKey('project')
        ->nodes->toBeNonEmptyArray()
        ->edges->toBeNonEmptyArray();
});

it('creates route nodes for each route', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $middlewareRegistry = new MiddlewareRegistry([], [], []);
    $controllers = (new ControllerAnalyzer)->analyze(fixture('laravel-project'), $routes);
    $traces = (new MethodTracer)->trace($controllers);
    $models = [];

    $graph = (new GraphBuilder)->build('test', $routes, $middlewareRegistry, $controllers, $traces, $models);

    $routeNodes = array_filter($graph->nodes(), fn ($n) => $n->type === 'route');

    expect($routeNodes)->toHavecount(count($routes));
});

it('exposes parent controller nodes and extends edges for inherited actions', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $middlewareRegistry = new MiddlewareRegistry([], [], []);
    $controllers = (new ControllerAnalyzer)->analyze(fixture('laravel-project'), $routes);
    $traces = (new MethodTracer)->trace($controllers);
    $models = [];

    $graph = (new GraphBuilder)->build('test', $routes, $middlewareRegistry, $controllers, $traces, $models, fixture('laravel-project'));

    $extends = array_values(array_filter($graph->edges(), fn ($e) => $e->type === 'controller-extends'));

    expect($extends)
        ->toBeArray()
        ->toHaveCount(1)
        ->andArrayFirstElement()
        ->toBeInstanceOf(Edge::class);

    $ids = array_map(fn ($n) => $n->id, $graph->nodes());

    expect($ids)
        ->toBeArray()
        ->toHaveCount(52)
        ->toContain('controller::App\\Http\\Controllers\\V3\\AbstractThingController');

    $handlesFromParent = array_filter(
        $graph->edges(),
        fn ($e) => $e->type === 'controller-to-action'
            && $e->source === 'controller::App\\Http\\Controllers\\V3\\AbstractThingController'
    );

    expect($handlesFromParent)
        ->toBeArray()
        ->andArrayFirstElement()
        ->toBeInstanceOf(Edge::class);
});

it('wires form request validated nodes and exposes validationRules on graph nodes', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $middlewareRegistry = new MiddlewareRegistry([], [], []);
    $analyzer = new ControllerAnalyzer;
    $controllers = $analyzer->analyze(fixture('laravel-project'), $routes);
    $traces = (new MethodTracer)->trace($controllers, $analyzer->getPsr4Map(), fixture('laravel-project'));
    $models = [];

    $graph = (new GraphBuilder)->build('test', $routes, $middlewareRegistry, $controllers, $traces, $models, fixture('laravel-project'));

    $frEdges = array_values(array_filter($graph->edges(), fn ($e) => $e->type === 'action-to-form-request'));

    expect($frEdges)
        ->toBeArray()
        ->andArrayFirstElement()
        ->toBeInstanceOf(Edge::class);

    $formRequestNodes = array_values(array_filter(
        $graph->nodes(),
        fn ($n) => ($n->data['fqcn'] ?? '') === 'App\\Http\\Requests\\ProfileStoreRequest'
            && ($n->data['method'] ?? '') === 'validated'
    ));

    expect($formRequestNodes)
        ->toBeArray()
        ->andArrayFirstElement()
        ->toBeInstanceOf(Node::class)
        ->data->validationRules->toBeNonEmptyArray()
        ->type->toBe('validation_request');
});

it('adds IoC binding edges from service providers to interfaces and implementations', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $middlewareRegistry = new MiddlewareRegistry([], [], []);
    $analyzer = new ControllerAnalyzer;
    $controllers = $analyzer->analyze(fixture('laravel-project'), $routes);
    $traces = (new MethodTracer)->trace($controllers, $analyzer->getPsr4Map(), fixture('laravel-project'));
    $models = [];
    $bindings = (new ContainerBindingAnalyzer)->analyze(fixture('laravel-project'));

    $graph = (new GraphBuilder)->build('test', $routes, $middlewareRegistry, $controllers, $traces, $models, fixture('laravel-project'), [], $bindings);

    $types = array_map(fn ($e) => $e->type, $graph->edges());

    expect($types)
        ->toBeArray()
        ->toHaveCount(63)
        ->toContain('binding-resolution', 'binding-registered-in');

    $resolution = array_values(array_filter($graph->edges(), fn ($e) => $e->type === 'binding-resolution'));

    expect($resolution)
        ->toBeArray()
        ->andArrayFirstElement()
        ->toBeInstanceOf(Edge::class)
        ->label->toContain('SqlThingRepository');
});

it('assigns content-addressed edge ids that are stable across rebuilds (graph format v2)', function () {
    $build = function () {
        $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
        $middlewareRegistry = new MiddlewareRegistry([], [], []);
        $controllers = (new ControllerAnalyzer)->analyze(fixture('laravel-project'), $routes);
        $traces = (new MethodTracer)->trace($controllers);
        $modelFqcns = array_map(fn ($t) => $t->calleeFqcn, array_filter($traces, fn ($t) => $t->type === 'model'));
        $models = (new ModelAnalyzer)->analyze(fixture('laravel-project'), $modelFqcns);

        return (new GraphBuilder)->build('test', $routes, $middlewareRegistry, $controllers, $traces, $models);
    };

    $ids1 = array_map(fn ($e) => $e['id'], json_decode($build()->toJson(), true)['edges']);
    $ids2 = array_map(fn ($e) => $e['id'], json_decode($build()->toJson(), true)['edges']);

    // Deterministic across independent builds (the incremental-analyze prerequisite)...
    expect($ids1)->toEqual($ids2);
    // ...content-addressed (v2 format), never the old insertion-sequential "e{N}_" ids...
    foreach ($ids1 as $id) {
        expect($id)->toStartWith('e_');
    }
    // ...and unique (duplicate edges get a stable per-occurrence suffix, edge set preserved).
    expect(count($ids1))->toBe(count(array_unique($ids1)));
});

it('keeps every existing edge id when a new edge appears', function () {
    // The reason for content-addressed ids. Under the old insertion-counter scheme any edge
    // added mid-build renumbered every edge after it, so a one-line code change rewrote most of
    // the graph's ids — which is what made diffing two builds, or rebuilding part of one,
    // impractical. A build that differs by one call must differ by one id.
    $build = function (array $extraTraces = []) {
        $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
        $middlewareRegistry = new MiddlewareRegistry([], [], []);
        $controllers = (new ControllerAnalyzer)->analyze(fixture('laravel-project'), $routes);
        $traces = (new MethodTracer)->trace($controllers);
        $modelFqcns = array_map(fn ($t) => $t->calleeFqcn, array_filter($traces, fn ($t) => $t->type === 'model'));
        $models = (new ModelAnalyzer)->analyze(fixture('laravel-project'), $modelFqcns);

        return (new GraphBuilder)->build(
            'test', $routes, $middlewareRegistry, $controllers, [...$traces, ...$extraTraces], $models,
        );
    };

    $before = array_map(fn ($e) => $e['id'], json_decode($build()->toJson(), true)['edges']);

    // Repeat an existing call, the way an added line of code would: same endpoints, so the nodes
    // are certain to exist and only the edge is new.
    $traces = (new MethodTracer)->trace(
        (new ControllerAnalyzer)->analyze(fixture('laravel-project'), (new RouteAnalyzer)->analyze(fixture('laravel-project'))),
    );
    $after = array_map(fn ($e) => $e['id'], json_decode($build([$traces[0]])->toJson(), true)['edges']);

    expect(array_diff($before, $after))->toBe([])
        ->and(count($after))->toBe(count($before) + 1);
});

it('hangs a listener off the canonical event node, not a mangled duplicate', function () {
    // A listener edge is the one hop whose caller is a class nobody calls. Given the generic
    // treatment its caller id becomes `app_events_orderplaced::__construct`, while every graph
    // that shows the event shows `event::App\Events\OrderPlaced` — two nodes for one class, with
    // the listener on the one no route can reach. Measured before the fix: 102 route tabs carried
    // an event node and not one carried a listener.
    $edges = [new CallChainEdge('App\\Events\\OrderPlaced', '__construct', 'App\\Listeners\\Notify', 'handle', 'listener')];

    $graph = (new GraphBuilder)->build('test', [], new MiddlewareRegistry([], [], []), [], $edges, []);

    $sources = array_map(fn (Edge $e): string => $e->source, $graph->edges());

    expect($sources)->toContain('event::App\\Events\\OrderPlaced')
        ->and($sources)->not->toContain('app_events_orderplaced::__construct');
});

it('creates the listener as a node of its own type', function () {
    $edges = [new CallChainEdge('App\\Events\\OrderPlaced', '__construct', 'App\\Listeners\\Notify', 'handle', 'listener')];

    $graph = (new GraphBuilder)->build('test', [], new MiddlewareRegistry([], [], []), [], $edges, []);

    $types = array_map(fn (Node $n): string => $n->type, $graph->nodes());

    expect($types)->toContain('listener')->toContain('event');
});
