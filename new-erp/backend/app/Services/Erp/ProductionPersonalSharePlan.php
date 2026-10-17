<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;

/** Execution clients receive personal allocation facts, never operation rates or amounts. */
final class ProductionPersonalSharePlan
{
    public function projection(array $scope, array $participants, ?array $assignment): array
    {
        $people = [];
        foreach ($participants as $person) {
            $id = (int) ($person['employee_legacy_id'] ?? 0);
            if ($id <= 0) continue;
            $people[$id] = ['employee_legacy_id' => $id, 'employee_name' => (string) ($person['employee_name'] ?? ''),
                'is_active' => (bool) ($person['is_active'] ?? false), 'has_labor' => (bool) ($person['has_labor'] ?? false),
                'identity_exists' => (bool) ($person['identity_exists'] ?? false)];
        }
        ksort($people);
        return [
            'scope_type' => $scope['scope_type'], 'scope_id' => (int) $scope['scope_id'],
            'scope_version' => (int) $scope['scope_version'], 'scope_name' => (string) ($scope['scope_name'] ?? ''),
            'owner_legacy_id' => (int) $scope['owner_legacy_id'], 'status' => (string) $scope['status'],
            'can_plan' => (bool) ($scope['can_plan'] ?? false), 'participants' => array_values($people),
            'share_plan' => $assignment ? ['version_no' => (int) $assignment['version_no'], 'status' => $assignment['status'],
                'shares' => array_map(fn ($share) => ['employee_legacy_id' => (int) $share['employee_legacy_id'],
                    'share_ratio' => (string) $share['share_ratio']], $assignment['shares'])] : null,
        ];
    }

    public function normalizeScopePlans(array $plans, ProductionPerformanceCalculator $calculator): array
    {
        $normalized = []; $seen = [];
        foreach ($plans as $plan) {
            $type = $plan['scope_type'] ?? null; $id = (int) ($plan['scope_id'] ?? 0);
            $key = $type.':'.$id;
            if (! in_array($type, ['unit_operation', 'quantity_operation'], true) || $id <= 0 || isset($seen[$key])) {
                throw new WorkOrderDomainException('performance_task_scope_invalid', '每个真实生产工序只能维护一组个人份额。');
            }
            $seen[$key] = true;
            if ((int) ($plan['expected_scope_version'] ?? 0) < 1 || (int) ($plan['expected_assignment_version'] ?? -1) < 0) {
                throw new WorkOrderDomainException('performance_scope_version_required', '请刷新实际工序及个人份额版本后再确认。');
            }
            $normalized[$key] = ['scope_type' => $type, 'scope_id' => $id,
                'expected_scope_version' => (int) $plan['expected_scope_version'],
                'expected_assignment_version' => (int) $plan['expected_assignment_version'],
                'shares' => $calculator->validateShares((array) ($plan['shares'] ?? []))['shares']];
        }
        ksort($normalized);
        return array_values($normalized);
    }

    public function assertScopeCoverage(array $plans, array $requiredKeys): void
    {
        $provided = array_map(fn ($plan) => $plan['scope_type'].':'.$plan['scope_id'], $plans);
        sort($provided); sort($requiredKeys);
        if ($provided !== $requiredKeys) throw new WorkOrderDomainException('performance_task_scope_invalid', '请分别确认本任务每个有效工序的实际人员和个人份额，不能套用其他工序。');
    }
}
