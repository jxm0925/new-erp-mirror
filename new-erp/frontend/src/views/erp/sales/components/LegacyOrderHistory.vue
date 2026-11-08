<template>
  <section v-loading="loading" class="legacy-order-history">
    <div class="history-head">
      <strong>旧系统工单</strong>
      <div class="history-actions">
        <span>本订单实际工时 <b>{{ hours(result.labor_hours) }}</b> 小时</span>
        <el-button size="mini" :loading="loading" icon="el-icon-refresh" @click="load">刷新</el-button>
      </div>
    </div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <template v-else>
      <el-table :data="result.work_orders || []" border size="mini" empty-text="暂无旧系统工单记录">
        <el-table-column prop="work_order_no" label="工单号" min-width="150" />
        <el-table-column label="订单商品 / 规格" min-width="220">
          <template slot-scope="{row}">
            <div v-for="(product, index) in row.products || []" :key="product.sales_order_line_id || index" class="product-row">
              {{ product.product_name || '-' }}<span v-if="product.sku_name"> / {{ product.sku_name }}</span> × {{ product.quantity }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="旧系统状态" min-width="100"><template slot-scope="{row}"><el-tag size="mini" type="info">{{ row.status_name || '-' }}</el-tag></template></el-table-column>
        <el-table-column label="实际工时（小时）" min-width="130" align="right"><template slot-scope="{row}">{{ hours(row.labor_hours) }}</template></el-table-column>
        <el-table-column label="操作" width="100" align="center"><template slot-scope="{row}"><el-button type="text" size="mini" icon="el-icon-view" @click="filterWorkOrder(row)">查看动态</el-button></template></el-table-column>
      </el-table>

      <div class="history-head logs-head">
        <strong>工单动态</strong>
        <el-tag v-if="selectedWorkOrder" size="small" closable @close="filterWorkOrder(null)">{{ selectedWorkOrder.work_order_no }}</el-tag>
      </div>
      <el-table :data="logs.data || []" border size="mini" empty-text="暂无旧系统工单动态">
        <el-table-column label="记录时间" min-width="155"><template slot-scope="{row}">{{ date(row.reported_at) }}</template></el-table-column>
        <el-table-column prop="work_order_no" label="工单号" min-width="145" />
        <el-table-column label="动态内容" min-width="240"><template slot-scope="{row}"><div class="history-content">{{ row.content || '-' }}</div></template></el-table-column>
        <el-table-column label="人员" min-width="120"><template slot-scope="{row}">{{ names(row.user_names) }}</template></el-table-column>
        <el-table-column label="工时（小时）" min-width="110" align="right"><template slot-scope="{row}">{{ hours(row.hours) }}</template></el-table-column>
        <el-table-column label="操作" width="80" align="center"><template slot-scope="{row}"><el-button type="text" size="mini" icon="el-icon-view" @click="selectedLog = row">详情</el-button></template></el-table-column>
      </el-table>
      <div class="history-pagination">
        <el-pagination background :current-page="page" :page-size="10" :total="Number(logs.total || 0)" layout="total, prev, pager, next, jumper" @current-change="changePage" />
      </div>
    </template>

    <el-dialog title="旧系统工单动态" :visible="!!selectedLog" width="min(780px, calc(100vw - 32px))" top="5vh" append-to-body :close-on-click-modal="false" @close="selectedLog = null">
      <div v-if="selectedLog" class="history-dialog-body">
        <div class="log-facts">
          <div><span>工单号</span><b>{{ selectedLog.work_order_no || '-' }}</b></div>
          <div><span>记录时间</span><b>{{ date(selectedLog.reported_at) }}</b></div>
          <div><span>记录人员</span><b>{{ names(selectedLog.user_names) }}</b></div>
          <div><span>实际工时</span><b>{{ hours(selectedLog.hours) }} 小时</b></div>
        </div>
        <h4>动态内容</h4>
        <div class="history-content">{{ selectedLog.content || '-' }}</div>
        <template v-if="(selectedLog.allocations || []).length">
          <h4>人员工时</h4>
          <el-table :data="selectedLog.allocations" border size="mini">
            <el-table-column prop="user_name" label="人员" min-width="150" />
            <el-table-column label="工时（小时）" min-width="130" align="right"><template slot-scope="{row}">{{ hours(row.hours) }}</template></el-table-column>
          </el-table>
          <p v-if="Number(selectedLog.allocation_difference_hours || 0) !== 0" class="allocation-notice">旧个人分配与本次工时相差 {{ hours(selectedLog.allocation_difference_hours) }} 小时；合计按本次工时统计</p>
        </template>
        <template v-if="(selectedLog.attachments || []).length">
          <h4>附件</h4>
          <div class="history-attachments">
            <a v-for="(file, index) in selectedLog.attachments" :key="index" :href="file.url" target="_blank" rel="noopener noreferrer">{{ file.name || `附件 ${index + 1}` }}</a>
          </div>
        </template>
      </div>
      <span slot="footer"><el-button size="small" @click="selectedLog = null">关闭</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { getSalesOrderLegacyHistory } from '@/api/erp/sales'
import { erpDateTime } from '@/utils/dateTime'

export default {
  name: 'LegacyOrderHistory',
  props: { orderId: { type: [Number, String], required: true } },
  data: () => ({ result: {}, page: 1, selectedWorkOrder: null, selectedLog: null, loading: false, error: '', sequence: 0 }),
  computed: { logs() { return this.result.logs || {} } },
  watch: {
    orderId: { immediate: true, handler() { this.page = 1; this.result = {}; this.selectedWorkOrder = null; this.selectedLog = null; this.load() } }
  },
  beforeDestroy() { this.sequence++ },
  methods: {
    hours(value) { return Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) },
    date(value) { return value ? erpDateTime(value) : '-' },
    names(value) { return Array.isArray(value) ? value.join('、') || '-' : value || '-' },
    filterWorkOrder(row) { this.selectedWorkOrder = row; this.page = 1; this.load() },
    changePage(page) { this.page = page; this.load() },
    async load() {
      if (!this.orderId) return
      const sequence = ++this.sequence
      this.loading = true
      this.error = ''
      try {
        const { data } = await getSalesOrderLegacyHistory(this.orderId, { page: this.page, per_page: 10, legacy_work_order_id: this.selectedWorkOrder ? this.selectedWorkOrder.legacy_work_order_id : undefined })
        if (sequence !== this.sequence) return
        this.result = data
        this.page = Number((data.logs || {}).current_page || this.page)
      } catch (error) {
        if (sequence === this.sequence) this.error = error.userMessage || '旧系统工单记录加载失败，请重试'
      } finally {
        if (sequence === this.sequence) this.loading = false
      }
    }
  }
}
</script>

<style scoped>
.legacy-order-history,
.history-dialog-body,
.history-head,
.history-actions,
.log-facts > div {
  box-sizing: border-box;
  min-width: 0;
}
.legacy-order-history {
  padding: 16px;
}
.history-head,
.history-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.history-head {
  justify-content: space-between;
  margin-bottom: 12px;
}
.history-actions {
  color: #64748b;
  font-size: 12px;
}
.allocation-notice {
  color: #64748b;
  font-size: 12px;
  line-height: 1.7;
}
.history-actions b,
.log-facts b {
  color: #1e293b;
}
.logs-head {
  justify-content: flex-start;
  margin-top: 20px;
}
.history-content,
.product-row {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  line-height: 1.7;
}
.history-pagination {
  margin-top: 14px;
  overflow: auto;
  text-align: right;
}
.history-dialog-body {
  max-height: 62vh;
  overflow-y: auto;
}
.log-facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
}
.log-facts span,
.log-facts b {
  display: block;
  overflow-wrap: anywhere;
}
.log-facts span {
  margin-bottom: 6px;
  color: #64748b;
}
.history-dialog-body h4 {
  margin: 20px 0 10px;
  color: #475569;
}
.history-attachments {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}
.history-attachments a,
.legacy-order-history :deep(.el-button--text) {
  color: #008b4b;
}
</style>
