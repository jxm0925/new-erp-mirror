<template>
  <section v-loading="loading" class="change-labor">
    <div class="panel-head"><strong>订单变更工时</strong><el-button size="mini" :loading="loading" @click="load">刷新</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <template v-else>
      <div class="labor-totals"><div v-for="item in totalFields" :key="item.key"><span>{{ item.label }}</span><b>{{ minutes(totals[item.key]) }} 分钟</b></div></div>
      <p class="labor-rule">{{ result.rule || '以订单全部审核完成、正式生效的时刻分段。暂停等待不计时；来源明细按实际人员和工时分摊留存。' }}</p>
      <el-table :data="result.segments || []" border size="mini" empty-text="暂无关联订单变更的实际工时">
        <el-table-column label="变更 / 取消生效" min-width="150"><template slot-scope="{row}">{{ row.change_no || '订单取消' }}<br>{{ date(row.effective_at) }}</template></el-table-column>
        <el-table-column label="工单 / 本次编号" min-width="130"><template slot-scope="{row}">{{ row.work_order_no }}<br><span v-if="row.work_order_unit_id">{{ row.unit_serial_no || ('第' + (row.unit_no || row.work_order_unit_id) + '个') }}</span></template></el-table-column>
        <el-table-column prop="operation_name" label="工序 / 工作" min-width="100" />
        <el-table-column prop="employee_name" label="实际人员" min-width="85" />
        <el-table-column prop="phase_label" label="工时归属" min-width="120" />
        <el-table-column label="计时区间" min-width="165"><template slot-scope="{row}">{{ date(row.started_at) }} 至 {{ row.active ? '计时中' : date(row.ended_at) }}</template></el-table-column>
        <el-table-column label="本次归属工时" min-width="90" align="right"><template slot-scope="{row}">{{ minutes(row.labor_minutes) }} 分钟</template></el-table-column>
        <el-table-column label="记录来源" min-width="145"><template slot-scope="{row}">{{ sourceLabel(row) }}<span v-if="row.shared_source || row.is_allocated"> · 分摊 {{ minutes(Number(row.allocation_ratio || 0) * 100) }}%</span></template></el-table-column>
      </el-table>
      <el-pagination v-if="result.segments_total" background :current-page="page" :page-size="10" :total="result.segments_total" layout="total, prev, pager, next" @current-change="changePage" />
    </template>
  </section>
</template>
<script>
import api from '@/api/erp/master'
export default {
  props: { orderId: { type: [Number, String], required: true }, initialResult: { type: Object, default: null }, loader: { type: Function, default: null } },
  data: () => ({
    loading: false, error: '', result: {}, page: 1, sequence: 0,
    totalFields: [
      { key: 'before_change_minutes', label: '变更生效前' },
      { key: 'after_change_stock_minutes', label: '后续备货' },
      { key: 'material_return_minutes', label: '退料处理' },
      { key: 'dismantle_minutes', label: '拆解处理' },
      { key: 'scrap_minutes', label: '报废处理' },
      { key: 'after_change_other_minutes', label: '其他后续工时' },
      { key: 'total_minutes', label: '合计' }
    ]
  }),
  computed: { totals() { return this.result.totals || {} } },
  watch: { orderId() { this.page = 1; this.load() } },
  created() { if (this.initialResult) this.result = this.initialResult; else this.load() },
  methods: {
    minutes(value) { return Number(value || 0).toFixed(2) },
    date(value) { return value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '-' },
    sourceLabel(row) {
      const labels = { mfg: '工序计时', cutting_run: '集中开料', disposition: '处置计时' }
      const allocation = { recorded_unit_count: '按参与编号分摊', legacy_work_order_unit_count: '历史共同工时分摊' }[row.allocation_method]
      return `${row.lane === 'rework' ? '返工 · ' : ''}${row.source_label || labels[row.source_type] || '工时记录'} #${row.source_id || row.trace_key || '-'}${allocation ? ' · ' + allocation : ''}`
    },
    changePage(page) { this.page = page; this.load() },
    async load() {
      const sequence = ++this.sequence
      this.loading = true; this.error = ''
      try {
        let result
        if (this.loader) result = await this.loader({ page: this.page, per_page: 10 })
        else { const response = await api.get(`/v1/erp/sales/orders/${this.orderId}/change-labor`, { params: { page: this.page, per_page: 10 } }); result = response.data.data }
        if (sequence === this.sequence) this.result = result || {}
      } catch (error) { if (sequence === this.sequence) this.error = error.userMessage || '订单变更工时加载失败' }
      finally { if (sequence === this.sequence) this.loading = false }
    }
  }
}
</script>
<style scoped>
.change-labor {
  margin-top:16px;
  background:#fff;
  border:1px solid #e2e8f0;
  border-radius:8px;
  padding:16px;
}
.panel-head {
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:12px;
}
.labor-totals {
  display:flex;
  flex-wrap:wrap;
  gap:16px 24px;
}
.labor-totals div {
  display:flex;
  flex-direction:column;
  gap:6px;
  min-width:0;
}
.labor-totals span, .labor-rule {
  color:#64748b;
  font-size:12px;
}
.labor-totals b {
  color:#1e293b;
}
</style>
