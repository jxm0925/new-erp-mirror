<template>
  <div class="cash-list-container">
    <!-- 页面全局头部：图标、标题、统计标签与主要操作 (完全对齐主数据中心规范) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i :class="direction === 'receipt' ? 'el-icon-bottom-left' : 'el-icon-wallet'" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">{{ title }}管理</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag total-tag">共 {{ total }} 笔{{ title }}单</el-tag>
            <el-tag v-if="hasSourceReference && sourceContext" size="small" type="warning" effect="plain" class="head-tag source-tag">
              来源冲抵中: {{ sourceContext.no }}
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="search">刷新</el-button>
        <el-button v-if="$can('finance.view')" size="small" icon="el-icon-data-analysis" class="btn-stat" @click="$router.push('/finance/statistics')">财务统计</el-button>
        <el-button v-if="$can(createPermission)" size="small" type="success" icon="el-icon-plus" class="btn-theme-create" :disabled="sourceLoading || !!sourceError" @click="create">
          新增{{ title }}
        </el-button>
      </div>
    </header>

    <!-- 全局统一页面提示条 (对齐主数据中心规范) -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>{{ pageTipText }}</span>
    </div>

    <!-- 来源应付单冲抵上下文提示条 (当从应付管理携带 source 跳转时展示) -->
    <section v-if="hasSourceReference" class="source-context-card" v-loading="sourceLoading">
      <div class="source-context-left">
        <span class="source-badge-icon"><i class="el-icon-link" /></span>
        <div class="source-info">
          <div class="source-title-line">
            <strong>冲抵目标：{{ sourceContext ? sourceContext.no : '解析中...' }}</strong>
            <span v-if="sourceContext" class="source-remaining-pill">待结算 {{ money(sourceContext.remainingAmount) }} {{ sourceContext.currency }}</span>
          </div>
          <span v-if="sourceContext" class="source-party-text">
            交易对手：{{ sourceContext.partyName }} · 正在为您筛选同一对手同币种且有可用预付款余额的已确认付款单
          </span>
          <span v-if="sourceError" class="source-error-text"><i class="el-icon-warning" /> {{ sourceError }}</span>
        </div>
      </div>
      <div class="source-actions">
        <el-button size="small" icon="el-icon-back" class="btn-return-payables" @click="returnToPayables">返回应付管理</el-button>
      </div>
    </section>

    <!-- 筛选工具栏与主表格卡片 (对齐主数据中心 table-container-card 规范) -->
    <section class="table-container-card">
      <div class="filter-toolbar">
        <div class="filter-fields">
          <div class="filter-item">
            <span class="filter-label">{{ title }}单号</span>
            <el-input v-model.trim="query.keyword" size="small" clearable :placeholder="title + '单号...'" @keyup.enter.native="search" />
          </div>
          <div class="filter-item">
            <span class="filter-label">交易对手</span>
            <el-input v-model.trim="query.party_keyword" size="small" clearable placeholder="请输入名称..." @keyup.enter.native="search" />
          </div>
          <div class="filter-item">
            <span class="filter-label">资金账户</span>
            <el-select v-model="query.finance_account_id" size="small" clearable placeholder="全部账户" @change="search">
              <el-option v-for="a in accounts" :key="a.id" :label="a.account_name" :value="a.id" />
            </el-select>
          </div>
          <div class="filter-item">
            <span class="filter-label">单据状态</span>
            <el-select v-model="query.status" size="small" clearable placeholder="全部状态" @change="statusChanged">
              <el-option label="草稿" value="draft" />
              <el-option label="已确认" value="confirmed" />
              <el-option label="已作废" value="voided" />
            </el-select>
          </div>
          <div class="filter-item">
            <span class="filter-label">核销状态</span>
            <el-select v-model="query.allocation_status" size="small" clearable placeholder="全部" @change="allocationStatusChanged">
              <el-option label="待核销" value="pending" />
              <el-option label="已全部核销" value="settled" />
            </el-select>
          </div>
          <div class="filter-item date-filter-item">
            <span class="filter-label">{{ title }}日期</span>
            <el-date-picker v-model="query.date_range" size="small" type="daterange" value-format="yyyy-MM-dd" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" @change="search" />
          </div>
        </div>
        <div class="filter-actions">
          <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" :disabled="sourceLoading || !!sourceError" @click="search">查询</el-button>
          <el-button size="small" icon="el-icon-refresh-left" class="btn-theme-reset" @click="reset">重置</el-button>
        </div>
      </div>

      <!-- 表格区域 -->
      <div ref="cashTableCard" class="table-wrap">
        <el-table
          v-loading="loading"
          :data="rows"
          border
          stripe
          size="small"
          class="enterprise-table"
          empty-text="暂无单据记录"
        >
          <el-table-column prop="document_no" :label="title + '单号'" min-width="160">
            <template slot-scope="{ row }">
              <span class="doc-code-link font-tabular" @click="view(row)">
                <i :class="direction === 'receipt' ? 'el-icon-bottom-left' : 'el-icon-wallet'" />
                {{ row.document_no }}
              </span>
            </template>
          </el-table-column>
          <el-table-column :label="title + '日期'" width="110" align="center">
            <template slot-scope="{ row }">
              <span class="date-text font-tabular">{{ dateText(row.business_date) }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="party_name_snapshot" label="交易对手" min-width="170" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="party-name-text">{{ row.party_name_snapshot || '—' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="关联采购订单" min-width="180" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="po-summary-text">{{ purchaseOrderSummary(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="account.account_name" label="资金账户" min-width="140" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="account-name-badge">{{ row.account ? row.account.account_name : '—' }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="currency" label="币种" width="75" align="center">
            <template slot-scope="{ row }">
              <el-tag size="mini" type="info" effect="plain" class="currency-cell-tag">{{ row.currency }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column :label="title + '金额'" width="125" align="right">
            <template slot-scope="{ row }">
              <strong class="money-text font-tabular">{{ money(row.amount) }}</strong>
            </template>
          </el-table-column>
          <el-table-column label="已核销" width="115" align="right">
            <template slot-scope="{ row }">
              <span class="allocated-text font-tabular">{{ money(row.allocated_amount) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="未核销" width="115" align="right">
            <template slot-scope="{ row }">
              <strong :class="['font-tabular', Number(row.unallocated_amount) > 0 ? 'unallocated-active' : 'unallocated-zero']">
                {{ money(row.unallocated_amount) }}
              </strong>
            </template>
          </el-table-column>
          <el-table-column :label="title + '方式'" min-width="110" align="center">
            <template slot-scope="{ row }">
              <span class="method-tag">{{ paymentMethodName(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="110" align="center">
            <template slot-scope="{ row }">
              <el-tag :type="tagType(row.status)" size="mini" effect="plain" class="status-cell-tag">{{ statusLabel(row) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="经办人" width="100" align="center">
            <template slot-scope="{ row }">
              <span class="operator-text">{{ row.operator_name_snapshot || row.operator_name || '—' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" :width="hasSourceReference ? 320 : 300" fixed="right" align="center">
            <template slot-scope="{ row }">
              <div class="row-actions">
                <el-button type="text" size="mini" class="btn-action-view" @click="view(row)">查看</el-button>
                <el-button v-if="row.status === 'draft' && $can(confirmPermission)" type="text" size="mini" class="btn-action-confirm" @click="confirm(row)">确认</el-button>
                <el-button v-if="row.status !== 'draft'" type="text" size="mini" :class="hasSourceReference ? 'btn-action-use-prepay' : 'btn-action-alloc'" :disabled="hasSourceReference && !canUseSource(row)" @click="openAllocation(row)">
                  {{ allocationLabel(row) }}
                </el-button>
                <el-button v-if="row.party_type === 'supplier' && row.status !== 'draft'" type="text" size="mini" class="btn-action-link" @click="openPurchaseLinks(row)">采购关联</el-button>
                <el-button v-if="row.status === 'confirmed' && $can(voidPermission)" type="text" size="mini" class="btn-action-void danger" @click="voidDoc(row)">作废</el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>

        <div class="table-pagination-row">
          <el-pagination
            background
            small
            layout="total, prev, pager, next, sizes, jumper"
            :current-page="page"
            :page-size="perPage"
            :page-sizes="[10, 20, 50, 100]"
            :total="total"
            @current-change="changePage"
            @size-change="changePageSize"
          />
        </div>
      </div>
    </section>

    <!-- 核销弹窗 -->
    <el-dialog :title="title + '核销'" :visible.sync="allocationVisible" class="cash-list-allocation-dialog" width="1100px" top="5vh" append-to-body :close-on-click-modal="false" :destroy-on-close="true" @closed="allocationId = null">
      <FinanceAllocation v-if="allocationVisible && allocationId" :key="allocationId" :cash-id="allocationId" embedded :initial-source="allocationSource" @changed="allocationChanged" />
    </el-dialog>

    <!-- 采购付款关联弹窗 -->
    <purchase-payment-link-dialog :visible.sync="purchaseLinksVisible" :cash-id="purchaseLinksCashId" @changed="load" />
  </div>
</template>

<script>
import { listCashDocuments, confirmCashDocument, voidCashDocument, listFinanceAccounts, resolveFinanceSource } from '../../../api/erp/finance'
import FinanceAllocation from './FinanceAllocation.vue'

const blankQuery = () => ({ keyword: '', party_keyword: '', finance_account_id: '', status: '', allocation_status: '', date_range: [] })
const positiveId = value => /^\d+$/.test(String(value || '')) && Number(value) > 0 ? Number(value) : null

export default {
  components: { FinanceAllocation, PurchasePaymentLinkDialog: () => import('../../../components/finance/PurchasePaymentLinkDialog.vue') },
  props: { direction: { type: String, required: true } },
  data: () => ({
    loading: false, rows: [], accounts: [], page: 1, perPage: 10, total: 0,
    query: blankQuery(), partyFilter: {}, sourceContext: null, sourceLoading: false, sourceError: '',
    allocationVisible: false, allocationId: null, contextRevision: 0, loadRevision: 0,
    purchaseLinksVisible: false, purchaseLinksCashId: 0,
  }),
  computed: {
    title() { return this.direction === 'receipt' ? '收款' : '付款' },
    basePath() { return this.direction === 'receipt' ? '/finance/receipts' : '/finance/payments' },
    createPermission() { return 'finance.' + this.direction + '.create' },
    confirmPermission() { return 'finance.' + this.direction + '.confirm' },
    voidPermission() { return 'finance.' + this.direction + '.void' },
    routeContextKey() { return JSON.stringify([this.direction, this.$route.query]) },
    hasSourceReference() { return !!(this.$route.query.source_id || this.$route.query.source_type) },
    allocationSource() { return this.sourceContext ? { type: this.sourceContext.type, id: this.sourceContext.id } : null },
    pageTipText() {
      return this.direction === 'receipt'
        ? '维护企业收款现金单据与客户对账流水。支持销售结算冲减、应收对冲核销与各资金账户收款过账。'
        : '维护企业供应商与费用付款单据。支持采购订单预付款排期、到货应付对冲核销与资金出账对账。'
    }
  },
  watch: {
    routeContextKey: { immediate: true, handler() { this.initializeFromRoute() } },
  },
  created() { this.loadAccounts() },
  beforeDestroy() { this.contextRevision++; this.loadRevision++ },
  methods: {
    money(value) { return Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) },
    dateText(value) { return String(value || '').slice(0, 10) || '—' },
    tagType(value) { return value === 'confirmed' ? 'success' : value === 'voided' ? 'danger' : 'warning' },
    statusLabel(row) {
      if (row.status === 'voided') return '已作废'
      if (row.status === 'draft') return '草稿'
      return Number(row.unallocated_amount) <= 0 ? '已全部核销' : Number(row.allocated_amount) > 0 ? '已部分核销' : '待核销'
    },
    paymentMethodName(row) { return row.payment_method_snapshot?.method_name || row.payment_method_snapshot?.name || row.payment_method || '—' },
    canAllocate(row) { return row.status === 'confirmed' && Number(row.unallocated_amount) > 0 && this.$can('finance.allocation.create') },
    canUseSource(row) {
      return !!this.sourceContext && !this.sourceError && this.canAllocate(row) &&
        row.party_type === this.sourceContext.partyType && Number(row.party_id) === Number(this.sourceContext.partyId) && row.currency === this.sourceContext.currency
    },
    allocationLabel(row) { return this.hasSourceReference ? '使用预付款' : this.canAllocate(row) ? '核销' : '核销记录' },
    async initializeFromRoute() {
      const revision = ++this.contextRevision
      this.loadRevision++
      this.allocationVisible = false
      this.allocationId = null
      this.purchaseLinksVisible = false
      this.purchaseLinksCashId = 0
      this.rows = []
      this.total = 0
      this.page = 1
      this.sourceContext = null
      this.sourceError = ''
      this.query = blankQuery()
      const routeQuery = this.$route.query
      this.query.allocation_status = ['pending', 'settled'].includes(routeQuery.allocation_status) ? routeQuery.allocation_status : ''
      this.partyFilter = {}
      if (['supplier', 'customer'].includes(routeQuery.party_type) && positiveId(routeQuery.party_id)) {
        this.partyFilter = { party_type: routeQuery.party_type, party_id: positiveId(routeQuery.party_id) }
      }
      if (this.hasSourceReference) {
        this.sourceLoading = true
        try {
          if (routeQuery.source_type !== 'purchase_settlement_source' || !positiveId(routeQuery.source_id) || this.direction !== 'payment') {
            throw new Error('应付来源无效，请返回应付管理重新选择。')
          }
          const response = await resolveFinanceSource({ type: routeQuery.source_type, id: positiveId(routeQuery.source_id) })
          if (revision !== this.contextRevision) return
          const source = response.data.data
          if (source.partyType !== 'supplier' || !source.partyId || !(Number(source.remainingAmount) > 0)) {
            throw new Error('这笔应付当前没有可结算余额，请返回应付管理刷新。')
          }
          this.sourceContext = source
          this.partyFilter = { party_type: source.partyType, party_id: source.partyId, currency: source.currency }
          this.query.allocation_status = 'pending'
        } catch (error) {
          if (revision !== this.contextRevision) return
          this.sourceError = error.userMessage || error.message || '应付来源加载失败，请重新选择。'
        } finally {
          if (revision === this.contextRevision) this.sourceLoading = false
        }
      } else {
        this.sourceLoading = false
      }
      if (revision === this.contextRevision) await this.load()
    },
    resetTableScroll() {
      this.$nextTick(() => {
        const body = this.$refs.cashTableCard?.querySelector('.el-table__body-wrapper')
        if (body) body.scrollLeft = 0
      })
    },
    async loadAccounts() {
      try { const response = await listFinanceAccounts({ status: 'enabled', page: 1, per_page: 100 }); this.accounts = response.data.data || [] }
      catch (error) { this.$message.error(error.userMessage || '资金账户加载失败') }
    },
    async load() {
      const revision = ++this.loadRevision
      if (this.sourceLoading || this.sourceError) { this.rows = []; this.total = 0; this.loading = false; return }
      this.loading = true
      try {
        const { date_range, ...filters } = this.query
        const response = await listCashDocuments(this.direction, {
          ...filters, ...this.partyFilter,
          business_date_start: date_range?.[0] || '', business_date_end: date_range?.[1] || '',
          page: this.page, per_page: this.perPage,
        })
        if (revision !== this.loadRevision) return
        this.rows = response.data.data || []
        this.total = Number(response.data.total || 0)
        this.resetTableScroll()
      } catch (error) {
        if (revision !== this.loadRevision) return
        this.rows = []
        this.total = 0
        this.$message.error(error.userMessage || '列表加载失败')
      } finally { if (revision === this.loadRevision) this.loading = false }
    },
    search() { this.page = 1; return this.load() },
    reset() { this.query = blankQuery(); if (this.hasSourceReference) this.query.allocation_status = 'pending'; return this.search() },
    changePage(value) { this.page = value; return this.load() },
    changePageSize(value) { this.perPage = value; return this.search() },
    statusChanged() { if (this.query.status && this.query.status !== 'confirmed') this.query.allocation_status = ''; return this.search() },
    allocationStatusChanged() { if (this.query.allocation_status) this.query.status = 'confirmed'; return this.search() },
    create() { this.$router.push({ path: this.basePath + '/create', query: this.sourceContext ? { source_type: this.sourceContext.type, source_id: this.sourceContext.id } : {} }) },
    view(row) { this.$router.push(this.basePath + '/' + row.id) },
    purchaseOrderSummary(row) {
      return [...new Set((row.purchase_order_allocations || []).map(link => link.purchase_order_no || `#${link.purchase_order_id}`))].join('、') || '—'
    },
    openPurchaseLinks(row) {
      if (row.party_type !== 'supplier' || row.status === 'draft') return
      this.purchaseLinksCashId = Number(row.id)
      this.purchaseLinksVisible = true
    },
    openAllocation(row) {
      if (this.hasSourceReference && !this.canUseSource(row)) return this.$message.warning('请选用同一供应商、同币种且有未核销余额的付款。')
      this.allocationId = row.id
      this.allocationVisible = true
    },
    allocationChanged() {
      if (this.sourceContext) { this.allocationVisible = false; return this.returnToPayables() }
      return this.load()
    },
    returnToPayables() {
      const sourceId = this.sourceContext?.id || positiveId(this.$route.query.source_id)
      this.$router.push({ path: '/finance/payables', query: { view: 'documents', ...(sourceId ? { source_id: sourceId } : {}) } })
    },
    async confirm(row) {
      // 草稿清单只表示待核销意向，列表不能跳过逐项核对直接把它当成预付款确认。
      if (row.draft_allocation_items?.length || row.purchase_order_allocations?.length) return this.view(row)
      try {
        await this.$confirm('确认' + this.title + '单 ' + row.document_no + '？确认后金额不可编辑。', '确认资金事实', { type: 'warning' })
        await confirmCashDocument(row.id)
        this.$message.success('已确认')
        await this.load()
      } catch (error) { if (!['cancel', 'close'].includes(error)) this.$message.error(error.userMessage || '确认失败') }
    },
    async voidDoc(row) {
      try {
        const { value } = await this.$prompt('作废后保留历史记录，请填写原因', '作废确认', { inputValidator: value => !!String(value || '').trim() || '原因必填' })
        await voidCashDocument(row.id, value)
        this.$message.success('已作废')
        await this.load()
      } catch (error) { if (!['cancel', 'close'].includes(error)) this.$message.error(error.userMessage || '作废失败') }
    },
  },
}
</script>

<style scoped>
/* 页面容器规范：完全对齐主数据中心页面结构 */
.cash-list-container {
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

.source-tag {
  border-radius: 4px;
  font-weight: 500;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

.btn-refresh,
.btn-stat {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-refresh:hover,
.btn-stat:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}

.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 统一页面提示条 */
.erp-page-tip {
  margin-bottom: 14px;
}

/* 来源冲抵上下文提示卡片 */
.source-context-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  padding: 12px 16px;
  margin-bottom: 14px;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  box-sizing: border-box;
}

.source-context-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
  flex: 1;
}

.source-badge-icon {
  width: 34px;
  height: 34px;
  border-radius: 6px;
  background: #dbeafe;
  color: #2563eb;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
}

.source-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.source-title-line {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.source-title-line strong {
  font-size: 14px;
  color: #1e3a8a;
  font-weight: 700;
}

.source-remaining-pill {
  font-size: 12px;
  background: #2563eb;
  color: #ffffff;
  padding: 1px 7px;
  border-radius: 4px;
  font-weight: 600;
}

.source-party-text {
  display: block;
  font-size: 12px;
  color: #475569;
  margin-top: 3px;
}

.source-error-text {
  display: block;
  font-size: 12px;
  color: #dc2626;
  margin-top: 3px;
  font-weight: 600;
}

.source-actions {
  flex-shrink: 0;
}

.btn-return-payables {
  border-color: #93c5fd !important;
  color: #1d4ed8 !important;
  background: #ffffff !important;
}

.btn-return-payables:hover {
  border-color: #2563eb !important;
  color: #1e40af !important;
}

/* 筛选工具栏与主卡片容器 (主数据中心规范) */
.table-container-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  overflow: hidden;
  box-sizing: border-box;
}

.filter-toolbar {
  padding: 14px 18px;
  background: #fafbfc;
  border-bottom: 1px solid #e2e8f0;
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

.filter-item .el-input {
  width: 155px;
}

.filter-item .el-select {
  width: 125px;
}

.date-filter-item .el-date-editor {
  width: 235px;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
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

.btn-theme-reset {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-theme-reset:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 主表格区域 */
.table-wrap {
  width: 100%;
  overflow-x: auto;
  min-width: 0;
}

.enterprise-table >>> th {
  background: #f8fafc;
  color: #334155;
  font-weight: 600;
  font-size: 12px;
  padding: 10px 0;
}

.enterprise-table >>> td {
  padding: 9px 0;
  font-size: 13px;
  color: #1e293b;
}

.font-tabular {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  font-variant-numeric: tabular-nums;
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
  text-decoration: underline;
  color: #00763f;
}

.date-text {
  color: #475569;
  font-size: 12.5px;
}

.party-name-text {
  font-weight: 500;
  color: #0f172a;
}

.po-summary-text {
  color: #475569;
  font-size: 12px;
}

.account-name-badge {
  color: #334155;
  font-size: 12.5px;
}

.currency-cell-tag {
  border-radius: 4px;
  font-weight: 600;
}

.money-text {
  color: #0f172a;
  font-size: 13px;
}

.allocated-text {
  color: #64748b;
  font-size: 12.5px;
}

.unallocated-active {
  color: #d97706;
}

.unallocated-zero {
  color: #94a3b8;
}

.method-tag {
  display: inline-block;
  padding: 1px 6px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  font-size: 11px;
  color: #475569;
}

.status-cell-tag {
  border-radius: 4px;
}

.operator-text {
  color: #64748b;
  font-size: 12px;
}

/* 操作列排版：规整、对齐、无拥挤 */
.row-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  white-space: nowrap;
}

.row-actions .el-button--text {
  padding: 3px 5px !important;
  font-size: 12.5px !important;
  font-weight: 500;
  margin-left: 0 !important;
}

.btn-action-view { color: #008b4b !important; }
.btn-action-view:hover { color: #00763f !important; }

.btn-action-confirm { color: #059669 !important; }
.btn-action-confirm:hover { color: #047857 !important; }

.btn-action-alloc { color: #2563eb !important; }
.btn-action-alloc:hover { color: #1d4ed8 !important; }

.btn-action-use-prepay { color: #d97706 !important; font-weight: 600 !important; }
.btn-action-use-prepay:hover { color: #b45309 !important; }

.btn-action-link { color: #0d9488 !important; }
.btn-action-link:hover { color: #0f766e !important; }

.btn-action-void.danger { color: #dc2626 !important; }
.btn-action-void.danger:hover { color: #b91c1c !important; }

/* 分页行 */
.table-pagination-row {
  padding: 12px 18px;
  background: #fafbfc;
  border-top: 1px solid #e2e8f0;
  display: flex;
  justify-content: flex-end;
}

/* 核销弹窗微调 */
.cash-list-allocation-dialog >>> .el-dialog {
  max-width: calc(100vw - 32px);
  margin-bottom: 5vh;
  border-radius: 8px;
  overflow: hidden;
}

.cash-list-allocation-dialog >>> .el-dialog__header {
  padding: 16px 20px;
  border-bottom: 1px solid #f1f5f9;
  background: #fafbfc;
}

.cash-list-allocation-dialog >>> .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.cash-list-allocation-dialog >>> .el-dialog__body {
  max-height: 72vh;
  overflow-y: auto;
  padding: 16px 20px;
}

/* 响应式自适应断点（遵照 2026-09-08 规则） */
@media (max-width: 1200px) {
  .filter-fields {
    gap: 10px;
  }
}

@media (max-width: 900px) {
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
  .source-context-card {
    flex-direction: column;
    align-items: stretch;
  }
  .source-actions {
    display: flex;
    justify-content: flex-end;
  }
}

@media (max-width: 768px) {
  .cash-list-container {
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
    flex-wrap: wrap;
  }
  .cash-list-allocation-dialog >>> .el-dialog__body {
    padding: 10px;
  }
}

@media (max-width: 520px) {
  .filter-fields {
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
  }
  .filter-item {
    width: 100%;
    justify-content: space-between;
  }
  .filter-item .el-input,
  .filter-item .el-select,
  .date-filter-item .el-date-editor {
    flex: 1;
    width: 100% !important;
  }
  .filter-actions .el-button {
    flex: 1;
  }
  .table-pagination-row {
    justify-content: center;
  }
  .table-pagination-row >>> .el-pagination {
    white-space: normal;
    text-align: center;
  }
}

@media (max-width: 360px) {
  .cash-list-container {
    padding: 8px 6px;
  }
}
</style>
