<?php

use LaraMint\LaravelBrain\Analysis\ConsoleAnalyzer;
use LaraMint\LaravelBrain\Analysis\ConsoleCommandDefinition;
use LaraMint\LaravelBrain\Analysis\ScheduleEntry;

function modularConsoleAnalyzer(): ConsoleAnalyzer
{
    return new ConsoleAnalyzer(
        consoleRoutePaths: ['packages/*/routes/*.php'],
        classPaths: ['packages/shop/src/Console'],
        kernelPaths: [],
    );
}

/** @return array<string, ConsoleCommandDefinition> */
function commandsBySignature(): array
{
    $result = modularConsoleAnalyzer()->analyze(fixture('modular-project'));
    $bySignature = [];

    foreach ($result['commands'] as $command) {
        $bySignature[$command->signature] = $command;
    }

    return $bySignature;
}

it('extracts a command declared with the Signature and Description attributes', function () {
    $commands = commandsBySignature();
    $signature = 'shop:sync-orders {--since= : Only orders updated after this date}';

    expect($commands)->toHaveKey($signature)
        ->and($commands[$signature]->description)->toBe('Pull orders from the storefront')
        ->and($commands[$signature]->class)->toBe('Acme\\Shop\\Console\\SyncOrdersCommand');
});

it('extracts a command declared with the AsCommand attribute', function () {
    $commands = commandsBySignature();

    expect($commands)->toHaveKey('shop:import-products')
        ->and($commands['shop:import-products']->description)->toBe('Import the product feed');
});

it('extracts a command that names itself through the legacy $name property', function () {
    $commands = commandsBySignature();

    expect($commands)->toHaveKey('shop:legacy')
        ->and($commands['shop:legacy']->description)->toBe('The pre-signature spelling');
});

it('lets a $signature property win over a Signature attribute', function () {
    $commands = commandsBySignature();

    expect($commands)->toHaveKey('shop:from-property')
        ->and($commands)->not->toHaveKey('shop:from-attribute');
});

it('reads schedule entries out of routes/schedule.php', function () {
    $result = modularConsoleAnalyzer()->analyze(fixture('modular-project'));

    // Laravel's own skeleton keeps the schedule in routes/console.php; a schedule split
    // into its own file is the layout the "console" keyword alone never reaches.
    $targets = array_map(fn (ScheduleEntry $entry): string => $entry->target, $result['schedule']);

    expect($targets)->toContain('shop:sync-orders')
        ->and($targets)->toContain('Acme\\Shop\\Jobs\\ReconcilePayouts');
});

it('reads the cadence off the scheduling chain', function () {
    $result = modularConsoleAnalyzer()->analyze(fixture('modular-project'));

    $byTarget = [];
    foreach ($result['schedule'] as $entry) {
        $byTarget[$entry->target] = $entry;
    }

    expect($byTarget['shop:sync-orders']->frequency)->toBe('dailyAt')
        ->and($byTarget['shop:sync-orders']->type)->toBe('command')
        ->and($byTarget['Acme\\Shop\\Jobs\\ReconcilePayouts']->frequency)->toBe('hourly')
        ->and($byTarget['Acme\\Shop\\Jobs\\ReconcilePayouts']->type)->toBe('job');
});

it('reads the time a cadence was given, and the guard wrapped around it', function () {
    // The method name alone does not answer the question the schedule list exists to answer:
    // `dailyAt` is not a time. The argument is.
    $result = modularConsoleAnalyzer()->analyze(fixture('modular-project'));

    $byTarget = [];
    foreach ($result['schedule'] as $entry) {
        $byTarget[$entry->target] = $entry;
    }

    expect($byTarget['shop:sync-orders']->frequencyArguments)->toBe(['05:30'])
        ->and($byTarget['shop:sync-orders']->cadence())->toBe('dailyAt 05:30')
        ->and($byTarget['shop:sync-orders']->modifiers)->toBe(['withoutOverlapping'])
        ->and($byTarget['Acme\\Shop\\Jobs\\ReconcilePayouts']->cadence())->toBe('hourly')
        ->and($byTarget['Acme\\Shop\\Jobs\\ReconcilePayouts']->modifiers)->toBe([]);
});
