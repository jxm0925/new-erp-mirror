<template>
  <section v-loading="loading" class="finance-page-container">
    <!-- 页面全局头部：图标、标题、统计标签与主要操作 (完全对齐主数据中心规范) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-data-analysis" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">财务统计</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag total-tag">资金数据中心</el-tag>
            <el-tag v-if="currency" size="small" type="info" effect="plain" class="head-tag currency-tag">本位币: {{ currency }}</el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="load">刷新</el-button>
      </div>
    </header>

    <!-- 全局统一页面提示条 (对齐主数据中心规范) -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>财务统计数据基于已确认现金收付单、账户过账流水与供应商往来账实时汇总。单据审核入账后自动计算，无需手工重结。</span>
    </div>

    <!-- 筛选工具栏卡片 (对齐主数据中心 table-container-card 规范) -->
    <section class="table-container-card filter-card" aria-label="统计筛选">
      <div class="filter-toolbar">
        <div class="filter-fields">
          <div class="filter-item preset-item">
            <span class="filter-label">时间范围</span>
            <el-radio-group v-model="preset" size="small" class="period-radio-group" @change="selectPeriod">
              <el-radio-button label="7">近7天</el-radio-button>
              <el-radio-button label="30">近30天</el-radio-button>
              <el-radio-button label="month">本月</el-radio-button>
              <el-radio-button label="year">本年</el-radio-button>
            </el-radio-group>
          </div>
          <div class="filter-item date-item">
            <span class="filter-label">开始日期</span>
            <el-date-picker v-model="filters.start" size="small" type="date" value-format="yyyy-MM-dd" :clearable="false" placeholder="开始日期" @change="preset = ''" />
          </div>
          <div class="filter-item date-item">
            <span class="filter-label">结束日期</span>
            <el-date-picker v-model="filters.end" size="small" type="date" value-format="yyyy-MM-dd" :clearable="false" placeholder="结束日期" @change="preset = ''" />
          </div>
          <div class="filter-item currency-item">
            <span class="filter-label">币种</span>
            <el-select v-model="filters.currency" size="small" filterable remote :remote-method="searchCurrencies" :loading="currencyLoading" placeholder="本位币" @visible-change="onCurrencyOpen">
              <el-option v-for="item in currencyOptions" :key="item.currency_code" :value="item.currency_code" :label="`${item.currency_code} ${item.currency_name || ''}`" />
            </el-select>
          </div>
        </div>
        <div class="filter-actions">
          <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" :disabled="loading" @click="load">查询</el-button>
        </div>
      </div>
    </section>

    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="dashboard-error-alert" />

    <template v-if="dashboard">
      <!-- 期间收付款概况 (对齐主数据中心 metric-overview-grid 风格) -->
      <div class="section-title-row">
        <div class="section-title-left">
          <span class="card-title-icon"><i class="el-icon-s-finance" /></span>
          <h2 class="section-title-text">期间收付款概览</h2>
          <span class="section-badge">{{ periodLabel }}</span>
        </div>
        <span class="currency-badge"><i class="el-icon-coin" /> 本位统计币种：<strong>{{ currency }}</strong></span>
      </div>

      <section class="metric-overview-grid">
        <div v-for="metric in cashMetrics" :key="metric.key" :class="['metric-card', `metric-${metric.key}`]">
          <div :class="['metric-icon-box', metric.tone]">
            <i :class="metric.icon" />
          </div>
          <div class="metric-info">
            <span class="metric-label">{{ metric.label }}</span>
            <strong :class="['metric-val', 'code-mono', { negative: Number(metric.value) < 0 }]">
              {{ currencySymbol }} {{ money(metric.value) }}
            </strong>
            <span v-if="metric.key !== 'balance_amount'" :class="['metric-sub', changeClass(metric.key)]">
              <i :class="changeIcon(metric.key)" />
              {{ changeText(metric.key) }}
            </span>
            <span v-else class="metric-sub metric-sub-asof">
              <i class="el-icon-time" /> 截至 {{ asOfLabel }}
            </span>
          </div>
        </div>
      </section>

      <!-- 主图表：收付款趋势与构成 -->
      <div class="chart-grid primary-charts">
        <article class="chart-card table-container-card">
          <div class="card-header">
            <div class="card-header-left">
              <span class="card-title-icon"><i class="el-icon-data-line" /></span>
              <span class="card-title-text">收付款趋势分析</span>
              <span class="card-subtitle-text">每日收付流水及净现金流走向</span>
            </div>
            <div class="card-header-right">
              <el-button type="text" size="mini" class="btn-action-detail" icon="el-icon-document" @click="showDetails('trend')">查看明细</el-button>
            </div>
          </div>
          <div class="chart-body">
            <finance-chart :option="trendOption" :height="310" label="每日收付款及收支净额趋势" :empty="!hasTrend" empty-text="所选期间暂无已确认收付款" @chart-click="showDetails('trend')" />
          </div>
        </article>

        <article class="chart-card table-container-card">
          <div class="card-header">
            <div class="card-header-left">
              <span class="card-title-icon"><i class="el-icon-pie-chart" /></span>
              <span class="card-title-text">收支构成占比</span>
            </div>
            <div class="card-header-right">
              <el-radio-group v-model="compositionDirection" size="mini" class="composition-radio-toggle">
                <el-radio-button label="receipt">收款</el-radio-button>
                <el-radio-button label="payment">付款</el-radio-button>
              </el-radio-group>
            </div>
          </div>
          <div class="chart-body">
            <finance-chart :option="cashCompositionOption" :height="270" :label="compositionLabel" :empty="!compositionRows.length" :empty-text="`所选期间暂无已确认${compositionLabel}`" @chart-click="showDetails('composition')" />
          </div>
          <div class="card-footer">
            <span>{{ compositionLabel }}合计：<strong class="footer-highlight">{{ money(compositionTotal) }}</strong> {{ currency }}</span>
            <el-button type="text" size="mini" class="btn-action-detail" icon="el-icon-document" @click="showDetails('composition')">查看明细</el-button>
          </div>
        </article>
      </div>

      <!-- 当前资金与往来余额 -->
      <div class="section-title-row">
        <div class="section-title-left">
          <span class="card-title-icon"><i class="el-icon-bank-card" /></span>
          <h2 class="section-title-text">实时资金余额与供应商往来</h2>
          <span class="section-badge"><i class="el-icon-time" /> 截至 {{ asOfLabel }}</span>
        </div>
        <span class="currency-badge"><i class="el-icon-coin" /> 币种：<strong>{{ currency }}</strong></span>
      </div>

      <!-- 实时指标卡片 (对齐主数据中心 metric-overview-grid 规范) -->
      <section v-if="currentMetrics.length" class="metric-overview-grid current-metrics-grid">
        <div v-for="metric in currentMetrics" :key="metric.key" :class="['metric-card', `current-card-${metric.key}`]">
          <div :class="['metric-icon-box', metric.key]">
            <i :class="currentMetricIcon(metric.key)" />
          </div>
          <div class="metric-info">
            <span class="metric-label">{{ metric.label }}</span>
            <strong class="metric-val code-mono">{{ currencySymbol }} {{ money(metric.value) }}</strong>
            <span class="metric-sub metric-sub-muted">{{ currentMetricSub(metric.key) }}</span>
          </div>
        </div>
      </section>

      <!-- 余额与排名图表网格 -->
      <div :class="['chart-grid', 'balance-charts', `cards-count-${balanceCardsCount}`]">
        <article class="chart-card table-container-card">
          <div class="card-header">
            <div class="card-header-left">
              <span class="card-title-icon"><i class="el-icon-wallet" /></span>
              <span class="card-title-text">账户资金余额分布</span>
            </div>
            <div class="card-header-right">
              <el-button type="text" size="mini" class="btn-action-detail" icon="el-icon-document" @click="showDetails('accounts')">查看明细</el-button>
            </div>
          </div>
          <div class="chart-body">
            <finance-chart :option="accountsOption" :height="chartHeight(accountChartRows)" label="当前各账户资金余额" :empty="!hasAccountBalances" empty-text="当前暂无账户资金余额" @chart-click="showDetails('accounts')" />
          </div>
          <div v-if="accountRows.length > 10" class="card-note-bar">
            <i class="el-icon-info" /> 展示绝对值前 10 个账户，明细包含全部 {{ accountRows.length }} 个账户。
          </div>
        </article>

        <article v-if="suppliersAvailable" class="chart-card table-container-card">
          <div class="card-header">
            <div class="card-header-left">
              <span class="card-title-icon"><i class="el-icon-office-building" /></span>
              <span class="card-title-text">供应商应付欠款排名</span>
            </div>
            <div class="card-header-right">
              <el-button type="text" size="mini" class="btn-action-detail" icon="el-icon-document" @click="showDetails('suppliers')">查看明细</el-button>
            </div>
          </div>
          <div class="chart-body">
            <finance-chart :option="suppliersOption" :height="chartHeight(supplierRows)" label="当前供应商未付金额前十名" :empty="!supplierRows.length" empty-text="当前暂无供应商欠款" @chart-click="showDetails('suppliers')" />
          </div>
          <div v-if="Number(dashboard.suppliers.other_unpaid_amount) > 0" class="card-note-bar">
            <i class="el-icon-info" /> 其余供应商未付合计：<strong>{{ money(dashboard.suppliers.other_unpaid_amount) }}</strong> {{ currency }}
          </div>
        </article>

        <article v-if="payablesAvailable" class="chart-card table-container-card">
          <div class="card-header">
            <div class="card-header-left">
              <span class="card-title-icon"><i class="el-icon-document-checked" /></span>
              <span class="card-title-text">采购应付结算情况</span>
            </div>
            <div class="card-header-right">
              <el-button type="text" size="mini" class="btn-action-detail" icon="el-icon-document" @click="showDetails('payables')">查看明细</el-button>
            </div>
          </div>
          <div class="chart-body">
            <finance-chart :option="payablesOption" :height="300" label="采购应付已核销与未付构成" :empty="!hasPayables" empty-text="当前暂无采购应付" @chart-click="showDetails('payables')" />
          </div>
          <div class="card-footer">
            <span>质量冻结金额：<b class="frozen-val">{{ money(dashboard.payables.summary.quality_frozen_amount) }}</b> {{ currency }}</span>
            <el-tooltip content="不合格品或质检待处理冻结的采购款项" placement="top">
              <i class="el-icon-question help-icon" />
            </el-tooltip>
          </div>
        </article>
      </div>
    </template>

    <!-- 详情弹窗 (对齐主数据中心紧凑弹窗规范) -->
    <el-dialog
      :visible.sync="detailVisible"
      :title="detail.title"
      width="min(960px, calc(100vw - 24px))"
      top="5vh"
      append-to-body
      custom-class="finance-statistics-details"
      :close-on-click-modal="false"
      @closed="detailPage = 1"
    >
      <div class="detail-dialog-head-info">
        <div class="head-info-pills">
          <span class="detail-period-pill"><i class="el-icon-date" /> {{ detail.period }}</span>
          <span class="detail-currency-pill"><i class="el-icon-coin" /> {{ currency }}</span>
          <span class="detail-count-pill"><i class="el-icon-document" /> 共 {{ detail.rows.length }} 条明细</span>
        </div>
      </div>
      <div class="detail-dialog-body">
        <el-table :data="visibleDetailRows" border stripe size="small" class="statistics-detail-table">
          <el-table-column type="index" label="#" width="45" align="center" />
          <el-table-column
            v-for="column in detail.columns"
            :key="column.key"
            :prop="column.key"
            :label="column.label"
            :min-width="column.width || 130"
            :align="column.money ? 'right' : 'left'"
          >
            <template slot-scope="{row}">
              <strong v-if="column.money" :class="Number(row[column.key]) < 0 ? 'text-danger' : 'text-money'">
                {{ money(row[column.key]) }}
              </strong>
              <span v-else>{{ row[column.key] }}</span>
            </template>
          </el-table-column>
        </el-table>
        <div class="detail-pagination-wrapper">
          <el-pagination
            v-if="detail.rows.length > 15"
            small
            background
            layout="total, prev, pager, next"
            :pager-count="5"
            :current-page.sync="detailPage"
            :page-size="15"
            :total="detail.rows.length"
          />
        </div>
      </div>
      <span slot="footer">
        <el-button size="small" class="btn-dialog-cancel" @click="detailVisible = false">关闭</el-button>
      </span>
    </el-dialog>
  </section>
</template>

<script>
import { getFinanceDashboard, listFinanceCurrencies } from '@/api/erp/finance'
import FinanceChart from '@/components/finance/FinanceChart.vue'
import { money, dateRangeFor, businessDateTime, hasAmounts, cashTrendOption, compositionOption, balanceOption } from '@/utils/financeDashboard'

export default {
  name: 'FinanceDashboard',
  components: { FinanceChart },
  data() {
    const [start, end] = dateRangeFor('30')
    return {
      dashboard: null,
      loading: false,
      error: '',
      preset: '30',
      filters: { start, end, currency: '' },
      businessTimezone: 'UTC',
      currencies: [],
      currencyLoading: false,
      requestSequence: 0,
      currencySequence: 0,
      compositionDirection: 'payment',
      detailVisible: false,
      detailPage: 1,
      detail: { title: '', period: '', columns: [], rows: [] }
    }
  },
  computed: {
    currency() { return this.dashboard?.currency || this.filters.currency || '' },
    currencySymbol() {
      const map = { CNY: '¥', USD: '$', EUR: '€', GBP: '£', JPY: '¥', HKD: 'HK$' }
      return map[this.currency] || this.currency || '¥'
    },
    currencyOptions() {
      const rows = [...this.currencies]
      if (this.filters.currency && !rows.some(row => row.currency_code === this.filters.currency)) {
        rows.unshift({ currency_code: this.filters.currency })
      }
      return rows
    },
    periodLabel() { return this.dashboard ? `${this.dashboard.period.start} 至 ${this.dashboard.period.end}` : '' },
    asOfLabel() { return businessDateTime(this.dashboard?.as_of, this.dashboard?.timezone || 'UTC') },
    cashMetrics() {
      const summary = this.dashboard?.cash?.summary || {}
      return [
        { key: 'receipt_amount', label: '期间收款', value: summary.receipt_amount, icon: 'el-icon-bottom-left', tone: 'green' },
        { key: 'payment_amount', label: '期间付款', value: summary.payment_amount, icon: 'el-icon-top-right', tone: 'orange' },
        { key: 'net_amount', label: '收支净额', value: summary.net_amount, icon: 'el-icon-data-line', tone: 'slate' },
        { key: 'balance_amount', label: '各账户当前资金余额', value: this.dashboard?.accounts?.balance_amount, icon: 'el-icon-bank-card', tone: 'green' }
      ]
    },
    currentMetrics() {
      const metrics = []
      const payables = this.dashboard?.payables
      if (payables?.status === 'available' && payables.summary) {
        metrics.push({ key: 'unpaid_amount', label: '采购未付总额', value: payables.summary.unpaid_amount })
      }
      const suppliers = this.dashboard?.suppliers
      if (suppliers?.status === 'available' && suppliers.summary) {
        metrics.push({ key: 'prepayment_balance_amount', label: '可用供应商预付款', value: suppliers.summary.prepayment_balance_amount })
        metrics.push({ key: 'pending_refund_amount', label: '待退供应商退款', value: suppliers.summary.pending_refund_amount })
      }
      return metrics
    },
    compositionLabel() { return this.compositionDirection === 'receipt' ? '收款' : '付款' },
    compositionRows() {
      return (this.dashboard?.cash?.composition || []).filter(item => item.direction === this.compositionDirection && Number(item.amount) > 0)
    },
    compositionTotal() {
      return this.compositionDirection === 'receipt'
        ? (this.dashboard?.cash?.summary?.receipt_amount || '0')
        : (this.dashboard?.cash?.summary?.payment_amount || '0')
    },
    trendOption() { return cashTrendOption(this.dashboard?.cash?.trend || [], this.currency) },
    cashCompositionOption() { return compositionOption(this.compositionRows, this.currency, `${this.compositionLabel}构成`) },
    accountRows() { return this.dashboard?.accounts?.items || [] },
    accountChartRows() { return this.accountRows.slice(0, 10) },
    supplierRows() { return this.dashboard?.suppliers?.payable_ranking || [] },
    accountsOption() {
      return balanceOption(this.accountChartRows, `账户资金余额（${this.currency}）`, 'account_name', 'balance_amount')
    },
    suppliersOption() {
      return balanceOption(this.supplierRows, `供应商应付欠款（${this.currency}）`, 'supplier_name', 'unpaid_amount')
    },
    payablesOption() {
      const summary = this.dashboard?.payables?.summary || {}
      return compositionOption([
        { label: '已核销付款', amount: summary.paid_amount },
        { label: '采购未付', amount: summary.unpaid_amount }
      ], '采购应付结算')
    },
    hasTrend() { return hasAmounts(this.dashboard?.cash?.trend || [], ['receipt_amount', 'payment_amount', 'net_amount']) },
    hasAccountBalances() { return hasAmounts(this.accountRows, ['balance_amount']) },
    hasPayables() {
      const summary = this.dashboard?.payables?.summary || {}
      return hasAmounts([summary], ['paid_amount', 'unpaid_amount'])
    },
    suppliersAvailable() { return this.dashboard?.suppliers?.status === 'available' },
    payablesAvailable() { return this.dashboard?.payables?.status === 'available' },
    balanceCardsCount() {
      let count = 1
      if (this.suppliersAvailable) count++
      if (this.payablesAvailable) count++
      return count
    },
    visibleDetailRows() {
      const start = (this.detailPage - 1) * 15
      return this.detail.rows.slice(start, start + 15)
    }
  },
  mounted() {
    this.searchCurrencies('')
    this.load(true)
  },
  beforeDestroy() {
    this.requestSequence++
    this.currencySequence++
  },
  methods: {
    money(val) { return money(val) },
    selectPeriod(kind) {
      if (!kind) return
      const [start, end] = dateRangeFor(kind)
      this.filters.start = start
      this.filters.end = end
    },
    async searchCurrencies(query) {
      const seq = ++this.currencySequence
      this.currencyLoading = true
      try {
        const response = await listFinanceCurrencies({ keyword: (query || '').trim(), per_page: 50 })
        if (seq !== this.currencySequence) return
        this.currencies = response.data?.data || []
      } catch (e) {
        if (seq === this.currencySequence) this.currencies = []
      } finally {
        if (seq === this.currencySequence) this.currencyLoading = false
      }
    },
    onCurrencyOpen(open) {
      if (open && !this.currencies.length) this.searchCurrencies('')
    },
    async load(isInitial = false) {
      if (!isInitial && this.filters.start && this.filters.end && this.filters.start > this.filters.end) {
        this.$message.warning('请选择正确的起止日期')
        return
      }
      const seq = ++this.requestSequence
      this.loading = true
      this.error = ''
      this.dashboard = null
      this.detailVisible = false
      try {
        const params = {}
        if (this.filters.currency) params.currency = this.filters.currency
        if (!isInitial) {
          if (this.filters.start) params.period_start = this.filters.start
          if (this.filters.end) params.period_end = this.filters.end
        }
        const response = await getFinanceDashboard(params)
        if (seq !== this.requestSequence) return
        const result = response.data?.data
        this.dashboard = result
        this.businessTimezone = result?.timezone || 'UTC'
        if (isInitial && result?.period) {
          this.filters.start = result.period.start
          this.filters.end = result.period.end
        }
        if (!this.filters.currency && result?.currency) {
          this.filters.currency = result.currency
        }
      } catch (e) {
        if (seq !== this.requestSequence) return
        this.error = e.userMessage || '财务统计数据加载失败'
        this.$message.error(this.error)
      } finally {
        if (seq === this.requestSequence) this.loading = false
      }
    },
    changeClass(key) {
      const percent = this.dashboard?.cash?.changes?.[key]?.percent
      if (percent === null || percent === undefined) return 'neutral'
      const num = Number(percent)
      if (num === 0) return 'neutral'
      if (key === 'payment_amount') {
        return num > 0 ? 'down' : 'up'
      }
      return num > 0 ? 'up' : 'down'
    },
    changeIcon(key) {
      const percent = this.dashboard?.cash?.changes?.[key]?.percent
      if (percent === null || percent === undefined) return 'el-icon-minus'
      const num = Number(percent)
      if (num > 0) return 'el-icon-top-right'
      if (num < 0) return 'el-icon-bottom-right'
      return 'el-icon-minus'
    },
    changeText(key) {
      const percent = this.dashboard?.cash?.changes?.[key]?.percent
      if (percent === null || percent === undefined) return '较上期：—'
      const num = Number(percent)
      return `较上期 ${num > 0 ? '+' : ''}${num}%`
    },
    chartHeight(rows) {
      return Math.max(260, Math.min(480, (rows?.length || 0) * 32 + 70))
    },
    currentMetricIcon(key) {
      const map = {
        unpaid_amount: 'el-icon-warning-outline',
        prepayment_balance_amount: 'el-icon-circle-check',
        pending_refund_amount: 'el-icon-refresh-left'
      }
      return map[key] || 'el-icon-coin'
    },
    currentMetricSub(key) {
      const map = {
        unpaid_amount: '累计采购未结清款项',
        prepayment_balance_amount: '可冲抵后续应付账款',
        pending_refund_amount: '供应商待退回的款项'
      }
      return map[key] || ''
    },
    showDetails(type) {
      if (!this.dashboard) return
      this.detailPage = 1
      if (type === 'trend') {
        this.detail = {
          title: '期间每日收付款趋势明细',
          period: this.periodLabel,
          columns: [
            { key: 'date', label: '业务日期', width: 130 },
            { key: 'receipt_amount', label: '收款金额', width: 140, money: true },
            { key: 'payment_amount', label: '付款金额', width: 140, money: true },
            { key: 'net_amount', label: '收支净额', width: 140, money: true }
          ],
          rows: this.dashboard.cash?.trend || []
        }
      } else if (type === 'composition') {
        this.detail = {
          title: `期间${this.compositionLabel}业务构成明细`,
          period: this.periodLabel,
          columns: [
            { key: 'label', label: '业务类型', width: 180 },
            { key: 'amount', label: `${this.compositionLabel}金额`, width: 140, money: true },
            { key: 'count', label: '单据笔数', width: 100 }
          ],
          rows: (this.dashboard.cash?.composition || []).filter(item => item.direction === this.compositionDirection)
        }
      } else if (type === 'accounts') {
        this.detail = {
          title: '资金账户当前余额明细',
          period: `截至 ${this.asOfLabel}`,
          columns: [
            { key: 'account_no', label: '账户编号', width: 140 },
            { key: 'account_name', label: '账户名称', width: 220 },
            { key: 'balance_amount', label: '当前资金余额', width: 160, money: true }
          ],
          rows: this.accountRows
        }
      } else if (type === 'suppliers') {
        if (!this.suppliersAvailable) return
        const rows = [...this.supplierRows]
        if (Number(this.dashboard.suppliers?.other_unpaid_amount) > 0) {
          rows.push({ supplier_id: 'other', supplier_name: '其余供应商合计', unpaid_amount: this.dashboard.suppliers.other_unpaid_amount })
        }
        this.detail = {
          title: '供应商应付欠款明细',
          period: `截至 ${this.asOfLabel}`,
          columns: [
            { key: 'supplier_name', label: '供应商名称', width: 240 },
            { key: 'unpaid_amount', label: '当前未付欠款', width: 160, money: true }
          ],
          rows
        }
      } else if (type === 'payables') {
        if (!this.payablesAvailable) return
        const summary = this.dashboard.payables?.summary || {}
        this.detail = {
          title: '采购应付结算明细汇总',
          period: `截至 ${this.asOfLabel}`,
          columns: [
            { key: 'label', label: '项目', width: 220 },
            { key: 'amount', label: '金额', width: 160, money: true }
          ],
          rows: [
            { label: '已核销应付', amount: summary.paid_amount },
            { label: '未付应付', amount: summary.unpaid_amount },
            { label: '质量冻结', amount: summary.quality_frozen_amount }
          ]
        }
      }
      this.detailVisible = true
    }
  }
}
</script>

<style scoped>
/* 容器规范：对齐主数据中心页面结构 */
.finance-page-container {
  padding: 16px 20px;
  background: #f8fafc;
  min-height: calc(100vh - 90px);
  box-sizing: border-box;
}

/* 页面全局头部：完全对齐主数据中心规范 */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  box-sizing: border-box;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.head-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  background: #f0fdf4;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}

.head-title-wrap {
  display: flex;
  flex-direction: column;
  min-width: 0;
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
  color: #0f172a;
  letter-spacing: -0.01em;
}

.head-tag {
  border-radius: 4px;
  font-weight: 500;
}

.currency-tag {
  border-radius: 4px;
  font-weight: 500;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

.btn-refresh {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-refresh:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 统一页面提示条 (全局规范样式已在 styles.css 维护，这里做尺寸防挤压保障) */
.erp-page-tip {
  margin-bottom: 14px;
}

/* 筛选工具栏卡片：对齐主数据中心 table-container-card 规范 */
.table-container-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  overflow: hidden;
  box-sizing: border-box;
}

.filter-card {
  margin-bottom: 14px;
}

.filter-toolbar {
  padding: 14px 18px;
  background: #ffffff;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.filter-fields {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  flex: 1;
  min-width: 0;
}

.filter-item {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.filter-label {
  font-size: 12px;
  color: #64748b;
  font-weight: 500;
  white-space: nowrap;
}

.date-item .el-date-editor {
  width: 140px;
}

.currency-item .el-select {
  width: 130px;
}

.period-radio-group >>> .el-radio-button__inner {
  padding: 8px 12px;
  font-size: 12px;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.btn-theme-search {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}

.btn-theme-search:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

.dashboard-error-alert {
  margin-bottom: 14px;
}

/* 小节标题条 */
.section-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  margin: 18px 0 12px;
}

.section-title-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.section-title-text {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}

.section-badge {
  font-size: 12px;
  color: #64748b;
  background: #e2e8f0;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 500;
}

.currency-badge {
  font-size: 12px;
  color: #475569;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  padding: 3px 10px;
  border-radius: 4px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.currency-badge strong {
  color: #008b4b;
}

/* 概览统计指标卡片网格 (完全对齐主数据中心 metric-overview-grid 规范) */
.metric-overview-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}

.metric-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
  min-width: 0;
}

.metric-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
  border-color: #cbd5e1;
}

.metric-icon-box {
  width: 42px;
  height: 42px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  font-size: 20px;
  flex-shrink: 0;
}

.metric-icon-box.green { background: #eaf7ef; color: #008b4b; }
.metric-icon-box.orange { background: #fff7ed; color: #d97706; }
.metric-icon-box.slate { background: #eff6ff; color: #2563eb; }
.metric-icon-box.unpaid_amount { background: #fef2f2; color: #dc2626; }
.metric-icon-box.prepayment_balance_amount { background: #f0fdfa; color: #0d9488; }
.metric-icon-box.pending_refund_amount { background: #faf5ff; color: #7c3aed; }

.metric-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.metric-label {
  font-size: 12px;
  color: #64748b;
  margin-bottom: 3px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.metric-val {
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.metric-val.negative {
  color: #dc2626;
}

.code-mono {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  font-variant-numeric: tabular-nums;
}

.metric-sub {
  font-size: 11px;
  margin-top: 3px;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  white-space: nowrap;
}

.metric-sub.up { color: #059669; }
.metric-sub.down { color: #dc2626; }
.metric-sub.neutral { color: #94a3b8; }
.metric-sub-asof,
.metric-sub-muted { color: #94a3b8; }

/* 实时指标卡片 3 列自适应 */
.current-metrics-grid {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

/* 图表网格与卡片 (对齐主数据中心卡片规范) */
.chart-grid {
  display: grid;
  gap: 14px;
  margin-bottom: 16px;
}

.primary-charts {
  grid-template-columns: 1.55fr 1fr;
}

.balance-charts.cards-count-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.balance-charts.cards-count-2 {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.balance-charts.cards-count-1 {
  grid-template-columns: minmax(0, 1fr);
}

.chart-card {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.card-header {
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  background: #fafbfc;
  border-bottom: 1px solid #e2e8f0;
}

.card-header-left {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  flex-wrap: wrap;
}

.card-title-icon {
  color: #008b4b;
  font-size: 16px;
  flex-shrink: 0;
}

.card-title-text {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
  white-space: nowrap;
}

.card-subtitle-text {
  font-size: 12px;
  color: #94a3b8;
  font-weight: 400;
}

.card-header-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.btn-action-detail {
  color: #008b4b !important;
  font-size: 12px !important;
  padding: 0 !important;
}

.btn-action-detail:hover {
  color: #00763f !important;
}

.composition-radio-toggle >>> .el-radio-button__inner {
  padding: 4px 8px;
  font-size: 11px;
}

.chart-body {
  padding: 14px 16px;
  flex: 1;
  min-width: 0;
}

.card-footer {
  padding: 10px 16px;
  background: #fafbfc;
  border-top: 1px solid #f1f5f9;
  font-size: 12px;
  color: #475569;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.card-note-bar {
  padding: 8px 16px;
  background: #f8fafc;
  border-top: 1px solid #f1f5f9;
  font-size: 11.5px;
  color: #64748b;
  display: flex;
  align-items: center;
  gap: 5px;
}

.footer-highlight {
  color: #008b4b;
  font-weight: 700;
}

.frozen-val {
  color: #d97706;
  font-weight: 700;
}

.help-icon {
  color: #94a3b8;
  cursor: help;
}

/* 详情弹窗 */
.detail-dialog-head-info {
  margin-bottom: 12px;
}

.head-info-pills {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.detail-period-pill,
.detail-currency-pill,
.detail-count-pill {
  font-size: 12px;
  padding: 3px 8px;
  border-radius: 4px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.detail-period-pill { background: #e2e8f0; color: #475569; font-weight: 500; }
.detail-currency-pill { background: #dcfce7; color: #166534; font-weight: 600; }
.detail-count-pill { background: #f1f5f9; color: #64748b; }

.detail-pagination-wrapper {
  margin-top: 12px;
  display: flex;
  justify-content: flex-end;
}

.text-danger { color: #dc2626; font-weight: 600; }
.text-money { color: #0f172a; font-weight: 600; }

.btn-dialog-cancel {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-dialog-cancel:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 响应式自适应断点（遵照 2026-09-08 规则） */
@media (max-width: 1200px) {
  .primary-charts {
    grid-template-columns: 1fr;
  }
  .balance-charts.cards-count-3 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 900px) {
  .metric-overview-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .current-metrics-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .filter-toolbar {
    flex-direction: column;
    align-items: stretch;
  }
  .filter-fields {
    width: 100%;
  }
  .filter-actions {
    justify-content: flex-end;
    width: 100%;
  }
}

@media (max-width: 768px) {
  .finance-page-container {
    padding: 12px;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
  }
  .head-actions {
    width: 100%;
    justify-content: flex-end;
  }
  .balance-charts.cards-count-3,
  .balance-charts.cards-count-2 {
    grid-template-columns: minmax(0, 1fr);
  }
  .card-header {
    padding: 10px 12px;
  }
  .card-subtitle-text {
    display: none;
  }
}

@media (max-width: 520px) {
  .period-radio-group {
    display: flex;
    width: 100%;
  }
  .period-radio-group >>> .el-radio-button {
    flex: 1;
    min-width: 0;
  }
  .period-radio-group >>> .el-radio-button__inner {
    width: 100%;
    padding: 7px 2px;
    font-size: 11px;
    text-align: center;
  }
  .filter-fields {
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
  }
  .filter-item {
    width: 100%;
    justify-content: space-between;
  }
  .filter-item.preset-item {
    flex-direction: column;
    align-items: flex-start;
  }
  .filter-item .el-date-editor,
  .filter-item .el-select {
    flex: 1;
    width: 100% !important;
  }
  .btn-theme-search {
    width: 100%;
  }
  .metric-overview-grid,
  .current-metrics-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (max-width: 360px) {
  .finance-page-container {
    padding: 8px 6px;
  }
}
</style>

<style>
.finance-statistics-details {
  display: flex;
  flex-direction: column;
  max-height: 85vh;
  border-radius: 8px;
  overflow: hidden;
}

.finance-statistics-details .el-dialog__header {
  padding: 16px 20px;
  border-bottom: 1px solid #f1f5f9;
  background: #fafbfc;
}

.finance-statistics-details .el-dialog__body {
  min-width: 0;
  overflow: auto;
  padding: 16px 20px;
  flex: 1;
}

.finance-statistics-details .el-dialog__footer {
  padding: 12px 20px;
  border-top: 1px solid #f1f5f9;
  background: #fafbfc;
}

.finance-statistics-details .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.finance-statistics-details .statistics-detail-table .cell {
  white-space: normal;
  overflow-wrap: anywhere;
}
</style>
