<?php

use Illuminate\Support\Facades\Route;
use Shared\Controllers\SharedRouteController;

Route::get('/shared/ping', [SharedRouteController::class, 'index']);

