<template>
  <div class="dashboard-records">
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-table v-loading="loading" :data="rows" size="small" border :show-summary="type === 'orders' && !!summary" :summary-method="summaries">
      <template v-if="type === 'orders'">
        <el-table-column label="订单号" min-width="170"><template slot-scope="{row}"><el-button v-if="canView(row)" type="text" @click="$emit('view', orderTarget(row))">{{ row.sales_order_no || row.order_no || row.code || '—' }}</el-button><span v-else>{{ row.sales_order_no || row.order_no || row.code || '—' }}</span></template></el-table-column>
        <el-table-column prop="customer_name" label="客户" min-width="150" />
        <el-table-column label="销售" min-width="100"><template slot-scope="{row}">{{ row.sales_user_name || row.sales_name || '—' }}</template></el-table-column>
        <el-table-column v-for="item in moneyColumns" :key="item.key" :prop="item.key" :label="item.label" min-width="145" align="right"><template slot-scope="{row}">{{ amount(row[item.key], row.currency) }}</template></el-table-column>
        <el-table-column label="金额状态" min-width="130"><template slot-scope="{row}"><el-tag v-if="row.needs_verification || row.verification_required || row.requires_verification" size="mini" type="warning">待核实</el-tag><span v-else>{{ row.amount_status_label || '已核实' }}</span></template></el-table-column>
      </template>
      <template v-else>
        <el-table-column :label="type === 'opportunities' ? '商机 / 客户' : type === 'customers' ? '客户' : '客户 / 事项'" min-width="220"><template slot-scope="{row}"><el-button v-if="canView(row)" type="text" @click="$emit('view', row)">{{ row.title || row.name || row.customer_name || row.customer_code || '—' }}</el-button><span v-else>{{ row.title || row.name || row.customer_name || '—' }}</span><small v-if="row.title && row.customer_name && row.title !== row.customer_name" class="crm-sub">{{ row.customer_name }}</small></template></el-table-column>
        <el-table-column label="销售" min-width="100"><template slot-scope="{row}">{{ row.owner_name || row.sales_user_name || '—' }}</template></el-table-column>
        <template v-if="type === 'customers'">
          <el-table-column prop="customer_code" label="客户编码" min-width="120" />
          <el-table-column label="行业" min-width="120"><template slot-scope="{row}">{{ row.industry || row.industry_name || '未填写' }}</template></el-table-column>
          <el-table-column label="地区" min-width="140"><template slot-scope="{row}">{{ row.region || [row.province,row.city,row.district].filter(Boolean).join(' / ') || '未填写' }}</template></el-table-column>
          <el-table-column label="最近联系" min-width="150"><template slot-scope="{row}">{{ date(row.last_contact_at) }}</template></el-table-column>
        </template>
        <template v-else-if="type === 'opportunities'">
          <el-table-column label="当前阶段" min-width="140"><template slot-scope="{row}">{{ row.stage_label || row.stage_name || '—' }}</template></el-table-column>
          <el-table-column label="预计金额" min-width="150" align="right"><template slot-scope="{row}">{{ amount(row.expected_amount,row.currency) }}</template></el-table-column>
          <el-table-column label="阶段停留" min-width="100"><template slot-scope="{row}">{{ row.stage_days === null || row.stage_days === undefined ? '待核实' : row.stage_days + '天' }}</template></el-table-column>
          <el-table-column label="最近联系" min-width="150"><template slot-scope="{row}">{{ date(row.last_contact_at) }}</template></el-table-column>
          <el-table-column label="预计成交日期" min-width="130"><template slot-scope="{row}">{{ row.expected_close_date ? date(row.expected_close_date).slice(0,10) : '未填写' }}</template></el-table-column>
          <el-table-column label="下次跟进计划" min-width="170"><template slot-scope="{row}">{{ date(row.next_contact_at) }}<small v-if="row.next_plan" class="crm-sub">{{ row.next_plan }}</small></template></el-table-column>
          <el-table-column label="逾期天数" min-width="100"><template slot-scope="{row}"><el-tag v-if="row.overdue_label" size="mini" type="danger">{{ row.overdue_label }}</el-tag><span v-else>—</span></template></el-table-column>
        </template>
        <template v-else-if="type === 'followups'">
          <el-table-column label="实际联系时间" min-width="155"><template slot-scope="{row}">{{ date(row.contacted_at || row.completed_at) }}</template></el-table-column>
          <el-table-column label="实际联系人" min-width="105"><template slot-scope="{row}">{{ row.operator_name || row.completed_by_name || '—' }}</template></el-table-column>
          <el-table-column prop="method" label="方式" min-width="85" />
          <el-table-column prop="content" label="跟进内容" min-width="230" />
          <el-table-column prop="result" label="沟通结果" min-width="120" />
        </template>
        <template v-else>
          <el-table-column label="原计划时间" min-width="155"><template slot-scope="{row}">{{ date(row.planned_at || row.next_contact_at) }}</template></el-table-column>
          <el-table-column label="逾期 / 状态" min-width="110"><template slot-scope="{row}"><el-tag v-if="row.overdue_label || row.overdue_days > 0" size="mini" type="danger">{{ row.overdue_label || row.overdue_days + '天' }}</el-tag><span v-else>{{ row.status_label || (row.completed_at ? '已完成' : '—') }}</span></template></el-table-column>
          <el-table-column label="下次动作" min-width="220"><template slot-scope="{row}">{{ row.next_plan || row.planned_action || row.plan || row.plan_summary || '—' }}</template></el-table-column>
        </template>
      </template>
      <el-table-column label="操作" min-width="155"><template slot-scope="{row}"><div class="crm-inline"><el-button v-if="canAct(row)" type="success" size="mini" @click="$emit('act', row)">{{ actionLabels[row.action] }}</el-button><el-button v-if="canView(row)" type="text" size="mini" icon="el-icon-view" @click="$emit('view', type === 'orders' ? orderTarget(row) : row)">{{ type === 'orders' ? '查看订单' : '查看' }}</el-button><span v-if="!canAct(row) && !canView(row)" class="crm-muted">无查看权限</span></div></template></el-table-column>
      <template slot="empty"><span class="crm-muted">{{ error || (loading ? '正在加载' : '当前条件下暂无记录') }}</span></template>
    </el-table>
    <footer class="crm-pager"><el-pagination background layout="total, sizes, prev, pager, next" :total="total" :current-page="page" :page-size="perPage" :page-sizes="[10,20,50]" @size-change="$emit('size', $event)" @current-change="$emit('page', $event)" /></footer>
  </div>
</template>
<script>
import { crmDate } from '@/api/erp/crm'
import { amountText, moneyText } from './dashboardFormat'
export default {
  name: 'DashboardRecords',
  props: { rows: { type: Array, default: () => [] }, loading: Boolean, error: String, total: { type: Number, default: 0 }, page: { type: Number, default: 1 }, perPage: { type: Number, default: 10 }, type: { type: String, default: 'tasks' }, summary: Object },
  data: () => ({ moneyColumns: [{ key: 'deal_amount', label: '成交金额' }, { key: 'received_amount', label: '已收金额' }, { key: 'outstanding_amount', label: '尾款金额' }], actionLabels: { followup: '跟进', receive: '接收', verify: '核实' } }),
  methods: {
    date: crmDate,
    amount(value,currency) { return amountText(value,currency,1) },
    canView(row) { const type = this.type === 'orders' ? 'order' : row.target_type; const permission = { order: 'sales_order.view', customer: 'sales.customer', lead: 'crm.lead.view', opportunity: 'crm.opportunity.view' }[type]; return !!permission && (!this.$can || this.$can(permission)) && (!row.allowed || row.allowed.view !== false) },
    canAct(row) { const key = { followup: 'follow', receive: 'accept', verify: 'verify' }[row.action]; return !!key && !!(row.allowed || {})[key] },
    orderTarget(row) { return { ...row, target_type: 'order', target_id: row.order_id || row.id } },
    summaries({columns}) { return columns.map((column,index) => index === 0 ? '合计' : this.moneyColumns.some(item => item.key === column.property) ? moneyText(this.summary,column.property,'') : '') }
  }
}
</script>
