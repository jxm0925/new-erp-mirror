<template>
  <section class="admin-page-container">
    <div class="admin-workspace">
      <!-- 页面全局头部：图标、标题、统计标签与主要操作 -->
      <header class="page-head">
        <div class="head-left">
          <span class="head-icon"><i class="el-icon-user-solid" /></span>
          <div class="head-title-wrap">
            <div class="title-row">
              <h1 class="page-title">管理员管理</h1>
              <el-tag size="small" type="success" effect="plain" class="total-tag">
                共 {{ total }} 位管理员
              </el-tag>
            </div>
          </div>
        </div>
        <div class="head-actions">
          <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="load">刷新</el-button>
          <el-button
            v-if="$can('system.admin.create')"
            size="small"
            type="success"
            icon="el-icon-plus"
            class="btn-theme-create"
            @click="openCreate"
          >
            新增管理员
          </el-button>
        </div>
      </header>

      <!-- 全局统一页面提示条（遵循主数据中心规范） -->
      <div class="erp-page-tip">
        <i class="el-icon-info" />
        <span>统一管理 ERP 系统管理员账号、角色分配、所属部门与数据权限范围。遵循企业统一身份原则，业务负责人与经办人员共用管理员账号体系，业务身份按部门归属自动识别。</span>
      </div>

      <!-- 概览统计指标卡片（参照基本数据指标卡设计） -->
      <section class="metric-overview-grid">
        <div
          class="metric-card metric-all"
          :class="{ active: !filters.status || filters.status === 'all' }"
          title="点击查看全部管理员"
          @click="filterStatus('all')"
        >
          <div class="metric-icon-box"><i class="el-icon-user" /></div>
          <div class="metric-info">
            <span class="metric-label">全部管理员档案</span>
            <strong class="metric-val code-mono">{{ total }}</strong>
          </div>
        </div>

        <div
          class="metric-card metric-enabled"
          :class="{ active: filters.status === 'normal' }"
          title="点击筛选正常启用管理员"
          @click="filterStatus('normal')"
        >
          <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
          <div class="metric-info">
            <span class="metric-label">正常启用状态</span>
            <strong class="metric-val code-mono text-success">{{ normalCount }}</strong>
          </div>
        </div>

        <div
          class="metric-card metric-disabled"
          :class="{ active: filters.status === 'hidden' }"
          title="点击筛选已停用管理员"
          @click="filterStatus('hidden')"
        >
          <div class="metric-icon-box"><i class="el-icon-circle-close" /></div>
          <div class="metric-info">
            <span class="metric-label">停用 / 禁用状态</span>
            <strong class="metric-val code-mono text-danger">{{ hiddenCount }}</strong>
          </div>
        </div>

        <div
          class="metric-card metric-department"
          title="已关联部门管理员"
        >
          <div class="metric-icon-box"><i class="el-icon-office-building" /></div>
          <div class="metric-info">
            <span class="metric-label">已归属部门管理员</span>
            <strong class="metric-val code-mono">{{ withDepartmentCount }}</strong>
          </div>
        </div>
      </section>

      <!-- 筛选工具栏与主表格卡片 -->
      <section class="table-container-card">
        <div class="filter-toolbar">
          <div class="filter-fields">
            <div class="filter-item">
              <span class="filter-label">关键字</span>
              <el-input
                v-model.trim="filters.keyword"
                size="small"
                clearable
                prefix-icon="el-icon-search"
                placeholder="姓名 / 账号 / 手机号..."
                class="filter-input-search"
                @keyup.enter.native="query"
                @clear="query"
              />
            </div>

            <div class="filter-item">
              <span class="filter-label">所属部门</span>
              <el-select
                v-model="filters.department_name"
                size="small"
                clearable
                filterable
                placeholder="全部部门"
                class="filter-select-dept"
                @change="query"
              >
                <el-option v-for="d in departments" :key="d.legacy_id" :label="d.name" :value="d.name" />
              </el-select>
            </div>

            <div class="filter-item">
              <span class="filter-label">所属角色</span>
              <div class="role-filter-box">
                <el-input
                  :value="filters.group_name"
                  size="small"
                  readonly
                  placeholder="选择角色..."
                  class="role-filter-input"
                  @click.native="openRoleFilter"
                >
                  <el-button slot="append" icon="el-icon-search" @click="openRoleFilter" />
                </el-input>
                <el-button
                  v-if="filters.group_name"
                  type="text"
                  size="mini"
                  class="clear-role-btn"
                  @click="clearRoleFilter"
                >
                  清除
                </el-button>
              </div>
            </div>

            <div class="filter-item">
              <span class="filter-label">账号状态</span>
              <el-select
                v-model="filters.status"
                size="small"
                placeholder="全部状态"
                class="filter-select-sm"
                @change="query"
              >
                <el-option label="全部状态" value="all" />
                <el-option label="正常启用" value="normal" />
                <el-option label="隐藏/禁用" value="hidden" />
              </el-select>
            </div>

            <div class="filter-item">
              <span class="filter-label">数据范围</span>
              <el-select
                v-model="filters.scope"
                size="small"
                clearable
                placeholder="全部范围"
                class="filter-select-sm"
                @change="query"
              >
                <el-option label="全部数据" value="all" />
                <el-option label="本部门数据" value="department" />
                <el-option label="仅本人数据" value="self" />
              </el-select>
            </div>
          </div>

          <div class="filter-actions">
            <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" @click="query">查询</el-button>
            <el-button size="small" icon="el-icon-refresh-left" class="btn-theme-reset" @click="reset">重置</el-button>
          </div>
        </div>

        <div class="table-wrap">
          <el-table
            ref="adminTable"
            v-loading="loading"
            :data="users"
            border
            size="small"
            highlight-current-row
            empty-text="暂无匹配管理员记录，请尝试更改筛选条件。"
            class="enterprise-table"
            @row-click="selectUser"
          >
            <el-table-column label="管理员" min-width="140">
              <template slot-scope="{ row }">
                <div class="admin-cell">
                  <span class="avatar-dot" :style="{ background: avatarColor(row) }">
                    {{ displayName(row).slice(0, 1) }}
                  </span>
                  <div class="admin-cell-meta">
                    <strong class="admin-name" title="点击查看详情" @click.stop="selectUser(row)">{{ displayName(row) }}</strong>
                    <small class="admin-subtext" :title="row.mobile || row.email || '-'">{{ row.mobile || row.email || '-' }}</small>
                  </div>
                </div>
              </template>
            </el-table-column>

            <el-table-column prop="username" label="登录账号" min-width="100">
              <template slot-scope="{ row }">
                <span class="account-badge code-mono">{{ row.username }}</span>
              </template>
            </el-table-column>

            <el-table-column label="所属部门" min-width="120" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <span v-if="firstJsonLabel(row.department_names) !== '-'" class="dept-badge">
                  <i class="el-icon-office-building" />
                  {{ firstJsonLabel(row.department_names) }}
                </span>
                <span v-else class="text-muted">-</span>
              </template>
            </el-table-column>

            <el-table-column label="岗位/主要角色" min-width="120" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <el-tag size="mini" effect="plain" class="role-tag">
                  {{ primaryRole(row) }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column label="数据范围" width="100" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="scopeType(userScope(row))" effect="light" class="scope-tag">
                  {{ scopeText(userScope(row)) }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column label="组织身份" width="95" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="identityType(row)" effect="plain" class="identity-tag">
                  {{ departmentIdentity(row) }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column label="账号状态" width="85" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="activeAccount(row) ? 'success' : 'danger'" effect="light" class="status-tag">
                  <i :class="activeAccount(row) ? 'el-icon-circle-check' : 'el-icon-circle-close'" />
                  {{ activeAccount(row) ? '正常' : '停用' }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column label="最近登录" width="90" align="center">
              <template>
                <span class="text-muted">-</span>
              </template>
            </el-table-column>

            <el-table-column label="操作" width="190" fixed="right" align="center">
              <template slot-scope="{ row }">
                <div class="row-actions">
                  <el-button type="text" size="mini" class="btn-action-view" icon="el-icon-view" @click.stop="selectUser(row)">详情</el-button>
                  <el-button v-if="$can('system.admin.edit')" type="text" size="mini" class="btn-action-edit" icon="el-icon-edit" @click.stop="openEdit(row)">修改</el-button>
                  <el-button
                    v-if="$can('system.admin.toggle_status') && !protectedAccount(row)"
                    type="text"
                    size="mini"
                    :class="activeAccount(row) ? 'btn-action-disable' : 'btn-action-enable'"
                    :icon="activeAccount(row) ? 'el-icon-video-pause' : 'el-icon-video-play'"
                    :disabled="busyId === row.id"
                    @click.stop="toggleStatus(row)"
                  >
                    {{ activeAccount(row) ? '停用' : '启用' }}
                  </el-button>
                  <el-button
                    v-if="$can('system.admin.delete') && !protectedAccount(row)"
                    type="text"
                    size="mini"
                    class="btn-action-delete"
                    icon="el-icon-delete"
                    :disabled="busyId === row.id"
                    @click.stop="removeAdmin(row)"
                  >
                    删除
                  </el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>
        </div>

        <footer class="table-pagination-footer">
          <span class="total-text">共 <strong>{{ total }}</strong> 位管理员</span>
          <el-pagination
            background
            layout="total, sizes, prev, pager, next, jumper"
            :current-page="page"
            :page-size="pageSize"
            :page-sizes="[10, 20, 50, 100]"
            :total="total"
            @size-change="handleSizeChange"
            @current-change="handlePageChange"
          />
        </footer>
      </section>

      <!-- 管理员详情模态弹窗（居中遮罩标准规范） -->
      <el-dialog
        title="管理员详情"
        :visible.sync="detailVisible"
        append-to-body
        top="5vh"
        width="760px"
        custom-class="admin-detail-dialog item-detail-modal"
        :close-on-click-modal="false"
      >
        <div v-loading="detailLoading" class="admin-detail-content">
          <template v-if="selected">
            <div class="modal-summary-banner">
              <span class="portrait" :style="{ background: avatarColor(selected) }">
                {{ displayName(selected).slice(0, 1) }}
              </span>
              <div class="banner-main">
                <div class="banner-title-line">
                  <h2>{{ displayName(selected) }}</h2>
                  <el-tag size="mini" :type="activeAccount(selected) ? 'success' : 'danger'" effect="light" class="status-tag">
                    <i :class="activeAccount(selected) ? 'el-icon-circle-check' : 'el-icon-circle-close'" />
                    {{ activeAccount(selected) ? '正常启用' : '已禁用' }}
                  </el-tag>
                  <el-tag size="mini" :type="identityType(selected)" effect="plain">
                    {{ departmentIdentity(selected) }}
                  </el-tag>
                  <el-tag size="mini" :type="scopeType(userScope(selected))" effect="light">
                    {{ scopeText(userScope(selected)) }}
                  </el-tag>
                </div>
                <div class="banner-sub-meta">
                  <span>登录账号：<strong class="code-mono">{{ selected.username || '-' }}</strong></span>
                  <span>手机号：<strong class="code-mono">{{ selected.mobile || '-' }}</strong></span>
                  <span>电子邮箱：<strong>{{ selected.email || '-' }}</strong></span>
                </div>
              </div>
            </div>

            <div class="detail-cards-stack">
              <section class="detail-card">
                <div class="card-title-bar">
                  <div class="card-icon-badge"><i class="el-icon-office-building" /></div>
                  <h3>部门关系与层级</h3>
                </div>
                <dl class="info-grid">
                  <div class="info-item">
                    <span class="info-label">所属部门</span>
                    <span class="info-val">{{ joinJsonLabels(selected.department_names) || '-' }}</span>
                  </div>
                  <div class="info-item">
                    <span class="info-label">部门路径</span>
                    <span class="info-val">公司 / {{ firstJsonLabel(selected.department_names) || '-' }}</span>
                  </div>
                  <div class="info-item full-width">
                    <span class="info-label">部门负责人</span>
                    <span class="info-val">
                      <el-tag v-if="isPrincipal(selected)" size="mini" type="success" effect="light">是（部门负责人）</el-tag>
                      <span v-else class="text-muted">否</span>
                    </span>
                  </div>
                </dl>
              </section>

              <section class="detail-card">
                <div class="card-title-bar space-between">
                  <div class="card-title-left">
                    <div class="card-icon-badge"><i class="el-icon-user" /></div>
                    <h3>角色与权限统计</h3>
                  </div>
                </div>
                <div class="tag-list mb-12">
                  <el-tag v-for="role in userRoles(selected)" :key="role" size="small" effect="plain" class="role-tag-item">
                    {{ role }}
                  </el-tag>
                  <span v-if="!userRoles(selected).length" class="text-muted text-xs">暂无配置角色</span>
                </div>
                <div class="metric-row">
                  <div class="metric-block">
                    <b>{{ (selected.permission_summary || {}).menu || 0 }}</b>
                    <small>菜单权限</small>
                  </div>
                  <div class="metric-block">
                    <b>{{ (selected.permission_summary || {}).button || 0 }}</b>
                    <small>按钮权限</small>
                  </div>
                  <div class="metric-block">
                    <b>{{ (selected.permission_summary || {}).api || 0 }}</b>
                    <small>接口权限</small>
                  </div>
                </div>
              </section>

              <section class="detail-card">
                <div class="card-title-bar">
                  <div class="card-icon-badge"><i class="el-icon-data-analysis" /></div>
                  <h3>业务数据范围与边界</h3>
                </div>
                <ul class="scope-list">
                  <li><strong>销售订单：</strong>{{ userScope(selected) === 'self' ? '仅可查看由本人创建或归属本人的销售订单与客户' : userScope(selected) === 'department' ? '可查看本部门所有经办人的销售订单及客户' : '可查看全公司所有销售单据与履约数据' }}</li>
                  <li><strong>采购单据：</strong>按所授权采购角色及业务范围查看采购需求、采购计划与采购订单</li>
                  <li><strong>库存流水：</strong>按所授权仓库及所属部门范围查看库存余额、出入库记录与过账流水</li>
                  <li><strong>客户信息：</strong>遵循销售人员客户私海保护与团队共享白名单机制</li>
                </ul>
              </section>
            </div>
          </template>
          <div v-else class="empty-panel">
            <i class="el-icon-user" style="font-size: 32px; color: #94a3b8; margin-bottom: 8px;" />
            <div>点击列表管理员行即可查看权限全景与数据范围详情</div>
          </div>
        </div>

        <span slot="footer" class="dialog-footer">
          <el-button size="small" class="btn-dialog-cancel" @click="detailVisible = false">关闭</el-button>
          <el-button v-if="selected && $can('system.admin.edit')" size="small" type="success" class="btn-theme-create" icon="el-icon-edit" @click="openEdit(selected)">修改管理员</el-button>
        </span>
      </el-dialog>

      <!-- 新增 / 修改管理员模态弹窗 -->
      <el-dialog
        :title="form.id ? '修改管理员' : '新增管理员'"
        :visible.sync="formVisible"
        append-to-body
        top="5vh"
        width="740px"
        custom-class="admin-form-dialog item-detail-modal"
        :close-on-click-modal="false"
        :close-on-press-escape="!saving"
        :show-close="!saving"
        @closed="!formVisible && resetForm()"
      >
        <el-form ref="adminForm" :model="form" :rules="formRules" label-position="top" size="small" class="admin-edit-form">
          <div class="form-grid">
            <el-form-item label="登录账号" prop="username">
              <el-input
                v-model.trim="form.username"
                maxlength="80"
                :disabled="form.username === 'admin' && !!form.id"
                autocomplete="off"
                placeholder="请输入登录用户名"
              />
            </el-form-item>

            <el-form-item label="姓名" prop="nickname">
              <el-input v-model.trim="form.nickname" maxlength="120" placeholder="请输入管理员姓名" />
            </el-form-item>

            <el-form-item label="手机号" prop="mobile">
              <el-input v-model.trim="form.mobile" maxlength="40" placeholder="请输入手机号" />
            </el-form-item>

            <el-form-item label="邮箱" prop="email">
              <el-input v-model.trim="form.email" maxlength="120" placeholder="请输入常用电子邮箱" />
            </el-form-item>

            <el-form-item :label="form.id ? '新密码' : '初始密码'" prop="password">
              <el-input
                v-model="form.password"
                type="password"
                show-password
                autocomplete="new-password"
                maxlength="128"
                :placeholder="form.id ? '留空保持原密码不变' : '请输入初始登录密码（至少8位）'"
              />
              <div class="field-note">{{ form.id ? '留空保持原密码；如填写新密码，保存后原登录会话将失效。' : '初始密码长度不少于 8 个字符。' }}</div>
            </el-form-item>

            <el-form-item label="确认密码" prop="password_confirmation">
              <el-input
                v-model="form.password_confirmation"
                type="password"
                show-password
                autocomplete="new-password"
                maxlength="128"
                placeholder="请再次输入密码以确认"
              />
            </el-form-item>

            <el-form-item label="账号状态" prop="status">
              <el-radio-group v-model="form.status" :disabled="!!form.id && (!$can('system.admin.toggle_status') || protectedAccount(form))">
                <el-radio label="normal">正常启用</el-radio>
                <el-radio label="hidden">停用/禁用</el-radio>
              </el-radio-group>
            </el-form-item>

            <el-form-item label="排序权重">
              <el-input-number v-model="form.sort" controls-position="right" :min="-999999" :max="999999" class="full-input-number" />
            </el-form-item>

            <el-form-item label="所属部门" class="full-width">
              <el-cascader
                v-model="form.department_ids"
                :options="departmentOptions"
                :props="{ multiple: true, checkStrictly: true, emitPath: false }"
                clearable
                collapse-tags
                placeholder="请选择所属部门（支持多选）"
                class="full-width-cascader"
              />
            </el-form-item>

            <el-form-item label="手工分配角色" class="full-width">
              <div class="record-tags-box">
                <el-tag
                  v-for="role in manualRoles"
                  :key="role.id"
                  closable
                  effect="plain"
                  class="role-picker-tag"
                  @close="removeManualRole(role.id)"
                >
                  {{ role.name }}
                </el-tag>
                <el-button size="mini" icon="el-icon-plus" class="btn-add-role" @click="openFormRoles">选择角色</el-button>
              </div>
            </el-form-item>

            <el-form-item v-if="inheritedRoles.length" label="系统继承角色（只读）" class="full-width">
              <div class="record-tags-box">
                <el-tag v-for="role in inheritedRoles" :key="role.id" type="info" effect="plain" class="role-picker-tag">
                  {{ role.name }}（{{ sourceText(role.sources) }}）
                </el-tag>
              </div>
            </el-form-item>
          </div>
        </el-form>

        <span slot="footer" class="dialog-footer">
          <el-button size="small" :disabled="saving" class="btn-dialog-cancel" @click="formVisible = false">取消</el-button>
          <el-button size="small" type="success" :loading="saving" class="btn-theme-create" icon="el-icon-check" @click="submitForm">保存管理员</el-button>
        </span>
      </el-dialog>

      <!-- 角色选择弹窗 -->
      <system-record-picker
        :visible.sync="rolePickerVisible"
        type="roles"
        :role-status="rolePickerMode === 'filter' ? 'all' : 'enabled'"
        :multiple="rolePickerMode !== 'filter'"
        :value="rolePickerMode === 'filter' ? roleFilterSelection : manualRoles"
        @confirm="rolesPicked"
      />
    </div>
  </section>
</template>

<script>
import { listUsers, getSystemOptions, getAdmin, saveAdmin, deleteAdmin, setAdminStatus } from '@/api/erp/rbac'
import SystemRecordPicker from '@/components/system/SystemRecordPicker.vue'
import { departmentTree, newSystemCommand, systemMutation, systemFormCommand } from '@/utils/systemManagement'
import '@/styles/system-management.css'

const emptyAdmin = () => ({
  id: null,
  username: '',
  nickname: '',
  mobile: '',
  email: '',
  sort: 0,
  status: 'normal',
  password: '',
  password_confirmation: '',
  department_ids: [],
  manual_role_ids: [],
  expected_version: 1
})

export default {
  name: 'SystemAdminManagement',
  components: { SystemRecordPicker },
  data: () => ({
    users: [],
    departments: [],
    selected: null,
    detailVisible: false,
    detailLoading: false,
    loading: false,
    loadSerial: 0,
    formVisible: false,
    form: emptyAdmin(),
    manualRoles: [],
    inheritedRoles: [],
    saving: false,
    formCommand: '',
    formCommandState: null,
    busyId: null,
    rolePickerVisible: false,
    rolePickerMode: 'form',
    roleFilterSelection: [],
    page: 1,
    pageSize: 10,
    total: 0,
    filters: {
      keyword: '',
      department_name: '',
      group_name: '',
      role_id: '',
      status: 'all',
      scope: ''
    }
  }),
  computed: {
    departmentOptions() {
      return departmentTree(this.departments)
    },
    normalCount() {
      return this.users.filter(u => this.activeAccount(u)).length
    },
    hiddenCount() {
      return this.users.filter(u => !this.activeAccount(u)).length
    },
    withDepartmentCount() {
      return this.users.filter(u => {
        const depts = this.parseJson(u.department_names)
        return depts && depts.length > 0
      }).length
    },
    formRules() {
      return {
        username: [
          { required: true, message: '请输入登录账号', trigger: 'blur' },
          { pattern: /^[A-Za-z0-9_.@-]+$/, message: '账号只能包含字母、数字、点、下划线、短横线或@', trigger: 'blur' }
        ],
        nickname: [
          { required: true, message: '请输入姓名', trigger: 'blur' }
        ],
        email: [
          { type: 'email', message: '请输入有效的邮箱地址', trigger: 'blur' }
        ],
        password: [
          {
            validator: (_, value, done) => {
              if (!value && !this.form.id) done(new Error('请输入初始密码'))
              else if (value && value.length < 8) done(new Error('密码至少需要8个字符'))
              else done()
            },
            trigger: 'blur'
          }
        ],
        password_confirmation: [
          {
            validator: (_, value, done) =>
              done(this.form.password && value !== this.form.password ? new Error('两次输入的密码不一致') : undefined),
            trigger: 'blur'
          }
        ]
      }
    },
    filteredUsers() {
      return this.users
    }
  },
  created() {
    this.load()
  },
  beforeDestroy() {
    this.loadSerial += 1
  },
  methods: {
    async load() {
      const serial = ++this.loadSerial
      this.loading = true
      try {
        const { data: userResponse } = await listUsers({
          ...this.filters,
          group_name: this.filters.role_id ? undefined : this.filters.group_name,
          scope: 'system',
          data_scope: this.filters.scope,
          page: this.page,
          per_page: this.pageSize
        })
        if (serial !== this.loadSerial) return
        this.users = userResponse.data || userResponse
        this.total = userResponse.meta ? userResponse.meta.total : this.users.length
        if (!this.users.length && this.page > 1) {
          this.page -= 1
          return this.load()
        }
      } catch (error) {
        if (serial !== this.loadSerial) return
        this.users = []
        this.total = 0
        this.selected = null
        this.$message.error(error.userMessage || '管理员目录加载失败')
      } finally {
        if (serial === this.loadSerial) this.loading = false
      }
      try {
        const { data } = await getSystemOptions()
        if (serial === this.loadSerial) this.departments = data.departments || []
      } catch (error) {
        this.departments = []
        this.$message.warning(error.userMessage || '部门筛选项加载失败')
      }
    },
    query() {
      this.page = 1
      this.load()
    },
    reset() {
      this.filters = {
        keyword: '',
        department_name: '',
        group_name: '',
        role_id: '',
        status: 'all',
        scope: ''
      }
      this.roleFilterSelection = []
      this.page = 1
      this.load()
    },
    filterStatus(status) {
      if (this.filters.status === status) {
        this.filters.status = 'all'
      } else {
        this.filters.status = status
      }
      this.query()
    },
    clearRoleFilter() {
      this.filters.group_name = ''
      this.filters.role_id = ''
      this.roleFilterSelection = []
      this.query()
    },
    handleSizeChange(size) {
      this.pageSize = size
      this.page = 1
      this.load()
    },
    handlePageChange(page) {
      this.page = page
      this.load()
    },
    async selectUser(row) {
      this.selected = null
      this.detailVisible = true
      this.detailLoading = true
      try {
        const { data } = await getAdmin(row.id)
        if (this.detailVisible) this.selected = data
      } catch (error) {
        this.$message.error(error.userMessage || '管理员详情加载失败')
        this.detailVisible = false
      } finally {
        this.detailLoading = false
      }
    },
    openCreate() {
      this.resetForm()
      this.formCommand = newSystemCommand()
      this.formVisible = true
    },
    async openEdit(row) {
      try {
        const { data } = await getAdmin(row.id)
        this.resetForm()
        this.form = {
          ...emptyAdmin(),
          ...data,
          status: this.activeAccount(data) ? 'normal' : 'hidden',
          expected_version: data.business_version,
          password: '',
          password_confirmation: ''
        }
        this.manualRoles = (data.role_assignments || []).filter(role => role.is_manual).map(role => ({ ...role }))
        this.inheritedRoles = (data.role_assignments || []).filter(role => (role.sources || []).some(source => source !== 'manual'))
        this.formCommand = newSystemCommand()
        this.detailVisible = false
        this.formVisible = true
      } catch (error) {
        this.$message.error(error.userMessage || '管理员资料加载失败')
      }
    },
    resetForm() {
      this.form = emptyAdmin()
      this.manualRoles = []
      this.inheritedRoles = []
      this.formCommand = ''
      this.formCommandState = null
      this.$nextTick(() => this.$refs.adminForm && this.$refs.adminForm.clearValidate())
    },
    openRoleFilter() {
      this.rolePickerMode = 'filter'
      this.rolePickerVisible = true
    },
    openFormRoles() {
      this.rolePickerMode = 'form'
      this.rolePickerVisible = true
    },
    rolesPicked(rows) {
      if (this.rolePickerMode === 'filter') {
        this.roleFilterSelection = rows
        this.filters.group_name = rows[0] ? rows[0].name : ''
        this.filters.role_id = rows[0] ? rows[0].id : ''
        this.query()
      } else {
        this.manualRoles = rows
        this.form.manual_role_ids = rows.map(row => row.id)
        this.formCommand = newSystemCommand()
      }
    },
    removeManualRole(id) {
      this.manualRoles = this.manualRoles.filter(role => role.id !== id)
      this.formCommand = newSystemCommand()
    },
    sourceText(sources) {
      return (sources || [])
        .filter(source => source !== 'manual')
        .map(source => ({ sso: '单点登录', department: '部门负责人' })[source] || '系统')
        .join('、')
    },
    async submitForm() {
      if (this.saving) return
      if (!(await this.$refs.adminForm.validate().catch(() => false))) return
      this.saving = true
      try {
        const payload = { ...this.form, manual_role_ids: this.manualRoles.map(role => role.id) }
        this.formCommandState = systemFormCommand(this.formCommandState, payload, this.formCommand)
        await saveAdmin({ ...payload, client_command_id: this.formCommandState.id })
        this.$message.success('管理员已保存')
        this.formVisible = false
        await this.load()
      } catch (error) {
        this.$message.error(error.userMessage || '管理员保存失败')
        if (error.response && error.response.status !== 0) {
          this.formCommand = newSystemCommand()
          this.formCommandState = null
        }
      } finally {
        this.saving = false
      }
    },
    activeAccount(row) {
      return ['normal', 'active'].includes(row.status)
    },
    protectedAccount(row) {
      const profile = JSON.parse(localStorage.getItem('erp_me') || '{}')
      const user = profile.user || JSON.parse(localStorage.getItem('erp_user') || '{}')
      return row.username === 'admin' || Number(row.id) === Number(user.legacy_id || user.id)
    },
    async toggleStatus(row) {
      const status = this.activeAccount(row) ? 'hidden' : 'normal'
      try {
        await this.$confirm(`确定${status === 'hidden' ? '停用' : '启用'}管理员“${this.displayName(row)}”？`, '确认操作', { type: 'warning' })
      } catch (_) {
        return
      }
      this.busyId = row.id
      try {
        await setAdminStatus(row.id, { ...systemMutation(row), status })
        this.$message.success('管理员状态已更新')
        await this.load()
      } catch (error) {
        this.$message.error(error.userMessage || '状态更新失败')
      } finally {
        this.busyId = null
      }
    },
    async removeAdmin(row) {
      try {
        await this.$confirm(`确定删除管理员“${this.displayName(row)}”？删除后不能登录，历史业务记录保留。`, '删除管理员', { type: 'warning', confirmButtonText: '删除' })
      } catch (_) {
        return
      }
      this.busyId = row.id
      try {
        await deleteAdmin(row.id, systemMutation(row))
        this.$message.success('管理员已删除')
        this.detailVisible = false
        await this.load()
      } catch (error) {
        this.$message.error(error.userMessage || '管理员删除失败')
      } finally {
        this.busyId = null
      }
    },
    displayName(row) {
      return row.nickname || row.username || `用户${row.id}`
    },
    parseJson(value) {
      try {
        return Array.isArray(value) ? value : JSON.parse(value || '[]')
      } catch (e) {
        return []
      }
    },
    firstJsonLabel(value) {
      return this.parseJson(value)[0] || '-'
    },
    joinJsonLabels(value) {
      return this.parseJson(value).join('、')
    },
    primaryRole(row) {
      const roles = row.rbac_roles || []
      const primary = roles.find(role => role.code === 'admin' && role.enabled !== false) || roles.find(role => role.enabled !== false) || roles[0]
      return primary ? primary.name + (primary.enabled === false ? '（停用）' : '') : '未配置角色'
    },
    userRoles(row) {
      return (row.role_assignments || row.rbac_roles || []).map(role => role.name).filter(Boolean)
    },
    userScope(row) {
      return row.data_scope || 'self'
    },
    isPrincipal(row) {
      return !!row.is_department_principal
    },
    departmentIdentity(row) {
      const departments = this.joinJsonLabels(row.department_names)
      if (departments.includes('销售')) return '销售人员'
      if (departments.includes('生产') || departments.includes('车间')) return '生产人员'
      if (departments.includes('仓储') || departments.includes('仓库')) return '仓储人员'
      if (departments.includes('采购')) return '采购人员'
      return '部门成员'
    },
    identityType(row) {
      const identity = this.departmentIdentity(row)
      return ({ 销售人员: 'success', 生产人员: 'primary', 仓储人员: 'warning', 采购人员: 'primary' })[identity] || 'info'
    },
    scopeText(scope) {
      return ({ all: '全部数据', department: '本部门数据', self: '仅本人数据' })[scope] || '仅本人数据'
    },
    scopeType(scope) {
      return ({ all: 'success', department: 'primary', self: 'warning' })[scope] || 'info'
    },
    avatarColor(row) {
      const colors = ['#008b4b', '#059669', '#0d9488', '#2563eb', '#7c3aed']
      return colors[(Number(row && row.id) || 0) % colors.length]
    }
  }
}
</script>

<style scoped>
.admin-page-container {
  padding: 16px 20px;
  background: #f8fafc;
  min-height: calc(100vh - 52px);
  box-sizing: border-box;
}

.admin-workspace {
  max-width: 100%;
}

/* 页面头部规范 */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.head-icon {
  width: 40px;
  height: 40px;
  border-radius: 8px;
  background: #f0fdf4;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
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
  color: #0f172a;
}

.total-tag {
  border-radius: 4px;
  font-weight: 500;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-refresh {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-refresh:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
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

/* 全局统一页面提示条 */
.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #166534;
  font-size: 13px;
  line-height: 1.5;
  margin-bottom: 14px;
}

.erp-page-tip i {
  color: #008b4b;
  font-size: 16px;
  flex-shrink: 0;
}

/* 顶部概览统计指标卡片 */
.metric-overview-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 16px;
}

.metric-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
  user-select: none;
}

.metric-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
  border-color: #cbd5e1;
}

.metric-card.active {
  border-color: #008b4b;
  background: #fcfdfd;
  box-shadow: 0 2px 8px rgba(0, 139, 75, 0.12);
}

.metric-icon-box {
  width: 42px;
  height: 42px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  font-size: 20px;
  flex-shrink: 0;
}

.metric-all .metric-icon-box { background: #eaf7ef; color: #008b4b; }
.metric-enabled .metric-icon-box { background: #f0fdf4; color: #059669; }
.metric-disabled .metric-icon-box { background: #fef2f2; color: #ef4444; }
.metric-department .metric-icon-box { background: #f0fdfa; color: #0f766e; }

.metric-info {
  display: flex;
  flex-direction: column;
}

.metric-label {
  font-size: 12px;
  color: #64748b;
  margin-bottom: 2px;
}

.metric-val {
  font-size: 22px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

.text-success { color: #059669 !important; }
.text-danger { color: #ef4444 !important; }

/* 表格容器卡片 */
.table-container-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  overflow: hidden;
}

/* 筛选工具栏 */
.filter-toolbar {
  padding: 12px 16px;
  background: #ffffff;
  border-bottom: 1px solid #f1f5f9;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.filter-fields {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  flex: 1;
}

.filter-item {
  display: flex;
  align-items: center;
  gap: 5px;
}

.filter-label {
  font-size: 12px;
  color: #475569;
  font-weight: 500;
  white-space: nowrap;
}

.filter-input-search {
  width: 155px;
}

.filter-select-dept {
  width: 130px;
}

.filter-select-sm {
  width: 105px;
}

.role-filter-box {
  display: flex;
  align-items: center;
  gap: 3px;
}

.role-filter-input {
  width: 110px;
}

.clear-role-btn {
  color: #ef4444 !important;
  padding: 0 2px !important;
  font-size: 12px !important;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.btn-theme-search {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}

.btn-theme-search:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

.btn-theme-reset {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-theme-reset:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 表格主体 */
.table-wrap {
  width: 100%;
  overflow-x: auto;
}

.enterprise-table ::v-deep th {
  background: #f8fafc !important;
  color: #1e293b;
  font-weight: 600;
  font-size: 13px;
  padding: 10px 0;
}

.enterprise-table ::v-deep td {
  padding: 8px 0;
  font-size: 13px;
}

.enterprise-table ::v-deep .el-table__row:hover > td {
  background: #f8fafc !important;
}

.admin-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.avatar-dot {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  color: #ffffff;
  display: grid;
  place-items: center;
  font-size: 13px;
  font-weight: 700;
  flex-shrink: 0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.admin-cell-meta {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.admin-name {
  color: #0f172a;
  font-weight: 600;
  cursor: pointer;
  transition: color 0.15s;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.admin-name:hover {
  color: #008b4b;
}

.admin-subtext {
  color: #64748b;
  font-size: 11px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 135px;
}

.account-badge {
  display: inline-block;
  padding: 2px 6px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  font-size: 12px;
  color: #334155;
  font-weight: 500;
}

.dept-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #334155;
  font-size: 12px;
}

.dept-badge i {
  color: #64748b;
}

.role-tag {
  border-color: #cbd5e1;
  color: #334155;
}

.scope-tag,
.identity-tag,
.status-tag {
  border-radius: 4px;
  font-weight: 500;
}

.status-tag i {
  margin-right: 2px;
}

.code-mono {
  font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, Courier, monospace;
  font-variant-numeric: tabular-nums;
}

.text-muted {
  color: #94a3b8;
}

.text-xs {
  font-size: 12px;
}

.mb-12 {
  margin-bottom: 12px;
}

/* 操作栏按钮规范 */
.row-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 0 4px;
}

.row-actions .el-button--text {
  padding: 4px 4px !important;
  font-size: 12px !important;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 2px;
  margin-left: 0 !important;
}

.btn-action-view {
  color: #008b4b !important;
}

.btn-action-view:hover {
  color: #00763f !important;
}

.btn-action-edit {
  color: #0d9488 !important;
}

.btn-action-edit:hover {
  color: #0f766e !important;
}

.btn-action-enable {
  color: #008b4b !important;
}

.btn-action-enable:hover {
  color: #00763f !important;
}

.btn-action-disable {
  color: #d97706 !important;
}

.btn-action-disable:hover {
  color: #b45309 !important;
}

.btn-action-delete {
  color: #ef4444 !important;
}

.btn-action-delete:hover {
  color: #dc2626 !important;
}

/* 表格底部分页栏 */
.table-pagination-footer {
  padding: 12px 18px;
  background: #ffffff;
  border-top: 1px solid #f1f5f9;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.total-text {
  font-size: 13px;
  color: #64748b;
}

.total-text strong {
  color: #0f172a;
}

::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 600;
}

::v-deep .el-pagination.is-background .el-pager li:not(.disabled):hover {
  color: #008b4b !important;
}

/* 详情弹窗标准规范（遵照主数据中心 item-detail-modal） */
::v-deep .admin-detail-dialog .el-dialog,
::v-deep .admin-form-dialog .el-dialog {
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12);
}

::v-deep .admin-detail-dialog .el-dialog__header,
::v-deep .admin-form-dialog .el-dialog__header {
  padding: 16px 20px;
  border-bottom: 1px solid #f1f5f9;
  background: #ffffff;
}

::v-deep .admin-detail-dialog .el-dialog__title,
::v-deep .admin-form-dialog .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

::v-deep .admin-detail-dialog .el-dialog__body,
::v-deep .admin-form-dialog .el-dialog__body {
  padding: 18px 20px;
  background: #f8fafc;
  max-height: 74vh;
  overflow-y: auto;
}

.modal-summary-banner {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px;
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 14px;
}

.portrait {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  color: #ffffff;
  display: grid;
  place-items: center;
  font-size: 20px;
  font-weight: 700;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}

.banner-main {
  flex: 1;
  min-width: 0;
}

.banner-title-line {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 6px;
  flex-wrap: wrap;
}

.banner-title-line h2 {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
}

.banner-sub-meta {
  display: flex;
  align-items: center;
  gap: 16px;
  font-size: 12px;
  color: #64748b;
  flex-wrap: wrap;
}

.banner-sub-meta strong {
  color: #1e293b;
}

.detail-cards-stack {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.detail-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
}

.card-title-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.card-title-bar.space-between {
  justify-content: space-between;
}

.card-title-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.card-icon-badge {
  width: 26px;
  height: 26px;
  border-radius: 6px;
  background: #eaf7ef;
  color: #008b4b;
  display: grid;
  place-items: center;
  font-size: 14px;
  flex-shrink: 0;
}

.card-title-bar h3 {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px 18px;
  margin: 0;
}

.info-item {
  display: flex;
  align-items: baseline;
  font-size: 13px;
}

.info-item.full-width {
  grid-column: 1 / -1;
}

.info-label {
  width: 86px;
  color: #64748b;
  flex-shrink: 0;
}

.info-val {
  color: #0f172a;
  word-break: break-all;
}

.tag-list {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.role-tag-item {
  background: #f8fafc;
  border-color: #cbd5e1;
  color: #334155;
}

.metric-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}

.metric-block {
  height: 56px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: #f8fafc;
  border: 1px solid #edf2f7;
  border-radius: 6px;
}

.metric-block b {
  font-size: 18px;
  color: #008b4b;
}

.metric-block small {
  font-size: 11px;
  color: #64748b;
}

.scope-list {
  margin: 0;
  padding-left: 18px;
  color: #475569;
  line-height: 1.8;
  font-size: 13px;
}

.empty-panel {
  padding: 40px 0;
  text-align: center;
  color: #94a3b8;
  font-size: 13px;
}

/* 新增/编辑表单布局 */
.admin-edit-form {
  margin-top: 4px;
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0 16px;
}

.form-grid .full-width {
  grid-column: 1 / -1;
}

.full-input-number {
  width: 100%;
}

.full-width-cascader {
  width: 100%;
}

.record-tags-box {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  min-height: 36px;
  padding: 6px 10px;
  background: #ffffff;
  border: 1px solid #dcdfe6;
  border-radius: 4px;
}

.role-picker-tag {
  background: #f0fdf4;
  border-color: #bbf7d0;
  color: #166534;
}

.btn-add-role {
  border-color: #cbd5e1 !important;
  color: #008b4b !important;
  background: #f0fdf4 !important;
}

.btn-add-role:hover {
  background: #dcfce7 !important;
  border-color: #86efac !important;
}

.field-note {
  font-size: 11px;
  color: #94a3b8;
  line-height: 1.4;
  margin-top: 3px;
}

.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

.btn-dialog-cancel {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-dialog-cancel:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 响应式媒体查询适配（遵循 2026-09-08 强制响应式规则） */
@media (max-width: 1200px) {
  .metric-overview-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 900px) {
  .admin-page-container {
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

  .filter-fields {
    flex-direction: column;
    align-items: stretch;
  }

  .filter-item {
    width: 100%;
    justify-content: space-between;
  }

  .filter-input-search,
  .filter-select-dept,
  .filter-select-sm,
  .role-filter-box,
  .role-filter-input {
    width: 100% !important;
    flex: 1;
  }

  .filter-actions {
    width: 100%;
    justify-content: flex-end;
  }

  .form-grid {
    grid-template-columns: 1fr;
  }

  .info-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 600px) {
  .admin-page-container {
    padding: 8px;
  }

  .metric-overview-grid {
    grid-template-columns: 1fr;
  }

  .modal-summary-banner {
    flex-direction: column;
    align-items: flex-start;
  }

  .portrait {
    width: 44px;
    height: 44px;
    font-size: 16px;
  }

  .metric-row {
    grid-template-columns: 1fr;
  }

  .table-pagination-footer ::v-deep .el-pagination__sizes,
  .table-pagination-footer ::v-deep .el-pagination__jump {
    display: none;
  }
}
</style>
