<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\{CancellationProductionDisposition as Disposition, MfgDispositionLaborSession as Session, MfgWorkOrder, SalesOrder};
use App\Services\Erp\AuthContextService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class DispositionLaborService
{
    use ProductionActor;

    public const ACTIVITIES = ['material_return' => '退料', 'dismantle' => '拆解退料', 'scrap' => '报废处理'];
    public const EXECUTE_PERMISSIONS = ['production.task.execute', 'production.material_return.request'];

    public static function assertNotTiming(int $employeeId): void
    {
        foreach (Session::where('employee_legacy_id', $employeeId)->where('status', 'active')->lockForUpdate()->get() as $session) {
            if ($session->started_at->copy()->addHours(4)->lte(now())) self::close($session, 'paused');
            else abort(422, '本人有正在计时的退料、拆解或报废作业，请先暂停。');
        }
    }

    public static function closeForDisposition(int $id, ?CarbonInterface $at = null): void
    {
        foreach (Session::where('disposition_id', $id)->where('status', 'active')->lockForUpdate()->get() as $session) self::close($session, 'completed', $at);
    }

    public static function pauseForDisposition(int $id, CarbonInterface $at, ?int $employeeId = null): void
    {
        foreach (Session::where('disposition_id', $id)->where('status', 'active')->when($employeeId !== null, fn ($q) => $q->where('employee_legacy_id', $employeeId))
            ->lockForUpdate()->get() as $session) self::close($session, 'paused', $at);
    }

    public static function permitted(Disposition $row, int $employeeId): bool
    {
        $ids = data_get($row->history, '0.permitted_decider_ids', []);
        return in_array($employeeId, array_map('intval', (array) $ids), true);
    }

    public static function canRecord(Disposition $row, int $employeeId): bool
    {
        return self::permitted($row, $employeeId)
            || DB::table('erp_mfg_task_participants')->where('work_order_id', $row->work_order_id)->where('employee_legacy_id', $employeeId)->exists()
            || DB::table('erp_mfg_labor_sessions')->where('work_order_id', $row->work_order_id)->where('employee_legacy_id', $employeeId)->exists()
            || DB::table('erp_mfg_cutting_run_allocations as a')->join('erp_mfg_cutting_run_sessions as s', 's.cutting_run_id', '=', 'a.cutting_run_id')
                ->where('a.work_order_id', $row->work_order_id)->where('a.pieces', '>', 0)->where('s.employee_legacy_id', $employeeId)->exists();
    }

    public function show(int $id, object $actor, array $filters = []): array
    {
        $row = Disposition::findOrFail($id);
        $me = (int) $this->actorId($actor);
        $sessions = Session::where('disposition_id', $id)->orderByDesc('id')->get();
        $active = $sessions->first(fn ($s) => $s->status === 'active' && (int) $s->employee_legacy_id === $me);
        $totals = array_fill_keys(array_keys(self::ACTIVITIES), 0.0);
        $rows = $sessions->map(function ($s) use (&$totals) {
            $minutes = self::minutes($s);
            $totals[$s->activity] += $minutes;
            return $s->toArray() + ['activity_label' => self::ACTIVITIES[$s->activity], 'actual_minutes' => round($minutes, 2), 'trace_key' => 'disposition:'.$s->id];
        })->all();
        foreach ($totals as &$minutes) $minutes = round($minutes, 2);
        unset($minutes);
        $permitted = self::canRecord($row, $me);
        $auth = app(AuthContextService::class);
        $executable = $auth->isSuperAdmin($actor) || array_intersect(self::EXECUTE_PERMISSIONS, $auth->permissionCodes($actor));
        $elsewhere = Session::where('employee_legacy_id', $me)->where('status', 'active')->where('disposition_id', '!=', $id)->exists()
            || DB::table('erp_mfg_labor_sessions')->where('employee_legacy_id', $me)->where('status', 'active')->exists()
            || DB::table('erp_shipment_packing_labor_sessions')->where('employee_legacy_id', $me)->where('status', 'ACTIVE')->exists()
            || DB::table('erp_mfg_cutting_run_sessions')->where('employee_legacy_id', $me)->where('status', 'active')->exists();
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 10)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        return ['disposition_id' => $id, 'status' => $row->status, 'resolved_at' => $row->resolved_at?->toIso8601String(),
            'can_start' => $executable && $permitted && $row->status === 'terminating' && !$row->resolved_at && !$active && !$elsewhere, 'timing_elsewhere' => $elsewhere,
            'can_pause' => $active !== null, 'can_finish' => $active !== null,
            'active_session' => $active ? collect($rows)->firstWhere('id', $active->id) : null,
            'activity_options' => collect(self::ACTIVITIES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'totals' => $totals + ['total_minutes' => round(array_sum($totals), 2)],
            'sessions' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'sessions_page' => $page,
            'sessions_total' => count($rows), 'sessions_per_page' => $perPage];
    }

    public function start(int $id, string $activity, object $actor): array
    {
        abort_unless(isset(self::ACTIVITIES[$activity]), 422, '请选择实际进行的处置作业。');
        return $this->command($id, $actor, function (Disposition $row, int $me) use ($activity, $actor) {
            abort_unless(self::canRecord($row, $me), 403, '只有本工单实际接单人和参与人员可以记录处置工时。');
            abort_if($row->resolved_at, 422, '该工单处置已经办结，不能新增处置工时。');
            abort_unless($row->status === 'terminating', 422, '请先由接单人选择取消、拆解退料或报废，再开始处置计时。');
            self::assertNotTiming($me);
            abort_if(DB::table('erp_mfg_labor_sessions')->where('employee_legacy_id', $me)->where('status', 'active')->exists()
                || DB::table('erp_shipment_packing_labor_sessions')->where('employee_legacy_id', $me)->where('status', 'ACTIVE')->exists()
                || DB::table('erp_mfg_cutting_run_sessions')->where('employee_legacy_id', $me)->where('status', 'active')->exists(), 422, '本人还有生产、包装或集中开料正在计时，请先暂停。');
            Session::create(['disposition_id' => $row->id, 'work_order_id' => $row->work_order_id, 'employee_legacy_id' => $me,
                'employee_name' => $this->actorName($actor), 'activity' => $activity, 'status' => 'active', 'started_at' => now()]);
        });
    }

    public function stop(int $id, object $actor, string $status): array
    {
        return $this->command($id, $actor, function (Disposition $row, int $me) use ($status) {
            $session = Session::where('disposition_id', $row->id)->where('employee_legacy_id', $me)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($session, 422, '本人在此工单没有正在计时的处置作业。');
            self::close($session, $status);
        });
    }

    private function command(int $id, object $actor, callable $action): array
    {
        return DB::transaction(function () use ($id, $actor, $action) {
            $identity = Disposition::findOrFail($id);
            SalesOrder::whereKey($identity->sales_order_id)->lockForUpdate()->firstOrFail();
            MfgWorkOrder::whereKey($identity->work_order_id)->lockForUpdate()->firstOrFail();
            $row = Disposition::whereKey($id)->lockForUpdate()->firstOrFail();
            $me = (int) $this->actorId($actor);
            // 与生产、包装、集中开料共用账号行锁，避免两条并发请求同时通过互斥检查。
            DB::table('erp_legacy_admin_users')->where('legacy_id', $me)->lockForUpdate()->firstOrFail();
            $action($row, $me);
            return $this->show($id, $actor);
        }, 5);
    }

    private static function close(Session $session, string $status, ?CarbonInterface $at = null): void
    {
        $at = $at ?? now();
        $end = $at->lessThan($session->started_at->copy()->addHours(4)) ? $at : $session->started_at->copy()->addHours(4);
        $session->update(['status' => $status, 'ended_at' => $end,
            'labor_minutes' => round(max(0, $session->started_at->diffInSeconds($end, false)) / 60, 2)]);
    }

    public static function minutes(Session $session): float
    {
        return $session->status === 'active' ? min(240, max(0, $session->started_at->diffInSeconds(now(), false)) / 60) : (float) $session->labor_minutes;
    }
}
