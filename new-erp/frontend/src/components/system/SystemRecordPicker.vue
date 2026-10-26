<template>
  <el-dialog class="system-picker-shell" :title="config.title || recordConfig.title" :visible="visible" append-to-body top="5vh" :width="request ? '960px' : '760px'" :custom-class="request ? 'system-record-picker paged-record-picker' : 'system-record-picker'" :close-on-click-modal="false" @close="$emit('update:visible', false)" @open="open">
    <div class="record-picker-body" :class="{ 'with-categories': categories.length }">
    <aside v-if="categories.length" class="record-picker-categories"><el-button type="text" @click="selectCategory(null)">全部分类</el-button><el-tree :data="categories" node-key="id" :props="{ label: 'category_name', children: 'children' }" highlight-current @node-click="selectCategory" /></aside>
    <div class="record-picker-table">
    <div class="picker-search">
      <el-input v-model="keyword" clearable :placeholder="config.placeholder || recordConfig.placeholder" @keyup.enter.native="query" />
      <el-button type="success" icon="el-icon-search" @click="query">查询</el-button>
      <el-button @click="reset">重置</el-button>
    </div>
    <el-alert v-if="error" :title="error" type="error" show-icon :closable="false" />
    <el-table v-loading="loading" :data="pageRows" border max-height="380" @row-click="toggle">
      <el-table-column label="选择" width="58" align="center"><template slot-scope="{ row }"><el-radio v-if="singleRecord" class="operation-choice" :value="selectedRows[0] && selectedRows[0][valueKey]" :label="row[valueKey]" :aria-label="'选择：' + displayName(row)" @click.native.stop @change="toggle(row)">&nbsp;</el-radio><el-checkbox v-else :value="!!selected[row[valueKey]]" @click.native.stop @change="toggle(row)" /></template></el-table-column>
      <template v-if="columns.length"><el-table-column v-for="column in columns" :key="column.prop" :prop="column.prop" :label="column.label" :min-width="column.width || 140"><template slot-scope="{ row }">{{ columnValue(row, column) }}</template></el-table-column></template>
      <template v-else><el-table-column :prop="recordConfig.code" :label="recordConfig.codeLabel" min-width="140" />
      <el-table-column :label="recordConfig.nameLabel" min-width="150"><template slot-scope="{ row }">{{ displayName(row) }}</template></el-table-column></template>
      <el-table-column v-if="!request && type === 'users'" label="所属部门" min-width="180"><template slot-scope="{ row }">{{ departmentNames(row) }}</template></el-table-column>
      <el-table-column v-else-if="!request && type === 'roles'" label="数据范围" min-width="100"><template slot-scope="{ row }">{{ scopeText(row.data_scope) }}</template></el-table-column>
      <el-table-column v-else-if="!request && type === 'operations'" label="公共标识" min-width="100"><template slot-scope="{ row }"><el-tag v-if="row.is_public" size="mini" type="success">公共工序</el-tag><span v-else>普通工序</span></template></el-table-column>
      <el-table-column v-else-if="!request && type === 'locations'" label="区域 / 用途" min-width="140"><template slot-scope="{ row }">{{ row.area || '—' }} / {{ locationPurposeText(row.location_purpose) }}</template></el-table-column>
    </el-table>
    <div class="picker-pagination"><span v-if="localRows.length">查询结果 {{ total }} 条 · 当前单位 {{ matchingLocalRows.length }} 项</span><span v-else>共 {{ total }} 条</span><el-pagination background small layout="prev, pager, next" :page-size="pageSize" :current-page="page" :total="total" @current-change="changePage" /></div>
    </div></div>
    <div class="selected-records"><span>已选择 {{ selectedRows.length }} 项</span><el-tag v-for="row in selectedRows" :key="row[valueKey]" closable @close="remove(row[valueKey])">{{ displayName(row) }}</el-tag></div>
    <span slot="footer"><el-button @click="$emit('update:visible', false)">取消</el-button><el-button type="success" :disabled="loading || !!error || (singleRecord && selectedRows.length !== 1)" @click="confirm">确认选择</el-button></span>
  </el-dialog>
</template>

<script>
import { listRoleOptions, listSystemUserOptions } from '@/api/erp/rbac'
import { searchProductionOptions } from '@/api/erp/production'
import { listEntity } from '@/api/erp/master'
import { parseSystemArray } from '@/utils/systemManagement'
import '@/styles/system-management.css'

export default {
  props: {
    visible: Boolean,
    request: Function,
    params: { type: Object, default: () => ({}) },
    config: { type: Object, default: () => ({}) },
    columns: { type: Array, default: () => [] },
    localRows: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    label: Function,
    valueKey: { type: String, default: 'id' },
    type: { type: String, default: 'users' },
    multiple: { type: Boolean, default: true },
    roleStatus: { type: String, default: 'enabled' },
    value: { type: Array, default: () => [] },
    departmentId: { type: Number, default: 0 },
    warehouseId: { type: Number, default: 0 },
    locationPurpose: { type: String, default: '' },
    locationPurposes: { type: Array, default: () => [] },
    managementScope: { type: String, default: '' }
  },
  data: () => ({ rows: [], selected: {}, keyword: '', categoryId: null, page: 1, pageSize: 10, total: 0, loading: false, error: '', requestSerial: 0 }),
  computed: {
    matchingLocalRows() { const keyword = this.keyword.trim().toLowerCase(); return this.localRows.filter(row => !keyword || [this.displayName(row), ...this.columns.map(column => this.columnValue(row, column))].join(' ').toLowerCase().includes(keyword)) },
    pageRows() { return [...this.matchingLocalRows, ...this.rows.filter(row => !this.matchingLocalRows.some(local => String(local[this.valueKey]) === String(row[this.valueKey])))] },
    selectedRows() { return Object.values(this.selected) },
    masterRecord() { return ['warehouses', 'locations'].includes(this.type) },
    singleRecord() { return this.request ? !this.multiple : this.type === 'operations' || this.masterRecord },
    recordConfig() {
      return {
        operations: { title: '选择工序', code: 'operation_no', codeLabel: '工序编码', nameLabel: '工序名称', placeholder: '工序编码 / 名称' },
        roles: { title: '选择角色', code: 'code', codeLabel: '角色编码', nameLabel: '角色名称', placeholder: '角色编码 / 名称' },
        warehouses: { title: '选择仓库', code: 'warehouse_code', codeLabel: '仓库编码', nameLabel: '仓库名称', placeholder: '仓库编码 / 名称' },
        locations: { title: '选择库位', code: 'location_code', codeLabel: '库位编码', nameLabel: '库位名称', placeholder: '库位编码 / 名称' }
      }[this.type] || { title: '选择管理员', code: 'username', codeLabel: '登录账号', nameLabel: '姓名', placeholder: '姓名 / 登录账号 / 手机号' }
    }
  },
  watch: {
    visible(value) { if (!value) this.requestSerial += 1 },
    params: { deep: true, handler(value, previous) { if (this.request && JSON.stringify(value) !== JSON.stringify(previous)) { this.requestSerial += 1; this.selected = {}; this.rows = []; this.page = 1; if (this.visible) this.load() } } }
  },
  methods: {
    open() {
      this.selected = this.value.reduce((map, row) => ({ ...map, [row[this.valueKey]]: { ...row } }), {})
      this.keyword = ''; this.categoryId = null; this.page = 1; this.error = ''; this.rows = []
      this.load()
    },
    async load() {
      const serial = ++this.requestSerial
      this.loading = true; this.error = ''
      try {
        if (!this.request && this.type === 'locations' && !this.warehouseId) throw new Error('请先选择仓库')
        const params = { keyword: this.keyword, page: this.page, per_page: this.pageSize }
        const { data } = await (this.request ? this.request({ ...this.params, ...params, ...(this.categoryId ? { category_id: this.categoryId } : {}) }) : this.masterRecord
          ? listEntity(this.type, { ...params, status: 'enabled', management_scope: this.managementScope || undefined, warehouse_id: this.type === 'locations' ? this.warehouseId : undefined, location_purpose: this.type === 'locations' ? this.locationPurpose || undefined : undefined, location_purposes: this.type === 'locations' && this.locationPurposes.length ? this.locationPurposes : undefined, include_location_summary: this.type === 'warehouses' ? 1 : undefined })
          : this.type === 'operations'
          ? searchProductionOptions('operations', params)
          : (this.type === 'roles' ? listRoleOptions : listSystemUserOptions)({ ...params, department_id: this.departmentId || undefined, status: this.roleStatus }))
        if (serial !== this.requestSerial || !this.visible) return
        const result = Array.isArray(data.data) ? data : data.data || data
        this.rows = result.data || []; this.total = Number(result.total || (result.meta && result.meta.total) || 0)
      } catch (error) {
        if (serial !== this.requestSerial || !this.visible) return
        this.rows = []; this.total = 0; this.error = error.userMessage || error.message || '选择列表加载失败'
      } finally { if (serial === this.requestSerial) this.loading = false }
    },
    query() { this.page = 1; this.load() },
    reset() { this.keyword = ''; this.categoryId = null; this.query() },
    selectCategory(row) { this.categoryId = row ? row.id : null; this.query() },
    changePage(page) { this.page = page; this.load() },
    toggle(row) {
      const id = row[this.valueKey]
      if (this.singleRecord) this.selected = { [id]: { ...row } }
      else if (this.selected[id]) this.$delete(this.selected, id)
      else if (!this.multiple) this.selected = { [id]: { ...row } }
      else this.$set(this.selected, id, { ...row })
    },
    remove(id) { this.$delete(this.selected, id) },
    displayName(row) { return this.label ? this.label(row) : this.type === 'operations' ? row.operation_name : this.type === 'warehouses' ? row.warehouse_name : this.type === 'locations' ? row.location_name : row.name || row.nickname || row.username || '-' },
    columnValue(row, column) { return column.format ? column.format(row) : column.prop.split('.').reduce((value, key) => value && value[key], row) ?? '—' },
    departmentNames(row) { return parseSystemArray(row.department_names).join('、') || '-' },
    scopeText(value) { return ({ all: '全部数据', department: '本部门数据', self: '本人数据' })[value] || '-' },
    locationPurposeText(value) { return ({ general: '普通存储', finished: '成品', shipping_staging: '待发', repair: '返修' })[value] || '普通存储' },
    confirm() { if (!this.visible || this.loading || this.error || (this.singleRecord && this.selectedRows.length !== 1)) return; this.$emit('confirm', this.selectedRows.map(row => ({ ...row }))); this.$emit('update:visible', false) }
  }
}
</script>

<style scoped>
.record-picker-body {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 16px;
}
.record-picker-body.with-categories {
  grid-template-columns: 200px minmax(0, 1fr);
}
.record-picker-categories {
  max-height: 440px;
  overflow: auto;
  border-right: 1px solid #e2e8f0;
  padding-right: 12px;
  box-sizing: border-box;
}
.record-picker-table {
  min-width: 0;
}
.system-picker-shell ::v-deep .paged-record-picker {
  width: min(960px, calc(100vw - 32px)) !important;
}
.picker-search {
  display: flex;
  gap: 10px;
  margin-bottom: 14px;
}
.picker-search > .el-input {
  flex: 1;
  min-width: 0;
}
.operation-choice ::v-deep .el-radio__input.is-checked .el-radio__inner {
  border-color: #008b4b;
  background: #008b4b;
}
.operation-choice ::v-deep .el-radio__inner:hover {
  border-color: #008b4b;
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
  .record-picker-body.with-categories {
    grid-template-columns: minmax(0, 1fr);
  }
  .record-picker-categories {
    max-height: 140px;
  }
  .picker-search { flex-wrap: wrap; }
  .picker-search > .el-input { flex-basis: 100%; }
}
</style>
