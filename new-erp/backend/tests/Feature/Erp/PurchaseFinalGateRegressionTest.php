<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryQualityEvent;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\Supplier;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\PurchaseReceiptConfirmationApplicationService;
use App\Services\Erp\PurchaseReturnApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseFinalGateRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_p01_nonstock_receipt_is_fulfilled_without_inventory_posting(): void
    {
        [$unit, $supplier] = $this->masters();
        $item = Item::create([
            'item_code' => $this->code('NS'), 'item_name' => '非库存办公用品测试', 'item_type' => 'consumable',
            'unit_id' => $unit->id, 'is_purchase_item' => true, 'is_stock_item' => false,
            'serial_tracking_mode' => 'none', 'status' => 'enabled',
        ]);
        $receipt = PurchaseReceipt::create([
            'receipt_no' => $this->code('PRC-NS'), 'supplier_id' => $supplier->id,
            'receipt_date' => now()->toDateString(), 'receipt_status' => 'draft', 'confirm_status' => 'draft',
            'stock_post_status' => 'pending', 'total_receipt_qty' => 2, 'total_qualified_qty' => 2,
            'total_unqualified_qty' => 0, 'total_amount' => 20, 'remark' => '非库存手工到货自动化验收',
        ]);
        $line = PurchaseReceiptItem::create([
            'receipt_id' => $receipt->id, 'item_id' => $item->id,
            'purchase_unit_id' => $unit->id, 'purchase_unit_name_snapshot' => '件',
            'conversion_factor_snapshot' => 1, 'base_unit_id' => $unit->id, 'base_unit_name_snapshot' => '件',
            'receipt_qty' => 2, 'qualified_qty' => 2, 'unqualified_qty' => 0,
            'standard_base_qty' => 2, 'actual_base_qty' => 2, 'qualified_base_qty' => 2, 'unqualified_base_qty' => 0,
            'unit_price' => 10, 'receipt_cost' => 20, 'tax_rate' => 0,
        ]);

        $confirmed = app(PurchaseReceiptConfirmationApplicationService::class)->confirm($receipt->id, 1, '门禁测试');

        $this->assertSame('not_required', $confirmed->stock_post_status);
        $this->assertSame('fulfilled', $confirmed->fulfillment_status);
        $this->assertFalse((bool) $line->fresh()->is_stock_item_snapshot);
        $this->assertSame('not_required', $line->fresh()->inventory_posting_status);
        $this->assertSame(0, $line->allocations()->count());
        $this->assertFalse(InventoryTransaction::where('source_type', 'purchase_receipt')->where('source_id', $receipt->id)->exists());
        $this->assertDatabaseHas('erp_purchase_settlement_sources', [
            'source_receipt_id' => $receipt->id,
            'source_line_id' => $line->id,
            'eligible_amount' => '20.0000',
            'status' => 'open',
        ]);
    }

    public function test_p08_serial_quality_return_creates_return_item_before_serial_link(): void
    {
        [$unit, $supplier] = $this->masters();
        $warehouse = Warehouse::create(['warehouse_code' => $this->code('WH'), 'warehouse_name' => '门禁仓', 'status' => 'enabled']);
        $location = Location::create(['location_code' => $this->code('LOC'), 'location_name' => '门禁库位', 'warehouse_id' => $warehouse->id, 'status' => 'enabled']);
        $item = Item::create([
            'item_code' => $this->code('SER'), 'item_name' => '序列号质量退货设备', 'item_type' => 'finished_goods',
            'unit_id' => $unit->id, 'is_purchase_item' => true, 'is_stock_item' => true,
            'is_serial_managed' => true, 'serial_tracking_mode' => 'required', 'status' => 'enabled',
        ]);
        $receipt = PurchaseReceipt::create([
            'receipt_no' => $this->code('PRC-SER'), 'supplier_id' => $supplier->id,
            'receipt_date' => now()->toDateString(), 'receipt_status' => 'confirmed', 'confirm_status' => 'confirmed',
            'stock_post_status' => 'posted', 'total_receipt_qty' => 1, 'total_qualified_qty' => 1,
            'total_unqualified_qty' => 0, 'total_amount' => 100, 'currency_snapshot' => 'CNY',
        ]);
        $line = PurchaseReceiptItem::create([
            'receipt_id' => $receipt->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'purchase_unit_id' => $unit->id, 'purchase_unit_name_snapshot' => '台', 'conversion_factor_snapshot' => 1,
            'base_unit_id' => $unit->id, 'base_unit_name_snapshot' => '台', 'receipt_qty' => 1,
            'qualified_qty' => 1, 'unqualified_qty' => 0, 'standard_base_qty' => 1, 'actual_base_qty' => 1,
            'qualified_base_qty' => 1, 'original_received_base_qty' => 1, 'original_qualified_base_qty' => 1,
            'final_stockable_base_qty' => 1, 'physical_received_base_qty' => 1, 'contract_fulfilled_base_qty' => 1,
            'is_stock_item_snapshot' => true, 'currency_snapshot' => 'CNY', 'tax_mode_snapshot' => 'tax_included',
            'amount_excl_tax' => 100, 'tax_amount_snapshot' => 0, 'amount_incl_tax' => 100,
            'finance_fact_status' => 'frozen', 'facts_frozen_at' => now(), 'unit_price' => 100, 'receipt_cost' => 100,
            'batch_no' => $this->code('BAT'), 'inventory_posting_status' => 'posted',
        ]);
        $balance = InventoryBalance::create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => $line->batch_no, 'unit_id' => $unit->id, 'quantity_on_hand' => 1,
            'quantity_available' => 1, 'average_unit_cost' => 100, 'inventory_value' => 100,
        ]);
        $tx = InventoryTransaction::create([
            'transaction_no' => $this->code('ITX'), 'transaction_type' => 'purchase_receipt', 'source_type' => 'purchase_receipt',
            'source_id' => $receipt->id, 'source_no' => $receipt->receipt_no, 'posting_status' => 'posted',
            'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'transaction_date' => now()->toDateString(), 'posted_at' => now(),
        ]);
        InventoryTransactionItem::create([
            'transaction_id' => $tx->id, 'transaction_no' => $tx->transaction_no, 'item_id' => $item->id,
            'item_code' => $item->item_code, 'item_name' => $item->item_name, 'warehouse_id' => $warehouse->id,
            'location_id' => $location->id, 'batch_no' => $line->batch_no, 'unit_id' => $unit->id,
            'change_qty' => 1, 'balance_after_qty' => 1, 'source_type' => 'purchase_receipt',
            'source_id' => $receipt->id, 'source_item_id' => $line->id,
        ]);
        $serial = InventorySerial::create([
            'serial_no' => $this->code('SN'), 'inventory_balance_id' => $balance->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $line->batch_no,
            'origin_type' => 'purchase', 'number_source' => 'supplier', 'serial_status' => 'available',
            'source_receipt_id' => $receipt->id, 'source_receipt_item_id' => $line->id, 'supplier_id' => $supplier->id,
        ]);
        $event = InventoryQualityEvent::create([
            'event_no' => $this->code('IQE'), 'inventory_balance_id' => $balance->id, 'inventory_serial_id' => $serial->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => $line->batch_no, 'serial_no' => $serial->serial_no, 'source_receipt_id' => $receipt->id,
            'source_receipt_item_id' => $line->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id,
            'unit_name_snapshot' => '台', 'issue_qty' => 1, 'issue_category' => 'function_failure',
            'issue_description' => '序列号设备入库后质量退货回归', 'handling_method' => 'return',
            'responsible_party' => 'supplier', 'event_status' => 'pending_return',
        ]);

        $return = app(PurchaseReturnApplicationService::class)->createFromInventoryQuality($event, $balance, $line, 1, '门禁测试');
        $returnItem = $return->items->first();

        $this->assertNotNull($returnItem);
        $this->assertDatabaseHas('erp_purchase_return_item_serials', [
            'purchase_return_item_id' => $returnItem->id, 'inventory_serial_id' => $serial->id, 'serial_no' => $serial->serial_no,
        ]);
    }

    private function masters(): array
    {
        $unit = Unit::create(['unit_code' => $this->code('U'), 'unit_name' => '件', 'unit_type' => 'count', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $supplier = Supplier::create(['supplier_code' => $this->code('SUP'), 'supplier_name' => '采购封板测试供应商', 'supplier_type' => 'manufacturer', 'status' => 'enabled']);
        return [$unit, $supplier];
    }

    private function code(string $prefix): string
    {
        return $prefix.'-'.strtoupper(substr((string) Str::ulid(), -10));
    }
}
