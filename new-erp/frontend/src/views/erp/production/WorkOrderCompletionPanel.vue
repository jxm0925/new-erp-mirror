<template>
  <section class="completion-panel" v-loading="loading">
    <el-alert v-if="!completion" title="当前工单尚无已审核通过的完工事实，不能创建成品入库。" type="warning" :closable="false" show-icon />
    <template v-else>
      <div class="completion-layout">
        <div class="left-column">
          <section class="completion-card">
            <h3>完工事实 <small>（只读）</small></h3>
            <dl class="fact-list">
              <dt>Completion 号</dt><dd>{{ completion.completion_no }}</dd>
              <dt>完工提交人</dt><dd>{{ completion.submitted_by_name || `用户 ${completion.submitted_by_legacy_id}` }}</dd>
              <dt>提交时间</dt><dd>{{ completion.submitted_at || '-' }}</dd>
            </dl>
            <div class="quantity-strip">
              <div><span>完工数量</span><b>{{ number(completion.submitted_base_qty) }} {{ unitName }}</b></div>
              <div><span>良品数量</span><b>{{ number(completion.qualified_base_qty) }} {{ unitName }}</b></div>
              <div><span>不良数量</span><b>{{ number(completion.unqualified_base_qty + completion.scrapped_base_qty) }} {{ unitName }}</b></div>
              <div><span>附件</span><b>{{ (completion.attachments || []).length }} 个</b></div>
            </div>
            <div class="note"><span>备注</span><p>{{ completion.remark || completion.defect_reason || '无' }}</p></div>
          </section>
          <section class="completion-card review-card">
            <h3>审核结论 <small>（只读）</small></h3>
            <dl class="fact-list">
              <dt>审核人</dt><dd>{{ completion.reviewed_by_name || `用户 ${completion.reviewed_by_legacy_id}` }}</dd>
              <dt>审核时间</dt><dd>{{ completion.reviewed_at || '-' }}</dd>
              <dt>审核结论</dt><dd class="approved"><i class="el-icon-success" /> 审核通过</dd>
              <dt>审核备注</dt><dd>{{ completion.review_reason || '无' }}</dd>
            </dl>
          </section>
          <section class="completion-note">
            <b>说明：</b>
            <p>1. PC 端申报人员执行成品入库；微信端仅记录完工事实，不显示入库相关操作。</p>
            <p>2. Completion（完工事实）与 Finished Goods Receipt（成品入库）严格分离。</p>
            <p>3. 每次入库均生成来源唯一键与入库单号，库存过账可追溯。</p>
          </section>
        </div>

        <div class="middle-column">
          <section class="completion-card progress-card">
            <div class="card-heading"><h3>成品入库进度 <small>（基于良品）</small></h3></div>
            <div class="progress-stats">
              <div><span>应入良品</span><b class="green">{{ number(completion.qualified_base_qty) }} {{ unitName }}</b></div>
              <div><span>已入库</span><b>{{ number(postedQty) }} {{ unitName }}</b></div>
              <div><span>待入库</span><b class="orange">{{ number(remainingQty) }} {{ unitName }}</b></div>
            </div>
            <p class="muted">说明：进度仅基于良品数量，不包含不良品。</p>
          </section>
          <section class="completion-card receipt-card">
            <h3>入库记录 <small>（Finished Goods Receipt）</small></h3>
            <el-table :data="completion.receipts || []" border size="small" empty-text="暂无成品入库记录">
              <el-table-column type="index" label="序号" width="55" />
              <el-table-column prop="receipt_no" label="入库单号" min-width="150" />
              <el-table-column label="入库数量（良品）" width="125"><template slot-scope="{row}">{{ number(row.posted_base_qty) }} {{ unitName }}</template></el-table-column>
              <el-table-column label="仓库 / 库位" min-width="130"><template slot-scope="{row}">{{ row.warehouse_name || '-' }}<br>{{ row.location_name || '-' }}</template></el-table-column>
              <el-table-column prop="batch_no" label="批次" min-width="125" />
              <el-table-column prop="posted_by_name" label="入库操作人" width="105" />
              <el-table-column prop="posted_at" label="入库时间" min-width="145" />
            </el-table>
            <div class="receipt-total">合计 <b>{{ number(postedQty) }} {{ unitName }}</b></div>
          </section>
        </div>

        <aside class="completion-card receipt-form">
          <h3>新建成品入库</h3>
          <template v-if="receivableLines.length">
            <label v-if="receivableLines.length > 1">完工产出
              <el-select v-model="form.output_record_id" placeholder="请选择产出" @change="selectLine"><el-option v-for="line in receivableLines" :key="line.output_record_id" :label="line.output_no" :value="line.output_record_id" /></el-select>
            </label>
            <label>本次入库数量（良品）<em>*</em><el-input-number v-model="form.posted_base_qty" :min="0.00000001" :max="lineRemaining" :precision="8" :controls="false" @change="invalidateCommand" /><small>剩余可入库：{{ number(lineRemaining) }} {{ unitName }}</small></label>
            <label>仓库 <em>*</em><el-select v-model="form.warehouse_id" filterable placeholder="请选择仓库" @change="warehouseChanged"><el-option v-for="row in warehouses" :key="row.id" :label="row.warehouse_name" :value="row.id" /></el-select></label>
            <label>库位 <em>*</em><el-select v-model="form.location_id" filterable placeholder="请选择库位" @change="invalidateCommand"><el-option v-for="row in filteredLocations" :key="row.id" :label="row.location_name" :value="row.id" /></el-select></label>
            <label>批次 <em>*</em><el-input v-model.trim="form.batch_no" maxlength="80" @input="invalidateCommand" /></label>
            <div class="command-box"><span>幂等 Command ID</span><code>{{ commandId || '提交时生成' }}</code><span>版本 V{{ selectedLine && selectedLine.output_business_version || '-' }}</span></div>
            <el-alert v-if="form.posted_base_qty > lineRemaining" title="超额入库阻断：请修改入库数量。" type="error" :closable="false" show-icon />
            <el-button type="success" :loading="submitting" :disabled="!canSubmit" @click="submitReceipt">确认过账</el-button>
            <p class="irreversible">过账后将生成入库单并更新库存，操作不可逆。</p>
          </template>
          <el-result v-else icon="success" title="良品已全部入库" sub-title="当前完工事实没有待入库数量。" />
        </aside>
      </div>
    </template>
  </section>
</template>

<script>
import { listWorkOrderCompletions, warehouseProductionOutput } from '../../../api/erp/production'
import { listEntity } from '../../../api/erp/master'

export default {
  name: 'WorkOrderCompletionPanel',
  props: { workOrder: { type: Object, required: true } },
  data: () => ({ loading: false, submitting: false, completion: null, warehouses: [], locations: [], commandId: '', form: { output_record_id: null, posted_base_qty: 0, warehouse_id: null, location_id: null, batch_no: '' } }),
  computed: {
    unitName() { return this.workOrder.quantity && this.workOrder.quantity.unit_name || '' },
    postedQty() { return (this.completion && this.completion.receipts || []).reduce((sum, row) => sum + Number(row.posted_base_qty || 0), 0) },
    remainingQty() { return Math.max(0, Number(this.completion && this.completion.qualified_base_qty || 0) - this.postedQty) },
    receivableLines() { return (this.completion && this.completion.lines || []).filter(row => row.requires_warehouse && Number(row.remaining_receivable_base_qty || 0) > 0 && row.output_status === 'WAIT_WAREHOUSE') },
    selectedLine() { return this.receivableLines.find(row => Number(row.output_record_id) === Number(this.form.output_record_id)) || this.receivableLines[0] || null },
    lineRemaining() { return Number(this.selectedLine && this.selectedLine.remaining_receivable_base_qty || 0) },
    filteredLocations() { return this.locations.filter(row => Number(row.warehouse_id) === Number(this.form.warehouse_id)) },
    canSubmit() { return Boolean(this.selectedLine && this.form.posted_base_qty > 0 && this.form.posted_base_qty <= this.lineRemaining && this.form.warehouse_id && this.form.location_id && this.form.batch_no && this.$can('production.output.warehouse')) }
  },
  watch: { 'workOrder.id': { immediate: true, handler(id) { if (id) this.load() } } },
  methods: {
    async load() {
      this.loading = true
      try {
        const [completions, warehouses, locations] = await Promise.allSettled([
          listWorkOrderCompletions(this.workOrder.id, { page: 1, per_page: 100 }),
          this.$can('production.output.warehouse') ? listEntity('warehouses', { status: 'enabled', per_page: 100 }) : Promise.resolve({ data: { data: [] } }),
          this.$can('production.output.warehouse') ? listEntity('locations', { status: 'enabled', per_page: 100 }) : Promise.resolve({ data: { data: [] } })
        ])
        if (completions.status !== 'fulfilled') throw completions.reason
        const rows = completions.value.data.data || []
        this.completion = rows.find(row => row.status === 'APPROVED') || null
        this.warehouses = warehouses.status === 'fulfilled' ? warehouses.value.data.data || [] : []
        this.locations = locations.status === 'fulfilled' ? locations.value.data.data || [] : []
        this.resetForm()
      } catch (error) { this.$message.error(error.userMessage || '完工与入库事实加载失败') } finally { this.loading = false }
    },
    resetForm() {
      const line = this.receivableLines[0]
      this.form = { output_record_id: line && line.output_record_id || null, posted_base_qty: Number(line && line.remaining_receivable_base_qty || 0), warehouse_id: null, location_id: null, batch_no: '' }
      this.commandId = ''
    },
    selectLine() { this.form.posted_base_qty = this.lineRemaining; this.invalidateCommand() },
    warehouseChanged() { this.form.location_id = null; this.invalidateCommand() },
    invalidateCommand() { if (!this.submitting) this.commandId = '' },
    newCommand() { return `finished-goods-receipt-${this.workOrder.id}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}` },
    async submitReceipt() {
      if (!this.canSubmit || this.submitting) return
      this.commandId = this.commandId || this.newCommand()
      this.submitting = true
      try {
        await warehouseProductionOutput(this.selectedLine.output_record_id, { client_command_id: this.commandId, expected_version: this.selectedLine.output_business_version, warehouse_id: this.form.warehouse_id, location_id: this.form.location_id, batch_no: this.form.batch_no, posted_base_qty: this.form.posted_base_qty })
        this.$message.success('成品入库已正式过账')
        await this.load()
        this.$emit('updated')
      } catch (error) {
        if (error.response) this.commandId = ''
        this.$message.error(error.userMessage || '入库结果尚未确认，请使用原命令重试')
      } finally { this.submitting = false }
    },
    number(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) }
  }
}
</script>

<style scoped>
.completion-panel{min-height:420px}.completion-layout{display:grid;grid-template-columns:minmax(270px,1.05fr) minmax(440px,1.35fr) minmax(300px,.95fr);gap:14px;align-items:start}.left-column,.middle-column{min-width:0}.completion-card,.completion-note{background:#fff;border:1px solid #e4eaf0;border-radius:5px;padding:18px;margin-bottom:14px}.completion-card h3{margin:0 0 18px;color:#20344e;font-size:15px;border-bottom:2px solid #079452;padding-bottom:11px}.completion-card h3 small{color:#8a98aa;font-weight:400}.fact-list{display:grid;grid-template-columns:115px 1fr;gap:14px 8px;margin:0}.fact-list dt{color:#7d8ca0}.fact-list dd{margin:0;color:#2a4160}.quantity-strip{display:grid;grid-template-columns:repeat(4,1fr);margin-top:20px;padding:15px 0;border:1px solid #e9eef3;border-width:1px 0}.quantity-strip div{display:flex;flex-direction:column;align-items:center;border-right:1px solid #e9eef3}.quantity-strip div:last-child{border:0}.quantity-strip span,.note span{color:#7d8ca0;font-size:12px}.quantity-strip b{margin-top:9px;font-size:16px}.note{margin-top:16px}.note p{line-height:1.6}.approved,.green{color:#079452!important}.orange{color:#f57c00!important}.completion-note{font-size:12px;color:#607086;line-height:1.6}.completion-note p{margin:5px 0}.progress-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}.progress-stats div{border:1px solid #e7edf2;border-radius:5px;text-align:center;padding:20px 8px}.progress-stats span,.progress-stats b{display:block}.progress-stats span{color:#7d8ca0}.progress-stats b{font-size:20px;margin-top:12px}.muted,.irreversible{font-size:12px;color:#8090a3}.receipt-total{display:flex;justify-content:space-between;padding:14px 12px 0}.receipt-form label{display:flex;flex-direction:column;gap:7px;margin-bottom:16px;color:#5b6b80}.receipt-form em{color:#e53935}.receipt-form .el-select,.receipt-form .el-input-number{width:100%}.receipt-form label small{color:#8190a2}.command-box{display:grid;grid-template-columns:1fr auto;gap:7px;margin:18px 0;padding:12px;background:#f7f9fb;color:#6f7e91;font-size:12px}.command-box code{grid-column:1/3;word-break:break-all;color:#294563}.receipt-form>.el-button{width:100%;margin-top:18px}.irreversible{text-align:center}.review-card{min-height:170px}@media(max-width:1300px){.completion-layout{grid-template-columns:1fr 1fr}.receipt-form{grid-column:1/3}}@media(max-width:900px){.completion-layout{grid-template-columns:1fr}.receipt-form{grid-column:auto}.quantity-strip{grid-template-columns:repeat(2,1fr);row-gap:16px}}
</style>
