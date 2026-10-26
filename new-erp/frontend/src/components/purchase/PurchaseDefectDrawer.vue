<template>
  <el-dialog title="到货不合格品处理" :visible.sync="localVisible" width="min(960px, calc(100vw - 40px))" top="5vh" append-to-body :close-on-click-modal="false" @open="open" @closed="loadRevision++">
    <div class="defect-drawer-body" v-loading="loading">
      <el-alert title="处理动作均记录业务单号、操作人和时间；库存只接收合格数量，未决金额保持冻结。" type="warning" :closable="false" show-icon />
      <section v-for="row in rows" :key="row.id" class="defect-line-card">
        <header><div><b>{{ row.item_code }} / {{ row.item_name }}</b><small>到货 {{ qty(row.receipt_qty) }}，不合格 {{ qty(row.unqualified_qty) }}，剩余 {{ qty(row.remaining_qty) }}</small></div><el-tag size="mini" :type="Number(row.remaining_qty)>0?'warning':'success'">{{ Number(row.remaining_qty)>0?'待处理':'已处理' }}</el-tag></header>
        <div v-if="Number(row.remaining_qty)>0" class="create-form">
          <el-input v-model="forms[row.id].defect_reason" size="small" placeholder="瑕疵原因（必填）" />
          <el-select v-model="forms[row.id].handling_method" size="small"><el-option v-for="m in methods" :key="m.value" :label="m.label" :value="m.value" /></el-select>
          <el-input-number v-model="forms[row.id].handling_qty" size="small" :min="0.0001" :max="Number(row.remaining_qty)" controls-position="right" />
          <el-input v-model="forms[row.id].remark" size="small" placeholder="处理备注" />
          <el-button size="small" type="success" @click="createHandling(row)">创建处理</el-button>
        </div>
        <div v-for="h in row.handlings || []" :key="h.id" class="handling-card">
          <div><b>{{ methodText(h.handling_method) }} · {{ qty(h.handling_qty) }}</b><el-tag size="mini" :type="h.handling_status==='completed'?'success':'warning'">{{ statusText(h.handling_status) }}</el-tag></div>
          <p>处理单 {{ h.handling_no || '--' }}　业务单 {{ h.business_doc_no || '--' }}</p>
          <p>当前环节：{{ stepText(h.current_step) }}</p>
          <p v-if="h.result_description">处理结果：{{ h.result_description }}</p>
          <small>{{ timeText(h.created_at) }} · {{ h.created_by || '系统' }}</small>
          <div class="handling-actions">
            <el-button v-for="a in actions(h)" :key="a.value" type="text" size="mini" @click="runAction(h,a)">{{ a.label }}</el-button>
          </div>
        </div>
      </section>
      <el-empty v-if="!loading && !rows.length" description="本到货单没有不合格品" :image-size="64" />
      <el-pagination background layout="total, sizes, prev, pager, next" :current-page.sync="page" :page-size.sync="perPage" :page-sizes="[10,20,50,100]" :total="total" @current-change="load" @size-change="page=1;load()" />
    </div>
  </el-dialog>
</template>

<script>
import { erpDateTime } from '../../utils/dateTime'
import { actionDefectHandling, listDefectHandlings, saveDefectHandling } from '@/api/erp/purchase'

export default {
  name: 'PurchaseDefectDrawer',
  props: { visible: Boolean, receiptNo: { type: String, default: '' } },
  data: () => ({ loading: false, loadRevision: 0, page: 1, perPage: 10, total: 0, rows: [], forms: {}, methods: [{ value: 'return_supplier', label: '退供应商' }, { value: 'exchange', label: '换货' }, { value: 'concession', label: '让步接收' }, { value: 'repair', label: '返修' }, { value: 'scrap', label: '报废' }, { value: 'pending', label: '待决定' }] }),
  computed: {
    localVisible: { get() { return this.visible }, set(value) { this.$emit('update:visible', value) } }
  },
  watch: { receiptNo() { if (this.visible) this.open() }, visible(value) { if (!value) this.loadRevision++ } },
  methods: {
    open() { this.loadRevision++; this.page = 1; this.perPage = 10; this.forms = {}; this.rows = []; this.total = 0; this.loading = false; this.load() },
    emptyForm(row) { return { receipt_item_id: row.receipt_item_id, handling_method: 'return_supplier', handling_qty: Number(row.remaining_qty || 0), defect_reason: '', responsible_party: '供应商', remark: '' } },
    async load() {
      if (!this.receiptNo) return
      const revision = ++this.loadRevision
      const receiptNo = this.receiptNo
      this.loading = true
      try {
        const res = await listDefectHandlings({ receipt_no: receiptNo, page: this.page, per_page: this.perPage })
        if (revision !== this.loadRevision || receiptNo !== this.receiptNo || !this.visible) return
        this.rows = res.data.data || []
        this.total = Number(res.data.total || 0)
        this.rows.filter(row => !this.forms[row.id]).forEach(row => this.$set(this.forms, row.id, this.emptyForm(row)))
      } catch (error) { if (revision === this.loadRevision) this.$message.error(error.userMessage || '不合格品加载失败') }
      finally { if (revision === this.loadRevision) this.loading = false }
    },
    async createHandling(row) {
      const form = this.forms[row.id]
      if (!String(form.defect_reason || '').trim()) return this.$message.warning('请填写瑕疵原因')
      try {
        await saveDefectHandling(form)
        this.$message.success('处理单已创建，请继续执行后续动作')
        await this.load()
        const current = this.rows.find(record => record.id === row.id)
        if (current) this.$set(this.forms, row.id, this.emptyForm(current))
        this.$emit('changed')
      } catch (e) { this.$message.error(e.userMessage || '创建处理失败') }
    },
    actions(h) {
      const map = { concession_pending_approval: [{ value: 'approve_concession', label: '批准让步接收', result: true }], repair_pending_start: [{ value: 'start_repair', label: '开始返修' }], repair_in_progress: [{ value: 'complete_repair', label: '完成返修并复检', result: true }], scrap_pending_approval: [{ value: 'approve_scrap', label: '批准报废' }], scrap_pending_confirmation: [{ value: 'confirm_scrap', label: '确认实物报废', result: true }], exchange_pending_original_return: [{ value: 'confirm_exchange_return', label: '确认原件退回' }], exchange_pending_replacement_receipt: [{ value: 'complete_exchange', label: '确认替换品验收', result: true }] }
      return map[h.current_step] || []
    },
    async runAction(h, action) {
      try {
        let result = ''
        if (action.result) result = (await this.$prompt('请填写执行结果、处置或复检结论', '业务确认', { inputType: 'textarea', inputValidator: v => String(v || '').trim() ? true : '处理结果不能为空' })).value
        else await this.$confirm(`确认执行“${action.label}”？`, '业务确认', { type: 'warning' })
        await actionDefectHandling(h.id, { action: action.value, result_description: result }); this.$message.success(`${action.label}已完成`); await this.load(); this.$emit('changed')
      } catch (e) { if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '处理失败') }
    },
    qty(v) { return Number(v || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) },
    methodText(v) { return ({ return_supplier: '退供应商', exchange: '换货', concession: '让步接收', repair: '返修', scrap: '报废', pending: '待决定' })[v] || v },
    statusText(v) { return ({ pending: '待处理', processing: '处理中', completed: '已完成', cancelled: '已取消' })[v] || v },
    stepText(v) { return ({ return_pending_approval: '退货单待审核', return_pending_outbound: '待退回供应商', return_completed: '已退回供应商', exchange_pending_original_return: '待退回原件', exchange_pending_replacement_receipt: '待替换品到货验收', exchange_completed: '换货完成', concession_pending_approval: '让步接收待审批', concession_completed: '让步接收完成', repair_pending_start: '待开始返修', repair_in_progress: '返修中', repair_completed: '返修完成', scrap_pending_approval: '报废待审批', scrap_pending_confirmation: '待确认实物报废', scrap_completed: '报废完成', pending_decision: '待决定' })[v] || v || '--' },
    timeText(v) { return v ? erpDateTime(v, 19) : '--' }
  }
}
</script>

<style scoped>
.defect-drawer-body{padding:0 16px 24px;max-height:calc(85vh - 110px);overflow-y:auto}.defect-drawer-body .el-pagination{margin-top:16px;text-align:right}.defect-line-card{margin-top:12px;padding:12px;border:1px solid #dfe5e9;border-radius:4px}.defect-line-card>header,.handling-card>div:first-child{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}.defect-line-card header div{display:grid;gap:4px}.defect-line-card small,.handling-card p{color:#74808a}.create-form{display:grid;grid-template-columns:1fr 130px;gap:8px;margin-top:12px}.create-form .el-input-number,.create-form .el-button{width:100%}.handling-card{margin-top:10px;padding:10px;background:#f7f9fa;border-left:3px solid #10a15c}.handling-card p{margin:5px 0;font-size:12px}.handling-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
</style>
