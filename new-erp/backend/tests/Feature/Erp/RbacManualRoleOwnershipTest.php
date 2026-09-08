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

        $token = 'rbac-manual-'.Str::random(28);
        DB::table('erp_auth_tokens')->insert([
            'user_legacy_id' => 1,
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
    }
}
