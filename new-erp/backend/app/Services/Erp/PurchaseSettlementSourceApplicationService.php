<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\FinanceInvoiceAllocation;
use App\Models\Erp\FinanceOperationLog;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReturnItem;
use App\Models\Erp\PurchaseSettlementSource;

/**
 * 采购结算来源是已冻结采购事实的财务索引，不替代到货、退货或库存事实。
 */
class PurchaseSettlementSourceApplicationService
{
    public const SOURCE_TYPE_RECEIPT_QUALIFIED = 'purchase_receipt_qualified';
    public const FINANCE_SOURCE_TYPE = 'purchase_settlement_source';

    public function syncReceipt(int|PurchaseReceipt $receipt, ?int $operatorId = null, ?string $operatorName = null): array
    {
        $receiptId = $receipt instanceof PurchaseReceipt ? $receipt->id : $receipt;
        $receipt = PurchaseReceipt::query()
            ->with(['supplier', 'order', 'items'])
            ->lockForUpdate()
            ->findOrFail($receiptId);

        if ($receipt->confirm_status !== 'confirmed' || $receipt->settlement_mode === 'replacement_no_charge') {
            return [];
        }

        $offsets = $this->offsetsByReceiptLine($receipt->id);
        $sources = [];
        foreach ($receipt->items as $line) {
            $sources[] = $this->syncLine($receipt, $line, $offsets[(int) $line->id] ?? '0.0000', $operatorId, $operatorName);
        }

        return $sources;
    }

    public function refresh(int $sourceId, ?int $operatorId = null, ?string $operatorName = null): PurchaseSettlementSource
    {
        // Keep the lock order consistent with every purchasing fact update:
        // receipt first, then settlement source. Locking the source before its
        // receipt would allow allocation reversal and receipt-quality updates
        // to deadlock under concurrency.
        $source = PurchaseSettlementSource::query()->findOrFail($sourceId);
        $this->syncReceipt((int) $source->source_receipt_id, $operatorId, $operatorName);

        return PurchaseSettlementSource::query()->lockForUpdate()->findOrFail($sourceId);
    }

    private function syncLine(PurchaseReceipt $receipt, object $line, string $apOffset, ?int $operatorId, ?string $operatorName): PurchaseSettlementSource
    {
        $source = PurchaseSettlementSource::query()
            ->where('source_type', self::SOURCE_TYPE_RECEIPT_QUALIFIED)
            ->where('source_document_type', 'purchase_receipt')
            ->where('source_document_id', $receipt->id)
            ->where('source_line_id', $line->id)
            ->where('source_version', 1)
            ->lockForUpdate()
            ->first();

        $created = ! $source;
        $source ??= new PurchaseSettlementSource([
            'source_type' => self::SOURCE_TYPE_RECEIPT_QUALIFIED,
            'source_document_type' => 'purchase_receipt',
            'source_document_id' => $receipt->id,
            'source_document_no' => $receipt->receipt_no,
            'source_receipt_id' => $receipt->id,
            'source_line_id' => $line->id,
            'source_version' => 1,
        ]);

        $original = Money::normalize((string) ($line->amount_incl_tax ?? '0'));
        $eligible = Money::normalize((string) ($line->settlement_amount ?? '0'));
        $frozen = Money::normalize((string) ($line->quality_hold_amount ?? '0'));
        $allocated = $this->activePaymentAllocated((int) ($source->id ?? 0));
        $invoiceMatched = $this->activeInvoiceMatched((int) ($source->id ?? 0));
        $payableAfterOffset = Money::maxZero(Money::sub($eligible, $apOffset));
        $unallocated = Money::maxZero(Money::sub($payableAfterOffset, $allocated));
        $invoiceUnmatched = Money::maxZero(Money::sub($payableAfterOffset, $invoiceMatched));
        $status = $this->status($eligible, $frozen, $apOffset, $allocated, $unallocated);

        $source->fill([
            'purchase_order_id' => $receipt->order_id,
            'purchase_order_no_snapshot' => $receipt->order?->purchase_order_no,
            'supplier_id' => $receipt->supplier_id,
            'supplier_name_snapshot' => $receipt->supplier?->supplier_name ?: '供应商',
            'currency' => $receipt->currency_snapshot ?: 'CNY',
            'business_date' => $receipt->receipt_date ?: now()->toDateString(),
            'original_amount' => $original,
            'eligible_amount' => $eligible,
            'frozen_amount' => $frozen,
            'ap_offset_amount' => $apOffset,
            'allocated_amount' => $allocated,
            'unallocated_amount' => $unallocated,
            'invoice_matched_amount' => $invoiceMatched,
            'invoice_unmatched_amount' => $invoiceUnmatched,
            'status' => $status,
            'eligible_at' => Money::compare($eligible, '0') > 0 ? ($source->eligible_at ?: now()) : null,
            'frozen_at' => Money::compare($frozen, '0') > 0 ? ($source->frozen_at ?: now()) : null,
            'closed_at' => in_array($status, ['paid', 'offset', 'closed'], true) ? ($source->closed_at ?: now()) : null,
        ]);

        $beforeStatus = $source->getOriginal('status');
        $changed = $created || $source->isDirty();
        $source->save();

        if ($changed) {
            FinanceOperationLog::create([
                'document_type' => 'purchase_settlement_source',
                'document_id' => $source->id,
                'action' => $created ? 'create_source' : 'sync_source',
                'from_status' => $created ? null : $beforeStatus,
                'to_status' => $source->status,
                'fact_snapshot' => [
                    'receipt_no' => $receipt->receipt_no,
                    'source_line_id' => $line->id,
                    'original_amount' => $original,
                    'eligible_amount' => $eligible,
                    'frozen_amount' => $frozen,
                    'ap_offset_amount' => $apOffset,
                    'allocated_amount' => $allocated,
                    'invoice_matched_amount' => $invoiceMatched,
                ],
                'operator_id' => $operatorId,
                'operator_name' => $operatorName ?: '系统',
                'content' => $created ? '由已确认采购到货事实生成结算来源。' : '根据采购结算/质量/退货事实刷新结算来源。',
            ]);
        }

        return $source->fresh();
    }

    private function offsetsByReceiptLine(int $receiptId): array
    {
        return PurchaseReturnItem::query()
            ->selectRaw('source_receipt_item_id, SUM(settlement_amount) AS amount')
            ->whereHas('purchaseReturn', fn ($query) => $query
                ->where('source_receipt_id', $receiptId)
                ->where('settlement_effect_type', FinanceConstants::PURCHASE_EFFECT_AP_OFFSET)
                // A draft, an outbound return, or a supplier-confirmation
                // pending return is not yet a payable offset. The original
                // receipt fact remains unchanged until the return is complete.
                ->where('return_status', 'completed'))
            ->groupBy('source_receipt_item_id')
            ->pluck('amount', 'source_receipt_item_id')
            ->map(fn ($amount) => Money::normalize((string) $amount))
            ->all();
    }

    private function activePaymentAllocated(int $sourceId): string
    {
        if ($sourceId <= 0) return '0.0000';
        return Money::normalize((string) FinanceAllocation::query()
            ->where('source_business_type', self::FINANCE_SOURCE_TYPE)
            ->where('source_document_id', $sourceId)
            ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
            ->whereHas('cashDocument', fn ($query) => $query
                ->where('direction', FinanceConstants::DIRECTION_PAYMENT)
                ->where('status', FinanceConstants::STATUS_CONFIRMED))
            ->sum('allocated_amount'));
    }

    private function activeInvoiceMatched(int $sourceId): string
    {
        if ($sourceId <= 0) return '0.0000';
        return Money::normalize((string) FinanceInvoiceAllocation::query()
            ->where('source_business_type', self::FINANCE_SOURCE_TYPE)
            ->where('source_document_id', $sourceId)
            ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
            // Editable draft matches are not received-invoice facts. Only a
            // confirmed invoice or a red-invoice fact changes official source coverage.
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', [FinanceConstants::STATUS_CONFIRMED, FinanceConstants::STATUS_RED]))
            ->sum('allocated_amount'));
    }

    private function status(string $eligible, string $frozen, string $offset, string $allocated, string $unallocated): string
    {
        if (Money::compare($eligible, '0') <= 0) return Money::compare($frozen, '0') > 0 ? 'frozen' : 'pending';
        if (Money::compare($unallocated, '0') > 0) return Money::compare($allocated, '0') > 0 ? 'partially_paid' : 'open';
        if (Money::compare($offset, $eligible) >= 0) return 'offset';
        return Money::compare($allocated, '0') > 0 ? 'paid' : 'closed';
    }
}
