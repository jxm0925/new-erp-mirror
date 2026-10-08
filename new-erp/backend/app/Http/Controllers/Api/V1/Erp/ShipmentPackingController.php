<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\SalesShipment;
use App\Models\Erp\ShipmentPackingOperation;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\ItemManagementScopeService;
use App\Services\Erp\SalesOrderVisibilityService;
use App\Services\Erp\ShipmentPackingApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipmentPackingController extends Controller
{
    public function commandResult(Request $request)
    {
        $auth = app(AuthContextService::class); $actor = $auth->currentUser($request);
        abort_unless($actor, 401, '未登录或登录已过期。');
        $request->validate(['client_command_id' => 'required|string|max:100']);
        $row = DB::table('erp_shipment_packing_commands')->where('operator_legacy_id', $actor->legacy_id)
            ->where('client_command_id', $request->input('client_command_id'))->first();
        return response()->json(['data' => ['status' => $row ? 'SUCCEEDED' : 'NOT_FOUND', 'response' => $row ? json_decode($row->response, true) : null]]);
    }
    public function show(Request $request, int $id, ShipmentPackingApplicationService $service)
    {
        $actor = $this->authorize($request, 'sales_order.shipment.packing.view');
        $shipment = $this->visibleShipment($id, $actor);
        $operations = $shipment->packingOperations()->orderBy('package_id')->orderBy('sequence')->paginate($this->pageSize($request));
        $operations->setCollection($operations->getCollection()->map(fn ($op) => $service->projection($op, (int) $actor->legacy_id)));
        $packages = $shipment->packages()->orderBy('id')->paginate($this->pageSize($request), ['*'], 'packages_page');
        $packages->setCollection($packages->getCollection()->map(fn ($row) => [
            'id' => (int) $row->id, 'package_no' => $row->package_no, 'package_status' => $row->package_status,
            'carrier_name' => $row->carrier_name, 'tracking_no' => $row->tracking_no,
            'weight' => $row->weight, 'volume' => $row->volume,
            'contents' => $row->packingContents()->get()->map(fn ($content) => ['shipment_line_id' => $content->shipment_line_id,
                'base_qty' => $content->base_qty, 'packaging_scheme_id' => $content->packaging_scheme_id,
                'inventory_serial_ids' => $content->serial_snapshot['inventory_serial_ids'] ?? []])->all(),
        ]));
        $plan = $request->boolean('plan') ? $shipment->packages()->orderBy('id')->get()->map(fn ($row) => [
            'package_no' => $row->package_no, 'carrier_name' => $row->carrier_name, 'tracking_no' => $row->tracking_no,
            'weight' => $row->weight, 'volume' => $row->volume,
            'contents' => $row->packingContents()->get()->map(fn ($content) => ['shipment_line_id' => (int) $content->shipment_line_id,
                'base_qty' => (float) $content->base_qty, 'packaging_scheme_id' => $content->packaging_scheme_id,
                'inventory_serial_ids' => $content->serial_snapshot['inventory_serial_ids'] ?? []])->all(),
        ])->all() : null;
        return response()->json(['data' => ['shipment_id' => (int) $shipment->id, 'shipment_no' => $shipment->shipment_no,
            'packing_version' => (int) $shipment->packing_version, 'requirements' => $service->requirements($shipment),
            'operations' => $operations, 'packages' => $packages, 'plan_packages' => $plan]]);
    }

    public function configure(Request $request, int $id, ShipmentPackingApplicationService $service)
    {
        $actor = $this->authorize($request, 'sales_order.shipment.packing.configure');
        $this->visibleShipment($id, $actor);
        $data = $request->validate([
            'expected_version' => 'required|integer|min:1', 'client_command_id' => 'required|string|max:100',
            'packages' => 'required|array|min:1|max:100', 'packages.*.package_no' => 'nullable|string|max:80',
            'packages.*.carrier_name' => 'nullable|string|max:120', 'packages.*.tracking_no' => 'nullable|string|max:120',
            'packages.*.weight' => 'nullable|numeric|min:0', 'packages.*.volume' => 'nullable|numeric|min:0',
            'packages.*.freight_amount' => 'nullable|numeric|min:0', 'packages.*.contents' => 'required|array|min:1|max:200',
            'packages.*.contents.*.shipment_line_id' => 'required|integer',
            'packages.*.contents.*.base_qty' => 'required|numeric|min:0.00000001',
            'packages.*.contents.*.packaging_scheme_id' => 'nullable|integer',
            'packages.*.contents.*.inventory_serial_ids' => 'nullable|array',
            'packages.*.contents.*.inventory_serial_ids.*' => 'integer',
        ]);
        return response()->json(['message' => '包装方案已保存，工序已生成', 'data' => $service->configure($id, $data, $actor)]);
    }

    public function operations(Request $request, ShipmentPackingApplicationService $service)
    {
        $actor = $this->authorizeExecutionRead($request);
        $query = ShipmentPackingOperation::query()->whereHas('shipment', fn ($q) => $q->whereIn('shipment_status', ['draft', 'pending_outbound', 'outbound_posted']))
            ->when($request->filled('shipment_id'), fn ($q) => $q->where('shipment_id', $request->integer('shipment_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('keyword'), fn ($q) => $q->where('operation_name_snapshot', 'like', '%'.trim((string) $request->input('keyword')).'%'));
        $this->applyExecutionScope($query, $actor);
        $page = $query->orderBy('shipment_id')->orderBy('package_id')->orderBy('sequence')->paginate($this->pageSize($request));
        $page->setCollection($page->getCollection()->map(fn ($op) => $service->projection($op, (int) $actor->legacy_id)));
        return response()->json(['data' => $page]);
    }

    public function operation(Request $request, int $id, ShipmentPackingApplicationService $service)
    {
        $actor = $this->authorizeExecutionRead($request);
        return response()->json(['data' => $service->projection($this->visibleOperation($id, $actor), (int) $actor->legacy_id)]);
    }

    public function action(Request $request, int $id, ShipmentPackingApplicationService $service)
    {
        $actor = $this->authorize($request, $request->input('action') === 'inspect'
            ? 'sales_order.shipment.packing.quality' : 'sales_order.shipment.packing.execute');
        $this->visibleOperation($id, $actor);
        $data = $request->validate(['action' => 'required|in:claim,collaborators,start,pause,materials,complete,inspect',
            'expected_version' => 'required|integer|min:1', 'client_command_id' => 'required|string|max:100',
            'employee_legacy_ids' => 'nullable|array|max:100', 'employee_legacy_ids.*' => 'integer|distinct',
            'completed_base_qty' => 'required_if:action,complete|numeric|min:0.00000001',
            'materials' => 'required_if:action,materials|array|min:1|max:100',
            'materials.*.inventory_balance_id' => 'required|integer', 'materials.*.base_qty' => 'required|numeric|min:0.00000001',
            'materials.*.inventory_serial_ids' => 'nullable|array', 'materials.*.inventory_serial_ids.*' => 'integer|distinct',
            'materials.*.physical_material_ids' => 'nullable|array', 'materials.*.physical_material_ids.*' => 'integer|distinct']);
        if ($data['action'] === 'inspect') $data += $request->validate(['result' => 'required|in:passed,failed', 'reason' => 'nullable|string|max:500']);
        return response()->json(['message' => '包装工序已更新', 'data' => $service->action($id, $data['action'], $data, $actor)]);
    }

    public function people(Request $request, int $id)
    {
        $actor = $this->authorize($request, 'sales_order.shipment.packing.execute');
        $op = $this->visibleOperation($id, $actor);
        abort_unless((int) $op->owner_legacy_id === (int) $actor->legacy_id, 403, '只有接单人可以选择协同人。');
        $auth = app(AuthContextService::class);
        $query = DB::table('erp_legacy_admin_users')->whereIn(DB::raw('LOWER(status)'), ['normal', 'active'])
            ->where('legacy_id', '!=', $actor->legacy_id)
            ->when($request->filled('department_id'), fn ($q) => $q->whereIn('legacy_id', DB::table('erp_department_users')->where('department_legacy_id', $request->integer('department_id'))->select('user_legacy_id')))
            ->when($request->filled('keyword'), fn ($q) => $q->where(fn ($w) => $w->where('username', 'like', '%'.$request->input('keyword').'%')->orWhere('nickname', 'like', '%'.$request->input('keyword').'%')))
            ->orderBy('legacy_id');
        // Pagination stays server-side; candidates lacking execution permission remain excluded
        // by the same authoritative permission query used when the owner saves the selection.
        $ids = DB::table('erp_rbac_user_roles as ur')->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.enabled', true)->where('p.enabled', true)->where('p.code', 'sales_order.shipment.packing.execute')->select('ur.user_legacy_id');
        $query->where(fn ($q) => $q->whereIn('legacy_id', $ids)->orWhere('username', 'admin'));
        return response()->json(['data' => $query->paginate($this->pageSize($request), ['legacy_id', 'username', 'nickname']),
            'departments' => $request->boolean('include_departments') ? DB::table('erp_departments')->whereNull('deleted_at')->orderBy('sort')->get(['legacy_id as id', 'parent_legacy_id as parent_id', 'name']) : null]);
    }

    public function materialSources(Request $request, int $id)
    {
        $actor = $this->authorize($request, 'sales_order.shipment.packing.execute');
        $op = $this->visibleOperation($id, $actor);
        abort_unless((int) $op->owner_legacy_id === (int) $actor->legacy_id, 403, '只有接单人可以登记实际用料。');
        $itemIds = collect($op->packaging_materials_snapshot ?? [])->pluck('component_item_id');
        $query = InventoryBalance::query()->with(['item', 'warehouse', 'location', 'unit'])
            ->whereIn('item_id', $itemIds)->where('quantity_available', '>', 0)
            ->whereHas('item', fn ($q) => app(ItemManagementScopeService::class)->applyScope($q, 'factory')->where('status', 'enabled'))
            ->whereHas('warehouse', fn ($q) => $q->whereIn('status', ['enabled', 'active']))
            ->whereHas('location', fn ($q) => $q->whereIn('status', ['enabled', 'active']))
            ->when($request->filled('category_id'), fn ($q) => $q->whereHas('item', fn ($w) => $w->where('category_id', $request->integer('category_id'))))
            ->when($request->filled('keyword'), fn ($q) => $q->whereHas('item', fn ($w) => $w->where(fn ($n) => $n->where('item_code', 'like', '%'.$request->input('keyword').'%')->orWhere('item_name', 'like', '%'.$request->input('keyword').'%')->orWhere('spec', 'like', '%'.$request->input('keyword').'%'))));
        $page = $query->orderBy('id')->paginate($this->pageSize($request));
        $page->setCollection($page->getCollection()->map(fn ($balance) => ['id' => (int) $balance->id,
            'item_id' => (int) $balance->item_id, 'item_code' => $balance->item?->item_code, 'item_name' => $balance->item?->item_name,
            'spec' => $balance->item?->spec, 'unit_name' => $balance->unit?->unit_name,
            'warehouse_name' => $balance->warehouse?->warehouse_name, 'location_name' => $balance->location?->location_name,
            'batch_no' => $balance->batch_no, 'quantity_available' => $balance->quantity_available,
            'physical' => $balance->item?->materialManagementMode() === 'physical',
            'serial_tracking_mode' => $balance->item?->serialTrackingMode() ?? 'none']));
        return response()->json(['data' => $page,
            'categories' => $request->boolean('include_categories') ? DB::table('erp_item_categories')->where('management_scope', 'factory')->orderBy('sort_order')->get(['id', 'parent_id', 'category_name']) : null]);
    }

    public function materialIdentities(Request $request, int $id)
    {
        $actor = $this->authorize($request, 'sales_order.shipment.packing.execute');
        $op = $this->visibleOperation($id, $actor);
        abort_unless((int) $op->owner_legacy_id === (int) $actor->legacy_id, 403);
        $balance = InventoryBalance::query()->whereIn('item_id', collect($op->packaging_materials_snapshot ?? [])->pluck('component_item_id'))
            ->whereHas('item', fn ($q) => app(ItemManagementScopeService::class)->applyScope($q, 'factory'))
            ->findOrFail($request->integer('inventory_balance_id'));
        $physical = $balance->item?->materialManagementMode() === 'physical';
        $query = DB::table($physical ? 'erp_material_physicals' : 'erp_inventory_serials')->where('inventory_balance_id', $balance->id);
        $physical ? $query->where('status', 'AVAILABLE') : $query->where('serial_status', 'available');
        if ($request->filled('keyword')) $query->where($physical ? 'physical_no' : 'serial_no', 'like', '%'.trim((string) $request->input('keyword')).'%');
        return response()->json(['data' => $query->orderBy('id')->paginate($this->pageSize($request), $physical
            ? ['id', 'physical_no', 'length_mm', 'width_mm', 'thickness_mm'] : ['id', 'serial_no'])]);
    }

    private function visibleShipment(int $id, object $actor): SalesShipment
    {
        return SalesShipment::query()->whereHas('order', fn ($q) => app(SalesOrderVisibilityService::class)->apply($q, $actor))->findOrFail($id);
    }

    private function visibleOperation(int $id, object $actor): ShipmentPackingOperation
    {
        $query = ShipmentPackingOperation::query();
        $this->applyExecutionScope($query, $actor);
        return $query->findOrFail($id);
    }

    private function applyExecutionScope($query, object $actor): void
    {
        $auth = app(AuthContextService::class);
        if ($auth->isSuperAdmin($actor) || $auth->dataScope($actor) === 'all') return;
        // Public ready work is claimable by an authorized worker. Claimed work and its
        // collaboration details are visible only to selected participants in self scope.
        $query->where(fn ($q) => $q->where(fn ($ready) => $ready->where('status', 'READY')->whereNull('owner_legacy_id'))
            ->orWhereHas('participants', fn ($p) => $p->where('employee_legacy_id', $actor->legacy_id))
            ->orWhere('inspected_by_legacy_id', $actor->legacy_id)
            ->when(in_array('sales_order.shipment.packing.quality', $auth->permissionCodes($actor), true), fn ($quality) => $quality->orWhere('status', 'WAIT_QUALITY')));
    }

    private function authorizeExecutionRead(Request $request): object
    {
        $auth = app(AuthContextService::class); $actor = $auth->currentUser($request);
        abort_unless($actor, 401, '未登录或登录已过期。');
        abort_unless($auth->isSuperAdmin($actor) || array_intersect(['sales_order.shipment.packing.execute', 'sales_order.shipment.packing.quality'], $auth->permissionCodes($actor)), 403, '没有包装作业查看权限。');
        return $actor;
    }

    private function authorize(Request $request, string $code): object
    {
        $auth = app(AuthContextService::class); $actor = $auth->currentUser($request);
        abort_unless($actor, 401, '未登录或登录已过期。');
        abort_unless($auth->isSuperAdmin($actor) || in_array($code, $auth->permissionCodes($actor), true), 403, '无按钮权限：'.$code);
        return $actor;
    }
    private function pageSize(Request $request): int { return min(max($request->integer('per_page', 20), 1), 100); }
}
