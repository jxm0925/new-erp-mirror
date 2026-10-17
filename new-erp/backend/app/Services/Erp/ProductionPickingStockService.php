<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\WorkOrderMaterialRequirement;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Stock selection and command validation share the same source eligibility. */
final class ProductionPickingStockService
{
    public function eligible(InventoryBalance $balance, WorkOrderMaterialRequirement $requirement): bool
    {
        if (! $balance->item || $balance->item->managementScope() !== 'factory'
            || (int) $balance->item_id !== (int) $requirement->component_item_id
            || ($requirement->base_unit_id && (int) $balance->unit_id !== (int) $requirement->base_unit_id)
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
        $geometry = $this->geometry($requirement, $balance->item->cuttingMode());
        if (! $geometry['valid']) return false;
        if ($balance->item->materialManagementMode() === 'physical') {
            if (! $this->physicalQuery($balance, $requirement)->exists()) return false;
        } elseif ($geometry['mode'] !== 'none') {
            if (! FrozenCuttingStockPolicy::allows($geometry, $this->lengthSource($balance, $requirement, $lot))) return false;
        }
        return true;
    }

    public function available(InventoryBalance $balance, ?int $excludingTaskId = null, array $requirementIds = [], ?WorkOrderMaterialRequirement $requirement = null, bool $ignoreGeometry = false): float
    {
        return (float) $this->availableDecimal($balance, $excludingTaskId, $requirementIds, $requirement, $ignoreGeometry);
    }

    public function availableDecimal(InventoryBalance $balance, ?int $excludingTaskId = null, array $requirementIds = [], ?WorkOrderMaterialRequirement $requirement = null, bool $ignoreGeometry = false): string
    {
        if (! $requirement && count(array_unique($requirementIds)) === 1) $requirement = WorkOrderMaterialRequirement::find((int) reset($requirementIds));
        $computed = bcsub(bcsub(bcsub((string) $balance->quantity_on_hand, (string) $balance->quantity_locked, 8),
            (string) $balance->quantity_defective, 8), (string) $balance->quantity_pending, 8);
        $safe = bccomp($computed, (string) $balance->quantity_available, 8) < 0 ? $computed : bcadd((string) $balance->quantity_available, '0', 8);
        if (bccomp($safe, '0', 8) < 0) $safe = '0.00000000';
        if ($balance->item?->materialManagementMode() === 'physical') {
            $physicalQuantity = '0.00000000';
            $stockKind = $requirement ? app(ProductionCuttingAllocationService::class)->stockUnitKind($requirement) : null;
            if (! $stockKind) $stockKind = in_array(strtoupper((string) $balance->unit?->unit_code), ['M2', 'SQM'], true) ? 'm2' : 'sheet';
            foreach ($this->physicalQuery($balance, $ignoreGeometry ? null : $requirement)->select('physical.dimensions', 'physical.shape')->cursor() as $physical)
                $physicalQuantity = bcadd($physicalQuantity, CuttingStockQuantityPolicy::sourceQuantity($stockKind, ['shape' => $physical->shape] + json_decode($physical->dimensions ?: '{}', true, 512, JSON_THROW_ON_ERROR)), 8);
            if (bccomp($safe, $physicalQuantity, 8) > 0) $safe = $physicalQuantity;
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

    /** The same query filters predictions, the real picker and final locked posting. */
    public function physicalQuery(InventoryBalance $balance, ?WorkOrderMaterialRequirement $requirement = null, string $physical = 'physical', string $holding = 'holding'): Builder
    {
        foreach ([$physical, $holding] as $alias) if (! preg_match('/^[a-z_]+$/D', $alias)) throw new \InvalidArgumentException('Invalid stock query alias.');
        $query = DB::table('erp_material_physicals as '.$physical)
            ->join('erp_material_holdings as '.$holding, $holding.'.id', '=', $physical.'.current_holding_id')
            ->where($physical.'.item_id', $balance->item_id)->where($physical.'.status', 'AVAILABLE')
            ->where($holding.'.inventory_balance_id', $balance->id)->where($holding.'.position_type', 'WAREHOUSE')->where($holding.'.status', 'ACTIVE');
        if (! $requirement) return $query;
        $geometry = $this->geometry($requirement, $balance->item?->cuttingMode() ?? 'none');
        if (! $geometry['valid']) return $query->whereRaw('1=0');
        if ($geometry['mode'] === 'none') return $query;
        $extract = function (string $key) use ($query, $physical): string {
            $json = "JSON_EXTRACT({$physical}.dimensions, '$.{$key}')";
            return $query->getConnection()->getDriverName() === 'sqlite' ? "NULLIF({$json}, 'null')" : "NULLIF(JSON_UNQUOTE({$json}), 'null')";
        };
        $length = 'CAST('.$extract('length_mm').' AS DECIMAL(18,8))';
        if ($geometry['mode'] === 'length') return $query->whereRaw("{$length} >= ?", [$geometry['length_mm']]);
        $width = 'CAST('.$extract('width_mm').' AS DECIMAL(18,8))';
        $area = 'CAST('.$extract('area_mm2').' AS DECIMAL(18,8))';
        $thickness = 'CAST(COALESCE('.$extract('nominal_thickness_mm').', '.$extract('thickness_mm').') AS DECIMAL(18,8))';
        return $query->whereRaw("{$thickness} = ?", [$geometry['thickness_mm']])->where(function (Builder $q) use ($geometry, $length, $width, $area, $physical): void {
            $q->where(fn ($rectangle) => $rectangle->where($physical.'.shape', 'RECTANGLE')->where(function ($fit) use ($geometry, $length, $width): void {
                $fit->whereRaw("{$length} >= ? AND {$width} >= ?", [$geometry['length_mm'], $geometry['width_mm']]);
                if ($geometry['allow_rotation']) $fit->orWhereRaw("{$length} >= ? AND {$width} >= ?", [$geometry['width_mm'], $geometry['length_mm']]);
            }))->orWhere(fn ($irregular) => $irregular->where($physical.'.shape', 'IRREGULAR')->whereRaw("{$area} >= ?", [bcmul($geometry['length_mm'], $geometry['width_mm'], 8)]));
        });
    }

    public function geometry(WorkOrderMaterialRequirement $requirement, string $itemMode): array
    {
        return FrozenCuttingStockPolicy::requirement($requirement->cutting_requirement_snapshot ?? [], $requirement->cut_length_mm_snapshot, $itemMode);
    }

    /** Conservative pieces per whole source; identity pages provide the exact per-piece capacity. */
    public function minimumPieceCapacity(InventoryBalance $balance, WorkOrderMaterialRequirement $requirement): ?int
    {
        $geometry = $this->geometry($requirement, $balance->item?->cuttingMode() ?? 'none');
        if (! $geometry['valid'] || $geometry['mode'] === 'none') return null;
        if (($geometry['quantity_basis'] ?? null) === 'total_length') return null;
        if ($balance->item?->materialManagementMode() !== 'physical') {
            $lot = $balance->material_lot_id ? DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->first() : null;
            return CuttingStockAllocationPolicy::capacity($geometry, $this->lengthSource($balance, $requirement, $lot));
        }
        $query = $this->physicalQuery($balance, $requirement)->whereNotIn('physical.id', app(ProductionCuttingAllocationService::class)->reservedPhysicalIds());
        $extract = fn (string $key) => $query->getConnection()->getDriverName() === 'sqlite'
            ? "CAST(JSON_EXTRACT(physical.dimensions, '$.{$key}') AS DECIMAL(18,8))"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT(physical.dimensions, '$.{$key}')) AS DECIMAL(18,8))";
        $length = $extract('length_mm'); $width = $extract('width_mm');
        $floor = fn (string $expression) => $query->getConnection()->getDriverName() === 'sqlite' ? "CAST({$expression} AS INTEGER)" : "FLOOR({$expression})";
        $normal = $floor("{$length} / ?"); $bindings = [$geometry['length_mm']];
        if ($geometry['mode'] === 'sheet') {
            $normal .= ' * '.$floor("{$width} / ?"); $bindings[] = $geometry['width_mm'];
            if ($geometry['allow_rotation']) {
                $function = $query->getConnection()->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';
                $normal = "{$function}({$normal}, ".$floor("{$length} / ?").' * '.$floor("{$width} / ?").')';
                $bindings = [...$bindings, $geometry['width_mm'], $geometry['length_mm']];
            }
        }
        if ($geometry['mode'] === 'sheet') {
            $area = $extract('area_mm2');
            $normal = "CASE WHEN physical.shape = 'IRREGULAR' THEN ".$floor("{$area} / ?")." ELSE {$normal} END";
            array_unshift($bindings, bcmul($geometry['length_mm'], $geometry['width_mm'], 8));
        }
        $value = $query->selectRaw("MIN({$normal}) as capacity", $bindings)->value('capacity');
        return $value === null ? 0 : (int) $value;
    }

    public function sourceMetadata(InventoryBalance $balance, WorkOrderMaterialRequirement $requirement, bool $mixed = false): array
    {
        $allocation = app(ProductionCuttingAllocationService::class); $stockKind = $allocation->stockUnitKind($requirement);
        if (! $stockKind) return ['cutting_stock_unit_kind' => null, 'cutting_source_dimensions' => null, 'cutting_stock_qty_per_source' => null];
        if ($allocation->unitKind($requirement) === 'root') {
            $lot = $balance->material_lot_id ? DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->first() : null;
            $dimensions = $this->lengthSource($balance, $requirement, $lot);
            $quantity = FrozenCuttingStockPolicy::positiveDecimal($dimensions['length_mm'] ?? null) ? CuttingStockQuantityPolicy::sourceQuantity($stockKind, $dimensions) : null;
            return ['cutting_stock_unit_kind' => $stockKind, 'cutting_source_dimensions' => $dimensions ?: null, 'cutting_stock_qty_per_source' => $quantity];
        }
        $query = $this->physicalQuery($balance, $mixed ? null : $requirement)->whereNotIn('physical.id', $allocation->reservedPhysicalIds());
        $extract = fn (string $key) => $query->getConnection()->getDriverName() === 'sqlite'
            ? "CAST(JSON_EXTRACT(physical.dimensions,'$.{$key}') AS DECIMAL(18,8))"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT(physical.dimensions,'$.{$key}')) AS DECIMAL(18,8))";
        $length = $extract('length_mm'); $width = $extract('width_mm');
        $area = "CASE WHEN physical.shape = 'IRREGULAR' THEN ".$extract('area_mm2')." ELSE {$length}*{$width} END";
        $range = $query->selectRaw("MIN({$length}) as min_l,MAX({$length}) as max_l,MIN({$width}) as min_w,MAX({$width}) as max_w,MIN({$area}) as min_area,MAX({$area}) as max_area,COUNT(*) as source_count,SUM(CASE WHEN physical.shape='IRREGULAR' THEN 1 ELSE 0 END) as irregular_count")->first();
        $dimensions = $range && $range->min_l !== null && bccomp((string) $range->min_l, (string) $range->max_l, 8) === 0
            && bccomp((string) $range->min_w, (string) $range->max_w, 8) === 0 ? ['length_mm' => (string) $range->min_l, 'width_mm' => (string) $range->min_w] : null;
        $quantity = $stockKind === 'sheet' ? '1.00000000' : ($dimensions ? CuttingStockQuantityPolicy::sourceQuantity($stockKind, $dimensions) : null);
        return ['cutting_stock_unit_kind' => $stockKind, 'cutting_source_dimensions' => $dimensions, 'cutting_stock_qty_per_source' => $quantity,
            'cutting_source_area_min_mm2' => $range?->min_area, 'cutting_source_area_max_mm2' => $range?->max_area, 'cutting_has_area_sources' => (int) ($range?->irregular_count ?? 0) > 0];
    }

    public function lengthSource(InventoryBalance $balance, WorkOrderMaterialRequirement $requirement, ?object $lot): array
    {
        if ($lot && $lot->cut_length_mm !== null) return ['length_mm' => $lot->cut_length_mm];
        $calculation = $requirement->cutting_requirement_snapshot['quantity_calculation'] ?? [];
        $source = $calculation['stock_source'] ?? [];
        // Only a frozen, explicit standard-length source proves an ordinary full root.
        // An unknown remnant never inherits the Item's current default length.
        if ((! $lot || $lot->material_form === 'FULL_STOCK') && ($source['type'] ?? null) === 'item_standard_length'
            && (int) ($source['item_id'] ?? 0) === (int) $balance->item_id) return (array) ($calculation['stock_dimensions'] ?? []);
        return [];
    }
}
