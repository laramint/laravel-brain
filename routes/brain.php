<?php

use Illuminate\Support\Facades\Route;
use LaraMint\LaravelBrain\Http\Controllers\BrainController;

Route::prefix(config('laravel-brain.route_prefix', '_laravel-brain'))->group(function () {
    Route::get('/api/source', [BrainController::class, 'source']);
    Route::post('/api/scan', [BrainController::class, 'scan']);
    Route::post('/api/stress-test', [BrainController::class, 'stressTest']);
    Route::get('/api/stress-test/{jobId}', [BrainController::class, 'stressTestPoll']);
    Route::get('/api/context', [BrainController::class, 'context']);
    Route::post('/api/generate-rules', [BrainController::class, 'generateRules']);
    Route::get('/{any?}', [BrainController::class, 'serve'])->where('any', '.*');
});
