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

    public function reserveProductionReplenishment(
        int $salesOrderId,
        int $salesOrderLineId,
        int $fulfillmentId,
        int $inventoryBalanceId,
        float $baseQty,
        int $outputRecordId,
        ?int $inventorySerialId = null,
    ): InventoryReservation {
        return DB::transaction(function () use ($salesOrderId, $salesOrderLineId, $fulfillmentId, $inventoryBalanceId, $baseQty, $outputRecordId, $inventorySerialId): InventoryReservation {
            $key = "sales_order:{$salesOrderId}:production_output:{$outputRecordId}";
            $existing = InventoryReservation::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) return $existing;
            $balance = InventoryBalance::query()->whereKey($inventoryBalanceId)->lockForUpdate()->first();
            if (! $balance || $baseQty <= 0 || $this->availability->availableForOutbound($balance) + 0.00000001 < $baseQty) {
                throw new \RuntimeException('生产回补库存不足，不能锁回来源销售订单');
            }
            $reservation = InventoryReservation::create([
                'source_type' => InventoryReservation::SOURCE_SALES_ORDER,
                'source_order_id' => $salesOrderId,
                'source_order_line_id' => $salesOrderLineId,
                'sales_order_fulfillment_id' => $fulfillmentId,
                'item_id' => $balance->item_id,
                'inventory_balance_id' => $balance->id,
                'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id,
                'batch_no' => $balance->batch_no,
                'reserved_qty' => $baseQty,
                'reservation_status' => 'active',
                'reserved_at' => now(),
                'idempotency_key' => $key,
                'reservation_snapshot' => [
                    'reservation_origin' => 'production_replenishment',
                    'production_output_record_id' => $outputRecordId,
                    'fulfillment_id' => $fulfillmentId,
                    'inventory_serial_id' => $inventorySerialId,
                    'balance_table' => 'erp_inventory_balances',
                    'available_before_qty' => $this->availability->availableForOutbound($balance),
                ],
            ]);
            $locked = (float) $balance->quantity_locked + $baseQty;
            $balance->update([
                'quantity_locked' => $locked,
                'quantity_available' => $this->availableAfterLock($balance, $locked),
                'last_transaction_at' => now(),
            ]);
            $this->refreshAlert($balance, 'sales_order_production_replenishment');
            $this->changeLocationLock($balance, $baseQty);
            return $reservation;
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

    public function adjustReservation(SalesOrder $order, string $reason = '订单履约数量变更'): array
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
    public function allocateToShipment(int $reservationId, float $qty, string $shipmentNo): InventoryReservation
    {
        return DB::transaction(function () use ($reservationId, $qty, $shipmentNo): InventoryReservation {
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
                'item_id' => $reservation->item_id,
                'inventory_balance_id' => $reservation->inventory_balance_id,
                'warehouse_id' => $reservation->warehouse_id,
                'location_id' => $reservation->location_id,
                'batch_no' => $reservation->batch_no,
                'reserved_qty' => $qty,
                'reservation_status' => 'converted_to_shipment',
                'reserved_at' => $reservation->reserved_at,
                'idempotency_key' => $reservation->idempotency_key.':shipment:'.$shipmentNo,
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
