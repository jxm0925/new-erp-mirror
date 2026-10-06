<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * New-ERP-only administrator directory. It never opens another database
 * connection; accounts, departments and roles are maintained locally.
 */
class ErpUserDirectoryService
{
    public function users(array $filters = [])
    {
        $query = DB::table('erp_legacy_admin_users')->whereNull('deleted_at')->orderByDesc('sort')->orderBy('legacy_id');

        $warehouseScope = ($filters['scope'] ?? null) === 'warehouse';
        if ($warehouseScope) {
            $query->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active']);
        } elseif (($filters['status'] ?? 'normal') !== 'all') {
            $status = $filters['status'] ?? 'normal';
            $query->whereIn('status', $status === 'normal' ? ['normal', 'active'] : ['hidden', 'disabled']);
        }
        if (!empty($filters['department_id'])) {
            $query->whereExists(function ($members) use ($filters): void {
                $members->selectRaw('1')->from('erp_department_users as du')
                    ->whereColumn('du.user_legacy_id', 'erp_legacy_admin_users.legacy_id')
                    ->where('du.department_legacy_id', (int) $filters['department_id']);
            });
        }
        if (($filters['scope'] ?? null) === 'sales') $query->where('is_sales', true);
        if (($filters['scope'] ?? null) === 'production') {
            $query->whereExists(function ($productionUser): void {
                $productionUser->selectRaw('1')
                    ->from('erp_rbac_user_roles as ur')
                    ->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
                    ->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'r.id')
                    ->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')
                    ->whereColumn('ur.user_legacy_id', 'erp_legacy_admin_users.legacy_id')
                    ->where('r.enabled', true)
                    ->where('p.enabled', true)
                    ->where('p.code', 'production.work_order.view');
            });
            if (($filters['capability'] ?? null) === 'collaborate') {
                $query->whereExists(function ($collaborator): void {
                    $collaborator->selectRaw('1')
                        ->from('erp_rbac_user_roles as ur')
                        ->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
                        ->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'r.id')
                        ->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')
                        ->whereColumn('ur.user_legacy_id', 'erp_legacy_admin_users.legacy_id')
                        ->where('r.enabled', true)->where('p.enabled', true)
                        ->where('p.code', 'production.task.collaborate');
                });
            }
        }
        if (!empty($filters['department_name'])) $query->where('department_names', 'like', '%' . $filters['department_name'] . '%');
        if (!empty($filters['role_id'])) {
            $query->whereExists(function ($role) use ($filters): void {
                $role->selectRaw('1')->from('erp_rbac_user_roles as ur')
                    ->whereColumn('ur.user_legacy_id', 'erp_legacy_admin_users.legacy_id')
                    ->where('ur.role_id', (int) $filters['role_id']);
            });
        }
        if (!empty($filters['group_name'])) {
            $groupName = trim((string) $filters['group_name']);
            $query->whereExists(function ($role) use ($groupName): void {
                $role->selectRaw('1')
                    ->from('erp_rbac_user_roles as ur')
                    ->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
                    ->whereColumn('ur.user_legacy_id', 'erp_legacy_admin_users.legacy_id')
                    ->where('r.enabled', true)
                    ->where(function ($match) use ($groupName): void {
                        $match->where('r.name', $groupName)->orWhere('r.code', $groupName);
                    });
            });
        }
        if (!empty($filters['data_scope'])) {
            $dataScope = (string) $filters['data_scope'];
            $query->whereExists(function ($role) use ($dataScope): void {
                $role->selectRaw('1')
                    ->from('erp_rbac_user_roles as ur')
                    ->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
                    ->whereColumn('ur.user_legacy_id', 'erp_legacy_admin_users.legacy_id')
                    ->where('r.enabled', true)
                    ->where('r.data_scope', $dataScope);
            });
        }
        if (!empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($q) use ($keyword) {
                $q->where('nickname', 'like', "%{$keyword}%")
                    ->orWhere('username', 'like', "%{$keyword}%")
                    ->orWhere('mobile', 'like', "%{$keyword}%");
            });
        }

        $productionScope = ($filters['scope'] ?? 'system') === 'production';
        $columns = $productionScope
            ? ['legacy_id as user_id', 'nickname as display_name', 'department_names as department_name', 'status']
            : ['legacy_id as id', 'username', 'nickname', 'status', 'department_names', 'mobile', 'email', 'is_sales', 'sort', 'business_version', 'created_at', 'updated_at'];
        if ($warehouseScope) $columns = ['legacy_id as id', 'username', 'nickname', 'status', 'department_names'];
        if (($filters['scope'] ?? 'system') === 'system' || $warehouseScope || !empty($filters['per_page']) || !empty($filters['page'])) {
            $paginator = $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 20))), $columns);
            if (! $productionScope && ($filters['scope'] ?? 'system') === 'system') {
                $paginator->setCollection($this->attachRbacRoles($paginator->getCollection()));
            }

            return $paginator;
        }

        $users = $query->get($columns);

        return ! $productionScope && ($filters['scope'] ?? 'system') === 'system'
            ? $this->attachRbacRoles($users)
            : $users;
    }

    private function attachRbacRoles(Collection $users): Collection
    {
        $userIds = $users->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($userIds === []) {
            return $users;
        }

        $rolesByUser = DB::table('erp_rbac_user_roles as ur')
            ->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
            ->whereIn('ur.user_legacy_id', $userIds)
            ->orderBy('r.id')
            ->get(['ur.user_legacy_id', 'r.id', 'r.code', 'r.name', 'r.enabled', 'r.data_scope'])
            ->groupBy('user_legacy_id');
        $identities = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $userIds)
            ->get(['legacy_id', 'username', 'auth_group_names'])->keyBy('legacy_id');

        return $users->map(function ($user) use ($rolesByUser, $identities) {
            $roles = $rolesByUser->get($user->id, collect());
            $user->rbac_roles = $roles->map(static fn ($role): array => [
                'id' => (int) $role->id,
                'code' => (string) $role->code,
                'name' => (string) $role->name,
                'enabled' => (bool) $role->enabled,
                'data_scope' => (string) $role->data_scope,
            ])->values()->all();
            $identity = $identities->get($user->id);
            $auth = app(AuthContextService::class);
            $user->is_department_principal = $auth->isDepartmentPrincipal($identity);
            $user->is_super_admin = $auth->isSuperAdmin($identity);
            $user->data_scope = $auth->dataScope($identity);

            return $user;
        });
    }
}
