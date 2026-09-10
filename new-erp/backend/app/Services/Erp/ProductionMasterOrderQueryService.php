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
        private readonly ErpUserProjectionService $users,
        private readonly ProductionExecutionReadProjectionService $executionProjections,
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
        $page = $query->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $summaries = $this->executionProjections->summaries($page->getCollection());
        $page->setCollection($page->getCollection()->map(function (WorkOrder $workOrder) use ($summaries): array {
            $execution = $summaries[(int) $workOrder->id] ?? null;
            return [
                'id' => (int) $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'status' => $workOrder->status,
                'display_status' => $execution['display_status'] ?? 'WAIT_CONDITION',
                'display_status_label' => $execution['display_status_label'] ?? '待条件',
                'output_item' => $workOrder->outputItem ? [
                    'id' => (int) $workOrder->outputItem->id,
                    'item_code' => $workOrder->outputItem->item_code,
                    'item_name' => $workOrder->outputItem->item_name,
                    'spec' => $workOrder->outputItem->spec,
                ] : null,
                'quantity_summary' => $execution['quantity'] ?? null,
                'production_task_progress' => $execution['tasks'] ?? null,
                'production_execution_mode' => $workOrder->production_execution_mode_snapshot,
                'business_version' => (int) $workOrder->business_version,
            ];
        }));
        return $page;
    }

    public function units(int $id, array $filters, object $user, array $permissions, bool $superAdmin = false): LengthAwarePaginator
    {
        $this->show($id, $user, $permissions, $superAdmin);
        $page = ProductionUnit::query()->with([
            'workOrder:id,work_order_no,production_master_order_id,serial_policy_snapshot',
            'deviceSerial:id,serial_no,status,generation_stage,inventory_serial_id',
            'equipmentIdentity:id,production_unit_id,status,equipment_no,source_type,source_id,bound_at',
        ])
            ->whereHas('workOrder', fn (Builder $q) => $q->where('production_master_order_id', $id))
            ->when(! empty($filters['work_order_id']), fn (Builder $query) => $query->where('work_order_id', (int) $filters['work_order_id']))
            ->orderBy('work_order_id')->orderBy('sequence_no')
            ->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $outputUnitIds = DB::table('erp_production_output_records')
            ->whereIn('production_unit_id', $page->getCollection()->pluck('id'))
            ->pluck('production_unit_id')->mapWithKeys(fn ($unitId) => [(int) $unitId => true]);
        $execution = $this->unitExecutionContext($page->getCollection());
        $page->setCollection($page->getCollection()->map(
            fn (ProductionUnit $unit) => $this->unitProjection($unit, isset($outputUnitIds[(int) $unit->id]), $execution[(int) $unit->id] ?? null)
        ));
        return $page;
    }

    /**
     * A PU number is a production-unit identity, never an equipment identity.  The
     * historical device_no_snapshot column is only a denormalized copy of the
     * ProductionSerial; expose the relationship as SN so API consumers cannot
     * relabel an SN as a physical equipment asset.
     */
    private function unitProjection(ProductionUnit $unit, bool $hasOutput, ?array $execution): array
    {
        $policy = (array) ($unit->workOrder?->serial_policy_snapshot ?? []);
        $trackingMode = (string) ($policy['serial_tracking_mode'] ?? 'none');
        $stage = (string) ($policy['serial_generation_stage'] ?? 'before_finished_goods_posting');
        $stageLabels = [
            'production_unit_created' => '生产单元创建时生成',
            'routing_operation_completed' => '指定工序完成时生成',
            'before_finished_goods_posting' => '成品入库前生成',
        ];
        $serial = $unit->deviceSerial;
        if ($trackingMode === 'none') {
            $serialProjection = ['applicable' => false, 'status' => 'NOT_APPLICABLE', 'label' => '不适用', 'serial_no' => null, 'generation_stage' => null, 'generation_stage_label' => null];
        } elseif ($serial) {
            $serialProjection = [
                'applicable' => true,
                'status' => $serial->inventory_serial_id ? 'INVENTORY_BOUND' : 'GENERATED',
                'label' => $serial->inventory_serial_id ? '已入库绑定' : '已生成',
                'serial_no' => $serial->serial_no,
                'generation_stage' => $serial->generation_stage,
                'generation_stage_label' => $stageLabels[$serial->generation_stage] ?? $serial->generation_stage,
            ];
        } else {
            // A unit-created policy is due immediately.  For later policies an
            // output proves that the configured generation point has already been
            // crossed; returning a visible exception prevents a silently broken
            // PU -> ProductionSerial -> Output -> InventorySerial lineage.
            $overdue = $stage === 'production_unit_created' || $hasOutput;
            $serialProjection = [
                'applicable' => true,
                'status' => $overdue ? 'EXCEPTION_NOT_GENERATED' : 'PENDING_GENERATION',
                'label' => $overdue ? '异常未生成' : '待生成',
                'serial_no' => null,
                'generation_stage' => $stage,
                'generation_stage_label' => $stageLabels[$stage] ?? $stage,
            ];
        }

        return [
            'id' => (int) $unit->id,
            'unit_no' => $unit->unit_no,
            'work_order' => ['id' => (int) $unit->work_order_id, 'work_order_no' => $unit->workOrder?->work_order_no],
            'sequence_no' => (int) $unit->sequence_no,
            'status' => $unit->status,
            'current_operation' => ['code' => $unit->current_operation_code_snapshot, 'name' => $unit->current_operation_name_snapshot],
            'execution' => $execution,
            'serial' => $serialProjection,
            'equipment_identity' => $this->equipmentProjection($unit),
            'business_version' => (int) $unit->business_version,
        ];
    }

    private function equipmentProjection(ProductionUnit $unit): array
    {
        $identity = $unit->equipmentIdentity;
        $status = $identity?->status ?: 'NOT_APPLICABLE';
        $labels = [
            'BOUND' => '已绑定',
            'PENDING_GENERATION' => '待生成',
            'PENDING_BINDING' => '待绑定',
            'NOT_APPLICABLE' => '不适用',
        ];
        return [
            'applicable' => $status !== 'NOT_APPLICABLE',
            'status' => $status,
            'label' => $labels[$status] ?? '异常',
            'equipment_no' => $identity?->equipment_no,
            'source_type' => $identity?->source_type,
            'source_id' => $identity?->source_id ? (int) $identity->source_id : null,
            'bound_at' => optional($identity?->bound_at)->toISOString(),
        ];
    }

    /** Build PU/current-PT/kitting/handover facts in batches; these are separate
     * domains and must not be collapsed into the unit's top-level status. */
    private function unitExecutionContext(Collection $units): array
    {
        $unitIds = $units->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($unitIds === []) return [];
        $operations = DB::table('erp_production_unit_operations')->whereIn('production_unit_id', $unitIds)
            ->orderBy('sequence_no_snapshot')->get()->groupBy('production_unit_id');
        $operationIds = $operations->flatten(1)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $tasks = DB::table('erp_production_tasks')->whereIn('production_unit_operation_id', $operationIds)
            ->get(['id', 'task_no', 'production_unit_operation_id', 'assignee_user_legacy_id', 'status'])
            ->keyBy('production_unit_operation_id');
        $taskIds = $tasks->pluck('id')->map(fn ($id) => (int) $id)->all();
        $activeOwners = DB::table('erp_production_labor_sessions')->whereIn('task_id', $taskIds)
            ->where('role', 'owner')->where('status', 'ACTIVE')->pluck('employee_legacy_id', 'task_id');
        $handovers = DB::table('erp_production_operation_handovers')->where('target_target_type', 'unit_operation')
            ->whereIn('target_target_id', $operationIds)->orderByDesc('id')->get()
            ->unique('target_target_id')->keyBy('target_target_id');
        $requirements = DB::table('erp_production_target_material_requirements')->where('target_type', 'unit_operation')
            ->whereIn('target_id', $operationIds)->selectRaw('target_id, SUM(required_base_qty) required_qty, SUM(satisfied_base_qty) satisfied_qty')
            ->groupBy('target_id')->get()->keyBy('target_id');
        $people = $this->users->many($tasks->pluck('assignee_user_legacy_id')->filter()->all());

        $result = [];
        foreach ($units as $unit) {
            $rows = $operations[(int) $unit->id] ?? collect();
            $current = $unit->current_routing_operation_id
                ? $rows->first(fn ($row) => (int) $row->routing_operation_id_snapshot === (int) $unit->current_routing_operation_id)
                : null;
            $current = $current ?: $rows->first(fn ($row) => $row->status !== 'COMPLETED') ?: $rows->last();
            if (! $current) { $result[(int) $unit->id] = null; continue; }
            $task = $tasks[(int) $current->id] ?? null;
            $ownerId = (int) ($task->assignee_user_legacy_id ?? 0);
            $inProgressIntegrity = $current->status !== 'IN_PROGRESS'
                ? ['valid' => true, 'reason_code' => null]
                : ($ownerId > 0 && (int) ($activeOwners[(int) ($task->id ?? 0)] ?? 0) === $ownerId
                    ? ['valid' => true, 'reason_code' => null]
                    : ['valid' => false, 'reason_code' => 'in_progress_owner_labor_missing']);
            $handover = $handovers[(int) $current->id] ?? null;
            $handoverStatus = (int) $current->sequence_no_snapshot === 1 ? 'NOT_REQUIRED'
                : ($handover?->status === 'RECEIVED' ? 'RECEIVED'
                    : (in_array($handover?->status, ['REJECTED'], true) ? 'EXCEPTION' : 'WAIT_RECEIVE'));
            $required = (bool) $current->kitting_required;
            $requirement = $requirements[(int) $current->id] ?? null;
            $kittingStatus = ! $required ? 'NOT_REQUIRED'
                : ($current->kitting_confirmed_at ? 'CONFIRMED'
                    : ((float) ($requirement->satisfied_qty ?? 0) > 0 ? 'PARTIAL' : 'NOT_CONFIRMED'));
            $result[(int) $unit->id] = [
                'unit_status' => ['status' => $unit->status, 'label' => $this->unitStatusLabel((string) $unit->status)],
                'current_operation' => ['id' => (int) $current->id, 'code' => $current->operation_code_snapshot,
                    'name' => $current->operation_name_snapshot, 'sequence' => (int) $current->sequence_no_snapshot,
                    'total' => $rows->count(), 'status' => $current->status, 'label' => $this->operationStatusLabel((string) $current->status)],
                'current_task' => ['id' => $task?->id ? (int) $task->id : null, 'task_no' => $task?->task_no,
                    'status' => $task?->status, 'owner' => $people[$ownerId] ?? null,
                    'owner_active_labor' => (int) ($activeOwners[(int) ($task->id ?? 0)] ?? 0) === $ownerId,
                    'execution_integrity' => $inProgressIntegrity],
                'kitting' => ['status' => $kittingStatus, 'label' => ['NOT_REQUIRED' => '不需要', 'CONFIRMED' => '已齐套', 'PARTIAL' => '部分满足', 'NOT_CONFIRMED' => '未齐套'][$kittingStatus]],
                'previous_handover' => ['status' => $handoverStatus, 'label' => ['NOT_REQUIRED' => '不需要', 'RECEIVED' => '已接收', 'WAIT_RECEIVE' => '待接收', 'EXCEPTION' => '异常'][$handoverStatus]],
            ];
        }
        return $result;
    }

    private function unitStatusLabel(string $status): string
    {
        return match ($status) { 'WAITING' => '待生产', 'PROCESSING', 'IN_PROGRESS' => '生产中', 'COMPLETED' => '已完成', default => '异常' };
    }

    private function operationStatusLabel(string $status): string
    {
        return match ($status) {
            'WAIT_CLAIM' => '待接单', 'WAIT_PREVIOUS', 'WAIT_PREDECESSOR' => '待交接', 'WAIT_MATERIAL' => '待齐套',
            'IN_PROGRESS' => '加工中', 'PAUSED' => '暂停', 'WAIT_QUALITY' => '待质检', 'WAIT_WAREHOUSE' => '待入库',
            'COMPLETED' => '已完成', 'READY' => '待开工', default => '异常',
        };
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

        $executionSummaries = $this->executionProjections->summaries($workOrders);
        $masterIds = $masters->pluck('id')->map(fn ($id) => (int) $id)->all();
        $salesOrderIds = $masters->pluck('sales_order_id')->map(fn ($id) => (int) $id)->filter()->all();

        $shipmentRows = DB::table('erp_sales_order_lines')->whereIn('sales_order_id', $salesOrderIds)
            ->where('commercial_role', 'sale')->where('line_type', 'physical')
            ->where('line_status', '<>', 'cancelled')
            ->selectRaw('sales_order_id, unit_id, unit_name_snapshot,
                SUM(GREATEST(order_qty - cancelled_qty, 0)) total_qty,
                SUM(LEAST(shipped_qty, GREATEST(order_qty - cancelled_qty, 0))) shipped_qty')
            ->groupBy('sales_order_id', 'unit_id', 'unit_name_snapshot')->get()->groupBy('sales_order_id');
        $attachmentRows = DB::table('erp_sales_order_attachments')->whereIn('sales_order_id', $salesOrderIds)
            ->where('status', 'active')->whereNull('deleted_at')->orderByDesc('uploaded_at')->orderByDesc('id')
            ->get(['id', 'sales_order_id', 'sales_order_line_id', 'attachment_scope', 'attachment_type',
                'original_name', 'mime_type', 'file_size', 'uploaded_by', 'uploaded_by_legacy_id', 'uploaded_at'])
            ->groupBy('sales_order_id');
        $remarkRows = DB::table('erp_sales_order_logs')->whereIn('sales_order_id', $salesOrderIds)
            ->whereIn('action', ['remark_create', 'remark_update', 'order_remark_update'])
            ->whereNotNull('content')->where('content', '<>', '')->orderBy('created_at')->orderBy('id')
            ->get(['id', 'sales_order_id', 'action', 'operator', 'content', 'created_at'])->groupBy('sales_order_id');

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
            ->join('erp_work_orders as wo', 'wo.id', '=', 'line.work_order_id')
            ->join('erp_work_order_material_requirements as requirement', 'requirement.id', '=', 'line.material_requirement_id')
            ->whereIn('prep.production_master_order_id', $masterIds)
            ->get(['prep.production_master_order_id', 'line.id', 'line.work_order_id', 'wo.work_order_no',
                'requirement.component_item_name_snapshot', 'line.required_base_qty', 'line.prepared_base_qty',
                'line.delivered_base_qty', 'line.received_base_qty', 'line.status', 'line.planned_start_at',
                'line.delivery_lead_minutes', 'line.delivery_trigger_status', 'line.delivery_released_at'])
            ->groupBy('production_master_order_id');
        $waveRows = DB::table('erp_material_delivery_waves')->whereIn('production_master_order_id', $masterIds)
            ->orderByDesc('id')->get()->groupBy('production_master_order_id');
        $waveIds = $waveRows->flatten(1)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $deliveryTaskRows = $waveIds === [] ? collect() : DB::table('erp_material_delivery_tasks')
            ->whereIn('delivery_wave_id', $waveIds)->orderBy('id')->get()->groupBy('delivery_wave_id');
        $deliveryTaskIds = $deliveryTaskRows->flatten(1)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $deliveryTaskLineCounts = $deliveryTaskIds === [] ? collect() : DB::table('erp_material_delivery_task_lines')
            ->whereIn('delivery_task_id', $deliveryTaskIds)
            ->selectRaw('delivery_task_id, COUNT(*) line_count, MIN(material_delivery_id) first_delivery_id')
            ->groupBy('delivery_task_id')->get()->keyBy('delivery_task_id');
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
            'deliveryRows', 'unitKitting', 'quantityKitting', 'gateBlockers', 'executionSummaries',
            'shipmentRows', 'attachmentRows', 'remarkRows', 'waveRows', 'deliveryTaskRows', 'deliveryTaskLineCounts'
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
        $salespersonId = (int) ($master->salesOrder?->sales_user_legacy_id ?: $master->salesperson_legacy_id);
        $salesperson = $this->users->one($salespersonId);
        $canViewAttachments = in_array('sales_order.view_attachment', $permissions, true);

        return [
            'id' => (int) $master->id,
            'master_order_no' => $master->master_order_no,
            'sales_order_id' => (int) $master->sales_order_id,
            'sales_order_no_snapshot' => $master->sales_order_no_snapshot,
            'customer_snapshot' => (array) $master->customer_snapshot,
            'order_remark_snapshot' => $master->order_remark_snapshot,
            'required_delivery_date_snapshot' => optional($master->required_delivery_date_snapshot)->format('Y-m-d'),
            'status' => $master->status,
            'business_version' => (int) $master->business_version,
            'created_at' => optional($master->created_at)->toISOString(),
            'updated_at' => optional($master->updated_at)->toISOString(),
            // Prefer the live sales-user projection.  The snapshot remains only
            // as an historical fallback and is never replaced with a department.
            'salesperson' => $salesperson ?: ($master->salesperson_name_snapshot ? [
                'user_id' => $salespersonId ?: null,
                'display_name' => $master->salesperson_name_snapshot,
                'department_name' => null,
                'status' => 'historical_snapshot',
            ] : null),
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
            'delivery_overview' => $this->deliveryOverview($master, $context, $kitting),
            'kitting' => $kitting,
            'material_status' => $kitting['status'],
            'funding' => $funding,
            'shipment' => $this->shipmentSummary($master, $context),
            'attachment_access' => ['can_view' => $canViewAttachments],
            'attachments' => $canViewAttachments ? $this->attachments($master, $context) : [],
            'remarks' => $this->remarks($master, $context, $salesperson),
            'funding_status' => $funding['production_funding_status'],
            'shipment_status' => $funding['shipment_funding_status'],
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
        ];
    }

    private function shipmentSummary(ProductionMasterOrder $master, array $context): array
    {
        $rows = $context['shipmentRows'][(int) $master->sales_order_id] ?? collect();
        $groups = $rows->map(fn ($row): array => [
            'unit_id' => $row->unit_id ? (int) $row->unit_id : null,
            'unit_name' => $row->unit_name_snapshot,
            'total_qty' => round((float) $row->total_qty, 8),
            'shipped_qty' => round((float) $row->shipped_qty, 8),
        ])->values()->all();
        $comparable = count($groups) <= 1;
        $only = $groups[0] ?? ['unit_id' => null, 'unit_name' => null, 'total_qty' => 0, 'shipped_qty' => 0];
        return [
            'comparable' => $comparable,
            'display_mode' => $comparable ? 'single_unit' : 'multiple_units',
            'unit_id' => $comparable ? $only['unit_id'] : null,
            'unit_name' => $comparable ? $only['unit_name'] : null,
            'total_qty' => $comparable ? $only['total_qty'] : null,
            'shipped_qty' => $comparable ? $only['shipped_qty'] : null,
            'groups' => $groups,
        ];
    }

    private function attachments(ProductionMasterOrder $master, array $context): array
    {
        $rows = $context['attachmentRows'][(int) $master->sales_order_id] ?? collect();
        $previewable = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        return $rows->map(fn ($row): array => [
            'id' => (int) $row->id,
            'sales_order_line_id' => $row->sales_order_line_id ? (int) $row->sales_order_line_id : null,
            'scope' => $row->attachment_scope,
            'attachment_type' => $row->attachment_type,
            'name' => $row->original_name,
            'mime_type' => $row->mime_type,
            'file_size' => (int) $row->file_size,
            'uploader' => $row->uploaded_by,
            'uploaded_at' => $row->uploaded_at ? \Illuminate\Support\Carbon::parse($row->uploaded_at)->toISOString() : null,
            'can_preview' => in_array(strtolower((string) $row->mime_type), $previewable, true),
            'can_download' => true,
            'source' => $row->sales_order_line_id ? 'sales_order_line' : 'sales_order',
        ])->values()->all();
    }

    private function remarks(ProductionMasterOrder $master, array $context, ?array $salesperson): array
    {
        $rows = [];
        $seen = [];
        $snapshot = trim((string) $master->order_remark_snapshot);
        if ($snapshot !== '') {
            $seen[$snapshot] = true;
            $rows[] = [
                'id' => 'mwo-snapshot-'.$master->id,
                'source' => 'sales_order_snapshot',
                'source_label' => '销售订单',
                'author' => $salesperson['display_name'] ?? $master->salesperson_name_snapshot,
                'content' => $snapshot,
                'created_at' => optional($master->created_at)->toISOString(),
                'immutable_snapshot' => true,
            ];
        }
        foreach ($context['remarkRows'][(int) $master->sales_order_id] ?? collect() as $row) {
            $content = trim((string) $row->content);
            if ($content === '' || isset($seen[$content])) continue;
            $seen[$content] = true;
            $rows[] = [
                'id' => 'sales-log-'.$row->id,
                'source' => 'sales_order_log',
                'source_label' => '销售订单',
                'action' => $row->action,
                'author' => $row->operator,
                'content' => $content,
                'created_at' => $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at)->toISOString() : null,
                'immutable_snapshot' => false,
            ];
        }
        return $rows;
    }

    private function deliveryOverview(ProductionMasterOrder $master, array $context, array $kitting): array
    {
        $lines = $context['deliveryRows'][(int) $master->id] ?? collect();
        $complete = fn ($line, string $field): bool => (float) $line->{$field} + 0.00000001 >= (float) $line->required_base_qty;
        $alerts = [];
        foreach ($lines as $line) {
            $subject = $line->component_item_name_snapshot ?: $line->work_order_no;
            if ($line->delivery_trigger_status === 'WAIT_CONFIGURATION') {
                $alerts[] = ['id' => (int) $line->id, 'type' => 'WAIT_CONFIGURATION', 'text' => "{$subject}：配送触发时间未配置"];
            } elseif ($line->delivery_trigger_status === 'READY_TO_RELEASE') {
                $alerts[] = ['id' => (int) $line->id, 'type' => 'READY_TO_RELEASE', 'text' => "{$subject}：已到配送释放时间"];
            }
        }

        $activeWaves = [];
        foreach ($context['waveRows'][(int) $master->id] ?? collect() as $wave) {
            $tasks = $context['deliveryTaskRows'][(int) $wave->id] ?? collect();
            $active = $tasks->whereNotIn('status', ['DONE', 'CANCELLED']);
            if ($active->isEmpty()) continue;
            $destinations = $active->flatMap(fn ($task) => array_filter([
                $task->location_code, $task->work_center_code, $task->production_zone_code, $task->zone_pool_code,
            ]))->unique()->values();
            $lineCount = $active->sum(fn ($task) => (int) ($context['deliveryTaskLineCounts'][(int) $task->id]->line_count ?? 0));
            $firstDeliveryId = $active->map(fn ($task) => $context['deliveryTaskLineCounts'][(int) $task->id]->first_delivery_id ?? null)->filter()->first();
            $statuses = $active->pluck('status');
            $label = $statuses->contains('EXCEPTION') ? '异常'
                : ($statuses->intersect(['DELIVERING', 'PARTIAL_DONE'])->isNotEmpty() ? '配送中'
                    : ($statuses->contains('PICKED_UP') ? '已取货'
                        : ($statuses->contains('CLAIMED') ? '已接单' : '待接单')));
            $activeWaves[] = [
                'id' => (int) $wave->id,
                'wave_no' => $wave->wave_no,
                'first_delivery_id' => $firstDeliveryId ? (int) $firstDeliveryId : null,
                'destination' => $destinations->isEmpty() ? '未配置目的地' : $destinations->join(' / '),
                'task_count' => $active->count(),
                'material_line_count' => $lineCount,
                'summary_text' => $active->count().' 个配送任务 / '.$lineCount.' 项物料',
                'status' => $label,
            ];
        }

        $upcoming = $lines->whereIn('delivery_trigger_status', ['WAIT_CONFIGURATION', 'WAIT_SCHEDULE', 'READY_TO_RELEASE'])
            ->groupBy(fn ($line) => implode('|', [$line->work_order_id, $line->delivery_trigger_status, (string) $line->planned_start_at]))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $releaseAt = null;
                if ($first->planned_start_at !== null && $first->delivery_lead_minutes !== null) {
                    $releaseAt = \Illuminate\Support\Carbon::parse($first->planned_start_at)->subMinutes((int) $first->delivery_lead_minutes);
                }
                $expected = match ($first->delivery_trigger_status) {
                    'WAIT_CONFIGURATION' => '待配置触发时间',
                    'READY_TO_RELEASE' => '已到释放时间',
                    default => $releaseAt ? '预计 '.$releaseAt->format('m-d H:i').' 释放' : '待排程',
                };
                return [
                    'id' => (int) $first->id,
                    'work_order_id' => (int) $first->work_order_id,
                    'stage_name' => '首工序 · '.$first->work_order_no,
                    'material_count' => $rows->count(),
                    'material_count_text' => $rows->count().' 项物料',
                    'trigger_status' => $first->delivery_trigger_status,
                    'expected_release_at' => $releaseAt?->toISOString(),
                    'expected_release_text' => $expected,
                ];
            })->values()->all();

        return [
            'total_required_line_count' => $lines->count(),
            'prepared_line_count' => $lines->filter(fn ($line) => $complete($line, 'prepared_base_qty'))->count(),
            'delivered_line_count' => $lines->filter(fn ($line) => $complete($line, 'delivered_base_qty'))->count(),
            'received_line_count' => $lines->filter(fn ($line) => $complete($line, 'received_base_qty'))->count(),
            'waiting_kitting_operation_count' => max(0, (int) ($kitting['required_target_count'] ?? 0) - (int) ($kitting['confirmed_target_count'] ?? 0)),
            'pending_alerts' => $alerts,
            'active_deliveries' => $activeWaves,
            'upcoming_deliveries' => $upcoming,
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
                'planned_qty' => 0.0, 'completed_qty' => 0.0, 'in_progress_qty' => 0.0,
                'waiting_qty' => 0.0, 'exception_qty' => 0.0,
            ];
            $id = (int) $workOrder->id;
            $quantity = (array) (($context['executionSummaries'][$id] ?? [])['quantity'] ?? []);
            $groups[$key]['planned_qty'] += (float) ($quantity['planned_base_qty'] ?? $workOrder->target_base_qty);
            $groups[$key]['completed_qty'] += (float) ($quantity['completed_base_qty'] ?? 0);
            $groups[$key]['in_progress_qty'] += (float) ($quantity['in_progress_base_qty'] ?? 0);
            $groups[$key]['waiting_qty'] += (float) ($quantity['waiting_base_qty'] ?? 0);
            $groups[$key]['exception_qty'] += (float) ($quantity['exception_base_qty'] ?? 0);
        }
        $groups = array_values(array_map(function (array $group): array {
            foreach (['planned_qty', 'completed_qty', 'in_progress_qty', 'waiting_qty', 'exception_qty'] as $field) {
                $group[$field] = round($group[$field], 8);
            }
            return $group;
        }, $groups));
        $comparable = count($groups) <= 1;
        $only = $groups[0] ?? ['base_unit_id' => null, 'unit_name' => null, 'planned_qty' => 0,
            'completed_qty' => 0, 'in_progress_qty' => 0, 'waiting_qty' => 0, 'exception_qty' => 0];
        return [
            'comparable' => $comparable,
            'display_mode' => $comparable ? 'single_unit' : 'multiple_units',
            'base_unit_id' => $comparable ? $only['base_unit_id'] : null,
            'unit_name' => $comparable ? $only['unit_name'] : null,
            'planned_qty' => $comparable ? $only['planned_qty'] : null,
            'completed_qty' => $comparable ? $only['completed_qty'] : null,
            'in_progress_qty' => $comparable ? $only['in_progress_qty'] : null,
            'waiting_qty' => $comparable ? $only['waiting_qty'] : null,
            'exception_qty' => $comparable ? $only['exception_qty'] : null,
            'groups' => $groups,
        ];
    }

    private function taskSummary(Collection $workOrders, array $context): array
    {
        $total = $completed = $active = 0;
        foreach ($workOrders as $workOrder) {
            $row = (array) (($context['executionSummaries'][(int) $workOrder->id] ?? [])['tasks'] ?? []);
            $total += (int) ($row['total'] ?? 0);
            $completed += (int) ($row['completed'] ?? 0);
            $active += (int) ($row['active'] ?? 0);
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
                    fn (WorkOrder $wo) => (float) (($context['executionSummaries'][(int) $wo->id]['quantity']['completed_base_qty'] ?? 0))
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
            'partially_received_line_count' => $started, 'dispatched_line_count' => $dispatched,
            'statistic_subject' => '正式物料配送需求项',
            'receipt_progress_label' => "已签收 {$received}/{$total} 项"];
    }

    private function kittingSummary(Collection $workOrders, array $context): array
    {
        $ids = $workOrders->pluck('id')->map(fn ($id) => (int) $id);
        $rows = $context === [] ? collect() : $context['unitKitting']->whereIn('work_order_id', $ids)
            ->concat($context['quantityKitting']->whereIn('work_order_id', $ids));
        $total = $rows->count();
        $confirmed = $rows->whereNotNull('kitting_confirmed_at')->count();
        $status = $total === 0 ? 'NOT_REQUIRED' : ($confirmed === $total ? 'READY' : ($confirmed > 0 ? 'PARTIAL' : 'WAIT_CONFIRM'));
        return ['status' => $status, 'required_target_count' => $total, 'confirmed_target_count' => $confirmed,
            'statistic_subject' => '需齐套工序', 'progress_label' => "齐套工序 {$confirmed}/{$total}"];
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
