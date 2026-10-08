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
        if (! $balance->item || $balance->item->managementScope() !== 'factory'
            || (int) $balance->item_id !== (int) $requirement->component_item_id
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

    public function available(InventoryBalance $balance, ?int $excludingTaskId = null, array $requirementIds = []): float
    {
        return (float) $this->availableDecimal($balance, $excludingTaskId, $requirementIds);
    }

    public function availableDecimal(InventoryBalance $balance, ?int $excludingTaskId = null, array $requirementIds = []): string
    {
        $computed = bcsub(bcsub(bcsub((string) $balance->quantity_on_hand, (string) $balance->quantity_locked, 8),
            (string) $balance->quantity_defective, 8), (string) $balance->quantity_pending, 8);
        $safe = bccomp($computed, (string) $balance->quantity_available, 8) < 0 ? $computed : bcadd((string) $balance->quantity_available, '0', 8);
        if (bccomp($safe, '0', 8) < 0) $safe = '0.00000000';
        if ($balance->item?->materialManagementMode() === 'physical') {
            $count = DB::table('erp_material_physicals as physical')
                ->join('erp_material_holdings as holding', 'holding.id', '=', 'physical.current_holding_id')
                ->where('physical.item_id', $balance->item_id)->where('physical.status', 'AVAILABLE')
                ->where('holding.inventory_balance_id', $balance->id)->where('holding.position_type', 'WAREHOUSE')
                ->where('holding.status', 'ACTIVE')->count();
            if (bccomp($safe, (string) $count, 8) > 0) $safe = bcadd((string) $count, '0', 8);
        }
        $assembly = app(AssemblyProductionInventoryService::class);
        $owned = '0.00000000';
        foreach (array_unique($requirementIds) as $requirementId) $owned = bcadd($owned, $assembly->ownedQuantity((int) $balance->id, (int) $requirementId), 8);
        if (bccomp($owned, (string) $balance->quantity_locked, 8) > 0) return '0.00000000';
        $safe = bcadd($safe, $owned, 8);
        $healthy = bcadd($computed, $owned, 8);
        if (bccomp($healthy, '0', 8) < 0) $healthy = '0.00000000';
        if (bccomp($safe, $healthy, 8) > 0) $safe = $healthy;
        // These are pending picking allocations, not another inventory ledger. The balance row
        // is locked by commands before this query so simultaneous plans cannot oversubscribe it.
        $pending = DB::table('erp_material_picking_task_lines as line')
            ->join('erp_material_picking_tasks as task', 'task.id', '=', 'line.task_id')
            ->where('line.inventory_balance_id', $balance->id)->whereIn('task.status', ['WAIT_PICK', 'PICKING'])
            ->when($excludingTaskId, fn ($q) => $q->where('task.id', '<>', $excludingTaskId))
            ->groupBy('line.material_requirement_id')->select('line.material_requirement_id')->selectRaw('SUM(line.planned_pick_qty) as qty')->get();
        // Assembly stock is already excluded by quantity_locked. Subtract only the uncovered
        // part of pending picks; counting the same ownership again would fabricate shortages.
        $reserved = '0.00000000';
        foreach ($pending as $row) {
            $owned = $assembly->ownedQuantity((int) $balance->id, (int) $row->material_requirement_id);
            $uncovered = bcsub((string) $row->qty, $owned, 8);
            if (bccomp($uncovered, '0', 8) > 0) $reserved = bcadd($reserved, $uncovered, 8);
            if (in_array((int) $row->material_requirement_id, array_map('intval', $requirementIds), true)) {
                $covered = bccomp((string) $row->qty, $owned, 8) < 0 ? (string) $row->qty : $owned;
                $reserved = bcadd($reserved, $covered, 8);
            }
        }
        $result = bcsub($safe, $reserved, 8);
        if ($requirementIds !== []) {
            $requirementLimit = '0.00000000';
            $hasAssemblyOwnership = false;
            foreach (array_unique($requirementIds) as $requirementId) {
                $required = DB::table('erp_work_order_material_requirements')->where('id', $requirementId)->first();
                if (! $required || (int) $required->component_item_id !== (int) $balance->item_id) continue;
                $totalOwned = $assembly->totalOwnedQuantity((int) $requirementId);
                if (bccomp($totalOwned, '0', 8) > 0) $hasAssemblyOwnership = true;
                $publicNeed = bcsub(bcsub((string) $required->required_qty, (string) $required->picked_qty, 8), $totalOwned, 8);
                if (bccomp($publicNeed, '0', 8) < 0) $publicNeed = '0.00000000';
                // Existing reservations remain the mandatory source for their quantity. Replacing
                // them with another public batch would leave unused locks and later double supply.
                $requirementLimit = bcadd($requirementLimit, bcadd($publicNeed, $assembly->ownedQuantity((int) $balance->id, (int) $requirementId), 8), 8);
            }
            if ($hasAssemblyOwnership && bccomp($result, $requirementLimit, 8) > 0) $result = $requirementLimit;
        }
        return bccomp($result, '0', 8) > 0 ? $result : '0.00000000';
    }
}
