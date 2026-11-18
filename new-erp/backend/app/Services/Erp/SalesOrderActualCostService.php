<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesShipment;

/** Keeps the order's displayed actual cost derived from immutable shipment/return facts. */
class SalesOrderActualCostService
{
    public function refresh(int $salesOrderId, array $movementCosts = []): void
    {
        if (SalesOrder::find($salesOrderId)?->isLegacyHistory()) return;
        // 根订单锁由协调器持有；锁读明细避免沿用业务入口更早的 RR 快照。
        $outboundCost = SalesShipment::query()
            ->where('sales_order_id', $salesOrderId)
            ->whereIn('shipment_status', ['outbound_posted', 'partially_shipped', 'shipped', 'completed'])
            ->with(['lines' => fn ($query) => $query->select('id', 'shipment_id', 'cost_amount_snapshot')->orderBy('id')->lockForUpdate()])
            ->orderBy('id')->lockForUpdate()->get(['id', 'actual_cost_amount'])
            ->reduce(function (string $sum, SalesShipment $row): string {
                // 在订单层冲回原始成本后再取分，避免多批次先各自取分造成整单全退仍留一分。
                $raw = $row->lines->reduce(fn ($cost, $line) => bcadd($cost, (string) $line->cost_amount_snapshot, 4), '0.0000');
                return Money::add($sum, bccomp($raw, '0', 4) === 0 ? (string) $row->actual_cost_amount : $raw);
            }, '0.0000');

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
            ->orderBy('erp_inventory_transaction_items.id')->lockForUpdate()->get(['erp_inventory_transaction_items.id', 'erp_inventory_transaction_items.cost_amount'])
            ->reduce(fn (string $sum, InventoryTransactionItem $row): string => Money::add($sum, (string) ($movementCosts[$row->id] ?? $row->cost_amount)), '0.0000');

        SalesOrder::query()->whereKey($salesOrderId)->update([
            'actual_sales_cost_amount' => Money::settle(Money::maxZero(Money::sub($outboundCost, $reversedReturnCost))),
        ]);
    }
}
