<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', [DashboardController::class, 'show']);
Route::post('/dashboard/refresh', [DashboardController::class, 'refresh']);
Route::get('/dashboard/plain', [DashboardController::class, 'plain']);
