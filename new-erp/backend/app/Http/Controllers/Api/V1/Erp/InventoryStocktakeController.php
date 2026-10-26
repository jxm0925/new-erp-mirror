<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\InventoryStocktake;
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

    public function show(Request $request, int $id)
    {
        $this->authorizePermission($request, 'inventory.stocktake.view');
        $stocktake = InventoryStocktake::query()->with(['warehouse:id,warehouse_code,warehouse_name', 'location:id,location_code,location_name',
            'adjustment:id,adjustment_no,adjustment_status', 'lines.item:id,item_code,item_name,spec', 'lines.location:id,location_code,location_name',
            'lines.unit:id,unit_name'])->findOrFail($id);
        $this->attachNames([$stocktake]);
        return response()->json(['data' => $stocktake]);
    }

    public function store(Request $request, InventoryStocktakeService $service)
    {
        $user = $this->authorizePermission($request, 'inventory.stocktake.create');
        $data = $request->validate([
            'warehouse_id' => 'required|integer|exists:erp_warehouses,id',
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
            'lines.*.counted_qty' => 'nullable|numeric|min:0',
            'lines.*.remark' => 'nullable|string|max:500',
        ]);
        return response()->json(['message' => '实盘数已保存', 'data' => $service->saveCounts($id, $data['lines'], (int) $user->legacy_id)]);
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
