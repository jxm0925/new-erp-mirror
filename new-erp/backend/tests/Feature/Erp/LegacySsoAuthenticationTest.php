<?php

namespace Tests\Feature\Erp;

use App\Services\Erp\RbacUserRoleOwnershipService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacySsoAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'phase6b1-sso-feature-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('sso.shared_secret', self::SECRET);
        Config::set('sso.ticket_ttl', 300);
    }

    public function test_valid_ticket_is_consumed_once_and_projects_least_privileged_operator(): void
    {
        $payload = $this->payload(991001);
        $first = $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($payload)]);

        $first->assertOk()->assertJsonPath('user.legacy_id', 991001)->assertJsonPath('data_scope', 'department')
            ->assertJsonPath('is_super_admin', false);
        $this->assertContains('production.task.claim', $first->json('permissions'));
        $this->assertNotContains('production.work_order.create', $first->json('permissions'));
        $this->assertSame(['production_operator'], $this->roleCodes(991001));
        $this->assertSame(['production_operator'], $this->ssoRoleCodes(991001));

        $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($payload)])->assertStatus(409);
        $this->assertSame(1, DB::table('erp_sso_ticket_consumptions')->where('nonce', $payload['nonce'])->count());
    }

    public function test_rejects_forged_expired_future_over_ttl_wrong_issuer_and_invalid_nonce_tickets(): void
    {
        $valid = $this->payload(991002);
        $forged = $this->ticket($valid);
        $forged = substr($forged, 0, -1).($forged[-1] === 'a' ? 'b' : 'a');
        $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $forged])->assertStatus(422);

        $this->assertRejected(array_merge($valid, ['nonce' => $this->nonce(), 'issued_at' => time() - 400, 'expire_at' => time() - 1]));
        $this->assertRejected(array_merge($valid, ['nonce' => $this->nonce(), 'issued_at' => time() + 31, 'expire_at' => time() + 90]));
        $this->assertRejected(array_merge($valid, ['nonce' => $this->nonce(), 'expire_at' => $valid['issued_at'] + 331]));
        $this->assertRejected(array_merge($valid, ['nonce' => $this->nonce(), 'issuer' => 'untrusted-erp']));
        $this->assertRejected(array_merge($valid, ['nonce' => 'bad-nonce']));
    }

    public function test_rejects_missing_username_and_username_conflict_without_partial_projection(): void
    {
        $missing = $this->payload(991003);
        unset($missing['username']);
        $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($missing)])->assertStatus(422);
        $this->assertDatabaseMissing('erp_sso_ticket_consumptions', ['nonce' => $missing['nonce']]);
        $this->assertDatabaseMissing('erp_legacy_admin_users', ['legacy_id' => 991003]);

        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => 991004, 'username' => 'duplicate-sso-user', 'status' => 'normal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $conflict = $this->payload(991005, ['username' => 'duplicate-sso-user']);
        $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($conflict)])->assertStatus(409);
        $this->assertDatabaseMissing('erp_sso_ticket_consumptions', ['nonce' => $conflict['nonce']]);
        $this->assertDatabaseMissing('erp_legacy_admin_users', ['legacy_id' => 991005]);
        $this->assertDatabaseMissing('erp_department_users', ['user_legacy_id' => 991005]);
        $this->assertDatabaseMissing('erp_rbac_user_roles', ['user_legacy_id' => 991005]);
        $this->assertSame(0, DB::table('erp_auth_tokens')->where('user_legacy_id', 991005)->count());
    }

    public function test_only_explicit_normal_and_active_statuses_are_allowed(): void
    {
        foreach (['normal', 'active'] as $offset => $status) {
            $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($this->payload(991010 + $offset, ['status' => $status]))])->assertOk();
        }
        foreach (['hidden', 'disabled', 'suspended', '', 'NORMAL_UNKNOWN'] as $offset => $status) {
            $payload = $this->payload(991020 + $offset, ['status' => $status]);
            $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($payload)])->assertStatus(403);
            $this->assertDatabaseMissing('erp_legacy_admin_users', ['legacy_id' => $payload['admin_id']]);
        }
    }

    public function test_departments_and_auth_groups_are_replaced_by_latest_signed_identity(): void
    {
        $legacyId = 991030;
        $first = $this->payload($legacyId, [
            'departments' => [['id' => 7301, 'name' => '旧部门', 'is_principal' => true]],
            'auth_groups' => [['id' => 8301, 'name' => '销售负责人']],
        ]);
        $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($first)])->assertOk();

        $second = $this->payload($legacyId, [
            'departments' => [['id' => 7302, 'name' => '新部门', 'is_principal' => false]],
            'auth_groups' => [['id' => 8302, 'name' => '普通员工']],
        ]);
        $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($second)])->assertOk();

        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->first();
        $this->assertSame([7302], json_decode($user->department_ids, true));
        $this->assertSame(['新部门'], json_decode($user->department_names, true));
        $this->assertSame([8302], json_decode($user->auth_group_ids, true));
        $this->assertSame(['普通员工'], json_decode($user->auth_group_names, true));
        $this->assertDatabaseMissing('erp_department_users', ['user_legacy_id' => $legacyId, 'department_legacy_id' => 7301]);
        $this->assertDatabaseHas('erp_department_users', ['user_legacy_id' => $legacyId, 'department_legacy_id' => 7302, 'is_principal' => 0]);
    }

    public function test_all_projected_high_roles_are_removed_when_identity_is_downgraded(): void
    {
        $cases = [
            'admin' => ['is_super_admin' => true],
            'sales_manager' => ['auth_groups' => [['id' => 8401, 'name' => '销售负责人']]],
            'department_principal' => ['departments' => [['id' => 7401, 'name' => '生产部', 'is_principal' => true]]],
            'sales_user' => ['is_sales' => true],
        ];
        $oldPermissions = [
            'admin' => 'system.menu.view',
            'sales_manager' => 'sales_order.inventory_lock',
            'department_principal' => 'production.work_order.publish',
            'sales_user' => 'sales_order.inventory_lock',
        ];

        $index = 0;
        foreach ($cases as $roleCode => $identity) {
            $legacyId = 991100 + $index++;
            $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($this->payload($legacyId, $identity))])->assertOk();
            $this->assertContains($roleCode, $this->roleCodes($legacyId));

            $response = $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($this->payload($legacyId))]);
            $response->assertOk()->assertJsonPath('data_scope', 'department')->assertJsonPath('is_super_admin', false);
            $this->assertSame(['production_operator'], $this->roleCodes($legacyId));
            $this->assertNotContains($oldPermissions[$roleCode], $response->json('permissions'));
        }
    }

    public function test_sso_role_replacement_preserves_independently_owned_manual_role(): void
    {
        $legacyId = 991200;
        $high = $this->payload($legacyId, ['auth_groups' => [['id' => 8501, 'name' => '销售负责人']]]);
        $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($high)])->assertOk();
        $roleId = (int) DB::table('erp_rbac_roles')->where('code', 'sales_manager')->value('id');
        app(RbacUserRoleOwnershipService::class)->addManualRole($legacyId, $roleId);

        $response = $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($this->payload($legacyId))]);
        $response->assertOk();
        $this->assertEqualsCanonicalizing(['production_operator', 'sales_manager'], $this->roleCodes($legacyId));
        $this->assertDatabaseHas('erp_rbac_user_role_sources', [
            'user_legacy_id' => $legacyId, 'role_id' => $roleId, 'assignment_source' => 'manual',
        ]);
        $this->assertDatabaseMissing('erp_rbac_user_role_sources', [
            'user_legacy_id' => $legacyId, 'role_id' => $roleId, 'assignment_source' => 'sso',
        ]);
    }

    public function test_disabled_local_user_cannot_use_an_existing_bearer_token(): void
    {
        $legacyId = 991300;
        $response = $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($this->payload($legacyId))])->assertOk();
        $token = $response->json('token');
        DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->update(['status' => 'disabled']);

        $this->withToken($token)->getJson('/api/v1/erp/auth/me')->assertStatus(401)->assertJsonPath('error_code', 'unauthenticated');
        $this->assertSame(0, DB::table('erp_auth_tokens')->where('user_legacy_id', $legacyId)->count());
    }

    private function payload(int $legacyId, array $overrides = []): array
    {
        $issuedAt = time();
        return array_replace([
            'issuer' => 'fastadmin',
            'admin_id' => $legacyId,
            'username' => 'sso-user-'.$legacyId,
            'nickname' => 'SSO 用户 '.$legacyId,
            'status' => 'normal',
            'issued_at' => $issuedAt,
            'expire_at' => $issuedAt + 300,
            'nonce' => $this->nonce(),
            'departments' => [['id' => 7000 + ($legacyId % 100), 'name' => '生产部', 'is_principal' => false]],
            'auth_groups' => [['id' => 8000 + ($legacyId % 100), 'name' => '普通员工']],
            'is_sales' => false,
            'is_super_admin' => false,
        ], $overrides);
    }

    private function ticket(array $payload): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        return $encoded.'.'.hash_hmac('sha256', $encoded, self::SECRET);
    }

    private function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function assertRejected(array $payload): void
    {
        $this->postJson('/api/v1/erp/auth/sso', ['ticket' => $this->ticket($payload)])->assertStatus(422);
        $this->assertDatabaseMissing('erp_sso_ticket_consumptions', ['nonce' => $payload['nonce']]);
    }

    private function roleCodes(int $legacyId): array
    {
        return DB::table('erp_rbac_user_roles as ur')->join('erp_rbac_roles as role', 'role.id', '=', 'ur.role_id')
            ->where('ur.user_legacy_id', $legacyId)->orderBy('role.code')->pluck('role.code')->all();
    }

    private function ssoRoleCodes(int $legacyId): array
    {
        return DB::table('erp_rbac_user_role_sources as source')->join('erp_rbac_roles as role', 'role.id', '=', 'source.role_id')
            ->where('source.user_legacy_id', $legacyId)->where('source.assignment_source', 'sso')->orderBy('role.code')->pluck('role.code')->all();
    }
}
