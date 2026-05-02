<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\InvoiceController;

Route::get('/billing/invoices', [InvoiceController::class, 'index']);

