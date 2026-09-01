<?php

namespace App\Console;

use App\Jobs\PruneOldExports;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    /**
     * The parameter is deliberately NOT named $schedule: the scheduler is found by reading
     * the signature, not by assuming the name Laravel's stub happens to use.
     */
    protected function schedule(Schedule $tasks): void
    {
        $tasks->command('reports:nightly')->dailyAt('03:00')->withoutOverlapping()->onOneServer();

        $tasks->job(new PruneOldExports)->cron('0 4 * * 1');

        $tasks->call(function () {
            // A closure task has no class behind it, so its own body is the only thing a reader
            // can be shown. These statements exist so a test can prove the body is read.
            Cache::forget('reports.stale');

            foreach (Report::query()->cursor() as $report) {
                $report->archive();
            }
        })->everyTenMinutes();

        // Not a scheduled task. Matching the bare method name anywhere in the file turned
        // this into a scheduled closure running on no cadence at all.
        $this->app->call([$this, 'warmCaches']);
    }

    public function warmCaches(): void
    {
        //
    }
}
