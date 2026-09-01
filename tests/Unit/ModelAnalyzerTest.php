<?php

use LaraMint\LaravelBrain\Analysis\ModelAnalyzer;
use LaraMint\LaravelBrain\Analysis\MorphMap;

it('detects dispatchesEvents on Order model', function () {
    $analyzer = new ModelAnalyzer;
    $models = $analyzer->analyze(fixture('laravel-project'), ['App\\Models\\Order']);

    expect($models)
        ->toBeArray()
        ->toHaveCount(1)
        ->toHaveKey('App\\Models\\Order');

    $order = array_first($models);

    expect($order)
        ->firedEvents->toBe(["App\Events\OrderPlaced"]);
});

it('detects relationships on User model', function () {
    $analyzer = new ModelAnalyzer;
    $models = $analyzer->analyze(fixture('laravel-project'), ['App\\Models\\User']);

    expect($models)->toHaveKey('App\\Models\\User');
    $user = $models['App\\Models\\User'];

    $types = array_column($user->relationships, 'type');
    expect($types)->toContain('hasMany');
});

it('detects belongsTo relationship on Order model', function () {
    $analyzer = new ModelAnalyzer;
    $models = $analyzer->analyze(fixture('laravel-project'), ['App\\Models\\Order']);

    $order = $models['App\\Models\\Order'];
    $types = array_column($order->relationships, 'type');

    expect($types)->ToBeArray()->toContain('belongsTo');
});

it('resolves a related model in the same namespace', function () {
    // Order::user() does `$this->belongsTo(User::class)`. Related models almost always sit
    // together in App\Models, so the target needs no import — and left short it became a second
    // node for a model the graph already had, with the relationship pointing at that one.
    $models = (new ModelAnalyzer)->analyze(fixture('laravel-project'), ['App\\Models\\Order']);
    $related = array_column($models['App\\Models\\Order']->relationships, 'related');

    expect($related)->toContain('App\\Models\\User')
        ->and($related)->not->toContain('User');
});

it('carries the alias the application registered for the model', function () {
    $models = (new ModelAnalyzer([], new MorphMap(['order' => 'App\\Models\\Order'])))
        ->analyze(fixture('laravel-project'), ['App\\Models\\Order']);

    expect($models['App\\Models\\Order']->morphAlias)->toBe('order');
});

it('leaves the alias unset for a model no map names', function () {
    $models = (new ModelAnalyzer([], new MorphMap(['parcel' => 'App\\Models\\Parcel'])))
        ->analyze(fixture('laravel-project'), ['App\\Models\\Order']);

    expect($models['App\\Models\\Order']->morphAlias)->toBeNull()
        ->and($models['App\\Models\\Order']->morphAliasMissing)->toBeFalse();
});

it('flags a model left out of an enforced morph map', function () {
    // Under `enforceMorphMap()` there is no fallback to the class name — the first
    // `getMorphClass()` call on this model throws ClassMorphViolationException.
    $models = (new ModelAnalyzer([], new MorphMap(['parcel' => 'App\\Models\\Parcel'], true)))
        ->analyze(fixture('laravel-project'), ['App\\Models\\Order']);

    expect($models['App\\Models\\Order']->morphAliasMissing)->toBeTrue();
});

it('does not flag a model that an enforced map does name', function () {
    $models = (new ModelAnalyzer([], new MorphMap(['order' => 'App\\Models\\Order'], true)))
        ->analyze(fixture('laravel-project'), ['App\\Models\\Order']);

    expect($models['App\\Models\\Order']->morphAliasMissing)->toBeFalse()
        ->and($models['App\\Models\\Order']->morphAlias)->toBe('order');
});
