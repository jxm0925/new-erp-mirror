<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RbacBootstrapService
{
    public function bootstrap(bool $force = false): void
    {
        if ($force) {
            $this->syncDepartments(true);
        }
        $permissionsChanged = $this->seedPermissions($force);
        $this->seedRoles($force || $permissionsChanged);
    }

    public function syncDepartments(bool $force = false): void
    {
        // Departments and principals are maintained in this ERP only.
        // This method intentionally remains a no-op so historic callers cannot
        // reopen an old-system database connection.
    }

    private function seedPermissions(bool $force = false): bool
    {
        $permissions = [
            // 一级模块 (7 大模块)
            ['master', '主数据中心', 'menu', null, null, null, 'el-icon-s-grid', 100],
            ['production', '生产管理', 'menu', null, null, null, 'el-icon-s-operation', 800],
            ['purchase', '采购管理', 'menu', null, null, null, 'el-icon-shopping-cart-2', 200],
            ['inventory', '库存管理', 'menu', null, null, null, 'el-icon-house', 300],
            ['bom', 'BOM管理', 'menu', null, null, null, 'el-icon-connection', 400],
            ['sales', '销售管理', 'menu', null, null, null, 'el-icon-s-order', 500],
            ['finance', '财务管理', 'menu', null, null, null, 'el-icon-bank-card', 600],
            ['approval', '审核中心', 'menu', null, null, null, 'el-icon-circle-check', 700],
            ['system', '系统管理', 'menu', null, null, null, 'el-icon-setting', 900],

            ['production.demand', '生产需求', 'menu', 'production', '/production/demands', 'ProductionDemandList', 'el-icon-document', 801],
            ['production.work_order', '工单管理', 'menu', 'production', '/production/work-orders', 'WorkOrderList', 'el-icon-s-order', 802],
            ['production.base', '生产基础', 'menu', 'production', null, null, 'el-icon-setting', 803],
            ['production.operation', '工序管理', 'menu', 'production.base', '/production/operations', 'ProductionOperationList', 'el-icon-set-up', 804],
            ['production.routing', '工艺路线', 'menu', 'production.base', '/production/routings', 'ProductionRoutingList', 'el-icon-guide', 805],
            ['production.labor_rule', '工时分配规则', 'menu', 'production.base', '/production/labor-allocation-rules', 'ProductionLaborAllocationRules', 'el-icon-timer', 806],

            // 1. 主数据中心 二级菜单
            ['master.product', '产品管理', 'menu', 'master', '/master/products', 'ProductList', 'el-icon-goods', 101],
            ['master.sku', 'SKU管理', 'menu', 'master', '/master/skus', 'SkuList', 'el-icon-box', 102],
            ['master.item', '物料管理', 'menu', 'master', '/master/items', 'ItemList', 'el-icon-coin', 103],
            ['item_category.view', '物料类目', 'menu', 'master', '/master/categories', 'ItemCategoryList', 'el-icon-folder-opened', 104],
            ['master.sku_item_relation', 'SKU-物料默认关系', 'menu', 'master', '/master/sku-item-relations', 'SkuItemRelationList', 'el-icon-connection', 105],
            ['master.base_archive', '基础档案', 'menu', 'master', '/master/base-archives', 'UnitList', 'el-icon-files', 106],
            ['master.supplier', '供应商管理', 'menu', 'master', '/master/suppliers', 'SupplierList', 'el-icon-truck', 107],
            ['master.warehouse_location', '仓库与库位', 'menu', 'master', '/master/warehouse-locations', 'WarehouseList', 'el-icon-office-building', 108],
            ['master.import', '数据导入', 'menu', 'master', '/master/imports', 'ImportWorkbench', 'el-icon-upload2', 109],

            // 主数据中心 按钮
            ['master.product.view', '查看产品', 'button', 'master.product', null, null, 'el-icon-mouse', 1],
            ['master.product.create', '新增产品', 'button', 'master.product', null, null, 'el-icon-mouse', 2],
            ['master.product.edit', '编辑产品', 'button', 'master.product', null, null, 'el-icon-mouse', 3],
            ['master.product.delete', '删除产品', 'button', 'master.product', null, null, 'el-icon-mouse', 4],
            ['master.product.export', '导出产品', 'button', 'master.product', null, null, 'el-icon-mouse', 5],

            ['master.sku.view', '查看SKU', 'button', 'master.sku', null, null, 'el-icon-mouse', 1],
            ['master.sku.create', '新增SKU', 'button', 'master.sku', null, null, 'el-icon-mouse', 2],
            ['master.sku.edit', '编辑SKU', 'button', 'master.sku', null, null, 'el-icon-mouse', 3],
            ['master.sku.delete', '删除SKU', 'button', 'master.sku', null, null, 'el-icon-mouse', 4],
            ['master.sku.export', '导出SKU', 'button', 'master.sku', null, null, 'el-icon-mouse', 5],

            ['master.item.view', '查看物料', 'button', 'master.item', null, null, 'el-icon-mouse', 1],
            ['master.item.create', '新增物料', 'button', 'master.item', null, null, 'el-icon-mouse', 2],
            ['master.item.edit', '编辑物料', 'button', 'master.item', null, null, 'el-icon-mouse', 3],
            ['master.item.delete', '删除物料', 'button', 'master.item', null, null, 'el-icon-mouse', 4],
            ['master.item.export', '导出物料', 'button', 'master.item', null, null, 'el-icon-mouse', 5],

            ['item_category.manage', '管理 Item 类目', 'button', 'item_category.view', null, null, 'el-icon-mouse', 1],

            ['master.sku_item_relation.view', '查看关系', 'button', 'master.sku_item_relation', null, null, 'el-icon-mouse', 1],
            ['master.sku_item_relation.create', '新增关系', 'button', 'master.sku_item_relation', null, null, 'el-icon-mouse', 2],
            ['master.sku_item_relation.set_primary', '设为主物料', 'button', 'master.sku_item_relation', null, null, 'el-icon-mouse', 3],
            ['sku_item_relation.repair', '数据完整性校验修复', 'button', 'master.sku_item_relation', null, null, 'el-icon-mouse', 4],

            ['master.base_archive.view', '查看档案', 'button', 'master.base_archive', null, null, 'el-icon-mouse', 1],
            ['master.base_archive.create', '新增/维护单位换算', 'button', 'master.base_archive', null, null, 'el-icon-mouse', 2],

            ['master.supplier.view', '查看供应商', 'button', 'master.supplier', null, null, 'el-icon-mouse', 1],
            ['master.supplier.create', '新增供应商', 'button', 'master.supplier', null, null, 'el-icon-mouse', 2],
            ['master.supplier.edit', '编辑供应商', 'button', 'master.supplier', null, null, 'el-icon-mouse', 3],
            ['supplier_capability.edit', '维护供货能力', 'button', 'master.supplier', null, null, 'el-icon-mouse', 4],
            ['supplier_quotation.edit', '维护供应商报价', 'button', 'master.supplier', null, null, 'el-icon-mouse', 5],

            ['master.warehouse.view', '查看仓库库位', 'button', 'master.warehouse_location', null, null, 'el-icon-mouse', 1],
            ['master.warehouse.create', '新增仓库', 'button', 'master.warehouse_location', null, null, 'el-icon-mouse', 2],
            ['master.location.create', '新增库位', 'button', 'master.warehouse_location', null, null, 'el-icon-mouse', 3],
            ['master.warehouse.edit', '编辑仓库库位', 'button', 'master.warehouse_location', null, null, 'el-icon-mouse', 4],

            ['master.import.upload', '上传数据文件', 'button', 'master.import', null, null, 'el-icon-mouse', 1],
            ['master.import.execute', '执行导入入库', 'button', 'master.import', null, null, 'el-icon-mouse', 2],

            // 2. 采购管理 二级菜单
            ['purchase.request', '采购需求', 'menu', 'purchase', '/purchase/requests', 'PurchaseRequestList', 'el-icon-shopping-cart-2', 201],
            ['purchase.plan', '采购计划', 'menu', 'purchase', '/purchase/plans', 'PurchasePlanList', 'el-icon-document', 202],
            ['purchase.order', '采购订单', 'menu', 'purchase', '/purchase/orders', 'PurchaseOrderList', 'el-icon-s-order', 203],
            ['purchase.receipt', '采购到货', 'menu', 'purchase', '/purchase/receipts', 'PurchaseReceiptList', 'el-icon-box', 204],
            ['purchase.return', '采购退货', 'menu', 'purchase', '/purchase/returns', 'PurchaseReturnList', 'el-icon-refresh-left', 205],

            // 采购管理 按钮
            ['purchase.request.view', '查看需求', 'button', 'purchase.request', null, null, 'el-icon-mouse', 1],
            ['purchase.request.create', '新增需求', 'button', 'purchase.request', null, null, 'el-icon-mouse', 2],
            ['purchase.request.edit', '编辑需求', 'button', 'purchase.request', null, null, 'el-icon-mouse', 3],
            ['purchase.request.delete', '删除需求', 'button', 'purchase.request', null, null, 'el-icon-mouse', 4],
            ['purchase.request.submit', '提交需求', 'button', 'purchase.request', null, null, 'el-icon-mouse', 5],
            ['purchase.request.audit', '审核需求', 'button', 'purchase.request', null, null, 'el-icon-mouse', 6],

            ['purchase.plan.view', '查看计划', 'button', 'purchase.plan', null, null, 'el-icon-mouse', 1],
            ['purchase.plan.create', '新增计划', 'button', 'purchase.plan', null, null, 'el-icon-mouse', 2],
            ['purchase.plan.edit', '编辑计划', 'button', 'purchase.plan', null, null, 'el-icon-mouse', 3],
            ['purchase.plan.delete', '删除计划', 'button', 'purchase.plan', null, null, 'el-icon-mouse', 4],
            ['purchase.plan.approve', '审核采购计划', 'button', 'purchase.plan', null, null, 'el-icon-mouse', 5],
            ['purchase.plan.generate_order', '从计划生成订单', 'button', 'purchase.plan', null, null, 'el-icon-mouse', 6],

            ['purchase.order.view', '查看订单', 'button', 'purchase.order', null, null, 'el-icon-mouse', 1],
            ['purchase.order.create', '新增订单', 'button', 'purchase.order', null, null, 'el-icon-mouse', 2],
            ['purchase.order.edit', '编辑订单', 'button', 'purchase.order', null, null, 'el-icon-mouse', 3],
            ['purchase.order.delete', '删除草稿', 'button', 'purchase.order', null, null, 'el-icon-mouse', 4],
            ['purchase.order.approve', '审核采购订单', 'button', 'purchase.order', null, null, 'el-icon-mouse', 5],
            ['purchase.order.cancel', '取消订单', 'button', 'purchase.order', null, null, 'el-icon-mouse', 6],
            ['purchase.order.generate', '从计划生成订单', 'button', 'purchase.order', null, null, 'el-icon-mouse', 7],

            ['purchase.receipt.view', '查看到货单', 'button', 'purchase.receipt', null, null, 'el-icon-mouse', 1],
            ['purchase.receipt.create', '新增到货单', 'button', 'purchase.receipt', null, null, 'el-icon-mouse', 2],
            ['purchase.receipt.edit', '编辑到货单', 'button', 'purchase.receipt', null, null, 'el-icon-mouse', 3],
            ['purchase.receipt.confirm', '确认采购到货', 'button', 'purchase.receipt', null, null, 'el-icon-mouse', 4],
            ['purchase.quality.view', '查看不合格品处理', 'button', 'purchase.receipt', null, null, 'el-icon-mouse', 5],
            ['purchase.quality.handle', '执行不合格品处理', 'button', 'purchase.receipt', null, null, 'el-icon-mouse', 6],
            ['purchase.exchange.view', '查看采购换货单', 'button', 'purchase.receipt', null, null, 'el-icon-mouse', 7],
            ['purchase.exchange.manage', '执行采购换货动作', 'button', 'purchase.receipt', null, null, 'el-icon-mouse', 8],

            ['purchase_return.view', '查看采购退货', 'button', 'purchase.return', null, null, 'el-icon-mouse', 1],
            ['purchase_return.create', '新建采购退货', 'button', 'purchase.return', null, null, 'el-icon-mouse', 2],
            ['purchase_return.submit', '提交采购退货', 'button', 'purchase.return', null, null, 'el-icon-mouse', 3],
            ['purchase_return.approve', '审核采购退货', 'button', 'purchase.return', null, null, 'el-icon-mouse', 4],
            ['purchase_return.post', '采购退货出库过账', 'button', 'purchase.return', null, null, 'el-icon-mouse', 5],
            ['purchase_return.cancel', '取消采购退货', 'button', 'purchase.return', null, null, 'el-icon-mouse', 6],

            // 3. 库存管理 二级菜单
            ['inventory.posting', '库存过账工作台', 'menu', 'inventory', '/inventory/posting', 'InventoryBoard', 'el-icon-finished', 301],
            ['inventory.balance', '库存余额', 'menu', 'inventory', '/inventory/balances', 'InventoryBoard', 'el-icon-coin', 302],
            ['inventory.transaction', '库存流水', 'menu', 'inventory', '/inventory/transactions', 'InventoryBoard', 'el-icon-tickets', 303],
            ['inventory.adjustment', '手工调整', 'menu', 'inventory', '/inventory/adjustments', 'InventoryBoard', 'el-icon-edit-outline', 304],
            ['inventory.alert', '库存预警', 'menu', 'inventory', '/inventory/alerts', 'InventoryAlertWorkbench', 'el-icon-warning-outline', 305],

            // 库存管理 按钮
            ['inventory.post.view', '查看待过账单据', 'button', 'inventory.posting', null, null, 'el-icon-mouse', 1],
            ['inventory.post.execute', '执行库存过账', 'button', 'inventory.posting', null, null, 'el-icon-mouse', 2],
            ['inventory.post.repair', '补充到货入库分配', 'button', 'inventory.posting', null, null, 'el-icon-mouse', 3],

            ['inventory.balance.view', '查看库存余额', 'button', 'inventory.balance', null, null, 'el-icon-mouse', 1],
            ['inventory.balance.export', '导出库存余额', 'button', 'inventory.balance', null, null, 'el-icon-mouse', 2],

            ['inventory.transaction.view', '查看库存流水', 'button', 'inventory.transaction', null, null, 'el-icon-mouse', 1],
            ['inventory.transaction.export', '导出库存流水', 'button', 'inventory.transaction', null, null, 'el-icon-mouse', 2],

            ['inventory.adjustment.view', '查看手工调整', 'button', 'inventory.adjustment', null, null, 'el-icon-mouse', 1],
            ['inventory.adjustment.create', '新建盘盈盘亏调整', 'button', 'inventory.adjustment', null, null, 'el-icon-mouse', 2],
            ['inventory.adjustment.confirm', '确认调整过账', 'button', 'inventory.adjustment', null, null, 'el-icon-mouse', 3],
            ['inventory.alert.view', '查看库存预警', 'button', 'inventory.alert', null, null, 'el-icon-mouse', 1],
            ['inventory.alert.configure', '配置库存预警', 'button', 'inventory.alert', null, null, 'el-icon-mouse', 2],
            ['inventory.alert.create_request', '从预警生成采购需求', 'button', 'inventory.alert', null, null, 'el-icon-mouse', 3],

            // 4. BOM管理 二级菜单
            ['bom.manage', 'BOM管理', 'menu', 'bom', '/bom/boms', 'BomList', 'el-icon-connection', 401],
            ['bom.expand', 'BOM展开', 'menu', 'bom', '/bom/expand', 'BomExpand', 'el-icon-share', 402],

            // BOM管理 按钮
            ['bom.manage.view', '查看BOM详情', 'button', 'bom.manage', null, null, 'el-icon-mouse', 1],
            ['bom.manage.create', '新增BOM', 'button', 'bom.manage', null, null, 'el-icon-mouse', 2],
            ['bom.manage.edit', '编辑BOM', 'button', 'bom.manage', null, null, 'el-icon-mouse', 3],
            ['bom.manage.delete', '删除BOM草稿', 'button', 'bom.manage', null, null, 'el-icon-mouse', 4],
            ['bom.manage.toggle_status', '启用/停用BOM', 'button', 'bom.manage', null, null, 'el-icon-mouse', 5],

            ['bom.expand.calculate', '执行展开计算', 'button', 'bom.expand', null, null, 'el-icon-mouse', 1],
            ['bom.expand.export', '导出BOM展开表', 'button', 'bom.expand', null, null, 'el-icon-mouse', 2],

            // 5. 销售管理 二级菜单
            ['sales.customer', '客户管理', 'menu', 'sales', '/sales/customers', 'SalesCustomerList', 'el-icon-user', 501],
            ['sales.order', '销售订单', 'menu', 'sales', '/sales/orders', 'SalesOrderList', 'el-icon-s-order', 502],
            ['sales.return', '销售退货', 'menu', 'sales', '/sales/returns', 'ReturnList', 'el-icon-refresh-left', 503],

            // 销售管理 按钮
            ['sales.customer.view', '查看客户', 'button', 'sales.customer', null, null, 'el-icon-mouse', 1],
            ['sales.customer.create', '新增客户', 'button', 'sales.customer', null, null, 'el-icon-mouse', 2],
            ['sales.customer.edit', '编辑客户', 'button', 'sales.customer', null, null, 'el-icon-mouse', 3],
            ['sales.customer.export', '导出客户清单', 'button', 'sales.customer', null, null, 'el-icon-mouse', 4],

            ['production.demand.view', '查看生产需求', 'button', 'production.demand', null, null, 'el-icon-mouse', 1],
            ['production.work_order.view', '查看工单', 'button', 'production.work_order', null, null, 'el-icon-mouse', 1],
            ['production.work_order.create', '创建工单', 'button', 'production.work_order', null, null, 'el-icon-mouse', 2],
            ['production.work_order.edit', '编辑工单', 'button', 'production.work_order', null, null, 'el-icon-mouse', 3],
            ['production.work_order.submit', '提交工单', 'button', 'production.work_order', null, null, 'el-icon-mouse', 4],
            ['production.work_order.cancel', '取消工单', 'button', 'production.work_order', null, null, 'el-icon-mouse', 5],
            ['production.work_order.gate.view', '查看工单发布检查', 'button', 'production.work_order', null, null, 'el-icon-mouse', 6],
            ['production.work_order.publish', '发布工单', 'button', 'production.work_order', null, null, 'el-icon-mouse', 7],
            ['production.material.view', '查看工单用料需求', 'button', 'production.work_order', null, null, 'el-icon-mouse', 8],
            ['production.material_requirement.view', '查看正式物料需求', 'button', 'production.work_order', null, null, 'el-icon-mouse', 9],
            ['production.material_picking.view', '查看配料任务', 'button', 'production.work_order', null, null, 'el-icon-mouse', 10],
            ['production.material_picking.create', '创建配料任务', 'button', 'production.work_order', null, null, 'el-icon-mouse', 11],
            ['production.material_picking.assign', '分配拣货人', 'button', 'production.work_order', null, null, 'el-icon-mouse', 12],
            ['production.material_picking.pick', '确认生产配料', 'button', 'production.work_order', null, null, 'el-icon-mouse', 13],
            ['production.material_picking.cancel', '取消未执行配料任务', 'button', 'production.work_order', null, null, 'el-icon-mouse', 14],
            ['production.material_delivery.view', '查看配送任务', 'button', 'production.work_order', null, null, 'el-icon-mouse', 15],
            ['production.material_delivery.create', '创建配送单', 'button', 'production.work_order', null, null, 'el-icon-mouse', 16],
            ['production.material_delivery.dispatch', '发出配送单', 'button', 'production.work_order', null, null, 'el-icon-mouse', 17],
            ['production.material_delivery.confirm', '确认配送送达', 'button', 'production.work_order', null, null, 'el-icon-mouse', 18],
            ['production.material_receipt.view', '查看收料记录', 'button', 'production.work_order', null, null, 'el-icon-mouse', 19],
            ['production.material_receipt.confirm', '确认生产收料', 'button', 'production.work_order', null, null, 'el-icon-mouse', 20],
            ['production.task.view', '查看生产任务', 'button', 'production.work_order', null, null, 'el-icon-mouse', 21],
            ['production.task.claim', '自主接单', 'button', 'production.work_order', null, null, 'el-icon-mouse', 22],
            ['production.task.start', '开始工序', 'button', 'production.work_order', null, null, 'el-icon-mouse', 23],
            ['production.task.pause', '暂停或继续工序', 'button', 'production.work_order', null, null, 'el-icon-mouse', 24],
            ['production.task.resume', '继续工序', 'button', 'production.work_order', null, null, 'el-icon-mouse', 24],
            ['production.task.complete', '完成工序', 'button', 'production.work_order', null, null, 'el-icon-mouse', 25],
            ['production.task.collaborate', '生产任务协同', 'button', 'production.work_order', null, null, 'el-icon-mouse', 26],
            ['production.delivery.view', '查看生产配送', 'button', 'production.work_order', null, null, 'el-icon-mouse', 27],
            ['production.delivery.dispatch', '确认配送发出', 'button', 'production.work_order', null, null, 'el-icon-mouse', 28],
            ['production.delivery.receive', '生产配送签收或拒收', 'button', 'production.work_order', null, null, 'el-icon-mouse', 29],
            ['production.kitting.view', '查看物料齐套', 'button', 'production.work_order', null, null, 'el-icon-mouse', 30],
            ['production.kitting.confirm', '确认物料齐套', 'button', 'production.work_order', null, null, 'el-icon-mouse', 31],
            ['production.handover.view', '查看工序交接', 'button', 'production.work_order', null, null, 'el-icon-mouse', 32],
            ['production.handover.receive', '接收工序产出', 'button', 'production.work_order', null, null, 'el-icon-mouse', 33],
            ['production.handover.reject', '拒收工序产出', 'button', 'production.work_order', null, null, 'el-icon-mouse', 34],
            ['production.unit.view', '查看生产单元', 'button', 'production.work_order', null, null, 'el-icon-mouse', 35],
            ['production.output.create', '登记工序产出', 'button', 'production.work_order', null, null, 'el-icon-mouse', 36],
            ['production.output.quality', '生产入库质检', 'button', 'production.work_order', null, null, 'el-icon-mouse', 37],
            ['production.output.warehouse', '生产产出入库', 'button', 'production.work_order', null, null, 'el-icon-mouse', 38],
            ['production.output.issue', '生产半成品发料', 'button', 'production.work_order', null, null, 'el-icon-mouse', 39],
            ['production.output.receive', '生产半成品接收', 'button', 'production.work_order', null, null, 'el-icon-mouse', 40],
            ['production.material_supplement.request', '申请生产补料', 'button', 'production.work_order', null, null, 'el-icon-mouse', 39],
            ['production.material_supplement.approve', '审批生产补料', 'button', 'production.work_order', null, null, 'el-icon-mouse', 40],
            ['production.material_return.create', '发起生产退料', 'button', 'production.work_order', null, null, 'el-icon-mouse', 41],
            ['production.material_return.receive', '仓库接收生产退料', 'button', 'production.work_order', null, null, 'el-icon-mouse', 42],
            ['production.material_return.quality', '检验生产退料', 'button', 'production.work_order', null, null, 'el-icon-mouse', 43],
            ['production.trace.view', '查看逐件生产追溯', 'button', 'production.work_order', null, null, 'el-icon-mouse', 44],
            ['production.assignment.recommend', '查看派单推荐', 'button', 'production.work_order', null, null, 'el-icon-mouse', 45],
            ['production.assignment.auto', '自动派单', 'button', 'production.work_order', null, null, 'el-icon-mouse', 46],
            ['production.assignment.override', '改派生产任务', 'button', 'production.work_order', null, null, 'el-icon-mouse', 47],
            ['production.operation.view', '查看工序', 'button', 'production.operation', null, null, 'el-icon-mouse', 1],
            ['production.operation.create', '新增工序', 'button', 'production.operation', null, null, 'el-icon-mouse', 2],
            ['production.operation.edit', '编辑工序', 'button', 'production.operation', null, null, 'el-icon-mouse', 3],
            ['production.operation.toggle', '启停工序', 'button', 'production.operation', null, null, 'el-icon-mouse', 4],
            ['production.routing.view', '查看工艺路线', 'button', 'production.routing', null, null, 'el-icon-mouse', 1],
            ['production.routing.create', '新增工艺路线', 'button', 'production.routing', null, null, 'el-icon-mouse', 2],
            ['production.routing.edit', '编辑工艺路线', 'button', 'production.routing', null, null, 'el-icon-mouse', 3],
            ['production.routing.activate', '生效工艺路线', 'button', 'production.routing', null, null, 'el-icon-mouse', 4],
            ['production.routing.default', '设置默认工艺路线', 'button', 'production.routing', null, null, 'el-icon-mouse', 5],
            ['production.labor_rule.view', '查看工时分配规则', 'button', 'production.labor_rule', null, null, 'el-icon-mouse', 1],
            ['production.labor_rule.manage', '维护工时分配规则版本', 'button', 'production.labor_rule', null, null, 'el-icon-mouse', 2],
            ['production.labor_stats.view', '查看生产工时统计', 'button', 'production.work_order', null, null, 'el-icon-mouse', 48],

            ['sales_order.view', '销售订单查看', 'button', 'sales.order', null, null, 'el-icon-mouse', 1],
            ['sales_order.create', '销售订单新建', 'button', 'sales.order', null, null, 'el-icon-mouse', 2],
            ['sales_order.edit_draft', '销售订单编辑', 'button', 'sales.order', null, null, 'el-icon-mouse', 3],
            ['sales_order.delete_draft', '销售订单删除', 'button', 'sales.order', null, null, 'el-icon-mouse', 4],
            ['sales_order.submit_confirmation', '销售订单提交确认', 'button', 'sales.order', null, null, 'el-icon-mouse', 5],
            ['sales_order.formal_confirm', '销售订单正式确认', 'button', 'sales.order', null, null, 'el-icon-mouse', 6],
            ['sales_order.production_confirm', '销售订单生产确认', 'button', 'sales.order', null, null, 'el-icon-mouse', 7],
            ['sales_order.inventory_lock', '销售订单锁库存', 'button', 'sales.order', null, null, 'el-icon-mouse', 71],
            ['sales_order.cancel', '销售订单取消', 'button', 'sales.order', null, null, 'el-icon-mouse', 8],
            ['sales_order.change', '销售订单变更', 'button', 'sales.order', null, null, 'el-icon-mouse', 9],
            ['sales_order.change.submit', '提交销售订单变更审核', 'button', 'sales.order', null, null, 'el-icon-mouse', 91],
            ['sales_order.change.approve_business', '审核销售订单业务变更', 'button', 'sales.order', null, null, 'el-icon-mouse', 92],
            ['sales_order.change.approve_finance', '审核销售订单财务变更', 'button', 'sales.order', null, null, 'el-icon-mouse', 93],
            ['sales_order.change.approve_fulfillment', '审核销售订单履约变更', 'button', 'sales.order', null, null, 'el-icon-mouse', 94],
            ['sales_order.upload_attachment', '销售订单附件上传', 'button', 'sales.order', null, null, 'el-icon-mouse', 10],
            ['sales_order.shipment.view', '查看销售发货单', 'button', 'sales.order', null, null, 'el-icon-mouse', 11],
            ['sales_order.shipment.create', '创建销售发货单', 'button', 'sales.order', null, null, 'el-icon-mouse', 12],
            ['sales_order.shipment.confirm', '确认销售发货单', 'button', 'sales.order', null, null, 'el-icon-mouse', 13],
            ['sales_order.shipment.post', '销售出库过账', 'button', 'sales.order', null, null, 'el-icon-mouse', 14],
            ['sales_order.shipment.dispatch', '销售发运', 'button', 'sales.order', null, null, 'el-icon-mouse', 15],
            ['sales_order.shipment.cancel', '取消销售发货单', 'button', 'sales.order', null, null, 'el-icon-mouse', 16],

            ['sales_return.view', '查看销售退货', 'button', 'sales.return', null, null, 'el-icon-mouse', 1],
            ['sales_return.create', '新建销售退货', 'button', 'sales.return', null, null, 'el-icon-mouse', 2],
            ['sales_return.confirm', '确认销售退货', 'button', 'sales.return', null, null, 'el-icon-mouse', 3],
            ['sales_return.receive', '销售退货收货', 'button', 'sales.return', null, null, 'el-icon-mouse', 4],
            ['sales_return.post', '销售退货入库过账', 'button', 'sales.return', null, null, 'el-icon-mouse', 5],
            ['sales_return.cancel', '取消销售退货', 'button', 'sales.return', null, null, 'el-icon-mouse', 6],

            // 6. 财务管理 二级菜单
            ['finance.receipt', '收款管理', 'menu', 'finance', '/finance/receipts', 'FinanceCashList', 'el-icon-money', 601],
            ['finance.payment', '付款管理', 'menu', 'finance', '/finance/payments', 'FinanceCashList', 'el-icon-wallet', 602],
            ['finance.payable', '应付管理', 'menu', 'finance', '/finance/payables', 'FinancePayableList', 'el-icon-tickets', 603],
            ['finance.supplier-ledger', '供应商往来', 'menu', 'finance', '/finance/supplier-ledgers', 'FinanceSupplierLedgerList', 'el-icon-office-building', 604],
            ['finance.invoice', '发票管理', 'menu', 'finance', '/finance/invoices', 'FinanceInvoiceList', 'el-icon-document-copy', 605],
            ['finance.allocation', '往来核销', 'menu', 'finance', '/finance/allocations', 'FinanceAllocationList', 'el-icon-connection', 606],
            ['finance.account', '资金账户', 'menu', 'finance', '/finance/accounts', 'FinanceAccountList', 'el-icon-bank-card', 607],
            ['finance.transfer', '资金转账 / 换汇', 'menu', 'finance', '/finance/transfers', 'FinanceTransferList', 'el-icon-sort', 608],
            ['finance.account_valuation', '资金账户估值', 'menu', 'finance', '/finance/account-valuations', 'FinanceAccountValuation', 'el-icon-pie-chart', 609],

            // 财务管理 按钮
            ['finance.receipt.view', '查看收款单', 'button', 'finance.receipt', null, null, 'el-icon-mouse', 1],
            ['finance.receipt.create', '新增收款单', 'button', 'finance.receipt', null, null, 'el-icon-mouse', 2],
            ['finance.receipt.confirm', '确认收款入账', 'button', 'finance.receipt', null, null, 'el-icon-mouse', 3],
            ['finance.receipt.void', '作废收款单', 'button', 'finance.receipt', null, null, 'el-icon-mouse', 4],

            ['finance.payment.view', '查看付款单', 'button', 'finance.payment', null, null, 'el-icon-mouse', 1],
            ['finance.payment.create', '新增付款单', 'button', 'finance.payment', null, null, 'el-icon-mouse', 2],
            ['finance.payment.confirm', '确认付款出账', 'button', 'finance.payment', null, null, 'el-icon-mouse', 3],
            ['finance.payment.void', '作废付款单', 'button', 'finance.payment', null, null, 'el-icon-mouse', 4],

            ['finance.payable.view', '查看应付账款', 'button', 'finance.payable', null, null, 'el-icon-mouse', 1],
            ['finance.payable.export', '导出应付对账', 'button', 'finance.payable', null, null, 'el-icon-mouse', 2],

            ['finance.supplier-ledger.view', '查看供应商往来', 'button', 'finance.supplier-ledger', null, null, 'el-icon-mouse', 1],
            ['finance.supplier-ledger.export', '导出供应商往来台账', 'button', 'finance.supplier-ledger', null, null, 'el-icon-mouse', 2],

            ['finance.invoice.view', '查看发票详情', 'button', 'finance.invoice', null, null, 'el-icon-mouse', 1],
            ['finance.invoice.create', '登记进项发票', 'button', 'finance.invoice', null, null, 'el-icon-mouse', 2],
            ['finance.invoice.edit_draft', '编辑发票草稿', 'button', 'finance.invoice', null, null, 'el-icon-mouse', 3],
            ['finance.invoice.match', '发票匹配', 'button', 'finance.invoice', null, null, 'el-icon-mouse', 4],
            ['finance.invoice.confirm', '确认发票匹配', 'button', 'finance.invoice', null, null, 'el-icon-mouse', 5],
            ['finance.invoice.reverse_match', '撤销发票匹配', 'button', 'finance.invoice', null, null, 'el-icon-mouse', 6],
            ['finance.invoice.red', '开具红字发票', 'button', 'finance.invoice', null, null, 'el-icon-mouse', 7],

            ['finance.allocation.view', '查看往来核销', 'button', 'finance.allocation', null, null, 'el-icon-mouse', 1],
            ['finance.allocation.create', '创建资金核销', 'button', 'finance.allocation', null, null, 'el-icon-mouse', 2],
            ['finance.allocation.reverse', '撤销核销', 'button', 'finance.allocation', null, null, 'el-icon-mouse', 3],

            ['finance.account.view', '查看资金账户', 'button', 'finance.account', null, null, 'el-icon-mouse', 1],
            ['finance.account.manage', '维护资金账户', 'button', 'finance.account', null, null, 'el-icon-mouse', 2],
            ['finance.account.valuation', '查看账户估值与流水', 'button', 'finance.account_valuation', null, null, 'el-icon-mouse', 1],

            ['finance.exchange-rate.view', '查看估值汇率历史', 'button', 'finance.account_valuation', null, null, 'el-icon-mouse', 2],
            ['finance.exchange-rate.create', '新增/停用估值汇率', 'button', 'finance.account_valuation', null, null, 'el-icon-mouse', 3],

            ['finance.transfer.view', '查看资金转账 / 换汇', 'button', 'finance.transfer', null, null, 'el-icon-mouse', 1],
            ['finance.transfer.create', '新建资金转账 / 换汇', 'button', 'finance.transfer', null, null, 'el-icon-mouse', 2],
            ['finance.transfer.confirm', '确认资金转账 / 换汇', 'button', 'finance.transfer', null, null, 'el-icon-mouse', 3],

            // 7. 审核中心：业务审核动作统一从独立工作台执行
            ['approval.todo', '审核工作台', 'menu', 'approval', '/approvals/tasks', 'ApprovalWorkbench', 'el-icon-circle-check', 701],
            ['approval.flow', '流程配置', 'menu', 'approval', '/approvals/flows', 'ApprovalFlowList', 'el-icon-connection', 704],
            ['approval.task.view', '查看审核任务', 'button', 'approval.todo', null, null, 'el-icon-mouse', 1],
            ['approval.task.decide', '处理审核任务', 'button', 'approval.todo', null, null, 'el-icon-mouse', 2],
            ['approval.task.batch_decide', '批量处理审核任务', 'button', 'approval.todo', null, null, 'el-icon-mouse', 3],
            ['approval.processed', '查看我的已处理', 'button', 'approval.todo', null, null, 'el-icon-mouse', 10],
            ['approval.all', '查看全部审核', 'button', 'approval.todo', null, null, 'el-icon-mouse', 11],
            ['approval.flow.view', '查看审核流程', 'button', 'approval.flow', null, null, 'el-icon-mouse', 1],
            ['approval.flow.edit', '编辑审核流程', 'button', 'approval.flow', null, null, 'el-icon-mouse', 2],
            ['approval.flow.publish', '发布审核流程', 'button', 'approval.flow', null, null, 'el-icon-mouse', 3],
            ['approval.flow.toggle', '启用/停用审核流程', 'button', 'approval.flow', null, null, 'el-icon-mouse', 4],

            // 8. 系统管理 二级菜单
            ['system.admin', '管理员管理', 'menu', 'system', '/system/admins', 'SystemAdminManagement', 'el-icon-user', 901],
            ['system.role', '角色权限', 'menu', 'system', '/system/roles', 'SystemRolePermission', 'el-icon-user-solid', 902],
            ['system.menu', '菜单管理', 'menu', 'system', '/system/menus', 'SystemMenuManagement', 'el-icon-menu', 903],
            ['system.department', '部门管理', 'menu', 'system', '/system/departments', 'SystemDepartmentManagement', 'el-icon-office-building', 904],
            ['system.document_number_rule', '编号规则', 'menu', 'system', '/system/document-number-rules', 'DocumentNumberRuleList', 'el-icon-postcard', 905],

            // 系统管理 按钮
            ['system.admin.view', '查看管理员列表', 'button', 'system.admin', null, null, 'el-icon-mouse', 1],
            ['system.admin.create', '新增管理员账号', 'button', 'system.admin', null, null, 'el-icon-mouse', 2],
            ['system.admin.edit', '编辑/分配角色权限', 'button', 'system.admin', null, null, 'el-icon-mouse', 3],
            ['system.admin.toggle_status', '启用/停用管理员', 'button', 'system.admin', null, null, 'el-icon-mouse', 4],

            ['system.role.view', '查看角色权限', 'button', 'system.role', null, null, 'el-icon-mouse', 1],
            ['system.role.create', '新增角色', 'button', 'system.role', null, null, 'el-icon-mouse', 2],
            ['system.role.save_permissions', '保存角色权限与数据范围', 'button', 'system.role', null, null, 'el-icon-mouse', 3],

            ['system.menu.view', '查看菜单与按钮树', 'button', 'system.menu', null, null, 'el-icon-mouse', 1],
            ['system.menu.save', '新增/编辑菜单权限节点', 'button', 'system.menu', null, null, 'el-icon-mouse', 2],
            ['system.menu.delete', '删除权限节点', 'button', 'system.menu', null, null, 'el-icon-mouse', 3],

            ['system.department.view', '查看部门架构', 'button', 'system.department', null, null, 'el-icon-mouse', 1],
            ['system.department.save', '新增/编辑部门', 'button', 'system.department', null, null, 'el-icon-mouse', 2],
            ['system.department.set_principal', '设置部门负责人', 'button', 'system.department', null, null, 'el-icon-mouse', 3],

            ['document_number_rule.view', '查看编号规则', 'button', 'system.document_number_rule', null, null, 'el-icon-mouse', 1],
            ['document_number_rule.edit', '编辑编号规则', 'button', 'system.document_number_rule', null, null, 'el-icon-mouse', 2],
        ];

        // Normal page reads must never reseed every permission.  The old
        // implementation updateOrInsert-ed the full tree on every RBAC page
        // request and consequently rebuilt the administrator's grants too.
        // Keep bootstrapping as an installation/upgrade safeguard only: once
        // every declared code exists, this method is a single lightweight
        // existence check and preserves changes made in menu management.
        $codes = array_values(array_unique(array_column($permissions, 0)));
        if (!$force && $this->hasSeededPermissions($codes)) {
            return false;
        }

        $existingCodes = $force
            ? []
            : DB::table('erp_rbac_permissions')->whereIn('code', $codes)->pluck('id', 'code')->all();

        // First pass: modules and menus
        foreach ($permissions as [$code, $name, $type, $parentCode, $path, $component, $icon, $sort]) {
            if ($type === 'menu') {
                if (!$force && array_key_exists($code, $existingCodes)) {
                    continue;
                }
                $parentId = $parentCode ? DB::table('erp_rbac_permissions')->where('code', $parentCode)->value('id') : null;
                DB::table('erp_rbac_permissions')->updateOrInsert(
                    ['code' => $code],
                    [
                        'parent_id' => $parentId,
                        'name' => $name,
                        'type' => $type,
                        'path' => $path,
                        'component' => $component,
                        'icon' => $icon,
                        'sort' => $sort,
                        'enabled' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        // Second pass: buttons
        foreach ($permissions as [$code, $name, $type, $parentCode, $path, $component, $icon, $sort]) {
            if ($type === 'button') {
                if (!$force && array_key_exists($code, $existingCodes)) {
                    continue;
                }
                $parentId = $parentCode ? DB::table('erp_rbac_permissions')->where('code', $parentCode)->value('id') : null;
                DB::table('erp_rbac_permissions')->updateOrInsert(
                    ['code' => $code],
                    [
                        'parent_id' => $parentId,
                        'name' => $name,
                        'type' => $type,
                        'path' => $path,
                        'component' => $component,
                        'icon' => $icon ?: 'el-icon-mouse',
                        'sort' => $sort,
                        'enabled' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        return true;
    }

    private function seedRoles(bool $force = false): void
    {
        $roles = [
            ['admin', '系统管理员', 'all'],
            ['department_principal', '部门负责人', 'department'],
            ['sales_manager', '销售负责人', 'all'],
            ['sales_user', '销售人员', 'self'],
        ];

        $roles[] = ['production_manager', '生产管理员', 'all'];
        $roles[] = ['production_operator', '生产操作员', 'department'];
        $rebuildAdminPermissions = $force || ! $this->hasSeededRoles(count($roles));

        foreach ($roles as [$code, $name, $scope]) {
            DB::table('erp_rbac_roles')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'data_scope' => $scope, 'enabled' => true, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $adminRoleId = DB::table('erp_rbac_roles')->where('code', 'admin')->value('id');
        if ($adminRoleId && $rebuildAdminPermissions) {
            $permissionIds = DB::table('erp_rbac_permissions')->pluck('id')->all();
            DB::table('erp_rbac_role_permissions')->where('role_id', $adminRoleId)->delete();
            $records = array_map(fn ($id) => [
                'role_id' => $adminRoleId,
                'permission_id' => $id,
            ], $permissionIds);
            DB::table('erp_rbac_role_permissions')->insert($records);
        }
        $this->ensureProductionRolePermissions();
        $this->ensureSalesInventoryLockPermission();
    }

    private function ensureSalesInventoryLockPermission(): void
    {
        $permissionId = DB::table('erp_rbac_permissions')->where('code', 'sales_order.inventory_lock')->value('id');
        if (! $permissionId) return;
        foreach (['admin', 'sales_manager', 'sales_user'] as $roleCode) {
            $roleId = DB::table('erp_rbac_roles')->where('code', $roleCode)->value('id');
            if (! $roleId) continue;
            DB::table('erp_rbac_role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    private function ensureProductionRolePermissions(): void
    {
        $permissionIds = DB::table('erp_rbac_permissions')
            ->whereIn('code', [
                'production.base', 'production.operation', 'production.routing', 'production.labor_rule',
                'production.demand.view', 'production.work_order.view', 'production.work_order.create',
                'production.work_order.edit', 'production.work_order.submit', 'production.work_order.cancel',
                'production.work_order.gate.view', 'production.work_order.publish', 'production.material.view',
                'production.material_requirement.view', 'production.material_picking.view', 'production.material_picking.create',
                'production.material_picking.assign', 'production.material_picking.pick', 'production.material_picking.cancel',
                'production.material_delivery.view', 'production.material_delivery.create', 'production.material_delivery.dispatch',
                'production.material_delivery.confirm', 'production.material_receipt.view', 'production.material_receipt.confirm',
                'production.task.view', 'production.task.claim', 'production.task.start', 'production.task.pause', 'production.task.resume', 'production.task.complete', 'production.task.collaborate',
                'production.delivery.view', 'production.delivery.dispatch', 'production.delivery.receive',
                'production.kitting.view', 'production.kitting.confirm', 'production.handover.view', 'production.handover.receive', 'production.handover.reject',
                'production.unit.view', 'production.output.create', 'production.output.quality', 'production.output.warehouse', 'production.output.issue', 'production.output.receive',
                'production.material_supplement.request', 'production.material_supplement.approve',
                'production.material_return.create', 'production.material_return.receive', 'production.material_return.quality',
                'production.trace.view', 'production.assignment.recommend', 'production.assignment.auto', 'production.assignment.override',
                'production.operation.view', 'production.operation.create', 'production.operation.edit', 'production.operation.toggle',
                'production.routing.view', 'production.routing.create', 'production.routing.edit', 'production.routing.activate', 'production.routing.default',
                'production.labor_rule.view', 'production.labor_rule.manage',
                'production.labor_stats.view',
            ])
            ->pluck('id', 'code');
        if ($permissionIds->isEmpty()) return;

        $matrix = [
            'admin' => array_keys($permissionIds->all()),
            'production_manager' => array_keys($permissionIds->all()),
            'production_operator' => [
                'production.base', 'production.operation', 'production.routing', 'production.labor_rule',
                'production.demand.view', 'production.work_order.view',
                'production.work_order.gate.view', 'production.material.view',
                'production.material_requirement.view', 'production.material_picking.view', 'production.material_delivery.view',
                'production.material_receipt.view',
                'production.task.view', 'production.task.claim', 'production.task.start', 'production.task.pause', 'production.task.resume', 'production.task.complete', 'production.task.collaborate',
                'production.delivery.view', 'production.delivery.receive', 'production.kitting.view', 'production.kitting.confirm',
                'production.handover.view', 'production.handover.receive', 'production.handover.reject', 'production.unit.view',
                'production.output.create', 'production.output.receive', 'production.material_supplement.request', 'production.material_return.create', 'production.trace.view',
                'production.operation.view', 'production.routing.view', 'production.labor_rule.view',
                'production.labor_stats.view',
            ],
            'department_principal' => [
                'production.base', 'production.operation', 'production.routing', 'production.labor_rule',
                'production.demand.view', 'production.work_order.view',
                'production.work_order.gate.view', 'production.work_order.publish', 'production.material.view',
                'production.material_requirement.view', 'production.material_picking.view', 'production.material_delivery.view',
                'production.material_receipt.view',
                'production.task.view', 'production.delivery.view', 'production.kitting.view', 'production.handover.view',
                'production.unit.view', 'production.output.quality', 'production.output.warehouse', 'production.output.issue',
                'production.material_supplement.approve', 'production.material_return.receive', 'production.material_return.quality', 'production.trace.view',
                'production.operation.view', 'production.routing.view', 'production.labor_rule.view', 'production.labor_rule.manage',
                'production.labor_stats.view',
            ],
        ];
        foreach ($matrix as $roleCode => $codes) {
            $roleId = DB::table('erp_rbac_roles')->where('code', $roleCode)->value('id');
            if (! $roleId) continue;
            $allowedPermissionIds = collect($codes)->map(fn (string $code) => $permissionIds[$code] ?? null)->filter()->values()->all();
            DB::table('erp_rbac_role_permissions')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permissionIds->values()->all())
                ->when($allowedPermissionIds !== [], fn ($query) => $query->whereNotIn('permission_id', $allowedPermissionIds))
                ->delete();
            foreach ($codes as $code) {
                if (! isset($permissionIds[$code])) continue;
                DB::table('erp_rbac_role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionIds[$code],
                ]);
            }
        }
    }

    private function hasSeededRoles(int $expectedCount): bool
    {
        return Schema::hasTable('erp_rbac_roles')
            && DB::table('erp_rbac_roles')->count() >= $expectedCount;
    }

    /**
     * @param array<int, string> $codes
     */
    private function hasSeededPermissions(array $codes): bool
    {
        return Schema::hasTable('erp_rbac_permissions')
            && DB::table('erp_rbac_permissions')
                ->whereIn('code', $codes)
                ->distinct()
                ->count('code') === count($codes);
    }
}
