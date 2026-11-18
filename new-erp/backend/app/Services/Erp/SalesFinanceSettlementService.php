<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\SalesOrder;

class SalesFinanceSettlementService
{
    public function __construct(private readonly FinanceAllocationBalanceQueryService $balances) {}

    public function status(int|SalesOrder $order): array
    {
        $order = $order instanceof SalesOrder ? $order : SalesOrder::query()->findOrFail($order);
        $contract = $this->receivableAmount($order);
        $received = $this->allocated(FinanceConstants::SOURCE_SALES_ORDER, $order->id, FinanceConstants::DIRECTION_RECEIPT);
        $refunded = $this->allocated(FinanceConstants::SOURCE_SALES_ORDER_REFUND, $order->id, FinanceConstants::DIRECTION_PAYMENT);
        $net = Money::settle(Money::maxZero(Money::sub($received, $refunded)));
        // 退货退款按已认可退货数量冲减应收；合同金额保持原值，未收/应退均按净应收计算。
        $returnCredit = SalesReturnSettlementService::orderCreditAmount((int) $order->id);
        $netReceivable = Money::settle(Money::maxZero(Money::sub($contract, $returnCredit)));
        $cancelled = $order->order_status === 'cancelled';
        if ($cancelled) $netReceivable = '0.00';
        $outstanding = Money::settle(Money::maxZero(Money::sub($netReceivable, $net)));
        $prepayment = Money::settle(Money::maxZero(Money::sub($net, $netReceivable)));
        if ($cancelled) $prepayment = Money::settle(app(SalesCancellationService::class)->amounts($order)['remaining']);
        $ratio = Money::ratio($net, $netReceivable);
        return [
            'contract_amount' => $contract,
            'return_credit_amount' => $returnCredit,
            'net_receivable_amount' => $netReceivable,
            'received_amount' => $received,
            'outstanding_amount' => $outstanding,
            'available_prepayment_amount' => $prepayment,
            'refunded_amount' => $refunded,
            'net_received_amount' => $net,
            'allocated_amount' => $received,
            'unallocated_amount' => $outstanding,
            'receipt_ratio' => $ratio,
            'production_funds_satisfied' => !$cancelled,
            'shipment_funds_satisfied' => !$cancelled && Money::compare($net, $netReceivable) >= 0,
        ];
    }

    public function assertCanShip(int|SalesOrder $order): void
    {
        if (!$this->status($order)['shipment_funds_satisfied']) throw new \DomainException('销售订单净收款未达到应收金额，不能发货。');
    }

    public function receivableAmount(SalesOrder $order): string
    {
        $frozen = data_get($order->funding_policy_snapshot, 'receivable_amount');
        if ($frozen !== null && $frozen !== '') return Money::settle((string) $frozen);
        $total = Money::settle((string) ($order->total_amount ?? 0));
        $final = $order->final_receivable_amount;
        if ($final !== null && $final !== '') {
            if ($order->short_closed_at || Money::compare((string) $final, '0') > 0 || Money::compare($total, '0') === 0) return Money::settle((string) $final);
        }
        return $total;
    }

    private function allocated(string $sourceType, int $sourceId, string $direction): string
    {
        return $this->balances->forSource($sourceType, $sourceId, $direction);
    }
}
