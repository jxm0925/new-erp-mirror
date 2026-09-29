<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Services\Erp\PurchaseReceiptPostingRepairApplicationService;
use App\Services\Erp\WarehouseCommandService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseCommandTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Support\CuttingTestFixtures;

    public function test_success_is_recoverable_without_reposting_and_is_actor_bound(): void
    {
        $f = $this->fixture();
        $sourceLine = PurchaseReceiptItem::where('item_id', $f['raw']->id)->firstOrFail();
        $receipt = $sourceLine->receipt->replicate();
        $receipt->fill(['receipt_no' => 'W-CMD-'.Str::ulid(), 'stock_post_status' => 'pending']); $receipt->save();
        $line = $sourceLine->replicate();
        $line->fill(['receipt_id' => $receipt->id, 'inventory_posting_status' => 'pending', 'inventory_posting_log_id' => null,
            'batch_no' => 'W-CMD-'.Str::ulid()]); $line->save();
        app(PurchaseReceiptPostingRepairApplicationService::class)->repair($receipt->id, [['receipt_item_id' => $line->id, 'allocations' => [[
            'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id, 'base_qty' => 2,
            'physical_entries' => [['dimensions' => ['length_mm' => 2000, 'width_mm' => 1000, 'thickness_mm' => 2]],
                ['dimensions' => ['length_mm' => 1800, 'width_mm' => 800, 'thickness_mm' => 2]]],
        ]]]], '仓库命令测试');
        $receiptId = $receipt->id;
        $service = app(WarehouseCommandService::class); $command = (string) Str::uuid(); $permissions = ['inventory.post.execute'];
        $count = DB::table('erp_inventory_transactions')->count();
        $first = $service->run('purchase.post', $receiptId, $command, [], $f['user'], $permissions, true);
        $this->assertEquals($first, $service->run('purchase.post', $receiptId, $command, [], $f['user'], $permissions, true));
        $this->assertSame($count + 1, DB::table('erp_inventory_transactions')->count());
        $this->assertEquals(['status' => 'SUCCEEDED', 'response' => $first], $service->result($command, $f['user'], $permissions, true));
        $other = $this->employee('warehouse-other-');
        $this->assertSame(['status' => 'NOT_FOUND'], $service->result($command, $other, $permissions, true));
        try { $service->result($command, $f['user'], [], false); $this->fail('Removed permissions must apply to recovery.'); }
        catch (WorkOrderDomainException $error) { $this->assertSame('permission_denied', $error->errorCode); }
        $this->expectException(WorkOrderDomainException::class);
        $service->run('purchase.post', $receiptId, $command, [], $other, $permissions, true);
    }

    public function test_domain_rejection_is_durable_and_does_not_change_stock(): void
    {
        $f = $this->fixture();
        $source = PurchaseReceipt::findOrFail(DB::table('erp_purchase_receipt_items')->where('item_id', $f['raw']->id)->value('receipt_id'));
        $receipt = PurchaseReceipt::create(['receipt_no' => 'W-CMD-'.Str::ulid(), 'supplier_id' => $source->supplier_id,
            'receipt_date' => now()->toDateString(), 'receipt_status' => 'draft', 'confirm_status' => 'pending', 'stock_post_status' => 'pending']);
        $service = app(WarehouseCommandService::class); $command = (string) Str::uuid(); $permissions = ['inventory.post.execute'];
        $count = DB::table('erp_inventory_transactions')->count();
        for ($i = 0; $i < 2; $i++) {
            try { $service->run('purchase.post', $receipt->id, $command, [], $f['user'], $permissions, true); $this->fail('Draft receipt must not post.'); }
            catch (WorkOrderDomainException $error) { $this->assertSame('validation_error', $error->errorCode); }
        }
        $result = $service->result($command, $f['user'], $permissions, true);
        $this->assertSame('FAILED', $result['status']);
        $this->assertSame(422, $result['response']['status']);
        $this->assertSame($count, DB::table('erp_inventory_transactions')->count());
        $this->assertSame(1, DB::table('erp_warehouse_commands')->where('client_command_id', $command)->count());
        $this->assertEquals(2, $f['balance']->fresh()->quantity_on_hand);
    }
}
