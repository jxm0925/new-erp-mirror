<?php

namespace App\Services\Erp;

use App\Models\Erp\PurchaseLog;
use App\Models\Erp\PurchaseReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptPostingRepairApplicationService
{
    public function allocationRevision(\App\Models\Erp\PurchaseReceiptItem $line, ?\Illuminate\Support\Collection $displayedAllocations = null): string
    {
        // The read token describes exactly the relations sent to the user. Re-querying
        // here could pair stale displayed rows with a newer token during a concurrent save.
        $allocations = $displayedAllocations ?? $line->allocations()->with('physicalEntries')->get();
        $snapshot = $allocations->sortBy('id')->values()
            ->map(fn ($allocation) => [$allocation->getAttributes(), $allocation->physicalEntries->sortBy('id')->values()->map(fn ($entry) => $entry->getAttributes())->all()])->all();
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function __construct(
        private readonly PurchaseReceiptAllocationService $allocations,
        private readonly PurchaseReceiptPostingEligibilityService $eligibility,
        private readonly InventorySerialApplicationService $serials,
    ) {
    }

    public function repair(int $receiptId, array $lines, ?string $operator): PurchaseReceipt
    {
        return $this->perform($receiptId, $lines, $operator, false);
    }

    /** Mobile pagination saves the explicitly edited lines without overwriting unseen allocations. */
    public function repairSelectedLines(int $receiptId, array $lines, ?string $operator): PurchaseReceipt
    {
        return $this->perform($receiptId, $lines, $operator, true);
    }

    private function perform(int $receiptId, array $lines, ?string $operator, bool $partial): PurchaseReceipt
    {
        return DB::transaction(function () use ($receiptId, $lines, $operator, $partial): PurchaseReceipt {
            $receipt = PurchaseReceipt::query()
                ->with(['items.item', 'items.allocations'])
                ->lockForUpdate()
                ->findOrFail($receiptId);

            if ($receipt->receipt_status !== 'confirmed' || $receipt->confirm_status !== 'confirmed') {
                throw ValidationException::withMessages(['receipt' => '只有已确认到货单允许在过账前补充入库分配。']);
            }
            if ($receipt->stock_post_status !== 'pending') {
                throw ValidationException::withMessages(['receipt' => '只有待库存过账的到货单允许补充入库分配。']);
            }

            $payload = collect($lines)->keyBy(fn (array $line) => (int) ($line['receipt_item_id'] ?? 0));
            if ($payload->isEmpty() || $payload->count() !== count($lines) || $payload->keys()->diff($receipt->items->pluck('id'))->isNotEmpty())
                throw ValidationException::withMessages(['allocations' => '入库分配明细重复或不属于当前收货单。']);
            foreach ($receipt->items as $line) {
                $qualified = round((float) ($line->qualified_base_qty ?: $line->qualified_qty), 8);
                if ($qualified <= 0) continue;
                $submitted = $payload->get($line->id);
                if (!$submitted) {
                    if ($partial) continue;
                    throw ValidationException::withMessages(['allocations' => "物料 {$line->item?->item_code} 缺少入库分配。"]);
                }
                if ($partial && !hash_equals($this->allocationRevision($line), (string) ($submitted['expected_revision'] ?? ''))) {
                    throw new \App\Exceptions\Erp\WorkOrderDomainException('allocation_changed', '入库分配已变化，请刷新明细后重新核对。', 409);
                }
                $this->allocations->replace($line, $submitted['allocations'] ?? []);
            }

            $receipt->refresh()->load(['items.item', 'items.allocations.warehouse', 'items.allocations.location', 'items.allocations.physicalEntries']);
            if (! $partial) $this->allocations->ensureForConfirmation($receipt);
            $receipt->load(['items.item', 'items.allocations']);
            // Rebind only the edited lines' existing serial identities. Unseen pages may
            // still be incomplete, but must not prevent saving a valid current line.
            $allLines = $receipt->items;
            if ($partial) $receipt->setRelation('items', $allLines->filter(fn ($line) => $payload->has($line->id)));
            $this->serials->registerAcceptedReceipt($receipt);
            $receipt->setRelation('items', $allLines);
            $receipt->load(['items.item', 'items.allocations.warehouse', 'items.allocations.location', 'items.allocations.physicalEntries']);
            $result = $this->eligibility->evaluate($receipt);
            if (!$partial && !$result['can_post']) {
                throw ValidationException::withMessages(['allocations' => $result['reason_text']]);
            }

            PurchaseLog::create([
                'target_type' => 'purchase_receipt',
                'target_id' => $receipt->id,
                'action' => 'repair_posting_allocation',
                'content' => '已确认到货单在库存过账前补充仓库/库位分配；未修改采购数量、质量、金额和原始确认记录。',
                'operator' => $operator ?: '系统',
            ]);

            return $receipt->fresh(['supplier', 'order', 'items.item.unit', 'items.allocations.warehouse', 'items.allocations.location', 'items.allocations.physicalEntries']);
        }, 5);
    }
}
