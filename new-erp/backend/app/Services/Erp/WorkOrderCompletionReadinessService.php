<?php

namespace App\Services\Erp;

use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;

final class WorkOrderCompletionReadinessService
{
    public function evaluate(WorkOrder $workOrder): array
    {
        $target = (float) $workOrder->target_base_qty;
        $approvedQualified = (float) DB::table('erp_work_order_completion_lines as line')
            ->join('erp_work_order_completions as completion', 'completion.id', '=', 'line.completion_id')
            ->where('completion.work_order_id', $workOrder->id)
            ->where('completion.status', 'APPROVED')
            ->sum('line.qualified_base_qty');

        $approvedLines = DB::table('erp_work_order_completion_lines as line')
            ->join('erp_work_order_completions as completion', 'completion.id', '=', 'line.completion_id')
            ->join('erp_production_output_records as output', 'output.id', '=', 'line.output_record_id')
            ->where('completion.work_order_id', $workOrder->id)
            ->where('completion.status', 'APPROVED')
            ->get(['line.id', 'line.qualified_base_qty', 'output.output_mode_snapshot', 'output.disposition', 'output.status as output_status']);

        $warehouseRequired = $workOrder->source_type === 'stock_prebuild'
            || $approvedLines->contains(fn (object $line): bool => $line->output_mode_snapshot === 'warehouse_required'
                || ($line->output_mode_snapshot === 'warehouse_optional' && $line->disposition === 'warehouse'));
        $warehousePosted = (float) DB::table('erp_work_order_finished_goods_receipts')
            ->where('work_order_id', $workOrder->id)
            ->where('status', 'POSTED')
            ->whereNotNull('inventory_transaction_id')
            ->sum('posted_base_qty');

        $warehouseLinesReady = $approvedLines->every(function (object $line) use ($workOrder): bool {
            $requiresWarehouse = $workOrder->source_type === 'stock_prebuild'
                || $line->output_mode_snapshot === 'warehouse_required'
                || ($line->output_mode_snapshot === 'warehouse_optional' && $line->disposition === 'warehouse');
            if (! $requiresWarehouse) return in_array($line->output_status, ['COMPLETED', 'WAREHOUSED'], true);
            $posted = (float) DB::table('erp_work_order_finished_goods_receipts')
                ->where('completion_line_id', $line->id)->where('status', 'POSTED')
                ->whereNotNull('inventory_transaction_id')->sum('posted_base_qty');
            return $posted + 0.00000001 >= (float) $line->qualified_base_qty
                && $line->output_status === 'WAREHOUSED';
        });

        $remainingProduction = (float) DB::table('erp_production_quantity_operations')
            ->where('work_order_id', $workOrder->id)->sum('remaining_base_qty');
        $activeLabor = DB::table('erp_production_labor_sessions as labor')
            ->join('erp_production_tasks as task', 'task.id', '=', 'labor.task_id')
            ->where('task.work_order_id', $workOrder->id)->where('labor.status', 'ACTIVE')->count();
        $openReturns = DB::table('erp_production_material_returns')->where('work_order_id', $workOrder->id)
            ->whereIn('status', ['SUBMITTED', 'WAIT_QUALITY'])->count();

        $ready = $approvedQualified + 0.00000001 >= $target
            && $approvedLines->isNotEmpty()
            && $warehouseLinesReady
            && (! $warehouseRequired || $warehousePosted + 0.00000001 >= $target)
            && $remainingProduction <= 0.00000001
            && $activeLabor === 0
            && $openReturns === 0;

        return [
            'ready' => $ready,
            'target_base_qty' => $target,
            'accepted_completed_base_qty' => $approvedQualified,
            'remaining_required_qualified_base_qty' => max(0, $target - $approvedQualified),
            'warehouse_required' => $warehouseRequired,
            'warehouse_posted_base_qty' => $warehousePosted,
            'terminal_outputs_ready' => $warehouseLinesReady,
            'remaining_production_base_qty' => $remainingProduction,
            'active_labor_count' => $activeLabor,
            'open_material_return_count' => $openReturns,
        ];
    }

    public function refresh(WorkOrder $workOrder, object $user, string $reason, ?int $sourceId = null, $now = null): bool
    {
        if ($workOrder->status === 'COMPLETED') return true;
        if (! $this->evaluate($workOrder)['ready']) return false;

        $now ??= now();
        $beforeStatus = (string) $workOrder->status;
        $beforeVersion = (int) $workOrder->business_version;
        $workOrder->status = 'COMPLETED';
        $workOrder->business_version = $beforeVersion + 1;
        $workOrder->save();
        DB::table('erp_work_order_status_logs')->insert([
            'work_order_id' => $workOrder->id,
            'before_status' => $beforeStatus,
            'after_status' => 'COMPLETED',
            'reason' => $reason,
            'operator_legacy_id' => (int) ($user->legacy_id ?? $user->id ?? 0),
            'organization_code' => $workOrder->organization_code,
            'before_version' => $beforeVersion,
            'after_version' => (int) $workOrder->business_version,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
        return true;
    }
}
