<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{ProductionRouting, ProductionRoutingOperation, ProductionUnit, WorkOrder};
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** The same formal execution/requirement rule drives options and command validation. */
final class StockPrebuildTargetService
{
    public function __construct(private readonly ProductionDataScopeResolver $scopes) {}

    public function outputItem(array $filters): int
    {
        $route = ProductionRouting::query()->where('status', 'active')
            ->where('output_item_id', (int) ($filters['output_item_id'] ?? 0))
            ->find((int) ($filters['routing_id'] ?? 0));
        $node = $route ? ProductionRoutingOperation::query()->where('routing_id', $route->id)
            ->find((int) ($filters['target_routing_operation_id'] ?? 0)) : null;
        if (! $node?->output_item_id) $this->fail('stock_prebuild_output_item_required', '请先选择有效的备货物料、工艺路线和加工终点。');
        return (int) $node->output_item_id;
    }

    public function candidates(int $itemId, ?object $user = null, array $permissions = [], bool $super = false): Builder
    {
        $visible = WorkOrder::query()->select('id')->whereIn('status', ['RELEASED', 'IN_PROGRESS']);
        if ($user) {
            if (! in_array('production.work_order.view', $permissions, true)) $visible->whereRaw('1 = 0');
            $this->scopes->applyWorkOrderScope($visible, $this->scopes->resolve($user, 'production.work_order.view', $permissions, $super));
        }
        $parts = [];
        foreach (['unit_operation' => 'erp_production_unit_operations', 'quantity_operation' => 'erp_production_quantity_operations'] as $type => $table) {
            $requirements = DB::table('erp_production_target_material_requirements')
                ->selectRaw('target_id, MIN(id) as requirement_id')->where('target_type', $type)
                ->where('component_item_id', $itemId)->groupBy('target_id')->havingRaw('COUNT(*) = 1');
            $tasks = DB::table('erp_production_task_targets')->selectRaw('target_id, MIN(task_id) as task_id')
                ->where('target_type', $type)->groupBy('target_id')->havingRaw('COUNT(DISTINCT task_id) = 1');
            $query = DB::table($table.' as op')->joinSub($requirements, 'req', 'req.target_id', '=', 'op.id')
                ->joinSub($tasks, 'tt', 'tt.target_id', '=', 'op.id')
                ->whereIn('op.work_order_id', clone $visible)
                ->whereNotIn('op.status', ['COMPLETED', 'CLOSED', 'CANCELLED'])
                ->selectRaw("'{$type}' as target_type, op.id as target_id, op.work_order_id, "
                    .($type === 'unit_operation' ? 'op.production_unit_id' : 'NULL').' as production_unit_id, '
                    .'op.routing_operation_id_snapshot as routing_operation_id, op.sequence_no_snapshot as sequence_no, '
                    .'op.operation_code_snapshot as operation_code, op.operation_name_snapshot as operation_name, req.requirement_id, tt.task_id');
            if ($type === 'unit_operation') {
                $query->join('erp_production_units as pu', 'pu.id', '=', 'op.production_unit_id')
                    ->whereColumn('pu.work_order_id', 'op.work_order_id')->whereNotIn('pu.status', ['COMPLETED', 'CLOSED', 'CANCELLED']);
            }
            $parts[] = $query;
        }
        return DB::query()->fromSub($parts[0]->unionAll($parts[1]), 'eligible_target');
    }

    public function resolve(int $workOrderId, ?int $unitId, int $operationId, int $itemId,
        ?object $user = null, array $permissions = [], bool $super = false): array
    {
        $workOrder = WorkOrder::query()->whereKey($workOrderId)->lockForUpdate()->first();
        if (! $workOrder || ! in_array($workOrder->status, ['RELEASED', 'IN_PROGRESS'], true)) {
            $this->fail('reserved_target_work_order_unavailable', '指定工单必须已发布且尚未完成、关闭或取消。');
        }
        if ($user && ! $this->scopes->workOrderVisible($workOrder, $this->scopes->resolve($user, 'production.work_order.view', $permissions, $super))) {
            throw new WorkOrderDomainException('forbidden', '无权使用该指定目标工单。', 403);
        }
        if ($workOrder->production_execution_mode_snapshot === 'unit') {
            if (! $unitId) $this->fail('stock_prebuild_reserved_unit_required', '逐件目标工单必须指定具体生产单元。');
            if (! ProductionUnit::query()->whereKey($unitId)->where('work_order_id', $workOrderId)->exists()) {
                $this->fail('stock_prebuild_reserved_unit_invalid', '指定生产单元不属于目标生产工单。');
            }
        } elseif ($unitId) {
            $this->fail('stock_prebuild_reserved_unit_invalid', '按数量生产的目标工单不能指定生产单元。');
        }
        $rows = $this->candidates($itemId, $user, $permissions, $super)->where('work_order_id', $workOrderId)
            ->where('routing_operation_id', $operationId)
            ->when($unitId, fn ($query) => $query->where('production_unit_id', $unitId))->get();
        if ($rows->count() !== 1) {
            $this->fail('reserved_target_material_requirement_invalid', '接收工序必须尚未完成，并且具有唯一正式任务和与备货终点产出物料相同的唯一正式需求。');
        }
        $row = $rows->first();
        // Commands run inside a transaction. Lock the selected facts before relying on them.
        $locked = DB::table($row->target_type === 'unit_operation' ? 'erp_production_unit_operations' : 'erp_production_quantity_operations')
            ->where('id', $row->target_id)->lockForUpdate()->first();
        $requirements = DB::table('erp_production_target_material_requirements')->where('target_type', $row->target_type)
            ->where('target_id', $row->target_id)->where('component_item_id', $itemId)->lockForUpdate()->get();
        if (! $locked || in_array($locked->status, ['COMPLETED', 'CLOSED', 'CANCELLED'], true) || $requirements->count() !== 1) {
            $this->fail('reserved_target_material_requirement_invalid', '目标工序或正式需求已发生变化，请重新选择。');
        }
        return ['type' => $row->target_type, 'id' => (int) $row->target_id,
            'task_id' => (int) $row->task_id, 'requirement_id' => (int) $row->requirement_id];
    }

    public function forWorkOrder(WorkOrder $source, ?object $user = null, array $permissions = [], bool $super = false): array
    {
        return $this->resolve((int) $source->reserved_for_work_order_id,
            $source->reserved_for_production_unit_id ? (int) $source->reserved_for_production_unit_id : null,
            (int) $source->reserved_for_target_operation_id, (int) $source->effective_output_item_id_snapshot,
            $user, $permissions, $super);
    }

    private function fail(string $code, string $message): never
    {
        throw new WorkOrderDomainException($code, $message, 422);
    }
}
