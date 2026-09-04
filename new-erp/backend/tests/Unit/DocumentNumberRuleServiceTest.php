<?php

namespace Tests\Unit;

use App\Services\Erp\DocumentNumberRuleService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentNumberRuleServiceTest extends TestCase
{
    public function test_format_preview_is_deterministic_and_does_not_need_a_counter_write(): void
    {
        Carbon::setTestNow('2026-08-01 10:00:00');
        $result = app(DocumentNumberRuleService::class)->preview([
            'document_type' => 'sales_order', 'name' => '销售订单编号规则', 'prefix' => 'SO',
            'date_format' => 'YYYYMMDD', 'sequence_length' => 5, 'reset_cycle' => 'daily', 'enabled' => true,
        ]);
        $this->assertSame('SO2026080100001', $result['format_example']);
    }

    #[DataProvider('unsafeCombinations')]
    public function test_unsafe_date_and_reset_combinations_are_rejected(string $format, string $cycle): void
    {
        $this->expectException(ValidationException::class);
        app(DocumentNumberRuleService::class)->preview([
            'document_type' => 'sales_order', 'name' => '销售订单编号规则', 'prefix' => 'SO',
            'date_format' => $format, 'sequence_length' => 5, 'reset_cycle' => $cycle, 'enabled' => true,
        ]);
    }

    public static function unsafeCombinations(): array
    {
        return [['none', 'daily'], ['YYYY', 'monthly'], ['YYYYMM', 'daily'], ['YYYYMMDD', 'none']];
    }

    public function test_business_type_dictionary_returns_chinese_label_and_stable_code(): void
    {
        $types = collect(app(DocumentNumberRuleService::class)->businessTypes())->keyBy('code');
        $this->assertSame('销售订单', $types['sales_order']['label']);
        $this->assertSame('物料', $types['item']['label']);
    }
}
