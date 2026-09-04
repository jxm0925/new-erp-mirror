<?php

namespace App\Services\Erp;

use App\Services\Erp\ApprovalActions\CompleteApplicationAction;
use App\Services\Erp\ApprovalActions\SalesOrderChangeAction;

/**
 * Code-installed approval capabilities.
 *
 * The approval designer may register database metadata from the browser, but it
 * must never accept an arbitrary PHP handler class from the browser. Business
 * side effects stay in this explicit allow-list and are only bound when the
 * matching adapter is selected on the registration page.
 */
class ApprovalInstalledCapabilityCatalog
{
    public function forTable(string $table): ?array
    {
        return collect($this->adapters())->firstWhere('source_table', $table);
    }

    public function adapters(): array
    {
        return [[
            'key' => 'sales_order_change',
            'label' => '销售订单变更业务适配器',
            'version' => 'v1.0',
            'source_table' => 'erp_sales_order_change_candidates',
            'defaults' => [
                'object_code' => 'SALES_ORDER_CHANGE',
                'object_name' => '销售订单变更候选版本',
                'business_module' => '销售管理',
                'primary_key' => 'id',
                'route_pattern' => '/sales/orders/{sales_order_id}/detail',
                'event_code' => 'submit_change',
                'event_name' => '提交销售订单变更审核',
                'manual_start_allowed' => false,
                'event_trigger_allowed' => true,
            ],
            'field_labels' => [
                'id' => '记录ID',
                'candidate_no' => '候选版本号',
                'sales_order_id' => '销售订单ID',
                'base_version' => '原版本',
                'candidate_version' => '候选版本',
                'candidate_status' => '候选状态',
                'submitted_by' => '提交人',
                'submitted_at' => '提交时间',
                'change_reason' => '变更原因',
                'structured_diffs' => '结构化差异',
                'impact_summary' => '影响摘要',
                'approval_requirements' => '审核要求',
                'approval_reasons' => '审核原因快照',
                'production_impact' => '生产影响',
                'activated_by' => '生效操作人',
                'activated_at' => '生效时间',
                'conflict_reason' => '冲突原因',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
            ],
            'field_defaults' => [
                'id' => ['display_enabled' => false, 'reference_enabled' => true],
                'candidate_no' => ['condition_enabled' => true, 'display_enabled' => true, 'search_enabled' => true],
                'sales_order_id' => ['condition_enabled' => true, 'display_enabled' => true, 'reference_enabled' => true],
                'candidate_version' => ['condition_enabled' => true, 'display_enabled' => true],
                'candidate_status' => ['condition_enabled' => true, 'display_enabled' => true],
                'submitted_by' => ['condition_enabled' => true, 'display_enabled' => true, 'reference_enabled' => true],
                'submitted_at' => ['condition_enabled' => true, 'display_enabled' => true],
                'change_reason' => ['condition_enabled' => true, 'display_enabled' => true],
                'impact_summary' => ['condition_enabled' => true, 'display_enabled' => true],
                'approval_requirements' => ['condition_enabled' => true, 'display_enabled' => true],
                'approval_reasons' => ['condition_enabled' => false, 'display_enabled' => true],
                'production_impact' => ['condition_enabled' => true, 'display_enabled' => true],
            ],
            'actions' => [[
                'action_code' => 'sales_order_change.decide',
                'action_name' => '执行销售订单变更节点决策',
                'result_event' => 'node_decision',
                'handler_class' => SalesOrderChangeAction::class,
            ]],
        ]];
    }

    public function globalActions(): array
    {
        return [
            [
                'action_code' => 'approval.complete',
                'action_name' => '完成审核并保留申请结果',
                'result_event' => 'approved',
                'handler_class' => CompleteApplicationAction::class,
            ],
            [
                'action_code' => 'approval.reject',
                'action_name' => '驳回申请并保留审核记录',
                'result_event' => 'rejected',
                'handler_class' => CompleteApplicationAction::class,
            ],
        ];
    }
}
