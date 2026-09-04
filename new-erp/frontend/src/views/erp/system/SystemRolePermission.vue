<template>
  <section class="system-frame role-page">
    <div class="system-titlebar">
      <div>
        <div class="system-crumb">系统管理　/　<b>角色权限</b></div>
        <h1>角色权限</h1>
        <p>角色决定菜单、按钮和数据范围；部门负责人可查看本部门数据。</p>
      </div>
      <div class="title-actions">
        <el-button icon="el-icon-refresh" @click="load">刷新</el-button>
      </div>
    </div>

    <div class="role-workbench">
      <aside class="role-list-card">
        <div class="card-head">
          <h2>角色列表</h2>
        </div>
        <el-input v-model="roleKeyword" clearable placeholder="搜索角色名称或编码" />
        <el-table :data="pagedRoles" height="604" highlight-current-row @row-click="selectRole">
          <el-table-column label="角色编码/名称" min-width="160">
            <template slot-scope="{ row }">
              <strong>{{ row.code }}</strong>
              <small>{{ row.name }}</small>
            </template>
          </el-table-column>
          <el-table-column label="数据范围" width="96"><template slot-scope="{ row }"><el-tag size="mini" :type="scopeType(row.data_scope)">{{ scopeText(row.data_scope) }}</el-tag></template></el-table-column>
          <el-table-column label="成员数" width="66"><template slot-scope="{ row }">{{ row.member_count || 0 }}</template></el-table-column>
          <el-table-column label="操作" width="54"><template slot-scope="{ row }"><el-button type="text" icon="el-icon-edit" @click.stop="selectRole(row)" /></template></el-table-column>
        </el-table>
        <div class="role-pager">
          <span>共 {{ roleTotal }} 条</span>
          <el-pagination background small layout="prev, pager, next" :page-size.sync="rolePageSize" :current-page.sync="rolePage" :total="roleTotal" @current-change="handleRolePageChange" />
        </div>
      </aside>

      <main class="role-editor" v-if="currentRole">
        <div class="current-role-strip">
          <span><i class="el-icon-success" /> 当前角色：<b>{{ currentRole.name }}</b>（{{ currentRole.code }}）</span>
          <small>成员：{{ currentRole.member_count || 0 }} 人</small>
          <small>更新：{{ currentRole.updated_at || '-' }}</small>
          <span class="role-status" :class="{ disabled: !currentRole.enabled }">{{ currentRole.enabled ? '启用' : '停用' }}</span>
        </div>

        <div class="permission-heading">
          <h2>菜单与按钮权限</h2>
          <span>按钮权限直接显示在所属业务菜单下面</span>
        </div>

        <section class="permission-zone">
          <div class="menu-tree-box">
            <h3>实际权限树 <small>一级模块 → 业务菜单 → 按钮</small></h3>
            <el-tree
              ref="permTree"
              :data="permissionTree"
              show-checkbox
              node-key="id"
              :default-expand-all="false"
              :props="{ label: 'name', children: 'children' }"
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
                <span v-else-if="data.type === 'button'" class="badge-tag badge-blue">按钮</span>
                <span v-else class="badge-tag badge-orange">接口</span>
                <code class="perm-code-tag">{{ data.code }}</code>
              </div>
            </el-tree>
            <div class="tree-actions">
              <el-button size="mini" class="btn-default" @click="expandAll">展开全部</el-button>
              <el-button size="mini" class="btn-default" @click="collapseAll">折叠全部</el-button>
            </div>
          </div>
        </section>

        <section class="lower-zone">
          <div class="scope-card">
            <h3>数据范围 <small>控制可访问的数据范围</small></h3>
            <el-radio-group v-model="currentRole.data_scope">
              <el-radio label="all"><b>全部数据</b><small>可查看和操作所有数据</small></el-radio>
              <el-radio label="department"><b>本部门数据</b><small>仅可查看本部门及子部门数据</small></el-radio>
              <el-radio label="self"><b>本人数据</b><small>仅可查看和操作本人创建的数据</small></el-radio>
            </el-radio-group>
          </div>

          <div class="description-card">
            <h3>说明</h3>
            <p>数据范围与菜单、按钮权限共同生效，最终决定用户可见可操作的数据。</p>
            <h4>当前生效范围</h4>
            <div class="scope-preview"><i class="el-icon-info" /> {{ scopePreview }}</div>
            <p class="sample-users">当前角色直接关联 {{ currentRole.member_count || 0 }} 名用户。</p>
          </div>
        </section>

        <div class="sticky-actions">
          <div class="risk-tip">
            <b>风险提示</b>
            <span>当前角色拥有 {{ checkedPermissionIds.length }} 项权限</span>
          </div>
          <div class="impact">
            <span>权限变更影响</span>
            <b>直接影响用户：{{ currentRole.member_count || 0 }} 人</b>
          </div>
          <el-button type="success" @click="save">保存权限</el-button>
        </div>
      </main>
    </div>
  </section>
</template>

<script>
import { listPermissions, listRoles, saveRole } from '@/api/erp/rbac'

export default {
  data: () => ({
    roles: [],
    permissions: [],
    currentRole: null,
    checkedPermissionIds: [],
    roleKeyword: '',
    rolePage: 1,
    rolePageSize: 10,
    roleTotal: 0
  }),
  computed: {
    filteredRoles() {
      return this.roles
    },
    pagedRoles() {
      return this.roles
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
      if (this.currentRole.data_scope === 'all') return `可查看：全部业务数据，以及被授权按钮动作。`
      if (this.currentRole.data_scope === 'department') return `可查看：本部门及子部门的销售订单、采购、库存、客户数据。`
      return `可查看：本人创建或归属给本人的销售订单、客户、跟进与业务单据。`
    }
  },
  created() {
    this.load()
  },
  watch: {
    roleKeyword() {
      this.rolePage = 1
      this.load()
    }
  },
  methods: {
    async load() {
      const [{ data: permissions }, { data: roleResponse }] = await Promise.all([
        listPermissions({ tree: 1 }),
        listRoles({ page: this.rolePage, per_page: this.rolePageSize, keyword: this.roleKeyword })
      ])
      this.permissions = permissions
      const roles = roleResponse.data || roleResponse
      this.roles = roles
      this.roleTotal = roleResponse.meta ? roleResponse.meta.total : roles.length
      this.selectRole(this.currentRole ? roles.find(item => item.id === this.currentRole.id) || roles[0] : roles[0])
    },
    selectRole(row) {
      if (!row) return
      this.currentRole = { ...row }
      this.checkedPermissionIds = row.permission_ids || []
      this.$nextTick(() => this.$refs.permTree && this.$refs.permTree.setCheckedKeys(this.checkedPermissionIds))
    },
    handleRolePageChange(page) {
      this.rolePage = page
      this.load()
    },
    syncChecked() {
      this.checkedPermissionIds = this.$refs.permTree ? this.$refs.permTree.getCheckedKeys() : []
    },
    expandAll() {
      this.permissionTree.forEach(node => this.walkTree(node, item => { if (this.$refs.permTree.store.nodesMap[item.id]) this.$refs.permTree.store.nodesMap[item.id].expanded = true }))
    },
    collapseAll() {
      this.permissionTree.forEach(node => this.walkTree(node, item => { if (this.$refs.permTree.store.nodesMap[item.id]) this.$refs.permTree.store.nodesMap[item.id].expanded = false }))
    },
    walkTree(node, cb) {
      cb(node)
      ;(node.children || []).forEach(child => this.walkTree(child, cb))
    },
    async save() {
      this.syncChecked()
      await saveRole({ ...this.currentRole, permission_ids: this.checkedPermissionIds })
      this.$message.success('角色权限已保存')
      this.load()
    },
    iconFor(row) {
      if (row.icon) return row.icon
      if (!row.parent_id) return 'el-icon-s-grid'
      return row.type === 'button' ? 'el-icon-mouse' : row.type === 'api' ? 'el-icon-connection' : 'el-icon-menu'
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
      return ({ all: 'primary', department: 'success', self: 'warning' })[scope] || 'info'
    }
  }
}
</script>

<style scoped>
.system-frame{min-height:calc(100vh - 52px);padding:20px 24px;background:#f6f8fb;color:#142236}.system-titlebar{display:flex;justify-content:space-between;margin-bottom:16px}.system-crumb{font-size:13px;color:#6d7785;margin-bottom:10px}.system-crumb b{color:#17243a}.system-titlebar h1{margin:0 0 8px;font-size:22px}.system-titlebar p{margin:0;color:#617083}.title-actions{display:flex;gap:10px;align-items:flex-start}
.role-workbench{display:grid;grid-template-columns:380px minmax(760px,1fr);gap:12px}.role-list-card,.role-editor{background:#fff;border:1px solid #dde5ee;border-radius:4px}.role-list-card{padding:14px}.card-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}.card-head h2{margin:0;font-size:16px}.role-list-card ::v-deep .el-input{margin-bottom:12px}.role-list-card strong{display:block;font-size:13px}.role-list-card small{display:block;color:#64748b;margin-top:4px}.role-pager{height:54px;display:flex;align-items:center;justify-content:space-between;color:#667487;border-top:1px solid #edf1f5;padding-top:10px}.role-pager ::v-deep .el-pagination{padding:0}
.role-editor{position:relative;min-height:780px;padding-bottom:76px}.current-role-strip{height:42px;margin:16px 16px 0;padding:0 14px;display:flex;align-items:center;gap:26px;background:#edf9f2;border-radius:4px;color:#334155}.current-role-strip>span:first-child{margin-right:auto}.current-role-strip i{color:#07883f}.current-role-strip small{color:#617083}.role-status{padding:2px 8px;border-radius:3px;background:#dcfce7;color:#07883f}.role-status.disabled{background:#f1f5f9;color:#64748b}.permission-heading{margin:18px 16px 0;padding-bottom:12px;display:flex;align-items:end;justify-content:space-between;border-bottom:1px solid #dde5ee}.permission-heading h2{margin:0;font-size:17px}.permission-heading span{color:#778396;font-size:13px}
.permission-zone{padding:16px}.permission-zone h3,.lower-zone h3{margin:0 0 12px;font-size:15px}.permission-zone small,.lower-zone small{font-weight:400;color:#778396;margin-left:6px}.menu-tree-box ::v-deep .el-tree{height:400px;overflow:auto;padding:10px;border:1px solid #edf1f5;border-radius:4px}.tree-actions{display:flex;align-items:center;gap:8px;margin-top:10px}
.lower-zone{display:grid;grid-template-columns:300px minmax(0,1fr);gap:14px;padding:0 16px 20px}.scope-card,.description-card{min-height:190px;padding:16px;background:#fff;border:1px solid #e2e8f0;border-radius:4px}.scope-card ::v-deep .el-radio{display:block;margin:0 0 18px}.scope-card b,.scope-card small{display:block}.scope-card small{margin:4px 0 0 24px}.description-card p{color:#617083;line-height:1.8}.description-card h4{margin:20px 0 8px}.scope-preview{padding:10px;background:#eaf4ff;border:1px solid #cfe5ff;color:#2d6eb8;border-radius:4px}.sample-users{font-size:13px}
.tree-node-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  width: 100%;
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
  color: #1677ff;
  font-size: 15px;
}
.node-main.level-2 .node-icon {
  color: #008b4b;
  font-size: 14px;
}
.node-main.level-3 .node-icon {
  color: #f59e0b;
  font-size: 13px;
}
.node-main.level-1 .node-label {
  font-weight: 700;
  color: #111827;
}
.node-main.level-2 .node-label {
  font-weight: 500;
  color: #1f2937;
}
.node-main.level-3 .node-label {
  color: #4b5563;
}
.node-label {
  color: #1f2937;
}

.badge-tag {
  display: inline-block;
  padding: 0 5px;
  border-radius: 3px;
  font-size: 10px;
  line-height: 16px;
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
  padding: 1px 5px;
  background: #f3f4f6;
  color: #6b7280;
  border-radius: 3px;
  font-family: Consolas, monospace;
  font-size: 11px;
  margin-left: auto;
  margin-right: 12px;
}

.rbac-perm-tree ::v-deep .el-tree-node__content {
  height: 32px;
}
.rbac-perm-tree ::v-deep .el-tree-node__content:hover {
  background: #f3f4f6;
}
@media(max-width:767px){
  .system-frame{padding:14px 12px}
  .system-titlebar{gap:12px}
  .system-titlebar p{line-height:1.6}
  .role-workbench{grid-template-columns:minmax(0,1fr)}
  .role-list-card,.role-editor{min-width:0}
  .role-editor{min-height:0}
  .current-role-strip{height:auto;min-height:42px;flex-wrap:wrap;gap:8px 16px;padding:10px 14px}
  .current-role-strip>span:first-child{width:100%;margin-right:0}
  .permission-heading{align-items:flex-start;flex-direction:column;gap:5px}
  .menu-tree-box ::v-deep .el-tree{max-width:100%}
  .tree-node-row{min-width:620px}
  .lower-zone{grid-template-columns:minmax(0,1fr)}
  .sticky-actions{position:static;flex-wrap:wrap}
}
</style>
