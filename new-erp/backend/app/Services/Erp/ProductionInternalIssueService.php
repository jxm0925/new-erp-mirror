<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ProductionInternalIssueService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ProductionStockPrebuildService $stockPrebuild,
        private readonly ProductionTargetReadinessService $targetReadiness,
        private readonly CuttingInventoryReservationService $cuttingReservations,
        private readonly ProductionMaterialCostService $materialCosts,
    ) {}
    public function dispatch(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.output.issue');
        $this->cuttingReservations->authorizeInternalIssue($id, $user, $permissions, $super, false);
        return $this->change('dispatch_internal_issue', $id, $payload, $user, function ($issue, $task, $target, $user) use ($permissions, $super): array {
            $this->cuttingReservations->authorizeInternalIssue($id = (int) $issue->id, $user, $permissions, $super, false, true);
            if ($issue->status !== 'WAIT_ISSUE') $this->fail('issue_not_waiting', '内部领用任务不处于待发料状态。', 409);
            if (! $task->assignee_user_legacy_id) $this->fail('target_task_not_claimed', '下一工序尚未接单，不能交付半成品。', 409);
            DB::table('erp_production_internal_issue_tasks')->where('id', $id)->update([
                'status' => 'ISSUED', 'expected_receiver_legacy_id' => $task->assignee_user_legacy_id,
                'issued_by_legacy_id' => $this->userId($user), 'issued_at' => now(),
                'business_version' => (int) $issue->business_version + 1, 'updated_at' => now(),
            ]);
            return ['id' => $id, 'status' => 'ISSUED', 'business_version' => (int) $issue->business_version + 1];
        });
    }
    public function receive(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.output.receive');
        $this->cuttingReservations->authorizeInternalIssue($id, $user, $permissions, $super, true);
        return $this->change('receive_internal_issue', $id, $payload, $user, function ($issue, $task, $target, $user) use ($permissions, $super): array {
            $this->cuttingReservations->authorizeInternalIssue((int) $issue->id, $user, $permissions, $super, true, true);
            if ($issue->status !== 'ISSUED') $this->fail('issue_not_dispatched', '内部领用尚未由仓库交出。', 409);
            if ((int) $task->assignee_user_legacy_id !== $this->userId($user)) {
                $this->fail('expected_receiver_required', '只有下一工序当前负责人可以确认接收。', 403);
            }
            $lines = DB::table('erp_production_internal_issue_lines')->where('issue_task_id', $issue->id)->lockForUpdate()->get();
            $this->stockPrebuild->releaseForIssue($issue, $lines);
            $this->cuttingReservations->releaseForIssue($issue, $lines);
            $transaction = $this->inventory->postProductionInternalIssue($issue, $lines, $user);
            $this->cuttingReservations->recordReceivedHoldings($issue, $lines, $transaction, $user);
            $this->materialCosts->recordInternalIssue($issue, $lines, $transaction, $this->userId($user));
            foreach ($lines as $line) {
                if (! $line->target_material_requirement_id) continue;
                $requirement = DB::table('erp_production_target_material_requirements')->where('id', $line->target_material_requirement_id)->lockForUpdate()->first();
                if (! $requirement || $requirement->target_type !== $issue->target_type || (int) $requirement->target_id !== (int) $issue->target_id) {
                    $this->fail('internal_issue_material_requirement_invalid', '内部领用绑定的目标物料需求无效，禁止接收。', 409);
                }
                $satisfied = (float) $requirement->satisfied_base_qty + (float) $line->issue_base_qty;
                $netSatisfied = max(0, $satisfied - (float) $requirement->returned_base_qty);
                DB::table('erp_production_target_material_requirements')->where('id', $requirement->id)->update([
                    'satisfied_base_qty' => $satisfied,
                    'status' => $netSatisfied + 0.00000001 >= (float) $requirement->required_base_qty ? 'SATISFIED' : 'PARTIAL',
                    'business_version' => (int) $requirement->business_version + 1,
                    'updated_at' => now(),
                ]);
            }
            DB::table('erp_production_internal_issue_tasks')->where('id', $issue->id)->update([
                'status' => 'RECEIVED', 'received_by_legacy_id' => $this->userId($user), 'received_at' => now(),
                'inventory_transaction_id' => $transaction->id, 'business_version' => (int) $issue->business_version + 1, 'updated_at' => now(),
            ]);
            $readiness = $this->targetReadiness->refresh((string) $issue->target_type, $target, $task, now());
            return ['id' => (int) $issue->id, 'status' => 'RECEIVED', 'inventory_transaction_id' => (int) $transaction->id,
                'target_status' => $readiness['target_status'], 'target_business_version' => $readiness['target_business_version'],
                'business_version' => (int) $issue->business_version + 1];
        });
    }
    private function change(string $type, int $id, array $payload, object $user, callable $action): array
    {
        $commandId = trim((string) $payload['client_command_id']);
        $cutting = DB::table('erp_production_internal_issue_tasks')->where('id', $id)->value('source_type') === 'cutting_reserved';
        $identity = [$id, (int) $payload['expected_version']];
        if ($cutting) {
            if (array_diff(array_keys($payload), ['client_command_id', 'expected_version'])) {
                $this->fail('command_fields_invalid', '下料库存领用操作包含不允许的字段。');
            }
            // A new target owner may handle the issue, but must not borrow the previous owner's command.
            $identity[] = $this->userId($user);
        }
        $hash = hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE));
        try {
            return DB::transaction(function () use ($type, $id, $payload, $user, $action, $commandId, $hash, $cutting): array {
            // Serialize a cutting issue before the absent-command lookup. Otherwise two new command IDs
            // may wait on a ledger gap/insert lock before either can recheck the actual issue version.
            if ($cutting) DB::table('erp_production_internal_issue_tasks')->where('id', $id)->lockForUpdate()->first();
            $existingQuery = ProductionExecutionCommand::where('client_command_id', $commandId);
            if (! $cutting) $existingQuery->lockForUpdate();
            $existing = $existingQuery->first();
            if ($existing) {
                if ($existing->command_type !== $type || $existing->request_hash !== $hash) {
                    $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
                }
                if ($cutting && $existing->status !== 'succeeded') $this->fail('command_processing', '相同命令尚未完成。', 409);
                $response = $existing->response_snapshot;
                if ($cutting) ksort($response);
                return $response;
            }
            $ledger = ProductionExecutionCommand::create([
                'client_command_id' => $commandId, 'command_type' => $type,
                'aggregate_type' => 'production_internal_issue', 'aggregate_id' => $id, 'request_hash' => $hash,
                'status' => 'processing', 'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now(),
            ]);
            $issue = DB::table('erp_production_internal_issue_tasks')->where('id', $id)->lockForUpdate()->first();
            if (! $issue) $this->fail('internal_issue_not_found', '生产内部领用任务不存在。', 404);
            if ((int) $issue->business_version !== (int) $payload['expected_version']) {
                $this->fail('version_conflict', '内部领用任务版本已变化，请刷新后重试。', 409);
            }
            $task = ProductionTask::query()->lockForUpdate()->findOrFail($issue->target_task_id);
            $target = $this->target($issue->target_type, (int) $issue->target_id);
            $result = $action($issue, $task, $target, $user);
            if ($cutting) ksort($result);
            $ledger->update(['result_type' => 'production_internal_issue', 'result_id' => $id,
                'response_snapshot' => $result, 'status' => 'succeeded', 'processing_finished_at' => now()]);
            return $result;
            }, 5);
        } catch (QueryException $e) {
            // A same-key concurrent insert waits on the unique index, then recovers after rollback.
            // This replaces absent-key gap locking only for the cutting issue path.
            if (! $cutting || (int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            $existing = ProductionExecutionCommand::where('client_command_id', $commandId)->first();
            if (! $existing) throw $e;
            if ($existing->command_type !== $type || $existing->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
            if ($existing->status !== 'succeeded') $this->fail('command_processing', '相同命令尚未完成。', 409);
            $response = $existing->response_snapshot; ksort($response); return $response;
        }
    }
    private function target(string $type, int $id): object { $model = $type === 'unit_operation' ? ProductionUnitOperation::class : ProductionQuantityOperation::class; return $model::query()->lockForUpdate()->findOrFail($id); }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
