<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionOutputRecord;
use App\Models\Erp\ProductionUnit;
use App\Models\Erp\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ProductionUnitTraceService
{
    public function __construct(
        private readonly ProductionDataScopeResolver $scopeResolver,
        private readonly ErpUserProjectionService $users,
    ) {}

    public function units(int $workOrderId, array $filters, object $user, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->permission($permissions, 'production.unit.view', $superAdmin);
        $this->visible($workOrderId, $user, $permissions, $superAdmin, 'production.unit.view');
        $page = ProductionUnit::query()->with(['deviceSerial', 'equipmentIdentity', 'workOrder.outputItem'])
            ->where('work_order_id', $workOrderId)
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderBy('sequence_no')
            ->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $page->setCollection($page->getCollection()->map(fn (ProductionUnit $unit) => $this->projection($unit)));
        return $page;
    }

    public function unit(int $id, object $user, array $permissions, bool $superAdmin): array
    {
        $this->permission($permissions, 'production.unit.view', $superAdmin);
        $unit = ProductionUnit::with(['deviceSerial', 'equipmentIdentity', 'workOrder.outputItem'])->find($id);
        if (! $unit) $this->fail('production_unit_not_found', '生产单元不存在。', 404);
        $this->visible($unit->work_order_id, $user, $permissions, $superAdmin, 'production.unit.view');
        return $this->projection($unit) + ['operations' => $this->timeline($unit)];
    }

    public function trace(string $keyword, object $user, array $permissions, bool $superAdmin): array
    {
        $this->permission($permissions, 'production.trace.view', $superAdmin);
        $keyword = trim($keyword);
        if ($keyword === '') $this->fail('trace_keyword_required', '请输入生产序列号、生产单元号、设备编号或半成品编号。');
        $unit = ProductionUnit::query()->with(['deviceSerial', 'equipmentIdentity', 'workOrder.outputItem'])
            ->where(function ($query) use ($keyword): void {
                $query->where('unit_no', $keyword)
                    ->orWhere('device_no_snapshot', $keyword)
                    ->orWhereHas('deviceSerial', fn ($serial) => $serial->where('serial_no', $keyword))
                    ->orWhereHas('equipmentIdentity', fn ($identity) => $identity->where('equipment_no', $keyword));
            })->first();
        if (! $unit) {
            $output = ProductionOutputRecord::where('serial_no_snapshot', $keyword)->first();
            $unit = $output?->production_unit_id
                ? ProductionUnit::with(['deviceSerial', 'equipmentIdentity', 'workOrder.outputItem'])->find($output->production_unit_id)
                : null;
        }
        if (! $unit) $this->fail('trace_not_found', '未找到该编号对应的生产链路。', 404);
        $this->visible($unit->work_order_id, $user, $permissions, $superAdmin, 'production.trace.view');
        $operationIds = $unit->operations()->pluck('id');
        $outputIds = DB::table('erp_production_output_records')->where('production_unit_id', $unit->id)->pluck('id');
        return $this->projection($unit) + [
            'operations' => $this->timeline($unit),
            'outputs' => DB::table('erp_production_output_records')->where('production_unit_id', $unit->id)->orderBy('produced_at')->get()->map(fn ($row) => (array) $row)->all(),
            'handovers' => DB::table('erp_production_operation_handovers')->where('work_order_id', $unit->work_order_id)->whereIn('source_target_id', $operationIds)->orderBy('handed_over_at')->get()->map(fn ($row) => (array) $row)->all(),
            'lineage' => DB::table('erp_production_output_lineage_links as lineage')
                ->join('erp_production_output_records as parent', 'parent.id', '=', 'lineage.parent_output_record_id')
                ->join('erp_production_output_records as child', 'child.id', '=', 'lineage.child_output_record_id')
                ->where(function ($query) use ($outputIds): void {
                    $query->whereIn('lineage.parent_output_record_id', $outputIds)->orWhereIn('lineage.child_output_record_id', $outputIds);
                })->orderBy('lineage.id')->get([
                    'lineage.*', 'parent.output_no as parent_output_no', 'parent.serial_no_snapshot as parent_serial_no',
                    'child.output_no as child_output_no', 'child.serial_no_snapshot as child_serial_no',
                ])->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function timeline(ProductionUnit $unit): array
    {
        $operations = $unit->operations()->get();
        $tasks = DB::table('erp_production_tasks')->whereIn('production_unit_operation_id', $operations->pluck('id'))->get()->keyBy('production_unit_operation_id');
        $people = $this->users->many($tasks->pluck('assignee_user_legacy_id')->filter()->all());
        return $operations->map(function ($operation) use ($tasks, $people): array {
            $task = $tasks[(int) $operation->id] ?? null;
            $ownerId = (int) ($task->assignee_user_legacy_id ?? 0);
            $ownerActive = $task && $ownerId > 0 && DB::table('erp_production_labor_sessions')->where('task_id', $task->id)
                ->where('employee_legacy_id', $ownerId)->where('role', 'owner')->where('status', 'ACTIVE')->exists();
            $expectedEnd = null;
            if ($operation->started_at && $operation->standard_minutes_snapshot !== null && $operation->status !== 'COMPLETED') {
                $expectedEnd = $operation->started_at->copy()->addMinutes((int) ceil((float) $operation->standard_minutes_snapshot));
            }
            $elapsedSeconds = $operation->started_at && ! $operation->completed_at ? max(0, $operation->started_at->diffInSeconds(now())) : null;
            return [
                'id' => (int) $operation->id,
                'sequence' => (int) $operation->sequence_no_snapshot,
                'operation_code' => $operation->operation_code_snapshot,
                'operation_name' => $operation->operation_name_snapshot,
                'status' => $operation->status,
                'status_label' => $this->operationStatusLabel((string) $operation->status),
                'claimed_at' => optional($operation->claimed_at)->toISOString(),
                'kitting_confirmed_at' => optional($operation->kitting_confirmed_at)->toISOString(),
                'started_at' => optional($operation->started_at)->toISOString(),
                'completed_at' => optional($operation->completed_at)->toISOString(),
                'planned_start_at' => null,
                'planned_end_at' => $expectedEnd?->toISOString(),
                'elapsed_seconds' => $elapsedSeconds,
                'standard_minutes' => $operation->standard_minutes_snapshot !== null ? (float) $operation->standard_minutes_snapshot : null,
                'actual_labor_minutes' => (float) $operation->actual_labor_minutes,
                'task' => [
                    'id' => $task?->id ? (int) $task->id : null,
                    'task_no' => $task?->task_no,
                    'status' => $task?->status,
                    'owner' => $people[$ownerId] ?? null,
                    'owner_active_labor' => $ownerActive,
                    'execution_integrity' => [
                        'valid' => $operation->status !== 'IN_PROGRESS' || ($ownerId > 0 && $ownerActive),
                        'reason_code' => $operation->status === 'IN_PROGRESS' && ! ($ownerId > 0 && $ownerActive) ? 'in_progress_owner_labor_missing' : null,
                    ],
                ],
                'labor_sessions' => DB::table('erp_production_labor_sessions')->where('target_type', 'unit_operation')->where('target_id', $operation->id)->orderBy('started_at')->get()->map(fn ($row) => (array) $row)->all(),
                'quality_inspections' => DB::table('erp_production_quality_inspections as inspection')
                    ->join('erp_production_output_records as output', 'output.id', '=', 'inspection.output_record_id')
                    ->where('output.source_target_type', 'unit_operation')->where('output.source_target_id', $operation->id)
                    ->select('inspection.*')->get()->map(fn ($row) => (array) $row)->all(),
            ];
        })->all();
    }

    private function projection(ProductionUnit $unit): array
    {
        $execution = $this->execution($unit);
        return [
            'id' => (int) $unit->id,
            'unit_no' => $unit->unit_no,
            'work_order' => [
                'id' => (int) $unit->work_order_id,
                'work_order_no' => $unit->workOrder?->work_order_no,
                'production_master_order_id' => $unit->workOrder?->production_master_order_id ? (int) $unit->workOrder->production_master_order_id : null,
            ],
            'sequence_no' => (int) $unit->sequence_no,
            'status' => $unit->status,
            'status_label' => $this->unitStatusLabel((string) $unit->status),
            'serial' => $this->serialProjection($unit),
            'equipment_identity' => $this->equipmentProjection($unit),
            'product' => $unit->workOrder?->outputItem ? [
                'item_id' => (int) $unit->workOrder->outputItem->id,
                'item_name' => $unit->workOrder->outputItem->item_name,
                'item_code' => $unit->workOrder->outputItem->item_code,
                'spec' => $unit->workOrder->outputItem->spec,
                'unit_name' => $unit->workOrder->target_unit_name_snapshot,
            ] : null,
            'current_operation' => $execution['current_operation'],
            'execution' => $execution,
            'business_version' => (int) $unit->business_version,
        ];
    }

    private function execution(ProductionUnit $unit): array
    {
        $operations = $unit->operations()->get();
        $current = $unit->current_routing_operation_id
            ? $operations->first(fn ($row) => (int) $row->routing_operation_id_snapshot === (int) $unit->current_routing_operation_id)
            : null;
        $current = $current ?: $operations->first(fn ($row) => $row->status !== 'COMPLETED') ?: $operations->last();
        if (! $current) return [
            'unit_status' => ['status' => $unit->status, 'label' => $this->unitStatusLabel((string) $unit->status)],
            'current_operation' => null, 'current_task' => null,
            'kitting' => ['status' => 'NOT_REQUIRED', 'label' => '不需要'],
            'previous_handover' => ['status' => 'NOT_REQUIRED', 'label' => '不需要'],
        ];
        $task = DB::table('erp_production_tasks')->where('production_unit_operation_id', $current->id)->first();
        $ownerId = (int) ($task->assignee_user_legacy_id ?? 0);
        $ownerActive = $task && $ownerId > 0 && DB::table('erp_production_labor_sessions')->where('task_id', $task->id)
            ->where('employee_legacy_id', $ownerId)->where('role', 'owner')->where('status', 'ACTIVE')->exists();
        $handover = DB::table('erp_production_operation_handovers')->where('target_target_type', 'unit_operation')
            ->where('target_target_id', $current->id)->orderByDesc('id')->first();
        $handoverStatus = (int) $current->sequence_no_snapshot === 1 ? 'NOT_REQUIRED'
            : ($handover?->status === 'RECEIVED' ? 'RECEIVED' : ($handover?->status === 'REJECTED' ? 'EXCEPTION' : 'WAIT_RECEIVE'));
        $requirement = DB::table('erp_production_target_material_requirements')->where('target_type', 'unit_operation')->where('target_id', $current->id)
            ->selectRaw('SUM(required_base_qty) required_qty, SUM(satisfied_base_qty) satisfied_qty')->first();
        $kittingStatus = ! $current->kitting_required ? 'NOT_REQUIRED'
            : ($current->kitting_confirmed_at ? 'CONFIRMED' : ((float) ($requirement->satisfied_qty ?? 0) > 0 ? 'PARTIAL' : 'NOT_CONFIRMED'));
        return [
            'unit_status' => ['status' => $unit->status, 'label' => $this->unitStatusLabel((string) $unit->status)],
            'current_operation' => [
                'id' => (int) $current->id, 'code' => $current->operation_code_snapshot, 'name' => $current->operation_name_snapshot,
                'sequence' => (int) $current->sequence_no_snapshot, 'total' => $operations->count(), 'status' => $current->status,
                'label' => $this->operationStatusLabel((string) $current->status),
            ],
            'current_task' => [
                'id' => $task?->id ? (int) $task->id : null, 'task_no' => $task?->task_no, 'status' => $task?->status,
                'owner' => $this->users->one($ownerId), 'owner_active_labor' => $ownerActive,
                'execution_integrity' => [
                    'valid' => $current->status !== 'IN_PROGRESS' || ($ownerId > 0 && $ownerActive),
                    'reason_code' => $current->status === 'IN_PROGRESS' && ! ($ownerId > 0 && $ownerActive) ? 'in_progress_owner_labor_missing' : null,
                ],
            ],
            'kitting' => ['status' => $kittingStatus, 'label' => [
                'NOT_REQUIRED' => '不需要', 'CONFIRMED' => '已齐套', 'PARTIAL' => '部分满足', 'NOT_CONFIRMED' => '未齐套',
            ][$kittingStatus]],
            'previous_handover' => ['status' => $handoverStatus, 'label' => [
                'NOT_REQUIRED' => '不需要', 'RECEIVED' => '已接收', 'WAIT_RECEIVE' => '待接收', 'EXCEPTION' => '异常',
            ][$handoverStatus]],
        ];
    }

    private function serialProjection(ProductionUnit $unit): array
    {
        $policy = (array) ($unit->workOrder?->serial_policy_snapshot ?? []);
        $mode = (string) ($policy['serial_tracking_mode'] ?? 'none');
        $stage = (string) ($policy['serial_generation_stage'] ?? 'before_finished_goods_posting');
        $labels = ['production_unit_created' => '生产单元创建时生成', 'routing_operation_completed' => '指定工序完成时生成', 'before_finished_goods_posting' => '成品入库前生成'];
        if ($mode === 'none') return ['applicable' => false, 'status' => 'NOT_APPLICABLE', 'label' => '不适用', 'serial_no' => null, 'generation_stage' => null, 'generation_stage_label' => null, 'inventory_serial_id' => null];
        $serial = $unit->deviceSerial;
        if ($serial) return [
            'applicable' => true, 'status' => $serial->inventory_serial_id ? 'INVENTORY_BOUND' : 'GENERATED',
            'label' => $serial->inventory_serial_id ? '已入库绑定' : '已生成', 'serial_no' => $serial->serial_no,
            'generation_stage' => $serial->generation_stage, 'generation_stage_label' => $labels[$serial->generation_stage] ?? $serial->generation_stage,
            'inventory_serial_id' => $serial->inventory_serial_id ? (int) $serial->inventory_serial_id : null,
        ];
        $overdue = $stage === 'production_unit_created' || DB::table('erp_production_output_records')->where('production_unit_id', $unit->id)->exists();
        return [
            'applicable' => true, 'status' => $overdue ? 'EXCEPTION_NOT_GENERATED' : 'PENDING_GENERATION',
            'label' => $overdue ? '异常未生成' : '待生成', 'serial_no' => null, 'generation_stage' => $stage,
            'generation_stage_label' => $labels[$stage] ?? $stage, 'inventory_serial_id' => null,
        ];
    }

    private function equipmentProjection(ProductionUnit $unit): array
    {
        $identity = $unit->equipmentIdentity;
        $status = $identity?->status ?: 'NOT_APPLICABLE';
        return [
            'applicable' => $status !== 'NOT_APPLICABLE', 'status' => $status,
            'label' => ['BOUND' => '已绑定', 'PENDING_GENERATION' => '待生成', 'PENDING_BINDING' => '待绑定', 'NOT_APPLICABLE' => '不适用'][$status] ?? '异常',
            'equipment_no' => $identity?->equipment_no, 'source_type' => $identity?->source_type,
            'source_id' => $identity?->source_id ? (int) $identity->source_id : null, 'bound_at' => optional($identity?->bound_at)->toISOString(),
        ];
    }

    private function unitStatusLabel(string $status): string
    {
        return match ($status) { 'WAITING' => '待生产', 'PROCESSING', 'IN_PROGRESS' => '生产中', 'COMPLETED' => '已完成', default => '异常' };
    }

    private function operationStatusLabel(string $status): string
    {
        return match ($status) {
            'WAIT_CLAIM' => '待接单', 'WAIT_PREVIOUS', 'WAIT_PREDECESSOR', 'WAIT_HANDOVER' => '待前序交接',
            'WAIT_MATERIAL' => '待齐套', 'IN_PROGRESS' => '加工中', 'PAUSED' => '暂停', 'WAIT_QUALITY' => '待质检',
            'WAIT_WAREHOUSE' => '待入库', 'COMPLETED' => '已完成', 'READY' => '待开工', 'REWORK' => '返工',
            'QUALITY_FAILED' => '质检异常', 'HANDOVER_REJECTED' => '交接驳回', default => '异常',
        };
    }

    private function visible(int $workOrderId, object $user, array $permissions, bool $superAdmin, string $permission): void
    {
        $workOrder = WorkOrder::find($workOrderId);
        if (! $workOrder || ! $this->scopeResolver->workOrderVisible($workOrder, $this->scopeResolver->resolve($user, $permission, $permissions, $superAdmin))) {
            $this->fail('data_scope_denied', '当前用户不在该工单的数据范围内。', 403);
        }
    }

    private function permission(array $permissions, string $code, bool $superAdmin): void
    {
        if (! $superAdmin && ! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403);
    }

    private function fail(string $code, string $message, int $status = 422): never
    {
        throw new WorkOrderDomainException($code, $message, $status);
    }
}
