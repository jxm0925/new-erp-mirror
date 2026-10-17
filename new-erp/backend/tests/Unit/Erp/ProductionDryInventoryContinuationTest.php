<?php

namespace Tests\Unit\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{InventoryBalance, InventoryLocationBalance, Item, ProductionOutputRecord, WorkOrder, SalesShipmentLine};
use App\Services\Erp\{DocumentNumberService, InventoryAvailabilityService, ProductionInventoryCheckpointPolicy,
    ProductionInventoryContinuationService, ProductionOutputTraceService, ProductionShipmentSourceResolver, ProductionStockPrebuildService, ShipmentDirectSourcePolicy,
    AssemblyProductionApplicationService, AssemblyProductionInventoryService, AuthContextService, BomMatcher, ProductionMasterDataService,
    ProductionDataScopeResolver, ProductionPickingStockService, ProductionUnstartedTreeGuard, ReleaseGateApplicationService, WorkOrderApplicationService};
use App\Models\Erp\{AssemblyProductionPlan, AssemblyComponentDemand, Bom, BomItem, Unit, ProductionRouting, ProductionRoutingOperation};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Facade};
use PHPUnit\Framework\TestCase;

/** Isolated SQLite memory only: no Laravel application, .env, or development/test database. */
class ProductionDryInventoryContinuationTest extends TestCase
{
    private Capsule $database;
    private ?Container $previousContainer;
    private mixed $previousResolver;
    private mixed $previousFacadeApplication;
    private ProductionInventoryContinuationService $continuations;
    private DocumentNumberService $numbers;
    private WorkOrder $wo;
    private ProductionOutputRecord $output;
    private InventoryBalance $balance;
    private object $source;
    private object $next;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        Container::setInstance($container);
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $container->instance(ShipmentDirectSourcePolicy::class, new ShipmentDirectSourcePolicy);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $this->schema();
        $this->numbers = $this->createMock(DocumentNumberService::class);
        $this->numbers->method('next')->willReturn('SN-DRY-1');
        $this->continuations = new ProductionInventoryContinuationService(new InventoryAvailabilityService, $this->numbers);
        $this->wo = WorkOrder::create(['work_order_no' => 'WO-SALE-1', 'source_type' => 'sales_order', 'output_item_id' => 1,
            'routing_snapshot' => ['operations' => [
                ['routing_operation_id' => 40, 'operation_id' => 4, 'sequence' => 40, 'execution_context' => 'production', 'stage_code' => 'ASSEMBLY', 'operation_name' => '总装'],
                ['routing_operation_id' => 50, 'operation_id' => 5, 'sequence' => 50, 'execution_context' => 'production', 'stage_code' => 'WATER_TEST', 'operation_name' => '试水'],
                ['routing_operation_id' => 60, 'operation_id' => 6, 'sequence' => 60, 'execution_context' => 'shipment', 'stage_code' => 'PACKING', 'operation_name' => '打包'],
            ]], 'status' => 'IN_PROGRESS', 'production_demand_id' => 10]);
        $this->balance = InventoryBalance::create(['item_id' => 1, 'warehouse_id' => 1, 'location_id' => 1, 'batch_no' => 'DRY-1',
            'quantity_on_hand' => 1, 'quantity_available' => 1, 'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0]);
        InventoryLocationBalance::create(['item_id' => 1, 'warehouse_id' => 1, 'location_id' => 1,
            'quantity_on_hand' => 1, 'quantity_available' => 1, 'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0]);
        DB::table('erp_production_units')->insert(['id' => 1, 'work_order_id' => $this->wo->id]);
        DB::table('erp_production_unit_operations')->insert(['id' => 4, 'work_order_id' => $this->wo->id, 'production_unit_id' => 1,
            'routing_operation_id_snapshot' => 40, 'operation_id_snapshot' => 4, 'sequence_no_snapshot' => 40, 'status' => 'COMPLETED']);
        $this->output = ProductionOutputRecord::create(['work_order_id' => $this->wo->id, 'output_no' => 'POU-DRY-1',
            'production_unit_id' => 1, 'source_target_type' => 'unit_operation', 'source_target_id' => 4,
            'output_item_id' => 1, 'output_base_qty' => 1, 'output_mode_snapshot' => 'warehouse_optional',
            'quality_mode_snapshot' => 'none', 'status' => 'WAREHOUSED', 'business_version' => 1]);
        $this->source = (object) ['id' => 4, 'stage_code_snapshot' => 'ASSEMBLY'];
        $this->next = (object) ['id' => 5, 'stage_code_snapshot' => 'WATER_TEST', 'status' => 'WAIT_PREDECESSOR',
            'routing_operation_id_snapshot' => 50, 'sequence_no_snapshot' => 50, 'operation_name_snapshot' => '试水', 'started_at' => null];
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        $this->previousResolver ? Model::setConnectionResolver($this->previousResolver) : Model::unsetConnectionResolver();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_dry_hold_reserves_exact_original_stock_and_receipt_releases_only_that_hold(): void
    {
        $serialId = $this->serial();
        $id = DB::transaction(fn () => $this->hold());
        $this->assertSame(1.0, (float) $this->balance->fresh()->quantity_locked);
        $this->assertSame(0.0, (float) $this->balance->fresh()->quantity_available);
        $this->assertSame('continuation_reserved', DB::table('erp_inventory_serials')->where('id', $serialId)->value('serial_status'));
        $this->assertSame($id, DB::transaction(fn () => $this->hold()));
        $this->assertSame(1, DB::table('erp_work_order_inventory_continuations')->count());
        $this->assertSame(1, DB::table('erp_production_execution_events')->count());
        $row = DB::table('erp_work_order_inventory_continuations')->first();
        $this->assertSame((int) $this->wo->id, (int) $row->work_order_id);
        $this->assertSame(1, (int) $row->production_unit_id);
        $this->assertSame(50, (int) $row->start_routing_operation_id);
        $this->assertSame('WAIT_PREDECESSOR', $this->next->status);
        DB::transaction(fn () => $this->continuations->releaseForIssue($this->issue(), [$this->line($id, $serialId)], (object) ['id' => 7]));
        $this->assertSame(0.0, (float) $this->balance->fresh()->quantity_locked);
        $this->assertSame(0.0, (float) InventoryLocationBalance::first()->quantity_locked);
        $this->assertSame('RECEIVED', DB::table('erp_work_order_inventory_continuations')->value('status'));
        $this->assertSame('production_consumed', DB::table('erp_inventory_serials')->where('id', $serialId)->value('serial_status'));
        $this->assertSame(['production_dry_inventory_reserved', 'production_continuation_received'], DB::table('erp_inventory_serial_events')->orderBy('id')->pluck('event_type')->all());
        $this->assertSame(1, DB::table('erp_production_units')->count());
        $this->assertSame(1, DB::table('erp_production_unit_operations')->count());
    }

    public function test_mismatched_internal_issue_cannot_unlock_another_output(): void
    {
        $serialId = $this->serial(); $id = DB::transaction(fn () => $this->hold());
        $line = $this->line($id, $serialId); $line->output_record_id = 999;
        try {
            DB::transaction(fn () => $this->continuations->releaseForIssue($this->issue(), [$line], (object) ['id' => 7]));
            $this->fail('A foreign output must never release the original hold.');
        } catch (WorkOrderDomainException $e) { $this->assertSame('continuation_reservation_invalid', $e->errorCode); }
        $this->assertSame(1.0, (float) $this->balance->fresh()->quantity_locked);
        $this->assertSame('RESERVED', DB::table('erp_work_order_inventory_continuations')->value('status'));
        $this->assertSame('continuation_reserved', DB::table('erp_inventory_serials')->where('id', $serialId)->value('serial_status'));
    }

    public function test_started_water_test_cannot_be_reclassified_as_unstarted_dry_stock(): void
    {
        $this->next->status = 'PAUSED'; $this->next->started_at = '2026-10-14 10:00:00';
        try { DB::transaction(fn () => $this->hold()); $this->fail('Started water test must reject dry holding.'); }
        catch (WorkOrderDomainException $e) { $this->assertSame('dry_inventory_successor_started', $e->errorCode); }
        $this->assertSame(0, DB::table('erp_work_order_inventory_continuations')->count());
        $this->assertSame('0', (string) $this->balance->fresh()->quantity_locked);
    }

    public function test_stock_prebuild_still_ends_at_its_target_and_is_not_owned_by_its_original_flow(): void
    {
        $this->wo->source_type = 'stock_prebuild';
        $this->assertNull(DB::transaction(fn () => $this->hold()));
        $this->assertSame(0, DB::table('erp_work_order_inventory_continuations')->count());
        $this->assertSame('1', (string) $this->balance->fresh()->quantity_available);
        $policy = new ProductionInventoryCheckpointPolicy;
        $this->assertFalse($policy->isDryAssembly(1, 2, 'warehouse_optional', 'ASSEMBLY', 'WATER_TEST'));
        $this->assertFalse($policy->isDryAssembly(1, 1, 'flow_only', 'ASSEMBLY', 'WATER_TEST'));
        $this->assertFalse($policy->isDryAssembly(1, 1, 'warehouse_optional', 'WELDING', 'WATER_TEST'));
    }

    public function test_first_dry_receipt_binds_one_sn_to_pu_and_reuses_it_after_received_continuation(): void
    {
        Item::create(['id' => 1, 'item_code' => 'DEVICE-1', 'serial_tracking_mode' => 'required']);
        $stock = new ProductionStockPrebuildService($this->numbers, new InventoryAvailabilityService);
        $posting = ['warehouse_id' => 1, 'location_id' => 1, 'batch_no' => 'DRY-1'];
        $serialId = DB::transaction(fn () => $stock->registerOutputSerial($this->wo, $this->output, $posting, (object) ['id' => 7]));
        $productionSerialId = DB::table('erp_production_units')->where('id', 1)->value('device_serial_id');
        $this->assertNotNull($productionSerialId);
        $this->assertSame((int) $productionSerialId, (int) $this->output->fresh()->serial_id);
        $id = DB::transaction(fn () => $this->hold());
        DB::transaction(fn () => $this->continuations->releaseForIssue($this->issue(), [$this->line($id, $serialId)], (object) ['id' => 7]));
        $child = ProductionOutputRecord::create(['work_order_id' => $this->wo->id, 'production_unit_id' => 1, 'source_target_type' => 'unit_operation',
            'source_target_id' => 5, 'output_no' => 'POU-WATER-1', 'output_item_id' => 1, 'output_base_qty' => 1,
            'serial_id' => $productionSerialId, 'serial_no_snapshot' => 'SN-DRY-1', 'status' => 'WAREHOUSED']);
        DB::table('erp_production_output_lineage_links')->insert(['parent_output_record_id' => $this->output->id,
            'child_output_record_id' => $child->id, 'parent_base_qty' => 1, 'child_base_qty' => 1]);
        $reused = DB::transaction(fn () => $stock->registerOutputSerial($this->wo, $child, $posting, (object) ['id' => 7]));
        $this->assertSame($serialId, $reused);
        $this->assertSame(1, DB::table('erp_inventory_serials')->count());
        $this->assertSame(1, DB::table('erp_production_serials')->count());
        $this->assertSame((int) $child->id, (int) DB::table('erp_inventory_serials')->value('source_document_id'));
        $this->assertSame('available', DB::table('erp_inventory_serials')->value('serial_status'));
        $this->assertSame((int) $productionSerialId, (int) DB::table('erp_production_units')->where('id', 1)->value('device_serial_id'));
    }

    public function test_other_ordinary_intermediate_receipts_retain_the_existing_sn_generation_policy(): void
    {
        Item::create(['id' => 1, 'item_code' => 'DEVICE-1', 'serial_tracking_mode' => 'required']);
        $route = $this->wo->routing_snapshot; $route['operations'][0]['stage_code'] = 'WELDING'; $this->wo->routing_snapshot = $route;
        $stock = new ProductionStockPrebuildService($this->numbers, new InventoryAvailabilityService);
        DB::transaction(fn () => $stock->registerOutputSerial($this->wo, $this->output,
            ['warehouse_id' => 1, 'location_id' => 1, 'batch_no' => 'DRY-1'], (object) ['id' => 7]));
        $this->assertSame(1, DB::table('erp_inventory_serials')->count());
        $this->assertSame(0, DB::table('erp_production_serials')->count());
        $this->assertNull(DB::table('erp_production_units')->where('id', 1)->value('device_serial_id'));
    }

    public function test_shipment_blocks_dry_sales_stock_and_offers_original_work_order_issue(): void
    {
        $serialId = $this->serial(); $id = DB::transaction(fn () => $this->hold());
        DB::table('erp_production_internal_issue_tasks')->insert(['id' => 9, 'status' => 'WAIT_ISSUE']);
        $context = (new ProductionShipmentSourceResolver)->contextForShipmentLine($this->shipmentLine($this->output->id, $serialId));
        $this->assertFalse($context['production_ready']);
        $this->assertStringContainsString('尚未完成试水', implode(' ', $context['blockers']));
        $this->assertSame('same_work_order', $context['continuations'][0]['continuation_mode']);
        $this->assertSame((int) $this->wo->id, $context['continuations'][0]['target_work_order_id']);
        $this->assertSame(9, $context['continuations'][0]['internal_issue_task_id']);
        $this->assertSame('WATER_TEST', $context['continuations'][0]['start_stage_code']);
    }

    public function test_completed_prebuild_still_blocks_unperformed_water_test_without_fabricating_next_task(): void
    {
        $this->wo->update(['source_type' => 'stock_prebuild', 'status' => 'COMPLETED']);
        $context = (new ProductionShipmentSourceResolver)->contextForShipmentLine($this->shipmentLine($this->output->id, null));
        $this->assertFalse($context['production_ready']);
        $this->assertSame('new_work_order', $context['continuations'][0]['continuation_mode']);
        $this->assertNull($context['continuations'][0]['target_work_order_id']);
        $this->assertSame((int) $this->output->id, $context['continuations'][0]['source_output_record_id']);
        $this->assertSame(0, DB::table('erp_work_order_inventory_continuations')->count());
    }

    public function test_continued_water_test_trace_includes_actual_prior_operations_once(): void
    {
        $this->wo->update(['source_type' => 'stock_prebuild', 'status' => 'COMPLETED']);
        $receiving = WorkOrder::create(['work_order_no' => 'WO-SALE-2', 'source_type' => 'sales_order', 'output_item_id' => 1,
            'routing_snapshot' => $this->wo->routing_snapshot, 'status' => 'COMPLETED']);
        $earlier = [];
        foreach ([1, 2, 3] as $targetId) {
            DB::table('erp_production_unit_operations')->insert(['id' => $targetId, 'work_order_id' => $this->wo->id, 'production_unit_id' => 1,
                'routing_operation_id_snapshot' => $targetId * 10, 'operation_id_snapshot' => $targetId, 'sequence_no_snapshot' => $targetId * 10, 'status' => 'COMPLETED']);
            $earlier[] = ProductionOutputRecord::create(['work_order_id' => $this->wo->id, 'source_target_type' => 'unit_operation',
                'source_target_id' => $targetId, 'output_item_id' => 1, 'output_base_qty' => 1, 'quality_mode_snapshot' => 'none', 'status' => 'HANDED_OVER']);
        }
        $earlier[] = $this->output;
        foreach ([0, 1, 2] as $index) DB::table('erp_production_output_lineage_links')->insert([
            'parent_output_record_id' => $earlier[$index]->id, 'child_output_record_id' => $earlier[$index + 1]->id, 'parent_base_qty' => 1, 'child_base_qty' => 1]);
        $child = ProductionOutputRecord::create(['work_order_id' => $receiving->id, 'source_target_type' => 'unit_operation',
            'source_target_id' => 5, 'output_item_id' => 1, 'output_base_qty' => 1, 'quality_mode_snapshot' => 'none', 'status' => 'WAREHOUSED']);
        DB::table('erp_production_unit_operations')->insert(['id' => 5, 'work_order_id' => $receiving->id, 'production_unit_id' => 2,
            'routing_operation_id_snapshot' => 50, 'operation_id_snapshot' => 5, 'sequence_no_snapshot' => 50, 'status' => 'COMPLETED']);
        DB::table('erp_production_output_lineage_links')->insert(['parent_output_record_id' => $this->output->id,
            'child_output_record_id' => $child->id, 'parent_base_qty' => 1, 'child_base_qty' => 1]);
        $trace = (new ProductionOutputTraceService)->contributions($child->id, '1');
        $this->assertSame([5, 4, 3, 2, 1], array_column($trace, 'target_id'));
        $this->assertSame(array_fill(0, 5, 'complete'), array_column($trace, 'trace_status'));
        $this->assertSame([(int) $receiving->id, (int) $this->wo->id, (int) $this->wo->id, (int) $this->wo->id, (int) $this->wo->id], array_column($trace, 'work_order_id'));
        $this->assertSame(1, DB::table('erp_production_unit_operations')->where('work_order_id', $receiving->id)->count());
        $context = (new ProductionShipmentSourceResolver)->contextForShipmentLine($this->shipmentLine($child->id, null));
        $this->assertTrue($context['production_ready']);
        $this->assertSame([], $context['continuations']);
    }

    public function test_water_test_output_requires_a_passed_quality_fact_before_shipment(): void
    {
        DB::table('erp_production_unit_operations')->insert(['id' => 5, 'work_order_id' => $this->wo->id, 'production_unit_id' => 1,
            'routing_operation_id_snapshot' => 50, 'operation_id_snapshot' => 5, 'sequence_no_snapshot' => 50, 'status' => 'COMPLETED']);
        $output = ProductionOutputRecord::create(['work_order_id' => $this->wo->id, 'source_target_type' => 'unit_operation',
            'source_target_id' => 5, 'output_item_id' => 1, 'output_base_qty' => 1, 'quality_mode_snapshot' => 'required', 'status' => 'WAREHOUSED']);
        $resolver = new ProductionShipmentSourceResolver;
        $this->assertFalse($resolver->contextForShipmentLine($this->shipmentLine($output->id, null))['production_ready']);
        DB::table('erp_production_quality_inspections')->insert(['output_record_id' => $output->id, 'status' => 'COMPLETED', 'result' => 'failed']);
        $this->assertFalse($resolver->contextForShipmentLine($this->shipmentLine($output->id, null))['production_ready']);
        DB::table('erp_production_quality_inspections')->insert(['output_record_id' => $output->id, 'status' => 'COMPLETED', 'result' => 'passed']);
        $this->assertTrue($resolver->contextForShipmentLine($this->shipmentLine($output->id, null))['production_ready']);
    }

    public function test_replacing_unstarted_assembly_releases_component_hold_and_cancels_children_without_cancelling_root(): void
    {
        [$root, $child, $record] = $this->preparedAssembly();
        $stock = InventoryBalance::create(['item_id' => 2, 'warehouse_id' => 1, 'location_id' => 1, 'quantity_on_hand' => 5,
            'quantity_available' => 3, 'quantity_locked' => 2, 'quantity_defective' => 0, 'quantity_pending' => 0]);
        InventoryLocationBalance::create(['item_id' => 2, 'warehouse_id' => 1, 'location_id' => 1, 'quantity_on_hand' => 5,
            'quantity_available' => 3, 'quantity_locked' => 2, 'quantity_defective' => 0, 'quantity_pending' => 0]);
        $demand = AssemblyComponentDemand::create(['assembly_plan_id' => $record->id, 'parent_work_order_id' => $root->id,
            'root_work_order_id' => $root->id, 'child_work_order_id' => $child->id, 'status' => 'PREPARED']);
        DB::table('erp_assembly_inventory_reservations')->insert(['component_demand_id' => $demand->id, 'work_order_id' => $root->id,
            'inventory_balance_id' => $stock->id, 'reserved_base_qty' => 2, 'consumed_base_qty' => 0, 'status' => 'ACTIVE']);
        DB::table('erp_work_order_material_preparations')->insert(['work_order_id' => $root->id, 'status' => 'ACTIVE']);
        DB::table('erp_work_order_preparation_materials')->insert(['work_order_id' => $root->id, 'status' => 'ACTIVE']);
        DB::transaction(fn () => $this->assembly()->replaceForInventoryContinuationLocked($root, (object) ['id' => 7]));
        $this->assertSame('WAIT_RELEASE', $root->fresh()->status);
        $this->assertSame('CANCELLED', $child->fresh()->status);
        $this->assertSame('CANCELLED', $record->fresh()->status);
        $this->assertSame('CANCELLED', $demand->fresh()->status);
        $this->assertSame('RELEASED', DB::table('erp_assembly_inventory_reservations')->value('status'));
        $this->assertSame(0.0, (float) $stock->fresh()->quantity_locked);
        $this->assertSame('SUPERSEDED', DB::table('erp_work_order_preparation_materials')->value('status'));
        $this->assertSame(['prior' => 'prepared components'], $record->fresh()->plan_snapshot);
        $this->assertSame('replace_assembly_for_continuation', DB::table('erp_production_execution_events')->value('action'));
    }

    public function test_replacement_blocks_execution_receipt_procurement_and_published_descendant_facts_before_changes(): void
    {
        [$root, $child, $record] = $this->preparedAssembly();
        $assembly = $this->assembly();
        $cases = [
            ['erp_production_tasks', ['work_order_id' => $child->id, 'status' => 'CLAIMED', 'claimed_at' => '2026-10-14 10:00:00'], 'continuation_tree_has_facts'],
            ['erp_material_receipts', ['work_order_id' => $child->id], 'continuation_tree_has_facts'],
            ['erp_material_procurement_sources', ['work_order_id' => $child->id], 'continuation_tree_has_procurement'],
        ];
        foreach ($cases as [$table, $fact, $code]) {
            DB::table($table)->insert($fact);
            try { DB::transaction(fn () => $assembly->replaceForInventoryContinuationLocked($root, (object) ['id' => 7])); $this->fail('Fact must block replacement.'); }
            catch (WorkOrderDomainException $e) { $this->assertSame($code, $e->errorCode); }
            $this->assertSame('DRAFT', $child->fresh()->status); $this->assertSame('PREPARED', $record->fresh()->status);
            DB::table($table)->delete(); // Only this isolated in-memory fixture is reset.
        }
        $grandchild = WorkOrder::create(['work_order_no' => 'CHILD-DEEP', 'status' => 'WAIT_RELEASE',
            'assembly_root_work_order_id' => $root->id, 'assembly_parent_work_order_id' => $child->id, 'released_at' => '2026-10-14 10:00:00']);
        try { DB::transaction(fn () => $assembly->replaceForInventoryContinuationLocked($root, (object) ['id' => 7])); $this->fail('Released descendant must block replacement.'); }
        catch (WorkOrderDomainException $e) { $this->assertSame('continuation_tree_already_released', $e->errorCode); }
        $this->assertSame('WAIT_RELEASE', $grandchild->fresh()->status);
        $this->assertSame('PREPARED', $record->fresh()->status);
    }

    public function test_full_assembly_stock_continuation_needs_no_new_upstream_components_and_hash_includes_source(): void
    {
        [$root, $bom, $service] = $this->assemblyNetFixture('10');
        $plan = (new \ReflectionMethod($service, 'build'))->invoke($service, $root, false);
        $this->assertFalse($plan['required']);
        $this->assertSame('not_required', $plan['status']);
        $this->assertSame([], $plan['components']);
        $old = $root->inventory_continuation_plan;
        $root->inventory_continuation_plan = [];
        $without = (new \ReflectionMethod($service, 'inputHash'))->invoke($service, $root, $bom);
        $root->inventory_continuation_plan = null;
        $this->assertSame($without, (new \ReflectionMethod($service, 'inputHash'))->invoke($service, $root, $bom));
        $root->inventory_continuation_plan = $old;
        $this->assertNotSame($without, $plan['input_hash']);
        $old[0]['source_output_record_id'] = 999; $root->inventory_continuation_plan = $old;
        $this->assertNotSame($plan['input_hash'], (new \ReflectionMethod($service, 'inputHash'))->invoke($service, $root, $bom));
    }

    public function test_partial_stock_continuation_plans_only_remaining_root_component_quantity(): void
    {
        [$root, $bom, $service] = $this->assemblyNetFixture('6');
        $plan = (new \ReflectionMethod($service, 'build'))->invoke($service, $root, false);
        $this->assertTrue($plan['required']);
        $this->assertSame([], $plan['issues']);
        $made = collect($plan['components'])->firstWhere('manufacturing_strategy', 'make');
        $this->assertSame('8.00000000', $made['required_base_qty']); // 4 new devices * 2 components.
        $this->assertSame('8.00000000', $made['production_base_qty']);
        $this->assertSame(0, WorkOrder::where('assembly_root_work_order_id', $root->id)->count());
    }

    public function test_repeated_partial_replacement_preserves_cancelled_demands_and_uses_new_unique_generation_paths(): void
    {
        [$root, $bom, $service] = $this->assemblyNetFixture('6');
        $orders = $this->createMock(WorkOrderApplicationService::class);
        $orders->method('createAssemblyChildLocked')->willReturnCallback(fn ($root, $parent, $demand, $row) => WorkOrder::create([
            'work_order_no' => 'CHILD-'.$demand->id, 'source_type' => 'stock_prebuild', 'status' => 'DRAFT', 'business_version' => 1,
            'assembly_root_work_order_id' => $root->id, 'assembly_parent_work_order_id' => $parent->id,
            'assembly_component_demand_id' => $demand->id, 'output_item_id' => $row['item_id'], 'target_base_qty' => $row['production_base_qty'],
        ]));
        Container::getInstance()->instance(WorkOrderApplicationService::class, $orders);
        $prepare = new \ReflectionMethod($service, 'prepareLocked');
        $first = DB::transaction(fn () => $prepare->invoke($service, $root, (object) ['id' => 7], 'prepare-1', 'hash-1'));
        $this->assertSame(1, $first['plan_version']);
        $firstDemand = AssemblyComponentDemand::where('root_work_order_id', $root->id)->first();
        $firstChild = $firstDemand->child_work_order_id;
        DB::transaction(fn () => $service->replaceForInventoryContinuationLocked($root, (object) ['id' => 7]));
        $plan = $root->inventory_continuation_plan; $plan[0]['base_qty'] = '5'; $root->inventory_continuation_plan = $plan; $root->save();
        $second = DB::transaction(fn () => $prepare->invoke($service, $root, (object) ['id' => 7], 'prepare-2', 'hash-2'));
        $demands = AssemblyComponentDemand::where('root_work_order_id', $root->id)->orderBy('id')->get();
        $this->assertSame(2, $second['plan_version']);
        $this->assertCount(2, $demands); $this->assertNotSame($demands[0]->path_key, $demands[1]->path_key);
        $this->assertSame('CANCELLED', $demands[0]->status); $this->assertSame('PREPARED', $demands[1]->status);
        $this->assertSame('CANCELLED', WorkOrder::find($firstChild)->status);
        $this->assertSame('10.00000000', $demands[1]->required_base_qty);
        $this->assertNull(AssemblyProductionPlan::where('root_work_order_id', $root->id)->first()->cancelled_at);
        DB::transaction(fn () => $service->replaceForInventoryContinuationLocked($root, (object) ['id' => 7]));
        $root->inventory_continuation_plan = []; $root->save();
        $third = DB::transaction(fn () => $prepare->invoke($service, $root, (object) ['id' => 7], 'prepare-3', 'hash-3'));
        $this->assertSame(3, $third['plan_version']);
        $this->assertSame('20.00000000', AssemblyComponentDemand::where('root_work_order_id', $root->id)->orderByDesc('id')->first()->required_base_qty);
        $this->assertSame(3, AssemblyComponentDemand::where('root_work_order_id', $root->id)->count());
    }

    public function test_full_continuation_preparation_passes_gate_only_for_its_exact_frozen_source_plan(): void
    {
        [$root, $bom, $service] = $this->assemblyNetFixture('10');
        DB::transaction(fn () => (new \ReflectionMethod($service, 'prepareLocked'))->invoke($service, $root, (object) ['id' => 7], 'prepare-full', 'hash-full'));
        $bom = $bom->fresh(); // Gate consumes a persisted BOM, as the real publish query does.
        $gate = DB::transaction(fn () => $service->releaseCheck($root, $bom));
        $this->assertTrue($gate['valid']);
        $this->assertSame('NOT_REQUIRED', AssemblyProductionPlan::where('root_work_order_id', $root->id)->value('status'));
        $this->assertSame(0, AssemblyComponentDemand::where('root_work_order_id', $root->id)->count());
        $plan = $root->inventory_continuation_plan; $plan[0]['source_output_record_id'] = 999; $root->inventory_continuation_plan = $plan;
        $changed = DB::transaction(fn () => $service->releaseCheck($root, $bom));
        $this->assertFalse($changed['valid']); $this->assertSame('assembly_plan_changed', $changed['code']);
    }

    public function test_configure_replaces_plan_after_validating_formal_stock_and_replays_without_duplicate_cancellation_or_lock(): void
    {
        [$root, $bom, $assembly] = $this->assemblyNetFixture('1');
        $root->update(['target_base_qty' => 1, 'inventory_continuation_plan' => []]);
        $this->wo->update(['source_type' => 'stock_prebuild', 'status' => 'COMPLETED']);
        $oldPlan = AssemblyProductionPlan::create(['root_work_order_id' => $root->id, 'status' => 'PREPARED', 'plan_version' => 1,
            'work_order_version' => 1, 'plan_snapshot' => ['prior' => 'full-production components']]);
        $child = WorkOrder::create(['work_order_no' => 'OLD-CHILD', 'status' => 'DRAFT', 'business_version' => 1,
            'assembly_root_work_order_id' => $root->id, 'assembly_component_demand_id' => 201]);
        DB::table('erp_work_order_finished_goods_receipts')->insert(['id' => 100, 'output_record_id' => $this->output->id, 'status' => 'POSTED']);
        DB::table('erp_inventory_transactions')->insert(['id' => 100, 'posting_status' => 'posted', 'source_type' => 'work_order_finished_goods_receipt',
            'source_id' => 100, 'transaction_type' => 'finished_goods_receipt']);
        DB::table('erp_production_output_warehouse_postings')->insert(['output_record_id' => $this->output->id, 'warehouse_id' => 1,
            'location_id' => 1, 'batch_no' => 'DRY-1', 'status' => 'POSTED', 'posted_base_qty' => 1, 'inventory_transaction_id' => 100]);
        $orders = $this->createMock(WorkOrderApplicationService::class);
        $orders->method('showWorkOrder')->willReturn($root);
        Container::getInstance()->instance(WorkOrderApplicationService::class, $orders);
        // The asynchronous callback is an adapter boundary in this isolated test.
        // Verify it is scheduled once; actual plan mutators have separate SQLite tests.
        $adapter = new class($assembly) {
            public int $scheduled = 0;
            public function __construct(private AssemblyProductionApplicationService $assembly) {}
            public function replaceForInventoryContinuationLocked(WorkOrder $wo, object $user): void { $this->assembly->replaceForInventoryContinuationLocked($wo, $user); }
            public function scheduleAutomaticLocked(WorkOrder $wo, object $user): void { $this->scheduled++; }
        };
        Container::getInstance()->instance(AssemblyProductionApplicationService::class, $adapter);
        $payload = ['client_command_id' => 'configure-exact-stock', 'expected_version' => 1, 'sources' => [[
            'inventory_balance_id' => $this->balance->id, 'source_output_record_id' => $this->output->id,
            'inventory_serial_id' => null, 'base_qty' => '1',
        ]]];
        $result = $this->continuations->configure($root->id, $payload, (object) ['id' => 7], ['production.work_order.edit'], true);
        $this->assertSame(2, $result['business_version']);
        $this->assertSame(50, $result['inventory_continuation_plan'][0]['start_sequence']);
        $this->assertSame('WAIT_RELEASE', $root->fresh()->status);
        $this->assertSame('CANCELLED', $child->fresh()->status); $this->assertSame('CANCELLED', $oldPlan->fresh()->status);
        $this->assertSame(1, $adapter->scheduled);
        $this->assertSame(0.0, (float) $this->balance->fresh()->quantity_locked);
        $this->assertSame($result, $this->continuations->configure($root->id, $payload, (object) ['id' => 7], ['production.work_order.edit'], true));
        $this->assertSame(1, $adapter->scheduled);
        $this->assertSame(1, DB::table('erp_production_execution_commands')->count());
    }

    private function preparedAssembly(): array
    {
        $root = WorkOrder::create(['work_order_no' => 'ROOT-CONT', 'source_type' => 'sales_order', 'status' => 'WAIT_RELEASE', 'business_version' => 1]);
        $child = WorkOrder::create(['work_order_no' => 'CHILD-CONT', 'source_type' => 'stock_prebuild', 'status' => 'DRAFT', 'business_version' => 1,
            'assembly_root_work_order_id' => $root->id, 'assembly_parent_work_order_id' => $root->id, 'assembly_component_demand_id' => 201]);
        $record = AssemblyProductionPlan::create(['root_work_order_id' => $root->id, 'status' => 'PREPARED', 'plan_version' => 1,
            'work_order_version' => 1, 'plan_snapshot' => ['prior' => 'prepared components']]);
        return [$root, $child, $record];
    }

    private function assembly(?BomMatcher $matcher = null, ?ProductionMasterDataService $routes = null, ?InventoryAvailabilityService $availability = null): AssemblyProductionApplicationService
    {
        return new AssemblyProductionApplicationService($matcher ?? $this->createMock(BomMatcher::class),
            $routes ?? $this->createMock(ProductionMasterDataService::class), $availability ?? new InventoryAvailabilityService,
            new ProductionPickingStockService, new AssemblyProductionInventoryService(new InventoryAvailabilityService),
            new ProductionDataScopeResolver(new AuthContextService));
    }

    private function assemblyNetFixture(string $stockQty): array
    {
        $unit = Unit::create(['unit_name' => '件', 'decimal_places' => 0, 'status' => 'enabled', 'is_legacy' => false]);
        foreach ([1 => 'make', 2 => 'make', 3 => 'purchase'] as $id => $strategy) Item::create(['id' => $id, 'item_code' => 'ITEM-'.$id,
            'item_name' => '物料-'.$id, 'unit_id' => $unit->id, 'manufacturing_strategy' => $strategy, 'status' => 'enabled',
            'is_stock_item' => true, 'is_production_item' => true, 'production_execution_mode' => 'unit', 'material_management_mode' => 'quantity', 'cutting_mode' => 'none']);
        $bom = Bom::create(['output_item_id' => 1, 'version' => 'V1', 'status' => 'active', 'audit_status' => 'approved']);
        $make = BomItem::create(['bom_id' => $bom->id, 'line_no' => 1, 'component_item_id' => 2, 'unit_id' => $unit->id, 'qty' => 2, 'loss_rate' => 0, 'fixed_qty' => 0]);
        $seal = BomItem::create(['bom_id' => $bom->id, 'line_no' => 2, 'component_item_id' => 3, 'unit_id' => $unit->id, 'qty' => 1, 'loss_rate' => 0, 'fixed_qty' => 0]);
        $childBom = Bom::create(['output_item_id' => 2, 'version' => 'V1', 'status' => 'active', 'audit_status' => 'approved']);
        BomItem::create(['bom_id' => $childBom->id, 'line_no' => 1, 'component_item_id' => 3, 'unit_id' => $unit->id, 'qty' => 1, 'loss_rate' => 0, 'fixed_qty' => 0]);
        $nodes = $this->wo->routing_snapshot['operations'];
        $nodes[0]['material_supply_rules'] = [['rule_id' => 1, 'component_item_id' => 2, 'target_routing_operation_id' => 40, 'required_qty_ratio' => 1]];
        $nodes[1]['material_supply_rules'] = [['rule_id' => 2, 'component_item_id' => 3, 'target_routing_operation_id' => 50, 'required_qty_ratio' => 1]];
        $root = WorkOrder::create(['work_order_no' => 'ROOT-NET', 'output_item_id' => 1, 'bom_id' => $bom->id, 'target_base_qty' => 10,
            'source_type' => 'sales_order', 'business_version' => 1, 'base_unit_id' => $unit->id, 'status' => 'WAIT_RELEASE', 'routing_snapshot' => ['operations' => $nodes],
            'inventory_continuation_plan' => [['source_output_record_id' => $this->output->id, 'item_id' => 1,
                'base_qty' => $stockQty, 'start_sequence' => 50, 'start_routing_operation_id' => 50]]]);
        $gate = $this->createMock(ReleaseGateApplicationService::class);
        $gate->method('buildMaterialRows')->willReturn([
            ['line_no' => 1, 'bom_item_id' => $make->id, 'component_item_id' => 2, 'base_required_qty' => 20,
                'per_output_qty' => 2, 'loss_rate' => 0, 'fixed_qty' => 0, 'required_piece_qty' => null],
            ['line_no' => 2, 'bom_item_id' => $seal->id, 'component_item_id' => 3, 'base_required_qty' => 10,
                'per_output_qty' => 1, 'loss_rate' => 0, 'fixed_qty' => 0, 'required_piece_qty' => null],
        ]);
        Container::getInstance()->instance(ReleaseGateApplicationService::class, $gate);
        Container::getInstance()->instance(ProductionInventoryContinuationService::class, $this->continuations);
        $matcher = $this->createMock(BomMatcher::class);
        $matcher->method('match')->willReturnCallback(fn ($product, $sku, $item) => ['bom_id' => $item === 2 ? $childBom->id : $bom->id]);
        $routes = $this->createMock(ProductionMasterDataService::class);
        $route = new ProductionRouting(['id' => 2]);
        $route->setRelation('operations', collect([new ProductionRoutingOperation(['id' => 70, 'sequence' => 10, 'execution_context' => 'production', 'output_item_id' => 2])]));
        $routes->method('defaultRoutingMatches')->willReturn(new \Illuminate\Database\Eloquent\Collection([$route]));
        $availability = $this->createMock(InventoryAvailabilityService::class);
        $availability->method('eligibleBalances')->willReturn(new \Illuminate\Database\Eloquent\Collection);
        return [$root, $bom, $this->assembly($matcher, $routes, $availability)];
    }

    private function hold(): ?int
    {
        return $this->continuations->reserveExistingFlow($this->wo, $this->output, $this->source, $this->next,
            $this->balance, 9, (object) ['id' => 7]);
    }

    private function serial(): int
    {
        $id = DB::table('erp_inventory_serials')->insertGetId(['serial_no' => 'SN-DRY-1', 'inventory_balance_id' => $this->balance->id,
            'serial_status' => 'available', 'source_document_type' => 'production_output', 'source_document_id' => $this->output->id,
            'warehouse_id' => 1, 'location_id' => 1, 'batch_no' => 'DRY-1', 'item_id' => 1]);
        $this->output->update(['inventory_serial_id' => $id]);
        return $id;
    }

    private function issue(): object { return (object) ['id' => 9, 'work_order_id' => $this->wo->id, 'source_type' => 'inventory_continuation', 'issue_no' => 'PII-DRY-1']; }
    private function line(int $id, int $serialId): object { return (object) ['inventory_continuation_id' => $id, 'inventory_balance_id' => $this->balance->id,
        'output_record_id' => $this->output->id, 'serial_id' => $serialId, 'issue_base_qty' => 1]; }

    private function shipmentLine(int $outputId, ?int $serialId): SalesShipmentLine
    {
        return new SalesShipmentLine(['id' => 1, 'sales_order_line_id' => 1, 'item_id' => 1, 'warehouse_id' => 1, 'location_id' => 1,
            'batch_no' => 'DRY-1', 'base_qty' => 1, 'sales_qty' => 1, 'packing_source_snapshot' => [[
                'output_record_id' => $outputId, 'inventory_serial_id' => $serialId, 'base_qty' => '1', 'sales_qty' => '1', 'source_origin' => 'production',
            ]], 'packing_routing_snapshot' => $this->wo->routing_snapshot]);
    }

    private function schema(): void
    {
        $definitions = [
            'erp_work_orders' => ['work_order_no', 'source_type', 'output_item_id', 'routing_snapshot', 'status', 'production_demand_id',
                'assembly_root_work_order_id', 'assembly_parent_work_order_id', 'assembly_component_demand_id', 'released_at', 'business_version',
                'cancelled_at', 'cancelled_by_legacy_id', 'cancel_reason', 'updated_by_legacy_id', 'organization_code', 'bom_id', 'target_base_qty',
                'base_unit_id', 'inventory_continuation_plan', 'technical_version', 'technical_snapshot'],
            'erp_inventory_balances' => ['item_id', 'warehouse_id', 'location_id', 'batch_no', 'quantity_on_hand', 'quantity_available', 'quantity_locked', 'quantity_defective', 'quantity_pending'],
            'erp_inventory_location_balances' => ['item_id', 'warehouse_id', 'location_id', 'quantity_on_hand', 'quantity_available', 'quantity_locked', 'quantity_defective', 'quantity_pending'],
            'erp_work_order_inventory_continuations' => ['work_order_id', 'source_output_record_id', 'inventory_balance_id', 'inventory_serial_id', 'production_unit_id',
                'start_routing_operation_id', 'start_sequence', 'base_qty', 'internal_issue_task_id', 'source_snapshot', 'created_by_legacy_id', 'status', 'business_version', 'received_base_qty'],
            'erp_inventory_serials' => ['serial_no', 'inventory_balance_id', 'serial_status', 'source_document_type', 'source_document_id', 'warehouse_id', 'location_id',
                'batch_no', 'item_id', 'source_document_no', 'posted_at', 'outbound_at', 'origin_type', 'number_source', 'received_at', 'registered_at'],
            'erp_inventory_serial_events' => ['inventory_serial_id', 'event_type', 'document_type', 'document_id', 'document_no', 'from_status', 'to_status', 'warehouse_id',
                'location_id', 'batch_no', 'operator_id', 'event_payload', 'occurred_at'],
            'erp_production_execution_events' => ['aggregate_type', 'aggregate_id', 'action', 'before_status', 'after_status',
                'before_version', 'after_version', 'fact_snapshot', 'operator_legacy_id', 'occurred_at'],
            'erp_production_units' => ['work_order_id', 'device_serial_id', 'device_no_snapshot'],
            'erp_production_unit_operations' => ['work_order_id', 'production_unit_id', 'routing_operation_id_snapshot', 'operation_id_snapshot', 'sequence_no_snapshot', 'status'],
            'erp_production_output_records' => ['work_order_id', 'output_no', 'production_unit_id', 'source_target_type', 'source_target_id', 'output_item_id', 'output_base_qty',
                'output_mode_snapshot', 'quality_mode_snapshot', 'status', 'business_version', 'serial_id', 'inventory_serial_id', 'serial_no_snapshot'],
            'erp_production_output_lineage_links' => ['parent_output_record_id', 'child_output_record_id', 'parent_inventory_serial_id', 'child_inventory_serial_id', 'parent_base_qty', 'child_base_qty'],
            'erp_production_serials' => ['serial_no', 'item_id', 'serial_type', 'generation_stage', 'status', 'inventory_serial_id', 'source_type', 'source_id', 'generated_at'],
            'erp_items' => ['item_code', 'item_name', 'serial_tracking_mode', 'serial_number_prefix', 'unit_id', 'manufacturing_strategy', 'status',
                'is_stock_item', 'is_production_item', 'production_execution_mode', 'material_management_mode', 'cutting_mode'],
            'erp_production_internal_issue_tasks' => ['status', 'target_task_id'],
            'erp_sales_order_production_requirements' => ['sales_order_line_id', 'consumed_qty', 'closed_qty'],
            'erp_production_quality_inspections' => ['output_record_id', 'status', 'result'],
            'erp_production_tasks' => ['work_order_id', 'status', 'claimed_at', 'started_at', 'completed_at', 'actual_labor_minutes'],
            'erp_production_quantity_operations' => ['work_order_id', 'status'],
            'erp_production_target_material_requirements' => ['target_type', 'target_id', 'satisfied_base_qty', 'returned_base_qty', 'consumed_base_qty'],
            'erp_production_reports' => ['work_order_id'],
            'erp_production_labor_sessions' => ['task_id', 'target_type', 'target_id'],
            'erp_production_workstation_stock_confirmations' => ['target_type', 'target_id'],
            'erp_production_kitting_confirmations' => ['target_type', 'target_id'],
            'erp_production_input_holdings' => ['target_type', 'target_id'],
            'erp_material_receipts' => ['work_order_id'],
            'erp_material_deliveries' => ['work_order_id', 'status'],
            'erp_material_picking_tasks' => ['work_order_id', 'status', 'inventory_transaction_id'],
            'erp_work_order_material_requirements' => ['work_order_id', 'issued_qty', 'picked_qty', 'delivered_qty', 'received_qty', 'returned_qty'],
            'erp_work_order_material_preparations' => ['work_order_id', 'status'],
            'erp_work_order_preparation_materials' => ['work_order_id', 'status'],
            'erp_material_procurement_sources' => ['work_order_id', 'preparation_material_requirement_id', 'target_material_requirement_id'],
            'erp_assembly_inventory_reservations' => ['component_demand_id', 'work_order_id', 'inventory_balance_id', 'reserved_base_qty', 'consumed_base_qty', 'status', 'released_at'],
            'erp_assembly_component_demands' => ['assembly_plan_id', 'root_work_order_id', 'parent_work_order_id', 'child_work_order_id', 'status',
                'bom_item_id', 'item_id', 'base_unit_id', 'path_key', 'required_base_qty', 'inventory_reserved_base_qty', 'production_base_qty', 'demand_snapshot', 'created_by_legacy_id'],
            'erp_assembly_production_plans' => ['root_work_order_id', 'status', 'plan_version', 'work_order_version', 'input_hash', 'plan_snapshot', 'cancelled_at',
                'command_id', 'request_hash', 'prepared_by_legacy_id', 'prepared_at', 'organization_code'],
            'erp_work_order_status_logs' => ['work_order_id', 'before_status', 'after_status', 'reason', 'operator_legacy_id', 'operator_name', 'organization_code', 'before_version', 'after_version', 'occurred_at'],
            'erp_units' => ['unit_name', 'decimal_places', 'status', 'is_legacy'],
            'erp_boms' => ['output_item_id', 'version', 'status', 'audit_status', 'effective_date', 'expire_date'],
            'erp_bom_items' => ['bom_id', 'line_no', 'component_item_id', 'unit_id', 'qty', 'loss_rate', 'fixed_qty'],
            'erp_inventory_transactions' => ['posting_status', 'source_type', 'source_id', 'transaction_type'],
            'erp_production_output_warehouse_postings' => ['output_record_id', 'warehouse_id', 'location_id', 'batch_no', 'status', 'posted_base_qty', 'inventory_transaction_id'],
            'erp_work_order_finished_goods_receipts' => ['output_record_id', 'status'],
            'erp_production_execution_commands' => ['client_command_id', 'command_type', 'aggregate_type', 'aggregate_id', 'request_hash', 'status',
                'response_snapshot', 'initiated_by_legacy_id', 'processing_finished_at'],
        ];
        foreach ($definitions as $name => $columns) $this->database->getConnection()->getSchemaBuilder()->create($name, function (Blueprint $table) use ($columns, $name): void {
            $table->increments('id');
            foreach ($columns as $column) {
                if ($name === 'erp_work_order_inventory_continuations' && $column === 'status') $table->string($column)->default('RESERVED');
                elseif ($name === 'erp_work_order_inventory_continuations' && $column === 'business_version') $table->integer($column)->default(1);
                elseif ($name === 'erp_work_order_inventory_continuations' && $column === 'received_base_qty') $table->string($column)->default('0');
                elseif ($name === 'erp_production_execution_events' && in_array($column, ['before_version', 'after_version'], true)) $table->integer($column);
                elseif ($name === 'erp_assembly_component_demands' && $column === 'status') $table->string($column)->default('PREPARED');
                else $table->string($column)->nullable();
            }
            if ($name === 'erp_assembly_component_demands') $table->unique(['assembly_plan_id', 'path_key']);
            if ($name === 'erp_production_execution_commands') $table->unique('client_command_id');
            $table->timestamps();
        });
    }
}
