<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryQualityEvent;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\PurchaseSettlementSource;
use App\Models\Erp\Supplier;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\InventoryService;
use App\Services\Erp\PurchaseDefectApplicationService;
use App\Services\Erp\PurchaseExchangeApplicationService;
use App\Services\Erp\PurchaseReceiptSettlementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseDefectFourWorkflowsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_draft_receipt_cannot_create_defect_handling(): void
    {
        [, $line] = $this->receiptFixture('draft');

        $this->expectException(ValidationException::class);
        app(PurchaseDefectApplicationService::class)->create(
            $this->payload($line, 'repair'),
            1,
            '测试管理员',
        );
    }

    public function test_exchange_repair_concession_and_scrap_close_quantity_amount_and_inventory(): void
    {
        [$receipt, $line] = $this->receiptFixture('confirmed');
        $service = app(PurchaseDefectApplicationService::class);

        $exchange = $service->create($this->payload($line, 'exchange'), 1, '测试管理员');
        $repair = $service->create($this->payload($line, 'repair'), 1, '测试管理员');
        $concession = $service->create($this->payload($line, 'concession'), 1, '测试管理员');
        $scrap = $service->create($this->payload($line, 'scrap'), 1, '测试管理员');

        $concession = $service->action($concession->id, 'approve_concession', [
            'result_description' => '尺寸偏差不影响使用，审批让步接收。',
        ], '质量负责人');
        $this->assertSame('concession_completed', $concession->current_step);

        $repair = $service->action($repair->id, 'start_repair', [], '维修负责人');
        $this->assertSame('repair_in_progress', $repair->current_step);
        $repair = $service->action($repair->id, 'complete_repair', [
            'result_description' => '更换损坏接头，耐压复检合格。',
        ], '质量负责人');
        $this->assertSame('repair_completed', $repair->current_step);

        $scrap = $service->action($scrap->id, 'approve_scrap', [], '质量负责人');
        $this->assertSame('scrap_pending_confirmation', $scrap->current_step);
        $scrap = $service->action($scrap->id, 'confirm_scrap', [
            'result_description' => '实物已破坏标识并移交报废区，供应商承担损失。',
        ], '仓库负责人');
        $this->assertSame('scrap_completed', $scrap->current_step);

        $exchangeService = app(PurchaseExchangeApplicationService::class);
        $exchangeOrder = $exchange->exchangeOrder;
        $this->assertSame('receipt_inspection', $exchangeOrder->source_scope);
        $this->assertNull($exchangeOrder->return_warehouse_name);
        $this->assertNull($exchangeOrder->return_location_name);
        $exchangeOrder = $exchangeService->action($exchangeOrder->id, 'register_original_return', [
            'returned_base_qty' => 1,
            'return_logistics_company' => '测试物流',
            'return_tracking_no' => 'RTN-DEFECT-001',
            'original_serial_numbers' => [],
        ], '采购负责人');
        $exchangeOrder = $exchangeService->action($exchangeOrder->id, 'confirm_supplier_receipt', [
            'supplier_receiver' => '供应商收货人',
        ], '采购负责人');
        $exchangeOrder = $exchangeService->action($exchangeOrder->id, 'confirm_replacement_shipment', [
            'replacement_shipped_date' => now()->toDateString(),
            'replacement_logistics_company' => '测试物流',
            'replacement_tracking_no' => 'RPL-DEFECT-001',
            'replacement_expected_date' => now()->addDay()->toDateString(),
        ], '采购负责人');
        $replacement = $exchangeOrder->replacementReceipt;
        $replacementLine = $replacement->items()->firstOrFail();
        $replacementLine->update([
            'qualified_qty' => 1,
            'unqualified_qty' => 0,
            'qualified_base_qty' => 1,
            'unqualified_base_qty' => 0,
        ]);
        $replacement->update([
            'receipt_status' => 'confirmed',
            'confirm_status' => 'confirmed',
            'total_qualified_qty' => 1,
            'total_unqualified_qty' => 0,
        ]);
        app(PurchaseReceiptSettlementService::class)->refresh($replacement);
        $exchangeService->syncReplacementReceiptConfirmed($replacement->fresh(), '仓库负责人');
        app(InventoryService::class)->postPurchaseReceipt($replacement->id);
        $exchangeOrder = $exchangeService->action($exchangeOrder->id, 'confirm_replacement_acceptance', [
            'result_description' => '替换品已到货，验收合格。',
        ], '质量负责人');
        $exchange = $exchange->fresh();
        $this->assertSame('exchange_completed', $exchange->current_step);
        $this->assertSame('completed', $exchangeOrder->current_step);
        $this->assertSame(0.0, (float) $exchangeOrder->exchange_additional_payable_amount);

        try {
            $service->create($this->payload($line->fresh(), 'repair'), 1, '测试管理员');
            $this->fail('四类处理完成后不得重复占用同一不合格数量');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('handling_qty', $exception->errors());
        }

        $receipt = app(PurchaseReceiptSettlementService::class)->refresh($receipt);
        $line = $receipt->items->first();
        $this->assertSame(3.0, (float) $line->qualified_base_qty);
        $this->assertSame(2.0, (float) $line->unqualified_base_qty);
        $this->assertSame(400.0, (float) $receipt->qualified_payable_amount);
        $this->assertSame(0.0, (float) $receipt->quality_hold_amount);
        $this->assertSame(100.0, (float) $receipt->rejected_claim_amount);
        $this->assertSame(300.0, (float) $line->inventory_cost_amount);

        // Each defect action refreshes the receipt settlement fact, then the
        // finance bridge. Finance sees the final eligible amount only; the
        // zero-payable replacement receipt never becomes a second payable.
        $source = PurchaseSettlementSource::query()
            ->where('source_receipt_id', $receipt->id)
            ->where('source_line_id', $line->id)
            ->sole();
        $this->assertSame('400.0000', (string) $source->eligible_amount);
        $this->assertSame('0.0000', (string) $source->frozen_amount);

        $replacement = $replacement->fresh(['items']);
        $this->assertSame(0.0, (float) $replacement->qualified_payable_amount);
        $this->assertSame(0.0, (float) $replacement->quality_hold_amount);
        $this->assertSame(100.0, (float) $replacement->items->first()->inventory_cost_amount);
        $this->assertDatabaseMissing('erp_purchase_settlement_sources', [
            'source_receipt_id' => $replacement->id,
        ]);

        app(InventoryService::class)->postPurchaseReceipt($receipt->id);
        $this->assertSame(4.0, (float) InventoryBalance::sum('quantity_on_hand'));
        $this->assertSame(400.0, (float) InventoryBalance::sum('inventory_value'));

        $this->assertDatabaseHas('erp_purchase_exchange_logs', [
            'exchange_order_id' => $exchangeOrder->id,
            'action' => 'confirm_replacement_acceptance',
        ]);
        $this->assertDatabaseHas('erp_purchase_defect_handling_logs', [
            'handling_id' => $repair->id,
            'action' => 'complete_repair',
        ]);
        $this->assertDatabaseHas('erp_purchase_defect_handling_logs', [
            'handling_id' => $concession->id,
            'action' => 'approve_concession',
        ]);
        $this->assertDatabaseHas('erp_purchase_defect_handling_logs', [
            'handling_id' => $scrap->id,
            'action' => 'confirm_scrap',
        ]);
    }

    public function test_inventory_quality_exchange_inherits_the_locked_original_serial(): void
    {
        [$receipt, $line, $warehouse, $location] = $this->receiptFixture('confirmed');
        $line->item()->update(['serial_tracking_mode' => 'required', 'is_serial_managed' => true]);
        $balance = InventoryBalance::create([
            'item_id' => $line->item_id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'batch_no' => $line->batch_no,
            'unit_id' => $line->base_unit_id,
            'quantity_on_hand' => 1,
            'quantity_available' => 1,
            'average_unit_cost' => 100,
            'inventory_value' => 100,
        ]);
        $event = InventoryQualityEvent::create([
            'event_no' => 'IQE-SERIAL-001',
            'inventory_balance_id' => $balance->id,
            'item_id' => $line->item_id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'batch_no' => $line->batch_no,
            'serial_no' => 'SN-LOCKED-001',
            'source_receipt_id' => $receipt->id,
            'source_receipt_item_id' => $line->id,
            'supplier_id' => $receipt->supplier_id,
            'unit_id' => $line->base_unit_id,
            'unit_name_snapshot' => $line->base_unit_name_snapshot,
            'issue_qty' => 1,
            'issue_category' => 'function_failure',
            'issue_description' => '锁定具体设备编号后发起换货。',
            'handling_method' => 'exchange',
            'responsible_party' => 'supplier',
            'event_status' => 'pending_exchange',
        ]);

        $service = app(PurchaseExchangeApplicationService::class);
        $exchange = $service->createFromInventoryQuality($event, $balance, $line, '测试管理员');
        $service->createFromInventoryQuality($event, $balance, $line, '测试管理员');

        $this->assertDatabaseHas('erp_purchase_exchange_serial_links', [
            'exchange_order_id' => $exchange->id,
            'original_serial_no' => 'SN-LOCKED-001',
            'original_return_status' => 'pending',
        ]);
        $this->assertSame(1, $exchange->serialLinks()->count());
    }

    private function payload(PurchaseReceiptItem $line, string $method): array
    {
        return [
            'receipt_item_id' => $line->id,
            'handling_method' => $method,
            'handling_qty' => 1,
            'defect_reason' => '到货检验不合格',
            'defect_description' => "用于验证 {$method} 完整状态链路",
            'responsible_party' => '供应商',
            'remark' => '自动化业务场景测试',
        ];
    }

    private function receiptFixture(string $confirmStatus): array
    {
        $unit = Unit::create([
            'unit_code' => 'PCS-DEFECT', 'unit_name' => '台', 'unit_type' => 'count',
            'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled',
        ]);
        $supplier = Supplier::create([
            'supplier_code' => 'SUP-DEFECT', 'supplier_name' => '不合格品测试供应商',
            'supplier_type' => 'manufacturer', 'status' => 'enabled',
        ]);
        $warehouse = Warehouse::create([
            'warehouse_code' => 'WH-DEFECT', 'warehouse_name' => '不合格品测试仓', 'status' => 'enabled',
        ]);
        $location = Location::create([
            'location_code' => 'LOC-DEFECT', 'location_name' => '不合格品测试库位',
            'warehouse_id' => $warehouse->id, 'status' => 'enabled',
        ]);
        $item = Item::create([
            'item_code' => 'ITEM-DEFECT', 'item_name' => '可追溯测试设备',
            'item_type' => 'finished_goods', 'unit_id' => $unit->id,
            'is_purchase_item' => true, 'is_stock_item' => true, 'status' => 'enabled',
        ]);
        $receipt = PurchaseReceipt::create([
            'receipt_no' => 'PRC-DEFECT-'.strtoupper($confirmStatus),
            'supplier_id' => $supplier->id,
            'receipt_date' => now()->toDateString(),
            'receipt_status' => $confirmStatus,
            'confirm_status' => $confirmStatus,
            'stock_post_status' => 'pending',
            'total_receipt_qty' => 5,
            'total_qualified_qty' => 1,
            'total_unqualified_qty' => 4,
            'total_amount' => 500,
        ]);
        $line = PurchaseReceiptItem::create([
            'receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'purchase_unit_id' => $unit->id,
            'purchase_unit_name_snapshot' => '台',
            'conversion_factor_snapshot' => 1,
            'base_unit_id' => $unit->id,
            'base_unit_name_snapshot' => '台',
            'receipt_qty' => 5,
            'qualified_qty' => 1,
            'unqualified_qty' => 4,
            'standard_base_qty' => 5,
            'actual_base_qty' => 5,
            'qualified_base_qty' => 1,
            'unqualified_base_qty' => 4,
            'is_stock_item_snapshot' => true,
            'quality_fact_origin' => 'original_inspection',
            'original_received_qty' => 5,
            'original_qualified_qty' => 1,
            'original_unqualified_qty' => 4,
            'original_received_base_qty' => 5,
            'original_qualified_base_qty' => 1,
            'original_unqualified_base_qty' => 4,
            'final_stockable_base_qty' => 1,
            'physical_received_base_qty' => 5,
            'contract_fulfilled_base_qty' => 5,
            'currency_snapshot' => 'CNY',
            'tax_mode_snapshot' => 'tax_included',
            'amount_excl_tax' => 500,
            'tax_amount_snapshot' => 0,
            'amount_incl_tax' => 500,
            'finance_fact_status' => 'frozen',
            'facts_frozen_at' => now(),
            'unit_price' => 100,
            'receipt_cost' => 500,
            'batch_no' => 'B-DEFECT-001',
            'inventory_posting_status' => 'pending',
        ]);
        app(PurchaseReceiptSettlementService::class)->refresh($receipt);

        return [$receipt->fresh(), $line->fresh(), $warehouse, $location];
    }
}
