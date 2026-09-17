<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;

/** One immutable identity per formal requirement, not a WO quantity estimate. */
final class CuttingDemandService
{
    public function __construct(private readonly CuttingCommandService $commands, private readonly DocumentNumberService $numbers) {}

    public function bind(array $plan, WorkOrder $producer, object $node, object $user, array $permissions, bool $super): object
    {
        $c = $this->commands;
        $targetId = filter_var($plan['target_material_requirement_id'] ?? null,FILTER_VALIDATE_INT);
        $inputId = filter_var($plan['input_material_requirement_id'] ?? null,FILTER_VALIDATE_INT);
        if (! $targetId || $targetId < 1) $c->fail('formal_requirement_required','下料计划必须引用唯一的正式目标物料需求行。');
        if (! $inputId || $inputId < 1) $c->fail('input_requirement_required','必须明确当前下料工序所需的具体原料需求行。');
        $target = DB::table('erp_production_target_material_requirements')->where('id',$targetId)->first();
        if (! $target) $c->fail('target_invalid','正式目标物料需求不存在。');
        $consumer = $c->workOrder($target->work_order_id,$user,$permissions,$super,'production.cutting.plan',true);
        if (! in_array($consumer->status,['RELEASED','IN_PROGRESS'],true) || in_array($target->status,['CANCELLED','CLOSED'],true)) $c->fail('target_not_active','正式需求尚未发布或已终止。');
        $target = DB::table('erp_production_target_material_requirements')->where('id',$targetId)->lockForUpdate()->first();
        $table = match ($target->target_type) { 'unit_operation'=>'erp_production_unit_operations','quantity_operation'=>'erp_production_quantity_operations',default=>null };
        $operation = $table ? DB::table($table)->where('id',$target->target_id)->where('work_order_id',$consumer->id)->first() : null;
        $source = DB::table('erp_work_order_material_requirements')->where('id',$target->material_requirement_id)->where('work_order_id',$consumer->id)->where('component_item_id',$target->component_item_id)->first();
        $supply = DB::table('erp_work_order_material_supply_rules')->where('id',$target->material_supply_rule_snapshot_id)->where('work_order_id',$consumer->id)
            ->where('material_requirement_id',$target->material_requirement_id)->where('component_item_id',$target->component_item_id)->first();
        if (! $operation || ! $source || ! $supply || (int) $supply->target_routing_operation_id_snapshot !== (int) $operation->routing_operation_id_snapshot)
            $c->fail('formal_requirement_inconsistent','正式需求、物料行和冻结目标工序的关联不一致。');
        $outputId = (int) ($node->output_item_id_snapshot ?: ($producer->effective_output_item_id_snapshot ?: $producer->output_item_id));
        if ((int) $target->component_item_id !== $outputId) $c->fail('target_invalid','正式需求物料与该下料工序产出不一致。');
        $input = DB::table('erp_work_order_material_requirements')->where('id',$inputId)->where('work_order_id',$producer->id)->lockForUpdate()->first();
        $raw = $input ? Item::find($input->component_item_id) : null;
        if (! $input || ! $raw || $raw->status !== 'enabled' || $raw->item_type !== 'raw_material' || ! in_array($raw->cuttingMode(),['sheet','length'],true))
            $c->fail('input_requirement_invalid','原料需求必须属于生产来源工单，并指向启用的下料原料。');
        $inputSupply = DB::table('erp_work_order_material_supply_rules')->where('work_order_id',$producer->id)->where('material_requirement_id',$inputId)
            ->where('component_item_id',$raw->id)->where('target_routing_operation_id_snapshot',$node->routing_operation_id_snapshot)->first();
        if (! $inputSupply) $c->fail('input_requirement_wrong_stage','该原料需求不属于当前发布工序的冻结供料规则。');
        $boundTarget = DB::table('erp_production_target_material_requirements')->where('work_order_id',$producer->id)->where('material_requirement_id',$inputId)
            ->where('material_supply_rule_snapshot_id',$inputSupply->id)->where('component_item_id',$raw->id)
            ->where('target_type',$node->production_unit_id ?? null ? 'unit_operation' : 'quantity_operation')->where('target_id',$node->id)->exists();
        if (! $boundTarget) $c->fail('input_requirement_wrong_stage','当前工序没有该原料的正式目标需求，不能推测关联。');
        $configId = isset($plan['configuration_id']) ? (int) $plan['configuration_id'] : null;
        $demand = DB::table('erp_cutting_demands')->where('source_type','production_target_material_requirement')->where('source_requirement_id',$targetId)->lockForUpdate()->first();
        if (! $demand) {
            $id = DB::table('erp_cutting_demands')->insertGetId(['demand_no'=>$this->numbers->next('cutting_demand','CD'),'source_type'=>'production_target_material_requirement',
                'source_requirement_id'=>$targetId,'item_id'=>$outputId,'configuration_id'=>$configId,'stage_id'=>$node->routing_operation_id_snapshot,
                'required_base_qty_snapshot'=>$target->required_base_qty,'cut_length_mm_snapshot'=>$target->cut_length_mm_snapshot,'required_piece_qty_snapshot'=>$target->required_piece_qty_snapshot,
                'source_business_version'=>$target->business_version,'source_snapshot'=>json_encode((array) $target,JSON_THROW_ON_ERROR),'status'=>'ACTIVE','business_version'=>1,
                'created_by_legacy_id'=>$c->actor($user),'created_at'=>now(),'updated_at'=>now()]);
            $demand = DB::table('erp_cutting_demands')->where('id',$id)->first();
        }
        if ((int) $demand->item_id !== $outputId || $demand->configuration_id != $configId || (int) $demand->stage_id !== (int) $node->routing_operation_id_snapshot)
            $c->fail('demand_identity_conflict','同一正式需求不能生成另一套物料、配置或阶段身份。',409);
        if (bccomp((string) $demand->required_base_qty_snapshot,(string) $target->required_base_qty,8) !== 0
            || $demand->cut_length_mm_snapshot != $target->cut_length_mm_snapshot || $demand->required_piece_qty_snapshot != $target->required_piece_qty_snapshot)
            $c->fail('demand_revision_required','正式需求规格或数量已变更，须先走独立需求变更命令。',409);
        $qty = CuttingDecimal::value($plan['planned_qty'] ?? null);
        $planned = (string) DB::table('erp_cutting_plan_allocations as p')->join('erp_cutting_orders as o','o.id','=','p.cutting_order_id')
            ->where('p.demand_id',$demand->id)->where('o.status','!=','CANCELLED')->sum('p.planned_qty');
        $received = bcsub((string) $target->satisfied_base_qty,(string) $target->returned_base_qty,8);
        if (bccomp(bcadd(bcadd($planned,$qty,8),$received,8),(string) $target->required_base_qty,8) > 0)
            $c->fail('plan_exceeds_source','下料安排超过这条正式物料需求尚未安排的数量。');
        return $demand;
    }
}
