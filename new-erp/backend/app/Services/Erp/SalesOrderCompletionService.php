<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use App\Domain\Finance\Money;
use App\Models\Erp\SalesReturn;

/**
 * 订单完结：已生产确认、实物全部发出（或无需发货）、收齐最终应收且无未结退货时自动“已完成”（order_status=closed）；
 * 之后发生退货、退款等使条件不再满足时自动回到“已确认”，以便继续换货发货或收退款。
 */
final class SalesOrderCompletionService
{
    private const OPEN_RETURN_STATUSES = ['pending_receipt', 'partial_received', 'received'];

    public function refresh(SalesOrder|int|null $order): void
    {
        if ($order === null) return;
        $order = $order instanceof SalesOrder ? $order->fresh() : SalesOrder::query()->find($order);
        if (! $order || ! in_array($order->order_status, ['confirmed', 'closed'], true)) return;
        $done = $order->production_confirm_status === 'confirmed'
            && in_array($order->shipment_status, ['shipped', 'not_required'], true)
            && $order->shipment_funding_status === 'passed'
            && $order->change_status !== 'processing'
            && ! SalesReturn::query()->where('sales_order_id', $order->id)->whereIn('return_status', self::OPEN_RETURN_STATUSES)->exists()
            && ! $this->hasPendingReturnRefund($order);
        $target = $done ? 'closed' : 'confirmed';
        if ($order->order_status !== $target) $order->update(['order_status' => $target]);
    }

    /** 退货退款冲减应收后，客户多付的部分须由财务退款核销完毕，订单才算完结。 */
    private function hasPendingReturnRefund(SalesOrder $order): bool
    {
        if (Money::compare(SalesReturnSettlementService::orderCreditAmount((int) $order->id), '0') <= 0) return false;
        return Money::compare(app(SalesFinanceSettlementService::class)->status($order)['available_prepayment_amount'], '0') > 0;
    }
}
