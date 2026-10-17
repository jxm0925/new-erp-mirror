<?php

namespace Tests\Unit\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Unit;
use App\Services\Erp\ShipmentPackingMaterialUsagePolicy;
use PHPUnit\Framework\TestCase;

class ShipmentPackingMaterialUsagePolicyTest extends TestCase
{
    public function test_actual_dimensions_and_chinese_remark_are_separate_from_inventory_quantity(): void
    {
        $row = ['base_qty' => '3', 'usage_dimensions' => ['length_mm' => '1200.25', 'width_mm' => null, 'thickness_mm' => '12'], 'usage_remark' => '  木箱底托实际用料  '];
        $snapshot = (new ShipmentPackingMaterialUsagePolicy)->snapshot($row);
        $this->assertSame(['dimensions' => ['length_mm' => '1200.25', 'thickness_mm' => '12.00'], 'remark' => '木箱底托实际用料'], $snapshot);
        $this->assertSame('3', $row['base_qty']);
        $this->assertArrayNotHasKey('base_qty', $snapshot);
        $this->assertSame('木箱底托实际用料', json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true)['remark']);
    }

    public function test_unknown_dimensions_remain_absent_and_blank_usage_has_no_synthetic_defaults(): void
    {
        $this->assertNull((new ShipmentPackingMaterialUsagePolicy)->snapshot(['usage_dimensions' => ['length_mm' => '', 'width_mm' => null]]));
    }

    public function test_dimensions_have_positive_two_place_precision(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        (new ShipmentPackingMaterialUsagePolicy)->snapshot(['usage_dimensions' => ['thickness_mm' => '1.234']]);
    }

    public function test_stock_count_material_cannot_use_a_theoretical_fraction_as_actual_consumption(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        (new ShipmentPackingMaterialUsagePolicy)->assertQuantity('0.1', '1', $this->unit(false, 0));
    }

    public function test_actual_decimal_consumption_obeys_the_real_unit_precision(): void
    {
        $policy = new ShipmentPackingMaterialUsagePolicy;
        $policy->assertQuantity('1.235', '3', $this->unit(true, 3));
        $this->expectException(WorkOrderDomainException::class);
        $policy->assertQuantity('1.2351', '3', $this->unit(true, 3));
    }

    public function test_actual_consumption_cannot_exceed_locked_source_availability(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        (new ShipmentPackingMaterialUsagePolicy)->assertQuantity('3.00000001', '3.00000000', $this->unit(true, 8));
    }

    public function test_missing_unit_does_not_default_to_a_decimal_unit(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        (new ShipmentPackingMaterialUsagePolicy)->assertQuantity('1', '3', null);
    }

    private function unit(bool $decimal, int $places): Unit
    {
        return new Unit(['unit_name' => $decimal ? '米' : '根', 'status' => 'enabled', 'allow_decimal' => $decimal, 'decimal_places' => $places]);
    }
}
