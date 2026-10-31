<template>
  <main v-loading="loading" class="invoice-match-page">
    <header class="page-head">
      <div class="head-left">
        <div class="title-row">
          <h1 class="page-title">进项发票匹配</h1>
          <span class="doc-pill">{{ invoice.document_no || '—' }}</span>
          <el-tag size="small" :type="isFullyMatched ? 'success' : 'warning'">
            {{ isFullyMatched ? '已足额匹配' : '待完成匹配' }}
          </el-tag>
        </div>
        <p class="page-desc">将进项发票与采购结算单进行金额核销匹配，匹配完成后方可确认发票并进入现金付款分配。</p>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-back" @click="$router.push('/finance/invoices')">返回发票列表</el-button>
        <el-button size="small" icon="el-icon-view" @click="$router.push(`/finance/invoices/${id}`)">查看详情</el-button>
        <el-button size="small" icon="el-icon-check" :loading="saving" :disabled="isFullyMatched" @click="saveMatches">保存匹配</el-button>
        <el-button size="small" type="success" class="green-button" icon="el-icon-circle-check" :loading="saving" @click="confirmMatches">确认匹配</el-button>
      </div>
    </header>

    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>发票匹配以「价税合计」为口径；税额随采购结算来源一并匹配，不可单独追加。勾选下方可匹配结算来源后可按需调整本次匹配金额。</span>
    </div>

    <section class="invoice-summary">
      <div class="summary-item">
        <span class="summary-label">发票记录号</span>
        <b class="summary-val mono">{{ invoice.document_no || '—' }}</b>
      </div>
      <div class="summary-item">
        <span class="summary-label">发票号码 / 代码</span>
        <b class="summary-val mono">
          {{ invoice.invoice_no || '—' }}<i v-if="invoice.invoice_code"> / {{ invoice.invoice_code }}</i>
        </b>
      </div>
      <div class="summary-item">
        <span class="summary-label">供应商名称</span>
        <b class="summary-val" :title="invoice.party_name_snapshot">{{ invoice.party_name_snapshot || '—' }}</b>
      </div>
      <div class="summary-item">
        <span class="summary-label">开票日期</span>
        <b class="summary-val">{{ invoice.invoice_date || '—' }}</b>
      </div>
      <div class="summary-item metric">
        <span class="summary-label">发票价税合计 (CNY)</span>
        <strong class="summary-num">¥{{ money(invoice.amount_incl_tax) }}</strong>
      </div>
      <div class="summary-item metric">
        <span class="summary-label">已匹配金额 (CNY)</span>
        <strong class="summary-num green">¥{{ money(currentMatched) }}</strong>
      </div>
      <div class="summary-item metric">
        <span class="summary-label">待匹配余额 (CNY)</span>
        <strong class="summary-num" :class="unmatchedAmount > 0 ? 'red' : 'muted'">¥{{ money(unmatchedAmount) }}</strong>
      </div>
    </section>

    <section class="source-card">
      <el-form class="filter-panel" size="small" @submit.native.prevent="search">
        <div class="filter-grid">
          <div class="filter-field">
            <span class="field-label">匹配来源</span>
            <el-select v-model="sourceType">
              <el-option label="采购结算单" value="purchase_settlement_source" />
            </el-select>
          </div>
          <div class="filter-field">
            <span class="field-label">供应商</span>
            <el-input :value="invoice.party_name_snapshot" disabled />
          </div>
          <div class="filter-field">
            <span class="field-label">业务日期</span>
            <el-date-picker
              v-model="businessDateRange"
              type="daterange"
              value-format="yyyy-MM-dd"
              range-separator="~"
              start-placeholder="开始日期"
              end-placeholder="结束日期"
            />
          </div>
          <div class="filter-field">
            <span class="field-label">结算来源单号</span>
            <el-input v-model.trim="filters.settlement_source_no" clearable placeholder="输入结算单号" @keyup.enter.native="search" />
          </div>
          <div class="filter-field">
            <span class="field-label">采购订单号</span>
            <el-input v-model.trim="filters.purchase_order_no" clearable placeholder="输入采购订单号" @keyup.enter.native="search" />
          </div>
          <div class="filter-actions">
            <el-button size="small" type="success" class="green-button" icon="el-icon-search" @click="search">查询</el-button>
            <el-button size="small" icon="el-icon-refresh-left" @click="reset">重置</el-button>
          </div>
        </div>
      </el-form>

      <div class="section-head">
        <h2>可匹配结算来源（已勾选 <b>{{ selectedCount }}</b> 条）</h2>
        <div class="summary-pills">
          <span class="pill-item">可匹配应付合计：<b>¥{{ money(summary.payable_amount) }}</b></span>
          <span class="pill-item">已开票合计：<b>¥{{ money(summary.invoiced_amount) }}</b></span>
          <span class="pill-item">可用余额合计：<b>¥{{ money(summary.available_amount) }}</b></span>
          <span class="pill-item active">本次勾选匹配：<b>¥{{ money(selectedTotal) }}</b></span>
        </div>
      </div>

      <el-table :data="rows" row-key="id" border stripe fit size="small" class="source-table" empty-text="当前供应商暂无可匹配的采购结算来源">
        <el-table-column width="48" align="center">
          <template slot="header">
            <el-checkbox :value="allPageSourcesSelected" :disabled="!matchableRows.length || isFullyMatched" @change="togglePageSelection" />
          </template>
          <template slot-scope="{ row }">
            <el-checkbox
              :value="Boolean(selected[row.id])"
              :disabled="isFullyMatched || Number(row.available_amount) <= 0"
              @change="checked => toggleSelection(row, checked)"
            />
          </template>
        </el-table-column>
        <el-table-column prop="settlement_source_no" label="结算来源单号" min-width="145" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <span class="mono-strong">{{ row.settlement_source_no || '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="purchase_order_no" label="采购订单号" min-width="135" show-overflow-tooltip />
        <el-table-column prop="receipt_no" label="收货单号" min-width="135" show-overflow-tooltip />
        <el-table-column prop="business_date" label="业务日期" min-width="104" />
        <amount-column label="当前应付 (价税合计 CNY)" prop="current_payable_amount" />
        <amount-column label="已开票金额 (CNY)" prop="invoiced_amount" />
        <amount-column label="可用金额 (CNY)" prop="available_amount" />
        <el-table-column label="本次匹配金额 (CNY)" min-width="155" align="right">
          <template slot-scope="{ row }">
            <el-input
              v-if="selected[row.id]"
              v-model.trim="selected[row.id].allocated_amount"
              size="small"
              placeholder="输入匹配金额"
              @input="validateRow(row)"
            />
            <span v-else class="muted">0.00</span>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager-bar">
        <el-pagination
          background
          layout="total, prev, pager, next, sizes, jumper"
          :current-page="page"
          :page-size="perPage"
          :page-sizes="[10, 20, 50]"
          :total="total"
          @current-change="p => { page = p; load() }"
          @size-change="s => { perPage = s; page = 1; load() }"
        />
      </div>
    </section>

    <section class="match-history">
      <div class="section-head">
        <h2>已保存匹配历史</h2>
        <span class="sub-hint">展示当前发票已保存的匹配分配记录</span>
      </div>
      <el-table :data="invoice.allocations || []" border stripe fit size="small" empty-text="暂无匹配记录">
        <el-table-column prop="source_document_no" label="结算来源单号" min-width="170" show-overflow-tooltip />
        <el-table-column prop="source_business_type" label="来源类型" min-width="150" show-overflow-tooltip />
        <el-table-column label="已保存匹配金额 (CNY)" min-width="150" align="right">
          <template slot-scope="{ row }">
            <b class="green">¥{{ money(row.allocated_amount) }}</b>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" min-width="110" align="center">
          <template slot-scope="{ row }">
            <el-tag size="mini" :type="row.status === 'active' ? 'success' : 'info'">
              {{ row.status === 'active' ? '生效中' : row.status }}
            </el-tag>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <section class="match-foot">
      <div class="match-check">
        <i :class="matchComplete || isFullyMatched ? 'el-icon-success pass' : 'el-icon-warning-outline wait'" />
        <div>
          <b>匹配校验：{{ matchComplete || isFullyMatched ? '已满足足额匹配' : '待完成足额匹配' }}</b>
          <small>发票价税合计 = 已保存匹配金额 + 本次勾选匹配金额</small>
        </div>
      </div>
      <div class="foot-metric">
        <span>已保存匹配金额 (CNY)</span>
        <b class="green">¥{{ money(currentMatched) }}</b>
      </div>
      <div class="foot-metric">
        <span>本次勾选匹配金额 (CNY)</span>
        <b class="green">¥{{ money(selectedTotal) }}</b>
      </div>
      <div class="foot-metric">
        <span>保存后剩余未匹配金额 (CNY)</span>
        <b :class="Math.abs(remainingAfterSelection) < 0.0001 ? 'green' : 'red'">¥{{ money(remainingAfterSelection) }}</b>
      </div>
    </section>
  </main>
</template>

<script>
import { getFinanceInvoice, listInvoiceMatchingSources, saveFinanceInvoiceMatches, confirmFinanceInvoice } from '../../../api/erp/finance'

export default {
  name: 'FinanceInvoiceMatch',
  components: {
    AmountColumn: {
      props: ['label', 'prop'],
      methods: {
        f(v) {
          return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        }
      },
      template: '<el-table-column :label="label" min-width="126" align="right"><template slot-scope="{row}">¥{{f(row[prop])}}</template></el-table-column>'
    }
  },
  data: () => ({
    loading: false,
    saving: false,
    invoice: {},
    rows: [],
    total: 0,
    page: 1,
    perPage: 10,
    summary: {},
    sourceType: 'purchase_settlement_source',
    businessDateRange: [],
    filters: {
      settlement_source_no: '',
      purchase_order_no: ''
    },
    selected: {}
  }),
  computed: {
    id() {
      return Number(this.$route.params.id)
    },
    selectedCount() {
      return Object.keys(this.selected).length
    },
    matchableRows() {
      return this.rows.filter(row => Number(row.available_amount) > 0)
    },
    allPageSourcesSelected() {
      return this.matchableRows.length > 0 && this.matchableRows.every(row => Boolean(this.selected[row.id]))
    },
    selectedTotal() {
      return Object.values(this.selected).reduce((sum, x) => sum + Number(x.allocated_amount || 0), 0)
    },
    currentMatched() {
      return Number(this.invoice.matched_amount || 0)
    },
    unmatchedAmount() {
      return Math.max(0, Number(this.invoice.amount_incl_tax || 0) - this.currentMatched)
    },
    isFullyMatched() {
      return this.unmatchedAmount < 0.0001
    },
    remainingAfterSelection() {
      return this.unmatchedAmount - this.selectedTotal
    },
    matchComplete() {
      return Math.abs(this.remainingAfterSelection) < 0.0001 && this.selectedTotal > 0
    }
  },
  created() {
    this.init()
  },
  methods: {
    async init() {
      this.loading = true
      try {
        const r = await getFinanceInvoice(this.id)
        this.invoice = r.data.data || {}
        if (this.invoice.status !== 'draft') this.$message.warning('已确认发票不可调整匹配')
        await this.load()
      } catch (e) {
        this.$message.error(e.userMessage || '发票匹配加载失败')
      } finally {
        this.loading = false
      }
    },
    params() {
      return {
        ...this.filters,
        business_date_start: this.businessDateRange?.[0] || '',
        business_date_end: this.businessDateRange?.[1] || '',
        page: this.page,
        per_page: this.perPage
      }
    },
    async load() {
      this.loading = true
      try {
        const r = await listInvoiceMatchingSources(this.id, this.params())
        this.rows = r.data.data || []
        this.total = Number(r.data.total || 0)
        this.summary = r.data.summary || {}
      } catch (e) {
        this.$message.error(e.userMessage || '可匹配结算来源加载失败')
      } finally {
        this.loading = false
      }
    },
    search() {
      this.page = 1
      this.load()
    },
    reset() {
      this.filters = { settlement_source_no: '', purchase_order_no: '' }
      this.businessDateRange = []
      this.search()
    },
    toggleSelection(row, checked) {
      if (checked) {
        this.$set(this.selected, row.id, {
          source_business_type: 'purchase_settlement_source',
          source_document_id: row.id,
          allocated_amount: String(row.available_amount || '')
        })
      } else {
        this.$delete(this.selected, row.id)
      }
    },
    togglePageSelection(checked) {
      this.matchableRows.forEach(row => this.toggleSelection(row, checked))
    },
    validateRow(row) {
      const item = this.selected[row.id]
      if (!item) return
      const amount = Number(item.allocated_amount || 0)
      if (!Number.isFinite(amount) || amount <= 0) {
        item.allocated_amount = ''
        return
      }
      if (amount > Number(row.available_amount)) {
        item.allocated_amount = String(row.available_amount)
        this.$message.warning(`本次匹配金额不能超过 ${this.money(row.available_amount)} CNY`)
      }
    },
    money(v) {
      return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    payload() {
      return Object.values(this.selected).filter(x => Number(x.allocated_amount) > 0)
    },
    async saveMatches(showMessage = true) {
      if (this.invoice.status !== 'draft') {
        this.$message.error('仅草稿发票允许保存匹配')
        return false
      }
      const items = this.payload()
      if (!items.length) {
        this.$message.error('请先勾选可匹配结算来源，并填写大于 0 的本次匹配金额；未匹配金额为只读结果。')
        return false
      }
      if (this.remainingAfterSelection < -0.0001) {
        this.$message.error('本次匹配金额不能超过发票未匹配金额')
        return false
      }
      this.saving = true
      try {
        await saveFinanceInvoiceMatches(this.id, items)
        const r = await getFinanceInvoice(this.id)
        this.invoice = r.data.data || {}
        this.selected = {}
        if (showMessage) this.$message.success('匹配已保存')
        await this.load()
        return true
      } catch (e) {
        this.$message.error(e.userMessage || '保存匹配失败')
        return false
      } finally {
        this.saving = false
      }
    },
    async confirmMatches() {
      const persistedComplete = Math.abs(Number(this.invoice.amount_incl_tax || 0) - Number(this.invoice.matched_amount || 0)) < 0.0001 && Number(this.invoice.amount_incl_tax || 0) > 0
      if (!this.matchComplete && !persistedComplete) {
        return this.$message.error('请将发票未匹配金额完整匹配后再确认')
      }
      if (!persistedComplete) {
        const saved = await this.saveMatches(false)
        if (!saved) return
      }
      this.saving = true
      try {
        await confirmFinanceInvoice(this.id)
        this.$message.success('发票匹配已确认')
        this.$router.push('/finance/invoices')
      } catch (e) {
        this.$message.error(e.userMessage || '确认匹配失败')
      } finally {
        this.saving = false
      }
    }
  }
}
</script>

<style scoped>
.invoice-match-page {
  min-height: calc(100vh - 58px);
  padding: 16px;
  background: #f4f6f8;
  color: #17233b;
  box-sizing: border-box;
}

.page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 18px;
  margin-bottom: 12px;
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #17233b;
}

.doc-pill {
  padding: 2px 8px;
  font-size: 12px;
  font-weight: 600;
  color: #008b4b;
  background: #eaf7f0;
  border: 1px solid #ccebd9;
  border-radius: 4px;
}

.page-desc {
  margin: 4px 0 0;
  font-size: 12px;
  color: #728096;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.green-button {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #fff !important;
}

.green-button:hover,
.green-button:focus {
  background: #00743f !important;
  border-color: #00743f !important;
  color: #fff !important;
}

.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  margin-bottom: 12px;
  font-size: 12px;
  line-height: 1.5;
  color: #4a5a70;
  background: #f0f9f4;
  border: 1px solid #ccebd9;
  border-radius: 6px;
}

.erp-page-tip i {
  font-size: 14px;
  color: #008b4b;
}

.invoice-summary {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 10px;
  padding: 12px 14px;
  margin-bottom: 12px;
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
}

.summary-item {
  padding: 8px 10px;
  background: #f8fafc;
  border: 1px solid #eef2f6;
  border-radius: 4px;
  min-width: 0;
}

.summary-item.metric {
  background: #fcfdfd;
}

.summary-label {
  display: block;
  font-size: 12px;
  color: #728096;
}

.summary-val {
  display: block;
  margin-top: 4px;
  font-size: 13px;
  color: #17233b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.summary-val i {
  font-style: normal;
  color: #64748b;
}

.summary-num {
  display: block;
  margin-top: 3px;
  font-size: 17px;
  font-weight: 700;
  color: #17233b;
  font-variant-numeric: tabular-nums;
}

.mono {
  font-family: Consolas, Monaco, monospace;
}

.mono-strong {
  font-weight: 600;
  color: #17233b;
}

.green {
  color: #008b4b !important;
}

.red {
  color: #e44d4d !important;
}

.muted {
  color: #98a2b3;
}

.source-card,
.match-history {
  padding: 14px 16px;
  margin-bottom: 12px;
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
}

.filter-panel {
  padding: 12px 14px;
  margin-bottom: 12px;
  background: #f8fafc;
  border: 1px solid #e9eef5;
  border-radius: 6px;
}

.filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(195px, 1fr));
  gap: 10px 12px;
  align-items: end;
}

.filter-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.field-label {
  font-size: 12px;
  font-weight: 500;
  color: #5b687c;
}

.filter-field .el-select,
.filter-field .el-input,
.filter-field .el-date-editor {
  width: 100%;
}

.filter-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}

.section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.section-head h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #17233b;
}

.section-head h2 b {
  color: #008b4b;
}

.sub-hint {
  font-size: 12px;
  color: #8490a2;
}

.summary-pills {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.pill-item {
  padding: 3px 9px;
  font-size: 12px;
  color: #4a5a70;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
}

.pill-item.active {
  color: #008b4b;
  background: #f0f9f4;
  border-color: #ccebd9;
}

.source-table {
  width: 100%;
}

.pager-bar {
  display: flex;
  justify-content: flex-end;
  padding-top: 12px;
}

.match-foot {
  display: grid;
  grid-template-columns: 1.8fr 1fr 1fr 1.1fr;
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
}

.match-foot > div {
  padding: 12px 16px;
  border-right: 1px solid #eef2f6;
}

.match-foot > div:last-child {
  border-right: 0;
}

.match-check {
  display: flex;
  align-items: center;
  gap: 12px;
}

.match-check > i {
  font-size: 26px;
}

.match-check .pass {
  color: #008b4b;
}

.match-check .wait {
  color: #fa8c16;
}

.match-check b {
  display: block;
  margin-bottom: 3px;
  font-size: 14px;
  color: #17233b;
}

.match-check small {
  font-size: 12px;
  color: #728096;
}

.foot-metric span {
  display: block;
  font-size: 12px;
  color: #728096;
}

.foot-metric b {
  display: block;
  margin-top: 4px;
  font-size: 16px;
  font-variant-numeric: tabular-nums;
}

@media (max-width: 1320px) {
  .invoice-summary {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (max-width: 900px) {
  .page-head {
    flex-direction: column;
    align-items: flex-start;
  }
  .invoice-summary,
  .match-foot {
    grid-template-columns: 1fr;
  }
  .match-foot > div {
    border-right: 0;
    border-bottom: 1px solid #eef2f6;
  }
}
</style>
