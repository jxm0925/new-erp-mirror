<?php

namespace App\Services\Erp;

use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use Illuminate\Support\Facades\DB;

final class CuttingHandoverService
{
    public function __construct(
        private readonly CuttingCommandService $commands,
        private readonly DocumentNumberService $numbers,
        private readonly ProductionDataScopeResolver $scopes,
        private readonly ProductionTargetReadinessService $readiness,
    ) {}

    public function dispatch(int $routeId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $permission = 'production.cutting.handover.dispatch';
        $this->authorizeRoute($routeId, $user, $permissions, $super, $permission);
        return $this->commands->run('dispatch_cutting_route', $routeId, $payload, $user, function () use ($routeId, $payload, $user, $permissions, $super, $permission): array {
            [$route, $result, $batch] = $this->lockRoute($routeId, $user, $permissions, $super, $permission);
            $this->commands->version($route, $payload);
            if ($route->route_type !== 'NEXT_OPERATION' || ! $route->target_material_requirement_id) {
                $this->commands->fail('cutting_route_not_handover', '只有已指定真实下一工序的产出可以交出。', 409);
            }
            if (! in_array($route->status, ['WAIT_DISPATCH', 'PART_DISPATCHED', 'PART_RECEIVED'], true)) {
                $this->commands->fail('cutting_route_not_dispatchable', '当前产出去向不处于可交出状态。', 409);
            }
            $quantity = CuttingDecimal::value($payload['quantity'] ?? null);
            $remaining = bcsub((string) $route->quantity, (string) $route->handed_over_qty, 8);
            if (bccomp($quantity, $remaining, 8) > 0) $this->commands->fail('dispatch_quantity_exceeded', '本次交出数量超过该去向尚未交出的数量。');

            [$requirement, $task, $target] = $this->targetContext($route, true);
            if (! $task->assignee_user_legacy_id || $task->status === 'WAIT_CLAIM') {
                $this->commands->fail('cutting_target_not_claimed', '下一工序任务尚未由真实负责人领取，不能交出。', 409);
            }
            if ($target->started_at || in_array($target->status, ['IN_PROGRESS', 'COMPLETED', 'CANCELLED'], true)) {
                $this->commands->fail('cutting_target_already_started', '下一工序已经开工或结束，不能补建下料交接。', 409);
            }
            $source = DB::table('erp_material_holdings')->where('id', $route->holding_id)->lockForUpdate()->first();
            if (! $source || $source->position_type !== 'WAIT_DISPATCH' || (int) $source->position_id !== (int) $route->id
                || $source->status !== 'ACTIVE' || bccomp((string) $source->quantity, $quantity, 8) < 0) {
                $this->commands->fail('dispatch_holding_invalid', '该去向没有足量、有效且唯一的待交出持有份额。', 409);
            }
            $cost = CuttingDecimal::share((string) $source->total_cost, (string) $source->quantity, $quantity);
            $leftQty = bcsub((string) $source->quantity, $quantity, 8); $leftCost = bcsub((string) $source->total_cost, $cost, 4);
            DB::table('erp_material_holdings')->where('id', $source->id)->update(['quantity' => $leftQty, 'total_cost' => $leftCost,
                'status' => bccomp($leftQty, '0', 8) === 0 ? 'CONSUMED' : 'ACTIVE',
                'business_version' => (int) $source->business_version + 1, 'updated_at' => now()]);
            $transitId = DB::table('erp_material_holdings')->insertGetId(['material_lot_id' => $source->material_lot_id,
                'position_type' => 'IN_TRANSIT', 'position_id' => $route->id, 'quantity' => $quantity, 'total_cost' => $cost,
                'status' => 'ACTIVE', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $now = now(); $handoverId = DB::table('erp_cutting_handovers')->insertGetId([
                'handover_no' => $this->numbers->next('cutting_handover', 'CHO'), 'route_id' => $route->id,
                'cutting_order_id' => $batch->cutting_order_id, 'result_id' => $result->id,
                'target_material_requirement_id' => $requirement->id, 'target_task_id' => $task->id,
                'target_type' => $requirement->target_type, 'target_id' => $requirement->target_id,
                'source_holding_id' => $source->id, 'transit_holding_id' => $transitId,
                'dispatched_qty' => $quantity, 'dispatched_cost' => $cost, 'status' => 'IN_TRANSIT',
                'expected_receiver_legacy_id' => $task->assignee_user_legacy_id,
                'dispatched_by_legacy_id' => $this->commands->actor($user), 'dispatched_at' => $now,
                'business_version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $handedQty = bcadd((string) $route->handed_over_qty, $quantity, 8);
            $handedCost = bcadd((string) $route->handed_over_cost, $cost, 4);
            $routeStatus = $this->routeStatus($handedQty, (string) $route->received_qty, (string) $route->quantity);
            DB::table('erp_cutting_result_routes')->where('id', $route->id)->update(['handed_over_qty' => $handedQty,
                'handed_over_cost' => $handedCost, 'status' => $routeStatus,
                'business_version' => (int) $route->business_version + 1, 'updated_at' => $now]);
            $this->movement((int) $route->id, (int) $source->id, $transitId, 'DISPATCH', $quantity, $cost, $user);
            $readiness = $this->readiness->refresh((string) $requirement->target_type, $target, $task, $now);
            $response = ['handover_id' => $handoverId, 'handover_no' => DB::table('erp_cutting_handovers')->where('id', $handoverId)->value('handover_no'),
                'status' => 'IN_TRANSIT', 'dispatched_qty' => $quantity, 'dispatched_cost' => $cost,
                'route_id' => (int) $route->id, 'route_status' => $routeStatus,
                'route_business_version' => (int) $route->business_version + 1,
                'target_task_id' => (int) $task->id, 'expected_receiver_legacy_id' => (int) $task->assignee_user_legacy_id,
                'target_status' => $readiness['target_status'], 'target_business_version' => $readiness['target_business_version']];
            $this->commands->event('cutting_handover', $handoverId, 'dispatch', $user, null, $response); return $response;
        });
    }

    public function pending(array $filters, object $user, array $permissions, bool $super = false): array
    {
        $permission = 'production.cutting.handover.view'; $this->commands->permission($permissions, $permission);
        $actor = $this->commands->actor($user);
        $visibleTasks = ProductionTask::query()->select('id');
        $this->scopes->applyProductionTaskScope($visibleTasks, $this->scopes->resolve($user, $permission, $permissions, $super), $actor);
        $query = DB::table('erp_cutting_handovers as h')->join('erp_cutting_result_routes as route', 'route.id', '=', 'h.route_id')
            ->join('erp_cutting_results as result', 'result.id', '=', 'h.result_id')->join('erp_items as item', 'item.id', '=', 'result.item_id')
            ->join('erp_production_tasks as task', 'task.id', '=', 'h.target_task_id')
            ->join('erp_work_orders as wo', 'wo.id', '=', 'task.work_order_id')
            ->where('task.assignee_user_legacy_id', $actor)->whereIn('task.id', $visibleTasks->toBase())
            ->whereIn('h.status', ['IN_TRANSIT', 'PARTIAL'])
            ->orderBy('h.dispatched_at')->select('h.*', 'h.target_task_id as task_id', 'task.task_no', 'wo.work_order_no', 'item.item_code', 'item.item_name');
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT);
        $size = filter_var($filters['per_page'] ?? 20, FILTER_VALIDATE_INT);
        if (! $page || $page < 1 || ! $size || $size < 1 || $size > 100) {
            $this->commands->fail('pagination_invalid', '分页参数不合法，每页最多100条。');
        }
        $result = $query->paginate($size, ['*'], 'page', $page);
        return ['data' => array_map(fn ($row) => (array) $row, $result->items()), 'meta' => [
            'current_page' => $page, 'per_page' => $size, 'total' => $result->total(), 'last_page' => $result->lastPage(),
        ]];
    }

    public function accept(int $handoverId, array $payload, object $user, array $permissions, bool $super = false): array
    { return $this->decide(true, $handoverId, $payload, $user, $permissions, $super); }

    public function reject(int $handoverId, array $payload, object $user, array $permissions, bool $super = false): array
    { return $this->decide(false, $handoverId, $payload, $user, $permissions, $super); }

    private function decide(bool $accept, int $handoverId, array $payload, object $user, array $permissions, bool $super): array
    {
        $permission = $accept ? 'production.cutting.handover.receive' : 'production.cutting.handover.reject';
        $this->authorizeHandover($handoverId, $user, $permissions, $super, $permission);
        $command = $accept ? 'accept_cutting_handover' : 'reject_cutting_handover';
        return $this->commands->run($command, $handoverId, $payload, $user, function () use ($accept, $handoverId, $payload, $user, $permissions, $super, $permission): array {
            $handover = DB::table('erp_cutting_handovers')->where('id', $handoverId)->lockForUpdate()->first();
            if (! $handover) $this->commands->fail('cutting_handover_missing', '下料交接不存在。', 404);
            $this->commands->version($handover, $payload);
            if (! in_array($handover->status, ['IN_TRANSIT', 'PARTIAL'], true)) {
                $this->commands->fail('cutting_handover_already_decided', '该下料交接已经全部处理。', 409);
            }
            $actor = $this->commands->actor($user);
            $task = ProductionTask::query()->lockForUpdate()->find($handover->target_task_id);
            if (! $task) $this->commands->fail('cutting_target_task_invalid', '下一工序真实任务不存在。', 409);
            $this->assertTargetTask($task, $user, $permissions, $super, $permission);
            $quantity = CuttingDecimal::value($payload['quantity'] ?? null);
            $outstandingQty = bcsub(bcsub((string) $handover->dispatched_qty, (string) $handover->accepted_qty, 8), (string) $handover->rejected_qty, 8);
            $outstandingCost = bcsub(bcsub((string) $handover->dispatched_cost, (string) $handover->accepted_cost, 4), (string) $handover->rejected_cost, 4);
            if (bccomp($quantity, $outstandingQty, 8) > 0) $this->commands->fail('handover_quantity_exceeded', '处理数量超过该交接尚未处理的数量。');
            $cost = CuttingDecimal::share($outstandingCost, $outstandingQty, $quantity);
            $reason = trim((string) ($payload['reason'] ?? ''));
            if (! $accept && $reason === '') $this->commands->fail('reject_reason_required', '拒收时必须填写原因。');
            $route = DB::table('erp_cutting_result_routes')->where('id', $handover->route_id)->lockForUpdate()->first();
            $transit = DB::table('erp_material_holdings')->where('id', $handover->transit_holding_id)->lockForUpdate()->first();
            if (! $route || ! $transit || $transit->status !== 'ACTIVE' || bccomp((string) $transit->quantity, $quantity, 8) < 0) {
                $this->commands->fail('handover_holding_invalid', '交接在途持有份额不足或已失效。', 409);
            }
            $leftTransitQty = bcsub((string) $transit->quantity, $quantity, 8); $leftTransitCost = bcsub((string) $transit->total_cost, $cost, 4);
            DB::table('erp_material_holdings')->where('id', $transit->id)->update(['quantity' => $leftTransitQty, 'total_cost' => $leftTransitCost,
                'status' => bccomp($leftTransitQty, '0', 8) === 0 ? 'CONSUMED' : 'ACTIVE',
                'business_version' => (int) $transit->business_version + 1, 'updated_at' => now()]);

            $requirement = DB::table('erp_production_target_material_requirements')->where('id', $handover->target_material_requirement_id)->lockForUpdate()->first();
            if (! $requirement || $requirement->target_type !== $handover->target_type || (int) $requirement->target_id !== (int) $handover->target_id) {
                $this->commands->fail('cutting_target_requirement_invalid', '交接绑定的下一工序物料需求已失效。', 409);
            }
            $now = now(); $targetHoldingId = null;
            if ($accept) {
                $net = bcsub((string) $requirement->satisfied_base_qty, (string) $requirement->returned_base_qty, 8);
                $shortage = bcsub((string) $requirement->required_base_qty, $net, 8);
                if (bccomp($quantity, $shortage, 8) > 0) $this->commands->fail('target_receipt_exceeded', '接收数量超过下一工序真实未满足需求。', 409);
                $targetHoldingId = DB::table('erp_material_holdings')->insertGetId(['material_lot_id' => $transit->material_lot_id,
                    'position_type' => 'PRODUCTION_WIP', 'position_id' => $requirement->id, 'quantity' => $quantity, 'total_cost' => $cost,
                    'status' => 'ACTIVE', 'business_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                $this->movement((int) $route->id, (int) $transit->id, $targetHoldingId, 'RECEIVE', $quantity, $cost, $user);
                $satisfied = bcadd((string) $requirement->satisfied_base_qty, $quantity, 8);
                $netAfter = bcsub($satisfied, (string) $requirement->returned_base_qty, 8);
                DB::table('erp_production_target_material_requirements')->where('id', $requirement->id)->update([
                    'satisfied_base_qty' => $satisfied,
                    'status' => bccomp($netAfter, (string) $requirement->required_base_qty, 8) >= 0 ? 'SATISFIED' : 'PARTIAL',
                    'business_version' => (int) $requirement->business_version + 1, 'updated_at' => $now]);
                $acceptedQty = bcadd((string) $handover->accepted_qty, $quantity, 8);
                $acceptedCost = bcadd((string) $handover->accepted_cost, $cost, 4);
                $rejectedQty = (string) $handover->rejected_qty; $rejectedCost = (string) $handover->rejected_cost;
                $routeReceivedQty = bcadd((string) $route->received_qty, $quantity, 8);
                $routeReceivedCost = bcadd((string) $route->received_cost, $cost, 4);
                $routeHandedQty = (string) $route->handed_over_qty; $routeHandedCost = (string) $route->handed_over_cost;
            } else {
                $source = DB::table('erp_material_holdings')->where('id', $handover->source_holding_id)->lockForUpdate()->first();
                if (! $source || (int) $source->material_lot_id !== (int) $transit->material_lot_id) {
                    $this->commands->fail('handover_return_source_invalid', '拒收退回的原持有份额已失效。', 409);
                }
                DB::table('erp_material_holdings')->where('id', $source->id)->update([
                    'quantity' => bcadd((string) $source->quantity, $quantity, 8),
                    'total_cost' => bcadd((string) $source->total_cost, $cost, 4), 'status' => 'ACTIVE',
                    'business_version' => (int) $source->business_version + 1, 'updated_at' => $now]);
                $this->movement((int) $route->id, (int) $transit->id, (int) $source->id, 'REJECT_RETURN', $quantity, $cost, $user);
                $acceptedQty = (string) $handover->accepted_qty; $acceptedCost = (string) $handover->accepted_cost;
                $rejectedQty = bcadd((string) $handover->rejected_qty, $quantity, 8);
                $rejectedCost = bcadd((string) $handover->rejected_cost, $cost, 4);
                $routeReceivedQty = (string) $route->received_qty; $routeReceivedCost = (string) $route->received_cost;
                $routeHandedQty = bcsub((string) $route->handed_over_qty, $quantity, 8);
                $routeHandedCost = bcsub((string) $route->handed_over_cost, $cost, 4);
            }
            $processed = bcadd($acceptedQty, $rejectedQty, 8);
            $status = bccomp($processed, (string) $handover->dispatched_qty, 8) < 0 ? 'PARTIAL'
                : (bccomp($acceptedQty, '0', 8) > 0 && bccomp($rejectedQty, '0', 8) > 0 ? 'MIXED'
                    : (bccomp($acceptedQty, '0', 8) > 0 ? 'RECEIVED' : 'REJECTED'));
            DB::table('erp_cutting_handovers')->where('id', $handoverId)->update(['accepted_qty' => $acceptedQty,
                'accepted_cost' => $acceptedCost, 'rejected_qty' => $rejectedQty, 'rejected_cost' => $rejectedCost,
                'status' => $status, 'completed_at' => $status === 'PARTIAL' ? null : $now,
                'business_version' => (int) $handover->business_version + 1, 'updated_at' => $now]);
            $routeStatus = $this->routeStatus($routeHandedQty, $routeReceivedQty, (string) $route->quantity);
            DB::table('erp_cutting_result_routes')->where('id', $route->id)->update(['handed_over_qty' => $routeHandedQty,
                'handed_over_cost' => $routeHandedCost, 'received_qty' => $routeReceivedQty, 'received_cost' => $routeReceivedCost,
                'status' => $routeStatus, 'business_version' => (int) $route->business_version + 1, 'updated_at' => $now]);
            DB::table('erp_cutting_handover_decisions')->insert(['handover_id' => $handoverId,
                'action' => $accept ? 'ACCEPT' : 'REJECT', 'quantity' => $quantity, 'total_cost' => $cost,
                'target_holding_id' => $targetHoldingId, 'reason' => $accept ? null : $reason,
                'operator_legacy_id' => $actor, 'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $target = $this->target((string) $handover->target_type, (int) $handover->target_id, true);
            $readiness = $this->readiness->refresh((string) $handover->target_type, $target, $task, $now);
            $response = ['handover_id' => $handoverId, 'status' => $status, 'accepted_qty' => $acceptedQty,
                'rejected_qty' => $rejectedQty, 'remaining_qty' => bcsub((string) $handover->dispatched_qty, $processed, 8),
                'business_version' => (int) $handover->business_version + 1, 'route_id' => (int) $route->id,
                'route_status' => $routeStatus, 'route_designated_qty' => (string) $route->quantity,
                'route_handed_over_qty' => $routeHandedQty, 'route_received_qty' => $routeReceivedQty,
                'target_status' => $readiness['target_status'], 'target_business_version' => $readiness['target_business_version'],
                'expected_receiver_legacy_id' => (int) $handover->expected_receiver_legacy_id,
                'handled_by_legacy_id' => $actor,
                'receiver_changed_after_dispatch' => (int) $handover->expected_receiver_legacy_id !== $actor];
            $this->commands->event('cutting_handover', $handoverId, $accept ? 'accept' : 'reject', $user, $handover, $response); return $response;
        });
    }

    private function authorizeRoute(int $routeId, object $user, array $permissions, bool $super, string $permission): void
    {
        $this->commands->permission($permissions, $permission);
        $row = DB::table('erp_cutting_result_routes as route')->join('erp_cutting_results as result', 'result.id', '=', 'route.result_id')
            ->join('erp_cutting_settlement_batches as batch', 'batch.id', '=', 'result.settlement_batch_id')
            ->where('route.id', $routeId)->select('batch.cutting_task_id')->first();
        if (! $row) $this->commands->fail('cutting_route_missing', '产出去向不存在。', 404);
        if (! $row->cutting_task_id) $this->commands->fail('cutting_task_missing', '产出去向尚未绑定正式下料任务。', 409);
        $this->commands->cuttingTask((int) $row->cutting_task_id, $user, $permissions, $super, $permission);
    }

    private function authorizeHandover(int $id, object $user, array $permissions, bool $super, string $permission): void
    {
        $this->commands->permission($permissions, $permission);
        $row = DB::table('erp_cutting_handovers')->where('id', $id)->first();
        if (! $row) $this->commands->fail('cutting_handover_missing', '下料交接不存在。', 404);
        // 成功命令也必须复验当前访问资格；幂等恢复只防重复事实，不能保留旧负责人的权限。
        $task = ProductionTask::query()->find($row->target_task_id);
        if (! $task) $this->commands->fail('cutting_target_task_invalid', '下一工序真实任务不存在。', 409);
        $this->assertTargetTask($task, $user, $permissions, $super, $permission);
    }

    private function lockRoute(int $routeId, object $user, array $permissions, bool $super, string $permission): array
    {
        $route = DB::table('erp_cutting_result_routes')->where('id', $routeId)->lockForUpdate()->first();
        $result = $route ? DB::table('erp_cutting_results')->where('id', $route->result_id)->lockForUpdate()->first() : null;
        $batch = $result ? DB::table('erp_cutting_settlement_batches')->where('id', $result->settlement_batch_id)->lockForUpdate()->first() : null;
        if (! $route || ! $result || ! $batch) $this->commands->fail('cutting_route_missing', '产出去向不存在。', 404);
        if (! $batch->cutting_task_id) $this->commands->fail('cutting_task_missing', '产出去向尚未绑定正式下料任务。', 409);
        $this->commands->cuttingTask((int) $batch->cutting_task_id, $user, $permissions, $super, $permission, true);
        return [$route, $result, $batch];
    }

    private function assertTargetTask(ProductionTask $task, object $user, array $permissions, bool $super, string $permission): void
    {
        $actor = $this->commands->actor($user);
        $visible = ProductionTask::query()->whereKey($task->id);
        $this->scopes->applyProductionTaskScope($visible, $this->scopes->resolve($user, $permission, $permissions, $super), $actor);
        if (! $visible->exists()) $this->commands->fail('data_scope_denied', '该接收任务不在当前生产数据范围内。', 403);
        if ((int) $task->assignee_user_legacy_id !== $actor) {
            $this->commands->fail('cutting_expected_receiver_required', '只有下一工序当前负责人可以接收或拒收。', 403);
        }
    }

    private function targetContext(object $route, bool $lock): array
    {
        $query = DB::table('erp_production_target_material_requirements')->where('id', $route->target_material_requirement_id);
        if ($lock) $query->lockForUpdate(); $requirement = $query->first();
        if (! $requirement) $this->commands->fail('cutting_target_requirement_invalid', '下一工序物料需求不存在。', 409);
        $link = DB::table('erp_production_task_targets')->where('target_type', $requirement->target_type)->where('target_id', $requirement->target_id)->first();
        $taskQuery = ProductionTask::query(); if ($lock) $taskQuery->lockForUpdate(); $task = $link ? $taskQuery->find($link->task_id) : null;
        if (! $task || (int) $task->work_order_id !== (int) $requirement->work_order_id) {
            $this->commands->fail('cutting_target_task_invalid', '下一工序尚未形成与物料需求一致的真实任务。', 409);
        }
        return [$requirement, $task, $this->target($requirement->target_type, (int) $requirement->target_id, $lock)];
    }

    private function target(string $type, int $id, bool $lock): object
    {
        $model = $type === 'unit_operation' ? ProductionUnitOperation::class : ($type === 'quantity_operation' ? ProductionQuantityOperation::class : null);
        if (! $model) $this->commands->fail('cutting_target_type_invalid', '下一工序目标类型无效。', 409);
        $query = $model::query(); if ($lock) $query->lockForUpdate();
        $target = $query->find($id); if (! $target) $this->commands->fail('cutting_target_missing', '下一工序执行目标不存在。', 409);
        return $target;
    }

    private function routeStatus(string $handed, string $received, string $total): string
    {
        if (bccomp($received, $total, 8) >= 0) return 'RECEIVED';
        if (bccomp($received, '0', 8) > 0) return 'PART_RECEIVED';
        if (bccomp($handed, $total, 8) >= 0) return 'IN_TRANSIT';
        if (bccomp($handed, '0', 8) > 0) return 'PART_DISPATCHED';
        return 'WAIT_DISPATCH';
    }

    private function movement(int $routeId, int $source, int $target, string $action, string $qty, string $cost, object $user): void
    {
        DB::table('erp_material_movements')->insert(['movement_no' => $this->numbers->next('material_movement', 'MM'),
            'route_id' => $routeId, 'source_holding_id' => $source, 'target_holding_id' => $target,
            'action' => $action, 'quantity' => $qty, 'total_cost' => $cost,
            'operator_legacy_id' => $this->commands->actor($user), 'created_at' => now(), 'updated_at' => now()]);
    }
}
