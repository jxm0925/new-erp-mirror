<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Item;
use App\Models\Erp\Unit;
use App\Models\Erp\WorkOrder;
use App\Services\Erp\CuttingRecordService;
use App\Services\Erp\RbacBootstrapService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CuttingConfigurationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_http_lifecycle_keeps_published_versions_immutable_and_replay_safe(): void
    {
        app(RbacBootstrapService::class)->bootstrap();
        $admin = $this->user('cfg-admin', true);
        $token = $this->token($admin);
        $item = $this->customItem('CFG-LIFE');

        $createPayload = $this->command(0) + [
            'item_id' => $item->id,
            'dimensions' => ['width_mm' => '80', 'length_mm' => '100.5'],
            'drawing_reference' => 'DRAWING-A-R1',
            'scope_mode' => 'PUBLIC',
            'scope_work_order_ids' => [],
        ];
        $created = $this->withToken($token)->postJson('/api/v1/erp/production/cutting/configurations', $createPayload)
            ->assertCreated()->assertJsonPath('message', '配置草稿已建立')
            ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.version_no', 1)
            ->assertJsonPath('data.business_version', 1)->assertJsonPath('data.dimensions.length_mm', '100.50000000')
            ->json('data');
        $id = (int) $created['id'];

        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/configurations?status=DRAFT&keyword='.$item->item_code)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/configurations/'.$id)
            ->assertOk()->assertJsonPath('data.configuration_no', $created['configuration_no']);

        $updated = $this->withToken($token)->putJson('/api/v1/erp/production/cutting/configurations/'.$id, $this->command(1) + [
            'dimensions' => ['length_mm' => '101', 'width_mm' => '81', 'thickness_mm' => '2'],
            'drawing_reference' => 'DRAWING-A-R2', 'scope_mode' => 'PUBLIC', 'scope_work_order_ids' => [],
        ])->assertOk()->assertJsonPath('message', '配置草稿已保存')
            ->assertJsonPath('data.business_version', 2)->assertJsonPath('data.drawing_reference', 'DRAWING-A-R2')->json('data');

        $publishPayload = $this->command(2);
        $publishedResponse = $this->withToken($token)->postJson('/api/v1/erp/production/cutting/configurations/'.$id.'/publish', $publishPayload)
            ->assertOk()->assertJsonPath('message', '配置版本已发布')->assertJsonPath('data.status', 'PUBLISHED')
            ->assertJsonPath('data.business_version', 3);
        $published = $publishedResponse->json('data');
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/configurations/'.$id.'/publish', $publishPayload)
            ->assertOk()->assertExactJson($publishedResponse->json());

        $this->withToken($token)->putJson('/api/v1/erp/production/cutting/configurations/'.$id, $this->command(3) + [
            'dimensions' => ['length_mm' => '999'], 'drawing_reference' => 'MUTATION',
            'scope_mode' => 'PUBLIC', 'scope_work_order_ids' => [],
        ])->assertStatus(409)->assertJsonPath('error_code', 'configuration_immutable');

        $versionPayload = $this->command(3);
        $nextResponse = $this->withToken($token)->postJson('/api/v1/erp/production/cutting/configurations/'.$id.'/versions', $versionPayload)
            ->assertCreated()->assertJsonPath('data.version_no', 2)->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.business_version', 1)->assertJsonPath('data.source_configuration_id', $id);
        $next = $nextResponse->json('data');
        $this->assertNotSame($id, (int) $next['id']);
        $this->assertSame($published['configuration_no'], $next['configuration_no']);
        $this->assertSame($published['dimensions'], $next['dimensions']);
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/configurations/'.$id.'/versions', $versionPayload)
            ->assertCreated()->assertExactJson($nextResponse->json());
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/configurations/'.$id.'/versions', $this->command(3))
            ->assertStatus(409)->assertJsonPath('error_code', 'configuration_draft_exists')
            ->assertJsonPath('details.draft_id', (int) $next['id']);

        $v1 = DB::table('erp_custom_configurations')->where('id', $id)->first();
        $this->assertSame('PUBLISHED', $v1->status);
        $this->assertSame('DRAWING-A-R2', $v1->drawing_reference);
        $this->assertSame(3, (int) $v1->business_version);
        $this->assertSame(4, DB::table('erp_cutting_events')->where('aggregate_type', 'configuration')->count());
        $this->assertSame(4, DB::table('erp_cutting_commands')->whereIn('command_type', [
            'create_cutting_configuration', 'update_cutting_configuration', 'publish_cutting_configuration', 'version_cutting_configuration',
        ])->count());
        $this->assertNotNull($updated['updated_at']);
    }

    public function test_restricted_scope_is_real_permission_filtered_and_published_config_is_usable_only_for_registered_work_orders(): void
    {
        app(RbacBootstrapService::class)->bootstrap();
        $admin = $this->user('cfg-root', true);
        $owner = $this->user('cfg-owner');
        $outsider = $this->user('cfg-outsider');
        $item = $this->customItem('CFG-SCOPE');
        $ownerWorkOrder = $this->workOrder($item, $owner, 'OWNER');
        $otherWorkOrder = $this->workOrder($item, $outsider, 'OTHER');
        $unregisteredWorkOrder = $this->workOrder($item, $owner, 'UNREG');
        $adminToken = $this->token($admin);
        $ownerToken = $this->token($owner, ['production.cutting.view', 'production.cutting.material_manage'], 'self');
        $outsiderToken = $this->token($outsider, ['production.cutting.view'], 'self');

        $created = $this->withToken($adminToken)->postJson('/api/v1/erp/production/cutting/configurations', $this->command(0) + [
            'item_id' => $item->id, 'dimensions' => ['length_mm' => '120', 'width_mm' => '60'],
            'drawing_reference' => 'SCOPE-DRAWING-R1', 'scope_mode' => 'RESTRICTED',
            'scope_work_order_ids' => [$otherWorkOrder->id, $ownerWorkOrder->id],
        ])->assertCreated()->assertJsonCount(2, 'data.scope_work_orders')->json('data');
        $id = (int) $created['id'];

        $this->withToken($ownerToken)->getJson('/api/v1/erp/production/cutting/configurations?item_id='.$item->id)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->withToken($outsiderToken)->getJson('/api/v1/erp/production/cutting/configurations/'.$id)->assertOk();

        $third = $this->user('cfg-third');
        $thirdToken = $this->token($third, ['production.cutting.view'], 'self');
        $this->withToken($thirdToken)->getJson('/api/v1/erp/production/cutting/configurations?item_id='.$item->id)
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->withToken($thirdToken)->getJson('/api/v1/erp/production/cutting/configurations/'.$id)
            ->assertForbidden()->assertJsonPath('error_code', 'data_scope_denied');

        // Seeing one registered work order is sufficient for read, but changing a
        // multi-work-order configuration requires authority over every old scope.
        $this->withToken($ownerToken)->putJson('/api/v1/erp/production/cutting/configurations/'.$id, $this->command(1) + [
            'dimensions' => ['length_mm' => '121'], 'drawing_reference' => 'TAKEOVER',
            'scope_mode' => 'RESTRICTED', 'scope_work_order_ids' => [$ownerWorkOrder->id],
        ])->assertForbidden()->assertJsonPath('error_code', 'data_scope_denied');

        $published = $this->withToken($adminToken)->postJson('/api/v1/erp/production/cutting/configurations/'.$id.'/publish', $this->command(1))
            ->assertOk()->assertJsonPath('data.status', 'PUBLISHED')->json('data');
        $service = app(CuttingRecordService::class);
        $service->configuration($id, $item, $ownerWorkOrder->id);
        $service->configuration($id, $item, $otherWorkOrder->id);
        $this->domain('configuration_scope_denied', fn () => $service->configuration($id, $item, $unregisteredWorkOrder->id), 403);
        $this->assertSame('RESTRICTED', $published['scope_mode']);
    }

    public function test_configuration_payload_rejects_unstructured_dimensions_invalid_items_and_invalid_scope_combinations(): void
    {
        app(RbacBootstrapService::class)->bootstrap();
        $admin = $this->user('cfg-validation', true);
        $token = $this->token($admin);
        $unit = $this->unit('CFG-BAD');
        $ordinary = Item::create(['item_code' => 'CFG-BAD-'.Str::ulid(), 'item_name' => '普通物料', 'item_type' => 'raw_material',
            'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => false, 'is_custom_item' => false, 'status' => 'enabled']);

        $base = $this->command(0) + ['item_id' => $ordinary->id, 'dimensions' => ['length_mm' => '100'],
            'drawing_reference' => 'DRAWING', 'scope_mode' => 'PUBLIC', 'scope_work_order_ids' => []];
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/configurations', $base)
            ->assertUnprocessable()->assertJsonPath('error_code', 'configuration_item_ineligible');

        $item = $this->customItem('CFG-VALID');
        $base['item_id'] = $item->id;
        $base['client_command_id'] = (string) Str::uuid();
        $base['dimensions'] = ['color' => 'red'];
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/configurations', $base)
            ->assertUnprocessable()->assertJsonPath('error_code', 'configuration_dimensions_invalid');

        $base['client_command_id'] = (string) Str::uuid();
        $base['dimensions'] = ['length_mm' => 100.5];
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/configurations', $base)
            ->assertUnprocessable()->assertJsonPath('error_code', 'decimal_invalid');

        $base['client_command_id'] = (string) Str::uuid();
        $base['dimensions'] = ['length_mm' => '100'];
        $base['scope_work_order_ids'] = [999999999];
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/configurations', $base)
            ->assertUnprocessable()->assertJsonPath('error_code', 'configuration_public_scope_invalid');

        $base['client_command_id'] = (string) Str::uuid();
        $base['scope_mode'] = 'RESTRICTED';
        $base['scope_work_order_ids'] = [];
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/configurations', $base)
            ->assertUnprocessable()->assertJsonPath('error_code', 'configuration_scopes_required');
        $this->assertSame(0, DB::table('erp_custom_configurations')->where('item_id', $item->id)->count());
    }

    private function user(string $prefix, bool $admin = false): object
    {
        $user = (object) ['legacy_id' => random_int(100000000, 999999999), 'username' => $prefix.'-'.Str::ulid()];
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $user->legacy_id, 'username' => $user->username,
            'status' => 'normal', 'auth_group_names' => json_encode($admin ? ['Admin group'] : []),
            'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    private function token(object $user, array $permissions = [], string $scope = 'all'): string
    {
        if ($permissions !== []) {
            $role = DB::table('erp_rbac_roles')->insertGetId(['code' => 'cfg-role-'.Str::ulid(), 'name' => '配置测试角色',
                'data_scope' => $scope, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($permissions as $code) {
                $permission = DB::table('erp_rbac_permissions')->where('code', $code)->value('id');
                DB::table('erp_rbac_role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
            }
            DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $user->legacy_id, 'role_id' => $role]);
        }
        $token = Str::random(64);
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $user->legacy_id, 'token_hash' => hash('sha256', $token),
            'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addHour()]);

        return $token;
    }

    private function unit(string $prefix): Unit
    {
        return Unit::create(['unit_code' => $prefix.'-U-'.Str::ulid(), 'unit_name' => '件', 'unit_type' => 'count',
            'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
    }

    private function customItem(string $prefix): Item
    {
        $unit = $this->unit($prefix);

        return Item::create(['item_code' => $prefix.'-I-'.Str::ulid(), 'item_name' => '定制下料产品', 'item_type' => 'semi_finished',
            'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'is_custom_item' => true, 'status' => 'enabled']);
    }

    private function workOrder(Item $item, object $owner, string $suffix): WorkOrder
    {
        return WorkOrder::create(['work_order_no' => 'CFG-WO-'.$suffix.'-'.Str::ulid(), 'source_type' => 'stock_prebuild',
            'output_item_id' => $item->id, 'target_qty' => 1, 'target_base_qty' => 1, 'target_unit_id' => $item->unit_id,
            'base_unit_id' => $item->unit_id, 'status' => 'RELEASED', 'business_version' => 1,
            'responsible_user_legacy_id' => $owner->legacy_id, 'created_by_legacy_id' => $owner->legacy_id,
            'updated_by_legacy_id' => $owner->legacy_id]);
    }

    private function command(int $version): array
    {
        return ['client_command_id' => (string) Str::uuid(), 'expected_version' => $version];
    }

    private function domain(string $code, callable $action, int $status = 422): void
    {
        try {
            $action();
            $this->fail('Expected '.$code);
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);
        }
    }
}
