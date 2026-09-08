<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionOutputRecord;
use App\Models\Erp\ProductionQualityInspection;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;

class ProductionOutputService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly InventoryService $inventory,
        private readonly InventoryReservationService $reservations,
        private readonly WorkOrderCompletionService $completions,
    ) {}

    public function inspect(int $outputId, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.output.quality');
        return $this->command('inspect_production_output', $outputId, $payload, $user, function () use ($outputId, $payload, $user): array {
            $output = ProductionOutputRecord::query()->lockForUpdate()->find($outputId);
            if (! $output) $this->fail('output_not_found', '生产产出记录不存在。', 404);
            if ((int) $output->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '产出记录版本已变化，请刷新后重试。', 409);
            if ($output->quality_mode_snapshot === 'none') $this->fail('quality_not_required', '该工序产出不需要生产质检。');
            if ($output->status !== 'WAIT_QUALITY') $this->fail('quality_already_decided', '该生产产出不处于待质检状态。', 409);
            $result = (string) ($payload['result'] ?? '');
            $qualified = (float) ($payload['qualified_base_qty'] ?? 0); $unqualified = (float) ($payload['unqualified_base_qty'] ?? 0);
            if (! in_array($result, ['passed', 'failed'], true) || $qualified < 0 || $unqualified < 0
                || abs($qualified + $unqualified - (float) $output->output_base_qty) > 0.00000001) $this->fail('inspection_quantity_invalid', '质检合格量与不合格量之和必须等于产出数量。');
            $fullQty = (float) $output->output_base_qty;
            if (($result === 'passed' && (abs($qualified - $fullQty) > 0.00000001 || $unqualified > 0.00000001))
                || ($result === 'failed' && ($qualified > 0.00000001 || abs($unqualified - $fullQty) > 0.00000001))) {
                $this->fail('partial_quality_not_supported', '当前生产产出按整批质检；混合合格结果必须先拆分产出批次，禁止将不合格量随合格量入库或交接。');
            }
            $inspection = ProductionQualityInspection::create(['inspection_no' => $this->numbers->next('production_quality', 'PQA'),
                'output_record_id' => $output->id, 'status' => 'COMPLETED', 'result' => $result,
                'inspected_base_qty' => (float) $output->output_base_qty, 'qualified_base_qty' => $qualified,
                'unqualified_base_qty' => $unqualified, 'reason' => $payload['reason'] ?? null,
                'inspection_snapshot' => $payload['inspection_snapshot'] ?? null, 'inspector_legacy_id' => $this->userId($user),
                'inspected_at' => now(), 'business_version' => 1]);
            $terminal = $this->isTerminalOutput($output);
            $direct = $result === 'passed' && ! $terminal
                && (($payload['next_step'] ?? null) === 'direct_handover' || $output->output_mode_snapshot === 'flow_only');
            if ($direct && $output->output_mode_snapshot === 'warehouse_required') $this->fail('warehouse_required', '该工序产出必须正式入库，不能选择直接交接。');
            $passedStatus = $terminal ? 'WAIT_COMPLETION' : ($direct ? 'HANDED_OVER' : 'WAIT_WAREHOUSE');
            $output->update(['status' => $result === 'passed' ? $passedStatus : 'QUALITY_FAILED',
                'disposition' => $result, 'business_version' => (int) $output->business_version + 1]);
            $result === 'passed'
                ? $this->syncSourceTarget($output, $terminal || $direct ? 'COMPLETED' : 'WAIT_WAREHOUSE')
                : $this->reopenSourceTargetForRework($output, $user);
            if ($direct) $this->ensureNextTask($output, true, $this->userId($user));
            return ['inspection_id' => (int) $inspection->id, 'inspection_no' => $inspection->inspection_no,
                'result' => $result, 'output_status' => $output->status, 'output_business_version' => (int) $output->business_version];
        });
    }

    public function warehouse(int $outputId, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.output.warehouse');
        return $this->command('warehouse_production_output', $outputId, $payload, $user, function () use ($outputId, $payload, $user): array {
            $output = ProductionOutputRecord::query()->lockForUpdate()->find($outputId);
            if (! $output) $this->fail('output_not_found', '生产产出记录不存在。', 404);
            if ((int) $output->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '产出记录版本已变化，请刷新后重试。', 409);
            if ($output->output_mode_snapshot === 'flow_only') $this->fail('flow_only_cannot_warehouse', '纯流转产出禁止入库。');
            if ($output->quality_mode_snapshot !== 'none' && ! ProductionQualityInspection::query()->where('output_record_id', $output->id)->where('result', 'passed')->exists())
                $this->fail('quality_not_passed', '生产产出尚未通过独立生产质检，不能入库。', 409);
            if (! in_array($output->status, ['CREATED', 'WAIT_WAREHOUSE'], true)) $this->fail('output_not_wait_warehouse', '该生产产出不处于待入库状态。', 409);
            $terminal = $this->isTerminalOutput($output);
            $completionLine = $terminal ? $this->completions->approvedLineForOutput((int) $output->id) : null;
            if ($terminal && ! $completionLine) {
                $this->fail('completion_approval_required', '终末工序产出必须先完成工单完工审核，才能办理成品入库。', 409);
            }

            $quantity = (float) $output->output_base_qty;
            $remainingBefore = $quantity;
            $receiptId = null; $receiptNo = null;
            if ($terminal) {
                $posted = (float) DB::table('erp_work_order_finished_goods_receipts')
                    ->where('completion_line_id', $completionLine->id)->where('status', 'POSTED')->sum('posted_base_qty');
                $remainingBefore = max(0, (float) $completionLine->qualified_base_qty - $posted);
                $quantity = array_key_exists('posted_base_qty', $payload)
                    ? (float) $payload['posted_base_qty'] : $remainingBefore;
                if ($quantity <= 0.00000001 || $quantity > $remainingBefore + 0.00000001) {
                    $this->fail('finished_goods_receipt_quantity_invalid', '本次成品入库数量必须大于 0，且不能超过剩余可入库良品数量。', 409);
                }
                $receiptNo = $this->numbers->next('finished_goods_receipt', 'FGR');
                $receiptId = DB::table('erp_work_order_finished_goods_receipts')->insertGetId([
                    'receipt_no' => $receiptNo, 'completion_id' => $completionLine->completion_id,
                    'completion_line_id' => $completionLine->id, 'work_order_id' => $output->work_order_id,
                    'output_record_id' => $output->id, 'output_warehouse_posting_id' => null,
                    'output_item_id' => $output->output_item_id,
                    'base_unit_id' => DB::table('erp_work_orders')->where('id', $output->work_order_id)->value('base_unit_id'),
                    'warehouse_id' => $payload['warehouse_id'], 'location_id' => $payload['location_id'],
                    'batch_no' => $payload['batch_no'], 'posted_base_qty' => $quantity,
                    'status' => 'POSTING', 'inventory_transaction_id' => null,
                    'posted_by_legacy_id' => $this->userId($user), 'posted_at' => now(),
                    'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $receipt = DB::table('erp_work_order_finished_goods_receipts')->where('id', $receiptId)->first();
                $transaction = $this->inventory->postFinishedGoodsReceipt($receipt, $output, $payload, $user);
            } else {
                if (array_key_exists('posted_base_qty', $payload)
                    && abs((float) $payload['posted_base_qty'] - $quantity) > 0.00000001) {
                    $this->fail('intermediate_output_partial_warehouse_not_supported', '中间工序产出必须整批入库，不能拆分入库。', 409);
                }
                $transaction = $this->inventory->postProductionOutputReceipt($output, $payload, $user);
            }
            $inspectionId = ProductionQualityInspection::query()->where('output_record_id', $output->id)->value('id');
            $postingId = DB::table('erp_production_output_warehouse_postings')->insertGetId([
                'posting_no' => $this->numbers->next('production_output_posting', 'PWH'), 'output_record_id' => $output->id,
                'quality_inspection_id' => $inspectionId, 'warehouse_id' => $payload['warehouse_id'], 'location_id' => $payload['location_id'],
                'batch_no' => $payload['batch_no'], 'posted_base_qty' => $quantity, 'status' => 'POSTED',
                'inventory_transaction_id' => $transaction->id, 'posted_by_legacy_id' => $this->userId($user), 'posted_at' => now(),
                'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $remainingAfter = $terminal ? (float) max(0, $remainingBefore - $quantity) : 0.0;
            $outputStatus = $remainingAfter <= 0.00000001 ? 'WAREHOUSED' : 'WAIT_WAREHOUSE';
            $output->update(['status' => $outputStatus, 'business_version' => (int) $output->business_version + 1]);
            if (! $terminal) {
                $this->syncSourceTarget($output, 'COMPLETED');
                $this->ensureNextTask($output, false, $this->userId($user));
            }
            $issueId = $terminal ? null : $this->createInternalIssue($output, $payload, $transaction);
            if ($terminal) DB::table('erp_work_order_finished_goods_receipts')->where('id', $receiptId)->update([
                'output_warehouse_posting_id' => $postingId, 'inventory_transaction_id' => $transaction->id,
                'status' => 'POSTED', 'updated_at' => now(),
            ]);
            $salesReservationId = $terminal
                ? $this->reserveTerminalOutputForOriginSalesOrder($output, $payload, $quantity, $receiptId)
                : null;
            if ($salesReservationId) DB::table('erp_production_output_warehouse_postings')->where('id', $postingId)
                ->update(['sales_order_reservation_id' => $salesReservationId, 'updated_at' => now()]);
            return ['posting_id' => $postingId, 'inventory_transaction_id' => (int) $transaction->id,
                'output_status' => $outputStatus, 'output_business_version' => (int) $output->business_version,
                'internal_issue_task_id' => $issueId, 'sales_order_reservation_id' => $salesReservationId,
                'finished_goods_receipt_id' => $receiptId, 'finished_goods_receipt_no' => $receiptNo,
                'posted_base_qty' => $quantity, 'remaining_receivable_base_qty' => $remainingAfter];
        });
    }

    private function reserveTerminalOutputForOriginSalesOrder(ProductionOutputRecord $output, array $payload, float $quantity, int $receiptId): ?int
    {
        $workOrder = WorkOrder::query()->with('demand.line')->lockForUpdate()->find($output->work_order_id);
        if (! $workOrder || $workOrder->source_type !== 'sales_order' || ! $workOrder->demand?->line) return null;
        if ((int) $output->output_item_id !== (int) $workOrder->output_item_id || ! $this->isTerminalOutput($output)) return null;

        $line = SalesOrderLine::query()->lockForUpdate()->find($workOrder->demand->sales_order_line_id);
        if (! $line) $this->fail('sales_order_line_missing', '销售来源工单无法定位原订单行，禁止丢失回补归属。', 409);
        $fulfillment = SalesOrderFulfillment::query()
            ->where('sales_order_id', $workOrder->demand->sales_order_id)
            ->where('sales_order_line_id', $line->id)
            ->where('fulfillment_type', 'production')
            ->where('demand_status', 'confirmed')
            ->lockForUpdate()->orderByDesc('id')->first();
        if (! $fulfillment) $this->fail('sales_production_fulfillment_missing', '销售来源工单缺少生产履约记录，禁止生成无归属库存。', 409);
        $balance = InventoryBalance::query()
            ->where('item_id', $output->output_item_id)
            ->where('warehouse_id', $payload['warehouse_id'])
            ->where('location_id', $payload['location_id'])
            ->where('batch_no', $payload['batch_no'])
            ->lockForUpdate()->first();
        if (! $balance) $this->fail('production_output_balance_missing', '生产入库后未找到对应库存余额，无法锁回来源订单。', 409);

        $factor = (float) ($line->fulfillment_factor_snapshot ?: 0);
        if ($factor <= 0) $this->fail('fulfillment_factor_missing', '来源订单行缺少有效履约换算因子。', 409);
        $salesQty = round($quantity / $factor, 8);
        $remainingReplenishment = max(0, (float) $line->production_required_qty - (float) $line->production_replenished_qty);
        if ($salesQty > $remainingReplenishment + 0.00000001) {
            $this->fail('production_replenishment_exceeds_gap', '本次生产回补数量超过来源订单剩余生产缺口。', 409);
        }
        $reservation = $this->reservations->reserveProductionReplenishment(
            (int) $workOrder->demand->sales_order_id,
            (int) $line->id,
            (int) $fulfillment->id,
            (int) $balance->id,
            $quantity,
            (int) $output->id,
            $output->inventory_serial_id ? (int) $output->inventory_serial_id : null,
            $receiptId,
        );
        $line->production_replenished_qty = round((float) $line->production_replenished_qty + $salesQty, 8);
        $line->save();
        return (int) $reservation->id;
    }

    private function isTerminalOutput(ProductionOutputRecord $output): bool
    {
        $source = $this->sourceTarget($output);
        if (! $source) return false;
        $table = $output->source_target_type === 'unit_operation'
            ? 'erp_production_unit_operations'
            : 'erp_production_quantity_operations';
        $query = DB::table($table)->where('sequence_no_snapshot', '>', $source->sequence_no_snapshot);
        $output->source_target_type === 'unit_operation'
            ? $query->where('production_unit_id', $source->production_unit_id)
            : $query->where('work_order_id', $source->work_order_id);
        return ! $query->exists();
    }

    private function createInternalIssue(ProductionOutputRecord $output, array $payload, object $transaction): ?int
    {
        if (! in_array($output->output_mode_snapshot, ['warehouse_required', 'warehouse_optional'], true)) return null;
        $source = $this->sourceTarget($output); if (! $source) return null;
        $model = $output->source_target_type === 'unit_operation' ? \App\Models\Erp\ProductionUnitOperation::class : \App\Models\Erp\ProductionQuantityOperation::class;
        $query = $model::query()->where('sequence_no_snapshot', '>', $source->sequence_no_snapshot);
        $output->source_target_type === 'unit_operation' ? $query->where('production_unit_id', $source->production_unit_id) : $query->where('work_order_id', $source->work_order_id);
        $next = $query->orderBy('sequence_no_snapshot')->first(); if (! $next) return null;
        $link = DB::table('erp_production_task_targets')->where('target_type', $output->source_target_type)->where('target_id', $next->id)->first();
        if (! $link) return null;
        $balance = DB::table('erp_inventory_balances')->where('item_id', $output->output_item_id)->where('warehouse_id', $payload['warehouse_id'])
            ->where('location_id', $payload['location_id'])->where('batch_no', $payload['batch_no'])->first();
        $issueId = DB::table('erp_production_internal_issue_tasks')->insertGetId(['issue_no' => $this->numbers->next('production_internal_issue', 'PII'),
            'work_order_id' => $output->work_order_id, 'target_task_id' => $link->task_id, 'target_type' => $output->source_target_type,
            'target_id' => $next->id, 'source_type' => $output->production_unit_id ? 'continuation_reserved' : 'common_inventory',
            'status' => 'WAIT_ISSUE', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $targetRequirementId = $this->uniqueTargetMaterialRequirement($output->source_target_type, (int) $next->id, (int) $output->output_item_id);
        DB::table('erp_production_internal_issue_lines')->insert(['issue_task_id' => $issueId, 'output_record_id' => $output->id,
            'target_material_requirement_id' => $targetRequirementId,
            'item_id' => $output->output_item_id, 'inventory_balance_id' => $balance->id, 'warehouse_id' => $payload['warehouse_id'],
            'location_id' => $payload['location_id'], 'batch_no' => $payload['batch_no'], 'serial_id' => $output->inventory_serial_id,
            'serial_no_snapshot' => $output->serial_no_snapshot, 'issue_base_qty' => $output->output_base_qty, 'created_at' => now(), 'updated_at' => now()]);
        return $issueId;
    }

    private function uniqueTargetMaterialRequirement(string $targetType, int $targetId, int $itemId): ?int
    {
        $ids = DB::table('erp_production_target_material_requirements')
            ->where('target_type', $targetType)->where('target_id', $targetId)->where('component_item_id', $itemId)
            ->whereRaw('GREATEST(0, satisfied_base_qty - returned_base_qty) < required_base_qty')->pluck('id');
        if ($ids->count() > 1) $this->fail('target_material_requirement_ambiguous', '下一工序存在多条相同物料需求，无法确定半成品领用对应项。', 409);
        return $ids->isEmpty() ? null : (int) $ids->first();
    }

    private function ensureNextTask(ProductionOutputRecord $output, bool $handover, int $userId): void
    {
        $source = $this->sourceTarget($output); if (! $source) return;
        $model = $output->source_target_type === 'unit_operation' ? \App\Models\Erp\ProductionUnitOperation::class : \App\Models\Erp\ProductionQuantityOperation::class;
        $query = $model::query()->where('sequence_no_snapshot', '>', $source->sequence_no_snapshot);
        $output->source_target_type === 'unit_operation' ? $query->where('production_unit_id', $source->production_unit_id) : $query->where('work_order_id', $source->work_order_id);
        $next = $query->orderBy('sequence_no_snapshot')->lockForUpdate()->first();
        if (! $next) return;
        $existing = DB::table('erp_production_task_targets')->where('target_type', $output->source_target_type)->where('target_id', $next->id)->exists();
        if (! $existing) {
            $next->status = 'WAIT_CLAIM'; $next->business_version = (int) $next->business_version + 1; $next->save();
            $mode = $output->source_target_type === 'unit_operation' ? 'unit' : 'quantity';
            $task = \App\Models\Erp\ProductionTask::query()->where('work_order_id', $output->work_order_id)->where('execution_mode', $mode)
                ->where('routing_operation_id_snapshot', $next->routing_operation_id_snapshot)->where('status', 'WAIT_CLAIM')->whereNull('assignee_user_legacy_id')->lockForUpdate()->first();
            if (! $task) $task = \App\Models\Erp\ProductionTask::create(['task_no' => $this->numbers->next('production_task', 'PT'),
                'work_order_id' => $output->work_order_id, 'execution_mode' => $mode, 'routing_operation_id_snapshot' => $next->routing_operation_id_snapshot,
                'operation_code_snapshot' => $next->operation_code_snapshot, 'operation_name_snapshot' => $next->operation_name_snapshot,
                'sequence_no_snapshot' => $next->sequence_no_snapshot, 'status' => 'WAIT_CLAIM', 'business_version' => 1]);
            $task->targets()->create(['target_type' => $output->source_target_type, 'target_id' => $next->id, 'status_snapshot' => 'WAIT_CLAIM']);
        }
        if ($handover) {
            $existingHandover = DB::table('erp_production_operation_handovers')
                ->where('output_record_id', $output->id)->lockForUpdate()->first();
            if (! $existingHandover) {
                $targetRequirementId = $this->uniqueTargetMaterialRequirement($output->source_target_type, (int) $next->id, (int) $output->output_item_id);
                DB::table('erp_production_operation_handovers')->insert(['handover_no' => $this->numbers->next('production_handover', 'PHO'),
                    'work_order_id' => $output->work_order_id, 'source_target_type' => $output->source_target_type, 'source_target_id' => $source->id,
                    'target_target_type' => $output->source_target_type, 'target_target_id' => $next->id,
                    'target_material_requirement_id' => $targetRequirementId, 'output_record_id' => $output->id,
                    'status' => 'WAIT_RECEIVE', 'handed_over_by_legacy_id' => $userId, 'handed_over_at' => now(),
                    'identity_snapshot' => json_encode(['output_no' => $output->output_no, 'serial_no' => $output->serial_no_snapshot], JSON_UNESCAPED_UNICODE),
                    'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($existingHandover->status === 'REJECTED') {
                // The output identity is unique across a rework cycle. Reopen the same
                // handover after the upstream task produces it again; the rejection
                // command and execution event retain the prior reason as immutable audit.
                DB::table('erp_production_operation_handovers')->where('id', $existingHandover->id)->update([
                    'status' => 'WAIT_RECEIVE', 'reject_reason' => null,
                    'expected_receiver_legacy_id' => null, 'received_by_legacy_id' => null, 'received_at' => null,
                    'accepted_base_qty' => null, 'completeness_snapshot' => null,
                    'handed_over_by_legacy_id' => $userId, 'handed_over_at' => now(),
                    'business_version' => (int) $existingHandover->business_version + 1, 'updated_at' => now(),
                ]);
            }
        }
    }

    private function syncSourceTarget(ProductionOutputRecord $output, string $status): void
    {
        $target = $this->sourceTarget($output, true);
        if (! $target) return;
        $target->status = $status; $target->business_version = (int) $target->business_version + 1; $target->save();
        $link = DB::table('erp_production_task_targets')->where('target_type', $output->source_target_type)->where('target_id', $target->id)->first();
        if (! $link) return;
        DB::table('erp_production_task_targets')->where('id', $link->id)->update(['status_snapshot' => $status, 'updated_at' => now()]);
        $states = DB::table('erp_production_task_targets')->where('task_id', $link->task_id)->pluck('status_snapshot');
        $taskStatus = $states->contains('WAIT_QUALITY') ? 'WAIT_QUALITY' : ($states->contains('WAIT_WAREHOUSE') ? 'WAIT_WAREHOUSE'
            : ($states->contains('REWORK') ? 'REWORK'
            : ($states->every(fn ($state) => in_array($state, ['COMPLETED', 'CANCELLED'], true)) ? 'COMPLETED' : null)));
        if ($taskStatus) DB::table('erp_production_tasks')->where('id', $link->task_id)->update(['status' => $taskStatus,
            'business_version' => DB::raw('business_version + 1'), 'updated_at' => now()]);
    }

    private function reopenSourceTargetForRework(ProductionOutputRecord $output, object $user): void
    {
        $target = $this->sourceTarget($output, true);
        if (! $target) return;
        $beforeStatus = (string) $target->status;
        $beforeVersion = (int) $target->business_version;
        if ($output->source_target_type === 'quantity_operation') {
            $target->completed_base_qty = max(0, (float) $target->completed_base_qty - (float) $output->output_base_qty);
            $target->remaining_base_qty = (float) $target->remaining_base_qty + (float) $output->output_base_qty;
        }
        $target->status = 'REWORK';
        $target->completed_at = null;
        $target->business_version = (int) $target->business_version + 1;
        $target->save();
        DB::table('erp_production_execution_events')->insert([
            'aggregate_type' => $output->source_target_type,
            'aggregate_id' => $target->id,
            'action' => 'quality_rework',
            'before_status' => $beforeStatus,
            'after_status' => 'REWORK',
            'before_version' => $beforeVersion,
            'after_version' => (int) $target->business_version,
            'fact_snapshot' => json_encode(['output_record_id' => (int) $output->id,
                'rework_base_qty' => (float) $output->output_base_qty], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'operator_legacy_id' => $this->userId($user),
            'operator_name' => $user->nickname ?? $user->username ?? null,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $link = DB::table('erp_production_task_targets')->where('target_type', $output->source_target_type)
            ->where('target_id', $target->id)->first();
        if (! $link) return;
        DB::table('erp_production_task_targets')->where('id', $link->id)->update(['status_snapshot' => 'REWORK', 'updated_at' => now()]);
        DB::table('erp_production_tasks')->where('id', $link->task_id)->update([
            'status' => 'REWORK', 'business_version' => DB::raw('business_version + 1'), 'updated_at' => now(),
        ]);
    }
    private function sourceTarget(ProductionOutputRecord $output, bool $lock = false): ?object
    { $model = $output->source_target_type === 'unit_operation' ? \App\Models\Erp\ProductionUnitOperation::class : ($output->source_target_type === 'quantity_operation' ? \App\Models\Erp\ProductionQuantityOperation::class : null); if (! $model) return null; $query = $model::query(); if ($lock) $query->lockForUpdate(); return $query->find($output->source_target_id); }
    private function command(string $type, int $id, array $payload, object $user, callable $action): array
    {
        $commandId = trim((string) ($payload['client_command_id'] ?? '')); $hashPayload = $payload; ksort($hashPayload);
        $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return DB::transaction(function () use ($type, $id, $payload, $user, $action, $commandId, $hash): array {
            $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
            if ($existing) { if ($existing->command_type !== $type || $existing->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409); return $existing->response_snapshot; }
            $ledger = ProductionExecutionCommand::create(['client_command_id' => $commandId, 'command_type' => $type,
                'aggregate_type' => 'production_output', 'aggregate_id' => $id, 'request_hash' => $hash, 'status' => 'processing',
                'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now()]);
            $result = $action(); $ledger->update(['result_type' => 'production_output', 'result_id' => $id,
                'response_snapshot' => $result, 'status' => 'succeeded', 'processing_finished_at' => now()]); return $result;
        }, 5);
    }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
