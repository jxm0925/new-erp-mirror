<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use Illuminate\Support\Facades\DB;

/** Field reporting only. No inventory, receipt, kitting or settlement effects. */
final class CuttingRecordService
{
    public function __construct(private readonly CuttingCommandService $commands, private readonly DocumentNumberService $numbers,
        private readonly CuttingDemandService $demands, private readonly CuttingMaterialEligibilityService $materials) {}

    public function publish(array $p, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands; $c->permission($permissions, 'production.cutting.plan');
        if (! is_array($p['plans'] ?? null)) $c->fail('source_missing', '请选择正式来源计划。');
        foreach ($p['plans'] as $plan) {
            if (! is_array($plan) || array_diff(array_keys($plan), ['work_order_id','stage_id','planned_qty','target_material_requirement_id','configuration_id','input_material_requirement_id'])) $c->fail('plan_fields_invalid','来源计划包含不允许的字段。');
            $c->workOrder((int) ($plan['work_order_id'] ?? 0), $user, $permissions, $super, 'production.cutting.plan');
            if (isset($plan['target_material_requirement_id'])) {
                $targetWo = DB::table('erp_production_target_material_requirements')->where('id',(int) $plan['target_material_requirement_id'])->value('work_order_id');
                if ($targetWo) $c->workOrder((int) $targetWo,$user,$permissions,$super,'production.cutting.plan');
            }
        }
        return $c->run('publish_cutting_order', 0, $p, $user, function () use ($c, $p, $user, $permissions, $super): array {
            if (($p['expected_version'] ?? null) !== 0) $c->fail('version_required', '新建下料单版本必须为0。');
            $plans = $p['plans'] ?? []; if (! is_array($plans) || ! array_is_list($plans) || count($plans) < 1 || count($plans) > 100) $c->fail('source_missing', '下料单必须包含1至100条正式来源计划。');
            $id = DB::table('erp_cutting_orders')->insertGetId(['cutting_order_no' => $this->numbers->next('cutting_order', 'CUT'),
                'status' => 'PUBLISHED', 'business_version' => 1, 'responsible_user_legacy_id' => $c->actor($user),
                'created_by_legacy_id' => $c->actor($user), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $taskId = DB::table('erp_cutting_tasks')->insertGetId(['cutting_order_id' => $id, 'task_no' => $this->numbers->next('cutting_task', 'CT'),
                'status' => 'READY', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            usort($plans, fn ($a, $b) => [(int) ($a['work_order_id'] ?? 0), (int) ($a['stage_id'] ?? 0)] <=> [(int) ($b['work_order_id'] ?? 0), (int) ($b['stage_id'] ?? 0)]);
            foreach ($plans as $plan) {
                $wo = $c->workOrder((int) ($plan['work_order_id'] ?? 0), $user, $permissions, $super, 'production.cutting.plan', true);
                if (! in_array($wo->status, ['RELEASED', 'IN_PROGRESS'], true)) $c->fail('source_not_released', '只能从已发布的正式工单建立下料计划。');
                $stage = (int) ($plan['stage_id'] ?? 0);
                $nodes = collect();
                foreach (['erp_production_quantity_operations', 'erp_production_unit_operations'] as $table)
                    $nodes = $nodes->concat(DB::table($table)->where('work_order_id', $wo->id)->where('routing_operation_id_snapshot', $stage)->get());
                $node = $nodes->first(); if (! $node) $c->fail('stage_invalid', '工序阶段不属于来源工单已冻结的正式路线。');
                $itemId = (int) ($node->output_item_id_snapshot ?: ($wo->effective_output_item_id_snapshot ?: $wo->output_item_id));
                $item = Item::find($itemId); if (! $item || $item->status !== 'enabled') $c->fail('output_item_invalid', '正式产出物料未启用。');
                $configId = isset($plan['configuration_id']) ? (int) $plan['configuration_id'] : null;
                $this->configuration($configId, $item, $wo->id);
                $qty = CuttingDecimal::value($plan['planned_qty'] ?? null);
                $demand = $this->demands->bind($plan,$wo,$node,$user,$permissions,$super);
                $targetId = (int) $demand->source_requirement_id;
                if ($targetId) {
                    $target = $this->target($targetId, $itemId, $user, $permissions, $super, 'production.cutting.plan');
                    $this->configuration($configId,$item,$target->work_order_id);
                }
                $snapshot = ['work_order_no' => $wo->work_order_no, 'work_order_version' => $wo->business_version,
                    'operation_code' => $node->operation_code_snapshot, 'operation_name' => $node->operation_name_snapshot,
                    'quality_mode' => $node->quality_mode_snapshot, 'output_mode' => $node->output_mode_snapshot,
                    'configuration_id' => $configId, 'configuration_version' => $configId ? DB::table('erp_custom_configurations')->where('id', $configId)->value('version_no') : null];
                $planId = DB::table('erp_cutting_plan_allocations')->insertGetId(['cutting_order_id' => $id, 'work_order_id' => $wo->id,
                    'target_material_requirement_id' => $targetId, 'output_item_id' => $itemId, 'configuration_id' => $configId, 'stage_id' => $stage,
                    'demand_id'=>$demand->id,'input_material_requirement_id'=>(int) $plan['input_material_requirement_id'],
                    'planned_qty' => $qty, 'source_snapshot' => json_encode($snapshot+['demand_id'=>$demand->id,'formal_requirement_id'=>$targetId,'input_material_requirement_id'=>(int) $plan['input_material_requirement_id']], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                $alreadyAllowed = DB::table('erp_cutting_allowed_outputs')->where('cutting_order_id',$id)->where('item_id',$itemId)
                    ->where('configuration_id',$configId)->where('stage_id',$stage)->where('quality_mode',$node->quality_mode_snapshot)
                    ->where('output_mode',$node->output_mode_snapshot)->where('work_mode',$node->work_mode_snapshot)->exists();
                if (! $alreadyAllowed) DB::table('erp_cutting_allowed_outputs')->insert(['cutting_order_id' => $id, 'plan_id' => $planId, 'item_id' => $itemId,
                    'configuration_id' => $configId, 'stage_id' => $stage, 'quality_mode' => $node->quality_mode_snapshot,
                    'output_mode' => $node->output_mode_snapshot, 'work_mode' => $node->work_mode_snapshot,
                    'rule_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
            $response = ['cutting_order_id' => $id, 'cutting_task_id' => $taskId, 'business_version' => 1, 'status' => 'PUBLISHED'];
            $c->event('order', $id, 'publish', $user, null, $response); return $response;
        });
    }

    public function saveResults(int $batchId, array $p, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands; $c->permission($permissions, 'production.cutting.record');
        $c->assertBatchVisible($batchId, $user, $permissions, $super, 'production.cutting.record');
        return $c->run('save_cutting_results', $batchId, $p, $user, function () use ($c, $batchId, $p, $user, $permissions, $super): array {
            $batch = $c->batch($batchId, $user, $permissions, $super, 'production.cutting.record'); $c->version($batch, $p);
            if ($batch->status !== 'PROCESSING') $c->fail('record_frozen', '已提交或已核算的加工记录不能直接修改。', 409);
            $this->input($batch);
            $rows = $p['results'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) < 1 || count($rows) > 100) $c->fail('results_invalid', '一次保存须包含1至100条结果。');
            $rowIds = []; $resultIds = [];
            foreach ($rows as $row) {
                if (! is_array($row)) $c->fail('results_invalid', '加工结果格式不合法。');
                if (array_diff(array_keys($row), ['client_row_id','result_type','allowed_output_id','actual_qty','piece_qty','cut_length_mm','measurement_status','measurements','reported_quality']))
                    $c->fail('result_fields_invalid', '加工结果包含不允许的字段，来源仅由本用料批次确定。');
                $key = $row['client_row_id'] ?? ''; if (! is_string($key) || strlen($key) < 1 || strlen($key) > 80 || in_array($key, $rowIds, true)) $c->fail('row_id_invalid', '结果行标识为空或重复。');
                $rowIds[] = $key; $data = $this->resultData($batch, $row);
                $existing = DB::table('erp_cutting_results')->where('settlement_batch_id', $batchId)->where('client_row_id', $key)->whereNotIn('status',['VOIDED','SUPERSEDED'])->lockForUpdate()->first();
                if ($existing && DB::table('erp_production_quality_inspections')->where('cutting_result_id',$existing->id)->exists()) {
                    // Inspected result identities/measurements are historical facts. Editing creates
                    // a replacement; neither an FK violation nor mutation of the inspected row is allowed.
                    $oldRoutes = DB::table('erp_cutting_result_routes')->where('result_id',$existing->id)->where('status','PLANNED')->orderBy('id')->lockForUpdate()->get();
                    DB::table('erp_cutting_results')->where('id',$existing->id)->update(['status'=>'SUPERSEDED','voided_at'=>now(),'voided_by_legacy_id'=>$c->actor($user),'updated_at'=>now()]);
                    $newId = DB::table('erp_cutting_results')->insertGetId($data+['settlement_batch_id'=>$batchId,'client_row_id'=>$key,'supersedes_result_id'=>$existing->id,
                        'status'=>'DRAFT','business_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
                    if ($existing->allowed_output_id == $data['allowed_output_id'] && $existing->actual_qty == $data['actual_qty'] && $existing->result_type === $data['result_type'])
                        foreach ($oldRoutes as $route) DB::table('erp_cutting_result_routes')->insert(['result_id'=>$newId,'route_type'=>$route->route_type,'target_material_requirement_id'=>$route->target_material_requirement_id,
                            'quantity'=>$route->quantity,'status'=>'PLANNED','business_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
                    $this->cancelDraftRoutes([$existing->id]);
                    $c->event('result',$existing->id,'supersede',$user,$existing,['replacement_result_id'=>$newId]);
                    $resultIds[] = $newId; continue;
                }
                if ($existing) {
                    // Quantity or identity changes invalidate only this result's route plan.
                    if ($existing->allowed_output_id != $data['allowed_output_id'] || $existing->actual_qty != $data['actual_qty'] || $existing->result_type !== $data['result_type'])
                        $this->cancelDraftRoutes([$existing->id]);
                    DB::table('erp_cutting_results')->where('id', $existing->id)->update($data + ['business_version' => $existing->business_version + 1, 'updated_at' => now()]);
                    $resultIds[] = $existing->id;
                } else $resultIds[] = DB::table('erp_cutting_results')->insertGetId($data + ['settlement_batch_id' => $batchId,
                    'client_row_id' => $key, 'business_version' => 1, 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()]);
            }
            // This is a full batch draft replacement, never an order-wide merge.
            $removed = DB::table('erp_cutting_results')->where('settlement_batch_id', $batchId)->whereNotIn('status',['VOIDED','SUPERSEDED'])->whereNotIn('client_row_id', $rowIds)->orderBy('id')->lockForUpdate()->get();
            $this->cancelDraftRoutes($removed->pluck('id')->all());
            foreach ($removed as $old) {
                DB::table('erp_cutting_results')->where('id',$old->id)->update(['status'=>'VOIDED','voided_at'=>now(),'voided_by_legacy_id'=>$c->actor($user),'business_version'=>$old->business_version+1,'updated_at'=>now()]);
                $c->event('result',$old->id,'void',$user,$old,['status'=>'VOIDED']);
            }
            DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->update(['business_version' => $batch->business_version + 1, 'updated_at' => now()]);
            $response = ['settlement_batch_id' => $batchId, 'result_ids' => $resultIds, 'business_version' => $batch->business_version + 1, 'status' => 'PROCESSING'];
            $c->event('batch', $batchId, 'save_results', $user, $batch, $response); return $response;
        });
    }

    public function splitRoutes(int $resultId, array $p, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands; $c->permission($permissions, 'production.cutting.record');
        $c->assertResultVisible($resultId, $user, $permissions, $super, 'production.cutting.record');
        return $c->run('split_cutting_result', $resultId, $p, $user, function () use ($c, $resultId, $p, $user, $permissions, $super): array {
            $row = DB::table('erp_cutting_results')->where('id', $resultId)->first(); if (! $row) $c->fail('result_missing', '产出结果不存在。', 404);
            $batch = $c->batch($row->settlement_batch_id, $user, $permissions, $super, 'production.cutting.record');
            $row = DB::table('erp_cutting_results')->where('id', $resultId)->lockForUpdate()->first(); $c->version($row, $p);
            $editableDraft = $batch->status === 'PROCESSING' && $row->status === 'DRAFT';
            $editableSubmitted = $batch->status === 'WAIT_ROUTE' && $row->status === 'SUBMITTED';
            if ((! $editableDraft && ! $editableSubmitted) || $row->result_type !== 'product')
                $c->fail('route_not_editable', '只有草稿或待完善去向的产品结果可以调整去向。', 409);
            $this->input($batch);
            $routes = $p['routes'] ?? null;
            if (! is_array($routes) || ! array_is_list($routes) || count($routes) > 100) $c->fail('routes_invalid', '去向明细格式不合法。');
            $sum = '0'; $insert = [];
            foreach ($routes as $route) {
                if (! is_array($route) || array_diff(array_keys($route), ['route_type','quantity','target_material_requirement_id']))
                    $c->fail('route_fields_invalid', '本页只保存去向计划，不能指定仓库、库位、批次或其他结果ID。');
                $type = $route['route_type'] ?? ''; if (! in_array($type, ['WAREHOUSE','NEXT_OPERATION'], true)) $c->fail('route_type_invalid', '请选择去下一工序或入库备货。');
                $qty = CuttingDecimal::value($route['quantity'] ?? null); $sum = bcadd($sum, $qty, 8);
                $targetId = isset($route['target_material_requirement_id']) ? (int) $route['target_material_requirement_id'] : null;
                $allowed = DB::table('erp_cutting_allowed_outputs')->where('id', $row->allowed_output_id)->first();
                if ($type === 'WAREHOUSE') {
                    if ($targetId) $c->fail('warehouse_target_invalid', '入库备货不能填下一工序目标。');
                    if ($allowed->output_mode === 'flow_only') $c->fail('flow_only_cannot_warehouse', '该正式路线产出仅允许直接流转。');
                } else {
                    if (! $targetId) $c->fail('target_missing', '去下一工序必须明确正式需求目标。');
                    if ($allowed->output_mode === 'warehouse_required') $c->fail('warehouse_required', '该正式路线产出必须先入库。');
                    $this->target($targetId, $row->item_id, $user, $permissions, $super, 'production.cutting.record');
                    $target = $this->target($targetId,$row->item_id,$user,$permissions,$super,'production.cutting.record');
                    $this->configuration($row->configuration_id,Item::findOrFail($row->item_id),$target->work_order_id);
                    if (! DB::table('erp_cutting_plan_allocations')->where('cutting_order_id',$batch->cutting_order_id)->where('output_item_id',$row->item_id)
                        ->where('configuration_id',$row->configuration_id)->where('stage_id',$row->stage_id)->where('target_material_requirement_id',$targetId)->exists())
                        $c->fail('target_not_planned', '去向目标不属于该产出的正式来源计划。');
                }
                $insert[] = ['result_id' => $resultId, 'route_type' => $type, 'target_material_requirement_id' => $targetId,
                    'quantity' => $qty, 'status' => 'PLANNED', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()];
            }
            if (bccomp($sum, (string) $row->actual_qty, 8) > 0) $c->fail('route_quantity_exceeded', '已指定数量不能超过这一条产出的实际数量。');
            $this->cancelDraftRoutes([$resultId]);
            if ($insert !== []) DB::table('erp_cutting_result_routes')->insert($insert);
            DB::table('erp_cutting_results')->where('id', $resultId)->update(['business_version' => $row->business_version + 1, 'updated_at' => now()]);
            $summary = $this->routeSummary($batch->id);
            $status = $batch->status;
            if ($status === 'WAIT_ROUTE' && $summary['route_complete']) $status = $this->postRouteStatus($batch->id);
            DB::table('erp_cutting_settlement_batches')->where('id', $batch->id)->update(['status'=>$status,
                'business_version' => $batch->business_version + 1, 'updated_at' => now()]);
            $resultSummary = collect($summary['results'])->firstWhere('result_id',$resultId);
            $response = ['result_id' => $resultId, 'business_version' => $row->business_version + 1, 'batch_business_version' => $batch->business_version + 1,
                'status'=>$status,'status_label'=>$this->statusLabel($status),'actual_qty'=>$resultSummary['actual_qty'],
                'assigned_qty'=>$resultSummary['assigned_qty'],'unassigned_qty'=>$resultSummary['unassigned_qty'],
                'route_complete'=>$resultSummary['route_complete'],'confirm_allowed'=>$status === 'WAIT_CONFIRM',
                'routes' => DB::table('erp_cutting_result_routes')->where('result_id', $resultId)->where('status','PLANNED')->get()->map(fn ($r) => (array) $r)->all()];
            $c->event('result', $resultId, 'split_routes', $user, $row, $response); return $response;
        });
    }

    public function submit(int $batchId, array $p, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands; $c->permission($permissions, 'production.cutting.record');
        $c->assertBatchVisible($batchId, $user, $permissions, $super, 'production.cutting.record');
        return $c->run('submit_cutting_results', $batchId, $p, $user, function () use ($c, $batchId, $p, $user, $permissions, $super): array {
            $batch = $c->batch($batchId, $user, $permissions, $super, 'production.cutting.record'); $c->version($batch, $p);
            if ($batch->status !== 'PROCESSING') $c->fail('record_frozen', '加工结果已提交，不能重复提交。', 409);
            $this->input($batch);
            $rows = DB::table('erp_cutting_results')->where('settlement_batch_id', $batchId)->whereNotIn('status',['VOIDED','SUPERSEDED'])->orderBy('id')->lockForUpdate()->get();
            if (! $rows->contains('result_type', 'product')) $c->fail('product_missing', '至少需要一条实际产品产出。');
            foreach ($rows as $row) {
                $this->resultData($batch, (array) $row, false);
                if ($row->result_type === 'product') {
                    $routes = DB::table('erp_cutting_result_routes')->where('result_id', $row->id)->where('status','PLANNED')->lockForUpdate()->get();
                    $sum = '0'; foreach ($routes as $route) {
                        $sum = bcadd($sum, (string) $route->quantity, 8);
                        if ($route->route_type === 'NEXT_OPERATION') $this->target($route->target_material_requirement_id, $row->item_id, $user, $permissions, $super, 'production.cutting.record');
                    }
                    if (bccomp($sum, (string) $row->actual_qty, 8) > 0) $c->fail('route_quantity_exceeded', '已指定数量不能超过这一条产出的实际数量。');
                }
            }
            $summary = $this->routeSummary($batchId);
            $status = $summary['route_complete'] ? $this->postRouteStatus($batchId) : 'WAIT_ROUTE';
            DB::table('erp_cutting_results')->whereIn('id',$rows->pluck('id'))->update(['status' => 'SUBMITTED', 'updated_at' => now()]);
            DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->update(['status' => $status,
                'submitted_at' => now(), 'business_version' => $batch->business_version + 1, 'updated_at' => now()]);
            $response = ['message' => '加工结果已提交', 'settlement_batch_id' => $batchId, 'status' => $status,
                'status_label'=>$this->statusLabel($status),'business_version' => $batch->business_version + 1,
                'actual_qty'=>$summary['actual_qty'],'assigned_qty'=>$summary['assigned_qty'],'unassigned_qty'=>$summary['unassigned_qty'],
                'route_complete'=>$summary['route_complete'],'confirm_allowed'=>$status === 'WAIT_CONFIRM','route_results'=>$summary['results']];
            $c->event('batch', $batchId, 'submit', $user, $batch, $response); return $response;
        });
    }

    public function inspect(int $resultId, array $p, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands; $c->permission($permissions, 'production.output.quality');
        $c->assertResultVisible($resultId, $user, $permissions, $super, 'production.output.quality');
        return $c->run('inspect_cutting_result', $resultId, $p, $user, function () use ($c, $resultId, $p, $user, $permissions, $super): array {
            $row = DB::table('erp_cutting_results')->where('id', $resultId)->first(); if (! $row) $c->fail('result_missing', '产出结果不存在。', 404);
            $batch = $c->batch($row->settlement_batch_id, $user, $permissions, $super, 'production.output.quality');
            $row = DB::table('erp_cutting_results')->where('id', $resultId)->lockForUpdate()->first(); $c->version($row, $p);
            if ($batch->status !== 'WAIT_QUALITY' || $row->quality_status !== 'WAIT_QUALITY' || $row->status !== 'SUBMITTED') $c->fail('quality_not_waiting', '该结果不处于当前待质检状态。', 409);
            if (! in_array($p['result'] ?? '', ['passed','failed'], true)) $c->fail('quality_invalid', '质检只接受整行合格或不合格；混合结果须先退回拆行。');
            $quality = $p['result'] === 'passed' ? 'PASSED' : 'FAILED';
            DB::table('erp_production_quality_inspections')->insert(['inspection_no' => $this->numbers->next('production_quality_inspection', 'PQI'),
                'output_record_id' => null, 'cutting_result_id' => $resultId, 'status' => 'COMPLETED', 'result' => $p['result'],
                'inspected_base_qty' => $row->actual_qty, 'qualified_base_qty' => $quality === 'PASSED' ? $row->actual_qty : '0',
                'unqualified_base_qty' => $quality === 'FAILED' ? $row->actual_qty : '0', 'reason' => $p['reason'] ?? null,
                'inspection_snapshot' => json_encode(['settlement_batch_id' => $batch->id, 'result_business_version' => $row->business_version,'result_snapshot'=>(array) $row], JSON_THROW_ON_ERROR),
                'inspector_legacy_id' => $c->actor($user), 'inspected_at' => now(), 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('erp_cutting_results')->where('id', $resultId)->update(['quality_status' => $quality, 'business_version' => $row->business_version + 1, 'updated_at' => now()]);
            $waiting = DB::table('erp_cutting_results')->where('settlement_batch_id', $batch->id)->where('status','SUBMITTED')->where('quality_status', 'WAIT_QUALITY')->exists();
            $failed = DB::table('erp_cutting_results')->where('settlement_batch_id', $batch->id)->where('status','SUBMITTED')->where('quality_status', 'FAILED')->exists();
            $status = $waiting ? 'WAIT_QUALITY' : ($failed ? 'QUALITY_FAILED' : 'WAIT_CONFIRM');
            DB::table('erp_cutting_settlement_batches')->where('id', $batch->id)->update(['status' => $status, 'business_version' => $batch->business_version + 1, 'updated_at' => now()]);
            $response = ['result_id' => $resultId, 'quality_status' => $quality, 'business_version' => $row->business_version + 1, 'batch_status' => $status];
            $c->event('result', $resultId, 'inspect', $user, $row, $response + ['reason' => $p['reason'] ?? null]); return $response;
        });
    }

    private function resultData(object $batch, array $row, bool $draft = true): array
    {
        $c = $this->commands; $type = $row['result_type'] ?? '';
        if (! in_array($type, ['product','usable_remnant','recyclable_scrap','process_loss','scrapped_output'], true)) $c->fail('result_type_invalid', '结果类型不合法。');
        $allowed = null;
        if ($type === 'product') {
            $allowed = DB::table('erp_cutting_allowed_outputs')->where('cutting_order_id', $batch->cutting_order_id)->where('id', (int) ($row['allowed_output_id'] ?? 0))->first();
            if (! $allowed) $c->fail('output_not_allowed', '该Item、配置或阶段不属于本下料任务允许的正式产出集合。');
            if (! $this->materials->plans($batch->cutting_order_id)->where('r.component_item_id',$batch->input_item_id)
                ->where('p.output_item_id',$allowed->item_id)->where('p.configuration_id',$allowed->configuration_id)->where('p.stage_id',$allowed->stage_id)->exists())
                $c->fail('output_input_mismatch','当前实际投入原料不属于这条产出的正式需求及冻结工序。');
            $item = Item::find($allowed->item_id); $plan = DB::table('erp_cutting_plan_allocations')->where('id', $allowed->plan_id)->first();
            $this->configuration($allowed->configuration_id, $item, $plan->work_order_id);
        } elseif (! empty($row['allowed_output_id'])) $c->fail('other_output_identity_invalid', '其他加工结果不能冒充正式产品产出。');
        $measurement = $row['measurement_status'] ?? 'NOT_RECORDED';
        if (! in_array($measurement, ['MEASURED','NOT_MEASURED','NOT_RECORDED'], true)) $c->fail('measurement_invalid', '请选择实测、未测量或未登记。');
        $qty = isset($row['actual_qty']) ? CuttingDecimal::value($row['actual_qty'], 8, $type !== 'product') : null;
        if ($type === 'product' && $qty === null) $c->fail('quantity_required', '产品必须登记实际数量。');
        if ($type !== 'product' && (($measurement === 'MEASURED') !== ($qty !== null))) $c->fail('measurement_quantity_invalid', '实测须填写数量；未测量或未登记不得自动写0。');
        $measurements = $row['measurements'] ?? null;
        if (is_string($measurements)) $measurements = json_decode($measurements, true, 512, JSON_THROW_ON_ERROR);
        if ($measurements !== null && ! is_array($measurements)) $c->fail('measurement_invalid', '尺寸和重量必须为结构化实测记录。');
        if ($measurement !== 'MEASURED' && $measurements !== null) $c->fail('measurement_invalid', '未测量或未登记不能填写实测尺寸或重量。');
        foreach ($measurements ?? [] as $key => $value) {
            if (! in_array($key, ['length_mm','width_mm','thickness_mm','weight_kg','area_mm2','shape'], true)) $c->fail('measurement_invalid', '实测字段不合法。');
            if ($key === 'shape') { if (! in_array($value, ['RECTANGLE','IRREGULAR'], true)) $c->fail('shape_invalid', '余料形状不合法。'); }
            else CuttingDecimal::value($value, 8, true);
        }
        $quality = $allowed && $allowed->quality_mode !== 'none' ? 'WAIT_QUALITY' : 'NOT_REQUIRED';
        if (isset($row['reported_quality']) && ! in_array($row['reported_quality'], ['qualified','unqualified'], true)) $c->fail('reported_quality_invalid','现场申报只能填写合格或不合格，不代替正式质检。');
        return ['result_type' => $type, 'allowed_output_id' => $allowed?->id, 'item_id' => $allowed?->item_id,
            'configuration_id' => $allowed?->configuration_id, 'stage_id' => $allowed?->stage_id, 'actual_qty' => $qty,
            'piece_qty' => isset($row['piece_qty']) ? CuttingDecimal::value($row['piece_qty']) : null,
            'cut_length_mm' => isset($row['cut_length_mm']) ? CuttingDecimal::value($row['cut_length_mm'], 2) : null,
            'measurements' => $measurements === null ? null : json_encode($measurements, JSON_THROW_ON_ERROR), 'measurement_status' => $measurement,
            'quality_status' => $draft ? $quality : $row['quality_status'], 'reported_quality' => $row['reported_quality'] ?? null];
    }

    public function configuration(?int $id, Item $item, int $woId): void
    {
        $c = $this->commands;
        if (! $id) { if ($item->is_custom_item) $c->fail('configuration_required', '定制物料必须选择正式发布的配置版本。'); return; }
        $config = DB::table('erp_custom_configurations')->where('id', $id)->where('item_id', $item->id)->where('status', 'PUBLISHED')->first();
        if (! $config) $c->fail('configuration_invalid', '配置版本未发布或不属于该物料。');
        if ($config->scope_mode !== 'PUBLIC' && ! DB::table('erp_custom_configuration_scopes')->where('configuration_id', $id)->where('source_type', 'work_order')->where('source_id', $woId)->exists())
            $c->fail('configuration_scope_denied', '配置版本不允许用于该正式来源工单。', 403);
    }

    public function assertInput(object $batch): void
    { $this->input($batch); }

    public function assertResultIdentity(object $batch, object $row): void
    {
        $data = $this->resultData($batch, (array) $row, false);
        foreach (['item_id','configuration_id','stage_id'] as $field)
            if ($row->{$field} != $data[$field]) $this->commands->fail('output_identity_changed', '产出身份与冻结允许集合不一致。', 409);
    }

    public function returnForEdit(int $batchId, array $p, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands; $c->permission($permissions, 'production.cutting.confirm');
        $c->assertBatchVisible($batchId, $user, $permissions, $super, 'production.cutting.confirm');
        return $c->run('return_cutting_for_edit', $batchId, $p, $user, function () use ($c,$batchId,$p,$user,$permissions,$super): array {
            $batch = $c->batch($batchId,$user,$permissions,$super,'production.cutting.confirm'); $c->version($batch,$p);
            if (! in_array($batch->status,['WAIT_ROUTE','WAIT_CONFIRM','WAIT_QUALITY','QUALITY_FAILED'],true)) $c->fail('return_edit_invalid','只有待完善去向、待确认、待质检或质量不合格的记录可以退回修改。',409);
            if (! is_string($p['reason'] ?? null) || trim($p['reason']) === '' || mb_strlen($p['reason']) > 1000) $c->fail('reason_required','请填写退回修改原因。');
            $this->input($batch);
            $rows = DB::table('erp_cutting_results')->where('settlement_batch_id',$batchId)->whereNotIn('status',['VOIDED','SUPERSEDED'])->orderBy('id')->lockForUpdate()->get();
            foreach ($rows as $row) {
                $allowed = $row->allowed_output_id ? DB::table('erp_cutting_allowed_outputs')->where('id',$row->allowed_output_id)->first() : null;
                DB::table('erp_cutting_results')->where('id',$row->id)->update(['status'=>'DRAFT','quality_status'=>$allowed && $allowed->quality_mode !== 'none' ? 'WAIT_QUALITY' : 'NOT_REQUIRED',
                    'business_version'=>$row->business_version+1,'updated_at'=>now()]);
            }
            // Past inspections remain immutable. A later confirm must reference a fresh row version.
            DB::table('erp_cutting_settlement_batches')->where('id',$batchId)->update(['status'=>'PROCESSING','submitted_at'=>null,'business_version'=>$batch->business_version+1,'updated_at'=>now()]);
            $response = ['settlement_batch_id'=>$batchId,'status'=>'PROCESSING','business_version'=>$batch->business_version+1];
            $c->event('batch',$batchId,'return_for_edit',$user,$batch,$response+['reason'=>$p['reason']]); return $response;
        });
    }

    private function input(object $batch): void
    {
        $c = $this->commands;
        $this->materials->assertItem($batch->cutting_order_id,$batch->input_item_id);
        if ($batch->physical_material_id) {
            $physical = DB::table('erp_material_physicals')->where('id',$batch->physical_material_id)->lockForUpdate()->first();
            if (! $physical || $physical->status !== 'ISSUED' || (int) $physical->item_id !== (int) $batch->input_item_id
                || (int) $physical->current_holding_id !== (int) $batch->wip_holding_id)
                $c->fail('input_physical_conflict','来源实物已不属于本用料批次的有效投入。',409);
        }
        $wip = DB::table('erp_material_holdings')->where('id',$batch->wip_holding_id)->lockForUpdate()->first();
        if (! $wip || $wip->status !== 'ACTIVE' || $wip->position_type !== 'CUTTING_WIP' || (int) $wip->position_id !== (int) $batch->id
            || bccomp((string) $wip->quantity,(string) $batch->input_qty,8) !== 0 || bccomp((string) $wip->total_cost,(string) $batch->original_total_cost,4) !== 0)
            $c->fail('input_wip_conflict','实际投入的在制数量或金额不完整。',409);
        if (! DB::table('erp_inventory_transactions')->where('id',$batch->issue_transaction_id)->where('posting_status','posted')
            ->where('source_type','cutting_settlement')->where('source_id',$batch->id)->exists()) $c->fail('input_not_issued','用料批次尚未通过正式领料，不允许登记结果。',409);
    }

    private function target(int $id, int $itemId, object $user, array $permissions, bool $super, string $permission): object
    {
        $c = $this->commands;
        $target = DB::table('erp_production_target_material_requirements')->where('id', $id)->lockForUpdate()->first();
        if (! $target || (int) $target->component_item_id !== $itemId) $c->fail('target_invalid', '正式目标需求不存在或物料不匹配。');
        $wo = $c->workOrder($target->work_order_id, $user, $permissions, $super, $permission);
        if (! in_array($wo->status, ['RELEASED','IN_PROGRESS'], true)) $c->fail('target_not_active', '目标工单已关闭或尚未正式发布。');
        return $target;
    }

    private function cancelDraftRoutes(array $resultIds): void
    {
        if (DB::table('erp_cutting_result_routes')->whereIn('result_id',$resultIds)->whereNotIn('status',['PLANNED','CANCELLED'])->exists())
            $this->commands->fail('route_frozen','已有正式去向事实的结果不能通过草稿编辑撤销。',409);
        DB::table('erp_cutting_result_routes')->whereIn('result_id',$resultIds)->where('status','PLANNED')->update(['status'=>'CANCELLED','business_version'=>DB::raw('business_version+1'),'updated_at'=>now()]);
    }

    private function routeSummary(int $batchId): array
    {
        $rows = DB::table('erp_cutting_results')->where('settlement_batch_id',$batchId)->where('result_type','product')
            ->whereNotIn('status',['VOIDED','SUPERSEDED'])->orderBy('id')->get();
        $actual = '0.00000000'; $assigned = '0.00000000'; $details = [];
        foreach ($rows as $row) {
            $rowAssigned = '0.00000000';
            foreach (DB::table('erp_cutting_result_routes')->where('result_id',$row->id)->where('status','PLANNED')->pluck('quantity') as $quantity)
                $rowAssigned = bcadd($rowAssigned,(string) $quantity,8);
            if (bccomp($rowAssigned,(string) $row->actual_qty,8) > 0)
                $this->commands->fail('route_quantity_exceeded','已指定数量不能超过这一条产出的实际数量。');
            $rowActual = CuttingDecimal::value((string) $row->actual_qty);
            $unassigned = bcsub($rowActual,$rowAssigned,8);
            $actual = bcadd($actual,$rowActual,8); $assigned = bcadd($assigned,$rowAssigned,8);
            $details[] = ['result_id'=>(int) $row->id,'actual_qty'=>$rowActual,'assigned_qty'=>$rowAssigned,
                'unassigned_qty'=>$unassigned,'route_complete'=>bccomp($unassigned,'0',8) === 0];
        }
        $unassigned = bcsub($actual,$assigned,8);
        return ['actual_qty'=>$actual,'assigned_qty'=>$assigned,'unassigned_qty'=>$unassigned,
            'route_complete'=>$details !== [] && collect($details)->every(fn (array $row) => $row['route_complete']),
            'results'=>$details];
    }

    private function postRouteStatus(int $batchId): string
    {
        return DB::table('erp_cutting_results')->where('settlement_batch_id',$batchId)->whereNotIn('status',['VOIDED','SUPERSEDED'])
            ->where('quality_status','WAIT_QUALITY')->exists() ? 'WAIT_QUALITY' : 'WAIT_CONFIRM';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'WAIT_ROUTE'=>'待完善去向','WAIT_QUALITY'=>'待质检','WAIT_CONFIRM'=>'待用料确认',
            'PROCESSING'=>'草稿',default=>$status,
        };
    }
}
