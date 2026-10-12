<?php

namespace Database\Seeders;

use App\Services\Erp\RbacBootstrapService;
use Illuminate\Database\Seeder;
use Database\Seeders\Support\RunsSafeSeed;
use Database\Seeders\Support\SeedSafety;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpRbacSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        // 默认填充绝不授予账号/角色权限，也不迁移或删除旧权限身份。
        (new RbacBootstrapService())->seedDefinitionsOnly();
        $this->seedReferenceRoles();
        $this->normalizeDocumentNumberPermissions();
        $this->normalizeApprovalPermissions();
    }

    private function seedReferenceRoles(): void
    {
        foreach ([
            'sales_manager' => '销售负责人',
            'finance_manager' => '财务负责人',
            'fulfillment_manager' => '履约负责人',
        ] as $code => $name) {
            $this->upsert('erp_rbac_roles', ['code' => $code], [
                'name' => $name,
                'data_scope' => 'department',
                'enabled' => true,
                'remark' => '审核流程处理人来源可选角色',
            ]);
        }
    }

    private function normalizeDocumentNumberPermissions(): void
    {
        $systemId = $this->permissionId('system');
        $menuId = $this->upsertPermission([
            'code' => 'system.document_number_rule',
            'parent_id' => $systemId,
            'name' => '编号规则',
            'type' => 'menu',
            'path' => '/system/document-number-rules',
            'component' => 'DocumentNumberRuleList',
            'icon' => 'el-icon-postcard',
            'sort' => 905,
            'enabled' => true,
        ]);

        $this->upsertPermission([
            'code' => 'document_number_rule.view',
            'parent_id' => $menuId,
            'name' => '查看编号规则',
            'type' => 'button',
            'path' => null,
            'component' => null,
            'icon' => 'el-icon-mouse',
            'sort' => 1,
            'enabled' => true,
        ]);

        $this->upsertPermission([
            'code' => 'document_number_rule.manage',
            'parent_id' => $menuId,
            'name' => '管理编号规则',
            'type' => 'button',
            'path' => null,
            'component' => null,
            'icon' => 'el-icon-mouse',
            'sort' => 2,
            'enabled' => true,
        ]);
    }

    private function normalizeApprovalPermissions(): void
    {
        $approvalId = $this->upsertPermission([
            'code' => 'approval',
            'parent_id' => null,
            'name' => '审核中心',
            'type' => 'menu',
            'path' => null,
            'component' => null,
            'icon' => 'el-icon-circle-check',
            'sort' => 700,
            'enabled' => true,
        ]);

        $workbenchId = $this->upsertPermission([
            'code' => 'approval.todo',
            'parent_id' => $approvalId,
            'name' => '审核工作台',
            'type' => 'menu',
            'path' => '/approvals/tasks',
            'component' => 'ApprovalWorkbench',
            'icon' => 'el-icon-circle-check',
            'sort' => 701,
            'enabled' => true,
        ]);

        $flowId = $this->upsertPermission([
            'code' => 'approval.flow',
            'parent_id' => $approvalId,
            'name' => '流程配置',
            'type' => 'menu',
            'path' => '/approvals/flows',
            'component' => 'ApprovalFlowList',
            'icon' => 'el-icon-circle-check',
            'sort' => 704,
            'enabled' => true,
        ]);

        $formId = $this->upsertPermission([
            'code' => 'approval.form',
            'parent_id' => $approvalId,
            'name' => '表单管理',
            'type' => 'menu',
            'path' => '/approvals/forms',
            'component' => 'ApprovalFormList',
            'icon' => 'el-icon-document',
            'sort' => 705,
            'enabled' => true,
        ]);

        foreach ([
            ['approval.processed', $workbenchId, '查看我的已处理', 10],
            ['approval.all', $workbenchId, '查看全部审核', 11],
            ['approval.task.view', $workbenchId, '查看审核任务', 1],
            ['approval.task.decide', $workbenchId, '处理审核任务', 2],
            ['approval.task.batch_decide', $workbenchId, '批量处理审核任务', 3],
            ['approval.task.submit', $approvalId, '提交通用审核任务', 20],
            ['approval.flow.view', $flowId, '查看审核流程', 1],
            ['approval.flow.edit', $flowId, '编辑审核流程', 2],
            ['approval.flow.publish', $flowId, '发布审核流程', 3],
            ['approval.flow.toggle', $flowId, '启用/停用审核流程', 4],
            ['approval.form.view', $formId, '查看自定义表单', 1],
            ['approval.form.edit', $formId, '编辑自定义表单', 2],
            ['approval.form.publish', $formId, '发布自定义表单', 3],
            ['approval.form.toggle', $formId, '启用/停用自定义表单', 4],
            ['approval.form.submit', $formId, '提交自定义表单申请', 5],
        ] as [$code, $parentId, $name, $sort]) {
            $this->upsertPermission([
                'code' => $code,
                'parent_id' => $parentId,
                'name' => $name,
                'type' => 'button',
                'path' => null,
                'component' => null,
                'icon' => 'el-icon-mouse',
                'sort' => $sort,
                'enabled' => true,
                'remark' => $code === 'approval.task.submit'
                    ? '业务模块根据流程配置的数据表和条件提交审核任务'
                    : null,
            ]);
        }
    }

    private function permissionId(string $code): ?int
    {
        $id = DB::table('erp_rbac_permissions')->where('code', $code)->value('id');
        return $id ? (int) $id : null;
    }

    private function upsertPermission(array $data): int
    {
        $code = $data['code'];
        unset($data['code']);
        $this->upsert('erp_rbac_permissions', ['code' => $code], $data);
        return (int) DB::table('erp_rbac_permissions')->where('code', $code)->value('id');
    }

    private function upsert(string $table, array $key, array $values): void
    {
        SeedSafety::insertMissing($table, $key, $values);
    }
}

