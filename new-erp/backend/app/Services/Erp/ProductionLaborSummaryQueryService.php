<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{SalesOrder, WorkOrder};
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Real closed intervals, including interrupted work. This is not the qualified-sample report. */
final class ProductionLaborSummaryQueryService
{
    public function __construct(private readonly ProductionDataScopeResolver $scope) {}

    public function workOrder(int $id, array $filters, object $user, array $permissions, bool $admin): array
    {
        $this->permission($permissions);
        if (! $admin && ! in_array('production.work_order.view', $permissions, true)) $this->denied();
        $visible = WorkOrder::query()->select('id');
        $this->scope->applyWorkOrderScope($visible, $this->scope->resolve($user, 'production.work_order.view', $permissions, $admin));
        $visible->whereIn('id', $this->statisticsVisible($user, $permissions, $admin));
        if (! (clone $visible)->whereKey($id)->exists()) throw new WorkOrderDomainException('not_found', '工单不存在或不在当前数据范围内。', 404);
        $ids = $this->descendants([$id], $visible);
        return $this->present($this->rows($ids, null, $visible), $filters);
    }

    /** Controller retains the existing sales-order visibility check. */
    public function salesOrder(SalesOrder $order, array $filters, object $user, array $permissions, bool $admin): array
    {
        $this->permission($permissions);
        $statisticsScope = $this->scope->resolve($user, 'production.labor_stats.view', $permissions, $admin);
        $visible = $this->statisticsVisible($user, $permissions, $admin, $statisticsScope);
        $roots = WorkOrder::where('source_type', 'sales_order')->where('source_id', $order->id)->whereIn('id', clone $visible)->pluck('id')->map('intval')->all();
        $outputIds = collect(app(ProductionShipmentSourceResolver::class)->forOrder($order))->pluck('output_record_id')->filter()->unique();
        // A selected stock-prebuild source is relevant before dispatch as well as after it.
        foreach (\App\Models\Erp\SalesShipmentLine::whereHas('shipment', fn ($q) => $q->where('sales_order_id', $order->id)
            ->where('shipment_status', '!=', 'cancelled'))->get() as $line) {
            $snapshot = $line->packing_source_snapshot ?? (app(ProductionShipmentSourceResolver::class)->contextForShipmentLine($line)['source_rows'] ?? []);
            foreach ($snapshot['sources'] ?? $snapshot as $source) if (is_array($source) && ! empty($source['output_record_id'])) $outputIds->push((int) $source['output_record_id']);
        }
        $roots = array_values(array_unique(array_merge($roots, DB::table('erp_production_output_records')->whereIn('id', $outputIds->unique())
            ->whereIn('work_order_id', clone $visible)->pluck('work_order_id')->map('intval')->all())));
        return $this->present($this->rows($this->descendants($roots, $visible), (int) $order->id, $visible,
            ($statisticsScope['mode'] ?? 'deny') === 'all'), $filters);
    }

    private function statisticsVisible(object $user, array $permissions, bool $admin, ?array $resolved = null)
    {
        $scope = $resolved ?? $this->scope->resolve($user, 'production.labor_stats.view', $permissions, $admin);
        $scope['can_manage_pool'] = false;
        $query = WorkOrder::query()->select('id'); $this->scope->applyWorkOrderScope($query, $scope);
        return $query;
    }

    private function descendants(array $roots, $visible = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $roots))); $frontier = $ids;
        while ($frontier !== []) {
            $query = WorkOrder::whereIn('assembly_parent_work_order_id', $frontier);
            if ($visible) $query->whereIn('id', clone $visible);
            $next = array_values(array_diff($query->pluck('id')->map('intval')->all(), $ids));
            $ids = array_merge($ids, $next); $frontier = $next;
        }
        return $ids;
    }

    private function rows(array $ids, ?int $salesOrder, $visible = null, bool $allowUnlinkedPacking = false): Builder
    {
        $queries = [];
        foreach (['unit_operation' => 'erp_production_unit_operations', 'quantity_operation' => 'erp_production_quantity_operations'] as $type => $table) {
            $queries[] = DB::table('erp_production_labor_sessions as s')->join($table.' as target', 'target.id', '=', 's.target_id')
                ->join('erp_work_orders as wo', 'wo.id', '=', 'target.work_order_id')
                ->where('s.target_type', $type)->whereIn('wo.id', $ids)
                ->selectRaw("CONCAT('production:',s.id) as id, 'production' as source_type, ? as subject_type, target.id as source_id,
                    wo.work_order_no as source_no, target.operation_name_snapshot as operation_name, s.employee_legacy_id,
                    s.status, s.started_at, s.ended_at, s.end_reason, s.actual_labor_minutes, 0 as shared,
                    JSON_ARRAY(JSON_OBJECT('id',wo.id,'work_order_no',wo.work_order_no)) as related_work_orders", [$type]);
        }
        // Joint jobs already have their own frozen allocation facts. Sum those once per actual session.
        $queries[] = DB::table('erp_production_labor_sessions as s')
            ->join('erp_production_job_bundle_labor_allocations as a', 'a.labor_session_id', '=', 's.id')
            ->join('erp_production_job_bundle_lines as line', 'line.id', '=', 'a.job_bundle_line_id')
            ->join('erp_production_tasks as task', 'task.id', '=', 'line.task_id')
            ->where('s.target_type', 'job_bundle')->whereIn('task.work_order_id', $ids)
            ->groupBy('s.id', 's.target_id', 's.employee_legacy_id', 's.status', 's.started_at', 's.ended_at', 's.end_reason')
            ->selectRaw("CONCAT('production:',s.id) as id, 'production' as source_type, 'job_bundle' as subject_type, s.target_id as source_id,
                NULL as source_no, '共同加工' as operation_name, s.employee_legacy_id, s.status, s.started_at, s.ended_at, s.end_reason,
                SUM(a.allocated_labor_minutes) as actual_labor_minutes, 0 as shared, NULL as related_work_orders");
        $queries[] = DB::table('erp_production_labor_sessions as s')
            ->join('erp_production_cutting_operations as cutting', 'cutting.cutting_task_id', '=', 's.cutting_task_id')
            ->join('erp_work_orders as wo', 'wo.id', '=', 'cutting.work_order_id')
            ->where('s.execution_task_type', 'CUTTING_TASK')->whereIn('wo.id', $ids)->distinct()
            ->selectRaw("CONCAT('production:',s.id) as id, 'production' as source_type, 'cutting_task' as subject_type, s.cutting_task_id as source_id,
                wo.work_order_no as source_no, '下料' as operation_name, s.employee_legacy_id, s.status, s.started_at, s.ended_at, s.end_reason,
                s.actual_labor_minutes, 0 as shared, JSON_ARRAY(JSON_OBJECT('id',wo.id,'work_order_no',wo.work_order_no)) as related_work_orders");

        $packingSources = DB::table('erp_shipment_packing_contents as content')
            ->join('erp_sales_shipment_lines as sl', 'sl.id', '=', 'content.shipment_line_id')
            ->join('erp_production_output_records as source', fn ($join) => $join->whereRaw("JSON_CONTAINS(sl.packing_source_snapshot, JSON_OBJECT('output_record_id',source.id))"))
            ->whereRaw('JSON_CONTAINS(op.packing_content_ids, CAST(content.id AS JSON))');
        $packing = DB::table('erp_shipment_packing_labor_sessions as s')->join('erp_shipment_packing_operations as op', 'op.id', '=', 's.operation_id')
            ->join('erp_sales_shipments as shipment', 'shipment.id', '=', 'op.shipment_id');
        if ($salesOrder !== null) $packing->where('shipment.sales_order_id', $salesOrder);
        else $packing->whereExists((clone $packingSources)->whereIn('source.work_order_id', $ids));
        if ($salesOrder !== null && ! $allowUnlinkedPacking) $packing->whereExists((clone $packingSources)->whereIn('source.work_order_id', $ids));
        if ($visible) $packing->whereNotExists((clone $packingSources)->whereNotIn('source.work_order_id', clone $visible));
        $shared = $salesOrder !== null ? '0' : 'IF(('.(clone $packingSources)->selectRaw('COUNT(DISTINCT source.work_order_id)')->toSql().') > 1,1,0)';
        $queries[] = $packing->selectRaw("CONCAT('packing:',s.id) as id, 'packing' as source_type, 'shipment_packing_operation' as subject_type,
            op.id as source_id, shipment.shipment_no as source_no, op.operation_name_snapshot as operation_name, s.employee_legacy_id,
            s.status, s.started_at, s.ended_at, CASE WHEN s.ended_at IS NULL THEN NULL ELSE s.status END as end_reason,
            s.actual_labor_minutes, {$shared} as shared, NULL as related_work_orders");

        if (Schema::hasTable('erp_warehouse_labor_sessions')) {
            foreach (['public_preparation' => 'erp_public_material_preparation_tasks', 'picking' => 'erp_material_picking_tasks', 'delivery' => 'erp_material_deliveries'] as $type => $table) {
                $query = DB::table('erp_warehouse_labor_sessions as s')->join($table.' as target', 'target.id', '=', 's.target_id')->where('s.target_type', $type);
                if ($type === 'public_preparation') {
                    $query->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_material_picking_tasks as child')
                        ->whereColumn('child.public_preparation_task_id', 'target.id')->whereIn('child.work_order_id', $ids));
                    if ($visible) $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_material_picking_tasks as child')
                        ->whereColumn('child.public_preparation_task_id', 'target.id')->whereNotIn('child.work_order_id', clone $visible));
                } else {
                    $query->whereIn('target.work_order_id', $ids);
                    if ($type === 'picking') $query->whereNull('target.public_preparation_task_id');
                }
                $number = $type === 'delivery' ? 'target.delivery_no' : 'target.task_no';
                $shared = $type === 'public_preparation' ? 1 : 0;
                $queries[] = $query->selectRaw("CONCAT('warehouse:',s.id) as id, 'warehouse' as source_type, ? as subject_type,
                    target.id as source_id, {$number} as source_no, NULL as operation_name, s.employee_legacy_id,
                    s.status, s.started_at, s.ended_at, s.end_reason, s.actual_labor_minutes, {$shared} as shared,
                    JSON_EXTRACT(s.source_snapshot,'$.related_work_orders') as related_work_orders", [$type]);
            }
        }
        $union = array_shift($queries); foreach ($queries as $query) $union->unionAll($query);
        return DB::query()->fromSub($union, 'labor')->leftJoin('erp_legacy_admin_users as employee', 'employee.legacy_id', '=', 'labor.employee_legacy_id');
    }

    private function present(Builder $query, array $filters): array
    {
        $amounts = "COALESCE(SUM(CASE WHEN labor.ended_at IS NOT NULL AND labor.shared=0 THEN labor.actual_labor_minutes ELSE 0 END),0) as exclusive_closed_minutes,
            COALESCE(SUM(CASE WHEN labor.ended_at IS NOT NULL AND labor.shared=1 THEN labor.actual_labor_minutes ELSE 0 END),0) as shared_closed_minutes,
            COALESCE(SUM(CASE WHEN labor.status='ACTIVE' THEN 1 ELSE 0 END),0) as active_count";
        $total = (array) (clone $query)->selectRaw($amounts)->first();
        $people = (clone $query)->groupBy('labor.employee_legacy_id', 'employee.nickname', 'employee.username')
            ->selectRaw('labor.employee_legacy_id, COALESCE(NULLIF(employee.nickname,\'\'),employee.username) as employee_name, '.$amounts)
            ->orderBy('labor.employee_legacy_id')->paginate(10, ['*'], 'people_page', max(1, (int) ($filters['people_page'] ?? 1)));
        $people->setCollection($people->getCollection()->map(fn ($row) => ['employee_legacy_id' => (int) $row->employee_legacy_id,
            'employee_name' => $row->employee_name, 'exclusive_closed_minutes' => bcadd((string) $row->exclusive_closed_minutes, '0', 2),
            'shared_closed_minutes' => bcadd((string) $row->shared_closed_minutes, '0', 2)]));
        $page = (clone $query)->orderByDesc('labor.started_at')->orderByDesc('labor.id')
            ->select('labor.*')->selectRaw("COALESCE(NULLIF(employee.nickname,''),employee.username) as employee_name")
            ->paginate(10, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $page->setCollection($page->getCollection()->map(function ($row) {
            $data = (array) $row; $data['shared'] = (bool) $row->shared;
            $data['related_work_orders'] = $row->related_work_orders ? json_decode($row->related_work_orders, true, 512, JSON_THROW_ON_ERROR) : [];
            if ($row->source_type === 'packing') $data['related_work_orders'] = $this->packingWorkOrders((int) $row->source_id);
            return $data;
        }));
        return ['summary' => ['exclusive_closed_minutes' => bcadd((string) $total['exclusive_closed_minutes'], '0', 2),
            'shared_closed_minutes' => bcadd((string) $total['shared_closed_minutes'], '0', 2), 'active_count' => (int) $total['active_count'],
            'people' => $people->toArray()], 'sessions' => $page->toArray()];
    }

    private function packingWorkOrders(int $operation): array
    {
        $ids = json_decode((string) DB::table('erp_shipment_packing_operations')->where('id', $operation)->value('packing_content_ids'), true) ?: [];
        $outputIds = [];
        foreach (DB::table('erp_shipment_packing_contents')->whereIn('id', $ids)->pluck('source_snapshot') as $json) {
            $snapshot = json_decode($json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            foreach ($snapshot['sources'] ?? $snapshot as $source) if (is_array($source) && ! empty($source['output_record_id'])) $outputIds[] = (int) $source['output_record_id'];
        }
        return WorkOrder::whereIn('id', DB::table('erp_production_output_records')->whereIn('id', $outputIds)->select('work_order_id'))
            ->orderBy('id')->get(['id', 'work_order_no'])->map(fn ($wo) => ['id' => (int) $wo->id, 'work_order_no' => $wo->work_order_no])->all();
    }
    private function permission(array $permissions): void
    { if (! in_array('production.labor_stats.view', $permissions, true)) $this->denied(); }
    private function denied(): never { throw new WorkOrderDomainException('permission_denied', '当前账号没有查看实际工时统计的权限。', 403); }
}
