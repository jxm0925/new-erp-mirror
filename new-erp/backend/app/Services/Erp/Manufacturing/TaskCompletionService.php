<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryReservation;
use App\Models\Erp\Location;
use App\Models\Erp\MfgReceipt;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLink;
use App\Models\Erp\MfgWorkOrderMaterial;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesShipment;
use App\Models\Erp\SalesShipmentLine;
use App\Services\Erp\SalesShipmentApplicationService;
use App\Services\Erp\ShipmentPackingApplicationService;

/** 完工去向：现场只办一次交接，库存与来源成本仍走正式入库、领用或待发作业。 */
final class TaskCompletionService
{
    use ProductionActor;

    public function options(MfgWorkOrder $wo, MfgWorkOrderOperation $op): ?array
    {
        if ($wo->rework_of_work_order_id || $this->nextProduction($op)) return null;
        // 按路线冻结的「完成去向」：可选入库让人选；合格后入库只能入库；直接交接有接收方就交，没有才入库。
        $mode = self::outputMode($op);
        $target = $mode === 'warehouse_required' ? null : $this->target($wo, $op);
        return [
            'output_mode' => $mode,
            'location_purpose' => ReceiptService::allowedLocationPurposes($wo) === ['general'] ? 'general' : 'finished',
            'next_operation_id' => $target['operation_id'] ?? null,
            'next_operation_name' => $target['operation_name'] ?? null,
            'target_work_order_no' => $target['work_order_no'] ?? null,
            // 随单半成品最多交到主工单还缺的数量，多出的只能入库
            'remaining_qty' => isset($target['remaining_qty']) ? round((float) $target['remaining_qty'], 4) : null,
            'handoff_available' => $target !== null && empty($target['blocked_reason']),
            'blocked_reason' => $target['blocked_reason'] ?? null,
        ];
    }

    private static function outputMode(MfgWorkOrderOperation $op): string
    {
        return in_array($op->output_mode, ['warehouse_optional', 'warehouse_required'], true) ? $op->output_mode : 'flow_only';
    }

    private function nextProduction(MfgWorkOrderOperation $op): bool
    {
        return MfgWorkOrderOperation::query()->where('work_order_id', $op->work_order_id)->where('execution_context', 'production')
            ->where(fn ($q) => $q->where('sequence', '>', $op->sequence)
                ->orWhere(fn ($w) => $w->where('sequence', $op->sequence)->where('id', '>', $op->id)))->exists();
    }

    /** 使用本工单冻结路线及主工单实际用料节点，不凭“总装”“试水”等名称猜去向。 */
    private function target(MfgWorkOrder $wo, MfgWorkOrderOperation $op, bool $lock = false, ?array $unitIds = null): ?array
    {
        if ($wo->source_type === 'stock') return null;
        if ($unitIds !== null && \App\Models\Erp\CancellationProductionDisposition::where('work_order_id', $wo->id)->whereIn('work_order_unit_id', $unitIds)->where('status', 'stock')->exists()) return null;
        foreach (CancellationProductionService::activeParentLinks((int) $wo->id, $lock) as $link) {
            $parent = MfgWorkOrder::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($link->parent_work_order_id);
            if (! $parent || (int) $link->component_item_id !== (int) $wo->item_id) continue;
            $materials = MfgWorkOrderMaterial::query()->where('work_order_id', $parent->id)->where('component_item_id', $wo->item_id)
                ->where('participates_in_kitting', true)->where('required_qty', '>', 0)->when($lock, fn ($q) => $q->lockForUpdate())->get();
            $operations = MfgWorkOrderOperation::query()->where('work_order_id', $parent->id)->where('execution_context', 'production')
                ->whereIn('id', $materials->pluck('work_order_operation_id'))->orderBy('sequence')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
            $issues = app(MaterialIssueService::class);
            foreach ($operations as $next) {
                $rows = $issues->receivable($next, $lock)->where('component_item_id', $wo->item_id);
                $prepared = MaterialDeliveryService::preparedQty((int) $next->id);
                $expected = $issues->expectedUnits($next, $parent, $lock);
                $remaining = $rows->sum(fn ($m) => max(0, $issues->neededFor($m, $parent, $expected) - (float) $m->issued_qty - (float) ($prepared[$m->id] ?? 0)));
                if ($remaining <= 0.00001) continue;
                $blocked = ! in_array($parent->status, ['released', 'in_progress', 'paused'], true)
                    ? '主工单尚未下达或已经结束，请先入库。'
                    : ($next->status === 'done' ? '主工单的这道工序已经完成，请先入库。' : null);
                if (! $this->demandAlive($parent, $lock)) $blocked = '主工单的原销售需求已经失效，请先入库。';
                return ['kind' => 'material', 'operation_id' => (int) $next->id, 'operation_name' => $next->operation_name,
                    'work_order_id' => (int) $parent->id, 'work_order_no' => $parent->work_order_no, 'remaining_qty' => $remaining,
                    'blocked_reason' => $blocked];
            }
        }
        // 集中备货没有订单接收方，最终产出只入库。
        if ($wo->source_type !== 'sales_order' || ! $wo->sales_order_id || ! $wo->sales_fulfillment_id) return null;
        $next = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->where('execution_context', 'shipment')
            ->where(fn ($q) => $q->where('sequence', '>', $op->sequence)
                ->orWhere(fn ($w) => $w->where('sequence', $op->sequence)->where('id', '>', $op->id)))
            ->orderBy('sequence')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $next) return null;
        return ['kind' => 'shipment', 'operation_id' => (int) $next->id, 'operation_name' => $next->operation_name,
            'routing_operation_id' => (int) $next->routing_operation_id,
            'work_order_id' => (int) $wo->id, 'work_order_no' => $wo->work_order_no,
            'blocked_reason' => $this->demandAlive($wo, $lock) ? null : '原销售需求已经失效，请先入库。'];
    }

    private function demandAlive(MfgWorkOrder $wo, bool $lock): bool
    {
        if ($wo->source_type !== 'sales_order') return true;
        return SalesOrder::query()->whereKey($wo->sales_order_id)->where('order_status', 'confirmed')->whereNull('short_closed_at')
            ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']) !== null
            && SalesOrderFulfillment::query()->whereKey($wo->sales_fulfillment_id)->where('sales_order_id', $wo->sales_order_id)
                ->where('sales_order_line_id', $wo->sales_order_line_id)->where('item_id', $wo->item_id)
                ->where('fulfillment_type', 'production')->where('demand_status', 'confirmed')
                ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']) !== null;
    }

    /** 调用方已锁定报工、工单和本批设备；任何一步失败都回滚本次报工及自动记账。 */
    public function complete(MfgWorkOrder $wo, MfgWorkOrderOperation $op, array $unitIds, array $data, object $actor): array
    {
        $action = $data['completion_action'] ?? null;
        if (! in_array($action, ['store', 'handoff'], true)) $this->fail('completion_required', '请选择本次完工后入库，或交接给下一道工序。');
        $mode = self::outputMode($op);
        $stockCount = \App\Models\Erp\CancellationProductionDisposition::where('work_order_id', $wo->id)->whereIn('work_order_unit_id', $unitIds)->where('status', 'stock')->count();
        if ($mode === 'flow_only' && $stockCount > 0 && $stockCount < count($unitIds))
            $this->fail('completion_mixed_destination', '这道工序要求直接交接；正常设备和备货设备请分开提交，备货入库，正常设备按原去向交接。');
        if ($action === 'handoff' && $mode === 'warehouse_required')
            $this->fail('handoff_not_allowed', $op->operation_name.'设为合格后入库，不能直接交接，请选择入库。');
        if ($action === 'store' && $mode === 'flow_only') {
            $next = $this->target($wo, $op, true, $unitIds);
            if ($next && empty($next['blocked_reason']) && ($next['kind'] !== 'material' || count($unitIds) <= $next['remaining_qty'] + 0.00001))
                $this->fail('store_not_allowed', $op->operation_name.'设为直接交接，请交接给'.$next['operation_name'].'。', 409);
        }
        $target = $action === 'handoff' ? $this->target($wo, $op, true, $unitIds) : null;
        if ($action === 'handoff') {
            if (! $target || (int) ($data['handoff_operation_id'] ?? 0) !== $target['operation_id'])
                $this->fail('handoff_changed', '下一道工序或待接收数量已变化，请刷新后重新选择交接去向。', 409);
            if (! empty($target['blocked_reason'])) $this->fail('handoff_blocked', $target['blocked_reason']);
            if ($target['kind'] === 'material' && count($unitIds) > $target['remaining_qty'] + 0.00001)
                $this->fail('handoff_exceeds_demand', $target['operation_name'].'尚需接收 '.$target['remaining_qty'].'，请减少本次交接数量，多出的部分选择入库。');
        }
        $receipts = app(ReceiptService::class);
        $staging = ($target['kind'] ?? null) === 'shipment';
        $location = $action === 'store'
            ? Location::query()->find((int) ($data['completion_location_id'] ?? 0))
            : ($staging ? $receipts->stagingLocation($wo) : $this->handoffLocation($wo));
        if (! $location) $this->fail('completion_location_missing', $action === 'store' ? '请选择入库库位。' : '没有可用于交接记账的启用工厂库位，请先维护对应仓库及库位。');
        if ($action === 'store' && $location->location_purpose !== (ReceiptService::allowedLocationPurposes($wo) === ['general'] ? 'general' : 'finished'))
            $this->fail('completion_location_invalid', '请选择与该半成品或成品匹配的入库库位。');
        $handoff = $action === 'handoff'
            ? $receipts->receiveForHandoff((int) $wo->id, (int) $op->id, $unitIds, (int) $target['operation_id'], $actor) : null;
        $receipt = $handoff ? $handoff['receipt'] : $receipts->receive((int) $wo->id, $unitIds, (int) $location->warehouse_id,
            (int) $location->id, '工序完工提交入库', $actor);
        $result = ['completion_action' => $action, 'receipt_no' => $receipt->receipt_no, 'received_qty' => count($unitIds),
            'receipt_nos' => $receipt->relationLoaded('relatedReceipts') ? $receipt->getRelation('relatedReceipts')->pluck('receipt_no')->all() : [$receipt->receipt_no],
            'receipt_id' => $receipt->id, 'receipt_status' => $receipt->status, 'handoff_operation_name' => $target['operation_name'] ?? null];
        if (isset($handoff['shipment_no'])) $result['shipment_no'] = $handoff['shipment_no'];
        return $result;
    }

    /** 自动入库专用校验，接收方完全来自冻结路线和主工单缺口。 */
    public function validatedHandoff(MfgWorkOrder $wo, MfgWorkOrderOperation $op, array $unitIds, int $targetOperationId): array
    {
        if ($wo->rework_of_work_order_id || $op->execution_context !== 'production' || $this->nextProduction($op)
            || self::outputMode($op) === 'warehouse_required') $this->fail('handoff_not_allowed', '这道工序不能通过完工直接交接。');
        $target = $this->target($wo, $op, true, $unitIds);
        if (! $target || (int) $target['operation_id'] !== $targetOperationId)
            $this->fail('handoff_changed', '下一道工序或待接收数量已变化，请刷新后重新选择交接去向。', 409);
        if (! empty($target['blocked_reason'])) $this->fail('handoff_blocked', $target['blocked_reason']);
        if ($target['kind'] === 'material' && count($unitIds) > $target['remaining_qty'] + 0.00001)
            $this->fail('handoff_exceeds_demand', '交接数量超过主工单待收数量，多出的设备请提交入库。');
        return $target;
    }

    /** 只接受已在当前事务记账的自动交接单；失败由外层连同库存、SN、报工一起回滚。 */
    public function finishHandoff(MfgWorkOrder $wo, MfgReceipt $receipt, array $unitIds, array $target, object $actor): array
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() < 1 || $receipt->status !== 'confirmed'
            || $receipt->confirmation_source !== 'auto_handoff' || ! $receipt->inventory_transaction_id)
            $this->fail('handoff_receipt_invalid', '自动交接必须在同一正式完工事务内办理。');
        if ($target['kind'] === 'material') {
            $this->handoffMaterial($wo, $receipt, $unitIds, $target, $actor);
            return [];
        }
        return ['shipment_no' => $this->handoffShipment($wo, $receipt, $unitIds, $target, $actor)];
    }

    public function handoffLocation(MfgWorkOrder $wo): ?Location
    {
        return Location::query()->where('location_purpose', 'general')->whereIn('status', ['enabled', 'active'])
            ->whereHas('warehouse', fn ($q) => $q->whereIn('status', ['enabled', 'active'])
                ->where(fn ($w) => $w->whereNull('management_scope')->orWhere('management_scope', 'factory')))
            ->orderByRaw('warehouse_id = ? DESC', [(int) $wo->warehouse_id])->orderBy('id')->first();
    }

    private function handoffMaterial(MfgWorkOrder $wo, MfgReceipt $receipt, array $unitIds, array $target, object $actor): void
    {
        $parent = MfgWorkOrder::query()->lockForUpdate()->findOrFail($target['work_order_id']);
        $op = MfgWorkOrderOperation::query()->where('work_order_id', $parent->id)->lockForUpdate()->findOrFail($target['operation_id']);
        $materials = app(MaterialIssueService::class);
        $rows = $materials->receivable($op, true)->where('component_item_id', $wo->item_id);
        $prepared = MaterialDeliveryService::preparedQty((int) $op->id);
        $expected = $materials->expectedUnits($op, $parent, true);
        $balance = InventoryBalance::query()->where('item_id', $wo->item_id)->where('batch_no', $wo->work_order_no)
            ->where('warehouse_id', $receipt->warehouse_id)->where('location_id', $receipt->location_id)->lockForUpdate()->firstOrFail();
        $serialIds = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('id', $unitIds)->orderBy('unit_no')
            ->pluck('inventory_serial_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $left = (float) count($unitIds);
        $plan = [];
        foreach ($rows as $m) {
            $qty = min($left, max(0, $materials->neededFor($m, $parent, $expected) - (float) $m->issued_qty - (float) ($prepared[$m->id] ?? 0)));
            if ($qty <= 0.00001) continue;
            if ($serialIds && abs($qty - round($qty)) > 0.00001) $this->fail('handoff_quantity_invalid', '按编号交接的半成品用量必须是整数，请核对主工单用料。');
            $picked = array_splice($serialIds, 0, (int) round($qty));
            $plan[] = ['material' => $m, 'qty' => $qty, 'picks' => [['balance_id' => (int) $balance->id, 'qty' => $qty, 'serial_ids' => $picked]]];
            $left = round($left - $qty, 4);
            if ($left <= 0.00001) break;
        }
        if ($left > 0.00001) $this->fail('handoff_exceeds_demand', '主工单的待收数量已变化，本次交接未生效，请刷新。');
        $issue = $materials->post($parent, $op, $plan, 0, '半成品工单 '.$wo->work_order_no.' 完工直接交接', $actor);
        $workOrders = app(WorkOrderService::class);
        $workOrders->log($parent, 'semi_handoff_received', $parent->status, $parent->status,
            "{$op->operation_name}收到 {$wo->work_order_no} 交接的 {$wo->item_name} ".count($unitIds).($wo->unit_name ?: '件'), $actor,
            ['child_work_order_id' => $wo->id, 'operation_id' => $op->id, 'receipt_id' => $receipt->id, 'issue_id' => $issue->id]);
        $workOrders->log($wo, 'handoff', $wo->status, $wo->fresh()->status, '完工直接交接给 '.$parent->work_order_no.' '.$op->operation_name, $actor,
            ['parent_work_order_id' => $parent->id, 'operation_id' => $op->id, 'receipt_id' => $receipt->id, 'issue_id' => $issue->id]);
        TaskService::syncWorkStatus([(int) $parent->id]);
    }

    private function handoffShipment(MfgWorkOrder $wo, MfgReceipt $receipt, array $unitIds, array $target, object $actor): string
    {
        $serialIds = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('id', $unitIds)->orderBy('unit_no')
            ->pluck('inventory_serial_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $service = app(SalesShipmentApplicationService::class);
        $command = 'mfg-completion-'.$receipt->id;
        $reservation = InventoryReservation::query()->where('idempotency_key', 'mfg_receipt:'.$receipt->id.':fulfillment:'.$wo->sales_fulfillment_id)
            ->where('reservation_status', 'active')->lockForUpdate()->first();
        if (! $reservation || (float) $reservation->reserved_qty + 0.00001 < count($unitIds))
            $this->fail('handoff_reservation_changed', '本次完工数量已超过原订单待发需求，请减少交接数量或选择入库。');
        // 一个订单一张发货单：订单已有没发完的发货单就追加上去，一台一行、一台一个包裹，各自试水打包
        $before = SalesShipmentLine::query()->whereHas('shipment', fn ($q) => $q->where('sales_order_id', $wo->sales_order_id))->pluck('id')->all();
        $shipment = $service->create((int) $wo->sales_order_id, ['client_command_id' => $command.'-create',
            'remark' => '工单 '.$wo->work_order_no.' 完工直接交接发货工序',
            'lines' => [['sales_order_fulfillment_id' => (int) $wo->sales_fulfillment_id, 'inventory_reservation_id' => (int) $reservation->id,
                'base_qty' => count($unitIds), 'inventory_serial_ids' => $serialIds]],
        ], $this->actorName($actor), $actor);
        $lineIds = $shipment->lines()->whereNotIn('id', $before)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($shipment->shipment_status === 'draft')
            $shipment = $service->confirm($shipment, $this->actorName($actor), ['client_command_id' => $command.'-confirm', 'expected_version' => (int) $shipment->fresh()->business_version], $actor);
        $shipment = $service->postOutbound($shipment, $this->actorName($actor), ['client_command_id' => $command.'-stage',
            'expected_version' => (int) $shipment->fresh()->business_version, 'staging_location_id' => (int) $receipt->location_id, 'line_ids' => $lineIds], $actor);
        $packageIds = app(ShipmentPackingApplicationService::class)->appendLinePackages(SalesShipment::query()->lockForUpdate()->findOrFail($shipment->id), $lineIds, $actor);
        $shipment = SalesShipment::query()->findOrFail($shipment->id);
        // 交接成功必须已经生成同一冻结节点的可接任务，不能只移待发位却没有实际接收工序。
        $ready = $shipment->packingOperations()->whereIn('package_id', $packageIds)->where('status', 'READY')->get();
        if ($ready->isEmpty() || $ready->contains(fn ($next) => (int) $next->routing_operation_id !== $target['routing_operation_id']))
            $this->fail('handoff_task_changed', '实际接收工序与所选去向不一致，请先入库并核对冻结发货路线。');
        app(WorkOrderService::class)->log($wo, 'handoff_shipment', $wo->status, $wo->fresh()->status,
            '完工直接交接发货作业（'.$shipment->shipment_no.'，'.count($lineIds).' 个包裹），未确认发运', $actor, ['receipt_id' => $receipt->id, 'shipment_id' => $shipment->id]);
        return $shipment->shipment_no;
    }
}
