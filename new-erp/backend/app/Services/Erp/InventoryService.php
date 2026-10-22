<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryAdjustment;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryBatch;
use App\Models\Erp\InventoryLocationBalance;
use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\InventoryQualityEvent;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItemAllocation;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\SalesReturnReceipt;
use App\Models\Erp\SalesReturnCostAllocation;
use App\Models\Erp\SalesShipment;
use App\Models\Erp\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(
        private readonly InventorySerialApplicationService $serials,
        private readonly InventoryAdjustmentApplicationService $adjustments,
        private readonly InventoryAvailabilityService $availability,
        private readonly MaterialPhysicalService $physicalMaterials,
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

            $transaction = InventoryTransaction::create([
                'transaction_no' => $this->nextNo('ITX'),
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
                    $transactionItem = $this->applyInventoryChange($transaction, [
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
        });
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

            $transaction = InventoryTransaction::create([
                'transaction_no' => $this->nextNo('ITX'),
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
            $this->applyInventoryChange($transaction, [
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
        });
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
            $transaction = InventoryTransaction::create([
                'transaction_no' => $this->nextNo('ITX'),
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
                        $transactionItem = $this->applyInventoryChange($transaction, [
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
                $this->applyInventoryChange($transaction, [
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
            $transaction = InventoryTransaction::create([
                'transaction_no' => $this->nextNo('ITX'),
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
                    $originalReturn = $this->originalReturnProof((int) ($segment['outbound_transaction_item_id'] ?? 0), [
                        'item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id,
                        'location_id' => $line->location_id, 'batch_no' => $line->batch_no,
                    ], (float) $segment['base_qty']);
                    $transactionItem = $this->applyInventoryChange($transaction, [
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
     * 生产成品入库：一张入库记录 = 一条入库流水；按每台暂估材料成本入账（第 3 阶段领料后改实际成本）。
     * 物料需要序列号管理时，同时把每台的序列号登记为可用库存序列号。
     * @return array{transaction: InventoryTransaction, serial_ids: array<string, int>}
     */
    public function postProductionReceipt(\App\Models\Erp\MfgReceipt $receipt, \App\Models\Erp\MfgWorkOrder $workOrder, array $serialNos, ?int $operatorId = null): array
    {
        return DB::transaction(function () use ($receipt, $workOrder, $serialNos, $operatorId): array {
            if (InventoryTransaction::query()->where('source_type', 'mfg_receipt')->where('source_id', $receipt->id)
                ->where('transaction_type', 'production_receipt_inbound')->exists()) {
                throw ValidationException::withMessages(['receipt' => '这张成品入库已经过账，不能重复入库。']);
            }
            $item = Item::query()->findOrFail($workOrder->item_id);
            if ($item->materialManagementMode() === 'physical') {
                throw ValidationException::withMessages(['item' => '这个产品按实物身份管理，暂不支持从生产直接入库。']);
            }
            $transaction = InventoryTransaction::create([
                'transaction_no' => $this->nextNo('ITX'),
                'transaction_type' => 'production_receipt_inbound',
                'source_type' => 'mfg_receipt',
                'source_id' => $receipt->id,
                'source_no' => $receipt->receipt_no,
                'posting_status' => 'posted',
                'warehouse_id' => $receipt->warehouse_id,
                'location_id' => $receipt->location_id,
                'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId,
                'posted_at' => now(),
                'created_by' => $operatorId,
                'remark' => '生产成品入库，工单 '.$workOrder->work_order_no,
            ]);
            $this->applyInventoryChange($transaction, [
                'item_id' => $item->id,
                'warehouse_id' => $receipt->warehouse_id,
                'location_id' => $receipt->location_id,
                'batch_no' => $workOrder->work_order_no,
                'unit_id' => $item->unit_id,
                'change_qty' => (float) $receipt->qty,
                'unit_cost' => (float) $receipt->unit_cost,
                'cost_amount' => (float) $receipt->cost_amount,
                'cost_source_type' => $receipt->cost_source === 'actual_materials_provisional' ? 'production_actual_materials' : 'production_estimate',
                'source_type' => 'mfg_receipt',
                'source_id' => $receipt->id,
                'remark' => '工单 '.$workOrder->work_order_no.($receipt->cost_source === 'actual_materials_provisional' ? ' 成品入库（实际材料成本，制造待结算）' : ' 成品入库（暂估材料成本）'),
            ]);
            $serialIds = [];
            if ($item->serialTrackingMode() !== 'none' && $serialNos) {
                $balance = InventoryBalance::query()->where('item_id', $item->id)->where('warehouse_id', $receipt->warehouse_id)
                    ->where('location_id', $receipt->location_id)->where('batch_no', $workOrder->work_order_no)->firstOrFail();
                foreach ($serialNos as $serialNo) {
                    if (InventorySerial::query()->where('serial_no', $serialNo)->exists()) {
                        throw ValidationException::withMessages(['serial_no' => "序列号 {$serialNo} 已在库存里登记过，不能重复入库。"]);
                    }
                    $serial = InventorySerial::create([
                        'serial_no' => $serialNo, 'inventory_balance_id' => $balance->id, 'item_id' => $item->id,
                        'warehouse_id' => $receipt->warehouse_id, 'location_id' => $receipt->location_id, 'batch_no' => $workOrder->work_order_no,
                        'origin_type' => 'production', 'number_source' => 'system',
                        'source_document_type' => 'mfg_receipt', 'source_document_id' => $receipt->id, 'source_document_no' => $receipt->receipt_no,
                        'serial_status' => 'available', 'received_at' => now(), 'registered_at' => now(), 'posted_at' => now(),
                    ]);
                    InventorySerialEvent::create([
                        'inventory_serial_id' => $serial->id, 'event_type' => 'production_receipt_in', 'document_type' => 'mfg_receipt',
                        'document_id' => $receipt->id, 'document_no' => $receipt->receipt_no, 'from_status' => null, 'to_status' => 'available',
                        'warehouse_id' => $receipt->warehouse_id, 'location_id' => $receipt->location_id, 'batch_no' => $workOrder->work_order_no,
                        'event_payload' => ['work_order_no' => $workOrder->work_order_no], 'occurred_at' => now(),
                    ]);
                    $serialIds[$serialNo] = $serial->id;
                }
            }
            InventoryPostingLog::create([
                'source_type' => 'mfg_receipt',
                'source_id' => $receipt->id,
                'source_no' => $receipt->receipt_no,
                'transaction_type' => 'production_receipt_inbound',
                'transaction_id' => $transaction->id,
                'posting_status' => 'posted',
                'message' => '生产成品入库成功',
                'posted_by' => $operatorId,
                'posted_at' => now(),
            ]);
            return ['transaction' => $transaction, 'serial_ids' => $serialIds];
        }, 5);
    }

    /**
     * 工序收料：车间收到本道要用的料时才扣库存（按库存平均成本出库）。
     * @param array<int, array{balance_id:int, item_id:int, material_id:int, qty:string, serial_ids:array<int>}> $allocations
     */
    public function postProductionMaterialIssue(\App\Models\Erp\MfgMaterialIssue $issue, \App\Models\Erp\MfgWorkOrder $workOrder, array $allocations, ?int $operatorId = null): InventoryTransaction
    {
        return DB::transaction(function () use ($issue, $workOrder, $allocations, $operatorId): InventoryTransaction {
            if (InventoryTransaction::query()->where('source_type', 'mfg_material_issue')->where('source_id', $issue->id)
                ->where('transaction_type', 'production_material_issue_outbound')->exists()) {
                throw ValidationException::withMessages(['issue' => '这张收料单已经扣过库存，不能重复扣。']);
            }
            if (! $allocations) throw ValidationException::withMessages(['issue' => '收料单没有要扣的库存。']);
            $transaction = InventoryTransaction::create([
                'transaction_no' => $this->nextNo('ITX'), 'transaction_type' => 'production_material_issue_outbound',
                'source_type' => 'mfg_material_issue', 'source_id' => $issue->id, 'source_no' => $issue->issue_no,
                'posting_status' => 'posted', 'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId, 'posted_at' => now(), 'created_by' => $operatorId,
                'remark' => '工序收料，工单 '.$workOrder->work_order_no,
            ]);
            $total = '0';
            foreach ($allocations as $row) {
                $balance = InventoryBalance::query()->whereKey($row['balance_id'])->lockForUpdate()->firstOrFail();
                $item = Item::query()->findOrFail($row['item_id']);
                $quantity = number_format((float) $row['qty'], 8, '.', '');
                if ((int) $balance->item_id !== (int) $item->id || $item->materialManagementMode() === 'physical'
                    || bccomp($quantity, '0', 8) <= 0 || $this->availability->availableForOutbound($balance) + 0.00000001 < (float) $quantity) {
                    throw ValidationException::withMessages(['stock' => $item->item_name.' 可用库存不足或不能按数量领用，请刷新后重试。']);
                }
                $serialIds = array_values(array_unique(array_map('intval', $row['serial_ids'] ?? [])));
                $serials = collect();
                if ($serialIds) {
                    $serials = InventorySerial::query()->whereIn('id', $serialIds)->where('inventory_balance_id', $balance->id)
                        ->where('item_id', $item->id)->where('serial_status', 'available')->lockForUpdate()->get();
                    if ($serials->count() !== count($serialIds)) throw ValidationException::withMessages(['stock' => $item->item_name.' 的序列号已被占用，请刷新后重试。']);
                }
                if ($item->serialTrackingMode() === 'required' && bccomp($quantity, (string) count($serialIds), 8) !== 0) {
                    throw ValidationException::withMessages(['stock' => $item->item_name.' 按序列号管理，领用数量必须和序列号个数一致。']);
                }
                // 制造领料始终分配权威余额总金额，最后一次领完时承接全部尾差，避免零数量仍留成本。
                $cost = CuttingDecimal::share((string) $balance->inventory_value, (string) $balance->quantity_on_hand, $quantity);
                $fact = $this->applyInventoryChange($transaction, [
                    'item_id' => $item->id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
                    'batch_no' => $balance->batch_no, 'unit_id' => $balance->unit_id,
                    'change_qty' => bcsub('0', $quantity, 8), 'unit_cost' => (float) $balance->average_unit_cost, 'cost_amount' => bcsub('0', $cost, 4),
                    'cost_source_type' => 'production_issue_average', 'source_type' => 'mfg_material_issue', 'source_id' => $issue->id,
                    'source_item_id' => $row['material_id'] ?? null, 'remark' => '工单 '.$workOrder->work_order_no.' 工序收料',
                ]);
                $total = bcadd($total, bcsub('0', (string) $fact->cost_amount, 4), 4);
                foreach ($serials as $serial) {
                    $serial->update(['serial_status' => 'production_consumed', 'outbound_at' => now()]);
                    InventorySerialEvent::create([
                        'inventory_serial_id' => $serial->id, 'event_type' => 'production_material_issued', 'document_type' => 'mfg_material_issue',
                        'document_id' => $issue->id, 'document_no' => $issue->issue_no, 'from_status' => 'available', 'to_status' => 'production_consumed',
                        'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no,
                        'event_payload' => ['work_order_no' => $workOrder->work_order_no], 'occurred_at' => now(),
                    ]);
                }
            }
            InventoryPostingLog::create([
                'source_type' => 'mfg_material_issue', 'source_id' => $issue->id, 'source_no' => $issue->issue_no,
                'transaction_type' => 'production_material_issue_outbound', 'transaction_id' => $transaction->id,
                'posting_status' => 'posted', 'message' => '工序收料出库成功', 'posted_by' => $operatorId, 'posted_at' => now(),
            ]);
            $issue->update(['inventory_transaction_id' => $transaction->id, 'cost_amount' => $total]);
            return $transaction;
        }, 5);
    }

    /**
     * 生产退料入库：按原收料出库流水的批次和单价冲回（同一条流水分次退时最后一次承接尾差），退回原库位或库管选定的库位。
     * 按编号退的序列号恢复在库可用，挂到退入的库存行。
     */
    public function postProductionMaterialReturn(\App\Models\Erp\MfgMaterialReturn $return, \App\Models\Erp\MfgWorkOrder $workOrder, ?int $operatorId = null): InventoryTransaction
    {
        return DB::transaction(function () use ($return, $workOrder, $operatorId): InventoryTransaction {
            if (InventoryTransaction::query()->where('source_type', 'mfg_material_return')->where('source_id', $return->id)
                ->where('transaction_type', 'production_material_return_inbound')->exists()) {
                throw ValidationException::withMessages(['return' => '这张退料单已经入过库，不能重复入库。']);
            }
            $lines = \App\Models\Erp\MfgMaterialReturnLine::query()->where('return_id', $return->id)->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) throw ValidationException::withMessages(['return' => '退料单没有明细。']);
            $transaction = InventoryTransaction::create([
                'transaction_no' => $this->nextNo('ITX'), 'transaction_type' => 'production_material_return_inbound',
                'source_type' => 'mfg_material_return', 'source_id' => $return->id, 'source_no' => $return->return_no,
                'posting_status' => 'posted', 'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId, 'posted_at' => now(), 'created_by' => $operatorId,
                'remark' => '生产退料，工单 '.$workOrder->work_order_no,
            ]);
            $total = '0.0000';
            foreach ($lines as $line) {
                $source = InventoryTransactionItem::query()->with('transaction')->whereKey($line->source_transaction_item_id)->lockForUpdate()->first();
                if (! $source || $source->transaction?->transaction_type !== 'production_material_issue_outbound' || $source->source_type !== 'mfg_material_issue'
                    || (int) $source->source_id !== (int) $line->issue_id || (int) $source->item_id !== (int) $line->component_item_id) {
                    throw ValidationException::withMessages(['return' => $line->component_item_name.' 找不到对应的原收料出库流水，不能退料。']);
                }
                $returned = \App\Models\Erp\MfgMaterialReturnLine::query()->where('source_transaction_item_id', $source->id)->where('return_id', '!=', $return->id)
                    ->whereHas('materialReturn', fn ($q) => $q->where('status', 'approved'))->selectRaw('COALESCE(SUM(qty), 0) AS q, COALESCE(SUM(cost_amount), 0) AS c')->first();
                $remainingQty = bcsub(number_format(abs((float) $source->change_qty), 8, '.', ''), (string) $returned->q, 8);
                $remainingCost = bcsub(bcsub('0', (string) $source->cost_amount, 4), (string) $returned->c, 4);
                $quantity = number_format((float) $line->qty, 8, '.', '');
                if (bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $remainingQty, 8) > 0) {
                    throw ValidationException::withMessages(['return' => $line->component_item_name.' 退料数量超过原收料未退数量，请刷新后重试。']);
                }
                $cost = CuttingDecimal::share($remainingCost, $remainingQty, $quantity);
                $warehouseId = (int) ($return->target_location_id ? $return->target_warehouse_id : $source->warehouse_id);
                $locationId = (int) ($return->target_location_id ?: $source->location_id);
                $change = [
                    'item_id' => $source->item_id, 'warehouse_id' => $warehouseId, 'location_id' => $locationId,
                    'batch_no' => $source->batch_no, 'unit_id' => $source->unit_id,
                    'change_qty' => $quantity, 'unit_cost' => (float) bcdiv($cost, $quantity, 4), 'cost_amount' => $cost,
                    'cost_source_type' => 'production_return_original_cost', 'source_type' => 'mfg_material_return', 'source_id' => $return->id,
                    'source_item_id' => $line->id, 'remark' => '工单 '.$workOrder->work_order_no.' 生产退料（原单 '.$source->transaction_no.'）',
                ];
                if ($source->material_lot_id) $change['material_lot_id'] = $source->material_lot_id;
                $this->applyInventoryChange($transaction, $change);
                $total = bcadd($total, $cost, 4);
                $line->update(['warehouse_id' => $warehouseId, 'location_id' => $locationId, 'unit_cost' => bcdiv($cost, $quantity, 4), 'cost_amount' => $cost]);
                if ($line->inventory_serial_id) {
                    $serial = InventorySerial::query()->whereKey($line->inventory_serial_id)->lockForUpdate()->first();
                    if (! $serial || $serial->serial_status !== 'production_consumed' || (int) $serial->item_id !== (int) $source->item_id) {
                        throw ValidationException::withMessages(['return' => '编号 '.$line->serial_no.' 当前不是生产已领状态，不能退料。']);
                    }
                    $balance = InventoryBalance::query()->where('item_id', $source->item_id)->where('warehouse_id', $warehouseId)
                        ->where('location_id', $locationId)->where('batch_no', $source->batch_no)->firstOrFail();
                    $serial->update(['serial_status' => 'available', 'inventory_balance_id' => $balance->id, 'warehouse_id' => $warehouseId,
                        'location_id' => $locationId, 'outbound_at' => null]);
                    InventorySerialEvent::create([
                        'inventory_serial_id' => $serial->id, 'event_type' => 'production_material_returned', 'document_type' => 'mfg_material_return',
                        'document_id' => $return->id, 'document_no' => $return->return_no, 'from_status' => 'production_consumed', 'to_status' => 'available',
                        'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'batch_no' => $source->batch_no,
                        'event_payload' => ['work_order_no' => $workOrder->work_order_no, 'unit_serial_no' => $line->unit_serial_no], 'occurred_at' => now(),
                    ]);
                }
            }
            InventoryPostingLog::create([
                'source_type' => 'mfg_material_return', 'source_id' => $return->id, 'source_no' => $return->return_no,
                'transaction_type' => 'production_material_return_inbound', 'transaction_id' => $transaction->id,
                'posting_status' => 'posted', 'message' => '生产退料入库成功', 'posted_by' => $operatorId, 'posted_at' => now(),
            ]);
            $return->update(['inventory_transaction_id' => $transaction->id, 'cost_amount' => $total]);
            return $transaction;
        }, 5);
    }

    /** 开料整根／整张领入：只接受已锁定批次保存的真实来源，不开放普通数量接口扣板。 */
    public function postManufacturingCuttingIssue(\App\Models\Erp\MfgCuttingBatch $batch, \App\Models\Erp\MfgWorkOrder $workOrder, ?int $operatorId = null): array
    {
        return DB::transaction(function () use ($batch, $workOrder, $operatorId) {
            $batch = \App\Models\Erp\MfgCuttingBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ((int) $batch->work_order_id !== (int) $workOrder->id || $batch->status !== 'issued' || $batch->inventory_issue_transaction_id) {
                throw ValidationException::withMessages(['cutting' => '开料批次已领入或不属于当前工单。']);
            }
            $transaction = InventoryTransaction::create([
                'transaction_no' => $this->nextNo('ITX'), 'transaction_type' => 'mfg_cutting_issue_outbound',
                'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id, 'source_no' => $batch->batch_no,
                'posting_status' => 'posted', 'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId, 'posted_at' => now(), 'created_by' => $operatorId,
                'remark' => '工单 '.$workOrder->work_order_no.' 开料领入',
            ]);
            $lines = [];
            foreach ($batch->input_lines as $input) {
                $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($input['inventory_balance_id']);
                $item = Item::query()->lockForUpdate()->findOrFail($input['item_id']);
                $qty = CuttingDecimal::value($input['qty']);
                $validBalance = $this->availability->outboundBalanceQuery([(int) $item->id])->where('erp_inventory_balances.id', $balance->id)->exists();
                $available = min((float) $balance->quantity_available, $this->availability->calculate((float) $balance->quantity_on_hand,
                    (float) $balance->quantity_locked, (float) $balance->quantity_defective, (float) $balance->quantity_pending));
                $lot = $balance->material_lot_id ? DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->lockForUpdate()->first() : null;
                if (! $validBalance || (int) $balance->item_id !== (int) $item->id || $available + 0.00000001 < (float) $qty
                    || ($balance->material_lot_id && ! $lot)
                    || ($lot && ((int) $lot->item_id !== (int) $item->id || $lot->configuration_id || $lot->stage_id || ! in_array($lot->material_form, ['FULL_STOCK', 'STANDARD_LENGTH', 'REMNANT'], true)))) {
                    throw ValidationException::withMessages(['sources' => '所选开料来源不可用或数量已变化，请重新选料。']);
                }
                $physical = null;
                if ($item->materialManagementMode() === 'physical') {
                    $physical = $this->physicalMaterials->assertWarehousePhysical((int) ($input['physical_material_id'] ?? 0), (int) $item->id, (int) $balance->id);
                    $this->physicalMaterials->assertPhysicalNotInOpenDocument((int) $physical->id);
                    if (bccomp($qty, '1', 8) !== 0 || bccomp((string) $physical->total_cost, (string) $input['cost_amount'], 4) !== 0) {
                        throw ValidationException::withMessages(['sources' => '开料板材身份、整张数量或来源成本不一致。']);
                    }
                    $cost = (string) $physical->total_cost;
                } else {
                    if ($input['physical_material_id'] ?? null) throw ValidationException::withMessages(['sources' => '普通管材来源不能冒用板材实物身份。']);
                    if ($item->serialTrackingMode() !== 'none') throw ValidationException::withMessages(['sources' => '按序列号管理的原料须先绑定具体序列号，当前开料入口不能按普通数量耗用。']);
                    $cost = CuttingDecimal::share((string) $balance->inventory_value, (string) $balance->quantity_on_hand, $qty);
                    if (bccomp($cost, (string) $input['cost_amount'], 4) !== 0) throw ValidationException::withMessages(['sources' => '开料来源成本已变化，请重新确认。']);
                }
                $fact = $this->applyInventoryChange($transaction, [
                    'item_id' => $item->id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
                    'batch_no' => $balance->batch_no, 'unit_id' => $balance->unit_id, 'material_lot_id' => $balance->material_lot_id,
                    'change_qty' => bcsub('0', $qty, 8), 'cost_amount' => bcsub('0', $cost, 4),
                    'unit_cost' => bcdiv($cost, $qty, 8), 'cost_source_type' => $physical ? 'mfg_cutting_physical_total' : 'mfg_cutting_average',
                    'physical_operation' => $physical ? 'mfg_cutting_issue' : null, 'physical_material_id' => $physical?->id,
                    'cutting_source_key' => $input['source_key'], 'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id,
                    'remark' => '开料来源 '.$input['source_key'],
                ]);
                $lines[$input['source_key']] = ['transaction_item_id' => (int) $fact->id, 'cost_amount' => bcsub('0', (string) $fact->cost_amount, 4)];
            }
            InventoryPostingLog::create([
                'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id, 'source_no' => $batch->batch_no,
                'transaction_type' => $transaction->transaction_type, 'transaction_id' => $transaction->id,
                'posting_status' => 'posted', 'message' => '开料原料领入成功', 'posted_by' => $operatorId, 'posted_at' => now(),
            ]);
            $batch->update(['inventory_issue_transaction_id' => $transaction->id]);
            return ['transaction' => $transaction, 'lines' => $lines];
        }, 5);
    }

    /** 只退回人工确认的真实余料；净耗留在制造成本，禁止把整张／整根成本再记一次。 */
    public function postManufacturingCuttingSettlement(\App\Models\Erp\MfgCuttingBatch $batch, \App\Models\Erp\MfgWorkOrder $workOrder, ?int $operatorId = null): array
    {
        return DB::transaction(function () use ($batch, $workOrder, $operatorId) {
            $batch = \App\Models\Erp\MfgCuttingBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ((int) $batch->work_order_id !== (int) $workOrder->id || $batch->status !== 'issued'
                || ! $batch->inventory_issue_transaction_id || $batch->inventory_return_transaction_id) {
                throw ValidationException::withMessages(['cutting' => '这张开料批次不能再次结算。']);
            }
            $positive = collect($batch->remnants)->filter(fn ($row) => bccomp((string) $row['measure_qty'], '0', 8) > 0);
            if ($positive->isEmpty()) return ['transaction' => null, 'lines' => []];
            $transaction = InventoryTransaction::create([
                'transaction_no' => $this->nextNo('ITX'), 'transaction_type' => 'mfg_cutting_remnant_inbound',
                'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id, 'source_no' => $batch->batch_no,
                'posting_status' => 'posted', 'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId, 'posted_at' => now(), 'created_by' => $operatorId,
                'remark' => '工单 '.$workOrder->work_order_no.' 开料余料入库',
            ]);
            $inputs = collect($batch->input_lines)->keyBy('source_key');
            $lines = [];
            foreach ($positive as $index => $remnant) {
                $input = $inputs->get($remnant['source_key']);
                if (! $input) throw ValidationException::withMessages(['remnants' => '余料缺少真实开料来源。']);
                $item = Item::query()->lockForUpdate()->findOrFail($input['item_id']);
                $lotId = DB::table('erp_material_lots')->insertGetId([
                    'lot_no' => app(DocumentNumberService::class)->next('material_lot', 'ML'), 'item_id' => $item->id,
                    'material_form' => 'REMNANT', 'cut_length_mm' => $input['mode'] === 'length' ? bcmul($remnant['measure_qty'], '1000', 2) : null,
                    'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id, 'parent_lot_id' => $input['material_lot_id'] ?: null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $qty = in_array($input['unit_kind'], ['root', 'sheet'], true) ? '1.00000000' : $remnant['measure_qty'];
                $batchNo = $batch->batch_no.'-R'.($index + 1);
                $fact = $this->applyInventoryChange($transaction, [
                    'item_id' => $item->id, 'warehouse_id' => $remnant['warehouse_id'], 'location_id' => $remnant['location_id'],
                    'batch_no' => $batchNo, 'unit_id' => $input['unit_id'], 'material_lot_id' => $lotId,
                    'change_qty' => $qty, 'cost_amount' => $remnant['cost_amount'], 'unit_cost' => bcdiv($remnant['cost_amount'], $qty, 8),
                    'cost_source_type' => 'mfg_cutting_remnant', 'physical_operation' => $input['mode'] === 'sheet' ? 'mfg_cutting_remnant' : null,
                    'physical_material_id' => $input['physical_material_id'], 'cutting_source_key' => $input['source_key'],
                    'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id, 'remark' => '开料余料来源 '.$input['source_key'],
                ]);
                $balance = InventoryBalance::query()->where('item_id', $item->id)->where('warehouse_id', $remnant['warehouse_id'])
                    ->where('location_id', $remnant['location_id'])->where('batch_no', $batchNo)->lockForUpdate()->firstOrFail();
                $physicalId = null;
                if ($input['mode'] === 'sheet') {
                    $physicalId = $this->physicalMaterials->createManufacturingRemnant($batch, $input, $remnant, $balance, $fact, $operatorId ?? 0);
                }
                $lines[$input['source_key']] = ['transaction_item_id' => (int) $fact->id, 'inventory_balance_id' => (int) $balance->id,
                    'material_lot_id' => $lotId, 'batch_no' => $batchNo, 'physical_material_id' => $physicalId, 'qty' => $qty];
            }
            InventoryPostingLog::create([
                'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id, 'source_no' => $batch->batch_no,
                'transaction_type' => $transaction->transaction_type, 'transaction_id' => $transaction->id,
                'posting_status' => 'posted', 'message' => '开料余料入库成功', 'posted_by' => $operatorId, 'posted_at' => now(),
            ]);
            $batch->update(['inventory_return_transaction_id' => $transaction->id]);
            return ['transaction' => $transaction, 'lines' => $lines];
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

    public function postAdjustment(int $adjustmentId, ?int $operatorId = null): InventoryTransaction
    {
        return DB::transaction(function () use ($adjustmentId, $operatorId) {
            $adjustment = InventoryAdjustment::with(['items.item', 'items.serials', 'items.physicalEntries'])->lockForUpdate()->findOrFail($adjustmentId);
            if ($adjustment->adjustment_status === 'posted') {
                throw ValidationException::withMessages(['adjustment' => '该调整单已过账，不能重复过账。']);
            }
            if ($adjustment->adjustment_status !== 'submitted') {
                throw ValidationException::withMessages(['adjustment' => '只有已提交的调整单可以确认过账。']);
            }
            if (InventoryTransaction::where('source_type', 'inventory_adjustment')->where('source_id', $adjustment->id)->where('transaction_type', 'manual_adjustment')->exists()) {
                throw ValidationException::withMessages(['adjustment' => '该调整单已过账，不能重复过账。']);
            }

            foreach ($adjustment->items as $line) {
                if ((float) $line->change_qty === 0.0) {
                    throw ValidationException::withMessages(['adjustment' => '调整数量必须大于 0，不能生成 0 数量库存事务。']);
                }
                if (!$line->item_id || !$line->warehouse_id || !$line->location_id || !$line->batch_no) {
                    throw ValidationException::withMessages(['adjustment' => '调整明细必须包含物料、仓库、库位和批次。']);
                }
                $balance = InventoryBalance::where('item_id', $line->item_id)
                    ->where('warehouse_id', $line->warehouse_id)
                    ->where('location_id', $line->location_id)
                    ->where('batch_no', $line->batch_no)
                    ->lockForUpdate()
                    ->first();
                if (!$balance) {
                    throw ValidationException::withMessages(['stock' => '库存调整必须基于真实库存余额行。']);
                }
                if ((float) $line->change_qty < 0) {
                    if ((float) $balance->quantity_on_hand + (float) $line->change_qty < 0 || (float) $balance->quantity_available + (float) $line->change_qty < 0) {
                        throw ValidationException::withMessages(['stock' => '当前库存不足，不能执行该调整。']);
                    }
                }
                $this->adjustments->validateStoredLine($line, $balance, $adjustment->id);
            }

            $transaction = InventoryTransaction::create([
                'transaction_no' => $this->nextNo('ITX'),
                'transaction_type' => 'manual_adjustment',
                'source_type' => 'inventory_adjustment',
                'source_id' => $adjustment->id,
                'source_no' => $adjustment->adjustment_no,
                'posting_status' => 'posted',
                'warehouse_id' => $adjustment->items->first()->warehouse_id,
                'location_id' => $adjustment->items->first()->location_id,
                'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId,
                'posted_at' => now(),
                'created_by' => $operatorId,
                // 流水备注给人看，原因编码仍保存在调整单 reason 字段。
                'remark' => InventoryAdjustmentApplicationService::reasonLabel($adjustment->reason),
            ]);

            foreach ($adjustment->items as $line) {
                $balance = InventoryBalance::query()
                    ->where('item_id', $line->item_id)
                    ->where('warehouse_id', $line->warehouse_id)
                    ->where('location_id', $line->location_id)
                    ->where('batch_no', $line->batch_no)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($line->item?->materialManagementMode() === 'physical') {
                    foreach ($line->physicalEntries as $entry) {
                        if ($entry->direction === 'increase') {
                            $transactionItem = $this->applyInventoryChange($transaction, [
                                'item_id' => $line->item_id,
                                'warehouse_id' => $line->warehouse_id,
                                'location_id' => $line->location_id,
                                'batch_no' => $line->batch_no,
                                'unit_id' => $line->unit_id ?: $line->item?->unit_id,
                                'change_qty' => '1',
                                'unit_cost' => (float) $entry->total_cost,
                                'cost_amount' => (string) $entry->total_cost,
                                'source_type' => 'inventory_adjustment',
                                'source_id' => $adjustment->id,
                                'source_item_id' => $line->id,
                                'physical_operation' => 'adjustment_increase',
                                'physical_entry_id' => $entry->id,
                                'remark' => $line->remark,
                            ]);
                            $this->physicalMaterials->createAdjustmentIncrease($entry, $line, $balance, $transactionItem);
                        } else {
                            $physical = $this->physicalMaterials->assertWarehousePhysical(
                                (int) $entry->physical_material_id,
                                (int) $line->item_id,
                                (int) $balance->id,
                            );
                            $transactionItem = $this->applyInventoryChange($transaction, [
                                'item_id' => $line->item_id,
                                'warehouse_id' => $line->warehouse_id,
                                'location_id' => $line->location_id,
                                'batch_no' => $line->batch_no,
                                'unit_id' => $line->unit_id ?: $line->item?->unit_id,
                                'change_qty' => '-1',
                                'unit_cost' => (float) $physical->total_cost,
                                'cost_amount' => bcsub('0', (string) $physical->total_cost, 4),
                                'source_type' => 'inventory_adjustment',
                                'source_id' => $adjustment->id,
                                'source_item_id' => $line->id,
                                'physical_operation' => 'adjustment_decrease',
                                'physical_entry_id' => $entry->id,
                                'physical_material_id' => $physical->id,
                                'remark' => $line->remark,
                            ]);
                            $this->physicalMaterials->completeAdjustmentDecrease($entry, $line, $transactionItem);
                        }
                    }
                    continue;
                }
                $this->applyInventoryChange($transaction, [
                    'item_id' => $line->item_id,
                    'warehouse_id' => $line->warehouse_id,
                    'location_id' => $line->location_id,
                    'batch_no' => $line->batch_no,
                    'unit_id' => $line->unit_id ?: $line->item?->unit_id,
                    'change_qty' => (float) $line->change_qty,
                    'unit_cost' => (float) $balance->average_unit_cost,
                    'source_type' => 'inventory_adjustment',
                    'source_id' => $adjustment->id,
                    'source_item_id' => $line->id,
                    'remark' => $line->remark,
                ]);
                $this->applyAdjustmentSerials($adjustment, $line, $balance);
            }

            $adjustment->update(['adjustment_status' => 'posted', 'posted_by' => $operatorId, 'posted_at' => now()]);
            InventoryPostingLog::create([
                'source_type' => 'inventory_adjustment',
                'source_id' => $adjustment->id,
                'source_no' => $adjustment->adjustment_no,
                'transaction_type' => 'manual_adjustment',
                'transaction_id' => $transaction->id,
                'posting_status' => 'posted',
                'message' => '手工库存调整过账成功',
                'posted_at' => now(),
            ]);

            return $transaction->fresh(['items.item', 'items.warehouse', 'items.location']);
        });
    }

    private function applyAdjustmentSerials(InventoryAdjustment $adjustment, $line, InventoryBalance $balance): void
    {
        foreach ($line->serials as $entry) {
            if ($entry->direction === 'increase') {
                $serial = InventorySerial::create([
                    'inventory_balance_id' => $balance->id,
                    'item_id' => $line->item_id,
                    'warehouse_id' => $line->warehouse_id,
                    'location_id' => $line->location_id,
                    'batch_no' => $line->batch_no,
                    'origin_type' => 'manual_adjustment',
                    'number_source' => $entry->number_source ?: 'manual',
                    'source_document_type' => 'inventory_adjustment',
                    'source_document_id' => $adjustment->id,
                    'source_document_no' => $adjustment->adjustment_no,
                    'serial_status' => 'available',
                    'received_at' => now(),
                    'registered_at' => now(),
                    'posted_at' => now(),
                    'serial_no' => $entry->serial_no,
                ]);
                $entry->update(['inventory_serial_id' => $serial->id]);
                $this->recordAdjustmentSerialEvent($serial, $adjustment, 'manual_adjustment_in', null, 'available');
                continue;
            }

            $serial = InventorySerial::query()->whereKey($entry->inventory_serial_id)->lockForUpdate()->firstOrFail();
            $serial->update(['serial_status' => 'adjusted_out', 'outbound_at' => now()]);
            $this->recordAdjustmentSerialEvent($serial, $adjustment, 'manual_adjustment_out', 'available', 'adjusted_out');
        }
    }

    private function recordAdjustmentSerialEvent(
        InventorySerial $serial,
        InventoryAdjustment $adjustment,
        string $eventType,
        ?string $fromStatus,
        string $toStatus,
    ): void {
        InventorySerialEvent::create([
            'inventory_serial_id' => $serial->id,
            'event_type' => $eventType,
            'document_type' => 'inventory_adjustment',
            'document_id' => $adjustment->id,
            'document_no' => $adjustment->adjustment_no,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'warehouse_id' => $serial->warehouse_id,
            'location_id' => $serial->location_id,
            'batch_no' => $serial->batch_no,
            'event_payload' => ['reason' => $adjustment->reason, 'remark' => $adjustment->remark],
            'occurred_at' => now(),
        ]);
    }

    public function postInventoryQualityOutbound(
        InventoryQualityEvent $qualityEvent,
        string $sourceNo,
        ?string $operatorName = null,
    ): InventoryTransaction {
        return DB::transaction(function () use ($qualityEvent, $sourceNo, $operatorName): InventoryTransaction {
            $event = InventoryQualityEvent::query()->lockForUpdate()->findOrFail($qualityEvent->id);
            $existing = InventoryTransaction::query()
                ->where('transaction_type', 'inventory_quality_outbound')
                ->where('source_type', 'inventory_quality_event')
                ->where('source_id', $event->id)
                ->first();
            if ($existing) return $existing->fresh(['items.item', 'items.warehouse', 'items.location']);

            $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($event->inventory_balance_id);
            $quantity = (float) $event->issue_qty;
            if ($quantity <= 0 || $quantity > (float) $balance->quantity_on_hand + 0.00000001) {
                throw ValidationException::withMessages(['stock' => '质量处理原品库存不足，不能登记退回供应商。']);
            }
            $transaction = InventoryTransaction::create([
                'transaction_no' => $this->nextNo('ITX'),
                'transaction_type' => 'inventory_quality_outbound',
                'source_type' => 'inventory_quality_event',
                'source_id' => $event->id,
                'source_no' => $sourceNo,
                'posting_status' => 'posted',
                'warehouse_id' => $event->warehouse_id,
                'location_id' => $event->location_id,
                'transaction_date' => now()->toDateString(),
                'posted_at' => now(),
                'posted_by' => null,
                'remark' => '库存质量换货原品退回供应商',
            ]);
            $this->applyInventoryChange($transaction, [
                'item_id' => $event->item_id,
                'warehouse_id' => $event->warehouse_id,
                'location_id' => $event->location_id,
                'batch_no' => $event->batch_no,
                'unit_id' => $event->unit_id,
                'change_qty' => -$quantity,
                'unit_cost' => (float) $balance->average_unit_cost,
                'source_type' => 'inventory_quality_event',
                'source_id' => $event->id,
                'source_item_id' => $event->source_receipt_item_id,
                'remark' => "质量事件 {$event->event_no} 原品退回供应商",
            ]);
            return $transaction->fresh(['items.item', 'items.warehouse', 'items.location']);
        }, 5);
    }

    /** 兼容历史整单过账的只读回查；新单只按正式发运批次扣库。 */
    public function postSalesShipment(SalesShipment $shipment, string $operator): InventoryTransaction
    {
        $existing = InventoryTransaction::query()->where('source_type', 'sales_shipment')->where('source_id', $shipment->id)
            ->where('transaction_type', 'sales_shipment_outbound')->where('posting_status', 'posted')->first();
        if ($existing) return $existing->fresh(['items.item', 'items.warehouse', 'items.location']);
        // 旧调用仅能回读已经发生的出库，新增销售扣库必须由正式发运批次承接。
        throw ValidationException::withMessages(['shipment' => '请通过确认发运或确认提货办理正式销售出库。']);
    }

    /** 待发/返修是内部移位：成对库存流水和同一预留同时迁移，销售数量及成本不结转。 */
    public function moveSalesShipmentLine(\App\Models\Erp\SalesShipmentLine $line, int $targetLocationId, string $action, object $actor): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Shipment movement requires an application transaction.');
        $purpose = match ($action) { 'stage' => 'shipping_staging', 'repair' => 'repair', 'repair_return' => 'finished', 'return' => null,
            default => throw new \LogicException('Unknown shipment movement.') };
        $target = Location::query()->whereKey($targetLocationId)->whereIn('status', ['enabled', 'active'])
            ->whereHas('warehouse', fn ($q) => $q->whereIn('status', ['enabled', 'active']))
            ->when($purpose !== null, fn ($q) => $q->where('location_purpose', $purpose))->lockForUpdate()->first();
        if (! $target) throw ValidationException::withMessages(['location_id' => '目标库位已停用或用途不符。']);
        $reservation = $line->reservation()->lockForUpdate()->firstOrFail();
        $source = InventoryBalance::query()->whereKey($reservation->inventory_balance_id)->where('item_id', $line->item_id)->lockForUpdate()->firstOrFail();
        $item = Item::query()->findOrFail($line->item_id);
        if ($item->materialManagementMode() !== 'quantity') throw ValidationException::withMessages(['stock' => '发货设备移位仅接收数量库存，实物板材须使用原实物业务。']);
        $qty = (float) $line->base_qty;
        $held = $reservation->reservation_status === 'converted_to_shipment' ? $qty : 0.0;
        if ($qty <= 0 || abs((float) $reservation->reserved_qty - $qty) > 0.00000001
            || (int) $source->warehouse_id !== (int) $line->warehouse_id || (int) $source->location_id !== (int) $line->location_id
            || $source->batch_no !== $line->batch_no || (float) $source->quantity_on_hand + 0.00000001 < $qty
            || (float) $source->quantity_locked + 0.00000001 < $held) throw ValidationException::withMessages(['stock' => '设备的原库存或本次发货占用已变化。']);
        if (! in_array($reservation->reservation_status, ['converted_to_shipment', 'released'], true)) throw ValidationException::withMessages(['stock' => '设备预留状态不允许移位。']);
        $serialIds = array_map('intval', $line->serial_snapshot['inventory_serial_ids'] ?? []);
        $serials = InventorySerial::query()->whereIn('id', $serialIds)->where('inventory_balance_id', $source->id)->where('item_id', $line->item_id)->lockForUpdate()->get();
        if ($serials->count() !== count($serialIds) || $serials->contains(fn ($s) => ! in_array($s->serial_status, ['shipment_reserved', 'staged', 'repair'], true))) throw ValidationException::withMessages(['serial_ids' => '设备身份或当前位置已变化。']);
        $origin = $line->preparation_snapshot ?? ['inventory_balance_id' => (int) $source->id, 'warehouse_id' => (int) $source->warehouse_id,
            'location_id' => (int) $source->location_id, 'batch_no' => $source->batch_no];
        if ($action === 'stage') $origin['staging_location_id'] = (int) $target->id;
        $same = (int) $source->location_id === (int) $target->id;
        $wasDefective = $serials->isNotEmpty() && $serials->every(fn ($s) => $s->serial_status === 'repair');
        $eligible = $this->availability->outboundBalanceQuery([(int) $line->item_id])->where('erp_inventory_balances.id', $source->id)->lockForUpdate()->first();
        if (! $eligible) throw ValidationException::withMessages(['stock' => '原仓库、库位或批次已停用或过期，不能继续移位。']);
        if ($action !== 'repair_return' && $this->availability->availableForOutbound($source, $held) + 0.00000001 < $qty)
            throw ValidationException::withMessages(['stock' => '本次设备存在不良、待定或其他占用，不能按正常设备移位。']);
        if ($action === 'repair_return' && ! $wasDefective) throw ValidationException::withMessages(['stock' => '原设备不在正式返修库存中，不能当作返修返回。']);
        $targetBalance = $source;
        $transaction = null;
        if (! $same) {
            $sourceLocation = InventoryLocationBalance::query()->where('item_id', $line->item_id)->where('warehouse_id', $source->warehouse_id)->where('location_id', $source->location_id)->lockForUpdate()->firstOrFail();
            if ((float) $sourceLocation->quantity_locked + 0.00000001 < $held || ($wasDefective && ((float) $source->quantity_defective + 0.00000001 < $qty
                || (float) $sourceLocation->quantity_defective + 0.00000001 < $qty))) throw ValidationException::withMessages(['stock' => '原库位占用或返修不良数量不一致，请核对后再移位。']);
            $source->update(['quantity_locked' => (float) $source->quantity_locked - $held,
                'quantity_defective' => max(0, (float) $source->quantity_defective - ($wasDefective ? $qty : 0))]);
            $sourceLocation->update(['quantity_locked' => (float) $sourceLocation->quantity_locked - $held,
                'quantity_defective' => max(0, (float) $sourceLocation->quantity_defective - ($wasDefective ? $qty : 0))]);
            $movement = \App\Models\Erp\SalesShipmentLog::create(['shipment_id' => $line->shipment_id, 'action' => 'internal_move',
                'operator' => $actor->nickname ?: $actor->username, 'content' => '发货明细 '.$line->id.' '.$action.'：'.$source->location_id.' → '.$target->id]);
            $transaction = InventoryTransaction::create(['transaction_no' => $this->nextNo('ITX'), 'transaction_type' => 'shipment_internal_transfer',
                'source_type' => 'sales_shipment_movement', 'source_id' => $movement->id, 'source_no' => $line->shipment->shipment_no,
                'posting_status' => 'posted', 'warehouse_id' => $source->warehouse_id, 'location_id' => $source->location_id,
                'transaction_date' => now()->toDateString(), 'posted_by' => (int) $actor->legacy_id, 'posted_at' => now()]);
            $cost = CuttingDecimal::share((string) $source->inventory_value, (string) $source->quantity_on_hand, (string) $line->base_qty);
            $common = ['item_id' => $line->item_id, 'batch_no' => $source->batch_no, 'unit_id' => $line->unit_id,
                'unit_cost' => $source->average_unit_cost, 'material_lot_id' => $source->material_lot_id,
                'cost_source_type' => 'shipment_internal_transfer', 'source_type' => 'sales_shipment_movement', 'source_id' => $movement->id, 'source_item_id' => $line->id];
            $outbound = $this->applyInventoryChange($transaction, $common + ['warehouse_id' => $source->warehouse_id, 'location_id' => $source->location_id,
                'change_qty' => -$qty, 'cost_amount' => bcsub('0', $cost, 4)]);
            $this->applyInventoryChange($transaction, $common + ['warehouse_id' => $target->warehouse_id, 'location_id' => $target->id,
                'change_qty' => $qty, 'cost_amount' => bcsub('0', (string) $outbound->cost_amount, 4)]);
            $targetBalance = InventoryBalance::query()->where('item_id', $line->item_id)->where('warehouse_id', $target->warehouse_id)
                ->where('location_id', $target->id)->where('batch_no', $source->batch_no)->lockForUpdate()->firstOrFail();
            $targetLoc = InventoryLocationBalance::query()->where('item_id', $line->item_id)->where('warehouse_id', $target->warehouse_id)->where('location_id', $target->id)->lockForUpdate()->firstOrFail();
            foreach ([$targetBalance, $targetLoc] as $balance) {
                $locked = (float) $balance->quantity_locked + $held;
                $defective = (float) $balance->quantity_defective + ($action === 'repair' ? $qty : 0);
                $balance->update(['quantity_locked' => $locked, 'quantity_defective' => $defective,
                    'quantity_available' => $this->availability->calculate((float) $balance->quantity_on_hand, $locked, $defective, (float) $balance->quantity_pending)]);
            }
        } elseif ($wasDefective && $action === 'repair_return') {
            foreach ([$source, InventoryLocationBalance::query()->where('item_id', $line->item_id)->where('warehouse_id', $source->warehouse_id)->where('location_id', $source->location_id)->lockForUpdate()->firstOrFail()] as $balance) {
                $defective = max(0, (float) $balance->quantity_defective - $qty);
                $balance->update(['quantity_defective' => $defective, 'quantity_available' => $this->availability->calculate((float) $balance->quantity_on_hand,
                    (float) $balance->quantity_locked, $defective, (float) $balance->quantity_pending)]);
            }
        }
        $reservation->update(['inventory_balance_id' => $targetBalance->id, 'warehouse_id' => $target->warehouse_id, 'location_id' => $target->id]);
        $line->update(['warehouse_id' => $target->warehouse_id, 'location_id' => $target->id, 'preparation_snapshot' => $origin]);
        foreach ($serials as $serial) {
            $status = $action === 'repair' ? 'repair' : ($action === 'stage' ? 'staged' : ($held > 0 ? 'shipment_reserved' : 'available'));
            $before = $serial->serial_status;
            $serial->update(['inventory_balance_id' => $targetBalance->id, 'warehouse_id' => $target->warehouse_id, 'location_id' => $target->id, 'serial_status' => $status]);
            InventorySerialEvent::create(['inventory_serial_id' => $serial->id, 'event_type' => 'shipment_'.$action, 'document_type' => 'sales_shipment',
                'document_id' => $line->shipment_id, 'document_no' => $line->shipment->shipment_no, 'from_status' => $before, 'to_status' => $status,
                'warehouse_id' => $target->warehouse_id, 'location_id' => $target->id, 'batch_no' => $source->batch_no,
                'operator_id' => (int) $actor->legacy_id, 'event_payload' => ['shipment_line_id' => $line->id, 'from_location_id' => $source->location_id,
                    'to_location_id' => $target->id, 'inventory_transaction_id' => $transaction?->id], 'occurred_at' => now()]);
        }
    }

    /** 一次发运批次是唯一过账来源；明细仍归原发货行，退货和成本沿用原正式依据。 */
    public function postSalesShipmentDispatch(\App\Models\Erp\ShipmentDispatch $dispatch, SalesShipment $shipment, array $lineIds, object $actor): InventoryTransaction
    {
        $existing = InventoryTransaction::query()->where('source_type', 'sales_shipment_dispatch')->where('source_id', $dispatch->id)->where('transaction_type', 'sales_shipment_outbound')->first();
        if ($existing) return $existing;
        $lines = $shipment->lines()->whereIn('id', $lineIds)->with('orderLine.item')->orderBy('id')->lockForUpdate()->get();
        if ($lines->count() !== count($lineIds) || $lines->isEmpty()) throw ValidationException::withMessages(['shipment' => '本次发运明细不完整。']);
        $first = $lines->first();
        $transaction = InventoryTransaction::create(['transaction_no' => $this->nextNo('ITX'), 'transaction_type' => 'sales_shipment_outbound',
            'source_type' => 'sales_shipment_dispatch', 'source_id' => $dispatch->id, 'source_no' => $shipment->shipment_no,
            'posting_status' => 'posted', 'warehouse_id' => $first->warehouse_id, 'location_id' => $first->location_id,
            'transaction_date' => now()->toDateString(), 'posted_by' => (int) $actor->legacy_id, 'posted_at' => now()]);
        foreach ($lines as $line) {
            $reservation = $line->reservation()->lockForUpdate()->firstOrFail();
            $balance = InventoryBalance::query()->whereKey($reservation->inventory_balance_id)->where('item_id', $line->item_id)
                ->where('warehouse_id', $line->warehouse_id)->where('location_id', $line->location_id)->where('batch_no', $line->batch_no)->lockForUpdate()->firstOrFail();
            $qty = (float) $line->base_qty;
            if ($reservation->reservation_status !== 'converted_to_shipment' || $line->line_status !== 'prepared'
                || $reservation->source_type !== 'sales_order' || (int) $reservation->source_order_id !== (int) $shipment->sales_order_id
                || (int) $reservation->source_order_line_id !== (int) $line->sales_order_line_id
                || abs((float) $reservation->reserved_qty - $qty) > 0.00000001 || (float) $balance->quantity_on_hand + 0.00000001 < $qty
                || (float) $balance->quantity_locked + 0.00000001 < $qty) throw ValidationException::withMessages(['stock' => '待发库存或本次占用已变化，不能正式发出。']);
            $eligible = $this->availability->outboundBalanceQuery([(int) $line->item_id])->where('erp_inventory_balances.id', $balance->id)->lockForUpdate()->first();
            if (! $eligible || $this->availability->availableForOutbound($balance, $qty) + 0.00000001 < $qty)
                throw ValidationException::withMessages(['stock' => '待发仓库、库位、批次或设备质量可用条件已变化，不能正式发出。']);
            $serialIds = array_values(array_unique(array_map('intval', $line->serial_snapshot['inventory_serial_ids'] ?? [])));
            $mode = $line->orderLine?->item?->serialTrackingMode() ?? 'none';
            if (($mode === 'required' && count($serialIds) !== (int) $qty) || ($mode === 'none' && $serialIds !== [])) throw ValidationException::withMessages(['serial_ids' => '本次发运设备身份与物料管理方式不符。']);
            $serials = InventorySerial::query()->whereIn('id', $serialIds)->where('inventory_balance_id', $balance->id)->where('serial_status', 'staged')->lockForUpdate()->get();
            if ($serials->count() !== count($serialIds)) throw ValidationException::withMessages(['serial_ids' => '所选设备已不在本次待发库存中。']);
            $balance->update(['quantity_locked' => (float) $balance->quantity_locked - $qty]);
            $location = InventoryLocationBalance::query()->where('item_id', $line->item_id)->where('warehouse_id', $line->warehouse_id)->where('location_id', $line->location_id)->lockForUpdate()->firstOrFail();
            if ((float) $location->quantity_locked + 0.00000001 < $qty) throw ValidationException::withMessages(['stock' => '待发区汇总占用不足。']);
            $location->update(['quantity_locked' => (float) $location->quantity_locked - $qty]);
            $cost = CuttingDecimal::share((string) $balance->inventory_value, (string) $balance->quantity_on_hand, (string) $line->base_qty);
            $posted = $this->applyInventoryChange($transaction, ['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id, 'location_id' => $line->location_id,
                'batch_no' => $line->batch_no, 'unit_id' => $line->unit_id, 'material_lot_id' => $balance->material_lot_id, 'change_qty' => -$qty,
                'unit_cost' => $balance->average_unit_cost, 'cost_amount' => bcsub('0', $cost, 4), 'cost_source_type' => 'sales_shipment_frozen_fact',
                'source_type' => 'sales_shipment', 'source_id' => $shipment->id, 'source_item_id' => $line->id]);
            $line->update(['unit_cost_snapshot' => $balance->average_unit_cost, 'cost_amount_snapshot' => bcsub('0', (string) $posted->cost_amount, 4)]);
            $reservation->update(['reservation_status' => 'consumed', 'released_at' => now(), 'release_reason' => '确认发运或提货，正式销售出库']);
            foreach ($serials as $serial) {
                $serial->update(['serial_status' => 'shipped', 'outbound_at' => now()]);
                InventorySerialEvent::create(['inventory_serial_id' => $serial->id, 'event_type' => 'sales_shipment_outbound', 'document_type' => 'sales_shipment',
                    'document_id' => $shipment->id, 'document_no' => $shipment->shipment_no, 'from_status' => 'staged', 'to_status' => 'shipped',
                    'warehouse_id' => $serial->warehouse_id, 'location_id' => $serial->location_id, 'batch_no' => $serial->batch_no, 'operator_id' => (int) $actor->legacy_id,
                    'event_payload' => ['shipment_line_id' => $line->id, 'dispatch_id' => $dispatch->id], 'occurred_at' => now()]);
            }
        }
        $shipment->update(['actual_cost_amount' => (string) $shipment->lines()->sum('cost_amount_snapshot')]);
        InventoryPostingLog::create(['source_type' => 'sales_shipment_dispatch', 'source_id' => $dispatch->id, 'source_no' => $dispatch->dispatch_no,
            'transaction_type' => 'sales_shipment_outbound', 'transaction_id' => $transaction->id, 'posting_status' => 'posted', 'posted_at' => now(), 'message' => '发运批次正式销售出库成功']);
        return $transaction->fresh('items');
    }

    /** A persisted packing material line owns one outbound fact; retries never consume it twice. */
    public function postShipmentPackingMaterials(object $operation, iterable $materials, object $actor): InventoryTransaction
    {
        return DB::transaction(function () use ($operation, $materials, $actor): InventoryTransaction {
            $op = DB::table('erp_shipment_packing_operations')->where('id', $operation->id)->lockForUpdate()->first();
            if (! $op) throw ValidationException::withMessages(['operation' => '包装工序不存在。']);
            $ids = collect($materials)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($ids === [] || count($ids) !== count(array_unique($ids))) throw ValidationException::withMessages(['materials' => '请选择不重复的正式包装用料明细。']);
            $rows = DB::table('erp_shipment_packing_materials')->where('operation_id', $op->id)->whereIn('id', $ids)->orderBy('inventory_balance_id')->orderBy('id')->lockForUpdate()->get();
            if ($rows->count() !== count($ids)) throw ValidationException::withMessages(['materials' => '用料明细不属于当前包装工序。']);
            $posted = $rows->pluck('inventory_transaction_id')->filter()->unique();
            if ($posted->isNotEmpty()) {
                if ($posted->count() !== 1 || $rows->contains(fn ($row) => ! $row->inventory_transaction_id)) throw ValidationException::withMessages(['materials' => '不可混合已过账与未过账包装用料。']);
                return InventoryTransaction::with('items')->findOrFail($posted->first());
            }
            // A job may record several actual consumptions. Its persisted material row
            // identifies this posting batch, while detail facts still identify the job;
            // using the job as the header source would collide on the second batch.
            $sourceMaterialId = min($ids);
            $transaction = InventoryTransaction::create(['transaction_no' => $this->nextNo('ITX'), 'transaction_type' => 'shipment_packing_material_outbound',
                'source_type' => 'shipment_packing_material', 'source_id' => $sourceMaterialId, 'source_no' => 'PACK-'.$op->id.'-MAT-'.$sourceMaterialId,
                'posting_status' => 'posted', 'transaction_date' => now()->toDateString(), 'posted_at' => now(),
                'posted_by' => (int) ($actor->legacy_id ?? $actor->id), 'remark' => '发货包装材料正式耗用']);
            foreach ($rows as $row) {
                $balance = InventoryBalance::query()->whereKey($row->inventory_balance_id)->lockForUpdate()->firstOrFail();
                $item = Item::findOrFail($row->item_id);
                $quantity = (string) $row->base_qty;
                if ((int) $balance->item_id !== (int) $row->item_id || (int) $balance->unit_id !== (int) $row->unit_id
                    || bccomp($quantity, '0', 8) <= 0 || bccomp((string) $balance->quantity_available, $quantity, 8) < 0) throw ValidationException::withMessages(['materials' => '包装材料的物料、基础单位或可用库存不匹配。']);
                $snapshot = json_decode($row->serial_snapshot ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                $serialIds = array_map('intval', $snapshot['inventory_serial_ids'] ?? []);
                $physicalIds = array_map('intval', $snapshot['physical_material_ids'] ?? []);
                $physicalCost = null;
                if ($item->materialManagementMode() === 'physical') {
                    if ($physicalIds === [] || count($physicalIds) !== count(array_unique($physicalIds)) || bccomp($quantity, (string) count($physicalIds), 8) !== 0) throw ValidationException::withMessages(['materials' => '板料耗用必须选择数量一致的整张真实实物。']);
                    $physicalCost = '0';
                    foreach ($physicalIds as $physicalId) {
                        if (! $this->physicalAtBalance($physicalId, (int) $item->id, (int) $balance->id)) throw ValidationException::withMessages(['materials' => '包装板料实物不属于当前可用批次，或已被占用。']);
                        $physicalCost = bcadd($physicalCost, (string) DB::table('erp_material_physicals')->where('id', $physicalId)->value('total_cost'), 4);
                    }
                } elseif ($physicalIds !== []) throw ValidationException::withMessages(['materials' => '普通数量物料不能提交板料实物身份。']);
                $serials = collect();
                $trackingMode = $item->serialTrackingMode();
                if ($trackingMode !== 'none') {
                    if (count($serialIds) !== count(array_unique($serialIds))
                        || bccomp($quantity, (string) count($serialIds), 8) < 0
                        || ($trackingMode === 'required' && bccomp($quantity, (string) count($serialIds), 8) !== 0)) throw ValidationException::withMessages(['materials' => '包装材料序列号不能重复或超过实耗，必需编号物料须逐件选择真实身份。']);
                    $serials = InventorySerial::whereIn('id', $serialIds)->where('inventory_balance_id', $balance->id)
                        ->where('item_id', $item->id)->where('warehouse_id', $balance->warehouse_id)
                        ->where('location_id', $balance->location_id)->where('batch_no', $balance->batch_no)
                        ->where('serial_status', 'available')->lockForUpdate()->get();
                    if ($serials->count() !== count($serialIds)) throw ValidationException::withMessages(['materials' => '所选包装材料序列号不可用。']);
                    if ($trackingMode === 'optional') {
                        // A quantity without selected identities may consume only unnumbered
                        // stock, matching formal adjustment rules; otherwise live SN facts
                        // would remain in this balance after their physical stock was used.
                        $availableSerialCount = InventorySerial::where('inventory_balance_id', $balance->id)->where('serial_status', 'available')->count();
                        $unnumberedAvailable = bcsub((string) $balance->quantity_available, (string) $availableSerialCount, 8);
                        if (bccomp($unnumberedAvailable, '0', 8) < 0) $unnumberedAvailable = '0';
                        if (bccomp(bcsub($quantity, (string) count($serialIds), 8), $unnumberedAvailable, 8) > 0) throw ValidationException::withMessages(['materials' => '包装材料无编号可用数量不足，请补选本次实际耗用的序列号。']);
                    }
                } elseif ($serialIds !== []) throw ValidationException::withMessages(['materials' => '非序列物料不能提交序列号。']);
                $cost = $physicalCost ?? ($balance->material_lot_id
                    ? CuttingDecimal::share((string) $balance->inventory_value, (string) $balance->quantity_on_hand, $quantity)
                    : bcmul($quantity, (string) $balance->average_unit_cost, 4));
                $fact = $this->applyInventoryChange($transaction, ['item_id' => $item->id, 'warehouse_id' => $balance->warehouse_id,
                    'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'unit_id' => $balance->unit_id,
                    'change_qty' => bcsub('0', $quantity, 8), 'unit_cost' => bcdiv($cost, $quantity, 8), 'cost_amount' => bcsub('0', $cost, 4),
                    'cost_source_type' => $physicalCost !== null ? 'shipment_packing_physical_total' : 'shipment_packing_material_fact',
                    'source_type' => 'shipment_packing_operation', 'source_id' => $op->id, 'source_item_id' => $row->id, 'remark' => '包装实际材料耗用']);
                foreach ($physicalIds as $physicalId) {
                    $physical = DB::table('erp_material_physicals')->where('id', $physicalId)->first();
                    $sourceHolding = DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->lockForUpdate()->first();
                    // Warehouse holdings are shared by the other plates in this balance.
                    // Move only the consumed identity; closing that holding would disable
                    // every remaining plate even though its stock and cost remain available.
                    $physicalMaterials = app(MaterialPhysicalService::class);
                    $targetHoldingId = $physicalMaterials->nonWarehouseHolding((int) $physical->material_lot_id,
                        'SHIPMENT_PACKING', (int) $row->id, (string) $physical->total_cost, 'CONSUMED');
                    $physicalMaterials->movePhysical($physical, $sourceHolding, $targetHoldingId, 'PACKING_CONSUME', 'CONSUMED',
                        (int) $transaction->id, (int) ($actor->legacy_id ?? $actor->id));
                }
                foreach ($serials as $serial) {
                    $serial->update(['serial_status' => 'packing_consumed', 'outbound_at' => now()]);
                    InventorySerialEvent::create(['inventory_serial_id' => $serial->id, 'event_type' => 'packing_material_consumed',
                        'document_type' => 'shipment_packing_operation', 'document_id' => $op->id, 'document_no' => 'PACK-'.$op->id,
                        'from_status' => 'available', 'to_status' => 'packing_consumed', 'warehouse_id' => $balance->warehouse_id,
                        'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'occurred_at' => now()]);
                }
                DB::table('erp_shipment_packing_materials')->where('id', $row->id)->update(['inventory_transaction_id' => $transaction->id,
                    'inventory_transaction_item_id' => $fact->id, 'cost_amount_snapshot' => bcsub('0', (string) $fact->cost_amount, 4),
                    'posted_at' => now(), 'updated_at' => now()]);
            }
            return $transaction->fresh('items');
        }, 5);
    }

    /** Post an identity-preserving warehouse-to-warehouse transfer. */
    public function postPhysicalTransfer(object $transfer, object $physical, InventoryBalance $source, object $operator): InventoryTransaction
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Physical transfer posting requires an application transaction.');
        $transaction = InventoryTransaction::create([
            'transaction_no' => $this->nextNo('ITX'),
            'transaction_type' => 'physical_material_transfer',
            'source_type' => 'material_physical_transfer',
            'source_id' => $transfer->id,
            'source_no' => $transfer->transfer_no,
            'posting_status' => 'posted',
            'warehouse_id' => $source->warehouse_id,
            'location_id' => $source->location_id,
            'transaction_date' => now()->toDateString(),
            'posted_by' => (int) ($operator->legacy_id ?? $operator->id ?? 0),
            'posted_at' => now(),
            'remark' => '实物材料仓库调拨',
        ]);
        $common = [
            'item_id' => $physical->item_id,
            'unit_id' => $source->unit_id,
            'unit_cost' => (float) $physical->total_cost,
            'material_lot_id' => $source->material_lot_id,
            'source_type' => 'material_physical_transfer',
            'source_id' => $transfer->id,
            'source_item_id' => $physical->id,
            'physical_transfer_id' => $transfer->id,
            'physical_material_id' => $physical->id,
        ];
        $this->applyInventoryChange($transaction, $common + [
            'warehouse_id' => $source->warehouse_id,
            'location_id' => $source->location_id,
            'batch_no' => $source->batch_no,
            'change_qty' => '-1',
            'cost_amount' => bcsub('0', (string) $physical->total_cost, 4),
            'physical_operation' => 'transfer_out',
            'remark' => '实物调拨出库',
        ]);
        $this->applyInventoryChange($transaction, $common + [
            'warehouse_id' => $transfer->target_warehouse_id,
            'location_id' => $transfer->target_location_id,
            'batch_no' => $transfer->target_batch_no,
            'change_qty' => '1',
            'cost_amount' => (string) $physical->total_cost,
            'physical_operation' => 'transfer_in',
            'remark' => '实物调拨入库',
        ]);
        return $transaction->fresh(['items']);
    }

    /** Post one exact warehouse physical to disposal; no Item-only scrap is accepted. */
    public function postPhysicalDisposal(object $disposal, object $physical, InventoryBalance $source, object $operator): InventoryTransaction
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Physical disposal posting requires an application transaction.');
        $transaction = InventoryTransaction::create([
            'transaction_no' => $this->nextNo('ITX'),
            'transaction_type' => 'physical_material_disposal',
            'source_type' => 'material_physical_disposal',
            'source_id' => $disposal->id,
            'source_no' => $disposal->disposal_no,
            'posting_status' => 'posted',
            'warehouse_id' => $source->warehouse_id,
            'location_id' => $source->location_id,
            'transaction_date' => now()->toDateString(),
            'posted_by' => (int) ($operator->legacy_id ?? $operator->id ?? 0),
            'posted_at' => now(),
            'remark' => '实物材料报废',
        ]);
        $this->applyInventoryChange($transaction, [
            'item_id' => $physical->item_id,
            'warehouse_id' => $source->warehouse_id,
            'location_id' => $source->location_id,
            'batch_no' => $source->batch_no,
            'unit_id' => $source->unit_id,
            'change_qty' => '-1',
            'unit_cost' => (float) $physical->total_cost,
            'cost_amount' => bcsub('0', (string) $physical->total_cost, 4),
            'material_lot_id' => $source->material_lot_id,
            'source_type' => 'material_physical_disposal',
            'source_id' => $disposal->id,
            'source_item_id' => $physical->id,
            'physical_operation' => 'disposal',
            'physical_disposal_id' => $disposal->id,
            'physical_material_id' => $physical->id,
            'remark' => '实物材料逐张报废出库',
        ]);
        return $transaction->fresh(['items']);
    }

    private function applyInventoryChange(InventoryTransaction $transaction, array $line): InventoryTransactionItem
    {
        $item = Item::query()->whereKey($line['item_id'])->lockForUpdate()->firstOrFail();
        if ((float) $line['change_qty'] > 0) {
            if (!empty($line['original_return_transaction_items']) && in_array($transaction->transaction_type, [
                'sales_return_inbound',
            ], true)) {
                $proofs = collect($line['original_return_transaction_items'])->groupBy('id')
                    ->map(fn ($rows) => $rows->reduce(fn ($sum, $row) => bcadd($sum, (string) $row['quantity'], 8), '0.00000000'));
                $restored = '0.00000000';
                foreach ($proofs as $sourceId => $quantity) {
                    $source = InventoryTransactionItem::query()->with('transaction')->whereKey($sourceId)->lockForUpdate()->first();
                    $originalType = 'sales_shipment_outbound';
                    if (!$source || $source->transaction?->transaction_type !== $originalType) {
                        throw ValidationException::withMessages(['stock' => '历史退回缺少对应业务的原出库流水证明。']);
                    }
                    $item = app(WarehouseManagementScopeService::class)->assertOriginalReturn($source, array_replace($line, ['change_qty' => $quantity]));
                    $restored = bcadd($restored, $quantity, 8);
                }
                if (bccomp($restored, (string) $line['change_qty'], 8) !== 0) {
                    throw ValidationException::withMessages(['stock' => '历史退回数量必须与原出库事实分配完全一致。']);
                }
            } else {
                $item = $this->assertForwardStockLocator($line);
            }
        }
        $balance = InventoryBalance::firstOrNew([
            'item_id' => $line['item_id'],
            'warehouse_id' => $line['warehouse_id'],
            'location_id' => $line['location_id'],
            'batch_no' => $line['batch_no'],
        ]);

        if ($item->materialManagementMode() === 'physical') {
            $this->assertPhysicalInventoryChange($transaction, $line, $item, $balance);
        }

        $onHand = (float) ($balance->quantity_on_hand ?? 0) + (float) $line['change_qty'];
        $previousValue = (float) ($balance->inventory_value ?? 0);
        $costAmount = array_key_exists('cost_amount', $line)
            ? (float) $line['cost_amount']
            : (float) $line['change_qty'] * (float) ($line['unit_cost'] ?? 0);
        $decimalLot = $item->materialManagementMode() === 'physical'
            || ! empty($line['material_lot_id']) || ! empty($balance->material_lot_id);
        if ($decimalLot) {
            if (empty($line['material_lot_id'])) $line['material_lot_id'] = $balance->material_lot_id;
            if ($balance->material_lot_id && (int) $balance->material_lot_id !== (int) $line['material_lot_id'])
                throw ValidationException::withMessages(['material_lot_id' => '同一库存批次不能混入不同配置、阶段或来源的材料批次。']);
            if (! array_key_exists('cost_amount', $line)) throw ValidationException::withMessages(['cost_amount' => '可追溯材料批次必须传入权威总金额。']);
            $costAmount = (string) $line['cost_amount'];
            $packingPhysicalTotal = ($line['cost_source_type'] ?? null) === 'shipment_packing_physical_total';
            $cuttingPhysicalTotal = ($line['cost_source_type'] ?? null) === 'mfg_cutting_physical_total';
            if ($packingPhysicalTotal && ($transaction->source_type !== 'shipment_packing_material'
                || $transaction->transaction_type !== 'shipment_packing_material_outbound'
                || ($line['source_type'] ?? null) !== 'shipment_packing_operation'
                || $item->materialManagementMode() !== 'physical')) throw ValidationException::withMessages(['cost_amount' => '包装实物总成本仅能来自正式包装用料及实物身份。']);
            if ($cuttingPhysicalTotal && ($transaction->source_type !== 'mfg_cutting_batch'
                || $transaction->transaction_type !== 'mfg_cutting_issue_outbound'
                || ($line['physical_operation'] ?? null) !== 'mfg_cutting_issue'
                || $item->materialManagementMode() !== 'physical')) throw ValidationException::withMessages(['cost_amount' => '开料实物总成本必须来自正式开料批次及逐张来源。']);
            if ((float) $line['change_qty'] < 0 && ! $packingPhysicalTotal && ! $cuttingPhysicalTotal) {
                $consumedQty = number_format(abs((float) $line['change_qty']), 8, '.', '');
                $costAmount = bcsub('0', CuttingDecimal::share((string) $balance->inventory_value, (string) $balance->quantity_on_hand, $consumedQty), 4);
            }
            $inventoryValue = bcadd((string) ($balance->inventory_value ?? '0'), $costAmount, 4);
            if (bccomp($inventoryValue, '0', 4) < 0) throw ValidationException::withMessages(['cost_amount' => '库存总金额不足，不能用归零掩盖成本差额。']);
        } else $inventoryValue = max(0, $previousValue + $costAmount);
        $locked = (float) ($balance->quantity_locked ?? 0);
        if ($onHand < 0 || $onHand - $locked < 0) {
            throw ValidationException::withMessages(['stock' => '当前库存不足，不能执行该调整。']);
        }

        $balance->fill([
            'unit_id' => $line['unit_id'] ?: $item->unit_id,
            'quantity_on_hand' => $onHand,
            'quantity_locked' => $locked,
            'quantity_available' => $this->availability->calculate($onHand, $locked, (float) ($balance->quantity_defective ?? 0), (float) ($balance->quantity_pending ?? 0)),
            'quantity_defective' => (float) ($balance->quantity_defective ?? 0),
            'quantity_pending' => (float) ($balance->quantity_pending ?? 0),
            'inventory_value' => $inventoryValue,
            'average_unit_cost' => $onHand > 0 ? $inventoryValue / $onHand : 0,
            'last_transaction_at' => now(),
        ])->save();

        if ($decimalLot) $balance->update(['material_lot_id' => $line['material_lot_id'],
            'average_unit_cost' => $onHand > 0 ? bcdiv($inventoryValue, number_format($onHand, 8, '.', ''), 8) : '0']);

        $locationBalance = InventoryLocationBalance::firstOrNew([
            'item_id' => $line['item_id'],
            'warehouse_id' => $line['warehouse_id'],
            'location_id' => $line['location_id'],
        ]);
        $locationOnHand = (float) ($locationBalance->quantity_on_hand ?? 0) + (float) $line['change_qty'];
        $locationLocked = (float) ($locationBalance->quantity_locked ?? 0);
        $locationBalance->fill([
            'unit_id' => $line['unit_id'] ?: $item->unit_id,
            'quantity_on_hand' => $locationOnHand,
            'quantity_locked' => $locationLocked,
            'quantity_available' => $this->availability->calculate($locationOnHand, $locationLocked, (float) ($locationBalance->quantity_defective ?? 0), (float) ($locationBalance->quantity_pending ?? 0)),
            'quantity_defective' => (float) ($locationBalance->quantity_defective ?? 0),
            'quantity_pending' => (float) ($locationBalance->quantity_pending ?? 0),
            'last_transaction_at' => now(),
        ])->save();

        InventoryBatch::firstOrCreate([
            'item_id' => $line['item_id'],
            'batch_no' => $line['batch_no'],
        ], [
            'warehouse_id' => $line['warehouse_id'],
            'location_id' => $line['location_id'],
            'source_type' => $line['source_type'],
            'source_id' => $line['source_id'],
            'status' => 'enabled',
        ]);

        $transactionItem = InventoryTransactionItem::create([
            'transaction_id' => $transaction->id,
            'transaction_no' => $transaction->transaction_no,
            'item_id' => $line['item_id'],
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            'warehouse_id' => $line['warehouse_id'],
            'location_id' => $line['location_id'],
            'batch_no' => $line['batch_no'],
            'unit_id' => $line['unit_id'] ?: $item->unit_id,
            'change_qty' => $line['change_qty'],
            'unit_cost' => (float) ($line['unit_cost'] ?? 0),
            'cost_amount' => $costAmount,
            'purchase_amount_snapshot' => $line['purchase_amount_snapshot'] ?? null,
            'freight_amount_snapshot' => $line['freight_amount_snapshot'] ?? 0,
            'other_purchase_cost_amount_snapshot' => $line['other_purchase_cost_amount_snapshot'] ?? 0,
            'cost_source_type' => $line['cost_source_type'] ?? null,
            'balance_after_qty' => $balance->quantity_on_hand,
            'source_type' => $line['source_type'],
            'source_id' => $line['source_id'],
            'source_item_id' => $line['source_item_id'] ?? null,
            'remark' => $line['remark'] ?? null,
        ]);
        if ($decimalLot) {
            $transactionItem->update(['material_lot_id' => $line['material_lot_id']]);
            InventoryBatch::where('item_id', $line['item_id'])->where('batch_no', $line['batch_no'])->update(['material_lot_id' => $line['material_lot_id']]);
        }

        // Alert facts belong to the same inventory transaction. The alert service deduplicates identical state/severity.
        app(InventoryAlertApplicationService::class)->recalculateForItemWarehouse(
            (int) $line['item_id'], (int) $line['warehouse_id'], 'inventory_transaction',
        );

        return $transactionItem;
    }

    private function assertForwardStockLocator(array $line): Item
    {
        $validated = app(WarehouseManagementScopeService::class)->assertForward((int) $line['item_id'], (int) $line['warehouse_id']);
        $location = Location::query()->whereKey((int) $line['location_id'])->lockForUpdate()->first();
        if (!$location || !in_array($location->status, ['active', 'enabled'], true)) {
            throw ValidationException::withMessages(['location_id' => '所选库位不存在或已停用。']);
        }
        if ((int) $location->warehouse_id !== (int) $validated['warehouse']->id) {
            throw ValidationException::withMessages(['location_id' => '所选库位必须属于当前入库仓库，不能使用其他仓库的库位。']);
        }
        if (!(bool) $validated['item']->is_stock_item) {
            throw ValidationException::withMessages(['stock' => '非库存物料不能建立新库存；历史原事实退回须提供真实原出库证明。']);
        }
        return $validated['item'];
    }

    private function assertPhysicalInventoryChange(
        InventoryTransaction $transaction,
        array $line,
        Item $item,
        InventoryBalance $balance,
    ): void {
        $quantity = (string) $line['change_qty'];
        $operation = $line['physical_operation'] ?? null;

        if (in_array($operation, ['mfg_cutting_issue', 'mfg_cutting_remnant'], true)) {
            $batch = \App\Models\Erp\MfgCuttingBatch::query()->lockForUpdate()->find($transaction->source_id);
            $input = $batch ? collect($batch->input_lines)->firstWhere('source_key', $line['cutting_source_key'] ?? null) : null;
            $physical = $input ? DB::table('erp_material_physicals')->where('id', $input['physical_material_id'])->lockForUpdate()->first() : null;
            $valid = $batch && $batch->status === 'issued' && $input && $physical
                && $transaction->source_type === 'mfg_cutting_batch' && ($line['source_type'] ?? null) === 'mfg_cutting_batch'
                && (int) $line['source_id'] === (int) $batch->id && (int) $input['item_id'] === (int) $item->id
                && (int) $physical->item_id === (int) $item->id && (int) ($line['physical_material_id'] ?? 0) === (int) $physical->id;
            if ($valid && $operation === 'mfg_cutting_issue' && ! $batch->inventory_issue_transaction_id
                && $transaction->transaction_type === 'mfg_cutting_issue_outbound'
                && (int) $input['inventory_balance_id'] === (int) $balance->id && bccomp($quantity, '-1', 8) === 0
                && bccomp((string) $line['cost_amount'], bcsub('0', (string) $physical->total_cost, 4), 4) === 0
                && $this->physicalAtBalance((int) $physical->id, (int) $item->id, (int) $balance->id)) return;
            $remnant = $batch ? collect($batch->remnants ?? [])->firstWhere('source_key', $line['cutting_source_key'] ?? null) : null;
            if ($valid && $operation === 'mfg_cutting_remnant' && $batch->inventory_issue_transaction_id && ! $batch->inventory_return_transaction_id
                && $transaction->transaction_type === 'mfg_cutting_remnant_inbound' && $physical->status === 'CUTTING'
                && $remnant && bccomp((string) $remnant['measure_qty'], '0', 8) > 0 && bccomp($quantity, '1', 8) === 0
                && (int) $remnant['warehouse_id'] === (int) $line['warehouse_id'] && (int) $remnant['location_id'] === (int) $line['location_id']
                && bccomp((string) $remnant['cost_amount'], (string) $line['cost_amount'], 4) === 0) return;
        }

        if ($operation === 'purchase_receipt') {
            $whole = bcadd($quantity, '0', 0);
            $valid = $transaction->source_type === 'purchase_receipt'
                && $transaction->transaction_type === 'purchase_receipt_posting'
                && bccomp($quantity, '0', 8) > 0
                && bccomp($quantity, $whole, 8) === 0
                && (int) ($line['physical_entry_count'] ?? -1) === (int) $whole;
            if ($valid) return;
        }

        if (in_array($operation, ['adjustment_increase', 'adjustment_decrease'], true)) {
            $entry = DB::table('erp_inventory_adjustment_item_physicals as entry')
                ->join('erp_inventory_adjustment_items as item_line', 'item_line.id', '=', 'entry.adjustment_item_id')
                ->where('entry.id', (int) ($line['physical_entry_id'] ?? 0))
                ->where('item_line.adjustment_id', $transaction->source_id)
                ->where('item_line.item_id', $item->id)
                ->select('entry.*')
                ->lockForUpdate()
                ->first();
            $expectedDirection = $operation === 'adjustment_increase' ? 'increase' : 'decrease';
            $expectedQty = $operation === 'adjustment_increase' ? '1' : '-1';
            $valid = $transaction->source_type === 'inventory_adjustment'
                && $transaction->transaction_type === 'manual_adjustment'
                && $entry
                && $entry->direction === $expectedDirection
                && !$entry->inventory_transaction_item_id
                && bccomp($quantity, $expectedQty, 8) === 0;
            if ($valid && $expectedDirection === 'increase' && !$entry->physical_material_id) return;
            if ($valid && $expectedDirection === 'decrease'
                && (int) $entry->physical_material_id === (int) ($line['physical_material_id'] ?? 0)
                && $this->physicalAtBalance((int) $entry->physical_material_id, (int) $item->id, (int) $balance->id)) return;
        }

        if ($operation === 'purchase_return') {
            $link = DB::table('erp_purchase_return_item_physicals as link')
                ->join('erp_purchase_return_items as item_line', 'item_line.id', '=', 'link.purchase_return_item_id')
                ->where('link.id', (int) ($line['physical_link_id'] ?? 0))
                ->where('item_line.return_id', $transaction->source_id)
                ->where('item_line.item_id', $item->id)
                ->select('link.*')
                ->lockForUpdate()
                ->first();
            if ($transaction->source_type === 'purchase_return'
                && $transaction->transaction_type === 'purchase_return_outbound'
                && $link && !$link->inventory_transaction_item_id
                && (int) $link->physical_material_id === (int) ($line['physical_material_id'] ?? 0)
                && bccomp($quantity, '-1', 8) === 0
                && $this->physicalAtBalance((int) $link->physical_material_id, (int) $item->id, (int) $balance->id)) return;
        }

        if (in_array($operation, ['transfer_out', 'transfer_in'], true)) {
            $transfer = DB::table('erp_material_physical_transfers')
                ->where('id', (int) ($line['physical_transfer_id'] ?? 0))
                ->where('physical_material_id', (int) ($line['physical_material_id'] ?? 0))
                ->where('status', 'PENDING')
                ->lockForUpdate()
                ->first();
            $physical = $transfer ? DB::table('erp_material_physicals')->where('id', $transfer->physical_material_id)->lockForUpdate()->first() : null;
            $validBase = $transfer && $physical
                && $transaction->source_type === 'material_physical_transfer'
                && $transaction->transaction_type === 'physical_material_transfer'
                && (int) $transaction->source_id === (int) $transfer->id
                && (int) $physical->item_id === (int) $item->id
                && $physical->status === 'AVAILABLE';
            if ($validBase && $operation === 'transfer_out'
                && bccomp($quantity, '-1', 8) === 0
                && (int) $transfer->source_inventory_balance_id === (int) $balance->id
                && $this->physicalAtBalance((int) $physical->id, (int) $item->id, (int) $balance->id)) return;
            if ($validBase && $operation === 'transfer_in'
                && bccomp($quantity, '1', 8) === 0
                && (int) $transfer->target_warehouse_id === (int) $line['warehouse_id']
                && (int) $transfer->target_location_id === (int) $line['location_id']
                && (string) $transfer->target_batch_no === (string) $line['batch_no']) return;
        }

        if ($operation === 'disposal') {
            $disposal = DB::table('erp_material_physical_disposals')
                ->where('id', (int) ($line['physical_disposal_id'] ?? 0))
                ->where('physical_material_id', (int) ($line['physical_material_id'] ?? 0))
                ->where('status', 'PENDING')
                ->lockForUpdate()
                ->first();
            if ($disposal
                && $transaction->source_type === 'material_physical_disposal'
                && $transaction->transaction_type === 'physical_material_disposal'
                && (int) $transaction->source_id === (int) $disposal->id
                && bccomp($quantity, '-1', 8) === 0
                && $this->physicalAtBalance((int) $disposal->physical_material_id, (int) $item->id, (int) $balance->id)) return;
        }

        if ($transaction->source_type === 'shipment_packing_material' && $transaction->transaction_type === 'shipment_packing_material_outbound') {
            $anchor = DB::table('erp_shipment_packing_materials')->where('id', $transaction->source_id)
                ->where('operation_id', (int) ($line['source_id'] ?? 0))->lockForUpdate()->first();
            $material = $anchor && ($line['source_type'] ?? null) === 'shipment_packing_operation'
                && (! $anchor->inventory_transaction_id || (int) $anchor->inventory_transaction_id === (int) $transaction->id)
                ? DB::table('erp_shipment_packing_materials')->where('id', (int) ($line['source_item_id'] ?? 0))
                    ->where('operation_id', $anchor->operation_id)->where('inventory_balance_id', $balance->id)->where('item_id', $item->id)->lockForUpdate()->first()
                : null;
            $ids = $material ? (json_decode($material->serial_snapshot ?: '{}', true)['physical_material_ids'] ?? []) : [];
            $valid = $material && ! $material->inventory_transaction_id && count($ids) > 0 && count($ids) === count(array_unique($ids))
                && bccomp((string) $material->base_qty, (string) count($ids), 8) === 0 && bccomp($quantity, bcsub('0', (string) count($ids), 8), 8) === 0;
            $total = '0';
            foreach ($ids as $id) {
                if (! $this->physicalAtBalance((int) $id, (int) $item->id, (int) $balance->id)) { $valid = false; break; }
                $total = bcadd($total, (string) DB::table('erp_material_physicals')->where('id', $id)->value('total_cost'), 4);
            }
            if ($valid && bccomp((string) ($line['cost_amount'] ?? '0'), bcsub('0', $total, 4), 4) === 0) return;
        }
        throw ValidationException::withMessages([
            'physical_material_id' => '实物管理物料禁止通过普通数量接口改变库存；必须逐张绑定已落库的正式实物动作。',
        ]);
    }

    private function physicalAtBalance(int $physicalId, int $itemId, int $balanceId): bool
    {
        $physical = DB::table('erp_material_physicals')->where('id', $physicalId)->lockForUpdate()->first();
        $holding = $physical ? DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->first() : null;
        return $physical && $holding && $physical->status === 'AVAILABLE' && $holding->status === 'ACTIVE'
            && $holding->position_type === 'WAREHOUSE' && (int) $physical->item_id === $itemId
            && (int) $holding->inventory_balance_id === $balanceId;
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

    private function originalReturnProof(int $sourceId, array $locator, float $quantity): array
    {
        $source = InventoryTransactionItem::query()->whereKey($sourceId)->lockForUpdate()->first();
        if (!$source || (int) $source->item_id !== (int) $locator['item_id']
            || (int) $source->warehouse_id !== (int) $locator['warehouse_id']
            || (int) $source->location_id !== (int) $locator['location_id']
            || (string) $source->batch_no !== (string) $locator['batch_no']) return [];
        return [['id' => $source->id, 'quantity' => number_format($quantity, 8, '.', '')]];
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

    private function nextNo(string $prefix): string
    {
        // Several stock postings can share one second. A random three-digit suffix collided
        // during real production regression; use the same locked counter as other documents.
        return app(DocumentNumberService::class)->next('inventory_transaction', $prefix);
    }
}
