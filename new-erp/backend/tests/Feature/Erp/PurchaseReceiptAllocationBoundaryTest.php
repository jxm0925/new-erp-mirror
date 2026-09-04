<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchaseOrderItem;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\Supplier;
use App\Models\Erp\Unit;
use App\Services\Erp\PurchaseReceiptApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseReceiptAllocationBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_open_draft_blocks_duplicate_generation_and_is_exposed_to_ui(): void
    {
        [$order] = $this->fixture();
        $service = app(PurchaseReceiptApplicationService::class);

        $receipt = $service->generateFromOrder($order->id, '测试采购员');
        $this->assertSame(10.0, (float) $receipt->total_receipt_qty);

        $decorated = $service->decorateOrder($order->fresh(['items']));
        $this->assertSame(1, $decorated->open_receipt_count);
        $this->assertSame(10.0, (float) $decorated->pending_receipt_qty);
        $this->assertSame(0.0, (float) $decorated->available_receipt_qty);
        $this->assertFalse($decorated->can_generate_receipt);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('已有未确认到货单');
        $service->generateFromOrder($order->id, '测试采购员');
    }

    public function test_confirmed_partial_receipt_reopens_only_actual_remainder(): void
    {
        [$order, $orderItem, $item, $supplier] = $this->fixture();
        $first = PurchaseReceipt::create([
            'receipt_no' => 'PRC-ALLOC-CONFIRMED', 'order_id' => $order->id, 'supplier_id' => $supplier->id,
            'receipt_status' => 'confirmed', 'confirm_status' => 'confirmed', 'settlement_mode' => 'normal',
        ]);
        PurchaseReceiptItem::create([
            'receipt_id' => $first->id, 'order_item_id' => $orderItem->id, 'item_id' => $item->id,
            'receipt_qty' => 4, 'qualified_qty' => 4, 'unit_price' => 20, 'receipt_cost' => 80,
        ]);
        $orderItem->update(['received_qty' => 4, 'remaining_qty' => 6, 'line_status' => 'partial']);
        $order->update(['purchase_status' => 'partially_received', 'receipt_status' => 'partial']);

        $second = app(PurchaseReceiptApplicationService::class)->generateFromOrder($order->id, '测试采购员');
        $this->assertSame(6.0, (float) $second->items()->sum('receipt_qty'));
    }

    public function test_replacement_receipt_does_not_consume_order_allocation(): void
    {
        [$order, $orderItem, $item, $supplier] = $this->fixture();
        $replacement = PurchaseReceipt::create([
            'receipt_no' => 'PRC-ALLOC-REPLACEMENT', 'order_id' => $order->id, 'supplier_id' => $supplier->id,
            'receipt_status' => 'draft', 'confirm_status' => 'draft', 'settlement_mode' => 'replacement_no_charge',
        ]);
        PurchaseReceiptItem::create([
            'receipt_id' => $replacement->id, 'order_item_id' => $orderItem->id, 'item_id' => $item->id,
            'receipt_qty' => 10, 'qualified_qty' => 10, 'unit_price' => 0, 'receipt_cost' => 0,
        ]);

        $normal = app(PurchaseReceiptApplicationService::class)->generateFromOrder($order->id, '测试采购员');
        $this->assertSame(10.0, (float) $normal->items()->sum('receipt_qty'));
    }

    public function test_replacement_receipt_source_and_commercial_fields_are_locked(): void
    {
        [$order, $orderItem, $item, $supplier] = $this->fixture();
        $replacement = PurchaseReceipt::create([
            'receipt_no' => 'PRC-LOCK-REPLACEMENT', 'order_id' => $order->id, 'supplier_id' => $supplier->id,
            'receipt_status' => 'draft', 'confirm_status' => 'draft', 'settlement_mode' => 'replacement_no_charge',
        ]);
        $line = PurchaseReceiptItem::create([
            'receipt_id' => $replacement->id, 'order_item_id' => $orderItem->id, 'item_id' => $item->id,
            'receipt_qty' => 2, 'qualified_qty' => 0, 'unqualified_qty' => 0,
            'purchase_unit_id' => $orderItem->purchase_unit_id, 'conversion_factor_snapshot' => 1,
            'base_unit_id' => $orderItem->base_unit_id, 'unit_price' => 20, 'tax_rate' => 13,
            'receipt_cost' => 40, 'batch_no' => 'BAT-LOCKED', 'data_source' => 'system',
        ]);

        $payload = app(PurchaseReceiptApplicationService::class)->protectReplacementDraft($replacement, [
            'supplier_id' => 999999,
            'items' => [[
                'id' => $line->id, 'item_id' => 999999, 'receipt_qty' => 999,
                'purchase_unit_id' => 999999, 'unit_price' => 0, 'tax_rate' => 0,
                'qualified_qty' => 2, 'unqualified_qty' => 0,
            ]],
        ]);

        $this->assertSame($supplier->id, $payload['supplier_id']);
        $this->assertSame($item->id, $payload['items'][0]['item_id']);
        $this->assertSame(2.0, (float) $payload['items'][0]['receipt_qty']);
        $this->assertSame(20.0, (float) $payload['items'][0]['unit_price']);
        $this->assertSame('BAT-LOCKED', $payload['items'][0]['batch_no']);
        $this->assertSame(2, $payload['items'][0]['qualified_qty']);
    }

    public function test_manual_draft_cannot_exceed_available_quantity(): void
    {
        [$order, $orderItem, $item, $supplier] = $this->fixture();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('超过可用剩余数量');
        app(PurchaseReceiptApplicationService::class)->assertDraftAllocation([
            'order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'items' => [[
                'order_item_id' => $orderItem->id,
                'item_id' => $item->id,
                'receipt_qty' => 11,
            ]],
        ]);
    }

    private function fixture(): array
    {
        $unit = Unit::create(['unit_code' => 'EA-ALLOC', 'unit_name' => '件', 'unit_type' => 'quantity', 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'ITEM-ALLOC', 'item_name' => '到货占用测试物料', 'item_type' => 'raw_material', 'unit_id' => $unit->id, 'is_purchase_item' => true, 'status' => 'enabled']);
        $supplier = Supplier::create(['supplier_code' => 'SUP-ALLOC', 'supplier_name' => '到货占用测试供应商', 'status' => 'enabled', 'approval_status' => 'approved']);
        $order = PurchaseOrder::create([
            'purchase_order_no' => 'PO-ALLOC-001', 'supplier_id' => $supplier->id,
            'purchase_status' => 'processing', 'audit_status' => 'approved', 'receipt_status' => 'not_received',
            'total_qty' => 10,
        ]);
        $orderItem = PurchaseOrderItem::create([
            'order_id' => $order->id, 'item_id' => $item->id, 'order_qty' => 10,
            'received_qty' => 0, 'remaining_qty' => 10, 'unit_price' => 20, 'amount' => 200,
            'purchase_unit_id' => $unit->id, 'base_unit_id' => $unit->id,
            'purchase_unit_name_snapshot' => '件', 'base_unit_name_snapshot' => '件',
            'conversion_factor_snapshot' => 1, 'purchase_qty' => 10, 'planned_base_qty' => 10,
        ]);

        return [$order, $orderItem, $item, $supplier];
    }
}
