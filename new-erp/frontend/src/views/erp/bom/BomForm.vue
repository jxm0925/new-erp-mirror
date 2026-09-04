<template>
  <section class="bom-form-page">
    <div class="page-toolbar">
      <div class="page-title">
        <button class="back-btn" type="button" @click="$router.push('/bom/boms')">
          <i class="el-icon-back"></i>
        </button>
        <span>BOM 新增 / 编辑</span>
      </div>
      <div class="toolbar-actions">
        <el-button size="small" @click="$router.push('/bom/boms')">取消</el-button>
        <el-button v-if="canEdit" size="small" @click="save">保存草稿</el-button>
        <el-button v-if="canEdit" size="small" type="success" @click="save(true)">提交审核</el-button>
      </div>
    </div>

    <el-alert
      v-if="!canEdit"
      class="form-alert"
      type="warning"
      :closable="false"
      show-icon
      title="待审核、已审核、已启用 BOM 不能直接编辑；如需修改，请复制为新版本。"
    />

    <div class="top-panels">
      <section class="panel basic-panel">
        <h3>基础信息</h3>
        <div class="basic-grid">
          <label class="field span-2 required">
            <span>BOM编号</span>
            <el-input v-model="form.bom_no" size="small" placeholder="系统预生成" disabled />
          </label>

          <label class="field span-2 required">
            <span>BOM名称</span>
            <el-input v-model="form.bom_name" size="small" :disabled="!canEdit" placeholder="请输入 BOM 名称" />
          </label>

          <label class="field">
            <span>BOM类型</span>
            <el-select v-model="form.bom_type" size="small" :disabled="!canEdit">
              <el-option label="标准" value="standard" />
              <el-option label="定制" value="custom" />
              <el-option label="试制" value="trial" />
            </el-select>
          </label>

          <label class="field">
            <span>版本</span>
            <el-input v-model="form.version" size="small" :disabled="!canEdit" />
          </label>

          <label class="field">
            <span>生效日期</span>
            <el-date-picker v-model="form.effective_date" size="small" value-format="yyyy-MM-dd" :disabled="!canEdit" placeholder="选择日期" />
          </label>

          <label class="field">
            <span>失效日期</span>
            <el-date-picker v-model="form.expire_date" size="small" value-format="yyyy-MM-dd" :disabled="!canEdit" placeholder="选择日期" />
          </label>

          <label v-if="isCustomBom" class="field required">
            <span>来源商品</span>
            <el-select v-model="form.source_product_id" filterable clearable size="small" :disabled="!canEdit" placeholder="请选择">
              <el-option v-for="p in products" :key="p.id" :label="`${p.product_code} / ${p.product_name}`" :value="p.id" />
            </el-select>
          </label>

          <label v-if="isCustomBom" class="field required">
            <span>来源SKU</span>
            <el-select v-model="form.source_sku_id" filterable clearable size="small" :disabled="!canEdit" placeholder="请选择">
              <el-option v-for="s in skus" :key="s.id" :label="`${s.sku_code} / ${s.sku_name}`" :value="s.id" />
            </el-select>
          </label>

          <label v-if="isCustomBom" class="field source-bom required">
            <span>来源标准<br>BOM</span>
            <el-select v-model="form.source_standard_bom_id" filterable clearable size="small" :disabled="!canEdit" placeholder="请选择来源标准 BOM">
              <el-option v-for="b in boms" :key="b.id" :label="`${b.bom_no} / ${b.bom_name} / ${b.version}`" :value="b.id" />
            </el-select>
          </label>

          <div v-if="!isCustomBom" class="standard-source-tip">
            标准 BOM 不强制填写来源商品、来源SKU、来源标准BOM；定制 BOM 会启用来源追溯字段。
          </div>

          <label class="field desc-field">
            <span>描述</span>
            <el-input v-model="form.custom_description" size="small" :disabled="!canEdit" placeholder="客户A定制需求，目标机型改型。" />
          </label>
        </div>
      </section>

      <section class="panel output-panel">
        <h3>产出对象</h3>
        <div class="output-grid">
          <label class="field required">
            <span>归属商品</span>
            <el-select v-model="form.product_id" filterable clearable size="small" :disabled="!canEdit" placeholder="请选择">
              <el-option v-for="p in products" :key="p.id" :label="`${p.product_code} / ${p.product_name}`" :value="p.id" />
            </el-select>
          </label>

          <label class="field required">
            <span>关联SKU</span>
            <el-select v-model="form.sku_id" filterable clearable size="small" :disabled="!canEdit" placeholder="请选择">
              <el-option v-for="s in skus" :key="s.id" :label="`${s.sku_code} / ${s.sku_name}`" :value="s.id" />
            </el-select>
          </label>

          <label class="field required">
            <span>产出Item</span>
            <div class="select-like" :class="{ disabled: !canEdit }" @click="canEdit && openPicker('output')">
              <span>{{ outputItemLabel }}</span>
              <i class="el-icon-arrow-down"></i>
            </div>
          </label>

          <label class="field">
            <span>计量单位</span>
            <el-input size="small" :value="outputUnitName" disabled />
          </label>
        </div>
      </section>
    </div>

    <section class="panel detail-panel">
      <div class="detail-title">BOM明细</div>
      <el-table :data="form.items" border size="mini" class="bom-lines">
        <el-table-column prop="line_no" label="行号" width="64" align="center" />
        <el-table-column label="组成物料Item" min-width="190">
          <template slot="header">
            <span class="required-head">组成物料Item</span>
          </template>
          <template slot-scope="{row,$index}">
            <div class="item-cell" :class="{ disabled: !canEdit }" @click="canEdit && openPicker('component', $index)">
              <span>{{ row.component_item_code || '请选择物料' }}</span>
              <i class="el-icon-search"></i>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="component_item_name" label="物料名称" min-width="130" show-overflow-tooltip />
        <el-table-column label="用量" width="122" align="center">
          <template slot="header">
            <span class="required-head">用量</span>
          </template>
          <template slot-scope="{row}">
            <el-input-number v-model="row.qty" size="mini" :min="0" :precision="4" :disabled="!canEdit" controls-position="right" />
          </template>
        </el-table-column>
        <el-table-column label="单位" width="112" align="center">
          <template slot-scope="{row}">
            <span class="base-unit-readonly">{{ lineUnitName(row) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="损耗率(%)" width="120" align="center">
          <template slot-scope="{row}">
            <el-input-number v-model="row.loss_rate" size="mini" :min="0" :max="100" :precision="2" :disabled="!canEdit" controls-position="right" />
          </template>
        </el-table-column>
        <el-table-column label="固定用量" width="122" align="center">
          <template slot-scope="{row}">
            <el-input-number v-model="row.fixed_qty" size="mini" :min="0" :precision="4" :disabled="!canEdit" controls-position="right" />
          </template>
        </el-table-column>
        <el-table-column label="可替代" width="92" align="center">
          <template slot-scope="{row}">
            <el-checkbox v-model="row.replaceable" :disabled="!canEdit" />
          </template>
        </el-table-column>
        <el-table-column label="备注" min-width="150">
          <template slot-scope="{row}">
            <el-input v-model="row.remark" size="mini" :disabled="!canEdit" placeholder="备注" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="78" align="center">
          <template slot-scope="{$index}">
            <el-button :disabled="!canEdit" type="text" size="mini" class="delete-link" @click="removeLine($index)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="line-actions">
        <el-button :disabled="!canEdit" size="small" plain type="success" icon="el-icon-plus" @click="addLine">添加行</el-button>
        <el-button :disabled="!canEdit" size="small" plain type="success" icon="el-icon-upload2">批量导入</el-button>
      </div>
    </section>

    <div class="bottom-panels">
      <section class="panel version-panel">
        <h3>版本信息</h3>
        <div class="status-row">
          <span>当前状态</span>
          <el-tag size="small" :type="displayStatusType">{{ displayStatusText }}</el-tag>
          <span>审核状态</span>
          <el-tag size="small" :type="displayAuditType">{{ displayAuditText }}</el-tag>
        </div>
      </section>

      <section class="panel remark-panel">
        <h3>备注</h3>
        <el-input v-model="form.remark" type="textarea" :rows="3" :disabled="!canEdit" placeholder="请输入备注" />
      </section>
    </div>

    <el-dialog :visible.sync="picker.visible" :title="picker.mode === 'output' ? '选择产出 Item' : '选择组成物料'" width="980px" class="item-picker">
      <div class="picker-filter">
        <el-input v-model="picker.keyword" size="small" clearable placeholder="Item编码/名称" @keyup.enter.native="searchItems" @clear="searchItems" />
        <el-select v-model="picker.category_id" size="small" clearable placeholder="分类" @change="searchItems">
          <el-option v-for="c in categories" :key="c.id" :label="c.category_name" :value="c.id" />
        </el-select>
        <el-select v-model="picker.item_type" size="small" clearable placeholder="物料类型" @change="searchItems">
          <el-option label="成品" value="finished_product" />
          <el-option label="半成品" value="semi_finished" />
          <el-option label="原材料" value="raw_material" />
          <el-option label="包材" value="packaging" />
          <el-option label="服务" value="service" />
        </el-select>
        <el-select v-model="picker.status" size="small" clearable placeholder="状态" @change="searchItems">
          <el-option label="启用" value="enabled" />
          <el-option label="活跃" value="active" />
          <el-option label="停用" value="disabled" />
        </el-select>
        <el-button size="small" type="success" @click="searchItems">查询</el-button>
        <el-button size="small" @click="resetItems">重置</el-button>
      </div>
      <div class="picker-tip">
        提示：重置或改变查询条件将返回第 1 页；停用物料不可选择；组成物料不可与产出Item相同。
      </div>
      <el-table :data="picker.rows" border size="mini" height="360" v-loading="picker.loading">
        <el-table-column prop="item_code" label="Item编码" width="140" />
        <el-table-column prop="item_name" label="Item名称" min-width="180" show-overflow-tooltip />
        <el-table-column label="类型" width="100"><template slot-scope="{row}">{{ itemTypeText(row.item_type) }}</template></el-table-column>
        <el-table-column label="分类" width="120"><template slot-scope="{row}">{{ row.category ? row.category.category_name : '-' }}</template></el-table-column>
        <el-table-column label="单位" width="80"><template slot-scope="{row}">{{ row.unit ? row.unit.unit_name : '-' }}</template></el-table-column>
        <el-table-column label="状态" width="80"><template slot-scope="{row}">{{ statusText(row.status) }}</template></el-table-column>
        <el-table-column label="选择" width="126">
          <template slot-scope="{row}">
            <div class="picker-select-cell">
              <el-button size="mini" plain type="success" :disabled="isItemDisabled(row)" @click="selectItem(row)">{{ isItemDisabled(row) ? '不可选' : '选择' }}</el-button>
              <small v-if="itemDisableReason(row)">{{ itemDisableReason(row) }}</small>
            </div>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        background
        small
        layout="total, sizes, prev, pager, next"
        :current-page.sync="picker.page"
        :page-size.sync="picker.perPage"
        :page-sizes="[10,20,50]"
        :total="picker.total"
        @current-change="loadItems"
        @size-change="loadItems"
      />
    </el-dialog>
  </section>
</template>

<script>
import { listEntity } from '@/api/erp/master'
import { listBoms, getBom, saveBom, submitBom } from '@/api/erp/bom'
import { clearCreatePageReservation, reserveForCreatePage } from '@/utils/documentNumberReservation'

const emptyLine = i => ({
  line_no: i + 1,
  component_item_id: null,
  component_item_code: '',
  component_item_name: '',
  qty: 1,
  unit_id: null,
  loss_rate: 0,
  fixed_qty: 0,
  replaceable: false,
  remark: ''
})

export default {
  data: () => ({
    form: {
      bom_no: '',
      bom_name: '',
      product_id: null,
      sku_id: null,
      output_item_id: null,
      output_item: null,
      bom_type: 'standard',
      version: 'V1.0',
      effective_date: '',
      expire_date: '',
      source_product_id: null,
      source_sku_id: null,
      source_standard_bom_id: null,
      custom_description: '',
      remark: '',
      status: 'draft',
      audit_status: 'pending',
      submitted_at: null,
      items: [emptyLine(0)]
    },
    products: [],
    skus: [],
    units: [],
    categories: [],
    boms: [],
    numberReservation: null,
    picker: {
      visible: false,
      mode: 'component',
      index: -1,
      keyword: '',
      category_id: '',
      item_type: '',
      status: '',
      rows: [],
      page: 1,
      perPage: 10,
      total: 0,
      loading: false
    }
  }),
  computed: {
    isEdit() {
      return Boolean(this.$route.params.id)
    },
    canEdit() {
      return !this.isEdit || (this.form.status === 'draft' && !this.form.submitted_at)
    },
    isCustomBom() {
      return this.form.bom_type === 'custom'
    },
    outputItemLabel() {
      return this.form.output_item ? `${this.form.output_item.item_code}　${this.form.output_item.item_name}` : '请选择产出Item'
    },
    outputUnitName() {
      if (!this.form.output_item || !this.form.output_item.unit) return '—'
      const unit = this.form.output_item.unit.standard_unit || this.form.output_item.unit.standardUnit || this.form.output_item.unit
      return `${unit.unit_code || ''} ${unit.unit_name || ''}`.trim() || '—'
    },
    isDraftEditable() {
      return this.form.status === 'draft' && !this.form.submitted_at
    },
    isPendingAudit() {
      return this.form.audit_status === 'pending' && Boolean(this.form.submitted_at)
    },
    displayStatusText() {
      if (this.isPendingAudit) return '已提交'
      if (this.form.audit_status === 'approved' && this.form.status !== 'active') return '已审核'
      return this.statusText(this.form.status || 'draft')
    },
    displayAuditText() {
      if (this.isDraftEditable) return '未提交'
      return this.auditText(this.form.audit_status || 'pending')
    },
    displayStatusType() {
      if (this.isPendingAudit) return 'warning'
      if (this.form.audit_status === 'approved' && this.form.status !== 'active') return 'success'
      return ({ active: 'success', inactive: 'warning', archived: 'info', draft: 'primary' })[this.form.status] || 'primary'
    },
    displayAuditType() {
      if (this.isDraftEditable) return 'info'
      return ({ approved: 'success', rejected: 'danger', pending: 'warning' })[this.form.audit_status] || 'warning'
    }
  },
  async created() {
    await this.loadRefs()
    if (this.isEdit) await this.loadDetail()
    else await this.reserveBomNumber()
    if (this.$route.query.openPicker) {
      this.$nextTick(() => setTimeout(() => {
        const query = this.$route.query
        this.picker.keyword = query.pickerKeyword || ''
        this.picker.status = query.pickerStatus || ''
        this.picker.item_type = query.pickerType || ''
        this.picker.page = Number(query.pickerPage || 1)
        this.openPicker(query.pickerMode || 'component', Number(query.pickerIndex || 0), { keepQuery: true })
      }, 500))
    }
  },
  methods: {
    async reserveBomNumber() {
      try {
        this.numberReservation = await reserveForCreatePage('bom', '/bom/create')
        this.form.bom_no = this.numberReservation.document_no
      } catch (e) {
        this.$message.error(e.userMessage || 'BOM 编号预生成失败，请刷新后重试')
      }
    },
    async loadRefs() {
      const [p, s, u, c, b] = await Promise.all([
        listEntity('products', { per_page: 100 }),
        listEntity('skus', { per_page: 100 }),
        listEntity('units', { per_page: 100 }),
        listEntity('categories', { per_page: 100, category_type: 'item' }),
        listBoms({ per_page: 100, bom_type: 'standard' })
      ])
      this.products = p.data.data || []
      this.skus = s.data.data || []
      this.units = u.data.data || []
      this.categories = c.data.data || []
      this.boms = b.data.data || []
    },
    async loadDetail() {
      const { data } = await getBom(this.$route.params.id)
      this.form = {
        ...data,
        items: (data.items || []).map((item, index) => ({
          ...item,
          line_no: index + 1,
          qty: Number(item.qty),
          loss_rate: Number(item.loss_rate),
          fixed_qty: Number(item.fixed_qty),
          replaceable: Boolean(item.replaceable)
        }))
      }
    },
    addLine() {
      this.form.items.push(emptyLine(this.form.items.length))
    },
    removeLine(index) {
      if (this.form.items.length === 1) return this.$message.warning('至少保留一行明细')
      this.form.items.splice(index, 1)
      this.form.items.forEach((line, idx) => { line.line_no = idx + 1 })
    },
    openPicker(mode, index = -1, options = {}) {
      this.picker = { ...this.picker, visible: true, mode, index, page: options.keepQuery ? this.picker.page : 1 }
      this.loadItems()
    },
    searchItems() {
      this.picker.page = 1
      this.loadItems()
    },
    resetItems() {
      Object.assign(this.picker, { keyword: '', category_id: '', item_type: '', status: '', page: 1 })
      this.loadItems()
    },
    async loadItems() {
      this.picker.loading = true
      try {
        const { data } = await listEntity('items', {
          keyword: this.picker.keyword,
          category_id: this.picker.category_id,
          item_type: this.picker.item_type,
          status: this.picker.status,
          page: this.picker.page,
          per_page: this.picker.perPage
        })
        this.picker.rows = data.data || []
        this.picker.total = data.total || 0
      } finally {
        this.picker.loading = false
      }
    },
    isItemDisabled(item) {
      return Boolean(this.itemDisableReason(item))
    },
    itemDisableReason(item) {
      if (this.picker.mode === 'component' && item.id === this.form.output_item_id) return '产出Item不能作为组成物料'
      if (['disabled', 'inactive'].includes(item.status)) return '停用物料不能选择'
      return ''
    },
    selectItem(item) {
      if (this.isItemDisabled(item)) return this.$message.warning('停用物料或当前产出 Item 不能选择')
      if (this.picker.mode === 'output') {
        this.$set(this.form, 'output_item_id', item.id)
        this.$set(this.form, 'output_item', { ...item })
      } else {
        const row = this.form.items[this.picker.index]
        if (!row) return this.$message.error('请重新选择 BOM 明细行')
        this.$set(row, 'component_item_id', item.id)
        this.$set(row, 'component_item_code', item.item_code)
        this.$set(row, 'component_item_name', item.item_name)
        this.$set(row, 'unit_id', item.unit_id)
        this.$set(row, 'unit', item.unit ? { ...item.unit } : null)
      }
      this.picker.visible = false
    },
    async save(andSubmit = false) {
      const payload = {
        ...this.form,
        items: this.form.items.map((line, index) => ({ ...line, line_no: (index + 1) * 10 }))
      }
      if (!this.isEdit) {
        if (!this.numberReservation) return this.$message.error('BOM 编号尚未生成，请刷新页面后重试')
        payload.reservation_token = this.numberReservation.reservation_token
        payload.creation_session_id = this.numberReservation.creation_session_id
      }
      if (payload.bom_type !== 'custom') {
        payload.source_product_id = null
        payload.source_sku_id = null
        payload.source_standard_bom_id = null
      }
      if (payload.bom_type === 'custom' && (!payload.source_product_id || !payload.source_sku_id || !payload.source_standard_bom_id)) {
        return this.$message.error('定制 BOM 需要填写来源商品、来源SKU和来源标准BOM，用于后续追溯')
      }
      if (!payload.output_item_id) return this.$message.error('请选择产出 Item')
      if (payload.items.some(item => !item.component_item_id)) return this.$message.error('BOM 明细中存在未选择物料的行')
      const { data } = await saveBom(payload)
      if (!this.isEdit) clearCreatePageReservation(this.numberReservation)
      if (andSubmit) await submitBom(data.data.id)
      this.$message.success(andSubmit ? 'BOM 已保存并提交审核' : 'BOM 草稿已保存')
      this.$router.push(`/bom/${data.data.id}/detail`)
    },
    itemTypeText(value) {
      return ({ finished_product: '成品', semi_finished: '半成品', raw_material: '原材料', packaging: '包材', service: '服务' })[value] || value
    },
    lineUnitName(row) {
      const unit = row.unit || this.units.find(item => Number(item.id) === Number(row.unit_id))
      return unit ? `${unit.unit_name}${unit.unit_symbol ? `（${unit.unit_symbol}）` : ''}` : '-'
    },
    statusText(value) {
      return ({ draft: '草稿', enabled: '启用', active: '启用', disabled: '停用', inactive: '停用', archived: '已归档' })[value] || value
    },
    auditText(value) {
      return ({ pending: '待审核', approved: '已审核', rejected: '已驳回' })[value] || value
    }
  }
}
</script>

<style scoped>
.bom-form-page {
  position: relative;
  z-index: 5;
  margin-top: -52px;
  min-height: 100vh;
  background: #fff;
  color: #172033;
  font-size: 13px;
}

.page-toolbar {
  height: 52px;
  padding: 0 22px;
  border-bottom: 1px solid #e5e9ef;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #fff;
}

.page-title {
  display: flex;
  align-items: center;
  gap: 14px;
  font-size: 20px;
  font-weight: 700;
  color: #111827;
}

.back-btn {
  width: 28px;
  height: 28px;
  border: 0;
  background: transparent;
  color: #374151;
  font-size: 20px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.toolbar-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.toolbar-actions .el-button {
  min-width: 92px;
}

.form-alert {
  margin: 10px 14px 0;
}

.top-panels {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0;
  padding: 0 10px;
}

.panel {
  background: #fff;
  border: 1px solid #e4e9f0;
}

.panel h3,
.detail-title {
  margin: 0;
  padding: 16px 16px 10px;
  font-size: 16px;
  line-height: 1;
  font-weight: 700;
  color: #111827;
}

.basic-panel {
  border-radius: 4px 0 0 4px;
}

.output-panel {
  border-left: 0;
  border-radius: 0 4px 4px 0;
}

.basic-grid,
.output-grid {
  padding: 4px 20px 16px 20px;
}

.basic-grid {
  display: grid;
  grid-template-columns: 96px minmax(200px, 1fr) 92px minmax(160px, 0.76fr);
  gap: 12px 14px;
  align-items: center;
}

.output-grid {
  display: grid;
  grid-template-columns: 96px 1fr;
  gap: 16px 14px;
  align-items: center;
  padding-top: 12px;
}

.field {
  display: contents;
}

.field > span {
  color: #374151;
  font-weight: 500;
  line-height: 18px;
}

.required > span::before,
.required-head::before {
  content: '*';
  color: #f5222d;
  margin-right: 4px;
  font-weight: 700;
}

.span-2 :deep(.el-input) {
  grid-column: span 3;
}

.source-bom :deep(.el-select),
.desc-field :deep(.el-input) {
  grid-column: span 3;
}

.source-bom > span {
  line-height: 16px;
}

.standard-source-tip {
  grid-column: 1 / -1;
  color: #8b949e;
  background: #f7fafc;
  border: 1px dashed #d7e2ec;
  border-radius: 4px;
  padding: 7px 10px;
  font-size: 12px;
}

.select-like,
.item-cell {
  height: 32px;
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 10px;
  color: #172033;
  cursor: pointer;
}

.select-like.disabled,
.item-cell.disabled {
  background: #f5f7fa;
  color: #a8abb2;
  cursor: not-allowed;
}

.select-like span,
.item-cell span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.detail-panel {
  margin: 0 10px;
  border-top: 0;
  border-radius: 0 0 4px 4px;
}

.bom-lines {
  width: calc(100% - 28px);
  margin: 0 14px;
}

.line-actions {
  padding: 12px 16px 14px;
  display: flex;
  gap: 10px;
}

.delete-link {
  color: #1677d2;
}

.bottom-panels {
  display: grid;
  grid-template-columns: 0.8fr 1.2fr;
  gap: 6px;
  padding: 6px 10px 16px;
}

.version-panel,
.remark-panel {
  min-height: 118px;
  border-radius: 4px;
}

.status-row {
  padding: 18px 22px;
  display: grid;
  grid-template-columns: 80px 120px 80px 120px;
  align-items: center;
  gap: 12px;
}

.status-row span {
  color: #374151;
}

.remark-panel :deep(.el-textarea) {
  width: calc(100% - 32px);
  margin: 0 16px 16px;
}

.picker-filter {
  display: grid;
  grid-template-columns: 1fr 150px 130px 110px 64px 64px;
  gap: 8px;
  margin-bottom: 10px;
}

.picker-tip {
  color: #8b949e;
  font-size: 12px;
  margin-bottom: 10px;
}

.picker-select-cell {
  display: grid;
  gap: 3px;
  justify-items: center;
}

.picker-select-cell small {
  color: #f59e0b;
  font-size: 11px;
  line-height: 14px;
}

.item-picker .el-pagination {
  margin-top: 10px;
  text-align: right;
}

.bom-form-page :deep(.el-input__inner),
.bom-form-page :deep(.el-textarea__inner) {
  border-color: #d9e0e8;
  color: #172033;
}

.bom-form-page :deep(.el-input--small .el-input__inner),
.bom-form-page :deep(.el-date-editor.el-input),
.bom-form-page :deep(.el-date-editor.el-input__inner) {
  width: 100%;
}

.bom-form-page :deep(.el-input-number--mini) {
  width: 92px;
}

.bom-form-page :deep(.el-table th) {
  background: #f7f9fb;
  color: #1f2937;
  font-weight: 700;
}

.bom-form-page :deep(.el-table .cell) {
  padding-left: 8px;
  padding-right: 8px;
}

.bom-form-page :deep(.el-table .el-input__inner) {
  padding-left: 6px;
  padding-right: 6px;
  text-align: center;
}

.bom-form-page :deep(.el-checkbox__input.is-checked .el-checkbox__inner) {
  background-color: #13a667;
  border-color: #13a667;
}

.bom-form-page :deep(.el-button--success) {
  background: #00984f;
  border-color: #00984f;
}

.bom-form-page :deep(.el-button--success.is-plain) {
  background: #fff;
  border-color: #b6dfc7;
  color: #00984f;
}

@media (max-width: 1320px) {
  .basic-grid {
    grid-template-columns: 88px minmax(170px, 1fr) 78px minmax(140px, 0.72fr);
  }

  .page-title {
    font-size: 18px;
  }
}
</style>
