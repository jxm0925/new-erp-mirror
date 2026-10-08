<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{Item, ProductionOperation, ProductionPackagingScheme, ProductionRouting, RoutingOperationOutputRule, Unit};
use App\Services\Erp\{DocumentNumberService, ProductionMasterDataService, RbacBootstrapService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RoutingOperationOutputRuleTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = [
        'production.routing.view', 'production.routing.create', 'production.routing.edit',
        'production.routing.activate', 'production.routing.delete',
    ];

    private object $manager;
    private Unit $unit;
    private Item $reference;
    private Item $secondProduct;
    private Item $byProduct;
    private ProductionOperation $operation;

    protected function setUp(): void
    {
        parent::setUp();
        app(RbacBootstrapService::class)->bootstrap(true);
        $id = random_int(4300000, 4399999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $id, 'username' => $this->code('USER'),
            'nickname' => '工序产出规则核对员', 'status' => 'normal', 'auth_group_names' => '[]', 'department_names' => '[]',
            'created_at' => now(), 'updated_at' => now()]);
        $this->manager = DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->first();
        $role = DB::table('erp_rbac_roles')->insertGetId(['code' => $this->code('ROLE'), 'name' => '工序产出规则角色',
            'enabled' => true, 'data_scope' => 'all', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('erp_rbac_permissions')->whereIn('code', self::PERMISSIONS)->pluck('id') as $permission) {
            DB::table('erp_rbac_role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $id, 'role_id' => $role]);
        $token = Str::random(48);
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($token);
        DB::table('erp_document_number_rules')->updateOrInsert(['document_type' => 'routing'], ['name' => '工艺路线',
            'prefix' => 'RT', 'date_format' => 'Ymd', 'sequence_length' => 5, 'reset_cycle' => 'daily', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $this->unit = $this->unit('件', 0);
        $this->reference = $this->item($this->unit, '参考成品');
        $this->secondProduct = $this->item($this->unit, '并列成品');
        $this->byProduct = $this->item($this->unit('kg', 4), '副产品', false);
        $this->operation = ProductionOperation::create(['operation_no' => $this->code('OP'), 'operation_name' => '公共加工',
            'status' => 'enabled', 'is_public' => true, 'auto_assignment_enabled' => true]);
    }

    public function test_http_saves_complete_multi_output_rules_without_reinterpreting_the_legacy_scalar(): void
    {
        $rules = [
            $this->rule($this->secondProduct, '0.125', ['quality_mode' => 'required', 'output_mode' => 'warehouse_required', 'allow_continue_without_warehouse' => false]),
            $this->rule($this->reference, '1'),
            $this->rule($this->byProduct, '0.00001250', ['output_role' => 'by_product', 'output_mode' => 'warehouse_optional']),
        ];
        $payload = $this->payload([$this->node($rules)]);
        $data = $this->postJson('/api/v1/erp/production/routings', $payload)->assertCreated()
            ->assertJsonCount(3, 'data.operations.0.output_rules')->json('data');
        $route = ProductionRouting::findOrFail($data['id']);
        $this->assertSame((int) $this->reference->id, (int) $data['operations'][0]['output_item_id']);
        $this->assertSame('none', $data['operations'][0]['quality_mode']);
        $this->assertTrue((bool) $data['operations'][0]['allow_continue_without_warehouse']);
        $snapshot = app(ProductionMasterDataService::class)->snapshot($route);
        $this->assertSame(array_column($rules, 'output_rule_key'), array_column($snapshot['operations'][0]['output_rules'], 'output_rule_key'));
        $this->assertSame(['0.12500000', '1.00000000', '0.00001250'], array_column($snapshot['operations'][0]['output_rules'], 'base_qty_per_reference_unit'));
        $this->assertSame(['product', 'product', 'by_product'], array_column($snapshot['operations'][0]['output_rules'], 'output_role'));
        foreach ($snapshot['operations'][0]['output_rules'] as $rule) {
            $this->assertSame($this->reference->id, $rule['reference_item_id']);
            $this->assertSame($this->unit->id, $rule['reference_base_unit_id']);
            $this->assertSame('件', $rule['reference_base_unit_name_snapshot']);
            $this->assertSame(0, $rule['reference_base_unit_decimal_places_snapshot']);
            $this->assertIsString($rule['base_qty_per_reference_unit']);
        }
        $this->assertSame('required', $snapshot['operations'][0]['output_rules'][0]['quality_mode']);
        $this->assertFalse($snapshot['operations'][0]['output_rules'][0]['allow_continue_without_warehouse']);
        $this->getJson('/api/v1/erp/production/routings/'.$route->id)->assertOk()
            ->assertJsonPath('data.operations.0.output_rules.2.base_unit_decimal_places_snapshot', 4);
        $audit = $this->commandSnapshot($payload['client_command_id']);
        $this->assertSame([], $audit['output_rule_change']['before']);
        $this->assertCount(3, $audit['output_rule_change']['after'][0]['output_rules']);
        $this->assertSame($this->manager->legacy_id, DB::table('erp_production_master_commands')->where('client_command_id', $payload['client_command_id'])->value('initiated_by_legacy_id'));
    }

    public function test_omission_preserves_keys_and_saved_labels_while_explicit_clear_and_replay_are_audited_once(): void
    {
        $rules = [$this->rule($this->reference, '1'), $this->rule($this->secondProduct, '2')];
        $route = $this->create([$this->node($rules)]);
        $node = $route->operations->sole();
        $before = app(ProductionMasterDataService::class)->snapshot($route)['operations'][0]['output_rules'];
        $this->secondProduct->update(['item_name' => '主档已改名']);
        $this->unit->update(['unit_name' => '当前新标签', 'decimal_places' => 3]);
        $omitted = $this->node(null) + ['id' => $node->id];
        $change = ['client_command_id' => $this->code('OMIT'), 'expected_version' => 1, 'operations' => [$omitted]];
        $this->putJson('/api/v1/erp/production/routings/'.$route->id, $change)->assertOk()
            ->assertJsonPath('data.business_version', 2)->assertJsonCount(2, 'data.operations.0.output_rules');
        $current = app(ProductionMasterDataService::class)->snapshot($route->fresh());
        $after = $current['operations'][0]['output_rules'];
        $this->assertSame(array_column($before, 'output_rule_key'), array_column($after, 'output_rule_key'));
        $this->assertNotSame($node->id, $current['operations'][0]['routing_operation_id']);
        $this->assertSame('并列成品', $after[1]['item_name_snapshot']);
        $this->assertSame('件', $after[1]['base_unit_name_snapshot']);
        $this->assertSame(0, $after[1]['base_unit_decimal_places_snapshot']);
        $this->assertSame(1, $after[1]['business_version']);
        $this->putJson('/api/v1/erp/production/routings/'.$route->id, $change)->assertOk()
            ->assertJsonPath('data.business_version', 2)->assertJsonCount(2, 'data.operations.0.output_rules');
        $this->assertSame(1, DB::table('erp_production_master_commands')->where('client_command_id', $change['client_command_id'])->count());
        $this->assertSame(2, RoutingOperationOutputRule::where('routing_id', $route->id)->count());
        $clear = ['client_command_id' => $this->code('CLEAR'), 'expected_version' => 2,
            'operations' => [$this->node([]) + ['id' => $current['operations'][0]['routing_operation_id']]]];
        $this->putJson('/api/v1/erp/production/routings/'.$route->id, $clear)->assertOk()->assertJsonCount(0, 'data.operations.0.output_rules');
        $this->assertSame(0, RoutingOperationOutputRule::where('routing_id', $route->id)->count());
        $clearAudit = $this->commandSnapshot($clear['client_command_id']);
        $this->assertCount(2, $clearAudit['output_rule_change']['before'][0]['output_rules']);
        $this->assertSame([], $clearAudit['output_rule_change']['after'][0]['output_rules']);
        $this->assertSame(3, $clearAudit['output_rule_change']['business_version']);
    }

    public function test_copied_versions_preserve_business_keys_but_rule_edits_are_isolated_from_the_source(): void
    {
        $rules = [$this->rule($this->reference, '1'), $this->rule($this->secondProduct, '2')];
        $source = $this->create([$this->node($rules)]);
        $master = app(ProductionMasterDataService::class);
        $source = $master->activateRouting($source->id, ['client_command_id' => $this->code('ACTIVE'), 'expected_version' => 1], $this->manager, self::PERMISSIONS, false);
        $frozen = $master->snapshot($source);
        $this->secondProduct->update(['item_name' => '复制前改名']);
        $copy = $master->copyRouting($source->id, ['client_command_id' => $this->code('COPY')], $this->manager, self::PERMISSIONS, false);
        $this->assertSame(2, $copy->version);
        $this->assertSame('draft', $copy->status);
        $copyRules = $master->snapshot($copy)['operations'][0]['output_rules'];
        $this->assertSame(array_column($rules, 'output_rule_key'), array_column($copyRules, 'output_rule_key'));
        $this->assertSame('并列成品', $copyRules[1]['item_name_snapshot']);
        $rules[1]['base_qty_per_reference_unit'] = '3.5';
        $copy = $master->updateRouting($copy->id, ['client_command_id' => $this->code('COPY-EDIT'), 'expected_version' => 1,
            'operations' => [$this->node($rules) + ['id' => $copy->operations->sole()->id]]], $this->manager, self::PERMISSIONS, false);
        $changed = $master->snapshot($copy)['operations'][0]['output_rules'];
        $this->assertSame('3.50000000', $changed[1]['base_qty_per_reference_unit']);
        $this->assertSame(2, $changed[1]['business_version']);
        $this->assertSame(1, $changed[0]['business_version']);
        $this->assertSame($frozen, $master->snapshot($source->fresh()));
        $this->assertSame('active', $source->fresh()->status);
    }

    public function test_invalid_numbers_unknown_fields_and_duplicate_rows_roll_back_the_entire_draft_command(): void
    {
        $route = $this->create([$this->node([$this->rule($this->reference, '1')])]);
        $before = app(ProductionMasterDataService::class)->snapshot($route);
        $good = $this->rule($this->secondProduct, '2');
        $invalidRules = [];
        foreach ([null, '', '0', '-1', '1e2', 0.125, '0.123456789', '100000000000000000000', true] as $value) {
            $invalidRules[] = [array_replace($good, ['base_qty_per_reference_unit' => $value])];
        }
        $invalidRules[] = [$good + ['base_unit_id' => $this->unit->id]];
        $invalidRules[] = [$good, $good];
        $invalidRules[] = [$good, $this->rule($this->secondProduct, '3')];
        $invalidRules[] = [array_replace($good, ['quality_mode' => 'optional'])];
        $invalidRules[] = [array_replace($good, ['allow_continue_without_warehouse' => null])];
        foreach ($invalidRules as $rows) {
            $command = $this->code('INVALID');
            $this->putJson('/api/v1/erp/production/routings/'.$route->id, ['client_command_id' => $command,
                'expected_version' => 1, 'operations' => [$this->node($rows) + ['id' => $route->operations->sole()->id]]])->assertUnprocessable();
            $this->assertSame($before, app(ProductionMasterDataService::class)->snapshot($route->fresh()));
            $this->assertFalse(DB::table('erp_production_master_commands')->where('client_command_id', $command)->exists());
        }
    }

    public function test_rule_keys_cannot_move_between_nodes_or_material_identities(): void
    {
        $rule = $this->rule($this->reference, '1');
        $route = $this->create([$this->node([$rule]), $this->node([], 20)]);
        $first = $route->operations->first();
        $second = $route->operations->last();
        $this->assertRejected($route, ['operations' => [
            $this->node([]) + ['id' => $first->id], $this->node([$rule], 20) + ['id' => $second->id],
        ]]);
        $this->assertRejected($route, ['operations' => [$this->node([array_replace($rule, ['item_id' => $this->secondProduct->id])]) + ['id' => $first->id]]]);
        $otherOperation = ProductionOperation::create(['operation_no' => $this->code('OTHER-OP'), 'operation_name' => '替换工序', 'status' => 'enabled']);
        $this->assertRejected($route, ['operations' => [array_replace($this->node([$rule]), ['id' => $first->id, 'operation_id' => $otherOperation->id])]]);
        try {
            $this->create([$this->node([$rule])]);
            $this->fail('其他路线不能借用已有规则标识。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operations', $exception->errors());
        }
        $this->assertSame(1, $route->fresh()->business_version);
        $this->assertSame($this->reference->id, RoutingOperationOutputRule::where('routing_id', $route->id)->sole()->item_id);
    }

    public function test_reference_and_output_base_unit_identity_changes_require_new_rule_keys(): void
    {
        $rule = $this->rule($this->secondProduct, '2');
        $route = $this->create([$this->node([$rule])]);
        $this->assertRejected($route, ['output_item_id' => $this->secondProduct->id]);
        $replacement = $this->unit('另一个件单位', 0);
        $this->secondProduct->update(['unit_id' => $replacement->id]);
        $this->assertRejected($route, ['operations' => [$this->node([$rule]) + ['id' => $route->operations->sole()->id]]]);
        $rule['output_rule_key'] = (string) Str::uuid();
        $revised = app(ProductionMasterDataService::class)->updateRouting($route->id, [
            'client_command_id' => $this->code('NEW-KEY'), 'expected_version' => 1,
            'operations' => [$this->node([$rule]) + ['id' => $route->operations->sole()->id]],
        ], $this->manager, self::PERMISSIONS, false);
        $this->assertSame($replacement->id, $revised->operations->sole()->outputRules->sole()->base_unit_id);
        $referenceRule = $this->rule($this->reference, '1');
        $referenceRoute = $this->create([$this->node([$referenceRule])]);
        $this->reference->update(['unit_id' => $replacement->id]);
        $this->assertRejected($referenceRoute, ['routing_name' => '只改名称也不重新解释分母']);
    }

    public function test_product_and_byproduct_flags_and_units_are_revalidated_before_activation(): void
    {
        $this->byProduct->update(['item_type' => 'service']);
        try {
            $this->create([$this->node([$this->rule($this->byProduct, '1', ['output_role' => 'by_product'])])]);
            $this->fail('服务不能充当副产品库存。');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('operations', $exception->errors()); }
        $this->byProduct->update(['item_type' => 'raw_material']);
        try {
            $this->create([$this->node([$this->rule($this->byProduct, '1')])]);
            $this->fail('不可生产物料不能登记为产品。');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('operations', $exception->errors()); }
        $route = $this->create([$this->node([$this->rule($this->secondProduct, '1')])]);
        $this->secondProduct->update(['status' => 'disabled']);
        $this->assertActivationRejected($route);
        $this->secondProduct->update(['status' => 'enabled', 'is_stock_item' => false]);
        $this->assertActivationRejected($route);
        $this->secondProduct->update(['is_stock_item' => true]);
        $this->unit->update(['status' => 'disabled']);
        $this->assertActivationRejected($route);
        $this->unit->update(['status' => 'enabled']);
        $legacy = $this->unit('没有映射的历史单位', 0);
        $legacy->update(['is_legacy' => true, 'standard_unit_id' => null]);
        $this->secondProduct->update(['unit_id' => $legacy->id]);
        $this->assertActivationRejected($route);
        $this->assertSame('draft', $route->fresh()->status);
        $this->assertSame(1, $route->fresh()->business_version);
    }

    public function test_shipment_rules_are_rejected_and_legacy_routes_keep_empty_rule_sets(): void
    {
        $scheme = ProductionPackagingScheme::create(['code' => $this->code('SCHEME'), 'name' => '纸箱', 'status' => 'enabled']);
        $shipment = array_replace($this->node([$this->rule($this->reference, '1')], 20), [
            'execution_context' => 'shipment', 'packaging_scheme_id' => $scheme->id,
        ]);
        try {
            $this->create([$this->node(null), $shipment]);
            $this->fail('包装不能使用生产产出规则。');
        } catch (ValidationException $exception) { $this->assertStringContainsString('发货包装', $exception->getMessage()); }
        $shipment['output_rules'] = [];
        $route = $this->create([$this->node(null), $shipment]);
        $snapshot = app(ProductionMasterDataService::class)->snapshot($route);
        $this->assertSame([], $snapshot['operations'][0]['output_rules']);
        $this->assertSame([], $snapshot['operations'][1]['output_rules']);
        $this->assertSame(0, RoutingOperationOutputRule::where('routing_id', $route->id)->count());
        $this->assertSame($this->reference->id, $snapshot['operations'][0]['output_item_id']);
        $this->assertTrue($snapshot['operations'][0]['is_public']);
        $this->assertTrue($snapshot['operations'][0]['auto_assignment_enabled']);
        $active = app(ProductionMasterDataService::class)->activateRouting($route->id,
            ['client_command_id' => $this->code('LEGACY-ACTIVE'), 'expected_version' => 1], $this->manager, self::PERMISSIONS, false);
        $this->assertSame('active', $active->status);
        $this->assertSame(0, RoutingOperationOutputRule::where('routing_id', $route->id)->count());
    }

    public function test_route_version_and_financial_permissions_remain_required(): void
    {
        $route = $this->create([$this->node([$this->rule($this->reference, '1')])]);
        $this->putJson('/api/v1/erp/production/routings/'.$route->id, ['client_command_id' => $this->code('STALE'),
            'expected_version' => 2, 'operations' => [$this->node([])]])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson('/api/v1/erp/production/routings/'.$route->id, ['client_command_id' => $this->code('PRICE'),
            'expected_version' => 1, 'operations' => [array_replace($this->node(null), ['performance_rate' => '0.2'])]])->assertForbidden();
        try {
            app(ProductionMasterDataService::class)->updateRouting($route->id, ['client_command_id' => $this->code('NO-EDIT'),
                'expected_version' => 1, 'operations' => [$this->node([])]], $this->manager, ['production.routing.view'], false);
            $this->fail('只读权限不能修改规则。');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        $active = app(ProductionMasterDataService::class)->activateRouting($route->id,
            ['client_command_id' => $this->code('ACTIVE'), 'expected_version' => 1], $this->manager, self::PERMISSIONS, false);
        $this->putJson('/api/v1/erp/production/routings/'.$route->id, ['client_command_id' => $this->code('EDIT-ACTIVE'),
            'expected_version' => $active->business_version, 'operations' => [$this->node([])]])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(1, RoutingOperationOutputRule::where('routing_id', $route->id)->count());
    }

    public function test_a_rule_saved_under_a_valid_legacy_mapping_freezes_the_canonical_inventory_unit(): void
    {
        $legacy = $this->unit('历史件', 0);
        $legacy->update(['is_legacy' => true, 'standard_unit_id' => $this->unit->id]);
        $this->secondProduct->update(['unit_id' => $legacy->id]);
        $route = $this->create([$this->node([$this->rule($this->secondProduct, '0.125')])]);
        $rule = app(ProductionMasterDataService::class)->snapshot($route)['operations'][0]['output_rules'][0];
        $this->assertSame($this->unit->id, $rule['base_unit_id']);
        $this->assertSame('件', $rule['base_unit_name_snapshot']);
        $this->assertSame('0.12500000', $rule['base_qty_per_reference_unit']);
        $newCanonical = $this->unit('另一个正式件', 0);
        $legacy->update(['standard_unit_id' => $newCanonical->id]);
        $this->assertActivationRejected($route);
    }

    private function assertRejected(ProductionRouting $route, array $changes): void
    {
        $before = app(ProductionMasterDataService::class)->snapshot($route->fresh());
        $command = $this->code('REJECT');
        try {
            app(ProductionMasterDataService::class)->updateRouting($route->id,
                ['client_command_id' => $command, 'expected_version' => 1] + $changes, $this->manager, self::PERMISSIONS, false);
            $this->fail('规则身份或单位不一致必须拒绝。');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('operations', $exception->errors()); }
        $this->assertSame($before, app(ProductionMasterDataService::class)->snapshot($route->fresh()));
        $this->assertFalse(DB::table('erp_production_master_commands')->where('client_command_id', $command)->exists());
    }

    private function assertActivationRejected(ProductionRouting $route): void
    {
        try {
            app(ProductionMasterDataService::class)->activateRouting($route->id,
                ['client_command_id' => $this->code('BAD-ACTIVE'), 'expected_version' => 1], $this->manager, self::PERMISSIONS, false);
            $this->fail('失效规则或单位不能生效。');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('operations', $exception->errors()); }
        $this->assertSame('draft', $route->fresh()->status);
    }

    private function rule(Item $item, string $ratio, array $changes = []): array
    {
        return array_replace(['output_rule_key' => (string) Str::uuid(), 'item_id' => $item->id, 'output_role' => 'product',
            'base_qty_per_reference_unit' => $ratio, 'quality_mode' => 'none', 'output_mode' => 'flow_only',
            'allow_continue_without_warehouse' => true, 'remark' => null], $changes);
    }

    private function node(?array $rules, int $sequence = 10): array
    {
        $node = ['operation_id' => $this->operation->id, 'sequence' => $sequence, 'output_item_id' => $this->reference->id,
            'output_mode' => 'flow_only', 'quality_mode' => 'none', 'allow_continue_without_warehouse' => true];
        if ($rules !== null) $node['output_rules'] = $rules;
        return $node;
    }

    private function payload(array $nodes): array
    {
        $session = (string) Str::uuid();
        $number = app(DocumentNumberService::class)->reserve('routing', $session, $this->manager->legacy_id, '/production/routings');
        return ['client_command_id' => $this->code('CREATE'), 'creation_session_id' => $session, 'reservation_token' => $number->reservation_token,
            'routing_name' => $this->code('多产出路线'), 'output_item_id' => $this->reference->id, 'operations' => $nodes];
    }

    private function create(array $nodes): ProductionRouting
    {
        return app(ProductionMasterDataService::class)->createRouting($this->payload($nodes), $this->manager, self::PERMISSIONS, false);
    }

    private function commandSnapshot(string $id): array
    {
        return json_decode(DB::table('erp_production_master_commands')->where('client_command_id', $id)->value('response_snapshot'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function item(Unit $unit, string $name, bool $production = true): Item
    {
        return Item::create(['item_code' => $this->code('ITEM'), 'item_name' => $name, 'spec' => '规则规格',
            'unit_id' => $unit->id, 'item_type' => $production ? 'finished_good' : 'raw_material',
            'is_production_item' => $production, 'is_stock_item' => true, 'status' => 'enabled']);
    }

    private function unit(string $name, int $precision): Unit
    {
        return Unit::create(['unit_code' => $this->code('UNIT'), 'unit_name' => $name, 'unit_type' => 'count',
            'decimal_places' => $precision, 'allow_decimal' => $precision > 0, 'is_base' => true, 'status' => 'enabled']);
    }

    private function code(string $prefix): string { return 'OUTRULE-'.$prefix.'-'.Str::upper(Str::random(10)); }
}
