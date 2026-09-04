<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesShipment;

/** Keeps the order's displayed actual cost derived from immutable shipment/return facts. */
class SalesOrderActualCostService
{
    public function refresh(int $salesOrderId): void
    {
        $outboundCost = (float) SalesShipment::query()
            ->where('sales_order_id', $salesOrderId)
            ->whereIn('shipment_status', ['outbound_posted', 'shipped', 'completed'])
            ->sum('actual_cost_amount');

        $reversedReturnCost = (float) InventoryTransactionItem::query()
            ->join('erp_inventory_transactions', 'erp_inventory_transactions.id', '=', 'erp_inventory_transaction_items.transaction_id')
            ->join('erp_sales_return_receipts', function ($join): void {
                $join->on('erp_sales_return_receipts.id', '=', 'erp_inventory_transaction_items.source_id')
                    ->where('erp_inventory_transaction_items.source_type', '=', 'sales_return_receipt');
            })
            ->join('erp_sales_returns', 'erp_sales_returns.id', '=', 'erp_sales_return_receipts.sales_return_id')
            ->where('erp_sales_returns.sales_order_id', $salesOrderId)
            ->where('erp_inventory_transactions.transaction_type', 'sales_return_inbound')
            ->where('erp_inventory_transactions.posting_status', 'posted')
            ->sum('erp_inventory_transaction_items.cost_amount');

        SalesOrder::query()->whereKey($salesOrderId)->update([
            'actual_sales_cost_amount' => round(max(0, $outboundCost - $reversedReturnCost), 4),
        ]);
    }
}
