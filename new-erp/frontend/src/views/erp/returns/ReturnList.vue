<template>
  <section class="return-page" :class="`return-page--${kind}`">
    <div class="page-head">
      <div>
        <p v-if="kind === 'sales'" class="breadcrumb">销售管理&nbsp;&nbsp;/&nbsp;&nbsp;销售退货</p>
        <h1>{{ title }}</h1>
        <p v-if="kind === 'purchase'" class="subtitle">退还不合格、超采或多余物料到供应商</p>
      </div>
      <div class="head-actions">
        <el-button v-if="$can(createPermission)" size="small" type="success" icon="el-icon-plus" @click="$router.push(`${basePath}/create`)">新建{{ title }}单</el-button>
        <template v-if="kind === 'sales'">
          <el-button size="small" icon="el-icon-download" @click="exportCurrent">导出</el-button>
          <el-button size="small" icon="el-icon-printer" @click="printPage">打印</el-button>
          <el-dropdown trigger="click" @command="batchCommand"><el-button size="small">批量操作 <i class="el-icon-arrow-down el-icon--right" /></el-button><el-dropdown-menu slot="dropdown"><el-dropdown-item command="export">批量导出当前查询结果</el-dropdown-item><el-dropdown-item command="print">批量打印当前查询结果</el-dropdown-item></el-dropdown-menu></el-dropdown>
        </template>
      </div>
    </div>

    <div v-if="kind === 'sales'" class="metric-grid metric-grid--sales">
      <div v-for="card in metrics" :key="card.key" class="metric-card" :class="`metric-card--${card.color}`">
        <i :class="card.icon" />
        <div><span>{{ card.label }}</span><strong>{{ card.value }}</strong></div>
      </div>
    </div>

    <div class="work-grid">
      <main class="main-column">
        <div class="filter-card" :class="`filter-card--${kind}`">
          <template v-if="kind === 'purchase'">
            <label>退货单号<el-input v-model="query.return_no" clearable size="small" placeholder="请输入退货单号" @keyup.enter.native="reload" /></label>
            <label>到货单<el-input v-model="query.receipt_no" clearable size="small" placeholder="请输入到货单号" @keyup.enter.native="reload" /></label>
            <label>采购订单关键字<el-input v-model="query.purchase_keyword" clearable size="small" suffix-icon="el-icon-search" placeholder="采购订单号 / 物料名称 / 物料编码" @keyup.enter.native="reload" /></label>
            <label>供应商<el-select v-model="query.supplier_id" clearable filterable size="small" placeholder="请选择供应商"><el-option v-for="item in suppliers" :key="item.id" :label="item.supplier_name" :value="item.id" /></el-select></label>
            <label>退货范围<el-select v-model="query.return_scope" clearable size="small" placeholder="请选择"><el-option label="入库后退货" value="posted_inventory" /><el-option label="未入库拒收" value="rejected_before_posting" /></el-select></label>
            <label>退货状态<el-select v-model="query.return_status" clearable size="small" placeholder="请选择"><el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" /></el-select></label>
            <label class="date-field">退货日期范围<el-date-picker v-model="dateRange" size="small" type="daterange" value-format="yyyy-MM-dd" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期" /></label>
            <div class="filter-actions"><el-button size="small" @click="reset">重置</el-button><el-button size="small" type="success" icon="el-icon-search" @click="reload">查询</el-button></div>
          </template>
          <template v-else>
            <label>退货单号/销售订单/原始单号<el-input v-model="query.keyword" clearable size="small" placeholder="请输入退货单号/销售订单/原始单号" @keyup.enter.native="reload" /></label>
            <label>客户<el-select v-model="query.customer_id" clearable filterable size="small" placeholder="请选择客户"><el-option v-for="item in customers" :key="item.id" :label="item.customer_name" :value="item.id" /></el-select></label>
            <label>销售人员<el-select v-model="query.sales_user_legacy_id" clearable filterable size="small" placeholder="请选择销售人员"><el-option v-for="item in salesUsers" :key="item.legacy_id" :label="item.nickname || item.username" :value="item.legacy_id" /></el-select></label>
            <label>退货状态<el-select v-model="query.return_status" clearable size="small" placeholder="请选择退货状态"><el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" /></el-select></label>
            <label class="date-field">退货日期<el-date-picker v-model="dateRange" size="small" type="daterange" value-format="yyyy-MM-dd" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期" /></label>
            <div class="filter-actions"><el-button size="small" type="success" @click="reload">查询</el-button><el-button size="small" @click="reset">重置</el-button></div>
          </template>
        </div>

        <div v-if="kind === 'purchase'" class="metric-grid">
          <div v-for="card in metrics" :key="card.key" class="metric-card" :class="`metric-card--${card.color}`">
            <i :class="card.icon" />
            <div><span>{{ card.label }}</span><strong>{{ card.value }}</strong></div>
          </div>
        </div>

        <div class="table-card">
          <el-table v-loading="loading" :data="rows" border size="mini">
            <el-table-column prop="return_no" label="退货单号" width="150" fixed />
            <template v-if="kind === 'purchase'">
              <el-table-column label="来源到货单" width="142"><template slot-scope="{row}">{{ row.receipt && row.receipt.receipt_no || '-' }}</template></el-table-column>
              <el-table-column label="采购订单" width="142"><template slot-scope="{row}">{{ row.receipt && row.receipt.order && row.receipt.order.purchase_order_no || '-' }}</template></el-table-column>
              <el-table-column label="供应商" min-width="165"><template slot-scope="{row}">{{ row.supplier && row.supplier.supplier_name || '-' }}</template></el-table-column>
              <el-table-column label="退货范围" width="145"><template slot-scope="{row}">{{ scopeText(row.return_scope) }}</template></el-table-column>
            </template>
            <template v-else>
              <el-table-column label="销售订单" width="150"><template slot-scope="{row}">{{ row.order && row.order.sales_order_no || '-' }}</template></el-table-column>
              <el-table-column label="平台原始单号" width="140"><template slot-scope="{row}">{{ row.order && row.order.origin_order_no || '-' }}</template></el-table-column>
              <el-table-column label="客户" min-width="170"><template slot-scope="{row}">{{ row.customer_name_snapshot || row.order && row.order.customer_name || '-' }}</template></el-table-column>
            </template>
            <el-table-column label="退货数量" width="95" align="right"><template slot-scope="{row}">{{ totalQty(row) }}</template></el-table-column>
            <el-table-column v-if="kind === 'sales'" label="已收数量" width="95" align="right"><template slot-scope="{row}">{{ receivedQty(row) }}</template></el-table-column>
            <el-table-column v-if="kind === 'sales'" label="可重新入库" width="100" align="right"><template slot-scope="{row}">{{ restockQty(row) }}</template></el-table-column>
            <el-table-column prop="return_reason" label="退货原因" min-width="120" />
            <el-table-column label="状态" width="115"><template slot-scope="{row}"><el-tag size="mini" :type="statusType(row.return_status)">{{ rowStatusText(row) }}</el-tag></template></el-table-column>
            <el-table-column v-if="kind === 'purchase'" label="库存过账" width="92"><template slot-scope="{row}"><el-tag size="mini" :type="row.stock_post_status === 'posted' ? 'success' : 'info'">{{ row.stock_post_status === 'posted' ? '已过账' : row.stock_post_status === 'not_required' ? '无需过账' : '未过账' }}</el-tag></template></el-table-column>
            <el-table-column label="更新时间" width="138"><template slot-scope="{row}">{{ fmt(row.updated_at) }}</template></el-table-column>
            <el-table-column label="操作" class-name="operation-column" :width="kind === 'purchase' ? 178 : 130" fixed="right">
              <template slot-scope="{row}">
                <el-button type="text" size="mini" @click="$router.push(`${basePath}/${row.id}/detail`)">查看</el-button>
                <el-button v-if="row.return_status === 'draft' && $can(kind === 'purchase' ? 'purchase_return.submit' : 'sales_return.confirm')" type="text" size="mini" @click="advance(row)">{{ kind === 'purchase' ? '提交' : '确认' }}</el-button>
                <el-button v-if="kind === 'purchase' && row.return_status === 'pending_outbound' && $can('purchase_return.post')" type="text" size="mini" @click="post(row)">{{ row.return_scope === 'rejected_before_posting' ? '确认退回' : '出库过账' }}</el-button>
                <el-button v-if="kind === 'sales' && ['pending_receipt','partial_received'].includes(row.return_status) && $can('sales_return.receive')" type="text" size="mini" @click="$router.push(`${basePath}/${row.id}/detail?receive=1`)">退货收货</el-button>
              </template>
            </el-table-column>
          </el-table>
          <el-pagination background layout="total, sizes, prev, pager, next, jumper" :current-page.sync="query.page" :page-size.sync="query.per_page" :page-sizes="[10,20,50,100]" :total="total" @current-change="load" @size-change="reload" />
        </div>
      </main>

      <aside class="rule-card">
        <h3><i class="el-icon-reading" /> {{ kind === 'purchase' ? '退货业务说明' : '退货说明' }}</h3>
        <ol><li v-for="(rule,index) in rules" :key="`${index}-${kind === 'sales' ? rule.title : rule}`"><span v-if="kind === 'purchase'">{{ index + 1 }}</span><div><strong v-if="kind === 'sales'">{{ rule.title }}</strong><p>{{ kind === 'sales' ? rule.text : rule }}</p></div></li></ol>
      </aside>
    </div>
  </section>
</template>

<script>
import { listPurchaseReturns, submitPurchaseReturn, postPurchaseReturn } from '@/api/erp/purchase'
import { listSalesReturns, confirmSalesReturn, listSalesCustomers } from '@/api/erp/sales'
import { listEntity } from '@/api/erp/master'
import { listUsers } from '@/api/erp/rbac'

const baseQuery = () => ({ keyword: '', return_no: '', receipt_no: '', purchase_keyword: '', supplier_id: '', customer_id: '', sales_user_legacy_id: '', return_scope: '', return_status: '', page: 1, per_page: 20 })

export default {
  props: { kind: { type: String, required: true } },
  data: () => ({ loading: false, rows: [], total: 0, dateRange: [], suppliers: [], customers: [], salesUsers: [], query: baseQuery() }),
  computed: {
    title() { return this.kind === 'purchase' ? '采购退货' : '销售退货' },
    basePath() { return this.kind === 'purchase' ? '/purchase/returns' : '/sales/returns' },
    createPermission() { return this.kind === 'purchase' ? 'purchase_return.create' : 'sales_return.create' },
    statusOptions() {
      const values = this.kind === 'purchase' ? ['draft','submitted','approved','pending_outbound','completed','cancelled','closed'] : ['draft','pending_receipt','partial_received','received','completed','cancelled','closed']
      return values.map(value => ({ value, label: this.statusText(value) }))
    },
    metrics() {
      const cards = this.kind === 'purchase'
        ? [
            { key: 'draft', label: '草稿', icon: 'el-icon-document', color: 'green' },
            { key: 'submitted', label: '待审核', icon: 'el-icon-time', color: 'orange' },
            { key: 'pending_outbound', label: '待出库', icon: 'el-icon-truck', color: 'blue' },
            { key: 'completed', label: '本月已完成', icon: 'el-icon-finished', color: 'green' }
          ]
        : [
            { key: 'draft', label: '草稿', icon: 'el-icon-document', color: 'blue' },
            { key: 'pending_receipt', label: '待确认', icon: 'el-icon-tickets', color: 'orange' },
            { key: 'received', label: '待收货', icon: 'el-icon-box', color: 'amber' },
            { key: 'partial_received', label: '部分收货', icon: 'el-icon-refresh', color: 'purple' },
            { key: 'completed', label: '本月已完成', icon: 'el-icon-circle-check', color: 'green' }
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
  created() { this.loadOptions(); this.load() },
  methods: {
    async loadOptions() {
      if (this.kind === 'purchase') {
        const { data } = await listEntity('suppliers', { status: 'enabled', page: 1, per_page: 100 })
        this.suppliers = data.data || []
        return
      }
      const [customers, users] = await Promise.all([listSalesCustomers({ page: 1, per_page: 100 }), listUsers({ scope: 'sales', status: 'normal', page: 1, per_page: 100 })])
      this.customers = customers.data.data || customers.data || []
      this.salesUsers = users.data.data || users.data || []
    },
    async load() {
      this.loading = true
      try {
        const params = { ...this.query, date_from: this.dateRange && this.dateRange[0], date_to: this.dateRange && this.dateRange[1] }
        const { data } = await (this.kind === 'purchase' ? listPurchaseReturns(params) : listSalesReturns(params))
        this.rows = data.data || []
        this.total = Number(data.total || 0)
      } finally { this.loading = false }
    },
    reload() { this.query.page = 1; this.load() },
    reset() { this.dateRange = []; this.query = baseQuery(); this.load() },
    totalQty(row) { return (row.items || []).reduce((sum, item) => sum + Number(item.requested_base_qty || item.requested_sales_qty || 0), 0).toFixed(2) },
    receivedQty(row) { return (row.items || []).reduce((sum, item) => sum + Number(item.received_base_qty || 0), 0).toFixed(2) },
    restockQty(row) { return (row.items || []).reduce((sum, item) => sum + Number(item.restock_base_qty || 0), 0).toFixed(2) },
    scopeText(value) { return value === 'rejected_before_posting' ? '到货拒收（未入库）' : '已入库退货' },
    rowStatusText(row) { return row.return_status === 'pending_outbound' && row.return_scope === 'rejected_before_posting' ? '待退回供应商' : this.statusText(row.return_status) },
    statusText(value) { return ({ draft:'草稿', submitted:'待审核', approved:'已审核', pending_outbound:'待执行', pending_receipt:'待收货', partial_received:'部分收货', received:'已收货', completed:'已完成', cancelled:'已取消', closed:'已关闭' })[value] || value || '-' },
    statusType(value) { return ({ completed:'success', approved:'success', received:'success', cancelled:'danger', closed:'info', submitted:'warning', pending_outbound:'warning', pending_receipt:'warning', partial_received:'warning' })[value] || '' },
    fmt(value) { return value ? String(value).replace('T',' ').slice(0,16) : '-' },
    async advance(row) { await this.$confirm(`确认${this.kind === 'purchase' ? '提交审核' : '提交确认'}？`, '操作确认'); await (this.kind === 'purchase' ? submitPurchaseReturn(row.id) : confirmSalesReturn(row.id)); this.$message.success('操作成功'); this.load() },
    async post(row) { const rejected = row.return_scope === 'rejected_before_posting'; await this.$confirm(rejected ? '确认不合格实物已经退回供应商？本操作不产生库存出库。' : '确认按原仓库、库位和批次执行退货出库过账？', rejected ? '确认退回供应商' : '出库过账', { type:'warning' }); await postPurchaseReturn(row.id); this.$message.success(rejected ? '已确认退回供应商' : '退货出库已过账'); this.load() },
    exportCurrent() {
      const header = ['退货单号','销售订单','平台原始单号','客户','退货数量','退货原因','状态','更新时间']
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
.return-page{padding:18px 20px 24px;min-height:calc(100vh - 52px);background:#f7f9fb;color:#172033}.page-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}.page-head h1{margin:0;font-size:23px;line-height:1.2}.page-head .subtitle{margin:6px 0 0;color:#7a8696;font-size:13px}.breadcrumb{margin:0 0 18px;color:#6f7c8f;font-size:13px}.head-actions{display:flex;gap:8px}.work-grid{display:grid;grid-template-columns:minmax(0,1fr) 258px;gap:14px;align-items:stretch}.main-column{min-width:0}.filter-card{background:#fff;border:1px solid #dde4ec;border-radius:5px;padding:16px;display:grid;gap:16px 20px}.filter-card--purchase{grid-template-columns:repeat(4,minmax(0,1fr))}.filter-card--sales{grid-template-columns:1.25fr 1fr 1fr 1fr}.filter-card label{display:flex;flex-direction:column;gap:7px;font-size:12px;font-weight:600;color:#344054}.filter-card label :deep(.el-select),.filter-card label :deep(.el-date-editor){width:100%}.filter-card .date-field{grid-column:span 2}.filter-actions{display:flex;align-items:flex-end;justify-content:flex-end;gap:8px}.metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:14px 0}.metric-grid--sales{grid-template-columns:repeat(5,minmax(0,1fr));margin:0 272px 14px 0}.metric-card{height:78px;padding:0 18px;background:#fff;border:1px solid #dde4ec;border-radius:5px;display:flex;align-items:center;gap:14px}.metric-card i{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:23px}.metric-card div{display:flex;flex-direction:column;gap:4px}.metric-card span{font-size:13px;color:#5d697a}.metric-card strong{font-size:23px;line-height:1}.metric-card--green i{background:#eaf8f1;color:#079650}.metric-card--orange i{background:#fff1e6;color:#ff7a00}.metric-card--blue i{background:#eaf2ff;color:#2478ee}.metric-card--amber i{background:#fff3dc;color:#f1a600}.metric-card--purple i{background:#f2eaff;color:#8047df}.table-card,.rule-card{background:#fff;border:1px solid #dde4ec;border-radius:5px;overflow:hidden}.table-card .el-pagination{padding:17px 12px;text-align:center}.rule-card{grid-column:2;grid-row:1 / span 3;padding:20px 18px}.rule-card h3{margin:0 0 22px;padding-bottom:16px;border-bottom:1px solid #e4e8ee;font-size:17px}.rule-card h3 i{margin-right:7px;color:#099853}.rule-card ol{list-style:none;margin:0;padding:0}.rule-card li{display:flex;gap:12px;margin-bottom:22px;line-height:1.65;font-size:13px}.rule-card li>span{flex:0 0 20px;height:20px;border-radius:50%;background:#12a158;color:white;text-align:center;line-height:20px}.rule-card li strong{display:block;margin-bottom:5px;font-size:13px}.rule-card li p{margin:0;color:#5d697a}.return-page :deep(.el-table th){background:#f7f9fb;color:#344054;font-weight:600}.return-page :deep(.el-button--success){background:#008d48;border-color:#008d48}.return-page :deep(.el-tag--success){background:#e8f7ef;color:#078647;border-color:#c7ead6}.return-page :deep(.el-pagination.is-background .el-pager li:not(.disabled).active){background:#008d48}@media(max-width:1350px){.work-grid{grid-template-columns:minmax(0,1fr) 230px}.metric-grid--sales{margin-right:244px}.filter-card{gap:12px}.return-page{padding:14px}.rule-card{padding:16px 13px}}@media(max-width:1050px){.work-grid{grid-template-columns:1fr}.rule-card{grid-column:auto;grid-row:auto}.metric-grid--sales{margin-right:0}.filter-card--purchase,.filter-card--sales{grid-template-columns:repeat(2,minmax(0,1fr))}.metric-grid,.metric-grid--sales{grid-template-columns:repeat(2,1fr)}}@media print{.head-actions,.filter-card,.rule-card{display:none}.work-grid{display:block}.return-page{padding:0;background:#fff}}
.table-card :deep(.el-table .cell){white-space:normal;word-break:break-word;line-height:1.5}.table-card :deep(.operation-column .cell){white-space:nowrap}
</style>
