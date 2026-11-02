<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\PhysicalInventoryApplicationService;
use Illuminate\Http\Request;

class PhysicalInventoryController extends Controller
{
    /** 只列当前账号仓库中可调出的实物，资格过滤先于分页。 */
    public function candidates(Request $request, \App\Services\Erp\InventoryAvailabilityService $availability)
    {
        $user = $this->authorizeTransfer($request, ['inventory.physical.manage']);
        $data = $request->validate(['warehouse_id' => 'nullable|integer|min:1', 'location_id' => 'nullable|integer|min:1',
            'category_id' => 'nullable|integer|min:1', 'keyword' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $query = \Illuminate\Support\Facades\DB::table('erp_material_physicals as p')
            ->join('erp_material_holdings as h', 'h.id', '=', 'p.current_holding_id')
            ->join('erp_inventory_balances as b', 'b.id', '=', 'h.inventory_balance_id')
            ->join('erp_items as i', 'i.id', '=', 'p.item_id')
            ->join('erp_warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->join('erp_locations as l', 'l.id', '=', 'b.location_id')
            ->leftJoin('erp_item_categories as category', 'category.id', '=', 'i.category_id')
            ->where('p.status', 'AVAILABLE')->where('h.status', 'ACTIVE')->where('h.position_type', 'WAREHOUSE')
            ->whereIn('b.id', $availability->outboundBalanceQuery()->reorder()->select('erp_inventory_balances.id'))
            ->whereColumn('i.management_scope', 'w.management_scope')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_inventory_stocktakes as st')->where('st.stocktake_status', 'counting')
                ->whereColumn('st.warehouse_id', 'b.warehouse_id')->where(fn ($q) => $q->whereNull('st.location_id')->orWhereColumn('st.location_id', 'b.location_id')))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_inventory_adjustment_item_physicals as ap')
                ->join('erp_inventory_adjustment_items as ai', 'ai.id', '=', 'ap.adjustment_item_id')->join('erp_inventory_adjustments as a', 'a.id', '=', 'ai.adjustment_id')
                ->whereColumn('ap.physical_material_id', 'p.id')->whereIn('a.adjustment_status', ['draft', 'submitted']))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_purchase_return_item_physicals as rp')
                ->join('erp_purchase_return_items as ri', 'ri.id', '=', 'rp.purchase_return_item_id')->join('erp_purchase_returns as r', 'r.id', '=', 'ri.return_id')
                ->whereColumn('rp.physical_material_id', 'p.id')->whereIn('r.return_status', ['draft', 'submitted', 'pending_outbound']));
        $ids = \App\Services\Erp\Inventory\InventoryTransferService::warehouseIds($user);
        if ($ids !== null) $query->whereIn('b.warehouse_id', $ids);
        if (!empty($data['warehouse_id'])) $query->where('b.warehouse_id', $data['warehouse_id']);
        if (!empty($data['location_id'])) $query->where('b.location_id', $data['location_id']);
        $categories = (clone $query)->whereNotNull('category.id')->select('category.id', 'category.category_name as name')->distinct()->orderBy('category.id')->get();
        if (!empty($data['category_id'])) $query->where('i.category_id', $data['category_id']);
        if ($keyword = trim($data['keyword'] ?? '')) $query->where(function ($q) use ($keyword) {
            foreach (['p.physical_no', 'i.item_code', 'i.item_name', 'i.spec', 'i.model', 'i.material_grade', 'b.batch_no', 'l.location_code'] as $field) $q->orWhere($field, 'like', '%'.$keyword.'%');
        });
        $page = $query->select('p.id', 'p.physical_no', 'p.dimensions', 'p.shape', 'p.material_form', 'p.status', 'p.business_version',
            'i.id as item_id', 'i.item_code', 'i.item_name', 'i.spec', 'i.model', 'i.material_grade', 'category.category_name',
            'b.batch_no', 'b.warehouse_id', 'w.warehouse_name', 'b.location_id', 'l.location_code', 'l.location_name')
            ->orderBy('i.id')->orderBy('p.id')->paginate($data['per_page'] ?? 10);
        $page->through(function ($row) { $row->dimensions = json_decode($row->dimensions, true, 512, JSON_THROW_ON_ERROR); return $row; });
        return response()->json([...$page->toArray(), 'categories' => $categories]);
    }

    public function transfer(Request $request, int $id)
    {
        $row = $this->transferCommand($request, 'dispatch', $id, ['inventory.physical.manage']);
        return response()->json(['message' => ($row['status'] ?? '') === 'IN_TRANSIT' ? '实物已调出，待目标仓接收。' : '实物同仓移库已过账。', 'data' => $row]);
    }

    public function transfers(Request $request, PhysicalInventoryApplicationService $service)
    {
        $user = $this->authorizeTransfer($request, ['inventory.physical.manage', 'inventory.transfer.view', 'inventory.inbound.confirm']);
        $filters = $request->validate(['status' => 'nullable|in:IN_TRANSIT,POSTED,in_transit,posted', 'has_exception' => 'nullable|boolean',
            'warehouse_id' => 'nullable|integer|min:1', 'keyword' => 'nullable|string|max:120', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($service->paginateTransfers($filters, $user));
    }

    public function showTransfer(Request $request, int $id, PhysicalInventoryApplicationService $service)
    {
        $user = $this->authorizeTransfer($request, ['inventory.physical.manage', 'inventory.transfer.view', 'inventory.inbound.confirm']);
        return response()->json(['data' => $service->transferDetail($id, $user)]);
    }

    public function confirmTransfer(Request $request, int $id)
    {
        return response()->json(['message' => '实物调拨已整单接收。', 'data' => $this->transferCommand($request, 'confirm', $id, ['inventory.inbound.confirm'])]);
    }

    public function transferException(Request $request, int $id)
    {
        return response()->json(['message' => '实物接收异常已记录，仍保持在途。',
            'data' => $this->transferCommand($request, 'exception', $id, ['inventory.inbound.confirm', 'inventory.physical.manage'])]);
    }

    private function transferCommand(Request $request, string $action, int $id, array $permissions): array
    {
        $user = $this->authorizeTransfer($request, $permissions);
        $request->validate(['client_command_id' => 'required|string|max:120']);
        $auth = app(AuthContextService::class);
        return app(\App\Services\Erp\WarehouseCommandService::class)->run('material_physical_transfer.'.$action, $id,
            $request->input('client_command_id'), $request->except('client_command_id'), $user, $auth->permissionCodes($user), $auth->isSuperAdmin($user))['result'];
    }

    private function authorizeTransfer(Request $request, array $permissions): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless($auth->isSuperAdmin($user) || array_intersect($permissions, $auth->permissionCodes($user)), 403, '没有对应实物调拨权限。');
        return $user;
    }

    public function dispose(Request $request, int $id, PhysicalInventoryApplicationService $service)
    {
        $user = $this->authorizePhysicalAction($request);
        $payload = $request->validate([
            'client_command_id' => 'required|string|max:120',
            'expected_version' => 'required|integer|min:1',
            'reason' => 'required|string|max:1000',
        ]);
        return response()->json(['message' => '仓库实物材料已报废出库', 'data' => $service->dispose($id, $payload, $user)]);
    }

    private function authorizePhysicalAction(Request $request): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless(
            $auth->isSuperAdmin($user) || in_array('inventory.physical.manage', $auth->permissionCodes($user), true),
            403,
            '无按钮权限：inventory.physical.manage',
        );
        return $user;
    }
}
