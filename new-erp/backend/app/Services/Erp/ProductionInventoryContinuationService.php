<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{InventoryBalance, InventoryLocationBalance, Item, ProductionExecutionCommand, ProductionOutputRecord, WorkOrder};
use Illuminate\Support\Facades\DB;

/** Reserve real intermediate stock and resume its frozen production checkpoint. */
class ProductionInventoryContinuationService
{
    public function __construct(private readonly InventoryAvailabilityService $availability, private readonly DocumentNumberService $numbers) {}

    public function candidates(int $workOrderId, array $filters, object $user, array $permissions, bool $super = false): array
    {
        $wo = app(WorkOrderApplicationService::class)->showWorkOrder($workOrderId, $user, $permissions, $super);
        // A common warehouse batch may contain many individually produced serials.
        // Page source checkpoints, rather than collapsing them into an ambiguous balance.
        $query = DB::table('erp_production_output_warehouse_postings as post')
            ->join('erp_production_output_records as output', 'output.id', '=', 'post.output_record_id')
            ->join('erp_work_orders as source', 'source.id', '=', 'output.work_order_id')
            ->join('erp_inventory_balances as balance', fn ($q) => $q->on('balance.item_id', '=', 'output.output_item_id')
                ->on('balance.warehouse_id', '=', 'post.warehouse_id')->on('balance.location_id', '=', 'post.location_id')->on('balance.batch_no', '=', 'post.batch_no'))
            ->join('erp_items as item', 'item.id', '=', 'balance.item_id')
            ->where('balance.quantity_available', '>', 0)->where('post.status', 'POSTED')->where('source.output_item_id', $wo->output_item_id);
        if (! empty($filters['keyword'])) {
            $keyword = '%'.trim($filters['keyword']).'%';
            $query->where(fn ($q) => $q->where('balance.batch_no', 'like', $keyword)->orWhere('item.item_code', 'like', $keyword)
                ->orWhere('item.item_name', 'like', $keyword)->orWhere('item.spec', 'like', $keyword));
        }
        $page = $query->select('balance.id as balance_id', 'output.id as output_id')->distinct()->orderBy('balance.id')->orderBy('output.id')
            ->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))));
        $rows = [];
        foreach ($page->items() as $candidate) {
            $balance = InventoryBalance::find($candidate->balance_id);
            try { $context = $this->source($wo, (int) $balance->id, null, (string) min(1, (float) $balance->quantity_available), false, (int) $candidate->output_id); }
            catch (WorkOrderDomainException $e) { continue; }
            $item = Item::find($balance->item_id);
            $serialQuery = DB::table('erp_inventory_serials')->where('inventory_balance_id', $balance->id)->where('serial_status', 'available')
                ->where('source_document_type', 'production_output')->where('source_document_id', $context['source_output_record_id']);
            $serialCount = $serialQuery->count();
            if ($item?->serialTrackingMode() !== 'none' && $serialCount === 0) continue;
            $serials = $serialQuery->select('id', 'serial_no')->limit(50)->get();
            $rows[] = ['inventory_balance_id' => (int) $balance->id, 'source_output_record_id' => $context['source_output_record_id'],
                'item_id' => (int) $balance->item_id, 'item_code' => $item?->item_code, 'item_name' => $item?->item_name,
                'spec' => $item?->spec, 'batch_no' => $balance->batch_no, 'available_base_qty' => (string) ($item?->serialTrackingMode() !== 'none' ? min($serialCount, (float) $balance->quantity_available) : $balance->quantity_available),
                'unit_name' => $item?->unit?->unit_name, 'serial_tracking_mode' => $item?->serialTrackingMode(),
                'serials' => $serials, 'start_routing_operation_id' => $context['start_routing_operation_id'],
                'start_sequence' => $context['start_sequence'], 'start_operation_name' => $context['start_operation_name'],
                'source_work_order_no' => $context['source_work_order_no']];
        }
        return ['data' => $rows, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total()]];
    }

    public function serials(int $id, array $filters, object $user, array $permissions, bool $super = false): array
    {
        $wo = app(WorkOrderApplicationService::class)->showWorkOrder($id, $user, $permissions, $super);
        $source = $this->source($wo, (int) $filters['inventory_balance_id'], null, '1', false, empty($filters['source_output_record_id']) ? null : (int) $filters['source_output_record_id']);
        $page = DB::table('erp_inventory_serials')->where('inventory_balance_id', $filters['inventory_balance_id'])
            ->where('serial_status', 'available')->where('source_document_type', 'production_output')->where('source_document_id', $source['source_output_record_id'])
            ->when($filters['keyword'] ?? null, fn ($q, $v) => $q->where('serial_no', 'like', '%'.trim($v).'%'))
            ->orderBy('id')->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))), ['id', 'serial_no']);
        return ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]];
    }

    public function configure(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        if (! $super && ! in_array('production.work_order.edit', $permissions, true)) $this->fail('permission_denied', '没有工单编辑权限。', 403);
        app(WorkOrderApplicationService::class)->showWorkOrder($id, $user, $permissions, $super);
        return DB::transaction(function () use ($id, $payload, $user): array {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($id);
            $hash = hash('sha256', json_encode([$id, $payload, (int) ($user->legacy_id ?? $user->id)], JSON_THROW_ON_ERROR));
            $old = ProductionExecutionCommand::where('client_command_id', $payload['client_command_id'])->first();
            if ($old) {
                if ($old->command_type !== 'configure_inventory_continuation' || $old->request_hash !== $hash) $this->fail('command_conflict', '命令标识已用于其他请求。', 409);
                return $old->response_snapshot;
            }
            if (! in_array($wo->status, ['DRAFT', 'WAIT_RELEASE'], true)) $this->fail('continuation_plan_locked', '只可在工单发布前选择库存续接。', 409);
            app(AssemblyProductionApplicationService::class)->assertMutable($wo);
            if ((int) $wo->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '工单已变化，请刷新后重试。', 409);
            if ($wo->source_type === 'stock_prebuild') $this->fail('continuation_source_invalid', '备货工单不能再次采用库存续接。');
            $plan = []; $sum = '0'; $balances = []; $serials = [];
            foreach ($payload['sources'] as $row) {
                $qty = CuttingDecimal::value($row['base_qty'], 8, true);
                if (($wo->outputItem?->production_execution_mode ?: 'unit') === 'unit' && bccomp($qty, bcadd($qty, '0', 0), 8) !== 0) $this->fail('continuation_unit_quantity_invalid', '逐件生产每条库存来源都必须是整数件。');
                $serial = empty($row['inventory_serial_id']) ? null : (int) $row['inventory_serial_id'];
                $source = $this->source($wo, (int) $row['inventory_balance_id'], $serial, $qty, true, empty($row['source_output_record_id']) ? null : (int) $row['source_output_record_id']);
                if ($serial && isset($serials[$serial])) $this->fail('continuation_serial_duplicate', '不能重复选择同一库存序列号。');
                if ($serial) $serials[$serial] = true;
                $balances[$row['inventory_balance_id']] = bcadd($balances[$row['inventory_balance_id']] ?? '0', $qty, 8);
                if (bccomp($balances[$row['inventory_balance_id']], (string) InventoryBalance::find($row['inventory_balance_id'])->quantity_available, 8) > 0) $this->fail('continuation_inventory_insufficient', '选用数量超过该批库存可用量。');
                $plan[] = $source; $sum = bcadd($sum, $qty, 8);
            }
            if (bccomp($sum, (string) $wo->target_base_qty, 8) > 0) $this->fail('continuation_quantity_exceeded', '库存续接数量超过工单数量。');
            $mode = $wo->outputItem?->production_execution_mode ?: 'unit';
            if ($mode === 'unit' && bccomp($sum, bcadd($sum, '0', 0), 8) !== 0) $this->fail('continuation_unit_quantity_invalid', '逐件生产只能选整数件库存。');
            $before = (int) $wo->business_version;
            $wo->update(['inventory_continuation_plan' => $plan, 'business_version' => $before + 1, 'updated_by_legacy_id' => $user->legacy_id ?? $user->id]);
            $response = ['id' => $id, 'business_version' => $before + 1, 'inventory_continuation_plan' => $plan];
            ProductionExecutionCommand::create(['client_command_id' => $payload['client_command_id'], 'command_type' => 'configure_inventory_continuation',
                'aggregate_type' => 'work_order', 'aggregate_id' => $id, 'request_hash' => $hash, 'status' => 'succeeded',
                'response_snapshot' => $response, 'initiated_by_legacy_id' => $user->legacy_id ?? $user->id, 'processing_finished_at' => now()]);
            DB::table('erp_production_execution_events')->insert(['aggregate_type' => 'work_order', 'aggregate_id' => $id,
                'action' => 'configure_inventory_continuation', 'before_version' => $before, 'after_version' => $before + 1,
                'fact_snapshot' => json_encode($plan, JSON_THROW_ON_ERROR), 'operator_legacy_id' => $user->legacy_id ?? $user->id,
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            return $response;
        }, 5);
    }

    private function source(WorkOrder $wo, int $balanceId, ?int $serialId, string $qty, bool $lock, ?int $requestedOutputId = null): array
    {
        $q = InventoryBalance::whereKey($balanceId); if ($lock) $q->lockForUpdate();
        $balance = $q->first();
        if (! $balance || bccomp((string) $balance->quantity_available, $qty, 8) < 0) $this->fail('continuation_inventory_insufficient', '库存不存在或可用数量不足。');
        $serial = null;
        if ($serialId) {
            $serialQuery = DB::table('erp_inventory_serials')->where('id', $serialId); if ($lock) $serialQuery->lockForUpdate();
            $serial = $serialQuery->first();
            if (! $serial || (int) $serial->inventory_balance_id !== $balanceId || $serial->serial_status !== 'available'
                || $serial->source_document_type !== 'production_output' || bccomp($qty, '1', 8) !== 0) $this->fail('continuation_serial_invalid', '序列号不属于所选生产库存，或数量不是一件。');
        }
        $outputId = $serial ? (int) $serial->source_document_id : app(ProductionShipmentSourceResolver::class)->outputIdForBalance($balance);
        if ($requestedOutputId !== null) {
            if (! $serial && DB::table('erp_inventory_serials')->where('inventory_balance_id', $balanceId)->where('serial_status', 'available')
                ->where('source_document_type', 'production_output')->where('source_document_id', $requestedOutputId)->exists()) $outputId = $requestedOutputId;
            if ($outputId !== null && $outputId !== $requestedOutputId) $this->fail('continuation_source_changed', '所选库存产出来源已变化，请重新选择。', 409);
            if ($outputId === null && ! DB::table('erp_inventory_serials')->where('inventory_balance_id', $balanceId)
                ->where('serial_status', 'available')->where('source_document_type', 'production_output')->where('source_document_id', $requestedOutputId)->exists()) $this->fail('continuation_source_missing', '该库存批次无法确定实际生产来源。');
            $outputId = $requestedOutputId;
        }
        $output = $outputId ? ProductionOutputRecord::find($outputId) : null;
        $sourceWo = $output ? WorkOrder::find($output->work_order_id) : null;
        if (! $output || ! $sourceWo || (int) $sourceWo->output_item_id !== (int) $wo->output_item_id
            || (int) $output->output_item_id !== (int) $balance->item_id || in_array($output->status, ['QUALITY_FAILED', 'CANCELLED', 'REWORK', 'REJECTED', 'HANDOVER_REJECTED'], true)) $this->fail('continuation_source_missing', '库存缺少对应物料的正式生产产出来源。');
        $postedQty = DB::table('erp_production_output_warehouse_postings as post')->where('post.output_record_id', $output->id)
            ->where('post.warehouse_id', $balance->warehouse_id)->where('post.location_id', $balance->location_id)->where('post.batch_no', $balance->batch_no)
            ->where('post.status', 'POSTED')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_inventory_transactions as tx')->whereColumn('tx.id', 'post.inventory_transaction_id')
                ->where('tx.posting_status', 'posted')->where(fn ($types) => $types
                    ->where(fn ($mid) => $mid->where('tx.source_type', 'production_output_record')->where('tx.source_id', $output->id)->where('tx.transaction_type', 'production_output_receipt'))
                    ->orWhere(fn ($finished) => $finished->where('tx.source_type', 'work_order_finished_goods_receipt')->where('tx.transaction_type', 'finished_goods_receipt')
                        ->whereExists(fn ($receipt) => $receipt->selectRaw('1')->from('erp_work_order_finished_goods_receipts as receipt')
                            ->whereColumn('receipt.id', 'tx.source_id')->where('receipt.output_record_id', $output->id)))))
            ->sum('post.posted_base_qty');
        if (bccomp((string) $postedQty, $qty, 8) < 0) $this->fail('continuation_source_not_posted', '所选生产产出没有对应的正式入库事实。');
        if ((int) ($sourceWo->output_configuration_id ?? 0) !== (int) ($wo->output_configuration_id ?? 0)) $this->fail('continuation_configuration_mismatch', '库存与本工单产品配置不一致。');
        $table = $output->source_target_type === 'unit_operation' ? 'erp_production_unit_operations' : 'erp_production_quantity_operations';
        $target = DB::table($table)->where('id', $output->source_target_id)->first();
        $old = $this->nodes($sourceWo); $current = $this->nodes($wo);
        $index = $old->search(fn ($node) => (int) $node['routing_operation_id'] === (int) ($target?->routing_operation_id_snapshot ?? 0));
        if ($index === false || ! isset($current[$index + 1])) $this->fail('continuation_checkpoint_invalid', '库存没有可续接的剩余生产工序。');
        foreach ($old->take($index + 1) as $i => $node) {
            if (! isset($current[$i]) || $this->signature($node) !== $this->signature($current[$i])) $this->fail('continuation_routing_mismatch', '库存前序工艺与本工单不兼容，不能跳过已有工序。');
        }
        foreach (app(ProductionOutputTraceService::class)->contributions((int) $output->id, $qty) as $row) {
            if (($row['trace_status'] ?? '') !== 'complete') $this->fail('continuation_lineage_missing', '库存来源数量关系不完整，请先核对。');
            $ancestor = ProductionOutputRecord::find($row['output_record_id']);
            if ($ancestor?->quality_mode_snapshot === 'required' && ! DB::table('erp_production_quality_inspections')->where('output_record_id', $ancestor->id)
                ->where('status', 'COMPLETED')->where('result', 'passed')->exists()) $this->fail('continuation_quality_pending', '库存来源工序质检尚未合格。');
        }
        $item = Item::find($balance->item_id);
        if ($item?->serialTrackingMode() !== 'none' && ! $serialId && $lock) $this->fail('continuation_serial_required', '请选择本次续接的具体库存序列号。');
        return ['source_output_record_id' => (int) $output->id, 'inventory_balance_id' => $balanceId, 'inventory_serial_id' => $serialId,
            'base_qty' => $qty, 'start_routing_operation_id' => (int) $current[$index + 1]['routing_operation_id'],
            'start_sequence' => (int) $current[$index + 1]['sequence'], 'start_operation_name' => $current[$index + 1]['operation_name'],
            'source_work_order_id' => (int) $sourceWo->id, 'source_work_order_no' => $sourceWo->work_order_no,
            'source_routing_version' => $sourceWo->routing_version_snapshot, 'source_target_type' => $output->source_target_type,
            'source_target_id' => (int) $output->source_target_id, 'item_id' => (int) $output->output_item_id];
    }

    public function nodes(WorkOrder $wo): \Illuminate\Support\Collection
    {
        return collect(data_get($wo->routing_snapshot, 'operations', []))->filter(fn ($n) => ($n['execution_context'] ?? 'production') === 'production')->sortBy('sequence')->values();
    }
    private function signature(array $node): array
    {
        return [(int) $node['operation_id'], (int) ($node['output_item_id'] ?? 0), $node['quality_mode'] ?? 'none',
            $node['work_mode'] ?? 'manual', $node['parameters'] ?? []];
    }
    public function plannedQuantity(WorkOrder $wo, int $sequence): float
    {
        $plans = collect($wo->inventory_continuation_plan ?? []);
        return max(0, (float) $wo->target_base_qty - (float) $plans->where('start_sequence', '>', $sequence)->sum('base_qty'));
    }
    public function assertPlanQuantity(WorkOrder $wo, ?string $executionMode = null): void
    {
        $quantity = '0';
        $executionMode ??= $wo->production_execution_mode_snapshot ?: ($wo->outputItem?->production_execution_mode ?: 'unit');
        foreach ($wo->inventory_continuation_plan ?? [] as $plan) {
            $planned = CuttingDecimal::value($plan['base_qty'], 8, true);
            if ($executionMode === 'unit' && bccomp($planned, bcadd($planned, '0', 0), 8) !== 0) $this->fail('continuation_unit_quantity_invalid', '逐件生产每条库存来源都必须是整数件，请重新选择库存。');
            $quantity = bcadd($quantity, $planned, 8);
        }
        if (bccomp($quantity, (string) $wo->target_base_qty, 8) > 0) $this->fail('continuation_quantity_exceeded', '已选库存续接数量超过工单数量，请先调整库存来源。');
    }
    public function unitPlan(WorkOrder $wo, int $unitSequence): ?array
    {
        $cursor = 0;
        foreach ($wo->inventory_continuation_plan ?? [] as $plan) {
            $cursor += (int) $plan['base_qty'];
            if ($unitSequence <= $cursor) return $plan;
        }
        return null;
    }

    /** Freeze remaining raw demand and explicit stock inputs; no fresh demand for skipped operations. */
    public function materialRows(WorkOrder $wo, array $rows): array
    {
        $this->assertPlanQuantity($wo);
        if (! $wo->inventory_continuation_plan) return $rows;
        $nodes = $this->nodes($wo)->keyBy('routing_operation_id'); $result = [];
        foreach ($rows as $row) {
            $row['requirement_kind'] = 'standard';
            $originalRequired = (float) $row['base_required_qty'];
            $remaining = []; $total = 0;
            foreach ($nodes as $node) foreach ($node['material_supply_rules'] ?? [] as $rule) {
                if ((int) $rule['component_item_id'] !== (int) $row['component_item_id']) continue;
                $target = $nodes->get((int) $rule['target_routing_operation_id']); if (! $target) continue;
                $quantity = $this->plannedQuantity($wo, (int) $target['sequence']);
                if ($quantity <= 0) continue;
                $required = round(((float) $row['per_output_qty'] * $quantity * (1 + (float) $row['loss_rate'] / 100)
                    + (float) $row['fixed_qty']) * (float) $rule['required_qty_ratio'], 8);
                $remaining['rule:'.$rule['rule_id']] = $required;
                $total += $required;
            }
            if ($total <= 0) continue;
            $row['remaining_supply_snapshot'] = json_encode($remaining, JSON_THROW_ON_ERROR);
            $row['base_required_qty'] = $row['required_qty'] = $row['remaining_qty'] = round($total, 8);
            if ($row['required_piece_qty'] !== null) $row['required_piece_qty'] = round((float) $row['required_piece_qty'] * $total / $originalRequired, 8);
            $result[] = $row;
        }
        foreach (collect($wo->inventory_continuation_plan)->groupBy(fn ($p) => $p['start_routing_operation_id'].':'.$p['item_id']) as $plans) {
            $plan = $plans->first(); $item = Item::with('unit')->findOrFail($plan['item_id']);
            $row = $rows[0]; $qty = (float) $plans->sum('base_qty');
            $row = array_replace($row, ['line_no' => 900000 + count($result), 'bom_item_id' => null,
                'requirement_kind' => 'stock_continuation', 'component_item_id' => $item->id,
                'component_item_code_snapshot' => $item->item_code, 'component_item_name_snapshot' => $item->item_name,
                'component_spec_snapshot' => $item->spec, 'configuration_id' => null, 'configuration_snapshot' => null,
                'cut_length_mm_snapshot' => null, 'cutting_requirement_snapshot' => null, 'per_output_piece_qty' => null,
                'required_piece_qty' => null, 'per_output_qty' => 1, 'loss_rate' => 0, 'fixed_qty' => 0,
                'required_qty' => $qty, 'base_required_qty' => $qty, 'remaining_qty' => $qty,
                'unit_id' => $item->unit_id, 'base_unit_id' => $item->unit_id,
                'unit_name_snapshot' => $item->unit?->unit_name, 'base_unit_name_snapshot' => $item->unit?->unit_name,
                'remaining_supply_snapshot' => json_encode([$plan['start_routing_operation_id'] => $qty], JSON_THROW_ON_ERROR)]);
            $result[] = $row;
        }
        return $result;
    }

    /** Called inside publish after the execution targets exist. Locks exactly the selected physical sources. */
    public function reservePublished(WorkOrder $wo, object $user): void
    {
        $unitSequence = 0;
        foreach ($wo->inventory_continuation_plan ?? [] as $plan) {
            $current = $this->source($wo, (int) $plan['inventory_balance_id'], $plan['inventory_serial_id'], (string) $plan['base_qty'], true, (int) $plan['source_output_record_id']);
            if ($current != $plan) $this->fail('continuation_source_changed', '所选库存工艺或来源已变化，请重新选择。', 409);
            $count = $wo->production_execution_mode_snapshot === 'unit' ? (int) $plan['base_qty'] : 1;
            for ($i = 0; $i < $count; $i++) {
                $unit = $wo->production_execution_mode_snapshot === 'unit'
                    ? DB::table('erp_production_units')->where('work_order_id', $wo->id)->where('sequence_no', ++$unitSequence)->first() : null;
                $type = $unit ? 'unit_operation' : 'quantity_operation';
                $table = $unit ? 'erp_production_unit_operations' : 'erp_production_quantity_operations';
                $target = DB::table($table)->where('work_order_id', $wo->id)->where('routing_operation_id_snapshot', $plan['start_routing_operation_id']);
                if ($unit) $target->where('production_unit_id', $unit->id);
                $target = $target->first();
                $taskId = DB::table('erp_production_task_targets')->where('target_type', $type)->where('target_id', $target->id)->value('task_id');
                $qty = $unit ? '1' : $plan['base_qty'];
                $balance = InventoryBalance::whereKey($plan['inventory_balance_id'])->lockForUpdate()->firstOrFail();
                $this->changeLock($balance, $qty);
                $continuationId = DB::table('erp_work_order_inventory_continuations')->insertGetId([
                    'work_order_id' => $wo->id, 'source_output_record_id' => $plan['source_output_record_id'],
                    'inventory_balance_id' => $balance->id, 'inventory_serial_id' => $plan['inventory_serial_id'],
                    'production_unit_id' => $unit?->id, 'start_routing_operation_id' => $plan['start_routing_operation_id'],
                    'start_sequence' => $plan['start_sequence'], 'base_qty' => $qty, 'source_snapshot' => json_encode($plan, JSON_THROW_ON_ERROR),
                    'created_by_legacy_id' => $user->legacy_id ?? $user->id, 'created_at' => now(), 'updated_at' => now()]);
                $issueId = DB::table('erp_production_internal_issue_tasks')->insertGetId(['issue_no' => $this->numbers->next('production_internal_issue', 'PII'),
                    'work_order_id' => $wo->id, 'target_task_id' => $taskId, 'target_type' => $type, 'target_id' => $target->id,
                    'source_type' => 'inventory_continuation', 'status' => 'WAIT_ISSUE', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $requirement = DB::table('erp_production_target_material_requirements')->where('target_type', $type)->where('target_id', $target->id)
                    ->where('component_item_id', $balance->item_id)->where('requirement_kind', 'stock_continuation')->first();
                DB::table('erp_production_internal_issue_lines')->insert(['issue_task_id' => $issueId, 'inventory_continuation_id' => $continuationId,
                    'output_record_id' => $plan['source_output_record_id'], 'target_material_requirement_id' => $requirement?->id,
                    'item_id' => $balance->item_id, 'inventory_balance_id' => $balance->id, 'warehouse_id' => $balance->warehouse_id,
                    'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'serial_id' => $plan['inventory_serial_id'],
                    'serial_no_snapshot' => $plan['inventory_serial_id'] ? DB::table('erp_inventory_serials')->where('id', $plan['inventory_serial_id'])->value('serial_no') : null,
                    'issue_base_qty' => $qty, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('erp_work_order_inventory_continuations')->where('id', $continuationId)->update(['internal_issue_task_id' => $issueId]);
                if ($plan['inventory_serial_id']) DB::table('erp_inventory_serials')->where('id', $plan['inventory_serial_id'])->update(['serial_status' => 'continuation_reserved', 'updated_at' => now()]);
            }
        }
    }

    public function releaseForIssue(object $issue, iterable $lines, ?object $user = null): void
    {
        if ($issue->source_type !== 'inventory_continuation') return;
        foreach ($lines as $line) {
            $row = DB::table('erp_work_order_inventory_continuations')->where('id', $line->inventory_continuation_id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'RESERVED' || (int) $row->internal_issue_task_id !== (int) $issue->id
                || (int) $row->work_order_id !== (int) $issue->work_order_id || bccomp((string) $row->base_qty, (string) $line->issue_base_qty, 8) !== 0) $this->fail('continuation_reservation_invalid', '库存续接领用与锁定来源不一致。', 409);
            $balance = InventoryBalance::whereKey($row->inventory_balance_id)->lockForUpdate()->firstOrFail();
            $this->changeLock($balance, bcsub('0', (string) $row->base_qty, 8));
            DB::table('erp_work_order_inventory_continuations')->where('id', $row->id)->update(['status' => 'RECEIVED',
                'received_base_qty' => $row->base_qty, 'business_version' => $row->business_version + 1, 'updated_at' => now()]);
            if ($row->inventory_serial_id) {
                $serial = DB::table('erp_inventory_serials')->where('id', $row->inventory_serial_id)->lockForUpdate()->first();
                if (! $serial || $serial->serial_status !== 'continuation_reserved' || (int) $serial->source_document_id !== (int) $row->source_output_record_id) $this->fail('continuation_serial_invalid', '续接序列号状态或来源已变化。', 409);
                DB::table('erp_inventory_serials')->where('id', $serial->id)->update(['serial_status' => 'production_consumed', 'updated_at' => now()]);
                DB::table('erp_inventory_serial_events')->insert(['inventory_serial_id' => $serial->id, 'event_type' => 'production_continuation_received',
                    'document_type' => 'production_internal_issue', 'document_id' => $issue->id, 'document_no' => $issue->issue_no,
                    'from_status' => 'continuation_reserved', 'to_status' => 'production_consumed', 'warehouse_id' => $serial->warehouse_id,
                    'location_id' => $serial->location_id, 'batch_no' => $serial->batch_no, 'operator_id' => $user->legacy_id ?? $user->id ?? null,
                    'event_payload' => json_encode(['inventory_continuation_id' => (int) $row->id, 'source_output_record_id' => (int) $row->source_output_record_id], JSON_THROW_ON_ERROR),
                    'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
    public function assertShipmentLineReady(\App\Models\Erp\SalesShipmentLine $line): void
    {
        $context = app(ProductionShipmentSourceResolver::class)->contextForShipmentLine($line);
        if (! $context['production_ready']) $this->fail('shipment_production_not_ready', implode(' ', $context['blockers']), 409);
    }
    private function changeLock(InventoryBalance $balance, string $delta): void
    {
        $locked = bcadd((string) $balance->quantity_locked, $delta, 8);
        $location = InventoryLocationBalance::where('item_id', $balance->item_id)->where('warehouse_id', $balance->warehouse_id)->where('location_id', $balance->location_id)->lockForUpdate()->firstOrFail();
        $locationLocked = bcadd((string) $location->quantity_locked, $delta, 8);
        if (bccomp($locked, '0', 8) < 0 || bccomp($locked, (string) $balance->quantity_on_hand, 8) > 0 || bccomp($locationLocked, '0', 8) < 0) $this->fail('continuation_lock_invalid', '库存续接锁定数量与实际余额不一致。', 409);
        $balance->quantity_locked = $locked;
        $balance->quantity_available = $this->availability->calculate((float) $balance->quantity_on_hand, (float) $locked, (float) $balance->quantity_defective, (float) $balance->quantity_pending); $balance->save();
        $location->quantity_locked = $locationLocked;
        $location->quantity_available = $this->availability->calculate((float) $location->quantity_on_hand, (float) $locationLocked, (float) $location->quantity_defective, (float) $location->quantity_pending); $location->save();
    }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
