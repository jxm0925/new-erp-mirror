<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryTransfer;
use App\Models\Erp\InventorySerial;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Inventory\InventoryTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryTransferController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->authorizeAnyPermission($request, ['inventory.transfer.view', 'inventory.inbound.confirm']);
        $request->validate(['status' => 'nullable|in:draft,in_transit,posted,cancelled', 'has_exception' => 'nullable|boolean']);
        $query = InventoryTransfer::query()->with([
            'sourceWarehouse:id,warehouse_code,warehouse_name',
            'targetWarehouse:id,warehouse_code,warehouse_name',
            'lines:id,transfer_id,item_id,source_location_id,target_location_id,unit_id,batch_no,quantity',
            'lines.item:id,item_code,item_name,spec',
            'lines.unit:id,unit_name',
            'lines.sourceLocation:id,location_code,location_name',
            'lines.targetLocation:id,location_code,location_name',
        ])->withCount('lines')->latest('id');
        if ($request->filled('keyword')) {
            $kw = trim((string) $request->input('keyword'));
            $query->where(fn ($q) => $q->where('transfer_no', 'like', "%{$kw}%")
                ->orWhere('remark', 'like', "%{$kw}%")
                ->orWhereHas('lines', fn ($lq) => $lq->where('batch_no', 'like', "%{$kw}%")
                    ->orWhereHas('item', fn ($iq) => $iq->where('item_code', 'like', "%{$kw}%")->orWhere('item_name', 'like', "%{$kw}%"))));
        }
        if ($request->filled('status')) $query->where('transfer_status', $request->input('status'));
        if ($request->has('has_exception')) $request->boolean('has_exception')
            ? $query->whereNotNull('receipt_exception')->where('receipt_exception', '!=', '')
            : $query->where(fn ($q) => $q->whereNull('receipt_exception')->orWhere('receipt_exception', ''));
        $warehouseIds = InventoryTransferService::warehouseIds($user);
        if ($warehouseIds !== null) $query->where(fn ($q) => $q->whereIn('source_warehouse_id', $warehouseIds)->orWhereIn('target_warehouse_id', $warehouseIds));
        if ($request->filled('warehouse_id')) {
            $warehouseId = (int) $request->input('warehouse_id');
            $query->where(fn ($q) => $q->where('source_warehouse_id', $warehouseId)->orWhere('target_warehouse_id', $warehouseId));
        }
        $statsRow = (array) DB::table('erp_inventory_transfers')->whereIn('id', (clone $query)->reorder()->select('id'))->selectRaw("
            COUNT(*) AS total,
            SUM(transfer_status = 'draft') AS draft,
            SUM(transfer_status = 'in_transit') AS in_transit,
            SUM(transfer_status = 'posted') AS posted,
            SUM(receipt_exception IS NOT NULL AND receipt_exception <> '') AS has_exception,
            SUM(transfer_status = 'cancelled') AS cancelled
        ")->first();
        $paginator = $query->paginate(min(100, max(10, (int) $request->input('per_page', 10))));
        $this->attachNames($paginator->getCollection());
        $this->attachActions($paginator->getCollection(), $user);
        $payload = $paginator->toArray();
        $payload['stats'] = array_map(fn ($v) => (int) ($v ?? 0), $statsRow);
        return response()->json($payload);
    }

    public function options(Request $request)
    {
        $this->authorizeAnyPermission($request, ['inventory.transfer.view', 'inventory.inbound.confirm']);
        return response()->json(['data' => [
            'transfer_statuses' => InventoryTransferService::STATUSES,
        ]]);
    }

    /** 调出仓库中可调拨的数量库存批次；资格先于分页，已选来源按 ID 回显。 */
    public function balances(Request $request, InventoryTransferService $service, InventoryAvailabilityService $availability)
    {
        $user = $this->authorizePermission($request, 'inventory.transfer.create');
        $data = $request->validate([
            'warehouse_id' => 'required|integer|min:1', 'keyword' => 'nullable|string|max:80',
            'location_id' => 'nullable|integer', 'category_id' => 'nullable|integer',
            'ids' => 'sometimes|required|array|min:1|max:200', 'ids.*' => 'required|integer|min:1|distinct',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        InventoryTransferService::assertWarehouseScope((int) $data['warehouse_id'], $user);
        $query = InventoryBalance::query()->with(['item:id,item_code,item_name,spec,item_type,material_management_mode,serial_tracking_mode,is_serial_managed',
            'location:id,location_code,location_name', 'unit:id,unit_name'])
            ->withCount(['serials as available_serials_count' => fn ($q) => $q->where('serial_status', 'available')])
            ->where('warehouse_id', (int) $data['warehouse_id'])
            ->whereIn('id', $service->transferableBalances()->select('erp_inventory_balances.id'))
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
            'quantity_available' => $available = $availability->availableForOutbound($row),
            'available_serials_count' => (int) $row->available_serials_count,
            'unnumbered_available' => max(0, $available - (int) $row->available_serials_count),
            'serial_tracking_mode' => $row->item?->serialTrackingMode() ?? 'none',
        ]);
        return response()->json($paginator);
    }

    public function show(Request $request, int $id)
    {
        $user = $this->authorizeAnyPermission($request, ['inventory.transfer.view', 'inventory.inbound.confirm']);
        $transfer = InventoryTransfer::query()->with(['sourceWarehouse:id,warehouse_code,warehouse_name,management_scope', 'targetWarehouse:id,warehouse_code,warehouse_name',
            'lines.item:id,item_code,item_name,spec', 'lines.sourceLocation:id,location_code,location_name', 'lines.targetLocation:id,location_code,location_name',
            'lines.unit:id,unit_name'])->findOrFail($id);
        $warehouseIds = InventoryTransferService::warehouseIds($user);
        abort_if($warehouseIds !== null && ! in_array((int) $transfer->source_warehouse_id, $warehouseIds, true)
            && ! in_array((int) $transfer->target_warehouse_id, $warehouseIds, true), 403, '调拨单不在当前账号的仓库范围内。');
        $serialNos = InventorySerial::query()->whereIn('id', $transfer->lines->flatMap(fn ($line) => $line->serial_ids ?? []))->pluck('serial_no', 'id');
        foreach ($transfer->lines as $line) $line->setAttribute('serial_nos', collect($line->serial_ids ?? [])->map(fn ($sid) => $serialNos[$sid] ?? null)->filter()->values());
        $this->attachNames([$transfer]);
        $this->attachActions([$transfer], $user);
        return response()->json(['data' => $transfer]);
    }

    public function store(Request $request)
    {
        return response()->json(['message' => '调拨单已保存', 'data' => $this->command($request, 'create', $request->integer('source_warehouse_id'), ['inventory.transfer.create'])], 201);
    }

    public function update(Request $request, int $id)
    {
        return response()->json(['message' => '调拨单已更新', 'data' => $this->command($request, 'update', $id, ['inventory.transfer.create'])]);
    }

    public function post(Request $request, int $id)
    {
        $row = $this->command($request, 'post', $id, ['inventory.transfer.post']);
        return response()->json(['message' => ($row['transfer_status'] ?? '') === 'in_transit' ? '调拨已调出，待目标仓整单接收。' : '同仓移库已过账。', 'data' => $row]);
    }

    public function confirm(Request $request, int $id)
    {
        return response()->json(['message' => '调拨已整单接收。', 'data' => $this->command($request, 'confirm', $id, ['inventory.inbound.confirm'])]);
    }

    public function receiptException(Request $request, int $id)
    {
        return response()->json(['message' => '接收异常已记录，调拨保持在途。', 'data' => $this->command($request, 'exception', $id, ['inventory.inbound.confirm', 'inventory.transfer.post'])]);
    }

    public function cancel(Request $request, int $id)
    {
        return response()->json(['message' => '调拨单已取消', 'data' => $this->command($request, 'cancel', $id, ['inventory.transfer.create', 'inventory.transfer.post'])]);
    }

    public function destroy(Request $request, int $id)
    {
        return response()->json(['message' => '调拨草稿已删除', 'data' => $this->command($request, 'delete', $id, ['inventory.transfer.delete'])]);
    }

    private function command(Request $request, string $action, int $id, array $permissions): array
    {
        $user = $this->authorizeAnyPermission($request, $permissions);
        $request->validate(['client_command_id' => 'required|string|max:120']);
        $auth = app(AuthContextService::class);
        return app(\App\Services\Erp\WarehouseCommandService::class)->run('inventory_transfer.'.$action, $id,
            $request->input('client_command_id'), $request->except('client_command_id'), $user,
            $auth->permissionCodes($user), $auth->isSuperAdmin($user))['result'];
    }

    private function attachNames($rows): void
    {
        $ids = collect($rows)->flatMap(fn ($row) => [$row->created_by, $row->posted_by, $row->cancelled_by, $row->dispatched_by, $row->confirmed_by, $row->exception_by])->filter()->unique();
        $names = $ids->isEmpty() ? collect() : DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->get(['legacy_id', 'nickname', 'username'])
            ->mapWithKeys(fn ($user) => [(int) $user->legacy_id => $user->nickname ?: $user->username]);
        foreach ($rows as $row) {
            foreach (['created_by', 'posted_by', 'cancelled_by', 'dispatched_by', 'confirmed_by', 'exception_by'] as $field) $row->setAttribute($field.'_name', $names[(int) $row->{$field}] ?? null);
            $row->setAttribute('has_exception', (bool) $row->receipt_exception);
            $row->setAttribute('status_label', InventoryTransferService::STATUSES[$row->transfer_status] ?? $row->transfer_status);
            if ($row->relationLoaded('lines')) {
                $previews = $row->lines->take(2)->map(function ($line) {
                    $name = $line->item?->item_name ?: $line->item?->item_code ?: '物料';
                    $qty = rtrim(rtrim(number_format((float) $line->quantity, 4, '.', ''), '0'), '.');
                    $unit = $line->unit?->unit_name ?: '';
                    return "{$name} × {$qty}{$unit}";
                })->all();
                $more = $row->lines->count() > 2 ? ' 等 '.$row->lines->count().' 项' : '';
                $row->setAttribute('items_summary', $previews ? implode('；', $previews).$more : '—');
                $row->setAttribute('total_quantity', (float) $row->lines->sum('quantity'));
            }
        }
    }

    private function attachActions($rows, object $user): void
    {
        $codes = app(AuthContextService::class)->permissionCodes($user);
        $warehouses = InventoryTransferService::warehouseIds($user);
        foreach ($rows as $row) {
            $source = $warehouses === null || in_array((int) $row->source_warehouse_id, $warehouses, true);
            $target = $warehouses === null || in_array((int) $row->target_warehouse_id, $warehouses, true);
            foreach (['edit' => 'inventory.transfer.create', 'post' => 'inventory.transfer.post', 'delete' => 'inventory.transfer.delete'] as $action => $permission)
                $row->setAttribute('can_'.$action, $row->transfer_status === 'draft' && $source && in_array($permission, $codes, true));
            $row->setAttribute('can_cancel', $row->transfer_status === 'draft' && $source && (bool) array_intersect(['inventory.transfer.create', 'inventory.transfer.post'], $codes));
            $row->setAttribute('can_confirm', $row->transfer_status === 'in_transit' && $target && in_array('inventory.inbound.confirm', $codes, true));
            $row->setAttribute('can_record_exception', $row->transfer_status === 'in_transit' && (($target && in_array('inventory.inbound.confirm', $codes, true))
                || ($source && in_array('inventory.transfer.post', $codes, true))));
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
