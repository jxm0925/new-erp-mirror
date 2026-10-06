<template>
  <el-dialog title="采购付款安排" :visible="visible" width="1180px" top="5vh" append-to-body
    custom-class="purchase-payment-plan-dialog" :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving"
    @close="$emit('update:visible', false)">
    <div class="payment-plan-body" v-loading="loading">
      <el-alert v-if="error" :title="error" :type="conflict ? 'warning' : 'error'" :closable="false" show-icon />
      <template v-if="plan">
        <div class="plan-context"><strong>{{ plan.purchase_order_no }}</strong><span>{{ plan.supplier_name }}</span><span>{{ plan.currency }}</span></div>
        <div class="plan-summary">
          <span>合同总额<strong>{{ money(plan.contract_amount) }}</strong></span>
          <span>已安排<strong>{{ money(plannedTotal) }}</strong></span>
          <span>未安排<strong>{{ money(unplannedAmount) }}</strong></span>
          <span>已付款<strong>{{ money(plan.paid_amount) }}</strong></span>
          <span>已退款<strong>{{ money(plan.refund_amount) }}</strong></span>
          <span>合同待付<strong>{{ money(plan.contract_unpaid_amount) }}</strong></span>
        </div>
        <p class="plan-note">付款安排用于约定付款条件。保存安排不会产生付款或应付；日期未知可留空。</p>
        <div class="plan-toolbar"><b>付款期次</b><el-button v-if="editable" size="small" type="success" icon="el-icon-plus" :disabled="saving" @click="addRow">添加期次</el-button></div>
        <el-table :data="items" border size="small" empty-text="尚未安排付款">
          <el-table-column label="期次" width="65" align="center"><template slot-scope="{ row, $index }">{{ row.sequence_no || $index + 1 }}</template></el-table-column>
          <el-table-column label="付款条件" min-width="138"><template slot-scope="{ row }"><el-select v-if="editable" v-model="row.trigger_type" :disabled="saving"><el-option v-for="option in triggers" :key="option.value" :value="option.value" :label="option.label" /></el-select><span v-else>{{ triggerLabel(row.trigger_type) }}</span></template></el-table-column>
          <el-table-column label="安排金额" min-width="120" align="right"><template slot-scope="{ row }"><el-input v-if="editable" v-model.trim="row.amount" :disabled="saving" /><span v-else>{{ money(row.amount) }}</span></template></el-table-column>
          <el-table-column label="约定付款日期" min-width="162"><template slot-scope="{ row }"><el-date-picker v-if="editable" v-model="row.due_date" :disabled="saving" type="date" value-format="yyyy-MM-dd" placeholder="日期未知可留空" clearable /><span v-else>{{ row.due_date || '日期待定' }}</span></template></el-table-column>
          <el-table-column label="说明" min-width="165"><template slot-scope="{ row }"><el-input v-if="editable" v-model.trim="row.remark" :disabled="saving" type="textarea" :autosize="{ minRows: 1, maxRows: 3 }" maxlength="1000" placeholder="例如：厂家排产订金" /><span v-else>{{ row.remark || '—' }}</span></template></el-table-column>
          <el-table-column label="已付 / 退款" min-width="130" align="right"><template slot-scope="{ row }">{{ money(row.paid_amount) }} / {{ money(row.refund_amount) }}</template></el-table-column>
          <el-table-column label="待付" min-width="110" align="right"><template slot-scope="{ row }">{{ row.id ? money(row.remaining_amount) : '—' }}</template></el-table-column>
          <el-table-column label="操作" width="148"><template slot-scope="{ row, $index }">
            <el-button v-if="canStartPayment && row.id && Number(row.remaining_amount) > 0" type="text" :disabled="saving || dirty || conflict" @click="startPayment(row)">发起付款</el-button>
            <el-button v-if="editable" type="text" class="danger" :disabled="saving || Number(row.paid_amount) > 0 || Number(row.refund_amount) > 0" @click="removeRow($index)">移除</el-button>
          </template></el-table-column>
        </el-table>
        <p v-if="validationError" class="plan-validation">{{ validationError }}</p>
      </template>
    </div>
    <span slot="footer" class="plan-footer">
      <el-button v-if="conflict || (!plan && error)" :disabled="saving || loading" @click="load">重新载入</el-button>
      <el-button :disabled="saving" @click="$emit('update:visible', false)">关闭</el-button>
      <el-button v-if="editable" type="success" :loading="saving" :disabled="loading || conflict || Boolean(validationError)" @click="save">保存付款安排</el-button>
    </span>
  </el-dialog>
</template>
<script>
import { getPurchasePaymentPlan, savePurchasePaymentPlan } from '../../api/erp/purchasePayments'
import { amountUnits, money } from '../../utils/financeCashAllocation'
import { paymentTriggers, paymentTriggerLabel, serializePaymentPlan, validatePaymentPlan, purchaseOrderCanPay, paymentErrorMessage } from '../../utils/purchasePayment'

export default {
  props: { visible: Boolean, orderId: { type: Number, default: 0 } },
  data: () => ({ loading: false, saving: false, plan: null, items: [], baseline: '', error: '', conflict: false, requestRevision: 0, triggers: paymentTriggers }),
  computed: {
    contextKey() { return `${this.visible}:${this.orderId}` },
    editable() { return !!this.plan && this.plan.purchase_status !== 'cancelled' && this.$can('purchase.order.edit') },
    canStartPayment() { return purchaseOrderCanPay(this.plan) && this.$can('finance.payment.create') && this.$can('finance.view') },
    dirty() { return JSON.stringify(serializePaymentPlan(this.items)) !== this.baseline },
    plannedTotal() { return this.items.reduce((sum, row) => sum + (amountUnits(row.amount) || 0), 0) / 10000 },
    unplannedAmount() { return ((amountUnits(this.plan?.contract_amount) || 0) - Math.round(this.plannedTotal * 10000)) / 10000 },
    validationError() { return this.plan ? validatePaymentPlan(this.items, this.plan.contract_amount) : '' },
  },
  watch: { contextKey: { immediate: true, handler() { this.reset(); if (this.visible && this.orderId) this.load() } } },
  beforeDestroy() { this.requestRevision += 1 },
  methods: {
    money,
    triggerLabel: paymentTriggerLabel,
    reset() { this.requestRevision += 1; this.plan = null; this.items = []; this.baseline = ''; this.error = ''; this.conflict = false; this.loading = false },
    applyPlan(plan) { this.plan = plan; this.items = (plan.items || []).map(row => ({ ...row, due_date: row.due_date || null })); this.baseline = JSON.stringify(serializePaymentPlan(this.items)); this.conflict = false },
    async load() {
      const revision = ++this.requestRevision
      this.loading = true
      this.error = ''
      try {
        const response = await getPurchasePaymentPlan(this.orderId)
        if (revision === this.requestRevision) this.applyPlan(response.data.data)
      } catch (error) { if (revision === this.requestRevision) this.error = paymentErrorMessage(error, '付款安排加载失败') }
      finally { if (revision === this.requestRevision) this.loading = false }
    },
    addRow() {
      if (!this.editable || this.saving) return
      if (this.items.length >= 100) return this.$message.warning('每张采购订单最多安排 100 期付款')
      this.items.push({ trigger_type: 'to_be_agreed', amount: '', due_date: null, remark: '' })
    },
    removeRow(index) {
      const row = this.items[index]
      if (!this.editable || this.saving || !row || Number(row.paid_amount) > 0 || Number(row.refund_amount) > 0) return
      this.items.splice(index, 1)
    },
    async save() {
      if (!this.editable || this.saving || this.loading || this.conflict) return
      if (this.validationError) return this.$message.warning(this.validationError)
      const revision = this.requestRevision
      this.saving = true
      this.error = ''
      try {
        const response = await savePurchasePaymentPlan(this.orderId, { version: this.plan.version, items: serializePaymentPlan(this.items) })
        if (revision !== this.requestRevision) return
        this.applyPlan(response.data.data)
        this.$message.success('付款安排已保存')
        this.$emit('changed', this.plan)
      } catch (error) {
        if (revision !== this.requestRevision) return
        this.conflict = error?.response?.status === 409 || Boolean(error?.response?.data?.errors?.version)
        this.error = this.conflict ? '付款安排已被其他人修改。当前输入已保留，请重新载入最新安排后核对。' : paymentErrorMessage(error, '付款安排保存失败')
      } finally { this.saving = false }
    },
    startPayment(row) {
      if (!this.canStartPayment || this.saving || this.dirty || this.conflict || !row.id || !(Number(row.remaining_amount) > 0)) return
      this.$router.push({ path: '/finance/payments/create', query: { purchase_order_id: this.orderId, payment_plan_id: row.id } })
    },
  },
}
</script>
<style>
.purchase-payment-plan-dialog {
  max-width: calc(100vw - 24px);
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
}
.purchase-payment-plan-dialog .el-dialog__body {
  min-height: 0;
  overflow-y: auto;
  padding: 16px 20px;
}
</style>
<style scoped>
.payment-plan-body,
.plan-summary > span {
  min-width: 0;
  box-sizing: border-box;
}
.plan-context,
.plan-toolbar,
.plan-footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}
.plan-context {
  margin-bottom: 14px;
  overflow-wrap: anywhere;
}
.plan-summary {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  padding: 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}
.plan-summary span {
  color: #64748b;
  font-size: 13px;
}
.plan-summary strong {
  display: block;
  color: #1e293b;
  margin-top: 6px;
  overflow-wrap: anywhere;
}
.plan-note {
  color: #64748b;
  font-size: 13px;
  line-height: 1.6;
}
.plan-toolbar {
  justify-content: space-between;
  margin: 14px 0;
}
.plan-footer {
  justify-content: flex-end;
}
.el-select,
.el-date-editor {
  width: 100%;
  min-width: 0;
}
.el-alert {
  margin-bottom: 14px;
}
.danger {
  color: #ef4444;
}
.plan-validation {
  color: #b45309;
  font-size: 13px;
}
@media (max-width: 720px) {
  .plan-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
