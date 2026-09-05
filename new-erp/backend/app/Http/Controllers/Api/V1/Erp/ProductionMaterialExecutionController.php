<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionMaterialExecutionService;
use Illuminate\Http\Request;

class ProductionMaterialExecutionController extends Controller
{
    public function pickingTasks(Request $request, ProductionMaterialExecutionService $service)
    {
        return response()->json($service->paginatePickingTasks($this->filters($request, true), ...$this->context($request)));
    }

    public function showPickingTask(Request $request, int $id, ProductionMaterialExecutionService $service)
    {
        return response()->json(['data' => $service->showPickingTask($id, ...$this->context($request))]);
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
        return response()->json(['data' => $service->showDelivery($id, ...$this->context($request))]);
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
        ]);
        $receipt = $service->receiveDelivery($id, $payload, ...$this->context($request));
        return response()->json(['message' => '收料已确认。', 'data' => $receipt], 201);
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
            'lines.*.material_requirement_id' => ['required', 'integer', 'min:1'],
            'lines.*.material_supply_rule_snapshot_id' => ['required', 'integer', 'min:1'],
            'lines.*.production_target_type' => ['required', 'in:unit_operation,quantity_operation'],
            'lines.*.production_target_id' => ['required', 'integer', 'min:1'],
            'lines.*.inventory_balance_id' => ['required', 'integer', 'min:1'],
            'lines.*.planned_pick_qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.serial_ids' => ['nullable', 'array'],
            'lines.*.serial_ids.*' => ['integer', 'min:1'],
        ]);
    }

    private function filters(Request $request, bool $picking = false): array
    {
        return $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'warehouse_id' => [$picking ? 'nullable' : 'prohibited', 'integer', 'min:1'],
            'work_order_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }
}
