<template>
  <div class="ledger-page" :class="{'is-embedded':embedded}">
    <div class="page-head" v-if="!embedded">
      <div><h1>供应商往来</h1><p>按供应商与币种查看当前余额和期间发生额。</p></div>
      <el-button v-if="$can('finance.payable.view')" size="small" @click="$router.push({path:'/finance/payables',query:{view:'documents'}})">查看应付单据</el-button>
    </div>
    <section class="filter-card">
      <div class="filter-form">
        <label>供应商<el-input v-model.trim="filters.supplier_keyword" size="small" clearable placeholder="名称 / 编码" @keyup.enter.native="search" /></label>
        <label>币种<el-input v-model.trim="filters.currency" size="small" clearable placeholder="如 CNY / USD" maxlength="10" @input="filters.currency=String(filters.currency).toUpperCase()" /></label>
        <label>发生额期间<el-date-picker v-model="dateRange" size="small" type="daterange" value-format="yyyy-MM-dd" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" /></label>
        <label>当前付款状态<el-select v-model="filters.payment_status" size="small" clearable placeholder="全部"><el-option label="未付款" value="unpaid" /><el-option label="部分付款" value="partial" /><el-option label="已付款" value="paid" /><el-option label="质量冻结" value="frozen" /></el-select></label>
        <label>当前发票状态<el-select v-model="filters.invoice_status" size="small" clearable placeholder="全部"><el-option label="未收票" value="unreceived" /><el-option label="部分收票" value="partial" /><el-option label="已收票" value="received" /></el-select></label>
        <label>当前往来余额<el-select v-model="filters.has_balance" size="small" clearable placeholder="全部"><el-option label="有余额" value="yes" /><el-option label="无余额" value="no" /></el-select></label>
        <label class="prepayment-filter"><el-checkbox v-model="filters.only_prepayment">仅预付款、未到货</el-checkbox></label>
        <div class="filter-actions"><el-button size="small" type="success" icon="el-icon-search" @click="search">查询</el-button><el-button size="small" icon="el-icon-refresh" @click="reset">重置</el-button></div>
      </div>
      <p class="date-basis">当前余额截至查询时，不受发生额期间影响；期间按各单据业务日期、核销发生日期统计，不表示历史期初或期末余额。</p>
      <p class="date-basis">未核销预付款已扣除未核销的收退款；已核销到正式退货退款来源的金额不再重复扣减。</p>
      <div v-if="filters.supplier_id" class="supplier-context">已限定供应商编号 {{ filters.supplier_id }}<el-button type="text" @click="clearSupplier">取消限定</el-button></div>
    </section>

    <section class="table-card">
      <div class="section-head"><h2>供应商往来</h2><span>{{ total }} 条供应商 / 币种记录</span></div>
      <el-table ref="ledgerTable" v-loading="loading" :data="rows" border size="small" class="ledger-table" :row-key="rowKey">
        <el-table-column prop="supplier_code" label="供应商编码" min-width="120" />
        <el-table-column prop="supplier_name" label="供应商名称" min-width="170" />
        <el-table-column prop="currency" label="币种" width="72" />
        <el-table-column label="当前结算与余额">
          <money-column label="应付总额" prop="current_payable_amount" />
          <money-column label="货款已核销" prop="paid_amount" />
          <money-column label="未付款" prop="unpaid_amount" strong />
          <money-column label="未核销预付款" prop="prepayment_balance_amount" />
          <money-column label="待供应商退款" prop="pending_refund_amount" />
          <money-column label="净收票金额" prop="received_invoice_amount" />
          <money-column label="未收发票" prop="unreceived_invoice_amount" />
        </el-table-column>
        <el-table-column label="期间发生额">
          <money-column label="有效付款" prop="period_payment_amount" />
          <money-column label="有效收退款" prop="period_refund_amount" />
        </el-table-column>
        <el-table-column label="财务状态" min-width="105"><template slot-scope="{row}"><el-tag size="mini" :type="financeTag(row.finance_status)">{{ financeLabel(row.finance_status) }}</el-tag></template></el-table-column>
        <el-table-column label="操作" width="188"><template slot-scope="{row}"><div class="row-actions"><el-button type="text" icon="el-icon-view" @click="viewDetail(row)">往来明细</el-button><el-button v-if="$can('finance.payable.view')" type="text" @click="viewDocuments(row)">应付来源</el-button></div></template></el-table-column>
      </el-table>
      <div class="pager"><el-pagination background layout="total, sizes, prev, pager, next, jumper" :current-page="page" :page-size="perPage" :page-sizes="[10,20,50,100]" :total="total" @current-change="changePage" @size-change="changeSize" /></div>
    </section>
    <supplier-finance-entries-dialog :visible.sync="detailVisible" :supplier="detailSupplier" :initial-period="dateRange || []" />
  </div>
</template>

<script>
import { listSupplierFinanceStatistics } from '../../../api/erp/supplierFinance'
import SupplierFinanceEntriesDialog from './SupplierFinanceEntriesDialog.vue'

const blankFilters = () => ({ supplier_id: null, supplier_keyword: '', currency: '', payment_status: '', invoice_status: '', has_balance: '', only_prepayment: false })
const MoneyColumn = {
  props: ['label', 'prop', 'strong'],
  template: '<el-table-column :label="label" min-width="126" align="right"><template slot-scope="{row}"><span :class="{\'money-strong\':strong}">{{ Number(row[prop] || 0).toLocaleString(\'zh-CN\',{minimumFractionDigits:2,maximumFractionDigits:4}) }}</span></template></el-table-column>',
}

export default {
  components: { MoneyColumn, SupplierFinanceEntriesDialog },
  props: { embedded: Boolean, active: { type: Boolean, default: true } },
  data: () => ({ rows: [], loading: false, page: 1, perPage: 20, total: 0, dateRange: [], filters: blankFilters(), loaded: false, requestId: 0, detailVisible: false, detailSupplier: {} }),
  created() {
    if (this.active) this.load()
  },
  beforeDestroy() { this.requestId += 1 },
  watch: { active(value) { if (!value) return; if (!this.loaded && !this.loading) this.load(); this.$nextTick(() => this.$refs.ledgerTable?.doLayout()) } },
  methods: {
    rowKey(row) { return `${row.supplier_id}:${row.currency}` },
    params() { return { ...this.filters, only_prepayment: this.filters.only_prepayment ? 1 : 0, period_start: this.dateRange?.[0] || '', period_end: this.dateRange?.[1] || '', page: this.page, per_page: this.perPage } },
    async load() {
      if (!this.$can('finance.supplier-ledger.view')) return
      const requestId = ++this.requestId; this.loading = true
      try {
        const response = await listSupplierFinanceStatistics(this.params())
        if (requestId !== this.requestId) return
        this.rows = response.data.data || []; this.total = Number(response.data.total || 0)
        this.loaded = true
      } catch (error) {
        if (requestId !== this.requestId) return
        this.rows = []; this.total = 0
        this.$message.error(error.userMessage || '供应商往来加载失败')
      } finally { if (requestId === this.requestId) this.loading = false }
    },
    search() { this.page = 1; return this.load() },
    reset() { this.filters = blankFilters(); this.dateRange = []; return this.search() },
    clearSupplier() { this.filters.supplier_id = null; return this.search() },
    changePage(page) { this.page = page; return this.load() },
    changeSize(size) { this.perPage = size; return this.search() },
    viewDetail(row) { if (!this.$can('finance.supplier-ledger.view')) return; this.detailSupplier = { ...row }; this.detailVisible = true },
    viewDocuments(row) { if (!this.$can('finance.payable.view')) return; if (this.embedded) return this.$emit('view-documents', row); return this.$router.push({ path: '/finance/payables', query: { view: 'documents', supplier_id: row.supplier_id } }) },
    financeLabel(value) { return ({ quality_frozen: '质量冻结', pending_refund: '待退款', settled: '已结清', unclosed: '未结清' })[value] || '—' },
    financeTag(value) { return ({ quality_frozen: 'warning', pending_refund: 'danger', settled: 'success', unclosed: 'info' })[value] || 'info' },
  },
}
</script>

<style scoped>
.ledger-page, .ledger-page * { box-sizing: border-box; }
.ledger-page { min-width: 0; padding: 22px; background: #f5f7fa; }
.ledger-page.is-embedded { padding: 0; }
.page-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 16px; padding: 18px 20px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; }
.page-head > div { min-width: 0; }
.page-head h1 { margin: 0; color: #1e293b; font-size: 22px; }
.page-head p { margin: 7px 0 0; color: #64748b; font-size: 13px; }
.filter-card, .table-card { min-width: 0; margin-bottom: 14px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; }
.filter-card { padding: 16px 20px; }
.filter-form { display: flex; align-items: flex-end; flex-wrap: wrap; gap: 12px; }
.filter-form label { display: grid; gap: 6px; min-width: 0; color: #475569; font-size: 12px; }
.filter-form .el-input, .filter-form .el-select { width: 155px; }
.filter-form .el-date-editor { width: 265px; }
.filter-form .prepayment-filter { padding-bottom: 8px; }
.filter-actions, .row-actions { display: flex; align-items: center; gap: 10px; }
.filter-actions .el-button, .row-actions .el-button { margin: 0; }
.filter-actions { flex-wrap: wrap; }
.date-basis { margin: 12px 0 0; color: #64748b; font-size: 12px; line-height: 1.7; }
.supplier-context { margin-top: 8px; color: #475569; font-size: 12px; }
.supplier-context .el-button { padding: 3px 0; margin-left: 12px; }
.section-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding: 14px 16px; }
.section-head h2 { margin: 0; font-size: 15px; color: #1e293b; }
.section-head span { color: #64748b; font-size: 12px; }
.ledger-table { width: 100%; }
.ledger-table >>> .cell { white-space: normal; overflow-wrap: anywhere; line-height: 1.6; }
.ledger-table >>> .money-strong { color: #dc4b4b; font-weight: 600; }
.ledger-page >>> .el-button--text { color: #008b4b; }
.ledger-page >>> .el-table th { background: #f8fafc; }
.ledger-page >>> .el-pagination.is-background .el-pager li:not(.disabled).active { background: #008b4b; }
.pager { max-width: 100%; padding: 14px 16px; overflow-x: auto; text-align: right; }
@media (max-width: 780px) {
  .ledger-page { padding: 12px; }
  .page-head { align-items: flex-start; flex-direction: column; }
  .filter-card { padding: 12px; }
  .filter-form { display: grid; grid-template-columns: minmax(0, 1fr); }
  .filter-form .el-input, .filter-form .el-select, .filter-form .el-date-editor { width: 100%; }
  .filter-actions { gap: 8px; }
  .section-head { align-items: flex-start; }
}
</style>
