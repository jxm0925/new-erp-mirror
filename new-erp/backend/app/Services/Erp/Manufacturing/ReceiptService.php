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
use App\Services\Erp\Inventory\ProductionInventoryPosting;
use App\Services\Erp\InventoryReservationService;
use App\Services\Erp\CuttingDecimal;
use Illuminate\Support\Facades\DB;

/**
 * 生产入库：最后一道合格（等入库）的设备入库。
 * - 选择入库：保留在半成品或成品库位。
 * - 选择直接交接：由完工服务在同一事务内调用入库及目标工序收料/待发记账。
 */
final class ReceiptService
{
    use ProductionActor;

    public function __construct(
        private readonly ProductionInventoryPosting $inventory,
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
            // 原订单已取消或短交：先转备货，做完的设备进公共库存。
            $this->workOrders->convertIfDemandGone($wo, $actor);
            $ids = array_values(array_unique(array_filter(array_map('intval', $unitIds))));
            if (! $ids) $this->fail('unit_required', '请选择要入库的设备。');
            $units = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('id', $ids)->orderBy('unit_no')->lockForUpdate()->get();
            if ($units->count() !== count($ids) || $units->contains(fn ($u) => $u->status !== 'completed'))
                $this->fail('unit_invalid', '只有生产完成、等入库的设备可以入库，请刷新后重选。');
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
            // 做完几台设备就能入库几台：成本按目前已领的料暂估分摊，最后一批承接全部差额。
            // 还会继续产出的设备 = 没入库、没报废、没取消的设备（含本批）。
            $open = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereNotIn('status', ['stored', 'scrapped', 'cancelled'])->lockForUpdate()->count();
            $lastBatch = $qty >= $open;
            // 最后一批要把整单成本收口：开料批次必须都已登记产出和余料，否则成本会漏掉。
            if ($lastBatch && MfgCuttingBatch::query()->where('work_order_id', $wo->id)->where('status', 'issued')->lockForUpdate()->first(['id']))
                $this->fail('cutting_not_settled', '这是本工单最后一批设备，仍有开料批次没登记产出和余料，请先在开料里登记完再入库。');
            $actualCost = '0.0000';
            foreach (MfgMaterialIssue::query()->where('work_order_id', $wo->id)->lockForUpdate()->get() as $issue) $actualCost = bcadd($actualCost, (string) $issue->cost_amount, 4);
            // 已审核的生产退料按原单价冲回，从实际材料成本里扣除。
            foreach (MfgMaterialReturn::query()->where('work_order_id', $wo->id)->where('status', 'approved')->lockForUpdate()->get() as $return) $actualCost = bcsub($actualCost, (string) $return->cost_amount, 4);
            foreach (MfgCuttingBatch::query()->where('work_order_id', $wo->id)->where('status', 'settled')->lockForUpdate()->get() as $batch) $actualCost = bcadd($actualCost, (string) $batch->net_cost_amount, 4);
            // 集中开料按定额分给本单的板材成本
            $actualCost = bcadd($actualCost, (string) \App\Models\Erp\MfgCuttingAllocation::query()->where('work_order_id', $wo->id)->lockForUpdate()->sum('cost_amount'), 4);
            // 集中开料记录按件数带过来的板材／管材成本（切完结束时已从仓库扣）
            $actualCost = bcadd($actualCost, (string) \App\Models\Erp\MfgCuttingRunAllocation::query()->where('work_order_id', $wo->id)->lockForUpdate()->sum('cost_amount'), 4);
            $alreadyCost = '0.0000';
            foreach (MfgReceipt::query()->where('work_order_id', $wo->id)->lockForUpdate()->get() as $oldReceipt) $alreadyCost = bcadd($alreadyCost, (string) $oldReceipt->cost_amount, 4);
            $remainingCost = bcsub($actualCost, $alreadyCost, 4);
            if (bccomp($remainingCost, '0', 4) < 0) $this->fail('cost_reconciliation_required', '已入库暂估金额超过当前实际材料净耗，请先按正式成本调整核对，不能改写历史入库。');
            // 早批按已领材料暂估，尾批承接全部差额；人工、外协和后到费用仍未结算，不能把实际材料成本称为最终制造成本。
            $costAmount = CuttingDecimal::share($remainingCost, (string) max($qty, $open), (string) $qty);
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
