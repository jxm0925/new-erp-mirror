<?php

namespace App\Services\Erp;

use App\Models\Erp\{ProductionQuantityOperation, ProductionTask, ProductionUnitOperation};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Comparable solo work only: a short assisting session never proves that its worker completed a product fastest. */
final class ProductionTaskEfficiencyService
{
    public const ALGORITHM_VERSION = 'comparable-solo-fastest-v1';

    public function candidates(ProductionTask $task): Collection
    {
        $task->loadMissing(['workOrder', 'targets']);
        // Existing aggregate tasks remain manually claimable. No guessed quantity normalisation is used for ranking.
        if ($task->targets->count() !== 1 || ! $task->workOrder?->production_routing_id) return collect();
        $link = $task->targets->first();
        $model = $link->target_type === 'unit_operation' ? ProductionUnitOperation::class
            : ($link->target_type === 'quantity_operation' ? ProductionQuantityOperation::class : null);
        $current = $model ? $model::find($link->target_id) : null;
        if (! $current?->routing_operation_id_snapshot || ! $current->operation_id_snapshot) return collect();
        $table = $current->getTable();
        $wo = $task->workOrder;

        $sessions = DB::table('erp_production_labor_sessions')->where('target_type', $link->target_type)
            ->whereIn('target_id', DB::table($table)->select('id')->where('routing_operation_id_snapshot', $current->routing_operation_id_snapshot)->where('status', 'COMPLETED'))
            ->groupBy('target_id')->selectRaw('target_id, MIN(employee_legacy_id) AS employee_id, COUNT(DISTINCT employee_legacy_id) AS people_count, SUM(actual_labor_minutes) AS minutes')
            ->selectRaw("MAX(CASE WHEN status <> 'ENDED' OR ended_at IS NULL OR role <> 'owner' THEN 1 ELSE 0 END) AS invalid_labor");
        $query = DB::table($table.' as op')->join('erp_work_orders as wo', 'wo.id', '=', 'op.work_order_id')
            ->joinSub($sessions, 'labor', 'labor.target_id', '=', 'op.id')
            ->where('op.status', 'COMPLETED')->where('labor.people_count', 1)->where('labor.invalid_labor', 0)
            ->whereIn('wo.status', ['RELEASED', 'IN_PROGRESS', 'COMPLETED'])
            ->where('labor.minutes', '>', 0)->whereColumn('op.responsible_user_legacy_id', 'labor.employee_id')
            ->where('wo.output_item_id', $wo->output_item_id)->where('wo.production_routing_id', $wo->production_routing_id)
            ->where('wo.routing_version_snapshot', $wo->routing_version_snapshot)
            ->where('op.routing_operation_id_snapshot', $current->routing_operation_id_snapshot)
            ->where('op.operation_id_snapshot', $current->operation_id_snapshot)
            ->where('op.work_mode_snapshot', $current->work_mode_snapshot)
            ->where('op.quality_mode_snapshot', $current->quality_mode_snapshot)
            ->where('op.output_mode_snapshot', $current->output_mode_snapshot)
            ->where('wo.base_unit_id', $wo->base_unit_id)
            ->whereExists(fn ($output) => $output->selectRaw('1')->from('erp_production_output_records as output')
                ->where('output.source_target_type', $link->target_type)->whereColumn('output.source_target_id', 'op.id')
                ->where(function ($quality): void {
                    $quality->where('op.quality_mode_snapshot', 'none')->orWhereExists(fn ($inspection) => $inspection->selectRaw('1')
                        ->from('erp_production_quality_inspections as inspection')->whereColumn('inspection.output_record_id', 'output.id')
                        ->where('inspection.result', 'passed')->where('inspection.unqualified_base_qty', 0)
                        ->whereColumn('inspection.qualified_base_qty', 'output.output_base_qty'));
                })
                ->whereNotExists(fn ($inspection) => $inspection->selectRaw('1')->from('erp_production_quality_inspections as inspection')
                    ->whereColumn('inspection.output_record_id', 'output.id')->where('inspection.result', 'failed')))
            ->whereNotExists(fn ($event) => $event->selectRaw('1')->from('erp_production_execution_events as event')
                ->where('event.aggregate_type', $link->target_type)->whereColumn('event.aggregate_id', 'op.id')
                ->where(fn ($state) => $state->where('event.before_status', 'REWORK')->orWhere('event.after_status', 'REWORK')))
            ->whereNotExists(fn ($handover) => $handover->selectRaw('1')->from('erp_production_operation_handovers as handover')
                ->where('handover.source_target_type', $link->target_type)->whereColumn('handover.source_target_id', 'op.id')->where('handover.status', 'REJECTED'));
        foreach (['output_configuration_id', 'bom_version_id', 'organization_code'] as $column) {
            $value = $wo->{$column};
            $value === null ? $query->whereNull('wo.'.$column) : $query->where('wo.'.$column, $value);
        }
        if ($link->target_type === 'quantity_operation') {
            // Batch totals are comparable only at the same accepted quantity; setup work cannot be divided away.
            $query->where('op.planned_base_qty', $current->planned_base_qty)->whereColumn('op.completed_base_qty', 'op.planned_base_qty')
                ->where('op.unqualified_base_qty', 0)->where('op.scrapped_base_qty', 0);
        }
        $rejected = $task->assignments()->where('status', 'REJECTED')->pluck('offered_to_legacy_id')->all();
        $eligible = DB::table('erp_legacy_admin_users as person')->where('person.status', 'normal')
            ->whereNotIn('person.legacy_id', $rejected);
        foreach (['production.task.claim', 'production.task.view'] as $permission) {
            $eligible->whereExists(fn ($grant) => $grant->selectRaw('1')->from('erp_rbac_user_roles as ur')
                ->join('erp_rbac_roles as role', 'role.id', '=', 'ur.role_id')
                ->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'role.id')
                ->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')
                ->whereColumn('ur.user_legacy_id', 'person.legacy_id')->where('role.enabled', true)->where('p.enabled', true)->where('p.code', $permission));
        }
        $samples = $query->whereIn('labor.employee_id', $eligible->select('person.legacy_id'))
            ->get(['op.id as target_id', 'labor.employee_id', 'labor.minutes']);
        $people = app(ErpUserProjectionService::class)->many($samples->pluck('employee_id')->unique()->all());
        return $samples->groupBy('employee_id')->map(function (Collection $rows, $employeeId) use ($people, $wo, $current, $link): array {
            $qualified = $this->withoutOutliers($rows);
            $fastest = $qualified->sortBy('minutes')->first();
            return [
                'employee_legacy_id' => (int) $employeeId, 'employee' => $people[(int) $employeeId] ?? null,
                'fastest_qualified_minutes' => round((float) $fastest->minutes, 2),
                'qualified_sample_count' => $qualified->count(), 'excluded_outlier_count' => $rows->count() - $qualified->count(),
                'source_target_type' => $link->target_type, 'fastest_source_target_id' => (int) $fastest->target_id,
                'comparison' => [
                    'output_item_id' => (int) $wo->output_item_id, 'output_configuration_id' => $wo->output_configuration_id,
                    'bom_version_id' => $wo->bom_version_id, 'routing_id' => (int) $wo->production_routing_id,
                    'routing_version' => $wo->routing_version_snapshot, 'routing_operation_id' => (int) $current->routing_operation_id_snapshot,
                    'execution_mode' => $link->target_type, 'work_mode' => $current->work_mode_snapshot,
                    'quantity' => $link->target_type === 'unit_operation' ? 1 : (float) $current->planned_base_qty,
                    'qualification' => 'completed_quality_passed_solo_no_rework_same_quantity',
                ],
            ];
        })->sort(function (array $a, array $b): int {
            return ($a['fastest_qualified_minutes'] <=> $b['fastest_qualified_minutes'])
                ?: ($b['qualified_sample_count'] <=> $a['qualified_sample_count'])
                ?: ($a['employee_legacy_id'] <=> $b['employee_legacy_id']);
        })->values();
    }

    private function withoutOutliers(Collection $rows): Collection
    {
        if ($rows->count() < 4) return $rows;
        $sorted = $rows->pluck('minutes')->map(fn ($minutes) => (float) $minutes)->sort()->values();
        $percentile = static function (float $p) use ($sorted): float {
            $position = ($sorted->count() - 1) * $p; $lo = (int) floor($position); $hi = (int) ceil($position);
            return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($position - $lo);
        };
        $q1 = $percentile(.25); $q3 = $percentile(.75); $iqr = $q3 - $q1;
        return $rows->filter(fn ($row) => (float) $row->minutes >= max(0, $q1 - 1.5 * $iqr) && (float) $row->minutes <= $q3 + 1.5 * $iqr);
    }
}
