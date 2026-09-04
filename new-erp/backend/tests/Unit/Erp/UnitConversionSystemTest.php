<?php

namespace Tests\Unit\Erp;

use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Unit;
use App\Services\Erp\UnitConversionDomainService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UnitConversionSystemTest extends TestCase
{
    private UnitConversionDomainService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UnitConversionDomainService();
    }

    public function test_unit_code_has_database_and_request_uniqueness(): void
    {
        $this->assertStringContainsString("unit_code', 40)->unique()", $this->source('database/migrations/2026_07_09_100000_create_erp_master_data_tables.php'));
        $this->assertStringContainsString("'unit_code' => \$unique('erp_units', 'unit_code')", $this->source('app/Http/Controllers/Api/V1/Erp/MasterDataController.php'));
    }

    public function test_unit_precision_is_enforced(): void
    {
        $unit = new Unit(['unit_name' => '千克', 'decimal_places' => 2]);
        $this->service->assertUnitPrecision(1.23, $unit);
        $this->expectException(ValidationException::class);
        $this->service->assertUnitPrecision(1.234, $unit);
    }

    public function test_item_and_sku_unit_locks_are_checked(): void
    {
        $source = $this->source('app/Http/Controllers/Api/V1/Erp/MasterDataController.php');
        $this->assertStringContainsString('itemBaseUnitLocked', $source);
        $this->assertStringContainsString('skuSalesUnitLocked', $source);
        $this->assertStringContainsString('assertStandardBusinessUnit', $source);
    }

    public function test_purchase_conversion_has_default_overlap_and_positive_factor_guards(): void
    {
        $source = $this->source('app/Services/Erp/ItemPurchaseConversionApplicationService.php');
        $this->assertStringContainsString("where('is_default', true)", $source);
        $this->assertStringContainsString("update(['is_default' => false])", $source);
        $this->assertStringContainsString('有效期不能重叠', $source);
        $this->assertStringContainsString('换算因子必须大于 0', $source);
    }

    public function test_purchase_and_base_price_formulas(): void
    {
        $result = $this->service->calculateReceiptBaseQuantity(40, 25, null, false, null);
        $this->assertSame(1000.0, $result['standard_base_qty']);
        $this->assertSame(2.0, $this->service->calculateBaseUnitPrice(50, 25));
    }

    public function test_actual_conversion_requires_permission(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->calculateReceiptBaseQuantity(10, 25, 248.5, false, '实际称重');
    }

    public function test_receipt_difference_requires_reason(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->calculateReceiptBaseQuantity(10, 25, 248.5, true, null);
    }

    public function test_receipt_quality_quantities_keep_both_conservation_equations(): void
    {
        $result = $this->service->calculateReceiptQualityBaseQuantities(10, 8, 2, 248.5);
        $this->assertSame(198.8, $result['qualified_base_qty']);
        $this->assertSame(49.7, $result['unqualified_base_qty']);
        $this->assertSame(248.5, $result['qualified_base_qty'] + $result['unqualified_base_qty']);
    }

    public function test_receipt_rejects_invalid_quality_quantity_sum(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->calculateReceiptQualityBaseQuantities(10, 8, 3, 250);
    }

    public function test_physical_sku_primary_item_is_unique(): void
    {
        $source = $this->source('database/migrations/2026_07_24_120000_enforce_single_enabled_primary_item_per_sku.php');
        $this->assertStringContainsString('erp_sku_item_one_enabled_primary', $source);
    }

    public function test_sales_requirement_and_bom_use_base_units(): void
    {
        $domain = $this->source('app/Services/Erp/UnitConversionDomainService.php');
        $bom = $this->source('app/Http/Controllers/Api/V1/Erp/BomController.php');
        $this->assertStringContainsString('$quantity * $factor', $domain);
        $this->assertStringContainsString("'unit_id' => \$baseUnit->id", $bom);
        $this->assertStringContainsString('canonicalUnit', $bom);
    }

    public function test_historical_documents_keep_conversion_snapshots(): void
    {
        $sales = $this->source('app/Services/Erp/SalesOrderFulfillmentApplicationService.php');
        $purchase = $this->source('app/Services/Erp/PurchaseConversionApplicationService.php');
        foreach (['fulfillment_factor_snapshot', 'item_base_required_qty', 'item_base_unit_name_snapshot'] as $field) {
            $this->assertStringContainsString($field, $sales);
        }
        foreach (['conversion_factor_snapshot', 'purchase_unit_name_snapshot', 'base_unit_name_snapshot'] as $field) {
            $this->assertStringContainsString($field, $purchase);
        }
    }

    public function test_production_confirmation_supports_split_but_does_not_create_work_order(): void
    {
        $source = $this->source('app/Services/Erp/SalesOrderFulfillmentApplicationService.php');
        foreach (['inventory_qty', 'production_qty', 'service_qty', 'no_delivery_qty', 'undetermined_qty'] as $field) {
            $this->assertStringContainsString($field, $source);
        }
        $this->assertStringNotContainsString('WorkOrder::create', $source);
        $this->assertStringNotContainsString('ProcessTask::create', $source);
        $this->assertStringContainsString("'is_ready_for_work_order' => false", $source);
    }

    public function test_different_base_units_are_grouped_separately(): void
    {
        $lines = new Collection([
            ['item_base_unit_id' => 1, 'item_base_unit_name_snapshot' => '千克', 'item_base_required_qty' => 250],
            ['item_base_unit_id' => 2, 'item_base_unit_name_snapshot' => '米', 'item_base_required_qty' => 8.5],
            ['item_base_unit_id' => 1, 'item_base_unit_name_snapshot' => '千克', 'item_base_required_qty' => 50],
        ]);
        $groups = collect($this->service->groupBaseRequirements($lines))->keyBy('unit_name');
        $this->assertSame(300.0, $groups['千克']['quantity']);
        $this->assertSame(8.5, $groups['米']['quantity']);
    }

    public function test_service_and_no_delivery_never_enter_inventory_or_production(): void
    {
        $service = new SalesOrderLine(['line_type' => 'service', 'order_qty' => 3, 'item_base_required_qty' => 999]);
        $noDelivery = new SalesOrderLine(['line_type' => 'no_delivery', 'order_qty' => 2, 'item_base_required_qty' => 999]);
        $this->assertSame(['type' => 'service', 'quantity' => 3.0], $this->service->demandForLine($service));
        $this->assertSame(['type' => 'no_delivery', 'quantity' => 0.0], $this->service->demandForLine($noDelivery));
    }

    private function source(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 3).'/'.$path);
    }
}
