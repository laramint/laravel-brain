<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\CallChainEdge;
use LaraMint\LaravelBrain\Analysis\ChannelAnalyzer;
use LaraMint\LaravelBrain\Analysis\ChannelDefinition;
use LaraMint\LaravelBrain\Analysis\ConsoleAnalyzer;
use LaraMint\LaravelBrain\Analysis\ConsoleCommandDefinition;
use LaraMint\LaravelBrain\Analysis\Reachability\ClassInventory;
use LaraMint\LaravelBrain\Analysis\Reachability\DeclaredClass;
use LaraMint\LaravelBrain\Analysis\Reachability\EntryPoint;
use LaraMint\LaravelBrain\Analysis\Reachability\EntryPointInventory;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;
use LaraMint\LaravelBrain\Analysis\ScheduleEntry;

// These definitions are declared alongside their analyzers; reference the analyzer so PSR-4
// autoloading pulls the file in.
class_exists(RouteAnalyzer::class);
class_exists(ConsoleAnalyzer::class);
class_exists(ChannelAnalyzer::class);

function listenerClass(string $fqcn, array $interfaces): DeclaredClass
{
    return new DeclaredClass($fqcn, '/app/Listeners.php', 'class', 'listener', '', $interfaces);
}

it('treats a queued listener as a root and a synchronous one as not', function () {
    // A synchronous listener runs inside the call chain of whoever dispatched the event, so it
    // is reached. A queued one is picked off the queue by a worker with no caller at all, and
    // seeding from it is the only way anything it calls counts as reached.
    $edges = [
        new CallChainEdge('App\Events\OrderPlaced', 'dispatch', 'App\Listeners\NotifyWarehouse', 'handle', 'listener'),
        new CallChainEdge('App\Events\OrderPlaced', 'dispatch', 'App\Listeners\LogOrder', 'handle', 'listener'),
    ];

    $classes = ClassInventory::of([
        'App\Listeners\NotifyWarehouse' => listenerClass('App\Listeners\NotifyWarehouse', ['Illuminate\Contracts\Queue\ShouldQueue']),
        'App\Listeners\LogOrder' => listenerClass('App\Listeners\LogOrder', []),
    ]);

    $entryPoints = EntryPointInventory::collect(listenerEdges: $edges, classes: $classes);

    expect(array_map(fn (EntryPoint $e): string => $e->fqcn, $entryPoints))
        ->toBe(['App\Listeners\NotifyWarehouse']);
});

it('finds ShouldQueue on a listener that inherits it from a base class', function () {
    $edges = [
        new CallChainEdge('App\Events\OrderPlaced', 'dispatch', 'App\Listeners\NotifyWarehouse', 'handle', 'listener'),
    ];

    $classes = ClassInventory::of([
        'App\Listeners\NotifyWarehouse' => listenerClass('App\Listeners\NotifyWarehouse', []),
        'App\Listeners\QueuedListener' => new DeclaredClass(
            'App\Listeners\QueuedListener',
            '/app/Listeners/QueuedListener.php',
            'abstract_class',
            'abstract_class',
            '',
            ['Illuminate\Contracts\Queue\ShouldQueue'],
        ),
    ]);

    expect(EntryPointInventory::collect(listenerEdges: $edges, classes: $classes))->toBe([]);

    $classes = ClassInventory::of([
        'App\Listeners\NotifyWarehouse' => new DeclaredClass(
            'App\Listeners\NotifyWarehouse',
            '/app/Listeners/NotifyWarehouse.php',
            'class',
            'listener',
            'App\Listeners\QueuedListener',
        ),
        'App\Listeners\QueuedListener' => new DeclaredClass(
            'App\Listeners\QueuedListener',
            '/app/Listeners/QueuedListener.php',
            'abstract_class',
            'abstract_class',
            '',
            ['Illuminate\Contracts\Queue\ShouldQueue'],
        ),
    ]);

    expect(EntryPointInventory::collect(listenerEdges: $edges, classes: $classes))->toHaveCount(1);
});

it('gives routes, commands, channels and schedule entries the node id the graph built them under', function () {
    // The FQCN alone cannot find these: their node id is built from a signature, and a closure
    // route or closure command has no class at all. Get an id wrong and the walk silently
    // starts from nothing, which reads as a healthy application with no reachable code.
    $entryPoints = EntryPointInventory::collect(
        routes: [new RouteDefinition('POST', '/orders', '', '', [], '', '/routes/web.php', 1)],
        commands: [new ConsoleCommandDefinition('orders:sync', '', '', '/routes/console.php', 'route')],
        schedules: [new ScheduleEntry('command', 'orders:sync', 'daily', '/routes/console.php')],
        channels: [new ChannelDefinition('orders.{id}', '', '/routes/channels.php')],
    );

    $ids = [];
    foreach ($entryPoints as $entryPoint) {
        $ids[$entryPoint->kind] = $entryPoint->nodeIds;
    }

    expect($ids)->toBe([
        EntryPoint::KIND_ROUTE => ['route::POST::/orders'],
        EntryPoint::KIND_COMMAND => ['command::orders:sync'],
        EntryPoint::KIND_SCHEDULE => ['schedule::'.md5('command'.'orders:sync'.'daily')],
        EntryPoint::KIND_CHANNEL => ['channel::'.md5('orders.{id}')],
    ]);
});

it('takes a scheduled job class as its own root but leaves a scheduled command to its own entry', function () {
    $entryPoints = EntryPointInventory::collect(schedules: [
        new ScheduleEntry('job', 'App\Jobs\RebuildIndex', 'hourly', '/routes/console.php'),
        new ScheduleEntry('command', 'orders:sync', 'daily', '/routes/console.php'),
    ]);

    expect($entryPoints[0]->fqcn)->toBe('App\Jobs\RebuildIndex')
        ->and($entryPoints[1]->fqcn)->toBe('');
});
