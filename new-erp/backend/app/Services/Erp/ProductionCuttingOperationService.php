<?php

namespace App\Services\Erp;

use App\Models\Erp\{ProductionTask, ProductionUnitOperation, ProductionQuantityOperation, WorkOrder};
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** A cutting record is the material subledger of one production operation, never a second clock or stock issue. */
final class ProductionCuttingOperationService
{
    public function __construct(private readonly CuttingCommandService $commands, private readonly DocumentNumberService $numbers) {}

    public function requirements(string $type, int $id): Builder
    {
        return DB::table('erp_production_target_material_requirements as target')
            ->join('erp_work_order_material_requirements as material', 'material.id', '=', 'target.material_requirement_id')
            ->join('erp_items as raw', 'raw.id', '=', 'target.component_item_id')
            ->where('target.target_type', $type)->where('target.target_id', $id)
            // Released dimensional requirements remain authoritative after master-data edits.
            ->whereNotNull('material.cutting_requirement_snapshot');
    }

    public function required(string $type, int $id): bool
    {
        return $this->requirements($type, $id)->exists();
    }

    public function linkedOrder(int $orderId): ?object
    {
        return DB::table('erp_production_cutting_operations')->where('cutting_order_id', $orderId)->first();
    }

    public function view(int $taskId, string $type, int $id, array $filters, object $user, array $permissions, bool $super = false): array
    {
        [$task, $target] = $this->context($taskId,$type,$id,$user,$permissions,$super);
        $taskView = app(ProductionTaskQueryService::class)->show($taskId,$user,$permissions,$super)->toArray();
        $workOrder = WorkOrder::with('outputItem')->findOrFail($task->work_order_id);
        $link = DB::table('erp_production_cutting_operations')->where('target_type',$type)->where('target_id',$id)->first();
        $requirements = $this->requirements($type,$id)->get(['material.*']);
        $firstSize = $requirements->first()?->cutting_requirement_snapshot;
        $output = DB::table('erp_items as item')->leftJoin('erp_units as unit','unit.id','=','item.unit_id')
            ->where('item.id',$target->output_item_id_snapshot)->first(['item.id','item.item_code','item.item_name','item.spec','unit.unit_name']);
        $batchPage = DB::table('erp_cutting_settlement_batches as batch')->leftJoin('erp_material_physicals as physical','physical.id','=','batch.physical_material_id')
            ->join('erp_items as item','item.id','=','batch.input_item_id')->where('batch.cutting_order_id',$link?->cutting_order_id ?? 0)
            ->select('batch.*','physical.physical_no','physical.dimensions','item.item_name','item.item_code','item.cutting_mode')
            ->orderBy('batch.id')->paginate(10,['*'],'batch_page',max(1,(int) ($filters['batch_page'] ?? 1)));
        $batchId = (int) ($filters['settlement_batch_id'] ?? ($batchPage->items()[0]->id ?? 0));
        $batch = $link && $batchId ? DB::table('erp_cutting_settlement_batches as batch')
            ->leftJoin('erp_material_physicals as physical','physical.id','=','batch.physical_material_id')
            ->join('erp_items as item','item.id','=','batch.input_item_id')
            ->where('batch.id',$batchId)->where('batch.cutting_order_id',$link->cutting_order_id)
            ->first(['batch.*','physical.physical_no','physical.dimensions','item.item_name','item.item_code','item.cutting_mode']) : null;
        $results = $batch ? DB::table('erp_cutting_results')->where('settlement_batch_id',$batch->id)->whereNotIn('status',['VOIDED','SUPERSEDED','REVERSED'])->orderBy('id')->get() : collect();
        $nextQuery = $type === 'unit_operation' ? ProductionUnitOperation::query()->where('production_unit_id',$target->production_unit_id) : ProductionQuantityOperation::query()->where('work_order_id',$task->work_order_id);
        $next = $nextQuery->where('sequence_no_snapshot','>',$target->sequence_no_snapshot)->orderBy('sequence_no_snapshot')->first();
        $data = ['work_order_id'=>$workOrder->id,'work_order_no'=>$workOrder->work_order_no,'planned_date'=>$workOrder->planned_date,
            'task'=>$taskView,'target'=>collect($taskView['target_details'] ?? [])->first(fn ($t) => ($t['target_type'] ?? '') === $type && (int) ($t['target_id'] ?? 0) === $id),
            'output'=>$output,'dimensions'=>$firstSize ? json_decode($firstSize,true,512,JSON_THROW_ON_ERROR) : null,
            'operation_id'=>$link?->id,'cutting_order_id'=>$link?->cutting_order_id,'next_operation'=>$next ? ['name'=>$next->operation_name_snapshot,'code'=>$next->operation_code_snapshot,'status'=>$next->status] : null,
            'batches'=>['data'=>$batchPage->items(),'meta'=>['current_page'=>$batchPage->currentPage(),'last_page'=>$batchPage->lastPage(),'total'=>$batchPage->total()]],
            'current_batch'=>$batch,'results'=>$results,'server_now'=>now()->toISOString(),
            'technical_attachments'=>$workOrder->technical_snapshot['attachments'] ?? []];
        $componentMode = $link ? ($this->json($link->technical_snapshot)['output_kind'] ?? null) === ProductionCuttingComponentService::KIND
            : strtoupper((string) ($target->stage_code_snapshot ?: $target->operation_code_snapshot)) === 'CUTTING';
        if ($componentMode) {
            $data['output_kind'] = ProductionCuttingComponentService::KIND;
            $data['output'] = null;
            $rows = $link ? $this->json($link->technical_snapshot)['requirements'] : $this->requirements($type, $id)
                ->get(['target.id as target_requirement_id', 'target.required_piece_qty_snapshot as required_piece_qty', 'material.bom_item_id', 'material.component_item_id',
                    'material.component_item_code_snapshot', 'material.component_item_name_snapshot', 'target.cutting_requirement_snapshot'])->map(fn ($r) => (array) $r)->all();
            $data['component_requirements'] = $this->componentProjection($rows, $link);
            if ($batch) {
                $frozen = $this->json(DB::table('erp_production_cutting_inputs')->where('settlement_batch_id', $batch->id)->value('requirement_snapshot'));
                $data['current_batch']->actual_stock_qty = $frozen['actual_stock_qty'] ?? null;
                $data['current_batch']->stock_unit_kind = $frozen['stock_unit_kind'] ?? null;
                $proven = collect($frozen['components'] ?? [])->keyBy('target_material_requirement_id');
                $data['current_batch']->component_requirements = collect($data['component_requirements'])->filter(fn ($r) => $proven->has($r['target_material_requirement_id']))
                    ->map(function ($r) use ($proven, $batch, $link) {
                        $source = $proven->get($r['target_material_requirement_id']);
                        $used = $this->componentQuantityUsage((int) $link->cutting_order_id, $r['target_material_requirement_id'], (int) $batch->id);
                        if (($r['quantity_basis'] ?? null) === 'total_length') {
                            $left = bcsub($r['required_total_length_mm'], $used, 8);
                            return $r + ['covered_total_length_mm' => $source['covered_total_length_mm'],
                                'max_total_length_mm' => bccomp($left, $source['covered_total_length_mm'], 8) < 0 ? (bccomp($left, '0', 8) > 0 ? $left : '0.00000000') : $source['covered_total_length_mm']];
                        }
                        $left = bcsub($r['required_piece_qty'], $used, 8);
                        return $r + ['covered_piece_qty' => $source['covered_piece_qty'], 'max_piece_qty' => bccomp($left, $source['covered_piece_qty'], 8) < 0 ? max(0, (int) $left) : (int) $source['covered_piece_qty']];
                    })->values()->all();
            }
        } elseif ($batch) $data['requirement'] = $this->frozenRequirement($batch, DB::table('erp_cutting_allowed_outputs')->where('cutting_order_id',$link->cutting_order_id)->first());
        if ($batch) $data['current_batch']->remnant_review = app(CuttingRemnantBalanceService::class)->projection($batch, $results);
        $running = in_array($target->status, ['IN_PROGRESS', 'PAUSED'], true) && ! $task->active_job_bundle_id;
        $data['allowed_actions'] = [
            'prepare' => ! $link && in_array($target->status, ['WAIT_MATERIAL', 'READY', 'IN_PROGRESS', 'PAUSED'], true) && ! $task->active_job_bundle_id,
            'use_material' => (bool) $link && in_array($target->status, ['READY', 'IN_PROGRESS', 'PAUSED'], true) && ! $task->active_job_bundle_id,
            'save_results' => $running && $batch?->status === 'PROCESSING',
            'finish' => $running && (bool) $link && $batchPage->total() > 0 && in_array('production.task.complete', $permissions, true),
        ];
        return app(ProductionFinancialProjectionService::class)->redact($data);
    }

    public function materials(int $taskId, string $type, int $id, array $filters, object $user, array $permissions, bool $super = false): array
    {
        $this->context($taskId,$type,$id,$user,$permissions,$super);
        $query = DB::table('erp_production_input_holdings as input')->join('erp_material_holdings as holding','holding.id','=','input.input_holding_id')
            ->join('erp_production_target_material_requirements as requirement','requirement.id','=','input.target_material_requirement_id')
            ->join('erp_work_order_material_requirements as material','material.id','=','requirement.material_requirement_id')
            ->join('erp_items as item','item.id','=','requirement.component_item_id')
            ->join('erp_inventory_transaction_items as posted','posted.id','=','input.inventory_transaction_item_id')
            ->leftJoin('erp_material_physicals as physical',fn ($join) => $join->on('physical.item_id','=','item.id')->on('physical.current_holding_id','=','holding.id')
                ->where('item.cutting_mode','sheet')->where('physical.status','PRODUCTION_RECEIVED'))
            ->where('input.target_type',$type)->where('input.target_id',$id)->where('input.status','ACTIVE')->where('holding.status','ACTIVE')->where('holding.quantity','>',0)
            ->whereNotNull('material.cutting_requirement_snapshot')
            ->where(fn ($q) => $q->where('item.cutting_mode','length')->orWhere(fn ($legacy) => $legacy->whereNull('item.cutting_mode')->where('item.is_length_cut_material',true))
                ->orWhere(fn ($sheet) => $sheet->where('item.cutting_mode','sheet')->whereNotNull('physical.id')));
        if (! empty($filters['category_id'])) $query->where('item.category_id',(int) $filters['category_id']);
        if (! empty($filters['keyword'])) {
            $word = '%'.$filters['keyword'].'%';
            $query->where(fn ($q) => $q->where('item.item_code','like',$word)->orWhere('item.item_name','like',$word)->orWhere('item.spec','like',$word)->orWhere('physical.physical_no','like',$word));
        }
        if (($filters['mode'] ?? '') === 'categories') {
            $query->join('erp_item_categories as category','category.id','=','item.category_id')->select('category.id','category.category_name')->distinct()->orderBy('category.id');
        } else {
            $query->select('input.id as production_input_holding_id','physical.id as physical_material_id','physical.physical_no','physical.dimensions',
                'item.item_code','item.item_name','item.spec','item.cutting_mode','item.standard_stock_length_mm','material.base_unit_name_snapshot as stock_unit_name','holding.quantity as available_qty','posted.batch_no')->orderBy('input.id')->orderBy('physical.id');
        }
        $page = $query->paginate(min(20,max(1,(int) ($filters['per_page'] ?? 10))),['*'],'page',max(1,(int) ($filters['page'] ?? 1)));
        return ['data'=>$page->items(),'meta'=>['current_page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'total'=>$page->total()]];
    }

    public function prepare(int $taskId, string $type, int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->context($taskId, $type, $id, $user, $permissions, $super);
        if (array_diff(array_keys($payload), ['client_command_id', 'expected_version'])) $this->commands->fail('command_fields_invalid', '下料准备不接受手填产出或路线。');
        return $this->commands->run('prepare_production_cutting', $id, $payload + ['production_task_id'=>$taskId, 'target_type'=>$type], $user,
            function () use ($taskId, $type, $id, $payload, $user, $permissions, $super): array {
                [$task, $target] = $this->context($taskId, $type, $id, $user, $permissions, $super, true);
                $existing = DB::table('erp_production_cutting_operations')->where('target_type', $type)->where('target_id', $id)->first();
                if ($existing) return ['operation_id'=>$existing->id, 'cutting_order_id'=>$existing->cutting_order_id];
                $this->commands->version($target, $payload);
                if (! in_array($target->status, ['WAIT_MATERIAL', 'READY', 'IN_PROGRESS', 'PAUSED'], true))
                    $this->commands->fail('cutting_operation_state_invalid', '当前工序尚不能登记下料。', 409);
                $requirements = $this->requirements($type, $id)->get(['target.id as target_requirement_id', 'target.required_piece_qty_snapshot as target_required_piece_qty', 'target.required_base_qty as target_required_base_qty', 'target.cutting_requirement_snapshot as target_cutting_requirement_snapshot', 'material.*']);
                if ($requirements->isEmpty()) $this->commands->fail('cutting_requirement_missing', '本工序没有冻结的板材或管材下料要求。', 409);
                $workOrder = WorkOrder::findOrFail($task->work_order_id);
                $componentMode = strtoupper((string) ($target->stage_code_snapshot ?: $target->operation_code_snapshot)) === 'CUTTING';
                if ($componentMode) app(ProductionCuttingComponentService::class)->assertTarget($target, $type);
                if ($componentMode) foreach ($requirements as $requirement) {
                    $requirement->required_piece_qty = $requirement->target_required_piece_qty;
                    $requirement->cutting_requirement_snapshot = $requirement->target_cutting_requirement_snapshot;
                }
                $outputItemId = $componentMode ? null : (int) $target->output_item_id_snapshot;
                if (! $componentMode && ! $outputItemId) $this->commands->fail('cutting_output_missing', '请先由技术人员维护工序产出，现场不能另选产品。', 409);
                $configurationId = $outputItemId === (int) $workOrder->output_item_id ? $workOrder->output_configuration_id : null;
                if (DB::table('erp_items')->where('id', $outputItemId)->where('is_custom_item', true)->exists() && ! $configurationId)
                    $this->commands->fail('cutting_configuration_missing', '定制工序产出缺少冻结配置，不能使用其他产品的配置。', 409);
                $actor = $this->commands->actor($user); $now = now();
                $orderId = DB::table('erp_cutting_orders')->insertGetId([
                    'cutting_order_no'=>$this->numbers->next('cutting_order', 'CUT'), 'purpose'=>'WORKER', 'status'=>'IN_PROGRESS',
                    'business_version'=>1, 'responsible_user_legacy_id'=>$actor, 'created_by_legacy_id'=>$actor, 'created_at'=>$now, 'updated_at'=>$now,
                ]);
                // This compatibility task supplies existing cutting visibility only. Its lifecycle is blocked;
                // production task labor sessions remain the sole elapsed/person-time authority.
                $cuttingTaskId = DB::table('erp_cutting_tasks')->insertGetId([
                    'cutting_order_id'=>$orderId, 'task_no'=>$this->numbers->next('cutting_task', 'CT'), 'status'=>'READY',
                    'assignee_user_legacy_id'=>$actor, 'claimed_at'=>$now, 'business_version'=>1, 'created_at'=>$now, 'updated_at'=>$now,
                ]);
                $snapshot = ['work_order_no'=>$workOrder->work_order_no, 'technical_version'=>$workOrder->technical_version,
                    'output_kind'=>$componentMode ? ProductionCuttingComponentService::KIND : 'item',
                    'output_item_id'=>$outputItemId, 'configuration_id'=>$configurationId, 'operation_name'=>$target->operation_name_snapshot,
                    'stage_id'=>$target->routing_operation_id_snapshot, 'requirements'=>$requirements->map(fn ($r) => (array) $r)->all()];
                $operationId = DB::table('erp_production_cutting_operations')->insertGetId([
                    'work_order_id'=>$workOrder->id, 'production_task_id'=>$taskId, 'target_type'=>$type, 'target_id'=>$id,
                    'cutting_order_id'=>$orderId, 'cutting_task_id'=>$cuttingTaskId, 'technical_snapshot'=>json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'created_by_legacy_id'=>$actor, 'created_at'=>$now, 'updated_at'=>$now,
                ]);
                // Quality and destination are enforced once by ordinary production completion/quality/handover.
                foreach ($componentMode ? $requirements : [null] as $requirement) {
                    $cutting = $componentMode ? $this->json($requirement->cutting_requirement_snapshot) : [];
                    if ($componentMode && (! $requirement->bom_item_id || ! $cutting || (FrozenCuttingStockPolicy::isTotalLength($cutting)
                        ? ! FrozenCuttingStockPolicy::requiredTotalLength($cutting) : ! $requirement->required_piece_qty)))
                        $this->commands->fail('cutting_component_requirement_invalid', '下料组件必须有冻结的 BOM 原料行、尺寸和实际件数。', 409);
                    if ($componentMode && ! in_array(data_get($this->json($requirement->cutting_requirement_snapshot), 'quantity_calculation.unit_kind'), ['root', 'sheet', 'm', 'm2'], true))
                        $this->commands->fail('cutting_component_stock_unit_not_supported', '下料原料必须冻结米、平方米、根或张的真实库存计量口径。', 409);
                    DB::table('erp_cutting_allowed_outputs')->insert([
                        'cutting_order_id'=>$orderId, 'item_id'=>$componentMode ? $requirement->component_item_id : $outputItemId,
                        'configuration_id'=>$componentMode ? $requirement->configuration_id : $configurationId,
                        'stage_id'=>$target->routing_operation_id_snapshot, 'quality_mode'=>'none', 'output_mode'=>'stockable', 'work_mode'=>$target->work_mode_snapshot ?: 'manual',
                        'rule_snapshot'=>json_encode($snapshot + ['target_material_requirement_id'=>$requirement?->target_requirement_id], JSON_THROW_ON_ERROR), 'created_at'=>$now, 'updated_at'=>$now,
                    ]);
                }
                $result = ['operation_id'=>$operationId, 'cutting_order_id'=>$orderId];
                $this->commands->event('order', $orderId, 'prepare_operation', $user, null, $result + ['production_task_id'=>$taskId, 'target_type'=>$type, 'target_id'=>$id]);
                return $result;
            });
    }

    /** Actual material has already left warehouse and been accepted by this operation. */
    public function useMaterial(int $taskId, string $type, int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->context($taskId, $type, $id, $user, $permissions, $super);
        if (array_diff(array_keys($payload), ['client_command_id','expected_version','production_input_holding_id','physical_material_id','quantity']))
            $this->commands->fail('command_fields_invalid', '只能选择本工序实际已收材料。');
        return $this->commands->run('use_production_cutting_material', $id, $payload + ['production_task_id'=>$taskId,'target_type'=>$type], $user,
            function () use ($taskId, $type, $id, $payload, $user, $permissions, $super): array {
                [$task, $target] = $this->context($taskId, $type, $id, $user, $permissions, $super, true);
                $this->commands->version($target, $payload);
                if (! in_array($target->status, ['READY','IN_PROGRESS','PAUSED'], true)) $this->commands->fail('cutting_operation_state_invalid', '当前工序不能添加加工用料。', 409);
                $link = DB::table('erp_production_cutting_operations')->where('target_type',$type)->where('target_id',$id)->lockForUpdate()->first();
                if (! $link) $this->commands->fail('cutting_operation_missing', '请先打开本工序下料记录。', 409);
                $componentMode = ($this->json($link->technical_snapshot)['output_kind'] ?? null) === ProductionCuttingComponentService::KIND;
                $input = DB::table('erp_production_input_holdings')->where('id',(int) ($payload['production_input_holding_id'] ?? 0))
                    ->where('target_type',$type)->where('target_id',$id)->where('status','ACTIVE')->lockForUpdate()->first();
                $holding = $input ? DB::table('erp_material_holdings')->where('id',$input->input_holding_id)->lockForUpdate()->first() : null;
                $requirement = $input ? $this->requirements($type,$id)->where('target.id',$input->target_material_requirement_id)
                    ->first(['target.id as target_requirement_id','material.*','raw.cutting_mode','raw.is_length_cut_material','raw.standard_stock_length_mm']) : null;
                if (! $input || ! $holding || ! $requirement || $holding->status !== 'ACTIVE' || $holding->position_type !== 'PRODUCTION_INPUT'
                    || (int) $holding->position_id !== (int) $input->id || ! $input->inventory_transaction_item_id || ! $input->material_receipt_line_id)
                    $this->commands->fail('cutting_received_material_required', '只能使用本工序由仓库正式出库并已确认收料的原材料。', 409);
                $posted = DB::table('erp_inventory_transaction_items as line')->join('erp_inventory_transactions as tx','tx.id','=','line.transaction_id')
                    ->where('line.id',$input->inventory_transaction_item_id)->where('tx.posting_status','posted')
                    ->where('tx.transaction_type','production_material_picking_outbound')->first(['line.*']);
                if (! $posted || (int) $posted->item_id !== (int) $requirement->component_item_id
                    || ! DB::table('erp_material_movements')->where('target_holding_id',$holding->id)->where('source_holding_id',$input->source_holding_id)
                        ->where('action','PROD_MATERIAL_RECEIPT')->exists())
                    $this->commands->fail('cutting_material_ancestry_invalid', '本次用料缺少正式出库与收料的连续凭据。', 409);
                if ($componentMode) {
                    $frozenMode = data_get($this->json($requirement->cutting_requirement_snapshot), 'quantity_calculation.mode');
                    if (! in_array($frozenMode, ['length', 'sheet'], true)) $this->commands->fail('cutting_component_mode_missing', '组件下料必须使用已发布的原料加工方式。', 409);
                    $requirement->cutting_mode = $frozenMode;
                }
                $physical = null;
                if ($requirement->cutting_mode === 'sheet') {
                    $physical = DB::table('erp_material_physicals')->where('id',(int) ($payload['physical_material_id'] ?? 0))->lockForUpdate()->first();
                    if (! $physical || $physical->status !== 'PRODUCTION_RECEIVED' || (int) $physical->item_id !== (int) $posted->item_id
                        || (int) $physical->current_holding_id !== (int) $holding->id)
                        $this->commands->fail('cutting_physical_receipt_mismatch', '这张板材不属于本工序已收材料的实际出库批次，或已被使用。', 409);
                    if (! $componentMode && isset($payload['quantity']) && bccomp(CuttingDecimal::value($payload['quantity']), '1', 8) !== 0)
                        $this->commands->fail('physical_quantity_invalid','板材按一张具体实物登记。');
                    $quantity = $componentMode && isset($payload['quantity']) ? CuttingDecimal::value($payload['quantity']) : '1.00000000';
                } else {
                    if (! empty($payload['physical_material_id'])) $this->commands->fail('physical_quantity_invalid','管材用料不能指定板材实物。');
                    $quantity = CuttingDecimal::value($payload['quantity'] ?? null);
                    if (! $componentMode && (bccomp($quantity, bcadd($quantity,'0',0),8) !== 0 || ! $requirement->standard_stock_length_mm))
                        $this->commands->fail('root_quantity_invalid','请登记实际整根数量，原料必须有标准长度。');
                }
                $componentSources = $componentMode ? $this->componentSources($input, $physical, $physical && ! isset($payload['quantity']) ? null : $quantity) : null;
                if ($componentMode) $quantity = CuttingStockAllocationPolicy::sum($componentSources, 'actual_stock_qty');
                if (bccomp($quantity,(string) $holding->quantity,8) > 0) $this->commands->fail('cutting_received_quantity_exceeded','加工数量超过本工序尚未使用的实际收料。',409);
                $cost = $physical ? (string) $physical->total_cost : CuttingDecimal::share((string) $holding->total_cost,(string) $holding->quantity,$quantity); $now = now();
                if (bccomp($cost,(string) $holding->total_cost,4) > 0) $this->commands->fail('cutting_received_cost_exceeded','板材实物金额超过本工序实际收料剩余金额。',409);
                $batchId = DB::table('erp_cutting_settlement_batches')->insertGetId([
                    'batch_no'=>$this->numbers->next('cutting_settlement','CB'), 'cutting_order_id'=>$link->cutting_order_id,
                    'cutting_task_id'=>$link->cutting_task_id,'input_item_id'=>$requirement->component_item_id,'physical_material_id'=>$physical?->id,
                    'source_holding_id'=>$holding->id,'input_qty'=>$componentMode ? count($componentSources) : $quantity,'original_total_cost'=>$cost,
                    'standard_stock_length_mm'=>$physical ? null : ($componentMode ? $componentSources[0]['dimensions']['length_mm'] : $requirement->standard_stock_length_mm),
                    'status'=>'PROCESSING','business_version'=>1,'created_at'=>$now,'updated_at'=>$now,
                ]);
                $wipId = DB::table('erp_material_holdings')->insertGetId([
                    'material_lot_id'=>$holding->material_lot_id,'position_type'=>'CUTTING_WIP','position_id'=>$batchId,
                    'quantity'=>$quantity,'total_cost'=>$cost,'status'=>'ACTIVE','business_version'=>1,'created_at'=>$now,'updated_at'=>$now,
                ]);
                DB::table('erp_cutting_settlement_batches')->where('id',$batchId)->update(['wip_holding_id'=>$wipId]);
                $remaining = bcsub((string) $holding->quantity,$quantity,8);
                DB::table('erp_material_holdings')->where('id',$holding->id)->update([
                    'quantity'=>$remaining,'total_cost'=>bcsub((string) $holding->total_cost,$cost,4),
                    'status'=>bccomp($remaining,'0',8) === 0 ? 'CONSUMED' : 'ACTIVE', 'business_version'=>$holding->business_version+1,'updated_at'=>$now,
                ]);
                if (bccomp($remaining,'0',8) === 0) DB::table('erp_production_input_holdings')->where('id',$input->id)->update(['status'=>'CONSUMED','updated_at'=>$now]);
                if ($physical) DB::table('erp_material_physicals')->where('id',$physical->id)->update([
                    'status'=>'ISSUED','current_holding_id'=>$wipId,'total_cost'=>$cost,'business_version'=>$physical->business_version+1,'updated_at'=>$now,
                ]);
                DB::table('erp_material_movements')->insert([
                    'movement_no'=>$this->numbers->next('material_movement','MM'),'source_holding_id'=>$holding->id,'target_holding_id'=>$wipId,
                    'action'=>'PROD_CUTTING_USE','quantity'=>$quantity,'total_cost'=>$cost,'operator_legacy_id'=>$this->commands->actor($user),'created_at'=>$now,'updated_at'=>$now,
                ]);
                $batch = DB::table('erp_cutting_settlement_batches')->where('id',$batchId)->first();
                $size = json_decode($requirement->cutting_requirement_snapshot,true,512,JSON_THROW_ON_ERROR);
                $snapshot = json_decode($link->technical_snapshot,true,512,JSON_THROW_ON_ERROR);
                $frozen = ['bom_item_id'=>$requirement->bom_item_id,'bom_id'=>$requirement->bom_id,
                    'required_length_mm'=>$size['length_mm'],'required_width_mm'=>$size['width_mm'] ?? null,
                    'required_thickness_mm'=>$size['thickness_mm'] ?? null,'per_output_piece_qty'=>$size['piece_qty'],
                    'allow_cut_rotation'=>$size['allow_rotation'] ?? false,'item_id'=>$snapshot['output_item_id'],
                    'configuration_id'=>$snapshot['configuration_id'],'input'=>app(CuttingOutputEligibilityService::class)->source($batch)];
                if ($componentMode) {
                    $frozen = $this->componentInputSnapshot($link, $input, $batch, $componentSources);
                    foreach ($frozen['components'] as $component) $this->assertSourceFits($component);
                } else $this->assertSourceFits($frozen);
                DB::table('erp_production_cutting_inputs')->insert([
                    'operation_id'=>$link->id,'settlement_batch_id'=>$batchId,'production_input_holding_id'=>$input->id,
                    'quantity'=>$quantity,'total_cost'=>$cost,'requirement_snapshot'=>json_encode($frozen,JSON_THROW_ON_ERROR),'created_at'=>$now,'updated_at'=>$now,
                ]);
                $result = ['settlement_batch_id'=>$batchId,'cutting_order_id'=>$link->cutting_order_id,'business_version'=>1];
                $this->commands->event('batch',$batchId,'use_received_material',$user,null,$result + ['production_input_holding_id'=>$input->id,'quantity'=>$quantity,'total_cost'=>$cost]);
                return $result;
            });
    }

    private function assertSourceFits(array $r): void
    {
        $source = $r['input'];
        if (FrozenCuttingStockPolicy::isTotalLength($r)) {
            if ($source['mode'] !== 'length' || ! FrozenCuttingStockPolicy::positiveDecimal($r['covered_total_length_mm'] ?? null)) $this->commands->fail('cutting_total_length_source_invalid', '管材总长度缺少实际已收原根覆盖。');
            return;
        }
        if ($source['mode'] === 'length') {
            if (bccomp((string) $r['required_length_mm'],(string) $source['length_mm'],8) > 0) $this->commands->fail('output_too_large','要求长度超过本次实际材料长度。');
            return;
        }
        if (bccomp((string) $r['required_thickness_mm'],(string) $source['thickness_mm'],8) !== 0)
            $this->commands->fail('output_thickness_mismatch','实际板材厚度与冻结工艺要求不符。');
        if (($source['shape'] ?? null) === 'IRREGULAR') {
            if (! isset($source['area_mm2']) || bccomp(bcmul((string) $r['required_length_mm'], (string) $r['required_width_mm'], 8), (string) $source['area_mm2'], 8) > 0)
                $this->commands->fail('output_too_large', '冻结板件面积超过本块实际余料面积。');
            return;
        }
        $normal = bccomp((string) $r['required_length_mm'],(string) $source['length_mm'],8) <= 0 && bccomp((string) $r['required_width_mm'],(string) $source['width_mm'],8) <= 0;
        $rotated = $r['allow_cut_rotation'] && bccomp((string) $r['required_length_mm'],(string) $source['width_mm'],8) <= 0 && bccomp((string) $r['required_width_mm'],(string) $source['length_mm'],8) <= 0;
        if (! $normal && ! $rotated) $this->commands->fail('output_too_large','冻结产出尺寸无法使用本张板材加工。');
    }

    public function save(int $taskId, string $type, int $id, int $batchId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->context($taskId,$type,$id,$user,$permissions,$super);
        return $this->commands->run('save_production_cutting_results',$batchId,$payload + ['production_task_id'=>$taskId,'target_type'=>$type,'target_id'=>$id],$user,
            function () use ($taskId,$type,$id,$batchId,$payload,$user,$permissions,$super): array {
                [, $target] = $this->context($taskId,$type,$id,$user,$permissions,$super,true);
                if (! in_array($target->status,['IN_PROGRESS','PAUSED'],true)) $this->commands->fail('cutting_operation_not_started','请先在所属工序开工。',409);
                $link = DB::table('erp_production_cutting_operations')->where('target_type',$type)->where('target_id',$id)->first();
                $batch = $link ? DB::table('erp_cutting_settlement_batches')->where('id',$batchId)->where('cutting_order_id',$link->cutting_order_id)->lockForUpdate()->first() : null;
                if (! $batch) $this->commands->fail('cutting_batch_scope_invalid','本用料记录不属于当前工序。',404);
                $this->commands->version($batch,$payload);
                $componentMode = ($this->json($link->technical_snapshot)['output_kind'] ?? null) === ProductionCuttingComponentService::KIND;
                if ($componentMode) return $this->saveComponentResults($link, $batch, $payload, $user, $permissions, $super);
                $allowed = DB::table('erp_cutting_allowed_outputs')->where('cutting_order_id',$link->cutting_order_id)->first();
                $r = $this->frozenRequirement($batch,$allowed);
                $quantity = CuttingDecimal::value($payload['actual_qty'] ?? null, 8, true);
                $measurements = ['length_mm'=>$r['required_length_mm']];
                if ($r['input']['mode'] === 'sheet') $measurements += ['width_mm'=>$r['required_width_mm'],'thickness_mm'=>$r['input']['actual_thickness_mm']];
                $rows = bccomp($quantity, '0', 8) > 0 ? [['client_row_id'=>'operation-product','result_type'=>'product','allowed_output_id'=>$allowed->id,
                    'actual_qty'=>$quantity,'cut_length_mm'=>$r['required_length_mm'],'measurement_status'=>'MEASURED','measurements'=>$measurements]] : [];
                foreach ($payload['other_results'] ?? [] as $other) {
                    if (! is_array($other) || ($other['result_type'] ?? 'product') === 'product') $this->commands->fail('other_result_invalid','余料和损耗不能替换工序产出。');
                    $rows[] = $other;
                }
                $rows = app(CuttingRemnantBalanceService::class)->prepareRows($batch, $rows, $payload['remnant_review'] ?? ['confirmed' => false]);
                if (! $rows) $this->commands->fail('cutting_results_required', '本次用料必须登记产出、余料或实际损耗，不能空白结算。');
                $prefix = 'op-cut-save-'.hash('sha256',$payload['client_command_id']);
                $saved = app(CuttingRecordService::class)->saveResults($batchId,['client_command_id'=>$prefix,'expected_version'=>$batch->business_version,'results'=>$rows],$user,$permissions,$super);
                $all = DB::table('erp_cutting_results')->where('settlement_batch_id',$batchId)->whereNotIn('status',['VOIDED','SUPERSEDED'])->get();
                app(CuttingOutputEligibilityService::class)->assertBatchFits($batch,$all);
                $saved['remnant_review'] = app(CuttingRemnantBalanceService::class)->saveReview($batch, $payload['remnant_review'] ?? ['confirmed' => false], $user);
                return $saved;
            });
    }

    public function finish(int $taskId, string $type, int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->context($taskId,$type,$id,$user,$permissions,$super);
        $this->commands->permission($permissions,'production.task.complete');
        return $this->commands->run('finish_production_cutting',$id,$payload + ['production_task_id'=>$taskId,'target_type'=>$type],$user,
            function () use ($taskId,$type,$id,$payload,$user,$permissions,$super): array {
                [, $target] = $this->context($taskId,$type,$id,$user,$permissions,$super,true);
                $this->commands->version($target,$payload);
                $link = DB::table('erp_production_cutting_operations')->where('target_type',$type)->where('target_id',$id)->lockForUpdate()->first();
                if (! $link) $this->commands->fail('cutting_results_required','请先登记本工序下料结果。',409);
                $batches = DB::table('erp_cutting_settlement_batches')->where('cutting_order_id',$link->cutting_order_id)->orderBy('id')->lockForUpdate()->get();
                if ($batches->isEmpty()) $this->commands->fail('cutting_results_required','请先登记本工序实际用料和结果。',409);
                $prefix = 'op-cut-finish-'.hash('sha256',$payload['client_command_id']);
                foreach ($batches as $batch) {
                    if ($batch->status === 'CONFIRMED') continue;
                    if ($batch->status !== 'PROCESSING') $this->commands->fail('cutting_batch_not_editable','存在状态异常的下料记录，请先处理。',409);
                    app(CuttingRemnantBalanceService::class)->assertConfirmed($batch);
                    $products = DB::table('erp_cutting_results')->where('settlement_batch_id',$batch->id)->where('result_type','product')->where('status','DRAFT')->get();
                    foreach ($products as $product) app(CuttingRecordService::class)->splitRoutes($product->id,
                        ['client_command_id'=>$prefix.'-route-'.$product->id,'expected_version'=>$product->business_version,
                            'routes'=>[['route_type'=>'WAREHOUSE','quantity'=>$product->actual_qty]]],$user,$permissions,$super);
                    $submitted = app(CuttingRecordService::class)->submit($batch->id,
                        ['client_command_id'=>$prefix.'-submit-'.$batch->id,'expected_version'=>DB::table('erp_cutting_settlement_batches')->where('id',$batch->id)->value('business_version')],$user,$permissions,$super);
                    app(CuttingConfirmationService::class)->confirm($batch->id,
                        ['client_command_id'=>$prefix.'-confirm-'.$batch->id,'expected_version'=>$submitted['business_version'],'cost_method'=>CuttingAutomaticCostService::RULE],$user,$permissions,$super);
                }
                $quantity = $this->confirmedOutputQuantity($link,$type,$target);
                return app(ProductionExecutionActionService::class)->complete($taskId,$type,$id,
                    ['client_command_id'=>$prefix.'-complete','expected_version'=>$target->business_version,
                        'completed_base_qty'=>$type === 'quantity_operation' ? $quantity : 0,'scrapped_base_qty'=>0,
                        'disposition'=>$payload['disposition'] ?? 'direct_handover'],$user,$permissions);
            });
    }

    /** Isolate confirmed cutting WIP from the old standalone warehouse/dispatch paths. */
    public function holdForOperation(object $batch): void
    {
        if (! $this->linkedOrder((int) $batch->cutting_order_id)) return;
        $routes = DB::table('erp_cutting_result_routes as route')->join('erp_cutting_results as result','result.id','=','route.result_id')
            ->where('result.settlement_batch_id',$batch->id)->where('route.status','WAIT_WAREHOUSE')->select('route.*')->get();
        foreach ($routes as $route) {
            DB::table('erp_cutting_result_routes')->where('id',$route->id)->update(['status'=>'WAIT_OPERATION','updated_at'=>now()]);
            DB::table('erp_material_holdings')->where('id',$route->holding_id)->update(['position_type'=>'OPERATION_CUT_OUTPUT','updated_at'=>now()]);
            DB::table('erp_cutting_output_allocations')->where('route_id',$route->id)->update(['disposition'=>'PRODUCTION_OPERATION','updated_at'=>now()]);
        }
    }

    public function beforeComplete(string $type, object $target, array $payload): void
    {
        if (! $this->required($type,$target->id)) return;
        $link = DB::table('erp_production_cutting_operations')->where('target_type',$type)->where('target_id',$target->id)->lockForUpdate()->first();
        if (! $link) $this->commands->fail('cutting_results_required','本工序有下料要求，请在下料工序页登记实际用料、产出和余料后完成。',409);
        $batches = DB::table('erp_cutting_settlement_batches')->where('cutting_order_id',$link->cutting_order_id)->lockForUpdate()->get();
        if ($batches->isEmpty() || $batches->contains(fn ($batch) => $batch->status !== 'CONFIRMED'))
            $this->commands->fail('cutting_results_unconfirmed','仍有用料没有核对结果，不能跳过下料记录完成工序。',409);
        $quantity = $this->confirmedOutputQuantity($link,$type,$target);
        if ($type === 'quantity_operation'
            && (bccomp((string) ($payload['completed_base_qty'] ?? 0),$quantity,8) !== 0 || bccomp((string) $target->completed_base_qty,'0',8) !== 0))
            $this->commands->fail('cutting_output_quantity_mismatch','下料实际合格产出必须与本工序待完成数量一致，不能重复报工或以其他数量完工。',409);
        $componentMode = ($this->json($link->technical_snapshot)['output_kind'] ?? null) === ProductionCuttingComponentService::KIND;
        foreach ($this->requirements($type,$target->id)->get(['target.id']) as $requirement) {
            $inputs = DB::table('erp_production_input_holdings')->where('target_material_requirement_id',$requirement->id)->pluck('id');
            if (! $componentMode && ! DB::table('erp_production_cutting_inputs')->where('operation_id',$link->id)->whereIn('production_input_holding_id',$inputs)->exists())
                $this->commands->fail('cutting_input_missing','存在尚未登记实际加工用料的下料需求。',409);
            if (DB::table('erp_production_input_holdings as input')->join('erp_material_holdings as holding','holding.id','=','input.input_holding_id')
                ->whereIn('input.id',$inputs)->where('holding.status','ACTIVE')->where('holding.quantity','>',0)->exists())
                $this->commands->fail('cutting_unused_received_material','还有已收原材料未加工，请继续登记或先办理生产退料。',409);
        }
    }

    private function confirmedOutputQuantity(object $link, string $type, object $target): string
    {
        $expected = $type === 'quantity_operation' ? (string) $target->planned_base_qty : '1';
        if (($this->json($link->technical_snapshot)['output_kind'] ?? null) === ProductionCuttingComponentService::KIND) {
            $pieces = [];
            foreach (DB::table('erp_cutting_results as result')->join('erp_cutting_settlement_batches as batch', 'batch.id', '=', 'result.settlement_batch_id')
                ->where('batch.cutting_order_id', $link->cutting_order_id)->where('result.status', 'CONFIRMED')->where('result.result_type', 'product')->get(['result.*']) as $result) {
                $r = $this->json($result->cutting_requirement_snapshot); $id = (int) ($r['target_material_requirement_id'] ?? 0);
                if (($r['output_kind'] ?? null) !== ProductionCuttingComponentService::KIND || ! $id) $this->commands->fail('cutting_component_identity_invalid', '逐规格下料结果缺少冻结需求身份。', 409);
                $amount = FrozenCuttingStockPolicy::isTotalLength($r) ? ($this->json($result->measurements)['total_length_mm'] ?? '0') : $result->piece_qty;
                $pieces[$id] = bcadd($pieces[$id] ?? '0', (string) $amount, 8);
            }
            foreach ($this->json($link->technical_snapshot)['requirements'] as $requirement) {
                $cutting = $this->json($requirement['cutting_requirement_snapshot']);
                $required = FrozenCuttingStockPolicy::isTotalLength($cutting) ? FrozenCuttingStockPolicy::requiredTotalLength($cutting) : $requirement['required_piece_qty'];
                if (bccomp($pieces[(int) $requirement['target_requirement_id']] ?? '0', (string) $required, 8) !== 0)
                    $this->commands->fail('cutting_component_quantity_mismatch', '每条冻结下料明细的实际总长度或合格件数必须完整满足本工序需求。', 409);
            }
            return $expected;
        }
        $byRequirement = DB::table('erp_cutting_results as result')
            ->join('erp_production_cutting_inputs as used','used.settlement_batch_id','=','result.settlement_batch_id')
            ->join('erp_production_input_holdings as input','input.id','=','used.production_input_holding_id')
            ->where('used.operation_id',$link->id)->where('result.status','CONFIRMED')->where('result.result_type','product')
            ->groupBy('input.target_material_requirement_id')->selectRaw('input.target_material_requirement_id, SUM(result.actual_qty) as quantity')
            ->pluck('quantity','target_material_requirement_id');
        // Each BOM material contributes to the same finished quantity. Adding their
        // output equivalents would count a two-material product twice. Each required
        // contribution must independently cover the frozen planned output quantity.
        foreach ($this->requirements($type,$target->id)->pluck('target.id') as $requirementId) {
            if (bccomp((string) ($byRequirement[$requirementId] ?? '0'),$expected,8) !== 0)
                $this->commands->fail('cutting_output_quantity_mismatch','每项下料用料的实际合格产出必须满足本工序计划数量，请核对各项用料结果。',409);
        }
        return $expected;
    }

    public function coveredRequirementIds(string $type, int $id): array
    {
        if ($link = app(ProductionCuttingComponentService::class)->link($type, $id)) return array_map(fn ($r) => (int) $r['target_requirement_id'], $this->json($link->technical_snapshot)['requirements']);
        return DB::table('erp_production_cutting_inputs as used')->join('erp_production_cutting_operations as operation','operation.id','=','used.operation_id')
            ->join('erp_production_input_holdings as input','input.id','=','used.production_input_holding_id')
            ->where('operation.target_type',$type)->where('operation.target_id',$id)->distinct()->pluck('input.target_material_requirement_id')->all();
    }

    public function attachOutputCosts(object $output, object $target, string $type, int $actor): object
    {
        if (app(ProductionCuttingComponentService::class)->isOutput($output)) return app(ProductionCuttingComponentService::class)->attachCosts($output, $target, $type, $actor);
        $link = DB::table('erp_production_cutting_operations')->where('target_type',$type)->where('target_id',$target->id)->first();
        if (! $link) return $output;
        if (DB::transactionLevel() < 1) throw new \LogicException('Cutting output transfer requires production completion transaction.');
        $routes = DB::table('erp_cutting_result_routes as route')->join('erp_cutting_results as result','result.id','=','route.result_id')
            ->join('erp_cutting_settlement_batches as batch','batch.id','=','result.settlement_batch_id')
            ->join('erp_production_cutting_inputs as used','used.settlement_batch_id','=','batch.id')
            ->join('erp_production_input_holdings as input','input.id','=','used.production_input_holding_id')
            ->where('batch.cutting_order_id',$link->cutting_order_id)->where('route.status','WAIT_OPERATION')->orderBy('route.id')->lockForUpdate()
            ->get(['route.*','result.item_id','result.configuration_id','input.target_material_requirement_id']);
        if ($routes->isEmpty()) $this->commands->fail('cutting_output_cost_missing','本工序没有可归集的已确认下料产出。',409);
        $snapshot = json_decode($link->technical_snapshot,true,512,JSON_THROW_ON_ERROR);
        $cost = '0'; $quantity = (string) $output->output_base_qty; $contributions = []; $sources = [];
        foreach ($routes as $route) {
            $source = DB::table('erp_material_holdings')->where('id',$route->holding_id)->lockForUpdate()->first();
            if (! $source || $source->status !== 'ACTIVE' || $source->position_type !== 'OPERATION_CUT_OUTPUT'
                || (int) $source->position_id !== (int) $route->id || (int) $route->item_id !== (int) $output->output_item_id
                || (int) $route->configuration_id !== (int) $snapshot['configuration_id']
                || bccomp((string) $source->quantity,(string) $route->quantity,8) !== 0 || bccomp((string) $source->total_cost,(string) $route->total_cost,4) !== 0)
                $this->commands->fail('cutting_output_holding_invalid','下料产出数量、成本或持有事实已变化，不能重复归集。',409);
            $sources[] = $source; $key = $route->target_material_requirement_id;
            $contributions[$key] = bcadd($contributions[$key] ?? '0',(string) $source->quantity,8); $cost = bcadd($cost,(string) $source->total_cost,4);
        }
        foreach ($contributions as $contribution) {
            if (bccomp($contribution,$quantity,8) !== 0) $this->commands->fail('cutting_output_quantity_mismatch','下料结果数量与工序产出不一致。',409);
        }
        $now = now(); $lotId = $output->material_lot_id; $holdingId = $output->material_holding_id;
        $total = bcadd((string) ($output->material_total_cost ?? '0'),$cost,4);
        if (! $lotId) {
            $lotId = DB::table('erp_material_lots')->insertGetId(['lot_no'=>$this->numbers->next('material_lot','ML'),
                'item_id'=>$output->output_item_id,'configuration_id'=>$snapshot['configuration_id'],'stage_id'=>$target->routing_operation_id_snapshot,
                'material_form'=>'PRODUCT','source_type'=>'production_output_record','source_id'=>$output->id,'created_at'=>$now,'updated_at'=>$now]);
            $holdingId = DB::table('erp_material_holdings')->insertGetId(['material_lot_id'=>$lotId,'position_type'=>'OUTPUT_WIP','position_id'=>$output->id,
                'quantity'=>$quantity,'total_cost'=>$total,'status'=>'ACTIVE','business_version'=>1,'created_at'=>$now,'updated_at'=>$now]);
        } else {
            DB::table('erp_material_holdings')->where('id',$holdingId)->update(['total_cost'=>$total,'business_version'=>DB::raw('business_version+1'),'updated_at'=>$now]);
        }
        foreach ($sources as $source) {
            DB::table('erp_material_holdings')->where('id',$source->id)->update(['quantity'=>'0','total_cost'=>'0','status'=>'CONSUMED',
                'business_version'=>$source->business_version+1,'updated_at'=>$now]);
            DB::table('erp_material_movements')->insert(['movement_no'=>$this->numbers->next('material_movement','MM'),'source_holding_id'=>$source->id,
                'target_holding_id'=>$holdingId,'action'=>'CUTTING_TO_OPERATION','quantity'=>$source->quantity,'total_cost'=>$source->total_cost,
                'operator_legacy_id'=>$actor,'created_at'=>$now,'updated_at'=>$now]);
        }
        DB::table('erp_cutting_result_routes')->whereIn('id',$routes->pluck('id'))->update(['status'=>'OPERATION_COMPLETED','updated_at'=>$now]);
        $rawInputs = DB::table('erp_production_cutting_inputs as used')->join('erp_production_input_holdings as input','input.id','=','used.production_input_holding_id')
            ->join('erp_material_holdings as holding','holding.id','=','input.input_holding_id')->where('used.operation_id',$link->id)
            ->selectRaw('input.target_material_requirement_id, holding.id as source_holding_id, holding.material_lot_id, SUM(used.quantity) as quantity, SUM(used.total_cost) as total_cost')
            ->groupBy('input.target_material_requirement_id','holding.id','holding.material_lot_id')->get();
        foreach ($rawInputs as $raw) {
            DB::table('erp_production_material_consumptions')->insert(['output_record_id'=>$output->id,'target_material_requirement_id'=>$raw->target_material_requirement_id,
                'source_holding_id'=>$raw->source_holding_id,'source_material_lot_id'=>$raw->material_lot_id,'quantity'=>$raw->quantity,'total_cost'=>$raw->total_cost,
                'operator_legacy_id'=>$actor,'occurred_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
        }
        foreach ($rawInputs->groupBy('target_material_requirement_id') as $requirementId => $inputs) {
            $used = '0'; foreach ($inputs as $input) $used = bcadd($used,(string) $input->quantity,8);
            DB::table('erp_production_target_material_requirements')->where('id',$requirementId)->update(['consumed_base_qty'=>$used,
                'business_version'=>DB::raw('business_version+1'),'updated_at'=>$now]);
        }
        $loss = (string) DB::table('erp_cutting_cost_dispositions as disposition')->join('erp_cutting_results as result','result.id','=','disposition.result_id')
            ->join('erp_cutting_settlement_batches as batch','batch.id','=','result.settlement_batch_id')->where('batch.cutting_order_id',$link->cutting_order_id)
            ->where('disposition.disposition','LOSS_EXPENSE')->sum('disposition.total_cost');
        DB::table('erp_production_output_records')->where('id',$output->id)->update(['material_lot_id'=>$lotId,'material_holding_id'=>$holdingId,
            'material_total_cost'=>$total,'material_loss_cost'=>bcadd((string) ($output->material_loss_cost ?? '0'),$loss,4),'updated_at'=>$now]);
        DB::table('erp_cutting_tasks')->where('id',$link->cutting_task_id)->update(['status'=>'FINISHED','completed_at'=>$now,'business_version'=>DB::raw('business_version+1'),'updated_at'=>$now]);
        DB::table('erp_cutting_orders')->where('id',$link->cutting_order_id)->update(['status'=>'CLOSED','business_version'=>DB::raw('business_version+1'),'updated_at'=>$now]);
        return DB::table('erp_production_output_records')->where('id',$output->id)->first();
    }

    public function frozenRequirement(object $batch, object $allowed): ?array
    {
        $row = DB::table('erp_production_cutting_inputs')->where('settlement_batch_id',$batch->id)->first();
        if (! $row) return null;
        $r = json_decode($row->requirement_snapshot,true,512,JSON_THROW_ON_ERROR);
        if (($r['output_kind'] ?? null) === ProductionCuttingComponentService::KIND) {
            $rule = $this->json($allowed->rule_snapshot);
            $r = collect($r['components'])->firstWhere('target_material_requirement_id', (int) ($rule['target_material_requirement_id'] ?? 0));
            if (! $r) $this->commands->fail('cutting_component_source_not_owned', '该冻结规格没有本次实际已收材料的覆盖凭据。', 409);
        }
        if ((int) $r['item_id'] !== (int) $allowed->item_id || (int) $r['configuration_id'] !== (int) $allowed->configuration_id)
            $this->commands->fail('cutting_output_identity_invalid','产出必须使用本工序冻结的物料和配置。');
        return $r;
    }

    /** Each selected whole source can be used once, and only its received member rows may yield pieces. */
    private function componentSources(object $input, ?object $physical, ?string $quantity): array
    {
        $receipt = DB::table('erp_material_receipt_lines')->where('id', $input->material_receipt_line_id)->first();
        $allocation = $this->json($receipt?->accepted_serial_snapshot)['cutting_allocation'] ?? null;
        if (! $allocation || ! ($allocation['sources'] ?? [])) $this->commands->fail('cutting_component_received_source_required', '组件加工必须引用正式收料中冻结的整根或整张来源和规格覆盖。', 409);
        $used = [];
        foreach (DB::table('erp_production_cutting_inputs')->where('production_input_holding_id', $input->id)->get(['requirement_snapshot']) as $previous) {
            $used = array_merge($used, $this->json($previous->requirement_snapshot)['source_keys'] ?? []);
        }
        $sources = array_values(array_filter($allocation['sources'], fn ($source) => ! in_array($source['source_key'], $used, true)
            && (! $physical || (int) ($source['physical_material_id'] ?? 0) === (int) $physical->id)));
        $selected = []; $stockQty = '0.00000000';
        foreach ($sources as $source) {
            $stockKind = $allocation['stock_unit_kind'] ?? $allocation['unit_kind'] ?? null;
            $actualQty = $source['actual_stock_qty'] ?? (in_array($stockKind, ['root', 'sheet'], true) ? '1.00000000' : null);
            if ($actualQty === null || bccomp((string) $actualQty, '0', 8) <= 0) $this->commands->fail('cutting_component_stock_quantity_missing', '每个实际已收来源必须明确原库存单位的真实数量，不能把米数或面积当作根数或张数。', 409);
            $sourceDimensions = $source['dimensions'] ?? [];
            $geometricQty = match ($stockKind) {
                'root', 'sheet' => '1.00000000',
                'm' => isset($sourceDimensions['length_mm']) ? bcdiv((string) $sourceDimensions['length_mm'], '1000', 8) : null,
                'm2' => FrozenCuttingStockPolicy::area($sourceDimensions) !== null ? bcdiv(FrozenCuttingStockPolicy::area($sourceDimensions), '1000000', 8) : null,
                default => null,
            };
            if ($geometricQty === null || bccomp((string) $actualQty, $geometricQty, 8) !== 0 || ($stockKind === 'm2' && ! ($source['physical_material_id'] ?? null)))
                $this->commands->fail('cutting_component_stock_source_invalid', '实际库存量必须与已收整根长度或逐张实物面积精确对应。', 409);
            $source['stock_unit_kind'] = $stockKind;
            $source['actual_stock_qty'] = bcadd((string) $actualQty, '0', 8);
            $selected[] = $source; $stockQty = bcadd($stockQty, $source['actual_stock_qty'], 8);
            if ($physical || ($quantity !== null && bccomp($stockQty, $quantity, 8) >= 0)) break;
        }
        if (! $selected || ($quantity !== null && bccomp($stockQty, $quantity, 8) !== 0)) $this->commands->fail('cutting_component_source_exceeded', '加工数量必须精确对应尚未加工的完整实际来源，不能重复或分割一根或一张。', 409);
        $sources = $selected;
        $dimensions = $sources[0]['dimensions'] ?? [];
        if ($physical ? FrozenCuttingStockPolicy::area($dimensions) === null : ! FrozenCuttingStockPolicy::positiveDecimal($dimensions['length_mm'] ?? null)) $this->commands->fail('cutting_component_source_dimensions_missing', '已收材料缺少实际冻结长度或面积，不能回退到物料当前默认尺寸。', 409);
        foreach ($sources as &$source) {
            if (($source['dimensions'] ?? []) !== $dimensions) $this->commands->fail('cutting_component_sources_mixed', '不同实际原料尺寸必须分别登记加工批次。', 409);
            $source['members'] ??= [['target_material_requirement_id' => (int) $allocation['target_material_requirement_id'],
                'covered_piece_qty' => (int) $source['covered_piece_qty'], 'covered_base_qty' => (string) $source['coverage_qty'],
                'formal_coverage_qty' => (string) $source['formal_coverage_qty']]];
        }
        unset($source);
        if ($physical) {
            $actual = ['shape' => $physical->shape] + $this->json($physical->dimensions);
            if (! FrozenCuttingStockPolicy::sameDimensions($dimensions, $actual))
                $this->commands->fail('cutting_component_physical_changed', '本张实物尺寸与正式已收来源不一致，不能重新解释原规格。', 409);
        }
        return $sources;
    }

    private function componentInputSnapshot(object $link, object $input, object $batch, array $sources): array
    {
        $requirements = collect($this->json($link->technical_snapshot)['requirements'])->keyBy('target_requirement_id');
        $members = []; $sourceLengths = [];
        foreach ($sources as $source) foreach ($source['members'] as $member) {
            $id = (int) ($member['target_material_requirement_id'] ?? 0);
            $requirement = $requirements->get($id);
            if (! $requirement || (int) $requirement['component_item_id'] !== (int) $batch->input_item_id
                || (isset($member['material_requirement_id']) && (int) $member['material_requirement_id'] !== (int) $requirement['id'])
                || (isset($member['bom_item_id']) && (int) $member['bom_item_id'] !== (int) $requirement['bom_item_id']))
                $this->commands->fail('cutting_component_member_invalid', '实际已收来源的成员不属于本工序冻结的同原料 BOM 需求。', 409);
            $size = $this->json($requirement['cutting_requirement_snapshot']);
            $totalLength = FrozenCuttingStockPolicy::isTotalLength($size);
            if ($totalLength) {
                if (($member['quantity_basis'] ?? null) !== 'total_length' || ! FrozenCuttingStockPolicy::positiveDecimal($member['covered_base_qty'] ?? null)
                    || bccomp((string) ($member['target_required_qty'] ?? '0'), (string) $requirement['target_required_base_qty'], 8) !== 0
                    || bccomp((string) ($member['required_total_length_mm'] ?? '0'), (string) FrozenCuttingStockPolicy::requiredTotalLength($size), 8) !== 0) {
                    $this->commands->fail('cutting_component_member_quantity_invalid', '实际已收来源的总长度口径或理论覆盖与冻结需求不一致。', 409);
                }
                $covered = FrozenCuttingStockPolicy::positiveDecimal($member['covered_total_length_mm'] ?? null);
                if (! $covered) $this->commands->fail('cutting_component_source_length_missing', '已收原根缺少冻结的净覆盖总长度，不能从舍入后的理论库存量反推。', 409);
                $sourceLengths[$source['source_key']] = bcadd($sourceLengths[$source['source_key']] ?? '0', $covered, 8);
                if (bccomp($covered, '0', 8) <= 0 || bccomp($sourceLengths[$source['source_key']], (string) $source['dimensions']['length_mm'], 8) > 0) $this->commands->fail('cutting_component_source_length_exceeded', '单根来源覆盖总长度超过其真实已收长度。', 409);
            } else {
                $covered = (string) ($member['covered_piece_qty'] ?? '0');
                if (bccomp($covered, '0', 8) <= 0 || bccomp($covered, bcadd($covered, '0', 0), 8) !== 0) $this->commands->fail('cutting_component_member_quantity_invalid', '实际材料必须明确覆盖每条冻结规格的整数件数。', 409);
            }
            if (isset($member['dimensions']) && ! $totalLength) {
                foreach (['length_mm', 'width_mm', 'thickness_mm'] as $axis) {
                    $frozen = $size[$axis] ?? null; $received = $member['dimensions'][$axis] ?? null;
                    if (($frozen === null) !== ($received === null) || ($frozen !== null && bccomp((string) $frozen, (string) $received, 8) !== 0))
                        $this->commands->fail('cutting_component_member_dimensions_changed', '已收原料分组的规格尺寸与本工序冻结 BOM 行不一致。', 409);
                }
            }
            $members[$id] = bcadd($members[$id] ?? '0', $covered, 8);
            $requiredQuantity = $totalLength ? FrozenCuttingStockPolicy::requiredTotalLength($size) : $requirement['required_piece_qty'];
            if (bccomp($members[$id], (string) $requiredQuantity, 8) > 0) $this->commands->fail('cutting_component_member_quantity_invalid', '实际来源覆盖超过本工序冻结的需求总长度或件数。', 409);
        }
        $source = app(CuttingOutputEligibilityService::class)->source($batch);
        $source += ['material_receipt_line_id' => (int) $input->material_receipt_line_id, 'inventory_transaction_item_id' => (int) $input->inventory_transaction_item_id,
            'production_input_holding_id' => (int) $input->id, 'source_keys' => array_column($sources, 'source_key'), 'sources' => $sources];
        $components = [];
        foreach ($members as $id => $covered) {
            $requirement = $requirements->get($id); $size = $this->json($requirement['cutting_requirement_snapshot']);
            if (($size['quantity_calculation']['unit_kind'] ?? null) !== $sources[0]['stock_unit_kind']) $this->commands->fail('cutting_component_stock_unit_changed', '实际来源的库存计量口径与冻结 BOM 原料行不一致。', 409);
            $totalLength = FrozenCuttingStockPolicy::isTotalLength($size);
            $components[] = ($totalLength ? ['quantity_basis' => 'total_length', 'quantity_unit' => 'm',
                'required_total_length_mm' => FrozenCuttingStockPolicy::requiredTotalLength($size), 'covered_total_length_mm' => $covered] : []) + ['output_kind' => ProductionCuttingComponentService::KIND, 'target_material_requirement_id' => $id,
                'material_requirement_id' => (int) $requirement['id'], 'bom_item_id' => (int) $requirement['bom_item_id'], 'bom_id' => (int) $requirement['bom_id'],
                'item_id' => (int) $batch->input_item_id, 'configuration_id' => $requirement['configuration_id'] ?? null,
                'required_length_mm' => $size['length_mm'], 'required_width_mm' => $size['width_mm'] ?? null,
                'required_thickness_mm' => $size['thickness_mm'] ?? null, 'allow_cut_rotation' => $size['allow_rotation'] ?? false,
                'per_output_piece_qty' => $totalLength ? null : 1, 'reference_piece_qty' => $size['piece_qty'], 'required_piece_qty' => $totalLength ? null : (string) $requirement['required_piece_qty'],
                'covered_piece_qty' => $totalLength ? null : $covered, 'input' => $source];
        }
        if (! $components) $this->commands->fail('cutting_component_members_missing', '该实际来源没有可以追溯的下料规格覆盖。', 409);
        return ['output_kind' => ProductionCuttingComponentService::KIND, 'actual_stock_qty' => CuttingStockAllocationPolicy::sum($sources, 'actual_stock_qty'),
            'stock_unit_kind' => $sources[0]['stock_unit_kind'], 'source_count' => count($sources), 'source_keys' => array_column($sources, 'source_key'), 'sources' => $sources, 'components' => $components];
    }

    private function saveComponentResults(object $link, object $batch, array $payload, object $user, array $permissions, bool $super): array
    {
        if (array_diff(array_keys($payload), ['client_command_id', 'expected_version', 'components', 'other_results', 'remnant_review']) || ! is_array($payload['components'] ?? null)
            || ! array_is_list($payload['components']) || count($payload['components']) > 100) $this->commands->fail('cutting_component_results_invalid', '请按本次已收材料覆盖的冻结规格逐项登记实际下料件数。');
        $allowedRows = DB::table('erp_cutting_allowed_outputs')->where('cutting_order_id', $link->cutting_order_id)->orderBy('id')->get()
            ->keyBy(fn ($allowed) => (int) ($this->json($allowed->rule_snapshot)['target_material_requirement_id'] ?? 0));
        $rows = []; $seen = [];
        foreach ($payload['components'] as $component) {
            if (! is_array($component) || array_diff(array_keys($component), ['target_material_requirement_id', 'actual_piece_qty', 'actual_total_length_mm'])) $this->commands->fail('cutting_component_result_fields_invalid', '组件结果只能引用冻结需求并登记对应的实际总长度或件数。');
            $id = (int) ($component['target_material_requirement_id'] ?? 0);
            if (isset($seen[$id]) || ! ($allowed = $allowedRows->get($id))) $this->commands->fail('cutting_component_result_identity_invalid', '下料结果的规格重复或不属于本工序。');
            $seen[$id] = true; $r = $this->frozenRequirement($batch, $allowed);
            if (FrozenCuttingStockPolicy::isTotalLength($r)) {
                if (isset($component['actual_piece_qty'])) $this->commands->fail('cutting_component_result_fields_invalid', '按总长度加工的管材不能填写实际件数。');
                $length = CuttingDecimal::value($component['actual_total_length_mm'] ?? null, 8, true);
                $other = $this->componentQuantityUsage((int) $link->cutting_order_id, $id, (int) $batch->id);
                if (bccomp($length, $r['covered_total_length_mm'], 8) > 0 || bccomp(bcadd($length, $other, 8), $r['required_total_length_mm'], 8) > 0) $this->commands->fail('cutting_component_result_quantity_invalid', '实际总长度超过本次已收来源覆盖或该需求剩余总长度。');
                if (bccomp($length, '0', 8) === 0) continue;
                $quantity = bcdiv($length, '1000', 8);
                if (bccomp(bcmul($quantity, '1000', 8), $length, 8) !== 0) $this->commands->fail('cutting_component_result_precision_invalid', '管材米数超出八位小数精度，不能静默舍入。');
                $rows[] = ['client_row_id' => 'component-'.$id, 'result_type' => 'product', 'allowed_output_id' => $allowed->id,
                    'actual_qty' => $quantity, 'piece_qty' => null, 'cut_length_mm' => null, 'measurement_status' => 'MEASURED', 'measurements' => ['total_length_mm' => $length]];
                continue;
            }
            if (isset($component['actual_total_length_mm'])) $this->commands->fail('cutting_component_result_fields_invalid', '按尺寸件数加工的明细不能填写实际总长度。');
            $qty = CuttingDecimal::value($component['actual_piece_qty'] ?? null, 8, true);
            $otherPieces = $this->componentQuantityUsage((int) $link->cutting_order_id, $id, (int) $batch->id);
            if (bccomp($qty, bcadd($qty, '0', 0), 8) !== 0 || bccomp($qty, $r['covered_piece_qty'], 8) > 0
                || bccomp(bcadd($qty, $otherPieces, 8), $r['required_piece_qty'], 8) > 0) $this->commands->fail('cutting_component_result_quantity_invalid', '实际件数必须是整数，并且不能超过本次来源覆盖或该冻结规格的剩余需求。');
            if (bccomp($qty, '0', 8) === 0) continue;
            $measurements = ['length_mm' => $r['required_length_mm']];
            if ($r['input']['mode'] === 'sheet') $measurements += ['width_mm' => $r['required_width_mm'], 'thickness_mm' => $r['input']['actual_thickness_mm']];
            $rows[] = ['client_row_id' => 'component-'.$id, 'result_type' => 'product', 'allowed_output_id' => $allowed->id,
                'actual_qty' => $qty, 'piece_qty' => $qty, 'cut_length_mm' => $r['required_length_mm'], 'measurement_status' => 'MEASURED', 'measurements' => $measurements];
        }
        foreach ($payload['other_results'] ?? [] as $other) {
            if (! is_array($other) || ($other['result_type'] ?? 'product') === 'product') $this->commands->fail('other_result_invalid', '余料和损耗不能替换冻结组件结果。');
            $rows[] = $other;
        }
        $rows = app(CuttingRemnantBalanceService::class)->prepareRows($batch, $rows, $payload['remnant_review'] ?? ['confirmed' => false]);
        if (! $rows) $this->commands->fail('cutting_results_required', '本次实际材料必须登记下料件数、余料或实测损耗。');
        $saved = app(CuttingRecordService::class)->saveResults((int) $batch->id, ['client_command_id' => 'op-cut-save-'.hash('sha256', $payload['client_command_id']), 'expected_version' => $batch->business_version, 'results' => $rows], $user, $permissions, $super);
        app(CuttingOutputEligibilityService::class)->assertBatchFits($batch, DB::table('erp_cutting_results')->where('settlement_batch_id', $batch->id)->whereNotIn('status', ['VOIDED', 'SUPERSEDED'])->get());
        $saved['remnant_review'] = app(CuttingRemnantBalanceService::class)->saveReview($batch, $payload['remnant_review'] ?? ['confirmed' => false], $user);
        return $saved;
    }

    private function componentQuantityUsage(int $orderId, int $requirementId, ?int $excludeBatch = null, bool $confirmedOnly = false): string
    {
        $query = DB::table('erp_cutting_results as result')->join('erp_cutting_settlement_batches as batch', 'batch.id', '=', 'result.settlement_batch_id')
            ->where('batch.cutting_order_id', $orderId)->where('result.result_type', 'product')->whereNotIn('result.status', ['VOIDED', 'SUPERSEDED', 'REVERSED']);
        if ($excludeBatch) $query->where('batch.id', '<>', $excludeBatch);
        if ($confirmedOnly) $query->where('result.status', 'CONFIRMED');
        $qty = '0.00000000';
        foreach ($query->get(['result.piece_qty', 'result.measurements', 'result.cutting_requirement_snapshot']) as $row) {
            $snapshot = $this->json($row->cutting_requirement_snapshot);
            if ((int) ($snapshot['target_material_requirement_id'] ?? 0) !== $requirementId) continue;
            $amount = FrozenCuttingStockPolicy::isTotalLength($snapshot) ? ($this->json($row->measurements)['total_length_mm'] ?? '0') : $row->piece_qty;
            $qty = bcadd($qty, (string) $amount, 8);
        }
        return $qty;
    }

    public function assertComponentResultCoverage(object $batch, \Illuminate\Support\Collection $rows): void
    {
        $used = DB::table('erp_production_cutting_inputs')->where('settlement_batch_id', $batch->id)->first();
        $snapshot = $this->json($used?->requirement_snapshot);
        if (($snapshot['output_kind'] ?? null) !== ProductionCuttingComponentService::KIND) return;
        $proven = collect($snapshot['components'] ?? [])->keyBy('target_material_requirement_id');
        $pieces = [];
        foreach ($rows->where('result_type', 'product') as $row) {
            $r = $this->json($row->cutting_requirement_snapshot); $id = (int) ($r['target_material_requirement_id'] ?? 0);
            if (FrozenCuttingStockPolicy::isTotalLength($r)) {
                $length = $this->json($row->measurements)['total_length_mm'] ?? null;
                if (! $proven->has($id) || $proven->get($id) !== $r || ! FrozenCuttingStockPolicy::positiveDecimal($length)
                    || $row->piece_qty !== null || $row->cut_length_mm !== null
                    || bccomp(bcmul((string) $row->actual_qty, '1000', 8), (string) $length, 8) !== 0) $this->commands->fail('cutting_component_source_not_owned', '管材结果缺少本次已收来源覆盖或米数与总长度不一致。', 409);
                $pieces[$id] = bcadd($pieces[$id] ?? '0', (string) $length, 8);
                continue;
            }
            if (! $proven->has($id) || $proven->get($id) !== $r || bccomp((string) $row->actual_qty, (string) $row->piece_qty, 8) !== 0
                || bccomp((string) $row->piece_qty, bcadd((string) $row->piece_qty, '0', 0), 8) !== 0) $this->commands->fail('cutting_component_source_not_owned', '组件结果必须引用本次已收来源覆盖的冻结规格和整数件数。', 409);
            $pieces[$id] = bcadd($pieces[$id] ?? '0', (string) $row->piece_qty, 8);
        }
        foreach ($pieces as $id => $quantity) {
            $r = $proven->get($id); $other = $this->componentQuantityUsage((int) $batch->cutting_order_id, $id, (int) $batch->id);
            $covered = FrozenCuttingStockPolicy::isTotalLength($r) ? $r['covered_total_length_mm'] : $r['covered_piece_qty'];
            $required = FrozenCuttingStockPolicy::isTotalLength($r) ? $r['required_total_length_mm'] : $r['required_piece_qty'];
            if (bccomp($quantity, $covered, 8) > 0 || bccomp(bcadd($quantity, $other, 8), $required, 8) > 0)
                $this->commands->fail('cutting_component_result_quantity_invalid', '逐规格结果合计不能超过该实际来源覆盖或正式剩余需求。', 409);
        }
    }

    private function componentProjection(array $requirements, ?object $link): array
    {
        return array_map(function ($row) use ($link) {
            $size = $this->json($row['cutting_requirement_snapshot']);
            $confirmed = $link ? $this->componentQuantityUsage((int) $link->cutting_order_id, (int) $row['target_requirement_id'], null, true) : '0.00000000';
            if (FrozenCuttingStockPolicy::isTotalLength($size)) {
                $required = FrozenCuttingStockPolicy::requiredTotalLength($size);
                return ['target_material_requirement_id' => (int) $row['target_requirement_id'], 'bom_item_id' => (int) $row['bom_item_id'], 'raw_item_id' => (int) $row['component_item_id'],
                    'raw_item_code' => $row['component_item_code_snapshot'], 'raw_item_name' => $row['component_item_name_snapshot'],
                    'quantity_basis' => 'total_length', 'quantity_unit' => 'm', 'dimensions' => null,
                    'required_piece_qty' => null, 'confirmed_piece_qty' => null, 'remaining_piece_qty' => null,
                    'required_total_length_mm' => $required, 'confirmed_total_length_mm' => $confirmed,
                    'remaining_total_length_mm' => bccomp($required, $confirmed, 8) > 0 ? bcsub($required, $confirmed, 8) : '0.00000000'];
            }
            return ['target_material_requirement_id' => (int) $row['target_requirement_id'], 'bom_item_id' => (int) $row['bom_item_id'], 'raw_item_id' => (int) $row['component_item_id'],
                'raw_item_code' => $row['component_item_code_snapshot'], 'raw_item_name' => $row['component_item_name_snapshot'],
                'dimensions' => ['length_mm' => $size['length_mm'], 'width_mm' => $size['width_mm'] ?? null, 'thickness_mm' => $size['thickness_mm'] ?? null],
                'required_piece_qty' => bcadd((string) $row['required_piece_qty'], '0', 8), 'confirmed_piece_qty' => $confirmed,
                'remaining_piece_qty' => max(0, (int) bcsub((string) $row['required_piece_qty'], $confirmed, 8))];
        }, $requirements);
    }

    private function json(mixed $value): array
    { return is_array($value) ? $value : ($value ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : []); }

    public function receivedInputValid(object $batch): bool
    {
        $row = DB::table('erp_production_cutting_inputs')->where('settlement_batch_id',$batch->id)->first();
        if (! $row) return false;
        $input = DB::table('erp_production_input_holdings')->where('id',$row->production_input_holding_id)->first();
        $snapshot = $this->json($row->requirement_snapshot);
        $componentMode = ($snapshot['output_kind'] ?? null) === ProductionCuttingComponentService::KIND;
        return $input && (int) $input->input_holding_id === (int) $batch->source_holding_id
            && ($componentMode ? (bccomp((string) $row->quantity, (string) $snapshot['actual_stock_qty'], 8) === 0
                && bccomp((string) $batch->input_qty, (string) $snapshot['source_count'], 8) === 0) : bccomp((string) $row->quantity,(string) $batch->input_qty,8) === 0)
            && bccomp((string) $row->total_cost,(string) $batch->original_total_cost,4) === 0
            && DB::table('erp_material_movements')->where('source_holding_id',$batch->source_holding_id)->where('target_holding_id',$batch->wip_holding_id)
                ->where('action','PROD_CUTTING_USE')->where('quantity',$row->quantity)->where('total_cost',$row->total_cost)->exists();
    }

    private function context(int $taskId, string $type, int $id, object $user, array $permissions, bool $super, bool $lock = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.record');
        $query = ProductionTask::query()->whereKey($taskId); if ($lock) $query->lockForUpdate();
        $task = $query->first();
        if (! $task || ! $task->targets()->where('target_type', $type)->where('target_id', $id)->exists())
            $this->commands->fail('task_target_not_found', '当前任务不存在该工序。', 404);
        $this->commands->workOrder($task->work_order_id, $user, $permissions, $super, 'production.cutting.record');
        if ((int) $task->assignee_user_legacy_id !== $this->commands->actor($user))
            $this->commands->fail('task_owner_required', '仅本工序负责人可以登记下料结果。', 403);
        if ($lock && $task->active_job_bundle_id) $this->commands->fail('job_bundle_execution_required', '当前任务已加入共同加工作业，请从共同加工作业办理。', 409);
        $model = match ($type) { 'unit_operation'=>ProductionUnitOperation::class, 'quantity_operation'=>ProductionQuantityOperation::class,
            default=>null };
        if (! $model) $this->commands->fail('target_type_invalid', '工序类型无效。');
        $query = $model::query()->whereKey($id); if ($lock) $query->lockForUpdate();
        $target = $query->first();
        if (! $target || (int) $target->work_order_id !== (int) $task->work_order_id) $this->commands->fail('target_missing', '工序不存在。', 404);
        return [$task, $target];
    }
}
