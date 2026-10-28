<?php

namespace App\Services\Erp\ApprovalActions;

use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;
use App\Models\Erp\ApprovalNodeDecision;
use App\Models\Erp\SalesOrderChangeCandidate;
use App\Services\Erp\Contracts\ApprovalBusinessActionHandler;
use App\Services\Erp\SalesOrderEditImpactService;
use Illuminate\Support\Facades\DB;

class SalesOrderChangeAction implements ApprovalBusinessActionHandler
{
    public function execute(ApprovalBusinessAction $action, ApprovalTask $task, ?ApprovalTaskNode $node, array $config, string $operator, ?string $decision = null, ?string $comment = null): array
    {
        $candidate = SalesOrderChangeCandidate::query()->findOrFail($task->business_id);
        // 自动动作可能在队列重试，不能凭显示姓名伪造身份或借用超级管理员权限。
        // 审批服务已保存本轮决定；树撤销的操作者必须从该真实决定关联的员工账号取得。
        $approval = $node ? ApprovalNodeDecision::query()
            ->where('approval_task_id', $task->id)->where('approval_task_node_id', $node->id)
            ->where('round_no', max(1, (int) $node->current_round))->where('decision', 'APPROVE')
            ->orderByDesc('decided_at')->orderByDesc('id')->first() : null;
        $actor = $approval ? DB::table('erp_legacy_admin_users')->where('legacy_id', $approval->approver_id)
            ->whereIn(DB::raw('LOWER(status)'), ['normal', 'active'])->first() : null;
        // 后续抄送或自动节点只检查已确定的结果，不能再次提交同一个销售审核决定。
        $result = $node && strtoupper((string) ($node->node_type ?: 'APPROVAL')) === 'APPROVAL'
            ? app(SalesOrderEditImpactService::class)->decide($candidate->id, (string) $node->approval_type, $decision === 'approve', $operator, $comment, $actor)
            : $candidate;
        return ['candidate_id' => $result->id, 'candidate_status' => $result->candidate_status,
            'activated_at' => optional($result->activated_at)->toDateTimeString(), 'conflict_reason' => $result->conflict_reason];
    }
}
