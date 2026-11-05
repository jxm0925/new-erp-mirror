<template>
  <el-dialog :visible.sync="visible" :title="dialogTitle" width="1100px" top="4vh" :close-on-click-modal="false" append-to-body>
    <div v-if="errors.length" class="as-errors">
      <div class="as-errors-title">请修正以下问题后再保存：</div>
      <table><tr v-for="(e, i) in errors" :key="i"><td class="as-err-pos">{{ e.field }}</td><td>{{ e.msg }}</td></tr></table>
    </div>
    <div class="as-grid">
      <div class="fi"><label>售后单号</label><span class="inp v mono">{{ reservationNumber || '…' }}</span><small>保存时正式占用</small></div>
      <div v-if="!order" class="fi"><label>原订单<b>*</b></label>
        <paged-record-select v-model="form.sales_order_id" :request="listSalesReturnSources" :selected-rows.sync="orderRows" :label="orderLabel" :columns="orderCols" title="选择原订单" placeholder="请选择已发货订单" :clearable="false" />
      </div>
      <div class="fi"><label>客户</label><span class="inp v">{{ customerLabel }}</span><small>取自原订单，不可改</small></div>
      <div class="fi"><label>负责人<b>*</b></label>
        <paged-record-select v-model="form.owner_legacy_id" :request="listUsers" :selected-rows.sync="ownerRows" :label="ownerLabel" :columns="ownerCols" :params="{ scope: 'sales', status: 'normal' }" title="选择负责人" placeholder="请选择负责人" />
      </div>
      <div class="fi"><label>问题类型<b>*</b></label><el-select v-model="form.issue_type" size="small"><el-option v-for="t in issueTypes" :key="t.value" :label="t.label" :value="t.value" /></el-select><small>质量故障 / 运输破损 / 错发漏发 / 安装问题 / 使用咨询 / 其他</small></div>
      <div class="fi"><label>保修判断<b>*</b></label><el-select v-model="form.warranty_status" size="small"><el-option label="保内" value="in_warranty" /><el-option label="保外" value="out_of_warranty" /><el-option label="待检测" value="pending_inspection" /></el-select><small>仅记录，本期不收费</small></div>
      <div class="fi"><label>客户反馈时间<b>*</b></label><el-date-picker v-model="form.reported_at" size="small" type="datetime" value-format="yyyy-MM-dd HH:mm" style="width:100%" /></div>
      <div class="fi full"><label>问题描述<b>*</b></label><el-input v-model="form.description" type="textarea" :rows="3" maxlength="2000" show-word-limit /></div>
      <div class="fi full"><label>图片 / 视频</label>
        <el-upload :show-file-list="false" :http-request="uploadFile" accept="image/*,video/*" multiple>
          <el-button size="mini" icon="el-icon-upload" :loading="uploading">上传</el-button>
        </el-upload>
        <div class="media-row" style="margin-top:6px">
          <div v-for="a in attachments" :key="a.id" class="media-item">
            <aftersales-attachment-media :attachment="a" />
            <div class="media-name" :title="a.original_name">{{ a.original_name }}</div>
            <i class="el-icon-close media-del" @click="removeAttachment(a)" />
          </div>
        </div>
      </div>
    </div>

    <div class="as-sec" style="margin-bottom:12px">
      <div class="as-sec-h">处理方式<span class="r"><small>可先只登记问题，受理后再定处理方式</small></span></div>
      <div class="chips">
        <div class="chip" :class="{ on: form.action_type === 'refund' }" @click="form.action_type = 'refund'"><b>退货退款</b><span>客户退回，冲减应收</span></div>
        <div class="chip" :class="{ on: form.action_type === 'exchange' }" @click="form.action_type = 'exchange'"><b>换货</b><span>退回旧件 + 补发新件</span></div>
        <div class="chip" :class="{ on: form.action_type === 'reship' }" @click="form.action_type = 'reship'"><b>补发</b><span>只发配件或整机</span></div>
        <div class="chip" :class="{ on: form.action_type === 'record' }" @click="form.action_type = 'record'"><b>仅记录</b><span>电话指导 / 上门，不动库存</span></div>
        <div class="chip" :class="{ on: form.action_type === 'compensation' }" @click="form.action_type = 'compensation'"><b>赔付记录</b><span>直接付给客户，只做记录</span></div>
        <div class="chip" :class="{ on: form.action_type === 'undecided' }" @click="form.action_type = 'undecided'"><b>暂不确定</b><span>受理后再选</span></div>
      </div>
    </div>

    <div class="as-sec">
      <div class="as-sec-h">涉及商品<span class="r"><small>从本订单已发货商品中选择；有序列号的逐台勾选</small></span></div>
      <el-table :data="eligibleRows" border size="mini" v-loading="loadingEligible">
        <el-table-column width="40"><template slot-scope="{ row }"><el-checkbox :value="row.checked" :disabled="row.available_qty <= 0" @change="v => toggleLine(row, v)" /></template></el-table-column>
        <el-table-column prop="line_no" label="行号" width="55" />
        <el-table-column label="商品 / SKU" min-width="160"><template slot-scope="{ row }">{{ row.product_name || row.item_name }}<span v-if="row.sku_name" class="muted"> / {{ row.sku_name }}</span></template></el-table-column>
        <el-table-column label="已发货" width="70" align="right"><template slot-scope="{ row }">{{ row.shipped_qty }}</template></el-table-column>
        <el-table-column label="已补发" width="70" align="right"><template slot-scope="{ row }">{{ row.reshipped_qty }}</template></el-table-column>
        <el-table-column label="退货占用" width="80" align="right"><template slot-scope="{ row }">{{ row.returned_qty }}</template></el-table-column>
        <el-table-column label="售后中" width="70" align="right"><template slot-scope="{ row }">{{ row.in_aftersales_qty }}</template></el-table-column>
        <el-table-column label="已完成售后" width="94" align="right"><template slot-scope="{ row }">{{ row.completed_aftersales_qty }}</template></el-table-column>
        <el-table-column label="可售后" width="70" align="right"><template slot-scope="{ row }"><b>{{ row.available_qty }}</b></template></el-table-column>
        <el-table-column label="序列号" min-width="190"><template slot-scope="{ row }">
          <template v-if="row.serial_tracked">
            <div v-for="s in row.serials" :key="s.inventory_serial_id" class="ser-row">
              <el-checkbox v-model="s.checked" :disabled="s.in_case || s.completed_case || !row.checked">{{ s.serial_no }}</el-checkbox>
              <el-tag v-if="s.in_case" size="mini" type="warning">售后中</el-tag>
              <el-tag v-else-if="s.completed_case" size="mini" type="info">已完成售后</el-tag>
            </div>
          </template>
          <span v-else class="muted">不管理序列号</span>
        </template></el-table-column>
        <el-table-column label="本次数量" width="90" align="right"><template slot-scope="{ row }">
          <el-input-number v-if="!row.serial_tracked" v-model="row.qty" size="mini" :min="0" :max="row.available_qty" :disabled="!row.checked" :controls="false" style="width:70px" />
          <span v-else>{{ selectedSerials(row).length }}</span>
        </template></el-table-column>
        <el-table-column label="备注" width="130"><template slot-scope="{ row }"><el-input v-model="row.remark" size="mini" placeholder="选填" :disabled="!row.checked" /></template></el-table-column>
      </el-table>
      <div v-if="!loadingEligible && orderSelected && !eligibleRows.length" class="empty-hint">该订单暂无已发货的可售后商品。</div>
      <div v-if="!orderSelected" class="empty-hint">请先选择原订单。</div>
    </div>

    <div class="as-foot">
      <span class="muted">已选 {{ selectedCount }} 件商品 · {{ serialCount }} 台设备</span>
      <div>
        <el-button size="small" @click="visible = false">取消</el-button>
        <el-button v-if="canSave" size="small" :loading="saving" @click="save(false)">保存待受理</el-button>
        <el-button v-if="canSave && canAccept" size="small" type="success" :loading="saving" @click="save(true)">保存并受理</el-button>
      </div>
    </div>
  </el-dialog>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import AftersalesAttachmentMedia from './AftersalesAttachmentMedia.vue'
import { listSalesReturnSources } from '@/api/erp/sales'
import { listUsers } from '@/api/erp/rbac'
import { createAftersalesCase, updateAftersalesCase, getAftersalesEligible, uploadAftersalesAttachment, deleteAftersalesAttachment } from '@/api/erp/aftersales'
import { reserveForCreatePage, clearCreatePageReservation } from '@/utils/documentNumberReservation'

const ISSUE_TYPES = [
  { value: 'quality', label: '质量故障' }, { value: 'transport_damage', label: '运输破损' },
  { value: 'wrong_shipment', label: '错发漏发' }, { value: 'installation', label: '安装问题' },
  { value: 'consultation', label: '使用咨询' }, { value: 'other', label: '其他' }
]

export default {
  name: 'AftersalesCaseForm',
  components: { PagedRecordSelect, AftersalesAttachmentMedia },
  props: { order: { type: Object, default: null } },
  data() {
    return {
      visible: false, saving: false, loadingEligible: false, editing: null, uploading: false,
      reservation: null, reservationNumber: '', errors: [],
      attachments: [], draftToken: '',
      eligibleRows: [], orderRows: [], ownerRows: [],
      form: this.blankForm(),
      issueTypes: ISSUE_TYPES, listSalesReturnSources, listUsers,
      orderCols: [{ prop: 'sales_order_no', label: '订单号' }, { prop: 'customer_name', label: '客户' }],
      ownerCols: [{ prop: 'username', label: '账号' }, { prop: 'nickname', label: '姓名' }]
    }
  },
  computed: {
    dialogTitle() {
      const no = this.order ? ` · ${this.order.sales_order_no}` : (this.orderRows[0] ? ` · ${this.orderRows[0].sales_order_no}` : '')
      return `${this.editing ? '编辑售后单' : '新建售后单'}${no}`
    },
    orderSelected() { return this.order || this.form.sales_order_id },
    customerLabel() {
      const o = this.order || this.orderRows[0]
      return o ? `${o.customer_name || ''}${o.customer_phone ? ' · ' + o.customer_phone : ''}` : '—'
    },
    canSave() { return this.$can('aftersales.create') },
    canAccept() { return this.$can('aftersales.handle') },
    selectedRows() { return this.eligibleRows.filter(r => r.checked) },
    selectedCount() { return this.selectedRows.length },
    serialCount() { return this.selectedRows.reduce((n, r) => n + this.selectedSerials(r).length, 0) }
  },
  methods: {
    // PagedRecordSelect 的 label 必须是函数（逐行渲染），传字符串会让选择弹窗渲染报错、无法确认和关闭。
    orderLabel(o) { return o ? `${o.sales_order_no} · ${o.customer_name || ''}` : '' },
    ownerLabel(u) { return u ? (u.nickname || u.username || u.name || String(u.id)) : '' },
    blankForm() {
      return { sales_order_id: null, owner_legacy_id: null, issue_type: 'quality', warranty_status: 'in_warranty', reported_at: '', description: '', action_type: 'undecided' }
    },
    currentUser() {
      try { return JSON.parse(localStorage.getItem('erp_user') || '{}') } catch (_) { return {} }
    },
    // 负责人候选来自业务员档案；当前登录人或原负责人可能不在档案里，先放进已选行，避免回显时报“已选记录不可用”。
    presetOwner(caseRow) {
      const u = this.currentUser()
      const id = caseRow ? caseRow.owner_legacy_id : u.legacy_id
      if (!id) return
      this.ownerRows = [{ id, nickname: caseRow ? caseRow.owner_name : (u.nickname || u.username) }]
      this.form.owner_legacy_id = id
    },
    async uploadFile({ file }) {
      this.uploading = true
      try {
        const { data } = await uploadAftersalesAttachment(file, {
          case_id: this.editing ? this.editing.id : null,
          draft_token: this.editing ? null : this.draftToken
        })
        this.attachments.push(data.data)
      } catch (e) { this.$message.error(e.response?.data?.message || '上传失败') } finally { this.uploading = false }
    },
    async removeAttachment(a) {
      try { await deleteAftersalesAttachment(a.id); this.attachments = this.attachments.filter(x => x.id !== a.id) }
      catch (e) { this.$message.error(e.response?.data?.message || '删除失败') }
    },
    open(caseRow = null) {
      this.editing = caseRow
      this.form = caseRow
        ? { sales_order_id: caseRow.sales_order_id, owner_legacy_id: caseRow.owner_legacy_id, issue_type: caseRow.issue_type, warranty_status: caseRow.warranty_status, reported_at: caseRow.reported_at ? caseRow.reported_at.slice(0, 16) : '', description: caseRow.description, action_type: 'undecided' }
        : this.blankForm()
      this.errors = []; this.orderRows = []; this.ownerRows = []
      this.attachments = (caseRow && caseRow.attachments || []).slice()
      this.draftToken = caseRow ? '' : `as-draft-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
      this.presetOwner(caseRow)
      this.visible = true
      this.$nextTick(async () => {
        if (!caseRow) await this.reserve()
        if (this.orderSelected) this.loadEligible()
      })
    },
    async reserve() {
      this.reservation = null; this.reservationNumber = ''
      try {
        this.reservation = await reserveForCreatePage('aftersales_case', `/sales/aftersales/${this.order ? this.order.id : 'create'}`)
        this.reservationNumber = this.reservation.number || this.reservation.document_no || ''
      } catch (e) { this.$message.error(e.userMessage || '售后单号预生成失败') }
    },
    async loadEligible() {
      this.loadingEligible = true
      try {
        const orderId = this.order ? this.order.id : this.form.sales_order_id
        const { data } = await getAftersalesEligible(orderId)
        this.eligibleRows = (data.data || []).map(r => ({
          ...r, checked: false, qty: 0, remark: '',
          serials: (r.serials || []).map(s => ({ ...s, checked: false }))
        }))
      } finally { this.loadingEligible = false }
    },
    selectedSerials(row) { return (row.serials || []).filter(s => s.checked) },
    toggleLine(row, v) {
      row.checked = v
      if (!v) { (row.serials || []).forEach(s => { s.checked = false }); row.qty = 0 }
    },
    buildItems() {
      const items = []
      for (const row of this.selectedRows) {
        if (row.serial_tracked) {
          for (const s of this.selectedSerials(row)) {
            items.push({ sales_order_line_id: row.sales_order_line_id, qty: 1, inventory_serial_id: s.inventory_serial_id, serial_no: s.serial_no, remark: row.remark || null })
          }
        } else if (row.qty > 0) {
          items.push({ sales_order_line_id: row.sales_order_line_id, qty: row.qty, inventory_serial_id: null, serial_no: null, remark: row.remark || null })
        }
      }
      return items
    },
    mapErrors(err) {
      const messages = err.response?.data?.errors
      if (!messages) { this.$message.error(err.response?.data?.message || '保存失败'); return }
      this.errors = Object.entries(messages).flatMap(([field, msgs]) => (Array.isArray(msgs) ? msgs : [msgs]).map(msg => ({ field: this.fieldLabel(field), msg })))
    },
    fieldLabel(f) {
      const first = String(f).split('.')[0]
      return ({ items: '涉及商品', description: '问题描述', reported_at: '反馈时间', issue_type: '问题类型', sales_order_id: '原订单', owner_legacy_id: '负责人' })[first] || first
    },
    async save(accept) {
      this.errors = []
      if (!this.orderSelected) { this.errors = [{ field: '原订单', msg: '请选择原订单' }]; return }
      if (!this.form.owner_legacy_id) { this.errors = [{ field: '负责人', msg: '请选择负责人' }]; return }
      const items = this.buildItems()
      if (!items.length) { this.errors = [{ field: '涉及商品', msg: '请至少选择一件商品' }]; return }
      const ownerName = this.ownerRows[0] ? (this.ownerRows[0].nickname || this.ownerRows[0].username) : null
      const payload = {
        ...this.form,
        sales_order_id: this.order ? this.order.id : this.form.sales_order_id,
        owner_name: ownerName,
        reported_at: this.form.reported_at ? this.form.reported_at.replace('T', ' ').slice(0, 16) + ':00' : null,
        items,
        ...(accept ? { accept: true } : {}),
        ...(!this.editing && this.reservation ? { reservation_token: this.reservation.reservation_token, creation_session_id: this.reservation.creation_session_id } : {})
      }
      this.saving = true
      try {
        const res = this.editing
          ? await updateAftersalesCase(this.editing.id, { ...payload, attachment_draft_token: undefined })
          : await createAftersalesCase({ ...payload, attachment_draft_token: this.draftToken })
        if (this.reservation) clearCreatePageReservation(this.reservation)
        this.$message.success(this.editing ? '售后单已保存' : accept ? '售后单已受理' : '售后单已保存待受理')
        const savedCase = res.data.data
        this.visible = false
        this.$emit('saved', savedCase)
        // 选了具体处理方式的新建单跳到详情并自动打开“添加处理”
        if (!this.editing && savedCase?.id && this.form.action_type && this.form.action_type !== 'undecided') {
          this.$router.push(`/sales/aftersales/${savedCase.id}/detail?action=${this.form.action_type}`)
        }
      } catch (e) { this.mapErrors(e) } finally { this.saving = false }
    }
  },
  watch: {
    'form.sales_order_id'(v) { if (v && this.visible && !this.editing) this.loadEligible() }
  }
}
</script>

<style scoped>
.as-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 16px; margin-bottom: 14px; }
.fi { display: flex; flex-direction: column; gap: 4px; }
.fi.full { grid-column: 1 / -1; }
.fi label { font-size: 12px; color: #5b6472; }
.fi label b { color: #ef4444; font-weight: 400; }
.fi small { color: #9aa3b2; font-size: 11px; }
.inp { border: 1px solid #dcdfe6; border-radius: 4px; padding: 7px 10px; font-size: 13px; color: #606266; }
.inp.v { background: #f7f8fa; }
.mono { font-family: Consolas, monospace; }
.muted { color: #9aa3b2; }
.media-row { display: flex; flex-wrap: wrap; gap: 8px; }
.media-item { position: relative; width: 72px; }
.media-thumb { width: 72px; height: 54px; object-fit: cover; border-radius: 4px; border: 1px solid #e4e7ed; cursor: pointer; display: flex; align-items: center; justify-content: center; background: #f5f7fa; color: #9aa3b2; font-size: 11px; flex-direction: column; gap: 2px; }
.video-thumb i { font-size: 18px; }
.media-name { font-size: 11px; color: #8a94a6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.media-del { position: absolute; top: -6px; right: -6px; background: #ef4444; color: #fff; border-radius: 50%; width: 16px; height: 16px; font-size: 10px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.chips { display: flex; gap: 8px; }
.chip { border: 1px solid #dcdfe6; border-radius: 6px; padding: 8px 12px; cursor: pointer; display: flex; flex-direction: column; gap: 2px; min-width: 130px; }
.chip b { font-size: 13px; }
.chip span { font-size: 11px; color: #8a94a6; }
.chip.on { border-color: #008b4b; background: rgba(0, 139, 75, .06); }
.as-sec { border-top: 1px solid #edf0f4; padding-top: 10px; }
.as-sec-h { font-weight: 600; font-size: 14px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: baseline; }
.as-sec-h small { color: #9aa3b2; font-weight: 400; }
.ser-row { display: flex; align-items: center; gap: 6px; }
.ser-row ::v-deep .el-checkbox__label { font-family: Consolas, monospace; font-size: 12px; }
.as-errors { background: #fef0f0; border: 1px solid #fbc4c4; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; }
.as-errors-title { color: #cf3f3f; font-weight: 600; margin-bottom: 6px; }
.as-errors table { width: 100%; border-collapse: collapse; font-size: 12px; }
.as-errors td { padding: 4px 8px; border-top: 1px solid #f6d5d5; }
.as-err-pos { width: 180px; color: #8a94a6; }
.empty-hint { color: #9aa3b2; text-align: center; padding: 16px 0; font-size: 13px; }
.as-foot { display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #edf0f4; padding-top: 12px; margin-top: 12px; }
</style>
