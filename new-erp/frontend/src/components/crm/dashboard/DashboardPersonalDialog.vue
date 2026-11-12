<template>
  <el-dialog class="crm-dialog dashboard-personal" :visible.sync="visible" width="1080px" top="6vh" append-to-body :close-on-click-modal="false" :before-close="close" @closed="invalidate">
    <div slot="title" class="dashboard-personal-head"><div><h2>{{ person.name || '未归属销售' }} · 个人经营</h2><small>{{ scope.date_from }} 至 {{ scope.date_to }}</small></div><label>销售<paged-record-select v-if="visible" :value="person.id" :request="salesRequest" :params="salesParams" :selected-rows="selectedSales" :columns="salesColumns" :label="salesLabel" title="选择销售" placeholder="选择销售" :clearable="false" @select="selectPerson" /></label></div>
    <el-tabs v-model="tab" @tab-click="tabChanged"><el-tab-pane label="个人概览" name="overview" /><el-tab-pane v-if="can('customers')" :label="'客户' + numberSuffix(metrics.customers)" name="customers" /><el-tab-pane v-if="can('opportunities')" :label="'进行中商机' + numberSuffix(metrics.open_opportunities)" name="opportunities" /><el-tab-pane v-if="canFollowups" label="跟进记录" name="followups" /><el-tab-pane v-if="can('orders') && can('amounts')" label="订单金额" name="orders" /></el-tabs>
    <el-alert v-if="summary.error" :title="summary.error" type="error" :closable="false" show-icon />
    <template v-if="tab === 'overview'">
      <div v-loading="summary.loading"><dashboard-metrics :items="cards" :columns="6" @drilldown="drill" /></div>
      <div class="dashboard-grid dashboard-grid-two">
        <section class="dashboard-panel"><div class="dashboard-panel-head"><h3>个人客户行业分布</h3><el-button v-if="can('customers')" type="text" @click="drill({kind:'customers'},'客户')">查看明细<i class="el-icon-arrow-right" /></el-button></div><el-alert v-if="industry.error" :title="industry.error" type="error" :closable="false" /><dashboard-chart v-loading="industry.loading" title="个人客户行业分布" :rows="industryRows" :empty-text="can('customers') ? industry.error || '暂无行业数据' : '无客户查看权限'" @select="row => drill(row.drilldown,row.label)" /><footer v-if="can('customers')" class="crm-pager"><el-pagination background small layout="total, prev, next" :total="total(industry)" :current-page="industry.page" :page-size="10" @current-change="industryPage" /></footer></section>
        <section class="dashboard-panel"><div class="dashboard-panel-head"><h3>个人商机阶段</h3><el-button v-if="can('opportunities')" type="text" @click="drill({kind:'pipeline_active'},'进行中商机')">查看明细<i class="el-icon-arrow-right" /></el-button></div><el-alert v-if="pipeline.error" :title="pipeline.error" type="error" :closable="false" /><dashboard-chart v-loading="pipeline.loading" title="个人当前商机阶段" :rows="stageRows" :empty-text="can('opportunities') ? pipeline.error || '暂无进行中商机' : '无商机查看权限'" @select="row => drill(row.query,row.label)" /></section>
      </div>
      <section class="dashboard-panel"><div class="dashboard-panel-head"><h3>需处理的跟进{{ tasks.data ? ' · ' + total(tasks) + ' 条' : '' }}</h3><el-button v-if="canFollowups" type="text" @click="drill({kind:'contact_overdue'},'逾期跟进')">查看明细<i class="el-icon-arrow-right" /></el-button></div><dashboard-records v-if="canFollowups" v-bind="recordsProps(tasks)" @page="taskPage" @size="taskSize" @view="$emit('view',$event)" @act="$emit('act',$event)" /><div v-else class="dashboard-empty">无跟进查看权限</div></section>
    </template>
    <section v-else class="dashboard-panel"><dashboard-records v-bind="recordsProps(records)" :type="recordType" @page="recordPage" @size="recordSize" @view="$emit('view',$event)" @act="$emit('act',$event)" /></section>
    <span slot="footer"><el-button type="success" @click="expand">展开该销售完整看板</el-button><el-button @click="close">关闭</el-button></span>
  </el-dialog>
</template>
<script>
import DashboardMetrics from './DashboardMetrics.vue'
import DashboardChart from './DashboardChart.vue'
import DashboardRecords from './DashboardRecords.vue'
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { getDashboardOverview, listDashboardDimensions, getDashboardPipeline, listDashboardDrilldown, listDashboardOrders, listDashboardSalesOptions } from '@/api/erp/crmDashboard'
import { crmError } from '@/api/erp/crm'
import { pageState, pageRows, pageTotal, moneyText, amountText } from './dashboardFormat'

export default {
  name: 'DashboardPersonalDialog',
  components: { DashboardMetrics, DashboardChart, DashboardRecords, PagedRecordSelect },
  props: { abilities: { type: Object, default: () => ({}) } },
  data: () => ({ visible: false, tab: 'overview', person: {}, scope: {}, session: 0, summary: pageState(), industry: pageState(), pipeline: pageState(), tasks: pageState(), records: pageState(), salesColumns: [{prop:'name',label:'姓名'}] }),
  computed: {
    metrics() { return ((this.summary.data || {}).metrics || {}) },
    canFollowups() { return this.can('customers') || this.can('leads') || this.can('opportunities') },
    params() { const params = {...this.scope}; delete params.sales_user_legacy_id; delete params.sales_user_unassigned; return {...params,...(this.person.id ? {sales_user_legacy_id:this.person.id} : {sales_user_unassigned:1})} },
    salesParams() { return {department_legacy_id:this.scope.department_legacy_id,group_legacy_id:this.scope.group_legacy_id} },
    selectedSales() { return this.person.id ? [{id:this.person.id,name:this.person.name}] : [] },
    industryRows() { return pageRows(this.industry).map(row => ({...row,count:row.customer_count})) },
    stageRows() { return ((this.pipeline.data || {}).current_stages || []).map(row => ({...row,label:row.name})) },
    recordType() { return this.tab === 'followups' ? 'followups' : this.tab },
    cards() {
      const data = this.summary.data || {}
      const amounts = (data.expected_amounts || []).filter(row => !this.scope.currency || row.currency === this.scope.currency)
      return [
        {key:'customers',label:'客户数',value:this.metrics.customers,icon:'el-icon-user',caption:'当前归属',drilldown:this.can('customers') ? {kind:'customers'} : null},
        {key:'open',label:'进行中商机',value:this.metrics.open_opportunities,icon:'el-icon-copy-document',caption:'当前状态',drilldown:this.can('opportunities') ? {kind:'open_opportunities'} : null},
        {key:'expected',label:'待成交金额',value:this.can('opportunities') && this.summary.data ? amounts.map(row => row.unknown_count ? `${row.currency} 待核实` : amountText(row.amount,row.currency)).join(' / ') || (this.metrics.open_opportunities === 0 ? '0' : '—') : null,icon:'el-icon-coin',caption:'当前进行中商机',drilldown:this.can('opportunities') ? {kind:'open_opportunities'} : null},
        ...[{key:'deal_amount',label:'成交金额',icon:'el-icon-document'},{key:'received_amount',label:'已收金额',icon:'el-icon-coin'},{key:'outstanding_amount',label:'尾款金额',icon:'el-icon-wallet'}].map(item => ({...item,value:data.money_summary ? moneyText(data.money_summary,item.key,this.scope.currency) : null,caption:'同批订单 · 当前金额',drilldown:this.can('orders') && this.can('amounts') ? {kind:'orders',date_from:this.scope.date_from,date_to:this.scope.date_to} : null}))
      ]
    }
  },
  beforeDestroy() { this.invalidate() },
  methods: {
    rows: pageRows,
    total: pageTotal,
    can(key) { return !!this.abilities[key] },
    numberSuffix(value) { return value === null || value === undefined ? '' : ' ' + value },
    salesRequest: listDashboardSalesOptions,
    salesLabel(row) { return row.name },
    recordsProps(state) { return {rows:pageRows(state),loading:state.loading,error:state.error,total:pageTotal(state),page:state.page,perPage:state.per_page,summary:(state.data || {}).summary} },
    open(row,scope) { this.invalidate(); this.scope = {...scope}; this.person = {id:row.sales_user_legacy_id || null,name:row.sales_user_name || '未归属销售'}; this.tab = 'overview'; this.visible = true; this.refresh() },
    invalidate() { this.session += 1; ['summary','industry','pipeline','tasks','records'].forEach(key => { this[key].sequence += 1 }) },
    close() { this.invalidate(); this.visible = false; this.$emit('closed') },
    selectPerson(row) { if (!row) return; this.$emit('invalidate'); this.invalidate(); this.person = {id:row.id,name:row.name}; this.tab = 'overview'; this.refresh() },
    async fetch(key,request,extra = {}) {
      const state = this[key]; const session = this.session; const sequence = ++state.sequence
      state.loading = true; state.error = ''; state.data = null
      try { const {data} = await request({...this.params,page:state.page,per_page:state.per_page,...extra}); if (session === this.session && sequence === state.sequence && this.visible) state.data = data }
      catch (error) { if (session === this.session && sequence === state.sequence && this.visible) state.error = crmError(error,'个人经营数据加载失败') }
      finally { if (session === this.session && sequence === state.sequence) state.loading = false }
    },
    refresh() { if (!this.visible) return; this.summary = pageState(); this.industry = pageState(); this.pipeline = pageState(); this.tasks = pageState(); this.records = pageState(); this.fetch('summary',getDashboardOverview); this.loadTab() },
    tabChanged() { this.$emit('invalidate'); this.records.page = 1; this.loadTab() },
    loadTab() {
      if (!this.visible) return
      if (this.tab === 'overview') {
        if (this.can('customers')) this.fetch('industry',listDashboardDimensions,{dimension:'industry'})
        if (this.can('opportunities') || this.can('leads')) this.fetch('pipeline',getDashboardPipeline)
        if (this.canFollowups) this.fetch('tasks',listDashboardDrilldown,{kind:'contact_overdue'})
      } else if (this.tab === 'orders' && this.can('orders') && this.can('amounts')) this.fetch('records',listDashboardOrders)
      else if (this.tab === 'customers' && this.can('customers')) this.fetch('records',listDashboardDrilldown,{kind:'customers'})
      else if (this.tab === 'opportunities' && this.can('opportunities')) this.fetch('records',listDashboardDrilldown,{kind:'open_opportunities'})
      else if (this.tab === 'followups' && this.canFollowups) this.fetch('records',listDashboardDrilldown,{kind:'followup_records'})
    },
    industryPage(page) { this.industry.page = page; this.fetch('industry',listDashboardDimensions,{dimension:'industry'}) },
    taskPage(page) { this.tasks.page = page; this.fetch('tasks',listDashboardDrilldown,{kind:'contact_overdue'}) },
    taskSize(size) { this.tasks.per_page = size; this.taskPage(1) },
    recordPage(page) { this.records.page = page; this.loadTab() },
    recordSize(size) { this.records.per_page = size; this.recordPage(1) },
    drill(query,title) { if (query) this.$emit('drilldown',{...this.params,...query},`${this.person.name} · ${title}`) },
    expand() { const scope = {...this.params}; this.close(); this.$emit('expand',scope) }
  }
}
</script>
