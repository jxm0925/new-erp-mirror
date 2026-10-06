<template>
  <el-dialog title="选择待核销业务" :visible="visible" width="960px" top="5vh" append-to-body
    custom-class="finance-source-picker-dialog" :close-on-click-modal="false" @close="$emit('update:visible', false)">
    <div class="source-picker">
      <div class="source-filters">
        <el-select v-model="sourceType" @change="search"><el-option v-for="option in sourceOptions" :key="option.value" :value="option.value" :label="option.label" /></el-select>
        <el-input v-model.trim="keyword" clearable placeholder="输入业务单号或名称搜索" @keyup.enter.native="search" />
        <el-button type="success" icon="el-icon-search" @click="search">查询</el-button>
        <el-button @click="resetSearch">重置</el-button>
      </div>
      <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
      <el-table v-loading="loading" :data="rows" border size="small" empty-text="暂无符合条件的业务来源">
        <el-table-column label="选择" width="58" align="center"><template slot-scope="{ row }"><el-checkbox :value="isSelected(row)" :disabled="Boolean(disabledReason(row))" @change="checked => toggle(row, checked)" /></template></el-table-column>
        <el-table-column prop="no" label="业务单号" min-width="150" />
        <el-table-column prop="partyName" label="交易对手" min-width="140" />
        <el-table-column prop="currency" label="币种" width="70" />
        <el-table-column label="业务金额" min-width="110" align="right"><template slot-scope="{ row }">{{ money(row.amount) }}</template></el-table-column>
        <el-table-column label="已结算" min-width="110" align="right"><template slot-scope="{ row }">{{ money(row.allocatedAmount) }}</template></el-table-column>
        <el-table-column label="待结算" min-width="110" align="right"><template slot-scope="{ row }">{{ money(row.remainingAmount) }}</template></el-table-column>
        <el-table-column label="选择状态" min-width="135"><template slot-scope="{ row }">{{ disabledReason(row) || (isSelected(row) ? '已选择' : '可选择') }}</template></el-table-column>
      </el-table>
      <el-pagination background layout="total, prev, pager, next" :current-page="page" :page-size="10" :total="total" :pager-count="5" @current-change="changePage" />
      <div v-if="selected.length" class="selected-sources"><b>本次已选 {{ selected.length }} 项</b><el-tag v-for="row in selected" :key="key(row)" closable type="success" @close="toggle(row, false)">{{ row.no }}</el-tag></div>
    </div>
    <span slot="footer"><el-button @click="$emit('update:visible', false)">取消</el-button><el-button type="success" :disabled="!selected.length" @click="confirm">确认选择（{{ selected.length }}）</el-button></span>
  </el-dialog>
</template>
<script>
import { listFinanceSources } from '../../api/erp/finance'
import { money, sourceKey, sourceOptionsFor, sourceMismatch, amountUnits } from '../../utils/financeCashAllocation'

export default {
  props: {
    visible: Boolean,
    direction: { type: String, required: true },
    partyType: { type: String, required: true },
    partyId: { type: Number, default: 0 },
    currency: { type: String, required: true },
    existing: { type: Array, default: () => [] },
  },
  data: () => ({ sourceType: '', keyword: '', rows: [], selected: [], page: 1, total: 0, loading: false, error: '', requestRevision: 0 }),
  computed: {
    sourceOptions() { return sourceOptionsFor(this.direction, this.partyType) },
    cashContext() { return { direction: this.direction, party_type: this.partyType, party_id: this.partyId, currency: this.currency } },
  },
  watch: {
    visible: {
      immediate: true,
      handler(open) {
        this.requestRevision += 1
        this.selected = []
        this.rows = []
        this.keyword = ''
        this.page = 1
        this.total = 0
        this.error = ''
        this.loading = false
        if (open) {
          this.sourceType = this.sourceOptions[0]?.value || ''
          this.load()
        }
      },
    },
  },
  beforeDestroy() { this.requestRevision += 1 },
  methods: {
    money,
    key: sourceKey,
    isSelected(row) { return this.selected.some(item => sourceKey(item) === sourceKey(row)) },
    disabledReason(row) {
      if (this.existing.some(item => sourceKey(item) === sourceKey(row))) return '已在待核销清单'
      return sourceMismatch(row, this.cashContext) || (amountUnits(row.remainingAmount) > 0 ? '' : '无可核销余额')
    },
    toggle(row, checked) {
      if (!checked) this.selected = this.selected.filter(item => sourceKey(item) !== sourceKey(row))
      else if (!this.disabledReason(row) && !this.isSelected(row)) {
        if (this.selected.length + this.existing.length >= 100) return this.$message.warning('每次最多选择 100 项业务来源')
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
      if (!this.partyId || !this.sourceType) { this.total = 0; return }
      this.loading = true
      try {
        const response = await listFinanceSources({ type: this.sourceType, party_id: this.partyId, currency: this.currency, keyword: this.keyword, page: this.page, per_page: 10 })
        if (revision !== this.requestRevision) return
        this.rows = response.data.data || []
        this.total = Number(response.data.total || 0)
      } catch (error) {
        if (revision === this.requestRevision) { this.error = error.userMessage || '业务来源加载失败，请重试'; this.total = 0 }
      } finally { if (revision === this.requestRevision) this.loading = false }
    },
    confirm() {
      this.$emit('confirm', this.selected.map(row => ({ ...row })))
      this.$emit('update:visible', false)
    },
  },
}
</script>
<style>
.finance-source-picker-dialog {
  max-width: calc(100vw - 24px);
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
}
.finance-source-picker-dialog .el-dialog__body {
  min-height: 0;
  overflow-y: auto;
  padding: 16px 20px;
}
</style>
<style scoped>
.source-picker,
.source-filters > * {
  min-width: 0;
  box-sizing: border-box;
}
.source-filters {
  display: grid;
  grid-template-columns: 180px minmax(0, 1fr) auto auto;
  gap: 10px;
  margin-bottom: 12px;
}
.source-filters .el-button + .el-button {
  margin-left: 0;
}
.el-pagination {
  margin-top: 14px;
  overflow-x: auto;
}
.selected-sources {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin-top: 12px;
}
.el-alert {
  margin-bottom: 12px;
}
@media (max-width: 720px) {
  .source-filters {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  }
  .source-filters .el-input {
    grid-column: 1 / -1;
  }
}
</style>
