<?php

use LaraMint\LaravelBrain\Analysis\ConsoleAnalyzer;
use LaraMint\LaravelBrain\Analysis\ConsoleCommandDefinition;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\ScheduleEntry;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

/**
 * A command node is keyed by its whole signature; a schedule names the command it runs. The two
 * spellings agree only for a command that declares no options at all — measured on a 60-module
 * application, 4 of 59 scheduled tasks found their command and the other 55 drew as a lone node
 * with nothing under them.
 *
 * @param  ConsoleCommandDefinition[]  $commands
 * @param  ScheduleEntry[]  $schedules
 */
function scheduleEdgeTypes(array $commands, array $schedules): array
{
    $builder = new GraphBuilder;
    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], [], []);
    $builder->addConsoleCommands($commands, $schedules);

    return array_map(fn ($edge): string => $edge->type, $graph->edges());
}

function optionedCommand(): ConsoleCommandDefinition
{
    // ConsoleCommandDefinition is declared inside ConsoleAnalyzer.php rather than a file of its
    // own, so PSR-4 cannot autoload it by name. Touching the analyzer loads the file that
    // declares both.
    class_exists(ConsoleAnalyzer::class);

    return new ConsoleCommandDefinition(
        signature: "reports:nightly\n    {--force : Ignore the dedup lock}\n    {--tenant=* : The tenant(s) to run for.}",
        description: 'Nightly reports',
        class: 'App\\Console\\NightlyReportsCommand',
        file: 'app/Console/NightlyReportsCommand.php',
        source: 'class',
    );
}

it('links a schedule to a command whose signature declares options', function () {
    $edges = scheduleEdgeTypes(
        [optionedCommand()],
        [new ScheduleEntry('command', 'reports:nightly', 'dailyAt', 'routes/console.php', ['03:00'])],
    );

    expect($edges)->toContain('schedule-to-command');
});

it('links a schedule that names its command by class', function () {
    // `Schedule::command(NightlyReportsCommand::class)` carries the FQCN, which matches neither
    // the signature nor the command name.
    $edges = scheduleEdgeTypes(
        [optionedCommand()],
        [new ScheduleEntry('command', 'App\\Console\\NightlyReportsCommand', 'hourly', 'routes/console.php')],
    );

    expect($edges)->toContain('schedule-to-command');
});

it('draws no edge to a command the application does not define', function () {
    // The honest remainder: a scheduled vendor command has no node, and inventing one would
    // put a class on the graph that this application never declares.
    $edges = scheduleEdgeTypes(
        [optionedCommand()],
        [new ScheduleEntry('command', 'tenants:artisan', 'everyMinute', 'routes/console.php')],
    );

    expect($edges)->not->toContain('schedule-to-command');
});
