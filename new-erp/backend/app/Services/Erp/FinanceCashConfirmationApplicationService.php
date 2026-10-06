<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\FinanceCashDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FinanceCashConfirmationApplicationService
{
    public function __construct(
        private readonly FinanceCashDocumentApplicationService $cashDocuments,
        private readonly FinanceAllocationApplicationService $allocations,
        private readonly FinanceCashPurchaseAllocationApplicationService $purchasePurposes,
    ) {}

    public function confirm(int $id, array $items, ?int $operatorId, ?string $operatorName): FinanceCashDocument
    {
        Validator::make(['items' => $items], FinanceAllocationApplicationService::itemRules('items', 'present|array|max:100'))->validate();
        if ($items === []) {
            return DB::transaction(function () use ($id, $operatorId, $operatorName): FinanceCashDocument {
                $document = FinanceCashDocument::query()->lockForUpdate()->findOrFail($id);
                // Purchase purposes are already frozen on this exact document.
                // A lost confirmation response is safe to replay without posting again.
                if ($document->status === FinanceConstants::STATUS_CONFIRMED && (int) $document->purchase_order_allocation_version > 0) return $this->currentDocument($id);
                return $this->cashDocuments->confirm($id, $operatorId, $operatorName);
            }, 5);
        }

        return DB::transaction(function () use ($id, $items, $operatorId, $operatorName): FinanceCashDocument {
            // Keep the same source -> cash -> allocation locks as shipment/funding
            // changes. The outer transaction owns both nested services, so any
            // failed allocation also rolls back confirmation, fees and ledger rows.
            $this->allocations->lockSources($items);
            $document = FinanceCashDocument::query()->lockForUpdate()->findOrFail($id);
            $existing = FinanceAllocation::query()->whereIn('idempotency_key', array_column($items, 'idempotency_key'))
                ->lockForUpdate()->get()->keyBy('idempotency_key');

            $matchedCount = 0;
            foreach ($items as $item) {
                $allocation = $existing->get($item['idempotency_key']);
                if ($allocation && ! $this->matches($allocation, $document->id, $item)) {
                    throw ValidationException::withMessages(['idempotency_key' => '核销请求编号已用于其他内容，或原核销已撤销，请刷新后重试。']);
                }
                if ($allocation) $matchedCount++;
            }
            // MySQL's key collation can match different casing/text while PHP
            // keys do not. Never mistake a collation collision for a successful
            // replay merely because the number of selected records is equal.
            if ($existing->count() !== $matchedCount) {
                throw ValidationException::withMessages(['idempotency_key' => '核销请求编号与已处理请求冲突，请刷新后重试。']);
            }

            if ($document->status === FinanceConstants::STATUS_CONFIRMED && $matchedCount === count($items)) {
                // A response can be lost after commit. Matching persisted facts
                // prove this retry was completed; never post money or allocations again.
                return $this->currentDocument($id);
            }
            if ($document->status !== FinanceConstants::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => '只有草稿资金单可以确认；已确认单据请使用后续核销。']);
            }
            if ($existing->isNotEmpty()) {
                throw ValidationException::withMessages(['idempotency_key' => '草稿资金单不能引用已处理的核销请求。']);
            }

            $this->cashDocuments->confirm($id, $operatorId, $operatorName, true);
            $this->allocations->allocate($id, $items, $operatorId, $operatorName);
            $this->purchasePurposes->assertNetAvailableForCash($id);
            return $this->currentDocument($id);
        }, 5);
    }

    private function currentDocument(int $id): FinanceCashDocument
    {
        // A retry may have started its snapshot before the original committed
        // while waiting for the source. Return the same current facts verified
        // above, rather than reloading an old draft/zero balance with fresh().
        return FinanceCashDocument::query()->with([
            'account', 'attachments',
            'allocations' => fn ($query) => $query->lockForUpdate(),
            'logs' => fn ($query) => $query->lockForUpdate(),
        ])->lockForUpdate()->findOrFail($id);
    }

    private function matches(FinanceAllocation $allocation, int $documentId, array $item): bool
    {
        return (int) $allocation->cash_document_id === $documentId
            && $allocation->status === FinanceConstants::ALLOCATION_ACTIVE
            && $allocation->source_business_type === $item['source_business_type']
            && (int) $allocation->source_document_id === (int) $item['source_document_id']
            && ($allocation->source_line_id === null ? null : (int) $allocation->source_line_id)
                === (isset($item['source_line_id']) ? (int) $item['source_line_id'] : null)
            && Money::compare((string) $allocation->allocated_amount, (string) $item['allocated_amount']) === 0;
    }
}
