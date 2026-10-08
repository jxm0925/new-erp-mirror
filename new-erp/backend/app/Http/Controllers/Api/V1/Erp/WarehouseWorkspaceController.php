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
            'kind' => 'nullable|in:purchase_receipt,output,remnant,cutting_product,production_return,sales_return,picking,sales_shipment,purchase_return',
            'keyword' => 'nullable|string|max:120', 'date_from' => 'nullable|date',
            'date_to' => 'nullable|date'.($request->filled('date_from') ? '|after_or_equal:date_from' : ''),
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json(app(\App\Services\Erp\ProductionFinancialProjectionService::class)->redact($service->paginate($filters, ...$this->context($request))));
    }

    public function summary(Request $request, WarehouseWorkspaceService $service)
    { return response()->json(['data' => app(\App\Services\Erp\ProductionFinancialProjectionService::class)->redact($service->summary(...$this->context($request)))]); }

    public function document(Request $request, string $kind, int $id, \App\Services\Erp\WarehouseDocumentService $service)
    {
        $filters = $request->validate(['stage' => 'nullable|in:post,receive', 'receipt_id' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1', 'packages_page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json(['data' => app(\App\Services\Erp\ProductionFinancialProjectionService::class)->redact($service->show($kind, $id, $filters, ...$this->context($request)))]);
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
        return response()->json(['data' => app(\App\Services\Erp\ProductionFinancialProjectionService::class)->redact($service->run($data['action'], $data['aggregate_id'], $data['client_command_id'], $data['payload'], ...$this->context($request)))]);
    }

    public function commandResult(Request $request, \App\Services\Erp\WarehouseCommandService $service)
    {
        $data = $request->validate(['client_command_id' => 'required|string|max:120']);
        return response()->json(['data' => app(\App\Services\Erp\ProductionFinancialProjectionService::class)->redact($service->result($data['client_command_id'], ...$this->context($request)))]);
    }

    public function locators(Request $request)
    {
        [, $permissions] = $this->context($request);
        $allowed = ['inventory.post.repair', 'production.output.warehouse', 'production.cutting.warehouse',
            'production.material_picking.create', 'sales_return.receive'];
        if (! array_intersect($allowed, $permissions)) throw new WorkOrderDomainException('permission_denied', '没有选择入出库位置的权限。', 403);
        $filters = $request->validate(['mode' => 'required|in:warehouse,location', 'warehouse_id' => 'required_if:mode,location|integer|min:1',
            'keyword' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $mode = $filters['mode'];
        $query = DB::table($mode === 'warehouse' ? 'erp_warehouses' : 'erp_locations')->where('status', 'enabled');
        if ($mode === 'location') $query->where('warehouse_id', $filters['warehouse_id']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where(fn ($q) => $q->where($mode.'_code', 'like', '%'.$keyword.'%')->orWhere($mode.'_name', 'like', '%'.$keyword.'%'));
        $query->select('id', $mode.'_code as code', $mode.'_name as name', 'status');
        if ($mode === 'location') $query->addSelect('warehouse_id', 'area');
        return response()->json($query->orderBy('id')->paginate($filters['per_page'] ?? 10));
    }

    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录ERP。', 401);
        return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)];
    }
}
