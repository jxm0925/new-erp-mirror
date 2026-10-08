<?php

namespace Tests\Unit\Erp;

use App\Services\Erp\ProductionFinancialProjectionService;
use PHPUnit\Framework\TestCase;

class ProductionFinancialProjectionTest extends TestCase
{
    public function test_nested_models_arrays_and_json_cannot_disclose_money_or_performance_rates_to_workers(): void
    {
        $projection = new ProductionFinancialProjectionService;
        $payload = ['actual_labor_minutes' => '10.5', 'performance_rate_snapshot' => '0.01', 'unit_price' => '1000',
            'output' => (object) ['output_base_qty' => '2', 'material_total_cost' => '100'],
            'snapshot' => json_encode(['price' => 2, 'actualCostAmount' => 500, 'order_qty' => 2,
                'parameters' => ['绩效比例' => 1, 'cut_length_mm' => 500]], JSON_UNESCAPED_UNICODE),
            'basis_policy' => ['basis' => '5000'], 'performance_assignments' => [['performance_amount' => 20]]];
        $safe = $projection->sanitize($payload, ['production.performance.view', 'production.output.cost.view']);
        $this->assertSame('10.5', $safe['actual_labor_minutes']);
        $this->assertSame(['output_base_qty' => '2'], $safe['output']);
        $this->assertSame(['order_qty' => 2, 'parameters' => ['cut_length_mm' => 500]], json_decode($safe['snapshot'], true));
        $this->assertArrayNotHasKey('performance_rate_snapshot', $safe);
        $this->assertArrayNotHasKey('performance_assignments', $safe);
        $this->assertArrayNotHasKey('basis_policy', $safe);
        $this->assertArrayNotHasKey('unit_price', $safe);
    }
}
