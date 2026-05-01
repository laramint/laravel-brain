<?php

use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;

$fixtureProject = __DIR__.'/../fixtures/laravel-project';

function findRoute(array $routes, callable $predicate): mixed
{
    foreach ($routes as $r) {
        if ($predicate($r)) {
            return $r;
        }
    }

    return null;
}

it('extracts basic routes from api.php', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    expect($routes)->not->toBeEmpty();
});

it('finds the POST /login route', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    $login = findRoute($routes, fn ($r) => str_contains($r->uri, 'login'));

    expect($login)->not->toBeNull();
    expect($login->method)->toBe('POST');
    expect($login->controller)->toContain('AuthController');
    expect($login->action)->toBe('login');
});

it('extracts middleware from groups', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    $ordersRoute = findRoute($routes, fn ($r) => $r->uri === '/orders' && $r->method === 'GET');

    expect($ordersRoute)->not->toBeNull();
    expect($ordersRoute->middlewares)->toContain('auth:sanctum');
});

it('applies prefix from nested group', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    $adminRoute = findRoute($routes, fn ($r) => str_contains($r->uri, 'admin'));

    expect($adminRoute)->not->toBeNull();
    expect($adminRoute->uri)->toContain('/admin/');
    expect($adminRoute->middlewares)->toContain('role:admin');
});

it('finds 5 routes total without extra globs', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    expect(count($routes))->toBe(5);
});

it('discovers module routes when an extra glob is provided', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject, ['app/Modules/*/routes/*.php']);

    expect(count($routes))->toBe(7); // 5 standard + 2 from module fixture

    $register = findRoute($routes, fn ($r) => str_contains($r->uri, 'register'));
    expect($register)->not->toBeNull();
    expect($register->method)->toBe('POST');
    expect($register->uri)->toBe('/api/v1/auth/register');
});

it('does not duplicate routes when a glob overlaps the routes/ directory', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject, ['routes/*.php']);

    expect(count($routes))->toBe(5);
});
