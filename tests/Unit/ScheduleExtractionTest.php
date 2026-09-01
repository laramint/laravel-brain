<?php

use LaraMint\LaravelBrain\Analysis\ConsoleAnalyzer;
use LaraMint\LaravelBrain\Analysis\ScheduleEntry;

// ScheduleEntry is declared alongside ConsoleAnalyzer in ConsoleAnalyzer.php;
// reference ConsoleAnalyzer so PSR-4 autoloading pulls in that file.
class_exists(ConsoleAnalyzer::class);

/** @return array<string, ScheduleEntry> */
function scheduledProjectByTarget(): array
{
    $result = (new ConsoleAnalyzer(
        consoleRoutePaths: ['routes/*.php'],
        classPaths: [],
        kernelPaths: ConsoleAnalyzer::DEFAULT_KERNEL_PATHS,
    ))->analyze(fixture('scheduled-project'));

    $byTarget = [];
    foreach ($result['schedule'] as $entry) {
        $byTarget[$entry->target] = $entry;
    }

    return $byTarget;
}

it('reads the cadence off a legacy Kernel::schedule() chain', function () {
    // The registration sits INSIDE the cadence call there — `$tasks->command('x')->daily()` —
    // so walking outward from the registration reaches only the scheduler variable. Every
    // legacy-kernel entry used to be recorded with an empty frequency.
    $entries = scheduledProjectByTarget();

    expect($entries)->toHaveKey('reports:nightly')
        ->and($entries['reports:nightly']->frequency)->toBe('dailyAt')
        ->and($entries['reports:nightly']->frequencyArguments)->toBe(['03:00'])
        ->and($entries['reports:nightly']->cadence())->toBe('dailyAt 03:00');
});

it('records the guards that decide whether a due task actually runs', function () {
    $entries = scheduledProjectByTarget();

    expect($entries['reports:nightly']->modifiers)->toBe(['withoutOverlapping', 'onOneServer']);
});

it('keeps the cron expression, not the word cron', function () {
    $entries = scheduledProjectByTarget();

    expect($entries['App\\Jobs\\PruneOldExports']->frequency)->toBe('cron')
        ->and($entries['App\\Jobs\\PruneOldExports']->cadence())->toBe('0 4 * * 1');
});

it('finds a scheduling closure passed to withSchedule() in bootstrap/app.php', function () {
    // A Laravel 11+ skeleton has no Console Kernel at all, so scanning only the legacy path
    // found no schedule in it whatsoever.
    $entries = scheduledProjectByTarget();

    expect($entries)->toHaveKey('cache:prune-stale-tags')
        ->and($entries['cache:prune-stale-tags']->cadence())->toBe('hourly')
        ->and($entries['cache:prune-stale-tags']->modifiers)->toBe(['onOneServer']);
});

it('resolves a job scheduled by class name and keeps its timezone out of the cadence', function () {
    $entries = scheduledProjectByTarget();

    expect($entries)->toHaveKey('App\\Jobs\\ReconcileLedger')
        ->and($entries['App\\Jobs\\ReconcileLedger']->type)->toBe('job')
        ->and($entries['App\\Jobs\\ReconcileLedger']->cadence())->toBe('weeklyOn 1, 08:00')
        ->and($entries['App\\Jobs\\ReconcileLedger']->timezone)->toBe('Europe/Warsaw');
});

it('ignores a ->call() made on something that is not the scheduler', function () {
    // `$this->app->call(...)` in the same method used to be recorded as a scheduled closure.
    $entries = scheduledProjectByTarget();
    $closures = array_filter($entries, fn (ScheduleEntry $entry): bool => $entry->type === 'call');

    expect($closures)->toHaveCount(1)
        ->and(array_values($closures)[0]->cadence())->toBe('everyTenMinutes');
});

it('reads the Schedule facade form out of routes/console.php', function () {
    $entries = scheduledProjectByTarget();

    expect($entries)->toHaveKey('inspire')
        ->and($entries['inspire']->cadence())->toBe('everyFifteenMinutes');
});

it('separates two runs of one command by their cadence arguments', function () {
    // The node id is what the sidebar seeds a tab from, so a command scheduled at 05:00 and
    // again at 17:00 has to hash to two ids or the second run is invisible.
    $morning = new ScheduleEntry('command', 'reports:send', 'dailyAt', 'x.php', ['05:00']);
    $evening = new ScheduleEntry('command', 'reports:send', 'dailyAt', 'x.php', ['17:00']);

    expect($morning->nodeId())->not->toBe($evening->nodeId());
});
