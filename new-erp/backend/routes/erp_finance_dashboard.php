<?php

use App\Http\Controllers\Api\V1\Erp\FinanceDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('v1/erp/finance/dashboard', [FinanceDashboardController::class, 'show']);
