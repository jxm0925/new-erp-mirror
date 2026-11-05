<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\{AuthContextService, WarehouseWorkspaceService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WarehouseWorkspaceController extends Controller
{
    public function index(Request $request, WarehouseWorkspaceService $service)
    {
        $filters = $request->validate(['direction' => 'nullable|in:all,inbound,outbound', 'status_group' => 'nullable|in:pending,completed',
            'kind' => 'nullable|in:purchase_receipt,sales_return,sales_shipment,purchase_return,material_preparation,material_delivery,material_return,stocktake,mfg_receipt,cutting_remnant,inventory_transfer,material_physical_transfer',
            'status' => 'nullable|in:pending,confirmed,rejected,cancelled,in_transit,posted,IN_TRANSIT,POSTED',
            'has_exception' => 'nullable|boolean', 'warehouse_id' => 'nullable|integer|min:1',
            'keyword' => 'nullable|string|max:120', 'date_from' => 'nullable|date',
            'date_to' => 'nullable|date'.($request->filled('date_from') ? '|after_or_equal:date_from' : ''),
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($this->project($service->paginate($filters, ...$this->context($request)), $request));
    }

    public function summary(Request $request, WarehouseWorkspaceService $service)
    {
        $summary = $this->project($service->summary(...$this->context($request)), $request);
        // 盘点中提示与网页端同源，仓库人员在小程序上也能看到哪些范围暂停出入库。
        $summary['stocktake_freezes'] = app(\App\Services\Erp\Inventory\StocktakeFreezeQuery::class)->paginate();
        return response()->json(['data' => $summary]);
    }

    public function document(Request $request, string $kind, int $id, \App\Services\Erp\WarehouseDocumentService $service)
    {
        $filters = $request->validate(['stage' => 'nullable|in:post,receive,prepare,deliver,count,confirm', 'receipt_id' => 'nullable|integer|min:1',
            'units' => 'nullable|integer|min:1|max:100000', 'keyword' => 'nullable|string|max:120',
            'unit_ids' => 'nullable|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1|distinct',
            'location_id' => 'nullable|integer|min:1', 'line_status' => 'nullable|in:uncounted,diff',
            'page' => 'nullable|integer|min:1', 'packages_page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json(['data' => $this->project($service->show($kind, $id, $filters, ...$this->context($request)), $request)]);
    }

    public function salesReturnSerials(Request $request, \App\Services\Erp\SalesReturnIdentityService $service)
    {
        [$user, $permissions] = $this->context($request);
        if (! in_array('sales_return.receive', $permissions, true) || ! in_array('sales_return.view', $permissions, true))
            throw new WorkOrderDomainException('permission_denied', '没有核对销售退货序列号的权限。', 403);
        $filters = $request->validate(['return_id' => 'required|integer|min:1', 'return_item_id' => 'required|integer|min:1',
            'keyword' => 'nullable|string|max:120', 'batch_no' => 'nullable|string|max:80',
            'ids' => 'sometimes|array|max:1000', 'ids.*' => 'required|integer|min:1|distinct',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $orders = \App\Models\Erp\SalesOrder::query()->select('id');
        app(\App\Services\Erp\SalesOrderVisibilityService::class)->apply($orders, $user);
        \App\Models\Erp\SalesReturn::whereIn('sales_order_id', $orders)->findOrFail($filters['return_id']);
        return response()->json($service->candidates($filters['return_id'], $filters['return_item_id'], $filters));
    }

    public function command(Request $request, \App\Services\Erp\WarehouseCommandService $service)
    {
        $data = $request->validate(['action' => 'required|string|max:60', 'aggregate_id' => 'required|integer|min:1',
            'client_command_id' => 'required|string|max:120', 'payload' => 'present|array']);
        return response()->json(['data' => $this->project($service->run($data['action'], $data['aggregate_id'], $data['client_command_id'], $data['payload'], ...$this->context($request)), $request)]);
    }

    public function commandResult(Request $request, \App\Services\Erp\WarehouseCommandService $service)
    {
        $data = $request->validate(['client_command_id' => 'required|string|max:120']);
        return response()->json(['data' => $this->project($service->result($data['client_command_id'], ...$this->context($request)), $request)]);
    }

    public function locators(Request $request)
    {
        [, $permissions] = $this->context($request);
        // production.task.execute：车间开料登记余料回库位置，只能选工厂仓。
        $allowed = ['inventory.post.repair', 'sales_return.receive', 'sales_order.shipment.post', 'sales_order.shipment.packing.execute',
            'sales_order.shipment.packing.quality', 'production.task.execute', 'inventory.balance.view', 'inventory.stocktake.view',
            'production.material_delivery.manage', 'production.material_return.approve', 'inventory.inbound.confirm', 'production.receipt.execute'];
        $allowed = array_merge($allowed, ['inventory.transfer.view', 'inventory.transfer.create', 'inventory.transfer.post', 'inventory.physical.manage', 'production.work_order.edit']);
        if (! array_intersect($allowed, $permissions)) throw new WorkOrderDomainException('permission_denied', '没有选择入出库位置的权限。', 403);
        $filters = $request->validate(['mode' => 'required|in:warehouse,location', 'warehouse_id' => 'required_if:mode,location|integer|min:1',
            'keyword' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
            'id' => 'nullable|integer|min:1',
            'kind' => 'nullable|in:inventory_transfer,material_physical_transfer',
            'location_purpose' => 'nullable|in:general,finished,shipping_staging,repair',
            'location_purposes' => 'nullable|array|max:4',
            'location_purposes.*' => 'required|in:general,finished,shipping_staging,repair|distinct']);
        $mode = $filters['mode'];
        $query = DB::table($mode === 'warehouse' ? 'erp_warehouses' : 'erp_locations')->where('status', 'enabled');
        if ($mode === 'location') $query->where('warehouse_id', $filters['warehouse_id']);
        if (! empty($filters['id'])) $query->where('id', $filters['id']);
        if ($mode === 'location' && ! empty($filters['location_purpose'])) $query->where('location_purpose', $filters['location_purpose']);
        if ($mode === 'location' && ! empty($filters['location_purposes'])) $query->whereIn('location_purpose', $filters['location_purposes']);
        if (! empty($filters['kind'])) {
            $ids = \App\Services\Erp\Inventory\InventoryTransferService::warehouseIds($this->context($request)[0]);
            if ($ids !== null) $query->whereIn($mode === 'warehouse' ? 'id' : 'warehouse_id', $ids);
        }
        if (array_intersect(['sales_order.shipment.post', 'sales_order.shipment.packing.execute', 'sales_order.shipment.packing.quality', 'production.task.execute', 'production.receipt.execute'], $permissions)
            && ! array_intersect(['inventory.post.repair', 'sales_return.receive', 'inventory.balance.view', 'inventory.stocktake.view',
                'production.material_return.approve', 'inventory.inbound.confirm'], $permissions)) {
            if ($mode === 'warehouse') $query->where('management_scope', 'factory');
            else $query->whereIn('warehouse_id', DB::table('erp_warehouses')->where('management_scope', 'factory')->where('status', 'enabled')->select('id'));
        }
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where(fn ($q) => $q->where($mode.'_code', 'like', '%'.$keyword.'%')->orWhere($mode.'_name', 'like', '%'.$keyword.'%'));
        $query->select('id', $mode.'_code as code', $mode.'_name as name', 'status');
        if ($mode === 'location') $query->addSelect('warehouse_id', 'area', 'location_purpose');
        return response()->json($query->orderBy('id')->paginate($filters['per_page'] ?? 10));
    }

    private function project(mixed $payload, Request $request): mixed
    {
        $inventoryAmounts = app(\App\Services\Erp\InventoryAmountVisibilityService::class);
        return app(\App\Services\Erp\ProductionFinancialProjectionService::class)->redact(
            $inventoryAmounts->forResponse($payload, $request), $inventoryAmounts->canView($request), true);
    }

    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录ERP。', 401);
        return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)];
    }
}
