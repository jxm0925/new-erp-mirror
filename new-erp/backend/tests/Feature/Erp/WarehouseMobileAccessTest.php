<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\{SalesOrder, SalesOrderLine, SalesReturn, SalesReturnItem, SalesShipment};
use App\Services\Erp\{CuttingConfirmationService, WarehouseCommandService, WarehouseDocumentService, WarehouseWorkspaceService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseMobileAccessTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Support\CuttingTestFixtures;

    public function test_view_only_cutting_product_preserves_existing_cost_fact_without_write_actions(): void
    {
        $f = $this->fixture();
        $batchId = $this->issue($f)['settlement_batch_id'];
        $result = $this->save($f, $batchId, '5')['result_ids'][0];
        $route = $this->route($f, $result, '5')['routes'][0]['id'];
        app(CuttingConfirmationService::class)->confirm($batchId, $this->confirmation($f, $batchId, $result, '3000'), $f['user'], self::PERMISSIONS, true);
        $read = app(WarehouseDocumentService::class)->show('cutting_product', $route, [], $f['user'], ['production.cutting.view'], true);
        $this->assertSame([], $read['actions']);
        $this->assertSame('5.00000000', $read['header']['remaining_qty']);
        $this->assertEquals(3000, $read['header']['material_total_cost']);
    }

    public function test_formal_cutting_receipt_preserves_existing_cost_fact_and_is_read_only(): void
    {
        $f = $this->fixture();
        $receipt = $this->warehouseStock($f, '5');
        $read = app(WarehouseDocumentService::class)->show('cutting_product', $receipt['route_id'], ['receipt_id' => $receipt['receipt_id']],
            $f['user'], ['production.cutting.view'], true);
        $this->assertSame([], $read['actions']);
        $formalReceipt = (array) $read['receipt'];
        $this->assertEquals($receipt['receipt_id'], $formalReceipt['id']);
        $this->assertEquals($receipt['posted_cost'], $formalReceipt['posted_cost']);
    }

    public function test_schema_rejection_has_durable_failed_result_for_lost_response_recovery(): void
    {
        $f = $this->fixture();
        $receiptId = PurchaseReceiptItem::where('item_id', $f['raw']->id)->value('receipt_id');
        $command = (string) Str::uuid();
        $service = app(WarehouseCommandService::class);
        $before = DB::table('erp_inventory_transactions')->count();
        try {
            $service->run('purchase.allocate', $receiptId, $command, ['items' => []], $f['user'], ['inventory.post.repair'], true);
            $this->fail('Empty allocation must fail validation.');
        } catch (WorkOrderDomainException $error) {
            $this->assertSame('validation_error', $error->errorCode);
        }
        $result = $service->result($command, $f['user'], ['inventory.post.repair'], true);
        $this->assertSame('FAILED', $result['status']);
        $this->assertSame(422, $result['response']['status']);
        $this->assertSame($before, DB::table('erp_inventory_transactions')->count());
    }

    public function test_sales_document_hides_amounts_but_keeps_quantities_and_owned_document(): void
    {
        [$f, $order, $return] = $this->salesFixture();
        $service = app(WarehouseDocumentService::class);
        $read = $service->show('sales_return', $return->id, ['stage' => 'receive'], $f['user'], ['sales_return.view'], false);
        $this->assertEquals($order->id, $read['header']['order']['id']);
        $this->assertSame([], $read['actions']);
        $this->assertArrayNotHasKey('total_amount', $read['header']['order']);
        $this->assertArrayNotHasKey('unit_price', $read['lines']['data'][0]['fulfillment_snapshot']);
        $this->assertSame('2.00000000', $read['lines']['data'][0]['remaining_receivable_qty']);
        $visible = $service->show('sales_return', $return->id, ['stage' => 'receive'], $f['user'], ['sales_return.view', 'sales_order.amount.view'], false);
        $this->assertEquals(987.65, $visible['header']['order']['total_amount']);
    }

    public function test_sales_command_replay_and_result_use_current_amount_permission(): void
    {
        [$f, $order, $return, $item] = $this->salesFixture();
        $service = app(WarehouseCommandService::class); $command = (string) Str::uuid();
        $payload = ['items' => [['sales_return_item_id' => $item->id, 'received_base_qty' => '1',
            'restock_base_qty' => '0', 'pending_base_qty' => '1', 'scrap_base_qty' => '0', 'rejected_base_qty' => '0']]];
        $write = ['sales_return.receive'];
        $first = $service->run('sales_return.receive', $return->id, $command, $payload, $f['user'], [...$write, 'sales_order.amount.view'], false);
        $this->assertEquals(987.65, $first['result']['sales_return']['order']['total_amount']);
        $replay = $service->run('sales_return.receive', $return->id, $command, $payload, $f['user'], $write, false);
        $this->assertArrayNotHasKey('total_amount', $replay['result']['sales_return']['order']);
        $queried = $service->result($command, $f['user'], $write, false);
        $this->assertSame('SUCCEEDED', $queried['status']);
        $this->assertArrayNotHasKey('total_amount', $queried['response']['result']['sales_return']['order']);
        $this->assertEquals($first['result']['id'], $replay['result']['id']);
        $this->assertSame(1, DB::table('erp_sales_return_receipts')->where('sales_return_id', $return->id)->count());
        $this->assertEquals(1, $item->fresh()->received_base_qty);
        $stored = json_decode(DB::table('erp_warehouse_commands')->where('client_command_id', $command)->value('response'), true);
        $this->assertEquals(987.65, $stored['result']['sales_return']['order']['total_amount']);
    }

    public function test_outbound_posted_shipment_remains_pending_dispatch_without_double_posting_count(): void
    {
        [$f, $order] = $this->salesFixture();
        $shipment = SalesShipment::create(['shipment_no' => 'W-SHIP-'.Str::ulid(), 'sales_order_id' => $order->id,
            'shipment_status' => 'outbound_posted', 'outbound_posted_at' => now()]);
        $service = app(WarehouseWorkspaceService::class); $permissions = ['sales_order.shipment.view'];
        $pending = $service->paginate(['kind' => 'sales_shipment'], $f['user'], $permissions, false);
        $this->assertSame([$shipment->id], array_column($pending['data'], 'id'));
        $this->assertSame('待发运', $pending['data'][0]['status_label']);
        $this->assertSame(1, $service->summary($f['user'], $permissions, false)['today_outbound']);
        $shipment->update(['shipment_status' => 'shipped', 'shipped_at' => now()]);
        $this->assertSame([], $service->paginate(['kind' => 'sales_shipment'], $f['user'], $permissions, false)['data']);
        $this->assertSame(1, $service->summary($f['user'], $permissions, false)['today_outbound']);
    }

    private function salesFixture(): array
    {
        $f = $this->fixture();
        $order = SalesOrder::create(['sales_order_no' => 'W-SALES-'.Str::ulid(), 'customer_name' => '仓库权限客户',
            'sales_user_legacy_id' => $f['user']->legacy_id, 'created_by_legacy_id' => $f['user']->legacy_id,
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'shipment_status' => 'shipped', 'total_amount' => '987.65']);
        $line = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'item_id' => $f['output']->id,
            'item_name' => $f['output']->item_name, 'line_type' => 'physical', 'order_qty' => 2, 'shipped_qty' => 2,
            'fulfillment_factor_snapshot' => 1, 'item_base_unit_id' => $f['output']->unit_id, 'item_base_required_qty' => 2,
            'item_snapshot' => ['id' => $f['output']->id], 'fulfillment_type' => 'inventory']);
        $return = SalesReturn::create(['return_no' => 'W-RETURN-'.Str::ulid(), 'sales_order_id' => $order->id,
            'return_date' => now()->toDateString(), 'return_status' => 'pending_receipt', 'return_reason' => '仓库权限测试']);
        $item = SalesReturnItem::create(['sales_return_id' => $return->id, 'sales_order_line_id' => $line->id,
            'item_id' => $f['output']->id, 'base_unit_id' => $f['output']->unit_id, 'requested_sales_qty' => 2,
            'requested_base_qty' => 2, 'fulfillment_snapshot' => ['item_id' => $f['output']->id, 'unit_price' => '493.825']]);
        return [$f, $order, $return, $item];
    }

    public function test_output_header_respects_existing_output_cost_permission(): void
    {
        $f = $this->fixture();
        $id = DB::table('erp_production_output_records')->insertGetId(['output_no' => 'W-OUT-'.Str::ulid(),
            'work_order_id' => $f['wo']->id, 'source_target_type' => 'quantity_operation', 'source_target_id' => $f['producerOperation'],
            'output_item_id' => $f['output']->id, 'output_base_qty' => 2, 'output_mode_snapshot' => 'warehouse_required',
            'quality_mode_snapshot' => 'none', 'status' => 'WAIT_WAREHOUSE', 'material_total_cost' => '123.4500',
            'material_loss_cost' => '6.7000', 'created_by_legacy_id' => $f['user']->legacy_id, 'produced_at' => now(),
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $service = app(WarehouseDocumentService::class);
        $hidden = $service->show('output', $id, [], $f['user'], ['production.task.view'], true);
        $this->assertArrayNotHasKey('material_total_cost', $hidden['header']);
        $this->assertArrayNotHasKey('material_loss_cost', $hidden['header']);
        $this->assertArrayNotHasKey('material_holding_id', $hidden['header']);
        $shown = $service->show('output', $id, [], $f['user'], ['production.task.view', 'production.output.cost.view'], true);
        $this->assertEquals(123.45, $shown['header']['material_total_cost']);
        $this->assertSame([], $shown['actions']);
    }
}
