<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\Location;
use App\Models\Erp\MfgReceipt;
use App\Models\Erp\MfgCuttingBatch;
use App\Models\Erp\MfgMaterialIssue;
use App\Models\Erp\MfgMaterialReturn;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLink;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\Warehouse;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryService;
use App\Services\Erp\InventoryReservationService;
use App\Services\Erp\CuttingDecimal;
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
        private readonly DocumentNumberService $numbers,
    ) {}

    public function receive(int $workOrderId, array $unitIds, int $warehouseId, int $locationId, ?string $remark, object $actor, bool $staging = false): MfgReceipt
    {
        return DB::transaction(function () use ($workOrderId, $unitIds, $warehouseId, $locationId, $remark, $actor, $staging) {
            $orderId = MfgWorkOrder::query()->whereKey($workOrderId)->value('sales_order_id');
            if ($orderId) SalesOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($workOrderId);
            $wo->setRelation('materials', \App\Models\Erp\MfgWorkOrderMaterial::query()->where('work_order_id', $wo->id)->orderBy('id')->lockForUpdate()->get());
            if (! in_array($wo->status, ['released', 'in_progress', 'paused'], true)) $this->fail('status_invalid', '工单不在生产中，不能入库。');
            $ids = array_values(array_unique(array_filter(array_map('intval', $unitIds))));
            if (! $ids) $this->fail('unit_required', '请选择要入库的台。');
            $units = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('id', $ids)->orderBy('unit_no')->lockForUpdate()->get();
            if ($units->count() !== count($ids) || $units->contains(fn ($u) => $u->status !== 'completed'))
                $this->fail('unit_invalid', '只有生产完成、等入库的台可以入库，请刷新后重选。');
            $location = Location::query()->find($locationId);
            $warehouse = Warehouse::query()->find($warehouseId);
            if (! $warehouse || ! in_array($warehouse->status, ['enabled', 'active'], true) || ($warehouse->management_scope && $warehouse->management_scope !== 'factory'))
                $this->fail('warehouse_invalid', '生产入库必须选择启用的工厂仓库。');
            if (! $location || (int) $location->warehouse_id !== $warehouseId || ! in_array($location->status, ['enabled', 'active'], true))
                $this->fail('location_invalid', '库位不属于所选启用仓库，或库位已经停用。');
            $purposes = self::allowedLocationPurposes($wo);
            $semi = $purposes === ['general'];
            if (! in_array($location->location_purpose, $purposes, true))
                $this->fail('location_purpose_invalid', $semi ? '半成品请入普通库存库位。' : '成品请入成品库位或待发库位，不能入返修等其他用途库位。');
            $qty = $units->count();
            if (MfgCuttingBatch::query()->where('work_order_id', $wo->id)->where('status', 'issued')->lockForUpdate()->first(['id']))
                $this->fail('cutting_not_settled', '仍有已领入的开料批次未结算，请先确认产出与余料。');
            foreach ($wo->materials->where('participates_in_kitting', true) as $material) {
                // 本轮尚无早批在库／已发货成本补差账本，必须先归集整单材料，不能把早批成本漏给后批。
                if (bccomp((string) $material->issued_qty, (string) $material->required_qty, 4) < 0)
                    $this->fail('material_cost_incomplete', '整单实际材料成本尚未归集完整，请先完成全部冻结用料的真实收料和开料结算；已完工设备保留待入库。');
            }
            $actualCost = '0.0000';
            foreach (MfgMaterialIssue::query()->where('work_order_id', $wo->id)->lockForUpdate()->get() as $issue) $actualCost = bcadd($actualCost, (string) $issue->cost_amount, 4);
            // 已审核的生产退料按原单价冲回，从实际材料成本里扣除。
            foreach (MfgMaterialReturn::query()->where('work_order_id', $wo->id)->where('status', 'approved')->lockForUpdate()->get() as $return) $actualCost = bcsub($actualCost, (string) $return->cost_amount, 4);
            foreach (MfgCuttingBatch::query()->where('work_order_id', $wo->id)->where('status', 'settled')->lockForUpdate()->get() as $batch) $actualCost = bcadd($actualCost, (string) $batch->net_cost_amount, 4);
            $alreadyCost = '0.0000';
            foreach (MfgReceipt::query()->where('work_order_id', $wo->id)->lockForUpdate()->get() as $oldReceipt) $alreadyCost = bcadd($alreadyCost, (string) $oldReceipt->cost_amount, 4);
            $remainingCost = bcsub($actualCost, $alreadyCost, 4);
            if (bccomp($remainingCost, '0', 4) < 0) $this->fail('cost_reconciliation_required', '已入库暂估金额超过当前实际材料净耗，请先按正式成本调整核对，不能改写历史入库。');
            // 尾批承接全部小数尾差；人工、外协和后到费用仍未结算，不能把实际材料成本称为最终制造成本。
            $costAmount = CuttingDecimal::share($remainingCost, bcsub((string) $wo->planned_qty, (string) $wo->stored_qty, 4), (string) $qty);
            $unitCost = bcdiv($costAmount, (string) $qty, 4);
            $receipt = MfgReceipt::create([
                'receipt_no' => $this->numbers->next('mfg_receipt', 'MR'), 'work_order_id' => $wo->id,
                'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'qty' => $qty,
                'unit_cost' => $unitCost, 'cost_amount' => $costAmount, 'cost_source' => 'actual_materials_provisional',
                'serial_nos' => $units->pluck('serial_no')->values()->all(),
                'operator_legacy_id' => $this->actorId($actor), 'operator_name' => $this->actorName($actor), 'remark' => $remark ?: null,
            ]);
            $posted = $this->inventory->postProductionReceipt($receipt, $wo, $units->pluck('serial_no')->all(), $this->actorId($actor));
            $receipt->update(['inventory_transaction_id' => $posted['transaction']->id]);
            app(InventoryReservationService::class)->reserveProductionReceipt($receipt, $wo);
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
            $this->workOrders->log($wo, 'staging_missing', $wo->status, $wo->status, '订单待发，但尚未维护启用的待发用途库位，请仓库维护库位用途后办理入库。', $actor);
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

    public static function allowedLocationPurposes(MfgWorkOrder $wo): array
    {
        return $wo->source_type === 'work_order' || $wo->item?->item_type === 'semi_finished' ? ['general'] : ['finished', 'shipping_staging'];
    }

    /** 待发用途是正式资料，不能用库位名称猜测；指定编码同样不能绕过用途与范围。 */
    public function stagingLocation(MfgWorkOrder $wo): ?Location
    {
        $enabled = Warehouse::query()->whereIn('status', ['enabled', 'active'])
            ->where(fn ($q) => $q->whereNull('management_scope')->orWhere('management_scope', 'factory'))->pluck('id');
        $query = Location::query()->whereIn('status', ['enabled', 'active'])->whereIn('warehouse_id', $enabled)->where('location_purpose', 'shipping_staging');
        if ($code = trim((string) config('erp.ship_staging_location_code'))) {
            $hit = (clone $query)->where('location_code', $code)->first();
            if ($hit) return $hit;
        }
        return $query->orderByRaw('warehouse_id = ? DESC', [(int) $wo->warehouse_id])->orderBy('id')->first();
    }
}
