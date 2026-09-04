<?php

use App\Http\Controllers\OfferController;
use Illuminate\Support\Facades\Route;

Route::get('/offers', [OfferController::class, 'index']);
Route::get('/reports', [OfferController::class, 'reports']);
