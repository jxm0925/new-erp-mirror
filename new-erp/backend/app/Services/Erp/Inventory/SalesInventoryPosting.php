<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryLocationBalance;
use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\SalesShipment;
use App\Models\Erp\Warehouse;
use App\Services\Erp\CuttingDecimal;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\MaterialPhysicalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 销售库存过账：发运出库、发货待发/返修内部移位、包装用料出库。业务只组装过账行，数量/成本/余额一律经 InventoryPostingKernel。 */
class SalesInventoryPosting
{
    public function __construct(
        private readonly InventoryAvailabilityService $availability,
        private readonly InventoryPostingKernel $kernel,
        private readonly PhysicalPostingGuard $physicalGuard,
    )
    {
    }

    /** 兼容旧调用：只回读已发生的销售出库；新增扣库必须走正式发运批次。 */
    public function postSalesShipment(SalesShipment $shipment, string $operator): InventoryTransaction
    {
        $existing = InventoryTransaction::query()->where('source_type', 'sales_shipment')->where('source_id', $shipment->id)
            ->where('transaction_type', 'sales_shipment_outbound')->where('posting_status', 'posted')->first();
        if ($existing) return $existing->fresh(['items.item', 'items.warehouse', 'items.location']);
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
            $transaction = $this->kernel->begin(['transaction_type' => 'shipment_internal_transfer',
                'source_type' => 'sales_shipment_movement', 'source_id' => $movement->id, 'source_no' => $line->shipment->shipment_no,
                'posting_status' => 'posted', 'warehouse_id' => $source->warehouse_id, 'location_id' => $source->location_id,
                'transaction_date' => now()->toDateString(), 'posted_by' => (int) $actor->legacy_id, 'posted_at' => now()]);
            $cost = CuttingDecimal::share((string) $source->inventory_value, (string) $source->quantity_on_hand, (string) $line->base_qty);
            $common = ['item_id' => $line->item_id, 'batch_no' => $source->batch_no, 'unit_id' => $line->unit_id,
                'unit_cost' => $source->average_unit_cost, 'material_lot_id' => $source->material_lot_id,
                'cost_source_type' => 'shipment_internal_transfer', 'source_type' => 'sales_shipment_movement', 'source_id' => $movement->id, 'source_item_id' => $line->id];
            $outbound = $this->kernel->apply($transaction, $common + ['warehouse_id' => $source->warehouse_id, 'location_id' => $source->location_id,
                'change_qty' => -$qty, 'cost_amount' => bcsub('0', $cost, 4)]);
            $this->kernel->apply($transaction, $common + ['warehouse_id' => $target->warehouse_id, 'location_id' => $target->id,
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
        $transaction = $this->kernel->begin(['transaction_type' => 'sales_shipment_outbound',
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
            $posted = $this->kernel->apply($transaction, ['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id, 'location_id' => $line->location_id,
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
            $transaction = $this->kernel->begin(['transaction_type' => 'shipment_packing_material_outbound',
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
                        if (! $this->physicalGuard->atBalance($physicalId, (int) $item->id, (int) $balance->id)) throw ValidationException::withMessages(['materials' => '包装板料实物不属于当前可用批次，或已被占用。']);
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
                $fact = $this->kernel->apply($transaction, ['item_id' => $item->id, 'warehouse_id' => $balance->warehouse_id,
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
}
