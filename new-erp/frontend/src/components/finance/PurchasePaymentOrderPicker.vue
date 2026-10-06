<template>
  <el-dialog title="选择关联采购订单" :visible="visible" width="1100px" top="5vh" append-to-body
    custom-class="purchase-payment-order-picker" :close-on-click-modal="false" @close="$emit('update:visible', false)">
    <div class="order-picker-body">
      <div class="order-picker-filter"><el-input v-model.trim="keyword" clearable placeholder="输入采购订单号或供应商名称" @keyup.enter.native="search" /><el-button type="success" icon="el-icon-search" @click="search">查询</el-button><el-button @click="resetSearch">重置</el-button></div>
      <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
      <el-table v-loading="loading" :data="rows" border size="small" empty-text="暂无符合条件的已审核采购订单">
        <el-table-column label="选择" width="58" align="center"><template slot-scope="{ row }"><el-checkbox :value="isSelected(row)" :disabled="Boolean(disabledReason(row))" @change="checked => toggle(row, checked)" /></template></el-table-column>
        <el-table-column prop="purchase_order_no" label="采购订单号" min-width="160" />
        <el-table-column prop="supplier_name" label="供应商" min-width="140" />
        <el-table-column prop="currency" label="币种" width="70" />
        <el-table-column label="合同总额" min-width="105" align="right"><template slot-scope="{ row }">{{ money(row.contract_amount) }}</template></el-table-column>
        <el-table-column label="已付净额" min-width="105" align="right"><template slot-scope="{ row }">{{ money(row.net_paid_amount) }}</template></el-table-column>
        <el-table-column label="合同待付" min-width="105" align="right"><template slot-scope="{ row }">{{ money(row.contract_unpaid_amount) }}</template></el-table-column>
        <el-table-column label="状态" min-width="145"><template slot-scope="{ row }">{{ disabledReason(row) || (isSelected(row) ? '已选择' : '可选择') }}</template></el-table-column>
      </el-table>
      <el-pagination background layout="total, prev, pager, next" :current-page="page" :page-size="10" :pager-count="5" :total="total" @current-change="changePage" />
      <div v-if="selected.length" class="order-picker-selected"><b>已选 {{ selected.length }} 张</b><el-tag v-for="row in selected" :key="orderId(row)" closable type="success" @close="toggle(row, false)">{{ row.purchase_order_no }}</el-tag></div>
    </div>
    <span slot="footer"><el-button @click="$emit('update:visible', false)">取消</el-button><el-button type="success" :disabled="!selected.length" @click="confirm">确认选择（{{ selected.length }}）</el-button></span>
  </el-dialog>
</template>
<script>
import { listPurchasePaymentOrders } from '../../api/erp/purchasePayments'
import { money } from '../../utils/financeCashAllocation'
import { paymentErrorMessage } from '../../utils/purchasePayment'

export default {
  props: { visible: Boolean, supplierId: { type: Number, default: 0 }, currency: { type: String, required: true }, existing: { type: Array, default: () => [] } },
  data: () => ({ loading: false, rows: [], selected: [], keyword: '', page: 1, total: 0, error: '', requestRevision: 0 }),
  computed: { contextKey() { return `${this.visible}:${this.supplierId}:${this.currency}` } },
  watch: { contextKey: { immediate: true, handler() { this.reset(); if (this.visible) this.load() } } },
  beforeDestroy() { this.requestRevision += 1 },
  methods: {
    money,
    orderId(row) { return Number(row.purchase_order_id || row.id) },
    reset() { this.requestRevision += 1; this.loading = false; this.rows = []; this.selected = []; this.keyword = ''; this.page = 1; this.total = 0; this.error = '' },
    isSelected(row) { return this.selected.some(item => this.orderId(item) === this.orderId(row)) },
    disabledReason(row) {
      if (Number(row.supplier_id) !== this.supplierId) return '供应商不一致'
      if (row.currency !== this.currency) return '币种不一致'
      return this.existing.some(item => Number(item.purchase_order_id) === this.orderId(row)) ? '已关联，可继续添加期次' : ''
    },
    toggle(row, checked) {
      if (!checked) this.selected = this.selected.filter(item => this.orderId(item) !== this.orderId(row))
      else if (!this.disabledReason(row) && !this.isSelected(row)) {
        if (this.selected.length + this.existing.length >= 100) return this.$message.warning('每笔资金最多关联 100 条订单用途')
        this.selected.push({ ...row })
      }
    },
    search() { this.page = 1; return this.load() },
    resetSearch() { this.keyword = ''; return this.search() },
    changePage(page) { this.page = page; return this.load() },
    async load() {
      const revision = ++this.requestRevision
      this.rows = []
      this.error = ''
      if (!this.supplierId || !this.currency) { this.total = 0; this.loading = false; return }
      this.loading = true
      try {
        const response = await listPurchasePaymentOrders({ supplier_id: this.supplierId, currency: this.currency, keyword: this.keyword, page: this.page, per_page: 10 })
        if (revision !== this.requestRevision) return
        this.rows = response.data.data || []
        this.total = Number(response.data.total || 0)
      } catch (error) { if (revision === this.requestRevision) { this.error = paymentErrorMessage(error, '采购订单加载失败'); this.total = 0 } }
      finally { if (revision === this.requestRevision) this.loading = false }
    },
    confirm() { this.$emit('confirm', this.selected.map(row => ({ ...row }))); this.$emit('update:visible', false) },
  },
}
</script>
<style>
.purchase-payment-order-picker {
  max-width: calc(100vw - 24px);
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
}
.purchase-payment-order-picker .el-dialog__body {
  min-height: 0;
  overflow-y: auto;
  padding: 16px 20px;
}
</style>
<style scoped>
.order-picker-body,
.order-picker-filter > * {
  min-width: 0;
  box-sizing: border-box;
}
.order-picker-filter {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  gap: 10px;
  margin-bottom: 12px;
}
.order-picker-filter .el-button + .el-button {
  margin-left: 0;
}
.el-pagination {
  margin-top: 14px;
  overflow-x: auto;
}
.order-picker-selected {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
}
.el-alert {
  margin-bottom: 12px;
}
@media (max-width: 620px) {
  .order-picker-filter {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .order-picker-filter .el-input {
    grid-column: 1 / -1;
  }
}
</style>
