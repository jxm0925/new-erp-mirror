<template>
  <section class="system-frame role-page-container">
    <!-- 紧凑页面头部：标题、统计胶囊与刷新/新增操作 -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-s-custom" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">角色权限</h1>
            <div class="header-stat-pills">
              <span class="stat-pill pill-total" title="全部角色总数">共 {{ roleTotal }} 个角色</span>
              <span class="stat-pill pill-enabled" title="正常启用角色数" @click="filterStatus('enabled')">启用 {{ enabledRolesCount }}</span>
              <span class="stat-pill pill-disabled" title="停用角色数" @click="filterStatus('disabled')">停用 {{ disabledRolesCount }}</span>
              <span class="stat-pill pill-perms" title="系统功能权限总数">{{ permissions.length }} 项权限节点</span>
            </div>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="load">刷新</el-button>
        <el-button
          v-if="$can('system.role.create')"
          size="small"
          type="success"
          icon="el-icon-plus"
          class="btn-theme-create"
          @click="openRoleForm()"
        >
          新增角色
        </el-button>
      </div>
    </header>

    <!-- 角色工作台主体 -->
    <div class="role-workbench">
      <!-- 左侧：角色列表卡片 -->
      <aside class="role-list-card">
        <div class="list-card-header">
          <div class="header-title">
            <h2>角色列表</h2>
            <span class="count-badge">{{ pagedRoles.length }} / {{ roleTotal }}</span>
          </div>
          <div class="status-tab-group">
            <span :class="{ active: roleStatusFilter === 'all' }" @click="filterStatus('all')">全部</span>
            <span :class="{ active: roleStatusFilter === 'enabled' }" @click="filterStatus('enabled')">启用</span>
            <span :class="{ active: roleStatusFilter === 'disabled' }" @click="filterStatus('disabled')">停用</span>
          </div>
        </div>

        <div class="role-search-wrap">
          <el-input
            v-model="roleKeyword"
            clearable
            size="small"
            prefix-icon="el-icon-search"
            placeholder="搜索角色名称或编码..."
          />
        </div>

        <div class="role-table-wrap">
          <el-table
            v-loading="loading"
            :data="pagedRoles"
            height="520"
            highlight-current-row
            :row-class-name="roleRowClassName"
            @row-click="selectRole"
          >
            <el-table-column label="角色信息" min-width="155">
              <template slot-scope="{ row }">
                <div class="role-item-main">
                  <div class="role-name-row">
                    <strong class="role-name-text">{{ row.name }}</strong>
                    <span v-if="row.is_system || row.code === 'admin'" class="sys-badge">系统</span>
                  </div>
                  <div class="role-code-row">
                    <code class="role-code-text" :title="row.code">{{ row.code }}</code>
                  </div>
                </div>
              </template>
            </el-table-column>

            <el-table-column label="数据范围" width="96" align="center">
              <template slot-scope="{ row }">
                <span class="scope-pill" :class="`scope-${row.data_scope}`">
                  {{ scopeText(row.data_scope) }}
                </span>
              </template>
            </el-table-column>

            <el-table-column label="状态" width="65" align="center">
              <template slot-scope="{ row }">
                <span class="status-indicator" :class="row.enabled ? 'is-enabled' : 'is-disabled'">
                  {{ row.enabled ? '启用' : '停用' }}
                </span>
              </template>
            </el-table-column>

            <el-table-column label="成员" width="50" align="center">
              <template slot-scope="{ row }">
                <span class="member-count-badge" :class="{ 'has-members': (row.member_count || 0) > 0 }">
                  {{ row.member_count || 0 }}
                </span>
              </template>
            </el-table-column>

            <el-table-column label="操作" width="65" align="center">
              <template slot-scope="{ row }">
                <el-button type="text" class="btn-select-role" @click.stop="selectRole(row)">
                  {{ currentRole && currentRole.id === row.id ? '当前' : '配置' }}
                </el-button>
              </template>
            </el-table-column>
          </el-table>
        </div>

        <div class="role-pager">
          <span class="pager-total">共 {{ roleTotal }} 条</span>
          <el-pagination
            background
            small
            layout="prev, pager, next"
            :page-size.sync="rolePageSize"
            :current-page.sync="rolePage"
            :total="roleTotal"
            @current-change="handleRolePageChange"
          />
        </div>
      </aside>

      <!-- 右侧：当前选中角色详情与权限配置面板 -->
      <main class="role-editor" v-if="currentRole">
        <!-- 顶部角色身份条：含顶部保存权限快捷按钮 -->
        <div class="role-profile-banner">
          <div class="profile-left">
            <div class="role-avatar-icon">
              <i class="el-icon-s-custom" />
            </div>
            <div class="role-meta-info">
              <div class="role-title-line">
                <h2 class="current-role-title">{{ currentRole.name }}</h2>
                <code class="current-role-code">{{ currentRole.code }}</code>
                <span v-if="currentRole.is_system || currentRole.code === 'admin'" class="sys-badge">系统预置</span>
                <span class="status-indicator" :class="currentRole.enabled ? 'is-enabled' : 'is-disabled'">
                  {{ currentRole.enabled ? '正常启用' : '已停用' }}
                </span>
              </div>
              <div class="role-sub-line">
                <span class="meta-item">
                  <i class="el-icon-data-analysis" /> 范围：<b>{{ scopeText(currentRole.data_scope) }}</b>
                </span>
                <span class="meta-item clickable" @click="openMembers">
                  <i class="el-icon-user" /> 关联成员：<b>{{ currentRole.member_count || 0 }} 人</b>
                  <el-link type="success" :underline="false" class="sub-link">(查看)</el-link>
                </span>
                <span class="meta-item" v-if="currentRole.updated_at">
                  <i class="el-icon-time" /> 更新：{{ currentRole.updated_at }}
                </span>
              </div>
            </div>
          </div>

          <!-- 右侧高频操作：保存权限配置按钮置于顶部明显位置 -->
          <div class="profile-actions">
            <el-button
              v-if="canSavePermissions"
              size="small"
              type="success"
              icon="el-icon-check"
              :loading="saving"
              :disabled="currentRole.code === 'admin'"
              class="btn-save-perm-top"
              @click="save"
            >
              保存权限配置
            </el-button>
            <el-button
              v-if="currentRole.code !== 'admin' && canSavePermissions"
              size="small"
              icon="el-icon-refresh-left"
              @click="resetPermissionSelection"
            >
              还原
            </el-button>
            <el-button
              v-if="$can('system.role.save_permissions')"
              size="small"
              icon="el-icon-edit"
              @click="openRoleForm(currentRole)"
            >
              修改角色
            </el-button>
            <el-button
              v-if="$can('system.role.save_permissions')"
              size="small"
              icon="el-icon-user"
              @click="openMembers"
            >
              角色成员
            </el-button>
            <el-button
              v-if="$can('system.role.save_permissions') && currentRole.code !== 'admin'"
              size="small"
              :icon="currentRole.enabled ? 'el-icon-video-pause' : 'el-icon-video-play'"
              @click="toggleRole"
            >
              {{ currentRole.enabled ? '停用' : '启用' }}
            </el-button>
            <el-button
              v-if="$can('system.role.delete') && !currentRole.is_system"
              size="small"
              type="text"
              class="danger-link btn-del-role"
              icon="el-icon-delete"
              @click="removeRole"
            >
              删除
            </el-button>
          </div>
        </div>

        <!-- 权限树配置卡片 -->
        <section class="permission-section">
          <div class="section-header">
            <div class="header-left-title">
              <span class="decor-bar" />
              <h3>功能与按钮权限</h3>
              <span class="perm-counter-badge">已配置 {{ checkedPermissionIds.length }} 项</span>
            </div>
            <div class="header-right-note">
              <span v-if="currentRole.code === 'admin'" class="admin-notice">
                <i class="el-icon-info" /> 系统管理员固定拥有全部已启用权限
              </span>
            </div>
          </div>

          <!-- 权限树操作工具栏：搜索与快捷按钮 -->
          <div class="tree-control-bar">
            <div class="tree-search-box">
              <el-input
                v-model="filterPermissionText"
                size="small"
                clearable
                prefix-icon="el-icon-search"
                placeholder="搜索权限名称或编码（如：采购、财务、system...）"
              />
            </div>
            <div class="tree-btn-group">
              <el-button size="mini" icon="el-icon-folder-opened" @click="expandAll">全部展开</el-button>
              <el-button size="mini" icon="el-icon-folder" @click="collapseAll">全部折叠</el-button>
              <el-button
                v-if="currentRole.code !== 'admin' && canSavePermissions"
                size="mini"
                icon="el-icon-check"
                @click="checkAllPermissions"
              >
                全选
              </el-button>
              <el-button
                v-if="currentRole.code !== 'admin' && canSavePermissions"
                size="mini"
                icon="el-icon-close"
                @click="uncheckAllPermissions"
              >
                清空
              </el-button>
            </div>
          </div>

          <!-- 权限树主体 -->
          <div class="rbac-tree-wrapper">
            <el-tree
              ref="permTree"
              :data="permissionTree"
              show-checkbox
              check-strictly
              node-key="id"
              :filter-node-method="filterPermissionNode"
              :default-expand-all="false"
              :props="{ label: 'name', children: 'children', disabled: () => !canSavePermissions || currentRole.code === 'admin' }"
              class="rbac-perm-tree"
              @check="syncChecked"
            >
              <div class="tree-node-row" slot-scope="{ data }">
                <span class="node-main" :class="`level-${nodeLevel(data)}`">
                  <i :class="iconFor(data)" class="node-icon" />
                  <span class="node-label">{{ data.name }}</span>
                </span>
                <span v-if="!data.parent_id" class="badge-tag badge-dark">模块</span>
                <span v-else-if="data.type === 'menu'" class="badge-tag badge-green">菜单</span>
                <span v-else-if="data.type === 'button'" class="badge-tag badge-teal">按钮</span>
                <span v-else class="badge-tag badge-amber">接口</span>
                <code class="perm-code-tag">{{ data.code }}</code>
              </div>
            </el-tree>
          </div>
        </section>

        <!-- 紧凑单行式数据范围控制栏 -->
        <section class="compact-scope-section">
          <div class="scope-bar-left">
            <span class="decor-bar" />
            <span class="scope-bar-label">数据范围控制：</span>
            <div class="scope-chip-group">
              <button
                type="button"
                class="scope-chip"
                :class="{
                  active: currentRole.data_scope === 'all',
                  disabled: !canSavePermissions || currentRole.code === 'admin'
                }"
                @click="selectScope('all')"
              >
                <i class="el-icon-data-analysis" />
                <span class="chip-name">全部数据</span>
                <span class="chip-sub">全企业</span>
              </button>

              <button
                type="button"
                class="scope-chip"
                :class="{
                  active: currentRole.data_scope === 'department',
                  disabled: !canSavePermissions || currentRole.code === 'admin'
                }"
                @click="selectScope('department')"
              >
                <i class="el-icon-office-building" />
                <span class="chip-name">本部门数据</span>
                <span class="chip-sub">部门隔离</span>
              </button>

              <button
                type="button"
                class="scope-chip"
                :class="{
                  active: currentRole.data_scope === 'self',
                  disabled: !canSavePermissions || currentRole.code === 'admin'
                }"
                @click="selectScope('self')"
              >
                <i class="el-icon-user" />
                <span class="chip-name">本人数据</span>
                <span class="chip-sub">个人私有</span>
              </button>
            </div>
          </div>

          <div class="scope-bar-right">
            <span class="scope-preview-inline" :title="scopePreview">
              <i class="el-icon-info" /> {{ scopePreview }}
            </span>
          </div>
        </section>

        <!-- 紧凑底部吸底操作栏 -->
        <div class="sticky-footer-bar">
          <div class="footer-left">
            <span class="risk-badge"><i class="el-icon-warning-outline" /> 风险提示</span>
            <span class="footer-tip">
              当前角色拥有 <b>{{ checkedPermissionIds.length }}</b> 项权限，直接影响 <b>{{ currentRole.member_count || 0 }}</b> 位系统用户。
            </span>
          </div>
          <div class="footer-actions">
            <span v-if="currentRole.code === 'admin'" class="admin-disabled-tip">
              系统管理员固定拥有全部权限
            </span>
            <el-button
              v-if="canSavePermissions"
              type="success"
              size="small"
              icon="el-icon-check"
              :loading="saving"
              :disabled="currentRole.code === 'admin'"
              class="btn-save-perm"
              @click="save"
            >
              保存权限配置
            </el-button>
          </div>
        </div>
      </main>

      <div v-else class="empty-state">
        <i class="el-icon-s-custom" />
        <p>暂无角色数据，请新增角色或调整搜索条件。</p>
      </div>
    </div>

    <!-- 弹窗：新增/修改角色 -->
    <el-dialog
      :title="roleForm.id ? '修改角色' : '新增角色'"
      :visible.sync="roleFormVisible"
      append-to-body
      width="760px"
      top="5vh"
      custom-class="system-management-dialog"
      :close-on-click-modal="false"
      :close-on-press-escape="!saving"
      :show-close="!saving"
      @closed="!roleFormVisible && resetRoleForm()"
    >
      <el-form ref="roleForm" :model="roleForm" :rules="roleRules" label-position="top">
        <div class="form-grid">
          <el-form-item label="角色编码" prop="code">
            <el-input
              v-model.trim="roleForm.code"
              maxlength="80"
              placeholder="如：finance_auditor"
              :disabled="!!roleForm.is_system"
            />
            <div class="field-note" v-if="roleForm.is_system">系统预置角色编码不可更改</div>
          </el-form-item>

          <el-form-item label="角色名称" prop="name">
            <el-input v-model.trim="roleForm.name" maxlength="120" placeholder="如：财务审核员" />
          </el-form-item>

          <el-form-item label="角色状态">
            <el-radio-group v-model="roleForm.enabled" :disabled="roleForm.code === 'admin'">
              <el-radio :label="true">正常启用</el-radio>
              <el-radio :label="false">停用禁用</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="默认数据范围">
            <el-select v-model="roleForm.data_scope" :disabled="roleForm.code === 'admin'">
              <el-option label="全部数据（全企业）" value="all" />
              <el-option label="本部门数据（按部门）" value="department" />
              <el-option label="本人数据（仅个人）" value="self" />
            </el-select>
          </el-form-item>

          <el-form-item label="说明与备注" class="full-width">
            <el-input
              v-model="roleForm.remark"
              type="textarea"
              :rows="3"
              maxlength="2000"
              placeholder="请输入角色职责、岗位范围及配置说明..."
            />
          </el-form-item>
        </div>
      </el-form>
      <span slot="footer">
        <el-button :disabled="saving" @click="roleFormVisible = false">取消</el-button>
        <el-button type="success" :loading="saving" @click="submitRoleForm">保存角色</el-button>
      </span>
    </el-dialog>

    <!-- 弹窗：角色成员管理 -->
    <el-dialog
      :title="membersRole ? membersRole.name + ' · 角色成员' : '角色成员'"
      :visible.sync="membersVisible"
      append-to-body
      width="780px"
      top="5vh"
      custom-class="system-management-dialog"
      :close-on-click-modal="false"
    >
      <div class="member-tools">
        <el-input
          v-model="memberKeyword"
          clearable
          size="small"
          prefix-icon="el-icon-search"
          placeholder="搜索姓名 / 登录账号 / 手机号..."
          @keyup.enter.native="queryMembers"
        />
        <el-button size="small" type="success" icon="el-icon-search" @click="queryMembers">查询</el-button>
        <el-button
          v-if="membersRole && membersRole.enabled"
          size="small"
          type="primary"
          icon="el-icon-plus"
          class="btn-theme-member"
          @click="userPickerVisible = true"
        >
          添加成员
        </el-button>
      </div>

      <el-table v-loading="memberLoading" :data="memberRows" border class="member-table">
        <el-table-column label="姓名" min-width="120">
          <template slot-scope="{ row }">
            <strong>{{ row.nickname || row.username }}</strong>
          </template>
        </el-table-column>
        <el-table-column prop="username" label="登录账号" min-width="120" />
        <el-table-column label="分配来源" min-width="160">
          <template slot-scope="{ row }">
            <el-tag size="mini" effect="plain" class="source-tag">
              {{ memberSourceText(row) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120" align="center" fixed="right">
          <template slot-scope="{ row }">
            <el-button
              v-if="row.is_manual && !(membersRole.code === 'admin' && row.username === 'admin')"
              type="text"
              class="danger-link"
              :disabled="memberSaving"
              @click="removeMember(row)"
            >
              移除手工分配
            </el-button>
            <span v-else class="text-muted text-xs">系统继承</span>
          </template>
        </el-table-column>
      </el-table>

      <div class="role-pager">
        <span>共 {{ memberTotal }} 位成员</span>
        <el-pagination
          background
          small
          layout="prev, pager, next"
          :page-size="10"
          :current-page="memberPage"
          :total="memberTotal"
          @current-change="changeMemberPage"
        />
      </div>

      <span slot="footer">
        <el-button @click="membersVisible = false">关闭</el-button>
      </span>
    </el-dialog>

    <!-- 人员选择器弹窗 -->
    <system-record-picker :visible.sync="userPickerVisible" type="users" @confirm="addMembers" />
  </section>
</template>

<script>
import { listPermissions, listRoles, saveRole, deleteRole, listRoleUsers, saveRoleUsers } from '@/api/erp/rbac'
import SystemRecordPicker from '@/components/system/SystemRecordPicker.vue'
import { newSystemCommand, permissionClosure, systemMutation, systemFormCommand } from '@/utils/systemManagement'
import '@/styles/system-management.css'

const emptyRole = () => ({ id: null, code: '', name: '', data_scope: 'self', enabled: true, remark: '', is_system: false })

export default {
  components: { SystemRecordPicker },
  data: () => ({
    roles: [],
    permissions: [],
    currentRole: null,
    checkedPermissionIds: [],
    roleKeyword: '',
    roleStatusFilter: 'all',
    filterPermissionText: '',
    rolePage: 1,
    rolePageSize: 10,
    roleTotal: 0,
    loading: false,
    saving: false,
    loadSerial: 0,
    filterTimer: null,
    roleFormVisible: false,
    roleForm: emptyRole(),
    formCommand: '',
    formCommandState: null,
    permissionCommand: '',
    membersVisible: false,
    membersRole: null,
    memberRows: [],
    memberPage: 1,
    memberTotal: 0,
    memberKeyword: '',
    memberLoading: false,
    memberSaving: false,
    memberSerial: 0,
    userPickerVisible: false,
    roleRules: {
      code: [
        { required: true, message: '请输入角色编码', trigger: 'blur' },
        { pattern: /^[A-Za-z0-9_.-]+$/, message: '编码只能包含字母、数字、点、短横线或下划线', trigger: 'blur' }
      ],
      name: [{ required: true, message: '请输入角色名称', trigger: 'blur' }]
    }
  }),
  computed: {
    canSavePermissions() {
      return typeof this.$can === 'function' ? this.$can('system.role.save_permissions') : true
    },
    filteredRoles() {
      if (this.roleStatusFilter === 'enabled') {
        return this.roles.filter(r => r.enabled)
      }
      if (this.roleStatusFilter === 'disabled') {
        return this.roles.filter(r => !r.enabled)
      }
      return this.roles
    },
    pagedRoles() {
      return this.filteredRoles
    },
    enabledRolesCount() {
      return this.roles.filter(r => r.enabled).length
    },
    disabledRolesCount() {
      return this.roles.filter(r => !r.enabled).length
    },
    permissionTree() {
      const map = {}
      this.permissions.forEach(item => { map[item.id] = { ...item, children: [] } })
      const roots = []
      this.permissions.forEach(item => {
        if (item.parent_id && map[item.parent_id]) map[item.parent_id].children.push(map[item.id])
        else roots.push(map[item.id])
      })
      return roots
    },
    scopePreview() {
      if (!this.currentRole) return ''
      if (this.currentRole.data_scope === 'all') return '可查看和操作全部业务数据，以及所授权的全部按钮动作。'
      if (this.currentRole.data_scope === 'department') return '按本部门及下属部门范围访问已授权业务，具体数据由各业务模块权限规则控制。'
      return '仅可查看与操作本人创建或归属给本人的销售订单、客户档案与业务单据。'
    }
  },
  created() {
    this.load()
  },
  beforeDestroy() {
    if (this.filterTimer) clearTimeout(this.filterTimer)
    this.loadSerial += 1
    this.memberSerial += 1
  },
  watch: {
    roleKeyword() {
      this.rolePage = 1
      if (this.filterTimer) clearTimeout(this.filterTimer)
      this.filterTimer = setTimeout(() => this.load(), 250)
    },
    filterPermissionText(val) {
      if (this.$refs.permTree) {
        this.$refs.permTree.filter(val)
      }
    }
  },
  methods: {
    async load() {
      const serial = ++this.loadSerial
      this.loading = true
      try {
        const [{ data: permissions }, { data: roleResponse }] = await Promise.all([
          listPermissions({ tree: 1 }),
          listRoles({ page: this.rolePage, per_page: this.rolePageSize, keyword: this.roleKeyword })
        ])
        if (serial !== this.loadSerial) return
        this.permissions = permissions || []
        this.roles = roleResponse.data || []
        this.roleTotal = roleResponse.meta.total
        if (!this.roles.length && this.rolePage > 1) {
          this.rolePage -= 1
          return this.load()
        }
        this.selectRole(this.currentRole ? this.roles.find(item => item.id === this.currentRole.id) || this.roles[0] : this.roles[0])
      } catch (error) {
        if (serial !== this.loadSerial) return
        this.roles = []
        this.roleTotal = 0
        this.currentRole = null
        this.checkedPermissionIds = []
        this.$message.error(error.userMessage || '角色权限加载失败')
      } finally {
        if (serial === this.loadSerial) this.loading = false
      }
    },
    selectRole(row) {
      this.currentRole = row ? { ...row } : null
      this.checkedPermissionIds = row ? [...(row.permission_ids || [])] : []
      this.permissionCommand = newSystemCommand()
      this.$nextTick(() => {
        if (this.$refs.permTree) {
          this.$refs.permTree.setCheckedKeys(this.checkedPermissionIds)
        }
      })
    },
    filterStatus(status) {
      this.roleStatusFilter = status
    },
    selectScope(scope) {
      if (!this.currentRole || this.currentRole.code === 'admin' || !this.canSavePermissions) return
      this.currentRole.data_scope = scope
    },
    handleRolePageChange(page) {
      this.rolePage = page
      this.load()
    },
    syncChecked() {
      this.checkedPermissionIds = this.$refs.permTree ? this.$refs.permTree.getCheckedKeys() : []
    },
    filterPermissionNode(value, data) {
      if (!value) return true
      const q = value.trim().toLowerCase()
      return (data.name && data.name.toLowerCase().includes(q)) || (data.code && data.code.toLowerCase().includes(q))
    },
    expandAll() {
      this.permissionTree.forEach(node => this.walkTree(node, item => {
        if (this.$refs.permTree && this.$refs.permTree.store.nodesMap[item.id]) {
          this.$refs.permTree.store.nodesMap[item.id].expanded = true
        }
      }))
    },
    collapseAll() {
      this.permissionTree.forEach(node => this.walkTree(node, item => {
        if (this.$refs.permTree && this.$refs.permTree.store.nodesMap[item.id]) {
          this.$refs.permTree.store.nodesMap[item.id].expanded = false
        }
      }))
    },
    checkAllPermissions() {
      if (this.currentRole?.code === 'admin') return
      const allIds = this.permissions.map(p => p.id)
      this.checkedPermissionIds = allIds
      if (this.$refs.permTree) {
        this.$refs.permTree.setCheckedKeys(allIds)
      }
    },
    uncheckAllPermissions() {
      if (this.currentRole?.code === 'admin') return
      this.checkedPermissionIds = []
      if (this.$refs.permTree) {
        this.$refs.permTree.setCheckedKeys([])
      }
    },
    resetPermissionSelection() {
      if (!this.currentRole) return
      this.checkedPermissionIds = [...(this.currentRole.permission_ids || [])]
      if (this.$refs.permTree) {
        this.$refs.permTree.setCheckedKeys(this.checkedPermissionIds)
      }
    },
    walkTree(node, cb) {
      cb(node)
      ;(node.children || []).forEach(child => this.walkTree(child, cb))
    },
    async save() {
      if (this.saving || !this.currentRole) return
      this.syncChecked()
      this.saving = true
      try {
        await saveRole({
          ...this.currentRole,
          permission_ids: permissionClosure(this.checkedPermissionIds, this.permissions),
          ...systemMutation(this.currentRole, this.permissionCommand)
        })
        this.$message.success('角色权限配置已保存')
        await this.load()
      } catch (error) {
        this.$message.error(error.userMessage || '角色权限保存失败')
        this.permissionCommand = newSystemCommand()
      } finally {
        this.saving = false
      }
    },
    resetRoleForm() {
      this.roleForm = emptyRole()
      this.formCommand = ''
      this.formCommandState = null
      this.$nextTick(() => this.$refs.roleForm && this.$refs.roleForm.clearValidate())
    },
    openRoleForm(row) {
      this.resetRoleForm()
      this.roleForm = row ? { ...emptyRole(), ...row, enabled: !!row.enabled } : emptyRole()
      this.formCommand = newSystemCommand()
      this.roleFormVisible = true
    },
    async submitRoleForm() {
      if (this.saving || !(await this.$refs.roleForm.validate().catch(() => false))) return
      this.saving = true
      const creating = !this.roleForm.id
      try {
        const payload = {
          id: this.roleForm.id || undefined,
          code: this.roleForm.code,
          name: this.roleForm.name,
          enabled: this.roleForm.enabled,
          data_scope: this.roleForm.data_scope,
          remark: this.roleForm.remark,
          ...(this.roleForm.id ? { expected_version: this.roleForm.business_version } : {})
        }
        this.formCommandState = systemFormCommand(this.formCommandState, payload, this.formCommand)
        const { data } = await saveRole({ ...payload, client_command_id: this.formCommandState.id })
        this.$message.success('角色已保存')
        this.roleFormVisible = false
        if (creating) {
          this.roleKeyword = data.code
          this.rolePage = 1
        }
        await this.load()
      } catch (error) {
        this.$message.error(error.userMessage || '角色保存失败')
        if (error.response && error.response.status !== 0) {
          this.formCommand = newSystemCommand()
          this.formCommandState = null
        }
      } finally {
        this.saving = false
      }
    },
    async toggleRole() {
      if (this.saving || !this.currentRole) return
      const row = { ...(this.roles.find(role => role.id === this.currentRole.id) || this.currentRole) }
      try {
        await this.$confirm(`确定${row.enabled ? '停用' : '启用'}角色“${row.name}”？`, '角色状态切换', { type: 'warning' })
      } catch (_) {
        return
      }
      this.saving = true
      try {
        await saveRole({
          id: row.id,
          code: row.code,
          name: row.name,
          data_scope: row.data_scope,
          remark: row.remark,
          enabled: !row.enabled,
          ...systemMutation(row)
        })
        this.$message.success('角色状态已更新')
        await this.load()
      } catch (error) {
        this.$message.error(error.userMessage || '角色状态更新失败')
      } finally {
        this.saving = false
      }
    },
    async removeRole() {
      const row = { ...this.currentRole }
      try {
        await this.$confirm(`确定删除角色“${row.name}”？仅可删除已停用且没有成员或审批引用的自定义角色。`, '删除角色', {
          type: 'warning',
          confirmButtonText: '删除',
          confirmButtonClass: 'el-button--danger'
        })
      } catch (_) {
        return
      }
      try {
        await deleteRole(row.id, systemMutation(row))
        this.$message.success('角色已删除')
        this.currentRole = null
        await this.load()
      } catch (error) {
        this.$message.error(error.userMessage || '角色删除失败')
      }
    },
    openMembers() {
      this.membersRole = { ...this.currentRole }
      this.memberRows = []
      this.memberKeyword = ''
      this.memberPage = 1
      this.membersVisible = true
      this.loadMembers()
    },
    async loadMembers() {
      if (!this.membersRole) return
      const serial = ++this.memberSerial
      this.memberLoading = true
      try {
        const { data } = await listRoleUsers(this.membersRole.id, { keyword: this.memberKeyword, page: this.memberPage, per_page: 10 })
        if (serial !== this.memberSerial) return
        this.memberRows = data.data
        this.memberTotal = data.meta.total
        if (!this.memberRows.length && this.memberPage > 1) {
          this.memberPage -= 1
          return this.loadMembers()
        }
      } catch (error) {
        if (serial === this.memberSerial) {
          this.memberRows = []
          this.memberTotal = 0
          this.$message.error(error.userMessage || '角色成员加载失败')
        }
      } finally {
        if (serial === this.memberSerial) this.memberLoading = false
      }
    },
    queryMembers() {
      this.memberPage = 1
      this.loadMembers()
    },
    changeMemberPage(page) {
      this.memberPage = page
      this.loadMembers()
    },
    memberSourceText(row) {
      return (row.sources && row.sources.length ? row.sources : ['manual']).map(source => ({
        manual: '手工分配',
        sso: '单点登录',
        department: '部门负责人'
      })[source] || '系统').join('、')
    },
    async changeMembers(delta) {
      if (this.memberSaving) return
      this.memberSaving = true
      try {
        const { data } = await saveRoleUsers({ role_id: this.membersRole.id, ...delta, ...systemMutation(this.membersRole) })
        this.membersRole.business_version = data.business_version
        this.$message.success('角色成员已更新')
        await this.loadMembers()
        await this.load()
      } catch (error) {
        this.$message.error(error.userMessage || '角色成员保存失败')
      } finally {
        this.memberSaving = false
      }
    },
    addMembers(rows) {
      if (rows.length) this.changeMembers({ add_user_ids: rows.map(row => row.id) })
    },
    async removeMember(row) {
      try {
        await this.$confirm(`确定移除“${row.nickname || row.username}”的手工角色分配？其他来源的角色关系会保留。`, '移除成员', { type: 'warning' })
      } catch (_) {
        return
      }
      await this.changeMembers({ remove_user_ids: [row.user_id] })
    },
    roleRowClassName({ row }) {
      return this.currentRole && this.currentRole.id === row.id ? 'active-role-row' : ''
    },
    iconFor(row) {
      if (row.icon) return row.icon
      if (!row.parent_id) return 'el-icon-folder-opened'
      return row.type === 'button' ? 'el-icon-thumb' : row.type === 'api' ? 'el-icon-connection' : 'el-icon-menu'
    },
    nodeLevel(row) {
      if (!row || !row.parent_id) return 1
      const parent = this.permissions.find(x => x.id === row.parent_id)
      if (parent && !parent.parent_id) return 2
      return 3
    },
    scopeText(scope) {
      return ({ all: '全部数据', department: '本部门数据', self: '本人数据' })[scope] || '本人数据'
    },
    scopeType(scope) {
      return ({ all: 'success', department: 'primary', self: 'warning' })[scope] || 'info'
    }
  }
}
</script>

<style scoped>
/* ==================== 1. 全局容器与重置 ==================== */
.role-page-container {
  min-height: calc(100vh - 52px);
  padding: 14px 20px;
  background: #f8fafc;
  color: #1e293b;
  box-sizing: border-box;
}

.role-page-container * {
  box-sizing: border-box;
}

/* ==================== 2. 紧凑页面头部规范 (.page-head) ==================== */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  padding: 10px 16px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.head-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #008b4b;
  font-size: 18px;
  flex-shrink: 0;
}

.head-title-wrap {
  display: flex;
  align-items: center;
  min-width: 0;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1.2;
}

.header-stat-pills {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.stat-pill {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 600;
  line-height: 18px;
  user-select: none;
}

.pill-total {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}

.pill-enabled {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
  cursor: pointer;
}

.pill-disabled {
  background: #fef2f2;
  color: #ef4444;
  border: 1px solid #fecaca;
  cursor: pointer;
}

.pill-perms {
  background: #f0fdfa;
  color: #0d9488;
  border: 1px solid #99f6e4;
}

.head-actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-shrink: 0;
}

.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}

.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

.btn-theme-member {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}

.btn-theme-member:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* ==================== 3. 主工作台分栏 ==================== */
.role-workbench {
  display: grid;
  grid-template-columns: 420px minmax(0, 1fr);
  gap: 14px;
  align-items: start;
}

.role-list-card,
.role-editor {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  min-width: 0;
}

/* ==================== 4. 左侧：角色列表卡片 ==================== */
.role-list-card {
  padding: 14px;
}

.list-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
  gap: 8px;
}

.header-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.header-title h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #1e293b;
}

.count-badge {
  padding: 1px 6px;
  background: #f1f5f9;
  border-radius: 10px;
  font-size: 11px;
  color: #64748b;
  font-weight: 600;
}

.status-tab-group {
  display: flex;
  background: #f1f5f9;
  border-radius: 6px;
  padding: 2px;
  gap: 2px;
}

.status-tab-group span {
  padding: 2px 7px;
  font-size: 11px;
  color: #64748b;
  cursor: pointer;
  border-radius: 4px;
  transition: all 0.15s ease;
  user-select: none;
}

.status-tab-group span:hover {
  color: #1e293b;
}

.status-tab-group span.active {
  background: #ffffff;
  color: #008b4b;
  font-weight: 600;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
}

.role-search-wrap {
  margin-bottom: 10px;
}

.role-table-wrap {
  border: 1px solid #f1f5f9;
  border-radius: 6px;
  overflow: hidden;
}

.role-table-wrap ::v-deep .el-table {
  border: none;
}

.role-table-wrap ::v-deep .el-table__body-wrapper {
  overflow-x: hidden !important;
}

.role-table-wrap ::v-deep .el-table th {
  background: #f8fafc;
  color: #475569;
  font-weight: 600;
  font-size: 12px;
  padding: 6px 4px !important;
}

.role-table-wrap ::v-deep .el-table td {
  padding: 6px 4px !important;
}

.role-table-wrap ::v-deep .active-role-row {
  background-color: #f0fdf4 !important;
}

.role-table-wrap ::v-deep .active-role-row td {
  background-color: #f0fdf4 !important;
}

.role-table-wrap ::v-deep .active-role-row td:first-child {
  border-left: 3px solid #008b4b !important;
}

.role-item-main {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.role-name-row {
  display: flex;
  align-items: center;
  gap: 6px;
}

.role-name-text {
  font-size: 13px;
  color: #1e293b;
  font-weight: 600;
  white-space: nowrap;
}

.sys-badge {
  display: inline-block;
  padding: 0 4px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  color: #64748b;
  border-radius: 3px;
  font-size: 10px;
  line-height: 14px;
  white-space: nowrap;
}

.role-code-row {
  margin-top: 1px;
}

.role-code-text {
  font-family: Consolas, Monaco, monospace;
  font-size: 11px;
  color: #64748b;
  background: #f8fafc;
  padding: 1px 4px;
  border-radius: 3px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  display: inline-block;
  max-width: 135px;
  vertical-align: middle;
}

.scope-pill {
  display: inline-block;
  padding: 2px 5px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 500;
  white-space: nowrap !important;
}

.scope-pill.scope-all {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}

.scope-pill.scope-department {
  background: #f0fdfa;
  color: #0d9488;
  border: 1px solid #99f6e4;
}

.scope-pill.scope-self {
  background: #fffbeb;
  color: #d97706;
  border: 1px solid #fde68a;
}

.member-count-badge {
  display: inline-block;
  min-width: 18px;
  height: 18px;
  line-height: 18px;
  padding: 0 4px;
  border-radius: 9px;
  background: #f1f5f9;
  color: #64748b;
  font-size: 11px;
  text-align: center;
  font-weight: 600;
  white-space: nowrap;
}

.member-count-badge.has-members {
  background: #e0f2fe;
  color: #0284c7;
}

.status-indicator {
  display: inline-block;
  padding: 1px 5px;
  border-radius: 3px;
  font-size: 11px;
  font-weight: 500;
  white-space: nowrap !important;
}

.status-indicator.is-enabled {
  background: #f0fdf4;
  color: #008b4b;
}

.status-indicator.is-disabled {
  background: #fef2f2;
  color: #ef4444;
}

.btn-select-role {
  color: #008b4b;
  font-size: 12px;
  padding: 2px 4px !important;
  white-space: nowrap !important;
}

.btn-select-role:hover {
  color: #00763f;
  text-decoration: underline;
}

.role-pager {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 10px;
  margin-top: 8px;
  border-top: 1px solid #f1f5f9;
  font-size: 12px;
  color: #64748b;
}

.role-pager ::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #ffffff;
}

.role-pager ::v-deep .el-pagination.is-background .el-pager li:hover {
  color: #008b4b;
}

/* ==================== 5. 右侧：角色详情与权限编辑器 ==================== */
.role-editor {
  display: flex;
  flex-direction: column;
  padding: 0;
  position: relative;
}

/* 5.1 角色身份条与操作区 */
.role-profile-banner {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 18px;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  border-top-left-radius: 8px;
  border-top-right-radius: 8px;
  gap: 12px;
  flex-wrap: wrap;
}

.profile-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.role-avatar-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 8px;
  color: #008b4b;
  font-size: 22px;
  flex-shrink: 0;
}

.role-meta-info {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.role-title-line {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.current-role-title {
  margin: 0;
  font-size: 17px;
  font-weight: 700;
  color: #1e293b;
}

.current-role-code {
  font-family: Consolas, Monaco, monospace;
  font-size: 11px;
  color: #475569;
  background: #f1f5f9;
  padding: 1px 5px;
  border-radius: 3px;
}

.role-sub-line {
  display: flex;
  align-items: center;
  gap: 14px;
  font-size: 12px;
  color: #64748b;
  flex-wrap: wrap;
}

.meta-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.meta-item b {
  color: #1e293b;
}

.meta-item.clickable {
  cursor: pointer;
  color: #008b4b;
}

.sub-link {
  font-size: 12px;
  color: #008b4b;
  margin-left: 2px;
}

.profile-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.btn-save-perm-top {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 600;
  box-shadow: 0 1px 3px rgba(0, 139, 75, 0.2);
}

.btn-save-perm-top:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

.profile-actions .btn-del-role {
  color: #ef4444;
  padding: 4px 8px;
}

.profile-actions .btn-del-role:hover {
  color: #dc2626;
  text-decoration: underline;
}

/* 5.2 模块公共分段标题 */
.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
  flex-wrap: wrap;
  gap: 8px;
}

.header-left-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.decor-bar {
  width: 3px;
  height: 14px;
  background: #008b4b;
  border-radius: 2px;
}

.section-header h3 {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
}

.perm-counter-badge {
  padding: 1px 7px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 600;
}

.header-right-note {
  font-size: 12px;
  color: #64748b;
}

.admin-notice {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #d97706;
  font-weight: 500;
  font-size: 12px;
}

/* 5.3 权限树区域 */
.permission-section {
  padding: 14px 18px;
  border-bottom: 1px solid #f1f5f9;
}

.tree-control-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}

.tree-search-box {
  flex: 1;
  min-width: 220px;
}

.tree-btn-group {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.tree-btn-group .el-button {
  padding: 6px 9px;
  border-color: #e2e8f0;
  color: #475569;
}

.tree-btn-group .el-button:hover {
  color: #008b4b;
  border-color: #bbf7d0;
  background: #f0fdf4;
}

.rbac-tree-wrapper {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #ffffff;
  overflow: hidden;
}

.rbac-tree-wrapper ::v-deep .el-tree {
  height: 380px;
  overflow-y: auto;
  overflow-x: auto;
  padding: 8px 12px;
}

.rbac-perm-tree ::v-deep .el-tree-node__content {
  height: 32px;
  border-radius: 4px;
  margin-bottom: 1px;
}

.rbac-perm-tree ::v-deep .el-tree-node__content:hover {
  background: #f8fafc;
}

.rbac-perm-tree ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}

.rbac-perm-tree ::v-deep .el-checkbox__input.is-indeterminate .el-checkbox__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}

.tree-node-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  width: 100%;
  min-width: 440px;
}

.node-main {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.node-icon {
  font-size: 14px;
  color: #008b4b;
}

.node-main.level-1 .node-icon {
  color: #008b4b;
  font-size: 14px;
}

.node-main.level-2 .node-icon {
  color: #0d9488;
  font-size: 13px;
}

.node-main.level-3 .node-icon {
  color: #d97706;
  font-size: 13px;
}

.node-main.level-1 .node-label {
  font-weight: 700;
  color: #0f172a;
}

.node-main.level-2 .node-label {
  font-weight: 500;
  color: #1e293b;
}

.node-main.level-3 .node-label {
  color: #475569;
}

.node-label {
  color: #1e293b;
}

.badge-tag {
  display: inline-block;
  padding: 0 5px;
  border-radius: 3px;
  font-size: 10px;
  line-height: 16px;
  font-weight: 500;
}

.badge-dark {
  background: #1e293b;
  color: #ffffff;
}

.badge-green {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}

.badge-teal {
  background: #f0fdfa;
  color: #0d9488;
  border: 1px solid #99f6e4;
}

.badge-amber {
  background: #fffbeb;
  color: #d97706;
  border: 1px solid #fde68a;
}

.perm-code-tag {
  display: inline-block;
  padding: 1px 5px;
  background: #f8fafc;
  color: #64748b;
  border: 1px solid #f1f5f9;
  border-radius: 3px;
  font-family: Consolas, Monaco, monospace;
  font-size: 11px;
  margin-left: auto;
  margin-right: 10px;
}

/* 5.4 紧凑单行式数据范围控制栏 */
.compact-scope-section {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 18px;
  background: #fbfcfe;
  border-bottom: 1px solid #f1f5f9;
  gap: 12px;
  flex-wrap: wrap;
}

.scope-bar-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.scope-bar-label {
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
  white-space: nowrap;
}

.scope-chip-group {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.scope-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  cursor: pointer;
  font-size: 12px;
  color: #475569;
  transition: all 0.15s ease;
  user-select: none;
}

.scope-chip:hover {
  border-color: #008b4b;
  color: #008b4b;
  background: #fafffc;
}

.scope-chip.active {
  border-color: #008b4b;
  background: #f0fdf4;
  color: #008b4b;
  font-weight: 600;
  box-shadow: 0 0 0 1px #008b4b;
}

.scope-chip.disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.chip-name {
  white-space: nowrap;
}

.chip-sub {
  font-size: 10px;
  padding: 1px 4px;
  background: #f1f5f9;
  border-radius: 3px;
  color: #64748b;
  font-weight: normal;
}

.scope-chip.active .chip-sub {
  background: #dcfce7;
  color: #166534;
}

.scope-bar-right {
  display: flex;
  align-items: center;
  min-width: 0;
}

.scope-preview-inline {
  font-size: 12px;
  color: #008b4b;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 360px;
}

/* 5.5 紧凑底部吸底操作栏 */
.sticky-footer-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 18px;
  background: #f8fafc;
  border-top: 1px solid #e2e8f0;
  border-bottom-left-radius: 8px;
  border-bottom-right-radius: 8px;
  gap: 12px;
  flex-wrap: wrap;
}

.footer-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.risk-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 6px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #d97706;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
}

.footer-tip {
  font-size: 12px;
  color: #475569;
}

.footer-tip b {
  color: #008b4b;
}

.footer-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.admin-disabled-tip {
  font-size: 12px;
  color: #64748b;
}

.btn-save-perm {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 600;
}

.btn-save-perm:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* ==================== 6. 成员管理弹窗样式 ==================== */
.member-tools {
  display: flex;
  gap: 10px;
  margin-bottom: 14px;
  flex-wrap: wrap;
}

.member-tools .el-input {
  flex: 1;
  min-width: 200px;
}

.member-table ::v-deep th {
  background: #f8fafc;
  color: #475569;
  font-weight: 600;
}

.source-tag {
  background: #f0fdf4 !important;
  color: #008b4b !important;
  border-color: #bbf7d0 !important;
}

/* ==================== 7. 空态 ==================== */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 20px;
  color: #94a3b8;
}

.empty-state i {
  font-size: 48px;
  margin-bottom: 12px;
  color: #cbd5e1;
}

.empty-state p {
  margin: 0;
  font-size: 14px;
}

/* ==================== 8. 响应式适配规范 ==================== */
@media (max-width: 1180px) {
  .role-workbench {
    grid-template-columns: minmax(0, 1fr);
  }

  .role-list-card ::v-deep .el-table {
    height: 320px !important;
  }
}

@media (max-width: 780px) {
  .role-page-container {
    padding: 10px;
  }

  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }

  .head-actions {
    width: 100%;
    justify-content: flex-end;
  }

  .role-profile-banner {
    flex-direction: column;
    align-items: flex-start;
  }

  .profile-actions {
    width: 100%;
    justify-content: flex-start;
  }

  .tree-control-bar {
    flex-direction: column;
    align-items: stretch;
  }

  .tree-search-box {
    width: 100%;
  }

  .tree-btn-group {
    justify-content: flex-start;
  }

  .compact-scope-section {
    flex-direction: column;
    align-items: flex-start;
  }

  .sticky-footer-bar {
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
  }

  .footer-actions {
    justify-content: flex-end;
  }

  .member-tools .el-input {
    flex-basis: 100%;
  }
}
</style>
