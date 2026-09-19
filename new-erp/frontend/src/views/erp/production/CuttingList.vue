<template>
  <section class="production-page" v-loading="loading">
    <div class="page-heading">
      <div>
        <p class="eyebrow">生产管理 / 下料管理</p>
        <h1>下料单列表</h1>
      </div>
      <div class="heading-actions">
        <el-button v-if="$can('production.cutting.publish') || $can('production.cutting.view')" type="success" @click="$router.push('/production/cutting/create')">新增下料单</el-button>
      </div>
    </div>

    <section class="filter-card">
      <div class="filter-grid">
        <label><span>下料单号 / 来源</span><el-input v-model="filters.keyword" placeholder="下料单号 / 工单号 / 需求号" clearable /></label>
        <label><span>加工进度</span>
          <el-select v-model="filters.progress_status" clearable placeholder="全部">
            <el-option v-for="item in progressOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </label>
        <label><span>用料核算</span>
          <el-select v-model="filters.settlement_status" clearable placeholder="全部">
            <el-option v-for="item in settlementOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </label>
        <label><span>计划日期</span>
          <el-date-picker v-model="dateRange" type="daterange" value-format="yyyy-MM-dd" range-separator="~" start-placeholder="开始" end-placeholder="结束" />
        </label>
      </div>
      <div class="filter-actions">
        <el-button @click="reset">重置</el-button>
        <el-button type="primary" @click="search">查询</el-button>
      </div>
    </section>

    <section class="table-card">
      <el-table :data="rows" border stripe empty-text="暂无下料单">
        <el-table-column prop="cutting_order_no" label="下料单号" min-width="150">
          <template slot-scope="{ row }">
            <el-button type="text" @click="openDetail(row)">{{ row.cutting_order_no || row.order_no || ('#' + row.id) }}</el-button>
          </template>
        </el-table-column>
        <el-table-column label="正式来源" min-width="180">
          <template slot-scope="{ row }">{{ sourceText(row) }}</template>
        </el-table-column>
        <el-table-column label="材料摘要" min-width="160">
          <template slot-scope="{ row }">{{ row.material_summary || row.materials_summary || '-' }}</template>
        </el-table-column>
        <el-table-column label="加工进度" min-width="110">
          <template slot-scope="{ row }">{{ progressLabel(row) }}</template>
        </el-table-column>
        <el-table-column label="用料核算" min-width="120">
          <template slot-scope="{ row }">{{ settlementLabel(row) }}</template>
        </el-table-column>
        <el-table-column label="负责人" min-width="100">
          <template slot-scope="{ row }">{{ row.owner_name || row.responsible_user_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="计划日期" min-width="110">
          <template slot-scope="{ row }">{{ (row.planned_date || '').toString().slice(0, 10) || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="100" fixed="right">
          <template slot-scope="{ row }">
            <el-button type="text" @click="openDetail(row)">查看</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination
          background
          layout="total, prev, pager, next, sizes"
          :total="total"
          :current-page.sync="page"
          :page-size.sync="perPage"
          :page-sizes="[10, 20, 50]"
          @current-change="fetchList"
          @size-change="search"
        />
      </div>
    </section>
  </section>
</template>

<script>
import { listCuttingOrders } from '../../../api/erp/cutting'

export default {
  name: 'CuttingList',
  data: () => ({
    loading: false,
    rows: [],
    total: 0,
    page: 1,
    perPage: 20,
    dateRange: [],
    filters: { keyword: '', progress_status: '', settlement_status: '' },
    progressOptions: [
      { value: 'WAIT_CLAIM', label: '待接单' },
      { value: 'IN_PROGRESS', label: '加工中' },
      { value: 'FINISHED', label: '已完工' },
      { value: 'CLOSED', label: '已关闭' },
      { value: 'CANCELLED', label: '已取消' }
    ],
    settlementOptions: [
      { value: 'PENDING', label: '待核算' },
      { value: 'PARTIAL', label: '部分核算' },
      { value: 'DONE', label: '已核算' }
    ]
  }),
  created() { this.fetchList() },
  methods: {
    search() { this.page = 1; this.fetchList() },
    reset() {
      this.filters = { keyword: '', progress_status: '', settlement_status: '' }
      this.dateRange = []
      this.search()
    },
    openDetail(row) { this.$router.push(`/production/cutting/${row.id}`) },
    sourceText(row) {
      return row.source_summary || row.work_order_no || row.demand_no || row.source_no || '-'
    },
    progressLabel(row) {
      const map = Object.fromEntries(this.progressOptions.map(x => [x.value, x.label]))
      return map[row.progress_status || row.status] || row.progress_label || row.status_label || row.status || '-'
    },
    settlementLabel(row) {
      if (row.settlement_label) return row.settlement_label
      if (row.settled_batches != null && row.total_batches != null) {
        return `已核算 ${row.settled_batches}/${row.total_batches}`
      }
      const map = Object.fromEntries(this.settlementOptions.map(x => [x.value, x.label]))
      return map[row.settlement_status] || row.settlement_status || '-'
    },
    async fetchList() {
      this.loading = true
      try {
        const params = {
          page: this.page,
          per_page: this.perPage,
          keyword: this.filters.keyword || undefined,
          progress_status: this.filters.progress_status || undefined,
          settlement_status: this.filters.settlement_status || undefined,
          planned_date_from: this.dateRange && this.dateRange[0],
          planned_date_to: this.dateRange && this.dateRange[1]
        }
        const { data } = await listCuttingOrders(params)
        const payload = data.data || data
        this.rows = Array.isArray(payload) ? payload : (payload.data || payload.items || [])
        this.total = data.total || payload.total || this.rows.length
      } catch (error) {
        this.rows = []
        this.total = 0
        this.$message.error(error.userMessage || '下料单列表加载失败')
      } finally {
        this.loading = false
      }
    }
  }
}
</script>

<style scoped>
.production-page { padding: 16px 20px 28px; }
.page-heading { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
.eyebrow { margin: 0; color: #64717d; font-size: 12px; }
.page-heading h1 { margin: 4px 0 0; font-size: 22px; }
.filter-card, .table-card { background: #fff; border: 1px solid #e6ebf0; border-radius: 8px; padding: 14px; margin-bottom: 14px; }
.filter-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.filter-grid label { display: grid; gap: 6px; font-size: 12px; color: #64717d; }
.filter-actions { margin-top: 12px; text-align: right; }
.pager { margin-top: 12px; text-align: right; }
@media (max-width: 1366px) {
  .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
