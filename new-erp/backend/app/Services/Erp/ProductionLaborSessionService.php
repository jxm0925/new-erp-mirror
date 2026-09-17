<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\CuttingTask;
use App\Models\Erp\ProductionLaborSession;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use Illuminate\Support\Facades\DB;

class ProductionLaborSessionService
{
    public function __construct(private readonly ProductionOperationWorkModeService $workModes) {}

    public function start(ProductionTask $task, object $target, string $type, int $employeeId, string $role, float $weight, $now, array $switchContext = []): ProductionLaborSession
    {
        $this->lockEmployee($employeeId);
        $previous = ProductionLaborSession::query()
            ->where('employee_legacy_id', $employeeId)
            ->where('status', 'ACTIVE')
            ->lockForUpdate()
            ->first();
        if ($previous && $previous->target_type === $type && (int) $previous->target_id === (int) $target->id) {
            $this->fail('labor_session_active', '当前人员已经在该生产目标计时中。', 409);
        }
        if ($previous) {
            $this->assertSwitchConfirmed($previous, $switchContext);
            $this->endExistingAndRecalculate($previous, 'task_switched', $now);
        }

        return ProductionLaborSession::create([
            'execution_task_type' => 'PRODUCTION_TASK',
            'task_id' => $task->id,
            'cutting_task_id' => null,
            'target_type' => $type,
            'target_id' => $target->id,
            'employee_legacy_id' => $employeeId,
            'role' => $role,
            'status' => 'ACTIVE',
            'started_at' => $now,
            'previous_labor_session_id' => $previous?->id,
            'actual_labor_minutes' => 0,
            'responsibility_weight_snapshot' => $weight,
            'credited_labor_minutes' => 0,
        ]);
    }

    public function startCutting(CuttingTask $task, int $employeeId, string $role, float $weight, $now, array $switchContext = []): ProductionLaborSession
    {
        $this->lockEmployee($employeeId);
        $previous = ProductionLaborSession::query()
            ->where('employee_legacy_id', $employeeId)
            ->where('status', 'ACTIVE')
            ->lockForUpdate()
            ->first();
        if ($previous && $previous->execution_task_type === 'CUTTING_TASK'
            && (int) $previous->cutting_task_id === (int) $task->id) {
            $this->fail('labor_session_active', '当前人员已经在该下料任务计时中。', 409);
        }
        if ($previous) {
            $this->assertSwitchConfirmed($previous, $switchContext);
            $this->endExistingAndRecalculate($previous, 'task_switched', $now);
        }

        return ProductionLaborSession::create([
            'execution_task_type' => 'CUTTING_TASK',
            'task_id' => null,
            'cutting_task_id' => $task->id,
            'target_type' => 'cutting_task',
            'target_id' => $task->id,
            'employee_legacy_id' => $employeeId,
            'role' => $role,
            'status' => 'ACTIVE',
            'started_at' => $now,
            'previous_labor_session_id' => $previous?->id,
            'actual_labor_minutes' => 0,
            'responsibility_weight_snapshot' => $weight,
            'credited_labor_minutes' => 0,
        ]);
    }

    public function assertStartAllowed(string $type, int $targetId, int $employeeId, array $switchContext): void
    {
        $this->lockEmployee($employeeId);
        $previous = ProductionLaborSession::query()->where('employee_legacy_id', $employeeId)
            ->where('status', 'ACTIVE')->lockForUpdate()->first();
        if (! $previous) return;
        if ($previous->target_type === $type && (int) $previous->target_id === $targetId) {
            $this->fail('labor_session_active', '当前人员已经在该生产目标计时中。', 409);
        }
        $this->assertSwitchConfirmed($previous, $switchContext);
    }

    public function end(ProductionTask $task, object $target, string $type, int $employeeId, string $reason, $now, bool $required = true, bool $recalculate = true, bool $incrementTargetVersion = true): ?ProductionLaborSession
    {
        $this->lockEmployee($employeeId);
        $session = ProductionLaborSession::query()
            ->where('task_id', $task->id)
            ->where('target_type', $type)
            ->where('target_id', $target->id)
            ->where('employee_legacy_id', $employeeId)
            ->where('status', 'ACTIVE')
            ->lockForUpdate()
            ->first();
        if (! $session) {
            if ($required) $this->fail('labor_session_missing', '未找到当前人员的进行中加工计时。', 409);
            return null;
        }

        $target->actual_labor_minutes = (float) $target->actual_labor_minutes + $this->finish($session, $reason, $now);
        if ($recalculate) $this->workModes->recalculate($task, $target, $type, $now, $incrementTargetVersion);
        return $session;
    }

    public function endActiveForTask(ProductionTask $task, int $employeeId, string $reason, $now): ?ProductionLaborSession
    {
        $this->lockEmployee($employeeId);
        $session = ProductionLaborSession::query()
            ->where('task_id', $task->id)
            ->where('employee_legacy_id', $employeeId)
            ->where('status', 'ACTIVE')
            ->lockForUpdate()
            ->first();
        if (! $session) return null;

        $target = $this->target($session->target_type, (int) $session->target_id);
        $target->actual_labor_minutes = (float) $target->actual_labor_minutes + $this->finish($session, $reason, $now);
        $this->workModes->recalculate($task, $target, $session->target_type, $now);
        return $session;
    }

    public function endCutting(CuttingTask $task, int $employeeId, string $reason, $now, bool $required = true, bool $recalculate = true): ?ProductionLaborSession
    {
        $this->lockEmployee($employeeId);
        $session = ProductionLaborSession::query()
            ->where('execution_task_type', 'CUTTING_TASK')
            ->where('cutting_task_id', $task->id)
            ->where('employee_legacy_id', $employeeId)
            ->where('status', 'ACTIVE')
            ->lockForUpdate()
            ->first();
        if (! $session) {
            if ($required) $this->fail('labor_session_missing', '未找到当前人员的进行中下料计时。', 409);
            return null;
        }

        $minutes = $this->finish($session, $reason, $now);
        $task->actual_labor_minutes = (float) $task->actual_labor_minutes + $minutes;
        if ($recalculate) $this->recalculateCuttingTask($task, $now);
        return $session;
    }

    public function recalculateCuttingTask(CuttingTask $task, $now, bool $incrementVersion = true): void
    {
        if (! in_array($task->status, ['IN_PROGRESS', 'PAUSED'], true)) return;
        $active = ProductionLaborSession::query()
            ->where('execution_task_type', 'CUTTING_TASK')
            ->where('cutting_task_id', $task->id)
            ->where('status', 'ACTIVE')
            ->exists();
        $automatic = ($task->work_mode_snapshot ?: 'manual') === 'automatic';
        $task->status = $automatic || $active ? 'IN_PROGRESS' : 'PAUSED';
        $task->paused_at = $task->status === 'PAUSED' ? ($task->paused_at ?: $now) : null;
        if ($incrementVersion) $task->business_version = (int) $task->business_version + 1;
        $task->save();
    }

    private function endExistingAndRecalculate(ProductionLaborSession $session, string $reason, $now): void
    {
        if ($session->execution_task_type === 'CUTTING_TASK') {
            $task = CuttingTask::query()->lockForUpdate()->findOrFail($session->cutting_task_id);
            $minutes = $this->finish($session, $reason, $now);
            $task->actual_labor_minutes = (float) $task->actual_labor_minutes + $minutes;
            $this->recalculateCuttingTask($task, $now);
            return;
        }
        $task = ProductionTask::query()->lockForUpdate()->findOrFail($session->task_id);
        $target = $this->target($session->target_type, (int) $session->target_id);
        $target->actual_labor_minutes = (float) $target->actual_labor_minutes + $this->finish($session, $reason, $now);
        $this->workModes->recalculate($task, $target, $session->target_type, $now);
    }

    private function finish(ProductionLaborSession $session, string $reason, $now): float
    {
        $minutes = max(0, $session->started_at->diffInSeconds($now) / 60);
        $session->update([
            'status' => 'ENDED',
            'ended_at' => $now,
            'end_reason' => $reason,
            'actual_labor_minutes' => $minutes,
            'credited_labor_minutes' => 0,
        ]);
        return $minutes;
    }

    private function assertSwitchConfirmed(ProductionLaborSession $previous, array $context): void
    {
        if (($context['switch_active_labor'] ?? false) === true
            && (int) ($context['expected_active_labor_session_id'] ?? 0) === (int) $previous->id) return;

        if ($previous->execution_task_type === 'CUTTING_TASK') {
            $task = CuttingTask::query()->find($previous->cutting_task_id);
            $this->fail('labor_switch_confirmation_required', '当前人员正在另一下料任务计时，请确认切换后重试。', 409, [
                'active_labor_session_id' => (int) $previous->id,
                'current_task' => [
                    'execution_task_type' => 'CUTTING_TASK',
                    'id' => $task?->id ? (int) $task->id : null,
                    'task_no' => $task?->task_no,
                    'target_type' => 'cutting_task',
                    'target_id' => (int) $previous->target_id,
                    'started_at' => optional($previous->started_at)->toISOString(),
                ],
            ]);
        }
        $task = ProductionTask::query()->find($previous->task_id);
        $target = $this->targetWithoutLock($previous->target_type, (int) $previous->target_id);
        $unitId = $previous->target_type === 'unit_operation' ? (int) ($target?->production_unit_id ?? 0) : 0;
        $unitNo = $unitId > 0 ? DB::table('erp_production_units')->where('id', $unitId)->value('unit_no') : null;
        $this->fail('labor_switch_confirmation_required', '当前人员正在另一生产任务计时，请确认切换后重试。', 409, [
            'active_labor_session_id' => (int) $previous->id,
            'current_task' => [
                'execution_task_type' => 'PRODUCTION_TASK',
                'id' => $task?->id ? (int) $task->id : null,
                'task_no' => $task?->task_no,
                'target_type' => $previous->target_type,
                'target_id' => (int) $previous->target_id,
                'production_unit_id' => $unitId ?: null,
                'production_unit_no' => $unitNo,
                'operation_code' => $target?->operation_code_snapshot,
                'operation_name' => $target?->operation_name_snapshot,
                'started_at' => optional($previous->started_at)->toISOString(),
            ],
        ]);
    }

    private function target(string $type, int $id): object
    {
        $model = match ($type) {
            'unit_operation' => ProductionUnitOperation::class,
            'quantity_operation' => ProductionQuantityOperation::class,
            default => null,
        };
        if (! $model) $this->fail('labor_target_invalid', '工时会话关联的生产目标类型无效。', 409);
        return $model::query()->lockForUpdate()->findOrFail($id);
    }

    private function targetWithoutLock(string $type, int $id): ?object
    {
        $model = $type === 'unit_operation' ? ProductionUnitOperation::class
            : ($type === 'quantity_operation' ? ProductionQuantityOperation::class : null);
        return $model ? $model::query()->find($id) : null;
    }

    private function lockEmployee(int $employeeId): void
    {
        DB::table('erp_legacy_admin_users')->where('legacy_id', $employeeId)->lockForUpdate()->first();
    }

    private function fail(string $code, string $message, int $status, array $details = []): never
    {
        throw new WorkOrderDomainException($code, $message, $status, $details);
    }
}
