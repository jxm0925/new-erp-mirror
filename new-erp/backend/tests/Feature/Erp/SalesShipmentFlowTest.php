<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryReservation;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\SalesShipmentApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SalesShipmentFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_one_order_can_ship_in_two_factual_outbound_transactions_and_freezes_cost(): void
    {
        [$order, $fulfillment, $balance] = $this->fixture();
        $service = app(SalesShipmentApplicationService::class);

        $first = $service->create($order->id, [
            'lines' => [['sales_order_fulfillment_id' => $fulfillment->id, 'base_qty' => 4]],
            'packages' => [
                ['package_no' => 'PKG-1-A', 'weight' => 1.2, 'tracking_no' => 'TRACK-1-A'],
                ['package_no' => 'PKG-1-B', 'weight' => 1.3, 'tracking_no' => 'TRACK-1-B'],
            ],
        ], '测试操作员');
        $this->assertSame(2, $first->packages()->count());
        $first = $service->confirm($first, '测试操作员');
        $first = $service->postOutbound($first, '测试操作员');

        $this->assertSame('outbound_posted', $first->shipment_status);
        $this->assertSame(40.0, (float) $first->actual_cost_amount);
        $this->assertSame(6.0, (float) $balance->fresh()->quantity_on_hand);
        $this->assertSame(6.0, (float) $balance->fresh()->quantity_locked);
        $this->assertSame('partially_shipped', $order->fresh()->shipment_status);
        $this->assertDatabaseHas('erp_inventory_transactions', [
            'source_type' => 'sales_shipment',
            'source_id' => $first->id,
            'transaction_type' => 'sales_shipment_outbound',
        ]);

        $second = $service->create($order->id, [
            'lines' => [['sales_order_fulfillment_id' => $fulfillment->id, 'base_qty' => 6]],
        ], '测试操作员');
        $second = $service->confirm($second, '测试操作员');
        $second = $service->postOutbound($second, '测试操作员');

        $this->assertSame(0.0, (float) $balance->fresh()->quantity_on_hand);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_locked);
        $this->assertSame('shipped', $order->fresh()->shipment_status);
        $this->assertSame(100.0, (float) $order->fresh()->actual_sales_cost_amount);
        $this->assertSame(2, $order->shipments()->count());
        $this->assertDatabaseHas('erp_inventory_transaction_items', [
            'source_type' => 'sales_shipment',
            'source_id' => $second->id,
            'change_qty' => -6,
            'unit_cost' => 10,
        ]);
    }

    public function test_cancelling_a_draft_shipment_restores_its_allocation_to_the_sales_order_lock(): void
    {
        [$order, $fulfillment, $balance] = $this->fixture();
        $service = app(SalesShipmentApplicationService::class);
        $shipment = $service->create($order->id, [
            'lines' => [['sales_order_fulfillment_id' => $fulfillment->id, 'base_qty' => 3]],
        ], '测试操作员');

        $service->cancel($shipment, '客户暂缓收货', '测试操作员');

        $this->assertSame(10.0, (float) InventoryReservation::where('source_order_id', $order->id)
            ->where('reservation_status', 'active')->value('reserved_qty'));
        $this->assertSame(10.0, (float) $balance->fresh()->quantity_locked);
        // Cancelling the shipment changes only the child shipment allocation.
        // The sales order itself still needs all 10 units, so none may become available.
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_available);
    }

    private function fixture(): array
    {
        $unit = Unit::create(['unit_code' => 'EA-SHIP', 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => 'WH-SHIP', 'warehouse_name' => '销售发货仓', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => 'LOC-SHIP', 'location_name' => '销售发货库位', 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'ITEM-SHIP', 'item_name' => '销售出库测试物料', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled']);
        $balance = InventoryBalance::create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => 'SHIP-BATCH-001', 'unit_id' => $unit->id,
            'quantity_on_hand' => 10, 'quantity_locked' => 10, 'quantity_available' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0,
            'inventory_value' => 100, 'average_unit_cost' => 10,
        ]);
        $order = SalesOrder::create([
            'sales_order_no' => 'SO-SHIP-'.uniqid(), 'customer_name' => '销售发货测试客户', 'order_status' => 'confirmed', 'confirm_status' => 'confirmed',
            'shipment_status' => 'not_shipped', 'total_amount' => 0, 'final_receivable_amount' => 0,
            'funding_policy_snapshot' => ['policy_type' => 'full_prepay', 'policy_name' => '全额预付'],
        ]);
        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_no' => 1, 'item_id' => $item->id, 'item_name' => $item->item_name,
            'line_type' => 'physical', 'order_qty' => 10, 'unit_price' => 10, 'amount' => 100,
            'fulfillment_factor_snapshot' => 1, 'item_base_unit_id' => $unit->id, 'item_base_required_qty' => 10,
            'fulfillment_type' => 'inventory', 'item_snapshot' => ['item_id' => $item->id, 'item_code' => $item->item_code],
        ]);
        $fulfillment = SalesOrderFulfillment::create([
            'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id, 'fulfillment_type' => 'inventory', 'fulfillment_qty' => 10,
            'sales_qty' => 10, 'fulfillment_factor_snapshot' => 1, 'item_base_qty' => 10, 'base_unit_id' => $unit->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $balance->batch_no,
            'inventory_balance_id' => $balance->id, 'reservation_status' => 'reserved', 'demand_status' => 'confirmed',
        ]);
        InventoryReservation::create([
            'source_type' => InventoryReservation::SOURCE_SALES_ORDER, 'source_order_id' => $order->id, 'source_order_line_id' => $line->id,
            'item_id' => $item->id, 'inventory_balance_id' => $balance->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => $balance->batch_no, 'reserved_qty' => 10, 'reservation_status' => 'active', 'reserved_at' => now(),
            'idempotency_key' => 'shipment-test-'.$order->id, 'reservation_snapshot' => ['balance_table' => 'erp_inventory_balances'],
        ]);

        return [$order, $fulfillment, $balance];
    }
}
