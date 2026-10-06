<?php

namespace Tests\Feature\Erp;

use App\Services\Erp\AuthContextService;
use App\Services\Erp\RbacBootstrapService;
use App\Services\Erp\RbacUserRoleOwnershipService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemManagementCrudTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;
    private int $actorId = 998900;

    protected function setUp(): void
    {
        parent::setUp();
        app(RbacBootstrapService::class)->bootstrap(true);
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $this->actorId, 'username' => 'system-manager-'.Str::lower(Str::random(8)),
            'nickname' => '系统功能验证员', 'status' => 'normal', 'auth_group_names' => '[]',
            'local_managed' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(RbacUserRoleOwnershipService::class)->addManualRole($this->actorId, (int) DB::table('erp_rbac_roles')->where('code', 'admin')->value('id'));
        $this->token = $this->tokenFor($this->actorId);
        $this->withToken($this->token);
    }

    public function test_account_create_update_login_and_delete_preserve_identity_without_exposing_credentials(): void
    {
        $department = $this->department('销售一部');
        $role = $this->role(['system.admin.view']);
        $payload = $this->accountPayload([$department['legacy_id']], [$role['id']]);
        $created = $this->postJson('/api/v1/erp/admins', $payload)->assertCreated()->assertJsonPath('nickname', '中文员工')->json();
        $id = $created['id'];
        $this->assertArrayNotHasKey('password_hash', $created);
        $this->assertArrayNotHasKey('legacy_payload', $created);
        $this->assertSame([$department['legacy_id']], $created['department_ids']);
        $this->assertSame([$role['id']], $created['manual_role_ids']);
        $storedHash = DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->value('password_hash');
        $this->assertTrue(Hash::check($payload['password'], $storedHash));

        // The same create command cannot create a second employee after a lost response.
        $this->postJson('/api/v1/erp/admins', $payload)->assertCreated()->assertJsonPath('id', $id);
        $this->assertSame(1, DB::table('erp_legacy_admin_users')->where('username', $payload['username'])->count());
        $this->putJson('/api/v1/erp/admins/'.$id, [...$payload, 'password' => null, 'password_confirmation' => null,
            'nickname' => '修改后的中文员工', 'expected_version' => $created['business_version'], 'client_command_id' => $this->command()])
            ->assertOk()->assertJsonPath('nickname', '修改后的中文员工')->assertJsonPath('business_version', 2);
        $this->assertSame($storedHash, DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->value('password_hash'));
        $login = $this->postJson('/api/v1/erp/auth/login', ['username' => $payload['username'], 'password' => $payload['password']])
            ->assertOk()->assertJsonMissingPath('user.password_hash')->assertJsonMissingPath('user.legacy_payload')->json();
        $this->assertSame([$role['id']], DB::table('erp_rbac_user_roles')->where('user_legacy_id', $id)->pluck('role_id')->all());
        $this->withToken($login['token'])->getJson('/api/v1/erp/auth/me')->assertOk()->assertJsonMissingPath('user.password_hash');
        $this->withToken($this->token)->getJson('/api/v1/erp/user-directory/users?scope=system&status=all&keyword='.$payload['username'])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.password_hash');

        $delete = ['expected_version' => 2, 'client_command_id' => $this->command()];
        $this->deleteJson('/api/v1/erp/admins/'.$id, $delete)->assertOk();
        $this->deleteJson('/api/v1/erp/admins/'.$id, $delete)->assertOk();
        $this->assertDatabaseHas('erp_legacy_admin_users', ['legacy_id' => $id, 'status' => 'deleted']);
        $this->assertDatabaseMissing('erp_department_users', ['user_legacy_id' => $id]);
        $this->assertDatabaseMissing('erp_rbac_user_roles', ['user_legacy_id' => $id]);
        $this->getJson('/api/v1/erp/admins/'.$id)->assertNotFound();
        $this->getJson('/api/v1/erp/user-directory/users?scope=system&status=all&keyword='.$payload['username'])->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($login['token'])->getJson('/api/v1/erp/auth/me')->assertUnauthorized();
        $this->withToken($this->token)->postJson('/api/v1/erp/auth/login', ['username' => $payload['username'], 'password' => $payload['password']])->assertUnprocessable();
        $snapshots = DB::table('erp_operation_logs')->where('module', 'system_management')->get(['old_snapshot', 'new_snapshot'])->toJson();
        $this->assertStringNotContainsString('password', $snapshots);
        $this->assertStringNotContainsString($payload['password'], $snapshots);
        $this->assertStringNotContainsString($storedHash, $snapshots);
    }

    public function test_invalid_account_inputs_are_atomic_and_conflicting_versions_or_commands_are_rejected(): void
    {
        $payload = $this->accountPayload();
        $before = DB::table('erp_legacy_admin_users')->count();
        $this->postJson('/api/v1/erp/admins', [...$payload, 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/admins', [...$payload, 'password_confirmation' => 'different-password'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/admins', [...$payload, 'department_ids' => [999999991]])->assertUnprocessable();
        $this->postJson('/api/v1/erp/admins', [...$payload, 'manual_role_ids' => [999999991]])->assertUnprocessable();
        $this->assertSame($before, DB::table('erp_legacy_admin_users')->count());
        $created = $this->postJson('/api/v1/erp/admins', $payload)->assertCreated()->json();
        $this->putJson('/api/v1/erp/admins/'.$created['id'], $payload)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->postJson('/api/v1/erp/admins', [...$payload, 'client_command_id' => $this->command()])->assertUnprocessable();
        $this->postJson('/api/v1/erp/admins', [...$payload, 'nickname' => '不同内容'])->assertConflict();
        $update = [...$payload, 'expected_version' => 1, 'client_command_id' => $this->command(), 'nickname' => '版本更新'];
        $this->putJson('/api/v1/erp/admins/'.$created['id'], $update)->assertOk();
        $this->putJson('/api/v1/erp/admins/'.$created['id'], [...$update, 'client_command_id' => $this->command(), 'nickname' => '过时修改'])->assertConflict();
        $this->assertDatabaseHas('erp_legacy_admin_users', ['legacy_id' => $created['id'], 'nickname' => '版本更新']);
    }

    public function test_account_status_and_password_changes_revoke_existing_sessions_and_self_access_is_protected(): void
    {
        $payload = $this->accountPayload();
        $created = $this->postJson('/api/v1/erp/admins', $payload)->assertCreated()->json();
        $targetToken = $this->tokenFor($created['id']);
        $this->postJson('/api/v1/erp/admins/'.$created['id'].'/status', ['status' => 'hidden', 'expected_version' => 1, 'client_command_id' => $this->command()])->assertOk();
        $this->withToken($targetToken)->getJson('/api/v1/erp/auth/me')->assertUnauthorized();
        $this->withToken($this->token)->postJson('/api/v1/erp/admins/'.$created['id'].'/status', ['status' => 'normal', 'expected_version' => 2, 'client_command_id' => $this->command()])->assertOk();
        $secondToken = $this->tokenFor($created['id']);
        $this->putJson('/api/v1/erp/admins/'.$created['id'], [...$payload, 'expected_version' => 3, 'password' => 'ChangedSecret123!', 'password_confirmation' => 'ChangedSecret123!', 'client_command_id' => $this->command()])->assertOk();
        $this->withToken($secondToken)->getJson('/api/v1/erp/auth/me')->assertUnauthorized();
        $this->withToken($this->token)->postJson('/api/v1/erp/admins/'.$this->actorId.'/status', ['status' => 'hidden', 'expected_version' => 1, 'client_command_id' => $this->command()])->assertUnprocessable();
        $this->deleteJson('/api/v1/erp/admins/'.$this->actorId, ['expected_version' => 1, 'client_command_id' => $this->command()])->assertUnprocessable();
        $this->assertDatabaseHas('erp_legacy_admin_users', ['legacy_id' => $this->actorId, 'status' => 'normal']);
    }

    public function test_buttons_and_option_endpoints_enforce_independent_permissions_and_cannot_grant_admin_to_a_non_super_manager(): void
    {
        $viewRole = $this->role(['system.admin.view']);
        $payload = $this->accountPayload([], [$viewRole['id']]);
        $viewer = $this->postJson('/api/v1/erp/admins', $payload)->assertCreated()->json();
        $viewerToken = $this->tokenFor($viewer['id']);
        $this->withToken($viewerToken)->getJson('/api/v1/erp/user-directory/users?scope=system&page=1&per_page=1')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/erp/admins', $this->accountPayload())->assertForbidden();
        $this->putJson('/api/v1/erp/admins/'.$viewer['id'], $payload)->assertForbidden();
        $this->deleteJson('/api/v1/erp/admins/'.$viewer['id'], ['expected_version' => 1, 'client_command_id' => $this->command()])->assertForbidden();
        $this->getJson('/api/v1/erp/admins/options/users')->assertForbidden();
        $this->postJson('/api/v1/erp/departments', ['name' => '禁止写入', 'parent_legacy_id' => 0, 'sort' => 0, 'status' => 'normal'])->assertForbidden();

        $this->withToken($this->token);
        $managerRole = $this->role(['system.admin.create', 'system.admin.edit', 'system.role.save_permissions', 'system.role.view']);
        $manager = $this->postJson('/api/v1/erp/admins', $this->accountPayload([], [$managerRole['id']]))->assertCreated()->json();
        $this->withToken($this->tokenFor($manager['id']))->getJson('/api/v1/erp/rbac/permissions?tree=1')->assertOk();
        $adminId = (int) DB::table('erp_rbac_roles')->where('code', 'admin')->value('id');
        $this->postJson('/api/v1/erp/admins', $this->accountPayload([], [$adminId]))->assertForbidden();
        $this->postJson('/api/v1/erp/rbac/role-users', ['role_id' => $adminId, 'add_user_ids' => [$viewer['id']], 'client_command_id' => $this->command()])->assertForbidden();
    }

    public function test_role_metadata_save_preserves_permissions_and_disabled_role_permissions_stop_immediately(): void
    {
        $role = $this->role(['system.admin.view']);
        $user = $this->postJson('/api/v1/erp/admins', $this->accountPayload([], [$role['id']]))->assertCreated()->json();
        $userToken = $this->tokenFor($user['id']);
        $this->withToken($userToken)->getJson('/api/v1/erp/user-directory/users?scope=system&per_page=1')->assertOk();
        $this->withToken($this->token);
        $metadata = ['id' => $role['id'], 'code' => $role['code'], 'name' => '改名后的角色', 'data_scope' => 'self', 'enabled' => false,
            'expected_version' => 1, 'client_command_id' => $this->command()];
        $this->postJson('/api/v1/erp/rbac/roles', $metadata)->assertOk()->assertJsonPath('name', '改名后的角色')->assertJsonPath('business_version', 2);
        $this->assertSame($role['permission_ids'], DB::table('erp_rbac_role_permissions')->where('role_id', $role['id'])->pluck('permission_id')->all());
        $this->getJson('/api/v1/erp/rbac/roles')->assertOk();
        $this->withToken($userToken)->getJson('/api/v1/erp/user-directory/users?scope=system&per_page=1')->assertForbidden();
        $this->getJson('/api/v1/erp/auth/me')->assertOk()->assertJsonCount(0, 'permissions');
        $this->withToken($this->token)->postJson('/api/v1/erp/rbac/roles', [...$metadata, 'expected_version' => 2,
            'enabled' => true, 'permission_ids' => [99999999], 'client_command_id' => $this->command()])->assertUnprocessable();
        $this->assertDatabaseHas('erp_rbac_roles', ['id' => $role['id'], 'enabled' => false, 'business_version' => 2]);
        $this->getJson('/api/v1/erp/user-directory/users?scope=system&role_id='.$role['id'])->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.data_scope', 'self')->assertJsonPath('data.0.rbac_roles.0.enabled', false);
    }

    public function test_builtin_role_changes_survive_repeated_reads_and_administrator_role_cannot_be_disabled(): void
    {
        $role = DB::table('erp_rbac_roles')->where('code', 'sales_user')->first();
        $this->postJson('/api/v1/erp/rbac/roles', ['id' => $role->id, 'code' => $role->code, 'name' => '本企业销售岗位', 'data_scope' => 'department',
            'enabled' => false, 'permission_ids' => [], 'expected_version' => $role->business_version, 'client_command_id' => $this->command()])->assertOk();
        $this->getJson('/api/v1/erp/rbac/roles')->assertOk();
        $this->getJson('/api/v1/erp/auth/me')->assertOk();
        $this->getJson('/api/v1/erp/departments?tree=1')->assertOk();
        $this->assertDatabaseHas('erp_rbac_roles', ['id' => $role->id, 'name' => '本企业销售岗位', 'enabled' => false, 'data_scope' => 'department']);
        $this->assertDatabaseMissing('erp_rbac_role_permissions', ['role_id' => $role->id]);
        $missing = DB::table('erp_rbac_roles')->where('code', 'production_operator')->value('id');
        DB::table('erp_rbac_user_role_sources')->where('role_id', $missing)->delete();
        DB::table('erp_rbac_user_roles')->where('role_id', $missing)->delete();
        DB::table('erp_rbac_role_permissions')->where('role_id', $missing)->delete();
        DB::table('erp_rbac_roles')->where('id', $missing)->delete();
        $this->getJson('/api/v1/erp/rbac/roles')->assertOk();
        $this->assertDatabaseMissing('erp_rbac_role_permissions', ['role_id' => $role->id]);
        $this->postJson('/api/v1/erp/rbac/roles', ['id' => $role->id, 'code' => 'renamed_builtin', 'name' => '禁止改编码', 'data_scope' => 'self'])->assertUnprocessable();
        $admin = DB::table('erp_rbac_roles')->where('code', 'admin')->first();
        $this->postJson('/api/v1/erp/rbac/roles', ['id' => $admin->id, 'code' => 'admin', 'name' => $admin->name, 'data_scope' => 'all', 'enabled' => false])->assertUnprocessable();
        $this->postJson('/api/v1/erp/rbac/roles', ['id' => $admin->id, 'code' => 'admin', 'name' => $admin->name, 'data_scope' => 'all', 'permission_ids' => []])->assertUnprocessable();
        $this->deleteJson('/api/v1/erp/rbac/roles/'.$admin->id)->assertUnprocessable();
    }

    public function test_role_delete_checks_members_and_approval_references_and_allows_an_unused_disabled_custom_role(): void
    {
        $role = $this->role([], false);
        $this->deleteJson('/api/v1/erp/rbac/roles/'.$role['id'], ['expected_version' => 1, 'client_command_id' => $this->command()])->assertOk();
        $this->assertDatabaseMissing('erp_rbac_roles', ['id' => $role['id']]);
        $memberRole = $this->role([]);
        $user = $this->postJson('/api/v1/erp/admins', $this->accountPayload([], [$memberRole['id']]))->assertCreated()->json();
        DB::table('erp_rbac_roles')->where('id', $memberRole['id'])->update(['enabled' => false]);
        $this->deleteJson('/api/v1/erp/rbac/roles/'.$memberRole['id'])->assertUnprocessable();
        $this->assertDatabaseHas('erp_rbac_user_roles', ['role_id' => $memberRole['id'], 'user_legacy_id' => $user['id']]);
        $used = $this->role([], false);
        $this->approvalVersion(['nodes' => [['approver_rule' => ['type' => 'role', 'value' => $used['code']]]]]);
        $this->deleteJson('/api/v1/erp/rbac/roles/'.$used['id'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/rbac/roles', ['id' => $used['id'], 'code' => 'changed-used-code', 'name' => '有审批引用', 'data_scope' => 'self', 'enabled' => false])->assertUnprocessable();
    }

    public function test_paginated_role_member_deltas_preserve_unseen_members_and_non_manual_ownership(): void
    {
        $role = $this->role([]);
        $users = [];
        for ($i = 0; $i < 12; $i++) $users[] = $this->postJson('/api/v1/erp/admins', $this->accountPayload([], [$role['id']]))->assertCreated()->json('id');
        app(RbacUserRoleOwnershipService::class)->syncSsoRole($users[0], $role['code']);
        $this->getJson('/api/v1/erp/rbac/role-users?role_id='.$role['id'].'&page=2&per_page=10')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 12);
        $this->postJson('/api/v1/erp/rbac/role-users', ['role_id' => $role['id'], 'remove_user_ids' => [$users[0], $users[11]],
            'expected_version' => 1, 'client_command_id' => $this->command()])->assertOk();
        $this->assertDatabaseHas('erp_rbac_user_role_sources', ['user_legacy_id' => $users[0], 'role_id' => $role['id'], 'assignment_source' => 'sso']);
        $this->assertDatabaseMissing('erp_rbac_user_role_sources', ['user_legacy_id' => $users[0], 'role_id' => $role['id'], 'assignment_source' => 'manual']);
        $this->assertDatabaseHas('erp_rbac_user_roles', ['user_legacy_id' => $users[0], 'role_id' => $role['id']]);
        $this->assertDatabaseMissing('erp_rbac_user_roles', ['user_legacy_id' => $users[11], 'role_id' => $role['id']]);
        $this->assertSame(11, DB::table('erp_rbac_user_roles')->where('role_id', $role['id'])->count());
        $this->getJson('/api/v1/erp/admins/'.$users[11])->assertOk()->assertJsonPath('business_version', 2);
        $this->postJson('/api/v1/erp/rbac/role-users', ['role_id' => $role['id'], 'add_user_ids' => [9999999]])->assertUnprocessable();
    }

    public function test_department_crud_prevents_cycles_duplicates_and_refreshes_real_member_projection(): void
    {
        $parent = $this->department('一级组织');
        $child = $this->department('销售子部门', $parent['legacy_id']);
        $grandchild = $this->department('三级组织', $child['legacy_id']);
        $member = $this->postJson('/api/v1/erp/admins', $this->accountPayload([$child['legacy_id']]))->assertCreated()->json();
        $this->putJson('/api/v1/erp/departments/'.$parent['legacy_id'], ['name' => $parent['name'], 'parent_legacy_id' => $grandchild['legacy_id'], 'sort' => 0, 'status' => 'normal', 'expected_version' => 1, 'client_command_id' => $this->command()])->assertUnprocessable();
        $this->postJson('/api/v1/erp/departments', ['name' => $child['name'], 'parent_legacy_id' => $parent['legacy_id'], 'sort' => 0, 'status' => 'normal', 'client_command_id' => $this->command()])->assertUnprocessable();
        $this->putJson('/api/v1/erp/departments/'.$child['legacy_id'], ['name' => '仓储子部门', 'parent_legacy_id' => $parent['legacy_id'], 'sort' => 15, 'status' => 'normal',
            'expected_version' => 1, 'client_command_id' => $this->command()])->assertOk()->assertJsonPath('business_version', 2);
        $this->getJson('/api/v1/erp/admins/'.$member['id'])->assertOk()->assertJsonPath('department_names', '["仓储子部门"]')->assertJsonPath('is_sales', 0)->assertJsonPath('business_version', 2);
        $this->putJson('/api/v1/erp/departments/'.$parent['legacy_id'], ['name' => $parent['name'], 'parent_legacy_id' => 0, 'sort' => 0, 'status' => 'hidden', 'expected_version' => 1, 'client_command_id' => $this->command()])->assertUnprocessable();
        $unused = $this->department('待删除组织');
        $delete = ['expected_version' => 1, 'client_command_id' => $this->command()];
        $this->deleteJson('/api/v1/erp/departments/'.$unused['legacy_id'], $delete)->assertOk();
        $this->deleteJson('/api/v1/erp/departments/'.$unused['legacy_id'], $delete)->assertOk();
        $this->getJson('/api/v1/erp/departments?tree=1')->assertOk()->assertJsonMissing(['legacy_id' => $unused['legacy_id']]);
        $this->assertGreaterThan($unused['legacy_id'], $this->department('新组织')['legacy_id']);
    }

    public function test_department_delete_and_principal_assignment_guard_existing_relationships_and_keep_other_role_sources(): void
    {
        $parent = $this->department('负责人组织');
        $child = $this->department('下级组织', $parent['legacy_id']);
        $principalRole = (int) DB::table('erp_rbac_roles')->where('code', 'department_principal')->value('id');
        $member = $this->postJson('/api/v1/erp/admins', $this->accountPayload([$child['legacy_id']], [$principalRole]))->assertCreated()->json();
        $outsider = $this->postJson('/api/v1/erp/admins', $this->accountPayload())->assertCreated()->json();
        $this->postJson('/api/v1/erp/departments/'.$child['legacy_id'].'/principals', ['principal_ids' => [$outsider['id']]])->assertUnprocessable();
        $this->postJson('/api/v1/erp/departments/'.$child['legacy_id'].'/principals', ['principal_ids' => [$member['id']], 'expected_version' => 1, 'client_command_id' => $this->command()])->assertOk();
        $this->getJson('/api/v1/erp/admins/'.$member['id'])->assertOk()->assertJsonPath('business_version', 2);
        $this->assertDatabaseHas('erp_rbac_user_role_sources', ['role_id' => $principalRole, 'user_legacy_id' => $member['id'], 'assignment_source' => 'department']);
        $this->postJson('/api/v1/erp/departments/'.$child['legacy_id'].'/principals', ['principal_ids' => [], 'expected_version' => 2, 'client_command_id' => $this->command()])->assertOk();
        $this->assertDatabaseMissing('erp_rbac_user_role_sources', ['role_id' => $principalRole, 'user_legacy_id' => $member['id'], 'assignment_source' => 'department']);
        $this->assertDatabaseHas('erp_rbac_user_role_sources', ['role_id' => $principalRole, 'user_legacy_id' => $member['id'], 'assignment_source' => 'manual']);
        $this->deleteJson('/api/v1/erp/departments/'.$parent['legacy_id'], ['expected_version' => 1, 'client_command_id' => $this->command()])->assertUnprocessable();
        $this->deleteJson('/api/v1/erp/departments/'.$child['legacy_id'], ['expected_version' => 3, 'client_command_id' => $this->command()])->assertUnprocessable();
        $this->getJson('/api/v1/erp/admins/options/users?department_id='.$child['legacy_id'].'&page=1&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $member['id']);
        $used = $this->department('审批组织');
        $this->approvalVersion(['applicable_scope' => ['type' => 'departments', 'department_ids' => [$used['legacy_id']]]]);
        $this->deleteJson('/api/v1/erp/departments/'.$used['legacy_id'], ['expected_version' => 1, 'client_command_id' => $this->command()])->assertUnprocessable();
    }

    private function command(): string { return (string) Str::uuid(); }

    private function tokenFor(int $id): string
    {
        $token = Str::random(48);
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        return $token;
    }

    private function accountPayload(array $departments = [], array $roles = []): array
    {
        return ['username' => 'system-crud-'.Str::lower(Str::random(10)), 'nickname' => '中文员工', 'mobile' => '13800000000',
            'email' => 'fixture@example.invalid', 'sort' => 0, 'status' => 'normal', 'password' => 'FixtureSecret123!',
            'password_confirmation' => 'FixtureSecret123!', 'department_ids' => $departments, 'manual_role_ids' => $roles, 'client_command_id' => $this->command()];
    }

    private function department(string $name, int $parent = 0): array
    {
        return $this->postJson('/api/v1/erp/departments', ['name' => $name.'-'.Str::random(5), 'parent_legacy_id' => $parent, 'sort' => 0, 'status' => 'normal', 'client_command_id' => $this->command()])->assertCreated()->json();
    }

    private function role(array $codes = [], bool $enabled = true): array
    {
        return $this->postJson('/api/v1/erp/rbac/roles', ['code' => 'system_crud_'.Str::lower(Str::random(10)), 'name' => '专项角色',
            'enabled' => $enabled, 'data_scope' => 'self', 'permission_ids' => DB::table('erp_rbac_permissions')->whereIn('code', $codes)->pluck('id')->all(), 'client_command_id' => $this->command()])->assertOk()->json();
    }

    private function approvalVersion(array $definition): void
    {
        $flowId = DB::table('erp_approval_flow_templates')->insertGetId([
            'flow_code' => 'system-crud-'.Str::random(8), 'flow_name' => '系统配置引用', 'business_module' => 'system', 'business_type' => 'fixture', 'business_scene' => '系统维护', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_approval_flow_versions')->insert(['flow_template_id' => $flowId, 'version_no' => 1, 'version_status' => 'DRAFT',
            'definition_snapshot' => json_encode($definition, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
    }
}
