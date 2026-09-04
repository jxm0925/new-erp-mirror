<?php

namespace App\Services\Erp;

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

        $order->update([
            'total_qty' => (float) $totals->qty,
            'total_amount' => round((float) $totals->amount + (float) $order->freight_amount, 4),
            'final_receivable_amount' => round((float) $totals->amount + (float) $order->freight_amount, 4),
        ]);

        return $order->fresh();
    }
}
