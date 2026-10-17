<template>
  <div class="system-department-page" v-loading="loading">
    <!-- 页面全局头部卡片：白底圆角独立卡片 (100% 对齐主数据中心 / SkuList.vue 风格) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-office-building" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">部门管理</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag total-tag">
              共 {{ departments.length }} 个组织部门
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="load">刷新</el-button>
        <el-button
          v-if="$can('system.department.save')"
          size="small"
          type="success"
          icon="el-icon-plus"
          class="btn-theme-create"
          @click="openDepartmentForm()"
        >
          新增部门
        </el-button>
      </div>
    </header>

    <!-- 全局统一页面业务提示条 -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>维护企业多级组织架构树、部门负责人与所属成员。部门作为业务数据范围（按部门隔离）与审批流归属的核心载体。</span>
    </div>

    <!-- 顶部概览指标卡片 (100% 对齐主数据中心 / SkuList.vue 风格) -->
    <section class="metric-overview-grid">
      <div class="metric-card metric-all">
        <div class="metric-icon-box"><i class="el-icon-office-building" /></div>
        <div class="metric-info">
          <span class="metric-label">全部组织部门</span>
          <strong class="metric-val code-mono">{{ departments.length }}</strong>
        </div>
      </div>

      <div class="metric-card metric-enabled">
        <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
        <div class="metric-info">
          <span class="metric-label">正常启用部门</span>
          <strong class="metric-val code-mono">{{ enabledDeptCount }}</strong>
        </div>
      </div>

      <div class="metric-card metric-disabled">
        <div class="metric-icon-box"><i class="el-icon-circle-close" /></div>
        <div class="metric-info">
          <span class="metric-label">已停用部门</span>
          <strong class="metric-val code-mono">{{ disabledDeptCount }}</strong>
        </div>
      </div>

      <div class="metric-card metric-members">
        <div class="metric-icon-box"><i class="el-icon-user" /></div>
        <div class="metric-info">
          <span class="metric-label">在册员工总数</span>
          <strong class="metric-val code-mono">{{ totalMemberCount }} <span class="metric-sub-unit">人</span></strong>
        </div>
      </div>
    </section>

    <!-- 部门工作台：左侧组织架构树 + 右侧选中部门详情 -->
    <div class="dept-workbench">
      <!-- 左侧组织架构树卡片 -->
      <aside class="dept-tree-panel">
        <div class="panel-header">
          <h2><i class="el-icon-share" /> 组织架构树</h2>
          <span class="dept-count-badge">{{ filteredTree.length }} 节点</span>
        </div>
        <div class="panel-search-box">
          <el-input
            v-model="keyword"
            size="small"
            prefix-icon="el-icon-search"
            clearable
            placeholder="搜索部门名称..."
          />
        </div>
        <div class="dept-tree">
          <div
            v-for="node in filteredTree"
            :key="node.legacy_id"
            class="dept-node"
            :class="{ active: current && current.legacy_id === node.legacy_id }"
            :style="{ paddingLeft: (12 + Math.min(node.depth, 4) * 16) + 'px' }"
            @click="openDepartment(node)"
          >
            <i :class="node.parent_legacy_id ? 'el-icon-folder' : 'el-icon-office-building'" class="node-type-icon" />
            <span class="node-name" :title="node.name">{{ node.name }}</span>
            <span class="node-status" :class="{ disabled: node.status !== 'normal' }">
              {{ node.status === 'normal' ? '启用' : '停用' }}
            </span>
            <span class="node-members" title="部门成员数量">
              <i class="el-icon-user" /> {{ node.member_count || memberCountMap[node.legacy_id] || 0 }}
            </span>
          </div>
        </div>
        <div class="tree-legend">
          <span><b class="legend-dot green" />启用</span>
          <span><b class="legend-dot orange" />停用</span>
        </div>
      </aside>

      <!-- 右侧部门详情主面板 -->
      <main v-if="current" class="dept-detail">
        <div class="detail-header-card">
          <div class="detail-title-row">
            <div class="detail-name-wrap">
              <h2>{{ current.name }}</h2>
              <span class="dept-path-tag">
                <i class="el-icon-location-outline" /> {{ current.parent_legacy_id ? parentName(current) + ' / ' : '' }}{{ current.name }}
              </span>
            </div>
            <div class="dept-actionbar">
              <el-button v-if="$can('system.department.save')" size="small" icon="el-icon-edit" class="btn-default" @click="openDepartmentForm(current)">修改部门</el-button>
              <el-button v-if="$can('system.department.save')" size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openDepartmentForm(null, current.legacy_id)">新增下级部门</el-button>
              <el-button v-if="$can('system.department.delete')" size="small" class="btn-danger-text" icon="el-icon-delete" :disabled="saving" @click="removeDepartment">删除部门</el-button>
            </div>
          </div>
        </div>

        <!-- 基本信息分块 -->
        <section class="detail-section base-info">
          <div class="section-title">
            <h3><i class="el-icon-document" /> 基本信息</h3>
          </div>
          <dl class="info-dl">
            <dt>部门名称：</dt><dd class="fw-bold">{{ current.name }}</dd>
            <dt>部门负责人：</dt><dd>{{ principalNames || '未设置' }}</dd>
            <dt>排序号：</dt><dd class="code-mono">{{ current.sort || 0 }}</dd>
            <dt>上级部门：</dt><dd>{{ parentName(current) }}</dd>
            <dt>部门成员：</dt><dd><span class="member-highlight">{{ current.member_count || 0 }}</span> 名在册员工</dd>
            <dt>状态：</dt><dd><el-switch :value="current.status === 'normal'" active-color="#008b4b" disabled /></dd>
            <dt>部门编号：</dt><dd class="code-mono">{{ current.legacy_id }}</dd>
            <dt>创建时间：</dt><dd class="time-text">{{ timeText(current.created_at) }}</dd>
            <dt>更新时间：</dt><dd class="time-text">{{ timeText(current.updated_at) }}</dd>
          </dl>
        </section>

        <!-- 部门负责人分块 -->
        <section class="detail-section principals-section">
          <div class="section-title">
            <div>
              <h3><i class="el-icon-s-custom" /> 部门负责人</h3>
              <small>按部门范围和角色权限访问业务与单据审批</small>
            </div>
            <el-button
              v-if="$can('system.department.set_principal')"
              size="small"
              icon="el-icon-edit"
              class="btn-default"
              :disabled="saving"
              @click="principalPickerVisible = true"
            >
              编辑负责人
            </el-button>
          </div>
          <div class="principal-tags-box">
            <el-tag v-for="user in principalUsers" :key="user.id" size="small" class="principal-tag" effect="plain">
              <i class="el-icon-user-solid" /> {{ displayName(user) }}
            </el-tag>
            <span v-if="!principalUsers.length" class="empty-text">未设置部门负责人（将影响下属单据按部门审批路由）</span>
          </div>
        </section>

        <!-- 部门成员分块 -->
        <section class="detail-section members-section">
          <div class="section-title">
            <div>
              <h3><i class="el-icon-user" /> 部门成员列表</h3>
              <small>普通成员按角色参与系统数据范围生效</small>
            </div>
            <span class="member-help-tip"><i class="el-icon-info" /> 成员归属由管理员档案维护</span>
          </div>

          <div class="member-filter-toolbar">
            <el-input
              v-model="memberKeyword"
              size="small"
              clearable
              prefix-icon="el-icon-search"
              placeholder="搜索成员姓名或账号..."
              class="member-search-input"
            />
            <el-select v-model="memberRole" size="small" clearable placeholder="全部角色" class="filter-select">
              <el-option label="销售人员" value="sales" />
              <el-option label="负责人" value="principal" />
              <el-option label="普通成员" value="normal" />
            </el-select>
            <el-select v-model="memberStatus" size="small" clearable placeholder="全部状态" class="filter-select">
              <el-option label="启用" value="normal" />
              <el-option label="停用" value="hidden" />
            </el-select>
          </div>

          <el-table v-loading="memberLoading" :data="pagedMembers" border size="small" class="dept-member-table erp-page-table">
            <el-table-column label="姓名" min-width="120">
              <template slot-scope="{ row }">
                <span class="member-name">{{ displayName(row) }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="username" label="登录账号" min-width="130">
              <template slot-scope="{ row }">
                <code class="code-mono">{{ row.username }}</code>
              </template>
            </el-table-column>
            <el-table-column label="组织身份" min-width="130">
              <template slot-scope="{ row }">
                <span v-if="row.is_principal" class="badge-tag badge-green">部门负责人</span>
                <span v-else class="badge-tag badge-dark">{{ rowIdentity(row) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="数据范围" min-width="120">
              <template slot-scope="{ row }">
                <span class="scope-text">{{ scopeText(row.data_scope) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="90" align="center">
              <template slot-scope="{ row }">
                <span class="status-pill" :class="row.status === 'normal' ? 'enabled' : 'disabled'">
                  {{ row.status === 'normal' ? '启用' : '停用' }}
                </span>
              </template>
            </el-table-column>
          </el-table>

          <div class="pager-row">
            <span class="pager-total">共 {{ memberTotal }} 条成员记录</span>
            <el-pagination
              background
              layout="sizes, prev, pager, next, jumper"
              :page-size.sync="pageSize"
              :current-page.sync="page"
              :page-sizes="[10, 20, 50, 100]"
              :total="memberTotal"
              @size-change="handleSizeChange"
              @current-change="handlePageChange"
            />
          </div>
        </section>
      </main>

      <!-- 未选择部门空状态 -->
      <div v-else class="empty-state dept-detail">
        <i class="el-icon-office-building empty-icon" />
        <p>暂无选中的部门信息，请点击左侧组织架构树选择或新增部门。</p>
      </div>
    </div>

    <!-- 部门编辑 / 新增弹窗 (严格遵循 2026-09-24 居中遮罩规范) -->
    <el-dialog
      :title="departmentForm.legacy_id ? '修改部门' : '新增部门'"
      :visible.sync="departmentFormVisible"
      append-to-body
      width="640px"
      top="6vh"
      custom-class="erp-themed-dialog system-management-dialog"
      :close-on-click-modal="false"
      :close-on-press-escape="!saving"
      :show-close="!saving"
      @closed="!departmentFormVisible && resetDepartmentForm()"
    >
      <div class="dialog-header-banner">
        <div class="dialog-banner-icon">
          <i :class="departmentForm.legacy_id ? 'el-icon-edit-outline' : 'el-icon-circle-plus-outline'" />
        </div>
        <div class="dialog-banner-text">
          <span class="dialog-banner-title">{{ departmentForm.legacy_id ? '修改部门信息' : '创建新组织部门' }}</span>
          <span class="dialog-banner-desc">维护企业多级组织架构树节点，配置所属上级与启用状态。</span>
        </div>
      </div>

      <el-form ref="departmentForm" :model="departmentForm" :rules="departmentRules" label-position="top" size="small" class="dialog-form">
        <div class="form-grid">
          <el-form-item label="部门名称" prop="name">
            <el-input v-model.trim="departmentForm.name" maxlength="120" placeholder="请输入部门名称，例如：研发一部" />
          </el-form-item>
          <el-form-item label="上级部门" prop="parent_legacy_id">
            <el-cascader
              v-model="departmentForm.parent_legacy_id"
              :options="parentOptions"
              :props="{ checkStrictly: true, emitPath: false }"
              placeholder="选择上级部门"
              style="width: 100%;"
            />
          </el-form-item>
          <el-form-item label="启用状态">
            <el-radio-group v-model="departmentForm.status">
              <el-radio label="normal">正常启用</el-radio>
              <el-radio label="hidden">停用</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="显示排序号">
            <el-input-number v-model="departmentForm.sort" controls-position="right" :min="-999999" :max="999999" style="width: 100%;" />
          </el-form-item>
        </div>
      </el-form>
      <span slot="footer" class="dialog-footer">
        <el-button size="small" class="btn-default" :disabled="saving" @click="departmentFormVisible = false">取消</el-button>
        <el-button size="small" type="success" class="btn-theme-submit" :loading="saving" @click="submitDepartmentForm">保存部门</el-button>
      </span>
    </el-dialog>

    <system-record-picker
      :visible.sync="principalPickerVisible"
      type="users"
      :department-id="current ? Number(current.legacy_id) : 0"
      :value="principalUsers"
      @confirm="savePrincipals"
    />
  </div>
</template>

<script>
import { listDepartments, listDepartmentMembers, saveDepartmentPrincipals, saveDepartment, deleteDepartment } from '@/api/erp/rbac'
import SystemRecordPicker from '@/components/system/SystemRecordPicker.vue'
import { departmentTree, flatDepartmentTree, newSystemCommand, systemMutation, systemTime, systemFormCommand } from '@/utils/systemManagement'
import '@/styles/system-management.css'

const emptyDepartment = () => ({ legacy_id: null, name: '', parent_legacy_id: 0, status: 'normal', sort: 0 })

export default {
  name: 'SystemDepartmentManagement',
  components: { SystemRecordPicker },
  data: () => ({
    departments: [],
    members: [],
    principalUsers: [],
    memberCountMap: {},
    current: null,
    keyword: '',
    memberKeyword: '',
    memberRole: '',
    memberStatus: '',
    principalIds: [],
    editPrincipal: false,
    page: 1,
    pageSize: 10,
    memberTotal: 0,
    filterTimer: null,
    loading: false,
    memberLoading: false,
    saving: false,
    loadSerial: 0,
    memberSerial: 0,
    departmentFormVisible: false,
    departmentForm: emptyDepartment(),
    formCommand: '',
    formCommandState: null,
    principalPickerVisible: false,
    departmentRules: {
      name: [{ required: true, message: '请输入部门名称', trigger: 'blur' }],
      parent_legacy_id: [{ required: true, message: '请选择上级部门', trigger: 'change' }]
    }
  }),
  computed: {
    filteredTree() {
      const keyword = this.keyword.trim()
      return flatDepartmentTree(this.departments).filter(item => !keyword || item.name.includes(keyword))
    },
    parentOptions() {
      return [{ value: 0, label: '无上级部门' }, ...departmentTree(this.departments, this.departmentForm.legacy_id)]
    },
    pagedMembers() {
      return this.members
    },
    principalNames() {
      return this.principalUsers.map(this.displayName).join('、')
    },
    enabledDeptCount() {
      return this.departments.filter(item => item.status === 'normal').length
    },
    disabledDeptCount() {
      return this.departments.filter(item => item.status !== 'normal').length
    },
    totalMemberCount() {
      return Object.values(this.memberCountMap).reduce((sum, count) => sum + (Number(count) || 0), 0)
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
    memberKeyword() {
      this.scheduleMemberReload()
    },
    memberRole() {
      this.scheduleMemberReload()
    },
    memberStatus() {
      this.scheduleMemberReload()
    }
  },
  methods: {
    async load() {
      const serial = ++this.loadSerial
      this.loading = true
      try {
        const { data } = await listDepartments({ tree: 1 })
        if (serial !== this.loadSerial) return
        this.departments = data || []
        this.memberCountMap = this.departments.reduce((map, dept) => ({ ...map, [dept.legacy_id]: dept.member_count || 0 }), {})
        await this.selectDepartment(this.current ? data.find(item => item.legacy_id === this.current.legacy_id) || data[0] : data[0])
      } catch (error) {
        if (serial !== this.loadSerial) return
        this.departments = []
        await this.selectDepartment(null)
        this.$message.error(error.userMessage || '部门列表加载失败')
      } finally {
        if (serial === this.loadSerial) this.loading = false
      }
    },
    async selectDepartment(dept) {
      const serial = ++this.memberSerial
      this.current = dept || null
      if (!dept) {
        this.members = []
        this.principalUsers = []
        this.principalIds = []
        this.memberTotal = 0
        this.memberLoading = false
        return
      }
      this.editPrincipal = false
      this.memberLoading = true
      try {
        const { data } = await listDepartmentMembers(dept.legacy_id, {
          page: this.page,
          per_page: this.pageSize,
          keyword: this.memberKeyword,
          role: this.memberRole,
          status: this.memberStatus
        })
        if (serial !== this.memberSerial) return
        this.members = (data.data || []).map(row => ({ ...row, is_principal: Boolean(row.is_principal) }))
        this.principalUsers = (data.principals || []).map(row => ({ ...row, is_principal: Boolean(row.is_principal) }))
        this.principalIds = this.principalUsers.map(row => row.id)
        this.memberTotal = data.meta ? data.meta.total : this.members.length
        if (!this.members.length && this.page > 1) {
          this.page -= 1
          return this.selectDepartment(dept)
        }
      } catch (error) {
        if (serial !== this.memberSerial) return
        this.members = []
        this.principalUsers = []
        this.memberTotal = 0
        this.$message.error(error.userMessage || '部门成员加载失败')
      } finally {
        if (serial === this.memberSerial) this.memberLoading = false
      }
    },
    openDepartment(dept) {
      this.page = 1
      this.selectDepartment(dept)
    },
    reloadMembers() {
      if (!this.current) return
      this.page = 1
      this.selectDepartment(this.current)
    },
    scheduleMemberReload() {
      if (this.filterTimer) clearTimeout(this.filterTimer)
      this.filterTimer = setTimeout(() => this.reloadMembers(), 250)
    },
    async savePrincipals(rows) {
      if (!this.current || this.saving) return
      this.saving = true
      try {
        await saveDepartmentPrincipals(this.current.legacy_id, rows.map(row => row.id), systemMutation(this.current))
        this.$message.success('部门负责人已保存')
        await this.load()
      } catch (error) {
        this.$message.error(error.userMessage || '负责人保存失败')
      } finally {
        this.saving = false
      }
    },
    resetDepartmentForm() {
      this.departmentForm = emptyDepartment()
      this.formCommand = ''
      this.formCommandState = null
      this.$nextTick(() => this.$refs.departmentForm && this.$refs.departmentForm.clearValidate())
    },
    openDepartmentForm(row, parent = 0) {
      this.resetDepartmentForm()
      this.departmentForm = row ? { ...emptyDepartment(), ...row } : { ...emptyDepartment(), parent_legacy_id: parent }
      this.formCommand = newSystemCommand()
      this.departmentFormVisible = true
    },
    async submitDepartmentForm() {
      if (this.saving || !(await this.$refs.departmentForm.validate().catch(() => false))) return
      this.saving = true
      try {
        const payload = {
          ...this.departmentForm,
          ...(this.departmentForm.legacy_id ? { expected_version: this.departmentForm.business_version } : {})
        }
        this.formCommandState = systemFormCommand(this.formCommandState, payload, this.formCommand)
        const { data } = await saveDepartment({ ...payload, client_command_id: this.formCommandState.id })
        this.current = data
        this.page = 1
        this.$message.success('部门已保存')
        this.departmentFormVisible = false
        await this.load()
      } catch (error) {
        this.$message.error(error.userMessage || '部门保存失败')
        if (error.response && error.response.status !== 0) {
          this.formCommand = newSystemCommand()
          this.formCommandState = null
        }
      } finally {
        this.saving = false
      }
    },
    async removeDepartment() {
      if (this.saving || !this.current) return
      const row = { ...this.current }
      try {
        await this.$confirm(`确定删除部门“${row.name}”？仍有下级、成员或审批引用的部门不能删除。`, '删除部门', {
          type: 'warning',
          confirmButtonText: '删除',
          confirmButtonClass: 'el-button--danger'
        })
      } catch (_) {
        return
      }
      this.saving = true
      try {
        await deleteDepartment(row.legacy_id, systemMutation(row))
        this.$message.success('部门已删除')
        this.current = null
        this.page = 1
        await this.load()
      } catch (error) {
        this.$message.error(error.userMessage || '部门删除失败')
      } finally {
        this.saving = false
      }
    },
    timeText: systemTime,
    scopeText(value) {
      return ({ all: '全部数据', department: '本部门数据', self: '本人数据' })[value] || '本人数据'
    },
    handleSizeChange(size) {
      this.pageSize = size
      this.page = 1
      this.selectDepartment(this.current)
    },
    handlePageChange(page) {
      this.page = page
      this.selectDepartment(this.current)
    },
    rowIdentity() {
      const deptName = this.current ? this.current.name : ''
      if (deptName.includes('销售')) return '销售人员'
      if (deptName.includes('生产') || deptName.includes('车间')) return '生产人员'
      if (deptName.includes('仓储') || deptName.includes('仓库')) return '仓储人员'
      if (deptName.includes('采购')) return '采购人员'
      return '部门成员'
    },
    displayName(row) {
      return row.nickname || row.username || `用户${row.id}`
    },
    parentName(row) {
      if (!row.parent_legacy_id) return '无上级部门'
      const parent = this.departments.find(item => item.legacy_id === row.parent_legacy_id)
      return parent ? parent.name : '上级部门不存在'
    }
  }
}
</script>

<style scoped>
.system-department-page {
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
  transition: all 0.2s ease;
  user-select: none;
}
.metric-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
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
.metric-enabled .metric-icon-box { background: #e8f5e9; color: #008b4b; }
.metric-disabled .metric-icon-box { background: #fef2f2; color: #ef4444; }
.metric-members .metric-icon-box { background: #e0f2fe; color: #0284c7; }

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
.metric-sub-unit {
  font-size: 13px;
  font-weight: 500;
  color: #64748b;
}
.code-mono {
  font-family: Consolas, Monaco, "Courier New", monospace;
}

/* 部门工作台主容器 */
.dept-workbench {
  display: grid;
  grid-template-columns: 320px minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}
.dept-tree-panel,
.dept-detail {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

/* 左侧组织架构树 */
.dept-tree-panel {
  min-height: 760px;
  padding: 16px;
  display: flex;
  flex-direction: column;
}
.panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}
.panel-header h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 6px;
}
.panel-header h2 i {
  color: #008b4b;
}
.dept-count-badge {
  font-size: 12px;
  color: #64748b;
  background: #f1f5f9;
  padding: 2px 6px;
  border-radius: 4px;
}
.panel-search-box {
  margin-bottom: 12px;
}
.dept-tree {
  flex: 1;
  overflow-y: auto;
  max-height: 640px;
  border-top: 1px solid #f1f5f9;
  padding-top: 8px;
}
.dept-node {
  min-height: 40px;
  display: grid;
  grid-template-columns: 20px minmax(0, 1fr) 36px 44px;
  align-items: center;
  gap: 8px;
  padding: 4px 10px;
  border-radius: 6px;
  cursor: pointer;
  color: #334155;
  transition: all 0.15s ease;
  margin-bottom: 2px;
}
.dept-node:hover {
  background: #f0fdf4;
}
.dept-node.active {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  font-weight: 600;
}
.node-type-icon {
  color: #008b4b;
  font-size: 16px;
}
.node-name {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 13px;
}
.node-status {
  font-size: 11px;
  padding: 1px 4px;
  border-radius: 3px;
  text-align: center;
  color: #008b4b;
  background: #dcfce7;
}
.node-status.disabled {
  color: #94a3b8;
  background: #f1f5f9;
}
.node-members {
  font-size: 12px;
  color: #64748b;
  text-align: right;
  display: flex;
  align-items: center;
  gap: 2px;
  justify-content: flex-end;
}
.node-members i {
  font-size: 12px;
}
.tree-legend {
  display: flex;
  gap: 16px;
  color: #64748b;
  font-size: 12px;
  padding-top: 12px;
  border-top: 1px solid #f1f5f9;
}
.legend-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-right: 6px;
}
.legend-dot.green { background: #008b4b; }
.legend-dot.orange { background: #f59e0b; }

/* 右侧详情面板 */
.dept-detail {
  padding: 18px 20px 24px;
  min-width: 0;
}
.detail-header-card {
  padding-bottom: 16px;
  border-bottom: 1px solid #f1f5f9;
  margin-bottom: 16px;
}
.detail-title-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  flex-wrap: wrap;
}
.detail-name-wrap h2 {
  margin: 0 0 6px;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
}
.dept-path-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: #008b4b;
  background: #f0fdf4;
  padding: 2px 8px;
  border-radius: 4px;
  border: 1px solid #bbf7d0;
}
.dept-actionbar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.btn-danger-text {
  color: #ef4444 !important;
  background: #fff !important;
  border: 1px solid #fecaca !important;
}
.btn-danger-text:hover {
  background: #fef2f2 !important;
  border-color: #ef4444 !important;
}

/* 分块通用样式 */
.detail-section {
  padding: 16px 0;
  border-bottom: 1px solid #f1f5f9;
}
.detail-section:last-of-type {
  border-bottom: none;
}
.section-title {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
  gap: 12px;
  flex-wrap: wrap;
}
.section-title h3 {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 6px;
}
.section-title h3 i {
  color: #008b4b;
}
.section-title small {
  font-weight: 400;
  color: #64748b;
  margin-left: 6px;
  font-size: 12px;
}

/* 基本信息 DL 网格 */
.info-dl {
  display: grid;
  grid-template-columns: 90px minmax(0, 1fr) 90px minmax(0, 1fr) 90px minmax(0, 1fr);
  gap: 12px 16px;
  margin: 0;
  font-size: 13px;
}
.info-dl dt {
  color: #64748b;
  text-align: right;
}
.info-dl dd {
  margin: 0;
  color: #1e293b;
  overflow-wrap: anywhere;
}
.fw-bold {
  font-weight: 600;
}
.member-highlight {
  font-weight: 700;
  color: #008b4b;
}
.time-text {
  color: #475569;
  font-size: 12px;
}

/* 部门负责人 */
.principal-tags-box {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  min-height: 32px;
}
.principal-tag {
  background: #f0fdf4 !important;
  border-color: #bbf7d0 !important;
  color: #008b4b !important;
  font-weight: 500;
}
.empty-text {
  color: #94a3b8;
  font-size: 13px;
}

/* 部门成员 */
.member-help-tip {
  font-size: 12px;
  color: #64748b;
}
.member-filter-toolbar {
  display: grid;
  grid-template-columns: 1fr 180px 180px;
  gap: 12px;
  margin-bottom: 12px;
}
.member-search-input {
  min-width: 0;
}
.filter-select {
  width: 100%;
}
.dept-member-table ::v-deep th {
  background: #f8fafc !important;
  color: #334155 !important;
  font-weight: 600;
  font-size: 13px;
}
.member-name {
  font-weight: 600;
  color: #1e293b;
}
.scope-text {
  color: #475569;
  font-size: 12px;
}
.badge-tag {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 3px;
  font-size: 11px;
}
.badge-green {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}
.badge-dark {
  background: #f1f5f9;
  color: #475569;
  border: 1px solid #e2e8f0;
}
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
.pager-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 14px;
  flex-wrap: wrap;
  gap: 12px;
}
.pager-total {
  font-size: 13px;
  color: #64748b;
}

/* 空状态卡片 */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 20px;
  color: #94a3b8;
  text-align: center;
}
.empty-icon {
  font-size: 48px;
  color: #cbd5e1;
  margin-bottom: 12px;
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
.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 18px;
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
  .dept-workbench {
    grid-template-columns: 1fr;
  }
  .dept-tree-panel {
    min-height: 0;
  }
  .dept-tree {
    max-height: 320px;
  }
  .info-dl {
    grid-template-columns: 90px minmax(0, 1fr) 90px minmax(0, 1fr);
  }
}
@media (max-width: 768px) {
  .system-department-page {
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
  .member-filter-toolbar {
    grid-template-columns: 1fr;
  }
  .info-dl {
    grid-template-columns: 90px minmax(0, 1fr);
  }
  .form-grid {
    grid-template-columns: 1fr;
  }
}
</style>
