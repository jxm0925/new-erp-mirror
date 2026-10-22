<?php

namespace App\Services\Erp;

use App\Models\Erp\{InventoryBalance, InventorySerial, InventorySerialEvent, Location, MfgWorkOrder, MfgWorkOrderOperation, MfgWorkOrderUnit, SalesShipment, SalesShipmentLine, ShipmentPackingOperation, ShipmentRework};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 发货失败仍是公司库存；返修沿用原台身份，换机另留原台到新台的关系。 */
final class ShipmentSerialApplicationService
{
    public function __construct(private readonly InventoryService $inventory, private readonly InventoryReservationService $reservations,
        private readonly ShipmentCommandService $commands, private readonly DocumentNumberService $numbers) {}

    public function reworkOperations(SalesShipment $shipment, int $lineId, array $filters): array
    {
        $line = $shipment->lines()->findOrFail($lineId);
        $unit = MfgWorkOrderUnit::query()->whereIn('inventory_serial_id', $line->serial_snapshot['inventory_serial_ids'] ?? [])->first();
        return MfgWorkOrderOperation::query()->where('work_order_id', $unit?->work_order_id ?: 0)->where('execution_context', 'production')
            ->when(trim((string) ($filters['keyword'] ?? '')) !== '', fn ($q) => $q->where('operation_name', 'like', '%'.trim($filters['keyword']).'%'))
            ->orderBy('sequence')->orderBy('id')->paginate(10, ['id', 'operation_name', 'sequence'], 'page', max(1, (int) ($filters['page'] ?? 1)))->toArray();
    }

    public function replacementSerials(SalesShipment $shipment, int $lineId, array $filters): array
    {
        $line = $shipment->lines()->findOrFail($lineId);
        $balances = app(InventoryAvailabilityService::class)->eligibleBalances((int) $line->item_id)->filter(fn ($b) => app(InventoryAvailabilityService::class)->availableForOutbound($b) >= 1)->pluck('id');
        $reserved = DB::table('erp_inventory_reservations')->where('source_type', 'sales_order')->where('source_order_id', $shipment->sales_order_id)
            ->where('source_order_line_id', $line->sales_order_line_id)->where('reservation_status', 'active')->where('reserved_qty', '>=', 1)->pluck('inventory_balance_id');
        $page = InventorySerial::query()->with(['warehouse', 'location'])->where('item_id', $line->item_id)->where('serial_status', 'available')->whereIn('inventory_balance_id', $balances->merge($reserved)->unique())
            ->when(trim((string) ($filters['keyword'] ?? '')) !== '', fn ($q) => $q->where('serial_no', 'like', '%'.trim($filters['keyword']).'%'))
            ->orderBy('id')->paginate(10, ['id', 'serial_no', 'warehouse_id', 'location_id', 'batch_no', 'inventory_balance_id'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $page->setCollection($page->getCollection()->map(fn ($serial) => $serial->only(['id', 'serial_no', 'warehouse_id', 'location_id', 'batch_no', 'inventory_balance_id'])
            + ['warehouse_name' => $serial->warehouse?->warehouse_name, 'location_name' => $serial->location?->location_name]));
        return $page->toArray();
    }

    public function failSerial(SalesShipmentLine $line, array $payload, object $actor): void
    {
        $ids = $line->serial_snapshot['inventory_serial_ids'] ?? [];
        if (count($ids) !== 1 || bccomp((string) $line->base_qty, '1', 8) !== 0 || $line->line_status !== 'prepared') $this->fail('只有本次待发的一台设备可以转返修。');
        $this->locator((int) ($payload['repair_location_id'] ?? 0), 'repair');
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') $this->fail('请填写试水失败原因。');
        $unitIdentity = MfgWorkOrderUnit::query()->where('inventory_serial_id', $ids[0])->first();
        $unit = null; $target = null;
        if ($unitIdentity) {
            $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($unitIdentity->work_order_id);
            if (in_array($wo->status, ['closed', 'cancelled'], true)) $this->fail('原制造工单已关闭或取消，请先按正式权限处理返工。');
            $target = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->where('execution_context', 'production')
                ->whereKey((int) ($payload['return_operation_id'] ?? 0))->lockForUpdate()->first();
            if (! $target) $this->fail('请选择原设备制造路线中实际要返回的工序。');
            $unit = MfgWorkOrderUnit::query()->lockForUpdate()->findOrFail($unitIdentity->id);
            if ($unit->status !== 'stored') $this->fail('原设备已在其他制造作业中，不能重复创建发货返工。');
            $unit->update(['status' => 'rework', 'current_operation_id' => $target->id, 'rework_count' => (int) $unit->rework_count + 1]);
            $target->update(['status' => 'in_progress']);
        }
        $this->inventory->moveSalesShipmentLine($line, (int) $payload['repair_location_id'], 'repair', $actor);
        $line->update(['line_status' => 'repair']);
        ShipmentRework::create(['rework_no' => $this->numbers->next('shipment_rework', 'SRW'), 'shipment_id' => $line->shipment_id,
            'shipment_line_id' => $line->id, 'inventory_serial_id' => $ids[0], 'work_order_id' => $unit?->work_order_id,
            'unit_id' => $unit?->id, 'return_operation_id' => $target?->id, 'round' => (int) $line->quality_round,
            'reason' => $reason, 'status' => 'in_progress', 'created_by_legacy_id' => (int) $actor->legacy_id]);
    }

    public function repairReturn(SalesShipment $shipment, int $lineId, array $payload, object $actor): array
    {
        return $this->command('return_shipment_repair', $shipment, $payload, $actor, function ($locked) use ($lineId, $payload, $actor): array {
            $line = $locked->lines()->lockForUpdate()->findOrFail($lineId);
            $repair = ShipmentRework::query()->where('shipment_line_id', $line->id)->where('status', 'completed')->whereNull('returned_at')->orderByDesc('id')->lockForUpdate()->first();
            if (! $repair || ! $repair->unit_id) $this->fail('原设备的制造返工尚未全部合格，不能返回成品位。');
            $this->locator((int) $payload['finished_location_id'], 'finished');
            $this->inventory->moveSalesShipmentLine($line, (int) $payload['finished_location_id'], 'repair_return', $actor);
            $ids = $line->serial_snapshot['inventory_serial_ids'] ?? [];
            $active = ! in_array($locked->shipment_status, ['cancelled', 'shipped', 'completed'], true) && $line->line_status !== 'replaced';
            if ($active) {
                $stagingId = (int) data_get($line->preparation_snapshot, 'staging_location_id');
                $this->locator($stagingId, 'shipping_staging');
                $this->inventory->moveSalesShipmentLine($line, $stagingId, 'stage', $actor);
                $line->update(['line_status' => 'prepared', 'quality_round' => (int) $line->quality_round + 1]);
                app(ShipmentPackingApplicationService::class)->resetSerialRound($line);
            } else {
                InventorySerial::query()->whereIn('id', $ids)->update(['serial_status' => 'available', 'reserved_at' => null]);
                if ($locked->shipment_status === 'cancelled') $this->reservations->restoreRepairedShipmentToOrder($line);
            }
            $repair->update(['returned_at' => now()]);
            MfgWorkOrderUnit::query()->whereKey($repair->unit_id)->update(['status' => 'stored', 'warehouse_id' => $line->warehouse_id, 'location_id' => $line->location_id]);
            $this->event($line, 'shipment_repair_return', $actor, ['rework_id' => $repair->id, 'reason' => $payload['reason']]);
            return ['shipment_id' => (int) $locked->id, 'shipment_line_id' => (int) $line->id];
        });
    }

    public function replace(SalesShipment $shipment, int $lineId, array $payload, object $actor): array
    {
        return $this->command('replace_shipment_serial', $shipment, $payload, $actor, function ($locked) use ($lineId, $payload, $actor): array {
            if (! in_array($locked->shipment_status, ['prepared', 'partially_shipped'], true)) $this->fail('当前发货单不能换机。');
            $old = $locked->lines()->lockForUpdate()->findOrFail($lineId);
            if ($old->line_status !== 'repair') $this->fail('只能为本次试水失败、已转返修的设备换机。');
            $shippingRoute = $locked->packingContents()->where('shipment_line_id', $old->id)->first()?->routing_snapshot ?? $old->packing_routing_snapshot;
            unset($shippingRoute['packing_route_selection']);
            $serial = InventorySerial::query()->whereKey((int) $payload['replacement_inventory_serial_id'])->where('item_id', $old->item_id)
                ->where('serial_status', 'available')->first();
            if (! $serial) $this->fail('替换设备已失效或不是相同物料，请重新选择。');
            $balance = InventoryBalance::query()->whereKey($serial->inventory_balance_id)->lockForUpdate()->firstOrFail();
            $serial = InventorySerial::query()->whereKey($serial->id)->where('inventory_balance_id', $balance->id)->where('serial_status', 'available')->lockForUpdate()->first();
            if (! $serial) $this->fail('替换设备已被其他业务使用，请重新选择。');
            $eligible = app(InventoryAvailabilityService::class)->outboundBalanceQuery([(int) $old->item_id])->where('erp_inventory_balances.id', $balance->id)->exists();
            if (! $eligible) $this->fail('替换设备所在仓库、库位或批次已失效。');
            $this->reservations->releaseShipmentReservation((int) $old->inventory_reservation_id, '试水失败换机，原设备继续返修');
            $reservation = $this->reservations->reserveReplacement($old, $balance, (string) $payload['client_command_id']);
            $new = SalesShipmentLine::create($old->only(['shipment_id', 'sales_order_line_id', 'sales_order_fulfillment_id', 'item_id', 'unit_id', 'sales_qty', 'base_qty', 'packing_routing_snapshot']) + [
                'inventory_reservation_id' => $reservation->id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
                'batch_no' => $balance->batch_no, 'serial_snapshot' => ['inventory_serial_ids' => [(int) $serial->id]], 'line_status' => 'pending_outbound',
                'replaces_line_id' => $old->id, 'remark' => $payload['reason']]);
            $serial->update(['serial_status' => 'shipment_reserved', 'reserved_at' => now()]);
            app(ShipmentPackingApplicationService::class)->freezeRequirements($locked);
            // 新机沿用本次已选发货路线；原制造路线仍在新机来源快照中，不继承旧机结果。
            $new->update(['packing_routing_snapshot' => $shippingRoute]);
            $stagingId = (int) data_get($old->preparation_snapshot, 'staging_location_id');
            $this->locator($stagingId, 'shipping_staging');
            $this->inventory->moveSalesShipmentLine($new, $stagingId, 'stage', $actor);
            $new->update(['line_status' => 'prepared', 'prepared_at' => now()]);
            $old->update(['line_status' => 'replaced']);
            foreach ($locked->packingContents()->where('shipment_line_id', $old->id)->lockForUpdate()->get() as $content) {
                $content->update(['shipment_line_id' => $new->id, 'serial_snapshot' => $new->serial_snapshot, 'source_snapshot' => $new->fresh()->packing_source_snapshot,
                    'routing_snapshot' => $new->fresh()->packing_routing_snapshot]);
            }
            app(ShipmentPackingApplicationService::class)->resetSerialRound($new);
            $this->event($old, 'shipment_serial_replaced', $actor, ['replacement_inventory_serial_id' => $serial->id, 'replacement_shipment_line_id' => $new->id, 'reason' => $payload['reason']]);
            return ['shipment_id' => (int) $locked->id, 'shipment_line_id' => (int) $new->id, 'replaced_line_id' => (int) $old->id];
        });
    }

    public function projection(SalesShipment $shipment): array
    {
        return $shipment->lines()->orderBy('id')->get()->flatMap(function ($line) use ($shipment) {
            $repair = ShipmentRework::query()->where('shipment_line_id', $line->id)->orderByDesc('id')->first();
            $replacement = $shipment->lines()->where('replaces_line_id', $line->id)->first();
            return InventorySerial::query()->with(['warehouse', 'location'])->whereIn('id', $line->serial_snapshot['inventory_serial_ids'] ?? [])->get()->map(function ($serial) use ($line, $repair, $replacement, $shipment) {
                $result = DB::table('erp_shipment_serial_results as result')->join('erp_shipment_packing_operations as operation', 'operation.id', '=', 'result.operation_id')
                    ->where('result.shipment_line_id', $line->id)->where('result.round', $line->quality_round)->where('operation.shipment_operation_type_snapshot', 'water_test')
                    ->whereNotNull('result.quality_result')->orderByDesc('result.id')->value('result.quality_result');
                return ['shipment_line_id' => (int) $line->id, 'inventory_serial_id' => (int) $serial->id, 'serial_no' => $serial->serial_no,
                    'round' => (int) $line->quality_round, 'status' => match ($line->line_status) { 'draft', 'pending_outbound' => 'allocated', default => $line->line_status },
                    'quality_result' => $result, 'rework_id' => $repair?->id,
                    'replaced_by_serial_id' => $replacement?->serial_snapshot['inventory_serial_ids'][0] ?? null,
                    'allowed_actions' => ['repair_return' => $repair && $repair->status === 'completed' && ! $repair->returned_at,
                        'replace' => $line->line_status === 'repair' && in_array($shipment->shipment_status, ['prepared', 'partially_shipped'], true)]] + $this->stockLocationProjection($serial);
            });
        })->values()->all();
    }

    /** 库存身份保留最后出库位置；“当前位置”不能继续把已发设备显示为在库。 */
    public function stockLocationProjection(InventorySerial $serial): array
    {
        $shipped = $serial->serial_status === 'shipped';
        return ['warehouse_id' => $shipped ? null : (int) $serial->warehouse_id, 'warehouse_name' => $shipped ? null : $serial->warehouse?->warehouse_name,
            'location_id' => $shipped ? null : (int) $serial->location_id, 'location_name' => $shipped ? '已出库' : $serial->location?->location_name,
            'outbound_warehouse_id' => $shipped ? (int) $serial->warehouse_id : null, 'outbound_warehouse_name' => $shipped ? $serial->warehouse?->warehouse_name : null,
            'outbound_location_id' => $shipped ? (int) $serial->location_id : null, 'outbound_location_name' => $shipped ? $serial->location?->location_name : null];
    }

    private function command(string $action, SalesShipment $shipment, array $payload, object $actor, \Closure $mutation): array
    {
        return $this->commands->run($action, (int) $shipment->id, $payload, $actor, function ($locked) use ($payload, $mutation) {
            if ((int) ($payload['expected_version'] ?? 0) !== (int) $locked->business_version) $this->fail('发货单已变化，请刷新后重试。');
            $result = $mutation($locked);
            $this->reservations->refreshSalesOrderExecution((int) $locked->sales_order_id);
            $locked->increment('business_version');
            return $result + ['business_version' => (int) $locked->business_version];
        });
    }

    private function locator(int $id, string $purpose): Location
    {
        $location = Location::query()->whereKey($id)->where('location_purpose', $purpose)->whereIn('status', ['enabled', 'active'])
            ->whereHas('warehouse', fn ($q) => $q->whereIn('status', ['enabled', 'active']))->first();
        if (! $location) $this->fail('请选择已启用、用途正确的正式库位。');
        return $location;
    }

    private function event(SalesShipmentLine $line, string $type, object $actor, array $payload): void
    {
        foreach ($line->serial_snapshot['inventory_serial_ids'] ?? [] as $serialId) InventorySerialEvent::create([
            'inventory_serial_id' => $serialId, 'event_type' => $type, 'document_type' => 'sales_shipment', 'document_id' => $line->shipment_id,
            'document_no' => $line->shipment->shipment_no, 'warehouse_id' => $line->warehouse_id, 'location_id' => $line->location_id,
            'batch_no' => $line->batch_no, 'operator_id' => (int) $actor->legacy_id, 'event_payload' => $payload + ['shipment_line_id' => $line->id], 'occurred_at' => now()]);
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['shipment_serial' => $message]); }
}
