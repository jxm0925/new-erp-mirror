<?php

namespace Tests\Unit\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\ProductionPerformanceCalculator;
use PHPUnit\Framework\TestCase;

class ProductionPerformanceCalculatorTest extends TestCase
{
    public function test_blank_eligibility_is_not_interpreted_as_explicitly_excluded(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        (new ProductionPerformanceCalculator)->validateShares([['employee_legacy_id' => 1, 'eligible' => null, 'share_ratio' => 0]], true, '剩余不计绩效');
    }

    public function test_historical_eighty_percent_is_not_normalized_and_excluded_worker_gets_no_amount(): void
    {
        $calculator = new ProductionPerformanceCalculator;
        // Historical confirmed rows are read unchanged; the new input contract is
        // deliberately not applied retrospectively to an already confirmed fact.
        $shares = [
            ['employee_legacy_id' => 1, 'eligible' => true, 'share_ratio' => '0.6'],
            ['employee_legacy_id' => 2, 'eligible' => true, 'share_ratio' => '0.2'],
            ['employee_legacy_id' => 3, 'eligible' => false, 'share_ratio' => '0.2'],
        ];
        $amounts = $calculator->amounts('5000', '0.01', $shares);
        $this->assertSame('50.0000', $amounts['performance_pool_amount']);
        $this->assertSame('40.0000', $amounts['personal_performance_amount']);
        $this->assertSame(['30.0000', '10.0000', '0.0000'], array_column($amounts['shares'], 'performance_amount'));
        $this->assertSame('10.0000', $amounts['noncredited_amount']);
    }

    public function test_historical_zero_remains_zero_but_new_missing_share_is_not_zero(): void
    {
        $calculator = new ProductionPerformanceCalculator;
        $amounts = $calculator->amounts('1000', '0.01', [['employee_legacy_id' => 1, 'eligible' => true, 'share_ratio' => 0]]);
        $this->assertSame('0.0000', $amounts['personal_performance_amount']);
        $this->assertSame('10.0000', $amounts['noncredited_amount']);
        $this->expectException(WorkOrderDomainException::class);
        $calculator->validateShares([['employee_legacy_id' => 1, 'eligible' => true]], true, '说明');
    }

    public function test_unexplained_uncredited_balance_is_not_silently_confirmed(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        (new ProductionPerformanceCalculator)->validateShares([], false, null);
    }

    public function test_overallocated_personal_shares_are_rejected(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        (new ProductionPerformanceCalculator)->validateShares([
            ['employee_legacy_id' => 1, 'eligible' => true, 'share_ratio' => '0.7'],
            ['employee_legacy_id' => 2, 'eligible' => true, 'share_ratio' => '0.4'],
        ], true, '说明');
    }

    public function test_new_seventy_thirty_needs_no_eligibility_or_noncredit_fields(): void
    {
        $calculator = new ProductionPerformanceCalculator;
        $allocation = $calculator->validateShares([
            ['employee_legacy_id' => 2, 'share_ratio' => '0.3'], ['employee_legacy_id' => 1, 'share_ratio' => '0.7'],
        ]);
        $this->assertSame('1.00000000', $allocation['credited_share_ratio']);
        $this->assertSame('0.00000000', $allocation['noncredited_share_ratio']);
        $this->assertSame([1, 2], array_column($allocation['shares'], 'employee_legacy_id'));
        $this->assertSame([true, true], array_column($allocation['shares'], 'eligible'));
        $amounts = $calculator->amounts('3000', '0.01', $allocation['shares']);
        $this->assertSame('30.0000', $amounts['performance_pool_amount']);
        $this->assertSame(['21.0000', '9.0000'], array_column($amounts['shares'], 'performance_amount'));
    }

    public function test_new_underallocation_cannot_be_turned_into_an_unpaid_tail(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        (new ProductionPerformanceCalculator)->validateShares([
            ['employee_legacy_id' => 1, 'share_ratio' => '0.7'], ['employee_legacy_id' => 2, 'share_ratio' => '0.2'],
        ], true, '旧版不计绩效原因不能绕过新规则');
    }

    public function test_single_and_three_person_defaults_are_exactly_one_without_rounding_loss(): void
    {
        $calculator = new ProductionPerformanceCalculator;
        $single = $calculator->validateShares($calculator->defaultShares([['employee_legacy_id' => 3]]));
        $this->assertSame('1.00000000', $single['shares'][0]['share_ratio']);
        $thirds = $calculator->validateShares($calculator->defaultShares([
            ['employee_legacy_id' => 30], ['employee_legacy_id' => 10], ['employee_legacy_id' => 20],
        ]));
        $this->assertSame([10, 20, 30], array_column($thirds['shares'], 'employee_legacy_id'));
        $this->assertSame(['0.33333333', '0.33333333', '0.33333334'], array_column($thirds['shares'], 'share_ratio'));
        $this->assertSame('1.00000000', $thirds['credited_share_ratio']);
        $this->assertSame([], $calculator->defaultShares([]));
    }

    public function test_commission_rate_is_a_decimal_fraction_and_does_not_silently_round_finer_input(): void
    {
        $calculator = new ProductionPerformanceCalculator;
        $this->assertSame('0.01000000', $calculator->ratio('0.01'));
        $this->assertSame('0.02000000', $calculator->ratio(0.02));
        $this->assertSame('0.00000001', $calculator->ratio(0.00000001));
        foreach (['1%', '1e-2', '-0.01', '1.01', '0.000000001', 0.000000001] as $invalid) {
            try {
                $calculator->ratio($invalid);
                $this->fail('A rate must not be silently changed or interpreted as percentage text.');
            } catch (WorkOrderDomainException $exception) {
                $this->assertContains($exception->errorCode, ['performance_ratio_invalid', 'performance_ratio_precision']);
            }
        }
    }

    public function test_mixed_products_keep_separate_amount_bases_and_two_independent_share_levels(): void
    {
        $calculator = new ProductionPerformanceCalculator;
        $seventyThirty = $calculator->validateShares([['employee_legacy_id' => 1, 'share_ratio' => '0.7'], ['employee_legacy_id' => 2, 'share_ratio' => '0.3']])['shares'];
        $half = $calculator->validateShares($calculator->defaultShares([['employee_legacy_id' => 1], ['employee_legacy_id' => 2]]))['shares'];
        $owner = $calculator->validateShares($calculator->defaultShares([['employee_legacy_id' => 1]]))['shares'];
        $operations = [
            $calculator->amounts($calculator->sourceBasis('3000', '1', '1', '1'), '0.01', $seventyThirty),
            $calculator->amounts($calculator->sourceBasis('3000', '1', '1', '1'), '0.02', $owner),
            $calculator->amounts($calculator->sourceBasis('800', '1', '1', '1'), '0.01', $half),
            $calculator->amounts($calculator->sourceBasis('1000', '1', '1', '1'), '0.01', $seventyThirty),
        ];
        $this->assertSame(['30.0000', '60.0000', '8.0000', '10.0000'], array_column($operations, 'performance_pool_amount'));
        $this->assertSame(['21.0000', '9.0000'], array_column($operations[0]['shares'], 'performance_amount'));
        $this->assertSame(['4.0000', '4.0000'], array_column($operations[2]['shares'], 'performance_amount'));
        $this->assertSame(['7.0000', '3.0000'], array_column($operations[3]['shares'], 'performance_amount'));
        $readiness = $calculator->shipmentReadiness([
            ['id' => 1, 'line_type' => 'physical', 'order_qty' => '1'], ['id' => 2, 'line_type' => 'physical', 'order_qty' => '1'],
            ['id' => 3, 'line_type' => 'physical', 'order_qty' => '1'], ['id' => 4, 'line_type' => 'service', 'order_qty' => '1'],
            ['id' => 5, 'line_type' => 'no_delivery', 'order_qty' => '1'],
        ], [1 => ['sales_qty' => '1'], 2 => ['sales_qty' => '1'], 3 => ['sales_qty' => '1']]);
        $this->assertTrue($readiness['entire_order_shipped']);
        $this->assertSame(3, $readiness['goods_lines_total']);
    }

    public function test_current_two_piece_sale_does_not_apply_old_ten_piece_batch_fraction_twice(): void
    {
        $calculator = new ProductionPerformanceCalculator;
        $basis = $calculator->sourceBasis('1000', '2', '2', '1');
        $this->assertSame('1000.000000000000', $basis);
        $this->assertSame('10.0000', $calculator->amounts($basis, '0.01', [['employee_legacy_id' => 1, 'eligible' => true, 'share_ratio' => 1]])['personal_performance_amount']);
        $this->assertSame('500.000000000000', $calculator->sourceBasis('1000', '2', '2', '0.5'));
    }

    public function test_real_shipped_line_quantities_must_finish_all_goods_lines_and_fees_are_excluded(): void
    {
        $calculator = new ProductionPerformanceCalculator;
        $lines = [['id' => 1, 'line_type' => 'physical', 'order_qty' => '10'],
            ['id' => 2, 'line_type' => 'physical', 'order_qty' => '2'], ['id' => 3, 'line_type' => 'fee', 'order_qty' => '1']];
        $partial = $calculator->shipmentReadiness($lines, [1 => ['sales_qty' => '10'], 2 => ['sales_qty' => '1']]);
        $this->assertFalse($partial['entire_order_shipped']);
        $this->assertSame(2, $partial['goods_lines_total']);
        $this->assertTrue($calculator->shipmentReadiness($lines, [1 => ['sales_qty' => '10'], 2 => ['sales_qty' => '2']])['entire_order_shipped']);
        $this->assertFalse($calculator->shipmentReadiness($lines, [1 => ['sales_qty' => '11'], 2 => ['sales_qty' => '2']])['entire_order_shipped']);
    }

    public function test_formally_cancelled_quantity_does_not_block_actual_delivery_of_remaining_goods(): void
    {
        $calculator = new ProductionPerformanceCalculator;
        $lines = [['id' => 1, 'line_type' => 'physical', 'order_qty' => '5', 'cancelled_qty' => '2']];
        $result = $calculator->shipmentReadiness($lines, [1 => ['sales_qty' => '3']]);
        $this->assertTrue($result['entire_order_shipped']);
        $this->assertSame('3.00000000', $result['lines'][0]['required_sales_qty']);
        $this->assertFalse($calculator->shipmentReadiness($lines, [1 => ['sales_qty' => '2']])['entire_order_shipped']);
        $this->assertFalse($calculator->shipmentReadiness($lines, [1 => ['sales_qty' => '4']])['entire_order_shipped']);
    }
}
