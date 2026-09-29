<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryLocationBalance;
use App\Models\Erp\InventoryReservation;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\SalesReturn;
use App\Models\Erp\SalesReturnCostAllocation;
use App\Models\Erp\SalesReturnReceipt;
use App\Models\Erp\SalesReturnSerialIdentity;
use App\Models\Erp\SalesShipment;
use App\Models\Erp\SalesShipmentLine;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\InventoryService;
use App\Services\Erp\SalesReturnApplicationService;
use App\Services\Erp\SalesReturnIdentityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesReturnSerialIdentityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_real_outbound_return_and_restock_preserve_identity_cost_and_event(): void
    {
        $f = $this->fixture([['A', 1, '17.4321']]);
        $serial = $f['shipments'][0]['serials'][0];
        $this->assertSame('shipped', $serial->fresh()->serial_status);
        $return = $this->createReturn($f, 1);
        $receipt = $this->receive($f, $return, ['restock' => [$serial->id]], 'A');
        $this->assertSame('sales_return_restock_pending', $serial->fresh()->serial_status);
        $this->assertNull($serial->fresh()->inventory_balance_id);
        $this->assertSame(0.0, (float) $f['shipments'][0]['balance']->fresh()->quantity_on_hand);
        app(SalesReturnApplicationService::class)->postReceipt($receipt->id, 1, '测试');
        $serial->refresh();
        $this->assertSame('available', $serial->serial_status);
        $this->assertSame($f['shipments'][0]['balance']->id, $serial->inventory_balance_id);
        $this->assertSame('A', $serial->batch_no);
        $this->assertNull($serial->outbound_at);
        $identity = SalesReturnSerialIdentity::where('inventory_serial_id', $serial->id)->firstOrFail();
        $this->assertNotNull($identity->inventory_transaction_item_id);
        $this->assertSame('17.4321', $identity->cost_amount_snapshot);
        $this->assertSame('17.4321', $f['shipments'][0]['balance']->fresh()->inventory_value);
        $this->assertDatabaseHas('erp_inventory_serial_events', ['inventory_serial_id' => $serial->id, 'event_type' => 'sales_return_inbound', 'from_status' => 'sales_return_restock_pending', 'to_status' => 'available']);
        $this->assertRejected(fn () => app(SalesReturnApplicationService::class)->postReceipt($receipt->id, 1, '重复'), 'receipt_status');
        $this->assertSame(1, InventorySerialEvent::where('inventory_serial_id', $serial->id)->where('event_type', 'sales_return_inbound')->count());
    }

    public function test_four_dispositions_are_bound_and_only_restock_changes_available_stock(): void
    {
        $f = $this->fixture([['A', 4, '10.0000']]);
        $ids = array_map(fn ($serial) => $serial->id, $f['shipments'][0]['serials']);
        $return = $this->createReturn($f, 4);
        $receipt = $this->receive($f, $return, ['restock' => [$ids[0]], 'pending' => [$ids[1]], 'scrap' => [$ids[2]], 'rejected' => [$ids[3]]], 'A');
        app(SalesReturnApplicationService::class)->postReceipt($receipt->id, 1, '测试');
        $this->assertSame(['available', 'sales_return_pending', 'sales_return_scrapped', 'sales_return_rejected'], InventorySerial::whereIn('id', $ids)->orderBy('id')->pluck('serial_status')->all());
        $balance = $f['shipments'][0]['balance']->fresh();
        $this->assertSame(1.0, (float) $balance->quantity_on_hand);
        $this->assertSame('10.0000', $balance->inventory_value);
        $this->assertSame(4, SalesReturnSerialIdentity::where('sales_return_item_id', $return->items->first()->id)->count());
        $this->assertSame(1, SalesReturnSerialIdentity::where('sales_return_item_id', $return->items->first()->id)->whereNotNull('inventory_transaction_item_id')->count());
        $projection = app(SalesReturnIdentityService::class)->receiptIdentities($receipt->id, ['page' => 2, 'per_page' => 1]);
        $this->assertSame(4, $projection->total()); $this->assertCount(1, $projection->items());
        $this->assertSame('pending', $projection->items()[0]->disposition);
        $this->assertObjectNotHasProperty('cost_amount_snapshot', $projection->items()[0]);
    }

    public function test_quantity_only_overlap_wrong_batch_and_foreign_serial_all_fail_atomically(): void
    {
        $f = $this->fixture([['A', 2, '10.0000'], ['B', 1, '25.0000']]);
        $return = $this->createReturn($f, 3);
        $id = $f['shipments'][0]['serials'][0]->id;
        $foreign = $this->fixture([['X', 1, '9.0000']])['shipments'][0]['serials'][0]->id;
        $bad = [
            ['serial_dispositions' => [], 'received_base_qty' => 1, 'restock_base_qty' => 1],
            ['serial_dispositions' => ['restock' => [$id], 'pending' => [$id]], 'received_base_qty' => 2, 'restock_base_qty' => 1, 'pending_base_qty' => 1],
            ['serial_dispositions' => ['restock' => [$id]], 'batch_no' => 'WRONG', 'received_base_qty' => 1, 'restock_base_qty' => 1],
            ['serial_dispositions' => ['restock' => [$foreign]], 'received_base_qty' => 1, 'restock_base_qty' => 1],
        ];
        foreach ($bad as $override) {
            $row = array_merge($this->row($f, $return, ['restock' => [$id]], 'A'), $override);
            $this->assertRejected(fn () => app(SalesReturnApplicationService::class)->receive(['sales_return_id' => $return->id, 'items' => [$row]], 1, '测试'), 'serial_dispositions');
            $this->assertSame(0, SalesReturnReceipt::where('sales_return_id', $return->id)->count());
            $this->assertSame(0.0, (float) $return->items->first()->fresh()->received_base_qty);
            $this->assertSame('shipped', InventorySerial::findOrFail($id)->serial_status);
        }
    }

    public function test_same_source_serial_cannot_be_received_twice_and_candidates_are_paginated(): void
    {
        $f = $this->fixture([['A', 3, '10.0000']]);
        $return = $this->createReturn($f, 3);
        $line = $return->items->first();
        $page = app(SalesReturnIdentityService::class)->candidates($return->id, $line->id, ['page' => 2, 'per_page' => 1]);
        $this->assertSame(3, $page->total()); $this->assertCount(1, $page->items());
        $id = $f['shipments'][0]['serials'][0]->id;
        $this->receive($f, $return, ['pending' => [$id]], 'A');
        $this->assertRejected(fn () => $this->receive($f, $return, ['pending' => [$id]], 'A'), 'serial_dispositions');
        $page = app(SalesReturnIdentityService::class)->candidates($return->id, $line->id, ['per_page' => 1]);
        $this->assertSame(2, $page->total());
        $this->assertNotSame($id, $page->items()[0]->id);
        $availableId = $f['shipments'][0]['serials'][2]->id;
        $selectedPage = app(SalesReturnIdentityService::class)->candidates($return->id, $line->id, ['ids' => [$id, $availableId], 'per_page' => 1]);
        $this->assertSame(1, $selectedPage->total());
        $this->assertSame($availableId, $selectedPage->items()[0]->id);
        $this->assertSame(0, app(SalesReturnIdentityService::class)->candidates($return->id, $line->id, ['ids' => []])->total());
        $this->assertSame(1, SalesReturnSerialIdentity::where('inventory_serial_id', $id)->count());
    }

    public function test_wrong_fifo_reservation_moves_only_unused_cost_to_the_actual_serial_source(): void
    {
        $f = $this->fixture([['A', 2, '10.0000'], ['B', 2, '40.0000']]);
        $return = $this->createReturn($f, 2);
        $line = $return->items->first();
        $original = SalesReturnCostAllocation::where('sales_return_item_id', $line->id)->firstOrFail();
        $this->assertSame($f['shipments'][0]['outbound']->id, $original->outbound_transaction_item_id);
        $first = $this->receive($f, $return, ['restock' => [$f['shipments'][1]['serials'][0]->id]], 'B');
        app(SalesReturnApplicationService::class)->postReceipt($first->id, 1, '测试');
        $this->assertSame(1.0, (float) $original->fresh()->allocated_base_qty);
        $bound = SalesReturnSerialIdentity::where('sales_return_item_id', $line->id)->firstOrFail();
        $this->assertSame($f['shipments'][1]['outbound']->id, $bound->outbound_transaction_item_id);
        $this->assertSame('40.0000', $bound->cost_amount_snapshot);
        $this->assertSame('40.0000', $f['shipments'][1]['balance']->fresh()->inventory_value);
        // A later receipt uses the remaining A reservation; the already posted B identity stays unchanged.
        $second = $this->receive($f, $return, ['restock' => [$f['shipments'][0]['serials'][0]->id]], 'A');
        app(SalesReturnApplicationService::class)->postReceipt($second->id, 1, '测试');
        $this->assertSame('10.0000', $f['shipments'][0]['balance']->fresh()->inventory_value);
        $this->assertSame($f['shipments'][1]['outbound']->id, $bound->fresh()->outbound_transaction_item_id);
        $this->assertSame('completed', $return->fresh()->return_status);
    }

    public function test_different_original_batches_must_be_received_separately(): void
    {
        $f = $this->fixture([['A', 1, '10.0000'], ['B', 1, '20.0000']]);
        $return = $this->createReturn($f, 2);
        $this->assertRejected(fn () => $this->receive($f, $return, ['restock' => [$f['shipments'][0]['serials'][0]->id, $f['shipments'][1]['serials'][0]->id]], 'A'), 'serial_dispositions');
        $this->assertSame(0, SalesReturnSerialIdentity::where('sales_return_item_id', $return->items->first()->id)->count());
    }

    public function test_frozen_total_rounding_is_preserved_across_separate_serial_receipts(): void
    {
        $f = $this->fixture([['A', 3, '0.33333333']]);
        $this->assertSame('-1.0000', $f['shipments'][0]['outbound']->cost_amount);
        $return = $this->createReturn($f, 3);
        foreach (array_reverse($f['shipments'][0]['serials']) as $serial) {
            $receipt = $this->receive($f, $return, ['restock' => [$serial->id]], 'A');
            app(SalesReturnApplicationService::class)->postReceipt($receipt->id, 1, '测试');
        }
        $this->assertSame('1.0000', $f['shipments'][0]['balance']->fresh()->inventory_value);
        $this->assertSame('1.0000', number_format((float) SalesReturnSerialIdentity::where('sales_return_item_id', $return->items->first()->id)->sum('cost_amount_snapshot'), 4, '.', ''));
    }

    public function test_post_failure_rolls_back_balance_identity_allocation_and_events_together(): void
    {
        $f = $this->fixture([['A', 2, '10.0000']]);
        $return = $this->createReturn($f, 2);
        $receipt = $this->receive($f, $return, ['restock' => array_map(fn ($s) => $s->id, $f['shipments'][0]['serials'])], 'A');
        $this->app->instance(SalesReturnIdentityService::class, new class extends SalesReturnIdentityService {
            private int $calls = 0;
            public function completeRestock(int $identityId, InventoryTransactionItem $transactionItem, ?int $operatorId): void
            {
                parent::completeRestock($identityId, $transactionItem, $operatorId);
                if (++$this->calls === 2) throw ValidationException::withMessages(['serial_dispositions' => '第二件库存事实验证失败']);
            }
        });
        $this->assertRejected(fn () => app(SalesReturnApplicationService::class)->postReceipt($receipt->id, 1, '测试'), 'serial_dispositions');
        $this->assertSame(0.0, (float) $f['shipments'][0]['balance']->fresh()->quantity_on_hand);
        $this->assertSame(0.0, (float) SalesReturnCostAllocation::where('sales_return_item_id', $return->items->first()->id)->sum('posted_base_qty'));
        $this->assertSame(0, SalesReturnSerialIdentity::where('sales_return_item_id', $return->items->first()->id)->whereNotNull('inventory_transaction_item_id')->count());
        $this->assertSame(0, InventoryTransaction::where('source_type', 'sales_return_receipt')->where('source_id', $receipt->id)->count());
        $this->assertSame(0, InventorySerialEvent::where('document_type', 'sales_return_receipt')->where('document_id', $receipt->id)->where('event_type', 'sales_return_inbound')->count());
        $this->assertSame(['sales_return_restock_pending'], InventorySerial::whereIn('id', array_map(fn ($s) => $s->id, $f['shipments'][0]['serials']))->pluck('serial_status')->unique()->values()->all());
    }

    public function test_returned_serial_can_be_sold_and_returned_again_under_a_new_outbound_fact(): void
    {
        $f = $this->fixture([['A', 1, '10.0000']]);
        $serial = $f['shipments'][0]['serials'][0];
        $return = $this->createReturn($f, 1);
        $receipt = $this->receive($f, $return, ['restock' => [$serial->id]], 'A');
        app(SalesReturnApplicationService::class)->postReceipt($receipt->id, 1, '测试');
        [$order, $orderLine] = $this->order($f['item'], $f['unit'], 1);
        $next = array_merge($f, ['order' => $order, 'line' => $orderLine]);
        $balance = $f['shipments'][0]['balance']->fresh();
        $balance->update(['quantity_locked' => 1, 'quantity_available' => 0]);
        InventoryLocationBalance::where('item_id', $f['item']->id)->where('warehouse_id', $f['warehouse']->id)->where('location_id', $f['location']->id)->update(['quantity_locked' => 1, 'quantity_available' => 0]);
        $newShipment = $this->ship($next, $balance, [$serial->fresh()]);
        $this->assertSame('shipped', $serial->fresh()->serial_status);
        $nextReturn = $this->createReturn($next, 1);
        $candidates = app(SalesReturnIdentityService::class)->candidates($nextReturn->id, $nextReturn->items->first()->id);
        $this->assertSame(1, $candidates->total());
        $this->assertSame($newShipment['outbound']->id, $candidates->items()[0]->outbound_transaction_item_id);
        $nextReceipt = $this->receive($next, $nextReturn, ['restock' => [$serial->id]], 'A');
        app(SalesReturnApplicationService::class)->postReceipt($nextReceipt->id, 1, '测试');
        $this->assertSame(2, SalesReturnSerialIdentity::where('inventory_serial_id', $serial->id)->distinct()->count('outbound_transaction_item_id'));
        $this->assertSame('available', $serial->fresh()->serial_status);
        $this->assertSame(1.0, (float) $balance->fresh()->quantity_on_hand);
    }

    public function test_historical_serial_tracking_cannot_be_bypassed_by_disabling_current_item_tracking(): void
    {
        $f = $this->fixture([['A', 1, '10.0000']]);
        $return = $this->createReturn($f, 1);
        $f['item']->update(['serial_tracking_mode' => 'none', 'is_serial_managed' => false]);
        $this->assertTrue(app(SalesReturnIdentityService::class)->requiresIdentities($return->items->first()->fresh()));
        $row = $this->row($f, $return, ['restock' => [$f['shipments'][0]['serials'][0]->id]], 'A'); unset($row['serial_dispositions']);
        $this->assertRejected(fn () => app(SalesReturnApplicationService::class)->receive(['sales_return_id' => $return->id, 'items' => [$row]], 1, '测试'), 'serial_dispositions');
    }

    private function fixture(array $sources): array
    {
        $tag = Str::upper(Str::random(10));
        $unit = Unit::create(['unit_code' => 'SRU-'.$tag, 'unit_name' => '台', 'unit_type' => 'count', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => 'SRW-'.$tag, 'warehouse_name' => '序列退货仓', 'status' => 'enabled']);
        $location = Location::create(['location_code' => 'SRL-'.$tag, 'location_name' => '序列退货库位', 'warehouse_id' => $warehouse->id, 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'SRI-'.$tag, 'item_name' => '序列控制柜', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_serial_managed' => true, 'serial_tracking_mode' => 'required', 'status' => 'enabled']);
        [$order, $line] = $this->order($item, $unit, array_sum(array_column($sources, 1)));
        $fixture = compact('unit', 'warehouse', 'location', 'item', 'order', 'line');
        $fixture['shipments'] = [];
        foreach ($sources as [$batch, $quantity, $cost]) {
            $total = round($quantity * (float) $cost, 4);
            $balance = InventoryBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $batch,
                'unit_id' => $unit->id, 'quantity_on_hand' => $quantity, 'quantity_locked' => $quantity, 'quantity_available' => 0, 'average_unit_cost' => $cost, 'inventory_value' => $total]);
            $locationBalance = InventoryLocationBalance::firstOrCreate(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id], ['unit_id' => $unit->id, 'quantity_on_hand' => 0, 'quantity_locked' => 0, 'quantity_available' => 0]);
            $locationBalance->increment('quantity_on_hand', $quantity); $locationBalance->increment('quantity_locked', $quantity);
            $serials = [];
            for ($i = 0; $i < $quantity; $i++) $serials[] = InventorySerial::create(['serial_no' => 'SRS-'.$tag.'-'.$batch.'-'.$i, 'inventory_balance_id' => $balance->id,
                'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $batch, 'serial_status' => 'available']);
            $fixture['shipments'][] = $this->ship($fixture, $balance, $serials);
        }
        return $fixture;
    }

    private function order(Item $item, Unit $unit, int $quantity): array
    {
        $order = SalesOrder::create(['sales_order_no' => 'SRO-'.Str::upper(Str::random(12)), 'customer_name' => '退货客户', 'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'shipment_status' => 'shipped']);
        $line = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'item_id' => $item->id, 'item_name' => $item->item_name, 'line_type' => 'physical',
            'order_qty' => $quantity, 'shipped_qty' => $quantity, 'fulfillment_factor_snapshot' => 1, 'item_base_unit_id' => $unit->id, 'item_base_required_qty' => $quantity,
            'item_snapshot' => ['id' => $item->id, 'item_code' => $item->item_code], 'fulfillment_type' => 'inventory']);
        return [$order, $line];
    }

    private function ship(array $f, InventoryBalance $balance, array $serials): array
    {
        $quantity = count($serials);
        $reservation = InventoryReservation::create(['source_type' => 'sales_order', 'source_order_id' => $f['order']->id, 'source_order_line_id' => $f['line']->id,
            'inventory_balance_id' => $balance->id, 'item_id' => $f['item']->id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
            'batch_no' => $balance->batch_no, 'reserved_qty' => $quantity, 'reservation_status' => 'converted_to_shipment']);
        $shipment = SalesShipment::create(['shipment_no' => 'SRSHP-'.Str::upper(Str::random(12)), 'sales_order_id' => $f['order']->id, 'shipment_status' => 'confirmed']);
        $shipmentLine = SalesShipmentLine::create(['shipment_id' => $shipment->id, 'sales_order_line_id' => $f['line']->id, 'inventory_reservation_id' => $reservation->id,
            'item_id' => $f['item']->id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no,
            'unit_id' => $f['unit']->id, 'sales_qty' => $quantity, 'base_qty' => $quantity, 'line_status' => 'confirmed',
            'serial_snapshot' => ['inventory_serial_ids' => array_map(fn ($serial) => $serial->id, $serials)]]);
        $transaction = app(InventoryService::class)->postSalesShipment($shipment, '测试');
        $shipment->update(['shipment_status' => 'shipped']); $shipmentLine->update(['line_status' => 'outbound_posted']);
        $outbound = $transaction->items->first();
        return compact('balance', 'serials', 'shipment', 'shipmentLine', 'outbound');
    }

    private function createReturn(array $f, int $quantity): SalesReturn
    {
        $return = app(SalesReturnApplicationService::class)->create(['sales_order_id' => $f['order']->id, 'return_reason' => '序列退货',
            'items' => [['sales_order_line_id' => $f['line']->id, 'requested_sales_qty' => $quantity]]], 1, '测试');
        return app(SalesReturnApplicationService::class)->confirm($return->id, 1, '测试');
    }

    private function row(array $f, SalesReturn $return, array $dispositions, string $batch): array
    {
        $row = ['sales_return_item_id' => $return->items->first()->id, 'received_base_qty' => array_sum(array_map('count', $dispositions)),
            'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id, 'batch_no' => $batch, 'serial_dispositions' => $dispositions];
        foreach (['restock', 'pending', 'scrap', 'rejected'] as $key) $row[$key.'_base_qty'] = count($dispositions[$key] ?? []);
        return $row;
    }

    private function receive(array $f, SalesReturn $return, array $dispositions, string $batch): SalesReturnReceipt
    {
        return app(SalesReturnApplicationService::class)->receive(['sales_return_id' => $return->id, 'items' => [$this->row($f, $return, $dispositions, $batch)]], 1, '测试');
    }

    private function assertRejected(callable $callback, string $field): void
    {
        try { $callback(); $this->fail('预期业务拒绝，但操作成功'); }
        catch (ValidationException $error) { $this->assertArrayHasKey($field, $error->errors()); }
    }
}
