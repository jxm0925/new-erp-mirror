<template>
  <section class="crm-drawing-requests">
    <div class="crm-inline crm-opportunity-section">
      <h3 class="crm-section-title">{{ mine ? '待我绘制' : '图纸需求' }}</h3>
      <div class="crm-actions crm-inline">
        <el-select v-if="mine" v-model="status" size="small" @change="query"><el-option value="" label="全部状态" /><el-option v-for="item in metadata.statuses || []" :key="item.value" :value="item.value" :label="item.label" /></el-select>
        <el-input v-if="mine" v-model.trim="keyword" size="small" clearable placeholder="需求编号 / 需求名称" @keyup.enter.native="query" @clear="query" />
        <el-button size="small" icon="el-icon-refresh" :loading="loading" @click="query">刷新</el-button>
        <el-button v-if="!mine && editable && (metadata.abilities || {}).create" size="small" type="success" icon="el-icon-plus" @click="edit()">提图纸需求</el-button>
      </div>
    </div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-table v-loading="loading" :data="rows" size="small" border>
      <el-table-column prop="title" label="图纸需求" min-width="180" />
      <el-table-column v-if="mine" prop="customer_name" label="客户" min-width="145" />
      <el-table-column v-if="mine" prop="subject_label" label="来源业务" min-width="160" />
      <el-table-column prop="technician_name" label="负责技术" min-width="100" />
      <el-table-column prop="status_label" label="状态" min-width="100" />
      <el-table-column label="客户确认版" min-width="160"><template slot-scope="{row}">{{ confirmedLabel(row) }}</template></el-table-column>
      <el-table-column label="操作" min-width="195"><template slot-scope="{row}"><div class="crm-inline">
        <el-button type="text" size="mini" icon="el-icon-view" @click="open(row.id)">查看</el-button>
        <el-button v-if="(row.abilities || {}).edit" type="text" size="mini" icon="el-icon-edit" @click="edit(row.id)">编辑指派</el-button>
        <el-button v-if="(row.abilities || {}).deliver" type="text" size="mini" icon="el-icon-upload2" @click="open(row.id, true)">交图</el-button>
      </div></template></el-table-column>
    </el-table>
    <div class="crm-pager"><el-pagination background layout="total, prev, pager, next" :total="total" :current-page.sync="page" :page-size="10" @current-change="load" /></div>

    <el-dialog class="crm-dialog" :title="form.id ? '编辑图纸需求与指派' : '提图纸需求'" :visible.sync="formVisible" width="780px" append-to-body :close-on-click-modal="false" :before-close="closeForm">
      <el-alert v-if="formError" :title="formError" type="error" :closable="false" show-icon />
      <el-form v-loading="formLoading" label-width="110px" size="small" :disabled="saving || formLoading">
        <el-form-item label="图纸需求" required><el-input v-model.trim="form.title" maxlength="200" /></el-form-item>
        <el-form-item label="详细要求" required><el-input v-model.trim="form.requirement" type="textarea" :rows="4" maxlength="5000" /></el-form-item>
        <el-form-item label="负责技术" required><paged-record-select v-if="formVisible" :key="'technician-' + formSession" v-model="form.technician_legacy_id" :request="technicians" :columns="employeeColumns" :selected-rows="technicianRows" :label="employeeLabel" title="指派负责技术" placeholder="请选择负责技术" :clearable="false" /></el-form-item>
        <el-form-item label="已有客户来图"><paged-record-select v-if="formVisible" :key="'references-' + formSession" v-model="form.reference_attachment_ids" multiple :request="references" :params="formSubject" :columns="referenceColumns" :selected-rows="referenceRows" :label="referenceLabel" title="选择客户来图" placeholder="选择本线索 / 商机已有附件" /></el-form-item>
        <el-form-item label="补充客户来图"><crm-attachments v-if="formVisible" :key="'upload-' + formSession" v-model="uploads" @busy="uploading = $event" /></el-form-item>
      </el-form>
      <span slot="footer"><el-button size="small" :disabled="saving" @click="closeForm">取消</el-button><el-button size="small" type="success" :loading="saving" :disabled="formLoading || uploading || !!formError" @click="save">保存并指派</el-button></span>
    </el-dialog>

    <el-dialog class="crm-dialog" title="图纸需求详情" :visible.sync="detailVisible" width="1100px" append-to-body :close-on-click-modal="false">
      <el-alert v-if="detailError" :title="detailError" type="error" :closable="false" show-icon />
      <div v-loading="detailLoading" v-if="detail.id">
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="图纸需求">{{ detail.title }}</el-descriptions-item><el-descriptions-item label="负责技术">{{ detail.technician_name }}</el-descriptions-item>
          <el-descriptions-item label="来源业务">{{ detail.subject_label }}</el-descriptions-item><el-descriptions-item label="客户">{{ detail.customer_name }}</el-descriptions-item>
          <el-descriptions-item label="详细要求" :span="2"><span class="crm-drawing-requirement">{{ detail.requirement }}</span></el-descriptions-item>
          <el-descriptions-item label="客户来图" :span="2"><crm-attachments :value="detail.reference_attachments || []" :download-request="downloadReference" readonly /></el-descriptions-item>
        </el-descriptions>
        <div class="crm-inline crm-opportunity-section"><h3 class="crm-section-title">技术交图版本</h3><el-button v-if="(detail.abilities || {}).deliver" size="small" type="success" icon="el-icon-upload2" class="crm-actions" @click="deliver">上传方案图</el-button></div>
        <el-table :data="detail.revisions || []" size="small" border>
          <el-table-column prop="drawing_no" label="图纸编号" min-width="160" /><el-table-column prop="drawing_name" label="图纸名称" min-width="150" />
          <el-table-column label="版本" width="70"><template slot-scope="{row}">V{{ row.version_no }}</template></el-table-column><el-table-column prop="designer_name" label="绘制人" min-width="95" />
          <el-table-column label="客户确认" min-width="150"><template slot-scope="{row}">{{ row.confirmed_at ? date(row.confirmed_at) : '待客户确认' }}</template></el-table-column>
          <el-table-column label="操作" min-width="295"><template slot-scope="{row}"><div class="crm-inline"><el-button v-if="row.can_download" type="text" size="mini" @click="preview(row)">查看此版</el-button><el-button v-if="row.can_download" type="text" size="mini" @click="download(row)">下载</el-button><el-button type="text" size="mini" @click="$refs.drawingDetail.open(row.drawing_id)">图纸详情</el-button><el-button v-if="row.can_confirm && !row.confirmed_at" type="text" size="mini" :loading="confirming === row.id" @click="confirm(row)">客户确认此版</el-button></div></template></el-table-column>
        </el-table>
      </div>
      <span slot="footer"><el-button size="small" @click="detailVisible = false">关闭</el-button></span>
    </el-dialog>
    <technical-drawing-register-dialog ref="register" @saved="delivered" /><technical-drawing-detail-dialog ref="drawingDetail" @changed="changed" />
    <sales-order-attachment-preview-dialog :visible.sync="previewVisible" :file="previewFile" :preview-request="downloadRevision" :download-request="downloadRevision" />
  </section>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import TechnicalDrawingRegisterDialog from '@/components/technical-drawings/TechnicalDrawingRegisterDialog.vue'
import TechnicalDrawingDetailDialog from '@/components/technical-drawings/TechnicalDrawingDetailDialog.vue'
import CrmAttachments from './CrmAttachments.vue'
import SalesOrderAttachmentPreviewDialog from '@/components/sales/SalesOrderAttachmentPreviewDialog.vue'
import { downloadTechnicalDrawing } from '@/api/erp/technicalDrawings'
import { listCrmDrawingRequests, getCrmDrawingRequestMetadata, listCrmDrawingTechnicians, listCrmDrawingReferences, getCrmDrawingRequest, downloadCrmDrawingReference, saveCrmDrawingRequest, confirmCrmDrawingRequestRevision } from '@/api/erp/crmDrawingRequests'
import { crmDate, crmError } from '@/api/erp/crm'
import { systemFormCommand } from '@/utils/systemManagement'
import '@/styles/crm.css'

const blank = () => ({ id: null, title: '', requirement: '', technician_legacy_id: null, reference_attachment_ids: [] })
export default {
  name: 'CrmDrawingRequests',
  components: { PagedRecordSelect, CrmAttachments, TechnicalDrawingRegisterDialog, TechnicalDrawingDetailDialog, SalesOrderAttachmentPreviewDialog },
  props: { subject: { type: Object, default: () => ({}) }, mine: Boolean, editable: Boolean },
  data: () => ({ rows: [], metadata: {}, page: 1, total: 0, loading: false, error: '', keyword: '', status: '', sequence: 0, formVisible: false, formLoading: false, formSession: 0, formError: '', form: blank(), uploads: [], uploading: false, saving: false, command: null, technicianRows: [], referenceRows: [], detailVisible: false, detailLoading: false, detailError: '', detail: {}, detailSequence: 0, confirming: null, previewVisible: false, previewFile: null, employeeColumns: [{ prop: 'name', label: '技术人员' }, { prop: 'department_name', label: '部门' }], referenceColumns: [{ prop: 'name', label: '文件名称' }, { prop: 'source_label', label: '来源' }] }),
  computed: {
    context() { return JSON.stringify([this.subject.type, this.subject.id, this.mine]) },
    subjectParams() { return this.mine ? { tab: 'mine' } : { subject_type: this.subject.type, subject_id: this.subject.id } },
    formSubject() { return { subject_type: this.form.subject_type || this.subject.type, subject_id: this.form.subject_id || this.subject.id } }
  },
  watch: {
    context: { immediate: true, handler() { this.sequence += 1; this.page = 1; this.status = this.mine ? 'pending' : ''; this.initialize() } },
    detailVisible(value) { if (!value) { this.detailSequence += 1; this.previewVisible = false } }
  },
  beforeDestroy() { this.sequence += 1; this.formSession += 1; this.detailSequence += 1 },
  methods: {
    technicians: listCrmDrawingTechnicians,
    references: listCrmDrawingReferences,
    employeeLabel: row => [row.name, row.department_name].filter(Boolean).join(' / '),
    referenceLabel: row => row.name || row.original_name || '附件',
    date: crmDate,
    downloadReference(id) { return downloadCrmDrawingReference(this.detail.id, id) },
    downloadRevision: downloadTechnicalDrawing,
    preview(row) { this.previewFile = { ...row, attachment_id: row.revision_id, file_name: `${row.drawing_no} V${row.version_no} · ${row.original_name || row.drawing_name}`, can_download: row.can_download, can_preview: ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'].includes(row.mime_type) }; this.previewVisible = true },
    async download(row) {
      try { const { data } = await downloadTechnicalDrawing(row.revision_id); const url = URL.createObjectURL(data); const link = document.createElement('a'); link.href = url; link.download = row.original_name || `${row.drawing_no}-V${row.version_no}`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000) }
      catch (error) { this.$message.error(crmError(error, '图纸版本下载失败')) }
    },
    confirmedLabel(row) { const revision = row.confirmed_revision || {}; return revision.drawing_no ? `${revision.drawing_no} · V${revision.version_no}` : '—' },
    async initialize() {
      const context = this.context
      if (!this.mine && !this.subject.id) return
      this.metadata = {}; this.rows = []; this.total = 0
      try { const { data } = await getCrmDrawingRequestMetadata(this.subjectParams); if (context === this.context) this.metadata = data.data || data }
      catch (error) { if (context === this.context) this.error = crmError(error, '图纸需求资料加载失败') }
      if (context === this.context) this.load()
    },
    query() { this.page = 1; this.load() },
    async load() {
      const sequence = ++this.sequence
      this.loading = true; this.error = ''
      try {
        const { data } = await listCrmDrawingRequests({ ...this.subjectParams, status: this.status || undefined, keyword: this.keyword || undefined, page: this.page, per_page: 10 })
        if (sequence !== this.sequence) return
        const result = Array.isArray(data.data) ? data : data.data || data
        this.rows = result.data || []; this.total = Number(result.total || (result.meta && result.meta.total) || 0)
      } catch (error) { if (sequence === this.sequence) { this.rows = []; this.total = 0; this.error = crmError(error, '图纸需求加载失败') } }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    async edit(id) {
      const session = ++this.formSession
      this.formVisible = true; this.formLoading = !!id; this.formError = ''; this.command = null; this.uploads = []; this.technicianRows = []; this.referenceRows = []; this.form = blank()
      if (!id) return
      try {
        const { data } = await getCrmDrawingRequest(id)
        if (session !== this.formSession || !this.formVisible) return
        this.form = { ...blank(), ...(data.data || data) }
        this.referenceRows = this.form.reference_attachments || []
        this.form.reference_attachment_ids = this.referenceRows.map(row => row.id)
        this.technicianRows = [{ id: this.form.technician_legacy_id, name: this.form.technician_name }]
      } catch (error) { if (session === this.formSession) this.formError = crmError(error, '图纸需求加载失败') }
      finally { if (session === this.formSession) this.formLoading = false }
    },
    closeForm() { if (this.saving) return; this.formVisible = false; this.formSession += 1 },
    async save() {
      if (this.saving || this.formLoading || this.uploading || this.formError) return
      if (!this.form.title || !this.form.requirement || !this.form.technician_legacy_id) return this.$message.warning('请填写图纸需求、详细要求并指派负责技术')
      const payload = { id: this.form.id, version: this.form.version, ...this.formSubject, title: this.form.title, requirement: this.form.requirement, technician_legacy_id: this.form.technician_legacy_id, reference_attachment_ids: Array.from(new Set([...this.form.reference_attachment_ids, ...this.uploads.map(row => row.id)])) }
      this.command = systemFormCommand(this.command, payload)
      this.saving = true
      try { await saveCrmDrawingRequest({ ...payload, request_key: this.command.id }); this.$message.success('图纸需求已指派'); this.formVisible = false; this.formSession += 1; this.changed() }
      catch (error) { this.$message.error(crmError(error, '图纸需求保存失败')) }
      finally { this.saving = false }
    },
    async open(id, startDelivery = false) {
      const sequence = ++this.detailSequence
      this.detailVisible = true; this.detailLoading = true; this.detailError = ''; this.detail = {}
      try { const { data } = await getCrmDrawingRequest(id); if (sequence !== this.detailSequence) return; this.detail = data.data || data; if (startDelivery && (this.detail.abilities || {}).deliver) this.$nextTick(() => this.deliver()) }
      catch (error) { if (sequence === this.detailSequence) this.detailError = crmError(error, '图纸需求详情加载失败') }
      finally { if (sequence === this.detailSequence) this.detailLoading = false }
    },
    deliver() { this.$refs.register.open({ source: { type: 'drawing_request', id: this.detail.id, version: this.detail.version }, sourceLabel: this.detail.title, designer: this.metadata.current_user }) },
    delivered() { this.changed(); if (this.detailVisible) this.open(this.detail.id) },
    changed() { this.load(); this.$emit('changed') },
    async confirm(row) {
      if (this.confirming) return
      let result
      try { result = await this.$prompt(`记录客户已确认 ${row.drawing_no} V${row.version_no}，请填写确认依据。`, '客户确认图纸版本', { inputType: 'textarea', inputValidator: value => !!(value && value.trim()) || '请填写客户确认依据', confirmButtonText: '确认此版', cancelButtonText: '取消' }) } catch (_) { return }
      this.confirming = row.id
      try { await confirmCrmDrawingRequestRevision(this.detail.id, row.id, { version: this.detail.version, reason: result.value.trim() }); this.$message.success('客户确认版本已记录'); this.changed(); this.open(this.detail.id) }
      catch (error) { this.$message.error(crmError(error, '客户确认记录失败')); if (error.response && error.response.status === 409) this.open(this.detail.id) }
      finally { this.confirming = null }
    }
  }
}
</script>

<style scoped>
.crm-drawing-requests {
  min-width: 0;
  box-sizing: border-box;
}
.crm-drawing-requests > .crm-inline {
  flex-wrap: wrap;
  gap: 12px;
}
.crm-drawing-requests > .crm-inline .el-input {
  width: 240px;
  max-width: 100%;
}
.crm-drawing-requests ::v-deep .el-button--text {
  color: #008b4b;
}
.crm-drawing-requests ::v-deep .el-table th.el-table__cell {
  background: #f8fafc;
  color: #475569;
}
.crm-drawing-requests ::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background: #008b4b;
  color: #ffffff;
}
.crm-drawing-requests ::v-deep .el-pagination.is-background .el-pager li:not(.disabled):hover {
  color: #008b4b;
}
.crm-drawing-requirement {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
</style>
