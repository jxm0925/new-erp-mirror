<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesReturn;
use App\Services\Erp\Manufacturing\WorkOrderService;
use Illuminate\Validation\ValidationException;

/**
 * 订单短交关闭：客户不再要剩余未发部分时，取消剩余数量并把应收改为“发多少记多少”
 * （实物行按已发数量折算金额 + 非实物行原金额 + 运费）。合同金额不变。
 * 不直接改订单状态：上报协调器按实发重算交付和资金，收齐款后自动“已完成”，多收须财务退款后才完成。
 */
class SalesOrderShortCloseService
{
    private const OPEN_SHIPMENT_STATUSES = ['draft', 'pending_outbound', 'prepared', 'partially_shipped'];
    private const OPEN_RETURN_STATUSES = ['pending_receipt', 'partial_received', 'received'];

    public function __construct(
        private readonly SalesFinanceSettlementService $settlements,
        private readonly InventoryReservationService $reservations,
        private readonly SalesOrderStateCoordinator $orderStates,
    ) {}

    /** 只读预览：每行关闭数量与金额、原应收 → 新应收、已收、多收，以及不能关闭的原因。 */
    public function preview(SalesOrder $order): array
    {
        $order->loadMissing('lines');
        $lines = [];
        $newReceivable = Money::normalize((string) ($order->freight_amount ?? 0));
        foreach ($order->lines->sortBy('id') as $line) {
            $physical = SalesOrderFulfillmentPolicy::isPhysical((string) ($line->line_type ?? 'physical'));
            $orderQty = (float) $line->order_qty;
            $shippedQty = (float) $line->shipped_qty;
            $amount = Money::normalize((string) ($line->amount_incl_tax ?? $line->amount ?? 0));
            $closeQty = $physical ? max(0, round($orderQty - $shippedQty, 8)) : 0.0;
            $shippedAmount = ! $physical ? $amount
                : ($orderQty <= 0 ? '0.0000' : Money::normalize(bcdiv(bcmul($amount, $this->decimal($shippedQty), 8), $this->decimal($orderQty), 2)));
            $newReceivable = Money::add($newReceivable, $shippedAmount);
            $lines[] = [
                'id' => $line->id, 'line_no' => $line->line_no, 'name' => $line->product_name ?: ($line->item_name ?: $line->sku_name),
                'sku_name' => $line->sku_name, 'unit_name' => $line->unit_name_snapshot,
                'is_physical' => $physical, 'order_qty' => $orderQty, 'shipped_qty' => $shippedQty, 'close_qty' => $closeQty,
                'amount' => $amount, 'shipped_amount' => $shippedAmount,
            ];
        }
        $status = $this->settlements->status($order);
        $before = $this->settlements->receivableAmount($order);
        $returnCredit = $status['return_credit_amount'];
        $netAfter = Money::maxZero(Money::sub($newReceivable, $returnCredit));
        return [
            'lines' => $lines,
            'contract_amount' => Money::normalize((string) ($order->total_amount ?? 0)),
            'receivable_before' => $before,
            'receivable_after' => $newReceivable,
            'short_closed_amount' => Money::sub($before, $newReceivable),
            'return_credit_amount' => $returnCredit,
            'net_received_amount' => $status['net_received_amount'],
            'overpaid_amount' => Money::maxZero(Money::sub($status['net_received_amount'], $netAfter)),
            'blockers' => $this->blockers($order),
        ];
    }

    public function close(SalesOrder $order, string $reason, string $operator, object $actor): array
    {
        $preview = $this->preview($order);
        if ($preview['blockers']) throw ValidationException::withMessages(['order' => implode('；', $preview['blockers'])]);
        foreach ($order->lines as $line) {
            if (! SalesOrderFulfillmentPolicy::isPhysical((string) ($line->line_type ?? 'physical'))) continue;
            $line->update(['cancelled_qty' => max(0, round((float) $line->order_qty - (float) $line->shipped_qty, 8))]);
        }
        $receivable = $preview['receivable_after'];
        $updates = [
            'final_receivable_amount' => $receivable,
            'short_closed_at' => now(), 'short_closed_by' => $operator, 'short_close_reason' => $reason,
            'short_closed_amount' => $preview['short_closed_amount'], 'receivable_before_short_close' => $preview['receivable_before'],
            'business_version' => (int) $order->business_version + 1,
        ];
        // 与 SalesOrderAmountService 一致：冻结的付款条件快照里的应收同步改为新应收，资金放行和完成都按它判断。
        foreach (['funding_policy_snapshot', 'payment_terms_snapshot'] as $field) {
            $snapshot = $order->getAttribute($field);
            if (is_array($snapshot) && array_key_exists('receivable_amount', $snapshot)) {
                $updates[$field] = array_replace($snapshot, ['receivable_amount' => $receivable]);
            }
        }
        $order->update($updates);
        $this->reservations->releaseForSalesOrder($order, '订单短交关闭：'.$reason);
        SalesOrderFulfillment::where('sales_order_id', $order->id)->whereIn('demand_status', ['pending', 'confirmed'])
            ->update(['demand_status' => 'cancelled', 'production_requirement_status' => 'cancelled']);
        SalesOrderFulfillment::where('sales_order_id', $order->id)->where('reservation_status', 'awaiting_purchase')->update(['reservation_status' => 'cancelled']);
        $workOrders = app(WorkOrderService::class)->cancelForSalesOrder($order, '订单短交关闭：'.$reason, $actor);
        $this->orderStates->handle(SalesOrderStateCoordinator::SHIPMENT_CHANGED, $order);
        $this->orderStates->handle(SalesOrderStateCoordinator::ORDER_TERMS_CHANGED, $order);
        return $preview + ['work_orders' => $workOrders];
    }

    /** @return string[] */
    private function blockers(SalesOrder $order): array
    {
        $reasons = [];
        if ($order->short_closed_at) $reasons[] = '订单已经短交关闭';
        if ($order->order_status !== 'confirmed') $reasons[] = '只有已确认且未完成的订单可以短交关闭';
        if ($order->lines->sum('shipped_qty') <= 0) $reasons[] = '订单一件都没有发出，请直接取消订单';
        $openLines = $order->lines->filter(fn ($line) => SalesOrderFulfillmentPolicy::isPhysical((string) ($line->line_type ?? 'physical'))
            && (float) $line->order_qty - (float) $line->cancelled_qty - (float) $line->shipped_qty > 0.00000001);
        if ($order->lines->sum('shipped_qty') > 0 && $openLines->isEmpty()) $reasons[] = '订单没有剩余未发数量，无需短交关闭';
        if ($order->shipments()->whereIn('shipment_status', self::OPEN_SHIPMENT_STATUSES)->exists()) {
            $reasons[] = '还有未完成的发货单，请先取消发货单或对部分发运的发货单“取消剩余未发”';
        }
        if ($order->change_status === 'processing') $reasons[] = '订单有进行中的变更';
        if (SalesReturn::query()->where('sales_order_id', $order->id)->whereIn('return_status', self::OPEN_RETURN_STATUSES)->exists()) {
            $reasons[] = '订单有进行中的退货';
        }
        return $reasons;
    }

    private function decimal(float $value): string
    {
        return number_format($value, 8, '.', '');
    }
}
