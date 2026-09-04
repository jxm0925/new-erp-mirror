<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\PurchaseReturnItem;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\PurchaseReturnApplicationService;
use Illuminate\Http\Request;

class PurchaseReturnController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePermission($request, 'purchase_return.view');
        $query = PurchaseReturn::query()
            ->with(['supplier', 'receipt.order', 'items.item', 'items.baseUnit', 'items.returnUnit'])
            ->latest('updated_at');

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('return_no', 'like', "%{$keyword}%")
                    ->orWhereHas('receipt', fn ($receipt) => $receipt->where('receipt_no', 'like', "%{$keyword}%"))
                    ->orWhereHas('supplier', fn ($supplier) => $supplier
                        ->where('supplier_code', 'like', "%{$keyword}%")
                        ->orWhere('supplier_name', 'like', "%{$keyword}%"));
            });
        }
        if ($request->filled('return_no')) {
            $query->where('return_no', 'like', '%'.trim((string) $request->input('return_no')).'%');
        }
        if ($request->filled('receipt_no')) {
            $receiptNo = trim((string) $request->input('receipt_no'));
            $query->whereHas('receipt', fn ($receipt) => $receipt->where('receipt_no', 'like', "%{$receiptNo}%"));
        }
        if ($request->filled('purchase_keyword')) {
            $purchaseKeyword = trim((string) $request->input('purchase_keyword'));
            $query->where(function ($builder) use ($purchaseKeyword): void {
                $builder->whereHas('receipt.order', fn ($order) => $order
                    ->where('purchase_order_no', 'like', "%{$purchaseKeyword}%"))
                    ->orWhereHas('items.item', fn ($item) => $item
                        ->where('item_code', 'like', "%{$purchaseKeyword}%")
                        ->orWhere('item_name', 'like', "%{$purchaseKeyword}%"));
            });
        }
        if ($request->filled('return_status')) $query->where('return_status', $request->input('return_status'));
        if ($request->filled('return_scope')) $query->where('return_scope', $request->input('return_scope'));
        if ($request->filled('supplier_id')) $query->where('supplier_id', $request->integer('supplier_id'));
        if ($request->filled('date_from')) $query->whereDate('return_date', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $query->whereDate('return_date', '<=', $request->input('date_to'));

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function sources(Request $request, InventoryAvailabilityService $availability)
    {
        $this->authorizePermission($request, 'purchase_return.view');
        $query = InventoryTransactionItem::query()
            ->with([
                'item.unit',
                'warehouse',
                'location',
                'purchaseReceiptItem.purchaseUnit',
                'purchaseReceiptItem.baseUnit',
                'purchaseReceiptItem.receipt.supplier',
                'purchaseReceiptItem.receipt.order',
            ])
            ->where('source_type', 'purchase_receipt')
            ->where('change_qty', '>', 0)
            ->latest('id');

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('item_code', 'like', "%{$keyword}%")
                    ->orWhere('item_name', 'like', "%{$keyword}%")
                    ->orWhere('batch_no', 'like', "%{$keyword}%")
                    ->orWhereHas('purchaseReceiptItem.receipt', fn ($receipt) => $receipt->where('receipt_no', 'like', "%{$keyword}%"));
            });
        }
        if ($request->filled('supplier_id')) {
            $query->whereHas('purchaseReceiptItem.receipt', fn ($receipt) => $receipt->where('supplier_id', $request->integer('supplier_id')));
        }
        if ($request->filled('warehouse_id')) $query->where('warehouse_id', $request->integer('warehouse_id'));

        $paginator = $query->paginate($this->perPage($request));
        $paginator->setCollection($paginator->getCollection()->map(function (InventoryTransactionItem $row) use ($availability): array {
            $reserved = (float) PurchaseReturnItem::query()
                ->join('erp_purchase_returns', 'erp_purchase_returns.id', '=', 'erp_purchase_return_items.return_id')
                ->where('erp_purchase_return_items.source_receipt_item_id', $row->source_item_id)
                ->where('erp_purchase_return_items.warehouse_id', $row->warehouse_id)
                ->where('erp_purchase_return_items.location_id', $row->location_id)
                ->where('erp_purchase_return_items.batch_no', $row->batch_no)
                ->whereNotIn('erp_purchase_returns.return_status', ['cancelled', 'closed'])
                ->sum('erp_purchase_return_items.requested_base_qty');
            $receiptItem = $row->purchaseReceiptItem;
            $balance = InventoryBalance::query()
                ->where('item_id', $row->item_id)
                ->where('warehouse_id', $row->warehouse_id)
                ->where('location_id', $row->location_id)
                ->where('batch_no', $row->batch_no)
                ->first();
            $currentAvailable = $balance ? $availability->availableForOutbound($balance) : 0.0;
            $contractAvailable = max(0, (float) $row->change_qty - $reserved);
            $serialTrackingMode = $receiptItem?->item?->serialTrackingMode() ?? 'none';
            $availableSerialCount = InventorySerial::query()
                ->where('source_receipt_item_id', $row->source_item_id)
                ->where('item_id', $row->item_id)
                ->where('warehouse_id', $row->warehouse_id)
                ->where('location_id', $row->location_id)
                ->where('batch_no', $row->batch_no)
                ->where('serial_status', 'available')
                ->whereDoesntHave('purchaseReturnLinks', fn ($links) => $links
                    ->whereHas('returnItem.purchaseReturn', fn ($returns) => $returns
                        ->whereNotIn('return_status', ['cancelled', 'closed'])))
                ->count();
            $availableReturnBaseQty = min($contractAvailable, max(0, $currentAvailable));
            if ($serialTrackingMode === 'required') {
                $availableReturnBaseQty = min($availableReturnBaseQty, $availableSerialCount);
            }
            $baseUnitId = (int) ($receiptItem?->base_unit_id ?: $row->unit_id ?: $receiptItem?->item?->unit_id);
            $baseUnitName = $receiptItem?->base_unit_name_snapshot
                ?: $receiptItem?->baseUnit?->unit_name
                ?: $row->unit?->unit_name
                ?: $receiptItem?->item?->unit?->unit_name;
            $purchaseUnitId = (int) ($receiptItem?->purchase_unit_id ?: $baseUnitId);
            $purchaseUnitName = $receiptItem?->purchase_unit_name_snapshot
                ?: $receiptItem?->purchaseUnit?->unit_name
                ?: $baseUnitName;
            $purchaseFactor = max(0.00000001, (float) ($receiptItem?->conversion_factor_snapshot ?: 1));
            $returnUnits = [[
                'unit_id' => $baseUnitId,
                'unit_name' => $baseUnitName,
                'conversion_factor' => 1,
                'decimal_places' => (int) ($receiptItem?->baseUnit?->decimal_places ?? $receiptItem?->item?->unit?->decimal_places ?? 4),
                'unit_type' => 'base',
            ]];
            if ($purchaseUnitId !== $baseUnitId) {
                array_unshift($returnUnits, [
                    'unit_id' => $purchaseUnitId,
                    'unit_name' => $purchaseUnitName,
                    'conversion_factor' => $purchaseFactor,
                    'decimal_places' => (int) ($receiptItem?->purchaseUnit?->decimal_places ?? 4),
                    'unit_type' => 'purchase_snapshot',
                ]);
            }

            return [
                'inventory_transaction_item_id' => $row->id,
                'source_receipt_id' => $receiptItem?->receipt_id,
                'source_receipt_item_id' => $row->source_item_id,
                'receipt_no' => $receiptItem?->receipt?->receipt_no,
                'purchase_order_no' => $receiptItem?->receipt?->order?->purchase_order_no,
                'supplier_id' => $receiptItem?->receipt?->supplier_id,
                'supplier_name' => $receiptItem?->receipt?->supplier?->supplier_name,
                'item_id' => $row->item_id,
                'item_code' => $row->item_code,
                'item_name' => $row->item_name,
                'warehouse_id' => $row->warehouse_id,
                'warehouse_name' => $row->warehouse?->warehouse_name,
                'location_id' => $row->location_id,
                'location_name' => $row->location?->location_name,
                'batch_no' => $row->batch_no,
                'base_unit_id' => $baseUnitId,
                'base_unit_name' => $baseUnitName,
                'purchase_unit_id' => $purchaseUnitId,
                'purchase_unit_name' => $purchaseUnitName,
                'purchase_conversion_factor' => $purchaseFactor,
                'return_units' => $returnUnits,
                'serial_tracking_mode' => $serialTrackingMode,
                'available_serial_count' => $availableSerialCount,
                'posted_base_qty' => (float) $row->change_qty,
                'reserved_return_base_qty' => $reserved,
                'current_available_base_qty' => max(0, $currentAvailable),
                'current_locked_base_qty' => (float) ($balance?->quantity_locked ?? 0),
                'current_quality_hold_base_qty' => (float) (($balance?->quantity_defective ?? 0) + ($balance?->quantity_pending ?? 0)),
                'contract_available_return_base_qty' => $contractAvailable,
                'available_return_base_qty' => $availableReturnBaseQty,
            ];
        }));

        return response()->json($paginator);
    }

    public function sourceSerials(Request $request, int $transactionItemId)
    {
        $this->authorizePermission($request, 'purchase_return.view');
        $source = InventoryTransactionItem::query()
            ->with('purchaseReceiptItem.item')
            ->whereKey($transactionItemId)
            ->where('source_type', 'purchase_receipt')
            ->where('change_qty', '>', 0)
            ->firstOrFail();

        $query = InventorySerial::query()
            ->where('source_receipt_item_id', $source->source_item_id)
            ->where('item_id', $source->item_id)
            ->where('warehouse_id', $source->warehouse_id)
            ->where('location_id', $source->location_id)
            ->where('batch_no', $source->batch_no)
            ->where('serial_status', 'available')
            ->whereDoesntHave('purchaseReturnLinks', fn ($links) => $links
                ->whereHas('returnItem.purchaseReturn', fn ($returns) => $returns
                    ->whereNotIn('return_status', ['cancelled', 'closed'])))
            ->orderBy('serial_no');

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('serial_no', 'like', "%{$keyword}%")
                    ->orWhere('manufacturer_serial_no', 'like', "%{$keyword}%");
            });
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function show(Request $request, int $id)
    {
        $this->authorizePermission($request, 'purchase_return.view');
        $purchaseReturn = PurchaseReturn::with([
            'supplier',
            'receipt.order',
            'items.sourceReceiptItem',
            'items.sourceDefectHandling',
            'items.item',
            'items.warehouse',
            'items.location',
            'items.baseUnit',
            'items.returnUnit',
            'items.serialLinks.inventorySerial',
            'logs',
        ])->findOrFail($id);

        $purchaseReturn->items->each(function (PurchaseReturnItem $item) use ($purchaseReturn): void {
            if (!$item->warehouse_id || !$item->location_id || !$item->batch_no) {
                $item->setAttribute('source_posted_base_qty', (float) $item->requested_base_qty);
                $item->setAttribute('previous_returned_base_qty', 0.0);
                return;
            }
            $posted = InventoryTransactionItem::query()
                ->where('source_type', 'purchase_receipt')
                ->where('source_id', $item->sourceReceiptItem?->receipt_id)
                ->where('source_item_id', $item->source_receipt_item_id)
                ->where('warehouse_id', $item->warehouse_id)
                ->where('location_id', $item->location_id)
                ->where('batch_no', $item->batch_no)
                ->where('change_qty', '>', 0)
                ->sum('change_qty');
            $previousReturned = PurchaseReturnItem::query()
                ->join('erp_purchase_returns', 'erp_purchase_returns.id', '=', 'erp_purchase_return_items.return_id')
                ->where('erp_purchase_return_items.return_id', '<>', $purchaseReturn->id)
                ->where('erp_purchase_return_items.source_receipt_item_id', $item->source_receipt_item_id)
                ->where('erp_purchase_return_items.warehouse_id', $item->warehouse_id)
                ->where('erp_purchase_return_items.location_id', $item->location_id)
                ->where('erp_purchase_return_items.batch_no', $item->batch_no)
                ->where('erp_purchase_returns.return_status', 'completed')
                ->where('erp_purchase_returns.stock_post_status', 'posted')
                ->sum('erp_purchase_return_items.posted_base_qty');
            $item->setAttribute('source_posted_base_qty', (float) $posted);
            $item->setAttribute('previous_returned_base_qty', (float) $previousReturned);
        });
        $purchaseReturn->setAttribute(
            'return_inventory_transaction_no',
            InventoryTransaction::query()
                ->where('source_type', 'purchase_return')
                ->where('source_id', $purchaseReturn->id)
                ->value('transaction_no')
        );

        return response()->json($purchaseReturn);
    }

    public function store(Request $request, PurchaseReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'purchase_return.create');
        $payload = $request->validate([
            'reservation_token' => 'nullable|uuid',
            'creation_session_id' => 'nullable|uuid',
            'return_scope' => 'required|in:posted_inventory',
            'source_receipt_id' => 'required|exists:erp_purchase_receipts,id',
            'supplier_id' => 'required|exists:erp_suppliers,id',
            'return_date' => 'nullable|date',
            'return_reason' => 'required|string|max:160',
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.source_receipt_item_id' => 'required|exists:erp_purchase_receipt_items,id',
            'items.*.warehouse_id' => 'required|exists:erp_warehouses,id',
            'items.*.location_id' => 'required|exists:erp_locations,id',
            'items.*.batch_no' => 'required|string|max:80',
            'items.*.requested_return_qty' => 'required|numeric|min:0.00000001',
            'items.*.return_unit_id' => 'required|exists:erp_units,id',
            'items.*.serial_ids' => 'nullable|array|max:500',
            'items.*.serial_ids.*' => 'integer|distinct|exists:erp_inventory_serials,id',
            'items.*.remark' => 'nullable|string',
        ]);
        [$operatorId, $operatorName] = $this->operator($request);

        return response()->json([
            'message' => '采购退货单已保存',
            'data' => $service->create($payload, $operatorId, $operatorName),
        ], 201);
    }

    public function submit(Request $request, int $id, PurchaseReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'purchase_return.submit');
        return $this->action($request, $service->submit($id, ...$this->operator($request)), '采购退货单已提交审批');
    }

    public function approve(Request $request, int $id, PurchaseReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'purchase_return.approve');
        $purchaseReturn = $service->approve($id, ...$this->operator($request));
        $message = $purchaseReturn->return_scope === 'rejected_before_posting'
            ? '采购退货单已审核，等待退回供应商'
            : '采购退货单已审核，等待仓库出库';

        return $this->action($request, $purchaseReturn, $message);
    }

    public function post(Request $request, int $id, PurchaseReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'purchase_return.post');
        return $this->action($request, $service->post($id, ...$this->operator($request)), '采购退货出库完成');
    }

    public function cancel(Request $request, int $id, PurchaseReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'purchase_return.cancel');
        return $this->action($request, $service->cancel($id, ...$this->operator($request)), '采购退货单已取消');
    }

    public function close(Request $request, int $id, PurchaseReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'purchase_return.close');
        return $this->action($request, $service->close($id, ...$this->operator($request)), '采购退货单已关闭');
    }

    private function action(Request $request, PurchaseReturn $data, string $message)
    {
        return response()->json(compact('message', 'data'));
    }

    private function operator(Request $request): array
    {
        $user = app(AuthContextService::class)->currentUser($request);
        return [$user?->legacy_id, $user?->nickname ?: $user?->username];
    }

    private function authorizePermission(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless(
            $auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true),
            403,
            '无按钮权限：'.$permission
        );

        return $user;
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, (int) $request->input('per_page', 20)));
    }
}
