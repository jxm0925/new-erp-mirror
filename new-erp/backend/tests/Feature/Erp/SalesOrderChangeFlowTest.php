<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryBatch;
use App\Models\Erp\InventoryReservation;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\SalesOrderChangeApplicationService;
use App\Services\Erp\SalesOrderFulfillmentApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesOrderChangeFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_confirmed_order_change_releases_old_reservation_recalculates_commercial_facts_and_requires_replanning(): void
    {
        [$order, $line, $balance] = $this->fixture();

        $change = app(SalesOrderChangeApplicationService::class)->apply($order->id, [
            'reason' => '客户将数量从十台调整为六台，并重新议价。',
            'lines' => [[
                'sales_order_line_id' => $line->id,
                'order_qty' => 6,
                'unit_price' => 20,
                'discount_rate' => 1,
                'tax_rate' => 13,
                'price_tax_mode' => 'tax_inclusive',
            ]],
        ], '订单变更验收员');

        $this->assertStringStartsWith('SOC', $change->change_no);
        $this->assertSame('applied', $change->change_status);
        $this->assertSame(1, (int) $change->version_no);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_locked);
        $this->assertSame(10.0, (float) $balance->fresh()->quantity_available);
        $this->assertSame('released', InventoryReservation::where('source_order_id', $order->id)->value('reservation_status'));
        $this->assertSame('superseded', SalesOrderFulfillment::where('sales_order_id', $order->id)->value('demand_status'));

        $fresh = $order->fresh();
        $freshLine = $line->fresh();
        $this->assertSame('pending', $fresh->production_confirm_status);
        $this->assertSame('pending', $fresh->fulfillment_status);
        $this->assertSame(6.0, (float) $freshLine->order_qty);
        $this->assertSame(20.0, (float) $freshLine->unit_price);
        $this->assertSame(120.0, (float) $freshLine->amount_incl_tax);
        $this->assertSame(120.0, (float) $fresh->final_receivable_amount);
        $this->assertDatabaseHas('erp_sales_order_versions', ['sales_order_id' => $order->id, 'version_no' => $change->version_no, 'change_type' => 'confirmed_order_change']);
        $this->assertDatabaseHas('erp_sales_order_logs', ['sales_order_id' => $order->id, 'action' => 'confirmed_order_change']);
    }

    public function test_shipped_order_cannot_be_changed_in_place(): void
    {
        [$order, $line] = $this->fixture();
        $order->update(['shipment_status' => 'partially_shipped']);

        try {
            app(SalesOrderChangeApplicationService::class)->apply($order->id, [
                'reason' => '不应绕过售后流程',
                'lines' => [['sales_order_line_id' => $line->id, 'order_qty' => 6]],
            ], '订单变更验收员');
            $this->fail('已有发货事实必须阻断普通订单变更。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shipment_status', $exception->errors());
        }
    }

    public function test_changed_order_rechecks_availability_and_reserves_again_only_after_new_confirmation(): void
    {
        [$order, $line, $balance] = $this->fixture();
        app(SalesOrderChangeApplicationService::class)->apply($order->id, [
            'reason' => '客户将数量下调后重新确认库存履约。',
            'lines' => [['sales_order_line_id' => $line->id, 'order_qty' => 6]],
        ], '订单变更验收员');

        app(SalesOrderFulfillmentApplicationService::class)->confirmProduction($order->id, [[
            'sales_order_line_id' => $line->id,
            'confirm_qty' => 6,
            'inventory_qty' => 6,
            'production_qty' => 0,
            'service_qty' => 0,
            'no_delivery_qty' => 0,
        ]], null, '订单变更验收员');

        $this->assertSame(6.0, (float) $balance->fresh()->quantity_locked);
        $this->assertSame(4.0, (float) $balance->fresh()->quantity_available);
        $this->assertSame('confirmed', $order->fresh()->production_confirm_status);
        $this->assertSame('active', InventoryReservation::where('source_order_id', $order->id)->latest('id')->value('reservation_status'));
    }

    public function test_changed_order_cannot_reconfirm_inventory_quantity_above_current_available_stock(): void
    {
        [$order, $line] = $this->fixture();
        app(SalesOrderChangeApplicationService::class)->apply($order->id, [
            'reason' => '客户追加数量，必须重新核验库存可用量。',
            'lines' => [['sales_order_line_id' => $line->id, 'order_qty' => 12]],
        ], '订单变更验收员');

        try {
            app(SalesOrderFulfillmentApplicationService::class)->confirmProduction($order->id, [[
                'sales_order_line_id' => $line->id,
                'confirm_qty' => 12,
                'inventory_qty' => 12,
                'production_qty' => 0,
                'service_qty' => 0,
                'no_delivery_qty' => 0,
            ]], null, '订单变更验收员');
            $this->fail('订单变更后不得沿用旧预留绕过当前库存可用量校验。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }
    }

    private function fixture(): array
    {
        $suffix = strtoupper(substr(uniqid(), -6));
        $unit = Unit::create(['unit_code' => 'SOC-U-'.$suffix, 'unit_name' => '台', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => 'SOC-W-'.$suffix, 'warehouse_name' => '订单变更仓库', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => 'SOC-L-'.$suffix, 'location_name' => '订单变更库位', 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'SOC-I-'.$suffix, 'item_name' => '订单变更测试物料', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled']);
        $balance = InventoryBalance::create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => 'SOC-B-'.$suffix, 'unit_id' => $unit->id,
            'quantity_on_hand' => 10, 'quantity_locked' => 10, 'quantity_available' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0,
            'inventory_value' => 100, 'average_unit_cost' => 10,
        ]);
        InventoryBatch::create([
            'item_id' => $item->id, 'batch_no' => $balance->batch_no,
            'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'status' => 'enabled',
        ]);
        $order = SalesOrder::create([
            'sales_order_no' => 'SOC-ORDER-'.$suffix, 'customer_name' => '订单变更验收客户',
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed',
            'shipment_status' => 'not_shipped', 'total_amount' => 100, 'final_receivable_amount' => 100,
            'funding_policy_snapshot' => ['policy_type' => 'installment_contract', 'policy_name' => '订单变更测试策略', 'production_threshold_type' => 'amount', 'production_threshold_value' => '0'],
        ]);
        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_no' => 1, 'item_id' => $item->id, 'item_name' => $item->item_name,
            'line_type' => 'physical', 'order_qty' => 10, 'unit_price' => 10, 'amount' => 100,
            'price_tax_mode' => 'tax_inclusive', 'discount_rate' => 1, 'tax_rate' => 0, 'amount_excl_tax' => 100, 'tax_amount' => 0, 'amount_incl_tax' => 100,
            'fulfillment_factor_snapshot' => 1, 'item_base_unit_id' => $unit->id, 'item_base_required_qty' => 10,
            'fulfillment_type' => 'inventory', 'line_status' => 'demand_confirmed', 'item_snapshot' => ['item_id' => $item->id, 'item_code' => $item->item_code],
        ]);
        SalesOrderFulfillment::create([
            'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id, 'fulfillment_type' => 'inventory', 'fulfillment_qty' => 10,
            'sales_qty' => 10, 'fulfillment_factor_snapshot' => 1, 'item_base_qty' => 10, 'base_unit_id' => $unit->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $balance->batch_no,
            'inventory_balance_id' => $balance->id, 'reservation_status' => 'reserved', 'demand_status' => 'confirmed',
        ]);
        InventoryReservation::create([
            'source_type' => InventoryReservation::SOURCE_SALES_ORDER, 'source_order_id' => $order->id, 'source_order_line_id' => $line->id,
            'item_id' => $item->id, 'inventory_balance_id' => $balance->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => $balance->batch_no, 'reserved_qty' => 10, 'reservation_status' => 'active', 'reserved_at' => now(),
            'idempotency_key' => 'sales-order-change-'.$order->id, 'reservation_snapshot' => ['balance_table' => 'erp_inventory_balances'],
        ]);

        return [$order, $line, $balance];
    }
}
