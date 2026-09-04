<template>
  <el-dialog
    class="purchase-item-picker"
    title="选择采购物料"
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

    <div class="picker-tip">仅查询新系统中已启用、允许采购的 Item；支持单击选中、双击直接确认。</div>

    <el-table
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
        <template slot-scope="{row}"><el-radio v-model="currentId" :label="row.id">&nbsp;</el-radio></template>
      </el-table-column>
      <el-table-column prop="item_code" label="Item编码" width="150" />
      <el-table-column prop="item_name" label="Item名称" min-width="210" />
      <el-table-column label="规格型号" min-width="170"><template slot-scope="{row}">{{ specification(row) }}</template></el-table-column>
      <el-table-column label="物料类型" width="95"><template slot-scope="{row}">{{ itemTypeText(row.item_type) }}</template></el-table-column>
      <el-table-column label="分类" width="140"><template slot-scope="{row}">{{ categoryName(row) }}</template></el-table-column>
      <el-table-column label="基本单位" width="80"><template slot-scope="{row}">{{ unitName(row) }}</template></el-table-column>
      <el-table-column label="状态" width="80"><template slot-scope="{row}"><el-tag size="mini" type="success">{{ statusText(row.status) }}</el-tag></template></el-table-column>
    </el-table>

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
        <el-button size="small" type="success" :disabled="!current" @click="confirm()">确定选择</el-button>
      </div>
    </div>
  </el-dialog>
</template>

<script>
import { listEntity } from '@/api/erp/master'

export default {
  name: 'PurchaseItemPicker',
  data: () => ({
    visible: false,
    loading: false,
    rows: [],
    total: 0,
    current: null,
    preferredId: null,
    extraParams: {},
    query: { keyword: '', item_type: '', page: 1, per_page: 10 }
  }),
  computed: {
    currentId: {
      get() { return this.current && this.current.id },
      set(id) { this.current = this.rows.find(row => Number(row.id) === Number(id)) || null }
    }
  },
  methods: {
    async open({ currentId = null, params = {} } = {}) {
      this.preferredId = currentId
      this.extraParams = { status: 'enabled', is_purchase_item: 1, ...params }
      this.query = { keyword: '', item_type: '', page: 1, per_page: 10 }
      this.current = null
      this.visible = true
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
    reset() { this.query = { keyword: '', item_type: '', page: 1, per_page: this.query.per_page }; this.preferredId = null; this.load() },
    changeSize() { this.query.page = 1; this.load() },
    selectRow(row) { this.current = row },
    confirm(row) {
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
.picker-filters{display:grid;grid-template-columns:minmax(280px,1fr) 170px 82px 82px;gap:10px;align-items:center;margin-bottom:10px}.picker-tip{margin-bottom:10px;padding:8px 10px;border:1px solid #cfe5d8;border-radius:4px;background:#f3fbf6;color:#397052;font-size:12px}.picker-footer{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:12px}.picker-actions{display:flex;gap:8px;white-space:nowrap}.purchase-item-picker ::v-deep .el-dialog{max-width:calc(100vw - 32px)}.purchase-item-picker ::v-deep .el-dialog__header{border-bottom:1px solid #edf0f4}.purchase-item-picker ::v-deep .el-table th{background:#f8fafc;color:#334155}.purchase-item-picker ::v-deep .el-table .cell{white-space:normal;word-break:break-word}.purchase-item-picker ::v-deep .el-button--success{background:#07883f;border-color:#07883f}@media(max-width:760px){.picker-filters{grid-template-columns:minmax(0,1fr) 150px}.picker-footer{align-items:flex-end;flex-direction:column}.picker-actions{align-self:stretch}.picker-actions .el-button{flex:1}}
</style>
