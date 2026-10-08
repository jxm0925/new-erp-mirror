<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{Item, ItemCategory, ItemMaterialPolicy, ProductionOperation, ProductionRouting, ProductionRoutingOperation, Unit};
use App\Services\Erp\{AuthContextService, MaterialPolicyApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MaterialPolicyManagementScopeTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;
    private Unit $unit;
    private array $permissions = ['master.item.view', 'master.item.create', 'master.item.edit'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'MPS-'.Str::upper(Str::random(8));
        $this->unit = Unit::create(['unit_code' => $this->code('U'), 'unit_name' => '策略验证单位',
            'unit_type' => 'quantity', 'status' => 'enabled', 'is_legacy' => false, 'allow_decimal' => false, 'decimal_places' => 0]);
        $user = (object) ['legacy_id' => 99, 'username' => 'policy_scope_tester', 'nickname' => '策略验证员', 'auth_group_names' => '[]'];
        $this->mock(AuthContextService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('currentUser')->andReturn($user);
            $mock->shouldReceive('currentLegacyId')->andReturn(99);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
        });
    }

    public function test_office_drafts_keep_inventory_expense_custody_return_asset_and_direct_expense_available(): void
    {
        $cases = [
            $this->policy('inventory'),
            $this->policy('expense', ['future_bearer_type' => 'department']),
            $this->policy('inventory', ['requires_custodian' => true, 'is_returnable' => true, 'future_bearer_type' => 'employee']),
            $this->policy('asset', ['requires_capitalization' => true, 'requires_custodian' => true, 'serial_tracking_mode' => 'required']),
            $this->policy('direct_expense', ['future_bearer_type' => 'department']),
        ];
        foreach ($cases as $payload) {
            $item = $this->item('office');
            $before = $item->getRawOriginal();
            $saved = $this->putJson($this->policyUrl($item).'/draft', $payload)->assertOk()->json('data');
            $draft = ItemMaterialPolicy::findOrFail($saved['id']);
            $this->assertSame('draft', $draft->status);
            $this->assertSame(0, (int) $draft->version_no);
            foreach (['is_stock_managed', 'inventory_management_mode', 'post_purchase_action',
                'consumption_confirmation_mode', 'future_route', 'future_bearer_type', 'requires_custodian',
                'is_returnable', 'requires_capitalization', 'serial_tracking_mode'] as $field) {
                $this->assertSame($payload[$field], $draft->getAttribute($field));
                $this->assertSame($payload[$field], $draft->parameter_snapshot[$field]);
            }
            $this->assertSame($before, $item->fresh()->getRawOriginal());
        }
    }

    public function test_office_activation_syncs_operational_flags_and_keeps_prior_policy_parameters(): void
    {
        $item = $this->item('office');
        $inventory = $this->policy('inventory');
        $draft = $this->putJson($this->policyUrl($item).'/draft', $inventory)->assertOk()->json('data');
        $first = $this->postJson($this->policyUrl($item).'/activate', $inventory)->assertOk()->json('data');
        $this->assertSame(1, (int) $first['version_no']);
        $this->assertSame('historical', ItemMaterialPolicy::findOrFail($draft['id'])->status);
        $firstParameters = ItemMaterialPolicy::findOrFail($first['id'])->parameter_snapshot;

        $asset = $this->policy('asset', ['requires_capitalization' => true, 'requires_custodian' => true,
            'is_returnable' => true, 'serial_tracking_mode' => 'required', 'future_bearer_type' => 'employee']);
        $second = $this->postJson($this->policyUrl($item).'/activate', $asset)->assertOk()->json('data');
        $this->assertSame(2, (int) $second['version_no']);
        $this->assertSame('active', $second['status']);
        $this->assertSame('historical', ItemMaterialPolicy::findOrFail($first['id'])->status);
        $this->assertSame($firstParameters, ItemMaterialPolicy::findOrFail($first['id'])->parameter_snapshot);
        $this->assertSame(1, $item->materialPolicies()->where('status', 'active')->count());
        $this->assertFalse($item->fresh()->is_stock_item);
        $this->assertTrue($item->fresh()->is_serial_managed);
        $this->assertSame('required', $item->fresh()->serialTrackingMode());
        $this->assertFalse($item->fresh()->is_production_item);
        $this->assertSame('office', $item->fresh()->managementScope());
        $this->assertSame('before_finished_goods_posting', $item->fresh()->serial_generation_stage);
        $this->assertNull($item->fresh()->serial_generation_routing_operation_id);
    }

    public function test_office_policy_without_optional_production_fields_keeps_the_existing_default(): void
    {
        $item = $this->item('office');
        $payload = $this->policy('inventory');
        unset($payload['production_execution_mode'], $payload['serial_generation_stage'], $payload['serial_generation_routing_operation_id']);
        $this->putJson($this->policyUrl($item).'/draft', $payload)->assertOk();
        $this->postJson($this->policyUrl($item).'/activate', $payload)->assertOk();
        $this->assertSame('before_finished_goods_posting', $item->fresh()->serial_generation_stage);
        $this->assertNull($item->fresh()->serial_generation_routing_operation_id);
        $this->assertSame('unit', $item->fresh()->production_execution_mode);
    }

    public function test_office_rejects_order_bearers_cost_intents_and_production_references_on_both_policy_endpoints(): void
    {
        $item = $this->item('office');
        $node = $this->routingNode($this->item('factory', ['is_production_item' => true]));
        $valid = $this->policy('inventory');
        $this->putJson($this->policyUrl($item).'/draft', $valid)->assertOk();
        $this->postJson($this->policyUrl($item).'/activate', $valid)->assertOk();
        $beforeItem = $item->fresh()->getRawOriginal();
        $beforePolicies = $this->policyRows($item);
        $changes = [
            ['future_bearer_type' => 'work_order'], ['future_bearer_type' => 'sales_order'],
            ['future_route' => 'work_order_cost'], ['future_route' => 'sales_order_direct_cost'],
            ['post_purchase_action' => 'work_order_cost'], ['post_purchase_action' => 'sales_order_direct_cost'],
            ['serial_generation_stage' => 'production_unit_created'],
            ['serial_generation_stage' => 'routing_operation_completed', 'serial_generation_routing_operation_id' => $node->id],
            // The default stage used to silently clear this otherwise valid FK.
            ['serial_generation_routing_operation_id' => $node->id],
        ];
        foreach ($changes as $change) {
            $payload = array_replace($valid, $change);
            foreach (['draft', 'activate'] as $action) {
                $response = $action === 'draft'
                    ? $this->putJson($this->policyUrl($item).'/draft?management_scope=factory', $payload)
                    : $this->postJson($this->policyUrl($item).'/activate?management_scope=factory', $payload);
                $response->assertUnprocessable();
                $this->assertStringContainsString('办公物资', $response->json('message'));
                $this->assertSame($beforeItem, $item->fresh()->getRawOriginal());
                $this->assertSame($beforePolicies, $this->policyRows($item));
            }
        }
    }

    public function test_four_common_intents_reject_crossed_stock_actions_modes_and_confirmations_for_both_scopes(): void
    {
        $invalid = [
            $this->policy('inventory', ['inventory_management_mode' => 'none']),
            $this->policy('inventory', ['post_purchase_action' => 'issue_confirmation']),
            $this->policy('inventory', ['consumption_confirmation_mode' => 'issue']),
            $this->policy('expense', ['is_stock_managed' => false]),
            $this->policy('expense', ['post_purchase_action' => 'inventory_receipt']),
            $this->policy('expense', ['consumption_confirmation_mode' => 'none']),
            $this->policy('asset', ['inventory_management_mode' => 'standard']),
            $this->policy('asset', ['consumption_confirmation_mode' => 'none']),
            $this->policy('direct_expense', ['inventory_management_mode' => 'standard']),
            $this->policy('direct_expense', ['consumption_confirmation_mode' => 'issue']),
        ];
        foreach (['office', 'factory'] as $scope) {
            $item = $this->item($scope);
            $before = $item->getRawOriginal();
            foreach ($invalid as $payload) {
                $this->putJson($this->policyUrl($item).'/draft', $payload)->assertUnprocessable();
                $this->postJson($this->policyUrl($item).'/activate', $payload)->assertUnprocessable();
            }
            $this->assertSame(0, $item->materialPolicies()->count());
            $this->assertSame($before, $item->fresh()->getRawOriginal());
        }
    }

    public function test_return_and_capitalization_constraints_still_reject_inconsistent_office_policies(): void
    {
        $item = $this->item('office');
        foreach ([['is_returnable' => true], ['requires_capitalization' => true]] as $change) {
            $payload = $this->policy('inventory', $change);
            $this->putJson($this->policyUrl($item).'/draft', $payload)->assertUnprocessable();
            $this->postJson($this->policyUrl($item).'/activate', $payload)->assertUnprocessable();
        }
        $this->assertSame(0, $item->materialPolicies()->count());
    }

    public function test_integrated_office_creation_rolls_back_the_item_policy_and_scope_audit_when_policy_is_invalid(): void
    {
        $template = $this->item('office');
        $before = [Item::count(), ItemMaterialPolicy::count(), DB::table('erp_operation_logs')->count()];
        foreach ([false, true] as $activate) {
            $itemPayload = array_replace($this->itemPayload($template), ['item_code' => $this->code('NEW'), 'item_name' => '不应保留的办公资产']);
            $payload = ['activate' => $activate, 'item' => $itemPayload,
                'policy' => $this->policy('asset', ['future_bearer_type' => 'work_order', 'serial_tracking_mode' => 'required'])];
            $response = $this->postJson('/api/v1/erp/master/items/integrated-form?management_scope=office', $payload)->assertUnprocessable();
            $this->assertStringContainsString('办公物资', $response->json('message'));
            $this->assertDatabaseMissing('erp_items', ['item_code' => $itemPayload['item_code']]);
            $this->assertSame($before, [Item::count(), ItemMaterialPolicy::count(), DB::table('erp_operation_logs')->count()]);
        }
    }

    public function test_integrated_scope_correction_uses_the_saved_scope_and_rolls_back_all_item_and_policy_changes(): void
    {
        $item = $this->item('factory', ['status' => 'disabled']);
        $this->postJson($this->policyUrl($item).'/activate', $this->policy('inventory'))->assertOk();
        $beforeItem = $item->fresh()->getRawOriginal();
        $beforePolicies = $this->policyRows($item);
        $beforeLogs = DB::table('erp_operation_logs')->count();
        $category = $this->category('office');
        foreach ([false, true] as $activate) {
            $itemPayload = array_replace($this->itemPayload($item), ['management_scope' => 'office', 'item_type' => 'office_consumable',
                'category_id' => $category->id, 'item_name' => '事务必须回滚的修改', 'status' => 'disabled']);
            $payload = ['activate' => $activate, 'item' => $itemPayload,
                'policy' => $this->policy('asset', ['future_bearer_type' => 'sales_order', 'serial_tracking_mode' => 'required'])];
            $response = $this->putJson('/api/v1/erp/master/items/'.$item->id.'/integrated-form?management_scope=factory', $payload)
                ->assertUnprocessable();
            $this->assertStringContainsString('办公物资', $response->json('message'));
            $this->assertSame($beforeItem, $item->fresh()->getRawOriginal());
            $this->assertSame($beforePolicies, $this->policyRows($item));
            $this->assertSame($beforeLogs, DB::table('erp_operation_logs')->count());
        }
    }

    public function test_factory_serial_generation_stages_and_item_owned_routing_reference_remain_available(): void
    {
        $item = $this->item('factory', ['is_production_item' => true]);
        $node = $this->routingNode($item);
        $otherNode = $this->routingNode($this->item('factory', ['is_production_item' => true]));
        $payload = $this->policy('inventory', ['serial_tracking_mode' => 'required', 'production_execution_mode' => 'quantity',
            'serial_generation_stage' => 'production_unit_created', 'future_bearer_type' => 'work_order']);
        $first = $this->postJson($this->policyUrl($item).'/activate', $payload)->assertOk()->json('data');
        $this->assertSame('production_unit_created', $item->fresh()->serial_generation_stage);
        $this->assertSame('quantity', $item->fresh()->production_execution_mode);
        $before = $this->policyRows($item);
        $payload['serial_generation_stage'] = 'routing_operation_completed';
        $payload['serial_generation_routing_operation_id'] = $otherNode->id;
        $this->postJson($this->policyUrl($item).'/activate', $payload)->assertUnprocessable()
            ->assertJsonPath('message', '指定的设备编号生成工序不属于该产出物料的工艺路线。');
        $this->assertSame($before, $this->policyRows($item));
        $payload['serial_generation_routing_operation_id'] = $node->id;
        $second = $this->postJson($this->policyUrl($item).'/activate', $payload)->assertOk()->json('data');
        $this->assertSame(2, (int) $second['version_no']);
        $this->assertSame($node->id, $item->fresh()->serial_generation_routing_operation_id);
        $this->assertSame('historical', ItemMaterialPolicy::findOrFail($first['id'])->status);
        $payload['serial_generation_stage'] = 'before_finished_goods_posting';
        $this->postJson($this->policyUrl($item).'/activate', $payload)->assertOk();
        $this->assertNull($item->fresh()->serial_generation_routing_operation_id);
    }

    public function test_factory_reserved_cost_intents_keep_their_existing_configuration_compatibility(): void
    {
        foreach (['work_order_cost' => 'work_order', 'sales_order_direct_cost' => 'sales_order'] as $route => $bearer) {
            $item = $this->item('factory');
            $payload = $this->policy('direct_expense', ['future_route' => $route, 'post_purchase_action' => $route, 'future_bearer_type' => $bearer]);
            $this->putJson($this->policyUrl($item).'/draft', $payload)->assertOk();
            $active = $this->postJson($this->policyUrl($item).'/activate', $payload)->assertOk()->json('data');
            $this->assertSame($route, $active['future_route']);
            $this->assertSame($route, $active['post_purchase_action']);
            $this->assertSame($bearer, $active['future_bearer_type']);
            $this->assertSame($route, $active['parameter_snapshot']['future_route']);
            $this->assertFalse($item->fresh()->is_stock_item);
        }
    }

    public function test_scope_does_not_grant_policy_or_integrated_item_write_permission(): void
    {
        $item = $this->item('office');
        $beforeItem = $item->getRawOriginal();
        $this->useRoleWithoutPermissions();
        $policy = $this->policy('inventory');
        $this->putJson($this->policyUrl($item).'/draft?management_scope=office', $policy)->assertForbidden();
        $this->postJson($this->policyUrl($item).'/activate?management_scope=office', $policy)->assertForbidden();
        $integrated = ['activate' => true, 'item' => $this->itemPayload($item), 'policy' => $policy];
        $this->putJson('/api/v1/erp/master/items/'.$item->id.'/integrated-form?management_scope=office', $integrated)->assertForbidden();
        $integrated['item']['item_code'] = $this->code('DENIED');
        $this->postJson('/api/v1/erp/master/items/integrated-form?management_scope=office', $integrated)->assertForbidden();
        $this->assertDatabaseMissing('erp_items', ['item_code' => $integrated['item']['item_code']]);
        $this->assertSame(0, $item->materialPolicies()->count());
        $this->assertSame($beforeItem, $item->fresh()->getRawOriginal());
    }

    public function test_public_policy_service_rechecks_scope_after_locking_instead_of_using_a_stale_item(): void
    {
        $staleItem = $this->item('factory');
        $officeCategory = $this->category('office');
        Item::whereKey($staleItem->id)->update(['management_scope' => 'office', 'category_id' => $officeCategory->id]);
        $this->assertSame('factory', $staleItem->managementScope());
        $before = $staleItem->fresh()->getRawOriginal();
        try {
            app(MaterialPolicyApplicationService::class)->saveDraft($staleItem,
                $this->policy('inventory', ['future_bearer_type' => 'work_order']), 99);
            $this->fail('A stale factory Item must not bypass the locked office scope.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('办公物资', $exception->getMessage());
        }
        $this->assertSame(0, $staleItem->materialPolicies()->count());
        $this->assertSame($before, $staleItem->fresh()->getRawOriginal());
    }

    private function policy(string $route, array $changes = []): array
    {
        $settings = match ($route) {
            'inventory' => [true, 'standard', 'inventory_receipt', 'none'],
            'expense' => [true, 'standard', 'issue_confirmation', 'issue'],
            'asset' => [false, 'none', 'asset_acceptance', 'asset_acceptance'],
            'direct_expense' => [false, 'none', 'expense_confirmation', 'none'],
        };
        return array_replace(['is_stock_managed' => $settings[0], 'inventory_management_mode' => $settings[1],
            'post_purchase_action' => $settings[2], 'consumption_confirmation_mode' => $settings[3], 'future_route' => $route,
            'requires_custodian' => false, 'is_returnable' => false, 'requires_capitalization' => false,
            'serial_tracking_mode' => 'none', 'production_execution_mode' => 'unit',
            'serial_generation_stage' => 'before_finished_goods_posting', 'serial_generation_routing_operation_id' => null,
            'future_bearer_type' => 'company', 'change_reason' => '策略范围回归验证'], $changes);
    }

    private function itemPayload(Item $item): array
    {
        return ['item_code' => $item->item_code, 'item_name' => $item->item_name, 'item_type' => $item->item_type,
            'management_scope' => $item->managementScope(), 'category_id' => $item->category_id, 'unit_id' => $item->unit_id,
            'cutting_mode' => 'none', 'material_management_mode' => 'quantity', 'is_length_cut_material' => false,
            'is_purchase_item' => true, 'is_stock_item' => true, 'is_production_item' => false,
            'manufacturing_strategy' => 'unspecified', 'cost_method' => 'weighted_average', 'status' => 'enabled'];
    }

    private function item(string $scope, array $changes = []): Item
    {
        return Item::create(array_replace(['item_code' => $this->code('I'), 'item_name' => '策略范围验证物料',
            'item_type' => $scope === 'office' ? 'office_consumable' : 'raw_material', 'management_scope' => $scope,
            'category_id' => $this->category($scope)->id, 'unit_id' => $this->unit->id,
            'is_purchase_item' => true, 'is_stock_item' => true, 'is_production_item' => false,
            'cutting_mode' => 'none', 'material_management_mode' => 'quantity', 'is_length_cut_material' => false,
            'cost_method' => 'weighted_average', 'status' => 'enabled'], $changes))->fresh();
    }

    private function category(string $scope): ItemCategory
    {
        return ItemCategory::create(['category_code' => $this->code('C'), 'category_name' => '策略范围验证类目',
            'category_type' => 'item', 'management_scope' => $scope, 'parent_id' => null, 'status' => 'enabled']);
    }

    private function routingNode(Item $item): ProductionRoutingOperation
    {
        $operation = ProductionOperation::create(['operation_no' => $this->code('OP'), 'operation_name' => '策略验证工序', 'status' => 'enabled']);
        $routing = ProductionRouting::create(['routing_no' => $this->code('RT'), 'routing_name' => '策略验证路线',
            'output_item_id' => $item->id, 'version' => 1, 'status' => 'draft', 'is_default' => false]);
        return ProductionRoutingOperation::create(['routing_id' => $routing->id, 'operation_id' => $operation->id,
            'sequence' => 10, 'output_item_id' => $item->id]);
    }

    private function policyRows(Item $item): array
    {
        return DB::table('erp_item_material_policies')->where('item_id', $item->id)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();
    }

    private function useRoleWithoutPermissions(): void
    {
        $id = random_int(9500000, 9599999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $id, 'username' => $this->code('USER'),
            'nickname' => '无策略维护权限验证员', 'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $role = DB::table('erp_rbac_roles')->insertGetId(['code' => $this->code('ROLE'), 'name' => '无策略维护权限角色',
            'data_scope' => 'self', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $id, 'role_id' => $role]);
        $token = $this->code('TOKEN');
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $auth = new AuthContextService();
        $this->app->instance(AuthContextService::class, $auth);
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->first();
        $this->assertFalse($auth->isSuperAdmin($user));
        $this->assertSame([], $auth->permissionCodes($user));
        $this->withToken($token);
    }

    private function policyUrl(Item $item): string { return '/api/v1/erp/master/items/'.$item->id.'/material-policy'; }
    private function code(string $kind): string { return $this->prefix.'-'.$kind.'-'.Str::upper(Str::random(5)); }
}
