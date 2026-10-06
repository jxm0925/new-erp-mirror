<template>
  <div class="sales-finance-panel" v-loading="loading">
    <div class="finance-panel-toolbar">
      <div><b>{{ overview.sales_order_no || '采购关联' }}</b><span class="muted">采购关联支持多批次，并保留调整记录</span></div>
      <div class="panel-actions"><el-button v-if="canManage" size="small" type="primary" :disabled="loading || !!error || !canAdd" @click="openPicker">关联采购明细</el-button><el-button size="small" @click="load">刷新</el-button></div>
    </div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <template v-if="overview.settlement && !error">
      <dl class="finance-facts"><div><dt>订单应收（{{ overview.currency || '币种未记录' }}）</dt><dd>{{ money(overview.settlement.contract_amount) }}</dd></div><div><dt>已核销净收款</dt><dd>{{ money(overview.settlement.net_received_amount) }}</dd></div><div><dt>未收金额</dt><dd>{{ money(overview.settlement.outstanding_amount) }}</dd></div><div><dt>关联采购订单</dt><dd>{{ overview.purchase_order_count }} 张</dd></div></dl>
      <h3 class="purchase-links-title">采购关联记录</h3>
      <el-table :data="links" border size="small" empty-text="暂无采购关联记录">
        <el-table-column label="采购单 / 供应商" min-width="190"><template slot-scope="{row}"><b>{{ row.source_snapshot.order_no }}</b><div class="muted">{{ row.source_snapshot.supplier_name || '-' }}</div></template></el-table-column>
        <el-table-column label="物料 / 规格" min-width="170"><template slot-scope="{row}">{{ row.source_snapshot.item_code }} · {{ row.source_snapshot.item_name }}<div class="muted">{{ row.source_snapshot.spec_model || '规格未记录' }}</div></template></el-table-column>
        <el-table-column label="归属数量" min-width="110"><template slot-scope="{row}">{{ qty(row.purchase_qty) }} {{ row.source_snapshot.purchase_unit_name || '单位未记录' }}</template></el-table-column>
        <el-table-column label="说明 / 操作人" min-width="180"><template slot-scope="{row}">{{ row.reason }}<div class="muted">{{ row.created_by }} · {{ date(row.created_at) }}</div><div v-if="row.status === 'reversed'" class="muted">撤销：{{ row.reverse_reason }} · {{ row.reversed_by }}</div></template></el-table-column>
        <el-table-column label="状态" width="85"><template slot-scope="{row}"><el-tag size="mini" :type="row.status === 'active' ? 'success' : 'info'">{{ row.status === 'active' ? '有效' : '已撤销' }}</el-tag></template></el-table-column>
        <el-table-column v-if="canManage" label="操作" width="75"><template slot-scope="{row}"><el-button v-if="row.status === 'active'" type="text" size="mini" :disabled="busy || loading" @click="reverse(row)">撤销</el-button></template></el-table-column>
      </el-table>
      <el-pagination class="panel-pagination" small layout="total, prev, pager, next" :current-page="linkPage" :page-size="10" :total="linkTotal" @current-change="changeLinkPage" />
    </template>
    <el-dialog title="关联采购明细" :visible.sync="pickerVisible" width="1040px" class="sales-purchase-picker" append-to-body :close-on-click-modal="false" :close-on-press-escape="!busy" :show-close="!busy" :before-close="closePicker" @closed="pickerClosed">
      <el-alert v-if="linkError" class="candidate-error" :title="linkError" :type="linkConflict ? 'warning' : 'error'" :closable="false" show-icon :description="linkConflict ? '已保留当前选择、数量和说明。请载入最新数据后核对，再保存关联。' : ''" />
      <div class="candidate-filters"><el-input v-model.trim="candidateFilters.keyword" clearable size="small" :disabled="busy" placeholder="采购单号 / 供应商 / 物料编码、名称、规格" @keyup.enter.native="searchCandidates" @clear="searchCandidates" /><el-button size="small" type="primary" :disabled="busy" @click="searchCandidates">查询</el-button></div>
      <div class="candidate-body"><aside class="candidate-categories"><el-button type="text" @click="chooseCategory(null)">全部分类</el-button><el-tree :data="categoryTree" node-key="id" :props="{label: 'category_name', children: 'children'}" :expand-on-click-node="false" highlight-current @node-click="chooseCategory" /></aside><div class="candidate-results">
        <el-table :data="candidates" v-loading="candidateLoading" border size="small" height="300" @row-click="selectCandidate">
          <el-table-column width="42"><template slot-scope="{row}"><el-radio :value="selected && selected.id" :label="row.id" :disabled="Number(row.available_purchase_qty) <= 0" @change="selectCandidate(row)"><span /></el-radio></template></el-table-column>
          <el-table-column label="采购单 / 供应商" min-width="180"><template slot-scope="{row}">{{ row.order_no }}<div class="muted">{{ row.supplier_name }}</div></template></el-table-column>
          <el-table-column label="物料 / 规格" min-width="160"><template slot-scope="{row}">{{ row.item_code }} · {{ row.item_name }}<div class="muted">{{ row.spec_model || '规格未记录' }}</div></template></el-table-column>
          <el-table-column label="未归属数量" min-width="105"><template slot-scope="{row}">{{ qty(row.available_purchase_qty) }} {{ row.purchase_unit_name || '单位未记录' }}</template></el-table-column>
        </el-table><el-pagination class="panel-pagination" small layout="total, prev, pager, next" :current-page="candidatePage" :page-size="10" :total="candidateTotal" @current-change="changeCandidatePage" />
      </div></div>
      <div class="candidate-selection"><p>{{ selected ? `已选：${selected.order_no} · ${selected.item_name} · ${selected.spec_model || '规格未记录'}` : '请先选择一条采购明细；切换页码会保留当前选择。' }}</p><p v-if="selected" class="muted">未归属数量：{{ qty(selected.available_purchase_qty) }} {{ selected.purchase_unit_name || '单位未记录' }}</p><el-form label-position="top" size="small"><div class="candidate-form-grid"><el-form-item :label="`归属数量${selected ? '（' + (selected.purchase_unit_name || '单位未记录') + '）' : ''}`"><el-input v-model.trim="linkForm.purchase_qty" :disabled="!selected || busy" placeholder="填写本销售订单使用的采购数量" /></el-form-item><el-form-item label="关联说明"><el-input v-model.trim="linkForm.reason" :disabled="busy" maxlength="500" placeholder="填写本批采购的用途或归属依据" /></el-form-item></div></el-form></div>
      <span slot="footer"><el-button v-if="linkConflict" :loading="refreshingLink" :disabled="busy && !refreshingLink" @click="refreshLinkContext">载入最新数据</el-button><el-button :disabled="busy" @click="pickerVisible = false">取消</el-button><el-button type="primary" :loading="busy && !refreshingLink" :disabled="busy || !selected || candidateLoading || linkConflict" @click="saveLink">保存关联</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import { getSalesOrderFinance, listSalesPurchaseLinks, listSalesPurchaseCandidates, listSalesPurchaseCategories, addSalesPurchaseLink, reverseSalesPurchaseLink } from '@/api/erp/salesFinance'

export default {
  props: { orderId: { type: [Number, String], required: true } },
  data: () => ({ loading: false, busy: false, error: '', overview: {}, links: [], linkPage: 1, linkTotal: 0, pickerVisible: false, candidates: [], categories: [], candidateFilters: { keyword: '', category_id: null }, candidatePage: 1, candidateTotal: 0, candidateLoading: false, candidateRequest: 0, selected: null, linkForm: { purchase_qty: '', reason: '', idempotency_key: '' }, linkConflict: false, linkError: '', refreshingLink: false, linkRecoveryRequest: 0, loadRequest: 0 }),
  computed: {
    canManage() { return this.$can('purchase.order.edit') },
    canAdd() { return ['confirmed', 'closed'].includes(this.overview.order_status) },
    categoryTree() {
      const nodes = new Map(this.categories.map(row => [row.id, { ...row, children: [] }]))
      const roots = []
      nodes.forEach(row => { if (row.parent_id && nodes.has(row.parent_id)) nodes.get(row.parent_id).children.push(row); else roots.push(row) })
      return roots
    }
  },
  watch: { busy(value) { this.$emit('busy-change', value) }, orderId: { immediate: true, handler() { this.linkRecoveryRequest++; this.candidateRequest++; this.linkConflict = false; this.linkError = ''; this.selected = null; this.linkPage = 1; this.overview = {}; this.pickerVisible = false; this.load() } } },
  beforeDestroy() { this.loadRequest++; this.candidateRequest++; this.linkRecoveryRequest++ },
  methods: {
    money(value) { return value === null || value === undefined || value === '' ? '待确认' : Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) },
    qty(value) { return value === null || value === undefined ? '-' : String(value).replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1') },
    date(value) { return value ? String(value).slice(0, 10) : '-' },
    async load() {
      if (!this.orderId) return
      const request = ++this.loadRequest; this.loading = true; this.error = ''
      try {
        const [overview, links] = await Promise.all([getSalesOrderFinance(this.orderId), listSalesPurchaseLinks(this.orderId, { page: this.linkPage, per_page: 10 })])
        if (request !== this.loadRequest) return
        this.overview = overview.data.data; this.links = links.data.data; this.linkTotal = links.data.total
      } catch (error) { if (request === this.loadRequest) this.error = error.userMessage || '采购关联加载失败，请重试。' }
      finally { if (request === this.loadRequest) this.loading = false }
    },
    changeLinkPage(page) { this.linkPage = page; return this.load() },
    async openPicker() {
      if (this.busy) return
      this.linkRecoveryRequest++; this.candidateRequest++; this.linkConflict = false; this.linkError = ''; this.refreshingLink = false
      this.selected = null; this.candidateFilters = { keyword: '', category_id: null }; this.candidatePage = 1; this.candidates = []; this.candidateTotal = 0
      this.linkForm = { purchase_qty: '', reason: '', idempotency_key: `sales-purchase-${Date.now()}-${Math.random().toString(36).slice(2)}` }; this.pickerVisible = true
      try { const response = await listSalesPurchaseCategories(this.orderId); this.categories = response.data.data || [] } catch (error) { this.$message.error(error.userMessage || '物料分类加载失败') }
      return this.loadCandidates()
    },
    closePicker(done) { if (!this.busy) done() },
    pickerClosed() { if (!this.pickerVisible) { this.linkRecoveryRequest++; this.candidateRequest++; this.candidateLoading = false; this.linkConflict = false; this.linkError = '' } },
    searchCandidates() { if (this.busy) return; this.candidatePage = 1; return this.loadCandidates() },
    chooseCategory(category) { if (this.busy) return; this.candidateFilters.category_id = category ? category.id : null; return this.searchCandidates() },
    changeCandidatePage(page) { if (this.busy) return; this.candidatePage = page; return this.loadCandidates() },
    async loadCandidates() {
      if (this.busy) return
      const request = ++this.candidateRequest; this.candidateLoading = true
      try { const response = await listSalesPurchaseCandidates(this.orderId, { ...this.candidateFilters, page: this.candidatePage, per_page: 10 }); if (request === this.candidateRequest) { this.candidates = response.data.data; this.candidateTotal = response.data.total } }
      catch (error) { if (request === this.candidateRequest) { this.candidates = []; this.candidateTotal = 0; this.$message.error(error.userMessage || '采购明细加载失败') } }
      finally { if (request === this.candidateRequest) this.candidateLoading = false }
    },
    selectCandidate(row) { if (Number(row.available_purchase_qty) <= 0 || this.busy) return; if (!this.selected || this.selected.id !== row.id) this.linkForm.purchase_qty = ''; this.selected = { ...row } },
    async refreshLinkContext() {
      if (this.busy || !this.linkConflict || !this.selected) return
      const request = ++this.linkRecoveryRequest
      const orderId = this.orderId; const selectedId = this.selected.id
      this.candidateRequest++; this.candidateLoading = false
      this.loadRequest++; this.loading = false
      this.busy = true; this.refreshingLink = true
      try {
        const [overview, candidates] = await Promise.all([
          getSalesOrderFinance(orderId),
          listSalesPurchaseCandidates(orderId, { purchase_order_item_id: selectedId, page: 1, per_page: 1 }),
        ])
        if (request !== this.linkRecoveryRequest || this.orderId !== orderId || !this.pickerVisible) return
        const selected = (candidates.data.data || []).find(row => Number(row.id) === Number(selectedId))
        this.overview = overview.data.data
        if (!this.canAdd) throw new Error('销售订单状态已变化，当前不能新增采购关联。')
        if (!selected) throw new Error('所选采购明细已不可关联，请重新选择明细后载入最新数据。')
        this.selected = { ...selected }
        this.candidates = this.candidates.map(row => Number(row.id) === Number(selectedId) ? { ...selected } : row)
        this.linkConflict = false; this.linkError = ''
        this.$message.success('最新版本和未归属数量已载入，请核对数量后保存。')
      } catch (error) {
        if (request === this.linkRecoveryRequest && this.orderId === orderId && this.pickerVisible) this.linkError = error.userMessage || error.message || '最新数据加载失败，请重试。'
      } finally { this.busy = false; this.refreshingLink = false }
    },
    async saveLink() {
      if (this.busy || !this.selected || this.linkConflict || this.candidateLoading) return
      if (!/^\d{1,10}(\.\d{1,8})?$/.test(this.linkForm.purchase_qty) || Number(this.linkForm.purchase_qty) <= 0 || Number(this.linkForm.purchase_qty) > Number(this.selected.available_purchase_qty)) return this.$message.warning('请填写大于零且不超过未归属数量的采购数量。')
      if (!this.linkForm.reason.trim()) return this.$message.warning('请填写关联说明。')
      this.busy = true; this.linkError = ''
      try { await addSalesPurchaseLink(this.orderId, { ...this.linkForm, purchase_order_item_id: this.selected.id, version: this.overview.version }); this.pickerVisible = false; this.linkPage = 1; await this.load(); this.$message.success('采购关联已保存') }
      catch (error) {
        const errors = error.response?.data?.errors || {}
        this.linkConflict = error.response?.status === 409 || ['version', 'purchase_qty', 'purchase_order_item_id', 'sales_order_id'].some(field => Boolean(errors[field]))
        this.linkError = error.userMessage || Object.values(errors).flat().find(Boolean) || '关联保存失败，请核实后重试。'
        this.$message.error(this.linkError)
      }
      finally { this.busy = false }
    },
    async reverse(row) {
      if (this.busy) return
      try { const { value } = await this.$prompt('撤销后会释放采购归属数量，原关联和撤销原因仍保留。', '撤销采购关联', { inputPlaceholder: '填写撤销原因', inputValidator: value => !!(value || '').trim() && value.length <= 500 || '请填写500字以内的撤销原因' }); this.busy = true; await reverseSalesPurchaseLink(this.orderId, row.id, { version: this.overview.version, reason: value.trim() }); await this.load(); this.$message.success('采购关联已撤销') }
      catch (error) { if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '撤销失败，请刷新后重试。') }
      finally { this.busy = false }
    },
  }
}
</script>

<style scoped>
.candidate-error{margin-bottom:14px}
.purchase-links-title{margin:18px 0 12px;font-size:14px;font-weight:600}
.sales-finance-panel{min-width:0;color:#303133}.finance-panel-toolbar,.panel-actions,.candidate-filters{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.finance-panel-toolbar{justify-content:space-between;margin-bottom:16px}.finance-panel-toolbar .muted{display:block;margin-top:5px}.muted{font-size:12px;color:#737b87;line-height:1.65}.finance-facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:16px 0}.finance-facts>div{min-width:0}.finance-facts dt{font-size:12px;color:#737b87}.finance-facts dd{font-size:18px;font-weight:600;margin:8px 0;overflow-wrap:anywhere}.panel-pagination{margin:14px 0;text-align:right;max-width:100%;overflow:auto}.candidate-filters{margin-bottom:14px}.candidate-filters .el-input{flex:1;min-width:180px}.candidate-body{display:flex;gap:14px;min-width:0}.candidate-categories{flex:0 0 160px;max-height:345px;overflow:auto;border-right:1px solid #ebeef5;padding-right:10px}.candidate-results{flex:1;min-width:0}.candidate-selection{margin-top:12px;padding:10px 14px;background:#f5f7fa;border-radius:6px}.candidate-selection p{margin:0 0 10px;font-size:13px;overflow-wrap:anywhere}.candidate-form-grid{display:grid;grid-template-columns:1fr 2fr;gap:14px}.candidate-form-grid .el-form-item{margin-bottom:0}@media(max-width:700px){.finance-facts{grid-template-columns:repeat(2,minmax(0,1fr))}.candidate-body{display:block}.candidate-categories{max-height:140px;border-right:0;border-bottom:1px solid #ebeef5;margin-bottom:10px}.candidate-form-grid{grid-template-columns:1fr}.finance-panel-toolbar{align-items:flex-start}.panel-actions{width:100%}}
</style>
<style>
.sales-purchase-picker.el-dialog__wrapper{display:flex;align-items:center;justify-content:center}.sales-purchase-picker .el-dialog{width:min(1040px,calc(100vw - 24px))!important;max-height:calc(100vh - 28px);margin:0!important;display:flex;flex-direction:column}.sales-purchase-picker .el-dialog__body{overflow:auto;min-height:0;padding:18px}.sales-purchase-picker .el-dialog__header,.sales-purchase-picker .el-dialog__footer{flex:none}.sales-purchase-picker .el-radio__label{padding:0}
</style>
