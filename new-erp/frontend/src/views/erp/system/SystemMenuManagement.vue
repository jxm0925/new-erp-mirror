<template>
  <section class="system-frame menu-page" v-loading="loading">
    <!-- 顶部标题栏 -->
    <div class="system-titlebar">
      <div>
        <div class="system-crumb">系统管理　/　<b>菜单管理</b></div>
        <h1>菜单管理</h1>
        <p>完整呈现 ERP 菜单与按钮的三级 RBAC 权限树（一级模块 → 业务菜单 → 按钮操作）。</p>
      </div>
      <div class="title-actions">
        <el-button type="primary" class="btn-green" icon="el-icon-plus" @click="openAddRoot">新增一级模块</el-button>
        <el-button class="btn-default" icon="el-icon-refresh" @click="load">刷新</el-button>
      </div>
    </div>

    <!-- 快捷工具栏与统计 -->
    <div class="menu-toolbar">
      <div class="toolbar-left">
        <el-input
          v-model="keyword"
          clearable
          size="small"
          prefix-icon="el-icon-search"
          placeholder="搜索菜单名称、权限码或路由地址..."
          style="width: 320px;"
        />
        <el-radio-group v-model="filterType" size="small" class="type-filter-group">
          <el-radio-button label="all">全部 ({{ stats.all }})</el-radio-button>
          <el-radio-button label="menu">菜单 ({{ stats.menu }})</el-radio-button>
          <el-radio-button label="button">按钮 ({{ stats.button }})</el-radio-button>
          <el-radio-button label="disabled">停用 ({{ stats.disabled }})</el-radio-button>
        </el-radio-group>
      </div>

      <div class="toolbar-right">
        <el-button size="small" class="btn-default" @click="toggleExpandAll">
          {{ isAllExpanded ? '折叠全部' : '展开全部' }}
        </el-button>
      </div>
    </div>

    <!-- 权限树工作台 -->
    <div class="menu-workbench">
      <main class="permission-main">
        <div class="hierarchy-tip">
          <i class="el-icon-info" />
          <span>结构与左侧侧边栏 100% 对应。一级模块为侧边栏大类，二级为业务菜单，三级为页面操作按钮权限。</span>
        </div>

        <el-table
          ref="menuTable"
          :data="filteredTree"
          row-key="id"
          border
          :default-expand-all="isAllExpanded"
          highlight-current-row
          :tree-props="{ children: 'children' }"
          size="small"
          class="menu-tree-table"
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
              <el-tag size="mini" :type="row.enabled ? 'success' : 'info'">
                {{ row.enabled ? '启用' : '停用' }}
              </el-tag>
            </template>
          </el-table-column>

          <el-table-column label="操作" width="180" fixed="right" align="center">
            <template slot-scope="{ row }">
              <el-button type="text" size="small" @click.stop="openPermission(row)">编辑</el-button>
              
              <!-- 一级模块：支持快捷添加二级菜单 -->
              <el-button
                v-if="!row.parent_id"
                type="text"
                size="small"
                class="action-add-link"
                @click.stop="openAddChildMenu(row)"
              >
                + 加菜单
              </el-button>

              <!-- 二级业务菜单：支持快捷添加三级操作按钮 -->
              <el-button
                v-if="row.type === 'menu' && row.parent_id"
                type="text"
                size="small"
                class="action-add-btn-link"
                @click.stop="openAddButton(row)"
              >
                + 加按钮
              </el-button>
            </template>
          </el-table-column>
        </el-table>
      </main>

      <!-- 选中权限节点详情展示 -->
      <aside class="permission-detail">
        <template v-if="selected">
          <h2>节点详情</h2>
          <div class="selected-title">
            <i :class="iconFor(selected)" />
            <div>
              <strong>{{ selected.name }}</strong>
              <code class="perm-code-tag">{{ selected.code }}</code>
            </div>
          </div>

          <dl>
            <dt>所属上级</dt><dd>{{ parentName(selected) }}</dd>
            <dt>节点类型</dt><dd>{{ typeText(selected.type) }}</dd>
            <dt>路由地址</dt><dd>{{ selected.path || '-' }}</dd>
            <dt>页面组件</dt><dd>{{ selected.component || '-' }}</dd>
            <dt>图标类名</dt><dd>{{ selected.icon || '-' }}</dd>
            <dt>显示排序</dt><dd>{{ selected.sort || 0 }}</dd>
            <dt>当前状态</dt><dd><el-tag size="mini" :type="selected.enabled ? 'success' : 'info'">{{ selected.enabled ? '启用' : '停用' }}</el-tag></dd>
          </dl>

          <section v-if="selected.type === 'button'">
            <h3>按钮权限说明</h3>
            <p>该按钮为操作级权限，绑定于上级菜单【{{ parentName(selected) }}】下。在前端可通过 <code>v-permission="'{{ selected.code }}'"</code> 或 <code>$can('{{ selected.code }}')</code> 进行精细化按钮可见性与点击拦截。</p>
          </section>
          <section v-else-if="!selected.parent_id">
            <h3>一级模块说明</h3>
            <p>一级模块对应系统左侧主导航分组，包含其下属的所有业务功能菜单。</p>
          </section>
          <section v-else>
            <h3>业务菜单说明</h3>
            <p>业务菜单对应实际页面路由入口，下辖各个业务操作按钮权限。</p>
          </section>
        </template>
        <div v-else class="empty-panel">
          <i class="el-icon-mouse" style="font-size: 32px; color: #b0c2d4; margin-bottom: 8px;" />
          <span>点击左侧表格中的行查看详细信息</span>
        </div>
      </aside>
    </div>

    <!-- 抽屉式编辑 / 新增弹窗 -->
    <el-drawer
      :visible.sync="drawer"
      :title="editForm.id ? '编辑权限节点' : '新增权限节点'"
      size="460px"
      custom-class="system-drawer"
    >
      <el-form class="drawer-form" label-width="96px" size="small">
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

        <el-form-item label="权限名称" required>
          <el-input v-model.trim="editForm.name" placeholder="例如：采购订单新增 / 销售管理" />
        </el-form-item>

        <el-form-item label="权限标识码" required>
          <el-input v-model.trim="editForm.code" placeholder="例如：purchase.order.create" />
        </el-form-item>

        <el-form-item v-if="editForm.type === 'menu'" label="路由地址">
          <el-input v-model.trim="editForm.path" placeholder="例如：/purchase/orders (一级模块留空)" />
        </el-form-item>

        <el-form-item v-if="editForm.type === 'menu'" label="页面组件">
          <el-input v-model.trim="editForm.component" placeholder="例如：PurchaseOrderList" />
        </el-form-item>

        <el-form-item label="图标类名">
          <el-input v-model.trim="editForm.icon" placeholder="例如：el-icon-shopping-cart-2" />
        </el-form-item>

        <el-form-item label="排序权重">
          <el-input-number v-model="editForm.sort" :min="0" :max="9999" style="width: 140px;" />
        </el-form-item>

        <el-form-item label="是否启用">
          <el-switch v-model="editForm.enabled" active-color="#008b4b" />
        </el-form-item>
      </el-form>

      <div class="drawer-actions">
        <el-button class="btn-default" @click="drawer = false">取消</el-button>
        <el-button type="primary" class="btn-green" :loading="saving" @click="save">保存权限</el-button>
      </div>
    </el-drawer>
  </section>
</template>

<script>
import { listPermissions, savePermission } from '@/api/erp/rbac'

export default {
  name: 'SystemMenuManagement',
  data: () => ({
    loading: false,
    saving: false,
    menuTree: [],
    permissions: [],
    selected: null,
    drawer: false,
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
    parentCandidates() {
      return this.permissions.filter(item => item.type === 'menu')
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

      if (this.keyword.trim()) {
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
      this.drawer = true
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
      this.drawer = true
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
      this.drawer = true
    },
    openPermission(row) {
      this.editForm = {
        ...row,
        enabled: !!row.enabled,
        children: undefined
      }
      this.drawer = true
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
        this.drawer = false
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
.system-frame {
  min-height: calc(100vh - 54px);
  padding: 16px 20px 24px;
  background: #f5f7fa;
  color: #1f2937;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  box-sizing: border-box;
}

.system-titlebar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
}
.system-crumb {
  font-size: 12px;
  color: #6b7280;
  margin-bottom: 4px;
}
.system-crumb b {
  color: #111827;
}
.system-titlebar h1 {
  margin: 0 0 4px;
  font-size: 20px;
  font-weight: 700;
  color: #111827;
}
.system-titlebar p {
  margin: 0;
  color: #6b7280;
  font-size: 12px;
}
.title-actions {
  display: flex;
  gap: 10px;
}

/* 按钮规范 */
.btn-default {
  height: 32px !important;
  line-height: 30px !important;
  padding: 0 16px !important;
  background: #ffffff !important;
  border: 1px solid #d9d9d9 !important;
  border-radius: 4px !important;
  color: #1f2937 !important;
  font-size: 13px !important;
}
.btn-green {
  height: 32px !important;
  line-height: 30px !important;
  padding: 0 16px !important;
  background: #008b4b !important;
  border-color: #008b4b !important;
  border-radius: 4px !important;
  color: #ffffff !important;
  font-size: 13px !important;
}
.btn-green:hover {
  background: #007840 !important;
  border-color: #007840 !important;
}

/* 顶部工具栏 */
.menu-toolbar {
  min-height: 50px;
  padding: 10px 14px;
  margin-bottom: 12px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 4px;
}
.toolbar-left {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

/* 工作台布局 */
.menu-workbench {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 14px;
}
.permission-main,
.permission-detail {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 4px;
}
.permission-main {
  padding: 14px;
  min-width: 0;
}
.hierarchy-tip {
  min-height: 36px;
  padding: 8px 12px;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
  background: #e6f4ff;
  border: 1px solid #91caff;
  color: #1677ff;
  font-size: 12px;
  border-radius: 4px;
}

/* 表格树与节点 */
.menu-tree-table ::v-deep th {
  background: #f8fafc;
  color: #1f2937;
  font-weight: 600;
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
  color: #1677ff;
  font-size: 16px;
}
.level-3 .node-icon {
  color: #f59e0b;
}
.node-name {
  font-weight: 500;
  color: #1f2937;
}
.level-1 .node-name {
  font-weight: 700;
  color: #111827;
}

/* 徽章 */
.icon-cell-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 6px;
  background: #f1f5f9;
  color: #475569;
  border-radius: 3px;
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
  background: #111827;
  color: #ffffff;
}
.badge-green {
  background: #e6f7ef;
  color: #008b4b;
}
.badge-blue {
  background: #e6f4ff;
  color: #1677ff;
}
.badge-orange {
  background: #fff7e6;
  color: #d97706;
}

.perm-code-tag {
  display: inline-block;
  padding: 2px 6px;
  background: #f3f4f6;
  color: #374151;
  border-radius: 3px;
  font-family: Consolas, monospace;
  font-size: 12px;
}
.path-text {
  color: #4b5563;
  font-size: 12px;
}
.empty-cell {
  color: #9ca3af;
}

.action-add-link {
  color: #008b4b !important;
  margin-left: 8px;
}
.action-add-btn-link {
  color: #1677ff !important;
  margin-left: 8px;
}

/* 右侧详情面板 */
.permission-detail {
  padding: 16px;
  height: fit-content;
}
.permission-detail h2 {
  font-size: 15px;
  font-weight: 700;
  margin: 0 0 14px;
  color: #111827;
}
.selected-title {
  display: flex;
  align-items: center;
  gap: 10px;
  padding-bottom: 14px;
  border-bottom: 1px solid #e5e7eb;
}
.selected-title i {
  width: 36px;
  height: 36px;
  display: grid;
  place-items: center;
  background: #e6f7ef;
  color: #008b4b;
  border-radius: 50%;
  font-size: 18px;
}
.selected-title strong {
  display: block;
  font-size: 14px;
  color: #111827;
}
.permission-detail dl {
  display: grid;
  grid-template-columns: 80px 1fr;
  gap: 10px;
  margin: 14px 0;
  font-size: 13px;
}
.permission-detail dt {
  color: #6b7280;
}
.permission-detail dd {
  margin: 0;
  color: #1f2937;
  word-break: break-all;
}
.permission-detail section {
  border-top: 1px solid #e5e7eb;
  padding-top: 12px;
  margin-top: 12px;
}
.permission-detail h3 {
  margin: 0 0 8px;
  font-size: 13px;
  font-weight: 600;
  color: #111827;
}
.permission-detail p {
  color: #6b7280;
  font-size: 12px;
  line-height: 1.6;
  margin: 0;
}
.empty-panel {
  height: 280px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: #9ca3af;
  font-size: 13px;
}

/* 抽屉内样式 */
.drawer-form {
  padding: 0 20px;
}
.quick-btn-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.quick-tag {
  cursor: pointer;
  background: #f3f4f6;
  color: #374151;
  border-color: #d1d5db;
}
.quick-tag:hover {
  background: #e6f7ef;
  color: #008b4b;
  border-color: #008b4b;
}
.drawer-actions {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 60px;
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 10px;
  padding: 0 20px;
  border-top: 1px solid #e5e7eb;
  background: #ffffff;
}

@media (max-width: 1200px) {
  .menu-workbench {
    grid-template-columns: 1fr;
  }
}
</style>
