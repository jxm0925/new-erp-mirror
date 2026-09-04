<!--
Design reference: D:\codex-introduce\new_erp\docs\product-design\phase6-order\phase6-sales-order-list.png
Design status: Approved
Do not change layout without approval.
-->
<template>
  <section class="sales-list-page">
    <div class="sales-main">
      <div class="sales-heading">
        <div>
          <div class="sub-breadcrumb">销售管理 / 销售订单</div>
          <h1>销售订单</h1>
          <p>集中查看销售订单草稿与确认前检查结果；本阶段不产生履约、库存、生产或发货单据。</p>
        </div>
        <div class="heading-actions">
          <el-button v-if="$can('sales_order.create')" size="small" type="success" icon="el-icon-plus" @click="$router.push('/sales/orders/create')">新增订单</el-button>
        </div>
      </div>

      <div class="metric-grid">
        <div v-for="card in metrics" :key="card.label" class="metric-card">
          <span :class="['metric-icon', card.type]"><i :class="card.icon" /></span>
          <p>{{ card.label }}</p>
          <strong>{{ card.value }}</strong>
        </div>
      </div>

      <div class="search-panel">
        <el-input v-model="query.keyword" clearable size="small" prefix-icon="el-icon-search" placeholder="搜索订单号、原始订单号、客户、Product、SKU" @keyup.enter.native="load" />
        <el-select v-model="query.order_status" clearable size="small" placeholder="订单状态">
          <el-option label="草稿" value="draft" />
          <el-option label="已确认" value="confirmed" />
          <el-option label="已关闭" value="closed" />
          <el-option label="已取消" value="cancelled" />
        </el-select>
        <el-select v-model="query.fulfillment_status" clearable size="small" placeholder="履约状态">
          <el-option label="待履约" value="pending" />
          <el-option label="部分履约" value="partial" />
          <el-option label="已履约" value="fulfilled" />
          <el-option label="已取消" value="cancelled" />
        </el-select>
        <el-select v-model="query.production_confirm_status" clearable size="small" placeholder="生产确认">
          <el-option label="无需确认" value="not_required" />
          <el-option label="待确认" value="pending" />
          <el-option label="已确认" value="confirmed" />
        </el-select>
        <el-button size="small" type="success" @click="reload">查询</el-button>
        <el-button size="small" @click="reset">重置</el-button>
      </div>

      <div class="status-tabs">
        <button v-for="tab in tabs" :key="tab.value" :class="{ active: activeTab === tab.value }" @click="switchTab(tab.value)">
          {{ tab.label }} <small>{{ tab.count }}</small>
        </button>
      </div>

      <div class="list-layout">
        <div class="table-card">
          <el-table
            v-loading="loading"
            :data="rows"
            border
            size="mini"
            highlight-current-row
            @current-change="selectOrder"
          >
            <el-table-column type="selection" width="38" />
            <el-table-column prop="sales_order_no" label="订单号" width="152" fixed show-overflow-tooltip />
            <el-table-column prop="origin_order_no" label="原始订单号" width="110" show-overflow-tooltip />
            <el-table-column label="客户名称" min-width="150" show-overflow-tooltip>
              <template slot-scope="{row}">
                <strong>{{ row.customer_name || '-' }}</strong>
                <small class="muted">{{ row.customer_phone || row.contact_phone || '-' }}</small>
              </template>
            </el-table-column>
            <el-table-column prop="created_by" label="销售人员" width="88" show-overflow-tooltip />
            <el-table-column label="订单日期" width="108">
              <template slot-scope="{row}">{{ dateOnly(row.order_time) }}</template>
            </el-table-column>
            <el-table-column label="要求交期" width="108">
              <template slot-scope="{row}">{{ dateOnly(row.required_delivery_date) }}</template>
            </el-table-column>
            <el-table-column label="金额" width="100" align="right">
              <template slot-scope="{row}">¥{{ money(row.total_amount) }}</template>
            </el-table-column>
            <el-table-column label="订单状态" width="88">
              <template slot-scope="{row}"><el-tag size="mini" :type="statusTag(row.order_status)">{{ statusText(row.order_status) }}</el-tag></template>
            </el-table-column>
            <el-table-column label="履约状态" width="104">
              <template slot-scope="{row}"><el-tag size="mini" :type="fulfillmentStatusTag(row.fulfillment_status)">{{ fulfillmentStatusText(row.fulfillment_status) }}</el-tag><small v-if="row.fulfillment_composition_label" class="muted">{{ row.fulfillment_composition_label }}</small></template>
            </el-table-column>
            <el-table-column label="生产确认" width="88">
              <template slot-scope="{row}"><el-tag size="mini" :type="statusTag(row.production_confirm_status)">{{ statusText(row.production_confirm_status) }}</el-tag></template>
            </el-table-column>
            <el-table-column label="标记" width="96">
              <template slot-scope="{row}">
                <el-tag v-if="row.is_urgent" size="mini" type="danger">加急</el-tag>
                <el-tag v-if="row.is_delay" size="mini" type="warning">延期</el-tag>
                <el-tag v-if="row.is_customized" size="mini">定制</el-tag>
                <span v-if="!row.is_urgent && !row.is_delay && !row.is_customized" class="muted">-</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="182" fixed="right">
              <template slot-scope="{row}">
                <el-button type="text" size="mini" @click.stop="$router.push(`/sales/orders/${row.id}/detail`)">查看详情</el-button>
                <el-button v-if="row.allowed_actions && row.allowed_actions.edit_draft" type="text" size="mini" @click.stop="$router.push(`/sales/orders/${row.id}/edit`)">编辑</el-button>
                <el-button v-if="row.allowed_actions && row.allowed_actions.submit_confirmation" type="text" size="mini" @click.stop="confirmOrder(row)">确认前检查</el-button>
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

        <aside class="order-side">
          <div class="side-head">
            <strong>订单阻塞信息（关联订单）</strong>
            <i class="el-icon-close" @click="selected = null" />
          </div>
          <div v-if="selected" class="side-body">
            <h3>{{ selected.sales_order_no }}</h3>
            <el-tag size="mini" :type="fulfillmentStatusTag(selected.fulfillment_status)">{{ fulfillmentStatusText(selected.fulfillment_status) }}</el-tag>
            <small v-if="selected.fulfillment_composition_label" class="muted">{{ selected.fulfillment_composition_label }}</small>
            <dl>
              <dt>客户</dt><dd>{{ selected.customer_name || '-' }}</dd>
              <dt>金额</dt><dd>¥{{ money(selected.total_amount) }}</dd>
              <dt>订单行</dt><dd>{{ (selected.lines || []).length }} 行</dd>
              <dt>生产确认</dt><dd>{{ statusText(selected.production_confirm_status) }}</dd>
            </dl>
            <div class="side-actions">
              <el-button size="small" plain @click="$router.push(`/sales/orders/${selected.id}/detail`)">查看详情</el-button>
               <el-button v-if="selected.allowed_actions && selected.allowed_actions.edit_draft" size="small" plain @click="$router.push(`/sales/orders/${selected.id}/edit`)">编辑订单</el-button>
            </div>
          </div>
          <div v-else class="side-empty">
            <i class="el-icon-document-checked" />
            <strong>请选择订单查看阻塞信息</strong>
            <p>从左侧列表选择订单，查看当前阻塞原因与建议处理入口。</p>
          </div>
        </aside>
      </div>
    </div>
  </section>
</template>

<script>
import { confirmSalesOrder, deleteSalesOrderDraft, listSalesOrders } from '@/api/erp/sales'
import { statusTag, statusText } from '@/utils/erpStatus'

export default {
  data: () => ({
    loading: false,
    rows: [],
    total: 0,
    selected: null,
    activeTab: '',
    query: { keyword: '', order_status: '', fulfillment_status: '', production_confirm_status: '', page: 1, per_page: 20 }
  }),
  computed: {
    metrics() {
      const all = this.total
      const draft = this.rows.filter(r => r.order_status === 'draft').length
      const pending = this.rows.filter(r => r.production_confirm_status === 'pending').length
      const pendingFulfillment = this.rows.filter(r => r.fulfillment_status === 'pending').length
      const partialFulfillment = this.rows.filter(r => r.fulfillment_status === 'partial').length
      const fulfilled = this.rows.filter(r => r.fulfillment_status === 'fulfilled').length
      const exception = this.rows.filter(r => r.order_status === 'cancelled').length
      return [
        { label: '全部订单', value: all, icon: 'el-icon-s-order', type: 'green' },
        { label: '待提交确认', value: draft, icon: 'el-icon-edit-outline', type: 'orange' },
        { label: '待生产确认', value: pending, icon: 'el-icon-cpu', type: 'blue' },
        { label: '待履约', value: pendingFulfillment, icon: 'el-icon-box', type: 'purple' },
        { label: '部分履约', value: partialFulfillment, icon: 'el-icon-s-operation', type: 'cyan' },
        { label: '已履约', value: fulfilled, icon: 'el-icon-set-up', type: 'green' },
        { label: '异常订单', value: exception, icon: 'el-icon-warning-outline', type: 'red' }
      ]
    },
    tabs() {
      return [
        { label: '全部', value: '', count: this.total },
        { label: '待提交确认', value: 'draft', count: this.rows.filter(r => r.order_status === 'draft').length },
        { label: '待生产确认', value: 'pending-production', count: this.rows.filter(r => r.production_confirm_status === 'pending').length },
        { label: '待履约', value: 'pending', count: this.rows.filter(r => r.fulfillment_status === 'pending').length },
        { label: '部分履约', value: 'partial', count: this.rows.filter(r => r.fulfillment_status === 'partial').length },
        { label: '已履约', value: 'fulfilled', count: this.rows.filter(r => r.fulfillment_status === 'fulfilled').length }
      ]
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
        if (this.selected) this.selected = this.rows.find(item => item.id === this.selected.id) || null
        else this.selected = null
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
      this.query = { keyword: '', order_status: '', fulfillment_status: '', production_confirm_status: '', page: 1, per_page: 20 }
      this.load()
    },
    switchTab(tab) {
      this.activeTab = tab
      this.query.order_status = tab === 'draft' ? 'draft' : ''
      this.query.production_confirm_status = tab === 'pending-production' ? 'pending' : ''
      this.query.fulfillment_status = ['pending', 'partial', 'fulfilled', 'cancelled'].includes(tab) ? tab : ''
      this.reload()
    },
    selectOrder(row) {
      this.selected = row
    },
    async confirmOrder(row) {
      const response = await confirmSalesOrder(row.id)
      const result = response.data && response.data.data ? response.data.data : response.data
      this.$alert(this.precheckHtml(result), '确认前检查结果', { dangerouslyUseHTMLString: true, confirmButtonText: '返回编辑' })
    },
    precheckHtml(result) {
      const items = (result.checks || []).map(check => `<li class="${check.status}"><b>${check.status === 'passed' ? '通过' : '阻塞'}</b> ${check.message}</li>`).join('')
      const fallback = '<li class="blocked"><b>阻塞</b> 服务端未返回逐项检查结果，请勿进入下一阶段。</li>'
      return `<p>${result.message || '当前仅进行确认前检查，不改变订单状态，不生成下游单据。'}</p><ul class="sales-precheck-list">${items || fallback}</ul>`
    },
    moreActions(row) {
      const actions = []
      if (row.allowed_actions && row.allowed_actions.edit_draft) actions.push({ command: 'edit', label: '编辑订单' })
      if (row.allowed_actions && row.allowed_actions.delete_draft) actions.push({ command: 'delete-draft', label: '删除草稿' })
      if (row.order_status === 'confirmed') actions.push({ command: 'logs', label: '日志与版本' })
      return actions
    },
    async handleCommand(cmd, row) {
      if (cmd === 'edit') this.$router.push(`/sales/orders/${row.id}/edit`)
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
    statusTag,
    fulfillmentStatusText(value) { return ({ pending: '待履约', partial: '部分履约', fulfilled: '已履约', cancelled: '已取消' })[value] || value || '-' },
    fulfillmentStatusTag(value) { return ({ pending: 'warning', partial: 'warning', fulfilled: 'success', cancelled: 'danger' })[value] || 'info' },
    dateOnly(v) {
      return v ? String(v).slice(0, 10) : '-'
    }
  }
}
</script>

<style scoped>
.sales-list-page{padding:16px;min-height:calc(100vh - 52px);background:#f7f8fa}.sales-heading{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px}.sub-breadcrumb{font-size:13px;color:#607085;margin-bottom:6px}.sales-heading h1{margin:0;font-size:24px;line-height:30px;color:#111827}.sales-heading p{margin:4px 0 0;color:#738092}.heading-actions{display:flex;gap:8px}.metric-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;margin-bottom:12px}.metric-card{height:78px;padding:14px 16px;background:#fff;border:1px solid #e4e9f0;border-radius:5px;display:grid;grid-template-columns:36px 1fr;column-gap:12px;align-items:center}.metric-card p{margin:0;color:#607085}.metric-card strong{font-size:24px;color:#111827;line-height:26px}.metric-icon{grid-row:span 2;width:36px;height:36px;border-radius:10px;display:grid;place-items:center}.metric-icon.green{color:#079855;background:#eaf8f0}.metric-icon.orange{color:#f97316;background:#fff2e8}.metric-icon.blue{color:#2563eb;background:#eef4ff}.metric-icon.purple{color:#7c3aed;background:#f3edff}.metric-icon.cyan{color:#0891b2;background:#e8fbff}.metric-icon.red{color:#ef4444;background:#fff1f2}.search-panel{padding:12px;background:#fff;border:1px solid #e4e9f0;border-radius:5px;display:grid;grid-template-columns:1fr 150px 190px 150px 70px 70px;gap:8px}.status-tabs{display:flex;gap:8px;margin:12px 0}.status-tabs button{height:32px;padding:0 14px;border:1px solid #dfe5ec;border-radius:4px;background:#fff;color:#334155;cursor:pointer}.status-tabs button.active{border-color:#00984f;color:#00984f;background:#edf9f2}.status-tabs small{margin-left:4px;color:#f97316}.list-layout{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:10px}.table-card{background:#fff;border:1px solid #e4e9f0;border-radius:5px;overflow:hidden}.table-card .el-pagination{padding:14px;text-align:right}.muted{display:block;color:#8b96a5;font-size:11px}.more-link{margin-left:8px;color:#2563eb;font-size:12px;cursor:pointer}.order-side{display:grid;gap:12px;align-content:start}.side-head,.side-body,.side-empty,.quick-entry,.warning-box{background:#fff;border:1px solid #e4e9f0;border-radius:5px}.side-head{height:48px;padding:0 14px;display:flex;align-items:center;justify-content:space-between}.side-head i{cursor:pointer}.side-body{padding:14px}.side-body h3{margin:0 0 8px;font-size:17px}.side-body dl{display:grid;grid-template-columns:70px 1fr;gap:8px;margin:14px 0}.side-body dt{color:#718096}.side-body dd{margin:0;color:#1f2937}.side-actions{display:grid;gap:8px}.side-empty{height:300px;padding:54px 26px;text-align:center;color:#7b8794}.side-empty i{font-size:48px;color:#b7e5d0}.side-empty strong{display:block;margin:12px 0 8px;color:#334155}.quick-entry{padding:14px;display:grid;gap:9px}.quick-entry h4{margin:0 0 4px;font-size:15px}.quick-entry button{height:36px;border:1px solid #e0e6ee;border-radius:4px;background:#fff;text-align:left;padding:0 12px;color:#2563eb}.quick-entry button:disabled{color:#94a3b8;background:#f8fafc}.warning-box{padding:14px;background:#fff8ed;border-color:#ffd9a8;color:#9a5b13}.warning-box p{margin:8px 0 0;font-size:12px;line-height:1.5}.sales-list-page :deep(.el-table th){background:#f8fafc;color:#334155}.sales-list-page :deep(.el-button--success){background:#00984f;border-color:#00984f}@media(max-width:1360px){.metric-grid{grid-template-columns:repeat(4,1fr)}.list-layout{grid-template-columns:1fr}.order-side{grid-template-columns:1fr 1fr}.side-empty{height:auto}}
/* Keep the desktop list composition until the actual narrow-screen breakpoint. */
@media (min-width:1101px) and (max-width:1360px){
  .metric-grid{grid-template-columns:repeat(7,minmax(0,1fr))!important}
  .list-layout{grid-template-columns:minmax(0,1fr) 280px!important}
  .order-side{grid-template-columns:1fr!important}
  .side-empty{height:300px!important}
}
</style>
