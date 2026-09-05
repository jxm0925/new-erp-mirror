<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionLaborSession;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ProductionExecutionActionService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ProductionLaborAllocationService $laborAllocation,
    ) {}

    public function start(int $taskId, string $type, int $targetId, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.task.start');
        return $this->mutate('start_target', $taskId, $type, $targetId, $payload, $user, function ($task, $target, int $userId): array {
            if ($target->kitting_required && (int) $task->assignee_user_legacy_id === $userId) {
                $this->fail('kitting_starts_processing', '该工序需要齐套，负责人点击“已齐套”时会直接开始加工，无需再次点击开始。', 409);
            }
            if (! in_array($target->status, ['READY', 'IN_PROGRESS'], true)) $this->fail('target_not_ready', '生产目标尚未完成接单、收料/交接和齐套，不能开始。', 409);
            $now = now();
            $target->fill(['status' => 'IN_PROGRESS', 'started_at' => $target->started_at ?: $now,
                'paused_at' => null, 'business_version' => (int) $target->business_version + 1])->save();
            $this->startSession($task, $target, $userId, $now);
            return $this->projection($task, $target);
        });
    }

    public function pause(int $taskId, string $type, int $targetId, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.task.pause');
        return $this->mutate('pause_target', $taskId, $type, $targetId, $payload, $user, function ($task, $target, int $userId): array {
            if ($target->status !== 'IN_PROGRESS') $this->fail('target_not_in_progress', '只有加工中的生产目标可以暂停。', 409);
            $now = now();
            $this->endSession($task, $target, $userId, $now);
            $otherActive = ProductionLaborSession::query()->where('target_type', $target instanceof ProductionUnitOperation ? 'unit_operation' : 'quantity_operation')
                ->where('target_id', $target->id)->where('status', 'ACTIVE')->exists();
            $target->fill(['status' => $otherActive ? 'IN_PROGRESS' : 'PAUSED', 'paused_at' => $otherActive ? null : $now,
                'business_version' => (int) $target->business_version + 1])->save();
            return $this->projection($task, $target);
        });
    }

    public function resume(int $taskId, string $type, int $targetId, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.task.resume');
        return $this->mutate('resume_target', $taskId, $type, $targetId, $payload, $user, function ($task, $target, int $userId): array {
            if (! in_array($target->status, ['PAUSED', 'IN_PROGRESS'], true)) $this->fail('target_not_paused', '只有已暂停或协同加工中的生产目标可以继续。', 409);
            $now = now();
            $target->fill(['status' => 'IN_PROGRESS', 'paused_at' => null,
                'business_version' => (int) $target->business_version + 1])->save();
            $this->startSession($task, $target, $userId, $now);
            return $this->projection($task, $target);
        });
    }

    public function complete(int $taskId, string $type, int $targetId, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.task.complete');
        return $this->mutate('complete_target', $taskId, $type, $targetId, $payload, $user, function ($task, $target, int $userId) use ($type, $payload): array {
            if ((int) $task->assignee_user_legacy_id !== $userId) $this->fail('task_owner_required', '只有任务负责人可以最终完成生产目标。', 403);
            if (! in_array($target->status, ['IN_PROGRESS', 'PAUSED'], true)) $this->fail('target_not_in_progress', '只有加工中或已暂停的生产目标可以完成。', 409);
            $now = now();
            if ($target->status === 'IN_PROGRESS') $this->endSession($task, $target, $userId, $now);
            if (ProductionLaborSession::query()->where('target_type', $type)->where('target_id', $target->id)->where('status', 'ACTIVE')->exists())
                $this->fail('collaborator_labor_active', '仍有协作者处于加工计时中，必须先结束全部协同计时。', 409);
            $this->laborAllocation->allocate($task, $type, (int) $target->id);
            if ($type === 'quantity_operation') $this->completeQuantity($target, $payload);

            $output = $this->createOutput($type, $target, $userId, $payload, $now);
            $warehouseChosen = $target->output_mode_snapshot === 'warehouse_required'
                || ($target->output_mode_snapshot === 'warehouse_optional' && ($payload['disposition'] ?? null) === 'warehouse');
            $nextStatus = $target->quality_mode_snapshot !== 'none' ? 'WAIT_QUALITY'
                : ($warehouseChosen ? 'WAIT_WAREHOUSE' : 'COMPLETED');
            $target->fill(['status' => $nextStatus, 'completed_at' => $now, 'paused_at' => null,
                'business_version' => (int) $target->business_version + 1])->save();
            $task->targets()->where('target_type', $type)->where('target_id', $target->id)->update(['status_snapshot' => $nextStatus]);
            $this->refreshTask($task);
            if ($nextStatus === 'COMPLETED') $this->advanceNext($type, $target, $task->work_order_id, $userId, $output);
            return $this->projection($task->fresh(), $target, $output);
        });
    }

    private function mutate(string $commandType, int $taskId, string $type, int $targetId, array $payload, object $user, callable $action): array
    {
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '') $this->fail('client_command_id_required', '写操作必须提供 client_command_id。');
        $hashPayload = $payload + ['task_id' => $taskId, 'target_type' => $type, 'target_id' => $targetId];
        ksort($hashPayload);
        $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        try {
            return DB::transaction(function () use ($commandId, $commandType, $taskId, $type, $targetId, $payload, $user, $action, $hash): array {
                $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
                if ($existing) return $this->replay($existing, $commandType, $hash);
                $ledger = ProductionExecutionCommand::create(['client_command_id' => $commandId, 'command_type' => $commandType,
                    'aggregate_type' => $type, 'aggregate_id' => $targetId, 'request_hash' => $hash, 'status' => 'processing',
                    'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now()]);
                [$task, $target] = $this->lockedTarget($taskId, $type, $targetId);
                $this->responsible($task, $user);
                if ((int) $target->business_version !== (int) ($payload['expected_version'] ?? 0)) {
                    $this->fail('version_conflict', '生产目标版本已变化，请刷新后重试。', 409, ['current_version' => (int) $target->business_version]);
                }
                $before = $target->status; $beforeVersion = (int) $target->business_version;
                $result = $action($task, $target, $this->userId($user));
                DB::table('erp_production_execution_events')->insert(['aggregate_type' => $type, 'aggregate_id' => $targetId,
                    'action' => $commandType, 'before_status' => $before, 'after_status' => $target->status,
                    'before_version' => $beforeVersion, 'after_version' => (int) $target->business_version,
                    'fact_snapshot' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'operator_legacy_id' => $this->userId($user), 'operator_name' => $user->nickname ?? $user->username ?? null,
                    'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $ledger->update(['result_type' => $type, 'result_id' => $targetId, 'response_snapshot' => $result,
                    'status' => 'succeeded', 'processing_finished_at' => now()]);
                return $result;
            }, 5);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->first();
            if ($existing) return $this->replay($existing, $commandType, $hash);
            throw $e;
        }
    }

    private function lockedTarget(int $taskId, string $type, int $targetId): array
    {
        $task = ProductionTask::query()->with('targets')->lockForUpdate()->find($taskId);
        if (! $task || ! $task->targets->contains(fn ($row) => $row->target_type === $type && (int) $row->target_id === $targetId))
            $this->fail('task_target_not_found', '任务中不存在该生产执行目标。', 404);
        $model = $type === 'unit_operation' ? ProductionUnitOperation::class : ($type === 'quantity_operation' ? ProductionQuantityOperation::class : null);
        if (! $model) $this->fail('task_target_invalid', '生产执行目标类型无效。');
        $target = $model::query()->lockForUpdate()->find($targetId);
        if (! $target) $this->fail('task_target_not_found', '生产执行目标不存在。', 404);
        return [$task, $target];
    }

    private function startSession(ProductionTask $task, object $target, int $userId, $now): void
    {
        if (ProductionLaborSession::query()->where('target_type', $target instanceof ProductionUnitOperation ? 'unit_operation' : 'quantity_operation')
            ->where('target_id', $target->id)->where('employee_legacy_id', $userId)->where('status', 'ACTIVE')->exists())
            $this->fail('labor_session_active', '当前人员已有进行中的加工计时。', 409);
        $collaborator = $task->collaborators()->where('employee_legacy_id', $userId)->whereNull('left_at')->first();
        $role = (int) $task->assignee_user_legacy_id === $userId ? 'owner' : 'collaborator';
        $weight = $role === 'owner' ? 1 : (float) ($collaborator?->responsibility_weight ?? 0);
        ProductionLaborSession::create(['task_id' => $task->id,
            'target_type' => $target instanceof ProductionUnitOperation ? 'unit_operation' : 'quantity_operation',
            'target_id' => $target->id, 'employee_legacy_id' => $userId, 'role' => $role, 'status' => 'ACTIVE',
            'started_at' => $now, 'actual_labor_minutes' => 0, 'responsibility_weight_snapshot' => $weight, 'credited_labor_minutes' => 0]);
    }

    private function endSession(ProductionTask $task, object $target, int $userId, $now): void
    {
        $type = $target instanceof ProductionUnitOperation ? 'unit_operation' : 'quantity_operation';
        $session = ProductionLaborSession::query()->where('task_id', $task->id)->where('target_type', $type)
            ->where('target_id', $target->id)->where('employee_legacy_id', $userId)->where('status', 'ACTIVE')->lockForUpdate()->first();
        if (! $session) $this->fail('labor_session_missing', '未找到当前人员的进行中加工计时。', 409);
        $minutes = max(0, $session->started_at->diffInSeconds($now) / 60);
        $session->update(['status' => 'ENDED', 'ended_at' => $now, 'actual_labor_minutes' => $minutes,
            'credited_labor_minutes' => 0]);
        $target->actual_labor_minutes = (float) $target->actual_labor_minutes + $minutes;
    }

    private function completeQuantity(ProductionQuantityOperation $target, array $payload): void
    {
        $completed = (float) ($payload['completed_base_qty'] ?? 0); $scrapped = (float) ($payload['scrapped_base_qty'] ?? 0);
        if ($completed < 0 || $scrapped < 0 || $completed + $scrapped <= 0) $this->fail('completion_quantity_invalid', '完成量与报废量必须为非负数且合计大于 0。');
        if ($completed + $scrapped > (float) $target->remaining_base_qty + 0.00000001) $this->fail('completion_quantity_exceeds_remaining', '完成量与报废量不能超过剩余数量。');
        $target->completed_base_qty = (float) $target->completed_base_qty + $completed;
        $target->scrapped_base_qty = (float) $target->scrapped_base_qty + $scrapped;
        $target->remaining_base_qty = max(0, (float) $target->remaining_base_qty - $completed - $scrapped);
        if ((float) $target->remaining_base_qty > 0.00000001) $this->fail('quantity_target_not_fully_reported', '本次完成后仍有剩余数量，请使用报工接口分批推进，不能直接结束工序。');
    }

    private function createOutput(string $type, object $target, int $userId, array $payload, $now): object
    {
        $qty = $type === 'unit_operation' ? 1 : (float) $target->completed_base_qty;
        $unitId = $type === 'unit_operation' ? (int) $target->production_unit_id : null;
        $existing = DB::table('erp_production_output_records')->where('source_target_type', $type)->where('source_target_id', $target->id)->first();
        if ($existing) return $existing;
        $id = DB::table('erp_production_output_records')->insertGetId([
                'output_no' => $this->numbers->next('production_output', 'POU'), 'work_order_id' => $target->work_order_id,
                'source_target_type' => $type, 'source_target_id' => $target->id, 'production_unit_id' => $unitId,
                'output_item_id' => $target->output_item_id_snapshot ?: DB::table('erp_work_orders')->where('id', $target->work_order_id)->value('output_item_id'),
                'output_base_qty' => $qty, 'output_mode_snapshot' => $target->output_mode_snapshot,
                'quality_mode_snapshot' => $target->quality_mode_snapshot, 'status' => $target->quality_mode_snapshot !== 'none' ? 'WAIT_QUALITY' : (($target->output_mode_snapshot === 'warehouse_required' || ($payload['disposition'] ?? null) === 'warehouse') ? 'WAIT_WAREHOUSE' : 'CREATED'),
                'disposition' => $payload['disposition'] ?? null, 'created_by_legacy_id' => $userId, 'produced_at' => $now,
                'business_version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        return DB::table('erp_production_output_records')->where('id', $id)->first();
    }

    private function advanceNext(string $type, object $target, int $workOrderId, int $userId, object $output): void
    {
        $query = $type === 'unit_operation'
            ? ProductionUnitOperation::query()->where('production_unit_id', $target->production_unit_id)
            : ProductionQuantityOperation::query()->where('work_order_id', $workOrderId);
        $next = $query->where('sequence_no_snapshot', '>', $target->sequence_no_snapshot)->orderBy('sequence_no_snapshot')->lockForUpdate()->first();
        if (! $next) return;
        $nextType = $type;
        $needsHandover = in_array($target->output_mode_snapshot, ['flow_only', 'warehouse_optional'], true);
        // The next target always enters the task pool first. Claiming it then derives
        // WAIT_HANDOVER/WAIT_MATERIAL; handover must never silently claim a task.
        $next->status = 'WAIT_CLAIM';
        $next->business_version = (int) $next->business_version + 1; $next->save();
        $mode = $type === 'unit_operation' ? 'unit' : 'quantity';
        $task = ProductionTask::query()->where('work_order_id', $workOrderId)->where('execution_mode', $mode)
            ->where('routing_operation_id_snapshot', $next->routing_operation_id_snapshot)->where('status', 'WAIT_CLAIM')
            ->whereNull('assignee_user_legacy_id')->lockForUpdate()->first();
        if (! $task) $task = ProductionTask::create(['task_no' => $this->numbers->next('production_task', 'PT'), 'work_order_id' => $workOrderId,
            'execution_mode' => $mode, 'routing_operation_id_snapshot' => $next->routing_operation_id_snapshot,
            'operation_code_snapshot' => $next->operation_code_snapshot, 'operation_name_snapshot' => $next->operation_name_snapshot,
            'sequence_no_snapshot' => $next->sequence_no_snapshot, 'status' => 'WAIT_CLAIM', 'business_version' => 1]);
        $task->targets()->create(['target_type' => $nextType, 'target_id' => $next->id, 'status_snapshot' => $next->status]);
        if ($needsHandover) {
            $requirementIds = DB::table('erp_production_target_material_requirements')
                ->where('target_type', $nextType)->where('target_id', $next->id)->where('component_item_id', $output->output_item_id)
                ->whereColumn('satisfied_base_qty', '<', 'required_base_qty')->pluck('id');
            if ($requirementIds->count() > 1) $this->fail('target_material_requirement_ambiguous', '下一工序存在多条相同物料需求，无法确定工序交接对应项。', 409);
            DB::table('erp_production_operation_handovers')->insert([
            'handover_no' => $this->numbers->next('production_handover', 'PHO'), 'work_order_id' => $workOrderId,
            'source_target_type' => $type, 'source_target_id' => $target->id, 'target_target_type' => $nextType,
            'target_target_id' => $next->id, 'target_material_requirement_id' => $requirementIds->isEmpty() ? null : (int) $requirementIds->first(),
            'output_record_id' => $output->id, 'status' => 'WAIT_RECEIVE',
            'handed_over_by_legacy_id' => $userId, 'handed_over_at' => now(), 'identity_snapshot' => json_encode(['output_no' => $output->output_no ?? null], JSON_UNESCAPED_UNICODE),
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function refreshTask(ProductionTask $task): void
    {
        $states = $task->targets->map(function ($link): ?string { $model = $link->target_type === 'unit_operation' ? ProductionUnitOperation::class : ProductionQuantityOperation::class; return $model::find($link->target_id)?->status; });
        $status = $states->contains('WAIT_QUALITY') ? 'WAIT_QUALITY'
            : ($states->contains('WAIT_WAREHOUSE') ? 'WAIT_WAREHOUSE'
                : ($states->every(fn ($state) => in_array($state, ['COMPLETED', 'CANCELLED'], true)) ? 'COMPLETED' : $task->status));
        if ($status !== $task->status) $task->update(['status' => $status, 'business_version' => (int) $task->business_version + 1]);
    }

    private function projection(ProductionTask $task, object $target, ?object $output = null): array
    {
        return ['task_id' => (int) $task->id, 'task_status' => $task->status, 'target_id' => (int) $target->id,
            'target_status' => $target->status, 'target_business_version' => (int) $target->business_version,
            'actual_labor_minutes' => (float) $target->actual_labor_minutes, 'started_at' => optional($target->started_at)->toISOString(),
            'paused_at' => optional($target->paused_at)->toISOString(), 'completed_at' => optional($target->completed_at)->toISOString(),
            'output_record_id' => $output?->id];
    }

    private function replay(ProductionExecutionCommand $command, string $type, string $hash): array
    {
        if ($command->command_type !== $type || $command->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
        if ($command->status !== 'succeeded' || ! is_array($command->response_snapshot)) $this->fail('command_processing', '相同命令正在处理中，请稍后重试。', 409);
        return $command->response_snapshot;
    }
    private function responsible(ProductionTask $task, object $user): void
    {
        $userId = $this->userId($user);
        if ((int) $task->assignee_user_legacy_id === $userId) return;
        if ($task->collaborators()->where('employee_legacy_id', $userId)->whereNull('left_at')->exists()) return;
        $this->fail('task_participant_required', '只有任务负责人或当前协作者可以推进该生产目标。', 403);
    }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403, ['permission' => $code]); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never { throw new WorkOrderDomainException($code, $message, $status, $details); }
}
