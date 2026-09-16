<template>
  <el-dialog
    class="purchase-item-picker"
    :title="dialogTitle"
    :visible.sync="visible"
    width="1080px"
    append-to-body
    :close-on-click-modal="false"
  >
    <div class="picker-filters">
      <el-input
        v-model.trim="query.keyword"
        size="small"
        clearable
        placeholder="Item编码 / 名称 / 规格型号"
        @keyup.enter.native="search"
        @clear="search"
      />
      <el-select v-model="query.item_type" size="small" clearable placeholder="物料类型" @change="search">
        <el-option label="成品" value="finished_product" />
        <el-option label="半成品" value="semi_finished" />
        <el-option label="原材料" value="raw_material" />
        <el-option label="包装物" value="packaging" />
        <el-option label="服务" value="service" />
      </el-select>
      <el-button size="small" type="success" icon="el-icon-search" @click="search">查询</el-button>
      <el-button size="small" @click="reset">重置</el-button>
    </div>

    <div class="picker-tip">{{ dialogTip }}</div>
    <div class="picker-body">
      <aside class="category-panel">
        <strong>物料分类</strong>
        <button type="button" :class="{active:!query.category_id}" @click="selectCategory(null)">全部分类</button>
        <el-tree :data="categoryTree" node-key="id" :props="{label:'category_name',children:'children'}" :expand-on-click-node="false" default-expand-all @node-click="selectCategory" />
      </aside>

    <el-table
      ref="table"
      v-loading="loading"
      :data="rows"
      border
      size="mini"
      height="420"
      highlight-current-row
      @row-click="selectRow"
      @row-dblclick="confirm"
    >
      <el-table-column width="54" align="center">
        <template slot-scope="{row}">
          <el-checkbox v-if="multiple" :value="Boolean(selectedById[row.id])" @click.native.stop @change="toggleRow(row)" />
          <el-radio v-else v-model="currentId" :label="row.id">&nbsp;</el-radio>
        </template>
      </el-table-column>
      <el-table-column prop="item_code" label="Item编码" width="150" />
      <el-table-column prop="item_name" label="Item名称" min-width="210" />
      <el-table-column label="规格型号" min-width="170"><template slot-scope="{row}">{{ specification(row) }}</template></el-table-column>
      <el-table-column label="物料类型" width="95"><template slot-scope="{row}">{{ itemTypeText(row.item_type) }}</template></el-table-column>
      <el-table-column label="分类" width="140"><template slot-scope="{row}">{{ categoryName(row) }}</template></el-table-column>
      <el-table-column label="基本单位" width="80"><template slot-scope="{row}">{{ unitName(row) }}</template></el-table-column>
      <el-table-column label="状态" width="80"><template slot-scope="{row}"><el-tag size="mini" type="success">{{ statusText(row.status) }}</el-tag></template></el-table-column>
    </el-table>
    </div>

    <div v-if="multiple && selectedRows.length" class="selected-review">
      <strong>已选 {{ selectedRows.length }} 项</strong>
      <el-tag v-for="row in selectedRows" :key="row.id" size="mini" closable @close="removeSelected(row)">{{ row.item_code }} / {{ row.item_name }}</el-tag>
    </div>

    <div class="picker-footer">
      <el-pagination
        background
        layout="total, sizes, prev, pager, next"
        :current-page.sync="query.page"
        :page-size.sync="query.per_page"
        :page-sizes="[10, 20, 50]"
        :total="total"
        @current-change="load"
        @size-change="changeSize"
      />
      <div class="picker-actions">
        <el-button size="small" @click="visible=false">取消</el-button>
        <el-button size="small" type="success" :disabled="multiple ? !selectedRows.length : !current" @click="confirm()">{{ multiple ? '批量带回' : '确定选择' }}</el-button>
      </div>
    </div>
  </el-dialog>
</template>

<script>
import { getItemCategoryTree, listEntity } from '@/api/erp/master'

export default {
  name: 'PurchaseItemPicker',
  data: () => ({
    visible: false,
    loading: false,
    rows: [],
    total: 0,
    current: null,
    preferredId: null,
    multiple: false,
    dialogTitle: '选择采购物料',
    dialogTip: '仅查询新系统中已启用、允许采购的 Item；支持单击选中、双击直接确认。',
    selectedById: {},
    categoryTree: [],
    extraParams: {},
    query: { keyword: '', item_type: '', category_id: null, page: 1, per_page: 10 }
  }),
  computed: {
    selectedRows() { return Object.values(this.selectedById) },
    currentId: {
      get() { return this.current && this.current.id },
      set(id) { this.current = this.rows.find(row => Number(row.id) === Number(id)) || null }
    }
  },
  methods: {
    async open({ currentId = null, params = {}, multiple = false, selected = [], title = '选择采购物料', tip = '仅查询新系统中已启用、允许采购的 Item；支持单击选中、双击直接确认。' } = {}) {
      this.preferredId = currentId
      this.multiple = multiple
      this.dialogTitle = title
      this.dialogTip = tip
      this.selectedById = Object.fromEntries((selected || []).filter(row => row && row.id).map(row => [row.id, row]))
      this.extraParams = { status: 'enabled', is_purchase_item: 1, ...params }
      this.query = { keyword: '', item_type: '', category_id: null, page: 1, per_page: 10 }
      this.current = null
      this.visible = true
      if (!this.categoryTree.length) {
        const { data } = await getItemCategoryTree()
        this.categoryTree = data.data || []
      }
      await this.load()
    },
    async load() {
      this.loading = true
      try {
        const params = { ...this.extraParams, ...this.query }
        if (!params.keyword) delete params.keyword
        if (!params.item_type) delete params.item_type
        const { data } = await listEntity('items', params)
        this.rows = data.data || []
        this.total = Number(data.total || 0)
        this.current = this.rows.find(row => Number(row.id) === Number(this.preferredId)) || null
      } catch (error) {
        this.$message.error(error.userMessage || '采购物料加载失败')
      } finally {
        this.loading = false
      }
    },
    search() { this.query.page = 1; this.preferredId = null; this.load() },
    reset() { this.query = { keyword: '', item_type: '', category_id: null, page: 1, per_page: this.query.per_page }; this.preferredId = null; this.load() },
    changeSize() { this.query.page = 1; this.load() },
    selectCategory(row) { if (row && !row.is_leaf && Array.isArray(row.children) && row.children.length) return; this.query.category_id = row ? row.id : null; this.search() },
    selectRow(row) { if (this.multiple) this.toggleRow(row); else this.current = row },
    toggleRow(row) { if (this.selectedById[row.id]) this.$delete(this.selectedById, row.id); else this.$set(this.selectedById, row.id, row) },
    removeSelected(row) { this.$delete(this.selectedById, row.id) },
    confirm(row) {
      if (row && this.multiple && !this.selectedById[row.id]) this.$set(this.selectedById, row.id, row)
      if (this.multiple) {
        if (!this.selectedRows.length) return this.$message.warning('请至少选择一个物料')
        this.$emit('select-multiple', this.selectedRows)
        this.visible = false
        return
      }
      if (row) this.current = row
      if (!this.current) return this.$message.warning('请先选择一个采购物料')
      this.$emit('select', this.current)
      this.visible = false
    },
    specification(row) { return row.spec || row.model || row.spec_model || row.specification || row.spec_text || '-' },
    categoryName(row) { return row.category?.category_name || row.category_name || '-' },
    unitName(row) {
      const unit = row.unit?.standard_unit || row.unit?.standardUnit || row.unit || row.inventory_unit
      return unit?.symbol || unit?.unit_name || unit?.unit_code || '-'
    },
    itemTypeText(value) { return ({ finished_product: '成品', finished_good: '成品', semi_finished: '半成品', raw_material: '原材料', packaging: '包装物', service: '服务' })[value] || value || '-' },
    statusText(value) { return ({ enabled: '启用', active: '启用', disabled: '停用' })[value] || value || '-' }
  }
}
</script>

<style scoped>
.picker-filters{display:grid;grid-template-columns:minmax(280px,1fr) 170px 82px 82px;gap:10px;align-items:center;margin-bottom:10px}.picker-tip{margin-bottom:10px;padding:8px 10px;border:1px solid #cfe5d8;border-radius:4px;background:#f3fbf6;color:#397052;font-size:12px}.picker-body{display:grid;grid-template-columns:210px minmax(0,1fr);gap:12px}.category-panel{height:420px;overflow:auto;border:1px solid #e2e8f0;border-radius:4px;padding:10px}.category-panel>strong{display:block;margin-bottom:8px}.category-panel>button{width:100%;border:0;background:transparent;padding:7px 8px;text-align:left;cursor:pointer}.category-panel>button.active{background:#eef8f2;color:#07883f}.selected-review{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:10px;padding:8px;border:1px solid #dbe8df;background:#f8fcf9}.picker-footer{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:12px}.picker-actions{display:flex;gap:8px;white-space:nowrap}.purchase-item-picker ::v-deep .el-dialog{max-width:calc(100vw - 32px);margin-top:5vh!important}.purchase-item-picker ::v-deep .el-dialog__header{border-bottom:1px solid #edf0f4}.purchase-item-picker ::v-deep .el-table th{background:#f8fafc;color:#334155}.purchase-item-picker ::v-deep .el-table .cell{white-space:normal;word-break:break-word}.purchase-item-picker ::v-deep .el-button--success{background:#07883f;border-color:#07883f}@media(max-width:760px){.picker-filters{grid-template-columns:minmax(0,1fr) 150px}.picker-body{grid-template-columns:1fr}.category-panel{height:160px}.picker-footer{align-items:flex-end;flex-direction:column}.picker-actions{align-self:stretch}.picker-actions .el-button{flex:1}}
</style>
