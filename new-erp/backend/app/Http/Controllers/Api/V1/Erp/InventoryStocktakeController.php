<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\InventoryStocktake;
use App\Models\Erp\InventoryAdjustment;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Inventory\InventoryStocktakeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryStocktakeController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePermission($request, 'inventory.stocktake.view');
        $query = InventoryStocktake::query()->with(['warehouse:id,warehouse_code,warehouse_name', 'location:id,location_code,location_name'])->latest('id');
        if ($request->filled('keyword')) $query->where('stocktake_no', 'like', '%'.$request->input('keyword').'%');
        if ($request->filled('warehouse_id')) $query->where('warehouse_id', (int) $request->input('warehouse_id'));
        if ($request->filled('status')) $query->where('stocktake_status', $request->input('status'));
        $paginator = $query->paginate(min(100, max(10, (int) $request->input('per_page', 10))));
        $this->attachNames($paginator->getCollection());
        return response()->json($paginator);
    }

    public function options(Request $request)
    {
        $this->authorizeAnyPermission($request, ['inventory.stocktake.view', 'inventory.transfer.view']);
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

    public function show(Request $request, int $id)
    {
        $this->authorizePermission($request, 'inventory.stocktake.view');
        $page = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'keyword' => 'nullable|string|max:120', 'location_id' => 'nullable|integer|min:1', 'line_status' => 'nullable|in:uncounted,diff']);
        $stocktake = InventoryStocktake::query()->with(['warehouse:id,warehouse_code,warehouse_name', 'location:id,location_code,location_name',
            'adjustment:id,adjustment_no,adjustment_status'])->findOrFail($id);
        $keyword = trim((string) ($page['keyword'] ?? ''));
        $lines = $stocktake->lines()->with(['item:id,item_code,item_name,spec', 'location:id,location_code,location_name', 'unit:id,unit_name'])
            ->when($keyword !== '', fn ($q) => $q->where(fn ($w) => $w->where('batch_no', 'like', "%{$keyword}%")
                ->orWhereHas('item', fn ($item) => $item->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%"))))
            ->when($page['location_id'] ?? null, fn ($q, $locationId) => $q->where('location_id', $locationId))
            ->when(($page['line_status'] ?? null) === 'uncounted', fn ($q) => $q->whereNull('counted_qty'))
            ->when(($page['line_status'] ?? null) === 'diff', fn ($q) => $q->whereNotNull('difference_qty')->where('difference_qty', '<>', 0))
            ->orderBy('location_id')->orderBy('item_id')->orderBy('batch_no')->orderBy('id')
            ->paginate($page['per_page'] ?? 10);
        // 进度与差异汇总按整张单一条聚合 SQL 计算，不受当前页和筛选影响。
        $summary = (array) DB::table('erp_inventory_stocktake_lines')->where('stocktake_id', $stocktake->id)->selectRaw(
            'COUNT(*) AS total_lines, SUM(counted_qty IS NOT NULL) AS counted_lines, SUM(counted_qty IS NULL) AS uncounted_lines,
            SUM(difference_qty IS NOT NULL AND difference_qty <> 0) AS diff_lines,
            COALESCE(SUM(CASE WHEN difference_qty > 0 THEN difference_qty ELSE 0 END), 0) AS gain_qty,
            COALESCE(SUM(CASE WHEN difference_qty < 0 THEN -difference_qty ELSE 0 END), 0) AS loss_qty,
            COALESCE(SUM(difference_amount), 0) AS difference_amount')->first();
        $stocktake->setAttribute('summary', array_map(fn ($value) => $value === null ? 0 : (float) $value, $summary));
        $stocktake->setAttribute('location_options', DB::table('erp_inventory_stocktake_lines as line')
            ->join('erp_locations as location', 'location.id', '=', 'line.location_id')->where('line.stocktake_id', $stocktake->id)
            ->distinct()->orderBy('location.location_code')->limit(500)->get(['location.id', 'location.location_code', 'location.location_name']));
        $entryStats = app(\App\Services\Erp\Inventory\InventoryStocktakeEntryService::class)
            ->stats($lines->getCollection()->where('count_mode', '!=', 'quantity')->pluck('id')->all());
        $lines->getCollection()->each(fn ($line) => $line->setAttribute('entry_stats', $line->count_mode === 'quantity' ? null
            : ($entryStats[$line->id] ?? app(\App\Services\Erp\Inventory\InventoryStocktakeEntryService::class)->emptyStats())));
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
        $data = $request->validate([
            'warehouse_id' => 'required|integer|exists:erp_warehouses,id',
            'scope' => 'sometimes|required|in:location,warehouse',
            'location_id' => 'nullable|integer|exists:erp_locations,id',
            'stocktake_date' => 'nullable|date',
            'remark' => 'nullable|string|max:500',
        ]);
        return response()->json(['message' => '盘点单已创建，范围内库存已冻结', 'data' => $service->create($data, (int) $user->legacy_id)], 201);
    }

    public function saveCounts(Request $request, int $id, InventoryStocktakeService $service)
    {
        $user = $this->authorizePermission($request, 'inventory.stocktake.create');
        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.id' => 'required|integer',
            'expected_version' => 'required|integer|min:1',
            'lines.*.counted_qty' => 'nullable|numeric|min:0',
            'lines.*.remark' => 'nullable|string|max:500',
            // 序列号/实物逐件行：在/不在、全部在/全部不在、补录新件、删除补录件、无编号实盘数。
            'lines.*.mark_all_present' => 'nullable|boolean',
            'lines.*.entries' => 'nullable|array|max:500',
            'lines.*.entries.*.id' => 'required|integer',
            'lines.*.entries.*.counted_present' => 'nullable|boolean',
            'lines.*.delete_entry_ids' => 'nullable|array|max:500',
            'lines.*.delete_entry_ids.*' => 'integer',
            'lines.*.unnumbered_counted_qty' => 'nullable|numeric|min:0',
            'lines.*.new_entries' => 'nullable|array|max:500',
            'lines.*.new_entries.*.serial_no' => 'nullable|string|max:120',
            'lines.*.new_entries.*.physical_label' => 'nullable|string|max:120',
            'lines.*.new_entries.*.dimensions' => 'nullable|array',
            'lines.*.new_entries.*.dimensions.length_mm' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'lines.*.new_entries.*.dimensions.width_mm' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'lines.*.new_entries.*.dimensions.thickness_mm' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'lines.*.new_entries.*.dimensions.nominal_thickness_mm' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'lines.*.new_entries.*.total_cost' => ['nullable', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
        ]);
        return response()->json(['message' => '实盘数已保存', 'data' => $service->saveCounts($id, $data['lines'], (int) $user->legacy_id, $data['expected_version'])]);
    }

    /** 逐件明细（序列号/实物），分页并支持按编号搜索。 */
    public function entries(Request $request, int $id, int $lineId)
    {
        $this->authorizePermission($request, 'inventory.stocktake.view');
        $data = $request->validate(['keyword' => 'nullable|string|max:120', 'filter' => 'nullable|in:undecided,missing,new,locked',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $line = \App\Models\Erp\InventoryStocktakeLine::query()->where('stocktake_id', $id)->findOrFail($lineId);
        $keyword = trim((string) ($data['keyword'] ?? ''));
        $filter = $data['filter'] ?? null;
        $paginator = \App\Models\Erp\InventoryStocktakeEntry::query()->where('line_id', $line->id)
            ->when($keyword !== '', fn ($q) => $q->where(fn ($w) => $w->where('serial_no', 'like', "%{$keyword}%")->orWhere('physical_label', 'like', "%{$keyword}%")))
            ->when($filter === 'undecided', fn ($q) => $q->where('book_present', true)->where('is_locked', false)->whereNull('counted_present'))
            ->when($filter === 'missing', fn ($q) => $q->where('book_present', true)->where('is_locked', false)->where('counted_present', false))
            ->when($filter === 'new', fn ($q) => $q->where('is_new', true))
            ->when($filter === 'locked', fn ($q) => $q->where('is_locked', true))
            ->orderBy('is_new')->orderBy('is_locked')->orderBy('id')
            ->paginate($data['per_page'] ?? 10, ['id', 'entry_type', 'serial_no', 'physical_label', 'book_present', 'counted_present',
                'is_new', 'is_locked', 'book_status', 'dimensions', 'total_cost', 'counted_at']);
        return response()->json($paginator);
    }

    public function complete(Request $request, int $id, InventoryStocktakeService $service)
    {
        // 完成即按差异过账库存，与调整过账同等级授权。
        $user = $this->authorizePermission($request, 'inventory.stocktake.complete');
        return response()->json(['message' => '盘点已完成', 'data' => $service->complete($id, (int) $user->legacy_id)]);
    }

    public function cancel(Request $request, int $id, InventoryStocktakeService $service)
    {
        $user = $this->authorizePermission($request, 'inventory.stocktake.cancel');
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
