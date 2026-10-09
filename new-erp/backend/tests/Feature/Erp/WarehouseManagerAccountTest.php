<?php

namespace Tests\Feature\Erp;

use App\Services\Erp\AuthContextService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseManagerAccountTest extends TestCase
{
    use DatabaseTransactions;

    private array $permissions = ['master.warehouse.create', 'master.warehouse.edit'];

    protected function setUp(): void
    {
        parent::setUp();
        $actor = (object) ['legacy_id' => 1, 'username' => 'warehouse-test'];
        $auth = \Mockery::mock(AuthContextService::class)->makePartial();
        $auth->shouldReceive('currentUser')->andReturn($actor);
        $auth->shouldReceive('isSuperAdmin')->andReturnFalse();
        $auth->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
        $this->app->instance(AuthContextService::class, $auth);
    }

    public function test_existing_admin_identity_is_saved_and_name_changes_are_read_from_same_account(): void
    {
        $id = $this->employee();
        $payload = $this->payload(['manager_user_id' => $id]);
        $row = $this->postJson('/api/v1/erp/master/warehouses', $payload)->assertCreated()
            ->assertJsonPath('data.manager_user_id', $id)->assertJsonPath('data.manager_user.legacy_id', $id)->json('data');
        $this->assertDatabaseHas('erp_warehouses', ['id' => $row['id'], 'manager_user_id' => $id, 'manager' => '同名员工']);
        DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->update(['nickname' => '更名员工']);
        $this->getJson('/api/v1/erp/master/warehouses/'.$row['id'])->assertOk()->assertJsonPath('manager_user.nickname', '更名员工');
        $this->getJson('/api/v1/erp/master/warehouses?keyword='.$payload['warehouse_code'])->assertOk()->assertJsonPath('data.0.manager_user.legacy_id', $id);
        $this->putJson('/api/v1/erp/master/warehouses/'.$row['id'], [...$payload, 'manager_user_id' => null, 'expected_manager_user_id' => $id])
            ->assertOk()->assertJsonPath('data.manager_user_id', null)->assertJsonPath('data.manager', null);
        $this->assertSame(2, DB::table('erp_operation_logs')->where('module', 'warehouse')->where('action', 'set_manager')->where('target_id', $row['id'])->count());
    }

    public function test_fake_names_unknown_ids_and_disabled_new_assignments_are_rejected(): void
    {
        $disabled = $this->employee('hidden');
        $this->postJson('/api/v1/erp/master/warehouses', $this->payload(['manager' => '随便填写']))->assertUnprocessable();
        $this->postJson('/api/v1/erp/master/warehouses', $this->payload(['manager_user_id' => 999999999]))->assertUnprocessable();
        $this->postJson('/api/v1/erp/master/warehouses', $this->payload(['manager_user_id' => $disabled]))->assertUnprocessable();
        $this->permissions = [];
        $this->postJson('/api/v1/erp/master/warehouses', $this->payload())->assertForbidden();
        $this->getJson('/api/v1/erp/user-directory/users?scope=warehouse')->assertForbidden();
    }

    public function test_stale_assignment_is_rejected_and_existing_disabled_employee_is_preserved(): void
    {
        $first = $this->employee(); $second = $this->employee('active');
        $payload = $this->payload(['manager_user_id' => $first]);
        $id = $this->postJson('/api/v1/erp/master/warehouses', $payload)->assertCreated()->json('data.id');
        DB::table('erp_legacy_admin_users')->where('legacy_id', $first)->update(['status' => 'hidden']);
        $this->putJson('/api/v1/erp/master/warehouses/'.$id, [...$payload, 'remark' => '保留任职关联', 'expected_manager_user_id' => $first])->assertOk();
        $this->putJson('/api/v1/erp/master/warehouses/'.$id, [...$payload, 'manager_user_id' => $second])->assertUnprocessable();
        $this->putJson('/api/v1/erp/master/warehouses/'.$id, [...$payload, 'manager_user_id' => $second, 'expected_manager_user_id' => $first])->assertOk();
        $this->putJson('/api/v1/erp/master/warehouses/'.$id, [...$payload, 'manager_user_id' => null, 'expected_manager_user_id' => $first])->assertUnprocessable();
        $this->assertDatabaseHas('erp_warehouses', ['id' => $id, 'manager_user_id' => $second]);
    }

    public function test_selector_paginates_real_admins_and_excludes_private_data_and_disabled_accounts(): void
    {
        $prefix = '库管'.Str::random(8);
        $first = $this->employee('normal', $prefix); $second = $this->employee('active', $prefix);
        $this->employee('hidden', $prefix);
        $response = $this->getJson('/api/v1/erp/user-directory/users?scope=warehouse&keyword='.$prefix.'&per_page=1&page=2&include_departments=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 2)->assertJsonCount(1, 'data');
        $row = $response->json('data.0');
        $this->assertContains($row['id'], [$first, $second]);
        foreach (['password_hash', 'email', 'mobile', 'legacy_payload', 'rbac_roles'] as $field) $this->assertArrayNotHasKey($field, $row);
        $this->getJson('/api/v1/erp/user-directory/users?scope=system')->assertForbidden();
        $this->getJson('/api/v1/erp/user-directory/users?scope=warehouse&keyword='.$prefix)->assertOk()->assertJsonPath('meta.per_page', 20);
    }

    public function test_department_filter_uses_existing_employee_memberships(): void
    {
        $prefix = '部门'.Str::random(8);
        $member = $this->employee('normal', $prefix);
        $this->employee('normal', $prefix);
        $department = random_int(10000000, 90000000);
        DB::table('erp_departments')->insert(['legacy_id' => $department, 'name' => $prefix, 'parent_legacy_id' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_department_users')->insert(['department_legacy_id' => $department, 'user_legacy_id' => $member]);
        $this->getJson('/api/v1/erp/user-directory/users?scope=warehouse&keyword='.$prefix.'&department_id='.$department)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $member);
    }

    private function employee(string $status = 'normal', ?string $prefix = null): int
    {
        $id = random_int(10000000, 90000000);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $id, 'username' => ($prefix ?: 'warehouse').$id, 'nickname' => '同名员工', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function payload(array $overrides = []): array
    {
        return [...['warehouse_code' => 'WH'.Str::random(14), 'warehouse_name' => '仓库负责人回归', 'warehouse_type' => 'general', 'status' => 'enabled'], ...$overrides];
    }
}
