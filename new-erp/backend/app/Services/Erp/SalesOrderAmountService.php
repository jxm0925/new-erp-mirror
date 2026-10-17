<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;

class SalesOrderAmountService
{
    public function refresh(SalesOrder $order): SalesOrder
    {
        $totals = SalesOrderLine::query()
            ->where('sales_order_id', $order->id)
            ->selectRaw('COALESCE(SUM(order_qty), 0) AS qty, COALESCE(SUM(COALESCE(amount_incl_tax, amount)), 0) AS amount')
            ->first();

        $receivable = round((float) $totals->amount + (float) $order->freight_amount, 4);
        $updates = [
            'total_qty' => (float) $totals->qty,
            'total_amount' => $receivable,
            'final_receivable_amount' => $receivable,
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
