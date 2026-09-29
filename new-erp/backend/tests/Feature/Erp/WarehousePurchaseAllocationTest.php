<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{InventorySerial, InventoryTransaction, Item, Location, PurchaseReceipt, PurchaseReceiptItem};
use App\Services\Erp\{InventoryService, PurchaseReceiptPostingEligibilityService, PurchaseReceiptPostingRepairApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WarehousePurchaseAllocationTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Support\CuttingTestFixtures;

    public function test_paginated_line_saves_preserve_unseen_physical_allocations_and_only_complete_document_can_post(): void
    {
        $f = $this->fixture();
        [$receipt, $first, $second] = $this->pendingTwoLineReceipt($f);
        $repair = app(PurchaseReceiptPostingRepairApplicationService::class);
        $firstPayload = $this->physicalAllocation($f, $first, [['600', '400', '2'], ['700', '450', '2']]);
        $saved = $repair->repairSelectedLines($receipt->id, [$firstPayload], '分页仓库员');

        $this->assertCount(2, $saved->items);
        $firstSnapshot = $first->fresh()->allocations()->with('physicalEntries')->firstOrFail()->toArray();
        $this->assertCount(2, $firstSnapshot['physical_entries']);
        $this->assertSame(0, $second->allocations()->count());
        $this->assertFalse(app(PurchaseReceiptPostingEligibilityService::class)->evaluate($saved)['can_post']);
        $beforeTransactions = InventoryTransaction::count();
        try {
            app(InventoryService::class)->postPurchaseReceipt($receipt->id);
            $this->fail('An unseen unallocated line must prevent posting.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('allocations', $error->errors());
        }
        $this->assertSame($beforeTransactions, InventoryTransaction::count());
        $this->assertSame('pending', $receipt->fresh()->stock_post_status);
        $this->assertSame($firstSnapshot, $first->fresh()->allocations()->with('physicalEntries')->firstOrFail()->toArray());

        $saved = $repair->repairSelectedLines($receipt->id, [$this->physicalAllocation($f, $second,
            [['800', '500', '2'], ['900', '550', '2']])], '分页仓库员');
        $this->assertSame($firstSnapshot, $first->fresh()->allocations()->with('physicalEntries')->firstOrFail()->toArray());
        $this->assertTrue(app(PurchaseReceiptPostingEligibilityService::class)->evaluate($saved)['can_post']);
        $transaction = app(InventoryService::class)->postPurchaseReceipt($receipt->id);
        $this->assertSame('posted', $receipt->fresh()->stock_post_status);
        $this->assertSame($beforeTransactions + 1, InventoryTransaction::count());
        $this->assertCount(2, $transaction->items);
        $this->assertEquals(4, $transaction->items->sum('change_qty'));
        $pieces = DB::table('erp_material_physicals')->whereIn('source_transaction_item_id', $transaction->items->pluck('id'))
            ->orderBy('id')->get();
        $this->assertCount(4, $pieces);
        $dimensions = $pieces->map(fn ($piece) => json_decode($piece->dimensions, true))->all();
        foreach ([['600', '400'], ['700', '450'], ['800', '500'], ['900', '550']] as $expected) {
            $matches = array_filter($dimensions, fn ($value) => (float) $value['length_mm'] === (float) $expected[0]
                && (float) $value['width_mm'] === (float) $expected[1] && (float) $value['thickness_mm'] === 2.0);
            $this->assertCount(1, $matches, 'Every saved physical dimension must survive the second page and posting.');
        }
    }

    public function test_partial_serial_line_save_rebinds_same_identity_without_requiring_unseen_line(): void
    {
        $f = $this->fixture();
        $serialItem = $f['raw']->replicate();
        $serialItem->fill(['item_code' => 'W-SERIAL-'.Str::ulid(), 'material_management_mode' => 'quantity',
            'cutting_mode' => 'none', 'serial_tracking_mode' => 'required']);
        $serialItem->save();
        [$receipt, $first, $second] = $this->pendingTwoLineReceipt($f, $serialItem);
        $firstNos = ['W-SN-'.Str::ulid(), 'W-SN-'.Str::ulid()];
        $secondNos = ['W-SN-'.Str::ulid(), 'W-SN-'.Str::ulid()];
        foreach ([[$first, $firstNos], [$second, $secondNos]] as [$line, $numbers]) $line->update([
            'serial_text' => implode("\n", $numbers), 'serial_number_source' => 'supplier',
            'serial_entries' => array_map(fn ($number) => ['serial_no' => $number, 'source' => 'supplier'], $numbers),
        ]);
        $repair = app(PurchaseReceiptPostingRepairApplicationService::class);
        $payload = fn ($line, $numbers, $location) => ['receipt_item_id' => $line->id, 'expected_revision' => $repair->allocationRevision($line), 'allocations' => [[
            'warehouse_id' => $f['warehouse']->id, 'location_id' => $location, 'base_qty' => '2', 'serial_nos' => $numbers,
        ]]];
        $repair->repairSelectedLines($receipt->id, [$payload($first, $firstNos, $f['location']->id)], '分页仓库员');
        $identities = InventorySerial::where('source_receipt_item_id', $first->id)->orderBy('serial_no')->pluck('id', 'serial_no')->all();
        $this->assertCount(2, $identities);
        $this->assertSame(0, InventorySerial::where('source_receipt_item_id', $second->id)->count());
        $this->assertSame(0, $second->allocations()->count());

        $newLocation = Location::create(['location_code' => 'W-LOC-'.Str::ulid(), 'location_name' => '重新分配库位',
            'warehouse_id' => $f['warehouse']->id, 'status' => 'enabled']);
        $repair->repairSelectedLines($receipt->id, [$payload($first, $firstNos, $newLocation->id)], '分页仓库员');
        $this->assertSame($identities, InventorySerial::where('source_receipt_item_id', $first->id)->orderBy('serial_no')->pluck('id', 'serial_no')->all());
        $this->assertSame(2, InventorySerial::where('source_receipt_item_id', $first->id)->where('location_id', $newLocation->id)
            ->where('serial_status', 'pending_posting')->count());
        $this->assertSame(2, DB::table('erp_inventory_serial_events')->whereIn('inventory_serial_id', array_values($identities))
            ->where('event_type', 'receipt_accepted')->count());

        $repair->repairSelectedLines($receipt->id, [$payload($second, $secondNos, $f['location']->id)], '分页仓库员');
        app(InventoryService::class)->postPurchaseReceipt($receipt->id);
        $this->assertSame(4, InventorySerial::where('source_receipt_id', $receipt->id)->where('serial_status', 'available')->count());
        $this->assertSame($identities, InventorySerial::where('source_receipt_item_id', $first->id)->orderBy('serial_no')->pluck('id', 'serial_no')->all());
        $this->assertSame(2, InventorySerial::where('source_receipt_item_id', $first->id)->where('location_id', $newLocation->id)->count());
    }

    public function test_stale_line_revision_cannot_replace_newer_saved_allocation(): void
    {
        $f = $this->fixture(); [$receipt, $line] = $this->pendingTwoLineReceipt($f);
        $repair = app(PurchaseReceiptPostingRepairApplicationService::class);
        $stale = $this->physicalAllocation($f, $line, [['600', '400', '2'], ['700', '450', '2']]);
        $displayed = $line->allocations()->with('physicalEntries')->get();
        $repair->repairSelectedLines($receipt->id, [$stale], '第一位仓库员');
        $current = $repair->allocationRevision($line);
        $this->assertSame($stale['expected_revision'], $repair->allocationRevision($line, $displayed));
        $this->assertNotSame($stale['expected_revision'], $current);
        $stale['allocations'][0]['physical_entries'][0]['dimensions']['length_mm'] = '999';
        try {
            $repair->repairSelectedLines($receipt->id, [$stale], '第二位仓库员');
            $this->fail('Stale allocation must be rejected.');
        } catch (\App\Exceptions\Erp\WorkOrderDomainException $error) {
            $this->assertSame('入库分配已变化，请刷新明细后重新核对。', $error->getMessage());
        }
        $this->assertSame($current, $repair->allocationRevision($line));
        $stale['expected_revision'] = $current;
        $repair->repairSelectedLines($receipt->id, [$stale], '刷新后的仓库员');
        $this->assertNotSame($current, $repair->allocationRevision($line));
        $this->assertSame('pending', $receipt->fresh()->stock_post_status);
    }

    private function pendingTwoLineReceipt(array $f, ?Item $item = null): array
    {
        $source = PurchaseReceiptItem::where('item_id', $f['raw']->id)->firstOrFail();
        $receipt = $source->receipt->replicate();
        $receipt->fill(['receipt_no' => 'W-PAGED-'.Str::ulid(), 'stock_post_status' => 'pending',
            'total_receipt_qty' => 4, 'total_qualified_qty' => 4, 'total_amount' => 12000]);
        $receipt->save();
        $lines = [];
        for ($i = 0; $i < 2; $i++) {
            $line = $source->replicate();
            $line->fill(['receipt_id' => $receipt->id, 'item_id' => ($item ?? $f['raw'])->id,
                'warehouse_id' => null, 'location_id' => null, 'inventory_posting_status' => 'pending',
                'inventory_posting_log_id' => null, 'batch_no' => 'W-BATCH-'.Str::ulid()]);
            $line->save(); $lines[] = $line;
        }
        return [$receipt, ...$lines];
    }

    private function physicalAllocation(array $f, PurchaseReceiptItem $line, array $dimensions): array
    {
        return ['receipt_item_id' => $line->id, 'expected_revision' => app(PurchaseReceiptPostingRepairApplicationService::class)->allocationRevision($line), 'allocations' => [[
            'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id, 'base_qty' => '2',
            'physical_entries' => array_map(fn ($d) => ['dimensions' => ['length_mm' => $d[0], 'width_mm' => $d[1], 'thickness_mm' => $d[2]]], $dimensions),
        ]]];
    }
}
