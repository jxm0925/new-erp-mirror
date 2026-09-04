<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{
    InventoryBalance,
    InventoryBatch,
    InventoryLocationBalance,
    InventoryReservation,
    Item,
    ItemPurchaseConversion,
    ItemSupplierPrice,
    Location,
    Product,
    PurchaseOrder,
    PurchaseOrderItem,
    SalesOrder,
    SalesOrderAttachment,
    SalesOrderFulfillment,
    SalesOrderLine,
    Sku,
    SkuItemRelation,
    Supplier,
    SupplierItemRelation,
    Unit,
    Warehouse
};
use App\Services\Erp\{
    BomMatcher,
    DocumentNumberService,
    PurchaseConversionApplicationService,
    SalesOrderFulfillmentApplicationService,
    SupplierQuotationService,
    UnitConversionDomainService
};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

class UnitConversionBusinessScenariosTest extends TestCase
{
    use DatabaseTransactions;

    private bool $mixedFulfillmentRetryScenario = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(BomMatcher::class, function (MockInterface $mock) {
            $calls = 0;
            $mock->shouldReceive('match')->andReturnUsing(function () use (&$calls) {
                $calls++;
                if ($this->mixedFulfillmentRetryScenario && $calls === 2) {
                    return [
                        'status' => 'blocked', 'block_reason' => 'QA temporary BOM block',
                        'bom_id' => null, 'bom_version_id' => null, 'bom_version' => null,
                        'bom_snapshot' => null, 'candidates' => [],
                    ];
                }
                return [
                    'status' => 'matched', 'block_reason' => null,
                    'bom_id' => 9001, 'bom_version_id' => 9001, 'bom_version' => 'V1.0',
                    'bom_snapshot' => ['id' => 9001, 'bom_no' => 'QA-BOM-001', 'version' => 'V1.0'],
                    'candidates' => [],
                ];
            });
        });
        $sequence = 0;
        $this->mock(DocumentNumberService::class, function (MockInterface $mock) use (&$sequence) {
            $mock->shouldReceive('next')->andReturnUsing(function () use (&$sequence) {
                return 'QA-SOPR-'.str_pad((string) ++$sequence, 4, '0', STR_PAD_LEFT);
            });
        });
    }

    public function test_01_all_inventory_uses_item_base_quantity(): void
    {
        [$order, $line] = $this->physicalOrder(10, 2.5, 25);
        $this->confirm($order, [$this->decision($line, 10, inventory: 10)]);

        $this->assertDatabaseHas('erp_sales_order_fulfillments', [
            'sales_order_line_id' => $line->id, 'fulfillment_type' => 'inventory',
            'sales_qty' => 10, 'fulfillment_qty' => 25, 'item_base_qty' => 25,
        ]);
        $this->assertDatabaseCount('erp_sales_order_production_requirements', 0);
        $this->assertSame('pending', $order->fresh()->fulfillment_status);
    }

    public function test_02_mixed_inventory_and_production_preserves_both_quantity_dialects(): void
    {
        [$order, $line] = $this->physicalOrder(10, 2.5, 10);
        $workOrderFootprint = $this->workOrderFootprint();
        $this->confirm($order, [$this->decision($line, 10, inventory: 4, production: 6)]);

        $this->assertDatabaseHas('erp_sales_order_fulfillments', [
            'sales_order_line_id' => $line->id, 'fulfillment_type' => 'inventory',
            'sales_qty' => 4, 'fulfillment_qty' => 10, 'item_base_qty' => 10,
        ]);
        $this->assertDatabaseHas('erp_sales_order_fulfillments', [
            'sales_order_line_id' => $line->id, 'fulfillment_type' => 'production',
            'sales_qty' => 6, 'fulfillment_qty' => 15, 'item_base_qty' => 15,
        ]);
        $this->assertDatabaseHas('erp_sales_order_production_requirements', [
            'sales_order_line_id' => $line->id, 'production_qty' => 6,
            'item_base_required_qty' => 15, 'is_ready_for_work_order' => false,
        ]);
        $this->assertSame('confirmed', $order->fresh()->production_confirm_status);
        $this->assertSame('pending', $order->fresh()->fulfillment_status);
        $this->assertSame(10.0, (float) DB::table('erp_sales_order_fulfillments')
            ->where('sales_order_line_id', $line->id)->sum('sales_qty'));
        $this->assertDatabaseMissing('erp_sales_order_fulfillments', [
            'sales_order_line_id' => $line->id,
            'fulfillment_type' => 'undetermined',
        ]);
        $this->assertSame($workOrderFootprint, $this->workOrderFootprint());
    }

    public function test_03_all_production_creates_requirement_contract_but_no_work_order(): void
    {
        [$order, $line] = $this->physicalOrder(10, 2.5, 0);
        $before = $this->workOrderFootprint();
        $this->confirm($order, [$this->decision($line, 10, production: 10)]);

        $this->assertDatabaseHas('erp_sales_order_production_requirements', [
            'sales_order_line_id' => $line->id, 'production_qty' => 10,
            'item_base_required_qty' => 25, 'requirement_status' => 'ready',
        ]);
        $this->assertSame($before, $this->workOrderFootprint());
    }

    public function test_04_mixed_blocked_retry_supersedes_ready_requirement_without_duplicate_active_demand(): void
    {
        $this->mixedFulfillmentRetryScenario = true;
        [$order, $first] = $this->physicalOrder(2, 1, 0);
        $second = $first->replicate(['line_uuid']);
        $second->line_no = 2;
        $second->line_uuid = 'QA-MIXED-RETRY-2';
        $second->save();

        $decisions = [$this->decision($first, 2, production: 2), $this->decision($second, 2, production: 2)];
        $this->confirm($order, $decisions);
        $this->assertSame('blocked', $order->fresh()->production_confirm_status);
        $this->assertDatabaseCount('erp_sales_order_production_requirements', 2);
        $firstDemandId = DB::table('erp_sales_order_production_requirements')
            ->where('sales_order_line_id', $first->id)->where('is_active', true)->value('id');
        DB::table('erp_work_orders')->insert([
            'work_order_no' => 'QA-MIXED-RETRY-WO', 'production_demand_id' => $firstDemandId,
            'target_qty' => 2, 'target_base_qty' => 2, 'status' => 'DRAFT', 'business_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->confirm($order->fresh(), $decisions);

        $this->assertDatabaseCount('erp_sales_order_production_requirements', 3);
        $this->assertSame(2, DB::table('erp_sales_order_production_requirements')
            ->where('sales_order_id', $order->id)->where('is_active', true)->count());
        $this->assertSame(1, DB::table('erp_sales_order_production_requirements')
            ->where('sales_order_line_id', $first->id)->where('is_active', true)->count());
        $this->assertSame(1, DB::table('erp_sales_order_production_requirements')
            ->where('sales_order_line_id', $second->id)->where('is_active', true)->count());
        $this->assertSame(1, DB::table('erp_sales_order_production_requirements')
            ->where('sales_order_line_id', $first->id)->where('requirement_status', 'ready')->where('is_active', true)->count());
        $this->assertDatabaseHas('erp_work_orders', ['production_demand_id' => $firstDemandId]);
    }

    public function test_04b_retry_converges_unreferenced_duplicate_active_demand_to_referenced_lineage(): void
    {
        [$order, $line] = $this->physicalOrder(2, 1, 0);
        $this->confirm($order, [$this->decision($line, 2, production: 2)]);
        $demandId = DB::table('erp_sales_order_production_requirements')
            ->where('sales_order_line_id', $line->id)->where('is_active', true)->value('id');
        $copy = (array) DB::table('erp_sales_order_production_requirements')->where('id', $demandId)->first();
        unset($copy['id']);
        $copy['requirement_no'] = 'QA-DUPLICATE-ACTIVE';
        $copy['created_at'] = now();
        $copy['updated_at'] = now();
        DB::table('erp_sales_order_production_requirements')->insert($copy);
        $duplicateId = (int) DB::getPdo()->lastInsertId();
        DB::table('erp_work_orders')->insert([
            'work_order_no' => 'QA-DUPLICATE-LINEAGE-WO', 'production_demand_id' => $demandId,
            'target_qty' => 1, 'target_base_qty' => 1, 'status' => 'DRAFT', 'business_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_sales_order_fulfillments')->where('sales_order_id', $order->id)->delete();
        $order->production_confirm_status = 'blocked';
        $order->save();

        $this->confirm($order->fresh(), [$this->decision($line, 2, production: 2)]);

        $this->assertSame(1, DB::table('erp_sales_order_production_requirements')
            ->where('sales_order_line_id', $line->id)->where('is_active', true)->count());
        $this->assertDatabaseHas('erp_sales_order_production_requirements', [
            'id' => $duplicateId, 'is_active' => 0, 'requirement_status' => 'superseded', 'superseded_by_id' => $demandId,
        ]);
        $this->assertNotNull(DB::table('erp_sales_order_production_requirements')->where('id', $duplicateId)->value('superseded_reason'));
        $this->assertDatabaseHas('erp_work_orders', ['production_demand_id' => $demandId]);
    }

    public function test_04c_retry_rejects_unbound_consumed_demand_without_superseding_history(): void
    {
        [$order, $line] = $this->physicalOrder(2, 1, 0);
        $this->confirm($order, [$this->decision($line, 2, production: 2)]);
        $demandId = (int) DB::table('erp_sales_order_production_requirements')
            ->where('sales_order_line_id', $line->id)->where('is_active', true)->value('id');
        DB::table('erp_sales_order_production_requirements')->where('id', $demandId)->update([
            'requirement_status' => 'consumed', 'consumed_qty' => 1, 'remaining_qty' => 1,
        ]);
        DB::table('erp_sales_order_fulfillments')->where('sales_order_id', $order->id)->delete();
        $order->production_confirm_status = 'blocked';
        $order->save();

        try {
            $this->confirm($order->fresh(), [$this->decision($line, 2, production: 2)]);
            $this->fail('A consumed demand without a WorkOrder must not be superseded during retry.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('production_requirements', $exception->errors());
        }
        $this->assertDatabaseHas('erp_sales_order_production_requirements', [
            'id' => $demandId, 'is_active' => 1, 'requirement_status' => 'consumed', 'consumed_qty' => 1,
        ]);
    }

    public function test_04d_retry_production_removal_rejects_unbound_partially_consumed_demand(): void
    {
        [$order, $line] = $this->physicalOrder(2, 1, 0);
        $this->confirm($order, [$this->decision($line, 2, production: 2)]);
        $demandId = (int) DB::table('erp_sales_order_production_requirements')
            ->where('sales_order_line_id', $line->id)->where('is_active', true)->value('id');
        DB::table('erp_sales_order_production_requirements')->where('id', $demandId)->update([
            'requirement_status' => 'partially_consumed', 'consumed_qty' => 1, 'remaining_qty' => 1,
        ]);
        DB::table('erp_sales_order_fulfillments')->where('sales_order_id', $order->id)->delete();
        $order->production_confirm_status = 'blocked';
        $order->save();

        try {
            $this->confirm($order->fresh(), [$this->decision($line, 0, production: 0)]);
            $this->fail('Removing production quantity must not supersede a partially consumed demand without a WorkOrder.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('production_requirements', $exception->errors());
        }
        $this->assertDatabaseHas('erp_sales_order_production_requirements', [
            'id' => $demandId, 'is_active' => 1, 'requirement_status' => 'partially_consumed', 'consumed_qty' => 1,
        ]);
    }

    public function test_04e_retry_production_removal_rejects_unbound_closed_execution_demand(): void
    {
        [$order, $line] = $this->physicalOrder(2, 1, 0);
        $this->confirm($order, [$this->decision($line, 2, production: 2)]);
        $demandId = (int) DB::table('erp_sales_order_production_requirements')
            ->where('sales_order_line_id', $line->id)->where('is_active', true)->value('id');
        DB::table('erp_sales_order_production_requirements')->where('id', $demandId)->update([
            'requirement_status' => 'closed', 'consumed_qty' => 1, 'closed_qty' => 1, 'remaining_qty' => 0,
        ]);
        DB::table('erp_sales_order_fulfillments')->where('sales_order_id', $order->id)->delete();
        $order->production_confirm_status = 'blocked';
        $order->save();

        try {
            $this->confirm($order->fresh(), [$this->decision($line, 0, production: 0)]);
            $this->fail('Removing production quantity must not supersede a closed demand without a WorkOrder.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('production_requirements', $exception->errors());
        }
        $this->assertDatabaseHas('erp_sales_order_production_requirements', [
            'id' => $demandId, 'is_active' => 1, 'requirement_status' => 'closed', 'consumed_qty' => 1, 'closed_qty' => 1,
        ]);
    }

    public function test_04a_service_line_never_enters_inventory_or_production(): void
    {
        [$order, $line] = $this->nonPhysicalOrder('service', 3);
        $this->confirm($order, [$this->decision($line, 3, service: 3)]);

        $this->assertDatabaseHas('erp_sales_order_fulfillments', [
            'sales_order_line_id' => $line->id, 'fulfillment_type' => 'service',
            'sales_qty' => 3, 'item_id' => null, 'item_base_qty' => null,
        ]);
        $this->assertDatabaseCount('erp_sales_order_production_requirements', 0);
    }

    public function test_05_no_delivery_line_never_enters_inventory_or_production(): void
    {
        [$order, $line] = $this->nonPhysicalOrder('no_delivery', 2);
        $this->confirm($order, [$this->decision($line, 2, noDelivery: 2)]);

        $this->assertDatabaseHas('erp_sales_order_fulfillments', [
            'sales_order_line_id' => $line->id, 'fulfillment_type' => 'no_delivery',
            'sales_qty' => 2, 'item_id' => null, 'item_base_qty' => null,
        ]);
        $this->assertDatabaseCount('erp_sales_order_production_requirements', 0);
    }

    public function test_06_same_sku_different_configuration_remains_two_independent_lines(): void
    {
        [$order, $first] = $this->physicalOrder(2, 1, 10, ['voltage' => '220V']);
        $second = $first->replicate(['line_uuid']);
        $second->line_no = 2;
        $second->line_uuid = 'QA-LINE-2';
        $second->configuration_snapshot = ['voltage' => '380V'];
        $second->save();

        $this->confirm($order, [
            $this->decision($first, 2, inventory: 2),
            $this->decision($second, 2, inventory: 2),
        ]);
        $this->assertDatabaseHas('erp_sales_order_fulfillments', ['sales_order_line_id' => $first->id, 'sales_qty' => 2]);
        $this->assertDatabaseHas('erp_sales_order_fulfillments', ['sales_order_line_id' => $second->id, 'sales_qty' => 2]);
    }

    public function test_07_special_custom_missing_drawing_blocks_and_rolls_back(): void
    {
        [$order, $line] = $this->physicalOrder(1, 1, 0);
        $line->update(['is_special_customized' => true]);

        try {
            $this->confirm($order, [$this->decision($line, 1, production: 1)]);
            $this->fail('Special custom line without a drawing must be blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attachments', $exception->errors());
        }
        $this->assertDatabaseCount('erp_sales_order_fulfillments', 0);
        $this->assertDatabaseCount('erp_sales_order_production_requirements', 0);
    }

    public function test_08_special_custom_with_drawing_can_create_production_requirement(): void
    {
        [$order, $line] = $this->physicalOrder(1, 1, 0);
        $line->update(['is_special_customized' => true]);
        SalesOrderAttachment::create([
            'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id,
            'attachment_scope' => 'line', 'attachment_type' => 'design_drawing',
            'original_name' => 'qa-drawing.pdf', 'stored_name' => 'qa-drawing.pdf',
            'storage_disk' => 'oss', 'storage_path' => 'qa/qa-drawing.pdf',
            'url' => 'https://example.invalid/qa-drawing.pdf', 'status' => 'active',
        ]);
        $this->confirm($order, [$this->decision($line, 1, production: 1)]);

        $this->assertDatabaseHas('erp_sales_order_production_requirements', [
            'sales_order_line_id' => $line->id, 'requirement_status' => 'ready',
        ]);
    }

    public function test_09_standard_package_receipt_uses_locked_factor(): void
    {
        [$item, $package] = $this->purchaseFixture(25, false);
        $snapshot = app(PurchaseConversionApplicationService::class)->receiptLineSnapshot([
            'item_id' => $item->id, 'purchase_unit_id' => $package->id,
            'receipt_qty' => 2, 'qualified_qty' => 2, 'unqualified_qty' => 0,
        ]);
        $this->assertSame(50.0, $snapshot['standard_base_qty']);
        $this->assertSame(50.0, $snapshot['actual_base_qty']);
    }

    public function test_10_actual_weight_difference_requires_and_keeps_reasoned_quantity(): void
    {
        [$item, $package] = $this->purchaseFixture(25, true);
        $snapshot = app(PurchaseConversionApplicationService::class)->receiptLineSnapshot([
            'item_id' => $item->id, 'purchase_unit_id' => $package->id,
            'receipt_qty' => 2, 'qualified_qty' => 2, 'unqualified_qty' => 0,
            'actual_base_qty' => 49.5, 'difference_reason' => '实际称重差异',
        ]);
        $this->assertSame(50.0, $snapshot['standard_base_qty']);
        $this->assertSame(49.5, $snapshot['actual_base_qty']);
        $this->assertSame(-0.5, $snapshot['difference_qty']);
    }

    public function test_11_qualified_and_unqualified_base_quantities_are_conserved(): void
    {
        [$item, $package] = $this->purchaseFixture(25, true);
        $snapshot = app(PurchaseConversionApplicationService::class)->receiptLineSnapshot([
            'item_id' => $item->id, 'purchase_unit_id' => $package->id,
            'receipt_qty' => 10, 'qualified_qty' => 8, 'unqualified_qty' => 2,
            'actual_base_qty' => 250, 'difference_reason' => null,
        ]);
        $this->assertSame(200.0, $snapshot['qualified_base_qty']);
        $this->assertSame(50.0, $snapshot['unqualified_base_qty']);
        $this->assertSame($snapshot['actual_base_qty'], $snapshot['qualified_base_qty'] + $snapshot['unqualified_base_qty']);
    }

    public function test_12_supplier_specific_factor_is_stored_with_quote(): void
    {
        [$item, $package] = $this->purchaseFixture(25, true);
        $supplier = Supplier::create(['supplier_code' => 'QA-SUP-1', 'supplier_name' => 'QA供应商一']);
        ItemSupplierPrice::create([
            'item_id' => $item->id, 'supplier_id' => $supplier->id, 'unit_id' => $package->id,
            'price' => 100, 'standard_conversion_factor' => 25, 'final_conversion_factor' => 24.8,
            'factor_source' => 'supplier_override', 'base_unit_price' => round(100 / 24.8, 8),
        ]);
        $this->assertDatabaseHas('erp_item_supplier_prices', [
            'supplier_id' => $supplier->id, 'final_conversion_factor' => 24.8,
        ]);
    }

    public function test_13_supplier_quotes_with_different_packages_compare_by_base_unit_price(): void
    {
        [$item, $package] = $this->purchaseFixture(25, true);
        $secondPackage = Unit::firstOrCreate(['unit_code' => 'ROLL'], ['unit_name' => '卷', 'symbol' => '卷', 'decimal_places' => 0]);
        $first = Supplier::create(['supplier_code' => 'QA-SUP-A', 'supplier_name' => 'QA供应商A']);
        $second = Supplier::create(['supplier_code' => 'QA-SUP-B', 'supplier_name' => 'QA供应商B']);
        ItemSupplierPrice::create(['item_id' => $item->id, 'supplier_id' => $first->id, 'unit_id' => $package->id, 'price' => 100, 'final_conversion_factor' => 25, 'base_unit_price' => 4]);
        ItemSupplierPrice::create(['item_id' => $item->id, 'supplier_id' => $second->id, 'unit_id' => $secondPackage->id, 'price' => 78, 'final_conversion_factor' => 20, 'base_unit_price' => 3.9]);
        $best = ItemSupplierPrice::where('item_id', $item->id)->orderBy('base_unit_price')->first();
        $this->assertSame($second->id, $best->supplier_id);
    }

    public function test_14_purchase_order_snapshot_is_unchanged_after_conversion_change(): void
    {
        [$item, $package, $conversion] = $this->purchaseFixture(25, true, true);
        $supplier = Supplier::create(['supplier_code' => 'QA-SUP-PO', 'supplier_name' => 'QA订单供应商']);
        $order = PurchaseOrder::create(['purchase_order_no' => 'QA-PO-001', 'supplier_id' => $supplier->id]);
        $snapshot = app(PurchaseConversionApplicationService::class)->orderLineSnapshot([
            'item_id' => $item->id, 'purchase_unit_id' => $package->id, 'order_qty' => 2, 'unit_price' => 100,
        ]);
        $line = PurchaseOrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'order_qty' => 2, 'remaining_qty' => 2, 'unit_price' => 100] + $snapshot);
        $conversion->update(['factor' => 30]);

        $this->assertSame('25.00000000', $line->fresh()->conversion_factor_snapshot);
        $this->assertSame('50.00000000', $line->fresh()->planned_base_qty);
    }

    public function test_15_sales_order_snapshot_is_unchanged_after_sku_item_relation_change(): void
    {
        [$order, $line, $sku, $item] = $this->physicalOrder(10, 2.5, 0, [], true);
        $relation = SkuItemRelation::create([
            'sku_id' => $sku->id, 'item_id' => $item->id, 'relation_type' => 'finished_product',
            'qty' => 2.5, 'unit_id' => $item->unit_id, 'is_primary' => true, 'status' => 'active',
        ]);
        $relation->update(['qty' => 3]);

        $this->assertSame('2.50000000', $line->fresh()->fulfillment_factor_snapshot);
        $this->assertSame('25.00000000', $line->fresh()->item_base_required_qty);
        $this->assertSame(3.0, (float) $relation->fresh()->qty);
    }

    public function test_16_supplier_quote_base_price_is_server_calculated_for_standard_and_override_factors(): void
    {
        [$item, $package] = $this->purchaseFixture(25, true);
        $standardSupplier = $this->eligibleSupplier('QA-SUP-STANDARD', $item);
        $standard = app(SupplierQuotationService::class)->saveQuote(
            $this->quotePayload($standardSupplier, $item, $package, 50),
            null
        );
        $this->assertSame(2.0, (float) $standard->base_unit_price);
        $this->assertSame(25.0, (float) $standard->final_conversion_factor);

        $overrideSupplier = $this->eligibleSupplier('QA-SUP-OVERRIDE', $item);
        $override = app(SupplierQuotationService::class)->saveQuote(array_merge(
            $this->quotePayload($overrideSupplier, $item, $package, 50),
            ['use_supplier_factor' => true, 'supplier_conversion_factor' => 20, 'supplier_factor_reason' => 'supplier package net weight']
        ), null);
        $this->assertSame(2.5, (float) $override->base_unit_price);
        $this->assertSame('supplier_specific', $override->factor_source);
    }

    public function test_17_tax_mode_does_not_change_base_price_and_old_history_snapshot_is_immutable(): void
    {
        [$item, $package] = $this->purchaseFixture(25, true);
        $supplier = $this->eligibleSupplier('QA-SUP-TAX', $item);
        $service = app(SupplierQuotationService::class);
        $included = $service->saveQuote(array_merge(
            $this->quotePayload($supplier, $item, $package, 50),
            ['tax_mode' => 'tax_included', 'tax_rate' => 13]
        ), null);
        $oldSnapshot = DB::table('erp_supplier_quotation_histories')
            ->where('quotation_id', $included->id)->oldest('created_at')->value('quotation_snapshot');
        $excluded = $service->saveQuote(array_merge(
            $this->quotePayload($supplier, $item, $package, 50),
            ['tax_mode' => 'tax_excluded', 'tax_rate' => 0]
        ), null);

        $this->assertSame(2.0, (float) $included->base_unit_price);
        $this->assertSame(2.0, (float) $excluded->base_unit_price);
        $this->assertSame(2.0, (float) json_decode($oldSnapshot, true)['base_unit_price']);
    }

    public function test_18_partial_plan_confirmation_keeps_actual_fulfillment_pending_without_undetermined_row(): void
    {
        [$order, $line] = $this->physicalOrder(10, 2.5, 25);
        $this->confirm($order, [$this->decision($line, 4, inventory: 4)]);

        $this->assertSame('pending', $order->fresh()->fulfillment_status);
        $this->assertDatabaseMissing('erp_sales_order_fulfillments', [
            'sales_order_line_id' => $line->id,
            'fulfillment_type' => 'undetermined',
        ]);
        $this->assertSame(6.0, (float) $line->fresh()->undetermined_qty);
    }

    public function test_19_confirmed_plan_cannot_be_submitted_twice(): void
    {
        [$order, $line] = $this->physicalOrder(10, 2.5, 10);
        $decision = $this->decision($line, 10, inventory: 4, production: 6);
        $this->confirm($order, [$decision]);
        $fulfillmentCount = DB::table('erp_sales_order_fulfillments')->where('sales_order_id', $order->id)->count();
        $requirementCount = DB::table('erp_sales_order_production_requirements')->where('sales_order_id', $order->id)->count();

        try {
            $this->confirm($order, [$decision]);
            $this->fail('Confirmed production plan must not be submitted twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('production_confirm_status', $exception->errors());
        }

        $this->assertSame($fulfillmentCount, DB::table('erp_sales_order_fulfillments')->where('sales_order_id', $order->id)->count());
        $this->assertSame($requirementCount, DB::table('erp_sales_order_production_requirements')->where('sales_order_id', $order->id)->count());
    }

    public function test_20_zero_finished_goods_inventory_suggests_all_production(): void
    {
        [$order] = $this->physicalOrder(10, 1, 0);
        $line = app(SalesOrderFulfillmentApplicationService::class)->preview($order->id)['lines']->first();

        $this->assertSame(0.0, $line['system_suggested_inventory_qty']);
        $this->assertSame(10.0, $line['system_suggested_production_qty']);
        $this->assertSame('当前无可用成品库存', $line['system_suggestion_reason']);
    }

    public function test_21_partial_finished_goods_inventory_suggests_inventory_plus_production(): void
    {
        [$order] = $this->physicalOrder(10, 1, 4);
        $line = app(SalesOrderFulfillmentApplicationService::class)->preview($order->id)['lines']->first();

        $this->assertSame(4.0, $line['available_base_qty']);
        $this->assertSame(4.0, $line['system_suggested_inventory_qty']);
        $this->assertSame(6.0, $line['system_suggested_production_qty']);
        $this->assertNotEmpty($line['inventory_calculated_at']);
    }

    public function test_22_sufficient_finished_goods_inventory_suggests_all_inventory(): void
    {
        [$order] = $this->physicalOrder(10, 1, 15);
        $line = app(SalesOrderFulfillmentApplicationService::class)->preview($order->id)['lines']->first();

        $this->assertSame(10.0, $line['system_suggested_inventory_qty']);
        $this->assertSame(0.0, $line['system_suggested_production_qty']);
    }

    public function test_23_locked_defective_and_pending_stock_are_excluded(): void
    {
        [$order] = $this->physicalOrder(10, 1, 20);
        InventoryBalance::query()->update([
            'quantity_locked' => 6,
            'quantity_defective' => 5,
            'quantity_pending' => 5,
            'quantity_available' => 20,
        ]);
        $line = app(SalesOrderFulfillmentApplicationService::class)->preview($order->id)['lines']->first();

        $this->assertSame(4.0, $line['available_base_qty']);
        $this->assertSame(4.0, $line['system_suggested_inventory_qty']);
        $this->assertSame(6.0, $line['system_suggested_production_qty']);
    }

    public function test_24_sales_unit_is_converted_to_item_base_unit_before_suggestion(): void
    {
        [$order] = $this->physicalOrder(10, 25, 100);
        $line = app(SalesOrderFulfillmentApplicationService::class)->preview($order->id)['lines']->first();

        $this->assertSame(250.0, $line['item_base_required_qty']);
        $this->assertSame(100.0, $line['available_base_qty']);
        $this->assertSame(4.0, $line['system_suggested_inventory_qty']);
        $this->assertSame(6.0, $line['system_suggested_production_qty']);
    }

    public function test_25_bom_material_inventory_never_counts_as_finished_goods_inventory(): void
    {
        [$order] = $this->physicalOrder(10, 1, 0);
        $base = Unit::where('unit_code', 'KG')->firstOrFail();
        $warehouse = Warehouse::firstOrFail();
        $location = Location::firstOrFail();
        $material = Item::create(['item_code' => 'QA-BOM-MATERIAL', 'item_name' => 'BOM原材料', 'item_type' => 'raw_material', 'unit_id' => $base->id, 'status' => 'enabled']);
        InventoryBalance::create([
            'item_id' => $material->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => 'QA-MATERIAL-BATCH', 'unit_id' => $base->id,
            'quantity_on_hand' => 1000, 'quantity_available' => 1000,
        ]);
        InventoryBatch::create([
            'item_id' => $material->id, 'batch_no' => 'QA-MATERIAL-BATCH',
            'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'status' => 'enabled',
        ]);

        $line = app(SalesOrderFulfillmentApplicationService::class)->preview($order->id)['lines']->first();
        $this->assertSame(0.0, $line['system_suggested_inventory_qty']);
        $this->assertSame(10.0, $line['system_suggested_production_qty']);
    }

    public function test_26_service_and_no_delivery_lines_skip_inventory_analysis(): void
    {
        [$serviceOrder, $serviceLine] = $this->nonPhysicalOrder('service', 1);
        $noDeliveryLine = SalesOrderLine::create([
            'sales_order_id' => $serviceOrder->id, 'line_no' => 2, 'line_type' => 'no_delivery',
            'order_qty' => 2, 'unit_id' => $serviceLine->unit_id,
            'unit_name_snapshot' => '件', 'unit_code_snapshot' => 'PCS',
        ]);
        $lines = app(SalesOrderFulfillmentApplicationService::class)->preview($serviceOrder->id)['lines']->keyBy('sales_order_line_id');

        $this->assertSame(1.0, $lines[$serviceLine->id]['service_qty']);
        $this->assertSame(0.0, $lines[$serviceLine->id]['inventory_qty']);
        $this->assertSame(2.0, $lines[$noDeliveryLine->id]['no_delivery_qty']);
        $this->assertSame(0.0, $lines[$noDeliveryLine->id]['production_qty']);
    }

    public function test_27_manual_change_requires_reason_and_keeps_suggestion_snapshot(): void
    {
        [$order, $line] = $this->physicalOrder(10, 1, 4);
        $decision = $this->decision($line, 10, inventory: 2, production: 8);

        try {
            $this->confirm($order, [$decision]);
            $this->fail('Manual change without reason must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('adjustment_reason', $exception->errors());
        }
        $this->assertDatabaseCount('erp_inventory_reservations', 0);

        $this->confirm($order, [$decision], '保留两件用于售后备件');
        $snapshot = SalesOrderFulfillment::where('sales_order_id', $order->id)->firstOrFail()->match_snapshot['planning'];
        $this->assertSame(4.0, (float) $snapshot['suggested_inventory_qty']);
        $this->assertSame(6.0, (float) $snapshot['suggested_production_qty']);
        $this->assertSame(2.0, (float) $snapshot['final_inventory_qty']);
        $this->assertSame(8.0, (float) $snapshot['final_production_qty']);
        $this->assertSame('保留两件用于售后备件', $snapshot['adjustment_reason']);
        $this->assertSame('QA验收员', $snapshot['confirmed_by']);
    }

    public function test_28_inventory_confirmation_creates_real_reservation_without_reducing_on_hand(): void
    {
        [$order, $line] = $this->physicalOrder(10, 1, 4);
        $this->confirm($order, [$this->decision($line, 10, inventory: 4, production: 6)]);

        $this->assertDatabaseHas('erp_inventory_reservations', [
            'source_order_id' => $order->id,
            'source_order_line_id' => $line->id,
            'reserved_qty' => 4,
            'reservation_status' => 'active',
        ]);
        $balance = InventoryBalance::firstOrFail();
        $this->assertSame(4.0, (float) $balance->quantity_on_hand);
        $this->assertSame(4.0, (float) $balance->quantity_locked);
        $this->assertSame(0.0, (float) $balance->quantity_available);
    }

    public function test_29_next_order_cannot_reuse_stock_reserved_by_first_order(): void
    {
        [$firstOrder, $firstLine] = $this->physicalOrder(1, 1, 1);
        $this->confirm($firstOrder, [$this->decision($firstLine, 1, inventory: 1)]);

        $secondOrder = $firstOrder->replicate();
        $secondOrder->sales_order_no = 'QA-SO-002';
        $secondOrder->production_confirm_status = 'pending';
        $secondOrder->save();
        $secondLine = $firstLine->replicate();
        $secondLine->sales_order_id = $secondOrder->id;
        $secondLine->line_uuid = 'QA-LINE-SECOND';
        $secondLine->line_status = 'confirmed_pending_fulfillment';
        $secondLine->inventory_fulfilled_qty = 0;
        $secondLine->production_required_qty = 0;
        $secondLine->undetermined_qty = 1;
        $secondLine->save();

        $suggestion = app(SalesOrderFulfillmentApplicationService::class)->preview($secondOrder->id)['lines']->first();
        $this->assertSame(0.0, $suggestion['system_suggested_inventory_qty']);
        $this->assertSame(1.0, $suggestion['system_suggested_production_qty']);
        $this->assertSame(1, InventoryReservation::where('source_order_id', $firstOrder->id)->where('reservation_status', 'active')->count());
    }

    public function test_30_stale_manual_inventory_allocation_is_rejected_after_another_order_reserves_stock(): void
    {
        [$firstOrder, $firstLine] = $this->physicalOrder(1, 1, 1);

        $secondOrder = $firstOrder->replicate();
        $secondOrder->sales_order_no = 'QA-SO-STALE';
        $secondOrder->production_confirm_status = 'pending';
        $secondOrder->save();
        $secondLine = $firstLine->replicate();
        $secondLine->sales_order_id = $secondOrder->id;
        $secondLine->line_uuid = 'QA-LINE-STALE';
        $secondLine->line_status = 'confirmed_pending_fulfillment';
        $secondLine->inventory_fulfilled_qty = 0;
        $secondLine->production_required_qty = 0;
        $secondLine->undetermined_qty = 1;
        $secondLine->save();

        $this->confirm($firstOrder, [$this->decision($firstLine, 1, inventory: 1)]);

        $this->expectException(ValidationException::class);
        $this->confirm(
            $secondOrder,
            [$this->decision($secondLine, 1, inventory: 1)],
            '并发前页面曾显示一件可用库存',
        );
    }

    private function physicalOrder(float $qty, float $factor, float $availableBase, array $configuration = [], bool $returnMaster = false): array
    {
        $salesUnit = Unit::firstOrCreate(['unit_code' => 'PCS'], ['unit_name' => '件', 'symbol' => '件', 'decimal_places' => 0, 'is_base' => true]);
        $baseUnit = Unit::firstOrCreate(['unit_code' => 'KG'], ['unit_name' => '千克', 'symbol' => 'kg', 'decimal_places' => 2, 'is_base' => true]);
        $product = Product::create(['product_code' => 'QA-PROD-001', 'product_name' => '验收产品', 'unit_id' => $salesUnit->id]);
        $sku = Sku::create(['product_id' => $product->id, 'sku_code' => 'QA-SKU-001', 'sku_name' => '验收SKU', 'sales_unit_id' => $salesUnit->id, 'order_line_type' => 'physical', 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'QA-ITEM-001', 'item_name' => '验收Item', 'item_type' => 'finished_product', 'unit_id' => $baseUnit->id, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => 'QA-WH-001', 'warehouse_name' => '验收仓']);
        $location = Location::create(['location_code' => 'QA-LOC-001', 'location_name' => '验收库位', 'warehouse_id' => $warehouse->id]);
        InventoryBalance::create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => 'QA-BATCH-001', 'unit_id' => $baseUnit->id,
            'quantity_on_hand' => $availableBase, 'quantity_available' => $availableBase,
            'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0,
        ]);
        InventoryBatch::create([
            'item_id' => $item->id, 'batch_no' => 'QA-BATCH-001',
            'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'status' => 'enabled',
        ]);
        InventoryLocationBalance::create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'unit_id' => $baseUnit->id, 'quantity_on_hand' => $availableBase, 'quantity_available' => $availableBase,
            'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0,
        ]);
        // These scenarios isolate quantity conversion/reservation. Finance gate
        // behaviour is covered by FinanceCoreV1Test, so use an explicit
        // zero-threshold contract policy rather than accidentally depending on
        // an unaudited paid flag or the default full-prepay policy.
        $order = SalesOrder::create(['sales_order_no' => 'QA-SO-001', 'customer_name' => '验收客户', 'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'pending', 'total_amount' => 0, 'final_receivable_amount' => 0, 'funding_policy_snapshot' => ['policy_type' => 'installment_contract', 'production_threshold_type' => 'amount', 'production_threshold_value' => '0', 'shipment_requires_full_payment' => true]]);
        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_uuid' => 'QA-LINE-1', 'line_no' => 1,
            'product_id' => $product->id, 'sku_id' => $sku->id, 'item_id' => $item->id,
            'product_name' => $product->product_name, 'sku_name' => $sku->sku_name, 'item_name' => $item->item_name,
            'line_type' => 'physical', 'order_qty' => $qty, 'unit_id' => $salesUnit->id,
            'unit_name_snapshot' => '件', 'unit_code_snapshot' => 'PCS',
            'item_base_unit_id' => $baseUnit->id, 'item_base_unit_name_snapshot' => '千克', 'item_base_unit_code_snapshot' => 'KG',
            'fulfillment_factor_snapshot' => $factor, 'item_base_required_qty' => $qty * $factor,
            'configuration_snapshot' => $configuration, 'line_status' => 'confirmed_pending_fulfillment',
        ]);
        return $returnMaster ? [$order, $line, $sku, $item] : [$order, $line];
    }

    private function nonPhysicalOrder(string $type, float $qty): array
    {
        $unit = Unit::firstOrCreate(['unit_code' => 'PCS'], ['unit_name' => '件', 'symbol' => '件', 'decimal_places' => 0]);
        $order = SalesOrder::create(['sales_order_no' => 'QA-SO-NP', 'customer_name' => '验收客户', 'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'pending', 'total_amount' => 0, 'final_receivable_amount' => 0, 'funding_policy_snapshot' => ['policy_type' => 'installment_contract', 'production_threshold_type' => 'amount', 'production_threshold_value' => '0', 'shipment_requires_full_payment' => true]]);
        $line = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'line_type' => $type, 'order_qty' => $qty, 'unit_id' => $unit->id, 'unit_name_snapshot' => '件', 'unit_code_snapshot' => 'PCS']);
        return [$order, $line];
    }

    private function purchaseFixture(float $factor, bool $allowActual, bool $returnConversion = false): array
    {
        $base = Unit::firstOrCreate(['unit_code' => 'KG'], ['unit_name' => '千克', 'symbol' => 'kg', 'decimal_places' => 2, 'is_base' => true]);
        $package = Unit::firstOrCreate(['unit_code' => 'BAG'], ['unit_name' => '包', 'symbol' => '包', 'decimal_places' => 0]);
        $item = Item::create(['item_code' => 'QA-PUR-ITEM', 'item_name' => '采购验收物料', 'item_type' => 'raw_material', 'unit_id' => $base->id, 'status' => 'enabled']);
        $conversion = ItemPurchaseConversion::create([
            'item_id' => $item->id, 'purchase_unit_id' => $package->id, 'base_unit_id' => $base->id,
            'factor' => $factor, 'is_default' => true, 'allow_actual_conversion' => $allowActual,
            'status' => 'active', 'effective_from' => now()->subDay(),
            'change_reason' => '最终验收测试',
        ]);
        return $returnConversion ? [$item, $package, $conversion] : [$item, $package];
    }

    private function eligibleSupplier(string $code, Item $item): Supplier
    {
        $supplier = Supplier::create([
            'supplier_code' => $code,
            'supplier_name' => $code,
            'status' => 'enabled',
            'approval_status' => 'approved',
            'is_blacklisted' => false,
            'cooperation_status' => 'normal',
            'purchase_restricted' => false,
            'quality_status' => 'normal',
        ]);
        SupplierItemRelation::create([
            'supplier_id' => $supplier->id,
            'item_id' => $item->id,
            'relation_status' => 'active',
            'capability_source' => 'manual_confirmed',
            'effective_at' => now()->subDay(),
        ]);
        return $supplier;
    }

    private function quotePayload(Supplier $supplier, Item $item, Unit $unit, float $price): array
    {
        return [
            'supplier_id' => $supplier->id,
            'item_id' => $item->id,
            'unit_id' => $unit->id,
            'price' => $price,
            'currency' => 'CNY',
            'tax_mode' => 'tax_included',
            'tax_rate' => 13,
            'lead_time_days' => 3,
            'min_order_qty' => 0,
            'valid_from' => now()->toDateString(),
            'use_supplier_factor' => false,
            'change_reason' => 'automated acceptance',
        ];
    }

    private function decision(SalesOrderLine $line, float $confirm, float $inventory = 0, float $production = 0, float $service = 0, float $noDelivery = 0, float $undetermined = 0): array
    {
        return ['sales_order_line_id' => $line->id, 'confirm_qty' => $confirm, 'inventory_qty' => $inventory, 'production_qty' => $production, 'service_qty' => $service, 'no_delivery_qty' => $noDelivery, 'undetermined_qty' => $undetermined];
    }

    private function confirm(SalesOrder $order, array $lines, ?string $adjustmentReason = null): SalesOrder
    {
        return app(SalesOrderFulfillmentApplicationService::class)->confirmProduction(
            $order->id,
            $lines,
            '单位换算最终验收',
            'QA验收员',
            $adjustmentReason,
        );
    }

    private function workOrderFootprint(): array
    {
        return collect(Schema::getTableListing())
            ->filter(fn (string $table) => preg_match('/(work.?order|process.?task|production.?schedule)/i', $table))
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }
}
