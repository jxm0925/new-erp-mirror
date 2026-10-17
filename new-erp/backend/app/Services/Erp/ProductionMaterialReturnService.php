<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\WorkOrderMaterialRequirement;
use Illuminate\Support\Facades\DB;

class ProductionMaterialReturnService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly InventoryService $inventory,
        private readonly ProductionMaterialCostService $materialCosts,
    ) {}

    public function create(array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.material_return.create');
        return $this->command('create_production_material_return', 'production_task', (int) $payload['task_id'], $payload, $user, function () use ($payload, $user): array {
            $task = ProductionTask::query()->with('targets')->lockForUpdate()->find($payload['task_id']);
            if (! $task || (int) $task->assignee_user_legacy_id !== $this->userId($user)) $this->fail('task_owner_required', '只有已接单的任务负责人可以发起签收后退料。', 403);
            if ((int) $task->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '任务版本已变化，请刷新后重试。', 409);
            if (! $task->targets->contains(fn ($row) => $row->target_type === $payload['target_type'] && (int) $row->target_id === (int) $payload['target_id'])) $this->fail('task_target_not_found', '任务中不存在该生产目标。', 404);
            $lines = collect($payload['lines']); if ($lines->isEmpty()) $this->fail('return_lines_required', '退料明细不能为空。');
            $id = DB::table('erp_production_material_returns')->insertGetId(['return_no' => $this->numbers->next('production_material_return', 'PMR'),
                'work_order_id' => $task->work_order_id, 'task_id' => $task->id, 'target_type' => $payload['target_type'], 'target_id' => $payload['target_id'],
                'return_type' => $payload['return_type'], 'status' => 'SUBMITTED', 'reason' => $payload['reason'],
                'requested_by_legacy_id' => $this->userId($user), 'requested_at' => now(), 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $line) {
                $requirement = WorkOrderMaterialRequirement::query()->lockForUpdate()->find((int) $line['material_requirement_id']);
                if (! $requirement || (int) $requirement->work_order_id !== (int) $task->work_order_id) $this->fail('material_requirement_invalid', '退料明细不属于当前工单。');
                $belongsToTarget = DB::table('erp_production_target_material_requirements')
                    ->where('target_type', $payload['target_type'])->where('target_id', $payload['target_id'])
                    ->where('material_requirement_id', $requirement->id)->exists();
                if (! $belongsToTarget) $this->fail('material_requirement_target_invalid', '退料明细不属于当前生产目标。');
                $qty = CuttingDecimal::value((string) $line['return_base_qty']);
                if (in_array(data_get($requirement->cutting_requirement_snapshot, 'quantity_calculation.unit_kind'), ['root', 'sheet'], true)) app(ProductionCuttingAllocationService::class)->whole($qty);
                $batchNo = (string) ($line['batch_no'] ?? '');
                $sourceReceived = (string) app(ProductionMaterialReceiptQueryService::class)->query()
                    ->where('pick_line.material_requirement_id', $requirement->id)
                    ->where('pick_line.production_target_type', $payload['target_type'])->where('pick_line.production_target_id', $payload['target_id'])
                    ->where('pick_line.warehouse_id', (int) $line['warehouse_id'])->where('pick_line.location_id', (int) $line['location_id'])
                    ->whereRaw('COALESCE(pick_line.batch_no, \'\') = ?', [$batchNo])->sum('receipt_line.accepted_qty');
                $already = (string) DB::table('erp_production_material_return_lines as return_line')
                    ->join('erp_production_material_returns as material_return', 'material_return.id', '=', 'return_line.return_id')
                    ->where('return_line.material_requirement_id', $requirement->id)
                    ->where('material_return.target_type', $payload['target_type'])->where('material_return.target_id', $payload['target_id'])
                    ->where('return_line.warehouse_id', (int) $line['warehouse_id'])->where('return_line.location_id', (int) $line['location_id'])
                    ->whereRaw('COALESCE(return_line.batch_no, \'\') = ?', [$batchNo])
                    ->whereIn('material_return.status', ['SUBMITTED', 'WAIT_QUALITY', 'COMPLETED', 'QUARANTINED'])->sum('return_line.return_base_qty');
                if (bccomp(bcadd($qty, $already, 8), $sourceReceived, 8) > 0) $this->fail('return_quantity_exceeds_received', '退料数量不能超过当前生产目标在该仓库、库位和批次已签收且尚未退回的数量。');
                DB::table('erp_production_material_return_lines')->insert(['return_id' => $id, 'material_requirement_id' => $requirement->id,
                    'component_item_id' => $requirement->component_item_id, 'warehouse_id' => $line['warehouse_id'], 'location_id' => $line['location_id'],
                    'batch_no' => $line['batch_no'] ?? null, 'serial_snapshot' => isset($line['serial_ids']) ? json_encode(['inventory_serial_ids' => $line['serial_ids']]) : null,
                    'return_base_qty' => $qty, 'quality_disposition' => $payload['return_type'] === 'quality_return' ? 'pending' : 'not_required',
                    'created_at' => now(), 'updated_at' => now()]);
            }
            return ['id' => $id, 'status' => 'SUBMITTED', 'return_type' => $payload['return_type'], 'business_version' => 1];
        });
    }

    public function receive(int $id, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.material_return.receive');
        return $this->command('receive_production_material_return', 'production_material_return', $id, $payload, $user, function () use ($id, $payload, $user): array {
            $return = DB::table('erp_production_material_returns')->where('id', $id)->lockForUpdate()->first();
            if (! $return) $this->fail('material_return_not_found', '生产退料单不存在。', 404);
            if ((int) $return->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '退料单版本已变化，请刷新后重试。', 409);
            if ($return->status !== 'SUBMITTED') $this->fail('material_return_already_received', '该生产退料已由仓库处理。', 409);
            $lines = DB::table('erp_production_material_return_lines')->where('return_id', $id)->lockForUpdate()->get();
            $costs = $this->materialCosts->prepareMaterialReturns($return, $lines);
            $transaction = $this->inventory->postProductionMaterialReturnReceipt(
                $return, $lines, $user, $return->return_type === 'quality_return', $costs
            );
            $this->materialCosts->finalizeMaterialReturns($lines, $transaction);
            $currentFormalReturns = [];
            foreach ($lines as $line) {
                $allocationService = app(ProductionCuttingAllocationService::class);
                $coverage = $allocationService->returnCoverage($allocationService->decode($line->serial_snapshot));
                $formalCoverages = $coverage['formal_coverages'] ?: [(int) $line->material_requirement_id => ['formal_coverage_qty' => $coverage['formal_coverage_qty'] ?? 0]];
                foreach ($formalCoverages as $key => $member) $currentFormalReturns[(int) $key] = bcadd($currentFormalReturns[(int) $key] ?? '0', (string) $member['formal_coverage_qty'], 8);
            }
            foreach ($lines as $line) {
                $requirement = WorkOrderMaterialRequirement::lockForUpdate()->findOrFail($line->material_requirement_id);
                $allocationService = app(ProductionCuttingAllocationService::class);
                $coverage = $allocationService->returnCoverage($allocationService->decode($line->serial_snapshot));
                $requirement->returned_qty = bcadd((string) $requirement->returned_qty, (string) $line->return_base_qty, 8);
                if ($allocationService->unitKind($requirement)) {
                    if ($coverage['formal_coverage_qty'] === null) $this->fail('cutting_return_allocation_missing', '整根或整张退料必须恢复原签收来源的冻结需求覆盖。');
                    // This header is still SUBMITTED, so formalRemaining does not include this receipt yet.
                    $requirement->remaining_qty = bcadd($allocationService->formalRemaining($requirement), $currentFormalReturns[(int) $requirement->id] ?? '0.00000000', 8);
                }
                $requirement->business_version++;
                $requirement->save();

                // A warehouse receipt (including a quality return entering quarantine)
                // means the material has physically left this production target. Record
                // that monotonic return fact once here; kitting derives net onsite stock
                // from accepted minus returned and command replay cannot double-increment it.
                $targetRequirement = DB::table('erp_production_target_material_requirements')
                    ->where('target_type', $return->target_type)
                    ->where('target_id', $return->target_id)
                    ->where('material_requirement_id', $line->material_requirement_id)
                    ->lockForUpdate()
                    ->first();
                if (! $targetRequirement) $this->fail('material_requirement_target_invalid', '退料明细不属于当前生产目标。');
                $targetCoverages = $coverage['target_coverages'] ?: [(int) $targetRequirement->id => ['material_requirement_id' => (int) $line->material_requirement_id, 'coverage_qty' => $coverage['coverage_qty'] ?? $line->return_base_qty]];
                foreach ($targetCoverages as $targetId => $member) {
                    $targetRow = DB::table('erp_production_target_material_requirements')->where('id', (int) $targetId)
                        ->where('target_type', $return->target_type)->where('target_id', $return->target_id)
                        ->where('material_requirement_id', (int) $member['material_requirement_id'])->where('component_item_id', $line->component_item_id)->lockForUpdate()->first();
                    if (! $targetRow) $this->fail('material_requirement_target_invalid', '组合退料的冻结成员不属于当前生产目标或原料。');
                    $returned = bcadd((string) $targetRow->returned_base_qty, (string) $member['coverage_qty'], 8);
                    $netSatisfied = bcsub((string) $targetRow->satisfied_base_qty, $returned, 8);
                    if (bccomp($netSatisfied, '0', 8) < 0) $netSatisfied = '0.00000000';
                    DB::table('erp_production_target_material_requirements')->where('id', $targetRow->id)->update([
                        'returned_base_qty' => $returned,
                        'status' => bccomp($netSatisfied, (string) $targetRow->required_base_qty, 8) >= 0 ? 'SATISFIED' : (bccomp($netSatisfied, '0', 8) > 0 ? 'PARTIALLY_SATISFIED' : 'OPEN'),
                        'business_version' => (int) $targetRow->business_version + 1, 'updated_at' => now(),
                    ]);
                }
            }
            // A combined stock movement belongs to its owner once; every covered BOM member
            // separately regains its frozen theoretical need when that stock is returned.
            $actualOwnerIds = $lines->pluck('material_requirement_id')->map(fn ($value) => (int) $value)->all();
            foreach ($currentFormalReturns as $requirementId => $coverageQuantity) {
                if (in_array($requirementId, $actualOwnerIds, true) || bccomp($coverageQuantity, '0', 8) <= 0) continue;
                $requirement = WorkOrderMaterialRequirement::lockForUpdate()->findOrFail($requirementId);
                if ((int) $requirement->work_order_id !== (int) $return->work_order_id) $this->fail('material_requirement_invalid', '组合退料成员不属于当前工单。');
                $requirement->remaining_qty = bcadd(app(ProductionCuttingAllocationService::class)->formalRemaining($requirement), $coverageQuantity, 8);
                $requirement->business_version++; $requirement->save();
            }
            $status = $return->return_type === 'quality_return' ? 'WAIT_QUALITY' : 'COMPLETED';
            DB::table('erp_production_material_returns')->where('id', $id)->update(['status' => $status,
                'warehouse_received_by_legacy_id' => $this->userId($user), 'warehouse_received_at' => now(),
                'inventory_transaction_id' => $transaction->id, 'business_version' => (int) $return->business_version + 1, 'updated_at' => now()]);
            return ['id' => $id, 'status' => $status, 'inventory_transaction_id' => (int) $transaction->id, 'business_version' => (int) $return->business_version + 1];
        });
    }

    public function quality(int $id, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.material_return.quality');
        return $this->command('inspect_production_material_return', 'production_material_return', $id, $payload, $user, function () use ($id, $payload, $user): array {
            $return = DB::table('erp_production_material_returns')->where('id', $id)->lockForUpdate()->first();
            if (! $return) $this->fail('material_return_not_found', '生产退料单不存在。', 404);
            if ((int) $return->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '退料单版本已变化，请刷新后重试。', 409);
            if ($return->return_type !== 'quality_return' || $return->status !== 'WAIT_QUALITY') $this->fail('quality_return_not_pending', '只有仓库已接收的质量退料可以检验。', 409);
            $passed = (bool) $payload['passed']; $lines = DB::table('erp_production_material_return_lines')->where('return_id', $id)->lockForUpdate()->get();
            $transaction = $passed ? $this->inventory->releaseProductionMaterialReturnQuarantine($return, $lines, $user) : null;
            $inspectionId = DB::table('erp_production_material_return_inspections')->insertGetId(['inspection_no' => $this->numbers->next('production_return_quality', 'PRQ'),
                'return_id' => $id, 'status' => 'COMPLETED', 'result' => $passed ? 'passed' : 'failed', 'reason' => $payload['reason'] ?? null,
                'inspector_legacy_id' => $this->userId($user), 'inspected_at' => now(), 'inventory_transaction_id' => $transaction?->id,
                'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('erp_production_material_return_lines')->where('return_id', $id)->update(['quality_disposition' => $passed ? 'usable' : 'quarantined', 'updated_at' => now()]);
            DB::table('erp_production_material_returns')->where('id', $id)->update(['status' => $passed ? 'COMPLETED' : 'QUARANTINED',
                'business_version' => (int) $return->business_version + 1, 'updated_at' => now()]);
            return ['id' => $id, 'inspection_id' => $inspectionId, 'status' => $passed ? 'COMPLETED' : 'QUARANTINED',
                'inventory_transaction_id' => $transaction?->id, 'business_version' => (int) $return->business_version + 1];
        });
    }

    private function command(string $type, string $aggregateType, int $aggregateId, array $payload, object $user, callable $action): array
    { $commandId = trim((string) ($payload['client_command_id'] ?? '')); $hashPayload = $payload; ksort($hashPayload); $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE)); return DB::transaction(function () use ($type, $aggregateType, $aggregateId, $user, $action, $commandId, $hash): array { $existing = ProductionExecutionCommand::where('client_command_id', $commandId)->lockForUpdate()->first(); if ($existing) { if ($existing->command_type !== $type || $existing->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409); return $existing->response_snapshot; } $ledger = ProductionExecutionCommand::create(['client_command_id' => $commandId, 'command_type' => $type, 'aggregate_type' => $aggregateType, 'aggregate_id' => $aggregateId, 'request_hash' => $hash, 'status' => 'processing', 'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now()]); $result = $action(); $ledger->update(['result_type' => $aggregateType, 'result_id' => $result['id'] ?? $aggregateId, 'response_snapshot' => $result, 'status' => 'succeeded', 'processing_finished_at' => now()]); return $result; }, 5); }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
