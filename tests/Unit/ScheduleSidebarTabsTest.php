<?php

use LaraMint\LaravelBrain\Analysis\ConsoleAnalyzer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\ScheduleEntry;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphBuilder;
use LaraMint\LaravelBrain\Graph\GraphSplitter;
use LaraMint\LaravelBrain\Graph\TabManifestEntry;

// ScheduleEntry is declared alongside ConsoleAnalyzer in ConsoleAnalyzer.php.
class_exists(ConsoleAnalyzer::class);

/**
 * The real builder, not hand-made nodes: the id a tab is seeded from and the id the node was
 * created under have to be the same string, and they used to be spelled out separately.
 *
 * @param  ScheduleEntry[]  $schedules
 */
function scheduleGraph(array $schedules): Graph
{
    $builder = new GraphBuilder;
    $graph = $builder->build('proj', [], new MiddlewareRegistry([], [], []), [], [], []);
    $builder->addConsoleCommands([], $schedules);

    return $graph;
}

/**
 * @param  ScheduleEntry[]  $schedules
 * @return array{subgraphs: array<string, Graph>, manifest: TabManifestEntry[]}
 */
function splitSchedules(array $schedules): array
{
    return (new GraphSplitter)->split(
        scheduleGraph($schedules), [], [], [], $schedules, 'proj', '2026-05-16T00:00:00Z',
    );
}

it('gives every scheduled task its own sidebar row', function () {
    // One "Scheduled Tasks" tab put a single row in the sidebar however many tasks the app
    // had, so the list answered "does this app schedule anything?" and nothing else.
    $split = splitSchedules([
        new ScheduleEntry('command', 'reports:nightly', 'dailyAt', 'routes/console.php', ['03:00']),
        new ScheduleEntry('job', 'App\\Jobs\\PruneOldExports', 'cron', 'routes/console.php', ['0 4 * * 1']),
    ]);

    $labels = array_map(fn ($entry): string => $entry->label, $split['manifest']);

    expect($labels)->toBe(['reports:nightly', 'App\\Jobs\\PruneOldExports'])
        ->and($split['manifest'][0]->category)->toBe('Schedule');
});

it('carries when and how a task runs on the row itself', function () {
    // The sidebar renders the row before any graph file is fetched, so anything it must show
    // without the reader opening the tab has to travel on the manifest.
    $split = splitSchedules([
        new ScheduleEntry(
            'command',
            'reports:nightly',
            'dailyAt',
            'routes/console.php',
            ['03:00'],
            ['withoutOverlapping', 'onOneServer'],
            'Europe/Warsaw',
        ),
    ]);

    expect($split['manifest'][0]->schedule)->toBe([
        'type' => 'command',
        'target' => 'reports:nightly',
        'cadence' => 'dailyAt 03:00',
        'timezone' => 'Europe/Warsaw',
        'modifiers' => ['withoutOverlapping', 'onOneServer'],
    ]);
});

it('emits the schedule payload in the manifest JSON, and only on schedule tabs', function () {
    $schedules = [new ScheduleEntry('command', 'reports:nightly', 'hourly', 'routes/console.php')];
    $graph = scheduleGraph($schedules);

    $splitter = new GraphSplitter;
    $split = $splitter->split($graph, [], [], [], $schedules, 'proj', '2026-05-16T00:00:00Z');
    $tabs = json_decode($splitter->buildManifestJson($split['manifest'], $graph, 'proj', '2026-05-16T00:00:00Z', 0), true)['tabs'];

    expect($tabs)->toHaveCount(1)
        ->and($tabs[0]['schedule']['cadence'])->toBe('hourly')
        ->and($tabs[0]['schedule']['modifiers'])->toBe([]);
});

it('seeds each tab from its own schedule node', function () {
    $split = splitSchedules([
        new ScheduleEntry('command', 'reports:nightly', 'dailyAt', 'routes/console.php', ['03:00']),
        new ScheduleEntry('command', 'reports:nightly', 'dailyAt', 'routes/console.php', ['17:00']),
    ]);

    // Same command, two runs: two rows, each showing its own time, and neither showing the
    // other's node. Hashing the cadence method alone collapsed them into a single node.
    $labels = array_map(fn ($entry): string => $entry->schedule['cadence'] ?? '', $split['manifest']);
    $ids = array_map(fn ($entry): string => $entry->id, $split['manifest']);

    expect($labels)->toBe(['dailyAt 03:00', 'dailyAt 17:00'])
        ->and($ids)->toBe(['schedule-command-reports-nightly', 'schedule-command-reports-nightly-2'])
        ->and($split['subgraphs']['schedule-command-reports-nightly']->nodeCount())->toBe(1)
        ->and($split['subgraphs']['schedule-command-reports-nightly-2']->nodeCount())->toBe(1);
});

it('does not repeat a task that was analysed twice', function () {
    $entry = new ScheduleEntry('command', 'reports:nightly', 'hourly', 'routes/console.php');
    $split = splitSchedules([$entry, clone $entry]);

    expect($split['manifest'])->toHaveCount(1);
});
