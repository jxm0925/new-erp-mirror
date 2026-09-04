<?php

namespace Tests\Unit\Erp;

use App\Services\Erp\ApprovalExpressionEngine;
use PHPUnit\Framework\TestCase;

class ApprovalExpressionEngineTest extends TestCase
{
    public function test_amount_ratio_enum_and_nested_conditions_are_type_aware(): void
    {
        $engine = new ApprovalExpressionEngine();
        $context = [
            'amount' => '50000.00000001',
            'discount_rate' => '0.1250',
            'risk_level' => 'HIGH',
            'business_type' => 'NORMAL',
            'is_over_budget' => false,
            'business_date' => '2026-12-01',
        ];
        $types = [
            'amount' => 'decimal', 'discount_rate' => 'decimal', 'risk_level' => 'enum',
            'business_type' => 'enum', 'is_over_budget' => 'boolean', 'business_date' => 'date',
        ];

        $this->assertTrue($engine->matches(['field' => 'amount', 'operator' => '>=', 'value' => '50000.00000000'], $context, $types));
        $this->assertTrue($engine->matches(['field' => 'discount_rate', 'operator' => '>', 'value' => '0.12'], $context, $types));
        $this->assertTrue($engine->matches([
            'logic' => 'OR', 'children' => [
                ['field' => 'risk_level', 'operator' => '=', 'value' => 'HIGH'],
                ['field' => 'business_type', 'operator' => '=', 'value' => 'SPECIAL'],
            ],
        ], $context, $types));
        $this->assertTrue($engine->matches([
            'logic' => 'AND', 'children' => [
                ['field' => 'amount', 'operator' => '>=', 'value' => '50000'],
                ['logic' => 'OR', 'children' => [
                    ['field' => 'risk_level', 'operator' => '=', 'value' => 'HIGH'],
                    ['field' => 'is_over_budget', 'operator' => '=', 'value' => true],
                ]],
            ],
        ], $context, $types));
        $this->assertTrue($engine->matches(['field' => 'business_date', 'operator' => '>', 'value' => '2026-08-01'], $context, $types));
        $this->assertFalse($engine->matches(['field' => 'amount', 'operator' => '<', 'value' => '50000'], $context, $types));
    }

    public function test_publish_validation_rejects_invalid_typed_values_and_members(): void
    {
        $engine = new ApprovalExpressionEngine();
        $types = ['qty' => 'integer', 'amount' => 'decimal', 'enabled' => 'boolean', 'day' => 'date', 'time' => 'datetime'];
        $allowed = array_keys($types);

        $this->assertNotEmpty($engine->validate(['field' => 'qty', 'operator' => '=', 'value' => '1.2'], $allowed, $types));
        $this->assertNotEmpty($engine->validate(['field' => 'amount', 'operator' => '=', 'value' => 'abc'], $allowed, $types));
        $this->assertNotEmpty($engine->validate(['field' => 'enabled', 'operator' => '=', 'value' => 'yes'], $allowed, $types));
        $this->assertNotEmpty($engine->validate(['field' => 'day', 'operator' => '=', 'value' => '2026-02-30'], $allowed, $types));
        $this->assertNotEmpty($engine->validate(['field' => 'time', 'operator' => '=', 'value' => 'tomorrow'], $allowed, $types));
        $this->assertNotEmpty($engine->validate(['field' => 'qty', 'operator' => 'in', 'value' => ['1', 'bad']], $allowed, $types));
        $this->assertSame([], $engine->validate(['field' => 'qty', 'operator' => 'in', 'value' => ['1', '2']], $allowed, $types));
    }

    public function test_null_false_and_zero_have_distinct_semantics(): void
    {
        $engine = new ApprovalExpressionEngine();
        $types = ['nullable' => 'integer', 'zero' => 'integer', 'disabled' => 'boolean'];
        $context = ['nullable' => null, 'zero' => 0, 'disabled' => false];

        $this->assertTrue($engine->matches(['field' => 'nullable', 'operator' => 'empty'], $context, $types));
        $this->assertFalse($engine->matches(['field' => 'nullable', 'operator' => '=', 'value' => 0], $context, $types));
        $this->assertTrue($engine->matches(['field' => 'zero', 'operator' => '=', 'value' => 0], $context, $types));
        $this->assertTrue($engine->matches(['field' => 'disabled', 'operator' => '=', 'value' => false], $context, $types));
        $this->assertTrue($engine->matches(['field' => 'zero', 'operator' => 'not_empty'], $context, $types));
    }
}
