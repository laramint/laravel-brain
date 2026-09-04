<?php

use Illuminate\Support\Facades\Route;
use LaraMint\LaravelBrain\Http\Controllers\BrainController;
use LaraMint\LaravelBrain\Http\Middleware\EnsureRequestIsSameOrigin;

Route::prefix('_laravel-brain')->group(function () {
    Route::get('/api/source', [BrainController::class, 'source']);
    Route::post('/api/scan', [BrainController::class, 'scan'])->middleware(EnsureRequestIsSameOrigin::class);
    Route::post('/api/stress-test', [BrainController::class, 'stressTest'])->middleware(EnsureRequestIsSameOrigin::class);
    Route::get('/api/stress-test/{jobId}', [BrainController::class, 'stressTestPoll']);
    Route::get('/api/context', [BrainController::class, 'context']);
    Route::get('/api/usages', [BrainController::class, 'usages']);
    Route::post('/api/generate-rules', [BrainController::class, 'generateRules'])->middleware(EnsureRequestIsSameOrigin::class);
    Route::get('/{any?}', [BrainController::class, 'serve'])->where('any', '.*');
});
