<?php

namespace Tests\Unit\Erp;

use App\Models\Erp\Sku;
use Tests\TestCase;

class SkuFormalFieldContractTest extends TestCase
{
    public function test_formal_order_line_fields_are_exposed_without_legacy_duplicate_fields(): void
    {
        $sku = new Sku([
            'sku_code' => 'SKU-CHECK-001',
            'spec_text' => '标准规格',
            'order_line_type' => 'physical',
            'is_sale_item' => true,
            'electric_mode' => 'optional',
            'need_pump_mode' => 'required',
            'allow_customized' => false,
            'allow_special_customized' => true,
        ]);

        $data = $sku->toArray();

        $this->assertSame('physical', $data['line_type']);
        $this->assertTrue($data['is_sellable']);
        $this->assertSame('标准规格', $data['spec_model']);
        $this->assertArrayNotHasKey('supports_electric', $data);
        $this->assertArrayNotHasKey('electric_required', $data);
        $this->assertArrayNotHasKey('supports_need_pump', $data);
        $this->assertArrayNotHasKey('is_customizable', $data);
    }

    public function test_special_custom_requirements_are_not_implied_by_regular_customization(): void
    {
        $sku = new Sku([
            'allow_customized' => true,
            'allow_special_customized' => false,
            'special_custom_drawing_required' => false,
            'special_custom_agreement_required' => false,
            'special_custom_description_required' => false,
        ]);

        $this->assertTrue($sku->allow_customized);
        $this->assertFalse($sku->allow_special_customized);
        $this->assertFalse($sku->special_custom_drawing_required);
        $this->assertFalse($sku->special_custom_agreement_required);
        $this->assertFalse($sku->special_custom_description_required);
    }

    public function test_legacy_sku_defaults_keep_order_attributes_hidden(): void
    {
        $sku = new Sku(['legacy_sku_id' => 12, 'legacy_sku_code' => 'OLD-12', 'electric_mode' => 'hidden', 'need_pump_mode' => 'hidden']);

        $this->assertSame(12, $sku->legacy_sku_id);
        $this->assertSame('OLD-12', $sku->legacy_sku_code);
        $this->assertSame('hidden', $sku->electric_mode);
        $this->assertSame('hidden', $sku->need_pump_mode);
    }
}
