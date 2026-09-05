<template>
  <div id="app" :class="{ 'sidebar-collapsed': sidebarCollapsed, 'production-route': $route.path.startsWith('/production') }">
    <router-view v-if="$route.path === '/login'" />
    <template v-else>
      <aside class="erp-sidebar">
        <div class="erp-brand">
          <svg v-if="$route.path.startsWith('/production')" class="brand-logo-icon production-logo-icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
            <polygon points="12,1 22,7 12,13 2,7" fill="#12d39a" />
            <polygon points="2,7 12,13 12,23 2,17" fill="#00a978" />
            <polygon points="22,7 12,13 12,23 22,17" fill="#008b67" />
          </svg>
          <svg v-else class="brand-logo-icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
            <polygon points="12 2 2 7 12 12 22 7 12 2" stroke="#00c58e" fill="rgba(0,197,142,0.15)" />
            <polyline points="2 17 12 22 22 17" stroke="#00c58e" />
            <polyline points="2 12 12 17 22 12" stroke="#00c58e" />
          </svg>
          <span>ERP系统</span>
        </div>

        <router-link class="console-link" :class="{ active: $route.path.startsWith('/console') }" to="/console">
          <i class="el-icon-s-home" />
          <span>{{ $route.path.startsWith('/production') ? '运营控制台' : '首页' }}</span>
        </router-link>

        <section
          v-for="section in menuSections"
          :key="section.key"
          class="menu-section"
          :data-section-key="section.key"
          :class="{ open: isMenuOpen(section.key), active: isSectionActive(section) }"
        >
          <button class="menu-title" type="button" @click="openMenuSection(section)">
            <i :class="section.icon" />
            <span>{{ section.title }}</span>
            <i class="el-icon-arrow-up menu-arrow" />
          </button>
          <el-collapse-transition>
            <nav v-show="isMenuOpen(section.key)" class="master-menu">
              <template v-for="item in visibleItems(section.items)">
                <div v-if="item.children" :key="item.name" class="menu-subgroup"><span>{{ item.name }}</span><router-link v-for="child in visibleItems(item.children)" :key="child.path" :to="child.path"><i :class="child.icon || 'el-icon-menu'" class="sub-menu-icon" /><span class="sub-menu-text">{{ child.name }}</span></router-link></div>
                <router-link v-else :key="item.path" :to="item.path"><i :class="item.icon || 'el-icon-menu'" class="sub-menu-icon" /><span class="sub-menu-text">{{ item.name }}</span></router-link>
              </template>
            </nav>
          </el-collapse-transition>
        </section>

        <button class="collapse" type="button" @click="sidebarCollapsed = !sidebarCollapsed">
          <i :class="sidebarCollapsed ? 'el-icon-s-unfold' : 'el-icon-s-fold'" />
          <span>{{ sidebarCollapsed ? '展开菜单' : '收起菜单' }}</span>
          <i v-if="$route.path.startsWith('/production')" class="el-icon-d-arrow-left collapse-tail" />
        </button>
      </aside>

      <main class="erp-shell">
        <header class="erp-topbar">
          <div class="breadcrumb"><i class="el-icon-s-unfold" /> {{ currentModule }} <b>/</b> {{ currentTitle }}</div>
          <el-input
            size="small"
            prefix-icon="el-icon-search"
            placeholder="搜索采购需求、采购订单、发票、销售订单、产品、SKU、物料..."
          />
          <div class="top-actions">
            <el-popover placement="bottom-end" width="380" trigger="hover" :open-delay="120" :close-delay="180" popper-class="inventory-alert-popover">
              <section class="inventory-alert-notices"><header><strong>审批通知</strong><el-button type="text" @click="$router.push('/approvals/tasks')">查看待审</el-button></header><button v-for="notice in approvalNotifications" :key="'approval-' + notice.id" class="inventory-alert-notice" @click="openApprovalNotification(notice)"><b class="warning"></b><span><strong>{{ notice.title }}</strong><small>{{ notice.content }}</small></span></button><p v-if="!approvalNotifications.length">当前没有审批通知</p><header><strong>库存预警通知</strong><el-button type="text" @click="$router.push('/inventory/alerts')">查看工作台</el-button></header><button v-for="alert in inventoryAlerts" :key="alert.id" class="inventory-alert-notice" @click="openInventoryAlert(alert)"><b :class="alert.severity"></b><span><strong>{{ alert.item && (alert.item.item_code || alert.item.item_name) }}</strong><small>当前可用库存 {{ alert.available_qty }}，{{ inventoryAlertLabel(alert) }}</small></span></button><p v-if="!inventoryAlerts.length">当前没有库存预警通知</p></section>
              <el-badge slot="reference" :value="totalNotificationCount" :hidden="!totalNotificationCount"><i class="el-icon-bell" /></el-badge>
            </el-popover>
            <i class="el-icon-question" />
            <span class="avatar">{{ userInitial }}</span>
            <strong>{{ currentUser.nickname || currentUser.username || '用户' }}<small>{{ dataScopeText }}</small></strong>
            <el-button type="text" @click="logout">退出</el-button>
          </div>
        </header>
        <router-view />
      </main>
    </template>
  </div>
</template>

<script>
import { logout as apiLogout, me as getCurrentSession } from './api/erp/auth'
import { listInventoryAlerts, listUnreadInventoryAlerts } from './api/erp/inventory'
import { listApprovalNotifications, readApprovalNotification } from './api/erp/approval'
import { connectApprovalTasks, connectInventoryAlerts, disconnectRealtime } from './services/erpRealtime'

export default {
  data: () => ({
    masterMenus: [
      { name: '产品管理', path: '/master/products', icon: 'el-icon-goods', permission: 'master.product' },
      { name: 'SKU管理', path: '/master/skus', icon: 'el-icon-box', permission: 'master.sku' },
      { name: '物料管理', path: '/master/items', icon: 'el-icon-coin', permission: 'master.item' },
      { name: '物料类目', path: '/master/categories', icon: 'el-icon-folder-opened', permission: 'item_category.view' },
      { name: 'SKU-物料默认关系', path: '/master/sku-item-relations', icon: 'el-icon-connection', permission: 'master.sku_item_relation' },
      { name: '基础档案', path: '/master/base-archives', icon: 'el-icon-files', permission: 'master.base_archive' },
      { name: '供应商管理', path: '/master/suppliers', icon: 'el-icon-truck', permission: 'master.supplier' },
      { name: '仓库与库位', path: '/master/warehouse-locations', icon: 'el-icon-office-building', permission: 'master.warehouse_location' },
      { name: '数据导入', path: '/master/imports', icon: 'el-icon-upload2', permission: 'master.import' }
    ],
    purchaseMenus: [
      { name: '采购需求', path: '/purchase/requests', icon: 'el-icon-shopping-cart-2', permission: 'purchase.request' },
      { name: '采购计划', path: '/purchase/plans', icon: 'el-icon-document', permission: 'purchase.plan' },
      { name: '采购订单', path: '/purchase/orders', icon: 'el-icon-s-order', permission: 'purchase.order' },
      { name: '采购到货', path: '/purchase/receipts', icon: 'el-icon-box', permission: 'purchase.receipt' },
      { name: '采购退货', path: '/purchase/returns', icon: 'el-icon-refresh-left', permission: 'purchase.return' }
    ],
    inventoryMenus: [
      { name: '库存过账工作台', path: '/inventory/posting', icon: 'el-icon-finished', permission: 'inventory.posting' },
      { name: '库存余额', path: '/inventory/balances', icon: 'el-icon-coin', permission: 'inventory.balance' },
      { name: '库存流水', path: '/inventory/transactions', icon: 'el-icon-tickets', permission: 'inventory.transaction' },
      { name: '手工调整', path: '/inventory/adjustments', icon: 'el-icon-edit-outline', permission: 'inventory.adjustment' },
      { name: '库存预警', path: '/inventory/alerts', icon: 'el-icon-warning-outline', permission: 'inventory.alert' }
    ],
    bomMenus: [
      { name: 'BOM管理', path: '/bom/boms', icon: 'el-icon-connection', permission: 'bom.manage' },
      { name: 'BOM展开', path: '/bom/expand', icon: 'el-icon-share', permission: 'bom.expand' }
    ],
    salesMenus: [
      { name: '客户管理', path: '/sales/customers', icon: 'el-icon-user', permission: 'sales.customer' },
      { name: '销售订单', path: '/sales/orders', icon: 'el-icon-s-order', permission: 'sales.order' },
      { name: '销售退货', path: '/sales/returns', icon: 'el-icon-refresh-left', permission: 'sales.return' }
    ],
    productionMenus: [
      { name: '生产基础', children: [
        { name: '工序管理', path: '/production/operations', icon: 'el-icon-set-up', permission: 'production.operation' },
        { name: '工艺路线', path: '/production/routings', icon: 'el-icon-guide', permission: 'production.routing' }
      ] },
      { name: '生产执行监管', path: '/production/execution-monitor', icon: 'el-icon-monitor', permission: 'production.unit.view' },
      { name: '生产需求', path: '/production/demands', icon: 'el-icon-document', permission: 'production.demand' },
      { name: '工单管理', path: '/production/work-orders', icon: 'el-icon-s-order', permission: 'production.work_order' }
    ],
    approvalMenus: [
      { name: '审核工作台', path: '/approvals/tasks', icon: 'el-icon-circle-check', permission: 'approval.task.view' },
      { name: '流程配置', path: '/approvals/flows', icon: 'el-icon-connection', permission: 'approval.flow.view' },
      { name: '表单管理', path: '/approvals/forms', icon: 'el-icon-document', permission: 'approval.form.view' }
    ],
    financeMenus: [
      { name: '收款管理', path: '/finance/receipts', icon: 'el-icon-money', permission: 'finance.receipt' },
      { name: '付款管理', path: '/finance/payments', icon: 'el-icon-wallet', permission: 'finance.payment' },
      { name: '应付管理', path: '/finance/payables', icon: 'el-icon-tickets', permission: 'finance.payable' },
      { name: '供应商往来', path: '/finance/supplier-ledgers', icon: 'el-icon-office-building', permission: 'finance.supplier-ledger' },
      { name: '发票管理', path: '/finance/invoices', icon: 'el-icon-document-copy', permission: 'finance.invoice' },
      { name: '往来核销', path: '/finance/allocations', icon: 'el-icon-connection', permission: 'finance.allocation' },
      { name: '资金账户', path: '/finance/accounts', icon: 'el-icon-bank-card', permission: 'finance.account' },
      { name: '资金转账 / 换汇', path: '/finance/transfers', icon: 'el-icon-sort', permission: 'finance.transfer' },
      { name: '资金账户估值', path: '/finance/account-valuations', icon: 'el-icon-pie-chart', permission: 'finance.account_valuation' }
    ],
    systemMenus: [
      { name: '编号规则', path: '/system/document-number-rules', icon: 'el-icon-postcard', permission: 'system.document_number_rule' },
      { name: '管理员管理', path: '/system/admins', icon: 'el-icon-user', permission: 'system.admin' },
      { name: '角色权限', path: '/system/roles', icon: 'el-icon-user-solid', permission: 'system.role' },
      { name: '菜单管理', path: '/system/menus', icon: 'el-icon-menu', permission: 'system.menu' },
      { name: '部门管理', path: '/system/departments', icon: 'el-icon-office-building', permission: 'system.department' }
    ],
    openedMenus: [],
    sidebarCollapsed: false,
    currentUser: JSON.parse(localStorage.getItem('erp_user') || '{}'),
    permissions: JSON.parse(localStorage.getItem('erp_permissions') || '[]'),
    inventoryAlertCount: 0,
    inventoryAlerts: [],
    approvalNotificationCount: 0,
    approvalNotifications: [],
    stopRealtime: null,
    stopApprovalRealtime: null,
    realtimeToken: null
  }),
  computed: {
    totalNotificationCount() { return this.inventoryAlertCount + this.approvalNotificationCount },
    isFinanceDesign() {
      return this.$route.path.startsWith('/finance/')
    },
    menuSections() {
      return [
        { key: 'production', title: '生产管理', icon: 'el-icon-s-operation', items: this.productionMenus, match: '/production' },
        { key: 'master', title: '主数据中心', icon: 'el-icon-s-grid', items: this.masterMenus, match: '/master' },
        { key: 'purchase', title: '采购管理', icon: 'el-icon-shopping-cart-2', items: this.purchaseMenus, match: '/purchase' },
        { key: 'inventory', title: '库存管理', icon: 'el-icon-house', items: this.inventoryMenus, match: '/inventory' },
        { key: 'bom', title: 'BOM管理', icon: 'el-icon-connection', items: this.bomMenus, match: '/bom' },
        { key: 'sales', title: '销售管理', icon: 'el-icon-s-order', items: this.$route.path.startsWith('/production') ? this.salesMenus.filter(item => item.path === '/sales/orders') : this.salesMenus, match: '/sales' },
        { key: 'approval', title: '审核中心', icon: 'el-icon-circle-check', items: this.approvalMenus, match: '/approvals' },
        { key: 'finance', title: '财务管理', icon: 'el-icon-coin', items: this.financeMenus, match: '/finance' },
        { key: 'system', title: '系统管理', icon: 'el-icon-setting', items: this.systemMenus, match: '/system' }
      ].filter(section => this.visibleItems(section.items).length)
    },
    userInitial() {
      return (this.currentUser.nickname || this.currentUser.username || '用').slice(0, 1)
    },
    dataScopeText() {
      const meta = JSON.parse(localStorage.getItem('erp_me') || '{}')
      return ({ all: '全部数据', department: '部门数据', self: '本人数据' })[meta.data_scope] || '权限用户'
    },
    currentModule() {
      if (this.$route.path.startsWith('/production')) return '生产管理'
      if (this.$route.path.startsWith('/console')) return 'ERP'
      if (this.$route.path.startsWith('/purchase')) return '采购管理'
      if (this.$route.path.startsWith('/inventory')) return '库存管理'
      if (this.$route.path.startsWith('/bom')) return 'BOM管理'
      if (this.$route.path.startsWith('/sales')) return '销售管理'
      if (this.$route.path.startsWith('/approvals')) return '审核中心'
      if (this.$route.path.startsWith('/finance')) return '财务管理'
      if (this.$route.path.startsWith('/system')) return '系统管理'
      return '主数据中心'
    },
    currentTitle() {
      const path = this.$route.path
      if (path === '/production/demands') return '生产需求'
      if (path.startsWith('/production/demands/')) return '生产需求 / 详情'
      if (path === '/production/work-orders') return '工单管理'
      if (path.startsWith('/production/work-orders/')) return '工单管理 / 详情'
      if (path === '/purchase/returns') return '采购退货'
      if (path === '/purchase/returns/create') return '采购退货 / 新建'
      if (path.startsWith('/purchase/returns/') && path.endsWith('/detail')) return '采购退货 / 详情'
      if (path === '/sales/returns') return '销售退货'
      if (path === '/finance/receipts') return '收款管理'
      if (path === '/finance/receipts/create') return '收款管理 / 新增收款单'
      if (/^\/finance\/receipts\/\d+$/.test(path)) return '收款管理 / 收款单详情'
      if (path === '/finance/payments') return '付款管理'
      if (path === '/finance/payments/create') return '付款管理 / 新增付款单'
      if (/^\/finance\/payments\/\d+$/.test(path)) return '付款管理 / 付款单详情'
      if (path === '/finance/payables') return '应付管理'
      if (path === '/finance/supplier-ledgers') return '供应商往来'
      if (path === '/finance/invoices') return '发票管理'
      if (path === '/finance/invoices/create') return '发票管理 / 登记进项发票'
      if (path.startsWith('/finance/invoices/') && path.endsWith('/edit')) return '发票管理 / 登记进项发票'
      if (path.startsWith('/finance/invoices/') && path.endsWith('/match')) return '发票管理 / 发票匹配'
      if (path.startsWith('/finance/invoices/')) return '发票管理 / 发票详情'
      if (path === '/finance/allocations') return '往来核销'
      if (path.startsWith('/finance/allocations/')) return '往来核销'
      if (path === '/finance/accounts') return '资金账户'
      if (path === '/finance/exchange-rates') return '汇率历史'
      if (path === '/finance/transfers') return '资金转账 / 换汇记录'
      if (path === '/finance/transfers/create') return '资金转账 / 换汇'
      if (path.startsWith('/finance/transfers/')) return '资金转账 / 换汇详情'
      if (path === '/finance/account-valuations') return '资金账户余额与估值'
      if (path === '/sales/returns/create') return '销售退货 / 新建'
      if (path.startsWith('/sales/returns/') && path.endsWith('/detail')) return '销售退货 / 详情'
      if (path.startsWith('/master/sku-item-relations')) return 'SKU-物料默认关系'
      if (path === '/master/categories') return '物料类目'
      if (path.startsWith('/console')) return '运营控制台'
      if (path === '/system/admins') return '管理员管理'
      if (path === '/system/document-number-rules') return '编号规则'
      if (path === '/system/roles') return '角色权限'
      if (path === '/system/menus') return '菜单管理'
      if (path === '/system/departments') return '部门管理'
      if (path.startsWith('/sales/orders/') && path.endsWith('/production-confirmation')) return '销售订单 / 订单生产确认'
      if (path === '/approvals/tasks') return '审核工作台'
      if (path.startsWith('/approvals/tasks/')) return '审核任务详情'
      if (path === '/approvals/flows') return '流程配置'
      if (path === '/approvals/flows/create') return '流程配置 / 新增审核流程'
      if (path.startsWith('/approvals/flows/')) return '流程配置 / 编辑审核流程'
      if (path === '/approvals/forms') return '表单管理'
      if (path === '/approvals/forms/create') return '表单管理 / 新建自定义表单'
      if (path.startsWith('/approvals/forms/')) return '表单管理 / 编辑自定义表单'
      const inventoryTitles = { posting: '库存过账工作台', balances: '库存余额', transactions: '库存流水', adjustments: '手工调整' }
      const inventoryMatch = path.match(/^\/inventory\/(posting|balances|transactions|adjustments)/)
      if (inventoryMatch) return inventoryTitles[inventoryMatch[1]]
      const purchaseTitles = { requests: '采购需求', plans: '采购计划', orders: '采购订单', receipts: '采购到货', defects: '不合格品处理', exchanges: '采购换货单' }
      const purchaseMatch = path.match(/^\/purchase\/(requests|plans|orders|receipts|defects|exchanges)(?:\/([^/]+))?(?:\/(edit|detail))?/)
      if (purchaseMatch) return this.docTitle(purchaseTitles[purchaseMatch[1]], purchaseMatch[2], purchaseMatch[3])
      if (path === '/bom/boms') return 'BOM管理'
      if (path === '/bom/create') return 'BOM管理 / 新增'
      if (path === '/bom/expand') return 'BOM展开'
      if (path.startsWith('/bom/') && path.endsWith('/edit')) return 'BOM管理 / 编辑'
      if (path.startsWith('/bom/') && path.endsWith('/detail')) return 'BOM管理 / 详情'
      if (path === '/sales/orders') return '销售订单'
      if (path === '/sales/customers') return '客户管理'
      if (path === '/sales/orders/create') return '销售订单 / 新增订单'
      if (path.startsWith('/sales/orders/') && path.endsWith('/change')) return '销售订单 / 订单变更'
      if (path.startsWith('/sales/orders/') && path.endsWith('/edit')) return '销售订单 / 编辑订单'
      if (path.startsWith('/sales/orders/') && path.endsWith('/detail')) return '销售订单 / 订单详情'
      if (path === '/sales/production-confirmation') return '订单生产确认'
      if (path === '/master/skus') return 'SKU管理'
      if (path === '/master/skus/new') return 'SKU管理 / 新增'
      if (path.startsWith('/master/skus/') && path.endsWith('/edit')) return 'SKU管理 / 编辑'
      if (path.startsWith('/master/skus/')) return 'SKU管理 / 详情'
      const current = this.masterMenus.find(item => item.path === path)
      if (current) return current.name
      if (path === '/master/units') return '基础档案'
      if (['/master/warehouses', '/master/locations'].includes(path)) return '仓库与库位'
      return '商品管理'
    }
  },
  created() {
    this.refreshSession()
    this.refreshInventoryAlerts()
    this.refreshApprovalNotifications()
    this.ensureRealtimeSubscriptions()
    window.addEventListener('erp:inventory-alert-read', this.refreshInventoryAlerts)
  },
  beforeDestroy() {
    window.removeEventListener('erp:inventory-alert-read', this.refreshInventoryAlerts)
    if (this.stopRealtime) this.stopRealtime()
    if (this.stopApprovalRealtime) this.stopApprovalRealtime()
  },
  watch: {
    '$route.path': {
      immediate: true,
      handler(path) {
        this.currentUser = JSON.parse(localStorage.getItem('erp_user') || '{}')
        this.permissions = JSON.parse(localStorage.getItem('erp_permissions') || '[]')
        this.ensureRealtimeSubscriptions()
        if (path.startsWith('/console')) {
          this.openedMenus = []
          this.sidebarCollapsed = false
          return
        }
        const section = this.menuSections.find(item => path.startsWith(item.match))
        this.openedMenus = section ? [section.key] : []
      }
    }
  },
  methods: {
    ensureRealtimeSubscriptions() {
      const token = localStorage.getItem('erp_token')
      const userId = this.currentUser && (this.currentUser.id || this.currentUser.legacy_id)
      const subscriptionIdentity = `${token || ''}:${userId || ''}`
      if (!token || !userId || this.realtimeToken === subscriptionIdentity) return
      if (this.stopRealtime) this.stopRealtime()
      if (this.stopApprovalRealtime) this.stopApprovalRealtime()
      this.stopRealtime = connectInventoryAlerts(this.onInventoryAlert)
      this.stopApprovalRealtime = connectApprovalTasks(userId, this.onApprovalTaskChanged)
      this.realtimeToken = subscriptionIdentity
    },
    onApprovalTaskChanged(payload) {
      this.refreshApprovalNotifications()
      const task = payload && (payload.task || payload.data || payload)
      const id = task && (task.id || task.task_id)
      if (!id) return
      this.$notify({ title: '审核任务更新', message: `${task.task_no || ''} ${task.subject || '审核状态已更新'}`, type: task.task_status === 'REJECTED' ? 'warning' : 'success', duration: 6000, onClick: () => this.$router.push(`/approvals/tasks/${id}`) })
    },
    async refreshInventoryAlerts() {
      if (!localStorage.getItem('erp_token')) return
      try { const [unread, recent] = await Promise.all([listUnreadInventoryAlerts({ per_page: 100 }), listInventoryAlerts({ per_page: 100 })]); this.inventoryAlertCount = unread.data.total || (unread.data.data || []).length; this.inventoryAlerts = recent.data.data || [] } catch (e) {}
    },
    async refreshApprovalNotifications() {
      if (!localStorage.getItem('erp_token')) return
      try {
        const { data } = await listApprovalNotifications({ status: 'UNREAD', per_page: 20 })
        this.approvalNotifications = data.data || []
        this.approvalNotificationCount = Number(data.unread_count || 0)
      } catch (e) {}
    },
    async openApprovalNotification(notice) {
      try { await readApprovalNotification(notice.id) } catch (e) {}
      await this.refreshApprovalNotifications()
      const taskId = notice.approval_task_id || (notice.task && notice.task.id)
      if (taskId) this.$router.push(`/approvals/tasks/${taskId}`)
    },
    onInventoryAlert(payload) {
      this.refreshInventoryAlerts()
      const text = payload.alert_status === 'normal'
        ? `物料 ${payload.item_code} 库存已恢复正常`
        : `物料 ${payload.item_code} 当前可用库存 ${payload.available_qty}，状态：${({ low_stock: '低库存', out_of_stock: '缺货', over_stock: '超储' })[payload.alert_status] || payload.alert_status}`
      this.$notify({ title: '库存预警', message: text, type: payload.severity === 'critical' ? 'error' : 'warning', duration: 6000, onClick: () => this.$router.push(`/inventory/alerts/${payload.alert_id || payload.id}`) })
    },
    openInventoryAlert(alert) { this.$router.push(`/inventory/alerts/${alert.id}`) },
    inventoryAlertLabel(alert) { if (alert.alert_status === 'out_of_stock') return '缺货（严重）'; if (alert.alert_status === 'low_stock') return alert.severity === 'critical' ? '低库存（严重）' : '低库存（一般）'; if (alert.alert_status === 'over_stock') return '超储'; return '正常' },
    async refreshSession() {
      if (!localStorage.getItem('erp_token')) return
      try {
        const { data } = await getCurrentSession()
        this.currentUser = data.user || this.currentUser
        this.permissions = data.permissions || []
        localStorage.setItem('erp_user', JSON.stringify(this.currentUser))
        localStorage.setItem('erp_permissions', JSON.stringify(this.permissions))
        localStorage.setItem('erp_me', JSON.stringify({
          data_scope: data.data_scope,
          is_super_admin: !!data.is_super_admin,
          is_department_principal: !!data.is_department_principal
        }))
        this.ensureRealtimeSubscriptions()
      } catch (e) {
        // Route guard keeps the current page responsive if the session has expired.
      }
    },
    docTitle(base, id, suffix) {
      if (!id) return base
      if (id === 'create') return `${base} / 新增`
      if (suffix === 'edit') return `${base} / 编辑`
      if (suffix === 'detail') return `${base} / 详情`
      return base
    },
    toggleMenu(key) {
      if (this.sidebarCollapsed) this.sidebarCollapsed = false
      this.openedMenus = this.openedMenus.includes(key)
        ? this.openedMenus.filter(item => item !== key)
        : [key]
    },
    openMenuSection(section) {
      if (this.sidebarCollapsed) this.sidebarCollapsed = false
      this.toggleMenu(section.key)
    },
    isMenuOpen(key) {
      return this.openedMenus.includes(key)
    },
    isSectionActive(section) {
      return this.$route.path.startsWith(section.match)
    },
    canMenu(permission) {
      if (!permission) return true
      if (JSON.parse(localStorage.getItem('erp_me') || '{}').is_super_admin) return true
      return this.permissions.includes(permission)
    },
    visibleItems(items) {
      return items.filter(item => item.children ? this.visibleItems(item.children).length : this.canMenu(item.permission))
    },
    async logout() {
      try {
        if (localStorage.getItem('erp_token')) await apiLogout()
      } catch (e) {
        // Clear the local session even if the server is unavailable.
      } finally {
        if (this.stopRealtime) this.stopRealtime()
        if (this.stopApprovalRealtime) this.stopApprovalRealtime()
        disconnectRealtime()
        this.realtimeToken = null
        localStorage.removeItem('erp_token')
        localStorage.removeItem('erp_user')
        localStorage.removeItem('erp_me')
        localStorage.removeItem('erp_permissions')
        this.$router.replace('/login')
      }
    }
  }
}
</script>
<style>
.inventory-alert-notices{max-height:360px;overflow:auto}.inventory-alert-notices header{display:flex;align-items:center;justify-content:space-between;padding:4px 4px 8px;border-bottom:1px solid #edf0f4}.inventory-alert-notices header strong{font-size:15px;color:#172033}.inventory-alert-notices header .el-button{padding:0}.inventory-alert-notices>p{margin:18px 4px;color:#8b95a5;text-align:center;font-size:13px}.inventory-alert-notice{display:flex;width:100%;gap:9px;padding:11px 4px;background:#fff;border:0;border-bottom:1px solid #f0f2f5;text-align:left;cursor:pointer}.inventory-alert-notice:hover{background:#f5f9f7}.inventory-alert-notice b{width:8px;height:8px;margin-top:6px;border-radius:50%;background:#f59e0b;flex:0 0 auto}.inventory-alert-notice b.critical{background:#ef4444}.inventory-alert-notice b.info{background:#5d8af8}.inventory-alert-notice span{min-width:0;display:flex;flex-direction:column;gap:3px}.inventory-alert-notice strong{font-size:13px;color:#253043}.inventory-alert-notice small{font-size:12px;color:#718096;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>
