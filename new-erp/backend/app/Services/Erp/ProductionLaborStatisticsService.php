<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionLaborSession;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionUnitOperation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductionLaborStatisticsService
{
    public function statistics(array $filters, array $permissions): array
    {
        $this->permission($permissions, 'production.labor_stats.view');
        $employeeId = isset($filters['employee_legacy_id']) ? (int) $filters['employee_legacy_id'] : null;
        $samples = collect();
        foreach (['unit_operation' => ProductionUnitOperation::class, 'quantity_operation' => ProductionQuantityOperation::class] as $type => $model) {
            $query = $model::query()->select($model::query()->getModel()->getTable().'.*')
                ->join('erp_work_orders as wo', 'wo.id', '=', $model::query()->getModel()->getTable().'.work_order_id')
                ->where($model::query()->getModel()->getTable().'.status', 'COMPLETED');
            $table = $model::query()->getModel()->getTable();
            if (! empty($filters['output_item_id'])) $query->where('wo.output_item_id', (int) $filters['output_item_id']);
            if (! empty($filters['routing_id'])) $query->where('wo.production_routing_id', (int) $filters['routing_id']);
            if (! empty($filters['routing_version'])) $query->where('wo.routing_version_snapshot', (int) $filters['routing_version']);
            if (! empty($filters['routing_operation_id'])) $query->where($table.'.routing_operation_id_snapshot', (int) $filters['routing_operation_id']);
            if (! empty($filters['operation_id'])) $query->where($table.'.operation_id_snapshot', (int) $filters['operation_id']);
            foreach ($query->get() as $target) {
                $sample = $this->qualifiedSample($type, $target, $employeeId);
                if ($sample) $samples->push($sample);
            }
        }

        $filtered = $this->withoutStatisticalOutliers($samples);
        $allMinutes = $filtered->pluck('actual_labor_minutes')->map(fn ($value) => (float) $value);
        $employeeMinutes = $filtered->pluck('employee_actual_labor_minutes')->filter(fn ($value) => $value !== null)->map(fn ($value) => (float) $value);
        $standards = $filtered->pluck('standard_minutes')->filter(fn ($value) => $value !== null)->map(fn ($value) => (float) $value);
        return [
            'filters' => $filters,
            'qualification_rule' => [
                'completed' => true, 'quality_passed_or_not_required' => true, 'rework_excluded' => true,
                'rejected_handover_excluded' => true, 'labor_sessions_complete' => true,
                'outlier_rule' => 'IQR 1.5 倍范围；样本少于 4 条时不做统计剔除',
            ],
            'sample_count_before_outlier_filter' => $samples->count(),
            'qualified_sample_count' => $filtered->count(),
            'excluded_outlier_count' => $samples->count() - $filtered->count(),
            'standard_minutes' => $standards->isEmpty() ? null : round((float) $standards->last(), 2),
            'historical_average_qualified_minutes' => $allMinutes->isEmpty() ? null : round((float) $allMinutes->avg(), 2),
            'historical_fastest_qualified_minutes' => $allMinutes->isEmpty() ? null : round((float) $allMinutes->min(), 2),
            'employee_average_qualified_minutes' => $employeeMinutes->isEmpty() ? null : round((float) $employeeMinutes->avg(), 2),
            'employee_fastest_qualified_minutes' => $employeeMinutes->isEmpty() ? null : round((float) $employeeMinutes->min(), 2),
            'suggested_standard_minutes' => $allMinutes->count() < 5 ? null : round($this->percentile($allMinutes->sort()->values(), 0.5), 2),
            'suggestion_sample_sufficient' => $allMinutes->count() >= 5,
            'suggestion_is_read_only' => true,
            'samples' => $filtered->values()->all(),
        ];
    }

    private function qualifiedSample(string $type, object $target, ?int $employeeId): ?array
    {
        $output = DB::table('erp_production_output_records')->where('source_target_type', $type)->where('source_target_id', $target->id)->first();
        if (! $output) return null;
        if ($target->quality_mode_snapshot !== 'none' && ! DB::table('erp_production_quality_inspections')
            ->where('output_record_id', $output->id)->where('result', 'passed')->exists()) return null;
        if (DB::table('erp_production_execution_events')->where('aggregate_type', $type)->where('aggregate_id', $target->id)
            ->where(fn ($query) => $query->where('before_status', 'REWORK')->orWhere('after_status', 'REWORK'))->exists()) return null;
        if (DB::table('erp_production_operation_handovers')->where('source_target_type', $type)->where('source_target_id', $target->id)
            ->where('status', 'REJECTED')->exists()) return null;
        $sessions = ProductionLaborSession::query()->where('target_type', $type)->where('target_id', $target->id)->get();
        if ($sessions->isEmpty() || $sessions->contains(fn ($session) => $session->status !== 'ENDED' || ! $session->ended_at)) return null;
        $actual = (float) $sessions->sum('actual_labor_minutes');
        if ($actual <= 0.00000001) return null;
        $employee = $employeeId ? (float) $sessions->where('employee_legacy_id', $employeeId)->sum('actual_labor_minutes') : null;
        return [
            'target_type' => $type, 'target_id' => (int) $target->id, 'work_order_id' => (int) $target->work_order_id,
            'routing_operation_id' => $target->routing_operation_id_snapshot ? (int) $target->routing_operation_id_snapshot : null,
            'operation_id' => $target->operation_id_snapshot ? (int) $target->operation_id_snapshot : null,
            'operation_code' => $target->operation_code_snapshot, 'operation_name' => $target->operation_name_snapshot,
            'standard_minutes' => $target->standard_minutes_snapshot === null ? null : (float) $target->standard_minutes_snapshot,
            'actual_labor_minutes' => round($actual, 2),
            'employee_actual_labor_minutes' => $employeeId && $employee > 0 ? round($employee, 2) : null,
            'completed_at' => optional($target->completed_at)->toISOString(),
        ];
    }

    private function withoutStatisticalOutliers(Collection $samples): Collection
    {
        if ($samples->count() < 4) return $samples;
        $values = $samples->pluck('actual_labor_minutes')->map(fn ($value) => (float) $value)->sort()->values();
        $q1 = $this->percentile($values, 0.25); $q3 = $this->percentile($values, 0.75); $iqr = $q3 - $q1;
        $low = max(0, $q1 - 1.5 * $iqr); $high = $q3 + 1.5 * $iqr;
        return $samples->filter(fn ($sample) => $sample['actual_labor_minutes'] >= $low && $sample['actual_labor_minutes'] <= $high);
    }

    private function percentile(Collection $sorted, float $percentile): float
    {
        if ($sorted->isEmpty()) return 0;
        $position = ($sorted->count() - 1) * $percentile;
        $lower = (int) floor($position); $upper = (int) ceil($position);
        if ($lower === $upper) return (float) $sorted[$lower];
        return (float) $sorted[$lower] + ((float) $sorted[$upper] - (float) $sorted[$lower]) * ($position - $lower);
    }

    private function permission(array $permissions, string $code): void
    { if (! in_array($code, $permissions, true)) throw new WorkOrderDomainException('permission_denied', '当前用户没有查看生产工时统计的权限。', 403, ['permission' => $code]); }
}
