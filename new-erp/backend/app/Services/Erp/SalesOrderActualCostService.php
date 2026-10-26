<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesShipment;

/** Keeps the order's displayed actual cost derived from immutable shipment/return facts. */
class SalesOrderActualCostService
{
    public function refresh(int $salesOrderId): void
    {
        // 根订单锁由协调器持有；锁读明细避免沿用业务入口更早的 RR 快照。
        $outboundCost = SalesShipment::query()
            ->where('sales_order_id', $salesOrderId)
            ->whereIn('shipment_status', ['outbound_posted', 'partially_shipped', 'shipped', 'completed'])
            ->orderBy('id')->lockForUpdate()->get(['actual_cost_amount'])
            ->reduce(fn (string $sum, SalesShipment $row): string => Money::add($sum, (string) $row->actual_cost_amount), '0.0000');

        $reversedReturnCost = InventoryTransactionItem::query()
            ->join('erp_inventory_transactions', 'erp_inventory_transactions.id', '=', 'erp_inventory_transaction_items.transaction_id')
            ->join('erp_sales_return_receipts', function ($join): void {
                $join->on('erp_sales_return_receipts.id', '=', 'erp_inventory_transaction_items.source_id')
                    ->where('erp_inventory_transaction_items.source_type', '=', 'sales_return_receipt');
            })
            ->join('erp_sales_returns', 'erp_sales_returns.id', '=', 'erp_sales_return_receipts.sales_return_id')
            ->where('erp_sales_returns.sales_order_id', $salesOrderId)
            ->where('erp_inventory_transactions.transaction_type', 'sales_return_inbound')
            ->where('erp_inventory_transactions.posting_status', 'posted')
            ->orderBy('erp_inventory_transaction_items.id')->lockForUpdate()->get(['erp_inventory_transaction_items.cost_amount'])
            ->reduce(fn (string $sum, InventoryTransactionItem $row): string => Money::add($sum, (string) $row->cost_amount), '0.0000');

        SalesOrder::query()->whereKey($salesOrderId)->update([
            'actual_sales_cost_amount' => Money::maxZero(Money::sub($outboundCost, $reversedReturnCost)),
        ]);
    }
}
