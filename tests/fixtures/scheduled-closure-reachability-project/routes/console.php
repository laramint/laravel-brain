<?php

use App\Services\HourlyCleanup;
use App\Services\NightlyCleanup;
use Illuminate\Support\Facades\Schedule;

// A cadence that takes arguments is part of the schedule's node id, so the walk has to seed from
// the same id the tracer hung this closure's calls on.
Schedule::call(function () {
    (new NightlyCleanup)->run();
})->dailyAt('05:30');

Schedule::call(function () {
    (new HourlyCleanup)->run();
})->hourly();
