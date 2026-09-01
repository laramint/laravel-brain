<?php

use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// The dedicated helpers. `options` has always existed; `query` only exists from the 14.x line
// (laravel/framework#60655), and is written here to prove the analyzer reads it out of source
// without the installed framework needing to be able to register it.
Route::options('/preflight', [SearchController::class, 'preflight']);
Route::query('/search', [SearchController::class, 'search']);

// The spelling every supported version can register.
Route::match(['options', 'query'], '/catalog', [SearchController::class, 'index']);

// Laravel accepts a bare string as well as a list.
Route::match('query', '/single', [SearchController::class, 'search']);

// HEAD rides along with GET in Laravel and is dropped, as it is in live-router mode.
Route::match(['get', 'head'], '/head-dropped', [SearchController::class, 'index']);

// Every verb the router knows, minus HEAD.
Route::any('/anything', [SearchController::class, 'anything']);

// Group context and post-route chaining have to survive the multi-verb expansion.
Route::prefix('v2')->middleware(['auth:sanctum'])->group(function () {
    Route::match(['options', 'query'], '/chained', [SearchController::class, 'index'])
        ->middleware('throttle:api');
});

// A verb list assembled at runtime cannot be read statically, and is skipped rather than guessed.
$verbs = ['get', 'post'];
Route::match($verbs, '/dynamic', [SearchController::class, 'index']);
