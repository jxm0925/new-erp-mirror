<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\RbacBootstrapService;
use App\Services\Erp\RbacUserRoleOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

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
                abort_unless(Schema::hasColumn('erp_rbac_permissions', 'is_system'), 503, '权限编辑保护结构尚未部署，请先更新数据库结构。');
                $existing = DB::table('erp_rbac_permissions')->where('id', $id)->lockForUpdate()->first();
                abort_unless($existing, 404);
                if (($existing->is_system ?? false) && $existing->code !== $data['code']) {
                    throw ValidationException::withMessages(['code' => '系统权限节点的编码不能修改，否则会破坏已发布的按钮权限合同。']);
                }
                DB::table('erp_rbac_permissions')->where('id', $id)->update($data);
                return (int) $id;
            }
            if (Schema::hasColumn('erp_rbac_permissions', 'is_system')) $data['is_system'] = false;
            $data['created_at'] = now();
            return (int) DB::table('erp_rbac_permissions')->insertGetId($data);
        });
        return response()->json(DB::table('erp_rbac_permissions')->find($id));
    }

    public function deletePermission(Request $request, int $id, RbacBootstrapService $rbac)
    {
        $user = $this->authorizePermission($request, 'system.menu.delete');
        // 代码先部署而结构未升级时必须拒绝删除，不能把缺失的系统标记当成自定义。
        abort_unless(Schema::hasColumn('erp_rbac_permissions', 'is_system'), 503, '权限删除保护结构尚未部署，请先更新数据库结构。');
        $rbac->bootstrap();
        DB::transaction(function () use ($id, $user): void {
            $permission = DB::table('erp_rbac_permissions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($permission, 404);
            abort_if((bool) ($permission->is_system ?? false), 422, '系统内置权限节点不能删除，只能按业务需要停用。');
            abort_if((bool) $permission->enabled, 422, '权限节点必须先停用，确认不再使用后才能删除。');
            abort_if(DB::table('erp_rbac_permissions')->where('parent_id', $id)->exists(), 422, '该权限节点仍有子节点，请先处理子节点。');
            abort_if(DB::table('erp_rbac_role_permissions')->where('permission_id', $id)->exists(), 422, '该权限节点仍分配给角色，不能删除。');
            $this->auditDeletion('rbac_permission', $permission, $user);
            DB::table('erp_rbac_permissions')->where('id', $id)->delete();
        }, 5);
        return response()->json(['message' => '停用且未被引用的自定义权限节点已删除。']);
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
                abort_unless(Schema::hasColumn('erp_rbac_roles', 'is_system'), 503, '角色编辑保护结构尚未部署，请先更新数据库结构。');
                $existing = DB::table('erp_rbac_roles')->where('id', $id)->lockForUpdate()->first();
                abort_unless($existing, 404);
                if (($existing->is_system ?? false) && $existing->code !== $data['code']) {
                    throw ValidationException::withMessages(['code' => '系统内置角色编码不能修改。']);
                }
                DB::table('erp_rbac_roles')->where('id', $id)->update($data);
            } else {
                if (Schema::hasColumn('erp_rbac_roles', 'is_system')) $data['is_system'] = false;
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

    public function deleteRole(Request $request, int $id, RbacBootstrapService $rbac)
    {
        $user = $this->authorizePermission($request, 'system.role.delete');
        abort_unless(Schema::hasColumn('erp_rbac_roles', 'is_system'), 503, '角色删除保护结构尚未部署，请先更新数据库结构。');
        $rbac->bootstrap();
        DB::transaction(function () use ($id, $user): void {
            $role = DB::table('erp_rbac_roles')->where('id', $id)->lockForUpdate()->first();
            abort_unless($role, 404);
            abort_if((bool) ($role->is_system ?? false), 422, '系统内置角色不能删除，只能按业务需要停用。');
            abort_if((bool) $role->enabled, 422, '角色必须先停用，确认不再使用后才能删除。');
            abort_if(DB::table('erp_rbac_user_roles')->where('role_id', $id)->exists()
                || DB::table('erp_rbac_user_role_sources')->where('role_id', $id)->exists(), 422, '该角色仍关联用户或身份来源，不能删除。');
            abort_if($this->approvalFlowUsesRole((string) $role->code), 422, '该角色已被审核流程版本引用，不能删除。');

            DB::table('erp_rbac_role_permissions')->where('role_id', $id)->delete();
            $this->auditDeletion('rbac_role', $role, $user);
            DB::table('erp_rbac_roles')->where('id', $id)->delete();
        }, 5);
        return response()->json(['message' => '停用且未被引用的自定义角色已删除。']);
    }

    public function roleUsers(Request $request)
    {
        $this->authorizePermission($request, 'system.role.view');
        $roleId = (int) $request->input('role_id');
        abort_if(!$roleId, 422, '请选择角色');

        $query = DB::table('erp_rbac_user_roles as ur')
            ->join('erp_legacy_admin_users as u', 'ur.user_legacy_id', '=', 'u.legacy_id')
            ->leftJoin('erp_rbac_user_role_sources as source', function ($join): void {
                $join->on('source.user_legacy_id', '=', 'ur.user_legacy_id')
                    ->on('source.role_id', '=', 'ur.role_id');
            })
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
            ->groupBy('u.legacy_id', 'u.nickname', 'u.username', 'u.status')
            ->select(['u.legacy_id as id', 'u.legacy_id as user_id', 'u.nickname', 'u.username', 'u.status'])
            ->selectRaw("GROUP_CONCAT(DISTINCT source.assignment_source ORDER BY source.assignment_source SEPARATOR ',') as source_list")
            ->selectRaw("MAX(CASE WHEN source.assignment_source = 'manual' THEN 1 ELSE 0 END) as is_manual");

        $paginator = $query->paginate($this->perPage($request));
        $paginator->through(function (object $row): object {
            $row->sources = $row->source_list ? explode(',', $row->source_list) : [];
            $row->is_manual = (bool) $row->is_manual;
            unset($row->source_list);
            return $row;
        });
        return $this->paginated($paginator);
    }

    public function saveRoleUsers(Request $request, RbacUserRoleOwnershipService $ownership)
    {
        $this->authorizePermission($request, 'system.role.save_permissions');
        $data = $request->validate([
            'role_id' => 'required|integer',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer',
        ]);
        DB::transaction(function () use ($data, $ownership): void {
            $roleId = (int) $data['role_id'];
            $selected = array_values(array_unique(array_map('intval', $data['user_ids'] ?? [])));
            // user_ids 表达页面上的手工勾选集合，只能与 manual 所有权比较。
            // 有效角色并集还可能由 SSO、部门或系统来源持有，绝不能在保存时认领成 manual。
            $existing = DB::table('erp_rbac_user_role_sources')->where('role_id', $roleId)
                ->where('assignment_source', RbacUserRoleOwnershipService::SOURCE_MANUAL)
                ->lockForUpdate()->pluck('user_legacy_id')->map(fn ($id) => (int) $id)->all();
            foreach (array_diff($existing, $selected) as $userId) $ownership->removeManualRole($userId, $roleId);
            foreach (array_diff($selected, $existing) as $userId) $ownership->addManualRole($userId, $roleId);
        });
        return response()->json(['message' => '角色用户已保存']);
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->input('per_page', 20)));
    }

    private function authorizePermission(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $request->attributes->get('erp_user') ?: $auth->currentUser($request);
        abort_unless($user, 401, '请先登录 ERP。');
        abort_unless($auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true), 403, '当前用户没有系统管理权限。');
        return $user;
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

    private function approvalFlowUsesRole(string $roleCode): bool
    {
        return DB::table('erp_approval_flow_versions')
            ->orderBy('id')
            ->get(['definition_snapshot'])
            ->contains(function (object $version) use ($roleCode): bool {
                $definition = json_decode((string) $version->definition_snapshot, true);
                foreach ((array) ($definition['nodes'] ?? []) as $node) {
                    $rule = (array) ($node['approver_rule'] ?? []);
                    if (($rule['type'] ?? null) === 'role' && (string) ($rule['value'] ?? '') === $roleCode) return true;
                }
                return false;
            });
    }

    private function auditDeletion(string $type, object $record, object $user): void
    {
        if (! Schema::hasTable('erp_operation_logs')) return;
        DB::table('erp_operation_logs')->insert([
            'module' => 'rbac',
            'action' => 'delete',
            'target_type' => $type,
            'target_id' => $record->id,
            'old_snapshot' => json_encode((array) $record, JSON_UNESCAPED_UNICODE),
            'new_snapshot' => null,
            'reason' => '删除停用且未被引用的自定义配置',
            'operator_id' => $user->legacy_id ?? null,
            'operator_name' => $user->nickname ?? $user->username ?? null,
            'created_at' => now(),
        ]);
    }
}
