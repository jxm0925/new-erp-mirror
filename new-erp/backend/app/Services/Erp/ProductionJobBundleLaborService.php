<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{ProductionJobBundle, ProductionJobBundleLaborAllocation, ProductionLaborSession};
use Illuminate\Support\Facades\DB;

final class ProductionJobBundleLaborService
{
    public function start(ProductionJobBundle $bundle, int $employeeId): ProductionLaborSession
    {
        // Reuse the employee-wide active slot also used by ordinary production and cutting.
        DB::table('erp_legacy_admin_users')->where('legacy_id', $employeeId)->lockForUpdate()->first();
        if (ProductionLaborSession::query()->where('employee_legacy_id', $employeeId)->where('status', 'ACTIVE')->lockForUpdate()->exists()
            || DB::table('erp_shipment_packing_labor_sessions')->where('employee_legacy_id', $employeeId)->where('status', 'ACTIVE')->lockForUpdate()->exists()) {
            throw new WorkOrderDomainException('labor_session_active', '当前人员已有加工计时，请先暂停原作业后再开始共同加工。', 409);
        }
        return ProductionLaborSession::create(['execution_task_type' => 'JOB_BUNDLE', 'job_bundle_id' => $bundle->id,
            'task_id' => null, 'cutting_task_id' => null, 'target_type' => 'job_bundle', 'target_id' => $bundle->id,
            'employee_legacy_id' => $employeeId, 'role' => 'owner', 'status' => 'ACTIVE', 'started_at' => now(),
            'actual_labor_minutes' => 0, 'bundle_checkpoint_minutes' => 0, 'responsibility_weight_snapshot' => 1, 'credited_labor_minutes' => 0]);
    }

    public function checkpoint(ProductionJobBundle $bundle, bool $end = false, string $reason = 'bundle_checkpoint'): void
    {
        $sessions = $bundle->laborSessions()->where('status', 'ACTIVE')->orderBy('id')->lockForUpdate()->get();
        $lines = $bundle->lines()->whereNull('execution_completed_at')->orderBy('id')->lockForUpdate()->get();
        foreach ($sessions as $session) {
            $totalCents = (int) round(max(0, $session->started_at->diffInSeconds(now())) * 100 / 60);
            $previousCents = (int) bcmul((string) $session->bundle_checkpoint_minutes, '100', 0);
            $deltaCents = max(0, $totalCents - $previousCents);
            if ($deltaCents > 0 && $lines->isEmpty()) {
                throw new WorkOrderDomainException('bundle_labor_without_open_lines', '没有未完工明细可接收新增工时，请直接结束共同加工作业。', 409);
            }
            $weightSum = '0';
            foreach ($lines as $line) $weightSum = bcadd($weightSum, (string) $line->standard_weight_minutes_snapshot, 8);
            // Distribute integer hundredths of a minute, then assign rounding residue deterministically.
            // This conserves the one actual session total rather than repeating it on every work order.
            $shares = []; $allocatedCents = 0;
            foreach ($lines as $line) {
                $share = $deltaCents ? (int) bcdiv(bcmul((string) $deltaCents, (string) $line->standard_weight_minutes_snapshot, 8), $weightSum, 0) : 0;
                $shares[(int) $line->id] = $share; $allocatedCents += $share;
            }
            $remainder = $deltaCents - $allocatedCents;
            $order = $lines->sortByDesc(fn ($line) => (float) $line->standard_weight_minutes_snapshot)->values();
            foreach ($order as $line) {
                if ($remainder <= 0) break;
                $shares[(int) $line->id]++; $remainder--;
            }
            foreach ($lines as $line) {
                $minutes = bcdiv((string) $shares[(int) $line->id], '100', 2);
                $allocation = ProductionJobBundleLaborAllocation::query()->firstOrCreate(
                    ['job_bundle_line_id' => $line->id, 'labor_session_id' => $session->id],
                    ['employee_legacy_id' => $session->employee_legacy_id, 'standard_weight_minutes_snapshot' => $line->standard_weight_minutes_snapshot,
                        'allocated_labor_minutes' => 0, 'credited_labor_minutes' => 0]);
                $allocation->update(['allocated_labor_minutes' => bcadd((string) $allocation->allocated_labor_minutes, $minutes, 2),
                    'credited_labor_minutes' => bcadd((string) $allocation->credited_labor_minutes, $minutes, 2)]);
                $line->update(['allocated_labor_minutes' => bcadd((string) $line->allocated_labor_minutes, $minutes, 2)]);
                [, $target] = app(ProductionJobBundleCompatibilityService::class)->target($line->task, true);
                $target->update(['actual_labor_minutes' => bcadd((string) $target->actual_labor_minutes, $minutes, 2)]);
            }
            $elapsed = bcdiv((string) $totalCents, '100', 2);
            $session->fill(['actual_labor_minutes' => $elapsed, 'bundle_checkpoint_minutes' => $elapsed,
                'credited_labor_minutes' => $elapsed]);
            if ($end) $session->fill(['status' => 'ENDED', 'ended_at' => now(), 'end_reason' => $reason]);
            $session->save();
            $bundle->actual_labor_minutes = bcadd((string) $bundle->actual_labor_minutes, bcdiv((string) $deltaCents, '100', 2), 2);
        }
        $bundle->save();
    }

    public function allocations(int $taskId, string $type, int $targetId): ?array
    {
        $rows = DB::table('erp_production_job_bundle_labor_allocations as a')
            ->join('erp_production_job_bundle_lines as l', 'l.id', '=', 'a.job_bundle_line_id')
            ->where('l.task_id', $taskId)->where('l.target_type', $type)->where('l.target_id', $targetId)->orderBy('a.id')->get(['a.*']);
        if ($rows->isEmpty()) return null;
        $total = '0'; $people = [];
        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->allocated_labor_minutes, 2);
            $id = (int) $row->employee_legacy_id;
            $people[$id] = bcadd($people[$id] ?? '0', (string) $row->allocated_labor_minutes, 2);
        }
        return ['actual_labor_minutes' => (float) $total, 'credited_labor_minutes' => (float) $total,
            'rule_snapshot' => ['method' => 'frozen_standard_minutes', 'actual_session_scope' => 'job_bundle'],
            'allocations' => collect($people)->map(fn ($minutes, $id) => ['employee_legacy_id' => $id, 'role' => 'owner',
                'actual_labor_minutes' => (float) $minutes, 'credited_labor_minutes' => (float) $minutes])->values()->all()];
    }
}
