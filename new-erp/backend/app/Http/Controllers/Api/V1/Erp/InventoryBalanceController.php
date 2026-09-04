<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryQualityEvent;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use Illuminate\Http\Request;

class InventoryBalanceController extends Controller
{
    public function index(Request $request)
    {
        if ($request->input('view') === 'item') {
            return $this->itemSummary($request);
        }

        $query = InventoryBalance::with(['item.unit', 'item.skuRelations.sku.product', 'warehouse', 'location'])
            ->where(function ($q) {
                $q->where('quantity_on_hand', '<>', 0)
                    ->orWhere('quantity_locked', '<>', 0)
                    ->orWhere('quantity_defective', '<>', 0)
                    ->orWhere('quantity_pending', '<>', 0);
            })
            ->latest('last_transaction_at');

        if ($request->filled('keyword')) {
            $keyword = $request->input('keyword');
            $query->whereHas('item', fn ($q) => $q->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%"));
        }
        if ($request->filled('item_code')) $query->whereHas('item', fn ($q) => $q->where('item_code', 'like', '%' . $request->input('item_code') . '%'));
        if ($request->filled('item_name')) $query->whereHas('item', fn ($q) => $q->where('item_name', 'like', '%' . $request->input('item_name') . '%'));
        if ($request->filled('warehouse_id')) $query->where('warehouse_id', $request->input('warehouse_id'));
        if ($request->filled('location_id')) $query->where('location_id', $request->input('location_id'));
        if ($request->filled('batch_no')) $query->where('batch_no', 'like', '%' . $request->input('batch_no') . '%');
        if ($request->filled('inventory_status')) {
            if ($request->input('inventory_status') === 'has_stock') $query->where('quantity_on_hand', '>', 0);
            if ($request->input('inventory_status') === 'has_quality') $query->where(fn ($q) => $q->where('quantity_defective', '>', 0)->orWhere('quantity_pending', '>', 0));
        }

        $statsQuery = clone $query;
        $stats = [
            'item_count' => (clone $statsQuery)->distinct()->count('item_id'),
            'stock_item_count' => (clone $statsQuery)->where('quantity_on_hand', '>', 0)->distinct()->count('item_id'),
            'balance_line_count' => (clone $statsQuery)->count(),
            'quality_line_count' => (clone $statsQuery)->where(fn ($q) => $q->where('quantity_defective', '>', 0)->orWhere('quantity_pending', '>', 0))->count(),
            'inventory_value' => (float) (clone $statsQuery)->sum('inventory_value'),
        ];
        $paginator = $query->paginate($this->perPage($request));
        return response()->json([...$paginator->toArray(), 'stats' => $stats]);
    }

    public function show(int $id)
    {
        return response()->json(InventoryBalance::with(['item.unit', 'item.skuRelations.sku.product', 'warehouse', 'location'])->findOrFail($id));
    }

    public function serials(Request $request, int $id)
    {
        InventoryBalance::query()->findOrFail($id);
        $query = InventorySerial::query()
            ->where('inventory_balance_id', $id)
            ->orderBy('serial_no');
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where('serial_no', 'like', "%{$keyword}%");
        }
        if ($request->filled('serial_status')) {
            $query->where('serial_status', $request->input('serial_status'));
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function itemBatches(Request $request, int $itemId)
    {
        $item = Item::query()->with(['unit'])->findOrFail($itemId);
        $query = $this->activeBalanceQuery($request)->where('item_id', $itemId)
            ->selectRaw('item_id, batch_no, SUM(quantity_on_hand) as quantity_on_hand, SUM(quantity_available) as quantity_available, SUM(quantity_locked) as quantity_locked, SUM(quantity_defective) as quantity_defective, SUM(quantity_pending) as quantity_pending, SUM(inventory_value) as inventory_value, MAX(last_transaction_at) as last_transaction_at, COUNT(DISTINCT warehouse_id) as warehouse_count, COUNT(DISTINCT location_id) as location_count, COUNT(*) as balance_count')
            ->groupBy('item_id', 'batch_no')
            ->orderByDesc('last_transaction_at');

        $paginator = $query->paginate($this->perPage($request));
        $paginator->getCollection()->transform(function ($row) use ($itemId) {
            $row->item_id = $itemId;
            $row->quality_event_count = InventoryQualityEvent::query()
                ->where('item_id', $itemId)->where('batch_no', $row->batch_no)
                ->whereNotIn('event_status', ['completed', 'cancelled'])->count();
            $row->serial_count = InventoryBalance::query()->where('item_id', $itemId)->where('batch_no', $row->batch_no)
                ->withCount('serials')->get()->sum('serials_count');
            return $row;
        });

        return response()->json([...$paginator->toArray(), 'item' => $item]);
    }

    public function batchContext(Request $request, int $itemId)
    {
        $batchNo = trim((string) $request->input('batch_no'));
        abort_if($batchNo === '', 422, '批次号不能为空。');
        $item = Item::query()->with(['unit', 'skuRelations.sku.product'])->findOrFail($itemId);
        $balances = InventoryBalance::query()
            ->with(['warehouse', 'location'])
            ->withCount([
                'serials',
                'serials as available_serials_count' => fn ($query) => $query->where('serial_status', 'available'),
            ])
            ->where('item_id', $itemId)->where('batch_no', $batchNo)
            ->where(fn ($q) => $q->where('quantity_on_hand', '<>', 0)->orWhere('quantity_locked', '<>', 0)->orWhere('quantity_defective', '<>', 0)->orWhere('quantity_pending', '<>', 0))
            ->orderBy('warehouse_id')->orderBy('location_id')->get();
        abort_if($balances->isEmpty(), 404, '该 Item 下没有此批次库存。');

        $source = InventoryTransactionItem::query()
            ->with(['purchaseReceiptItem.receipt.order', 'purchaseReceiptItem.receipt.supplier'])
            ->where('source_type', 'purchase_receipt')->where('item_id', $itemId)
            ->where('batch_no', $batchNo)->where('change_qty', '>', 0)->latest('id')->first();
        $receipt = $source?->purchaseReceiptItem?->receipt;

        return response()->json([
            'item' => $item,
            'batch_no' => $batchNo,
            'balances' => $balances,
            'source' => [
                'receipt_id' => $receipt?->id,
                'receipt_no' => $receipt?->receipt_no,
                'purchase_order_id' => $receipt?->order_id,
                'purchase_order_no' => $receipt?->order?->purchase_order_no,
                'supplier_id' => $receipt?->supplier_id,
                'supplier_name' => $receipt?->supplier?->supplier_name,
                'receipt_date' => $receipt?->receipt_date?->format('Y-m-d'),
            ],
            'quality_events' => InventoryQualityEvent::query()
                ->with(['warehouse', 'location', 'serial', 'logs'])
                ->where('item_id', $itemId)->where('batch_no', $batchNo)
                ->latest('created_at')->get(),
        ]);
    }

    private function itemSummary(Request $request)
    {
        $base = $this->activeBalanceQuery($request);
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $base->whereHas('item', fn ($q) => $q->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%"));
        }
        $statsQuery = clone $base;
        $stats = [
            'item_count' => (clone $statsQuery)->distinct()->count('item_id'),
            'stock_item_count' => (clone $statsQuery)->where('quantity_on_hand', '>', 0)->distinct()->count('item_id'),
            'batch_count' => (clone $statsQuery)->selectRaw("COUNT(DISTINCT CONCAT(item_id, '|', COALESCE(batch_no, ''))) as aggregate")->value('aggregate'),
            'quality_item_count' => (clone $statsQuery)->where(fn ($q) => $q->where('quantity_defective', '>', 0)->orWhere('quantity_pending', '>', 0))->distinct()->count('item_id'),
            'inventory_value' => (float) (clone $statsQuery)->sum('inventory_value'),
        ];
        $query = $base->selectRaw('item_id, SUM(quantity_on_hand) as quantity_on_hand, SUM(quantity_available) as quantity_available, SUM(quantity_locked) as quantity_locked, SUM(quantity_defective) as quantity_defective, SUM(quantity_pending) as quantity_pending, SUM(inventory_value) as inventory_value, COUNT(DISTINCT batch_no) as batch_count, COUNT(*) as balance_count, MAX(last_transaction_at) as last_transaction_at')
            ->groupBy('item_id')->orderByDesc('last_transaction_at');
        $paginator = $query->paginate($this->perPage($request));
        $items = Item::query()->with(['unit', 'skuRelations.sku.product'])->whereIn('id', $paginator->getCollection()->pluck('item_id'))->get()->keyBy('id');
        $paginator->getCollection()->transform(function ($row) use ($items) {
            $row->item = $items->get($row->item_id);
            return $row;
        });
        return response()->json([...$paginator->toArray(), 'stats' => $stats]);
    }

    private function activeBalanceQuery(Request $request)
    {
        $query = InventoryBalance::query()->where(fn ($q) => $q->where('quantity_on_hand', '<>', 0)
            ->orWhere('quantity_locked', '<>', 0)->orWhere('quantity_defective', '<>', 0)->orWhere('quantity_pending', '<>', 0));
        if ($request->filled('warehouse_id')) $query->where('warehouse_id', $request->input('warehouse_id'));
        if ($request->filled('location_id')) $query->where('location_id', $request->input('location_id'));
        if ($request->filled('batch_no')) $query->where('batch_no', 'like', '%' . $request->input('batch_no') . '%');
        if ($request->filled('inventory_status')) {
            if ($request->input('inventory_status') === 'has_stock') $query->where('quantity_on_hand', '>', 0);
            if ($request->input('inventory_status') === 'has_quality') $query->where(fn ($q) => $q->where('quantity_defective', '>', 0)->orWhere('quantity_pending', '>', 0));
        }
        return $query;
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, (int) $request->input('per_page', 20)));
    }
}
