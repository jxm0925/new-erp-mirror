<?php

namespace Tests\Unit\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{InventoryBalance, Item, MaterialPickingTaskLine, WorkOrderMaterialRequirement};
use App\Services\Erp\{ProductionCuttingAllocationService, ProductionPickingStockService, WorkOrderPreparationMaterialService};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/** A separately constructed SQLite :memory: connection; never boots Laravel or reads .env. */
final class ProductionCuttingAllocationInMemoryTest extends TestCase
{
    private Capsule $database;
    private ProductionCuttingAllocationService $service;
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance(); $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver(); $this->previousDispatcher = Model::getEventDispatcher();
        $container = new Container; Container::setInstance($container);
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        $this->database->bootEloquent(); $this->database->setAsGlobal();
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
        $container->instance('db', $this->database->getDatabaseManager());
        $container->bind('db.schema', fn () => $this->database->getConnection()->getSchemaBuilder());
        $this->service = new ProductionCuttingAllocationService;
        $container->instance(ProductionCuttingAllocationService::class, $this->service);
        $container->instance(ProductionPickingStockService::class, new ProductionPickingStockService);
        $this->tables();
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->purge('default');
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver); else Model::unsetConnectionResolver();
        Model::unsetEventDispatcher();
        if ($this->previousDispatcher) Model::setEventDispatcher($this->previousDispatcher);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_point_one_theoretical_requirement_plans_one_real_sheet_and_receives_its_coverage(): void
    {
        $requirement = $this->requirement(1, 1, '0.1'); $target = $this->target(1, 1, '0.1');
        $balance = $this->balance(1); $this->physical(1, 1, 200, 100, 2);
        $plan = $this->service->plan($requirement, $target, $balance, '1', null, true);
        $this->assertSame('0.10000000', $plan['planned_coverage_qty']);
        $line = $this->line(1, 1, $plan); $this->assertSame(0, $this->service->remaining($requirement, $target)['target_piece_qty']);
        $this->service->confirm($line, '1', [1]); $line->actual_pick_qty = 1; $line->save();
        $this->db()->table('erp_material_picking_tasks')->where('id', 1)->update(['status' => 'PICKED']);
        $receipt = $this->service->receive($line, '1', [1]);
        $this->assertSame(1, $receipt['covered_piece_qty']); $this->assertSame('0.10000000', $receipt['coverage_qty']);
        $this->assertSame('0.00000000', $this->service->formalRemaining($requirement));
        $this->assertSame('0.10000000', $requirement->fresh()->required_qty);
    }

    public function test_partial_actual_picking_releases_only_the_unpicked_sources_coverage(): void
    {
        $requirement = $this->requirement(1, 5, '0.5'); $target = $this->target(1, 5, '0.5');
        $balance = $this->balance(1); $this->physical(1, 1, 600, 100, 2); $this->physical(2, 1, 400, 100, 2);
        $plan = $this->service->plan($requirement, $target, $balance, '2', null, true);
        $line = $this->line(1, 1, $plan); $this->service->confirm($line, '1', [2]); $line->actual_pick_qty = 1; $line->save();
        $this->db()->table('erp_material_picking_tasks')->where('id', 1)->update(['status' => 'PICKED']);
        $remaining = $this->service->remaining($requirement, $target);
        $this->assertSame(3, $remaining['target_piece_qty']); $this->assertSame('0.30000000', $remaining['target_coverage_qty']);
        $this->assertSame('0.30000000', $this->service->formalRemaining($requirement));
        $receipt = $this->service->receive($line, '1', [2]);
        $this->assertSame(2, $receipt['covered_piece_qty']); $this->assertSame('0.20000000', $receipt['coverage_qty']);
        $this->saveReceipt(1, $line->id, $receipt);
        $this->expectException(WorkOrderDomainException::class); $this->service->receive($line->fresh(), '1', [2]);
    }

    public function test_cross_batch_sources_require_explicit_non_overlapping_covered_piece_counts(): void
    {
        $requirement = $this->requirement(1, 4, '0.4'); $target = $this->target(1, 4, '0.4');
        $first = $this->balance(1); $second = $this->balance(2); $this->physical(1, 1, 400, 100, 2); $this->physical(2, 2, 400, 100, 2);
        try { $this->service->plan($requirement, $target, $first, '1', null, false); $this->fail('Ambiguous automatic allocation was accepted.'); }
        catch (WorkOrderDomainException $e) { $this->assertSame('cutting_allocation_invalid', $e->errorCode); }
        $this->line(1, 1, $this->service->plan($requirement, $target, $first, '1', 2, false));
        $this->line(2, 1, $this->service->plan($requirement, $target, $second, '1', 2, false));
        $this->assertSame(0, $this->service->remaining($requirement, $target)['formal_piece_qty']);
        $this->expectException(WorkOrderDomainException::class); $this->service->plan($requirement, $target, $first, '1', 1, false);
    }

    public function test_same_physical_identity_cannot_be_reserved_by_two_formal_requirements(): void
    {
        $first = $this->requirement(1, 1, '0.1'); $second = $this->requirement(2, 1, '0.1');
        $balance = $this->balance(1); $this->physical(1, 1, 200, 100, 2);
        $this->line(1, 1, $this->service->plan($first, $this->target(1, 1, '0.1'), $balance, '1', 1, true, [1]));
        $this->expectException(WorkOrderDomainException::class);
        $this->service->plan($second, $this->target(2, 1, '0.1'), $balance, '1', 1, true, [1]);
    }

    public function test_sheet_geometry_filter_and_prediction_cannot_count_wrong_thickness_or_small_area(): void
    {
        $requirement = $this->requirement(1, 10, '1'); $balance = $this->balance(1);
        $this->physical(1, 1, 200, 100, 1); $this->physical(2, 1, 199, 10000, 2); $this->physical(3, 1, 200, 100, 2);
        $stock = new ProductionPickingStockService;
        $this->assertSame([3], $stock->physicalQuery($balance, $requirement)->pluck('physical.id')->all());
        $this->assertSame(1, $stock->minimumPieceCapacity($balance, $requirement));
        $this->assertSame('1.00000000', $stock->availableDecimal($balance, null, [], $requirement));
        $claimed = ['physical_ids' => [], 'balance_qty' => []];
        $method = new \ReflectionMethod(WorkOrderPreparationMaterialService::class, 'cuttingAvailableCoverage');
        $service = new WorkOrderPreparationMaterialService;
        $this->assertSame('0.10000000', $method->invokeArgs($service, [$requirement, &$claimed, '1']));
        $this->assertSame([3], $claimed['physical_ids']);
        $this->assertSame('0.00000000', $method->invokeArgs($service, [$requirement, &$claimed, '1']));
    }

    public function test_formal_return_reopens_the_exact_original_coverage_with_actual_whole_stock_preserved(): void
    {
        $requirement = $this->requirement(1, 1, '0.1'); $target = $this->target(1, 1, '0.1');
        $balance = $this->balance(1); $this->physical(1, 1, 200, 100, 2);
        $line = $this->line(1, 1, $this->service->plan($requirement, $target, $balance, '1', 1, true));
        $this->service->confirm($line, '1', [1]); $line->actual_pick_qty = 1; $line->save();
        $this->db()->table('erp_material_picking_tasks')->where('id', 1)->update(['status' => 'PICKED']);
        $receipt = $this->service->receive($line, '1', [1]); $this->saveReceipt(1, $line->id, $receipt);
        $this->db()->table('erp_production_input_holdings')->insert(['id' => 1, 'input_holding_id' => 50, 'material_receipt_line_id' => 1, 'target_material_requirement_id' => 1]);
        $this->db()->table('erp_production_material_returns')->insert(['id' => 1, 'status' => 'SUBMITTED']);
        $this->db()->table('erp_production_material_return_lines')->insert(['id' => 1, 'return_id' => 1, 'material_requirement_id' => 1, 'return_base_qty' => 1]);
        $this->db()->table('erp_production_material_return_cost_allocations')->insert(['id' => 1, 'return_line_id' => 1, 'source_input_holding_id' => 50, 'quantity' => 1, 'physical_material_ids' => '[1]']);
        $frozen = $this->service->freezeReturnAllocations(1, 1, '1');
        $this->assertSame('0.10000000', $frozen['cutting_allocation']['coverage_qty']);
        $this->db()->table('erp_production_material_returns')->where('id', 1)->update(['status' => 'COMPLETED']);
        $remaining = $this->service->remaining($requirement, $target);
        $this->assertSame(1, $remaining['target_piece_qty']); $this->assertSame('0.10000000', $remaining['target_coverage_qty']);
        $this->assertSame('0.10000000', $this->service->formalRemaining($requirement));
        $this->assertSame('1', $this->db()->table('erp_production_material_return_lines')->value('return_base_qty'));
    }

    private function tables(): void
    {
        $schema = $this->db()->getSchemaBuilder();
        foreach (['erp_work_order_material_requirements' => ['component_item_id', 'base_unit_id', 'required_piece_qty', 'required_qty', 'picked_qty', 'cut_length_mm_snapshot', 'cutting_requirement_snapshot'],
            'erp_material_picking_tasks' => ['status'],
            'erp_material_picking_task_lines' => ['task_id', 'material_requirement_id', 'material_supply_rule_snapshot_id', 'production_target_type', 'production_target_id', 'inventory_balance_id', 'planned_pick_qty', 'actual_pick_qty', 'received_qty', 'serial_snapshot'],
            'erp_material_receipt_lines' => ['picking_task_line_id', 'accepted_serial_snapshot'],
            'erp_material_lots' => ['item_id', 'configuration_id', 'material_form', 'cut_length_mm', 'source_type', 'source_id'],
            'erp_inventory_batches' => ['item_id', 'batch_no', 'status', 'expire_date'],
            'erp_material_physicals' => ['item_id', 'current_holding_id', 'dimensions', 'status'],
            'erp_material_holdings' => ['inventory_balance_id', 'position_type', 'status'],
            'erp_production_material_returns' => ['status'],
            'erp_production_material_return_lines' => ['return_id', 'material_requirement_id', 'serial_snapshot', 'return_base_qty'],
            'erp_production_material_return_cost_allocations' => ['return_line_id', 'source_input_holding_id', 'quantity', 'physical_material_ids'],
            'erp_production_input_holdings' => ['input_holding_id', 'material_receipt_line_id', 'target_material_requirement_id'],
            'erp_production_cutting_inputs' => ['production_input_holding_id', 'quantity']] as $table => $columns) {
            $schema->create($table, function (Blueprint $definition) use ($columns) { $definition->integer('id')->primary(); foreach ($columns as $column) $definition->text($column)->nullable(); $definition->timestamps(); });
        }
    }

    private function requirement(int $id, int $pieces, string $quantity): WorkOrderMaterialRequirement
    {
        $this->db()->table('erp_work_order_material_requirements')->insert(['id' => $id, 'component_item_id' => 10, 'base_unit_id' => 1,
            'required_piece_qty' => $pieces, 'required_qty' => $quantity, 'picked_qty' => 0,
            'cutting_requirement_snapshot' => json_encode(['length_mm' => 200, 'width_mm' => 100, 'thickness_mm' => 2,
                'quantity_calculation' => ['mode' => 'sheet', 'unit_kind' => 'sheet']], JSON_THROW_ON_ERROR)]);
        return WorkOrderMaterialRequirement::findOrFail($id);
    }

    private function target(int $id, int $pieces, string $quantity): object
    {
        return (object) ['id' => $id, 'required_piece_qty_snapshot' => $pieces, 'required_base_qty' => $quantity];
    }

    private function balance(int $id): InventoryBalance
    {
        $balance = new InventoryBalance(['id' => $id, 'item_id' => 10, 'unit_id' => 1, 'warehouse_id' => 1, 'location_id' => 2,
            'batch_no' => 'TEST-'.$id, 'quantity_on_hand' => 100, 'quantity_available' => 100, 'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0]);
        $balance->setRelation('item', new Item(['id' => 10, 'cutting_mode' => 'sheet', 'material_management_mode' => 'physical', 'management_scope' => 'factory']));
        $balance->setRelation('warehouse', (object) ['status' => 'enabled']); $balance->setRelation('location', (object) ['status' => 'enabled', 'warehouse_id' => 1]);
        return $balance;
    }

    private function physical(int $id, int $balanceId, int $length, int $width, int $thickness): void
    {
        $this->db()->table('erp_material_holdings')->insert(['id' => $id, 'inventory_balance_id' => $balanceId, 'position_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $this->db()->table('erp_material_physicals')->insert(['id' => $id, 'item_id' => 10, 'current_holding_id' => $id, 'status' => 'AVAILABLE',
            'dimensions' => json_encode(['length_mm' => $length, 'width_mm' => $width, 'thickness_mm' => $thickness], JSON_THROW_ON_ERROR)]);
    }

    private function line(int $taskId, int $requirementId, array $allocation): MaterialPickingTaskLine
    {
        $this->db()->table('erp_material_picking_tasks')->insertOrIgnore(['id' => $taskId, 'status' => 'WAIT_PICK']);
        return MaterialPickingTaskLine::create(['id' => $taskId, 'task_id' => $taskId, 'material_requirement_id' => $requirementId,
            'inventory_balance_id' => $allocation['inventory_balance_id'], 'planned_pick_qty' => count($allocation['sources']), 'actual_pick_qty' => 0, 'received_qty' => 0,
            'serial_snapshot' => ['cutting_allocation' => $allocation]]);
    }

    private function saveReceipt(int $id, int $lineId, array $allocation): void
    {
        $this->db()->table('erp_material_receipt_lines')->insert(['id' => $id, 'picking_task_line_id' => $lineId,
            'accepted_serial_snapshot' => json_encode(['cutting_allocation' => $allocation], JSON_THROW_ON_ERROR)]);
    }
    private function db() { return $this->database->getConnection(); }
}
