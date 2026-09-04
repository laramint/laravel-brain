<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::post('/orders', [OrderController::class, 'store']);
Route::post('/orders/{order}/ship', [OrderController::class, 'ship']);
Route::post('/orders/{order}/refund', [OrderController::class, 'refund']);
Route::post('/orders/{order}/archive', [OrderController::class, 'archive']);
