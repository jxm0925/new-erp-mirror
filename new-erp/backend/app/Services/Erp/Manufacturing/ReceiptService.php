<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\Location;
use App\Models\Erp\MfgReceipt;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * 成品入库：最后一道合格（等入库）的台，由仓库/主管选仓库库位确认入库。
 * 入库后台显示“待试水”；锁给订单在第 6 阶段做。
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

    public function receive(int $workOrderId, array $unitIds, int $warehouseId, int $locationId, ?string $remark, object $actor): MfgReceipt
    {
        return DB::transaction(function () use ($workOrderId, $unitIds, $warehouseId, $locationId, $remark, $actor) {
            $wo = MfgWorkOrder::query()->with('materials')->lockForUpdate()->findOrFail($workOrderId);
            if (! in_array($wo->status, ['released', 'in_progress', 'paused'], true)) $this->fail('status_invalid', '工单不在生产中，不能入库。');
            $ids = array_values(array_unique(array_filter(array_map('intval', $unitIds))));
            if (! $ids) $this->fail('unit_required', '请选择要入库的台。');
            $units = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('id', $ids)->orderBy('unit_no')->lockForUpdate()->get();
            if ($units->count() !== count($ids) || $units->contains(fn ($u) => $u->status !== 'completed'))
                $this->fail('unit_invalid', '只有生产完成、等入库的台可以入库，请刷新后重选。');
            $location = Location::query()->find($locationId);
            if (! $location || (int) $location->warehouse_id !== $warehouseId) $this->fail('location_invalid', '库位不属于所选仓库。');
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
                $this->workOrders->log($wo, 'unit_stored', 'completed', 'stored', "{$unit->serial_no} 成品入库（{$receipt->receipt_no}），待试水", $actor, [], $unit->id);
            }
            $wo->update(['stored_qty' => (float) $wo->stored_qty + $qty]);
            $this->workOrders->log($wo, 'received', $wo->status, $wo->status, "成品入库 {$qty} 台（{$receipt->receipt_no}）", $actor, ['receipt_id' => $receipt->id]);
            $this->workOrders->refreshCompletion($wo, $actor);
            return $receipt->fresh();
        }, 5);
    }
}
