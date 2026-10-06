<?php

use App\Http\Controllers\Api\V1\Erp\SalesOrderFinanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/erp')->group(function (): void {
    Route::get('finance/sales-order-statistics', [SalesOrderFinanceController::class, 'statistics']);
    Route::prefix('sales/orders/{id}/finance')->whereNumber('id')->group(function (): void {
        Route::get('/', [SalesOrderFinanceController::class, 'overview']);
        Route::get('purchase-links', [SalesOrderFinanceController::class, 'links']);
        Route::get('purchase-candidates', [SalesOrderFinanceController::class, 'candidates']);
        Route::get('purchase-categories', [SalesOrderFinanceController::class, 'categories']);
        Route::post('purchase-links', [SalesOrderFinanceController::class, 'add']);
        Route::post('purchase-links/{linkId}/reverse', [SalesOrderFinanceController::class, 'reverse'])->whereNumber('linkId');
    });
});
