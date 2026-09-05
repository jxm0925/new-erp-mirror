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
use App\Models\Erp\MaterialPickingTask;
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
    )
    {
    }

    public function postPurchaseReceipt(int $receiptId): InventoryTransaction
    {
        return DB::transaction(function () use ($receiptId) {
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
                'posted_at' => now(),
                'remark' => '采购到货入库过账',
            ]);

            foreach ($receipt->items as $line) {
                if (!(bool) $line->is_stock_item_snapshot) continue;
                $qty = $this->qualifiedBaseQuantity($line);
                if ($qty <= 0) continue;
                $unitCost = $qty > 0 ? (float) $line->inventory_cost_amount / $qty : 0;
                foreach ($line->allocations as $allocation) {
                    $allocationQty = (float) $allocation->base_qty;
                    if ($allocationQty <= 0) continue;
                    $balance = $this->applyInventoryChange($transaction, [
                        'item_id' => $line->item_id,
                        'warehouse_id' => $allocation->warehouse_id,
                        'location_id' => $allocation->location_id,
                        'batch_no' => $line->batch_no,
                        'unit_id' => $line->base_unit_id ?: $line->item?->unit_id,
                        'change_qty' => $allocationQty,
                        'unit_cost' => $unitCost,
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
                        'remark' => '采购到货合格数量按库位分配入库',
                    ]);
                    $this->registerReceiptSerials($receipt, $line, $allocation);
                }
            }

            $receipt->update(['stock_post_status' => 'posted', 'fulfillment_status' => 'fulfilled']);
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

    public function postPurchaseReturn(int $returnId, ?int $operatorId = null): InventoryTransaction
    {
        return DB::transaction(function () use ($returnId, $operatorId): InventoryTransaction {
            $purchaseReturn = PurchaseReturn::query()
                ->with(['items.item', 'items.sourceInventoryQualityEvent', 'items.serialLinks'])
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
                    $this->applyInventoryChange($transaction, [
                        'item_id' => $line->item_id,
                        'warehouse_id' => $line->warehouse_id,
                        'location_id' => $line->location_id,
                        'batch_no' => $line->batch_no,
                        'unit_id' => $line->base_unit_id ?: $line->item?->unit_id,
                        'change_qty' => $segment['base_qty'],
                        'unit_cost' => $segment['unit_cost'],
                        'cost_amount' => $segment['cost_amount'],
                        'cost_source_type' => 'sales_return_frozen_cost',
                        'source_type' => 'sales_return_receipt',
                        'source_id' => $receipt->id,
                        'source_item_id' => $line->id,
                        'remark' => '销售退货按原发运成本重新入库；原发运单行 #'.$segment['shipment_line_id'],
                    ]);
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

    public function postAdjustment(int $adjustmentId): InventoryTransaction
    {
        return DB::transaction(function () use ($adjustmentId) {
            $adjustment = InventoryAdjustment::with(['items.item', 'items.serials'])->lockForUpdate()->findOrFail($adjustmentId);
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
                    throw ValidationException::withMessages(['adjustment' => '调整明细必须包含 Item、仓库、库位和批次。']);
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
                'posted_at' => now(),
                'remark' => $adjustment->reason,
            ]);

            foreach ($adjustment->items as $line) {
                $balance = InventoryBalance::query()
                    ->where('item_id', $line->item_id)
                    ->where('warehouse_id', $line->warehouse_id)
                    ->where('location_id', $line->location_id)
                    ->where('batch_no', $line->batch_no)
                    ->lockForUpdate()
                    ->firstOrFail();
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

            $adjustment->update(['adjustment_status' => 'posted', 'posted_at' => now()]);
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

    /**
     * Posts the only inventory fact for a sales shipment.  A shipment consumes an
     * order reservation; it must never update inventory balances directly without
     * producing a transaction and transaction lines.
     */
    public function postSalesShipment(SalesShipment $shipment, string $operator): InventoryTransaction
    {
        return DB::transaction(function () use ($shipment, $operator): InventoryTransaction {
            $shipment = SalesShipment::query()
                ->with(['lines.reservation', 'lines.orderLine.item'])
                ->lockForUpdate()
                ->findOrFail($shipment->id);

            $existing = InventoryTransaction::query()
                ->where('transaction_type', 'sales_shipment_outbound')
                ->where('source_type', 'sales_shipment')
                ->where('source_id', $shipment->id)
                ->first();
            if ($existing) return $existing->fresh(['items.item', 'items.warehouse', 'items.location']);

            $first = $shipment->lines->first();
            if (!$first) {
                throw ValidationException::withMessages(['shipment' => '销售发货单至少需要一条库存履约明细。']);
            }
            $transaction = InventoryTransaction::create([
                'transaction_no' => $this->nextNo('ITX'),
                'transaction_type' => 'sales_shipment_outbound',
                'source_type' => 'sales_shipment',
                'source_id' => $shipment->id,
                'source_no' => $shipment->shipment_no,
                'posting_status' => 'posted',
                'warehouse_id' => $first->warehouse_id,
                'location_id' => $first->location_id,
                'transaction_date' => now()->toDateString(),
                'posted_at' => now(),
                'remark' => '销售发货库存出库；操作人：'.$operator,
            ]);

            $totalCost = 0.0;
            $allSerialIds = [];
            foreach ($shipment->lines as $shipmentLine) {
                $reservation = $shipmentLine->reservation()->lockForUpdate()->firstOrFail();
                if ($reservation->reservation_status !== 'converted_to_shipment') {
                    throw ValidationException::withMessages(['shipment' => '发货占用已失效或已被其他单据处理，不能重复库存过账。']);
                }
                $quantity = (float) $shipmentLine->base_qty;
                if ($quantity <= 0 || abs($quantity - (float) $reservation->reserved_qty) > 0.00000001) {
                    throw ValidationException::withMessages(['shipment' => '发货数量与已转换的库存预留不一致，不能过账。']);
                }
                $balance = InventoryBalance::query()
                    ->whereKey($reservation->inventory_balance_id)
                    ->where('item_id', $shipmentLine->item_id)
                    ->where('warehouse_id', $shipmentLine->warehouse_id)
                    ->where('location_id', $shipmentLine->location_id)
                    ->where('batch_no', $shipmentLine->batch_no)
                    ->lockForUpdate()
                    ->first();
                if (!$balance || (float) $balance->quantity_on_hand + 0.00000001 < $quantity
                    || (float) $balance->quantity_locked + 0.00000001 < $quantity) {
                    throw ValidationException::withMessages(['stock' => '发货来源批次的实物库存或订单锁定数量不足，不能出库。']);
                }

                $serialIds = array_values(array_unique(array_map('intval', (array) (($shipmentLine->serial_snapshot ?? [])['inventory_serial_ids'] ?? []))));
                $trackingMode = $shipmentLine->orderLine?->item?->serialTrackingMode() ?? 'none';
                if ($trackingMode === 'required'
                    && (abs($quantity - round($quantity)) > 0.00000001 || count($serialIds) !== (int) round($quantity))) {
                    throw ValidationException::withMessages(['serial_ids' => '启用序列号管理的发货必须逐件选择同数量的设备编号/序列号。']);
                }
                if ($trackingMode === 'none' && $serialIds !== []) {
                    throw ValidationException::withMessages(['serial_ids' => '未启用序列号管理的物料不可携带设备编号/序列号。']);
                }
                if (array_intersect($allSerialIds, $serialIds) !== []) {
                    throw ValidationException::withMessages(['serial_ids' => '同一设备编号/序列号不能在同一张发货单重复出库。']);
                }
                $allSerialIds = array_merge($allSerialIds, $serialIds);
                $serials = collect();
                if ($serialIds !== []) {
                    $serials = InventorySerial::query()
                        ->whereIn('id', $serialIds)
                        ->where('inventory_balance_id', $balance->id)
                        ->where('item_id', $shipmentLine->item_id)
                        ->where('warehouse_id', $shipmentLine->warehouse_id)
                        ->where('location_id', $shipmentLine->location_id)
                        ->where('batch_no', $shipmentLine->batch_no)
                        ->where('serial_status', 'available')
                        ->lockForUpdate()
                        ->get();
                    if ($serials->count() !== count($serialIds)) {
                        throw ValidationException::withMessages(['serial_ids' => '所选设备编号/序列号不属于当前可用库存批次，或已被其他业务占用。']);
                    }
                }

                // Release the sales-order lock first. applyInventoryChange then records the
                // on-hand reduction and makes the factual inventory transaction atomically.
                $balance->update(['quantity_locked' => max(0, (float) $balance->quantity_locked - $quantity)]);
                $locationBalance = InventoryLocationBalance::query()->where([
                    'item_id' => $shipmentLine->item_id,
                    'warehouse_id' => $shipmentLine->warehouse_id,
                    'location_id' => $shipmentLine->location_id,
                ])->lockForUpdate()->first();
                if ($locationBalance) {
                    $locationBalance->update(['quantity_locked' => max(0, (float) $locationBalance->quantity_locked - $quantity)]);
                }
                $unitCost = (float) $balance->average_unit_cost;
                $costAmount = -round($quantity * $unitCost, 4);
                $this->applyInventoryChange($transaction, [
                    'item_id' => $shipmentLine->item_id,
                    'warehouse_id' => $shipmentLine->warehouse_id,
                    'location_id' => $shipmentLine->location_id,
                    'batch_no' => $shipmentLine->batch_no,
                    'unit_id' => $shipmentLine->unit_id,
                    'change_qty' => -$quantity,
                    'unit_cost' => $unitCost,
                    'cost_amount' => $costAmount,
                    'cost_source_type' => 'sales_shipment_frozen_fact',
                    'source_type' => 'sales_shipment',
                    'source_id' => $shipment->id,
                    'source_item_id' => $shipmentLine->id,
                    'remark' => '销售发货出库 '.$shipment->shipment_no,
                ]);
                foreach ($serials as $serial) {
                    $serial->update(['serial_status' => 'shipped', 'outbound_at' => now()]);
                    InventorySerialEvent::create([
                        'inventory_serial_id' => $serial->id,
                        'event_type' => 'sales_shipment_outbound',
                        'document_type' => 'sales_shipment',
                        'document_id' => $shipment->id,
                        'document_no' => $shipment->shipment_no,
                        'from_status' => 'available',
                        'to_status' => 'shipped',
                        'warehouse_id' => $serial->warehouse_id,
                        'location_id' => $serial->location_id,
                        'batch_no' => $serial->batch_no,
                        'event_payload' => ['shipment_line_id' => $shipmentLine->id],
                        'occurred_at' => now(),
                    ]);
                }
                $reservation->update([
                    'reservation_status' => 'consumed',
                    'released_at' => now(),
                    'release_reason' => '销售发货库存出库过账',
                ]);
                $shipmentLine->update([
                    'unit_cost_snapshot' => $unitCost,
                    'cost_amount_snapshot' => abs($costAmount),
                ]);
                $totalCost += abs($costAmount);
            }
            $shipment->update(['actual_cost_amount' => round($totalCost, 4)]);
            InventoryPostingLog::create([
                'source_type' => 'sales_shipment',
                'source_id' => $shipment->id,
                'source_no' => $shipment->shipment_no,
                'transaction_type' => 'sales_shipment_outbound',
                'transaction_id' => $transaction->id,
                'posting_status' => 'posted',
                'message' => '销售发货库存出库过账成功',
                'posted_at' => now(),
            ]);
            return $transaction->fresh(['items.item', 'items.warehouse', 'items.location']);
        }, 5);
    }

    /**
     * Confirmed production picking is the single outbound inventory fact for
     * Phase 6B. Delivery and receipt only move custody/state and never deduct
     * warehouse stock a second time.
     */
    public function postProductionMaterialPicking(MaterialPickingTask $task, object $operator): InventoryTransaction
    {
        return DB::transaction(function () use ($task, $operator): InventoryTransaction {
            $task = MaterialPickingTask::query()->with(['lines.componentItem'])->lockForUpdate()->findOrFail($task->id);
            $existing = InventoryTransaction::query()
                ->where('transaction_type', 'production_material_picking_outbound')
                ->where('source_type', 'material_picking_task')
                ->where('source_id', $task->id)
                ->first();
            if ($existing) return $existing->fresh(['items.item', 'items.warehouse', 'items.location']);

            $lines = $task->lines->filter(fn ($line) => (float) $line->actual_pick_qty > 0);
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => '确认拣货至少需要一条大于 0 的实拣数量。']);
            }
            $transaction = InventoryTransaction::create([
                'transaction_no' => $this->nextNo('ITX'),
                'transaction_type' => 'production_material_picking_outbound',
                'source_type' => 'material_picking_task',
                'source_id' => $task->id,
                'source_no' => $task->task_no,
                'posting_status' => 'posted',
                'warehouse_id' => $task->warehouse_id,
                'transaction_date' => now()->toDateString(),
                'posted_by' => (int) ($operator->legacy_id ?? $operator->id ?? 0),
                'posted_at' => now(),
                'remark' => '生产工单配料确认出库',
            ]);

            $seenSerialIds = [];
            foreach ($lines as $line) {
                $quantity = (float) $line->actual_pick_qty;
                $balance = InventoryBalance::query()
                    ->whereKey($line->inventory_balance_id)
                    ->where('item_id', $line->component_item_id)
                    ->where('warehouse_id', $line->warehouse_id)
                    ->where('location_id', $line->location_id)
                    ->where('batch_no', $line->batch_no)
                    ->lockForUpdate()->first();
                if (! $balance || (float) $balance->quantity_available + 0.00000001 < $quantity) {
                    throw ValidationException::withMessages(['stock' => '所选真实库存批次可用数量不足，不能确认拣货。']);
                }

                $serialIds = array_values(array_unique(array_map('intval', (array) (($line->serial_snapshot ?? [])['inventory_serial_ids'] ?? []))));
                $trackingMode = $line->componentItem?->serialTrackingMode() ?? 'none';
                if ($trackingMode === 'required' && (abs($quantity - round($quantity)) > 0.00000001 || count($serialIds) !== (int) round($quantity))) {
                    throw ValidationException::withMessages(['serial_ids' => '序列号管理物料必须逐件选择与实拣数量一致的真实可用序列号。']);
                }
                if ($trackingMode === 'none' && $serialIds !== []) {
                    throw ValidationException::withMessages(['serial_ids' => '未启用序列号管理的物料不可提交序列号。']);
                }
                if (array_intersect($seenSerialIds, $serialIds) !== []) {
                    throw ValidationException::withMessages(['serial_ids' => '同一序列号不能在一次配料确认中重复分配。']);
                }
                $seenSerialIds = array_merge($seenSerialIds, $serialIds);
                $serials = $serialIds === [] ? collect() : InventorySerial::query()
                    ->whereIn('id', $serialIds)
                    ->where('inventory_balance_id', $balance->id)
                    ->where('serial_status', 'available')
                    ->lockForUpdate()->get();
                if ($serials->count() !== count($serialIds)) {
                    throw ValidationException::withMessages(['serial_ids' => '所选序列号不属于当前真实可用库存批次，或已被其他业务占用。']);
                }

                $unitCost = (float) $balance->average_unit_cost;
                $this->applyInventoryChange($transaction, [
                    'item_id' => $line->component_item_id,
                    'warehouse_id' => $line->warehouse_id,
                    'location_id' => $line->location_id,
                    'batch_no' => $line->batch_no,
                    'unit_id' => $line->unit_id,
                    'change_qty' => -$quantity,
                    'unit_cost' => $unitCost,
                    'cost_amount' => -round($quantity * $unitCost, 4),
                    'cost_source_type' => 'production_material_picking_fact',
                    'source_type' => 'material_picking_task',
                    'source_id' => $task->id,
                    'source_item_id' => $line->id,
                    'remark' => '生产配料出库 '.$task->task_no,
                ]);
                foreach ($serials as $serial) {
                    $serial->update(['serial_status' => 'production_in_transit', 'outbound_at' => now()]);
                    InventorySerialEvent::create([
                        'inventory_serial_id' => $serial->id,
                        'event_type' => 'production_material_picking_outbound',
                        'document_type' => 'material_picking_task',
                        'document_id' => $task->id,
                        'document_no' => $task->task_no,
                        'from_status' => 'available',
                        'to_status' => 'production_in_transit',
                        'warehouse_id' => $serial->warehouse_id,
                        'location_id' => $serial->location_id,
                        'batch_no' => $serial->batch_no,
                        'event_payload' => ['picking_task_line_id' => $line->id],
                        'occurred_at' => now(),
                    ]);
                }
            }
            InventoryPostingLog::create([
                'source_type' => 'material_picking_task', 'source_id' => $task->id,
                'source_no' => $task->task_no, 'transaction_type' => 'production_material_picking_outbound',
                'transaction_id' => $transaction->id, 'posting_status' => 'posted',
                'message' => '生产工单配料库存出库过账成功',
                'posted_by' => (int) ($operator->legacy_id ?? $operator->id ?? 0), 'posted_at' => now(),
            ]);
            return $transaction->fresh(['items.item', 'items.warehouse', 'items.location']);
        }, 5);
    }

    /** Post a real production output receipt; output creation and QA never change stock. */
    public function postProductionOutputReceipt(object $output, array $posting, object $operator): InventoryTransaction
    {
        return DB::transaction(function () use ($output, $posting, $operator): InventoryTransaction {
            $existing = InventoryTransaction::query()->where('transaction_type', 'production_output_receipt')
                ->where('source_type', 'production_output_record')->where('source_id', $output->id)->first();
            if ($existing) return $existing;
            $warehouseId = (int) ($posting['warehouse_id'] ?? 0); $locationId = (int) ($posting['location_id'] ?? 0);
            $batchNo = trim((string) ($posting['batch_no'] ?? ''));
            if ($warehouseId < 1 || $locationId < 1 || $batchNo === '') throw ValidationException::withMessages(['posting' => '生产入库必须指定仓库、库位和批次号。']);
            $transaction = InventoryTransaction::create(['transaction_no' => $this->nextNo('ITX'),
                'transaction_type' => 'production_output_receipt', 'source_type' => 'production_output_record',
                'source_id' => $output->id, 'source_no' => $output->output_no, 'posting_status' => 'posted',
                'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'transaction_date' => now()->toDateString(),
                'posted_by' => (int) ($operator->legacy_id ?? $operator->id ?? 0), 'posted_at' => now(), 'remark' => '生产工序产出正式入库']);
            $this->applyInventoryChange($transaction, ['item_id' => $output->output_item_id, 'warehouse_id' => $warehouseId,
                'location_id' => $locationId, 'batch_no' => $batchNo, 'unit_id' => Item::findOrFail($output->output_item_id)->unit_id,
                'change_qty' => (float) $output->output_base_qty, 'unit_cost' => (float) ($posting['unit_cost'] ?? 0),
                'cost_source_type' => 'production_output_fact', 'source_type' => 'production_output_record',
                'source_id' => $output->id, 'remark' => '生产产出入库 '.$output->output_no]);
            InventoryPostingLog::create(['source_type' => 'production_output_record', 'source_id' => $output->id,
                'source_no' => $output->output_no, 'transaction_type' => 'production_output_receipt',
                'transaction_id' => $transaction->id, 'posting_status' => 'posted', 'message' => '生产产出库存入库过账成功',
                'posted_by' => (int) ($operator->legacy_id ?? $operator->id ?? 0), 'posted_at' => now()]);
            return $transaction->fresh(['items']);
        }, 5);
    }

    public function postProductionMaterialReturnReceipt(object $return, iterable $lines, object $operator, bool $quarantine): InventoryTransaction
    {
        return DB::transaction(function () use ($return, $lines, $operator, $quarantine): InventoryTransaction {
            $type = $quarantine ? 'production_material_quality_return_quarantine' : 'production_material_return_receipt';
            $existing = InventoryTransaction::where('transaction_type', $type)->where('source_type', 'production_material_return')->where('source_id', $return->id)->first();
            if ($existing) return $existing;
            $first = collect($lines)->first();
            $transaction = InventoryTransaction::create(['transaction_no' => $this->nextNo('ITX'), 'transaction_type' => $type,
                'source_type' => 'production_material_return', 'source_id' => $return->id, 'source_no' => $return->return_no,
                'posting_status' => 'posted', 'warehouse_id' => $first->warehouse_id ?? null, 'location_id' => $first->location_id ?? null,
                'transaction_date' => now()->toDateString(), 'posted_by' => (int) ($operator->legacy_id ?? $operator->id ?? 0),
                'posted_at' => now(), 'remark' => $quarantine ? '生产质量退料进入隔离库存' : '生产正常退料入可用库存']);
            foreach ($lines as $line) {
                $this->applyInventoryChange($transaction, ['item_id' => $line->component_item_id, 'warehouse_id' => $line->warehouse_id,
                    'location_id' => $line->location_id, 'batch_no' => $line->batch_no ?: 'PROD-RETURN-'.$return->id,
                    'unit_id' => Item::findOrFail($line->component_item_id)->unit_id, 'change_qty' => (float) $line->return_base_qty,
                    'unit_cost' => 0, 'cost_source_type' => 'production_material_return_fact', 'source_type' => 'production_material_return',
                    'source_id' => $return->id, 'source_item_id' => $line->id, 'remark' => '生产退料 '.$return->return_no]);
                if ($quarantine) {
                    $balance = InventoryBalance::where('item_id', $line->component_item_id)->where('warehouse_id', $line->warehouse_id)
                        ->where('location_id', $line->location_id)->where('batch_no', $line->batch_no ?: 'PROD-RETURN-'.$return->id)->lockForUpdate()->firstOrFail();
                    $balance->quantity_pending = (float) $balance->quantity_pending + (float) $line->return_base_qty;
                    $balance->quantity_available = $this->availability->calculate((float) $balance->quantity_on_hand, (float) $balance->quantity_locked,
                        (float) $balance->quantity_defective, (float) $balance->quantity_pending); $balance->save();
                    $location = InventoryLocationBalance::where('item_id', $line->component_item_id)->where('warehouse_id', $line->warehouse_id)
                        ->where('location_id', $line->location_id)->lockForUpdate()->firstOrFail();
                    $location->quantity_pending = (float) $location->quantity_pending + (float) $line->return_base_qty;
                    $location->quantity_available = $this->availability->calculate((float) $location->quantity_on_hand, (float) $location->quantity_locked,
                        (float) $location->quantity_defective, (float) $location->quantity_pending); $location->save();
                }
            }
            return $transaction->fresh(['items']);
        }, 5);
    }

    public function releaseProductionMaterialReturnQuarantine(object $return, iterable $lines, object $operator): InventoryTransaction
    {
        return DB::transaction(function () use ($return, $lines, $operator): InventoryTransaction {
            $existing = InventoryTransaction::where('transaction_type', 'production_material_return_quality_release')
                ->where('source_type', 'production_material_return')->where('source_id', $return->id)->first();
            if ($existing) return $existing;
            $first = collect($lines)->first();
            $transaction = InventoryTransaction::create(['transaction_no' => $this->nextNo('ITX'), 'transaction_type' => 'production_material_return_quality_release',
                'source_type' => 'production_material_return', 'source_id' => $return->id, 'source_no' => $return->return_no,
                'posting_status' => 'posted', 'warehouse_id' => $first->warehouse_id ?? null, 'location_id' => $first->location_id ?? null,
                'transaction_date' => now()->toDateString(), 'posted_by' => (int) ($operator->legacy_id ?? $operator->id ?? 0), 'posted_at' => now(),
                'remark' => '生产质量退料检验合格，解除隔离']);
            foreach ($lines as $line) {
                $batch = $line->batch_no ?: 'PROD-RETURN-'.$return->id; $qty = (float) $line->return_base_qty;
                $balance = InventoryBalance::where('item_id', $line->component_item_id)->where('warehouse_id', $line->warehouse_id)
                    ->where('location_id', $line->location_id)->where('batch_no', $batch)->lockForUpdate()->firstOrFail();
                if ((float) $balance->quantity_pending + 0.00000001 < $qty) throw ValidationException::withMessages(['stock' => '隔离库存数量不足，不能解除。']);
                $balance->quantity_pending = (float) $balance->quantity_pending - $qty;
                $balance->quantity_available = $this->availability->calculate((float) $balance->quantity_on_hand, (float) $balance->quantity_locked,
                    (float) $balance->quantity_defective, (float) $balance->quantity_pending); $balance->save();
                $location = InventoryLocationBalance::where('item_id', $line->component_item_id)->where('warehouse_id', $line->warehouse_id)
                    ->where('location_id', $line->location_id)->lockForUpdate()->firstOrFail();
                $location->quantity_pending = max(0, (float) $location->quantity_pending - $qty);
                $location->quantity_available = $this->availability->calculate((float) $location->quantity_on_hand, (float) $location->quantity_locked,
                    (float) $location->quantity_defective, (float) $location->quantity_pending); $location->save();
                InventoryTransactionItem::create(['transaction_id' => $transaction->id, 'transaction_no' => $transaction->transaction_no,
                    'item_id' => $line->component_item_id, 'item_code' => Item::find($line->component_item_id)?->item_code,
                    'item_name' => Item::find($line->component_item_id)?->item_name, 'warehouse_id' => $line->warehouse_id,
                    'location_id' => $line->location_id, 'batch_no' => $batch, 'unit_id' => Item::find($line->component_item_id)?->unit_id,
                    'change_qty' => 0, 'unit_cost' => $balance->average_unit_cost, 'cost_amount' => 0,
                    'balance_after_qty' => $balance->quantity_on_hand, 'source_type' => 'production_material_return', 'source_id' => $return->id,
                    'source_item_id' => $line->id, 'remark' => '质量退料解除隔离，库存数量不重复增加']);
            }
            return $transaction->fresh(['items']);
        }, 5);
    }

    public function postProductionInternalIssue(object $issue, iterable $lines, object $operator): InventoryTransaction
    {
        return DB::transaction(function () use ($issue, $lines, $operator): InventoryTransaction {
            $existing = InventoryTransaction::where('transaction_type', 'production_internal_issue_outbound')
                ->where('source_type', 'production_internal_issue')->where('source_id', $issue->id)->first();
            if ($existing) return $existing;
            $first = collect($lines)->first();
            $transaction = InventoryTransaction::create(['transaction_no' => $this->nextNo('ITX'), 'transaction_type' => 'production_internal_issue_outbound',
                'source_type' => 'production_internal_issue', 'source_id' => $issue->id, 'source_no' => $issue->issue_no,
                'posting_status' => 'posted', 'warehouse_id' => $first->warehouse_id ?? null, 'location_id' => $first->location_id ?? null,
                'transaction_date' => now()->toDateString(), 'posted_by' => (int) ($operator->legacy_id ?? $operator->id ?? 0),
                'posted_at' => now(), 'remark' => '生产半成品内部领用正式出库']);
            foreach ($lines as $line) {
                $balance = InventoryBalance::query()->whereKey($line->inventory_balance_id)->lockForUpdate()->first();
                if (! $balance || (float) $balance->quantity_available + 0.00000001 < (float) $line->issue_base_qty)
                    throw ValidationException::withMessages(['stock' => '绑定的半成品库存不足，不能确认内部领用。']);
                $this->applyInventoryChange($transaction, ['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id,
                    'location_id' => $line->location_id, 'batch_no' => $line->batch_no, 'unit_id' => $balance->unit_id,
                    'change_qty' => -(float) $line->issue_base_qty, 'unit_cost' => $balance->average_unit_cost,
                    'cost_amount' => -(float) $line->issue_base_qty * (float) $balance->average_unit_cost,
                    'cost_source_type' => 'production_internal_issue_fact', 'source_type' => 'production_internal_issue',
                    'source_id' => $issue->id, 'source_item_id' => $line->id, 'remark' => '生产内部领用 '.$issue->issue_no]);
            }
            return $transaction->fresh(['items']);
        }, 5);
    }

    private function applyInventoryChange(InventoryTransaction $transaction, array $line): InventoryTransactionItem
    {
        $item = Item::findOrFail($line['item_id']);
        $balance = InventoryBalance::firstOrNew([
            'item_id' => $line['item_id'],
            'warehouse_id' => $line['warehouse_id'],
            'location_id' => $line['location_id'],
            'batch_no' => $line['batch_no'],
        ]);

        $onHand = (float) ($balance->quantity_on_hand ?? 0) + (float) $line['change_qty'];
        $previousValue = (float) ($balance->inventory_value ?? 0);
        $costAmount = array_key_exists('cost_amount', $line)
            ? (float) $line['cost_amount']
            : (float) $line['change_qty'] * (float) ($line['unit_cost'] ?? 0);
        $inventoryValue = max(0, $previousValue + $costAmount);
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

        // Alert facts belong to the same inventory transaction. The alert service deduplicates identical state/severity.
        app(InventoryAlertApplicationService::class)->recalculateForItemWarehouse(
            (int) $line['item_id'], (int) $line['warehouse_id'], 'inventory_transaction',
        );

        return $transactionItem;
    }

    /**
     * Consume only the quantity that really passed return inspection and is being
     * restocked. The value comes from the immutable sales-outbound transaction.
     */
    private function consumeSalesReturnCostAllocations(object $receiptLine, float $restockQty): array
    {
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
        return $prefix . now()->format('YmdHis') . random_int(100, 999);
    }
}
