<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;

class SalesOrderAmountService
{
    public function refresh(SalesOrder $order): SalesOrder
    {
        // 短交关闭后应收已按实发金额定格，不能再按订单行全额回算。
        if ($order->short_closed_at) return $order->fresh();
        $totals = SalesOrderLine::query()
            ->where('sales_order_id', $order->id)
            ->selectRaw('COALESCE(SUM(order_qty), 0) AS qty, COALESCE(SUM(COALESCE(amount_incl_tax, amount)), 0) AS amount')
            ->first();

        $receivable = round((float) $totals->amount + (float) $order->freight_amount, 4);
        $updates = [
            'total_qty' => (float) $totals->qty,
            'total_amount' => $receivable,
            'final_receivable_amount' => $receivable,
            // 本币金额 = 应收 × 订单业务汇率（本币订单汇率为 1），供财务汇总和分析按本币口径统计。
            'base_total_amount' => round($receivable * (float) ($order->business_exchange_rate ?: 1), 4),
        ];
        foreach (['funding_policy_snapshot', 'payment_terms_snapshot'] as $field) {
            $snapshot = $order->getAttribute($field);
            if (is_array($snapshot) && array_key_exists('receivable_amount', $snapshot)) {
                $updates[$field] = array_replace($snapshot, ['receivable_amount' => Money::normalize((string) $receivable)]);
            }
        }
        $order->update($updates);

        return $order->fresh();
    }
}
