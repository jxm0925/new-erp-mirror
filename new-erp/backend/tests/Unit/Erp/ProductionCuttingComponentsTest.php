<?php

namespace Tests\Unit\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\{AuthContextService, CuttingCommandService, CuttingOutputEligibilityService, DocumentNumberService,
    ProductionCuttingComponentService, ProductionCuttingOperationService, ProductionDataScopeResolver, ProductionHandoverService,
    ProductionMaterialCostService, ProductionTargetReadinessService, WorkOrderCompletionReadinessService};
use App\Services\Erp\ProductionOutputService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Facade};
use PHPUnit\Framework\TestCase;

/** SQLite :memory: services only; no Laravel boot, .env, MySQL, or business fixtures. */
final class ProductionCuttingComponentsTest extends TestCase
{
    private Capsule $db;
    private ?Container $previousContainer;
    private mixed $previousResolver;
    private mixed $previousFacade;
    private ProductionCuttingComponentService $components;
    private ProductionCuttingOperationService $cutting;
    private ProductionMaterialCostService $costs;
    private ProductionDataScopeResolver $scope;
    private object $target;
    private array $requirements;
    private array $proof;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance(); $this->previousResolver = Model::getConnectionResolver(); $this->previousFacade = Facade::getFacadeApplication();
        $container = new Container; Container::setInstance($container); $this->db = new Capsule($container);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']); $this->db->bootEloquent();
        $container->instance('db', $this->db->getDatabaseManager()); $container->instance('db.schema', $this->db->getConnection()->getSchemaBuilder());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container); $this->schema();
        $numbers = $this->createMock(DocumentNumberService::class); $counter = 0; $numbers->method('next')->willReturnCallback(function () use (&$counter) { return 'MEM-'.++$counter; });
        $container->instance(DocumentNumberService::class, $numbers); $this->scope = new ProductionDataScopeResolver(new AuthContextService);
        $commands = new CuttingCommandService($this->scope); $container->instance(CuttingCommandService::class, $commands);
        $container->instance(CuttingOutputEligibilityService::class, new CuttingOutputEligibilityService($commands));
        $this->components = new ProductionCuttingComponentService($numbers); $container->instance(ProductionCuttingComponentService::class, $this->components);
        $this->cutting = new ProductionCuttingOperationService($commands, $numbers); $container->instance(ProductionCuttingOperationService::class, $this->cutting);
        $this->costs = new ProductionMaterialCostService($numbers); $this->fixtures();
    }

    protected function tearDown(): void
    {
        $this->db->getDatabaseManager()->disconnect(); $this->previousResolver ? Model::setConnectionResolver($this->previousResolver) : Model::unsetConnectionResolver();
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previousFacade); Container::setInstance($this->previousContainer);
    }

    public function test_same_raw_item_different_lengths_are_distinct_components_without_finished_item_or_sn(): void
    {
        $output = $this->bundle(); $identity = DB::transaction(fn () => $this->components->handoverIdentity($output));
        $this->assertNull($output->output_item_id); $this->assertNull($output->serial_id); $this->assertNull($output->material_lot_id); $this->assertNull($output->material_holding_id);
        $this->assertSame('100.0000', $output->material_total_cost); $this->assertSame(2, count($identity['components']));
        $this->assertSame([1, 1], array_column($identity['components'], 'raw_item_id'));
        $this->assertSame([101, 102], array_column($identity['components'], 'source_requirement_id'));
        $this->assertSame(['1000.00', '500.00'], array_map(fn ($r) => $r['measurements']['length_mm'], $identity['components']));
        $this->assertSame(['2.00000000', '2.00000000'], array_column($identity['components'], 'piece_qty'));
        $this->assertSame(1.0, (float) DB::table('erp_production_material_consumptions')->where('output_record_id', $output->id)->value('quantity'));
        $this->assertSame(0.2, (float) DB::table('erp_production_target_material_requirements')->where('id', 101)->value('consumed_base_qty'));
        $this->assertSame(0.1, (float) DB::table('erp_production_target_material_requirements')->where('id', 102)->value('consumed_base_qty'));
        $this->assertSame([1, 1], DB::table('erp_material_lots')->whereIn('id', [11, 12])->pluck('item_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_actual_handover_command_receives_each_spec_once_then_welding_creates_real_frame_output(): void
    {
        $output = $this->bundle(); $handover = $this->handover($output); $service = $this->handoverService();
        $payload = ['client_command_id' => 'receive-components', 'expected_version' => 1]; $user = (object) ['legacy_id' => 7];
        $response = $service->accept($handover->id, $payload, $user, ['production.handover.receive'], true);
        $this->assertSame('RECEIVED', $response['status']); $this->assertSame($response, $service->accept($handover->id, $payload, $user, ['production.handover.receive'], true));
        $this->assertSame(2, DB::table('erp_production_input_holdings')->where('source_output_record_id', $output->id)->count());
        $this->assertSame(2, DB::table('erp_material_movements')->where('action', 'CUT_COMPONENT_HANDOVER')->count());
        $this->assertSame(0.0, (float) DB::table('erp_material_holdings')->whereIn('id', [21, 22])->sum('quantity'));
        $frameId = DB::table('erp_production_output_records')->insertGetId($this->outputRow(20, 2));
        $frame = DB::table('erp_production_output_records')->where('id', $frameId)->first(); $welding = DB::table('erp_production_quantity_operations')->where('id', 20)->first();
        $frame = DB::transaction(fn () => $this->costs->consume($frame, $welding, 'quantity_operation', [], 7, []));
        $this->assertSame(2, (int) $frame->output_item_id); $this->assertSame(100.0, (float) $frame->material_total_cost);
        $this->assertSame(2, (int) DB::table('erp_material_lots')->where('id', $frame->material_lot_id)->value('item_id'));
        $this->assertSame(2, DB::table('erp_production_material_consumptions')->where('output_record_id', $frameId)->count());
        $this->assertSame(4.0, (float) DB::table('erp_production_material_consumptions')->where('output_record_id', $frameId)->sum('quantity'));
        $this->assertSame(0, DB::table('erp_production_input_holdings')->where('source_output_record_id', $output->id)->where('status', 'ACTIVE')->count());
    }

    public function test_receiver_owner_still_required_even_for_administrator_scope(): void
    {
        $handover = $this->handover($this->bundle());
        $this->expectCode('expected_receiver_required', fn () => $this->handoverService()->accept($handover->id,
            ['client_command_id' => 'wrong-receiver', 'expected_version' => 1], (object) ['legacy_id' => 9], ['production.handover.receive'], true));
        $this->assertSame(0, DB::table('erp_production_input_holdings')->whereNotNull('source_output_record_id')->count());
    }

    public function test_component_bundle_rejects_cross_work_order_or_started_successor(): void
    {
        $output = $this->bundle(); $handover = $this->handover($output);
        DB::table('erp_production_quantity_operations')->where('id', 20)->update(['work_order_id' => 99]);
        $this->expectCode('cutting_components_target_invalid', fn () => DB::transaction(fn () => $this->components->acceptHandover($handover, '1', 7)));
        DB::table('erp_production_quantity_operations')->where('id', 20)->update(['work_order_id' => 1, 'started_at' => '2026-10-14 00:00:00']);
        $this->expectCode('cutting_components_target_invalid', fn () => DB::transaction(fn () => $this->components->acceptHandover($handover, '1', 7)));
        $this->assertSame(0, DB::table('erp_production_input_holdings')->whereNotNull('source_output_record_id')->count());
    }

    public function test_component_bundle_rejects_changed_piece_dimension_cost_or_source_identity(): void
    {
        $output = $this->bundle(); $handover = $this->handover($output);
        DB::table('erp_cutting_results')->where('id', 31)->update(['measurements' => json_encode(['length_mm' => '999.00'])]);
        $this->expectCode('output_length_mismatch', fn () => DB::transaction(fn () => $this->components->acceptHandover($handover, '1', 7)));
        DB::table('erp_cutting_results')->where('id', 31)->update(['measurements' => json_encode(['length_mm' => '1000.00'])]);
        DB::table('erp_material_holdings')->where('id', 21)->update(['total_cost' => '66']);
        $this->expectCode('cutting_components_holding_invalid', fn () => DB::transaction(fn () => $this->components->acceptHandover($handover, '1', 7)));
        $this->assertSame(0, DB::table('erp_production_input_holdings')->whereNotNull('source_output_record_id')->count());
    }

    public function test_direct_low_level_draft_cannot_split_one_member_into_excess_results(): void
    {
        $batch = DB::table('erp_cutting_settlement_batches')->where('id', 1)->first();
        $rows = DB::table('erp_cutting_results')->get(); $this->cutting->assertComponentResultCoverage($batch, $rows);
        $extra = clone $rows[0]; $extra->id = 99; $extra->actual_qty = $extra->piece_qty = '1'; $rows->push($extra);
        $this->expectCode('cutting_component_result_quantity_invalid', fn () => $this->cutting->assertComponentResultCoverage($batch, $rows));
    }

    public function test_freeze_one_received_root_into_multiple_proven_bom_rows_and_reject_unowned_member(): void
    {
        $input = DB::table('erp_production_input_holdings')->where('id', 1)->first(); $batch = DB::table('erp_cutting_settlement_batches')->where('id', 1)->first();
        $source = $this->source('root', '1');
        $method = new \ReflectionMethod($this->cutting, 'componentInputSnapshot');
        $snapshot = $method->invoke($this->cutting, $this->components->link('quantity_operation', 10), $input, $batch, [$source]);
        $this->assertSame([101, 102], array_column($snapshot['components'], 'target_material_requirement_id')); $this->assertSame(1, $snapshot['source_count']);
        $this->assertSame('1.00000000', $snapshot['actual_stock_qty']); $this->assertSame(['root:0'], $snapshot['source_keys']);
        $source['members'][1]['target_material_requirement_id'] = 999;
        $this->expectCode('cutting_component_member_invalid', fn () => $method->invoke($this->cutting, $this->components->link('quantity_operation', 10), $input, $batch, [$source]));
    }

    public function test_six_meters_are_one_proven_root_and_ledger_quantity_never_becomes_six_roots(): void
    {
        $input = DB::table('erp_production_input_holdings')->where('id', 1)->first();
        DB::table('erp_production_cutting_inputs')->delete();
        $allocation = ['unit_kind' => 'root', 'stock_unit_kind' => 'm', 'sources' => [$this->source('m', '6')]];
        DB::table('erp_material_receipt_lines')->insert(['id' => 1, 'accepted_serial_snapshot' => json_encode(['cutting_allocation' => $allocation])]);
        $method = new \ReflectionMethod($this->cutting, 'componentSources'); $selected = $method->invoke($this->cutting, $input, null, '6');
        $this->assertCount(1, $selected); $this->assertSame('6.00000000', $selected[0]['actual_stock_qty']);
        $this->expectCode('cutting_component_source_exceeded', fn () => $method->invoke($this->cutting, $input, null, '1'));
        $allocation['sources'][0]['actual_stock_qty'] = '1';
        DB::table('erp_material_receipt_lines')->where('id', 1)->update(['accepted_serial_snapshot' => json_encode(['cutting_allocation' => $allocation])]);
        $this->expectCode('cutting_component_stock_source_invalid', fn () => $method->invoke($this->cutting, $input, null, '1'));
    }

    public function test_real_material_use_command_freezes_one_six_meter_source_for_two_bom_rows_once(): void
    {
        DB::table('erp_production_cutting_inputs')->delete();
        foreach ($this->requirements as &$row) {
            $size = json_decode($row['cutting_requirement_snapshot'], true); $size['quantity_calculation']['unit_kind'] = 'm';
            $row['cutting_requirement_snapshot'] = json_encode($size);
            DB::table('erp_work_order_material_requirements')->where('id', $row['id'])->update(['cutting_requirement_snapshot' => $row['cutting_requirement_snapshot']]);
        }
        unset($row);
        DB::table('erp_production_cutting_operations')->where('id', 1)->update(['technical_snapshot' => json_encode(['output_kind' => ProductionCuttingComponentService::KIND, 'output_item_id' => null, 'configuration_id' => null, 'requirements' => $this->requirements])]);
        DB::table('erp_production_input_holdings')->where('id', 1)->update(['source_holding_id' => 9, 'quantity' => 6, 'total_cost' => 100, 'status' => 'ACTIVE']);
        DB::table('erp_material_holdings')->where('id', 1)->update(['quantity' => 6, 'total_cost' => 100, 'status' => 'ACTIVE']);
        DB::table('erp_material_receipt_lines')->insert(['id' => 1, 'accepted_serial_snapshot' => json_encode(['cutting_allocation' => ['unit_kind' => 'root', 'stock_unit_kind' => 'm', 'sources' => [$this->source('m', '6')]]])]);
        DB::table('erp_inventory_transactions')->insert(['id' => 1, 'posting_status' => 'posted', 'transaction_type' => 'production_material_picking_outbound']);
        DB::table('erp_inventory_transaction_items')->insert(['id' => 1, 'transaction_id' => 1, 'item_id' => 1]);
        DB::table('erp_material_movements')->insert(['source_holding_id' => 9, 'target_holding_id' => 1, 'action' => 'PROD_MATERIAL_RECEIPT']);
        DB::table('erp_items')->where('id', 1)->update(['standard_stock_length_mm' => 999]);
        $payload = ['client_command_id' => 'use-one-real-root', 'expected_version' => 1, 'production_input_holding_id' => 1, 'quantity' => '6'];
        $user = (object) ['legacy_id' => 7]; $permissions = ['production.cutting.record'];
        $result = $this->cutting->useMaterial(1, 'quantity_operation', 10, $payload, $user, $permissions, true);
        $this->assertSame($result, $this->cutting->useMaterial(1, 'quantity_operation', 10, $payload, $user, $permissions, true));
        $batch = DB::table('erp_cutting_settlement_batches')->where('id', $result['settlement_batch_id'])->first();
        $used = DB::table('erp_production_cutting_inputs')->where('settlement_batch_id', $batch->id)->first(); $frozen = json_decode($used->requirement_snapshot, true);
        $this->assertSame(1.0, (float) $batch->input_qty); $this->assertSame(6000.0, (float) $batch->standard_stock_length_mm);
        $this->assertSame(6.0, (float) $used->quantity); $this->assertSame(100.0, (float) $used->total_cost);
        $this->assertSame(2, count($frozen['components'])); $this->assertSame('m', $frozen['stock_unit_kind']);
        $this->assertSame(['root:0'], $frozen['source_keys']); $this->assertSame('6.00000000', $frozen['actual_stock_qty']);
        $this->assertTrue($this->cutting->receivedInputValid($batch));
        $this->assertSame(1, DB::table('erp_production_cutting_inputs')->count()); $this->assertSame(0.0, (float) DB::table('erp_material_holdings')->where('id', 1)->value('quantity'));
    }

    public function test_bundle_cannot_be_terminal_or_warehouse_and_existing_item_mode_is_preserved(): void
    {
        $this->expectCode('cutting_components_warehouse_forbidden', fn () => DB::transaction(fn () => $this->components->createOutput('quantity_operation', $this->target, 7, ['disposition' => 'warehouse'], now(), false)));
        DB::table('erp_production_quantity_operations')->where('id', 20)->delete();
        $this->expectCode('cutting_components_route_required', fn () => $this->components->assertTarget($this->target, 'quantity_operation'));
        DB::table('erp_production_cutting_operations')->where('id', 1)->update(['technical_snapshot' => json_encode(['output_kind' => 'item'])]);
        $this->assertNull($this->components->createOutput('quantity_operation', $this->target, 7, [], now(), false));
    }

    public function test_sqm_uses_one_actual_sheet_and_its_half_square_meter_stock_quantity(): void
    {
        DB::table('erp_production_cutting_inputs')->delete();
        $input = DB::table('erp_production_input_holdings')->where('id', 1)->first();
        $physical = (object) ['id' => 8, 'dimensions' => json_encode(['length_mm' => '1000', 'width_mm' => '500', 'thickness_mm' => '2'])];
        $source = $this->source('m2', '0.5'); $source['source_key'] = 'physical:8'; $source['physical_material_id'] = 8;
        $source['dimensions'] = json_decode($physical->dimensions, true);
        DB::table('erp_material_receipt_lines')->insert(['id' => 1, 'accepted_serial_snapshot' => json_encode(['cutting_allocation' => ['unit_kind' => 'sheet', 'stock_unit_kind' => 'm2', 'sources' => [$source]]])]);
        $method = new \ReflectionMethod($this->cutting, 'componentSources'); $selected = $method->invoke($this->cutting, $input, $physical, null);
        $this->assertCount(1, $selected); $this->assertSame('0.50000000', $selected[0]['actual_stock_qty']);
        $this->expectCode('cutting_component_source_exceeded', fn () => $method->invoke($this->cutting, $input, $physical, '1'));
        $physical->dimensions = json_encode(['length_mm' => '1000', 'width_mm' => '600', 'thickness_mm' => '2']);
        $this->expectCode('cutting_component_physical_changed', fn () => $method->invoke($this->cutting, $input, $physical, null));
    }

    public function test_required_quality_and_public_warehouse_guard_still_apply_to_bundle(): void
    {
        $this->target->quality_mode_snapshot = 'required';
        $output = DB::transaction(fn () => $this->components->createOutput('quantity_operation', $this->target, 7, [], now(), false));
        $this->assertSame('WAIT_QUALITY', $output->status); $this->assertNull($output->serial_id); $this->assertNull($output->output_item_id);
        $warehouse = (new \ReflectionClass(ProductionOutputService::class))->newInstanceWithoutConstructor();
        $this->expectCode('cutting_components_warehouse_forbidden', fn () => $warehouse->warehouse($output->id,
            ['client_command_id' => 'warehouse-components', 'expected_version' => 1], (object) ['legacy_id' => 7], ['production.output.warehouse']));
        $this->assertSame(0, DB::table('erp_production_execution_commands')->where('client_command_id', 'warehouse-components')->count());
    }

    private function bundle(): object
    { return DB::transaction(fn () => $this->components->attachCosts($this->components->createOutput('quantity_operation', $this->target, 7, ['disposition' => 'direct_handover'], now(), false), $this->target, 'quantity_operation', 7)); }

    private function handover(object $output): object
    {
        $identity = DB::transaction(fn () => $this->components->handoverIdentity($output));
        $id = DB::table('erp_production_operation_handovers')->insertGetId(['work_order_id' => 1, 'source_target_type' => 'quantity_operation', 'source_target_id' => 10,
            'target_target_type' => 'quantity_operation', 'target_target_id' => 20, 'target_material_requirement_id' => null, 'output_record_id' => $output->id,
            'status' => 'WAIT_RECEIVE', 'business_version' => 1, 'identity_snapshot' => json_encode($identity), 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('erp_production_operation_handovers')->where('id', $id)->first();
    }

    private function handoverService(): ProductionHandoverService
    {
        $readiness = $this->createMock(ProductionTargetReadinessService::class); $readiness->method('refresh')->willReturn(['target_status' => 'READY', 'target_business_version' => 2]);
        $completion = (new \ReflectionClass(WorkOrderCompletionReadinessService::class))->newInstanceWithoutConstructor();
        return new ProductionHandoverService($completion, $readiness, $this->costs, $this->scope);
    }

    private function expectCode(string $code, callable $action): void
    { try { $action(); $this->fail('Expected '.$code); } catch (WorkOrderDomainException $e) { $this->assertSame($code, $e->errorCode); } }

    private function source(string $stockKind, string $quantity): array
    {
        return ['source_key' => 'root:0', 'stock_unit_kind' => $stockKind, 'actual_stock_qty' => $quantity,
            'dimensions' => ['length_mm' => '6000.00'], 'members' => array_map(fn ($r) => ['target_material_requirement_id' => $r['target_requirement_id'],
                'material_requirement_id' => $r['id'], 'bom_item_id' => $r['bom_item_id'], 'covered_piece_qty' => 2], $this->requirements)];
    }

    private function outputRow(int $targetId, ?int $itemId): array
    { return ['work_order_id' => 1, 'source_target_type' => 'quantity_operation', 'source_target_id' => $targetId, 'output_item_id' => $itemId,
        'output_no' => 'OUT-'.$targetId, 'output_base_qty' => '1', 'output_mode_snapshot' => 'flow_only', 'quality_mode_snapshot' => 'none', 'status' => 'CREATED', 'business_version' => 1]; }

    private function fixtures(): void
    {
        DB::table('erp_work_orders')->insert(['id' => 1, 'source_type' => 'sales_order', 'output_item_id' => 2, 'status' => 'IN_PROGRESS']);
        DB::table('erp_production_quantity_operations')->insert([['id' => 10, 'work_order_id' => 1, 'sequence_no_snapshot' => 10, 'stage_code_snapshot' => 'CUTTING', 'output_mode_snapshot' => 'flow_only', 'quality_mode_snapshot' => 'none', 'completed_base_qty' => '1', 'planned_base_qty' => '1', 'status' => 'IN_PROGRESS', 'business_version' => 1],
            ['id' => 20, 'work_order_id' => 1, 'sequence_no_snapshot' => 20, 'stage_code_snapshot' => 'WELDING', 'output_mode_snapshot' => 'flow_only', 'quality_mode_snapshot' => 'none', 'completed_base_qty' => '0', 'planned_base_qty' => '1', 'status' => 'WAIT_HANDOVER', 'business_version' => 1]]);
        $this->target = DB::table('erp_production_quantity_operations')->where('id', 10)->first();
        DB::table('erp_production_tasks')->insert(['id' => 2, 'work_order_id' => 1, 'assignee_user_legacy_id' => 7, 'status' => 'WAIT_HANDOVER', 'business_version' => 1]);
        DB::table('erp_production_tasks')->insert(['id' => 1, 'work_order_id' => 1, 'assignee_user_legacy_id' => 7, 'status' => 'IN_PROGRESS', 'business_version' => 1]);
        DB::table('erp_production_task_targets')->insert(['task_id' => 2, 'target_type' => 'quantity_operation', 'target_id' => 20]);
        DB::table('erp_production_task_targets')->insert(['task_id' => 1, 'target_type' => 'quantity_operation', 'target_id' => 10]);
        DB::table('erp_items')->insert(['id' => 1, 'item_code' => 'RAW', 'item_name' => '方管', 'cutting_mode' => 'length', 'standard_stock_length_mm' => 6000]);
        $this->requirements = []; $this->proof = [];
        foreach ([101 => '1000.00', 102 => '500.00'] as $id => $length) {
            $size = ['length_mm' => $length, 'piece_qty' => 2, 'quantity_calculation' => ['mode' => 'length', 'unit_kind' => 'root']];
            $this->requirements[] = ['target_requirement_id' => $id, 'id' => $id-100, 'bom_id' => 1, 'bom_item_id' => $id+100, 'component_item_id' => 1,
                'configuration_id' => null, 'required_piece_qty' => '2', 'cutting_requirement_snapshot' => json_encode($size)];
            $this->proof[] = ['output_kind' => ProductionCuttingComponentService::KIND, 'target_material_requirement_id' => $id, 'material_requirement_id' => $id-100,
                'bom_item_id' => $id+100, 'bom_id' => 1, 'item_id' => 1, 'configuration_id' => null, 'required_length_mm' => $length,
                'required_width_mm' => null, 'required_thickness_mm' => null, 'allow_cut_rotation' => false, 'per_output_piece_qty' => 1,
                'reference_piece_qty' => 2, 'required_piece_qty' => '2', 'covered_piece_qty' => '2', 'input' => ['mode' => 'length', 'item_id' => 1, 'length_mm' => '6000', 'input_qty' => '1']];
            DB::table('erp_production_target_material_requirements')->insert(['id' => $id, 'target_type' => 'quantity_operation', 'target_id' => 10,
                'work_order_id' => 1, 'material_requirement_id' => $id-100, 'component_item_id' => 1,
                'required_base_qty' => $id === 101 ? '0.2' : '0.1', 'consumed_base_qty' => 0, 'business_version' => 1]);
            DB::table('erp_work_order_material_requirements')->insert(['id' => $id-100, 'work_order_id' => 1, 'bom_id' => 1, 'bom_item_id' => $id+100,
                'component_item_id' => 1, 'cutting_requirement_snapshot' => json_encode($size)]);
        }
        DB::table('erp_production_cutting_operations')->insert(['id' => 1, 'work_order_id' => 1, 'target_type' => 'quantity_operation', 'target_id' => 10, 'cutting_order_id' => 1, 'cutting_task_id' => 1,
            'technical_snapshot' => json_encode(['output_kind' => ProductionCuttingComponentService::KIND, 'requirements' => $this->requirements])]);
        DB::table('erp_cutting_orders')->insert(['id' => 1, 'status' => 'IN_PROGRESS', 'business_version' => 1]);
        DB::table('erp_cutting_settlement_batches')->insert(['id' => 1, 'cutting_order_id' => 1, 'input_item_id' => 1, 'input_qty' => 1, 'standard_stock_length_mm' => '6000', 'status' => 'CONFIRMED']);
        DB::table('erp_production_input_holdings')->insert(['id' => 1, 'target_type' => 'quantity_operation', 'target_id' => 10, 'target_material_requirement_id' => 101,
            'input_holding_id' => 1, 'material_receipt_line_id' => 1, 'inventory_transaction_item_id' => 1, 'quantity' => 1, 'total_cost' => 100, 'status' => 'CONSUMED']);
        DB::table('erp_material_holdings')->insert(['id' => 1, 'material_lot_id' => 1, 'position_type' => 'PRODUCTION_INPUT', 'position_id' => 1, 'quantity' => 0, 'total_cost' => 0, 'status' => 'CONSUMED', 'business_version' => 1]);
        DB::table('erp_production_cutting_inputs')->insert(['id' => 1, 'operation_id' => 1, 'settlement_batch_id' => 1, 'production_input_holding_id' => 1, 'quantity' => 1, 'total_cost' => 100,
            'requirement_snapshot' => json_encode(['output_kind' => ProductionCuttingComponentService::KIND, 'actual_stock_qty' => '1', 'source_count' => 1, 'source_keys' => ['root:0'], 'components' => $this->proof])]);
        foreach ($this->proof as $index => $r) {
            $resultId = 31+$index; $routeId = 41+$index; $holdingId = 21+$index; $lotId = 11+$index; $cost = $index === 0 ? '66.6667' : '33.3333';
            DB::table('erp_cutting_results')->insert(['id' => $resultId, 'settlement_batch_id' => 1, 'result_type' => 'product', 'item_id' => 1, 'actual_qty' => 2, 'piece_qty' => 2,
                'cut_length_mm' => $r['required_length_mm'], 'measurements' => json_encode(['length_mm' => $r['required_length_mm']]), 'cutting_requirement_snapshot' => json_encode($r), 'material_lot_id' => $lotId, 'status' => 'CONFIRMED']);
            DB::table('erp_cutting_result_routes')->insert(['id' => $routeId, 'result_id' => $resultId, 'holding_id' => $holdingId, 'quantity' => 2, 'total_cost' => $cost, 'status' => 'WAIT_OPERATION']);
            DB::table('erp_material_lots')->insert(['id' => $lotId, 'item_id' => 1, 'source_type' => 'cutting_result', 'source_id' => $resultId, 'material_form' => 'CUT_OUTPUT']);
            DB::table('erp_material_holdings')->insert(['id' => $holdingId, 'material_lot_id' => $lotId, 'position_type' => 'OPERATION_CUT_OUTPUT', 'position_id' => $routeId,
                'quantity' => 2, 'total_cost' => $cost, 'status' => 'ACTIVE', 'business_version' => 1]);
        }
    }

    private function schema(): void
    {
        $tables = [
            'erp_work_orders' => 'source_type output_item_id output_configuration_id status',
            'erp_production_quantity_operations' => 'work_order_id production_unit_id routing_operation_id_snapshot sequence_no_snapshot stage_code_snapshot operation_code_snapshot output_mode_snapshot quality_mode_snapshot completed_base_qty planned_base_qty started_at status business_version',
            'erp_production_unit_operations' => 'work_order_id production_unit_id sequence_no_snapshot started_at',
            'erp_production_cutting_operations' => 'work_order_id target_type target_id cutting_order_id cutting_task_id technical_snapshot',
            'erp_cutting_orders' => 'status business_version',
            'erp_cutting_settlement_batches' => 'batch_no cutting_order_id cutting_task_id input_item_id physical_material_id source_holding_id wip_holding_id input_qty original_total_cost standard_stock_length_mm status business_version',
            'erp_production_cutting_inputs' => 'operation_id settlement_batch_id production_input_holding_id quantity total_cost requirement_snapshot',
            'erp_production_output_records' => 'output_no work_order_id source_target_type source_target_id production_unit_id output_item_id output_base_qty output_mode_snapshot quality_mode_snapshot status serial_id inventory_serial_id material_lot_id material_holding_id material_total_cost material_loss_cost disposition created_by_legacy_id produced_at business_version',
            'erp_cutting_results' => 'settlement_batch_id result_type item_id configuration_id actual_qty piece_qty cut_length_mm measurements cutting_requirement_snapshot material_lot_id status',
            'erp_cutting_result_routes' => 'result_id holding_id quantity total_cost status received_qty received_cost',
            'erp_material_lots' => 'lot_no item_id configuration_id stage_id material_form source_type source_id',
            'erp_material_holdings' => 'material_lot_id position_type position_id quantity total_cost status business_version inventory_balance_id',
            'erp_production_input_holdings' => 'target_type target_id target_material_requirement_id source_output_record_id source_holding_id input_holding_id operation_handover_id inventory_transaction_item_id material_receipt_line_id quantity total_cost status',
            'erp_production_material_consumptions' => 'output_record_id target_material_requirement_id source_holding_id source_material_lot_id quantity total_cost operator_legacy_id occurred_at',
            'erp_production_target_material_requirements' => 'work_order_id target_type target_id material_requirement_id component_item_id required_base_qty consumed_base_qty business_version',
            'erp_work_order_material_requirements' => 'work_order_id bom_id bom_item_id component_item_id cutting_requirement_snapshot',
            'erp_cutting_cost_dispositions' => 'result_id disposition status total_cost',
            'erp_material_movements' => 'movement_no source_holding_id target_holding_id action quantity total_cost operator_legacy_id',
            'erp_production_operation_handovers' => 'work_order_id source_target_type source_target_id target_target_type target_target_id target_material_requirement_id output_record_id status identity_snapshot business_version received_by_legacy_id received_at completeness_snapshot accepted_base_qty',
            'erp_production_tasks' => 'work_order_id assignee_user_legacy_id status business_version',
            'erp_production_task_targets' => 'task_id target_type target_id',
            'erp_production_execution_commands' => 'client_command_id command_type aggregate_type aggregate_id request_hash status initiated_by_legacy_id processing_started_at result_type result_id response_snapshot processing_finished_at',
            'erp_material_receipt_lines' => 'accepted_serial_snapshot',
            'erp_items' => 'item_code item_name cutting_mode is_length_cut_material standard_stock_length_mm',
            'erp_inventory_transactions' => 'posting_status transaction_type',
            'erp_inventory_transaction_items' => 'transaction_id item_id',
            'erp_cutting_commands' => 'client_command_id command_type actor_legacy_id request_hash status response',
            'erp_cutting_events' => 'aggregate_type aggregate_id action operator_legacy_id before_snapshot after_snapshot occurred_at',
        ];
        foreach ($tables as $name => $columns) $this->db->getConnection()->getSchemaBuilder()->create($name, function (Blueprint $table) use ($columns) {
            $table->increments('id'); foreach (explode(' ', $columns) as $column) $table->text($column)->nullable(); $table->timestamps();
        });
    }
}
