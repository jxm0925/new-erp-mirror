<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\MaterialDelivery;
use App\Models\Erp\MaterialDeliveryLine;
use App\Models\Erp\MaterialPickingTask;
use App\Models\Erp\MaterialPickingTaskLine;
use App\Models\Erp\MaterialReceipt;
use App\Models\Erp\WorkOrder;
use App\Models\Erp\WorkOrderMaterialRequirement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ProductionMaterialExecutionService
{
    public function __construct(
        private readonly ProductionDataScopeResolver $scopeResolver,
        private readonly InventoryService $inventory,
    ) {}

    public function paginatePickingTasks(array $filters, object $user, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->permission($permissions, 'production.material_picking.view');
        $query = MaterialPickingTask::query()->with(['workOrder.outputItem', 'warehouse'])->orderByDesc('id');
        $this->applyWorkOrderRelationScope($query, 'workOrder', $user, 'production.material_picking.view', $permissions, $superAdmin);
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['warehouse_id'])) $query->where('warehouse_id', (int) $filters['warehouse_id']);
        if (! empty($filters['work_order_id'])) $query->where('work_order_id', (int) $filters['work_order_id']);
        return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
    }

    public function showPickingTask(int $id, object $user, array $permissions, bool $superAdmin): MaterialPickingTask
    {
        $this->permission($permissions, 'production.material_picking.view');
        $task = MaterialPickingTask::with($this->pickingRelations())->find($id);
        if (! $task) $this->fail('not_found', '配料任务不存在。', 404);
        $this->visible($task->workOrder, $user, 'production.material_picking.view', $permissions, $superAdmin);
        return $task;
    }

    public function createPickingTask(array $payload, object $user, array $permissions, bool $superAdmin): MaterialPickingTask
    {
        $this->permission($permissions, 'production.material_picking.create');
        return $this->command('create_picking_task', 'work_order', (int) ($payload['work_order_id'] ?? 0), $payload, $user,
            function () use ($payload, $user, $permissions, $superAdmin): MaterialPickingTask {
                $workOrder = WorkOrder::query()->lockForUpdate()->find((int) $payload['work_order_id']);
                if (! $workOrder) $this->fail('not_found', '工单不存在。', 404);
                $this->visible($workOrder, $user, 'production.material_picking.view', $permissions, $superAdmin);
                $this->version($workOrder, $payload);
                if ($workOrder->status !== WorkOrderApplicationService::RELEASED) {
                    $this->fail('invalid_state', '只有已发布工单可以创建配料任务。');
                }
                if (trim((string) $workOrder->production_location_name) === '') {
                    $this->fail('production_location_missing', '工单缺少生产地点，不能创建配送任务。');
                }
                $warehouseId = (int) ($payload['warehouse_id'] ?? 0);
                $rows = collect($payload['lines'] ?? []);
                if ($warehouseId <= 0 || $rows->isEmpty()) $this->fail('validation_error', '仓库和配料明细不能为空。');
                if ($rows->pluck('material_requirement_id')->duplicates()->isNotEmpty()) {
                    $this->fail('duplicate_requirement', '同一配料任务内一个物料需求只能出现一次。');
                }

                $requirementIds = $rows->pluck('material_requirement_id')->map(fn ($id) => (int) $id)->all();
                $requirements = WorkOrderMaterialRequirement::whereIn('id', $requirementIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($requirements->count() !== count($requirementIds) || $requirements->contains(fn ($row) => (int) $row->work_order_id !== (int) $workOrder->id)) {
                    $this->fail('requirement_invalid', '配料任务只能引用当前已发布工单的正式物料需求。');
                }

                $task = MaterialPickingTask::create([
                    'task_no' => 'TMP-'.bin2hex(random_bytes(12)), 'work_order_id' => $workOrder->id,
                    'status' => 'WAIT_PICK', 'warehouse_id' => $warehouseId,
                    'organization_code' => $workOrder->organization_code,
                    'production_location_name_snapshot' => $workOrder->production_location_name,
                    'responsible_user_legacy_id' => $workOrder->responsible_user_legacy_id,
                    'planned_delivery_at' => $payload['planned_delivery_at'] ?? null,
                    'remark' => $payload['remark'] ?? null, 'business_version' => 1,
                    'created_by_legacy_id' => $this->userId($user), 'updated_by_legacy_id' => $this->userId($user),
                ]);
                $task->task_no = 'MPT'.now()->format('Ymd').str_pad((string) $task->id, 6, '0', STR_PAD_LEFT);
                $task->save();

                foreach ($rows as $row) {
                    $requirement = $requirements[(int) $row['material_requirement_id']];
                    $supply = DB::table('erp_work_order_material_supply_rules')->where('id', (int) ($row['material_supply_rule_snapshot_id'] ?? 0))->lockForUpdate()->first();
                    if (! $supply || (int) $supply->work_order_id !== (int) $workOrder->id
                        || (int) $supply->material_requirement_id !== (int) $requirement->id) {
                        $this->fail('material_supply_rule_invalid', '配料明细必须引用当前工单发布时冻结的物料供应规则。');
                    }
                    if ($supply->supply_mode_snapshot !== 'dedicated_delivery' || ! $supply->requires_delivery_snapshot) {
                        $this->fail('per_order_delivery_not_required', '线边常备或无需逐单配送的物料不能生成逐单配料配送任务。');
                    }
                    $targetType = (string) ($row['production_target_type'] ?? '');
                    $targetId = (int) ($row['production_target_id'] ?? 0);
                    $targetRequirement = DB::table('erp_production_target_material_requirements')
                        ->where('target_type', $targetType)->where('target_id', $targetId)
                        ->where('material_supply_rule_snapshot_id', $supply->id)->lockForUpdate()->first();
                    if (! $targetRequirement) $this->fail('production_target_invalid', '配料明细没有匹配的生产执行目标物料需求。');
                    $balance = InventoryBalance::with('item')->whereKey((int) ($row['inventory_balance_id'] ?? 0))->lockForUpdate()->first();
                    if (! $balance || (int) $balance->item_id !== (int) $requirement->component_item_id || (int) $balance->warehouse_id !== $warehouseId) {
                        $this->fail('inventory_batch_invalid', '配料明细必须选择当前仓库中该物料的真实库存批次。');
                    }
                    $planned = $this->quantity($row['planned_pick_qty'] ?? null, 'planned_pick_qty');
                    $reserved = (float) MaterialPickingTaskLine::query()
                        ->join('erp_material_picking_tasks as tasks', 'tasks.id', '=', 'erp_material_picking_task_lines.task_id')
                        ->where('erp_material_picking_task_lines.material_requirement_id', $requirement->id)
                        ->whereIn('tasks.status', ['WAIT_PICK', 'PICKING'])->sum('erp_material_picking_task_lines.planned_pick_qty');
                    $remaining = (float) $requirement->required_qty - (float) $requirement->picked_qty - $reserved;
                    if ($planned > $remaining + 0.00000001) {
                        $this->fail('pick_quantity_exceeded', '计划配料量超过该正式需求的剩余可配数量。', 422, ['remaining_to_pick' => max(0, $remaining)]);
                    }
                    $serialIds = array_values(array_unique(array_map('intval', (array) ($row['serial_ids'] ?? []))));
                    MaterialPickingTaskLine::create([
                        'task_id' => $task->id, 'material_requirement_id' => $requirement->id,
                        'material_supply_rule_snapshot_id' => $supply->id,
                        'target_routing_operation_id_snapshot' => $supply->target_routing_operation_id_snapshot,
                        'target_operation_code_snapshot' => $supply->target_operation_code_snapshot,
                        'target_operation_name_snapshot' => $supply->target_operation_name_snapshot,
                        'production_target_type' => $targetType, 'production_target_id' => $targetId,
                        'component_item_id' => $requirement->component_item_id,
                        'required_qty_snapshot' => $requirement->required_qty, 'planned_pick_qty' => $planned,
                        'actual_pick_qty' => 0, 'delivered_qty' => 0, 'received_qty' => 0,
                        'unit_id' => $requirement->unit_id, 'unit_name_snapshot' => $requirement->unit_name_snapshot,
                        'inventory_balance_id' => $balance->id, 'warehouse_id' => $balance->warehouse_id,
                        'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no,
                        'serial_control_type' => $balance->item?->serialTrackingMode() ?? 'none',
                        'serial_snapshot' => $serialIds === [] ? null : ['inventory_serial_ids' => $serialIds],
                        'status' => 'WAIT_PICK', 'business_version' => 1,
                    ]);
                }
                $this->event('picking_task', $task->id, 'create', null, 'WAIT_PICK', 0, 1, null, $payload['remark'] ?? null, $user);
                return $task->fresh($this->pickingRelations());
            });
    }

    public function assignPickingTask(int $id, array $payload, object $user, array $permissions, bool $superAdmin): MaterialPickingTask
    {
        $this->permission($permissions, 'production.material_picking.assign');
        return $this->taskTransition($id, $payload, $user, $permissions, $superAdmin, ['WAIT_PICK'], 'WAIT_PICK', 'assign', function (MaterialPickingTask $task) use ($payload): void {
            $picker = (int) ($payload['assigned_picker_legacy_id'] ?? 0);
            if ($picker <= 0 || ! DB::table('erp_legacy_admin_users')->where('legacy_id', $picker)->where('status', 'normal')->exists()) {
                $this->fail('picker_invalid', '指定拣货人不存在或已停用。');
            }
            $task->assigned_picker_legacy_id = $picker;
        });
    }

    public function startPickingTask(int $id, array $payload, object $user, array $permissions, bool $superAdmin): MaterialPickingTask
    {
        $this->permission($permissions, 'production.material_picking.pick');
        return $this->taskTransition($id, $payload, $user, $permissions, $superAdmin, ['WAIT_PICK'], 'PICKING', 'start', function (MaterialPickingTask $task): void {
            if (! $task->assigned_picker_legacy_id) $this->fail('picker_missing', '请先分配拣货人。');
        });
    }

    public function confirmPickingTask(int $id, array $payload, object $user, array $permissions, bool $superAdmin): MaterialPickingTask
    {
        $this->permission($permissions, 'production.material_picking.pick');
        return $this->command('confirm_picking', 'picking_task', $id, $payload, $user,
            function () use ($id, $payload, $user, $permissions, $superAdmin): MaterialPickingTask {
                $task = MaterialPickingTask::with(['lines.componentItem', 'workOrder'])->lockForUpdate()->find($id);
                if (! $task) $this->fail('not_found', '配料任务不存在。', 404);
                $this->visible($task->workOrder, $user, 'production.material_picking.view', $permissions, $superAdmin);
                $this->version($task, $payload);
                if ($task->status !== 'PICKING') $this->fail('invalid_state', '只有拣货中的任务可以确认拣货。');
                $actualRows = collect($payload['lines'] ?? [])->keyBy(fn ($row) => (int) ($row['picking_task_line_id'] ?? 0));
                if ($actualRows->isEmpty()) $this->fail('validation_error', '实拣明细不能为空。');
                $quantitySnapshot = [];
                foreach ($task->lines as $line) {
                    $row = $actualRows->get($line->id);
                    $actual = $row ? $this->quantity($row['actual_pick_qty'] ?? null, 'actual_pick_qty', true) : 0.0;
                    if ($actual > (float) $line->planned_pick_qty + 0.00000001) $this->fail('pick_quantity_exceeded', '实拣数量不能超过计划配料数量。');
                    if ($actual > 0 && isset($row['serial_ids'])) {
                        $line->serial_snapshot = ['inventory_serial_ids' => array_values(array_unique(array_map('intval', (array) $row['serial_ids'])))];
                    }
                    $line->actual_pick_qty = $actual;
                    $line->status = $actual > 0 ? 'PICKED' : 'UNPICKED';
                    $line->business_version++;
                    $line->save();
                    if ($actual > 0) $quantitySnapshot[] = ['line_id' => $line->id, 'actual_pick_qty' => $actual];
                }
                if ($quantitySnapshot === []) $this->fail('validation_error', '确认拣货至少需要一条大于 0 的实拣数量。');
                $transaction = $this->inventory->postProductionMaterialPicking($task, $user);
                foreach ($task->lines->where('actual_pick_qty', '>', 0) as $line) {
                    $requirement = WorkOrderMaterialRequirement::lockForUpdate()->findOrFail($line->material_requirement_id);
                    $requirement->picked_qty = (float) $requirement->picked_qty + (float) $line->actual_pick_qty;
                    $requirement->issued_qty = (float) $requirement->issued_qty + (float) $line->actual_pick_qty;
                    $requirement->remaining_qty = max(0, (float) $requirement->required_qty - (float) $requirement->issued_qty + (float) $requirement->returned_qty);
                    $requirement->status = (float) $requirement->remaining_qty <= 0.00000001 ? 'FULLY_PICKED' : 'PARTIALLY_PICKED';
                    $requirement->business_version++;
                    $requirement->save();
                }
                $beforeVersion = (int) $task->business_version;
                $task->status = 'PICKED';
                $task->inventory_transaction_id = $transaction->id;
                $task->business_version++;
                $task->updated_by_legacy_id = $this->userId($user);
                $task->save();
                $this->event('picking_task', $task->id, 'confirm', 'PICKING', 'PICKED', $beforeVersion, $task->business_version, $quantitySnapshot, $payload['reason'] ?? null, $user);
                return $task->fresh($this->pickingRelations());
            });
    }

    public function cancelPickingTask(int $id, array $payload, object $user, array $permissions, bool $superAdmin): MaterialPickingTask
    {
        $this->permission($permissions, 'production.material_picking.cancel');
        return $this->taskTransition($id, $payload, $user, $permissions, $superAdmin, ['WAIT_PICK', 'PICKING', 'PICKED'], 'CANCELLED', 'cancel', function (MaterialPickingTask $task) use ($payload): void {
            if ($task->inventory_transaction_id) $this->fail('reverse_required', '该任务已产生正式库存事实，不能直接取消，必须走逆向业务。', 409);
            if (trim((string) ($payload['reason'] ?? '')) === '') $this->fail('reason_required', '取消原因不能为空。');
            $task->lines()->update(['status' => 'CANCELLED']);
        });
    }

    public function paginateDeliveries(array $filters, object $user, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->permission($permissions, 'production.material_delivery.view');
        $query = MaterialDelivery::query()->with(['workOrder.outputItem', 'pickingTask'])->orderByDesc('id');
        $this->applyWorkOrderRelationScope($query, 'workOrder', $user, 'production.material_delivery.view', $permissions, $superAdmin);
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['work_order_id'])) $query->where('work_order_id', (int) $filters['work_order_id']);
        return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
    }

    public function showDelivery(int $id, object $user, array $permissions, bool $superAdmin): MaterialDelivery
    {
        $this->permission($permissions, 'production.material_delivery.view');
        $delivery = MaterialDelivery::with($this->deliveryRelations())->find($id);
        if (! $delivery) $this->fail('not_found', '配送单不存在。', 404);
        $this->visible($delivery->workOrder, $user, 'production.material_delivery.view', $permissions, $superAdmin);
        return $delivery;
    }

    public function createDelivery(array $payload, object $user, array $permissions, bool $superAdmin): MaterialDelivery
    {
        $this->permission($permissions, 'production.material_delivery.create');
        $taskId = (int) ($payload['picking_task_id'] ?? 0);
        return $this->command('create_delivery', 'picking_task', $taskId, $payload, $user,
            function () use ($taskId, $payload, $user, $permissions, $superAdmin): MaterialDelivery {
                $task = MaterialPickingTask::with(['lines', 'workOrder'])->lockForUpdate()->find($taskId);
                if (! $task) $this->fail('not_found', '配料任务不存在。', 404);
                $this->visible($task->workOrder, $user, 'production.material_delivery.view', $permissions, $superAdmin);
                $this->version($task, $payload);
                if (! in_array($task->status, ['PICKED', 'WAIT_DELIVERY', 'DELIVERED', 'PARTIALLY_RECEIVED'], true)) {
                    $this->fail('invalid_state', '当前配料任务状态不能创建配送单。');
                }
                $rows = collect($payload['lines'] ?? []);
                if ($rows->isEmpty()) $this->fail('validation_error', '配送明细不能为空。');
                $deliveryType = (string) ($payload['delivery_type'] ?? 'standard');
                $sourceDelivery = null;
                if ($deliveryType === 'redelivery') {
                    $sourceDelivery = MaterialDelivery::with('lines')->lockForUpdate()->find((int) ($payload['source_delivery_id'] ?? 0));
                    if (! $sourceDelivery || (int) $sourceDelivery->work_order_id !== (int) $task->work_order_id) $this->fail('source_delivery_invalid', '补送配送必须关联同一工单的原配送单。');
                    if (! $sourceDelivery->lines->contains(fn ($line) => (float) $line->rejected_qty > 0)) $this->fail('redelivery_balance_missing', '原配送单没有拒收余额，不需要补送。');
                } elseif (! empty($payload['source_delivery_id'])) {
                    $this->fail('source_delivery_not_allowed', '只有原需求未履约补送才允许关联原配送单。');
                }
                $lineIds = $rows->pluck('picking_task_line_id')->map(fn ($id) => (int) $id)->all();
                $pickLines = MaterialPickingTaskLine::whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($pickLines->count() !== count($lineIds) || $pickLines->contains(fn ($line) => (int) $line->task_id !== $task->id || (float) $line->actual_pick_qty <= 0)) {
                    $this->fail('picking_line_invalid', '配送只能引用当前任务已确认的正式配料明细。');
                }
                $targetKeys = $pickLines->map(fn ($line) => $line->production_target_type.':'.$line->production_target_id.':'.$line->target_routing_operation_id_snapshot)->unique();
                if ($targetKeys->count() !== 1) $this->fail('delivery_target_mismatch', '一张配送单只能绑定同一个生产目标和目标工序。');
                $firstPickLine = $pickLines->first();
                $expectedReceiver = DB::table('erp_production_task_targets as target')
                    ->join('erp_production_tasks as production_task', 'production_task.id', '=', 'target.task_id')
                    ->where('target.target_type', $firstPickLine->production_target_type)
                    ->where('target.target_id', $firstPickLine->production_target_id)
                    ->value('production_task.assignee_user_legacy_id');
                $delivery = MaterialDelivery::create([
                    'delivery_no' => 'TMP-'.bin2hex(random_bytes(12)), 'work_order_id' => $task->work_order_id,
                    'picking_task_id' => $task->id, 'status' => 'READY',
                    'delivery_type' => $deliveryType,
                    'source_delivery_id' => $payload['source_delivery_id'] ?? null,
                    'target_routing_operation_id_snapshot' => $firstPickLine->target_routing_operation_id_snapshot,
                    'target_operation_code_snapshot' => $firstPickLine->target_operation_code_snapshot,
                    'target_operation_name_snapshot' => $firstPickLine->target_operation_name_snapshot,
                    'production_target_type' => $firstPickLine->production_target_type,
                    'production_target_id' => $firstPickLine->production_target_id,
                    'delivery_user_legacy_id' => $payload['delivery_user_legacy_id'] ?? null,
                    'expected_receiver_legacy_id' => $expectedReceiver,
                    'from_warehouse_id' => $task->warehouse_id, 'organization_code' => $task->organization_code,
                    'to_production_location_snapshot' => $task->production_location_name_snapshot,
                    'remark' => $payload['remark'] ?? null, 'business_version' => 1,
                    'created_by_legacy_id' => $this->userId($user), 'updated_by_legacy_id' => $this->userId($user),
                ]);
                $delivery->delivery_no = 'MDL'.now()->format('Ymd').str_pad((string) $delivery->id, 6, '0', STR_PAD_LEFT);
                $delivery->save();
                foreach ($rows as $row) {
                    $pickLine = $pickLines[(int) $row['picking_task_line_id']];
                    $quantity = $this->quantity($row['delivery_qty'] ?? null, 'delivery_qty');
                    if ($deliveryType === 'redelivery') {
                        $sourceLine = $sourceDelivery->lines->firstWhere('picking_task_line_id', $pickLine->id);
                        if (! $sourceLine) $this->fail('redelivery_source_line_invalid', '补送明细必须对应原配送单的拒收行。');
                        $alreadyRedelivered = (float) MaterialDeliveryLine::query()
                            ->join('erp_material_deliveries as deliveries', 'deliveries.id', '=', 'erp_material_delivery_lines.delivery_id')
                            ->where('deliveries.delivery_type', 'redelivery')->where('deliveries.source_delivery_id', $sourceDelivery->id)
                            ->where('deliveries.status', '<>', 'CANCELLED')->where('erp_material_delivery_lines.picking_task_line_id', $pickLine->id)
                            ->sum('erp_material_delivery_lines.delivery_qty');
                        if ($quantity > (float) $sourceLine->rejected_qty - $alreadyRedelivered + 0.00000001) $this->fail('redelivery_quantity_exceeded', '补送数量不能超过原配送单尚未补送的拒收余额。');
                    } else {
                    $allocated = (float) MaterialDeliveryLine::query()
                        ->join('erp_material_deliveries as deliveries', 'deliveries.id', '=', 'erp_material_delivery_lines.delivery_id')
                        ->where('erp_material_delivery_lines.picking_task_line_id', $pickLine->id)
                        ->where('deliveries.status', '<>', 'CANCELLED')->sum('erp_material_delivery_lines.delivery_qty');
                    if ($quantity > (float) $pickLine->actual_pick_qty - $allocated + 0.00000001) {
                        $this->fail('delivery_quantity_exceeded', '配送数量不能超过该配料行尚未分配的已拣数量。');
                    }
                    }
                    MaterialDeliveryLine::create([
                        'delivery_id' => $delivery->id, 'material_requirement_id' => $pickLine->material_requirement_id,
                        'picking_task_line_id' => $pickLine->id, 'component_item_id' => $pickLine->component_item_id,
                        'delivery_qty' => $quantity, 'received_qty' => 0, 'rejected_qty' => 0,
                        'unit_id' => $pickLine->unit_id, 'unit_name_snapshot' => $pickLine->unit_name_snapshot,
                        'batch_no' => $pickLine->batch_no, 'serial_snapshot' => $pickLine->serial_snapshot,
                    ]);
                }
                $this->event('delivery', $delivery->id, 'create', null, 'READY', 0, 1, null, $payload['remark'] ?? null, $user);
                $task->status = 'WAIT_DELIVERY'; $task->business_version++; $task->updated_by_legacy_id = $this->userId($user); $task->save();
                return $delivery->fresh($this->deliveryRelations());
            });
    }

    public function dispatchDelivery(int $id, array $payload, object $user, array $permissions, bool $superAdmin): MaterialDelivery
    {
        $this->permission($permissions, 'production.material_delivery.dispatch');
        return $this->deliveryTransition($id, $payload, $user, $permissions, $superAdmin, 'READY', 'IN_TRANSIT', 'dispatch', function (MaterialDelivery $delivery) use ($payload): void {
            $deliveryUser = (int) ($payload['delivery_user_legacy_id'] ?? $delivery->delivery_user_legacy_id ?? 0);
            if ($deliveryUser <= 0 || ! DB::table('erp_legacy_admin_users')->where('legacy_id', $deliveryUser)->where('status', 'normal')->exists()) {
                $this->fail('delivery_user_invalid', '配送人不存在或已停用。');
            }
            $delivery->delivery_user_legacy_id = $deliveryUser;
            $delivery->departed_at = now();
            $delivery->pickingTask->status = 'DELIVERING';
            $delivery->pickingTask->business_version++;
            $delivery->pickingTask->save();
        });
    }

    public function deliverDelivery(int $id, array $payload, object $user, array $permissions, bool $superAdmin): MaterialDelivery
    {
        $this->permission($permissions, 'production.material_delivery.confirm');
        return $this->deliveryTransition($id, $payload, $user, $permissions, $superAdmin, 'IN_TRANSIT', 'DELIVERED', 'deliver', function (MaterialDelivery $delivery): void {
            foreach ($delivery->lines as $line) {
                $line->pickingTaskLine()->lockForUpdate()->increment('delivered_qty', $line->delivery_qty);
                $line->requirement()->lockForUpdate()->incrementEach(['delivered_qty' => $line->delivery_qty, 'business_version' => 1]);
            }
            $delivery->delivered_at = now();
            $delivery->pickingTask->status = 'DELIVERED';
            $delivery->pickingTask->business_version++;
            $delivery->pickingTask->save();
        });
    }

    public function receiveDelivery(int $id, array $payload, object $user, array $permissions, bool $superAdmin): MaterialReceipt
    {
        $this->permission($permissions, 'production.material_receipt.confirm');
        return $this->command('receive_delivery', 'delivery', $id, $payload, $user,
            function () use ($id, $payload, $user, $permissions, $superAdmin): MaterialReceipt {
                $delivery = MaterialDelivery::with($this->deliveryRelations())->lockForUpdate()->find($id);
                if (! $delivery) $this->fail('not_found', '配送单不存在。', 404);
                $this->visible($delivery->workOrder, $user, 'production.material_receipt.view', $permissions, $superAdmin);
                $this->version($delivery, $payload);
                if ($delivery->status !== 'DELIVERED') $this->fail('invalid_state', '只有已送达且尚未全部确认的配送单可以收料。');
                $expectedReceiver = $delivery->expected_receiver_legacy_id ?: DB::table('erp_production_task_targets as target')
                    ->join('erp_production_tasks as production_task', 'production_task.id', '=', 'target.task_id')
                    ->where('target.target_type', $delivery->production_target_type)
                    ->where('target.target_id', $delivery->production_target_id)
                    ->value('production_task.assignee_user_legacy_id');
                if (! $expectedReceiver) $this->fail('production_target_unclaimed', '生产目标尚未接单，不能确认物料责任交接。', 409);
                if ((int) $expectedReceiver !== $this->userId($user)) {
                    $this->fail('receiver_mismatch', '只有该生产目标当前责任人可以确认收料。', 403);
                }
                if (! $delivery->expected_receiver_legacy_id) $delivery->expected_receiver_legacy_id = (int) $expectedReceiver;
                $rows = collect($payload['lines'] ?? [])->keyBy(fn ($row) => (int) ($row['delivery_line_id'] ?? 0));
                if ($rows->isEmpty()) $this->fail('validation_error', '收料明细不能为空。');
                $receipt = MaterialReceipt::create([
                    'receipt_no' => 'TMP-'.bin2hex(random_bytes(12)), 'delivery_id' => $delivery->id,
                    'work_order_id' => $delivery->work_order_id, 'status' => 'CONFIRMED',
                    'received_by_legacy_id' => $this->userId($user), 'received_at' => now(),
                    'remark' => $payload['remark'] ?? null, 'business_version' => 1,
                ]);
                $receipt->receipt_no = 'MRC'.now()->format('Ymd').str_pad((string) $receipt->id, 6, '0', STR_PAD_LEFT);
                $receipt->save();
                $snapshot = [];
                foreach ($delivery->lines as $line) {
                    $row = $rows->get($line->id);
                    if (! $row) continue;
                    $accepted = $this->quantity($row['accepted_qty'] ?? 0, 'accepted_qty', true);
                    $rejected = $this->quantity($row['rejected_qty'] ?? 0, 'rejected_qty', true);
                    if ($accepted + $rejected <= 0) continue;
                    $remaining = (float) $line->delivery_qty - (float) $line->received_qty - (float) $line->rejected_qty;
                    if ($accepted + $rejected > $remaining + 0.00000001) $this->fail('receipt_quantity_exceeded', '收料与拒收合计不能超过该配送行的剩余待确认数量。');
                    $reason = trim((string) ($row['reject_reason'] ?? ''));
                    if ($rejected > 0 && $reason === '') $this->fail('reject_reason_required', '存在拒收数量时必须填写拒收原因。');
                    $acceptedSerials = array_values(array_unique(array_map('intval', (array) ($row['accepted_serial_ids'] ?? []))));
                    $rejectedSerials = array_values(array_unique(array_map('intval', (array) ($row['rejected_serial_ids'] ?? []))));
                    $availableSerials = array_values(array_map('intval', (array) (($line->serial_snapshot ?? [])['inventory_serial_ids'] ?? [])));
                    if (($acceptedSerials || $rejectedSerials) && array_diff(array_merge($acceptedSerials, $rejectedSerials), $availableSerials)) {
                        $this->fail('serial_invalid', '收料序列号必须来自该配送行的真实配料序列号。');
                    }
                    if (array_intersect($acceptedSerials, $rejectedSerials)) $this->fail('serial_duplicate', '同一序列号不能同时收料和拒收。');
                    MaterialReceipt::findOrFail($receipt->id)->lines()->create([
                        'delivery_line_id' => $line->id, 'component_item_id' => $line->component_item_id,
                        'delivered_qty_snapshot' => $line->delivery_qty, 'accepted_qty' => $accepted,
                        'rejected_qty' => $rejected, 'reject_reason' => $reason ?: null, 'unit_id' => $line->unit_id,
                        'accepted_serial_snapshot' => $acceptedSerials ? ['inventory_serial_ids' => $acceptedSerials] : null,
                        'rejected_serial_snapshot' => $rejectedSerials ? ['inventory_serial_ids' => $rejectedSerials] : null,
                    ]);
                    $line->received_qty = (float) $line->received_qty + $accepted;
                    $line->rejected_qty = (float) $line->rejected_qty + $rejected;
                    $line->save();
                    $line->pickingTaskLine()->lockForUpdate()->increment('received_qty', $accepted);
                    $line->requirement()->lockForUpdate()->incrementEach(['received_qty' => $accepted, 'business_version' => 1]);
                    if ($accepted > 0 && $delivery->production_target_type && $delivery->production_target_id) {
                        $targetRequirement = DB::table('erp_production_target_material_requirements')
                            ->where('target_type', $delivery->production_target_type)
                            ->where('target_id', $delivery->production_target_id)
                            ->where('material_requirement_id', $line->material_requirement_id)
                            ->lockForUpdate()->first();
                        if ($targetRequirement) {
                            $satisfied = min((float) $targetRequirement->required_base_qty, (float) $targetRequirement->satisfied_base_qty + $accepted);
                            DB::table('erp_production_target_material_requirements')->where('id', $targetRequirement->id)->update([
                                'satisfied_base_qty' => $satisfied,
                                'status' => $satisfied + 0.00000001 >= (float) $targetRequirement->required_base_qty ? 'SATISFIED' : 'PARTIALLY_SATISFIED',
                                'business_version' => (int) $targetRequirement->business_version + 1,
                                'updated_at' => now(),
                            ]);
                        }
                    }
                    $this->updateReceivedSerials($acceptedSerials, 'production_received', $receipt, $line);
                    $this->updateReceivedSerials($rejectedSerials, 'production_rejected', $receipt, $line);
                    $snapshot[] = ['delivery_line_id' => $line->id, 'accepted_qty' => $accepted, 'rejected_qty' => $rejected];
                }
                if ($snapshot === []) $this->fail('validation_error', '收料至少需要一条大于 0 的确认数量。');
                $beforeVersion = (int) $delivery->business_version;
                $settled = $delivery->lines()->get()->every(fn ($line) => (float) $line->received_qty + (float) $line->rejected_qty >= (float) $line->delivery_qty - 0.00000001);
                $delivery->status = $settled ? 'RECEIVED' : 'DELIVERED';
                $delivery->business_version++;
                $delivery->updated_by_legacy_id = $this->userId($user);
                $delivery->save();
                $task = $delivery->pickingTask()->lockForUpdate()->first();
                $allSettled = $task->deliveries()->where('status', '<>', 'RECEIVED')->doesntExist();
                $task->status = $allSettled ? 'RECEIVED' : 'PARTIALLY_RECEIVED';
                $task->business_version++; $task->updated_by_legacy_id = $this->userId($user); $task->save();
                $this->event('delivery', $delivery->id, 'receive', 'DELIVERED', $delivery->status, $beforeVersion, $delivery->business_version, $snapshot, $payload['remark'] ?? null, $user);
                return $receipt->fresh(['lines.deliveryLine', 'delivery', 'workOrder']);
            });
    }

    public function showReceipt(int $id, object $user, array $permissions, bool $superAdmin): MaterialReceipt
    {
        $this->permission($permissions, 'production.material_receipt.view');
        $receipt = MaterialReceipt::with(['lines.deliveryLine', 'delivery', 'workOrder'])->find($id);
        if (! $receipt) $this->fail('not_found', '收料记录不存在。', 404);
        $this->visible($receipt->workOrder, $user, 'production.material_receipt.view', $permissions, $superAdmin);
        return $receipt;
    }

    public function workOrderExecution(int $id, object $user, array $permissions, bool $superAdmin): array
    {
        $this->permission($permissions, 'production.material_requirement.view');
        $workOrder = WorkOrder::with([
            'materialRequirements.componentItem', 'materialPickingTasks.lines',
            'materialDeliveries.lines', 'materialReceipts.lines',
        ])->find($id);
        if (! $workOrder) $this->fail('not_found', '工单不存在。', 404);
        $this->visible($workOrder, $user, 'production.material_requirement.view', $permissions, $superAdmin);
        return [
            'work_order' => $workOrder,
            'requirements' => $workOrder->materialRequirements->map(fn ($row) => [
                'id' => $row->id, 'component_item_id' => $row->component_item_id,
                'item_code' => $row->component_item_code_snapshot, 'item_name' => $row->component_item_name_snapshot,
                'required_qty' => (float) $row->required_qty, 'picked_qty' => (float) $row->picked_qty,
                'delivered_qty' => (float) $row->delivered_qty, 'received_qty' => (float) $row->received_qty,
                'remaining_to_pick' => max(0, (float) $row->required_qty - (float) $row->picked_qty),
                'unit_name' => $row->unit_name_snapshot, 'status' => $row->status,
            ])->values(),
            'picking_tasks' => $workOrder->materialPickingTasks,
            'deliveries' => $workOrder->materialDeliveries,
            'receipts' => $workOrder->materialReceipts,
        ];
    }

    private function taskTransition(int $id, array $payload, object $user, array $permissions, bool $superAdmin, array $from, string $to, string $action, callable $mutate): MaterialPickingTask
    {
        return $this->command($action.'_picking', 'picking_task', $id, $payload, $user, function () use ($id, $payload, $user, $permissions, $superAdmin, $from, $to, $action, $mutate) {
            $task = MaterialPickingTask::with(['lines', 'workOrder'])->lockForUpdate()->find($id);
            if (! $task) $this->fail('not_found', '配料任务不存在。', 404);
            $this->visible($task->workOrder, $user, 'production.material_picking.view', $permissions, $superAdmin);
            $this->version($task, $payload);
            if (! in_array($task->status, $from, true)) $this->fail('invalid_state', '当前配料任务状态不允许执行该操作。');
            $before = $task->status; $beforeVersion = (int) $task->business_version;
            $mutate($task);
            $task->status = $to; $task->business_version++; $task->updated_by_legacy_id = $this->userId($user); $task->save();
            $this->event('picking_task', $task->id, $action, $before, $to, $beforeVersion, $task->business_version, null, $payload['reason'] ?? null, $user);
            return $task->fresh($this->pickingRelations());
        });
    }

    private function deliveryTransition(int $id, array $payload, object $user, array $permissions, bool $superAdmin, string $from, string $to, string $action, callable $mutate): MaterialDelivery
    {
        return $this->command($action.'_delivery', 'delivery', $id, $payload, $user, function () use ($id, $payload, $user, $permissions, $superAdmin, $from, $to, $action, $mutate) {
            $delivery = MaterialDelivery::with($this->deliveryRelations())->lockForUpdate()->find($id);
            if (! $delivery) $this->fail('not_found', '配送单不存在。', 404);
            $this->visible($delivery->workOrder, $user, 'production.material_delivery.view', $permissions, $superAdmin);
            $this->version($delivery, $payload);
            if ($delivery->status !== $from) $this->fail('invalid_state', '当前配送单状态不允许执行该操作。');
            $beforeVersion = (int) $delivery->business_version;
            $mutate($delivery);
            $delivery->status = $to; $delivery->business_version++; $delivery->updated_by_legacy_id = $this->userId($user); $delivery->save();
            $this->event('delivery', $delivery->id, $action, $from, $to, $beforeVersion, $delivery->business_version, null, $payload['reason'] ?? null, $user);
            return $delivery->fresh($this->deliveryRelations());
        });
    }

    private function command(string $type, string $aggregateType, ?int $aggregateId, array $payload, object $user, callable $action): mixed
    {
        $id = trim((string) ($payload['client_command_id'] ?? ''));
        if ($id === '') $this->fail('validation_error', 'client_command_id 不能为空。');
        $normalized = $this->sortPayload($payload);
        $hash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        try {
            return DB::transaction(function () use ($type, $aggregateType, $aggregateId, $payload, $user, $action, $id, $hash) {
                $existing = DB::table('erp_production_material_commands')->where('client_command_id', $id)->lockForUpdate()->first();
                if ($existing) return $this->existingCommand($existing, $type, $hash);
                DB::table('erp_production_material_commands')->insert([
                    'client_command_id' => $id, 'command_type' => $type, 'aggregate_type' => $aggregateType,
                    'aggregate_id' => $aggregateId, 'request_hash' => $hash, 'status' => 'processing',
                    'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $result = $action();
                $resultType = $result instanceof MaterialPickingTask ? 'picking_task' : ($result instanceof MaterialDelivery ? 'delivery' : 'receipt');
                DB::table('erp_production_material_commands')->where('client_command_id', $id)->update([
                    'aggregate_id' => $aggregateId ?: $result->id, 'result_type' => $resultType, 'result_id' => $result->id,
                    'response_snapshot' => json_encode(['id' => $result->id], JSON_UNESCAPED_UNICODE),
                    'status' => 'succeeded', 'processing_finished_at' => now(), 'updated_at' => now(),
                ]);
                return $result;
            }, 5);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000' || (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                $existing = DB::table('erp_production_material_commands')->where('client_command_id', $id)->first();
                if ($existing) return $this->existingCommand($existing, $type, $hash);
                $this->fail('persistence_conflict', '生产物料执行数据发生并发冲突，请刷新后重试。', 409);
            }
            throw $exception;
        }
    }

    private function existingCommand(object $existing, string $type, string $hash): mixed
    {
        if ($existing->command_type !== $type || $existing->request_hash !== $hash) {
            $this->fail('idempotency_hash_conflict', '该请求标识已用于不同的生产物料操作。', 409);
        }
        if ($existing->status !== 'succeeded' || ! $existing->result_id) $this->fail('command_processing', '相同命令正在处理中，请稍后重试。', 409);
        return match ($existing->result_type) {
            'picking_task' => MaterialPickingTask::with($this->pickingRelations())->findOrFail($existing->result_id),
            'delivery' => MaterialDelivery::with($this->deliveryRelations())->findOrFail($existing->result_id),
            'receipt' => MaterialReceipt::with(['lines.deliveryLine', 'delivery', 'workOrder'])->findOrFail($existing->result_id),
            default => $this->fail('command_result_invalid', '幂等命令结果无法恢复，请联系管理员。', 409),
        };
    }

    private function updateReceivedSerials(array $ids, string $status, MaterialReceipt $receipt, MaterialDeliveryLine $line): void
    {
        if ($ids === []) return;
        $serials = InventorySerial::whereIn('id', $ids)->where('serial_status', 'production_in_transit')->lockForUpdate()->get();
        if ($serials->count() !== count($ids)) $this->fail('serial_state_conflict', '部分序列号已被其他收料操作处理。', 409);
        foreach ($serials as $serial) {
            $serial->update(['serial_status' => $status]);
            InventorySerialEvent::create([
                'inventory_serial_id' => $serial->id, 'event_type' => 'production_material_receipt',
                'document_type' => 'material_receipt', 'document_id' => $receipt->id,
                'document_no' => $receipt->receipt_no, 'from_status' => 'production_in_transit',
                'to_status' => $status, 'warehouse_id' => $serial->warehouse_id,
                'location_id' => $serial->location_id, 'batch_no' => $serial->batch_no,
                'event_payload' => ['delivery_line_id' => $line->id], 'occurred_at' => now(),
            ]);
        }
    }

    private function event(string $type, int $id, string $action, ?string $before, ?string $after, int $beforeVersion, int $afterVersion, mixed $quantities, ?string $reason, object $user): void
    {
        DB::table('erp_production_material_events')->insert([
            'aggregate_type' => $type, 'aggregate_id' => $id, 'action' => $action,
            'before_status' => $before, 'after_status' => $after,
            'before_version' => $beforeVersion, 'after_version' => $afterVersion,
            'quantity_snapshot' => $quantities === null ? null : json_encode($quantities, JSON_UNESCAPED_UNICODE),
            'reason' => $reason, 'operator_legacy_id' => $this->userId($user),
            'operator_name' => $user->nickname ?? $user->username ?? null,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function applyWorkOrderRelationScope($query, string $relation, object $user, string $permission, array $permissions, bool $superAdmin): void
    {
        $scope = $this->scopeResolver->resolve($user, $permission, $permissions, $superAdmin);
        $query->whereHas($relation, function ($workOrders) use ($scope): void { $this->scopeResolver->applyWorkOrderScope($workOrders, $scope); });
    }

    private function visible(WorkOrder $workOrder, object $user, string $permission, array $permissions, bool $superAdmin): void
    {
        $scope = $this->scopeResolver->resolve($user, $permission, $permissions, $superAdmin);
        if (! $this->scopeResolver->workOrderVisible($workOrder, $scope)) $this->fail('data_scope_denied', '当前用户不在该生产物料任务的数据范围内。', 403);
    }

    private function permission(array $permissions, string $required): void
    {
        if (! in_array($required, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该生产物料操作的权限。', 403, ['permission' => $required]);
    }

    private function version(object $model, array $payload): void
    {
        if (! array_key_exists('expected_version', $payload)) $this->fail('validation_error', 'expected_version 不能为空。');
        if ((int) $payload['expected_version'] !== (int) $model->business_version) $this->fail('version_conflict', '数据版本已变化，请刷新后重试。', 409, ['current_version' => (int) $model->business_version]);
    }

    private function quantity(mixed $value, string $field, bool $allowZero = false): float
    {
        $text = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,8})?$/', $text)) $this->fail('quantity_precision', "{$field} 必须是最多 8 位小数的非负数量。");
        $number = (float) $text;
        if ($allowZero ? $number < 0 : $number <= 0) $this->fail('quantity_invalid', "{$field} 数量不合法。");
        return $number;
    }

    private function sortPayload(array $payload): array
    {
        foreach ($payload as $key => $value) if (is_array($value)) $payload[$key] = $this->sortPayload($value);
        ksort($payload);
        return $payload;
    }

    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function pickingRelations(): array { return ['workOrder.outputItem', 'warehouse', 'inventoryTransaction.items', 'lines.requirement', 'lines.componentItem', 'lines.inventoryBalance']; }
    private function deliveryRelations(): array { return ['workOrder.outputItem', 'pickingTask', 'lines.requirement', 'lines.pickingTaskLine', 'receipts.lines']; }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never { throw new WorkOrderDomainException($code, $message, $status, $details); }
}
