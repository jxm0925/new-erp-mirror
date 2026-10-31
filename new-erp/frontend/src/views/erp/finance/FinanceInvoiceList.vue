<template>
  <main class="invoice-list-page">
    <!-- 顶部标准页头卡片 -->
    <header class="page-head">
      <div class="head-left">
        <div class="title-row">
          <h1>发票管理</h1>
          <el-tag size="mini" type="success" effect="plain">财务中心</el-tag>
        </div>
        <p class="subtitle">统一管理供应商开具的进项发票、向客户开具的销项发票及销售端发起的开票申请</p>
      </div>
      <div class="heading-actions">
        <template v-if="tab === 'purchase'">
          <el-button
            v-if="$can('finance.invoice.create')"
            size="small"
            type="success"
            icon="el-icon-plus"
            class="green-button"
            @click="$router.push('/finance/invoices/create')"
          >
            登记进项发票
          </el-button>
          <el-button size="small" icon="el-icon-connection" @click="toUnmatched">待匹配发票</el-button>
          <el-button size="small" icon="el-icon-download" @click="exportCurrent">导出当前页</el-button>
        </template>
        <template v-else-if="tab === 'sales'">
          <el-button
            v-if="$can('finance.invoice_request.view')"
            size="small"
            type="success"
            plain
            icon="el-icon-tickets"
            @click="switchTab('requests')"
          >
            处理开票申请
            <span v-if="pendingRequests" class="btn-count-pill">{{ pendingRequests }}</span>
          </el-button>
          <el-button size="small" icon="el-icon-download" @click="exportCurrent">导出当前页</el-button>
        </template>
      </div>
    </header>

    <!-- 业务提示条 -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>
        <strong>业务说明：</strong>进项发票由供应商开具，登记后需与采购结算单完成匹配确认；销项发票由销售订单发起<strong>开票申请</strong>，财务登记发票号后自动生成。
      </span>
    </div>

    <!-- 主内容区卡片 -->
    <section class="board-card">
      <el-tabs :value="tab" class="invoice-tabs" @tab-click="t => switchTab(t.name)">
        <el-tab-pane v-if="canInvoice" name="purchase">
          <span slot="label">
            <i class="el-icon-download" /> 进项发票
            <small>供应商开具</small>
          </span>
        </el-tab-pane>
        <el-tab-pane v-if="canInvoice" name="sales">
          <span slot="label">
            <i class="el-icon-upload2" /> 销项发票
            <small>开具给客户</small>
          </span>
        </el-tab-pane>
        <el-tab-pane v-if="canRequest" name="requests">
          <span slot="label">
            <i class="el-icon-tickets" /> 开票申请
            <el-badge v-if="pendingRequests" class="tab-badge" :value="pendingRequests" />
          </span>
        </el-tab-pane>
      </el-tabs>

      <!-- Tab 3: 开票申请内嵌视图 -->
      <finance-invoice-request-list
        v-if="tab === 'requests'"
        embedded
        @summary="s => (pendingRequests = Number(s.pending_count || 0))"
      />

      <!-- Tab 1 & 2: 进项发票 / 销项发票 -->
      <template v-else>
        <!-- 核心指标统计条 -->
        <section v-if="isSales" class="metrics-strip metrics-3">
          <div class="metric-card">
            <div class="metric-icon icon-slate"><i class="el-icon-document-checked" /></div>
            <div class="metric-body">
              <span class="metric-label">销项发票总额</span>
              <div class="metric-val"><b>¥{{ money(summary.invoice_total_amount) }}</b><em>CNY</em></div>
            </div>
          </div>
          <div class="metric-card">
            <div class="metric-icon icon-green"><i class="el-icon-circle-check" /></div>
            <div class="metric-body">
              <span class="metric-label">已确认销项金额</span>
              <div class="metric-val"><b class="green">¥{{ money(summary.confirmed_amount) }}</b><em>CNY</em></div>
            </div>
          </div>
          <div class="metric-card clickable" @click="canRequest && switchTab('requests')">
            <div class="metric-icon icon-orange"><i class="el-icon-bell" /></div>
            <div class="metric-body">
              <span class="metric-label">待处理开票申请 <i v-if="canRequest" class="el-icon-right" /></span>
              <div class="metric-val"><b class="orange">{{ pendingRequests }}</b><em>张</em></div>
            </div>
          </div>
        </section>

        <section v-else class="metrics-strip metrics-4">
          <div class="metric-card">
            <div class="metric-icon icon-slate"><i class="el-icon-tickets" /></div>
            <div class="metric-body">
              <span class="metric-label">进项发票总额</span>
              <div class="metric-val"><b>¥{{ money(summary.invoice_total_amount) }}</b><em>CNY</em></div>
            </div>
          </div>
          <div class="metric-card">
            <div class="metric-icon icon-green"><i class="el-icon-circle-check" /></div>
            <div class="metric-body">
              <span class="metric-label">已确认金额</span>
              <div class="metric-val"><b class="green">¥{{ money(summary.confirmed_amount) }}</b><em>CNY</em></div>
            </div>
          </div>
          <div class="metric-card clickable" @click="toUnmatched">
            <div class="metric-icon icon-orange"><i class="el-icon-connection" /></div>
            <div class="metric-body">
              <span class="metric-label">待匹配金额 <i class="el-icon-right" /></span>
              <div class="metric-val"><b class="orange">¥{{ money(summary.unmatched_amount) }}</b><em>CNY</em></div>
            </div>
          </div>
          <div class="metric-card">
            <div class="metric-icon icon-red"><i class="el-icon-refresh-left" /></div>
            <div class="metric-body">
              <span class="metric-label">待红冲金额</span>
              <div class="metric-val"><b class="red">¥{{ money(summary.pending_red_amount) }}</b><em>CNY</em></div>
            </div>
          </div>
        </section>

        <!-- 紧凑筛选栏 -->
        <section class="filter-panel">
          <el-form :model="filters" size="small" class="filter-form" @submit.native.prevent="search">
            <div class="filter-fields">
              <div class="filter-item">
                <span class="filter-label">{{ isSales ? '客户名称' : '供应商' }}</span>
                <el-input
                  v-model.trim="filters.supplier_keyword"
                  :placeholder="isSales ? '输入客户名称搜索' : '输入供应商名称/编码'"
                  clearable
                  prefix-icon="el-icon-search"
                  @keyup.enter.native="search"
                  @clear="search"
                />
              </div>
              <div class="filter-item">
                <span class="filter-label">发票号码</span>
                <el-input
                  v-model.trim="filters.invoice_no"
                  placeholder="输入发票号码"
                  clearable
                  @keyup.enter.native="search"
                  @clear="search"
                />
              </div>
              <div class="filter-item">
                <span class="filter-label">发票代码</span>
                <el-input
                  v-model.trim="filters.invoice_code"
                  placeholder="输入发票代码"
                  clearable
                  @keyup.enter.native="search"
                  @clear="search"
                />
              </div>
              <div class="filter-item filter-item-date">
                <span class="filter-label">开票日期</span>
                <el-date-picker
                  v-model="invoiceDateRange"
                  type="daterange"
                  value-format="yyyy-MM-dd"
                  range-separator="至"
                  start-placeholder="开始日期"
                  end-placeholder="结束日期"
                  @change="search"
                />
              </div>
              <div v-if="!isSales" class="filter-item">
                <span class="filter-label">收票日期</span>
                <el-date-picker
                  v-model="receivedDateRange"
                  type="date"
                  value-format="yyyy-MM-dd"
                  placeholder="选择收票日期"
                  @change="search"
                />
              </div>
              <div class="filter-item filter-item-select">
                <span class="filter-label">发票状态</span>
                <el-select v-model="filters.status" clearable placeholder="全部状态" @change="search">
                  <el-option v-if="!isSales" label="草稿" value="draft" />
                  <el-option label="已确认" value="confirmed" />
                  <template v-if="!isSales">
                    <el-option label="待红冲" value="pending_red" />
                    <el-option label="已红冲" value="red" />
                  </template>
                </el-select>
              </div>
              <div v-if="!isSales" class="filter-item filter-item-select">
                <span class="filter-label">匹配状态</span>
                <el-select v-model="filters.match_status" clearable placeholder="全部匹配状态" @change="search">
                  <el-option label="未匹配" value="unmatched" />
                  <el-option label="部分匹配" value="partial" />
                  <el-option label="已匹配" value="matched" />
                </el-select>
              </div>
              <div class="filter-actions">
                <el-button type="success" size="small" icon="el-icon-search" class="green-button" @click="search">查询</el-button>
                <el-button size="small" icon="el-icon-refresh" @click="reset">重置</el-button>
              </div>
            </div>
          </el-form>
        </section>

        <!-- 数据表格 -->
        <section class="table-section">
          <el-table
            v-if="isSales"
            key="sales"
            v-loading="loading"
            :data="rows"
            border
            stripe
            fit
            size="small"
            class="invoice-table"
            empty-text="暂无销项发票，开票申请登记后会自动出现在这里"
          >
            <el-table-column prop="document_no" label="发票记录号" min-width="120" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <span class="code-text">{{ row.document_no || '—' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="发票号码 / 代码" min-width="135" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <span class="font-mono">{{ row.invoice_no || '—' }}</span>
                <span v-if="row.invoice_code" class="sub-code"> / {{ row.invoice_code }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="invoice_type" label="发票类型" min-width="115" show-overflow-tooltip>
              <template slot-scope="{ row }">{{ invoiceType(row.invoice_type) }}</template>
            </el-table-column>
            <el-table-column prop="party_name_snapshot" label="客户名称" min-width="140" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <span class="party-name">{{ row.party_name_snapshot || '—' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="关联销售订单" min-width="145" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <div v-for="o in row.sales_orders || []" :key="o.id">
                  <el-button type="text" size="mini" class="action-link-green font-mono" @click="$router.push(`/sales/orders/${o.id}/detail`)">
                    {{ o.no }}
                  </el-button>
                </div>
                <span v-if="!(row.sales_orders || []).length" class="muted">—</span>
                <div v-if="row.invoice_request_no" class="muted">申请：{{ row.invoice_request_no }}</div>
              </template>
            </el-table-column>
            <el-table-column prop="invoice_date" label="开票日期" width="98" align="center" />
            <amount-column label="未税金额" prop="amount_excl_tax" />
            <amount-column label="税额" prop="tax_amount" />
            <amount-column label="价税合计" prop="amount_incl_tax" strong />
            <el-table-column label="状态" width="80" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="statusTone(row.status)">{{ statusText(row.status) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" fixed="right" width="85" align="center">
              <template slot-scope="{ row }">
                <div class="row-actions">
                  <el-button type="text" size="mini" icon="el-icon-view" class="action-link-green" @click="view(row)">查看</el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>

          <el-table
            v-else
            key="purchase"
            v-loading="loading"
            :data="rows"
            border
            stripe
            fit
            size="small"
            class="invoice-table"
            empty-text="暂无进项发票记录"
          >
            <el-table-column prop="document_no" label="发票记录号" min-width="116" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <span class="code-text">{{ row.document_no || '—' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="发票号码 / 代码" min-width="128" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <span class="font-mono">{{ row.invoice_no || '—' }}</span>
                <span v-if="row.invoice_code" class="sub-code"> / {{ row.invoice_code }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="invoice_type" label="发票类型" min-width="112" show-overflow-tooltip>
              <template slot-scope="{ row }">{{ invoiceType(row.invoice_type) }}</template>
            </el-table-column>
            <el-table-column prop="party_name_snapshot" label="供应商" min-width="135" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <span class="party-name">{{ row.party_name_snapshot || '—' }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="invoice_date" label="开票日期" width="96" align="center" />
            <el-table-column prop="received_date" label="收票日期" width="96" align="center" />
            <amount-column label="未税金额" prop="amount_excl_tax" />
            <amount-column label="税额" prop="tax_amount" />
            <amount-column label="价税合计" prop="amount_incl_tax" strong />
            <amount-column label="已匹配" prop="matched_amount" green />
            <amount-column label="未匹配" prop="unmatched_amount" />
            <el-table-column label="状态" width="76" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="statusTone(row.status)">{{ statusText(row.status) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="匹配状态" width="84" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="matchTone(row.match_status)">{{ matchText(row.match_status) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" fixed="right" width="175" align="center">
              <template slot-scope="{ row }">
                <div class="row-actions">
                  <el-button type="text" size="mini" icon="el-icon-view" class="action-link-green" @click="view(row)">查看</el-button>
                  <el-button v-if="row.status === 'draft'" type="text" size="mini" icon="el-icon-edit" class="action-link-green" @click="edit(row)">编辑</el-button>
                  <el-button v-if="row.status === 'draft'" type="text" size="mini" icon="el-icon-connection" class="action-link-green" @click="match(row)">匹配</el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>

          <div class="pagination-bar">
            <el-pagination
              background
              layout="total, prev, pager, next, sizes, jumper"
              :current-page="page"
              :page-size="perPage"
              :page-sizes="[10, 20, 50, 100]"
              :total="total"
              @current-change="p => { page = p; load() }"
              @size-change="s => { perPage = s; page = 1; load() }"
            />
          </div>
        </section>
      </template>
    </section>
  </main>
</template>

<script>
import { listFinanceInvoices, listSalesInvoiceRequests } from '../../../api/erp/finance'
import FinanceInvoiceRequestList from './FinanceInvoiceRequestList.vue'

export default {
  components: {
    FinanceInvoiceRequestList,
    AmountColumn: {
      props: ['label', 'prop', 'strong', 'green'],
      methods: {
        f(v) {
          return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        }
      },
      template:
        '<el-table-column :label="label" min-width="96" align="right" show-overflow-tooltip><template slot-scope="{row}"><b :class="{\'amount-strong\':strong,\'amount-green\':green}">¥{{f(row[prop])}}</b></template></el-table-column>'
    }
  },
  data: () => ({
    tab: 'purchase',
    pendingRequests: 0,
    loading: false,
    rows: [],
    page: 1,
    perPage: 10,
    total: 0,
    summary: {},
    invoiceDateRange: [],
    receivedDateRange: '',
    filters: {
      invoice_direction: 'purchase',
      supplier_keyword: '',
      invoice_no: '',
      invoice_code: '',
      status: '',
      match_status: ''
    }
  }),
  computed: {
    isSales() {
      return this.tab === 'sales'
    },
    canInvoice() {
      return this.$can('finance.invoice.view')
    },
    canRequest() {
      return this.$can('finance.invoice_request.view')
    }
  },
  watch: {
    '$route.query.tab'() {
      if (this.$route.path === '/finance/invoices') this.applyRouteTab()
    }
  },
  created() {
    this.applyRouteTab()
    this.loadPendingCount()
  },
  methods: {
    params() {
      return {
        ...this.filters,
        invoice_date_start: this.invoiceDateRange?.[0] || '',
        invoice_date_end: this.invoiceDateRange?.[1] || '',
        received_date_start: this.receivedDateRange || '',
        page: this.page,
        per_page: this.perPage
      }
    },
    async load() {
      this.loading = true
      try {
        const r = await listFinanceInvoices(this.params())
        this.rows = r.data.data || []
        this.total = Number(r.data.total || 0)
        this.summary = r.data.summary || {}
      } catch (e) {
        this.$message.error(e.userMessage || '发票列表加载失败')
      } finally {
        this.loading = false
      }
    },
    applyRouteTab() {
      const q = this.$route.query.tab || (this.$route.query.invoice_direction === 'sales' ? 'sales' : '')
      const allowed = [...(this.canInvoice ? ['purchase', 'sales'] : []), ...(this.canRequest ? ['requests'] : [])]
      const next = allowed.includes(q) ? q : allowed[0] || 'purchase'
      const changed = next !== this.tab || !this.loadedOnce
      this.tab = next
      if (next !== 'requests' && changed) {
        this.filters = {
          invoice_direction: next,
          supplier_keyword: '',
          invoice_no: '',
          invoice_code: '',
          status: '',
          match_status: ''
        }
        this.invoiceDateRange = []
        this.receivedDateRange = ''
        this.page = 1
        this.rows = []
        this.summary = {}
        this.loadedOnce = true
        this.load()
      }
    },
    switchTab(name) {
      if (name === this.tab) return
      this.$router.replace({ path: '/finance/invoices', query: { tab: name } }).catch(() => {})
    },
    async loadPendingCount() {
      if (!this.canRequest) return
      try {
        const r = await listSalesInvoiceRequests({ status: 'pending', per_page: 5 })
        this.pendingRequests = Number((r.data.summary || {}).pending_count || 0)
      } catch (e) {}
    },
    search() {
      this.page = 1
      this.load()
    },
    reset() {
      this.filters = {
        invoice_direction: this.isSales ? 'sales' : 'purchase',
        supplier_keyword: '',
        invoice_no: '',
        invoice_code: '',
        status: '',
        match_status: ''
      }
      this.invoiceDateRange = []
      this.receivedDateRange = ''
      this.search()
    },
    toUnmatched() {
      this.filters.match_status = 'unmatched'
      this.search()
    },
    view(r) {
      this.$router.push({ path: `/finance/invoices/${r.id}`, query: { mode: 'view' } })
    },
    edit(r) {
      this.$router.push(`/finance/invoices/${r.id}/edit`)
    },
    match(r) {
      this.$router.push(`/finance/invoices/${r.id}/match`)
    },
    money(v) {
      return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    invoiceType(v) {
      return { vat_special: '增值税专用发票', vat_normal: '增值税普通发票', other: '其他' }[v] || v || '—'
    },
    statusText(v) {
      return { draft: '草稿', confirmed: '已确认', pending_red: '待红冲', red: '已红冲', voided: '已作废' }[v] || v
    },
    statusTone(v) {
      return { draft: 'info', confirmed: 'success', pending_red: 'danger', red: 'danger', voided: 'info' }[v] || 'info'
    },
    matchText(v) {
      return { unmatched: '未匹配', partial: '部分匹配', matched: '已匹配' }[v] || '—'
    },
    matchTone(v) {
      return { unmatched: 'warning', partial: 'warning', matched: 'success' }[v] || 'info'
    },
    exportCurrent() {
      const cols = [
        ['发票记录号', r => r.document_no],
        ['发票号码', r => r.invoice_no],
        ['发票代码', r => r.invoice_code],
        [this.isSales ? '客户' : '供应商', r => r.party_name_snapshot],
        ...(this.isSales ? [['关联销售订单', r => (r.sales_orders || []).map(o => o.no).join(' ')]] : []),
        ['价税合计', r => r.amount_incl_tax],
        ['已匹配', r => r.matched_amount],
        ['未匹配', r => r.unmatched_amount]
      ]
      const useCols = this.isSales ? cols.filter(c => !['已匹配', '未匹配'].includes(c[0])) : cols
      const csv = [
        useCols.map(x => x[0]).join(','),
        ...this.rows.map(r => useCols.map(([, f]) => `"${String(f(r) || '').replace(/"/g, '""')}"`).join(','))
      ].join('\n')
      const a = document.createElement('a')
      a.href = URL.createObjectURL(new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' }))
      a.download = `${this.isSales ? '销项' : '进项'}发票-${new Date().toISOString().slice(0, 10)}-第${this.page}页.csv`
      a.click()
      URL.revokeObjectURL(a.href)
      this.$message.success('已导出当前页数据')
    }
  }
}
</script>

<style scoped>
.invoice-list-page {
  min-height: calc(100vh - 58px);
  padding: 16px 20px 24px;
  background: #f8fafc;
  color: #1e293b;
  box-sizing: border-box;
}

/* 顶部页头卡片 */
.page-head {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  margin-bottom: 12px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.head-left {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.title-row h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.3;
}

.subtitle {
  margin: 0;
  font-size: 12px;
  color: #64748b;
}

.heading-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.green-button {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}

.green-button:hover,
.green-button:focus {
  background-color: #00763f !important;
  border-color: #00763f !important;
}

.btn-count-pill {
  display: inline-block;
  margin-left: 4px;
  padding: 0 6px;
  line-height: 16px;
  font-size: 11px;
  border-radius: 10px;
  background: #ef4444;
  color: #ffffff;
}

/* 主看板容器 */
.board-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 18px 18px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  box-sizing: border-box;
}

/* Tabs 品牌绿定制 */
.invoice-tabs {
  margin-bottom: 14px;
}

.invoice-tabs ::v-deep .el-tabs__item {
  font-size: 14px;
  height: 42px;
  line-height: 42px;
  color: #475569;
  font-weight: 500;
}

.invoice-tabs ::v-deep .el-tabs__item.is-active {
  color: #008b4b;
  font-weight: 600;
}

.invoice-tabs ::v-deep .el-tabs__item:hover {
  color: #008b4b;
}

.invoice-tabs ::v-deep .el-tabs__active-bar {
  background-color: #008b4b;
  height: 3px;
  border-radius: 2px;
}

.invoice-tabs small {
  margin-left: 6px;
  color: #94a3b8;
  font-size: 12px;
  font-weight: 400;
}

.tab-badge {
  margin-left: 6px;
}

.tab-badge ::v-deep .el-badge__content {
  top: -2px;
}

/* 指标统计条 */
.metrics-strip {
  display: grid;
  gap: 12px;
  margin-bottom: 14px;
}

.metrics-4 {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.metrics-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.metric-card {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #f8fafc;
  box-sizing: border-box;
  transition: all 0.2s ease;
}

.metric-card.clickable {
  cursor: pointer;
}

.metric-card.clickable:hover {
  border-color: #008b4b;
  background: #f0fdf4;
}

.metric-icon {
  width: 40px;
  height: 40px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
}

.icon-slate {
  background: #e2e8f0;
  color: #334155;
}

.icon-green {
  background: #dcfce7;
  color: #008b4b;
}

.icon-orange {
  background: #ffedd5;
  color: #ea580c;
}

.icon-red {
  background: #fee2e2;
  color: #dc2626;
}

.metric-body {
  min-width: 0;
  flex: 1;
}

.metric-label {
  display: block;
  font-size: 12px;
  color: #64748b;
  margin-bottom: 4px;
}

.metric-val {
  display: flex;
  align-items: baseline;
  gap: 6px;
}

.metric-val b {
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

.metric-val em {
  font-style: normal;
  font-size: 12px;
  color: #94a3b8;
}

.metric-val .green {
  color: #008b4b;
}

.metric-val .orange {
  color: #ea580c;
}

.metric-val .red {
  color: #dc2626;
}

/* 筛选面板 */
.filter-panel {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  margin-bottom: 14px;
}

.filter-fields {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(185px, 1fr));
  gap: 12px 14px;
  align-items: end;
}

.filter-item {
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-width: 0;
}

.filter-item-date {
  grid-column: span 2;
}

.filter-label {
  font-size: 12px;
  font-weight: 500;
  color: #475569;
  line-height: 1.2;
}

.filter-item .el-select,
.filter-item .el-input,
.filter-item .el-date-editor {
  width: 100% !important;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  padding-top: 2px;
}

/* 表格区 */
.table-section {
  width: 100%;
  min-width: 0;
}

.invoice-table {
  width: 100%;
}

.invoice-table ::v-deep th {
  background-color: #f8fafc !important;
  color: #475569;
  font-weight: 600;
}

.invoice-table ::v-deep th > .cell,
.invoice-table ::v-deep td > .cell {
  padding-left: 8px;
  padding-right: 8px;
}

.code-text {
  font-family: Menlo, Consolas, monospace;
  font-weight: 600;
  color: #0f172a;
}

.font-mono {
  font-family: Menlo, Consolas, monospace;
}

.sub-code {
  color: #64748b;
  font-size: 12px;
}

.party-name {
  font-weight: 500;
  color: #1e293b;
}

.amount-strong {
  font-weight: 700;
  color: #0f172a;
}

.amount-green {
  color: #008b4b;
  font-weight: 600;
}

.action-link-green {
  color: #008b4b !important;
  font-weight: 500;
}

.action-link-green:hover {
  color: #006334 !important;
}

.row-actions {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  flex-wrap: wrap;
}

.muted {
  color: #94a3b8;
  font-size: 12px;
}

.pagination-bar {
  padding-top: 14px;
  display: flex;
  justify-content: flex-end;
}

@media (max-width: 1180px) {
  .metrics-4 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .invoice-list-page {
    padding: 12px;
  }
  .metrics-4,
  .metrics-3 {
    grid-template-columns: 1fr;
  }
  .filter-item-date {
    grid-column: span 1;
  }
}
</style>
