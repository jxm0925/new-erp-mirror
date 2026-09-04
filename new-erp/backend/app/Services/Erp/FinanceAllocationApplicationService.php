<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\FinanceOperationLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceAllocationApplicationService
{
    public function __construct(
        private readonly FinanceBusinessSourceResolver $sources,
        private readonly PurchaseSettlementSourceApplicationService $purchaseSettlementSources,
    ) {}

    public function allocate(int $cashDocumentId, array $rows, ?int $operatorId, ?string $operatorName): array
    {
        try {
            return DB::transaction(function () use ($cashDocumentId, $rows, $operatorId, $operatorName): array {
                $document = FinanceCashDocument::query()->lockForUpdate()->findOrFail($cashDocumentId);
                if ($document->status !== FinanceConstants::STATUS_CONFIRMED) throw ValidationException::withMessages(['status' => '只有已确认资金单可以核销。']);
                $allocatedBefore = $this->activeTotalForDocument($document->id);
                $remainingFunds = Money::sub((string) $document->amount, $allocatedBefore);
                $created = [];
                foreach ($rows as $row) {
                    $amount = Money::normalize((string) $row['allocated_amount']);
                    if (Money::compare($amount, '0') <= 0) throw ValidationException::withMessages(['allocated_amount' => '核销金额必须大于 0。']);
                    if (Money::compare($amount, $remainingFunds) > 0) throw ValidationException::withMessages(['allocated_amount' => '核销总额超过资金单未核销余额。']);
                    $source = $this->sources->resolve($row['source_business_type'], (int) $row['source_document_id']);
                    $this->assertDirection($document->direction, $source['type']);
                    if ($document->party_type !== $source['partyType'] || (int) $document->party_id !== $source['partyId']) throw ValidationException::withMessages(['party_id' => '资金单交易对手与业务来源不一致。']);
                    if ($document->currency !== $source['currency']) throw ValidationException::withMessages(['currency' => '资金单币种与业务来源币种不一致。']);
                    $sourceAllocated = $this->activeTotalForSource($source['type'], $source['id']);
                    $sourceRemaining = Money::sub($source['amount'], $sourceAllocated);
                    if (Money::compare($amount, $sourceRemaining) > 0) throw ValidationException::withMessages(['allocated_amount' => '核销金额超过业务来源未结算余额。']);
                    $allocation = FinanceAllocation::create([
                        'cash_document_id' => $document->id,
                        'source_business_type' => $source['type'], 'source_document_id' => $source['id'],
                        'source_document_no' => $source['no'], 'source_line_id' => $row['source_line_id'] ?? null,
                        'party_type' => $source['partyType'], 'party_id' => $source['partyId'], 'currency' => $source['currency'],
                        'source_amount_snapshot' => $source['amount'], 'allocated_amount' => $amount,
                        'status' => FinanceConstants::ALLOCATION_ACTIVE, 'allocated_by' => $operatorId,
                        'allocated_at' => now(), 'idempotency_key' => $row['idempotency_key'],
                    ]);
                    $remainingFunds = Money::sub($remainingFunds, $amount);
                    $created[] = $allocation;
                    if ($source['type'] === FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE) {
                        $this->purchaseSettlementSources->refresh((int) $source['id'], $operatorId, $operatorName);
                    }
                }
                FinanceOperationLog::create([
                    'document_type' => 'cash_document', 'document_id' => $document->id, 'action' => 'allocate',
                    'from_status' => $document->status, 'to_status' => $document->status,
                    'fact_snapshot' => ['allocation_ids' => collect($created)->pluck('id')->all(), 'remaining_amount' => $remainingFunds],
                    'operator_id' => $operatorId, 'operator_name' => $operatorName, 'content' => '新增资金核销事实',
                ]);
                return ['allocations' => $created, 'remaining_amount' => $remainingFunds];
            }, 5);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                throw ValidationException::withMessages(['idempotency_key' => '该核销请求已经处理，不能重复提交。']);
            }
            throw $exception;
        }
    }

    public function reverse(int $allocationId, string $reason, ?int $operatorId, ?string $operatorName): FinanceAllocation
    {
        return DB::transaction(function () use ($allocationId, $reason, $operatorId, $operatorName): FinanceAllocation {
            $allocation = FinanceAllocation::query()->lockForUpdate()->findOrFail($allocationId);
            FinanceCashDocument::query()->whereKey($allocation->cash_document_id)->lockForUpdate()->firstOrFail();
            if ($allocation->status !== FinanceConstants::ALLOCATION_ACTIVE) throw ValidationException::withMessages(['status' => '只有有效核销可以撤销。']);
            if (trim($reason) === '') throw ValidationException::withMessages(['reversal_reason' => '撤销核销必须填写原因。']);
            $allocation->update(['status' => FinanceConstants::ALLOCATION_REVERSED, 'reversed_by' => $operatorId, 'reversed_at' => now(), 'reversal_reason' => $reason]);
            $reversal = FinanceAllocation::create([
                'cash_document_id' => $allocation->cash_document_id,
                'source_business_type' => $allocation->source_business_type, 'source_document_id' => $allocation->source_document_id,
                'source_document_no' => $allocation->source_document_no, 'source_line_id' => $allocation->source_line_id,
                'party_type' => $allocation->party_type, 'party_id' => $allocation->party_id, 'currency' => $allocation->currency,
                'source_amount_snapshot' => $allocation->source_amount_snapshot,
                'allocated_amount' => Money::negate((string) $allocation->allocated_amount),
                'status' => FinanceConstants::ALLOCATION_REVERSAL, 'reversal_of_id' => $allocation->id,
                'allocated_by' => $operatorId, 'allocated_at' => now(),
                'idempotency_key' => 'REV-'.$allocation->id,
            ]);
            FinanceOperationLog::create([
                'document_type' => 'cash_document', 'document_id' => $allocation->cash_document_id, 'action' => 'reverse_allocation',
                'fact_snapshot' => ['allocation_id' => $allocation->id, 'reversal_id' => $reversal->id, 'amount' => (string) $allocation->allocated_amount],
                'operator_id' => $operatorId, 'operator_name' => $operatorName, 'content' => $reason,
            ]);
            if ($allocation->source_business_type === FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE) {
                $this->purchaseSettlementSources->refresh((int) $allocation->source_document_id, $operatorId, $operatorName);
            }
            return $reversal;
        }, 5);
    }

    public function activeTotalForDocument(int $documentId): string
    {
        return Money::normalize((string) FinanceAllocation::query()->where('cash_document_id', $documentId)->where('status', FinanceConstants::ALLOCATION_ACTIVE)->sum('allocated_amount'));
    }

    public function activeTotalForSource(string $type, int $id): string
    {
        return Money::normalize((string) FinanceAllocation::query()->where('source_business_type', $type)->where('source_document_id', $id)->where('status', FinanceConstants::ALLOCATION_ACTIVE)->whereHas('cashDocument', fn ($q) => $q->where('status', FinanceConstants::STATUS_CONFIRMED))->sum('allocated_amount'));
    }

    private function assertDirection(string $direction, string $source): void
    {
        $receiptSources = [FinanceConstants::SOURCE_SALES_ORDER, FinanceConstants::SOURCE_PURCHASE_RETURN_SUPPLIER_REFUND];
        $paymentSources = [FinanceConstants::SOURCE_PURCHASE_RECEIPT, FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE, FinanceConstants::SOURCE_SALES_ORDER_REFUND];
        if (($direction === FinanceConstants::DIRECTION_RECEIPT && !in_array($source, $receiptSources, true))
            || ($direction === FinanceConstants::DIRECTION_PAYMENT && !in_array($source, $paymentSources, true))) {
            throw ValidationException::withMessages(['source_business_type' => '资金方向与业务来源不匹配。']);
        }
    }
}
