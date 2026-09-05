<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionMasterDataService;
use Illuminate\Http\Request;

class ProductionMasterDataController extends Controller
{
    public function operations(Request $request, ProductionMasterDataService $service) { return response()->json($service->operations($this->filters($request), ...$this->context($request))); }
    public function operation(Request $request, int $id, ProductionMasterDataService $service) { return response()->json(['data' => $service->operation($id, ...$this->context($request))]); }
    public function storeOperation(Request $request, ProductionMasterDataService $service) { return response()->json(['message' => '工序已新增。', 'data' => $service->createOperation($this->operationData($request, false), $this->user($request), ...$this->context($request))], 201); }
    public function updateOperation(Request $request, int $id, ProductionMasterDataService $service) { return response()->json(['message' => '工序已保存。', 'data' => $service->updateOperation($id, $this->operationData($request, true), $this->user($request), ...$this->context($request))]); }
    public function enableOperation(Request $request, int $id, ProductionMasterDataService $service) { return response()->json(['message' => '工序已启用。', 'data' => $service->setOperationEnabled($id, true, $this->stateData($request), $this->user($request), ...$this->context($request))]); }
    public function disableOperation(Request $request, int $id, ProductionMasterDataService $service) { return response()->json(['message' => '工序已停用。', 'data' => $service->setOperationEnabled($id, false, $this->stateData($request), $this->user($request), ...$this->context($request))]); }
    public function routings(Request $request, ProductionMasterDataService $service) { return response()->json($service->routings($this->filters($request), ...$this->context($request))); }
    public function routing(Request $request, int $id, ProductionMasterDataService $service) { return response()->json(['data' => $service->routing($id, ...$this->context($request))]); }
    public function storeRouting(Request $request, ProductionMasterDataService $service) { return response()->json(['message' => '工艺路线草稿已新增。', 'data' => $service->createRouting($this->routingData($request, false), $this->user($request), ...$this->context($request))], 201); }
    public function updateRouting(Request $request, int $id, ProductionMasterDataService $service) { return response()->json(['message' => '工艺路线已保存。', 'data' => $service->updateRouting($id, $this->routingData($request, true), $this->user($request), ...$this->context($request))]); }
    public function activateRouting(Request $request, int $id, ProductionMasterDataService $service) { return response()->json(['message' => '工艺路线已生效。', 'data' => $service->activateRouting($id, $this->stateData($request), $this->user($request), ...$this->context($request))]); }
    public function setDefaultRouting(Request $request, int $id, ProductionMasterDataService $service) { return response()->json(['message' => '默认工艺路线已更新。', 'data' => $service->setDefaultRouting($id, $this->stateData($request), $this->user($request), ...$this->context($request))]); }
    public function copyRouting(Request $request, int $id, ProductionMasterDataService $service) { return response()->json(['message' => '已复制为新版本。', 'data' => $service->copyRouting($id, $request->validate(['client_command_id' => 'required|string|max:120']), $this->user($request), ...$this->context($request))], 201); }
    public function retireRouting(Request $request, int $id, ProductionMasterDataService $service) { return response()->json(['message' => '工艺路线已退役。', 'data' => $service->retireRouting($id, $this->stateData($request), $this->user($request), ...$this->context($request))]); }
    public function selector(Request $request, string $type, ProductionMasterDataService $service) { return response()->json($service->selector($type, $request->validate(['keyword' => 'nullable|string|max:160', 'output_item_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']), ...$this->context($request))); }

    private function context(Request $request): array { $auth = app(AuthContextService::class); $user = $this->user($request); return [$auth->permissionCodes($user), $auth->isSuperAdmin($user)]; }
    private function user(Request $request): object { $user = app(AuthContextService::class)->currentUser($request); abort_unless($user, 401, '请先登录 ERP。'); return $user; }
    private function filters(Request $request): array { return $request->validate(['keyword' => 'nullable|string|max:160', 'status' => 'nullable|string|max:20', 'reference_status' => 'nullable|in:referenced,unreferenced', 'output_item_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1']); }
    private function stateData(Request $request): array { return $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1']); }
    private function operationData(Request $request, bool $editing): array { return $request->validate([
        'client_command_id' => 'required|string|max:120', 'creation_session_id' => $editing ? 'prohibited' : 'required|uuid', 'reservation_token' => $editing ? 'prohibited' : 'required|uuid',
        'operation_name' => ($editing ? 'sometimes' : 'required').'|string|max:160', 'status' => ($editing ? 'sometimes' : 'nullable').'|in:enabled,disabled',
        'sort' => 'nullable|integer|min:0|max:999999', 'description' => 'nullable|string|max:2000', 'expected_version' => $editing ? 'required|integer|min:1' : 'prohibited',
    ]); }
    private function routingData(Request $request, bool $editing): array { return $request->validate([
        'client_command_id' => 'required|string|max:120', 'creation_session_id' => $editing ? 'prohibited' : 'required|uuid', 'reservation_token' => $editing ? 'prohibited' : 'required|uuid',
        'routing_name' => ($editing ? 'sometimes' : 'required').'|string|max:160', 'output_item_id' => ($editing ? 'sometimes' : 'required').'|integer|exists:erp_items,id',
        'product_id' => 'nullable|integer|exists:erp_products,id', 'sku_id' => 'nullable|integer|exists:erp_skus,id', 'version' => 'prohibited', 'remark' => 'nullable|string|max:2000',
        'operations' => ($editing ? 'sometimes' : 'required').'|array|min:1', 'operations.*.operation_id' => 'required|integer|exists:erp_production_operations,id',
        'operations.*.sequence' => 'required|integer|min:1|max:999999', 'operations.*.parameters' => 'nullable|array', 'operations.*.is_key_operation' => 'nullable|boolean', 'operations.*.remark' => 'nullable|string|max:500',
        'operations.*.standard_minutes' => 'nullable|numeric|min:0|max:999999',
        'operations.*.setup_standard_minutes' => 'nullable|numeric|min:0|max:999999',
        'operations.*.unit_standard_minutes' => 'nullable|numeric|min:0|max:999999',
        'operations.*.output_item_id' => 'nullable|integer|exists:erp_items,id',
        'operations.*.output_mode' => 'nullable|in:flow_only,warehouse_optional,warehouse_required', 'operations.*.quality_mode' => 'nullable|in:none,required',
        'operations.*.allow_continue_without_warehouse' => 'nullable|boolean', 'operations.*.material_supply_rules' => 'nullable|array',
        'operations.*.material_supply_rules.*.component_item_id' => 'required|integer|exists:erp_items,id',
        'operations.*.material_supply_rules.*.target_sequence' => 'required|integer|min:1|max:999999',
        'operations.*.material_supply_rules.*.required_qty_ratio' => 'nullable|numeric|gt:0|max:1',
        'operations.*.material_supply_rules.*.supply_mode' => 'required|in:dedicated_delivery,workstation_stock,no_per_order_delivery',
        'operations.*.material_supply_rules.*.requires_delivery' => 'nullable|boolean', 'operations.*.material_supply_rules.*.participates_in_kitting' => 'nullable|boolean',
        'operations.*.material_supply_rules.*.allow_partial_delivery' => 'nullable|boolean', 'operations.*.material_supply_rules.*.delivery_location_type' => 'nullable|in:operation_station,production_line,workshop',
        'expected_version' => $editing ? 'required|integer|min:1' : 'prohibited',
    ]); }
}
