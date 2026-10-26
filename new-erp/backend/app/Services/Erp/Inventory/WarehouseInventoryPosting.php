<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryAdjustment;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\InventoryQualityEvent;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Services\Erp\CuttingDecimal;
use App\Services\Erp\InventoryAdjustmentApplicationService;
use App\Services\Erp\MaterialPhysicalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 仓内库存过账：手工调整、库存质量换货原品出库、实物材料调拨与报废。业务只组装过账行，数量/成本/余额一律经 InventoryPostingKernel。 */
class WarehouseInventoryPosting
{
    public function __construct(
        private readonly InventoryAdjustmentApplicationService $adjustments,
        private readonly MaterialPhysicalService $physicalMaterials,
        private readonly InventoryPostingKernel $kernel,
    )
    {
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

            $transaction = $this->kernel->begin([
                
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
                            $transactionItem = $this->kernel->apply($transaction, [
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
                            $transactionItem = $this->kernel->apply($transaction, [
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
                $cost = [];
                if ($balance->material_lot_id) {
                    $quantity = (string) $line->change_qty;
                    $amount = (string) $balance->inventory_value;
                    $onHand = (string) $balance->quantity_on_hand;
                    if (bccomp($quantity, '0', 8) < 0) {
                        $amount = bcsub('0', CuttingDecimal::share($amount, $onHand, bcsub('0', $quantity, 8)), 4);
                    } else {
                        if (bccomp($onHand, '0', 8) === 0) {
                            $fact = InventoryTransactionItem::query()->where('item_id', $balance->item_id)
                                ->where('warehouse_id', $balance->warehouse_id)->where('location_id', $balance->location_id)
                                ->where('batch_no', $balance->batch_no)->where('material_lot_id', $balance->material_lot_id)
                                ->where('change_qty', '>', 0)->whereHas('transaction', fn ($query) => $query->where('posting_status', 'posted'))
                                ->latest('id')->lockForUpdate()->first();
                            if (! $fact) throw ValidationException::withMessages(['cost_amount' => '该材料批次没有可追溯的入库金额，不能确定盘盈成本。']);
                            $amount = (string) $fact->cost_amount;
                            $onHand = (string) $fact->change_qty;
                        }
                        $amount = bcdiv(bcmul($amount, $quantity, 12), $onHand, 4);
                    }
                    $cost = ['cost_amount' => $amount, 'unit_cost' => bcdiv($amount, $quantity, 8), 'material_lot_id' => $balance->material_lot_id];
                }
                $this->kernel->apply($transaction, $cost + [
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
        }, 5);
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
            $transaction = $this->kernel->begin([
                
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
            $this->kernel->apply($transaction, [
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

    /** Post an identity-preserving warehouse-to-warehouse transfer. */
    public function postPhysicalTransfer(object $transfer, object $physical, InventoryBalance $source, object $operator): InventoryTransaction
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Physical transfer posting requires an application transaction.');
        $transaction = $this->kernel->begin([
            
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
        $this->kernel->apply($transaction, $common + [
            'warehouse_id' => $source->warehouse_id,
            'location_id' => $source->location_id,
            'batch_no' => $source->batch_no,
            'change_qty' => '-1',
            'cost_amount' => bcsub('0', (string) $physical->total_cost, 4),
            'physical_operation' => 'transfer_out',
            'remark' => '实物调拨出库',
        ]);
        $this->kernel->apply($transaction, $common + [
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
        $transaction = $this->kernel->begin([
            
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
        $this->kernel->apply($transaction, [
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
}
