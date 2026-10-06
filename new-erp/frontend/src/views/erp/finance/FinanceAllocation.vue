<template>
  <div class="allocation-page" :class="{ 'is-embedded': embedded }" v-loading="loading">
    <div v-if="!embedded" class="allocation-heading"><h1>收付款核销</h1><el-button @click="back">返回资金单</el-button></div>
    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" show-icon />
    <template v-if="doc.id">
      <div class="cash-summary">
        <span>资金单号<b>{{ doc.document_no }}</b></span><span>交易对手<b>{{ doc.party_name_snapshot }}</b></span>
        <span>资金金额<b>{{ money(doc.amount) }} {{ doc.currency }}</b></span><span>已核销<b>{{ money(doc.allocated_amount) }}</b></span><span>未核销<b>{{ money(doc.unallocated_amount) }}</b></span>
      </div>
      <section v-if="canAllocate" class="allocation-card">
        <div class="card-heading"><h2>待核销业务</h2><el-button type="success" icon="el-icon-plus" :disabled="saving" @click="pickerVisible = true">选择业务来源</el-button></div>
        <el-alert v-if="initialSourceError" :title="initialSourceError" type="warning" :closable="false" show-icon />
        <finance-pending-allocations :rows="pending" :editable="!saving" @remove="removePending" />
        <div class="allocation-summary"><span>本次核销合计：<b>{{ money(pendingTotal) }}</b></span><span>核销后余额：<b>{{ money(remainingAfterPending) }}</b></span><el-button type="success" :loading="saving" :disabled="!pending.length || Boolean(initialSourceError)" @click="submit">确认核销</el-button></div>
      </section>
      <section class="allocation-card history-card">
        <div class="card-heading"><h2>核销记录</h2><span>共 {{ (doc.allocations || []).length }} 条</span></div>
        <el-table :data="doc.allocations || []" border size="small" empty-text="暂无核销记录">
          <el-table-column label="业务来源" min-width="130"><template slot-scope="{ row }">{{ sourceLabel(row.source_business_type) }}</template></el-table-column>
          <el-table-column prop="source_document_no" label="业务单号" min-width="155" />
          <el-table-column label="核销金额" min-width="110" align="right"><template slot-scope="{ row }">{{ money(row.allocated_amount) }}</template></el-table-column>
          <el-table-column label="核销时间" min-width="160"><template slot-scope="{ row }">{{ dateTimeText(row.allocated_at) }}</template></el-table-column>
          <el-table-column prop="operator_name" label="操作人" min-width="90" />
          <el-table-column label="状态" width="86"><template slot-scope="{ row }">{{ row.status === 'active' ? '已核销' : '已撤销' }}</template></el-table-column>
          <el-table-column label="撤销时间" min-width="160"><template slot-scope="{ row }">{{ dateTimeText(row.reversed_at) }}</template></el-table-column>
          <el-table-column prop="reversal_reason" label="撤销原因" min-width="140" />
          <el-table-column label="操作" width="94"><template slot-scope="{ row }"><el-button v-if="doc.status === 'confirmed' && row.status === 'active' && $can('finance.allocation.reverse')" type="text" :disabled="saving || Boolean(loadError)" @click="reverse(row)">撤销核销</el-button></template></el-table-column>
        </el-table>
      </section>
      <finance-source-picker :visible.sync="pickerVisible" :direction="doc.direction" :party-type="doc.party_type" :party-id="Number(doc.party_id)" :currency="doc.currency" :existing="pending" @confirm="addSources" />
    </template>
  </div>
</template>
<script>
import { getCashDocument, resolveFinanceSource, allocateCashDocument, reverseFinanceAllocation } from '../../../api/erp/finance'
import FinanceSourcePicker from '../../../components/finance/FinanceSourcePicker.vue'
import FinancePendingAllocations from '../../../components/finance/FinancePendingAllocations.vue'
import { money, sourceLabel, sourceKey, sourceMismatch, amountUnits, pendingFromSource, allocationTotal, serializeAllocationItems, validatePendingAllocations } from '../../../utils/financeCashAllocation'

export default {
  components: { FinanceSourcePicker, FinancePendingAllocations },
  props: {
    cashId: { type: Number, default: 0 },
    embedded: { type: Boolean, default: false },
    initialSource: { type: Object, default: null },
  },
  data: () => ({ loading: false, saving: false, doc: { allocations: [] }, pending: [], pickerVisible: false, loadError: '', initialSourceError: '', loadRevision: 0 }),
  computed: {
    resolvedCashId() { return this.cashId || Number(this.$route?.params?.id || 0) },
    remaining() { return Number(this.doc.unallocated_amount || 0) },
    canAllocate() { return !this.loadError && this.doc.status === 'confirmed' && this.remaining > 0 && this.$can('finance.allocation.create') },
    pendingTotal() { return allocationTotal(this.pending) },
    remainingAfterPending() { return ((amountUnits(this.doc.unallocated_amount) || 0) - Math.round(this.pendingTotal * 10000)) / 10000 },
  },
  watch: {
    resolvedCashId: { immediate: true, handler() { this.load(true) } },
    initialSource: { deep: true, handler() { this.load(true) } },
  },
  beforeDestroy() { this.loadRevision += 1 },
  methods: {
    money,
    sourceLabel,
    dateTimeText(value) { return String(value || '').replace('T', ' ').replace(/\.\d+Z$/, '') || '—' },
    async load(reset = false) {
      const revision = ++this.loadRevision
      const id = this.resolvedCashId
      if (reset) { this.doc = { allocations: [] }; this.pending = []; this.pickerVisible = false; this.initialSourceError = '' }
      this.loadError = ''
      if (!id) { this.loading = false; return }
      this.loading = true
      try {
        const response = await getCashDocument(id)
        if (revision !== this.loadRevision) return
        this.doc = response.data.data
        if (reset && this.initialSource && this.canAllocate) {
          try {
            const sourceResponse = await resolveFinanceSource({ type: this.initialSource.type, id: this.initialSource.id })
            if (revision !== this.loadRevision) return
            this.addSources([sourceResponse.data.data])
          } catch (error) {
            if (revision === this.loadRevision) this.initialSourceError = error.userMessage || error.message || '指定业务来源加载失败，请重新选择'
          }
        }
        return this.doc
      } catch (error) {
        if (revision === this.loadRevision) this.loadError = error.userMessage || '资金单加载失败，请关闭后重试'
      } finally { if (revision === this.loadRevision) this.loading = false }
    },
    removePending(index) { this.pending.splice(index, 1) },
    addSources(sources) {
      if (!this.canAllocate || this.saving) return
      let added = 0
      for (const source of sources) {
        const mismatch = sourceMismatch(source, this.doc)
        if (mismatch) { this.$message.warning(mismatch); continue }
        if (this.pending.some(row => sourceKey(row) === sourceKey(source))) continue
        if (!(amountUnits(source.remainingAmount) > 0)) { this.$message.warning('该业务来源已无可核销余额'); continue }
        this.pending.push(pendingFromSource(source, this.remainingAfterPending.toFixed(4)))
        added += 1
      }
      if (added) this.initialSourceError = ''
      if (this.pending.some(row => amountUnits(row.allocated_amount) === 0)) this.$message.warning('业务已加入，请调整各项核销金额，使合计不超过资金余额')
    },
    async submit() {
      if (!this.canAllocate || this.saving || this.initialSourceError) return
      const validation = validatePendingAllocations(this.pending, this.doc.unallocated_amount, { allowEmpty: false })
      if (validation) return this.$message.error(validation)
      const id = this.doc.id
      this.saving = true
      try {
        await allocateCashDocument(id, serializeAllocationItems(this.pending))
        if (this.doc.id !== id) return
        this.pending = []
        this.$message.success('核销成功')
        const updated = await this.load()
        this.$emit('changed', updated || { id })
      } catch (error) { this.$message.error(error.userMessage || '核销失败，请核对业务余额后重试') }
      finally { this.saving = false }
    },
    async reverse(row) {
      if (this.doc.status !== 'confirmed' || row.status !== 'active' || !this.$can('finance.allocation.reverse') || this.saving || this.loadError) return
      const id = this.doc.id
      try {
        const { value } = await this.$prompt('撤销不会删除历史记录，请填写原因', '撤销核销', { inputValidator: value => !!String(value || '').trim() || '原因必填' })
        if (this.doc.id !== id) return
        this.saving = true
        await reverseFinanceAllocation(row.id, value)
        if (this.doc.id !== id) return
        this.pending = []
        this.$message.success('核销已撤销')
        const updated = await this.load()
        this.$emit('changed', updated || { id })
      } catch (error) { if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '撤销失败') }
      finally { this.saving = false }
    },
    back() {
      const path = this.doc.direction === 'receipt' ? '/finance/receipts' : '/finance/payments'
      this.$router.push(this.doc.id ? `${path}/${this.doc.id}` : path)
    },
  },
}
</script>
<style scoped>
.allocation-page {
  padding: 18px 22px 32px;
  min-width: 0;
  box-sizing: border-box;
}
.allocation-page.is-embedded {
  padding: 0;
}
.allocation-heading,
.card-heading,
.allocation-summary {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.allocation-heading {
  margin-bottom: 16px;
}
.allocation-heading h1 {
  font-size: 24px;
  margin: 0;
}
.cash-summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 14px;
  padding: 16px;
  margin-bottom: 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}
.cash-summary span,
.cash-summary b {
  display: block;
  min-width: 0;
  overflow-wrap: anywhere;
}
.cash-summary span {
  color: #64748b;
  font-size: 13px;
}
.cash-summary b {
  color: #1e293b;
  margin-top: 7px;
}
.allocation-card {
  min-width: 0;
  margin-bottom: 14px;
  background: #fff;
}
.card-heading {
  margin-bottom: 12px;
}
.card-heading h2 {
  margin: 0;
  font-size: 16px;
}
.card-heading span {
  font-size: 13px;
  color: #64748b;
}
.allocation-summary {
  padding: 14px 0;
  font-size: 14px;
}
.allocation-summary b {
  color: #008b4b;
}
.el-alert {
  margin-bottom: 12px;
}
@media (max-width: 720px) {
  .allocation-page {
    padding: 12px;
  }
  .cash-summary {
    grid-template-columns: minmax(0, 1fr);
  }
  .allocation-summary {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
