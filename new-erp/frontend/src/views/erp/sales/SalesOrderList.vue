<!--
Design reference: D:\codex-introduce\new_erp\docs\product-design\phase6-order\phase6-sales-order-list.png
Design status: 按“基础档案”页面风格重做（页头卡片 / 提示条 / 带数量页签 / 居中弹窗），待用户确认。
说明：订单流程为 草稿 → 确认订单 → 核实期（工单未发布，可直接修改）→ 生产确认 → 工单发布后修改需审核。
-->
<template>
  <div class="sales-order-page">
    <!-- 页面头部：图标、标题、当前页签统计与主要操作 -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-s-order" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">销售订单</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">当前模块：{{ currentTabLabel }}（{{ currentTabCount }}项）</el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" @click="load">刷新</el-button>
        <el-button v-if="$can('sales_order.create')" size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="$router.push('/sales/orders/create')">新增订单</el-button>
      </div>
    </header>

    <!-- 页面提示条：说明订单流程 -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>订单流程：草稿（可随意修改）→ <b>确认订单</b>（不生成工单）→ 核实期（工单发布前可直接修改）→ <b>生产确认</b>（生成待发布工单）→ 工单发布后，订单修改须经相关负责人审核。</span>
    </div>

    <section class="table-container-card">
      <!-- 带数量徽标的状态页签 -->
      <div class="status-tabs">
        <button v-for="tab in tabs" :key="tab.value" type="button" :class="['tab-item', { active: activeTab === tab.value }]" @click="switchTab(tab.value)">
          {{ tab.label }}<span class="tab-badge">{{ tab.count }}</span>
        </button>
      </div>

      <div class="filter-toolbar">
        <div class="filter-fields">
          <el-input v-model="query.keyword" clearable size="small" prefix-icon="el-icon-search" class="filter-input-search" placeholder="搜索订单号、原始订单号、客户、Product、SKU" @keyup.enter.native="reload" />
          <el-select v-model="query.order_status" clearable size="small" placeholder="订单状态" class="filter-select">
            <el-option label="草稿" value="draft" />
            <el-option label="已确认" value="confirmed" />
            <el-option label="已完成" value="closed" />
            <el-option label="已取消" value="cancelled" />
          </el-select>
          <el-select v-model="query.fulfillment_status" clearable size="small" placeholder="交付状态" class="filter-select">
            <el-option label="待交付" value="pending" />
            <el-option label="部分交付" value="partial" />
            <el-option label="已交付" value="fulfilled" />
            <el-option label="已取消" value="cancelled" />
          </el-select>
          <el-select v-model="query.production_confirm_status" clearable size="small" placeholder="生产确认" class="filter-select">
            <el-option label="无需确认" value="not_required" />
            <el-option label="待确认" value="pending" />
            <el-option label="已确认" value="confirmed" />
          </el-select>
        </div>
        <div class="filter-buttons">
          <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" @click="reload">查询</el-button>
          <el-button size="small" icon="el-icon-refresh-left" class="btn-theme-reset" @click="reset">重置</el-button>
        </div>
      </div>

      <div class="table-body">
        <el-table v-loading="loading" :data="rows" border stripe size="small" class="order-data-table">
          <el-table-column label="订单号" width="160" fixed show-overflow-tooltip>
            <template slot-scope="{row}">
              <el-button type="text" size="mini" class="order-no-link" @click="openSummary(row)">{{ row.sales_order_no }}</el-button>
            </template>
          </el-table-column>
          <el-table-column prop="origin_order_no" label="原始订单号" width="120" show-overflow-tooltip />
          <el-table-column label="客户名称" min-width="150" show-overflow-tooltip>
            <template slot-scope="{row}">
              <strong>{{ row.customer_name || '-' }}</strong>
              <small class="muted">{{ row.customer_phone || row.contact_phone || '-' }}</small>
            </template>
          </el-table-column>
          <el-table-column prop="created_by" label="销售人员" width="88" show-overflow-tooltip />
          <el-table-column label="成交平台" width="150" show-overflow-tooltip><template slot-scope="{ row }">{{ row.platform_label || '-' }}</template></el-table-column>
          <el-table-column label="订单日期" width="104">
            <template slot-scope="{row}">{{ dateOnly(row.order_time) }}</template>
          </el-table-column>
          <el-table-column label="要求交期" width="104">
            <template slot-scope="{row}">{{ dateOnly(row.required_delivery_date) }}</template>
          </el-table-column>
          <el-table-column label="金额" width="110" align="right">
            <template slot-scope="{row}">¥{{ money(row.total_amount) }}</template>
          </el-table-column>
          <el-table-column label="订单状态" width="88">
            <template slot-scope="{row}"><el-tag size="mini" :type="orderStatusTag(row.order_status)">{{ orderStatusText(row.order_status) }}</el-tag></template>
          </el-table-column>
          <el-table-column label="订单阶段" width="150">
            <template slot-scope="{row}">
              <el-tag size="mini" :type="stageOf(row).type">{{ stageOf(row).label }}</el-tag>
              <small class="muted">{{ stageOf(row).hint }}</small>
            </template>
          </el-table-column>
          <el-table-column label="生产确认" width="92">
            <template slot-scope="{row}"><span v-if="row.order_status === 'draft'" class="muted">-</span><el-tag v-else size="mini" :type="productionTag(row.production_confirm_status)">{{ productionText(row.production_confirm_status) }}</el-tag></template>
          </el-table-column>
          <el-table-column label="资金" width="92">
            <template slot-scope="{row}">
              <span v-if="['draft', 'cancelled'].includes(row.order_status)" class="muted">-</span>
              <template v-else>
                <el-tag size="mini" :type="row.production_funding_status === 'passed' ? 'success' : 'warning'">{{ row.production_funding_status === 'passed' ? '可开工' : '定金未到' }}</el-tag>
                <small class="muted">{{ row.shipment_funding_status === 'passed' ? '可发货' : '尾款未齐' }}</small>
              </template>
            </template>
          </el-table-column>
          <el-table-column label="交付状态" width="112">
            <template slot-scope="{row}">
              <el-tag size="mini" :type="fulfillmentStatusTag(row.fulfillment_status)">{{ fulfillmentStatusText(row.fulfillment_status) }}</el-tag>
              <small class="muted">发货：{{ shipmentStatusText(row.shipment_status) }}</small>
              <small v-if="row.fulfillment_composition_label" class="muted">{{ row.fulfillment_composition_label }}</small>
            </template>
          </el-table-column>
          <el-table-column label="标记" width="96">
            <template slot-scope="{row}">
              <el-tag v-if="row.is_urgent" size="mini" type="danger">加急</el-tag>
              <el-tag v-if="row.is_delay" size="mini" type="warning">延期</el-tag>
              <el-tag v-if="row.is_customized" size="mini">定制</el-tag>
              <span v-if="!row.is_urgent && !row.is_delay && !row.is_customized" class="muted">-</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="230" fixed="right">
            <template slot-scope="{row}">
              <el-button type="text" size="mini" @click.stop="$router.push(`/sales/orders/${row.id}/detail`)">查看</el-button>
              <el-button v-if="canEdit(row)" type="text" size="mini" @click.stop="$router.push(`/sales/orders/${row.id}/edit`)">{{ editLabel(row) }}</el-button>
              <el-button v-if="row.allowed_actions && row.allowed_actions.submit_confirmation" type="text" size="mini" @click.stop="confirmOrder(row)">确认订单</el-button>
              <el-button v-if="row.allowed_actions && row.allowed_actions.production_confirmation" type="text" size="mini" @click.stop="$router.push(`/sales/orders/${row.id}/production-confirmation`)">生产确认</el-button>
              <el-dropdown v-if="moreActions(row).length" trigger="click" @command="cmd => handleCommand(cmd, row)">
                <span class="more-link">更多<i class="el-icon-arrow-down" /></span>
                <el-dropdown-menu slot="dropdown">
                  <el-dropdown-item v-for="item in moreActions(row)" :key="item.command" :command="item.command">{{ item.label }}</el-dropdown-item>
                </el-dropdown-menu>
              </el-dropdown>
            </template>
          </el-table-column>
        </el-table>
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
    </section>

    <!-- 订单概要与处理建议：居中弹窗 -->
    <el-dialog :visible.sync="summaryVisible" width="520px" append-to-body custom-class="order-summary-dialog" title="订单概要与处理建议">
      <div v-if="selected" class="summary-body">
        <div class="summary-title">
          <h3>{{ selected.sales_order_no }}</h3>
          <el-tag size="mini" :type="stageOf(selected).type">{{ stageOf(selected).label }}</el-tag>
        </div>
        <div class="summary-next"><i class="el-icon-s-flag" /><span>{{ nextStep(selected) }}</span></div>
        <dl>
          <dt>客户</dt><dd>{{ selected.customer_name || '-' }}</dd>
          <dt>金额</dt><dd>¥{{ money(selected.total_amount) }}</dd>
          <dt>订单行</dt><dd>{{ (selected.lines || []).length }} 行</dd>
          <dt>交付状态</dt>
          <dd>
            <el-tag size="mini" :type="fulfillmentStatusTag(selected.fulfillment_status)">{{ fulfillmentStatusText(selected.fulfillment_status) }}</el-tag>
            <small v-if="selected.fulfillment_composition_label" class="muted">{{ selected.fulfillment_composition_label }}</small>
          </dd>
          <dt>发货状态</dt><dd><el-tag size="mini" :type="statusTag(selected.shipment_status)">{{ shipmentStatusText(selected.shipment_status) }}</el-tag></dd>
          <dt>生产确认</dt><dd>{{ productionText(selected.production_confirm_status) }}</dd>
        </dl>
      </div>
      <span slot="footer" class="dialog-footer">
        <el-button size="small" @click="summaryVisible = false">关闭</el-button>
        <el-button v-if="selected" size="small" @click="$router.push(`/sales/orders/${selected.id}/detail`)">查看详情</el-button>
        <el-button v-if="selected && canEdit(selected)" size="small" @click="$router.push(`/sales/orders/${selected.id}/edit`)">{{ editLabel(selected) }}</el-button>
        <el-button v-if="selected && selected.allowed_actions && selected.allowed_actions.production_confirmation" size="small" type="success" class="btn-theme-create" @click="$router.push(`/sales/orders/${selected.id}/production-confirmation`)">生产确认</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import { confirmSalesOrder, deleteSalesOrderDraft, listSalesOrders } from '@/api/erp/sales'
import { statusTag, statusText, shipmentStatusText, orderStatusText, orderStatusTag } from '@/utils/erpStatus'

const TABS = [
  { label: '全部', value: '', countKey: 'all' },
  { label: '草稿', value: 'draft', countKey: 'draft' },
  { label: '待生产确认', value: 'pending_production', countKey: 'pending_production' },
  { label: '待交付', value: 'pending', countKey: 'pending' },
  { label: '部分交付', value: 'partial', countKey: 'partial' },
  { label: '已交付', value: 'fulfilled', countKey: 'fulfilled' }
]

const emptyQuery = () => ({ keyword: '', order_status: '', fulfillment_status: '', production_confirm_status: '', stage: '', page: 1, per_page: 10 })

export default {
  data: () => ({
    loading: false,
    rows: [],
    total: 0,
    stageCounts: {},
    selected: null,
    summaryVisible: false,
    activeTab: '',
    query: emptyQuery()
  }),
  computed: {
    tabs() {
      return TABS.map(tab => ({ ...tab, count: this.stageCounts[tab.countKey] || 0 }))
    },
    currentTabLabel() {
      const tab = TABS.find(item => item.value === this.activeTab)
      return tab ? tab.label : '全部'
    },
    currentTabCount() {
      return this.total
    }
  },
  created() {
    this.load()
  },
  methods: {
    async load() {
      this.loading = true
      try {
        const { data } = await listSalesOrders(this.query)
        this.rows = data.data || []
        this.total = data.total || 0
        this.stageCounts = data.stage_counts || this.stageCounts
        if (this.selected) this.selected = this.rows.find(item => item.id === this.selected.id) || this.selected
      } finally {
        this.loading = false
      }
    },
    reload() {
      this.query.page = 1
      this.load()
    },
    reset() {
      this.activeTab = ''
      this.query = emptyQuery()
      this.load()
    },
    switchTab(tab) {
      this.activeTab = tab
      this.query.order_status = ''
      this.query.production_confirm_status = ''
      this.query.fulfillment_status = ''
      this.query.stage = ''
      if (['draft', 'pending_production', 'fulfilled'].includes(tab)) this.query.stage = tab
      if (['pending', 'partial'].includes(tab)) {
        this.query.order_status = 'confirmed'
        this.query.fulfillment_status = tab
      }
      this.reload()
    },
    openSummary(row) {
      this.selected = row
      this.summaryVisible = true
    },
    canEdit(row) {
      const actions = row.allowed_actions || {}
      return !!(actions.edit_draft || actions.edit)
    },
    editLabel(row) {
      const actions = row.allowed_actions || {}
      if (row.order_status !== 'confirmed') return '编辑'
      return actions.production_released ? '申请修改' : '编辑'
    },
    // 订单所处阶段：用于让销售一眼看出“现在能不能随便改”。
    stageOf(row) {
      const actions = row.allowed_actions || {}
      if (row.order_status === 'draft') return { label: '草稿', type: 'info', hint: '可随意修改' }
      if (row.order_status === 'cancelled') return { label: '已取消', type: 'danger', hint: '' }
      if (row.order_status === 'closed') return { label: '已完成', type: 'success', hint: '已收齐款并全部发出' }
      if (row.order_status !== 'confirmed') return { label: statusText(row.order_status), type: 'info', hint: '' }
      if (row.short_closed_at) return { label: '已短交', type: 'warning', hint: '剩余未发已关闭，待收款/退款后完成' }
      if (actions.production_released) return { label: '工单已发布', type: 'warning', hint: '修改需审核' }
      if (['pending', 'blocked'].includes(row.production_confirm_status)) return { label: '核实期', type: 'success', hint: '待生产确认，可直接修改' }
      if (row.production_confirm_status === 'not_required') return { label: '已确认', type: 'success', hint: '无需生产确认' }
      return { label: '待发布工单', type: 'success', hint: '发布前可直接修改' }
    },
    nextStep(row) {
      const actions = row.allowed_actions || {}
      if (row.order_status === 'draft') return '请核对订单信息，无误后点击“确认订单”。草稿可随意修改。'
      if (row.order_status !== 'confirmed') return '该订单已结束，无需处理。'
      if (row.short_closed_at) return '订单已短交关闭，剩余未发不再发货；应收已按实发金额调整，收齐款或办完退款后自动完成。'
      if (actions.production_released) return '工单已发布，订单修改须提交并经相关负责人审核后才会生效。'
      if (['pending', 'blocked'].includes(row.production_confirm_status)) return '订单已确认，尚未生成工单。请核实订单（此阶段可直接修改），无误后执行“生产确认”。'
      if (row.production_confirm_status === 'not_required') return '该订单无需生产确认。'
      return '已生成待发布工单。工单发布前仍可直接修改订单（会撤销待发布工单并需重新生产确认）。'
    },
    async confirmOrder(row) {
      // 一步确认：服务端先检查、通过后立即确认；未通过不改状态。确认订单不会生成工单。
      try {
        await this.$confirm('确认后将锁定单位换算与资金条款，订单进入核实期：此时不会生成工单，工单发布前仍可直接修改订单。系统会先自动检查，是否继续？', '确认订单', { type: 'warning' })
      } catch (error) {
        return
      }
      const response = await confirmSalesOrder(row.id)
      const result = response.data && response.data.data ? response.data.data : response.data
      if (result.passed && !(result.checks || []).some(check => check.status === 'blocked')) {
        this.$message.success('订单已确认，请核实后执行“生产确认”')
        await this.load()
        return
      }
      this.$alert(this.precheckHtml(result), '订单暂不能确认', { dangerouslyUseHTMLString: true, confirmButtonText: '返回编辑' })
    },
    precheckHtml(result) {
      const items = (result.checks || []).map(check => `<li class="${check.status}"><b>${check.status === 'passed' ? '通过' : '阻塞'}</b> ${check.message}</li>`).join('')
      const fallback = '<li class="blocked"><b>阻塞</b> 服务端未返回逐项检查结果，请勿进入下一阶段。</li>'
      return `<p>${result.message || '当前仅进行确认前检查，不改变订单状态，不生成下游单据。'}</p><ul class="sales-precheck-list">${items || fallback}</ul>`
    },
    moreActions(row) {
      const actions = []
      if (row.allowed_actions && row.allowed_actions.delete_draft) actions.push({ command: 'delete-draft', label: '删除草稿' })
      if (row.order_status === 'confirmed') actions.push({ command: 'logs', label: '日志与版本' })
      actions.push({ command: 'summary', label: '处理建议' })
      return actions
    },
    async handleCommand(cmd, row) {
      if (cmd === 'summary') this.openSummary(row)
      if (cmd === 'logs') this.$router.push(`/sales/orders/${row.id}/detail?tab=logs`)
      if (cmd === 'delete-draft') {
        await this.$confirm('删除后草稿及其订单行将不能恢复，附件绑定会保留审计记录。确认删除？', '删除销售订单草稿', { type: 'warning' })
        await deleteSalesOrderDraft(row.id)
        this.$message.success('销售订单草稿已删除')
        if (this.selected && this.selected.id === row.id) this.selected = null
        await this.load()
      }
    },
    money(value) {
      return Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    statusText,
    orderStatusText,
    orderStatusTag,
    statusTag,
    shipmentStatusText,
    productionText(value) { return ({ pending: '待生产确认', blocked: '受阻', confirmed: '已确认', not_required: '无需确认' })[value] || value || '-' },
    productionTag(value) { return ({ pending: 'warning', blocked: 'danger', confirmed: 'success', not_required: 'info' })[value] || 'info' },
    fulfillmentStatusText(value) { return ({ pending: '待交付', partial: '部分交付', fulfilled: '已交付', cancelled: '已取消' })[value] || value || '-' },
    fulfillmentStatusTag(value) { return ({ pending: 'warning', partial: 'warning', fulfilled: 'success', cancelled: 'danger' })[value] || 'info' },
    dateOnly(v) {
      return v ? String(v).slice(0, 10) : '-'
    }
  }
}
</script>

<style scoped>
.sales-order-page { min-height: calc(100vh - 52px); padding: 16px 20px 30px; background: #f5f7fa; color: #1f2937; box-sizing: border-box; }

/* 页面头部 */
.page-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 16px 20px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03); }
.head-left { display: flex; align-items: center; gap: 12px; }
.head-icon { width: 38px; height: 38px; border-radius: 8px; background: #f0fdf4; color: #008b4b; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.head-title-wrap { display: flex; flex-direction: column; }
.title-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.page-title { margin: 0; font-size: 18px; font-weight: 700; color: #0f172a; }
.head-tag { border-radius: 4px; font-weight: 500; }
.head-actions { display: flex; align-items: center; gap: 10px; }
.btn-refresh { border-color: #cbd5e1 !important; color: #475569 !important; }
.btn-refresh:hover { border-color: #008b4b !important; color: #008b4b !important; }
.btn-theme-create, .btn-theme-search { background: #008b4b !important; border-color: #008b4b !important; color: #fff !important; font-weight: 500; }
.btn-theme-create:hover, .btn-theme-search:hover { background: #00763f !important; border-color: #00763f !important; }
.btn-theme-reset { background: #fff !important; border: 1px solid #dcdfe6 !important; color: #4b5563 !important; }
.btn-theme-reset:hover { border-color: #008b4b !important; color: #008b4b !important; }

/* 页面提示条 */
.erp-page-tip { display: flex; align-items: flex-start; gap: 8px; padding: 9px 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; color: #00763f; font-size: 13px; line-height: 1.5; margin-bottom: 14px; }
.erp-page-tip i { font-size: 15px; color: #008b4b; flex-shrink: 0; margin-top: 2px; }

/* 主卡片 */
.table-container-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03); overflow: hidden; box-sizing: border-box; }
.status-tabs { display: flex; gap: 6px; padding: 10px 18px 0; border-bottom: 1px solid #edf1f5; flex-wrap: wrap; }
.tab-item { display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 14px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: #475569; font-size: 13px; cursor: pointer; }
.tab-item:hover { color: #008b4b; }
.tab-item.active { color: #008b4b; border-bottom-color: #008b4b; font-weight: 600; }
.tab-badge { min-width: 20px; padding: 0 6px; height: 18px; line-height: 18px; border-radius: 9px; background: #eef2f6; color: #64748b; font-size: 12px; text-align: center; }
.tab-item.active .tab-badge { background: #dcfce7; color: #00763f; }

.filter-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 12px 18px; background: #fafbfc; border-bottom: 1px solid #edf1f5; flex-wrap: wrap; gap: 12px; }
.filter-fields { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.filter-input-search { width: 280px; }
.filter-select { width: 140px; }
.filter-buttons { display: flex; gap: 8px; }

.table-body { padding: 0; }
.order-data-table ::v-deep th { background: #f8fafc !important; color: #374151 !important; font-weight: 600; height: 40px; }
.table-body .el-pagination { padding: 14px 18px; text-align: right; }
.muted { display: block; color: #8b96a5; font-size: 11px; line-height: 1.4; }
.order-no-link { color: #008b4b; font-weight: 600; }
.more-link { margin-left: 8px; color: #008b4b; font-size: 12px; cursor: pointer; }

/* 概要弹窗 */
.summary-title { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.summary-title h3 { margin: 0; font-size: 17px; }
.summary-next { display: flex; gap: 8px; padding: 9px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; color: #00763f; font-size: 13px; line-height: 1.5; }
.summary-next i { color: #008b4b; margin-top: 2px; }
.summary-body dl { display: grid; grid-template-columns: 80px 1fr; gap: 10px 8px; margin: 14px 0 0; }
.summary-body dt { color: #718096; }
.summary-body dd { margin: 0; color: #1f2937; }

/* 响应式：900px 以下工具栏纵向排列，600px 以下弹窗与页边距收窄 */
@media (max-width: 900px) {
  .sales-order-page { padding: 12px; }
  .filter-input-search, .filter-select { width: 100%; }
  .filter-fields { width: 100%; }
  .filter-buttons { width: 100%; }
  .table-body .el-pagination { text-align: center; overflow-x: auto; }
}
@media (max-width: 600px) {
  .page-head { padding: 12px; }
  .status-tabs { padding: 8px 8px 0; }
  .tab-item { padding: 0 10px; }
}
</style>
<style>
.order-summary-dialog { max-width: 94vw; }
@media (max-width: 600px) { .order-summary-dialog { width: 94vw !important; } }
</style>
