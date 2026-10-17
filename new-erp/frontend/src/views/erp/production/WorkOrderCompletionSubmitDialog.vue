<template>
  <el-dialog
    class="completion-submit-dialog"
    title="提交工单完工"
    :visible.sync="visible"
    width="736px"
    append-to-body
    :close-on-click-modal="false"
    :close-on-press-escape="!saving"
    :show-close="!saving"
    :before-close="beforeClose"
    @closed="afterClose"
  >
    <div v-loading="loading" class="completion-body">
      <div v-if="pendingJob" class="completion-notice" role="status">
        <i class="el-icon-info" />
        <span>上次提交结果待确认，请核对上次提交。已选产出和填写内容已保留。</span>
      </div>
      <div v-if="errorText" class="completion-error" role="alert">{{ errorText }}</div>

      <template v-if="preflight">
        <section class="completion-summary">
          <div><label>生产工单</label><strong>{{ preflight.work_order_no || '—' }}</strong></div>
          <div><label>产出物料</label><strong>{{ preflight.product.name || '—' }}</strong></div>
          <div><label>计划数量</label><strong>{{ qty(preflight.quantity.planned_base_qty) }} {{ unitName }}</strong></div>
        </section>

        <section class="completion-section">
          <div class="section-heading">
            <h3>完工前检查</h3>
            <el-button type="text" icon="el-icon-refresh" :disabled="loading || saving" @click="loadPreflight">刷新检查</el-button>
          </div>
          <div class="completion-checks">
            <div v-for="check in preflight.checks" :key="check.key" class="completion-check">
              <span>{{ checkLabel(check.key) }}</span>
              <strong :class="check.passed ? 'check-passed' : 'check-pending'">
                <i :class="check.passed ? 'el-icon-circle-check' : 'el-icon-warning-outline'" />
                {{ check.passed ? '通过' : '待处理' }}
              </strong>
            </div>
          </div>
        </section>

        <section class="completion-section">
          <div class="section-heading"><h3>本次完工产出</h3><span class="section-count">已选择 {{ selectedOutputIds.length }} 项</span></div>
          <el-table
            ref="outputTable"
            :key="tableKey"
            :data="preflight.terminal_outputs"
            row-key="output_record_id"
            border
            size="small"
            max-height="250"
            empty-text="暂无可提交的终末工序产出"
            @selection-change="changeSelection"
          >
            <el-table-column type="selection" width="44" :selectable="canSelectOutput" />
            <el-table-column prop="output_no" label="产出单号" min-width="165" />
            <el-table-column label="合格数量" min-width="94" align="right"><template slot-scope="{ row }">{{ qty(row.qualified_base_qty) }}</template></el-table-column>
            <el-table-column label="不合格" min-width="84" align="right"><template slot-scope="{ row }">{{ qty(row.unqualified_base_qty) }}</template></el-table-column>
            <el-table-column label="报废" min-width="76" align="right"><template slot-scope="{ row }">{{ qty(row.scrapped_base_qty) }}</template></el-table-column>
            <el-table-column label="单位" width="58"><template>{{ unitName }}</template></el-table-column>
          </el-table>
          <div class="completion-totals">
            <span>合格 <strong>{{ selectedQty('qualified_base_qty') }}</strong> {{ unitName }}</span>
            <span>不合格 <strong>{{ selectedQty('unqualified_base_qty') }}</strong> {{ unitName }}</span>
            <span>报废 <strong>{{ selectedQty('scrapped_base_qty') }}</strong> {{ unitName }}</span>
          </div>
        </section>
      </template>
      <div v-else-if="!loading && !errorText" class="completion-empty">暂无完工检查资料</div>

      <el-form class="completion-form" label-position="top" size="small" :disabled="inputsLocked">
        <el-form-item label="不合格 / 报废原因">
          <el-input v-model="form.defect_reason" type="textarea" :rows="2" maxlength="1000" resize="none" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="2000" resize="none" />
        </el-form-item>
      </el-form>
    </div>
    <div slot="footer" class="completion-footer">
      <span v-if="selectedOutputIds.length > 100" class="completion-limit">每次最多提交 100 项产出</span>
      <div class="completion-footer-actions">
        <el-button size="small" :disabled="saving" @click="close">关闭</el-button>
        <el-button type="success" size="small" :loading="saving" :disabled="!canSubmit" @click="submit">
          {{ pendingJob ? '核对上次提交' : '提交完工' }}
        </el-button>
      </div>
    </div>
  </el-dialog>
</template>

<script>
import { getCompletionPreflight, writeExecutionCommand, pendingExecutionCommands, retryExecutionCommand } from '@/api/erp/production-execution'

const checkLabels = {
  labor_ended: '作业工时已结束',
  reports_settled: '生产报工已结清',
  material_returns_settled: '退料已处理完毕',
  terminal_outputs_ready: '终末工序产出已就绪'
}

export default {
  name: 'WorkOrderCompletionSubmitDialog',
  data: () => ({
    visible: false,
    loading: false,
    saving: false,
    workOrderId: null,
    loadSequence: 0,
    preflight: null,
    pendingJob: null,
    pendingReadError: false,
    errorText: '',
    selectedOutputIds: [],
    form: { defect_reason: '', remark: '' }
  }),
  computed: {
    commandPath() { return `/v1/erp/production/work-orders/${this.workOrderId}/completions` },
    canCreate() { return Boolean(this.$can && this.$can('production.completion.create')) },
    unitName() { return this.preflight && this.preflight.quantity.base_unit_name || '' },
    tableKey() { return `${this.workOrderId}:${this.preflight && this.preflight.work_order_business_version || 0}` },
    inputsLocked() { return !this.canCreate || this.loading || this.saving || Boolean(this.pendingJob) || this.pendingReadError },
    canSubmit() {
      if (!this.canCreate || this.saving || this.pendingReadError) return false
      // An uncertain command must be replayed unchanged, even when a fresh preflight no longer lists its outputs.
      if (this.pendingJob) return true
      return !this.loading && Boolean(this.preflight && this.preflight.passed) && this.selectedOutputIds.length > 0 && this.selectedOutputIds.length <= 100
    }
  },
  methods: {
    async open(workOrderId) {
      if (this.saving) return
      const id = Number(workOrderId)
      if (!Number.isSafeInteger(id) || id <= 0) return this.$message.error('生产工单无效，请刷新后重试')
      this.loadSequence += 1
      this.workOrderId = id
      this.loading = false
      this.preflight = null
      this.pendingJob = null
      this.pendingReadError = false
      this.errorText = ''
      this.selectedOutputIds = []
      this.form = { defect_reason: '', remark: '' }
      this.visible = true
      this.syncPending()
      if (!this.canCreate) { this.errorText = '当前账号没有提交工单完工权限'; return }
      await this.loadPreflight()
    },
    syncPending() {
      try {
        const jobs = pendingExecutionCommands(this.commandPath).filter(job => job.path === this.commandPath && job.method.toUpperCase() === 'POST')
        if (jobs.length > 1 || jobs.some(job => Number(job.id) !== this.workOrderId)) throw new Error('上次提交记录与当前工单不一致，请联系管理员核对结果')
        const job = jobs[0] || null
        if (job && (!Number.isInteger(Number(job.payload.expected_version)) || Number(job.payload.expected_version) < 1 || !Array.isArray(job.payload.output_record_ids) || !job.payload.output_record_ids.length)) throw new Error('上次提交记录不完整，请联系管理员核对结果')
        this.pendingJob = job
        this.pendingReadError = false
        if (job) {
          this.selectedOutputIds = job.payload.output_record_ids.map(Number)
          this.form = { defect_reason: job.payload.defect_reason || '', remark: job.payload.remark || '' }
        }
      } catch (error) {
        this.pendingReadError = true
        this.errorText = error.message || '上次提交记录无法读取，请联系管理员核对结果'
      }
    },
    async loadPreflight() {
      if (!this.canCreate || this.saving || !this.workOrderId) return
      const sequence = ++this.loadSequence
      const id = this.workOrderId
      this.loading = true
      if (!this.pendingReadError) this.errorText = ''
      if (!this.pendingJob) this.selectedOutputIds = []
      if (this.$refs.outputTable) this.$refs.outputTable.clearSelection()
      try {
        const response = await getCompletionPreflight(id)
        if (!this.visible || sequence !== this.loadSequence || id !== this.workOrderId) return
        const value = response.data && response.data.data
        if (!value || Number(value.work_order_id) !== id || !Number.isInteger(Number(value.work_order_business_version)) || Number(value.work_order_business_version) < 1) throw new Error('完工检查资料不完整，请刷新后重试')
        this.preflight = {
          work_order_no: value.work_order_no,
          work_order_business_version: Number(value.work_order_business_version),
          product: { name: value.product && value.product.name },
          quantity: { planned_base_qty: value.quantity && value.quantity.planned_base_qty, base_unit_name: value.quantity && value.quantity.base_unit_name },
          passed: value.passed === true,
          checks: (value.checks || []).map(check => ({ key: check.key, passed: check.passed === true })),
          terminal_outputs: (value.terminal_outputs || []).map(row => ({
            output_record_id: Number(row.output_record_id), output_no: row.output_no,
            submitted_base_qty: row.submitted_base_qty, qualified_base_qty: row.qualified_base_qty,
            unqualified_base_qty: row.unqualified_base_qty, scrapped_base_qty: row.scrapped_base_qty
          }))
        }
        await this.$nextTick()
        if (this.pendingJob && this.$refs.outputTable) this.preflight.terminal_outputs.forEach(row => {
          if (this.selectedOutputIds.includes(row.output_record_id)) this.$refs.outputTable.toggleRowSelection(row, true)
        })
      } catch (error) {
        if (this.visible && sequence === this.loadSequence && id === this.workOrderId) this.errorText = error.userMessage || error.message || '完工检查读取失败，请重试'
      } finally {
        if (sequence === this.loadSequence && id === this.workOrderId) this.loading = false
      }
    },
    checkLabel(key) { return checkLabels[key] || '完工条件' },
    qty(value) {
      if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return '—'
      return Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 8 })
    },
    selectedQty(field) {
      const rows = this.preflight ? this.preflight.terminal_outputs.filter(row => this.selectedOutputIds.includes(row.output_record_id)) : []
      if (rows.length !== this.selectedOutputIds.length) return '—'
      return this.qty(rows.reduce((sum, row) => sum + Number(row[field] || 0), 0))
    },
    canSelectOutput() { return !this.inputsLocked },
    changeSelection(rows) {
      if (!this.inputsLocked) this.selectedOutputIds = rows.map(row => row.output_record_id)
    },
    async submit() {
      if (!this.canSubmit) return
      this.saving = true
      this.errorText = ''
      try {
        if (this.pendingJob) await retryExecutionCommand(this.pendingJob)
        else await writeExecutionCommand('POST', this.commandPath, this.workOrderId, {
          expected_version: this.preflight.work_order_business_version,
          output_record_ids: [...this.selectedOutputIds],
          defect_reason: this.form.defect_reason.trim() || null,
          remark: this.form.remark.trim() || null
        })
        this.pendingJob = null
        this.visible = false
        this.loadSequence += 1
        this.$message.success('完工已提交并生效，可继续办理成品入库或交下一工单')
        this.$emit('updated')
      } catch (error) {
        this.errorText = error.userMessage || error.message || '提交未完成，请核对后重试'
        this.syncPending()
      } finally {
        this.saving = false
      }
    },
    close() { if (!this.saving) { this.visible = false; this.loadSequence += 1 } },
    beforeClose(done) { if (!this.saving) { this.loadSequence += 1; done() } },
    afterClose() { this.loadSequence += 1; this.loading = false }
  }
}
</script>

<style scoped>
.completion-submit-dialog {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  box-sizing: border-box;
}
.completion-submit-dialog ::v-deep .el-dialog {
  margin: 0 !important;
  max-width: 100%;
  max-height: calc(100vh - 32px);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border-radius: 4px;
}
.completion-submit-dialog ::v-deep .el-dialog__header {
  padding: 18px 24px;
  border-bottom: 1px solid #e5e9ef;
  flex-shrink: 0;
}
.completion-submit-dialog ::v-deep .el-dialog__title {
  color: #1d3047;
  font-size: 18px;
  font-weight: 600;
}
.completion-submit-dialog ::v-deep .el-dialog__headerbtn {
  top: 19px;
  right: 24px;
}
.completion-submit-dialog ::v-deep .el-dialog__body {
  padding: 0;
  min-height: 0;
  overflow: auto;
}
.completion-submit-dialog ::v-deep .el-dialog__footer {
  padding: 0;
  flex-shrink: 0;
}
.completion-body {
  padding: 24px;
  color: #33465e;
}
.completion-notice, .completion-error {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  padding: 10px 12px;
  margin-bottom: 16px;
  line-height: 1.6;
  border: 1px solid #dce6e1;
  border-radius: 4px;
  background: #f5faf7;
  font-size: 13px;
}
.completion-notice > i {
  margin-top: 3px;
  color: #008b4b;
}
.completion-error {
  border-color: #eed8d5;
  background: #fff8f7;
  color: #a9463c;
}
.completion-summary {
  display: grid;
  grid-template-columns: 1.1fr 1.25fr .75fr;
  gap: 16px;
  padding-bottom: 20px;
}
.completion-summary > div {
  min-width: 0;
}
.completion-summary label {
  display: block;
  margin-bottom: 8px;
  color: #7d8a9d;
  font-size: 12px;
}
.completion-summary strong {
  display: block;
  font-size: 14px;
  line-height: 1.6;
  color: #23364c;
  overflow-wrap: anywhere;
}
.completion-section {
  margin-bottom: 20px;
  padding: 14px;
  border: 1px solid #e3e9f0;
  border-radius: 4px;
}
.section-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: 24px;
  margin-bottom: 12px;
}
.section-heading h3 {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  color: #1e344e;
}
.section-heading .el-button {
  padding: 0;
  color: #008b4b;
}
.section-count {
  font-size: 12px;
  color: #7d8a9d;
}
.completion-checks {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px 20px;
}
.completion-check {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  min-width: 0;
  font-size: 13px;
  line-height: 1.5;
}
.completion-check strong {
  flex-shrink: 0;
  font-size: 12px;
  font-weight: 500;
}
.check-passed {
  color: #008b4b;
}
.check-pending {
  color: #a26a22;
}
.completion-submit-dialog ::v-deep .el-table th {
  background: #f6f8fb;
  color: #45566e;
}
.completion-submit-dialog ::v-deep .el-table .cell {
  white-space: normal;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.completion-submit-dialog ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner,
.completion-submit-dialog ::v-deep .el-checkbox__input.is-indeterminate .el-checkbox__inner {
  background: #008b4b;
  border-color: #008b4b;
}
.completion-submit-dialog ::v-deep .el-checkbox__inner:hover {
  border-color: #008b4b;
}
.completion-totals {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px 20px;
  margin-top: 12px;
  color: #718096;
  font-size: 12px;
}
.completion-totals strong {
  color: #30445d;
  font-weight: 600;
  margin-left: 5px;
}
.completion-form ::v-deep .el-form-item {
  margin-bottom: 16px;
}
.completion-form ::v-deep .el-form-item:last-child {
  margin-bottom: 0;
}
.completion-form ::v-deep .el-form-item__label {
  padding-bottom: 6px;
  color: #45566e;
  font-size: 13px;
  line-height: 1.5;
}
.completion-form ::v-deep .el-textarea__inner:focus {
  border-color: #008b4b;
}
.completion-empty {
  padding: 22px 0;
  text-align: center;
  color: #8491a3;
}
.completion-footer {
  min-height: 64px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 12px 24px;
  box-sizing: border-box;
  border-top: 1px solid #e5e9ef;
}
.completion-footer-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-left: auto;
}
.completion-footer-actions .el-button {
  margin: 0;
  min-width: 80px;
}
.completion-footer-actions .el-button--success {
  background: #008b4b;
  border-color: #008b4b;
}
.completion-footer-actions .el-button--success.is-disabled {
  background: #9ac9b3;
  border-color: #9ac9b3;
}
.completion-limit {
  color: #a26a22;
  font-size: 12px;
}
@media (max-width: 600px) {
  .completion-submit-dialog {
    padding: 12px;
  }
  .completion-submit-dialog ::v-deep .el-dialog {
    max-height: calc(100vh - 24px);
  }
  .completion-submit-dialog ::v-deep .el-dialog__header {
    padding: 16px;
  }
  .completion-body {
    padding: 16px;
  }
  .completion-summary {
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }
  .completion-summary > div:first-child {
    grid-column: 1 / -1;
  }
  .completion-section {
    padding: 10px;
  }
  .completion-checks {
    grid-template-columns: 1fr;
  }
  .completion-footer {
    padding: 12px 16px;
    flex-wrap: wrap;
  }
}
</style>
