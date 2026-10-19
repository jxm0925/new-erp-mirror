<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;

/** Pure decimal rules used only by the PC statistics query after an entire order ships. */
final class ProductionPerformanceCalculator
{
    public const BASIS_MODE = 'discounted_goods_incl_tax';

    public function policy(): array
    {
        return ['code' => self::BASIS_MODE, 'version' => 2, 'label' => '折后商品含税金额，不含运费和另收包装费',
            'trigger' => 'entire_order_shipped', 'individual_share_mode' => 'owner_planned_or_completion_labor_minutes', 'individual_share_label' => '负责人已分配的按分配；未分配的按实际工时占比，工时均为 0 时均分',
            'new_share_total' => '1.00000000', 'normalize_shares' => false, 'historical_assignments' => 'preserve'];
    }

    public function ratio(mixed $value): string
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > 1) {
            throw new WorkOrderDomainException('performance_ratio_invalid', '比例必须在 0% 到 100% 之间。');
        }
        $decimal = is_float($value) ? number_format($value, 8, '.', '') : trim((string) $value);
        if (is_float($value) && abs($value - (float) $decimal) > 0.000000000001) {
            throw new WorkOrderDomainException('performance_ratio_precision', '比例最多保留 8 位小数。');
        }
        if (! preg_match('/^\d+(?:\.\d+)?$/D', $decimal) || strlen($decimal) > 80) {
            throw new WorkOrderDomainException('performance_ratio_invalid', '比例必须使用十进制数，1% 请填写 0.01。');
        }
        $scale = str_contains($decimal, '.') ? strlen(explode('.', $decimal, 2)[1]) : 0;
        if (bccomp($decimal, bcadd($decimal, '0', 8), max(8, $scale)) !== 0) {
            throw new WorkOrderDomainException('performance_ratio_precision', '比例最多保留 8 位小数。');
        }
        return bcadd($decimal, '0', 8);
    }

    public function validateShares(array $shares, bool $noncreditedConfirmed = false, ?string $reason = null): array
    {
        $declared = '0.00000000';
        $ids = [];
        $normalized = [];
        foreach ($shares as $share) {
            $id = (int) ($share['employee_legacy_id'] ?? 0);
            if ($id <= 0 || in_array($id, $ids, true)) throw new WorkOrderDomainException('performance_employee_invalid', '绩效人员必须有效且不能重复。');
            if (! array_key_exists('share_ratio', $share)) {
                throw new WorkOrderDomainException('performance_share_missing', '请填写每位参与人员的个人份额。');
            }
            $ids[] = $id;
            $ratio = $this->ratio($share['share_ratio']);
            // The new contract divides the whole operation pool. Historical eligible=false
            // rows are still read by amounts(), but cannot be used to create a new unpaid tail.
            if (array_key_exists('eligible', $share) && filter_var($share['eligible'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) {
                throw new WorkOrderDomainException('performance_eligibility_invalid', '新个人份额直接按参与人分配，不再登记不计绩效人员。');
            }
            $declared = bcadd($declared, $ratio, 8);
            $normalized[] = ['employee_legacy_id' => $id, 'eligible' => true, 'share_ratio' => $ratio,
                'remark' => trim((string) ($share['remark'] ?? '')) ?: null];
        }
        if (bccomp($declared, '1', 8) !== 0) throw new WorkOrderDomainException('performance_share_total_invalid', '本工序参与人员的个人份额合计必须等于 100%。');
        usort($normalized, fn ($a, $b) => $a['employee_legacy_id'] <=> $b['employee_legacy_id']);
        return ['shares' => $normalized, 'credited_share_ratio' => '1.00000000', 'noncredited_share_ratio' => '0.00000000'];
    }

    /** Stable remainder allocation gives three participants exactly 100%, without normalizing entered shares. */
    public function defaultShares(array $participants): array
    {
        $ids = array_values(array_unique(array_map(fn ($row) => (int) ($row['employee_legacy_id'] ?? 0), $participants)));
        $ids = array_values(array_filter($ids, fn ($id) => $id > 0));
        sort($ids);
        if ($ids === []) return [];
        $equal = bcdiv('1', (string) count($ids), 8);
        $remaining = '1.00000000'; $shares = [];
        foreach ($ids as $index => $id) {
            $ratio = $index === count($ids) - 1 ? $remaining : $equal;
            $remaining = bcsub($remaining, $ratio, 8);
            $shares[] = ['employee_legacy_id' => $id, 'eligible' => true, 'share_ratio' => $ratio, 'remark' => null];
        }
        return $shares;
    }

    /** Completion default: weight by actual labor minutes; falls back to equal shares when no labor was recorded. */
    public function laborShares(array $participants): array
    {
        $minutes = [];
        foreach ($participants as $row) {
            $id = (int) ($row['employee_legacy_id'] ?? 0);
            if ($id <= 0) continue;
            $value = is_numeric($row['actual_labor_minutes'] ?? null) && (float) $row['actual_labor_minutes'] > 0 ? bcadd((string) $row['actual_labor_minutes'], '0', 4) : '0.0000';
            $minutes[$id] = bcadd($minutes[$id] ?? '0', $value, 4);
        }
        ksort($minutes);
        $total = array_reduce($minutes, fn ($sum, $value) => bcadd($sum, $value, 4), '0');
        if ($minutes === [] || bccomp($total, '0', 4) <= 0) return $this->defaultShares($participants);
        $remaining = '1.00000000'; $shares = []; $ids = array_keys($minutes);
        foreach ($ids as $index => $id) {
            $ratio = $index === count($ids) - 1 ? $remaining : bcdiv($minutes[$id], $total, 8);
            $remaining = bcsub($remaining, $ratio, 8);
            $shares[] = ['employee_legacy_id' => $id, 'eligible' => true, 'share_ratio' => $ratio,
                'remark' => '按实际工时 '.rtrim(rtrim($minutes[$id], '0'), '.').' 分钟'];
        }
        return $shares;
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
            $required = bcsub($required, (string) ($line['cancelled_qty'] ?? '0'), 8);
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
