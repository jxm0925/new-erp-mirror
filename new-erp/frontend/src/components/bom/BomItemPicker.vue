<template>
  <el-dialog
    :visible.sync="picker.visible"
    append-to-body
    :width="picker.mode === 'output' ? '1100px' : '1280px'"
    top="5vh"
    custom-class="item-picker-dialog bom-component-picker-dialog"
    :close-on-click-modal="false"
  >
    <div slot="title" class="dialog-custom-header">
      <div class="dialog-title-left">
        <div class="dialog-icon-circle">
          <i class="el-icon-goods"></i>
        </div>
        <div class="dialog-title-texts">
          <div class="dialog-title-line">
            <span class="dialog-title-main">{{ picker.mode === 'output' ? '选择产出物料' : '选择组成物料' }}</span>
            <span class="dialog-title-tag">主数据物料库</span>
          </div>
          <p class="dialog-subtitle-text">
            {{ picker.mode === 'output' ? '挑选本 BOM 方案产出的成品或半成品，自动带出标准计量单位' : '挑选组成子项物料，自动继承规格型号、计量单位与下料计算参数' }}
          </p>
        </div>
      </div>
    </div>

    <div class="bom-item-picker-body">
      <aside class="bom-item-categories">
        <div class="category-head">
          <i class="el-icon-folder-opened"></i>
          <span>物料分类</span>
        </div>
        <el-button
          type="text"
          class="category-all-btn"
          :class="{ active: !picker.category_id }"
          @click="clearItemCategory"
        >全部分类</el-button>
        <el-tree
          ref="pickerCategoryTree"
          :data="categories"
          :props="{ label: 'category_name', children: 'children' }"
          node-key="id"
          highlight-current
          :expand-on-click-node="false"
          class="picker-category-tree"
          @node-click="selectItemCategory"
        />
      </aside>
      <div class="bom-item-results">
        <div class="picker-filter-toolbar">
          <el-input v-model="picker.keyword" size="small" clearable placeholder="物料编码 / 名称 / 规格" class="filter-search" @keyup.enter.native="searchItems" @clear="searchItems">
            <i slot="prefix" class="el-icon-search"></i>
          </el-input>

          <el-select v-model="picker.item_type" size="small" clearable placeholder="物料类型" class="filter-select" @change="searchItems">
            <el-option label="成品" value="finished_product" />
            <el-option label="半成品" value="semi_finished" />
            <el-option label="原材料" value="raw_material" />
            <el-option label="包材" value="packaging" />
            <el-option label="服务" value="service" />
          </el-select>
          <div class="filter-actions">
            <el-button size="small" type="success" icon="el-icon-search" @click="searchItems">查询</el-button>
            <el-button size="small" icon="el-icon-refresh" @click="resetItems">重置</el-button>
          </div>
        </div>

        <div class="picker-tip-box">
          <i class="el-icon-info"></i>
          <span>{{ picker.multiple ? '仅显示启用物料；支持直接点击整行或勾选首列复选框批量选择，组成物料不可与产出物料相同。' : '仅显示启用物料；支持直接点击整行或点击操作按钮快速选定物料。' }}</span>
        </div>

        <el-table
          :data="picker.rows"
          border
          size="mini"
          height="340"
          v-loading="picker.loading"
          class="picker-table"
          :row-class-name="pickerRowClassName"
          @row-click="onPickerRowClick"
        >
          <el-table-column width="48" align="center">
            <template slot="header" slot-scope="scope">
              <el-checkbox
                v-if="picker.multiple"
                :key="scope.$index"
                :value="isAllPickerPageSelected"
                :indeterminate="isPickerPageIndeterminate"
                :disabled="!canEdit || !selectablePickerPageRows.length"
                aria-label="全选当前页物料"
                class="picker-header-checkbox"
                @change="toggleSelectAllPickerPage"
              />
              <span v-else>选择</span>
            </template>
            <template slot-scope="{ row }">
              <div class="picker-select-cell" @click.stop>
                <el-checkbox
                  v-if="picker.multiple"
                  :value="isPickerItemSelected(row)"
                  :disabled="!canEdit || isItemDisabled(row)"
                  :aria-label="`选择 ${row.item_code} ${row.item_name}`"
                  @change="togglePickerItem(row, $event)"
                />
                <el-radio
                  v-else
                  :value="currentSinglePickedItemId"
                  :label="row.id"
                  :disabled="!canEdit || isItemDisabled(row)"
                  :aria-label="`选择 ${row.item_code} ${row.item_name}`"
                  class="picker-row-radio"
                  @change="selectItem(row)"
                >&nbsp;</el-radio>
              </div>
            </template>
          </el-table-column>
          <el-table-column v-if="picker.mode !== 'output'" label="图片" width="70" align="center">
            <template slot-scope="{ row }">
              <el-image v-if="row.image_url" :src="row.image_url" :alt="row.item_name" :preview-src-list="[row.image_url]" fit="contain" class="picker-item-image" @click.native.stop><span slot="error" /></el-image>
            </template>
          </el-table-column>
          <el-table-column prop="item_code" label="物料编码" min-width="115" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="code-mono font-bold">{{ row.item_code }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="item_name" label="物料名称" min-width="150" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <div class="picker-name-cell">
                <span class="picker-name-text">{{ row.item_name }}</span>
                <el-tag v-if="picker.multiple && isPickerItemSelected(row)" size="mini" type="success" effect="plain" class="picked-inline-tag">已选</el-tag>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="spec" label="规格型号" min-width="120" show-overflow-tooltip>
            <template slot-scope="{ row }">{{ row.spec || '—' }}</template>
          </el-table-column>
          <el-table-column label="物料类型" width="84" align="center">
            <template slot-scope="{ row }">
              <el-tag size="mini" type="info">{{ itemTypeText(row.item_type) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="分类" min-width="96" show-overflow-tooltip>
            <template slot-scope="{ row }">{{ row.category ? row.category.category_name : '-' }}</template>
          </el-table-column>
          <el-table-column label="单位" width="64" align="center">
            <template slot-scope="{ row }">{{ row.unit ? row.unit.unit_name : '-' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="72" align="center">
            <template slot-scope="{ row }">
              <span class="status-dot" :class="row.status === 'enabled' || row.status === 'active' ? 'enabled' : 'disabled'"></span>
              {{ statusText(row.status) }}
            </template>
          </el-table-column>
          <el-table-column label="操作" width="92" align="center" fixed="right">
            <template slot-scope="{ row }">
              <div class="picker-select-cell" @click.stop>
                <el-button
                  v-if="picker.multiple"
                  size="mini"
                  :type="isPickerItemSelected(row) ? 'success' : 'default'"
                  :plain="!isPickerItemSelected(row)"
                  :disabled="!canEdit || isItemDisabled(row)"
                  class="picker-action-btn"
                  @click="togglePickerItem(row, !isPickerItemSelected(row))"
                >
                  {{ isItemDisabled(row) ? '不可选' : (isPickerItemSelected(row) ? '已选' : '选择') }}
                </el-button>
                <el-button
                  v-else
                  size="mini"
                  plain
                  type="success"
                  :disabled="!canEdit || isItemDisabled(row)"
                  class="picker-action-btn"
                  @click="selectItem(row)"
                >
                  {{ isItemDisabled(row) ? '不可选' : '选择' }}
                </el-button>
                <small v-if="itemDisableReason(row)" class="disable-reason">{{ itemDisableReason(row) }}</small>
              </div>
            </template>
          </el-table-column>
        </el-table>

        <div v-if="picker.multiple" class="picker-selected">
          <div class="picker-selected-head">
            <strong>已选 {{ picker.selected.length }} 项组成物料</strong>
            <el-button v-if="picker.selected.length" type="text" size="mini" :disabled="!canEdit" @click="picker.selected = []">清空已选</el-button>
          </div>
          <div v-if="picker.selected.length" class="picker-selected-tags">
            <el-tag v-for="item in picker.selected" :key="item.id" size="small" type="success" :closable="canEdit" @close="removePickerItem(item)">{{ item.item_code }} / {{ item.item_name }}</el-tag>
          </div>
        </div>

        <div class="picker-pager">
          <span class="picker-total-text">共 {{ picker.total }} 条物料记录</span>
          <el-pagination
            background
            small
            layout="sizes, prev, pager, next"
            :current-page.sync="picker.page"
            :page-size.sync="picker.perPage"
            :page-sizes="[10, 20, 50]"
            :total="picker.total"
            @current-change="loadItems"
            @size-change="loadItems"
          />
        </div>
      </div>
    </div>
    <span slot="footer" class="dialog-footer">
      <el-button size="small" @click="picker.visible = false">{{ picker.multiple ? '取消' : '关闭' }}</el-button>
      <el-button v-if="picker.multiple" size="small" type="success" :disabled="!canEdit || !picker.selected.length" @click="confirmPickerItems">确认添加（{{ picker.selected.length }}）</el-button>
    </span>
  </el-dialog>
</template>

<script>
export default {
  name: 'BomItemPicker',
  props: {
    picker: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    canEdit: Boolean,
    currentSinglePickedItemId: { default: null },
    disabledReason: Function
  },
  computed: {
    selectablePickerPageRows() {
      return (this.picker.rows || []).filter(row => !this.isItemDisabled(row))
    },
    isAllPickerPageSelected() {
      if (!this.picker.multiple || !this.selectablePickerPageRows.length) return false
      return this.selectablePickerPageRows.every(row => this.isPickerItemSelected(row))
    },
    isPickerPageIndeterminate() {
      if (!this.picker.multiple || !this.selectablePickerPageRows.length) return false
      const selectedCount = this.selectablePickerPageRows.filter(row => this.isPickerItemSelected(row)).length
      return selectedCount > 0 && selectedCount < this.selectablePickerPageRows.length
    }
  },
  methods: {
    selectItemCategory(row) {
      this.picker.category_id = row.id
      this.searchItems()
    },
    clearItemCategory() {
      this.picker.category_id = ''
      if (this.$refs.pickerCategoryTree && typeof this.$refs.pickerCategoryTree.setCurrentKey === 'function') {
        this.$refs.pickerCategoryTree.setCurrentKey(null)
      }
      this.searchItems()
    },
    searchItems() {
      this.picker.page = 1
      this.loadItems()
    },
    resetItems() {
      Object.assign(this.picker, { keyword: '', category_id: '', item_type: '', page: 1 })
      if (this.$refs.pickerCategoryTree && typeof this.$refs.pickerCategoryTree.setCurrentKey === 'function') {
        this.$refs.pickerCategoryTree.setCurrentKey(null)
      }
      this.loadItems()
    },
    pickerRowClassName({ row }) {
      if (this.isItemDisabled(row)) return 'picker-row-disabled'
      if (this.picker.multiple && this.isPickerItemSelected(row)) return 'picker-row-selected picker-row-clickable'
      if (!this.picker.multiple && Number(row.id) === Number(this.currentSinglePickedItemId)) return 'picker-row-selected picker-row-clickable'
      return 'picker-row-clickable'
    },
    onPickerRowClick(row) {
      if (!this.canEdit) return
      if (this.isItemDisabled(row)) {
        this.$message.warning(this.itemDisableReason(row) || '停用物料或当前产出物料不能选择')
        return
      }
      if (this.picker.multiple) {
        this.togglePickerItem(row, !this.isPickerItemSelected(row))
      } else {
        this.selectItem(row)
      }
    },
    toggleSelectAllPickerPage(checked) {
      if (!this.canEdit || !this.picker.multiple) return
      this.selectablePickerPageRows.forEach(row => {
        this.togglePickerItem(row, Boolean(checked))
      })
    },
    isPickerItemSelected(item) {
      return this.picker.selected.some(row => Number(row.id) === Number(item.id))
    },
    togglePickerItem(item, selected) {
      if (!this.canEdit || this.isItemDisabled(item)) return
      if (selected && !this.isPickerItemSelected(item)) this.picker.selected.push(item)
      else if (!selected) this.removePickerItem(item)
    },
    removePickerItem(item) {
      if (!this.canEdit) return
      this.picker.selected = this.picker.selected.filter(row => Number(row.id) !== Number(item.id))
    },
    itemTypeText(value) {
      return ({ finished_product: '成品', semi_finished: '半成品', raw_material: '原材料', packaging: '包材', service: '服务' })[value] || value
    },
    statusText(value) { return ({ enabled: '启用', active: '启用', disabled: '停用', inactive: '停用' })[value] || value || '-' },
    itemDisableReason(item) { return this.disabledReason ? this.disabledReason(item) : (['disabled', 'inactive'].includes(item.status) ? '停用物料不能选择' : '') },
    isItemDisabled(item) { return Boolean(this.itemDisableReason(item)) },
    loadItems() { this.$emit('load') },
    selectItem(item) { if (this.canEdit && !this.isItemDisabled(item)) this.$emit('select', item) },
    confirmPickerItems() { if (this.canEdit && this.picker.selected.length) this.$emit('confirm') }
  }
}
</script>

<style scoped>
.bom-item-picker-body {
  display: grid;
  grid-template-columns: 185px minmax(0, 1fr);
  gap: 14px;
  align-items: stretch;
  min-width: 0;
  box-sizing: border-box;
}
.bom-item-categories {
  max-height: 460px;
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #f8fafc;
  padding: 10px;
  box-sizing: border-box;
  min-width: 0;
}
.category-head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 700;
  color: #1e293b;
  padding-bottom: 8px;
  margin-bottom: 8px;
  border-bottom: 1px solid #e2e8f0;
}
.category-head i {
  color: #008b4b;
  font-size: 15px;
}
.category-all-btn {
  display: flex !important;
  align-items: center;
  width: 100%;
  padding: 7px 10px !important;
  margin: 0 0 6px 0 !important;
  border-radius: 4px !important;
  font-size: 13px !important;
  color: #334155 !important;
  text-align: left;
  box-sizing: border-box;
  transition: all 0.15s;
}
.category-all-btn:hover {
  background: #e2e8f0 !important;
  color: #0f172a !important;
}
.category-all-btn.active {
  background: #ecfdf5 !important;
  color: #008b4b !important;
  font-weight: 700 !important;
  border: 1px solid #a7f3d0 !important;
}
.picker-category-tree {
  background: transparent;
}
.picker-category-tree ::v-deep .el-tree-node.is-current > .el-tree-node__content,
.picker-category-tree ::v-deep .el-tree-node__content:hover {
  background: #f0fdf4;
  color: #00763f;
}
.bom-item-results {
  min-width: 0;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
}
@media (max-width: 860px) {
  .bom-item-picker-body {
    grid-template-columns: minmax(0, 1fr);
    gap: 10px;
  }
  .bom-item-categories {
    max-height: 140px;
  }
}

/* 物料挑选弹窗内部筛选 */
.picker-filter-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  flex-wrap: wrap;
  min-width: 0;
  box-sizing: border-box;
}
.filter-search {
  flex: 1 1 220px;
  min-width: 0;
  max-width: 300px;
}
.filter-select {
  width: 140px;
  min-width: 0;
}
.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.picker-tip-box {
  display: flex;
  align-items: center;
  gap: 6px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 4px;
  padding: 6px 12px;
  margin-bottom: 10px;
  font-size: 12px;
  color: #166534;
  box-sizing: border-box;
}

.picker-table {
  width: 100%;
}

.picker-table :deep(th) {
  background: #f8fafc !important;
  color: #1e293b;
  font-weight: 700;
}

.picker-table ::v-deep .picker-row-clickable {
  cursor: pointer;
}

.picker-table ::v-deep .picker-row-selected > td {
  background-color: #f0fdf4 !important;
}

.picker-table ::v-deep .picker-row-disabled {
  color: #94a3b8;
  background-color: #f8fafc;
  cursor: not-allowed;
}

.picker-name-cell {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  min-width: 0;
}

.picker-name-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.picked-inline-tag {
  flex-shrink: 0;
  height: 18px;
  line-height: 16px;
  padding: 0 5px;
}

.picker-select-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
}
.picker-select-cell ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner,
.picker-select-cell ::v-deep .el-checkbox__input.is-indeterminate .el-checkbox__inner,
.picker-header-checkbox ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner,
.picker-header-checkbox ::v-deep .el-checkbox__input.is-indeterminate .el-checkbox__inner,
.picker-row-radio ::v-deep .el-radio__input.is-checked .el-radio__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}
.picker-select-cell ::v-deep .el-checkbox__inner:hover,
.picker-header-checkbox ::v-deep .el-checkbox__inner:hover,
.picker-row-radio ::v-deep .el-radio__inner:hover {
  border-color: #008b4b;
}
.picker-row-radio {
  margin-right: 0 !important;
}
.picker-row-radio ::v-deep .el-radio__label {
  display: none !important;
  padding-left: 0 !important;
}
.picker-action-btn {
  padding: 5px 10px !important;
}
.picker-selected {
  margin-top: 10px;
  padding: 8px 10px;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  background: #f0fdf4;
  box-sizing: border-box;
}
.picker-selected-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  font-size: 12px;
  color: #166534;
  flex-wrap: wrap;
}
.picker-selected-head ::v-deep .el-button--text {
  color: #008b4b;
}
.picker-selected-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  max-height: 88px;
  overflow: auto;
  margin-top: 6px;
}
.picker-selected-tags .el-tag {
  max-width: 100%;
  height: auto;
  white-space: normal;
  overflow-wrap: anywhere;
}
.disable-reason {
  color: #d97706;
  font-size: 10px;
  line-height: 12px;
  text-align: center;
  white-space: normal;
}

.status-dot {
  display: inline-block;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  margin-right: 4px;
}
.status-dot.enabled {
  background-color: #10b981;
}
.status-dot.disabled {
  background-color: #94a3b8;
}

.picker-pager {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 10px;
  box-sizing: border-box;
}
.picker-total-text {
  font-size: 12px;
  color: #64748b;
}

.picker-item-image {
  width: 40px;
  height: 40px;
  vertical-align: middle;
}
.code-mono {
  font-family: Consolas, Monaco, monospace;
}
.font-bold {
  font-weight: 700;
}
@media (max-width: 768px) {
  .filter-search,
  .filter-select {
    flex: 1 1 100%;
    max-width: 100%;
    width: 100%;
  }
  .picker-pager {
    justify-content: center;
  }
}
</style>

<style>
.bom-component-picker-dialog.el-dialog {
  display: flex;
  flex-direction: column;
  max-width: calc(100vw - 32px);
  max-height: calc(92vh - 16px);
  margin-bottom: 16px !important;
  box-sizing: border-box;
}
.bom-component-picker-dialog .el-dialog__body {
  min-height: 0;
  overflow-y: auto;
  box-sizing: border-box;
}
.bom-component-picker-dialog .el-dialog__header,
.bom-component-picker-dialog .el-dialog__footer {
  flex-shrink: 0;
  box-sizing: border-box;
}
/* 针对 append-to-body 的物料选择弹窗全局层样式：浅底柔和标头、高对比度显眼关闭按钮 */
.item-picker-dialog.el-dialog {
  border-radius: 8px !important;
  overflow: hidden !important;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04) !important;
}

.item-picker-dialog .el-dialog__header {
  padding: 16px 56px 14px 20px !important;
  background: #ffffff !important;
  border-bottom: 1px solid #e2e8f0 !important;
  border-radius: 8px 8px 0 0 !important;
  position: relative !important;
}

.item-picker-dialog .el-dialog__body {
  padding: 16px 20px !important;
}

.item-picker-dialog .el-dialog__footer {
  padding: 12px 20px !important;
  border-top: 1px solid #e2e8f0 !important;
  background: #ffffff !important;
}

.dialog-custom-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-width: 0;
}

.dialog-title-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.dialog-icon-circle {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: #ecfdf5;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  border: 1px solid #bbf7d0;
  flex-shrink: 0;
}

.dialog-title-texts {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.dialog-title-line {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.dialog-title-main {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
  letter-spacing: -0.2px;
}

.dialog-title-tag {
  font-size: 11px;
  color: #15803d;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  padding: 1px 7px;
  border-radius: 4px;
  font-weight: 600;
}

.dialog-subtitle-text {
  margin: 0;
  font-size: 12px;
  color: #64748b;
  line-height: 1.4;
}

/* 弹窗显眼高对比度关闭按钮 - 独立浅灰方块与深色粗体X，悬停醒目红色 */
.item-picker-dialog .el-dialog__headerbtn {
  top: 16px !important;
  right: 18px !important;
  width: 32px !important;
  height: 32px !important;
  border-radius: 6px !important;
  background: #f1f5f9 !important;
  border: 1px solid #cbd5e1 !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
  cursor: pointer !important;
  z-index: 10 !important;
}

.item-picker-dialog .el-dialog__headerbtn .el-dialog__close {
  color: #334155 !important;
  font-size: 16px !important;
  font-weight: 700 !important;
  transition: color 0.2s !important;
}

.item-picker-dialog .el-dialog__headerbtn:hover {
  background: #fee2e2 !important;
  border-color: #fca5a5 !important;
}

.item-picker-dialog .el-dialog__headerbtn:hover .el-dialog__close {
  color: #dc2626 !important;
}

@media (max-width: 768px) {
  .bom-component-picker-dialog.el-dialog {
    width: calc(100vw - 16px) !important;
    max-width: calc(100vw - 16px) !important;
    margin-top: 2vh !important;
    max-height: 96vh !important;
  }
  .item-picker-dialog .el-dialog__header {
    padding: 12px 48px 10px 14px !important;
  }
  .item-picker-dialog .el-dialog__body {
    padding: 12px 14px !important;
  }
  .item-picker-dialog .el-dialog__footer {
    padding: 10px 14px !important;
  }
}
</style>
