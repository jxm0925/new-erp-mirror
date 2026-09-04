<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\PurchaseExchangeOrder;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReturn;

class PurchaseFinanceSettlementService
{
    public function receipt(int|PurchaseReceipt $receipt): array
    {
        $receipt = $receipt instanceof PurchaseReceipt ? $receipt : PurchaseReceipt::query()->findOrFail($receipt);
        $settleable = Money::normalize((string) $receipt->settlement_amount);
        $offset = Money::normalize((string) PurchaseReturn::query()->where('source_receipt_id', $receipt->id)
            ->where('settlement_effect_type', FinanceConstants::PURCHASE_EFFECT_AP_OFFSET)->whereNotIn('return_status', ['draft', 'submitted', 'cancelled'])->sum('settlement_amount'));
        $payable = Money::maxZero(Money::sub($settleable, $offset));
        $paid = Money::normalize((string) FinanceAllocation::query()
            ->where('source_business_type', FinanceConstants::SOURCE_PURCHASE_RECEIPT)->where('source_document_id', $receipt->id)
            ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
            ->whereHas('cashDocument', fn ($q) => $q->where('direction', FinanceConstants::DIRECTION_PAYMENT)->where('status', FinanceConstants::STATUS_CONFIRMED))
            ->sum('allocated_amount'));
        return [
            'receipt_no' => $receipt->receipt_no,
            'settleable_amount' => $settleable,
            'quality_hold_amount' => Money::normalize((string) ($receipt->quality_hold_amount ?? '0')),
            'rejected_claim_amount' => Money::normalize((string) ($receipt->rejected_claim_amount ?? '0')),
            'ap_offset_amount' => $offset,
            'payable_amount' => $payable,
            'paid_amount' => $paid,
            'unpaid_amount' => Money::maxZero(Money::sub($payable, $paid)),
            'inventory_cost_amount' => Money::normalize((string) ($receipt->inventory_cost_amount ?? '0')),
        ];
    }

    public function exchange(int|PurchaseExchangeOrder $exchange): array
    {
        $exchange = $exchange instanceof PurchaseExchangeOrder ? $exchange : PurchaseExchangeOrder::query()->findOrFail($exchange);
        return ['replacement_payable_amount' => Money::normalize((string) $exchange->replacement_payable_amount), 'can_be_payment_source' => false];
    }

    public function returnSemantics(int|PurchaseReturn $return): array
    {
        $return = $return instanceof PurchaseReturn ? $return : PurchaseReturn::query()->findOrFail($return);
        return ['settlement_effect_type' => $return->settlement_effect_type, 'settlement_amount' => Money::normalize((string) $return->settlement_amount)];
    }
}
