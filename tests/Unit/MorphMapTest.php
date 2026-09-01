<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use LaraMint\LaravelBrain\Analysis\MorphMap;

/** Run a closure against a stated morph map, then put the framework's global static back. */
function withMorphMap(array $map, bool $enforced, callable $fn): mixed
{
    $previousMap = Relation::morphMap();
    $previousEnforced = Relation::requiresMorphMap();

    try {
        Relation::morphMap($map, merge: false);
        Relation::requireMorphMap($enforced);

        return $fn();
    } finally {
        Relation::morphMap($previousMap, merge: false);
        Relation::requireMorphMap($previousEnforced);
    }
}

it('answers the alias a mapped model persists under', function () {
    $map = new MorphMap(['parcel' => 'App\\Models\\Parcel']);

    expect($map->aliasFor('App\\Models\\Parcel'))->toBe('parcel');
});

it('answers nothing for a model the map does not name', function () {
    $map = new MorphMap(['parcel' => 'App\\Models\\Parcel']);

    expect($map->aliasFor('App\\Models\\Order'))->toBeNull();
});

it('matches a class however the leading backslash falls', function () {
    // A map written `\App\Models\Parcel::class` keeps no backslash, but one written as a string
    // literal often does, and the analyzer's FQCNs come from a parser that may keep either.
    $map = new MorphMap(['parcel' => '\\App\\Models\\Parcel']);

    expect($map->aliasFor('App\\Models\\Parcel'))->toBe('parcel')
        ->and($map->aliasFor('\\App\\Models\\Parcel'))->toBe('parcel');
});

it('reports the first of two aliases pointing at the same model', function () {
    // `Model::getMorphClass()` resolves through `array_search()`, so the earlier key is the one
    // the database actually receives. Naming the later one would name a value no row holds.
    $map = new MorphMap(['parcel' => 'App\\Models\\Parcel', 'shipment' => 'App\\Models\\Parcel']);

    expect($map->aliasFor('App\\Models\\Parcel'))->toBe('parcel');
});

it('treats a map as advisory unless the application enforces one', function () {
    expect((new MorphMap(['parcel' => 'App\\Models\\Parcel']))->isEnforced())->toBeFalse()
        ->and((new MorphMap([], true))->isEnforced())->toBeTrue();
});

it('reads the aliases the running application registered', function () {
    // The whole reason this is a runtime read: aliases arrive from packages, config values and
    // conditionals, none of which a provider parser can see.
    $alias = withMorphMap(['parcel' => 'App\\Models\\Parcel'], false, fn () => MorphMap::fromApplication()->aliasFor('App\\Models\\Parcel'));

    expect($alias)->toBe('parcel');
});

it('reads whether the running application enforces the map', function () {
    $enforced = withMorphMap([], true, fn () => MorphMap::fromApplication()->isEnforced());

    expect($enforced)->toBeTrue();
});

it('states nothing when no application registered a map', function () {
    // A unit test, or a scan run outside an application. Answering "empty, not enforced" shows no
    // aliases and flags no model, rather than declaring every model in the project broken.
    $map = withMorphMap([], false, fn () => MorphMap::fromApplication());

    expect($map->aliasFor('App\\Models\\Parcel'))->toBeNull()
        ->and($map->isEnforced())->toBeFalse();
});
