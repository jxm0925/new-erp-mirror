<template>
  <el-dialog
    class="purchase-item-picker"
    :title="dialogTitle"
    :visible.sync="visible"
    width="1100px"
    append-to-body
    :close-on-click-modal="false"
  >
    <div class="picker-filters">
      <el-input
        v-model.trim="query.keyword"
        size="small"
        clearable
        prefix-icon="el-icon-search"
        placeholder="输入物料编码 / 名称 / 规格型号检索"
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
      <div class="filter-btns">
        <el-button size="small" type="success" icon="el-icon-search" @click="search">查询</el-button>
        <el-button size="small" icon="el-icon-refresh" @click="reset">重置</el-button>
      </div>
    </div>

    <div class="picker-body">
      <aside class="category-panel">
        <div class="category-head">
          <i class="el-icon-menu" />
          <span>物料分类</span>
        </div>
        <button type="button" class="category-btn" :class="{active: !query.category_id}" @click="selectCategory(null)">
          全部分类
        </button>
        <el-tree
          :data="categoryTree"
          node-key="id"
          :props="{label: 'category_name', children: 'children'}"
          :expand-on-click-node="false"
          default-expand-all
          highlight-current
          @node-click="selectCategory"
        />
      </aside>

      <div class="table-panel">
        <el-table
          ref="table"
          v-loading="loading"
          :data="rows"
          border
          stripe
          size="small"
          height="420"
          highlight-current-row
          :row-class-name="tableRowClassName"
          @row-click="selectRow"
          @row-dblclick="confirm"
        >
          <el-table-column width="50" align="center">
            <template slot="header">
              <el-checkbox
                v-if="multiple"
                :value="isAllCurrentPageSelected"
                :indeterminate="isIndeterminate"
                @change="toggleSelectAllCurrentPage"
              />
              <span v-else>选择</span>
            </template>
            <template slot-scope="{row}">
              <el-checkbox v-if="multiple" :value="Boolean(selectedById[row.id])" @click.native.stop @change="toggleRow(row)" />
              <el-radio v-else v-model="currentId" :label="row.id" @click.native.stop>&nbsp;</el-radio>
            </template>
          </el-table-column>
          <el-table-column prop="item_code" label="物料编码" width="140" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span class="item-code-badge">{{ row.item_code }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="item_name" label="物料名称" min-width="180" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span class="item-name-text">{{ row.item_name }}</span>
              <span v-if="selectedById[row.id]" class="row-picked-badge">已选</span>
            </template>
          </el-table-column>
          <el-table-column label="规格型号" min-width="140" show-overflow-tooltip>
            <template slot-scope="{row}">{{ specification(row) }}</template>
          </el-table-column>
          <el-table-column label="物料类型" width="90" align="center">
            <template slot-scope="{row}">
              <el-tag size="mini" type="info">{{ itemTypeText(row.item_type) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="分类" width="120" show-overflow-tooltip>
            <template slot-scope="{row}">{{ categoryName(row) }}</template>
          </el-table-column>
          <el-table-column label="单位" width="70" align="center">
            <template slot-scope="{row}">{{ unitName(row) }}</template>
          </el-table-column>
          <el-table-column label="状态" width="70" align="center">
            <template slot-scope="{row}">
              <el-tag size="mini" type="success">{{ statusText(row.status) }}</el-tag>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </div>

    <!-- 已选择的物料预览与清空：高对比度、清晰可辨配色 -->
    <div v-if="multiple && selectedRows.length" class="selected-review">
      <div class="review-header">
        <div class="review-title-group">
          <i class="el-icon-circle-check" />
          <span class="review-title">已选物料（<strong class="count-num">{{ selectedRows.length }}</strong>）</span>
        </div>
        <el-button type="text" size="mini" class="clear-btn" icon="el-icon-delete" @click="selectedById = {}">清空已选</el-button>
      </div>
      <div class="tags-container">
        <div
          v-for="row in selectedRows"
          :key="row.id"
          class="selected-chip"
        >
          <span class="chip-code">{{ row.item_code }}</span>
          <span class="chip-name" :title="row.item_name">{{ row.item_name }}</span>
          <i class="el-icon-close chip-close" @click.stop="removeSelected(row)" />
        </div>
      </div>
    </div>

    <div class="picker-footer">
      <el-pagination
        background
        layout="total, sizes, prev, pager, next"
        :current-page.sync="query.page"
        :page-size.sync="query.per_page"
        :page-sizes="[10, 20, 50, 100]"
        :total="total"
        @current-change="load"
        @size-change="changeSize"
      />
      <div class="picker-actions">
        <el-button size="small" @click="visible = false">取消</el-button>
        <el-button
          size="small"
          type="success"
          icon="el-icon-check"
          :disabled="multiple ? !selectedRows.length : !current"
          @click="confirm()"
        >
          {{ multiple ? `确定添加 (${selectedRows.length})` : '确定选择' }}
        </el-button>
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
    selectedById: {},
    categoryTree: [],
    extraParams: {},
    query: { keyword: '', item_type: '', category_id: null, page: 1, per_page: 20 }
  }),
  computed: {
    selectedRows() { return Object.values(this.selectedById) },
    currentId: {
      get() { return this.current && this.current.id },
      set(id) { this.current = this.rows.find(row => Number(row.id) === Number(id)) || null }
    },
    isAllCurrentPageSelected() {
      if (!this.rows.length) return false
      return this.rows.every(row => Boolean(this.selectedById[row.id]))
    },
    isIndeterminate() {
      const count = this.rows.filter(row => Boolean(this.selectedById[row.id])).length
      return count > 0 && count < this.rows.length
    }
  },
  methods: {
    async open({ currentId = null, params = {}, multiple = false, selected = [], title = '选择采购物料' } = {}) {
      this.preferredId = currentId
      this.multiple = multiple
      this.dialogTitle = title
      this.selectedById = Object.fromEntries((selected || []).filter(row => row && row.id).map(row => [row.id, row]))
      this.extraParams = { status: 'enabled', is_purchase_item: 1, ...params }
      this.query = { keyword: '', item_type: '', category_id: null, page: 1, per_page: 20 }
      this.current = null
      this.visible = true
      if (!this.categoryTree.length) {
        try {
          const { data } = await getItemCategoryTree()
          this.categoryTree = data.data || []
        } catch (e) {
          // ignore error
        }
      }
      await this.load()
    },
    tableRowClassName({ row }) {
      return this.selectedById[row.id] ? 'row-selected-highlight' : ''
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
    reset() {
      this.query = { keyword: '', item_type: '', category_id: null, page: 1, per_page: this.query.per_page }
      this.preferredId = null
      this.load()
    },
    changeSize() { this.query.page = 1; this.load() },
    selectCategory(row) {
      if (row && !row.is_leaf && Array.isArray(row.children) && row.children.length) return
      this.query.category_id = row ? row.id : null
      this.search()
    },
    selectRow(row) {
      if (this.multiple) {
        this.toggleRow(row)
      } else {
        this.current = row
      }
    },
    toggleRow(row) {
      if (this.selectedById[row.id]) {
        this.$delete(this.selectedById, row.id)
      } else {
        this.$set(this.selectedById, row.id, row)
      }
    },
    toggleSelectAllCurrentPage(checked) {
      if (checked) {
        this.rows.forEach(row => {
          this.$set(this.selectedById, row.id, row)
        })
      } else {
        this.rows.forEach(row => {
          this.$delete(this.selectedById, row.id)
        })
      }
    },
    removeSelected(row) { this.$delete(this.selectedById, row.id) },
    confirm(row) {
      if (row && this.multiple) {
        if (!this.selectedById[row.id]) {
          this.$set(this.selectedById, row.id, row)
        }
      }
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
    itemTypeText(value) {
      return ({
        finished_product: '成品', finished_good: '成品',
        semi_finished: '半成品', raw_material: '原材料',
        packaging: '包装物', service: '服务'
      })[value] || value || '-'
    },
    statusText(value) { return ({ enabled: '启用', active: '启用', disabled: '停用' })[value] || value || '-' }
  }
}
</script>

<style scoped>
.picker-filters {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.picker-filters .el-input {
  width: 320px;
}
.picker-filters .el-select {
  width: 140px;
}
.filter-btns {
  display: flex;
  gap: 8px;
}

.picker-body {
  display: grid;
  grid-template-columns: 200px minmax(0, 1fr);
  gap: 14px;
}

.category-panel {
  height: 420px;
  overflow-y: auto;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px;
  background: #f8fafc;
  box-sizing: border-box;
}
.category-head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  font-size: 13px;
  color: #1e293b;
  margin-bottom: 8px;
  padding-bottom: 6px;
  border-bottom: 1px solid #e2e8f0;
}
.category-btn {
  width: 100%;
  border: 0;
  background: transparent;
  padding: 6px 10px;
  text-align: left;
  border-radius: 4px;
  cursor: pointer;
  font-size: 13px;
  color: #475569;
  margin-bottom: 4px;
  transition: all 0.15s;
}
.category-btn:hover {
  background: #e2e8f0;
  color: #0f172a;
}
.category-btn.active {
  background: #f0fdf4;
  color: #008b4b;
  font-weight: 600;
}

.table-panel {
  min-width: 0;
}
.item-code-badge {
  font-family: monospace;
  font-weight: 600;
  color: #008b4b;
}
.item-name-text {
  font-weight: 500;
  color: #1e293b;
}

.row-picked-badge {
  display: inline-block;
  margin-left: 6px;
  padding: 1px 6px;
  font-size: 11px;
  font-weight: 600;
  color: #008b4b;
  background: #dcfce7;
  border: 1px solid #bbf7d0;
  border-radius: 3px;
  line-height: 1.2;
  vertical-align: middle;
}

/* 高对比度已选物料预览区 */
.selected-review {
  margin-top: 12px;
  padding: 10px 14px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  border-radius: 6px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.review-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.review-title-group {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
}
.review-title-group i {
  color: #008b4b;
  font-size: 15px;
}
.count-num {
  color: #008b4b;
}
.clear-btn {
  color: #ef4444 !important;
  font-size: 12px;
  padding: 0;
}
.clear-btn:hover {
  color: #dc2626 !important;
}

.tags-container {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  max-height: 88px;
  overflow-y: auto;
}

/* 高对比度独立物料标签 */
.selected-chip {
  display: inline-flex;
  align-items: center;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  padding: 4px 10px;
  font-size: 12px;
  line-height: 1.4;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
  transition: all 0.15s ease;
}
.selected-chip:hover {
  border-color: #94a3b8;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
}
.chip-code {
  font-family: monospace;
  font-weight: 700;
  color: #008b4b;
  margin-right: 6px;
  background: #f0fdf4;
  padding: 1px 4px;
  border-radius: 2px;
}
.chip-name {
  color: #0f172a;
  font-weight: 500;
  max-width: 220px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.chip-close {
  margin-left: 6px;
  color: #94a3b8;
  cursor: pointer;
  font-size: 12px;
  border-radius: 50%;
  padding: 1px;
  transition: all 0.15s;
}
.chip-close:hover {
  background: #fee2e2;
  color: #ef4444;
}

.picker-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-top: 14px;
}
.picker-actions {
  display: flex;
  gap: 8px;
}

/* 主题绿按钮与样式穿透 */
.purchase-item-picker ::v-deep .el-dialog {
  max-width: calc(100vw - 32px);
  border-radius: 8px;
  overflow: hidden;
}
.purchase-item-picker ::v-deep .el-dialog__header {
  border-bottom: 1px solid #f1f5f9;
  padding: 14px 20px;
}
.purchase-item-picker ::v-deep .el-dialog__title {
  font-size: 16px;
  font-weight: 600;
  color: #1e293b;
}
.purchase-item-picker ::v-deep .el-dialog__body {
  padding: 16px 20px 12px;
}
.purchase-item-picker ::v-deep .el-button--success {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
}
.purchase-item-picker ::v-deep .el-button--success:hover {
  background-color: #00763f !important;
  border-color: #00763f !important;
}
.purchase-item-picker ::v-deep .el-table th {
  background: #f8fafc;
  color: #334155;
  font-weight: 600;
}
.purchase-item-picker ::v-deep .el-table .row-selected-highlight {
  background-color: #f0fdf4 !important;
}
.purchase-item-picker ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner,
.purchase-item-picker ::v-deep .el-checkbox__input.is-indeterminate .el-checkbox__inner {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
}
.purchase-item-picker ::v-deep .el-radio__input.is-checked .el-radio__inner {
  border-color: #008b4b !important;
  background: #008b4b !important;
}
.purchase-item-picker ::v-deep .el-tree-node.is-current > .el-tree-node__content {
  background-color: #f0fdf4 !important;
  color: #008b4b !important;
  font-weight: 600;
}

@media (max-width: 800px) {
  .picker-filters {
    flex-direction: column;
    align-items: stretch;
  }
  .picker-filters .el-input,
  .picker-filters .el-select {
    width: 100%;
  }
  .picker-body {
    grid-template-columns: 1fr;
  }
  .category-panel {
    height: 140px;
  }
  .picker-footer {
    flex-direction: column;
    align-items: stretch;
  }
  .picker-actions {
    justify-content: flex-end;
  }
}
</style>
