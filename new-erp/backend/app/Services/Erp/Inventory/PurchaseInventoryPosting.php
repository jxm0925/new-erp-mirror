<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItemAllocation;
use App\Models\Erp\PurchaseReturn;
use App\Services\Erp\CuttingDecimal;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\InventorySerialApplicationService;
use App\Services\Erp\MaterialPhysicalService;
use App\Services\Erp\PurchaseReceiptAllocationService;
use App\Services\Erp\PurchaseReceiptManagementScopeService;
use App\Services\Erp\PurchaseReceiptPostingEligibilityService;
use App\Services\Erp\PurchaseReceiptSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 采购库存过账：到货入库、不合格转合格补入库、采购退货出库。业务只组装过账行，数量/成本/余额一律经 InventoryPostingKernel。 */
class PurchaseInventoryPosting
{
    public function __construct(
        private readonly InventorySerialApplicationService $serials,
        private readonly InventoryAvailabilityService $availability,
        private readonly MaterialPhysicalService $physicalMaterials,
        private readonly InventoryPostingKernel $kernel,
    )
    {
    }

    public function postPurchaseReceipt(int $receiptId, ?int $operatorId = null): InventoryTransaction
    {
        return DB::transaction(function () use ($receiptId, $operatorId) {
            $receipt = PurchaseReceipt::with(['items.item', 'items.allocations', 'supplier', 'order'])->lockForUpdate()->findOrFail($receiptId);

            if ($receipt->stock_post_status === 'posted') {
                throw ValidationException::withMessages(['receipt' => '该到货单已过账，不能重复过账。']);
            }
            if ($receipt->stock_post_status === 'not_required') {
                throw ValidationException::withMessages(['receipt' => '该到货单不包含需要库存管理的合格物料，无须库存过账。']);
            }
            if ($receipt->stock_post_status !== 'pending') {
                throw ValidationException::withMessages(['receipt' => '只有待库存过账的到货单可以过账。']);
            }
            if ($receipt->receipt_status !== 'confirmed' || $receipt->confirm_status !== 'confirmed') {
                throw ValidationException::withMessages(['receipt' => '采购到货单确认到货后，才允许库存过账。']);
            }
            app(PurchaseReceiptSettlementService::class)->refresh($receipt);
            $receipt->load(['items.item', 'supplier', 'order']);
            if (InventoryTransaction::where('source_type', 'purchase_receipt')->where('source_id', $receipt->id)->where('transaction_type', 'purchase_receipt_posting')->exists()) {
                throw ValidationException::withMessages(['receipt' => '该到货单已过账，不能重复过账。']);
            }
            app(PurchaseReceiptManagementScopeService::class)->assertReceipt($receipt);

            $receipt->load(['items.item', 'items.allocations', 'supplier', 'order']);
            app(PurchaseReceiptAllocationService::class)->ensureForConfirmation($receipt);
            $receipt->load(['items.item', 'items.allocations', 'supplier', 'order']);

            $qualifiedTotal = 0;
            foreach ($receipt->items as $line) {
                if (!(bool) $line->is_stock_item_snapshot) continue;
                if (!$line->batch_no) {
                    throw ValidationException::withMessages(['receipt' => '请先补充批次号，或按批次规则自动生成后再执行库存过账。']);
                }
                $qualifiedTotal += $this->qualifiedBaseQuantity($line);
            }
            if ($qualifiedTotal <= 0) {
                throw ValidationException::withMessages(['receipt' => '合格数量必须大于 0 才能产生正常入库流水。']);
            }

            $transaction = $this->kernel->begin([
                
                'transaction_type' => 'purchase_receipt_posting',
                'source_type' => 'purchase_receipt',
                'source_id' => $receipt->id,
                'source_no' => $receipt->receipt_no,
                'posting_status' => 'posted',
                'warehouse_id' => $receipt->items->first()?->allocations->first()?->warehouse_id,
                'location_id' => $receipt->items->first()?->allocations->first()?->location_id,
                'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId,
                'posted_at' => now(),
                'created_by' => $operatorId,
                'remark' => '采购到货入库过账',
            ]);

            foreach ($receipt->items as $line) {
                if (!(bool) $line->is_stock_item_snapshot) continue;
                $qty = $this->qualifiedBaseQuantity($line);
                if ($qty <= 0) continue;
                $unitCost = $qty > 0 ? (float) $line->inventory_cost_amount / $qty : 0;
                $remainingCost = (string) $line->inventory_cost_amount;
                $remainingQty = number_format($qty, 8, '.', '');
                foreach ($line->allocations as $allocation) {
                    $allocationQty = (float) $allocation->base_qty;
                    if ($allocationQty <= 0) continue;
                    $allocationQtyDecimal = (string) $allocation->base_qty;
                    $allocationCost = CuttingDecimal::share($remainingCost, $remainingQty, $allocationQtyDecimal);
                    $transactionItem = $this->kernel->apply($transaction, [
                        'item_id' => $line->item_id,
                        'warehouse_id' => $allocation->warehouse_id,
                        'location_id' => $allocation->location_id,
                        'batch_no' => $line->batch_no,
                        'unit_id' => $line->base_unit_id ?: $line->item?->unit_id,
                        'change_qty' => $allocationQty,
                        'unit_cost' => $unitCost,
                        'cost_amount' => $allocationCost,
                        'purchase_amount_snapshot' => $qty > 0
                            ? round((float) $line->amount_excl_tax * $allocationQty / $qty, 4)
                            : 0,
                        'freight_amount_snapshot' => $qty > 0
                            ? round((float) $line->freight_allocated_amount * $allocationQty / $qty, 4)
                            : 0,
                        'other_purchase_cost_amount_snapshot' => $qty > 0
                            ? round((float) $line->other_purchase_cost_amount * $allocationQty / $qty, 4)
                            : 0,
                        'cost_source_type' => $receipt->settlement_mode === 'replacement_no_charge'
                            ? 'purchase_exchange_replacement'
                            : 'purchase_receipt_frozen_fact',
                        'source_type' => 'purchase_receipt',
                        'source_id' => $receipt->id,
                        'source_item_id' => $line->id,
                        'physical_operation' => $line->item?->materialManagementMode() === 'physical' ? 'purchase_receipt' : null,
                        'physical_entry_count' => $allocation->physicalEntries()->count(),
                        'remark' => '采购到货合格数量按库位分配入库',
                    ]);
                    if ($line->item?->materialManagementMode() === 'physical') {
                        $this->physicalMaterials->createPurchaseReceiptPhysicals($line, $allocation, $transactionItem);
                    }
                    $this->registerReceiptSerials($receipt, $line, $allocation);
                    $remainingCost = bcsub($remainingCost, $allocationCost, 4);
                    $remainingQty = bcsub($remainingQty, $allocationQtyDecimal, 8);
                }
            }

            $qualityPending = (float) $receipt->items->sum('quality_hold_amount') > 0.0001;
            $receipt->update(['stock_post_status' => 'posted', 'fulfillment_status' => $qualityPending ? 'pending_quality' : 'fulfilled']);
            $postingLog = InventoryPostingLog::create([
                'source_type' => 'purchase_receipt',
                'source_id' => $receipt->id,
                'source_no' => $receipt->receipt_no,
                'transaction_type' => 'purchase_receipt_posting',
                'transaction_id' => $transaction->id,
                'posting_status' => 'posted',
                'message' => '采购到货库存过账成功',
                'posted_at' => now(),
            ]);
            foreach ($receipt->items as $line) {
                $line->update($line->is_stock_item_snapshot
                    ? ['inventory_posting_status' => 'posted', 'inventory_posting_log_id' => $postingLog->id]
                    : ['inventory_posting_status' => 'not_required']);
            }

            return $transaction->fresh(['items.item', 'items.warehouse', 'items.location']);
        }, 5);
    }

    /**
     * 到货单已按合格部分过账后，不合格品经让步接收或返修合格转为可入库数量，
     * 按“应入库数量 - 已过账数量”补一笔采购入库流水，入到该行第一条库位分配上。
     */
    public function postPurchaseReceiptQualityRelease(int $receiptItemId, ?int $operatorId = null, ?object $handling = null): ?InventoryTransaction
    {
        return DB::transaction(function () use ($receiptItemId, $operatorId, $handling): ?InventoryTransaction {
            $line = \App\Models\Erp\PurchaseReceiptItem::with(['receipt', 'item', 'allocations'])->lockForUpdate()->findOrFail($receiptItemId);
            $receipt = $line->receipt;
            if (!$receipt || $receipt->stock_post_status !== 'posted' || !(bool) $line->is_stock_item_snapshot) return null;
            if (PurchaseReceiptPostingEligibilityService::requiresQualityClosedBeforePosting($line->item)) {
                throw ValidationException::withMessages(['receipt' => '序列号或实物管理物料不能在到货单过账后补入库，请走库存质量处理。']);
            }

            $posted = DB::table('erp_inventory_transaction_items as movement')
                ->join('erp_inventory_transactions as posting', 'posting.id', '=', 'movement.transaction_id')
                ->where('movement.source_type', 'purchase_receipt')->where('movement.source_id', $receipt->id)
                ->whereIn('posting.transaction_type', ['purchase_receipt_posting', 'purchase_receipt_quality_release'])->where('posting.posting_status', 'posted')
                ->where('movement.source_item_id', $line->id)
                ->selectRaw('COALESCE(SUM(movement.change_qty), 0) as qty, COALESCE(SUM(movement.cost_amount), 0) as cost')
                ->first();
            $delta = bcsub(number_format($this->qualifiedBaseQuantity($line), 8, '.', ''), (string) $posted->qty, 8);
            if (bccomp($delta, '0', 8) <= 0) return null;
            $cost = bcsub((string) $line->inventory_cost_amount, (string) $posted->cost, 4);
            if (bccomp($cost, '0', 4) < 0) $cost = '0.0000';

            $allocation = $line->allocations->sortBy('id')->first();
            if (!$allocation) {
                throw ValidationException::withMessages(['receipt' => '该到货明细没有入库库位分配，无法补入库，请联系仓库补充分配。']);
            }
            $allocation->update(['base_qty' => bcadd((string) $allocation->base_qty, $delta, 8)]);

            $transaction = $this->kernel->begin([
                
                // 一张到货单只允许一笔正式过账流水（来源唯一索引），补入库挂在不合格品处理单上，每张处理单一笔；
                // 明细行仍记到货单来源，便于按到货明细汇总已入库数量。
                'transaction_type' => 'purchase_receipt_quality_release',
                'source_type' => $handling ? 'purchase_defect_handling' : 'purchase_receipt',
                'source_id' => $handling ? $handling->id : $receipt->id,
                'source_no' => $handling ? $handling->handling_no : $receipt->receipt_no,
                'posting_status' => 'posted',
                'warehouse_id' => $allocation->warehouse_id,
                'location_id' => $allocation->location_id,
                'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId,
                'posted_at' => now(),
                'created_by' => $operatorId,
                'remark' => '不合格品让步接收/返修合格补入库（到货单 '.$receipt->receipt_no.'）',
            ]);
            $this->kernel->apply($transaction, [
                'item_id' => $line->item_id,
                'warehouse_id' => $allocation->warehouse_id,
                'location_id' => $allocation->location_id,
                'batch_no' => $line->batch_no,
                'unit_id' => $line->base_unit_id ?: $line->item?->unit_id,
                'change_qty' => (float) $delta,
                'unit_cost' => (float) $delta > 0 ? (float) $cost / (float) $delta : 0,
                'cost_amount' => $cost,
                'purchase_amount_snapshot' => (float) $cost,
                'freight_amount_snapshot' => 0,
                'other_purchase_cost_amount_snapshot' => 0,
                'cost_source_type' => 'purchase_receipt_frozen_fact',
                'source_type' => 'purchase_receipt',
                'source_id' => $receipt->id,
                'source_item_id' => $line->id,
                'physical_operation' => null,
                'physical_entry_count' => 0,
                'remark' => '不合格品让步接收/返修合格补入库',
            ]);
            InventoryPostingLog::create([
                'source_type' => $transaction->source_type,
                'source_id' => $transaction->source_id,
                'source_no' => $transaction->source_no,
                'transaction_type' => 'purchase_receipt_quality_release',
                'transaction_id' => $transaction->id,
                'posting_status' => 'posted',
                'message' => '不合格品转合格补入库成功',
                'posted_at' => now(),
            ]);

            return $transaction->fresh(['items.item', 'items.warehouse', 'items.location']);
        }, 5);
    }

    public function postPurchaseReturn(int $returnId, ?int $operatorId = null): InventoryTransaction
    {
        return DB::transaction(function () use ($returnId, $operatorId): InventoryTransaction {
            $purchaseReturn = PurchaseReturn::query()
                ->with(['items.item', 'items.sourceInventoryQualityEvent', 'items.serialLinks', 'items.physicalLinks'])
                ->lockForUpdate()
                ->findOrFail($returnId);

            if ($purchaseReturn->return_scope !== 'posted_inventory') {
                throw ValidationException::withMessages(['return_scope' => '未入库拒收不产生采购退货库存出库流水。']);
            }
            if ($purchaseReturn->return_status !== 'pending_outbound'
                || $purchaseReturn->audit_status !== 'approved'
                || $purchaseReturn->stock_post_status !== 'pending') {
                throw ValidationException::withMessages(['return_status' => '只有已审核且待仓库出库的采购退货单可以过账。']);
            }
            if (InventoryTransaction::query()
                ->where('source_type', 'purchase_return')
                ->where('source_id', $purchaseReturn->id)
                ->where('transaction_type', 'purchase_return_outbound')
                ->exists()) {
                throw ValidationException::withMessages(['return_status' => '该采购退货单已经出库，不能重复过账。']);
            }

            $lockedReturnSerials = [];
            foreach ($purchaseReturn->items as $line) {
                if (!$line->warehouse_id || !$line->location_id || !$line->batch_no) {
                    throw ValidationException::withMessages(['items' => '采购退货出库明细必须包含物料、仓库、库位和原采购批次。']);
                }
                $quantity = (float) $line->approved_base_qty;
                if ($quantity <= 0) {
                    throw ValidationException::withMessages(['items' => '采购退货审核数量必须大于 0。']);
                }
                $balance = InventoryBalance::query()
                    ->where('item_id', $line->item_id)
                    ->where('warehouse_id', $line->warehouse_id)
                    ->where('location_id', $line->location_id)
                    ->where('batch_no', $line->batch_no)
                    ->lockForUpdate()
                    ->first();
                $this->physicalMaterials->assertPurchaseReturnReady($line, (string) $quantity);
                $qualityEvent = $line->sourceInventoryQualityEvent;
                if (!$qualityEvent) {
                    $mode = $line->item?->serialTrackingMode() ?? 'none';
                    $serialIds = $line->serialLinks->pluck('inventory_serial_id')->map(fn ($id) => (int) $id)->all();
                    if ($mode === 'required' && (count($serialIds) !== (int) round($quantity) || abs($quantity - round($quantity)) > 0.00000001)) {
                        throw ValidationException::withMessages(['serial_ids' => "物料 {$line->item?->item_code} 退货 {$quantity} 件，必须逐件选择相同数量的设备编号。"]);
                    }
                    if ($mode === 'none' && count($serialIds) > 0) {
                        throw ValidationException::withMessages(['serial_ids' => "物料 {$line->item?->item_code} 未启用单件编号，不允许携带设备编号。"]);
                    }
                    if (count($serialIds) > 0) {
                        $serials = InventorySerial::query()
                            ->whereIn('id', $serialIds)
                            ->where('item_id', $line->item_id)
                            ->where('warehouse_id', $line->warehouse_id)
                            ->where('location_id', $line->location_id)
                            ->where('batch_no', $line->batch_no)
                            ->where('serial_status', 'available')
                            ->lockForUpdate()
                            ->get();
                        if ($serials->count() !== count($serialIds)) {
                            throw ValidationException::withMessages(['serial_ids' => '采购退货所选设备编号已不在当前批次可用库存中，请重新核对。']);
                        }
                        $lockedReturnSerials[$line->id] = $serials;
                    }
                }
                if ($qualityEvent) {
                    $held = $balance ? (float) $balance->quantity_pending : 0;
                    if (!$balance
                        || $qualityEvent->event_status !== 'pending_supplier_return'
                        || (float) $balance->quantity_on_hand + 0.00000001 < $quantity
                        || $held + 0.00000001 < $quantity) {
                        throw ValidationException::withMessages([
                            'stock' => '库存质量退货所冻结的原批次实物不足，不能执行退供应商出库。',
                        ]);
                    }
                } elseif ($line->item?->materialManagementMode() === 'physical') {
                    if (!$balance || $line->physicalLinks->count() !== (int) round($quantity)
                        || (float) $balance->quantity_on_hand + 0.00000001 < $quantity) {
                        throw ValidationException::withMessages(['stock' => '采购退货所选具体实物与当前原采购批次库存不一致。']);
                    }
                } elseif (!$balance || $this->availability->availableForOutbound($balance) < $quantity) {
                    $available = $balance ? $this->availability->availableForOutbound($balance) : 0;
                    throw ValidationException::withMessages(['stock' => "原采购批次可用库存不足，当前可用 {$available}，不能退货 {$quantity}。"]);
                }
            }

            $first = $purchaseReturn->items->first();
            $transaction = $this->kernel->begin([
                
                'transaction_type' => 'purchase_return_outbound',
                'source_type' => 'purchase_return',
                'source_id' => $purchaseReturn->id,
                'source_no' => $purchaseReturn->return_no,
                'posting_status' => 'posted',
                'warehouse_id' => $first?->warehouse_id,
                'location_id' => $first?->location_id,
                'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId,
                'posted_at' => now(),
                'created_by' => $operatorId,
                'remark' => '采购退货出库',
            ]);

            foreach ($purchaseReturn->items as $line) {
                $quantity = (float) $line->approved_base_qty;
                $balance = InventoryBalance::query()
                    ->where('item_id', $line->item_id)
                    ->where('warehouse_id', $line->warehouse_id)
                    ->where('location_id', $line->location_id)
                    ->where('batch_no', $line->batch_no)
                    ->lockForUpdate()
                    ->firstOrFail();
                $unitCost = (float) ($balance->average_unit_cost ?: $line->unit_cost_snapshot);
                if ($line->item?->materialManagementMode() === 'physical') {
                    $lineCost = '0';
                    foreach ($line->physicalLinks as $link) {
                        $physical = $this->physicalMaterials->assertWarehousePhysical(
                            (int) $link->physical_material_id,
                            (int) $line->item_id,
                            (int) $balance->id,
                        );
                        $transactionItem = $this->kernel->apply($transaction, [
                            'item_id' => $line->item_id,
                            'warehouse_id' => $line->warehouse_id,
                            'location_id' => $line->location_id,
                            'batch_no' => $line->batch_no,
                            'unit_id' => $line->base_unit_id ?: $line->item?->unit_id,
                            'change_qty' => '-1',
                            'unit_cost' => (float) $physical->total_cost,
                            'cost_amount' => bcsub('0', (string) $physical->total_cost, 4),
                            'purchase_amount_snapshot' => null,
                            'cost_source_type' => 'purchase_return_physical_fact',
                            'source_type' => 'purchase_return',
                            'source_id' => $purchaseReturn->id,
                            'source_item_id' => $line->id,
                            'physical_operation' => 'purchase_return',
                            'physical_link_id' => $link->id,
                            'physical_material_id' => $physical->id,
                            'remark' => '采购退货逐张实物出库',
                        ]);
                        $this->physicalMaterials->completePurchaseReturn($link, $line, $transactionItem, (int) ($operatorId ?? 0));
                        $lineCost = bcadd($lineCost, (string) $physical->total_cost, 4);
                    }
                    $line->update([
                        'posted_base_qty' => $quantity,
                        'inventory_cost_amount' => $lineCost,
                        'finance_fact_status' => 'frozen',
                    ]);
                    continue;
                }
                $this->kernel->apply($transaction, [
                    'item_id' => $line->item_id,
                    'warehouse_id' => $line->warehouse_id,
                    'location_id' => $line->location_id,
                    'batch_no' => $line->batch_no,
                    'unit_id' => $line->base_unit_id ?: $line->item?->unit_id,
                    'change_qty' => -$quantity,
                    'unit_cost' => $unitCost,
                    'cost_amount' => -round($quantity * $unitCost, 4),
                    'purchase_amount_snapshot' => -abs((float) $line->return_amount_excl_tax),
                    'cost_source_type' => 'purchase_return_frozen_fact',
                    'source_type' => 'purchase_return',
                    'source_id' => $purchaseReturn->id,
                    'source_item_id' => $line->id,
                    'remark' => '采购退货出库',
                ]);
                foreach ($lockedReturnSerials[$line->id] ?? [] as $serial) {
                    $serial->update(['serial_status' => 'returned', 'outbound_at' => now()]);
                    InventorySerialEvent::create([
                        'inventory_serial_id' => $serial->id,
                        'event_type' => 'purchase_return_outbound',
                        'document_type' => 'purchase_return',
                        'document_id' => $purchaseReturn->id,
                        'document_no' => $purchaseReturn->return_no,
                        'from_status' => 'available',
                        'to_status' => 'returned',
                        'warehouse_id' => $serial->warehouse_id,
                        'location_id' => $serial->location_id,
                        'batch_no' => $serial->batch_no,
                        'operator_id' => $operatorId,
                        'event_payload' => ['purchase_return_item_id' => $line->id],
                        'occurred_at' => now(),
                    ]);
                }
                $line->update([
                    'posted_base_qty' => $quantity,
                    'inventory_cost_amount' => round($quantity * $unitCost, 4),
                    'finance_fact_status' => 'frozen',
                ]);
            }

            $purchaseReturn->update([
                'return_status' => 'completed',
                'stock_post_status' => 'posted',
                'posted_by' => $operatorId,
                'posted_at' => now(),
                'cost_amount' => round((float) $purchaseReturn->items()->sum('inventory_cost_amount'), 4),
                'finance_fact_status' => 'frozen',
            ]);
            InventoryPostingLog::create([
                'source_type' => 'purchase_return',
                'source_id' => $purchaseReturn->id,
                'source_no' => $purchaseReturn->return_no,
                'transaction_type' => 'purchase_return_outbound',
                'transaction_id' => $transaction->id,
                'posting_status' => 'posted',
                'message' => '采购退货库存出库成功',
                'posted_by' => $operatorId,
                'posted_at' => now(),
            ]);

            return $transaction->fresh(['items.item', 'items.warehouse', 'items.location']);
        }, 5);
    }

    private function qualifiedBaseQuantity($line): float
    {
        if (!(bool) ($line->is_stock_item_snapshot ?? true)) return 0.0;
        if ($line->final_stockable_base_qty !== null) {
            return max(0, (float) $line->final_stockable_base_qty);
        }
        if ($line->qualified_base_qty !== null && (float) $line->qualified_base_qty >= 0) {
            return (float) $line->qualified_base_qty;
        }
        $receiptQty = (float) $line->receipt_qty;
        $qualifiedQty = (float) $line->qualified_qty;
        if ($receiptQty <= 0 || $qualifiedQty <= 0) return 0.0;
        $baseTotal = $line->actual_base_qty !== null ? (float) $line->actual_base_qty : (float) $line->standard_base_qty;
        return round($baseTotal * $qualifiedQty / $receiptQty, 8);
    }

    private function registerReceiptSerials(PurchaseReceipt $receipt, object $line, PurchaseReceiptItemAllocation $allocation): void
    {
        $balance = InventoryBalance::query()
            ->where('item_id', $line->item_id)
            ->where('warehouse_id', $allocation->warehouse_id)
            ->where('location_id', $allocation->location_id)
            ->where('batch_no', $line->batch_no)
            ->firstOrFail();
        $this->serials->attachPostedReceiptAllocation($receipt, $line, $allocation, $balance);
    }
}
