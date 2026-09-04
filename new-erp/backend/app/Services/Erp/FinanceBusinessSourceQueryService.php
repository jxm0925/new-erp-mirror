<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\PurchaseSettlementSource;
use App\Models\Erp\SalesOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class FinanceBusinessSourceQueryService
{
    public function paginate(string $type, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = match ($type) {
            FinanceConstants::SOURCE_SALES_ORDER,
            FinanceConstants::SOURCE_SALES_ORDER_REFUND => SalesOrder::query()->whereNotIn('order_status', ['draft', 'cancelled']),
            FinanceConstants::SOURCE_PURCHASE_RECEIPT => PurchaseReceipt::query()->with('supplier')->where('confirm_status', 'confirmed')->where('settlement_mode', '<>', 'replacement_no_charge'),
            FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE => PurchaseSettlementSource::query()
                ->whereIn('status', ['open', 'partially_paid']),
            FinanceConstants::SOURCE_PURCHASE_RETURN_SUPPLIER_REFUND => PurchaseReturn::query()
                ->with('supplier')
                ->where('settlement_effect_type', FinanceConstants::PURCHASE_EFFECT_SUPPLIER_REFUND)
                ->whereNotIn('return_status', ['draft', 'submitted', 'cancelled']),
            default => throw ValidationException::withMessages(['type' => '该业务来源不支持列表选择。']),
        };
        $this->applyParty($query, $type, $filters);
        $this->applyKeyword($query, $type, trim((string) ($filters['keyword'] ?? '')));
        return $query->latest('id')->paginate($perPage)->through(fn ($row) => $this->payload($type, $row));
    }

    private function applyParty(Builder $query, string $type, array $filters): void
    {
        if (empty($filters['party_id'])) return;
        $field = in_array($type, [FinanceConstants::SOURCE_SALES_ORDER, FinanceConstants::SOURCE_SALES_ORDER_REFUND], true) ? 'customer_id' : 'supplier_id';
        $query->where($field, (int) $filters['party_id']);
    }

    private function applyKeyword(Builder $query, string $type, string $keyword): void
    {
        if ($keyword === '') return;
        $query->where(function (Builder $q) use ($type, $keyword): void {
            if (in_array($type, [FinanceConstants::SOURCE_SALES_ORDER, FinanceConstants::SOURCE_SALES_ORDER_REFUND], true)) {
                $q->where('sales_order_no', 'like', "%{$keyword}%")->orWhere('customer_name_snapshot', 'like', "%{$keyword}%");
            } elseif ($type === FinanceConstants::SOURCE_PURCHASE_RECEIPT) {
                $q->where('receipt_no', 'like', "%{$keyword}%");
            } elseif ($type === FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE) {
                $q->where('source_document_no', 'like', "%{$keyword}%")
                    ->orWhere('purchase_order_no_snapshot', 'like', "%{$keyword}%")
                    ->orWhere('supplier_name_snapshot', 'like', "%{$keyword}%");
            } else {
                $q->where('return_no', 'like', "%{$keyword}%");
            }
        });
    }

    private function payload(string $type, object $row): array
    {
        $isSales = in_array($type, [FinanceConstants::SOURCE_SALES_ORDER, FinanceConstants::SOURCE_SALES_ORDER_REFUND], true);
        if ($type === FinanceConstants::SOURCE_SALES_ORDER_REFUND) {
            $amount = $this->allocated(FinanceConstants::SOURCE_SALES_ORDER, (int) $row->id);
        } elseif ($type === FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE) {
            $amount = Money::maxZero(Money::sub((string) $row->eligible_amount, (string) $row->ap_offset_amount));
        } elseif ($type === FinanceConstants::SOURCE_PURCHASE_RECEIPT) {
            $offset = Money::normalize((string) PurchaseReturn::query()->where('source_receipt_id', $row->id)
                ->where('settlement_effect_type', FinanceConstants::PURCHASE_EFFECT_AP_OFFSET)
                ->whereNotIn('return_status', ['draft', 'submitted', 'cancelled'])->sum('settlement_amount'));
            $amount = Money::maxZero(Money::sub((string) $row->settlement_amount, $offset));
        } elseif ($type === FinanceConstants::SOURCE_PURCHASE_RETURN_SUPPLIER_REFUND) {
            $amount = Money::normalize((string) $row->settlement_amount);
        } else {
            $amount = Money::normalize((string) $row->total_amount);
        }
        $allocated = $this->allocated($type, (int) $row->id);
        return [
            'type' => $type, 'id' => (int) $row->id,
            'no' => (string) ($isSales ? $row->sales_order_no : ($type === FinanceConstants::SOURCE_PURCHASE_RECEIPT ? $row->receipt_no : ($type === FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE ? $row->source_document_no : $row->return_no))),
            'partyType' => $isSales ? FinanceConstants::PARTY_CUSTOMER : FinanceConstants::PARTY_SUPPLIER,
            'partyId' => (int) ($isSales ? $row->customer_id : $row->supplier_id),
            'partyName' => (string) ($isSales ? ($row->customer_name_snapshot ?: $row->customer_name) : ($type === FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE ? $row->supplier_name_snapshot : ($row->supplier?->supplier_name ?? ''))),
            'currency' => (string) (($row->currency ?? $row->currency_snapshot) ?: 'CNY'),
            'amount' => $amount, 'allocatedAmount' => $allocated,
            'remainingAmount' => Money::maxZero(Money::sub($amount, $allocated)),
        ];
    }

    private function allocated(string $type, int $id): string
    {
        return Money::normalize((string) FinanceAllocation::query()->where('source_business_type', $type)->where('source_document_id', $id)
            ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
            ->whereHas('cashDocument', fn ($q) => $q->where('status', FinanceConstants::STATUS_CONFIRMED))->sum('allocated_amount'));
    }
}
