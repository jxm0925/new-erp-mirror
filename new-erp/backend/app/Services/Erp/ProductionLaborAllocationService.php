<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionLaborSession;
use App\Models\Erp\ProductionTask;
use Illuminate\Support\Facades\DB;

class ProductionLaborAllocationService
{
    public function allocate(ProductionTask $task, string $targetType, int $targetId): array
    {
        return DB::transaction(function () use ($task, $targetType, $targetId): array {
            $sessions = ProductionLaborSession::query()->where('task_id', $task->id)
                ->where('target_type', $targetType)->where('target_id', $targetId)
                ->lockForUpdate()->get();
            if ($sessions->isEmpty() || $sessions->contains(fn ($session) => $session->status !== 'ENDED')) {
                throw new WorkOrderDomainException('labor_sessions_incomplete', '全部人员的真实加工计时结束后才能计算责任工时。', 409);
            }
            $actualTotal = (float) $sessions->sum('actual_labor_minutes');
            if ($actualTotal <= 0.00000001) throw new WorkOrderDomainException('actual_labor_empty', '真实实际工时为空，不能计算责任工时。', 409);

            $rule = (array) $task->labor_allocation_rule_snapshot;
            $ownerRatio = (float) ($rule['owner_ratio'] ?? 0.6);
            $collaboratorRatio = (float) ($rule['collaborator_total_ratio'] ?? 0.4);
            $ownerId = (int) $task->assignee_user_legacy_id;
            $byEmployee = $sessions->groupBy('employee_legacy_id');
            $collaboratorActual = (float) $sessions->where('employee_legacy_id', '<>', $ownerId)->sum('actual_labor_minutes');
            if ($collaboratorActual <= 0.00000001) { $ownerRatio = 1.0; $collaboratorRatio = 0.0; }

            $allocations = [];
            foreach ($byEmployee as $employeeId => $employeeSessions) {
                $employeeActual = (float) $employeeSessions->sum('actual_labor_minutes');
                $isOwner = (int) $employeeId === $ownerId;
                $employeeCredit = $isOwner
                    ? $actualTotal * $ownerRatio
                    : ($collaboratorActual > 0 ? $actualTotal * $collaboratorRatio * $employeeActual / $collaboratorActual : 0);
                foreach ($employeeSessions as $session) {
                    $sessionCredit = $employeeActual > 0 ? $employeeCredit * (float) $session->actual_labor_minutes / $employeeActual : 0;
                    $session->update([
                        'responsibility_weight_snapshot' => $actualTotal > 0 ? $employeeCredit / $actualTotal : 0,
                        'credited_labor_minutes' => round($sessionCredit, 2),
                    ]);
                }
                $allocations[] = ['employee_legacy_id' => (int) $employeeId, 'role' => $isOwner ? 'owner' : 'collaborator',
                    'actual_labor_minutes' => round($employeeActual, 2), 'credited_labor_minutes' => round($employeeCredit, 2)];
            }
            return ['actual_labor_minutes' => round($actualTotal, 2),
                'credited_labor_minutes' => round((float) collect($allocations)->sum('credited_labor_minutes'), 2),
                'rule_snapshot' => $rule, 'allocations' => $allocations];
        });
    }
}
