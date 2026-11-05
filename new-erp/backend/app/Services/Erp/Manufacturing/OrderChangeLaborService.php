<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\{CancellationProductionDisposition as Disposition, MfgLaborSession, MfgDispositionLaborSession, MfgWorkOrder, MfgWorkOrderUnit};
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** 变更关联工时只追溯原始时段，不新增生产工时，也不重新计提工资或提成。 */
final class OrderChangeLaborService
{
    private const PHASES = ['before_change' => '变更前生产', 'after_change_stock' => '变更后继续备货',
        'after_change_other' => '变更后生产', 'material_return' => '退料', 'dismantle' => '拆解退料', 'scrap' => '报废处理'];

    public static function hasActualWork(array $ids, ?array $unitIds = null): bool
    {
        if (!$ids) return false;
        if ($unitIds !== null) {
            if (!$unitIds) return false;
            $units = MfgWorkOrderUnit::whereIn('work_order_id', $ids)->whereIn('id', $unitIds)->get();
            foreach (MfgLaborSession::whereIn('work_order_id', $ids)->whereNotNull('started_at')
                ->where(fn ($q) => $q->where('status', 'active')->orWhereColumn('ended_at', '>', 'started_at'))->get() as $session) {
                $scope = self::recordedUnitIds($session);
                if ($scope !== null && array_intersect($scope, $unitIds)) return true;
                if ($scope === null && $units->contains(fn ($unit) => (int) $unit->work_order_id === (int) $session->work_order_id
                    && $unit->started_at && $unit->started_at->lte($session->ended_at ?? now()))) return true;
            }
            foreach (DB::table('erp_mfg_cutting_run_allocations as a')->join('erp_mfg_cutting_run_sessions as s', 's.cutting_run_id', '=', 'a.cutting_run_id')
                ->whereIn('a.work_order_id', $ids)->where('a.pieces', '>', 0)->whereNotNull('s.started_at')
                ->where(fn ($q) => $q->where('s.status', 'active')->orWhereColumn('s.ended_at', '>', 's.started_at'))->get(['a.*']) as $allocation) {
                $scope = self::recordedUnitIds($allocation);
                if ($scope !== null && array_intersect($scope, $unitIds)) return true;
                if ($scope === null && DB::table('erp_mfg_unit_reports')->whereIn('unit_id', $unitIds)
                    ->where('work_order_operation_id', $allocation->work_order_operation_id)->where('result', 'passed')->exists()) return true;
            }
            return false;
        }
        return DB::table('erp_mfg_labor_sessions')->whereIn('work_order_id', $ids)->whereNotNull('started_at')
            ->where(fn ($q) => $q->where('status', 'active')->orWhereColumn('ended_at', '>', 'started_at'))->exists()
            || DB::table('erp_mfg_cutting_run_allocations as a')->join('erp_mfg_cutting_run_sessions as s', 's.cutting_run_id', '=', 'a.cutting_run_id')
                ->whereIn('a.work_order_id', $ids)->where('a.pieces', '>', 0)->whereNotNull('s.started_at')
                ->where(fn ($q) => $q->where('s.status', 'active')->orWhereColumn('s.ended_at', '>', 's.started_at'))->exists();
    }

    public static function recordedUnitIds(object $record): ?array
    {
        $ids = $record->unit_ids ?? null;
        if ($ids === null) return null;
        return array_values(array_unique(array_map('intval', is_array($ids) ? $ids : (json_decode($ids, true) ?: []))));
    }

    public static function legacyUnitIds(int $workOrderId): array
    {
        $first = Disposition::where('work_order_id', $workOrderId)->whereNotNull('material_allocation_snapshot')->orderBy('id')->first();
        $frozen = data_get($first?->material_allocation_snapshot, 'unit_ids');
        if ($frozen !== null) return array_map('intval', $frozen);
        return MfgWorkOrderUnit::where('work_order_id', $workOrderId)->where('unit_no', '<=', MfgWorkOrder::whereKey($workOrderId)->value('planned_qty'))
            ->where(fn ($q) => $q->where('status', '!=', 'cancelled')->orWhereNotNull('started_at'))->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function freezeMaterialsForDisposition(Disposition $d, MfgWorkOrder $wo, ?object $actor = null): void
    {
        app(DispositionMaterialService::class)->freeze($d, $wo);
    }

    public static function settleMaterialDisposition(Disposition $d, string $action, ?object $actor = null): void
    {
        app(DispositionMaterialService::class)->settle($d, $action);
    }

    public static function hasOutstandingMaterials(Disposition $d): bool
    {
        return app(DispositionMaterialService::class)->outstanding($d);
    }

    public static function cuttingWorkers(array $ids): array
    {
        if (!$ids) return [];
        return DB::table('erp_mfg_cutting_run_allocations as a')->join('erp_mfg_cutting_runs as r', 'r.id', '=', 'a.cutting_run_id')
            ->join('erp_mfg_cutting_run_sessions as s', fn ($join) => $join->on('s.cutting_run_id', '=', 'r.id')->on('s.employee_legacy_id', '=', 'r.started_by_legacy_id'))
            ->whereIn('a.work_order_id', $ids)->where('a.pieces', '>', 0)->whereNotNull('s.started_at')
            ->where(fn ($q) => $q->where('s.status', 'active')->orWhereColumn('s.ended_at', '>', 's.started_at'))
            ->distinct()->pluck('r.started_by_legacy_id')->map(fn ($id) => (int) $id)->all();
    }

    public static function freezeAt(array $ids, CarbonInterface $at, ?array $unitIds = null): void
    {
        foreach (MfgLaborSession::whereIn('work_order_id', $ids)->where('status', 'active')->orderBy('id')->lockForUpdate()->get() as $session) {
            $scope = $session->unit_ids ?? MfgWorkOrderUnit::where('work_order_id', $session->work_order_id)
                ->where('current_operation_id', $session->work_order_operation_id)->whereIn('status', TaskService::laneStatuses($session->lane))->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($unitIds !== null && !array_intersect($scope, $unitIds)) continue;
            $deadline = TaskService::sessionDeadline($session);
            TaskService::closeSession($session, 'paused', $at);
            $remaining = $unitIds === null ? [] : array_values(array_diff($scope, $unitIds));
            if ($remaining && $at->lt($deadline)) MfgLaborSession::create([
                'work_order_id' => $session->work_order_id, 'work_order_operation_id' => $session->work_order_operation_id,
                'employee_legacy_id' => $session->employee_legacy_id, 'employee_name' => $session->employee_name,
                'lane' => $session->lane, 'is_rework' => $session->is_rework, 'unit_ids' => $remaining,
                'continuation_of_id' => $session->continuation_of_id ?: $session->id, 'status' => 'active', 'started_at' => $at,
            ]);
        }
    }

    public function order(int $orderId, array $filters = []): array
    {
        $dispositions = Disposition::where('sales_order_id', $orderId)->orderBy('effective_at')->orderBy('id')->get();
        $ids = $dispositions->pluck('work_order_id')->all();
        $wos = DB::table('erp_mfg_work_orders')->whereIn('id', $ids ?: [0])->get()->keyBy('id');
        $changes = DB::table('erp_sales_order_changes')->whereIn('id', $dispositions->pluck('sales_order_change_id')->filter()->all() ?: [0])->get()->keyBy('id');
        $all = []; $groups = [];
        foreach ($dispositions as $row) {
            $wo = $wos->get($row->work_order_id);
            if (!$wo) continue;
            $wo->change_no = $changes->get($row->sales_order_change_id)?->change_no;
            $unit = $row->work_order_unit_id ? MfgWorkOrderUnit::find($row->work_order_unit_id) : null;
            $wo->change_unit_id = $unit?->id;
            $wo->change_unit_serial_no = $unit?->serial_no;
            $wo->change_unit_no = $unit?->unit_no;
            $firstReceipt = $unit ? DB::table('erp_mfg_receipts')->where('work_order_id', $wo->id)->where('status', 'confirmed')
                ->whereJsonContains('serial_nos', $unit->serial_no)->whereNotNull('confirmed_at')->orderBy('confirmed_at')->value('confirmed_at') : null;
            $wo->change_labor_ended_at = $row->status === 'stored' ? $row->effective_at?->toDateTimeString()
                : ($row->status === 'stock' ? ($firstReceipt ?? $unit?->completed_at?->toDateTimeString() ?? ($unit ? null : $wo->completed_at)) : null);
            $segments = $this->segments($row, $wo);
            $key = $row->sales_order_change_id ?: 'cancelled';
            $groups[$key] ??= ['sales_order_change_id' => $row->sales_order_change_id ? (int) $row->sales_order_change_id : null,
                'change_no' => $wo->change_no,
                'effective_at' => $row->effective_at?->toIso8601String(), 'work_orders' => []];
            $groups[$key]['work_orders'][] = ['work_order_id' => (int) $wo->id, 'work_order_no' => $wo->work_order_no,
                'work_order_unit_id' => $unit?->id, 'unit_serial_no' => $unit?->serial_no, 'unit_no' => $unit?->unit_no,
                'item_name' => $wo->item_name, 'sales_order_line_id' => $row->sales_order_line_id ? (int) $row->sales_order_line_id : null,
                'disposition_id' => (int) $row->id, 'status' => $row->status, 'reason' => $row->reason,
                'effective_at' => $row->effective_at?->toIso8601String(), 'production_ended_at' => $wo->change_labor_ended_at,
                'totals' => $this->totals($segments)];
            array_push($all, ...$segments);
        }
        usort($all, fn ($a, $b) => [$a['started_at'], $a['trace_key']] <=> [$b['started_at'], $b['trace_key']]);
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 10)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        return ['totals' => $this->totals($all), 'changes' => array_values($groups),
            'segments' => array_slice($all, ($page - 1) * $perPage, $perPage), 'segments_page' => $page,
            'segments_total' => count($all), 'segments_per_page' => $perPage,
            'rule' => '以全部审核完成、订单正式生效的时刻切分实际作业时段。暂停等待不计；共同工时按冻结参与编号分摊，集中开料另按实际领用份额分摊。继续备货工时截至该编号首次正式入库，尚未入库时以本次生产完成为止；后续其他订单返修不算本次变更。同一原始时段只记一次公司劳动，不重复计酬。'];
    }

    public function origins(int $workOrderId): array
    {
        return DB::table('erp_cancellation_production_dispositions as d')
            ->where('d.work_order_id', $workOrderId)->orderBy('d.id')
            ->get(['d.id', 'd.sales_order_change_id', 'd.effective_at'])
            ->map(fn ($r) => ['disposition_id' => (int) $r->id,
                'sales_order_change_id' => $r->sales_order_change_id ? (int) $r->sales_order_change_id : null, 'effective_at' => $r->effective_at,
                'trace_rule' => '沿原始工时来源追溯，不重复记公司劳动或计酬。'])->all();
    }

    /** 处置是另一份真实劳动；已取消工单原有生产劳动补入独立栏，均不进入提成池。 */
    public function monthAdditional(string $start, string $end, ?int $employeeId = null): array
    {
        $from = Carbon::parse($start)->startOfDay();
        $until = Carbon::parse($end)->endOfDay();
        $out = ['disposition_labor' => ['minutes' => 0.0, 'rows' => []], 'cancelled_labor' => ['minutes' => 0.0, 'rows' => []]];
        $queries = [
            'disposition_labor' => DB::table('erp_mfg_disposition_labor_sessions as s')->join('erp_mfg_work_orders as w', 'w.id', '=', 's.work_order_id')
                ->select(['s.*', 'w.work_order_no', 'w.item_name']),
            'cancelled_labor' => DB::table('erp_mfg_labor_sessions as s')->join('erp_mfg_work_orders as w', 'w.id', '=', 's.work_order_id')
                ->where('w.status', 'cancelled')->whereNull('w.rework_of_work_order_id')->where('s.is_rework', false)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_cancellation_production_dispositions as d')->whereColumn('d.work_order_id', 's.work_order_id'))
                ->select(['s.*', 'w.work_order_no', 'w.item_name']),
        ];
        foreach ($queries as $kind => $query) {
            foreach ($query->where('s.status', '!=', 'active')->whereNotNull('s.ended_at')->where('s.started_at', '<=', $until)->where('s.ended_at', '>=', $from)
                ->when($employeeId, fn ($q) => $q->where('s.employee_legacy_id', $employeeId))->orderBy('s.id')->get() as $session) {
                $started = Carbon::parse($session->started_at); $ended = Carbon::parse($session->ended_at);
                $duration = max(0, $started->diffInSeconds($ended, false));
                if ($duration <= 0) continue;
                $sliceStart = $started->greaterThan($from) ? $started : $from;
                $sliceEnd = $ended->lessThan($until) ? $ended : $until;
                $minutes = (float) $session->labor_minutes * max(0, $sliceStart->diffInSeconds($sliceEnd, false)) / $duration;
                $out[$kind]['minutes'] += $minutes;
                $out[$kind]['rows'][] = ['trace_key' => ($kind === 'disposition_labor' ? 'disposition:' : 'mfg:').$session->id,
                    'source_id' => (int) $session->id, 'work_order_id' => (int) $session->work_order_id, 'work_order_no' => $session->work_order_no,
                    'item_name' => $session->item_name, 'employee_legacy_id' => (int) $session->employee_legacy_id, 'employee_name' => $session->employee_name,
                    'activity' => $session->activity ?? 'cancelled_production',
                    'activity_label' => self::PHASES[$session->activity ?? ''] ?? '已取消工单生产',
                    'started_at' => $sliceStart->toIso8601String(), 'ended_at' => $sliceEnd->toIso8601String(), 'labor_minutes' => round($minutes, 4)];
            }
            $out[$kind]['minutes'] = round($out[$kind]['minutes'], 2);
        }
        return $out;
    }

    private function segments(Disposition $row, object $wo): array
    {
        $out = [];
        $legacy = data_get($row->material_allocation_snapshot, 'unit_ids') ?? MfgWorkOrderUnit::where('work_order_id', $wo->id)
            ->where('unit_no', '<=', $wo->planned_qty)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach (DB::table('erp_mfg_labor_sessions as s')->join('erp_mfg_work_order_operations as o', 'o.id', '=', 's.work_order_operation_id')
            ->where('s.work_order_id', $wo->id)->whereNotNull('s.started_at')->orderBy('s.id')
            ->get(['s.*', 'o.operation_name']) as $session) {
            $cohort = self::recordedUnitIds($session) ?? $legacy;
            if ($row->work_order_unit_id && !in_array((int) $row->work_order_unit_id, $cohort, true)) continue;
            $ratio = $row->work_order_unit_id ? 1 / max(1, count($cohort)) : 1.0;
            $session->allocation_method = $session->unit_ids === null ? 'legacy_work_order_unit_count' : 'recorded_unit_count';
            $session->cohort_unit_ids = $cohort;
            array_push($out, ...$this->split($session, $row, $wo, 'mfg', $ratio, $session->operation_name,
                (bool) $session->is_rework || !empty($wo->rework_of_work_order_id)));
        }
        // 公共开料批次只取本工单已真实领到的件，保留批次计时原ID，不能将整批劳动压给原订单。
        $allocations = DB::table('erp_mfg_cutting_run_allocations as a')->join('erp_mfg_cutting_runs as r', 'r.id', '=', 'a.cutting_run_id')
            ->where('a.work_order_id', $wo->id)->where('r.total_pieces', '>', 0)->get(['a.*', 'r.total_pieces', 'r.run_no']);
        foreach ($allocations as $allocation) {
            $cohort = self::recordedUnitIds($allocation) ?? $legacy;
            if ($row->work_order_unit_id && !in_array((int) $row->work_order_unit_id, $cohort, true)) continue;
            $ratio = min(1.0, (float) $allocation->pieces / (float) $allocation->total_pieces) * ($row->work_order_unit_id ? 1 / max(1, count($cohort)) : 1);
            foreach (DB::table('erp_mfg_cutting_run_sessions')->where('cutting_run_id', $allocation->cutting_run_id)->whereNotNull('started_at')->get() as $session) {
                $session->allocation_id = (int) $allocation->id;
                $session->allocation_method = $allocation->unit_ids === null ? 'legacy_work_order_unit_count' : 'recorded_unit_count';
                $session->cohort_unit_ids = $cohort;
                array_push($out, ...$this->split($session, $row, $wo, 'cutting_run', $ratio, '集中开料 '.$allocation->run_no, false));
            }
        }
        foreach (MfgDispositionLaborSession::where('disposition_id', $row->id)->get() as $session) {
            $out[] = $this->segment($session, $row, $wo, 'disposition', 1, $session->activity,
                $session->started_at, $session->ended_at ?? now()->min($session->started_at->copy()->addHours(4)),
                DispositionLaborService::minutes($session), DispositionLaborService::ACTIVITIES[$session->activity], false);
        }
        return $out;
    }

    private function split(object $session, Disposition $row, object $wo, string $source, float $ratio, string $operation, bool $rework): array
    {
        $start = Carbon::parse($session->started_at);
        $rootStart = !empty($session->continuation_of_id) ? MfgLaborSession::findOrFail($session->continuation_of_id)->started_at : $start;
        $end = $session->ended_at ? Carbon::parse($session->ended_at) : now()->min($rootStart->copy()->addHours(4));
        $duration = max(0, $start->diffInSeconds($end, false));
        if ($duration <= 0) return [];
        $minutes = ($session->ended_at ? (float) $session->labor_minutes : $duration / 60) * $ratio;
        // 发货试水失败会重开原已完工工单的返工任务；这些劳动属于后续订单，不能无限追溯给旧变更。
        if ($wo->change_labor_ended_at && $end->gt($cutoff = Carbon::parse($wo->change_labor_ended_at))) {
            if ($start->gte($cutoff)) return [];
            $minutes *= $start->diffInSeconds($cutoff, false) / $duration;
            $end = $cutoff;
            $duration = max(0, $start->diffInSeconds($end, false));
        }
        $at = $row->effective_at;
        $phase = $row->status === 'stock' || $wo->source_type === 'stock' ? 'after_change_stock' : 'after_change_other';
        if (!$at || $end->lte($at)) return [$this->segment($session, $row, $wo, $source, $ratio, 'before_change', $start, $end, $minutes, $operation, $rework)];
        if ($start->gte($at)) return [$this->segment($session, $row, $wo, $source, $ratio, $phase, $start, $end, $minutes, $operation, $rework)];
        $before = $minutes * $start->diffInSeconds($at, false) / $duration;
        return [$this->segment($session, $row, $wo, $source, $ratio, 'before_change', $start, $at, $before, $operation, $rework),
            $this->segment($session, $row, $wo, $source, $ratio, $phase, $at, $end, $minutes - $before, $operation, $rework)];
    }

    private function segment(object $session, Disposition $row, object $wo, string $source, float $ratio, string $phase,
        CarbonInterface $start, CarbonInterface $end, float $minutes, string $operation, bool $rework): array
    {
        return ['trace_key' => $source.':'.$session->id, 'source_type' => $source, 'source_id' => (int) $session->id,
            'continuation_trace_key' => !empty($session->continuation_of_id) ? 'mfg:'.$session->continuation_of_id : null,
            'allocation_id' => $session->allocation_id ?? null, 'allocation_method' => $session->allocation_method ?? 'single_unit',
            'cohort_unit_ids' => $session->cohort_unit_ids ?? [$row->work_order_unit_id], 'is_allocated' => $ratio < 1,
            'sales_order_change_id' => $row->sales_order_change_id ? (int) $row->sales_order_change_id : null,
            'change_no' => $wo->change_no, 'effective_at' => $row->effective_at?->toIso8601String(),
            'disposition_id' => (int) $row->id, 'work_order_id' => (int) $wo->id, 'work_order_no' => $wo->work_order_no,
            'work_order_unit_id' => $wo->change_unit_id, 'unit_serial_no' => $wo->change_unit_serial_no, 'unit_no' => $wo->change_unit_no,
            'operation_name' => $operation, 'employee_legacy_id' => (int) $session->employee_legacy_id, 'employee_name' => $session->employee_name,
            'lane' => $rework ? 'rework' : 'normal', 'phase' => $phase, 'phase_label' => self::PHASES[$phase],
            'started_at' => $start->toIso8601String(), 'ended_at' => $end->toIso8601String(), 'active' => $session->status === 'active',
            'labor_minutes' => round($minutes, 4), 'allocation_ratio' => round($ratio, 10), 'shared_source' => $source === 'cutting_run'];
    }

    private function totals(array $segments): array
    {
        $totals = array_fill_keys(array_map(fn ($phase) => $phase.'_minutes', array_keys(self::PHASES)), 0.0)
            + ['total_minutes' => 0.0, 'normal_minutes' => 0.0, 'rework_minutes' => 0.0];
        foreach ($segments as $segment) {
            $totals[$segment['phase'].'_minutes'] += $segment['labor_minutes'];
            $totals['total_minutes'] += $segment['labor_minutes'];
            $totals[$segment['lane'] === 'rework' ? 'rework_minutes' : 'normal_minutes'] += $segment['labor_minutes'];
        }
        return array_map(fn ($minutes) => round($minutes, 2), $totals);
    }
}
