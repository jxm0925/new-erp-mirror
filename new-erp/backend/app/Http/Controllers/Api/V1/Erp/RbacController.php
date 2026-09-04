<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\RbacBootstrapService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RbacController extends Controller
{
    public function permissions(Request $request, RbacBootstrapService $rbac)
    {
        $this->authorizePermission($request, 'system.menu.view');
        $rbac->bootstrap();

        if ($request->boolean('hierarchy')) {
            return $this->permissionHierarchy($request);
        }

        $query = DB::table('erp_rbac_permissions')
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $keyword = trim((string) $request->input('keyword'));
                $q->where(function ($sub) use ($keyword) {
                    $sub->where('name', 'like', "%{$keyword}%")
                        ->orWhere('code', 'like', "%{$keyword}%")
                        ->orWhere('path', 'like', "%{$keyword}%")
                        ->orWhere('component', 'like', "%{$keyword}%");
                });
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->orderBy('sort')
            ->orderBy('id');

        // A complete tree is an explicit selector for role/menu maintenance.
        // All normal list requests remain paginated by default.
        if ($request->boolean('tree')) {
            return response()->json($query->get());
        }

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function savePermission(Request $request)
    {
        $this->authorizePermission($request, 'system.menu.save');
        $data = $request->validate([
            'id' => 'nullable|integer',
            'parent_id' => 'nullable|integer',
            'code' => 'required|string|max:120',
            'name' => 'required|string|max:120',
            'type' => 'required|in:menu,button,api',
            'path' => 'nullable|string|max:200',
            'component' => 'nullable|string|max:200',
            'icon' => 'nullable|string|max:80',
            'sort' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
            'remark' => 'nullable|string',
        ]);
        $id = $data['id'] ?? null;
        unset($data['id']);
        $data['enabled'] = (bool) ($data['enabled'] ?? true);
        $data['sort'] = (int) ($data['sort'] ?? 0);
        $data['updated_at'] = now();
        $id = DB::transaction(function () use ($id, $data): int {
            if ($id) {
                DB::table('erp_rbac_permissions')->where('id', $id)->update($data);
                return (int) $id;
            }
            $data['created_at'] = now();
            return (int) DB::table('erp_rbac_permissions')->insertGetId($data);
        });
        return response()->json(DB::table('erp_rbac_permissions')->find($id));
    }

    public function roles(Request $request, RbacBootstrapService $rbac)
    {
        $this->authorizePermission($request, 'system.role.view');
        $rbac->bootstrap();

        $memberCounts = DB::table('erp_rbac_user_roles')
            ->select('role_id', DB::raw('COUNT(*) as member_count'))
            ->groupBy('role_id');

        $query = DB::table('erp_rbac_roles as r')
            ->leftJoinSub($memberCounts, 'members', 'members.role_id', '=', 'r.id')
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $keyword = trim((string) $request->input('keyword'));
                $q->where(function ($sub) use ($keyword) {
                    $sub->where('r.name', 'like', "%{$keyword}%")
                        ->orWhere('r.code', 'like', "%{$keyword}%");
                });
            })
            ->orderBy('r.id')
            ->select('r.*', DB::raw('COALESCE(members.member_count, 0) as member_count'));

        $paginator = $query->paginate($this->perPage($request));
        $this->attachRolePermissions($paginator->getCollection());

        return $this->paginated($paginator);
    }

    public function saveRole(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer',
            'code' => 'required|string|max:80',
            'name' => 'required|string|max:120',
            'data_scope' => 'required|in:all,department,self',
            'enabled' => 'nullable|boolean',
            'remark' => 'nullable|string',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'integer',
        ]);
        $permissionIds = $data['permission_ids'] ?? [];
        $id = $data['id'] ?? null;
        $this->authorizePermission($request, $id ? 'system.role.save_permissions' : 'system.role.create');
        unset($data['id'], $data['permission_ids']);
        $data['enabled'] = (bool) ($data['enabled'] ?? true);
        $data['updated_at'] = now();
        $id = DB::transaction(function () use ($id, $data, $permissionIds): int {
            if ($id) {
                DB::table('erp_rbac_roles')->where('id', $id)->update($data);
            } else {
                $data['created_at'] = now();
                $id = DB::table('erp_rbac_roles')->insertGetId($data);
            }
            DB::table('erp_rbac_role_permissions')->where('role_id', $id)->delete();
            foreach (array_unique(array_map('intval', $permissionIds)) as $permissionId) {
                DB::table('erp_rbac_role_permissions')->insert(['role_id' => $id, 'permission_id' => $permissionId]);
            }
            return (int) $id;
        });
        return response()->json(['id' => $id, 'message' => '角色已保存']);
    }

    public function roleUsers(Request $request)
    {
        $this->authorizePermission($request, 'system.role.view');
        $roleId = (int) $request->input('role_id');
        abort_if(!$roleId, 422, '请选择角色');

        $query = DB::table('erp_rbac_user_roles as ur')
            ->join('erp_legacy_admin_users as u', 'ur.user_legacy_id', '=', 'u.legacy_id')
            ->where('ur.role_id', $roleId)
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $keyword = trim((string) $request->input('keyword'));
                $q->where(function ($sub) use ($keyword) {
                    $sub->where('u.nickname', 'like', "%{$keyword}%")
                        ->orWhere('u.username', 'like', "%{$keyword}%")
                        ->orWhere('u.mobile', 'like', "%{$keyword}%");
                });
            })
            ->orderBy('u.legacy_id')
            ->select(['u.legacy_id as id', 'u.nickname', 'u.username', 'u.status']);

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function saveRoleUsers(Request $request)
    {
        $this->authorizePermission($request, 'system.role.save_permissions');
        $data = $request->validate([
            'role_id' => 'required|integer',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer',
        ]);
        DB::transaction(function () use ($data): void {
            DB::table('erp_rbac_user_roles')->where('role_id', $data['role_id'])->delete();
            foreach (array_unique(array_map('intval', $data['user_ids'] ?? [])) as $userId) {
                DB::table('erp_rbac_user_roles')->insert(['role_id' => $data['role_id'], 'user_legacy_id' => $userId]);
            }
        });
        return response()->json(['message' => '角色用户已保存']);
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->input('per_page', 20)));
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $auth = app(AuthContextService::class);
        $user = $request->attributes->get('erp_user') ?: $auth->currentUser($request);
        abort_unless($user, 401, '请先登录 ERP。');
        abort_unless($auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true), 403, '当前用户没有系统管理权限。');
    }

    private function paginated(LengthAwarePaginator $paginator)
    {
        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    private function attachRolePermissions($roles): void
    {
        $roleIds = $roles->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (!$roleIds) return;

        $permissionMap = DB::table('erp_rbac_role_permissions')
            ->whereIn('role_id', $roleIds)
            ->orderBy('permission_id')
            ->get(['role_id', 'permission_id'])
            ->groupBy('role_id');

        $roles->each(function ($role) use ($permissionMap) {
            $role->permission_ids = $permissionMap->get($role->id, collect())
                ->pluck('permission_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        });
    }

    private function permissionHierarchy(Request $request)
    {
        $permissions = DB::table('erp_rbac_permissions')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $byParent = $permissions->groupBy(fn ($item) => (int) ($item->parent_id ?? 0));
        $build = function (int $parentId) use (&$build, $byParent): array {
            return $byParent->get($parentId, collect())->map(function ($item) use (&$build) {
                $node = (array) $item;
                $node['children'] = $build((int) $item->id);
                return $node;
            })->all();
        };

        $roots = $build(0);
        $keyword = mb_strtolower(trim((string) $request->input('keyword', '')));
        if ($keyword !== '') {
            $filter = function (array $node) use (&$filter, $keyword): ?array {
                $node['children'] = array_values(array_filter(array_map($filter, $node['children'] ?? [])));
                $haystack = mb_strtolower(implode(' ', [
                    $node['name'] ?? '',
                    $node['code'] ?? '',
                    $node['path'] ?? '',
                ]));
                return str_contains($haystack, $keyword) || $node['children'] ? $node : null;
            };
            $roots = array_values(array_filter(array_map($filter, $roots)));
        }

        $perPage = $this->perPage($request);
        $page = max(1, (int) $request->input('page', 1));
        $total = count($roots);
        $pageRoots = array_slice($roots, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'data' => $pageRoots,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'stats' => [
                'menu' => $permissions->where('type', 'menu')->count(),
                'button' => $permissions->where('type', 'button')->count(),
                'api' => $permissions->where('type', 'api')->count(),
                'disabled' => $permissions->where('enabled', false)->count(),
            ],
        ]);
    }
}
