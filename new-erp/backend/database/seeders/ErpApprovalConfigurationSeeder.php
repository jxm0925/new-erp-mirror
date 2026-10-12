<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Database\Seeders\Support\RunsSafeSeed;
use Database\Seeders\Support\SeedSafety;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpApprovalConfigurationSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        if (!Schema::hasTable('erp_approval_flow_templates')) {
            return;
        }

        $this->assertDependencies();
        $this->seedBusinessObjects();
        $this->seedBusinessEventsAndActions();
        $this->seedSalesOrderChangeFlow();
    }

    private function assertDependencies(): void
    {
        foreach ($this->businessObjects() as $code => $config) {
            $columns = Schema::getColumnListing($config['table']);
            foreach (array_unique(array_merge($config['display_fields'], $config['search_fields'], $config['display'], $config['condition'], $config['reference'], $config['writable'])) as $field) {
                if (!in_array($field, $columns, true)) throw new \RuntimeException("审批来源字段缺失：{$code}.{$field}");
            }
            if (!class_exists($config['provider'])) throw new \RuntimeException("审批来源Provider不存在：{$code}");
            $existing = DB::table('erp_approval_business_objects')->where('object_code', $code)->first();
            if ($existing && ($existing->source_table !== $config['table'] || $existing->primary_key !== 'id' || $existing->source_type !== 'database')) {
                throw new \RuntimeException("审批对象代码与来源身份冲突：{$code}，请通过正式配置升级处理。");
            }
        }
        foreach ($this->businessActions() as [$objectCode, $actionCode, $actionName, $resultEvent, $handlerClass]) {
            if (!class_exists($handlerClass)) throw new \RuntimeException('审批动作处理器不存在：'.$actionCode);
            $action = DB::table('erp_approval_business_actions')->where('action_code', $actionCode)->first();
            if (!$action) continue;
            $objectId = $objectCode === null ? null : DB::table('erp_approval_business_objects')->where('object_code', $objectCode)->value('id');
            if (($action->business_object_id === null ? null : (int) $action->business_object_id) !== ($objectId === null ? null : (int) $objectId)) {
                throw new \RuntimeException('审批动作代码与业务对象身份冲突：'.$actionCode);
            }
        }
        $flow = DB::table('erp_approval_flow_templates')->where('flow_code', 'SALES_ORDER_CHANGE')->first();
        if ($flow) {
            if ($flow->business_type !== 'SALES_ORDER_CHANGE') throw new \RuntimeException('审批模板代码与业务身份冲突。');
            $current = (int) $flow->current_version;
            $version = $current > 0 ? DB::table('erp_approval_flow_versions')->where(['flow_template_id' => $flow->id, 'version_no' => $current])->first() : null;
            if (($current > 0 && !$version) || ($flow->status === 'enabled' && (!$version || $version->version_status !== 'published'))) {
                throw new \RuntimeException('既有审批模板当前版本缺失或未发布，不能由安装Seeder伪造版本；请通过流程维护修复。');
            }
        } else {
            $object = DB::table('erp_approval_business_objects')->where('object_code', 'SALES_ORDER_CHANGE')->first();
            if ($object && (!$object->enabled || $object->provider_class !== $this->businessObjects()['SALES_ORDER_CHANGE']['provider'])) {
                throw new \RuntimeException('新审批模板依赖的既有业务对象已停用或Provider不同，请通过流程维护明确配置。');
            }
            if ($object) {
                $event = DB::table('erp_approval_business_events')->where(['business_object_id' => $object->id, 'event_code' => 'submit_change'])->first();
                if ($event && (!$event->enabled || !$event->event_trigger_allowed)) throw new \RuntimeException('新审批模板依赖的既有事件不允许触发。');
            }
            $action = DB::table('erp_approval_business_actions')->where('action_code', 'sales_order_change.decide')->first();
            if ($action && (!$action->enabled || $action->handler_class !== 'App\\Services\\Erp\\ApprovalActions\\SalesOrderChangeAction')) throw new \RuntimeException('新审批模板依赖的既有动作已停用或处理器不同。');
            foreach (['sales_manager', 'finance_manager'] as $role) {
                $definition = DB::table('erp_rbac_roles')->where('code', $role)->first();
                if (!$definition || !$definition->enabled) throw new \RuntimeException("新审批模板缺少启用角色定义：{$role}，请先补齐RBAC定义。");
            }
            foreach (['sales_order.change.approve_business', 'sales_order.change.approve_finance', 'sales_order.change.approve_fulfillment'] as $permission) {
                $definition = DB::table('erp_rbac_permissions')->where('code', $permission)->first();
                if (!$definition || !$definition->enabled) throw new \RuntimeException("新审批模板缺少启用权限定义：{$permission}");
            }
        }
    }

    private function businessObjects(): array
    {
        return [
            'PURCHASE_ORDER' => [
                'name' => '采购订单',
                'module' => '采购管理',
                'table' => 'erp_purchase_orders',
                'route' => '/purchase/orders/{id}/detail',
                'provider' => 'App\\Services\\Erp\\ApprovalProviders\\DatabaseBusinessObjectProvider',
                'display_fields' => ['purchase_order_no', 'supplier_id', 'total_amount', 'purchase_status', 'audit_status'],
                'search_fields' => ['purchase_order_no', 'source_no'],
                'display' => ['id', 'purchase_order_no', 'supplier_id', 'order_date', 'expected_arrival_date', 'currency', 'tax_mode', 'settlement_method', 'purchase_status', 'audit_status', 'receipt_status', 'total_qty', 'total_amount', 'amount_excl_tax', 'amount_incl_tax', 'tax_amount', 'freight_amount', 'other_purchase_cost_amount', 'finance_fact_status', 'remark'],
                'condition' => ['id', 'purchase_order_no', 'plan_id', 'source_type', 'source_no', 'supplier_id', 'order_date', 'expected_arrival_date', 'currency', 'base_currency', 'business_exchange_rate', 'exchange_rate_date', 'tax_mode', 'settlement_method', 'delivery_method', 'purchase_status', 'audit_status', 'receipt_status', 'total_qty', 'total_amount', 'amount_excl_tax', 'base_amount_excl_tax', 'amount_incl_tax', 'base_amount_incl_tax', 'tax_amount', 'freight_amount', 'other_purchase_cost_amount', 'finance_fact_status', 'remark', 'data_source', 'created_at'],
                'reference' => ['plan_id', 'supplier_id'],
                'writable' => ['remark'],
            ],
            'SALES_ORDER_CHANGE' => [
                'name' => '销售订单变更候选版本',
                'module' => '销售管理',
                'table' => 'erp_sales_order_change_candidates',
                'route' => '/sales/orders/{sales_order_id}/detail',
                'provider' => 'App\\Services\\Erp\\ApprovalProviders\\DatabaseBusinessObjectProvider',
                'display_fields' => ['candidate_no', 'sales_order_id', 'candidate_version', 'candidate_status', 'change_reason'],
                'search_fields' => ['candidate_no'],
                'display' => ['id', 'candidate_no', 'sales_order_id', 'base_version', 'candidate_version', 'candidate_status', 'submitted_by', 'submitted_at', 'change_reason', 'impact_summary', 'approval_requirements', 'created_at'],
                'condition' => ['id', 'candidate_no', 'sales_order_id', 'base_version', 'candidate_version', 'candidate_status', 'submitted_by', 'submitted_at', 'change_reason', 'impact_summary', 'approval_requirements', 'created_at'],
                'reference' => ['sales_order_id', 'submitted_by'],
                'writable' => [],
            ],
            'CUSTOM_FORM_SUBMISSION' => [
                'name' => '自定义表单申请',
                'module' => '行政管理',
                'table' => 'erp_approval_form_submissions',
                'route' => null,
                'provider' => 'App\\Services\\Erp\\ApprovalProviders\\CustomFormSubmissionProvider',
                'display_fields' => ['submission_no', 'subject', 'submission_status', 'submitted_by_name', 'submitted_at'],
                'search_fields' => ['submission_no', 'subject'],
                'display' => ['id', 'submission_no', 'form_template_id', 'form_version_id', 'subject', 'submission_status', 'submitted_by', 'submitted_by_name', 'submitted_at', 'created_at'],
                'condition' => ['id', 'form_template_id', 'form_version_id', 'subject', 'submission_status', 'submitted_by', 'submitted_at', 'created_at'],
                'reference' => ['form_template_id', 'form_version_id', 'submitted_by'],
                'writable' => [],
            ],
        ];
    }

    private function seedBusinessObjects(): void
    {
        if (!Schema::hasTable('erp_approval_business_objects') || !Schema::hasTable('erp_approval_business_object_fields')) {
            return;
        }

        $objects = $this->businessObjects();

        foreach ($objects as $code => $config) {
            if (!Schema::hasTable($config['table'])) {
                continue;
            }

            $this->upsert('erp_approval_business_objects', ['object_code' => $code], [
                'object_name' => $config['name'],
                'business_module' => $config['module'],
                'source_type' => 'database',
                'source_table' => $config['table'],
                'primary_key' => 'id',
                'display_fields' => json_encode($config['display_fields'], JSON_UNESCAPED_UNICODE),
                'search_fields' => json_encode($config['search_fields'], JSON_UNESCAPED_UNICODE),
                'route_pattern' => $config['route'],
                'provider_class' => $config['provider'],
                'context_provider_class' => $config['provider'],
                'enabled' => true,
                'view_permission_code' => $code === 'PURCHASE_ORDER' ? 'purchase.order.view' : null,
            ]);

            $objectId = (int) DB::table('erp_approval_business_objects')->where('object_code', $code)->value('id');

            $this->syncObjectFields($objectId, $config['table'], $config);
        }
    }

    private function syncObjectFields(int $objectId, string $table, array $config): void
    {
        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->orderBy('ORDINAL_POSITION')
            ->get();

        foreach ($columns as $index => $column) {
            if (in_array($column->COLUMN_NAME, ['password', 'password_hash', 'remember_token', 'legacy_payload'], true)) {
                continue;
            }

            $type = match (strtolower((string) $column->DATA_TYPE)) {
                'tinyint' => preg_match('/tinyint\(1\)/i', (string) $column->COLUMN_TYPE) ? 'boolean' : 'integer',
                'smallint', 'mediumint', 'int', 'bigint' => 'integer',
                'decimal', 'float', 'double' => 'decimal',
                'date' => 'date',
                'datetime', 'timestamp' => 'datetime',
                'json' => 'json',
                'enum' => 'enum',
                default => 'string',
            };

            $field = (string) $column->COLUMN_NAME;
            $this->upsert('erp_approval_business_object_fields', [
                'business_object_id' => $objectId,
                'field_code' => $field,
            ], [
                'field_name' => $column->COLUMN_COMMENT ?: $field,
                'field_type' => $type,
                'source_path' => $field,
                'options' => null,
                'condition_enabled' => in_array($field, $config['condition'], true),
                'display_enabled' => in_array($field, $config['display'], true),
                'reference_enabled' => in_array($field, $config['reference'], true),
                'approval_writable' => in_array($field, $config['writable'], true),
                'sort' => $index,
            ]);
        }
    }

    private function businessActions(): array
    {
        return [
            [null, 'approval.complete', '完成审核并保留申请结果', 'approved', 'App\\Services\\Erp\\ApprovalActions\\CompleteApplicationAction'],
            [null, 'approval.reject', '驳回申请并保留审核记录', 'rejected', 'App\\Services\\Erp\\ApprovalActions\\CompleteApplicationAction'],
            [null, 'generic.approve.update_fields', '审核通过后更新允许字段', 'approved', 'App\\Services\\Erp\\ApprovalActions\\UpdateRegisteredFieldsAction'],
            [null, 'generic.reject.update_fields', '审核驳回后更新允许字段', 'rejected', 'App\\Services\\Erp\\ApprovalActions\\UpdateRegisteredFieldsAction'],
            ['PURCHASE_ORDER', 'purchase_order.approve', '确认采购订单并进入待到货', 'approved', 'App\\Services\\Erp\\ApprovalActions\\PurchaseOrderAction'],
            ['PURCHASE_ORDER', 'purchase_order.reject', '退回采购订单草稿', 'rejected', 'App\\Services\\Erp\\ApprovalActions\\PurchaseOrderAction'],
            ['SALES_ORDER_CHANGE', 'sales_order_change.decide', '执行销售订单变更节点决策', 'node_decision', 'App\\Services\\Erp\\ApprovalActions\\SalesOrderChangeAction'],
        ];
    }

    private function seedBusinessEventsAndActions(): void
    {
        if (!Schema::hasTable('erp_approval_business_events') || !Schema::hasTable('erp_approval_business_actions')) {
            return;
        }

        $objectIds = DB::table('erp_approval_business_objects')
            ->whereIn('object_code', ['PURCHASE_ORDER', 'SALES_ORDER_CHANGE', 'CUSTOM_FORM_SUBMISSION'])
            ->pluck('id', 'object_code');

        foreach ([
            ['PURCHASE_ORDER', 'submit_approval', '提交采购订单审核', true, true],
            ['SALES_ORDER_CHANGE', 'submit_change', '提交订单变更审核', false, true],
            ['CUSTOM_FORM_SUBMISSION', 'submit_form', '提交自定义表单', true, true],
        ] as [$objectCode, $eventCode, $eventName, $manualStart, $eventTrigger]) {
            if (!isset($objectIds[$objectCode])) {
                continue;
            }
            $this->upsert('erp_approval_business_events', [
                'business_object_id' => $objectIds[$objectCode],
                'event_code' => $eventCode,
            ], [
                'event_name' => $eventName,
                'manual_start_allowed' => $manualStart,
                'event_trigger_allowed' => $eventTrigger,
                'enabled' => true,
            ]);
        }

        $actions = $this->businessActions();

        foreach ($actions as [$objectCode, $actionCode, $actionName, $resultEvent, $handlerClass]) {
            $objectId = $objectCode === null ? null : ($objectIds[$objectCode] ?? null);
            if ($objectCode !== null && !$objectId) {
                continue;
            }

            $this->upsert('erp_approval_business_actions', ['action_code' => $actionCode], [
                'business_object_id' => $objectId,
                'action_name' => $actionName,
                'result_event' => $resultEvent,
                'handler_class' => $handlerClass,
                'config_schema' => null,
                'enabled' => true,
            ]);
        }
    }

    private function seedSalesOrderChangeFlow(): void
    {
        if (!Schema::hasTable('erp_approval_flow_versions')) {
            return;
        }

        // 既有模板的当前版和所有历史版都属于配置事实；缺版必须正式修复，不能补造已发布快照。
        if (DB::table('erp_approval_flow_templates')->where('flow_code', 'SALES_ORDER_CHANGE')->exists()) return;

        // 安装补缺只提供当前格式草稿；发布必须走正式验证和冲突检查。
        $definition = [
            'schema_version' => 2,
            'source_mode' => 'existing',
            'business_object_code' => 'SALES_ORDER_CHANGE',
            'event_code' => 'submit_change',
            'trigger_mode' => 'EVENT_TRIGGER',
            'execution_mode' => 'BEFORE_ACTION',
            'priority' => 100,
            'match_strategy' => 'FIRST_MATCH',
            'start_conditions' => [],
            // 完成动作需要业务负责人确认，不能从旧版回调字符串猜测当前执行语义。
            'completion_actions' => [],
            'processing_mode' => 'sequential',
            'source_table' => 'erp_sales_order_change_candidates',
            'applicable_scope' => [
                'type' => 'all',
                'department_ids' => [],
            ],
            'nodes' => [
                $this->flowNode('business', '业务审核', 'business', 'sales_order.change.approve_business', 4, [
                    'type' => 'role',
                    'value' => 'sales_manager',
                ], 'business'),
                $this->flowNode('finance', '财务审核', 'finance', 'sales_order.change.approve_finance', 8, [
                    'type' => 'role',
                    'value' => 'finance_manager',
                ], 'finance'),
                $this->flowNode('fulfillment', '履约复核', 'fulfillment', 'sales_order.change.approve_fulfillment', 12, [
                    'type' => 'department_principal',
                    'value' => 'task_department',
                    'label' => '业务发起人所属部门负责人',
                ], 'fulfillment'),
            ],
            'notifications' => [
                'websocket' => true,
                'in_app' => true,
                'internal' => true,
                'email' => false,
            ],
            'allow_self_approval' => false,
        ];

        if (!SeedSafety::insertMissing('erp_approval_flow_templates', ['flow_code' => 'SALES_ORDER_CHANGE'], [
            'flow_name' => '销售订单变更审核',
            'business_module' => '销售管理',
            'business_type' => 'SALES_ORDER_CHANGE',
            'business_object_code' => 'SALES_ORDER_CHANGE',
            'event_code' => 'submit_change',
            'business_scene' => '销售订单变更',
            'applicable_scope' => 'all',
            'trigger_mode' => 'EVENT_TRIGGER',
            'execution_mode' => 'BEFORE_ACTION',
            'priority' => 100,
            'match_strategy' => 'FIRST_MATCH',
            'status' => 'draft',
            'current_version' => 0,
            'description' => '销售订单变更审核草稿；请确认完成动作并通过正式流程验证后发布。',
            'created_by' => '系统',
            'updated_by' => '系统',
        ])) return;

        $templateId = (int) DB::table('erp_approval_flow_templates')
            ->where('flow_code', 'SALES_ORDER_CHANGE')
            ->value('id');

        $this->upsert('erp_approval_flow_versions', [
            'flow_template_id' => $templateId,
            'version_no' => 1,
        ], [
            'version_status' => 'draft',
            'definition_snapshot' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'validation_snapshot' => null,
            'published_by' => null,
            'published_at' => null,
            'updated_by' => '系统',
        ]);
    }

    private function flowNode(string $key, string $name, string $approvalType, string $permissionCode, int $slaHours, array $approverRule, string $requirement): array
    {
        $conditions = [[
            'field' => 'approval_requirements',
            'operator' => 'contains',
            'value' => $requirement,
        ]];

        return [
            'key' => $key,
            'node_type' => 'APPROVAL',
            'name' => $name,
            'approval_type' => $approvalType,
            'permission_code' => $permissionCode,
            'sla_hours' => $slaHours,
            'approver_rule' => $approverRule,
            'condition' => $conditions,
            'conditions' => $conditions,
            'processing_strategy' => 'sequential',
            'allow_reject' => true,
            'allow_transfer' => true,
            'comment_required' => true,
            'reminder_hours' => 1,
            'reject_strategy' => 'previous',
        ];
    }

    private function upsert(string $table, array $key, array $values): void
    {
        SeedSafety::insertMissing($table, $key, $values);
    }
}
