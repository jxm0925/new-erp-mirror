<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{ProductionQuantityOperation, ProductionTask, ProductionUnitOperation};
use Illuminate\Support\Facades\DB;

final class ProductionJobBundleCompatibilityService
{
    public function target(ProductionTask $task, bool $lock = false): array
    {
        $links = $task->targets()->get();
        if ($links->count() !== 1) $this->fail('bundle_single_target_required', '共同加工只接受一个明确执行目标的原任务。');
        $link = $links->first();
        $model = match ($link->target_type) {
            'quantity_operation' => ProductionQuantityOperation::class,
            'unit_operation' => ProductionUnitOperation::class,
            default => null,
        };
        if (! $model) $this->fail('bundle_target_invalid', '该任务执行类型不支持共同加工。');
        $query = $model::query();
        if ($lock) $query->lockForUpdate();
        $target = $query->find($link->target_id);
        if (! $target || (int) $target->work_order_id !== (int) $task->work_order_id) $this->fail('bundle_target_invalid', '任务执行目标与工单身份不一致。');
        return [$link->target_type, $target];
    }

    public function inspect(ProductionTask $task, bool $creation = true): array
    {
        $task->loadMissing('workOrder.outputItem');
        [$type, $target] = $this->target($task);
        if ($task->execution_mode !== ($type === 'quantity_operation' ? 'quantity' : 'unit')) $this->fail('bundle_target_invalid', '任务执行模式与目标类型不一致。');
        $wo = $task->workOrder;
        if (! $wo || ! in_array($wo->status, ['RELEASED', 'IN_PROGRESS'], true)) $this->fail('work_order_not_executable', '来源工单尚未发布或已停止执行。');
        if ($creation && ($task->active_job_bundle_id || $target->started_at
            || ! in_array($task->status, ['WAIT_CLAIM', 'CLAIMED', 'READY', 'WAIT_MATERIAL', 'WAIT_HANDOVER'], true)
            || ! in_array($target->status, ['WAIT_CLAIM', 'CLAIMED', 'READY', 'WAIT_MATERIAL', 'WAIT_HANDOVER'], true))) {
            $this->fail('bundle_task_not_available', '任务已进入其他作业、已经开工或当前不可安排。');
        }
        if ((int) $target->responsible_user_legacy_id !== (int) $task->assignee_user_legacy_id) $this->fail('bundle_task_ownership_invalid', '任务与生产目标的负责人不一致，不能安排共同加工。');
        if ($creation && $task->pendingAssignment()->exists()) $this->fail('bundle_assignment_pending', '请先处理原任务待接受派单，再安排共同加工。');
        if ($creation && $task->collaborators()->where('role', 'collaborator')->whereNull('left_at')->exists()) $this->fail('bundle_collaborators_present', '原任务已有协作者，请先完成原协作安排。');
        if ((int) $target->operation_id_snapshot <= 0) $this->fail('bundle_operation_identity_missing', '历史任务缺少冻结工序档案身份，不能只按同名工序合并。');
        $node = collect(data_get($wo->routing_snapshot, 'operations', []))->first(fn ($n) => (int) ($n['routing_operation_id'] ?? 0) === (int) $target->routing_operation_id_snapshot);
        if (! $node || (int) ($node['operation_id'] ?? 0) !== (int) $target->operation_id_snapshot) $this->fail('bundle_routing_snapshot_invalid', '执行目标与工艺冻结快照不一致。');
        // Multi-output execution remains protected by the existing release gate. A bundle never invents new posting rules.
        if (count((array) ($node['output_rules'] ?? [])) > 1) $this->fail('bundle_multi_output_not_supported', '多产出比例执行尚未开放，不能通过共同加工绕过发布保护。');
        $outputRule = collect((array) ($node['output_rules'] ?? []))->first();
        if ($outputRule && ((string) ($outputRule['output_role'] ?? '') !== 'product'
            || bccomp((string) ($outputRule['base_qty_per_reference_unit'] ?? '0'), '1', 8) !== 0
            || (int) ($outputRule['item_id'] ?? 0) !== (int) $target->output_item_id_snapshot)) {
            $this->fail('bundle_multi_output_not_supported', '当前单产出执行不能处理该产出比例规则，请保留原发布保护。');
        }
        if (app(ProductionCuttingOperationService::class)->required($type, (int) $target->id)) $this->fail('bundle_cutting_task_requires_cutting_entry', '需要下料结果登记的任务请使用正式下料作业入口。');
        if (! in_array($target->work_mode_snapshot ?: 'manual', ['manual', 'automatic'], true)
            || ! in_array($target->quality_mode_snapshot, ['none', 'required'], true)
            || ! in_array($target->output_mode_snapshot, ['flow_only', 'warehouse_optional', 'warehouse_required'], true)
            || (int) $target->output_item_id_snapshot <= 0) $this->fail('bundle_execution_policy_invalid', '该任务缺少可执行的单产出、质检或加工方式快照。');
        $weight = (string) ($target->standard_minutes_snapshot ?? '0');
        if (! is_numeric($weight) || bccomp($weight, '0', 8) <= 0) $this->fail('bundle_standard_weight_required', '每条任务必须有大于零的冻结标准工时，不能按数量补默认分配权重。');
        $parameters = $node['parameters'] ?? [];
        if (is_string($parameters)) $parameters = json_decode($parameters, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($parameters)) $this->fail('bundle_process_parameters_invalid', '冻结工位、设备及工艺参数无法核对。');
        $facts = ['operation_id' => (int) $target->operation_id_snapshot, 'operation_code' => (string) $target->operation_code_snapshot,
            'execution_mode' => (string) $task->execution_mode, 'work_mode' => $target->work_mode_snapshot ?: 'manual',
            'organization_code' => $task->organization_code, 'parameters' => $this->canonical($parameters)];
        $materials = $this->materials($task, $type, (int) $target->id);
        return ['target_type' => $type, 'target' => $target, 'compatibility_snapshot' => $facts,
            'compatibility_key' => hash('sha256', json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'standard_weight_minutes' => $weight, 'materials' => $materials];
    }

    public function materials(ProductionTask $task, string $type, int $targetId): array
    {
        $rows = DB::table('erp_production_target_material_requirements as t')
            ->join('erp_work_order_material_requirements as w', 'w.id', '=', 't.material_requirement_id')
            ->join('erp_work_order_material_supply_rules as s', 's.id', '=', 't.material_supply_rule_snapshot_id')
            ->where('t.target_type', $type)->where('t.target_id', $targetId)
            ->select(['t.*', 'w.work_order_id as requirement_work_order_id', 'w.component_item_id as requirement_item_id',
                'w.configuration_id as required_configuration_id',
                'w.component_item_code_snapshot', 'w.component_item_name_snapshot', 'w.component_spec_snapshot', 'w.unit_name_snapshot',
                's.work_order_id as supply_work_order_id', 's.supply_mode_snapshot', 's.delivery_location_type_snapshot'])->orderBy('t.id')->get();
        if ($rows->count() !== DB::table('erp_production_target_material_requirements')->where('target_type', $type)->where('target_id', $targetId)->count()) {
            $this->fail('bundle_material_ownership_invalid', '明细材料缺少正式需求或供应规则，不能合并作业。');
        }
        foreach ($rows as $r) {
            if ((int) $r->work_order_id !== (int) $task->work_order_id || (int) $r->requirement_work_order_id !== (int) $task->work_order_id
                || (int) $r->supply_work_order_id !== (int) $task->work_order_id || (int) $r->component_item_id !== (int) $r->requirement_item_id) {
                $this->fail('bundle_material_ownership_invalid', '明细材料需求、物料或供应规则不属于该原工单，禁止混用。');
            }
        }
        $requirementIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $inputs = DB::table('erp_production_input_holdings as i')
            ->leftJoin('erp_material_holdings as h', 'h.id', '=', 'i.input_holding_id')
            ->leftJoin('erp_material_lots as lot', 'lot.id', '=', 'h.material_lot_id')
            ->where('i.target_type', $type)->where('i.target_id', $targetId)->where('i.status', 'ACTIVE')
            ->get(['i.id', 'i.target_material_requirement_id', 'i.source_output_record_id', 'i.source_holding_id', 'i.input_holding_id',
                'i.inventory_transaction_item_id', 'i.material_receipt_line_id', 'h.material_lot_id', 'h.quantity', 'lot.item_id',
                'lot.configuration_id as actual_configuration_id', 'lot.cut_length_mm', 'lot.material_form']);
        $byRequirement = $rows->keyBy('id');
        foreach ($inputs as $input) {
            $required = $byRequirement->get((int) $input->target_material_requirement_id);
            if (! $required || (int) $input->item_id !== (int) $required->component_item_id) {
                $this->fail('bundle_material_ownership_invalid', '实际投入来源不属于该明细的正式物料需求，禁止跨明细混用。');
            }
            $this->assertMaterialConfiguration($required, $input);
        }
        $holdings = DB::table('erp_material_holdings as h')->join('erp_material_lots as lot', 'lot.id', '=', 'h.material_lot_id')
            ->where('h.position_type', 'PRODUCTION_WIP')->whereIn('h.position_id', $requirementIds)->where('h.status', 'ACTIVE')
            ->get(['h.id', 'h.position_id', 'h.material_lot_id', 'h.quantity', 'lot.item_id',
                'lot.configuration_id as actual_configuration_id', 'lot.cut_length_mm', 'lot.material_form']);
        foreach ($holdings as $holding) {
            $required = $byRequirement->get((int) $holding->position_id);
            if ((int) $holding->item_id !== (int) $required->component_item_id) {
                $this->fail('bundle_material_ownership_invalid', '实物材料与明细物料身份不一致，禁止替代。');
            }
            $this->assertMaterialConfiguration($required, $holding);
        }
        return $rows->map(function ($r) use ($inputs, $holdings) {
            return (array) $r + ['source_facts' => [
                'production_inputs' => $inputs->where('target_material_requirement_id', $r->id)->map(fn ($i) => (array) $i)->values()->all(),
                'requirement_holdings' => $holdings->where('position_id', $r->id)->map(fn ($h) => (array) $h)->values()->all(),
            ]];
        })->all();
    }

    private function assertMaterialConfiguration(object $requirement, object $source): void
    {
        // Same Item does not establish the same confirmed specification. The
        // exact configuration version, including the absence of one, belongs
        // to the frozen material demand and must still match on final start.
        if ((int) $requirement->required_configuration_id !== (int) $source->actual_configuration_id) {
            $this->fail('bundle_material_configuration_invalid', '实际材料配置版本与本条冻结用料配置不一致，禁止用同物料的其他规格替代。');
        }
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as &$child) if (is_array($child)) $child = $this->canonical($child);
        return $value;
    }
    private function fail(string $code, string $message): never { throw new WorkOrderDomainException($code, $message, 409); }
}
