<?php

namespace Database\Seeders;

use App\Services\Erp\RbacBootstrapService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpRbacSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('erp_rbac_permissions') || !Schema::hasTable('erp_rbac_roles')) {
            return;
        }

        // Keep the application's current RBAC definition as the canonical full tree.
        // Historical migrations must never call this service again; seeding is the right place.
        app(RbacBootstrapService::class)->bootstrap();

        $this->seedReferenceRoles();
        $this->normalizeDocumentNumberPermissions();
        $this->normalizeItemCategoryPermissions();
        $this->normalizeBomExpandPermissions();
        $this->normalizeFinanceNavigation();
        $this->normalizeApprovalPermissions();
        $this->grantApprovalRolePermissions();
        $this->grantAllPermissionsToAdmin();
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

    private function normalizeItemCategoryPermissions(): void
    {
        $masterId = $this->permissionId('master');
        $viewId = $this->upsertPermission([
            'code' => 'item_category.view',
            'parent_id' => $masterId,
            'name' => 'Item 类目',
            'type' => 'menu',
            'path' => '/master/categories',
            'component' => 'ItemCategoryList',
            'icon' => 'el-icon-collection-tag',
            'sort' => 205,
            'enabled' => true,
        ]);

        $manageId = $this->upsertPermission([
            'code' => 'item_category.manage',
            'parent_id' => $viewId,
            'name' => '管理 Item 类目',
            'type' => 'button',
            'path' => null,
            'component' => null,
            'icon' => 'el-icon-mouse',
            'sort' => 1,
            'enabled' => true,
        ]);

        // Retire the old duplicate code while preserving any historic grants.
        $legacy = DB::table('erp_rbac_permissions')->where('code', 'item_category.edit')->first();
        if ($legacy) {
            if (Schema::hasTable('erp_rbac_role_permissions')) {
                foreach (DB::table('erp_rbac_role_permissions')->where('permission_id', $legacy->id)->pluck('role_id') as $roleId) {
                    DB::table('erp_rbac_role_permissions')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $manageId,
                    ]);
                }
                DB::table('erp_rbac_role_permissions')->where('permission_id', $legacy->id)->delete();
            }
            DB::table('erp_rbac_permissions')->where('id', $legacy->id)->delete();
        }
    }

    private function normalizeBomExpandPermissions(): void
    {
        $bomId = $this->permissionId('bom');
        $menuId = $this->upsertPermission([
            'code' => 'bom.expand',
            'parent_id' => $bomId,
            'name' => 'BOM展开',
            'type' => 'menu',
            'path' => '/bom/expand',
            'component' => 'BomExpand',
            'icon' => 'el-icon-share',
            'sort' => 402,
            'enabled' => true,
        ]);

        DB::table('erp_rbac_permissions')
            ->whereIn('code', ['bom.expand.calculate', 'bom.expand.export'])
            ->update(['parent_id' => $menuId, 'updated_at' => now()]);
    }

    private function normalizeFinanceNavigation(): void
    {
        $financeId = $this->permissionId('finance');
        if (!$financeId) {
            return;
        }

        $menus = [
            'finance.receipt' => ['收款管理', '/finance/receipts', 'FinanceCashList', 'el-icon-money', 601],
            'finance.payment' => ['付款管理', '/finance/payments', 'FinanceCashList', 'el-icon-wallet', 602],
            'finance.supplier-ledger' => ['供应商往来', '/finance/supplier-ledgers', 'FinanceSupplierLedgerList', 'el-icon-office-building', 604],
            'finance.allocation' => ['往来核销', '/finance/allocations', 'FinanceAllocationList', 'el-icon-connection', 606],
            'finance.transfer' => ['资金转账 / 换汇', '/finance/transfers', 'FinanceTransferList', 'el-icon-sort', 608],
            'finance.account_valuation' => ['资金账户估值', '/finance/account-valuations', 'FinanceAccountValuation', 'el-icon-pie-chart', 609],
        ];

        $ids = [];
        foreach ($menus as $code => [$name, $path, $component, $icon, $sort]) {
            $ids[$code] = $this->upsertPermission([
                'code' => $code,
                'parent_id' => $financeId,
                'name' => $name,
                'type' => 'menu',
                'path' => $path,
                'component' => $component,
                'icon' => $icon,
                'sort' => $sort,
                'enabled' => true,
            ]);
        }

        $parentMap = [
            'finance.receipt.view' => 'finance.receipt',
            'finance.receipt.create' => 'finance.receipt',
            'finance.receipt.confirm' => 'finance.receipt',
            'finance.receipt.void' => 'finance.receipt',
            'finance.payment.view' => 'finance.payment',
            'finance.payment.create' => 'finance.payment',
            'finance.payment.confirm' => 'finance.payment',
            'finance.payment.void' => 'finance.payment',
            'finance.supplier-ledger.view' => 'finance.supplier-ledger',
            'finance.supplier-ledger.export' => 'finance.supplier-ledger',
            'finance.allocation.view' => 'finance.allocation',
            'finance.allocation.create' => 'finance.allocation',
            'finance.allocation.reverse' => 'finance.allocation',
            'finance.account.valuation' => 'finance.account_valuation',
            'finance.exchange-rate.view' => 'finance.account_valuation',
            'finance.exchange-rate.create' => 'finance.account_valuation',
        ];

        foreach ($parentMap as $buttonCode => $parentCode) {
            DB::table('erp_rbac_permissions')->where('code', $buttonCode)->update([
                'parent_id' => $ids[$parentCode],
                'updated_at' => now(),
            ]);
        }

        foreach ([
            ['finance.transfer.view', '查看资金转账 / 换汇', 1],
            ['finance.transfer.create', '新建资金转账 / 换汇', 2],
            ['finance.transfer.confirm', '确认资金转账 / 换汇', 3],
        ] as [$code, $name, $sort]) {
            $this->upsertPermission([
                'code' => $code,
                'parent_id' => $ids['finance.transfer'],
                'name' => $name,
                'type' => 'button',
                'path' => null,
                'component' => null,
                'icon' => 'el-icon-mouse',
                'sort' => $sort,
                'enabled' => true,
            ]);
        }

        // Remove obsolete duplicate navigation nodes only when a replacement exists.
        $this->removeDuplicatePermission('finance.cash', 'finance.receipt');
        $this->removeDuplicatePermission('finance.exchange-rate', 'finance.account_valuation');
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

    private function grantApprovalRolePermissions(): void
    {
        $map = [
            'sales_manager' => 'sales_order.change.approve_business',
            'finance_manager' => 'sales_order.change.approve_finance',
            'fulfillment_manager' => 'sales_order.change.approve_fulfillment',
        ];

        foreach ($map as $roleCode => $permissionCode) {
            $roleId = DB::table('erp_rbac_roles')->where('code', $roleCode)->value('id');
            $permissionId = $this->permissionId($permissionCode);
            if ($roleId && $permissionId) {
                DB::table('erp_rbac_role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    private function grantAllPermissionsToAdmin(): void
    {
        if (!Schema::hasTable('erp_rbac_role_permissions')) {
            return;
        }

        $adminRoleId = DB::table('erp_rbac_roles')->where('code', 'admin')->value('id');
        if (!$adminRoleId) {
            return;
        }

        foreach (DB::table('erp_rbac_permissions')->pluck('id') as $permissionId) {
            DB::table('erp_rbac_role_permissions')->insertOrIgnore([
                'role_id' => $adminRoleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    private function removeDuplicatePermission(string $legacyCode, string $targetCode): void
    {
        $legacy = DB::table('erp_rbac_permissions')->where('code', $legacyCode)->first();
        $target = DB::table('erp_rbac_permissions')->where('code', $targetCode)->first();
        if (!$legacy || !$target || $legacy->id === $target->id) {
            return;
        }

        if (Schema::hasTable('erp_rbac_role_permissions')) {
            foreach (DB::table('erp_rbac_role_permissions')->where('permission_id', $legacy->id)->pluck('role_id') as $roleId) {
                DB::table('erp_rbac_role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $target->id,
                ]);
            }
            DB::table('erp_rbac_role_permissions')->where('permission_id', $legacy->id)->delete();
        }

        DB::table('erp_rbac_permissions')->where('parent_id', $legacy->id)->update([
            'parent_id' => $target->id,
            'updated_at' => now(),
        ]);
        DB::table('erp_rbac_permissions')->where('id', $legacy->id)->delete();
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
        $exists = DB::table($table)->where($key)->exists();
        $payload = $values + ['updated_at' => now()];
        if (!$exists) {
            $payload['created_at'] = now();
        }
        DB::table($table)->updateOrInsert($key, $payload);
    }
}

