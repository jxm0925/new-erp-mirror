<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{ProductionQuantityOperation, ProductionTask, WorkOrder};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ProductionWorkOrderOperationQueryService
{
    public function __construct(private readonly ProductionDataScopeResolver $scopes) {}

    public function paginate(int $id, array $filters, object $user, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        if (!in_array('production.work_order.view', $permissions, true)) {
            throw new WorkOrderDomainException('permission_denied', '无权查看工单。', 403);
        }
        $workOrder = WorkOrder::find($id);
        if (!$workOrder) throw new WorkOrderDomainException('not_found', '工单不存在。', 404);
        if (!$this->scopes->workOrderVisible($workOrder, $this->scopes->resolve($user, 'production.work_order.view', $permissions, $superAdmin))) {
            throw new WorkOrderDomainException('data_scope_denied', '工单不在可查看范围内。', 403);
        }
        $query = ProductionQuantityOperation::where('work_order_id', $id)
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('sequence_no_snapshot')->orderBy('id');
        $page = $query->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $tasks = ProductionTask::query()->where('work_order_id', $id)
            ->whereIn('production_quantity_operation_id', $page->getCollection()->pluck('id'));
        $this->scopes->applyProductionTaskScope($tasks, $this->scopes->resolve($user, 'production.task.view', $permissions, $superAdmin), (int) ($user->legacy_id ?? $user->id));
        $tasks = ($superAdmin || in_array('production.task.view', $permissions, true))
            ? $tasks->get(['id', 'task_no', 'status', 'production_quantity_operation_id'])->keyBy('production_quantity_operation_id') : collect();
        $factor = (float) $workOrder->target_qty > 0 ? (float) $workOrder->target_base_qty / (float) $workOrder->target_qty : 1;
        $factor = $factor > 0 ? $factor : 1;
        $page->setCollection($page->getCollection()->map(function ($operation) use ($workOrder, $tasks, $factor): array {
            $task = $tasks[$operation->id] ?? null;
            return [
                'id' => (int) $operation->id, 'target_type' => 'quantity_operation',
                'routing_operation_id' => (int) $operation->routing_operation_id_snapshot,
                'sequence' => (int) $operation->sequence_no_snapshot,
                'operation_code' => $operation->operation_code_snapshot, 'operation_name' => $operation->operation_name_snapshot,
                'is_public_snapshot' => (bool) $operation->is_public_snapshot,
                'status' => $operation->status, 'business_version' => (int) $operation->business_version,
                'quantity' => [
                    'planned_qty' => round((float) $operation->planned_base_qty / $factor, 8),
                    'completed_qty' => round((float) $operation->completed_base_qty / $factor, 8),
                    'unqualified_qty' => round((float) $operation->unqualified_base_qty / $factor, 8),
                    'scrapped_qty' => round((float) $operation->scrapped_base_qty / $factor, 8),
                    'remaining_qty' => round((float) $operation->remaining_base_qty / $factor, 8),
                    'unit_name' => $workOrder->target_unit_name_snapshot, 'base_unit_name' => $workOrder->base_unit_name_snapshot,
                ],
                'task' => $task ? ['id' => (int) $task->id, 'task_no' => $task->task_no, 'status' => $task->status] : null,
                'actions' => ['view_task' => $task !== null],
            ];
        }));
        return $page;
    }
}
