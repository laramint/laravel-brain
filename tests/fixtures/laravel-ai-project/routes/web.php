<?php

use App\Http\Controllers\SupportController;
use Illuminate\Support\Facades\Route;

Route::get('/support', [SupportController::class, 'index']);
Route::post('/support/ask', [SupportController::class, 'ask']);
