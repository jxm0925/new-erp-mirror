<?php

namespace App\Services\Erp;

use App\Services\Erp\ApprovalActions\CompleteApplicationAction;
use App\Services\Erp\ApprovalActions\BomAction;
use App\Services\Erp\ApprovalActions\SalesOrderChangeAction;
use App\Services\Erp\ApprovalActions\UpdateRegisteredFieldsAction;

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
        ], [
            'key' => 'bom', 'label' => 'BOM 生效业务适配器', 'version' => 'v1.0', 'source_table' => 'erp_boms',
            'defaults' => [
                'object_code' => 'BOM', 'object_name' => '物料清单(BOM)', 'business_module' => '生产基础配置', 'primary_key' => 'id',
                'route_pattern' => '/bom/{id}/detail', 'event_code' => 'submit_approval', 'event_name' => '提交 BOM 生效审批',
                'manual_start_allowed' => false, 'event_trigger_allowed' => true,
            ],
            'field_labels' => [
                'id' => '记录编号', 'bom_no' => 'BOM 编码', 'bom_name' => '方案名称', 'product_id' => '归属商品', 'sku_id' => '关联 SKU',
                'output_item_id' => '产出物料', 'routing_id' => '工艺路线', 'bom_type' => '方案类型', 'version' => '版本',
                'is_default' => '默认版本', 'status' => '启用状态', 'audit_status' => '审核状态', 'submitted_at' => '提交时间',
                'effective_date' => '生效日期', 'expire_date' => '失效日期', 'created_by' => '创建人', 'remark' => '备注',
            ],
            'field_defaults' => [
                'id' => ['reference_enabled' => true],
                'bom_no' => ['condition_enabled' => true, 'display_enabled' => true, 'search_enabled' => true],
                'bom_name' => ['condition_enabled' => true, 'display_enabled' => true, 'search_enabled' => true],
                'output_item_id' => ['condition_enabled' => true, 'display_enabled' => true, 'reference_enabled' => true],
                'version' => ['condition_enabled' => true, 'display_enabled' => true],
                'status' => ['condition_enabled' => true, 'display_enabled' => true],
                'audit_status' => ['condition_enabled' => true, 'display_enabled' => true],
                'created_by' => ['condition_enabled' => true, 'reference_enabled' => true],
                'remark' => ['display_enabled' => true, 'approval_writable' => true],
            ],
            'actions' => [
                ['action_code' => 'bom.approve', 'action_name' => '审批通过后生效 BOM 并按提交选择设为默认', 'result_event' => 'approved', 'handler_class' => BomAction::class],
                ['action_code' => 'bom.reject', 'action_name' => '驳回 BOM 生效申请并返回草稿', 'result_event' => 'rejected', 'handler_class' => BomAction::class],
            ],
        ]];
    }

    public function globalActions(): array
    {
        return [
            [
                'action_code' => 'generic.approve.update_fields', 'action_name' => '审核通过后更新业务字段',
                'result_event' => 'approved', 'handler_class' => UpdateRegisteredFieldsAction::class,
            ],
            [
                'action_code' => 'generic.reject.update_fields', 'action_name' => '审核驳回后更新业务字段',
                'result_event' => 'rejected', 'handler_class' => UpdateRegisteredFieldsAction::class,
            ],
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
