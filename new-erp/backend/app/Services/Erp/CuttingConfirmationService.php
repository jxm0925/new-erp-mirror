<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use Illuminate\Support\Facades\DB;

/** Cost confirmation creates controlled WIP, never warehouse stock or receipt/kitting facts. */
final class CuttingConfirmationService
{
    public function __construct(private readonly CuttingCommandService $commands,
        private readonly CuttingRecordService $records, private readonly DocumentNumberService $numbers) {}

    public function confirm(int $batchId, array $p, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands; $c->permission($permissions,'production.cutting.confirm');
        $c->assertBatchVisible($batchId,$user,$permissions,$super,'production.cutting.confirm');
        return $c->run('confirm_cutting_batch',$batchId,$p,$user,function () use ($c,$batchId,$p,$user,$permissions,$super): array {
            $batch = $c->batch($batchId,$user,$permissions,$super,'production.cutting.confirm'); $c->version($batch,$p);
            if ($batch->status !== 'WAIT_CONFIRM') $c->fail('batch_not_confirmable','用料批次尚未通过申报和质量确认，或已核算。',409);
            $this->records->assertInput($batch);
            $rows = DB::table('erp_cutting_results')->where('settlement_batch_id',$batchId)->whereNotIn('status',['VOIDED','SUPERSEDED'])->orderBy('id')->lockForUpdate()->get();
            if ($rows->isEmpty()) $c->fail('results_missing','没有可核算的实际结果。');
            $costs = $this->costs($rows->pluck('id')->all(),$p['costs'] ?? null);
            $sum = '0'; foreach ($costs as $cost) $sum = bcadd($sum,$cost,4);
            if (bccomp($sum,(string) $batch->original_total_cost,4) !== 0) $c->fail('cost_not_conserved','逐结果金额合计必须等于本用料批次的原始投入总金额。');
            $routes = DB::table('erp_cutting_result_routes')->whereIn('result_id',$rows->pluck('id'))->where('status','PLANNED')->orderBy('id')->lockForUpdate()->get();
            $allocations = $this->allocations($routes,$p['allocations'] ?? null,$batch,$user,$permissions,$super);
            foreach ($rows as $row) {
                $this->records->assertResultIdentity($batch,$row);
                if ($row->status !== 'SUBMITTED') $c->fail('result_not_submitted','结果尚未正式提交。',409);
                $cost = $costs[$row->id]; $lotId = null; $physicalId = null;
                if ($row->result_type === 'product') {
                    $this->quality($row);
                    $ownRoutes = $routes->where('result_id',$row->id);
                    $qty = '0'; foreach ($ownRoutes as $route) $qty = bcadd($qty,(string) $route->quantity,8);
                    if (bccomp($qty,(string) $row->actual_qty,8) !== 0) $c->fail('route_quantity_mismatch','这一条产出的去向数量不完整。');
                    $lotId = $this->lot($row->item_id,$row->configuration_id,$row->stage_id,'CUT_OUTPUT',$row->cut_length_mm,$row->id);
                    $remainingQty = (string) $row->actual_qty; $remainingCost = $cost;
                    foreach ($ownRoutes as $route) {
                        $routeCost = CuttingDecimal::share($remainingCost,$remainingQty,(string) $route->quantity);
                        $remainingCost = bcsub($remainingCost,$routeCost,4); $remainingQty = bcsub($remainingQty,(string) $route->quantity,8);
                        $holdingId = $this->holding($lotId,$route->route_type === 'WAREHOUSE' ? 'WAIT_WAREHOUSE' : 'WAIT_DISPATCH',$route->id,(string) $route->quantity,$routeCost);
                        DB::table('erp_cutting_result_routes')->where('id',$route->id)->update(['total_cost'=>$routeCost,'holding_id'=>$holdingId,
                            'status'=>$route->route_type === 'WAREHOUSE' ? 'WAIT_WAREHOUSE' : 'WAIT_DISPATCH','business_version'=>$route->business_version+1,'updated_at'=>now()]);
                        $leftQty = (string) $route->quantity; $leftCost = $routeCost;
                        foreach ($allocations[$route->id] as $allocation) {
                            $allocationCost = CuttingDecimal::share($leftCost,$leftQty,$allocation['quantity']);
                            $leftCost = bcsub($leftCost,$allocationCost,4); $leftQty = bcsub($leftQty,$allocation['quantity'],8);
                            DB::table('erp_cutting_output_allocations')->insert($allocation+['result_id'=>$row->id,'route_id'=>$route->id,
                                'total_cost'=>$allocationCost,'status'=>'EFFECTIVE','created_at'=>now(),'updated_at'=>now()]);
                        }
                        $this->movement($batch,$holdingId,$route->id,(string) $route->quantity,$routeCost,$user);
                    }
                } elseif ($row->result_type === 'usable_remnant') {
                    [$lotId,$physicalId] = $this->remnant($batch,$row,$cost,$user);
                } else {
                    DB::table('erp_cutting_cost_dispositions')->insert(['result_id'=>$row->id,
                        'disposition'=>$row->result_type === 'recyclable_scrap' ? 'HELD_RECYCLABLE' : 'LOSS_EXPENSE',
                        'total_cost'=>$cost,'measured_qty'=>$row->actual_qty,'status'=>'RECORDED','operator_legacy_id'=>$c->actor($user),'created_at'=>now(),'updated_at'=>now()]);
                }
                DB::table('erp_cutting_results')->where('id',$row->id)->update(['total_cost'=>$cost,'material_lot_id'=>$lotId,
                    'physical_material_id'=>$physicalId,'status'=>'CONFIRMED','business_version'=>$row->business_version+1,'updated_at'=>now()]);
            }
            DB::table('erp_material_holdings')->where('id',$batch->wip_holding_id)->update(['quantity'=>'0','total_cost'=>'0','status'=>'CONSUMED','business_version'=>DB::raw('business_version+1'),'updated_at'=>now()]);
            if ($batch->physical_material_id) {
                DB::table('erp_material_physicals')->where('id',$batch->physical_material_id)->update(['status'=>'CONSUMED','first_cut_at'=>DB::raw('COALESCE(first_cut_at,CURRENT_TIMESTAMP)'),
                    'business_version'=>DB::raw('business_version+1'),'updated_at'=>now()]);
                DB::table('erp_material_physical_reservations')->where('physical_material_id',$batch->physical_material_id)->where('cutting_order_id',$batch->cutting_order_id)->where('status','ACTIVE')->update(['status'=>'CONSUMED','updated_at'=>now()]);
            }
            DB::table('erp_cutting_settlement_batches')->where('id',$batchId)->update(['status'=>'CONFIRMED','confirmed_at'=>now(),'confirmed_by_legacy_id'=>$c->actor($user),
                'first_cut_at'=>DB::raw('COALESCE(first_cut_at,CURRENT_TIMESTAMP)'),'business_version'=>$batch->business_version+1,'updated_at'=>now()]);
            if ($batch->correction_of_batch_id) DB::table('erp_cutting_corrections')->where('correction_settlement_batch_id', $batchId)
                ->where('status', 'OPEN')->update(['status' => 'CONFIRMED', 'completed_at' => now(), 'updated_at' => now()]);
            $response = ['settlement_batch_id'=>$batchId,'status'=>'CONFIRMED','business_version'=>$batch->business_version+1,'confirmed_total_cost'=>$sum];
            $c->event('batch',$batchId,'confirm',$user,$batch,$response); return $response;
        });
    }

    private function costs(array $ids, mixed $entries): array
    {
        $c = $this->commands;
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) !== count($ids)) $c->fail('costs_required','须逐条明确所有产品、余料、废料及损失金额。');
        $costs = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry),['result_id','total_cost'])) $c->fail('cost_fields_invalid','核算金额字段不合法。');
            $id = filter_var($entry['result_id'] ?? null,FILTER_VALIDATE_INT);
            if (! in_array($id,$ids,true) || isset($costs[$id])) $c->fail('cost_source_invalid','核算金额引用了其他用料批次或重复结果。');
            $costs[$id] = CuttingDecimal::value($entry['total_cost'] ?? null,4,true);
        }
        return $costs;
    }

    private function allocations(object $routes, mixed $entries, object $batch, object $user, array $permissions, bool $super): array
    {
        $c = $this->commands;
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) < 1 || count($entries) > 500) $c->fail('allocations_required','须逐去向明确正式计划归属或未分配产出的处置。');
        $plans = DB::table('erp_cutting_plan_allocations')->where('cutting_order_id',$batch->cutting_order_id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($plans->contains(fn ($plan) => ! $plan->demand_id || ! $plan->input_material_requirement_id || ! $plan->target_material_requirement_id))
            $c->fail('formal_requirement_required','历史计划尚未绑定唯一正式需求及原料行，禁止继续核算。',409);
        // Lock every source/target WO in a stable order, so different cutting orders cannot over-allocate one requirement.
        $woIds = $plans->pluck('work_order_id')->merge(DB::table('erp_production_target_material_requirements')->whereIn('id',$routes->pluck('target_material_requirement_id')->filter())->pluck('work_order_id'))->unique()->sort();
        foreach ($woIds as $woId) $c->workOrder($woId,$user,$permissions,$super,'production.cutting.confirm',true);
        $result = []; $planAdded = []; $targetAdded = []; $seen = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry),['route_id','plan_id','quantity','disposition'])) $c->fail('allocation_fields_invalid','分配字段不合法。');
            $route = $routes->firstWhere('id',(int) ($entry['route_id'] ?? 0));
            if (! $route || $route->status !== 'PLANNED') $c->fail('allocation_route_invalid','分配去向不属于当前用料批次。');
                $row = DB::table('erp_cutting_results')->where('id',$route->result_id)->first();
                $allowed = DB::table('erp_cutting_allowed_outputs')->where('id',$row->allowed_output_id)->first();
                if (! $allowed || ! in_array($route->route_type,['WAREHOUSE','NEXT_OPERATION'],true)
                    || ($route->route_type === 'WAREHOUSE' && ($allowed->output_mode === 'flow_only' || $route->target_material_requirement_id))
                    || ($route->route_type === 'NEXT_OPERATION' && ($allowed->output_mode === 'warehouse_required' || ! $route->target_material_requirement_id)))
                    $c->fail('route_policy_invalid','该结果去向不符合发布时冻结的正式产出规则。');
            $qty = CuttingDecimal::value($entry['quantity'] ?? null); $type = $entry['disposition'] ?? '';
            $planId = isset($entry['plan_id']) ? (int) $entry['plan_id'] : null;
            $key = $route->id.':'.($planId ?? 'unallocated'); if (isset($seen[$key])) $c->fail('allocation_duplicate','同一去向的同一归属不能重复。'); $seen[$key] = true;
            if ($type === 'PLAN') {
                $plan = $plans->get($planId);
                if (! $plan || (int) $plan->output_item_id !== (int) $row->item_id || $plan->configuration_id != $row->configuration_id || (int) $plan->stage_id !== (int) $row->stage_id)
                    $c->fail('allocation_identity_invalid','分配计划的Item、配置或阶段与该结果不一致。');
                if ($route->route_type === 'NEXT_OPERATION' && (int) $plan->target_material_requirement_id !== (int) $route->target_material_requirement_id) $c->fail('target_not_planned','下一工序不属于分配计划的正式需求。');
                $this->records->configuration($row->configuration_id,Item::findOrFail($row->item_id),$plan->work_order_id);
                $planAdded[$planId] = bcadd($planAdded[$planId] ?? '0',$qty,8);
                $used = (string) DB::table('erp_cutting_output_allocations')->where('plan_id',$planId)->where('status','EFFECTIVE')->sum('quantity');
                if (bccomp(bcadd($used,$planAdded[$planId],8),(string) $plan->planned_qty,8) > 0) $c->fail('allocation_exceeds_plan','正式归属数量超过本计划尚未满足的数量。');
            } else {
                if ($planId || $route->route_type !== 'WAREHOUSE' || ! in_array($type,['PUBLIC_UNALLOCATED','RESTRICTED_UNALLOCATED'],true)) $c->fail('surplus_disposition_invalid','未分配产出必须明确公共或专用备货处置，不能冒充目标需求。');
                $scope = $row->configuration_id ? DB::table('erp_custom_configurations')->where('id',$row->configuration_id)->value('scope_mode') : 'PUBLIC';
                if (($scope === 'PUBLIC') !== ($type === 'PUBLIC_UNALLOCATED')) $c->fail('surplus_scope_invalid','专用配置不能变为公共可用产出。',403);
            }
            if ($route->route_type === 'NEXT_OPERATION') {
                $target = DB::table('erp_production_target_material_requirements')->where('id',$route->target_material_requirement_id)->lockForUpdate()->first();
                if (! $target || (int) $target->component_item_id !== (int) $row->item_id) $c->fail('target_invalid','正式目标物料不匹配。');
                $this->records->configuration($row->configuration_id,Item::findOrFail($row->item_id),$target->work_order_id);
                $targetAdded[$target->id] = bcadd($targetAdded[$target->id] ?? '0',$qty,8);
                $pending = (string) DB::table('erp_cutting_result_routes')->where('target_material_requirement_id',$target->id)
                    ->whereIn('status',['WAIT_DISPATCH','PART_DISPATCHED','IN_TRANSIT','PART_RECEIVED'])
                    ->selectRaw('COALESCE(SUM(quantity-received_qty),0) AS quantity')->value('quantity');
                $netReceived = bcsub((string) $target->satisfied_base_qty,(string) $target->returned_base_qty,8);
                if (bccomp(bcadd(bcadd($netReceived,$pending,8),$targetAdded[$target->id],8),(string) $target->required_base_qty,8) > 0) $c->fail('target_supply_exceeded','去下一工序的供给超过真实目标尚未满足的需求。');
            }
            $result[$route->id][] = ['plan_id'=>$planId,'quantity'=>$qty,'disposition'=>$type];
        }
        foreach ($routes as $route) {
            $sum = '0'; foreach ($result[$route->id] ?? [] as $entry) $sum = bcadd($sum,$entry['quantity'],8);
            if (bccomp($sum,(string) $route->quantity,8) !== 0) $c->fail('allocation_quantity_mismatch','每一条去向的归属数量合计必须等于其计划数量。');
        }
        return $result;
    }

    private function quality(object $row): void
    {
        $c = $this->commands;
        if (! in_array($row->quality_status,['PASSED','NOT_REQUIRED'],true) || ($row->reported_quality === 'unqualified' && $row->quality_status !== 'PASSED')) $c->fail('quality_not_released','不合格、待检或待处置产品不能正式形成合格供给。');
        if ($row->quality_status === 'PASSED') {
            $fact = DB::table('erp_production_quality_inspections')->where('cutting_result_id',$row->id)->where('result','passed')->orderByDesc('id')->first();
            $snapshot = $fact ? json_decode($fact->inspection_snapshot,true,512,JSON_THROW_ON_ERROR) : [];
            if (! $fact || (int) ($snapshot['result_business_version'] ?? 0) !== (int) $row->business_version-1 || bccomp((string) $fact->qualified_base_qty,(string) $row->actual_qty,8) !== 0)
                $c->fail('quality_fact_stale','缺少当前版本的正式合格检验事实。',409);
        }
    }

    private function remnant(object $batch, object $row, string $cost, object $user): array
    {
        $c = $this->commands; $measure = $row->measurements ? json_decode($row->measurements,true,512,JSON_THROW_ON_ERROR) : [];
        if ($row->measurement_status !== 'MEASURED' || bccomp((string) ($row->actual_qty ?? '0'),'1',8) !== 0) $c->fail('remnant_not_measured','可用余料须明确真实数量和尺寸；未测量记录不能生成可用材料。');
        $item = Item::findOrFail($batch->input_item_id); $dimensions = []; $parent = null;
        if ($batch->physical_material_id) {
            $parent = DB::table('erp_material_physicals')->where('id',$batch->physical_material_id)->first();
            foreach (['length_mm','width_mm','thickness_mm'] as $field) $dimensions[$field] = CuttingDecimal::value($measure[$field] ?? null,2);
            if (! in_array($measure['shape'] ?? '',['RECTANGLE','IRREGULAR'],true)) $c->fail('remnant_shape_required','须明确余料矩形或异形及真实／外包尺寸。');
        } else {
            if ($item->cuttingMode() !== 'length') $c->fail('remnant_input_invalid','余料来源不是已支持的板材或定长原料。');
            $dimensions['length_mm'] = CuttingDecimal::value($measure['length_mm'] ?? null,2);
        }
        $sourceLot = DB::table('erp_material_holdings')->where('id',$batch->wip_holding_id)->value('material_lot_id');
        $lotId = $this->lot($item->id,null,null,'REMNANT',$dimensions['length_mm'],$row->id,$sourceLot);
        $holdingId = $this->holding($lotId,'REMNANT_WIP',$row->id,'1.00000000',$cost); $physicalId = null;
        if ($parent) {
            $physicalId = DB::table('erp_material_physicals')->insertGetId(['physical_no'=>$this->numbers->next('material_physical','PLATE'),'item_id'=>$item->id,
                'material_lot_id'=>$lotId,'current_holding_id'=>$holdingId,'root_physical_id'=>$parent->root_physical_id ?: $parent->id,'parent_physical_id'=>$parent->id,
                'material_form'=>'REMNANT','shape'=>$measure['shape'],'dimensions'=>json_encode($dimensions,JSON_THROW_ON_ERROR),'total_cost'=>$cost,
                'status'=>'AVAILABLE','business_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        }
        $this->movement($batch,$holdingId,null,'1.00000000',$cost,$user); return [$lotId,$physicalId];
    }

    private function lot(int $itemId, ?int $configurationId, ?int $stageId, string $form, ?string $length, int $resultId, ?int $parent = null): int
    { return DB::table('erp_material_lots')->insertGetId(['lot_no'=>$this->numbers->next('material_lot','ML'),'item_id'=>$itemId,'configuration_id'=>$configurationId,
        'stage_id'=>$stageId,'material_form'=>$form,'cut_length_mm'=>$length,'source_type'=>'cutting_result','source_id'=>$resultId,'parent_lot_id'=>$parent,'created_at'=>now(),'updated_at'=>now()]); }

    private function holding(int $lotId, string $position, int $id, string $qty, string $cost): int
    { return DB::table('erp_material_holdings')->insertGetId(['material_lot_id'=>$lotId,'position_type'=>$position,'position_id'=>$id,'quantity'=>$qty,'total_cost'=>$cost,
        'status'=>'ACTIVE','business_version'=>1,'created_at'=>now(),'updated_at'=>now()]); }

    private function movement(object $batch, int $target, ?int $routeId, string $qty, string $cost, object $user): void
    { DB::table('erp_material_movements')->insert(['movement_no'=>$this->numbers->next('material_movement','MM'),'route_id'=>$routeId,
        'source_holding_id'=>$batch->wip_holding_id,'target_holding_id'=>$target,'action'=>'TRANSFORM','quantity'=>$qty,'total_cost'=>$cost,
        'operator_legacy_id'=>$this->commands->actor($user),'created_at'=>now(),'updated_at'=>now()]); }
}
