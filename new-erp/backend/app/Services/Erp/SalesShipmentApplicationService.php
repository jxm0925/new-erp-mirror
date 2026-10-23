<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryReservation;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\SalesShipment;
use App\Models\Erp\SalesShipmentLine;
use App\Models\Erp\SalesShipmentLog;
use App\Models\Erp\SalesShipmentPackage;
use App\Models\Erp\ShipmentDispatch;
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
        private readonly ShipmentPackingApplicationService $packing,
        private readonly ShipmentCommandService $commands,
    ) {}

    public function create(int $orderId, array $payload, string $operator, object $actor): SalesShipment
    {
        $response = $this->commands->run('create_shipment', $orderId, $payload, $actor,
            fn () => ['shipment_id' => $this->createDraft($orderId, $payload, $operator)->id]);
        return SalesShipment::with(['lines', 'packages', 'order'])->findOrFail($response['shipment_id']);
    }

    private function createDraft(int $orderId, array $payload, string $operator): SalesShipment
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

            $usedSerialIds = [];
            foreach ($payload['lines'] ?? [] as $index => $row) {
                $fulfillment = SalesOrderFulfillment::query()
                    ->where('sales_order_id', $order->id)
                    ->where('id', $row['sales_order_fulfillment_id'] ?? 0)
                    ->whereIn('fulfillment_type', ['inventory', 'production', 'purchase'])
                    ->where('demand_status', 'confirmed')
                    ->lockForUpdate()->firstOrFail();
                $parents = InventoryReservation::query()
                    ->where('source_type', InventoryReservation::SOURCE_SALES_ORDER)
                    ->where('source_order_id', $order->id)
                    ->where('source_order_line_id', $fulfillment->sales_order_line_id)
                    ->where(function ($query) use ($fulfillment): void {
                        $query->where('sales_order_fulfillment_id', $fulfillment->id)
                            ->orWhere(function ($legacy) use ($fulfillment): void {
                                $legacy->whereNull('sales_order_fulfillment_id')
                                    ->where('inventory_balance_id', $fulfillment->inventory_balance_id);
                            });
                    })
                    ->where('reservation_status', 'active')
                    ->orderBy('reserved_at')->orderBy('id')->lockForUpdate()->get();
                if ($parents->isEmpty()) {
                    throw ValidationException::withMessages(['lines.'.$index => '当前订单行没有可用于发货的有效库存预留。']);
                }
                $availableQty = round((float) $parents->sum('reserved_qty'), 8);
                $baseQty = round((float) ($row['base_qty'] ?? $availableQty), 8);
                if ($baseQty <= 0 || $baseQty > $availableQty + 0.00000001) {
                    throw ValidationException::withMessages(['lines.'.$index.'.base_qty' => '本次发货数量超过该订单行的有效库存预留。']);
                }
                $line = SalesOrderLine::query()->findOrFail($fulfillment->sales_order_line_id);
                $baseUnit = app(UnitConversionDomainService::class)->canonicalUnit(\App\Models\Erp\Unit::find($line->item_base_unit_id ?: $line->unit_id));
                if (! $baseUnit) throw ValidationException::withMessages(['lines.'.$index => '发货明细缺少有效基础单位。']);
                app(UnitConversionDomainService::class)->assertUnitPrecision($baseQty, $baseUnit, 'lines.'.$index.'.base_qty');
                if (! SalesOrderFulfillmentPolicy::isPhysical((string) $line->line_type)) {
                    throw ValidationException::withMessages(['lines.'.$index => '服务或无需发货的订单行不能创建实物发货。']);
                }
                $factor = (float) $line->fulfillment_factor_snapshot;
                if ($factor <= 0 || (int) $fulfillment->item_id !== (int) $line->item_id
                    || ($fulfillment->fulfillment_factor_snapshot !== null && abs((float) $fulfillment->fulfillment_factor_snapshot - $factor) > 0.00000001)) {
                    throw ValidationException::withMessages(['lines.'.$index => '订单行与库存备货的物料或单位快照不一致，请先核对。']);
                }
                // 直接汇总有效发货明细，避免旧 shipped_qty 缓存含待发运数量时被重复扣除。
                $allocatedSalesQty = (float) SalesShipmentLine::query()->where('sales_order_line_id', $line->id)
                    ->where('line_status', '!=', 'replaced')
                    ->whereHas('shipment', fn ($q) => $q->whereIn('shipment_status', ['draft', 'pending_outbound', 'prepared', 'partially_shipped', 'outbound_posted', 'shipped', 'completed']))->sum('sales_qty');
                $remainingSalesQty = max(0, SalesOrderFulfillmentPolicy::effectiveQuantity((float) $line->order_qty, (float) $line->cancelled_qty)
                    - $allocatedSalesQty);
                if ($baseQty / $factor > $remainingSalesQty + 0.00000001) {
                    throw ValidationException::withMessages(['lines.'.$index.'.base_qty' => '本次发货数量超过扣除取消量及其他发货单后的订单有效待发数量。']);
                }
                $serialIds = array_values(array_unique(array_map('intval', (array) ($row['inventory_serial_ids'] ?? []))));
                if (array_intersect($usedSerialIds, $serialIds) !== []) {
                    throw ValidationException::withMessages(['lines.'.$index.'.inventory_serial_ids' => '同一设备编号/序列号不能分配到多个发货行。']);
                }
                $usedSerialIds = array_merge($usedSerialIds, $serialIds);
                $trackingMode = $parents->first()->item?->serialTrackingMode() ?? 'none';
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
                $serialsByBalance = $serialIds === [] ? collect() : InventorySerial::query()->whereIn('id', $serialIds)
                    ->whereIn('inventory_balance_id', $parents->pluck('inventory_balance_id'))->where('serial_status', 'available')
                    ->where('item_id', $line->item_id)->lockForUpdate()->get()->groupBy('inventory_balance_id');
                if ($serialIds !== [] && $serialsByBalance->flatten(1)->count() !== count($serialIds)) {
                    throw ValidationException::withMessages(['lines.'.$index.'.inventory_serial_ids' => '所选序列号不属于该订单行预留的真实库存来源，或已被其他业务使用。']);
                }
                $remainingQty = $baseQty;
                $allocatedSalesQty = '0.00000000';
                $rowSalesQty = number_format(round($baseQty / $factor, 8), 8, '.', '');
                if ($trackingMode === 'optional' && $serialIds !== []) {
                    $parents = $parents->sortByDesc(fn ($parent) => ($serialsByBalance->get($parent->inventory_balance_id) ?? collect())->isNotEmpty())->values();
                }
                foreach ($parents as $parent) {
                    if ($remainingQty <= 0.00000001) break;
                    $sliceQty = min($remainingQty, (float) $parent->reserved_qty);
                    $parentSerialIds = ($serialsByBalance->get($parent->inventory_balance_id) ?? collect())->pluck('id')->map(fn ($id) => (int) $id)->all();
                    if ($trackingMode === 'optional' && $serialIds !== []) {
                        $otherIdentities = $serialsByBalance->flatten(1)->count() - count($parentSerialIds);
                        $sliceQty = min($sliceQty, max(0, $remainingQty - $otherIdentities));
                        if ($sliceQty <= 0) continue;
                    }
                    if ($trackingMode === 'required') {
                        $sliceQty = min($sliceQty, (float) count($parentSerialIds));
                        if ($sliceQty <= 0) continue;
                    }
                    // Serial identities are grouped by their factual balance. Sequentially slicing
                    // a request across unrelated parents can place another batch's device into this line.
                    $sliceSerialIds = array_slice($parentSerialIds, 0, (int) floor($sliceQty + 0.00000001));
                    $serialsByBalance->put($parent->inventory_balance_id,
                        ($serialsByBalance->get($parent->inventory_balance_id) ?? collect())->slice(count($sliceSerialIds))->values());
                    // 新设备一台一行，分批发运和换机只处理该台；数量库存保留独立的无 SN 行。
                    $identityGroups = collect($sliceSerialIds)->map(fn ($id) => ['base_qty' => 1.0, 'serial_ids' => [(int) $id]]);
                    $quantityWithoutIdentity = round($sliceQty - count($sliceSerialIds), 8);
                    if ($trackingMode === 'optional' && $quantityWithoutIdentity > 0.00000001) {
                        $sourceBalance = \App\Models\Erp\InventoryBalance::query()->whereKey($parent->inventory_balance_id)->lockForUpdate()->firstOrFail();
                        $identified = InventorySerial::query()->where('inventory_balance_id', $sourceBalance->id)
                            ->whereNotIn('serial_status', ['shipped', 'returned', 'production_consumed', 'packing_consumed', 'adjusted_out', 'sales_return_scrapped', 'sales_return_rejected'])
                            ->lockForUpdate()->get()->count();
                        $anonymousAllocated = SalesShipmentLine::query()->whereHas('reservation', fn ($q) => $q->where('inventory_balance_id', $sourceBalance->id)->where('reservation_status', 'converted_to_shipment'))
                            ->where('line_status', '!=', 'replaced')->lockForUpdate()->get()->sum(fn ($allocated) => max(0, (float) $allocated->base_qty - count($allocated->serial_snapshot['inventory_serial_ids'] ?? [])));
                        if ($quantityWithoutIdentity > max(0, (float) $sourceBalance->quantity_on_hand - $identified - $anonymousAllocated) + 0.00000001)
                            throw ValidationException::withMessages(['serial_ids' => '该来源已有真实设备编号，请选择对应设备；不能把有编号的设备作为无编号数量发货。']);
                    }
                    if ($quantityWithoutIdentity > 0.00000001) $identityGroups->push(['base_qty' => $quantityWithoutIdentity, 'serial_ids' => []]);
                    foreach ($identityGroups as $groupIndex => $group) {
                        $lastGroup = $groupIndex === $identityGroups->count() - 1 && abs($remainingQty - $sliceQty) <= 0.00000001;
                        $salesQty = app(ShipmentDirectSourcePolicy::class)->salesQuantitySlice($rowSalesQty, number_format($baseQty, 8, '.', ''),
                            number_format($group['base_qty'], 8, '.', ''), $allocatedSalesQty, $lastGroup);
                        $allocatedSalesQty = bcadd($allocatedSalesQty, $salesQty, 8);
                        $reserved = $this->reservations->allocateToShipment($parent->id, $group['base_qty'], $shipment->shipment_no, $index.'-'.$parent->id.'-'.$groupIndex);
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
                        'sales_qty' => $salesQty,
                        'base_qty' => $group['base_qty'],
                        'serial_snapshot' => ['inventory_serial_ids' => $group['serial_ids']],
                        'line_status' => 'draft',
                        'remark' => $row['remark'] ?? null,
                    ]);
                    }
                    $remainingQty = round($remainingQty - $sliceQty, 8);
                }
                if ($remainingQty > 0.00000001 || $serialsByBalance->flatten(1)->isNotEmpty()) {
                    throw ValidationException::withMessages(['serial_ids' => '发货数量或序列号未能完整匹配订单行的实际库存预留。']);
                }
            }
            InventorySerial::query()->whereIn('id', $usedSerialIds)->update(['serial_status' => 'shipment_reserved', 'reserved_at' => now()]);
            if (!$shipment->lines()->exists()) throw ValidationException::withMessages(['lines' => '发货单至少需要一行有库存预留的订单明细。']);
            $this->syncPackages($shipment, (array) ($payload['packages'] ?? []));
            $this->packing->freezeRequirements($shipment);
            $this->reservations->refreshSalesOrderExecution((int) $shipment->sales_order_id);
            $this->log($shipment, 'create', null, 'draft', $operator, '创建销售发货单草稿。');
            return $shipment->fresh(['lines', 'packages', 'order']);
        });
    }

    public function confirm(SalesShipment $shipment, string $operator, array $payload, object $actor): SalesShipment
    {
        return $this->mutate('confirm_shipment', $shipment, $payload, $actor, function ($shipment) use ($operator): void {
            $this->fundingGates->assertCanShip($shipment->sales_order_id);
            if ($shipment->shipment_status !== 'draft') throw ValidationException::withMessages(['shipment' => '只有草稿发货单可以确认。']);
            $shipment->update(['shipment_status' => 'pending_outbound', 'confirmed_at' => now(), 'confirmed_by' => $operator]);
            $shipment->lines()->update(['line_status' => 'pending_outbound']);
            $this->log($shipment, 'confirm', 'draft', 'pending_outbound', $operator, '发货单已确认，等待移至待发区。');
        });
    }

    public function postOutbound(SalesShipment $shipment, string $operator, array $payload, object $actor): SalesShipment
    {
        return $this->mutate('prepare_shipment', $shipment, $payload, $actor, function ($shipment) use ($payload, $actor, $operator): void {
            if ($shipment->shipment_status !== 'pending_outbound') throw ValidationException::withMessages(['shipment' => '当前发货单不处于待移位状态。']);
            $this->fundingGates->assertCanShip($shipment->sales_order_id);
            $target = $this->location((int) ($payload['staging_location_id'] ?? 0), 'shipping_staging');
            foreach ($shipment->lines()->orderBy('id')->lockForUpdate()->get() as $line) {
                $this->inventory->moveSalesShipmentLine($line, (int) $target->id, 'stage', $actor);
                $line->update(['line_status' => 'prepared', 'prepared_at' => now()]);
                $this->packing->resetSerialRound($line);
            }
            $shipment->update(['shipment_status' => 'prepared', 'staged_at' => now()]);
            $this->log($shipment, 'prepare', 'pending_outbound', 'prepared', $operator, '移至待发区并保持库存占用，公司总库存及成本不变。');
        });
    }

    public function dispatch(SalesShipment $shipment, string $operator, array $payload, object $actor): SalesShipment
    {
        return $this->mutate('dispatch_shipment', $shipment, $payload, $actor, function ($shipment) use ($payload, $actor, $operator): void {
            if (! in_array($shipment->shipment_status, ['prepared', 'partially_shipped', 'outbound_posted'], true)) throw ValidationException::withMessages(['shipment' => '只有已移至待发区的发货单可以确认发运或提货。']);
            $this->fundingGates->assertCanShip($shipment->sales_order_id);
            $packageIds = array_values(array_unique(array_map('intval', $payload['package_ids'] ?? [])));
            $packages = $shipment->packages()->whereIn('id', $packageIds)->where('package_status', '!=', 'shipped')->orderBy('id')->lockForUpdate()->get();
            if ($packageIds === [] || $packages->count() !== count($packageIds)) throw ValidationException::withMessages(['package_ids' => '请选择本单尚未发运的真实包裹。']);
            $mode = $payload['delivery_mode'] ?? '';
            if (! in_array($mode, ['dispatch', 'pickup'], true)) throw ValidationException::withMessages(['delivery_mode' => '请选择发运或客户提货。']);
            $this->packing->assertReady($shipment, $packageIds);
            $contents = $shipment->packingContents()->whereIn('package_id', $packageIds)->get();
            $lineIds = $contents->pluck('shipment_line_id')->unique()->map('intval')->all();
            $lines = $shipment->lines()->whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) throw ValidationException::withMessages(['package_ids' => '包裹没有可发运的产品明细。']);
            foreach ($lines as $line) {
                if (in_array($line->line_status, ['shipped', 'replaced', 'repair', 'cancelled'], true)
                    || bccomp((string) $contents->where('shipment_line_id', $line->id)->sum('base_qty'), (string) $line->base_qty, 8) !== 0) {
                    throw ValidationException::withMessages(['package_ids' => '所选包裹必须完整覆盖对应发货明细，返修、换出或已发设备不能发运。']);
                }
            }
            // 旧单已经扣库的事实只认真实过账，状态本身不是免扣库凭证。
            $legacy = DB::table('erp_inventory_transactions')->where('source_type', 'sales_shipment')->where('source_id', $shipment->id)
                ->where('transaction_type', 'sales_shipment_outbound')->where('posting_status', 'posted')->first();
            if ($shipment->shipment_status === 'outbound_posted' && ! $legacy) throw ValidationException::withMessages(['shipment' => '历史发货单缺少真实销售出库事实，不能跳过库存记账。']);
            if ($legacy) foreach ($lines as $line) {
                $proof = DB::table('erp_inventory_transaction_items')->where('transaction_id', $legacy->id)->where('source_type', 'sales_shipment')
                    ->where('source_id', $shipment->id)->where('source_item_id', $line->id)->where('item_id', $line->item_id)
                    ->where('warehouse_id', $line->warehouse_id)->where('location_id', $line->location_id)->where('batch_no', $line->batch_no)
                    ->where('change_qty', bcsub('0', (string) $line->base_qty, 8))->exists();
                if (! $proof) throw ValidationException::withMessages(['shipment' => '历史销售出库与本次所选明细不符，不能免记库存。']);
            }
            $dispatch = ShipmentDispatch::create(['dispatch_no' => $this->numbers->next('shipment_dispatch', 'DSP'), 'shipment_id' => $shipment->id,
                'delivery_mode' => $mode, 'package_ids' => $packageIds, 'shipment_line_ids' => $lineIds,
                'operator_legacy_id' => (int) $actor->legacy_id, 'operator_name' => $operator, 'dispatched_at' => now()]);
            $transaction = $legacy ?: $this->inventory->postSalesShipmentDispatch($dispatch, $shipment, $lineIds, $actor);
            $dispatch->update(['inventory_transaction_id' => $transaction->id]);
            foreach ($lines as $line) $line->update(['line_status' => 'shipped', 'dispatched_at' => now()]);
            $shipment->packages()->whereIn('id', $packageIds)->update(['package_status' => 'shipped']);
            $remaining = $shipment->lines()->whereNotIn('line_status', ['shipped', 'replaced', 'cancelled'])->exists();
            $before = $shipment->shipment_status;
            $shipment->update(['shipment_status' => $remaining ? 'partially_shipped' : 'shipped', 'delivery_mode' => $mode,
                'shipped_at' => now(), 'outbound_posted_at' => $shipment->outbound_posted_at ?: now(), 'outbound_posted_by' => $operator]);
            $this->refreshOrderShipmentStatus((int) $shipment->sales_order_id);
            $this->log($shipment, 'dispatch', $before, $shipment->shipment_status, $operator, ($mode === 'pickup' ? '客户已提货' : '物流已发运').'，批次 '.$dispatch->dispatch_no.'；正式出库 '.$transaction->transaction_no);
        });
    }

    public function cancel(SalesShipment $shipment, string $reason, string $operator, array $payload, object $actor): SalesShipment
    {
        return $this->mutate('cancel_shipment', $shipment, $payload, $actor, function ($shipment) use ($reason, $operator, $actor): void {
            if (! in_array($shipment->shipment_status, ['draft', 'pending_outbound', 'prepared'], true)) {
                throw ValidationException::withMessages(['shipment' => '已经正式出库或部分发运的单据不能整单取消。']);
            }
            if ($shipment->packingOperations()->whereHas('laborSessions', fn ($q) => $q->where('status', 'ACTIVE'))->exists()) throw ValidationException::withMessages(['shipment' => '请参与人员先暂停本人工时，再取消待发准备。']);
            foreach ($shipment->lines()->where('line_status', '!=', 'replaced')->orderBy('id')->lockForUpdate()->get() as $line) {
                if ($line->line_status === 'repair') {
                    $this->reservations->releaseShipmentReservation((int) $line->inventory_reservation_id, '取消发货，失败设备保留返修：'.$reason);
                    continue;
                }
                if ($line->prepared_at) $this->inventory->moveSalesShipmentLine($line, (int) data_get($line->preparation_snapshot, 'location_id'), 'return', $actor);
                $this->reservations->restoreShipmentReservationToOrder([(int) $line->inventory_reservation_id], '发货单取消，恢复原订单库存锁定：'.$reason);
                InventorySerial::query()->whereIn('id', $line->serial_snapshot['inventory_serial_ids'] ?? [])->where('serial_status', '!=', 'repair')->update(['serial_status' => 'available', 'reserved_at' => null]);
            }
            $before = $shipment->shipment_status;
            $shipment->update(['shipment_status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $shipment->lines()->whereNotIn('line_status', ['repair', 'replaced'])->update(['line_status' => 'cancelled']);
            $shipment->packingOperations()->where('status', '!=', 'COMPLETED')->update(['status' => 'CANCELLED']);
            $this->log($shipment, 'cancel', $before, 'cancelled', $operator, $reason);
        });
    }

    private function mutate(string $action, SalesShipment $shipment, array $payload, object $actor, \Closure $mutation): SalesShipment
    {
        $response = $this->commands->run($action, (int) $shipment->id, $payload, $actor, function ($locked) use ($payload, $mutation): array {
            if ((int) ($payload['expected_version'] ?? 0) !== (int) $locked->business_version) throw ValidationException::withMessages(['expected_version' => '发货单已变化，请刷新后重试。']);
            $mutation($locked);
            $this->reservations->refreshSalesOrderExecution((int) $locked->sales_order_id);
            $locked->increment('business_version');
            return ['shipment_id' => (int) $locked->id, 'business_version' => (int) $locked->business_version];
        });
        return SalesShipment::with(['lines', 'packages', 'dispatches'])->findOrFail($response['shipment_id']);
    }

    private function location(int $id, string $purpose): \App\Models\Erp\Location
    {
        $location = \App\Models\Erp\Location::query()->whereKey($id)->where('location_purpose', $purpose)
            ->whereIn('status', ['enabled', 'active'])->whereHas('warehouse', fn ($q) => $q->whereIn('status', ['enabled', 'active']))->first();
        if (! $location) {
            $label = ['repair' => '返修区', 'finished' => '成品', 'shipping_staging' => '发货待发区'][$purpose] ?? $purpose;
            throw ValidationException::withMessages(['location_id' => "所选库位不是已启用的{$label}库位，请重新选择{$label}用途的库位。"]);
        }
        return $location;
    }

    public function deleteDraft(SalesShipment $shipment): void
    {
        DB::transaction(function () use ($shipment): void {
            SalesOrder::query()->whereKey($shipment->sales_order_id)->lockForUpdate()->firstOrFail();
            $shipment = SalesShipment::query()->with('lines')->lockForUpdate()->findOrFail($shipment->id);
            if ($shipment->shipment_status !== 'draft' || $shipment->confirmed_at !== null) {
                throw ValidationException::withMessages(['shipment' => '只有从未确认、未出库的销售发货草稿可以删除。']);
            }
            $this->packing->assertCanCancel($shipment);
            if (DB::table('erp_inventory_transactions')
                ->where('source_type', 'sales_shipment')->where('source_id', $shipment->id)->exists()) {
                throw ValidationException::withMessages(['shipment' => '该发货单已产生库存流水，不能删除；请走取消、退货或红冲流程。']);
            }
            if (DB::table('erp_sales_return_cost_allocations')->where('sales_shipment_id', $shipment->id)->exists()) {
                throw ValidationException::withMessages(['shipment' => '该发货单已被销售退货成本追溯引用，不能删除。']);
            }

            $reservationIds = $shipment->lines->pluck('inventory_reservation_id')->filter()->map(fn ($id) => (int) $id)->all();
            $this->reservations->restoreShipmentReservationToOrder($reservationIds, '删除未确认的发货草稿，恢复原销售订单库存锁定');
            $serialIds = $shipment->lines->flatMap(fn ($line) => $line->serial_snapshot['inventory_serial_ids'] ?? [])->all();
            InventorySerial::query()->whereIn('id', $serialIds)->where('serial_status', 'shipment_reserved')->update(['serial_status' => 'available', 'reserved_at' => null]);
            \App\Models\Erp\ShipmentSerialResult::query()->where('shipment_id', $shipment->id)->delete();
            $shipment->delete();

            // 分拆出的发货预留已把数量归还父预留，可清理该临时行；若原预留
            // 是就地恢复为 active，则必须保留，继续作为销售订单库存锁定事实。
            InventoryReservation::query()->whereIn('id', $reservationIds)
                ->where('reservation_status', 'returned_to_order')->delete();
            $this->reservations->refreshSalesOrderExecution((int) $shipment->sales_order_id);
        }, 5);
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
        // 出库只形成库存与成本事实；实际发运后才累计销售已发货数量。
        $shippedByLine = SalesShipmentLine::query()->where('line_status', 'shipped')->whereHas('shipment', fn ($q) => $q->where('sales_order_id', $orderId)->whereIn('shipment_status', ['partially_shipped', 'shipped', 'completed']))
            ->selectRaw('sales_order_line_id, SUM(sales_qty) AS shipped_qty')->groupBy('sales_order_line_id')->pluck('shipped_qty', 'sales_order_line_id');
        foreach ($order->lines as $line) $line->update(['shipped_qty' => $shippedByLine[$line->id] ?? 0]);
        $order->update(['shipment_status' => SalesOrderFulfillmentPolicy::shipmentStatus($order->lines->map(fn ($line) => $line->getAttributes())->all())]);
        $this->actualCosts->refresh($orderId);
        app(SalesOrderCompletionService::class)->refresh($order);
    }

    private function log(SalesShipment $shipment, string $action, ?string $before, ?string $after, string $operator, string $content): void
    {
        SalesShipmentLog::create(['shipment_id' => $shipment->id, 'action' => $action, 'before_status' => $before, 'after_status' => $after, 'operator' => $operator, 'content' => $content]);
    }
}
