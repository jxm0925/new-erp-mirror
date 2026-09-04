<?php

namespace App\Services\Erp;

use App\Events\ApprovalTaskChanged;
use App\Exceptions\ApprovalActionExecutionException;
use App\Models\Erp\ApprovalActionExecution;
use App\Models\Erp\ApprovalFlowTemplate;
use App\Models\Erp\ApprovalFlowVersion;
use App\Models\Erp\ApprovalFormSubmission;
use App\Models\Erp\ApprovalFormTemplate;
use App\Models\Erp\ApprovalNodeDecision;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskLog;
use App\Models\Erp\ApprovalTaskNode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalTaskApplicationService
{
    public function __construct(
        private readonly ApprovalBusinessAdapterRegistry $adapters,
        private readonly ApprovalConfigurationCatalog $catalog,
        private readonly ApprovalBusinessObjectRegistry $objects,
        private readonly ApprovalExpressionEngine $expressions,
        private readonly ApprovalAssigneeResolver $assignees,
        private readonly ApprovalNotificationService $notifications,
        private readonly BusinessActionRegistry $actions,
    ) {}

    public function createForBusinessFlow(string $businessType, array $payload, object $user): ?ApprovalTask
    {
        return DB::transaction(function () use ($businessType, $payload, $user) {
            $requestedFlowId = (int) ($payload['flow_template_id'] ?? 0);
            $requestedEvent = trim((string) ($payload['event_code'] ?? ''));
            $activeKey = $businessType.':'.(int) $payload['business_id'].':'.($requestedEvent ?: 'manual').($requestedFlowId ? ':'.$requestedFlowId : '');
            if ($existing = ApprovalTask::query()->where('active_business_key', $activeKey)->lockForUpdate()->first()) {
                return $existing->load('nodes.decisions');
            }

            $department = DB::table('erp_department_users as du')->leftJoin('erp_departments as d', 'd.legacy_id', '=', 'du.department_legacy_id')
                ->where('du.user_legacy_id', $user->legacy_id)->select('d.legacy_id', 'd.name')->first();
            $flowQuery = ApprovalFlowTemplate::query()->where('status', 'enabled')
                ->where(function ($query) use ($businessType) {
                    $query->where('business_object_code', $businessType)->orWhere('business_type', $businessType);
                });
            if ($requestedFlowId) $flowQuery->whereKey($requestedFlowId);
            $flow = $flowQuery
                ->with(['versions' => fn ($query) => $query->where('version_status', 'published')->orderByDesc('version_no')])
                ->orderByDesc('priority')
                ->get()->first(function (ApprovalFlowTemplate $item) use ($department) {
                    $version = $item->versions->firstWhere('version_no', $item->current_version) ?: $item->versions->first();
                    $scope = (array) data_get($version?->definition_snapshot, 'applicable_scope', ['type' => 'all']);
                    $type = (string) ($scope['type'] ?? 'all');
                    if ($type === 'all' || $type === 'initiator_department') return true;
                    return $type === 'departments' && $department && in_array((int) $department->legacy_id, array_map('intval', (array) ($scope['department_ids'] ?? [])), true);
                });
            if (!$flow) throw ValidationException::withMessages(['approval_flow' => $businessType.'没有已启用且适用于当前组织的审核流程。']);
            $version = ApprovalFlowVersion::query()->where('flow_template_id', $flow->id)
                ->where('version_no', $flow->current_version)->where('version_status', 'published')->firstOrFail();
            $definition = (array) $version->definition_snapshot;
            $objectCode = (string) ($flow->business_object_code ?: ($definition['business_object_code'] ?? $businessType));
            $registeredObject = $this->objects->find($objectCode);
            $linkedObject = $this->objects->serialize($registeredObject);
            $isCustomForm = ($definition['source_mode'] ?? null) === 'custom_form';
            $sourceTable = $isCustomForm
                ? 'erp_approval_form_submissions'
                : (string) $registeredObject->source_table;
            if (!$sourceTable || !preg_match('/^erp_[a-z0-9_]+$/', $sourceTable) || !\Illuminate\Support\Facades\Schema::hasTable($sourceTable)) {
                throw ValidationException::withMessages(['source_table' => '流程没有配置有效的业务数据表。']);
            }
            $primaryKey = (string) $registeredObject->primary_key;
            $sourceRow = DB::table($sourceTable)->where($primaryKey, (int) $payload['business_id'])->first();
            if (!$sourceRow) throw ValidationException::withMessages(['business_id' => '在流程配置的业务数据表中找不到该业务记录。']);
            if ($isCustomForm && (int) ($sourceRow->form_template_id ?? 0) !== (int) ($definition['form_template_id'] ?? 0)) {
                throw ValidationException::withMessages(['business_id' => '申请记录与流程绑定的自定义表单不一致。']);
            }
            $provider = $this->objects->provider($registeredObject);
            $context = $provider->context($registeredObject, (int) $payload['business_id'], $user);
            $businessSnapshot = $provider->snapshot($registeredObject, (int) $payload['business_id'], $user);
            $fieldTypes = $registeredObject->fields->pluck('field_type', 'field_code')->all();
            $startConditions = (array) ($definition['start_conditions'] ?? []);
            if ($startConditions && !$this->expressions->matches($startConditions, $context, $fieldTypes)) return null;
            $nodes = collect($definition['nodes'] ?? [])->values();
            if ($nodes->isEmpty()) throw ValidationException::withMessages(['approval_flow' => '流程没有可执行节点。']);
            $eligible = $nodes->filter(fn ($node) => $this->expressions->matches((array) ($node['entry_conditions'] ?? $node['conditions'] ?? $node['condition'] ?? []), $context, $fieldTypes));
            if ($eligible->isEmpty()) throw ValidationException::withMessages(['approval_flow' => '流程启动成功，但所有审核节点均被进入条件跳过。']);

            $operator = (string) ($user->nickname ?: $user->username ?: '系统');
            $task = ApprovalTask::create([
                'flow_template_id' => $flow->id, 'flow_version_id' => $version->id,
                'business_type' => $businessType, 'business_object_code' => $objectCode,
                'event_code' => $requestedEvent ?: ($flow->event_code ?: ($definition['event_code'] ?? 'manual_start')),
                'business_id' => (int) $payload['business_id'],
                'business_no' => $payload['business_no'] ?? data_get($sourceRow, collect($registeredObject->display_fields ?: [])->first() ?: $registeredObject->primary_key),
                'subject' => trim((string) ($payload['subject'] ?? '')) ?: $flow->flow_name.'申请',
                'source_route' => $payload['source_route'] ?? ($registeredObject->route_pattern
                    ? str_replace(['{id}', '{'.$primaryKey.'}'], (string) $payload['business_id'], $registeredObject->route_pattern)
                    : null), 'risk_level' => $payload['risk_level'] ?? 'medium',
                'task_status' => 'PENDING', 'active_business_key' => $activeKey,
                'idempotency_key' => hash('sha256', $activeKey), 'launch_result' => 'STARTED', 'action_status' => 'PENDING',
                'current_node_order' => (int) $eligible->keys()->first() + 1,
                'initiator_id' => $user->legacy_id, 'initiator_name' => $operator,
                'department_id' => $department?->legacy_id, 'department_name' => $department?->name,
                'business_snapshot' => $businessSnapshot,
                'diff_snapshot' => (array) ($payload['diff_snapshot'] ?? []),
                'flow_snapshot' => ['flow_code' => $flow->flow_code, 'flow_name' => $flow->flow_name, 'version_no' => $version->version_no, 'definition' => $definition],
                'metadata' => array_merge((array) ($payload['metadata'] ?? []), ['condition_context' => $context, 'source_table' => $sourceTable, 'business_object_code' => $objectCode, 'start_conditions' => $startConditions]),
                'submitted_at' => now(),
            ]);
            $task->update(['task_no' => 'RW'.now()->format('Ymd').str_pad((string) $task->id, 6, '0', STR_PAD_LEFT)]);
            $firstEligibleIndex = (int) $eligible->keys()->first();
            foreach ($nodes as $index => $node) {
                $canEnter = $eligible->keys()->contains($index);
                $startedAt = $index === $firstEligibleIndex ? now() : null;
                $nodeType = strtoupper((string) ($node['node_type'] ?? 'APPROVAL'));
                $taskNode = ApprovalTaskNode::create([
                    'approval_task_id' => $task->id, 'node_key' => $node['key'], 'node_order' => $index + 1,
                    'node_name' => $node['name'], 'node_type' => $nodeType, 'approval_type' => $node['approval_type'] ?? 'business',
                    'node_status' => !$canEnter ? 'SKIPPED' : ($index === $firstEligibleIndex ? 'PENDING' : 'WAITING'),
                    'processing_strategy' => $node['processing_strategy'] ?? 'sequential',
                    'completion_strategy' => $node['completion_strategy'] ?? (($node['processing_strategy'] ?? 'sequential') === 'parallel' ? 'ALL' : 'ANY'),
                    'required_approver_count' => $node['required_approver_count'] ?? null,
                    'required_approver_ratio' => $node['required_approver_ratio'] ?? null,
                    'reject_on_any' => $node['reject_on_any'] ?? true,
                    'permission_code' => $node['permission_code'] ?? 'approval.task.decide',
                    'approver_rule' => $node['approver_rule'] ?? null,
                    'action_code' => $node['action_code'] ?? null, 'action_config' => $node['action_config'] ?? null,
                    'condition_snapshot' => $node['entry_conditions'] ?? $node['conditions'] ?? $node['condition'] ?? null,
                    'sla_hours' => $node['sla_hours'] ?? null, 'started_at' => $startedAt,
                    'due_at' => $startedAt && !empty($node['sla_hours']) ? $startedAt->copy()->addHours((int) $node['sla_hours']) : null,
                ]);
                if ($canEnter && in_array($nodeType, ['APPROVAL', 'CC'], true)) {
                    $this->assignees->snapshot($task, $taskNode, (array) ($node['approver_rule'] ?? []), $context);
                    $this->log($task, $taskNode, 'assignees_resolved', null, $taskNode->node_status, null, '系统', '节点实际处理人已解析并固化。', [
                        'source_rule' => $taskNode->approver_rule,
                        'assignee_ids' => $taskNode->assignees()->pluck('user_id')->all(),
                    ]);
                }
                if (!$canEnter) $this->log($task, $taskNode, 'node_skipped', 'WAITING', 'SKIPPED', null, '系统', '节点进入条件不满足，已跳过：'.$taskNode->node_name, ['entry_conditions' => $taskNode->condition_snapshot]);
            }
            $this->log($task, null, 'flow_matched', null, 'PENDING', $user->legacy_id, $operator, '流程匹配成功并创建任务。', ['flow_id' => $flow->id, 'flow_version' => $version->version_no, 'priority' => $flow->priority, 'match_strategy' => $flow->match_strategy]);
            $this->log($task, null, 'start_condition_passed', null, 'PENDING', $user->legacy_id, $operator, '流程启动条件校验通过。', ['start_conditions' => $startConditions]);
            $this->log($task, null, 'submitted', null, 'PENDING', $user->legacy_id, $operator, $flow->flow_name.'审核任务已提交。', ['business_type' => $businessType, 'source_table' => $sourceTable]);
            $this->advanceAutomaticNodes($task, $context);
            return $task->fresh(['nodes.decisions', 'nodes.assignees', 'flowTemplate', 'flowVersion']);
        });
    }

    public function createForLinkedObject(string $objectCode, int $businessId, array $payload, object $user): ?ApprovalTask
    {
        $flow = ApprovalFlowTemplate::query()->where('status', 'enabled')
            ->with(['versions' => fn ($query) => $query->where('version_status', 'published')->orderByDesc('version_no')])
            ->orderBy('id')->get()->first(function (ApprovalFlowTemplate $candidate) use ($objectCode) {
                $version = $candidate->versions->firstWhere('version_no', $candidate->current_version) ?: $candidate->versions->first();
                return data_get($this->catalog->linkedObject((array) ($version?->definition_snapshot ?? [])), 'code') === $objectCode;
            });
        if (!$flow) return null;
        return $this->createForBusinessFlow((string) $flow->business_type, ['business_id' => $businessId, ...$payload], $user);
    }

    public function createForCustomForm(ApprovalFormTemplate $form, ApprovalFormSubmission $submission, object $user, ?int $flowTemplateId = null): ?ApprovalTask
    {
        $submission->loadMissing('version');
        $flowQuery = ApprovalFlowTemplate::query()->where('status', 'enabled');
        if ($flowTemplateId) $flowQuery->whereKey($flowTemplateId);
        $flow = $flowQuery
            ->with(['versions' => fn ($query) => $query->where('version_status', 'published')->orderByDesc('version_no')])
            ->orderBy('id')->get()->first(function (ApprovalFlowTemplate $candidate) use ($form) {
                $version = $candidate->versions->firstWhere('version_no', $candidate->current_version) ?: $candidate->versions->first();
                $definition = (array) ($version?->definition_snapshot ?? []);
                return ($definition['source_mode'] ?? null) === 'custom_form'
                    && (int) ($definition['form_template_id'] ?? 0) === (int) $form->id;
            });
        if (!$flow) {
            throw ValidationException::withMessages(['approval_flow' => '该自定义表单没有已启用的审核流程，不能提交申请。']);
        }
        return $this->createForBusinessFlow((string) $flow->business_type, [
            'flow_template_id' => $flow->id,
            'business_id' => $submission->id,
            'business_no' => $submission->submission_no,
            'subject' => $submission->subject,
            // 自定义表单内容已固化在任务快照中；未提供独立申请单详情页时不生成无效跳转。
            'source_route' => null,
            'risk_level' => 'low',
            'metadata' => ['source_mode' => 'custom_form', 'form_template_id' => $form->id],
        ], $user);
    }

    public function paginate(array $filters, object $user, array $permissionCodes, bool $isSuperAdmin): LengthAwarePaginator
    {
        $query = ApprovalTask::query()->with(['nodes.decisions', 'nodes.assignees', 'flowTemplate']);
        $scope = $filters['scope'] ?? 'todo';
        if ($scope === 'todo') {
            $this->applyActionableTaskScope($query, $user, $permissionCodes, $isSuperAdmin);
        } elseif ($scope === 'processed') {
            $query->where(function ($processed) use ($user) {
                $processed->whereHas('nodes', fn ($node) => $node->where('decided_by', $user->legacy_id))
                    ->orWhereHas('nodes.decisions', fn ($decision) => $decision->where('approver_id', $user->legacy_id));
            });
        } elseif ($scope === 'initiated') {
            $query->where('initiator_id', $user->legacy_id);
        }
        if ($value = trim((string) ($filters['business_type'] ?? ''))) $query->where('business_type', $value);
        if ($value = trim((string) ($filters['approval_type'] ?? ''))) $query->whereHas('nodes', fn ($q) => $q->where('approval_type', $value));
        if ($value = trim((string) ($filters['risk_level'] ?? ''))) $query->where('risk_level', $value);
        if ($value = trim((string) ($filters['task_status'] ?? ''))) $query->where('task_status', $value);
        if ($value = trim((string) ($filters['initiator'] ?? ''))) $query->where('initiator_name', 'like', "%{$value}%");
        if ($value = trim((string) ($filters['department'] ?? ''))) $query->where('department_name', 'like', "%{$value}%");
        if ($from = ($filters['submitted_from'] ?? null)) $query->whereDate('submitted_at', '>=', $from);
        if ($to = ($filters['submitted_to'] ?? null)) $query->whereDate('submitted_at', '<=', $to);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(fn ($q) => $q->where('task_no', 'like', "%{$keyword}%")
                ->orWhere('business_no', 'like', "%{$keyword}%")->orWhere('subject', 'like', "%{$keyword}%"));
        }
        $page = $query->orderByRaw("FIELD(risk_level, 'high', 'medium', 'low')")
            ->orderByDesc('submitted_at')->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
        $page->getCollection()->transform(fn (ApprovalTask $task) => $this->decorate($task, $user, $permissionCodes, $isSuperAdmin));
        return $page;
    }

    public function summary(object $user, array $permissionCodes, bool $isSuperAdmin): array
    {
        $todo = ApprovalTask::query();
        $this->applyActionableTaskScope($todo, $user, $permissionCodes, $isSuperAdmin);
        $processed = ApprovalTask::query()->where(function ($query) use ($user) {
            $query->whereHas('nodes', fn ($node) => $node->where('decided_by', $user->legacy_id))
                ->orWhereHas('nodes.decisions', fn ($decision) => $decision->where('approver_id', $user->legacy_id));
        });
        $initiated = ApprovalTask::query()->where('initiator_id', $user->legacy_id);
        return [
            'todo' => (clone $todo)->count(), 'high_risk' => (clone $todo)->where('risk_level', 'high')->count(),
            'today_new' => (clone $todo)->whereDate('submitted_at', today())->count(),
            'near_timeout' => (clone $todo)->whereHas('nodes', fn ($q) => $q->where('node_status', 'PENDING')->whereBetween('due_at', [now(), now()->addHours(4)]))->count(),
            'processed' => (clone $processed)->count(),
            'initiated' => (clone $initiated)->count(),
            'all' => ($isSuperAdmin || in_array('approval.all', $permissionCodes, true)) ? ApprovalTask::query()->count() : null,
            'rejected' => (clone $processed)->where('task_status', 'REJECTED')->count(),
        ];
    }

    public function show(int $id, object $user, array $permissionCodes, bool $isSuperAdmin): ApprovalTask
    {
        $task = ApprovalTask::query()->with(['nodes.decisions', 'nodes.assignees', 'logs.node', 'attachments', 'actionExecutions', 'flowTemplate', 'flowVersion'])->findOrFail($id);
        $task = $this->decorate($task, $user, $permissionCodes, $isSuperAdmin);
        if (!$isSuperAdmin && !in_array('approval.all', $permissionCodes, true)) {
            $participated = $task->nodes->contains(fn ($node) => (int) $node->decided_by === (int) $user->legacy_id
                || $node->decisions->contains(fn ($decision) => (int) $decision->approver_id === (int) $user->legacy_id));
            $initiated = (int) $task->initiator_id === (int) $user->legacy_id;
            if (!$task->can_decide && !$participated && !$initiated) throw new AuthorizationException('无权查看该审核任务。');
        }
        return $task;
    }

    public function decide(int $taskId, string $decision, ?string $comment, object $user, array $permissionCodes, bool $isSuperAdmin): ApprovalTask
    {
        try {
            return $this->decideWithinTransaction($taskId, $decision, $comment, $user, $permissionCodes, $isSuperAdmin);
        } catch (ApprovalActionExecutionException $exception) {
            $this->recordActionFailure($taskId, null, $exception->actionCode, $exception->getMessage(), (string) ($user->nickname ?: $user->username ?: '系统'));
            throw ValidationException::withMessages(['business_action' => '业务动作执行失败，审批未完成，可修复业务条件后重新提交：'.$exception->getMessage()]);
        }
    }

    public function batchDecide(array $taskIds, string $comment, object $user, array $permissionCodes, bool $isSuperAdmin): array
    {
        $preflight = [];
        foreach (array_values(array_unique(array_map('intval', $taskIds))) as $taskId) {
            try {
                $this->preflightDecision($taskId, 'approve', $comment, $user, $permissionCodes, $isSuperAdmin);
                $preflight[$taskId] = null;
            } catch (\Throwable $exception) {
                $preflight[$taskId] = $this->decisionFailure($taskId, $exception);
            }
        }

        $results = [];
        foreach ($preflight as $taskId => $failure) {
            if ($failure) { $results[] = $failure; continue; }
            try {
                $task = $this->decide($taskId, 'approve', $comment, $user, $permissionCodes, $isSuperAdmin);
                $results[] = ['task_id' => $taskId, 'task_no' => $task->task_no, 'success' => true, 'status' => $task->task_status, 'error_code' => null, 'message' => '当前节点已通过。'];
            } catch (\Throwable $exception) {
                $results[] = $this->decisionFailure($taskId, $exception);
            }
        }
        $success = collect($results)->where('success', true)->count();
        $failed = count($results) - $success;
        return [
            'total' => count($results), 'success_count' => $success, 'failed_count' => $failed,
            'partial_success' => $success > 0 && $failed > 0,
            'summary' => "批量审核完成：成功 {$success} 条，失败 {$failed} 条。",
            'results' => $results,
        ];
    }

    public function preflightDecision(int $taskId, string $decision, ?string $comment, object $user, array $permissionCodes, bool $isSuperAdmin): void
    {
        $task = ApprovalTask::query()->findOrFail($taskId);
        if ($task->task_status !== 'PENDING') throw ValidationException::withMessages(['task' => '审核任务已结束，不能重复处理。']);
        $node = ApprovalTaskNode::query()->where('approval_task_id', $task->id)->where('node_status', 'PENDING')->orderBy('node_order')->first();
        if (!$node) throw ValidationException::withMessages(['node' => '当前没有可处理的审核节点。']);
        if (!$isSuperAdmin && (!$node->permission_code || !in_array($node->permission_code, $permissionCodes, true))) {
            throw ValidationException::withMessages(['permission' => '无权处理当前审核节点：'.$node->node_name]);
        }
        if (!$isSuperAdmin && !$node->assignees()->where('user_id', $user->legacy_id)->where('status', 'PENDING')->exists()) {
            throw ValidationException::withMessages(['approver' => '当前账号不符合该节点配置的处理人规则。']);
        }
        if (!$isSuperAdmin && !data_get($task->flow_snapshot, 'definition.allow_self_approval', false)
            && $task->initiator_id && (int) $task->initiator_id === (int) $user->legacy_id) {
            throw ValidationException::withMessages(['approver' => '发起人不能审核自己提交的任务。']);
        }
        $definition = collect(data_get($task->flow_snapshot, 'definition.nodes', []))->firstWhere('key', $node->node_key) ?: [];
        if ($decision === 'reject' && ($definition['allow_reject'] ?? true) === false) throw ValidationException::withMessages(['decision' => '当前节点配置为不允许驳回。']);
        if (($definition['comment_required'] ?? true) && trim((string) $comment) === '') throw ValidationException::withMessages(['comment' => '请填写审核意见。']);
        if (ApprovalNodeDecision::query()->where('approval_task_node_id', $node->id)->where('approver_id', $user->legacy_id)->where('round_no', max(1, (int) $node->current_round))->exists()) {
            throw ValidationException::withMessages(['decision' => '您已经处理过当前会签节点的本轮审核，不能重复提交意见。']);
        }
    }

    private function decideWithinTransaction(int $taskId, string $decision, ?string $comment, object $user, array $permissionCodes, bool $isSuperAdmin): ApprovalTask
    {
        return DB::transaction(function () use ($taskId, $decision, $comment, $user, $permissionCodes, $isSuperAdmin) {
            $task = ApprovalTask::query()->lockForUpdate()->findOrFail($taskId);
            if ($task->task_status !== 'PENDING') throw ValidationException::withMessages(['task' => '审核任务已结束，不能重复处理。']);
            $nodes = ApprovalTaskNode::query()->where('approval_task_id', $task->id)->orderBy('node_order')->lockForUpdate()->get();
            $node = $nodes->firstWhere('node_status', 'PENDING');
            if (!$node) throw ValidationException::withMessages(['node' => '当前没有可处理的审核节点。']);
            if (!$isSuperAdmin && (!$node->permission_code || !in_array($node->permission_code, $permissionCodes, true))) {
                throw ValidationException::withMessages(['permission' => '无权处理当前审核节点：'.$node->node_name]);
            }
            if (!$isSuperAdmin && !$node->assignees()->where('user_id', $user->legacy_id)->where('status', 'PENDING')->exists()) {
                throw ValidationException::withMessages(['approver' => '当前账号不符合该节点配置的处理人规则。']);
            }
            $allowSelfApproval = (bool) data_get($task->flow_snapshot, 'definition.allow_self_approval', false);
            if (!$isSuperAdmin && !$allowSelfApproval && $task->initiator_id && (int) $task->initiator_id === (int) $user->legacy_id) {
                throw ValidationException::withMessages(['approver' => '发起人不能审核自己提交的任务。']);
            }
            $comment = trim((string) $comment);
            $nodeDefinition = collect(data_get($task->flow_snapshot, 'definition.nodes', []))->firstWhere('key', $node->node_key) ?: [];
            if ($decision === 'reject' && ($nodeDefinition['allow_reject'] ?? true) === false) {
                throw ValidationException::withMessages(['decision' => '当前节点配置为不允许驳回。']);
            }
            if (($nodeDefinition['comment_required'] ?? true) && $comment === '') {
                throw ValidationException::withMessages(['comment' => '请填写审核意见。']);
            }

            $operator = (string) ($user->nickname ?: $user->username ?: '系统');
            if (ApprovalNodeDecision::query()->where('approval_task_node_id', $node->id)->where('approver_id', $user->legacy_id)->where('round_no', max(1, (int) $node->current_round))->exists()) {
                throw ValidationException::withMessages(['decision' => '您已经处理过当前会签节点的本轮审核，不能重复提交意见。']);
            }
            ApprovalNodeDecision::create([
                'approval_task_id' => $task->id, 'approval_task_node_id' => $node->id,
                'round_no' => max(1, (int) $node->current_round),
                'approver_id' => $user->legacy_id, 'approver_name' => $operator,
                'decision' => strtoupper($decision), 'comment' => $comment, 'decided_at' => now(),
            ]);
            $node->assignees()->where('user_id', $user->legacy_id)->update(['status' => strtoupper($decision), 'completed_at' => now()]);

            $requiredApproverIds = $node->assignees()->where('status', '!=', 'TRANSFERRED')->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->all();
            $completionStrategy = strtoupper((string) ($node->completion_strategy ?: ($node->processing_strategy === 'parallel' ? 'ALL' : 'ANY')));
            if ($decision === 'approve' && count($requiredApproverIds) > 1 && $completionStrategy !== 'ANY') {
                $approvedIds = ApprovalNodeDecision::query()->where('approval_task_node_id', $node->id)
                    ->where('round_no', max(1, (int) $node->current_round))->where('decision', 'APPROVE')->pluck('approver_id')->map(fn ($id) => (int) $id)->unique()->all();
                $remainingIds = array_values(array_diff($requiredApproverIds, $approvedIds));
                $requiredApprovalCount = match ($completionStrategy) {
                    'COUNT' => min(count($requiredApproverIds), max(1, (int) $node->required_approver_count)),
                    'RATIO' => min(count($requiredApproverIds), max(1, (int) ceil(count($requiredApproverIds) * (float) $node->required_approver_ratio))),
                    default => count($requiredApproverIds),
                };
                if (count($approvedIds) < $requiredApprovalCount) {
                    $this->log($task, $node, 'node_approval_recorded', 'PENDING', 'PENDING', $user->legacy_id, $operator,
                        $node->node_name.'已记录会签意见，等待其余指定人员。', [
                            'comment' => $comment, 'approved_count' => count($approvedIds),
                            'required_count' => $requiredApprovalCount, 'completion_strategy' => $completionStrategy,
                            'remaining_approver_ids' => $remainingIds,
                        ]);
                    event(new ApprovalTaskChanged($task));
                    return $this->show($task->id, $user, $permissionCodes, $isSuperAdmin);
                }
            }

            $rejectStrategy = (string) ($nodeDefinition['reject_strategy'] ?? 'TERMINATE');
            $returnPrevious = $decision === 'reject' && $rejectStrategy === 'RETURN_PREVIOUS';
            $businessResult = $returnPrevious ? ['candidate_status' => 'PENDING', 'reject_strategy' => 'RETURN_PREVIOUS']
                : $this->adapters->decide($task, $node, $decision, $operator, $comment);
            $before = $node->node_status;
            $node->update([
                'node_status' => $returnPrevious ? 'WAITING' : ($decision === 'approve' ? 'APPROVED' : 'REJECTED'), 'decision' => $returnPrevious ? 'RETURN_PREVIOUS' : strtoupper($decision),
                'decision_comment' => $comment, 'decided_by' => $user->legacy_id, 'decided_by_name' => $operator, 'decided_at' => now(),
            ]);
            $this->log($task, $node, $decision === 'approve' ? 'node_approved' : 'node_rejected', $before, $node->node_status, $user->legacy_id, $operator,
                $decision === 'approve' ? $node->node_name.'已通过。' : $node->node_name.'已驳回，流程结束。', ['business_result' => $businessResult, 'comment' => $comment]);

            if ($returnPrevious) {
                $previous = $nodes->filter(fn ($item) => $item->node_order < $node->node_order && $item->node_status !== 'SKIPPED')->sortByDesc('node_order')->first();
                if (!$previous) throw ValidationException::withMessages(['reject_strategy' => '当前节点没有可退回的上一审批节点。']);
                $previous->assignees()->update(['status' => 'PENDING', 'completed_at' => null]);
                $previousRound = max(1, (int) $previous->current_round);
                $previous->update(['current_round' => $previousRound + 1, 'node_status' => 'PENDING', 'decision' => null, 'decision_comment' => null, 'decided_by' => null, 'decided_by_name' => null, 'decided_at' => null, 'started_at' => now(), 'due_at' => $previous->sla_hours ? now()->addHours($previous->sla_hours) : null]);
                $task->update(['current_node_order' => $previous->node_order, 'result_snapshot' => $businessResult]);
                $this->log($task, $previous, 'returned_previous', $node->node_name, $previous->node_name, $user->legacy_id, $operator, '审核已退回上一节点重新处理。', [
                    'comment' => $comment, 'from_node_id' => $node->id, 'to_node_id' => $previous->id,
                    'closed_round' => $previousRound, 'reopened_round' => $previousRound + 1,
                    'preserved_decision_ids' => $previous->decisions()->where('round_no', $previousRound)->pluck('id')->all(),
                ]);
                $this->notifications->notifyNode($task, $previous, 'RETURNED');
            } elseif ($decision === 'reject') {
                ApprovalTaskNode::query()->where('approval_task_id', $task->id)->where('node_status', 'WAITING')->update(['node_status' => 'SKIPPED']);
                $task->update(['task_status' => 'REJECTED', 'active_business_key' => null, 'completed_at' => now(), 'result_snapshot' => $businessResult, 'action_status' => 'SUCCEEDED', 'action_error' => null]);
            } elseif (($businessResult['candidate_status'] ?? null) === 'CONFLICTED') {
                $task->update(['task_status' => 'CONFLICTED', 'active_business_key' => null, 'completed_at' => now(), 'result_snapshot' => $businessResult]);
            } else {
                $next = $nodes->first(fn ($item) => $item->node_order > $node->node_order && $item->node_status === 'WAITING');
                if ($next) {
                    $startedAt = now();
                    $next->update(['node_status' => 'PENDING', 'started_at' => $startedAt, 'due_at' => $next->sla_hours ? $startedAt->copy()->addHours($next->sla_hours) : null]);
                    $task->update(['current_node_order' => $next->node_order, 'result_snapshot' => $businessResult]);
                    $this->log($task, $next, 'node_started', 'WAITING', 'PENDING', null, '系统', '进入审核节点：'.$next->node_name, []);
                    $this->advanceAutomaticNodes($task, (array) data_get($task->metadata, 'condition_context', []));
                } else {
                    $finalStatus = ($businessResult['candidate_status'] ?? null) === 'APPROVED' ? 'APPROVED' : 'PENDING';
                    if ($finalStatus !== 'APPROVED') throw ValidationException::withMessages(['business_result' => '所有审核节点已处理，但业务回调未返回已生效状态。']);
                    $task->update(['task_status' => 'APPROVED', 'active_business_key' => null, 'completed_at' => now(), 'result_snapshot' => $businessResult, 'action_status' => 'SUCCEEDED', 'action_error' => null]);
                    $this->log($task, null, 'task_completed', 'PENDING', 'APPROVED', $user->legacy_id, $operator, '全部必需节点已通过，业务候选版本已原子生效。', $businessResult);
                }
            }
            event(new ApprovalTaskChanged($task));
            return $this->show($task->id, $user, $permissionCodes, $isSuperAdmin);
        });
    }

    public function progress(string $businessType, int $businessId): ?ApprovalTask
    {
        return ApprovalTask::query()->with('nodes')->where('business_type', $businessType)->where('business_id', $businessId)->latest('id')->first();
    }

    public function transfer(int $taskId, int $targetUserId, string $reason, object $user, array $permissionCodes, bool $isSuperAdmin, ?int $sourceAssigneeId = null): ApprovalTask
    {
        return DB::transaction(function () use ($taskId, $targetUserId, $reason, $user, $permissionCodes, $isSuperAdmin, $sourceAssigneeId) {
            $task = ApprovalTask::query()->lockForUpdate()->findOrFail($taskId);
            $node = ApprovalTaskNode::query()->where('approval_task_id', $task->id)->where('node_status', 'PENDING')->lockForUpdate()->firstOrFail();
            $definition = collect(data_get($task->flow_snapshot, 'definition.nodes', []))->firstWhere('key', $node->node_key) ?: [];
            if (($definition['allow_transfer'] ?? false) !== true) throw ValidationException::withMessages(['transfer' => '当前节点不允许转交。']);
            $sourceQuery = $node->assignees()->where('status', 'PENDING')->lockForUpdate();
            if ($isSuperAdmin) {
                if (!$sourceAssigneeId) throw ValidationException::withMessages(['source_assignee_id' => '管理员转交必须明确选择一个真实的当前待处理人。']);
                $sourceQuery->whereKey($sourceAssigneeId);
            } else {
                $sourceQuery->where('user_id', $user->legacy_id);
            }
            $source = $sourceQuery->first();
            if (!$isSuperAdmin && !$source) throw ValidationException::withMessages(['transfer' => '您不是当前节点待处理人，不能转交。']);
            if ($isSuperAdmin && !$source) throw ValidationException::withMessages(['source_assignee_id' => '所选来源处理人不是当前节点的待处理人。']);
            $target = DB::table('erp_legacy_admin_users')->where('legacy_id', $targetUserId)->where('status', 'normal')->first();
            if (!$target) throw ValidationException::withMessages(['target_user_id' => '目标处理人不存在或已停用。']);
            if ((int) $targetUserId === (int) $source->user_id) throw ValidationException::withMessages(['target_user_id' => '不能转交给原处理人本人。']);
            if ($node->assignees()->where('user_id', $targetUserId)->where('status', 'PENDING')->exists()) {
                throw ValidationException::withMessages(['target_user_id' => '目标人员已是当前节点待处理人，转交会改变会签分母。']);
            }
            $source->update(['status' => 'TRANSFERRED', 'completed_at' => now()]);
            \App\Models\Erp\ApprovalNodeAssignee::query()->updateOrCreate([
                'approval_task_node_id' => $node->id, 'user_id' => $targetUserId,
            ], [
                'approval_task_id' => $task->id, 'user_name' => $target->nickname ?: $target->username,
                'source_type' => 'transfer', 'source_value' => (string) $user->legacy_id, 'status' => 'PENDING',
                'transferred_from' => $source->id, 'assigned_at' => now(), 'completed_at' => null,
            ]);
            $operator = (string) ($user->nickname ?: $user->username ?: '系统');
            $this->log($task, $node, 'transferred', 'PENDING', 'PENDING', $user->legacy_id, $operator,
                '审核任务已转交给 '.($target->nickname ?: $target->username).'。', ['target_user_id' => $targetUserId, 'reason' => $reason, 'source_assignee_id' => $source->id]);
            $this->notifications->notifyNode($task, $node, 'TRANSFERRED');
            return $this->show($task->id, $user, $permissionCodes, $isSuperAdmin);
        });
    }

    public function retryAction(int $taskId, object $user, array $permissionCodes, bool $isSuperAdmin): ApprovalTask
    {
        return DB::transaction(function () use ($taskId, $user, $permissionCodes, $isSuperAdmin) {
            $task = ApprovalTask::query()->lockForUpdate()->findOrFail($taskId);
            if ($task->task_status !== 'PENDING' || $task->action_status !== 'FAILED') {
                throw ValidationException::withMessages(['business_action' => '当前任务没有可重试的失败动作。']);
            }
            if (!$isSuperAdmin && !in_array('approval.task.decide', $permissionCodes, true)) {
                throw new AuthorizationException('无权重试审批业务动作。');
            }
            $node = ApprovalTaskNode::query()->where('approval_task_id', $taskId)->where('node_status', 'FAILED')->lockForUpdate()->first();
            $completionRetry = false;
            if (!$node) {
                $node = ApprovalTaskNode::query()->where('approval_task_id', $taskId)->where('node_status', 'APPROVED')->orderByDesc('node_order')->lockForUpdate()->first();
                $completionRetry = true;
            }
            if (!$node || (!$completionRetry && $node->node_type !== 'ACTION')) {
                throw ValidationException::withMessages(['business_action' => '失败动作没有可恢复的流程节点。']);
            }
            if (!$completionRetry) $node->update(['node_status' => 'PENDING']);
            $task->update(['action_status' => 'PENDING', 'action_error' => null, 'current_node_order' => $node->node_order]);
            $this->log($task, $node, 'business_action_retry', 'FAILED', 'PENDING', $user->legacy_id,
                (string) ($user->nickname ?: $user->username ?: '系统'), '重新执行失败的流程动作。', ['action_code' => $node->action_code]);
            if ($completionRetry) {
                try {
                    $result = $this->adapters->decide($task, $node, 'approve', (string) ($user->nickname ?: $user->username ?: '系统'), '重试流程完成动作');
                    if (($result['candidate_status'] ?? null) !== 'APPROVED') throw new \RuntimeException('流程完成动作没有返回已生效状态。');
                    $task->update(['task_status' => 'APPROVED', 'active_business_key' => null, 'completed_at' => now(), 'result_snapshot' => $result, 'action_status' => 'SUCCEEDED', 'action_error' => null]);
                    $this->log($task, null, 'task_completed', 'PENDING', 'APPROVED', $user->legacy_id, (string) ($user->nickname ?: $user->username ?: '系统'), '失败的流程完成动作重试成功。', $result);
                } catch (ApprovalActionExecutionException $exception) {
                    $this->markRunningActionFailed($task, $node, $exception, false);
                }
            } else {
                $this->advanceAutomaticNodes($task, (array) data_get($task->metadata, 'condition_context', []));
            }
            return $this->show($taskId, $user, $permissionCodes, $isSuperAdmin);
        });
    }

    private function advanceAutomaticNodes(ApprovalTask $task, array $context): void
    {
        while ($task->task_status === 'PENDING') {
            $node = ApprovalTaskNode::query()->where('approval_task_id', $task->id)->where('node_status', 'PENDING')->orderBy('node_order')->first();
            if (!$node) return;
            $nodeType = strtoupper((string) ($node->node_type ?: 'APPROVAL'));
            if ($nodeType === 'APPROVAL') {
                $this->notifications->notifyNode($task, $node);
                return;
            }

            $before = $node->node_status;
            if ($nodeType === 'CC') {
                $this->notifications->notifyNode($task, $node, 'CC');
                $node->assignees()->where('status', 'PENDING')->update(['status' => 'COPIED', 'completed_at' => now()]);
                $this->log($task, $node, 'node_cc_completed', $before, 'APPROVED', null, '系统', '抄送节点已通知实际接收人。', [
                    'assignee_ids' => $node->assignees()->pluck('user_id')->all(),
                ]);
            } elseif ($nodeType === 'ACTION') {
                if (!$node->action_code) {
                    $node->update(['node_status' => 'FAILED']);
                    $task->update(['action_status' => 'FAILED', 'action_error' => '动作节点未配置已注册业务动作。']);
                    $this->log($task, $node, 'business_action_failed', $before, 'FAILED', null, '系统', '动作节点未配置已注册业务动作。', []);
                    return;
                }
                try {
                    $result = $this->actions->execute($node->action_code, $task, $node, (array) $node->action_config, '系统', 'approve', '流程动作节点自动执行');
                    $task->update(['action_status' => 'SUCCEEDED', 'action_error' => null, 'result_snapshot' => array_merge((array) $task->result_snapshot, ['node_action' => $result])]);
                    $this->log($task, $node, 'business_action_succeeded', $before, 'APPROVED', null, '系统', '流程动作节点执行成功。', ['action_code' => $node->action_code, 'result' => $result]);
                } catch (ApprovalActionExecutionException $exception) {
                    $this->markRunningActionFailed($task, $node, $exception);
                    return;
                }
            } else {
                $this->log($task, $node, 'condition_node_passed', $before, 'APPROVED', null, '系统', '条件节点进入条件成立，继续流转。', ['condition' => $node->condition_snapshot]);
            }

            $node->update(['node_status' => 'APPROVED', 'decision' => 'AUTO', 'decided_by_name' => '系统', 'decided_at' => now()]);
            $next = ApprovalTaskNode::query()->where('approval_task_id', $task->id)->where('node_order', '>', $node->node_order)
                ->where('node_status', 'WAITING')->orderBy('node_order')->first();
            if ($next) {
                $startedAt = now();
                $next->update(['node_status' => 'PENDING', 'started_at' => $startedAt, 'due_at' => $next->sla_hours ? $startedAt->copy()->addHours($next->sla_hours) : null]);
                $task->update(['current_node_order' => $next->node_order]);
                $this->log($task, $next, 'node_started', 'WAITING', 'PENDING', null, '系统', '进入流程节点：'.$next->node_name, ['node_type' => $next->node_type]);
                continue;
            }

            try {
                $result = $this->adapters->decide($task, $node, 'approve', '系统', '自动节点流转完成');
            } catch (ApprovalActionExecutionException $exception) {
                $this->markRunningActionFailed($task, $node, $exception, false);
                return;
            }
            if (($result['candidate_status'] ?? null) !== 'APPROVED') {
                $task->update(['action_status' => 'FAILED', 'action_error' => '流程完成动作没有返回已生效状态。']);
                $this->log($task, $node, 'business_action_failed', 'PENDING', 'PENDING', null, '系统', '流程完成动作没有返回已生效状态。', ['result' => $result]);
                return;
            }
            $task->update(['task_status' => 'APPROVED', 'active_business_key' => null, 'completed_at' => now(), 'result_snapshot' => $result, 'action_status' => 'SUCCEEDED', 'action_error' => null]);
            $this->log($task, null, 'task_completed', 'PENDING', 'APPROVED', null, '系统', '全部流程节点已完成。', $result);
            event(new ApprovalTaskChanged($task));
            return;
        }
    }

    private function markRunningActionFailed(ApprovalTask $task, ApprovalTaskNode $node, ApprovalActionExecutionException $exception, bool $failNode = true): void
    {
        ApprovalActionExecution::query()->where('approval_task_id', $task->id)->where('action_code', $exception->actionCode)
            ->where('execution_status', 'RUNNING')->latest('id')->first()?->update([
                'execution_status' => 'FAILED', 'error_message' => $exception->getMessage(), 'finished_at' => now(),
            ]);
        if ($failNode) $node->update(['node_status' => 'FAILED']);
        $task->update(['action_status' => 'FAILED', 'action_error' => mb_substr($exception->getMessage(), 0, 2000)]);
        $this->log($task, $node, 'business_action_failed', 'PENDING', $failNode ? 'FAILED' : 'PENDING', null, '系统',
            $failNode ? '流程动作节点执行失败，任务保持可重试状态。' : '流程完成动作失败，任务未标记完成。',
            ['action_code' => $exception->actionCode, 'error' => $exception->getMessage()]);
        event(new ApprovalTaskChanged($task));
    }

    private function decorate(ApprovalTask $task, object $user, array $permissionCodes, bool $isSuperAdmin): ApprovalTask
    {
        $current = $task->nodes->firstWhere('node_status', 'PENDING');
        foreach ($task->nodes as $node) {
            $required = $node->relationLoaded('assignees') ? $node->assignees->where('status', '!=', 'TRANSFERRED')->count() : 1;
            $decided = $node->relationLoaded('decisions') ? $node->decisions->where('round_no', max(1, (int) $node->current_round))->where('decision', 'APPROVE')->count() : 0;
            $node->setAttribute('required_approver_count', max($required, 1));
            $node->setAttribute('decided_approver_count', $decided);
            $node->setAttribute('approver_progress_text', $node->processing_strategy === 'parallel' && $required > 1
                ? $decided.'/'.$required.' 人已通过' : '任一符合规则的处理人通过');
        }
        $task->setAttribute('current_node', $current);
        $task->setAttribute('waiting_minutes', $task->submitted_at ? (int) floor($task->submitted_at->diffInMinutes(now())) : 0);
        $alreadyDecided = $current && $current->relationLoaded('decisions')
            && $current->decisions->contains(fn ($row) => (int) $row->round_no === max(1, (int) $current->current_round) && (int) $row->approver_id === (int) $user->legacy_id);
        $task->setAttribute('can_decide', $task->task_status === 'PENDING' && $current
            && ($isSuperAdmin || ($current->permission_code && in_array($current->permission_code, $permissionCodes, true)))
            && ($isSuperAdmin || $current->assignees->contains(fn ($assignee) => (int) $assignee->user_id === (int) $user->legacy_id && $assignee->status === 'PENDING'))
            && !$alreadyDecided
            && ($isSuperAdmin || !$task->initiator_id || (int) $task->initiator_id !== (int) $user->legacy_id));
        $nodeDefinition = $current ? collect(data_get($task->flow_snapshot, 'definition.nodes', []))->firstWhere('key', $current->node_key) : [];
        $task->setAttribute('can_reject', (bool) $task->can_decide && (($nodeDefinition['allow_reject'] ?? true) !== false));
        $task->setAttribute('can_transfer', (bool) $task->can_decide && (($nodeDefinition['allow_transfer'] ?? true) !== false));
        return $task;
    }

    private function applyActionableTaskScope(Builder $query, object $user, array $permissionCodes, bool $isSuperAdmin): void
    {
        $query->where('task_status', 'PENDING');
        if ($isSuperAdmin) {
            $query->whereHas('nodes', fn ($node) => $node->where('node_status', 'PENDING'));
            return;
        }

        $roleCodes = DB::table('erp_rbac_user_roles as ur')
            ->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_legacy_id', $user->legacy_id)
            ->where('r.enabled', true)
            ->pluck('r.code')->all();
        $principalDepartmentIds = DB::table('erp_department_users')
            ->where('user_legacy_id', $user->legacy_id)
            ->where(fn ($department) => $department->where('is_principal', true)->orWhere('is_owner', true))
            ->pluck('department_legacy_id')->map(fn ($id) => (string) $id)->all();

        $query->where(function ($selfApproval) use ($user) {
            $selfApproval->whereNull('initiator_id')
                ->orWhere('initiator_id', '!=', $user->legacy_id)
                ->orWhereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(flow_snapshot, '$.definition.allow_self_approval')), 'false') IN ('true', '1')");
        })->whereHas('nodes', function ($node) use ($user, $permissionCodes) {
            $node->where('node_status', 'PENDING')
                ->whereIn('permission_code', $permissionCodes ?: ['__none__'])
                ->whereHas('assignees', fn ($assignee) => $assignee->where('user_id', $user->legacy_id)->where('status', 'PENDING'));
        });
    }

    private function log(ApprovalTask $task, ?ApprovalTaskNode $node, string $action, ?string $from, ?string $to, ?int $operatorId, ?string $operatorName, string $content, array $payload): void
    {
        ApprovalTaskLog::create([
            'approval_task_id' => $task->id, 'approval_task_node_id' => $node?->id, 'action' => $action,
            'from_status' => $from, 'to_status' => $to, 'operator_id' => $operatorId, 'operator_name' => $operatorName,
            'content' => $content, 'payload' => $payload, 'operated_at' => now(),
        ]);
    }

    private function decisionFailure(int $taskId, \Throwable $exception): array
    {
        $message = $exception->getMessage();
        $code = 'APPROVAL_DECISION_FAILED';
        if ($exception instanceof ValidationException) {
            $errors = collect($exception->errors())->flatten()->filter()->values();
            $message = (string) ($errors->first() ?: $message);
            $code = strtoupper((string) collect(array_keys($exception->errors()))->first() ?: 'VALIDATION_FAILED');
        } elseif ($exception instanceof AuthorizationException) {
            $code = 'FORBIDDEN';
        } elseif ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            $code = 'TASK_NOT_FOUND';
        }
        return ['task_id' => $taskId, 'task_no' => null, 'success' => false, 'status' => null, 'error_code' => $code, 'message' => $message ?: '审核失败。'];
    }

    private function recordActionFailure(int $taskId, ?int $nodeId, string $actionCode, string $error, string $operator): void
    {
        DB::transaction(function () use ($taskId, $nodeId, $actionCode, $error, $operator) {
            $task = ApprovalTask::query()->lockForUpdate()->find($taskId);
            if (!$task) return;
            $attempt = ApprovalActionExecution::query()->where('approval_task_id', $taskId)->where('action_code', $actionCode)->count() + 1;
            ApprovalActionExecution::create([
                'approval_task_id' => $taskId, 'approval_task_node_id' => $nodeId,
                'action_code' => $actionCode, 'attempt_no' => $attempt, 'execution_status' => 'FAILED',
                'error_message' => $error, 'operator_name' => $operator, 'started_at' => now(), 'finished_at' => now(),
            ]);
            $task->update(['action_status' => 'FAILED', 'action_error' => mb_substr($error, 0, 2000)]);
            $this->log($task, $nodeId ? ApprovalTaskNode::find($nodeId) : null, 'business_action_failed', $task->task_status, $task->task_status, null, $operator,
                '业务动作执行失败，审批保持可恢复状态。', ['action_code' => $actionCode, 'error' => $error]);
        });
    }
}
