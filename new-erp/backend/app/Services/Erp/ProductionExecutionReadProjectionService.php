<?php

namespace App\Services\Erp;

use App\Models\Erp\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the read-only execution facts shared by MWO and WO detail screens.
 *
 * The projection deliberately derives progress from PU/PT execution records.  A
 * work-order completion approval is a later document gate and must not make an
 * already completed PU look unfinished on the production execution screen.
 */
final class ProductionExecutionReadProjectionService
{
    private const ACTIVE_OPERATION_STATUSES = [
        'CLAIMED', 'WAIT_MATERIAL', 'WAIT_HANDOVER', 'READY', 'IN_PROGRESS',
        'PAUSED', 'WAIT_QUALITY', 'WAIT_WAREHOUSE',
    ];

    private const EXCEPTION_OPERATION_STATUSES = ['REWORK', 'QUALITY_FAILED', 'HANDOVER_REJECTED'];

    /** @return array<int, array<string, mixed>> */
    public function summaries(Collection $workOrders): array
    {
        $orders = $workOrders->filter(fn ($row) => $row instanceof WorkOrder)->values();
        $ids = $orders->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) return [];

        $unitStates = DB::table('erp_production_units as unit')
            ->leftJoin('erp_production_unit_operations as operation', 'operation.production_unit_id', '=', 'unit.id')
            ->whereIn('unit.work_order_id', $ids)
            ->groupBy('unit.id', 'unit.work_order_id', 'unit.status')
            ->selectRaw("unit.work_order_id, unit.id unit_id, unit.status,
                MAX(CASE WHEN operation.status IN ('".implode("','", self::ACTIVE_OPERATION_STATUSES)."') THEN 1 ELSE 0 END) has_active,
                MAX(CASE WHEN operation.status IN ('".implode("','", self::EXCEPTION_OPERATION_STATUSES)."') THEN 1 ELSE 0 END) has_exception");
        $unitRows = DB::query()->fromSub($unitStates, 'unit_state')
            ->selectRaw("work_order_id, COUNT(*) total_count,
                SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) completed_count,
                SUM(CASE WHEN status <> 'COMPLETED' AND has_exception = 1 THEN 1 ELSE 0 END) exception_count,
                SUM(CASE WHEN status <> 'COMPLETED' AND has_exception = 0
                    AND (status IN ('PROCESSING', 'IN_PROGRESS') OR has_active = 1) THEN 1 ELSE 0 END) active_count")
            ->groupBy('work_order_id')->get()->keyBy('work_order_id');

        $quantityRows = DB::table('erp_production_quantity_operations')
            ->whereIn('work_order_id', $ids)
            ->orderByDesc('sequence_no_snapshot')->get([
                'work_order_id', 'sequence_no_snapshot', 'status', 'planned_base_qty',
                'completed_base_qty', 'scrapped_base_qty', 'remaining_base_qty',
            ])->groupBy('work_order_id');

        $taskRows = DB::table('erp_production_tasks')->whereIn('work_order_id', $ids)
            ->selectRaw("work_order_id, COUNT(*) total_count,
                SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) completed_count,
                SUM(CASE WHEN status IN ('".implode("','", self::ACTIVE_OPERATION_STATUSES)."') THEN 1 ELSE 0 END) active_count")
            ->groupBy('work_order_id')->get()->keyBy('work_order_id');

        $result = [];
        foreach ($orders as $workOrder) {
            $id = (int) $workOrder->id;
            $plannedTarget = max(0, (float) $workOrder->target_qty);
            $plannedBase = max(0, (float) $workOrder->target_base_qty);
            $factor = $plannedTarget > 0 ? $plannedBase / $plannedTarget : 1.0;
            if ($factor <= 0) $factor = 1.0;

            if ($workOrder->production_execution_mode_snapshot === 'quantity') {
                $operations = $quantityRows[$id] ?? collect();
                $terminal = $operations->first();
                $completedBase = max(0, (float) ($terminal->completed_base_qty ?? 0));
                $exceptionBase = (float) ($operations->whereIn('status', self::EXCEPTION_OPERATION_STATUSES)
                    ->map(fn ($row) => max((float) $row->scrapped_base_qty, (float) $row->remaining_base_qty))->max() ?? 0);
                $activeBase = (float) ($operations->whereIn('status', self::ACTIVE_OPERATION_STATUSES)
                    ->map(fn ($row) => max(0, (float) $row->remaining_base_qty))->max() ?? 0);
                $completed = $completedBase / $factor;
                $exception = min(max(0, $plannedTarget - $completed), $exceptionBase / $factor);
                $active = min(max(0, $plannedTarget - $completed - $exception), $activeBase / $factor);
            } else {
                $row = $unitRows[$id] ?? null;
                $completed = (float) ($row->completed_count ?? 0);
                $exception = (float) ($row->exception_count ?? 0);
                $active = (float) ($row->active_count ?? 0);
            }
            $completed = min($plannedTarget, max(0, $completed));
            $exception = min(max(0, $plannedTarget - $completed), max(0, $exception));
            $active = min(max(0, $plannedTarget - $completed - $exception), max(0, $active));
            $waiting = max(0, $plannedTarget - $completed - $exception - $active);

            $task = $taskRows[$id] ?? null;
            $taskTotal = (int) ($task->total_count ?? 0);
            $taskCompleted = (int) ($task->completed_count ?? 0);
            $quantity = [
                'planned_qty' => $this->round($plannedTarget),
                'completed_qty' => $this->round($completed),
                'in_progress_qty' => $this->round($active),
                'waiting_qty' => $this->round($waiting),
                'exception_qty' => $this->round($exception),
                'unit_id' => $workOrder->target_unit_id ? (int) $workOrder->target_unit_id : null,
                'unit_name' => $workOrder->target_unit_name_snapshot,
                'planned_base_qty' => $this->round($plannedBase),
                'completed_base_qty' => $this->round($completed * $factor),
                'in_progress_base_qty' => $this->round($active * $factor),
                'waiting_base_qty' => $this->round($waiting * $factor),
                'exception_base_qty' => $this->round($exception * $factor),
                'base_unit_id' => $workOrder->base_unit_id ? (int) $workOrder->base_unit_id : null,
                'base_unit_name' => $workOrder->base_unit_name_snapshot,
            ];
            $tasks = [
                'completed' => $taskCompleted,
                'total' => $taskTotal,
                'active' => (int) ($task->active_count ?? 0),
                'ratio' => $taskTotal > 0 ? round($taskCompleted / $taskTotal, 4) : 0,
            ];
            $displayStatus = $exception > 0 ? 'EXCEPTION'
                : ($plannedTarget > 0 && $completed + 0.00000001 >= $plannedTarget ? 'COMPLETED'
                    : ($active > 0 ? 'IN_PROGRESS' : 'WAIT_CONDITION'));
            $result[$id] = [
                'quantity' => $quantity,
                'tasks' => $tasks,
                'display_status' => $displayStatus,
                'display_status_label' => [
                    'EXCEPTION' => '异常', 'COMPLETED' => '已完成',
                    'IN_PROGRESS' => '生产中', 'WAIT_CONDITION' => '待条件',
                ][$displayStatus],
            ];
        }
        return $result;
    }

    private function round(float $value): float
    {
        return round($value, 8);
    }
}
