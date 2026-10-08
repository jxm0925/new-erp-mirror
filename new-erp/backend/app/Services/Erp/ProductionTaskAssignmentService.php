<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{ProductionExecutionCommand, ProductionQuantityOperation, ProductionTask, ProductionTaskAssignment, ProductionUnitOperation, WorkOrder};
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ProductionTaskAssignmentService
{
    public function __construct(
        private readonly ProductionTaskEfficiencyService $efficiency,
        private readonly ProductionDataScopeResolver $scopes,
        private readonly ErpUserProjectionService $users,
        private readonly ProductionFinancialProjectionService $financial,
    ) {}

    public function claim(int $taskId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.task.claim');
        foreach (['user_id', 'assignee_user_id', 'assignee_user_legacy_id'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) $this->fail('assignee_spoofing_forbidden', '接单人只能取当前登录用户。');
        }
        $this->visibleTask($taskId, $user, $permissions, $super, 'production.task.claim');
        return $this->command('claim_task', $taskId, $payload, $user, function (ProductionTask $task) use ($payload, $user): array {
            $this->taskVersion($task, $payload['expected_version']);
            if ($task->status !== 'WAIT_CLAIM' || $task->assignee_user_legacy_id || $task->pendingAssignment()->exists()) {
                $this->fail('task_already_claimed', '该任务已接单或有待接受派单，请刷新。', 409);
            }
            return $this->commitClaim($task, $user, 'manual_claim', null);
        });
    }

    public function candidates(int $taskId, array $filters, object $user, array $permissions, bool $super = false): LengthAwarePaginator
    {
        $this->permission($permissions, 'production.assignment.auto');
        $task = $this->visibleTask($taskId, $user, $permissions, $super, 'production.assignment.auto');
        $rows = $this->efficiency->candidates($task);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $rows = $rows->filter(fn ($row) => str_contains(mb_strtolower(($row['employee']['display_name'] ?? '').' '.$row['employee_legacy_id']), mb_strtolower($keyword)))->values();
        }
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 20))); $page = max(1, (int) ($filters['page'] ?? 1));
        return new LengthAwarePaginator($this->financial->redact($rows->slice(($page - 1) * $perPage, $perPage)->values()->all()), $rows->count(), $perPage, $page);
    }

    public function autoAssign(int $taskId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.assignment.auto');
        $this->visibleTask($taskId, $user, $permissions, $super, 'production.assignment.auto');
        return $this->command('offer_fastest_assignment', $taskId, $payload, $user, function (ProductionTask $task) use ($payload, $user): array {
            $this->taskVersion($task, $payload['expected_version']);
            if ($task->status !== 'WAIT_CLAIM' || $task->assignee_user_legacy_id) $this->fail('task_not_assignable', '只有待接单的任务可以发起效率派单。', 409);
            return $this->offerLocked($task, $user) ?? ['task_id' => (int) $task->id, 'task_business_version' => (int) $task->business_version,
                'status' => 'WAIT_CLAIM', 'assignment' => null, 'reason_code' => 'no_comparable_history', 'message' => '没有可比较的独立合格历史，请手动接单。'];
        });
    }

    /** Formal readiness transitions may offer work; they never claim or start it. */
    public function tryOfferReadyTask(ProductionTask $task, ?object $actor = null): ?array
    {
        if (! $task->auto_assignment_enabled_snapshot || $task->active_job_bundle_id) return null;
        return DB::transaction(function () use ($task, $actor): ?array {
            $locked = ProductionTask::query()->lockForUpdate()->find($task->id);
            if (! $locked || ! $locked->auto_assignment_enabled_snapshot || $locked->status !== 'WAIT_CLAIM' || $locked->assignee_user_legacy_id) return null;
            return $this->offerLocked($locked, $actor ?? (object) ['legacy_id' => 0, 'nickname' => '系统自动派单']);
        }, 5);
    }

    public function assignments(array $filters, object $user, array $permissions, bool $super = false): LengthAwarePaginator
    {
        $this->permission($permissions, 'production.task.view');
        $view = $filters['view'] ?? 'mine';
        if ($view === 'all') $this->permission($permissions, 'production.assignment.auto');
        $query = ProductionTaskAssignment::query()->with(['task.workOrder.outputItem']);
        if ($view !== 'all') $query->where('offered_to_legacy_id', $this->userId($user));
        else {
            $tasks = ProductionTask::query()->select('id');
            $this->scopes->applyProductionTaskScope($tasks, $this->scopes->resolve($user, 'production.assignment.auto', $permissions, $super), $this->userId($user));
            $query->whereIn('task_id', $tasks);
        }
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['task_id'])) $query->where('task_id', (int) $filters['task_id']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->whereHas('task', fn ($task) => $task->where('task_no', 'like', "%{$keyword}%")
            ->orWhere('operation_name_snapshot', 'like', "%{$keyword}%")->orWhereHas('workOrder', fn ($wo) => $wo->where('work_order_no', 'like', "%{$keyword}%")));
        $page = $query->orderByDesc('id')->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))));
        $page->setCollection($page->getCollection()->map(fn ($offer) => $this->assignmentProjection($offer, $user, $permissions)));
        return $page;
    }

    public function accept(int $assignmentId, array $payload, object $user, array $permissions): array
    { return $this->decide($assignmentId, $payload, $user, $permissions, true); }

    public function reject(int $assignmentId, array $payload, object $user, array $permissions): array
    { return $this->decide($assignmentId, $payload, $user, $permissions, false); }

    public function cancelPendingForWorkOrder(int $workOrderId, ?object $actor = null): void
    {
        DB::transaction(function () use ($workOrderId, $actor): void {
            $actor ??= (object) ['legacy_id' => 0, 'nickname' => '系统'];
            foreach (ProductionTask::query()->where('work_order_id', $workOrderId)->orderBy('id')->lockForUpdate()->get() as $task) {
                $offer = $task->pendingAssignment()->lockForUpdate()->first();
                if (! $offer) continue;
                $offer->update(['status' => 'CANCELLED', 'active_task_id' => null, 'decided_by_legacy_id' => $this->userId($actor) ?: null,
                    'decided_at' => now(), 'business_version' => (int) $offer->business_version + 1]);
                $this->event($task, 'assignment_cancelled', $task->status, $task->status, (int) $task->business_version, ['assignment_id' => $offer->id], $actor);
            }
        }, 5);
    }

    private function decide(int $id, array $payload, object $user, array $permissions, bool $accept): array
    {
        $this->permission($permissions, 'production.task.claim');
        $source = ProductionTaskAssignment::find($id);
        if (! $source || (int) $source->offered_to_legacy_id !== $this->userId($user)) $this->fail('assignment_recipient_required', '只有被派单人可以接受或拒绝该派单。', 403);
        if (! $accept && mb_strlen(trim((string) ($payload['reason'] ?? ''))) > 500) $this->fail('rejection_reason_too_long', '拒绝原因不能超过500字。');
        return $this->command($accept ? 'accept_task_assignment' : 'reject_task_assignment', (int) $source->task_id,
            array_merge($payload, ['assignment_id' => $id]), $user, function (ProductionTask $task) use ($id, $payload, $user, $permissions, $accept): array {
                $offer = ProductionTaskAssignment::query()->lockForUpdate()->findOrFail($id);
                if ((int) $offer->offered_to_legacy_id !== $this->userId($user)) $this->fail('assignment_recipient_required', '只有被派单人可以处理该派单。', 403);
                if ((int) $offer->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '派单记录已变化，请刷新。', 409);
                $this->taskVersion($task, $payload['expected_task_version']);
                if ($offer->status !== 'PENDING' || (int) $offer->active_task_id !== (int) $task->id || $task->status !== 'WAIT_ACCEPT' || $task->assignee_user_legacy_id) {
                    $this->fail('assignment_not_pending', '该派单已处理或任务状态已变化。', 409);
                }
                $offer->fill(['status' => $accept ? 'ACCEPTED' : 'REJECTED', 'active_task_id' => null,
                    'decided_by_legacy_id' => $this->userId($user), 'decided_at' => now(),
                    'rejection_reason' => $accept ? null : (trim((string) ($payload['reason'] ?? '')) ?: null),
                    'business_version' => (int) $offer->business_version + 1])->save();
                if ($accept) {
                    if (! $this->eligibleAccount($this->userId($user))) $this->fail('assignee_unavailable', '当前账号已停用或不具备接单权限。', 403);
                    $result = $this->commitClaim($task, $user, 'fastest_offer_accepted', $offer->score_snapshot);
                } else {
                    $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($task->work_order_id);
                    if (! in_array($workOrder->status, ['RELEASED', 'IN_PROGRESS'], true)) $this->fail('work_order_not_executable', '工单已取消或尚未发布。', 409);
                    $version = (int) $task->business_version;
                    $task->update(['status' => 'WAIT_CLAIM', 'assignment_mode' => null, 'assignment_score_snapshot' => null, 'business_version' => $version + 1]);
                    $this->event($task, 'assignment_rejected', 'WAIT_ACCEPT', 'WAIT_CLAIM', $version,
                        ['assignment_id' => $offer->id, 'recipient_legacy_id' => $this->userId($user), 'reason' => $offer->rejection_reason], $user);
                    $result = $this->taskProjection($task);
                }
                $result['assignment'] = $this->assignmentProjection($offer->fresh(), $user, $permissions);
                return $result;
            });
    }

    private function offerLocked(ProductionTask $task, object $actor): ?array
    {
        $task->loadMissing(['targets']);
        $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($task->work_order_id);
        if (! in_array($workOrder->status, ['RELEASED', 'IN_PROGRESS'], true) || $task->targets->isEmpty() || $task->pendingAssignment()->exists()) return null;
        foreach ($task->targets as $link) if ($this->lockTarget($link->target_type, (int) $link->target_id)->status !== 'WAIT_CLAIM') return null;
        $task->setRelation('workOrder', $workOrder);
        $score = $this->efficiency->candidates($task)->first();
        if (! $score) return null;
        $version = (int) $task->business_version;
        $offer = ProductionTaskAssignment::create(['task_id' => $task->id, 'active_task_id' => $task->id,
            'offered_to_legacy_id' => $score['employee_legacy_id'], 'status' => 'PENDING',
            'algorithm_version' => ProductionTaskEfficiencyService::ALGORITHM_VERSION, 'score_snapshot' => $score,
            'offered_by_legacy_id' => $this->userId($actor) ?: null, 'offered_at' => now(), 'business_version' => 1]);
        $task->update(['status' => 'WAIT_ACCEPT', 'assignment_mode' => 'fastest_offer', 'assignment_score_snapshot' => $score, 'business_version' => $version + 1]);
        $this->event($task, 'assignment_offered', 'WAIT_CLAIM', 'WAIT_ACCEPT', $version,
            ['assignment_id' => $offer->id, 'offered_to_legacy_id' => $offer->offered_to_legacy_id, 'algorithm_version' => $offer->algorithm_version, 'score_snapshot' => $score], $actor);
        return ['task_id' => (int) $task->id, 'task_business_version' => (int) $task->business_version, 'status' => 'WAIT_ACCEPT',
            'assignment' => $this->assignmentProjection($offer, $actor, [])];
    }

    private function commitClaim(ProductionTask $task, object $user, string $mode, ?array $score): array
    {
        $userId = $this->userId($user); $version = (int) $task->business_version; $before = $task->status; $now = now();
        $task->loadMissing(['targets']);
        if ($task->targets->isEmpty()) $this->fail('task_target_invalid', '任务没有可执行的生产目标。', 409);
        $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($task->work_order_id);
        if (! in_array($workOrder->status, ['RELEASED', 'IN_PROGRESS'], true)) $this->fail('work_order_not_executable', '工单已取消或尚未发布，不能接单。', 409);
        $task->fill(['assignee_user_legacy_id' => $userId, 'claimed_at' => $now, 'status' => 'CLAIMED',
            'assignment_mode' => $mode, 'assignment_score_snapshot' => $score, 'business_version' => $version + 1])->save();
        foreach ($task->targets as $link) {
            $target = $this->lockTarget($link->target_type, (int) $link->target_id);
            if ($target->status !== 'WAIT_CLAIM' || $target->responsible_user_legacy_id) $this->fail('target_state_conflict', '生产目标已接单或已推进，请刷新。', 409);
            $status = $this->statusAfterClaim($link->target_type, $target);
            $target->fill(['responsible_user_legacy_id' => $userId, 'claimed_at' => $now, 'status' => $status,
                'business_version' => (int) $target->business_version + 1])->save();
            $link->update(['status_snapshot' => $status]);
        }
        if (! $workOrder->responsible_user_legacy_id) $workOrder->update(['responsible_user_legacy_id' => $userId, 'business_version' => (int) $workOrder->business_version + 1]);
        $task->collaborators()->create(['employee_legacy_id' => $userId, 'role' => 'owner', 'responsibility_weight' => 1, 'joined_at' => $now, 'business_version' => 1]);
        $this->event($task, $mode === 'manual_claim' ? 'claim' : 'assignment_accepted', $before, 'CLAIMED', $version,
            ['assignment_mode' => $mode, 'assignment_score_snapshot' => $score], $user);
        return $this->taskProjection($task->fresh(['targets']));
    }

    private function statusAfterClaim(string $type, object $target): string
    {
        if ($target->kitting_required && ! $target->kitting_confirmed_at) return 'WAIT_MATERIAL';
        if (DB::table('erp_production_internal_issue_tasks')->where('target_type', $type)->where('target_id', $target->id)->whereIn('status', ['WAIT_ISSUE', 'ISSUED'])->exists()) return 'WAIT_MATERIAL';
        return DB::table('erp_production_operation_handovers')->where('target_target_type', $type)->where('target_target_id', $target->id)->where('status', 'WAIT_RECEIVE')->exists() ? 'WAIT_HANDOVER' : 'READY';
    }

    private function command(string $type, int $taskId, array $payload, object $user, callable $action): array
    {
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '') $this->fail('client_command_id_required', '写操作必须提供 client_command_id。');
        $hashPayload = $payload; ksort($hashPayload);
        $hash = hash('sha256', json_encode([$taskId, $this->userId($user), $hashPayload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        try {
            return DB::transaction(function () use ($type, $taskId, $commandId, $hash, $user, $action): array {
                // All assignment decisions lock the same task before looking up the ledger and active offer slot.
                $task = ProductionTask::query()->lockForUpdate()->find($taskId);
                if (! $task) $this->fail('task_not_found', '生产任务不存在。', 404);
                ProductionJobBundleExecutionContext::assertTask($task);
                $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->first();
                if ($existing) return $this->replay($existing, $type, $hash);
                $ledger = ProductionExecutionCommand::create(['client_command_id' => $commandId, 'command_type' => $type,
                    'aggregate_type' => 'production_task', 'aggregate_id' => $taskId, 'request_hash' => $hash,
                    'status' => 'processing', 'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now()]);
                $result = $this->financial->redact($action($task));
                $ledger->update(['result_type' => 'production_task', 'result_id' => $taskId, 'response_snapshot' => $result, 'status' => 'succeeded', 'processing_finished_at' => now()]);
                return $result;
            }, 5);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            $existing = ProductionExecutionCommand::where('client_command_id', $commandId)->first();
            if (! $existing) throw $e;
            return $this->replay($existing, $type, $hash);
        }
    }

    private function replay(ProductionExecutionCommand $command, string $type, string $hash): array
    {
        if ($command->command_type !== $type || $command->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
        if ($command->status !== 'succeeded' || ! is_array($command->response_snapshot)) $this->fail('command_processing', '相同命令尚未完成，请继续原操作。', 409);
        return $this->financial->redact($command->response_snapshot);
    }

    private function visibleTask(int $id, object $user, array $permissions, bool $super, string $permission): ProductionTask
    {
        $query = ProductionTask::query()->whereKey($id);
        $this->scopes->applyProductionTaskScope($query, $this->scopes->resolve($user, $permission, $permissions, $super), $this->userId($user));
        $task = $query->first();
        if (! $task) $this->fail('task_not_found', '生产任务不存在或不在当前数据范围内。', 404);
        return $task;
    }

    private function eligibleAccount(int $id): bool
    {
        return DB::table('erp_legacy_admin_users as person')->where('person.legacy_id', $id)->where('person.status', 'normal')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_rbac_user_roles as ur')->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
                ->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')
                ->whereColumn('ur.user_legacy_id', 'person.legacy_id')->where('r.enabled', true)->where('p.enabled', true)->where('p.code', 'production.task.claim'))->exists();
    }

    private function assignmentProjection(ProductionTaskAssignment $offer, object $user, array $permissions): array
    {
        $offer->loadMissing(['task.workOrder.outputItem']); $task = $offer->task; $wo = $task?->workOrder;
        $canDecide = $offer->status === 'PENDING' && $task?->status === 'WAIT_ACCEPT' && ! $task->assignee_user_legacy_id
            && (int) $offer->offered_to_legacy_id === $this->userId($user) && in_array('production.task.claim', $permissions, true);
        return $this->financial->redact([
            'id' => (int) $offer->id, 'task_id' => (int) $offer->task_id, 'task_no' => $task?->task_no,
            'work_order_no' => $wo?->work_order_no, 'item_name' => $wo?->outputItem?->item_name,
            'operation_name' => $task?->operation_name_snapshot, 'status' => $offer->status,
            'offered_to_legacy_id' => (int) $offer->offered_to_legacy_id, 'offered_to' => $this->users->one($offer->offered_to_legacy_id),
            'offered_at' => $offer->offered_at?->toISOString(), 'decided_at' => $offer->decided_at?->toISOString(),
            'decided_by_legacy_id' => $offer->decided_by_legacy_id, 'rejection_reason' => $offer->rejection_reason,
            'algorithm_version' => $offer->algorithm_version, 'score_snapshot' => $offer->score_snapshot,
            'business_version' => (int) $offer->business_version, 'task_business_version' => (int) ($task?->business_version ?? 0),
            'allowed_actions' => ['accept' => $canDecide, 'reject' => $canDecide],
        ]);
    }

    private function taskProjection(ProductionTask $task): array
    {
        $task->loadMissing('targets');
        return $this->financial->redact(['id' => (int) $task->id, 'task_no' => $task->task_no, 'work_order_id' => (int) $task->work_order_id,
            'status' => $task->status, 'assignee_user_legacy_id' => $task->assignee_user_legacy_id ? (int) $task->assignee_user_legacy_id : null,
            'assignment_mode' => $task->assignment_mode, 'assignment_score_snapshot' => $task->assignment_score_snapshot,
            'claimed_at' => $task->claimed_at?->toISOString(), 'business_version' => (int) $task->business_version,
            'targets' => $task->targets->map(fn ($link) => ['id' => (int) $link->id, 'target_type' => $link->target_type, 'target_id' => (int) $link->target_id, 'status' => $link->status_snapshot])->values()->all()]);
    }

    private function event(ProductionTask $task, string $action, string $before, string $after, int $beforeVersion, array $snapshot, object $user): void
    {
        DB::table('erp_production_execution_events')->insert(['aggregate_type' => 'production_task', 'aggregate_id' => $task->id, 'action' => $action,
            'before_status' => $before, 'after_status' => $after, 'before_version' => $beforeVersion, 'after_version' => (int) $task->business_version,
            'fact_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'operator_legacy_id' => $this->userId($user),
            'operator_name' => $user->nickname ?? $user->username ?? null, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function taskVersion(ProductionTask $task, mixed $version): void
    { if ((int) $task->business_version !== (int) $version) $this->fail('version_conflict', '任务版本已变化，请刷新。', 409, ['current_version' => (int) $task->business_version]); }
    private function lockTarget(string $type, int $id): object
    { $model = match ($type) { 'unit_operation' => ProductionUnitOperation::class, 'quantity_operation' => ProductionQuantityOperation::class, default => $this->fail('task_target_invalid', '任务目标类型无效。', 409) }; return $model::query()->lockForUpdate()->findOrFail($id); }
    private function permission(array $permissions, string $code): void
    { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403, ['permission' => $code]); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never { throw new WorkOrderDomainException($code, $message, $status, $details); }
}
