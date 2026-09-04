<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\ApprovalFlowApplicationService;
use App\Services\Erp\ApprovalAttachmentApplicationService;
use App\Services\Erp\ApprovalTaskApplicationService;
use App\Services\Erp\ApprovalFormApplicationService;
use App\Services\Erp\ApprovalNotificationService;
use App\Services\Erp\ApprovalRegistryApplicationService;
use App\Services\Erp\ApprovalTriggerEngine;
use App\Services\Erp\ApprovalBusinessObjectAccessService;
use App\Services\Erp\ApprovalBusinessObjectRegistry;
use App\Services\Erp\AuthContextService;
use App\Models\Erp\ApprovalTaskAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApprovalController extends Controller
{
    public function tasks(Request $request, ApprovalTaskApplicationService $service)
    {
        [$user, $codes, $super] = $this->context($request, 'approval.task.view');
        $filters = $request->validate([
            'scope' => ['nullable', Rule::in(['todo', 'processed', 'initiated', 'all'])], 'business_type' => ['nullable', 'string', 'max:100'],
            'approval_type' => ['nullable', 'string', 'max:80'], 'risk_level' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'task_status' => ['nullable', Rule::in(['PENDING', 'APPROVED', 'REJECTED', 'CONFLICTED', 'CANCELLED'])],
            'initiator' => ['nullable', 'string', 'max:100'], 'department' => ['nullable', 'string', 'max:120'],
            'submitted_from' => ['nullable', 'date'], 'submitted_to' => ['nullable', 'date'], 'keyword' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (($filters['scope'] ?? 'todo') === 'all') {
            abort_unless($super || in_array('approval.all', $codes, true), 403, '无权查看全部审核任务。');
        }
        return response()->json($service->paginate($filters, $user, $codes, $super));
    }

    public function summary(Request $request, ApprovalTaskApplicationService $service)
    {
        [$user, $codes, $super] = $this->context($request, 'approval.task.view');
        return response()->json(['data' => $service->summary($user, $codes, $super)]);
    }

    public function showTask(Request $request, int $id, ApprovalTaskApplicationService $service)
    {
        [$user, $codes, $super] = $this->context($request, 'approval.task.view');
        return response()->json(['data' => $service->show($id, $user, $codes, $super)]);
    }

    public function decideTask(Request $request, int $id, ApprovalTaskApplicationService $service)
    {
        [$user, $codes, $super] = $this->context($request, 'approval.task.decide');
        $payload = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'comment' => ['required', 'string', 'max:2000']]);
        return response()->json(['message' => $payload['decision'] === 'approve' ? '当前审核节点已通过。' : '审核任务已驳回。', 'data' => $service->decide($id, $payload['decision'], $payload['comment'], $user, $codes, $super)]);
    }

    public function batchDecide(Request $request, ApprovalTaskApplicationService $service)
    {
        [$user, $codes, $super] = $this->context($request, 'approval.task.batch_decide');
        $payload = $request->validate(['task_ids' => ['required', 'array', 'min:1', 'max:50'], 'task_ids.*' => ['integer', 'distinct'], 'comment' => ['required', 'string', 'max:2000']]);
        $result = $service->batchDecide(array_map('intval', $payload['task_ids']), $payload['comment'], $user, $codes, $super);
        return response()->json(['message' => $result['summary'], 'data' => $result]);
    }

    public function transferTask(Request $request, int $id, ApprovalTaskApplicationService $service)
    {
        [$user, $codes, $super] = $this->context($request, 'approval.task.decide');
        $payload = $request->validate([
            'target_user_id' => ['required', 'integer', 'min:1'], 'source_assignee_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        return response()->json(['message' => '审核任务已转交。', 'data' => $service->transfer($id, (int) $payload['target_user_id'], $payload['reason'], $user, $codes, $super, isset($payload['source_assignee_id']) ? (int) $payload['source_assignee_id'] : null)]);
    }

    public function retryTaskAction(Request $request, int $id, ApprovalTaskApplicationService $service)
    {
        [$user, $codes, $super] = $this->context($request, 'approval.task.decide');
        return response()->json(['message' => '审批业务动作已重新执行。', 'data' => $service->retryAction($id, $user, $codes, $super)]);
    }

    public function notifications(Request $request, ApprovalNotificationService $service)
    {
        [$user] = $this->context($request, 'approval.task.view');
        $filters = $request->validate(['status' => ['nullable', Rule::in(['UNREAD', 'READ'])], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = $service->paginate((int) $user->legacy_id, $filters);
        return response()->json(['data' => $page->items(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'unread_count' => $service->unreadCount((int) $user->legacy_id)]);
    }

    public function readNotification(Request $request, int $id, ApprovalNotificationService $service)
    {
        [$user] = $this->context($request, 'approval.task.view');
        return response()->json(['message' => '通知已读。', 'data' => $service->markRead($id, (int) $user->legacy_id)]);
    }

    public function submitBusinessFlow(Request $request, string $businessType, ApprovalTriggerEngine $triggers, ApprovalBusinessObjectRegistry $objects, ApprovalBusinessObjectAccessService $access)
    {
        [$user, $codes, $super] = $this->context($request, 'approval.task.submit');
        $payload = $request->validate([
            'business_id' => ['required', 'integer', 'min:1'], 'business_no' => ['nullable', 'string', 'max:160'],
            'subject' => ['required', 'string', 'max:255'], 'source_route' => ['nullable', 'string', 'max:500'],
            'risk_level' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'event_code' => ['nullable', 'string', 'max:100'],
            'diff_snapshot' => ['nullable', 'array'], 'metadata' => ['nullable', 'array'],
        ]);
        $access->assertCanAccessRecord($objects->find($businessType), (int) $payload['business_id'], $user, $codes, $super);
        $result = $triggers->dispatch($businessType, (int) $payload['business_id'], (string) ($payload['event_code'] ?? ''), $user, [
            'request_metadata' => (array) ($payload['metadata'] ?? []),
        ]);
        return response()->json(['message' => $result['result'] === 'STARTED' ? '业务事件已进入审核。' : '业务事件无需审核，已按 BYPASS 放行。', 'data' => $result], $result['result'] === 'STARTED' ? 201 : 200);
    }

    public function launchOptions(Request $request, ApprovalFlowApplicationService $service)
    {
        [$user, $codes, $super] = $this->context($request, 'approval.task.submit');
        return response()->json(['data' => $service->launchOptions($user, $codes, $super)]);
    }

    public function launchSourceRecords(Request $request, int $id, ApprovalFlowApplicationService $service)
    {
        [$user, $codes, $super] = $this->context($request, 'approval.task.submit');
        $filters = $request->validate([
            'keyword' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        return response()->json($service->sourceRecords($id, $filters, $user, $codes, $super));
    }

    public function launchFlow(
        Request $request,
        int $id,
        ApprovalFlowApplicationService $flows,
        ApprovalTaskApplicationService $tasks,
        ApprovalFormApplicationService $forms,
        ApprovalTriggerEngine $triggers,
    ) {
        [$user, $codes, $super] = $this->context($request, 'approval.task.submit');
        $payload = $request->validate([
            'business_id' => ['nullable', 'integer', 'min:1'], 'subject' => ['nullable', 'string', 'max:255'],
            'risk_level' => ['nullable', Rule::in(['low', 'medium', 'high'])], 'form_data' => ['nullable', 'array'],
        ]);
        $launch = $flows->launchDefinition($id, $user, $codes, $super, isset($payload['business_id']) ? (int) $payload['business_id'] : null);
        $flow = $launch['flow']; $definition = $launch['definition'];
        if (($definition['source_mode'] ?? 'existing') === 'custom_form') {
            $formId = (int) ($definition['form_template_id'] ?? 0);
            $submission = $forms->submit($formId, [
                'subject' => $payload['subject'] ?? $flow->flow_name,
                'form_data' => (array) ($payload['form_data'] ?? []),
            ], $user, $flow->id);
            return response()->json(['message' => '申请已发起并进入审核流程。', 'data' => $submission->approval_task], 201);
        }
        if (empty($payload['business_id'])) {
            throw ValidationException::withMessages(['business_id' => '请选择要发起审核的业务记录。']);
        }
        $result = $triggers->dispatch((string) $flow->business_object_code, (int) $payload['business_id'],
            (string) ($flow->event_code ?: ($definition['event_code'] ?? $definition['trigger_action'] ?? 'manual_start')),
            $user, ['launch_source' => 'approval_workbench', 'subject' => $payload['subject'] ?? $flow->flow_name], 'MANUAL_START', $flow->id);
        $task = $result['task'] ?? null;
        if (!$task) return response()->json(['message' => '当前业务数据不满足流程启动条件，已按 BYPASS 处理。', 'data' => ['result' => 'BYPASS']]);
        return response()->json(['message' => '申请已发起并进入审核流程。', 'data' => $task], 201);
    }

    public function uploadTaskAttachment(Request $request, int $id, ApprovalAttachmentApplicationService $service, ApprovalTaskApplicationService $tasks)
    {
        [$user, $codes, $super] = $this->context($request, 'approval.task.decide');
        $task = $tasks->show($id, $user, $codes, $super);
        abort_unless($task->can_decide || $super, 403, '只有当前任务处理人可以上传审核附件。');
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,xlsx,xls,doc,docx']]);
        return response()->json(['message' => '审核附件已上传。', 'data' => $service->upload($id, $request->file('file'), $user)], 201);
    }

    public function previewTaskAttachment(Request $request, int $attachmentId, ApprovalTaskApplicationService $tasks)
    {
        [$user, $codes, $super] = $this->context($request, 'approval.task.view');
        $attachment = ApprovalTaskAttachment::findOrFail($attachmentId);
        $tasks->show((int) $attachment->approval_task_id, $user, $codes, $super);
        return Storage::disk($attachment->storage_disk)->response($attachment->storage_path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream', 'Content-Disposition' => 'inline',
        ]);
    }

    public function deleteTaskAttachment(Request $request, int $attachmentId, ApprovalAttachmentApplicationService $service, ApprovalTaskApplicationService $tasks)
    {
        [$user, $codes, $super] = $this->context($request, 'approval.task.decide');
        $attachment = ApprovalTaskAttachment::findOrFail($attachmentId);
        $task = $tasks->show((int) $attachment->approval_task_id, $user, $codes, $super);
        abort_unless($task->can_decide || $super, 403, '只有当前任务处理人可以删除审核附件。');
        $service->delete($attachment, $user, $super);
        return response()->json(['message' => '审核附件已删除。']);
    }

    public function flows(Request $request, ApprovalFlowApplicationService $service)
    {
        $this->context($request, 'approval.flow.view');
        $filters = $request->validate(['business_module' => ['nullable', 'string', 'max:80'], 'business_type' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['draft', 'enabled', 'disabled'])], 'keyword' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        return response()->json($service->paginate($filters));
    }

    public function flowSummary(Request $request, ApprovalFlowApplicationService $service)
    {
        $this->context($request, 'approval.flow.view');
        return response()->json(['data' => $service->summary()]);
    }

    public function flowConfigOptions(Request $request, ApprovalFlowApplicationService $service)
    {
        $this->context($request, 'approval.flow.view');
        return response()->json(['data' => $service->configOptions()]);
    }

    public function registryCandidates(Request $request, ApprovalRegistryApplicationService $service)
    {
        $this->context($request, 'approval.flow.edit');
        $filters = $request->validate([
            'keyword' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        return response()->json($service->candidates($filters));
    }

    public function registryCandidate(Request $request, ApprovalRegistryApplicationService $service)
    {
        $this->context($request, 'approval.flow.edit');
        $payload = $request->validate(['table' => ['required', 'string', 'max:120']]);
        return response()->json(['data' => $service->candidate($payload['table'])]);
    }

    public function registerBusinessObject(Request $request, ApprovalRegistryApplicationService $service)
    {
        [$user] = $this->context($request, 'approval.flow.edit');
        $payload = $request->validate([
            'source_table' => ['required', 'string', 'max:120'],
            'adapter_key' => ['nullable', 'string', 'max:100'],
            'object_code' => ['required', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_]*$/', 'unique:erp_approval_business_objects,object_code'],
            'object_name' => ['required', 'string', 'max:160'],
            'business_module' => ['required', 'string', 'max:80'],
            'primary_key' => ['required', 'string', 'max:80'],
            'route_pattern' => ['nullable', 'string', 'max:240'],
            'view_permission_code' => ['nullable', 'string', 'max:160'],
            'fields' => ['required', 'array', 'min:1', 'max:200'],
            'fields.*.field_code' => ['required', 'string', 'max:120'],
            'fields.*.field_name' => ['required', 'string', 'max:160'],
            'fields.*.field_type' => ['required', 'string', 'max:40'],
            'fields.*.selected' => ['required', 'boolean'],
            'fields.*.condition_enabled' => ['required', 'boolean'],
            'fields.*.display_enabled' => ['required', 'boolean'],
            'fields.*.search_enabled' => ['required', 'boolean'],
            'fields.*.reference_enabled' => ['required', 'boolean'],
            'fields.*.approval_writable' => ['required', 'boolean'],
            'fields.*.sort' => ['nullable', 'integer', 'min:0'],
            'event' => ['required', 'array'],
            'event.event_code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'event.event_name' => ['required', 'string', 'max:160'],
            'event.manual_start_allowed' => ['required', 'boolean'],
            'event.event_trigger_allowed' => ['required', 'boolean'],
        ]);
        if (!$payload['event']['manual_start_allowed'] && !$payload['event']['event_trigger_allowed']) {
            throw ValidationException::withMessages(['event' => '手动发起和业务事件触发至少启用一种。']);
        }
        return response()->json([
            'message' => '审核业务对象已登记，可直接用于当前流程。',
            'data' => $service->register($payload, $this->operator($user)),
        ], 201);
    }

    public function showFlow(Request $request, int $id, ApprovalFlowApplicationService $service)
    {
        $this->context($request, 'approval.flow.view');
        return response()->json(['data' => $service->show($id)]);
    }

    public function storeFlow(Request $request, ApprovalFlowApplicationService $service)
    {
        [$user] = $this->context($request, 'approval.flow.edit');
        return response()->json(['message' => '审核流程草稿已保存。', 'data' => $service->saveDraft(null, $this->flowPayload($request), $this->operator($user))], 201);
    }

    public function updateFlow(Request $request, int $id, ApprovalFlowApplicationService $service)
    {
        [$user] = $this->context($request, 'approval.flow.edit');
        return response()->json(['message' => '审核流程草稿已保存。', 'data' => $service->saveDraft($id, $this->flowPayload($request), $this->operator($user))]);
    }

    public function validateFlow(Request $request, ApprovalFlowApplicationService $service)
    {
        $this->context($request, 'approval.flow.view');
        $payload = $request->validate(['definition' => ['required', 'array']]);
        return response()->json(['data' => $service->validateDefinition($payload['definition'])]);
    }

    public function publishFlow(Request $request, int $id, ApprovalFlowApplicationService $service)
    {
        [$user] = $this->context($request, 'approval.flow.publish');
        $payload = $request->validate(['definition' => ['nullable', 'array'], 'flow_code' => ['sometimes', 'string'], 'flow_name' => ['sometimes', 'string'], 'business_module' => ['sometimes', 'string'], 'business_type' => ['sometimes', 'string'], 'business_scene' => ['sometimes', 'string'], 'applicable_scope' => ['sometimes', 'string'], 'description' => ['nullable', 'string']]);
        return response()->json(['message' => '审核流程新版本已发布。', 'data' => $service->publish($id, $payload, $this->operator($user))]);
    }

    public function toggleFlow(Request $request, int $id, ApprovalFlowApplicationService $service)
    {
        [$user] = $this->context($request, 'approval.flow.toggle');
        $payload = $request->validate(['enabled' => ['required', 'boolean']]);
        return response()->json(['message' => $payload['enabled'] ? '审核流程已启用。' : '审核流程已停用。', 'data' => $service->toggle($id, $payload['enabled'], $this->operator($user))]);
    }

    public function copyFlow(Request $request, int $id, ApprovalFlowApplicationService $service)
    {
        [$user] = $this->context($request, 'approval.flow.edit');
        return response()->json(['message' => '审核流程已复制为新草稿。', 'data' => $service->copy($id, $this->operator($user))], 201);
    }

    private function flowPayload(Request $request): array
    {
        return $request->validate([
            'flow_code' => ['required', 'string', 'max:100'], 'flow_name' => ['required', 'string', 'max:160'],
            'business_module' => ['required', 'string', 'max:80'], 'business_type' => ['nullable', 'string', 'max:100'],
            'business_scene' => ['nullable', 'string', 'max:160'], 'applicable_scope' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'], 'definition' => ['required', 'array'],
        ]);
    }


    private function context(Request $request, string $permission): array
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        abort_unless($user, 401, '请先登录。');
        $codes = $auth->permissionCodes($user); $super = $auth->isSuperAdmin($user);
        abort_unless($super || in_array($permission, $codes, true), 403, '无按钮权限：'.$permission);
        return [$user, $codes, $super];
    }

    private function operator(object $user): string { return (string) ($user->nickname ?: $user->username ?: '系统'); }
}
