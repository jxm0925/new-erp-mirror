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
            if ($result === 'passed' && $qualified <= 0) $this->fail('inspection_result_invalid', '质检通过时合格数量必须大于 0。');
            $inspection = ProductionQualityInspection::create(['inspection_no' => $this->numbers->next('production_quality', 'PQA'),
                'output_record_id' => $output->id, 'status' => 'COMPLETED', 'result' => $result,
                'inspected_base_qty' => (float) $output->output_base_qty, 'qualified_base_qty' => $qualified,
                'unqualified_base_qty' => $unqualified, 'reason' => $payload['reason'] ?? null,
                'inspection_snapshot' => $payload['inspection_snapshot'] ?? null, 'inspector_legacy_id' => $this->userId($user),
                'inspected_at' => now(), 'business_version' => 1]);
            $direct = $result === 'passed' && (($payload['next_step'] ?? null) === 'direct_handover' || $output->output_mode_snapshot === 'flow_only');
            if ($direct && $output->output_mode_snapshot === 'warehouse_required') $this->fail('warehouse_required', '该工序产出必须正式入库，不能选择直接交接。');
            $output->update(['status' => $result === 'passed' ? ($direct ? 'HANDED_OVER' : 'WAIT_WAREHOUSE') : 'QUALITY_FAILED',
                'disposition' => $result, 'business_version' => (int) $output->business_version + 1]);
            $this->syncSourceTarget($output, $result === 'passed' ? ($direct ? 'COMPLETED' : 'WAIT_WAREHOUSE') : 'REWORK');
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
            $transaction = $this->inventory->postProductionOutputReceipt($output, $payload, $user);
            $inspectionId = ProductionQualityInspection::query()->where('output_record_id', $output->id)->value('id');
            $postingId = DB::table('erp_production_output_warehouse_postings')->insertGetId([
                'posting_no' => $this->numbers->next('production_output_posting', 'PWH'), 'output_record_id' => $output->id,
                'quality_inspection_id' => $inspectionId, 'warehouse_id' => $payload['warehouse_id'], 'location_id' => $payload['location_id'],
                'batch_no' => $payload['batch_no'], 'posted_base_qty' => $output->output_base_qty, 'status' => 'POSTED',
                'inventory_transaction_id' => $transaction->id, 'posted_by_legacy_id' => $this->userId($user), 'posted_at' => now(),
                'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $output->update(['status' => 'WAREHOUSED', 'business_version' => (int) $output->business_version + 1]);
            $this->syncSourceTarget($output, 'COMPLETED');
            $this->ensureNextTask($output, false, $this->userId($user));
            $issueId = $this->createInternalIssue($output, $payload, $transaction);
            $salesReservationId = $this->reserveTerminalOutputForOriginSalesOrder($output, $payload);
            if ($salesReservationId) DB::table('erp_production_output_warehouse_postings')->where('id', $postingId)
                ->update(['sales_order_reservation_id' => $salesReservationId, 'updated_at' => now()]);
            $this->completeWorkOrderIfTerminal($output);
            return ['posting_id' => $postingId, 'inventory_transaction_id' => (int) $transaction->id,
                'output_status' => 'WAREHOUSED', 'output_business_version' => (int) $output->business_version,
                'internal_issue_task_id' => $issueId, 'sales_order_reservation_id' => $salesReservationId];
        });
    }

    private function reserveTerminalOutputForOriginSalesOrder(ProductionOutputRecord $output, array $payload): ?int
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
        $salesQty = round((float) $output->output_base_qty / $factor, 8);
        $remainingReplenishment = max(0, (float) $line->production_required_qty - (float) $line->production_replenished_qty);
        if ($salesQty > $remainingReplenishment + 0.00000001) {
            $this->fail('production_replenishment_exceeds_gap', '本次生产回补数量超过来源订单剩余生产缺口。', 409);
        }
        $reservation = $this->reservations->reserveProductionReplenishment(
            (int) $workOrder->demand->sales_order_id,
            (int) $line->id,
            (int) $fulfillment->id,
            (int) $balance->id,
            (float) $output->output_base_qty,
            (int) $output->id,
            $output->inventory_serial_id ? (int) $output->inventory_serial_id : null,
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
            ->whereColumn('satisfied_base_qty', '<', 'required_base_qty')->pluck('id');
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
        if (! $next) { $this->completeWorkOrderIfTerminal($output); return; }
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
        if ($handover && ! DB::table('erp_production_operation_handovers')->where('output_record_id', $output->id)->exists()) {
            $targetRequirementId = $this->uniqueTargetMaterialRequirement($output->source_target_type, (int) $next->id, (int) $output->output_item_id);
            DB::table('erp_production_operation_handovers')->insert(['handover_no' => $this->numbers->next('production_handover', 'PHO'),
                'work_order_id' => $output->work_order_id, 'source_target_type' => $output->source_target_type, 'source_target_id' => $source->id,
                'target_target_type' => $output->source_target_type, 'target_target_id' => $next->id,
                'target_material_requirement_id' => $targetRequirementId, 'output_record_id' => $output->id,
                'status' => 'WAIT_RECEIVE', 'handed_over_by_legacy_id' => $userId, 'handed_over_at' => now(),
                'identity_snapshot' => json_encode(['output_no' => $output->output_no, 'serial_no' => $output->serial_no_snapshot], JSON_UNESCAPED_UNICODE),
                'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function completeWorkOrderIfTerminal(ProductionOutputRecord $output): void
    {
        $unitOpen = DB::table('erp_production_unit_operations')->where('work_order_id', $output->work_order_id)->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->exists();
        $quantityOpen = DB::table('erp_production_quantity_operations')->where('work_order_id', $output->work_order_id)->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->exists();
        if (! $unitOpen && ! $quantityOpen) DB::table('erp_work_orders')->where('id', $output->work_order_id)->update(['status' => 'COMPLETED', 'updated_at' => now()]);
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
            : ($states->every(fn ($state) => in_array($state, ['COMPLETED', 'CANCELLED'], true)) ? 'COMPLETED' : null));
        if ($taskStatus) DB::table('erp_production_tasks')->where('id', $link->task_id)->update(['status' => $taskStatus,
            'business_version' => DB::raw('business_version + 1'), 'updated_at' => now()]);
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
