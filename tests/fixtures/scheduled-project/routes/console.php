<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    //
})->purpose('Display an inspiring quote');

Schedule::command('inspire')->everyFifteenMinutes();
