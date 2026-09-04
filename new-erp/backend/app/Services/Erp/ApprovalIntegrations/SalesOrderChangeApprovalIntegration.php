<?php

namespace App\Services\Erp\ApprovalIntegrations;

use App\Models\Erp\ApprovalTask;
use App\Models\Erp\SalesOrderChangeCandidate;
use App\Services\Erp\ApprovalTriggerEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderChangeApprovalIntegration
{
    public function __construct(private readonly ApprovalTriggerEngine $triggers) {}

    public function submit(SalesOrderChangeCandidate $candidate): ApprovalTask
    {
        $candidate->loadMissing('order');
        $submitter = DB::table('erp_legacy_admin_users')->where('nickname', $candidate->submitted_by)
            ->orWhere('username', $candidate->submitted_by)->first();
        if (!$submitter) throw ValidationException::withMessages(['initiator' => '订单变更提交人未关联有效系统账号。']);
        $result = $this->triggers->dispatch('SALES_ORDER_CHANGE', $candidate->id, 'submit_change', $submitter, [
            'integration_source' => 'sales_order_change',
            'subject' => '销售订单变更审核申请',
            'business_no' => $candidate->candidate_no,
            'source_route' => '/sales/orders/'.$candidate->sales_order_id.'/detail',
            'risk_level' => data_get($candidate->impact_summary, 'overall_risk_level', 'medium'),
            'diff_snapshot' => (array) $candidate->structured_diffs,
            'approval_requirements' => (array) $candidate->approval_requirements,
            'approval_reasons' => (array) $candidate->approval_reasons,
        ]);
        $task = $result['task'] ?? throw ValidationException::withMessages(['approval_flow' => '当前业务数据未命中审批启动条件，已按 BYPASS 处理。']);
        $task->forceFill([
            'business_no' => $candidate->candidate_no,
            'subject' => '销售订单变更审核申请',
            'source_route' => '/sales/orders/'.$candidate->sales_order_id.'/detail',
            'risk_level' => data_get($candidate->impact_summary, 'overall_risk_level', 'medium'),
            'diff_snapshot' => (array) $candidate->structured_diffs,
            'metadata' => array_merge((array) $task->metadata, [
                'approval_requirements' => (array) $candidate->approval_requirements,
                'approval_reasons' => (array) $candidate->approval_reasons,
            ]),
        ])->save();

        return $task->fresh(['nodes.decisions', 'nodes.assignees', 'flowTemplate', 'flowVersion']);
    }
}
