<template>
  <section class="production-labor-section" v-loading="loading">
    <div class="labor-heading"><h4>实际工时</h4><el-button v-if="scopeType" type="text" :disabled="loading || busy" @click="load(page)">刷新工时</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-alert v-if="value && value.schema_ready === false" title="当前暂时无法读取工时，请稍后刷新。" type="warning" :closable="false" />
    <template v-if="value && value.schema_ready !== false">
      <div v-if="value.summary" class="labor-totals"><span>本单工时：{{ minutes(value.summary.exclusive_closed_minutes) }} 分钟</span><span>关联公共任务工时（不分摊）：{{ minutes(value.summary.shared_closed_minutes) }} 分钟</span></div>
      <div v-else class="labor-totals"><span>已结束工时：{{ minutes(value.closed_minutes) }} 分钟</span><el-tag v-if="value.shared" size="mini" type="info">公共配料，共享工时</el-tag></div>
      <p v-if="activeCount" class="labor-note">{{ activeCount }} 段正在计时，尚未计入已结束工时。</p>
      <p v-if="value.shared" class="labor-note">公共配料只记录一次实际工时，关联工单不分别分摊。{{ relatedText(value.related_work_orders) }}</p>
      <slot name="actions" />
      <template v-if="value.summary && Number(people.total || 0)"><el-table :data="people.data || []" border size="small" class="labor-people"><el-table-column prop="employee_name" label="人员" min-width="120" /><el-table-column label="本单工时（分钟）" min-width="150"><template slot-scope="{row}">{{ minutes(row.exclusive_closed_minutes) }}</template></el-table-column><el-table-column label="关联公共任务工时（分钟）" min-width="200"><template slot-scope="{row}">{{ minutes(row.shared_closed_minutes) }}</template></el-table-column></el-table><el-pagination class="labor-pagination" :current-page="Number(people.current_page || 1)" :page-size="10" :total="Number(people.total || 0)" layout="total, prev, pager, next" :disabled="loading || busy" @current-change="changePeoplePage" /></template>
      <el-table :data="sessions.data || []" border size="small" row-key="id" empty-text="尚无实际计时记录">
        <el-table-column v-if="scopeType" label="作业来源" min-width="190"><template slot-scope="{row}"><div>{{ sourceText(row) }} {{ row.operation_name }} {{ row.source_no }}</div><small v-if="row.shared">共享任务 · {{ relatedText(row.related_work_orders) }}</small></template></el-table-column>
        <el-table-column prop="employee_name" label="实际作业人" min-width="110" />
        <el-table-column label="开始时间" min-width="155"><template slot-scope="{row}">{{ date(row.started_at) }}</template></el-table-column>
        <el-table-column label="结束时间" min-width="155"><template slot-scope="{row}">{{ row.ended_at ? date(row.ended_at) : '计时中' }}</template></el-table-column>
        <el-table-column label="实际工时（分钟）" min-width="145"><template slot-scope="{row}">{{ row.ended_at ? minutes(row.actual_labor_minutes) : '—' }}</template></el-table-column>
        <el-table-column label="计时状态" width="100"><template slot-scope="{row}">{{ row.ended_at ? '已结束' : '计时中' }}</template></el-table-column>
      </el-table>
      <el-pagination class="labor-pagination" :current-page="Number(sessions.current_page || 1)" :page-size="10" :total="Number(sessions.total || 0)" layout="total, prev, pager, next" :disabled="loading || busy" @current-change="changePage" />
    </template>
  </section>
</template>

<script>
import { erpDateTime } from '../../../utils/dateTime'
import { getProductionLaborSummary } from '../../../api/erp/production-materials'

export default {
  name: 'ProductionLaborSummaryPanel',
  props: { labor: { type: Object, default: null }, scopeType: String, scopeId: { type: Number, default: 0 }, busy: Boolean },
  data: () => ({ result: null, page: 1, peoplePage: 1, loading: false, error: '', sequence: 0 }),
  computed: {
    context() { return `${this.scopeType || ''}:${this.scopeId}` },
    value() { return this.labor || this.result },
    sessions() { return this.value?.sessions || {} },
    people() { return this.value?.summary?.people || {} },
    activeCount() { return Number(this.value?.summary?.active_count ?? this.value?.active_count ?? 0) }
  },
  watch: { context: { immediate: true, handler() { this.sequence++; this.result = null; this.error = ''; this.page = 1; this.peoplePage = 1; if (this.scopeType && this.scopeId) this.load(1) } } },
  beforeDestroy() { this.sequence++ },
  methods: {
    minutes(value) { return value == null ? '—' : Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 }) },
    date(value) { return value ? erpDateTime(value, 19) : '—' },
    relatedText(rows) { return (rows || []).map(row => row.work_order_no || `工单 #${row.id}`).join('、') },
    sourceText(row) { return ({ production: '生产工序', packing: '打包', warehouse: '仓配作业', public_preparation: '公共配料', picking: '配料', delivery: '配送' })[row.subject_type || row.source_type] || '作业' },
    changePage(page) { if (this.busy || this.loading) return; if (this.labor) this.$emit('page', page); else this.load(page) },
    changePeoplePage(page) { if (!this.busy && !this.loading) this.load(this.page, page) },
    async load(page = 1, peoplePage = this.peoplePage) {
      if (!this.scopeType || !this.scopeId) return
      const context = this.context; const sequence = ++this.sequence
      this.loading = true; this.error = ''; this.page = page; this.peoplePage = peoplePage
      try { const { data } = await getProductionLaborSummary(this.scopeType, this.scopeId, { page, per_page: 10, people_page: peoplePage, people_per_page: 10 }); if (sequence === this.sequence && context === this.context) this.result = data.data }
      catch (e) { if (sequence === this.sequence && context === this.context) this.error = e.userMessage || '实际工时读取失败' }
      finally { if (sequence === this.sequence) this.loading = false }
    }
  }
}
</script>

<style scoped>
.production-labor-section { margin: 16px 0; padding: 16px; border: 1px solid #e2e8f0; background: #fff; min-width: 0; }
.labor-heading, .labor-totals { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.labor-heading h4 { margin: 0; }
.labor-note { color: #64748b; line-height: 1.6; overflow-wrap: anywhere; }
.labor-people { margin-bottom: 12px; }
.labor-pagination { margin-top: 12px; overflow-x: auto; }
small { color: #64748b; }
</style>
