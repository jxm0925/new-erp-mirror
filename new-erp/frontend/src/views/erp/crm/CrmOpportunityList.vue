<template>
  <div class="crm-page">
    <header class="crm-head"><span class="crm-head-icon"><i class="el-icon-aim" /></span><h1>商机中心</h1><div class="crm-actions"><span class="crm-muted">{{ (metadata.current_user || {}).name }} / {{ (metadata.current_user || {}).department_name }}</span><crm-list-view-controls ref="views" resource="opportunities" title="商机" :configuration="viewConfiguration" :filters="viewFilters" :column-groups="columnGroups" :filter-options="viewFilterOptions" @apply="applyView" /><el-button v-if="abilities.create" type="success" size="small" icon="el-icon-plus" @click="$refs.form.open()">新建商机</el-button></div></header>
    <el-tabs v-model="query.tab" @tab-click="search"><el-tab-pane v-for="item in tabs" :key="item.value" :name="item.value" :label="item.label + ' ' + (counts[item.value] || 0)" /></el-tabs>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <div class="crm-filters"><el-input v-model.trim="query.keyword" size="small" clearable placeholder="客户 / 商机名称" @keyup.enter.native="search" /><label>业务线<el-select v-model="query.business_line" clearable size="small" placeholder="全部" popper-class="crm-select-popper"><el-option v-for="item in metadata.business_lines || []" :key="item" :label="item" :value="item" /></el-select></label><label>预计成交日期<el-date-picker v-model="dateRange" type="daterange" value-format="yyyy-MM-dd" size="small" start-placeholder="开始日期" end-placeholder="结束日期" popper-class="crm-date-popper" /></label><el-button size="small" type="success" @click="search">查询</el-button><el-button size="small" @click="reset">重置</el-button></div>
    <section class="crm-box"><div class="crm-inline"><b>阶段筛选</b><span class="crm-muted">进行中 {{ openCount }} 项<span v-for="item in expectedAmounts" :key="item.currency"> · 预计金额 {{ money(item.amount,item.currency) }}</span></span></div><div class="crm-opportunity-stages"><button v-for="(label,key) in metadata.stages || {}" :key="key" type="button" class="crm-opportunity-stage" :class="{current:query.stage === key}" @click="selectStage(key)">{{ label }} <b>{{ stageCounts[key] || 0 }}</b></button></div></section>
    <section class="crm-card">
      <el-table :key="viewTableKey" v-loading="loading" :span-method="viewGroupSpan" :data="rows" size="small">
        <el-table-column v-if="viewConfiguration.group_by" prop="__view_group" :label="groupTitle" min-width="130"><template slot-scope="{row}">{{ groupValue(row) }}</template></el-table-column>
        <el-table-column v-for="column in viewColumns" :key="column.key" :label="column.label" :min-width="column.key === 'name' || column.key === 'expected_amount' ? 155 : 125" :align="column.key === 'expected_amount' ? 'right' : 'left'">
          <template slot-scope="{row}">
            <template v-if="column.key === 'customer_name'"><el-button v-if="!$can || $can('sales.customer')" type="text" size="mini" @click="$refs.customer.open(row.customer_id)">{{ row.customer_name }}</el-button><span v-else>{{ row.customer_name }}</span></template>
            <el-tag v-else-if="column.key === 'stage'" :type="row.status === 'lost' ? 'danger' : row.status === 'won' ? 'success' : 'info'" size="mini">{{ row.status === 'open' ? row.stage_label : row.status_label }}</el-tag>
            <template v-else-if="column.key === 'expected_amount'">{{ money(row.expected_amount,row.currency) }}</template>
            <template v-else-if="column.key === 'expected_close_date'">{{ shortDate(row.expected_close_date) }}</template>
            <template v-else-if="column.key === 'last_contact_at' || column.key === 'next_contact_at'">{{ date(row[column.key]) }}</template>
            <template v-else>{{ row[column.key] || '—' }}</template>
          </template>
        </el-table-column>
        <el-table-column label="操作" min-width="115"><template slot-scope="{row}"><div class="crm-inline"><el-button type="text" size="mini" @click="$refs.detail.open(row.id)">查看</el-button><el-button v-if="(row.abilities || {}).follow" type="text" size="mini" @click="follow(row)">跟进</el-button></div></template></el-table-column>
      </el-table>
      <footer class="crm-pager"><el-pagination background layout="total, sizes, prev, pager, next, jumper" :total="total" :current-page.sync="query.page" :page-size.sync="query.per_page" :page-sizes="[10,20,50]" @size-change="search" @current-change="load" /></footer>
    </section>
    <crm-opportunity-form-dialog ref="form" @saved="saved" /><crm-opportunity-detail-dialog ref="detail" @changed="load" /><crm-followup-dialog ref="follow" @saved="load" /><customer-detail-dialog ref="customer" />
  </div>
</template>
<script>
import { getCrmOpportunityMetadata, listCrmOpportunities, crmDate, crmError } from '@/api/erp/crm'
import { erpDateTime } from '@/utils/dateTime'
import CrmOpportunityFormDialog from '@/components/crm/CrmOpportunityFormDialog.vue'
import CrmOpportunityDetailDialog from '@/components/crm/CrmOpportunityDetailDialog.vue'
import CrmFollowupDialog from '@/components/crm/CrmFollowupDialog.vue'
import CrmListViewControls from '@/components/crm/CrmListViewControls.vue'
import { opportunityColumnGroups, orderedColumnGroups, listViewQuery, listViewGroupSpan } from '@/components/crm/listViewColumns'
import '@/styles/crm.css'
const blank = (perPage = 10, tab = 'mine') => ({tab,keyword:'',business_line:'',stage:'',page:1,per_page:perPage})
export default {
  name:'CrmOpportunityList',
  components:{CrmListViewControls,CrmOpportunityFormDialog,CrmOpportunityDetailDialog,CrmFollowupDialog,CustomerDetailDialog:() => import('@/components/sales/CustomerDetailDialog.vue')},
  data:() => ({columnGroups:opportunityColumnGroups,viewConfiguration:{columns:opportunityColumnGroups.flatMap(group => group.fields),filters:{},sort:{field:'',direction:'desc'},group_by:''},metadata:{},query:blank(),dateRange:[],rows:[],total:0,counts:{},stageCounts:{},expectedAmounts:[],loading:false,error:'',sequence:0}),
  computed:{
    viewColumns() { return orderedColumnGroups(this.viewConfiguration.columns,this.columnGroups) },
    viewTableKey() { return this.viewConfiguration.columns.join(',') + ':' + this.viewConfiguration.group_by },
    viewFilters() { return { ...this.query, expected_close_from:(this.dateRange || [])[0] || '', expected_close_to:(this.dateRange || [])[1] || '' } },
    groupTitle() { const field = ((this.$refs.views && this.$refs.views.metadata.group_fields) || []).find(field => field.key === this.viewConfiguration.group_by); return field ? field.label : '分组' },
    viewFilterOptions() { return { tab: Object.fromEntries(this.tabs.map(item => [item.value,item.label])), stage: this.metadata.stages || {} } },
    abilities() { return this.metadata.abilities || {} },
    tabs() { return [{value:'mine',label:'我负责的'},{value:'collaborating',label:'我协作的'},{value:'won',label:'已成交'},{value:'lost',label:'已丢单'}] },
    openCount() { return Object.values(this.stageCounts).reduce((sum,value) => sum + Number(value || 0),0) }
  },
  async created() {
    try {
      const {data} = await getCrmOpportunityMetadata()
      this.metadata = data.data || data
      await this.$nextTick()
      const configuration = await this.$refs.views.initialize()
      if (configuration) this.applyView(configuration, false)
      await this.load()
    } catch(error) { this.error = crmError(error,'商机中心加载失败') }
  },
  beforeDestroy() { this.sequence += 1 },
  methods:{
    viewGroupSpan: listViewGroupSpan,
    applyView(configuration, reload = true) {
      this.viewConfiguration = configuration
      const filters = { ...configuration.filters }
      this.dateRange = filters.expected_close_from || filters.expected_close_to ? [filters.expected_close_from || '',filters.expected_close_to || ''] : []
      delete filters.expected_close_from
      delete filters.expected_close_to
      this.query = { ...blank(this.query.per_page), ...filters, page: 1 }
      if (!this.tabs.some(item => item.value === this.query.tab)) this.query.tab = 'mine'
      if (reload) this.load()
    },
    groupValue(row) { return this.viewConfiguration.group_by === 'stage' ? row.stage_label : this.viewConfiguration.group_by === 'status' ? row.status_label : (row[this.viewConfiguration.group_by] || '未填写') },
    date:crmDate,
    shortDate(value) { return erpDateTime(value,10) || '—' },
    money(value,currency) { return value === null || value === undefined || value === '' ? '—' : `${currency || 'CNY'} ${Number(value).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2})}` },
    async load() {
      const sequence = ++this.sequence
      this.loading = true
      this.error = ''
      try {
        const {data} = await listCrmOpportunities({...this.query,...listViewQuery(this.viewConfiguration),expected_close_from:(this.dateRange || [])[0],expected_close_to:(this.dateRange || [])[1]})
        if (sequence !== this.sequence) return
        this.rows = data.data || []
        this.total = data.total || 0
        this.counts = data.tabs || {}
        this.stageCounts = data.stage_counts || {}
        const amounts = data.expected_amounts || {}
        this.expectedAmounts = Array.isArray(amounts) ? amounts : Object.entries(amounts).map(([currency,amount]) => ({currency,amount}))
      } catch (error) {
        if (sequence !== this.sequence) return
        this.rows = []
        this.total = 0
        this.counts = {}
        this.stageCounts = {}
        this.expectedAmounts = []
        this.error = crmError(error,'商机列表加载失败')
      } finally {
        if (sequence === this.sequence) this.loading = false
      }
    },
    search() { this.query.page = 1; this.load() },
    reset() { this.query = blank(this.query.per_page,this.query.tab); this.dateRange = []; this.load() },
    selectStage(key) { this.query.stage = this.query.stage === key ? '' : key; this.search() },
    follow(row) { this.$refs.follow.open({subject_type:'opportunity',subject_id:row.id,customer_id:row.customer_id,customer_name:row.customer_name,opportunity_name:row.name,contact_name:row.contact_name}) },
    saved(row) { this.load(); if(row && row.id) this.$refs.detail.open(row.id) }
  }
}
</script>
