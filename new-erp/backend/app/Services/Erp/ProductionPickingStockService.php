<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\WorkOrderMaterialRequirement;
use Illuminate\Support\Facades\DB;

/** Stock selection and command validation share the same source eligibility. */
final class ProductionPickingStockService
{
    public function eligible(InventoryBalance $balance, WorkOrderMaterialRequirement $requirement): bool
    {
        if ((int) $balance->item_id !== (int) $requirement->component_item_id
            || ! in_array($balance->warehouse?->status, ['enabled', 'active'], true)
            || ! in_array($balance->location?->status, ['enabled', 'active'], true)
            || (int) $balance->location?->warehouse_id !== (int) $balance->warehouse_id) return false;
        $batch = DB::table('erp_inventory_batches')->where('item_id', $balance->item_id)->where('batch_no', $balance->batch_no)->first();
        if ($batch && (! in_array($batch->status, ['enabled', 'active'], true)
            || ($batch->expire_date && substr($batch->expire_date, 0, 10) < now()->toDateString()))) return false;
        $lot = $balance->material_lot_id ? DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->first() : null;
        if ((int) $requirement->configuration_id !== (int) ($lot?->configuration_id ?? 0)) return false;
        // A staged/reserved production output cannot become common material just because its Item matches.
        if ($lot && $lot->source_type === 'production_output_record') {
            $reserved = DB::table('erp_production_output_records as output')
                ->join('erp_work_orders as wo', 'wo.id', '=', 'output.work_order_id')
                ->where('output.id', $lot->source_id)->whereNotNull('wo.reserved_for_work_order_id')->exists();
            if ($reserved) return false;
        }
        return true;
    }

    public function available(InventoryBalance $balance, ?int $excludingTaskId = null): float
    {
        $safe = max(0, min((float) $balance->quantity_available,
            (float) $balance->quantity_on_hand - (float) $balance->quantity_locked
            - (float) $balance->quantity_defective - (float) $balance->quantity_pending));
        if ($balance->item?->materialManagementMode() === 'physical') {
            $count = DB::table('erp_material_physicals as physical')
                ->join('erp_material_holdings as holding', 'holding.id', '=', 'physical.current_holding_id')
                ->where('physical.item_id', $balance->item_id)->where('physical.status', 'AVAILABLE')
                ->where('holding.inventory_balance_id', $balance->id)->where('holding.position_type', 'WAREHOUSE')
                ->where('holding.status', 'ACTIVE')->count();
            $safe = min($safe, $count);
        }
        // These are pending picking allocations, not another inventory ledger. The balance row
        // is locked by commands before this query so simultaneous plans cannot oversubscribe it.
        $reserved = DB::table('erp_material_picking_task_lines as line')
            ->join('erp_material_picking_tasks as task', 'task.id', '=', 'line.task_id')
            ->where('line.inventory_balance_id', $balance->id)->whereIn('task.status', ['WAIT_PICK', 'PICKING'])
            ->when($excludingTaskId, fn ($q) => $q->where('task.id', '<>', $excludingTaskId))
            ->sum('line.planned_pick_qty');
        return max(0, round($safe - (float) $reserved, 8));
    }
}
