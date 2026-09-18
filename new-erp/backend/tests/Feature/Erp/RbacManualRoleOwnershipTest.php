<?php

namespace Tests\Feature\Erp;

use App\Services\Erp\{RbacBootstrapService, RbacUserRoleOwnershipService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RbacManualRoleOwnershipTest extends TestCase
{
    use DatabaseTransactions;

    public function test_role_member_save_only_changes_manual_ownership(): void
    {
        app(RbacBootstrapService::class)->bootstrap(true);
        $roleId = (int) DB::table('erp_rbac_roles')->where('code', 'production_operator')->value('id');
        $ids = [996101, 996102, 996103];
        foreach ($ids as $id) {
            DB::table('erp_legacy_admin_users')->insert([
                'legacy_id' => $id,
                'username' => 'manual-source-'.$id.'-'.Str::lower(Str::random(5)),
                'nickname' => '角色来源测试'.$id,
                'status' => 'normal',
                'auth_group_names' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $ownership = app(RbacUserRoleOwnershipService::class);
        foreach ([$ids[0], $ids[1]] as $id) {
            DB::table('erp_rbac_user_role_sources')->insert([
                'user_legacy_id' => $id, 'role_id' => $roleId, 'assignment_source' => 'sso',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $id, 'role_id' => $roleId]);
        }
        $ownership->addManualRole($ids[1], $roleId);
        $ownership->addManualRole($ids[2], $roleId);

        // 本用例自行建立有效操作者及最小权限，不依赖测试库恰好存在legacy_id=1的管理员。
        $managerId = 996100;
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $managerId, 'username' => 'manual-manager-'.Str::lower(Str::random(8)),
            'nickname' => '角色来源管理测试', 'status' => 'normal', 'auth_group_names' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $managerRole = DB::table('erp_rbac_roles')->insertGetId([
            'code' => 'manual_manager_'.Str::lower(Str::random(8)), 'name' => '角色来源管理测试',
            'data_scope' => 'all', 'enabled' => true, 'is_system' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $managerPermissions = DB::table('erp_rbac_permissions')
            ->whereIn('code', ['system.role.view', 'system.role.save_permissions'])->pluck('id');
        $this->assertCount(2, $managerPermissions);
        foreach ($managerPermissions as $permissionId) {
            DB::table('erp_rbac_role_permissions')->insert(['role_id' => $managerRole, 'permission_id' => $permissionId]);
        }
        $ownership->addManualRole($managerId, $managerRole);
        $token = 'rbac-manual-'.Str::random(28);
        DB::table('erp_auth_tokens')->insert([
            'user_legacy_id' => $managerId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = collect($this->withToken($token)->getJson('/api/v1/erp/rbac/role-users?role_id='.$roleId.'&per_page=100')
            ->assertOk()->json('data'))->keyBy('user_id');
        $this->assertFalse($rows[$ids[0]]['is_manual']);
        $this->assertSame(['sso'], $rows[$ids[0]]['sources']);
        $this->assertTrue($rows[$ids[1]]['is_manual']);
        $this->assertEqualsCanonicalizing(['manual', 'sso'], $rows[$ids[1]]['sources']);

        // 页面未勾选纯 SSO 用户，并取消另外两人的 manual 所有权。
        $this->withToken($token)->postJson('/api/v1/erp/rbac/role-users', [
            'role_id' => $roleId,
            'user_ids' => [],
        ])->assertOk();

        $this->assertDatabaseMissing('erp_rbac_user_role_sources', [
            'user_legacy_id' => $ids[0], 'role_id' => $roleId, 'assignment_source' => 'manual',
        ]);
        $this->assertDatabaseHas('erp_rbac_user_roles', ['user_legacy_id' => $ids[0], 'role_id' => $roleId]);
        $this->assertDatabaseMissing('erp_rbac_user_role_sources', [
            'user_legacy_id' => $ids[1], 'role_id' => $roleId, 'assignment_source' => 'manual',
        ]);
        $this->assertDatabaseHas('erp_rbac_user_role_sources', [
            'user_legacy_id' => $ids[1], 'role_id' => $roleId, 'assignment_source' => 'sso',
        ]);
        $this->assertDatabaseHas('erp_rbac_user_roles', ['user_legacy_id' => $ids[1], 'role_id' => $roleId]);
        $this->assertDatabaseMissing('erp_rbac_user_roles', ['user_legacy_id' => $ids[2], 'role_id' => $roleId]);

        // 修夹具不放松认证：同一操作者停用后原token仍必须即时失效。
        DB::table('erp_legacy_admin_users')->where('legacy_id', $managerId)->update(['status' => 'disabled']);
        $this->withToken($token)->getJson('/api/v1/erp/rbac/role-users?role_id='.$roleId)->assertUnauthorized();
        $this->assertDatabaseMissing('erp_auth_tokens', ['token_hash' => hash('sha256', $token)]);
    }
}
