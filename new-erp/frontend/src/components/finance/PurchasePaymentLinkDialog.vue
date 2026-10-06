<template>
  <el-dialog title="采购订单关联" :visible="visible" width="1080px" top="5vh" append-to-body
    custom-class="purchase-payment-link-dialog" :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving"
    @close="$emit('update:visible', false)">
    <div v-loading="loading" class="payment-link-dialog-body">
      <el-alert v-if="error" :title="error" :type="conflict ? 'warning' : 'error'" :closable="false" show-icon />
      <template v-if="doc">
        <div class="link-cash-context"><strong>{{ doc.document_no }}</strong><span>{{ doc.party_name_snapshot }}</span><span>{{ doc.direction === 'receipt' ? '退款收款' : '付款' }} {{ money(doc.amount) }} {{ doc.currency }}</span></div>
        <purchase-payment-links ref="links" v-model="rows" :supplier-id="Number(doc.party_id)" :currency="doc.currency" :amount="doc.amount" :direction="doc.direction" :readonly="!editable" :disabled="saving || loading || conflict" />
        <el-form v-if="editable" label-position="top" class="link-reason-form"><el-form-item label="本次补充或调整原因（必填）"><el-input v-model.trim="reason" type="textarea" :rows="2" maxlength="1000" show-word-limit :disabled="saving" placeholder="说明关联调整原因；超合同付款也请在此说明" /></el-form-item></el-form>
        <p v-else-if="doc.purchase_order_allocation_reason" class="link-reason-text">关联说明：{{ doc.purchase_order_allocation_reason }}</p>
      </template>
    </div>
    <span slot="footer" class="link-dialog-footer"><el-button v-if="conflict || (!doc && error)" :disabled="saving || loading" @click="load">重新载入</el-button><el-button :disabled="saving" @click="$emit('update:visible', false)">关闭</el-button><el-button v-if="editable" type="success" :loading="saving" :disabled="loading || conflict" @click="save">保存关联</el-button></span>
  </el-dialog>
</template>
<script>
import { getCashDocument } from '../../api/erp/finance'
import { updateCashPurchaseOrders } from '../../api/erp/purchasePayments'
import { allocationKey, money } from '../../utils/financeCashAllocation'
import { serializePurchaseOrderAllocations, validatePurchaseOrderAllocations, paymentErrorMessage } from '../../utils/purchasePayment'
import PurchasePaymentLinks from './PurchasePaymentLinks.vue'

export default {
  components: { PurchasePaymentLinks },
  props: { visible: Boolean, cashId: { type: Number, default: 0 } },
  data: () => ({ loading: false, saving: false, doc: null, rows: [], reason: '', error: '', conflict: false, requestRevision: 0, idempotencyKey: '' }),
  computed: {
    contextKey() { return `${this.visible}:${this.cashId}` },
    editable() { return this.doc?.party_type === 'supplier' && this.doc?.status === 'confirmed' && this.$can(`finance.${this.doc.direction}.confirm`) },
  },
  watch: { contextKey: { immediate: true, handler() { this.reset(); if (this.visible && this.cashId) this.load() } } },
  beforeDestroy() { this.requestRevision += 1 },
  methods: {
    money,
    reset() { this.requestRevision += 1; this.doc = null; this.rows = []; this.reason = ''; this.error = ''; this.conflict = false; this.loading = false; this.idempotencyKey = allocationKey() },
    applyDocument(doc) { this.doc = doc; this.rows = (doc.purchase_order_allocations || []).map(row => ({ ...row })); this.reason = ''; this.conflict = false; this.idempotencyKey = allocationKey() },
    async load() {
      const revision = ++this.requestRevision
      this.loading = true
      this.error = ''
      try {
        const response = await getCashDocument(this.cashId)
        if (revision !== this.requestRevision) return
        if (response.data.data.party_type !== 'supplier') throw new Error('仅供应商收付款可以关联采购订单')
        this.applyDocument(response.data.data)
      } catch (error) { if (revision === this.requestRevision) this.error = paymentErrorMessage(error, '收付款单加载失败') }
      finally { if (revision === this.requestRevision) this.loading = false }
    },
    async save() {
      if (!this.editable || this.saving || this.loading || this.conflict) return
      const validation = validatePurchaseOrderAllocations(this.rows, this.doc.amount, this.doc.party_type) || this.$refs.links?.validate()
      if (validation) return this.$message.warning(validation)
      if (!this.reason.trim()) return this.$message.warning('请填写本次补充或调整原因')
      const revision = this.requestRevision
      this.saving = true
      this.error = ''
      try {
        const response = await updateCashPurchaseOrders(this.cashId, {
          version: this.doc.purchase_order_allocation_version,
          idempotency_key: this.idempotencyKey,
          reason: this.reason.trim(),
          purchase_order_allocations: serializePurchaseOrderAllocations(this.rows),
        })
        if (revision !== this.requestRevision) return
        this.applyDocument(response.data.data)
        this.$message.success('采购订单关联已保存')
        this.$emit('changed', this.doc)
      } catch (error) {
        if (revision !== this.requestRevision) return
        this.conflict = error?.response?.status === 409 || Boolean(error?.response?.data?.errors?.version)
        this.error = this.conflict ? '采购订单关联已发生变化。当前输入已保留，请重新载入后核对。' : paymentErrorMessage(error, '采购订单关联保存失败')
      } finally { this.saving = false }
    },
  },
}
</script>
<style>
.purchase-payment-link-dialog {
  max-width: calc(100vw - 24px);
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
}
.purchase-payment-link-dialog .el-dialog__body {
  min-height: 0;
  overflow-y: auto;
  padding: 16px 20px;
}
</style>
<style scoped>
.payment-link-dialog-body {
  min-width: 0;
  box-sizing: border-box;
}
.link-cash-context,
.link-dialog-footer {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.link-cash-context {
  margin-bottom: 18px;
  overflow-wrap: anywhere;
}
.link-dialog-footer {
  justify-content: flex-end;
}
.el-alert {
  margin-bottom: 14px;
}
.link-reason-form {
  margin-top: 14px;
}
.link-reason-text {
  color: #64748b;
  line-height: 1.6;
  overflow-wrap: anywhere;
}
</style>
