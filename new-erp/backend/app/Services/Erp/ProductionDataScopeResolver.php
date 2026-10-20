<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

/**
 * Resolves production visibility only from roles that actually grant the
 * requested production permission. Sales ownership must never expand this
 * scope. An unresolved production role/permission combination is denied.
 */
final class ProductionDataScopeResolver
{
    public function __construct(private readonly AuthContextService $authContext) {}

    public function resolve(
        object $user,
        string $viewPermission,
        array $permissions,
        bool $superAdmin = false,
    ): array {
        $userId = (int) ($user->legacy_id ?? $user->id ?? 0);
        if ($superAdmin) {
            return ['mode' => 'all', 'user_ids' => [], 'can_manage_pool' => true];
        }
        if ($userId <= 0 || ! in_array($viewPermission, $permissions, true)) {
            return ['mode' => 'deny', 'user_ids' => [], 'can_manage_pool' => false];
        }

        $roleScopes = DB::table('erp_rbac_user_roles as ur')
            ->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
            ->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_legacy_id', $userId)
            ->where('r.enabled', true)
            ->where('p.enabled', true)
            ->where('p.code', $viewPermission)
            ->pluck('r.data_scope')
            ->all();
        if ($roleScopes === []) {
            return ['mode' => 'deny', 'user_ids' => [], 'can_manage_pool' => false];
        }

        $mode = in_array('all', $roleScopes, true)
            ? 'all'
            : (in_array('department', $roleScopes, true) ? 'department' : 'self');
        $userIds = $mode === 'department'
            ? $this->authContext->departmentUserIds($user)
            : [$userId];
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id): bool => $id > 0)));
        if ($mode !== 'all' && $userIds === []) {
            return ['mode' => 'deny', 'user_ids' => [], 'can_manage_pool' => false];
        }

        $managementPermissions = [
            'production.work_order.create',
            'production.work_order.edit',
            'production.work_order.publish',
            'production.work_order.cancel',
        ];

        return [
            'mode' => $mode,
            'user_ids' => $userIds,
            'can_manage_pool' => (bool) array_intersect($managementPermissions, $permissions),
        ];
    }
}
