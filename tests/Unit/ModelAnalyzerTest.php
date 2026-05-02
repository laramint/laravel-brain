<?php

use LaraMint\LaravelBrain\Analysis\ModelAnalyzer;

$fixtureProject = __DIR__.'/../fixtures/laravel-project';
$monorepoFixture = __DIR__.'/../fixtures/monorepo';

it('detects dispatchesEvents on Order model', function () use ($fixtureProject) {
    $analyzer = new ModelAnalyzer;
    $models = $analyzer->analyze($fixtureProject, ['App\\Models\\Order']);

    expect($models)->toHaveKey('App\\Models\\Order');
    $order = $models['App\\Models\\Order'];
    expect($order->firedEvents)->not->toBeEmpty();
    expect($order->firedEvents[0])->toContain('OrderPlaced');
});

it('detects relationships on User model', function () use ($fixtureProject) {
    $analyzer = new ModelAnalyzer;
    $models = $analyzer->analyze($fixtureProject, ['App\\Models\\User']);

    expect($models)->toHaveKey('App\\Models\\User');
    $user = $models['App\\Models\\User'];

    $types = array_column($user->relationships, 'type');
    expect($types)->toContain('hasMany');
});

it('detects belongsTo relationship on Order model', function () use ($fixtureProject) {
    $analyzer = new ModelAnalyzer;
    $models = $analyzer->analyze($fixtureProject, ['App\\Models\\Order']);

    $order = $models['App\\Models\\Order'];
    $types = array_column($order->relationships, 'type');
    expect($types)->toContain('belongsTo');
});

it('resolves models from nested module composer roots', function () use ($monorepoFixture) {
    $analyzer = new ModelAnalyzer;
    $models = $analyzer->analyze($monorepoFixture, ['Modules\\Billing\\Models\\Invoice']);

    expect($models)->toHaveKey('Modules\\Billing\\Models\\Invoice');
    expect($models['Modules\\Billing\\Models\\Invoice']->file)->toContain('/modules/Billing/app/Models/Invoice.php');
});
