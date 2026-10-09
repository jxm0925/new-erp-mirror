<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{InventoryBalance, InventoryTransaction, InventoryTransactionItem, Item, Location, ProductionOutputRecord, PurchaseOrder, PurchaseOrderItem, PurchaseReceipt, PurchaseReceiptItem, Supplier, Unit, Warehouse, WorkOrder};
use App\Services\Erp\{InventoryAdjustmentApplicationService, InventoryService, PurchaseReceiptAllocationService, PurchaseReceiptApplicationService, PurchaseReceiptConfirmationApplicationService, PurchaseReceiptPostingEligibilityService, PurchaseReceiptPostingRepairApplicationService, WarehouseManagementScopeService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseWarehouseManagementScopeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_both_stock_scopes_confirm_and_post_only_to_their_own_warehouse(): void
    {
        foreach (['factory', 'office'] as $scope) {
            $f = $this->fixture($scope);
            $confirmed = app(PurchaseReceiptConfirmationApplicationService::class)->confirm($f['receipt']->id, 1, '范围验收');
            $this->assertSame($scope, $confirmed->management_scope);
            $this->assertTrue((bool) $confirmed->items->first()->is_stock_item_snapshot);
            $transaction = app(InventoryService::class)->postPurchaseReceipt($confirmed->id);
            $this->assertSame(1, $transaction->items->count());
            $this->assertSame($f['warehouse']->id, $transaction->items->first()->warehouse_id);
            $this->assertDatabaseHas('erp_inventory_balances', ['item_id' => $f['item']->id,
                'warehouse_id' => $f['warehouse']->id, 'quantity_on_hand' => '2.00000000']);
        }
    }

    public function test_office_nonstock_receipt_fulfills_and_creates_payable_without_inventory(): void
    {
        $f = $this->fixture('office', false);
        $confirmed = app(PurchaseReceiptConfirmationApplicationService::class)->confirm($f['receipt']->id, 1, '办公用品验收');
        $this->assertSame('office', $confirmed->management_scope);
        $this->assertSame('fulfilled', $confirmed->fulfillment_status);
        $this->assertSame('not_required', $confirmed->stock_post_status);
        $this->assertFalse((bool) $f['line']->fresh()->is_stock_item_snapshot);
        $this->assertSame(0, $f['line']->allocations()->count());
        $this->assertNull($f['line']->fresh()->warehouse_id);
        $this->assertFalse(InventoryBalance::where('item_id', $f['item']->id)->exists());
        $this->assertFalse(InventoryTransaction::where('source_type', 'purchase_receipt')->where('source_id', $confirmed->id)->exists());
        $this->assertDatabaseHas('erp_purchase_settlement_sources', ['source_receipt_id' => $confirmed->id,
            'source_line_id' => $f['line']->id, 'eligible_amount' => '20.0000', 'status' => 'open']);
    }

    public function test_cross_scope_allocation_is_rejected_before_any_saved_allocation_is_deleted(): void
    {
        foreach (['factory', 'office'] as $scope) {
            $f = $this->fixture($scope);
            $service = app(PurchaseReceiptAllocationService::class);
            $service->replace($f['line'], [['warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id, 'base_qty' => 2]]);
            $before = $f['line']->allocations()->get()->toArray();
            $foreign = $this->warehouse($scope === 'factory' ? 'office' : 'factory');
            $this->reject(fn () => $service->replace($f['line'], [['warehouse_id' => $foreign[0]->id,
                'location_id' => $foreign[1]->id, 'base_qty' => 2]]), 'allocations');
            $this->assertSame($before, $f['line']->allocations()->get()->toArray());
        }
    }

    public function test_unknown_header_snapshot_item_or_warehouse_blocks_new_confirmation(): void
    {
        foreach (['header', 'snapshot', 'item', 'warehouse'] as $changed) {
            $f = $this->fixture('office');
            match ($changed) {
                'header' => $f['receipt']->update(['management_scope' => null]),
                'snapshot' => $f['line']->update(['management_scope_snapshot' => 'factory']),
                'item' => DB::table('erp_items')->where('id', $f['item']->id)->update(['management_scope' => 'factory']),
                'warehouse' => $f['warehouse']->update(['management_scope' => null]),
            };
            $before = $f['line']->fresh()->getAttributes();
            $this->reject(fn () => app(PurchaseReceiptConfirmationApplicationService::class)->confirm($f['receipt']->id, 1, '范围验收'));
            $this->assertSame('draft', $f['receipt']->fresh()->confirm_status);
            $this->assertSame($before, $f['line']->fresh()->getAttributes());
            $this->assertFalse(InventoryTransaction::where('source_type', 'purchase_receipt')->where('source_id', $f['receipt']->id)->exists());
        }
    }

    public function test_stock_policy_drift_never_overwrites_frozen_snapshot_during_confirmation(): void
    {
        foreach ([true, false] as $stock) {
            $f = $this->fixture('office', $stock);
            DB::table('erp_items')->where('id', $f['item']->id)->update(['is_stock_item' => !$stock]);
            $this->reject(fn () => app(PurchaseReceiptConfirmationApplicationService::class)->confirm($f['receipt']->id, 1, '范围验收'), 'items');
            $this->assertSame($stock, (bool) $f['line']->fresh()->is_stock_item_snapshot);
            $this->assertSame('draft', $f['receipt']->fresh()->confirm_status);
            $this->assertFalse(InventoryBalance::where('item_id', $f['item']->id)->exists());
        }
    }

    public function test_first_confirmation_can_complete_a_known_legacy_draft_scope_once(): void
    {
        $f = $this->fixture('factory');
        $f['line']->update(['management_scope_snapshot' => null]);
        $confirmed = app(PurchaseReceiptConfirmationApplicationService::class)->confirm($f['receipt']->id, 1, '首次草稿确认');
        $this->assertSame('confirmed', $confirmed->confirm_status);
        $this->assertSame('factory', $f['line']->fresh()->management_scope_snapshot);
        $this->assertTrue((bool) $f['line']->fresh()->is_stock_item_snapshot);
        $before = $f['line']->fresh()->getAttributes();
        $this->reject(fn () => app(PurchaseReceiptConfirmationApplicationService::class)->confirm($f['receipt']->id, 1, '重放'), 'receipt');
        $this->assertSame($before, $f['line']->fresh()->getAttributes());
        $this->assertSame(1, $f['line']->allocations()->count());
    }

    public function test_failed_first_confirmation_keeps_null_draft_scope_and_confirmed_null_remains_blocked(): void
    {
        $f = $this->fixture('factory');
        $f['line']->update(['management_scope_snapshot' => null]);
        [$foreign, $foreignLocation] = $this->warehouse('office');
        $f['line']->update(['warehouse_id' => $foreign->id, 'location_id' => $foreignLocation->id]);
        $this->reject(fn () => app(PurchaseReceiptConfirmationApplicationService::class)->confirm($f['receipt']->id, 1, '错误分配'), 'allocations');
        $this->assertNull($f['line']->fresh()->management_scope_snapshot);
        $this->assertSame('draft', $f['receipt']->fresh()->confirm_status);
        $this->assertSame(0, $f['line']->allocations()->count());

        $valid = $this->fixture('factory');
        app(PurchaseReceiptConfirmationApplicationService::class)->confirm($valid['receipt']->id, 1, '正常确认');
        $valid['line']->update(['management_scope_snapshot' => null]);
        $before = $valid['line']->allocations()->get()->toArray();
        $this->reject(fn () => app(InventoryService::class)->postPurchaseReceipt($valid['receipt']->id), 'management_scope');
        $this->reject(fn () => app(PurchaseReceiptPostingRepairApplicationService::class)->repair($valid['receipt']->id, [[
            'receipt_item_id' => $valid['line']->id, 'allocations' => [['warehouse_id' => $valid['warehouse']->id,
                'location_id' => $valid['location']->id, 'base_qty' => 2]],
        ]], '历史缺快照'), 'management_scope');
        $this->assertNull($valid['line']->fresh()->management_scope_snapshot);
        $this->assertSame($before, $valid['line']->allocations()->get()->toArray());
        $this->assertSame('pending', $valid['receipt']->fresh()->stock_post_status);
    }

    public function test_changed_warehouse_or_stock_policy_blocks_pending_posting_and_repair(): void
    {
        foreach (['warehouse', 'policy'] as $changed) {
            $f = $this->fixture('office');
            app(PurchaseReceiptConfirmationApplicationService::class)->confirm($f['receipt']->id, 1, '范围验收');
            if ($changed === 'warehouse') $f['warehouse']->update(['management_scope' => 'factory']);
            else DB::table('erp_items')->where('id', $f['item']->id)->update(['is_stock_item' => false]);
            $before = $f['line']->allocations()->get()->toArray();
            $eligibility = app(PurchaseReceiptPostingEligibilityService::class)->evaluate($f['receipt']->fresh());
            $this->assertFalse($eligibility['can_post']);
            $this->assertStringStartsWith('scope_', $eligibility['reason_code']);
            $this->reject(fn () => app(InventoryService::class)->postPurchaseReceipt($f['receipt']->id));
            $this->reject(fn () => app(PurchaseReceiptPostingRepairApplicationService::class)->repair($f['receipt']->id, [[
                'receipt_item_id' => $f['line']->id, 'allocations' => [['warehouse_id' => $f['warehouse']->id,
                    'location_id' => $f['location']->id, 'base_qty' => 2]],
            ]], '范围验收'));
            $this->assertSame($before, $f['line']->allocations()->get()->toArray());
            $this->assertSame('pending', $f['receipt']->fresh()->stock_post_status);
            $this->assertTrue((bool) $f['line']->fresh()->is_stock_item_snapshot);
            $this->assertFalse(InventoryBalance::where('item_id', $f['item']->id)->exists());
        }
    }

    public function test_order_generated_receipt_inherits_office_scope_and_policy(): void
    {
        $f = $this->fixture('office', false);
        $order = PurchaseOrder::create(['purchase_order_no' => 'SCOPE-PO-'.Str::ulid(), 'management_scope' => 'office',
            'supplier_id' => $f['supplier']->id, 'purchase_status' => 'processing', 'audit_status' => 'approved',
            'receipt_status' => 'not_received', 'total_qty' => 2]);
        PurchaseOrderItem::create(['order_id' => $order->id, 'item_id' => $f['item']->id, 'order_qty' => 2,
            'received_qty' => 0, 'remaining_qty' => 2, 'unit_price' => 10, 'amount' => 20,
            'purchase_unit_id' => $f['unit']->id, 'base_unit_id' => $f['unit']->id,
            'purchase_unit_name_snapshot' => '件', 'base_unit_name_snapshot' => '件',
            'conversion_factor_snapshot' => 1, 'purchase_qty' => 2, 'planned_base_qty' => 2,
            'material_policy_snapshot' => ['source' => 'frozen_order', 'is_stock_managed' => false, 'future_route' => 'expense']]);
        $receipt = app(PurchaseReceiptApplicationService::class)->generateFromOrder($order->id, '范围验收');
        $this->assertSame('office', $receipt->management_scope);
        $this->assertSame('office', $receipt->items->first()->management_scope_snapshot);
        $this->assertFalse((bool) $receipt->items->first()->is_stock_item_snapshot);
        $this->assertSame('frozen_order', $receipt->items->first()->material_policy_snapshot['source']);
    }

    public function test_uniform_stock_entry_rejects_cross_scope_even_with_forged_return_metadata(): void
    {
        $f = $this->fixture('office');
        [$warehouse, $location] = $this->warehouse('factory');
        $receipt = (object) ['id' => random_int(90000000, 99999999), 'receipt_no' => 'SCOPE-CUT-'.Str::ulid(), 'route_id' => 1];
        $line = ['item_id' => $f['item']->id, 'unit_id' => $f['unit']->id,
            'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => 'SCOPE-FORGED-'.Str::ulid(),
            'change_qty' => 1, 'unit_cost' => 10, 'original_return_transaction_items' => [['id' => 1, 'quantity' => 1]]];
        $this->reject(fn () => DB::transaction(fn () => app(InventoryService::class)->postCuttingReceipt($receipt, $line, (object) ['legacy_id' => 1])), 'warehouse_id');
        $this->assertFalse(InventoryBalance::where('item_id', $f['item']->id)->exists());
        $this->assertFalse(InventoryTransaction::where('source_type', 'cutting_warehouse_receipt')->where('source_id', $receipt->id)->exists());
    }

    public function test_true_uncut_return_restores_original_unknown_warehouse_and_replays_once(): void
    {
        $f = $this->fixture('factory');
        $batch = (object) ['id' => random_int(90000000, 99999999), 'batch_no' => 'SCOPE-RETURN-'.Str::ulid(),
            'input_item_id' => $f['item']->id, 'input_qty' => '1.00000000', 'original_total_cost' => '10.0000', 'physical_material_id' => null];
        $balance = InventoryBalance::create(['item_id' => $f['item']->id, 'warehouse_id' => $f['warehouse']->id,
            'location_id' => $f['location']->id, 'batch_no' => 'SCOPE-ORIGINAL-'.Str::ulid(), 'unit_id' => $f['unit']->id,
            'quantity_on_hand' => 0, 'quantity_available' => 0, 'quantity_locked' => 0, 'inventory_value' => 0]);
        $original = InventoryTransaction::create(['transaction_no' => 'SCOPE-ISSUE-'.Str::ulid(), 'transaction_type' => 'cutting_material_issue',
            'source_type' => 'cutting_settlement', 'source_id' => $batch->id, 'posting_status' => 'posted', 'transaction_date' => now()->toDateString()]);
        InventoryTransactionItem::create(['transaction_id' => $original->id, 'transaction_no' => $original->transaction_no,
            'source_type' => 'cutting_settlement', 'source_id' => $batch->id, 'source_item_id' => $batch->id,
            'item_id' => $f['item']->id, 'item_code' => $f['item']->item_code, 'item_name' => $f['item']->item_name,
            'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
            'batch_no' => $balance->batch_no, 'unit_id' => $f['unit']->id, 'change_qty' => -1, 'unit_cost' => 10,
            'cost_amount' => -10, 'balance_after_qty' => 0]);
        $f['warehouse']->update(['management_scope' => null]);
        [$currentWarehouse] = $this->warehouse('office');
        $f['location']->update(['warehouse_id' => $currentWarehouse->id, 'status' => 'disabled']);
        DB::table('erp_items')->where('id', $f['item']->id)->update(['management_scope' => 'office']);
        $service = app(InventoryService::class);
        $returned = $service->postCuttingOriginalReturn($batch, $balance, (object) ['legacy_id' => 1]);
        $replayed = $service->postCuttingOriginalReturn($batch, $balance, (object) ['legacy_id' => 1]);
        $this->assertSame($returned->id, $replayed->id);
        $this->assertEquals(1, $balance->fresh()->quantity_on_hand);
        $this->assertEquals(10, $balance->fresh()->inventory_value);
        $this->assertSame(1, InventoryTransaction::where('source_type', 'cutting_settlement')->where('source_id', $batch->id)
            ->where('transaction_type', 'cutting_material_return')->count());
        $this->assertNull($f['warehouse']->fresh()->management_scope);
        $this->assertSame('office', $f['item']->fresh()->management_scope);
        $this->assertSame($f['location']->id, $returned->items->first()->location_id);
        $this->assertSame('disabled', $f['location']->fresh()->status);
        $this->assertSame($currentWarehouse->id, $f['location']->fresh()->warehouse_id);
    }

    public function test_production_receipts_reject_foreign_missing_and_disabled_locations_without_stock_facts(): void
    {
        foreach (['office', 'other_factory', 'missing', 'disabled'] as $case) {
            foreach (['intermediate', 'finished'] as $type) {
                $f = $this->fixture('factory');
                $output = $this->receiptProductionOutput($f);
                $locationId = $f['location']->id;
                if (in_array($case, ['office', 'other_factory'], true)) {
                    [, $foreignLocation] = $this->warehouse($case === 'office' ? 'office' : 'factory');
                    $locationId = $foreignLocation->id;
                } elseif ($case === 'missing') {
                    $locationId = (int) Location::max('id') + 1000000;
                } else {
                    $f['location']->update(['status' => 'disabled']);
                }
                $receipt = (object) ['id' => random_int(90000000, 99999999), 'receipt_no' => 'SCOPE-FG-'.Str::ulid(), 'posted_base_qty' => '2.00000000'];
                $sourceType = $type === 'intermediate' ? 'production_output_record' : 'work_order_finished_goods_receipt';
                $sourceId = $type === 'intermediate' ? $output->id : $receipt->id;
                $before = $this->inventoryFacts($f['item']->id, $sourceType, $sourceId);
                $posting = ['warehouse_id' => $f['warehouse']->id, 'location_id' => $locationId,
                    'batch_no' => 'SCOPE-OUTPUT-'.Str::ulid(), 'unit_cost' => 10];
                $service = app(InventoryService::class);
                $this->reject(fn () => $type === 'intermediate'
                    ? $service->postProductionOutputReceipt($output, $posting, (object) ['legacy_id' => 1])
                    : $service->postFinishedGoodsReceipt($receipt, $output, $posting, (object) ['legacy_id' => 1]), 'location_id');
                $this->assertSame($before, $this->inventoryFacts($f['item']->id, $sourceType, $sourceId), $case.' '.$type);
                $this->assertSame('WAIT_WAREHOUSE', $output->fresh()->status);
            }
        }
    }

    public function test_positive_adjustment_rejects_another_warehouse_location_and_rolls_back_earlier_valid_line(): void
    {
        foreach (['office', 'factory'] as $foreignScope) {
            $f = $this->fixture('factory');
            [, $foreignLocation] = $this->warehouse($foreignScope);
            $balances = collect([$f['location'], $foreignLocation])->map(fn (Location $location) => InventoryBalance::create([
                'item_id' => $f['item']->id, 'warehouse_id' => $f['warehouse']->id, 'location_id' => $location->id,
                'batch_no' => 'SCOPE-ADJUSTMENT-'.Str::ulid(), 'unit_id' => $f['unit']->id,
                'quantity_on_hand' => 2, 'quantity_locked' => 0, 'quantity_available' => 2,
                'average_unit_cost' => 10, 'inventory_value' => 20,
            ]));
            $adjustments = app(InventoryAdjustmentApplicationService::class);
            $adjustment = $adjustments->save(['adjustment_no' => 'SCOPE-ADJ-'.Str::ulid(), 'reason' => '核对历史库位库存',
                'items' => $balances->map(fn (InventoryBalance $balance) => ['item_id' => $f['item']->id,
                    'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
                    'batch_no' => $balance->batch_no, 'unit_id' => $f['unit']->id, 'change_qty' => 1])->all()]);
            $adjustments->submit($adjustment->id);
            $before = $this->inventoryFacts($f['item']->id, 'inventory_adjustment', $adjustment->id);
            $this->reject(fn () => app(InventoryService::class)->postAdjustment($adjustment->id), 'location_id');
            $this->assertSame($before, $this->inventoryFacts($f['item']->id, 'inventory_adjustment', $adjustment->id));
            $this->assertSame('submitted', $adjustment->fresh()->adjustment_status);
            $this->assertNull($adjustment->fresh()->posted_at);
        }
    }

    public function test_valid_output_locator_posts_once_and_successful_replay_keeps_original_fact_after_master_change(): void
    {
        $f = $this->fixture('factory');
        $output = $this->receiptProductionOutput($f);
        $posting = ['warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
            'batch_no' => 'SCOPE-VALID-OUTPUT-'.Str::ulid(), 'unit_cost' => 10];
        $service = app(InventoryService::class);
        $posted = $service->postProductionOutputReceipt($output, $posting, (object) ['legacy_id' => 1]);
        $this->assertSame($f['location']->id, $posted->items->first()->location_id);
        $this->assertDatabaseHas('erp_inventory_balances', ['item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id, 'quantity_on_hand' => '2.00000000']);
        $before = $this->inventoryFacts($f['item']->id, 'production_output_record', $output->id);
        [$currentWarehouse] = $this->warehouse('office');
        $f['location']->update(['warehouse_id' => $currentWarehouse->id, 'status' => 'disabled']);
        $replayed = $service->postProductionOutputReceipt($output, $posting, (object) ['legacy_id' => 1]);
        $this->assertSame($posted->id, $replayed->id);
        $this->assertSame($before, $this->inventoryFacts($f['item']->id, 'production_output_record', $output->id));
    }

    private function receiptProductionOutput(array $f): ProductionOutputRecord
    {
        $workOrder = WorkOrder::create(['work_order_no' => 'SCOPE-OUTPUT-WO-'.Str::ulid(), 'source_type' => 'stock_prebuild',
            'output_item_id' => $f['item']->id, 'target_qty' => 2, 'target_base_qty' => 2,
            'target_unit_id' => $f['unit']->id, 'base_unit_id' => $f['unit']->id, 'status' => 'COMPLETED', 'business_version' => 1]);
        return ProductionOutputRecord::create(['output_no' => 'SCOPE-OUTPUT-'.Str::ulid(), 'work_order_id' => $workOrder->id,
            'source_target_type' => 'work_order', 'source_target_id' => $workOrder->id, 'output_item_id' => $f['item']->id,
            'output_base_qty' => 2, 'output_mode_snapshot' => 'warehouse_required', 'quality_mode_snapshot' => 'none',
            'status' => 'WAIT_WAREHOUSE', 'created_by_legacy_id' => 1, 'produced_at' => now(), 'business_version' => 1])->fresh();
    }

    private function inventoryFacts(int $itemId, string $sourceType, int $sourceId): array
    {
        $facts = [];
        foreach (['erp_inventory_balances', 'erp_inventory_location_balances', 'erp_inventory_batches', 'erp_inventory_serials', 'erp_inventory_alerts'] as $table) {
            $facts[$table] = DB::table($table)->where('item_id', $itemId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        foreach (['erp_inventory_transactions', 'erp_inventory_transaction_items', 'erp_inventory_posting_logs'] as $table) {
            $facts[$table] = DB::table($table)->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        return $facts;
    }

    private function fixture(string $scope, bool $stock = true): array
    {
        $suffix = (string) Str::ulid();
        $unit = Unit::create(['unit_code' => 'SCOPE-U-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'status' => 'enabled']);
        $supplier = Supplier::create(['supplier_code' => 'SCOPE-S-'.$suffix, 'supplier_name' => '范围验收供应商', 'status' => 'enabled', 'approval_status' => 'approved']);
        $item = Item::create(['item_code' => 'SCOPE-I-'.$suffix, 'item_name' => '范围验收物料', 'management_scope' => $scope,
            'item_type' => $scope === 'office' ? 'consumable' : 'raw_material', 'unit_id' => $unit->id,
            'is_purchase_item' => true, 'is_stock_item' => $stock, 'material_management_mode' => 'quantity',
            'serial_tracking_mode' => 'none', 'status' => 'enabled'])->fresh();
        [$warehouse, $location] = $this->warehouse($scope);
        $receipt = PurchaseReceipt::create(['receipt_no' => 'SCOPE-R-'.$suffix, 'management_scope' => $scope,
            'supplier_id' => $supplier->id, 'receipt_date' => now()->toDateString(), 'receipt_status' => 'draft', 'confirm_status' => 'draft',
            'stock_post_status' => 'pending', 'total_receipt_qty' => 2, 'total_qualified_qty' => 2, 'total_amount' => 20, 'remark' => '范围验收手工到货']);
        $line = PurchaseReceiptItem::create(['receipt_id' => $receipt->id, 'item_id' => $item->id,
            'management_scope_snapshot' => $scope, 'is_stock_item_snapshot' => $stock,
            'material_policy_snapshot' => ['source' => 'test_frozen_fact', 'is_stock_managed' => $stock, 'future_route' => $stock ? 'inventory' : 'expense'],
            'purchase_unit_id' => $unit->id, 'purchase_unit_name_snapshot' => '件', 'base_unit_id' => $unit->id, 'base_unit_name_snapshot' => '件',
            'conversion_factor_snapshot' => 1, 'receipt_qty' => 2, 'qualified_qty' => 2, 'unqualified_qty' => 0,
            'standard_base_qty' => 2, 'actual_base_qty' => 2, 'qualified_base_qty' => 2, 'unqualified_base_qty' => 0,
            'unit_price' => 10, 'receipt_cost' => 20, 'tax_rate' => 0, 'warehouse_id' => $warehouse->id,
            'location_id' => $location->id, 'batch_no' => 'SCOPE-B-'.$suffix]);
        return compact('unit', 'supplier', 'item', 'warehouse', 'location', 'receipt', 'line');
    }

    private function warehouse(string $scope): array
    {
        $suffix = (string) Str::ulid();
        $warehouse = Warehouse::create(['warehouse_code' => 'SCOPE-W-'.$suffix, 'warehouse_name' => '范围验收仓库', 'management_scope' => $scope, 'status' => 'enabled']);
        $location = Location::create(['location_code' => 'SCOPE-L-'.$suffix, 'location_name' => '范围验收库位', 'warehouse_id' => $warehouse->id, 'status' => 'enabled']);
        return [$warehouse, $location];
    }

    private function reject(callable $action, ?string $field = null): void
    {
        try {
            $action();
            $this->fail('Scope or policy mismatch must be rejected.');
        } catch (ValidationException $error) {
            if ($field !== null) $this->assertArrayHasKey($field, $error->errors());
            else $this->assertNotEmpty($error->errors());
        }
    }
}
