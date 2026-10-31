<?php

namespace App\Services\Erp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthContextService
{
    public function currentLegacyId(Request $request): ?int
    {
        $bearer = $request->bearerToken();
        if ($bearer) {
            $hash = hash('sha256', $bearer);
            $row = DB::table('erp_auth_tokens')
                ->where('token_hash', $hash)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->first();
            if ($row) {
                $active = DB::table('erp_legacy_admin_users')
                    ->where('legacy_id', $row->user_legacy_id)
                    ->whereIn(DB::raw('LOWER(status)'), ['normal', 'active'])
                    ->exists();
                if (! $active) {
                    DB::table('erp_auth_tokens')->where('id', $row->id)->delete();
                    return null;
                }
                DB::table('erp_auth_tokens')->where('id', $row->id)->update(['last_used_at' => now()]);
                return (int) $row->user_legacy_id;
            }
        }

        $header = $request->header('X-Erp-User-Id');
        if ($header && ctype_digit((string) $header) && $this->isTrustedInternalHeader($request, (string) $header)) {
            return (int) $header;
        }

        $user = $request->user();
        if ($user) {
            foreach (['source_user_id', 'legacy_admin_id', 'id'] as $field) {
                $value = $user->{$field} ?? null;
                if ($value && is_numeric($value)) return (int) $value;
            }
        }

        return null;
    }

    private function isTrustedInternalHeader(Request $request, string $legacyId): bool
    {
        $secret = (string) config('app.erp_internal_auth_secret', env('ERP_INTERNAL_AUTH_SECRET', ''));
        if ($secret === '') return false;

        $timestamp = (string) $request->header('X-Erp-Internal-Timestamp', '');
        $signature = (string) $request->header('X-Erp-Internal-Signature', '');
        if ($timestamp === '' || $signature === '' || abs(time() - (int) $timestamp) > 300) return false;

        $expected = hash_hmac('sha256', $legacyId.'|'.$timestamp, $secret);
        return hash_equals($expected, $signature);
    }

    public function currentUser(Request $request): ?object
    {
        $legacyId = $this->currentLegacyId($request);
        if (! $legacyId) return null;
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->first();
        return $user && $this->isActiveUser($user) ? $user : null;
    }

    public function isActiveUser(object $user): bool
    {
        return in_array(strtolower(trim((string) ($user->status ?? ''))), ['normal', 'active'], true);
    }

    public function isSuperAdmin(object $user): bool
    {
        $groups = json_decode(($user->auth_group_names ?? null) ?: '[]', true) ?: [];
        if (($user->username ?? '') === 'admin' || in_array('Admin group', $groups, true)) return true;
        return DB::table('erp_rbac_user_roles as ur')
            ->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_legacy_id', $user->legacy_id)
            ->where('r.code', 'admin')
            ->where('r.enabled', true)
            ->exists();
    }

    public function isDepartmentPrincipal(object $user): bool
    {
        return DB::table('erp_department_users')
            ->where('user_legacy_id', $user->legacy_id)
            ->where('is_principal', true)
            ->exists();
    }

    public function dataScope(object $user): string
    {
        if ($this->isSuperAdmin($user)) return 'all';
        $roleScope = DB::table('erp_rbac_user_roles as ur')
            ->join('erp_rbac_roles as r', 'ur.role_id', '=', 'r.id')
            ->where('ur.user_legacy_id', $user->legacy_id)
            ->where('r.enabled', true)
            ->orderByRaw("FIELD(r.data_scope, 'all', 'department', 'self')")
            ->value('r.data_scope');
        if ($roleScope) return $roleScope;
        if ($this->isDepartmentPrincipal($user)) return 'department';
        return 'self';
    }

    public function departmentUserIds(object $user): array
    {
        $departmentIds = DB::table('erp_department_users')
            ->where('user_legacy_id', $user->legacy_id)
            ->pluck('department_legacy_id')
            ->all();
        if (!$departmentIds) return [(int) $user->legacy_id];

        return DB::table('erp_department_users')
            ->whereIn('department_legacy_id', $departmentIds)
            ->pluck('user_legacy_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function permissionCodes(object $user): array
    {
        if ($this->isSuperAdmin($user)) {
            return DB::table('erp_rbac_permissions')->where('enabled', true)->pluck('code')->all();
        }

        return DB::table('erp_rbac_user_roles as ur')
            ->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
            ->join('erp_rbac_role_permissions as rp', 'ur.role_id', '=', 'rp.role_id')
            ->join('erp_rbac_permissions as p', 'rp.permission_id', '=', 'p.id')
            ->where('ur.user_legacy_id', $user->legacy_id)
            ->where('r.enabled', true)
            ->where('p.enabled', true)
            ->pluck('p.code')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 左侧导航：完全来自菜单管理（名称、图标、排序、停用、层级都以库里为准），停用的菜单连同下级一起不下发。
     * granted：菜单本身或其下任一权限已授予。前端按页面路由的访问权限决定显示（与路由守卫同一规则，菜单能看到就一定能打开），
     * 路由没有声明权限的页面才看 granted，所以这里不按授权裁剪。
     *
     * @return array<int, array<string, mixed>>
     */
    public function menuTree(object $user): array
    {
        $rows = DB::table('erp_rbac_permissions')->where('enabled', true)
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'parent_id', 'code', 'name', 'type', 'path', 'icon']);
        $granted = array_flip($this->permissionCodes($user));
        $super = $this->isSuperAdmin($user);
        $children = [];
        foreach ($rows as $row) $children[(int) $row->parent_id][] = $row;

        $build = function (int $parentId) use (&$build, $children, $granted, $super): array {
            $nodes = [];
            foreach ($children[$parentId] ?? [] as $row) {
                $sub = $build((int) $row->id);
                if ($row->type !== 'menu') continue;
                $subMenus = array_values(array_filter($sub, fn ($node) => $node['is_menu']));
                $visible = $super || isset($granted[$row->code]) || array_filter($sub, fn ($node) => $node['granted']);
                $nodes[] = [
                    'is_menu' => true,
                    'granted' => (bool) $visible,
                    'code' => $row->code,
                    'name' => $row->name,
                    'path' => $row->path ?: null,
                    'icon' => $row->icon ?: null,
                    'children' => $subMenus,
                ];
            }
            // 按钮只用来判断上级菜单是否可见。
            foreach ($children[$parentId] ?? [] as $row) {
                if ($row->type === 'menu') continue;
                $sub = $build((int) $row->id);
                $nodes[] = ['is_menu' => false, 'granted' => isset($granted[$row->code]) || (bool) array_filter($sub, fn ($node) => $node['granted'])];
            }

            return $nodes;
        };

        $prune = function (array $nodes) use (&$prune): array {
            $out = [];
            foreach ($nodes as $node) {
                if (! $node['is_menu']) continue;
                $kids = $prune($node['children']);
                if (! $node['path'] && ! $kids) continue;
                $out[] = ['code' => $node['code'], 'name' => $node['name'], 'path' => $node['path'], 'icon' => $node['icon'], 'granted' => $node['granted'], 'children' => $kids];
            }

            return $out;
        };

        return $prune($build(0));
    }
}
