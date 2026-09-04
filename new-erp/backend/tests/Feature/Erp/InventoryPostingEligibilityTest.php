<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\Supplier;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\PurchaseReceiptPostingEligibilityService;
use App\Services\Erp\PurchaseReceiptPostingRepairApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryPostingEligibilityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_missing_allocation_is_explained_and_can_be_repaired_without_changing_receipt_facts(): void
    {
        $suffix = strtoupper(substr((string) Str::ulid(), -8));
        $unit = Unit::create([
            'unit_code' => "U-POST-{$suffix}", 'unit_name' => '件', 'unit_type' => 'count',
            'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled',
        ]);
        $supplier = Supplier::create([
            'supplier_code' => "SUP-POST-{$suffix}", 'supplier_name' => '过账检查测试供应商',
            'supplier_type' => 'manufacturer', 'status' => 'enabled',
        ]);
        $warehouse = Warehouse::create([
            'warehouse_code' => "WH-POST-{$suffix}", 'warehouse_name' => '过账检查测试仓', 'status' => 'enabled',
        ]);
        $location = Location::create([
            'location_code' => "LOC-POST-{$suffix}", 'location_name' => '过账检查测试库位',
            'warehouse_id' => $warehouse->id, 'status' => 'enabled',
        ]);
        $item = Item::create([
            'item_code' => "ITEM-POST-{$suffix}", 'item_name' => '过账检查测试物料',
            'item_type' => 'raw_material', 'unit_id' => $unit->id,
            'is_purchase_item' => true, 'is_stock_item' => true,
            'serial_tracking_mode' => 'none', 'status' => 'enabled',
        ]);
        $receipt = PurchaseReceipt::create([
            'receipt_no' => "PRC-POST-{$suffix}", 'supplier_id' => $supplier->id,
            'receipt_date' => now()->toDateString(), 'receipt_status' => 'confirmed',
            'confirm_status' => 'confirmed', 'stock_post_status' => 'pending',
            'total_receipt_qty' => 3, 'total_qualified_qty' => 3, 'total_amount' => 36,
        ]);
        $line = PurchaseReceiptItem::create([
            'receipt_id' => $receipt->id, 'item_id' => $item->id,
            'purchase_unit_id' => $unit->id, 'purchase_unit_name_snapshot' => '件',
            'conversion_factor_snapshot' => 1, 'base_unit_id' => $unit->id,
            'base_unit_name_snapshot' => '件', 'receipt_qty' => 3,
            'qualified_qty' => 3, 'unqualified_qty' => 0,
            'standard_base_qty' => 3, 'actual_base_qty' => 3,
            'qualified_base_qty' => 3, 'unqualified_base_qty' => 0,
            'is_stock_item_snapshot' => true,
            'quality_fact_origin' => 'current',
            'original_received_qty' => 3,
            'original_qualified_qty' => 3,
            'original_unqualified_qty' => 0,
            'original_received_base_qty' => 3,
            'original_qualified_base_qty' => 3,
            'original_unqualified_base_qty' => 0,
            'final_stockable_base_qty' => 3,
            'physical_received_base_qty' => 3,
            'contract_fulfilled_base_qty' => 3,
            'unit_price' => 12, 'receipt_cost' => 36,
            'batch_no' => "BAT-POST-{$suffix}", 'inventory_posting_status' => 'pending',
        ]);

        $eligibility = app(PurchaseReceiptPostingEligibilityService::class);
        $before = $eligibility->evaluate($receipt);
        $this->assertFalse($before['can_post']);
        $this->assertStringContainsString('尚未分配仓库和库位', $before['reason_text']);

        app(PurchaseReceiptPostingRepairApplicationService::class)->repair($receipt->id, [[
            'receipt_item_id' => $line->id,
            'allocations' => [[
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'base_qty' => 3,
                'serial_nos' => [],
            ]],
        ]], '测试管理员');

        $receipt->refresh();
        $line->refresh();
        $after = $eligibility->evaluate($receipt);
        $this->assertTrue($after['can_post']);
        $this->assertSame('confirmed', $receipt->confirm_status);
        $this->assertSame('pending', $receipt->stock_post_status);
        $this->assertSame(3.0, (float) $line->qualified_base_qty);
        $this->assertSame(36.0, (float) $line->receipt_cost);
        $this->assertDatabaseHas('erp_purchase_receipt_item_allocations', [
            'receipt_item_id' => $line->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'base_qty' => 3,
        ]);
        $this->assertFalse(InventoryTransaction::query()
            ->where('source_type', 'purchase_receipt')
            ->where('source_id', $receipt->id)
            ->exists());
    }
}
