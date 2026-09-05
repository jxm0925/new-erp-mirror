<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryReservation;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\ProductionDemand;
use App\Models\Erp\ProductionOutputRecord;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Models\Erp\WorkOrder;
use App\Services\Erp\ProductionOutputService;
use App\Services\Erp\SalesShipmentApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesProductionReplenishmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_terminal_sales_output_receipt_is_immediately_reserved_for_origin_order(): void
    {
        $suffix = Str::upper(Str::random(8));
        $unit = Unit::create(['unit_code' => 'EA-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => 'WH-'.$suffix, 'warehouse_name' => '成品仓', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => 'LOC-'.$suffix, 'location_name' => '成品库位', 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'FG-'.$suffix, 'item_name' => '销售来源成品', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'status' => 'enabled']);
        $order = SalesOrder::create(['sales_order_no' => 'SO-'.$suffix, 'customer_name' => '生产回补客户', 'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed', 'business_version' => 1]);
        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_no' => 1, 'line_type' => 'physical', 'item_id' => $item->id,
            'item_name' => $item->item_name, 'order_qty' => 6, 'unit_id' => $unit->id, 'unit_name_snapshot' => '件',
            'item_base_unit_id' => $unit->id, 'item_base_unit_name_snapshot' => '件',
            'fulfillment_factor_snapshot' => 1, 'item_base_required_qty' => 6,
            'production_required_qty' => 6, 'production_replenished_qty' => 0,
        ]);
        $fulfillment = SalesOrderFulfillment::create([
            'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id,
            'fulfillment_type' => 'production', 'fulfillment_qty' => 6, 'sales_qty' => 6,
            'fulfillment_factor_snapshot' => 1, 'item_base_qty' => 6, 'item_id' => $item->id,
            'base_unit_id' => $unit->id, 'base_unit_name_snapshot' => '件',
            'reservation_status' => 'not_required', 'production_requirement_status' => 'confirmed', 'demand_status' => 'confirmed',
        ]);
        $demand = ProductionDemand::create([
            'requirement_no' => 'SOPR-'.$suffix, 'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id,
            'item_id' => $item->id, 'production_qty' => 6, 'item_base_required_qty' => 6,
            'base_unit_id' => $unit->id, 'base_unit_name_snapshot' => '件', 'allocated_qty' => 6,
            'consumed_qty' => 0, 'remaining_qty' => 0, 'closed_qty' => 0, 'requirement_status' => 'consumed',
            'bom_match_status' => 'matched', 'is_active' => true, 'requirement_version' => 1,
            'business_version' => 1, 'is_ready_for_work_order' => true,
        ]);
        $workOrder = WorkOrder::create([
            'work_order_no' => 'WO-'.$suffix, 'source_type' => 'sales_order', 'source_id' => $order->id,
            'source_no_snapshot' => $order->sales_order_no, 'production_demand_id' => $demand->id,
            'output_item_id' => $item->id, 'target_qty' => 6, 'target_base_qty' => 6,
            'target_unit_id' => $unit->id, 'base_unit_id' => $unit->id, 'status' => 'IN_PROGRESS', 'business_version' => 1,
        ]);
        $target = ProductionQuantityOperation::create([
            'work_order_id' => $workOrder->id, 'operation_code_snapshot' => 'FINAL', 'operation_name_snapshot' => '成品完工',
            'sequence_no_snapshot' => 10, 'status' => 'WAIT_WAREHOUSE', 'planned_base_qty' => 6,
            'completed_base_qty' => 6, 'scrapped_base_qty' => 0, 'remaining_base_qty' => 0,
            'output_item_id_snapshot' => $item->id, 'output_mode_snapshot' => 'warehouse_required',
            'quality_mode_snapshot' => 'none', 'business_version' => 1,
        ]);
        $output = ProductionOutputRecord::create([
            'output_no' => 'POUT-'.$suffix, 'work_order_id' => $workOrder->id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => $target->id,
            'output_item_id' => $item->id, 'output_base_qty' => 6,
            'output_mode_snapshot' => 'warehouse_required', 'quality_mode_snapshot' => 'none',
            'status' => 'CREATED', 'created_by_legacy_id' => 10001, 'produced_at' => now(), 'business_version' => 1,
        ]);
        $user = (object) ['legacy_id' => 10001, 'username' => 'warehouse-tester', 'nickname' => '仓库测试员'];

        $result = app(ProductionOutputService::class)->warehouse($output->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => 'PROD-'.$suffix, 'unit_cost' => 20,
        ], $user, ['production.output.warehouse']);

        $balance = InventoryBalance::query()->where('item_id', $item->id)->where('batch_no', 'PROD-'.$suffix)->firstOrFail();
        $reservation = InventoryReservation::findOrFail($result['sales_order_reservation_id']);
        $this->assertSame(6.0, (float) $balance->quantity_on_hand);
        $this->assertSame(6.0, (float) $balance->quantity_locked);
        $this->assertSame(0.0, (float) $balance->quantity_available);
        $this->assertSame($order->id, (int) $reservation->source_order_id);
        $this->assertSame($line->id, (int) $reservation->source_order_line_id);
        $this->assertSame($fulfillment->id, (int) $reservation->sales_order_fulfillment_id);
        $this->assertSame('production_replenishment', data_get($reservation->reservation_snapshot, 'reservation_origin'));
        $this->assertSame(6.0, (float) $line->fresh()->production_replenished_qty);
        $this->assertSame($reservation->id, (int) DB::table('erp_production_output_warehouse_postings')->where('output_record_id', $output->id)->value('sales_order_reservation_id'));

        $shipments = app(SalesShipmentApplicationService::class);
        $shipment = $shipments->create($order->id, [
            'lines' => [['sales_order_fulfillment_id' => $fulfillment->id, 'base_qty' => 6]],
        ], '销售发货测试员');
        $shipment = $shipments->confirm($shipment, '销售发货测试员');
        $shipments->postOutbound($shipment, '销售发货测试员');
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_on_hand);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_locked);
        $this->assertSame(6.0, (float) $line->fresh()->production_replenished_qty);
    }
}
