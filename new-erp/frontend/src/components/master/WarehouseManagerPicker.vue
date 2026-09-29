<template>
  <el-dialog title="选择仓管负责人" :visible="visible" append-to-body width="min(820px, calc(100vw - 32px))" top="8vh" custom-class="warehouse-manager-picker" :close-on-click-modal="false" @close="close">
    <div class="manager-picker-layout">
      <aside class="manager-departments">
        <el-tree :data="departmentTree" node-key="id" :props="{ label: 'name', children: 'children' }" default-expand-all highlight-current :current-node-key="departmentId" @node-click="selectDepartment" />
      </aside>
      <div class="manager-results">
        <form class="manager-search" @submit.prevent="search">
          <el-input v-model.trim="keyword" size="small" placeholder="搜索员工姓名 / 账号" clearable @clear="search" />
          <el-button size="small" native-type="submit">查询</el-button>
        </form>
        <el-alert v-if="error" :title="error" type="error" :closable="false" />
        <el-table v-loading="loading" :data="rows" size="small" border max-height="330" highlight-current-row @row-click="selected = $event">
          <el-table-column label="选择" width="56" align="center"><template slot-scope="{ row }"><el-radio :value="selected && selected.id" :label="row.id" @change="selected = row"><span class="sr-only">{{ row.nickname || row.username }}</span></el-radio></template></el-table-column>
          <el-table-column label="员工姓名" min-width="110"><template slot-scope="{ row }">{{ row.nickname || row.username }}</template></el-table-column>
          <el-table-column prop="username" label="账号" min-width="105" />
          <el-table-column label="部门" min-width="120"><template slot-scope="{ row }">{{ departmentsText(row.department_names) }}</template></el-table-column>
        </el-table>
        <el-pagination small :current-page="page" :page-size="20" :total="total" layout="total, prev, pager, next" @current-change="changePage" />
        <div class="manager-selection">已选：{{ selected ? `${selected.nickname || selected.username}（${selected.username || selected.id}）` : '未选择' }}</div>
      </div>
    </div>
    <span slot="footer"><el-button size="small" @click="close">取消</el-button><el-button size="small" type="success" :disabled="!selected || loading" @click="confirm">确认选择</el-button></span>
  </el-dialog>
</template>

<script>
import { listUsers } from '../../api/erp/rbac'

export default {
  name: 'WarehouseManagerPicker',
  props: { visible: Boolean, current: { type: Object, default: null } },
  data: () => ({ rows: [], departments: [], selected: null, keyword: '', page: 1, total: 0, departmentId: 0, loading: false, error: '', requestSerial: 0 }),
  computed: {
    departmentTree () {
      const nodes = this.departments.map(d => ({ ...d, children: [] }))
      const byId = new Map(nodes.map(d => [Number(d.id), d]))
      const roots = []
      nodes.forEach(d => {
        const parent = byId.get(Number(d.parent_id))
        if (parent && parent !== d) parent.children.push(d)
        else roots.push(d)
      })
      return [{ id: 0, name: '全部员工', children: roots }]
    }
  },
  watch: {
    visible (value) {
      if (!value) { this.requestSerial++; return }
      this.selected = this.current ? { ...this.current } : null
      this.rows = []; this.departments = []; this.keyword = ''; this.page = 1; this.total = 0; this.departmentId = 0; this.error = ''
      this.load(true)
    }
  },
  beforeDestroy () { this.requestSerial++ },
  methods: {
    async load (includeDepartments = false) {
      const serial = ++this.requestSerial
      this.loading = true; this.error = ''; this.rows = []
      try {
        const { data } = await listUsers({ scope: 'warehouse', keyword: this.keyword, department_id: this.departmentId || undefined, page: this.page, per_page: 20, include_departments: includeDepartments ? 1 : undefined })
        if (serial !== this.requestSerial || !this.visible) return
        this.rows = data.data || []; this.total = data.meta.total
        if (data.departments) this.departments = data.departments
      } catch (e) {
        if (serial === this.requestSerial) { this.error = e.userMessage || '员工列表加载失败，请重新查询'; this.total = 0 }
      } finally { if (serial === this.requestSerial) this.loading = false }
    },
    search () { this.page = 1; this.load() },
    selectDepartment (department) { this.departmentId = department.id; this.search() },
    changePage (page) { this.page = page; this.load() },
    departmentsText (value) {
      try { return (Array.isArray(value) ? value : JSON.parse(value || '[]')).join('、') || '-' } catch (_) { return value || '-' }
    },
    close () { this.$emit('update:visible', false) },
    confirm () { this.$emit('selected', this.selected); this.close() }
  }
}
</script>

<style scoped>
.manager-picker-layout{display:grid;grid-template-columns:170px minmax(0,1fr);gap:16px;max-height:65vh;overflow:auto}.manager-departments{overflow:auto;border-right:1px solid #ebeef5}.manager-results{min-width:0}.manager-search{display:flex;gap:8px;margin-bottom:12px}.manager-selection{margin-top:12px;overflow-wrap:anywhere}.el-pagination{max-width:100%;overflow:auto;margin-top:12px}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
@media(max-width:600px){.manager-picker-layout{grid-template-columns:minmax(0,1fr)}.manager-departments{max-height:100px;border-right:0;border-bottom:1px solid #ebeef5}}
</style>
