<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;

/** Pure decimal rules used only by the PC statistics query after an entire order ships. */
final class ProductionPerformanceCalculator
{
    public const BASIS_MODE = 'discounted_goods_incl_tax';

    public function policy(): array
    {
        return ['code' => self::BASIS_MODE, 'version' => 1, 'label' => '折后商品含税金额，不含运费和另收包装费',
            'trigger' => 'entire_order_shipped', 'individual_share_mode' => 'owner_confirmed', 'normalize_shares' => false];
    }

    public function ratio(mixed $value): string
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > 1) {
            throw new WorkOrderDomainException('performance_ratio_invalid', '比例必须在 0% 到 100% 之间。');
        }
        return number_format((float) $value, 8, '.', '');
    }

    public function validateShares(array $shares, bool $noncreditedConfirmed, ?string $reason): array
    {
        $credited = '0.00000000';
        $declared = '0.00000000';
        $ids = [];
        $normalized = [];
        foreach ($shares as $share) {
            $id = (int) ($share['employee_legacy_id'] ?? 0);
            if ($id <= 0 || in_array($id, $ids, true)) throw new WorkOrderDomainException('performance_employee_invalid', '绩效人员必须有效且不能重复。');
            if (! array_key_exists('eligible', $share) || ! array_key_exists('share_ratio', $share)) {
                throw new WorkOrderDomainException('performance_share_missing', '请明确是否计个人绩效及个人份额，不能用默认零代替未登记。');
            }
            $ids[] = $id;
            $ratio = $this->ratio($share['share_ratio']);
            if ($share['eligible'] === null || (is_string($share['eligible']) && trim($share['eligible']) === '')) {
                throw new WorkOrderDomainException('performance_eligibility_invalid', '是否计个人绩效必须明确选择。');
            }
            $eligible = filter_var($share['eligible'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($eligible === null) throw new WorkOrderDomainException('performance_eligibility_invalid', '是否计个人绩效必须明确选择。');
            $declared = bcadd($declared, $ratio, 8);
            if ($eligible) $credited = bcadd($credited, $ratio, 8);
            $normalized[] = ['employee_legacy_id' => $id, 'eligible' => $eligible, 'share_ratio' => $ratio,
                'remark' => trim((string) ($share['remark'] ?? '')) ?: null];
        }
        if (bccomp($declared, '1', 8) > 0) throw new WorkOrderDomainException('performance_share_exceeded', '本工序登记的个人份额合计不能超过 100%。');
        $noncredited = bcsub('1', $credited, 8);
        if (bccomp($noncredited, '0', 8) > 0 && (! $noncreditedConfirmed || trim((string) $reason) === '')) {
            throw new WorkOrderDomainException('performance_noncredited_confirmation_required', '请确认剩余份额不计个人绩效并填写原因。');
        }
        usort($normalized, fn ($a, $b) => $a['employee_legacy_id'] <=> $b['employee_legacy_id']);
        return ['shares' => $normalized, 'credited_share_ratio' => $credited, 'noncredited_share_ratio' => $noncredited];
    }

    public function sourceBasis(string $lineAmount, string $sourceSalesQty, string $orderSalesQty, string $coverage): string
    {
        if (bccomp($orderSalesQty, '0', 8) <= 0 || bccomp($sourceSalesQty, '0', 8) < 0 || bccomp($lineAmount, '0', 4) < 0) {
            throw new WorkOrderDomainException('performance_basis_invalid', '商品金额或销售数量不完整，暂不能计算绩效。');
        }
        // Coverage is the ancestor's contribution to this shipment source slice.
        // A 2-piece slice from an old 10-piece batch still has coverage 1 for its
        // sole welding ancestor; multiplying by 2/10 here would allocate twice.
        $coverage = $this->ratio($coverage);
        return bcmul(bcdiv(bcmul($lineAmount, $sourceSalesQty, 12), $orderSalesQty, 12), $coverage, 12);
    }

    public function shipmentReadiness(array $lines, array $shippedByLine): array
    {
        $goods = [];
        $complete = true;
        foreach ($lines as $line) {
            if (in_array($line['line_type'] ?? '', ['service', 'no_delivery', 'fee', 'auxiliary'], true)
                || ($line['line_status'] ?? '') === 'cancelled') continue;
            $required = bcsub(bcsub((string) ($line['order_qty'] ?? '0'), (string) ($line['no_delivery_qty'] ?? '0'), 8),
                (string) ($line['service_fulfilled_qty'] ?? '0'), 8);
            if (bccomp($required, '0', 8) <= 0) continue;
            $shipped = (string) ($shippedByLine[(int) $line['id']]['sales_qty'] ?? '0');
            $comparison = bccomp($shipped, $required, 8);
            if ($comparison !== 0) $complete = false;
            $goods[] = ['sales_order_line_id' => (int) $line['id'], 'required_sales_qty' => $required,
                'shipped_sales_qty' => $shipped, 'remaining_sales_qty' => $comparison < 0 ? bcsub($required, $shipped, 8) : '0.00000000',
                'complete' => $comparison === 0, 'quantity_conflict' => $comparison > 0];
        }
        return ['entire_order_shipped' => $goods !== [] && $complete, 'goods_lines_total' => count($goods),
            'fully_shipped_lines' => count(array_filter($goods, fn ($line) => $line['complete'])), 'lines' => $goods];
    }

    public function amounts(string $basis, string $rate, array $shares): array
    {
        $pool = bcmul($basis, $this->ratio($rate), 12);
        $personal = '0.000000000000';
        $employees = [];
        foreach ($shares as $share) {
            $amount = ($share['eligible'] ?? false) ? bcmul($pool, $this->ratio($share['share_ratio']), 12) : '0.000000000000';
            $personal = bcadd($personal, $amount, 12);
            $employees[] = $share + ['performance_amount' => $this->round($amount)];
        }
        return ['performance_pool_amount' => $this->round($pool), 'personal_performance_amount' => $this->round($personal),
            'noncredited_amount' => $this->round(bcsub($pool, $personal, 12)), 'shares' => $employees];
    }

    public function round(string $amount): string
    {
        return bcadd($amount, '0.00005', 4);
    }
}
