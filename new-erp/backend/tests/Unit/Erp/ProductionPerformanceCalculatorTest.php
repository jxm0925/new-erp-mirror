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

    public function test_owner_confirmed_eighty_percent_is_not_normalized_and_excluded_worker_gets_no_amount(): void
    {
        $calculator = new ProductionPerformanceCalculator;
        $allocation = $calculator->validateShares([
            ['employee_legacy_id' => 1, 'eligible' => true, 'share_ratio' => '0.6'],
            ['employee_legacy_id' => 2, 'eligible' => true, 'share_ratio' => '0.2'],
            ['employee_legacy_id' => 3, 'eligible' => false, 'share_ratio' => '0.2'],
        ], true, '临时参与部分不计个人绩效');
        $this->assertSame('0.80000000', $allocation['credited_share_ratio']);
        $this->assertSame('0.20000000', $allocation['noncredited_share_ratio']);
        $amounts = $calculator->amounts('5000', '0.01', $allocation['shares']);
        $this->assertSame('50.0000', $amounts['performance_pool_amount']);
        $this->assertSame('40.0000', $amounts['personal_performance_amount']);
        $this->assertSame(['30.0000', '10.0000', '0.0000'], array_column($amounts['shares'], 'performance_amount'));
        $this->assertSame('10.0000', $amounts['noncredited_amount']);
    }

    public function test_confirmed_zero_is_valid_but_missing_share_is_not_zero(): void
    {
        $calculator = new ProductionPerformanceCalculator;
        $allocation = $calculator->validateShares([['employee_legacy_id' => 1, 'eligible' => true, 'share_ratio' => 0]], true, '本工序不计个人绩效');
        $this->assertSame('0.00000000', $allocation['credited_share_ratio']);
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
}
