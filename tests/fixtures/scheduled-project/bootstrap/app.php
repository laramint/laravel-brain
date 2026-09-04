<?php

use App\Jobs\ReconcileLedger;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('cache:prune-stale-tags')->hourly()->onOneServer();

        $schedule->job(ReconcileLedger::class)->weeklyOn(1, '08:00')->timezone('Europe/Warsaw');
    })
    ->create();
