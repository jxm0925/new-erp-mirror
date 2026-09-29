<?php

namespace App\Services\Erp;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ProductionUnitDisplayQuery
{
    public const STATUSES = ['WAITING', 'WAIT_MATERIAL', 'WAIT_HANDOVER', 'IN_PROGRESS', 'COMPLETED', 'EXCEPTION'];

    public function apply(Builder $query, int $workOrderId, ?string $filter): void
    {
        // Select the same frozen current node as the unit trace projection:
        // explicit current node, first unfinished node, then the final node.
        // Classification is applied before pagination; filtering a loaded page
        // hides matching units on subsequent pages and produces false totals.
        $current = DB::table('erp_production_units as state_unit')->where('state_unit.work_order_id', $workOrderId)
            ->select('state_unit.id')->selectSub(function ($q): void {
                $q->from('erp_production_unit_operations as node')->select('node.id')
                    ->whereColumn('node.production_unit_id', 'state_unit.id')
                    ->orderByRaw('CASE WHEN node.routing_operation_id_snapshot = state_unit.current_routing_operation_id THEN 0 WHEN node.status <> ? THEN 1 ELSE 2 END', ['COMPLETED'])
                    ->orderByRaw('CASE WHEN node.status <> ? THEN node.sequence_no_snapshot ELSE -node.sequence_no_snapshot END', ['COMPLETED'])
                    ->orderBy('node.id')->limit(1);
            }, 'operation_id');
        $query->leftJoinSub($current, 'unit_state', fn ($join) => $join->on('unit_state.id', '=', 'erp_production_units.id'))
            ->leftJoin('erp_production_unit_operations as current_node', 'current_node.id', '=', 'unit_state.operation_id')
            ->select('erp_production_units.*')->selectRaw($this->expression().' AS display_status');
        if ($filter) $query->whereRaw('('.$this->expression().') = ?', [$filter]);
    }

    private function expression(): string
    {
        return "CASE WHEN erp_production_units.status = 'COMPLETED' THEN 'COMPLETED'
            WHEN current_node.status IN ('REWORK','QUALITY_FAILED','HANDOVER_REJECTED') THEN 'EXCEPTION'
            WHEN current_node.status = 'WAIT_MATERIAL' THEN 'WAIT_MATERIAL'
            WHEN current_node.status IN ('WAIT_PREVIOUS','WAIT_PREDECESSOR','WAIT_HANDOVER') THEN 'WAIT_HANDOVER'
            WHEN erp_production_units.status IN ('PROCESSING','IN_PROGRESS') OR current_node.status IN ('IN_PROGRESS','PAUSED','WAIT_QUALITY','WAIT_WAREHOUSE') THEN 'IN_PROGRESS'
            ELSE 'WAITING' END";
    }
}
