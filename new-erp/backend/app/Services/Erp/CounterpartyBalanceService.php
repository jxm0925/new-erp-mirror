<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\SalesOrder;

class CounterpartyBalanceService
{
    public function customer(int $customerId): array
    {
        $receivable = Money::normalize((string) SalesOrder::query()->where('customer_id', $customerId)->whereNotIn('order_status', ['draft', 'cancelled'])->sum('total_amount'));
        $received = $this->allocated(FinanceConstants::PARTY_CUSTOMER, $customerId, FinanceConstants::DIRECTION_RECEIPT);
        $refunded = $this->allocated(FinanceConstants::PARTY_CUSTOMER, $customerId, FinanceConstants::DIRECTION_PAYMENT);
        $net = Money::maxZero(Money::sub($received, $refunded));
        $cancelledNet = $pending = $retained = '0.0000';
        foreach (SalesOrder::where('customer_id', $customerId)->where('order_status', 'cancelled')->get() as $order) {
            $amounts = app(SalesCancellationService::class)->amounts($order);
            $cancelledNet = Money::add($cancelledNet, Money::sub($amounts['received'], $amounts['refunded']));
            $pending = Money::add($pending, $amounts['remaining']);
            $retained = Money::add($retained, $amounts['retained']);
        }
        // Cancelled-contract funds remain a separate liability until refunded or retained by finance.
        $activeNet = Money::maxZero(Money::sub($net, $cancelledNet));
        return ['receivable_amount' => $receivable, 'received_amount' => $received,
            'outstanding_amount' => Money::maxZero(Money::sub($receivable, $activeNet)),
            'prepayment_amount' => Money::maxZero(Money::sub($activeNet, $receivable)), 'refunded_amount' => $refunded,
            'cancellation_pending_amount' => $pending, 'cancellation_retained_amount' => $retained];
    }

    public function supplier(int $supplierId): array
    {
        $payable = Money::normalize((string) PurchaseReceipt::query()->where('supplier_id', $supplierId)->where('confirm_status', 'confirmed')->where('settlement_mode', '<>', 'replacement_no_charge')->sum('settlement_amount'));
        $offset = Money::normalize((string) PurchaseReturn::query()->where('supplier_id', $supplierId)->where('settlement_effect_type', FinanceConstants::PURCHASE_EFFECT_AP_OFFSET)->whereNotIn('return_status', ['draft', 'submitted', 'cancelled'])->sum('settlement_amount'));
        $payable = Money::maxZero(Money::sub($payable, $offset));
        $paid = $this->allocated(FinanceConstants::PARTY_SUPPLIER, $supplierId, FinanceConstants::DIRECTION_PAYMENT);
        $refund = $this->allocated(FinanceConstants::PARTY_SUPPLIER, $supplierId, FinanceConstants::DIRECTION_RECEIPT);
        return ['payable_amount' => $payable, 'paid_amount' => $paid, 'unpaid_amount' => Money::maxZero(Money::sub($payable, $paid)), 'prepayment_amount' => Money::maxZero(Money::sub($paid, $payable)), 'supplier_refund_amount' => $refund];
    }

    private function allocated(string $partyType, int $partyId, string $direction): string
    {
        return Money::normalize((string) FinanceAllocation::query()->where('party_type', $partyType)->where('party_id', $partyId)
            ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
            ->whereHas('cashDocument', fn ($q) => $q->where('direction', $direction)->where('status', FinanceConstants::STATUS_CONFIRMED))
            ->sum('allocated_amount'));
    }
}
