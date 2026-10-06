<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Local accounts, roles and departments share the existing business identity. */
class SystemAdministrationApplicationService
{
    public function __construct(private readonly RbacUserRoleOwnershipService $ownership, private readonly AuthContextService $auth)
    {
    }

    public function account(int $id): array
    {
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->whereNull('deleted_at')->first();
        abort_unless($user, 404, '管理员不存在或已删除。');
        $row = $this->safeSnapshot($user);
        $row['id'] = $id;
        $row['department_ids'] = DB::table('erp_department_users')->where('user_legacy_id', $id)
            ->orderBy('id')->pluck('department_legacy_id')->map(fn ($value) => (int) $value)->all();
        $row['role_assignments'] = $this->roleAssignments($id);
        $row['manual_role_ids'] = array_column(array_filter($row['role_assignments'], fn ($role) => $role['is_manual']), 'id');
        $row['is_super_admin'] = $this->auth->isSuperAdmin($user);
        $row['is_department_principal'] = $this->auth->isDepartmentPrincipal($user);
        $row['data_scope'] = $this->auth->dataScope($user);
        $codes = $this->auth->permissionCodes($user);
        $row['permission_summary'] = DB::table('erp_rbac_permissions')->whereIn('code', $codes)->get(['type'])
            ->countBy('type')->all();
        return $row;
    }

    public function saveAccount(?int $id, array $payload, object $actor, array $permissions, bool $super): array
    {
        $this->authorize($id ? 'system.admin.edit' : 'system.admin.create', $permissions, $super);
        $data = Validator::make($payload, [
            'username' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.@-]+$/'],
            'nickname' => 'required|string|max:120', 'mobile' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:120', 'sort' => 'nullable|integer|min:-999999|max:999999',
            'status' => 'required|in:normal,hidden', 'password' => ($id ? 'nullable' : 'required').'|string|min:8|max:128|confirmed',
            'department_ids' => 'present|array', 'department_ids.*' => 'integer|distinct|min:1',
            'manual_role_ids' => 'present|array', 'manual_role_ids.*' => 'integer|distinct|min:1',
            'expected_version' => ($id ? 'required' : 'nullable').'|integer|min:1', 'client_command_id' => 'required|string|max:80',
        ], ['password.min' => '密码至少需要8个字符。', 'password.confirmed' => '两次输入的密码不一致。',
            'username.regex' => '登录账号只能包含字母、数字、点、下划线、短横线或@。'])->validate();
        $data['department_ids'] = array_map('intval', $data['department_ids']);
        $data['manual_role_ids'] = array_map('intval', $data['manual_role_ids']);
        return $this->command($id ? 'admin.update:'.$id : 'admin.create', $data, $actor, function () use ($id, $data, $actor, $permissions, $super): array {
            $existing = $id ? $this->lockedAccount($id) : null;
            if ($existing) {
                $this->version($existing, $data);
                abort_if($this->auth->isSuperAdmin($existing) && ! $super, 403, '只有系统管理员可以修改系统管理员账号。');
                abort_if($existing->username === 'admin' && $data['username'] !== 'admin', 422, '系统默认管理员的登录账号不能更改。');
                if ($data['status'] !== $this->normalStatus($existing->status)) {
                    $this->authorize('system.admin.toggle_status', $permissions, $super);
                    $this->guardAccountAccess($existing, $data['status'], $actor);
                }
            }
            abort_if(DB::table('erp_legacy_admin_users')->where('username', trim($data['username']))
                ->when($id, fn ($q) => $q->where('legacy_id', '<>', $id))->exists(), 422, '登录账号已被使用，请更换账号。');
            $departments = $this->departmentsForAssignment($data['department_ids'], $id);
            $selectedRoles = $this->rolesForAssignment($data['manual_role_ids'], $id, $super);
            if ($existing && $existing->username === 'admin') {
                abort_unless($selectedRoles->contains(fn ($role) => $role->code === 'admin'), 422, '系统默认管理员必须保留系统管理员角色。');
            }
            $id ??= max(1, (int) DB::table('erp_legacy_admin_users')->max('legacy_id') + 1);
            $before = $existing ? $this->account($id) : null;
            $values = [
                'username' => trim($data['username']), 'nickname' => trim($data['nickname']),
                'mobile' => $data['mobile'] ?? null, 'email' => $data['email'] ?? null, 'sort' => $data['sort'] ?? 0,
                'status' => $data['status'], 'local_managed' => true,
                'department_ids' => $this->json($departments->pluck('legacy_id')->map(fn ($v) => (int) $v)->all()),
                'department_names' => $this->json($departments->pluck('name')->all()),
                'is_sales' => $departments->contains(fn ($dept) => str_contains($dept->name, '销售')),
                'business_version' => ($existing->business_version ?? 0) + 1, 'updated_at' => now(),
            ];
            if (! empty($data['password'])) $values['password_hash'] = Hash::make($data['password']);
            if ($existing) DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->update($values);
            else DB::table('erp_legacy_admin_users')->insert([
                ...$values, 'legacy_id' => $id, 'auth_group_names' => '[]', 'auth_group_ids' => '[]',
                'legacy_payload' => $this->json(['auth_source' => 'local_management']), 'created_at' => now(),
            ]);

            $oldMemberships = DB::table('erp_department_users')->where('user_legacy_id', $id)->get();
            foreach ($oldMemberships as $membership) {
                if (! in_array((int) $membership->department_legacy_id, $data['department_ids'], true)) {
                    DB::table('erp_department_users')->where('id', $membership->id)->delete();
                }
            }
            foreach ($data['department_ids'] as $departmentId) {
                DB::table('erp_department_users')->insertOrIgnore([
                    'department_legacy_id' => $departmentId, 'user_legacy_id' => $id,
                    'is_principal' => false, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->replaceManualRoles($id, $selectedRoles->pluck('id')->map(fn ($v) => (int) $v)->all());
            $this->refreshPrincipalRole($id);
            if ($existing && $this->auth->isSuperAdmin($existing) && ! $this->auth->isSuperAdmin(DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->first())) {
                $this->assertRemainingAdministrator();
            }
            // Changes to credentials, role ownership or membership invalidate
            // cached sessions. The logged-in editor keeps its session when only
            // its own non-authentication profile fields changed.
            if ($data['status'] !== 'normal' || ! empty($data['password']) || ($existing && $id !== (int) $actor->legacy_id)) {
                DB::table('erp_auth_tokens')->where('user_legacy_id', $id)->delete();
            }
            $after = $this->account($id);
            $this->audit('admin_account', $id, $existing ? 'update' : 'create', $before, $after, $actor);
            return $after;
        });
    }

    public function setAccountStatus(int $id, array $payload, object $actor, array $permissions, bool $super): array
    {
        $this->authorize('system.admin.toggle_status', $permissions, $super);
        $data = Validator::make($payload, ['status' => 'required|in:normal,hidden',
            'expected_version' => 'required|integer|min:1', 'client_command_id' => 'required|string|max:80'])->validate();
        return $this->command('admin.status:'.$id, $data, $actor, function () use ($id, $data, $actor, $super): array {
            $user = $this->lockedAccount($id);
            $this->version($user, $data);
            abort_if($this->auth->isSuperAdmin($user) && ! $super, 403, '只有系统管理员可以调整系统管理员账号状态。');
            $this->guardAccountAccess($user, $data['status'], $actor);
            $before = $this->account($id);
            DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->update([
                'status' => $data['status'], 'local_managed' => true, 'business_version' => $user->business_version + 1, 'updated_at' => now(),
            ]);
            DB::table('erp_auth_tokens')->where('user_legacy_id', $id)->delete();
            $after = $this->account($id);
            $this->audit('admin_account', $id, 'status', $before, $after, $actor);
            return $after;
        });
    }

    public function deleteAccount(int $id, array $payload, object $actor, array $permissions, bool $super): array
    {
        $this->authorize('system.admin.delete', $permissions, $super);
        $data = $this->mutationData($payload);
        return $this->command('admin.delete:'.$id, $data, $actor, function () use ($id, $data, $actor, $super): array {
            $user = $this->lockedAccount($id);
            $this->version($user, $data);
            abort_if($this->auth->isSuperAdmin($user) && ! $super, 403, '只有系统管理员可以删除系统管理员账号。');
            $this->guardAccountAccess($user, 'deleted', $actor);
            $before = $this->account($id);
            // Business documents reference this identity. Keep it as a tombstone
            // while removing its current access and organization assignments.
            DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->update([
                'status' => 'deleted', 'deleted_at' => now(), 'deleted_by' => $actor->legacy_id,
                'local_managed' => true, 'business_version' => $user->business_version + 1, 'updated_at' => now(),
            ]);
            foreach (['erp_auth_tokens', 'erp_department_users', 'erp_rbac_user_roles', 'erp_rbac_user_role_sources'] as $table) {
                DB::table($table)->where('user_legacy_id', $id)->delete();
            }
            $this->audit('admin_account', $id, 'delete', $before, ['status' => 'deleted', 'id' => $id], $actor);
            return ['id' => $id, 'message' => '管理员已删除，历史业务记录保留。'];
        });
    }

    public function saveRole(array $payload, object $actor, array $permissions, bool $super): array
    {
        $id = isset($payload['id']) ? (int) $payload['id'] : null;
        $this->authorize($id ? 'system.role.save_permissions' : 'system.role.create', $permissions, $super);
        $data = Validator::make($payload, [
            'id' => 'nullable|integer|min:1', 'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'name' => 'required|string|max:120', 'data_scope' => 'required|in:all,department,self',
            'enabled' => 'nullable|boolean', 'remark' => 'nullable|string|max:2000',
            'permission_ids' => 'sometimes|array', 'permission_ids.*' => 'integer|distinct|min:1',
            'expected_version' => 'nullable|integer|min:1', 'client_command_id' => 'nullable|string|max:80',
        ])->validate();
        if ($id) abort_unless(Schema::hasColumn('erp_rbac_roles', 'is_system'), 503, '角色编辑保护结构尚未部署，请先更新数据库结构。');
        return $this->command($id ? 'role.update:'.$id : 'role.create', $data, $actor, function () use ($id, $data, $actor, $super): array {
            $existing = $id ? DB::table('erp_rbac_roles')->where('id', $id)->lockForUpdate()->first() : null;
            if ($id) abort_unless($existing, 404, '角色不存在或已删除。');
            if ($existing) {
                $this->version($existing, $data);
                abort_if($existing->code === 'admin' && ! $super, 403, '只有系统管理员可以维护系统管理员角色。');
                abort_if($existing->is_system && $existing->code !== $data['code'], 422, '系统内置角色编码不能修改。');
                abort_if($existing->code !== $data['code'] && $this->roleReferenced($existing->code), 422, '角色已被审批流程引用，不能修改编码。');
                abort_if($existing->code === 'admin' && (! ($data['enabled'] ?? true) || $data['data_scope'] !== 'all'), 422, '系统管理员角色必须保持启用和全部数据范围。');
            }
            abort_if(DB::table('erp_rbac_roles')->where('code', $data['code'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists(), 422, '角色编码已存在。');
            if (! $existing) abort_if($data['code'] === 'admin', 422, '系统管理员编码已保留。');
            $before = $existing ? $this->roleSnapshot($id) : null;
            $permissionIds = $data['permission_ids'] ?? ($before['permission_ids'] ?? []);
            abort_if(DB::table('erp_rbac_permissions')->whereIn('id', $permissionIds)->count() !== count($permissionIds), 422, '所选权限节点不存在，请刷新权限树。');
            abort_if($existing && $existing->code === 'admin' && isset($data['permission_ids'])
                && count($permissionIds) !== DB::table('erp_rbac_permissions')->count(), 422, '系统管理员固定拥有全部权限，不能缩减其权限配置。');
            $values = [
                'code' => trim($data['code']), 'name' => trim($data['name']), 'data_scope' => $data['data_scope'],
                'enabled' => (bool) ($data['enabled'] ?? true), 'remark' => $data['remark'] ?? null,
                'business_version' => ($existing->business_version ?? 0) + 1, 'updated_at' => now(),
            ];
            if ($existing) DB::table('erp_rbac_roles')->where('id', $id)->update($values);
            else $id = (int) DB::table('erp_rbac_roles')->insertGetId([...$values, 'is_system' => false, 'created_at' => now()]);
            DB::table('erp_rbac_role_permissions')->where('role_id', $id)->delete();
            foreach ($permissionIds as $permissionId) DB::table('erp_rbac_role_permissions')->insert(['role_id' => $id, 'permission_id' => $permissionId]);
            $after = $this->roleSnapshot($id);
            $this->audit('rbac_role', $id, $existing ? 'update' : 'create', $before, $after, $actor);
            return [...$after, 'message' => '角色已保存。'];
        });
    }

    public function deleteRole(int $id, array $payload, object $actor, array $permissions, bool $super): array
    {
        $this->authorize('system.role.delete', $permissions, $super);
        abort_unless(Schema::hasColumn('erp_rbac_roles', 'is_system'), 503, '角色删除保护结构尚未部署，请先更新数据库结构。');
        $data = $this->mutationData($payload, false);
        return $this->command('role.delete:'.$id, $data, $actor, function () use ($id, $data, $actor): array {
            $role = DB::table('erp_rbac_roles')->where('id', $id)->lockForUpdate()->first();
            abort_unless($role, 404, '角色不存在或已删除。');
            $this->version($role, $data);
            abort_if($role->is_system, 422, '系统内置角色不能删除。');
            abort_if($role->enabled, 422, '请先停用该角色后再删除。');
            abort_if(DB::table('erp_rbac_user_roles')->where('role_id', $id)->exists()
                || DB::table('erp_rbac_user_role_sources')->where('role_id', $id)->exists(), 422, '角色仍有关联成员，请先移除成员。');
            abort_if($this->roleReferenced($role->code), 422, '角色已被审批流程引用，不能删除。');
            $before = $this->roleSnapshot($id);
            DB::table('erp_rbac_role_permissions')->where('role_id', $id)->delete();
            DB::table('erp_rbac_roles')->where('id', $id)->delete();
            $this->audit('rbac_role', $id, 'delete', $before, null, $actor);
            return ['id' => $id, 'message' => '角色已删除。'];
        });
    }

    public function saveRoleMembers(array $payload, object $actor, array $permissions, bool $super): array
    {
        $this->authorize('system.role.save_permissions', $permissions, $super);
        $data = Validator::make($payload, ['role_id' => 'required|integer|min:1',
            'user_ids' => 'nullable|array', 'user_ids.*' => 'integer|distinct|min:1',
            'add_user_ids' => 'nullable|array', 'add_user_ids.*' => 'integer|distinct|min:1',
            'remove_user_ids' => 'nullable|array', 'remove_user_ids.*' => 'integer|distinct|min:1',
            'expected_version' => 'nullable|integer|min:1', 'client_command_id' => 'nullable|string|max:80'])->validate();
        return $this->command('role.members:'.$data['role_id'], $data, $actor, function () use ($data, $actor, $super): array {
            $roleId = (int) $data['role_id'];
            $role = DB::table('erp_rbac_roles')->where('id', $roleId)->lockForUpdate()->first();
            abort_unless($role, 404, '角色不存在。');
            $this->version($role, $data);
            abort_if($role->code === 'admin' && ! $super, 403, '只有系统管理员可以分配系统管理员角色。');
            $manualIds = DB::table('erp_rbac_user_role_sources')->where('role_id', $roleId)->where('assignment_source', 'manual')->pluck('user_legacy_id')->map(fn ($v) => (int) $v)->all();
            // Existing clients can replace the complete manual set. The paginated
            // editor uses deltas so unseen members on other pages remain intact.
            $added = array_key_exists('user_ids', $data) ? array_diff($data['user_ids'] ?? [], $manualIds) : ($data['add_user_ids'] ?? []);
            $removed = array_key_exists('user_ids', $data) ? array_diff($manualIds, $data['user_ids'] ?? []) : ($data['remove_user_ids'] ?? []);
            abort_if(count(array_intersect($added, $removed)) > 0, 422, '同一成员不能同时添加和移除。');
            abort_if($added && ! $role->enabled, 422, '停用角色不能添加成员。');
            abort_if(DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $added)->whereNull('deleted_at')
                ->whereIn('status', ['normal', 'active'])->count() !== count($added), 422, '新增成员中存在停用或已删除账号。');
            $before = ['manual_user_ids' => $manualIds];
            foreach ($removed as $userId) {
                if ($role->code === 'admin') abort_if(DB::table('erp_legacy_admin_users')->where('legacy_id', $userId)->where('username', 'admin')->exists(), 422, '不能移除系统默认管理员的系统管理员角色。');
                $this->ownership->removeManualRole((int) $userId, $roleId);
                if ($role->code === 'admin') $this->assertRemainingAdministrator();
            }
            foreach ($added as $userId) $this->ownership->addManualRole((int) $userId, $roleId);
            DB::table('erp_legacy_admin_users')->whereIn('legacy_id', array_unique(array_merge($added, $removed)))
                ->increment('business_version', 1, ['updated_at' => now()]);
            DB::table('erp_rbac_roles')->where('id', $roleId)->update(['business_version' => $role->business_version + 1, 'updated_at' => now()]);
            $after = ['manual_user_ids' => DB::table('erp_rbac_user_role_sources')->where('role_id', $roleId)->where('assignment_source', 'manual')->pluck('user_legacy_id')->all()];
            $this->audit('rbac_role', $roleId, 'members', $before, $after, $actor);
            return ['message' => '角色成员已保存。', 'business_version' => $role->business_version + 1];
        });
    }

    public function saveDepartment(?int $id, array $payload, object $actor, array $permissions, bool $super): array
    {
        $this->authorize('system.department.save', $permissions, $super);
        $data = Validator::make($payload, ['name' => 'required|string|max:120', 'parent_legacy_id' => 'required|integer|min:0',
            'sort' => 'required|integer|min:-999999|max:999999', 'status' => 'required|in:normal,hidden',
            'expected_version' => ($id ? 'required' : 'nullable').'|integer|min:1', 'client_command_id' => 'required|string|max:80'])->validate();
        return $this->command($id ? 'department.update:'.$id : 'department.create', $data, $actor, function () use ($id, $data, $actor): array {
            $existing = $id ? $this->lockedDepartment($id) : null;
            if ($existing) $this->version($existing, $data);
            $parentId = (int) $data['parent_legacy_id'];
            $visited = [];
            for ($cursor = $parentId; $cursor > 0;) {
                abort_if($cursor === $id || in_array($cursor, $visited, true), 422, '上级部门不能是本部门或本部门的下级。');
                $visited[] = $cursor;
                $parent = $this->lockedDepartment($cursor);
                if ($data['status'] === 'normal') abort_if($parent->status !== 'normal', 422, '启用部门不能挂在停用部门下。');
                $cursor = (int) $parent->parent_legacy_id;
            }
            abort_if(DB::table('erp_departments')->whereNull('deleted_at')->where('parent_legacy_id', $parentId)
                ->where('name', trim($data['name']))->when($id, fn ($q) => $q->where('legacy_id', '<>', $id))->exists(), 422, '同一上级下已经存在同名部门。');
            if ($existing && $data['status'] === 'hidden') {
                abort_if(DB::table('erp_departments')->where('parent_legacy_id', $id)->whereNull('deleted_at')->where('status', 'normal')->exists(), 422, '请先停用下级部门，再停用本部门。');
            }
            $before = $existing ? (array) $existing : null;
            $id ??= max(1, (int) DB::table('erp_departments')->max('legacy_id') + 1);
            $values = [...array_intersect_key($data, array_flip(['name', 'parent_legacy_id', 'sort', 'status'])),
                'name' => trim($data['name']), 'local_managed' => true, 'business_version' => ($existing->business_version ?? 0) + 1, 'updated_at' => now()];
            if ($existing) DB::table('erp_departments')->where('legacy_id', $id)->update($values);
            else DB::table('erp_departments')->insert([...$values, 'legacy_id' => $id, 'legacy_payload' => $this->json(['auth_source' => 'local_management']), 'created_at' => now()]);
            if ($existing && $existing->name !== $values['name']) {
                $userIds = DB::table('erp_department_users')->where('department_legacy_id', $id)->pluck('user_legacy_id')->all();
                foreach ($userIds as $userId) $this->refreshDepartmentProjection((int) $userId);
            }
            $after = (array) DB::table('erp_departments')->where('legacy_id', $id)->first();
            $this->audit('department', $id, $existing ? 'update' : 'create', $before, $after, $actor);
            return $after;
        });
    }

    public function deleteDepartment(int $id, array $payload, object $actor, array $permissions, bool $super): array
    {
        $this->authorize('system.department.delete', $permissions, $super);
        $data = $this->mutationData($payload);
        return $this->command('department.delete:'.$id, $data, $actor, function () use ($id, $data, $actor): array {
            $department = $this->lockedDepartment($id);
            $this->version($department, $data);
            abort_if(DB::table('erp_departments')->where('parent_legacy_id', $id)->whereNull('deleted_at')->exists(), 422, '部门仍有下级部门，不能删除。');
            abort_if(DB::table('erp_department_users')->where('department_legacy_id', $id)->exists(), 422, '部门仍有成员，请先调整成员归属。');
            abort_if(DB::table('erp_approval_tasks')->where('department_id', $id)->exists() || $this->departmentReferenced($id), 422, '部门已被审批流程或审批记录引用，不能删除。');
            DB::table('erp_departments')->where('legacy_id', $id)->update([
                'status' => 'deleted', 'deleted_at' => now(), 'deleted_by' => $actor->legacy_id,
                'local_managed' => true, 'business_version' => $department->business_version + 1, 'updated_at' => now(),
            ]);
            $this->audit('department', $id, 'delete', (array) $department, ['legacy_id' => $id, 'status' => 'deleted'], $actor);
            return ['id' => $id, 'message' => '部门已删除。'];
        });
    }

    public function saveDepartmentPrincipals(int $id, array $payload, object $actor, array $permissions, bool $super): array
    {
        $this->authorize('system.department.set_principal', $permissions, $super);
        $data = Validator::make($payload, ['principal_ids' => 'present|array', 'principal_ids.*' => 'integer|distinct|min:1',
            'expected_version' => 'nullable|integer|min:1', 'client_command_id' => 'nullable|string|max:80'])->validate();
        return $this->command('department.principals:'.$id, $data, $actor, function () use ($id, $data, $actor): array {
            $department = $this->lockedDepartment($id);
            $this->version($department, $data);
            $selected = $data['principal_ids'] ?? [];
            abort_if($selected && $department->status !== 'normal', 422, '停用部门不能设置负责人。');
            $members = DB::table('erp_department_users as du')->join('erp_legacy_admin_users as u', 'u.legacy_id', '=', 'du.user_legacy_id')
                ->where('du.department_legacy_id', $id)->whereIn('u.legacy_id', $selected)->whereNull('u.deleted_at')->whereIn('u.status', ['normal', 'active'])->count();
            abort_if($members !== count($selected), 422, '负责人必须是本部门的启用成员。');
            $before = DB::table('erp_department_users')->where('department_legacy_id', $id)->where('is_principal', true)->pluck('user_legacy_id')->map(fn ($v) => (int) $v)->all();
            DB::table('erp_department_users')->where('department_legacy_id', $id)->update(['is_principal' => false, 'updated_at' => now()]);
            DB::table('erp_department_users')->where('department_legacy_id', $id)->whereIn('user_legacy_id', $selected)->update(['is_principal' => true]);
            foreach (array_unique(array_merge($before, $selected)) as $userId) $this->refreshPrincipalRole((int) $userId);
            DB::table('erp_legacy_admin_users')->whereIn('legacy_id', array_unique(array_merge($before, $selected)))
                ->increment('business_version', 1, ['local_managed' => true, 'updated_at' => now()]);
            DB::table('erp_departments')->where('legacy_id', $id)->update(['local_managed' => true, 'business_version' => $department->business_version + 1, 'updated_at' => now()]);
            $this->audit('department', $id, 'principals', ['principal_ids' => $before], ['principal_ids' => $selected], $actor);
            return ['message' => '部门负责人已保存。', 'business_version' => $department->business_version + 1];
        });
    }

    private function command(string $operation, array $data, object $actor, callable $action): array
    {
        abort_unless(Schema::hasTable('erp_system_management_commands'), 503, '系统管理结构尚未部署，请先更新数据库结构。');
        return DB::transaction(function () use ($operation, $data, $actor, $action): array {
            // The immutable administrator role is the shared organization lock.
            // Always acquire it first: IDs, tree edits, role ownership and deletion
            // cannot race each other into orphan assignments or cyclic trees.
            abort_unless(DB::table('erp_rbac_roles')->where('code', 'admin')->lockForUpdate()->first(), 503, '系统管理员角色尚未初始化。');
            $commandId = $data['client_command_id'] ?? null;
            $requestHash = hash('sha256', $this->json([$operation, $data]));
            if ($commandId) {
                $previous = DB::table('erp_system_management_commands')->where('command_id', $commandId)->lockForUpdate()->first();
                if ($previous) {
                    abort_unless($previous->operation === $operation && (int) $previous->operator_id === (int) $actor->legacy_id
                        && hash_equals($previous->request_hash, $requestHash), 409, '操作编号已被其他内容使用，请重新打开表单。');
                    return json_decode($previous->response_snapshot, true, 512, JSON_THROW_ON_ERROR);
                }
            }
            $response = $action();
            if ($commandId) DB::table('erp_system_management_commands')->insert([
                'command_id' => $commandId, 'operation' => $operation, 'operator_id' => $actor->legacy_id,
                'request_hash' => $requestHash, 'response_snapshot' => $this->json($response), 'created_at' => now(),
            ]);
            return $response;
        }, 5);
    }

    private function mutationData(array $payload, bool $required = true): array
    {
        return Validator::make($payload, [
            'expected_version' => ($required ? 'required' : 'nullable').'|integer|min:1',
            'client_command_id' => ($required ? 'required' : 'nullable').'|string|max:80',
        ])->validate();
    }

    private function authorize(string $required, array $permissions, bool $super): void
    {
        abort_unless($super || in_array($required, $permissions, true), 403, '没有执行此系统管理操作的权限。');
    }

    private function version(object $row, array $data): void
    {
        if (isset($data['expected_version'])) abort_unless((int) $row->business_version === (int) $data['expected_version'], 409, '资料已被其他操作修改，请刷新后重试。');
    }

    private function lockedAccount(int $id): object
    {
        $row = DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
        abort_unless($row, 404, '管理员不存在或已删除。');
        return $row;
    }

    private function lockedDepartment(int $id): object
    {
        $row = DB::table('erp_departments')->where('legacy_id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
        abort_unless($row, 404, '部门不存在或已删除。');
        return $row;
    }

    private function normalStatus(?string $status): string
    {
        return in_array($status, ['normal', 'active'], true) ? 'normal' : 'hidden';
    }

    private function guardAccountAccess(object $user, string $status, object $actor): void
    {
        if ($status === 'normal') return;
        abort_if((int) $user->legacy_id === (int) $actor->legacy_id, 422, '不能停用或删除当前登录账号。');
        abort_if($user->username === 'admin', 422, '系统默认管理员不能停用或删除。');
        if ($this->auth->isSuperAdmin($user)) $this->assertRemainingAdministrator((int) $user->legacy_id);
    }

    private function assertRemainingAdministrator(?int $exclude = null): void
    {
        $users = DB::table('erp_legacy_admin_users')->whereNull('deleted_at')->whereIn('status', ['normal', 'active'])
            ->when($exclude, fn ($q) => $q->where('legacy_id', '<>', $exclude))->get();
        abort_unless($users->contains(fn ($row) => $this->auth->isSuperAdmin($row)), 422, '必须保留至少一个启用的系统管理员。');
    }

    private function departmentsForAssignment(array $ids, ?int $userId)
    {
        $rows = DB::table('erp_departments')->whereIn('legacy_id', $ids)->whereNull('deleted_at')->lockForUpdate()->get();
        abort_if($rows->count() !== count($ids), 422, '所选部门不存在或已删除。');
        $existingIds = $userId ? DB::table('erp_department_users')->where('user_legacy_id', $userId)->pluck('department_legacy_id')->all() : [];
        foreach ($rows as $row) abort_if($row->status !== 'normal' && ! in_array($row->legacy_id, $existingIds), 422, '不能把账号加入停用部门。');
        return $rows;
    }

    private function rolesForAssignment(array $ids, ?int $userId, bool $super)
    {
        $rows = DB::table('erp_rbac_roles')->whereIn('id', $ids)->lockForUpdate()->get();
        abort_if($rows->count() !== count($ids), 422, '所选角色不存在。');
        $existingIds = $userId ? array_column($this->roleAssignments($userId), 'id') : [];
        foreach ($rows as $row) {
            abort_if($row->code === 'admin' && ! $super, 403, '只有系统管理员可以分配系统管理员角色。');
            abort_if(! $row->enabled && ! in_array((int) $row->id, $existingIds, true), 422, '不能分配停用角色。');
        }
        return $rows;
    }

    private function roleAssignments(int $userId): array
    {
        $sources = DB::table('erp_rbac_user_role_sources')->where('user_legacy_id', $userId)->get()->groupBy('role_id');
        return DB::table('erp_rbac_user_roles as ur')->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_legacy_id', $userId)->orderBy('r.id')->get(['r.id', 'r.code', 'r.name', 'r.enabled', 'r.data_scope'])
            ->map(function ($role) use ($sources): array {
                $owners = $sources->get($role->id, collect())->pluck('assignment_source')->all();
                return [...(array) $role, 'sources' => $owners, 'is_manual' => $owners === [] || in_array('manual', $owners, true)];
            })->all();
    }

    private function replaceManualRoles(int $userId, array $selected): void
    {
        foreach ($this->roleAssignments($userId) as $role) {
            // Historic local pivots predate source tracking. Adopt only pivots
            // without any owner; SSO/department ownership remains independent.
            if ($role['sources'] === []) $this->ownership->addManualRole($userId, (int) $role['id']);
            if ($role['is_manual'] && ! in_array((int) $role['id'], $selected, true)) $this->ownership->removeManualRole($userId, (int) $role['id']);
        }
        foreach ($selected as $roleId) $this->ownership->addManualRole($userId, $roleId);
    }

    private function refreshPrincipalRole(int $userId): void
    {
        $roleId = (int) DB::table('erp_rbac_roles')->where('code', 'department_principal')->value('id');
        if (! $roleId) return;
        if (DB::table('erp_department_users')->where('user_legacy_id', $userId)->where('is_principal', true)->exists()) $this->ownership->addDepartmentRole($userId, $roleId);
        else $this->ownership->removeDepartmentRole($userId, $roleId);
    }

    private function refreshDepartmentProjection(int $userId): void
    {
        $rows = DB::table('erp_department_users as du')->join('erp_departments as d', 'd.legacy_id', '=', 'du.department_legacy_id')
            ->where('du.user_legacy_id', $userId)->whereNull('d.deleted_at')->orderBy('du.id')->get(['d.legacy_id', 'd.name']);
        DB::table('erp_legacy_admin_users')->where('legacy_id', $userId)->update([
            'department_ids' => $this->json($rows->pluck('legacy_id')->map(fn ($v) => (int) $v)->all()),
            'department_names' => $this->json($rows->pluck('name')->all()),
            'is_sales' => $rows->contains(fn ($row) => str_contains($row->name, '销售')),
            'business_version' => DB::raw('business_version + 1'), 'updated_at' => now(),
        ]);
    }

    private function roleSnapshot(int $id): array
    {
        return [...(array) DB::table('erp_rbac_roles')->where('id', $id)->first(),
            'permission_ids' => DB::table('erp_rbac_role_permissions')->where('role_id', $id)->orderBy('permission_id')->pluck('permission_id')->map(fn ($v) => (int) $v)->all()];
    }

    private function roleReferenced(string $code): bool
    {
        return DB::table('erp_approval_flow_versions')->orderBy('id')->get(['definition_snapshot'])->contains(function ($version) use ($code): bool {
            $definition = json_decode($version->definition_snapshot, true) ?: [];
            foreach (($definition['nodes'] ?? []) as $node) {
                $rule = $node['approver_rule'] ?? [];
                if (($rule['type'] ?? '') === 'role' && ($rule['value'] ?? '') === $code) return true;
            }
            return false;
        });
    }

    private function departmentReferenced(int $id): bool
    {
        return DB::table('erp_approval_flow_versions')->orderBy('id')->get(['definition_snapshot'])->contains(function ($version) use ($id): bool {
            $definition = json_decode($version->definition_snapshot, true) ?: [];
            if (in_array($id, array_map('intval', $definition['applicable_scope']['department_ids'] ?? []), true)) return true;
            foreach (($definition['nodes'] ?? []) as $node) {
                $rule = $node['approver_rule'] ?? [];
                if (in_array($rule['type'] ?? '', ['department', 'department_principal', 'department_manager'], true)
                    && (int) ($rule['value'] ?? 0) === $id) return true;
            }
            return false;
        });
    }

    private function safeSnapshot(object|array $row): array
    {
        return array_diff_key((array) $row, array_flip(['password', 'password_hash', 'password_confirmation', 'legacy_payload']));
    }

    private function audit(string $type, int $id, string $action, ?array $before, ?array $after, object $actor): void
    {
        DB::table('erp_operation_logs')->insert([
            'module' => 'system_management', 'action' => $action, 'target_type' => $type, 'target_id' => $id,
            'old_snapshot' => $before === null ? null : $this->json($this->safeSnapshot($before)),
            'new_snapshot' => $after === null ? null : $this->json($this->safeSnapshot($after)),
            'operator_id' => $actor->legacy_id, 'operator_name' => $actor->nickname ?? $actor->username,
            'created_at' => now(),
        ]);
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
