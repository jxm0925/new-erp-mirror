<template>
  <section class="packing-labor-section">
    <div class="labor-heading"><h4>实际工时</h4></div>
    <div class="labor-totals"><span>已结束工时：{{ minutes(labor.closed_minutes) }} 分钟</span></div>
    <p v-if="activeCount" class="labor-note">{{ activeCount }} 段正在计时，尚未计入已结束工时。</p>
    <el-table :data="sessions.data || []" border size="small" row-key="id" empty-text="尚无实际计时记录">
      <el-table-column prop="employee_name" label="实际作业人" min-width="110" />
      <el-table-column label="开始时间" min-width="155"><template slot-scope="{row}">{{ date(row.started_at) }}</template></el-table-column>
      <el-table-column label="结束时间" min-width="155"><template slot-scope="{row}">{{ row.ended_at ? date(row.ended_at) : '计时中' }}</template></el-table-column>
      <el-table-column label="实际工时（分钟）" min-width="145"><template slot-scope="{row}">{{ row.ended_at ? minutes(row.actual_labor_minutes) : '—' }}</template></el-table-column>
      <el-table-column label="计时状态" width="100"><template slot-scope="{row}">{{ row.ended_at ? '已结束' : '计时中' }}</template></el-table-column>
    </el-table>
    <el-pagination class="labor-pagination" :current-page="Number(sessions.current_page || 1)" :page-size="10" :total="Number(sessions.total || 0)" layout="total, prev, pager, next" :disabled="busy" @current-change="page => $emit('page', page)" />
  </section>
</template>

<script>
import { erpDateTime } from '../../../utils/dateTime'

export default {
  name: 'PackingLaborPanel',
  props: { labor: { type: Object, required: true }, busy: Boolean },
  computed: {
    sessions () { return this.labor.sessions || {} },
    activeCount () { return Number(this.labor.active_count || 0) }
  },
  methods: {
    minutes (value) { return value == null ? '—' : Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 }) },
    date (value) { return value ? erpDateTime(value, 19) : '—' }
  }
}
</script>

<style scoped>
.packing-labor-section { margin: 16px 0; padding: 16px; border: 1px solid #e2e8f0; background: #fff; min-width: 0; }
.labor-heading, .labor-totals { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.labor-heading h4 { margin: 0; }
.labor-note { color: #64748b; line-height: 1.6; overflow-wrap: anywhere; }
.labor-pagination { margin-top: 12px; overflow-x: auto; }
</style>
