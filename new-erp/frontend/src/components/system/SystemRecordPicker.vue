<template>
  <el-dialog :title="recordConfig.title" :visible="visible" append-to-body top="5vh" width="760px" custom-class="system-record-picker" :close-on-click-modal="false" @close="$emit('update:visible', false)" @open="open">
    <div class="picker-search">
      <el-input v-model="keyword" clearable :placeholder="recordConfig.placeholder" @keyup.enter.native="query" />
      <el-button type="success" icon="el-icon-search" @click="query">查询</el-button>
      <el-button @click="reset">重置</el-button>
    </div>
    <el-alert v-if="error" :title="error" type="error" show-icon :closable="false" />
    <el-table v-loading="loading" :data="rows" border max-height="380" @row-click="toggle">
      <el-table-column label="选择" width="58" align="center"><template slot-scope="{ row }"><el-radio v-if="singleRecord" class="operation-choice" :value="selectedRows[0] && selectedRows[0].id" :label="row.id" :aria-label="'选择：' + displayName(row)" @click.native.stop @change="toggle(row)">&nbsp;</el-radio><el-checkbox v-else :value="!!selected[row.id]" @click.native.stop @change="toggle(row)" /></template></el-table-column>
      <el-table-column :prop="recordConfig.code" :label="recordConfig.codeLabel" min-width="140" />
      <el-table-column :label="recordConfig.nameLabel" min-width="150"><template slot-scope="{ row }">{{ displayName(row) }}</template></el-table-column>
      <el-table-column v-if="type === 'users'" label="所属部门" min-width="180"><template slot-scope="{ row }">{{ departmentNames(row) }}</template></el-table-column>
      <el-table-column v-else-if="type === 'roles'" label="数据范围" min-width="100"><template slot-scope="{ row }">{{ scopeText(row.data_scope) }}</template></el-table-column>
      <el-table-column v-else-if="type === 'operations'" label="公共标识" min-width="100"><template slot-scope="{ row }"><el-tag v-if="row.is_public" size="mini" type="success">公共工序</el-tag><span v-else>普通工序</span></template></el-table-column>
      <el-table-column v-else-if="type === 'locations'" label="区域 / 用途" min-width="140"><template slot-scope="{ row }">{{ row.area || '—' }} / {{ locationPurposeText(row.location_purpose) }}</template></el-table-column>
    </el-table>
    <div class="picker-pagination"><span>共 {{ total }} 条</span><el-pagination background small layout="prev, pager, next" :page-size="pageSize" :current-page="page" :total="total" @current-change="changePage" /></div>
    <div class="selected-records"><span>已选择 {{ selectedRows.length }} 项</span><el-tag v-for="row in selectedRows" :key="row.id" closable @close="remove(row.id)">{{ displayName(row) }}</el-tag></div>
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
  data: () => ({ rows: [], selected: {}, keyword: '', page: 1, pageSize: 10, total: 0, loading: false, error: '', requestSerial: 0 }),
  computed: {
    selectedRows() { return Object.values(this.selected) },
    masterRecord() { return ['warehouses', 'locations'].includes(this.type) },
    singleRecord() { return this.type === 'operations' || this.masterRecord },
    recordConfig() {
      return {
        operations: { title: '选择工序', code: 'operation_no', codeLabel: '工序编码', nameLabel: '工序名称', placeholder: '工序编码 / 名称' },
        roles: { title: '选择角色', code: 'code', codeLabel: '角色编码', nameLabel: '角色名称', placeholder: '角色编码 / 名称' },
        warehouses: { title: '选择仓库', code: 'warehouse_code', codeLabel: '仓库编码', nameLabel: '仓库名称', placeholder: '仓库编码 / 名称' },
        locations: { title: '选择库位', code: 'location_code', codeLabel: '库位编码', nameLabel: '库位名称', placeholder: '库位编码 / 名称' }
      }[this.type] || { title: '选择管理员', code: 'username', codeLabel: '登录账号', nameLabel: '姓名', placeholder: '姓名 / 登录账号 / 手机号' }
    }
  },
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
        if (this.type === 'locations' && !this.warehouseId) throw new Error('请先选择仓库')
        const params = { keyword: this.keyword, page: this.page, per_page: this.pageSize }
        const { data } = await (this.masterRecord
          ? listEntity(this.type, { ...params, status: 'enabled', management_scope: this.managementScope || undefined, warehouse_id: this.type === 'locations' ? this.warehouseId : undefined, location_purpose: this.type === 'locations' ? this.locationPurpose || undefined : undefined, location_purposes: this.type === 'locations' && this.locationPurposes.length ? this.locationPurposes : undefined, include_location_summary: this.type === 'warehouses' ? 1 : undefined })
          : this.type === 'operations'
          ? searchProductionOptions('operations', params)
          : (this.type === 'roles' ? listRoleOptions : listSystemUserOptions)({ ...params, department_id: this.departmentId || undefined, status: this.roleStatus }))
        if (serial !== this.requestSerial || !this.visible) return
        this.rows = data.data || []; this.total = this.type === 'operations' || this.masterRecord ? data.total : data.meta.total
      } catch (error) {
        if (serial !== this.requestSerial || !this.visible) return
        this.rows = []; this.total = 0; this.error = error.userMessage || error.message || '选择列表加载失败'
      } finally { if (serial === this.requestSerial) this.loading = false }
    },
    query() { this.page = 1; this.load() },
    reset() { this.keyword = ''; this.query() },
    changePage(page) { this.page = page; this.load() },
    toggle(row) {
      if (this.singleRecord) this.selected = { [row.id]: { ...row } }
      else if (this.selected[row.id]) this.$delete(this.selected, row.id)
      else if (!this.multiple) this.selected = { [row.id]: { ...row } }
      else this.$set(this.selected, row.id, { ...row })
    },
    remove(id) { this.$delete(this.selected, id) },
    displayName(row) { return this.type === 'operations' ? row.operation_name : this.type === 'warehouses' ? row.warehouse_name : this.type === 'locations' ? row.location_name : row.name || row.nickname || row.username || '-' },
    departmentNames(row) { return parseSystemArray(row.department_names).join('、') || '-' },
    scopeText(value) { return ({ all: '全部数据', department: '本部门数据', self: '本人数据' })[value] || '-' },
    locationPurposeText(value) { return ({ general: '普通存储', finished: '成品', shipping_staging: '待发', repair: '返修' })[value] || '普通存储' },
    confirm() { if (this.singleRecord && this.selectedRows.length !== 1) return; this.$emit('confirm', this.selectedRows.map(row => ({ ...row }))); this.$emit('update:visible', false) }
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
  .picker-search { flex-wrap: wrap; }
  .picker-search > .el-input { flex-basis: 100%; }
}
</style>
