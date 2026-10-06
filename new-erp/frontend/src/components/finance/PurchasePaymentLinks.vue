<template>
  <div class="purchase-payment-links">
    <div class="purchase-links-heading"><strong>关联采购订单</strong><el-button v-if="!readonly" type="success" size="small" icon="el-icon-plus" :disabled="disabled" @click="openPicker">选择采购订单</el-button></div>
    <el-table :data="value" border size="small" empty-text="尚未关联采购订单">
      <el-table-column label="采购订单" min-width="155"><template slot-scope="{ row }">{{ orderName(row) }}<small v-if="orderErrors[row.purchase_order_id]" class="link-error">{{ orderErrors[row.purchase_order_id] }}</small></template></el-table-column>
      <el-table-column label="付款期次" min-width="190"><template slot-scope="{ row, $index }">
        <el-select v-if="!readonly" :value="row.payment_plan_id || 0" :disabled="disabled || loadingFacts" @change="id => planChanged($index, id)">
          <el-option :value="0" label="未指定期次" />
          <el-option v-for="plan in plansFor(row)" :key="plan.id" :value="Number(plan.id)" :label="`第${plan.sequence_no}期 · ${triggerLabel(plan.trigger_type)}`" />
        </el-select>
        <span v-else>{{ row.payment_plan_id ? `第${row.sequence_no || '—'}期 · ${triggerLabel(row.trigger_type)}` : '未指定期次' }}</span>
      </template></el-table-column>
      <el-table-column label="付款条件 / 日期" min-width="165"><template slot-scope="{ row }">{{ conditionText(row) }}</template></el-table-column>
      <el-table-column :label="direction === 'receipt' ? '关联退款金额' : '关联付款金额'" min-width="145" align="right"><template slot-scope="{ row, $index }"><el-input v-if="!readonly" :value="row.amount" :disabled="disabled" @input="amount => patchRow($index, { amount })" /><span v-else>{{ money(row.amount) }}</span></template></el-table-column>
      <el-table-column v-if="!readonly" label="操作" width="145"><template slot-scope="{ $index }"><el-button type="text" :disabled="disabled || loadingFacts" @click="addInstallment($index)">添加期次</el-button><el-button type="text" class="danger" :disabled="disabled" @click="removeRow($index)">移除</el-button></template></el-table-column>
    </el-table>
    <div class="purchase-links-total"><span>关联合计：<b>{{ money(total) }} {{ currency }}</b></span><span>资金金额：<b>{{ money(amount) }} {{ currency }}</b></span></div>
    <p v-if="validationError && !readonly" class="link-error">{{ validationError }}</p>
    <p class="purchase-links-note">关联用于记录采购用途；实际核销按结算来源单独办理。</p>
    <purchase-payment-order-picker :visible.sync="pickerVisible" :supplier-id="supplierId" :currency="currency" :existing="value" @confirm="addOrders" />
  </div>
</template>
<script>
import { getPurchasePaymentPlan } from '../../api/erp/purchasePayments'
import { amountUnits, money } from '../../utils/financeCashAllocation'
import { paymentTriggerLabel, purchaseAllocationTotal, validatePurchaseOrderAllocations, purchaseOrderCanPay, paymentErrorMessage } from '../../utils/purchasePayment'
import PurchasePaymentOrderPicker from './PurchasePaymentOrderPicker.vue'

export default {
  components: { PurchasePaymentOrderPicker },
  props: {
    value: { type: Array, default: () => [] }, supplierId: { type: Number, default: 0 },
    currency: { type: String, required: true }, amount: { type: [Number, String], default: '' },
    direction: { type: String, default: 'payment' }, readonly: Boolean, disabled: Boolean,
  },
  data: () => ({ pickerVisible: false, facts: {}, orderErrors: {}, loadingFacts: false, requestRevision: 0 }),
  computed: {
    orderIds() { return [...new Set(this.value.map(row => Number(row.purchase_order_id)).filter(Boolean))] },
    contextKey() { return `${this.supplierId}:${this.currency}:${this.orderIds.join(',')}` },
    total() { return purchaseAllocationTotal(this.value) },
    validationError() {
      const error = validatePurchaseOrderAllocations(this.value, this.amount)
      if (error) return error
      if (this.loadingFacts && this.value.length) return '正在核对采购订单，请稍候'
      return Object.values(this.orderErrors).find(Boolean) || ''
    },
  },
  watch: { contextKey: { immediate: true, handler() { this.pickerVisible = false; this.loadFacts() } } },
  beforeDestroy() { this.requestRevision += 1 },
  methods: {
    money,
    triggerLabel: paymentTriggerLabel,
    validate() { return this.readonly ? '' : this.validationError },
    orderName(row) { return row.purchase_order_no || this.facts[row.purchase_order_id]?.purchase_order_no || `采购订单 #${row.purchase_order_id}` },
    plansFor(row) { return this.facts[row.purchase_order_id]?.items || [] },
    conditionText(row) {
      const plan = this.plansFor(row).find(item => Number(item.id) === Number(row.payment_plan_id))
      if (!row.payment_plan_id) return '未指定期次'
      return `${paymentTriggerLabel(plan?.trigger_type || row.trigger_type)} / ${plan?.due_date || row.due_date || '日期待定'}`
    },
    async loadFacts() {
      const revision = ++this.requestRevision
      this.facts = {}
      this.orderErrors = {}
      const ids = this.orderIds
      if (!ids.length) { this.loadingFacts = false; return }
      this.loadingFacts = true
      // 只取已关联订单的安排，分组读取避免多订单资金单同时发出大量请求。
      for (let offset = 0; offset < ids.length; offset += 4) {
        await Promise.all(ids.slice(offset, offset + 4).map(async id => {
          try {
            const response = await getPurchasePaymentPlan(id)
            if (revision !== this.requestRevision) return
            const fact = response.data.data
            if (Number(fact.supplier_id) !== this.supplierId || fact.currency !== this.currency) throw new Error('采购订单的供应商或币种与资金单不一致')
            if (!this.readonly && !purchaseOrderCanPay(fact)) throw new Error('采购订单尚未审核完成或已取消，不能关联付款')
            this.$set(this.facts, id, fact)
            const missing = this.value.some(row => Number(row.purchase_order_id) === id && row.payment_plan_id && !(fact.items || []).some(item => Number(item.id) === Number(row.payment_plan_id)))
            if (missing) this.$set(this.orderErrors, id, '关联期次已失效，请重新选择')
          } catch (error) { if (revision === this.requestRevision) this.$set(this.orderErrors, id, paymentErrorMessage(error, '采购订单加载失败，请移除后重新选择')) }
        }))
        if (revision !== this.requestRevision) return
      }
      if (revision === this.requestRevision) this.loadingFacts = false
    },
    openPicker() {
      if (this.readonly || this.disabled) return
      if (!this.supplierId) return this.$message.warning('请先选择供应商')
      if (!(amountUnits(this.amount) > 0)) return this.$message.warning('请先填写收付款金额')
      this.pickerVisible = true
    },
    emitRows(rows) { this.$emit('input', rows.map(row => ({ ...row }))) },
    patchRow(index, changes) {
      if (this.readonly || this.disabled) return
      this.emitRows(this.value.map((row, i) => i === index ? { ...row, ...changes } : row))
    },
    planChanged(index, id) {
      if (this.readonly || this.disabled) return
      const row = this.value[index]
      const plan = this.plansFor(row).find(item => Number(item.id) === Number(id))
      const rows = this.value.map((item, i) => i === index ? { ...item, payment_plan_id: Number(id) || null, sequence_no: plan?.sequence_no || null, trigger_type: plan?.trigger_type || null, due_date: plan?.due_date || null } : item)
      this.emitRows(rows)
      if (this.facts[row.purchase_order_id]) {
        const missing = rows.some(item => Number(item.purchase_order_id) === Number(row.purchase_order_id) && item.payment_plan_id && !this.plansFor(item).some(part => Number(part.id) === Number(item.payment_plan_id)))
        if (missing) this.$set(this.orderErrors, row.purchase_order_id, '关联期次已失效，请重新选择')
        else this.$delete(this.orderErrors, row.purchase_order_id)
      }
    },
    addOrders(orders) {
      if (this.readonly || this.disabled) return
      const rows = this.value.map(row => ({ ...row }))
      let remaining = (amountUnits(this.amount) || 0) - Math.round(purchaseAllocationTotal(rows) * 10000)
      for (const order of orders) {
        const id = Number(order.purchase_order_id || order.id)
        if (Number(order.supplier_id) !== this.supplierId || order.currency !== this.currency) { this.$message.warning('只能关联同一供应商、同一币种的采购订单'); continue }
        if (rows.some(row => Number(row.purchase_order_id) === id)) continue
        const suggested = amountUnits(this.direction === 'receipt' ? order.net_paid_amount : order.contract_unpaid_amount) || 0
        const value = Math.max(0, Math.min(remaining, suggested > 0 ? suggested : remaining))
        rows.push({ purchase_order_id: id, purchase_order_no: order.purchase_order_no, payment_plan_id: null, amount: (value / 10000).toFixed(4) })
        remaining -= value
      }
      this.emitRows(rows)
    },
    addInstallment(index) {
      if (this.readonly || this.disabled || this.loadingFacts) return
      if (this.value.length >= 100) return this.$message.warning('每笔资金最多关联 100 条订单用途')
      const row = this.value[index]
      const plan = this.plansFor(row).find(item => !this.value.some(link => Number(link.purchase_order_id) === Number(row.purchase_order_id) && Number(link.payment_plan_id) === Number(item.id)))
      if (!plan) return this.$message.warning('该订单没有可添加的其他付款期次，请先维护付款安排')
      this.emitRows([...this.value, { purchase_order_id: row.purchase_order_id, purchase_order_no: this.orderName(row), payment_plan_id: plan.id, sequence_no: plan.sequence_no, trigger_type: plan.trigger_type, due_date: plan.due_date || null, amount: '0.0000' }])
    },
    removeRow(index) { if (!this.readonly && !this.disabled) this.emitRows(this.value.filter((row, i) => i !== index)) },
  },
}
</script>
<style scoped>
.purchase-payment-links {
  min-width: 0;
  box-sizing: border-box;
}
.purchase-links-heading,
.purchase-links-total {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.purchase-links-heading {
  margin-bottom: 12px;
}
.purchase-links-heading strong {
  font-size: 16px;
}
.purchase-links-total {
  padding: 14px 0 2px;
  color: #475569;
  font-size: 14px;
}
.purchase-links-total b {
  color: #008b4b;
}
.purchase-links-note {
  color: #64748b;
  font-size: 13px;
  line-height: 1.6;
}
.el-select {
  width: 100%;
}
.link-error {
  display: block;
  color: #b45309;
  line-height: 1.5;
  overflow-wrap: anywhere;
  font-size: 13px;
}
.danger {
  color: #ef4444;
}
</style>
