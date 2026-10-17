<template>
  <section class="performance-panel" v-loading="loading">
    <div class="panel-heading"><h3>工序绩效比例</h3><el-button type="text" @click="load(page)">刷新</el-button></div>
    <p>各道工序比例独立设置，例如 1% 或 2%。个人分成在工序内合计为 100%。</p>
    <el-table :data="rows" border size="small" empty-text="尚未生成执行工序">
      <el-table-column label="工序" min-width="160"><template slot-scope="{row}">{{ row.scope.operation_name || '-' }}</template></el-table-column>
      <el-table-column label="生产单元" width="100"><template slot-scope="{row}">{{ row.production_unit_id ? '#' + row.production_unit_id : '按数量执行' }}</template></el-table-column>
      <el-table-column label="状态" min-width="100"><template slot-scope="{row}">{{ statusText(row.scope.status) }}</template></el-table-column>
      <el-table-column label="工序比例" width="100"><template slot-scope="{row}">{{ rateText(row.scope.performance_rate_snapshot) }}</template></el-table-column>
      <el-table-column label="比例版本" width="95"><template slot-scope="{row}">{{ row.scope.rate_version || '原始比例' }}</template></el-table-column>
      <el-table-column label="操作" min-width="130"><template slot-scope="{row}"><el-button v-if="row.can_adjust_rate" type="text" @click="$refs.rate.open(row.scope)">调整工序比例</el-button><span v-else>—</span></template></el-table-column>
    </el-table>
    <el-pagination v-if="total > 10" small layout="total, prev, pager, next" :current-page="page" :page-size="10" :total="total" @current-change="load" />
    <ProductionPerformanceRateDialog ref="rate" @updated="load(page)" @stale="load(page)" />
  </section>
</template>

<script>
import { listWorkOrderPerformanceScopes } from '../../../api/erp/production-performance'
import { statusText } from '../../../utils/erpStatus'
import ProductionPerformanceRateDialog from './ProductionPerformanceRateDialog.vue'

export default {
  name: 'WorkOrderPerformancePanel',
  components: { ProductionPerformanceRateDialog },
  props: { workOrderId: { type: Number, required: true } },
  data: () => ({ rows: [], loading: false, page: 1, total: 0, sequence: 0 }),
  watch: { workOrderId: { immediate: true, handler() { this.rows = []; this.total = 0; this.load(1) } } },
  beforeDestroy() { this.sequence++ },
  methods: {
    statusText,
    rateText(value) { return value == null ? '未设置' : (Number(value) * 100).toLocaleString('zh-CN', { maximumFractionDigits: 6 }) + '%' },
    async load(page = 1) {
      const sequence = ++this.sequence
      const id = this.workOrderId
      this.loading = true
      try {
        const { data } = await listWorkOrderPerformanceScopes(id, { page, per_page: 10 })
        if (sequence !== this.sequence || id !== this.workOrderId) return
        const result = data.data || data
        this.rows = Array.isArray(result) ? result : result.data || []
        this.total = Number((data.meta || result.meta || {}).total || 0)
        this.page = page
      } catch (error) { if (sequence === this.sequence) this.$message.error(error.userMessage || '工序比例加载失败') }
      finally { if (sequence === this.sequence) this.loading = false }
    }
  }
}
</script>

<style scoped>
.performance-panel {
  margin-top: 16px;
  padding: 18px;
  background: #fff;
  border: 1px solid #e1e7ef;
  border-radius: 5px;
  min-width: 0;
  box-sizing: border-box;
}
.panel-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.panel-heading h3 { margin: 0; font-size: 16px; }
.performance-panel > p { color: #66758a; font-size: 13px; line-height: 1.6; }
.performance-panel .el-pagination { margin-top: 14px; white-space: normal; }
.performance-panel ::v-deep .el-table .cell { word-break: break-word; }
@media (max-width: 767px) { .performance-panel { padding: 12px; } }
</style>
