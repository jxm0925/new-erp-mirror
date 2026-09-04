<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceInvoice;
use App\Models\Erp\FinanceInvoiceAllocation;
use App\Models\Erp\FinanceOperationLog;
use App\Models\Erp\PurchaseSettlementSource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model for the invoice register. Invoice matching is deliberately
 * separate from payment allocation: the former proves tax-document coverage,
 * the latter proves cash settlement.
 */
class FinanceInvoiceQueryService
{
    public function paginate(array $filters, int $perPage): array
    {
        $query = $this->baseQuery($filters);
        $summary = $this->summary(clone $query);
        $page = $query->latest('invoice_date')->latest('id')->paginate($perPage);
        $matched = $this->matchedTotals($page->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all());

        return [
            ...$page->toArray(),
            'data' => $page->getCollection()->map(fn (FinanceInvoice $invoice) => $this->payload($invoice, $matched[(int) $invoice->id] ?? '0.0000'))->values(),
            'summary' => $summary,
        ];
    }

    /**
     * Paginated purchase-settlement facts available to a single input invoice.
     * This is deliberately not the payment-source query: invoice coverage and
     * cash settlement have independent remaining balances.
     */
    public function matchingSources(int $invoiceId, int $supplierId, array $filters, int $perPage): array
    {
        $currentInvoiceMatches = FinanceInvoiceAllocation::query()
            ->where('invoice_id', $invoiceId)
            ->where('source_business_type', FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE)
            ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
            ->selectRaw('source_document_id, SUM(allocated_amount) as total')
            ->groupBy('source_document_id')->pluck('total', 'source_document_id')
            ->map(fn ($amount) => Money::normalize((string) $amount))->all();

        // `invoice_unmatched_amount` on a purchase settlement source is a
        // denormalized read field. It is useful for summaries, but must not
        // decide whether an invoice can be matched: a delayed source refresh
        // would otherwise make a valid source disappear from this page.
        // Candidate eligibility is therefore derived from the immutable
        // procurement fact plus invoice-allocation facts in this query.
        $officialInvoiceMatchQuery = FinanceInvoiceAllocation::query()
            ->join('erp_finance_invoices as matched_invoice', 'matched_invoice.id', '=', 'erp_finance_invoice_allocations.invoice_id')
            ->where('erp_finance_invoice_allocations.source_business_type', FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE)
            ->where('erp_finance_invoice_allocations.status', FinanceConstants::ALLOCATION_ACTIVE)
            ->whereIn('matched_invoice.status', [FinanceConstants::STATUS_CONFIRMED, FinanceConstants::STATUS_RED])
            ->selectRaw('erp_finance_invoice_allocations.source_document_id, SUM(erp_finance_invoice_allocations.allocated_amount) as total')
            ->groupBy('erp_finance_invoice_allocations.source_document_id');
        $officialInvoiceMatches = (clone $officialInvoiceMatchQuery)
            ->pluck('total', 'source_document_id')
            ->map(fn ($amount) => Money::normalize((string) $amount))->all();

        $draftReservationQuery = FinanceInvoiceAllocation::query()
            ->join('erp_finance_invoices as reservation_invoice', 'reservation_invoice.id', '=', 'erp_finance_invoice_allocations.invoice_id')
            ->where('erp_finance_invoice_allocations.source_business_type', FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE)
            ->where('erp_finance_invoice_allocations.status', FinanceConstants::ALLOCATION_ACTIVE)
            ->where('reservation_invoice.status', FinanceConstants::STATUS_DRAFT)
            ->selectRaw('erp_finance_invoice_allocations.source_document_id, SUM(erp_finance_invoice_allocations.allocated_amount) as total')
            ->groupBy('erp_finance_invoice_allocations.source_document_id');
        $draftReservations = (clone $draftReservationQuery)
            ->pluck('total', 'source_document_id')
            ->map(fn ($amount) => Money::maxZero(Money::normalize((string) $amount)))->all();

        $query = PurchaseSettlementSource::query()
            ->from('erp_purchase_settlement_sources as settlement_source')
            ->with('receipt:id,receipt_no')
            ->leftJoinSub($officialInvoiceMatchQuery, 'official_invoice_matches', fn ($join) => $join
                ->on('official_invoice_matches.source_document_id', '=', 'settlement_source.id'))
            ->leftJoinSub($draftReservationQuery, 'draft_invoice_reservations', fn ($join) => $join
                ->on('draft_invoice_reservations.source_document_id', '=', 'settlement_source.id'))
            ->where('settlement_source.supplier_id', $supplierId)
            ->where('settlement_source.currency', 'CNY')
            ->whereRaw('(settlement_source.eligible_amount - settlement_source.ap_offset_amount - COALESCE(official_invoice_matches.total, 0) - COALESCE(draft_invoice_reservations.total, 0)) > 0.0000')
            ->when($filters['settlement_source_no'] ?? null, fn (Builder $q, string $value) => $q->where('settlement_source.source_document_no', 'like', '%'.trim($value).'%'))
            ->when($filters['purchase_order_no'] ?? null, fn (Builder $q, string $value) => $q->where('settlement_source.purchase_order_no_snapshot', 'like', '%'.trim($value).'%'))
            ->when($filters['business_date_start'] ?? null, fn (Builder $q, string $value) => $q->whereDate('settlement_source.business_date', '>=', $value))
            ->when($filters['business_date_end'] ?? null, fn (Builder $q, string $value) => $q->whereDate('settlement_source.business_date', '<=', $value))
            ->select('settlement_source.*');

        $summary = (clone $query)->get(['id', 'eligible_amount', 'ap_offset_amount', 'invoice_matched_amount', 'invoice_unmatched_amount'])
            ->reduce(function (array $carry, PurchaseSettlementSource $source) use ($officialInvoiceMatches, $draftReservations): array {
                $payable = Money::maxZero(Money::sub((string) $source->eligible_amount, (string) $source->ap_offset_amount));
                $officialMatched = $officialInvoiceMatches[(int) $source->id] ?? '0.0000';
                $reservedByDrafts = $draftReservations[(int) $source->id] ?? '0.0000';
                $available = Money::maxZero(Money::sub(Money::sub($payable, $officialMatched), $reservedByDrafts));
                $carry['payable_amount'] = Money::add($carry['payable_amount'], $payable);
                $carry['invoiced_amount'] = Money::add($carry['invoiced_amount'], $officialMatched);
                $carry['available_amount'] = Money::add($carry['available_amount'], $available);
                return $carry;
            }, ['payable_amount' => '0.0000', 'invoiced_amount' => '0.0000', 'available_amount' => '0.0000']);
        $page = $query->latest('business_date')->latest('id')->paginate($perPage);

        return [
            ...$page->toArray(),
            'data' => $page->getCollection()->map(function (PurchaseSettlementSource $source) use ($currentInvoiceMatches, $officialInvoiceMatches, $draftReservations): array {
                $current = $currentInvoiceMatches[(int) $source->id] ?? '0.0000';
                $payable = Money::maxZero(Money::sub((string) $source->eligible_amount, (string) $source->ap_offset_amount));
                $officialMatched = $officialInvoiceMatches[(int) $source->id] ?? '0.0000';
                $reservedByDrafts = $draftReservations[(int) $source->id] ?? '0.0000';
                $available = Money::maxZero(Money::sub(Money::sub($payable, $officialMatched), $reservedByDrafts));
                return [
                    'id' => $source->id,
                    'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
                    'settlement_source_no' => $source->source_document_no,
                    'purchase_order_no' => $source->purchase_order_no_snapshot,
                    'receipt_no' => $source->receipt?->receipt_no,
                    'business_date' => $source->business_date?->toDateString(),
                    'current_payable_amount' => $payable,
                    'invoiced_amount' => $officialMatched,
                    'current_invoice_matched_amount' => $current,
                    'available_amount' => $available,
                    'reserved_by_draft_amount' => $reservedByDrafts,
                ];
            })->values(),
            'summary' => $summary,
        ];
    }

    /**
     * Detail screen read model. History is deliberately paginated so a long
     * lived invoice cannot cause the browser to fetch every allocation/log.
     */
    public function detail(int $invoiceId, int $matchPerPage, int $matchPage, int $logPerPage, int $logPage): array
    {
        $invoice = FinanceInvoice::query()->findOrFail($invoiceId);
        $matched = $this->matchedTotals([$invoice->id])[$invoice->id] ?? '0.0000';
        $matches = FinanceInvoiceAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->latest('id')
            ->paginate($matchPerPage, ['*'], 'match_page', $matchPage);

        $sourceIds = $matches->getCollection()
            ->where('source_business_type', FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE)
            ->pluck('source_document_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $sources = PurchaseSettlementSource::query()->with('receipt:id,receipt_no')
            ->whereIn('id', $sourceIds)->get()->keyBy('id');

        $logs = FinanceOperationLog::query()
            ->where('document_type', 'finance_invoice')
            ->where('document_id', $invoice->id)
            ->latest('id')
            ->paginate($logPerPage, ['*'], 'log_page', $logPage);

        return [
            'invoice' => $this->payload($invoice, $matched),
            'attachments' => $invoice->attachments()->where('status', 'active')->latest('id')->get()->map(fn ($attachment) => [
                'id' => $attachment->id,
                'attachment_type' => $attachment->attachment_type,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'file_size' => (int) $attachment->file_size,
                'uploaded_at' => $attachment->uploaded_at?->format('Y-m-d H:i:s'),
            ])->values(),
            'match_history' => [
                ...$matches->toArray(),
                'data' => $matches->getCollection()->map(function (FinanceInvoiceAllocation $allocation) use ($sources): array {
                    $source = $sources->get((int) $allocation->source_document_id);
                    return [
                        'id' => $allocation->id,
                        'source_business_type' => $allocation->source_business_type,
                        'source_business_type_label' => $allocation->source_business_type === FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE ? '采购结算单' : $allocation->source_business_type,
                        'source_document_no' => $allocation->source_document_no,
                        'purchase_order_no' => $source?->purchase_order_no_snapshot,
                        'receipt_no' => $source?->receipt?->receipt_no,
                        'business_date' => $source?->business_date?->toDateString(),
                        'source_amount_snapshot' => Money::normalize((string) $allocation->source_amount_snapshot),
                        'allocated_amount' => Money::normalize((string) $allocation->allocated_amount),
                        'tax_amount' => null,
                        'status' => $allocation->status,
                        'created_at' => $allocation->created_at?->format('Y-m-d H:i:s'),
                    ];
                })->values(),
            ],
            'operation_logs' => [
                ...$logs->toArray(),
                'data' => $logs->getCollection()->map(fn (FinanceOperationLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'operator_name' => $log->operator_name ?: '系统',
                    'content' => $log->content,
                    'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                ])->values(),
            ],
        ];
    }

    /** Read-only source of truth for the red-invoice page. */
    public function redPreview(int $invoiceId): array
    {
        $invoice = FinanceInvoice::query()->findOrFail($invoiceId);
        if ($invoice->invoice_direction !== FinanceConstants::INVOICE_PURCHASE
            || $invoice->currency !== 'CNY'
            || $invoice->status !== FinanceConstants::STATUS_CONFIRMED
            || $invoice->red_invoice_of_id !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['invoice_id' => '仅已确认的 CNY 采购蓝票可开具红字发票。']);
        }
        $redTotal = Money::normalize((string) FinanceInvoice::query()
            ->where('red_invoice_of_id', $invoice->id)->where('status', FinanceConstants::STATUS_RED)
            ->sum('amount_incl_tax'));
        $redExcl = Money::normalize((string) FinanceInvoice::query()
            ->where('red_invoice_of_id', $invoice->id)->where('status', FinanceConstants::STATUS_RED)
            ->sum('amount_excl_tax'));
        $redTax = Money::normalize((string) FinanceInvoice::query()
            ->where('red_invoice_of_id', $invoice->id)->where('status', FinanceConstants::STATUS_RED)
            ->sum('tax_amount'));

        return [
            'invoice' => $this->payload($invoice, $this->matchedTotals([$invoice->id])[$invoice->id] ?? '0.0000'),
            'red_total_amount' => $redTotal,
            'red_remaining_amount' => Money::maxZero(Money::sub((string) $invoice->amount_incl_tax, $redTotal)),
            'red_remaining_excl_tax' => Money::maxZero(Money::sub((string) $invoice->amount_excl_tax, $redExcl)),
            'red_remaining_tax_amount' => Money::maxZero(Money::sub((string) $invoice->tax_amount, $redTax)),
        ];
    }

    private function baseQuery(array $filters): Builder
    {
        $query = FinanceInvoice::query()
            ->where('currency', 'CNY')
            ->when($filters['invoice_direction'] ?? null, fn (Builder $q, string $value) => $q->where('invoice_direction', $value))
            ->when($filters['supplier_keyword'] ?? null, function (Builder $q, string $value): void {
                $q->where('party_name_snapshot', 'like', '%'.trim($value).'%');
            })
            ->when($filters['invoice_no'] ?? null, fn (Builder $q, string $value) => $q->where('invoice_no', 'like', '%'.trim($value).'%'))
            ->when($filters['invoice_code'] ?? null, fn (Builder $q, string $value) => $q->where('invoice_code', 'like', '%'.trim($value).'%'))
            ->when($filters['status'] ?? null, fn (Builder $q, string $value) => $q->where('status', $value))
            ->when($filters['invoice_date_start'] ?? null, fn (Builder $q, string $value) => $q->whereDate('invoice_date', '>=', $value))
            ->when($filters['invoice_date_end'] ?? null, fn (Builder $q, string $value) => $q->whereDate('invoice_date', '<=', $value))
            ->when($filters['received_date_start'] ?? null, fn (Builder $q, string $value) => $q->whereDate('received_date', '>=', $value))
            ->when($filters['received_date_end'] ?? null, fn (Builder $q, string $value) => $q->whereDate('received_date', '<=', $value));

        if (($filters['match_status'] ?? null) === 'unmatched') {
            $query->where('status', '!=', FinanceConstants::STATUS_RED);
            $query->whereRaw('(amount_incl_tax - (select COALESCE(sum(allocated_amount), 0) from erp_finance_invoice_allocations where invoice_id = erp_finance_invoices.id and status = ?)) > 0', [FinanceConstants::ALLOCATION_ACTIVE]);
        }
        if (($filters['match_status'] ?? null) === 'matched') {
            $query->where('status', '!=', FinanceConstants::STATUS_RED);
            $query->whereRaw('(amount_incl_tax - (select COALESCE(sum(allocated_amount), 0) from erp_finance_invoice_allocations where invoice_id = erp_finance_invoices.id and status = ?)) <= 0', [FinanceConstants::ALLOCATION_ACTIVE]);
        }
        if (($filters['match_status'] ?? null) === 'partial') {
            $query->where('status', '!=', FinanceConstants::STATUS_RED);
            $query->whereRaw('(select COALESCE(sum(allocated_amount), 0) from erp_finance_invoice_allocations where invoice_id = erp_finance_invoices.id and status = ?) > 0', [FinanceConstants::ALLOCATION_ACTIVE]);
            $query->whereRaw('(amount_incl_tax - (select COALESCE(sum(allocated_amount), 0) from erp_finance_invoice_allocations where invoice_id = erp_finance_invoices.id and status = ?)) > 0', [FinanceConstants::ALLOCATION_ACTIVE]);
        }

        return $query;
    }

    private function summary(Builder $query): array
    {
        $rows = $query->get(['id', 'amount_incl_tax', 'status']);
        $ids = $rows->pluck('id')->all();
        $matched = $this->matchedTotals($ids);
        $total = '0.0000'; $confirmed = '0.0000'; $unmatched = '0.0000'; $pendingRed = '0.0000';
        foreach ($rows as $invoice) {
            $amount = Money::normalize((string) $invoice->amount_incl_tax);
            $isRed = $invoice->status === FinanceConstants::STATUS_RED;
            $signedAmount = $isRed ? Money::negate($amount) : $amount;
            $total = Money::add($total, $signedAmount);
            if ($invoice->status === FinanceConstants::STATUS_CONFIRMED) $confirmed = Money::add($confirmed, $amount);
            // A confirmed red invoice is an immutable reversal fact, never a
            // new document waiting for source matching. Its negative match is
            // intentionally excluded from the ordinary "待匹配" bucket.
            if (! $isRed && Money::compare(Money::sub($amount, $matched[(int) $invoice->id] ?? '0.0000'), '0') > 0) {
                $unmatched = Money::add($unmatched, $amount);
            }
            if ($invoice->status === 'pending_red') $pendingRed = Money::add($pendingRed, $amount);
        }
        return ['invoice_total_amount' => $total, 'confirmed_amount' => $confirmed, 'unmatched_amount' => $unmatched, 'pending_red_amount' => $pendingRed];
    }

    private function payload(FinanceInvoice $invoice, string $matched): array
    {
        $amount = Money::normalize((string) $invoice->amount_incl_tax);
        $isRed = $invoice->status === FinanceConstants::STATUS_RED;
        $unmatched = $isRed ? '0.0000' : Money::maxZero(Money::sub($amount, $matched));
        return [
            ...$invoice->toArray(),
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'received_date' => $invoice->received_date?->toDateString(),
            'matched_amount' => $matched,
            'unmatched_amount' => $unmatched,
            'match_status' => $isRed ? 'red' : (Money::compare($matched, '0') <= 0 ? 'unmatched' : (Money::compare($unmatched, '0') <= 0 ? 'matched' : 'partial')),
        ];
    }

    /** @return array<int, string> */
    private function matchedTotals(array $invoiceIds): array
    {
        if ($invoiceIds === []) return [];
        return FinanceInvoiceAllocation::query()->whereIn('invoice_id', $invoiceIds)
            ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
            ->selectRaw('invoice_id, SUM(allocated_amount) as total')
            ->groupBy('invoice_id')->pluck('total', 'invoice_id')
            ->map(fn ($amount) => Money::normalize((string) $amount))->all();
    }
}
