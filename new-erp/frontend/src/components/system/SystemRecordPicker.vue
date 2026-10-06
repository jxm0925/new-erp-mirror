<template>
  <el-dialog :title="type === 'roles' ? '选择角色' : '选择管理员'" :visible="visible" append-to-body top="5vh" width="760px" custom-class="system-record-picker" :close-on-click-modal="false" @close="$emit('update:visible', false)" @open="open">
    <div class="picker-search">
      <el-input v-model="keyword" clearable :placeholder="type === 'roles' ? '角色编码 / 名称' : '姓名 / 登录账号 / 手机号'" @keyup.enter.native="query" />
      <el-button type="success" icon="el-icon-search" @click="query">查询</el-button>
      <el-button @click="reset">重置</el-button>
    </div>
    <el-alert v-if="error" :title="error" type="error" show-icon :closable="false" />
    <el-table v-loading="loading" :data="rows" border max-height="380" @row-click="toggle">
      <el-table-column label="选择" width="58" align="center"><template slot-scope="{ row }"><el-checkbox :value="!!selected[row.id]" @click.native.stop @change="toggle(row)" /></template></el-table-column>
      <el-table-column :prop="type === 'roles' ? 'code' : 'username'" :label="type === 'roles' ? '角色编码' : '登录账号'" min-width="140" />
      <el-table-column :label="type === 'roles' ? '角色名称' : '姓名'" min-width="150"><template slot-scope="{ row }">{{ displayName(row) }}</template></el-table-column>
      <el-table-column v-if="type === 'users'" label="所属部门" min-width="180"><template slot-scope="{ row }">{{ departmentNames(row) }}</template></el-table-column>
      <el-table-column v-else label="数据范围" min-width="100"><template slot-scope="{ row }">{{ scopeText(row.data_scope) }}</template></el-table-column>
    </el-table>
    <div class="picker-pagination"><span>共 {{ total }} 条</span><el-pagination background small layout="prev, pager, next" :page-size="pageSize" :current-page="page" :total="total" @current-change="changePage" /></div>
    <div class="selected-records"><span>已选择 {{ selectedRows.length }} 项</span><el-tag v-for="row in selectedRows" :key="row.id" closable @close="remove(row.id)">{{ displayName(row) }}</el-tag></div>
    <span slot="footer"><el-button @click="$emit('update:visible', false)">取消</el-button><el-button type="success" :disabled="loading || !!error" @click="confirm">确认选择</el-button></span>
  </el-dialog>
</template>

<script>
import { listRoleOptions, listSystemUserOptions } from '@/api/erp/rbac'
import { parseSystemArray } from '@/utils/systemManagement'

export default {
  props: {
    visible: Boolean,
    type: { type: String, default: 'users' },
    multiple: { type: Boolean, default: true },
    roleStatus: { type: String, default: 'enabled' },
    value: { type: Array, default: () => [] },
    departmentId: { type: Number, default: 0 }
  },
  data: () => ({ rows: [], selected: {}, keyword: '', page: 1, pageSize: 10, total: 0, loading: false, error: '', requestSerial: 0 }),
  computed: { selectedRows() { return Object.values(this.selected) } },
  watch: { visible(value) { if (!value) this.requestSerial += 1 } },
  methods: {
    open() {
      this.selected = this.value.reduce((map, row) => ({ ...map, [row.id]: { ...row } }), {})
      this.keyword = ''; this.page = 1; this.error = ''; this.rows = []
      this.load()
    },
    async load() {
      const serial = ++this.requestSerial
      this.loading = true; this.error = ''
      try {
        const { data } = await (this.type === 'roles' ? listRoleOptions : listSystemUserOptions)({ keyword: this.keyword, department_id: this.departmentId || undefined, status: this.roleStatus, page: this.page, per_page: this.pageSize })
        if (serial !== this.requestSerial || !this.visible) return
        this.rows = data.data || []; this.total = data.meta.total
      } catch (error) {
        if (serial !== this.requestSerial || !this.visible) return
        this.rows = []; this.total = 0; this.error = error.userMessage || '选择列表加载失败'
      } finally { if (serial === this.requestSerial) this.loading = false }
    },
    query() { this.page = 1; this.load() },
    reset() { this.keyword = ''; this.query() },
    changePage(page) { this.page = page; this.load() },
    toggle(row) {
      if (this.selected[row.id]) this.$delete(this.selected, row.id)
      else if (!this.multiple) this.selected = { [row.id]: { ...row } }
      else this.$set(this.selected, row.id, { ...row })
    },
    remove(id) { this.$delete(this.selected, id) },
    displayName(row) { return row.name || row.nickname || row.username || '-' },
    departmentNames(row) { return parseSystemArray(row.department_names).join('、') || '-' },
    scopeText(value) { return ({ all: '全部数据', department: '本部门数据', self: '本人数据' })[value] || '-' },
    confirm() { this.$emit('confirm', this.selectedRows.map(row => ({ ...row }))); this.$emit('update:visible', false) }
  }
}
</script>

<style scoped>
.picker-search {
  display: flex;
  gap: 10px;
  margin-bottom: 14px;
}
.picker-search > .el-input {
  flex: 1;
  min-width: 0;
}
.picker-pagination {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin: 12px 0;
}
.selected-records {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding-top: 12px;
  border-top: 1px solid #e2e8f0;
}
@media (max-width: 600px) {
  .picker-search { flex-wrap: wrap; }
  .picker-search > .el-input { flex-basis: 100%; }
}
</style>
