<?php

use App\Http\Controllers\LedgerController;
use App\Services\Reconciler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/ledger', [LedgerController::class, 'store']);

Route::post('/reconcile', function (): void {
    DB::transaction(function (): void {
        (new Reconciler)->handle();
    });
});
