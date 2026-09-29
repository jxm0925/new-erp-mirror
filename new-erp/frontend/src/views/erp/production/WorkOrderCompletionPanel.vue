<template>
  <section class="completion-panel" v-loading="loading">
    <div class="completion-records">
      <h3>完工记录</h3>
      <el-select v-model="statusFilter" class="status-filter" :disabled="busy" @change="changeFilter"><el-option label="全部状态" value="" /><el-option v-for="(label, status) in statuses" :key="status" :label="label" :value="status" /></el-select>
      <div class="record-options"><button v-for="row in records" :key="row.completion_id" class="record-option" :class="{selected: selectedId === row.completion_id}" :disabled="busy" @click="selectCompletion(row.completion_id)"><i class="el-icon-document" /><span>{{ row.completion_no }}</span><el-tag size="small" :type="statusType(row.status)">{{ statusText(row.status) }}</el-tag><span>{{ number(row.submitted_base_qty) }} {{ unitName }}</span><time>{{ row.submitted_at }}</time></button></div>
      <el-pagination small :current-page="page" :page-size="5" :total="total" :disabled="busy" layout="total, prev, pager, next" @current-change="changePage" />
    </div>
    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" show-icon><el-button type="text" @click="load()">重新加载</el-button></el-alert>
    <el-alert v-if="pendingReview" title="上次审核结果尚未确认，请继续原操作。" type="warning" :closable="false" show-icon><el-button v-if="$can('production.completion.review')" type="text" :loading="reviewBusy" @click="retryReview">继续上次审核</el-button></el-alert>
    <el-alert v-if="pendingReceipt" title="上次入库结果尚未确认，请继续原操作。" type="warning" :closable="false" show-icon><el-button v-if="$can('production.output.warehouse')" type="text" :loading="submitting" @click="submitReceipt">继续上次入库</el-button></el-alert>
    <el-empty v-if="!completion && !loading && !loadError" :description="statusFilter ? '暂无符合条件的完工记录' : '当前工单尚未提交完工'" />
    <div v-if="completion" class="completion-layout">
      <div class="left-column">
        <section class="completion-card">
          <h3>完工事实 <small>（只读）</small></h3>
          <dl class="fact-list"><dt>完工单号</dt><dd>{{ completion.completion_no }}</dd><dt>提交人</dt><dd>{{ completion.submitted_by_name || `用户 ${completion.submitted_by_legacy_id}` }}</dd><dt>提交时间</dt><dd>{{ completion.submitted_at || '-' }}</dd></dl>
          <div class="quantity-strip"><div v-for="metric in metrics" :key="metric.key"><span>{{ metric.label }}</span><b>{{ number(completion[metric.key]) }} {{ unitName }}</b></div></div>
          <div class="note"><span>备注</span><p>{{ completion.remark || completion.defect_reason || '无' }}</p></div>
        </section>
        <section class="completion-card review-card">
          <h3>{{ completion.status === 'PENDING_REVIEW' ? '审核处理' : '审核结论' }}</h3>
          <dl class="fact-list"><dt>当前状态</dt><dd :class="statusClass"><i :class="statusIcon" /> {{ statusText(completion.status) }}</dd></dl>
          <template v-if="completion.status === 'PENDING_REVIEW'">
            <p class="check-caption">提交时检查</p>
            <div v-for="check in checks" :key="check.key" class="check-row"><i :class="check.passed ? 'el-icon-success green' : 'el-icon-warning orange'" /><span>{{ check.label }}</span><strong :class="check.passed ? 'green' : 'orange'">{{ check.passed ? '通过' : '未通过' }}</strong></div>
            <p v-if="!checks.length" class="muted">没有可显示的提交检查记录</p>
            <div v-if="canReview" class="review-actions"><el-button type="success" :disabled="busy || !!pendingReview" @click="openReview('approve')">审核通过</el-button><el-button type="danger" plain :disabled="busy || !!pendingReview" @click="openReview('reject')">驳回并退回</el-button></div>
          </template>
          <dl v-else class="fact-list review-facts"><dt>审核人</dt><dd>{{ completion.reviewed_by_name || (completion.reviewed_by_legacy_id ? `用户 ${completion.reviewed_by_legacy_id}` : '-') }}</dd><dt>审核时间</dt><dd>{{ completion.reviewed_at || '-' }}</dd><dt>审核备注</dt><dd>{{ completion.review_reason || '无' }}</dd></dl>
        </section>
      </div>
      <div class="middle-column">
        <section class="completion-card progress-card">
          <h3>成品入库进度 <small>（基于良品）</small></h3>
          <div class="progress-stats"><div><span>应入良品</span><b class="green">{{ number(receivableQty) }} {{ unitName }}</b></div><div><span>已入库</span><b>{{ number(postedQty) }} {{ unitName }}</b></div><div><span>待入库</span><b class="orange">{{ number(remainingQty) }} {{ unitName }}</b></div></div>
          <p class="muted">{{ completion.status === 'APPROVED' ? '进度仅基于需入库的良品数量。' : '审核通过后可办理成品入库。' }}</p>
        </section>
        <section class="completion-card receipt-card">
          <h3>入库记录</h3>
          <el-table :data="completion.receipts || []" border size="small" empty-text="暂无入库记录"><el-table-column type="index" label="序号" width="55" /><el-table-column prop="receipt_no" label="入库单号" min-width="150" /><el-table-column label="入库数量（良品）" width="125"><template slot-scope="{row}">{{ number(row.posted_base_qty) }} {{ unitName }}</template></el-table-column><el-table-column label="仓库 / 库位" min-width="130"><template slot-scope="{row}">{{ row.warehouse_name || '-' }}<br>{{ row.location_name || '-' }}</template></el-table-column><el-table-column prop="batch_no" label="批次" min-width="125" /><el-table-column prop="posted_by_name" label="入库操作人" width="105" /><el-table-column prop="posted_at" label="入库时间" min-width="145" /></el-table>
          <div class="receipt-total">合计 <b>{{ number(postedQty) }} {{ unitName }}</b></div>
        </section>
      </div>
      <aside class="completion-card receipt-form">
        <h3>新建成品入库</h3>
        <div v-if="completion.status !== 'APPROVED'" class="locked-receipt"><i class="el-icon-lock" /><h4>{{ completion.status === 'REJECTED' ? '完工申请已驳回' : '等待完工审核' }}</h4><p>{{ completion.status === 'REJECTED' ? '车间处理后重新提交完工，当前记录不能入库。' : '审核通过后可办理成品入库。' }}</p><el-alert :title="completion.status === 'REJECTED' ? '当前完工记录不可入库' : '请先完成完工审核'" type="info" :closable="false" show-icon /></div>
        <template v-else-if="receivableLines.length && $can('production.output.warehouse')">
          <label v-if="receivableLines.length > 1">完工产出<el-select v-model="form.output_record_id" :disabled="receiptLocked" placeholder="请选择产出" @change="selectLine"><el-option v-for="line in receivableLines" :key="line.output_record_id" :label="`${line.output_no} · ${line.item_name} · 可入 ${number(line.remaining_receivable_base_qty)} ${unitName}`" :value="line.output_record_id" /></el-select></label>
          <label>本次入库数量（良品）<em>*</em><el-input-number v-model="form.posted_base_qty" :disabled="receiptLocked" :min="0.00000001" :max="lineRemaining" :precision="8" :controls="false" /><small>剩余可入库：{{ number(lineRemaining) }} {{ unitName }}</small></label>
          <label>仓库 <em>*</em><el-select v-model="form.warehouse_id" :disabled="receiptLocked" filterable placeholder="请选择仓库" @change="warehouseChanged"><el-option v-for="row in warehouses" :key="row.id" :label="row.warehouse_name" :value="row.id" /></el-select></label>
          <label>库位 <em>*</em><el-select v-model="form.location_id" :disabled="receiptLocked" filterable placeholder="请选择库位"><el-option v-for="row in filteredLocations" :key="row.id" :label="row.location_name" :value="row.id" /></el-select></label>
          <label>批次 <em>*</em><el-input v-model.trim="form.batch_no" :disabled="receiptLocked" maxlength="80" /></label>
          <el-button type="success" :loading="submitting" :disabled="!canSubmit" @click="submitReceipt">确认过账</el-button><p class="muted">过账后生成入库单并更新库存。</p>
        </template>
        <el-result v-else-if="!receivableLines.length" icon="success" :title="receivableQty > 0 ? '良品已全部入库' : '当前产出无需入库'" />
        <el-alert v-else title="当前账号没有成品入库权限" type="info" :closable="false" show-icon />
      </aside>
    </div>
    <el-dialog :visible.sync="reviewVisible" :title="reviewDecision === 'approve' ? '确认完工审核' : '驳回完工申请'" :width="reviewDecision === 'approve' ? '674px' : '604px'" custom-class="completion-review-dialog" append-to-body :close-on-click-modal="false" :close-on-press-escape="!reviewBusy && !pendingReview" :show-close="!reviewBusy && !pendingReview" :before-close="closeReview">
      <template v-if="reviewSnapshot">
        <div v-if="reviewDecision === 'approve'" class="review-summary"><dl class="fact-list"><dt>工单</dt><dd>{{ workOrder.work_order_no }}</dd><dt>完工单</dt><dd>{{ reviewSnapshot.completion_no }}</dd><dt>提交人</dt><dd>{{ reviewSnapshot.submitted_by_name || `用户 ${reviewSnapshot.submitted_by_legacy_id}` }}</dd></dl><dl class="fact-list"><dt>产品</dt><dd>{{ productName }}</dd><dt>规格</dt><dd>{{ productSpec }}</dd><dt>产出去向</dt><dd>{{ reviewSnapshot.lines.some(line => line.requires_warehouse) ? '成品入库' : '直接流转' }}</dd></dl></div>
        <dl v-else class="fact-list"><dt>工单</dt><dd>{{ workOrder.work_order_no }}</dd><dt>完工单</dt><dd>{{ reviewSnapshot.completion_no }}</dd><dt>产品</dt><dd>{{ productName }} {{ productSpec }}</dd></dl>
        <div class="quantity-strip modal-quantities"><div v-for="metric in metrics" :key="metric.key"><span>{{ metric.label }}</span><b>{{ number(reviewSnapshot[metric.key]) }} {{ unitName }}</b></div></div>
        <template v-if="reviewDecision === 'approve'"><p class="check-caption">提交时检查</p><div v-for="check in reviewChecks" :key="check.key" class="modal-check"><i :class="check.passed ? 'el-icon-success green' : 'el-icon-warning orange'" /><strong>{{ check.label }}</strong><span>{{ check.passed ? check.description : '提交时检查未通过，请核对。' }}</span></div></template>
        <el-form :model="reviewForm" label-position="top"><el-form-item :label="reviewDecision === 'reject' ? '驳回原因' : '审核备注（选填）'" :required="reviewDecision === 'reject'" :error="reviewError"><el-input v-model="reviewForm.reason" type="textarea" :rows="3" :maxlength="1000" :disabled="reviewBusy || !!pendingReview" :placeholder="reviewDecision === 'reject' ? '请填写驳回原因' : '请填写审核意见'" @input="reviewError=''" /></el-form-item></el-form>
        <p class="review-explanation">{{ reviewDecision === 'approve' ? '审核通过后，仓库可办理需入库的产出，本次审核不增加库存。' : '驳回后保留本次提交和审核记录，产出退回待提交完工，库存不变。' }}</p>
      </template>
      <span slot="footer"><el-button :disabled="reviewBusy || !!pendingReview" @click="closeReview()">返回核对</el-button><el-button :type="reviewDecision === 'approve' ? 'success' : 'danger'" :loading="reviewBusy" @click="submitReview">{{ pendingReview ? '继续原操作' : (reviewDecision === 'approve' ? '确认通过' : '确认驳回') }}</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { listWorkOrderCompletions, reviewWorkOrderCompletion, warehouseProductionOutput } from '../../../api/erp/production'
import { listEntity } from '../../../api/erp/master'
import { completionCommandStore, completionErrorIsDefinitive } from '../../../utils/completionCommand'

const CHECKS = {
  labor_ended: ['工时已结束', '无仍在计时的作业。'], reports_settled: ['报工数量满足', '已登记产出数量满足完工要求。'],
  material_returns_settled: ['无待确认退料', '没有待处理的退料单。'], terminal_outputs_ready: ['终末产出已就绪', '产出已完成所需工序，等待完工审核。']
}
const checksFor = row => ((row && row.preflight_snapshot && row.preflight_snapshot.checks) || []).filter(check => CHECKS[check.key]).map(check => ({ ...check, label: CHECKS[check.key][0], description: CHECKS[check.key][1] }))
export default {
  name: 'WorkOrderCompletionPanel',
  props: { workOrder: { type: Object, required: true } },
  data: () => ({ loading: false, loadError: '', records: [], selectedId: null, statusFilter: '', page: 1, total: 0,
    warehouses: [], locations: [], submitting: false, form: {}, pendingReceipt: null, pendingReview: null,
    reviewVisible: false, reviewBusy: false, reviewDecision: 'approve', reviewSnapshot: null, reviewForm: { reason: '' }, reviewError: '',
    statuses: { PENDING_REVIEW: '待审核', APPROVED: '已通过', REJECTED: '已驳回' },
    metrics: [{ key: 'submitted_base_qty', label: '完工数量' }, { key: 'qualified_base_qty', label: '良品数量' }, { key: 'unqualified_base_qty', label: '不良数量' }, { key: 'scrapped_base_qty', label: '报废数量' }] }),
  computed: {
    completion() { return this.records.find(row => row.completion_id === this.selectedId) || null },
    unitName() { return this.workOrder.quantity && (this.workOrder.quantity.unit_name || this.workOrder.quantity.base_unit_name) || '' },
    productName() { return this.workOrder.product && (this.workOrder.product.name || this.workOrder.product.item_name) || this.workOrder.output_item && this.workOrder.output_item.item_name || (this.reviewSnapshot && this.reviewSnapshot.preflight_snapshot && this.reviewSnapshot.preflight_snapshot.product && this.reviewSnapshot.preflight_snapshot.product.name) || '-' },
    productSpec() { return this.workOrder.product && this.workOrder.product.specification || (this.reviewSnapshot && this.reviewSnapshot.preflight_snapshot && this.reviewSnapshot.preflight_snapshot.product && this.reviewSnapshot.preflight_snapshot.product.specification) || '-' },
    checks() { return checksFor(this.completion) }, reviewChecks() { return checksFor(this.reviewSnapshot) },
    busy() { return this.loading || this.submitting || this.reviewBusy }, receiptLocked() { return this.busy || !!this.pendingReceipt },
    statusClass() { return this.completion.status === 'APPROVED' ? 'green' : 'orange' }, statusIcon() { return this.completion.status === 'APPROVED' ? 'el-icon-success' : 'el-icon-time' },
    canReview() { return this.completion && this.completion.status === 'PENDING_REVIEW' && this.completion.allowed_actions && this.completion.allowed_actions.review && this.$can('production.completion.review') },
    postedQty() { return (this.completion && this.completion.receipts || []).reduce((sum, row) => sum + Number(row.posted_base_qty || 0), 0) },
    receivableQty() { return (this.completion && this.completion.lines || []).filter(line => line.requires_warehouse).reduce((sum, line) => sum + Number(line.qualified_base_qty || 0), 0) },
    remainingQty() { return Math.max(0, this.receivableQty - this.postedQty) },
    receivableLines() { return this.completion && this.completion.status === 'APPROVED' ? (this.completion.lines || []).filter(row => row.requires_warehouse && Number(row.remaining_receivable_base_qty) > 0 && row.output_status === 'WAIT_WAREHOUSE') : [] },
    selectedLine() { return this.receivableLines.find(row => row.output_record_id === this.form.output_record_id) || null },
    lineRemaining() { return Number(this.selectedLine && this.selectedLine.remaining_receivable_base_qty || 0) },
    filteredLocations() { return this.locations.filter(row => Number(row.warehouse_id) === Number(this.form.warehouse_id)) },
    canSubmit() { return !this.busy && !this.pendingReceipt && this.selectedLine && this.form.posted_base_qty > 0 && this.form.posted_base_qty <= this.lineRemaining && this.form.warehouse_id && this.form.location_id && this.form.batch_no && this.$can('production.output.warehouse') }
  },
  watch: { 'workOrder.id': { immediate: true, handler(id) { if (id) { this.page = 1; this.selectedId = null; this.statusFilter = ''; this.reviewVisible = false; this.load(); this.loadLocations() } } } },
  beforeDestroy() { this.listSequence = (this.listSequence || 0) + 1; this.locationSequence = (this.locationSequence || 0) + 1 },
  methods: {
    statusText(status) { return this.statuses[status] || status }, statusType(status) { return { PENDING_REVIEW: 'warning', APPROVED: 'success', REJECTED: 'danger' }[status] || 'info' },
    store(kind) { let actor = {}; try { actor = JSON.parse(localStorage.getItem('erp_user') || '{}') || {} } catch (_) { /* Keep a stable session key if the optional profile is malformed. */ } return completionCommandStore(localStorage, `completion-${kind}:${actor.legacy_id || actor.id || 'session'}:${this.workOrder.id}`) },
    newCommand(kind) { return `completion-${kind}-${this.workOrder.id}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}` },
    async load() {
      const sequence = this.listSequence = (this.listSequence || 0) + 1; this.loading = true; this.loadError = ''
      try {
        this.pendingReview = this.store('review').read(); this.pendingReceipt = this.store('receipt').read()
        const { data } = await listWorkOrderCompletions(this.workOrder.id, { page: this.page, per_page: 5, status: this.statusFilter || undefined })
        if (sequence !== this.listSequence) return
        if (this.page > data.last_page) { this.page = data.last_page; return this.load() }
        this.records = data.data || []; this.total = Number(data.total || 0)
        if (!this.records.some(row => row.completion_id === this.selectedId)) this.selectedId = this.records[0] && this.records[0].completion_id || null
        this.resetForm()
      } catch (error) { if (sequence === this.listSequence) { this.loadError = error.userMessage || error.message || '完工记录加载失败'; this.records = []; this.selectedId = null } }
      finally { if (sequence === this.listSequence) this.loading = false }
    },
    async loadLocations() {
      const sequence = this.locationSequence = (this.locationSequence || 0) + 1
      if (!this.$can('production.output.warehouse')) { this.warehouses = []; this.locations = []; return }
      try { const [warehouses, locations] = await Promise.all([listEntity('warehouses', { status: 'enabled', per_page: 100 }), listEntity('locations', { status: 'enabled', per_page: 100 })]); if (sequence !== this.locationSequence) return; this.warehouses = warehouses.data.data || []; this.locations = locations.data.data || [] } catch (error) { this.$message.error(error.userMessage || '仓库库位加载失败') }
    },
    changeFilter() { this.page = 1; this.selectedId = null; this.load() }, changePage(page) { this.page = page; this.selectedId = null; this.load() },
    selectCompletion(id) { if (this.busy) return; this.selectedId = id; this.resetForm() },
    resetForm() { const line = this.receivableLines.length === 1 ? this.receivableLines[0] : null; this.form = { output_record_id: line && line.output_record_id || null, posted_base_qty: Number(line && line.remaining_receivable_base_qty || 0), warehouse_id: null, location_id: null, batch_no: '' } },
    selectLine() { this.form.posted_base_qty = this.lineRemaining }, warehouseChanged() { this.form.location_id = null },
    openReview(decision) { if (!this.canReview || this.pendingReview || this.busy) return; this.reviewDecision = decision; this.reviewSnapshot = JSON.parse(JSON.stringify(this.completion)); this.reviewForm = { reason: '' }; this.reviewError = ''; this.reviewVisible = true },
    closeReview(done) { if (this.reviewBusy || this.pendingReview) return; this.reviewVisible = false; this.reviewSnapshot = null; this.reviewForm = { reason: '' }; this.reviewError = ''; if (typeof done === 'function') done() },
    async retryReview() {
      if (this.reviewBusy || !this.pendingReview) return
      this.reviewBusy = true
      try {
        // Read the exact record even when it has left the active status/page filter.
        const { data } = await listWorkOrderCompletions(this.workOrder.id, { completion_id: this.pendingReview.id, per_page: 1 })
        const current = data.data && data.data[0]
        if (!current) throw new Error('无法查看上次审核记录，请核对访问权限。')
        if (current.status !== 'PENDING_REVIEW') { this.store('review').clear(); this.pendingReview = null; this.reviewVisible = false; this.$message.info(`该完工单当前${this.statusText(current.status)}，已刷新实际结果。`); await this.load(); return }
        this.reviewSnapshot = current; this.reviewDecision = this.pendingReview.payload.decision; this.reviewForm = { reason: this.pendingReview.payload.reason || '' }; this.reviewVisible = true
      } catch (error) { this.$message.error(error.userMessage || error.message) } finally { this.reviewBusy = false }
    },
    async submitReview() {
      if (this.reviewBusy || !this.reviewSnapshot || !this.$can('production.completion.review')) return
      if (this.reviewDecision === 'reject' && !this.reviewForm.reason.trim()) { this.reviewError = '请填写驳回原因'; return }
      this.reviewBusy = true
      try {
        const job = this.store('review').prepare({ id: this.reviewSnapshot.completion_id, payload: { client_command_id: this.newCommand('review'), expected_version: this.reviewSnapshot.business_version, decision: this.reviewDecision, reason: this.reviewForm.reason.trim() || null } })
        this.pendingReview = job
        await reviewWorkOrderCompletion(job.id, job.payload)
        this.store('review').clear(); this.pendingReview = null; this.reviewVisible = false; this.reviewSnapshot = null
        this.$message.success(job.payload.decision === 'approve' ? '完工审核已通过' : '完工申请已驳回'); await this.load(); this.$emit('updated')
      } catch (error) {
        if (completionErrorIsDefinitive(error)) { this.store('review').clear(); this.pendingReview = null; this.reviewVisible = false; await this.load() }
        this.$message.error(error.userMessage || error.message || '审核结果尚未确认，请继续原操作。')
      } finally { this.reviewBusy = false }
    },
    async submitReceipt() {
      if (this.submitting || !this.$can('production.output.warehouse') || (!this.pendingReceipt && !this.canSubmit)) return
      this.submitting = true
      try {
        const job = this.pendingReceipt || this.store('receipt').prepare({ id: this.selectedLine.output_record_id, payload: { client_command_id: this.newCommand('receipt'), expected_version: this.selectedLine.output_business_version, ...this.form } })
        this.pendingReceipt = job
        await warehouseProductionOutput(job.id, job.payload)
        this.store('receipt').clear(); this.pendingReceipt = null; this.$message.success('成品入库已正式过账'); await this.load(); this.$emit('updated')
      } catch (error) {
        if (completionErrorIsDefinitive(error)) { this.store('receipt').clear(); this.pendingReceipt = null; await this.load() }
        this.$message.error(error.userMessage || error.message || '入库结果尚未确认，请继续原操作。')
      } finally { this.submitting = false }
    },
    number(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) }
  }
}
</script>

<style scoped>
.receipt-card ::v-deep .el-table__empty-block{width:100%!important;min-width:0}
.completion-panel{min-width:0;color:#263d60}.completion-records{display:flex;align-items:center;gap:16px;padding:14px 16px;border:1px solid #e4eaf0;border-radius:4px;background:#fff;margin-bottom:20px;flex-wrap:wrap}.completion-records h3{margin:0;font-size:16px}.status-filter{width:130px}.record-options{display:flex;flex:1;min-width:220px;flex-wrap:wrap;gap:7px}.record-option{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:white;border:1px solid transparent;color:#365170;padding:6px;cursor:pointer;text-align:left;max-width:100%;overflow-wrap:anywhere}.record-option.selected{background:#f5faf8;border-color:#e0eee6}.record-option time{font-size:12px}.completion-layout{display:grid;grid-template-columns:minmax(280px,1.1fr) minmax(360px,1.35fr) minmax(270px,.95fr);gap:14px;align-items:stretch}.left-column,.middle-column{min-width:0}.completion-card{background:#fff;border:1px solid #e4eaf0;border-radius:4px;padding:18px;margin-bottom:14px;min-width:0}.completion-card h3{margin:0 0 24px;color:#20344e;font-size:16px}.completion-card h3 small{color:#8597b4;font-weight:400;font-size:13px}.fact-list{display:grid;grid-template-columns:100px minmax(0,1fr);gap:15px 8px;margin:0;font-size:13px}.fact-list dt{color:#7b8eac}.fact-list dd{margin:0;color:#2a4160;overflow-wrap:anywhere;white-space:pre-wrap}.quantity-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));margin-top:22px;padding:16px 0;background:#f9fbfc;border:1px solid #edf0f5;border-radius:4px;gap:5px}.quantity-strip div{display:flex;flex-direction:column;align-items:center;gap:12px;min-width:0}.quantity-strip span{color:#7b8eac;font-size:12px}.quantity-strip b{font-size:16px;font-weight:500}.note{margin-top:22px;font-size:13px}.note span{color:#7b8eac}.note p{line-height:1.6;white-space:pre-wrap;overflow-wrap:anywhere}.green{color:#079452!important}.orange{color:#e98416!important}.progress-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.progress-stats div{border:1px solid #e7edf2;border-radius:4px;text-align:center;padding:20px 4px}.progress-stats span,.progress-stats b{display:block;font-size:13px}.progress-stats b{font-size:20px;margin-top:14px;font-weight:500}.muted,.review-explanation{font-size:12px;color:#7b8eac;line-height:1.7}.receipt-card{min-height:300px;display:flex;flex-direction:column}.receipt-total{display:flex;justify-content:space-between;padding:18px 10px 0;margin-top:auto}.receipt-form h3{padding-bottom:16px;border-bottom:2px solid #079452}.receipt-form label{display:flex;flex-direction:column;gap:8px;margin-bottom:18px;color:#687f9f;font-size:13px}.receipt-form em{color:#e53935}.receipt-form .el-select,.receipt-form .el-input-number{width:100%}.receipt-form label small{color:#8190a2}.receipt-form>.el-button{width:100%;margin-top:10px}.locked-receipt{padding-top:55px;text-align:center}.locked-receipt>i{display:inline-flex;justify-content:center;align-items:center;background:#f4f7fc;border-radius:50%;width:86px;height:86px;font-size:38px;color:#7b8fb0}.locked-receipt h4{font-size:16px;margin:24px 0 16px}.locked-receipt p{font-size:13px;line-height:1.7;color:#7b8eac}.locked-receipt .el-alert{margin-top:48px;text-align:left}.check-caption{font-size:13px;color:#637c9d;margin:22px 0 6px}.check-row{display:flex;align-items:center;gap:10px;border-top:1px solid #edf1f5;padding:12px 0;font-size:13px}.check-row strong{font-weight:400;margin-left:auto}.check-row i{font-size:17px}.review-actions{display:flex;gap:10px;border-top:1px solid #edf1f5;padding-top:16px;margin-top:8px}.review-actions .el-button{flex:1;margin:0;padding:12px 6px}.review-facts{margin-top:20px}.review-summary{display:grid;grid-template-columns:1fr 1fr;gap:20px}.review-summary .fact-list{grid-template-columns:80px minmax(0,1fr)}.modal-quantities{margin:26px 0}.modal-check{display:flex;align-items:center;gap:18px;background:#f2faf7;padding:13px 16px;margin:8px 0;font-size:13px;border-radius:4px}.modal-check strong{color:#078649;min-width:110px}.modal-check span{color:#7b8eac}.modal-check i{font-size:21px}.completion-panel>.el-alert{margin-bottom:14px}
@media(max-width:1250px){.completion-layout{grid-template-columns:minmax(0,1fr) minmax(0,1.2fr)}.receipt-form{grid-column:1/3}.locked-receipt{padding-top:15px}.locked-receipt .el-alert{margin-top:20px}}
@media(max-width:760px){.completion-layout{grid-template-columns:minmax(0,1fr)}.receipt-form{grid-column:auto}.completion-records{gap:10px;padding:12px}.record-options{flex-basis:100%;min-width:0}.completion-card{padding:14px}.progress-stats{gap:8px}.fact-list{grid-template-columns:85px minmax(0,1fr)}.review-summary{grid-template-columns:minmax(0,1fr);gap:16px}.modal-check{gap:8px;flex-wrap:wrap}.modal-check span{flex-basis:100%}.record-option{gap:6px}.quantity-strip span{font-size:11px}.completion-records .el-pagination{max-width:100%;overflow-x:auto}}
</style>
<style>
.completion-review-dialog{max-width:calc(100vw - 32px);margin:0!important;display:flex;flex-direction:column;max-height:calc(100vh - 32px)}.el-dialog__wrapper:has(>.completion-review-dialog){display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}.completion-review-dialog .el-dialog__header{padding:22px 24px;border-bottom:1px solid #edf1f5}.completion-review-dialog .el-dialog__title{color:#20344e;font-weight:600}.completion-review-dialog .el-dialog__body{padding:24px;overflow-y:auto;min-height:0}.completion-review-dialog .el-dialog__footer{padding:16px 24px 24px}.completion-review-dialog .el-form{margin-top:26px}.completion-review-dialog .el-form-item{margin-bottom:10px}@media(max-width:480px){.completion-review-dialog .el-dialog__body{padding:16px}.completion-review-dialog .el-dialog__footer{padding:12px 16px 16px}.completion-review-dialog .el-dialog__footer .el-button{margin:0 4px;max-width:48%;white-space:normal}}
</style>
