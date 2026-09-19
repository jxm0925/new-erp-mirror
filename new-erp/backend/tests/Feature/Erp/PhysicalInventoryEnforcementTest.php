<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Location;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\Warehouse;
use App\Services\Erp\InventoryAdjustmentApplicationService;
use App\Services\Erp\InventoryService;
use App\Services\Erp\PhysicalInventoryApplicationService;
use App\Services\Erp\PurchaseReceiptPostingRepairApplicationService;
use App\Services\Erp\PurchaseReturnApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhysicalInventoryEnforcementTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Support\CuttingTestFixtures;

    public function test_purchase_posting_creates_every_plate_identity_in_the_same_transaction(): void
    {
        $fixture = $this->fixture();
        $rows = DB::table('erp_material_physicals')
            ->whereIn('id', $fixture['physicals'])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEquals(2.0, (float) $fixture['balance']->fresh()->quantity_on_hand);
        $this->assertSame('6000.0000', $rows->reduce(fn (string $sum, object $row): string => bcadd($sum, (string) $row->total_cost, 4), '0'));
        foreach ($rows as $row) {
            $this->assertSame((int) $row->id, (int) $row->root_physical_id);
            $this->assertSame('AVAILABLE', $row->status);
            $this->assertNotNull($row->source_transaction_item_id);
            $holding = DB::table('erp_material_holdings')->where('id', $row->current_holding_id)->first();
            $this->assertSame('WAREHOUSE', $holding->position_type);
            $this->assertSame((int) $fixture['balance']->id, (int) $holding->inventory_balance_id);
        }
        $this->assertSame(2, DB::table('erp_purchase_receipt_allocation_physicals')
            ->whereIn('physical_material_id', $fixture['physicals'])->whereNotNull('inventory_transaction_item_id')->count());
    }

    public function test_physical_adjustment_requires_per_plate_facts_and_moves_or_creates_exact_identity(): void
    {
        $fixture = $this->fixture();
        $service = app(InventoryAdjustmentApplicationService::class);
        $base = [
            'item_id' => $fixture['raw']->id,
            'warehouse_id' => $fixture['warehouse']->id,
            'location_id' => $fixture['location']->id,
            'batch_no' => $fixture['balance']->batch_no,
            'unit_id' => $fixture['raw']->unit_id,
        ];

        try {
            $service->save(['reason' => '盘点差异', 'items' => [$base + ['change_qty' => -1]]]);
            $this->fail('A physical adjustment without identities must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('physical_entries', $exception->errors());
        }

        $decrease = $service->save(['reason' => '盘点差异', 'items' => [$base + [
            'change_qty' => -1,
            'physical_entries' => [['physical_material_id' => $fixture['physicals'][0]]],
        ]]]);
        $service->submit($decrease->id);
        app(InventoryService::class)->postAdjustment($decrease->id);
        $this->assertEquals(1.0, (float) $fixture['balance']->fresh()->quantity_on_hand);
        $this->assertSame('ADJUSTED_OUT', DB::table('erp_material_physicals')->where('id', $fixture['physicals'][0])->value('status'));

        $increase = $service->save(['reason' => '盘点差异', 'items' => [$base + [
            'change_qty' => 1,
            'physical_entries' => [[
                'dimensions' => ['length_mm' => '2440', 'width_mm' => '1220', 'thickness_mm' => '2'],
                'total_cost' => '3100.1234',
            ]],
        ]]]);
        $service->submit($increase->id);
        app(InventoryService::class)->postAdjustment($increase->id);

        $entry = DB::table('erp_inventory_adjustment_item_physicals as entry')
            ->join('erp_inventory_adjustment_items as line', 'line.id', '=', 'entry.adjustment_item_id')
            ->where('line.adjustment_id', $increase->id)->first();
        $created = DB::table('erp_material_physicals')->where('id', $entry->physical_material_id)->first();
        $this->assertEquals(2.0, (float) $fixture['balance']->fresh()->quantity_on_hand);
        $this->assertSame('AVAILABLE', $created->status);
        $this->assertSame('3100.1234', (string) $created->total_cost);
        $this->assertNotNull($entry->inventory_transaction_item_id);
    }

    public function test_physical_transfer_and_disposal_preserve_identity_cost_and_replay_actor(): void
    {
        $fixture = $this->fixture();
        $suffix = strtoupper(substr((string) Str::ulid(), -8));
        $warehouse = Warehouse::create(['warehouse_code' => 'PHY-T-'.$suffix, 'warehouse_name' => '实物调拨目标仓', 'status' => 'enabled']);
        $location = Location::create(['location_code' => 'PHY-L-'.$suffix, 'location_name' => '实物调拨目标库位', 'warehouse_id' => $warehouse->id, 'status' => 'enabled']);
        $service = app(PhysicalInventoryApplicationService::class);
        $transferPayload = [
            'client_command_id' => (string) Str::uuid(),
            'expected_version' => 1,
            'target_warehouse_id' => $warehouse->id,
            'target_location_id' => $location->id,
            'target_batch_no' => 'PHY-MOVE-'.$suffix,
            'reason' => '生产仓位调整',
        ];
        $result = $service->transfer($fixture['physicals'][0], $transferPayload, $fixture['user']);
        $replay = $service->transfer($fixture['physicals'][0], $transferPayload, $fixture['user']);
        $this->assertSame($result, $replay);
        $this->assertSame(1, DB::table('erp_material_physical_transfers')->where('client_command_id', $transferPayload['client_command_id'])->count());
        try {
            $service->transfer($fixture['physicals'][0], $transferPayload, $this->employee('physical-replay-'));
            $this->fail('A different actor must not replay another actor\'s physical transfer command.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('client_command_id', $exception->errors());
        }
        $target = InventoryBalance::findOrFail($result['target_inventory_balance_id']);
        $this->assertEquals(1.0, (float) $target->quantity_on_hand);
        $this->assertSame('3000.0000', (string) $target->inventory_value);

        $physical = DB::table('erp_material_physicals')->where('id', $fixture['physicals'][0])->first();
        $this->assertSame('AVAILABLE', $physical->status);
        $this->assertSame(2, (int) $physical->business_version);
        $disposePayload = [
            'client_command_id' => (string) Str::uuid(),
            'expected_version' => 2,
            'reason' => '盘点确认无法继续使用',
        ];
        $disposed = $service->dispose($physical->id, $disposePayload, $fixture['user']);
        $disposedReplay = $service->dispose($physical->id, $disposePayload, $fixture['user']);
        $this->assertSame($disposed, $disposedReplay);
        try {
            $service->dispose($physical->id, $disposePayload, $this->employee('physical-dispose-replay-'));
            $this->fail('A different actor must not replay another actor\'s disposal command.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('client_command_id', $exception->errors());
        }
        $this->assertEquals(0.0, (float) $target->fresh()->quantity_on_hand);
        $this->assertSame('DISPOSED', DB::table('erp_material_physicals')->where('id', $physical->id)->value('status'));
        $this->assertSame('3000.0000', $disposed['total_cost']);
    }

    public function test_purchase_return_requires_and_posts_the_exact_original_plate(): void
    {
        $fixture = $this->fixture();
        $physical = DB::table('erp_material_physicals')->where('id', $fixture['physicals'][0])->first();
        $transactionLine = InventoryTransactionItem::findOrFail($physical->source_transaction_item_id);
        $receiptLine = PurchaseReceiptItem::with('receipt')->findOrFail($transactionLine->source_item_id);
        $service = app(PurchaseReturnApplicationService::class);
        $return = $service->create([
            'return_scope' => 'posted_inventory',
            'source_receipt_id' => $receiptLine->receipt_id,
            'supplier_id' => $receiptLine->receipt->supplier_id,
            'return_reason' => '供应商材料不符',
            'items' => [[
                'source_receipt_item_id' => $receiptLine->id,
                'warehouse_id' => $fixture['warehouse']->id,
                'location_id' => $fixture['location']->id,
                'batch_no' => $fixture['balance']->batch_no,
                'requested_return_qty' => 1,
                'return_unit_id' => $receiptLine->base_unit_id,
                'physical_material_ids' => [$physical->id],
            ]],
        ], (int) $fixture['user']->legacy_id, '实物退货测试');
        $service->submit($return->id, (int) $fixture['user']->legacy_id, '实物退货测试');
        $service->approve($return->id, (int) $fixture['user']->legacy_id, '实物退货测试');
        $posted = $service->post($return->id, (int) $fixture['user']->legacy_id, '实物退货测试');

        $this->assertSame('completed', $posted->return_status);
        $this->assertEquals(1.0, (float) $fixture['balance']->fresh()->quantity_on_hand);
        $this->assertSame('RETURNED', DB::table('erp_material_physicals')->where('id', $physical->id)->value('status'));
        $this->assertDatabaseHas('erp_purchase_return_item_physicals', [
            'physical_material_id' => $physical->id,
        ]);
        $this->assertNotNull(DB::table('erp_purchase_return_item_physicals')->where('physical_material_id', $physical->id)->value('inventory_transaction_item_id'));
    }

    public function test_confirmed_physical_receipt_without_per_plate_dimensions_cannot_post(): void
    {
        $fixture = $this->fixture();
        $sourcePhysical = DB::table('erp_material_physicals')->where('id', $fixture['physicals'][0])->first();
        $sourceTransactionLine = InventoryTransactionItem::findOrFail($sourcePhysical->source_transaction_item_id);
        $sourceLine = PurchaseReceiptItem::with('receipt')->findOrFail($sourceTransactionLine->source_item_id);
        $receipt = PurchaseReceipt::create([
            'receipt_no' => 'PHY-MISSING-'.Str::ulid(),
            'supplier_id' => $sourceLine->receipt->supplier_id,
            'receipt_date' => now()->toDateString(),
            'receipt_status' => 'confirmed',
            'confirm_status' => 'confirmed',
            'stock_post_status' => 'pending',
        ]);
        $line = PurchaseReceiptItem::create([
            ...$sourceLine->only([
                'item_id', 'purchase_unit_id', 'purchase_unit_name_snapshot', 'conversion_factor_snapshot',
                'base_unit_id', 'base_unit_name_snapshot', 'is_stock_item_snapshot', 'unit_price',
            ]),
            'receipt_id' => $receipt->id,
            'receipt_qty' => 1,
            'qualified_qty' => 1,
            'unqualified_qty' => 0,
            'standard_base_qty' => 1,
            'actual_base_qty' => 1,
            'qualified_base_qty' => 1,
            'unqualified_base_qty' => 0,
            'final_stockable_base_qty' => 1,
            'inventory_cost_amount' => '3000.0000',
            'batch_no' => 'PHY-MISSING-'.Str::ulid(),
            'inventory_posting_status' => 'pending',
        ]);

        $this->expectException(ValidationException::class);
        app(PurchaseReceiptPostingRepairApplicationService::class)->repair($receipt->id, [[
            'receipt_item_id' => $line->id,
            'allocations' => [[
                'warehouse_id' => $fixture['warehouse']->id,
                'location_id' => $fixture['location']->id,
                'base_qty' => 1,
                'serial_nos' => [],
            ]],
        ]], '缺少实物尺寸测试');
    }
}
