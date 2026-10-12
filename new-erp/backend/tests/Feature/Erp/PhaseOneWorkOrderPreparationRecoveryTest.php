<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{AssemblyComponentDemand, AssemblyProductionPlan, Bom, BomItem, InventoryBalance, InventoryLocationBalance, Item, MaterialPickingTask, MaterialPickingTaskLine, ProductionDemand, ProductionRouting, SalesOrder, SalesOrderLine, Unit, WorkOrder};
use App\Services\Erp\{AssemblyProductionApplicationService, AssemblyProductionInventoryService, InventoryService, ProductionMasterDataService, ProductionPickingStockService, RbacBootstrapService, ReleaseGateApplicationService, WorkOrderApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Erp\WorkOrderPreparationMaterialService;
use App\Services\Erp\WorkOrderTechnicalService;
use App\Models\Erp\ProductionExecutionCommand;
use Tests\TestCase;

class PhaseOneWorkOrderPreparationRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = ['production.work_order.view', 'production.work_order.create', 'production.work_order.edit',
        'production.work_order.submit', 'production.work_order.publish', 'production.work_order.cancel',
        'production.work_order.gate.view', 'production.demand.view', 'production.technical.prepare', 'production.material.view'];

    public function test_sales_root_commits_pending_before_a_later_preparation_failure(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '1');
        $demand = $this->salesDemand($f);
        $this->failMaterialSynchronization(new \RuntimeException('SQLSTATE secret credential must not be visible'));
        $outerLevel = DB::transactionLevel();
        $created = DB::transaction(function () use ($demand, $f, $stock) {
            $root = app(WorkOrderApplicationService::class)->ensureAutomaticSalesDraft($demand, $f['user']);
            $this->assertSame('PENDING', AssemblyProductionPlan::where('root_work_order_id', $root->id)->value('status'));
            $this->assertSame(0, WorkOrder::where('assembly_root_work_order_id', $root->id)->count());
            $this->assertSame('0.0000', (string) $stock->fresh()->quantity_locked);
            return $root;
        });
        // Laravel's test transaction manager executes afterCommit at level 1;
        // the test wrapper is never committed or replaced by a live transaction.
        $this->assertSame($outerLevel, DB::transactionLevel());
        $this->assertSame('WAIT_RELEASE', $created->fresh()->status);
        $plan = AssemblyProductionPlan::where('root_work_order_id', $created->id)->firstOrFail();
        $this->assertSame('BLOCKED', $plan->status);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($plan->plan_snapshot));
        $this->assertStringNotContainsString('credential', json_encode($plan->plan_snapshot));
        $this->assertSame(0, WorkOrder::where('assembly_root_work_order_id', $created->id)->count());
        $this->assertSame(0, AssemblyComponentDemand::where('root_work_order_id', $created->id)->count());
        $this->assertSame('0.0000', (string) $stock->fresh()->quantity_locked);
        $this->assertSame(0, DB::table('erp_work_order_preparation_materials')->where('work_order_id', $created->id)->count());
    }

    public function test_failed_command_replays_failure_and_new_command_retries_without_duplicate_ownership(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '1');
        $payload = $this->command($f['wo']);
        $this->failMaterialSynchronization(new \RuntimeException('Injected after child and reservation creation'));
        $this->expectCode('assembly_preparation_failed', fn () => $this->service()->prepare($f['wo']->id, $payload, $f['user'], self::PERMISSIONS));
        $this->app->forgetInstance(WorkOrderPreparationMaterialService::class);
        $this->expectCode('assembly_preparation_failed', fn () => $this->service()->prepare($f['wo']->id, $payload, $f['user'], self::PERMISSIONS));
        $this->assertSame('0.0000', (string) $stock->fresh()->quantity_locked);
        $this->assertSame(0, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->count());
        $this->assertSame('failed', ProductionExecutionCommand::where('client_command_id', $payload['client_command_id'])->value('status'));
        $retry = $this->command($f['wo']);
        $first = $this->service()->prepare($f['wo']->id, $retry, $f['user'], self::PERMISSIONS);
        $again = $this->service()->prepare($f['wo']->id, $retry, $f['user'], self::PERMISSIONS);
        $this->assertSame($first['plan_id'], $again['plan_id']);
        $this->assertSame(2, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->count());
        $this->assertSame('1.0000', (string) $stock->fresh()->quantity_locked);
        $this->expectCode('permission_denied', fn () => $this->service()->prepare($f['wo']->id, $retry, $f['user'], ['production.work_order.view']));
        $f['wo']->fresh()->update(['business_version' => 99]);
        $this->expectCode('version_conflict', fn () => $this->service()->prepare($f['wo']->id, $retry, $f['user'], self::PERMISSIONS));
    }

    public function test_wrapped_pdo_failure_rolls_back_instead_of_persisting_inside_the_failed_savepoint(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '1');
        // Exercise Laravel's wrapper type without claiming a real MySQL deadlock:
        // no concurrency marker is injected because the server did not implicitly
        // roll back this test's wrapping transaction.
        $this->failMaterialSynchronization(new \Illuminate\Database\DeadlockException('Injected wrapped driver failure'));
        $payload = $this->command($f['wo']);
        $this->expectCode('assembly_preparation_unavailable', fn () => $this->service()->prepare($f['wo']->id, $payload, $f['user'], self::PERMISSIONS));
        $this->assertSame(0, ProductionExecutionCommand::where('client_command_id', $payload['client_command_id'])->count());
        $this->assertSame(0, AssemblyProductionPlan::where('root_work_order_id', $f['wo']->id)->count());
        $this->assertSame(0, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->count());
        $this->assertSame('0.0000', (string) $stock->fresh()->quantity_locked);
    }

    public function test_live_material_invalidation_enables_retry_and_never_uses_old_ready_snapshot(): void
    {
        $f = $this->fixture();
        $f['frame']->update(['manufacturing_strategy' => 'purchase']);
        $f['box']->update(['manufacturing_strategy' => 'purchase']);
        $prepared = $this->prepare($f);
        $this->assertTrue($prepared['material_preparation']['ready']);
        $this->assertFalse($prepared['actions']['can_prepare']);
        $f['root_bom']->items()->first()->update(['qty' => 3]);
        $view = $this->preview($f);
        $this->assertSame('not_required', $view['status']);
        $this->assertFalse($view['material_preparation']['ready']);
        $this->assertFalse($view['preparation_ready']);
        $this->assertTrue($view['actions']['can_retry']);
        $readonly = $this->service()->preview($f['wo']->id, $f['user'], ['production.work_order.view']);
        $this->assertFalse($readonly['actions']['can_retry']);
        $this->assertFalse($readonly['actions']['can_prepare']);
        $this->assertTrue($this->prepare($f)['material_preparation']['ready']);
    }

    public function test_child_projection_contains_only_its_own_material_rows_and_scoped_links(): void
    {
        $f = $this->fixture(); $this->prepare($f);
        $child = WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->where('output_item_id', $f['frame']->id)->firstOrFail();
        $viewer = $this->actor(['production.work_order.view'], 'self');
        $child->update(['responsible_user_legacy_id' => $viewer['user']->legacy_id]);
        $view = $this->service()->preview($child->id, $viewer['user'], ['production.work_order.view']);
        $this->assertNotEmpty($view['material_preparation']['rows']);
        foreach ($view['material_preparation']['rows'] as $row) {
            $this->assertSame((int) $child->id, $row['work_order_id']);
            $this->assertSame((int) $f['raw']->id, $row['component_item_id']);
        }
        $this->assertArrayNotHasKey('child_material_preparations', $view);
        $this->assertArrayNotHasKey('root_base_qty', $view);
        $this->assertFalse($view['actions']['can_prepare']);
        $this->expectCode('data_scope_denied', fn () => $this->service()->preview($f['wo']->id, $viewer['user'], ['production.work_order.view']));
    }

    public function test_expected_child_technical_hold_keeps_the_complete_tree_and_can_be_resolved(): void
    {
        [$f, $child, $payload] = $this->technicalHoldFixture();
        $stock = $f['stock']; $rootVersion = (int) $f['wo']->fresh()->business_version;
        $sibling = WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->whereKeyNot($child->id)->firstOrFail();
        $this->expectCode('release_gate_blocked', fn () => app(WorkOrderApplicationService::class)->publish($sibling->id, $this->command($sibling), $f['user'], self::PERMISSIONS));
        $beforeIds = WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->orderBy('id')->pluck('id')->all();
        $view = $this->preview($f);
        $this->assertSame('prepared', $view['status']);
        $this->assertFalse($view['children_ready']);
        $this->assertFalse($view['preparation_ready']);
        $this->assertSame(2, count($beforeIds));
        $this->assertSame('1.0000', (string) $stock->fresh()->quantity_locked);
        $childView = $this->service()->preview($child->id, $f['user'], self::PERMISSIONS);
        $this->assertFalse($childView['material_preparation']['ready']);
        $this->assertSame([], $childView['material_preparation']['rows']);
        $this->expectCode('release_gate_blocked', fn () => app(WorkOrderApplicationService::class)->publish($child->id, $this->command($child), $f['user'], self::PERMISSIONS));

        $confirmed = app(WorkOrderApplicationService::class)->confirmTechnical($child->id, $payload, $f['user'], self::PERMISSIONS);
        $this->assertSame(1, (int) $confirmed->technical_version);
        $this->assertSame($rootVersion + 1, (int) $f['wo']->fresh()->business_version);
        $this->assertFalse($this->preview($f)['children_ready']);
        $this->expectCode('release_gate_blocked', fn () => app(WorkOrderApplicationService::class)->publish($child->id, $this->command($child), $f['user'], self::PERMISSIONS));
        $rechecked = $this->prepare($f);
        $this->assertTrue($rechecked['children_ready']);
        $this->assertSame($beforeIds, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->orderBy('id')->pluck('id')->all());
        $this->assertSame('1.0000', (string) $stock->fresh()->quantity_locked);
        $released = app(WorkOrderApplicationService::class)->publish($child->id, $this->command($child), $f['user'], self::PERMISSIONS);
        $this->assertSame('RELEASED', $released->status);
        $releasedSibling = app(WorkOrderApplicationService::class)->publish($sibling->id, $this->command($sibling), $f['user'], self::PERMISSIONS);
        $this->assertSame('RELEASED', $releasedSibling->status);
    }

    public function test_initial_child_technical_confirmation_rejects_changed_planning_facts_and_later_revision(): void
    {
        [$f, $child, $payload] = $this->technicalHoldFixture();
        $candidate = DB::transaction(fn () => app(WorkOrderTechnicalService::class)->prepare($child, $payload));
        $variants = [];
        $variant = $candidate; $variant['bom_id'] = $f['root_bom']->id; $variants[] = $variant;
        $variant = $candidate; $variant['production_routing_id'] = $f['root_routing']->id; $variants[] = $variant;
        $variant = $candidate; $variant['configuration_snapshot'] = ['cut_requirements' => [['length_mm' => 300]]]; $variants[] = $variant;
        $variant = $candidate; $variant['output_configuration'] = ['id' => 999]; $variants[] = $variant;
        $variant = $candidate; $variant['bom_snapshot']['items'][0]['qty'] = '99'; $variants[] = $variant;
        foreach ($variants as $snapshot) $this->expectCode('assembly_technical_facts_changed', fn () => DB::transaction(
            fn () => $this->service()->assertInitialChildTechnicalLocked($child, $snapshot)));
        $changedQuantity = clone $child; $changedQuantity->target_base_qty = 99;
        $this->expectCode('assembly_technical_facts_changed', fn () => DB::transaction(
            fn () => $this->service()->assertInitialChildTechnicalLocked($changedQuantity, $candidate)));
        $material = Item::findOrFail($candidate['materials'][0]['component_item_id']);
        $material->update(['manufacturing_strategy' => 'make']);
        $this->expectCode('assembly_technical_facts_changed', fn () => DB::transaction(
            fn () => $this->service()->assertInitialChildTechnicalLocked($child, $candidate)));
        $material->update(['manufacturing_strategy' => 'purchase']);
        app(WorkOrderApplicationService::class)->confirmTechnical($child->id, $payload, $f['user'], self::PERMISSIONS);
        $this->expectCode('assembly_plan_immutable', fn () => app(WorkOrderApplicationService::class)->confirmTechnical($child->id,
            array_replace($payload, $this->command($child), ['reason' => '第二次修订']), $f['user'], self::PERMISSIONS));
        $this->assertSame(2, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->count());
        $this->assertSame('1.0000', (string) $f['stock']->fresh()->quantity_locked);
    }

    public function test_child_first_confirmation_is_rejected_after_root_cancellation_or_publication(): void
    {
        [$f, $child, $payload] = $this->technicalHoldFixture();
        foreach (['CANCELLED', 'RELEASED'] as $status) {
            $f['wo']->fresh()->update(['status' => $status]);
            $this->expectCode('assembly_plan_immutable', fn () => app(WorkOrderApplicationService::class)->confirmTechnical($child->id,
                array_replace($payload, $this->command($child)), $f['user'], self::PERMISSIONS));
        }
        $this->assertSame(0, (int) $child->fresh()->technical_version);
    }

    public function test_legacy_prepared_command_can_replay_its_consumed_version_without_rebuilding(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '1'); $payload = $this->command($f['wo']);
        $first = $this->service()->prepare($f['wo']->id, $payload, $f['user'], self::PERMISSIONS);
        ProductionExecutionCommand::where('client_command_id', $payload['client_command_id'])->delete();
        $identity = ['work_order_id' => $f['wo']->id, 'expected_version' => $payload['expected_version'], 'actor' => $f['user']->legacy_id];
        ksort($identity);
        AssemblyProductionPlan::where('root_work_order_id', $f['wo']->id)->firstOrFail()->update([
            'request_hash' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
        $replay = $this->service()->prepare($f['wo']->id, $payload, $f['user'], self::PERMISSIONS);
        $this->assertSame($first['plan_id'], $replay['plan_id']);
        $this->assertSame(2, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->count());
        $this->assertSame('1.0000', (string) $stock->fresh()->quantity_locked);
    }

    public function test_legacy_released_child_uses_frozen_facts_without_new_buying_authority(): void
    {
        $f = $this->fixture(); $this->prepare($f);
        $child = WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->firstOrFail();
        $child = app(WorkOrderApplicationService::class)->publish($child->id, $this->command($child), $f['user'], self::PERMISSIONS);
        $this->assertTrue($child->materialRequirements()->exists());
        $actual = app(WorkOrderPreparationMaterialService::class);
        $projection = new class($actual, (int) $child->id) {
            public string $status = 'not_prepared';
            public function __construct(private $actual, private int $childId) {}
            public function projection(WorkOrder $wo): array {
                return (int) $wo->id === $this->childId
                    ? ['status' => $this->status, 'ready' => false, 'issues' => [['code' => $this->status, 'message' => 'Unavailable']], 'version' => null, 'rows' => []]
                    : $this->actual->projection($wo);
            }
        };
        $this->app->instance(WorkOrderPreparationMaterialService::class, $projection);
        $own = $this->service()->projection($child)['material_preparation'];
        $this->assertSame('historical_published', $own['status']);
        $this->assertTrue($own['ready']);
        $this->assertFalse($own['can_procure']);
        $this->assertSame([], $own['rows']);
        $this->assertTrue($this->service()->projection($f['wo']->fresh())['children_ready']);
        $projection->status = 'schema_not_ready';
        $this->assertFalse($this->service()->projection($child)['material_preparation']['ready']);
    }
    private function failMaterialSynchronization(\Throwable $failure): void
    {
        $actual = app(WorkOrderPreparationMaterialService::class);
        $this->app->instance(WorkOrderPreparationMaterialService::class, new class($actual, $failure) {
            public function __construct(private $actual, private \Throwable $failure) {}
            public function synchronizeLocked(WorkOrder $workOrder, object $user): array { throw $this->failure; }
            public function projection(WorkOrder $workOrder): array { return $this->actual->projection($workOrder); }
        });
    }

    private function technicalHoldFixture(): array
    {
        $f = $this->fixture(); $f['stock'] = $this->stock($f['frame'], '1');
        $line = $f['box_bom']->items()->firstOrFail();
        $item = Item::findOrFail($line->component_item_id); $item->update(['is_custom_item' => true]);
        $config = DB::table('erp_custom_configurations')->insertGetId(['item_id' => $item->id,
            'configuration_no' => $this->code('CFG'), 'version_no' => 1, 'dimensions' => json_encode(['width_mm' => 100]),
            'drawing_reference' => '首轮技术资料 R1', 'scope_mode' => 'PUBLIC', 'status' => 'PUBLISHED', 'business_version' => 1,
            'created_by_legacy_id' => $f['user']->legacy_id, 'created_at' => now(), 'updated_at' => now()]);
        $this->prepare($f);
        $child = WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->where('output_item_id', $f['box']->id)->firstOrFail();
        $payload = $this->command($child) + ['bom_id' => $f['box_bom']->id, 'production_routing_id' => $f['box_routing']->id,
            'materials' => [['bom_item_id' => $line->id, 'configuration_id' => $config]], 'drawing_reference' => '首轮技术资料 R1'];
        return [$f, $child, $payload];
    }

    private function salesDemand(array $f): ProductionDemand
    {
        $order = SalesOrder::create(['sales_order_no' => $this->code('SO'), 'customer_name' => '准备恢复客户',
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed',
            'total_amount' => 0, 'final_receivable_amount' => 0, 'funding_policy_snapshot' => ['policy_type' => 'full_prepay']]);
        $line = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'line_uuid' => (string) Str::uuid(),
            'line_type' => 'physical', 'item_id' => $f['machine']->id, 'order_qty' => 2, 'unit_id' => $f['unit']->id,
            'unit_name_snapshot' => '件', 'unit_price' => 0, 'amount' => 0, 'item_base_unit_id' => $f['unit']->id, 'item_base_required_qty' => 2]);
        return ProductionDemand::create(['requirement_no' => $this->code('D'), 'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id,
            'item_id' => $f['machine']->id, 'production_qty' => 2, 'base_unit_id' => $f['unit']->id, 'base_unit_name_snapshot' => '件',
            'item_base_required_qty' => 2, 'requirement_status' => 'ready', 'is_active' => true, 'requirement_version' => 1, 'business_version' => 1,
            'is_ready_for_work_order' => true, 'bom_id' => $f['root_bom']->id]);
    }

    private function fixture(): array
    {
        $actor = $this->actor(self::PERMISSIONS); $unit = Unit::create(['unit_code' => $this->code('U'), 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $metre = Unit::create(['unit_code' => $this->code('M'), 'unit_name' => '米', 'unit_type' => 'quantity', 'decimal_places' => 4, 'is_base' => true, 'status' => 'enabled']);
        $machine = $this->item($unit, '双滤一体机', 'unspecified'); $frame = $this->item($unit, '焊接架子', 'make'); $box = $this->item($unit, '电箱', 'make');
        $raw = $this->item($metre, '方管', 'purchase', ['item_type' => 'raw_material', 'is_production_item' => false,
            'is_length_cut_material' => true, 'cutting_mode' => 'length', 'standard_stock_length_mm' => 6000]);
        $plate = $this->item($unit, '电箱板件', 'purchase', ['item_type' => 'raw_material', 'is_production_item' => false]);
        $frameBom = $this->bom($frame, [[$raw, 2, 1000, 2], [$raw, 3, 1500, 2]]);
        $boxBom = $this->bom($box, [[$plate, 3, null, null]]);
        $rootBom = $this->bom($machine, [[$frame, 1, null, null], [$box, 1, null, null]]);
        [$rootRouting, $rootNode] = $this->routing($machine, [$frame, $box]);
        [$frameRouting] = $this->routing($frame, [$raw]); [$boxRouting] = $this->routing($box, [$plate]);
        $wo = $this->rootOrder($machine, $rootRouting, $rootNode, $actor['user']);
        return $actor + ['wo' => $wo, 'unit' => $unit, 'machine' => $machine, 'frame' => $frame, 'box' => $box, 'raw' => $raw, 'cut_count' => DB::table('erp_cutting_orders')->count(),
            'root_bom' => $rootBom, 'frame_bom' => $frameBom, 'box_bom' => $boxBom,
            'root_routing' => $rootRouting, 'root_node' => $rootNode, 'frame_routing' => $frameRouting, 'box_routing' => $boxRouting];
    }
    private function rootOrder(Item $item, ProductionRouting $routing, int $node, object $user): WorkOrder
    {
        return app(WorkOrderApplicationService::class)->createDraft(['client_command_id' => $this->code('CMD'), 'source_type' => 'stock_prebuild',
            'output_item_id' => $item->id, 'production_routing_id' => $routing->id, 'target_routing_operation_id' => $node,
            'stocking_purpose' => 'common_inventory', 'target_qty' => 2, 'responsible_user_legacy_id' => $user->legacy_id], $user, self::PERMISSIONS);
    }
    private function item(Unit $unit, string $name, string $strategy, array $extra = []): Item
    {
        return Item::create(array_replace(['item_code' => $this->code('I'), 'item_name' => $name, 'item_type' => 'finished_good',
            'unit_id' => $unit->id, 'is_purchase_item' => true, 'is_stock_item' => true, 'is_production_item' => true,
            'manufacturing_strategy' => $strategy, 'production_execution_mode' => 'quantity', 'status' => 'enabled'], $extra));
    }
    private function bom(Item $output, array $lines): Bom
    {
        $bom = Bom::create(['bom_no' => $this->code('B'), 'bom_name' => $output->item_name.'BOM', 'output_item_id' => $output->id,
            'bom_type' => 'standard', 'version' => 'V1.0', 'is_default' => true, 'status' => 'active', 'audit_status' => 'approved']);
        foreach ($lines as $i => [$item, $qty, $length, $pieces]) BomItem::create(['bom_id' => $bom->id, 'line_no' => ($i + 1) * 10,
            'component_item_id' => $item->id, 'component_item_code' => $item->item_code, 'component_item_name' => $item->item_name,
            'qty' => $qty, 'unit_id' => $item->unit_id, 'loss_rate' => 0, 'fixed_qty' => 0, 'cut_length_mm' => $length, 'piece_qty' => $pieces]);
        return $bom;
    }
    private function routing(Item $item, array $components): array
    {
        $operation = DB::table('erp_production_operations')->insertGetId(['operation_no' => $this->code('OP'), 'operation_name' => '焊接装配', 'status' => 'enabled', 'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $routing = ProductionRouting::create(['routing_no' => $this->code('RT'), 'routing_name' => $item->item_name.'路线', 'output_item_id' => $item->id,
            'version' => 1, 'status' => 'active', 'is_default' => true, 'default_scope_key' => (string) $item->id, 'business_version' => 1]);
        $node = DB::table('erp_production_routing_operations')->insertGetId(['routing_id' => $routing->id, 'operation_id' => $operation,
            'sequence' => 10, 'output_item_id' => $item->id, 'output_mode' => 'flow_only', 'quality_mode' => 'none',
            'allow_continue_without_warehouse' => true, 'is_key_operation' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($components as $component) DB::table('erp_routing_operation_material_supply_rules')->insert(['routing_operation_id' => $node,
            'component_item_id' => $component->id, 'target_routing_operation_id' => $node, 'required_qty_ratio' => 1,
            'supply_mode' => 'dedicated_delivery', 'requires_delivery' => true, 'participates_in_kitting' => true,
            'allow_partial_delivery' => false, 'delivery_location_type' => 'operation_station', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return [$routing, $node];
    }
    private function stock(Item $item, string $qty): InventoryBalance
    {
        $warehouse = DB::table('erp_warehouses')->insertGetId(['warehouse_code' => $this->code('WH'), 'warehouse_name' => '部件仓', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        $location = DB::table('erp_locations')->insertGetId(['warehouse_id' => $warehouse, 'location_code' => $this->code('LOC'), 'location_name' => '部件库位', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        $batch = $this->code('BATCH');
        DB::table('erp_inventory_batches')->insert(['item_id' => $item->id, 'batch_no' => $batch, 'warehouse_id' => $warehouse, 'location_id' => $location, 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        InventoryLocationBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse, 'location_id' => $location, 'unit_id' => $item->unit_id,
            'quantity_on_hand' => $qty, 'quantity_available' => $qty, 'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0]);
        return InventoryBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse, 'location_id' => $location, 'batch_no' => $batch,
            'unit_id' => $item->unit_id, 'quantity_on_hand' => $qty, 'quantity_available' => $qty, 'quantity_locked' => 0,
            'quantity_defective' => 0, 'quantity_pending' => 0, 'average_unit_cost' => 5]);
    }
    private function manualPick(WorkOrder $wo, object $requirement, InventoryBalance $stock, string $qty, string $status): MaterialPickingTask
    {
        $task = MaterialPickingTask::create(['task_no' => $this->code('PICK'), 'work_order_id' => $wo->id,
            'warehouse_id' => $stock->warehouse_id, 'production_location_name_snapshot' => '装配工位', 'status' => $status, 'business_version' => 1]);
        MaterialPickingTaskLine::create(['task_id' => $task->id, 'material_requirement_id' => $requirement->id,
            'component_item_id' => $stock->item_id, 'required_qty_snapshot' => $requirement->required_qty,
            'planned_pick_qty' => $qty, 'actual_pick_qty' => 0, 'unit_id' => $stock->unit_id,
            'inventory_balance_id' => $stock->id, 'warehouse_id' => $stock->warehouse_id,
            'location_id' => $stock->location_id, 'batch_no' => $stock->batch_no, 'status' => 'WAIT_PICK', 'business_version' => 1]);
        return $task;
    }
    private function preview(array $f): array { return $this->service()->preview($f['wo']->id, $f['user'], self::PERMISSIONS); }
    private function prepare(array $f): array { return $this->service()->prepare($f['wo']->id, $this->command($f['wo']), $f['user'], self::PERMISSIONS); }
    private function submit(array &$f): void { $f['wo'] = app(WorkOrderApplicationService::class)->submit($f['wo']->id, $this->command($f['wo']), $f['user'], self::PERMISSIONS); }
    private function gate(array $f): array { return app(ReleaseGateApplicationService::class)->evaluate($f['wo']->id, $f['user'], self::PERMISSIONS); }
    private function service(): AssemblyProductionApplicationService { return app(AssemblyProductionApplicationService::class); }
    private function command(WorkOrder $wo): array { return ['client_command_id' => $this->code('CMD'), 'expected_version' => (int) $wo->fresh()->business_version]; }
    private function code(string $prefix): string { return 'P1REC-'.$prefix.'-'.strtoupper(Str::random(10)); }
    private function expectCode(string $code, callable $run): void { try { $run(); $this->fail('预期业务阻断 '.$code); } catch (WorkOrderDomainException $e) { $this->assertSame($code, $e->errorCode, $e->getMessage()); } }
    private function actor(array $permissions, string $scope = 'all'): array
    {
        app(RbacBootstrapService::class)->bootstrap(); $id = random_int(6300000, 6399999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $id, 'username' => $this->code('USER'), 'nickname' => '装配计划测试人员',
            'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $role = DB::table('erp_rbac_roles')->insertGetId(['code' => $this->code('ROLE'), 'name' => '装配计划测试角色', 'data_scope' => $scope, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('erp_rbac_permissions')->whereIn('code', $permissions)->pluck('id') as $permission) DB::table('erp_rbac_role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $id, 'role_id' => $role]);
        $token = $this->code('TOKEN');
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        return ['user' => DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->first(), 'token' => $token];
    }
}
