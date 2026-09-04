<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\SalesShipment;
use App\Models\Erp\SalesShipmentLine;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\SalesReturnApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesReturnFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_partial_sales_return_receipts_only_restock_qualified_quantity(): void
    {
        [$order, $line, $warehouse, $location] = $this->fixture();
        $service = app(SalesReturnApplicationService::class);
        $salesReturn = $service->create([
            'sales_order_id' => $order->id,
            'return_reason' => '客户部分退货',
            'items' => [[
                'sales_order_line_id' => $line->id,
                'requested_sales_qty' => 4,
            ]],
        ], 1, '测试管理员');
        $salesReturn = $service->confirm($salesReturn->id, 1, '测试管理员');
        $this->assertSame('pending_receipt', $salesReturn->return_status);

        $first = $service->receive([
            'sales_return_id' => $salesReturn->id,
            'items' => [[
                'sales_return_item_id' => $salesReturn->items->first()->id,
                'received_base_qty' => 2,
                'restock_base_qty' => 1,
                'pending_base_qty' => 1,
                'scrap_base_qty' => 0,
                'rejected_base_qty' => 0,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'batch_no' => 'SR-BATCH-001',
            ]],
        ], 1, '测试管理员');
        $this->assertSame('partial_received', $first->salesReturn->return_status);
        $service->postReceipt($first->id, 1, '测试管理员');
        $this->assertSame(1.0, (float) InventoryBalance::firstOrFail()->quantity_on_hand);
        $this->assertSame(12.0, (float) InventoryBalance::firstOrFail()->average_unit_cost);

        $second = $service->receive([
            'sales_return_id' => $salesReturn->id,
            'items' => [[
                'sales_return_item_id' => $salesReturn->items->first()->id,
                'received_base_qty' => 2,
                'restock_base_qty' => 2,
                'pending_base_qty' => 0,
                'scrap_base_qty' => 0,
                'rejected_base_qty' => 0,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'batch_no' => 'SR-BATCH-001',
            ]],
        ], 1, '测试管理员');
        $service->postReceipt($second->id, 1, '测试管理员');

        $this->assertSame('completed', $salesReturn->fresh()->return_status);
        $this->assertSame(3.0, (float) InventoryBalance::firstOrFail()->quantity_on_hand);
        $this->assertSame(36.0, (float) InventoryBalance::firstOrFail()->inventory_value);
        $this->assertDatabaseHas('erp_inventory_transactions', [
            'source_type' => 'sales_return_receipt',
            'source_id' => $first->id,
            'transaction_type' => 'sales_return_inbound',
        ]);
        $this->assertDatabaseHas('erp_inventory_transaction_items', [
            'source_type' => 'sales_return_receipt',
            'source_id' => $first->id,
            'change_qty' => 1,
            'unit_cost' => 12,
            'cost_source_type' => 'sales_return_frozen_cost',
        ]);
    }

    public function test_sales_return_uses_original_order_line_item_snapshot(): void
    {
        [$order, $line] = $this->fixture();
        $newCurrentItem = Item::create([
            'item_code' => 'ITEM-NEW-CURRENT',
            'item_name' => '当前新默认物料',
            'item_type' => 'finished_good',
            'unit_id' => $line->item_base_unit_id,
            'is_stock_item' => true,
            'status' => 'enabled',
        ]);

        $salesReturn = app(SalesReturnApplicationService::class)->create([
            'sales_order_id' => $order->id,
            'return_reason' => '历史订单退货',
            'items' => [[
                'sales_order_line_id' => $line->id,
                'requested_sales_qty' => 1,
            ]],
        ], 1, '测试管理员');

        $this->assertSame($line->item_id, (int) $salesReturn->items->first()->item_id);
        $this->assertNotSame($newCurrentItem->id, (int) $salesReturn->items->first()->item_id);
        $this->assertSame($line->item_id, (int) $salesReturn->items->first()->fulfillment_snapshot['item_id']);
    }

    public function test_sales_return_cannot_exceed_shipped_quantity_across_active_returns(): void
    {
        [$order, $line] = $this->fixture();
        $service = app(SalesReturnApplicationService::class);
        $payload = [
            'sales_order_id' => $order->id,
            'return_reason' => '第一张退货',
            'items' => [[
                'sales_order_line_id' => $line->id,
                'requested_sales_qty' => 8,
            ]],
        ];
        $service->create($payload, 1, '测试管理员');
        $payload['items'][0]['requested_sales_qty'] = 3;

        $this->expectException(ValidationException::class);
        $service->create($payload, 1, '测试管理员');
    }

    public function test_non_restock_receipt_completes_without_creating_a_pending_stock_task(): void
    {
        [$order, $line] = $this->fixture();
        $service = app(SalesReturnApplicationService::class);
        $salesReturn = $service->create([
            'sales_order_id' => $order->id,
            'return_reason' => '退回后等待售后判定',
            'items' => [[
                'sales_order_line_id' => $line->id,
                'requested_sales_qty' => 2,
            ]],
        ], 1, '测试管理员');
        $salesReturn = $service->confirm($salesReturn->id, 1, '测试管理员');

        $receipt = $service->receive([
            'sales_return_id' => $salesReturn->id,
            'items' => [[
                'sales_return_item_id' => $salesReturn->items->first()->id,
                'received_base_qty' => 2,
                'restock_base_qty' => 0,
                'pending_base_qty' => 2,
                'scrap_base_qty' => 0,
                'rejected_base_qty' => 0,
            ]],
        ], 1, '测试管理员');

        $this->assertSame('not_required', $receipt->stock_post_status);
        $this->assertSame('completed', $salesReturn->fresh()->return_status);
        $this->assertDatabaseMissing('erp_inventory_transactions', [
            'source_type' => 'sales_return_receipt',
            'source_id' => $receipt->id,
            'transaction_type' => 'sales_return_inbound',
        ]);
    }

    private function fixture(): array
    {
        $unit = Unit::create([
            'unit_code' => 'EA-SALES-RETURN',
            'unit_name' => '件',
            'unit_type' => 'quantity',
            'decimal_places' => 0,
            'is_base' => true,
            'status' => 'enabled',
        ]);
        $warehouse = Warehouse::create([
            'warehouse_code' => 'WH-SALES-RETURN',
            'warehouse_name' => '销售退货仓',
            'status' => 'enabled',
        ]);
        $location = Location::create([
            'location_code' => 'LOC-SALES-RETURN',
            'location_name' => '销售退货库位',
            'warehouse_id' => $warehouse->id,
            'status' => 'enabled',
        ]);
        $item = Item::create([
            'item_code' => 'ITEM-SALES-RETURN',
            'item_name' => '原发货履约物料',
            'item_type' => 'finished_good',
            'unit_id' => $unit->id,
            'is_stock_item' => true,
            'status' => 'enabled',
        ]);
        $order = SalesOrder::create([
            'sales_order_no' => 'SO-SALES-RETURN-001',
            'customer_name' => '销售退货测试客户',
            'order_status' => 'confirmed',
            'confirm_status' => 'confirmed',
            'shipment_status' => 'shipped',
        ]);
        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'line_no' => 1,
            'item_id' => $item->id,
            'item_name' => $item->item_name,
            'line_type' => 'physical',
            'order_qty' => 10,
            'shipped_qty' => 10,
            'fulfillment_factor_snapshot' => 1,
            'item_base_unit_id' => $unit->id,
            'item_base_required_qty' => 10,
            'item_snapshot' => ['id' => $item->id, 'item_code' => $item->item_code],
            'fulfillment_type' => 'inventory',
        ]);

        // The original sales-outbound fact is deliberately independent from the
        // current balance: a later return must reuse this frozen 12/unit cost.
        $shipment = SalesShipment::create([
            'shipment_no' => 'SHP-SALES-RETURN-001',
            'sales_order_id' => $order->id,
            'shipment_status' => 'shipped',
            'actual_cost_amount' => 120,
        ]);
        $shipmentLine = SalesShipmentLine::create([
            'shipment_id' => $shipment->id,
            'sales_order_line_id' => $line->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'batch_no' => 'SO-OUTBOUND-001',
            'unit_id' => $unit->id,
            'sales_qty' => 10,
            'base_qty' => 10,
            'unit_cost_snapshot' => 12,
            'cost_amount_snapshot' => 120,
            'line_status' => 'outbound_posted',
        ]);
        $outbound = InventoryTransaction::create([
            'transaction_no' => 'ITX-SALES-RETURN-001',
            'transaction_type' => 'sales_shipment_outbound',
            'source_type' => 'sales_shipment',
            'source_id' => $shipment->id,
            'source_no' => $shipment->shipment_no,
            'posting_status' => 'posted',
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'transaction_date' => now()->toDateString(),
            'posted_at' => now(),
        ]);
        InventoryTransactionItem::create([
            'transaction_id' => $outbound->id,
            'transaction_no' => $outbound->transaction_no,
            'item_id' => $item->id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'batch_no' => 'SO-OUTBOUND-001',
            'unit_id' => $unit->id,
            'change_qty' => -10,
            'unit_cost' => 12,
            'cost_amount' => -120,
            'cost_source_type' => 'sales_shipment_frozen_fact',
            'source_type' => 'sales_shipment',
            'source_id' => $shipment->id,
            'source_item_id' => $shipmentLine->id,
        ]);

        return [$order, $line, $warehouse, $location];
    }
}
