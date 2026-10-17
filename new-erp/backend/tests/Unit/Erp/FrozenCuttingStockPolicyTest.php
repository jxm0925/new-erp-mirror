<?php

namespace Tests\Unit\Erp;

use App\Services\Erp\{CuttingStockAllocationPolicy, FrozenCuttingStockPolicy};
use PHPUnit\Framework\TestCase;

/** Pure facts only: no framework boot, environment, credentials or database. */
final class FrozenCuttingStockPolicyTest extends TestCase
{
    public function test_sheet_requires_frozen_length_width_and_exact_nominal_thickness(): void
    {
        $required = $this->sheet();
        $this->assertTrue(FrozenCuttingStockPolicy::allows($required, ['length_mm' => 200, 'width_mm' => 100, 'thickness_mm' => 2]));
        foreach ([['length_mm' => 199, 'width_mm' => 100, 'thickness_mm' => 2], ['length_mm' => 200, 'width_mm' => 99, 'thickness_mm' => 2],
            ['length_mm' => 200, 'width_mm' => 100, 'thickness_mm' => 1.9], ['length_mm' => 200, 'width_mm' => 100],
            ['length_mm' => 'unknown', 'width_mm' => 100, 'thickness_mm' => 2]] as $source) $this->assertFalse(FrozenCuttingStockPolicy::allows($required, $source));
        $this->assertTrue(FrozenCuttingStockPolicy::allows($required, ['length_mm' => 200, 'width_mm' => 100, 'thickness_mm' => 1.9, 'nominal_thickness_mm' => 2]));
        $this->assertFalse(FrozenCuttingStockPolicy::allows($required, ['length_mm' => 200, 'width_mm' => 100, 'thickness_mm' => 2, 'nominal_thickness_mm' => 1.9]));
        $this->assertFalse(FrozenCuttingStockPolicy::allows($required, ['length_mm' => 200, 'width_mm' => 100, 'thickness_mm' => 2, 'nominal_thickness_mm' => 'bad']));
    }

    public function test_rotation_is_explicit_and_frozen_even_if_current_item_mode_changes(): void
    {
        $source = ['length_mm' => 100, 'width_mm' => 200, 'thickness_mm' => 2];
        $this->assertFalse(FrozenCuttingStockPolicy::allows($this->sheet(), $source));
        $this->assertTrue(FrozenCuttingStockPolicy::allows($this->sheet(true), $source));
        $frozen = FrozenCuttingStockPolicy::requirement(['length_mm' => 200, 'width_mm' => 100, 'thickness_mm' => 2], null, 'none');
        $this->assertSame('sheet', $frozen['mode']);
        $this->assertFalse(FrozenCuttingStockPolicy::allows($frozen, ['length_mm' => 100, 'width_mm' => 100, 'thickness_mm' => 2]));
    }

    public function test_unknown_sheet_or_length_dimensions_never_use_default_sizes(): void
    {
        $this->assertFalse(FrozenCuttingStockPolicy::requirement([], null, 'sheet')['valid']);
        $length = FrozenCuttingStockPolicy::requirement([], 1000, 'length');
        $this->assertTrue(FrozenCuttingStockPolicy::allows($length, ['length_mm' => 1000]));
        $this->assertFalse(FrozenCuttingStockPolicy::allows($length, []));
        $this->assertFalse(FrozenCuttingStockPolicy::allows($length, ['length_mm' => 999]));
        $this->assertFalse(FrozenCuttingStockPolicy::requirement(['quantity_calculation' => ['mode' => 'bad']], 1000, 'length')['valid']);
    }

    public function test_grid_capacity_respects_geometry_and_rotation_instead_of_area_ratio(): void
    {
        $this->assertSame(4, CuttingStockAllocationPolicy::capacity($this->sheet(), ['length_mm' => 400, 'width_mm' => 200, 'thickness_mm' => 2]));
        $this->assertSame(0, CuttingStockAllocationPolicy::capacity($this->sheet(), ['length_mm' => 199, 'width_mm' => 10000, 'thickness_mm' => 2]));
        $this->assertSame(1, CuttingStockAllocationPolicy::capacity($this->sheet(true), ['length_mm' => 100, 'width_mm' => 200, 'thickness_mm' => 2]));
        $this->assertSame(0, CuttingStockAllocationPolicy::capacity($this->sheet(true), ['length_mm' => 400, 'width_mm' => 200, 'thickness_mm' => 1]));
        $length = FrozenCuttingStockPolicy::requirement(['length_mm' => 1100], null, 'length');
        $this->assertSame(5, CuttingStockAllocationPolicy::capacity($length, ['length_mm' => 6000]));
    }

    private function sheet(bool $rotation = false): array
    {
        return FrozenCuttingStockPolicy::requirement(['length_mm' => 200, 'width_mm' => 100, 'thickness_mm' => 2, 'allow_rotation' => $rotation], null, 'sheet');
    }
}
