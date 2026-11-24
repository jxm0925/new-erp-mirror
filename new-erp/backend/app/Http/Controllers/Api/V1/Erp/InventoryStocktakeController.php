<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\InventoryStocktake;
use App\Models\Erp\InventoryStocktakeLine;
use App\Models\Erp\Item;
use App\Models\Erp\InventoryAdjustment;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Inventory\InventoryStocktakeService;
use App\Services\Erp\Inventory\InventoryTransferService;
use App\Services\Erp\ItemCategoryApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryStocktakeController extends Controller
{
    public function index(Request $request)
    {
        $actor = $this->authorizePermission($request, 'inventory.stocktake.view');
        $warehouseIds = InventoryTransferService::warehouseIds($actor);
        $visible = InventoryStocktake::query()->when($warehouseIds !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouseIds));
        $query = (clone $visible)
            ->with(['warehouse:id,warehouse_code,warehouse_name', 'location:id,location_code,location_name'])
            ->withCount(['lines as counted_lines_count' => fn ($q) => $q->whereNotNull('counted_qty')])
            ->latest('id');
        if ($request->filled('keyword')) {
            $kw = trim((string) $request->input('keyword'));
            $query->where(fn ($q) => $q->where('stocktake_no', 'like', "%{$kw}%")
                ->orWhere('remark', 'like', "%{$kw}%")
                ->orWhereHas('warehouse', fn ($w) => $w->where('warehouse_name', 'like', "%{$kw}%")->orWhere('warehouse_code', 'like', "%{$kw}%")));
        }
        if ($request->filled('warehouse_id')) $query->where('warehouse_id', (int) $request->input('warehouse_id'));
        if ($request->filled('status')) $query->where('stocktake_status', $request->input('status'));
        $hasDifference = fn ($q) => $q->where(fn ($closed) => $closed->where('stocktake_status', '<>', 'counting')->where('difference_count', '>', 0))
            ->orWhere(fn ($open) => $open->where('stocktake_status', 'counting')->whereHas('lines', fn ($lines) => $lines->withDifferences()));
        if ($request->boolean('has_diff')) $query->where($hasDifference);
        $paginator = $query->paginate(min(100, max(10, (int) $request->input('per_page', 10))));
        $stocktakes = app(InventoryStocktakeService::class);
        foreach ($paginator->getCollection() as $document) {
            if ($document->stocktake_status !== 'counting') continue;
            $summary = $stocktakes->summary($document);
            $document->line_count = (int) $summary['total_lines'];
            $document->difference_count = (int) $summary['diff_lines'];
            $document->gain_qty = $summary['gain_qty'];
            $document->loss_qty = $summary['loss_qty'];
            $document->difference_amount = $summary['difference_amount'];
        }
        $this->attachNames($paginator->getCollection());
        $statsRow = (array) (clone $visible)->toBase()->selectRaw("
            COUNT(*) AS total,
            SUM(stocktake_status = 'counting') AS counting,
            SUM(stocktake_status = 'completed') AS completed,
            SUM(stocktake_status = 'cancelled') AS cancelled
        ")->first();
        $statsRow['diff_orders'] = (clone $visible)->where($hasDifference)->count();
        $payload = $paginator->toArray();
        $payload['stats'] = array_map(fn ($v) => (int) ($v ?? 0), $statsRow);
        return response()->json($payload);
    }

    public function options(Request $request)
    {
        $this->authorizeAnyPermission($request, ['inventory.stocktake.view', 'inventory.stocktake.create', 'inventory.transfer.view']);
        return response()->json(['data' => [
            'warehouses' => self::warehouseOptions(),
            'stocktake_statuses' => InventoryStocktakeService::STATUSES,
        ]]);
    }

    /** 盘点中提示：库存、配料、发货相关岗位都能看到冻结范围，权限与各页面入口一致。 */
    public function active(Request $request, \App\Services\Erp\Inventory\StocktakeFreezeQuery $freezes)
    {
        $this->authorizeAnyPermission($request, ['inventory.stocktake.view', 'inventory.balance.view', 'inventory.post.view',
            'inventory.material_delivery', 'production.material_delivery.manage', 'sales_order.shipment.view',
            'sales_order.shipment.post', 'sales_order.shipment.packing.execute', 'sales_order.shipment.packing.quality']);
        $data = $request->validate(['warehouse_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1']);
        return response()->json($freezes->paginate($data, (int) ($data['page'] ?? 1)));
    }

    public function show(Request $request, int $id, InventoryStocktakeService $service)
    {
        $actor = $this->authorizePermission($request, 'inventory.stocktake.view');
        $page = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'keyword' => 'nullable|string|max:120', 'location_id' => 'nullable|integer|min:1', 'line_id' => 'nullable|integer|min:1', 'line_status' => 'nullable|in:uncounted,diff']);
        $stocktake = InventoryStocktake::query()->with(['warehouse:id,warehouse_code,warehouse_name', 'location:id,location_code,location_name',
            'adjustment:id,adjustment_no,adjustment_status'])->findOrFail($id);
        InventoryTransferService::assertWarehouseScope((int) $stocktake->warehouse_id, $actor);
        $lines = $service->paginateLines($stocktake, $page, (int) ($page['per_page'] ?? 10));
        $summary = $service->summary($stocktake);
        $stocktake->line_count = (int) $summary['total_lines'];
        $stocktake->setAttribute('summary', $summary);
        $stocktake->setRelation('lines', $lines->getCollection());
        $stocktake->setAttribute('lines_pagination', ['current_page' => $lines->currentPage(), 'per_page' => $lines->perPage(), 'total' => $lines->total()]);
        $adjustmentIds = DB::table('erp_inventory_posting_logs as log')
            ->join('erp_inventory_transactions as transaction', 'transaction.id', '=', 'log.transaction_id')
            ->where('log.source_type', 'inventory_stocktake')->where('log.source_id', $id)
            ->where('log.transaction_type', 'stocktake_difference')->where('log.posting_status', 'posted')
            ->where('transaction.source_type', 'inventory_adjustment')->where('transaction.posting_status', 'posted')
            ->pluck('transaction.source_id')->push($stocktake->adjustment_id)->filter()->unique();
        $stocktake->setAttribute('adjustments', InventoryAdjustment::query()->whereIn('id', $adjustmentIds)
            ->orderBy('id')->get(['id', 'adjustment_no', 'adjustment_status']));
        $this->attachNames([$stocktake]);
        return response()->json(['data' => $stocktake]);
    }

    public function store(Request $request, InventoryStocktakeService $service)
    {
        $user = $this->authorizePermission($request, 'inventory.stocktake.create');
        $data = $request->validate(InventoryStocktakeService::CREATE_RULES);
        InventoryTransferService::assertWarehouseScope((int) $data['warehouse_id'], $user);
        return response()->json(['message' => '盘点单已创建，范围内库存已冻结', 'data' => $service->create($data, (int) $user->legacy_id)], 201);
    }

    public function locationPreview(Request $request, InventoryStocktakeService $service)
    {
        $actor = $this->authorizeAnyPermission($request, ['inventory.stocktake.create', 'inventory.stocktake.view']);
        $filters = $request->validate([
            'warehouse_id' => 'required|integer|min:1', 'location_id' => 'required|integer|min:1', 'keyword' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $warehouseId = (int) $filters['warehouse_id'];
        $locationId = (int) $filters['location_id'];
        InventoryTransferService::assertWarehouseScope($warehouseId, $actor);
        return response()->json($service->previewLocation($warehouseId, $locationId, $filters));
    }

    public function saveCounts(Request $request, int $id, InventoryStocktakeService $service)
    {
        $user = $this->authorizeInWarehouse($request, $id, 'inventory.stocktake.create');
        $data = $request->validate(InventoryStocktakeService::COUNT_RULES);
        return response()->json(['message' => '实盘数已保存', 'data' => $service->saveCounts($id, $data['lines'], (int) $user->legacy_id, $data['expected_version'])]);
    }

    public function items(Request $request, int $id)
    {
        $actor = $this->authorizePermission($request, 'inventory.stocktake.create');
        $stocktake = InventoryStocktake::with('warehouse')->findOrFail($id);
        InventoryTransferService::assertWarehouseScope((int) $stocktake->warehouse_id, $actor);
        $filters = $request->validate(['keyword' => 'nullable|string|max:120', 'category_id' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $keyword = trim($filters['keyword'] ?? '');
        $items = Item::query()->whereIn('status', ['enabled', 'active'])->where('is_stock_item', true)->where('item_type', '<>', 'service')
            ->where('management_scope', $stocktake->warehouse->management_scope)
            ->where(fn ($q) => $q->whereNull('manufacturing_strategy')->orWhere('manufacturing_strategy', '<>', 'phantom'))
            ->when($filters['category_id'] ?? null, fn ($q, $category) => $q->whereIn('category_id', [$category, ...app(ItemCategoryApplicationService::class)->descendantIds((int) $category)]))
            ->when($keyword !== '', fn ($q) => $q->where(fn ($w) => $w->where('item_code', 'like', "%{$keyword}%")
                ->orWhere('item_name', 'like', "%{$keyword}%")->orWhere('spec', 'like', "%{$keyword}%")))
            ->orderBy('item_code')->orderBy('id')->paginate($filters['per_page'] ?? 10, ['id', 'item_code', 'item_name', 'spec', 'unit_id',
                'material_management_mode', 'serial_tracking_mode', 'cutting_mode', 'is_length_cut_material']);
        $items->getCollection()->each(fn ($item) => $item->setAttribute('cutting_mode', $item->cuttingMode()));
        return response()->json($items);
    }

    public function addLine(Request $request, int $id, InventoryStocktakeService $service)
    {
        $actor = $this->authorizePermission($request, 'inventory.stocktake.create');
        return response()->json(['data' => $service->addLine($id, $request->validate(InventoryStocktakeService::ADD_LINE_RULES), $actor)], 201);
    }

    public function sourceEntries(Request $request, int $id, InventoryStocktakeService $service)
    {
        $actor = $this->authorizePermission($request, 'inventory.stocktake.view');
        $doc = InventoryStocktake::findOrFail($id);
        InventoryTransferService::assertWarehouseScope((int) $doc->warehouse_id, $actor);
        $filters = $request->validate([
            'source_key' => 'required|string|max:100', 'keyword' => 'nullable|string|max:120',
            'filter' => 'nullable|in:undecided,missing,new,locked', 'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        return response()->json($service->sourceEntries($doc, $filters['source_key'], $filters));
    }

    /** 逐件明细（序列号/实物），分页并支持按编号搜索。 */
    public function entries(Request $request, int $id, int $lineId)
    {
        $this->authorizeInWarehouse($request, $id, 'inventory.stocktake.view');
        $data = $request->validate(['keyword' => 'nullable|string|max:120', 'filter' => 'nullable|in:undecided,missing,new,locked',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $line = InventoryStocktakeLine::query()->where('stocktake_id', $id)->findOrFail($lineId);
        return response()->json(app(\App\Services\Erp\Inventory\InventoryStocktakeEntryService::class)->paginateStored($line, $data));
    }

    public function complete(Request $request, int $id, InventoryStocktakeService $service)
    {
        // 完成即按差异过账库存，与调整过账同等级授权。
        $user = $this->authorizeInWarehouse($request, $id, 'inventory.stocktake.complete');
        return response()->json(['message' => '盘点已完成', 'data' => $service->complete($id, (int) $user->legacy_id)]);
    }

    public function cancel(Request $request, int $id, InventoryStocktakeService $service)
    {
        $user = $this->authorizeInWarehouse($request, $id, 'inventory.stocktake.cancel');
        return response()->json(['message' => '盘点已取消，库存解除冻结', 'data' => $service->cancel($id, (int) $user->legacy_id)]);
    }

    /** 启用仓库及其启用库位，供盘点/调拨表单选择。 */
    public static function warehouseOptions(): array
    {
        $locations = DB::table('erp_locations')->whereIn('status', ['enabled', 'active'])->orderBy('location_code')
            ->get(['id', 'warehouse_id', 'location_code', 'location_name'])->groupBy('warehouse_id');
        return DB::table('erp_warehouses')->whereIn('status', ['enabled', 'active'])->orderBy('warehouse_code')
            ->get(['id', 'warehouse_code', 'warehouse_name'])
            ->map(fn ($warehouse) => (array) $warehouse + ['locations' => ($locations[$warehouse->id] ?? collect())->values()->all()])->all();
    }

    private function attachNames($rows): void
    {
        $ids = collect($rows)->flatMap(fn ($row) => [$row->created_by, $row->completed_by, $row->cancelled_by])->filter()->unique();
        $names = $ids->isEmpty() ? collect() : DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->get(['legacy_id', 'nickname', 'username'])
            ->mapWithKeys(fn ($user) => [(int) $user->legacy_id => $user->nickname ?: $user->username]);
        foreach ($rows as $row) {
            foreach (['created_by', 'completed_by', 'cancelled_by'] as $field) $row->setAttribute($field.'_name', $names[(int) $row->{$field}] ?? null);
            $row->setAttribute('status_label', InventoryStocktakeService::STATUSES[$row->stocktake_status] ?? $row->stocktake_status);
        }
    }

    private function authorizeInWarehouse(Request $request, int $id, string $permission): object
    {
        $actor = $this->authorizePermission($request, $permission);
        InventoryTransferService::assertWarehouseScope((int) InventoryStocktake::findOrFail($id, ['warehouse_id'])->warehouse_id, $actor);
        return $actor;
    }

    private function authorizeAnyPermission(Request $request, array $permissions): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless($auth->isSuperAdmin($user) || array_intersect($permissions, $auth->permissionCodes($user)), 403, '无按钮权限：'.implode(' 或 ', $permissions));
        return $user;
    }

    private function authorizePermission(Request $request, string $permission): object
    {
        return $this->authorizeAnyPermission($request, [$permission]);
    }
}
