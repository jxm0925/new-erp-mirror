<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderProductionRequirement;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Coordinates the production reverse action of an authorized official sales change. */
final class SalesOrderProductionChangeService
{
    public static function treeIsUnreleased(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (! empty($node['released_at']) || ! in_array($node['status'] ?? null, ['DRAFT', 'WAIT_RELEASE', 'CANCELLED'], true)) return false;
        }
        return true;
    }

    public static function taskHasExecutionFacts(array $task): bool
    {
        return ! empty($task['claimed_at'])
            || ! in_array($task['status'] ?? null, ['WAIT_CLAIM', 'WAIT_PREDECESSOR', 'WAIT_ACCEPT', 'CANCELLED'], true);
    }

    public static function operationHasExecutionFacts(array $operation): bool
    {
        if (! in_array($operation['status'] ?? null, ['WAIT_PREVIOUS', 'WAIT_CLAIM', 'CANCELLED'], true)) return true;
        foreach (['claimed_at', 'kitting_confirmed_at', 'started_at', 'paused_at', 'completed_at'] as $field) {
            if (! empty($operation[$field])) return true;
        }
        foreach (['actual_labor_minutes', 'completed_base_qty', 'unqualified_base_qty', 'scrapped_base_qty'] as $field) {
            if ((float) ($operation[$field] ?? 0) > 0) return true;
        }
        return false;
    }

    public function cancelUnreleasedLocked(SalesOrder $order, ?object $actor, string $reason): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('销售生产变更必须在订单事务内执行。');
        $demandIds = SalesOrderProductionRequirement::where('sales_order_id', $order->id)->pluck('id');
        $roots = WorkOrder::query()->whereNull('assembly_component_demand_id')
            ->where(function ($q) use ($order, $demandIds): void {
                $q->where(fn ($source) => $source->where('source_type', 'sales_order')->where('source_id', $order->id))
                    ->orWhereIn('production_demand_id', $demandIds);
            })->where(function ($q): void {
                $q->where('status', '<>', WorkOrderApplicationService::CANCELLED)
                    ->orWhereExists(function ($children): void {
                        $children->selectRaw('1')->from('erp_work_orders as child')
                            ->whereColumn('child.assembly_root_work_order_id', 'erp_work_orders.id')
                            ->where('child.status', '<>', WorkOrderApplicationService::CANCELLED);
                    });
            })->orderBy('id')->get();
        if ($roots->isEmpty()) return;
        if (! $actor || (int) ($actor->legacy_id ?? $actor->id ?? 0) < 1) {
            throw ValidationException::withMessages(['operator' => '撤销订单关联生产工单需要真实操作账号，请重新登录后处理。']);
        }
        // This does not borrow production permissions. The formal sales use case has
        // already checked its own permission and data scope; the dedicated WO entry
        // re-locks ownership and the complete unreleased tree before changing facts.
        foreach ($roots as $root) {
            app(WorkOrderApplicationService::class)->cancelUnreleasedSalesTreeLocked((int) $order->id, (int) $root->id, $actor, $reason);
        }
    }
}
