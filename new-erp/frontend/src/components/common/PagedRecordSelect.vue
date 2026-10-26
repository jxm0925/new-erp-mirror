<template>
  <div class="paged-record-select">
    <el-input :value="displayText" :placeholder="placeholder" :disabled="disabled" readonly :size="size" @click.native="open" @keydown.native.enter.prevent="open" @keydown.native.space.prevent="open">
      <template slot="suffix"><i v-if="clearable && values.length && !disabled" class="el-input__icon el-icon-circle-close" role="button" tabindex="0" aria-label="清空选择" @click.stop.prevent="clear" @keydown.enter.stop.prevent="clear" @keydown.space.stop.prevent="clear" /><i v-else class="el-input__icon el-icon-search" /></template>
    </el-input>
    <system-record-picker :visible.sync="visible" :request="loadRecords" :params="queryParams" :config="{ title, placeholder }" :columns="recordColumns" :categories="categories" :label="recordLabel" :value-key="valueKey" :multiple="multiple" :value="eligibleRows" :local-rows="localRows" @confirm="confirm" />
  </div>
</template>

<script>
import SystemRecordPicker from '@/components/system/SystemRecordPicker.vue'
import { getItemCategoryTree, listEntity } from '@/api/erp/master'
import emitter from 'element-ui/src/mixins/emitter'

export default {
  name: 'PagedRecordSelect',
  components: { SystemRecordPicker },
  mixins: [emitter],
  props: {
    value: { default: null },
    request: Function,
    categoryRequest: Function,
    entity: { type: String, default: '' },
    params: { type: Object, default: () => ({}) },
    label: Function,
    valueKey: { type: String, default: 'id' },
    columns: { type: Array, default: () => [] },
    selectedRows: { type: Array, default: () => [] },
    localRows: { type: Array, default: () => [] },
    title: { type: String, default: '选择记录' },
    placeholder: { type: String, default: '请选择' },
    multiple: Boolean,
    disabled: Boolean,
    clearable: { type: Boolean, default: true },
    size: { type: String, default: 'small' },
    categoryType: { type: String, default: '' },
    managementScope: { type: String, default: '' }
  },
  data: () => ({ visible: false, cache: {}, categories: [], resolveSerial: 0, openSerial: 0, resolveLoading: false, resolveError: '' }),
  computed: {
    values() { return (Array.isArray(this.value) ? this.value : [this.value]).filter(value => value !== null && value !== undefined && value !== '') },
    currentRows() { return this.values.map(value => this.localRows.find(row => String(row[this.valueKey]) === String(value)) || this.cache[value] || this.selectedRows.find(row => String(row[this.valueKey]) === String(value)) || { [this.valueKey]: value }) },
    eligibleRows() { return this.values.map(value => this.localRows.find(row => String(row[this.valueKey]) === String(value)) || this.cache[value]).filter(Boolean) },
    queryParams() { return this.managementScope ? { management_scope: this.managementScope, ...this.params } : this.params },
    requestContext() { return JSON.stringify([this.entity, this.valueKey, this.queryParams]) },
    recordFields() { return ({ products: ['product_code', 'product_name'], skus: ['sku_code', 'sku_name'], items: ['item_code', 'item_name'], units: ['unit_code', 'unit_name'], categories: ['category_code', 'category_name'], suppliers: ['supplier_code', 'supplier_name'], warehouses: ['warehouse_code', 'warehouse_name'], locations: ['location_code', 'location_name'] })[this.entity] || ['code', 'name'] },
    recordColumns() { return this.columns.length ? this.columns : [{ prop: this.recordFields[0], label: '编码' }, { prop: this.recordFields[1], label: '名称' }] },
    displayText() { return this.currentRows.map(row => Object.keys(row).length > 1 ? this.recordLabel(row) : this.resolveLoading ? '正在加载已选记录' : '已选记录不可用').join('、') }
  },
  watch: {
    value: { immediate: true, handler() { this.resolveSelected() } },
    requestContext() { this.openSerial += 1; this.visible = false; this.cache = {}; this.resolveSelected() },
    visible(value) { if (!value) this.openSerial += 1 }
  },
  beforeDestroy() { this.openSerial += 1; this.resolveSerial += 1 },
  methods: {
    loadRecords(params) { return this.request ? this.request(params) : listEntity(this.entity, params) },
    recordLabel(row) { return this.label ? this.label(row) : [row[this.recordFields[0]], row[this.recordFields[1]]].filter(Boolean).join(' / ') || row[this.valueKey] },
    remember(rows) {
      const fresh = (rows || []).filter(row => row && this.cache[row[this.valueKey]] !== row)
      if (!fresh.length) return
      fresh.forEach(row => { this.$set(this.cache, row[this.valueKey], row) })
      this.$emit('update:selectedRows', Array.from(new Map([...this.selectedRows, ...fresh].map(row => [row[this.valueKey], row])).values()))
    },
    async resolveSelected() {
      const serial = ++this.resolveSerial
      const ids = this.values.filter(value => !this.cache[value] && !this.localRows.some(row => String(row[this.valueKey]) === String(value)))
      this.resolveError = ''
      if (!ids.length) { this.resolveLoading = false; return }
      this.resolveLoading = true
      try {
        const { data } = await this.loadRecords({ ...this.queryParams, ids, page: 1, per_page: ids.length })
        if (serial !== this.resolveSerial) return
        const result = Array.isArray(data.data) ? data : data.data || data
        this.remember(result.data || [])
        if (ids.some(value => !this.cache[value] && ![...this.selectedRows, ...this.localRows].some(row => String(row[this.valueKey]) === String(value)))) this.resolveError = '部分已选记录当前不可用，请重新选择'
      } catch (error) {
        if (serial === this.resolveSerial) this.resolveError = error.userMessage || '已选记录加载失败'
      } finally {
        if (serial === this.resolveSerial) { this.resolveLoading = false; if (this.resolveError) this.$message.error(this.resolveError) }
      }
    },
    async open() {
      if (this.disabled) return
      const serial = ++this.openSerial
      await this.resolveSelected()
      if (serial !== this.openSerial || this.disabled) return
      if (this.categoryRequest || this.categoryType) {
        try {
          const { data } = await (this.categoryRequest ? this.categoryRequest() : this.categoryType === 'product' ? listEntity('categories', { category_type: 'product', tree: 1 }) : getItemCategoryTree({ management_scope: this.managementScope || this.params.management_scope || undefined }))
          if (serial !== this.openSerial) return
          this.categories = data.data || []
        } catch (error) {
          if (serial !== this.openSerial) return
          this.categories = []
          this.$message.error(error.userMessage || '分类加载失败')
        }
      }
      this.visible = true
    },
    confirm(rows) {
      this.remember(rows)
      const value = this.multiple ? rows.map(row => row[this.valueKey]) : rows[0] && rows[0][this.valueKey]
      this.$emit('input', value)
      this.$emit('change', value, rows)
      this.$emit('select', this.multiple ? rows : rows[0])
      this.dispatch('ElFormItem', 'el.form.change', [value])
    },
    clear() {
      this.openSerial += 1
      this.visible = false
      this.$emit('input', this.multiple ? [] : null)
      this.$emit('change', this.multiple ? [] : null, [])
      this.$emit('select', this.multiple ? [] : null)
      this.dispatch('ElFormItem', 'el.form.change', [this.multiple ? [] : null])
    }
  }
}
</script>

<style scoped>
.paged-record-select {
  display: inline-block;
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
  vertical-align: middle;
}
.paged-record-select ::v-deep .el-input__inner,
.paged-record-select ::v-deep .el-icon-circle-close {
  cursor: pointer;
}
</style>
