<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\SalesReturnCostAllocation;
use App\Models\Erp\SalesReturnReceipt;
use App\Services\Erp\SalesReturnIdentityService;
use App\Services\Erp\SalesReturnCostTraceService;
use App\Services\Erp\CuttingDecimal;
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
                ->with(['items' => fn ($query) => $query->with('item')->orderBy('id')->lockForUpdate()])
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
                // 残次品处置行同样占用过账：按 defective_base_qty 计可入库数量，仓库/库位/批次要求与转入库一致。
                $restock = ($line->stock_quality ?? 'normal') === 'defective' ? (float) $line->defective_base_qty : (float) $line->restock_base_qty;
                if ($restock <= 0) continue;
                if (!$line->warehouse_id || !$line->location_id || !$line->batch_no) {
                    throw ValidationException::withMessages(['items' => '销售退货可重新入库数量必须选择仓库、库位并确认退货批次。']);
                }
                $restockTotal += $restock;
            }
            if ($restockTotal <= 0) {
                throw ValidationException::withMessages(['items' => '本次销售退货没有可重新入库数量，无需执行库存过账。']);
            }

            $first = $receipt->items->first(fn ($line) => (($line->stock_quality ?? 'normal') === 'defective' ? (float) $line->defective_base_qty : (float) $line->restock_base_qty) > 0);
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
                // 转残次品行按 defective_base_qty 走同一条原发货成本入库链；数量同时计为不良，可售不增加。
                $isDefective = ($line->stock_quality ?? 'normal') === 'defective';
                $restock = (float) $line->restock_base_qty;
                if ($restock <= 0 && $isDefective) $restock = (float) $line->defective_base_qty;
                if ($restock <= 0) continue;
                $postedQty = 0.0;
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
                        'remark' => '销售退货按原发货成本重新入库；原发货单行 #'.$segment['shipment_line_id'],
                    ]);
                    if (isset($segment['return_serial_identity_id'])) {
                        if ($isDefective) {
                            app(SalesReturnIdentityService::class)->completeDefective((int) $segment['return_serial_identity_id'], $transactionItem, $operatorId);
                        } else {
                            app(SalesReturnIdentityService::class)->completeRestock((int) $segment['return_serial_identity_id'], $transactionItem, $operatorId);
                        }
                    }
                    $postedQty += (float) $segment['base_qty'];
                }
                if ($isDefective && $postedQty > 0.00000001) {
                    $availability = app(\App\Services\Erp\InventoryAvailabilityService::class);
                    foreach ([\App\Models\Erp\InventoryBalance::class, \App\Models\Erp\InventoryLocationBalance::class] as $model) {
                        $balance = $model::where('item_id', $line->item_id)->where('warehouse_id', $line->warehouse_id)
                            ->where('location_id', $line->location_id)->when($model === \App\Models\Erp\InventoryBalance::class, fn ($q) => $q->where('batch_no', $line->batch_no))
                            ->lockForUpdate()->first();
                        if (! $balance) continue;
                        $defective = (float) $balance->quantity_defective + $postedQty;
                        $balance->update(['quantity_defective' => $defective,
                            'quantity_available' => $availability->calculate((float) $balance->quantity_on_hand, (float) $balance->quantity_locked, $defective, (float) $balance->quantity_pending)]);
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
        $costStates = [];
        foreach ($allocations->filter(fn ($allocation) => bccomp((string) $allocation->allocated_base_qty, (string) $allocation->posted_base_qty, 8) > 0)
            ->pluck('outbound_transaction_item_id')->unique() as $sourceId) $costStates[$sourceId] = $this->quantityReturnCostState((int) $sourceId);
        foreach ($allocations as $allocation) {
            if ($remaining <= 0.00000001) {
                break;
            }
            $available = max(0, (float) $allocation->allocated_base_qty - (float) $allocation->posted_base_qty);
            if ($available <= 0.00000001) {
                continue;
            }
            $quantity = min($remaining, $available);
            $quantityDecimal = number_format($quantity, 8, '.', '');
            $state = &$costStates[$allocation->outbound_transaction_item_id];
            $nextQuantity = bcadd($state['returned_quantity'], $quantityDecimal, 8);
            if (bccomp($nextQuantity, $state['source_quantity'], 8) > 0) throw ValidationException::withMessages(['items' => '累计退货数量超过原出库数量。']);
            // 以真实已退金额抵扣累计应退成本，末次退完吃掉尾差，不能逐次用舍入单价乘数量。
            $cost = bcsub(CuttingDecimal::share($state['source_cost'], $state['source_quantity'], $nextQuantity), $state['returned_cost'], 4);
            if (bccomp($cost, '0', 4) < 0) throw ValidationException::withMessages(['items' => '原退货已入库成本超过本次可退成本，请先核对原成本事实。']);
            $state['returned_quantity'] = $nextQuantity;
            $state['returned_cost'] = bcadd($state['returned_cost'], $cost, 4);
            unset($state);
            $newPosted = round((float) $allocation->posted_base_qty + $quantity, 8);
            $allocation->update([
                'posted_base_qty' => $newPosted,
                'allocation_status' => $newPosted + 0.00000001 >= (float) $allocation->allocated_base_qty ? 'posted' : 'reserved',
            ]);
            $segments[] = [
                'base_qty' => $quantity,
                'unit_cost' => bcdiv($cost, $quantityDecimal, 8),
                'cost_amount' => $cost,
                'shipment_line_id' => $allocation->sales_shipment_line_id,
                'outbound_transaction_item_id' => $allocation->outbound_transaction_item_id,
            ];
            $remaining -= $quantity;
        }
        if ($remaining > 0.00000001) {
            throw ValidationException::withMessages([
                'items' => '销售退货缺少可用的原发货成本分摊，不能按当前库存成本重新入库。',
            ]);
        }

        return $segments;
    }

    private function quantityReturnCostState(int $sourceId): array
    {
        $source = DB::table('erp_inventory_transaction_items')->where('id', $sourceId)->lockForUpdate()->first();
        if (! $source || $source->source_type !== 'sales_shipment' || bccomp((string) $source->change_qty, '0', 8) >= 0) {
            throw ValidationException::withMessages(['items' => '退货缺少真实原销售出库成本。']);
        }
        $allocations = DB::table('erp_sales_return_cost_allocations')->where('outbound_transaction_item_id', $sourceId)->orderBy('id')->lockForUpdate()->get();
        $returnedCost = '0.0000'; $returnedQuantity = '0.00000000';
        foreach (app(SalesReturnCostTraceService::class)->postedSegments($allocations->pluck('sales_return_item_id')->unique()->all()) as $segment) {
            if ((int) $segment['source']->id !== $sourceId || $segment['serial_id'] !== null) continue;
            $returnedCost = bcadd($returnedCost, (string) $segment['movement']->cost_amount, 4);
            $returnedQuantity = bcadd($returnedQuantity, $segment['quantity'], 8);
        }
        $pool = app(SalesReturnCostTraceService::class)->quantityPool($source);
        return ['source_cost' => $pool['cost'], 'source_quantity' => $pool['quantity'],
            'returned_cost' => $returnedCost, 'returned_quantity' => $returnedQuantity];
    }
}
