<?php

namespace Tests\Feature\Erp;

use App\Http\Controllers\Api\V1\Erp\{BomController, InventoryBalanceController};
use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Bom, BomItem, InventoryBalance, Item, ItemCategory, Product, ProductionDemand, ProductionRouting, SalesOrder, SalesOrderLine, Sku, WorkOrder};
use App\Models\Erp\Unit;
use App\Models\Erp\{MaterialDelivery, MaterialDeliveryLine, MaterialPickingTask, MaterialPickingTaskLine};
use App\Models\Erp\{InventoryLocationBalance, SalesShipment, SalesShipmentPackage, ShipmentPackingOperation};
use App\Models\Erp\{ProductionLaborSession, ProductionOutputRecord};
use App\Services\Erp\{AssemblyProductionApplicationService, CuttingMaterialEligibilityService, ProductionMasterDataService,
    RbacBootstrapService, ReleaseGateApplicationService, RoutingOperationOutputRuleService, SkuItemDefaultRelationService,
    StockPrebuildEligibilityService, WorkOrderApplicationService, WorkOrderPlannedOutputOptionService};
use App\Services\Erp\{ProductionInternalIssueService, ProductionMaterialExecutionService};
use App\Services\Erp\{CuttingReadService, CuttingRecordService, CuttingWorkerOrderService,
    ProductionExecutionActionService, ProductionOutputService};
use App\Services\Erp\{CuttingConfirmationService, CuttingHandoverService, CuttingWarehouseReceiptService, ProductionHandoverService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductionItemManagementScopeTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = ['production.work_order.view', 'production.work_order.create', 'production.work_order.edit',
        'production.work_order.submit', 'production.work_order.publish', 'production.work_order.gate.view',
        'production.operation.view', 'production.routing.view', 'sku_item_relation.set', 'sku_item_relation.change',
        'production.material_receipt.view', 'production.material_receipt.confirm', 'production.output.receive',
        'sales_order.shipment.packing.execute', 'production.task.complete', 'production.output.warehouse',
        'production.cutting.view', 'production.cutting.record', 'production.cutting.issue', 'production.cutting.confirm',
        'production.handover.receive', 'production.handover.reject', 'production.cutting.handover.dispatch',
        'production.cutting.handover.receive', 'production.cutting.handover.reject', 'production.cutting.warehouse'];

    public function test_production_selectors_ignore_a_forged_office_filter_and_only_offer_factory_items_and_categories(): void
    {
        $f = $this->fixture();
        $category = ItemCategory::create(['category_code' => $this->code('FC'), 'category_name' => '工厂分类',
            'management_scope' => 'factory', 'category_type' => 'item', 'status' => 'enabled']);
        $officeCategory = ItemCategory::create(['category_code' => $this->code('OC'), 'category_name' => '办公分类',
            'management_scope' => 'office', 'category_type' => 'item', 'status' => 'enabled']);
        $f['output']->update(['category_id' => $category->id]);
        $office = $this->item($f['unit'], 'office', ['category_id' => $officeCategory->id]);
        $query = app(ProductionMasterDataService::class)->selector('items', ['management_scope' => 'office', 'keyword' => $office->item_code], self::PERMISSIONS, true);
        $this->assertSame(0, $query->total());
        $this->assertFalse(app(StockPrebuildEligibilityService::class)->items()->whereKey($office->id)->exists());
        $options = app(WorkOrderPlannedOutputOptionService::class)->options($f['wo']->id,
            ['type' => 'items', 'management_scope' => 'office', 'keyword' => $office->item_code], $f['user'], self::PERMISSIONS, true);
        $this->assertSame(0, $options['total']);
        $categories = app(WorkOrderPlannedOutputOptionService::class)->options($f['wo']->id, ['type' => 'categories'], $f['user'], self::PERMISSIONS, true);
        $ids = array_column($categories['data'], 'id');
        $this->assertContains($category->id, $ids);
        $this->assertNotContains($officeCategory->id, $ids);
    }

    public function test_office_item_cannot_create_a_work_order_even_when_its_production_flags_are_forged(): void
    {
        $f = $this->fixture();
        $office = $this->item($f['unit'], 'office');
        [$route, $node] = $this->routing($office, [$f['component']]);
        $before = WorkOrder::count();
        $this->assertScopeRejected(fn () => app(WorkOrderApplicationService::class)->createDraft($this->draft($office, $route, $node), $f['user'], self::PERMISSIONS, true));
        $this->assertSame($before, WorkOrder::count());
    }

    public function test_office_by_product_cannot_be_saved_as_a_route_rule_or_work_order_planned_output(): void
    {
        $f = $this->fixture();
        $office = $this->item($f['unit'], 'office');
        $rule = ['output_rule_key' => (string) Str::uuid(), 'item_id' => $office->id, 'output_role' => 'by_product',
            'base_qty_per_reference_unit' => '1', 'quality_mode' => 'none', 'output_mode' => 'flow_only', 'allow_continue_without_warehouse' => true];
        $this->assertScopeRejected(fn () => app(RoutingOperationOutputRuleService::class)->prepare($f['routing'], [$rule], null));
        $before = $f['wo']->fresh()->business_version;
        $this->assertScopeRejected(fn () => app(WorkOrderApplicationService::class)->savePlannedOutputs($f['wo']->id,
            $this->command($f['wo']) + ['outputs' => [['line_uuid' => (string) Str::uuid(), 'item_id' => $office->id,
                'output_role' => 'by_product', 'planned_base_qty' => '1']]], $f['user'], self::PERMISSIONS, true));
        $this->assertSame($before, $f['wo']->fresh()->business_version);
        $this->assertSame(0, DB::table('erp_work_order_planned_outputs')->where('work_order_id', $f['wo']->id)->count());
        $this->assertSame(0, DB::table('erp_work_order_planned_output_versions')->where('work_order_id', $f['wo']->id)->count());
    }

    public function test_old_office_sales_demand_cannot_automatically_create_a_work_order(): void
    {
        $f = $this->fixture();
        $office = $this->item($f['unit'], 'office');
        $order = SalesOrder::create(['sales_order_no' => $this->code('SO'), 'customer_name' => '旧映射销售客户',
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed',
            'sales_user_legacy_id' => $f['user']->legacy_id, 'created_by_legacy_id' => $f['user']->legacy_id]);
        $line = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'line_uuid' => (string) Str::uuid(),
            'line_type' => 'physical', 'item_id' => $office->id, 'item_name' => $office->item_name,
            'order_qty' => 2, 'unit_id' => $f['unit']->id, 'item_base_unit_id' => $f['unit']->id, 'item_base_required_qty' => 2]);
        $demand = ProductionDemand::create(['requirement_no' => $this->code('D'), 'sales_order_id' => $order->id,
            'sales_order_line_id' => $line->id, 'item_id' => $office->id, 'production_qty' => 2, 'base_unit_id' => $f['unit']->id,
            'allocated_qty' => 0, 'remaining_qty' => 2, 'consumed_qty' => 0, 'closed_qty' => 0, 'requirement_status' => 'ready',
            'bom_match_status' => 'matched', 'is_active' => true, 'requirement_version' => 1, 'business_version' => 1]);
        $this->assertScopeRejected(fn () => DB::transaction(fn () => app(WorkOrderApplicationService::class)->ensureAutomaticSalesDraft($demand, $f['user'])));
        $this->assertSame(0, WorkOrder::where('production_demand_id', $demand->id)->count());
        $this->assertSame('0.00000000', (string) $demand->fresh()->allocated_qty);
        $this->assertSame(0, DB::table('erp_production_master_orders')->where('sales_order_id', $order->id)->count());
    }

    public function test_copying_a_legacy_route_with_office_supply_cannot_create_a_new_version(): void
    {
        $f = $this->fixture();
        DB::table('erp_items')->where('id', $f['component']->id)->update(['management_scope' => 'office']);
        $before = ProductionRouting::where('routing_no', $f['routing']->routing_no)->count();
        $this->assertScopeRejected(fn () => app(ProductionMasterDataService::class)->copyRouting($f['routing']->id,
            ['client_command_id' => $this->code('CMD')], $f['user'], self::PERMISSIONS, true));
        $this->assertSame($before, ProductionRouting::where('routing_no', $f['routing']->routing_no)->count());
    }

    public function test_bom_edit_rejects_an_office_component_and_rolls_back_header_and_existing_lines(): void
    {
        $f = $this->fixture();
        $office = $this->item($f['unit'], 'office');
        $f['bom']->update(['status' => 'draft', 'is_default' => false]);
        $oldName = $f['bom']->bom_name;
        $oldLine = $f['bom']->items()->first()->id;
        $payload = ['bom_name' => '不应保存的新名称', 'output_item_id' => $f['output']->id, 'bom_type' => 'standard',
            'version' => 'V1.0', 'items' => [['component_item_id' => $office->id, 'qty' => 1, 'unit_id' => $f['unit']->id]]];
        $this->assertScopeRejected(fn () => app(BomController::class)->update(Request::create('/', 'PUT', $payload), $f['bom']->id));
        $this->assertSame($oldName, $f['bom']->fresh()->bom_name);
        $this->assertSame([$oldLine], $f['bom']->items()->pluck('id')->all());
        $this->assertSame($f['component']->id, (int) $f['bom']->items()->first()->component_item_id);
    }

    public function test_existing_office_bom_and_legacy_scalar_route_cannot_publish_or_create_execution_rows(): void
    {
        $f = $this->fixture();
        // Simulate a pre-existing invalid reference without changing historical snapshots through a business API.
        DB::table('erp_items')->where('id', $f['component']->id)->update(['management_scope' => 'office']);
        $waiting = app(WorkOrderApplicationService::class)->submit($f['wo']->id, $this->command($f['wo']), $f['user'], self::PERMISSIONS, true);
        $gate = app(ReleaseGateApplicationService::class)->evaluate($waiting->id, $f['user'], self::PERMISSIONS, true);
        $this->assertFalse($gate['allowed']);
        $this->assertContains('office_item_not_allowed_in_production', array_column($gate['blockers'], 'reason_code'));
        try {
            app(WorkOrderApplicationService::class)->publish($waiting->id, $this->command($waiting), $f['user'], self::PERMISSIONS, true);
            $this->fail('Office BOM must not publish.');
        } catch (WorkOrderDomainException $e) { $this->assertSame('release_gate_blocked', $e->errorCode); }
        $this->assertSame('WAIT_RELEASE', $waiting->fresh()->status);
        $this->assertSame(0, $waiting->materialRequirements()->count());
        $this->assertSame(0, $waiting->productionTasks()->count());
        $this->assertNull(data_get($waiting->fresh()->routing_snapshot, 'output_plan'));
    }

    public function test_released_factory_work_order_retains_its_frozen_history_after_current_scope_changes(): void
    {
        $f = $this->fixture();
        $waiting = app(WorkOrderApplicationService::class)->submit($f['wo']->id, $this->command($f['wo']), $f['user'], self::PERMISSIONS, true);
        $released = app(WorkOrderApplicationService::class)->publish($waiting->id, $this->command($waiting), $f['user'], self::PERMISSIONS, true);
        $frozen = $released->routing_snapshot['output_plan'];
        $checks = DB::table('erp_work_order_release_gate_checks')->where('work_order_id', $released->id)->count();
        DB::table('erp_items')->whereIn('id', [$f['output']->id, $f['component']->id])->update(['management_scope' => 'office']);
        $gate = app(ReleaseGateApplicationService::class)->evaluate($released->id, $f['user'], self::PERMISSIONS, true);
        $this->assertTrue($gate['allowed']);
        $this->assertTrue($gate['immutable']);
        $this->assertSame($frozen, $gate['output_plan']);
        $this->assertSame($checks, DB::table('erp_work_order_release_gate_checks')->where('work_order_id', $released->id)->count());
    }

    public function test_sku_default_and_compatibility_write_cannot_bind_office_item(): void
    {
        $f = $this->fixture();
        $office = $this->item($f['unit'], 'office');
        $product = Product::create(['product_code' => $this->code('P'), 'product_name' => '工厂商品', 'product_type' => 'standard', 'status' => 'enabled']);
        $sku = Sku::create(['product_id' => $product->id, 'sku_code' => $this->code('SKU'), 'sku_name' => '标准规格',
            'sales_unit_id' => $f['unit']->id, 'order_line_type' => 'physical', 'fulfillment_type' => 'physical', 'status' => 'enabled']);
        $this->assertScopeRejected(fn () => app(SkuItemDefaultRelationService::class)->setPrimary($sku->id, $office->id,
            '首次设置', null, $f['user']->legacy_id, '测试人员'));
        $this->withToken($f['token'])->postJson('/api/v1/erp/master/sku-item-relations',
            ['sku_id' => $sku->id, 'item_id' => $office->id, 'factor' => 1, 'change_reason' => '首次设置'])->assertUnprocessable();
        $this->assertSame(0, DB::table('erp_sku_item_relations')->where('sku_id', $sku->id)->count());
        $this->assertSame(0, DB::table('erp_sku_item_relation_logs')->where('sku_id', $sku->id)->count());
    }

    public function test_inventory_scope_filters_rows_and_statistics_before_pagination_and_preserves_shared_default(): void
    {
        $f = $this->fixture();
        $office = $this->item($f['unit'], 'office');
        $factoryStock = $this->stock($f['output'], '2');
        $officeStock = $this->stock($office, '7', (int) $factoryStock->warehouse_id);
        $controller = app(InventoryBalanceController::class);
        foreach (['factory' => [$factoryStock->id, 10], 'office' => [$officeStock->id, 35]] as $scope => [$id, $value]) {
            $request = Request::create('/', 'GET', ['management_scope' => $scope, 'warehouse_id' => $factoryStock->warehouse_id]);
            $rows = $controller->index($request)->getData(true);
            $this->assertSame([$id], array_column($rows['data'], 'id'));
            $this->assertSame(1, $rows['stats']['item_count']);
            $this->assertSame((float) $value, (float) $rows['stats']['inventory_value']);
            $summary = $controller->index(Request::create('/', 'GET', ['view' => 'item', 'management_scope' => $scope,
                'warehouse_id' => $factoryStock->warehouse_id]))->getData(true);
            $this->assertSame(1, $summary['total']);
            $this->assertSame(1, $summary['stats']['item_count']);
            $this->assertSame($scope, $summary['data'][0]['item']['management_scope']);
        }
        $all = $controller->index(Request::create('/', 'GET', ['warehouse_id' => $factoryStock->warehouse_id]))->getData(true);
        $this->assertSame(2, $all['total']);
        $this->assertSame(2, $all['stats']['item_count']);
        $this->assertSame(45.0, (float) $all['stats']['inventory_value']);
        $this->assertScopeRejected(fn () => $controller->itemBatches(Request::create('/', 'GET', ['management_scope' => 'factory']), $office->id));
        $this->assertScopeRejected(fn () => $controller->show(Request::create('/', 'GET', ['management_scope' => 'factory']), $officeStock->id));
        $this->assertScopeRejected(fn () => $controller->index(Request::create('/', 'GET', ['management_scope' => 'unknown'])));
        $this->assertSame('7.0000', (string) $officeStock->fresh()->quantity_on_hand);
    }

    public function test_cutting_candidate_and_write_guard_exclude_office_raw_material_even_with_cutting_flags(): void
    {
        $f = $this->fixture();
        $raw = $this->item($f['unit'], 'office', ['item_type' => 'raw_material', 'cutting_mode' => 'length',
            'is_length_cut_material' => true, 'material_management_mode' => 'quantity', 'standard_stock_length_mm' => 6000]);
        $factory = $this->item($f['unit'], 'factory', ['item_type' => 'raw_material', 'cutting_mode' => 'length',
            'is_length_cut_material' => true, 'material_management_mode' => 'quantity', 'standard_stock_length_mm' => 6000]);
        $eligibility = app(CuttingMaterialEligibilityService::class);
        $this->assertFalse($eligibility->workerMaterials()->where('id', $raw->id)->exists());
        $this->assertTrue($eligibility->workerMaterials()->where('id', $factory->id)->exists());
        try { $eligibility->assertItem(0, $raw->id); $this->fail('Office raw material must not be accepted.'); }
        catch (WorkOrderDomainException $e) { $this->assertSame('office_item_not_allowed_in_production', $e->errorCode); }
    }

    public function test_assembly_preparation_does_not_reserve_office_stock_or_create_child_orders(): void
    {
        $f = $this->fixture();
        DB::table('erp_items')->where('id', $f['component']->id)->update(['management_scope' => 'office', 'manufacturing_strategy' => 'make']);
        $balance = $this->stock($f['component'], '10');
        $before = WorkOrder::count();
        $preview = app(AssemblyProductionApplicationService::class)->preview($f['wo']->id, $f['user'], self::PERMISSIONS, true);
        $this->assertSame('blocked', $preview['status']);
        $this->assertContains('office_item_not_allowed_in_production', array_column($preview['issues'], 'code'));
        try { app(AssemblyProductionApplicationService::class)->prepare($f['wo']->id, $this->command($f['wo']), $f['user'], self::PERMISSIONS, true); $this->fail('Office component must not prepare.'); }
        catch (WorkOrderDomainException $e) { $this->assertSame('assembly_plan_blocked', $e->errorCode); }
        $this->assertSame($before, WorkOrder::count());
        $this->assertSame('0.0000', (string) $balance->fresh()->quantity_locked);
        $this->assertSame(0, DB::table('erp_assembly_production_plans')->where('root_work_order_id', $f['wo']->id)->count());
    }

    public function test_old_office_delivery_and_onsite_receipt_roll_back_but_rejection_remains_available(): void
    {
        $f = $this->publishedFixture();
        [$pick, $pickLine] = $this->pendingPick($f, 'dedicated_delivery');
        // Existing delivered input is a fixture; the tested receive/reject commands are actual application actions.
        $delivery = MaterialDelivery::create(['delivery_no' => $this->code('DEL'), 'work_order_id' => $f['wo']->id,
            'picking_task_id' => $pick->id, 'production_target_type' => 'quantity_operation', 'production_target_id' => $f['target']->id,
            'expected_receiver_legacy_id' => $f['user']->legacy_id, 'from_warehouse_id' => $pick->warehouse_id,
            'to_production_location_snapshot' => '实际装配工位',
            'status' => 'DELIVERED', 'business_version' => 1]);
        $line = MaterialDeliveryLine::create(['delivery_id' => $delivery->id, 'picking_task_line_id' => $pickLine->id,
            'material_requirement_id' => $f['requirement']->id, 'component_item_id' => $f['component']->id,
            'unit_id' => $f['unit']->id, 'batch_no' => $pickLine->batch_no, 'delivery_qty' => 2, 'received_qty' => 0, 'rejected_qty' => 0]);
        DB::table('erp_items')->where('id', $f['component']->id)->update(['management_scope' => 'office']);
        $service = app(ProductionMaterialExecutionService::class);
        $this->assertScopeRejected(fn () => $service->receiveDelivery($delivery->id,
            ['client_command_id' => $this->code('CMD'), 'expected_version' => 1,
                'lines' => [['delivery_line_id' => $line->id, 'accepted_qty' => 2, 'rejected_qty' => 0]]], $f['user'], self::PERMISSIONS, true));
        $this->assertSame('DELIVERED', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->business_version);
        $this->assertSame('0.00000000', (string) $line->fresh()->received_qty);
        $this->assertSame(0, $delivery->receipts()->count());
        $this->assertSame(0, DB::table('erp_production_input_holdings')->where('target_type', 'quantity_operation')->where('target_id', $f['target']->id)->count());

        $receipt = $service->receiveDelivery($delivery->id, ['client_command_id' => $this->code('CMD'), 'expected_version' => 1,
            'lines' => [['delivery_line_id' => $line->id, 'accepted_qty' => 0, 'rejected_qty' => 2, 'reject_reason' => '旧办公错误用料退回']]],
            $f['user'], self::PERMISSIONS, true);
        $this->assertSame('RECEIVED', $delivery->fresh()->status);
        $this->assertSame('0.00000000', (string) $receipt->lines->first()->accepted_qty);
        $this->assertSame('2.00000000', (string) $receipt->lines->first()->rejected_qty);
        $this->assertSame(0, DB::table('erp_production_input_holdings')->where('target_type', 'quantity_operation')->where('target_id', $f['target']->id)->count());

        [$onsite, $onsiteLine] = $this->pendingPick($f, 'onsite_cutting');
        $this->assertScopeRejected(fn () => $service->receiveOnsite($onsite->id,
            ['client_command_id' => $this->code('CMD'), 'expected_version' => 1,
                'lines' => [['picking_task_line_id' => $onsiteLine->id, 'accepted_qty' => 2]]], $f['user'], self::PERMISSIONS, true));
        $this->assertSame(0, DB::table('erp_material_receipts')->where('picking_task_id', $onsite->id)->count());
        $this->assertSame('0.00000000', (string) $onsiteLine->fresh()->received_qty);
        $this->assertSame('0.00000000', (string) $f['requirement']->fresh()->received_qty);
    }

    public function test_old_office_internal_issue_receipt_does_not_unlock_stock_or_post_inventory_or_cost(): void
    {
        $f = $this->publishedFixture();
        $balance = $this->stock($f['component'], '2');
        $balance->update(['quantity_available' => 0, 'quantity_locked' => 2]);
        $issue = DB::table('erp_production_internal_issue_tasks')->insertGetId(['issue_no' => $this->code('ISS'),
            'work_order_id' => $f['wo']->id, 'target_task_id' => $f['task']->id, 'target_type' => 'quantity_operation',
            'target_id' => $f['target']->id, 'source_type' => 'warehouse_required', 'status' => 'ISSUED',
            'expected_receiver_legacy_id' => $f['user']->legacy_id, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_production_internal_issue_lines')->insert(['issue_task_id' => $issue, 'item_id' => $f['component']->id,
            'inventory_balance_id' => $balance->id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
            'batch_no' => $balance->batch_no, 'issue_base_qty' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_items')->where('id', $f['component']->id)->update(['management_scope' => 'office']);
        $this->assertScopeRejected(fn () => app(ProductionInternalIssueService::class)->receive($issue,
            ['client_command_id' => $this->code('CMD'), 'expected_version' => 1], $f['user'], self::PERMISSIONS, true));
        $this->assertSame('ISSUED', DB::table('erp_production_internal_issue_tasks')->where('id', $issue)->value('status'));
        $this->assertSame(1, DB::table('erp_production_internal_issue_tasks')->where('id', $issue)->value('business_version'));
        $this->assertSame('2.0000', (string) $balance->fresh()->quantity_on_hand);
        $this->assertSame('2.0000', (string) $balance->fresh()->quantity_locked);
        $this->assertSame(0, DB::table('erp_inventory_transactions')->where('source_type', 'production_internal_issue')->where('source_id', $issue)->count());
        $this->assertSame(0, DB::table('erp_production_input_holdings')->where('target_type', 'quantity_operation')->where('target_id', $f['target']->id)->count());
    }

    public function test_packing_sources_and_identity_options_exclude_office_items_from_legacy_material_snapshots(): void
    {
        $f = $this->packingFixture();
        $url = '/api/v1/erp/production/shipment-packing/operations/'.$f['packing']->id;
        $response = $this->withToken($f['token'])->getJson($url.'/material-sources?include_categories=1&management_scope=office')->assertOk();
        $this->assertSame([$f['factoryStock']->id], array_column($response->json('data.data'), 'id'));
        $categories = array_column($response->json('categories'), 'id');
        $this->assertContains($f['factoryCategory']->id, $categories);
        $this->assertNotContains($f['officeCategory']->id, $categories);
        $this->getJson($url.'/material-identities?inventory_balance_id='.$f['officeStock']->id)->assertNotFound();
        $this->assertSame('7.0000', (string) $f['officeStock']->fresh()->quantity_on_hand);
        $this->assertSame(0, $f['packing']->materials()->count());
    }

    public function test_packing_office_consumption_rolls_back_a_mixed_command_and_factory_posting_replays_once(): void
    {
        $f = $this->packingFixture();
        $url = '/api/v1/erp/production/shipment-packing/operations/'.$f['packing']->id.'/actions';
        $beforeTransactions = DB::table('erp_inventory_transactions')->count();
        $rejected = ['action' => 'materials', 'expected_version' => 1, 'client_command_id' => $this->code('PACK-REJECT'),
            'materials' => [['inventory_balance_id' => $f['factoryStock']->id, 'base_qty' => '2'],
                ['inventory_balance_id' => $f['officeStock']->id, 'base_qty' => '1']]];
        $this->withToken($f['token'])->postJson($url, $rejected)->assertUnprocessable();
        $this->assertSame('5.0000', (string) $f['factoryStock']->fresh()->quantity_on_hand);
        $this->assertSame('25.0000', (string) $f['factoryStock']->fresh()->inventory_value);
        $this->assertSame('7.0000', (string) $f['officeStock']->fresh()->quantity_on_hand);
        $this->assertSame('35.0000', (string) $f['officeStock']->fresh()->inventory_value);
        $this->assertSame(0, $f['packing']->materials()->count());
        $this->assertSame(1, (int) $f['packing']->fresh()->business_version);
        $this->assertSame($beforeTransactions, DB::table('erp_inventory_transactions')->count());
        $this->assertSame(0, DB::table('erp_shipment_packing_commands')->where('client_command_id', $rejected['client_command_id'])->count());
        $this->assertSame(0, DB::table('erp_shipment_packing_logs')->where('operation_id', $f['packing']->id)->count());

        $posted = ['action' => 'materials', 'expected_version' => 1, 'client_command_id' => $this->code('PACK-POST'),
            'materials' => [['inventory_balance_id' => $f['factoryStock']->id, 'base_qty' => '2']]];
        $saved = $this->postJson($url, $posted)->assertOk()->json('data');
        $this->assertSame('3.0000', (string) $f['factoryStock']->fresh()->quantity_on_hand);
        $this->assertSame('15.0000', (string) $f['factoryStock']->fresh()->inventory_value);
        $this->assertSame('3.0000', (string) InventoryLocationBalance::where('item_id', $f['component']->id)
            ->where('location_id', $f['factoryStock']->location_id)->value('quantity_on_hand'));
        $this->assertSame('10.0000', (string) $f['packing']->materials()->firstOrFail()->cost_amount_snapshot);
        $this->assertSame(2, (int) $f['packing']->fresh()->business_version);
        $this->assertSame($beforeTransactions + 1, DB::table('erp_inventory_transactions')->count());
        // A completed command replays its persisted result even after current master data changes.
        DB::table('erp_items')->where('id', $f['component']->id)->update(['management_scope' => 'office']);
        $replayed = $this->postJson($url, $posted)->assertOk()->json('data');
        ksort($saved);
        ksort($replayed);
        $this->assertSame($saved, $replayed);
        $this->assertSame('3.0000', (string) $f['factoryStock']->fresh()->quantity_on_hand);
        $this->assertSame(1, $f['packing']->materials()->count());
        $this->assertSame($beforeTransactions + 1, DB::table('erp_inventory_transactions')->count());
        $this->assertSame(1, DB::table('erp_shipment_packing_commands')->where('client_command_id', $posted['client_command_id'])->count());
        $this->assertSame(1, DB::table('erp_shipment_packing_logs')->where('operation_id', $f['packing']->id)->count());
    }

    public function test_old_cutting_output_identity_cannot_record_or_submit_office_output_but_history_and_return_for_edit_remain_available(): void
    {
        $f = $this->fixture();
        $f['component']->update(['cutting_mode' => 'length', 'is_length_cut_material' => true,
            'material_management_mode' => 'quantity', 'standard_stock_length_mm' => '6000']);
        $f['bom']->items()->first()->update(['cut_length_mm' => '500', 'piece_qty' => '1']);
        $stock = $this->stock($f['component'], '5');
        InventoryLocationBalance::create(['item_id' => $stock->item_id, 'warehouse_id' => $stock->warehouse_id,
            'location_id' => $stock->location_id, 'unit_id' => $stock->unit_id, 'quantity_on_hand' => 5,
            'quantity_available' => 5, 'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0]);
        $created = app(CuttingWorkerOrderService::class)->create(['client_command_id' => $this->code('CUT'),
            'expected_version' => 0, 'inputs' => [['inventory_balance_id' => $stock->id, 'input_qty' => '1']]],
            $f['user'], self::PERMISSIONS, true);
        $batchId = $created['batches'][0]['settlement_batch_id'];
        $records = app(CuttingRecordService::class);
        $save = ['client_command_id' => $this->code('CUT-SAVE'), 'expected_version' => 1,
            'results' => [['client_row_id' => 'factory-result', 'result_type' => 'product', 'item_id' => $f['output']->id,
                'actual_qty' => '1', 'piece_qty' => '1', 'cut_length_mm' => '500', 'measurement_status' => 'MEASURED',
                'measurements' => ['length_mm' => '500']]]];
        $saved = $records->saveResults($batchId, $save, $f['user'], self::PERMISSIONS, true);
        $resultId = $saved['result_ids'][0];
        $allowedId = (int) DB::table('erp_cutting_results')->where('id', $resultId)->value('allowed_output_id');
        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'office']);
        $this->assertScopeRejected(fn () => $records->saveResults($batchId,
            ['client_command_id' => $this->code('CUT-BLOCK'), 'expected_version' => $saved['business_version'],
                'results' => [['client_row_id' => 'new-office-result', 'result_type' => 'product', 'allowed_output_id' => $allowedId,
                    'actual_qty' => '1', 'piece_qty' => '1', 'cut_length_mm' => '500', 'measurement_status' => 'MEASURED',
                    'measurements' => ['length_mm' => '500']]]], $f['user'], self::PERMISSIONS, true));
        $this->assertScopeRejected(fn () => $records->submit($batchId,
            ['client_command_id' => $this->code('CUT-SUBMIT'), 'expected_version' => $saved['business_version']],
            $f['user'], self::PERMISSIONS, true));
        $this->assertSame(1, DB::table('erp_cutting_results')->where('settlement_batch_id', $batchId)->count());
        $this->assertSame('DRAFT', DB::table('erp_cutting_results')->where('id', $resultId)->value('status'));
        $this->assertSame((int) $saved['business_version'], DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->value('business_version'));
        $this->assertEquals($saved, $records->saveResults($batchId, $save, $f['user'], self::PERMISSIONS, true));
        $history = app(CuttingReadService::class)->execution($created['cutting_order_id'], [], $f['user'], self::PERMISSIONS, true);
        $this->assertCount(1, $history['results']['data']);
        $this->assertSame($resultId, (int) $history['results']['data'][0]['id']);

        // Existing submitted facts may be returned for correction even if their original input was office stock.
        DB::table('erp_items')->where('id', $f['component']->id)->update(['management_scope' => 'office']);
        DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->update(['status' => 'WAIT_CONFIRM']);
        DB::table('erp_cutting_results')->where('id', $resultId)->update(['status' => 'SUBMITTED']);
        $returned = $records->returnForEdit($batchId, ['client_command_id' => $this->code('CUT-RETURN'),
            'expected_version' => $saved['business_version'], 'reason' => '纠正旧办公物料引用'], $f['user'], self::PERMISSIONS, true);
        $this->assertSame('PROCESSING', $returned['status']);
        $this->assertSame('DRAFT', DB::table('erp_cutting_results')->where('id', $resultId)->value('status'));
        $this->assertSame('4.0000', (string) $stock->fresh()->quantity_on_hand);
    }

    public function test_old_office_execution_target_cannot_create_or_reopen_output_and_completion_facts_roll_back(): void
    {
        $f = $this->publishedFixture();
        $f['target']->update(['status' => 'IN_PROGRESS']);
        $f['task']->update(['status' => 'IN_PROGRESS']);
        $session = ProductionLaborSession::create(['task_id' => $f['task']->id, 'target_type' => 'quantity_operation',
            'target_id' => $f['target']->id, 'employee_legacy_id' => $f['user']->legacy_id, 'role' => 'owner', 'status' => 'ENDED',
            'started_at' => now()->subMinute(), 'ended_at' => now(), 'actual_labor_minutes' => 1, 'credited_labor_minutes' => 0]);
        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'office']);
        $service = app(ProductionExecutionActionService::class);
        $command = ['client_command_id' => $this->code('COMPLETE'), 'expected_version' => $f['target']->business_version,
            'completed_base_qty' => 2, 'scrapped_base_qty' => 0];
        $this->assertScopeRejected(fn () => $service->complete($f['task']->id, 'quantity_operation', $f['target']->id,
            $command, $f['user'], self::PERMISSIONS));
        $this->assertSame('IN_PROGRESS', $f['target']->fresh()->status);
        $this->assertSame('0.00000000', (string) $f['target']->fresh()->completed_base_qty);
        $this->assertSame('2.00000000', (string) $f['target']->fresh()->remaining_base_qty);
        $this->assertSame('0.00', (string) $session->fresh()->credited_labor_minutes);
        $this->assertSame(0, DB::table('erp_production_reports')->where('task_id', $f['task']->id)->count());
        $this->assertSame(0, DB::table('erp_production_output_records')->where('work_order_id', $f['wo']->id)->count());
        $this->assertSame(0, DB::table('erp_production_execution_commands')->where('client_command_id', $command['client_command_id'])->count());

        $oldOutput = ProductionOutputRecord::create(['output_no' => $this->code('OLD-OUTPUT'), 'work_order_id' => $f['wo']->id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => $f['target']->id, 'output_item_id' => $f['output']->id,
            'output_base_qty' => 2, 'output_mode_snapshot' => 'warehouse_required', 'quality_mode_snapshot' => 'none',
            'status' => 'QUALITY_FAILED', 'created_by_legacy_id' => $f['user']->legacy_id, 'produced_at' => now(), 'business_version' => 1]);
        $f['target']->fresh()->update(['completed_base_qty' => 2, 'remaining_base_qty' => 0]);
        $this->assertScopeRejected(fn () => $service->complete($f['task']->id, 'quantity_operation', $f['target']->id,
            ['client_command_id' => $this->code('REWORK'), 'expected_version' => $f['target']->business_version,
                'completed_base_qty' => 0, 'scrapped_base_qty' => 0], $f['user'], self::PERMISSIONS));
        $this->assertSame('QUALITY_FAILED', $oldOutput->fresh()->status);
        $this->assertSame(1, $oldOutput->fresh()->business_version);
        $this->assertSame('0.00', (string) $session->fresh()->credited_labor_minutes);
    }

    public function test_old_office_output_cannot_post_inventory_but_successful_factory_receipt_replays_without_new_cost_or_stock(): void
    {
        $f = $this->publishedFixture();
        $f['target']->update(['status' => 'COMPLETED', 'completed_base_qty' => 2, 'remaining_base_qty' => 0, 'completed_at' => now()]);
        $f['task']->update(['status' => 'COMPLETED']);
        $output = ProductionOutputRecord::create(['output_no' => $this->code('OLD-OUTPUT'), 'work_order_id' => $f['wo']->id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => $f['target']->id, 'output_item_id' => $f['output']->id,
            'output_base_qty' => 2, 'output_mode_snapshot' => 'warehouse_required', 'quality_mode_snapshot' => 'none',
            'status' => 'WAIT_WAREHOUSE', 'created_by_legacy_id' => $f['user']->legacy_id, 'produced_at' => now(), 'business_version' => 1]);
        $lot = DB::table('erp_material_lots')->insertGetId(['lot_no' => $this->code('ML'), 'item_id' => $f['output']->id,
            'material_form' => 'PRODUCT', 'source_type' => 'production_output_record', 'source_id' => $output->id, 'created_at' => now(), 'updated_at' => now()]);
        $holding = DB::table('erp_material_holdings')->insertGetId(['material_lot_id' => $lot, 'position_type' => 'OUTPUT_WIP',
            'position_id' => $output->id, 'quantity' => 2, 'total_cost' => 10, 'status' => 'ACTIVE', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $output->update(['material_lot_id' => $lot, 'material_holding_id' => $holding, 'material_total_cost' => 10, 'material_loss_cost' => 0]);
        // Approved legacy completion is fixture data; the warehouse commands below are actual application actions.
        $completion = DB::table('erp_work_order_completions')->insertGetId(['completion_no' => $this->code('WC'),
            'client_command_id' => $this->code('WC-CMD'), 'work_order_id' => $f['wo']->id, 'status' => 'APPROVED',
            'submitted_base_qty' => 2, 'qualified_base_qty' => 2, 'preflight_snapshot' => '{}',
            'submitted_by_legacy_id' => $f['user']->legacy_id, 'submitted_at' => now(), 'reviewed_by_legacy_id' => $f['user']->legacy_id,
            'reviewed_at' => now(), 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_work_order_completion_lines')->insert(['completion_id' => $completion, 'output_record_id' => $output->id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => $f['target']->id, 'output_item_id' => $f['output']->id,
            'base_unit_id' => $f['unit']->id, 'submitted_base_qty' => 2, 'qualified_base_qty' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $point = $this->stock($f['component'], '1');
        $payload = ['client_command_id' => $this->code('WH-POST'), 'expected_version' => 1,
            'warehouse_id' => $point->warehouse_id, 'location_id' => $point->location_id, 'batch_no' => $this->code('FG'), 'posted_base_qty' => 2];
        $service = app(ProductionOutputService::class);
        $before = DB::table('erp_inventory_transactions')->count();
        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'office']);
        $this->assertScopeRejected(fn () => $service->warehouse($output->id, $payload, $f['user'], self::PERMISSIONS));
        $this->assertSame('WAIT_WAREHOUSE', $output->fresh()->status);
        $this->assertSame(1, $output->fresh()->business_version);
        $this->assertSame('10.0000', (string) DB::table('erp_material_holdings')->where('id', $holding)->value('total_cost'));
        $this->assertSame($before, DB::table('erp_inventory_transactions')->count());
        $this->assertSame(0, InventoryBalance::where('item_id', $f['output']->id)->count());
        $this->assertSame(0, DB::table('erp_work_order_finished_goods_receipts')->where('output_record_id', $output->id)->count());
        $this->assertSame(0, DB::table('erp_production_execution_commands')->where('client_command_id', $payload['client_command_id'])->count());

        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'factory']);
        $posted = $service->warehouse($output->id, $payload, $f['user'], self::PERMISSIONS);
        $balance = InventoryBalance::where('item_id', $f['output']->id)->firstOrFail();
        $this->assertSame('2.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('10.0000', (string) $balance->inventory_value);
        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'office']);
        $this->assertEquals($posted, $service->warehouse($output->id, $payload, $f['user'], self::PERMISSIONS));
        $this->assertSame('2.0000', (string) $balance->fresh()->quantity_on_hand);
        $this->assertSame('10.0000', (string) $balance->fresh()->inventory_value);
        $this->assertSame($before + 1, DB::table('erp_inventory_transactions')->count());
        $this->assertSame(1, DB::table('erp_work_order_finished_goods_receipts')->where('output_record_id', $output->id)->count());
        $this->assertSame(1, DB::table('erp_production_output_warehouse_postings')->where('output_record_id', $output->id)->count());
    }

    public function test_old_office_operation_handover_cannot_receive_new_input_but_rejection_and_successful_receipt_replay_remain_available(): void
    {
        $f = $this->productionHandoverFixture();
        $service = app(ProductionHandoverService::class);
        $payload = ['client_command_id' => $this->code('HO-RECEIVE'), 'expected_version' => 1];
        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'office']);
        $this->assertScopeRejected(fn () => $service->accept($f['handoverId'], $payload, $f['user'], self::PERMISSIONS, true));
        $this->assertSame('WAIT_RECEIVE', DB::table('erp_production_operation_handovers')->where('id', $f['handoverId'])->value('status'));
        $this->assertSame(1, DB::table('erp_production_operation_handovers')->where('id', $f['handoverId'])->value('business_version'));
        $this->assertSame('0.00000000', (string) DB::table('erp_production_target_material_requirements')->where('id', $f['consumerRequirement']->id)->value('satisfied_base_qty'));
        $this->assertSame('2.00000000', (string) DB::table('erp_material_holdings')->where('id', $f['holdingId'])->value('quantity'));
        $this->assertSame('10.0000', (string) DB::table('erp_material_holdings')->where('id', $f['holdingId'])->value('total_cost'));
        $this->assertSame(0, DB::table('erp_production_input_holdings')->where('operation_handover_id', $f['handoverId'])->count());
        $this->assertSame(0, DB::table('erp_material_movements')->where('source_holding_id', $f['holdingId'])->count());
        $this->assertSame(0, DB::table('erp_production_execution_commands')->where('client_command_id', $payload['client_command_id'])->count());

        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'factory']);
        $accepted = $service->accept($f['handoverId'], $payload, $f['user'], self::PERMISSIONS, true);
        $this->assertSame('RECEIVED', $accepted['status']);
        $this->assertSame('2.00000000', (string) DB::table('erp_production_target_material_requirements')->where('id', $f['consumerRequirement']->id)->value('satisfied_base_qty'));
        $input = DB::table('erp_production_input_holdings')->where('operation_handover_id', $f['handoverId'])->sole();
        $this->assertSame('2.00000000', (string) $input->quantity);
        $this->assertSame('10.0000', (string) $input->total_cost);
        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'office']);
        $this->assertEquals($accepted, $service->accept($f['handoverId'], $payload, $f['user'], self::PERMISSIONS, true));
        $this->assertSame(1, DB::table('erp_production_input_holdings')->where('operation_handover_id', $f['handoverId'])->count());
        $this->assertSame(1, DB::table('erp_material_movements')->where('source_holding_id', $f['holdingId'])->where('action', 'PRODUCTION_HANDOVER')->count());

        $rejected = $this->productionHandoverFixture();
        DB::table('erp_items')->where('id', $rejected['output']->id)->update(['management_scope' => 'office']);
        $decision = $service->reject($rejected['handoverId'], ['client_command_id' => $this->code('HO-REJECT'),
            'expected_version' => 1, 'reason' => '退回旧办公物料引用'], $rejected['user'], self::PERMISSIONS, true);
        $this->assertSame('REJECTED', $decision['status']);
        $this->assertSame('REWORK', $rejected['target']->fresh()->status);
        $this->assertSame('0.00000000', (string) $rejected['target']->fresh()->completed_base_qty);
        $this->assertSame('2.00000000', (string) $rejected['target']->fresh()->remaining_base_qty);
        $this->assertSame('HANDOVER_REJECTED', $rejected['record']->fresh()->status);
        $this->assertSame('10.0000', (string) DB::table('erp_material_holdings')->where('id', $rejected['holdingId'])->value('total_cost'));
        $this->assertSame(0, DB::table('erp_production_input_holdings')->where('operation_handover_id', $rejected['handoverId'])->count());
    }

    public function test_old_office_cutting_output_cannot_dispatch_or_receive_but_reject_return_and_successful_commands_replay(): void
    {
        $f = $this->cuttingDownstreamFixture('NEXT_OPERATION');
        $service = app(CuttingHandoverService::class);
        $dispatchPayload = ['client_command_id' => $this->code('CUT-DISPATCH'), 'expected_version' => 2, 'quantity' => '1'];
        $movementCount = DB::table('erp_material_movements')->where('route_id', $f['routeId'])->count();
        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'office']);
        $this->assertScopeRejected(fn () => $service->dispatch($f['routeId'], $dispatchPayload, $f['user'], self::PERMISSIONS, true));
        $this->assertSame('WAIT_DISPATCH', DB::table('erp_cutting_result_routes')->where('id', $f['routeId'])->value('status'));
        $this->assertSame(2, DB::table('erp_cutting_result_routes')->where('id', $f['routeId'])->value('business_version'));
        $this->assertSame('1.00000000', (string) DB::table('erp_material_holdings')->where('id', $f['holdingId'])->value('quantity'));
        $this->assertSame('5.0000', (string) DB::table('erp_material_holdings')->where('id', $f['holdingId'])->value('total_cost'));
        $this->assertSame(0, DB::table('erp_cutting_handovers')->where('route_id', $f['routeId'])->count());
        $this->assertSame($movementCount, DB::table('erp_material_movements')->where('route_id', $f['routeId'])->count());
        $this->assertSame(0, DB::table('erp_cutting_commands')->where('client_command_id', $dispatchPayload['client_command_id'])->count());

        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'factory']);
        $dispatched = $service->dispatch($f['routeId'], $dispatchPayload, $f['user'], self::PERMISSIONS, true);
        $handover = DB::table('erp_cutting_handovers')->where('id', $dispatched['handover_id'])->first();
        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'office']);
        $this->assertSame($dispatched, $service->dispatch($f['routeId'], $dispatchPayload, $f['user'], self::PERMISSIONS, true));
        $acceptPayload = ['client_command_id' => $this->code('CUT-RECEIVE'), 'expected_version' => 1, 'quantity' => '1'];
        $this->assertScopeRejected(fn () => $service->accept($handover->id, $acceptPayload, $f['user'], self::PERMISSIONS, true));
        $this->assertSame('IN_TRANSIT', DB::table('erp_cutting_handovers')->where('id', $handover->id)->value('status'));
        $this->assertSame('1.00000000', (string) DB::table('erp_material_holdings')->where('id', $handover->transit_holding_id)->value('quantity'));
        $this->assertSame('5.0000', (string) DB::table('erp_material_holdings')->where('id', $handover->transit_holding_id)->value('total_cost'));
        $this->assertSame('0.00000000', (string) DB::table('erp_production_target_material_requirements')->where('id', $f['consumerRequirement']->id)->value('satisfied_base_qty'));
        $this->assertSame(0, DB::table('erp_material_holdings')->where('position_type', 'PRODUCTION_WIP')->where('position_id', $f['consumerRequirement']->id)->count());
        $this->assertSame(0, DB::table('erp_cutting_handover_decisions')->where('handover_id', $handover->id)->count());
        $this->assertSame(0, DB::table('erp_cutting_commands')->where('client_command_id', $acceptPayload['client_command_id'])->count());

        $returned = $service->reject($handover->id, ['client_command_id' => $this->code('CUT-REJECT'),
            'expected_version' => 1, 'quantity' => '1', 'reason' => '拒收旧办公物料引用'], $f['user'], self::PERMISSIONS, true);
        $this->assertSame('REJECTED', $returned['status']);
        $this->assertSame('WAIT_DISPATCH', $returned['route_status']);
        $this->assertSame('1.00000000', (string) DB::table('erp_material_holdings')->where('id', $f['holdingId'])->value('quantity'));
        $this->assertSame('5.0000', (string) DB::table('erp_material_holdings')->where('id', $f['holdingId'])->value('total_cost'));
        $this->assertSame('0.00000000', (string) DB::table('erp_production_target_material_requirements')->where('id', $f['consumerRequirement']->id)->value('satisfied_base_qty'));

        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'factory']);
        $next = $service->dispatch($f['routeId'], ['client_command_id' => $this->code('CUT-REDISPATCH'),
            'expected_version' => (int) DB::table('erp_cutting_result_routes')->where('id', $f['routeId'])->value('business_version'),
            'quantity' => '1'], $f['user'], self::PERMISSIONS, true);
        $nextPayload = ['client_command_id' => $this->code('CUT-NEXT-RECEIVE'), 'expected_version' => 1, 'quantity' => '1'];
        $accepted = $service->accept($next['handover_id'], $nextPayload, $f['user'], self::PERMISSIONS, true);
        $this->assertSame('RECEIVED', $accepted['status']);
        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'office']);
        $this->assertSame($accepted, $service->accept($next['handover_id'], $nextPayload, $f['user'], self::PERMISSIONS, true));
        $this->assertSame('1.00000000', (string) DB::table('erp_production_target_material_requirements')->where('id', $f['consumerRequirement']->id)->value('satisfied_base_qty'));
        $this->assertSame(1, DB::table('erp_cutting_handover_decisions')->where('handover_id', $next['handover_id'])->where('action', 'ACCEPT')->count());
        $this->assertSame('5.0000', (string) DB::table('erp_material_holdings')->where('position_type', 'PRODUCTION_WIP')->where('position_id', $f['consumerRequirement']->id)->value('total_cost'));
    }

    public function test_old_office_cutting_warehouse_receipt_rolls_back_all_posting_facts_and_factory_posting_replays_once(): void
    {
        $f = $this->cuttingDownstreamFixture('WAREHOUSE');
        $service = app(CuttingWarehouseReceiptService::class);
        $payload = ['client_command_id' => $this->code('CUT-WH'), 'expected_version' => 2, 'quantity' => '1',
            'warehouse_id' => $f['stock']->warehouse_id, 'location_id' => $f['stock']->location_id, 'batch_no' => $this->code('CUT-FG')];
        $before = DB::table('erp_inventory_transactions')->count();
        $movementCount = DB::table('erp_material_movements')->where('route_id', $f['routeId'])->count();
        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'office']);
        $this->assertScopeRejected(fn () => $service->post($f['routeId'], $payload, $f['user'], self::PERMISSIONS, true));
        $this->assertSame('WAIT_WAREHOUSE', DB::table('erp_cutting_result_routes')->where('id', $f['routeId'])->value('status'));
        $this->assertSame(2, DB::table('erp_cutting_result_routes')->where('id', $f['routeId'])->value('business_version'));
        $this->assertSame('1.00000000', (string) DB::table('erp_material_holdings')->where('id', $f['holdingId'])->value('quantity'));
        $this->assertSame('5.0000', (string) DB::table('erp_material_holdings')->where('id', $f['holdingId'])->value('total_cost'));
        $this->assertSame($before, DB::table('erp_inventory_transactions')->count());
        $this->assertSame(0, InventoryBalance::where('item_id', $f['output']->id)->count());
        $this->assertSame(0, DB::table('erp_cutting_warehouse_receipts')->where('route_id', $f['routeId'])->count());
        $this->assertSame(0, DB::table('erp_cutting_commands')->where('client_command_id', $payload['client_command_id'])->count());
        $this->assertSame($movementCount, DB::table('erp_material_movements')->where('route_id', $f['routeId'])->count());

        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'factory']);
        $posted = $service->post($f['routeId'], $payload, $f['user'], self::PERMISSIONS, true);
        $balance = InventoryBalance::findOrFail($posted['inventory_balance_id']);
        $this->assertSame('1.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('5.0000', (string) $balance->inventory_value);
        DB::table('erp_items')->where('id', $f['output']->id)->update(['management_scope' => 'office']);
        $this->assertSame($posted, $service->post($f['routeId'], $payload, $f['user'], self::PERMISSIONS, true));
        $this->assertSame('1.0000', (string) $balance->fresh()->quantity_on_hand);
        $this->assertSame('5.0000', (string) $balance->fresh()->inventory_value);
        $this->assertSame($before + 1, DB::table('erp_inventory_transactions')->count());
        $this->assertSame(1, DB::table('erp_cutting_warehouse_receipts')->where('route_id', $f['routeId'])->count());
        $this->assertSame(1, DB::table('erp_material_movements')->where('route_id', $f['routeId'])->where('action', 'RECEIPT')->count());
    }

    private function productionHandoverFixture(): array
    {
        $f = $this->publishedFixture();
        $consumer = $this->consumerFixture($f, $f['output']);
        $f['target']->update(['status' => 'COMPLETED', 'completed_base_qty' => 2, 'remaining_base_qty' => 0, 'completed_at' => now()]);
        $f['task']->update(['status' => 'COMPLETED']);
        // These are existing traceable upstream output and handover facts; decisions below use the real services.
        $record = ProductionOutputRecord::create(['output_no' => $this->code('HO-OUTPUT'), 'work_order_id' => $f['wo']->id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => $f['target']->id, 'output_item_id' => $f['output']->id,
            'output_base_qty' => 2, 'output_mode_snapshot' => 'flow_only', 'quality_mode_snapshot' => 'none',
            'status' => 'HANDED_OVER', 'created_by_legacy_id' => $f['user']->legacy_id, 'produced_at' => now(), 'business_version' => 1]);
        $lotId = DB::table('erp_material_lots')->insertGetId(['lot_no' => $this->code('ML'), 'item_id' => $f['output']->id,
            'material_form' => 'PRODUCT', 'source_type' => 'production_output_record', 'source_id' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
        $holdingId = DB::table('erp_material_holdings')->insertGetId(['material_lot_id' => $lotId, 'position_type' => 'OUTPUT_WIP',
            'position_id' => $record->id, 'quantity' => 2, 'total_cost' => 10, 'status' => 'ACTIVE', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $record->update(['material_lot_id' => $lotId, 'material_holding_id' => $holdingId, 'material_total_cost' => 10, 'material_loss_cost' => 0]);
        $handoverId = DB::table('erp_production_operation_handovers')->insertGetId(['handover_no' => $this->code('HO'), 'work_order_id' => $f['wo']->id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => $f['target']->id, 'target_target_type' => 'quantity_operation',
            'target_target_id' => $consumer['consumerTarget']->id, 'target_material_requirement_id' => $consumer['consumerRequirement']->id,
            'output_record_id' => $record->id, 'status' => 'WAIT_RECEIVE', 'handed_over_by_legacy_id' => $f['user']->legacy_id,
            'handed_over_at' => now(), 'expected_receiver_legacy_id' => $f['user']->legacy_id, 'identity_snapshot' => '{}',
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return $f + $consumer + compact('record', 'holdingId', 'handoverId');
    }

    private function cuttingDownstreamFixture(string $routeType): array
    {
        $f = $this->fixture();
        $f['component']->update(['cutting_mode' => 'length', 'is_length_cut_material' => true,
            'material_management_mode' => 'quantity', 'standard_stock_length_mm' => '6000']);
        $f['bom']->items()->first()->update(['cut_length_mm' => '500', 'piece_qty' => '1']);
        $stock = $this->stock($f['component'], '5');
        InventoryLocationBalance::create(['item_id' => $stock->item_id, 'warehouse_id' => $stock->warehouse_id,
            'location_id' => $stock->location_id, 'unit_id' => $stock->unit_id, 'quantity_on_hand' => 5,
            'quantity_available' => 5, 'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0]);
        $created = app(CuttingWorkerOrderService::class)->create(['client_command_id' => $this->code('CUT'),
            'expected_version' => 0, 'inputs' => [['inventory_balance_id' => $stock->id, 'input_qty' => '1']]], $f['user'], self::PERMISSIONS, true);
        $batchId = $created['batches'][0]['settlement_batch_id'];
        $records = app(CuttingRecordService::class);
        $saved = $records->saveResults($batchId, ['client_command_id' => $this->code('CUT-SAVE'), 'expected_version' => 1,
            'results' => [['client_row_id' => 'factory-result', 'result_type' => 'product', 'item_id' => $f['output']->id,
                'actual_qty' => '1', 'piece_qty' => '1', 'cut_length_mm' => '500', 'measurement_status' => 'MEASURED',
                'measurements' => ['length_mm' => '500']]]], $f['user'], self::PERMISSIONS, true);
        $resultId = $saved['result_ids'][0];
        $consumer = $routeType === 'NEXT_OPERATION' ? $this->consumerFixture($f, $f['output']) : [];
        $route = ['route_type' => $routeType, 'quantity' => '1'];
        if ($consumer !== []) $route['target_material_requirement_id'] = $consumer['consumerRequirement']->id;
        $split = $records->splitRoutes($resultId, ['client_command_id' => $this->code('CUT-SPLIT'), 'expected_version' => 1,
            'routes' => [$route]], $f['user'], self::PERMISSIONS, true);
        $routeId = $split['routes'][0]['id'];
        $submitted = $records->submit($batchId, ['client_command_id' => $this->code('CUT-SUBMIT'),
            'expected_version' => (int) DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->value('business_version')], $f['user'], self::PERMISSIONS, true);
        app(CuttingConfirmationService::class)->confirm($batchId, ['client_command_id' => $this->code('CUT-CONFIRM'),
            'expected_version' => $submitted['business_version'], 'costs' => [['result_id' => $resultId, 'total_cost' => '5']],
            'allocations' => [['route_id' => $routeId, 'quantity' => '1',
                'disposition' => $routeType === 'NEXT_OPERATION' ? 'WORK_ORDER' : 'PUBLIC_UNALLOCATED']]], $f['user'], self::PERMISSIONS, true);
        $holdingId = (int) DB::table('erp_cutting_result_routes')->where('id', $routeId)->value('holding_id');
        return $f + $consumer + compact('stock', 'batchId', 'resultId', 'routeId', 'holdingId');
    }

    private function consumerFixture(array $f, Item $component): array
    {
        $output = $this->item($f['unit'], 'factory');
        [$routing, $node] = $this->routing($output, [$component]);
        $bom = Bom::create(['bom_no' => $this->code('CONSUMER-BOM'), 'bom_name' => '实际下一工序BOM', 'output_item_id' => $output->id,
            'bom_type' => 'standard', 'version' => 'V1.0', 'is_default' => true, 'status' => 'active', 'audit_status' => 'approved']);
        BomItem::create(['bom_id' => $bom->id, 'line_no' => 10, 'component_item_id' => $component->id,
            'component_item_code' => $component->item_code, 'component_item_name' => $component->item_name,
            'qty' => 1, 'unit_id' => $f['unit']->id, 'loss_rate' => 0, 'fixed_qty' => 0]);
        $workOrders = app(WorkOrderApplicationService::class);
        $draft = $workOrders->createDraft($this->draft($output, $routing, $node), $f['user'], self::PERMISSIONS, true);
        $waiting = $workOrders->submit($draft->id, $this->command($draft), $f['user'], self::PERMISSIONS, true);
        $consumerWo = $workOrders->publish($waiting->id, $this->command($waiting), $f['user'], self::PERMISSIONS, true);
        $consumerTarget = $consumerWo->quantityOperations()->firstOrFail();
        $consumerTarget->update(['status' => 'WAIT_MATERIAL', 'responsible_user_legacy_id' => $f['user']->legacy_id, 'claimed_at' => now()]);
        $consumerTask = $consumerWo->productionTasks()->firstOrFail();
        $consumerTask->update(['status' => 'WAIT_MATERIAL', 'assignee_user_legacy_id' => $f['user']->legacy_id, 'claimed_at' => now()]);
        $consumerTask->targets()->where('target_type', 'quantity_operation')->where('target_id', $consumerTarget->id)->update(['status_snapshot' => 'WAIT_MATERIAL']);
        $consumerRequirement = DB::table('erp_production_target_material_requirements')->where('target_type', 'quantity_operation')->where('target_id', $consumerTarget->id)->sole();
        return compact('consumerWo', 'consumerTarget', 'consumerTask', 'consumerRequirement');
    }

    private function packingFixture(): array
    {
        $f = $this->fixture();
        $factoryCategory = ItemCategory::create(['category_code' => $this->code('FC'), 'category_name' => '工厂包装类目',
            'management_scope' => 'factory', 'category_type' => 'item', 'status' => 'enabled']);
        $officeCategory = ItemCategory::create(['category_code' => $this->code('OC'), 'category_name' => '办公类目',
            'management_scope' => 'office', 'category_type' => 'item', 'status' => 'enabled']);
        $f['component']->update(['category_id' => $factoryCategory->id]);
        $office = $this->item($f['unit'], 'office', ['category_id' => $officeCategory->id]);
        $factoryStock = $this->stock($f['component'], '5');
        $officeStock = $this->stock($office, '7', $factoryStock->warehouse_id);
        foreach ([$factoryStock, $officeStock] as $balance) InventoryLocationBalance::create([
            'item_id' => $balance->item_id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
            'unit_id' => $balance->unit_id, 'quantity_on_hand' => $balance->quantity_on_hand,
            'quantity_available' => $balance->quantity_available, 'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0]);
        $order = SalesOrder::create(['sales_order_no' => $this->code('SO'), 'customer_name' => '包装范围验证客户',
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'shipment_status' => 'not_shipped',
            'total_amount' => 0, 'final_receivable_amount' => 0]);
        $shipment = SalesShipment::create(['sales_order_id' => $order->id, 'shipment_no' => $this->code('SHIP'), 'shipment_status' => 'draft']);
        $package = SalesShipmentPackage::create(['shipment_id' => $shipment->id, 'package_no' => $this->code('PACKAGE')]);
        // A pre-existing packing job can contain invalid office references in its frozen requirements.
        $packing = ShipmentPackingOperation::create(['shipment_id' => $shipment->id, 'package_id' => $package->id,
            'routing_id' => $f['routing']->id, 'routing_operation_id' => $f['node'],
            'operation_id' => DB::table('erp_production_routing_operations')->where('id', $f['node'])->value('operation_id'),
            'operation_name_snapshot' => '历史包装工序', 'sequence' => 10, 'chain_key' => $this->code('CHAIN'), 'packing_content_ids' => [],
            'packaging_materials_snapshot' => [['component_item_id' => $f['component']->id, 'base_qty_per_output_unit' => '1'],
                ['component_item_id' => $office->id, 'base_qty_per_output_unit' => '1']],
            'planned_base_qty' => 2, 'status' => 'IN_PROGRESS', 'owner_legacy_id' => $f['user']->legacy_id, 'business_version' => 1]);
        return $f + compact('factoryCategory', 'officeCategory', 'office', 'factoryStock', 'officeStock', 'packing');
    }

    private function publishedFixture(): array
    {
        $f = $this->fixture();
        $waiting = app(WorkOrderApplicationService::class)->submit($f['wo']->id, $this->command($f['wo']), $f['user'], self::PERMISSIONS, true);
        $f['wo'] = app(WorkOrderApplicationService::class)->publish($waiting->id, $this->command($waiting), $f['user'], self::PERMISSIONS, true);
        $f['target'] = $f['wo']->quantityOperations()->first();
        $f['task'] = $f['wo']->productionTasks()->first();
        $f['task']->update(['assignee_user_legacy_id' => $f['user']->legacy_id]);
        $f['requirement'] = $f['wo']->materialRequirements()->first();
        return $f;
    }

    private function pendingPick(array $f, string $mode): array
    {
        $stock = $this->stock($f['component'], '2');
        $pick = MaterialPickingTask::create(['task_no' => $this->code('PICK'), 'work_order_id' => $f['wo']->id,
            'warehouse_id' => $stock->warehouse_id, 'production_location_name_snapshot' => '实际装配工位', 'status' => 'PICKED', 'business_version' => 1]);
        $line = MaterialPickingTaskLine::create(['task_id' => $pick->id, 'material_requirement_id' => $f['requirement']->id,
            'component_item_id' => $f['component']->id, 'required_qty_snapshot' => 2, 'planned_pick_qty' => 2, 'actual_pick_qty' => 2,
            'delivered_qty' => $mode === 'onsite_cutting' ? 0 : 2, 'received_qty' => 0, 'unit_id' => $f['unit']->id,
            'production_target_type' => 'quantity_operation', 'production_target_id' => $f['target']->id,
            'fulfillment_mode_snapshot' => $mode, 'serial_control_type' => 'none', 'inventory_balance_id' => $stock->id,
            'warehouse_id' => $stock->warehouse_id, 'location_id' => $stock->location_id, 'batch_no' => $stock->batch_no,
            'status' => 'PICKED', 'business_version' => 1]);
        return [$pick, $line];
    }

    private function fixture(): array
    {
        $actor = $this->actor();
        $unit = Unit::create(['unit_code' => $this->code('U'), 'unit_name' => '件', 'unit_type' => 'quantity',
            'decimal_places' => 4, 'is_base' => true, 'status' => 'enabled']);
        $output = $this->item($unit, 'factory');
        $component = $this->item($unit, 'factory', ['item_type' => 'raw_material', 'is_production_item' => false]);
        [$routing, $node] = $this->routing($output, [$component]);
        $bom = Bom::create(['bom_no' => $this->code('B'), 'bom_name' => '实际工厂BOM', 'output_item_id' => $output->id,
            'bom_type' => 'standard', 'version' => 'V1.0', 'is_default' => true, 'status' => 'active', 'audit_status' => 'approved']);
        BomItem::create(['bom_id' => $bom->id, 'line_no' => 10, 'component_item_id' => $component->id,
            'component_item_code' => $component->item_code, 'component_item_name' => $component->item_name,
            'qty' => 1, 'unit_id' => $unit->id, 'loss_rate' => 0, 'fixed_qty' => 0]);
        $wo = app(WorkOrderApplicationService::class)->createDraft($this->draft($output, $routing, $node), $actor['user'], self::PERMISSIONS, true);
        return $actor + compact('unit', 'output', 'component', 'routing', 'node', 'bom', 'wo');
    }

    private function routing(Item $output, array $components): array
    {
        $operation = DB::table('erp_production_operations')->insertGetId(['operation_no' => $this->code('OP'),
            'operation_name' => '实际装配工序', 'status' => 'enabled', 'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $routing = ProductionRouting::create(['routing_no' => $this->code('RT'), 'routing_name' => '实际工厂路线', 'output_item_id' => $output->id,
            'version' => 1, 'status' => 'active', 'is_default' => true, 'default_scope_key' => (string) $output->id, 'business_version' => 1]);
        $node = DB::table('erp_production_routing_operations')->insertGetId(['routing_id' => $routing->id, 'operation_id' => $operation,
            'sequence' => 10, 'output_item_id' => $output->id, 'output_mode' => 'flow_only', 'quality_mode' => 'none',
            'allow_continue_without_warehouse' => true, 'is_key_operation' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($components as $component) DB::table('erp_routing_operation_material_supply_rules')->insert(['routing_operation_id' => $node,
            'component_item_id' => $component->id, 'target_routing_operation_id' => $node, 'required_qty_ratio' => 1,
            'supply_mode' => 'dedicated_delivery', 'requires_delivery' => true, 'participates_in_kitting' => true,
            'allow_partial_delivery' => false, 'delivery_location_type' => 'operation_station', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return [$routing, $node];
    }

    private function item(Unit $unit, string $scope, array $extra = []): Item
    {
        return Item::create(array_replace(['item_code' => $this->code('I'), 'item_name' => $scope === 'office' ? '办公用品' : '工厂物料',
            'item_type' => 'finished_product', 'management_scope' => $scope, 'unit_id' => $unit->id, 'is_purchase_item' => true,
            'is_stock_item' => true, 'is_production_item' => true, 'production_execution_mode' => 'quantity',
            'manufacturing_strategy' => 'unspecified', 'status' => 'enabled'], $extra));
    }

    private function stock(Item $item, string $qty, ?int $warehouse = null): InventoryBalance
    {
        $warehouse ??= DB::table('erp_warehouses')->insertGetId(['warehouse_code' => $this->code('WH'), 'warehouse_name' => '测试仓', 'management_scope' => $item->management_scope, 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        $location = DB::table('erp_locations')->insertGetId(['warehouse_id' => $warehouse, 'location_code' => $this->code('LOC'), 'location_name' => '实际库位', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        return InventoryBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse, 'location_id' => $location,
            'batch_no' => $this->code('BATCH'), 'unit_id' => $item->unit_id, 'quantity_on_hand' => $qty, 'quantity_available' => $qty,
            'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0, 'average_unit_cost' => 5,
            'inventory_value' => bcmul($qty, '5', 4)]);
    }

    private function draft(Item $item, ProductionRouting $routing, int $node): array
    {
        return ['client_command_id' => $this->code('CMD'), 'source_type' => 'stock_prebuild', 'output_item_id' => $item->id,
            'production_routing_id' => $routing->id, 'target_routing_operation_id' => $node, 'stocking_purpose' => 'common_inventory', 'target_qty' => 2];
    }

    private function assertScopeRejected(callable $action): void
    {
        try { $action(); $this->fail('Office scope must be rejected.'); }
        catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); $this->assertSame(422, $e->status); }
    }

    private function command(WorkOrder $wo): array { return ['client_command_id' => $this->code('CMD'), 'expected_version' => (int) $wo->fresh()->business_version]; }
    private function code(string $prefix): string { return $prefix.'-'.Str::lower(Str::random(10)); }

    private function actor(): array
    {
        app(RbacBootstrapService::class)->bootstrap();
        foreach (['sku_item_relation.set', 'sku_item_relation.change'] as $permission) {
            if (! DB::table('erp_rbac_permissions')->where('code', $permission)->exists()) {
                DB::table('erp_rbac_permissions')->insert(['code' => $permission, 'name' => '旧默认 Item 按钮验证',
                    'type' => 'button', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $id = random_int(6300000, 6399999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $id, 'username' => $this->code('USER'), 'nickname' => '范围验证人员',
            'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $role = DB::table('erp_rbac_roles')->insertGetId(['code' => $this->code('ROLE'), 'name' => '范围验证角色', 'data_scope' => 'all', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('erp_rbac_permissions')->whereIn('code', self::PERMISSIONS)->pluck('id') as $permission) DB::table('erp_rbac_role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $id, 'role_id' => $role]);
        $token = $this->code('TOKEN');
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        return ['user' => DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->first(), 'token' => $token];
    }
}
