<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\{AuthContextService, ProductionFinancialProjectionService, ProductionMaterialExecutionService, ProductionMaterialProcurementApplicationService, PublicMaterialPreparationApplicationService};
use Illuminate\Http\Request;

final class PublicMaterialPreparationController extends Controller
{
    public function index(Request $request, PublicMaterialPreparationApplicationService $service)
    { return response()->json($service->paginate($this->filters($request), ...$this->context($request))); }

    public function show(Request $request, int $id, PublicMaterialPreparationApplicationService $service)
    { return response()->json(['data' => $service->show($id, $this->filters($request), ...$this->context($request))]); }

    public function store(Request $request, PublicMaterialPreparationApplicationService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'warehouse_id' => 'required|integer|min:1',
            'work_order_versions' => 'required|array', 'work_order_versions.*' => 'required|integer|min:1', 'remark' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:100', 'lines.*.target_material_requirement_id' => 'required|integer|min:1',
            'lines.*.inventory_balance_id' => 'required|integer|min:1', 'lines.*.planned_pick_qty' => 'required|numeric|gt:0',
            'lines.*.planned_cut_piece_qty' => 'nullable|integer|min:1|max:999999999',
            'lines.*.planned_cut_total_length_mm' => 'nullable|numeric|decimal:0,5|gt:0',
            'lines.*.cutting_members' => 'nullable|array|min:1|max:100',
            'lines.*.cutting_members.*.target_material_requirement_id' => 'required|integer|min:1',
            'lines.*.cutting_members.*.planned_cut_piece_qty' => 'required|integer|min:1|max:999999999',
            'lines.*.cutting_source_plan' => 'nullable|array',
            'lines.*.cutting_source_plan.version' => 'required_with:lines.*.cutting_source_plan|integer|in:1',
            'lines.*.cutting_source_plan.source_fingerprint' => 'nullable|string|size:64',
            'lines.*.cutting_source_plan.sources' => 'required_with:lines.*.cutting_source_plan|array|min:1|max:1000',
            'lines.*.cutting_source_plan.sources.*.physical_material_id' => 'required|integer|min:1',
            'lines.*.cutting_source_plan.sources.*.expected_physical_version' => 'required|integer|min:1',
            'lines.*.cutting_source_plan.sources.*.members' => 'required|array|min:1|max:100',
            'lines.*.cutting_source_plan.sources.*.members.*.target_material_requirement_id' => 'required|integer|min:1',
            'lines.*.cutting_source_plan.sources.*.members.*.planned_cut_piece_qty' => 'required|integer|min:1|max:100000',
            'lines.*.physical_material_ids' => 'nullable|array|max:1000', 'lines.*.physical_material_ids.*' => 'integer|min:1|distinct']);
        return response()->json(['message' => '公共配料任务已创建。', 'data' => $service->create($payload, ...$this->context($request))], 201);
    }

    public function transition(Request $request, int $id, string $action, PublicMaterialPreparationApplicationService $service)
    {
        $rules = ['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'child_versions' => 'required|array', 'child_versions.*' => 'required|integer|min:1', 'reason' => ($action === 'cancel' ? 'required' : 'nullable').'|string|max:500'];
        if ($action === 'assign') $rules['assigned_picker_legacy_id'] = 'required|integer|min:1';
        if ($action === 'confirm') $rules = [...$rules, 'lines' => 'required|array|min:1|max:100',
            'lines.*.picking_task_line_id' => 'required|integer|min:1|distinct', 'lines.*.actual_pick_qty' => 'required|numeric|min:0',
            'lines.*.serial_ids' => 'nullable|array|max:1000', 'lines.*.serial_ids.*' => 'integer|min:1|distinct',
            'lines.*.physical_material_ids' => 'nullable|array|max:1000', 'lines.*.physical_material_ids.*' => 'integer|min:1|distinct'];
        return response()->json(['message' => '公共配料任务已更新。', 'data' => $service->transition($id, $action, $request->validate($rules), ...$this->context($request))]);
    }

    public function labor(Request $request, int $id, string $action, \App\Services\Erp\WarehouseLaborSessionService $service)
    {
        abort_if(array_diff(array_keys($request->all()), ['client_command_id', 'expected_version']), 422, '本人计时只能提交操作标识与当前作业版本。');
        $body = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1']);
        return response()->json(['data' => $service->command('public_preparation', $id, $action, $body, ...$this->context($request))]);
    }

    public function procurementOptions(Request $request, string $kind, ProductionMaterialProcurementApplicationService $service)
    {
        $result = $service->options($kind, $this->filters($request), ...$this->context($request));
        return response()->json($result);
    }

    public function procure(Request $request, ProductionMaterialProcurementApplicationService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'sales_order_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|exists:erp_warehouses,id', 'expected_date' => 'nullable|date', 'remark' => 'required|string|max:2000',
            'items' => 'required|array|min:1|max:100', 'items.*.item_id' => 'required|integer|min:1|distinct',
            'items.*.preparation_material_requirement_id' => 'nullable|integer|min:1',
            'items.*.preparation_version' => 'nullable|integer|min:1', 'items.*.work_order_version' => 'nullable|integer|min:1',
            'items.*.target_material_requirement_id' => 'nullable|integer|min:1', 'items.*.request_qty' => 'required|numeric|gt:0|decimal:0,4', 'items.*.remark' => 'nullable|string|max:500']);
        return response()->json(['message' => '采购需求已提交，可进入采购计划。', 'data' => $service->create($payload, ...$this->context($request))], 201);
    }

    public function onsite(Request $request, ProductionMaterialExecutionService $service)
    {
        $filters = [...$this->filters($request), ...$request->validate(['production_target_type' => 'nullable|in:unit_operation,quantity_operation',
            'production_target_id' => 'nullable|integer|min:1', 'work_order_id' => 'nullable|integer|min:1'])];
        return response()->json(app(ProductionFinancialProjectionService::class)->redact($service->onsiteCollections($filters, ...$this->context($request))));
    }

    public function receiveOnsite(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1', 'remark' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:100', 'lines.*.picking_task_line_id' => 'required|integer|min:1|distinct', 'lines.*.accepted_qty' => 'required|numeric|gt:0',
            'lines.*.physical_material_ids' => 'nullable|array|max:1000', 'lines.*.physical_material_ids.*' => 'integer|min:1|distinct',
            'lines.*.accepted_serial_ids' => 'nullable|array|max:1000', 'lines.*.accepted_serial_ids.*' => 'integer|min:1|distinct']);
        return response()->json(['message' => '现场领料已确认。', 'data' => app(ProductionFinancialProjectionService::class)->redact($service->receiveOnsite($id, $payload, ...$this->context($request)))], 201);
    }

    public function onsiteSources(Request $request, string $kind, ProductionMaterialExecutionService $service)
    {
        $filters = [...$this->filters($request), ...$request->validate(['picking_task_line_id' => 'required|integer|min:1'])];
        return response()->json(app(ProductionFinancialProjectionService::class)->redact($service->onsiteSources($kind, $filters, ...$this->context($request))));
    }

    private function filters(Request $request): array
    { return $request->validate(['keyword' => 'nullable|string|max:160', 'status' => 'nullable|string|max:30', 'category_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
        'labor_page' => 'nullable|integer|min:1', 'labor_per_page' => 'nullable|integer|in:10']); }

    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录ERP。', 401);
        return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)];
    }
}
