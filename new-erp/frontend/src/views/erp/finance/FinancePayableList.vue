<template>
  <div class="payable-page-container">
    <!-- 页面全局头部：图标、标题、统计标签与操作按钮 -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-bank-card" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">应付管理</h1>
            <el-tag
              v-if="canViewDocuments && activeView === 'documents'"
              size="small"
              type="success"
              effect="plain"
              class="head-tag total-tag"
            >
              共 {{ total.toLocaleString() }} 笔应付单据
            </el-tag>
            <!-- 筛选限定提示标签 -->
            <el-tag
              v-if="filters.supplier_id && activeSupplierText"
              size="small"
              type="warning"
              effect="plain"
              closable
              class="head-tag filter-tag"
              @close="clearSupplierFilter"
            >
              限定供应商：{{ activeSupplierText }}
            </el-tag>
            <el-tag
              v-if="$route.query.source_id"
              size="small"
              type="info"
              effect="plain"
              closable
              class="head-tag filter-tag"
              @close="clearSourceFilter"
            >
              限定单据：{{ sourceFilterText }}
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" @click="refreshCurrent">刷新</el-button>
        <el-button
          v-if="activeView === 'documents'"
          size="small"
          icon="el-icon-download"
          class="btn-export"
          @click="exportCurrent"
        >
          导出当页
        </el-button>
      </div>
    </header>

    <!-- 全局统一页面提示条 (遵照 ERP 规范标准) -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>基于采购到货结算事实统一汇总应付单据、实际付款、预付款核销、质量冻结与进项发票，账实严格守恒，禁止手工修改余额。</span>
    </div>

    <!-- 模块选项卡容器 (对齐基础档案 archive-tabs 规范) -->
    <div class="tabs-card">
      <el-tabs :value="activeView" class="archive-tabs" @tab-click="changeView">
        <el-tab-pane v-if="canViewDocuments" name="documents">
          <span slot="label" class="custom-tab-item">
            <i class="el-icon-document" />
            <span>按应付单据</span>
            <span v-if="total && activeView === 'documents'" class="tab-badge code-mono">{{ total }}</span>
          </span>
        </el-tab-pane>

        <el-tab-pane v-if="canViewSuppliers" name="suppliers">
          <span slot="label" class="custom-tab-item">
            <i class="el-icon-office-building" />
            <span>按供应商往来</span>
          </span>
        </el-tab-pane>
      </el-tabs>
    </div>

    <!-- 按应付单据视图 -->
    <div v-if="activeView === 'documents'" class="view-documents-pane">
      <!-- 财务核心概览指标卡片 -->
      <section class="metric-overview-grid">
        <div class="metric-card metric-payable">
          <div class="metric-icon-box"><i class="el-icon-wallet" /></div>
          <div class="metric-info">
            <span class="metric-label">当前应付总额</span>
            <strong class="metric-val font-tabular">{{ money(summary.current_payable_amount) }}</strong>
            <span class="metric-sub">应结算采购到货净额</span>
          </div>
        </div>

        <div class="metric-card metric-unpaid">
          <div class="metric-icon-box"><i class="el-icon-warning-outline" /></div>
          <div class="metric-info">
            <span class="metric-label">未付款总额 (待支付)</span>
            <strong class="metric-val font-tabular text-danger">{{ money(summary.unpaid_amount) }}</strong>
            <span v-if="Number(summary.quality_frozen_amount || 0) > 0" class="metric-sub text-warning">
              含质量冻结 {{ money(summary.quality_frozen_amount) }}
            </span>
            <span v-else class="metric-sub">未结清货款金额</span>
          </div>
        </div>

        <div class="metric-card metric-paid">
          <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
          <div class="metric-info">
            <span class="metric-label">已付款总额 (已核销)</span>
            <strong class="metric-val font-tabular text-success">{{ money(summary.paid_amount) }}</strong>
            <span class="metric-sub">已完成付款并核销</span>
          </div>
        </div>

        <div class="metric-card metric-invoice">
          <div class="metric-icon-box"><i class="el-icon-tickets" /></div>
          <div class="metric-info">
            <span class="metric-label">待收发票总额</span>
            <strong class="metric-val font-tabular text-amber">{{ money(summary.unreceived_invoice_amount) }}</strong>
            <span class="metric-sub">已收发票 {{ money(summary.received_invoice_amount) }}</span>
          </div>
        </div>
      </section>

      <!-- 筛选工具栏与主表格卡片 -->
      <section class="table-container-card">
        <div class="filter-toolbar">
          <div class="filter-fields">
            <div class="filter-item">
              <span class="filter-label">供应商</span>
              <paged-record-select v-model="filters.supplier_id" size="small" placeholder="选择或搜索供应商" class="filter-select-supplier" entity="suppliers" :params="{ status: 'enabled' }" :selected-rows.sync="suppliers" title="选择供应商" @change="search" />
            </div>

            <div class="filter-item">
              <span class="filter-label">采购订单号</span>
              <el-input
                v-model.trim="filters.purchase_order_no"
                size="small"
                clearable
                prefix-icon="el-icon-search"
                placeholder="输入采购订单号"
                class="filter-input-text"
                @keyup.enter.native="search"
                @clear="search"
              />
            </div>

            <div class="filter-item">
              <span class="filter-label">来源单据号</span>
              <el-input
                v-model.trim="filters.source_document_no"
                size="small"
                clearable
                prefix-icon="el-icon-document"
                placeholder="输入到货单据号"
                class="filter-input-text"
                @keyup.enter.native="search"
                @clear="search"
              />
            </div>

            <div class="filter-item">
              <span class="filter-label">业务日期</span>
              <el-date-picker
                v-model="dateRange"
                size="small"
                type="daterange"
                value-format="yyyy-MM-dd"
                range-separator="至"
                start-placeholder="开始日期"
                end-placeholder="结束日期"
                class="filter-date-picker"
                @change="search"
              />
            </div>

            <div class="filter-item">
              <span class="filter-label">付款状态</span>
              <el-select
                v-model="filters.payment_status"
                size="small"
                clearable
                placeholder="全部状态"
                class="filter-select-sm"
                @change="search"
              >
                <el-option label="未付款" value="unpaid" />
                <el-option label="部分付款" value="partial" />
                <el-option label="已付款" value="paid" />
                <el-option label="质量冻结" value="frozen" />
              </el-select>
            </div>

            <div class="filter-item">
              <span class="filter-label">发票状态</span>
              <el-select
                v-model="filters.invoice_status"
                size="small"
                clearable
                placeholder="全部状态"
                class="filter-select-sm"
                @change="search"
              >
                <el-option label="未收票" value="unreceived" />
                <el-option label="部分收票" value="partial" />
                <el-option label="已收票" value="received" />
              </el-select>
            </div>

            <div class="filter-item">
              <span class="filter-label">是否有余额</span>
              <el-select
                v-model="filters.has_balance"
                size="small"
                clearable
                placeholder="全部"
                class="filter-select-xs"
                @change="search"
              >
                <el-option label="有余额" value="yes" />
                <el-option label="无余额" value="no" />
              </el-select>
            </div>
          </div>

          <div class="filter-actions">
            <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-query" @click="search">查询</el-button>
            <el-button size="small" icon="el-icon-refresh-left" class="btn-theme-reset" @click="reset">重置</el-button>
          </div>
        </div>

        <!-- 列表表头卡片区 -->
        <div class="section-head">
          <div class="section-title">
            <i class="el-icon-s-order" />
            <span>应付结算来源单据列表</span>
            <span class="sub-count font-tabular">共 {{ total }} 条记录</span>
          </div>
          <div class="section-legend">
            <i class="el-icon-info" />
            <span>金额单位：人民币元 (CNY) · 账实守恒校验</span>
          </div>
        </div>

        <!-- 主表格 -->
        <div class="table-wrap">
          <el-table
            ref="documentsTable"
            v-loading="loading"
            :data="rows"
            border
            size="small"
            class="enterprise-table custom-payable-table"
            empty-text="暂无匹配的应付结算单据数据。"
          >
            <el-table-column label="供应商" min-width="180">
              <template slot-scope="{ row }">
                <div class="supplier-cell" @click="openDetail(row)">
                  <span class="supplier-name" :title="row.supplier_name">{{ row.supplier_name }}</span>
                  <span v-if="row.supplier_code" class="code-chip font-tabular">{{ row.supplier_code }}</span>
                </div>
              </template>
            </el-table-column>

            <el-table-column prop="source_document_no" label="来源单据号" min-width="155">
              <template slot-scope="{ row }">
                <span class="doc-code-link font-tabular" :title="row.source_document_no" @click="openDetail(row)">
                  <i class="el-icon-document" /> {{ row.source_document_no }}
                </span>
              </template>
            </el-table-column>

            <el-table-column prop="purchase_order_no" label="采购订单号" min-width="155">
              <template slot-scope="{ row }">
                <span v-if="row.purchase_order_no" class="order-code-text font-tabular">
                  <i class="el-icon-tickets" /> {{ row.purchase_order_no }}
                </span>
                <span v-else class="text-muted">—</span>
              </template>
            </el-table-column>

            <el-table-column prop="business_date" label="业务日期" width="110" align="center">
              <template slot-scope="{ row }">
                <span class="date-text font-tabular">{{ row.business_date || '—' }}</span>
              </template>
            </el-table-column>

            <money-column label="当前应付" prop="current_payable_amount" strong color-type="dark" />
            <money-column label="未付款" prop="unpaid_amount" strong color-type="danger" />
            <money-column label="已付款" prop="paid_amount" color-type="success" />
            <money-column label="质量冻结" prop="quality_frozen_amount" color-type="warning" />
            <money-column label="退货抵扣" prop="ap_offset_amount" />
            <money-column label="预付款核销" prop="prepayment_applied_amount" />
            <money-column label="待退款" prop="pending_refund_amount" />
            <money-column label="已收发票" prop="received_invoice_amount" />
            <money-column label="未收发票" prop="unreceived_invoice_amount" />

            <el-table-column label="付款状态" width="96" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="paymentTag(row.payment_status)" effect="light" class="status-pill">
                  {{ paymentLabel(row.payment_status) }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column label="发票状态" width="96" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="invoiceTag(row.invoice_status)" effect="light" class="status-pill">
                  {{ invoiceLabel(row.invoice_status) }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column label="财务状态" width="96" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="financeTag(row.finance_status)" effect="plain" class="status-pill">
                  {{ financeLabel(row.finance_status, row) }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column label="操作" width="230" fixed="right">
              <template slot-scope="{ row }">
                <div class="row-actions">
                  <el-button type="text" size="small" icon="el-icon-view" class="action-btn theme-link" @click="openDetail(row)">查看明细</el-button>
                  <el-button v-if="canPay(row)" type="text" size="small" icon="el-icon-money" class="action-btn success-link" @click="pay(row)">付款</el-button>
                  <el-button v-if="canUsePrepayment(row)" type="text" size="small" icon="el-icon-bank-card" class="action-btn theme-link" @click="usePrepayment(row)">使用预付款</el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>
        </div>

        <!-- 底部分页 -->
        <div class="table-pagination-footer">
          <div class="total-text font-tabular">
            共 <strong>{{ total.toLocaleString() }}</strong> 笔应付单据
          </div>
          <el-pagination
            background
            layout="total, sizes, prev, pager, next, jumper"
            :current-page="page"
            :page-size="perPage"
            :page-sizes="[10, 20, 50, 100]"
            :total="total"
            @current-change="p => { page = p; load() }"
            @size-change="s => { perPage = s; page = 1; load() }"
          />
        </div>
      </section>
    </div>

    <!-- 按供应商往来视图 (嵌入模式) -->
    <div v-if="activeView === 'suppliers'" class="view-suppliers-pane">
      <finance-supplier-ledger-list
        v-if="supplierVisited"
        embedded
        :active="activeView === 'suppliers'"
        @view-documents="viewSupplier"
      />
    </div>

    <!-- 应付单据详情弹窗 (对齐居中弹窗规范 el-dialog) -->
    <el-dialog
      title="应付结算单据详情"
      :visible.sync="detailDialogVisible"
      width="820px"
      top="6vh"
      custom-class="payable-detail-modal"
      :close-on-click-modal="true"
      append-to-body
    >
      <div v-if="detailRow" class="detail-modal-body">
        <!-- 弹窗顶端横幅 -->
        <div class="modal-summary-banner">
          <div class="banner-icon"><i class="el-icon-bank-card" /></div>
          <div class="banner-main">
            <div class="banner-title-line">
              <h2 class="font-tabular">{{ detailRow.source_document_no }}</h2>
              <el-tag size="mini" :type="paymentTag(detailRow.payment_status)" effect="light">
                {{ paymentLabel(detailRow.payment_status) }}
              </el-tag>
              <el-tag size="mini" :type="invoiceTag(detailRow.invoice_status)" effect="light">
                {{ invoiceLabel(detailRow.invoice_status) }}
              </el-tag>
              <el-tag size="mini" :type="financeTag(detailRow.finance_status)" effect="plain">
                {{ financeLabel(detailRow.finance_status, detailRow) }}
              </el-tag>
            </div>
            <div class="banner-sub-meta">
              <span>采购订单：<strong class="font-tabular">{{ detailRow.purchase_order_no || '—' }}</strong></span>
              <span>供应商：<strong>{{ detailRow.supplier_name }}</strong> <span v-if="detailRow.supplier_code" class="code-mono">({{ detailRow.supplier_code }})</span></span>
              <span>业务日期：<strong class="font-tabular">{{ detailRow.business_date || '—' }}</strong></span>
            </div>
          </div>
        </div>

        <!-- 4 项重点金额快报 -->
        <div class="detail-kpi-grid">
          <div class="kpi-mini-card">
            <span class="kpi-label">当前应付</span>
            <strong class="kpi-val font-tabular">{{ money(detailRow.current_payable_amount) }}</strong>
          </div>
          <div class="kpi-mini-card is-danger">
            <span class="kpi-label">未付款金额</span>
            <strong class="kpi-val font-tabular text-danger">{{ money(detailRow.unpaid_amount) }}</strong>
          </div>
          <div class="kpi-mini-card is-success">
            <span class="kpi-label">已付款金额</span>
            <strong class="kpi-val font-tabular text-success">{{ money(detailRow.paid_amount) }}</strong>
          </div>
          <div class="kpi-mini-card is-amber">
            <span class="kpi-label">待收发票</span>
            <strong class="kpi-val font-tabular text-amber">{{ money(detailRow.unreceived_invoice_amount) }}</strong>
          </div>
        </div>

        <!-- 卡片式分块详情 -->
        <div class="detail-cards-stack">
          <!-- 卡片 1：基础往来与单据信息 -->
          <div class="detail-card">
            <div class="card-title-bar">
              <i class="el-icon-document" />
              <span>基础单据与往来信息</span>
            </div>
            <div class="kv-grid">
              <div class="kv-item"><span class="kv-label">供应商编码</span><span class="kv-val font-tabular code-mono">{{ detailRow.supplier_code || '—' }}</span></div>
              <div class="kv-item"><span class="kv-label">供应商名称</span><span class="kv-val font-bold">{{ detailRow.supplier_name }}</span></div>
              <div class="kv-item"><span class="kv-label">来源单据号</span><span class="kv-val font-tabular code-mono">{{ detailRow.source_document_no }}</span></div>
              <div class="kv-item"><span class="kv-label">采购订单号</span><span class="kv-val font-tabular code-mono">{{ detailRow.purchase_order_no || '—' }}</span></div>
              <div class="kv-item"><span class="kv-label">业务日期</span><span class="kv-val font-tabular">{{ detailRow.business_date || '—' }}</span></div>
              <div class="kv-item"><span class="kv-label">结算币种</span><span class="kv-val font-tabular">{{ detailRow.currency || 'CNY' }}</span></div>
            </div>
          </div>

          <!-- 卡片 2：应付结算与款项明细 -->
          <div class="detail-card">
            <div class="card-title-bar">
              <i class="el-icon-coin" />
              <span>应付结算与核销明细</span>
            </div>
            <div class="kv-grid">
              <div class="kv-item"><span class="kv-label">当前应付金额</span><span class="kv-val font-tabular font-bold">{{ money(detailRow.current_payable_amount) }}</span></div>
              <div class="kv-item"><span class="kv-label">已付款金额</span><span class="kv-val font-tabular text-success">{{ money(detailRow.paid_amount) }}</span></div>
              <div class="kv-item"><span class="kv-label">未付款金额</span><span class="kv-val font-tabular text-danger font-bold">{{ money(detailRow.unpaid_amount) }}</span></div>
              <div class="kv-item"><span class="kv-label">质量冻结金额</span><span class="kv-val font-tabular" :class="{ 'text-warning': Number(detailRow.quality_frozen_amount) > 0 }">{{ money(detailRow.quality_frozen_amount) }}</span></div>
              <div class="kv-item"><span class="kv-label">退货抵扣金额</span><span class="kv-val font-tabular">{{ money(detailRow.ap_offset_amount) }}</span></div>
              <div class="kv-item"><span class="kv-label">预付款已冲销</span><span class="kv-val font-tabular">{{ money(detailRow.prepayment_applied_amount) }}</span></div>
              <div class="kv-item"><span class="kv-label">待退款金额</span><span class="kv-val font-tabular">{{ money(detailRow.pending_refund_amount) }}</span></div>
            </div>
          </div>

          <!-- 卡片 3：发票匹配进度 -->
          <div class="detail-card">
            <div class="card-title-bar">
              <i class="el-icon-tickets" />
              <span>进项发票匹配进度</span>
            </div>
            <div class="kv-grid">
              <div class="kv-item"><span class="kv-label">发票处理状态</span><span class="kv-val"><el-tag size="mini" :type="invoiceTag(detailRow.invoice_status)">{{ invoiceLabel(detailRow.invoice_status) }}</el-tag></span></div>
              <div class="kv-item"><span class="kv-label">已收发票金额</span><span class="kv-val font-tabular text-success">{{ money(detailRow.received_invoice_amount) }}</span></div>
              <div class="kv-item"><span class="kv-label">未收发票金额</span><span class="kv-val font-tabular text-amber">{{ money(detailRow.unreceived_invoice_amount) }}</span></div>
            </div>
          </div>
        </div>
      </div>

      <div slot="footer" class="dialog-footer">
        <el-button size="small" @click="detailDialogVisible = false">关闭</el-button>
        <el-button
          v-if="detailRow && canUsePrepayment(detailRow)"
          size="small"
          icon="el-icon-bank-card"
          @click="usePrepaymentFromDetail"
        >
          使用预付款
        </el-button>
        <el-button
          v-if="detailRow && canPay(detailRow)"
          size="small"
          type="success"
          icon="el-icon-money"
          class="btn-theme-create"
          @click="payFromDetail"
        >
          立即付款
        </el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import Vue from 'vue'
import { listFinancePayables } from '../../../api/erp/finance'
import FinanceSupplierLedgerList from './FinanceSupplierLedgerList.vue'

const blankFilters = () => ({
  supplier_id: null,
  purchase_order_no: '',
  source_document_no: '',
  payment_status: '',
  invoice_status: '',
  has_balance: '',
})

Vue.component('money-column', {
  props: ['label', 'prop', 'strong', 'colorType'],
  template: '<el-table-column :label="label" min-width="112" align="right"><template slot-scope="{row}"><span :class="[\'font-tabular\', strong ? \'money-strong\' : \'\', colorType ? \'money-\' + colorType : (strong ? \'money-danger\' : \'money-normal\')]">{{ Number(row[prop] || 0).toLocaleString(\'zh-CN\',{minimumFractionDigits:2,maximumFractionDigits:2}) }}</span></template></el-table-column>',
})

export default {
  components: { PagedRecordSelect, FinanceSupplierLedgerList },
  data: () => ({
    rows: [],
    suppliers: [],
    loading: false,
    page: 1,
    perPage: 10,
    total: 0,
    dateRange: [],
    filters: blankFilters(),
    summary: {},
    documentsLoaded: false,
    supplierVisited: false,
    requestId: 0,
    detailDialogVisible: false,
    detailRow: null,
  }),
  computed: {
    sourceFilterText() {
      const id = Number(this.$route.query.source_id)
      const row = this.rows.find(item => Number(item.id) === id)
      return row ? `${row.source_document_no}${row.purchase_order_no ? `（订单 ${row.purchase_order_no}）` : ''}` : `来源 #${id}`
    },
    canViewDocuments() { return this.$can('finance.payable.view') },
    canViewSuppliers() { return this.$can('finance.supplier-ledger.view') },
    activeView() {
      const requested = this.$route.query.view === 'suppliers' ? 'suppliers' : 'documents'
      if (requested === 'documents' && this.canViewDocuments) return 'documents'
      if (requested === 'suppliers' && this.canViewSuppliers) return 'suppliers'
      return this.canViewDocuments ? 'documents' : this.canViewSuppliers ? 'suppliers' : ''
    },
    activeSupplierText() {
      if (!this.filters.supplier_id) return ''
      const id = Number(this.filters.supplier_id)
      const hit = this.suppliers.find(s => Number(s.id) === id)
      return hit ? (hit.supplier_name + (hit.supplier_code ? ` (${hit.supplier_code})` : '')) : `ID: ${id}`
    },
  },
  created() {
    this.syncRoute(this.$route)
  },
  watch: {
    $route(to, from) {
      this.syncRoute(to, from)
    },
  },
  methods: {
    money(v) {
      return '¥ ' + Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    params() {
      return {
        ...this.filters,
        business_date_start: this.dateRange?.[0] || '',
        business_date_end: this.dateRange?.[1] || '',
        page: this.page,
        per_page: this.perPage,
        source_id: this.$route.query.source_id || '',
      }
    },
    syncRoute(to, from) {
      const query = to.query || {}
      const previous = from?.query || {}
      const contextChanged = !from || ['supplier_id', 'source_id', 'purchase_order_no'].some(key => String(query[key] || '') !== String(previous[key] || ''))
      if (contextChanged) {
        this.requestId += 1
        this.loading = false
        this.filters.supplier_id = Number(query.supplier_id) || null
        // 从采购订单“业务进度”跳转时按订单号筛选应付。
        if (query.purchase_order_no) this.filters.purchase_order_no = String(query.purchase_order_no)
        this.page = 1
        this.documentsLoaded = false
      }
      if (this.activeView === 'suppliers') this.supplierVisited = true
      if (this.activeView === 'documents') {
        if (contextChanged || (!this.documentsLoaded && !this.loading)) this.load()
        this.$nextTick(() => this.$refs.documentsTable?.doLayout())
      }
    },
    changeView(tab) {
      const view = tab.name
      if ((view === 'documents' && !this.canViewDocuments) || (view === 'suppliers' && !this.canViewSuppliers)) return
      if (view === this.activeView) return
      return this.$router.push({ path: '/finance/payables', query: { ...this.$route.query, view } })
    },
    async load() {
      if (!this.canViewDocuments) return
      const requestId = ++this.requestId
      this.loading = true
      try {
        const r = await listFinancePayables(this.params())
        if (requestId !== this.requestId) return
        this.rows = r.data.data || []
        this.total = Number(r.data.total || 0)
        this.summary = r.data.summary || {}
        this.documentsLoaded = true
      } catch (e) {
        if (requestId === this.requestId) this.$message.error(e.userMessage || '应付数据加载失败')
      } finally {
        if (requestId === this.requestId) this.loading = false
      }
    },
    search() {
      this.page = 1
      return this.load()
    },
    reset() {
      this.filters = blankFilters()
      this.dateRange = []
      this.page = 1
      if (this.$route.query.supplier_id || this.$route.query.source_id) {
        const query = { ...this.$route.query, view: 'documents' }
        delete query.supplier_id
        delete query.source_id
        return this.$router.replace({ path: '/finance/payables', query })
      }
      return this.load()
    },
    clearSupplierFilter() {
      this.filters.supplier_id = null
      if (this.$route.query.supplier_id) {
        const query = { ...this.$route.query }
        delete query.supplier_id
        return this.$router.replace({ path: '/finance/payables', query })
      }
      return this.search()
    },
    clearSourceFilter() {
      if (this.$route.query.source_id) {
        const query = { ...this.$route.query }
        delete query.source_id
        return this.$router.replace({ path: '/finance/payables', query })
      }
    },
    refreshCurrent() {
      if (this.activeView === 'documents') {
        this.load()
      } else {
        this.syncRoute(this.$route)
      }
    },
    viewSupplier(row) {
      if (!this.canViewDocuments) return
      this.filters = { ...blankFilters(), supplier_id: Number(row.supplier_id) }
      this.dateRange = []
      this.page = 1
      this.documentsLoaded = false
      if (!this.suppliers.some(s => Number(s.id) === Number(row.supplier_id))) {
        this.suppliers.push({ id: Number(row.supplier_id), supplier_code: row.supplier_code, supplier_name: row.supplier_name })
      }
      return this.$router.push({ path: '/finance/payables', query: { view: 'documents', supplier_id: row.supplier_id } })
    },
    canPay(row) {
      return this.$can('finance.payment.create') && this.$can('finance.allocation.create') && this.$can('finance.view') && Number(row.unpaid_amount) > 0
    },
    canUsePrepayment(row) {
      return this.$can('finance.allocation.create') && this.$can('finance.view') && Number(row.unpaid_amount) > 0
        && row.has_available_prepayment
    },
    pay(row) {
      if (!this.canPay(row)) return
      return this.$router.push({ path: '/finance/payments/create', query: { source_type: 'purchase_settlement_source', source_id: row.id } })
    },
    usePrepayment(row) {
      if (!this.canUsePrepayment(row)) return
      return this.$router.push({
        path: '/finance/payments',
        query: {
          party_type: 'supplier',
          party_id: row.supplier_id,
          allocation_status: 'pending',
          source_type: 'purchase_settlement_source',
          source_id: row.id,
        },
      })
    },
    openDetail(row) {
      this.detailRow = { ...row }
      this.detailDialogVisible = true
    },
    payFromDetail() {
      if (!this.detailRow) return
      const row = this.detailRow
      this.detailDialogVisible = false
      this.pay(row)
    },
    usePrepaymentFromDetail() {
      if (!this.detailRow) return
      const row = this.detailRow
      this.detailDialogVisible = false
      this.usePrepayment(row)
    },
    viewSource(row) {
      return this.openDetail(row)
    },
    exportCurrent() {
      const columns = [
        ['供应商', r => r.supplier_name],
        ['来源单据号', r => r.source_document_no],
        ['采购订单号', r => r.purchase_order_no],
        ['业务日期', r => r.business_date],
        ['当前应付', r => r.current_payable_amount],
        ['质量冻结', r => r.quality_frozen_amount],
        ['退货抵扣', r => r.ap_offset_amount],
        ['已付款', r => r.paid_amount],
        ['未付款', r => r.unpaid_amount],
        ['已收发票', r => r.received_invoice_amount],
        ['未收发票', r => r.unreceived_invoice_amount],
      ]
      const content = [
        columns.map(c => c[0]).join(','),
        ...this.rows.map(row => columns.map(([, getter]) => `"${String(getter(row) || '').replace(/"/g, '""')}"`).join(',')),
      ].join('\n')
      const blob = new Blob(['\ufeff' + content], { type: 'text/csv;charset=utf-8' })
      const link = document.createElement('a')
      link.href = URL.createObjectURL(blob)
      link.download = `应付管理-${new Date().toISOString().slice(0, 10)}-第${this.page}页.csv`
      link.click()
      URL.revokeObjectURL(link.href)
      this.$message.success('已导出当前页数据')
    },
    paymentLabel(v) { return ({ unpaid: '未付款', partial: '部分付款', paid: '已付款', frozen: '质量冻结', settled: '已结清' })[v] || '—' },
    paymentTag(v) { return ({ unpaid: 'danger', partial: 'warning', paid: 'success', frozen: 'warning', settled: 'info' })[v] || 'info' },
    invoiceLabel(v) { return ({ unreceived: '未收票', partial: '部分收票', received: '已收票', not_required: '无需发票' })[v] || '—' },
    invoiceTag(v) { return ({ unreceived: 'danger', partial: 'warning', received: 'success', not_required: 'info' })[v] || 'info' },
    financeLabel(v, row) {
      // “未结清”细分原因：已付清只差发票时显示“待收票”，已收票只差付款时显示“待付款”。
      if (v === 'unclosed' && row) {
        const unpaid = Number(row.unpaid_amount) > 0
        const uninvoiced = Number(row.unreceived_invoice_amount) > 0
        if (!unpaid && uninvoiced) return '待收票'
        if (unpaid && !uninvoiced) return '待付款'
      }
      return ({ quality_frozen: '质量冻结', pending_refund: '待退款', settled: '已结清', unclosed: '未结清' })[v] || '—'
    },
    financeTag(v) { return ({ quality_frozen: 'warning', pending_refund: 'danger', settled: 'success', unclosed: 'info' })[v] || 'info' },
  },
}
</script>

<style scoped>
.payable-page-container,
.payable-page-container * {
  box-sizing: border-box;
}

.payable-page-container {
  padding: 20px 24px;
  min-width: 0;
  background: #f8fafc;
}

/* 页面头部 */
.page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 14px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
}

.head-left {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.head-icon {
  width: 42px;
  height: 42px;
  border-radius: 10px;
  background: #eaf7ef;
  color: #008b4b;
  display: grid;
  place-items: center;
  font-size: 22px;
  flex-shrink: 0;
}

.head-title-wrap {
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
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  line-height: 28px;
}

.head-tag {
  font-size: 12px;
}

.total-tag {
  border-color: #bbf7d0 !important;
  color: #008b4b !important;
  background: #f0fdf4 !important;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

/* 统一提示条 */
.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  margin-bottom: 14px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  font-size: 13px;
  color: #00763f;
  line-height: 1.5;
}

.erp-page-tip i {
  font-size: 15px;
  color: #008b4b;
  flex-shrink: 0;
}

/* 选项卡容器 */
.tabs-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 0 16px;
  margin-bottom: 14px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
}

.archive-tabs >>> .el-tabs__header {
  margin: 0;
}

.archive-tabs >>> .el-tabs__nav-wrap::after {
  display: none;
}

.archive-tabs >>> .el-tabs__item {
  height: 46px;
  line-height: 46px;
  font-size: 14px;
  font-weight: 500;
  color: #475569;
  padding: 0 16px;
  transition: color 0.2s ease;
}

.archive-tabs >>> .el-tabs__item:hover {
  color: #008b4b;
}

.archive-tabs >>> .el-tabs__item.is-active {
  color: #008b4b;
  font-weight: 600;
}

.archive-tabs >>> .el-tabs__active-bar {
  background-color: #008b4b;
  height: 3px;
  border-radius: 3px 3px 0 0;
}

.custom-tab-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.tab-badge {
  display: inline-block;
  padding: 1px 7px;
  font-size: 11px;
  font-weight: 600;
  border-radius: 10px;
  background: #eaf7ef;
  color: #008b4b;
  line-height: 16px;
}

/* 财务概览指标网格 */
.metric-overview-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 14px;
}

.metric-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  transition: all 0.2s ease;
}

.metric-card:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
  transform: translateY(-1px);
}

.metric-icon-box {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  display: grid;
  place-items: center;
  font-size: 24px;
  flex-shrink: 0;
}

.metric-payable .metric-icon-box {
  background: #eaf7ef;
  color: #008b4b;
}

.metric-unpaid .metric-icon-box {
  background: #fef2f2;
  color: #ef4444;
}

.metric-paid .metric-icon-box {
  background: #ecfdf5;
  color: #10b981;
}

.metric-invoice .metric-icon-box {
  background: #fffbeb;
  color: #d97706;
}

.metric-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.metric-label {
  font-size: 13px;
  color: #64748b;
  line-height: 18px;
}

.metric-val {
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  line-height: 28px;
  margin: 2px 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.metric-sub {
  font-size: 12px;
  color: #94a3b8;
  line-height: 16px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* 表格容器与筛选工具栏 */
.table-container-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  overflow: hidden;
}

.filter-toolbar {
  padding: 16px 20px;
  border-bottom: 1px solid #f1f5f9;
  background: #ffffff;
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 14px;
  flex-wrap: wrap;
}

.filter-fields {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 12px;
  flex: 1;
  min-width: 0;
}

.filter-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
}

.filter-label {
  font-size: 12px;
  font-weight: 500;
  color: #475569;
}

.filter-select-supplier {
  width: 200px;
}

.filter-input-text {
  width: 160px;
}

.filter-date-picker {
  width: 250px !important;
}

.filter-select-sm {
  width: 120px;
}

.filter-select-xs {
  width: 105px;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  padding-bottom: 1px;
}

.section-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 20px;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  gap: 12px;
  flex-wrap: wrap;
}

.section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 600;
  color: #1e293b;
}

.section-title i {
  color: #008b4b;
  font-size: 15px;
}

.sub-count {
  font-size: 12px;
  font-weight: 400;
  color: #64748b;
}

.section-legend {
  font-size: 12px;
  color: #64748b;
  display: flex;
  align-items: center;
  gap: 5px;
}

/* 表格主体 */
.table-wrap {
  width: 100%;
  overflow-x: auto;
}

.custom-payable-table {
  width: 100%;
}

.custom-payable-table >>> th {
  background-color: #f8fafc !important;
  color: #475569 !important;
  font-weight: 600 !important;
  padding: 10px 0 !important;
}

.custom-payable-table >>> td {
  padding: 8px 0 !important;
  color: #1e293b;
}

.supplier-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
  cursor: pointer;
}

.supplier-name {
  font-weight: 600;
  color: #0f172a;
  transition: color 0.15s ease;
}

.supplier-cell:hover .supplier-name {
  color: #008b4b;
}

.code-chip {
  display: inline-block;
  padding: 1px 6px;
  background: #f1f5f9;
  color: #475569;
  font-size: 11px;
  border-radius: 4px;
  width: fit-content;
}

.doc-code-link {
  color: #008b4b;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.doc-code-link:hover {
  color: #00763f;
  text-decoration: underline;
}

.order-code-text {
  color: #334155;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.date-text {
  color: #64748b;
  font-size: 12px;
}

.status-pill {
  border-radius: 12px !important;
  padding: 0 8px !important;
  font-weight: 500 !important;
}

.row-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  white-space: nowrap;
}

.row-actions .el-button + .el-button {
  margin-left: 0;
}

.action-btn {
  padding: 0 !important;
  margin: 0 !important;
  font-size: 12px !important;
}

.theme-link {
  color: #008b4b !important;
}

.theme-link:hover {
  color: #00763f !important;
}

.success-link {
  color: #008b4b !important;
  font-weight: 600 !important;
}

.success-link:hover {
  color: #00763f !important;
}

/* 金额高亮格式 */
.font-tabular {
  font-variant-numeric: tabular-nums;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.code-mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.money-strong {
  font-weight: 700;
}

.money-danger,
.text-danger {
  color: #ef4444 !important;
}

.money-success,
.text-success {
  color: #008b4b !important;
}

.money-warning,
.text-warning {
  color: #d97706 !important;
}

.text-amber {
  color: #d97706 !important;
}

.money-dark {
  color: #0f172a;
}

.money-normal {
  color: #334155;
}

.text-muted {
  color: #94a3b8;
}

/* 分页栏 */
.table-pagination-footer {
  padding: 12px 20px;
  background: #ffffff;
  border-top: 1px solid #f1f5f9;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.total-text {
  font-size: 13px;
  color: #64748b;
}

.total-text strong {
  color: #0f172a;
}

/* 主题按钮与表单覆盖 */
::v-deep .btn-theme-query,
.btn-theme-query {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  transition: all 0.2s ease;
}

::v-deep .btn-theme-query:hover,
::v-deep .btn-theme-query:focus {
  background-color: #00763f !important;
  border-color: #00763f !important;
  color: #ffffff !important;
  box-shadow: 0 2px 6px rgba(0, 139, 75, 0.25);
}

::v-deep .btn-theme-create,
.btn-theme-create {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}

::v-deep .btn-theme-create:hover {
  background-color: #00763f !important;
  border-color: #00763f !important;
  box-shadow: 0 2px 6px rgba(0, 139, 75, 0.25);
}

::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 600;
}

::v-deep .el-pagination.is-background .el-pager li:not(.disabled):hover {
  color: #008b4b !important;
}

::v-deep .el-input.is-active .el-input__inner,
::v-deep .el-input__inner:focus,
::v-deep .el-select .el-input.is-focus .el-input__inner {
  border-color: #008b4b !important;
  box-shadow: 0 0 0 2px rgba(0, 139, 75, 0.12);
}

/* 详情居中弹窗样式 */
::v-deep .payable-detail-modal {
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

::v-deep .payable-detail-modal .el-dialog__header {
  padding: 16px 20px;
  border-bottom: 1px solid #f1f5f9;
}

::v-deep .payable-detail-modal .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

::v-deep .payable-detail-modal .el-dialog__body {
  padding: 18px 20px;
  background: #f8fafc;
  max-height: 74vh;
  overflow-y: auto;
}

.modal-summary-banner {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 14px;
}

.banner-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  background: #eaf7ef;
  color: #008b4b;
  display: grid;
  place-items: center;
  font-size: 22px;
  flex-shrink: 0;
}

.banner-main {
  flex: 1;
  min-width: 0;
}

.banner-title-line {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 4px;
  flex-wrap: wrap;
}

.banner-title-line h2 {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.banner-sub-meta {
  display: flex;
  align-items: center;
  gap: 16px;
  font-size: 12px;
  color: #64748b;
  flex-wrap: wrap;
}

.banner-sub-meta strong {
  color: #1e293b;
}

.detail-kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
  margin-bottom: 14px;
}

.kpi-mini-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
}

.kpi-label {
  font-size: 12px;
  color: #64748b;
}

.kpi-val {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
  margin-top: 2px;
}

.detail-cards-stack {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.detail-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
}

.card-title-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
}

.card-title-bar i {
  color: #008b4b;
}

.kv-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px 16px;
}

.kv-item {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.kv-label {
  font-size: 12px;
  color: #64748b;
}

.kv-val {
  font-size: 13px;
  color: #1e293b;
  overflow-wrap: anywhere;
}

.font-bold {
  font-weight: 600;
}

/* 响应式断点适配 (锁定 2026-09-08 指令) */
@media (max-width: 1200px) {
  .metric-overview-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .kv-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 780px) {
  .payable-page-container {
    padding: 12px;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  .head-actions {
    width: 100%;
    display: flex;
    justify-content: flex-end;
  }
  .metric-overview-grid {
    grid-template-columns: 1fr;
  }
  .detail-kpi-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .kv-grid {
    grid-template-columns: 1fr;
  }
  .filter-toolbar {
    flex-direction: column;
    align-items: stretch;
  }
  .filter-fields {
    flex-direction: column;
    gap: 10px;
  }
  .filter-item {
    width: 100%;
  }
  .filter-select-supplier,
  .filter-input-text,
  .filter-date-picker,
  .filter-select-sm,
  .filter-select-xs {
    width: 100% !important;
  }
  .filter-actions {
    justify-content: flex-end;
    width: 100%;
  }
}
</style>
