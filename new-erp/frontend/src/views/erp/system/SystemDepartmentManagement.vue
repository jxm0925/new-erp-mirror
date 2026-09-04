<template>
  <section class="system-frame dept-page">
    <div class="dept-top">
      <div>
        <div class="system-crumb">系统管理　/　<b>部门管理</b></div>
        <h1>部门管理</h1>
      </div>
      <el-button icon="el-icon-refresh" @click="load">刷新</el-button>
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
            :class="{ active: current && current.legacy_id === node.legacy_id, child: node.parent_legacy_id }"
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
          <h2>部门详情 <span>公司 / {{ current.name }}</span></h2>
        </div>

        <section class="base-info">
          <h3>基本信息</h3>
          <dl>
            <dt>部门名称：</dt><dd>{{ current.name }}</dd>
            <dt>部门负责人：</dt><dd>{{ principalNames || '未设置' }}</dd>
            <dt>排序号：</dt><dd>{{ current.sort || 0 }}</dd>
            <dt>上级部门：</dt><dd>{{ parentName(current) }}</dd>
            <dt>部门成员：</dt><dd>{{ memberTotal }} 名</dd>
            <dt>状态：</dt><dd><el-switch :value="current.status === 'normal'" active-color="#07883f" disabled /></dd>
            <dt>部门编码：</dt><dd>DEP-{{ String(current.legacy_id).padStart(3, '0') }}</dd>
            <dt>创建时间：</dt><dd>2026-07-15 09:18:26</dd>
            <dt>更新人：</dt><dd>张伟</dd>
            <dt>更新时间：</dt><dd>2026-07-15 10:32:45</dd>
          </dl>
        </section>

        <section class="principals">
          <div class="section-title">
            <h3>部门负责人 <small>部门负责人有本部门数据范围内的全部查看权限（不提升按钮权限）</small></h3>
            <el-button icon="el-icon-edit" @click="editPrincipal = true">编辑负责人</el-button>
          </div>
          <el-select v-model="principalIds" multiple filterable placeholder="请选择负责人（可多选）" :disabled="!editPrincipal">
            <el-option v-for="member in members" :key="member.id" :label="displayName(member)" :value="member.id" />
          </el-select>
          <el-button v-if="editPrincipal" type="success" @click="savePrincipals">保存负责人</el-button>
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
          <el-table :data="pagedMembers" border>
            <el-table-column type="selection" width="42" />
            <el-table-column label="姓名"><template slot-scope="{ row }">{{ displayName(row) }}</template></el-table-column>
            <el-table-column prop="username" label="账号" />
            <el-table-column label="组织身份"><template slot-scope="{ row }">{{ row.is_principal ? '部门负责人' : rowIdentity(row) }}</template></el-table-column>
            <el-table-column label="数据范围"><template slot-scope="{ row }">{{ row.is_principal ? '本部门' : rowIdentity(row) === '销售人员' ? '仅本人' : '按角色' }}</template></el-table-column>
            <el-table-column label="状态"><template slot-scope="{ row }"><span class="member-status" :class="{ disabled: row.status !== 'normal' }">{{ row.status === 'normal' ? '启用' : '停用' }}</span></template></el-table-column>
          </el-table>
          <div class="pager-row"><span>共 {{ memberTotal }} 条</span><el-pagination background layout="sizes, prev, pager, next, jumper" :page-size.sync="pageSize" :current-page.sync="page" :total="memberTotal" @size-change="handleSizeChange" @current-change="handlePageChange" /></div>
        </section>

        <section class="scope-impact">
          <div>
            <h3>数据范围影响 <small>预览</small></h3>
            <ul>
              <li>本部门负责人可查看本部门范围内的所有销售订单 / 库存单据 / 采购单据。</li>
              <li>销售订单特殊规则：管理员和销售负责人看全部，销售人员只看自己的订单。</li>
            </ul>
          </div>
          <div class="shield"><i class="el-icon-user-solid" /></div>
        </section>

      </main>
    </div>
  </section>
</template>

<script>
import { listDepartments, listDepartmentMembers, saveDepartmentPrincipals } from '@/api/erp/rbac'

export default {
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
    filterTimer: null
  }),
  computed: {
    filteredTree() {
      const keyword = this.keyword.trim()
      return this.departments.filter(item => !keyword || item.name.includes(keyword))
    },
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
      const { data } = await listDepartments({ tree: 1 })
      this.departments = data
      this.memberCountMap = data.reduce((map, dept) => ({ ...map, [dept.legacy_id]: dept.member_count || 0 }), {})
      if (!this.current && data.length) await this.selectDepartment(data[0])
      else if (this.current) await this.selectDepartment(data.find(item => item.legacy_id === this.current.legacy_id) || data[0])
    },
    async selectDepartment(dept) {
      if (!dept) return
      this.current = dept
      this.editPrincipal = false
      const { data } = await listDepartmentMembers(dept.legacy_id, {
        page: this.page,
        per_page: this.pageSize,
        keyword: this.memberKeyword,
        role: this.memberRole,
        status: this.memberStatus
      })
      this.members = (data.data || []).map(row => ({ ...row, is_principal: Boolean(row.is_principal) }))
      this.principalUsers = (data.principals || []).map(row => ({ ...row, is_principal: Boolean(row.is_principal) }))
      this.principalIds = this.principalUsers.map(row => row.id)
      this.memberTotal = data.meta ? data.meta.total : this.members.length
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
    async savePrincipals() {
      if (!this.current) return
      await saveDepartmentPrincipals(this.current.legacy_id, this.principalIds)
      this.$message.success('部门负责人已保存')
      this.editPrincipal = false
      await this.selectDepartment(this.current)
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
      const parent = this.departments.find(item => item.legacy_id === row.parent_legacy_id)
      return parent ? parent.name : '公司'
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
