<?php

use App\Http\Controllers\Api\V1\Erp\FinanceController;
use App\Http\Controllers\Api\V1\Erp\PurchasePaymentPlanController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/erp/purchase')->group(function (): void {
    Route::get('payment-orders', [PurchasePaymentPlanController::class, 'orders']);
    Route::get('orders/{id}/payment-plan', [PurchasePaymentPlanController::class, 'show'])->whereNumber('id');
    Route::put('orders/{id}/payment-plan', [PurchasePaymentPlanController::class, 'update'])->whereNumber('id');
});
Route::prefix('v1/erp/finance')->group(function (): void {
    Route::get('purchase-payment-statistics', [PurchasePaymentPlanController::class, 'statistics']);
    Route::put('cash-documents/{id}/purchase-orders', [FinanceController::class, 'replacePurchaseOrderAllocations'])->whereNumber('id');
});
