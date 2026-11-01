<?php

use App\Http\Controllers\Api\V1\Erp\AssemblyDisplayController;
use App\Http\Middleware\EnsureErpAuthenticated;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/erp/public/assembly-display')->withoutMiddleware(EnsureErpAuthenticated::class)->group(function () {
    Route::get('work-orders', [AssemblyDisplayController::class, 'index']);
    Route::get('work-orders/{id}', [AssemblyDisplayController::class, 'show'])->where('id', '[1-9][0-9]{0,17}');
    Route::get('work-orders/{id}/history', [AssemblyDisplayController::class, 'history'])->where('id', '[1-9][0-9]{0,17}');
    Route::get('changes/{id}', [AssemblyDisplayController::class, 'change'])->where('id', '[1-9][0-9]{0,17}');
});
