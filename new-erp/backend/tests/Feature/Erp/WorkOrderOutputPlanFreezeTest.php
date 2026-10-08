<?php

namespace Tests\Feature\Erp;

use App\DTO\Erp\WorkOrderDto;
use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Bom, BomItem, Item, Product, ProductionDemand, SalesOrder, SalesOrderLine, Sku, Unit, WorkOrder};
use App\Services\Erp\{ProductionMasterDataService, RbacBootstrapService, ReleaseGateApplicationService, WorkOrderApplicationService, WorkOrderOutputPlanService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkOrderOutputPlanFreezeTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = [
        'production.demand.view', 'production.work_order.view', 'production.work_order.create',
        'production.work_order.edit', 'production.work_order.submit', 'production.work_order.publish',
        'production.work_order.gate.view', 'production.material.view',
    ];

    public function test_legacy_preview_is_read_only_and_its_reference_uuid_is_stable(): void
    {
        $f = $this->fixture();
        $before = $f['work_order']->fresh()->getRawOriginal();
        $preview = $this->preview($f)->assertOk()->json('data');
        $again = $this->preview($f)->assertOk()->json('data');
        $this->assertSame($preview, $again);
        $this->assertTrue(Str::isUuid($preview['outputs'][0]['line_uuid']));
        $this->assertSame('5.00000000', $preview['outputs'][0]['planned_base_qty']);
        $this->assertTrue($preview['matched']);
        $this->assertTrue($preview['execution_supported']);
        $this->assertFalse($preview['has_explicit_rules']);
        $this->assertSame('legacy_snapshot', $preview['operations'][0]['rule_source']);
        $this->assertSame([], $preview['operations'][0]['outputs']);
        $this->assertNull(WorkOrderDto::fromModel($f['work_order'], self::PERMISSIONS)['output_plan']);
        $this->assertSame($before, $f['work_order']->fresh()->getRawOriginal());
        $this->assertSame(0, DB::table('erp_work_order_planned_outputs')->where('work_order_id', $f['work_order']->id)->count());
        $this->assertSame(0, DB::table('erp_work_order_release_gate_checks')->where('work_order_id', $f['work_order']->id)->count());
    }

    public function test_single_rule_freezes_saved_uuid_all_facts_and_history_ignores_changed_master_data(): void
    {
        $f = $this->fixture();
        $saved = app(WorkOrderApplicationService::class)->savePlannedOutputs($f['work_order']->id,
            $this->command($f['work_order']) + ['outputs' => []], $f['user'], self::PERMISSIONS);
        $referenceUuid = $saved->plannedOutputs()->where('is_reference', true)->value('line_uuid');
        $f['work_order'] = $saved;
        $rule = $this->rule($f, $f['output']);
        $this->setRules($f, [$rule]);
        $originalNodes = $f['work_order']->routing_snapshot['operations'];
        $preview = $this->preview($f)->assertOk()->json('data');
        $row = $preview['operations'][0]['outputs'][0];
        $this->assertSame($referenceUuid, $row['work_order_output_line_uuid']);
        $this->assertTrue(Str::isUuid($row['operation_output_line_uuid']));
        $this->assertSame($rule['output_rule_key'], $row['output_rule_key']);
        $this->assertSame('work_order', $row['output_scope']);
        $this->assertSame('5.00000000', $row['planned_base_qty']);
        $this->assertSame('工单冻结成品', $row['item_name']);
        $this->assertSame('件', $row['base_unit_name']);
        $this->assertSame('none', $row['quality_mode']);
        $this->assertSame('flow_only', $row['output_mode']);

        $released = $this->publish($f);
        $frozen = $released->routing_snapshot['output_plan'];
        $this->assertSame('frozen', $frozen['status']);
        $this->assertTrue($frozen['immutable']);
        $this->assertSame((int) $released->business_version, $frozen['work_order_version']);
        $this->assertSame($referenceUuid, $frozen['outputs'][0]['line_uuid']);
        $this->assertSame($this->sortedFacts($preview['operations']), $this->sortedFacts($frozen['operations']));
        $this->assertSame($originalNodes, $released->routing_snapshot['operations']);
        $this->assertSame('5.00000000', (string) $released->quantityOperations()->first()->planned_base_qty);
        $this->assertSame(1, $released->productionTasks()->count());
        $this->assertSame(1, $released->materialRequirements()->count());
        $this->assertSame(1, DB::table('erp_work_order_planned_output_versions')->where('work_order_id', $released->id)->count());

        $changedUnit = $this->unit(2, '公斤');
        $f['output']->update(['item_name' => '后来改名', 'spec' => '后来改规格', 'unit_id' => $changedUnit->id]);
        $f['unit']->update(['unit_name' => '后来改单位名']);
        DB::table('erp_production_routing_operations')->where('id', $f['node_id'])->update(['quality_mode' => 'required', 'output_mode' => 'warehouse_required']);
        $released->update(['status' => 'COMPLETED', 'business_version' => (int) $released->business_version + 1]);
        $f['work_order'] = $released->fresh();
        $checksBefore = DB::table('erp_work_order_release_gate_checks')->where('work_order_id', $released->id)->orderBy('id')->get()->toArray();
        $this->assertSame($frozen, $this->preview($f)->assertOk()->json('data'));
        $dto = WorkOrderDto::fromModel($f['work_order'], self::PERMISSIONS);
        $this->assertSame($frozen, $dto['output_plan']);
        $this->assertSame($frozen['outputs'], $dto['planned_outputs']['outputs']);
        $this->withToken($f['token'])->getJson($this->url($released->id, 'planned-outputs'))
            ->assertOk()->assertJsonPath('data.plan_source', 'frozen_plan')->assertJsonPath('data.outputs.0.item_name', '工单冻结成品');
        $gate = app(ReleaseGateApplicationService::class)->evaluate($released->id, $f['user'], self::PERMISSIONS);
        $this->assertTrue($gate['immutable']);
        $this->assertTrue($gate['allowed']);
        $this->assertSame($frozen, $gate['output_plan']);
        $this->assertEquals($checksBefore, DB::table('erp_work_order_release_gate_checks')->where('work_order_id', $released->id)->orderBy('id')->get()->toArray());
        $this->assertSame('COMPLETED', $released->fresh()->status);
    }

    public function test_intermediate_rule_has_its_own_identity_without_inventing_a_final_work_order_line(): void
    {
        $f = $this->fixture();
        $semi = $this->item($f['unit'], ['item_name' => '真实中间半成品', 'item_type' => 'semi_finished']);
        $firstId = DB::table('erp_production_routing_operations')->insertGetId([
            'routing_id' => $f['routing_id'], 'operation_id' => $f['operation_id'], 'sequence' => 5,
            'output_item_id' => $semi->id, 'output_mode' => 'flow_only', 'quality_mode' => 'none',
            'allow_continue_without_warehouse' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $snapshot = app(ProductionMasterDataService::class)->snapshot(\App\Models\Erp\ProductionRouting::findOrFail($f['routing_id']));
        $snapshot['operations'][0]['output_rules'] = [$this->rule($f, $semi)];
        $snapshot['operations'][1]['output_rules'] = [$this->rule($f, $f['output'])];
        $f['work_order']->update(['routing_snapshot' => $snapshot]);
        $f['work_order'] = $f['work_order']->fresh();
        $plan = $this->preview($f)->assertOk()->json('data');
        $this->assertTrue($plan['matched']);
        $this->assertTrue($plan['execution_supported']);
        $this->assertCount(1, $plan['outputs']);
        $this->assertSame($firstId, $plan['operations'][0]['routing_operation_id']);
        $first = $plan['operations'][0]['outputs'][0];
        $last = $plan['operations'][1]['outputs'][0];
        $this->assertSame('intermediate', $first['output_scope']);
        $this->assertNull($first['work_order_output_line_uuid']);
        $this->assertSame('work_order', $last['output_scope']);
        $this->assertSame($plan['outputs'][0]['line_uuid'], $last['work_order_output_line_uuid']);
        $this->assertNotSame($first['operation_output_line_uuid'], $last['operation_output_line_uuid']);
        $released = $this->publish($f);
        $this->assertSame($this->sortedFacts($plan['operations']), $this->sortedFacts($released->routing_snapshot['output_plan']['operations']));
        $this->assertSame(2, $released->productionTasks()->count());
        $this->assertSame(0, DB::table('erp_work_order_planned_outputs')->where('work_order_id', $released->id)->count());
    }

    public function test_route_declared_multi_output_cannot_escape_gate_when_work_order_has_only_one_reference(): void
    {
        $f = $this->fixture();
        $extra = $this->item($f['unit'], ['item_name' => '真实副产物', 'item_type' => 'raw_material', 'is_production_item' => false]);
        $this->setRules($f, [$this->rule($f, $f['output']), $this->rule($f, $extra, ['output_role' => 'by_product'])]);
        $waiting = $this->submit($f);
        $gate = app(ReleaseGateApplicationService::class)->evaluate($waiting->id, $f['user'], self::PERMISSIONS);
        $this->assertSame('passed', collect($gate['checks'])->firstWhere('key', 'planned_outputs')['status']);
        $this->assertSame('blocked', collect($gate['checks'])->firstWhere('key', 'operation_output_plan')['status']);
        $this->assertContains('multi_output_execution_not_available', array_column($gate['output_plan']['execution_blockers'], 'code'));
        $this->assertFalse($gate['allowed']);
        $this->withToken($f['token'])->postJson($this->url($waiting->id, 'publish'), $this->command($waiting) + ['reason' => '不得丢掉第二条规则'])
            ->assertStatus(422)->assertJsonPath('error_code', 'release_gate_blocked');
        $this->assertUnpublished($waiting);
    }

    public function test_target_quantity_and_unit_precision_must_be_exact_before_freezing(): void
    {
        $f = $this->fixture();
        $this->setRules($f, [$this->rule($f, $f['output'], ['base_qty_per_reference_unit' => '0.33333333'])]);
        $plan = $this->preview($f)->assertOk()->json('data');
        $this->assertFalse($plan['matched']);
        $this->assertFalse($plan['execution_supported']);
        $this->assertSame('1.66666665', $plan['operations'][0]['outputs'][0]['planned_base_qty']);
        $this->assertContains('operation_output_quantity_precision', array_column($plan['issues'], 'code'));
        $this->assertContains('operation_output_work_order_quantity_mismatch', array_column($plan['issues'], 'code'));
        $this->assertContains('operation_output_legacy_quantity_mismatch', array_column($plan['execution_blockers'], 'code'));
        $waiting = $this->submit($f);
        $this->withToken($f['token'])->postJson($this->url($waiting->id, 'publish'), $this->command($waiting) + ['reason' => '数量不得取整猜测'])
            ->assertStatus(422)->assertJsonPath('error_code', 'release_gate_blocked');
        $this->assertUnpublished($waiting);
    }

    public function test_single_rule_quality_destination_and_item_must_agree_with_existing_executor(): void
    {
        $f = $this->fixture();
        $this->setRules($f, [$this->rule($f, $f['output'], ['quality_mode' => 'required', 'output_mode' => 'warehouse_required', 'allow_continue_without_warehouse' => false])]);
        $plan = $this->preview($f)->assertOk()->json('data');
        $this->assertTrue($plan['matched']);
        $this->assertFalse($plan['execution_supported']);
        $this->assertSame('operation_output_legacy_policy_mismatch', $plan['execution_blockers'][0]['code']);
        $waiting = $this->submit($f);
        $this->withToken($f['token'])->postJson($this->url($waiting->id, 'publish'), $this->command($waiting) + ['reason' => '不得绕过逐行质检去向'])
            ->assertStatus(422);
        $this->assertUnpublished($waiting);
    }

    public function test_prebuild_reference_basis_is_reversed_from_target_rule_and_never_multiplied_as_final_product_quantity(): void
    {
        $f = $this->fixture();
        $semi = $this->item($f['unit'], ['item_type' => 'semi_finished', 'item_name' => '备货目标半成品']);
        $snapshot = $f['work_order']->routing_snapshot;
        $snapshot['operations'][0]['output_item_id'] = $semi->id;
        $snapshot['operations'][0]['output_item_code'] = $semi->item_code;
        $snapshot['operations'][0]['output_item_name'] = $semi->item_name;
        $snapshot['operations'][0]['output_rules'] = [$this->rule($f, $semi, ['base_qty_per_reference_unit' => '2.00000000'])];
        $f['work_order']->update(['source_type' => 'stock_prebuild', 'production_demand_id' => null,
            'effective_output_item_id_snapshot' => $semi->id, 'target_routing_operation_id' => $f['node_id'],
            'configured_output_mode_snapshot' => 'flow_only', 'effective_output_mode_snapshot' => 'warehouse_required',
            'stocking_purpose' => 'common_inventory', 'routing_snapshot' => $snapshot]);
        $f['work_order'] = $f['work_order']->fresh();
        $plan = $this->preview($f)->assertOk()->json('data');
        $this->assertTrue($plan['matched']);
        $this->assertTrue($plan['execution_supported']);
        $this->assertSame('2.50000000', $plan['reference_basis']['reference_base_qty']);
        $this->assertSame('stock_prebuild_target_rule', $plan['reference_basis']['resolution_source']);
        $this->assertSame($semi->id, $plan['outputs'][0]['item_id']);
        $this->assertSame('5.00000000', $plan['operations'][0]['outputs'][0]['planned_base_qty']);
        $this->assertSame('flow_only', $plan['operations'][0]['outputs'][0]['configured_output_mode']);
        $this->assertSame('warehouse_required', $plan['operations'][0]['outputs'][0]['output_mode']);

        $snapshot['operations'][0]['output_rules'][0]['base_qty_per_reference_unit'] = '3.00000000';
        $f['work_order']->update(['target_base_qty' => '1.00000000', 'routing_snapshot' => $snapshot]);
        $f['work_order'] = $f['work_order']->fresh();
        $invalid = $this->preview($f)->assertOk()->json('data');
        $this->assertFalse($invalid['matched']);
        $this->assertNull($invalid['reference_basis']);
        $this->assertContains('operation_output_reference_basis_not_exact', array_column($invalid['issues'], 'code'));
    }

    public function test_unit_identity_changes_block_new_freezes_and_intermediate_units_cannot_be_guessed(): void
    {
        $f = $this->fixture();
        $this->setRules($f, [$this->rule($f, $f['output'])]);
        $replacement = $this->unit(4, '公斤');
        $f['output']->update(['unit_id' => $replacement->id]);
        $plan = $this->preview($f)->assertOk()->json('data');
        $this->assertFalse($plan['matched']);
        $this->assertContains('operation_output_unit_changed', array_column($plan['issues'], 'code'));
        $this->assertContains('operation_output_reference_invalid', array_column($plan['issues'], 'code'));
        $this->assertSame(0, DB::table('erp_work_order_planned_outputs')->where('work_order_id', $f['work_order']->id)->count());

        $f['output']->update(['unit_id' => $f['unit']->id]);
        $semi = $this->item($replacement, ['item_type' => 'semi_finished']);
        $snapshot = $f['work_order']->fresh()->routing_snapshot;
        $node = $snapshot['operations'][0];
        $node['routing_operation_id'] += 1000000;
        $node['sequence'] = 5;
        $node['output_item_id'] = $semi->id;
        $node['output_rules'] = [$this->rule($f, $semi)];
        array_unshift($snapshot['operations'], $node);
        $f['work_order']->update(['routing_snapshot' => $snapshot]);
        $f['work_order'] = $f['work_order']->fresh();
        $plan = $this->preview($f)->assertOk()->json('data');
        $this->assertTrue($plan['matched']);
        $this->assertFalse($plan['execution_supported']);
        $this->assertContains('operation_output_legacy_unit_mismatch', array_column($plan['execution_blockers'], 'code'));
    }

    public function test_legacy_release_reads_only_stored_labels_without_retroactive_rule_matching(): void
    {
        $f = $this->fixture();
        $f['work_order']->update(['status' => 'RELEASED', 'released_at' => now()]);
        $changed = $this->unit(2, '公斤');
        $f['output']->update(['item_name' => '后改的名称', 'unit_id' => $changed->id]);
        $f['work_order'] = $f['work_order']->fresh();
        $before = $f['work_order']->getRawOriginal();
        $plan = $this->preview($f)->assertOk()->json('data');
        $this->assertTrue($plan['immutable']);
        $this->assertSame('legacy_snapshot', $plan['status']);
        $this->assertNull($plan['matched']);
        $this->assertSame('工单冻结成品', $plan['outputs'][0]['item_name']);
        $this->assertSame($f['unit']->id, $plan['outputs'][0]['base_unit_id']);
        $this->assertSame('件', $plan['outputs'][0]['base_unit_name']);
        $this->assertSame($before, $f['work_order']->fresh()->getRawOriginal());
        $this->assertSame(0, DB::table('erp_work_order_planned_outputs')->where('work_order_id', $f['work_order']->id)->count());
    }

    public function test_every_rule_must_accept_the_reference_quantity_at_its_own_frozen_precision(): void
    {
        $f = $this->fixture();
        $semi = $this->item($f['unit'], ['item_type' => 'semi_finished']);
        DB::table('erp_production_routing_operations')->insert(['routing_id' => $f['routing_id'], 'operation_id' => $f['operation_id'],
            'sequence' => 5, 'output_item_id' => $semi->id, 'output_mode' => 'flow_only', 'quality_mode' => 'none',
            'allow_continue_without_warehouse' => true, 'created_at' => now(), 'updated_at' => now()]);
        $snapshot = app(ProductionMasterDataService::class)->snapshot(\App\Models\Erp\ProductionRouting::findOrFail($f['routing_id']));
        $snapshot['operations'][0]['output_rules'] = [$this->rule($f, $semi)];
        $snapshot['operations'][1]['output_rules'] = [$this->rule($f, $f['output'], ['reference_base_unit_decimal_places_snapshot' => 0])];
        $f['work_order']->update(['target_qty' => '5.5', 'target_base_qty' => '5.50000000', 'routing_snapshot' => $snapshot]);
        $f['work_order'] = $f['work_order']->fresh();
        $plan = $this->preview($f)->assertOk()->json('data');
        $this->assertFalse($plan['matched']);
        $this->assertSame('5.50000000', $plan['reference_basis']['reference_base_qty']);
        $problem = collect($plan['issues'])->firstWhere('code', 'operation_output_reference_precision_invalid');
        $this->assertNotNull($problem);
        $this->assertSame($f['node_id'], $problem['routing_operation_id']);
        $this->assertSame(0, $problem['details']['reference_base_unit_decimal_places']);
        $waiting = $this->submit($f);
        $this->withToken($f['token'])->postJson($this->url($waiting->id, 'publish'), $this->command($waiting) + ['reason' => '每条参考精度都须精确'])
            ->assertStatus(422)->assertJsonPath('error_code', 'release_gate_blocked');
        $this->assertUnpublished($waiting);
    }

    public function test_invalid_output_unit_precision_is_unknown_and_cannot_be_cast_to_zero(): void
    {
        $f = $this->fixture();
        $this->setRules($f, [$this->rule($f, $f['output'], ['base_unit_decimal_places_snapshot' => 'unconfirmed'])]);
        $plan = $this->preview($f)->assertOk()->json('data');
        $this->assertFalse($plan['matched']);
        $this->assertNull($plan['operations'][0]['outputs'][0]['base_unit_decimal_places']);
        $this->assertContains('operation_output_unit_precision_missing', array_column($plan['issues'], 'code'));
        $waiting = $this->submit($f);
        $this->withToken($f['token'])->postJson($this->url($waiting->id, 'publish'), $this->command($waiting) + ['reason' => '未确认精度不得默认整数'])
            ->assertStatus(422)->assertJsonPath('error_code', 'release_gate_blocked');
        $this->assertUnpublished($waiting);
    }

    public function test_legacy_mapping_target_must_remain_an_enabled_standard_unit(): void
    {
        $f = $this->fixture();
        $this->setRules($f, [$this->rule($f, $f['output'])]);
        $alias = $this->unit(4, '历史件');
        $alias->update(['is_legacy' => true, 'standard_unit_id' => $f['unit']->id]);
        $f['output']->update(['unit_id' => $alias->id]);
        $f['work_order'] = app(WorkOrderApplicationService::class)->savePlannedOutputs($f['work_order']->id,
            $this->command($f['work_order']) + ['outputs' => []], $f['user'], self::PERMISSIONS);
        $valid = $this->preview($f)->assertOk()->json('data');
        $this->assertTrue($valid['matched']);
        $this->assertSame($f['unit']->id, $valid['outputs'][0]['base_unit_id']);
        $f['unit']->update(['is_legacy' => true, 'standard_unit_id' => null]);
        $invalid = $this->preview($f)->assertOk()->json('data');
        $this->assertFalse($invalid['matched']);
        $this->assertContains('operation_output_item_invalid', array_column($invalid['issues'], 'code'));
        $this->assertContains('operation_output_reference_invalid', array_column($invalid['issues'], 'code'));
        $waiting = $this->submit($f);
        $this->withToken($f['token'])->postJson($this->url($waiting->id, 'publish'), $this->command($waiting) + ['reason' => '映射目标不能再次成为历史单位'])
            ->assertStatus(422)->assertJsonPath('error_code', 'release_gate_blocked');
        $this->assertUnpublished($waiting);
    }

    public function test_preview_requires_authentication_explicit_view_permission_and_current_work_order_scope(): void
    {
        $f = $this->fixture();
        $url = $this->url($f['work_order']->id, 'output-plan-preview');
        $this->flushHeaders();
        $this->getJson($url)->assertUnauthorized();
        $withoutView = $this->actor([]);
        $this->withToken($withoutView['token'])->getJson($url)->assertForbidden();
        $outside = $this->actor(['production.work_order.view'], 'self');
        $this->withToken($outside['token'])->getJson($url)->assertForbidden();
        $reader = $this->actor(['production.work_order.view']);
        $this->withToken($reader['token'])->getJson($url)->assertOk();
        try {
            app(WorkOrderOutputPlanService::class)->preview($f['work_order']->id, $f['user'], [], true);
            $this->fail('管理员标记不能替代工单查看权限。');
        } catch (WorkOrderDomainException $e) {
            $this->assertSame('permission_denied', $e->errorCode);
            $this->assertSame(403, $e->status);
        }
    }

    private function fixture(): array
    {
        $actor = $this->actor(self::PERMISSIONS);
        $unit = $this->unit(4, '件');
        $output = $this->item($unit, ['item_name' => '工单冻结成品', 'spec' => '原冻结规格']);
        $component = $this->item($unit, ['item_type' => 'raw_material', 'is_production_item' => false]);
        $product = Product::create(['product_code' => $this->code('P'), 'product_name' => '产出冻结产品', 'product_type' => 'standard', 'status' => 'enabled']);
        $sku = Sku::create(['product_id' => $product->id, 'sales_unit_id' => $unit->id, 'sku_code' => $this->code('SKU'),
            'sku_name' => '冻结规格', 'order_line_type' => 'physical', 'fulfillment_type' => 'physical', 'status' => 'enabled']);
        $operationId = DB::table('erp_production_operations')->insertGetId(['operation_no' => $this->code('OP'),
            'operation_name' => '真实产出工序', 'status' => 'enabled', 'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $routingId = DB::table('erp_production_routings')->insertGetId(['routing_no' => $this->code('RT'),
            'routing_name' => '产出冻结路线', 'output_item_id' => $output->id, 'version' => 1,
            'status' => 'active', 'is_default' => true, 'default_scope_key' => (string) $output->id,
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $nodeId = DB::table('erp_production_routing_operations')->insertGetId(['routing_id' => $routingId,
            'operation_id' => $operationId, 'sequence' => 10, 'output_item_id' => $output->id,
            'output_mode' => 'flow_only', 'quality_mode' => 'none', 'allow_continue_without_warehouse' => true,
            'is_key_operation' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_routing_operation_material_supply_rules')->insert(['routing_operation_id' => $nodeId,
            'component_item_id' => $component->id, 'target_routing_operation_id' => $nodeId,
            'required_qty_ratio' => 1, 'supply_mode' => 'dedicated_delivery', 'requires_delivery' => true,
            'participates_in_kitting' => true, 'allow_partial_delivery' => false, 'delivery_location_type' => 'operation_station',
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $order = SalesOrder::create(['sales_order_no' => $this->code('SO'), 'customer_name' => '产出冻结测试客户',
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed',
            'sales_user_legacy_id' => $actor['user']->legacy_id, 'created_by_legacy_id' => $actor['user']->legacy_id,
            'total_amount' => 0, 'final_receivable_amount' => 0,
            'funding_policy_snapshot' => ['policy_type' => 'full_prepay', 'shipment_requires_full_payment' => true]]);
        $line = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'line_uuid' => (string) Str::uuid(),
            'line_type' => 'physical', 'product_id' => $product->id, 'product_name' => $product->product_name,
            'sku_id' => $sku->id, 'sku_name' => $sku->sku_name, 'item_id' => $output->id, 'item_name' => $output->item_name,
            'order_qty' => 10, 'unit_id' => $unit->id, 'unit_name_snapshot' => $unit->unit_name, 'unit_price' => 0, 'amount' => 0,
            'item_base_unit_id' => $unit->id, 'item_base_required_qty' => 10, 'is_special_customized' => false]);
        $demand = ProductionDemand::create(['requirement_no' => $this->code('D'), 'sales_order_id' => $order->id,
            'sales_order_line_id' => $line->id, 'product_id' => $product->id, 'sku_id' => $sku->id, 'item_id' => $output->id,
            'production_qty' => 10, 'base_unit_id' => $unit->id, 'base_unit_name_snapshot' => $unit->unit_name,
            'allocated_qty' => 0, 'consumed_qty' => 0, 'remaining_qty' => 10, 'closed_qty' => 0,
            'requirement_status' => 'ready', 'bom_match_status' => 'matched', 'is_active' => true,
            'requirement_version' => 1, 'business_version' => 1, 'is_ready_for_work_order' => true]);
        $bom = Bom::create(['bom_no' => $this->code('BOM'), 'bom_name' => '冻结计划正式BOM',
            'product_id' => $product->id, 'sku_id' => $sku->id, 'output_item_id' => $output->id, 'bom_type' => 'standard',
            'version' => 'V1.0', 'is_default' => true, 'status' => 'active', 'audit_status' => 'approved', 'effective_date' => now()->subDay()->toDateString()]);
        BomItem::create(['bom_id' => $bom->id, 'line_no' => 10, 'component_item_id' => $component->id,
            'component_item_code' => $component->item_code, 'component_item_name' => $component->item_name,
            'qty' => 2, 'unit_id' => $unit->id, 'loss_rate' => 0, 'fixed_qty' => 0, 'replaceable' => false]);
        $workOrder = app(WorkOrderApplicationService::class)->createDraft(['client_command_id' => $this->code('CMD'),
            'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 5,
            'responsible_user_legacy_id' => $actor['user']->legacy_id], $actor['user'], self::PERMISSIONS);
        return $actor + ['unit' => $unit, 'output' => $output, 'component' => $component, 'work_order' => $workOrder,
            'routing_id' => $routingId, 'node_id' => $nodeId, 'operation_id' => $operationId];
    }

    private function rule(array $f, Item $item, array $overrides = []): array
    {
        $unit = $item->unit;
        return array_replace([
            'routing_operation_output_rule_id' => null, 'output_rule_key' => (string) Str::uuid(),
            'line_no' => 1, 'business_version' => 1, 'item_id' => $item->id, 'output_role' => 'product',
            'base_unit_id' => $unit->id, 'item_code_snapshot' => $item->item_code,
            'item_name_snapshot' => $item->item_name, 'spec_snapshot' => $item->spec,
            'base_unit_name_snapshot' => $unit->unit_name, 'base_unit_decimal_places_snapshot' => (int) $unit->decimal_places,
            'base_qty_per_reference_unit' => '1.00000000', 'quality_mode' => 'none', 'output_mode' => 'flow_only',
            'allow_continue_without_warehouse' => true, 'remark' => '明确产出规则',
            'reference_item_id' => $f['output']->id, 'reference_base_unit_id' => $f['unit']->id,
            'reference_item_code_snapshot' => $f['output']->item_code, 'reference_item_name_snapshot' => $f['output']->item_name,
            'reference_base_unit_name_snapshot' => $f['unit']->unit_name, 'reference_base_unit_decimal_places_snapshot' => 4,
        ], $overrides);
    }

    private function setRules(array &$f, array $rules): void
    {
        $snapshot = $f['work_order']->fresh()->routing_snapshot;
        $snapshot['operations'][0]['output_rules'] = $rules;
        $f['work_order']->update(['routing_snapshot' => $snapshot]);
        $f['work_order'] = $f['work_order']->fresh();
    }

    private function submit(array &$f): WorkOrder
    {
        $f['work_order'] = app(WorkOrderApplicationService::class)->submit($f['work_order']->id,
            $this->command($f['work_order']), $f['user'], self::PERMISSIONS);
        return $f['work_order'];
    }

    private function publish(array &$f): WorkOrder
    {
        $waiting = $this->submit($f);
        $gate = app(ReleaseGateApplicationService::class)->evaluate($waiting->id, $f['user'], self::PERMISSIONS);
        $this->assertTrue($gate['allowed'], json_encode($gate['blockers'], JSON_UNESCAPED_UNICODE));
        $f['work_order'] = app(WorkOrderApplicationService::class)->publish($waiting->id,
            $this->command($waiting) + ['reason' => '冻结可执行单产出计划'], $f['user'], self::PERMISSIONS);
        return $f['work_order'];
    }

    private function assertUnpublished(WorkOrder $workOrder): void
    {
        $current = $workOrder->fresh();
        $this->assertSame('WAIT_RELEASE', $current->status);
        $this->assertNull(data_get($current->routing_snapshot, 'output_plan'));
        $this->assertSame(0, $current->productionTasks()->count());
        $this->assertSame(0, $current->materialRequirements()->count());
    }

    /** MySQL JSON canonicalizes object keys; preserve all scalar types and list order. */
    private function sortedFacts(mixed $facts): mixed
    {
        if (! is_array($facts)) return $facts;
        if (! array_is_list($facts)) ksort($facts);
        return array_map(fn ($value) => $this->sortedFacts($value), $facts);
    }

    private function preview(array $f) { return $this->withToken($f['token'])->getJson($this->url($f['work_order']->id, 'output-plan-preview')); }
    private function url(int $id, string $suffix): string { return '/api/v1/erp/production/work-orders/'.$id.'/'.$suffix; }
    private function command(WorkOrder $wo): array { return ['client_command_id' => $this->code('CMD'), 'expected_version' => (int) $wo->fresh()->business_version]; }
    private function unit(int $precision, string $name): Unit { return Unit::create(['unit_code' => $this->code('U'), 'unit_name' => $name, 'unit_type' => 'quantity', 'decimal_places' => $precision, 'is_base' => true, 'status' => 'enabled']); }
    private function item(Unit $unit, array $attributes = []): Item { return Item::create(array_replace(['item_code' => $this->code('ITEM'), 'item_name' => '实际产出物料', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'production_execution_mode' => 'quantity', 'status' => 'enabled'], $attributes)); }
    private function code(string $prefix): string { return 'WOPF-'.$prefix.'-'.strtoupper(Str::random(12)); }

    private function actor(array $permissions, string $scope = 'all'): array
    {
        app(RbacBootstrapService::class)->bootstrap();
        $id = random_int(6200000, 6299999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $id, 'username' => $this->code('USER'),
            'nickname' => '工单冻结测试人员', 'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $role = DB::table('erp_rbac_roles')->insertGetId(['code' => $this->code('ROLE'), 'name' => '工单冻结测试角色',
            'data_scope' => $scope, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('erp_rbac_permissions')->whereIn('code', $permissions)->pluck('id') as $permission) DB::table('erp_rbac_role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $id, 'role_id' => $role]);
        $token = $this->code('TOKEN');
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        return ['user' => DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->first(), 'token' => $token];
    }
}
