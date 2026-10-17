<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Item;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use App\Models\Erp\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ProductionHandoverService
{
    public function __construct(
        private readonly WorkOrderCompletionReadinessService $completionReadiness,
        private readonly ProductionTargetReadinessService $targetReadiness,
        private readonly ProductionMaterialCostService $materialCosts,
        private readonly ProductionDataScopeResolver $scopeResolver,
    ) {}

    public function pending(object $user, array $permissions, array $filters = [], bool $super = false): array
    {
        $this->permission($permissions, 'production.handover.view');
        $userId = $this->userId($user);
        $query = DB::table('erp_production_operation_handovers as handover')
            ->join('erp_production_task_targets as link', function ($join): void {
                $join->on('link.target_type', '=', 'handover.target_target_type')->on('link.target_id', '=', 'handover.target_target_id');
            })->join('erp_production_tasks as task', 'task.id', '=', 'link.task_id')
            ->leftJoin('erp_work_orders as work_order', 'work_order.id', '=', 'task.work_order_id')
            ->leftJoin('erp_items as item', 'item.id', '=', 'work_order.output_item_id')
            ->where('handover.status', 'WAIT_RECEIVE')->where('task.assignee_user_legacy_id', $userId)
            ->whereIn('task.id', $this->scopedTasks($user, $permissions, $super, 'production.handover.view')->select('erp_production_tasks.id'))
            ->select('handover.*', 'task.id as task_id', 'task.task_no');
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(fn ($q) => $q->where('handover.handover_no', 'like', "%{$keyword}%")
                ->orWhere('task.task_no', 'like', "%{$keyword}%")->orWhere('work_order.work_order_no', 'like', "%{$keyword}%")
                ->orWhere('item.item_code', 'like', "%{$keyword}%")->orWhere('item.item_name', 'like', "%{$keyword}%"));
        }
        $page = $query->orderBy('handover.handed_over_at')->orderBy('handover.id')
            ->paginate(min(20, max(1, (int) ($filters['per_page'] ?? 10))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $taskIds = collect($page->items())->pluck('task_id')->unique()->all();
        $actionTasks = [];
        foreach (['accept' => 'production.handover.receive', 'reject' => 'production.handover.reject'] as $action => $permission) {
            $ids = in_array($permission, $permissions, true) && $taskIds !== []
                ? $this->scopedTasks($user, $permissions, $super, $permission)->whereKey($taskIds)->pluck('id')->all() : [];
            $actionTasks[$action] = array_fill_keys($ids, true);
        }
        $page->setCollection(collect($page->items())->map(fn ($row) => (array) $row + ['allowed_actions' => [
            'accept' => isset($actionTasks['accept'][$row->task_id]),
            'reject' => isset($actionTasks['reject'][$row->task_id]),
        ]]));
        // Keep data as a flat array for the existing queue and todo readers.
        return $page->toArray();
    }

    private function scopedTasks(object $user, array $permissions, bool $super, string $permission): Builder
    {
        $query = ProductionTask::query();
        $scope = $this->scopeResolver->resolve($user, $permission, $permissions, $super);
        $this->scopeResolver->applyProductionTaskScope($query, $scope, $this->userId($user));
        return $query;
    }

    public function accept(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    { $this->permission($permissions, 'production.handover.receive'); return $this->decide($id, $payload, $user, $permissions, $super, true); }
    public function reject(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    { if (! in_array('production.handover.reject', $permissions, true)) $this->permission($permissions, 'production.task.claim'); return $this->decide($id, $payload, $user, $permissions, $super, false); }

    private function decide(int $id, array $payload, object $user, array $permissions, bool $super, bool $accept): array
    {
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        $type = $accept ? 'accept_handover' : 'reject_handover';
        $permission = $accept ? 'production.handover.receive' : (in_array('production.handover.reject', $permissions, true) ? 'production.handover.reject' : 'production.task.claim');
        $actor = $this->userId($user);
        $hash = hash('sha256', json_encode([$id, (int) ($payload['expected_version'] ?? 0), $payload['reason'] ?? null], JSON_UNESCAPED_UNICODE));
        // Idempotency only prevents duplicate facts. It must never preserve an
        // old receiver's authority after the target task has been reassigned.
        $this->authorizeHandover($id, $user, $permissions, $super, $permission, ! $accept);
        return DB::transaction(function () use ($id, $payload, $user, $permissions, $super, $accept, $commandId, $type, $hash, $permission, $actor): array {
            $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
            if ($existing) return $this->replay($existing, $type, $hash, $actor);
            $ledger = ProductionExecutionCommand::create(['client_command_id' => $commandId, 'command_type' => $type,
                'aggregate_type' => 'operation_handover', 'aggregate_id' => $id, 'request_hash' => $hash, 'status' => 'processing',
                'initiated_by_legacy_id' => $actor, 'processing_started_at' => now()]);
            $handover = DB::table('erp_production_operation_handovers')->where('id', $id)->lockForUpdate()->first();
            if (! $handover) $this->fail('handover_not_found', '工序交接单不存在。', 404);
            if ((int) $handover->business_version !== (int) ($payload['expected_version'] ?? 0)) $this->fail('version_conflict', '交接单版本已变化，请刷新后重试。', 409);
            if ($handover->status !== 'WAIT_RECEIVE') $this->fail('handover_already_decided', '该工序交接已经处理。', 409);
            [$task, $target] = $this->targetTask($handover->target_target_type, (int) $handover->target_target_id);
            // Keep the locked recheck: authorization may change between the
            // pre-ledger check and the business transaction acquiring its rows.
            $this->assertTargetTask($task, $user, $permissions, $super, $permission, ! $accept);
            $now = now();
            if ($accept) {
                $this->receiveLockedHandover($handover, $user, $payload['completeness'] ?? ['complete' => true], $now);
            } else {
                $reason = trim((string) ($payload['reason'] ?? ''));
                if ($reason === '') $this->fail('reject_reason_required', '拒收交接时必须填写原因。');
                DB::table('erp_production_operation_handovers')->where('id', $id)->update(['status' => 'REJECTED', 'reject_reason' => $reason,
                    'received_by_legacy_id' => $this->userId($user), 'received_at' => $now,
                    'business_version' => (int) $handover->business_version + 1, 'updated_at' => $now]);
                $this->reopenSourceForRework($handover, $reason, $user);
                // 接单前退回：下道任务还没有负责人，回到等上道工序重新产出；历史已接单的仍保持待交接确认。
                $target->status = (int) $task->assignee_user_legacy_id > 0 ? 'WAIT_HANDOVER' : 'WAIT_PREVIOUS';
            }
            if ($accept) {
                $readiness = $this->targetReadiness->refresh((string) $handover->target_target_type, $target, $task, $now);
            } else {
                $target->business_version = (int) $target->business_version + 1; $target->save();
                $task->targets()->where('target_type', $handover->target_target_type)->where('target_id', $handover->target_target_id)
                    ->update(['status_snapshot' => $target->status, 'updated_at' => $now]);
                if ((int) $task->assignee_user_legacy_id > 0) {
                    if ($task->status !== 'WAIT_HANDOVER') {
                        $task->update(['status' => 'WAIT_HANDOVER', 'business_version' => (int) $task->business_version + 1]);
                    }
                } else {
                    $offer = $task->pendingAssignment()->lockForUpdate()->first();
                    if ($offer) {
                        $offer->update(['status' => 'CANCELLED', 'active_task_id' => null, 'decided_by_legacy_id' => $actor ?: null,
                            'decided_at' => $now, 'business_version' => (int) $offer->business_version + 1]);
                    }
                    $task->update(['status' => 'WAIT_PREVIOUS', 'assignment_mode' => null, 'assignment_score_snapshot' => null,
                        'business_version' => (int) $task->business_version + 1]);
                }
                $readiness = ['target_status' => $target->status, 'target_business_version' => (int) $target->business_version];
            }
            if ($accept) {
                $sourceWorkOrder = WorkOrder::query()->lockForUpdate()->find($handover->work_order_id);
                if ($sourceWorkOrder?->source_type === 'stock_prebuild') {
                    $this->completionReadiness->refresh($sourceWorkOrder, $user, '指定工单备货产出已正式交接', $id);
                }
            }
            $result = ['id' => $id, 'status' => $accept ? 'RECEIVED' : 'REJECTED', 'target_status' => $readiness['target_status'],
                'target_business_version' => $readiness['target_business_version'], 'handled_at' => $now->toISOString()];
            $ledger->update(['result_type' => 'operation_handover', 'result_id' => $id, 'response_snapshot' => $result,
                'status' => 'succeeded', 'processing_finished_at' => now()]);
            return $result;
        }, 5);
    }

    private function reopenSourceForRework(object $handover, string $reason, object $user): void
    {
        $source = $this->target($handover->source_target_type, (int) $handover->source_target_id, true);
        $output = DB::table('erp_production_output_records')->where('id', $handover->output_record_id)->lockForUpdate()->first();
        if (! $output) $this->fail('handover_output_missing', '交接关联的生产产出不存在，禁止丢失返工数量。', 409);

        $beforeStatus = (string) $source->status;
        $beforeVersion = (int) $source->business_version;
        if ($handover->source_target_type === 'quantity_operation') {
            $source->completed_base_qty = max(0, (float) $source->completed_base_qty - (float) $output->output_base_qty);
            $source->remaining_base_qty = (float) $source->remaining_base_qty + (float) $output->output_base_qty;
        }
        $source->status = 'REWORK';
        $source->completed_at = null;
        $source->business_version = $beforeVersion + 1;
        $source->save();

        DB::table('erp_production_output_records')->where('id', $output->id)->update([
            'status' => 'HANDOVER_REJECTED',
            'business_version' => (int) $output->business_version + 1,
            'updated_at' => now(),
        ]);
        DB::table('erp_production_execution_events')->insert([
            'aggregate_type' => $handover->source_target_type,
            'aggregate_id' => $source->id,
            'action' => 'handover_rework',
            'before_status' => $beforeStatus,
            'after_status' => 'REWORK',
            'before_version' => $beforeVersion,
            'after_version' => (int) $source->business_version,
            'fact_snapshot' => json_encode([
                'handover_id' => (int) $handover->id,
                'output_record_id' => (int) $output->id,
                'rework_base_qty' => (float) $output->output_base_qty,
                'reason' => $reason,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'operator_legacy_id' => $this->userId($user),
            'operator_name' => $user->nickname ?? $user->username ?? null,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $sourceLink = DB::table('erp_production_task_targets')->where('target_type', $handover->source_target_type)
            ->where('target_id', $source->id)->lockForUpdate()->first();
        if (! $sourceLink) $this->fail('source_task_not_found', '上游生产任务不存在，无法进入返工。', 409);
        DB::table('erp_production_task_targets')->where('id', $sourceLink->id)->update([
            'status_snapshot' => 'REWORK', 'updated_at' => now(),
        ]);
        DB::table('erp_production_tasks')->where('id', $sourceLink->task_id)->update([
            'status' => 'REWORK', 'business_version' => DB::raw('business_version + 1'), 'updated_at' => now(),
        ]);
    }

    private function acceptTargetMaterial(object $handover): string
    {
        $requirementId = $handover->target_material_requirement_id;
        if (! $requirementId) {
            $quantity = (string) (DB::table('erp_production_output_records')->where('id', $handover->output_record_id)->value('output_base_qty') ?? '0');
            if (bccomp($quantity, '0', 8) <= 0) $this->fail('handover_output_quantity_invalid', '工序交接缺少真实产出数量。', 409);
            return $quantity;
        }
        $requirement = DB::table('erp_production_target_material_requirements')->where('id', $requirementId)->lockForUpdate()->first();
        if (! $requirement || $requirement->target_type !== $handover->target_target_type || (int) $requirement->target_id !== (int) $handover->target_target_id) {
            $this->fail('handover_material_requirement_invalid', '工序交接绑定的目标物料需求无效，禁止接收。', 409);
        }
        $outputQty = (string) (DB::table('erp_production_output_records')->where('id', $handover->output_record_id)->value('output_base_qty') ?? '0');
        $netSatisfied = bcsub((string) $requirement->satisfied_base_qty, (string) $requirement->returned_base_qty, 8);
        if (bccomp($netSatisfied, '0', 8) < 0) $netSatisfied = '0';
        $shortage = bcsub((string) $requirement->required_base_qty, $netSatisfied, 8);
        if (bccomp($shortage, '0', 8) < 0) $shortage = '0';
        $accepted = bccomp($outputQty, $shortage, 8) <= 0 ? $outputQty : $shortage;
        if (bccomp($accepted, '0', 8) <= 0) return '0';
        DB::table('erp_production_target_material_requirements')->where('id', $requirement->id)->update([
            'satisfied_base_qty' => bcadd((string) $requirement->satisfied_base_qty, $accepted, 8),
            'status' => bccomp(bcadd($accepted, $netSatisfied, 8), (string) $requirement->required_base_qty, 8) >= 0 ? 'SATISFIED' : 'PARTIAL',
            'business_version' => (int) $requirement->business_version + 1, 'updated_at' => now(),
        ]);
        return $accepted;
    }

    private function targetTask(string $type, int $id, bool $lock = true): array
    {
        $link = DB::table('erp_production_task_targets')->where('target_type', $type)->where('target_id', $id)->first();
        $taskQuery = ProductionTask::query();
        if ($lock) $taskQuery->lockForUpdate();
        $task = $link ? $taskQuery->find($link->task_id) : null;
        if (! $task) $this->fail('target_task_not_found', '下一工序尚未形成有效生产任务。', 409);
        return [$task, $this->target($type, $id, $lock)];
    }
    private function target(string $type, int $id, bool $lock): object
    {
        $model = $type === 'unit_operation' ? ProductionUnitOperation::class : ($type === 'quantity_operation' ? ProductionQuantityOperation::class : null);
        if (! $model) $this->fail('task_target_invalid', '生产执行目标类型无效。');
        $query = $model::query(); if ($lock) $query->lockForUpdate();
        return $query->findOrFail($id);
    }
    private function authorizeHandover(int $id, object $user, array $permissions, bool $super, string $permission, bool $allowUnclaimed = false): void
    {
        $handover = DB::table('erp_production_operation_handovers')->where('id', $id)->first();
        if (! $handover) $this->fail('handover_not_found', '工序交接单不存在。', 404);
        [$task] = $this->targetTask((string) $handover->target_target_type, (int) $handover->target_target_id, false);
        $this->assertTargetTask($task, $user, $permissions, $super, $permission, $allowUnclaimed);
    }

    /**
     * 历史已接单的交接由当前负责人处理；“接单前退回”允许有接单权限的人（可接任务，或自己被派单但尚未接受）直接退回。
     */
    private function assertTargetTask(ProductionTask $task, object $user, array $permissions, bool $super, string $permission, bool $allowUnclaimed = false): void
    {
        $actor = $this->userId($user);
        $isAssignee = (int) $task->assignee_user_legacy_id === $actor;
        $unclaimedAllowed = false;
        if ($allowUnclaimed && ! $isAssignee && ! (int) $task->assignee_user_legacy_id && in_array('production.task.claim', $permissions, true)) {
            if ($task->status === 'WAIT_CLAIM') {
                $unclaimedAllowed = true;
            } elseif ($task->status === 'WAIT_ACCEPT') {
                $offer = $task->pendingAssignment()->first();
                $unclaimedAllowed = $offer && (int) $offer->offered_to_legacy_id === $actor;
            }
        }
        if (! $isAssignee && ! $unclaimedAllowed) {
            $this->fail('expected_receiver_required', '只有下一工序的接单人，或有权接该任务的人可以处理。', 403);
        }
        $scopePermission = $isAssignee ? $permission : 'production.task.claim';
        $visible = ProductionTask::query()->whereKey($task->id);
        $scope = $this->scopeResolver->resolve($user, $scopePermission, $permissions, $super);
        $this->scopeResolver->applyProductionTaskScope($visible, $scope, $actor);
        if (! $visible->exists()) $this->fail('data_scope_denied', '该接收任务不在当前生产数据范围内。', 403);
    }

    /**
     * 接单即验收：由接单事务调用。对该执行对象所有“待接收”的上道工序交接自动确认接收，
     * 近似于以前的“接收交接”（计入物料成本、满足下道物料需求），但不再需要单独点击。
     * 调用方必须已锁定目标行与任务行，并在所有目标状态都更新后再调用 refreshAfterClaim。
     *
     * @return int 自动接收的交接条数
     */
    public function receiveOnClaim(string $targetType, object $target, object $user): int
    {
        $handovers = DB::table('erp_production_operation_handovers')
            ->where('target_target_type', $targetType)->where('target_target_id', $target->id)
            ->where('status', 'WAIT_RECEIVE')->orderBy('id')->lockForUpdate()->get();
        $now = now();
        foreach ($handovers as $handover) {
            $acceptedQty = $this->receiveLockedHandover($handover, $user, ['complete' => true, 'received_on' => 'task_claim'], $now);
            DB::table('erp_production_execution_events')->insert([
                'aggregate_type' => $targetType, 'aggregate_id' => (int) $target->id, 'action' => 'handover_received_on_claim',
                'before_status' => (string) $target->status, 'after_status' => (string) $target->status,
                'before_version' => (int) $target->business_version, 'after_version' => (int) $target->business_version,
                'fact_snapshot' => json_encode(['handover_id' => (int) $handover->id, 'handover_no' => $handover->handover_no,
                    'output_record_id' => (int) $handover->output_record_id, 'accepted_base_qty' => $acceptedQty], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'operator_legacy_id' => $this->userId($user), 'operator_name' => $user->nickname ?? $user->username ?? null,
                'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $sourceWorkOrder = WorkOrder::query()->lockForUpdate()->find($handover->work_order_id);
            if ($sourceWorkOrder?->source_type === 'stock_prebuild') {
                $this->completionReadiness->refresh($sourceWorkOrder, $user, '指定工单备货产出已正式交接', (int) $handover->id);
            }
        }
        return $handovers->count();
    }

    /** 接单事务中所有目标都已写入状态后，重算预备状态（物料、齐套、下料交接等仍以事实为准）。 */
    public function refreshAfterClaim(string $targetType, object $target, ProductionTask $task): void
    {
        $this->targetReadiness->refresh($targetType, $target, $task, now());
    }

    /** 把一条已锁定的 WAIT_RECEIVE 交接写成已接收，供“接单即验收”和历史手动接收共用。 */
    private function receiveLockedHandover(object $handover, object $user, array $completeness, $now): string
    {
        $output = DB::table('erp_production_output_records')->where('id', $handover->output_record_id)->lockForUpdate()->first();
        if (! $output) $this->fail('handover_output_missing', '交接关联的生产产出不存在，禁止接收。', 409);
        if (! app(ProductionCuttingComponentService::class)->isOutput($output)) {
            $item = Item::query()->lockForUpdate()->find($output->output_item_id);
            if (! $item) $this->fail('handover_output_missing', '交接关联的产出物料不存在，禁止接收。', 409);
            app(ItemManagementScopeService::class)->assertProductionAllowed($item, 'output_item_id');
        }
        $acceptedQty = $this->acceptTargetMaterial($handover);
        $this->materialCosts->acceptHandover($handover, $acceptedQty, $this->userId($user));
        DB::table('erp_production_operation_handovers')->where('id', $handover->id)->update(['status' => 'RECEIVED',
            'received_by_legacy_id' => $this->userId($user),
            'received_at' => $now, 'completeness_snapshot' => json_encode($completeness, JSON_UNESCAPED_UNICODE),
            'accepted_base_qty' => $acceptedQty,
            'business_version' => (int) $handover->business_version + 1, 'updated_at' => $now]);
        return (string) $acceptedQty;
    }

    private function replay(ProductionExecutionCommand $command, string $type, string $hash, int $actor): array
    {
        if ((int) $command->initiated_by_legacy_id !== $actor) {
            $this->fail('command_actor_mismatch', '该 client_command_id 属于其他操作者，不能恢复其历史结果。', 403);
        }
        if ($command->command_type !== $type || $command->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
        if ($command->status !== 'succeeded' || ! is_array($command->response_snapshot)) $this->fail('command_processing', '相同命令正在处理中，请稍后重试。', 409);
        return $command->response_snapshot;
    }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
