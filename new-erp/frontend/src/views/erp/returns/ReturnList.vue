<template>
  <section class="return-page" :class="`return-page--${kind}`">
    <div class="return-main">
      <!-- 顶部白底页头卡片 -->
      <div class="page-head">
        <div class="head-left">
          <div class="title-row">
            <span class="title-icon"><i class="el-icon-refresh-left" /></span>
            <h1>{{ title }}</h1>
            <el-tag size="mini" type="success">{{ title }}管理</el-tag>
          </div>
          <p class="subtitle">
            {{ kind === 'purchase' ? '退还不合格、超采或多余物料至供应商，严格追溯原到货单、仓库库位与批次台账' : '基于原销售发货出库明细快照办理退货收货、质量检验处置与退款冲减' }}
          </p>
        </div>
        <div class="head-actions">
          <el-button size="small" plain icon="el-icon-refresh" @click="load">刷新</el-button>
          <el-button
            v-if="$can(createPermission)"
            size="small"
            type="success"
            icon="el-icon-plus"
            @click="$router.push(`${basePath}/create`)"
          >
            新建{{ title }}单
          </el-button>
          <template v-if="kind === 'sales'">
            <el-button size="small" icon="el-icon-download" @click="exportCurrent">导出</el-button>
            <el-button size="small" icon="el-icon-printer" @click="printPage">打印</el-button>
            <el-dropdown trigger="click" @command="batchCommand">
              <el-button size="small">批量操作 <i class="el-icon-arrow-down el-icon--right" /></el-button>
              <el-dropdown-menu slot="dropdown">
                <el-dropdown-item command="export">批量导出当前查询结果</el-dropdown-item>
                <el-dropdown-item command="print">批量打印当前查询结果</el-dropdown-item>
              </el-dropdown-menu>
            </el-dropdown>
          </template>
        </div>
      </div>

      <!-- 统一规则提示条（释放右侧边栏为 100% 全宽表格区） -->
      <div class="erp-page-tip">
        <i class="el-icon-info" />
        <span>
          <strong>{{ kind === 'purchase' ? '退货规则：' : '退货说明：' }}</strong>
          <template v-if="kind === 'purchase'">
            {{ rules.map((r, i) => `${i + 1}）${r}`).join('；') }}。
          </template>
          <template v-else>
            {{ rules.map(r => `${r.title}：${r.text}`).join(' ') }}
          </template>
        </span>
      </div>

      <!-- KPI 统计卡片（本页状态分布，支持点击联动筛选） -->
      <div class="stat-row" :class="`stat-row--${kind}`">
        <div
          v-for="card in metrics"
          :key="card.key"
          :class="['stat-card', { 'is-active': query.return_status === card.key }]"
          @click="toggleMetricFilter(card.key)"
        >
          <div class="stat-icon-wrapper" :class="`is-${card.color}`">
            <i :class="card.icon" />
          </div>
          <div class="stat-info">
            <span class="stat-label">{{ card.label }}</span>
            <strong class="stat-val">{{ card.value }} <small>单</small></strong>
            <small class="stat-sub">{{ query.return_status === card.key ? '已筛选，再次点击取消' : '本页统计 · 点击筛选' }}</small>
          </div>
        </div>
      </div>

      <!-- 筛选卡片（全部使用 div 容器，杜绝原生 label 导致下拉框闪退） -->
      <div class="filter-card" :class="`filter-card--${kind}`">
        <template v-if="kind === 'purchase'">
          <div class="filter-field">
            <span class="field-label">管理类型</span>
            <el-select v-model="query.management_scope" size="small" clearable placeholder="全部管理类型" @change="reload">
              <el-option v-for="scope in scopeOptions" :key="scope.value" :label="scope.label" :value="scope.value" />
            </el-select>
          </div>
          <div class="filter-field">
            <span class="field-label">退货单号</span>
            <el-input v-model="query.return_no" clearable size="small" placeholder="请输入退货单号" @keyup.enter.native="reload" />
          </div>
          <div class="filter-field">
            <span class="field-label">来源到货单</span>
            <el-input v-model="query.receipt_no" clearable size="small" placeholder="请输入到货单号" @keyup.enter.native="reload" />
          </div>
          <div class="filter-field">
            <span class="field-label">订单 / 物料</span>
            <el-input v-model="query.purchase_keyword" clearable size="small" prefix-icon="el-icon-search" placeholder="采购订单号 / 物料编码 / 名称" @keyup.enter.native="reload" />
          </div>
          <div class="filter-field">
            <span class="field-label">供应商</span>
            <paged-record-select v-model="query.supplier_id" entity="suppliers" :params="{ status: 'enabled' }" title="选择供应商" placeholder="请选择供应商" />
          </div>
          <div class="filter-field">
            <span class="field-label">退货范围</span>
            <el-select v-model="query.return_scope" clearable size="small" placeholder="全部退货范围">
              <el-option label="已入库退货" value="posted_inventory" />
              <el-option label="到货拒收（未入库）" value="rejected_before_posting" />
            </el-select>
          </div>
          <div class="filter-field">
            <span class="field-label">退货状态</span>
            <el-select v-model="query.return_status" clearable size="small" placeholder="全部状态">
              <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
          </div>
          <div class="filter-field date-field">
            <span class="field-label">退货日期范围</span>
            <el-date-picker v-model="dateRange" size="small" type="daterange" value-format="yyyy-MM-dd" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期" />
          </div>
          <div class="filter-actions">
            <el-button size="small" icon="el-icon-refresh-left" @click="reset">重置</el-button>
            <el-button size="small" type="success" icon="el-icon-search" @click="reload">查询</el-button>
          </div>
        </template>

        <template v-else>
          <div class="filter-field">
            <span class="field-label">退货单号/销售订单/原始单号</span>
            <el-input v-model="query.keyword" clearable size="small" placeholder="请输入退货单号/销售订单/原始单号" @keyup.enter.native="reload" />
          </div>
          <div class="filter-field">
            <span class="field-label">客户</span>
            <paged-record-select v-model="query.customer_id" :request="listCustomers" :label="customerLabel" :columns="[{ prop: 'customer_code', label: '客户编码' }, { prop: 'customer_name', label: '客户名称' }]" title="选择客户" placeholder="请选择客户" />
          </div>
          <div class="filter-field">
            <span class="field-label">销售人员</span>
            <paged-record-select v-model="query.sales_user_legacy_id" :request="listSalesUsers" :params="{ scope: 'sales', status: 'normal' }" :label="userLabel" :columns="[{ prop: 'username', label: '账号' }, { prop: 'nickname', label: '姓名' }]" title="选择销售人员" placeholder="请选择销售人员" />
          </div>
          <div class="filter-field">
            <span class="field-label">退货状态</span>
            <el-select v-model="query.return_status" clearable size="small" placeholder="请选择退货状态">
              <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
          </div>
          <div class="filter-field date-field">
            <span class="field-label">退货日期</span>
            <el-date-picker v-model="dateRange" size="small" type="daterange" value-format="yyyy-MM-dd" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期" />
          </div>
          <div class="filter-actions">
            <el-button size="small" icon="el-icon-refresh-left" @click="reset">重置</el-button>
            <el-button size="small" type="success" icon="el-icon-search" @click="reload">查询</el-button>
          </div>
        </template>
      </div>

      <!-- 主表格面板 -->
      <div class="table-panel">
        <el-table v-loading="loading" :data="rows" border stripe size="mini" empty-text="当前筛选条件下暂无退货记录">
          <el-table-column v-if="kind === 'purchase'" label="管理类型" width="110" align="center">
            <template slot-scope="{row}">
              <el-tag size="mini" :type="row.management_scope === 'office' ? 'info' : 'success'">{{ scopeLabel(row.management_scope) }}</el-tag>
            </template>
          </el-table-column>

          <el-table-column prop="return_no" label="退货单号" min-width="165" fixed>
            <template slot-scope="{row}">
              <span class="code-link mono" @click="$router.push(`${basePath}/${row.id}/detail`)">{{ row.return_no }}</span>
            </template>
          </el-table-column>

          <template v-if="kind === 'purchase'">
            <el-table-column label="来源到货单" min-width="150">
              <template slot-scope="{row}">
                <span class="mono-text">{{ row.receipt && row.receipt.receipt_no || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="采购订单" min-width="150">
              <template slot-scope="{row}">
                <span class="mono-text">{{ row.receipt && row.receipt.order && row.receipt.order.purchase_order_no || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="供应商" min-width="180" show-overflow-tooltip>
              <template slot-scope="{row}">
                <span class="supplier-name">{{ row.supplier && row.supplier.supplier_name || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="退货范围" width="150" align="center">
              <template slot-scope="{row}">
                <span :class="['scope-pill', row.return_scope === 'rejected_before_posting' ? 'is-reject' : 'is-posted']">
                  {{ scopeText(row.return_scope) }}
                </span>
              </template>
            </el-table-column>
          </template>

          <template v-else>
            <el-table-column label="销售订单" width="150">
              <template slot-scope="{row}">{{ row.order && row.order.sales_order_no || '-' }}</template>
            </el-table-column>
            <el-table-column label="平台原始单号" width="140">
              <template slot-scope="{row}">{{ row.order && row.order.origin_order_no || '-' }}</template>
            </el-table-column>
            <el-table-column label="客户" min-width="170">
              <template slot-scope="{row}">{{ row.customer_name_snapshot || row.order && row.order.customer_name || '-' }}</template>
            </el-table-column>
          </template>

          <el-table-column label="退货数量" width="102" align="right">
            <template slot-scope="{row}">
              <span class="qty-num">{{ totalQty(row) }}</span>
            </template>
          </el-table-column>

          <el-table-column v-if="kind === 'sales'" label="已收数量" width="95" align="right">
            <template slot-scope="{row}">{{ receivedQty(row) }}</template>
          </el-table-column>
          <el-table-column v-if="kind === 'sales'" label="可重新入库" width="100" align="right">
            <template slot-scope="{row}">{{ restockQty(row) }}</template>
          </el-table-column>

          <el-table-column prop="return_reason" label="退货原因" min-width="135" show-overflow-tooltip />

          <el-table-column label="状态" width="120" align="center">
            <template slot-scope="{row}">
              <el-tag size="mini" :type="statusType(row.return_status)">{{ rowStatusText(row) }}</el-tag>
            </template>
          </el-table-column>

          <el-table-column v-if="kind === 'purchase'" label="库存过账" width="102" align="center">
            <template slot-scope="{row}">
              <el-tag size="mini" :type="row.stock_post_status === 'posted' ? 'success' : 'info'" effect="plain">
                {{ row.stock_post_status === 'posted' ? '已过账' : row.stock_post_status === 'not_required' ? '无需过账' : '未过账' }}
              </el-tag>
            </template>
          </el-table-column>

          <el-table-column label="更新时间" width="145">
            <template slot-scope="{row}">
              <span class="time-text">{{ fmt(row.updated_at) }}</span>
            </template>
          </el-table-column>

          <el-table-column label="操作" class-name="operation-column" :width="kind === 'purchase' ? 186 : 138" fixed="right">
            <template slot-scope="{row}">
              <div class="row-op-group">
                <el-button class="action-link-theme" type="text" size="mini" icon="el-icon-view" @click="$router.push(`${basePath}/${row.id}/detail`)">查看</el-button>
                <el-button
                  v-if="row.return_status === 'draft' && !scopeIssue(row) && $can(kind === 'purchase' ? 'purchase_return.submit' : 'sales_return.confirm')"
                  class="action-link-theme is-strong"
                  type="text"
                  size="mini"
                  icon="el-icon-s-promotion"
                  @click="advance(row)"
                >
                  {{ kind === 'purchase' ? '提交' : '确认' }}
                </el-button>
                <el-button
                  v-if="kind === 'purchase' && !scopeIssue(row) && row.return_status === 'pending_outbound' && $can('purchase_return.post')"
                  class="action-link-theme is-strong"
                  type="text"
                  size="mini"
                  icon="el-icon-box"
                  @click="post(row)"
                >
                  {{ row.return_scope === 'rejected_before_posting' ? '确认退回' : '出库过账' }}
                </el-button>
                <el-button
                  v-if="kind === 'sales' && ['pending_receipt','partial_received'].includes(row.return_status) && $can('sales_return.receive')"
                  class="action-link-theme is-strong"
                  type="text"
                  size="mini"
                  icon="el-icon-takeaway-box"
                  @click="$router.push(`${basePath}/${row.id}/detail?receive=1`)"
                >
                  退货收货
                </el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>

        <div class="pager-bar">
          <span class="pager-info">共 {{ total }} 条退货记录 · 第 {{ query.page }} 页</span>
          <el-pagination
            background
            layout="total, sizes, prev, pager, next, jumper"
            :current-page.sync="query.page"
            :page-size.sync="query.per_page"
            :page-sizes="[10, 20, 50, 100]"
            :total="total"
            @current-change="load"
            @size-change="reload"
          />
        </div>
      </div>
    </div>
  </section>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { erpDateTime } from '../../../utils/dateTime'
import { purchaseScopes, purchaseScopeLabel, purchaseScopeIssue } from '@/utils/purchaseManagementScope.mjs'
import { listPurchaseReturns, submitPurchaseReturn, postPurchaseReturn } from '@/api/erp/purchase'
import { listSalesReturns, confirmSalesReturn, listSalesCustomers } from '@/api/erp/sales'
import { listUsers } from '@/api/erp/rbac'

const baseQuery = () => ({ management_scope: '', keyword: '', return_no: '', receipt_no: '', purchase_keyword: '', supplier_id: '', customer_id: '', sales_user_legacy_id: '', return_scope: '', return_status: '', page: 1, per_page: 10 })

export default {
  components: { PagedRecordSelect },
  props: { kind: { type: String, required: true } },
  data: () => ({ listRevision: 0, loading: false, rows: [], total: 0, dateRange: [], suppliers: [], customers: [], salesUsers: [], query: baseQuery() }),
  computed: {
    scopeOptions() { return purchaseScopes },
    title() { return this.kind === 'purchase' ? '采购退货' : '销售退货' },
    basePath() { return this.kind === 'purchase' ? '/purchase/returns' : '/sales/returns' },
    createPermission() { return this.kind === 'purchase' ? 'purchase_return.create' : 'sales_return.create' },
    statusOptions() {
      const values = this.kind === 'purchase'
        ? ['draft', 'submitted', 'approved', 'pending_outbound', 'completed', 'cancelled', 'closed']
        : ['draft', 'pending_receipt', 'partial_received', 'received', 'completed', 'cancelled', 'closed']
      return values.map(value => ({ value, label: this.statusText(value) }))
    },
    metrics() {
      const cards = this.kind === 'purchase'
        ? [
            { key: 'draft', label: '草稿中', icon: 'el-icon-document', color: 'neutral' },
            { key: 'submitted', label: '待审核', icon: 'el-icon-time', color: 'orange' },
            { key: 'pending_outbound', label: '待出库 / 待退回', icon: 'el-icon-truck', color: 'amber' },
            { key: 'completed', label: '已完成退货', icon: 'el-icon-circle-check', color: 'brand' }
          ]
        : [
            { key: 'draft', label: '草稿', icon: 'el-icon-document', color: 'neutral' },
            { key: 'pending_receipt', label: '待确认', icon: 'el-icon-tickets', color: 'orange' },
            { key: 'received', label: '待收货', icon: 'el-icon-box', color: 'amber' },
            { key: 'partial_received', label: '部分收货', icon: 'el-icon-refresh', color: 'purple' },
            { key: 'completed', label: '已完成', icon: 'el-icon-circle-check', color: 'brand' }
          ]
      return cards.map(card => ({ ...card, value: this.rows.filter(row => row.return_status === card.key).length }))
    },
    rules() {
      return this.kind === 'purchase'
        ? ['退货时仅可选择原到货单及其批次', '退货数量不可超过可退数量', '未过账的退货不产生库存出库', '过账后的退货必须生成出库台账']
        : [
            { title: '原发货出库明细快照', text: '退货单基于原发货出库明细生成，快照记录商品、发货数量、仓库、批次等信息。' },
            { title: '累计退货上限限制', text: '累计退货数量≤已发货数量，超出部分不可提交。' },
            { title: '合格品入库', text: '仅合格品可重新入库，不合格品不入库，需走不良品处置流程。' },
            { title: '支持多次退货收货', text: '同一退货单可多次收货，直至完成或取消。' }
          ]
    }
  },
  // 从不合格品处理等页面跳转过来时带着退货单号，直接定位到该单据。
  created() { if (this.$route.query.return_no) this.query.return_no = String(this.$route.query.return_no); this.load() },
  methods: {
    listCustomers: listSalesCustomers,
    listSalesUsers: listUsers,
    customerLabel(row) { return row.customer_name },
    userLabel(row) { return row.nickname || row.username },
    scopeLabel(scope) { return purchaseScopeLabel(scope) },
    scopeIssue(row) { return this.kind === 'purchase' ? purchaseScopeIssue(row) : '' },
    toggleMetricFilter(status) {
      this.query.return_status = this.query.return_status === status ? '' : status
      this.reload()
    },
    async load() {
      const revision = ++this.listRevision
      this.loading = true
      try {
        const params = { ...this.query, date_from: this.dateRange && this.dateRange[0], date_to: this.dateRange && this.dateRange[1] }
        const { data } = await (this.kind === 'purchase' ? listPurchaseReturns(params) : listSalesReturns(params))
        if (revision !== this.listRevision) return
        this.rows = data.data || []
        this.total = Number(data.total || 0)
      } finally { if (revision === this.listRevision) this.loading = false }
    },
    reload() { this.query.page = 1; this.load() },
    reset() { this.dateRange = []; this.query = baseQuery(); this.load() },
    totalQty(row) { return (row.items || []).reduce((sum, item) => sum + Number(item.requested_base_qty || item.requested_sales_qty || 0), 0).toFixed(2) },
    receivedQty(row) { return (row.items || []).reduce((sum, item) => sum + Number(item.received_base_qty || 0), 0).toFixed(2) },
    restockQty(row) { return (row.items || []).reduce((sum, item) => sum + Number(item.restock_base_qty || 0), 0).toFixed(2) },
    scopeText(value) { return value === 'rejected_before_posting' ? '到货拒收（未入库）' : '已入库退货' },
    rowStatusText(row) { return row.return_status === 'pending_outbound' && row.return_scope === 'rejected_before_posting' ? '待退回供应商' : this.statusText(row.return_status) },
    statusText(value) { return ({ draft: '草稿', submitted: '待审核', approved: '已审核', pending_outbound: '待执行', pending_receipt: '待收货', partial_received: '部分收货', received: '已收货', completed: '已完成', cancelled: '已取消', closed: '已关闭' })[value] || value || '-' },
    statusType(value) { return ({ completed: 'success', approved: 'success', received: 'success', cancelled: 'danger', closed: 'info', submitted: 'warning', pending_outbound: 'warning', pending_receipt: 'warning', partial_received: 'warning' })[value] || 'info' },
    fmt(value) { return value ? erpDateTime(value, 16) : '-' },
    async advance(row) {
      if (this.scopeIssue(row)) return this.$message.warning(this.scopeIssue(row))
      await this.$confirm(`确认${this.kind === 'purchase' ? '提交审核' : '提交确认'}？`, '操作确认')
      await (this.kind === 'purchase' ? submitPurchaseReturn(row.id) : confirmSalesReturn(row.id))
      this.$message.success('操作成功')
      this.load()
    },
    async post(row) {
      if (this.scopeIssue(row)) return this.$message.warning(this.scopeIssue(row))
      const rejected = row.return_scope === 'rejected_before_posting'
      await this.$confirm(rejected ? '确认不合格实物已经退回供应商？本操作不产生库存出库。' : '确认按原仓库、库位和批次执行退货出库过账？', rejected ? '确认退回供应商' : '出库过账', { type: 'warning' })
      await postPurchaseReturn(row.id)
      this.$message.success(rejected ? '已确认退回供应商' : '退货出库已过账')
      this.load()
    },
    exportCurrent() {
      const header = ['退货单号', '销售订单', '平台原始单号', '客户', '退货数量', '退货原因', '状态', '更新时间']
      const lines = this.rows.map(row => [row.return_no, row.order && row.order.sales_order_no, row.order && row.order.origin_order_no, row.customer_name_snapshot || row.order && row.order.customer_name, this.totalQty(row), row.return_reason, this.statusText(row.return_status), this.fmt(row.updated_at)])
      const csv = '\ufeff' + [header, ...lines].map(line => line.map(value => `"${String(value || '').replace(/"/g, '""')}"`).join(',')).join('\n')
      const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); link.download = `销售退货-${Date.now()}.csv`; link.click(); URL.revokeObjectURL(link.href)
    },
    printPage() { window.print() },
    batchCommand(command) { if (command === 'export') this.exportCurrent(); if (command === 'print') this.printPage() }
  }
}
</script>

<style scoped>
.return-page {
  padding: 16px 20px 24px;
  min-height: calc(100vh - 52px);
  background: #f5f7f9;
  color: #0f172a;
  box-sizing: border-box;
}

.return-main {
  min-width: 0;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

/* 顶部页头卡片 */
.page-head {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  margin-bottom: 14px;
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
  flex-wrap: wrap;
  gap: 10px;
}

.title-icon {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
}

.title-row h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
}

.subtitle {
  margin: 0;
  font-size: 12px;
  color: #64748b;
}

.head-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

/* 统计卡片 */
.stat-row {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 14px;
}

.stat-row--sales {
  grid-template-columns: repeat(5, minmax(0, 1fr));
}

.stat-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 14px;
  cursor: pointer;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  transition: transform 0.18s, box-shadow 0.18s, border-color 0.18s, background-color 0.18s;
}

.stat-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
  border-color: #bbf7d0;
}

.stat-card.is-active {
  background: #f0fdf4;
  border-color: #008b4b;
}

.stat-icon-wrapper {
  width: 42px;
  height: 42px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}

.stat-icon-wrapper.is-neutral {
  background: #f1f5f9;
  color: #475569;
}

.stat-icon-wrapper.is-orange {
  background: #fff7ed;
  color: #ea580c;
}

.stat-icon-wrapper.is-amber {
  background: #fffbeb;
  color: #d97706;
}

.stat-icon-wrapper.is-brand {
  background: #f0fdf4;
  color: #008b4b;
}

.stat-icon-wrapper.is-purple {
  background: #f5f3ff;
  color: #7c3aed;
}

.stat-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.stat-label {
  font-size: 12px;
  color: #64748b;
}

.stat-val {
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

.stat-val small {
  font-size: 12px;
  font-weight: 500;
  color: #64748b;
  margin-left: 2px;
}

.stat-sub {
  font-size: 11px;
  color: #94a3b8;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* 筛选卡片 */
.filter-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: grid;
  gap: 12px 16px;
}

.filter-card--purchase {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.filter-card--sales {
  grid-template-columns: 1.25fr 1fr 1fr 1fr;
}

.filter-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
}

.field-label {
  font-size: 12px;
  font-weight: 600;
  color: #334155;
}

.filter-field ::v-deep .el-select,
.filter-field ::v-deep .el-date-editor {
  width: 100%;
}

.filter-card .date-field {
  grid-column: span 2;
}

.filter-actions {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  padding-top: 2px;
}

/* 主表格面板 */
.table-panel {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  overflow: hidden;
}

.code-link {
  color: #008b4b;
  font-weight: 600;
  cursor: pointer;
}

.code-link:hover {
  color: #00763f;
  text-decoration: underline;
}

.mono-text {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  color: #334155;
  font-size: 12px;
}

.supplier-name {
  font-weight: 500;
  color: #0f172a;
}

.scope-pill {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
}

.scope-pill.is-posted {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
}

.scope-pill.is-reject {
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #b45309;
}

.qty-num {
  font-weight: 600;
  color: #0f172a;
  font-variant-numeric: tabular-nums;
}

.time-text {
  color: #64748b;
  font-size: 12px;
}

.row-op-group {
  display: flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
}

.action-link-theme {
  color: #008b4b !important;
  font-weight: 500;
  font-size: 12px;
  padding: 2px 0;
}

.action-link-theme:hover {
  color: #00763f !important;
  text-decoration: underline;
}

.action-link-theme.is-strong {
  font-weight: 600;
}

.pager-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid #f1f5f9;
}

.pager-info {
  font-size: 12px;
  color: #64748b;
}

/* 主题绿控件统一覆盖 */
.return-page ::v-deep .el-table th {
  background: #f8fafc !important;
  color: #475569;
  font-weight: 600;
  font-size: 12px;
}

.return-page ::v-deep .el-table .cell {
  white-space: normal;
  word-break: break-word;
  line-height: 1.5;
}

.return-page ::v-deep .operation-column .cell {
  white-space: nowrap;
}

.return-page ::v-deep .el-button--success {
  background-color: #008b4b;
  border-color: #008b4b;
}

.return-page ::v-deep .el-button--success:hover,
.return-page ::v-deep .el-button--success:focus {
  background-color: #00763f;
  border-color: #00763f;
}

.return-page ::v-deep .el-button--default:hover,
.return-page ::v-deep .el-button--default:focus {
  color: #008b4b;
  border-color: #bbf7d0;
  background-color: #f0fdf4;
}

.return-page ::v-deep .el-input__inner:focus {
  border-color: #008b4b;
}

.return-page ::v-deep .el-tag--success {
  background: #f0fdf4;
  color: #008b4b;
  border-color: #bbf7d0;
}

.return-page ::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #ffffff !important;
  border-color: #008b4b !important;
  border-radius: 4px;
}

@media (max-width: 1180px) {
  .stat-row,
  .stat-row--sales {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .filter-card--purchase,
  .filter-card--sales {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 767px) {
  .return-page {
    padding: 12px 10px 20px;
  }
  .stat-row,
  .stat-row--sales {
    grid-template-columns: minmax(0, 1fr);
    gap: 10px;
  }
  .filter-card--purchase,
  .filter-card--sales {
    grid-template-columns: minmax(0, 1fr);
  }
  .filter-card .date-field {
    grid-column: auto;
  }
}

@media print {
  .head-actions,
  .filter-card,
  .erp-page-tip {
    display: none;
  }
  .return-page {
    padding: 0;
    background: #fff;
  }
}
</style>
