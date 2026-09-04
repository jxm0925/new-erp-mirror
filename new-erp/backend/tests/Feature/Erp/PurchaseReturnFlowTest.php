<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\PurchaseDefectHandling;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\Supplier;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\InventoryService;
use App\Services\Erp\InventoryQualityApplicationService;
use App\Services\Erp\PurchaseReturnApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseReturnFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_posted_purchase_batch_can_return_once_and_decreases_stock(): void
    {
        [$receipt, $line, $warehouse, $location] = $this->postedReceiptFixture();
        $service = app(PurchaseReturnApplicationService::class);

        $purchaseReturn = $service->create([
            'return_scope' => 'posted_inventory',
            'source_receipt_id' => $receipt->id,
            'supplier_id' => $receipt->supplier_id,
            'return_date' => now()->toDateString(),
            'return_reason' => '入库后抽检不合格',
            'items' => [[
                'source_receipt_item_id' => $line->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'batch_no' => 'B-RETURN-001',
                'requested_base_qty' => 20,
            ]],
        ], 1, '测试管理员');

        $this->assertSame('draft', $purchaseReturn->return_status);
        $purchaseReturn = $service->submit($purchaseReturn->id, 1, '测试管理员');
        $this->assertSame('submitted', $purchaseReturn->return_status);
        $purchaseReturn = $service->approve($purchaseReturn->id, 1, '测试管理员');
        $this->assertSame('pending_outbound', $purchaseReturn->return_status);
        $purchaseReturn = $service->post($purchaseReturn->id, 1, '测试管理员');

        $this->assertSame('completed', $purchaseReturn->return_status);
        $this->assertSame('posted', $purchaseReturn->stock_post_status);
        $balanceQuery = InventoryBalance::query()
            ->where('item_id', $line->item_id)
            ->where('warehouse_id', $warehouse->id)
            ->where('location_id', $location->id)
            ->where('batch_no', 'B-RETURN-001');
        $this->assertSame(80.0, (float) $balanceQuery->firstOrFail()->quantity_on_hand);
        $this->assertDatabaseHas('erp_inventory_transactions', [
            'source_type' => 'purchase_return',
            'source_id' => $purchaseReturn->id,
            'transaction_type' => 'purchase_return_outbound',
        ]);
        $this->assertDatabaseHas('erp_inventory_transaction_items', [
            'source_type' => 'purchase_return',
            'source_id' => $purchaseReturn->id,
            'change_qty' => -20,
        ]);

        try {
            $service->post($purchaseReturn->id, 1, '测试管理员');
            $this->fail('重复采购退货过账必须被拒绝');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('return_status', $exception->errors());
        }
        $this->assertSame(80.0, (float) $balanceQuery->firstOrFail()->quantity_on_hand);
    }

    public function test_one_piece_quality_supplier_return_can_be_approved_and_posted(): void
    {
        [$receipt, $line, $warehouse, $location] = $this->postedReceiptFixture(1);
        $balance = InventoryBalance::query()
            ->where('item_id', $line->item_id)
            ->where('warehouse_id', $warehouse->id)
            ->where('location_id', $location->id)
            ->where('batch_no', 'B-RETURN-001')
            ->firstOrFail();

        $event = app(InventoryQualityApplicationService::class)->create([
            'inventory_balance_id' => $balance->id,
            'issue_qty' => 1,
            'issue_category' => 'function_failure',
            'issue_description' => '单件采购设备入库后发现功能故障，退回供应商。',
            'handling_method' => 'return_supplier',
            'responsible_party' => 'supplier',
            'attachments' => [],
        ], 1, '测试管理员');

        $return = $event->purchaseReturnItems()->with('purchaseReturn')->firstOrFail()->purchaseReturn;
        $this->assertSame('submitted', $return->return_status);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_available);

        $return = app(PurchaseReturnApplicationService::class)->approve($return->id, 1, '测试管理员');
        $this->assertSame('pending_outbound', $return->return_status);
        $return = app(PurchaseReturnApplicationService::class)->post($return->id, 1, '测试管理员');

        $this->assertSame('completed', $return->return_status);
        $this->assertSame('posted', $return->stock_post_status);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_on_hand);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_pending);
        $this->assertSame('completed', $event->fresh()->event_status);
    }

    public function test_one_piece_quality_supplier_return_can_be_cancelled_and_releases_hold(): void
    {
        [$receipt, $line, $warehouse, $location] = $this->postedReceiptFixture(1);
        $balance = InventoryBalance::query()
            ->where('item_id', $line->item_id)
            ->where('warehouse_id', $warehouse->id)
            ->where('location_id', $location->id)
            ->where('batch_no', 'B-RETURN-001')
            ->firstOrFail();

        $event = app(InventoryQualityApplicationService::class)->create([
            'inventory_balance_id' => $balance->id,
            'issue_qty' => 1,
            'issue_category' => 'function_failure',
            'issue_description' => '单件采购设备入库后待退供应商，取消退货。',
            'handling_method' => 'return_supplier',
            'responsible_party' => 'supplier',
            'attachments' => [],
        ], 1, '测试管理员');

        $return = $event->purchaseReturnItems()->with('purchaseReturn')->firstOrFail()->purchaseReturn;
        $return = app(PurchaseReturnApplicationService::class)->cancel($return->id, 1, '测试管理员');

        $this->assertSame('cancelled', $return->return_status);
        $this->assertSame('cancelled', $event->fresh()->event_status);
        $this->assertSame(1.0, (float) $balance->fresh()->quantity_on_hand);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_pending);
        $this->assertSame(1.0, (float) $balance->fresh()->quantity_available);
        $this->assertDatabaseMissing('erp_inventory_transactions', [
            'source_type' => 'purchase_return',
            'source_id' => $return->id,
        ]);
    }

    public function test_unposted_rejection_creates_formal_return_without_fake_inventory_flow(): void
    {
        [$receipt, $line] = $this->unpostedRejectedReceiptFixture();
        $handling = PurchaseDefectHandling::create([
            'handling_no' => 'PDH-TEST-001',
            'receipt_id' => $receipt->id,
            'receipt_item_id' => $line->id,
            'item_id' => $line->item_id,
            'supplier_id' => $receipt->supplier_id,
            'handling_method' => 'return_supplier',
            'handling_qty' => 10,
            'handling_status' => 'completed',
            'defect_reason' => '来料破损',
            'responsible_party' => '供应商',
        ]);

        $purchaseReturn = app(PurchaseReturnApplicationService::class)->createRejectedFromDefect(
            $line,
            $handling,
            10,
            1,
            '测试管理员',
        );

        $this->assertSame('rejected_before_posting', $purchaseReturn->return_scope);
        $this->assertSame('submitted', $purchaseReturn->return_status);
        $this->assertSame('not_required', $purchaseReturn->stock_post_status);
        $this->assertSame(10.0, (float) $purchaseReturn->items->first()->requested_base_qty);
        $this->assertSame($purchaseReturn->return_no, $handling->fresh()->business_doc_no);
        $this->assertDatabaseMissing('erp_inventory_transactions', [
            'source_type' => 'purchase_return',
            'source_id' => $purchaseReturn->id,
        ]);
    }

    public function test_active_returns_share_one_available_quantity_limit(): void
    {
        [$receipt, $line, $warehouse, $location] = $this->postedReceiptFixture();
        $service = app(PurchaseReturnApplicationService::class);
        $payload = [
            'return_scope' => 'posted_inventory',
            'source_receipt_id' => $receipt->id,
            'supplier_id' => $receipt->supplier_id,
            'return_reason' => '库存批次退货',
            'items' => [[
                'source_receipt_item_id' => $line->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'batch_no' => 'B-RETURN-001',
                'requested_base_qty' => 70,
            ]],
        ];
        $service->create($payload, 1, '测试管理员');

        $payload['items'][0]['requested_base_qty'] = 31;
        $this->expectException(ValidationException::class);
        $service->create($payload, 1, '测试管理员');
    }

    public function test_return_quantity_is_capped_by_current_available_stock_after_other_inventory_changes(): void
    {
        [$receipt, $line, $warehouse, $location] = $this->postedReceiptFixture();
        $balance = InventoryBalance::query()
            ->where('item_id', $line->item_id)
            ->where('warehouse_id', $warehouse->id)
            ->where('location_id', $location->id)
            ->where('batch_no', 'B-RETURN-001')
            ->firstOrFail();
        $balance->update([
            'quantity_on_hand' => 60,
            'quantity_available' => 60,
            'inventory_value' => 120,
        ]);
        $service = app(PurchaseReturnApplicationService::class);
        $payload = [
            'return_scope' => 'posted_inventory',
            'source_receipt_id' => $receipt->id,
            'supplier_id' => $receipt->supplier_id,
            'return_reason' => '其他库存事务后退货上限复核',
            'items' => [[
                'source_receipt_item_id' => $line->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'batch_no' => 'B-RETURN-001',
                'requested_base_qty' => 61,
            ]],
        ];

        try {
            $service->create($payload, 1, '测试管理员');
            $this->fail('采购退货数量不得超过当前真实可用库存。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $payload['items'][0]['requested_base_qty'] = 60;
        $return = $service->create($payload, 1, '测试管理员');
        $this->assertSame(60.0, (float) $return->items->first()->requested_base_qty);
    }

    public function test_one_piece_can_be_returned_from_a_carton_purchase(): void
    {
        [$receipt, $line, $warehouse, $location] = $this->postedReceiptFixture();
        $piece = $line->baseUnit;
        $carton = Unit::create([
            'unit_code' => 'CTN-RETURN',
            'unit_name' => '箱',
            'unit_type' => 'package',
            'decimal_places' => 2,
            'is_base' => false,
            'status' => 'enabled',
        ]);
        $line->update([
            'purchase_unit_id' => $carton->id,
            'purchase_unit_name_snapshot' => '箱',
            'conversion_factor_snapshot' => 12,
        ]);

        $service = app(PurchaseReturnApplicationService::class);
        $payload = [
            'return_scope' => 'posted_inventory',
            'source_receipt_id' => $receipt->id,
            'supplier_id' => $receipt->supplier_id,
            'return_reason' => '整箱到货其中一件损坏',
            'items' => [[
                'source_receipt_item_id' => $line->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'batch_no' => 'B-RETURN-001',
                'requested_return_qty' => 1,
                'return_unit_id' => $piece->id,
            ]],
        ];

        $pieceReturn = $service->create($payload, 1, '测试管理员');
        $pieceItem = $pieceReturn->items->first();
        $this->assertSame(1.0, (float) $pieceItem->requested_return_qty);
        $this->assertSame('千克', $pieceItem->return_unit_name_snapshot);
        $this->assertSame(1.0, (float) $pieceItem->requested_base_qty);

        $payload['items'][0]['return_unit_id'] = $carton->id;
        $cartonReturn = $service->create($payload, 1, '测试管理员');
        $cartonItem = $cartonReturn->items->first();
        $this->assertSame('箱', $cartonItem->return_unit_name_snapshot);
        $this->assertSame(12.0, (float) $cartonItem->requested_base_qty);
    }

    public function test_purchase_return_keeps_original_receipt_and_inbound_transaction_immutable(): void
    {
        [$receipt, $line, $warehouse, $location] = $this->postedReceiptFixture(6);
        $inbound = InventoryTransactionItem::query()
            ->where('source_type', 'purchase_receipt')
            ->where('source_id', $receipt->id)
            ->where('source_item_id', $line->id)
            ->firstOrFail();

        $service = app(PurchaseReturnApplicationService::class);
        $return = $service->create([
            'return_scope' => 'posted_inventory',
            'source_receipt_id' => $receipt->id,
            'supplier_id' => $receipt->supplier_id,
            'return_reason' => '验证入库事实不可变',
            'items' => [[
                'source_receipt_item_id' => $line->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'batch_no' => 'B-RETURN-001',
                'requested_base_qty' => 1,
            ]],
        ], 1, '测试管理员');
        $service->submit($return->id, 1, '测试管理员');
        $service->approve($return->id, 1, '测试管理员');
        $service->post($return->id, 1, '测试管理员');

        $this->assertSame(6.0, (float) $receipt->fresh()->total_qualified_qty);
        $this->assertSame(6.0, (float) $line->fresh()->qualified_base_qty);
        $this->assertSame(6.0, (float) $inbound->fresh()->change_qty);
        $this->assertSame(5.0, (float) InventoryBalance::query()
            ->where('item_id', $line->item_id)
            ->where('warehouse_id', $warehouse->id)
            ->where('location_id', $location->id)
            ->where('batch_no', 'B-RETURN-001')
            ->value('quantity_on_hand'));
        $this->assertDatabaseHas('erp_inventory_transaction_items', [
            'source_type' => 'purchase_return',
            'source_id' => $return->id,
            'change_qty' => -1,
        ]);
    }

    public function test_order_or_work_hold_blocks_purchase_return_and_inventory_quality_actions(): void
    {
        [$receipt, $line, $warehouse, $location] = $this->postedReceiptFixture(6);
        $balance = InventoryBalance::query()
            ->where('item_id', $line->item_id)
            ->where('warehouse_id', $warehouse->id)
            ->where('location_id', $location->id)
            ->where('batch_no', 'B-RETURN-001')
            ->firstOrFail();
        // Deliberately leave the denormalized available field stale: the safe
        // boundary must still respect the actual order/work hold.
        $balance->update(['quantity_locked' => 6, 'quantity_available' => 6]);

        try {
            app(PurchaseReturnApplicationService::class)->create([
                'return_scope' => 'posted_inventory',
                'source_receipt_id' => $receipt->id,
                'supplier_id' => $receipt->supplier_id,
                'return_reason' => '已被履约占用，不得退货',
                'items' => [[
                    'source_receipt_item_id' => $line->id,
                    'warehouse_id' => $warehouse->id,
                    'location_id' => $location->id,
                    'batch_no' => 'B-RETURN-001',
                    'requested_base_qty' => 1,
                ]],
            ], 1, '测试管理员');
            $this->fail('被订单或工单占用的库存不得创建采购退货。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        try {
            app(InventoryQualityApplicationService::class)->create([
                'inventory_balance_id' => $balance->id,
                'issue_qty' => 1,
                'issue_category' => 'function_failure',
                'issue_description' => '已被履约占用的库存不得重复发起库存退换货。',
                'handling_method' => 'return_supplier',
                'responsible_party' => 'supplier',
                'attachments' => [],
            ], 1, '测试管理员');
            $this->fail('被订单或工单占用的库存不得进入库存退换货。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('issue_qty', $exception->errors());
        }

        $this->assertSame(6.0, (float) $balance->fresh()->quantity_on_hand);
    }

    private function postedReceiptFixture(float $quantity = 100): array
    {
        [$unit, $item, $supplier, $warehouse, $location] = $this->masterFixture();
        $receipt = PurchaseReceipt::create([
            'receipt_no' => 'PRC-RETURN-001',
            'supplier_id' => $supplier->id,
            'receipt_date' => now()->toDateString(),
            'receipt_status' => 'confirmed',
            'confirm_status' => 'confirmed',
            'stock_post_status' => 'pending',
            'total_receipt_qty' => $quantity,
            'total_qualified_qty' => $quantity,
        ]);
        $line = PurchaseReceiptItem::create([
            'receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'purchase_unit_id' => $unit->id,
            'purchase_unit_name_snapshot' => '千克',
            'conversion_factor_snapshot' => 1,
            'base_unit_id' => $unit->id,
            'base_unit_name_snapshot' => '千克',
            'receipt_qty' => $quantity,
            'qualified_qty' => $quantity,
            'unqualified_qty' => 0,
            'standard_base_qty' => $quantity,
            'actual_base_qty' => $quantity,
            'qualified_base_qty' => $quantity,
            'unqualified_base_qty' => 0,
            'unit_price' => 2,
            'receipt_cost' => $quantity * 2,
            'batch_no' => 'B-RETURN-001',
            'inventory_posting_status' => 'pending',
        ]);
        app(InventoryService::class)->postPurchaseReceipt($receipt->id);

        return [$receipt->fresh(), $line->fresh(), $warehouse, $location];
    }

    private function unpostedRejectedReceiptFixture(): array
    {
        [$unit, $item, $supplier] = $this->masterFixture();
        $receipt = PurchaseReceipt::create([
            'receipt_no' => 'PRC-REJECT-001',
            'supplier_id' => $supplier->id,
            'receipt_date' => now()->toDateString(),
            'receipt_status' => 'confirmed',
            'confirm_status' => 'confirmed',
            'stock_post_status' => 'pending',
            'total_receipt_qty' => 100,
            'total_qualified_qty' => 90,
            'total_unqualified_qty' => 10,
        ]);
        $line = PurchaseReceiptItem::create([
            'receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'purchase_unit_id' => $unit->id,
            'purchase_unit_name_snapshot' => '千克',
            'conversion_factor_snapshot' => 1,
            'base_unit_id' => $unit->id,
            'base_unit_name_snapshot' => '千克',
            'receipt_qty' => 100,
            'qualified_qty' => 90,
            'unqualified_qty' => 10,
            'standard_base_qty' => 100,
            'actual_base_qty' => 100,
            'qualified_base_qty' => 90,
            'unqualified_base_qty' => 10,
            'unit_price' => 2,
            'receipt_cost' => 200,
            'inventory_posting_status' => 'pending',
        ]);

        return [$receipt, $line];
    }

    private function masterFixture(): array
    {
        $unit = Unit::create([
            'unit_code' => 'KG-RETURN',
            'unit_name' => '千克',
            'unit_type' => 'weight',
            'decimal_places' => 4,
            'is_base' => true,
            'status' => 'enabled',
        ]);
        $supplier = Supplier::create([
            'supplier_code' => 'SUP-RETURN',
            'supplier_name' => '采购退货测试供应商',
            'supplier_type' => 'manufacturer',
            'status' => 'enabled',
        ]);
        $warehouse = Warehouse::create([
            'warehouse_code' => 'WH-RETURN',
            'warehouse_name' => '采购退货测试仓',
            'status' => 'enabled',
        ]);
        $location = Location::create([
            'location_code' => 'LOC-RETURN',
            'location_name' => '采购退货测试库位',
            'warehouse_id' => $warehouse->id,
            'status' => 'enabled',
        ]);
        $item = Item::create([
            'item_code' => 'ITEM-RETURN',
            'item_name' => '采购退货测试物料',
            'item_type' => 'raw_material',
            'unit_id' => $unit->id,
            'is_purchase_item' => true,
            'is_stock_item' => true,
            'status' => 'enabled',
        ]);

        return [$unit, $item, $supplier, $warehouse, $location];
    }
}
