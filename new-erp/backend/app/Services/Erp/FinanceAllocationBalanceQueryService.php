<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FinanceAllocationBalanceQueryService
{
    public function forDocument(int $documentId): string
    {
        return $this->total(FinanceAllocation::query()
            ->where('cash_document_id', $documentId)
            ->where('erp_finance_allocations.status', FinanceConstants::ALLOCATION_ACTIVE));
    }

    public function forSource(string $type, int $id, ?string $direction = null): string
    {
        $query = FinanceAllocation::query()
            ->join('erp_finance_cash_documents as allocation_cash', 'allocation_cash.id', '=', 'erp_finance_allocations.cash_document_id')
            ->where('source_business_type', $type)->where('source_document_id', $id)
            ->where('erp_finance_allocations.status', FinanceConstants::ALLOCATION_ACTIVE)
            ->where('allocation_cash.status', FinanceConstants::STATUS_CONFIRMED);
        if ($direction !== null) $query->where('allocation_cash.direction', $direction);
        return $this->total($query);
    }

    private function total(Builder $query): string
    {
        // Locking a source does not advance an earlier REPEATABLE READ snapshot.
        // Read the actual allocation rows (and cash status through the same JOIN)
        // using a current read, then sum exact decimals. SUM + a snapshot EXISTS
        // subquery can otherwise miss money committed while waiting for the source.
        if (DB::transactionLevel() > 0) $query->lockForUpdate();
        return $query->orderBy('erp_finance_allocations.id')
            ->get(['erp_finance_allocations.allocated_amount'])
            ->reduce(fn (string $sum, FinanceAllocation $row): string => Money::add($sum, (string) $row->allocated_amount), '0.0000');
    }
}
