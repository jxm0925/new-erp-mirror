<template>
  <div id="app" class="erp-layout" :class="{ 'sidebar-collapsed': sidebarCollapsed }">
    <router-view v-if="$route.path === '/login'" />
    <template v-else>
      <aside class="erp-sidebar" @click.capture="markShellNavigation">
        <div class="erp-brand">
          <svg class="brand-logo-icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
            <polygon points="12,1 22,7 12,13 2,7" fill="#12d39a" />
            <polygon points="2,7 12,13 12,23 2,17" fill="#00a978" />
            <polygon points="22,7 12,13 12,23 22,17" fill="#008b67" />
          </svg>
          <span>ERP系统</span>
        </div>

        <router-link class="console-link" :class="{ active: $route.path.startsWith('/console') }" to="/console">
          <i class="el-icon-s-home" />
          <span>运营控制台</span>
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
                <div v-if="item.children" :key="item.name" class="menu-subgroup">
                  <span>{{ item.name }}</span>
                  <router-link
                    v-for="child in visibleItems(item.children)"
                    :key="child.path"
                    :to="child.path"
                    :class="{ 'router-link-active': isItemActive(child) }"
                  >
                    <i :class="child.icon || 'el-icon-menu'" class="sub-menu-icon" />
                    <span class="sub-menu-text">{{ child.name }}</span>
                  </router-link>
                </div>
                <router-link
                  v-else
                  :key="item.path"
                  :to="item.path"
                  :class="{ 'router-link-active': isItemActive(item) }"
                >
                  <i :class="item.icon || 'el-icon-menu'" class="sub-menu-icon" />
                  <span class="sub-menu-text">{{ item.name }}</span>
                </router-link>
              </template>
            </nav>
          </el-collapse-transition>
        </section>

        <button class="collapse" type="button" @click="sidebarCollapsed = !sidebarCollapsed">
          <i :class="sidebarCollapsed ? 'el-icon-s-unfold' : 'el-icon-s-fold'" />
          <span>{{ sidebarCollapsed ? '展开菜单' : '收起菜单' }}</span>
          <i v-if="!sidebarCollapsed" class="el-icon-d-arrow-left collapse-tail" />
        </button>
      </aside>

      <main class="erp-shell">
        <header class="erp-topbar" @click.capture="markShellNavigation">
          <div class="topbar-left">
            <button
              class="sidebar-toggle-btn"
              type="button"
              :title="sidebarCollapsed ? '展开菜单' : '收起菜单'"
              @click="sidebarCollapsed = !sidebarCollapsed"
            >
              <i :class="sidebarCollapsed ? 'el-icon-s-unfold' : 'el-icon-s-fold'" />
            </button>
            <nav class="erp-breadcrumb" aria-label="breadcrumb">
              <template v-for="(bc, bcIdx) in breadcrumbList">
                <span v-if="bcIdx > 0" :key="'sep-' + bcIdx" class="bc-sep">/</span>
                <span
                  :key="'bc-' + bcIdx"
                  class="bc-item"
                  :class="{ 'is-link': !!bc.path && !bc.active, active: !!bc.active }"
                  @click="bc.path && !bc.active && $router.push(bc.path)"
                >
                  <i v-if="bc.icon" :class="bc.icon" />
                  {{ bc.title }}
                </span>
              </template>
            </nav>
          </div>

          <div class="topbar-search">
            <el-input
              size="small"
              prefix-icon="el-icon-search"
              placeholder="搜索采购需求、采购订单、发票、销售订单、产品、SKU、物料..."
              clearable
            />
          </div>

          <div class="top-actions">
            <el-popover placement="bottom-end" width="380" trigger="hover" :open-delay="120" :close-delay="180" popper-class="inventory-alert-popover">
              <section class="inventory-alert-notices"><header><strong>审批通知</strong><el-button type="text" @click="$router.push('/approvals/tasks')">查看待审</el-button></header><button v-for="notice in approvalNotifications" :key="'approval-' + notice.id" class="inventory-alert-notice" @click="openApprovalNotification(notice)"><b class="warning"></b><span><strong>{{ notice.title }}</strong><small>{{ notice.content }}</small></span></button><p v-if="!approvalNotifications.length">当前没有审批通知</p><header><strong>库存预警通知</strong><el-button type="text" @click="$router.push('/inventory/alerts')">查看工作台</el-button></header><button v-for="alert in inventoryAlerts" :key="alert.id" class="inventory-alert-notice" @click="openInventoryAlert(alert)"><b :class="alert.severity"></b><span><strong>{{ alert.item && (alert.item.item_code || alert.item.item_name) }}</strong><small>当前可用库存 {{ alert.available_qty }}，{{ inventoryAlertLabel(alert) }}</small></span></button><p v-if="!inventoryAlerts.length">当前没有库存预警通知</p></section>
              <el-badge slot="reference" :value="totalNotificationCount" :hidden="!totalNotificationCount"><i class="el-icon-bell" /></el-badge>
            </el-popover>
            <span class="avatar">{{ userInitial }}</span>
            <div class="user-meta-block">
              <strong class="user-name">{{ currentUser.nickname || currentUser.username || '用户' }}</strong>
              <small class="user-scope">{{ dataScopeText }}</small>
            </div>
            <el-button type="text" class="btn-logout" @click="logout"><i class="el-icon-switch-button" /> 退出</el-button>
          </div>
        </header>

        <!-- 多标签页导航 TagsView -->
        <nav class="erp-tags-bar" aria-label="页面标签导航" @click.capture="markShellNavigation">
          <div ref="tagsContainer" class="tags-scroll-container">
            <div
              v-for="(tag, index) in visitedViews"
              :key="tag.path"
              class="tag-tab-item"
              :class="{ active: isTagActive(tag) }"
              @click="handleTagClick(tag)"
            >
              <span class="tag-dot" />
              <span class="tag-title" :title="tag.title">{{ tag.title }}</span>
              <i
                v-if="!tag.affix"
                class="el-icon-close tag-close-icon"
                title="关闭标签"
                @click.stop="closeTag(tag, index)"
              />
            </div>
          </div>
          <div class="tags-action-menu">
            <el-dropdown trigger="click" size="small" @command="handleTagAction">
              <button type="button" class="btn-tags-more" title="标签操作选项">
                <i class="el-icon-arrow-down" />
              </button>
              <el-dropdown-menu slot="dropdown">
                <el-dropdown-item command="closeOthers" icon="el-icon-circle-close">关闭其他标签</el-dropdown-item>
                <el-dropdown-item command="closeAll" icon="el-icon-close">关闭全部标签</el-dropdown-item>
                <el-dropdown-item command="refreshCurrent" icon="el-icon-refresh" divided>刷新当前页面</el-dropdown-item>
              </el-dropdown-menu>
            </el-dropdown>
          </div>
        </nav>

        <section class="erp-content-container">
          <router-view v-if="isRouterAlive" :key="$route.path === '/finance/payables' ? $route.path : $route.fullPath" />
        </section>
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
    // 左侧导航完全来自后台菜单管理（登录/刷新会话时下发，已按当前账号权限过滤）。
    menus: JSON.parse(localStorage.getItem('erp_menus') || '[]'),
    openedMenus: [],
    sidebarCollapsed: false,
    currentUser: JSON.parse(localStorage.getItem('erp_user') || '{}'),
    permissions: JSON.parse(localStorage.getItem('erp_permissions') || '[]'),
    // 登录身份（超级管理员、数据范围）放进响应式数据：切换账号后菜单立即按新账号重算，不沿用上一个账号的显示。
    profile: JSON.parse(localStorage.getItem('erp_me') || '{}'),
    inventoryAlertCount: 0,
    inventoryAlerts: [],
    approvalNotificationCount: 0,
    approvalNotifications: [],
    stopRealtime: null,
    stopApprovalRealtime: null,
    realtimeToken: null,
    visitedViews: [],
    shellNavigationAt: 0,
    isRouterAlive: true
  }),
  computed: {
    totalNotificationCount() { return this.inventoryAlertCount + this.approvalNotificationCount },
    isFinanceDesign() {
      return this.$route.path.startsWith('/finance/')
    },
    menuSections() {
      return this.menus.map(node => {
        const items = node.children && node.children.length
          ? node.children.map(child => child.children && child.children.length
            ? { name: child.name, children: this.menuLeaves(child.children) }
            : { name: child.name, path: child.path, icon: child.icon })
          : [{ name: node.name, path: node.path, icon: node.icon }]
        const firstPath = this.menuLeaves([node]).map(item => item.path).find(Boolean) || ''
        return { key: node.code, title: node.name, icon: node.icon || 'el-icon-menu', items, match: firstPath ? `/${firstPath.split('/')[1]}` : `/${node.code}` }
      }).filter(section => this.visibleItems(section.items).length)
    },
    userInitial() {
      return (this.currentUser.nickname || this.currentUser.username || '用').slice(0, 1)
    },
    dataScopeText() {
      return ({ all: '全部数据', department: '部门数据', self: '本人数据' })[this.profile.data_scope] || '权限用户'
    },
    currentModule() {
      const section = this.menuSections.find(item => this.$route.path.startsWith(item.match))
      if (section) return section.title
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
      return this.titleForPath(this.$route.path)
    },
    breadcrumbList() {
      const path = this.$route.path
      if (path === '/console') {
        return [{ title: '运营控制台', path: '/console', icon: 'el-icon-s-home', active: true }]
      }
      const list = [{ title: '首页', path: '/console', icon: 'el-icon-s-home' }]
      const moduleName = this.currentModule
      if (moduleName) {
        list.push({ title: moduleName })
      }
      const title = this.currentTitle
      if (title) {
        const parts = title.split(' / ')
        if (parts.length > 1) {
          const parentRoute = this.findParentRoute(path)
          list.push({ title: parts[0], path: parentRoute !== path ? parentRoute : null })
          list.push({ title: parts[1], active: true })
        } else {
          list.push({ title, active: true })
        }
      }
      return list
    }
  },
  created() {
    this.refreshSession()
    this.refreshInventoryAlerts()
    this.refreshApprovalNotifications()
    this.ensureRealtimeSubscriptions()
    this.loadVisitedViews()
    this.addVisitedView(this.$route)
    window.addEventListener('erp:inventory-alert-read', this.refreshInventoryAlerts)
  },
  beforeDestroy() {
    window.removeEventListener('erp:inventory-alert-read', this.refreshInventoryAlerts)
    if (this.stopRealtime) this.stopRealtime()
    if (this.stopApprovalRealtime) this.stopApprovalRealtime()
  },
  watch: {
    '$route': {
      immediate: true,
      handler(to, from) {
        this.closeFinishedFormTag(to, from)
        // 浏览器标签页标题跟随当前页面，便于多开标签时区分。
        document.title = to && to.path === '/login' ? '登录 - 简谈ERP' : `${this.titleForPath(to && to.path)} - 简谈ERP`
        this.currentUser = JSON.parse(localStorage.getItem('erp_user') || '{}')
        this.permissions = JSON.parse(localStorage.getItem('erp_permissions') || '[]')
        this.menus = JSON.parse(localStorage.getItem('erp_menus') || '[]')
        this.profile = JSON.parse(localStorage.getItem('erp_me') || '{}')
        this.ensureRealtimeSubscriptions()
        if (to && to.path !== '/login') {
          this.addVisitedView(to)
          if (!to.path.startsWith('/console')) {
            const section = this.menuSections.find(item => to.path.startsWith(item.match))
            if (section) {
              this.openedMenus = [section.key]
            } else {
              this.openedMenus = []
            }
          } else {
            this.openedMenus = []
          }
          this.moveToCurrentTag()
        }
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
      try { const [unread, recent] = await Promise.all([listUnreadInventoryAlerts({ per_page: 10 }), listInventoryAlerts({ per_page: 10 })]); this.inventoryAlertCount = unread.data.total || (unread.data.data || []).length; this.inventoryAlerts = recent.data.data || [] } catch (e) {}
    },
    async refreshApprovalNotifications() {
      if (!localStorage.getItem('erp_token')) return
      try {
        const { data } = await listApprovalNotifications({ status: 'UNREAD', per_page: 10 })
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
        this.menus = data.menus || []
        localStorage.setItem('erp_menus', JSON.stringify(this.menus))
        localStorage.setItem('erp_user', JSON.stringify(this.currentUser))
        localStorage.setItem('erp_permissions', JSON.stringify(this.permissions))
        this.profile = {
          data_scope: data.data_scope,
          is_super_admin: !!data.is_super_admin,
          is_department_principal: !!data.is_department_principal
        }
        localStorage.setItem('erp_me', JSON.stringify(this.profile))
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
    isItemActive(item) {
      if (!item || !item.path) return false
      const path = this.$route.path
      if (path === item.path) return true
      if (path.startsWith(item.path + '/')) return true
      return false
    },
    addVisitedView(route) {
      if (!route || !route.path || route.path === '/login') return
      const path = route.path
      const fullPath = route.fullPath || route.path
      const title = this.computePageTitle(route)
      const existing = this.visitedViews.find(v => v.path === path)
      if (existing) {
        existing.fullPath = fullPath
        existing.title = title
        existing.query = route.query
      } else {
        this.visitedViews.push({
          title,
          path,
          fullPath,
          query: route.query,
          affix: path === '/console'
        })
      }
      this.saveVisitedViews()
    },
    saveVisitedViews() {
      try {
        sessionStorage.setItem('erp_visited_views', JSON.stringify(this.visitedViews))
      } catch (e) {}
    },
    loadVisitedViews() {
      try {
        const cached = sessionStorage.getItem('erp_visited_views')
        if (cached) {
          const parsed = JSON.parse(cached)
          if (Array.isArray(parsed) && parsed.length) {
            this.visitedViews = parsed.filter(v => v && v.path && v.title && typeof v.title === 'string' && v.title.trim().length > 0 && v.title !== '页面' && v.title !== '业务页面')
            if (!this.visitedViews.some(v => v.path === '/console')) {
              this.visitedViews.unshift({ title: '运营控制台', path: '/console', fullPath: '/console', affix: true })
            }
            return
          }
        }
      } catch (e) {}
      this.visitedViews = [
        { title: '运营控制台', path: '/console', fullPath: '/console', affix: true }
      ]
    },
    moveToCurrentTag() {
      this.$nextTick(() => {
        const container = this.$refs.tagsContainer
        if (!container) return
        if (this.$route.path === '/console') {
          container.scrollLeft = 0
          return
        }
        const activeEl = container.querySelector('.tag-tab-item.active')
        if (activeEl) {
          const cRect = container.getBoundingClientRect()
          const eRect = activeEl.getBoundingClientRect()
          if (eRect.left < cRect.left) {
            container.scrollLeft -= (cRect.left - eRect.left + 8)
          } else if (eRect.right > cRect.right) {
            container.scrollLeft += (eRect.right - cRect.right + 8)
          }
        }
      })
    },
    isTagActive(tag) {
      return this.$route.path === tag.path
    },
    handleTagClick(tag) {
      if (this.$route.fullPath !== tag.fullPath) {
        this.$router.push(tag.fullPath).catch(() => {})
      }
    },
    // 新增/编辑表单保存后会跳回所属列表（或刚建好的单据），此时表单标签已完成使命，自动关闭，
    // 避免每办一张单据就多留一个“新增/编辑”标签。用户主动切换到无关页面时不关闭，保留未保存内容。
    closeFinishedFormTag(to, from) {
      if (!to || !from || !from.path) return
      // 通过菜单、标签或面包屑主动切换页面时，表单可能还有未保存内容，不自动关闭。
      if (Date.now() - this.shellNavigationAt < 1500) return
      const match = from.path.match(/^(.*?)\/(?:create|new|\d+\/edit)$/)
      if (!match) return
      const listPath = match[1]
      if (to.path !== listPath && !to.path.startsWith(listPath + '/')) return
      const index = this.visitedViews.findIndex(v => v.path === from.path)
      if (index > -1 && !this.visitedViews[index].affix) {
        this.visitedViews.splice(index, 1)
        this.saveVisitedViews()
      }
    },
    markShellNavigation() { this.shellNavigationAt = Date.now() },
    closeTag(tag, index) {
      if (tag.affix) return
      this.visitedViews.splice(index, 1)
      this.saveVisitedViews()
      if (this.isTagActive(tag)) {
        const nextTag = this.visitedViews[index] || this.visitedViews[index - 1] || this.visitedViews[0]
        if (nextTag) {
          this.$router.push(nextTag.fullPath).catch(() => {})
        } else {
          this.$router.push('/console').catch(() => {})
        }
      }
    },
    handleTagAction(command) {
      if (command === 'closeOthers') {
        this.visitedViews = this.visitedViews.filter(v => v.affix || this.isTagActive(v))
        this.saveVisitedViews()
      } else if (command === 'closeAll') {
        this.visitedViews = this.visitedViews.filter(v => v.affix)
        this.saveVisitedViews()
        this.$router.push('/console').catch(() => {})
      } else if (command === 'refreshCurrent') {
        this.refreshCurrentView()
      }
    },
    refreshCurrentView() {
      this.isRouterAlive = false
      this.$nextTick(() => {
        this.isRouterAlive = true
      })
    },
    computePageTitle(route) {
      if (!route || !route.path) return '运营控制台'
      const path = route.path
      if (path === '/console' || path === '/') return '运营控制台'
      const title = this.titleForPath(path)
      return title || '业务管理'
    },
    getMenuNameForPath(path) {
      const item = this.menuLeaves(this.menus).find(node => node.path === path)
      return item ? item.name : null
    },
    menuLeaves(nodes) {
      return (nodes || []).reduce((list, node) => list.concat(node.children && node.children.length ? this.menuLeaves(node.children) : (node.path ? [{ name: node.name, path: node.path, icon: node.icon }] : [])), [])
    },
    // 菜单路径必须对应真实页面：已合并进其他页面的旧入口（路由只剩跳转）和前端没有的页面不显示。
    isRoutableMenu(path) {
      if (!path) return false
      const route = this.$router.resolve(path).route
      return route.matched.length > 0 && route.path === path
    },
    titleForPath(path) {
      if (!path) return '运营控制台'
      if (path === '/' || path.startsWith('/console')) return '运营控制台'

      // Exact menu item name
      const menuName = this.getMenuNameForPath(path)
      if (menuName) return menuName

      // 生产管理
      if (path === '/production/operations') return '工序管理'
      if (path === '/production/routings') return '工艺路线'
      if (path.startsWith('/production/routings/new')) return '工艺路线 / 新增'
      if (path.startsWith('/production/routings/') && path.endsWith('/edit')) return '工艺路线 / 编辑'
      if (path.startsWith('/production/routings/')) return '工艺路线 / 详情'
      if (path === '/production/work-orders/new') return '工单管理 / 新建备货工单'
      if (path.startsWith('/production/work-orders/')) return '工单管理 / 工单详情'
      if (path.startsWith('/production/tech/')) return '定制工艺管理 / 详情'
      if (path === '/production/tech') return '定制工艺管理'

      // 销售管理
      if (path === '/sales/orders/create') return '销售订单 / 新增订单'
      if (path.startsWith('/sales/orders/') && path.endsWith('/change')) return '销售订单 / 订单变更'
      if (path.startsWith('/sales/orders/') && path.endsWith('/edit')) return '销售订单 / 编辑订单'
      if (path.startsWith('/sales/orders/') && path.endsWith('/detail')) return '销售订单 / 订单详情'
      if (path === '/sales/orders') return '销售订单'
      if (path === '/sales/customers') return '客户管理'
      if (path === '/sales/returns/create') return '销售退货 / 新建'
      if (path.startsWith('/sales/returns/') && path.endsWith('/detail')) return '销售退货 / 详情'
      if (path === '/sales/returns') return '销售退货'
      if (path.startsWith('/sales/orders/') && path.endsWith('/production-confirmation')) return '订单生产确认'
      if (path === '/sales/production-confirmation') return '订单生产确认'

      // 采购管理
      if (path === '/purchase/requests') return '采购需求'
      if (path === '/purchase/plans') return '采购计划'
      if (path === '/purchase/orders') return '采购订单'
      if (path === '/purchase/receipts') return '采购到货'
      if (path === '/purchase/returns') return '采购退货'
      if (path === '/purchase/production-shortages') return '生产缺料'
      if (path === '/purchase/returns/create') return '采购退货 / 新建'
      if (path.startsWith('/purchase/returns/') && path.endsWith('/detail')) return '采购退货 / 详情'
      if (path === '/purchase/defects') return '不合格品处理'
      if (path === '/purchase/exchanges') return '采购换货'
      const purchaseTitles = { requests: '采购需求', plans: '采购计划', orders: '采购订单', receipts: '采购到货', defects: '不合格品处理', exchanges: '采购换货单' }
      const purchaseMatch = path.match(/^\/purchase\/(requests|plans|orders|receipts|defects|exchanges)(?:\/([^/]+))?(?:\/(edit|detail))?/)
      if (purchaseMatch) return this.docTitle(purchaseTitles[purchaseMatch[1]], purchaseMatch[2], purchaseMatch[3])

      // 库存管理
      if (path === '/inventory/posting') return '库存过账工作台'
      if (path === '/inventory/balances') return '库存余额'
      if (path === '/inventory/transactions') return '库存流水'
      if (path === '/inventory/adjustments') return '手工调整'
      if (path === '/inventory/alerts') return '库存预警'
      if (path === '/inventory/material-returns') return '生产退料审核'
      if (path === '/inventory/material-deliveries') return '生产配料'
      if (path === '/inventory/stocktakes') return '库存盘点'
      if (path === '/inventory/transfers') return '库存调拨'
      if (path.startsWith('/inventory/alerts/')) return '库存预警 / 详情'

      // BOM管理
      if (path === '/bom/boms') return 'BOM管理'
      if (path === '/bom/create') return 'BOM管理 / 新增'
      if (path === '/bom/expand') return 'BOM展开'
      if (path.startsWith('/bom/') && path.endsWith('/edit')) return 'BOM管理 / 编辑'
      if (path.startsWith('/bom/') && path.endsWith('/detail')) return 'BOM管理 / 详情'

      // 主数据中心
      if (path === '/master/products') return '商品管理'
      if (path === '/master/products/new') return '商品管理 / 新增商品'
      if (path.startsWith('/master/products/') && path.endsWith('/edit')) return '商品管理 / 编辑商品'
      if (path.startsWith('/master/products/')) return '商品管理 / 商品详情'
      if (path === '/master/skus') return 'SKU管理'
      if (path.startsWith('/master/skus/new')) return 'SKU管理 / 新增'
      if (path.startsWith('/master/skus/') && path.endsWith('/edit')) return 'SKU管理 / 编辑'
      if (path.startsWith('/master/skus/')) return 'SKU管理 / 详情'
      if (path === '/master/items') return '物料管理'
      if (path.startsWith('/master/items/new')) return '物料管理 / 新增'
      if (path.startsWith('/master/items/') && path.endsWith('/edit')) return '物料管理 / 编辑'
      if (path.startsWith('/master/items/')) return '物料管理 / 详情'
      if (path === '/master/categories') return '物料分类'
      if (path.startsWith('/master/sku-item-relations')) return 'SKU-物料默认关系'
      if (path === '/master/base-archives' || path === '/master/units') return '基础档案'
      if (path === '/master/suppliers') return '供应商管理'
      if (path === '/master/warehouse-locations' || ['/master/warehouses', '/master/locations'].includes(path)) return '仓库与库位'
      if (path === '/master/imports') return '数据导入'

      // 审核中心
      if (path === '/approvals/tasks') return '审核工作台'
      if (path.startsWith('/approvals/tasks/')) return '审核任务详情'
      if (path === '/approvals/flows') return '流程配置'
      if (path.startsWith('/approvals/flows/create')) return '流程配置 / 新增流程'
      if (path.startsWith('/approvals/flows/')) return '流程配置 / 编辑流程'
      if (path === '/approvals/forms') return '表单管理'
      if (path.startsWith('/approvals/forms/create')) return '表单管理 / 新建表单'
      if (path.startsWith('/approvals/forms/')) return '表单管理 / 编辑表单'

      // 财务管理
      if (path === '/finance/receipts') return '收款管理'
      if (path === '/finance/receipts/create') return '收款管理 / 新增收款单'
      if (/^\/finance\/receipts\/\d+$/.test(path)) return '收款管理 / 收款单详情'
      if (path === '/finance/payments') return '付款管理'
      if (path === '/finance/payments/create') return '付款管理 / 新增付款单'
      if (/^\/finance\/payments\/\d+$/.test(path)) return '付款管理 / 付款单详情'
      if (path === '/finance/payables') return '应付管理'
      if (path === '/finance/supplier-ledgers') return '应付管理'
      if (['/finance/purchase-payment-statistics', '/finance/supplier-statistics', '/finance/sales-order-statistics'].includes(path)) return '财务统计'
      if (path === '/finance/invoices') return '发票管理'
      if (path === '/finance/invoices/create') return '发票管理 / 登记进项发票'
      if (path.startsWith('/finance/invoices/') && path.endsWith('/edit')) return '发票管理 / 登记进项发票'
      if (path.startsWith('/finance/invoices/') && path.endsWith('/match')) return '发票管理 / 发票匹配'
      if (path.startsWith('/finance/invoices/')) return '发票管理 / 发票详情'
      if (path.startsWith('/finance/allocations')) return '收付款核销'
      if (path === '/finance/accounts') return '资金账户'
      if (path === '/finance/exchange-rates') return '汇率历史'
      if (path === '/finance/transfers/create') return '资金转账 / 换汇'
      if (path.startsWith('/finance/transfers/')) return '资金转账 / 换汇详情'
      if (path.startsWith('/finance/transfers')) return '资金转账 / 换汇'
      if (path === '/finance/account-valuations') return '资金账户估值'

      // 系统管理
      if (path === '/system/document-number-rules') return '编号规则'
      if (path === '/system/admins') return '管理员管理'
      if (path === '/system/roles') return '角色权限'
      if (path === '/system/menus') return '菜单管理'
      if (path === '/system/departments') return '部门管理'
      if (path === '/system/business-staff') return '业务员档案'

      return '业务管理'
    },
    findParentRoute(path) {
      if (path.startsWith('/sales/orders')) return '/sales/orders'
      if (path.startsWith('/sales/customers')) return '/sales/customers'
      if (path.startsWith('/sales/returns')) return '/sales/returns'
      if (path.startsWith('/purchase/requests')) return '/purchase/requests'
      if (path.startsWith('/purchase/plans')) return '/purchase/plans'
      if (path.startsWith('/purchase/orders')) return '/purchase/orders'
      if (path.startsWith('/purchase/receipts')) return '/purchase/receipts'
      if (path.startsWith('/purchase/returns')) return '/purchase/returns'
      if (path.startsWith('/inventory/alerts')) return '/inventory/alerts'
      if (path.startsWith('/production/operations')) return '/production/operations'
      if (path.startsWith('/production/routings')) return '/production/routings'
      if (path.startsWith('/production/work-orders')) return '/production/work-orders'
      if (path.startsWith('/bom/boms') || path.startsWith('/bom/create') || path.startsWith('/bom/')) return '/bom/boms'
      if (path.startsWith('/finance/receipts')) return '/finance/receipts'
      if (path.startsWith('/finance/payments')) return '/finance/payments'
      if (path.startsWith('/finance/invoices')) return '/finance/invoices'
      if (path.startsWith('/finance/payables') || path.startsWith('/finance/supplier-ledgers')) return '/finance/payables'
      if (['/finance/statistics', '/finance/purchase-payment-statistics', '/finance/supplier-statistics', '/finance/sales-order-statistics'].includes(path)) return '/finance/statistics'
      if (path.startsWith('/finance/transfers')) return '/finance/transfers'
      if (path.startsWith('/master/products')) return '/master/products'
      if (path.startsWith('/master/skus')) return '/master/skus'
      if (path.startsWith('/master/items')) return '/master/items'
      if (path.startsWith('/master/sku-item-relations')) return '/master/sku-item-relations'
      if (path.startsWith('/approvals/tasks')) return '/approvals/tasks'
      if (path.startsWith('/approvals/flows')) return '/approvals/flows'
      if (path.startsWith('/approvals/forms')) return '/approvals/forms'
      if (path.startsWith('/system/admins')) return '/system/admins'
      if (path.startsWith('/system/roles')) return '/system/roles'
      if (path.startsWith('/system/menus')) return '/system/menus'
      if (path.startsWith('/system/departments')) return '/system/departments'
      return null
    },
    toggleMenu(key) {
      if (this.sidebarCollapsed) this.sidebarCollapsed = false
      if (this.openedMenus.includes(key)) {
        this.openedMenus = []
      } else {
        this.openedMenus = [key]
      }
    },
    openMenuSection(section) {
      if (this.sidebarCollapsed) this.sidebarCollapsed = false
      if (this.openedMenus.includes(section.key)) {
        this.openedMenus = []
      } else {
        this.openedMenus = [section.key]
      }
    },
    isMenuOpen(key) {
      return this.openedMenus.includes(key)
    },
    isSectionActive(section) {
      return this.$route.path.startsWith(section.match)
    },
    visibleItems(items) {
      return items.filter(item => item.children ? this.visibleItems(item.children).length : this.isRoutableMenu(item.path))
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
        localStorage.removeItem('erp_menus')
        // 页面标签属于当前账号，换账号登录后不能残留上一个人打开过的单据页面。
        try { sessionStorage.removeItem('erp_visited_views') } catch (e) {}
        this.visitedViews = [{ title: '运营控制台', path: '/console', fullPath: '/console', affix: true }]
        this.$router.replace('/login')
      }
    }
  }
}
</script>
<style>
.inventory-alert-notices{max-height:360px;overflow:auto}.inventory-alert-notices header{display:flex;align-items:center;justify-content:space-between;padding:4px 4px 8px;border-bottom:1px solid #edf0f4}.inventory-alert-notices header strong{font-size:15px;color:#172033}.inventory-alert-notices header .el-button{padding:0}.inventory-alert-notices>p{margin:18px 4px;color:#8b95a5;text-align:center;font-size:13px}.inventory-alert-notice{display:flex;width:100%;gap:9px;padding:11px 4px;background:#fff;border:0;border-bottom:1px solid #f0f2f5;text-align:left;cursor:pointer}.inventory-alert-notice:hover{background:#f5f9f7}.inventory-alert-notice b{width:8px;height:8px;margin-top:6px;border-radius:50%;background:#f59e0b;flex:0 0 auto}.inventory-alert-notice b.critical{background:#ef4444}.inventory-alert-notice b.info{background:#5d8af8}.inventory-alert-notice span{min-width:0;display:flex;flex-direction:column;gap:3px}.inventory-alert-notice strong{font-size:13px;color:#253043}.inventory-alert-notice small{font-size:12px;color:#718096;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>
