<?php

use App\Http\Controllers\Api\V1\Erp\ImportController;
use App\Http\Controllers\Api\V1\Erp\BomController;
use App\Http\Controllers\Api\V1\Erp\AftersalesReservedController;
use App\Http\Controllers\Api\V1\Erp\AuthController;
use App\Http\Controllers\Api\V1\Erp\ApprovalController;
use App\Http\Controllers\Api\V1\Erp\ApprovalFormController;
use App\Http\Controllers\Api\V1\Erp\DepartmentController;
use App\Http\Controllers\Api\V1\Erp\DocumentNumberController;
use App\Http\Controllers\Api\V1\Erp\FinanceController;
use App\Http\Controllers\Api\V1\Erp\InventoryAdjustmentController;
use App\Http\Controllers\Api\V1\Erp\InventoryAlertController;
use App\Http\Controllers\Api\V1\Erp\InventoryBalanceController;
use App\Http\Controllers\Api\V1\Erp\InventoryPostingController;
use App\Http\Controllers\Api\V1\Erp\InventoryQualityController;
use App\Http\Controllers\Api\V1\Erp\InventoryTransactionController;
use App\Http\Controllers\Api\V1\Erp\ItemCategoryController;
use App\Http\Controllers\Api\V1\Erp\ItemPurchaseConversionController;
use App\Http\Controllers\Api\V1\Erp\ItemMaterialPolicyController;
use App\Http\Controllers\Api\V1\Erp\ItemIntegratedFormController;
use App\Http\Controllers\Api\V1\Erp\MasterDataController;
use App\Http\Controllers\Api\V1\Erp\PurchaseController;
use App\Http\Controllers\Api\V1\Erp\PurchaseExchangeController;
use App\Http\Controllers\Api\V1\Erp\PurchaseReturnController;
use App\Http\Controllers\Api\V1\Erp\PurchaseSupplierRecommendationController;
use App\Http\Controllers\Api\V1\Erp\ProductionWorkOrderController;
use App\Http\Controllers\Api\V1\Erp\ProductionMasterDataController;
use App\Http\Controllers\Api\V1\Erp\RbacController;
use App\Http\Controllers\Api\V1\Erp\SalesOrderController;
use App\Http\Controllers\Api\V1\Erp\SalesShipmentController;
use App\Http\Controllers\Api\V1\Erp\SalesReturnController;
use App\Http\Controllers\Api\V1\Erp\SalesCustomerController;
use App\Http\Controllers\Api\V1\Erp\SkuItemRelationController;
use App\Http\Controllers\Api\V1\Erp\SupplierCapabilityController;
use App\Http\Controllers\Api\V1\Erp\UserDirectoryController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['api']]);

Route::prefix('v1/erp/approvals')->group(function () {
    Route::get('forms', [ApprovalFormController::class, 'index']);
    Route::get('forms/summary', [ApprovalFormController::class, 'summary']);
    Route::post('forms/validate', [ApprovalFormController::class, 'validateSchema']);
    Route::post('forms', [ApprovalFormController::class, 'store']);
    Route::get('forms/{id}', [ApprovalFormController::class, 'show'])->whereNumber('id');
    Route::put('forms/{id}', [ApprovalFormController::class, 'update'])->whereNumber('id');
    Route::post('forms/{id}/publish', [ApprovalFormController::class, 'publish'])->whereNumber('id');
    Route::post('forms/{id}/toggle', [ApprovalFormController::class, 'toggle'])->whereNumber('id');
    Route::post('forms/{id}/submit', [ApprovalFormController::class, 'submit'])->whereNumber('id');
    Route::post('forms/{id}/copy', [ApprovalFormController::class, 'copy'])->whereNumber('id');
    Route::delete('forms/{id}', [ApprovalFormController::class, 'destroy'])->whereNumber('id');
    Route::get('tasks/summary', [ApprovalController::class, 'summary']);
    Route::get('notifications', [ApprovalController::class, 'notifications']);
    Route::post('notifications/{id}/read', [ApprovalController::class, 'readNotification'])->whereNumber('id');
    Route::get('launch-options', [ApprovalController::class, 'launchOptions']);
    Route::get('flows/{id}/source-records', [ApprovalController::class, 'launchSourceRecords'])->whereNumber('id');
    Route::post('flows/{id}/launch', [ApprovalController::class, 'launchFlow'])->whereNumber('id');
    Route::get('tasks', [ApprovalController::class, 'tasks']);
    Route::post('tasks/batch-decision', [ApprovalController::class, 'batchDecide']);
    Route::get('tasks/{id}', [ApprovalController::class, 'showTask'])->whereNumber('id');
    Route::post('tasks/{id}/decision', [ApprovalController::class, 'decideTask'])->whereNumber('id');
    Route::post('tasks/{id}/transfer', [ApprovalController::class, 'transferTask'])->whereNumber('id');
    Route::post('tasks/{id}/retry-action', [ApprovalController::class, 'retryTaskAction'])->whereNumber('id');
    Route::post('tasks/submit/{businessType}', [ApprovalController::class, 'submitBusinessFlow'])->where('businessType', '[A-Z][A-Z0-9_]*');
    Route::post('tasks/{id}/attachments', [ApprovalController::class, 'uploadTaskAttachment'])->whereNumber('id');
    Route::get('attachments/{attachmentId}/preview', [ApprovalController::class, 'previewTaskAttachment'])->whereNumber('attachmentId');
    Route::delete('attachments/{attachmentId}', [ApprovalController::class, 'deleteTaskAttachment'])->whereNumber('attachmentId');
    Route::get('flows/summary', [ApprovalController::class, 'flowSummary']);
    Route::get('flows/config-options', [ApprovalController::class, 'flowConfigOptions']);
    Route::get('registry/candidates', [ApprovalController::class, 'registryCandidates']);
    Route::get('registry/candidate', [ApprovalController::class, 'registryCandidate']);
    Route::post('registry/business-objects', [ApprovalController::class, 'registerBusinessObject']);
    Route::post('flows/validate', [ApprovalController::class, 'validateFlow']);
    Route::get('flows', [ApprovalController::class, 'flows']);
    Route::post('flows', [ApprovalController::class, 'storeFlow']);
    Route::get('flows/{id}', [ApprovalController::class, 'showFlow'])->whereNumber('id');
    Route::put('flows/{id}', [ApprovalController::class, 'updateFlow'])->whereNumber('id');
    Route::post('flows/{id}/publish', [ApprovalController::class, 'publishFlow'])->whereNumber('id');
    Route::post('flows/{id}/toggle', [ApprovalController::class, 'toggleFlow'])->whereNumber('id');
    Route::post('flows/{id}/copy', [ApprovalController::class, 'copyFlow'])->whereNumber('id');
});


Route::prefix('v1/erp/purchase')->group(function () {
    Route::post('attachments/upload', [PurchaseController::class, 'uploadAttachment']);
    Route::get('attachments/{id}/preview', [PurchaseController::class, 'previewAttachment'])->whereNumber('id');
    Route::get('attachments/{id}/download', [PurchaseController::class, 'downloadAttachment'])->whereNumber('id');
    Route::delete('attachments/{id}', [PurchaseController::class, 'deleteAttachment'])->whereNumber('id');
    Route::get('items/{itemId}/supplier-recommendations', [PurchaseSupplierRecommendationController::class, 'index'])->whereNumber('itemId');
    Route::get('requests', [PurchaseController::class, 'requests']);
    Route::post('requests', [PurchaseController::class, 'storeRequest']);
    Route::get('requests/{id}', [PurchaseController::class, 'showRequest'])->whereNumber('id');
    Route::put('requests/{id}', [PurchaseController::class, 'updateRequest'])->whereNumber('id');
    Route::delete('requests/{id}', [PurchaseController::class, 'deleteRequest'])->whereNumber('id');
    Route::post('requests/{id}/submit', [PurchaseController::class, 'submitRequest'])->whereNumber('id');
    Route::post('requests/{id}/close', [PurchaseController::class, 'closeRequest'])->whereNumber('id');
    Route::post('requests/{id}/cancel', [PurchaseController::class, 'cancelRequest'])->whereNumber('id');
    Route::post('requests/{id}/to-plan', [PurchaseController::class, 'requestToPlan'])->whereNumber('id');

    Route::get('plans', [PurchaseController::class, 'plans']);
    Route::post('plans', [PurchaseController::class, 'storePlan']);
    Route::get('plans/{id}', [PurchaseController::class, 'showPlan'])->whereNumber('id');
    Route::put('plans/{id}', [PurchaseController::class, 'updatePlan'])->whereNumber('id');
    Route::delete('plans/{id}', [PurchaseController::class, 'deletePlan'])->whereNumber('id');
    Route::post('plans/{id}/submit', [PurchaseController::class, 'submitPlan'])->whereNumber('id');
    Route::post('plans/{id}/approve', [PurchaseController::class, 'approvePlan'])->whereNumber('id');
    Route::post('plans/{id}/reject', [PurchaseController::class, 'rejectPlan'])->whereNumber('id');
    Route::get('plans/{id}/orders-preview', [PurchaseController::class, 'previewPlanOrders'])->whereNumber('id');
    Route::post('plans/{id}/generate-orders', [PurchaseController::class, 'generateOrdersFromPlan'])->whereNumber('id');

    Route::get('orders', [PurchaseController::class, 'orders']);
    Route::post('orders', [PurchaseController::class, 'storeOrder']);
    Route::get('orders/{id}', [PurchaseController::class, 'showOrder'])->whereNumber('id');
    Route::put('orders/{id}', [PurchaseController::class, 'updateOrder'])->whereNumber('id');
    Route::delete('orders/{id}', [PurchaseController::class, 'deleteOrder'])->whereNumber('id');
    Route::post('orders/{id}/submit', [PurchaseController::class, 'submitOrder'])->whereNumber('id');
    Route::post('orders/{id}/approve', [PurchaseController::class, 'approveOrder'])->whereNumber('id');
    Route::post('orders/{id}/reject', [PurchaseController::class, 'rejectOrder'])->whereNumber('id');
    Route::post('orders/{id}/cancel', [PurchaseController::class, 'cancelOrder'])->whereNumber('id');
    Route::post('orders/{id}/close', [PurchaseController::class, 'closeOrder'])->whereNumber('id');
    Route::post('orders/{id}/to-receipt', [PurchaseController::class, 'orderToReceipt'])->whereNumber('id');

    Route::get('receipts', [PurchaseController::class, 'receipts']);
    Route::post('receipts', [PurchaseController::class, 'storeReceipt']);
    Route::post('receipts/serials/generate', [PurchaseController::class, 'generateReceiptSerials']);
    Route::get('receipts/{id}', [PurchaseController::class, 'showReceipt'])->whereNumber('id');
    Route::put('receipts/{id}', [PurchaseController::class, 'updateReceipt'])->whereNumber('id');
    Route::delete('receipts/{id}', [PurchaseController::class, 'deleteReceipt'])->whereNumber('id');
    Route::post('receipts/{id}/confirm', [PurchaseController::class, 'confirmReceipt'])->whereNumber('id');
    Route::get('defect-handlings', [PurchaseController::class, 'defectRows']);
    Route::post('defect-handlings', [PurchaseController::class, 'storeDefectHandling']);
    Route::post('defect-handlings/{id}/actions', [PurchaseController::class, 'actionDefectHandling'])->whereNumber('id');
    Route::get('exchange-orders', [PurchaseExchangeController::class, 'index']);
    Route::get('exchange-orders/{id}', [PurchaseExchangeController::class, 'show'])->whereNumber('id');
    Route::post('exchange-orders/{id}/actions', [PurchaseExchangeController::class, 'action'])->whereNumber('id');

    Route::get('price-histories', [PurchaseController::class, 'priceHistories']);
    Route::get('supplier-item-stats', [PurchaseController::class, 'supplierItemStats']);

    Route::get('returns/sources', [PurchaseReturnController::class, 'sources']);
    Route::get('returns/sources/{transactionItemId}/serials', [PurchaseReturnController::class, 'sourceSerials'])->whereNumber('transactionItemId');
    Route::get('returns', [PurchaseReturnController::class, 'index']);
    Route::post('returns', [PurchaseReturnController::class, 'store']);
    Route::get('returns/{id}', [PurchaseReturnController::class, 'show'])->whereNumber('id');
    Route::post('returns/{id}/submit', [PurchaseReturnController::class, 'submit'])->whereNumber('id');
    Route::post('returns/{id}/approve', [PurchaseReturnController::class, 'approve'])->whereNumber('id');
    Route::post('returns/{id}/post', [PurchaseReturnController::class, 'post'])->whereNumber('id');
    Route::post('returns/{id}/cancel', [PurchaseReturnController::class, 'cancel'])->whereNumber('id');
    Route::post('returns/{id}/close', [PurchaseReturnController::class, 'close'])->whereNumber('id');
});

Route::prefix('v1/erp/document-numbers')->group(function () {
    Route::get('rules', [DocumentNumberController::class, 'rules']);
    Route::get('rule-types', [DocumentNumberController::class, 'ruleTypes']);
    Route::post('rules/preview', [DocumentNumberController::class, 'previewRule']);
    Route::post('rules', [DocumentNumberController::class, 'storeRule']);
    Route::put('rules/{id}', [DocumentNumberController::class, 'updateRule'])->whereNumber('id');
    Route::post('rules/{id}/enable', [DocumentNumberController::class, 'enableRule'])->whereNumber('id');
    Route::post('rules/{id}/disable', [DocumentNumberController::class, 'disableRule'])->whereNumber('id');
    Route::post('reserve', [DocumentNumberController::class, 'reserve']);
    Route::get('reservations', [DocumentNumberController::class, 'index']);
    Route::post('reservations/expire', [DocumentNumberController::class, 'expire']);
});

Route::prefix('v1/erp/finance')->group(function () {
    Route::get('accounts', [FinanceController::class, 'accounts']);
    Route::get('currencies', [FinanceController::class, 'currencies']);
    Route::post('currencies', [FinanceController::class, 'storeCurrency']);
    Route::put('currencies/{id}', [FinanceController::class, 'updateCurrency'])->whereNumber('id');
    Route::get('exchange-rates', [FinanceController::class, 'exchangeRates']);
    Route::get('exchange-rate-history', [FinanceController::class, 'exchangeRateHistory']);
    Route::post('exchange-rates', [FinanceController::class, 'storeExchangeRate']);
    Route::post('exchange-rates/{id}/disable', [FinanceController::class, 'disableExchangeRate'])->whereNumber('id');
    Route::post('accounts', [FinanceController::class, 'storeAccount']);
    Route::put('accounts/{id}', [FinanceController::class, 'updateAccount'])->whereNumber('id');
      Route::post('accounts/{id}/status', [FinanceController::class, 'accountStatus'])->whereNumber('id');
      Route::get('accounts/valuations', [FinanceController::class, 'accountValuations']);
      Route::get('accounts/{id}/valuation', [FinanceController::class, 'accountValuation'])->whereNumber('id');
    Route::get('transfers', [FinanceController::class, 'transfers']);
    Route::post('transfers/preview', [FinanceController::class, 'previewTransfer']);
    Route::post('transfers', [FinanceController::class, 'storeTransfer']);
    Route::get('transfers/{id}', [FinanceController::class, 'showTransfer'])->whereNumber('id');
    Route::put('transfers/{id}', [FinanceController::class, 'updateTransfer'])->whereNumber('id');
    Route::post('transfers/{id}/confirm', [FinanceController::class, 'confirmTransfer'])->whereNumber('id');
    Route::post('transfers/{id}/void', [FinanceController::class, 'voidTransfer'])->whereNumber('id');
    Route::post('transfers/{id}/attachments', [FinanceController::class, 'uploadTransferAttachment'])->whereNumber('id');
    Route::get('cash-documents/{direction}', [FinanceController::class, 'cashDocuments'])->whereIn('direction', ['receipt', 'payment']);
    Route::post('cash-documents/{direction}', [FinanceController::class, 'storeCashDocument'])->whereIn('direction', ['receipt', 'payment']);
    Route::get('cash-documents/show/{id}', [FinanceController::class, 'showCashDocument'])->whereNumber('id');
    Route::put('cash-documents/{id}', [FinanceController::class, 'updateCashDocument'])->whereNumber('id');
    Route::post('cash-documents/{id}/confirm', [FinanceController::class, 'confirmCashDocument'])->whereNumber('id');
    Route::post('cash-documents/{id}/void', [FinanceController::class, 'voidCashDocument'])->whereNumber('id');
    Route::post('cash-documents/{id}/allocations', [FinanceController::class, 'allocate'])->whereNumber('id');
    Route::post('allocations/{id}/reverse', [FinanceController::class, 'reverseAllocation'])->whereNumber('id');
    Route::get('sources/resolve', [FinanceController::class, 'source']);
    Route::get('sources', [FinanceController::class, 'sources']);
    Route::get('payables', [FinanceController::class, 'payables']);
    Route::get('supplier-ledgers', [FinanceController::class, 'supplierLedgers']);
    Route::get('invoices', [FinanceController::class, 'invoices']);
    Route::post('invoices', [FinanceController::class, 'storeInvoice']);
    Route::get('invoices/{id}/red-preview', [FinanceController::class, 'invoiceRedPreview'])->whereNumber('id');
    Route::post('invoices/{id}/red', [FinanceController::class, 'storeRedInvoice'])->whereNumber('id');
    Route::post('invoices/{id}/red-draft', [FinanceController::class, 'storeRedInvoiceDraft'])->whereNumber('id');
    Route::post('invoices/red-drafts/{id}/confirm', [FinanceController::class, 'confirmRedInvoiceDraft'])->whereNumber('id');
    Route::get('invoices/{id}/matching-sources', [FinanceController::class, 'invoiceMatchingSources'])->whereNumber('id');
    Route::get('invoices/{id}/detail', [FinanceController::class, 'invoiceDetail'])->whereNumber('id');
    Route::get('invoices/{id}', [FinanceController::class, 'showInvoice'])->whereNumber('id');
    Route::put('invoices/{id}', [FinanceController::class, 'updateInvoice'])->whereNumber('id');
    Route::put('invoices/{id}/matches', [FinanceController::class, 'saveInvoiceMatches'])->whereNumber('id');
    Route::post('invoices/{id}/confirm', [FinanceController::class, 'confirmInvoice'])->whereNumber('id');
    Route::post('invoices/allocations/{id}/reverse', [FinanceController::class, 'reverseInvoiceMatch'])->whereNumber('id');
    Route::post('invoices/{id}/attachments', [FinanceController::class, 'uploadInvoiceAttachment'])->whereNumber('id');
    Route::delete('invoices/attachments/{id}', [FinanceController::class, 'deleteInvoiceAttachment'])->whereNumber('id');
    Route::get('counterparties/{partyType}/{partyId}/balance', [FinanceController::class, 'counterpartyBalance'])->whereNumber('partyId');
    Route::post('cash-documents/{id}/attachments', [FinanceController::class, 'uploadAttachment'])->whereNumber('id');
    Route::get('attachments/{id}/preview', [FinanceController::class, 'previewAttachment'])->whereNumber('id');
    Route::delete('attachments/{id}', [FinanceController::class, 'deleteAttachment'])->whereNumber('id');
});

Route::prefix('v1/erp/inventory')->group(function () {
    Route::get('alerts', [InventoryAlertController::class, 'index']);
    Route::get('alerts/unread', [InventoryAlertController::class, 'unread']);
    Route::get('alerts/{id}', [InventoryAlertController::class, 'show'])->whereNumber('id');
    Route::post('alerts/{id}/read', [InventoryAlertController::class, 'markRead'])->whereNumber('id');
    Route::post('alerts/{id}/purchase-request', [InventoryAlertController::class, 'createPurchaseRequest'])->whereNumber('id');
    Route::get('alerts/policies/{itemId}', [InventoryAlertController::class, 'policy'])->whereNumber('itemId');
    Route::put('alerts/policies/{itemId}', [InventoryAlertController::class, 'savePolicy'])->whereNumber('itemId');
    Route::post('alerts/policies/{itemId}/activate', [InventoryAlertController::class, 'activatePolicy'])->whereNumber('itemId');
    Route::post('alerts/policies/{itemId}/disable', [InventoryAlertController::class, 'disablePolicy'])->whereNumber('itemId');
    Route::get('posting/pending-receipts', [InventoryPostingController::class, 'pendingReceipts']);
    Route::get('posting/receipts/{id}', [InventoryPostingController::class, 'showReceipt'])->whereNumber('id');
    Route::put('posting/receipts/{id}/allocations', [InventoryPostingController::class, 'repairReceiptAllocations'])->whereNumber('id');
    Route::post('posting/receipts/{id}/post', [InventoryPostingController::class, 'postReceipt'])->whereNumber('id');

    Route::get('balances', [InventoryBalanceController::class, 'index']);
    Route::get('balances/items/{itemId}/batches', [InventoryBalanceController::class, 'itemBatches'])->whereNumber('itemId');
    Route::get('balances/items/{itemId}/batch-context', [InventoryBalanceController::class, 'batchContext'])->whereNumber('itemId');
    Route::get('balances/{id}/serials', [InventoryBalanceController::class, 'serials'])->whereNumber('id');
    Route::get('balances/{id}', [InventoryBalanceController::class, 'show'])->whereNumber('id');

    Route::get('quality-events', [InventoryQualityController::class, 'index']);
    Route::get('quality-events/context/{balanceId}', [InventoryQualityController::class, 'context'])->whereNumber('balanceId');
    Route::post('quality-events', [InventoryQualityController::class, 'store']);
    Route::post('quality-events/{id}/start', [InventoryQualityController::class, 'start'])->whereNumber('id');
    Route::post('quality-events/{id}/complete', [InventoryQualityController::class, 'complete'])->whereNumber('id');
    Route::get('quality-events/{id}', [InventoryQualityController::class, 'show'])->whereNumber('id');

    Route::get('transactions', [InventoryTransactionController::class, 'index']);
    Route::get('transactions/{id}', [InventoryTransactionController::class, 'show'])->whereNumber('id');

      Route::get('adjustments', [InventoryAdjustmentController::class, 'index']);
      Route::get('adjustments/reasons', [InventoryAdjustmentController::class, 'reasons']);
      Route::post('adjustments/serials/generate', [InventoryAdjustmentController::class, 'generateSerials']);
      Route::post('adjustments', [InventoryAdjustmentController::class, 'store']);
    Route::get('adjustments/{id}', [InventoryAdjustmentController::class, 'show'])->whereNumber('id');
    Route::put('adjustments/{id}', [InventoryAdjustmentController::class, 'update'])->whereNumber('id');
    Route::post('adjustments/{id}/submit', [InventoryAdjustmentController::class, 'submit'])->whereNumber('id');
    Route::post('adjustments/{id}/post', [InventoryAdjustmentController::class, 'post'])->whereNumber('id');
    Route::post('adjustments/{id}/cancel', [InventoryAdjustmentController::class, 'cancel'])->whereNumber('id');
    Route::delete('adjustments/{id}', [InventoryAdjustmentController::class, 'destroy'])->whereNumber('id');
});

Route::prefix('v1/erp/bom')->group(function () {
    Route::get('boms', [BomController::class, 'index']);
    Route::post('boms', [BomController::class, 'store']);
    Route::post('expand', [BomController::class, 'expand']);
    Route::get('boms/{id}', [BomController::class, 'show'])->whereNumber('id');
    Route::put('boms/{id}', [BomController::class, 'update'])->whereNumber('id');
    Route::post('boms/{id}/submit', [BomController::class, 'submit'])->whereNumber('id');
    Route::post('boms/{id}/approve', [BomController::class, 'approve'])->whereNumber('id');
    Route::post('boms/{id}/reject', [BomController::class, 'reject'])->whereNumber('id');
    Route::post('boms/{id}/activate', [BomController::class, 'activate'])->whereNumber('id');
    Route::post('boms/{id}/deactivate', [BomController::class, 'deactivate'])->whereNumber('id');
    Route::post('boms/{id}/set-default', [BomController::class, 'setDefault'])->whereNumber('id');
    Route::post('boms/{id}/copy-version', [BomController::class, 'copyVersion'])->whereNumber('id');
});

Route::prefix('v1/erp/sales')->group(function () {
    Route::get('customers', [SalesCustomerController::class, 'index']);
    Route::post('customers', [SalesCustomerController::class, 'store']);
    Route::get('customers/{id}', [SalesCustomerController::class, 'show'])->whereNumber('id');
    Route::put('customers/{id}', [SalesCustomerController::class, 'update'])->whereNumber('id');
    Route::get('orders', [SalesOrderController::class, 'orders']);
    Route::post('orders', [SalesOrderController::class, 'storeDraft']);
    Route::get('orders/skus/search', [SalesOrderController::class, 'searchOrderSkus']);
    Route::get('orders/interface-contract', [SalesOrderController::class, 'interfaceContract']);
    Route::get('orders/options', [SalesOrderController::class, 'options']);
    Route::post('orders/attachments/upload', [SalesOrderController::class, 'uploadDraftAttachment']);
    Route::get('orders/attachments/{id}/preview', [SalesOrderController::class, 'previewAttachment'])->whereNumber('id');
    Route::get('orders/attachments/{id}/download', [SalesOrderController::class, 'downloadAttachment'])->whereNumber('id');
    Route::delete('orders/attachments/{id}', [SalesOrderController::class, 'deleteDraftAttachment'])->whereNumber('id');
    Route::get('orders/{id}', [SalesOrderController::class, 'show'])->whereNumber('id');
    Route::put('orders/{id}', [SalesOrderController::class, 'updateDraft'])->whereNumber('id');
    Route::post('orders/{id}/edit-impact/preview', [SalesOrderController::class, 'previewEditImpact'])->whereNumber('id');
    Route::post('orders/{id}/edit-impact/submit', [SalesOrderController::class, 'submitEditImpact'])->whereNumber('id');
    Route::get('orders/{id}/change-candidates', [SalesOrderController::class, 'candidateHistory'])->whereNumber('id');
    Route::post('orders/{id}/change-candidates/{candidateId}/decision', [SalesOrderController::class, 'decideCandidate'])->whereNumber('id')->whereNumber('candidateId');
    Route::delete('orders/{id}', [SalesOrderController::class, 'destroyDraft'])->whereNumber('id');
    Route::post('orders/{id}/confirm', [SalesOrderController::class, 'submitDraftConfirmation'])->whereNumber('id');
    Route::post('orders/{id}/formal-confirm', [SalesOrderController::class, 'confirm'])->whereNumber('id');
    Route::get('orders/{id}/production-confirmation-preview', [SalesOrderController::class, 'productionConfirmationPreview'])->whereNumber('id');
    Route::post('orders/{id}/production-confirmation', [SalesOrderController::class, 'confirmProduction'])->whereNumber('id');
    Route::post('orders/{id}/cancel', [SalesOrderController::class, 'cancelWithReason'])->whereNumber('id');
    Route::get('shipments', [SalesShipmentController::class, 'index']);
    Route::post('shipments', [SalesShipmentController::class, 'store']);
    Route::get('shipments/{id}', [SalesShipmentController::class, 'show'])->whereNumber('id');
    Route::post('shipments/{id}/confirm', [SalesShipmentController::class, 'confirm'])->whereNumber('id');
    Route::post('shipments/{id}/post-outbound', [SalesShipmentController::class, 'postOutbound'])->whereNumber('id');
    Route::post('shipments/{id}/dispatch', [SalesShipmentController::class, 'dispatch'])->whereNumber('id');
    Route::post('shipments/{id}/cancel', [SalesShipmentController::class, 'cancel'])->whereNumber('id');
    Route::get('orders/{id}/logs', [SalesOrderController::class, 'logs'])->whereNumber('id');
    Route::get('orders/{id}/versions', [SalesOrderController::class, 'versions'])->whereNumber('id');
    Route::get('orders/{id}/changes', [SalesOrderController::class, 'changes'])->whereNumber('id');
    Route::get('orders/{id}/returns', [SalesReturnController::class, 'orderReturns'])->whereNumber('id');

    Route::get('returns/sources', [SalesReturnController::class, 'sources']);
    Route::get('returns', [SalesReturnController::class, 'index']);
    Route::post('returns', [SalesReturnController::class, 'store']);
    Route::get('returns/{id}', [SalesReturnController::class, 'show'])->whereNumber('id');
    Route::post('returns/{id}/confirm', [SalesReturnController::class, 'confirm'])->whereNumber('id');
    Route::post('returns/{id}/receive', [SalesReturnController::class, 'receive'])->whereNumber('id');
    Route::post('returns/{id}/receipts/{receiptId}/post', [SalesReturnController::class, 'postReceipt'])->whereNumber('id')->whereNumber('receiptId');
    Route::post('returns/{id}/cancel', [SalesReturnController::class, 'cancel'])->whereNumber('id');
    Route::post('returns/{id}/close', [SalesReturnController::class, 'close'])->whereNumber('id');
});

Route::prefix('v1/erp/production')->group(function () {
    Route::get('master-options', [ProductionMasterDataController::class, 'options']);
    Route::get('select-options/{type}', [ProductionMasterDataController::class, 'selector'])->whereIn('type', ['items', 'operations', 'products', 'skus', 'routings']);
    Route::get('operations', [ProductionMasterDataController::class, 'operations']);
    Route::post('operations', [ProductionMasterDataController::class, 'storeOperation']);
    Route::get('operations/{id}', [ProductionMasterDataController::class, 'operation'])->whereNumber('id');
    Route::put('operations/{id}', [ProductionMasterDataController::class, 'updateOperation'])->whereNumber('id');
    Route::post('operations/{id}/enable', [ProductionMasterDataController::class, 'enableOperation'])->whereNumber('id');
    Route::post('operations/{id}/disable', [ProductionMasterDataController::class, 'disableOperation'])->whereNumber('id');
    Route::get('routings', [ProductionMasterDataController::class, 'routings']);
    Route::post('routings', [ProductionMasterDataController::class, 'storeRouting']);
    Route::get('routings/{id}', [ProductionMasterDataController::class, 'routing'])->whereNumber('id');
    Route::put('routings/{id}', [ProductionMasterDataController::class, 'updateRouting'])->whereNumber('id');
    Route::post('routings/{id}/activate', [ProductionMasterDataController::class, 'activateRouting'])->whereNumber('id');
    Route::post('routings/{id}/set-default', [ProductionMasterDataController::class, 'setDefaultRouting'])->whereNumber('id');
    Route::post('routings/{id}/copy-version', [ProductionMasterDataController::class, 'copyRouting'])->whereNumber('id');
    Route::post('routings/{id}/retire', [ProductionMasterDataController::class, 'retireRouting'])->whereNumber('id');
    Route::get('demands', [ProductionWorkOrderController::class, 'demands']);
    Route::get('demands/{id}', [ProductionWorkOrderController::class, 'demand'])->whereNumber('id');
    Route::get('work-orders', [ProductionWorkOrderController::class, 'workOrders']);
    Route::post('work-orders', [ProductionWorkOrderController::class, 'store']);
    Route::get('work-orders/{id}', [ProductionWorkOrderController::class, 'showWorkOrder'])->whereNumber('id');
    Route::get('work-orders/{id}/release-gate', [ProductionWorkOrderController::class, 'releaseGate'])->whereNumber('id');
    Route::get('work-orders/{id}/material-requirements', [ProductionWorkOrderController::class, 'materialRequirements'])->whereNumber('id');
    Route::put('work-orders/{id}', [ProductionWorkOrderController::class, 'update'])->whereNumber('id');
    Route::post('work-orders/{id}/submit', [ProductionWorkOrderController::class, 'submit'])->whereNumber('id');
    Route::post('work-orders/{id}/publish', [ProductionWorkOrderController::class, 'publish'])->whereNumber('id');
    Route::post('work-orders/{id}/return-draft', [ProductionWorkOrderController::class, 'returnToDraft'])->whereNumber('id');
    Route::post('work-orders/{id}/cancel', [ProductionWorkOrderController::class, 'cancel'])->whereNumber('id');
});

Route::prefix('v1/erp/user-directory')->group(function () {
    Route::get('users', [UserDirectoryController::class, 'users']);
});

Route::prefix('v1/erp/auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
});

Route::prefix('v1/erp/rbac')->group(function () {
    Route::get('permissions', [RbacController::class, 'permissions']);
    Route::post('permissions', [RbacController::class, 'savePermission']);
    Route::get('roles', [RbacController::class, 'roles']);
    Route::post('roles', [RbacController::class, 'saveRole']);
    Route::get('role-users', [RbacController::class, 'roleUsers']);
    Route::post('role-users', [RbacController::class, 'saveRoleUsers']);
});

Route::prefix('v1/erp/departments')->group(function () {
    Route::get('', [DepartmentController::class, 'index']);
    Route::post('sync', [DepartmentController::class, 'sync']);
    Route::get('{legacyId}/members', [DepartmentController::class, 'members'])->whereNumber('legacyId');
    Route::post('{legacyId}/principals', [DepartmentController::class, 'savePrincipals'])->whereNumber('legacyId');
});

Route::prefix('v1/erp/aftersales')->group(function () {
    Route::get('interface-contract', [AftersalesReservedController::class, 'interfaceContract']);
    Route::get('cases', [AftersalesReservedController::class, 'indexCases']);
    Route::post('cases', [AftersalesReservedController::class, 'storeCase']);
    Route::get('cases/{id}', [AftersalesReservedController::class, 'showCase'])->whereNumber('id');
    Route::post('cases/{id}/accept', [AftersalesReservedController::class, 'acceptCase'])->whereNumber('id');
    Route::post('cases/{id}/resolve', [AftersalesReservedController::class, 'resolveCase'])->whereNumber('id');
    Route::get('returns', [SalesReturnController::class, 'index']);
    Route::post('returns', [SalesReturnController::class, 'store']);
    Route::get('returns/{id}', [SalesReturnController::class, 'show'])->whereNumber('id');
    Route::post('returns/{id}/receive', [SalesReturnController::class, 'receive'])->whereNumber('id');
});

Route::prefix('v1/erp/master')->group(function () {
    Route::get('items/{itemId}/integrated-form', [ItemIntegratedFormController::class, 'show'])->whereNumber('itemId');
    Route::post('items/integrated-form', [ItemIntegratedFormController::class, 'store']);
    Route::put('items/{itemId}/integrated-form', [ItemIntegratedFormController::class, 'update'])->whereNumber('itemId');
    Route::get('items/{itemId}/material-policy', [ItemMaterialPolicyController::class, 'show'])->whereNumber('itemId');
    Route::get('items/{itemId}/material-policy/history', [ItemMaterialPolicyController::class, 'history'])->whereNumber('itemId');
    Route::put('items/{itemId}/material-policy/draft', [ItemMaterialPolicyController::class, 'saveDraft'])->whereNumber('itemId');
    Route::post('items/{itemId}/material-policy/activate', [ItemMaterialPolicyController::class, 'activate'])->whereNumber('itemId');
    Route::get('items/{itemId}/purchase-conversions/options', [ItemPurchaseConversionController::class, 'options'])->whereNumber('itemId');
    Route::get('items/{itemId}/purchase-conversions', [ItemPurchaseConversionController::class, 'index'])->whereNumber('itemId');
    Route::post('items/{itemId}/purchase-conversions', [ItemPurchaseConversionController::class, 'store'])->whereNumber('itemId');
    Route::put('items/{itemId}/purchase-conversions/{id}', [ItemPurchaseConversionController::class, 'update'])->whereNumber('itemId')->whereNumber('id');
    Route::post('items/{itemId}/purchase-conversions/{id}/disable', [ItemPurchaseConversionController::class, 'disable'])->whereNumber('itemId')->whereNumber('id');
    Route::get('item-categories/tree', [ItemCategoryController::class, 'tree']);
    Route::get('item-categories', [ItemCategoryController::class, 'index']);
    Route::post('item-categories', [ItemCategoryController::class, 'store']);
    Route::get('item-categories/{id}', [ItemCategoryController::class, 'show'])->whereNumber('id');
    Route::put('item-categories/{id}', [ItemCategoryController::class, 'update'])->whereNumber('id');
    Route::post('item-categories/{id}/disable', [ItemCategoryController::class, 'disable'])->whereNumber('id');
    Route::post('item-categories/{id}/enable', [ItemCategoryController::class, 'enable'])->whereNumber('id');
    Route::delete('item-categories/{id}', [ItemCategoryController::class, 'destroy'])->whereNumber('id');
    Route::post('products/image-upload', [MasterDataController::class, 'uploadProductImage']);
    Route::post('skus/image-upload', [MasterDataController::class, 'uploadSkuImage']);
    Route::get('suppliers/{supplierId}/capabilities', [SupplierCapabilityController::class, 'summary'])->whereNumber('supplierId');
    Route::put('suppliers/{supplierId}/capabilities/categories', [SupplierCapabilityController::class, 'syncCategories'])->whereNumber('supplierId');
    Route::get('suppliers/{supplierId}/item-relations', [SupplierCapabilityController::class, 'itemRelations'])->whereNumber('supplierId');
    Route::post('suppliers/{supplierId}/item-relations', [SupplierCapabilityController::class, 'storeItemRelation'])->whereNumber('supplierId');
    Route::post('suppliers/{supplierId}/item-relations/{relationId}/disable', [SupplierCapabilityController::class, 'disableItemRelation'])->whereNumber('supplierId')->whereNumber('relationId');
    Route::get('suppliers/{supplierId}/quotations', [SupplierCapabilityController::class, 'quotations'])->whereNumber('supplierId');
    Route::post('suppliers/{supplierId}/quotations', [SupplierCapabilityController::class, 'storeQuotation'])->whereNumber('supplierId');
    Route::post('suppliers/{supplierId}/quotations/{quotationId}/disable', [SupplierCapabilityController::class, 'disableQuotation'])->whereNumber('supplierId')->whereNumber('quotationId');
    Route::get('suppliers/{supplierId}/quotation-history', [SupplierCapabilityController::class, 'quotationHistory'])->whereNumber('supplierId');
    Route::get('suppliers/{supplierId}/purchase-history', [SupplierCapabilityController::class, 'purchaseHistory'])->whereNumber('supplierId');
    Route::get('suppliers/{supplierId}/relation-history', [SupplierCapabilityController::class, 'relationHistory'])->whereNumber('supplierId');
    foreach (['products', 'skus', 'items', 'units', 'categories', 'suppliers', 'warehouses', 'locations'] as $entity) {
        Route::get($entity, [MasterDataController::class, 'index'])->defaults('entity', $entity);
        Route::post($entity, [MasterDataController::class, 'store'])->defaults('entity', $entity);
        Route::get("$entity/{id}", [MasterDataController::class, 'show'])->defaults('entity', $entity)->whereNumber('id');
        Route::put("$entity/{id}", [MasterDataController::class, 'update'])->defaults('entity', $entity)->whereNumber('id');
        Route::post("$entity/{id}/disable", [MasterDataController::class, 'disable'])->defaults('entity', $entity)->whereNumber('id');
        Route::post("$entity/{id}/enable", [MasterDataController::class, 'enable'])->defaults('entity', $entity)->whereNumber('id');
        Route::delete("$entity/{id}", [MasterDataController::class, 'destroy'])->defaults('entity', $entity)->whereNumber('id');
    }
    // Stage 4: direct SKU -> one active default Item. All collection endpoints paginate.
    Route::get('sku-item-relations/defaults', [SkuItemRelationController::class, 'defaultRelationIndex']);
    Route::post('sku-item-relations/audit', [SkuItemRelationController::class, 'audit']);
    Route::get('sku-item-relations/{skuId}/history', [SkuItemRelationController::class, 'relationHistory'])->whereNumber('skuId');
    Route::get('sku-item-relations/{skuId}', [SkuItemRelationController::class, 'showDefaultRelation'])->whereNumber('skuId');
    Route::post('sku-item-relations/{skuId}/set-primary', [SkuItemRelationController::class, 'setDefaultItem'])->whereNumber('skuId');
    Route::post('sku-item-relations/{skuId}/resolve-duplicate', [SkuItemRelationController::class, 'resolveDuplicate'])->whereNumber('skuId');
    Route::post('sku-item-relations/{skuId}/remove-wrong-binding', [SkuItemRelationController::class, 'removeWrongBinding'])->whereNumber('skuId');
    Route::get('sku-item-relations', [SkuItemRelationController::class, 'index']);
    Route::post('sku-item-relations', [SkuItemRelationController::class, 'legacySetDefaultItem']);
    Route::post('sku-item-relations/replace-primary', [SkuItemRelationController::class, 'legacySetDefaultItem']);
    Route::post('imports/upload', [ImportController::class, 'upload']);
    Route::post('imports/{id}/preview', [ImportController::class, 'preview']);
    Route::post('imports/{id}/confirm', [ImportController::class, 'confirm']);
    Route::get('imports/{id}/rows', [ImportController::class, 'rows']);
    Route::get('imports/{id}/errors/export', [ImportController::class, 'exportErrors']);
});
