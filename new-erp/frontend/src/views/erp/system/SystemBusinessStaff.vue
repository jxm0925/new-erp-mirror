<template>
  <section class="staff-page-container system-frame">
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-s-custom" /></span>
        <div class="title-row">
          <h1 class="page-title">业务员档案</h1>
          <el-tag size="small" type="success" effect="plain">共 {{ total }} 条档案</el-tag>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" :loading="loading" @click="load">刷新</el-button>
        <el-button v-if="$can('system.business_staff.save')" size="small" type="success" icon="el-icon-plus" @click="openForm()">登记业务员</el-button>
      </div>
    </header>

    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>业务员档案决定账号的业务身份：订单销售人员、工单负责人、工序工人与协同人、仓库负责人都从对应有效档案中选择；有效审核员、财务负责人可在审核工作台处理分配给自己的任务。离职或调岗请停用或设置失效日期，历史单据与审核记录保留追溯。</span>
    </div>

    <section class="metric-overview-grid">
      <div
        v-for="item in summary"
        :key="item.staff_type"
        class="metric-card"
        :class="{ active: filters.staff_type === item.staff_type }"
        :title="`点击筛选${item.label}`"
        @click="filterType(item.staff_type)"
      >
        <div class="metric-icon-box"><i :class="typeIcon(item.staff_type)" /></div>
        <div class="metric-info">
          <span class="metric-label">{{ item.label }}（启用）</span>
          <strong class="metric-val">{{ item.enabled }}</strong>
          <span v-if="item.disabled" class="metric-sub">已停用 {{ item.disabled }}</span>
        </div>
      </div>
    </section>

    <section class="table-container-card">
      <div class="filter-toolbar">
        <div class="filter-fields">
          <div class="filter-item">
            <span class="filter-label">关键字</span>
            <el-input v-model.trim="filters.keyword" size="small" clearable placeholder="姓名 / 账号 / 手机" class="filter-input" @keyup.enter.native="query" />
          </div>
          <div class="filter-item">
            <span class="filter-label">类型</span>
            <el-select v-model="filters.staff_type" size="small" clearable placeholder="全部类型" class="filter-select">
              <el-option v-for="item in options.types" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
          </div>
          <div class="filter-item">
            <span class="filter-label">所属部门</span>
            <el-select v-model="filters.department_id" size="small" clearable filterable placeholder="全部部门" class="filter-select">
              <el-option v-for="item in options.departments" :key="item.id" :label="item.name" :value="item.id" />
            </el-select>
          </div>
          <div class="filter-item">
            <span class="filter-label">状态</span>
            <el-select v-model="filters.status" size="small" clearable placeholder="全部状态" class="filter-select">
              <el-option label="启用" value="enabled" />
              <el-option label="停用" value="disabled" />
            </el-select>
          </div>
        </div>
        <div class="filter-actions">
          <el-button size="small" type="success" icon="el-icon-search" @click="query">查询</el-button>
          <el-button size="small" icon="el-icon-refresh-left" @click="reset">重置</el-button>
        </div>
      </div>

      <div class="table-wrap">
        <el-table v-loading="loading" :data="rows" border size="small" empty-text="暂无业务员档案">
          <el-table-column label="员工" min-width="150">
            <template slot-scope="{ row }">
              <div class="user-cell">
                <strong>{{ row.nickname || row.username || `账号 ${row.user_legacy_id}` }}</strong>
                <span>{{ row.username }}<template v-if="row.mobile"> · {{ row.mobile }}</template></span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="类型" width="100">
            <template slot-scope="{ row }"><el-tag size="mini" effect="plain">{{ row.staff_type_label }}</el-tag></template>
          </el-table-column>
          <el-table-column label="所属部门" min-width="120">
            <template slot-scope="{ row }">{{ row.department_name || '—' }}</template>
          </el-table-column>
          <el-table-column label="生效期间" min-width="180">
            <template slot-scope="{ row }">{{ row.effective_from || '不限' }} 至 {{ row.effective_to || '长期' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="150">
            <template slot-scope="{ row }">
              <el-tag v-if="row.is_effective" size="mini" type="success">有效</el-tag>
              <el-tooltip v-else :content="row.inactive_reason || '无效'" placement="top">
                <el-tag size="mini" type="info">{{ row.inactive_reason || '无效' }}</el-tag>
              </el-tooltip>
            </template>
          </el-table-column>
          <el-table-column label="来源" width="100">
            <template slot-scope="{ row }">{{ sourceLabel(row.source) }}</template>
          </el-table-column>
          <el-table-column label="备注" min-width="140" show-overflow-tooltip>
            <template slot-scope="{ row }">{{ row.remark || '—' }}</template>
          </el-table-column>
          <el-table-column label="操作" width="160" fixed="right" align="center">
            <template slot-scope="{ row }">
              <el-button v-if="$can('system.business_staff.save')" type="text" size="mini" @click="openForm(row)">编辑</el-button>
              <el-button v-if="$can('system.business_staff.save')" type="text" size="mini" @click="toggleStatus(row)">{{ row.status === 'enabled' ? '停用' : '启用' }}</el-button>
              <el-button v-if="$can('system.business_staff.delete')" type="text" size="mini" class="danger-link" @click="remove(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <footer class="table-pagination-footer">
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :current-page="page"
          :page-size="pageSize"
          :page-sizes="[10, 20, 50, 100]"
          :total="total"
          @size-change="size => { pageSize = size; page = 1; load() }"
          @current-change="current => { page = current; load() }"
        />
      </footer>
    </section>

    <el-dialog
      :title="form.id ? '编辑业务员档案' : '登记业务员'"
      :visible.sync="formVisible"
      append-to-body
      width="640px"
      custom-class="system-management-dialog"
      :close-on-click-modal="false"
    >
      <el-form ref="form" :model="form" :rules="rules" label-position="top" size="small" class="form-grid">
        <el-form-item label="员工账号" prop="user_legacy_id">
          <el-select
            v-model="form.user_legacy_id"
            filterable
            remote
            :remote-method="searchUsers"
            :loading="userLoading"
            :disabled="!!form.id"
            placeholder="输入姓名 / 账号搜索"
            @change="applyDefaultDepartment"
          >
            <el-option v-for="item in userOptions" :key="item.id" :label="userLabel(item)" :value="item.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="业务员类型" prop="staff_type">
          <el-select v-model="form.staff_type" :disabled="!!form.id" placeholder="请选择类型">
            <el-option v-for="item in options.types" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="所属部门 / 业务组">
          <el-select v-model="form.department_legacy_id" clearable filterable placeholder="可不选">
            <el-option v-for="item in options.departments" :key="item.id" :label="item.name" :value="item.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio label="enabled">启用</el-radio>
            <el-radio label="disabled">停用</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="生效日期">
          <el-date-picker v-model="form.effective_from" type="date" value-format="yyyy-MM-dd" placeholder="不限" />
        </el-form-item>
        <el-form-item label="失效日期">
          <el-date-picker v-model="form.effective_to" type="date" value-format="yyyy-MM-dd" placeholder="长期有效" />
        </el-form-item>
        <el-form-item label="备注" class="full-width">
          <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="500" show-word-limit />
        </el-form-item>
      </el-form>
      <div slot="footer">
        <el-button size="small" @click="formVisible = false">取消</el-button>
        <el-button size="small" type="success" :loading="saving" @click="submit">保存</el-button>
      </div>
    </el-dialog>
  </section>
</template>

<script>
import { listBusinessStaff, getBusinessStaffOptions, saveBusinessStaff, deleteBusinessStaff } from '@/api/erp/rbac'
import '@/styles/system-management.css'

const emptyFilters = () => ({ keyword: '', staff_type: '', department_id: '', status: '' })
const emptyForm = () => ({ id: null, user_legacy_id: null, staff_type: 'sales', department_legacy_id: null, status: 'enabled', effective_from: null, effective_to: null, remark: '', expected_version: null })

export default {
  name: 'SystemBusinessStaff',
  data: () => ({
    loading: false,
    saving: false,
    userLoading: false,
    rows: [],
    total: 0,
    page: 1,
    pageSize: 10,
    summary: [],
    filters: emptyFilters(),
    options: { types: [], departments: [] },
    userOptions: [],
    formVisible: false,
    form: emptyForm(),
    rules: {
      user_legacy_id: [{ required: true, message: '请选择员工账号', trigger: 'change' }],
      staff_type: [{ required: true, message: '请选择业务员类型', trigger: 'change' }],
      status: [{ required: true, message: '请选择状态', trigger: 'change' }]
    }
  }),
  created() {
    this.loadOptions()
    this.load()
  },
  methods: {
    async loadOptions(keyword = '') {
      const { data } = await getBusinessStaffOptions({ keyword })
      this.options = { types: data.types || [], departments: data.departments || [] }
      this.userOptions = data.users || []
    },
    async load() {
      this.loading = true
      try {
        const { data } = await listBusinessStaff({ ...this.filters, page: this.page, per_page: this.pageSize })
        this.rows = data.data || []
        this.total = (data.meta && data.meta.total) || 0
        this.summary = data.summary || []
      } finally {
        this.loading = false
      }
    },
    query() { this.page = 1; this.load() },
    reset() { this.filters = emptyFilters(); this.query() },
    filterType(type) {
      this.filters.staff_type = this.filters.staff_type === type ? '' : type
      this.query()
    },
    typeIcon(type) {
      return { sales: 'el-icon-s-custom', production_manager: 'el-icon-s-management', production_worker: 'el-icon-s-tools', warehouse: 'el-icon-box', purchase: 'el-icon-shopping-cart-2', quality: 'el-icon-finished', reviewer: 'el-icon-circle-check', finance_manager: 'el-icon-s-check' }[type] || 'el-icon-user'
    },
    sourceLabel(source) {
      return { manual: '手工登记', sso: '统一身份同步', legacy_flag: '旧标记迁移' }[source] || source || '—'
    },
    userLabel(user) {
      const departments = Array.isArray(user.department_names) ? user.department_names : this.parseNames(user.department_names)
      return `${user.nickname || user.username}（${user.username}）${departments.length ? ' · ' + departments.join('、') : ''}`
    },
    parseNames(value) {
      try { return JSON.parse(value || '[]') || [] } catch (e) { return [] }
    },
    async searchUsers(keyword) {
      this.userLoading = true
      try {
        const { data } = await getBusinessStaffOptions({ keyword })
        this.userOptions = data.users || []
      } finally {
        this.userLoading = false
      }
    },
    applyDefaultDepartment(userId) {
      if (this.form.department_legacy_id) return
      const user = this.userOptions.find(item => item.id === userId)
      const name = user ? this.parseNames(user.department_names)[0] : null
      const department = name && this.options.departments.find(item => item.name === name)
      if (department) this.form.department_legacy_id = department.id
    },
    openForm(row) {
      this.form = row
        ? { ...emptyForm(), ...row, expected_version: row.business_version }
        : emptyForm()
      if (row && !this.userOptions.some(item => item.id === row.user_legacy_id)) {
        this.userOptions = [{ id: row.user_legacy_id, username: row.username, nickname: row.nickname, department_names: '[]' }, ...this.userOptions]
      }
      this.formVisible = true
      this.$nextTick(() => this.$refs.form && this.$refs.form.clearValidate())
    },
    payload(form) {
      const data = {
        department_legacy_id: form.department_legacy_id || null,
        status: form.status,
        effective_from: form.effective_from || null,
        effective_to: form.effective_to || null,
        remark: form.remark || null
      }
      return form.id
        ? { ...data, id: form.id, expected_version: form.expected_version }
        : { ...data, user_legacy_id: form.user_legacy_id, staff_type: form.staff_type }
    },
    submit() {
      this.$refs.form.validate(async valid => {
        if (!valid) return
        this.saving = true
        try {
          await saveBusinessStaff(this.payload(this.form))
          this.$message.success('业务员档案已保存')
          this.formVisible = false
          this.load()
        } finally {
          this.saving = false
        }
      })
    },
    async toggleStatus(row) {
      const next = row.status === 'enabled' ? 'disabled' : 'enabled'
      if (next === 'disabled') {
        await this.$confirm(`停用后 ${row.nickname || row.username} 不能再被选为${row.staff_type_label}，历史单据不受影响。确定停用？`, '停用业务员', { type: 'warning' })
      }
      await saveBusinessStaff(this.payload({ ...row, status: next, expected_version: row.business_version }))
      this.$message.success(next === 'enabled' ? '已启用' : '已停用')
      this.load()
    },
    async remove(row) {
      await this.$confirm(`确定删除 ${row.nickname || row.username} 的${row.staff_type_label}档案？已有单据的档案只能停用。`, '删除档案', { type: 'warning' })
      await deleteBusinessStaff(row.id, { expected_version: row.business_version })
      this.$message.success('已删除')
      this.load()
    }
  }
}
</script>

<style scoped>
.staff-page-container { padding: 16px 20px; background: #f8fafc; min-height: calc(100vh - 52px); box-sizing: border-box; }
.page-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 16px 20px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 14px; }
.head-left { display: flex; align-items: center; gap: 12px; }
.head-icon { width: 40px; height: 40px; border-radius: 8px; background: #f0fdf4; color: #008b4b; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.title-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.page-title { margin: 0; font-size: 18px; font-weight: 700; color: #0f172a; }
.head-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.metric-overview-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 14px; margin-bottom: 16px; }
.metric-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; display: flex; align-items: center; gap: 14px; cursor: pointer; user-select: none; }
.metric-card:hover { border-color: #cbd5e1; }
.metric-card.active { border-color: #008b4b; box-shadow: 0 2px 8px rgba(0, 139, 75, 0.12); }
.metric-icon-box { width: 42px; height: 42px; border-radius: 8px; display: grid; place-items: center; font-size: 20px; flex-shrink: 0; background: #eaf7ef; color: #008b4b; }
.metric-info { display: flex; flex-direction: column; }
.metric-label { font-size: 12px; color: #64748b; margin-bottom: 2px; }
.metric-val { font-size: 22px; font-weight: 700; color: #0f172a; line-height: 1.2; }
.metric-sub { font-size: 12px; color: #94a3b8; }
.table-container-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
.filter-toolbar { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
.filter-fields { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; flex: 1; }
.filter-item { display: flex; align-items: center; gap: 5px; }
.filter-label { font-size: 12px; color: #475569; font-weight: 500; white-space: nowrap; }
.filter-input { width: 170px; }
.filter-select { width: 140px; }
.filter-actions { display: flex; gap: 8px; }
.table-wrap { padding: 12px 16px; }
.user-cell { display: flex; flex-direction: column; line-height: 1.4; }
.user-cell span { font-size: 12px; color: #94a3b8; }
.table-pagination-footer { display: flex; justify-content: flex-end; padding: 10px 16px 14px; overflow-x: auto; }
@media (max-width: 1400px) {
  .metric-overview-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 900px) {
  .metric-overview-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 640px) {
  .staff-page-container { padding: 12px; }
  .metric-overview-grid { grid-template-columns: 1fr; }
  .filter-item, .filter-input, .filter-select { width: 100%; }
  .filter-item .el-input, .filter-item .el-select { flex: 1; }
}
</style>
