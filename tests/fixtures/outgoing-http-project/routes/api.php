<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::post('/payments', [PaymentController::class, 'store']);
Route::get('/payments/status', [PaymentController::class, 'status']);
