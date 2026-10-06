<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{InventoryBalance, InventoryReservation, Item, Location, SalesOrder, SalesOrderFulfillment, SalesOrderLine, SalesReturn, SalesShipment, SalesShipmentLog, Unit, Warehouse};
use App\Services\Erp\{AuthContextService, SalesShipmentApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SalesShipmentReturnCostVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private bool $super = false;
    private string $scope = 'self';
    private array $permissions = [
        'sales_order.amount.view', 'sales_order.shipment.view', 'sales_order.shipment.create',
        'sales_order.shipment.confirm', 'sales_order.shipment.post', 'sales_order.shipment.dispatch',
        'sales_order.shipment.cancel', 'sales_order.shipment.delete_draft',
        'sales_return.view', 'sales_return.create', 'sales_return.confirm', 'sales_return.receive',
        'sales_return.post', 'sales_return.cancel', 'sales_return.delete',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(AuthContextService::class, function ($mock): void {
            $mock->shouldReceive('currentUser')->andReturn((object) ['legacy_id' => 71, 'nickname' => '销售业务测试员']);
            $mock->shouldReceive('isSuperAdmin')->andReturnUsing(fn () => $this->super);
            $mock->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
            $mock->shouldReceive('dataScope')->andReturnUsing(fn () => $this->scope);
            $mock->shouldReceive('departmentUserIds')->andReturn([71, 72]);
        });
    }

    public function test_shipment_reads_and_every_fulfillment_action_hide_cost_while_retaining_facts_in_storage(): void
    {
        [$order, $fulfillment, $balance] = $this->fixture();
        $created = $this->postJson('/api/v1/erp/sales/shipments', $this->shipmentPayload($order, $fulfillment))->assertCreated();
        $this->assertCostSafe($created);
        $id = $created->json('data.id');
        $this->assertSame(17.0, (float) SalesShipment::findOrFail($id)->actual_freight_amount);
        $this->assertSame(8.0, (float) SalesShipment::findOrFail($id)->packages()->first()->freight_amount);
        $created->assertJsonMissingPath('data.packages.0.freight_amount')->assertJsonPath('data.packages.0.tracking_no', 'TRACK-COST-SAFE');

        foreach (['confirm', 'post-outbound', 'dispatch'] as $action) {
            $this->assertCostSafe($this->postJson('/api/v1/erp/sales/shipments/'.$id.'/'.$action)->assertOk());
        }
        $shipment = SalesShipment::findOrFail($id);
        $this->assertSame('shipped', $shipment->shipment_status);
        $this->assertSame(100.0, (float) $shipment->actual_cost_amount);
        $this->assertSame(10.0, (float) $shipment->lines()->first()->unit_cost_snapshot);
        $this->assertSame(100.0, (float) $order->fresh()->actual_sales_cost_amount);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_on_hand);

        SalesShipmentLog::create(['shipment_id' => $id, 'action' => 'visibility_fixture', 'operator' => '测试员', 'content' => '历史快照',
            'payload' => ['actual_cost_amount' => 100, 'safe_tracking_no' => 'TRACK-COST-SAFE', 'nested_snapshot' => ['unit_cost' => 10]]]);
        // Customer charges remain sales data even when fulfillment cost is hidden.
        $order->update(['freight_amount' => 23]);
        foreach ([false, true] as $super) {
            $this->super = $super;
            $detail = $this->getJson('/api/v1/erp/sales/shipments/'.$id)->assertOk();
            $this->assertCostSafe($detail);
            $detail->assertJsonPath('data.order.id', $order->id)
                ->assertJsonMissingPath('data.packages.0.freight_amount');
            $this->assertSame(23.0, (float) $detail->json('data.order.freight_amount'));
            $this->assertSame(25.0, (float) $detail->json('data.order.lines.0.unit_price'));
            $this->assertCostSafe($this->getJson('/api/v1/erp/sales/shipments?sales_order_id='.$order->id)->assertOk());
        }
        $this->assertSame(100.0, (float) SalesShipmentLog::where('action', 'visibility_fixture')->first()->payload['actual_cost_amount']);
    }

    public function test_return_reads_and_receive_post_actions_hide_original_cost_allocations_without_changing_inventory_cost(): void
    {
        [$order, $fulfillment, $balance] = $this->fixture();
        $this->postShipment($order, $fulfillment);
        $payload = ['sales_order_id' => $order->id, 'return_reason' => '客户退回部分货品',
            'items' => [['sales_order_line_id' => $fulfillment->sales_order_line_id, 'requested_sales_qty' => 2]]];
        $created = $this->postJson('/api/v1/erp/sales/returns', $payload)->assertCreated();
        $this->assertCostSafe($created);
        $id = $created->json('data.id');
        $return = SalesReturn::with('items.costAllocations')->findOrFail($id);
        $this->assertNotEmpty($return->items->first()->costAllocations);
        $this->assertSame(10.0, (float) $return->items->first()->costAllocations->first()->unit_cost_snapshot);
        $this->assertCostSafe($this->postJson('/api/v1/erp/sales/returns/'.$id.'/confirm')->assertOk());
        $received = $this->postJson('/api/v1/erp/sales/returns/'.$id.'/receive', ['items' => [[
            'sales_return_item_id' => $return->items->first()->id, 'received_base_qty' => 2, 'restock_base_qty' => 2,
            'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id, 'batch_no' => 'SAFE-RETURN-BATCH',
        ]]])->assertCreated();
        $this->assertCostSafe($received);
        $receiptId = $received->json('data.id');
        $this->assertCostSafe($this->postJson('/api/v1/erp/sales/returns/'.$id.'/receipts/'.$receiptId.'/post')->assertOk());
        $this->assertDatabaseHas('erp_inventory_transaction_items', ['source_type' => 'sales_return_receipt', 'source_id' => $receiptId, 'unit_cost' => 10, 'cost_amount' => 20]);

        foreach ([false, true] as $super) {
            $this->super = $super;
            foreach (['/returns/'.$id, '/returns?keyword='.$order->sales_order_no, '/orders/'.$order->id.'/returns', '/returns/sources?keyword='.$order->sales_order_no] as $path) {
                $this->assertCostSafe($this->getJson('/api/v1/erp/sales'.$path)->assertOk());
            }
            $detail = $this->getJson('/api/v1/erp/sales/returns/'.$id)->assertOk();
            $detail->assertJsonMissingPath('items.0.cost_allocations')->assertJsonPath('return_status', 'completed');
            $this->assertSame(2.0, (float) $detail->json('items.0.received_base_qty'));
        }
        $this->assertSame(10.0, (float) $return->items->first()->costAllocations->first()->fresh()->unit_cost_snapshot);
    }

    public function test_cancellation_and_draft_deletion_remain_usable_and_do_not_return_costs(): void
    {
        [$order, $fulfillment] = $this->fixture();
        $created = $this->postJson('/api/v1/erp/sales/shipments', $this->shipmentPayload($order, $fulfillment))->assertCreated();
        $this->assertCostSafe($this->postJson('/api/v1/erp/sales/shipments/'.$created->json('data.id').'/cancel', ['reason' => '客户暂缓收货'])->assertOk());
        $draft = $this->postJson('/api/v1/erp/sales/shipments', $this->shipmentPayload($order, $fulfillment))->assertCreated();
        $this->assertCostSafe($this->deleteJson('/api/v1/erp/sales/shipments/'.$draft->json('data.id'))->assertOk());
        $this->postShipment($order, $fulfillment);
        $payload = ['sales_order_id' => $order->id, 'return_reason' => '退货草稿',
            'items' => [['sales_order_line_id' => $fulfillment->sales_order_line_id, 'requested_sales_qty' => 1]]];
        $created = $this->postJson('/api/v1/erp/sales/returns', $payload)->assertCreated();
        $this->assertCostSafe($this->postJson('/api/v1/erp/sales/returns/'.$created->json('data.id').'/cancel', ['reason' => '客户取消退货'])->assertOk());
        $draft = $this->postJson('/api/v1/erp/sales/returns', $payload)->assertCreated();
        $this->assertCostSafe($this->deleteJson('/api/v1/erp/sales/returns/'.$draft->json('data.id'))->assertOk());
    }

    public function test_shipment_creation_and_reads_enforce_self_and_department_scope_without_admin_bypass(): void
    {
        [$mine, $mineFulfillment] = $this->fixture(71);
        [$colleague, $colleagueFulfillment] = $this->fixture(72);
        [$outside, $outsideFulfillment] = $this->fixture(73);
        $this->postJson('/api/v1/erp/sales/shipments', $this->shipmentPayload($colleague, $colleagueFulfillment))->assertForbidden();
        $this->postJson('/api/v1/erp/sales/shipments', $this->shipmentPayload($outside, $outsideFulfillment))->assertForbidden();
        $mineResponse = $this->postJson('/api/v1/erp/sales/shipments', $this->shipmentPayload($mine, $mineFulfillment))->assertCreated();
        $this->assertCostSafe($mineResponse);
        $this->scope = 'department';
        $colleagueResponse = $this->postJson('/api/v1/erp/sales/shipments', $this->shipmentPayload($colleague, $colleagueFulfillment))->assertCreated();
        $this->assertCostSafe($colleagueResponse);
        $this->postJson('/api/v1/erp/sales/shipments', $this->shipmentPayload($outside, $outsideFulfillment))->assertForbidden();
        $colleagueId = $colleagueResponse->json('data.id');
        $this->getJson('/api/v1/erp/sales/shipments/'.$colleagueId)->assertOk();
        $this->scope = 'self';
        $this->getJson('/api/v1/erp/sales/shipments/'.$colleagueId)->assertNotFound();
        $this->getJson('/api/v1/erp/sales/shipments?sales_order_id='.$colleague->id)->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson('/api/v1/erp/sales/shipments/'.$colleagueId.'/confirm')->assertNotFound();
        $this->assertSame('draft', SalesShipment::findOrFail($colleagueId)->shipment_status);
        $this->assertSame(0, $outside->shipments()->count());
    }

    private function assertCostSafe(TestResponse $response): void
    {
        $walk = function (mixed $value) use (&$walk): void {
            if (! is_array($value)) return;
            $forbidden = ['actual_cost_amount', 'actual_sales_cost_amount', 'actual_freight_amount', 'unit_cost', 'cost_amount',
                'unit_cost_snapshot', 'cost_amount_snapshot', 'cost_allocations', 'cost_snapshot', 'frozen_cost_amount',
                'estimated_cost_amount', 'estimated_sales_cost_amount', 'actual_profit_amount', 'estimated_profit_amount',
                'average_unit_cost', 'inventory_value', 'standard_cost', 'last_purchase_price', 'supplier_price', 'purchase_price'];
            $this->assertSame([], array_values(array_intersect(array_keys($value), $forbidden)), 'Sales response contains a fulfillment or procurement cost field.');
            foreach ($value as $child) $walk($child);
        };
        $walk($response->json());
    }

    private function shipmentPayload(SalesOrder $order, SalesOrderFulfillment $fulfillment): array
    {
        return ['sales_order_id' => $order->id, 'actual_freight_amount' => 17,
            'lines' => [['sales_order_fulfillment_id' => $fulfillment->id, 'base_qty' => 10]],
            'packages' => [['package_no' => 'SAFE-PACKAGE', 'tracking_no' => 'TRACK-COST-SAFE', 'freight_amount' => 8]]];
    }

    private function postShipment(SalesOrder $order, SalesOrderFulfillment $fulfillment): void
    {
        $service = app(SalesShipmentApplicationService::class);
        $shipment = $service->create($order->id, $this->shipmentPayload($order, $fulfillment), '测试员');
        $shipment = $service->confirm($shipment, '测试员');
        $service->postOutbound($shipment, '测试员');
    }

    private function fixture(int $owner = 71): array
    {
        $code = Str::upper(Str::random(10));
        $unit = Unit::create(['unit_code' => 'CS-U-'.$code, 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => 'CS-W-'.$code, 'warehouse_name' => '发货测试仓', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => 'CS-L-'.$code, 'location_name' => '测试库位', 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'CS-I-'.$code, 'item_name' => '成本隔离测试物料', 'item_type' => 'finished_good', 'unit_id' => $unit->id,
            'standard_cost' => 10, 'last_purchase_price' => 12, 'is_stock_item' => true, 'status' => 'enabled']);
        $balance = InventoryBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => 'CS-B-'.$code, 'unit_id' => $unit->id, 'quantity_on_hand' => 10, 'quantity_locked' => 10,
            'quantity_available' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0, 'inventory_value' => 100, 'average_unit_cost' => 10]);
        $order = SalesOrder::create(['sales_order_no' => 'CS-SO-'.$code, 'sales_user_legacy_id' => $owner, 'customer_name' => '成本隔离测试客户',
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'shipment_status' => 'not_shipped', 'total_amount' => 0, 'final_receivable_amount' => 0,
            'funding_policy_snapshot' => ['policy_type' => 'full_prepay', 'policy_name' => '全额预付']]);
        $line = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'item_id' => $item->id, 'item_name' => $item->item_name,
            'line_type' => 'physical', 'order_qty' => 10, 'unit_price' => 25, 'amount' => 250, 'fulfillment_factor_snapshot' => 1,
            'item_base_unit_id' => $unit->id, 'item_base_required_qty' => 10, 'fulfillment_type' => 'inventory',
            'item_snapshot' => ['item_id' => $item->id, 'item_code' => $item->item_code, 'standard_cost' => 10, 'purchase_price' => 12]]);
        $fulfillment = SalesOrderFulfillment::create(['sales_order_id' => $order->id, 'sales_order_line_id' => $line->id,
            'fulfillment_type' => 'inventory', 'fulfillment_qty' => 10, 'sales_qty' => 10, 'fulfillment_factor_snapshot' => 1,
            'item_base_qty' => 10, 'base_unit_id' => $unit->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'location_id' => $location->id, 'batch_no' => $balance->batch_no, 'inventory_balance_id' => $balance->id,
            'reservation_status' => 'reserved', 'demand_status' => 'confirmed']);
        InventoryReservation::create(['source_type' => InventoryReservation::SOURCE_SALES_ORDER, 'source_order_id' => $order->id,
            'source_order_line_id' => $line->id, 'item_id' => $item->id, 'inventory_balance_id' => $balance->id,
            'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $balance->batch_no,
            'reserved_qty' => 10, 'reservation_status' => 'active', 'reserved_at' => now(),
            'idempotency_key' => 'cost-safe-reservation-'.$order->id, 'reservation_snapshot' => ['balance_table' => 'erp_inventory_balances']]);
        return [$order, $fulfillment, $balance];
    }
}
