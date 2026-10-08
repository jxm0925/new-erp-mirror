<template>
  <section v-if="workOrder.id && workOrder.source_type !== 'stock_prebuild'" class="continuation-panel">
    <div class="panel-heading">
      <div><h3>库存续接</h3><p>选择已有工序合格库存，从其下一道工序继续生产。未选用库存的数量按本工单完整工艺生产。</p></div>
      <el-button v-if="canConfigure" size="small" type="success" plain :disabled="busy || !!pending" @click="openPicker">选择库存</el-button>
    </div>
    <div class="quantity-summary"><span>工单数量 <b>{{ quantity(targetQuantity) }}</b></span><span>库存续接 <b>{{ quantity(selectedQuantity) }}</b></span><span>新生产 <b>{{ quantity(Math.max(0, targetQuantity - selectedQuantity)) }}</b></span><span>{{ workOrder.quantity && (workOrder.quantity.base_unit_name || workOrder.quantity.unit_name) }}</span></div>
    <el-alert v-if="pending" class="pending-alert" type="warning" :closable="false" title="上次保存结果尚未确认，请重试原请求。" show-icon />
    <el-table v-if="selected.length" :data="selected" size="small" border empty-text="未选择库存续接">
      <el-table-column label="来源工单" min-width="150"><template slot-scope="{ row }">{{ row.source_work_order_no || '—' }}</template></el-table-column>
      <el-table-column label="库存来源" min-width="170"><template slot-scope="{ row }">{{ row.batch_no || `库存 #${row.inventory_balance_id}` }}<small v-if="row.serial_no" class="cell-detail">{{ row.serial_no }}</small><small v-else-if="row.inventory_serial_id" class="cell-detail">序列 #{{ row.inventory_serial_id }}</small></template></el-table-column>
      <el-table-column label="数量" width="105"><template slot-scope="{ row }">{{ quantity(row.base_qty) }}</template></el-table-column>
      <el-table-column label="从下一道工序开始" min-width="165"><template slot-scope="{ row }">{{ row.start_sequence }} · {{ row.start_operation_name || '—' }}</template></el-table-column>
      <el-table-column v-if="canConfigure" label="操作" width="85"><template slot-scope="{ row }"><el-button type="text" :disabled="busy || !!pending" @click="removeSelection(row)">移除</el-button></template></el-table-column>
    </el-table>
    <p v-else class="empty-note">本工单从首道工序开始生产。</p>
    <div v-if="canConfigure" class="panel-actions"><span v-if="dirty">库存选择尚未保存</span><el-button type="success" size="small" :loading="busy" :disabled="!dirty && !pending" @click="save">{{ pending ? '重试原保存请求' : '保存库存续接' }}</el-button></div>

    <div v-if="pickerOpen" class="picker-overlay" @click.self="closePicker">
      <section class="picker-window" role="dialog" aria-modal="true" aria-labelledby="continuation-picker-title">
        <header><h3 id="continuation-picker-title">{{ identitySource ? '选择具体库存序列号' : '选择可续接库存' }}</h3><el-button type="text" aria-label="关闭库存选择" @click="closePicker"><i class="el-icon-close" /></el-button></header>
        <div class="picker-body">
          <template v-if="!identitySource">
            <div class="search-line"><el-input v-model="keyword" size="small" clearable placeholder="编码、名称、规格、批次或来源工单" @keyup.enter.native="loadCandidates(1)" @clear="loadCandidates(1)" /><el-button type="success" size="small" :loading="loading" @click="loadCandidates(1)">查询</el-button></div>
            <el-table :data="candidates" size="small" border v-loading="loading" empty-text="暂无与本工单工艺兼容的合格库存">
              <el-table-column label="选择" width="92"><template slot-scope="{ row }"><el-checkbox v-if="row.serial_tracking_mode === 'none'" :value="!!noneSelection(row)" @change="toggleNone(row, $event)" /><el-button v-else type="text" @click="openIdentities(row)">选序列<span v-if="identityCount(row)"> ({{ identityCount(row) }})</span></el-button></template></el-table-column>
              <el-table-column label="物料 / 规格" min-width="190"><template slot-scope="{ row }">{{ row.item_code }} · {{ row.item_name }}<small class="cell-detail">{{ row.spec || '—' }}</small></template></el-table-column>
              <el-table-column label="批次 / 来源" min-width="185"><template slot-scope="{ row }">{{ row.batch_no }}<small class="cell-detail">{{ row.source_work_order_no }}</small></template></el-table-column>
              <el-table-column label="可用数量" width="110"><template slot-scope="{ row }">{{ quantity(row.available_base_qty) }} {{ row.unit_name }}</template></el-table-column>
              <el-table-column label="续接数量" width="138"><template slot-scope="{ row }"><el-input-number v-if="noneSelection(row)" v-model="noneSelection(row).base_qty" size="mini" :min="0" :max="Number(row.available_base_qty)" :precision="8" :controls="false" /><span v-else>{{ row.serial_tracking_mode === 'none' ? '—' : identityCount(row) }}</span></template></el-table-column>
              <el-table-column label="从下一道工序开始" min-width="170"><template slot-scope="{ row }">{{ row.start_sequence }} · {{ row.start_operation_name }}</template></el-table-column>
            </el-table>
            <el-pagination small layout="prev, pager, next, total" :current-page="page" :page-size="20" :total="total" :pager-count="5" @current-change="loadCandidates" />
          </template>
          <template v-else>
            <el-button type="text" @click="backToSources"><i class="el-icon-back" /> 返回库存来源</el-button>
            <p class="identity-context">{{ identitySource.item_code }} · {{ identitySource.item_name }} · {{ identitySource.batch_no }}<small class="cell-detail">{{ identitySource.start_sequence }} · {{ identitySource.start_operation_name }}；每个序列号对应一件库存。</small></p>
            <div class="search-line"><el-input v-model="serialKeyword" size="small" clearable placeholder="搜索设备编号 / 序列号" @keyup.enter.native="loadIdentities(1)" @clear="loadIdentities(1)" /><el-button type="success" size="small" :loading="serialLoading" @click="loadIdentities(1)">查询</el-button></div>
            <el-table :data="serials" size="small" border v-loading="serialLoading" empty-text="暂无可用序列号">
              <el-table-column label="选择" width="70"><template slot-scope="{ row }"><el-checkbox :value="!!draft[serialKey(identitySource, row.id)]" @change="toggleSerial(row, $event)" /></template></el-table-column>
              <el-table-column prop="serial_no" label="设备编号 / 序列号" min-width="230" />
              <el-table-column label="数量" width="85"><template>1</template></el-table-column>
            </el-table>
            <el-pagination small layout="prev, pager, next, total" :current-page="serialPage" :page-size="20" :total="serialTotal" :pager-count="5" @current-change="loadIdentities" />
          </template>
          <p class="selection-note">已选 {{ draftRows.length }} 项，续接数量 {{ quantity(draftQuantity) }}；新生产数量 {{ quantity(Math.max(0, targetQuantity - draftQuantity)) }}。</p>
          <el-alert v-if="draftQuantity > targetQuantity" type="error" :closable="false" title="续接数量超过工单数量，请调整。" />
        </div>
        <footer><el-button size="small" @click="closePicker">取消</el-button><el-button size="small" type="success" :disabled="loading || serialLoading || draftQuantity > targetQuantity" @click="applyPicker">确认选择</el-button></footer>
      </section>
    </div>
  </section>
</template>

<script>
import { configureInventoryContinuation, listInventoryContinuationCandidates, listInventoryContinuationSerials, pendingInventoryContinuation } from '../../../api/erp/production-continuations'

const sourceKey = row => `${row.inventory_balance_id}:${row.source_output_record_id}`
const keyOf = row => `${sourceKey(row)}:${row.inventory_serial_id || 'quantity'}`
const summary = rows => JSON.stringify(rows.map(row => ({ inventory_balance_id: Number(row.inventory_balance_id), source_output_record_id: Number(row.source_output_record_id), inventory_serial_id: row.inventory_serial_id ? Number(row.inventory_serial_id) : null, base_qty: String(row.base_qty) })).sort((a, b) => keyOf(a).localeCompare(keyOf(b))))
export default {
  name: 'ProductionInventoryContinuationPanel',
  props: { workOrder: { type: Object, required: true } },
  data: () => ({ selected: [], baseline: '[]', pending: null, busy: false, pickerOpen: false, draft: {}, candidates: [], loading: false, keyword: '', page: 1, total: 0,
    identitySource: null, serialKeyword: '', serials: [], serialPage: 1, serialTotal: 0, serialLoading: false }),
  computed: {
    canConfigure () { return ['DRAFT', 'WAIT_RELEASE'].includes(this.workOrder.status) && this.$can('production.work_order.edit') },
    targetQuantity () { return Number(this.workOrder.target_base_qty || this.workOrder.quantity?.target_base_qty || this.workOrder.quantity?.target_qty || 0) },
    selectedQuantity () { return this.selected.reduce((sum, row) => sum + Number(row.base_qty || 0), 0) },
    draftRows () { return Object.values(this.draft) },
    draftQuantity () { return this.draftRows.reduce((sum, row) => sum + Number(row.base_qty || 0), 0) },
    dirty () { return summary(this.selected) !== this.baseline }
  },
  watch: { workOrder: { immediate: true, handler () { this.syncFromWorkOrder() } } },
  beforeDestroy () { this.loadSequence = (this.loadSequence || 0) + 1; this.serialSequence = (this.serialSequence || 0) + 1 },
  methods: {
    quantity (value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) },
    syncFromWorkOrder () {
      this.closePicker()
      this.selected = (this.workOrder.inventory_continuation_plan || []).map(row => ({ ...row }))
      this.baseline = summary(this.selected)
      this.pending = this.workOrder.id ? pendingInventoryContinuation(this.workOrder.id) : null
    },
    removeSelection (row) { this.selected = this.selected.filter(candidate => keyOf(candidate) !== keyOf(row)) },
    openPicker () {
      if (!this.canConfigure || this.pending) return
      this.draft = Object.fromEntries(this.selected.map(row => [keyOf(row), { ...row }]))
      this.keyword = ''; this.page = 1; this.total = 0; this.candidates = []; this.pickerOpen = true; this.loadCandidates(1)
    },
    closePicker () {
      this.loadSequence = (this.loadSequence || 0) + 1; this.serialSequence = (this.serialSequence || 0) + 1
      this.pickerOpen = false; this.identitySource = null; this.serials = []; this.serialKeyword = ''; this.serialTotal = 0; this.serialPage = 1; this.serialLoading = false; this.loading = false
    },
    async loadCandidates (page) {
      const sequence = this.loadSequence = (this.loadSequence || 0) + 1; const id = this.workOrder.id
      this.page = page; this.loading = true
      try {
        const { data } = await listInventoryContinuationCandidates(id, { page, per_page: 20, keyword: this.keyword.trim() })
        if (sequence !== this.loadSequence || !this.pickerOpen || id !== this.workOrder.id) return
        this.candidates = data.data || []; this.total = Number(data.meta?.total || 0)
        for (const row of this.candidates) for (const selected of this.draftRows.filter(candidate => sourceKey(candidate) === sourceKey(row))) Object.assign(selected, row, { inventory_serial_id: selected.inventory_serial_id, base_qty: selected.base_qty })
      } catch (error) { if (sequence === this.loadSequence) this.$message.error(error.userMessage || '库存来源加载失败') }
      finally { if (sequence === this.loadSequence) this.loading = false }
    },
    noneSelection (row) { return this.draft[`${sourceKey(row)}:quantity`] },
    identityCount (row) { return this.draftRows.filter(candidate => sourceKey(candidate) === sourceKey(row) && candidate.inventory_serial_id).length },
    toggleNone (row, checked) { const key = `${sourceKey(row)}:quantity`; if (checked) this.$set(this.draft, key, { ...row, inventory_serial_id: null, base_qty: null }); else this.$delete(this.draft, key) },
    serialKey (source, id) { return `${sourceKey(source)}:${id}` },
    openIdentities (row) { this.identitySource = row; this.serials = []; this.serialKeyword = ''; this.serialTotal = 0; this.loadIdentities(1) },
    backToSources () { this.serialSequence = (this.serialSequence || 0) + 1; this.identitySource = null; this.serials = []; this.serialLoading = false },
    async loadIdentities (page) {
      const sequence = this.serialSequence = (this.serialSequence || 0) + 1; const source = this.identitySource; const id = this.workOrder.id
      if (!source) return
      this.serialPage = page; this.serialLoading = true
      try {
        const { data } = await listInventoryContinuationSerials(id, { inventory_balance_id: source.inventory_balance_id, source_output_record_id: source.source_output_record_id, keyword: this.serialKeyword.trim(), page, per_page: 20 })
        if (sequence !== this.serialSequence || !this.identitySource || sourceKey(source) !== sourceKey(this.identitySource) || !this.pickerOpen || id !== this.workOrder.id) return
        this.serials = data.data || []; this.serialTotal = Number(data.meta?.total || 0)
      } catch (error) { if (sequence === this.serialSequence) this.$message.error(error.userMessage || '序列号加载失败') }
      finally { if (sequence === this.serialSequence) this.serialLoading = false }
    },
    toggleSerial (row, checked) { const key = this.serialKey(this.identitySource, row.id); if (checked) this.$set(this.draft, key, { ...this.identitySource, inventory_serial_id: row.id, serial_no: row.serial_no, base_qty: 1 }); else this.$delete(this.draft, key) },
    validate (rows) {
      const quantities = {}; const balanceQuantities = {}; const serialIds = new Set()
      for (const row of rows) {
        const amount = Number(row.base_qty)
        if (!Number.isFinite(amount) || amount <= 0) { this.$message.warning('请填写每个库存来源的实际续接数量'); return false }
        if (!(Number(row.source_output_record_id) > 0)) { this.$message.warning('库存来源缺少正式生产产出，请重新选择'); return false }
        if (row.inventory_serial_id && amount !== 1) { this.$message.warning('每个具体序列号对应一件库存'); return false }
        if (row.inventory_serial_id && serialIds.has(Number(row.inventory_serial_id))) { this.$message.warning('不能重复选择同一库存序列号'); return false }
        if (row.inventory_serial_id) serialIds.add(Number(row.inventory_serial_id))
        const key = sourceKey(row)
        quantities[key] = (quantities[key] || 0) + amount
        balanceQuantities[row.inventory_balance_id] = (balanceQuantities[row.inventory_balance_id] || 0) + amount
        if (row.available_base_qty !== undefined && quantities[key] > Number(row.available_base_qty) + 1e-8) { this.$message.warning('续接数量超过该产出来源当前可用库存'); return false }
        if (row.inventory_balance_available_base_qty !== undefined && balanceQuantities[row.inventory_balance_id] > Number(row.inventory_balance_available_base_qty) + 1e-8) { this.$message.warning('续接总量超过该批次当前可用库存'); return false }
      }
      if (rows.reduce((sum, row) => sum + Number(row.base_qty || 0), 0) > this.targetQuantity + 1e-8) { this.$message.warning('库存续接数量超过工单数量'); return false }
      return true
    },
    applyPicker () { if (!this.validate(this.draftRows)) return; this.selected = this.draftRows.map(row => ({ ...row })); this.closePicker() },
    async save () {
      if (this.busy || !this.canConfigure || (!this.pending && !this.validate(this.selected))) return
      const id = this.workOrder.id
      this.busy = true
      try {
        await configureInventoryContinuation(id, { expected_version: this.workOrder.business_version,
          sources: this.selected.map(row => ({ inventory_balance_id: Number(row.inventory_balance_id), source_output_record_id: Number(row.source_output_record_id), inventory_serial_id: row.inventory_serial_id ? Number(row.inventory_serial_id) : null, base_qty: String(row.base_qty) })) })
        if (id !== this.workOrder.id) return
        this.pending = null; this.$message.success('库存续接已保存'); this.$emit('updated')
      } catch (error) {
        if (id !== this.workOrder.id) return
        this.pending = pendingInventoryContinuation(id); this.$message.error(error.userMessage || (this.pending ? '结果未确认，请重试原保存请求' : '库存续接保存失败'))
        if (!this.pending && Number(error.response?.status) === 409) this.$emit('updated')
      } finally { if (id === this.workOrder.id) this.busy = false }
    }
  }
}
</script>

<style scoped>
.continuation-panel{position:relative;background:#fff;border:1px solid #e6ebf0;border-radius:5px;padding:18px 20px;margin:14px 0;min-width:0;color:#33455d}.panel-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}.panel-heading>div{min-width:0}.panel-heading h3{font-size:14px;margin:0 0 8px}.panel-heading p,.empty-note{font-size:12px;color:#7f8da0;line-height:1.65;margin:0 0 12px}.quantity-summary{display:flex;align-items:center;flex-wrap:wrap;gap:10px 20px;font-size:12px;margin:8px 0 14px}.quantity-summary b{color:#26946b}.panel-actions{display:flex;justify-content:flex-end;align-items:center;flex-wrap:wrap;gap:12px;margin-top:14px;font-size:12px;color:#9c6a2a}.cell-detail{display:block;color:#7f8da0;font-size:12px;line-height:1.5;overflow-wrap:anywhere}.pending-alert{margin:12px 0}.picker-overlay{position:fixed;inset:64px 20px 20px 230px;z-index:2080;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(25,40,35,.25);box-sizing:border-box}.picker-window{width:min(100%,1050px);max-height:100%;display:flex;flex-direction:column;background:#fff;border-radius:6px;box-shadow:0 12px 36px rgba(22,43,32,.18);min-width:0}.picker-window header,.picker-window footer{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 18px;border-bottom:1px solid #e6ebf0}.picker-window header h3{margin:0;font-size:15px;min-width:0}.picker-window footer{border-bottom:0;border-top:1px solid #e6ebf0;justify-content:flex-end;flex-wrap:wrap}.picker-body{padding:16px 18px;overflow:auto;min-height:0;min-width:0}.search-line{display:flex;gap:8px;margin-bottom:14px}.search-line .el-input{min-width:0;flex:1}.search-line .el-button{flex-shrink:0}.selection-note,.identity-context{font-size:12px;line-height:1.7;margin:14px 0;overflow-wrap:anywhere}.el-pagination{text-align:right;margin-top:12px;white-space:normal}.continuation-panel .el-input-number{width:110px;max-width:100%}
@media(max-width:1000px){.picker-overlay{inset:60px 12px 12px 12px;padding:12px}}@media(max-width:600px){.continuation-panel{padding:12px}.panel-heading{flex-wrap:wrap}.quantity-summary{gap:10px}.picker-overlay{inset:56px 8px 8px;padding:6px}.picker-body{padding:12px}.picker-window header,.picker-window footer{padding:12px}.picker-body .el-pagination{font-size:11px}.picker-body .el-pagination /deep/ .el-pagination__total{display:block;text-align:right}.panel-actions .el-button{margin-left:0}}
</style>
