<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryReservation;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\SalesShipment;
use App\Models\Erp\SalesShipmentLine;
use App\Models\Erp\SalesShipmentLog;
use App\Models\Erp\SalesShipmentPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Sales shipment is an independent business fact; it never stores logistics on the order header. */
class SalesShipmentApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly SalesOrderFundingGateService $fundingGates,
        private readonly InventoryReservationService $reservations,
        private readonly InventoryService $inventory,
        private readonly SalesOrderActualCostService $actualCosts,
    ) {}

    public function create(int $orderId, array $payload, string $operator): SalesShipment
    {
        return DB::transaction(function () use ($orderId, $payload, $operator): SalesShipment {
            $order = SalesOrder::query()->with('lines')->lockForUpdate()->findOrFail($orderId);
            $this->fundingGates->assertCanShip($order);
            if (!in_array($order->order_status, ['confirmed', 'in_progress'], true)) {
                throw ValidationException::withMessages(['order' => '只有已确认的销售订单才可以创建发货单。']);
            }
            $shipment = SalesShipment::create([
                'shipment_no' => $this->numbers->next('sales_shipment', 'SHP'),
                'sales_order_id' => $order->id,
                'shipment_status' => 'draft',
                'carrier_name_snapshot' => $payload['carrier_name'] ?? $order->default_carrier_name_snapshot,
                'tracking_no' => $payload['tracking_no'] ?? null,
                'receiver_snapshot' => $order->shipping_address_snapshot,
                'actual_freight_amount' => $payload['actual_freight_amount'] ?? 0,
                'remark' => $payload['remark'] ?? null,
                'created_by' => $operator,
            ]);

            foreach ($payload['lines'] ?? [] as $index => $row) {
                $fulfillment = SalesOrderFulfillment::query()
                    ->where('sales_order_id', $order->id)
                    ->where('id', $row['sales_order_fulfillment_id'] ?? 0)
                    ->where('fulfillment_type', 'inventory')
                    ->where('demand_status', 'confirmed')
                    ->lockForUpdate()->firstOrFail();
                $parent = InventoryReservation::query()
                    ->where('source_type', InventoryReservation::SOURCE_SALES_ORDER)
                    ->where('source_order_id', $order->id)
                    ->where('source_order_line_id', $fulfillment->sales_order_line_id)
                    ->where('inventory_balance_id', $fulfillment->inventory_balance_id)
                    ->where('reservation_status', 'active')
                    ->lockForUpdate()->firstOrFail();
                $baseQty = round((float) ($row['base_qty'] ?? $parent->reserved_qty), 8);
                $reserved = $this->reservations->allocateToShipment($parent->id, $baseQty, $shipment->shipment_no);
                $line = SalesOrderLine::query()->findOrFail($fulfillment->sales_order_line_id);
                $serialIds = array_values(array_unique(array_map('intval', (array) ($row['inventory_serial_ids'] ?? []))));
                $trackingMode = $reserved->item?->serialTrackingMode() ?? 'none';
                if ($trackingMode === 'required'
                    && (abs($baseQty - round($baseQty)) > 0.00000001 || count($serialIds) !== (int) round($baseQty))) {
                    throw ValidationException::withMessages([
                        'lines.'.$index.'.inventory_serial_ids' => '该物料必须逐件选择设备编号/序列号，且数量必须与本次发货基础数量一致。',
                    ]);
                }
                if ($trackingMode === 'none' && $serialIds !== []) {
                    throw ValidationException::withMessages([
                        'lines.'.$index.'.inventory_serial_ids' => '当前物料未启用序列号管理，不允许填写设备编号/序列号。',
                    ]);
                }
                $factor = (float) ($line->fulfillment_factor_snapshot ?: 1);
                SalesShipmentLine::create([
                    'shipment_id' => $shipment->id,
                    'sales_order_line_id' => $line->id,
                    'sales_order_fulfillment_id' => $fulfillment->id,
                    'inventory_reservation_id' => $reserved->id,
                    'item_id' => $reserved->item_id,
                    'warehouse_id' => $reserved->warehouse_id,
                    'location_id' => $reserved->location_id,
                    'batch_no' => $reserved->batch_no,
                    'unit_id' => $line->item_base_unit_id ?: $line->unit_id,
                    'sales_qty' => round($baseQty / $factor, 8),
                    'base_qty' => $baseQty,
                    'serial_snapshot' => ['inventory_serial_ids' => $serialIds],
                    'line_status' => 'draft',
                    'remark' => $row['remark'] ?? null,
                ]);
            }
            if (!$shipment->lines()->exists()) throw ValidationException::withMessages(['lines' => '发货单至少需要一行库存履约明细。']);
            $this->syncPackages($shipment, (array) ($payload['packages'] ?? []));
            $this->log($shipment, 'create', null, 'draft', $operator, '创建销售发货单草稿。');
            return $shipment->fresh(['lines', 'packages', 'order']);
        });
    }

    public function confirm(SalesShipment $shipment, string $operator): SalesShipment
    {
        return DB::transaction(function () use ($shipment, $operator): SalesShipment {
            $shipment = SalesShipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $this->fundingGates->assertCanShip($shipment->sales_order_id);
            if ($shipment->shipment_status !== 'draft') throw ValidationException::withMessages(['shipment' => '只有草稿发货单可以确认。']);
            $shipment->update(['shipment_status' => 'pending_outbound', 'confirmed_at' => now(), 'confirmed_by' => $operator]);
            $shipment->lines()->update(['line_status' => 'pending_outbound']);
            $this->log($shipment, 'confirm', 'draft', 'pending_outbound', $operator, '已复核资金门禁，等待销售出库。');
            return $shipment->fresh(['lines', 'packages']);
        });
    }

    public function postOutbound(SalesShipment $shipment, string $operator): SalesShipment
    {
        return DB::transaction(function () use ($shipment, $operator): SalesShipment {
            $shipment = SalesShipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $this->fundingGates->assertCanShip($shipment->sales_order_id);
            if ($shipment->shipment_status !== 'pending_outbound') throw ValidationException::withMessages(['shipment' => '当前发货单不处于待出库状态。']);
            $transaction = $this->inventory->postSalesShipment($shipment, $operator);
            $shipment->refresh();
            $shipment->update(['shipment_status' => 'outbound_posted', 'outbound_posted_at' => now(), 'outbound_posted_by' => $operator]);
            $shipment->lines()->update(['line_status' => 'outbound_posted']);
            $this->refreshOrderShipmentStatus($shipment->sales_order_id);
            $this->log($shipment, 'post_outbound', 'pending_outbound', 'outbound_posted', $operator, '销售出库已过账：'.$transaction->transaction_no);
            return $shipment->fresh(['lines', 'packages']);
        });
    }

    public function dispatch(SalesShipment $shipment, string $operator): SalesShipment
    {
        return DB::transaction(function () use ($shipment, $operator): SalesShipment {
            $shipment = SalesShipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if ($shipment->shipment_status !== 'outbound_posted') throw ValidationException::withMessages(['shipment' => '只有已出库的发货单可以发运。']);
            $shipment->update(['shipment_status' => 'shipped', 'shipped_at' => now()]);
            $shipment->packages()->update(['package_status' => 'shipped']);
            $this->log($shipment, 'dispatch', 'outbound_posted', 'shipped', $operator, '物流已发运。');
            return $shipment->fresh(['lines', 'packages']);
        });
    }

    public function cancel(SalesShipment $shipment, string $reason, string $operator): SalesShipment
    {
        return DB::transaction(function () use ($shipment, $reason, $operator): SalesShipment {
            $shipment = SalesShipment::query()->with('lines')->lockForUpdate()->findOrFail($shipment->id);
            if (!in_array($shipment->shipment_status, ['draft', 'pending_outbound'], true)) {
                throw ValidationException::withMessages(['shipment' => '已出库或已发运的发货单不能取消，应进入销售退货/红冲流程。']);
            }
            $this->reservations->releaseShipmentReservation($shipment->lines->pluck('inventory_reservation_id')->filter()->all(), $reason);
            $before = $shipment->shipment_status;
            $shipment->update(['shipment_status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $shipment->lines()->update(['line_status' => 'cancelled']);
            $this->log($shipment, 'cancel', $before, 'cancelled', $operator, $reason);
            return $shipment->fresh(['lines', 'packages']);
        });
    }

    private function syncPackages(SalesShipment $shipment, array $packages): void
    {
        foreach (array_values($packages) as $index => $package) {
            SalesShipmentPackage::create([
                'shipment_id' => $shipment->id,
                'package_no' => $package['package_no'] ?? ($shipment->shipment_no.'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)),
                'weight' => $package['weight'] ?? null,
                'volume' => $package['volume'] ?? null,
                'carrier_name' => $package['carrier_name'] ?? $shipment->carrier_name_snapshot,
                'tracking_no' => $package['tracking_no'] ?? $shipment->tracking_no,
                'freight_amount' => $package['freight_amount'] ?? 0,
                'remark' => $package['remark'] ?? null,
            ]);
        }
    }

    private function refreshOrderShipmentStatus(int $orderId): void
    {
        $order = SalesOrder::query()->with('lines')->lockForUpdate()->findOrFail($orderId);
        $shippedByLine = SalesShipmentLine::query()->whereHas('shipment', fn ($q) => $q->where('sales_order_id', $orderId)->whereIn('shipment_status', ['outbound_posted', 'shipped', 'completed']))
            ->selectRaw('sales_order_line_id, SUM(sales_qty) AS shipped_qty')->groupBy('sales_order_line_id')->pluck('shipped_qty', 'sales_order_line_id');
        foreach ($order->lines as $line) $line->update(['shipped_qty' => $shippedByLine[$line->id] ?? 0]);
        $complete = $order->lines->every(fn (SalesOrderLine $line) => (float) ($shippedByLine[$line->id] ?? 0) + 0.00000001 >= (float) $line->order_qty);
        $order->update(['shipment_status' => $complete ? 'shipped' : 'partially_shipped']);
        $this->actualCosts->refresh($orderId);
    }

    private function log(SalesShipment $shipment, string $action, ?string $before, ?string $after, string $operator, string $content): void
    {
        SalesShipmentLog::create(['shipment_id' => $shipment->id, 'action' => $action, 'before_status' => $before, 'after_status' => $after, 'operator' => $operator, 'content' => $content]);
    }
}
