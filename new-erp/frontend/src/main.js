import Vue from 'vue'
import VueRouter from 'vue-router'
import ElementUI from 'element-ui'
import 'element-ui/lib/theme-chalk/index.css'
import App from './App.vue'
import './styles.css'
import ConsoleDashboard from './views/erp/console/ConsoleDashboard.vue'
import ProductList from './views/erp/master/ProductList.vue'
import ProductForm from './views/erp/master/ProductForm.vue'
import SkuList from './views/erp/master/SkuList.vue'
import SkuForm from './views/erp/master/SkuForm.vue'
import SkuDetail from './views/erp/master/SkuDetail.vue'
import ItemList from './views/erp/master/ItemList.vue'
import ItemMaterialPolicy from './views/erp/master/ItemMaterialPolicy.vue'
import ItemForm from './views/erp/master/ItemForm.vue'
import SkuItemRelationList from './views/erp/master/SkuItemRelationList.vue'
import SkuItemRelationDetail from './views/erp/master/SkuItemRelationDetail.vue'
import SkuItemSetPrimary from './views/erp/master/SkuItemSetPrimary.vue'
import SkuItemIntegrityCheck from './views/erp/master/SkuItemIntegrityCheck.vue'
import UnitList from './views/erp/master/UnitList.vue'
import ItemCategoryList from './views/erp/master/ItemCategoryList.vue'
import SupplierList from './views/erp/master/SupplierList.vue'
import WarehouseList from './views/erp/master/WarehouseList.vue'
import LocationList from './views/erp/master/LocationList.vue'
import ImportWorkbench from './views/erp/master/ImportWorkbench.vue'
import PurchaseRequestList from './views/erp/purchase/PurchaseRequestList.vue'
import PurchasePlanList from './views/erp/purchase/PurchasePlanList.vue'
import PurchaseOrderList from './views/erp/purchase/PurchaseOrderList.vue'
import PurchaseReceiptList from './views/erp/purchase/PurchaseReceiptList.vue'
import PurchaseDefectList from './views/erp/purchase/PurchaseDefectList.vue'
import PurchaseExchangeList from './views/erp/purchase/PurchaseExchangeList.vue'
import PurchaseExchangeDetail from './views/erp/purchase/PurchaseExchangeDetail.vue'
import ReturnList from './views/erp/returns/ReturnList.vue'
import ReturnForm from './views/erp/returns/ReturnForm.vue'
import ReturnDetail from './views/erp/returns/ReturnDetail.vue'
import PurchaseDocumentForm from './views/erp/purchase/PurchaseDocumentForm.vue'
import PurchaseReceiptForm from './views/erp/purchase/PurchaseReceiptForm.vue'
import PurchasePlanDetail from './views/erp/purchase/PurchasePlanDetail.vue'
import PurchaseSimpleDetail from './views/erp/purchase/PurchaseSimpleDetail.vue'
import InventoryBoard from './views/erp/inventory/InventoryBoard.vue'
import InventoryAlertWorkbench from './views/erp/inventory/InventoryAlertWorkbench.vue'
import InventoryAlertConfiguration from './views/erp/inventory/InventoryAlertConfiguration.vue'
import InventoryAlertDetail from './views/erp/inventory/InventoryAlertDetail.vue'
import BomList from './views/erp/bom/BomList.vue'
import BomForm from './views/erp/bom/BomForm.vue'
import BomDetail from './views/erp/bom/BomDetail.vue'
import BomExpand from './views/erp/bom/BomExpand.vue'
import SalesOrderList from './views/erp/sales/SalesOrderList.vue'
import SalesCustomerList from './views/erp/sales/SalesCustomerList.vue'
import SalesOrderForm from './views/erp/sales/SalesOrderForm.vue'
import SalesOrderDetail from './views/erp/sales/SalesOrderDetail.vue'
import SalesProductionConfirmation from './views/erp/sales/SalesProductionConfirmation.vue'
import ProductionDemandList from './views/erp/production/ProductionDemandList.vue'
import ProductionDemandDetail from './views/erp/production/ProductionDemandDetail.vue'
import WorkOrderList from './views/erp/production/WorkOrderList.vue'
import WorkOrderDetail from './views/erp/production/WorkOrderDetail.vue'
import WorkOrderForm from './views/erp/production/WorkOrderForm.vue'
import ProductionOperationList from './views/erp/production/ProductionOperationList.vue'
import ProductionOperationForm from './views/erp/production/ProductionOperationForm.vue'
import ProductionRoutingList from './views/erp/production/ProductionRoutingList.vue'
import ProductionRoutingForm from './views/erp/production/ProductionRoutingForm.vue'
import ProductionExecutionMonitor from './views/erp/production/ProductionExecutionMonitor.vue'
import ApprovalWorkbench from './views/erp/approval/ApprovalWorkbench.vue'
import ApprovalTaskDetail from './views/erp/approval/ApprovalTaskDetail.vue'
import ApprovalFlowList from './views/erp/approval/ApprovalFlowList.vue'
import ApprovalFlowForm from './views/erp/approval/ApprovalFlowForm.vue'
import ApprovalFormList from './views/erp/approval/ApprovalFormList.vue'
import ApprovalFormEditor from './views/erp/approval/ApprovalFormEditor.vue'
import Login from './views/erp/system/Login.vue'
import SystemAdminManagement from './views/erp/system/SystemAdminManagement.vue'
import SystemRolePermission from './views/erp/system/SystemRolePermission.vue'
import SystemMenuManagement from './views/erp/system/SystemMenuManagement.vue'
import SystemDepartmentManagement from './views/erp/system/SystemDepartmentManagement.vue'
import DocumentNumberRuleList from './views/erp/system/DocumentNumberRuleList.vue'
import FinanceAccountList from './views/erp/finance/FinanceAccountList.vue'
import FinanceCashList from './views/erp/finance/FinanceCashList.vue'
import FinanceCashForm from './views/erp/finance/FinanceCashForm.vue'
import FinanceAllocation from './views/erp/finance/FinanceAllocation.vue'
import FinanceAllocationList from './views/erp/finance/FinanceAllocationList.vue'
import FinancePayableList from './views/erp/finance/FinancePayableList.vue'
import FinanceSupplierLedgerList from './views/erp/finance/FinanceSupplierLedgerList.vue'
import FinanceExchangeRateHistory from './views/erp/finance/FinanceExchangeRateHistory.vue'
import FinanceTransferForm from './views/erp/finance/FinanceTransferForm.vue'
import FinanceTransferList from './views/erp/finance/FinanceTransferList.vue'
import FinanceAccountValuation from './views/erp/finance/FinanceAccountValuation.vue'
import FinanceInvoiceList from './views/erp/finance/FinanceInvoiceList.vue'
import FinanceInvoiceForm from './views/erp/finance/FinanceInvoiceForm.vue'
import FinanceInvoiceMatch from './views/erp/finance/FinanceInvoiceMatch.vue'
import FinanceInvoiceDetail from './views/erp/finance/FinanceInvoiceDetail.vue'

Vue.use(ElementUI)
Vue.use(VueRouter)
Vue.config.productionTip = false
Vue.prototype.$can = code => {
  if (!code) return true
  const profile = JSON.parse(localStorage.getItem('erp_me') || '{}')
  if (profile.is_super_admin) return true
  const permissions = JSON.parse(localStorage.getItem('erp_permissions') || '[]')
  return permissions.includes(code)
}

const router = new VueRouter({
  mode: 'hash',
  routes: [
    { path: '/login', component: Login },
    { path: '/', redirect: '/console' },
    { path: '/console', component: ConsoleDashboard },
    { path: '/master/products', component: ProductList },
    { path: '/master/products/new', component: ProductForm },
    { path: '/master/products/:id/edit', component: ProductForm },
    { path: '/master/skus', component: SkuList },
    { path: '/master/skus/new', component: SkuForm },
    { path: '/master/skus/:id/complete', component: SkuForm },
    {
      path: '/master/skus/:id/edit',
      component: SkuForm,
      beforeEnter: (to, from, next) => {
        if (to.query.scope === 'basic' && to.query.from === 'product') {
          next({ path: '/master/products', query: { edit_sku: to.params.id }, replace: true })
          return
        }
        next()
      }
    },
    { path: '/master/skus/:id', component: SkuDetail },
    { path: '/master/items', component: ItemList },
    { path: '/master/items/new', component: ItemForm },
    { path: '/master/items/:id/edit', component: ItemForm },
    { path: '/master/items/:id/material-policy', redirect: to => `/master/items/${to.params.id}/edit` },
    { path: '/master/sku-item-relations', component: SkuItemRelationList },
    { path: '/master/sku-item-relations/integrity-check', component: SkuItemIntegrityCheck },
    { path: '/master/sku-item-relations/:skuId/set-primary', component: SkuItemSetPrimary },
    { path: '/master/sku-item-relations/:skuId', component: SkuItemRelationDetail },
    { path: '/master/base-archives', component: UnitList },
    { path: '/master/units', component: UnitList },
    { path: '/master/categories', component: ItemCategoryList, meta: { permission: 'item_category.view' } },
    { path: '/master/suppliers', component: SupplierList },
    { path: '/master/warehouse-locations', component: WarehouseList },
    { path: '/master/warehouses', component: WarehouseList },
    { path: '/master/locations', component: LocationList },
    { path: '/master/imports', component: ImportWorkbench },
    { path: '/purchase/requests', component: PurchaseRequestList },
    { path: '/purchase/requests/create', component: PurchaseDocumentForm, props: { type: 'request' } },
    { path: '/purchase/requests/:id/edit', component: PurchaseDocumentForm, props: { type: 'request' } },
    { path: '/purchase/requests/:id/detail', component: PurchaseSimpleDetail, props: { type: 'requests' } },
    { path: '/purchase/plans', component: PurchasePlanList },
    { path: '/purchase/plans/create', component: PurchaseDocumentForm, props: { type: 'plan' } },
    { path: '/purchase/plans/:id/edit', component: PurchaseDocumentForm, props: { type: 'plan' } },
    { path: '/purchase/plans/:id/detail', component: PurchasePlanDetail },
    { path: '/purchase/orders', component: PurchaseOrderList },
    { path: '/purchase/orders/create', component: PurchaseDocumentForm, props: { type: 'order' } },
    { path: '/purchase/orders/:id/edit', component: PurchaseDocumentForm, props: { type: 'order' } },
    { path: '/purchase/orders/:id/detail', component: PurchaseSimpleDetail, props: { type: 'orders' } },
    { path: '/purchase/receipts', component: PurchaseReceiptList },
    { path: '/purchase/receipts/create', component: PurchaseReceiptForm },
    { path: '/purchase/receipts/:id/edit', component: PurchaseReceiptForm },
    { path: '/purchase/defects', component: PurchaseDefectList },
    { path: '/purchase/exchanges', component: PurchaseExchangeList },
    { path: '/purchase/exchanges/:id', component: PurchaseExchangeDetail },
    { path: '/purchase/returns', component: ReturnList, props: { kind: 'purchase' }, meta: { permission: 'purchase_return.view' } },
    { path: '/purchase/returns/create', component: ReturnForm, props: { kind: 'purchase' }, meta: { permission: 'purchase_return.create' } },
    { path: '/purchase/returns/:id/detail', component: ReturnDetail, props: { kind: 'purchase' }, meta: { permission: 'purchase_return.view' } },
    { path: '/inventory', redirect: '/inventory/posting' },
    { path: '/inventory/posting', component: InventoryBoard },
    { path: '/inventory/balances', component: InventoryBoard },
    { path: '/inventory/transactions', component: InventoryBoard },
    { path: '/inventory/adjustments', component: InventoryBoard },
    { path: '/inventory/alerts', component: InventoryAlertWorkbench, meta: { permission: 'inventory.alert' } },
    { path: '/inventory/alerts/config', component: InventoryAlertConfiguration, meta: { permission: 'inventory.alert' } },
    { path: '/inventory/alerts/:id', component: InventoryAlertDetail, meta: { permission: 'inventory.alert' } },
    { path: '/bom', redirect: '/bom/boms' },
    { path: '/bom/boms', component: BomList },
    { path: '/bom/create', component: BomForm },
    { path: '/bom/:id/edit', component: BomForm },
    { path: '/bom/:id/detail', component: BomDetail },
    { path: '/bom/expand', component: BomExpand },
    { path: '/sales', redirect: '/sales/orders' },
    { path: '/sales/customers', component: SalesCustomerList },
    { path: '/sales/orders', component: SalesOrderList },
    { path: '/sales/orders/create', component: SalesOrderForm },
    { path: '/sales/orders/:id/edit', component: SalesOrderForm },
    { path: '/sales/orders/:id/detail', component: SalesOrderDetail },
    { path: '/sales/orders/:id/production-confirmation', component: SalesProductionConfirmation },
    { path: '/production/demands', component: ProductionDemandList, meta: { permission: 'production.demand.view' } },
    { path: '/production/demands/:id', component: ProductionDemandDetail, meta: { permission: 'production.demand.view' } },
    { path: '/production/work-orders', component: WorkOrderList, meta: { permission: 'production.work_order.view' } },
    { path: '/production/work-orders/create', component: WorkOrderForm, meta: { permission: 'production.work_order.create' } },
    { path: '/production/work-orders/:id', component: WorkOrderDetail, meta: { permission: 'production.work_order.view' } },
    { path: '/production/operations', component: ProductionOperationList, meta: { permission: 'production.operation.view' } },
    { path: '/production/operations/new', redirect: { path: '/production/operations', query: { create: '1' } }, meta: { permission: 'production.operation.create' } },
    { path: '/production/operations/:id/edit', redirect: to => ({ path: '/production/operations', query: { edit: to.params.id } }), meta: { permission: 'production.operation.edit' } },
    { path: '/production/operations/:id', component: ProductionOperationForm, meta: { permission: 'production.operation.view' } },
    { path: '/production/routings', component: ProductionRoutingList, meta: { permission: 'production.routing.view' } },
    { path: '/production/routings/new', component: ProductionRoutingForm, meta: { permission: 'production.routing.create' } },
    { path: '/production/routings/:id/edit', component: ProductionRoutingForm, meta: { permission: 'production.routing.edit' } },
    { path: '/production/routings/:id', component: ProductionRoutingForm, meta: { permission: 'production.routing.view' } },
    { path: '/production/execution-monitor', component: ProductionExecutionMonitor, meta: { permission: 'production.unit.view' } },
    { path: '/approvals', redirect: '/approvals/tasks' },
    { path: '/approvals/tasks', component: ApprovalWorkbench, meta: { permission: 'approval.task.view' } },
    { path: '/approvals/processed', redirect: { path: '/approvals/tasks', query: { scope: 'processed' } } },
    { path: '/approvals/all', redirect: { path: '/approvals/tasks', query: { scope: 'all' } } },
    { path: '/approvals/tasks/:id', component: ApprovalTaskDetail, meta: { permission: 'approval.task.view' } },
    { path: '/approvals/flows', component: ApprovalFlowList, meta: { permission: 'approval.flow.view' } },
    { path: '/approvals/flows/create', component: ApprovalFlowForm, meta: { permission: 'approval.flow.edit' } },
    { path: '/approvals/flows/:id/edit', component: ApprovalFlowForm, meta: { permission: 'approval.flow.edit' } },
    { path: '/approvals/forms', component: ApprovalFormList, meta: { permission: 'approval.form.view' } },
    { path: '/approvals/forms/create', component: ApprovalFormEditor, meta: { permission: 'approval.form.edit' } },
    { path: '/approvals/forms/:id/edit', component: ApprovalFormEditor, meta: { permission: 'approval.form.edit' } },
    { path: '/finance', redirect: '/finance/receipts' },
    { path: '/finance/receipts', component: FinanceCashList, props: { direction: 'receipt' }, meta: { permission: 'finance.view' } },
    { path: '/finance/receipts/create', component: FinanceCashForm, props: { direction: 'receipt' }, meta: { permission: 'finance.receipt.create' } },
    { path: '/finance/receipts/:id', component: FinanceCashForm, props: { direction: 'receipt' }, meta: { permission: 'finance.view' } },
    { path: '/finance/payments', component: FinanceCashList, props: { direction: 'payment' }, meta: { permission: 'finance.view' } },
    { path: '/finance/payments/create', component: FinanceCashForm, props: { direction: 'payment' }, meta: { permission: 'finance.payment.create' } },
    { path: '/finance/payments/:id', component: FinanceCashForm, props: { direction: 'payment' }, meta: { permission: 'finance.view' } },
    { path: '/finance/payables', component: FinancePayableList, meta: { permission: 'finance.payable.view' } },
    { path: '/finance/supplier-ledgers', component: FinanceSupplierLedgerList, meta: { permission: 'finance.supplier-ledger.view' } },
    { path: '/finance/invoices', component: FinanceInvoiceList, meta: { permission: 'finance.invoice.view' } },
    { path: '/finance/invoices/create', component: FinanceInvoiceForm, meta: { permission: 'finance.invoice.create' } },
    { path: '/finance/invoices/:id/edit', component: FinanceInvoiceForm, meta: { permission: 'finance.invoice.edit_draft' } },
    { path: '/finance/invoices/:id/match', component: FinanceInvoiceMatch, meta: { permission: 'finance.invoice.match' } },
    { path: '/finance/invoices/:id/red', component: () => import('./views/erp/finance/FinanceInvoiceRed.vue'), meta: { permission: 'finance.invoice.create' } },
    { path: '/finance/invoices/:id', component: FinanceInvoiceDetail, meta: { permission: 'finance.invoice.view' } },
    { path: '/finance/allocations', component: FinanceAllocationList, meta: { permission: 'finance.view' } },
    { path: '/finance/allocations/:id', component: FinanceAllocation, meta: { permission: 'finance.view' } },
    { path: '/finance/accounts', component: FinanceAccountList, meta: { permission: 'finance.view' } },
    { path: '/finance/exchange-rates', redirect: { path: '/finance/account-valuations', query: { rate_maintenance: '1' } } },
    { path: '/finance/transfers', component: FinanceTransferList, meta: { permission: 'finance.view' } },
    { path: '/finance/transfers/create', component: FinanceTransferForm, meta: { permission: 'finance.payment.create' } },
    { path: '/finance/transfers/:id', component: FinanceTransferForm, meta: { permission: 'finance.view' } },
    { path: '/finance/account-valuations', component: FinanceAccountValuation, meta: { permission: 'finance.account_valuation' } },
    { path: '/sales/returns', component: ReturnList, props: { kind: 'sales' }, meta: { permission: 'sales_return.view' } },
    { path: '/sales/returns/create', component: ReturnForm, props: { kind: 'sales' }, meta: { permission: 'sales_return.create' } },
    { path: '/sales/returns/:id/detail', component: ReturnDetail, props: { kind: 'sales' }, meta: { permission: 'sales_return.view' } },
    { path: '/system', redirect: '/system/admins' },
    { path: '/system/document-number-rules', component: DocumentNumberRuleList, meta: { permission: 'document_number_rule.view' } },
    { path: '/system/admins', component: SystemAdminManagement, meta: { permission: 'system.admin.view' } },
    { path: '/system/roles', component: SystemRolePermission, meta: { permission: 'system.role.view' } },
    { path: '/system/menus', component: SystemMenuManagement, meta: { permission: 'system.menu.view' } },
    { path: '/system/departments', component: SystemDepartmentManagement, meta: { permission: 'system.department.view' } },
    { path: '*', redirect: '/console' }
  ]
})

router.beforeEach((to, from, next) => {
  if (to.path === '/login') return next()
  if (!localStorage.getItem('erp_token')) return next(`/login?redirect=${encodeURIComponent(to.fullPath)}`)
  const requiredPermission = to.matched.map(record => record.meta.permission).find(Boolean)
  const profile = JSON.parse(localStorage.getItem('erp_me') || '{}')
  const permissions = JSON.parse(localStorage.getItem('erp_permissions') || '[]')
  if (requiredPermission && !profile.is_super_admin && !permissions.includes(requiredPermission)) {
    ElementUI.Message.error('无权访问该页面')
    return next('/console')
  }
  next()
})

Vue.prototype.$can = function(permission) {
  if (!permission) return true
  const profile = JSON.parse(localStorage.getItem('erp_me') || '{}')
  if (profile.is_super_admin) return true
  const permissions = JSON.parse(localStorage.getItem('erp_permissions') || '[]')
  if (Array.isArray(permission)) {
    return permission.some(p => permissions.includes(p))
  }
  return permissions.includes(permission)
}

Vue.directive('permission', {
  inserted(el, binding) {
    const { value } = binding
    if (!value) return
    const profile = JSON.parse(localStorage.getItem('erp_me') || '{}')
    if (profile.is_super_admin) return
    const permissions = JSON.parse(localStorage.getItem('erp_permissions') || '[]')
    const hasPermission = Array.isArray(value)
      ? value.some(p => permissions.includes(p))
      : permissions.includes(value)
    if (!hasPermission) {
      if (el.parentNode) {
        el.parentNode.removeChild(el)
      } else {
        el.style.display = 'none'
      }
    }
  }
})

new Vue({ router, render: h => h(App) }).$mount('#app')
