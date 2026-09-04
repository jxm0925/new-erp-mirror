<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\SalesOrder;

class SalesFinanceSettlementService
{
    public function status(int|SalesOrder $order): array
    {
        $order = $order instanceof SalesOrder ? $order : SalesOrder::query()->findOrFail($order);
        $contract = Money::normalize((string) $order->total_amount);
        $received = $this->allocated(FinanceConstants::SOURCE_SALES_ORDER, $order->id, FinanceConstants::DIRECTION_RECEIPT);
        $refunded = $this->allocated(FinanceConstants::SOURCE_SALES_ORDER_REFUND, $order->id, FinanceConstants::DIRECTION_PAYMENT);
        $net = Money::maxZero(Money::sub($received, $refunded));
        $outstanding = Money::maxZero(Money::sub($contract, $net));
        $prepayment = Money::maxZero(Money::sub($net, $contract));
        $ratio = Money::ratio($net, $contract);
        return [
            'contract_amount' => $contract,
            'received_amount' => $received,
            'outstanding_amount' => $outstanding,
            'available_prepayment_amount' => $prepayment,
            'refunded_amount' => $refunded,
            'net_received_amount' => $net,
            'allocated_amount' => $received,
            'unallocated_amount' => $outstanding,
            'receipt_ratio' => $ratio,
            'production_funds_satisfied' => Money::compare($net, '0') > 0,
            'shipment_funds_satisfied' => Money::compare($net, $contract) >= 0,
        ];
    }

    public function assertCanStartProduction(int|SalesOrder $order): void
    {
        if (!$this->status($order)['production_funds_satisfied']) throw new \DomainException('销售订单尚无有效已核销收款，不能进入生产。');
    }

    public function assertCanShip(int|SalesOrder $order): void
    {
        if (!$this->status($order)['shipment_funds_satisfied']) throw new \DomainException('销售订单净收款未达到应收金额，不能发货。');
    }

    private function allocated(string $sourceType, int $sourceId, string $direction): string
    {
        return Money::normalize((string) FinanceAllocation::query()
            ->where('source_business_type', $sourceType)->where('source_document_id', $sourceId)
            ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
            ->whereHas('cashDocument', fn ($q) => $q->where('direction', $direction)->where('status', FinanceConstants::STATUS_CONFIRMED))
            ->sum('allocated_amount'));
    }
}
