<?php

use App\Http\Controllers\Api\V1\Erp\SupplierFinanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/erp/finance/supplier-finance')->group(function (): void {
    Route::get('statistics', [SupplierFinanceController::class, 'statistics']);
    Route::get('{supplierId}/entries', [SupplierFinanceController::class, 'entries'])->whereNumber('supplierId');
});
