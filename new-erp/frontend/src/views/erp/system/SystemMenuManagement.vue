<template>
  <div class="system-menu-page" v-loading="loading">
    <!-- 页面全局头部卡片：白底圆角独立卡片 (100% 对齐主数据中心 / SkuList.vue 风格) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-menu" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">菜单管理</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag total-tag">
              共 {{ stats.all }} 项权限/菜单
            </el-tag>
            <el-tag
              v-if="activeFilterTag"
              size="small"
              type="info"
              effect="plain"
              closable
              class="head-tag filter-status-tag"
              @close="clearFilterTag"
            >
              {{ activeFilterTag }}
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="load">刷新</el-button>
        <el-button
          size="small"
          type="success"
          icon="el-icon-plus"
          class="btn-theme-create"
          @click="openAddRoot"
        >
          新增一级模块
        </el-button>
      </div>
    </header>

    <!-- 全局统一页面业务提示条 -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>完整呈现 ERP 菜单与按钮的三级 RBAC 权限树（一级模块 → 业务菜单 → 按钮操作），结构与系统导航实时对齐并支持按角色分配权限。</span>
    </div>

    <!-- 顶部概览指标卡片 (100% 对齐主数据中心风格，支持即时点击联动筛选) -->
    <section class="metric-overview-grid">
      <div
        class="metric-card metric-all"
        :class="{ active: filterType === 'all' && !keyword }"
        title="点击查看全部权限节点"
        @click="switchFilter('all')"
      >
        <div class="metric-icon-box"><i class="el-icon-s-grid" /></div>
        <div class="metric-info">
          <span class="metric-label">全部权限节点</span>
          <strong class="metric-val code-mono">{{ stats.all }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-menu"
        :class="{ active: filterType === 'menu' }"
        title="点击筛选业务功能菜单"
        @click="switchFilter('menu')"
      >
        <div class="metric-icon-box"><i class="el-icon-menu" /></div>
        <div class="metric-info">
          <span class="metric-label">业务功能菜单</span>
          <strong class="metric-val code-mono">{{ stats.menu }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-button"
        :class="{ active: filterType === 'button' }"
        title="点击筛选操作按钮权限"
        @click="switchFilter('button')"
      >
        <div class="metric-icon-box"><i class="el-icon-mouse" /></div>
        <div class="metric-info">
          <span class="metric-label">按钮操作权限</span>
          <strong class="metric-val code-mono">{{ stats.button }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-disabled"
        :class="{ active: filterType === 'disabled' }"
        title="点击筛选已停用节点"
        @click="switchFilter('disabled')"
      >
        <div class="metric-icon-box"><i class="el-icon-circle-close" /></div>
        <div class="metric-info">
          <span class="metric-label">已停用节点</span>
          <strong class="metric-val code-mono">{{ stats.disabled }}</strong>
        </div>
      </div>
    </section>

    <!-- 主工作台卡片：包含筛选工具栏、树形表格与选中详情 -->
    <section class="table-container-card">
      <div class="filter-toolbar">
        <div class="toolbar-left">
          <el-input
            v-model="keyword"
            clearable
            size="small"
            prefix-icon="el-icon-search"
            placeholder="搜索菜单名称、权限码或路由地址..."
            class="filter-input-search"
            @clear="load"
          />
          <el-radio-group v-model="filterType" size="small" class="type-filter-group">
            <el-radio-button label="all">全部 ({{ stats.all }})</el-radio-button>
            <el-radio-button label="menu">菜单 ({{ stats.menu }})</el-radio-button>
            <el-radio-button label="button">按钮 ({{ stats.button }})</el-radio-button>
            <el-radio-button label="disabled">停用 ({{ stats.disabled }})</el-radio-button>
          </el-radio-group>
        </div>

        <div class="toolbar-right">
          <el-button size="small" class="btn-default" icon="el-icon-sort" @click="toggleExpandAll">
            {{ isAllExpanded ? '折叠全部' : '展开全部' }}
          </el-button>
        </div>
      </div>

      <!-- 权限树与节点详情双栏工作台 -->
      <div class="menu-workbench">
        <main class="permission-main">
          <el-table
            ref="menuTable"
            :data="filteredTree"
            row-key="id"
            border
            :default-expand-all="isAllExpanded"
            highlight-current-row
            :tree-props="{ children: 'children' }"
            size="small"
            class="menu-tree-table erp-page-table"
            @row-click="selectPermission"
          >
            <el-table-column label="菜单 / 按钮名称" min-width="260">
              <template slot-scope="{ row }">
                <div class="perm-node-cell" :class="`level-${nodeLevel(row)}`">
                  <i :class="iconFor(row)" class="node-icon" />
                  <span class="node-name">{{ row.name }}</span>
                  <span v-if="!row.parent_id" class="badge-tag badge-dark">模块</span>
                  <span v-else-if="row.type === 'menu'" class="badge-tag badge-green">菜单</span>
                  <span v-else-if="row.type === 'button'" class="badge-tag badge-blue">按钮</span>
                  <span v-else class="badge-tag badge-orange">接口</span>
                </div>
              </template>
            </el-table-column>

            <el-table-column label="图标" width="110" align="center">
              <template slot-scope="{ row }">
                <span v-if="row.icon" class="icon-cell-badge">
                  <i :class="row.icon" />
                  <small>{{ row.icon.replace('el-icon-', '') }}</small>
                </span>
                <span v-else class="empty-cell">-</span>
              </template>
            </el-table-column>

            <el-table-column prop="code" label="权限标识码 (code)" min-width="210">
              <template slot-scope="{ row }">
                <code class="perm-code-tag" :title="row.code">{{ row.code || '-' }}</code>
              </template>
            </el-table-column>

            <el-table-column prop="path" label="实际路由" min-width="180">
              <template slot-scope="{ row }">
                <span v-if="row.path" class="path-text">{{ row.path }}</span>
                <span v-else class="empty-cell">-</span>
              </template>
            </el-table-column>

            <el-table-column prop="sort" label="排序" width="70" align="center" />

            <el-table-column label="状态" width="80" align="center">
              <template slot-scope="{ row }">
                <span class="status-pill" :class="row.enabled ? 'enabled' : 'disabled'">
                  {{ row.enabled ? '启用' : '停用' }}
                </span>
              </template>
            </el-table-column>

            <el-table-column label="操作" width="180" fixed="right" align="center">
              <template slot-scope="{ row }">
                <el-button type="text" size="small" class="theme-btn-link" icon="el-icon-edit" @click.stop="openPermission(row)">编辑</el-button>
                
                <!-- 一级模块：支持快捷添加二级菜单 -->
                <el-button
                  v-if="!row.parent_id"
                  type="text"
                  size="small"
                  class="action-add-link"
                  icon="el-icon-plus"
                  @click.stop="openAddChildMenu(row)"
                >
                  加菜单
                </el-button>

                <!-- 二级业务菜单：支持快捷添加三级操作按钮 -->
                <el-button
                  v-if="row.type === 'menu' && row.parent_id"
                  type="text"
                  size="small"
                  class="action-add-btn-link"
                  icon="el-icon-mouse"
                  @click.stop="openAddButton(row)"
                >
                  加按钮
                </el-button>
              </template>
            </el-table-column>
          </el-table>
        </main>

        <!-- 选中权限节点详情展示卡片 -->
        <aside class="permission-detail">
          <template v-if="selected">
            <div class="detail-card-head">
              <div class="selected-icon-box">
                <i :class="iconFor(selected)" />
              </div>
              <div class="selected-title-info">
                <strong>{{ selected.name }}</strong>
                <code class="perm-code-tag">{{ selected.code }}</code>
              </div>
            </div>

            <dl class="detail-dl">
              <dt>所属上级</dt><dd>{{ parentName(selected) }}</dd>
              <dt>节点类型</dt><dd><span class="node-type-badge">{{ typeText(selected.type) }}</span></dd>
              <dt>路由地址</dt><dd class="code-mono">{{ selected.path || '-' }}</dd>
              <dt>页面组件</dt><dd class="code-mono">{{ selected.component || '-' }}</dd>
              <dt>图标类名</dt><dd>{{ selected.icon || '-' }}</dd>
              <dt>显示排序</dt><dd>{{ selected.sort || 0 }}</dd>
              <dt>当前状态</dt>
              <dd>
                <span class="status-pill" :class="selected.enabled ? 'enabled' : 'disabled'">
                  {{ selected.enabled ? '启用' : '停用' }}
                </span>
              </dd>
            </dl>

            <div class="detail-action-wrap">
              <el-button size="small" type="success" icon="el-icon-edit" class="btn-edit-node" @click="openPermission(selected)">
                编辑此节点
              </el-button>
            </div>

            <section v-if="selected.type === 'button'" class="detail-desc-box">
              <h3>按钮权限说明</h3>
              <p>该按钮为操作级权限，绑定于上级菜单【{{ parentName(selected) }}】下。在前端可通过 <code>v-permission="'{{ selected.code }}'"</code> 或 <code>$can('{{ selected.code }}')</code> 进行精细化按钮可见性与点击拦截。</p>
            </section>
            <section v-else-if="!selected.parent_id" class="detail-desc-box">
              <h3>一级模块说明</h3>
              <p>一级模块对应系统左侧主导航分组，包含其下属的所有业务功能菜单。</p>
            </section>
            <section v-else class="detail-desc-box">
              <h3>业务菜单说明</h3>
              <p>业务菜单对应实际页面路由入口，下辖各个业务操作按钮权限。</p>
            </section>
          </template>
          <div v-else class="empty-panel">
            <i class="el-icon-mouse" />
            <span>点击左侧表格中的行查看详细信息与权限绑定</span>
          </div>
        </aside>
      </div>
    </section>

    <!-- 弹窗式编辑 / 新增 (严格遵循 2026-09-24 居中弹窗规范，全面替代旧版 el-drawer 抽屉) -->
    <el-dialog
      :visible.sync="dialogVisible"
      :title="editForm.id ? '编辑权限节点' : '新增权限节点'"
      width="640px"
      top="6vh"
      custom-class="erp-themed-dialog system-management-dialog"
      :close-on-click-modal="false"
      append-to-body
    >
      <div class="dialog-header-banner">
        <div class="dialog-banner-icon">
          <i :class="editForm.id ? 'el-icon-edit-outline' : 'el-icon-circle-plus-outline'" />
        </div>
        <div class="dialog-banner-text">
          <span class="dialog-banner-title">{{ editForm.id ? '编辑权限节点' : '新增权限节点' }}</span>
          <span class="dialog-banner-desc">配置三级 RBAC 权限（一级模块 / 业务菜单 / 操作按钮），绑定系统导航与操作拦截。</span>
        </div>
      </div>

      <el-form class="dialog-form" label-width="96px" size="small">
        <el-form-item label="节点类型" required>
          <el-radio-group v-model="editForm.type" @change="onTypeChange">
            <el-radio label="menu">菜单 / 模块</el-radio>
            <el-radio label="button">按钮权限</el-radio>
            <el-radio label="api">接口权限</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="所属上级">
          <el-select v-model="editForm.parent_id" clearable filterable placeholder="无（作为一级模块）" style="width: 100%;">
            <el-option
              v-for="item in parentCandidates"
              :key="item.id"
              :label="`${item.name} (${item.code})`"
              :value="item.id"
            />
          </el-select>
        </el-form-item>

        <!-- 按钮快捷预设 -->
        <el-form-item v-if="editForm.type === 'button'" label="快捷常用动作">
          <div class="quick-btn-tags">
            <el-tag
              v-for="btn in presetButtons"
              :key="btn.action"
              size="small"
              class="quick-tag"
              @click="applyPresetButton(btn)"
            >
              {{ btn.name }} ({{ btn.action }})
            </el-tag>
          </div>
        </el-form-item>

        <div class="form-grid-row">
          <el-form-item label="权限名称" required>
            <el-input v-model.trim="editForm.name" placeholder="例如：采购订单新增 / 销售管理" />
          </el-form-item>

          <el-form-item label="权限标识码" required>
            <el-input v-model.trim="editForm.code" placeholder="例如：purchase.order.create" />
          </el-form-item>
        </div>

        <div v-if="editForm.type === 'menu'" class="form-grid-row">
          <el-form-item label="路由地址">
            <el-input v-model.trim="editForm.path" placeholder="例如：/purchase/orders (一级模块留空)" />
          </el-form-item>

          <el-form-item label="页面组件">
            <el-input v-model.trim="editForm.component" placeholder="例如：PurchaseOrderList" />
          </el-form-item>
        </div>

        <div class="form-grid-row">
          <el-form-item label="图标类名">
            <el-input v-model.trim="editForm.icon" placeholder="例如：el-icon-shopping-cart-2" />
          </el-form-item>

          <el-form-item label="排序权重">
            <el-input-number v-model="editForm.sort" :min="0" :max="9999" controls-position="right" style="width: 100%;" />
          </el-form-item>
        </div>

        <el-form-item label="是否启用">
          <el-switch v-model="editForm.enabled" active-color="#008b4b" />
        </el-form-item>
      </el-form>

      <span slot="footer" class="dialog-footer">
        <el-button size="small" class="btn-default" @click="dialogVisible = false">取消</el-button>
        <el-button size="small" type="success" class="btn-theme-submit" :loading="saving" @click="save">保存权限</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import { listPermissions, savePermission } from '@/api/erp/rbac'
import '@/styles/system-management.css'

export default {
  name: 'SystemMenuManagement',
  data: () => ({
    loading: false,
    saving: false,
    menuTree: [],
    permissions: [],
    selected: null,
    dialogVisible: false,
    editForm: {},
    keyword: '',
    filterType: 'all',
    isAllExpanded: false,
    presetButtons: [
      { name: '查看详情', action: 'view' },
      { name: '新增', action: 'create' },
      { name: '编辑', action: 'edit' },
      { name: '删除', action: 'delete' },
      { name: '审核确认', action: 'audit' },
      { name: '导出数据', action: 'export' },
      { name: '开具红冲', action: 'red' },
      { name: '发票匹配', action: 'match' }
    ]
  }),
  computed: {
    stats() {
      const all = this.permissions.length
      const menu = this.permissions.filter(x => x.type === 'menu').length
      const button = this.permissions.filter(x => x.type === 'button').length
      const disabled = this.permissions.filter(x => !x.enabled).length
      return { all, menu, button, disabled }
    },
    activeFilterTag() {
      if (this.filterType === 'menu') return '筛选: 业务菜单'
      if (this.filterType === 'button') return '筛选: 按钮权限'
      if (this.filterType === 'disabled') return '筛选: 已停用'
      if (this.keyword && this.keyword.trim()) return `搜索: ${this.keyword.trim()}`
      return ''
    },
    parentCandidates() {
      return this.permissions.filter(item => item.type === 'menu' && (!this.editForm.id || item.id !== this.editForm.id))
    },
    filteredTree() {
      let data = JSON.parse(JSON.stringify(this.menuTree || []))
      if (this.filterType === 'menu') {
        data = this.filterTreeByType(data, ['menu'])
      } else if (this.filterType === 'button') {
        data = this.filterTreeByType(data, ['menu', 'button'])
      } else if (this.filterType === 'disabled') {
        data = this.filterTreeByDisabled(data)
      }

      if (this.keyword && this.keyword.trim()) {
        const kw = this.keyword.trim().toLowerCase()
        data = this.filterTreeByKeyword(data, kw)
      }
      return data
    }
  },
  created() {
    this.load()
  },
  methods: {
    async load() {
      this.loading = true
      try {
        const { data: response } = await listPermissions({ hierarchy: 1 })
        this.menuTree = response.data || []
        this.permissions = this.flatten(this.menuTree)
        if (this.selected) {
          this.selected = this.permissions.find(x => x.id === this.selected.id) || null
        } else {
          this.selected = null
        }
      } catch (e) {
        this.$message.error('加载菜单权限失败')
      } finally {
        this.loading = false
      }
    },
    switchFilter(type) {
      this.filterType = type
    },
    clearFilterTag() {
      this.filterType = 'all'
      this.keyword = ''
    },
    flatten(nodes) {
      return (nodes || []).reduce((rows, node) => rows.concat(node, this.flatten(node.children)), [])
    },
    filterTreeByType(nodes, allowedTypes) {
      return (nodes || []).reduce((res, node) => {
        const children = this.filterTreeByType(node.children || [], allowedTypes)
        if (allowedTypes.includes(node.type) || children.length) {
          res.push({ ...node, children })
        }
        return res
      }, [])
    },
    filterTreeByDisabled(nodes) {
      return (nodes || []).reduce((res, node) => {
        const children = this.filterTreeByDisabled(node.children || [], false)
        if (!node.enabled || children.length) {
          res.push({ ...node, children })
        }
        return res
      }, [])
    },
    filterTreeByKeyword(nodes, kw) {
      return (nodes || []).reduce((res, node) => {
        const match =
          (node.name && node.name.toLowerCase().includes(kw)) ||
          (node.code && node.code.toLowerCase().includes(kw)) ||
          (node.path && node.path.toLowerCase().includes(kw))
        const children = this.filterTreeByKeyword(node.children || [], kw)
        if (match || children.length) {
          res.push({ ...node, children })
        }
        return res
      }, [])
    },
    toggleExpandAll() {
      this.isAllExpanded = !this.isAllExpanded
      this.expandRows(this.menuTree, this.isAllExpanded)
    },
    expandRows(nodes, expand) {
      if (!this.$refs.menuTable) return
      (nodes || []).forEach(row => {
        this.$refs.menuTable.toggleRowExpansion(row, expand)
        if (row.children && row.children.length) {
          this.expandRows(row.children, expand)
        }
      })
    },
    selectPermission(row) {
      this.selected = row
    },
    nodeLevel(row) {
      if (!row.parent_id) return 1
      const parent = this.permissions.find(x => x.id === row.parent_id)
      if (parent && !parent.parent_id) return 2
      return 3
    },
    openAddRoot() {
      this.editForm = {
        name: '',
        code: '',
        type: 'menu',
        parent_id: null,
        path: '',
        component: '',
        icon: 'el-icon-s-grid',
        sort: (this.menuTree.length + 1) * 10,
        enabled: true
      }
      this.dialogVisible = true
    },
    openAddChildMenu(parentRow) {
      this.editForm = {
        name: '',
        code: parentRow.code ? `${parentRow.code}.` : '',
        type: 'menu',
        parent_id: parentRow.id,
        path: '',
        component: '',
        icon: 'el-icon-menu',
        sort: 10,
        enabled: true
      }
      this.dialogVisible = true
    },
    openAddButton(parentRow) {
      this.editForm = {
        name: '',
        code: parentRow.code ? `${parentRow.code}.` : '',
        type: 'button',
        parent_id: parentRow.id,
        path: '',
        component: '',
        icon: 'el-icon-mouse',
        sort: 10,
        enabled: true
      }
      this.dialogVisible = true
    },
    openPermission(row) {
      this.editForm = {
        ...row,
        enabled: !!row.enabled,
        children: undefined
      }
      this.dialogVisible = true
    },
    onTypeChange(val) {
      if (val === 'button' && !this.editForm.icon) {
        this.editForm.icon = 'el-icon-mouse'
      }
    },
    applyPresetButton(btn) {
      const parent = this.permissions.find(x => x.id === this.editForm.parent_id)
      const baseCode = parent && parent.code ? parent.code : 'action'
      this.editForm.name = btn.name
      this.editForm.code = `${baseCode}.${btn.action}`
      this.editForm.type = 'button'
      this.editForm.icon = 'el-icon-mouse'
    },
    async save() {
      if (!this.editForm.name || !this.editForm.code) {
        return this.$message.warning('请填写权限名称和权限标识码')
      }
      this.saving = true
      try {
        await savePermission(this.editForm)
        this.$message.success('权限节点已保存')
        this.dialogVisible = false
        await this.load()
      } catch (e) {
        this.$message.error(e.userMessage || '保存权限失败')
      } finally {
        this.saving = false
      }
    },
    parentName(row) {
      if (!row || !row.parent_id) return '一级根模块'
      const parent = this.permissions.find(item => item.id === row.parent_id)
      return parent ? parent.name : '一级根模块'
    },
    typeText(type) {
      return { menu: '菜单/模块', button: '按钮权限', api: '接口权限' }[type] || type
    },
    iconFor(row) {
      if (row.icon) return row.icon
      if (!row.parent_id) return 'el-icon-s-grid'
      return row.type === 'button' ? 'el-icon-mouse' : row.type === 'api' ? 'el-icon-connection' : 'el-icon-menu'
    }
  }
}
</script>

<style scoped>
.system-menu-page {
  padding: 16px 20px 24px;
  background: #f8fafc;
  min-height: calc(100vh - 54px);
  box-sizing: border-box;
}

/* 顶部标头卡片 (100% 对齐主数据中心 / SkuList.vue 风格) */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #ffffff;
  padding: 16px 20px;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
  margin-bottom: 12px;
}
.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}
.head-icon {
  width: 40px;
  height: 40px;
  background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
  color: #008b4b;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}
.head-title-wrap {
  display: flex;
  flex-direction: column;
}
.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1.2;
}
.head-tag {
  font-size: 12px;
  border-radius: 4px;
  height: 24px;
  line-height: 22px;
  padding: 0 8px;
}
.total-tag {
  background: #f0fdf4 !important;
  border-color: #bbf7d0 !important;
  color: #008b4b !important;
  font-weight: 600;
}
.filter-status-tag {
  background: #f1f5f9 !important;
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}
.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}
.btn-refresh {
  background: #ffffff !important;
  border: 1px solid #dcdfe6 !important;
  color: #606266 !important;
}
.btn-refresh:hover {
  color: #008b4b !important;
  border-color: #bbf7d0 !important;
  background: #f0fdf4 !important;
}
.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}
.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 全局统一提示条 */
.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
  padding: 10px 14px;
  border-radius: 6px;
  font-size: 13px;
  margin-bottom: 14px;
}
.erp-page-tip i {
  font-size: 15px;
  flex-shrink: 0;
}

/* 顶部概览指标卡片 (100% 对齐主数据中心 / SkuList.vue 风格) */
.metric-overview-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 14px;
}
.metric-card {
  background: #ffffff;
  border-radius: 8px;
  padding: 14px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  cursor: pointer;
  transition: all 0.2s ease;
  user-select: none;
}
.metric-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
}
.metric-card.active {
  border-color: #008b4b;
  background: #f0fdf4;
}
.metric-icon-box {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}
.metric-all .metric-icon-box { background: #f1f5f9; color: #475569; }
.metric-menu .metric-icon-box { background: #e8f5e9; color: #008b4b; }
.metric-button .metric-icon-box { background: #e0f2fe; color: #0284c7; }
.metric-disabled .metric-icon-box { background: #fef2f2; color: #ef4444; }

.metric-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.metric-label {
  font-size: 13px;
  color: #64748b;
}
.metric-val {
  font-size: 22px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1.2;
}
.code-mono {
  font-family: Consolas, Monaco, "Courier New", monospace;
}

/* 表格与操作卡片 */
.table-container-card {
  background: #ffffff;
  border-radius: 8px;
  padding: 16px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
  border: 1px solid #e2e8f0;
}
.filter-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  padding-bottom: 16px;
  margin-bottom: 14px;
  border-bottom: 1px solid #f1f5f9;
}
.toolbar-left {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.filter-input-search {
  width: 300px;
}
.type-filter-group ::v-deep .el-radio-button__inner {
  font-size: 12px;
}
.type-filter-group ::v-deep .el-radio-button__orig-radio:checked + .el-radio-button__inner {
  background-color: #008b4b;
  border-color: #008b4b;
  box-shadow: -1px 0 0 0 #008b4b;
}

/* 工作台布局 */
.menu-workbench {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 16px;
}
.permission-main {
  min-width: 0;
}
.menu-tree-table ::v-deep th {
  background: #f8fafc !important;
  color: #334155 !important;
  font-weight: 600;
  font-size: 13px;
}
.perm-node-cell {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.node-icon {
  font-size: 15px;
  color: #008b4b;
}
.level-1 .node-icon {
  color: #008b4b;
  font-size: 16px;
}
.level-3 .node-icon {
  color: #0284c7;
}
.node-name {
  font-weight: 500;
  color: #1e293b;
}
.level-1 .node-name {
  font-weight: 700;
  color: #0f172a;
}

/* 徽章 */
.icon-cell-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 6px;
  background: #f1f5f9;
  color: #475569;
  border-radius: 4px;
  font-size: 11px;
}
.icon-cell-badge i {
  color: #008b4b;
  font-size: 13px;
}
.badge-tag {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 3px;
  font-size: 11px;
  line-height: 16px;
  margin-left: 4px;
}
.badge-dark {
  background: #0f172a;
  color: #ffffff;
}
.badge-green {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}
.badge-blue {
  background: #f0f9ff;
  color: #0284c7;
  border: 1px solid #bae6fd;
}
.badge-orange {
  background: #fffbeb;
  color: #d97706;
  border: 1px solid #fde68a;
}

.perm-code-tag {
  display: inline-block;
  padding: 2px 6px;
  background: #f1f5f9;
  color: #334155;
  border-radius: 4px;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
}
.path-text {
  color: #475569;
  font-size: 12px;
}
.empty-cell {
  color: #94a3b8;
}

/* 状态胶囊 */
.status-pill {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
}
.status-pill.enabled {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}
.status-pill.disabled {
  background: #f1f5f9;
  color: #94a3b8;
  border: 1px solid #e2e8f0;
}

.theme-btn-link {
  color: #008b4b !important;
}
.theme-btn-link:hover {
  color: #00763f !important;
}
.action-add-link {
  color: #008b4b !important;
  margin-left: 8px;
}
.action-add-btn-link {
  color: #0284c7 !important;
  margin-left: 8px;
}

/* 右侧详情面板 */
.permission-detail {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px;
  height: fit-content;
}
.detail-card-head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding-bottom: 14px;
  border-bottom: 1px solid #f1f5f9;
}
.selected-icon-box {
  width: 40px;
  height: 40px;
  display: grid;
  place-items: center;
  background: #f0fdf4;
  color: #008b4b;
  border-radius: 8px;
  font-size: 20px;
  flex-shrink: 0;
}
.selected-title-info {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.selected-title-info strong {
  font-size: 14px;
  color: #0f172a;
}
.detail-dl {
  display: grid;
  grid-template-columns: 75px 1fr;
  gap: 10px;
  margin: 14px 0;
  font-size: 13px;
}
.detail-dl dt {
  color: #64748b;
}
.detail-dl dd {
  margin: 0;
  color: #1e293b;
  word-break: break-all;
}
.node-type-badge {
  display: inline-block;
  padding: 1px 6px;
  background: #f1f5f9;
  border-radius: 3px;
  font-size: 12px;
}
.detail-action-wrap {
  margin-bottom: 14px;
}
.btn-edit-node {
  width: 100%;
  background: #008b4b !important;
  border-color: #008b4b !important;
}
.detail-desc-box {
  border-top: 1px solid #f1f5f9;
  padding-top: 12px;
}
.detail-desc-box h3 {
  margin: 0 0 6px;
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
}
.detail-desc-box p {
  color: #64748b;
  font-size: 12px;
  line-height: 1.6;
  margin: 0;
}
.detail-desc-box code {
  background: #f1f5f9;
  color: #008b4b;
  padding: 1px 4px;
  border-radius: 3px;
}
.empty-panel {
  height: 240px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: #94a3b8;
  font-size: 13px;
  text-align: center;
  padding: 20px;
}
.empty-panel i {
  font-size: 32px;
  margin-bottom: 8px;
  color: #cbd5e1;
}

/* 居中弹窗样式 (完全对齐 ERP 规范) */
.dialog-header-banner {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  padding: 12px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 18px;
}
.dialog-banner-icon {
  width: 36px;
  height: 36px;
  background: #dcfce7;
  color: #008b4b;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}
.dialog-banner-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.dialog-banner-title {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}
.dialog-banner-desc {
  font-size: 12px;
  color: #008b4b;
}
.dialog-form {
  padding: 0 4px;
}
.form-grid-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
.quick-btn-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.quick-tag {
  cursor: pointer;
  background: #f8fafc;
  color: #475569;
  border-color: #e2e8f0;
}
.quick-tag:hover {
  background: #f0fdf4;
  color: #008b4b;
  border-color: #bbf7d0;
}
.btn-theme-submit {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}
.btn-theme-submit:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 响应式断点 (2026-09-08 锁定) */
@media (max-width: 1200px) {
  .metric-overview-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .menu-workbench {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 768px) {
  .system-menu-page {
    padding: 12px;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  .head-actions {
    width: 100%;
    justify-content: flex-end;
  }
  .metric-overview-grid {
    grid-template-columns: 1fr;
  }
  .filter-input-search {
    width: 100%;
  }
  .form-grid-row {
    grid-template-columns: 1fr;
  }
}
</style>
