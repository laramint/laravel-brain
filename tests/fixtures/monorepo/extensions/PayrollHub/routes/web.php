<?php

use Extensions\PayrollHub\Http\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

Route::get('/payroll/employees', [EmployeeController::class, 'index']);
