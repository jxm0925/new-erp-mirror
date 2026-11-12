<template>
  <section class="customer-list-expansion">
    <el-tabs v-model="tab" @tab-click="tabChanged"><el-tab-pane v-if="canOpportunities" label="商机" name="opportunities" /><el-tab-pane v-if="canLeads" label="线索" name="leads" /></el-tabs>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-table v-loading="loading" :data="rows" size="small">
      <el-table-column :label="tab === 'opportunities' ? '商机名称' : '线索编号'" min-width="170"><template slot-scope="{row}"><el-button type="text" size="mini" @click="$emit('open',tab,row)">{{ tab === 'opportunities' ? row.name : row.lead_no }}</el-button></template></el-table-column>
      <el-table-column v-if="tab === 'leads'" prop="demand_summary" label="需求摘要" min-width="180" />
      <el-table-column :label="tab === 'opportunities' ? '阶段' : '状态'" min-width="140"><template slot-scope="{row}">{{ tab === 'opportunities' && row.status === 'open' ? row.stage_label : row.status_label }}</template></el-table-column>
      <el-table-column prop="owner_name" label="负责人" min-width="120" />
      <el-table-column v-if="tab === 'opportunities'" label="预计金额" min-width="140" align="right"><template slot-scope="{row}">{{ money(row.expected_amount,row.currency) }}</template></el-table-column>
      <el-table-column v-if="tab === 'opportunities'" prop="expected_close_date" label="预计成交日期" min-width="125" />
      <el-table-column v-if="tab === 'opportunities'" label="关联订单" min-width="95" align="right"><template slot-scope="{row}"><el-button v-if="row.orders_count && (!$can || $can('sales_order.view'))" type="text" size="mini" @click="$emit('open',tab,row,'orders')">{{ row.orders_count }}</el-button><span v-else>{{ row.orders_count || 0 }}</span></template></el-table-column>
      <el-table-column v-if="tab === 'leads'" prop="customer_pool" label="客户池" min-width="140" />
      <el-table-column label="最近联系" min-width="135"><template slot-scope="{row}">{{ date(row.last_contact_at) }}</template></el-table-column>
    </el-table>
    <div class="expansion-pager"><el-pagination background layout="total, prev, pager, next" :page-size="10" :current-page.sync="page" :total="total" @current-change="load" /></div>
  </section>
</template>

<script>
import { listCrmLeads, listCrmOpportunities, crmDate, crmError } from '@/api/erp/crm'
export default {
  name: 'CrmCustomerListExpansion',
  props: { customerId: { type: Number, required: true } },
  data: () => ({ tab: '', page: 1, rows: [], total: 0, loading: false, error: '', sequence: 0 }),
  computed: {
    canLeads() { return !this.$can || this.$can('crm.lead.view') },
    canOpportunities() {
      return !this.$can || this.$can('crm.opportunity.view');
    }
  },
  created() { this.tab = this.canOpportunities ? 'opportunities' : 'leads'; this.load() },
  beforeDestroy() { this.sequence += 1 },
  watch: { customerId() { this.page = 1; this.load() } },
  methods: {
    date: crmDate,
    tabChanged() { this.page = 1; this.load() },
    money(value,currency) { return value === null || value === undefined ? '待核实' : `${currency || 'CNY'} ${Number(value).toLocaleString('zh-CN',{maximumFractionDigits:2})}` },
    async load() {
      if ((this.tab === 'opportunities' && !this.canOpportunities) || (this.tab === 'leads' && !this.canLeads)) return
      const sequence = ++this.sequence
      this.loading = true
      this.error = ''
      this.rows = []
      this.total = 0
      try {
        const request = this.tab === 'opportunities' ? listCrmOpportunities : listCrmLeads
        const { data } = await request({ customer_id: this.customerId, tab: 'all', page: this.page, per_page: 10 })
        if (sequence !== this.sequence) return
        this.rows = data.data || []
        this.total = data.total || 0
      } catch (error) {
        if (sequence === this.sequence) {
          this.rows = [];
          this.total = 0;
          this.error = crmError(error,'关联记录加载失败');
        }
      } finally { if (sequence === this.sequence) this.loading = false }
    }
  }
}
</script>

<style scoped>
.customer-list-expansion {
  padding: 0 14px 10px;
  background: #fbfdfc;
  box-sizing: border-box;
  min-width: 0;
}
.customer-list-expansion :deep(.el-tabs__header) {
  margin-bottom: 8px;
}
.expansion-pager {
  display: flex;
  justify-content: flex-end;
  padding-top: 10px;
}
</style>
