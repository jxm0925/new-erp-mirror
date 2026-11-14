<template>
  <el-dialog :title="editable ? '修正实际金额' : '实际金额修正记录'" :visible="visible" width="960px" top="5vh" append-to-body
    custom-class="purchase-actual-amount-dialog" :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving"
    @close="$emit('update:visible', false)">
    <div class="actual-amount-body">
      <div class="actual-context"><strong>{{ source.source_document_no }}</strong><span>{{ source.supplier_name }}</span><span>{{ source.item_code }} {{ source.item_name }}</span><span>{{ source.spec_model }}</span></div>
      <el-alert v-if="error || recoveryError" :title="recoveryError || error" type="error" :closable="false" show-icon />
      <el-alert v-if="pendingRequest" title="上次确认结果尚未核实，请重试原请求；金额和原因已保留。" type="warning" :closable="false" show-icon />
      <el-tabs v-model="activeTab" @tab-click="tabChanged">
        <el-tab-pane v-if="editable" label="修正实际金额" name="edit">
          <div v-loading="loading">
            <div class="actual-summary"><span>原到货金额<strong>{{ money(facts ? facts.original_amount : source.original_amount) }}</strong></span><span>当前实际金额<strong>{{ money(facts ? facts.current_actual_amount : source.current_actual_amount) }}</strong></span><span>当前应付<strong>{{ money(facts ? facts.current_payable_amount : source.current_payable_amount) }}</strong></span></div>
            <el-form label-position="top" class="actual-form" @submit.native.prevent>
              <el-form-item label="最终实际总额（元）" required :error="amountError">
                <el-input v-model.trim="actualAmount" inputmode="decimal" maxlength="13" placeholder="输入本行最终总额，最多两位小数" :disabled="loading || saving || Boolean(pendingRequest) || Boolean(recoveryError)" @input="invalidatePreview" />
              </el-form-item>
              <el-form-item label="修正原因" required>
                <el-input v-model.trim="reason" type="textarea" :rows="2" maxlength="1000" show-word-limit placeholder="填写本次实际金额或库存成本修正原因" :disabled="loading || saving || Boolean(pendingRequest) || Boolean(recoveryError)" />
              </el-form-item>
            </el-form>
            <div class="actual-preview-action"><el-button type="success" plain size="small" :loading="previewing" :disabled="loading || saving || Boolean(pendingRequest) || Boolean(recoveryError) || Boolean(amountError) || !actualAmount" @click="previewChange">预览金额变化</el-button><el-button v-if="!facts && !loading" size="small" :disabled="saving" @click="loadFacts">重新载入</el-button></div>
            <template v-if="preview">
              <div class="actual-summary actual-impact">
                <span>应付金额<strong>{{ money(preview.current_payable_amount) }} → {{ money(preview.target_payable_amount) }}</strong></span>
                <span>实际金额变动<strong>{{ money(preview.actual_amount_delta) }}</strong></span>
                <span>库存成本<strong>{{ money(preview.old_inventory_cost) }} → {{ money(preview.target_inventory_cost) }}</strong></span>
                <span>{{ preview.inventory_posted ? '在库成本补差' : '待入库成本变动' }}<strong>{{ money(preview.cost_adjustment_amount) }}</strong></span>
                <span v-if="preview.current_order_payment_amount != null">订单付款总额<strong>{{ money(preview.current_order_payment_amount) }} → {{ money(preview.target_order_payment_amount) }}</strong></span>
              </div>
              <ul v-if="preview.block_reasons && preview.block_reasons.length" class="actual-block-reasons"><li v-for="(block, index) in preview.block_reasons" :key="block.code + ':' + index">{{ block.message }}</li></ul>
              <p v-else-if="!canConfirmPreview" class="actual-note">当前金额和库存成本均无需修正。</p>
            </template>
          </div>
        </el-tab-pane>
        <el-tab-pane label="修正记录" name="history">
          <div class="actual-history-toolbar"><el-button size="small" icon="el-icon-refresh" :disabled="historyLoading" @click="loadHistory">刷新记录</el-button></div>
          <el-alert v-if="historyError" :title="historyError" type="error" :closable="false" show-icon />
          <el-table v-loading="historyLoading" :data="historyRows" border size="small" empty-text="暂无实际金额修正记录">
            <el-table-column prop="business_date" label="业务日期" width="105" />
            <el-table-column prop="operator_name" label="操作人" min-width="95" />
            <el-table-column label="实际金额（元）" min-width="180" align="right"><template slot-scope="{ row }">{{ money(row.old_actual_amount) }} → {{ money(row.new_actual_amount) }}</template></el-table-column>
            <el-table-column label="库存成本（元）" min-width="180" align="right"><template slot-scope="{ row }">{{ money(row.old_inventory_cost_amount) }} → {{ money(row.new_inventory_cost_amount) }}</template></el-table-column>
            <el-table-column prop="reason" label="修正原因" min-width="210" />
          </el-table>
          <el-pagination background layout="total, prev, pager, next" :current-page="historyPage" :page-size="10" :pager-count="5" :total="historyTotal" @current-change="changeHistoryPage" />
        </el-tab-pane>
      </el-tabs>
    </div>
    <span slot="footer" class="actual-footer"><el-button :disabled="saving" @click="$emit('update:visible', false)">关闭</el-button><el-button v-if="editable && activeTab === 'edit'" type="success" :loading="saving" :disabled="loading || previewing || Boolean(recoveryError) || (!pendingRequest && (!canConfirmPreview || !reason.trim()))" @click="save">{{ pendingRequest ? '重试确认' : '确认保存' }}</el-button></span>
  </el-dialog>
</template>
<script>
import { previewPurchaseActualAmount, confirmPurchaseActualAmount, listPurchaseActualAmountHistory } from '../../api/erp/finance'
import { allocationKey, money } from '../../utils/financeCashAllocation'
import { paymentErrorMessage } from '../../utils/purchasePayment'
import { completionCommandStore, completionErrorIsDefinitive } from '../../utils/completionCommand'

export default {
  props: { visible: Boolean, source: { type: Object, required: true } },
  data: () => ({ activeTab: 'edit', facts: null, actualAmount: '', reason: '', preview: null, previewedAmount: '', loading: false,
    previewing: false, saving: false, error: '', recoveryError: '', pendingRequest: null, contextRevision: 0, previewRevision: 0,
    historyRows: [], historyPage: 1, historyTotal: 0, historyLoading: false, historyError: '', historyRevision: 0, historyVisited: false }),
  computed: {
    contextKey() { return `${this.visible}:${this.source.id}` },
    editable() { return this.$can('finance.payable.view') && this.$can('finance.payment.confirm') },
    amountError() { return this.actualAmount && !/^\d{1,10}(?:\.\d{1,2})?$/.test(this.actualAmount) ? '请输入非负最终总额，整数最多十位，小数最多两位' : '' },
    canConfirmPreview() { return !!this.preview && this.previewedAmount === this.actualAmount && Boolean(this.preview.can_apply) },
  },
  watch: { contextKey: { immediate: true, handler() { this.reset(); if (this.visible && this.source.id && this.$can('finance.payable.view')) this.open() } } },
  beforeDestroy() { this.contextRevision += 1; this.previewRevision += 1; this.historyRevision += 1 },
  methods: {
    money,
    pendingStore() {
      const user = JSON.parse(localStorage.getItem('erp_user') || '{}')
      const actor = Number(user.legacy_id || user.id || 0)
      if (!actor || !localStorage.getItem('erp_token')) throw new Error('请先登录 ERP')
      return completionCommandStore(sessionStorage, `erp_purchase_actual_amount:${encodeURIComponent(process.env.VUE_APP_BASE_API || '')}:${actor}:${this.source.id}`)
    },
    reset() {
      this.contextRevision += 1; this.previewRevision += 1; this.historyRevision += 1
      this.activeTab = this.editable ? 'edit' : 'history'; this.facts = null; this.preview = null; this.previewedAmount = ''; this.actualAmount = ''; this.reason = ''
      this.loading = false; this.previewing = false; this.saving = false; this.error = ''; this.recoveryError = ''; this.pendingRequest = null
      this.historyRows = []; this.historyPage = 1; this.historyTotal = 0; this.historyLoading = false; this.historyError = ''; this.historyVisited = false
    },
    open() {
      if (this.editable) {
        try {
          const job = this.pendingStore().read()
          if (job) {
            const data = job.payload.data
            if (Number(job.id) !== Number(this.source.id) || !data || data.idempotency_key !== job.payload.client_command_id
              || typeof data.actual_amount !== 'string' || typeof data.reason !== 'string' || !/^[a-f0-9]{64}$/.test(data.expected_fingerprint || '')) throw new Error('上次操作记录无法读取，请联系管理员核对结果。')
            this.restorePending(job.payload.data)
          }
        } catch (error) { this.recoveryError = paymentErrorMessage(error, '上次修正请求读取失败，请核对修正记录') }
        this.loadFacts()
      } else this.loadHistory()
    },
    restorePending(data) { this.pendingRequest = data; this.actualAmount = data.actual_amount; this.reason = data.reason },
    async loadFacts() {
      const context = this.contextRevision
      this.loading = true; this.error = ''
      try {
        const response = await previewPurchaseActualAmount(this.source.id)
        if (context === this.contextRevision) this.facts = response.data.data
      } catch (error) { if (context === this.contextRevision) this.error = paymentErrorMessage(error, '当前金额加载失败') }
      finally { if (context === this.contextRevision) this.loading = false }
    },
    invalidatePreview() { this.previewRevision += 1; this.preview = null; this.previewedAmount = ''; this.previewing = false; this.error = '' },
    async previewChange() {
      if (!this.editable || this.saving || this.loading || this.pendingRequest || this.recoveryError || !this.actualAmount || this.amountError) return
      const context = this.contextRevision
      const revision = ++this.previewRevision
      const amount = this.actualAmount
      this.previewing = true; this.preview = null; this.error = ''
      try {
        const response = await previewPurchaseActualAmount(this.source.id, { actual_amount: amount })
        if (context !== this.contextRevision || revision !== this.previewRevision) return
        this.preview = response.data.data; this.previewedAmount = amount
      } catch (error) { if (context === this.contextRevision && revision === this.previewRevision) this.error = paymentErrorMessage(error, '金额变化预览失败') }
      finally { if (context === this.contextRevision && revision === this.previewRevision) this.previewing = false }
    },
    async save() {
      if (!this.editable || this.saving || this.loading || this.previewing || this.recoveryError) return
      if (!this.pendingRequest && (!this.canConfirmPreview || !this.reason.trim())) return
      const context = this.contextRevision
      const sourceId = this.source.id
      let store
      let posting = false
      this.saving = true; this.error = ''
      try {
        store = this.pendingStore()
        if (!this.pendingRequest) {
          const key = allocationKey()
          const data = { actual_amount: this.actualAmount, reason: this.reason.trim(), expected_fingerprint: this.preview.expected_fingerprint, idempotency_key: key }
          const job = store.prepare({ id: Number(sourceId), payload: { client_command_id: key, data } })
          this.restorePending(job.payload.data)
          if (job.payload.client_command_id !== key) { this.error = '存在尚未确认结果的修正，请核对保留的金额和原因后重试原请求。'; return }
        }
        posting = true
        const response = await confirmPurchaseActualAmount(sourceId, this.pendingRequest)
        store.clear()
        if (context !== this.contextRevision) return
        this.pendingRequest = null; this.facts = response.data.data; this.preview = null; this.actualAmount = ''; this.reason = ''
        this.$message.success(response.data.message || '实际金额已修正')
        this.$emit('changed', response.data.data)
        this.activeTab = 'history'; this.historyPage = 1; this.loadHistory()
      } catch (error) {
        if (context !== this.contextRevision) return
        if (posting && completionErrorIsDefinitive(error)) { store.clear(); this.pendingRequest = null; this.invalidatePreview() }
        this.error = paymentErrorMessage(error, '实际金额修正失败')
        if (posting && this.pendingRequest) this.error += '。请重试原请求以确认结果。'
      } finally { if (context === this.contextRevision) this.saving = false }
    },
    tabChanged(tab) { if (tab.name === 'history' && !this.historyVisited) this.loadHistory() },
    changeHistoryPage(page) { this.historyPage = page; this.loadHistory() },
    async loadHistory() {
      if (!this.visible || !this.$can('finance.payable.view')) return
      const context = this.contextRevision
      const revision = ++this.historyRevision
      this.historyLoading = true; this.historyError = ''; this.historyRows = []
      try {
        const response = await listPurchaseActualAmountHistory(this.source.id, { page: this.historyPage, per_page: 10 })
        if (context !== this.contextRevision || revision !== this.historyRevision) return
        this.historyRows = response.data.data || []; this.historyTotal = Number(response.data.total || 0); this.historyVisited = true
      } catch (error) { if (context === this.contextRevision && revision === this.historyRevision) this.historyError = paymentErrorMessage(error, '修正记录加载失败') }
      finally { if (context === this.contextRevision && revision === this.historyRevision) this.historyLoading = false }
    },
  },
}
</script>
<style>
.purchase-actual-amount-dialog {
  max-width: calc(100vw - 24px);
  max-height: 85vh;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
}
.purchase-actual-amount-dialog .el-dialog__body {
  min-height: 0;
  overflow-y: auto;
  padding: 16px 20px;
}
</style>
<style scoped>
.actual-amount-body,
.actual-summary > span {
  min-width: 0;
  box-sizing: border-box;
}
.actual-context,
.actual-footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}
.actual-context {
  margin-bottom: 14px;
  overflow-wrap: anywhere;
  color: #64748b;
}
.actual-context strong {
  color: #1e293b;
}
.actual-summary {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  padding: 14px;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #f8fafc;
}
.actual-summary span {
  color: #64748b;
  font-size: 13px;
}
.actual-summary strong {
  display: block;
  margin-top: 6px;
  color: #1e293b;
  overflow-wrap: anywhere;
}
.actual-form {
  margin-top: 18px;
}
.actual-form .el-form-item:first-child {
  max-width: 360px;
}
.actual-preview-action,
.actual-history-toolbar {
  margin: 14px 0;
}
.actual-impact {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
.actual-block-reasons {
  color: #b45309;
  line-height: 1.8;
  padding-left: 20px;
}
.actual-note {
  color: #64748b;
  font-size: 13px;
}
.actual-footer {
  justify-content: flex-end;
}
.el-alert {
  margin-bottom: 14px;
}
.el-pagination {
  margin-top: 16px;
  text-align: right;
}
@media (max-width: 640px) {
  .actual-summary {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
