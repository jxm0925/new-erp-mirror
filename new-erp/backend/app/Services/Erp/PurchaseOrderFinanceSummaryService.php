<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Domain\Finance\FinanceConstants;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\FinanceInvoiceAllocation;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\PurchaseSettlementSource;

/**
 * Read-only financial projection for a purchase order.
 *
 * It deliberately aggregates settlement sources rather than deriving any
 * payable fact from the purchase order itself. Payment and allocation remain
 * exclusively in Finance Core.
 */
class PurchaseOrderFinanceSummaryService
{
    public function forOrder(PurchaseOrder|int $order): array
    {
        $order = $order instanceof PurchaseOrder
            ? $order
            : PurchaseOrder::query()->findOrFail($order);

        $sources = PurchaseSettlementSource::query()
            ->where('purchase_order_id', $order->id)
            ->get();

        $sum = fn (string $field): string => $this->sum($sources->pluck($field)->all());
        $confirmedReceiptAmount = $sum('original_amount');
        $eligibleAmount = $sum('eligible_amount');
        $qualityFrozenAmount = $sum('frozen_amount');
        $returnOffsetAmount = $sum('ap_offset_amount');
        $paidAmount = $sum('allocated_amount');
        $currentPayableAmount = $sources->reduce(fn (string $total, PurchaseSettlementSource $source): string => Money::add($total, Money::maxZero(Money::sub((string) $source->eligible_amount, (string) $source->ap_offset_amount))), '0.0000');
        $unpaidAmount = $sources->reduce(fn (string $total, PurchaseSettlementSource $source): string => Money::add($total, Money::maxZero(Money::sub(Money::maxZero(Money::sub((string) $source->eligible_amount, (string) $source->ap_offset_amount)), (string) $source->allocated_amount))), '0.0000');
        $invoiceMatchedAmount = $sum('invoice_matched_amount');
        $invoiceUnmatchedAmount = $sum('invoice_unmatched_amount');
        $sourceIds = $sources->pluck('id')->map(fn ($id) => (int) $id)->all();
        $receiptIds = $sources->pluck('source_receipt_id')->map(fn ($id) => (int) $id)->all();
        $pendingRefundAmount = $this->pendingRefundAmount($receiptIds);
        $prepaymentAppliedAmount = $this->prepaymentAppliedAmount($sourceIds);
        $redInvoiceAmount = $this->redInvoiceAmount($sourceIds);

        return [
            'currency' => $sources->first()?->currency ?: 'CNY',
            'contract_amount' => Money::normalize((string) ($order->amount_incl_tax ?? $order->total_amount ?? 0)),
            'confirmed_receipt_amount' => $confirmedReceiptAmount,
            'eligible_amount' => $eligibleAmount,
            'quality_frozen_amount' => $qualityFrozenAmount,
            'current_payable_amount' => $currentPayableAmount,
            'return_offset_amount' => $returnOffsetAmount,
            'paid_amount' => $paidAmount,
            'unpaid_amount' => $unpaidAmount,
            'prepayment_applied_amount' => $prepaymentAppliedAmount,
            'pending_refund_amount' => $pendingRefundAmount,
            'invoice_matched_amount' => $invoiceMatchedAmount,
            'red_invoice_amount' => $redInvoiceAmount,
            'net_received_invoice_amount' => $invoiceMatchedAmount,
            'invoice_unmatched_amount' => $invoiceUnmatchedAmount,
            'fulfillment_status' => $this->fulfillmentStatus($order),
            'payment_status' => $this->paymentStatus($eligibleAmount, $unpaidAmount, $paidAmount),
            // Invoice UI/matching is deliberately deferred. These fields are
            // only a stable interface boundary for the existing invoice core.
            'invoice_status' => $this->invoiceStatus($eligibleAmount, $invoiceUnmatchedAmount),
            'financial_settlement_status' => $this->financialSettlementStatus($order, $qualityFrozenAmount, $unpaidAmount, $invoiceUnmatchedAmount, $pendingRefundAmount),
            'source_count' => $sources->count(),
        ];
    }

    private function sum(array $amounts): string
    {
        return array_reduce($amounts, fn (string $total, mixed $amount): string => Money::add($total, (string) $amount), '0.0000');
    }

    private function fulfillmentStatus(PurchaseOrder $order): string
    {
        return match ($order->receipt_status) {
            'received' => 'completed',
            'partial' => 'partial',
            default => 'pending',
        };
    }

    private function paymentStatus(string $eligible, string $unpaid, string $paid): string
    {
        if (Money::compare($eligible, '0') <= 0) return 'not_due';
        if (Money::compare($unpaid, '0') <= 0) return Money::compare($paid, '0') > 0 ? 'paid' : 'offset';
        return Money::compare($paid, '0') > 0 ? 'partial' : 'unpaid';
    }

    private function invoiceStatus(string $eligible, string $unmatched): string
    {
        if (Money::compare($eligible, '0') <= 0) return 'not_required';
        return Money::compare($unmatched, '0') <= 0 ? 'matched' : 'unmatched';
    }

    private function financialSettlementStatus(PurchaseOrder $order, string $frozen, string $unpaid, string $unreceivedInvoice, string $pendingRefund): string
    {
        if ($this->fulfillmentStatus($order) !== 'completed') return 'pending_fulfillment';
        if (Money::compare($frozen, '0') > 0) return 'quality_frozen';
        if (Money::compare($pendingRefund, '0') > 0) return 'pending_refund';
        if (Money::compare($unpaid, '0') > 0) return 'pending_payment';
        if (Money::compare($unreceivedInvoice, '0') > 0) return 'pending_invoice';
        return 'settled';
    }

    private function pendingRefundAmount(array $receiptIds): string
    {
        if ($receiptIds === []) return '0.0000';
        return Money::normalize((string) PurchaseReturn::query()
            ->whereIn('source_receipt_id', $receiptIds)
            ->where('settlement_effect_type', FinanceConstants::PURCHASE_EFFECT_SUPPLIER_REFUND)
            ->where('return_status', 'completed')
            ->sum('settlement_amount'));
    }

    private function prepaymentAppliedAmount(array $sourceIds): string
    {
        if ($sourceIds === []) return '0.0000';
        return Money::normalize((string) FinanceAllocation::query()
            ->join('erp_finance_cash_documents as documents', 'documents.id', '=', 'erp_finance_allocations.cash_document_id')
            ->join('erp_purchase_settlement_sources as sources', 'sources.id', '=', 'erp_finance_allocations.source_document_id')
            ->where('erp_finance_allocations.source_business_type', FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE)
            ->where('erp_finance_allocations.status', FinanceConstants::ALLOCATION_ACTIVE)
            ->where('documents.direction', FinanceConstants::DIRECTION_PAYMENT)
            ->where('documents.status', FinanceConstants::STATUS_CONFIRMED)
            ->where('documents.currency', 'CNY')
            ->whereIn('erp_finance_allocations.source_document_id', $sourceIds)
            ->whereColumn('documents.business_date', '<', 'sources.business_date')
            ->sum('erp_finance_allocations.allocated_amount'));
    }

    private function redInvoiceAmount(array $sourceIds): string
    {
        if ($sourceIds === []) return '0.0000';
        $signed = FinanceInvoiceAllocation::query()
            ->where('source_business_type', FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE)
            ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
            ->whereIn('source_document_id', $sourceIds)
            ->whereHas('invoice', fn ($query) => $query->where('status', FinanceConstants::STATUS_RED))
            ->sum('allocated_amount');
        return Money::maxZero(Money::negate((string) $signed));
    }
}
