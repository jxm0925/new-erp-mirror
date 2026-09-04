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
        $query = DB::table('erp_legacy_admin_users')->orderByDesc('sort')->orderBy('legacy_id');

        if (($filters['status'] ?? 'normal') !== 'all') {
            $query->where('status', $filters['status'] ?? 'normal');
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
        }
        if (!empty($filters['department_name'])) $query->where('department_names', 'like', '%' . $filters['department_name'] . '%');
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
            : ['legacy_id as id', 'username', 'nickname', 'status', 'department_names', 'mobile', 'email', 'is_sales'];
        if (!empty($filters['per_page']) || !empty($filters['page'])) {
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
            ->where('r.enabled', true)
            ->orderBy('r.id')
            ->get(['ur.user_legacy_id', 'r.code', 'r.name', 'r.data_scope'])
            ->groupBy('user_legacy_id');

        $scopeRank = ['self' => 1, 'department' => 2, 'all' => 3];

        return $users->map(function ($user) use ($rolesByUser, $scopeRank) {
            $roles = $rolesByUser->get($user->id, collect());
            $user->rbac_roles = $roles->map(static fn ($role): array => [
                'code' => (string) $role->code,
                'name' => (string) $role->name,
                'data_scope' => (string) $role->data_scope,
            ])->values()->all();
            $user->data_scope = $roles
                ->sortByDesc(static fn ($role): int => $scopeRank[$role->data_scope] ?? 0)
                ->pluck('data_scope')
                ->first() ?? 'self';

            return $user;
        });
    }
}
