<template>
  <section class="production-page cutting-detail-page" v-loading="loading">
    <div class="page-heading">
      <div>
        <p class="eyebrow">生产管理　/　下料管理　/　详情</p>
        <div class="cutting-title"><h1>{{ orderNo }}</h1><el-tag type="info">{{ orderStatus }}</el-tag></div>
        <p class="sub">来源工单　{{ sourceText }}　·　用料批次　{{ settlements.map(row => row.batch_no).join('、') || '—' }}</p>
      </div>
      <div class="heading-actions">
        <el-button @click="fetchDetail">刷新</el-button>
        <el-button @click="$router.push('/production/cutting')">返回列表</el-button>
        <el-button v-if="$can('production.cutting.close') && !['CLOSED','CANCELLED'].includes(detail.status)" type="danger" plain :loading="closing" @click="closeOrder">关闭下料单</el-button>
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
          <el-table-column label="已入库 / 入库单" min-width="180"><template slot-scope="{row}">{{ row.warehoused_qty || '0' }}<div v-for="receipt in row.warehouse_receipts || []" :key="receipt.id">{{ receipt.receipt_no }} / {{ receipt.posted_qty }}</div></template></el-table-column>
        </el-table>
      </el-tab-pane>
      <el-tab-pane label="产出入库" name="warehouse" v-if="$can('production.cutting.view')">
        <el-tabs v-model="warehouseTab" class="cutting-warehouse-tabs">
          <el-tab-pane label="产品入库" name="products"><cutting-warehouse-panel v-if="warehouseTab === 'products'" :order-id="pageRoute.params.id" @posted="fetchDetail" /></el-tab-pane>
          <el-tab-pane label="余料入库" name="remnants"><cutting-remnant-panel v-if="warehouseTab === 'remnants'" :order-id="pageRoute.params.id" @posted="fetchDetail" /></el-tab-pane>
        </el-tabs>
      </el-tab-pane>
    </el-tabs>
    <el-pagination v-if="tab === 'settlements' || tab === 'handovers'" :current-page="page" :page-size="20" :total="tab === 'settlements' ? inputTotal : resultTotal" layout="total, prev, pager, next" @current-change="changePage" />
  </section>
</template>

<script>
import cachedPageRoute from '@/utils/cachedPageRoute'
import { getCuttingExecution, closeCuttingOrder } from '../../../api/erp/cutting'
import CuttingWarehousePanel from './CuttingWarehousePanel.vue'
import CuttingRemnantPanel from './CuttingRemnantPanel.vue'

export default {
  mixins: [cachedPageRoute],
  name: 'CuttingDetail',
  components: { CuttingWarehousePanel, CuttingRemnantPanel },
  data: () => ({
    loading: false,
    closing: false,
    tab: 'overview',
    warehouseTab: 'products', sourceWorkOrders: [],
    detail: {},
    tasks: [],
    settlements: [],
    handovers: [],
    page: 1, inputTotal: 0, resultTotal: 0, lifecycle: {}
  }),
  computed: {
    orderNo() { return this.detail.cutting_order_no || this.detail.order_no || (`下料单 #${this.pageRoute.params.id}`) },
    progressLabel() { return this.detail.progress_label || this.detail.status_label || this.detail.status || '-' },
    settlementLabel() {
      if (this.detail.settlement_label) return this.detail.settlement_label
      if (this.detail.settled_batches != null && this.detail.total_batches != null) {
        return `已核算 ${this.detail.settled_batches}/${this.detail.total_batches}`
      }
      return this.detail.settlement_status || '-'
    },
    sourceText() { return this.sourceWorkOrders.map(row => row.work_order_no).join('、') || this.detail.source_summary || this.detail.work_order_no || '—' },
    orderStatus() { return ({ PUBLISHED: '已发布', IN_PROGRESS: '加工中', CLOSED: '已关闭', CANCELLED: '已取消' })[this.detail.status] || this.detail.status || '—' },
    closeHint() { return this.lifecycle.can_close ? '当前可关闭' : '需完成加工、用料核算与工序交接后关闭' }
  },
  created() { this.fetchDetail() },
  watch: { 'pageRoute.params.id'() { this.page = 1; this.fetchDetail() } },
  methods: {
    changePage(page) { this.page = page; this.fetchDetail() },
    async fetchDetail() {
      this.loading = true
      try {
        const { data } = await getCuttingExecution(this.pageRoute.params.id, { page: this.page, per_page: 20 })
        const payload = data.data || data
        this.detail = payload.order || payload.cutting_order || payload
        this.sourceWorkOrders = payload.source_work_orders || []
        this.lifecycle = payload.lifecycle || {}
        this.tasks = payload.task && payload.task.id ? [payload.task] : []
        const inputs = payload.inputs || {}; const results = payload.results || {}
        this.inputTotal = Number((inputs.meta || {}).total || 0); this.resultTotal = Number((results.meta || {}).total || 0)
        this.settlements = (inputs.data || []).map(row => ({ ...row, settlement_no: row.batch_no, status_label: row.display_status }))
        this.handovers = (results.data || []).flatMap(result => (result.routes || []).map(route => ({ ...route, item_name: result.item_name, status_label: route.display_status, target_label: route.route_type === 'WAREHOUSE' ? '入库' : [route.work_order_no, route.operation_name].filter(Boolean).join(' / ') })))
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
        const { data } = await closeCuttingOrder(this.pageRoute.params.id, {
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
.production-page { padding: 16px 20px 28px; background: #fff; min-height: 100vh; box-sizing: border-box; font-size: 16px; }
.page-heading { display: flex; justify-content: space-between; margin-bottom: 22px; height: auto; min-height: 108px; gap: 20px; }
.eyebrow { margin: 0; color: #506581; font-size: 16px; }
.page-heading h1 { margin: 4px 0 0; font-size: 28px; }
.cutting-title { display: flex; align-items: center; gap: 16px; margin: 18px 0 12px; flex-wrap: wrap; min-width: 0; }.cutting-title h1 { margin: 0; overflow-wrap: anywhere; }.cutting-warehouse-tabs { margin-top: 6px; }.cutting-warehouse-tabs ::v-deep > .el-tabs__header { margin-bottom: 16px; }.cutting-warehouse-tabs ::v-deep > .el-tabs__header .el-tabs__item { padding: 0 28px!important; }.page-heading>div:first-child { min-width: 0; }.sub { overflow-wrap: anywhere; }
.sub { margin: 6px 0 0; color: #64717d; }
.panel { background: #fff; border: 1px solid #e6ebf0; border-radius: 8px; padding: 14px; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.grid-2 label { display: block; color: #64717d; font-size: 12px; margin-bottom: 4px; }
.heading-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.heading-actions .el-button { margin-left: 0; }
.cutting-detail-page ::v-deep > .el-tabs > .el-tabs__header .el-tabs__item { font-size: 17px; height: 46px; line-height: 46px; padding: 0 24px; }.cutting-detail-page ::v-deep .el-tabs__item.is-active { color: #008454; }.cutting-detail-page ::v-deep .el-tabs__active-bar { background: #008454; }.cutting-warehouse-tabs ::v-deep > .el-tabs__header .el-tabs__item { font-size: 16px; height: 46px; line-height: 46px; }.heading-actions { align-items: flex-start; }.heading-actions .el-button { font-size: 14px; }
@media (max-width: 760px) { .page-heading { flex-direction: column; gap: 12px; }.grid-2 { grid-template-columns: minmax(0, 1fr); }.production-page { padding: 12px; } }
</style>
<style>
/* The approved cutting detail has its own breadcrumb/action header. Keep the shared
   sidebar geometry intact while using that explicit detail header on this page. */
.erp-shell:has(.cutting-detail-page) > .erp-topbar { display: none; }
</style>
