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
        $firstTargets = [];

        for ($sequence = 1; $sequence <= $count; $sequence++) {
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

            if (($policy['serial_tracking_mode'] ?? 'none') !== 'none'
                && ($policy['serial_generation_stage'] ?? null) === 'production_unit_created') {
                $serial = $this->createSerial($workOrder, $unit, $policy);
                $unit->update(['device_serial_id' => $serial->id, 'device_no_snapshot' => $serial->serial_no]);
            }

            foreach ($operations as $index => $operation) {
                $target = $unit->operations()->create($this->operationAttributes(
                    $workOrder,
                    $operation,
                    $index === 0 ? 'WAIT_CLAIM' : 'WAIT_PREVIOUS',
                ));
                $this->createTargetMaterialRequirements($workOrder, 'unit_operation', $target->id, $operation['routing_operation_id'], $sequence);
                if ($index === 0) $firstTargets[] = ['target_type' => 'unit_operation', 'target_id' => $target->id, 'status_snapshot' => 'WAIT_CLAIM'];
            }
        }

        $this->createTask($workOrder, 'unit', $operations->first(), $firstTargets);
    }

    private function createQuantityExecution(WorkOrder $workOrder, Collection $operations): void
    {
        $firstTarget = null;
        foreach ($operations as $index => $operation) {
            $target = ProductionQuantityOperation::create($this->operationAttributes(
                $workOrder,
                $operation,
                $index === 0 ? 'WAIT_CLAIM' : 'WAIT_PREVIOUS',
            ) + [
                'planned_base_qty' => $workOrder->target_base_qty,
                'completed_base_qty' => 0,
                'scrapped_base_qty' => 0,
                'remaining_base_qty' => $workOrder->target_base_qty,
            ]);
            $this->createTargetMaterialRequirements($workOrder, 'quantity_operation', $target->id, $operation['routing_operation_id']);
            if ($index === 0) $firstTarget = ['target_type' => 'quantity_operation', 'target_id' => $target->id, 'status_snapshot' => 'WAIT_CLAIM'];
        }
        $this->createTask($workOrder, 'quantity', $operations->first(), [$firstTarget]);
    }

    private function createTask(WorkOrder $workOrder, string $mode, array $operation, array $targets): void
    {
        $laborRule = $this->laborRules->activeSnapshot();
        $task = ProductionTask::create([
            'task_no' => $this->numbers->next('production_task', 'PT'),
            'work_order_id' => $workOrder->id,
            'execution_mode' => $mode,
            'routing_operation_id_snapshot' => $operation['routing_operation_id'],
            'operation_code_snapshot' => $operation['operation_code'],
            'operation_name_snapshot' => $operation['operation_name'],
            'sequence_no_snapshot' => $operation['sequence'],
            'status' => 'WAIT_CLAIM',
            'labor_allocation_rule_id' => $laborRule['id'],
            'labor_allocation_rule_version' => $laborRule['version_no'],
            'labor_allocation_rule_snapshot' => $laborRule,
            'business_version' => 1,
            'organization_code' => $workOrder->organization_code,
        ]);
        $task->targets()->createMany($targets);
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
        $quantity = $mode === 'unit' ? 1.0 : (float) $workOrder->target_base_qty;
        $standard = $unit === null ? null : ($mode === 'unit' ? $unit : $setup + $unit * $quantity);
        return [
            'work_order_id' => $workOrder->id,
            'routing_operation_id_snapshot' => $operation['routing_operation_id'],
            'operation_id_snapshot' => $operation['operation_id'],
            'operation_code_snapshot' => $operation['operation_code'],
            'operation_name_snapshot' => $operation['operation_name'],
            'sequence_no_snapshot' => $operation['sequence'],
            'status' => $status,
            'standard_minutes_snapshot' => $standard,
            'setup_standard_minutes_snapshot' => $setup,
            'unit_standard_minutes_snapshot' => $unit,
            'standard_quantity_snapshot' => $quantity,
            'standard_time_formula_snapshot' => $mode === 'unit' ? 'unit_standard' : 'setup_plus_unit_times_qty',
            'kitting_required' => $kittingRequired,
            'output_item_id_snapshot' => $operation['output_item_id'],
            'output_mode_snapshot' => $operation['output_mode'],
            'quality_mode_snapshot' => $operation['quality_mode'],
            'allow_continue_without_warehouse_snapshot' => $operation['allow_continue_without_warehouse'],
            'business_version' => 1,
        ];
    }

    private function executionOperations(WorkOrder $workOrder): Collection
    {
        $rows = collect((array) data_get($workOrder->routing_snapshot, 'operations', []))
            ->map(fn (array $row): array => [
                'routing_operation_id' => (int) ($row['routing_operation_id'] ?? 0),
                'operation_id' => (int) ($row['operation_id'] ?? 0),
                'operation_code' => (string) ($row['operation_no'] ?? $row['operation_code'] ?? ''),
                'operation_name' => (string) ($row['operation_name'] ?? ''),
                'sequence' => (int) ($row['sequence'] ?? 0),
                'standard_minutes' => $row['standard_minutes'] ?? null,
                'setup_standard_minutes' => $row['setup_standard_minutes'] ?? 0,
                'unit_standard_minutes' => $row['unit_standard_minutes'] ?? ($row['standard_minutes'] ?? null),
                'output_item_id' => $row['output_item_id'] ?? null,
                'output_mode' => $row['output_mode'] ?? 'flow_only',
                'quality_mode' => $row['quality_mode'] ?? 'none',
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
            foreach ($rules->get($requirement->component_item_id, collect()) as $rule) {
                $target = $operationById->get((int) $rule->target_routing_operation_id);
                DB::table('erp_work_order_material_supply_rules')->insert([
                    'work_order_id' => $workOrder->id,
                    'material_requirement_id' => $requirement->id,
                    'component_item_id' => $requirement->component_item_id,
                    'source_rule_id' => $rule->rule_id ?? null,
                    'target_routing_operation_id_snapshot' => $rule->target_routing_operation_id,
                    'target_operation_code_snapshot' => $target['operation_code'],
                    'target_operation_name_snapshot' => $target['operation_name'],
                    'required_base_qty_snapshot' => round((float) $requirement->base_required_qty * (float) $rule->required_qty_ratio, 8),
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

    private function createTargetMaterialRequirements(WorkOrder $workOrder, string $targetType, int $targetId, int $routingOperationId, ?int $unitSequence = null): void
    {
        $rows = DB::table('erp_work_order_material_supply_rules as supply')
            ->join('erp_work_order_material_requirements as requirement', 'requirement.id', '=', 'supply.material_requirement_id')
            ->where('supply.work_order_id', $workOrder->id)
            ->where('supply.target_routing_operation_id_snapshot', $routingOperationId)
            ->select('supply.*', 'requirement.per_output_qty', 'requirement.loss_rate', 'requirement.fixed_qty')
            ->get();
        foreach ($rows as $row) {
            $rule = json_decode((string) $row->rule_snapshot, true) ?: [];
            $ratio = (float) ($rule['required_qty_ratio'] ?? 1);
            $required = $unitSequence === null
                ? (float) $row->required_base_qty_snapshot
                : ((float) $row->per_output_qty * (1 + (float) $row->loss_rate / 100) + ($unitSequence === 1 ? (float) $row->fixed_qty : 0)) * $ratio;
            DB::table('erp_production_target_material_requirements')->insert([
                'work_order_id' => $workOrder->id,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'material_requirement_id' => $row->material_requirement_id,
                'material_supply_rule_snapshot_id' => $row->id,
                'component_item_id' => $row->component_item_id,
                'requirement_kind' => 'standard',
                'required_base_qty' => round($required, 8),
                'satisfied_base_qty' => 0,
                'consumed_base_qty' => 0,
                'returned_base_qty' => 0,
                'status' => 'OPEN',
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
