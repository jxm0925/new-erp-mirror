<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionMasterOrder;
use App\Models\Erp\ProductionUnit;
use App\Models\Erp\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProductionMasterOrderQueryService
{
    private const DISPLAY_STATUSES = ['IN_PROGRESS', 'WAIT_CONDITION', 'EXCEPTION', 'COMPLETED'];
    private const ACTIVE_TASK_STATUSES = [
        'CLAIMED', 'WAIT_MATERIAL', 'WAIT_HANDOVER', 'READY', 'IN_PROGRESS', 'PAUSED',
        'WAIT_QUALITY', 'WAIT_WAREHOUSE',
    ];
    private const EXCEPTION_OPERATION_STATUSES = ['REWORK', 'QUALITY_FAILED', 'HANDOVER_REJECTED'];

    public function __construct(
        private readonly ProductionDataScopeResolver $scopeResolver,
        private readonly SalesOrderFundingGateService $fundingGates,
    ) {}

    public function paginate(array $filters, object $user, array $permissions, bool $superAdmin = false): LengthAwarePaginator
    {
        $this->permission($permissions, $superAdmin);
        $scope = $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin);
        $query = $this->baseQuery($scope);
        $this->applyKeyword($query, $filters['keyword'] ?? null);
        $this->applyDisplayStatus($query, $filters['status'] ?? null, $scope);

        $page = $query->paginate(
            min(50, max(1, (int) ($filters['per_page'] ?? 20))),
            ['*'],
            'page',
            max(1, (int) ($filters['page'] ?? 1)),
        );
        $context = $this->projectionContext($page->getCollection());
        $page->setCollection($page->getCollection()->map(
            fn (ProductionMasterOrder $order) => $this->projection($order, $context, $permissions, $superAdmin)
        ));
        return $page;
    }

    /**
     * Counts are calculated from the same scoped live facts as the list. The persisted
     * MWO status is intentionally not used because it is only the creation snapshot.
     */
    public function summary(array $filters, object $user, array $permissions, bool $superAdmin = false): array
    {
        $this->permission($permissions, $superAdmin);
        $scope = $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin);
        $counts = [];
        foreach (self::DISPLAY_STATUSES as $status) {
            $query = $this->baseQuery($scope, false);
            $this->applyKeyword($query, $filters['keyword'] ?? null);
            $this->applyDisplayStatus($query, $status, $scope);
            $counts[$status] = $query->count();
        }
        return [
            'total' => array_sum($counts),
            'in_progress' => $counts['IN_PROGRESS'],
            'wait_condition' => $counts['WAIT_CONDITION'],
            'exception' => $counts['EXCEPTION'],
            'completed' => $counts['COMPLETED'],
        ];
    }

    public function show(int $id, object $user, array $permissions, bool $superAdmin = false): array
    {
        $this->permission($permissions, $superAdmin);
        $scope = $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin);
        $order = ProductionMasterOrder::query()->with([
            'salesOrder',
            'workOrders' => function ($workOrders) use ($scope): void {
                $this->scopeResolver->applyWorkOrderScope($workOrders->getQuery(), $scope);
                $workOrders->with('outputItem');
            },
        ])->find($id);
        if (! $order) throw new WorkOrderDomainException('master_order_not_found', '主生产工单不存在。', 404);
        $visible = WorkOrder::query()->where('production_master_order_id', $id);
        $this->scopeResolver->applyWorkOrderScope($visible, $scope);
        if (! $visible->exists()) throw new WorkOrderDomainException('data_scope_denied', '主生产工单不在当前数据范围内。', 403);
        $masters = collect([$order]);
        return $this->projection($order, $this->projectionContext($masters), $permissions, $superAdmin);
    }

    public function workOrders(int $id, array $filters, object $user, array $permissions, bool $superAdmin = false): LengthAwarePaginator
    {
        $this->show($id, $user, $permissions, $superAdmin);
        $query = WorkOrder::query()->with('outputItem')->where('production_master_order_id', $id)->orderBy('id');
        $scope = $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin);
        $this->scopeResolver->applyWorkOrderScope($query, $scope);
        return $query->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
    }

    public function units(int $id, array $filters, object $user, array $permissions, bool $superAdmin = false): LengthAwarePaginator
    {
        $this->show($id, $user, $permissions, $superAdmin);
        return ProductionUnit::query()->with(['workOrder:id,work_order_no,production_master_order_id'])
            ->whereHas('workOrder', fn (Builder $q) => $q->where('production_master_order_id', $id))
            ->orderBy('work_order_id')->orderBy('sequence_no')
            ->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
    }

    private function baseQuery(array $scope, bool $withRelations = true): Builder
    {
        $query = ProductionMasterOrder::query()->orderByDesc('id');
        if ($withRelations) {
            $query->with([
                'salesOrder',
                'workOrders' => function ($workOrders) use ($scope): void {
                    $this->scopeResolver->applyWorkOrderScope($workOrders->getQuery(), $scope);
                    $workOrders->with('outputItem');
                },
            ]);
        }
        $query->whereHas('workOrders', function (Builder $workOrders) use ($scope): void {
            $this->scopeResolver->applyWorkOrderScope($workOrders, $scope);
        });
        return $query;
    }

    private function applyKeyword(Builder $query, mixed $value): void
    {
        $keyword = trim((string) $value);
        if ($keyword === '') return;
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $keyword).'%';
        $query->where(function (Builder $q) use ($like): void {
            $q->where('master_order_no', 'like', $like)
                ->orWhere('sales_order_no_snapshot', 'like', $like)
                ->orWhere('salesperson_name_snapshot', 'like', $like)
                ->orWhereRaw("JSON_SEARCH(customer_snapshot, 'one', ?) IS NOT NULL", [$like])
                ->orWhereHas('workOrders.outputItem', fn (Builder $item) => $item
                    ->where('item_name', 'like', $like)
                    ->orWhere('item_code', 'like', $like)
                    ->orWhere('spec', 'like', $like));
        });
    }

    private function applyDisplayStatus(Builder $query, mixed $value, array $scope): void
    {
        $status = strtoupper(trim((string) $value));
        if ($status === '') return;
        if (! in_array($status, self::DISPLAY_STATUSES, true)) {
            throw new WorkOrderDomainException('invalid_master_order_status', '主生产工单状态筛选值无效。', 422);
        }

        if ($status === 'EXCEPTION') {
            $this->whereHasExecutionException($query, $scope);
            return;
        }
        $this->whereHasExecutionException($query, $scope, true);
        if ($status === 'IN_PROGRESS') {
            $query->whereHas('workOrders', function (Builder $workOrders) use ($scope): void {
                $this->scopeResolver->applyWorkOrderScope($workOrders, $scope);
                $workOrders->where('status', 'IN_PROGRESS');
            });
            return;
        }
        if ($status === 'COMPLETED') {
            $query->whereHas('workOrders', function (Builder $workOrders) use ($scope): void {
                $this->scopeResolver->applyWorkOrderScope($workOrders, $scope);
            })
                ->whereDoesntHave('workOrders', function (Builder $workOrders) use ($scope): void {
                    $this->scopeResolver->applyWorkOrderScope($workOrders, $scope);
                    $workOrders->whereNotIn('status', ['COMPLETED', 'CLOSED']);
                });
            return;
        }
        $query->whereDoesntHave('workOrders', function (Builder $workOrders) use ($scope): void {
            $this->scopeResolver->applyWorkOrderScope($workOrders, $scope);
            $workOrders->where('status', 'IN_PROGRESS');
        })->where(function (Builder $pending) use ($scope): void {
                $pending->whereDoesntHave('workOrders', function (Builder $workOrders) use ($scope): void {
                    $this->scopeResolver->applyWorkOrderScope($workOrders, $scope);
                })
                    ->orWhereHas('workOrders', function (Builder $workOrders) use ($scope): void {
                        $this->scopeResolver->applyWorkOrderScope($workOrders, $scope);
                        $workOrders->whereNotIn('status', ['COMPLETED', 'CLOSED']);
                    });
            });
    }

    private function whereHasExecutionException(Builder $query, array $scope, bool $negate = false): void
    {
        $method = $negate ? 'whereDoesntHave' : 'whereHas';
        $query->{$method}('workOrders', function (Builder $workOrders) use ($scope): void {
            $this->scopeResolver->applyWorkOrderScope($workOrders, $scope);
            $workOrders->where(function (Builder $execution): void {
                $execution->whereExists(function ($ops): void {
                    $ops->selectRaw('1')->from('erp_production_unit_operations as uop')
                        ->whereColumn('uop.work_order_id', 'erp_work_orders.id')
                        ->whereIn('uop.status', self::EXCEPTION_OPERATION_STATUSES);
                })->orWhereExists(function ($ops): void {
                    $ops->selectRaw('1')->from('erp_production_quantity_operations as qop')
                        ->whereColumn('qop.work_order_id', 'erp_work_orders.id')
                        ->whereIn('qop.status', self::EXCEPTION_OPERATION_STATUSES);
                });
            });
        });
    }

    private function projectionContext(Collection $masters): array
    {
        $workOrders = $masters->flatMap(fn (ProductionMasterOrder $master) => $master->workOrders)->values();
        $workOrderIds = $workOrders->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($workOrderIds === []) return [];

        $taskRows = DB::table('erp_production_tasks')->whereIn('work_order_id', $workOrderIds)
            ->selectRaw("work_order_id, COUNT(*) total_count, SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) completed_count, SUM(CASE WHEN status IN ('".implode("','", self::ACTIVE_TASK_STATUSES)."') THEN 1 ELSE 0 END) active_count")
            ->groupBy('work_order_id')->get()->keyBy('work_order_id');
        $completionRows = DB::table('erp_work_order_completion_lines as line')
            ->join('erp_work_order_completions as completion', 'completion.id', '=', 'line.completion_id')
            ->whereIn('completion.work_order_id', $workOrderIds)->where('completion.status', 'APPROVED')
            ->selectRaw('completion.work_order_id, SUM(line.qualified_base_qty) completed_qty')
            ->groupBy('completion.work_order_id')->pluck('completed_qty', 'completion.work_order_id');
        $unitActive = DB::table('erp_production_unit_operations')->whereIn('work_order_id', $workOrderIds)
            ->whereIn('status', self::ACTIVE_TASK_STATUSES)->selectRaw('work_order_id, COUNT(DISTINCT production_unit_id) qty')
            ->groupBy('work_order_id')->pluck('qty', 'work_order_id');
        $unitException = DB::table('erp_production_unit_operations')->whereIn('work_order_id', $workOrderIds)
            ->whereIn('status', self::EXCEPTION_OPERATION_STATUSES)->selectRaw('work_order_id, COUNT(DISTINCT production_unit_id) qty')
            ->groupBy('work_order_id')->pluck('qty', 'work_order_id');
        $quantityOperations = DB::table('erp_production_quantity_operations')->whereIn('work_order_id', $workOrderIds)
            ->get(['work_order_id', 'status', 'planned_base_qty', 'completed_base_qty', 'scrapped_base_qty', 'remaining_base_qty'])
            ->groupBy('work_order_id');

        $deliveryRows = DB::table('erp_production_preparation_order_lines as line')
            ->join('erp_production_preparation_orders as prep', 'prep.id', '=', 'line.preparation_order_id')
            ->whereIn('prep.production_master_order_id', $masters->pluck('id'))
            ->get(['prep.production_master_order_id', 'line.required_base_qty', 'line.prepared_base_qty', 'line.delivered_base_qty', 'line.received_base_qty', 'line.status'])
            ->groupBy('production_master_order_id');
        $unitKitting = DB::table('erp_production_unit_operations')->whereIn('work_order_id', $workOrderIds)
            ->where('kitting_required', true)->get(['work_order_id', 'kitting_confirmed_at']);
        $quantityKitting = DB::table('erp_production_quantity_operations')->whereIn('work_order_id', $workOrderIds)
            ->where('kitting_required', true)->get(['work_order_id', 'kitting_confirmed_at']);

        $latestGateVersions = DB::table('erp_work_order_release_gate_checks')
            ->whereIn('work_order_id', $workOrderIds)
            ->selectRaw('work_order_id, MAX(work_order_version) work_order_version')
            ->groupBy('work_order_id');
        $gateBlockers = DB::table('erp_work_order_release_gate_checks as gate')
            ->joinSub($latestGateVersions, 'latest', fn ($join) => $join
                ->on('latest.work_order_id', '=', 'gate.work_order_id')
                ->on('latest.work_order_version', '=', 'gate.work_order_version'))
            ->where('gate.status', '!=', 'passed')
            ->get(['gate.work_order_id', 'gate.reason_code', 'gate.message']);

        return compact(
            'taskRows', 'completionRows', 'unitActive', 'unitException', 'quantityOperations',
            'deliveryRows', 'unitKitting', 'quantityKitting', 'gateBlockers'
        );
    }

    private function projection(ProductionMasterOrder $master, array $context, array $permissions, bool $superAdmin): array
    {
        $workOrders = $master->workOrders->values();
        $quantity = $this->quantitySummary($workOrders, $context);
        $tasks = $this->taskSummary($workOrders, $context);
        $delivery = $this->deliverySummary($master, $context);
        $kitting = $this->kittingSummary($workOrders, $context);
        $funding = $this->fundingGates->statusForPermissions($master->salesOrder, $permissions, $superAdmin);
        $blockers = $this->blockers($workOrders, $context, $funding);
        $status = $this->displayStatus($workOrders, $quantity, $tasks);

        return [...$master->toArray(),
            'display_status' => $status,
            'work_order_count' => $workOrders->count(),
            'product_summary' => $this->productSummary($workOrders, $context),
            'quantity_summary' => $quantity,
            // Compatibility fields remain numeric only when every WO uses one comparable base unit.
            'total_unit_qty' => $quantity['comparable'] ? $quantity['planned_qty'] : null,
            'completed_unit_qty' => $quantity['comparable'] ? $quantity['completed_qty'] : null,
            'in_progress_unit_qty' => $quantity['comparable'] ? $quantity['in_progress_qty'] : null,
            'exception_unit_qty' => $quantity['comparable'] ? $quantity['exception_qty'] : null,
            'production_progress' => $tasks['ratio'],
            'production_task_progress' => $tasks,
            'delivery' => $delivery,
            'kitting' => $kitting,
            'material_status' => $kitting['status'],
            'funding' => $funding,
            'funding_status' => $funding['production_funding_status'],
            'shipment_status' => $funding['shipment_funding_status'],
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
        ];
    }

    private function quantitySummary(Collection $workOrders, array $context): array
    {
        $groups = [];
        foreach ($workOrders as $workOrder) {
            $key = (int) $workOrder->base_unit_id > 0
                ? 'id:'.(int) $workOrder->base_unit_id
                : 'name:'.trim((string) $workOrder->base_unit_name_snapshot);
            $groups[$key] ??= [
                'base_unit_id' => $workOrder->base_unit_id ? (int) $workOrder->base_unit_id : null,
                'unit_name' => $workOrder->base_unit_name_snapshot ?: '未配置单位',
                'planned_qty' => 0.0, 'completed_qty' => 0.0, 'in_progress_qty' => 0.0, 'exception_qty' => 0.0,
            ];
            $id = (int) $workOrder->id;
            $groups[$key]['planned_qty'] += (float) $workOrder->target_base_qty;
            $groups[$key]['completed_qty'] += (float) ($context['completionRows'][$id] ?? 0);
            if ($workOrder->production_execution_mode_snapshot === 'quantity') {
                $operations = $context['quantityOperations'][$id] ?? collect();
                $active = $operations->whereIn('status', self::ACTIVE_TASK_STATUSES)
                    ->map(fn ($row) => max(0, (float) $row->remaining_base_qty))->max() ?? 0;
                $exception = $operations->whereIn('status', self::EXCEPTION_OPERATION_STATUSES)
                    ->map(fn ($row) => max((float) $row->scrapped_base_qty, (float) $row->remaining_base_qty))->max() ?? 0;
                $groups[$key]['in_progress_qty'] += (float) $active;
                $groups[$key]['exception_qty'] += (float) $exception;
            } else {
                $groups[$key]['in_progress_qty'] += (float) ($context['unitActive'][$id] ?? 0);
                $groups[$key]['exception_qty'] += (float) ($context['unitException'][$id] ?? 0);
            }
        }
        $groups = array_values(array_map(function (array $group): array {
            foreach (['planned_qty', 'completed_qty', 'in_progress_qty', 'exception_qty'] as $field) {
                $group[$field] = round($group[$field], 8);
            }
            return $group;
        }, $groups));
        $comparable = count($groups) <= 1;
        $only = $groups[0] ?? ['base_unit_id' => null, 'unit_name' => null, 'planned_qty' => 0, 'completed_qty' => 0, 'in_progress_qty' => 0, 'exception_qty' => 0];
        return [
            'comparable' => $comparable,
            'display_mode' => $comparable ? 'single_unit' : 'multiple_units',
            'base_unit_id' => $comparable ? $only['base_unit_id'] : null,
            'unit_name' => $comparable ? $only['unit_name'] : null,
            'planned_qty' => $comparable ? $only['planned_qty'] : null,
            'completed_qty' => $comparable ? $only['completed_qty'] : null,
            'in_progress_qty' => $comparable ? $only['in_progress_qty'] : null,
            'exception_qty' => $comparable ? $only['exception_qty'] : null,
            'groups' => $groups,
        ];
    }

    private function taskSummary(Collection $workOrders, array $context): array
    {
        $total = $completed = $active = 0;
        foreach ($workOrders as $workOrder) {
            $row = $context['taskRows'][(int) $workOrder->id] ?? null;
            $total += (int) ($row->total_count ?? 0);
            $completed += (int) ($row->completed_count ?? 0);
            $active += (int) ($row->active_count ?? 0);
        }
        return ['completed' => $completed, 'total' => $total, 'active' => $active,
            'ratio' => $total > 0 ? round($completed / $total, 4) : 0];
    }

    private function productSummary(Collection $workOrders, array $context): array
    {
        return $workOrders->groupBy(fn (WorkOrder $wo) => implode(':', [
            (int) $wo->output_item_id,
            (int) $wo->target_unit_id,
            (string) $wo->target_unit_name_snapshot,
        ]))->map(function (Collection $rows) use ($context): array {
            /** @var WorkOrder $first */
            $first = $rows->first();
            return [
                'output_item_id' => $first->output_item_id ? (int) $first->output_item_id : null,
                'item_code' => $first->outputItem?->item_code,
                'item_name' => $first->outputItem?->item_name,
                'spec' => $first->outputItem?->spec,
                'work_order_count' => $rows->count(),
                'planned_qty' => round((float) $rows->sum('target_qty'), 8),
                'unit_name' => $first->target_unit_name_snapshot,
                'planned_base_qty' => round((float) $rows->sum('target_base_qty'), 8),
                'base_unit_name' => $first->base_unit_name_snapshot,
                'completed_base_qty' => round((float) $rows->sum(
                    fn (WorkOrder $wo) => (float) ($context['completionRows'][(int) $wo->id] ?? 0)
                ), 8),
            ];
        })->values()->all();
    }

    private function deliverySummary(ProductionMasterOrder $master, array $context): array
    {
        $rows = $context['deliveryRows'][(int) $master->id] ?? collect();
        $total = $rows->count();
        $received = $rows->filter(fn ($line) => (float) $line->received_base_qty + 0.00000001 >= (float) $line->required_base_qty)->count();
        $started = $rows->filter(fn ($line) => (float) $line->received_base_qty > 0)->count();
        $dispatched = $rows->filter(fn ($line) => (float) $line->delivered_base_qty > 0)->count();
        $status = $total === 0 ? 'NOT_CREATED'
            : ($received === $total ? 'RECEIVED'
                : ($started > 0 ? 'PARTIALLY_RECEIVED' : ($dispatched > 0 ? 'IN_TRANSIT' : 'WAIT_PREPARE')));
        return ['status' => $status, 'total_line_count' => $total, 'received_line_count' => $received,
            'partially_received_line_count' => $started, 'dispatched_line_count' => $dispatched];
    }

    private function kittingSummary(Collection $workOrders, array $context): array
    {
        $ids = $workOrders->pluck('id')->map(fn ($id) => (int) $id);
        $rows = $context === [] ? collect() : $context['unitKitting']->whereIn('work_order_id', $ids)
            ->concat($context['quantityKitting']->whereIn('work_order_id', $ids));
        $total = $rows->count();
        $confirmed = $rows->whereNotNull('kitting_confirmed_at')->count();
        $status = $total === 0 ? 'NOT_REQUIRED' : ($confirmed === $total ? 'READY' : ($confirmed > 0 ? 'PARTIAL' : 'WAIT_CONFIRM'));
        return ['status' => $status, 'required_target_count' => $total, 'confirmed_target_count' => $confirmed];
    }

    private function blockers(Collection $workOrders, array $context, array $funding): array
    {
        $blockers = [];
        if (! ($funding['production_funds_satisfied'] ?? false)) {
            $blockers[] = [
                'type' => 'production_funding',
                'reason_code' => $funding['production_block_reason'] ?? 'production_funds_insufficient',
                'label' => $funding['production_block_message'] ?? '生产资金待满足',
                'count' => 1,
            ];
        }
        if ($context === []) return $blockers;
        $ids = $workOrders->pluck('id')->map(fn ($id) => (int) $id);
        $rows = $context['gateBlockers']->whereIn('work_order_id', $ids)
            ->groupBy(fn ($row) => (string) ($row->reason_code ?: 'release_gate_blocked'));
        foreach ($rows as $reasonCode => $matches) {
            $blockers[] = [
                'type' => 'release_gate',
                'reason_code' => $reasonCode,
                'label' => (string) ($matches->first()->message ?: '生产工单发布条件未满足'),
                'count' => $matches->pluck('work_order_id')->unique()->count(),
            ];
        }
        return $blockers;
    }

    private function displayStatus(Collection $workOrders, array $quantity, array $tasks): string
    {
        $exceptionTargets = collect($quantity['groups'])->sum('exception_qty');
        if ($exceptionTargets > 0) return 'EXCEPTION';
        if ($workOrders->isNotEmpty() && $workOrders->every(fn (WorkOrder $wo) => in_array($wo->status, ['COMPLETED', 'CLOSED'], true))) {
            return 'COMPLETED';
        }
        if ($tasks['active'] > 0 || $workOrders->contains(fn (WorkOrder $wo) => $wo->status === 'IN_PROGRESS')) return 'IN_PROGRESS';
        return 'WAIT_CONDITION';
    }

    private function permission(array $permissions, bool $superAdmin): void
    {
        if (! $superAdmin && ! in_array('production.work_order.view', $permissions, true)) {
            throw new WorkOrderDomainException('permission_denied', '当前用户没有查看主生产工单的权限。', 403);
        }
    }
}
