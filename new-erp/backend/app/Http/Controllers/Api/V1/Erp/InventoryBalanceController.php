<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesErpPermissions;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryQualityEvent;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use Illuminate\Http\Request;
use App\Services\Erp\ItemManagementScopeService;
use App\Services\Erp\Inventory\StockMeasureService;
use App\Services\Erp\Inventory\InventoryTransferService;
use App\Services\Erp\PhysicalInventoryApplicationService;
use Illuminate\Support\Facades\DB;

class InventoryBalanceController extends Controller
{
    use AuthorizesErpPermissions;

    public function index(Request $request)
    {
        $this->erpAuthorize($request, 'inventory.balance.view');
        $request->validate(['item_id' => 'nullable|integer|min:1', 'exact_batch' => 'nullable|boolean']);
        if ($request->input('inventory_status') === 'in_transit') return $this->transit($request);
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
        $this->applyManagementScope($query, $request);

        if ($request->filled('keyword')) {
            $keyword = $request->input('keyword');
            $query->whereHas('item', fn ($q) => $q->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%")
                ->orWhere('spec', 'like', "%{$keyword}%")->orWhere('model', 'like', "%{$keyword}%"));
        }
        if ($request->filled('item_id')) $query->where('item_id', $request->integer('item_id'));
        if ($request->filled('item_code')) $query->whereHas('item', fn ($q) => $q->where('item_code', 'like', '%' . $request->input('item_code') . '%'));
        if ($request->filled('item_name')) $query->whereHas('item', fn ($q) => $q->where('item_name', 'like', '%' . $request->input('item_name') . '%'));
        if ($request->filled('warehouse_id')) $query->where('warehouse_id', $request->input('warehouse_id'));
        if ($request->filled('location_id')) $query->where('location_id', $request->input('location_id'));
        if ($request->filled('batch_no')) $query->where('batch_no', $request->boolean('exact_batch') ? '=' : 'like',
            $request->boolean('exact_batch') ? $request->input('batch_no') : '%' . $request->input('batch_no') . '%');
        if ($request->filled('inventory_status')) {
            if ($request->input('inventory_status') === 'has_stock') $query->where('quantity_on_hand', '>', 0);
            if ($request->input('inventory_status') === 'has_quality') $query->where(fn ($q) => $q->where('quantity_defective', '>', 0)->orWhere('quantity_pending', '>', 0));
        }

        $statsQuery = clone $query;
        $warehouseCost = (string) (clone $statsQuery)->sum('inventory_value');
        $stats = [
            'item_count' => (clone $statsQuery)->distinct()->count('item_id'),
            'stock_item_count' => (clone $statsQuery)->where('quantity_on_hand', '>', 0)->distinct()->count('item_id'),
            'balance_line_count' => (clone $statsQuery)->count(),
            'quality_line_count' => (clone $statsQuery)->where(fn ($q) => $q->where('quantity_defective', '>', 0)->orWhere('quantity_pending', '>', 0))->count(),
            'inventory_value' => (float) $warehouseCost,
        ];
        $stats = $this->withTransitStats($request, $stats, $warehouseCost);
        if ($request->boolean('include_quantity_summary')) {
            // 不同库存单位不可直接相加；全量汇总后按余额快照单位返回，保留小数精度。
            $quantities = (clone $statsQuery)->toBase()->reorder()->select('unit_id')
                ->selectRaw('SUM(quantity_on_hand) AS quantity')->groupBy('unit_id')->orderBy('unit_id')->get();
            $unitNames = \App\Models\Erp\Unit::whereIn('id', $quantities->pluck('unit_id')->filter())->pluck('unit_name', 'id');
            $stats['quantity_by_unit'] = $quantities->map(fn ($row) => [
                'unit_id' => $row->unit_id, 'unit_name' => $unitNames[$row->unit_id] ?? '未指定单位',
                'quantity' => (string) $row->quantity,
            ])->all();
        }
        $paginator = $query->paginate($this->perPage($request));
        return response()->json([...$paginator->toArray(), 'stats' => $stats, 'stocktake_freezes' => $this->stocktakeFreezes($request)]);
    }

    public function show(Request $request, int $id)
    {
        $this->erpAuthorize($request, 'inventory.balance.view');
        $balance = InventoryBalance::with(['item.unit', 'item.skuRelations.sku.product', 'warehouse', 'location'])->findOrFail($id);
        app(ItemManagementScopeService::class)->assertContext($balance->item, $this->managementScope($request));
        return response()->json($balance);
    }

    public function serials(Request $request, int $id)
    {
        $forTransfer = $request->boolean('for_transfer');
        $user = $this->erpAuthorize($request, $forTransfer ? 'inventory.transfer.create' : 'inventory.balance.view');
        $data = $request->validate([
            'warehouse_id' => ($forTransfer ? 'required' : 'nullable').'|integer|min:1',
            'ids' => 'sometimes|required|array|min:1|max:500', 'ids.*' => 'required|integer|min:1|distinct',
        ]);
        if ($forTransfer) InventoryTransferService::assertWarehouseScope((int) $data['warehouse_id'], $user);
        $balance = InventoryBalance::query()->with('item')
            ->when($forTransfer, fn ($q) => $q->whereIn('id', app(\App\Services\Erp\Inventory\InventoryTransferService::class)->transferableBalances()->select('erp_inventory_balances.id')))
            ->when($forTransfer, fn ($q) => $q->where('warehouse_id', $data['warehouse_id'])->where('quantity_available', '>', 0)
                ->whereHas('item', fn ($item) => $item->where(fn ($mode) => $mode
                    ->whereIn('material_management_mode', ['quantity', ''])->orWhereNull('material_management_mode'))))
            ->findOrFail($id);
        app(ItemManagementScopeService::class)->assertContext($balance->item, $this->managementScope($request));
        $query = InventorySerial::query()
            ->where('inventory_balance_id', $id)
            ->when($forTransfer, fn ($q) => $q->where('serial_status', 'available'))
            ->when($forTransfer, fn ($q) => $q->select(['id', 'inventory_balance_id', 'serial_no']))
            ->when($data['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('serial_no');
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where('serial_no', 'like', "%{$keyword}%");
        }
        if ($request->filled('serial_status')) {
            $query->where('serial_status', $request->input('serial_status'));
        }

        return response()->json($query->paginate(isset($data['ids']) ? count($data['ids']) : $this->perPage($request)));
    }

    public function itemBatches(Request $request, int $itemId)
    {
        $this->erpAuthorize($request, 'inventory.balance.view');
        $item = Item::query()->with(['unit'])->findOrFail($itemId);
        app(ItemManagementScopeService::class)->assertContext($item, $this->managementScope($request));
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
        $this->attachBatchMeasures($request, $item, $paginator->getCollection());
        $measure = app(StockMeasureService::class);

        return response()->json([...$paginator->toArray(), 'item' => $item, 'measure_profile' => $measure->profile($item)]);
    }

    public function batchContext(Request $request, int $itemId)
    {
        $this->erpAuthorize($request, 'inventory.balance.view');
        $batchNo = trim((string) $request->input('batch_no'));
        abort_if($batchNo === '', 422, '批次号不能为空。');
        $item = Item::query()->with(['unit', 'skuRelations.sku.product'])->findOrFail($itemId);
        app(ItemManagementScopeService::class)->assertContext($item, $this->managementScope($request));
        $balances = InventoryBalance::query()
            ->with(['warehouse', 'location'])
            ->withCount([
                'serials',
                'serials as available_serials_count' => fn ($query) => $query->where('serial_status', 'available'),
            ])
            ->where('item_id', $itemId)->where('batch_no', $batchNo)
            ->where(fn ($q) => $q->where('quantity_on_hand', '<>', 0)->orWhere('quantity_locked', '<>', 0)->orWhere('quantity_defective', '<>', 0)->orWhere('quantity_pending', '<>', 0))
            ->orderBy('warehouse_id')->orderBy('location_id')->get();
        abort_if($balances->isEmpty(), 404, '该物料下没有此批次库存。');

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

    /** 在途是独立资产事实，不生成任何可用余额；实物只取当前 TRANSIT holding 一次。 */
    public function transit(Request $request)
    {
        $this->erpAuthorize($request, 'inventory.balance.view');
        $request->validate(['item_id' => 'nullable|integer|min:1', 'warehouse_id' => 'nullable|integer|min:1',
            'location_id' => 'nullable|integer|min:1', 'has_exception' => 'nullable|boolean', 'per_page' => 'nullable|integer|min:1|max:100']);
        $query = $this->transitBase($request);
        $stats = $this->transitStats(clone $query);
        $warehouseQuery = $this->activeBalanceQuery($request);
        if ($request->filled('keyword')) {
            $keyword = '%'.trim((string) $request->input('keyword')).'%';
            $warehouseQuery->whereHas('item', fn ($q) => $q->where('item_code', 'like', $keyword)->orWhere('item_name', 'like', $keyword)
                ->orWhere('spec', 'like', $keyword)->orWhere('model', 'like', $keyword));
        }
        $warehouseCost = (string) $warehouseQuery->sum('inventory_value');
        $stats += ['warehouse_cost_amount' => $warehouseCost, 'asset_cost_amount' => bcadd($warehouseCost, $stats['in_transit_cost_amount'], 4)];
        $query->leftJoin('erp_units as transit_unit', 'transit_unit.id', '=', 'transit.unit_id')
            ->leftJoin('erp_warehouses as transit_source', 'transit_source.id', '=', 'transit.source_warehouse_id')
            ->leftJoin('erp_warehouses as transit_target', 'transit_target.id', '=', 'transit.target_warehouse_id')
            ->leftJoin('erp_locations as transit_source_location', 'transit_source_location.id', '=', 'transit.source_location_id')
            ->leftJoin('erp_locations as transit_target_location', 'transit_target_location.id', '=', 'transit.target_location_id')
            ->select('transit.*', 'transit_item.item_code', 'transit_item.item_name', 'transit_item.spec', 'transit_item.management_scope', 'transit_unit.unit_name',
                'transit_source.warehouse_name as source_warehouse_name', 'transit_target.warehouse_name as target_warehouse_name',
                'transit_source_location.location_code as source_location_code', 'transit_target_location.location_code as target_location_code')
            ->orderBy('transit.dispatched_at')->orderBy('transit.kind')->orderBy('transit.id')->orderBy('transit.line_id');
        return response()->json([...$query->paginate($this->perPage($request))->toArray(), 'stats' => $stats]);
    }

    private function transitBase(Request $request): \Illuminate\Database\Query\Builder
    {
        $ordinary = app(InventoryTransferService::class)->transitQuery();
        $physical = app(PhysicalInventoryApplicationService::class)->transitQuery();
        $query = DB::query()->fromSub($ordinary->unionAll($physical), 'transit')->join('erp_items as transit_item', 'transit_item.id', '=', 'transit.item_id');
        if (($scope = $this->managementScope($request)) !== null) $query->where('transit_item.management_scope', $scope);
        if ($request->filled('item_id')) $query->where('transit.item_id', $request->integer('item_id'));
        if ($request->filled('warehouse_id')) $query->where(fn ($q) => $q->where('transit.source_warehouse_id', $request->integer('warehouse_id'))->orWhere('transit.target_warehouse_id', $request->integer('warehouse_id')));
        if ($request->filled('location_id')) $query->where(fn ($q) => $q->where('transit.source_location_id', $request->integer('location_id'))->orWhere('transit.target_location_id', $request->integer('location_id')));
        if ($request->filled('batch_no')) $query->where('transit.batch_no', $request->boolean('exact_batch') ? '=' : 'like',
            $request->boolean('exact_batch') ? $request->input('batch_no') : '%'.$request->input('batch_no').'%');
        foreach (['item_code', 'item_name'] as $field) if ($request->filled($field)) $query->where('transit_item.'.$field, 'like', '%'.$request->input($field).'%');
        if ($request->filled('keyword')) {
            $keyword = '%'.$request->input('keyword').'%';
            $query->where(fn ($q) => $q->where('transit_item.item_code', 'like', $keyword)->orWhere('transit_item.item_name', 'like', $keyword)
                ->orWhere('transit_item.spec', 'like', $keyword)->orWhere('transit_item.model', 'like', $keyword)
                ->orWhere('transit.number', 'like', $keyword)->orWhere('transit.batch_no', 'like', $keyword));
        }
        if ($request->has('has_exception')) $request->boolean('has_exception')
            ? $query->whereNotNull('transit.receipt_exception')->where('transit.receipt_exception', '!=', '')
            : $query->where(fn ($q) => $q->whereNull('transit.receipt_exception')->orWhere('transit.receipt_exception', ''));
        // 质量/在库筛选的资产范围仍只含对应仓内事实，不把在途伪装成不良或可用库存。
        if (in_array($request->input('inventory_status'), ['has_stock', 'has_quality'], true)) $query->whereRaw('1 = 0');
        return $query;
    }

    private function transitStats(\Illuminate\Database\Query\Builder $query): array
    {
        $row = (clone $query)->selectRaw('COALESCE(SUM(transit.cost_amount), 0) AS cost_amount, COUNT(DISTINCT transit.item_id) AS item_count,
            COUNT(DISTINCT CONCAT(transit.kind, ":", transit.id)) AS transfer_count,
            COUNT(DISTINCT CASE WHEN transit.receipt_exception IS NOT NULL AND transit.receipt_exception <> "" THEN CONCAT(transit.kind, ":", transit.id) END) AS exception_count')->first();
        $quantities = (clone $query)->select('transit.unit_id')->selectRaw('SUM(transit.quantity) AS quantity')->groupBy('transit.unit_id')->get();
        $units = \App\Models\Erp\Unit::query()->whereIn('id', $quantities->pluck('unit_id')->filter())->pluck('unit_name', 'id');
        return ['in_transit_cost_amount' => (string) $row->cost_amount, 'in_transit_item_count' => (int) $row->item_count,
            'in_transit_transfer_count' => (int) $row->transfer_count, 'in_transit_exception_count' => (int) $row->exception_count, 'in_transit_quantity_by_unit' => $quantities->map(fn ($q) => [
                'unit_id' => $q->unit_id, 'unit_name' => $units[$q->unit_id] ?? '未指定单位', 'quantity' => (string) $q->quantity])->all()];
    }

    private function withTransitStats(Request $request, array $stats, string $warehouseCost): array
    {
        $transit = $this->transitStats($this->transitBase($request));
        return $stats + $transit + ['warehouse_cost_amount' => $warehouseCost,
            'asset_cost_amount' => bcadd($warehouseCost, $transit['in_transit_cost_amount'], 4)];
    }

    private function itemSummary(Request $request)
    {
        $base = $this->activeBalanceQuery($request);
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $base->whereHas('item', fn ($q) => $q->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%")
                ->orWhere('spec', 'like', "%{$keyword}%")->orWhere('model', 'like', "%{$keyword}%"));
        }
        $statsQuery = clone $base;
        $warehouseCost = (string) (clone $statsQuery)->sum('inventory_value');
        $stats = [
            'item_count' => (clone $statsQuery)->distinct()->count('item_id'),
            'stock_item_count' => (clone $statsQuery)->where('quantity_on_hand', '>', 0)->distinct()->count('item_id'),
            'batch_count' => (clone $statsQuery)->selectRaw("COUNT(DISTINCT CONCAT(item_id, '|', COALESCE(batch_no, ''))) as aggregate")->value('aggregate'),
            'quality_item_count' => (clone $statsQuery)->where(fn ($q) => $q->where('quantity_defective', '>', 0)->orWhere('quantity_pending', '>', 0))->distinct()->count('item_id'),
            'inventory_value' => (float) $warehouseCost,
        ];
        $stats = $this->withTransitStats($request, $stats, $warehouseCost);
        $query = $base->selectRaw('item_id, SUM(quantity_on_hand) as quantity_on_hand, SUM(quantity_available) as quantity_available, SUM(quantity_locked) as quantity_locked, SUM(quantity_defective) as quantity_defective, SUM(quantity_pending) as quantity_pending, SUM(inventory_value) as inventory_value, COUNT(DISTINCT batch_no) as batch_count, COUNT(*) as balance_count, MAX(last_transaction_at) as last_transaction_at')
            ->groupBy('item_id')->orderByDesc('last_transaction_at');
        $paginator = $query->paginate($this->perPage($request));
        $items = Item::query()->with(['unit', 'skuRelations.sku.product'])->whereIn('id', $paginator->getCollection()->pluck('item_id'))->get()->keyBy('id');
        $paginator->getCollection()->transform(function ($row) use ($items) {
            $row->item = $items->get($row->item_id);
            return $row;
        });
        $this->attachItemMeasures($request, $paginator->getCollection());
        return response()->json([...$paginator->toArray(), 'stats' => $stats, 'stocktake_freezes' => $this->stocktakeFreezes($request)]);
    }

    /** 管材按根、板材按张记账：补上每个物料的总米数／总面积，列表上直接看。 */
    private function attachItemMeasures(Request $request, $rows): void
    {
        $service = app(StockMeasureService::class);
        $profiles = $service->profiles($rows->pluck('item_id'));
        if (! $profiles) return;
        $balances = $this->activeBalanceQuery($request)->whereIn('item_id', array_keys($profiles))
            ->get(['id', 'item_id', 'material_lot_id', 'batch_no', 'quantity_on_hand', 'quantity_available']);
        $measures = $service->balanceMeasures($balances);
        $rows->each(function ($row) use ($profiles, $balances, $measures) {
            $profile = $profiles[(int) $row->item_id] ?? null;
            if (! $profile) return;
            $row->measure = $this->sumMeasures($profile, $balances->where('item_id', $row->item_id)->map(fn ($b) => $measures[(int) $b->id] ?? null)->filter());
        });
    }

    private function attachBatchMeasures(Request $request, Item $item, $rows): void
    {
        $service = app(StockMeasureService::class);
        $profile = $service->profile($item);
        if (! $profile) return;
        $balances = $this->activeBalanceQuery($request)->where('item_id', $item->id)->whereIn('batch_no', $rows->pluck('batch_no'))
            ->get(['id', 'item_id', 'material_lot_id', 'batch_no', 'quantity_on_hand', 'quantity_available']);
        $measures = $service->balanceMeasures($balances);
        $rows->each(function ($row) use ($profile, $balances, $measures) {
            $list = $balances->where('batch_no', $row->batch_no)->map(fn ($b) => $measures[(int) $b->id] ?? null)->filter();
            $sum = $this->sumMeasures($profile, $list);
            $first = $list->first();
            $per = $list->pluck('per_unit_m')->filter()->unique()->values();
            $row->measure = $sum + [
                'per_unit_m' => $per->count() === 1 ? $per->first() : null,
                'lot_id' => $first['lot_id'] ?? null, 'lot_length_mm' => $first['lot_length_mm'] ?? null,
                'material_form' => $first['material_form'] ?? null,
                'length_editable' => $profile['mode'] === 'length' && $profile['kind'] === 'root' && ! empty($first['lot_length_mm']),
            ];
        });
    }

    private function sumMeasures(array $profile, $list): array
    {
        $base = ['mode' => $profile['mode'], 'kind' => $profile['kind'], 'standard_length_mm' => $profile['standard_length_mm']];
        if ($profile['mode'] === 'length') {
            $missing = $list->contains(fn ($m) => $m['length_m'] === null);
            return $base + ['length_m' => $missing ? null : round($list->sum('length_m'), 4),
                'available_length_m' => $missing ? null : round($list->sum('available_length_m'), 4)];
        }
        return $base + ['area_m2' => round($list->sum('area_m2'), 4)];
    }

    private function stocktakeFreezes(Request $request): array
    {
        $request->validate(['freeze_page' => 'sometimes|integer|min:1']);
        return app(\App\Services\Erp\Inventory\StocktakeFreezeQuery::class)->paginate([
            'management_scope' => $this->managementScope($request),
            'warehouse_id' => $request->input('warehouse_id'),
            'location_id' => $request->input('location_id'),
        ], (int) $request->input('freeze_page', 1), 'freeze_page');
    }

    private function activeBalanceQuery(Request $request)
    {
        $query = InventoryBalance::query()->where(fn ($q) => $q->where('quantity_on_hand', '<>', 0)
            ->orWhere('quantity_locked', '<>', 0)->orWhere('quantity_defective', '<>', 0)->orWhere('quantity_pending', '<>', 0));
        $this->applyManagementScope($query, $request);
        if ($request->filled('item_id')) $query->where('item_id', $request->integer('item_id'));
        if ($request->filled('warehouse_id')) $query->where('warehouse_id', $request->input('warehouse_id'));
        if ($request->filled('location_id')) $query->where('location_id', $request->input('location_id'));
        if ($request->filled('batch_no')) $query->where('batch_no', $request->boolean('exact_batch') ? '=' : 'like',
            $request->boolean('exact_batch') ? $request->input('batch_no') : '%' . $request->input('batch_no') . '%');
        if ($request->filled('inventory_status')) {
            if ($request->input('inventory_status') === 'has_stock') $query->where('quantity_on_hand', '>', 0);
            if ($request->input('inventory_status') === 'has_quality') $query->where(fn ($q) => $q->where('quantity_defective', '>', 0)->orWhere('quantity_pending', '>', 0));
        }
        return $query;
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, (int) $request->input('per_page', 10)));
    }

    private function managementScope(Request $request): ?string
    {
        return app(ItemManagementScopeService::class)->requestScope($request);
    }

    private function applyManagementScope($query, Request $request): void
    {
        $scope = $this->managementScope($request);
        if ($scope !== null) $query->whereHas('item', fn ($item) => app(ItemManagementScopeService::class)->applyScope($item, $scope));
    }
}
