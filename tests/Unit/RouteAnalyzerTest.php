<?php

use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;

$fixtureProject = __DIR__.'/../fixtures/laravel-project';
$monorepoFixture = __DIR__.'/../fixtures/monorepo';

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

it('finds 5 routes total', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    expect(count($routes))->toBe(5);
});

it('scans nested routes inside monorepo layouts regardless of container folder name', function () use ($monorepoFixture) {
    $routes = (new RouteAnalyzer)->analyze($monorepoFixture);
    $nested = findRoute($routes, fn ($r) => $r->uri === '/billing/invoices' && $r->method === 'GET');
    $shared = findRoute($routes, fn ($r) => $r->uri === '/shared/ping' && $r->method === 'GET');
    $extension = findRoute($routes, fn ($r) => $r->uri === '/payroll/employees' && $r->method === 'GET');

    expect($routes)->toHaveCount(4);
    expect($nested)->not->toBeNull();
    expect($nested->controller)->toBe('Modules\\Billing\\Http\\Controllers\\InvoiceController');
    expect($shared)->not->toBeNull();
    expect($shared->controller)->toBe('Shared\\Controllers\\SharedRouteController');
    expect($extension)->not->toBeNull();
    expect($extension->controller)->toBe('Extensions\\PayrollHub\\Http\\Controllers\\EmployeeController');
});
