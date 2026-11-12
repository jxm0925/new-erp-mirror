<template>
  <div class="dashboard-config-preview-content">
    <p v-if="changed" class="dashboard-caption">保存后按所选条件重新计算。</p>
    <dashboard-metrics v-else-if="definition && definition.metric" :items="metricItems" :columns="1" />
    <dashboard-metrics v-else-if="componentKey === 'overview_followups'" :items="dashboard.reminderCards.map(item => ({...item,drilldown:null}))" :columns="3" />
    <el-table v-else-if="componentKey === 'dimension_cross'" :data="dashboard.crossRows.slice(0,3)" size="mini" border><el-table-column prop="sales_user_name" label="销售人员" min-width="85" /><el-table-column v-for="column in dashboard.crossColumns.slice(0,4)" :key="String(column.value)" :label="column.label" min-width="75" align="right"><template slot-scope="{row}">{{ count(cell(row,column)) }}</template></el-table-column></el-table>
    <dashboard-chart v-else-if="chartRows" :title="definition.title" :rows="chartRows" :series="series" :type="componentKey.includes('funnel') ? 'funnel' : 'bar'" :height="160" />
    <el-table v-else-if="tableRows" :data="tableRows.slice(0,3)" size="mini" border><el-table-column v-for="column in tableColumns" :key="column.prop" :prop="column.prop" :label="column.label" min-width="90" /></el-table>
    <div v-else class="dashboard-empty">当前条件下暂无可预览数据</div>
  </div>
</template>
<script>
import DashboardMetrics from './DashboardMetrics.vue'
import DashboardChart from './DashboardChart.vue'
import {dashboardComponentCatalog} from './dashboardConfiguration'
import {countText,moneyText} from './dashboardFormat'
export default {
  name:'DashboardConfigurationPreview',
  components:{DashboardMetrics,DashboardChart},
  props:{componentKey:String,configuration:Object,dashboard:Object},
  computed:{
    definition(){return dashboardComponentCatalog.find(item=>item.key===this.componentKey)},
    changed(){const keys=new Set([...Object.keys(this.configuration.filters || {}),...Object.keys(this.dashboard.filters || {})]);if([...keys].some(key=>String(this.configuration.filters[key] || '')!==String(this.dashboard.filters[key] || '')))return true;const settings=this.configuration.settings || {};if(this.componentKey.startsWith('dimension_') && settings.dimension!==this.dashboard.dimension)return true;if(this.componentKey==='source_distribution' && settings.source!==this.dashboard.source)return true;return ['sales_funnels','pipeline_funnel','pipeline_stages','pipeline_records'].includes(this.componentKey) && ['cohort_from','cohort_to','observed_on','stage_mode','stalled_days'].some(key=>settings[key]!==this.dashboard.settings[key])},
    metricItems(){const selected=this.configuration.components.find(item=>item.key===this.componentKey);return (this.dashboard.metricCards || []).filter(item=>item.key===this.componentKey).map(item=>({...item,label:selected ? selected.title : item.label,drilldown:null}))},
    chartRows(){return {overview_sales:this.dashboard.overviewSalesRows,overview_stages:this.dashboard.overviewStageRows,overview_industry:this.dashboard.industryRows,overview_purchase:this.dashboard.purchaseRows,dimension_distribution:this.dashboard.dimensionRows,pipeline_stages:this.dashboard.stageRows,pipeline_funnel:this.dashboard.funnelRows,sales_funnels:this.dashboard.salesFunnelRows,source_distribution:this.dashboard.sourceRows,source_followups:this.dashboard.followupSalesRows,income_chart:this.dashboard.incomeSalesRows}[this.componentKey]},
    series(){if(this.componentKey==='overview_sales')return [{key:'deal',label:'成交金额（万）'},{key:'expected',label:'待成交金额（万）'}];if(this.componentKey==='source_followups')return [{key:'due_count',label:'应跟进'},{key:'completed_count',label:'已完成'}];if(this.componentKey==='income_chart')return [{key:'deal',label:'成交金额（万）'},{key:'received',label:'已收金额（万）'},{key:'outstanding',label:'尾款金额（万）'}];return [{key:'count',label:'数量'}]},
    tableRows(){const rows={sales_table:this.dashboard.salesRows,income_table:this.dashboard.incomeRows,income_orders:this.dashboard.orderRows,pipeline_records:this.dashboard.riskRows,source_records:this.dashboard.taskRows,dimension_table:this.dashboard.dimensionRows}[this.componentKey];return rows && rows.map(row=>({...row,preview_name:row.title || row.name || row.customer_name || '—',preview_owner:row.owner_name || row.sales_user_name || '—',preview_deal:row.money_summary ? moneyText(row.money_summary,'deal_amount',this.configuration.filters.currency) : '—'}))},
    tableColumns(){if(this.componentKey==='sales_table')return [{prop:'sales_user_name',label:'销售人员'},{prop:'customer_count',label:'客户数'}];if(this.componentKey==='income_table')return [{prop:'sales_user_name',label:'销售人员'},{prop:'preview_deal',label:'成交金额（万）'}];if(this.componentKey==='dimension_table')return [{prop:'label',label:'维度'},{prop:'customer_count',label:'客户数'}];if(this.componentKey==='income_orders')return [{prop:'sales_order_no',label:'订单编号'},{prop:'customer_name',label:'客户'}];return [{prop:'preview_name',label:this.componentKey==='pipeline_records'?'商机名称':'跟进对象'},{prop:'preview_owner',label:'负责人'}]}
  },
  methods:{count:countText,cell(row,column){const item=(row.cells || []).find(cell=>String(cell.value)===String(column.value));return item?item.customer_count:null}}
}
</script>
