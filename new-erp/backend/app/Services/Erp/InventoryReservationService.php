<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryLocationBalance;
use App\Models\Erp\InventoryReservation;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderFulfillment;
use Illuminate\Support\Facades\DB;

class InventoryReservationService
{
    public function __construct(
        private readonly InventoryAvailabilityService $availability,
        private readonly InventoryAlertApplicationService $alerts,
    )
    {
    }

    /** 只读执行投影：冻结需求保持不变，生产补产只认原履约对应的正式入库。 */
    public function salesOrderExecutionProjection(SalesOrder $order, bool $currentRead = false): array
    {
        $order->loadMissing(['lines', 'fulfillments']);
        $fulfillments = $order->fulfillments->where('demand_status', 'confirmed');
        if (! in_array($order->order_status, ['confirmed', 'closed'], true)) {
            return ['fulfillments' => $fulfillments->mapWithKeys(fn ($row) => [$row->id => ['reservation_status' => $row->reservation_status, 'production_requirement_status' => $row->production_requirement_status]])->all(),
                'production_replenished_qty_by_line' => [], 'fulfillment_status' => $order->fulfillment_status];
        }
        $ids = $fulfillments->pluck('id')->all();
        $workOrders = DB::table('erp_mfg_work_orders')->where('source_type', 'sales_order')->where('sales_order_id', $order->id)
            ->whereIn('sales_fulfillment_id', $ids)->when($currentRead, fn ($q) => $q->lockForUpdate())->get()->groupBy('sales_fulfillment_id');
        $receipts = DB::table('erp_mfg_receipts as receipt')->join('erp_mfg_work_orders as wo', 'wo.id', '=', 'receipt.work_order_id')
            ->join('erp_sales_order_fulfillments as fulfillment', 'fulfillment.id', '=', 'wo.sales_fulfillment_id')
            ->join('erp_inventory_transactions as tx', 'tx.id', '=', 'receipt.inventory_transaction_id')
            ->where('wo.source_type', 'sales_order')->where('wo.sales_order_id', $order->id)->whereIn('wo.sales_fulfillment_id', $ids)
            ->where('fulfillment.demand_status', 'confirmed')->where('fulfillment.fulfillment_type', 'production')
            ->whereColumn('fulfillment.sales_order_id', 'wo.sales_order_id')->whereColumn('fulfillment.sales_order_line_id', 'wo.sales_order_line_id')
            ->whereColumn('fulfillment.item_id', 'wo.item_id')
            ->where('tx.source_type', 'mfg_receipt')->whereColumn('tx.source_id', 'receipt.id')
            ->where('tx.transaction_type', 'production_receipt_inbound')->where('tx.posting_status', 'posted')->where('receipt.qty', '>', 0)
            ->whereExists(function ($q) use ($currentRead): void {
                $q->selectRaw('1')->from('erp_inventory_transaction_items as fact')->whereColumn('fact.transaction_id', 'tx.id')
                    ->where('fact.source_type', 'mfg_receipt')->whereColumn('fact.source_id', 'receipt.id')->whereColumn('fact.item_id', 'wo.item_id')
                    ->whereColumn('fact.unit_id', 'fulfillment.base_unit_id')->whereColumn('fact.batch_no', 'wo.work_order_no')->whereColumn('fact.change_qty', 'receipt.qty')
                    ->when($currentRead, fn ($query) => $query->lockForUpdate());
            })->when($currentRead, fn ($q) => $q->lockForUpdate())->get(['wo.sales_fulfillment_id', 'wo.sales_order_line_id', 'wo.item_id', 'receipt.qty'])->groupBy('sales_fulfillment_id');
        $reservations = InventoryReservation::query()->where('source_type', InventoryReservation::SOURCE_SALES_ORDER)
            ->where('source_order_id', $order->id)->when($currentRead, fn ($q) => $q->lockForUpdate())->get();
        $statuses = []; $replenished = []; $unsupportedLines = [];
        foreach ($fulfillments as $fulfillment) {
            $required = (string) ($fulfillment->item_base_qty ?? $fulfillment->fulfillment_qty);
            $rows = $reservations->filter(fn ($row) => (int) $row->sales_order_fulfillment_id === (int) $fulfillment->id
                || (! $row->sales_order_fulfillment_id && $fulfillment->inventory_balance_id
                    && (int) $row->source_order_line_id === (int) $fulfillment->sales_order_line_id
                    && (int) $row->inventory_balance_id === (int) $fulfillment->inventory_balance_id));
            $reservationStatus = match ($fulfillment->fulfillment_type) { 'inventory' => 'pending', 'purchase' => 'awaiting_purchase', default => 'not_required' };
            if ($rows->isNotEmpty()) {
                $positive = $rows->filter(fn ($row) => bccomp((string) $row->reserved_qty, '0', 8) > 0);
                if ($positive->whereIn('reservation_status', ['active', 'partially_released'])->isNotEmpty()) $reservationStatus = 'reserved';
                elseif ($positive->where('reservation_status', 'converted_to_shipment')->isNotEmpty()) $reservationStatus = 'converted_to_shipment';
                elseif ($positive->where('reservation_status', 'consumed')->isNotEmpty()) $reservationStatus = 'consumed';
                elseif ($positive->where('reservation_status', 'released')->isNotEmpty()) $reservationStatus = 'released';
            }
            $productionStatus = $fulfillment->production_requirement_status;
            if ($fulfillment->fulfillment_type === 'production') {
                $manufacturing = $workOrders->get($fulfillment->id, collect())->filter(fn ($wo) => (int) $wo->item_id === (int) $fulfillment->item_id
                    && (int) $wo->sales_order_line_id === (int) $fulfillment->sales_order_line_id);
                $received = $receipts->get($fulfillment->id, collect())->filter(fn ($row) => (int) $row->item_id === (int) $fulfillment->item_id
                    && (int) $row->sales_order_line_id === (int) $fulfillment->sales_order_line_id)
                    ->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->qty, 8), '0.00000000');
                $received = bccomp($received, $required, 8) > 0 ? $required : $received;
                if (bccomp($required, '0', 8) > 0 && bccomp($received, $required, 8) >= 0) $productionStatus = 'completed';
                elseif ($manufacturing->isNotEmpty()) $productionStatus = $manufacturing->contains(fn ($wo) => $wo->status === 'planned' && filled($wo->block_reason)) ? 'blocked' : 'confirmed';
                $factor = (string) ($fulfillment->fulfillment_factor_snapshot ?? '0');
                if ($manufacturing->isEmpty() || bccomp($factor, '0', 8) <= 0 || $fulfillment->sales_qty === null) {
                    // 旧制造／混合来源无法按当前 MFG 单据完整解释时，保留原补产缓存，不重解释当前主档单位。
                    $unsupportedLines[$fulfillment->sales_order_line_id] = true;
                } else {
                    $salesQty = bcdiv($received, $factor, 8);
                    if (bccomp($salesQty, (string) $fulfillment->sales_qty, 8) > 0) $salesQty = (string) $fulfillment->sales_qty;
                    $replenished[$fulfillment->sales_order_line_id] = bcadd($replenished[$fulfillment->sales_order_line_id] ?? '0', $salesQty, 8);
                }
            }
            $statuses[$fulfillment->id] = ['reservation_status' => $reservationStatus, 'production_requirement_status' => $productionStatus];
        }
        foreach ($unsupportedLines as $lineId => $_) unset($replenished[$lineId]);
        $any = false; $complete = true;
        foreach ($order->lines as $line) {
            $required = SalesOrderFulfillmentPolicy::effectiveQuantity((float) $line->order_qty, (float) $line->cancelled_qty);
            if ($required <= 0.00000001) continue;
            $done = SalesOrderFulfillmentPolicy::isPhysical((string) $line->line_type) ? (float) $line->shipped_qty
                : (in_array($line->line_type, ['no_delivery', 'fee', 'auxiliary'], true) ? $required : (float) $line->service_fulfilled_qty);
            $any = $any || $done > 0.00000001;
            $complete = $complete && $done + 0.00000001 >= $required;
        }
        return ['fulfillments' => $statuses, 'production_replenished_qty_by_line' => $replenished,
            'fulfillment_status' => $complete ? 'fulfilled' : ($any ? 'partial' : 'pending')];
    }

    /** 正式业务动作结束时同步派生缓存；GET 只能调用上面的只读投影。 */
    public function refreshSalesOrderExecution(int $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $order = SalesOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if (! in_array($order->order_status, ['confirmed', 'closed'], true)) return;
            $order->setRelation('lines', $order->lines()->orderBy('id')->lockForUpdate()->get());
            $order->setRelation('fulfillments', $order->fulfillments()->orderBy('id')->lockForUpdate()->get());
            $projection = $this->salesOrderExecutionProjection($order, true);
            foreach ($order->fulfillments->where('demand_status', 'confirmed') as $fulfillment) {
                $fulfillment->fill($projection['fulfillments'][$fulfillment->id]);
                if ($fulfillment->isDirty()) $fulfillment->save();
            }
            foreach ($order->lines as $line) {
                if (! array_key_exists($line->id, $projection['production_replenished_qty_by_line'])) continue;
                $quantity = $projection['production_replenished_qty_by_line'][$line->id];
                if (bccomp((string) $line->production_replenished_qty, $quantity, 8) !== 0) $line->update(['production_replenished_qty' => $quantity]);
            }
            if ($order->fulfillment_status !== $projection['fulfillment_status']) $order->update(['fulfillment_status' => $projection['fulfillment_status']]);
        }, 5);
    }

    public function reserveForSalesOrder(SalesOrder $order): array
    {
        return DB::transaction(function () use ($order) {
            $created = [];
            foreach ($order->fulfillments()
                ->where('fulfillment_type', 'inventory')
                ->where('demand_status', 'confirmed')
                ->where('reservation_status', 'pending')
                ->orderBy('inventory_balance_id')
                ->get() as $fulfillment) {
                $key = "sales_order:{$order->id}:line:{$fulfillment->sales_order_line_id}:fulfillment:{$fulfillment->id}";
                $existing = InventoryReservation::where('idempotency_key', $key)->first();
                if ($existing) {
                    $fulfillment->update(['reservation_status' => 'reserved']);
                    $created[] = $existing;
                    continue;
                }
                $balance = InventoryBalance::whereKey($fulfillment->inventory_balance_id)->lockForUpdate()->first();
                if (!$balance || $this->availability->availableForOutbound($balance) < (float) $fulfillment->fulfillment_qty) {
                    throw new \RuntimeException('库存可用数量不足，不能正式占用');
                }
                $reservation = InventoryReservation::create([
                    'source_type' => InventoryReservation::SOURCE_SALES_ORDER,
                    'source_order_id' => $order->id,
                    'source_order_line_id' => $fulfillment->sales_order_line_id,
                    'sales_order_fulfillment_id' => $fulfillment->id,
                    'item_id' => $fulfillment->item_id,
                    'inventory_balance_id' => $fulfillment->inventory_balance_id,
                    'warehouse_id' => $fulfillment->warehouse_id,
                    'location_id' => $fulfillment->location_id,
                    'batch_no' => $fulfillment->batch_no,
                    'reserved_qty' => $fulfillment->fulfillment_qty,
                    'reservation_status' => 'active',
                    'reserved_at' => now(),
                    'idempotency_key' => $key,
                    'reservation_snapshot' => [
                        'fulfillment_id' => $fulfillment->id,
                        'balance_table' => 'erp_inventory_balances',
                        'available_before_qty' => $this->availability->availableForOutbound($balance),
                    ],
                ]);
                $reserved = (float) $reservation->reserved_qty;
                $locked = (float) ($balance->quantity_locked ?? 0) + $reserved;
                $onHand = (float) ($balance->quantity_on_hand ?? 0);
                $balance->update([
                    'quantity_locked' => $locked,
                    'quantity_available' => $this->availableAfterLock($balance, $locked),
                    'last_transaction_at' => now(),
                ]);
                $this->refreshAlert($balance, 'sales_order_reservation');
                $this->changeLocationLock($balance, $reserved);
                $fulfillment->update(['reservation_status' => 'reserved']);
                $created[] = $reservation;
            }
            return $created;
        });
    }

    public function releaseForSalesOrder(SalesOrder $order, string $reason): int
    {
        return DB::transaction(function () use ($order, $reason) {
            $reservations = InventoryReservation::where('source_type', InventoryReservation::SOURCE_SALES_ORDER)
                ->where('source_order_id', $order->id)
                ->whereIn('reservation_status', ['active', 'partially_released'])
                ->lockForUpdate()
                ->get();
            foreach ($reservations as $reservation) {
                $this->releaseBalance($reservation);
                $reservation->update(['reservation_status' => 'released', 'released_at' => now(), 'release_reason' => $reason]);
            }
            SalesOrderFulfillment::where('sales_order_id', $order->id)
                ->where('reservation_status', 'reserved')
                ->update(['reservation_status' => 'released']);
            return $reservations->count();
        });
    }

    /** 同一正式入库只补锁一次；备货和半成品不会冒领销售履约。 */
    public function reserveProductionReceipt(\App\Models\Erp\MfgReceipt $receipt, \App\Models\Erp\MfgWorkOrder $workOrder): array
    {
        if ($workOrder->source_type !== 'sales_order' || ! $workOrder->sales_fulfillment_id) return [];
        return DB::transaction(function () use ($receipt, $workOrder): array {
            $fulfillment = SalesOrderFulfillment::query()->whereKey($workOrder->sales_fulfillment_id)
                ->where('sales_order_id', $workOrder->sales_order_id)->where('sales_order_line_id', $workOrder->sales_order_line_id)
                ->where('item_id', $workOrder->item_id)->where('fulfillment_type', 'production')->where('demand_status', 'confirmed')
                ->lockForUpdate()->first();
            if (! $fulfillment) throw new \RuntimeException('生产入库的原销售生产履约已失效，不能静默转为公共库存。');
            $key = 'mfg_receipt:'.$receipt->id.':fulfillment:'.$fulfillment->id;
            $existing = InventoryReservation::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) return [$existing];
            if (! DB::table('erp_inventory_transactions')->where('source_type', 'mfg_receipt')->where('source_id', $receipt->id)
                ->where('transaction_type', 'production_receipt_inbound')->where('posting_status', 'posted')->exists()) {
                throw new \RuntimeException('生产入库尚未正式过账，不能建立销售预留。');
            }
            $balance = InventoryBalance::query()->where('item_id', $workOrder->item_id)->where('warehouse_id', $receipt->warehouse_id)
                ->where('location_id', $receipt->location_id)->where('batch_no', $workOrder->work_order_no)->lockForUpdate()->firstOrFail();
            $reserved = InventoryReservation::query()->where('sales_order_fulfillment_id', $fulfillment->id)
                ->whereIn('reservation_status', ['active', 'converted_to_shipment', 'consumed'])->lockForUpdate()->get()->sum('reserved_qty');
            $required = (float) ($fulfillment->item_base_qty ?: $fulfillment->fulfillment_qty);
            $qty = round(min((float) $receipt->qty, max(0, $required - (float) $reserved)), 8);
            if ($qty <= 0) return [];
            if ($this->availability->availableForOutbound($balance) + 0.00000001 < $qty) throw new \RuntimeException('生产入库的可用数量不足，不能回补原销售预留。');
            $reservation = InventoryReservation::create([
                'source_type' => InventoryReservation::SOURCE_SALES_ORDER, 'source_order_id' => $workOrder->sales_order_id,
                'source_order_line_id' => $workOrder->sales_order_line_id, 'sales_order_fulfillment_id' => $fulfillment->id,
                'source_document_no' => $receipt->receipt_no, 'item_id' => $workOrder->item_id,
                'inventory_balance_id' => $balance->id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
                'batch_no' => $balance->batch_no, 'reserved_qty' => $qty, 'reservation_status' => 'active', 'reserved_at' => now(),
                'idempotency_key' => $key, 'reservation_snapshot' => ['fulfillment_id' => $fulfillment->id, 'balance_table' => 'erp_inventory_balances',
                    'mfg_receipt_id' => (int) $receipt->id, 'mfg_work_order_id' => (int) $workOrder->id,
                    'serial_nos' => $receipt->serial_nos ?? [], 'receipt_qty' => (string) $receipt->qty],
            ]);
            $locked = (float) $balance->quantity_locked + $qty;
            $balance->update(['quantity_locked' => $locked, 'quantity_available' => $this->availableAfterLock($balance, $locked)]);
            $this->changeLocationLock($balance, $qty);
            $this->refreshAlert($balance, 'production_receipt_sales_reservation');
            $fulfillment->update(['reservation_status' => 'reserved']);
            $this->refreshSalesOrderExecution((int) $workOrder->sales_order_id);
            return [$reservation];
        }, 5);
    }

    public function adjustReservation(SalesOrder $order, string $reason = '订单备货数量变更'): array
    {
        return DB::transaction(function () use ($order, $reason) {
            $this->releaseForSalesOrder($order, $reason);
            return $this->reserveForSalesOrder($order->fresh('fulfillments'));
        });
    }

    public function convertToShipmentReservation(array $reservationIds, string $shipmentNo): int
    {
        return DB::transaction(function () use ($reservationIds, $shipmentNo) {
            $reservations = InventoryReservation::whereIn('id', $reservationIds)
                ->where('reservation_status', 'active')->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                $snapshot = is_array($reservation->reservation_snapshot) ? $reservation->reservation_snapshot : [];
                $snapshot['shipment_no'] = $shipmentNo;
                $reservation->update(['reservation_status' => 'converted_to_shipment', 'reservation_snapshot' => $snapshot]);
            }
            return $reservations->count();
        });
    }

    /**
     * Moves only the requested part of an active sales reservation to one shipment.
     * The lock quantity is deliberately unchanged here: it was already locked by the
     * sales order and is consumed only by the later outbound inventory transaction.
     */
    public function allocateToShipment(int $reservationId, float $qty, string $shipmentNo, ?string $allocationFragment = null): InventoryReservation
    {
        return DB::transaction(function () use ($reservationId, $qty, $shipmentNo, $allocationFragment): InventoryReservation {
            $reservation = InventoryReservation::query()->lockForUpdate()->findOrFail($reservationId);
            if ($reservation->reservation_status !== 'active' || $qty <= 0 || $qty > (float) $reservation->reserved_qty + 0.00000001) {
                throw new \RuntimeException('当前库存预留不可用于本次发货，或发货数量超过可用预留。');
            }
            $snapshot = (array) $reservation->reservation_snapshot;
            $snapshot['shipment_no'] = $shipmentNo;
            $snapshot['shipment_parent_reservation_id'] = $reservation->id;
            if (abs($qty - (float) $reservation->reserved_qty) <= 0.00000001) {
                $reservation->update(['reservation_status' => 'converted_to_shipment', 'reservation_snapshot' => $snapshot]);
                return $reservation->fresh();
            }

            $reservation->update(['reserved_qty' => round((float) $reservation->reserved_qty - $qty, 8)]);
            return InventoryReservation::create([
                'source_type' => $reservation->source_type,
                'source_order_id' => $reservation->source_order_id,
                'source_order_line_id' => $reservation->source_order_line_id,
                'sales_order_fulfillment_id' => $reservation->sales_order_fulfillment_id,
                'item_id' => $reservation->item_id,
                'inventory_balance_id' => $reservation->inventory_balance_id,
                'warehouse_id' => $reservation->warehouse_id,
                'location_id' => $reservation->location_id,
                'batch_no' => $reservation->batch_no,
                'reserved_qty' => $qty,
                'reservation_status' => 'converted_to_shipment',
                'reserved_at' => $reservation->reserved_at,
                'idempotency_key' => $reservation->idempotency_key.':shipment:'.$shipmentNo.($allocationFragment === null ? '' : ':'.hash('sha256', $allocationFragment)),
                'reservation_snapshot' => $snapshot,
            ]);
        });
    }

    public function restoreShipmentReservationToOrder(array $reservationIds, string $reason): int
    {
        return DB::transaction(function () use ($reservationIds, $reason) {
            $reservations = InventoryReservation::whereIn('id', $reservationIds)
                ->where('reservation_status', 'converted_to_shipment')
                ->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                $snapshot = (array) $reservation->reservation_snapshot;
                $parentId = (int) ($snapshot['shipment_parent_reservation_id'] ?? 0);
                if ($parentId > 0 && $parentId !== (int) $reservation->id) {
                    $parent = InventoryReservation::query()->whereKey($parentId)->lockForUpdate()->first();
                    if ($parent && $parent->reservation_status === 'active') {
                        $parent->reserved_qty = round((float) $parent->reserved_qty + (float) $reservation->reserved_qty, 8);
                        $parent->save();
                        $reservation->update([
                            'reservation_status' => 'returned_to_order',
                            'released_at' => now(),
                            'release_reason' => $reason,
                        ]);
                        continue;
                    }
                }
                unset($snapshot['shipment_no']);
                $snapshot['restored_from_cancelled_shipment_at'] = now()->toDateTimeString();
                $reservation->update([
                    'reservation_status' => 'active',
                    'released_at' => null,
                    'release_reason' => null,
                    'reservation_snapshot' => $snapshot,
                ]);
            }
            return $reservations->count();
        });
    }

    public function consumeReservationOnShipment(array $reservationIds, string $reason = '发货出库核销'): int
    {
        return DB::transaction(function () use ($reservationIds, $reason) {
            $reservations = InventoryReservation::whereIn('id', $reservationIds)
                ->where('reservation_status', 'converted_to_shipment')
                ->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                $balance = InventoryBalance::whereKey($reservation->inventory_balance_id)->lockForUpdate()->first();
                if (!$balance || (float) $balance->quantity_on_hand < (float) $reservation->reserved_qty) {
                    throw new \RuntimeException('发货核销库存不足');
                }
                $locked = max(0, (float) ($balance->quantity_locked ?? 0) - (float) $reservation->reserved_qty);
                $onHand = (float) $balance->quantity_on_hand - (float) $reservation->reserved_qty;
                $balance->update([
                    'quantity_on_hand' => $onHand,
                    'quantity_locked' => $locked,
                    'quantity_available' => $this->availability->calculate($onHand, $locked, (float) $balance->quantity_defective, (float) $balance->quantity_pending),
                    'last_transaction_at' => now(),
                ]);
                $this->refreshAlert($balance, 'sales_order_shipment');
                $this->consumeLocationReservation($balance, (float) $reservation->reserved_qty);
                $reservation->update(['reservation_status' => 'consumed', 'released_at' => now(), 'release_reason' => $reason]);
            }
            return $reservations->count();
        });
    }

    public function releaseShipmentReservation(int $id, string $reason): void
    {
        $reservation = InventoryReservation::query()->lockForUpdate()->findOrFail($id);
        if ($reservation->reservation_status !== 'converted_to_shipment') throw new \RuntimeException('发货占用已失效，请刷新后重试。');
        $this->releaseBalance($reservation);
        $reservation->update(['reservation_status' => 'released', 'released_at' => now(), 'release_reason' => $reason]);
    }

    /** 换机只能占用同一订单行的实际可用来源，不以修改原预留的物料或批次代替。 */
    public function reserveReplacement(\App\Models\Erp\SalesShipmentLine $line, InventoryBalance $balance, string $key): InventoryReservation
    {
        $parent = InventoryReservation::query()->where('source_type', InventoryReservation::SOURCE_SALES_ORDER)
            ->where('source_order_id', $line->shipment->sales_order_id)->where('source_order_line_id', $line->sales_order_line_id)
            ->where('inventory_balance_id', $balance->id)->where('reservation_status', 'active')->where('reserved_qty', '>=', 1)
            ->orderBy('id')->lockForUpdate()->first();
        if ($parent) return $this->allocateToShipment((int) $parent->id, 1, $line->shipment->shipment_no, $key);
        if ((int) $line->item_id !== (int) $balance->item_id || $this->availability->availableForOutbound($balance) < 1) throw new \RuntimeException('换机来源没有同物料的一台真实可用库存。');
        $parent = InventoryReservation::create(['source_type' => InventoryReservation::SOURCE_SALES_ORDER,
            'source_order_id' => $line->shipment->sales_order_id, 'source_order_line_id' => $line->sales_order_line_id,
            'sales_order_fulfillment_id' => $line->sales_order_fulfillment_id, 'item_id' => $line->item_id,
            'inventory_balance_id' => $balance->id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
            'batch_no' => $balance->batch_no, 'reserved_qty' => 1, 'reservation_status' => 'active', 'reserved_at' => now(),
            'idempotency_key' => 'shipment_replacement:'.$line->id.':'.hash('sha256', $key),
            'reservation_snapshot' => ['balance_table' => 'erp_inventory_balances', 'replacement_for_shipment_line_id' => $line->id]]);
        $locked = (float) $balance->quantity_locked + 1;
        $balance->update(['quantity_locked' => $locked, 'quantity_available' => $this->availableAfterLock($balance, $locked)]);
        $this->changeLocationLock($balance, 1);
        return $this->allocateToShipment((int) $parent->id, 1, $line->shipment->shipment_no, $key);
    }

    public function restoreRepairedShipmentToOrder(\App\Models\Erp\SalesShipmentLine $line): void
    {
        $reservation = $line->reservation()->lockForUpdate()->firstOrFail();
        if ($reservation->reservation_status !== 'released') return;
        $fulfillment = SalesOrderFulfillment::query()->whereKey($line->sales_order_fulfillment_id)->where('demand_status', 'confirmed')->lockForUpdate()->first();
        if (! $fulfillment || ! in_array($line->shipment->order?->order_status, ['confirmed', 'in_progress'], true)) return;
        $taken = InventoryReservation::query()->where('sales_order_fulfillment_id', $fulfillment->id)->whereIn('reservation_status', ['active', 'converted_to_shipment', 'consumed'])->lockForUpdate()->get()->sum('reserved_qty');
        if ((float) ($fulfillment->item_base_qty ?: $fulfillment->fulfillment_qty) - (float) $taken + 0.00000001 < (float) $line->base_qty) return;
        $balance = InventoryBalance::query()->whereKey($reservation->inventory_balance_id)->lockForUpdate()->firstOrFail();
        if ($this->availability->availableForOutbound($balance) + 0.00000001 < (float) $line->base_qty) throw new \RuntimeException('返修设备已返回，但库存可用量不足以恢复原订单占用。');
        $snapshot = $reservation->reservation_snapshot ?? [];
        unset($snapshot['shipment_no'], $snapshot['shipment_parent_reservation_id']);
        $snapshot['repaired_from_shipment_line_id'] = (int) $line->id;
        $reservation->update(['reservation_status' => 'active', 'released_at' => null, 'release_reason' => null, 'reservation_snapshot' => $snapshot]);
        $locked = (float) $balance->quantity_locked + (float) $line->base_qty;
        $balance->update(['quantity_locked' => $locked, 'quantity_available' => $this->availableAfterLock($balance, $locked)]);
        $this->changeLocationLock($balance, (float) $line->base_qty);
    }

    private function releaseBalance(InventoryReservation $reservation): void
    {
        $snapshot = (array) $reservation->reservation_snapshot;
        if (($snapshot['balance_table'] ?? null) !== 'erp_inventory_balances') {
            $legacy = InventoryLocationBalance::whereKey($reservation->inventory_balance_id)->lockForUpdate()->first();
            if (!$legacy) return;
            $locked = max(0, (float) $legacy->quantity_locked - (float) $reservation->reserved_qty);
            $legacy->update([
                'quantity_locked' => $locked,
                'quantity_available' => $this->availability->calculate((float) $legacy->quantity_on_hand, $locked, (float) $legacy->quantity_defective, (float) $legacy->quantity_pending),
                'last_transaction_at' => now(),
            ]);
            return;
        }

        $balance = InventoryBalance::whereKey($reservation->inventory_balance_id)->lockForUpdate()->first();
        if (!$balance) return;
        $released = (float) $reservation->reserved_qty;
        $locked = max(0, (float) $balance->quantity_locked - $released);
        $balance->update([
            'quantity_locked' => $locked,
            'quantity_available' => $this->availableAfterLock($balance, $locked),
            'last_transaction_at' => now(),
        ]);
        $this->refreshAlert($balance, 'sales_order_reservation_released');
        $this->changeLocationLock($balance, -$released);
    }

    private function availableAfterLock(InventoryBalance $balance, float $locked): float
    {
        return $this->availability->calculate(
            (float) $balance->quantity_on_hand
            , $locked
            , (float) $balance->quantity_defective
            , (float) $balance->quantity_pending
        );
    }

    private function refreshAlert(InventoryBalance $balance, string $reason): void
    {
        $this->alerts->recalculateForItemWarehouse((int) $balance->item_id, (int) $balance->warehouse_id, $reason);
    }

    private function changeLocationLock(InventoryBalance $balance, float $changeQty): void
    {
        $location = InventoryLocationBalance::where([
            'item_id' => $balance->item_id,
            'warehouse_id' => $balance->warehouse_id,
            'location_id' => $balance->location_id,
        ])->lockForUpdate()->first();
        if (!$location) return;
        $locked = max(0, (float) $location->quantity_locked + $changeQty);
        $location->update([
            'quantity_locked' => $locked,
            'quantity_available' => $this->availability->calculate(
                (float) $location->quantity_on_hand,
                $locked,
                (float) $location->quantity_defective,
                (float) $location->quantity_pending,
            ),
            'last_transaction_at' => now(),
        ]);
    }

    private function consumeLocationReservation(InventoryBalance $balance, float $qty): void
    {
        $location = InventoryLocationBalance::where([
            'item_id' => $balance->item_id,
            'warehouse_id' => $balance->warehouse_id,
            'location_id' => $balance->location_id,
        ])->lockForUpdate()->first();
        if (!$location) return;
        $onHand = max(0, (float) $location->quantity_on_hand - $qty);
        $locked = max(0, (float) $location->quantity_locked - $qty);
        $location->update([
            'quantity_on_hand' => $onHand,
            'quantity_locked' => $locked,
            'quantity_available' => $this->availability->calculate($onHand, $locked, (float) $location->quantity_defective, (float) $location->quantity_pending),
            'last_transaction_at' => now(),
        ]);
    }
    public function queryReservation(SalesOrder $order)
    {
        return InventoryReservation::where('source_type', InventoryReservation::SOURCE_SALES_ORDER)->where('source_order_id', $order->id)->get();
    }
}
