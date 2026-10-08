<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\Item;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\MaterialDelivery;
use App\Models\Erp\MaterialDeliveryLine;
use App\Models\Erp\MaterialPickingTask;
use App\Models\Erp\MaterialPickingTaskLine;
use App\Models\Erp\MaterialReceipt;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
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
        private readonly ProductionTargetReadinessService $targetReadiness,
        private readonly ProductionMaterialCostService $materialCosts,
        private readonly ProductionPickingStockService $pickingStock,
    ) {}

    public function paginatePickingTasks(array $filters, object $user, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->permission($permissions, 'production.material_picking.view');
        $query = MaterialPickingTask::query()->with(['workOrder.outputItem', 'warehouse'])->withCount('lines')->orderByDesc('id');
        $this->applyWorkOrderRelationScope($query, 'workOrder', $user, 'production.material_picking.view', $permissions, $superAdmin);
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (($filters['status_group'] ?? null) === 'active') $query->whereIn('status', ['WAIT_PICK', 'PICKING']);
        if (! empty($filters['warehouse_id'])) $query->where('warehouse_id', (int) $filters['warehouse_id']);
        if (! empty($filters['work_order_id'])) $query->where('work_order_id', (int) $filters['work_order_id']);
        $this->searchDocuments($query, $filters, 'task_no');
        return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
    }

    public function paginatePreparationDemands(array $filters, object $user, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        return $this->preparationDemandQuery($filters, $user, $permissions, $superAdmin)
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
    }

    public function preparationDemandQuery(array $filters, object $user, array $permissions, bool $superAdmin)
    {
        $this->permission($permissions, 'production.material_requirement.view');
        $scope = $this->scopeResolver->resolve($user, 'production.material_requirement.view', $permissions, $superAdmin);
        $visibleWorkOrders = WorkOrder::query()->select('id');
        $this->scopeResolver->applyWorkOrderScope($visibleWorkOrders, $scope);

        $allocatedSql = "COALESCE((SELECT SUM(CASE WHEN task.status IN ('WAIT_PICK', 'PICKING') THEN line.planned_pick_qty ELSE GREATEST(line.actual_pick_qty - line.received_qty, 0) END) FROM erp_material_picking_task_lines line JOIN erp_material_picking_tasks task ON task.id = line.task_id WHERE line.production_target_type = demand.target_type AND line.production_target_id = demand.target_id AND line.material_supply_rule_snapshot_id = demand.material_supply_rule_snapshot_id AND task.status != 'CANCELLED'), 0)";
        $remainingSql = "GREATEST(demand.required_base_qty - GREATEST(demand.satisfied_base_qty - demand.returned_base_qty, 0) - {$allocatedSql}, 0)";

        $query = DB::table('erp_production_target_material_requirements as demand')
            ->join('erp_work_order_material_supply_rules as supply', 'supply.id', '=', 'demand.material_supply_rule_snapshot_id')
            ->join('erp_work_order_material_requirements as requirement', 'requirement.id', '=', 'demand.material_requirement_id')
            ->join('erp_work_orders as work_order', 'work_order.id', '=', 'demand.work_order_id')
            ->join('erp_items as item', 'item.id', '=', 'demand.component_item_id')
            ->leftJoin('erp_items as output', 'output.id', '=', 'work_order.output_item_id')
            ->leftJoin('erp_production_unit_operations as unit_operation', fn ($join) => $join->on('unit_operation.id', '=', 'demand.target_id')->where('demand.target_type', 'unit_operation'))
            ->leftJoin('erp_production_units as production_unit', 'production_unit.id', '=', 'unit_operation.production_unit_id')
            ->whereIn('demand.work_order_id', $visibleWorkOrders)
            ->whereIn('work_order.status', ['RELEASED', 'IN_PROGRESS'])
            ->where(function ($q): void {
                $q->where(fn ($r) => $r->where('supply.supply_mode_snapshot', 'dedicated_delivery')->where('supply.requires_delivery_snapshot', true))
                    ->orWhereIn('item.cutting_mode', ['sheet', 'length'])->orWhere('item.is_length_cut_material', true);
            })
            ->orderBy('demand.id')
            ->select([
                'demand.id', 'demand.work_order_id', 'work_order.work_order_no',
                'work_order.business_version as work_order_version', 'work_order.production_batch as production_batch_no',
                'output.item_name as output_item_name', 'output.item_code as output_item_code',
                'production_unit.unit_no as production_unit_no',
                'requirement.configuration_id', 'item.category_id',
                'supply.target_routing_operation_id_snapshot as target_routing_operation_id',
                'supply.target_operation_code_snapshot as target_operation_code',
                'supply.target_operation_name_snapshot as target_operation_name',
                'demand.target_type as production_target_type', 'demand.target_id as production_target_id',
                'demand.material_requirement_id', 'demand.component_item_id',
                'demand.cut_length_mm_snapshot', 'demand.required_piece_qty_snapshot',
                'item.item_code', 'item.item_name', 'item.spec',
                'demand.required_base_qty as required_qty', 'demand.satisfied_base_qty',
                'demand.returned_base_qty', 'requirement.base_unit_name_snapshot as unit_name',
                'demand.status', 'demand.business_version', 'demand.created_at', 'demand.updated_at',
            ])
            ->selectRaw("{$remainingSql} as remaining_to_prepare")
            ->selectRaw("CASE WHEN item.cutting_mode IN ('sheet','length') OR item.is_length_cut_material = 1 THEN 'onsite_cutting' ELSE 'delivery' END as fulfillment_mode");

        if (! empty($filters['status'])) {
            $query->where('demand.status', (string) $filters['status']);
        } else {
            $query->whereRaw("{$remainingSql} > 0.00000001");
        }
        if (! empty($filters['work_order_id'])) $query->where('demand.work_order_id', (int) $filters['work_order_id']);
        if (! empty($filters['target_routing_operation_id'])) {
            $query->where('supply.target_routing_operation_id_snapshot', (int) $filters['target_routing_operation_id']);
        }
        if (! empty($filters['production_target_type'])) $query->where('demand.target_type', $filters['production_target_type']);
        if (! empty($filters['production_target_id'])) $query->where('demand.target_id', (int) $filters['production_target_id']);
        if (trim((string) ($filters['keyword'] ?? '')) !== '') {
            $keyword = '%'.trim((string) $filters['keyword']).'%';
            $query->where(function ($nested) use ($keyword): void {
                $nested->where('work_order.work_order_no', 'like', $keyword)
                    ->orWhere('item.item_code', 'like', $keyword)
                    ->orWhere('item.item_name', 'like', $keyword)
                    ->orWhere('item.spec', 'like', $keyword);
            });
        }

        return $query;
    }

    public function showPickingTask(int $id, object $user, array $permissions, bool $superAdmin, array $lineFilters = []): MaterialPickingTask
    {
        $this->permission($permissions, 'production.material_picking.view');
        $query = MaterialPickingTask::with($lineFilters ? array_values(array_filter($this->pickingRelations(), fn ($r) => ! in_array($r, ['inventoryTransaction.items', 'deliveries'], true))) : $this->pickingRelations());
        if ($lineFilters) $query->with(['lines' => fn ($q) => $q->orderBy('id')->offset(((int) $lineFilters['page'] - 1) * (int) $lineFilters['per_page'])->limit((int) $lineFilters['per_page'])]);
        $task = $query->find($id);
        if (! $task) $this->fail('not_found', '配料任务不存在。', 404);
        $this->visible($task->workOrder, $user, 'production.material_picking.view', $permissions, $superAdmin);
        $task->setAttribute('assigned_picker_name', $this->personName($task->assigned_picker_legacy_id));
        $task->setAttribute('allowed_actions', array_values(array_filter([
            $task->status === 'WAIT_PICK' ? 'picking.assign' : null,
            $task->status === 'WAIT_PICK' && $task->assigned_picker_legacy_id ? 'picking.start' : null,
            $task->status === 'PICKING' ? 'picking.confirm' : null,
            in_array($task->status, ['WAIT_PICK', 'PICKING'], true) ? 'picking.cancel' : null,
            in_array($task->status, ['PICKED', 'WAIT_DELIVERY', 'DELIVERING', 'DELIVERED', 'PARTIALLY_RECEIVED'], true) ? 'delivery.create' : null,
        ], fn ($action) => $action && in_array(WarehouseActionService::PERMISSIONS[$action], $permissions, true)
            && (! $task->public_preparation_task_id || ! str_starts_with($action, 'picking.'))
            && ($action !== 'delivery.create' || $task->lines()->where('fulfillment_mode_snapshot', '<>', 'onsite_cutting')->where('actual_pick_qty', '>', 0)->exists()))));
        if ($lineFilters) $task->setAttribute('line_meta', $this->lineMeta($task->lines()->count(), $lineFilters));
        $allocated = DB::table('erp_material_delivery_lines as line')->join('erp_material_deliveries as delivery', 'delivery.id', '=', 'line.delivery_id')
            ->where('delivery.picking_task_id', $task->id)->whereIn('line.picking_task_line_id', $task->lines->pluck('id'))->where('delivery.status', '<>', 'CANCELLED')
            ->where('delivery.delivery_type', '<>', 'redelivery')->get(['line.picking_task_line_id', 'line.delivery_qty', 'line.serial_snapshot']);
        foreach ($task->lines as $line) {
            $rows = $allocated->where('picking_task_line_id', $line->id);
            $line->setAttribute('allocated_delivery_qty', (float) $rows->sum('delivery_qty'));
            $line->setAttribute('remaining_delivery_qty', $line->fulfillment_mode_snapshot === 'onsite_cutting' ? 0 : max(0, (float) $line->actual_pick_qty - (float) $rows->sum('delivery_qty')));
            $line->setAttribute('remaining_onsite_qty', $line->fulfillment_mode_snapshot === 'onsite_cutting' ? max(0, (float) $line->actual_pick_qty - (float) $line->received_qty) : 0);
            $used = $rows->flatMap(fn ($row) => json_decode($row->serial_snapshot ?: '{}', true)['inventory_serial_ids'] ?? [])->all();
            $line->setAttribute('available_delivery_serial_ids', array_values(array_diff($line->serial_snapshot['inventory_serial_ids'] ?? [], $used)));
        }
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
                if (! in_array($workOrder->status, [WorkOrderApplicationService::RELEASED, 'IN_PROGRESS'], true)) {
                    $this->fail('invalid_state', '只有已发布工单可以创建配料任务。');
                }
                $warehouseId = (int) ($payload['warehouse_id'] ?? 0);
                $rows = $this->resolvePickingRows(collect($payload['lines'] ?? []), $workOrder);
                if ($warehouseId <= 0 || $rows->isEmpty()) $this->fail('validation_error', '仓库和配料明细不能为空。');
                // Split a demand across sources without weakening its aggregate quantity limit.
                $sourceKeys = $rows->map(fn ($row) => implode(':', [
                    $row['material_requirement_id'] ?? 0, $row['material_supply_rule_snapshot_id'] ?? 0,
                    $row['production_target_type'] ?? '', $row['production_target_id'] ?? 0,
                    $row['inventory_balance_id'] ?? 0,
                ]));
                if ($sourceKeys->duplicates()->isNotEmpty()) {
                    $this->fail('duplicate_requirement', '同一物料需求与生产目标下的库存来源不能重复选择。');
                }

                $requirementIds = $rows->pluck('material_requirement_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
                $requirements = WorkOrderMaterialRequirement::whereIn('id', $requirementIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($requirements->count() !== count($requirementIds) || $requirements->contains(fn ($row) => (int) $row->work_order_id !== (int) $workOrder->id)) {
                    $this->fail('requirement_invalid', '配料任务只能引用当前已发布工单的正式物料需求。');
                }
                $balances = InventoryBalance::with(['item', 'warehouse', 'location'])->whereIn('id', $rows->pluck('inventory_balance_id'))
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                // 本厂按固定工序配送；地点摘要取冻结供料工序，不能要求工单再次填写。
                // 多工序配料保留行级接收目标，配送单再按本次实际目标工序带出。
                $destinations = DB::table('erp_work_order_material_supply_rules')->where('work_order_id', $workOrder->id)
                    ->whereIn('id', $rows->pluck('material_supply_rule_snapshot_id'))->orderBy('id')->lockForUpdate()
                    ->pluck('target_operation_name_snapshot')->filter()->unique()->values();
                $destination = $destinations->implode('、');
                if (mb_strlen($destination) > 160) $destination = $destinations->count().'个工序（详见明细）';

                $task = MaterialPickingTask::create([
                    'task_no' => 'TMP-'.bin2hex(random_bytes(12)), 'work_order_id' => $workOrder->id,
                    'status' => 'WAIT_PICK', 'warehouse_id' => $warehouseId,
                    'organization_code' => $workOrder->organization_code,
                    'production_location_name_snapshot' => $destination,
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
                    if (($supply->supply_mode_snapshot !== 'dedicated_delivery' || ! $supply->requires_delivery_snapshot) && ! $this->onsiteMaterial((int) $requirement->component_item_id)) {
                        $this->fail('per_order_delivery_not_required', '线边常备或无需逐单配送的物料不能生成逐单配料配送任务。');
                    }
                    $targetType = (string) ($row['production_target_type'] ?? '');
                    $targetId = (int) ($row['production_target_id'] ?? 0);
                    $targetRequirement = DB::table('erp_production_target_material_requirements')
                        ->where('target_type', $targetType)->where('target_id', $targetId)
                        ->where('material_supply_rule_snapshot_id', $supply->id)->lockForUpdate()->first();
                    if (! $targetRequirement) $this->fail('production_target_invalid', '配料明细没有匹配的生产执行目标物料需求。');
                    $balance = $balances->get((int) ($row['inventory_balance_id'] ?? 0));
                    if (! $balance || (int) $balance->item_id !== (int) $requirement->component_item_id || (int) $balance->warehouse_id !== $warehouseId) {
                        $this->fail('inventory_batch_invalid', '配料明细必须选择当前仓库中该物料的真实库存批次。');
                    }
                    $planned = $this->quantity($row['planned_pick_qty'] ?? null, 'planned_pick_qty');
                    $lotConfigurationId = $balance->material_lot_id
                        ? DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->value('configuration_id') : null;
                    if ((int) $requirement->configuration_id !== (int) $lotConfigurationId) {
                        $this->fail('material_configuration_mismatch', '库存批次配置与工单已确认用料配置不一致。');
                    }
                    if (! $this->pickingStock->eligible($balance, $requirement)) {
                        $this->fail('inventory_source_ineligible', '所选库存来源已停用、过期或不符合该物料需求。');
                    }
                    $available = $this->pickingStock->available($balance, null, [(int) $requirement->id]);
                    if ($planned > $available + 0.00000001) {
                        $this->fail('inventory_changed', '库存已变化，请调整后重试。', 409,
                            ['inventory_balance_id' => $balance->id, 'available_qty' => $available]);
                    }
                    $reserved = (float) MaterialPickingTaskLine::query()
                        ->join('erp_material_picking_tasks as tasks', 'tasks.id', '=', 'erp_material_picking_task_lines.task_id')
                        ->where('erp_material_picking_task_lines.material_requirement_id', $requirement->id)
                        ->whereIn('tasks.status', ['WAIT_PICK', 'PICKING'])->sum('erp_material_picking_task_lines.planned_pick_qty');
                    $remaining = (float) $requirement->required_qty - (float) $requirement->picked_qty - $reserved;
                    if ($planned > $remaining + 0.00000001) {
                        $this->fail('pick_quantity_exceeded', '计划配料量超过该正式需求的剩余可配数量。', 422, ['remaining_to_pick' => max(0, $remaining)]);
                    }
                    $targetRemaining = $this->targetRemainingToPrepare($targetRequirement);
                    if ($planned > $targetRemaining + 0.00000001) {
                        $this->fail('target_prepare_quantity_exceeded', '计划配料量超过该系统待准备需求的剩余数量。', 422, [
                            'remaining_to_prepare' => max(0, $targetRemaining),
                        ]);
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
                        'fulfillment_mode_snapshot' => $this->onsiteMaterial((int) $requirement->component_item_id) ? 'onsite_cutting' : 'delivery',
                        'required_qty_snapshot' => $requirement->required_qty, 'planned_pick_qty' => $planned,
                        'actual_pick_qty' => 0, 'delivered_qty' => 0, 'received_qty' => 0,
                        'unit_id' => $requirement->unit_id, 'unit_name_snapshot' => $requirement->unit_name_snapshot,
                        'inventory_balance_id' => $balance->id, 'warehouse_id' => $balance->warehouse_id,
                        'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no,
                        'serial_control_type' => $balance->item?->serialTrackingMode() ?? 'none',
                        'serial_snapshot' => $serialIds === [] ? null : ['inventory_serial_ids' => $serialIds],
                        'status' => 'WAIT_PICK', 'business_version' => 1,
                    ]);
                    $this->refreshPreparationStatus((int) $targetRequirement->id);
                }
                $this->event('picking_task', $task->id, 'create', null, 'WAIT_PICK', 0, 1, null, $payload['remark'] ?? null, $user);
                return $task->fresh($this->pickingRelations());
            });
    }

    public function assignPickingTask(int $id, array $payload, object $user, array $permissions, bool $superAdmin, bool $fromPublicTask = false): MaterialPickingTask
    {
        $this->permission($permissions, 'production.material_picking.assign');
        $this->standaloneTask($id, $fromPublicTask);
        return $this->taskTransition($id, $payload, $user, $permissions, $superAdmin, ['WAIT_PICK'], 'WAIT_PICK', 'assign', function (MaterialPickingTask $task) use ($payload): void {
            $picker = (int) ($payload['assigned_picker_legacy_id'] ?? 0);
            if ($picker <= 0 || ! DB::table('erp_legacy_admin_users')->where('legacy_id', $picker)->where('status', 'normal')->exists()) {
                $this->fail('picker_invalid', '指定拣货人不存在或已停用。');
            }
            $task->assigned_picker_legacy_id = $picker;
        });
    }

    public function claimPickingTask(int $id, array $payload, object $user, array $permissions, bool $superAdmin, bool $fromPublicTask = false): MaterialPickingTask
    {
        $this->permission($permissions, 'production.material_picking.pick');
        $this->standaloneTask($id, $fromPublicTask);
        return $this->taskTransition($id, $payload, $user, $permissions, $superAdmin, ['WAIT_PICK'], 'WAIT_PICK', 'claim', function ($task) use ($user): void {
            if ($task->assigned_picker_legacy_id && (int) $task->assigned_picker_legacy_id !== $this->userId($user)) $this->fail('picker_mismatch', '该配料任务已由其他人领取。', 409);
            $task->assigned_picker_legacy_id = $this->userId($user);
        });
    }

    public function startPickingTask(int $id, array $payload, object $user, array $permissions, bool $superAdmin, bool $fromPublicTask = false): MaterialPickingTask
    {
        $this->permission($permissions, 'production.material_picking.pick');
        $this->standaloneTask($id, $fromPublicTask);
        return $this->taskTransition($id, $payload, $user, $permissions, $superAdmin, ['WAIT_PICK'], 'PICKING', 'start', function (MaterialPickingTask $task): void {
            if (! $task->assigned_picker_legacy_id) $this->fail('picker_missing', '请先分配拣货人。');
        });
    }

    public function confirmPickingTask(int $id, array $payload, object $user, array $permissions, bool $superAdmin, bool $fromPublicTask = false): MaterialPickingTask
    {
        $this->permission($permissions, 'production.material_picking.pick');
        $this->standaloneTask($id, $fromPublicTask);
        return $this->command('confirm_picking', 'picking_task', $id, $payload, $user,
            function () use ($id, $payload, $user, $permissions, $superAdmin): MaterialPickingTask {
                $task = MaterialPickingTask::with(['lines.componentItem', 'workOrder'])->lockForUpdate()->find($id);
                if (! $task) $this->fail('not_found', '配料任务不存在。', 404);
                $this->visible($task->workOrder, $user, 'production.material_picking.view', $permissions, $superAdmin);
                $this->version($task, $payload);
                if ($task->status !== 'PICKING') $this->fail('invalid_state', '只有拣货中的任务可以确认拣货。');
                $actualRows = collect($payload['lines'] ?? [])->keyBy(fn ($row) => (int) ($row['picking_task_line_id'] ?? 0));
                if ($actualRows->isEmpty()) $this->fail('validation_error', '实拣明细不能为空。');
                if ($actualRows->count() !== count($payload['lines']) || $actualRows->keys()->diff($task->lines->pluck('id'))->isNotEmpty())
                    $this->fail('validation_error', '实拣明细重复或不属于当前配料任务。');
                $quantitySnapshot = [];
                foreach ($task->lines as $line) {
                    $row = $actualRows->get($line->id);
                    $actual = $row ? $this->quantity($row['actual_pick_qty'] ?? null, 'actual_pick_qty', true) : 0.0;
                    if ($actual > (float) $line->planned_pick_qty + 0.00000001) $this->fail('pick_quantity_exceeded', '实拣数量不能超过计划配料数量。');
                    if ($actual > 0 && isset($row['serial_ids'])) {
                        $line->serial_snapshot = ['inventory_serial_ids' => array_values(array_unique(array_map('intval', (array) $row['serial_ids'])))];
                    }
                    if ($actual > 0 && array_key_exists('physical_material_ids', $row)) {
                        $ids = array_map('intval', (array) $row['physical_material_ids']);
                        if ($line->componentItem?->materialManagementMode() !== 'physical' || count($ids) !== count(array_unique($ids)) || count($ids) !== (int) $actual)
                            $this->fail('physical_selection_invalid', '所选实物必须与当前物料和实拣整张数量一致。');
                        $line->serial_snapshot = array_merge($line->serial_snapshot ?? [], ['physical_material_ids' => $ids]);
                    }
                    $line->actual_pick_qty = $actual;
                    $line->status = $actual > 0 ? 'PICKED' : 'UNPICKED';
                    $line->business_version++;
                    $line->save();
                    if ($actual > 0) $quantitySnapshot[] = ['line_id' => $line->id, 'actual_pick_qty' => $actual];
                }
                if ($quantitySnapshot === []) $this->fail('validation_error', '确认拣货至少需要一条大于 0 的实拣数量。');
                foreach ($task->lines->groupBy('inventory_balance_id')->sortKeys() as $balanceId => $sourceLines) {
                    $balance = InventoryBalance::with(['item', 'warehouse', 'location'])->whereKey($balanceId)->lockForUpdate()->first();
                    $required = WorkOrderMaterialRequirement::findOrFail($sourceLines->first()->material_requirement_id);
                    if (! $balance || ! $this->pickingStock->eligible($balance, $required)
                        || $sourceLines->sum('actual_pick_qty') > $this->pickingStock->available($balance, $task->id, $sourceLines->pluck('material_requirement_id')->map(fn ($id) => (int) $id)->all()) + 0.00000001) {
                        $this->fail('inventory_changed', '库存已变化，请调整实拣数量后重试。', 409, ['inventory_balance_id' => $balanceId]);
                    }
                }
                app(AssemblyProductionInventoryService::class)->consumePicking($task);
                $transaction = $this->inventory->postProductionMaterialPicking($task, $user);
                $this->materialCosts->recordPickingOutbound($task, $transaction);
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
                foreach ($task->lines as $line) {
                    $demandId = DB::table('erp_production_target_material_requirements')->where('target_type', $line->production_target_type)
                        ->where('target_id', $line->production_target_id)->where('material_supply_rule_snapshot_id', $line->material_supply_rule_snapshot_id)->value('id');
                    if ($demandId) $this->refreshPreparationStatus((int) $demandId);
                }
                $this->event('picking_task', $task->id, 'confirm', 'PICKING', 'PICKED', $beforeVersion, $task->business_version, $quantitySnapshot, $payload['reason'] ?? null, $user);
                return $task->fresh($this->pickingRelations());
            });
    }

    public function cancelPickingTask(int $id, array $payload, object $user, array $permissions, bool $superAdmin, bool $fromPublicTask = false): MaterialPickingTask
    {
        $this->permission($permissions, 'production.material_picking.cancel');
        $this->standaloneTask($id, $fromPublicTask);
        return $this->taskTransition($id, $payload, $user, $permissions, $superAdmin, ['WAIT_PICK', 'PICKING', 'PICKED'], 'CANCELLED', 'cancel', function (MaterialPickingTask $task) use ($payload): void {
            if ($task->inventory_transaction_id) $this->fail('reverse_required', '该任务已产生正式库存事实，不能直接取消，必须走逆向业务。', 409);
            if (trim((string) ($payload['reason'] ?? '')) === '') $this->fail('reason_required', '取消原因不能为空。');
            $task->lines()->update(['status' => 'CANCELLED']);
        });
    }

    public function paginateDeliveries(array $filters, object $user, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->permission($permissions, 'production.material_delivery.view');
        $query = MaterialDelivery::query()->with(['workOrder.outputItem', 'pickingTask'])->withCount(['lines',
            'lines as material_items_count' => fn ($q) => $q->select(DB::raw('COUNT(DISTINCT component_item_id)')),
            'lines as rejected_lines_count' => fn ($q) => $q->where('rejected_qty', '>', 0)])->orderByDesc('id');
        $this->applyWorkOrderRelationScope($query, 'workOrder', $user, 'production.material_delivery.view', $permissions, $superAdmin);
        if (($filters['status_group'] ?? null) === 'pending_dispatch') $query->whereIn('status', ['READY', 'IN_TRANSIT']);
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['work_order_id'])) $query->where('work_order_id', (int) $filters['work_order_id']);
        $this->searchDocuments($query, $filters, 'delivery_no');
        $page = $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
        foreach ($page->items() as $delivery) $delivery->setAttribute('delivery_user_name', $this->personName($delivery->delivery_user_legacy_id));
        return $page;
    }

    public function showDelivery(int $id, object $user, array $permissions, bool $superAdmin, array $lineFilters = []): MaterialDelivery
    {
        $this->permission($permissions, 'production.material_delivery.view');
        $query = MaterialDelivery::with($this->deliveryRelations());
        if ($lineFilters) $query->with(['lines' => fn ($q) => $q->orderBy('id')->offset(((int) $lineFilters['page'] - 1) * (int) $lineFilters['per_page'])->limit((int) $lineFilters['per_page'])]);
        $delivery = $query->find($id);
        if (! $delivery) $this->fail('not_found', '配送单不存在。', 404);
        $this->visible($delivery->workOrder, $user, 'production.material_delivery.view', $permissions, $superAdmin);
        if ($lineFilters) $delivery->setAttribute('line_meta', $this->lineMeta($delivery->lines()->count(), $lineFilters));
        $person = DB::table('erp_legacy_admin_users')->where('legacy_id', $delivery->delivery_user_legacy_id)->first(['nickname', 'username']);
        $delivery->setAttribute('delivery_user_name', $person?->nickname ?: $person?->username);
        $unit = $delivery->production_target_type === 'unit_operation'
            ? \App\Models\Erp\ProductionUnitOperation::with('productionUnit')->find($delivery->production_target_id)?->productionUnit : null;
        $delivery->setAttribute('production_unit_no', $unit?->unit_no);
        $delivery->setAttribute('expected_receiver_name', $this->personName($this->expectedReceiver($delivery)));
        $receiver = $this->expectedReceiver($delivery);
        $receiptScope = $this->scopeResolver->resolve($user, 'production.material_receipt.view', $permissions, $superAdmin);
        $canReceive = $delivery->status === 'DELIVERED' && $receiver === $this->userId($user)
            && $this->scopeResolver->workOrderVisible($delivery->workOrder, $receiptScope);
        $hasRemainingRedelivery = $delivery->lines()->whereRaw('rejected_qty > COALESCE((SELECT SUM(resent.delivery_qty)
            FROM erp_material_delivery_lines resent JOIN erp_material_deliveries redelivery ON redelivery.id=resent.delivery_id
            WHERE redelivery.source_delivery_id=? AND redelivery.delivery_type=? AND redelivery.status<>?
            AND resent.picking_task_line_id=erp_material_delivery_lines.picking_task_line_id),0)', [$delivery->id, 'redelivery', 'CANCELLED'])->exists();
        $delivery->setAttribute('has_remaining_redelivery', $hasRemainingRedelivery);
        $delivery->setAttribute('allowed_actions', array_values(array_filter([
            $delivery->status === 'READY' ? 'delivery.dispatch' : null,
            $delivery->status === 'IN_TRANSIT' ? 'delivery.deliver' : null,
            $delivery->status === 'READY' ? 'delivery.cancel' : null,
            $canReceive ? 'delivery.receive' : null,
            $hasRemainingRedelivery ? 'delivery.create' : null,
        ], fn ($action) => $action && in_array(WarehouseActionService::PERMISSIONS[$action], $permissions, true))));
        foreach ($delivery->lines as $line) {
            $resentLines = DB::table('erp_material_delivery_lines as line')->join('erp_material_deliveries as delivery', 'delivery.id', '=', 'line.delivery_id')
                ->where('delivery.source_delivery_id', $delivery->id)->where('delivery.delivery_type', 'redelivery')
                ->where('delivery.status', '<>', 'CANCELLED')->where('line.picking_task_line_id', $line->picking_task_line_id)->get(['line.delivery_qty', 'line.serial_snapshot']);
            $resent = $resentLines->sum('delivery_qty');
            $rejectedIds = $delivery->receipts->flatMap(fn ($receipt) => $receipt->lines)->where('delivery_line_id', $line->id)
                ->flatMap(fn ($receiptLine) => $receiptLine->rejected_serial_snapshot['inventory_serial_ids'] ?? [])->all();
            $allocatedIds = $resentLines->flatMap(fn ($resentLine) => json_decode($resentLine->serial_snapshot ?: '{}', true)['inventory_serial_ids'] ?? [])->all();
            $line->setAttribute('available_redelivery_serial_ids', array_values(array_diff($rejectedIds, $allocatedIds)));
            $line->setAttribute('redelivered_qty', (float) $resent);
            $line->setAttribute('remaining_redelivery_qty', max(0, (float) $line->rejected_qty - (float) $resent));
            $line->setAttribute('reject_reasons', $delivery->receipts->flatMap(fn ($receipt) => $receipt->lines)->where('delivery_line_id', $line->id)->pluck('reject_reason')->filter()->values());
        }
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
                if (! in_array($task->status, ['PICKED', 'WAIT_DELIVERY', 'DELIVERING', 'DELIVERED', 'PARTIALLY_RECEIVED'], true)) {
                    $this->fail('invalid_state', '当前配料任务状态不能创建配送单。');
                }
                $rows = collect($payload['lines'] ?? []);
                if ($rows->isEmpty()) $this->fail('validation_error', '配送明细不能为空。');
                $deliveryType = (string) ($payload['delivery_type'] ?? 'standard');
                $sourceDelivery = null;
                if ($deliveryType === 'redelivery') {
                    $sourceDelivery = MaterialDelivery::with(['lines', 'receipts.lines'])->lockForUpdate()->find((int) ($payload['source_delivery_id'] ?? 0));
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
                if ($pickLines->contains(fn ($line) => $line->fulfillment_mode_snapshot === 'onsite_cutting')) {
                    $this->fail('onsite_material_not_deliverable', '下料板材和长料在现场领料，无需创建配送单。');
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
                    'to_production_location_snapshot' => $firstPickLine->target_operation_name_snapshot ?: $task->production_location_name_snapshot,
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
                        ->where('deliveries.status', '<>', 'CANCELLED')->where('deliveries.delivery_type', '<>', 'redelivery')->sum('erp_material_delivery_lines.delivery_qty');
                    if ($quantity > (float) $pickLine->actual_pick_qty - $allocated + 0.00000001) {
                        $this->fail('delivery_quantity_exceeded', '配送数量不能超过该配料行尚未分配的已拣数量。');
                    }
                    }
                    $serialIds = array_values(array_unique(array_map('intval', (array) ($row['serial_ids'] ?? []))));
                    $availableSerialIds = array_values(array_map('intval', (array) (($pickLine->serial_snapshot ?? [])['inventory_serial_ids'] ?? [])));
                    if ($deliveryType === 'redelivery') {
                        $sourceLineId = $sourceDelivery->lines->firstWhere('picking_task_line_id', $pickLine->id)?->id;
                        $availableSerialIds = $sourceDelivery->receipts->flatMap(fn ($receipt) => $receipt->lines)
                            ->where('delivery_line_id', $sourceLineId)->flatMap(fn ($row) => $row->rejected_serial_snapshot['inventory_serial_ids'] ?? [])
                            ->map(fn ($id) => (int) $id)->unique()->values()->all();
                    }
                    if ($serialIds && array_diff($serialIds, $availableSerialIds)) {
                        $this->fail('delivery_serial_invalid', '配送任务行的序列号切片必须来自该正式配料行。');
                    }
                    $allocatedSerialIds = MaterialDeliveryLine::query()
                        ->where('picking_task_line_id', $pickLine->id)
                        ->whereHas('delivery', fn ($query) => $query->where('status', '<>', 'CANCELLED'))
                        ->when($deliveryType === 'redelivery', fn ($query) => $query->whereHas('delivery', fn ($d) => $d->where('source_delivery_id', $sourceDelivery->id)))
                        ->get()->flatMap(fn (MaterialDeliveryLine $line) => (array) (($line->serial_snapshot ?? [])['inventory_serial_ids'] ?? []))
                        ->map(fn ($id) => (int) $id)->unique()->all();
                    if (array_intersect($serialIds, $allocatedSerialIds)) {
                        $this->fail('delivery_serial_already_allocated', '同一序列号不能切片给多个配送执行任务。', 409);
                    }
                    if ($pickLine->serial_control_type !== 'none') {
                        if (count($serialIds) !== (int) $quantity || abs($quantity - (int) $quantity) > 0.00000001) {
                            $this->fail('delivery_serial_quantity_mismatch', '序列管理物料的配送数量必须与本任务序列号切片数量一致。');
                        }
                    }
                    MaterialDeliveryLine::create([
                        'delivery_id' => $delivery->id, 'material_requirement_id' => $pickLine->material_requirement_id,
                        'picking_task_line_id' => $pickLine->id, 'component_item_id' => $pickLine->component_item_id,
                        'delivery_qty' => $quantity, 'received_qty' => 0, 'rejected_qty' => 0,
                        'unit_id' => $pickLine->unit_id, 'unit_name_snapshot' => $pickLine->unit_name_snapshot,
                        'batch_no' => $pickLine->batch_no,
                        'serial_snapshot' => $serialIds === [] ? null : ['inventory_serial_ids' => $serialIds],
                    ]);
                }
                $this->event('delivery', $delivery->id, 'create', null, 'READY', 0, 1, null, $payload['remark'] ?? null, $user);
                $this->refreshPickingDeliveryState($task->id, $user);
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
            // Re-dispatch the exact rejected serials without another inventory deduction.
            // Keep READY/cancelled redeliveries rejected until they actually depart.
            if ($delivery->delivery_type === 'redelivery') {
                $ids = $delivery->lines->flatMap(fn ($line) => $line->serial_snapshot['inventory_serial_ids'] ?? [])->all();
                $serials = InventorySerial::whereIn('id', $ids)->where('serial_status', 'production_rejected')->lockForUpdate()->get();
                if ($serials->count() !== count($ids)) $this->fail('serial_state_conflict', '补送序列号已被其他操作处理，请刷新后重试。', 409);
                foreach ($serials as $serial) {
                    $serial->update(['serial_status' => 'production_in_transit']);
                    InventorySerialEvent::create([
                        'inventory_serial_id' => $serial->id, 'event_type' => 'production_material_redelivery',
                        'document_type' => 'material_delivery', 'document_id' => $delivery->id,
                        'document_no' => $delivery->delivery_no, 'from_status' => 'production_rejected',
                        'to_status' => 'production_in_transit', 'warehouse_id' => $serial->warehouse_id,
                        'location_id' => $serial->location_id, 'batch_no' => $serial->batch_no,
                        'occurred_at' => now(),
                    ]);
                }
            }
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
                $expectedReceiver = $this->expectedReceiver($delivery);
                if (! $expectedReceiver) $this->fail('production_target_unclaimed', '生产目标尚未接单，不能确认物料责任交接。', 409);
                if ((int) $expectedReceiver !== $this->userId($user)) {
                    $this->fail('receiver_mismatch', '只有该生产目标当前责任人可以确认收料。', 403);
                }
                if (! $delivery->expected_receiver_legacy_id) $delivery->expected_receiver_legacy_id = (int) $expectedReceiver;
                $rows = collect($payload['lines'] ?? [])->keyBy(fn ($row) => (int) ($row['delivery_line_id'] ?? 0));
                if ($rows->isEmpty()) $this->fail('validation_error', '收料明细不能为空。');
                if ($rows->count() !== count($payload['lines']) || $rows->keys()->diff($delivery->lines->pluck('id'))->isNotEmpty())
                    $this->fail('validation_error', '收料明细重复或不属于当前配送单。');
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
                    if ($accepted > 0) app(ItemManagementScopeService::class)->assertProductionAllowed(
                        Item::query()->lockForUpdate()->findOrFail($line->component_item_id), 'lines');
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
                    $serialReasons = [];
                    if (array_key_exists('rejected_serial_reasons', $row)) {
                        $submittedReasons = (array) $row['rejected_serial_reasons'];
                        if (array_diff(array_map('intval', array_keys($submittedReasons)), $rejectedSerials)
                            || count($submittedReasons) !== count($rejectedSerials)) $this->fail('serial_reject_reason_invalid', '逐件拒收原因必须与本次拒收序列号一一对应。');
                        foreach ($rejectedSerials as $serialId) {
                            $serialReason = trim((string) ($submittedReasons[$serialId] ?? ''));
                            if ($serialReason === '' || mb_strlen($serialReason) > 500) $this->fail('serial_reject_reason_required', '每个拒收序列号都必须填写不超过500字的原因。');
                            $serialReasons[$serialId] = $serialReason;
                        }
                    } else foreach ($rejectedSerials as $serialId) $serialReasons[$serialId] = $reason;
                    if ($line->pickingTaskLine->serial_control_type !== 'none'
                        && (count($acceptedSerials) !== (int) $accepted || count($rejectedSerials) !== (int) $rejected
                            || abs($accepted - (int) $accepted) > 0.00000001 || abs($rejected - (int) $rejected) > 0.00000001)) {
                        $this->fail('receipt_serial_quantity_mismatch', '收料及拒收数量必须分别与所选序列号数量一致。');
                    }
                    $receiptLine = MaterialReceipt::findOrFail($receipt->id)->lines()->create([
                        'delivery_line_id' => $line->id, 'component_item_id' => $line->component_item_id,
                        'delivered_qty_snapshot' => $line->delivery_qty, 'accepted_qty' => $accepted,
                        'rejected_qty' => $rejected, 'reject_reason' => $reason ?: null, 'unit_id' => $line->unit_id,
                        'accepted_serial_snapshot' => $acceptedSerials ? ['inventory_serial_ids' => $acceptedSerials] : null,
                        'rejected_serial_snapshot' => $rejectedSerials ? ['inventory_serial_ids' => $rejectedSerials, 'reasons' => $serialReasons] : null,
                    ]);
                    if ($accepted > 0) {
                        $this->materialCosts->recordDeliveryReceipt($delivery, $line, $receiptLine, $this->userId($user));
                    }
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
                            // Keep accepted and returned as separate monotonic facts. Net onsite
                            // availability is derived as accepted minus warehouse-received returns;
                            // capping accepted at required would make later replenishment unable to
                            // restore kitting after a return.
                            $satisfied = (float) $targetRequirement->satisfied_base_qty + $accepted;
                            $netSatisfied = max(0, $satisfied - (float) $targetRequirement->returned_base_qty);
                            DB::table('erp_production_target_material_requirements')->where('id', $targetRequirement->id)->update([
                                'satisfied_base_qty' => $satisfied,
                                'status' => $netSatisfied + 0.00000001 >= (float) $targetRequirement->required_base_qty ? 'SATISFIED' : 'PARTIALLY_SATISFIED',
                                'business_version' => (int) $targetRequirement->business_version + 1,
                                'updated_at' => now(),
                            ]);
                            $this->refreshPreparationStatus((int) $targetRequirement->id);
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
                // A rejected delivery line is settled for that delivery, but the picked
                // material is still outstanding until a redelivery is actually accepted.
                // Completing the picking task on delivery status alone would make the
                // redelivery route unreachable because createDelivery intentionally only
                // accepts an unfinished picking task.
                $this->refreshPickingDeliveryState($task->id, $user);
                if ($delivery->production_target_type && $delivery->production_target_id) {
                    $this->refreshTargetReadiness((string) $delivery->production_target_type, (int) $delivery->production_target_id);
                }
                $this->event('delivery', $delivery->id, 'receive', 'DELIVERED', $delivery->status, $beforeVersion, $delivery->business_version, $snapshot, $payload['remark'] ?? null, $user);
                return $receipt->fresh(['lines.deliveryLine', 'delivery', 'workOrder']);
            });
    }

    public function onsiteCollections(array $filters, object $user, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->permission($permissions, 'production.material_receipt.view');
        $scope = $this->scopeResolver->resolve($user, 'production.material_receipt.view', $permissions, $superAdmin);
        $visible = WorkOrder::query()->select('id');
        $this->scopeResolver->applyWorkOrderScope($visible, $scope);
        $query = DB::table('erp_material_picking_task_lines as line')
            ->join('erp_material_picking_tasks as picking', 'picking.id', '=', 'line.task_id')
            ->join('erp_work_orders as wo', 'wo.id', '=', 'picking.work_order_id')
            ->join('erp_items as item', 'item.id', '=', 'line.component_item_id')
            ->whereIn('picking.work_order_id', $visible)->where('line.fulfillment_mode_snapshot', 'onsite_cutting')
            ->whereNotIn('picking.status', ['WAIT_PICK', 'PICKING', 'CANCELLED'])
            ->whereColumn('line.actual_pick_qty', '>', 'line.received_qty')
            ->whereExists(function ($q) use ($user): void {
                $q->selectRaw('1')->from('erp_production_task_targets as target')
                    ->join('erp_production_tasks as task', 'task.id', '=', 'target.task_id')
                    ->whereColumn('target.target_type', 'line.production_target_type')->whereColumn('target.target_id', 'line.production_target_id')
                    ->where('task.assignee_user_legacy_id', $this->userId($user));
            });
        if (! empty($filters['production_target_type'])) $query->where('line.production_target_type', $filters['production_target_type']);
        if (! empty($filters['production_target_id'])) $query->where('line.production_target_id', (int) $filters['production_target_id']);
        if (! empty($filters['work_order_id'])) $query->where('picking.work_order_id', (int) $filters['work_order_id']);
        return $query->select('line.id', 'line.task_id', 'line.component_item_id', 'line.production_target_type', 'line.production_target_id',
            'line.actual_pick_qty', 'line.received_qty', 'line.unit_name_snapshot', 'line.target_operation_name_snapshot',
            'line.serial_control_type', 'item.item_code', 'item.item_name', 'item.spec', 'item.material_management_mode',
            'wo.work_order_no', 'picking.task_no', 'picking.business_version as task_version')
            ->selectRaw('line.actual_pick_qty - line.received_qty as remaining_qty')->orderBy('line.id')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
    }

    public function releaseUnpickedPublicTask(int $id, array $payload, object $user, array $permissions, bool $superAdmin, bool $fromPublicTask = false): MaterialPickingTask
    {
        $this->permission($permissions, 'production.material_picking.pick');
        if (! $fromPublicTask) $this->fail('public_preparation_required', '未实拣预留只能在公共配料任务中释放。', 409);
        return $this->taskTransition($id, $payload, $user, $permissions, $superAdmin, ['PICKING'], 'CANCELLED', 'release_unpicked', function ($task): void {
            if (! $task->public_preparation_task_id || $task->inventory_transaction_id) $this->fail('invalid_state', '已经出库的物料不能按未实拣释放。', 409);
        });
    }

    public function onsiteSources(string $kind, array $filters, object $user, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->permission($permissions, 'production.material_receipt.view');
        $line = MaterialPickingTaskLine::with('task.workOrder')->findOrFail((int) $filters['picking_task_line_id']);
        $this->visible($line->task->workOrder, $user, 'production.material_receipt.view', $permissions, $superAdmin);
        $owner = DB::table('erp_production_task_targets as target')->join('erp_production_tasks as task', 'task.id', '=', 'target.task_id')
            ->where('target.target_type', $line->production_target_type)->where('target.target_id', $line->production_target_id)->value('task.assignee_user_legacy_id');
        if ((int) $owner !== $this->userId($user) || $line->fulfillment_mode_snapshot !== 'onsite_cutting') $this->fail('receiver_mismatch', '只能选择本人生产目标的现场领料实物。', 403);
        if ($kind === 'serials') {
            $query = InventorySerial::query()->whereIn('id', $line->serial_snapshot['inventory_serial_ids'] ?? [])->where('serial_status', 'production_in_transit')
                ->select('id', 'serial_no', 'serial_status');
            if (! empty($filters['keyword'])) $query->where('serial_no', 'like', '%'.$filters['keyword'].'%');
            return $query->orderBy('id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
        }
        $query = DB::table('erp_material_physicals as physical')->join('erp_material_holdings as holding', 'holding.id', '=', 'physical.current_holding_id')
            ->join('erp_inventory_transaction_items as posted', 'posted.id', '=', 'holding.position_id')
            ->join('erp_inventory_transactions as tx', 'tx.id', '=', 'posted.transaction_id')
            ->where('holding.position_type', 'PRODUCTION_TRANSIT')->where('holding.status', 'ACTIVE')->where('physical.status', 'PRODUCTION_TRANSIT')
            ->where('tx.transaction_type', 'production_material_picking_outbound')->where('tx.source_type', 'material_picking_task')->where('tx.source_id', $line->task_id)
            ->where('posted.source_item_id', $line->id)->where('physical.item_id', $line->component_item_id);
        if (! empty($filters['keyword'])) $query->where('physical.physical_no', 'like', '%'.$filters['keyword'].'%');
        $page = $query->select('physical.id', 'physical.physical_no', 'physical.dimensions')->orderBy('physical.id')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
        $page->getCollection()->transform(function ($row) { $row->dimensions = json_decode($row->dimensions ?: '{}', true); return $row; });
        return $page;
    }

    public function receiveOnsite(int $id, array $payload, object $user, array $permissions, bool $superAdmin): MaterialReceipt
    {
        $this->permission($permissions, 'production.material_receipt.confirm');
        return app(ProductionMaterialCommandService::class)->run('onsite_material_receive', $payload, $user, function () use ($id, $payload, $user, $permissions, $superAdmin) {
            $task = MaterialPickingTask::with(['lines', 'workOrder'])->lockForUpdate()->findOrFail($id);
            $this->visible($task->workOrder, $user, 'production.material_receipt.view', $permissions, $superAdmin);
            $this->version($task, $payload);
            if (in_array($task->status, ['WAIT_PICK', 'PICKING', 'CANCELLED'], true)) $this->fail('invalid_state', '确认拣货后才能现场领料。', 409);
            $rows = collect($payload['lines'] ?? []);
            if ($rows->isEmpty() || $rows->pluck('picking_task_line_id')->unique()->count() !== $rows->count()) $this->fail('validation_error', '领料明细不能为空或重复。');
            $receipt = MaterialReceipt::create(['receipt_no' => 'TMP-'.bin2hex(random_bytes(12)), 'delivery_id' => null,
                'picking_task_id' => $task->id, 'collection_type' => 'onsite_cutting', 'work_order_id' => $task->work_order_id,
                'status' => 'CONFIRMED', 'received_by_legacy_id' => $this->userId($user), 'received_at' => now(),
                'remark' => $payload['remark'] ?? null, 'business_version' => 1]);
            $receipt->update(['receipt_no' => 'MRC'.now()->format('Ymd').str_pad((string) $receipt->id, 6, '0', STR_PAD_LEFT)]);
            foreach ($rows as $row) {
                $line = MaterialPickingTaskLine::where('task_id', $task->id)->where('id', $row['picking_task_line_id'])->lockForUpdate()->first();
                if (! $line || $line->fulfillment_mode_snapshot !== 'onsite_cutting') $this->fail('onsite_line_invalid', '现场领料只能引用本次已拣货的下料物料。');
                $target = DB::table('erp_production_task_targets as target')->join('erp_production_tasks as production_task', 'production_task.id', '=', 'target.task_id')
                    ->where('target.target_type', $line->production_target_type)->where('target.target_id', $line->production_target_id)
                    ->lockForUpdate()->first(['production_task.assignee_user_legacy_id']);
                if (! $target?->assignee_user_legacy_id || (int) $target->assignee_user_legacy_id !== $this->userId($user))
                    $this->fail('receiver_mismatch', '只有生产目标当前责任人可以确认现场领料。', 403);
                $qty = $this->quantity($row['accepted_qty'] ?? 0, 'accepted_qty');
                app(ItemManagementScopeService::class)->assertProductionAllowed(
                    Item::query()->lockForUpdate()->findOrFail($line->component_item_id), 'lines');
                if ($qty > (float) $line->actual_pick_qty - (float) $line->received_qty + 0.00000001) $this->fail('receipt_quantity_exceeded', '领料数量不能超过当前剩余数量。');
                $serials = array_map('intval', (array) ($row['accepted_serial_ids'] ?? []));
                $available = (array) (($line->serial_snapshot ?? [])['inventory_serial_ids'] ?? []);
                if (count($serials) !== count(array_unique($serials)) || array_diff($serials, $available)) $this->fail('serial_invalid', '领料序列号必须来自本次正式拣货。');
                if ($line->serial_control_type !== 'none' && (count($serials) !== (int) $qty || abs($qty - (int) $qty) > 0.00000001))
                    $this->fail('receipt_serial_quantity_mismatch', '领料数量必须与所选序列号数量一致。');
                $receiptLine = $receipt->lines()->create(['delivery_line_id' => null, 'picking_task_line_id' => $line->id,
                    'component_item_id' => $line->component_item_id, 'delivered_qty_snapshot' => $line->actual_pick_qty,
                    'accepted_qty' => $qty, 'rejected_qty' => 0, 'unit_id' => $line->unit_id,
                    'accepted_serial_snapshot' => $serials ? ['inventory_serial_ids' => $serials] : null]);
                $this->materialCosts->recordOnsiteReceipt($line, $receiptLine, $this->userId($user), $row['physical_material_ids'] ?? []);
                $this->updateReceivedSerials($serials, 'production_received', $receipt, $line);
                MaterialPickingTaskLine::whereKey($line->id)->incrementEach(['received_qty' => $qty, 'business_version' => 1]);
                $line->requirement()->lockForUpdate()->incrementEach(['received_qty' => $qty, 'business_version' => 1]);
                $demand = DB::table('erp_production_target_material_requirements')->where('target_type', $line->production_target_type)
                    ->where('target_id', $line->production_target_id)->where('material_supply_rule_snapshot_id', $line->material_supply_rule_snapshot_id)->lockForUpdate()->first();
                if (! $demand) $this->fail('production_receipt_target_requirement_missing', '缺少正式生产需求，不能现场领料。');
                DB::table('erp_production_target_material_requirements')->where('id', $demand->id)->incrementEach(['satisfied_base_qty' => $qty, 'business_version' => 1], ['updated_at' => now()]);
                $this->refreshPreparationStatus((int) $demand->id);
                $this->refreshTargetReadiness($line->production_target_type, (int) $line->production_target_id);
            }
            $before = $task->status;
            $this->refreshPickingDeliveryState($task->id, $user);
            $task->refresh();
            $this->event('picking_task', $task->id, 'onsite_receive', $before, $task->status, (int) $payload['expected_version'], $task->business_version, $payload['lines'], $payload['remark'] ?? null, $user);
            return $receipt->fresh(['lines', 'workOrder']);
        }, function ($receiptId) use ($user, $permissions, $superAdmin) {
            $receipt = MaterialReceipt::with(['lines', 'workOrder'])->findOrFail($receiptId);
            $this->visible($receipt->workOrder, $user, 'production.material_receipt.view', $permissions, $superAdmin);
            return $receipt;
        });
    }

    private function refreshTargetReadiness(string $targetType, int $targetId): void
    {
        $link = DB::table('erp_production_task_targets')->where('target_type', $targetType)
            ->where('target_id', $targetId)->lockForUpdate()->first();
        if (! $link) $this->fail('production_target_task_missing', '配送目标没有对应的正式生产任务。', 409);
        $task = ProductionTask::query()->lockForUpdate()->findOrFail($link->task_id);
        $model = match ($targetType) {
            'unit_operation' => ProductionUnitOperation::class,
            'quantity_operation' => ProductionQuantityOperation::class,
            default => $this->fail('production_target_type_invalid', '配送目标类型无效。', 409),
        };
        $target = $model::query()->lockForUpdate()->findOrFail($targetId);
        $this->targetReadiness->refresh($targetType, $target, $task, now());
    }

    public function cancelDelivery(int $id, array $payload, object $user, array $permissions, bool $superAdmin): MaterialDelivery
    {
        $this->permission($permissions, 'production.material_delivery.cancel');
        return $this->deliveryTransition($id, $payload, $user, $permissions, $superAdmin, 'READY', 'CANCELLED', 'cancel', function (MaterialDelivery $delivery) use ($payload): void {
            if (trim((string) ($payload['reason'] ?? '')) === '') $this->fail('reason_required', '取消配送单必须填写原因。');
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
                'cut_length_mm' => $row->cut_length_mm_snapshot === null ? null : (float) $row->cut_length_mm_snapshot,
                'required_piece_qty' => $row->required_piece_qty === null ? null : (float) $row->required_piece_qty,
                'cut_requirement' => $row->cut_length_mm_snapshot === null ? null : $this->cutDisplay($row->cut_length_mm_snapshot, $row->required_piece_qty),
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

    private function cutDisplay($length, $pieces): string
    {
        $lengthText = rtrim(rtrim(number_format((float) $length, 2, '.', ''), '0'), '.');
        $pieceText = rtrim(rtrim(number_format((float) $pieces, 8, '.', ''), '0'), '.');
        return "{$lengthText}mm × {$pieceText}段";
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
            if (in_array($action, ['cancel', 'release_unpicked'], true)) {
                foreach ($task->lines as $line) {
                    $targetRequirementId = DB::table('erp_production_target_material_requirements')
                        ->where('target_type', $line->production_target_type)
                        ->where('target_id', $line->production_target_id)
                        ->where('material_supply_rule_snapshot_id', $line->material_supply_rule_snapshot_id)
                        ->value('id');
                    if ($targetRequirementId) $this->refreshPreparationStatus((int) $targetRequirementId);
                }
            }
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
            $this->refreshPickingDeliveryState($delivery->picking_task_id, $user);
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

    private function updateReceivedSerials(array $ids, string $status, MaterialReceipt $receipt, MaterialDeliveryLine|MaterialPickingTaskLine $line): void
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
                'event_payload' => [$line instanceof MaterialDeliveryLine ? 'delivery_line_id' : 'picking_task_line_id' => $line->id], 'occurred_at' => now(),
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

    private function resolvePickingRows($rows, WorkOrder $workOrder)
    {
        return $rows->map(function ($row) use ($workOrder): array {
            $row = (array) $row;
            $demandId = (int) ($row['target_material_requirement_id'] ?? 0);
            if ($demandId <= 0) return $row;

            $demand = DB::table('erp_production_target_material_requirements as demand')
                ->join('erp_work_order_material_supply_rules as supply', 'supply.id', '=', 'demand.material_supply_rule_snapshot_id')
                ->where('demand.id', $demandId)
                ->where('demand.work_order_id', $workOrder->id)
                ->lockForUpdate()
                ->select('demand.*', 'supply.supply_mode_snapshot', 'supply.requires_delivery_snapshot')
                ->first();
            if (! $demand) $this->fail('preparation_demand_invalid', '系统待准备需求不存在或不属于当前工单。');
            if (($demand->supply_mode_snapshot !== 'dedicated_delivery' || ! $demand->requires_delivery_snapshot) && ! $this->onsiteMaterial((int) $demand->component_item_id)) {
                $this->fail('per_order_delivery_not_required', '工位常备或无需逐单配送的物料不能生成逐单配料配送任务。');
            }
            if ($demand->status === 'SATISFIED') $this->fail('preparation_demand_satisfied', '该系统待准备需求已满足，无需重复配料。');

            $derived = [
                'material_requirement_id' => (int) $demand->material_requirement_id,
                'material_supply_rule_snapshot_id' => (int) $demand->material_supply_rule_snapshot_id,
                'production_target_type' => (string) $demand->target_type,
                'production_target_id' => (int) $demand->target_id,
            ];
            foreach ($derived as $key => $value) {
                if (array_key_exists($key, $row) && (string) $row[$key] !== (string) $value) {
                    $this->fail('preparation_demand_mismatch', '配料明细与系统待准备需求不一致，禁止覆盖系统生产需求。');
                }
            }
            return array_merge($row, $derived);
        });
    }

    private function targetRemainingToPrepare(object $targetRequirement): float
    {
        $netSatisfied = max(0, (float) $targetRequirement->satisfied_base_qty - (float) $targetRequirement->returned_base_qty);
        $allocated = DB::table('erp_material_picking_task_lines as line')
            ->join('erp_material_picking_tasks as task', 'task.id', '=', 'line.task_id')
            ->where('line.production_target_type', $targetRequirement->target_type)
            ->where('line.production_target_id', $targetRequirement->target_id)
            ->where('line.material_supply_rule_snapshot_id', $targetRequirement->material_supply_rule_snapshot_id)
            ->where('task.status', '!=', 'CANCELLED')
            ->get(['task.status as task_status', 'line.planned_pick_qty', 'line.actual_pick_qty', 'line.received_qty'])
            ->sum(fn ($line): float => in_array($line->task_status, ['WAIT_PICK', 'PICKING'], true)
                ? (float) $line->planned_pick_qty
                : max(0, (float) $line->actual_pick_qty - (float) $line->received_qty));
        return max(0, (float) $targetRequirement->required_base_qty - $netSatisfied - $allocated);
    }

    private function refreshPreparationStatus(int $targetRequirementId): void
    {
        $demand = DB::table('erp_production_target_material_requirements')->where('id', $targetRequirementId)->lockForUpdate()->first();
        if (! $demand) return;
        $netSatisfied = max(0, (float) $demand->satisfied_base_qty - (float) $demand->returned_base_qty);
        $remaining = $this->targetRemainingToPrepare($demand);
        $required = (float) $demand->required_base_qty;
        $status = $netSatisfied + 0.00000001 >= $required
            ? 'SATISFIED'
            : ($remaining <= 0.00000001
                ? 'PREPARING'
                : ($remaining + $netSatisfied + 0.00000001 < $required
                    ? 'PARTIAL_PREPARING'
                    : ($netSatisfied > 0 ? 'PARTIALLY_SATISFIED' : 'WAIT_PREPARE')));
        if ($status === $demand->status) return;
        DB::table('erp_production_target_material_requirements')->where('id', $demand->id)->update([
            'status' => $status,
            'business_version' => (int) $demand->business_version + 1,
            'updated_at' => now(),
        ]);
    }

    private function refreshPickingDeliveryState(int $taskId, object $user): void
    {
        // Derive the whole task from all live deliveries; one completed/cancelled wave must
        // never hide another wave that is still on the road or material still to be sent.
        $task = MaterialPickingTask::with('lines')->lockForUpdate()->findOrFail($taskId);
        $statuses = $task->deliveries()->where('status', '<>', 'CANCELLED')->pluck('status');
        $allReceived = $task->lines->every(fn ($line) => (float) $line->received_qty + 0.00000001 >= (float) $line->actual_pick_qty);
        $anyReceived = $task->lines->contains(fn ($line) => (float) $line->received_qty > 0);
        $task->status = match (true) {
            $statuses->contains('IN_TRANSIT') => 'DELIVERING',
            $statuses->contains('DELIVERED') => 'DELIVERED',
            $statuses->contains('READY') => 'WAIT_DELIVERY',
            $allReceived && $anyReceived => 'RECEIVED',
            $anyReceived => 'PARTIALLY_RECEIVED',
            default => 'PICKED',
        };
        $task->business_version++;
        $task->updated_by_legacy_id = $this->userId($user);
        $task->save();
    }

    private function personName(?int $id): ?string
    {
        $person = $id ? DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->first(['nickname', 'username']) : null;
        return $person?->nickname ?: $person?->username;
    }

    private function standaloneTask(int $id, bool $fromPublicTask): void
    {
        if (! $fromPublicTask && MaterialPickingTask::whereKey($id)->whereNotNull('public_preparation_task_id')->exists())
            $this->fail('public_preparation_required', '此明细属于公共配料任务，请在公共任务中统一操作。', 409);
    }

    private function onsiteMaterial(int $itemId): bool
    {
        return DB::table('erp_items')->where('id', $itemId)->where(fn ($q) => $q->whereIn('cutting_mode', ['sheet', 'length'])->orWhere('is_length_cut_material', true))->exists();
    }

    private function searchDocuments($query, array $filters, string $number): void
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') $query->where(function ($q) use ($number, $keyword): void {
            $q->where($number, 'like', '%'.$keyword.'%')
                ->orWhereHas('workOrder', fn ($wo) => $wo->where('work_order_no', 'like', '%'.$keyword.'%')
                    ->orWhereHas('outputItem', fn ($item) => $item->where('item_name', 'like', '%'.$keyword.'%')->orWhere('item_code', 'like', '%'.$keyword.'%')));
            if ($number === 'delivery_no') $q->orWhereIn('delivery_user_legacy_id', DB::table('erp_legacy_admin_users')
                ->where(fn ($person) => $person->where('nickname', 'like', '%'.$keyword.'%')->orWhere('username', 'like', '%'.$keyword.'%'))->select('legacy_id'));
        });
    }

    private function sortPayload(array $payload): array
    {
        foreach ($payload as $key => $value) if (is_array($value)) $payload[$key] = $this->sortPayload($value);
        ksort($payload);
        return $payload;
    }

    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function lineMeta(int $total, array $filters): array
    { return ['total' => $total, 'current_page' => (int) $filters['page'], 'per_page' => (int) $filters['per_page'], 'last_page' => max(1, (int) ceil($total / (int) $filters['per_page']))]; }

    private function expectedReceiver(MaterialDelivery $delivery): int
    { return (int) ($delivery->expected_receiver_legacy_id ?: DB::table('erp_production_task_targets as target')
        ->join('erp_production_tasks as production_task', 'production_task.id', '=', 'target.task_id')
        ->where('target.target_type', $delivery->production_target_type)->where('target.target_id', $delivery->production_target_id)
        ->value('production_task.assignee_user_legacy_id')); }

    private function pickingRelations(): array { return ['workOrder.outputItem', 'warehouse', 'inventoryTransaction.items', 'lines.requirement', 'lines.componentItem', 'lines.inventoryBalance.location', 'deliveries']; }
    private function deliveryRelations(): array { return ['workOrder.outputItem', 'pickingTask.warehouse', 'lines.requirement', 'lines.pickingTaskLine.componentItem', 'lines.pickingTaskLine.inventoryBalance.location', 'receipts.lines']; }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never { throw new WorkOrderDomainException($code, $message, $status, $details); }
}
