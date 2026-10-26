<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\SalesReturnCostAllocation;
use App\Models\Erp\SalesReturnReceipt;
use App\Services\Erp\SalesReturnIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 销售退货入库过账：只按原出库成本与原出库流水证明冲回。业务只组装过账行，数量/成本/余额一律经 InventoryPostingKernel。 */
class SalesReturnInventoryPosting
{
    public function __construct(
        private readonly InventoryPostingKernel $kernel,
        private readonly OriginalReturnProofGuard $originalReturns,
    )
    {
    }

    public function postSalesReturnReceipt(int $receiptId, ?int $operatorId = null): InventoryTransaction
    {
        return DB::transaction(function () use ($receiptId, $operatorId): InventoryTransaction {
            $receipt = SalesReturnReceipt::query()
                ->with(['salesReturn', 'items.item'])
                ->lockForUpdate()
                ->findOrFail($receiptId);
            if ($receipt->receipt_status !== 'confirmed' || $receipt->stock_post_status !== 'pending') {
                throw ValidationException::withMessages(['receipt_status' => '只有已确认且待库存过账的销售退货到货单可以重新入库。']);
            }
            if (InventoryTransaction::query()
                ->where('source_type', 'sales_return_receipt')
                ->where('source_id', $receipt->id)
                ->where('transaction_type', 'sales_return_inbound')
                ->exists()) {
                throw ValidationException::withMessages(['receipt_status' => '该销售退货到货单已经入库，不能重复过账。']);
            }

            $restockTotal = 0.0;
            foreach ($receipt->items as $line) {
                $restock = (float) $line->restock_base_qty;
                if ($restock <= 0) continue;
                if (!$line->warehouse_id || !$line->location_id || !$line->batch_no) {
                    throw ValidationException::withMessages(['items' => '销售退货可重新入库数量必须选择仓库、库位并确认退货批次。']);
                }
                $restockTotal += $restock;
            }
            if ($restockTotal <= 0) {
                throw ValidationException::withMessages(['items' => '本次销售退货没有可重新入库数量，无需执行库存过账。']);
            }

            $first = $receipt->items->first(fn ($line) => (float) $line->restock_base_qty > 0);
            $transaction = $this->kernel->begin([
                
                'transaction_type' => 'sales_return_inbound',
                'source_type' => 'sales_return_receipt',
                'source_id' => $receipt->id,
                'source_no' => $receipt->receipt_no,
                'posting_status' => 'posted',
                'warehouse_id' => $first?->warehouse_id,
                'location_id' => $first?->location_id,
                'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId,
                'posted_at' => now(),
                'created_by' => $operatorId,
                'remark' => '销售退货重新入库',
            ]);

            foreach ($receipt->items as $line) {
                $restock = (float) $line->restock_base_qty;
                if ($restock <= 0) continue;
                foreach ($this->consumeSalesReturnCostAllocations($line, $restock) as $segment) {
                    $originalReturn = $this->originalReturns->proofFor((int) ($segment['outbound_transaction_item_id'] ?? 0), [
                        'item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id,
                        'location_id' => $line->location_id, 'batch_no' => $line->batch_no,
                    ], (float) $segment['base_qty']);
                    $transactionItem = $this->kernel->apply($transaction, [
                        'item_id' => $line->item_id,
                        'warehouse_id' => $line->warehouse_id,
                        'location_id' => $line->location_id,
                        'batch_no' => $line->batch_no,
                        'unit_id' => $line->base_unit_id ?: $line->item?->unit_id,
                        'change_qty' => $segment['base_qty'],
                        'original_return_transaction_items' => $originalReturn,
                        'unit_cost' => $segment['unit_cost'],
                        'cost_amount' => $segment['cost_amount'],
                        'cost_source_type' => 'sales_return_frozen_cost',
                        'source_type' => 'sales_return_receipt',
                        'source_id' => $receipt->id,
                        'source_item_id' => $line->id,
                        'remark' => '销售退货按原发运成本重新入库；原发运单行 #'.$segment['shipment_line_id'],
                    ]);
                    if (isset($segment['return_serial_identity_id'])) {
                        app(SalesReturnIdentityService::class)->completeRestock((int) $segment['return_serial_identity_id'], $transactionItem, $operatorId);
                    }
                }
            }

            $receipt->update([
                'stock_post_status' => 'posted',
                'posted_by' => $operatorId,
                'posted_at' => now(),
            ]);
            InventoryPostingLog::create([
                'source_type' => 'sales_return_receipt',
                'source_id' => $receipt->id,
                'source_no' => $receipt->receipt_no,
                'transaction_type' => 'sales_return_inbound',
                'transaction_id' => $transaction->id,
                'posting_status' => 'posted',
                'message' => '销售退货重新入库成功',
                'posted_by' => $operatorId,
                'posted_at' => now(),
            ]);

            return $transaction->fresh(['items.item', 'items.warehouse', 'items.location']);
        }, 5);
    }

    /**
     * Consume only the quantity that really passed return inspection and is being
     * restocked. The value comes from the immutable sales-outbound transaction.
     */
    private function consumeSalesReturnCostAllocations(object $receiptLine, float $restockQty): array
    {
        $serialSegments = app(SalesReturnIdentityService::class)->consumeRestock($receiptLine);
        if ($serialSegments !== null) return $serialSegments;
        $allocations = SalesReturnCostAllocation::query()
            ->where('sales_return_item_id', $receiptLine->sales_return_item_id)
            ->whereIn('allocation_status', ['reserved', 'posted'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $restockQty;
        $segments = [];
        foreach ($allocations as $allocation) {
            if ($remaining <= 0.00000001) {
                break;
            }
            $available = max(0, (float) $allocation->allocated_base_qty - (float) $allocation->posted_base_qty);
            if ($available <= 0.00000001) {
                continue;
            }
            $quantity = min($remaining, $available);
            $newPosted = round((float) $allocation->posted_base_qty + $quantity, 8);
            $allocation->update([
                'posted_base_qty' => $newPosted,
                'allocation_status' => $newPosted + 0.00000001 >= (float) $allocation->allocated_base_qty ? 'posted' : 'reserved',
            ]);
            $segments[] = [
                'base_qty' => $quantity,
                'unit_cost' => (float) $allocation->unit_cost_snapshot,
                'cost_amount' => round($quantity * (float) $allocation->unit_cost_snapshot, 4),
                'shipment_line_id' => $allocation->sales_shipment_line_id,
                'outbound_transaction_item_id' => $allocation->outbound_transaction_item_id,
            ];
            $remaining -= $quantity;
        }
        if ($remaining > 0.00000001) {
            throw ValidationException::withMessages([
                'items' => '销售退货缺少可用的原发运成本分摊，不能按当前库存成本重新入库。',
            ]);
        }

        return $segments;
    }
}
