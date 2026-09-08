<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{Bom, BomItem, InventoryBalance, InventoryBatch, Item, Location, Product, ProductionDemand, ProductionOutputRecord, ProductionTask, SalesOrder, SalesOrderFulfillment, SalesOrderLine, Sku, Unit, Warehouse};
use App\Services\Erp\{ProductionExecutionActionService, ProductionOutputService, ProductionTaskAssignmentService, RbacBootstrapService, SalesOrderFulfillmentApplicationService, SalesOrderInventoryLockService, SalesShipmentApplicationService, WorkOrderApplicationService, WorkOrderCompletionService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** One uninterrupted business fixture from a confirmed order to factual shipment. */
class SalesToProductionFullFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_partial_stock_order_runs_through_demand_work_order_execution_warehouse_reservation_and_shipment(): void
    {
        $f = $this->fixture();
        $user = (object) ['legacy_id' => 9901, 'username' => 'full-flow', 'nickname' => '全流程测试员'];

        $locked = app(SalesOrderInventoryLockService::class)->lock($f['order']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
        ], $user, ['sales_order.inventory_lock']);
        $this->assertSame(4.0, (float) $locked['totals']['locked_inventory_qty']);
        $this->assertSame(6.0, (float) $locked['totals']['pending_production_qty']);

        app(SalesOrderFulfillmentApplicationService::class)->confirmProduction($f['order']->id, [[
            'sales_order_line_id' => $f['line']->id, 'confirm_qty' => 6,
            'inventory_qty' => 0, 'production_qty' => 6, 'service_qty' => 0, 'no_delivery_qty' => 0,
        ]], '锁库存后确认剩余生产需求', $user->nickname);
        $demand = ProductionDemand::query()->where('sales_order_line_id', $f['line']->id)->where('is_active', true)->firstOrFail();
        $this->assertSame(6.0, (float) $demand->production_qty);
        $this->assertSame('ready', $demand->requirement_status);

        $workOrders = app(WorkOrderApplicationService::class);
        $draft = $workOrders->createDraft([
            'client_command_id' => (string) Str::uuid(), 'source_type' => 'sales_order',
            'production_demand_id' => $demand->id, 'expected_demand_version' => $demand->business_version,
            'target_qty' => 6, 'planned_date' => now()->addDay()->toDateString(),
            'responsible_user_legacy_id' => $user->legacy_id, 'production_location_name' => '全流程装配区',
        ], $user, ['production.work_order.create'], true);
        $waiting = $workOrders->submit($draft->id, ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1, 'reason' => '全流程发布'], $user,
            ['production.work_order.submit'], true);
        $released = $workOrders->publish($waiting->id, ['client_command_id' => (string) Str::uuid(), 'expected_version' => 2, 'reason' => '全流程发布'], $user,
            ['production.work_order.publish', 'production.work_order.gate.view', 'production.material.view'], true);
        $this->assertSame('RELEASED', $released->status);

        $task = ProductionTask::query()->where('work_order_id', $released->id)->firstOrFail();
        $link = $task->targets()->firstOrFail();
        $claimed = app(ProductionTaskAssignmentService::class)->claim($task->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
        ], $user, ['production.task.claim']);
        $this->assertSame('READY', $claimed['targets'][0]['status']);
        $targetVersion = (int) DB::table('erp_production_quantity_operations')->where('id', $link->target_id)->value('business_version');
        $actions = app(ProductionExecutionActionService::class);
        $started = $actions->start($task->id, 'quantity_operation', $link->target_id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $targetVersion,
        ], $user, ['production.task.start']);
        $remaining = (float) DB::table('erp_production_quantity_operations')->where('id', $link->target_id)->value('remaining_base_qty');
        $this->assertSame(6.0, $remaining);
        $completed = $actions->complete($task->id, 'quantity_operation', $link->target_id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $started['target_business_version'],
            'completed_base_qty' => $remaining, 'scrapped_base_qty' => 0, 'disposition' => 'warehouse',
        ], $user, ['production.task.complete']);
        $this->assertSame('COMPLETED', $completed['target_status']);
        $output = ProductionOutputRecord::findOrFail($completed['output_record_id']);
        $this->assertSame('WAIT_COMPLETION', $output->status);

        $completionService = app(WorkOrderCompletionService::class);
        $completion = $completionService->submit($released->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $released->fresh()->business_version,
            'output_record_ids' => [$output->id], 'remark' => '全流程完工申报',
        ], $user, ['production.completion.create', 'production.work_order.view.all'], true);
        $completion = $completionService->review($completion['completion_id'], [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $completion['business_version'],
            'decision' => 'approve',
        ], $user, ['production.completion.review', 'production.work_order.view.all'], true);
        $this->assertSame('APPROVED', $completion['status']);
        $this->assertSame('COMPLETED', $released->fresh()->status);
        $output->refresh();
        $this->assertSame('WAIT_WAREHOUSE', $output->status);

        $posted = app(ProductionOutputService::class)->warehouse($output->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $output->business_version,
            'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
            'batch_no' => 'FULL-PROD-'.$f['suffix'], 'unit_cost' => 15,
        ], $user, ['production.output.warehouse']);
        $this->assertNotNull($posted['sales_order_reservation_id']);
        $this->assertNotNull($posted['finished_goods_receipt_id']);
        $this->assertSame(10.0, (float) $f['line']->fresh()->inventory_fulfilled_qty + (float) $f['line']->fresh()->production_required_qty);
        $this->assertSame(6.0, (float) $f['line']->fresh()->production_replenished_qty);
        $this->assertSame(10.0, (float) InventoryBalance::query()->where('item_id', $f['item']->id)->sum('quantity_locked'));

        $fulfillments = SalesOrderFulfillment::query()->where('sales_order_id', $f['order']->id)->where('demand_status', 'confirmed')->get();
        $this->assertEqualsCanonicalizing(['inventory', 'production'], $fulfillments->pluck('fulfillment_type')->all());
        $shipments = app(SalesShipmentApplicationService::class);
        $shipment = $shipments->create($f['order']->id, [
            'lines' => $fulfillments->map(fn ($row) => ['sales_order_fulfillment_id' => $row->id, 'base_qty' => (float) $row->item_base_qty])->all(),
        ], $user->nickname);
        $shipment = $shipments->confirm($shipment, $user->nickname);
        $shipments->postOutbound($shipment, $user->nickname);
        $this->assertSame(0.0, (float) InventoryBalance::query()->where('item_id', $f['item']->id)->sum('quantity_on_hand'));
        $this->assertSame(0.0, (float) InventoryBalance::query()->where('item_id', $f['item']->id)->sum('quantity_locked'));
        $this->assertSame(10.0, (float) $f['line']->fresh()->shipped_qty);
    }

    private function fixture(): array
    {
        $suffix = Str::upper(Str::random(8));
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => 9901, 'username' => 'flow-'.$suffix, 'nickname' => '全流程测试员',
            'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        app(RbacBootstrapService::class)->bootstrap(true);
        DB::table('erp_rbac_user_roles')->insertOrIgnore(['user_legacy_id' => 9901,
            'role_id' => DB::table('erp_rbac_roles')->where('code', 'production_manager')->value('id')]);
        $unit = Unit::create(['unit_code' => 'EA-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $product = Product::create(['product_code' => 'P-'.$suffix, 'product_name' => '全流程产品', 'product_type' => 'standard', 'status' => 'enabled']);
        $sku = Sku::create(['product_id' => $product->id, 'sales_unit_id' => $unit->id, 'sku_code' => 'S-'.$suffix, 'sku_name' => '全流程规格', 'order_line_type' => 'physical', 'fulfillment_type' => 'physical', 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'FG-'.$suffix, 'item_name' => '全流程成品', 'item_type' => 'finished_good', 'unit_id' => $unit->id,
            'is_stock_item' => true, 'is_production_item' => true, 'production_execution_mode' => 'quantity', 'status' => 'enabled']);
        $component = Item::create(['item_code' => 'RM-'.$suffix, 'item_name' => '全流程常备辅料', 'item_type' => 'raw_material', 'unit_id' => $unit->id,
            'is_stock_item' => true, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => 'WH-'.$suffix, 'warehouse_name' => '全流程成品仓', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => 'LOC-'.$suffix, 'location_name' => '全流程成品库位', 'status' => 'enabled']);
        InventoryBatch::create(['item_id' => $item->id, 'batch_no' => 'OPEN-'.$suffix, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'status' => 'enabled']);
        InventoryBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => 'OPEN-'.$suffix,
            'unit_id' => $unit->id, 'quantity_on_hand' => 4, 'quantity_locked' => 0, 'quantity_available' => 4, 'quantity_defective' => 0, 'quantity_pending' => 0]);
        $bom = Bom::create(['bom_no' => 'BOM-'.$suffix, 'bom_name' => '全流程 BOM', 'product_id' => $product->id, 'sku_id' => $sku->id,
            'output_item_id' => $item->id, 'bom_type' => 'standard', 'version' => 'V1.0', 'is_default' => true,
            'status' => 'active', 'audit_status' => 'approved', 'effective_date' => now()->subDay()->toDateString()]);
        BomItem::create(['bom_id' => $bom->id, 'line_no' => 10, 'component_item_id' => $component->id,
            'component_item_code' => $component->item_code, 'component_item_name' => $component->item_name,
            'qty' => 0, 'fixed_qty' => 1, 'loss_rate' => 0, 'unit_id' => $unit->id, 'replaceable' => false]);
        $operationId = DB::table('erp_production_operations')->insertGetId(['operation_no' => 'OP-'.$suffix, 'operation_name' => '全流程总装',
            'status' => 'enabled', 'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $routingId = DB::table('erp_production_routings')->insertGetId(['routing_no' => 'RT-'.$suffix, 'routing_name' => '全流程默认路线',
            'output_item_id' => $item->id, 'version' => 1, 'status' => 'active', 'is_default' => true,
            'default_scope_key' => $item->id, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $routingOperationId = DB::table('erp_production_routing_operations')->insertGetId(['routing_id' => $routingId, 'operation_id' => $operationId, 'sequence' => 10,
            'is_key_operation' => true, 'output_item_id' => $item->id, 'output_mode' => 'warehouse_required', 'quality_mode' => 'none',
            'allow_continue_without_warehouse' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_routing_operation_material_supply_rules')->insert(['routing_operation_id' => $routingOperationId,
            'component_item_id' => $component->id, 'target_routing_operation_id' => $routingOperationId,
            'required_qty_ratio' => 1, 'supply_mode' => 'workstation_stock', 'requires_delivery' => false,
            'participates_in_kitting' => false, 'allow_partial_delivery' => true, 'delivery_location_type' => 'operation_station',
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $order = SalesOrder::create(['sales_order_no' => 'SO-'.$suffix, 'customer_name' => '全流程客户', 'order_status' => 'confirmed',
            'confirm_status' => 'confirmed', 'production_confirm_status' => 'pending', 'shipment_status' => 'not_shipped',
            'sales_user_legacy_id' => 9901, 'created_by_legacy_id' => 9901, 'total_amount' => 0, 'final_receivable_amount' => 0,
            'business_version' => 1]);
        $line = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'line_uuid' => 'L-'.$suffix, 'line_type' => 'physical',
            'product_id' => $product->id, 'product_name' => $product->product_name, 'sku_id' => $sku->id, 'sku_name' => $sku->sku_name,
            'item_id' => $item->id, 'item_name' => $item->item_name, 'order_qty' => 10, 'cancelled_qty' => 0, 'shipped_qty' => 0,
            'unit_id' => $unit->id, 'unit_code_snapshot' => $unit->unit_code, 'unit_name_snapshot' => $unit->unit_name,
            'unit_price' => 10, 'amount' => 100, 'fulfillment_factor_snapshot' => 1, 'item_base_unit_id' => $unit->id,
            'item_base_unit_name_snapshot' => $unit->unit_name, 'item_base_required_qty' => 10, 'is_special_customized' => false]);
        return compact('suffix', 'unit', 'product', 'sku', 'item', 'warehouse', 'location', 'order', 'line');
    }
}
