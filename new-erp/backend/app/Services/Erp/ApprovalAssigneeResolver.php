<?php

namespace App\Services\Erp;

use App\Models\Erp\ApprovalNodeAssignee;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalAssigneeResolver
{
    public function resolve(array $rule, ApprovalTask $task, array $context): Collection
    {
        $type = (string) ($rule['type'] ?? ''); $value = $rule['value'] ?? null;
        $ids = match ($type) {
            'user', 'specified_users' => array_map('intval', (array) $value),
            'role' => $this->usersByRole((string) $value),
            'department' => $this->usersByDepartment((int) $value),
            'department_manager' => $this->departmentManagers((int) $value),
            'department_principal', 'initiator_department_manager', 'initiator_manager' => $this->departmentManagers((int) $task->department_id),
            'business_record_owner', 'field_user' => array_map('intval', (array) data_get($context, (string) ($rule['field'] ?? $value))),
            'business_record_department_manager', 'field_department_manager' => $this->departmentManagers((int) data_get($context, (string) ($rule['field'] ?? $value))),
            'field_user_manager' => $this->managersForUsers(array_map('intval', (array) data_get($context, (string) ($rule['field'] ?? $value)))),
            'position' => $this->usersByPosition((string) $value),
            default => [],
        };
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) throw ValidationException::withMessages(['approver_rule' => '当前节点审批人规则没有解析出任何启用账号。']);
        $resolved = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->where('status', 'normal')
            ->get(['legacy_id', 'nickname', 'username'])->map(fn ($user) => [
                'user_id' => (int) $user->legacy_id, 'user_name' => $user->nickname ?: $user->username,
                'source_type' => $type, 'source_value' => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            ]);
        if ($resolved->isEmpty()) {
            throw ValidationException::withMessages(['approver_rule' => '当前节点处理人均不存在或已停用，流程不能启动。']);
        }
        return $resolved;
    }

    public function snapshot(ApprovalTask $task, ApprovalTaskNode $node, array $rule, array $context): Collection
    {
        $rows = $this->resolve($rule, $task, $context);
        foreach ($rows as $row) ApprovalNodeAssignee::query()->updateOrCreate([
            'approval_task_node_id' => $node->id, 'user_id' => $row['user_id'],
        ], [
            'approval_task_id' => $task->id, 'user_name' => $row['user_name'],
            'source_type' => $row['source_type'], 'source_value' => $row['source_value'],
            'status' => 'PENDING', 'assigned_at' => now(),
        ]);
        return ApprovalNodeAssignee::query()->where('approval_task_node_id', $node->id)->get();
    }

    private function usersByRole(string $role): array
    {
        return DB::table('erp_rbac_user_roles as ur')->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.code', $role)->where('r.enabled', true)->pluck('ur.user_legacy_id')->map(fn ($id) => (int) $id)->all();
    }
    private function usersByDepartment(int $department): array
    {
        return DB::table('erp_department_users')->where('department_legacy_id', $department)->pluck('user_legacy_id')->map(fn ($id) => (int) $id)->all();
    }
    private function departmentManagers(int $department): array
    {
        if (!$department) return [];
        return DB::table('erp_department_users')->where('department_legacy_id', $department)
            ->where(fn ($q) => $q->where('is_principal', true)->orWhere('is_owner', true))->pluck('user_legacy_id')->map(fn ($id) => (int) $id)->all();
    }
    private function managersForUsers(array $users): array
    {
        $departments = DB::table('erp_department_users')->whereIn('user_legacy_id', $users)->pluck('department_legacy_id')->all();
        return collect($departments)->flatMap(fn ($department) => $this->departmentManagers((int) $department))->unique()->values()->all();
    }
    private function usersByPosition(string $position): array
    {
        if ($position === '') return [];
        return DB::table('erp_legacy_admin_users')->where('status', 'normal')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(legacy_payload, '$.position')) = ?", [$position])
            ->pluck('legacy_id')->map(fn ($id) => (int) $id)->all();
    }
}
