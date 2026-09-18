<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read side for production execution facts which previously only exposed write
 * commands.  Every resource is constrained by the same work-order scope as the
 * rest of production, so a mobile inbox can safely discover actionable rows.
 */
final class ProductionExecutionInboxService
{
    public function __construct(private readonly ProductionDataScopeResolver $scopeResolver) {}

    public function paginate(string $resource, array $filters, object $user, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->permission($permissions, 'production.task.view');
        $query = $this->query($resource, $this->visibleWorkOrderIds($user, $permissions, $superAdmin), $user, $permissions, $superAdmin);
        $this->applyFilters($query, $filters);
        $page = $query->orderByDesc('records.id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
        $rawRows = collect($page->items());
        $summaries = $this->lineSummaries($resource, $rawRows->pluck('id')->map(fn ($id) => (int) $id)->all());
        $rows = $rawRows->map(function (object $row) use ($resource, $permissions, $user, $summaries): array {
            $data = $this->present($resource, $row, $permissions, $user);
            if ($resource !== 'outputs') {
                $data['line_count'] = $summaries->get((int) $row->id, collect())->count();
                $data['line_summary'] = $summaries->get((int) $row->id, collect())->values()->all();
            }
            return $data;
        });
        $page->setCollection($rows);
        return $page;
    }

    public function show(string $resource, int $id, object $user, array $permissions, bool $superAdmin): array
    {
        $row = $this->visibleRecord($resource, $id, $user, $permissions, $superAdmin);
        if (! $row) throw new WorkOrderDomainException('execution_record_not_found', '生产执行记录不存在或不在当前数据范围内。', 404);
        return $this->present($resource, $row, $permissions, $user, true);
    }

    /**
     * Guard write commands with the exact same work-order data scope as their
     * read-side record.  Controllers call this before entering a command
     * service so button permission alone can never cross a self/department
     * boundary.
     */
    public function assertVisible(string $resource, int $id, object $user, array $permissions, bool $superAdmin): void
    {
        if (! $this->visibleRecord($resource, $id, $user, $permissions, $superAdmin)) {
            throw new WorkOrderDomainException('execution_record_not_found', '生产执行记录不存在或不在当前数据范围内。', 404);
        }
    }

    private function visibleRecord(string $resource, int $id, object $user, array $permissions, bool $superAdmin): ?object
    {
        $this->permission($permissions, 'production.task.view');
        return $this->query($resource, $this->visibleWorkOrderIds($user, $permissions, $superAdmin), $user, $permissions, $superAdmin)
            ->where('records.id', $id)->first();
    }

    private function visibleWorkOrderIds(object $user, array $permissions, bool $superAdmin)
    {
        $scope = $this->scopeResolver->resolve($user, 'production.task.view', $permissions, $superAdmin);
        $query = WorkOrder::query()->select('id');
        $this->scopeResolver->applyWorkOrderScope($query, $scope);
        return $query;
    }

    private function query(string $resource, $visibleWorkOrders, object $user, array $permissions, bool $superAdmin): Builder
    {
        $table = match ($resource) {
            'outputs' => 'erp_production_output_records',
            'internal_issues' => 'erp_production_internal_issue_tasks',
            'material_supplements' => 'erp_production_material_supplement_requests',
            'material_returns' => 'erp_production_material_returns',
            default => throw new WorkOrderDomainException('execution_resource_invalid', '生产执行资源类型无效。', 404),
        };
        $query = DB::table("{$table} as records")
            ->join('erp_work_orders as wo', 'wo.id', '=', 'records.work_order_id')
            ->leftJoin('erp_items as output_item', 'output_item.id', '=', 'wo.output_item_id')
            ->select('records.*', 'wo.work_order_no', 'wo.status as work_order_status',
                'output_item.item_code as work_order_item_code', 'output_item.item_name as work_order_item_name');
        if ($resource !== 'outputs') {
            $taskKey = $resource === 'internal_issues' ? 'records.target_task_id' : 'records.task_id';
            $query->leftJoin('erp_production_tasks as task', 'task.id', '=', $taskKey)
                ->addSelect('task.task_no', 'task.operation_code_snapshot', 'task.operation_name_snapshot');
        } else {
            $query->leftJoin('erp_items as item', 'item.id', '=', 'records.output_item_id')
                ->addSelect('item.item_code', 'item.item_name');
        }
        if ($resource === 'internal_issues') {
            $tasks = \App\Models\Erp\ProductionTask::query();
            $this->scopeResolver->applyProductionTaskScope($tasks,
                $this->scopeResolver->resolve($user, 'production.task.view', $permissions, $superAdmin),
                (int) ($user->legacy_id ?? $user->id ?? 0));
            // A cutting receipt can serve a different WO owner. The receiver follows the
            // real target task; unrelated ordinary issue records retain their previous WO gate.
            $query->where(fn ($q) => $q->whereIn('records.work_order_id', $visibleWorkOrders)
                ->orWhere(fn ($q) => $q->where('records.source_type', 'cutting_reserved')
                    ->whereIn('records.target_task_id', $tasks->select('erp_production_tasks.id'))));
            $query->addSelect('task.assignee_user_legacy_id as current_receiver_legacy_id');
        } else $query->whereIn('records.work_order_id', $visibleWorkOrders);
        return $query;
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) $query->where('records.status', $filters['status']);
        if (! empty($filters['work_order_id'])) $query->where('records.work_order_id', (int) $filters['work_order_id']);
        if (! empty($filters['task_id'])) $query->where('task.id', (int) $filters['task_id']);
    }

    private function present(string $resource, object $row, array $permissions, object $user, bool $detail = false): array
    {
        $data = (array) $row;
        if ($resource === 'outputs') $data = ProductionMaterialCostService::presentOutput($row, $permissions);
        $data['id'] = (int) $row->id;
        $data['work_order_id'] = (int) $row->work_order_id;
        $data['business_version'] = (int) $row->business_version;
        $data['allowed_actions'] = $this->actions($resource, $row, $permissions, $user);
        if (! $detail) return $data;

        if ($resource === 'outputs') {
            $data['quality_inspections'] = DB::table('erp_production_quality_inspections')->where('output_record_id', $row->id)->orderBy('id')->get()->map(fn ($v) => (array) $v)->all();
            $data['warehouse_postings'] = DB::table('erp_production_output_warehouse_postings')->where('output_record_id', $row->id)->orderBy('id')->get()->map(fn ($v) => (array) $v)->all();
        }
        if ($resource === 'internal_issues') {
            $data['lines'] = DB::table('erp_production_internal_issue_lines as line')->leftJoin('erp_items as item', 'item.id', '=', 'line.item_id')
                ->where('line.issue_task_id', $row->id)->select('line.*', 'item.item_code', 'item.item_name')->orderBy('line.id')->get()->map(fn ($v) => (array) $v)->all();
            if (($row->source_type ?? '') === 'cutting_reserved' && ! in_array('production.cutting.inventory.view', $permissions, true)) {
                foreach ($data['lines'] as &$line) unset($line['issue_total_cost']);
                unset($line);
            }
        }
        if ($resource === 'material_supplements') {
            $data['lines'] = DB::table('erp_production_material_supplement_lines as line')->leftJoin('erp_items as item', 'item.id', '=', 'line.component_item_id')
                ->where('line.supplement_request_id', $row->id)->select('line.*', 'item.item_code', 'item.item_name')->orderBy('line.id')->get()->map(fn ($v) => (array) $v)->all();
        }
        if ($resource === 'material_returns') {
            $data['lines'] = DB::table('erp_production_material_return_lines as line')->leftJoin('erp_items as item', 'item.id', '=', 'line.component_item_id')
                ->leftJoin('erp_warehouses as warehouse', 'warehouse.id', '=', 'line.warehouse_id')->leftJoin('erp_locations as location', 'location.id', '=', 'line.location_id')
                ->where('line.return_id', $row->id)->select('line.*', 'item.item_code', 'item.item_name', 'warehouse.warehouse_name', 'location.location_name')
                ->orderBy('line.id')->get()->map(fn ($v) => (array) $v)->all();
            $data['quality_inspections'] = DB::table('erp_production_material_return_inspections')->where('return_id', $row->id)->orderBy('id')->get()->map(fn ($v) => (array) $v)->all();
        }
        return $data;
    }

    private function actions(string $resource, object $row, array $permissions, object $user): array
    {
        $has = fn (string $permission): bool => in_array($permission, $permissions, true);
        $status = (string) $row->status;
        return match ($resource) {
            'outputs' => [
                'quality_inspect' => $status === 'WAIT_QUALITY' && $has('production.output.quality'),
                'warehouse' => $status === 'WAIT_WAREHOUSE' && $has('production.output.warehouse'),
            ],
            'internal_issues' => [
                'dispatch' => $status === 'WAIT_ISSUE' && $has('production.output.issue'),
                'receive' => $status === 'ISSUED' && $has('production.output.receive')
                    && (int) (($row->source_type ?? '') === 'cutting_reserved'
                        ? ($row->current_receiver_legacy_id ?? 0) : ($row->expected_receiver_legacy_id ?? 0)) === (int) ($user->legacy_id ?? $user->id ?? 0),
            ],
            'material_supplements' => ['decide' => $status === 'SUBMITTED' && $has('production.material_supplement.approve')],
            'material_returns' => [
                'receive' => $status === 'SUBMITTED' && $has('production.material_return.receive'),
                'quality' => $status === 'WAIT_QUALITY' && $has('production.material_return.quality'),
            ],
        };
    }

    private function lineSummaries(string $resource, array $ids): \Illuminate\Support\Collection
    {
        if ($ids === [] || $resource === 'outputs') return collect();
        [$table, $foreignKey, $quantity] = match ($resource) {
            'internal_issues' => ['erp_production_internal_issue_lines', 'issue_task_id', 'issue_base_qty'],
            'material_supplements' => ['erp_production_material_supplement_lines', 'supplement_request_id', 'additional_base_qty'],
            'material_returns' => ['erp_production_material_return_lines', 'return_id', 'return_base_qty'],
        };
        return DB::table("{$table} as line")->leftJoin('erp_items as item', 'item.id', '=', $resource === 'internal_issues' ? 'line.item_id' : 'line.component_item_id')
            ->whereIn("line.{$foreignKey}", $ids)
            ->select("line.{$foreignKey} as parent_id", 'item.item_code', 'item.item_name', "line.{$quantity} as quantity")
            ->orderBy('line.id')->get()->groupBy('parent_id')->map(fn ($rows) => $rows->map(fn ($row): array => [
                'item_code' => $row->item_code, 'item_name' => $row->item_name, 'quantity' => (float) $row->quantity,
            ]));
    }

    private function permission(array $permissions, string $code): void
    {
        if (! in_array($code, $permissions, true)) throw new WorkOrderDomainException('permission_denied', '当前用户没有查看生产执行待办的权限。', 403, ['permission' => $code]);
    }
}
