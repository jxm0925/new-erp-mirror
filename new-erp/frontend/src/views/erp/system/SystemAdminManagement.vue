<template>
  <section class="system-frame admin-page">
    <div class="system-titlebar">
      <div>
        <div class="system-crumb">系统管理　/　<b>管理员管理</b></div>
        <h1>管理员管理</h1>
        <p>管理新 ERP 管理员账号、角色分配与权限控制。业务身份按部门归属自动识别，不在管理员资料里单独维护。</p>
      </div>
    </div>

    <div class="system-filter">
      <label>关键词<el-input v-model="filters.keyword" clearable placeholder="管理员 / 账号 / 手机号" /></label>
      <label>部门<el-select v-model="filters.department_name" clearable placeholder="全部部门"><el-option v-for="d in departments" :key="d.legacy_id" :label="d.name" :value="d.name" /></el-select></label>
      <label>角色<el-select v-model="filters.group_name" clearable placeholder="全部角色"><el-option v-for="r in roleOptions" :key="r" :label="r" :value="r" /></el-select></label>
      <label>状态<el-select v-model="filters.status" placeholder="全部状态"><el-option label="全部状态" value="all" /><el-option label="正常" value="normal" /><el-option label="隐藏/禁用" value="hidden" /></el-select></label>
      <label>数据范围<el-select v-model="filters.scope" clearable placeholder="全部范围"><el-option label="全部数据" value="all" /><el-option label="本部门数据" value="department" /><el-option label="仅本人数据" value="self" /></el-select></label>
      <el-button type="success" @click="query">查询</el-button>
      <el-button @click="reset">重置</el-button>
    </div>

    <div class="sync-strip">
      <i class="el-icon-success" />
      <span>本系统管理员 {{ total }} 人（当前页 {{ users.length }} 人）</span>
    </div>

    <div class="admin-workbench">
      <div class="admin-table-card">
        <el-table :data="users" border height="660" highlight-current-row @row-click="selectUser">
          <el-table-column label="管理员" min-width="130">
            <template slot-scope="{ row }">
              <div class="admin-cell">
                <span class="avatar-dot" :style="{ background: avatarColor(row) }">{{ displayName(row).slice(0, 1) }}</span>
                <div><strong>{{ displayName(row) }}</strong><small>{{ row.mobile || '-' }}</small></div>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="username" label="登录账号" min-width="110" />
          <el-table-column label="所属部门" min-width="150"><template slot-scope="{ row }">{{ firstJsonLabel(row.department_names) }}</template></el-table-column>
          <el-table-column label="岗位/角色" min-width="130"><template slot-scope="{ row }"><el-tag size="mini" type="primary">{{ primaryRole(row) }}</el-tag></template></el-table-column>
          <el-table-column label="数据范围" width="115"><template slot-scope="{ row }"><el-tag size="mini" :type="scopeType(userScope(row))">{{ scopeText(userScope(row)) }}</el-tag></template></el-table-column>
          <el-table-column label="组织身份" width="112"><template slot-scope="{ row }"><el-tag size="mini" :type="identityType(row)">{{ departmentIdentity(row) }}</el-tag></template></el-table-column>
          <el-table-column label="状态" width="86"><template slot-scope="{ row }"><el-tag size="mini" :type="row.status === 'normal' ? 'success' : 'danger'">{{ row.status === 'normal' ? '正常' : '禁用' }}</el-tag></template></el-table-column>
          <el-table-column label="最近登录" width="126"><template>-</template></el-table-column>
          <el-table-column label="操作" width="70" fixed="right">
            <template slot-scope="{ row }">
              <el-button type="text" @click.stop="selectUser(row)">查看</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div class="pager-row">
          <span>共 {{ total }} 条</span>
          <el-pagination background layout="sizes, prev, pager, next, jumper" :page-size.sync="pageSize" :current-page.sync="page" :total="total" @size-change="handleSizeChange" @current-change="handlePageChange" />
        </div>
      </div>

      <aside class="admin-detail">
        <button class="panel-close" @click="selected = null"><i class="el-icon-close" /></button>
        <template v-if="selected">
          <h2>管理员详情</h2>
          <div class="profile-line">
            <span class="portrait">{{ displayName(selected).slice(0, 1) }}</span>
            <div>
              <strong>{{ displayName(selected) }}</strong>
              <el-tag size="mini" type="success">{{ selected.status === 'normal' ? '正常' : '禁用' }}</el-tag>
              <small>登录账号：{{ selected.username || '-' }}</small>
              <small>手机号：{{ selected.mobile || '-' }}</small>
              <small>邮箱：{{ selected.email || '-' }}</small>
              <small>组织身份：<el-tag size="mini" :type="identityType(selected)">{{ departmentIdentity(selected) }}</el-tag>　数据范围：<el-tag size="mini" :type="scopeType(userScope(selected))">{{ scopeText(userScope(selected)) }}</el-tag></small>
            </div>
          </div>

          <section>
            <h3>部门关系</h3>
            <dl>
              <dt>所属部门</dt><dd>{{ joinJsonLabels(selected.department_names) || '-' }}</dd>
              <dt>部门路径</dt><dd>公司 / {{ firstJsonLabel(selected.department_names) || '-' }}</dd>
              <dt>部门负责人</dt><dd>{{ isPrincipal(selected) ? '是' : '否' }}</dd>
            </dl>
          </section>

          <section>
            <h3>角色与权限</h3>
            <div class="tag-list">
              <el-tag v-for="role in userRoles(selected)" :key="role" size="mini">{{ role }}</el-tag>
            </div>
            <div class="metric-row">
              <span><b>-</b><small>菜单权限</small></span>
              <span><b>-</b><small>按钮权限</small></span>
              <span><b>-</b><small>未授权</small></span>
              <span><b>-</b><small>无权限</small></span>
            </div>
          </section>

          <section>
            <h3>按钮权限概览</h3>
            <div class="permission-preview"><p>暂无逐按钮权限统计，请以角色权限页面的真实配置为准。</p></div>
          </section>

          <section>
            <h3>数据范围预览</h3>
            <ul class="scope-list">
              <li>销售订单：{{ userScope(selected) === 'self' ? '只能查看自己的订单' : '可查看范围内订单' }}</li>
              <li>采购订单：可查看被授权范围内采购单据</li>
              <li>库存单据：可查看被授权范围内库存单据</li>
              <li>客户信息：按销售归属和共享人限制</li>
            </ul>
          </section>
        </template>
        <div v-else class="empty-panel">点击左侧管理员查看权限详情</div>
      </aside>
    </div>
  </section>
</template>

<script>
import { listDepartments, listUsers } from '@/api/erp/rbac'

export default {
  data: () => ({
    users: [],
    departments: [],
    selected: null,
    page: 1,
    pageSize: 10,
    total: 0,
    filters: { keyword: '', department_name: '', group_name: '', status: 'all', scope: '' }
  }),
  computed: {
    roleOptions() {
      const set = new Set()
      this.users.forEach(user => (user.rbac_roles || []).forEach(role => set.add(role.name)))
      return Array.from(set).filter(Boolean)
    },
    filteredUsers() {
      return this.users
    }
  },
  created() {
    this.load()
  },
  methods: {
    async load() {
      try {
        const { data: userResponse } = await listUsers({ ...this.filters, scope: 'system', data_scope: this.filters.scope, page: this.page, per_page: this.pageSize })
        this.users = userResponse.data || userResponse
        this.total = userResponse.meta ? userResponse.meta.total : this.users.length
        this.selected = this.users[0] || null
      } catch (error) {
        this.users = []
        this.total = 0
        this.selected = null
        this.$message.error(error.userMessage || '管理员目录加载失败')
      }
      try {
        const { data: departments } = await listDepartments({ tree: 1 })
        this.departments = departments || []
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
      this.filters = { keyword: '', department_name: '', group_name: '', status: 'all', scope: '' }
      this.page = 1
      this.load()
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
    selectUser(row) { this.selected = row },
    displayName(row) { return row.nickname || row.username || `用户${row.id}` },
    parseJson(value) {
      try { return Array.isArray(value) ? value : JSON.parse(value || '[]') } catch (e) { return [] }
    },
    firstJsonLabel(value) { return this.parseJson(value)[0] || '-' },
    joinJsonLabels(value) { return this.parseJson(value).join('、') },
    primaryRole(row) {
      const roles = row.rbac_roles || []
      const primary = roles.find(role => role.code === 'admin') || roles[0]
      return primary ? primary.name : '未配置角色'
    },
    userRoles(row) {
      return (row.rbac_roles || []).map(role => role.name).filter(Boolean)
    },
    userScope(row) {
      return row.data_scope || 'self'
    },
    isPrincipal(row) {
      return (row.rbac_roles || []).some(role => role.code === 'department_principal')
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
      const colors = ['#4f7df3', '#10a66e', '#8b5cf6', '#f59e0b', '#0ea5e9']
      return colors[(row.id || 0) % colors.length]
    }
  }
}
</script>

<style scoped>
.system-frame{min-height:calc(100vh - 52px);padding:20px 24px;background:#f6f8fb;color:#152235}
.system-titlebar{display:flex;justify-content:space-between;gap:24px;margin-bottom:16px}
.system-crumb{font-size:13px;color:#6d7785;margin-bottom:10px}.system-crumb b{color:#17243a}.system-titlebar h1{margin:0 0 8px;font-size:22px}.system-titlebar p{margin:0;color:#617083}.title-actions{display:flex;gap:10px;align-items:flex-start}
.system-filter{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;padding:16px;background:#fff;border:1px solid #dde5ee;border-radius:4px}.system-filter label{display:grid;gap:6px;color:#26364a;font-size:13px}.system-filter .el-input{width:180px}.system-filter .el-select{width:170px}
.sync-strip{height:34px;margin:12px 0;display:flex;align-items:center;gap:10px;padding:0 16px;background:#edf9f2;border:1px solid #ccebd9;color:#16824c;border-radius:4px}
.admin-workbench{display:grid;grid-template-columns:minmax(0,1fr) 290px;gap:12px}.admin-table-card,.admin-detail{background:#fff;border:1px solid #dde5ee;border-radius:4px}.admin-table-card{min-width:0;overflow:hidden}.admin-cell{display:flex;align-items:center;gap:10px}.admin-cell strong{display:block}.admin-cell small{display:block;color:#697789}.avatar-dot,.portrait{display:grid;place-items:center;border-radius:50%;color:#fff;font-weight:700}.avatar-dot{width:30px;height:30px}.portrait{width:56px;height:56px;background:#4f7df3;font-size:18px}.pager-row{height:58px;display:flex;align-items:center;gap:16px;padding:0 14px}.pager-row>span{margin-right:auto;color:#667487}
.admin-detail{position:relative;padding:18px 16px;min-height:720px}.panel-close{position:absolute;right:14px;top:14px;border:0;background:transparent;color:#8190a3;cursor:pointer}.admin-detail h2{font-size:18px;margin:0 0 18px}.profile-line{display:flex;gap:14px;padding-bottom:16px;border-bottom:1px solid #e5ebf2}.profile-line strong{font-size:18px;margin-right:8px}.profile-line small{display:block;margin-top:6px;color:#59697c}.admin-detail section{padding:16px 0;border-bottom:1px solid #e5ebf2}.admin-detail h3{margin:0 0 10px;font-size:14px}.admin-detail dl{display:grid;grid-template-columns:78px 1fr;gap:8px;margin:0}.admin-detail dt{color:#738195}.admin-detail dd{margin:0}.tag-list{display:flex;gap:8px;flex-wrap:wrap}.metric-row{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:12px}.metric-row span{height:52px;display:grid;place-items:center;background:#f6f8fb;border:1px solid #edf1f5;border-radius:4px}.metric-row b{font-size:18px;color:#07883f}.metric-row small{font-size:11px;color:#6b7788}.permission-preview p{display:flex;justify-content:space-between;margin:8px 0;color:#435266}.scope-list{margin:0;padding-left:18px;color:#435266;line-height:2}.empty-panel{height:100%;display:grid;place-items:center;color:#8b97a8}.danger-link{color:#f05252}
</style>
