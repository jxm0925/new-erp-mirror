<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryLocationBalance;
use App\Models\Erp\Item;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;

/** Formal use of the stock restriction created by a cutting warehouse receipt. */
final class CuttingInventoryReservationService
{
    public function __construct(
        private readonly CuttingCommandService $commands,
        private readonly CuttingRecordService $records,
        private readonly DocumentNumberService $numbers,
        private readonly InventoryAvailabilityService $availability,
        private readonly ProductionDataScopeResolver $scopes,
    ) {}

    public function paginate(array $filters, object $user, array $permissions, bool $super = false): array
    {
        $permission = 'production.cutting.inventory.view';
        $this->commands->permission($permissions, $permission);
        $workOrders = WorkOrder::query();
        $this->scopes->applyWorkOrderScope($workOrders, $this->scopes->resolve($user, $permission, $permissions, $super));
        $query = DB::table('erp_cutting_inventory_reservations as reservation')
            ->join('erp_cutting_warehouse_receipt_allocations as receipt_allocation', 'receipt_allocation.id', '=', 'reservation.receipt_allocation_id')
            ->join('erp_cutting_warehouse_receipts as receipt', 'receipt.id', '=', 'receipt_allocation.receipt_id')
            ->join('erp_cutting_results as result', 'result.id', '=', 'receipt.result_id')
            ->join('erp_cutting_settlement_batches as batch', 'batch.id', '=', 'result.settlement_batch_id')
            ->join('erp_cutting_tasks as task', 'task.id', '=', 'batch.cutting_task_id')
            ->leftJoin('erp_production_target_material_requirements as requirement', 'requirement.id', '=', 'reservation.target_material_requirement_id')
            ->where(function ($q) use ($workOrders, $user, $permissions, $super, $permission): void {
                $q->whereIn('requirement.work_order_id', (clone $workOrders)->select('erp_work_orders.id'));
                $tasks = \App\Models\Erp\CuttingTask::query();
                $this->scopes->applyCuttingTaskScope($tasks, $this->scopes->resolve($user, $permission, $permissions, $super), $this->commands->actor($user));
                $q->orWhere(fn ($q) => $q->whereNull('reservation.target_material_requirement_id')
                    ->whereIn('task.id', $tasks->select('erp_cutting_tasks.id')));
            });
        if (! empty($filters['status'])) $query->where('reservation.status', $filters['status']);
        if (! empty($filters['keyword'])) $query->where('reservation.reservation_no', 'like', '%'.$filters['keyword'].'%');
        $page = $query->select('reservation.*', 'receipt.receipt_no', 'receipt.route_id', 'result.stage_id')
            ->orderByDesc('reservation.id')->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $data = $page->getCollection()->map(function ($row): array {
            $pending = $this->pending((int) $row->id);
            return (array) $row + ['remaining_qty' => $this->remaining($row),
                'pending_issue_qty' => $pending['quantity'],
                'issuable_qty' => bcsub($this->remaining($row), $pending['quantity'], 8)];
        })->all();
        return ['data' => $data, 'total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage()];
    }

    public function createIssue(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $permission = 'production.cutting.inventory.issue';
        $reservation = $this->reservation($id);
        $targetId = $this->targetId($reservation, $payload);
        $this->authorizeTarget($targetId, $user, $permissions, $super, $permission);
        $workOrderId = (int) DB::table('erp_production_target_material_requirements')->where('id', $targetId)->value('work_order_id');
        $this->records->configuration($reservation->configuration_id, Item::findOrFail($reservation->item_id), $workOrderId);
        return $this->commands->run('create_cutting_inventory_issue', $id, $payload, $user, function () use ($id, $payload, $targetId, $user, $permissions, $super, $permission): array {
            [$requirement, $task, $target] = $this->target($targetId, true);
            $this->authorizeTarget($targetId, $user, $permissions, $super, $permission, true);
            $reservation = $this->reservation($id, true);
            $this->commands->version($reservation, $payload);
            if ($this->targetId($reservation, $payload) !== $targetId) $this->commands->fail('reservation_target_changed', '库存归属已变化，请刷新。', 409);
            $this->assertUsable($reservation, $requirement, $task, $target);
            $quantity = CuttingDecimal::value($payload['quantity'] ?? null);
            $pending = $this->pending($id, true);
            $remaining = bcsub($this->remaining($reservation), $pending['quantity'], 8);
            if (bccomp($quantity, $remaining, 8) > 0) $this->commands->fail('cutting_inventory_issue_exceeded', '领用数量超过尚未安排领用的专用库存。', 409);
            $this->assertShortage($requirement, $quantity, null);
            $source = $this->source($reservation);
            $remainingCost = bcsub(bcsub(bcsub((string) $source->total_cost, (string) $reservation->issued_total_cost, 4),
                (string) $reservation->released_total_cost, 4), $pending['cost'], 4);
            if (bccomp($remainingCost, '0', 4) < 0) $this->commands->fail('cutting_inventory_cost_invalid', '专用库存剩余成本不一致。', 409);
            $cost = CuttingDecimal::share($remainingCost, $remaining, $quantity);
            $balance = $this->balance($reservation);
            $now = now();
            $issueNo = $this->numbers->next('production_internal_issue', 'PII');
            $issueId = DB::table('erp_production_internal_issue_tasks')->insertGetId([
                'issue_no' => $issueNo, 'work_order_id' => $requirement->work_order_id, 'target_task_id' => $task->id,
                'target_type' => $requirement->target_type, 'target_id' => $requirement->target_id,
                'source_type' => 'cutting_reserved', 'status' => 'WAIT_ISSUE', 'business_version' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('erp_production_internal_issue_lines')->insert([
                'issue_task_id' => $issueId, 'cutting_inventory_reservation_id' => $id,
                'target_material_requirement_id' => $targetId, 'item_id' => $reservation->item_id,
                'inventory_balance_id' => $balance->id, 'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no,
                'issue_base_qty' => $quantity, 'issue_total_cost' => $cost, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('erp_cutting_inventory_reservations')->where('id', $id)->update([
                'business_version' => (int) $reservation->business_version + 1, 'updated_at' => $now,
            ]);
            $response = ['reservation_id' => $id, 'internal_issue_task_id' => $issueId, 'issue_no' => $issueNo,
                'status' => 'WAIT_ISSUE', 'issue_business_version' => 1, 'quantity' => $quantity, 'total_cost' => $cost,
                'business_version' => (int) $reservation->business_version + 1];
            $this->commands->event('cutting_inventory_reservation', $id, 'create_issue', $user, $reservation, $response);
            return $response;
        });
    }

    public function release(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $permission = 'production.cutting.inventory.release';
        $this->authorizeReservation($this->reservation($id), $user, $permissions, $super, $permission);
        return $this->commands->run('release_cutting_inventory', $id, $payload, $user, function () use ($id, $payload, $user, $permissions, $super, $permission): array {
            $reservation = $this->reservation($id, true);
            $this->authorizeReservation($reservation, $user, $permissions, $super, $permission);
            $this->commands->version($reservation, $payload);
            $quantity = CuttingDecimal::value($payload['quantity'] ?? null);
            $reason = trim((string) ($payload['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) > 1000) $this->commands->fail('release_reason_required', '释放必须填写不超过1000字的原因。');
            if ($reservation->status !== 'ACTIVE') $this->commands->fail('cutting_inventory_not_active', '专用库存归属已处理。', 409);
            $pending = $this->pending($id, true);
            $remaining = $this->remaining($reservation);
            if (bccomp($quantity, bcsub($remaining, $pending['quantity'], 8), 8) > 0) {
                $this->commands->fail('cutting_inventory_release_exceeded', '释放数量超过尚未领用或安排领用的数量。', 409);
            }
            $source = $this->source($reservation);
            $config = $reservation->configuration_id ? DB::table('erp_custom_configurations')->where('id', $reservation->configuration_id)->lockForUpdate()->first() : null;
            if ($reservation->configuration_id && ! $config) $this->commands->fail('configuration_invalid', '配置版本不存在。', 409);
            $restricted = $config && $config->scope_mode !== 'PUBLIC';
            $changes = ['business_version' => (int) $reservation->business_version + 1, 'updated_at' => now()];
            if ($restricted) {
                // Dropping a demand assignment never drops a configuration restriction.
                // Preserve the same locked stock identity; only a later qualified demand may use it.
                if ($reservation->reservation_scope !== 'PLAN' || ! $reservation->target_material_requirement_id) {
                    $this->commands->fail('restricted_inventory_release_denied', '专用配置未分配库存不能释放为公共库存。', 403);
                }
                if (bccomp($pending['quantity'], '0', 8) !== 0 || bccomp($quantity, $remaining, 8) !== 0) {
                    $this->commands->fail('restricted_plan_release_requires_remainder', '专用配置须取消待领用单后，整体释放剩余计划归属；库存仍保留专用限制。', 409);
                }
                $changes += ['reservation_scope' => 'RESTRICTED_CONFIGURATION', 'target_material_requirement_id' => null,
                    'released_target_qty' => bcadd((string) $reservation->released_target_qty, $quantity, 8)];
                $releasedCost = '0.0000';
            } else {
                $remainingCost = bcsub(bcsub(bcsub((string) $source->total_cost, (string) $reservation->issued_total_cost, 4),
                    (string) $reservation->released_total_cost, 4), $pending['cost'], 4);
                $releasedCost = CuttingDecimal::share($remainingCost, bcsub($remaining, $pending['quantity'], 8), $quantity);
                $this->changeLock($this->balance($reservation), bcsub('0', $quantity, 8));
                $released = bcadd((string) $reservation->released_qty, $quantity, 8);
                $left = bcsub(bcsub((string) $reservation->reserved_qty, (string) $reservation->issued_qty, 8), $released, 8);
                $changes += ['released_qty' => $released,
                    'released_total_cost' => bcadd((string) $reservation->released_total_cost, $releasedCost, 4),
                    'status' => bccomp($left, '0', 8) === 0 ? 'RELEASED' : 'ACTIVE'];
            }
            DB::table('erp_cutting_inventory_reservations')->where('id', $id)->update($changes);
            $response = ['reservation_id' => $id, 'released_qty' => $quantity, 'released_cost' => $releasedCost,
                'public_availability_released' => ! $restricted, 'remaining_qty' => $restricted ? $remaining : $left,
                'reservation_scope' => $changes['reservation_scope'] ?? $reservation->reservation_scope,
                'status' => $changes['status'] ?? $reservation->status, 'business_version' => $changes['business_version'], 'reason' => $reason];
            $this->commands->event('cutting_inventory_reservation', $id, 'release', $user, $reservation, $response);
            return $response;
        });
    }

    public function cancelIssue(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.inventory.issue');
        $this->authorizeInternalIssue($id, $user, $permissions, $super, false);
        // Cancel/dispatch/receive must share issue-before-command lock order.
        return DB::transaction(function () use ($id, $payload, $user, $permissions, $super): array {
            DB::table('erp_production_internal_issue_tasks')->where('id', $id)->lockForUpdate()->first();
            return $this->commands->run('cancel_cutting_inventory_issue', $id, $payload, $user, function () use ($id, $payload, $user, $permissions, $super): array {
            $issue = DB::table('erp_production_internal_issue_tasks')->where('id', $id)->lockForUpdate()->first();
            $this->authorizeInternalIssue($id, $user, $permissions, $super, false, true);
            $this->commands->permission($permissions, 'production.cutting.inventory.issue');
            $this->commands->version($issue, $payload);
            if ($issue->source_type !== 'cutting_reserved' || $issue->status !== 'WAIT_ISSUE') $this->commands->fail('cutting_issue_not_cancellable', '只有尚未交出的下料库存领用单可以取消。', 409);
            $lines = DB::table('erp_production_internal_issue_lines')->where('issue_task_id', $id)->lockForUpdate()->get();
            foreach ($lines as $line) {
                $reservation = $this->reservation((int) $line->cutting_inventory_reservation_id, true);
                DB::table('erp_cutting_inventory_reservations')->where('id', $reservation->id)->update([
                    'business_version' => (int) $reservation->business_version + 1, 'updated_at' => now(),
                ]);
            }
            DB::table('erp_production_internal_issue_tasks')->where('id', $id)->update([
                'status' => 'CANCELLED', 'business_version' => (int) $issue->business_version + 1, 'updated_at' => now(),
            ]);
            $response = ['id' => $id, 'status' => 'CANCELLED', 'business_version' => (int) $issue->business_version + 1];
            $this->commands->event('cutting_internal_issue', $id, 'cancel', $user, $issue, $response);
            return $response;
            });
        }, 5);
    }

    /** Recheck before command replay AND after task locking; old successful commands are not access grants. */
    public function authorizeInternalIssue(int $id, object $user, array $permissions, bool $super, bool $receive, bool $lock = false): void
    {
        $issue = DB::table('erp_production_internal_issue_tasks')->where('id', $id)->first();
        if (! $issue || $issue->source_type !== 'cutting_reserved') return;
        $permission = $receive ? 'production.output.receive' : 'production.output.issue';
        $this->commands->permission($permissions, $permission);
        if (! $receive) $this->commands->workOrder((int) $issue->work_order_id, $user, $permissions, $super, $permission, $lock);
        $query = ProductionTask::query()->whereKey($issue->target_task_id);
        if ($receive) $this->scopes->applyProductionTaskScope($query, $this->scopes->resolve($user, $permission, $permissions, $super), $this->commands->actor($user));
        if ($lock) $query->lockForUpdate();
        $task = $query->first();
        if (! $task && ProductionTask::query()->whereKey($issue->target_task_id)->exists()) {
            $this->commands->fail('data_scope_denied', '领用目标任务不在当前数据范围内。', 403);
        }
        if (! $task || (int) $task->work_order_id !== (int) $issue->work_order_id) $this->commands->fail('cutting_target_task_invalid', '领用目标任务已失效。', 409);
        if ($receive && (int) $task->assignee_user_legacy_id !== $this->commands->actor($user)) {
            $this->commands->fail('expected_receiver_required', '只有下一工序当前负责人可以确认接收。', 403);
        }
    }

    /** Unlock only in the same transaction that posts the existing formal internal issue. */
    public function releaseForIssue(object $issue, iterable $lines): void
    {
        if ($issue->source_type !== 'cutting_reserved') return;
        if (DB::transactionLevel() < 1) throw new \LogicException('Reserved cutting issue requires a transaction.');
        foreach ($lines as $line) {
            $reservation = $this->reservation((int) $line->cutting_inventory_reservation_id, true);
            [$requirement, $task, $target] = $this->target((int) $line->target_material_requirement_id, true);
            $this->assertUsable($reservation, $requirement, $task, $target);
            if ((int) $line->inventory_balance_id !== (int) $reservation->inventory_balance_id
                || (int) $line->item_id !== (int) $reservation->item_id || (int) $task->id !== (int) $issue->target_task_id
                || $requirement->target_type !== $issue->target_type || (int) $requirement->target_id !== (int) $issue->target_id
                || ($reservation->reservation_scope === 'PLAN' && (int) $reservation->target_material_requirement_id !== (int) $requirement->id)
                || $line->issue_total_cost === null || bccomp((string) $line->issue_base_qty, $this->remaining($reservation), 8) > 0) {
                $this->commands->fail('cutting_inventory_issue_invalid', '领用单与专用库存归属、数量或成本不匹配。', 409);
            }
            $this->assertShortage($requirement, (string) $line->issue_base_qty, (int) $issue->id);
            $source = $this->source($reservation);
            $cost = bcadd((string) $reservation->issued_total_cost, (string) $line->issue_total_cost, 4);
            if (bccomp(bcadd($cost, (string) $reservation->released_total_cost, 4), (string) $source->total_cost, 4) > 0) {
                $this->commands->fail('cutting_inventory_cost_exceeded', '领用成本超过该入库归属剩余成本。', 409);
            }
            $this->changeLock($this->balance($reservation), bcsub('0', (string) $line->issue_base_qty, 8));
            $issued = bcadd((string) $reservation->issued_qty, (string) $line->issue_base_qty, 8);
            $remaining = bcsub(bcsub((string) $reservation->reserved_qty, $issued, 8), (string) $reservation->released_qty, 8);
            DB::table('erp_cutting_inventory_reservations')->where('id', $reservation->id)->update([
                'issued_qty' => $issued, 'issued_total_cost' => $cost,
                'status' => bccomp($remaining, '0', 8) === 0 ? 'CONSUMED' : 'ACTIVE',
                'consumed_at' => bccomp($remaining, '0', 8) === 0 ? now() : null,
                'business_version' => (int) $reservation->business_version + 1, 'updated_at' => now(),
            ]);
        }
    }

    public function recordReceivedHoldings(object $issue, iterable $lines, object $transaction, object $user): void
    {
        if ($issue->source_type !== 'cutting_reserved') return;
        foreach ($lines as $line) {
            $reservation = $this->reservation((int) $line->cutting_inventory_reservation_id);
            $source = $this->source($reservation);
            $balance = $this->balance($reservation);
            $holding = DB::table('erp_material_holdings')->insertGetId([
                'material_lot_id' => $balance->material_lot_id, 'position_type' => 'PRODUCTION_WIP',
                'position_id' => $line->target_material_requirement_id, 'quantity' => $line->issue_base_qty,
                'total_cost' => $line->issue_total_cost, 'status' => 'ACTIVE', 'business_version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('erp_production_internal_issue_lines')->where('id', $line->id)->update(['material_holding_id' => $holding, 'updated_at' => now()]);
            DB::table('erp_material_movements')->insert([
                'movement_no' => $this->numbers->next('material_movement', 'MM'), 'route_id' => $source->route_id,
                'source_holding_id' => $source->warehouse_holding_id, 'target_holding_id' => $holding,
                'action' => 'INTERNAL_ISSUE', 'quantity' => $line->issue_base_qty, 'total_cost' => $line->issue_total_cost,
                'inventory_transaction_id' => $transaction->id, 'operator_legacy_id' => $this->commands->actor($user),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function reservation(int $id, bool $lock = false): object
    {
        $query = DB::table('erp_cutting_inventory_reservations')->where('id', $id);
        if ($lock) $query->lockForUpdate();
        $row = $query->first();
        if (! $row) $this->commands->fail('cutting_inventory_reservation_missing', '下料专用库存归属不存在。', 404);
        return $row;
    }

    private function targetId(object $reservation, array $payload): int
    {
        $id = filter_var($payload['target_material_requirement_id'] ?? $reservation->target_material_requirement_id, FILTER_VALIDATE_INT);
        if (! $id || $id < 1) $this->commands->fail('formal_requirement_required', '领用必须指定真实的正式目标物料需求。');
        if ($reservation->reservation_scope === 'PLAN' && $id !== (int) $reservation->target_material_requirement_id) {
            $this->commands->fail('cutting_inventory_target_mismatch', '计划专用库存只能由原目标需求领用。', 403);
        }
        return $id;
    }

    private function authorizeTarget(int $id, object $user, array $permissions, bool $super, string $permission, bool $lock = false): void
    {
        $this->commands->permission($permissions, $permission);
        $requirement = DB::table('erp_production_target_material_requirements')->where('id', $id)->first();
        if (! $requirement) $this->commands->fail('cutting_target_requirement_invalid', '正式目标物料需求不存在。', 404);
        $this->commands->workOrder((int) $requirement->work_order_id, $user, $permissions, $super, $permission, $lock);
    }

    private function authorizeReservation(object $reservation, object $user, array $permissions, bool $super, string $permission): void
    {
        $this->commands->permission($permissions, $permission);
        if ($reservation->target_material_requirement_id) {
            $this->authorizeTarget((int) $reservation->target_material_requirement_id, $user, $permissions, $super, $permission);
        } else {
            $source = $this->source($reservation);
            $this->commands->cuttingTask((int) $source->cutting_task_id, $user, $permissions, $super, $permission);
        }
    }

    private function target(int $id, bool $lock): array
    {
        $query = DB::table('erp_production_target_material_requirements')->where('id', $id);
        if ($lock) $query->lockForUpdate();
        $requirement = $query->first();
        if (! $requirement) $this->commands->fail('cutting_target_requirement_invalid', '目标物料需求不存在。', 409);
        $link = DB::table('erp_production_task_targets')->where('target_type', $requirement->target_type)->where('target_id', $requirement->target_id)->first();
        $taskQuery = ProductionTask::query();
        if ($lock) $taskQuery->lockForUpdate();
        $task = $link ? $taskQuery->find($link->task_id) : null;
        $model = match ($requirement->target_type) {
            'unit_operation' => ProductionUnitOperation::class, 'quantity_operation' => ProductionQuantityOperation::class, default => null,
        };
        $targetQuery = $model ? $model::query()->whereKey($requirement->target_id) : null;
        if ($targetQuery && $lock) $targetQuery->lockForUpdate();
        $target = $targetQuery?->first();
        if (! $task || ! $target || (int) $task->work_order_id !== (int) $requirement->work_order_id
            || (int) $target->work_order_id !== (int) $requirement->work_order_id) {
            $this->commands->fail('cutting_target_task_invalid', '正式物料需求未形成一致的真实工序任务。', 409);
        }
        return [$requirement, $task, $target];
    }

    private function assertUsable(object $reservation, object $requirement, ProductionTask $task, object $target): void
    {
        if ($reservation->status !== 'ACTIVE') $this->commands->fail('cutting_inventory_not_active', '下料专用库存已领用或释放。', 409);
        $workOrder = WorkOrder::query()->whereKey($requirement->work_order_id)->lockForUpdate()->first();
        if (! $workOrder || ! in_array($workOrder->status, ['RELEASED', 'IN_PROGRESS'], true)
            || in_array($requirement->status, ['CLOSED', 'CANCELLED'], true)
            || ! $task->assignee_user_legacy_id || ! in_array($target->status, ['WAIT_HANDOVER', 'WAIT_MATERIAL', 'READY'], true)) {
            $this->commands->fail('cutting_inventory_target_unavailable', '目标工单、物料需求或已接单工序不具备领用条件。', 409);
        }
        $source = $this->source($reservation);
        if ((int) $requirement->component_item_id !== (int) $reservation->item_id || $source->receipt_status !== 'POSTED'
            || $source->allocation_status !== 'EFFECTIVE' || $source->result_status !== 'CONFIRMED') {
            $this->commands->fail('cutting_inventory_identity_invalid', '专用库存必须来自已核算、正式入库的同物料产出。', 409);
        }
        $this->records->configuration($reservation->configuration_id, Item::findOrFail($reservation->item_id), (int) $requirement->work_order_id);
        if (! DB::table('erp_cutting_demands')->where('source_type', 'production_target_material_requirement')
            ->where('source_requirement_id', $requirement->id)->where('item_id', $reservation->item_id)
            ->where('configuration_id', $reservation->configuration_id)->where('stage_id', $source->stage_id)->where('status', 'ACTIVE')->exists()) {
            $this->commands->fail('cutting_inventory_demand_identity_invalid', '该正式需求未声明一致的配置和下料阶段，禁止仅按物料领取。', 409);
        }
    }

    private function source(object $reservation): object
    {
        $row = DB::table('erp_cutting_warehouse_receipt_allocations as allocation')
            ->join('erp_cutting_warehouse_receipts as receipt', 'receipt.id', '=', 'allocation.receipt_id')
            ->join('erp_cutting_output_allocations as output', 'output.id', '=', 'allocation.output_allocation_id')
            ->join('erp_cutting_results as result', 'result.id', '=', 'receipt.result_id')
            ->join('erp_cutting_settlement_batches as batch', 'batch.id', '=', 'result.settlement_batch_id')
            ->where('allocation.id', $reservation->receipt_allocation_id)
            ->whereColumn('output.result_id', 'receipt.result_id')->whereColumn('output.route_id', 'receipt.route_id')
            ->where('receipt.inventory_balance_id', $reservation->inventory_balance_id)
            ->where('result.item_id', $reservation->item_id)->where('result.configuration_id', $reservation->configuration_id)
            ->first(['allocation.total_cost', 'receipt.route_id', 'receipt.warehouse_holding_id', 'result.stage_id', 'batch.cutting_task_id',
                'result.material_lot_id', 'receipt.status as receipt_status', 'output.status as allocation_status', 'result.status as result_status']);
        if (! $row) $this->commands->fail('cutting_inventory_source_invalid', '专用库存入库来源不存在。', 409);
        return $row;
    }

    private function balance(object $reservation): InventoryBalance
    {
        $balance = InventoryBalance::query()->whereKey($reservation->inventory_balance_id)->lockForUpdate()->first();
        $source = $this->source($reservation);
        if (! $balance || ! $balance->material_lot_id || (int) $balance->item_id !== (int) $reservation->item_id
            || (int) $balance->material_lot_id !== (int) $source->material_lot_id) {
            $this->commands->fail('cutting_inventory_balance_invalid', '下料专用库存余额或批次身份已失效。', 409);
        }
        return $balance;
    }

    private function remaining(object $reservation): string
    { return bcsub(bcsub((string) $reservation->reserved_qty, (string) $reservation->issued_qty, 8), (string) $reservation->released_qty, 8); }

    private function pending(int $id, bool $lock = false): array
    {
        $query = DB::table('erp_production_internal_issue_lines as line')
            ->join('erp_production_internal_issue_tasks as issue', 'issue.id', '=', 'line.issue_task_id')
            ->where('line.cutting_inventory_reservation_id', $id)->whereIn('issue.status', ['WAIT_ISSUE', 'ISSUED'])->orderBy('line.id');
        if ($lock) $query->lockForUpdate();
        $rows = $query->get(['line.issue_base_qty', 'line.issue_total_cost']);
        $quantity = '0.00000000'; $cost = '0.0000';
        foreach ($rows as $row) {
            $quantity = bcadd($quantity, (string) $row->issue_base_qty, 8);
            $cost = bcadd($cost, (string) $row->issue_total_cost, 4);
        }
        return ['quantity' => $quantity, 'cost' => $cost];
    }

    private function assertShortage(object $requirement, string $quantity, ?int $excludeIssue): void
    {
        $query = DB::table('erp_production_internal_issue_lines as line')->join('erp_production_internal_issue_tasks as issue', 'issue.id', '=', 'line.issue_task_id')
            ->where('line.target_material_requirement_id', $requirement->id)->whereIn('issue.status', ['WAIT_ISSUE', 'ISSUED']);
        if ($excludeIssue) $query->where('issue.id', '!=', $excludeIssue);
        $pending = (string) $query->sum('line.issue_base_qty');
        $net = bcsub((string) $requirement->satisfied_base_qty, (string) $requirement->returned_base_qty, 8);
        $shortage = bcsub(bcsub((string) $requirement->required_base_qty, $net, 8), $pending, 8);
        if (bccomp($quantity, $shortage, 8) > 0) $this->commands->fail('cutting_target_issue_exceeded', '领用数量超过目标需求尚未满足且尚未安排领用的数量。', 409);
    }

    private function changeLock(InventoryBalance $balance, string $delta): void
    {
        $locked = bcadd((string) $balance->quantity_locked, $delta, 8);
        $location = InventoryLocationBalance::query()->where('item_id', $balance->item_id)
            ->where('warehouse_id', $balance->warehouse_id)->where('location_id', $balance->location_id)->lockForUpdate()->firstOrFail();
        $locationLocked = bcadd((string) $location->quantity_locked, $delta, 8);
        if (bccomp($locked, '0', 8) < 0 || bccomp($locked, (string) $balance->quantity_on_hand, 8) > 0
            || bccomp($locationLocked, '0', 8) < 0 || bccomp($locationLocked, (string) $location->quantity_on_hand, 8) > 0) {
            $this->commands->fail('cutting_inventory_lock_invalid', '专用库存锁定量与真实余额不一致。', 409);
        }
        $balance->quantity_locked = $locked;
        $balance->quantity_available = $this->availability->calculate((float) $balance->quantity_on_hand, (float) $locked,
            (float) $balance->quantity_defective, (float) $balance->quantity_pending);
        $balance->save();
        $location->quantity_locked = $locationLocked;
        $location->quantity_available = $this->availability->calculate((float) $location->quantity_on_hand, (float) $locationLocked,
            (float) $location->quantity_defective, (float) $location->quantity_pending);
        $location->save();
    }
}
