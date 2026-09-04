<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\Reachability\ClassInventory;
use LaraMint\LaravelBrain\Analysis\Reachability\ClassStringIndex;

it('finds every class-like declaration under the source paths with its surface and kind', function () {
    $inventory = ClassInventory::scan(fixture('reachability-project'), ['app']);
    $all = $inventory->all();

    expect($all)->toHaveKey('App\Jobs\ArchiveOrders')
        ->and($all['App\Jobs\ArchiveOrders']->kind)->toBe('job')
        ->and($all['App\Contracts\Importer']->surface)->toBe('interface')
        ->and($all['App\Support\BaseWorkflow']->surface)->toBe('abstract_class')
        ->and($all['App\Providers\AppServiceProvider']->kind)->toBe('service_provider')
        ->and($all['App\Exceptions\OrderFailed']->kind)->toBe('exception')
        ->and($all['App\Http\Controllers\OrderController']->kind)->toBe('controller');
});

it('records what a declaration extends, implements and uses', function () {
    $inventory = ClassInventory::scan(fixture('reachability-project'), ['app']);

    expect($inventory->get('App\Services\OrderService')->parent)->toBe('App\Support\BaseWorkflow')
        ->and($inventory->get('App\Listeners\NotifyWarehouse')->interfaces)
        ->toBe(['Illuminate\Contracts\Queue\ShouldQueue']);
});

it('names middleware and API resources before the controller heuristic claims them', function () {
    // Both live under \Http\, which the controller rule matches. Ordered the other way round
    // every middleware class in an application is filed as a controller.
    expect(ClassInventory::kindOf('App\Http\Middleware\EnsureTenant', 'class'))->toBe('middleware')
        ->and(ClassInventory::kindOf('App\Http\Resources\OrderResource', 'class'))->toBe('resource')
        ->and(ClassInventory::kindOf('App\Http\Controllers\OrderController', 'class'))->toBe('controller');
});

it('reports the declaration surface rather than a name-based kind for non-classes', function () {
    expect(ClassInventory::kindOf('App\Jobs\Contract', 'interface'))->toBe('interface')
        ->and(ClassInventory::kindOf('App\Jobs\Concerns\Retries', 'trait'))->toBe('trait')
        ->and(ClassInventory::kindOf('App\Jobs\Status', 'enum'))->toBe('enum');
});

it('knows which kinds the tracer has no edge for', function () {
    expect(ClassInventory::isTracerBlind('service_provider'))->toBeTrue()
        ->and(ClassInventory::isTracerBlind('exception'))->toBeTrue()
        ->and(ClassInventory::isTracerBlind('job'))->toBeFalse();
});

it('finds a class named as ::class and as a quoted FQCN, but not by its own file', function () {
    $index = ClassStringIndex::scan(fixture('reachability-project'), ['app', 'config']);

    expect($index->hasReferenceTo('App\Support\ReportRenderer'))->toBeTrue()
        ->and($index->hasReferenceTo('App\Jobs\RebuildIndex'))->toBeTrue()
        ->and($index->hasReferenceTo('App\Jobs\ArchiveOrders'))->toBeFalse()
        // Paths in the index are resolved, the same way the inventory's are, so the two
        // agree on what "its own file" is.
        ->and($index->hasReferenceTo(
            'App\Support\ReportRenderer',
            (string) realpath(fixture('reachability-project/app/Providers/AppServiceProvider.php')),
        ))->toBeFalse();
});

it('does not read a path or a regex as a class name', function () {
    // The scan looks at every string literal in the source tree. A looser pattern turns
    // "vendor/bin/x" into a reference and every unreached class picks up a hint that means
    // nothing.
    expect(preg_match(ClassStringIndex::FQCN_PATTERN, 'App\Jobs\RebuildIndex'))->toBe(1)
        ->and(preg_match(ClassStringIndex::FQCN_PATTERN, 'routes/web.php'))->toBe(0)
        ->and(preg_match(ClassStringIndex::FQCN_PATTERN, 'App\\'))->toBe(0)
        ->and(preg_match(ClassStringIndex::FQCN_PATTERN, 'orders:sync'))->toBe(0)
        // A separator is required. Without one, every 'handle' and 'default' in the tree is a
        // reference to some root-namespace class, and the hint stops discriminating anything.
        ->and(preg_match(ClassStringIndex::FQCN_PATTERN, 'handle'))->toBe(0);
});
