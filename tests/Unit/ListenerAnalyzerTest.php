<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\ListenerAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

it('discovers an event → listener edge by convention', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->calleeFqcn === 'App\\Listeners\\HandleUserLoggedIn'
    ));

    expect($match)->toHaveCount(1);
    expect($match[0])
        ->callerFqcn->toBe('App\\Events\\UserLoggedIn')
        ->callerMethod->toBe('__construct')
        ->calleeMethod->toBe('handle')
        ->type->toBe('listener');
});

it('discovers an invokable listener', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->calleeFqcn === 'App\\Listeners\\LogUserLoggedIn'
    ));

    expect($match)->toHaveCount(1);
    expect($match[0])
        ->callerFqcn->toBe('App\\Events\\UserLoggedIn')
        ->type->toBe('listener');
});

it('discovers a listener registered via the $listen array', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->callerFqcn === 'App\\Events\\OrderShipped' && $e->calleeFqcn === 'App\\Listeners\\SendShipmentNotification'
    ));

    expect($match)->toHaveCount(1);
    expect($match[0])
        ->callerMethod->toBe('__construct')
        ->calleeMethod->toBe('handle')
        ->type->toBe('listener');
});

it('discovers subscriber handlers from listen() calls and the returned map', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $byHandler = fn (string $method) => array_values(array_filter(
        $edges,
        fn ($e) => $e->calleeFqcn === 'App\\Subscribers\\UserEventSubscriber' && $e->calleeMethod === $method
    ));

    $imperative = $byHandler('handlePasswordReset');
    expect($imperative)->toHaveCount(1);
    expect($imperative[0])
        ->callerFqcn->toBe('App\\Events\\PasswordReset')
        ->type->toBe('listener');

    $returned = $byHandler('handleAccountDeleted');
    expect($returned)->toHaveCount(1);
    expect($returned[0])->callerFqcn->toBe('App\\Events\\AccountDeleted');
});

it('discovers a listener registered via the #[AsEventListener] attribute', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->calleeFqcn === 'App\\Listeners\\SendPodcastNotification'
    ));

    expect($match)->toHaveCount(1);
    expect($match[0])
        ->callerFqcn->toBe('App\\Events\\PodcastProcessed')
        ->calleeMethod->toBe('notify')
        ->type->toBe('listener');
});

it('discovers a listener registered via a class-level #[AsEventListener] attribute', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->calleeFqcn === 'App\\Listeners\\ProcessInvoice'
    ));

    expect($match)->toHaveCount(1);
    expect($match[0])
        ->callerFqcn->toBe('App\\Events\\InvoiceRequested')
        ->calleeMethod->toBe('handle')
        ->type->toBe('listener');
});

it('does not duplicate a listener registered by both convention and $listen', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->calleeFqcn === 'App\\Listeners\\HandleUserLoggedIn'
    ));

    expect($match)->toHaveCount(1);
});

it('resolves the Class@method and string-FQCN-key forms in $listen', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->callerFqcn === 'App\\Events\\ReportReady'
    ));

    expect($match)->toHaveCount(1);
    expect($match[0])
        ->calleeFqcn->toBe('App\\Listeners\\SendShipmentNotification')
        ->calleeMethod->toBe('sendReport')
        ->type->toBe('listener');
});

it('resolves an aliased event import and the [Class, method] tuple form', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->callerFqcn === 'App\\Events\\PaymentCaptured'
    ));

    expect($match)->toHaveCount(1);
    expect($match[0])
        ->calleeFqcn->toBe('App\\Listeners\\SendShipmentNotification')
        ->calleeMethod->toBe('onCaptured');
});

it('resolves a single (non-array) listener value in $listen', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->callerFqcn === 'App\\Events\\InvoiceArchived'
    ));

    expect($match)->toHaveCount(1);
    expect($match[0])
        ->calleeFqcn->toBe('App\\Listeners\\SendShipmentNotification')
        ->calleeMethod->toBe('handle');
});

it('reads the positional method argument of #[AsEventListener]', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->calleeFqcn === 'App\\Listeners\\ArchivePodcast'
    ));

    expect($match)->toHaveCount(1);
    expect($match[0])
        ->callerFqcn->toBe('App\\Events\\PodcastArchived')
        ->calleeMethod->toBe('archive');
});

it('does not emit an edge for a listener registered nowhere', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $match = array_filter($edges, fn ($e) => $e->calleeFqcn === 'App\\Listeners\\UnregisteredListener');

    expect($match)->toBe([]);
});

it('connects a dispatched event to its listener in the graph', function () {
    $project = fixture('laravel-project');
    $routes = (new RouteAnalyzer)->analyze($project);
    $controllers = (new ControllerAnalyzer)->analyze($project, $routes);
    $traces = (new MethodTracer)->trace($controllers);
    $traces = array_merge($traces, (new ListenerAnalyzer)->analyze($project));

    $graph = (new GraphBuilder)->build('test', $routes, new MiddlewareRegistry([], [], []), $controllers, $traces, []);

    $listenerNodes = array_filter($graph->nodes(), fn ($n) => $n->type === 'listener');
    expect($listenerNodes)->not->toBeEmpty();

    $listenerNode = array_values($listenerNodes)[0];
    $edge = array_values(array_filter(
        $graph->edges(),
        fn ($e) => $e->target === $listenerNode->id
    ));

    expect($edge)->not->toBeEmpty();
    expect($edge[0]->label)->toBe('handled by');
});
