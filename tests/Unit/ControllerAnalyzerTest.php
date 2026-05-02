<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;

$fixtureProject = __DIR__.'/../fixtures/laravel-project';
$monorepoFixture = __DIR__.'/../fixtures/monorepo';

it('resolves controller files from routes', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    $controllers = (new ControllerAnalyzer)->analyze($fixtureProject, $routes);

    expect($controllers)->not->toBeEmpty();
});

it('extracts constructor dependencies', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    $controllers = (new ControllerAnalyzer)->analyze($fixtureProject, $routes);

    $authController = null;
    foreach ($controllers as $c) {
        if (str_contains($c->fqcn, 'AuthController')) {
            $authController = $c;
            break;
        }
    }

    expect($authController)->not->toBeNull();
    expect($authController->constructorDeps)->toHaveKey('authService');
});

it('finds methods on controllers', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    $controllers = (new ControllerAnalyzer)->analyze($fixtureProject, $routes);

    $orderController = null;
    foreach ($controllers as $c) {
        if (str_contains($c->fqcn, 'OrderController')) {
            $orderController = $c;
            break;
        }
    }

    expect($orderController)->not->toBeNull();

    $methodNames = array_map(fn ($m) => $m->name, $orderController->methods);
    expect($methodNames)->toContain('index');
    expect($methodNames)->toContain('store');
    expect($methodNames)->toContain('show');
    expect($methodNames)->toContain('destroy');
});

it('resolves controllers from nested module composer roots', function () use ($monorepoFixture) {
    $routes = (new RouteAnalyzer)->analyze($monorepoFixture);
    $controllers = (new ControllerAnalyzer)->analyze($monorepoFixture, $routes);

    expect($controllers)->toHaveKey('Modules\\Billing\\Http\\Controllers\\InvoiceController');
    expect($controllers['Modules\\Billing\\Http\\Controllers\\InvoiceController']->constructorDeps)
        ->toHaveKey('invoiceService');
    expect($controllers['Modules\\Billing\\Http\\Controllers\\InvoiceController']->constructorDeps)
        ->toHaveKey('sharedService');
    expect($controllers)->toHaveKey('Shared\\Controllers\\SharedRouteController');
});
