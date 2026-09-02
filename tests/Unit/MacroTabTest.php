<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;

/**
 * @filamerce-covers \LaraMint\LaravelBrain\Graph\GraphSplitter
 */
beforeEach(function () {
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository(['app' => ['name' => 'MacroTab'], 'laravel-brain' => [
        'macros' => ['enabled' => true, 'paths' => ['app']],
    ]]));
});

afterEach(function () {
    Container::setInstance(null);
});

function macroTab(array $overrides = []): ?object
{
    if ($overrides !== []) {
        config()->set('laravel-brain.macros', $overrides);
    }

    $result = (new ProjectAnalyzer)->analyze(fixture('macros-project'), function () {});

    return $result->subgraphs['macros'] ?? null;
}

/** Node types in the tab, counted. */
function macroTabTypes(object $tab): array
{
    $types = [];

    foreach ($tab->nodes() as $node) {
        $types[$node->type] = ($types[$node->type] ?? 0) + 1;
    }

    return $types;
}

it('gives macros a tab of their own', function () {
    // Its own tab because a macro hangs off a provider, and providers are not what tabs are
    // seeded from — the nodes would otherwise be built, correct, and in no tab anyone opens.
    $tab = macroTab();

    expect($tab)->not->toBeNull()
        ->and(macroTabTypes($tab))->toMatchArray([
            'macro' => 7,
            'macro_group' => 4,
            'service_provider' => 2,
        ]);
});

it('carries the chain from receiver to method to the file that creates it', function () {
    $tab = macroTab();

    $labels = [];

    foreach ($tab->edges() as $edge) {
        $labels[$edge->label] = ($labels[$edge->label] ?? 0) + 1;
    }

    // One 'adds' per macro, and one 'registered in' per macro. The registrar edge points FROM
    // the macro: the tab is grown forward from the groups, so drawn the other way the provider
    // would sit outside the one tab that is about macros.
    expect($labels)->toBe(['adds' => 7, 'registered in' => 7]);
});

it('groups by receiver and counts what each one gained', function () {
    $tab = macroTab();

    $groups = [];

    foreach ($tab->nodes() as $node) {
        if ($node->type === 'macro_group') {
            $groups[class_basename($node->data['fqcn'])] = $node->data['count'];
        }
    }

    // Canonicalised: the groups are created in receiver order, which is not what is being
    // claimed here.
    expect($groups)->toEqualCanonicalizing([
        'Blueprint' => 2,
        'Column' => 1,
        'Builder' => 2,
        'Collection' => 2,
    ]);
});

it('marks a receiver the application does not own', function () {
    // The interesting case is exactly this one: a class whose source a reader can open and still
    // not find the method.
    $tab = macroTab();

    $vendor = [];

    foreach ($tab->nodes() as $node) {
        if ($node->type === 'macro_group') {
            $vendor[class_basename($node->data['fqcn'])] = $node->data['vendor'];
        }
    }

    expect($vendor['Blueprint'])->toBeTrue()
        ->and($vendor['Column'])->toBeFalse();
});

it('builds no tab at all when the pass is switched off', function () {
    expect(macroTab(['enabled' => false, 'paths' => ['app']]))->toBeNull();
});
