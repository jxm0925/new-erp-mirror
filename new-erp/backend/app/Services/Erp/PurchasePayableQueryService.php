<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\FinanceInvoice;
use App\Models\Erp\FinanceInvoiceAllocation;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\PurchaseReturnItem;
use App\Models\Erp\PurchaseSettlementSource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model for the CNY purchasing settlement facts.
 *
 * It deliberately reads PurchaseSettlementSource instead of reconstructing
 * payable figures from receipt screens.  A receipt, a quality hold, a return,
 * a payment allocation and an invoice allocation are different facts; the
 * settlement source is the only place where their current settlement effect
 * is frozen together.
 */
class PurchasePayableQueryService
{
    public function payables(array $filters, int $perPage): array
    {
        $query = $this->baseSourceQuery($filters)->with('supplier:id,supplier_code,supplier_name');
        $summary = $this->sourceSummary(clone $query);
        $page = $query->latest('business_date')->latest('id')->paginate($perPage);

        $sourceExtras = $this->sourceExtras($page->getCollection());

        return [
            ...$page->toArray(),
            'data' => $page->getCollection()->map(fn (PurchaseSettlementSource $source) => $this->payablePayload(
                $source,
                $sourceExtras[(int) $source->id] ?? ['prepayment_applied_amount' => '0.0000', 'pending_refund_amount' => '0.0000', 'red_invoice_amount' => '0.0000'],
            ))->values(),
            'summary' => $summary,
        ];
    }

    public function supplierLedgers(array $filters, int $perPage): array
    {
        $sourceQuery = $this->baseSourceQuery($filters);
        $grouped = (clone $sourceQuery)
            ->select('supplier_id', 'supplier_name_snapshot')
            ->selectRaw('MIN(business_date) as first_business_date')
            ->selectRaw('SUM(eligible_amount - ap_offset_amount) as payable_amount')
            ->selectRaw('SUM(frozen_amount) as quality_frozen_amount')
            ->selectRaw('SUM(allocated_amount) as paid_amount')
            ->selectRaw('SUM(invoice_matched_amount) as received_invoice_amount')
            ->selectRaw('SUM(invoice_unmatched_amount) as unreceived_invoice_amount')
            ->groupBy('supplier_id', 'supplier_name_snapshot')
            ->orderBy('supplier_name_snapshot')
            ->paginate($perPage);

        $supplierIds = collect($grouped->items())->pluck('supplier_id')->map(fn ($id) => (int) $id)->all();
        $supplement = $this->supplierSupplements($supplierIds);
        $rows = collect($grouped->items())->map(function (object $row) use ($supplement): array {
            $supplierId = (int) $row->supplier_id;
            $payable = Money::maxZero((string) $row->payable_amount);
            $paid = Money::normalize((string) $row->paid_amount);
            $receivedInvoice = Money::normalize((string) $row->received_invoice_amount);
            $pendingRefund = $supplement[$supplierId]['pending_refund_amount'] ?? '0.0000';
            $paymentTotal = $supplement[$supplierId]['confirmed_payment_amount'] ?? $paid;

            return [
                'supplier_id' => $supplierId,
                'supplier_code' => $supplement[$supplierId]['supplier_code'] ?? null,
                'supplier_name' => (string) $row->supplier_name_snapshot,
                'current_payable_amount' => $payable,
                'quality_frozen_amount' => Money::normalize((string) $row->quality_frozen_amount),
                'paid_amount' => $paid,
                'unpaid_amount' => Money::maxZero(Money::sub($payable, $paid)),
                'prepayment_balance_amount' => Money::maxZero(Money::sub($paymentTotal, $paid)),
                'pending_refund_amount' => $pendingRefund,
                'received_invoice_amount' => $receivedInvoice,
                'unreceived_invoice_amount' => Money::maxZero(Money::sub($payable, $receivedInvoice)),
                'red_invoice_amount' => $supplement[$supplierId]['red_invoice_amount'] ?? '0.0000',
                'payment_status' => $this->paymentStatus($payable, $paid, (string) $row->quality_frozen_amount),
                'invoice_status' => $this->invoiceStatus($payable, $receivedInvoice),
                'finance_status' => $this->financeStatus($payable, $paid, $receivedInvoice, (string) $row->quality_frozen_amount, $pendingRefund),
            ];
        })->values();

        $summary = $rows->reduce(function (array $carry, array $row): array {
            foreach (['current_payable_amount', 'prepayment_balance_amount', 'pending_refund_amount', 'unreceived_invoice_amount'] as $field) {
                $carry[$field] = Money::add($carry[$field], $row[$field]);
            }
            return $carry;
        }, [
            'current_payable_amount' => '0.0000',
            'prepayment_balance_amount' => '0.0000',
            'pending_refund_amount' => '0.0000',
            'unreceived_invoice_amount' => '0.0000',
        ]);

        return [...$grouped->toArray(), 'data' => $rows, 'summary' => $summary];
    }

    private function baseSourceQuery(array $filters): Builder
    {
        $query = PurchaseSettlementSource::query()->where('currency', 'CNY');
        if (!empty($filters['supplier_id'])) $query->where('supplier_id', (int) $filters['supplier_id']);
        if (!empty($filters['source_id'])) $query->whereKey((int) $filters['source_id']);
        if (!empty($filters['purchase_order_no'])) $query->where('purchase_order_no_snapshot', 'like', '%'.trim((string) $filters['purchase_order_no']).'%');
        if (!empty($filters['source_document_no'])) $query->where('source_document_no', 'like', '%'.trim((string) $filters['source_document_no']).'%');
        if (!empty($filters['business_date_start'])) $query->whereDate('business_date', '>=', $filters['business_date_start']);
        if (!empty($filters['business_date_end'])) $query->whereDate('business_date', '<=', $filters['business_date_end']);
        if (!empty($filters['supplier_keyword'])) {
            $keyword = trim((string) $filters['supplier_keyword']);
            $query->where(function (Builder $nested) use ($keyword): void {
                $nested->where('supplier_name_snapshot', 'like', "%{$keyword}%")
                    ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('supplier_code', 'like', "%{$keyword}%"));
            });
        }
        if (($filters['has_balance'] ?? null) === 'yes') $query->whereRaw('(eligible_amount - ap_offset_amount - allocated_amount) > 0');
        if (($filters['has_balance'] ?? null) === 'no') $query->whereRaw('(eligible_amount - ap_offset_amount - allocated_amount) <= 0');
        if (!empty($filters['payment_status'])) $this->applyPaymentStatus($query, (string) $filters['payment_status']);
        if (!empty($filters['invoice_status'])) $this->applyInvoiceStatus($query, (string) $filters['invoice_status']);

        return $query;
    }

    private function applyPaymentStatus(Builder $query, string $status): void
    {
        match ($status) {
            'unpaid' => $query->where('allocated_amount', '<=', 0)->whereRaw('(eligible_amount - ap_offset_amount) > 0'),
            'partial' => $query->where('allocated_amount', '>', 0)->whereRaw('allocated_amount < (eligible_amount - ap_offset_amount)'),
            'paid' => $query->whereRaw('allocated_amount >= (eligible_amount - ap_offset_amount)')->whereRaw('(eligible_amount - ap_offset_amount) > 0'),
            'frozen' => $query->where('frozen_amount', '>', 0),
            default => null,
        };
    }

    private function applyInvoiceStatus(Builder $query, string $status): void
    {
        match ($status) {
            'unreceived' => $query->where('invoice_matched_amount', '<=', 0)->whereRaw('(eligible_amount - ap_offset_amount) > 0'),
            'partial' => $query->where('invoice_matched_amount', '>', 0)->whereRaw('invoice_matched_amount < (eligible_amount - ap_offset_amount)'),
            'received' => $query->whereRaw('invoice_matched_amount >= (eligible_amount - ap_offset_amount)')->whereRaw('(eligible_amount - ap_offset_amount) > 0'),
            default => null,
        };
    }

    private function sourceSummary(Builder $query): array
    {
        $rows = $query->get(['eligible_amount', 'ap_offset_amount', 'frozen_amount', 'allocated_amount', 'invoice_matched_amount']);
        return $rows->reduce(function (array $carry, PurchaseSettlementSource $source): array {
            $payable = Money::maxZero(Money::sub((string) $source->eligible_amount, (string) $source->ap_offset_amount));
            $carry['current_payable_amount'] = Money::add($carry['current_payable_amount'], $payable);
            $carry['quality_frozen_amount'] = Money::add($carry['quality_frozen_amount'], (string) $source->frozen_amount);
            $carry['paid_amount'] = Money::add($carry['paid_amount'], (string) $source->allocated_amount);
            $carry['unpaid_amount'] = Money::add($carry['unpaid_amount'], Money::maxZero(Money::sub($payable, (string) $source->allocated_amount)));
            $carry['received_invoice_amount'] = Money::add($carry['received_invoice_amount'], (string) $source->invoice_matched_amount);
            $carry['unreceived_invoice_amount'] = Money::add($carry['unreceived_invoice_amount'], Money::maxZero(Money::sub($payable, (string) $source->invoice_matched_amount)));
            return $carry;
        }, [
            'current_payable_amount' => '0.0000', 'quality_frozen_amount' => '0.0000', 'paid_amount' => '0.0000',
            'unpaid_amount' => '0.0000', 'received_invoice_amount' => '0.0000', 'unreceived_invoice_amount' => '0.0000',
        ]);
    }

    private function payablePayload(PurchaseSettlementSource $source, array $extras): array
    {
        $payable = Money::maxZero(Money::sub((string) $source->eligible_amount, (string) $source->ap_offset_amount));
        $paid = Money::normalize((string) $source->allocated_amount);
        $receivedInvoice = Money::normalize((string) $source->invoice_matched_amount);
        return [
            'id' => $source->id,
            'supplier_id' => $source->supplier_id,
            'supplier_code' => $source->supplier?->supplier_code,
            'supplier_name' => $source->supplier_name_snapshot,
            'source_document_no' => $source->source_document_no,
            'purchase_order_no' => $source->purchase_order_no_snapshot,
            'business_date' => $source->business_date?->toDateString(),
            'currency' => $source->currency,
            'current_payable_amount' => $payable,
            'quality_frozen_amount' => Money::normalize((string) $source->frozen_amount),
            'ap_offset_amount' => Money::normalize((string) $source->ap_offset_amount),
            'paid_amount' => $paid,
            'unpaid_amount' => Money::maxZero(Money::sub($payable, $paid)),
            'prepayment_applied_amount' => $extras['prepayment_applied_amount'],
            'pending_refund_amount' => $extras['pending_refund_amount'],
            'red_invoice_amount' => $extras['red_invoice_amount'],
            'received_invoice_amount' => $receivedInvoice,
            'unreceived_invoice_amount' => Money::maxZero(Money::sub($payable, $receivedInvoice)),
            'payment_status' => $this->paymentStatus($payable, $paid, (string) $source->frozen_amount),
            'invoice_status' => $this->invoiceStatus($payable, $receivedInvoice),
            'finance_status' => $this->financeStatus($payable, $paid, $receivedInvoice, (string) $source->frozen_amount, $extras['pending_refund_amount']),
        ];
    }

    /**
     * Per-source supplementary facts are deliberately queried in bulk.  They
     * stay derived from their own immutable facts; the payable source remains
     * the only current balance projection.
     *
     * @param \Illuminate\Support\Collection<int, PurchaseSettlementSource> $sources
     * @return array<int, array{prepayment_applied_amount:string,pending_refund_amount:string,red_invoice_amount:string}>
     */
    private function sourceExtras($sources): array
    {
        $ids = $sources->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) return [];

        $prepayments = FinanceAllocation::query()
            ->join('erp_finance_cash_documents as documents', 'documents.id', '=', 'erp_finance_allocations.cash_document_id')
            ->join('erp_purchase_settlement_sources as sources', 'sources.id', '=', 'erp_finance_allocations.source_document_id')
            ->where('erp_finance_allocations.source_business_type', FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE)
            ->where('erp_finance_allocations.status', FinanceConstants::ALLOCATION_ACTIVE)
            ->where('documents.direction', FinanceConstants::DIRECTION_PAYMENT)
            ->where('documents.status', FinanceConstants::STATUS_CONFIRMED)
            ->where('documents.currency', 'CNY')
            ->whereIn('erp_finance_allocations.source_document_id', $ids)
            ->whereColumn('documents.business_date', '<', 'sources.business_date')
            ->groupBy('erp_finance_allocations.source_document_id')
            ->selectRaw('erp_finance_allocations.source_document_id, SUM(erp_finance_allocations.allocated_amount) AS amount')
            ->pluck('amount', 'erp_finance_allocations.source_document_id');

        $lineIds = $sources->pluck('source_line_id')->map(fn ($id) => (int) $id)->all();
        $refundsByLine = PurchaseReturnItem::query()
            ->whereIn('source_receipt_item_id', $lineIds)
            ->whereHas('purchaseReturn', fn (Builder $query) => $query
                ->where('settlement_effect_type', FinanceConstants::PURCHASE_EFFECT_SUPPLIER_REFUND)
                ->where('return_status', 'completed'))
            ->groupBy('source_receipt_item_id')
            ->selectRaw('source_receipt_item_id, SUM(settlement_amount) AS amount')
            ->pluck('amount', 'source_receipt_item_id');

        $redBySource = FinanceInvoiceAllocation::query()
            ->where('source_business_type', FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE)
            ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
            ->whereIn('source_document_id', $ids)
            ->whereHas('invoice', fn (Builder $query) => $query->where('status', FinanceConstants::STATUS_RED))
            ->groupBy('source_document_id')
            ->selectRaw('source_document_id, SUM(allocated_amount) AS amount')
            ->pluck('amount', 'source_document_id');

        return $sources->mapWithKeys(function (PurchaseSettlementSource $source) use ($prepayments, $refundsByLine, $redBySource): array {
            return [(int) $source->id => [
                'prepayment_applied_amount' => Money::normalize((string) ($prepayments[$source->id] ?? '0')),
                'pending_refund_amount' => Money::normalize((string) ($refundsByLine[$source->source_line_id] ?? '0')),
                'red_invoice_amount' => Money::maxZero(Money::negate((string) ($redBySource[$source->id] ?? '0'))),
            ]];
        })->all();
    }

    private function supplierSupplements(array $supplierIds): array
    {
        if ($supplierIds === []) return [];
        $payment = FinanceCashDocument::query()
            ->selectRaw('party_id, SUM(amount) as confirmed_payment_amount')
            ->where('party_type', FinanceConstants::PARTY_SUPPLIER)
            ->where('direction', FinanceConstants::DIRECTION_PAYMENT)
            ->where('status', FinanceConstants::STATUS_CONFIRMED)
            ->where('currency', 'CNY')->whereIn('party_id', $supplierIds)->groupBy('party_id')->pluck('confirmed_payment_amount', 'party_id');
        $refundDue = PurchaseReturn::query()
            ->selectRaw('supplier_id, SUM(settlement_amount) as refund_due_amount')
            ->where('settlement_effect_type', FinanceConstants::PURCHASE_EFFECT_SUPPLIER_REFUND)
            ->whereNotIn('return_status', ['draft', 'submitted', 'cancelled'])
            ->whereIn('supplier_id', $supplierIds)->groupBy('supplier_id')->pluck('refund_due_amount', 'supplier_id');
        $refundReceived = FinanceAllocation::query()
            ->selectRaw('party_id, SUM(allocated_amount) as refund_received_amount')
            ->where('source_business_type', FinanceConstants::SOURCE_PURCHASE_RETURN_SUPPLIER_REFUND)
            ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
            ->whereIn('party_id', $supplierIds)
            ->whereHas('cashDocument', fn (Builder $query) => $query->where('direction', FinanceConstants::DIRECTION_RECEIPT)->where('status', FinanceConstants::STATUS_CONFIRMED)->where('currency', 'CNY'))
            ->groupBy('party_id')->pluck('refund_received_amount', 'party_id');

        $supplierCodes = PurchaseSettlementSource::query()->with('supplier:id,supplier_code')->whereIn('supplier_id', $supplierIds)->get()->groupBy('supplier_id')
            ->map(fn ($rows) => $rows->first()->supplier?->supplier_code);
        $redInvoices = FinanceInvoice::query()
            ->where('invoice_direction', FinanceConstants::INVOICE_PURCHASE)
            ->where('party_type', FinanceConstants::PARTY_SUPPLIER)
            ->where('currency', 'CNY')
            ->where('status', FinanceConstants::STATUS_RED)
            ->whereIn('party_id', $supplierIds)
            ->groupBy('party_id')
            ->selectRaw('party_id, SUM(amount_incl_tax) AS amount')
            ->pluck('amount', 'party_id');
        return collect($supplierIds)->mapWithKeys(function (int $supplierId) use ($payment, $refundDue, $refundReceived, $supplierCodes, $redInvoices): array {
            return [$supplierId => [
                'supplier_code' => $supplierCodes->get($supplierId),
                'confirmed_payment_amount' => Money::normalize((string) ($payment[$supplierId] ?? '0')),
                'pending_refund_amount' => Money::maxZero(Money::sub((string) ($refundDue[$supplierId] ?? '0'), (string) ($refundReceived[$supplierId] ?? '0'))),
                'red_invoice_amount' => Money::normalize((string) ($redInvoices[$supplierId] ?? '0')),
            ]];
        })->all();
    }

    private function paymentStatus(string $payable, string $paid, string $frozen): string
    {
        if (Money::compare($frozen, '0') > 0 && Money::compare($payable, '0') === 0) return 'frozen';
        if (Money::compare($payable, '0') <= 0) return 'settled';
        if (Money::compare($paid, '0') <= 0) return 'unpaid';
        return Money::compare($paid, $payable) >= 0 ? 'paid' : 'partial';
    }

    private function invoiceStatus(string $payable, string $received): string
    {
        if (Money::compare($payable, '0') <= 0) return 'not_required';
        if (Money::compare($received, '0') <= 0) return 'unreceived';
        return Money::compare($received, $payable) >= 0 ? 'received' : 'partial';
    }

    private function financeStatus(string $payable, string $paid, string $received, string $frozen, string $pendingRefund): string
    {
        if (Money::compare($frozen, '0') > 0) return 'quality_frozen';
        if (Money::compare($pendingRefund, '0') > 0) return 'pending_refund';
        if (Money::compare($payable, $paid) <= 0 && Money::compare($payable, $received) <= 0) return 'settled';
        return 'unclosed';
    }
}
