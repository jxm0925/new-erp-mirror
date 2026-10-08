<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{InventoryTransaction, Item, Location, ProductionLaborSession, ProductionPerformanceAssignment, ProductionQuantityOperation, ProductionTask, ProductionTaskTarget, SalesOrder, SalesOrderLine, SalesShipment, SalesShipmentLine, Unit, Warehouse, WorkOrder};
use App\Services\Erp\{ProductionOutputTraceService, ProductionPerformanceApplicationService, ProductionPerformanceQueryService, ProductionShipmentSourceResolver};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ProductionPerformanceTest extends TestCase
{
    use DatabaseTransactions;
    private const PERMISSIONS = ['production.performance.view', 'production.performance.manage'];
    private array $c;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = Str::upper(Str::random(10));
        $unit = Unit::create(['unit_code' => 'PF-U-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'PF-I-'.$suffix, 'item_name' => '绩效来源成品', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => 'PF-WH-'.$suffix, 'warehouse_name' => '绩效验证仓', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => 'PF-L-'.$suffix, 'location_name' => '绩效验证库位', 'status' => 'enabled']);
        $owner = $this->user('真实接单人'); $helper = $this->user('临时参与人员'); $outsider = $this->user('其他员工');
        $order = SalesOrder::create(['sales_order_no' => 'PF-SO-'.$suffix, 'customer_name' => '绩效验证客户', 'order_status' => 'confirmed', 'confirm_status' => 'confirmed',
            'shipment_status' => 'shipped', 'total_amount' => 9999, 'final_receivable_amount' => 9999, 'business_version' => 1]);
        $line = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'item_id' => $item->id, 'item_name' => $item->item_name,
            'line_type' => 'physical', 'order_qty' => 2, 'unit_price' => 500, 'amount' => 1000, 'amount_incl_tax' => 1000,
            'fulfillment_factor_snapshot' => 1, 'item_base_unit_id' => $unit->id, 'item_base_required_qty' => 2]);
        SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 2, 'line_type' => 'fee', 'order_qty' => 1, 'amount' => 300, 'amount_incl_tax' => 300, 'product_name' => '另收包装费']);
        $workOrder = WorkOrder::create(['work_order_no' => 'PF-WO-'.$suffix, 'source_type' => 'stock_prebuild', 'output_item_id' => $item->id,
            'target_qty' => 10, 'target_base_qty' => 10, 'target_unit_id' => $unit->id, 'base_unit_id' => $unit->id, 'status' => 'COMPLETED', 'business_version' => 1]);
        $target = ProductionQuantityOperation::create(['work_order_id' => $workOrder->id, 'operation_code_snapshot' => 'WELD', 'operation_name_snapshot' => '历史焊接',
            'sequence_no_snapshot' => 1, 'status' => 'COMPLETED', 'planned_base_qty' => 10, 'completed_base_qty' => 10, 'remaining_base_qty' => 0,
            'responsible_user_legacy_id' => $owner->legacy_id, 'performance_rate_snapshot' => '0.30000000', 'completed_at' => now(), 'business_version' => 1]);
        $task = ProductionTask::create(['task_no' => 'PF-T-'.$suffix, 'work_order_id' => $workOrder->id, 'execution_mode' => 'quantity',
            'operation_code_snapshot' => 'WELD', 'operation_name_snapshot' => '历史焊接', 'sequence_no_snapshot' => 1, 'status' => 'COMPLETED',
            'assignee_user_legacy_id' => $owner->legacy_id, 'business_version' => 1]);
        ProductionTaskTarget::create(['task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $target->id, 'status_snapshot' => 'COMPLETED']);
        foreach ([$owner->legacy_id, $helper->legacy_id] as $employee) ProductionLaborSession::create(['task_id' => $task->id,
            'target_type' => 'quantity_operation', 'target_id' => $target->id, 'employee_legacy_id' => $employee, 'role' => $employee === $owner->legacy_id ? 'owner' : 'collaborator',
            'status' => 'ENDED', 'started_at' => now()->subMinutes(20), 'ended_at' => now(), 'actual_labor_minutes' => 20, 'credited_labor_minutes' => 20, 'responsibility_weight_snapshot' => 0.5]);
        $shipment = SalesShipment::create(['shipment_no' => 'PF-SH-'.$suffix, 'sales_order_id' => $order->id, 'shipment_status' => 'shipped',
            'actual_freight_amount' => 888, 'shipped_at' => now(), 'outbound_posted_at' => now()]);
        $shipmentLine = SalesShipmentLine::create(['shipment_id' => $shipment->id, 'sales_order_line_id' => $line->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => 'PF-'.$suffix, 'unit_id' => $unit->id,
            'sales_qty' => 2, 'base_qty' => 2, 'line_status' => 'shipped']);
        $transaction = InventoryTransaction::create(['transaction_no' => 'PF-TX-'.$suffix, 'transaction_type' => 'sales_shipment_outbound',
            'source_type' => 'sales_shipment', 'source_id' => $shipment->id, 'posting_status' => 'posted', 'posted_at' => now()]);
        $this->c = compact('suffix', 'unit', 'item', 'warehouse', 'location', 'owner', 'helper', 'outsider', 'order', 'line', 'workOrder', 'target', 'task', 'shipment', 'shipmentLine', 'transaction');
    }

    public function test_entire_order_query_uses_consumed_old_stock_goods_amount_excluding_freight_and_packaging_fee(): void
    {
        $this->confirm(); $this->fakeSources();
        $result = $this->statistics();
        $this->assertTrue($result['statistics_complete']);
        $this->assertSame('1000.0000', $result['rows']['data'][0]['basis_amount']);
        $this->assertSame('300.0000', $result['performance_pool_amount']);
        $this->assertSame('240.0000', $result['personal_performance_amount']);
        $this->assertSame('60.0000', $result['noncredited_amount']);
        $this->assertSame('240.0000', $result['employees']['data'][0]['performance_amount']);
        $this->assertSame('discounted_goods_incl_tax', $result['basis_policy']['code']);
    }

    public function test_partial_and_only_posted_shipments_do_not_calculate_any_amount(): void
    {
        $this->confirm(); $this->c['shipmentLine']->update(['sales_qty' => 1, 'base_qty' => 1]); $this->fakeSources(1);
        $partial = $this->statistics();
        $this->assertFalse($partial['readiness']['entire_order_shipped']); $this->assertNull($partial['performance_pool_amount']);
        $this->assertNull($partial['rows']['data'][0]['personal_performance_amount']); $this->assertSame([], $partial['employees']['data']);
        $this->c['shipmentLine']->update(['sales_qty' => 2, 'base_qty' => 2]);
        $this->c['shipment']->update(['shipment_status' => 'outbound_posted', 'shipped_at' => null]); $this->fakeSources(2);
        $posted = $this->statistics();
        $this->assertSame('waiting_shipment', $posted['statistics_status']); $this->assertNull($posted['personal_performance_amount']);
    }

    public function test_zero_confirmed_share_is_valid_and_missing_share_remains_pending(): void
    {
        $this->fakeSources();
        $missing = $this->statistics();
        $this->assertSame('pending_shares', $missing['rows']['data'][0]['statistics_status']);
        $this->assertNull($missing['personal_performance_amount']);
        $this->confirm('0.00000000'); $zero = $this->statistics();
        $this->assertTrue($zero['statistics_complete']); $this->assertSame('0.0000', $zero['personal_performance_amount']);
        $this->assertSame('300.0000', $zero['noncredited_amount']);
        $this->assertSame('0.00000000', $zero['rows']['data'][0]['assignment']['shares'][0]['share_ratio']);
    }

    public function test_owner_confirms_share_versions_without_rewriting_labor_and_replay_is_bound_to_actor(): void
    {
        $laborBefore = ProductionLaborSession::where('target_id', $this->c['target']->id)->get()->toArray();
        $payload = $this->payload(); $service = app(ProductionPerformanceApplicationService::class);
        $first = $service->confirm('quantity_operation', $this->c['target']->id, $payload, $this->c['owner'], self::PERMISSIONS);
        $this->assertSame($first, $service->confirm('quantity_operation', $this->c['target']->id, $payload, $this->c['owner'], self::PERMISSIONS));
        $this->domain('performance_owner_required', fn () => $service->confirm('quantity_operation', $this->c['target']->id, $payload, $this->c['outsider'], self::PERMISSIONS));
        $this->domain('performance_assignment_version_conflict', fn () => $service->confirm('quantity_operation', $this->c['target']->id, $this->payload(), $this->c['owner'], self::PERMISSIONS));
        $next = $this->payload('0.60000000'); $next['expected_assignment_version'] = 1;
        $second = $service->confirm('quantity_operation', $this->c['target']->id, $next, $this->c['owner'], self::PERMISSIONS);
        $this->assertSame(2, $second['version_no']); $this->assertDatabaseHas('erp_production_performance_assignments', ['id' => $first['id'], 'status' => 'superseded', 'active_scope_key' => null]);
        $this->assertSame('0.80000000', ProductionPerformanceAssignment::findOrFail($first['id'])->shares()->where('employee_legacy_id', $this->c['owner']->legacy_id)->value('share_ratio'));
        $this->assertSame($laborBefore, ProductionLaborSession::where('target_id', $this->c['target']->id)->get()->toArray());
        $this->assertArrayNotHasKey('performance_amount', $first); $this->assertArrayNotHasKey('basis_amount', $first);
    }

    public function test_source_quantity_missing_rate_and_legacy_zero_tax_amount_stay_pending(): void
    {
        $this->confirm(); $this->fakeSources(1);
        $quantity = $this->statistics(); $this->assertFalse($quantity['statistics_complete']);
        $this->assertContains('shipment_source_quantity_mismatch', array_column($quantity['issues']['data'], 'code'));
        $this->assertNull($quantity['rows']['data'][0]['personal_performance_amount']);
        $this->fakeSources(); $this->c['target']->update(['performance_rate_snapshot' => null]);
        $rate = $this->statistics(); $this->assertSame('pending_rate', $rate['rows']['data'][0]['statistics_status']); $this->assertNull($rate['personal_performance_amount']);
        $this->c['target']->update(['performance_rate_snapshot' => .3]); $this->c['line']->update(['amount_incl_tax' => 0]);
        $amount = $this->statistics(); $this->assertSame('pending_amount', $amount['rows']['data'][0]['statistics_status']); $this->assertNull($amount['personal_performance_amount']);
    }

    public function test_unposted_shipment_and_duplicate_source_rows_cannot_double_count_money(): void
    {
        $this->confirm(); $this->fakeSources(2, true);
        $duplicate = $this->statistics(); $this->assertSame('300.0000', $duplicate['performance_pool_amount']);
        $this->c['transaction']->update(['posting_status' => 'reversed']);
        $unposted = $this->statistics(); $this->assertFalse($unposted['readiness']['entire_order_shipped']); $this->assertNull($unposted['personal_performance_amount']);
    }

    public function test_owner_scope_entry_is_paginated_and_has_no_calculated_money(): void
    {
        $this->fakeSources(); $query = app(ProductionPerformanceQueryService::class);
        $scopes = $query->operations(['per_page' => 1], $this->c['owner'], self::PERMISSIONS);
        $this->assertSame(1, $scopes['meta']['total']); $this->assertSame('quantity_operation', $scopes['data'][0]['scope']['scope_type']);
        $this->assertArrayNotHasKey('basis_amount', $scopes['data'][0]); $this->assertArrayNotHasKey('performance_pool_amount', $scopes['data'][0]);
        $entry = $query->scope('quantity_operation', $this->c['target']->id, ['per_page' => 1], $this->c['owner'], self::PERMISSIONS);
        $this->assertSame(2, $entry['participants']['meta']['total']); $this->assertCount(1, $entry['participants']['data']);
        $this->assertSame([], $query->operations([], $this->c['outsider'], self::PERMISSIONS)['data']);
        $this->domain('performance_owner_required', fn () => $query->scope('quantity_operation', $this->c['target']->id, [], $this->c['outsider'], self::PERMISSIONS));
        $this->domain('permission_denied', fn () => $query->operations([], $this->c['owner'], ['production.task.view']));
    }

    public function test_owner_http_share_dialog_reads_pages_confirms_and_reloads_assignment_versions(): void
    {
        $c = $this->c;
        $this->performanceHttpContext($c['owner'], self::PERMISSIONS);
        $scopeUrl = '/api/v1/erp/production/performance/scopes/quantity_operation/'.$c['target']->id;
        $assignmentUrl = '/api/v1/erp/production/performance/assignments/quantity_operation/'.$c['target']->id;
        $laborBefore = ProductionLaborSession::where('target_id', $c['target']->id)->get()->toArray();
        $firstPage = $this->getJson($scopeUrl.'?page=1&per_page=1')->assertOk();
        $firstPage->assertJsonStructure(['data' => [
            'scope' => ['scope_type', 'scope_id', 'scope_version', 'owner_legacy_id', 'status', 'operation_name', 'work_order_no', 'task_no'],
            'participants' => ['data' => [['employee_legacy_id', 'employee_name', 'identity_exists', 'actual_labor_minutes']],
                'meta' => ['total', 'current_page', 'per_page', 'last_page']], 'assignment',
        ]])->assertJsonPath('data.scope.scope_type', 'quantity_operation')->assertJsonPath('data.scope.scope_id', $c['target']->id)
            ->assertJsonPath('data.scope.scope_version', 1)->assertJsonPath('data.scope.owner_legacy_id', $c['owner']->legacy_id)
            ->assertJsonPath('data.scope.status', 'COMPLETED')->assertJsonPath('data.scope.operation_name', '历史焊接')
            ->assertJsonPath('data.scope.work_order_no', $c['workOrder']->work_order_no)->assertJsonPath('data.scope.task_no', $c['task']->task_no)
            ->assertJsonPath('data.assignment', null)->assertJsonPath('data.participants.meta.total', 2)
            ->assertJsonPath('data.participants.meta.current_page', 1)->assertJsonPath('data.participants.meta.per_page', 1)
            ->assertJsonPath('data.participants.meta.last_page', 2)->assertJsonCount(1, 'data.participants.data');
        $this->assertArrayNotHasKey('participants', $firstPage->json('data.scope'));
        $secondPage = $this->getJson($scopeUrl.'?page=2&per_page=1')->assertOk()
            ->assertJsonPath('data.participants.meta.current_page', 2)->assertJsonCount(1, 'data.participants.data');
        $participants = array_merge($firstPage->json('data.participants.data'), $secondPage->json('data.participants.data'));
        $expectedIds = [$c['owner']->legacy_id, $c['helper']->legacy_id]; sort($expectedIds);
        $this->assertSame($expectedIds, array_column($participants, 'employee_legacy_id'));
        foreach ($participants as $participant) {
            $this->assertTrue($participant['identity_exists']);
            $this->assertSame(20.0, (float) $participant['actual_labor_minutes']);
        }

        $payload = $this->payload();
        $payload['shares'][] = ['employee_legacy_id' => $c['helper']->legacy_id, 'eligible' => false,
            'share_ratio' => '0.20000000', 'remark' => '临时参与，不计个人绩效'];
        $saved = $this->putJson($assignmentUrl, $payload)->assertOk();
        $saved->assertJsonStructure(['message', 'data' => ['id', 'scope_type', 'scope_id', 'owner_legacy_id', 'scope_version',
            'version_no', 'status', 'credited_share_ratio', 'noncredited_share_ratio', 'noncredited_confirmed', 'noncredited_reason',
            'shares' => [['employee_legacy_id', 'employee_name', 'eligible', 'share_ratio', 'remark']]]])
            ->assertJsonPath('data.version_no', 1)->assertJsonPath('data.scope_version', 1)->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.credited_share_ratio', '0.80000000')->assertJsonPath('data.noncredited_share_ratio', '0.20000000')
            ->assertJsonPath('data.noncredited_confirmed', true)->assertJsonPath('data.noncredited_reason', $payload['noncredited_reason']);
        $shares = collect($saved->json('data.shares'))->keyBy('employee_legacy_id');
        $this->assertTrue($shares[$c['owner']->legacy_id]['eligible']);
        $this->assertSame('0.80000000', $shares[$c['owner']->legacy_id]['share_ratio']);
        $this->assertFalse($shares[$c['helper']->legacy_id]['eligible']);
        $this->assertSame('0.20000000', $shares[$c['helper']->legacy_id]['share_ratio']);
        $replay = $this->putJson($assignmentUrl, $payload)->assertOk();
        $this->assertSame($saved->json('data'), $replay->json('data'));
        $reloaded = $this->getJson($scopeUrl.'?page=2&per_page=1')->assertOk()
            ->assertJsonPath('data.assignment.id', $saved->json('data.id'))->assertJsonPath('data.assignment.version_no', 1)
            ->assertJsonPath('data.assignment.scope_version', 1)->assertJsonCount(2, 'data.assignment.shares');
        $this->assertSame($saved->json('data'), $reloaded->json('data.assignment'));

        $revision = $this->payload('0.60000000'); $revision['expected_assignment_version'] = 1;
        $revision['shares'][] = ['employee_legacy_id' => $c['helper']->legacy_id, 'eligible' => false, 'share_ratio' => '0.40000000'];
        $this->putJson($assignmentUrl, $revision)->assertOk()->assertJsonPath('data.version_no', 2)
            ->assertJsonPath('data.scope_version', 1)->assertJsonPath('data.credited_share_ratio', '0.60000000')
            ->assertJsonPath('data.noncredited_share_ratio', '0.40000000');
        $current = $this->getJson($scopeUrl.'?per_page=1')->assertOk()->assertJsonPath('data.assignment.version_no', 2)
            ->assertJsonPath('data.scope.scope_version', 1)->assertJsonPath('data.assignment.credited_share_ratio', '0.60000000');
        $this->assertDatabaseHas('erp_production_performance_assignments', ['id' => $saved->json('data.id'), 'status' => 'superseded', 'active_scope_key' => null]);
        $this->putJson($assignmentUrl, $this->payload())->assertStatus(409)
            ->assertJsonPath('error_code', 'performance_assignment_version_conflict')->assertJsonPath('details.current_version', 2);
        $this->assertSame($laborBefore, ProductionLaborSession::where('target_id', $c['target']->id)->get()->toArray());
        foreach ([$firstPage, $saved, $current] as $response) foreach (['basis_amount', 'performance_amount', 'performance_pool_amount', 'noncredited_amount'] as $field)
            $this->assertStringNotContainsString($field, $response->getContent());
    }

    public function test_http_share_scope_read_permission_and_confirmation_permission_are_enforced(): void
    {
        $scopeUrl = '/api/v1/erp/production/performance/scopes/quantity_operation/'.$this->c['target']->id;
        $assignmentUrl = '/api/v1/erp/production/performance/assignments/quantity_operation/'.$this->c['target']->id;
        $this->performanceHttpContext($this->c['owner'], ['production.performance.view']);
        $this->getJson($scopeUrl)->assertOk()->assertJsonPath('data.assignment', null);
        $this->putJson($assignmentUrl, $this->payload())->assertForbidden()->assertJsonPath('error_code', 'permission_denied');
        $this->performanceHttpContext($this->c['owner'], ['production.performance.manage']);
        $this->getJson($scopeUrl)->assertForbidden()->assertJsonPath('error_code', 'permission_denied');
        $this->performanceHttpContext($this->c['owner'], []);
        $this->getJson($scopeUrl)->assertForbidden()->assertJsonPath('error_code', 'permission_denied');
        $this->putJson($assignmentUrl, $this->payload())->assertForbidden()->assertJsonPath('error_code', 'permission_denied');
        $this->assertDatabaseMissing('erp_production_performance_assignments', ['scope_type' => 'quantity_operation', 'scope_id' => $this->c['target']->id]);
    }

    public function test_http_actual_participant_cannot_read_or_confirm_another_owners_share_scope(): void
    {
        $this->performanceHttpContext($this->c['helper'], self::PERMISSIONS);
        $this->getJson('/api/v1/erp/production/performance/scopes/quantity_operation/'.$this->c['target']->id)
            ->assertForbidden()->assertJsonPath('error_code', 'performance_owner_required');
        $this->putJson('/api/v1/erp/production/performance/assignments/quantity_operation/'.$this->c['target']->id, $this->payload())
            ->assertForbidden()->assertJsonPath('error_code', 'performance_owner_required');
        $this->assertDatabaseMissing('erp_production_performance_assignments', ['scope_type' => 'quantity_operation', 'scope_id' => $this->c['target']->id]);
    }

    public function test_changed_completed_scope_requires_a_new_personal_share_confirmation(): void
    {
        $this->confirm(); $this->fakeSources();
        $this->c['target']->update(['business_version' => 2]);
        $result = $this->statistics();
        $this->assertFalse($result['statistics_complete']); $this->assertFalse($result['rows']['data'][0]['assignment_current']);
        $this->assertSame('pending_shares', $result['rows']['data'][0]['statistics_status']); $this->assertNull($result['personal_performance_amount']);
        $payload = $this->payload(); $payload['expected_scope_version'] = 2; $payload['expected_assignment_version'] = 1;
        app(ProductionPerformanceApplicationService::class)->confirm('quantity_operation', $this->c['target']->id, $payload, $this->c['owner'], self::PERMISSIONS);
        $this->assertTrue($this->statistics()['statistics_complete']);
    }

    public function test_packing_operation_uses_only_its_factual_product_contents(): void
    {
        $c = $this->c; $this->confirm();
        $secondItem = Item::create(['item_code' => 'PF-MIX-'.$c['suffix'], 'item_name' => '混装第二种成品', 'item_type' => 'finished_good', 'unit_id' => $c['unit']->id, 'status' => 'enabled']);
        $secondLine = SalesOrderLine::create(['sales_order_id' => $c['order']->id, 'line_no' => 3, 'item_id' => $secondItem->id, 'item_name' => $secondItem->item_name,
            'line_type' => 'physical', 'order_qty' => 4, 'unit_price' => 150, 'amount' => 600, 'amount_incl_tax' => 600, 'fulfillment_factor_snapshot' => 1,
            'item_base_unit_id' => $c['unit']->id, 'item_base_required_qty' => 4]);
        $secondShipmentLine = SalesShipmentLine::create(['shipment_id' => $c['shipment']->id, 'sales_order_line_id' => $secondLine->id, 'item_id' => $secondItem->id,
            'warehouse_id' => $c['warehouse']->id, 'location_id' => $c['location']->id, 'batch_no' => 'PF-MIX-'.$c['suffix'], 'unit_id' => $c['unit']->id,
            'sales_qty' => 4, 'base_qty' => 4, 'line_status' => 'shipped']);
        $this->fakeSources(2, false, [['source_key' => 'PF-MIX-SOURCE-'.$c['suffix'], 'shipment_line_id' => $secondShipmentLine->id,
            'sales_order_line_id' => $secondLine->id, 'output_record_id' => 770002, 'base_qty' => '4', 'sales_qty' => '4', 'inventory_serial_id' => null, 'trace_status' => 'complete']]);
        $package = DB::table('erp_sales_shipment_packages')->insertGetId(['shipment_id' => $c['shipment']->id, 'package_no' => 'PF-PACK-'.$c['suffix'], 'created_at' => now(), 'updated_at' => now()]);
        $content = DB::table('erp_shipment_packing_contents')->insertGetId(['shipment_id' => $c['shipment']->id, 'package_id' => $package,
            'shipment_line_id' => $c['shipmentLine']->id, 'sales_order_line_id' => $c['line']->id, 'item_id' => $c['item']->id, 'base_qty' => 1,
            'serial_snapshot' => '[]', 'source_snapshot' => '{}', 'routing_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $secondContent = DB::table('erp_shipment_packing_contents')->insertGetId(['shipment_id' => $c['shipment']->id, 'package_id' => $package,
            'shipment_line_id' => $secondShipmentLine->id, 'sales_order_line_id' => $secondLine->id, 'item_id' => $secondItem->id, 'base_qty' => 2,
            'serial_snapshot' => '[]', 'source_snapshot' => '{}', 'routing_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $packing = DB::table('erp_shipment_packing_operations')->insertGetId(['shipment_id' => $c['shipment']->id, 'package_id' => $package,
            'operation_id' => 1, 'operation_name_snapshot' => '本次发货包装', 'performance_rate_snapshot' => .1, 'sequence' => 1, 'chain_key' => 'PF-'.$c['suffix'],
            'packing_content_ids' => json_encode([$content, $secondContent]), 'planned_base_qty' => 3, 'completed_base_qty' => 3, 'status' => 'COMPLETED',
            'owner_legacy_id' => $c['owner']->legacy_id, 'completed_at' => now(), 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $payload = $this->payload('0.50000000');
        app(ProductionPerformanceApplicationService::class)->confirm('shipment_packing_operation', $packing, $payload, $c['owner'], self::PERMISSIONS);
        $result = $this->statistics(); $rows = collect($result['rows']['data']);
        $packRows = $rows->filter(fn ($row) => $row['scope']['scope_type'] === 'shipment_packing_operation')->values();
        $this->assertTrue($result['statistics_complete']); $this->assertCount(2, $packRows);
        $this->assertSame(['500.0000', '300.0000'], $packRows->pluck('basis_amount')->all());
        $this->assertSame(['25.0000', '15.0000'], $packRows->pluck('personal_performance_amount')->all());
        $this->assertSame('560.0000', $result['performance_pool_amount']); $this->assertSame('424.0000', $result['personal_performance_amount']);
        $this->assertSame(2, $result['employees']['data'][0]['operations_count']);
    }

    public function test_old_stock_new_assembly_and_shipment_packing_merge_without_replacing_original_owners_or_rates(): void
    {
        $c = $this->c; $this->confirm(); $this->fakeSources();
        $assemblyWork = WorkOrder::create(['work_order_no' => 'PF-ASM-WO-'.$c['suffix'], 'source_type' => 'sales_order', 'source_id' => $c['order']->id,
            'output_item_id' => $c['item']->id, 'target_qty' => 2, 'target_base_qty' => 2, 'target_unit_id' => $c['unit']->id,
            'base_unit_id' => $c['unit']->id, 'status' => 'COMPLETED', 'business_version' => 1]);
        $assembly = ProductionQuantityOperation::create(['work_order_id' => $assemblyWork->id, 'operation_code_snapshot' => 'ASSEMBLY',
            'operation_name_snapshot' => '本次新装配', 'sequence_no_snapshot' => 1, 'status' => 'COMPLETED', 'planned_base_qty' => 2,
            'completed_base_qty' => 2, 'remaining_base_qty' => 0, 'responsible_user_legacy_id' => $c['helper']->legacy_id,
            'performance_rate_snapshot' => .1, 'completed_at' => now(), 'business_version' => 1]);
        $assemblyTask = ProductionTask::create(['task_no' => 'PF-ASM-T-'.$c['suffix'], 'work_order_id' => $assemblyWork->id, 'execution_mode' => 'quantity',
            'operation_code_snapshot' => 'ASSEMBLY', 'operation_name_snapshot' => '本次新装配', 'sequence_no_snapshot' => 1,
            'status' => 'COMPLETED', 'assignee_user_legacy_id' => $c['helper']->legacy_id, 'business_version' => 1]);
        ProductionTaskTarget::create(['task_id' => $assemblyTask->id, 'target_type' => 'quantity_operation', 'target_id' => $assembly->id, 'status_snapshot' => 'COMPLETED']);
        $assemblyPayload = $this->payload(.5); $assemblyPayload['shares'][0]['employee_legacy_id'] = $c['helper']->legacy_id;
        app(ProductionPerformanceApplicationService::class)->confirm('quantity_operation', $assembly->id, $assemblyPayload, $c['helper'], self::PERMISSIONS);
        $trace = Mockery::mock(ProductionOutputTraceService::class);
        $trace->shouldReceive('contributions')->andReturn([
            ['target_type' => 'quantity_operation', 'target_id' => $c['target']->id, 'work_order_id' => $c['workOrder']->id,
                'output_record_id' => 770000, 'allocated_base_qty' => '2', 'output_base_qty' => '10', 'total_target_output_qty' => '10', 'basis_fraction' => '1', 'trace_status' => 'complete'],
            ['target_type' => 'quantity_operation', 'target_id' => $assembly->id, 'work_order_id' => $assemblyWork->id,
                'output_record_id' => 770001, 'allocated_base_qty' => '2', 'output_base_qty' => '2', 'total_target_output_qty' => '2', 'basis_fraction' => '1', 'trace_status' => 'complete'],
        ]);
        app()->instance(ProductionOutputTraceService::class, $trace); app()->forgetInstance(ProductionPerformanceQueryService::class);
        $package = DB::table('erp_sales_shipment_packages')->insertGetId(['shipment_id' => $c['shipment']->id,
            'package_no' => 'PF-FULL-PACK-'.$c['suffix'], 'created_at' => now(), 'updated_at' => now()]);
        $content = DB::table('erp_shipment_packing_contents')->insertGetId(['shipment_id' => $c['shipment']->id, 'package_id' => $package,
            'shipment_line_id' => $c['shipmentLine']->id, 'sales_order_line_id' => $c['line']->id, 'item_id' => $c['item']->id, 'base_qty' => 2,
            'serial_snapshot' => '[]', 'source_snapshot' => '{}', 'routing_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $packing = DB::table('erp_shipment_packing_operations')->insertGetId(['shipment_id' => $c['shipment']->id, 'package_id' => $package,
            'operation_id' => 1, 'operation_name_snapshot' => '本次发货包装', 'performance_rate_snapshot' => .05, 'sequence' => 1, 'chain_key' => 'PF-FULL-'.$c['suffix'],
            'packing_content_ids' => json_encode([$content]), 'planned_base_qty' => 2, 'completed_base_qty' => 2, 'status' => 'COMPLETED',
            'owner_legacy_id' => $c['helper']->legacy_id, 'completed_at' => now(), 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $packingPayload = $this->payload(.4); $packingPayload['shares'][0]['employee_legacy_id'] = $c['helper']->legacy_id;
        app(ProductionPerformanceApplicationService::class)->confirm('shipment_packing_operation', $packing, $packingPayload, $c['helper'], self::PERMISSIONS);
        $result = $this->statistics();
        $this->assertTrue($result['statistics_complete']); $this->assertSame('450.0000', $result['performance_pool_amount']);
        $this->assertSame('310.0000', $result['personal_performance_amount']); $this->assertSame('140.0000', $result['noncredited_amount']);
        $employees = collect($result['employees']['data'])->keyBy('employee_legacy_id');
        $this->assertSame('240.0000', $employees[$c['owner']->legacy_id]['performance_amount']);
        $this->assertSame('70.0000', $employees[$c['helper']->legacy_id]['performance_amount']);
        $this->assertSame($c['owner']->legacy_id, $result['rows']['data'][0]['scope']['owner_legacy_id']);
        $this->assertSame('0.300000', $result['rows']['data'][0]['scope']['performance_rate_snapshot']);
    }

    public function test_warehouse_worker_document_http_boundary_removes_rates_and_money_from_nested_frozen_snapshots(): void
    {
        $c = $this->c;
        $route = ['operations' => [['routing_operation_id' => 9011, 'operation_name' => '发货包装', 'performance_rate' => .7]]];
        $c['shipmentLine']->update(['packing_routing_snapshot' => $route]);
        $c['line']->update(['product_snapshot' => ['production_context' => $route, 'unit_price' => 500, 'basis_amount' => 1000]]);
        $this->warehouseWorkerContext();
        $raw = app(\App\Services\Erp\WarehouseDocumentService::class)->show('sales_shipment', $c['shipment']->id, [], $c['owner'],
            ['sales_order.shipment.view', 'sales_order.shipment.dispatch'], false);
        $this->assertSame(.7, data_get($raw, 'lines.data.0.order_line.product_snapshot.production_context.operations.0.performance_rate'),
            '此回归必须穿过尚含绩效比例的真实仓库服务结果');
        $response = $this->getJson('/api/v1/erp/inventory/warehouse-workspace/documents/sales_shipment/'.$c['shipment']->id)->assertOk();
        $response->assertJsonPath('data.header.id', $c['shipment']->id)->assertJsonPath('data.header.shipment_status', 'shipped')
            ->assertJsonPath('data.lines.data.0.id', $c['shipmentLine']->id)
            ->assertJsonPath('data.lines.data.0.order_line.product_snapshot.production_context.operations.0.routing_operation_id', 9011);
        $this->assertSame(2.0, (float) $response->json('data.lines.data.0.base_qty'));
        foreach (['performance_rate', 'unit_price', 'basis_amount', 'actual_freight_amount', 'material_total_cost'] as $field)
            $this->assertStringNotContainsString($field, $response->getContent());
    }

    public function test_warehouse_worker_http_command_replay_redacts_money_without_rewriting_the_audit_fact(): void
    {
        $c = $this->c; $this->warehouseWorkerContext(); $commandId = 'PF-WH-'.Str::uuid(); $action = 'sales_shipment.dispatch';
        $stored = ['action' => $action, 'aggregate_id' => $c['shipment']->id, 'result' => ['id' => $c['shipment']->id, 'status' => 'shipped',
            'base_qty' => '2.00000000', 'unit_price' => 500, 'performance_amount' => '240.0000', 'material_total_cost' => '888.0000',
            'routing_snapshot' => ['operations' => [['routing_operation_id' => 9011, 'performance_rate' => '.7']]]]];
        DB::table('erp_warehouse_commands')->insert(['client_command_id' => $commandId, 'action' => $action, 'aggregate_id' => $c['shipment']->id,
            'actor_legacy_id' => $c['owner']->legacy_id, 'request_hash' => hash('sha256', json_encode([$action, $c['shipment']->id, $c['owner']->legacy_id, []], JSON_THROW_ON_ERROR)),
            'request_payload' => '[]', 'status' => 'SUCCEEDED', 'response' => json_encode($stored, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        $raw = app(\App\Services\Erp\WarehouseCommandService::class)->result($commandId, $c['owner'], ['sales_order.shipment.dispatch'], false);
        $this->assertSame('.7', data_get($raw, 'response.result.routing_snapshot.operations.0.performance_rate'));
        $result = $this->getJson('/api/v1/erp/inventory/warehouse-commands/result?client_command_id='.$commandId)->assertOk();
        $replay = $this->postJson('/api/v1/erp/inventory/warehouse-commands', ['action' => $action, 'aggregate_id' => $c['shipment']->id,
            'client_command_id' => $commandId, 'payload' => []])->assertOk();
        $result->assertJsonPath('data.status', 'SUCCEEDED')->assertJsonPath('data.response.result.status', 'shipped')
            ->assertJsonPath('data.response.result.routing_snapshot.operations.0.routing_operation_id', 9011);
        $replay->assertJsonPath('data.aggregate_id', $c['shipment']->id)->assertJsonPath('data.result.base_qty', '2.00000000')
            ->assertJsonPath('data.result.routing_snapshot.operations.0.routing_operation_id', 9011);
        foreach ([$result, $replay] as $response) foreach (['performance_rate', 'performance_amount', 'unit_price', 'material_total_cost'] as $field)
            $this->assertStringNotContainsString($field, $response->getContent());
        $audit = json_decode(DB::table('erp_warehouse_commands')->where('client_command_id', $commandId)->value('response'), true);
        $this->assertEquals($stored, $audit, '仓库工人投影不能改写留存的原始成本与绩效审计事实');
    }

    private function warehouseWorkerContext(): void
    {
        $actor = $this->c['owner'];
        $this->mock(\App\Services\Erp\AuthContextService::class, function ($mock) use ($actor): void {
            $mock->shouldReceive('currentUser')->andReturn($actor);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturn(['sales_order.shipment.view', 'sales_order.shipment.dispatch']);
            $mock->shouldReceive('dataScope')->andReturn('all');
        });
    }

    private function performanceHttpContext(object $actor, array $permissions): void
    {
        $this->mock(\App\Services\Erp\AuthContextService::class, function ($mock) use ($actor, $permissions): void {
            $mock->shouldReceive('currentUser')->andReturn($actor);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturn($permissions);
        });
    }

    private function fakeSources(int $salesQty = 2, bool $duplicate = false, array $additionalSources = []): void
    {
        $c = $this->c;
        $source = ['source_key' => 'PF-SOURCE-'.$c['suffix'], 'shipment_line_id' => $c['shipmentLine']->id, 'sales_order_line_id' => $c['line']->id,
            'output_record_id' => 770001, 'base_qty' => (string) $salesQty, 'sales_qty' => (string) $salesQty, 'inventory_serial_id' => null, 'trace_status' => 'complete'];
        $resolver = Mockery::mock(ProductionShipmentSourceResolver::class); $resolver->shouldReceive('forOrder')->andReturn(array_merge($duplicate ? [$source, $source] : [$source], $additionalSources));
        $trace = Mockery::mock(ProductionOutputTraceService::class); $trace->shouldReceive('contributions')->andReturnUsing(fn ($outputId, $baseQty) => [['target_type' => 'quantity_operation',
            'target_id' => $c['target']->id, 'work_order_id' => $c['workOrder']->id, 'output_record_id' => $outputId, 'allocated_base_qty' => (string) $baseQty,
            'output_base_qty' => '10', 'total_target_output_qty' => '10', 'basis_fraction' => '1.00000000', 'trace_status' => 'complete']]);
        app()->instance(ProductionShipmentSourceResolver::class, $resolver); app()->instance(ProductionOutputTraceService::class, $trace);
        app()->forgetInstance(ProductionPerformanceQueryService::class);
    }
    private function statistics(): array { return app(ProductionPerformanceQueryService::class)->show($this->c['order']->id, [], $this->c['owner'], self::PERMISSIONS, true); }
    private function confirm(string $ratio = '0.80000000'): array { return app(ProductionPerformanceApplicationService::class)->confirm('quantity_operation', $this->c['target']->id, $this->payload($ratio), $this->c['owner'], self::PERMISSIONS); }
    private function payload(string $ratio = '0.80000000'): array
    {
        return ['client_command_id' => 'PF-CMD-'.Str::uuid(), 'expected_scope_version' => 1, 'expected_assignment_version' => 0,
            'shares' => [['employee_legacy_id' => $this->c['owner']->legacy_id, 'eligible' => true, 'share_ratio' => $ratio]],
            'noncredited_confirmed' => true, 'noncredited_reason' => '剩余为临时参与，不计个人绩效'];
    }
    private function user(string $name): object
    {
        $id = random_int(2300000, 2399999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $id, 'username' => 'pf-'.$id, 'nickname' => $name, 'status' => 'normal',
            'department_names' => '[]', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        return (object) ['legacy_id' => $id, 'nickname' => $name];
    }
    private function domain(string $code, callable $action): void
    {
        try { $action(); $this->fail('预期业务规则拒绝 '.$code); }
        catch (WorkOrderDomainException $e) { $this->assertSame($code, $e->errorCode); }
    }
}
