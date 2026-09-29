<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\{CuttingConfirmationService, WarehouseDocumentService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WarehouseDocumentTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Support\CuttingTestFixtures;

    public function test_purchase_document_is_paginated_and_posted_receipt_is_read_only(): void
    {
        $f = $this->fixture();
        $id = DB::table('erp_purchase_receipt_items')->where('item_id', $f['raw']->id)->value('receipt_id');
        $read = app(WarehouseDocumentService::class)->show('purchase_receipt', $id, ['per_page' => 1], $f['user'], ['inventory.post.view', 'inventory.post.execute'], true);
        $this->assertCount(1, $read['lines']['data']); $this->assertSame(1, $read['lines']['per_page']);
        $this->assertSame([], $read['actions']); $this->assertNotEmpty($read['receipt']['transaction_no']);
        $this->assertCount(2, $read['lines']['data'][0]['allocations'][0]['physical_entries']);
        $this->expectException(WorkOrderDomainException::class);
        app(WarehouseDocumentService::class)->show('purchase_receipt', $id, [], $f['user'], [], true);
    }

    public function test_cut_product_detail_uses_the_original_lot_and_authorized_remaining_quantity(): void
    {
        $f = $this->fixture(); $batch = $this->issue($f); $batchId = $batch['settlement_batch_id'];
        $result = $this->save($f, $batchId, '5')['result_ids'][0];
        $route = $this->route($f, $result, '5')['routes'][0]['id'];
        app(CuttingConfirmationService::class)->confirm($batchId, $this->confirmation($f, $batchId, $result, '3000'), $f['user'], self::PERMISSIONS, true);
        $read = app(WarehouseDocumentService::class)->show('cutting_product', $route, [], $f['user'], self::PERMISSIONS, true);
        $this->assertSame('5.00000000', $read['header']['remaining_qty']);
        $this->assertSame(['cutting.warehouse'], $read['actions']);
        $holding = DB::table('erp_material_holdings')->where('id', $read['header']['holding_id'])->first();
        $this->assertSame(DB::table('erp_material_lots')->where('id', $holding->material_lot_id)->value('lot_no'), $read['header']['batch_no']);
        $this->expectException(WorkOrderDomainException::class);
        app(WarehouseDocumentService::class)->show('cutting_product', $route, ['receipt_id' => 2147483647], $f['user'], self::PERMISSIONS, true);
    }
}
