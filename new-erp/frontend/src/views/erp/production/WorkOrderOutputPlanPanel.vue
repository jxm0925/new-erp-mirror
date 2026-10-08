<template>
  <section class="output-plan-panel" v-loading="loading">
    <div class="output-plan-heading">
      <h3>计划产出</h3>
      <div>
        <el-button size="small" icon="el-icon-refresh" :disabled="saving" @click="load">刷新</el-button>
        <el-button v-if="editable || pending" size="small" type="success" icon="el-icon-edit" @click="openEditor">{{ pending ? '重试保存' : '编辑产出计划' }}</el-button>
      </div>
    </div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <div class="output-column-head"><span>物料 / 规格</span><span>产出类型</span><span>计划数量</span><span>单位</span></div>
    <article v-for="row in outputs" :key="row.line_uuid || `reference-${row.item_id}`" class="output-read-row">
      <div class="output-item"><strong>{{ row.item_code }}</strong><span>{{ row.item_name }}</span><small>{{ row.spec || '—' }}</small><el-tag v-if="row.is_reference" size="mini" type="info">当前生产目标</el-tag></div>
      <div><label>产出类型</label>{{ roleName(row.output_role) }}</div>
      <div><label>计划数量</label>{{ quantity(row.planned_base_qty) }}</div>
      <div><label>单位</label>{{ row.base_unit_name || '—' }}</div>
      <p v-if="row.remark" class="output-remark">备注：{{ row.remark }}</p>
    </article>
    <el-empty v-if="!loading && !error && !outputs.length" :image-size="55" description="尚无计划产出" />
    <work-order-operation-output-plan :work-order="workOrder" />

    <el-dialog title="编辑产出计划" :visible.sync="editorVisible" width="1040px" custom-class="work-order-output-plan-dialog" append-to-body :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving" @closed="resetEditor">
      <div v-loading="saving" class="output-editor">
        <div class="output-editor-actions"><el-button size="small" type="success" icon="el-icon-plus" :disabled="!!pending" @click="openPicker">添加产出物料</el-button></div>
        <article v-for="row in draft" :key="row.line_uuid || `reference-${row.item_id}`" class="output-edit-row">
          <div class="output-item"><strong>{{ row.item_code }}</strong><span>{{ row.item_name }}</span><small>{{ row.spec || '—' }}</small><el-tag v-if="row.is_reference" size="mini" type="info">当前生产目标</el-tag></div>
          <div class="output-edit-field"><label :for="`output-role-${row.line_uuid}`">产出类型</label><span v-if="row.is_reference">{{ roleName(row.output_role) }}</span><el-select v-else :id="`output-role-${row.line_uuid}`" v-model="row.output_role" size="small" popper-class="work-order-output-role-dropdown" :disabled="!!pending"><el-option label="产品" value="product" /><el-option label="副产品" value="by_product" /></el-select></div>
          <div class="output-edit-field"><label :for="`output-qty-${row.line_uuid}`">计划数量</label><strong v-if="row.is_reference">{{ quantity(row.planned_base_qty) }} {{ row.base_unit_name }}</strong><el-input v-else :id="`output-qty-${row.line_uuid}`" v-model.trim="row.planned_base_qty" size="small" inputmode="decimal" maxlength="29" :aria-label="`${row.item_name}计划数量`" :disabled="!!pending" placeholder="请输入数量"><template slot="append">{{ row.base_unit_name }}</template></el-input></div>
          <div class="output-edit-field"><label :for="`output-remark-${row.line_uuid}`">备注</label><el-input v-if="!row.is_reference" :id="`output-remark-${row.line_uuid}`" v-model.trim="row.remark" size="small" maxlength="500" :aria-label="`${row.item_name}备注`" :disabled="!!pending" /><span v-else>—</span></div>
          <el-button v-if="!row.is_reference" type="text" class="output-remove" icon="el-icon-delete" :disabled="!!pending" @click="remove(row)">移除</el-button>
        </article>
      </div>
      <span slot="footer"><el-button :disabled="saving" @click="editorVisible=false">取消</el-button><el-button type="success" :loading="saving" @click="save">{{ pending ? '重试保存' : '保存产出计划' }}</el-button></span>
    </el-dialog>

    <el-dialog title="选择产出物料" :visible.sync="pickerVisible" width="1080px" custom-class="work-order-output-picker-dialog" append-to-body :close-on-click-modal="false" @closed="resetPicker">
      <div class="output-picker-filters"><el-input v-model.trim="keyword" clearable placeholder="物料编码 / 名称 / 规格" @keyup.enter.native="search" @clear="search" /><el-button type="success" icon="el-icon-search" @click="search">查询</el-button><el-button icon="el-icon-refresh" @click="resetSearch">重置</el-button></div>
      <el-alert v-if="pickerError" :title="pickerError" type="error" :closable="false" />
      <div class="output-picker-body">
        <aside class="output-categories"><strong>物料分类</strong><el-button type="text" @click="selectCategory(null)">全部分类</el-button><el-tree :data="categories" node-key="id" :props="{label:'category_name',children:'children'}" :expand-on-click-node="false" default-expand-all @node-click="selectCategory" /></aside>
        <el-table :data="optionRows" v-loading="optionLoading" border size="small" height="320" @row-click="toggleSelection">
          <el-table-column width="48" align="center"><template slot-scope="{row}"><el-checkbox :value="!!selected[row.id]" :disabled="alreadyAdded(row.id)" :aria-label="`选择 ${row.item_name}`" @click.native.stop @change="toggleSelection(row)" /></template></el-table-column>
          <el-table-column prop="item_code" label="物料编码" min-width="135" />
          <el-table-column prop="item_name" label="物料名称" min-width="160" />
          <el-table-column prop="spec" label="规格型号" min-width="150" />
          <el-table-column label="单位" min-width="80"><template slot-scope="{row}">{{ optionUnit(row) }}</template></el-table-column>
        </el-table>
      </div>
      <div class="output-selected"><span>已选择 {{ Object.keys(selected).length }} 项</span><el-tag v-for="row in Object.values(selected)" :key="row.id" closable @close="$delete(selected,row.id)">{{ row.item_code }} / {{ row.item_name }}</el-tag></div>
      <div class="output-picker-pagination"><el-pagination small background layout="total, prev, pager, next" :pager-count="5" :current-page="optionPage" :page-size="20" :total="optionTotal" @current-change="loadOptions" /></div>
      <span slot="footer"><el-button @click="pickerVisible=false">取消</el-button><el-button type="success" :disabled="!Object.keys(selected).length" @click="applySelection">确认添加</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { getWorkOrderPlannedOutputs, searchWorkOrderOutputOptions, pendingWorkOrderOutputPlan, saveWorkOrderPlannedOutputs } from '../../../api/erp/production-output-plans'
import WorkOrderOperationOutputPlan from './WorkOrderOperationOutputPlan.vue'

const uuid = () => window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16) })
export default {
  name: 'WorkOrderOutputPlanPanel',
  components: { WorkOrderOperationOutputPlan },
  props: { workOrder: { type: Object, required: true } },
  data: () => ({ loading:false, saving:false, error:'', plan:null, editorVisible:false, draft:[], editorVersion:null, pending:null,
    pickerVisible:false, categories:[], categoryId:null, keyword:'', optionRows:[], optionPage:1, optionTotal:0, optionLoading:false, pickerError:'', selected:{}, loadSequence:0, optionSequence:0, categorySequence:0 }),
  computed: {
    outputs () { return this.plan?.outputs || [] },
    editable () { return this.workOrder.status === 'DRAFT' && !!this.plan?.editable && this.$can('production.work_order.edit') }
  },
  watch: { 'workOrder.business_version': { immediate:true, handler () { if (this.workOrder.id) this.load() } } },
  beforeDestroy () { this.loadSequence++; this.optionSequence++; this.categorySequence++ },
  methods: {
    async load () {
      const sequence = ++this.loadSequence
      const id = this.workOrder.id
      this.loading = true; this.error = ''
      this.pending = pendingWorkOrderOutputPlan(id)
      try { const response = await getWorkOrderPlannedOutputs(id); if (sequence === this.loadSequence && id === this.workOrder.id) this.plan = response.data.data }
      catch (error) { if (sequence === this.loadSequence) { this.plan = null; this.error = error.userMessage || '产出计划读取失败' } }
      finally { if (sequence === this.loadSequence) this.loading = false }
    },
    roleName (role) { return ({ product:'产品', by_product:'副产品' })[role] || role },
    quantity (value) { return value === null || value === undefined || value === '' ? '—' : String(value).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '') },
    optionUnit (row) { return row.base_unit_name || row.unit?.unit_name || row.unit_name || '—' },
    openEditor () {
      if ((!this.editable && !this.pending) || this.loading || this.saving) return
      this.resetEditor()
      this.pending = pendingWorkOrderOutputPlan(this.workOrder.id)
      this.editorVersion = this.pending?.payload?.expected_version || this.plan.business_version
      this.draft = JSON.parse(JSON.stringify(this.pending?.display_rows?.length ? this.pending.display_rows : this.outputs))
      this.editorVisible = true
    },
    resetEditor () { this.resetPicker(); this.pickerVisible = false; this.draft = []; this.editorVersion = null },
    remove (row) { if (row.is_reference || this.pending) return; this.draft = this.draft.filter(candidate => candidate.line_uuid !== row.line_uuid) },
    alreadyAdded (id) { return this.draft.some(row => Number(row.item_id) === Number(id)) },
    async openPicker () {
      if (this.pending || this.saving) return
      this.resetPicker(); this.pickerVisible = true
      const sequence = ++this.categorySequence
      this.loadOptions(1)
      try { const response = await searchWorkOrderOutputOptions(this.workOrder.id, { type:'categories' }); if (this.pickerVisible && sequence === this.categorySequence) this.categories = response.data.data || [] }
      catch (error) { if (this.pickerVisible && sequence === this.categorySequence) this.pickerError = error.userMessage || '分类读取失败' }
    },
    resetPicker () { this.optionSequence++; this.categorySequence++; Object.assign(this, { keyword:'',categoryId:null,optionRows:[],optionPage:1,optionTotal:0,optionLoading:false,pickerError:'',selected:{},categories:[] }) },
    async loadOptions (page = 1) {
      const sequence = ++this.optionSequence
      const id = this.workOrder.id
      this.optionPage = page; this.optionLoading = true; this.pickerError = ''
      try {
        const response = await searchWorkOrderOutputOptions(id, { type:'items',keyword:this.keyword,category_id:this.categoryId,page,per_page:20 })
        if (this.pickerVisible && sequence === this.optionSequence && id === this.workOrder.id) { this.optionRows = response.data.data || []; this.optionTotal = Number(response.data.total ?? response.data.meta?.total ?? 0) }
      } catch (error) { if (this.pickerVisible && sequence === this.optionSequence) { this.optionRows = []; this.optionTotal = 0; this.pickerError = error.userMessage || '物料读取失败' } }
      finally { if (sequence === this.optionSequence) this.optionLoading = false }
    },
    search () { this.loadOptions(1) },
    resetSearch () { this.keyword = ''; this.categoryId = null; this.search() },
    selectCategory (row) { this.categoryId = row?.id || null; this.search() },
    toggleSelection (row) { if (this.alreadyAdded(row.id)) return; this.selected[row.id] ? this.$delete(this.selected,row.id) : this.$set(this.selected,row.id,row) },
    applySelection () {
      for (const row of Object.values(this.selected)) {
        if (!this.alreadyAdded(row.id)) this.draft.push({ line_uuid:uuid(),is_reference:false,output_role:'product',item_id:row.id,item_code:row.item_code,item_name:row.item_name,spec:row.spec,
          base_unit_id:row.base_unit_id || row.unit_id,base_unit_name:this.optionUnit(row),base_unit_decimal_places:row.base_unit_decimal_places,planned_base_qty:'',remark:'' })
      }
      this.pickerVisible = false; this.resetPicker()
    },
    quantityScale (row) { return Math.min(8, Math.max(0, Number(row.base_unit_decimal_places ?? 8))) },
    validQuantity (value, scale = 8) {
      const text = String(value)
      return /^(?:0|[1-9]\d{0,19})(?:\.\d{1,8})?$/.test(text) && /[1-9]/.test(text) && !/[1-9]/.test((text.split('.')[1] || '').slice(scale))
    },
    async save () {
      if (this.saving || (!this.editable && !this.pending)) return
      const outputs = this.draft.filter(row => !row.is_reference)
      const invalid = outputs.find(row => !this.validQuantity(row.planned_base_qty, this.quantityScale(row)))
      if (!this.pending && invalid) return this.$message.error(`请填写「${invalid.item_name}」大于零的计划数量，${invalid.base_unit_name}最多支持${this.quantityScale(invalid)}位小数。`)
      this.saving = true
      try {
        await saveWorkOrderPlannedOutputs(this.workOrder.id, { expected_version:this.editorVersion,outputs:outputs.map(row => ({ line_uuid:row.line_uuid,item_id:row.item_id,output_role:row.output_role,planned_base_qty:row.planned_base_qty,remark:row.remark || '' })) }, this.draft)
        this.pending = null; this.editorVisible = false; this.resetEditor(); this.$message.success('产出计划已保存'); await this.load(); this.$emit('updated')
      } catch (error) { this.pending = pendingWorkOrderOutputPlan(this.workOrder.id); this.$message.error(error.userMessage || '产出计划保存失败，请重试'); if (!this.pending && error.response?.status === 409) { this.editorVisible = false; this.resetEditor(); await this.load(); this.$emit('updated') } }
      finally { this.saving = false }
    }
  }
}
</script>

<style scoped>
.output-plan-panel {
  box-sizing: border-box;
  min-width: 0;
  padding: 18px 20px;
  background: #fff;
  border: 1px solid #e6ebf0;
  border-radius: 5px;
  color: #33455d;
}

.output-plan-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}

.output-plan-heading h3 {
  margin: 0;
  font-size: 15px;
  color: #1d3048;
}

.output-plan-heading > div {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  min-width: 0;
  max-width: 100%;
}

.output-plan-heading .el-button + .el-button {
  margin-left: 0;
}

.output-column-head, .output-read-row {
  display: grid;
  grid-template-columns: minmax(0, 2.4fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr);
  gap: 14px;
}

.output-column-head {
  padding: 12px;
  background: #f8fafc;
  font-size: 13px;
  font-weight: 600;
}

.output-read-row {
  padding: 14px 12px;
  align-items: start;
  border-bottom: 1px solid #e6ebf0;
}

.output-read-row > div, .output-edit-row > div {
  min-width: 0;
  overflow-wrap: anywhere;
}

.output-read-row label {
  display: none;
}

.output-item {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 5px;
  font-size: 13px;
}

.output-item strong {
  color: #008b4b;
}

.output-item small {
  font-size: 12px;
  color: #64748b;
}

.output-remark {
  grid-column: 1 / -1;
  margin: 0;
  font-size: 13px;
  overflow-wrap: anywhere;
}

.output-editor-actions {
  margin-bottom: 16px;
}

.output-edit-row {
  display: grid;
  grid-template-columns: minmax(0, 1.8fr) minmax(0, 1fr) minmax(0, 1.3fr) minmax(0, 1.2fr) 58px;
  align-items: start;
  gap: 12px;
  padding: 14px 0;
  border-bottom: 1px solid #e6ebf0;
}

.output-edit-field {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.output-edit-field label {
  font-size: 12px;
  color: #64748b;
}

.output-edit-field .el-select {
  width: 100%;
}

.output-remove {
  color: #ef4444;
  align-self: center;
  padding: 0;
}

.output-picker-filters {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 14px;
}

.output-picker-filters > .el-input {
  flex: 1;
  min-width: 160px;
}

.output-picker-filters > .el-button + .el-button {
  margin-left: 0;
}

.output-picker-body {
  display: grid;
  grid-template-columns: 190px minmax(0, 1fr);
  gap: 12px;
}

.output-categories {
  box-sizing: border-box;
  min-width: 0;
  max-height: 320px;
  overflow: auto;
  border: 1px solid #e2e8f0;
  padding: 12px;
}

.output-categories strong {
  display: block;
  font-size: 13px;
}

.output-selected {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 7px;
  margin-top: 14px;
  font-size: 13px;
}

.output-selected .el-tag {
  height: auto;
  white-space: normal;
  overflow-wrap: anywhere;
  max-width: 100%;
}

.output-picker-pagination {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}

@media (max-width: 780px) {
  .output-plan-panel {
    padding: 14px;
  }
  .output-column-head {
    display: none;
  }
  .output-read-row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    padding: 14px 0;
  }
  .output-read-row > .output-item {
    grid-column: 1 / -1;
  }
  .output-read-row label {
    display: block;
    color: #64748b;
    font-size: 12px;
    margin-bottom: 5px;
  }
  .output-edit-row {
    grid-template-columns: minmax(0, 1fr);
  }
  .output-edit-row > .output-item {
    grid-column: 1 / -1;
  }
  .output-edit-field:nth-child(4) {
    grid-column: 1 / -1;
  }
  .output-remove {
    justify-self: start;
  }
  .output-picker-body {
    grid-template-columns: minmax(0, 1fr);
  }
  .output-categories {
    max-height: 140px;
  }
  .output-picker-filters > .el-input {
    flex-basis: 100%;
  }
  .output-picker-pagination {
    justify-content: flex-start;
  }
}
</style>

<style>
.work-order-output-plan-dialog, .work-order-output-picker-dialog {
  box-sizing: border-box;
  max-width: calc(100vw - 32px);
  margin-top: 5vh !important;
  border-radius: 6px;
}

.work-order-output-plan-dialog .el-dialog__body, .work-order-output-picker-dialog .el-dialog__body {
  max-height: 65vh;
  overflow-y: auto;
  box-sizing: border-box;
  padding: 16px 20px;
}

.work-order-output-plan-dialog .el-dialog__footer, .work-order-output-picker-dialog .el-dialog__footer {
  border-top: 1px solid #e6ebf0;
}

.work-order-output-picker-dialog .el-table .cell {
  white-space: normal;
  overflow-wrap: anywhere;
}

.work-order-output-picker-dialog .el-checkbox__input.is-checked .el-checkbox__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}

.work-order-output-picker-dialog .el-checkbox__inner:hover,
.work-order-output-picker-dialog .el-checkbox__input.is-focus .el-checkbox__inner,
.work-order-output-plan-dialog .el-input__inner:focus,
.work-order-output-picker-dialog .el-input__inner:focus,
.work-order-output-plan-dialog .el-select .el-input.is-focus .el-input__inner {
  border-color: #008b4b;
}

.work-order-output-picker-dialog .el-button--text,
.work-order-output-picker-dialog .el-pagination .el-pager li:hover,
.work-order-output-role-dropdown .el-select-dropdown__item.selected {
  color: #008b4b;
}

.work-order-output-picker-dialog .el-button--text:hover {
  color: #00763f;
}

.work-order-output-picker-dialog .el-tag {
  color: #00763f;
  background: #f0fdf4;
  border-color: #bbf7d0;
}

.work-order-output-picker-dialog .el-tag .el-tag__close {
  color: #00763f;
}

.work-order-output-picker-dialog .el-tag .el-tag__close:hover,
.work-order-output-picker-dialog .el-pagination.is-background .el-pager li:not(.disabled).active {
  color: #fff;
  background: #008b4b;
}

@media (max-width: 780px) {
  .work-order-output-plan-dialog .el-dialog__body, .work-order-output-picker-dialog .el-dialog__body {
    padding: 14px;
  }
}
</style>
