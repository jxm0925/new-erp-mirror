<?php

namespace App\Services\Erp;

use App\Models\Erp\PurchaseLog;
use App\Models\Erp\PurchaseReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptPostingRepairApplicationService
{
    public function __construct(
        private readonly PurchaseReceiptAllocationService $allocations,
        private readonly PurchaseReceiptPostingEligibilityService $eligibility,
        private readonly InventorySerialApplicationService $serials,
    ) {
    }

    public function repair(int $receiptId, array $lines, ?string $operator): PurchaseReceipt
    {
        return DB::transaction(function () use ($receiptId, $lines, $operator): PurchaseReceipt {
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
            foreach ($receipt->items as $line) {
                $qualified = round((float) ($line->qualified_base_qty ?: $line->qualified_qty), 8);
                if ($qualified <= 0) continue;
                $submitted = $payload->get($line->id);
                if (!$submitted) {
                    throw ValidationException::withMessages(['allocations' => "物料 {$line->item?->item_code} 缺少入库分配。"]);
                }
                $this->allocations->replace($line, $submitted['allocations'] ?? []);
            }

            $receipt->refresh()->load(['items.item', 'items.allocations.warehouse', 'items.allocations.location']);
            $this->allocations->ensureForConfirmation($receipt);
            $receipt->load(['items.item', 'items.allocations']);
            $this->serials->registerAcceptedReceipt($receipt);
            $receipt->load(['items.item', 'items.allocations.warehouse', 'items.allocations.location']);
            $result = $this->eligibility->evaluate($receipt);
            if (!$result['can_post']) {
                throw ValidationException::withMessages(['allocations' => $result['reason_text']]);
            }

            PurchaseLog::create([
                'target_type' => 'purchase_receipt',
                'target_id' => $receipt->id,
                'action' => 'repair_posting_allocation',
                'content' => '已确认到货单在库存过账前补充仓库/库位分配；未修改采购数量、质量、金额和原始确认记录。',
                'operator' => $operator ?: '系统',
            ]);

            return $receipt->fresh(['supplier', 'order', 'items.item.unit', 'items.allocations.warehouse', 'items.allocations.location']);
        }, 5);
    }
}
