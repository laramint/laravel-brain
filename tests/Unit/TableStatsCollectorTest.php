<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use LaraMint\LaravelBrain\Analysis\TableStats;
use LaraMint\LaravelBrain\Analysis\TableStatsCollector;

/**
 * The collector reads a live database, so these drive a real one — an in-memory SQLite through
 * the same Capsule the store tests use — rather than asserting against a mock that would agree
 * with whatever the code happens to do.
 */
function bootTableStatsDatabase(): Capsule
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'default');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $capsule->schema()->create('widgets', function ($table): void {
        $table->increments('id');
        $table->string('name');
    });

    return $capsule;
}

it('reports a total size for every table the connection holds', function () {
    $capsule = bootTableStatsDatabase();

    $stats = (new TableStatsCollector($capsule->getConnection()))->collect();

    expect($stats)->toHaveKey('widgets')
        ->and($stats['widgets'])->toBeInstanceOf(TableStats::class)
        ->and($stats['widgets']->table)->toBe('widgets');
});

it('leaves a figure the driver cannot answer for as null rather than zero', function () {
    // SQLite reports a size and nothing else: no row estimate short of counting, and no split
    // between heap and indexes. Zero would read as an empty table, which is a different claim.
    $capsule = bootTableStatsDatabase();

    $stats = (new TableStatsCollector($capsule->getConnection()))->collect();

    expect($stats['widgets']->rows)->toBeNull()
        ->and($stats['widgets']->indexBytes)->toBeNull();
});

it('hands back no collector at all when the connection cannot be resolved', function () {
    // The ordinary case for a CI run, and the one that must not stop a scan. Without a container
    // there is no connection to resolve, which is the same failure a missing database reaches.
    expect(TableStatsCollector::forConnection('no-such-connection'))->toBeNull();
});

it('carries every measured figure through to the array the graph stores', function () {
    $stats = new TableStats(
        table: 'orders',
        rows: 1_402_881,
        tableBytes: 30_220_288,
        indexBytes: 12_058_624,
        totalBytes: 42_278_912,
        rowsEstimated: true,
    );

    expect($stats->toArray())->toBe([
        'rows' => 1_402_881,
        'tableBytes' => 30_220_288,
        'indexBytes' => 12_058_624,
        'totalBytes' => 42_278_912,
        'rowsEstimated' => true,
    ]);
});
