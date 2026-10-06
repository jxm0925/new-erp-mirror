<template>
  <section class="system-frame dept-page">
    <div class="dept-top">
      <div>
        <div class="system-crumb">系统管理　/　<b>部门管理</b></div>
        <h1>部门管理</h1>
      </div>
      <div class="title-actions"><el-button icon="el-icon-refresh" :loading="loading" @click="load">刷新</el-button><el-button v-if="$can('system.department.save')" type="success" icon="el-icon-plus" @click="openDepartmentForm()">新增部门</el-button></div>
    </div>

    <div class="dept-workbench">
      <aside class="dept-tree-panel">
        <h2>部门组织架构</h2>
        <el-input v-model="keyword" suffix-icon="el-icon-search" clearable placeholder="请输入部门名称" />
        <div class="dept-tree">
          <div
            v-for="node in filteredTree"
            :key="node.legacy_id"
            class="dept-node"
            :class="{ active: current && current.legacy_id === node.legacy_id }"
            :style="{ paddingLeft: (10 + Math.min(node.depth, 4) * 16) + 'px' }"
            @click="openDepartment(node)"
          >
            <i :class="node.parent_legacy_id ? 'el-icon-folder' : 'el-icon-office-building'" />
            <span>{{ node.name }}</span>
            <span class="node-status" :class="{ disabled: node.status !== 'normal' }">{{ node.status === 'normal' ? '启用' : '停用' }}</span>
            <small><i class="el-icon-user" /> {{ node.member_count || memberCountMap[node.legacy_id] || 0 }}</small>
          </div>
        </div>
        <div class="tree-legend"><span><b class="green" />启用</span><span><b class="orange" />停用</span><span><b class="gray" />已归档</span></div>
      </aside>

      <main v-if="current" class="dept-detail">
        <div class="detail-head">
          <h2>部门详情 <span>{{ current.parent_legacy_id ? parentName(current) + ' / ' : '' }}{{ current.name }}</span></h2>
        </div>
        <div class="system-actions dept-actionbar"><el-button v-if="$can('system.department.save')" icon="el-icon-edit" @click="openDepartmentForm(current)">修改部门</el-button><el-button v-if="$can('system.department.save')" icon="el-icon-plus" @click="openDepartmentForm(null, current.legacy_id)">新增下级部门</el-button><el-button v-if="$can('system.department.delete')" class="danger-link" icon="el-icon-delete" :disabled="saving" @click="removeDepartment">删除部门</el-button></div>

        <section class="base-info">
          <h3>基本信息</h3>
          <dl>
            <dt>部门名称：</dt><dd>{{ current.name }}</dd>
            <dt>部门负责人：</dt><dd>{{ principalNames || '未设置' }}</dd>
            <dt>排序号：</dt><dd>{{ current.sort || 0 }}</dd>
            <dt>上级部门：</dt><dd>{{ parentName(current) }}</dd>
            <dt>部门成员：</dt><dd>{{ current.member_count || 0 }} 名</dd>
            <dt>状态：</dt><dd><el-switch :value="current.status === 'normal'" active-color="#07883f" disabled /></dd>
            <dt>部门编号：</dt><dd>{{ current.legacy_id }}</dd>
            <dt>创建时间：</dt><dd>{{ timeText(current.created_at) }}</dd>
            <dt>更新时间：</dt><dd>{{ timeText(current.updated_at) }}</dd>
          </dl>
        </section>

        <section class="principals">
          <div class="section-title">
            <h3>部门负责人 <small>按部门范围和角色权限访问业务</small></h3>
            <el-button v-if="$can('system.department.set_principal')" icon="el-icon-edit" :disabled="saving" @click="principalPickerVisible = true">编辑负责人</el-button>
          </div>
          <div class="principal-tags"><el-tag v-for="user in principalUsers" :key="user.id">{{ displayName(user) }}</el-tag><span v-if="!principalUsers.length">未设置</span></div>
        </section>

        <section class="members">
          <div class="section-title">
            <h3>部门成员 <small>普通成员仅按角色参与数据范围生效</small></h3>
            <div>
              <span class="member-help">成员归属由管理员档案维护</span>
            </div>
          </div>
          <div class="member-filter">
            <el-input v-model="memberKeyword" clearable suffix-icon="el-icon-search" placeholder="请输入姓名或账号" />
            <el-select v-model="memberRole" clearable placeholder="全部角色"><el-option label="销售人员" value="sales" /><el-option label="负责人" value="principal" /><el-option label="普通成员" value="normal" /></el-select>
            <el-select v-model="memberStatus" clearable placeholder="全部状态"><el-option label="启用" value="normal" /><el-option label="停用" value="hidden" /></el-select>
          </div>
          <el-table v-loading="memberLoading" :data="pagedMembers" border>
            <el-table-column label="姓名"><template slot-scope="{ row }">{{ displayName(row) }}</template></el-table-column>
            <el-table-column prop="username" label="账号" />
            <el-table-column label="组织身份"><template slot-scope="{ row }">{{ row.is_principal ? '部门负责人' : rowIdentity(row) }}</template></el-table-column>
            <el-table-column label="数据范围"><template slot-scope="{ row }">{{ scopeText(row.data_scope) }}</template></el-table-column>
            <el-table-column label="状态"><template slot-scope="{ row }"><span class="member-status" :class="{ disabled: row.status !== 'normal' }">{{ row.status === 'normal' ? '启用' : '停用' }}</span></template></el-table-column>
          </el-table>
          <div class="pager-row"><span>共 {{ memberTotal }} 条</span><el-pagination background layout="sizes, prev, pager, next, jumper" :page-size.sync="pageSize" :current-page.sync="page" :total="memberTotal" @size-change="handleSizeChange" @current-change="handlePageChange" /></div>
        </section>

      </main>
      <div v-else class="empty-state">暂无部门，请新增部门。</div>
    </div>
    <el-dialog :title="departmentForm.legacy_id ? '修改部门' : '新增部门'" :visible.sync="departmentFormVisible" append-to-body width="760px" top="5vh" custom-class="system-management-dialog" :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving" @closed="!departmentFormVisible && resetDepartmentForm()">
      <el-form ref="departmentForm" :model="departmentForm" :rules="departmentRules" label-position="top">
        <div class="form-grid">
          <el-form-item label="部门名称" prop="name"><el-input v-model.trim="departmentForm.name" maxlength="120" /></el-form-item>
          <el-form-item label="上级部门" prop="parent_legacy_id"><el-cascader v-model="departmentForm.parent_legacy_id" :options="parentOptions" :props="{ checkStrictly: true, emitPath: false }" placeholder="选择上级部门" /></el-form-item>
          <el-form-item label="状态"><el-radio-group v-model="departmentForm.status"><el-radio label="normal">启用</el-radio><el-radio label="hidden">停用</el-radio></el-radio-group></el-form-item>
          <el-form-item label="排序号"><el-input-number v-model="departmentForm.sort" controls-position="right" :min="-999999" :max="999999" /></el-form-item>
        </div>
      </el-form>
      <span slot="footer"><el-button :disabled="saving" @click="departmentFormVisible = false">取消</el-button><el-button type="success" :loading="saving" @click="submitDepartmentForm">保存</el-button></span>
    </el-dialog>
    <system-record-picker :visible.sync="principalPickerVisible" type="users" :department-id="current ? Number(current.legacy_id) : 0" :value="principalUsers" @confirm="savePrincipals" />
  </section>
</template>

<script>
import { listDepartments, listDepartmentMembers, saveDepartmentPrincipals, saveDepartment, deleteDepartment } from '@/api/erp/rbac'
import SystemRecordPicker from '@/components/system/SystemRecordPicker.vue'
import { departmentTree, flatDepartmentTree, newSystemCommand, systemMutation, systemTime, systemFormCommand } from '@/utils/systemManagement'
import '@/styles/system-management.css'

const emptyDepartment = () => ({ legacy_id: null, name: '', parent_legacy_id: 0, status: 'normal', sort: 0 })

export default {
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
    filterTimer: null, loading: false, memberLoading: false, saving: false, loadSerial: 0, memberSerial: 0,
    departmentFormVisible: false, departmentForm: emptyDepartment(), formCommand: '', formCommandState: null, principalPickerVisible: false,
    departmentRules: { name: [{ required: true, message: '请输入部门名称', trigger: 'blur' }], parent_legacy_id: [{ required: true, message: '请选择上级部门', trigger: 'change' }] }
  }),
  computed: {
    filteredTree() {
      const keyword = this.keyword.trim()
      return flatDepartmentTree(this.departments).filter(item => !keyword || item.name.includes(keyword))
    },
    parentOptions() { return [{ value: 0, label: '无上级部门' }, ...departmentTree(this.departments, this.departmentForm.legacy_id)] },
    filteredMembers() {
      return this.members
    },
    pagedMembers() {
      return this.members
    },
    principalNames() {
      return this.principalUsers.map(this.displayName).join('、')
    }
  },
  created() {
    this.load()
  },
  beforeDestroy() {
    if (this.filterTimer) clearTimeout(this.filterTimer)
    this.loadSerial += 1; this.memberSerial += 1
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
      const serial = ++this.loadSerial; this.loading = true
      try {
        const { data } = await listDepartments({ tree: 1 })
        if (serial !== this.loadSerial) return
        this.departments = data || []
        this.memberCountMap = this.departments.reduce((map, dept) => ({ ...map, [dept.legacy_id]: dept.member_count || 0 }), {})
        await this.selectDepartment(this.current ? data.find(item => item.legacy_id === this.current.legacy_id) || data[0] : data[0])
      } catch (error) {
        if (serial !== this.loadSerial) return
        this.departments = []; await this.selectDepartment(null); this.$message.error(error.userMessage || '部门列表加载失败')
      } finally { if (serial === this.loadSerial) this.loading = false }
    },
    async selectDepartment(dept) {
      const serial = ++this.memberSerial
      this.current = dept || null
      if (!dept) { this.members = []; this.principalUsers = []; this.principalIds = []; this.memberTotal = 0; this.memberLoading = false; return }
      this.editPrincipal = false
      this.memberLoading = true
      try {
        const { data } = await listDepartmentMembers(dept.legacy_id, { page: this.page, per_page: this.pageSize, keyword: this.memberKeyword, role: this.memberRole, status: this.memberStatus })
        if (serial !== this.memberSerial) return
        this.members = (data.data || []).map(row => ({ ...row, is_principal: Boolean(row.is_principal) }))
        this.principalUsers = (data.principals || []).map(row => ({ ...row, is_principal: Boolean(row.is_principal) }))
        this.principalIds = this.principalUsers.map(row => row.id)
        this.memberTotal = data.meta ? data.meta.total : this.members.length
        if (!this.members.length && this.page > 1) { this.page -= 1; return this.selectDepartment(dept) }
      } catch (error) {
        if (serial !== this.memberSerial) return
        this.members = []; this.principalUsers = []; this.memberTotal = 0; this.$message.error(error.userMessage || '部门成员加载失败')
      } finally { if (serial === this.memberSerial) this.memberLoading = false }
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
      try { await saveDepartmentPrincipals(this.current.legacy_id, rows.map(row => row.id), systemMutation(this.current)); this.$message.success('部门负责人已保存'); await this.load() }
      catch (error) { this.$message.error(error.userMessage || '负责人保存失败') }
      finally { this.saving = false }
    },
    resetDepartmentForm() { this.departmentForm = emptyDepartment(); this.formCommand = ''; this.formCommandState = null; this.$nextTick(() => this.$refs.departmentForm && this.$refs.departmentForm.clearValidate()) },
    openDepartmentForm(row, parent = 0) {
      this.resetDepartmentForm(); this.departmentForm = row ? { ...emptyDepartment(), ...row } : { ...emptyDepartment(), parent_legacy_id: parent }
      this.formCommand = newSystemCommand(); this.departmentFormVisible = true
    },
    async submitDepartmentForm() {
      if (this.saving || !(await this.$refs.departmentForm.validate().catch(() => false))) return
      this.saving = true
      try {
        const payload = { ...this.departmentForm, ...(this.departmentForm.legacy_id ? { expected_version: this.departmentForm.business_version } : {}) }
        this.formCommandState = systemFormCommand(this.formCommandState, payload, this.formCommand)
        const { data } = await saveDepartment({ ...payload, client_command_id: this.formCommandState.id })
        this.current = data; this.page = 1; this.$message.success('部门已保存'); this.departmentFormVisible = false; await this.load()
      } catch (error) {
        this.$message.error(error.userMessage || '部门保存失败')
        if (error.response && error.response.status !== 0) { this.formCommand = newSystemCommand(); this.formCommandState = null }
      }
      finally { this.saving = false }
    },
    async removeDepartment() {
      if (this.saving || !this.current) return
      const row = { ...this.current }
      try { await this.$confirm(`确定删除部门“${row.name}”？仍有下级、成员或审批引用的部门不能删除。`, '删除部门', { type: 'warning', confirmButtonText: '删除' }) }
      catch (_) { return }
      this.saving = true
      try { await deleteDepartment(row.legacy_id, systemMutation(row)); this.$message.success('部门已删除'); this.current = null; this.page = 1; await this.load() }
      catch (error) { this.$message.error(error.userMessage || '部门删除失败') }
      finally { this.saving = false }
    },
    timeText: systemTime,
    scopeText(value) { return ({ all: '全部数据', department: '本部门数据', self: '本人数据' })[value] || '本人数据' },
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
.system-frame{min-height:calc(100vh - 52px);padding:18px 24px;background:#f6f8fb;color:#142236}.dept-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px}.system-crumb{font-size:13px;color:#6d7785;margin-bottom:10px}.system-crumb b{color:#17243a}.dept-top h1{margin:0;font-size:22px}
.dept-workbench{display:grid;grid-template-columns:320px minmax(760px,1fr);gap:12px}.dept-tree-panel,.dept-detail{background:#fff;border:1px solid #dde5ee;border-radius:4px}.dept-tree-panel{min-height:810px;padding:16px;display:flex;flex-direction:column}.dept-tree-panel h2{margin:0 0 12px;font-size:16px}.dept-tree{margin-top:14px;flex:1;overflow:auto}.dept-node{height:42px;display:grid;grid-template-columns:22px 1fr 48px 42px;align-items:center;gap:8px;padding:0 10px;border-radius:4px;cursor:pointer;color:#334155}.dept-node.child{margin-left:24px}.dept-node:hover,.dept-node.active{background:#ecf8f2}.dept-node i{color:#e19a1c}.dept-node small{color:#657184}.node-status,.member-status{color:#07883f;font-size:12px}.node-status.disabled,.member-status.disabled{color:#94a3b8}.tree-legend{display:flex;gap:16px;color:#657184}.tree-legend b{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px}.green{background:#07883f}.orange{background:#f59e0b}.gray{background:#8b96a7}
.dept-detail{padding:14px 14px 10px}.detail-head{height:52px;display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1px solid #e5ebf2}.detail-head h2{margin:0;font-size:16px}.detail-head span{margin-left:14px;color:#6d7785;font-size:13px;font-weight:400}.dept-detail section{padding:14px 0;border-bottom:1px solid #e5ebf2}.dept-detail h3{margin:0 0 10px;font-size:14px}.dept-detail small{font-weight:400;color:#7b8798;margin-left:6px}
.base-info dl{display:grid;grid-template-columns:90px 1fr 90px 1fr 90px 1fr;gap:12px 18px;margin:0}.base-info dt{color:#6c788a;text-align:right}.base-info dd{margin:0}.section-title{display:flex;justify-content:space-between;align-items:center}.principals{display:grid;grid-template-columns:1fr auto;gap:12px}.principals .section-title{grid-column:1/3}.principals .el-select{width:100%}.member-help{color:#7b8798;font-size:12px}.member-filter{display:grid;grid-template-columns:1fr 190px 190px;gap:12px;margin-bottom:10px}.pager-row{height:50px;display:flex;align-items:center;gap:16px}.pager-row>span{margin-right:auto;color:#667487}
.scope-impact{display:grid;grid-template-columns:1fr 160px;align-items:center;background:#f4f9ff;border:1px solid #d8e9ff!important;border-radius:4px;padding:16px!important}.scope-impact ul{margin:0;padding-left:18px;color:#526176;line-height:2}.shield{justify-self:center;width:106px;height:86px;display:grid;place-items:center;background:#e5f1ff;border-radius:24px;color:#2f80ed;font-size:46px}
</style>

<style scoped>
.dept-workbench { grid-template-columns: 300px minmax(0, 1fr); }
.dept-tree-panel,
.dept-detail { min-width: 0; }
.dept-node { height: auto; min-height: 42px; grid-template-columns: 22px minmax(0, 1fr) 32px 36px; }
.dept-node > span:first-of-type { overflow-wrap: anywhere; line-height: 1.5; }
.dept-actionbar { margin: 14px 0; }
.base-info dl { grid-template-columns: 90px minmax(0, 1fr) 90px minmax(0, 1fr); }
.base-info dd { overflow-wrap: anywhere; }
.detail-head { height: auto; min-height: 52px; }
.section-title { gap: 12px; flex-wrap: wrap; }
.principals { display: block; }
.principal-tags { display: flex; flex-wrap: wrap; gap: 8px; }
.member-filter { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr); }
.pager-row { height: auto; min-height: 50px; flex-wrap: wrap; padding: 10px 0; }
.scope-impact { grid-template-columns: minmax(0, 1fr) 100px; }
.scope-impact { background: #f0fdf4; border-color: #bbf7d0 !important; }
.shield { color: #008b4b; background: #dcfce7; width: 80px; height: 70px; }
@media (max-width: 1180px) {
  .dept-workbench { grid-template-columns: minmax(0, 1fr); }
  .dept-tree-panel { min-height: 0; }
  .dept-tree { max-height: 320px; }
}
@media (max-width: 780px) {
  .system-frame { padding: 14px 12px; }
  .base-info dl { grid-template-columns: 90px minmax(0, 1fr); }
  .member-filter { grid-template-columns: minmax(0, 1fr); }
  .scope-impact { grid-template-columns: minmax(0, 1fr); }
  .shield { display: none; }
  .pager-row ::v-deep .el-pagination__sizes,
  .pager-row ::v-deep .el-pagination__jump { display: none; }
}
</style>
