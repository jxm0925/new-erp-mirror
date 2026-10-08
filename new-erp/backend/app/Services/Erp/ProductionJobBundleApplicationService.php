<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{ProductionExecutionCommand, ProductionJobBundle, ProductionJobBundleLine, ProductionTask, WorkOrder};
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ProductionJobBundleApplicationService
{
    public function __construct(private readonly DocumentNumberService $numbers,
        private readonly ProductionJobBundleQueryService $queries, private readonly ProductionJobBundleCompatibilityService $compatibility,
        private readonly ProductionJobBundleLaborService $labor, private readonly ProductionExecutionActionService $actions,
        private readonly ProductionReportService $reports, private readonly ProductionTaskAssignmentService $assignments) {}

    public function create(array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.work_order.edit');
        $this->permission($permissions, 'production.task.view');
        $rows = $payload['tasks'] ?? [];
        if (! is_array($rows) || count($rows) < 2 || count($rows) > 20) $this->fail('bundle_task_count_invalid', '共同加工必须选择2至20条明确的原任务。');
        $ids = array_map(fn ($row) => (int) ($row['task_id'] ?? 0), $rows);
        if (count(array_unique($ids)) !== count($ids) || min($ids) <= 0) $this->fail('bundle_task_duplicate', '不能重复选择任务或使用无效任务。');
        $this->queries->assertCandidateScope($ids, $user, $permissions, $super);
        return $this->command('create_job_bundle', null, $payload, $user, $permissions, $super, function () use ($payload, $rows, $ids, $user, $permissions): ProductionJobBundle {
            WorkOrder::query()->whereIn('id', ProductionTask::query()->whereIn('id', $ids)->select('work_order_id'))->orderBy('id')->lockForUpdate()->get();
            $tasks = ProductionTask::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($tasks->count() !== count($ids)) $this->fail('bundle_task_not_found', '所选任务已经变化，请刷新。', 409);
            $expected = collect($rows)->keyBy('task_id'); $factsByTask = []; $key = null; $owners = [];
            foreach ($tasks as $task) {
                if ((int) $task->business_version !== (int) ($expected[$task->id]['expected_task_version'] ?? 0)) $this->fail('version_conflict', '所选原任务版本已变化，请刷新。', 409, ['task_id' => (int) $task->id]);
                $this->compatibility->target($task, true);
                $facts = $this->compatibility->inspect($task);
                if ($key !== null && $key !== $facts['compatibility_key']) $this->fail('bundle_tasks_incompatible', '所选任务的工序档案、加工方式、工位设备或冻结工艺参数不兼容。', 409, ['task_id' => (int) $task->id]);
                $key = $facts['compatibility_key']; $factsByTask[(int) $task->id] = $facts;
                if ($task->assignee_user_legacy_id) $owners[] = (int) $task->assignee_user_legacy_id;
            }
            $owners = array_values(array_unique($owners));
            if (count($owners) > 1) $this->fail('bundle_task_owners_incompatible', '已有不同负责人的任务不能组合为同一人员作业。', 409);
            $first = $factsByTask[(int) $tasks->first()->id];
            $bundle = ProductionJobBundle::create(['bundle_no' => $this->numbers->next('production_job_bundle', 'PJB'),
                'title' => trim((string) ($payload['title'] ?? '')) ?: null, 'status' => 'WAIT_CLAIM',
                'operation_id_snapshot' => $first['target']->operation_id_snapshot,
                'operation_code_snapshot' => $first['target']->operation_code_snapshot, 'operation_name_snapshot' => $first['target']->operation_name_snapshot,
                'compatibility_key' => $key, 'compatibility_snapshot' => $first['compatibility_snapshot'],
                'assignee_user_legacy_id' => $owners[0] ?? null, 'created_by_legacy_id' => $this->userId($user),
                'organization_code' => $tasks->first()->organization_code, 'business_version' => 1]);
            foreach ($tasks as $task) {
                $facts = $factsByTask[(int) $task->id];
                $snapshot = $this->queries->taskProjection($task, $facts['target_type'], $facts['target']);
                $bundle->lines()->create(['task_id' => $task->id, 'active_task_id' => $task->id, 'work_order_id' => $task->work_order_id,
                    'target_type' => $facts['target_type'], 'target_id' => $facts['target']->id,
                    'standard_weight_minutes_snapshot' => $facts['standard_weight_minutes'], 'source_snapshot' => $snapshot,
                    'material_snapshot' => $facts['materials'], 'status' => 'PENDING']);
                $task->update(['active_job_bundle_id' => $bundle->id, 'business_version' => (int) $task->business_version + 1]);
            }
            return $bundle;
        });
    }

    public function claim(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.task.claim');
        return $this->command('claim_job_bundle', $id, $payload, $user, $permissions, $super, function ($bundle) use ($payload, $user, $permissions, $super) {
            if ($bundle->status !== 'WAIT_CLAIM') $this->fail('bundle_not_claimable', '该共同加工作业已经接单或停止。', 409);
            if ($bundle->assignee_user_legacy_id && (int) $bundle->assignee_user_legacy_id !== $this->userId($user)) $this->fail('bundle_owner_required', '该作业已有原任务负责人，只能由本人接单。', 403);
            $employee = DB::table('erp_legacy_admin_users')->where('legacy_id', $this->userId($user))->lockForUpdate()->first();
            if (! $employee || $employee->status !== 'normal') $this->fail('assignee_unavailable', '当前员工账号不可用。', 403);
            ProductionJobBundleExecutionContext::run((int) $bundle->id, function () use ($bundle, $payload, $user, $permissions, $super) {
                foreach ($bundle->lines as $line) {
                    $task = $line->task;
                    if (! $task->assignee_user_legacy_id) {
                        $this->assignments->claim((int) $task->id,
                            ['client_command_id' => $this->childCommand($payload, 'claim', (int) $line->id), 'expected_version' => (int) $task->business_version], $user, $permissions, $super);
                    } elseif ((int) $task->assignee_user_legacy_id !== $this->userId($user)) $this->fail('bundle_task_owner_changed', '原任务负责人已经变化。', 409);
                    $task->refresh(); [, $target] = $this->compatibility->target($task, true);
                    app(ProductionTargetReadinessService::class)->refresh($line->target_type, $target, $task);
                }
            });
            $bundle->fill(['status' => 'CLAIMED', 'assignee_user_legacy_id' => $this->userId($user), 'claimed_at' => now()])->save();
            return $bundle;
        });
    }

    public function start(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.task.start');
        return $this->command('start_job_bundle', $id, $payload, $user, $permissions, $super, function ($bundle) use ($payload, $user, $permissions) {
            $this->owner($bundle, $user);
            if ($bundle->status !== 'CLAIMED' || $bundle->started_at) $this->fail('bundle_not_ready', '只有已接单且未开工的共同加工作业可以开始。', 409);
            foreach ($bundle->lines as $line) {
                $facts = $this->compatibility->inspect($line->task, false);
                if ($facts['compatibility_key'] !== $bundle->compatibility_key) $this->fail('bundle_compatibility_changed', '原任务冻结兼容事实已经变化，请重新安排。', 409);
                $target = $facts['target'];
                if ($target->started_at || $target->status !== 'READY' || ($target->kitting_required && ! $target->kitting_confirmed_at)) $this->fail('bundle_line_not_ready', '仍有明细未齐套、未交接或已经开工，不能统一开工。', 409, ['line_id' => (int) $line->id]);
                // READY alone may be stale. Recheck every material mode from authoritative source facts.
                $probe = clone $target; $probe->status = 'CLAIMED';
                $readiness = app(ProductionTargetReadinessService::class)->project($line->target_type, $probe);
                if (! $readiness['ready']) $this->fail('bundle_line_not_ready', $readiness['reason_message'] ?: '明细材料尚未满足开工条件。', 409, ['line_id' => (int) $line->id, 'readiness' => $readiness]);
                if ($this->materialIdentity($facts['materials']) !== $this->materialIdentity((array) $line->material_snapshot)) $this->fail('bundle_material_identity_changed', '明细材料或尺寸身份发生变化，不能替代原安排。', 409, ['line_id' => (int) $line->id]);
                $line->update(['started_material_snapshot' => $facts['materials']]);
            }
            $this->labor->start($bundle, $this->userId($user));
            ProductionJobBundleExecutionContext::run((int) $bundle->id, function () use ($bundle, $payload, $user, $permissions) {
                foreach ($bundle->lines as $line) {
                    [, $target] = $this->compatibility->target($line->task, true);
                    $this->actions->start((int) $line->task_id, $line->target_type, (int) $line->target_id,
                        ['client_command_id' => $this->childCommand($payload, 'start', (int) $line->id), 'expected_version' => (int) $target->business_version], $user, $permissions);
                    $line->update(['status' => 'IN_PROGRESS']);
                }
            });
            $bundle->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'paused_at' => null]);
            return $bundle;
        });
    }

    public function pause(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.task.pause');
        return $this->command('pause_job_bundle', $id, $payload, $user, $permissions, $super, function ($bundle) use ($user) {
            $this->owner($bundle, $user);
            if ($bundle->status !== 'IN_PROGRESS') $this->fail('bundle_not_in_progress', '只有进行中的共同加工作业可以暂停。', 409);
            $this->labor->checkpoint($bundle, true, 'bundle_paused');
            foreach ($bundle->lines as $line) if (! $line->execution_completed_at) {
                [, $target] = $this->compatibility->target($line->task, true);
                // Automatic processing can remain running when the employee pauses; preserve the original work-mode rule.
                $targetStatus = ($target->work_mode_snapshot ?: 'manual') === 'automatic' ? 'IN_PROGRESS' : 'PAUSED';
                $target->update(['status' => $targetStatus, 'paused_at' => $targetStatus === 'PAUSED' ? now() : null, 'business_version' => (int) $target->business_version + 1]);
                $line->task->targets()->where('target_type', $line->target_type)->where('target_id', $line->target_id)->update(['status_snapshot' => $targetStatus]);
                $line->task->update(['status' => $targetStatus, 'business_version' => (int) $line->task->business_version + 1]);
                $line->update(['status' => 'PAUSED']);
            }
            $bundle->update(['status' => 'PAUSED', 'paused_at' => now()]);
            return $bundle;
        });
    }

    public function resume(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.task.resume');
        return $this->command('resume_job_bundle', $id, $payload, $user, $permissions, $super, function ($bundle) use ($payload, $user, $permissions) {
            $this->owner($bundle, $user);
            if ($bundle->status !== 'PAUSED' || ! $bundle->lines->contains(fn ($l) => ! $l->execution_completed_at)) $this->fail('bundle_not_resumable', '只有还有未完工明细的暂停作业可以继续。', 409);
            $this->labor->start($bundle, $this->userId($user));
            ProductionJobBundleExecutionContext::run((int) $bundle->id, function () use ($bundle, $payload, $user, $permissions) {
                foreach ($bundle->lines as $line) if (! $line->execution_completed_at) {
                    [, $target] = $this->compatibility->target($line->task, true);
                    $this->actions->resume((int) $line->task_id, $line->target_type, (int) $line->target_id,
                        ['client_command_id' => $this->childCommand($payload, 'resume', (int) $line->id), 'expected_version' => (int) $target->business_version], $user, $permissions);
                    $line->update(['status' => 'IN_PROGRESS']);
                }
            });
            $bundle->update(['status' => 'IN_PROGRESS', 'paused_at' => null]);
            return $bundle;
        });
    }

    public function report(int $id, int $lineId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.report.create');
        return $this->command('report_job_bundle_line', $id, $payload + ['line_id' => $lineId], $user, $permissions, $super, function ($bundle) use ($lineId, $payload, $user, $permissions) {
            $line = $this->executionLine($bundle, $lineId, $payload, $user);
            // Reporting a detail never ends the shared session or starts another target timer.
            $original = $payload;
            $original['client_command_id'] = $this->childCommand($payload, 'report', $lineId);
            $original['expected_version'] = (int) $payload['expected_target_version'];
            $original['end_labor'] = false;
            ProductionJobBundleExecutionContext::run((int) $bundle->id, fn () => $this->reports->report((int) $line->task_id,
                $line->target_type, (int) $line->target_id, $original, $user, $permissions));
            return $bundle;
        });
    }

    public function complete(int $id, int $lineId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.task.complete');
        return $this->command('complete_job_bundle_line', $id, $payload + ['line_id' => $lineId], $user, $permissions, $super, function ($bundle) use ($lineId, $payload, $user, $permissions) {
            $line = $this->executionLine($bundle, $lineId, $payload, $user);
            [, $target] = $this->compatibility->target($line->task, true);
            $disposition = $this->completionDisposition($target, $payload);
            $lastOpen = $bundle->lines->filter(fn ($l) => ! $l->execution_completed_at)->count() === 1;
            $this->labor->checkpoint($bundle, $lastOpen, $lastOpen ? 'bundle_last_line_completed' : 'bundle_line_completed');
            $target->refresh();
            $original = $payload;
            $original['disposition'] = $disposition;
            $original['client_command_id'] = $this->childCommand($payload, 'complete', $lineId);
            $original['expected_version'] = (int) $target->business_version;
            ProductionJobBundleExecutionContext::run((int) $bundle->id, fn () => $this->actions->complete((int) $line->task_id,
                $line->target_type, (int) $line->target_id, $original, $user, $permissions));
            $line->update(['status' => 'COMPLETED', 'execution_completed_at' => now()]);
            // The last detail ends physical processing; the explicit finish command preserves the reviewable job lifecycle.
            if ($lastOpen) $bundle->update(['status' => 'PAUSED', 'paused_at' => now()]);
            return $bundle;
        });
    }

    public function finish(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.task.complete');
        return $this->command('finish_job_bundle', $id, $payload, $user, $permissions, $super, function ($bundle) use ($user) {
            $this->owner($bundle, $user);
            if (! in_array($bundle->status, ['IN_PROGRESS', 'PAUSED'], true) || $bundle->lines->contains(fn ($l) => ! $l->execution_completed_at)) $this->fail('bundle_lines_not_complete', '每条原任务都正式完工后才能结束共同加工作业。', 409);
            $this->labor->checkpoint($bundle, true, 'bundle_finished');
            $this->release($bundle);
            $bundle->update(['status' => 'COMPLETED', 'completed_at' => now(), 'paused_at' => null]);
            return $bundle;
        });
    }

    public function cancel(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.work_order.edit');
        return $this->command('cancel_job_bundle', $id, $payload, $user, $permissions, $super, function ($bundle) use ($user, $permissions, $super) {
            $this->queries->assertCandidateScope($bundle->lines->pluck('task_id')->map(fn ($id) => (int) $id)->all(), $user, $permissions, $super);
            if ($bundle->started_at || ! in_array($bundle->status, ['WAIT_CLAIM', 'CLAIMED'], true)) $this->fail('bundle_already_started', '已经开工的共同加工作业不能取消，请完成各明细后结束。', 409);
            $this->release($bundle);
            $bundle->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);
            return $bundle;
        });
    }

    private function command(string $action, ?int $id, array $payload, object $user, array $permissions, bool $super, callable $apply): array
    {
        $command = trim((string) ($payload['client_command_id'] ?? ''));
        if ($command === '' || strlen($command) > 120) $this->fail('client_command_id_required', '写操作必须提供不超过120字的命令编号。');
        if ($id !== null) $this->queries->visible($id, $user, $permissions, $super);
        $hash = hash('sha256', json_encode([$action, $id, $this->userId($user), $this->canonical($payload)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        try {
            return DB::transaction(function () use ($action, $id, $payload, $user, $permissions, $command, $hash, $apply) {
                $bundle = $id === null ? null : ProductionJobBundle::query()->lockForUpdate()->findOrFail($id);
                $old = ProductionExecutionCommand::query()->where('client_command_id', $command)->lockForUpdate()->first();
                if ($old) return $this->replay($old, $action, $hash);
                $ledger = ProductionExecutionCommand::create(['client_command_id' => $command, 'command_type' => $action,
                    'aggregate_type' => 'job_bundle', 'aggregate_id' => $id, 'request_hash' => $hash, 'status' => 'processing',
                    'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now()]);
                $before = $bundle?->status; $version = (int) ($bundle?->business_version ?? 0);
                if ($bundle) {
                    if ((int) ($payload['expected_version'] ?? 0) !== $version) $this->fail('version_conflict', '共同加工作业版本已变化，请刷新。', 409, ['current_version' => $version]);
                    $bundle->setRelation('lines', $bundle->lines()->orderBy('task_id')->lockForUpdate()->get());
                    $workOrders = WorkOrder::query()->whereIn('id', $bundle->lines->pluck('work_order_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    $tasks = ProductionTask::query()->whereIn('id', $bundle->lines->pluck('task_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    foreach ($bundle->lines as $line) {
                        $task = $tasks->get($line->task_id);
                        if (! $task) $this->fail('bundle_task_not_found', '原任务不存在。', 409);
                        $task->setRelation('workOrder', $workOrders->get($line->work_order_id));
                        $line->setRelation('task', $task);
                        if (! in_array($bundle->status, ['COMPLETED', 'CANCELLED'], true)
                            && ((int) $task->active_job_bundle_id !== (int) $bundle->id || (int) $line->active_task_id !== (int) $task->id)) $this->fail('bundle_membership_conflict', '共同加工明细占用事实已变化。', 409);
                        if ($action !== 'cancel_job_bundle' && ! $line->execution_completed_at && ! in_array($task->workOrder?->status, ['RELEASED', 'IN_PROGRESS'], true)) $this->fail('work_order_not_executable', '来源工单已经停止执行。', 409);
                        $this->compatibility->target($task, true);
                    }
                }
                $bundle = $apply($bundle);
                if ($id !== null) $bundle->update(['business_version' => $version + 1]);
                $bundle->unsetRelation('lines'); $bundle->unsetRelation('laborSessions');
                // Hydrate database defaults before exposing a newly created
                // aggregate (for example actual_labor_minutes = 0.00).
                $bundle->refresh();
                // MySQL stores object keys in its own JSON order. Return the
                // same canonical facts on first execution and every replay.
                $result = $this->canonical($this->queries->project($bundle, $user, $permissions));
                DB::table('erp_production_execution_events')->insert(['aggregate_type' => 'job_bundle', 'aggregate_id' => $bundle->id,
                    'action' => $action, 'before_status' => $before, 'after_status' => $bundle->status, 'before_version' => $version,
                    'after_version' => $bundle->business_version, 'fact_snapshot' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'operator_legacy_id' => $this->userId($user), 'operator_name' => $user->nickname ?? $user->username ?? null,
                    'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $ledger->update(['aggregate_id' => $bundle->id, 'result_type' => 'job_bundle', 'result_id' => $bundle->id,
                    'response_snapshot' => $result, 'status' => 'succeeded', 'processing_finished_at' => now()]);
                return $result;
            }, 5);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            $old = ProductionExecutionCommand::query()->where('client_command_id', $command)->first();
            if ($old) return $this->replay($old, $action, $hash);
            $this->fail('bundle_task_already_active', '原任务已进入另一项活跃共同作业，请刷新。', 409);
        }
    }

    private function executionLine(ProductionJobBundle $bundle, int $lineId, array $payload, object $user): ProductionJobBundleLine
    {
        $this->owner($bundle, $user);
        if (! in_array($bundle->status, ['IN_PROGRESS', 'PAUSED'], true)) $this->fail('bundle_not_executable', '该共同加工作业尚未开工或已经停止。', 409);
        $line = $bundle->lines->firstWhere('id', $lineId);
        if (! $line || $line->execution_completed_at) $this->fail('bundle_line_not_executable', '该明细不属于当前作业或已经完工。', 409);
        [, $target] = $this->compatibility->target($line->task, true);
        if ((int) ($payload['expected_target_version'] ?? 0) !== (int) $target->business_version) $this->fail('version_conflict', '原生产目标版本已变化，请刷新。', 409, ['current_target_version' => (int) $target->business_version]);
        if ((int) $line->task->assignee_user_legacy_id !== $this->userId($user)) $this->fail('bundle_task_owner_changed', '原任务负责人已经变化。', 409);
        return $line;
    }

    private function release(ProductionJobBundle $bundle): void
    {
        foreach ($bundle->lines as $line) {
            $line->update(['active_task_id' => null]);
            $line->task->update(['active_job_bundle_id' => null, 'business_version' => (int) $line->task->business_version + 1]);
        }
    }

    private function materialIdentity(array $rows): array
    {
        return $this->canonical(array_map(fn ($r) => array_intersect_key($r, array_flip(['id', 'material_requirement_id', 'material_supply_rule_snapshot_id',
            'work_order_id', 'component_item_id', 'cut_length_mm_snapshot', 'required_piece_qty_snapshot', 'required_base_qty',
            'required_configuration_id', 'supply_mode_snapshot', 'delivery_location_type_snapshot'])), $rows));
    }
    private function completionDisposition(object $target, array $payload): string
    {
        $choice = $payload['disposition'] ?? null;
        $mode = $target->output_mode_snapshot;
        if ($mode === 'warehouse_optional') {
            if (! in_array($choice, ['warehouse', 'direct_handover'], true)) $this->fail('output_disposition_required', '本条产出可入库或直接交接，请明确选择去向。');
            if ($choice === 'direct_handover' && ! $target->allow_continue_without_warehouse_snapshot) $this->fail('output_direct_handover_not_allowed', '本条产出必须先入库，不能直接交接。');
            return $choice;
        }
        $required = $mode === 'warehouse_required' ? 'warehouse' : 'direct_handover';
        if ($choice !== null && $choice !== $required) $this->fail('output_disposition_invalid', '所选产出去向不符合本条原工序的冻结规则。');
        return $required;
    }
    private function canonical(array $value): array { if (! array_is_list($value)) ksort($value); foreach ($value as &$v) if (is_array($v)) $v = $this->canonical($v); return $value; }
    private function childCommand(array $payload, string $action, int $lineId): string { return 'PJB-'.hash('sha256', (string) $payload['client_command_id'].'|'.$action.'|'.$lineId); }
    private function replay(ProductionExecutionCommand $command, string $action, string $hash): array
    {
        if ($command->command_type !== $action || $command->request_hash !== $hash) $this->fail('command_conflict', '该命令编号已用于不同请求。', 409);
        if ($command->status !== 'succeeded' || ! is_array($command->response_snapshot)) $this->fail('command_processing', '相同命令正在处理中，请稍后重试。', 409);
        return $this->canonical($command->response_snapshot);
    }
    private function owner(ProductionJobBundle $bundle, object $user): void { if ((int) $bundle->assignee_user_legacy_id !== $this->userId($user)) $this->fail('bundle_owner_required', '只有该实际作业负责人可以执行。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403, ['permission' => $code]); }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never { throw new WorkOrderDomainException($code, $message, $status, $details); }
}
