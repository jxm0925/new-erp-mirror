<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
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
            'target_material_requirement_id' => [$action === 'sources' || $request->input('kind') === 'categories' ? 'required' : 'nullable', 'integer', 'min:1'],
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
        $method = $action === 'receipt-serials' ? 'receiptSerials' : $action;
        return response()->json($service->{$method}($filters, ...$this->context($request)));
    }

    public function materialEvents(Request $request, string $type, int $id, ProductionPickingWorkspaceService $service)
    {
        $filters = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        return response()->json($service->events($type, $id, $filters, ...$this->context($request)));
    }

    public function preparationDemands(Request $request, ProductionMaterialExecutionService $service)
    {
        $filters = $request->validate([
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
        return response()->json($service->paginateDeliveries($this->filters($request), ...$this->context($request)));
    }

    public function showDelivery(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        return response()->json(['data' => $service->showDelivery($id, ...array_merge($this->context($request), [$this->detailPagination($request)]))]);
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
        $receipt = $service->receiveDelivery($id, $payload, ...$this->context($request));
        return response()->json(['message' => '收料已确认。', 'data' => $receipt], 201);
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
        return response()->json(['data' => $service->showReceipt($id, ...$this->context($request))]);
    }

    public function workOrderExecution(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        return response()->json(['data' => $service->workOrderExecution($id, ...$this->context($request))]);
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
            'lines.*.serial_ids' => ['nullable', 'array'],
            'lines.*.serial_ids.*' => ['integer', 'min:1'],
        ]);
    }

    private function detailPagination(Request $request): array
    {
        if (! $request->has('line_page')) return [];
        $data = $request->validate(['line_page' => 'required|integer|min:1', 'line_per_page' => 'nullable|integer|min:1|max:100']);
        return ['page' => $data['line_page'], 'per_page' => $data['line_per_page'] ?? 10];
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
