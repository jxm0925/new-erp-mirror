<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionFinancialProjectionService;
use App\Services\Erp\ProductionMaterialExecutionService;
use App\Services\Erp\ProductionPickingWorkspaceService;
use Illuminate\Http\Request;

class ProductionMaterialExecutionController extends Controller
{
    public function pickingWorkspace(Request $request, string $action, ProductionPickingWorkspaceService $service)
    {
        $filters = $request->validate([
            'keyword' => ['nullable', 'string', 'max:160'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'work_order_id' => ['nullable', 'integer', 'min:1'],
            'target_material_requirement_id' => [in_array($action, ['sources', 'warehouse-physicals'], true) || $request->input('kind') === 'categories' ? 'required' : 'nullable', 'integer', 'min:1'],
            'inventory_balance_id' => [$action === 'warehouse-physicals' ? 'required' : 'nullable', 'integer', 'min:1'],
            'target_material_requirement_ids' => ['nullable', 'array', 'min:1', 'max:100'],
            'target_material_requirement_ids.*' => ['integer', 'min:1', 'distinct'],
            'warehouse_id' => [$action === 'sources' ? 'required' : 'nullable', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer', 'min:1'], 'category_id' => ['nullable', 'integer', 'min:1'],
            'kind' => [$action === 'options' ? 'required' : 'nullable', 'in:warehouses,people,categories,departments,locations'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'ids' => ['nullable', 'array', 'max:100'], 'ids.*' => ['integer', 'min:1'],
            'picking_task_id' => [in_array($action, ['serials', 'physicals'], true) ? 'required' : 'nullable', 'integer', 'min:1'],
            'picking_task_line_id' => [in_array($action, ['serials', 'physicals'], true) ? 'required' : 'nullable', 'integer', 'min:1'],
            'source_delivery_id' => ['nullable', 'integer', 'min:1'],
            'delivery_id' => [$action === 'receipt-serials' ? 'required' : 'nullable', 'integer', 'min:1'],
            'delivery_line_id' => [$action === 'receipt-serials' ? 'required' : 'nullable', 'integer', 'min:1'],
        ]);
        $method = match ($action) { 'receipt-serials' => 'receiptSerials', 'warehouse-physicals' => 'warehousePhysicals', default => $action };
        $context = $this->context($request);
        $result = $service->{$method}($filters, ...$context);
        return response()->json($action === 'receipt-serials' ? $this->receiptProjection($result, $context) : $result);
    }

    public function cuttingPreview(Request $request, ProductionPickingWorkspaceService $service)
    {
        $payload = $request->validate([
            'target_material_requirement_id' => 'required|integer|min:1', 'target_material_requirement_ids' => 'nullable|array|min:1|max:100',
            'target_material_requirement_ids.*' => 'integer|min:1|distinct', 'inventory_balance_id' => 'required|integer|min:1',
            'physical_material_ids' => 'required|array|min:1|max:1000', 'physical_material_ids.*' => 'integer|min:1|distinct',
            'cutting_members' => 'required|array|min:1|max:100', 'cutting_members.*.target_material_requirement_id' => 'required|integer|min:1|distinct',
            'cutting_members.*.planned_cut_piece_qty' => 'required|integer|min:1|max:100000',
        ] + $this->sourcePlanRules('cutting_source_plan'));
        return response()->json($service->cuttingPreview($payload, ...$this->context($request)));
    }

    private function sourcePlanRules(string $key): array
    {
        return [$key => 'nullable|array', $key.'.version' => 'required_with:'.$key.'|integer|in:1', $key.'.source_fingerprint' => 'nullable|string|size:64',
            $key.'.sources' => 'required_with:'.$key.'|array|min:1|max:1000', $key.'.sources.*.physical_material_id' => 'required|integer|min:1',
            $key.'.sources.*.expected_physical_version' => 'required|integer|min:1', $key.'.sources.*.members' => 'required|array|min:1|max:100',
            $key.'.sources.*.members.*.target_material_requirement_id' => 'required|integer|min:1',
            $key.'.sources.*.members.*.planned_cut_piece_qty' => 'required|integer|min:1|max:100000'];
    }

    public function materialEvents(Request $request, string $type, int $id, ProductionPickingWorkspaceService $service)
    {
        $filters = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $context = $this->context($request);
        $result = $service->events($type, $id, $filters, ...$context);
        return response()->json($type === 'delivery' ? $this->receiptProjection($result, $context) : $result);
    }

    public function preparationDemands(Request $request, ProductionMaterialExecutionService $service)
    {
        $filters = $request->validate([
            'demand_stage' => ['nullable', 'in:preparation,execution'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'component_item_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:40'],
            'work_order_id' => ['nullable', 'integer', 'min:1'],
            'target_routing_operation_id' => ['nullable', 'integer', 'min:1'],
            'production_target_type' => ['nullable', 'in:unit_operation,quantity_operation'],
            'production_target_id' => ['nullable', 'integer', 'min:1'],
            'keyword' => ['nullable', 'string', 'max:160'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return response()->json($service->paginatePreparationDemands($filters, ...$this->context($request)));
    }

    public function pickingTasks(Request $request, ProductionMaterialExecutionService $service)
    {
        return response()->json($service->paginatePickingTasks($this->filters($request, true), ...$this->context($request)));
    }

    public function showPickingTask(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        return response()->json(['data' => $service->showPickingTask($id, ...array_merge($this->context($request), [$this->detailPagination($request)]))]);
    }

    public function pickingLabor(Request $request, int $id, string $action)
    { return $this->warehouseLabor($request, 'picking', $id, $action); }

    public function deliveryLabor(Request $request, int $id, string $action)
    { return $this->warehouseLabor($request, 'delivery', $id, $action); }

    private function warehouseLabor(Request $request, string $type, int $id, string $action)
    {
        abort_if(array_diff(array_keys($request->all()), ['client_command_id', 'expected_version']), 422, '本人计时只能提交操作标识与当前作业版本。');
        $body = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1']);
        return response()->json(['data' => app(\App\Services\Erp\WarehouseLaborSessionService::class)->command($type, $id, $action, $body, ...$this->context($request))]);
    }

    public function createPickingTask(Request $request, ProductionMaterialExecutionService $service)
    {
        $task = $service->createPickingTask($this->pickingPayload($request, true), ...$this->context($request));
        return response()->json(['message' => '配料任务已创建。', 'data' => $task], 201);
    }

    public function assignPickingTask(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        $task = $service->assignPickingTask($id, $this->transitionPayload($request, ['assigned_picker_legacy_id' => ['required', 'integer', 'min:1']]), ...$this->context($request));
        return response()->json(['message' => '拣货人已分配。', 'data' => $task]);
    }

    public function startPickingTask(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        $task = $service->startPickingTask($id, $this->transitionPayload($request), ...$this->context($request));
        return response()->json(['message' => '配料任务已开始拣货。', 'data' => $task]);
    }

    public function confirmPickingTask(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        $payload = $this->transitionPayload($request, [
            'reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.picking_task_line_id' => ['required', 'integer', 'min:1'],
            'lines.*.actual_pick_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.serial_ids' => ['nullable', 'array'],
            'lines.*.serial_ids.*' => ['integer', 'min:1'],
            'lines.*.physical_material_ids' => ['nullable', 'array', 'max:1000'],
            'lines.*.physical_material_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        $task = $service->confirmPickingTask($id, $payload, ...$this->context($request));
        return response()->json(['message' => '拣货已确认并完成正式库存过账。', 'data' => $task]);
    }

    public function cancelPickingTask(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        $task = $service->cancelPickingTask($id, $this->transitionPayload($request, ['reason' => ['required', 'string', 'max:500']]), ...$this->context($request));
        return response()->json(['message' => '配料任务已取消。', 'data' => $task]);
    }

    public function deliveries(Request $request, ProductionMaterialExecutionService $service)
    {
        $context = $this->context($request);
        return response()->json($this->receiptProjection($service->paginateDeliveries($this->filters($request), ...$context), $context));
    }

    public function showDelivery(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        $context = $this->context($request);
        return response()->json(['data' => $this->receiptProjection($service->showDelivery($id, ...array_merge($context, [$this->detailPagination($request)])), $context)]);
    }

    public function createDelivery(Request $request, ProductionMaterialExecutionService $service)
    {
        $payload = $this->transitionPayload($request, [
            'picking_task_id' => ['required', 'integer', 'min:1'],
            'delivery_user_legacy_id' => ['nullable', 'integer', 'min:1'],
            'delivery_type' => ['nullable', 'in:standard,redelivery,supplement,internal_issue'],
            'source_delivery_id' => ['nullable', 'integer', 'exists:erp_material_deliveries,id'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.picking_task_line_id' => ['required', 'integer', 'min:1'],
            'lines.*.delivery_qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.serial_ids' => ['nullable', 'array'],
            'lines.*.serial_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        $delivery = $service->createDelivery($payload, ...$this->context($request));
        return response()->json(['message' => '配送单已创建。', 'data' => $delivery], 201);
    }

    public function dispatchDelivery(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        $delivery = $service->dispatchDelivery($id, $this->transitionPayload($request, ['delivery_user_legacy_id' => ['nullable', 'integer', 'min:1']]), ...$this->context($request));
        return response()->json(['message' => '配送单已发出。', 'data' => $delivery]);
    }

    public function deliverDelivery(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        $delivery = $service->deliverDelivery($id, $this->transitionPayload($request), ...$this->context($request));
        return response()->json(['message' => '配送单已送达。', 'data' => $delivery]);
    }

    public function receiveDelivery(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        $payload = $this->transitionPayload($request, [
            'remark' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.delivery_line_id' => ['required', 'integer', 'min:1'],
            'lines.*.accepted_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.rejected_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.reject_reason' => ['nullable', 'string', 'max:500'],
            'lines.*.accepted_serial_ids' => ['nullable', 'array'],
            'lines.*.accepted_serial_ids.*' => ['integer', 'min:1'],
            'lines.*.rejected_serial_ids' => ['nullable', 'array'],
            'lines.*.rejected_serial_ids.*' => ['integer', 'min:1'],
            'lines.*.rejected_serial_reasons' => ['nullable', 'array', 'max:1000'],
            'lines.*.rejected_serial_reasons.*' => ['string', 'max:500'],
        ]);
        $context = $this->context($request);
        $receipt = $service->receiveDelivery($id, $payload, ...$context);
        return response()->json(['message' => '收料已确认。', 'data' => $this->receiptProjection($receipt, $context)], 201);
    }

    public function cancelDelivery(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        $delivery = $service->cancelDelivery($id, $this->transitionPayload($request, [
            'reason' => ['required', 'string', 'max:500'],
        ]), ...$this->context($request));
        return response()->json(['message' => '待发出配送单已取消。', 'data' => $delivery]);
    }

    public function showReceipt(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        $context = $this->context($request);
        return response()->json(['data' => $this->receiptProjection($service->showReceipt($id, ...$context), $context)]);
    }

    public function workOrderExecution(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        return response()->json(['data' => $service->workOrderExecution($id, ...$this->context($request))]);
    }

    private function receiptProjection(mixed $result, array $context): mixed
    {
        return in_array('production.material_delivery.view', $context[1], true)
            ? $result : app(ProductionFinancialProjectionService::class)->redact($result);
    }

    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)];
    }

    private function transitionPayload(Request $request, array $extra = []): array
    {
        return $request->validate(array_merge([
            'client_command_id' => ['required', 'string', 'max:120'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ], $extra));
    }

    private function pickingPayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'client_command_id' => ['required', 'string', 'max:120'],
            'work_order_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'planned_delivery_at' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.target_material_requirement_id' => ['required_without:lines.*.material_requirement_id', 'integer', 'min:1'],
            'lines.*.material_requirement_id' => ['required_without:lines.*.target_material_requirement_id', 'integer', 'min:1'],
            'lines.*.material_supply_rule_snapshot_id' => ['required_without:lines.*.target_material_requirement_id', 'integer', 'min:1'],
            'lines.*.production_target_type' => ['required_without:lines.*.target_material_requirement_id', 'in:unit_operation,quantity_operation'],
            'lines.*.production_target_id' => ['required_without:lines.*.target_material_requirement_id', 'integer', 'min:1'],
            'lines.*.inventory_balance_id' => ['required', 'integer', 'min:1'],
            'lines.*.planned_pick_qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.planned_cut_piece_qty' => ['nullable', 'integer', 'min:1', 'max:999999999'],
            'lines.*.planned_cut_total_length_mm' => ['nullable', 'numeric', 'decimal:0,5', 'gt:0'],
            'lines.*.cutting_members' => ['nullable', 'array', 'min:1', 'max:100'],
            'lines.*.cutting_members.*.target_material_requirement_id' => ['required', 'integer', 'min:1'],
            'lines.*.cutting_members.*.planned_cut_piece_qty' => ['required', 'integer', 'min:1', 'max:999999999'],
            'lines.*.physical_material_ids' => ['nullable', 'array', 'max:1000'],
            'lines.*.physical_material_ids.*' => ['integer', 'min:1', 'distinct'],
            'lines.*.serial_ids' => ['nullable', 'array'],
            'lines.*.serial_ids.*' => ['integer', 'min:1'],
        ] + $this->sourcePlanRules('lines.*.cutting_source_plan'));
    }

    private function detailPagination(Request $request): array
    {
        if (! $request->has('line_page') && ! $request->has('line_per_page') && ! $request->has('labor_page') && ! $request->has('labor_per_page')) return [];
        $data = $request->validate(['line_page' => 'nullable|integer|min:1', 'line_per_page' => 'nullable|integer|min:1|max:100',
            'labor_page' => 'nullable|integer|min:1', 'labor_per_page' => 'nullable|integer|in:10']);
        $result = ['labor_page' => $data['labor_page'] ?? 1];
        if ($request->has('line_page') || $request->has('line_per_page')) $result += ['page' => $data['line_page'] ?? 1, 'per_page' => $data['line_per_page'] ?? 10];
        return $result;
    }

    private function filters(Request $request, bool $picking = false): array
    {
        return $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'warehouse_id' => [$picking ? 'nullable' : 'prohibited', 'integer', 'min:1'],
            'keyword' => ['nullable', 'string', 'max:160'],
            'status_group' => ['nullable', 'in:'.($picking ? 'active' : 'pending_dispatch')],
            'page' => ['nullable', 'integer', 'min:1'],
            'work_order_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }
}
