<?php

namespace App\Services\Erp\ApprovalIntegrations;

use App\Models\Erp\{ApprovalFlowTemplate, ApprovalFlowVersion, ApprovalTask, Bom};
use App\Services\Erp\ApprovalTriggerEngine;
use Illuminate\Validation\ValidationException;

/** BOM 只接业务事件，流程设计、处理人和节点决策仍由统一审批引擎管理。 */
class BomApprovalIntegration
{
    public function __construct(private readonly ApprovalTriggerEngine $triggers) {}

    public function submitted(Bom $bom, object $actor, bool $setDefault): ?ApprovalTask
    {
        $flows = ApprovalFlowTemplate::query()->where('status', 'enabled')
            ->where('business_object_code', 'BOM')->where('event_code', 'submit_approval')->whereIn('trigger_mode', ['EVENT_TRIGGER', 'BOTH'])
            ->orderBy('id')->lockForUpdate()->get();
        if ($flows->isEmpty()) return null;
        foreach ($flows as $flow) {
            $version = ApprovalFlowVersion::query()->where('flow_template_id', $flow->id)->where('version_no', $flow->current_version)
                ->where('version_status', 'published')->lockForUpdate()->first();
            if (! $version) throw ValidationException::withMessages(['approval_flow' => '启用的 BOM 审批流程缺少当前已发布版本，请先修复流程配置；本次保存及提交未生效。']);
        }
        $result = $this->triggers->dispatch('BOM', (int) $bom->id, 'submit_approval', $actor, [
            'subject' => 'BOM 生效 · '.$bom->bom_no, 'business_no' => $bom->bom_no, 'source_route' => '/bom/'.$bom->id.'/detail',
            'integration_source' => 'bom_submit', 'set_default' => $setDefault,
        ]);
        $task = $result['task'] ?? null;
        if (($result['result'] ?? null) !== 'STARTED' || ! $task instanceof ApprovalTask) {
            throw ValidationException::withMessages(['approval_flow' => '已有启用的 BOM 审批流程，但未匹配可执行任务；请检查流程条件与处理人，本次保存及提交未生效。']);
        }
        // 已发布旧配置也须有专属回调，不能把“任务完成”冒充 BOM 生效。
        $actions = collect(data_get($task->flow_snapshot, 'definition.completion_actions', []));
        foreach (['approved' => 'bom.approve', 'rejected' => 'bom.reject'] as $event => $actionKey) {
            if (! $actions->contains(fn ($action) => ($action['event'] ?? '') === $event && ($action['action_key'] ?? '') === $actionKey)) {
                throw ValidationException::withMessages(['approval_flow' => 'BOM 审批流程缺少对应的生效或驳回处理动作，请修复并发布后重试。']);
            }
        }
        $task->refresh();
        if ($task->action_status === 'FAILED') throw ValidationException::withMessages(['approval_flow' => 'BOM 审批处理失败：'.$task->action_error]);
        return $task;
    }
}
