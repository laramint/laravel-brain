<?php

use App\Http\Controllers\SearchController;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;

/**
 * OPTIONS and QUERY through the whole reading path: the two dedicated helpers, the
 * `Route::match([...])` spelling every supported Laravel can register, and `Route::any()`.
 */
function httpMethodFixtureRoutes(): array
{
    return (new RouteAnalyzer)->analyze(fixture('http-methods-project'));
}

/** @return array<int, RouteDefinition> */
function httpMethodRoutesFor(array $routes, string $uri): array
{
    return array_values(array_filter($routes, fn ($r) => $r->uri === $uri));
}

/** Sorted so an assertion pins the set of verbs, not the order the visitor happened to emit them. */
function httpMethodVerbsFor(array $routes, string $uri): array
{
    $methods = array_map(fn ($r) => $r->method, httpMethodRoutesFor($routes, $uri));
    sort($methods);

    return $methods;
}

it('extracts a Route::options() route as OPTIONS', function () {
    $routes = httpMethodFixtureRoutes();

    expect(httpMethodVerbsFor($routes, '/preflight'))->toBe(['OPTIONS']);

    $preflight = httpMethodRoutesFor($routes, '/preflight')[0];
    expect($preflight->controller)->toBe(SearchController::class)
        ->and($preflight->action)->toBe('preflight')
        ->and($preflight->tabGroup)->toBe('OPTIONS /preflight');
});

it('extracts a Route::query() route as QUERY even though the installed Laravel cannot register one', function () {
    // `Route::query()` landed on the framework's master (14.x) branch, not on 13.x, so the helper
    // does not exist here. Reading it is pure source analysis, which is why the analyzer can
    // support it ahead of the version matrix.
    expect((new ReflectionClass(Router::class))->hasMethod('query'))->toBeFalse();

    $routes = httpMethodFixtureRoutes();

    expect(httpMethodVerbsFor($routes, '/search'))->toBe(['QUERY']);

    $search = httpMethodRoutesFor($routes, '/search')[0];
    expect($search->controller)->toBe(SearchController::class)
        ->and($search->action)->toBe('search')
        ->and($search->tabGroup)->toBe('QUERY /search');
});

it('expands Route::match() into one route per verb', function () {
    $routes = httpMethodFixtureRoutes();

    expect(httpMethodVerbsFor($routes, '/catalog'))->toBe(['OPTIONS', 'QUERY']);

    foreach (httpMethodRoutesFor($routes, '/catalog') as $route) {
        expect($route->controller)->toBe(SearchController::class)
            ->and($route->action)->toBe('index')
            ->and($route->tabGroup)->toBe($route->method.' /catalog');
    }
});

it('accepts the bare-string form of Route::match()', function () {
    expect(httpMethodVerbsFor(httpMethodFixtureRoutes(), '/single'))->toBe(['QUERY']);
});

it('drops HEAD from a Route::match() verb list', function () {
    // Laravel registers HEAD alongside GET by itself, and the live-router path already skips it;
    // keeping it here would put a duplicate of every GET tab in the sidebar.
    expect(httpMethodVerbsFor(httpMethodFixtureRoutes(), '/head-dropped'))->toBe(['GET']);
});

it('expands Route::any() into the verbs the running router actually answers', function () {
    $routes = httpMethodFixtureRoutes();

    $expected = RouteAnalyzer::anyRouteVerbs();
    sort($expected);

    expect(httpMethodVerbsFor($routes, '/anything'))
        ->toBe($expected)
        ->toContain('OPTIONS')
        ->not->toContain('HEAD');
});

it('reads the any() verb list from the router rather than assuming one', function () {
    // Installed here is Laravel 13, whose Router::$verbs has no QUERY — so `Route::any()` must
    // not produce a QUERY endpoint the application would answer with 405. An app on the 14.x
    // line gets QUERY from the same read, with no change to this package.
    expect(RouteAnalyzer::anyRouteVerbs())->toBe(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])
        ->and(Router::$verbs)->not->toContain('QUERY');
});

it('applies group prefix and chained middleware to every verb of a match route', function () {
    $routes = httpMethodFixtureRoutes();

    expect(httpMethodVerbsFor($routes, '/v2/chained'))->toBe(['OPTIONS', 'QUERY']);

    foreach (httpMethodRoutesFor($routes, '/v2/chained') as $route) {
        expect($route->middlewares)->toContain('auth:sanctum')
            ->and($route->middlewares)->toContain('throttle:api')
            ->and($route->action)->toBe('index');
    }
});

it('skips a Route::match() whose verb list is built at runtime', function () {
    expect(httpMethodVerbsFor(httpMethodFixtureRoutes(), '/dynamic'))->toBe([]);
});

it('carries QUERY and OPTIONS through live-router discovery', function () {
    $container = new Container;
    Container::setInstance($container);
    $events = new Dispatcher($container);
    $router = new Router($events, $container);
    $container->instance('router', $router);
    $container->instance(Router::class, $router);

    try {
        // `Route::query()` does not exist on this version, but `match()` hands its verbs to
        // addRoute() without checking them against Router::$verbs, so a QUERY route registers.
        $router->match(['QUERY'], '/search', [SearchController::class, 'search']);
        $router->options('/preflight', [SearchController::class, 'preflight']);

        $routes = (new RouteAnalyzer([], autoDiscover: true, excludeVendor: false))->analyze('/unused');

        expect(httpMethodVerbsFor($routes, '/search'))->toBe(['QUERY'])
            ->and(httpMethodVerbsFor($routes, '/preflight'))->toBe(['OPTIONS']);
    } finally {
        Container::setInstance(null);
    }
});

it('offers the stress runner every verb it puts in the sidebar', function () {
    // BrainController builds the stress endpoint's method allow-list from this constant, so a
    // verb the analyzer can emit is always one the stress panel is allowed to fire.
    expect(RouteAnalyzer::HTTP_VERBS)->toBe(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'QUERY']);
});
