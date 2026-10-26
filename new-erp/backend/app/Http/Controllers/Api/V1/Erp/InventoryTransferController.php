<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryTransfer;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Inventory\InventoryTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryTransferController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePermission($request, 'inventory.transfer.view');
        $query = InventoryTransfer::query()->with(['sourceWarehouse:id,warehouse_code,warehouse_name', 'targetWarehouse:id,warehouse_code,warehouse_name'])
            ->withCount('lines')->latest('id');
        if ($request->filled('keyword')) $query->where('transfer_no', 'like', '%'.$request->input('keyword').'%');
        if ($request->filled('status')) $query->where('transfer_status', $request->input('status'));
        if ($request->filled('warehouse_id')) {
            $warehouseId = (int) $request->input('warehouse_id');
            $query->where(fn ($q) => $q->where('source_warehouse_id', $warehouseId)->orWhere('target_warehouse_id', $warehouseId));
        }
        $paginator = $query->paginate(min(100, max(10, (int) $request->input('per_page', 10))));
        $this->attachNames($paginator->getCollection());
        return response()->json($paginator);
    }

    public function options(Request $request)
    {
        $this->authorizePermission($request, 'inventory.transfer.view');
        return response()->json(['data' => [
            'transfer_statuses' => InventoryTransferService::STATUSES,
        ]]);
    }

    /** 调出仓库中可调拨的数量库存批次；资格先于分页，已选来源按 ID 回显。 */
    public function balances(Request $request)
    {
        $this->authorizePermission($request, 'inventory.transfer.create');
        $data = $request->validate([
            'warehouse_id' => 'required|integer|min:1', 'keyword' => 'nullable|string|max:80',
            'location_id' => 'nullable|integer', 'category_id' => 'nullable|integer',
            'ids' => 'sometimes|required|array|min:1|max:200', 'ids.*' => 'required|integer|min:1|distinct',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = InventoryBalance::query()->with(['item:id,item_code,item_name,spec,material_management_mode,serial_tracking_mode,is_serial_managed',
            'location:id,location_code,location_name', 'unit:id,unit_name'])
            ->withCount(['serials as available_serials_count' => fn ($q) => $q->where('serial_status', 'available')])
            ->where('warehouse_id', (int) $data['warehouse_id'])->where('quantity_available', '>', 0)
            ->whereHas('item', fn ($item) => $item->where(fn ($mode) => $mode
                ->whereIn('material_management_mode', ['quantity', ''])->orWhereNull('material_management_mode')))
            ->when($data['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', (int) $id))
            ->when($data['category_id'] ?? null, fn ($q, $id) => $q->whereHas('item', fn ($item) => $item->where('category_id', $id)))
            ->when($data['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when($data['keyword'] ?? null, fn ($q, $keyword) => $q->where(fn ($w) => $w->where('batch_no', 'like', "%{$keyword}%")
                ->orWhereHas('item', fn ($item) => $item->where('item_code', 'like', "%{$keyword}%")
                    ->orWhere('item_name', 'like', "%{$keyword}%")->orWhere('spec', 'like', "%{$keyword}%"))
                ->orWhereHas('location', fn ($location) => $location->where('location_code', 'like', "%{$keyword}%")
                    ->orWhere('location_name', 'like', "%{$keyword}%"))))
            ->orderBy('item_id')->orderBy('id');
        $paginator = $query->paginate(isset($data['ids']) ? count($data['ids']) : ($data['per_page'] ?? 10));
        $paginator->getCollection()->transform(fn ($row) => [
            'id' => $row->id, 'item_id' => $row->item_id, 'item_code' => $row->item?->item_code, 'item_name' => $row->item?->item_name,
            'spec' => $row->item?->spec, 'location_id' => $row->location_id, 'location_name' => $row->location?->location_name,
            'location_code' => $row->location?->location_code, 'batch_no' => $row->batch_no, 'unit_name' => $row->unit?->unit_name,
            'quantity_available' => (float) $row->quantity_available,
            'available_serials_count' => (int) $row->available_serials_count,
        ]);
        return response()->json($paginator);
    }

    public function show(Request $request, int $id)
    {
        $this->authorizePermission($request, 'inventory.transfer.view');
        $transfer = InventoryTransfer::query()->with(['sourceWarehouse:id,warehouse_code,warehouse_name', 'targetWarehouse:id,warehouse_code,warehouse_name',
            'lines.item:id,item_code,item_name,spec', 'lines.sourceLocation:id,location_code,location_name', 'lines.targetLocation:id,location_code,location_name',
            'lines.unit:id,unit_name'])->findOrFail($id);
        $serialNos = InventorySerial::query()->whereIn('id', $transfer->lines->flatMap(fn ($line) => $line->serial_ids ?? []))->pluck('serial_no', 'id');
        foreach ($transfer->lines as $line) $line->setAttribute('serial_nos', collect($line->serial_ids ?? [])->map(fn ($sid) => $serialNos[$sid] ?? null)->filter()->values());
        $this->attachNames([$transfer]);
        return response()->json(['data' => $transfer]);
    }

    public function store(Request $request, InventoryTransferService $service)
    {
        $user = $this->authorizePermission($request, 'inventory.transfer.create');
        return response()->json(['message' => '调拨单已保存', 'data' => $service->save($this->payload($request), null, (int) $user->legacy_id)], 201);
    }

    public function update(Request $request, int $id, InventoryTransferService $service)
    {
        $user = $this->authorizePermission($request, 'inventory.transfer.create');
        return response()->json(['message' => '调拨单已更新', 'data' => $service->save($this->payload($request), $id, (int) $user->legacy_id)]);
    }

    public function post(Request $request, int $id, InventoryTransferService $service)
    {
        $user = $this->authorizePermission($request, 'inventory.transfer.post');
        return response()->json(['message' => '调拨已过账', 'data' => $service->post($id, (int) $user->legacy_id)]);
    }

    public function cancel(Request $request, int $id, InventoryTransferService $service)
    {
        $user = $this->authorizeAnyPermission($request, ['inventory.transfer.create', 'inventory.transfer.post']);
        return response()->json(['message' => '调拨单已取消', 'data' => $service->cancel($id, (int) $user->legacy_id)]);
    }

    public function destroy(Request $request, int $id, InventoryTransferService $service)
    {
        $this->authorizePermission($request, 'inventory.transfer.delete');
        $service->deleteDraft($id);
        return response()->json(['message' => '调拨草稿已删除']);
    }

    private function payload(Request $request): array
    {
        return $request->validate([
            'source_warehouse_id' => 'required|integer|exists:erp_warehouses,id',
            'target_warehouse_id' => 'required|integer|exists:erp_warehouses,id',
            'transfer_date' => 'nullable|date',
            'remark' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1|max:200',
            'lines.*.inventory_balance_id' => 'required|integer',
            'lines.*.target_location_id' => 'required|integer',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.serial_ids' => 'nullable|array|max:500',
            'lines.*.serial_ids.*' => 'integer',
            'lines.*.remark' => 'nullable|string|max:500',
        ]);
    }

    private function attachNames($rows): void
    {
        $ids = collect($rows)->flatMap(fn ($row) => [$row->created_by, $row->posted_by, $row->cancelled_by])->filter()->unique();
        $names = $ids->isEmpty() ? collect() : DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->get(['legacy_id', 'nickname', 'username'])
            ->mapWithKeys(fn ($user) => [(int) $user->legacy_id => $user->nickname ?: $user->username]);
        foreach ($rows as $row) {
            foreach (['created_by', 'posted_by', 'cancelled_by'] as $field) $row->setAttribute($field.'_name', $names[(int) $row->{$field}] ?? null);
            $row->setAttribute('status_label', InventoryTransferService::STATUSES[$row->transfer_status] ?? $row->transfer_status);
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
