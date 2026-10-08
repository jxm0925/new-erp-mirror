<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{AssemblyComponentDemand, AssemblyProductionPlan, Bom, BomItem, InventoryBalance, InventoryLocationBalance, Item, MaterialPickingTask, MaterialPickingTaskLine, ProductionDemand, ProductionRouting, SalesOrder, SalesOrderLine, Unit, WorkOrder};
use App\Services\Erp\{AssemblyProductionApplicationService, AssemblyProductionInventoryService, InventoryService, ProductionMasterDataService, ProductionPickingStockService, RbacBootstrapService, ReleaseGateApplicationService, WorkOrderApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssemblyProductionPlanTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = ['production.work_order.view', 'production.work_order.create', 'production.work_order.edit',
        'production.work_order.submit', 'production.work_order.publish', 'production.work_order.cancel',
        'production.work_order.gate.view', 'production.demand.view', 'production.technical.prepare', 'production.material.view'];

    public function test_preview_nets_stock_before_expanding_a_self_made_component_and_writes_nothing(): void
    {
        $f = $this->fixture(); $this->stock($f['frame'], '2');
        $before = WorkOrder::count();
        $plan = $this->preview($f);
        $frame = collect($plan['components'])->firstWhere('item_id', $f['frame']->id);
        $this->assertSame('2.00000000', $frame['required_base_qty']);
        $this->assertSame('2.00000000', $frame['inventory_reserved_base_qty']);
        $this->assertSame('0.00000000', $frame['production_base_qty']);
        $this->assertFalse(collect($plan['components'])->contains(fn ($row) => $row['parent_path'] === $frame['path_key']));
        $this->assertSame($before, WorkOrder::count());
        $this->assertSame(0, AssemblyProductionPlan::where('root_work_order_id', $f['wo']->id)->count());
        $this->assertFalse($plan['immutable']);
    }

    public function test_preparation_creates_only_shortages_and_preserves_each_child_bom_cut_length(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '1');
        $plan = $this->prepare($f);
        $frame = collect($plan['components'])->firstWhere('item_id', $f['frame']->id);
        $box = collect($plan['components'])->firstWhere('item_id', $f['box']->id);
        $this->assertSame('1.00000000', $frame['inventory_reserved_base_qty']);
        $this->assertSame('1.00000000', $frame['production_base_qty']);
        $this->assertSame('2.00000000', $box['production_base_qty']);
        $children = WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->get();
        $this->assertCount(2, $children);
        $this->assertSame('WAIT_RELEASE', $children->first()->status);
        $this->assertSame('warehouse_required', $children->first()->effective_output_mode_snapshot);
        $this->assertSame($f['wo']->id, $children->first()->assembly_parent_work_order_id);
        $cuts = collect($plan['components'])->where('parent_path', $frame['path_key'])->pluck('cut_length_mm')->all();
        $this->assertSame(['1000.00', '1500.00'], $cuts);
        $this->assertSame('1.0000', (string) $stock->fresh()->quantity_locked);
        $this->assertSame('0.0000', (string) $stock->fresh()->quantity_available);
        $this->assertSame($f['cut_count'], DB::table('erp_cutting_orders')->count());
        $this->assertSame(2, AssemblyComponentDemand::where('root_work_order_id', $f['wo']->id)->count());
        $this->assertSame(2, $f['wo']->fresh()->business_version);
    }

    public function test_repeated_commands_and_a_fresh_prepare_do_not_duplicate_children_or_stock_locks(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '1');
        $payload = $this->command($f['wo']);
        $first = $this->service()->prepare($f['wo']->id, $payload, $f['user'], self::PERMISSIONS);
        $repeat = $this->service()->prepare($f['wo']->id, $payload, $f['user'], self::PERMISSIONS);
        $new = $this->prepare($f);
        $this->assertSame($first['plan_id'], $repeat['plan_id']);
        $this->assertSame($first['plan_id'], $new['plan_id']);
        $this->assertSame(2, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->count());
        $this->assertSame('1.0000', (string) $stock->fresh()->quantity_locked);
        $this->assertSame(2, $f['wo']->fresh()->business_version);
        $other = $this->actor(self::PERMISSIONS);
        $this->expectCode('idempotency_conflict', fn () => $this->service()->prepare($f['wo']->id, $payload, $other['user'], self::PERMISSIONS));
    }

    public function test_two_roots_cannot_allocate_the_same_available_component_stock_twice(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '2');
        $first = $this->prepare($f);
        $secondWo = $this->rootOrder($f['machine'], $f['root_routing'], $f['root_node'], $f['user']);
        $second = $this->service()->prepare($secondWo->id, $this->command($secondWo), $f['user'], self::PERMISSIONS);
        $this->assertSame('2.00000000', collect($first['components'])->firstWhere('item_id', $f['frame']->id)['inventory_reserved_base_qty']);
        $this->assertSame('0.00000000', collect($second['components'])->firstWhere('item_id', $f['frame']->id)['inventory_reserved_base_qty']);
        $this->assertSame('2.00000000', collect($second['components'])->firstWhere('item_id', $f['frame']->id)['production_base_qty']);
        $this->assertSame('2.0000', (string) $stock->fresh()->quantity_locked);
    }

    public function test_purchase_strategy_does_not_create_children_even_when_an_effective_bom_exists(): void
    {
        $f = $this->fixture(); $f['frame']->update(['manufacturing_strategy' => 'purchase']); $f['box']->update(['manufacturing_strategy' => 'unspecified']);
        $plan = $this->prepare($f);
        $this->assertFalse($plan['required']);
        $this->assertSame('not_required', $plan['status']);
        $this->assertSame(0, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->count());
        $this->assertSame(0, AssemblyProductionPlan::where('root_work_order_id', $f['wo']->id)->count());
        $this->submit($f); $this->assertTrue($this->gate($f)['allowed']);
    }

    public function test_new_make_components_block_publication_until_preparation_but_old_boms_keep_their_gate(): void
    {
        $f = $this->fixture(); $this->submit($f);
        $this->assertContains('assembly_preparation_required', array_column($this->gate($f)['blockers'], 'reason_code'));
        $this->expectCode('release_gate_blocked', fn () => app(WorkOrderApplicationService::class)->publish($f['wo']->id, $this->command($f['wo']), $f['user'], self::PERMISSIONS));
        $this->prepare($f);
        $this->assertTrue($this->gate($f)['allowed'], json_encode($this->gate($f)['blockers'], JSON_UNESCAPED_UNICODE));
    }

    public function test_missing_child_route_or_cyclic_bom_rolls_back_every_allocation_and_child(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '1');
        DB::table('erp_production_routings')->where('id', $f['box_routing']->id)->update(['is_default' => false]);
        $this->expectCode('assembly_plan_blocked', fn () => $this->prepare($f));
        $this->assertSame('0.0000', (string) $stock->fresh()->quantity_locked);
        $this->assertSame(0, AssemblyComponentDemand::where('root_work_order_id', $f['wo']->id)->count());
        DB::table('erp_production_routings')->where('id', $f['box_routing']->id)->update(['is_default' => true]);
        $f['machine']->update(['manufacturing_strategy' => 'make']);
        BomItem::create(['bom_id' => $f['frame_bom']->id, 'line_no' => 30, 'component_item_id' => $f['machine']->id,
            'component_item_code' => $f['machine']->item_code, 'component_item_name' => $f['machine']->item_name,
            'qty' => 1, 'unit_id' => $f['unit']->id, 'loss_rate' => 0, 'fixed_qty' => 0]);
        $preview = $this->preview($f);
        $this->assertContains('assembly_bom_cycle', array_column($preview['issues'], 'code'));
        $this->expectCode('assembly_plan_blocked', fn () => $this->prepare($f));
        $this->assertSame(0, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->count());
    }

    public function test_nested_component_shortage_uses_the_parent_shortage_quantity(): void
    {
        $f = $this->fixture(); $this->stock($f['frame'], '1');
        $sub = $this->item($f['unit'], '支架', 'make');
        $this->bom($sub, [[$f['raw'], 3, null, null]]); $this->routing($sub, [$f['raw']]);
        BomItem::create(['bom_id' => $f['frame_bom']->id, 'line_no' => 30, 'component_item_id' => $sub->id,
            'component_item_code' => $sub->item_code, 'component_item_name' => $sub->item_name,
            'qty' => 2, 'unit_id' => $f['unit']->id, 'loss_rate' => 0, 'fixed_qty' => 0]);
        $plan = $this->prepare($f);
        $frame = collect($plan['components'])->firstWhere('item_id', $f['frame']->id);
        $subRow = collect($plan['components'])->firstWhere('item_id', $sub->id);
        $this->assertSame('2.00000000', $subRow['required_base_qty']);
        $this->assertSame($frame['child_work_order_id'], $subRow['parent_work_order_id']);
        $this->assertSame('2.00000000', (string) WorkOrder::find($subRow['child_work_order_id'])->target_base_qty);
    }

    public function test_prepared_snapshot_survives_master_labels_and_rejects_quantity_or_technical_changes(): void
    {
        $f = $this->fixture(); $first = $this->prepare($f); $f['frame']->update(['item_name' => '后改名称']);
        $this->assertSame('焊接架子', collect($this->preview($f)['components'])->firstWhere('item_id', $f['frame']->id)['item']['name']);
        $this->expectCode('assembly_plan_immutable', fn () => app(WorkOrderApplicationService::class)->updateDraft($f['wo']->id,
            $this->command($f['wo']) + ['target_qty' => 3], $f['user'], self::PERMISSIONS));
        $this->expectCode('assembly_plan_immutable', fn () => app(WorkOrderApplicationService::class)->confirmTechnical($f['wo']->id,
            $this->command($f['wo']) + ['bom_id' => $f['root_bom']->id, 'reason' => '改技术'], $f['user'], self::PERMISSIONS));
        $this->assertSame($first['plan_id'], $this->preview($f)['plan_id']);
    }

    public function test_master_bom_quantity_change_is_blocked_before_publication_and_preserves_prepared_facts(): void
    {
        $f = $this->fixture(); $this->prepare($f); $this->submit($f);
        $f['root_bom']->items()->first()->update(['qty' => 2]);
        $this->assertContains('assembly_plan_changed', array_column($this->gate($f)['blockers'], 'reason_code'));
        $this->assertSame('2.00000000', collect($this->preview($f)['components'])->firstWhere('item_id', $f['frame']->id)['required_base_qty']);
        $this->assertSame(0, $f['wo']->materialRequirements()->count());
    }

    public function test_root_cancellation_releases_all_component_stock_and_cancels_unpublished_children_once(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '1'); $this->prepare($f);
        $cancel = app(WorkOrderApplicationService::class)->cancel($f['wo']->id, $this->command($f['wo']) + ['reason' => '取消整机'], $f['user'], self::PERMISSIONS);
        $this->assertSame('CANCELLED', $cancel->status);
        $this->assertSame('0.0000', (string) $stock->fresh()->quantity_locked);
        $this->assertSame('1.0000', (string) $stock->fresh()->quantity_available);
        $this->assertSame(0, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->where('status', '<>', 'CANCELLED')->count());
        $this->assertSame('cancelled', $this->preview($f)['status']);
    }

    public function test_child_cannot_be_cancelled_independently_and_started_children_block_root_cancel(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '1'); $this->prepare($f);
        $child = WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->first();
        $this->expectCode('assembly_child_cancel_forbidden', fn () => app(WorkOrderApplicationService::class)->cancel($child->id,
            $this->command($child) + ['reason' => '单独取消'], $f['user'], self::PERMISSIONS));
        $child->update(['status' => 'RELEASED']);
        $this->expectCode('assembly_children_started', fn () => app(WorkOrderApplicationService::class)->cancel($f['wo']->id,
            $this->command($f['wo']) + ['reason' => '取消整机'], $f['user'], self::PERMISSIONS));
        $this->assertSame('1.0000', (string) $stock->fresh()->quantity_locked);
    }

    public function test_picking_only_uses_the_owner_reservation_and_stock_is_posted_once(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '2'); $this->prepare($f); $this->submit($f);
        $published = app(WorkOrderApplicationService::class)->publish($f['wo']->id, $this->command($f['wo']), $f['user'], self::PERMISSIONS);
        $requirement = $published->materialRequirements()->where('component_item_id', $f['frame']->id)->firstOrFail();
        $stockService = app(ProductionPickingStockService::class);
        $this->assertSame(0.0, $stockService->available($stock->fresh()));
        $this->assertSame(2.0, $stockService->available($stock->fresh(), null, [$requirement->id]));
        $task = MaterialPickingTask::create(['task_no' => $this->code('PICK'), 'work_order_id' => $published->id,
            'warehouse_id' => $stock->warehouse_id, 'production_location_name_snapshot' => '装配工位', 'status' => 'PICKING', 'business_version' => 1]);
        MaterialPickingTaskLine::create(['task_id' => $task->id, 'material_requirement_id' => $requirement->id,
            'component_item_id' => $f['frame']->id, 'required_qty_snapshot' => 2, 'planned_pick_qty' => 2, 'actual_pick_qty' => 2, 'unit_id' => $f['unit']->id,
            'inventory_balance_id' => $stock->id, 'warehouse_id' => $stock->warehouse_id, 'location_id' => $stock->location_id,
            'batch_no' => $stock->batch_no, 'status' => 'PICKED', 'business_version' => 1]);
        DB::transaction(function () use ($task, $f): void {
            app(AssemblyProductionInventoryService::class)->consumePicking($task->fresh('lines'));
            $first = app(InventoryService::class)->postProductionMaterialPicking($task, $f['user']);
            $again = app(InventoryService::class)->postProductionMaterialPicking($task, $f['user']);
            $this->assertSame($first->id, $again->id);
        });
        $this->assertSame('0.0000', (string) $stock->fresh()->quantity_on_hand);
        $this->assertSame('0.0000', (string) $stock->fresh()->quantity_locked);
        $this->assertSame('2.00000000', (string) DB::table('erp_assembly_inventory_reservations')->where('work_order_id', $published->id)->value('consumed_base_qty'));
    }

    public function test_automatic_sales_work_order_prepares_components_in_the_confirmation_transaction(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '1');
        $order = SalesOrder::create(['sales_order_no' => $this->code('SO'), 'customer_name' => '整机客户',
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed',
            'total_amount' => 0, 'final_receivable_amount' => 0, 'funding_policy_snapshot' => ['policy_type' => 'full_prepay']]);
        $line = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'line_uuid' => (string) Str::uuid(),
            'line_type' => 'physical', 'item_id' => $f['machine']->id, 'order_qty' => 2, 'unit_id' => $f['unit']->id,
            'unit_name_snapshot' => '件', 'unit_price' => 0, 'amount' => 0, 'item_base_unit_id' => $f['unit']->id, 'item_base_required_qty' => 2]);
        $demand = ProductionDemand::create(['requirement_no' => $this->code('D'), 'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id,
            'item_id' => $f['machine']->id, 'production_qty' => 2, 'base_unit_id' => $f['unit']->id, 'base_unit_name_snapshot' => '件',
            'item_base_required_qty' => 2, 'requirement_status' => 'ready', 'is_active' => true, 'requirement_version' => 1, 'business_version' => 1,
            'is_ready_for_work_order' => true, 'bom_id' => $f['root_bom']->id]);
        $created = DB::transaction(fn () => app(WorkOrderApplicationService::class)->ensureAutomaticSalesDraft($demand, $f['user']));
        $again = DB::transaction(fn () => app(WorkOrderApplicationService::class)->ensureAutomaticSalesDraft($demand, $f['user']));
        $this->assertSame($created->id, $again->id);
        $this->assertSame('WAIT_RELEASE', $created->status);
        $this->assertSame(2, WorkOrder::where('assembly_root_work_order_id', $created->id)->count());
        $this->assertSame('1.0000', (string) $stock->fresh()->quantity_locked);
        $this->assertSame('PREPARED', AssemblyProductionPlan::where('root_work_order_id', $created->id)->value('status'));
    }

    public function test_api_requires_auth_permission_scope_and_rejects_stale_versions(): void
    {
        $f = $this->fixture(); $url = '/api/v1/erp/production/work-orders/'.$f['wo']->id.'/assembly-plan-preview';
        $this->getJson($url)->assertUnauthorized();
        $this->withToken($f['token'])->getJson($url)->assertOk()->assertJsonPath('data.required', true);
        $this->expectCode('permission_denied', fn () => $this->service()->preview($f['wo']->id, $f['user'], [], true));
        $other = $this->actor(['production.work_order.view'], 'self');
        $this->withToken($other['token'])->getJson($url)->assertForbidden();
        $this->withToken($f['token'])->postJson(str_replace('assembly-plan-preview', 'prepare-assembly', $url),
            ['client_command_id' => $this->code('CMD'), 'expected_version' => 99])->assertConflict();
        $this->assertSame(0, AssemblyProductionPlan::where('root_work_order_id', $f['wo']->id)->count());
    }

    public function test_a_child_viewer_sees_only_its_downstream_bom_without_root_or_sibling_access(): void
    {
        $f = $this->fixture();
        $sub = $this->item($f['unit'], '架子内支架', 'make');
        $this->bom($sub, [[$f['raw'], 3, null, null]]); $this->routing($sub, [$f['raw']]);
        BomItem::create(['bom_id' => $f['frame_bom']->id, 'line_no' => 30, 'component_item_id' => $sub->id,
            'component_item_code' => $sub->item_code, 'component_item_name' => $sub->item_name,
            'qty' => 2, 'unit_id' => $f['unit']->id, 'loss_rate' => 0, 'fixed_qty' => 0]);
        $prepared = $this->prepare($f);
        $frame = collect($prepared['components'])->firstWhere('item_id', $f['frame']->id);
        $box = collect($prepared['components'])->firstWhere('item_id', $f['box']->id);
        $viewer = $this->actor(['production.work_order.view'], 'self');
        WorkOrder::whereKey($frame['child_work_order_id'])->update(['responsible_user_legacy_id' => $viewer['user']->legacy_id]);
        $base = '/api/v1/erp/production/work-orders/';
        $this->withToken($viewer['token'])->getJson($base.$f['wo']->id.'/assembly-plan-preview')->assertForbidden();
        $this->getJson($base.$box['child_work_order_id'].'/assembly-plan-preview')->assertForbidden();
        $response = $this->getJson($base.$frame['child_work_order_id'].'/assembly-plan-preview')
            ->assertOk()->assertJsonPath('data.work_order_id', $frame['child_work_order_id'])
            ->assertJsonPath('data.root_work_order_id', $f['wo']->id)->assertJsonCount(4, 'data.components');
        $itemIds = array_column($response->json('data.components'), 'item_id');
        $this->assertContains($sub->id, $itemIds);
        $this->assertContains($f['raw']->id, $itemIds);
        $this->assertNotContains($f['frame']->id, $itemIds);
        $this->assertNotContains($f['box']->id, $itemIds);
        $record = AssemblyProductionPlan::where('root_work_order_id', $f['wo']->id)->firstOrFail();
        $this->assertCount(count($prepared['components']), $record->plan_snapshot['components']);
        $this->assertSame(count($prepared['components']), count($this->preview($f)['components']));
    }

    public function test_one_balance_used_at_multiple_bom_levels_is_netted_once(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '1');
        BomItem::create(['bom_id' => $f['box_bom']->id, 'line_no' => 20, 'component_item_id' => $f['frame']->id,
            'component_item_code' => $f['frame']->item_code, 'component_item_name' => $f['frame']->item_name,
            'qty' => 1, 'unit_id' => $f['unit']->id, 'loss_rate' => 0, 'fixed_qty' => 0]);
        $preview = $this->preview($f);
        $frames = collect($preview['components'])->where('item_id', $f['frame']->id)->values();
        $this->assertCount(2, $frames);
        $this->assertSame(['1.00000000', '0.00000000'], $frames->pluck('inventory_reserved_base_qty')->all());
        $this->assertSame(['1.00000000', '2.00000000'], $frames->pluck('production_base_qty')->all());
        $plan = $this->prepare($f);
        $this->assertSame('1.0000', (string) $stock->fresh()->quantity_locked);
        $this->assertSame(3, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->count());
        $this->assertSame('1.00000000', (string) DB::table('erp_assembly_inventory_reservations')->where('inventory_balance_id', $stock->id)->sum('reserved_base_qty'));
    }

    public function test_exact_netting_preserves_the_four_decimal_tail_of_large_stock_quantities(): void
    {
        $f = $this->fixture(); $f['unit']->update(['decimal_places' => 4]);
        $f['root_bom']->items()->where('component_item_id', $f['frame']->id)->update(['qty' => '500000000.0000']);
        $f['frame_bom']->items()->update(['cut_length_mm' => null, 'piece_qty' => null]);
        $this->stock($f['frame'], '999999999.9999');
        $frame = collect($this->preview($f)['components'])->firstWhere('item_id', $f['frame']->id);
        $this->assertSame('1000000000.00000000', $frame['required_base_qty']);
        $this->assertSame('999999999.99990000', $frame['inventory_reserved_base_qty']);
        $this->assertSame('0.00010000', $frame['production_base_qty']);
    }

    public function test_quantity_beyond_inventory_ledger_precision_is_blocked_without_rounding(): void
    {
        $f = $this->fixture(); $f['unit']->update(['decimal_places' => 8]);
        $f['wo']->update(['target_qty' => '0.5', 'target_base_qty' => '0.5']);
        $f['root_bom']->items()->where('component_item_id', $f['frame']->id)->update(['qty' => '0.0001']);
        $plan = $this->preview($f);
        $this->assertSame('0.00005000', collect($plan['components'])->firstWhere('item_id', $f['frame']->id)['required_base_qty']);
        $this->assertContains('assembly_component_unit_invalid', array_column($plan['issues'], 'code'));
        $this->expectCode('assembly_plan_blocked', fn () => $this->prepare($f));
        $this->assertSame(0, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->count());
    }

    public function test_an_oversized_child_shortage_is_blocked_before_work_order_or_stock_writes(): void
    {
        $f = $this->fixture(); $boxStock = $this->stock($f['box'], '1');
        $f['root_bom']->items()->where('component_item_id', $f['frame']->id)->update(['qty' => '5000000000.0000']);
        $plan = $this->preview($f);
        $issue = collect($plan['issues'])->firstWhere('code', 'assembly_component_quantity_capacity');
        $this->assertNotNull($issue);
        $this->assertSame('10000000000.00000000', $issue['required_base_qty']);
        $this->assertSame('9999999999.99999999', $issue['maximum_material_requirement_qty']);
        $this->expectCode('assembly_plan_blocked', fn () => $this->prepare($f));
        $this->assertSame(0, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->count());
        $this->assertSame(0, AssemblyComponentDemand::where('root_work_order_id', $f['wo']->id)->count());
        $this->assertSame(0, AssemblyProductionPlan::where('root_work_order_id', $f['wo']->id)->count());
        $this->assertSame('0.0000', (string) $boxStock->fresh()->quantity_locked);
        $this->assertSame(1, $f['wo']->fresh()->business_version);
    }

    public function test_multiple_stock_balances_cannot_hide_an_unrecordable_gross_material_requirement(): void
    {
        $f = $this->fixture();
        $first = $this->stock($f['frame'], '5000000000'); $second = $this->stock($f['frame'], '5000000000');
        $f['root_bom']->items()->where('component_item_id', $f['frame']->id)->update(['qty' => '5000000000.0000']);
        $this->assertSame('10000000000.00000000', bcadd((string) $first->quantity_available, (string) $second->quantity_available, 8));
        $plan = $this->preview($f);
        $frame = collect($plan['components'])->firstWhere('item_id', $f['frame']->id);
        $this->assertSame('10000000000.00000000', $frame['required_base_qty']);
        $this->assertContains('assembly_component_quantity_capacity', array_column($plan['issues'], 'code'));
        $this->expectCode('assembly_plan_blocked', fn () => $this->prepare($f));
        $this->assertSame('0.0000', (string) $first->fresh()->quantity_locked);
        $this->assertSame('0.0000', (string) $second->fresh()->quantity_locked);
        $this->assertSame(0, AssemblyComponentDemand::where('root_work_order_id', $f['wo']->id)->count());
        $this->assertSame(0, AssemblyProductionPlan::where('root_work_order_id', $f['wo']->id)->count());
        $this->assertSame(0, WorkOrder::where('assembly_root_work_order_id', $f['wo']->id)->count());
    }

    public function test_a_single_bom_level_over_500_components_is_blocked_without_partial_preparation(): void
    {
        $f = $this->fixture(); $frameStock = $this->stock($f['frame'], '2'); $boxStock = $this->stock($f['box'], '2');
        // Distinct purchased components keep this a valid wide BOM. Both made
        // components are stocked, so the row guard must also cover no recursion.
        $items = [];
        for ($i = 0; $i < 499; $i++) $items[] = ['item_code' => $this->code('FASTENER'), 'item_name' => '紧固件'.($i + 1),
            'item_type' => 'raw_material', 'unit_id' => $f['unit']->id, 'is_purchase_item' => true,
            'is_stock_item' => true, 'is_production_item' => false, 'manufacturing_strategy' => 'purchase',
            'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()];
        DB::table('erp_items')->insert($items);
        $bomLines = [];
        foreach (Item::whereIn('item_code', array_column($items, 'item_code'))->orderBy('id')->get() as $i => $item) {
            $bomLines[] = ['bom_id' => $f['root_bom']->id, 'line_no' => ($i + 3) * 10,
                'component_item_id' => $item->id, 'component_item_code' => $item->item_code, 'component_item_name' => $item->item_name,
                'qty' => 1, 'unit_id' => $f['unit']->id, 'loss_rate' => 0, 'fixed_qty' => 0,
                'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('erp_bom_items')->insert($bomLines);
        $plan = $this->preview($f);
        $this->assertCount(500, $plan['components']);
        $this->assertContains('assembly_tree_limit', array_column($plan['issues'], 'code'));
        $this->expectCode('assembly_plan_blocked', fn () => $this->prepare($f));
        $this->assertSame(0, AssemblyProductionPlan::where('root_work_order_id', $f['wo']->id)->count());
        $this->assertSame(0, AssemblyComponentDemand::where('root_work_order_id', $f['wo']->id)->count());
        $this->assertSame('0.0000', (string) $frameStock->fresh()->quantity_locked);
        $this->assertSame('0.0000', (string) $boxStock->fresh()->quantity_locked);
    }

    public function test_safe_stock_and_pending_picking_are_deducted_from_cached_available_stock(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '5');
        $stock->update(['quantity_locked' => 1, 'quantity_defective' => 1, 'quantity_pending' => 1, 'quantity_available' => 5]);
        InventoryLocationBalance::where('item_id', $f['frame']->id)->where('location_id', $stock->location_id)
            ->update(['quantity_locked' => 1, 'quantity_defective' => 1, 'quantity_pending' => 1, 'quantity_available' => 5]);
        $f['frame']->update(['manufacturing_strategy' => 'purchase']); $f['box']->update(['manufacturing_strategy' => 'purchase']);
        $other = $this->rootOrder($f['machine'], $f['root_routing'], $f['root_node'], $f['user']);
        $other = app(WorkOrderApplicationService::class)->submit($other->id, $this->command($other), $f['user'], self::PERMISSIONS);
        $other = app(WorkOrderApplicationService::class)->publish($other->id, $this->command($other), $f['user'], self::PERMISSIONS);
        $req = $other->materialRequirements()->where('component_item_id', $f['frame']->id)->firstOrFail();
        $this->manualPick($other, $req, $stock, '1', 'WAIT_PICK');
        $f['frame']->update(['manufacturing_strategy' => 'make']); $f['box']->update(['manufacturing_strategy' => 'make']);
        $expired = $this->stock($f['frame'], '20');
        DB::table('erp_inventory_batches')->where('item_id', $f['frame']->id)->where('batch_no', $expired->batch_no)->update(['expire_date' => now()->subDay()->toDateString()]);
        $frame = collect($this->prepare($f)['components'])->firstWhere('item_id', $f['frame']->id);
        $this->assertSame('1.00000000', $frame['inventory_reserved_base_qty']);
        $this->assertSame('1.00000000', $frame['production_base_qty']);
        $this->assertSame('2.0000', (string) $stock->fresh()->quantity_locked);
        $this->assertSame('0.0000', (string) $expired->fresh()->quantity_locked);
    }

    public function test_owned_pending_picks_are_not_deducted_twice_from_public_stock(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '4'); $this->prepare($f); $this->submit($f);
        $published = app(WorkOrderApplicationService::class)->publish($f['wo']->id, $this->command($f['wo']), $f['user'], self::PERMISSIONS);
        $req = $published->materialRequirements()->where('component_item_id', $f['frame']->id)->firstOrFail();
        $this->manualPick($published, $req, $stock, '1', 'WAIT_PICK');
        $this->assertSame('2.00000000', app(ProductionPickingStockService::class)->availableDecimal($stock->fresh()));
        $next = $this->rootOrder($f['machine'], $f['root_routing'], $f['root_node'], $f['user']);
        $plan = $this->service()->prepare($next->id, $this->command($next), $f['user'], self::PERMISSIONS);
        $this->assertSame('2.00000000', collect($plan['components'])->firstWhere('item_id', $f['frame']->id)['inventory_reserved_base_qty']);
        $this->assertSame('4.0000', (string) $stock->fresh()->quantity_locked);
    }

    public function test_quality_hold_after_preparation_cannot_be_bypassed_with_owner_quantity(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '2'); $this->prepare($f); $this->submit($f);
        $published = app(WorkOrderApplicationService::class)->publish($f['wo']->id, $this->command($f['wo']), $f['user'], self::PERMISSIONS);
        $req = $published->materialRequirements()->where('component_item_id', $f['frame']->id)->firstOrFail();
        $stock->update(['quantity_defective' => 1]);
        $this->assertSame('1.00000000', app(ProductionPickingStockService::class)->availableDecimal($stock->fresh(), null, [$req->id]));
        $other = $this->stock($f['frame'], '10');
        $this->assertSame('0.00000000', app(ProductionPickingStockService::class)->availableDecimal($other->fresh(), null, [$req->id]));
    }

    public function test_inventory_unit_mismatch_and_later_component_unit_changes_are_blocked(): void
    {
        $f = $this->fixture(); $stock = $this->stock($f['frame'], '2');
        $other = Unit::create(['unit_code' => $this->code('U'), 'unit_name' => '箱', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $stock->update(['unit_id' => $other->id]);
        $this->assertContains('assembly_stock_unit_mismatch', array_column($this->preview($f)['issues'], 'code'));
        $this->expectCode('assembly_plan_blocked', fn () => $this->prepare($f));
        $stock->update(['unit_id' => $f['unit']->id]); $this->prepare($f); $this->submit($f);
        $f['frame']->update(['unit_id' => $other->id]);
        $this->assertContains('assembly_plan_changed', array_column($this->gate($f)['blockers'], 'reason_code'));
        $this->assertSame('件', collect($this->preview($f)['components'])->firstWhere('item_id', $f['frame']->id)['item']['base_unit_name']);
    }

    public function test_child_finished_goods_receipt_reserves_the_unpublished_parent_in_the_same_transaction(): void
    {
        $f = $this->fixture(); $plan = $this->prepare($f);
        $frame = collect($plan['components'])->firstWhere('item_id', $f['frame']->id);
        $child = WorkOrder::findOrFail($frame['child_work_order_id']);
        $child = app(WorkOrderApplicationService::class)->publish($child->id, $this->command($child), $f['user'], self::PERMISSIONS);
        $target = $child->quantityOperations()->firstOrFail();
        // The completed report/target is fixture input. Completion approval and warehouse receipt
        // use the real application services below; no stock or reservation is inserted as evidence.
        $target->update(['status' => 'COMPLETED', 'completed_base_qty' => 2, 'remaining_base_qty' => 0, 'completed_at' => now()]);
        $output = \App\Models\Erp\ProductionOutputRecord::create(['output_no' => $this->code('OUT'), 'work_order_id' => $child->id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => $target->id,
            'output_item_id' => $f['frame']->id, 'output_base_qty' => 2, 'output_mode_snapshot' => 'warehouse_required',
            'quality_mode_snapshot' => 'none', 'status' => 'WAIT_COMPLETION', 'produced_at' => now(),
            'created_by_legacy_id' => $f['user']->legacy_id, 'business_version' => 1]);
        $completions = app(\App\Services\Erp\WorkOrderCompletionService::class);
        $submitted = $completions->submit($child->id, $this->command($child) + ['output_record_ids' => [$output->id]], $f['user'], ['production.completion.create'], true);
        $completions->review($submitted['completion_id'], ['client_command_id' => $this->code('CMD'), 'expected_version' => 1, 'decision' => 'approve'], $f['user'], ['production.completion.review'], true);
        $emptyStock = $this->stock($f['frame'], '0');
        $payload = ['client_command_id' => $this->code('CMD'), 'expected_version' => $output->fresh()->business_version,
            'warehouse_id' => $emptyStock->warehouse_id, 'location_id' => $emptyStock->location_id,
            'batch_no' => $this->code('FGR'), 'posted_base_qty' => 2];
        $posted = app(\App\Services\Erp\ProductionOutputService::class)->warehouse($output->id, $payload, $f['user'], ['production.output.warehouse']);
        $again = app(\App\Services\Erp\ProductionOutputService::class)->warehouse($output->id, $payload, $f['user'], ['production.output.warehouse']);
        $this->assertSame($posted['finished_goods_receipt_id'], $again['finished_goods_receipt_id']);
        $balance = InventoryBalance::where('item_id', $f['frame']->id)->where('batch_no', $payload['batch_no'])->firstOrFail();
        $this->assertSame('2.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('2.0000', (string) $balance->quantity_locked);
        $this->assertSame('0.0000', (string) $balance->quantity_available);
        $this->assertSame('DRAFT', $f['wo']->fresh()->status);
        $reservation = DB::table('erp_assembly_inventory_reservations')->where('finished_goods_receipt_id', $posted['finished_goods_receipt_id'])->first();
        $this->assertSame($f['wo']->id, (int) $reservation->work_order_id);
        $this->assertNull($reservation->material_requirement_id);
        $this->assertSame('2.00000000', (string) $reservation->reserved_base_qty);
        $this->submit($f);
        $parent = app(WorkOrderApplicationService::class)->publish($f['wo']->id, $this->command($f['wo']), $f['user'], self::PERMISSIONS);
        $req = $parent->materialRequirements()->where('component_item_id', $f['frame']->id)->firstOrFail();
        $this->assertSame((int) $req->id, (int) DB::table('erp_assembly_inventory_reservations')->where('id', $reservation->id)->value('material_requirement_id'));
        $this->assertSame('2.00000000', app(ProductionPickingStockService::class)->availableDecimal($balance->fresh(), null, [$req->id]));
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
    private function code(string $prefix): string { return 'ASM-'.$prefix.'-'.strtoupper(Str::random(10)); }
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
