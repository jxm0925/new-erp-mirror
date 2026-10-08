<template>
  <div class="performance-page">
    <header class="performance-head"><div><p>生产管理 / 统计</p><h1>个人绩效统计</h1><span>整单商品全部发出后，按实际生产和包装来源计算个人绩效。</span></div><el-button icon="el-icon-refresh" size="small" @click="load">刷新</el-button></header>
    <div class="performance-policy">计算口径：折后商品含税金额，不含运费和另收包装费。工序比例和个人份额按登记值计算。</div>
    <el-tabs v-model="tab" @tab-click="switchTab"><el-tab-pane label="订单统计" name="orders" /><el-tab-pane label="我的工序份额" name="operations" /></el-tabs>
    <section class="performance-card">
      <div class="performance-filters"><el-input v-model="keyword" clearable :placeholder="tab === 'orders' ? '订单号、客户名称' : '工序名称'" size="small" @keyup.enter.native="search" /><el-date-picker v-if="tab === 'orders'" v-model="dates" type="daterange" value-format="yyyy-MM-dd" size="small" start-placeholder="发货开始日期" end-placeholder="发货结束日期" /><el-button type="success" size="small" @click="search">查询</el-button></div>
      <el-table v-if="tab === 'orders'" v-loading="loading" :data="rows" border stripe>
        <el-table-column prop="sales_order_no" label="销售订单" min-width="165" /><el-table-column prop="customer_name" label="客户" min-width="160" />
        <el-table-column label="商品发货进度" min-width="160"><template slot-scope="s">{{ s.row.readiness.fully_shipped_lines }} / {{ s.row.readiness.goods_lines_total }} 项全部发出</template></el-table-column>
        <el-table-column label="统计条件" width="155"><template slot-scope="s"><el-tag size="small" :type="s.row.readiness.entire_order_shipped ? 'success' : 'info'">{{ s.row.readiness.entire_order_shipped ? '可核对统计资料' : '等待整单发完' }}</el-tag></template></el-table-column>
        <el-table-column prop="last_shipped_at" label="最近发货时间" width="170" /><el-table-column label="操作" width="90"><template slot-scope="s"><el-button type="text" @click="openOrder(s.row.id)">查看统计</el-button></template></el-table-column>
      </el-table>
      <el-table v-else v-loading="loading" :data="rows" border stripe>
        <el-table-column label="工序" min-width="165"><template slot-scope="s">{{ s.row.scope.operation_name || s.row.scope.operation_code }}</template></el-table-column>
        <el-table-column label="来源" min-width="160"><template slot-scope="s">{{ s.row.scope.work_order_no || ('发货单 #' + s.row.scope.shipment_id) }}</template></el-table-column>
        <el-table-column label="工序类别" width="105"><template slot-scope="s">{{ scopeType(s.row.scope.scope_type) }}</template></el-table-column>
        <el-table-column label="计个人绩效份额" width="160"><template slot-scope="s">{{ s.row.assignment_version ? percent(s.row.credited_share_ratio) : '尚未确认' }}</template></el-table-column>
        <el-table-column label="份额版本" width="105"><template slot-scope="s">{{ s.row.assignment_version ? 'V' + s.row.assignment_version : '尚未确认' }}</template></el-table-column>
        <el-table-column prop="scope.completed_at" label="完成时间" width="170" /><el-table-column label="操作" width="120"><template slot-scope="s"><el-button v-if="s.row.can_confirm && $can('production.performance.manage')" type="text" @click="openShares(s.row.scope)">确认个人份额</el-button><span v-else>仅可查看</span></template></el-table-column>
      </el-table>
      <div class="performance-pagination"><span>共 {{ total }} 条</span><el-pagination background layout="prev, pager, next" :current-page="page" :page-size="20" :total="total" @current-change="v=>{page=v;load()}" /></div>
    </section>
    <el-dialog title="订单个人绩效统计" :visible.sync="detailVisible" width="1180px" append-to-body custom-class="performance-dialog" :close-on-click-modal="false" @closed="detailSequence++">
      <div v-loading="detailLoading" v-if="detail">
        <div class="performance-scope-meta"><strong>{{ detail.order.sales_order_no }}</strong><span>{{ detail.order.customer_name }}</span><el-tag :type="detail.statistics_complete ? 'success' : 'warning'">{{ statusText(detail.statistics_status) }}</el-tag></div>
        <el-alert v-if="!detail.statistics_complete" :title="detail.readiness.entire_order_shipped ? '来源资料或个人份额尚未核对完整，订单合计暂不显示。' : '整单商品尚未全部发出，本订单暂不计算绩效金额。'" type="warning" :closable="false" show-icon />
        <div class="performance-totals"><div><span>工序绩效合计</span><strong>{{ money(detail.performance_pool_amount) }}</strong></div><div><span>个人绩效合计</span><strong>{{ money(detail.personal_performance_amount) }}</strong></div><div><span>不计个人绩效</span><strong>{{ money(detail.noncredited_amount) }}</strong></div></div>
        <el-tabs v-model="detailTab"><el-tab-pane label="工序来源" name="sources"><el-table :data="detail.rows.data" border size="small">
          <el-table-column label="商品" min-width="140"><template slot-scope="s">{{ s.row.product_name || s.row.item_name || s.row.sku_name || ('商品行 #' + s.row.sales_order_line_id) }}</template></el-table-column>
          <el-table-column label="工序及来源" min-width="175"><template slot-scope="s"><strong>{{ s.row.scope.operation_name }}</strong><small>{{ s.row.scope.work_order_no || ('发货单 #' + s.row.scope.shipment_id) }}</small><small>{{ sourceText(s.row) }}</small></template></el-table-column>
          <el-table-column label="工序比例" width="95"><template slot-scope="s">{{ percent(s.row.scope.performance_rate_snapshot) }}</template></el-table-column>
          <el-table-column label="对应商品金额" width="140"><template slot-scope="s">{{ money(s.row.basis_amount) }}</template></el-table-column>
          <el-table-column label="个人绩效金额" width="140"><template slot-scope="s">{{ money(s.row.personal_performance_amount) }}</template></el-table-column>
          <el-table-column label="个人份额" min-width="180"><template slot-scope="s"><div v-if="s.row.assignment"><small v-for="share in s.row.assignment.shares" :key="share.employee_legacy_id">{{ share.employee_name }}：{{ percent(share.share_ratio) }} {{ share.eligible ? '' : '（不计个人绩效）' }}</small></div><span v-else>尚未确认</span></template></el-table-column>
          <el-table-column label="资料状态" width="120"><template slot-scope="s">{{ statusText(s.row.statistics_status) }}</template></el-table-column>
          <el-table-column label="操作" width="110"><template slot-scope="s"><el-button v-if="s.row.can_confirm" type="text" @click="openShares(s.row.scope)">确认个人份额</el-button></template></el-table-column>
        </el-table><div class="performance-pagination"><span>共 {{ detail.rows.meta.total }} 条来源</span><el-pagination background layout="prev, pager, next" :current-page="detailPages.page" :page-size="20" :total="detail.rows.meta.total" @current-change="v=>detailPage('page',v)" /></div></el-tab-pane>
          <el-tab-pane label="员工统计" name="employees"><p v-if="!detail.statistics_complete" class="performance-help">以下为已核对工序的明细；整单资料补齐后形成完整统计。</p><el-table :data="detail.employees.data" border size="small"><el-table-column prop="employee_name" label="员工" min-width="180" /><el-table-column prop="operations_count" label="已核对工序数" width="160" /><el-table-column label="个人绩效金额" min-width="180"><template slot-scope="s">{{ money(s.row.performance_amount) }}</template></el-table-column></el-table><div class="performance-pagination"><span>共 {{ detail.employees.meta.total }} 人</span><el-pagination background layout="prev, pager, next" :current-page="detailPages.employees_page" :page-size="20" :total="detail.employees.meta.total" @current-change="v=>detailPage('employees_page',v)" /></div></el-tab-pane>
          <el-tab-pane :label="'待核对（' + detail.issues.meta.total + '）'" name="issues"><el-table :data="detail.issues.data" border size="small"><el-table-column prop="message" label="待核对内容" min-width="360" /><el-table-column label="来源" min-width="130"><template slot-scope="s">{{ s.row.scope_id ? scopeType(s.row.scope_type) + ' #' + s.row.scope_id : ('商品行 #' + (s.row.sales_order_line_id || '—')) }}</template></el-table-column></el-table><div class="performance-pagination"><span>共 {{ detail.issues.meta.total }} 项</span><el-pagination background layout="prev, pager, next" :current-page="detailPages.issues_page" :page-size="20" :total="detail.issues.meta.total" @current-change="v=>detailPage('issues_page',v)" /></div></el-tab-pane>
        </el-tabs>
      </div><span slot="footer"><el-button @click="detailVisible=false">关闭</el-button><el-button type="success" :loading="detailLoading" @click="loadDetail">重新核对统计</el-button></span>
    </el-dialog>
    <production-performance-shares ref="shares" @confirmed="afterConfirmation" />
  </div>
</template>

<script>
import { listPerformanceOrders, listPerformanceOperations, getPerformanceOrder } from '../../../api/erp/production-performance'
import { ratioToPercent } from '../../../utils/production-performance.mjs'
import ProductionPerformanceShares from './ProductionPerformanceShares.vue'
export default {
  components: { ProductionPerformanceShares },
  data: () => ({ tab: 'orders', keyword: '', dates: [], rows: [], total: 0, page: 1, loading: false, sequence: 0, detailVisible: false, detailLoading: false, detailSequence: 0, detailId: 0, detail: null, detailTab: 'sources', detailPages: { page: 1, employees_page: 1, issues_page: 1 } }),
  created() { this.load() },
  methods: {
    percent(value) { const text = ratioToPercent(value); return text === '' ? '尚未登记' : text + '%' },
    money(value) { return value === null || value === undefined ? '—' : Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 4, maximumFractionDigits: 4 }) },
    scopeType(value) { return { unit_operation: '单件工序', quantity_operation: '批量工序', shipment_packing_operation: '发货包装' }[value] || '工序' },
    statusText(value) { return { ready: '已核对完整', pending_facts: '资料待核对', waiting_shipment: '等待整单发完', pending_shares: '个人份额待确认', pending_rate: '工序比例待核对', pending_completion: '工序未完成', pending_amount: '商品金额待核对', pending_trace: '来源数量待核对' }[value] || '资料待核对' },
    sourceText(row) { const facts = row.source_facts || []; return facts.map(f => f.packing_content_id ? '包装内容 #' + f.packing_content_id + '，数量 ' + f.base_qty : '产出 #' + f.output_record_id + '，本次使用 ' + f.allocated_base_qty).join('；') },
    search() { this.page = 1; this.load() },
    switchTab() { this.page = 1; this.keyword = ''; this.rows = []; this.load() },
    async load() {
      if (!this.$can('production.performance.view')) return
      const sequence = ++this.sequence; this.loading = true
      try {
        const params = { page: this.page, per_page: 20, keyword: this.keyword.trim() || undefined }
        if (this.tab === 'orders' && this.dates?.length === 2) { params.shipped_from = this.dates[0]; params.shipped_to = this.dates[1] }
        const { data } = await (this.tab === 'orders' ? listPerformanceOrders(params) : listPerformanceOperations(params))
        if (sequence !== this.sequence) return
        this.rows = data.data; this.total = data.meta.total
      } catch (error) { if (sequence === this.sequence) this.$message.error(error.userMessage || error.message) }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    openOrder(id) { this.detailId = id; this.detail = null; this.detailTab = 'sources'; this.detailPages = { page: 1, employees_page: 1, issues_page: 1 }; this.detailVisible = true; this.loadDetail() },
    detailPage(key, value) { this.$set(this.detailPages, key, value); this.loadDetail() },
    async loadDetail() {
      if (!this.detailId || !this.detailVisible) return
      const sequence = ++this.detailSequence; this.detailLoading = true
      try { const { data } = await getPerformanceOrder(this.detailId, { ...this.detailPages, per_page: 20 }); if (sequence === this.detailSequence && this.detailVisible) this.detail = data.data }
      catch (error) { if (sequence === this.detailSequence) this.$message.error(error.userMessage || error.message) }
      finally { if (sequence === this.detailSequence) this.detailLoading = false }
    },
    openShares(scope) { this.$refs.shares.open(scope.scope_type, scope.scope_id) },
    afterConfirmation() { this.load(); if (this.detailVisible) this.loadDetail() }
  }
}
</script>

<style>
.performance-page{padding:26px 30px;color:#24364b;max-width:1660px;margin:auto}.performance-head{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:22px}.performance-head p{font-size:12px;color:#7c8998;margin:0 0 7px}.performance-head h1{font-size:25px;margin:0 0 9px}.performance-head span,.performance-help{font-size:13px;color:#748293;line-height:1.6}.performance-policy{background:#eef8f2;border:1px solid #d5ebde;color:#38624b;font-size:13px;padding:12px 16px;margin-bottom:20px;border-radius:5px}.performance-page .el-tabs__active-bar{background:#3b8a63}.performance-page .el-tabs__item.is-active{color:#3b8a63}.performance-card{background:#fff;border:1px solid #e5ebee;border-radius:5px;padding:18px}.performance-filters{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}.performance-filters>.el-input{width:250px}.performance-pagination{display:flex;align-items:center;justify-content:space-between;gap:16px;padding-top:16px;color:#7c8998;font-size:12px}.performance-dialog{top:50%;margin:0 auto!important;transform:translateY(-50%);max-width:calc(100vw - 36px);max-height:90vh;display:flex;flex-direction:column;border-radius:6px}.performance-dialog .el-dialog__header{padding:19px 24px 16px;border-bottom:1px solid #e7edef;flex-shrink:0}.performance-dialog .el-dialog__title{font-weight:600;color:#263b4f}.performance-dialog .el-dialog__body{padding:20px 24px;min-height:0;overflow:auto}.performance-dialog .el-dialog__footer{padding:14px 24px 18px;border-top:1px solid #e7edef;flex-shrink:0}.performance-scope-meta{display:flex;flex-wrap:wrap;align-items:center;gap:12px;font-size:13px;margin-bottom:16px}.performance-scope-meta>span{color:#718292}.performance-dialog small{display:block;color:#748293;line-height:1.7}.performance-dialog .el-tag{margin-left:5px}.performance-dialog .performance-error{color:#bd544f}.performance-dialog .el-button--text{color:#3b8a63}.performance-totals{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:18px 0}.performance-totals>div{padding:16px 18px;border:1px solid #e1eae5;background:#f8fbf9;border-radius:4px}.performance-totals span{display:block;color:#6d7c88;font-size:12px;margin-bottom:8px}.performance-totals strong{font-size:21px;color:#315743}.performance-share-total{display:flex;flex-wrap:wrap;gap:20px;margin:18px 0;font-size:13px}.performance-share-total strong{color:#426851}.performance-reason{margin-top:12px}.performance-page .el-table td,.performance-dialog .el-table td{vertical-align:top}.performance-page .el-table th,.performance-dialog .el-table th{background:#f5f8f7;color:#607367}.performance-page .el-table .cell,.performance-dialog .el-table .cell{word-break:break-word}.performance-page .el-button--text{color:#3b8a63}@media(max-width:720px){.performance-page{padding:16px 12px}.performance-head{align-items:flex-start}.performance-head h1{font-size:22px}.performance-card{padding:12px}.performance-filters>.el-input{width:100%}.performance-filters .el-date-editor{max-width:100%}.performance-totals{grid-template-columns:1fr}.performance-dialog{max-width:calc(100vw - 18px);max-height:94vh}.performance-dialog .el-dialog__body{padding:14px}.performance-dialog .el-dialog__header,.performance-dialog .el-dialog__footer{padding:15px}.performance-pagination{flex-wrap:wrap}}
</style>
