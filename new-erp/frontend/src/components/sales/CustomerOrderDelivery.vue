<template>
  <section v-loading="loading" class="customer-delivery">
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <div v-if="order" class="delivery-summary">
      <el-tag v-if="order.confirmed_at" size="mini" type="success">订单生效 {{ date(order.confirmed_at) }}</el-tag>
      <el-tag size="mini" type="info">{{ fulfillmentText(order.fulfillment_status) }}</el-tag>
      <span v-if="summary" class="delivery-muted">生产履约 {{ summary.production_fulfillment_count }} 项</span>
    </div>
    <el-table :data="rows" size="small">
      <el-table-column label="工单 / 关联" min-width="190"><template slot-scope="{row}"><b>{{ row.work_order_no }}</b><span class="delivery-sub">{{ relationText(row) }}{{ row.parent_work_order_no ? ' · ' + row.parent_work_order_no : '' }}{{ row.rework_of_work_order_no ? ' · ' + row.rework_of_work_order_no : '' }}</span></template></el-table-column>
      <el-table-column label="商品 / 订单行" min-width="170"><template slot-scope="{row}">{{ row.product_name || row.item_name || row.order_product_name || '—' }}<span class="delivery-sub">{{ row.sku_name || '' }} · 订单第 {{ row.line_no }} 行</span></template></el-table-column>
      <el-table-column label="计划 / 完工 / 入库" min-width="150" align="right"><template slot-scope="{row}">{{ quantity(row.planned_qty) }} / {{ quantity(row.completed_qty) }} / {{ quantity(row.stored_qty) }}</template></el-table-column>
      <el-table-column prop="status_label" label="状态" min-width="90" />
      <el-table-column label="工序进度" min-width="250"><template slot-scope="{row}"><span v-for="(operation, index) in row.progress || []" :key="index" class="operation-progress">{{ operation.operation_name }} {{ quantity(operation.completed_qty) }}/{{ quantity(operation.planned_qty) }}</span><span v-if="!(row.progress || []).length" class="delivery-muted">—</span></template></el-table-column>
      <el-table-column label="操作" width="100"><template slot-scope="{row}"><el-button v-if="row.can_view" type="text" size="mini" @click="viewWorkOrder(row)">查看工单</el-button></template></el-table-column>
      <template slot="empty"><span class="delivery-muted">{{ error || '暂无关联生产工单' }}</span></template>
    </el-table>
    <div class="delivery-pager"><el-pagination background layout="total, sizes, prev, pager, next" :total="total" :current-page.sync="page" :page-size="perPage" :page-sizes="[10,20,50]" @current-change="load" @size-change="changeSize" /></div>
  </section>
</template>

<script>
import { listCustomerOrderDelivery, crmDate, crmError } from '@/api/erp/crm'

export default {
  name: 'CustomerOrderDelivery',
  props: { customerId: { type: [Number, String], required: true }, orderId: { type: [Number, String], required: true } },
  data: () => ({ rows: [], total: 0, page: 1, perPage: 10, order: null, summary: null, loading: false, error: '', sequence: 0 }),
  created() { this.load() },
  beforeDestroy() { this.sequence += 1 },
  methods: {
    date: crmDate,
    quantity(value) { return value === null || value === undefined ? '—' : Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 4 }) },
    relationText(row) { return ({ order: '订单工单', child: '子工单', rework: '返工工单' })[row.relation] || '—' },
    fulfillmentText(value) { return ({ pending: '待交付', partial: '部分交付', fulfilled: '已交付', cancelled: '已取消' })[value] || value || '—' },
    changeSize(size) { this.perPage = size; this.page = 1; this.load() },
    async load() {
      const sequence = ++this.sequence
      this.loading = true
      this.error = ''
      this.rows = []
      this.total = 0
      this.order = null
      this.summary = null
      try {
        const { data } = await listCustomerOrderDelivery(this.customerId, this.orderId, { page: this.page, per_page: this.perPage })
        if (sequence !== this.sequence) return
        this.rows = data.data || []
        this.total = data.total || 0
        this.order = data.order || null
        this.summary = data.summary || null
      } catch (error) { if (sequence === this.sequence) this.error = crmError(error, '生产交付加载失败') }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    viewWorkOrder(row) {
      if (!row.can_view) return
      this.$router.push(`/production/work-orders/${row.id}`)
      this.$emit('navigate')
    }
  }
}
</script>

<style scoped>
.customer-delivery {
  min-width: 0;
  padding: 8px 12px;
  background: #f8fafc;
  box-sizing: border-box;
}
.delivery-summary {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 10px;
}
.delivery-muted, .delivery-sub {
  color: #64748b;
  font-size: 12px;
}
.delivery-sub {
  display: block;
  margin-top: 4px;
}
.operation-progress {
  display: inline-block;
  margin: 2px 10px 2px 0;
}
.delivery-pager {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  margin-top: 8px;
}
</style>
