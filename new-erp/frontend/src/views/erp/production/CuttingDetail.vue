<template>
  <section class="production-page" v-loading="loading">
    <div class="page-heading">
      <div>
        <p class="eyebrow">生产管理 / 下料管理</p>
        <h1>{{ orderNo }}</h1>
        <p class="sub">加工进度：{{ progressLabel }}　｜　用料核算：{{ settlementLabel }}</p>
      </div>
      <div class="heading-actions">
        <el-button @click="$router.push('/production/cutting')">返回列表</el-button>
        <el-button v-if="$can('production.cutting.close')" type="danger" plain :loading="closing" @click="closeOrder">关闭下料单</el-button>
      </div>
    </div>

    <el-tabs v-model="tab">
      <el-tab-pane label="概览" name="overview">
        <section class="panel grid-2">
          <div><label>正式来源</label><div>{{ sourceText }}</div></div>
          <div><label>负责人</label><div>{{ detail.owner_name || detail.responsible_user_name || '-' }}</div></div>
          <div><label>材料摘要</label><div>{{ detail.material_summary || '-' }}</div></div>
          <div><label>关闭条件</label><div>{{ detail.close_blockers_text || closeHint }}</div></div>
        </section>
      </el-tab-pane>

      <el-tab-pane label="加工任务" name="tasks">
        <el-table :data="tasks" border empty-text="暂无加工任务">
          <el-table-column prop="task_no" label="任务号" min-width="140" />
          <el-table-column label="状态" width="110">
            <template slot-scope="{ row }">{{ row.status_label || row.status || '-' }}</template>
          </el-table-column>
          <el-table-column label="负责人" min-width="120">
            <template slot-scope="{ row }">{{ row.assignee_name || '-' }}</template>
          </el-table-column>
          <el-table-column label="材料" min-width="180">
            <template slot-scope="{ row }">{{ row.material_summary || '-' }}</template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="用料核算" name="settlements">
        <el-table :data="settlements" border empty-text="暂无用料批次">
          <el-table-column prop="settlement_no" label="用料批次" min-width="140" />
          <el-table-column label="来源材料" min-width="180">
            <template slot-scope="{ row }">{{ row.source_label || row.physical_no || row.batch_no || '-' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="120">
            <template slot-scope="{ row }">{{ row.status_label || row.status || '-' }}</template>
          </el-table-column>
          <el-table-column label="金额核对" min-width="140">
            <template slot-scope="{ row }">{{ row.amount_check_label || row.amount_balanced_label || '-' }}</template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="工序交接" name="handovers">
        <el-table :data="handovers" border empty-text="暂无工序交接">
          <el-table-column label="产出" min-width="160">
            <template slot-scope="{ row }">{{ row.result_label || row.item_name || '-' }}</template>
          </el-table-column>
          <el-table-column label="目标任务" min-width="180">
            <template slot-scope="{ row }">{{ row.target_label || row.target_task_no || '-' }}</template>
          </el-table-column>
          <el-table-column label="数量" width="100">
            <template slot-scope="{ row }">{{ row.quantity || row.handed_over_qty || '-' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="120">
            <template slot-scope="{ row }">{{ row.status_label || row.status || '-' }}</template>
          </el-table-column>
        </el-table>
      </el-tab-pane>
    </el-tabs>
  </section>
</template>

<script>
import { getCuttingExecution, closeCuttingOrder } from '../../../api/erp/cutting'

export default {
  name: 'CuttingDetail',
  data: () => ({
    loading: false,
    closing: false,
    tab: 'overview',
    detail: {},
    tasks: [],
    settlements: [],
    handovers: []
  }),
  computed: {
    orderNo() { return this.detail.cutting_order_no || this.detail.order_no || (`下料单 #${this.$route.params.id}`) },
    progressLabel() { return this.detail.progress_label || this.detail.status_label || this.detail.status || '-' },
    settlementLabel() {
      if (this.detail.settlement_label) return this.detail.settlement_label
      if (this.detail.settled_batches != null && this.detail.total_batches != null) {
        return `已核算 ${this.detail.settled_batches}/${this.detail.total_batches}`
      }
      return this.detail.settlement_status || '-'
    },
    sourceText() { return this.detail.source_summary || this.detail.work_order_no || '-' },
    closeHint() { return this.detail.can_close ? '当前可关闭' : '需完成加工、用料核算与工序交接后关闭' }
  },
  created() { this.fetchDetail() },
  watch: { '$route.params.id'() { this.fetchDetail() } },
  methods: {
    async fetchDetail() {
      this.loading = true
      try {
        const { data } = await getCuttingExecution(this.$route.params.id)
        const payload = data.data || data
        this.detail = payload.order || payload.cutting_order || payload
        this.tasks = payload.tasks || payload.cutting_tasks || []
        this.settlements = payload.settlements || payload.settlement_batches || payload.batches || []
        this.handovers = payload.handovers || payload.routes || []
      } catch (error) {
        this.detail = {}
        this.tasks = []
        this.settlements = []
        this.handovers = []
        this.$message.error(error.userMessage || '下料单详情加载失败')
      } finally {
        this.loading = false
      }
    },
    async closeOrder() {
      try {
        await this.$confirm('确认关闭该下料单？关闭前请确认加工、用料核算与工序交接均已完成。', '关闭下料单', { type: 'warning' })
      } catch (e) { return }
      this.closing = true
      try {
        const { data } = await closeCuttingOrder(this.$route.params.id, {
          expected_version: this.detail.business_version || this.detail.version
        })
        this.$message.success(data.message || '下料单已关闭')
        this.fetchDetail()
      } catch (error) {
        this.$message.error(error.userMessage || '关闭失败')
      } finally {
        this.closing = false
      }
    }
  }
}
</script>

<style scoped>
.production-page { padding: 16px 20px 28px; }
.page-heading { display: flex; justify-content: space-between; margin-bottom: 14px; }
.eyebrow { margin: 0; color: #64717d; font-size: 12px; }
.page-heading h1 { margin: 4px 0 0; font-size: 22px; }
.sub { margin: 6px 0 0; color: #64717d; }
.panel { background: #fff; border: 1px solid #e6ebf0; border-radius: 8px; padding: 14px; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.grid-2 label { display: block; color: #64717d; font-size: 12px; margin-bottom: 4px; }
</style>
