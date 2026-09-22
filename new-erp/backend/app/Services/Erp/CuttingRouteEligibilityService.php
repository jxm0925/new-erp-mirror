<?php

namespace App\Services\Erp;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Capacity and geometry checks shared by the order picker and locked writes. */
final class CuttingRouteEligibilityService
{
    public function pending(int $editingResultId): Builder
    {
        return DB::table('erp_cutting_result_routes')->whereNotNull('target_material_requirement_id')
            ->where('status', '!=', 'CANCELLED')
            ->where(fn (Builder $q) => $q->where('result_id', '!=', $editingResultId)->orWhere('status', '!=', 'PLANNED'))
            ->selectRaw('target_material_requirement_id, SUM(quantity - received_qty) AS pending_qty')
            ->groupBy('target_material_requirement_id');
    }

    public function constrain(Builder $q, object $result): void
    {
        $size = $this->size($result);
        $q->where(fn (Builder $w) => $w->whereNull('requirement.cut_length_mm_snapshot')
            ->orWhere('requirement.cut_length_mm_snapshot', $size['length_mm'] ?? -1));
        foreach (['width_mm', 'thickness_mm'] as $field) {
            $expression = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(requirement.cutting_requirement_snapshot, '$.{$field}')), 'null')";
            $q->whereRaw("({$expression} IS NULL OR CAST({$expression} AS DECIMAL(12,2)) = ?)", [$size[$field] ?? -1]);
        }
    }

    public function assertTarget(object $target, object $result, string $quantity): void
    {
        $c = app(CuttingCommandService::class); $size = $this->size($result);
        $expected = $target->cutting_requirement_snapshot ? json_decode($target->cutting_requirement_snapshot, true, 512, JSON_THROW_ON_ERROR) : [];
        if ($target->cut_length_mm_snapshot !== null) $expected['length_mm'] = $target->cut_length_mm_snapshot;
        foreach (['length_mm', 'width_mm', 'thickness_mm'] as $field) {
            if (isset($expected[$field]) && (! isset($size[$field]) || bccomp((string) $size[$field], (string) $expected[$field], 8) !== 0)) {
                $c->fail('route_dimensions_mismatch', '这条产出的尺寸或厚度不符合所选订单需求。');
            }
        }
        $task = DB::table('erp_production_task_targets as link')->join('erp_production_tasks as task', 'task.id', '=', 'link.task_id')
            ->where('link.target_type', $target->target_type)->where('link.target_id', $target->target_id)->select('task.id', 'task.status')->lockForUpdate()->first();
        if (! $task || in_array($task->status, ['IN_PROGRESS', 'COMPLETED', 'CANCELLED'], true)) {
            $c->fail('route_target_not_available', '所选订单需求对应工序已开工、结束或不存在，不能继续分配。');
        }
        // The caller locks the target requirement first, serializing assignments
        // from different cutting results against the same finite requirement.
        // Use a current locking read: an earlier snapshot in this transaction may
        // predate another result's assignment even after waiting for this target.
        $routes = DB::table('erp_cutting_result_routes')->where('target_material_requirement_id', $target->id)
            ->where('status', '!=', 'CANCELLED')
            ->where(fn (Builder $q) => $q->where('result_id', '!=', $result->id)->orWhere('status', '!=', 'PLANNED'))
            ->orderBy('id')->lockForUpdate()->get(['quantity', 'received_qty']);
        $pending = '0';
        foreach ($routes as $route) $pending = bcadd($pending, bcsub((string) $route->quantity, (string) $route->received_qty, 8), 8);
        $received = bcsub((string) $target->satisfied_base_qty, (string) $target->returned_base_qty, 8);
        if (bccomp($received, '0', 8) < 0) $received = '0';
        $available = bcsub(bcsub((string) $target->required_base_qty, $received, 8), $pending, 8);
        if (bccomp($quantity, $available, 8) > 0) $c->fail('route_demand_exceeded', '本次分配数量超过所选订单尚可接收的数量。');
    }

    private function size(object $result): array
    {
        $m = $result->measurements ? json_decode($result->measurements, true, 512, JSON_THROW_ON_ERROR) : [];
        $frozen = $result->cutting_requirement_snapshot ? json_decode($result->cutting_requirement_snapshot, true, 512, JSON_THROW_ON_ERROR) : [];
        return ['length_mm' => $result->cut_length_mm ?? $m['length_mm'] ?? null,
            'width_mm' => $m['width_mm'] ?? null,
            'thickness_mm' => $frozen['required_thickness_mm'] ?? $m['thickness_mm'] ?? null];
    }
}
