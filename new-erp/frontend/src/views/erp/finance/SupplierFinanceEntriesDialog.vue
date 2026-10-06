<template>
  <el-dialog :visible="visible" :title="`${supplier.supplier_name || '供应商'} · 往来明细`" class="supplier-ledger-dialog" width="min(1180px, calc(100vw - 32px))" top="5vh" append-to-body :close-on-click-modal="false" @close="close">
    <p class="ledger-basis">到货、退货、收付与核销分别记录，不将不同性质金额相加作为欠款；作废及撤销记录保留。</p>
    <div class="entry-filters">
      <label>单据号<el-input v-model.trim="filters.keyword" size="small" clearable placeholder="来源 / 关联单据号" @keyup.enter.native="search" /></label>
      <label>记录类型<el-select v-model="filters.event_family" size="small" clearable placeholder="全部类型"><el-option label="到货结算" value="receipt" /><el-option label="退货与退款义务" value="return" /><template v-if="$can('finance.view')"><el-option label="实际付款 / 收退款" value="cash" /><el-option label="核销 / 撤销核销" value="allocation" /><el-option label="资金单作废" value="void" /></template></el-select></label>
      <label>发生日期<el-date-picker v-model="dateRange" size="small" type="daterange" value-format="yyyy-MM-dd" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" /></label>
      <div class="entry-actions"><el-button size="small" type="success" icon="el-icon-search" @click="search">查询</el-button><el-button size="small" @click="reset">重置</el-button></div>
    </div>
    <p v-if="!cashDetailsVisible" class="permission-note">当前权限可查看到货及退货来源；付款、收退款与核销明细需要财务查看权限。</p>
    <el-table v-loading="loading" :data="rows" border size="small" row-key="event_key" class="entries-table">
      <el-table-column prop="business_date" label="发生日期" width="108" />
      <el-table-column prop="event_label" label="记录类型" min-width="132" />
      <el-table-column label="来源单据" min-width="165"><template slot-scope="{row}"><el-button v-if="documentRoute(row)" type="text" @click="openDocument(row)">{{ row.document_no }}</el-button><span v-else>{{ row.document_no || '—' }}</span></template></el-table-column>
      <el-table-column label="关联单据" min-width="165"><template slot-scope="{row}"><el-button v-if="relatedRoute(row)" type="text" @click="openRelated(row)">{{ row.related_document_no || `记录 #${row.related_document_id}` }}</el-button><span v-else>{{ row.related_document_no || '—' }}</span></template></el-table-column>
      <el-table-column prop="currency" label="币种" width="70" />
      <el-table-column label="记录金额" width="125" align="right"><template slot-scope="{row}">{{ money(row.amount) }}</template></el-table-column>
      <el-table-column prop="amount_meaning" label="金额性质 / 方向" min-width="160" />
      <el-table-column label="状态" min-width="106"><template slot-scope="{row}"><el-tag size="mini" :type="statusTag(row.status)">{{ statusText(row.status) }}</el-tag></template></el-table-column>
      <el-table-column label="来源现状" min-width="190"><template slot-scope="{row}"><template v-if="row.current_payable_amount !== null"><div>当前应付 {{ money(row.current_payable_amount) }}</div><div>质量冻结 {{ money(row.quality_frozen_amount) }}</div></template><span v-else>—</span></template></el-table-column>
    </el-table>
    <div class="entry-footer"><span>{{ supplier.currency || '各币种' }} · 共 {{ total }} 条</span><el-pagination background layout="sizes, prev, pager, next" :current-page="page" :page-size="perPage" :page-sizes="[10,20,50,100]" :total="total" @current-change="changePage" @size-change="changeSize" /></div>
    <span slot="footer"><el-button size="small" @click="close">关闭</el-button></span>
  </el-dialog>
</template>

<script>
import { listSupplierFinanceEntries } from '../../../api/erp/supplierFinance'

export default {
  props: { visible: Boolean, supplier: { type: Object, default: () => ({}) }, initialPeriod: { type: Array, default: () => [] } },
  data: () => ({ rows: [], total: 0, loading: false, page: 1, perPage: 20, dateRange: [], filters: { keyword: '', event_family: '' }, requestId: 0, cashDetailsVisible: false }),
  watch: {
    visible(value) { if (value) this.open(); else this.invalidate() },
    supplier() { if (this.visible) this.open() },
  },
  beforeDestroy() { this.invalidate() },
  methods: {
    money(value) { return Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) },
    invalidate() { this.requestId += 1; this.loading = false },
    open() {
      this.invalidate(); this.rows = []; this.total = 0; this.page = 1
      this.filters = { keyword: '', event_family: '' }; this.dateRange = [...this.initialPeriod]
      this.cashDetailsVisible = this.$can('finance.view')
      return this.load()
    },
    async load() {
      if (!this.visible || !this.supplier.supplier_id || !this.$can('finance.supplier-ledger.view')) return
      const requestId = ++this.requestId; this.loading = true
      try {
        const response = await listSupplierFinanceEntries(this.supplier.supplier_id, { ...this.filters, currency: this.supplier.currency || '', period_start: this.dateRange?.[0] || '', period_end: this.dateRange?.[1] || '', page: this.page, per_page: this.perPage })
        if (requestId !== this.requestId) return
        this.rows = response.data.data || []; this.total = Number(response.data.total || 0)
        this.cashDetailsVisible = response.data.cash_details_visible === true
      } catch (error) {
        if (requestId !== this.requestId) return
        this.rows = []; this.total = 0; this.$message.error(error.userMessage || '往来明细加载失败')
      } finally { if (requestId === this.requestId) this.loading = false }
    },
    search() { this.page = 1; return this.load() },
    reset() { this.filters = { keyword: '', event_family: '' }; this.dateRange = []; return this.search() },
    changePage(page) { this.page = page; return this.load() },
    changeSize(size) { this.perPage = size; return this.search() },
    close() { this.invalidate(); this.$emit('update:visible', false) },
    routeFor(type, id, direction) {
      if (!id) return null
      if (type === 'cash_document' && this.$can('finance.view')) return `/finance/${direction === 'receipt' ? 'receipts' : 'payments'}/${id}`
      if (type === 'purchase_settlement_source' && this.$can('finance.payable.view')) return { path: '/finance/payables', query: { view: 'documents', source_id: id, supplier_id: this.supplier.supplier_id } }
      if (['purchase_return', 'purchase_return_supplier_refund', 'purchase_return_ap_offset'].includes(type) && this.$can('purchase_return.view')) return `/purchase/returns/${id}/detail`
      return null
    },
    documentRoute(row) { return this.routeFor(row.document_type, row.document_id, row.cash_direction) },
    relatedRoute(row) { return this.routeFor(row.related_document_type, row.related_document_id, row.cash_direction) },
    openDocument(row) { const route = this.documentRoute(row); if (route) { this.close(); this.$router.push(route) } },
    openRelated(row) { const route = this.relatedRoute(row); if (route) { this.close(); this.$router.push(route) } },
    statusText(value) { return ({ active: '有效', reversed: '已撤销', reversal: '撤销记录', confirmed: '已确认', voided: '已作废', completed: '已完成', pending_outbound: '待退货完成', cancelled: '已取消', closed: '已关闭', open: '待付款', partially_paid: '部分付款', paid: '已付款', frozen: '质量冻结', pending: '待结算', offset: '已抵扣' })[value] || value },
    statusTag(value) { return ['voided', 'reversed', 'cancelled'].includes(value) ? 'danger' : ['confirmed', 'active', 'completed', 'paid'].includes(value) ? 'success' : 'info' },
  },
}
</script>

<style scoped>
.supplier-ledger-dialog >>> .el-dialog { display: flex; flex-direction: column; max-height: 90vh; border-radius: 8px; }
.supplier-ledger-dialog >>> .el-dialog__body { min-width: 0; overflow: auto; padding: 14px 20px; }
.supplier-ledger-dialog >>> .el-dialog__header, .supplier-ledger-dialog >>> .el-dialog__footer { flex-shrink: 0; }
.supplier-ledger-dialog >>> .el-dialog__title { display: block; padding-right: 20px; overflow-wrap: anywhere; }
.ledger-basis, .permission-note { margin: 0 0 14px; color: #64748b; font-size: 12px; line-height: 1.7; }
.permission-note { color: #a16207; }
.entry-filters { display: flex; align-items: flex-end; flex-wrap: wrap; gap: 12px; margin-bottom: 14px; }
.entry-filters label { display: grid; gap: 6px; min-width: 0; color: #475569; font-size: 12px; }
.entry-filters .el-input, .entry-filters .el-select { width: 175px; }
.entry-filters .el-date-editor { width: 270px; }
.entry-actions { display: flex; gap: 8px; }
.entry-actions .el-button { margin: 0; }
.entries-table { width: 100%; }
.entries-table >>> .cell { white-space: normal; overflow-wrap: anywhere; line-height: 1.6; }
.entries-table >>> .el-button--text { color: #008b4b; white-space: normal; text-align: left; }
.entry-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-top: 14px; color: #64748b; font-size: 12px; }
.entry-footer .el-pagination { max-width: 100%; overflow-x: auto; }
.supplier-ledger-dialog >>> .el-pagination.is-background .el-pager li:not(.disabled).active { background: #008b4b; }
@media (max-width: 780px) {
  .supplier-ledger-dialog >>> .el-dialog__body { padding: 12px; }
  .entry-filters { display: grid; grid-template-columns: minmax(0, 1fr); }
  .entry-filters .el-input, .entry-filters .el-select, .entry-filters .el-date-editor { width: 100%; }
  .entry-footer { align-items: flex-start; }
}
</style>
