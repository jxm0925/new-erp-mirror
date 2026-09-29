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
            ->orderBy('batch.id')->paginate(20,['*'],'batch_page',max(1,(int) ($filters['batch_page'] ?? 1)));
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
        if ($batch) $data['requirement'] = $this->frozenRequirement($batch, DB::table('erp_cutting_allowed_outputs')->where('cutting_order_id',$link->cutting_order_id)->first());
        if (in_array('production.cutting.confirm',$permissions,true) && $link) {
            $data['material_costs'] = [
                'input'=>(string) DB::table('erp_production_cutting_inputs')->where('operation_id',$link->id)->sum('total_cost'),
                'by_result'=>DB::table('erp_cutting_results as result')->join('erp_cutting_settlement_batches as batch','batch.id','=','result.settlement_batch_id')
                    ->where('batch.cutting_order_id',$link->cutting_order_id)->where('result.status','CONFIRMED')
                    ->groupBy('result.result_type')->selectRaw('result.result_type, SUM(result.total_cost) as total_cost')->get(),
            ];
        }
        // Workers need quantities and provenance; financial amounts require the existing cost permission.
        if (! in_array('production.cutting.confirm',$permissions,true)) {
            foreach ($data['batches']['data'] as $row) unset($row->original_total_cost);
            if ($data['current_batch']) unset($data['current_batch']->original_total_cost);
            foreach ($data['results'] as $row) unset($row->total_cost);
        }
        return $data;
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
                'item.item_code','item.item_name','item.spec','item.cutting_mode','item.standard_stock_length_mm','holding.quantity as available_qty','posted.batch_no')->orderBy('input.id')->orderBy('physical.id');
        }
        $page = $query->paginate(min(20,max(1,(int) ($filters['per_page'] ?? 20))),['*'],'page',max(1,(int) ($filters['page'] ?? 1)));
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
                $requirements = $this->requirements($type, $id)->get(['target.id as target_requirement_id', 'material.*']);
                if ($requirements->isEmpty()) $this->commands->fail('cutting_requirement_missing', '本工序没有冻结的板材或管材下料要求。', 409);
                $workOrder = WorkOrder::findOrFail($task->work_order_id);
                $outputItemId = (int) $target->output_item_id_snapshot;
                if (! $outputItemId) $this->commands->fail('cutting_output_missing', '请先由技术人员维护工序产出，现场不能另选产品。', 409);
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
                    'output_item_id'=>$outputItemId, 'configuration_id'=>$configurationId, 'operation_name'=>$target->operation_name_snapshot,
                    'stage_id'=>$target->routing_operation_id_snapshot, 'requirements'=>$requirements->map(fn ($r) => (array) $r)->all()];
                $operationId = DB::table('erp_production_cutting_operations')->insertGetId([
                    'work_order_id'=>$workOrder->id, 'production_task_id'=>$taskId, 'target_type'=>$type, 'target_id'=>$id,
                    'cutting_order_id'=>$orderId, 'cutting_task_id'=>$cuttingTaskId, 'technical_snapshot'=>json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'created_by_legacy_id'=>$actor, 'created_at'=>$now, 'updated_at'=>$now,
                ]);
                // Quality and destination are enforced once by ordinary production completion/quality/handover.
                DB::table('erp_cutting_allowed_outputs')->insert([
                    'cutting_order_id'=>$orderId, 'item_id'=>$outputItemId, 'configuration_id'=>$configurationId,
                    'stage_id'=>$target->routing_operation_id_snapshot, 'quality_mode'=>'none', 'output_mode'=>'stockable', 'work_mode'=>$target->work_mode_snapshot ?: 'manual',
                    'rule_snapshot'=>json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at'=>$now, 'updated_at'=>$now,
                ]);
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
                $physical = null;
                if ($requirement->cutting_mode === 'sheet') {
                    $physical = DB::table('erp_material_physicals')->where('id',(int) ($payload['physical_material_id'] ?? 0))->lockForUpdate()->first();
                    if (! $physical || $physical->status !== 'PRODUCTION_RECEIVED' || (int) $physical->item_id !== (int) $posted->item_id
                        || (int) $physical->current_holding_id !== (int) $holding->id)
                        $this->commands->fail('cutting_physical_receipt_mismatch', '这张板材不属于本工序已收材料的实际出库批次，或已被使用。', 409);
                    if (isset($payload['quantity']) && bccomp(CuttingDecimal::value($payload['quantity']), '1', 8) !== 0)
                        $this->commands->fail('physical_quantity_invalid','板材按一张具体实物登记。');
                    $quantity = '1.00000000';
                } else {
                    if (! empty($payload['physical_material_id'])) $this->commands->fail('physical_quantity_invalid','管材用料不能指定板材实物。');
                    $quantity = CuttingDecimal::value($payload['quantity'] ?? null);
                    if (bccomp($quantity, bcadd($quantity,'0',0),8) !== 0 || ! $requirement->standard_stock_length_mm)
                        $this->commands->fail('root_quantity_invalid','请登记实际整根数量，原料必须有标准长度。');
                }
                if (bccomp($quantity,(string) $holding->quantity,8) > 0) $this->commands->fail('cutting_received_quantity_exceeded','加工数量超过本工序尚未使用的实际收料。',409);
                $cost = $physical ? (string) $physical->total_cost : CuttingDecimal::share((string) $holding->total_cost,(string) $holding->quantity,$quantity); $now = now();
                if (bccomp($cost,(string) $holding->total_cost,4) > 0) $this->commands->fail('cutting_received_cost_exceeded','板材实物金额超过本工序实际收料剩余金额。',409);
                $batchId = DB::table('erp_cutting_settlement_batches')->insertGetId([
                    'batch_no'=>$this->numbers->next('cutting_settlement','CB'), 'cutting_order_id'=>$link->cutting_order_id,
                    'cutting_task_id'=>$link->cutting_task_id,'input_item_id'=>$requirement->component_item_id,'physical_material_id'=>$physical?->id,
                    'source_holding_id'=>$holding->id,'input_qty'=>$quantity,'original_total_cost'=>$cost,
                    'standard_stock_length_mm'=>$physical ? null : $requirement->standard_stock_length_mm,
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
                $this->assertSourceFits($frozen);
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
        if ($source['mode'] === 'length') {
            if (bccomp((string) $r['required_length_mm'],(string) $source['length_mm'],8) > 0) $this->commands->fail('output_too_large','要求长度超过本次实际材料长度。');
            return;
        }
        if (bccomp((string) $r['required_thickness_mm'],(string) $source['thickness_mm'],8) !== 0)
            $this->commands->fail('output_thickness_mismatch','实际板材厚度与冻结工艺要求不符。');
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
                if (! $rows) $this->commands->fail('cutting_results_required', '本次用料必须登记产出、余料或实际损耗，不能空白结算。');
                $prefix = 'op-cut-save-'.hash('sha256',$payload['client_command_id']);
                $saved = app(CuttingRecordService::class)->saveResults($batchId,['client_command_id'=>$prefix,'expected_version'=>$batch->business_version,'results'=>$rows],$user,$permissions,$super);
                $all = DB::table('erp_cutting_results')->where('settlement_batch_id',$batchId)->whereNotIn('status',['VOIDED','SUPERSEDED'])->get();
                app(CuttingOutputEligibilityService::class)->assertBatchFits($batch,$all);
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
        foreach ($this->requirements($type,$target->id)->get(['target.id']) as $requirement) {
            $inputs = DB::table('erp_production_input_holdings')->where('target_material_requirement_id',$requirement->id)->pluck('id');
            if (! DB::table('erp_production_cutting_inputs')->where('operation_id',$link->id)->whereIn('production_input_holding_id',$inputs)->exists())
                $this->commands->fail('cutting_input_missing','存在尚未登记实际加工用料的下料需求。',409);
            if (DB::table('erp_production_input_holdings as input')->join('erp_material_holdings as holding','holding.id','=','input.input_holding_id')
                ->whereIn('input.id',$inputs)->where('holding.status','ACTIVE')->where('holding.quantity','>',0)->exists())
                $this->commands->fail('cutting_unused_received_material','还有已收原材料未加工，请继续登记或先办理生产退料。',409);
        }
    }

    private function confirmedOutputQuantity(object $link, string $type, object $target): string
    {
        $expected = $type === 'quantity_operation' ? (string) $target->planned_base_qty : '1';
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
        return DB::table('erp_production_cutting_inputs as used')->join('erp_production_cutting_operations as operation','operation.id','=','used.operation_id')
            ->join('erp_production_input_holdings as input','input.id','=','used.production_input_holding_id')
            ->where('operation.target_type',$type)->where('operation.target_id',$id)->distinct()->pluck('input.target_material_requirement_id')->all();
    }

    public function attachOutputCosts(object $output, object $target, string $type, int $actor): object
    {
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
        if ((int) $r['item_id'] !== (int) $allowed->item_id || (int) $r['configuration_id'] !== (int) $allowed->configuration_id)
            $this->commands->fail('cutting_output_identity_invalid','产出必须使用本工序冻结的物料和配置。');
        return $r;
    }

    public function receivedInputValid(object $batch): bool
    {
        $row = DB::table('erp_production_cutting_inputs')->where('settlement_batch_id',$batch->id)->first();
        if (! $row) return false;
        $input = DB::table('erp_production_input_holdings')->where('id',$row->production_input_holding_id)->first();
        return $input && (int) $input->input_holding_id === (int) $batch->source_holding_id
            && bccomp((string) $row->quantity,(string) $batch->input_qty,8) === 0
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
        $model = match ($type) { 'unit_operation'=>ProductionUnitOperation::class, 'quantity_operation'=>ProductionQuantityOperation::class,
            default=>null };
        if (! $model) $this->commands->fail('target_type_invalid', '工序类型无效。');
        $query = $model::query()->whereKey($id); if ($lock) $query->lockForUpdate();
        $target = $query->first();
        if (! $target || (int) $target->work_order_id !== (int) $task->work_order_id) $this->commands->fail('target_missing', '工序不存在。', 404);
        return [$task, $target];
    }
}
