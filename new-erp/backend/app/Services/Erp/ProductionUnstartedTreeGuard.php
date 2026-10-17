<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;

/** Replacing an unpublished component plan must not discard execution or purchasing facts. */
final class ProductionUnstartedTreeGuard
{
    public function assertForInventoryContinuation(WorkOrder $root): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Production plan replacement requires its application transaction.');
        $tree = WorkOrder::where(fn ($q) => $q->whereKey($root->id)->orWhere('assembly_root_work_order_id', $root->id))
            ->orderBy('id')->lockForUpdate()->get();
        if (! SalesOrderProductionChangeService::treeIsUnreleased($tree->map(fn ($wo) => $wo->getAttributes())->all())) {
            $this->fail('continuation_tree_already_released', '关联部件工单已发布，不能改用整机库存续接。');
        }
        $ids = $tree->pluck('id');
        $demandFacts = DB::table('erp_sales_order_production_requirements')->whereIn('id', $tree->pluck('production_demand_id')->filter())
            ->where(fn ($q) => $q->where('consumed_qty', '>', 0)->orWhere('closed_qty', '>', 0))->lockForUpdate()->get()->isNotEmpty();
        $tasks = DB::table('erp_production_tasks')->whereIn('work_order_id', $ids)->orderBy('id')->lockForUpdate()->get();
        $hasFacts = $demandFacts || $tasks->contains(fn ($task) => SalesOrderProductionChangeService::taskHasExecutionFacts((array) $task)
            || ! empty($task->started_at) || ! empty($task->completed_at) || (float) ($task->actual_labor_minutes ?? 0) > 0);
        $targets = [];
        foreach (['unit_operation' => 'erp_production_unit_operations', 'quantity_operation' => 'erp_production_quantity_operations'] as $type => $table) {
            $rows = DB::table($table)->whereIn('work_order_id', $ids)->orderBy('id')->lockForUpdate()->get();
            $hasFacts = $hasFacts || $rows->contains(fn ($row) => SalesOrderProductionChangeService::operationHasExecutionFacts((array) $row));
            $targets[$type] = $rows->pluck('id');
        }
        $targetQuery = static function ($query) use ($targets): void {
            foreach ($targets as $type => $targetIds) $query->orWhere(fn ($q) => $q->where('target_type', $type)->whereIn('target_id', $targetIds));
        };
        $targetRequirements = DB::table('erp_production_target_material_requirements')->where($targetQuery)->lockForUpdate()->get();
        $hasFacts = $hasFacts || $targetRequirements->contains(fn ($row) => (float) ($row->satisfied_base_qty ?? 0) > 0
                || (float) ($row->consumed_base_qty ?? 0) > 0 || (float) ($row->returned_base_qty ?? 0) > 0)
            || DB::table('erp_production_output_records')->whereIn('work_order_id', $ids)->lockForUpdate()->get()->isNotEmpty()
            || DB::table('erp_production_reports')->whereIn('work_order_id', $ids)->lockForUpdate()->get()->isNotEmpty()
            || DB::table('erp_production_labor_sessions')->where(function ($q) use ($tasks, $targetQuery): void {
                $q->whereIn('task_id', $tasks->pluck('id'))->orWhere($targetQuery);
            })->lockForUpdate()->get()->isNotEmpty()
            || DB::table('erp_production_workstation_stock_confirmations')->where($targetQuery)->lockForUpdate()->get()->isNotEmpty()
            || DB::table('erp_production_kitting_confirmations')->where($targetQuery)->lockForUpdate()->get()->isNotEmpty()
            || DB::table('erp_production_input_holdings')->where($targetQuery)->lockForUpdate()->get()->isNotEmpty()
            || DB::table('erp_material_receipts')->whereIn('work_order_id', $ids)->lockForUpdate()->get()->isNotEmpty()
            || DB::table('erp_material_deliveries')->whereIn('work_order_id', $ids)->where('status', '<>', 'CANCELLED')->lockForUpdate()->get()->isNotEmpty()
            || DB::table('erp_assembly_inventory_reservations')->whereIn('work_order_id', $ids)->where('consumed_base_qty', '>', 0)->lockForUpdate()->get()->isNotEmpty()
            || DB::table('erp_material_picking_tasks')->whereIn('work_order_id', $ids)
                ->where(fn ($q) => $q->where('status', '<>', 'CANCELLED')->orWhereNotNull('inventory_transaction_id'))->lockForUpdate()->get()->isNotEmpty();
        $formal = DB::table('erp_work_order_material_requirements')->whereIn('work_order_id', $ids)->lockForUpdate()->get();
        $hasFacts = $hasFacts || $formal->contains(function ($row): bool {
            foreach (['issued_qty', 'picked_qty', 'delivered_qty', 'received_qty', 'returned_qty'] as $field) if ((float) ($row->{$field} ?? 0) > 0) return true;
            return false;
        });
        if ($hasFacts) $this->fail('continuation_tree_has_facts', '关联工单已有接单、齐套、工时、领料或产出事实，不能改用整机库存续接。');
        if ($tasks->isNotEmpty() || collect($targets)->contains(fn ($rows) => $rows->isNotEmpty())
            || DB::table('erp_production_units')->whereIn('work_order_id', $ids)->lockForUpdate()->get()->isNotEmpty()) {
            $this->fail('continuation_execution_foundation_exists', '未发布工单已存在正式执行任务，请先核对发布与执行状态，不能直接替换。');
        }
        // Any formal requirement before publication is inconsistent with the current
        // preparation contract. Preserve it for review rather than delete/reinsert it.
        if ($formal->isNotEmpty()) $this->fail('continuation_formal_requirements_exist', '未发布工单已存在正式物料需求，请先核对原需求，不能直接替换。');
        $preparations = DB::table('erp_work_order_preparation_materials')->whereIn('work_order_id', $ids)->lockForUpdate()->pluck('id');
        $procurement = DB::table('erp_material_procurement_sources')->where(function ($q) use ($ids, $preparations, $targetRequirements): void {
            $q->whereIn('work_order_id', $ids)->orWhereIn('preparation_material_requirement_id', $preparations)
                ->orWhereIn('target_material_requirement_id', $targetRequirements->pluck('id'));
        })->lockForUpdate()->get();
        // Purchasing is a separate commitment. Even a fulfilled or cancelled request
        // keeps its ownership history; replacement must wait for its formal reverse action.
        if ($procurement->isNotEmpty()) $this->fail('continuation_tree_has_procurement', '关联工单已有申购或采购来源记录，请先处理原采购关联，不能直接替换生产计划。');
    }

    private function fail(string $code, string $message): never { throw new WorkOrderDomainException($code, $message, 409); }
}
