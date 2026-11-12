<template>
  <div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-table v-loading="loading" :data="rows" size="small" border :show-summary="!!summary" :summary-method="summaries">
      <el-table-column prop="sales_user_name" label="销售" min-width="100"><template slot-scope="{row}"><el-button type="text" @click="$emit('person', row)">{{ row.sales_user_name || '未归属销售' }}</el-button></template></el-table-column>
      <template v-if="!income">
        <el-table-column v-for="item in countColumns" :key="item.key" :prop="item.key" :label="item.label" min-width="100" align="right"><template slot-scope="{row}"><el-button v-if="row[item.key] !== null && row[item.key] !== undefined" type="text" @click="drill(row,item.kind,item.label)">{{ count(row[item.key]) }}</el-button><span v-else>—</span></template></el-table-column>
        <el-table-column prop="expected_amounts" label="待成交金额" min-width="160" align="right"><template slot-scope="{row}"><el-button v-if="row.expected_amounts" type="text" @click="drill(row,'open_opportunities','进行中商机')">{{ expected(row.expected_amounts) }}</el-button><span v-else>—</span></template></el-table-column>
      </template>
      <el-table-column v-for="item in moneyColumns" :key="item.key" :prop="item.key" :label="item.label + '（万）'" min-width="145" align="right"><template slot-scope="{row}"><el-button v-if="row.money_summary" type="text" @click="$emit('orders', salesQuery(row), row.sales_user_name)">{{ money(row.money_summary,item.key,currency) }}</el-button><span v-else>—</span></template></el-table-column>
      <el-table-column v-if="!income" prop="overdue_followup_count" label="逾期跟进" min-width="100" align="right"><template slot-scope="{row}"><el-button v-if="row.overdue_followup_count !== null && row.overdue_followup_count !== undefined" type="text" class="danger-link" @click="drill(row,'contact_overdue','逾期跟进')">{{ count(row.overdue_followup_count) }}</el-button><span v-else>—</span></template></el-table-column>
      <el-table-column label="操作" min-width="140"><template slot-scope="{row}"><el-button type="text" @click="income ? $emit('orders', salesQuery(row), row.sales_user_name) : $emit('person',row)">{{ income ? '查看订单' : '查看个人经营' }}</el-button></template></el-table-column>
      <template slot="empty"><span class="crm-muted">{{ error || (loading ? '正在加载' : '当前条件下暂无销售数据') }}</span></template>
    </el-table>
    <footer class="crm-pager"><el-pagination background layout="total, sizes, prev, pager, next" :total="total" :current-page="page" :page-size="perPage" :page-sizes="[10,20,50]" @current-change="$emit('page',$event)" @size-change="$emit('size',$event)" /></footer>
  </div>
</template>
<script>
import { countText, moneyText, amountText } from './dashboardFormat'
export default {
  name: 'DashboardSalesTable',
  props: { rows: { type: Array, default: () => [] }, loading: Boolean, error: String, total: { type: Number, default: 0 }, page: { type: Number, default: 1 }, perPage: { type: Number, default: 10 }, currency: String, income: Boolean, summary: Object },
  data: () => ({ countColumns: [{key:'customer_count',label:'客户数',kind:'customers'},{key:'new_customer_count',label:'本期新增',kind:'new_customers'},{key:'open_opportunity_count',label:'进行中商机',kind:'open_opportunities'}], moneyColumns: [{key:'deal_amount',label:'成交金额'},{key:'received_amount',label:'已收金额'},{key:'outstanding_amount',label:'尾款金额'}] }),
  methods: {
    count: countText,
    money: moneyText,
    expected(rows) { return rows === null || rows === undefined ? '—' : rows.length === 0 ? '0' : rows.filter(row => !this.currency || row.currency === this.currency).map(row => row.unknown_count ? `${row.currency} 待核实` : amountText(row.amount,row.currency)).join(' / ') || '—' },
    salesQuery(row) { return row.sales_user_legacy_id ? { sales_user_legacy_id: row.sales_user_legacy_id } : { sales_user_unassigned: 1 } },
    drill(row,kind,label) { this.$emit('drilldown',{kind,...this.salesQuery(row)},`${row.sales_user_name || '未归属销售'} · ${label}`) },
    summaries({columns}) {
      return columns.map((column,index) => {
        if (index === 0) return '合计'
        const key = column.property; const metric = this.countColumns.find(item => item.key === key)
        const link = (text,kind,label) => this.$createElement('el-button',{props:{type:'text'},on:{click:() => this.$emit('drilldown',{kind},label)}},[text])
        if (metric) return this.summary[key] === null || this.summary[key] === undefined ? '—' : link(countText(this.summary[key]),metric.kind,metric.label)
        if (key === 'expected_amounts') return this.summary.expected_amounts ? link(this.expected(this.summary.expected_amounts),'open_opportunities','进行中商机') : '—'
        if (this.moneyColumns.some(item => item.key === key)) return this.summary.money_summary ? link(moneyText(this.summary.money_summary,key,this.currency),'orders','成交订单') : '—'
        if (key === 'overdue_followup_count') return this.summary[key] === null || this.summary[key] === undefined ? '—' : link(countText(this.summary[key]),'contact_overdue','逾期跟进')
        return ''
      })
    }
  }
}
</script>
