<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\Location;
use App\Models\Erp\MfgReceipt;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLink;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\Warehouse;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * 生产入库：最后一道合格（等入库）的台入库。
 * - 半成品工单（下料组）：仓库确认入半成品仓，总装收料时再出库。
 * - 订单工单总装完、订单马上要发：自动记入“成品待发区”库位，直接走发货试水，不用仓库手工入库。
 * - 备货工单、延期发货的订单：仓库确认入成品仓。
 */
final class ReceiptService
{
    use ProductionActor;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly WorkOrderService $workOrders,
        private readonly WorkOrderPlanBuilder $plans,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function receive(int $workOrderId, array $unitIds, int $warehouseId, int $locationId, ?string $remark, object $actor, bool $staging = false): MfgReceipt
    {
        return DB::transaction(function () use ($workOrderId, $unitIds, $warehouseId, $locationId, $remark, $actor, $staging) {
            $wo = MfgWorkOrder::query()->with('materials')->lockForUpdate()->findOrFail($workOrderId);
            if (! in_array($wo->status, ['released', 'in_progress', 'paused'], true)) $this->fail('status_invalid', '工单不在生产中，不能入库。');
            $ids = array_values(array_unique(array_filter(array_map('intval', $unitIds))));
            if (! $ids) $this->fail('unit_required', '请选择要入库的台。');
            $units = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('id', $ids)->orderBy('unit_no')->lockForUpdate()->get();
            if ($units->count() !== count($ids) || $units->contains(fn ($u) => $u->status !== 'completed'))
                $this->fail('unit_invalid', '只有生产完成、等入库的台可以入库，请刷新后重选。');
            $location = Location::query()->find($locationId);
            if (! $location || (int) $location->warehouse_id !== $warehouseId) $this->fail('location_invalid', '库位不属于所选仓库。');
            $semi = $wo->source_type === 'work_order';
            $materials = $wo->materials->map(fn ($m) => ['component_item_id' => (int) $m->component_item_id, 'required_qty' => (float) $m->required_qty])->all();
            $unitCost = (float) ($this->plans->estimateMaterialCost($materials, (float) $wo->planned_qty) ?? 0);
            $qty = $units->count();
            $receipt = MfgReceipt::create([
                'receipt_no' => $this->numbers->next('mfg_receipt', 'MR'), 'work_order_id' => $wo->id,
                'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'qty' => $qty,
                'unit_cost' => $unitCost, 'cost_amount' => round($unitCost * $qty, 4), 'cost_source' => 'material_estimate',
                'serial_nos' => $units->pluck('serial_no')->values()->all(),
                'operator_legacy_id' => $this->actorId($actor), 'operator_name' => $this->actorName($actor), 'remark' => $remark ?: null,
            ]);
            $posted = $this->inventory->postProductionReceipt($receipt, $wo, $units->pluck('serial_no')->all(), $this->actorId($actor));
            $receipt->update(['inventory_transaction_id' => $posted['transaction']->id]);
            foreach ($units as $unit) {
                $unit->update(['status' => 'stored', 'stored_at' => now(), 'warehouse_id' => $warehouseId, 'location_id' => $locationId,
                    'inventory_serial_id' => $posted['serial_ids'][$unit->serial_no] ?? null]);
                $this->workOrders->log($wo, 'unit_stored', 'completed', 'stored', $semi ? "{$unit->serial_no} 半成品入库（{$receipt->receipt_no}）" : "{$unit->serial_no} 成品入库（{$receipt->receipt_no}），待试水", $actor, [], $unit->id);
            }
            $wo->update(['stored_qty' => (float) $wo->stored_qty + $qty]);
            $where = $staging ? '自动记入成品待发区 '.$location->location_code.'，等发货试水' : ($semi ? '半成品入库' : '成品入库');
            $this->workOrders->log($wo, 'received', $wo->status, $wo->status, "{$where} {$qty} 台（{$receipt->receipt_no}）", $actor, ['receipt_id' => $receipt->id, 'staging' => $staging]);
            if ($link = MfgWorkOrderLink::query()->where('child_work_order_id', $wo->id)->first()) {
                $parent = MfgWorkOrder::query()->find($link->parent_work_order_id);
                if ($parent) $this->workOrders->log($parent, 'semi_stored', $parent->status, $parent->status, "半成品工单 {$wo->work_order_no} 入库 {$wo->item_name} {$qty}".($wo->unit_name ?: '')."，可以收料了", $actor, ['child_work_order_id' => $wo->id]);
            }
            $this->workOrders->refreshCompletion($wo, $actor);
            return $receipt->fresh();
        }, 5);
    }

    /**
     * 总装最后一道合格后调用：订单马上要发货的，自动记入成品待发区；备货、延期、半成品工单不处理（仓库手工入库）。
     * 没配置待发区库位时不处理，留给仓库手工入库，并在工单日志里提示。
     */
    public function autoStage(int $workOrderId, array $unitIds, object $actor): ?MfgReceipt
    {
        $wo = MfgWorkOrder::query()->findOrFail($workOrderId);
        if (! $unitIds || ! $this->shipsImmediately($wo)) return null;
        $location = $this->stagingLocation($wo);
        if (! $location) {
            $this->workOrders->log($wo, 'staging_missing', $wo->status, $wo->status, '订单马上要发货，但没有找到“成品待发区”库位，请仓库手工入库（库位名称含“待发区”或在配置 ERP_SHIP_STAGING_LOCATION 指定库位编码）', $actor);
            return null;
        }
        return $this->receive($wo->id, $unitIds, (int) $location->warehouse_id, (int) $location->id, '总装完工，订单马上发货，自动记入成品待发区', $actor, true);
    }

    /** 订单工单、订单已确认、不是延期发货 = 做完马上发。 */
    public function shipsImmediately(MfgWorkOrder $wo): bool
    {
        if ($wo->source_type !== 'sales_order' || ! $wo->sales_order_id) return false;
        $order = SalesOrder::query()->find($wo->sales_order_id);
        if (! $order || $order->order_status !== 'confirmed') return false;
        $delayed = (bool) ($order->is_delay || $order->delay);
        if ($delayed && $order->delay_date && $order->delay_date->lte(now()->startOfDay())) $delayed = false;
        return ! $delayed;
    }

    /** 成品待发区库位：先看配置的库位编码，再找名称/区域含“待发”的启用库位，优先工单仓库。 */
    public function stagingLocation(MfgWorkOrder $wo): ?Location
    {
        $enabled = Warehouse::query()->whereIn('status', ['enabled', 'active'])->pluck('id');
        $query = Location::query()->whereIn('status', ['enabled', 'active'])->whereIn('warehouse_id', $enabled);
        if ($code = trim((string) config('erp.ship_staging_location_code'))) {
            $hit = (clone $query)->where('location_code', $code)->first();
            if ($hit) return $hit;
        }
        return $query->where(fn ($q) => $q->where('location_name', 'like', '%待发%')->orWhere('area', 'like', '%待发%'))
            ->orderByRaw('warehouse_id = ? DESC', [(int) $wo->warehouse_id])->orderBy('id')->first();
    }
}
