<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Item;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionSerial;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnit;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Expands an immutable released work order into execution facts exactly once.
 * This runs in the publish transaction: moving it to a listener would expose a
 * RELEASED work order without its units/tasks and make retries non-deterministic.
 */
class ProductionExecutionFoundationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ProductionLaborAllocationRuleService $laborRules,
    ) {}

    public function policySnapshot(WorkOrder $workOrder): array
    {
        $item = Item::query()->with('activeMaterialPolicy')->lockForUpdate()->find($workOrder->output_item_id);
        if (! $item) $this->fail('output_item_missing', '工单产出物料不存在，不能建立生产执行。');

        $mode = (string) ($item->production_execution_mode ?: $item->activeMaterialPolicy?->production_execution_mode ?: 'unit');
        $stage = (string) ($item->serial_generation_stage ?: $item->activeMaterialPolicy?->serial_generation_stage ?: 'before_finished_goods_posting');
        if (! in_array($mode, ['unit', 'quantity'], true)) $this->fail('production_execution_mode_invalid', '产出物料的生产执行模式无效。');

        return [
            'production_execution_mode' => $mode,
            'material_policy_id' => $item->activeMaterialPolicy?->id,
            'material_policy_version' => $item->activeMaterialPolicy?->version_no,
            'serial_tracking_mode' => $item->serialTrackingMode(),
            'serial_number_prefix' => $item->serial_number_prefix,
            'serial_generation_stage' => $stage,
            'serial_generation_routing_operation_id' => $item->serial_generation_routing_operation_id
                ?: $item->activeMaterialPolicy?->serial_generation_routing_operation_id,
            'equipment_identity_requirement' => (string) ($item->equipment_identity_requirement ?: 'not_applicable'),
        ];
    }

    public function assertReleaseQuantity(WorkOrder $workOrder, array $policy): void
    {
        $quantity = (string) $workOrder->target_base_qty;
        if (($policy['production_execution_mode'] ?? null) === 'unit'
            && ! preg_match('/^\d+(?:\.0+)?$/', trim($quantity))) {
            $this->fail('production_unit_quantity_not_integer', "逐件生产物料的工单基准数量必须为整数，当前基准数量为 {$quantity}，请检查订单数量或单位换算。");
        }
    }

    public function initializePublished(WorkOrder $workOrder, array $policy): void
    {
        app(ProductionInventoryContinuationService::class)->assertPlanQuantity($workOrder, $policy['production_execution_mode']);
        if (ProductionUnit::query()->where('work_order_id', $workOrder->id)->exists()
            || ProductionQuantityOperation::query()->where('work_order_id', $workOrder->id)->exists()) {
            $this->fail('production_execution_exists', '该工单已经存在生产执行底座，禁止重复展开。', 409);
        }

        $workOrder->production_execution_mode_snapshot = $policy['production_execution_mode'];
        $workOrder->serial_policy_snapshot = $policy;
        $workOrder->save();

        $operations = $this->executionOperations($workOrder);
        if ($operations->isEmpty()) $this->fail('routing_snapshot_missing', '工单缺少可执行的冻结路线工序。');
        $this->freezeMaterialSupplyRules($workOrder, $operations);

        if ($policy['production_execution_mode'] === 'unit') {
            $this->createUnitExecution($workOrder, $operations, $policy);
            return;
        }
        $this->createQuantityExecution($workOrder, $operations);
    }

    private function createUnitExecution(WorkOrder $workOrder, Collection $operations, array $policy): void
    {
        $count = (int) ((string) $workOrder->target_base_qty);
        if ($count < 1) $this->fail('production_unit_quantity_invalid', '逐件生产工单至少需要一个生产单元。');
        for ($sequence = 1; $sequence <= $count; $sequence++) {
            $continuation = app(ProductionInventoryContinuationService::class)->unitPlan($workOrder, $sequence);
            $unit = ProductionUnit::create([
                'unit_no' => $this->numbers->next('production_unit', 'PU'),
                'work_order_id' => $workOrder->id,
                'sequence_no' => $sequence,
                'output_item_id' => $workOrder->output_item_id,
                'status' => 'WAITING',
                'routing_id_snapshot' => $workOrder->production_routing_id,
                'routing_version_snapshot' => $workOrder->routing_version_snapshot,
                'routing_snapshot' => $workOrder->routing_snapshot,
                'current_routing_operation_id' => $operations->first()['routing_operation_id'],
                'current_operation_code_snapshot' => $operations->first()['operation_code'],
                'current_operation_name_snapshot' => $operations->first()['operation_name'],
                'production_location_name_snapshot' => $workOrder->production_location_name,
                'organization_code' => $workOrder->organization_code,
                'business_version' => 1,
            ]);

            $sourceOutput = $continuation ? DB::table('erp_production_output_records')->where('id', $continuation['source_output_record_id'])->first() : null;
            $originalUnit = $sourceOutput?->production_unit_id ? ProductionUnit::find($sourceOutput->production_unit_id) : null;
            $originalSerial = $originalUnit?->device_serial_id ? ProductionSerial::find($originalUnit->device_serial_id) : null;
            if (! $originalSerial && $continuation && $continuation['inventory_serial_id'] && (int) $continuation['item_id'] === (int) $workOrder->output_item_id) {
                $inventorySerial = DB::table('erp_inventory_serials')->where('id', $continuation['inventory_serial_id'])->first();
                $originalSerial = ProductionSerial::firstOrCreate(['serial_no' => $inventorySerial->serial_no], [
                    'item_id' => $workOrder->output_item_id, 'serial_type' => 'finished_device', 'generation_stage' => 'inventory_continuation',
                    'status' => 'inventory_bound', 'inventory_serial_id' => $inventorySerial->id,
                    'source_type' => 'inventory_serial', 'source_id' => $inventorySerial->id, 'generated_at' => now()]);
            }
            if ($originalSerial) {
                if ((int) $originalSerial->item_id !== (int) $workOrder->output_item_id) $this->fail('continuation_serial_item_mismatch', '库存来源产品序列号与本工单物料不一致。');
                $unit->update(['device_serial_id' => $originalSerial->id, 'device_no_snapshot' => $originalSerial->serial_no]);
            } elseif (($policy['serial_tracking_mode'] ?? 'none') !== 'none'
                && ($policy['serial_generation_stage'] ?? null) === 'production_unit_created') {
                $serial = $this->createSerial($workOrder, $unit, $policy);
                $unit->update(['device_serial_id' => $serial->id, 'device_no_snapshot' => $serial->serial_no]);
            }
            $this->createEquipmentIdentity($unit, $policy);
            if ($originalUnit?->equipmentIdentity && $originalUnit->equipmentIdentity->status === 'BOUND') {
                DB::table('erp_production_unit_equipment_identities')->where('production_unit_id', $unit->id)->update([
                    'status' => 'BOUND', 'source_type' => 'production_unit_identity', 'source_id' => $originalUnit->equipmentIdentity->id,
                    'bound_by_legacy_id' => $originalUnit->equipmentIdentity->bound_by_legacy_id, 'bound_at' => $originalUnit->equipmentIdentity->bound_at,
                    'updated_at' => now()]);
            }
            $unitOperations = $continuation ? $operations->where('sequence', '>=', $continuation['start_sequence'])->values() : $operations;
            $unit->update(['current_routing_operation_id' => $unitOperations->first()['routing_operation_id'],
                'current_operation_code_snapshot' => $unitOperations->first()['operation_code'],
                'current_operation_name_snapshot' => $unitOperations->first()['operation_name']]);
            foreach ($unitOperations as $index => $operation) {
                $targetStatus = $index === 0 ? 'WAIT_CLAIM' : 'WAIT_PREDECESSOR';
                $target = $unit->operations()->create($this->operationAttributes(
                    $workOrder,
                    $operation,
                    $targetStatus,
                ));
                $this->createTargetMaterialRequirements($workOrder, 'unit_operation', $target->id, $operation['routing_operation_id'], $sequence);
                $this->createTask($workOrder, 'unit', $operation, 'unit_operation', $target, $targetStatus);
            }
        }
    }

    private function createQuantityExecution(WorkOrder $workOrder, Collection $operations): void
    {
        $operations = $operations->filter(fn ($operation) => app(ProductionInventoryContinuationService::class)->plannedQuantity($workOrder, $operation['sequence']) > 0)->values();
        foreach ($operations as $index => $operation) {
            $quantity = app(ProductionInventoryContinuationService::class)->plannedQuantity($workOrder, $operation['sequence']);
            $targetStatus = $index === 0 ? 'WAIT_CLAIM' : 'WAIT_PREDECESSOR';
            $target = ProductionQuantityOperation::create($this->operationAttributes(
                $workOrder,
                $operation,
                $targetStatus,
            ) + [
                'planned_base_qty' => $quantity,
                'completed_base_qty' => 0,
                'scrapped_base_qty' => 0,
                'remaining_base_qty' => $quantity,
            ]);
            $this->createTargetMaterialRequirements($workOrder, 'quantity_operation', $target->id, $operation['routing_operation_id']);
            $this->createTask($workOrder, 'quantity', $operation, 'quantity_operation', $target, $targetStatus);
        }
    }

    private function createTask(WorkOrder $workOrder, string $mode, array $operation, string $targetType, object $target, string $status): void
    {
        $laborRule = $this->laborRules->activeSnapshot();
        $task = ProductionTask::create([
            'task_no' => $this->numbers->next('production_task', 'PT'),
            'work_order_id' => $workOrder->id,
            'production_unit_id' => $mode === 'unit' ? $target->production_unit_id : null,
            'production_unit_operation_id' => $mode === 'unit' ? $target->id : null,
            'production_quantity_operation_id' => $mode === 'quantity' ? $target->id : null,
            'execution_mode' => $mode,
            'routing_operation_id_snapshot' => $operation['routing_operation_id'],
            'operation_code_snapshot' => $operation['operation_code'],
            'operation_name_snapshot' => $operation['operation_name'],
            'sequence_no_snapshot' => $operation['sequence'],
            'production_stage_id_snapshot' => $operation['production_stage_id'],
            'stage_code_snapshot' => $operation['stage_code'], 'stage_name_snapshot' => $operation['stage_name'],
            'performance_rate_snapshot' => $operation['performance_rate'],
            'auto_assignment_enabled_snapshot' => $operation['auto_assignment_enabled'],
            'is_public_snapshot' => $operation['is_public'],
            'status' => $status,
            'labor_allocation_rule_id' => $laborRule['id'],
            'labor_allocation_rule_version' => $laborRule['version_no'],
            'labor_allocation_rule_snapshot' => $laborRule,
            'business_version' => 1,
            'organization_code' => $workOrder->organization_code,
        ]);
        $task->targets()->create([
            'target_type' => $targetType,
            'target_id' => $target->id,
            'status_snapshot' => $status,
        ]);
        if ($status === 'WAIT_CLAIM') app(ProductionTaskAssignmentService::class)->tryOfferReadyTask($task);
    }

    private function operationAttributes(WorkOrder $workOrder, array $operation, string $status): array
    {
        $kittingRequired = DB::table('erp_work_order_material_supply_rules')
            ->where('work_order_id', $workOrder->id)
            ->where('target_routing_operation_id_snapshot', $operation['routing_operation_id'])
            ->where('participates_in_kitting_snapshot', true)
            ->exists();

        $mode = (string) ($workOrder->production_execution_mode_snapshot ?: 'unit');
        $setup = (float) ($operation['setup_standard_minutes'] ?? 0);
        $unit = $operation['unit_standard_minutes'] === null ? null : (float) $operation['unit_standard_minutes'];
        $quantity = $mode === 'unit' ? 1.0 : app(ProductionInventoryContinuationService::class)->plannedQuantity($workOrder, $operation['sequence']);
        $standard = $unit === null ? null : ($mode === 'unit' ? $unit : $setup + $unit * $quantity);
        $isStockPrebuildTarget = $workOrder->source_type === 'stock_prebuild'
            && (int) $workOrder->target_routing_operation_id === (int) $operation['routing_operation_id'];
        return [
            'work_order_id' => $workOrder->id,
            'routing_operation_id_snapshot' => $operation['routing_operation_id'],
            'operation_id_snapshot' => $operation['operation_id'],
            'is_public_snapshot' => $operation['is_public'],
            'operation_code_snapshot' => $operation['operation_code'],
            'operation_name_snapshot' => $operation['operation_name'],
            'sequence_no_snapshot' => $operation['sequence'],
            'production_stage_id_snapshot' => $operation['production_stage_id'],
            'stage_code_snapshot' => $operation['stage_code'], 'stage_name_snapshot' => $operation['stage_name'],
            'performance_rate_snapshot' => $operation['performance_rate'],
            'status' => $status,
            'standard_minutes_snapshot' => $standard,
            'setup_standard_minutes_snapshot' => $setup,
            'unit_standard_minutes_snapshot' => $unit,
            'standard_quantity_snapshot' => $quantity,
            'standard_time_formula_snapshot' => $mode === 'unit' ? 'unit_standard' : 'setup_plus_unit_times_qty',
            'kitting_required' => $kittingRequired,
            // 备货生产做到中间工序时，目标工序自己的正式产出 Item 和本单有效去向是唯一依据。
            // 禁止回退到整条路线最终成品，否则会制造错误库存和错误谱系。
            'output_item_id_snapshot' => $isStockPrebuildTarget
                ? ($workOrder->effective_output_item_id_snapshot ?? $operation['output_item_id']) : $operation['output_item_id'],
            'output_mode_snapshot' => $isStockPrebuildTarget
                ? ($workOrder->effective_output_mode_snapshot ?? $operation['output_mode']) : $operation['output_mode'],
            'quality_mode_snapshot' => $operation['quality_mode'],
            'work_mode_snapshot' => $operation['work_mode'],
            'allow_continue_without_warehouse_snapshot' => $operation['allow_continue_without_warehouse'],
            'business_version' => 1,
        ];
    }

    private function executionOperations(WorkOrder $workOrder): Collection
    {
        $rows = collect((array) data_get($workOrder->routing_snapshot, 'operations', []))
            ->filter(fn ($row) => ($row['execution_context'] ?? 'production') === 'production')
            ->map(fn (array $row): array => [
                'routing_operation_id' => (int) ($row['routing_operation_id'] ?? 0),
                'operation_id' => (int) ($row['operation_id'] ?? 0),
                'operation_code' => (string) ($row['operation_no'] ?? $row['operation_code'] ?? ''),
                'operation_name' => (string) ($row['operation_name'] ?? ''),
                'sequence' => (int) ($row['sequence'] ?? 0),
                'production_stage_id' => $row['production_stage_id'] ?? null,
                'stage_code' => $row['stage_code'] ?? null, 'stage_name' => $row['stage_name'] ?? null,
                'performance_rate' => $row['performance_rate'] ?? null,
                'auto_assignment_enabled' => (bool) ($row['auto_assignment_enabled'] ?? false),
                // 分类与执行规则同源冻结；旧快照没有该字段时保持默认非公共，不能回读当前工序档案。
                'is_public' => (bool) ($row['is_public'] ?? false),
                'standard_minutes' => $row['standard_minutes'] ?? null,
                'setup_standard_minutes' => $row['setup_standard_minutes'] ?? 0,
                'unit_standard_minutes' => $row['unit_standard_minutes'] ?? ($row['standard_minutes'] ?? null),
                'output_item_id' => $row['output_item_id'] ?? null,
                'output_mode' => $row['output_mode'] ?? 'flow_only',
                'quality_mode' => $row['quality_mode'] ?? 'none',
                'work_mode' => $row['work_mode'] ?? 'manual',
                'allow_continue_without_warehouse' => (bool) ($row['allow_continue_without_warehouse'] ?? true),
            ])->filter(fn (array $row): bool => $row['routing_operation_id'] > 0 && $row['sequence'] > 0)
            ->sortBy('sequence')->values();

        if ($workOrder->source_type === 'stock_prebuild' && $workOrder->target_routing_operation_id) {
            $target = $rows->firstWhere('routing_operation_id', (int) $workOrder->target_routing_operation_id);
            if (! $target) $this->fail('target_routing_operation_invalid', '备货工单目标路线工序不在冻结路线中。');
            $rows = $rows->where('sequence', '<=', $target['sequence'])->values();
        }
        return $rows;
    }

    private function freezeMaterialSupplyRules(WorkOrder $workOrder, Collection $operations): void
    {
        $operationById = $operations->keyBy('routing_operation_id');
        $rules = collect((array) data_get($workOrder->routing_snapshot, 'operations', []))
            ->flatMap(fn (array $operation) => collect((array) ($operation['material_supply_rules'] ?? []))->map(fn (array $rule) => (object) $rule))
            ->filter(fn (object $rule): bool => $operationById->has((int) ($rule->target_routing_operation_id ?? 0)))
            ->groupBy('component_item_id');

        foreach ($workOrder->materialRequirements()->lockForUpdate()->get() as $requirement) {
            if ($requirement->requirement_kind === 'stock_continuation') {
                $quantities = json_decode((string) $requirement->remaining_supply_snapshot, true) ?: [];
                foreach ($quantities as $nodeId => $quantity) {
                    $node = $operationById->get((int) $nodeId);
                    DB::table('erp_work_order_material_supply_rules')->insert([
                        'work_order_id' => $workOrder->id, 'material_requirement_id' => $requirement->id,
                        'component_item_id' => $requirement->component_item_id, 'target_routing_operation_id_snapshot' => $nodeId,
                        'target_operation_code_snapshot' => $node['operation_code'], 'target_operation_name_snapshot' => $node['operation_name'],
                        'required_base_qty_snapshot' => $quantity, 'supply_mode_snapshot' => 'warehouse_picking',
                        'requires_delivery_snapshot' => false, 'participates_in_kitting_snapshot' => true,
                        'allow_partial_delivery_snapshot' => false, 'delivery_location_type_snapshot' => 'operation_station',
                        'rule_snapshot' => json_encode(['requirement_kind' => 'stock_continuation', 'required_qty_ratio' => 1], JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now()]);
                }
                continue;
            }
            $remaining = $requirement->remaining_supply_snapshot ? json_decode((string) $requirement->remaining_supply_snapshot, true) : null;
            foreach ($rules->get($requirement->component_item_id, collect()) as $rule) {
                if ($remaining !== null && ! isset($remaining['rule:'.$rule->rule_id])) continue;
                $target = $operationById->get((int) $rule->target_routing_operation_id);
                DB::table('erp_work_order_material_supply_rules')->insert([
                    'work_order_id' => $workOrder->id,
                    'material_requirement_id' => $requirement->id,
                    'component_item_id' => $requirement->component_item_id,
                    'source_rule_id' => $rule->rule_id ?? null,
                    'target_routing_operation_id_snapshot' => $rule->target_routing_operation_id,
                    'target_operation_code_snapshot' => $target['operation_code'],
                    'target_operation_name_snapshot' => $target['operation_name'],
                    'required_base_qty_snapshot' => $remaining['rule:'.$rule->rule_id] ?? round((float) $requirement->base_required_qty * (float) $rule->required_qty_ratio, 8),
                    'supply_mode_snapshot' => $rule->supply_mode,
                    'requires_delivery_snapshot' => (bool) $rule->requires_delivery,
                    'participates_in_kitting_snapshot' => (bool) $rule->participates_in_kitting,
                    'allow_partial_delivery_snapshot' => (bool) $rule->allow_partial_delivery,
                    'delivery_location_type_snapshot' => $rule->delivery_location_type,
                    'rule_snapshot' => json_encode((array) $rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function createSerial(WorkOrder $workOrder, ProductionUnit $unit, array $policy): ProductionSerial
    {
        $prefix = trim((string) ($policy['serial_number_prefix'] ?? '')) ?: 'SN';
        return ProductionSerial::create([
            'serial_no' => $this->numbers->next('production_serial_'.$workOrder->output_item_id, $prefix),
            'item_id' => $workOrder->output_item_id,
            'serial_type' => 'finished_device',
            'generation_stage' => 'production_unit_created',
            'status' => 'generated',
            'source_type' => 'production_unit',
            'source_id' => $unit->id,
            'generated_at' => now(),
        ]);
    }

    private function createEquipmentIdentity(ProductionUnit $unit, array $policy): void
    {
        $required = ($policy['equipment_identity_requirement'] ?? 'not_applicable') === 'required';
        DB::table('erp_production_unit_equipment_identities')->insert([
            'production_unit_id' => $unit->id,
            'status' => $required ? 'PENDING_GENERATION' : 'NOT_APPLICABLE',
            'business_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTargetMaterialRequirements(WorkOrder $workOrder, string $targetType, int $targetId, int $routingOperationId, ?int $unitSequence = null): void
    {
        $rows = DB::table('erp_work_order_material_supply_rules as supply')
            ->join('erp_work_order_material_requirements as requirement', 'requirement.id', '=', 'supply.material_requirement_id')
            ->where('supply.work_order_id', $workOrder->id)
            ->where('supply.target_routing_operation_id_snapshot', $routingOperationId)
            ->select(
                'supply.*',
                'requirement.per_output_qty',
                'requirement.loss_rate',
                'requirement.fixed_qty',
                'requirement.cut_length_mm_snapshot',
                'requirement.cutting_requirement_snapshot',
                'requirement.per_output_piece_qty',
                'requirement.required_piece_qty'
            )
            ->get();
        foreach ($rows as $row) {
            $rule = json_decode((string) $row->rule_snapshot, true) ?: [];
            $ratio = (float) ($rule['required_qty_ratio'] ?? 1);
            $firstUnitSequence = 1;
            if ($unitSequence !== null && $workOrder->inventory_continuation_plan) {
                $nodeSequence = (int) collect(data_get($workOrder->routing_snapshot, 'operations', []))->firstWhere('routing_operation_id', $routingOperationId)['sequence'];
                for ($candidate = 1; $candidate <= (int) $workOrder->target_base_qty; $candidate++) {
                    $plan = app(ProductionInventoryContinuationService::class)->unitPlan($workOrder, $candidate);
                    if (! $plan || $plan['start_sequence'] <= $nodeSequence) { $firstUnitSequence = $candidate; break; }
                }
            }
            $required = $unitSequence === null
                ? (float) $row->required_base_qty_snapshot
                : ((float) $row->per_output_qty * (1 + (float) $row->loss_rate / 100) + ($unitSequence === $firstUnitSequence ? (float) $row->fixed_qty : 0)) * $ratio;
            $kind = $rule['requirement_kind'] ?? 'standard';
            if ($kind === 'stock_continuation' && $unitSequence !== null) {
                $plan = app(ProductionInventoryContinuationService::class)->unitPlan($workOrder, $unitSequence);
                $required = $plan && (int) $plan['start_routing_operation_id'] === $routingOperationId && (int) $plan['item_id'] === (int) $row->component_item_id ? 1 : 0;
            }
            if ($required <= 0) continue;
            $requiredPieces = $row->per_output_piece_qty === null
                ? null
                : ($unitSequence === null
                    ? app(ProductionInventoryContinuationService::class)->plannedQuantity($workOrder, (int) $this->executionOperations($workOrder)->firstWhere('routing_operation_id', $routingOperationId)['sequence']) * (float) $row->per_output_piece_qty * $ratio
                    : (float) $row->per_output_piece_qty * $ratio);
            DB::table('erp_production_target_material_requirements')->insert([
                'work_order_id' => $workOrder->id,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'material_requirement_id' => $row->material_requirement_id,
                'material_supply_rule_snapshot_id' => $row->id,
                'component_item_id' => $row->component_item_id,
                'cut_length_mm_snapshot' => $row->cut_length_mm_snapshot,
                'cutting_requirement_snapshot' => $row->cutting_requirement_snapshot,
                'required_piece_qty_snapshot' => $requiredPieces === null ? null : round($requiredPieces, 8),
                'requirement_kind' => $kind,
                'required_base_qty' => round($required, 8),
                'satisfied_base_qty' => 0,
                'consumed_base_qty' => 0,
                'returned_base_qty' => 0,
                'status' => $row->supply_mode_snapshot === 'dedicated_delivery' && (bool) $row->requires_delivery_snapshot
                    ? 'WAIT_PREPARE'
                    : 'OPEN',
                'business_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function fail(string $code, string $message, int $status = 422): never
    {
        throw new WorkOrderDomainException($code, $message, $status);
    }
}
