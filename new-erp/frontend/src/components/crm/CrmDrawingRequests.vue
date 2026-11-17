<template>
  <section class="crm-drawing-requests">
    <template v-if="!dialogOnly">
    <div class="crm-inline crm-opportunity-section">
      <h3 class="crm-section-title">{{ mine ? '待我绘制' : '图纸需求' }}</h3>
      <div class="crm-actions crm-inline">
        <el-select v-if="mine" v-model="status" size="small" @change="query"><el-option value="" label="全部状态" /><el-option v-for="item in metadata.statuses || []" :key="item.value" :value="item.value" :label="item.label" /></el-select>
        <el-input v-if="mine" v-model.trim="keyword" size="small" clearable placeholder="需求编号 / 需求名称" @keyup.enter.native="query" @clear="query" />
        <el-button size="small" icon="el-icon-refresh" :loading="loading" @click="load">刷新</el-button>
        <el-button v-if="!mine && editable && (metadata.abilities || {}).create" size="small" type="success" icon="el-icon-plus" @click="edit()">提图纸需求</el-button>
      </div>
    </div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-table v-if="mine" key="groups" v-loading="loading" :data="rows" :row-key="groupKey" :expand-row-keys="expandedGroups" size="small" border @expand-change="expandGroup">
      <el-table-column type="expand" width="48"><template slot-scope="{row: group}">
        <div class="crm-drawing-group">
          <el-alert v-if="group.requests.error" :title="group.requests.error" type="error" :closable="false" show-icon />
          <el-table v-loading="group.requests.loading" :data="group.requests.rows" size="small" border>
            <el-table-column label="图纸需求" min-width="180"><template slot-scope="{row}">{{ row.title }}<span class="crm-sub">{{ row.request_no }}</span></template></el-table-column>
            <el-table-column prop="technician_name" label="负责技术" min-width="100" />
            <el-table-column prop="status_label" label="状态" min-width="100" />
            <el-table-column label="修改意见" min-width="230"><template slot-scope="{row}"><div v-for="item in row.pending_modifications || []" :key="item.return_request_revision_id || item.id" class="crm-drawing-return"><b>{{ item.drawing_no }} · V{{ item.version_no }}</b><div>{{ item.return_reason }}</div></div></template></el-table-column>
            <el-table-column label="客户确认版" min-width="160"><template slot-scope="{row}">{{ confirmedLabel(row) }}</template></el-table-column>
            <el-table-column label="操作" width="195" fixed="right"><template slot-scope="{row}"><div class="crm-actions">
              <el-button type="text" size="mini" icon="el-icon-view" @click="open(row.id)">查看</el-button>
              <el-button v-if="(row.abilities || {}).edit" type="text" size="mini" icon="el-icon-edit" @click="edit(row.id)">编辑指派</el-button>
              <el-button v-if="(row.abilities || {}).deliver" type="text" size="mini" icon="el-icon-upload2" @click="open(row.id, true)">{{ deliveryText(row) }}</el-button>
              <el-button v-if="(row.abilities || {}).delete" type="text" size="mini" icon="el-icon-delete" class="crm-danger-link" :loading="deleting === row.id" :disabled="!!deleting || loading || group.requests.loading || saving" @click="remove(row)">删除</el-button>
            </div></template></el-table-column>
          </el-table>
          <div class="crm-pager"><el-pagination background layout="total, prev, pager, next" :total="group.requests.total" :current-page.sync="group.requests.page" :page-size="10" @current-change="loadGroup(group)" /></div>
        </div>
      </template></el-table-column>
      <el-table-column prop="subject_label" label="来源" width="80" />
      <el-table-column prop="subject_no" label="来源单号" min-width="180" />
      <el-table-column prop="subject_name" label="业务名称" min-width="200" />
      <el-table-column prop="customer_name" label="客户" min-width="150" />
      <el-table-column prop="request_count" label="需求数" width="90" align="center" />
    </el-table>
    <el-table v-else key="requests" v-loading="loading" :data="rows" size="small" border>
      <el-table-column prop="title" label="图纸需求" min-width="180" />
      <el-table-column prop="technician_name" label="负责技术" min-width="100" />
      <el-table-column prop="status_label" label="状态" min-width="100" />
      <el-table-column label="客户确认版" min-width="160"><template slot-scope="{row}">{{ confirmedLabel(row) }}</template></el-table-column>
      <el-table-column label="操作" min-width="195"><template slot-scope="{row}"><div class="crm-inline">
        <el-button type="text" size="mini" icon="el-icon-view" @click="open(row.id)">查看</el-button>
        <el-button v-if="(row.abilities || {}).edit" type="text" size="mini" icon="el-icon-edit" @click="edit(row.id)">编辑指派</el-button>
        <el-button v-if="(row.abilities || {}).deliver" type="text" size="mini" icon="el-icon-upload2" @click="open(row.id, true)">{{ deliveryText(row) }}</el-button>
        <el-button v-if="(row.abilities || {}).delete" type="text" size="mini" icon="el-icon-delete" class="crm-danger-link" :loading="deleting === row.id" :disabled="!!deleting || loading || saving" @click="remove(row)">删除</el-button>
      </div></template></el-table-column>
    </el-table>
    <div class="crm-pager"><el-pagination background layout="total, prev, pager, next" :total="total" :current-page.sync="page" :page-size="10" @current-change="load" /></div>
    </template>

    <el-dialog class="crm-dialog" :title="form.id ? '编辑图纸需求与指派' : '提图纸需求'" :visible.sync="formVisible" width="780px" append-to-body :close-on-click-modal="false" :before-close="closeForm">
      <el-alert v-if="formError" :title="formError" type="error" :closable="false" show-icon />
      <div v-if="formLoading" class="crm-muted" role="status">正在读取已保存的图纸需求…</div>
      <el-form v-else-if="!formError" label-width="110px" size="small" :disabled="saving">
        <el-form-item label="图纸需求" required><el-input v-model.trim="form.title" maxlength="200" /></el-form-item>
        <el-form-item label="详细要求" required><el-input v-model.trim="form.requirement" type="textarea" :rows="4" maxlength="5000" /></el-form-item>
        <el-form-item label="负责技术" required><paged-record-select v-if="formVisible" :key="'technician-' + formSession" v-model="form.technician_legacy_id" :request="technicians" :columns="employeeColumns" :selected-rows="technicianRows" :label="employeeLabel" title="指派负责技术" placeholder="请选择负责技术" :clearable="false" /></el-form-item>
        <el-form-item label="客户参考资料"><crm-drawing-references v-if="formVisible" :key="'references-' + formSession" v-model="referenceRows" :subject="referenceSubject" :submitted-ids="form.reference_attachment_ids" :disabled="saving || formLoading" @busy="uploading = $event" /></el-form-item>
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
          <el-descriptions-item label="客户参考资料" :span="2"><crm-drawing-references :value="detail.reference_attachments || []" readonly /></el-descriptions-item>
        </el-descriptions>
        <div class="crm-inline crm-opportunity-section"><h3 class="crm-section-title">技术交图版本</h3><el-button v-if="(detail.abilities || {}).deliver" size="small" type="success" icon="el-icon-upload2" class="crm-actions" @click="deliver">{{ (detail.pending_modifications || []).length ? '上传独立方案图' : '上传方案图' }}</el-button></div>
        <div class="crm-drawing-versions">
          <article v-for="row in detail.revisions || []" :key="row.id" class="crm-box crm-drawing-version">
            <div class="crm-inline crm-drawing-version-head">
              <div class="crm-inline crm-drawing-version-title"><b>{{ row.drawing_no }}</b><span>V{{ row.version_no }}</span><span class="crm-drawing-version-name">{{ row.drawing_name }}</span></div>
              <el-tag size="small" class="crm-drawing-version-status" :type="row.confirmed_at ? 'success' : 'info'">{{ row.review_status_label || (row.confirmed_at ? '客户已确认' : '待客户确认') }}</el-tag>
            </div>
            <div class="crm-inline crm-drawing-version-meta">
              <span v-if="row.designer_name">绘制人：{{ row.designer_name }}</span>
              <span>文件：{{ row.file_count || 1 }} 份</span>
              <span v-if="row.source_context && row.source_context.sales_user_name">负责销售：{{ row.source_context.sales_user_name }}</span>
              <span v-if="row.confirmed_at">客户确认：{{ date(row.confirmed_at) }}</span>
            </div>
            <div v-if="row.return_reason" class="crm-drawing-version-return">
              <div class="crm-drawing-return"><span class="crm-muted">修改意见：</span>{{ row.return_reason }}</div>
              <div v-if="row.returned_by_name || row.returned_at" class="crm-inline crm-drawing-version-meta"><span v-if="row.returned_by_name">提出人：{{ row.returned_by_name }}</span><span v-if="row.returned_at">提出时间：{{ date(row.returned_at) }}</span></div>
            </div>
            <div class="crm-inline crm-drawing-version-actions">
              <el-button v-if="row.can_download" type="text" size="mini" @click="$refs.files.open(row)">查看文件</el-button>
              <el-button type="text" size="mini" @click="$refs.drawingDetail.open(row.drawing_id)">图纸详情</el-button>
              <el-button v-if="row.can_modify" type="text" size="mini" icon="el-icon-edit" @click="modify(row)">按意见修改</el-button>
              <el-button v-if="row.can_request_changes" type="text" size="mini" :loading="requestingChanges === row.id" :disabled="!!confirming" @click="requestChanges(row)">要求修改</el-button>
              <el-button v-if="row.can_confirm && !row.confirmed_at" type="text" size="mini" :loading="confirming === row.id" :disabled="!!requestingChanges" @click="confirm(row)">客户确认此版</el-button>
            </div>
          </article>
          <el-empty v-if="!(detail.revisions || []).length" description="暂无交图版本" :image-size="60" />
        </div>
      </div>
      <span slot="footer"><el-button v-if="(detail.abilities || {}).delete" type="text" size="small" icon="el-icon-delete" class="crm-danger-link" :loading="deleting === detail.id" :disabled="!!deleting || detailLoading || saving" @click="remove(detail)">删除需求</el-button><el-button size="small" @click="detailVisible = false">关闭</el-button></span>
    </el-dialog>
    <technical-drawing-register-dialog ref="register" @saved="delivered" /><technical-drawing-detail-dialog ref="drawingDetail" @changed="changed" />
    <technical-drawing-file-dialog ref="files" :show-source="false" />
  </section>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import TechnicalDrawingRegisterDialog from '@/components/technical-drawings/TechnicalDrawingRegisterDialog.vue'
import TechnicalDrawingDetailDialog from '@/components/technical-drawings/TechnicalDrawingDetailDialog.vue'
import CrmDrawingReferences from './CrmDrawingReferences.vue'
import TechnicalDrawingFileDialog from '@/components/technical-drawings/TechnicalDrawingFileDialog.vue'
import { listCrmDrawingRequests, getCrmDrawingRequestMetadata, listCrmDrawingTechnicians, getCrmDrawingRequest, saveCrmDrawingRequest, deleteCrmDrawingRequest, confirmCrmDrawingRequestRevision, returnCrmDrawingRequestRevision } from '@/api/erp/crmDrawingRequests'
import { crmDate, crmError } from '@/api/erp/crm'
import { systemFormCommand } from '@/utils/systemManagement'
import '@/styles/crm.css'

const blank = () => ({ id: null, subject_type: '', subject_id: null, title: '', requirement: '', technician_legacy_id: null, reference_attachment_ids: [] })
export default {
  name: 'CrmDrawingRequests',
  components: { PagedRecordSelect, CrmDrawingReferences, TechnicalDrawingRegisterDialog, TechnicalDrawingDetailDialog, TechnicalDrawingFileDialog },
  props: { subject: { type: Object, default: () => ({}) }, mine: Boolean, editable: Boolean, dialogOnly: Boolean },
  data: () => ({ rows: [], expandedGroups: [], metadata: {}, page: 1, total: 0, loading: false, error: '', keyword: '', status: '', sequence: 0, formVisible: false, formLoading: false, formSession: 0, formError: '', form: blank(), uploading: false, saving: false, deleting: null, command: null, technicianRows: [], referenceRows: [], detailVisible: false, detailLoading: false, detailError: '', detail: {}, detailSequence: 0, confirming: null, requestingChanges: null, employeeColumns: [{ prop: 'name', label: '技术人员' }, { prop: 'department_name', label: '部门' }] }),
  computed: {
    context() { return JSON.stringify([this.subject.type, this.subject.id, this.mine]) },
    subjectParams() { return this.mine ? { tab: 'mine' } : { subject_type: this.subject.type, subject_id: this.subject.id } },
    formSubject() { return { subject_type: this.form.subject_type || this.subject.type, subject_id: this.form.subject_id || this.subject.id } },
    referenceSubject() { return { ...this.formSubject, ...(this.form.id ? { request_id: this.form.id } : {}) } }
  },
  watch: {
    context: { immediate: true, handler() { this.sequence += 1; this.formSession += 1; this.formVisible = false; this.uploading = false; this.page = 1; this.expandedGroups = []; this.status = this.mine ? 'todo' : ''; if (!this.dialogOnly) this.initialize() } },
    detailVisible(value) { if (!value) { this.detailSequence += 1; if (this.$refs.files) this.$refs.files.close() } }
  },
  beforeDestroy() { this.sequence += 1; this.formSession += 1; this.detailSequence += 1 },
  methods: {
    technicians: listCrmDrawingTechnicians,
    employeeLabel: row => [row.name, row.department_name].filter(Boolean).join(' / '),
    date: crmDate,
    confirmedLabel(row) { const revision = row.confirmed_revision || {}; return revision.drawing_no ? `${revision.drawing_no} · V${revision.version_no}` : '—' },
    deliveryText(row) { const count = (row.pending_modifications || []).length; return count === 1 ? '按意见修改' : count > 1 ? '查看修改版本' : '交图' },
    async initialize() {
      const context = this.context
      if (!this.mine && !this.subject.id) return
      this.metadata = {}; this.rows = []; this.total = 0
      try { const { data } = await getCrmDrawingRequestMetadata(this.subjectParams); if (context === this.context) this.metadata = data.data || data }
      catch (error) { if (context === this.context) this.error = crmError(error, '图纸需求资料加载失败') }
      if (context === this.context) this.load()
    },
    query() { this.page = 1; this.expandedGroups = []; this.load() },
    async load() {
      const sequence = ++this.sequence
      const filters = { status: this.status || undefined, keyword: this.keyword || undefined }
      this.loading = true; this.error = ''
      try {
        const { data } = await listCrmDrawingRequests({ ...this.subjectParams, grouped: this.mine ? 1 : undefined, ...filters, page: this.page, per_page: 10 })
        if (sequence !== this.sequence) return
        const result = Array.isArray(data.data) ? data : data.data || data
        this.total = Number(result.total || (result.meta && result.meta.total) || 0)
        const lastPage = Math.max(1, Math.ceil(this.total / 10))
        if (this.page > lastPage) { this.page = lastPage; await this.load(); return }
        if (this.mine) {
          const previous = new Map(this.rows.filter(group => group.filters && group.filters.status === filters.status && group.filters.keyword === filters.keyword).map(group => [this.groupKey(group), group.requests]))
          this.rows = (result.data || []).map(group => ({ ...group, filters, requests: previous.get(this.groupKey(group)) || { rows: [], page: 1, total: group.request_count, loading: false, error: '', sequence: 0 } }))
          this.expandedGroups = this.expandedGroups.filter(key => this.rows.some(group => this.groupKey(group) === key))
          await Promise.all(this.rows.filter(group => this.expandedGroups.includes(this.groupKey(group))).map(group => this.loadGroup(group)))
        } else this.rows = result.data || []
      } catch (error) { if (sequence === this.sequence) { this.rows = []; this.total = 0; this.expandedGroups = []; this.error = crmError(error, '图纸需求加载失败') } }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    groupKey(group) { return `${group.subject_type}:${group.subject_id}` },
    expandGroup(group, expandedRows) {
      this.expandedGroups = expandedRows.map(this.groupKey)
      if (this.expandedGroups.includes(this.groupKey(group))) this.loadGroup(group)
      else { group.requests.sequence += 1; group.requests.loading = false }
    },
    async loadGroup(group) {
      const state = group.requests; const sequence = ++state.sequence; const listSequence = this.sequence; const key = this.groupKey(group)
      state.loading = true; state.error = ''
      try {
        const { data } = await listCrmDrawingRequests({ tab: 'mine', subject_type: group.subject_type, subject_id: group.subject_id, ...group.filters, page: state.page, per_page: 10 })
        if (listSequence !== this.sequence || sequence !== state.sequence || !this.expandedGroups.includes(key)) return
        const result = Array.isArray(data.data) ? data : data.data || data
        state.rows = result.data || []; state.total = Number(result.total || (result.meta && result.meta.total) || 0); group.request_count = state.total
        const lastPage = Math.max(1, Math.ceil(state.total / 10))
        if (state.page > lastPage) { state.page = lastPage; await this.loadGroup(group) }
      } catch (error) { if (listSequence === this.sequence && sequence === state.sequence && this.expandedGroups.includes(key)) { state.rows = []; state.total = 0; state.error = crmError(error, '该业务的图纸需求加载失败') } }
      finally { if (listSequence === this.sequence && sequence === state.sequence) state.loading = false }
    },
    async edit(id) {
      const session = ++this.formSession
      this.formVisible = true; this.formLoading = !!id; this.formError = ''; this.command = null; this.uploading = false; this.technicianRows = []; this.referenceRows = []; this.form = { ...blank(), id: id || null, subject_type: this.subject.type || '', subject_id: this.subject.id || null }
      if (!id) return
      try {
        const { data } = await getCrmDrawingRequest(id)
        if (session !== this.formSession || !this.formVisible) return
        this.form = { ...blank(), ...(data.data || data) }
        const references = this.form.reference_attachments
        const selectedIds = new Set((references || []).map(file => String(file.id)))
        if (!Array.isArray(references) || (this.form.reference_attachment_ids || []).some(key => !selectedIds.has(String(key)))) {
          this.formError = '已选客户参考资料未能完整读取，请重新打开需求后再编辑'
          return
        }
        this.referenceRows = references
        this.technicianRows = [{ id: this.form.technician_legacy_id, name: this.form.technician_name }]
      } catch (error) { if (session === this.formSession) this.formError = crmError(error, '图纸需求加载失败') }
      finally { if (session === this.formSession) this.formLoading = false }
    },
    closeForm() { if (this.saving) return; this.formVisible = false; this.formSession += 1; this.uploading = false },
    async remove(row) {
      if (this.deleting || this.saving || !(row.abilities || {}).delete) return
      const { id, version, title } = row; const context = this.context
      this.deleting = id
      try {
        try { await this.$confirm(`确认删除图纸需求“${title}”？仅从未交图的需求可删除，删除后无法恢复。`, '删除图纸需求', { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' }) } catch (_) { return }
        if (context !== this.context || this._isDestroyed) return
        await deleteCrmDrawingRequest(id, { version })
        if (context !== this.context || this._isDestroyed) return
        if (this.detail.id === id) { this.detailVisible = false; this.detail = {} }
        if (this.form.id === id) this.closeForm()
        this.$message.success('图纸需求已删除'); this.changed()
      } catch (error) {
        if (context !== this.context || this._isDestroyed) return
        this.$message.error(crmError(error, '图纸需求删除失败，请刷新后重试'))
        this.changed()
        if (this.detailVisible && this.detail.id === id) this.open(id)
      } finally { this.deleting = null }
    },
    async save() {
      if (this.saving || this.formLoading || this.uploading || this.formError) return
      if (!this.formSubject.subject_type || !this.formSubject.subject_id) return this.$message.warning('未取得来源业务，请关闭后重新打开图纸需求')
      if (!this.form.title || !this.form.requirement || !this.form.technician_legacy_id) return this.$message.warning('请填写图纸需求、详细要求并指派负责技术')
      const session = this.formSession
      const payload = { id: this.form.id, version: this.form.version, ...this.formSubject, title: this.form.title, requirement: this.form.requirement, technician_legacy_id: this.form.technician_legacy_id, reference_attachment_ids: Array.from(new Set(this.referenceRows.map(row => row.id))) }
      this.command = systemFormCommand(this.command, payload)
      this.saving = true
      try { await saveCrmDrawingRequest({ ...payload, request_key: this.command.id }); if (session !== this.formSession) return; this.$message.success('图纸需求已指派'); this.formVisible = false; this.formSession += 1; this.changed() }
      catch (error) { if (session === this.formSession) this.$message.error(crmError(error, '图纸需求保存失败')) }
      finally { this.saving = false }
    },
    async open(id, startDelivery = false) {
      const sequence = ++this.detailSequence
      this.detailVisible = true; this.detailLoading = true; this.detailError = ''; this.detail = {}
      try { const { data } = await getCrmDrawingRequest(id); if (sequence !== this.detailSequence) return; this.detail = data.data || data; if (startDelivery && (this.detail.abilities || {}).deliver) this.$nextTick(() => { const pending = this.detail.pending_modifications || []; if (pending.length === 1 && pending[0].can_modify) this.modify(pending[0]); else if (!pending.length) this.deliver() }) }
      catch (error) { if (sequence === this.detailSequence) this.detailError = crmError(error, '图纸需求详情加载失败') }
      finally { if (sequence === this.detailSequence) this.detailLoading = false }
    },
    deliver() { this.$refs.register.open({ source: { type: 'drawing_request', id: this.detail.id, version: this.detail.version }, sourceLabel: this.detail.title, designer: this.metadata.current_user }) },
    modify(row) {
      if (!row.can_modify || this.detailLoading) return
      this.$refs.register.open({ action: 'modify', source: { type: 'drawing_request', id: this.detail.id, version: this.detail.version }, sourceLabel: this.detail.title, designer: this.metadata.current_user, drawing: { ...row, id: row.drawing_id, current_revision_id: row.current_revision_id, current_version: row.current_version }, lockedDrawing: true, modificationOpinion: row.return_reason, modificationVersion: row.version_no, returnRequestRevisionId: row.return_request_revision_id || row.id })
    },
    delivered() { this.changed(); if (this.detailVisible) this.open(this.detail.id) },
    changed() { if (!this.dialogOnly) this.load(); this.$emit('changed') },
    async requestChanges(row) {
      if (this.requestingChanges || this.confirming || !row.can_request_changes) return
      const requestId = this.detail.id; const version = this.detail.version; const sequence = this.detailSequence
      this.requestingChanges = row.id
      try {
        let result
        try { result = await this.$prompt(`请填写 ${row.drawing_no} V${row.version_no} 需要修改的内容。`, '要求技术修改图纸', { inputType: 'textarea', inputValidator: value => !!(value && value.trim()) && value.trim().length <= 1000 || '请填写1至1000字修改意见', confirmButtonText: '要求修改', cancelButtonText: '取消' }) } catch (_) { return }
        if (sequence !== this.detailSequence || !this.detailVisible) return
        const { data } = await returnCrmDrawingRequestRevision(requestId, row.id, { version, reason: result.value.trim() })
        this.$message.success('已要求技术修改此版图纸'); this.changed()
        if (sequence === this.detailSequence && this.detailVisible) this.detail = data.data || data
      } catch (error) { this.$message.error(crmError(error, '要求修改失败')); if (error.response && error.response.status === 409 && sequence === this.detailSequence && this.detailVisible) this.open(requestId) }
      finally { this.requestingChanges = null }
    },
    async confirm(row) {
      if (this.confirming || this.requestingChanges) return
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
.crm-drawing-requests ::v-deep .el-table .cell {
  word-break: break-word;
  overflow-wrap: anywhere;
}
.crm-drawing-requests ::v-deep .el-table__expanded-cell {
  padding: 0;
}
.crm-drawing-group {
  min-width: 0;
  padding: 12px 16px;
  box-sizing: border-box;
}
.crm-drawing-group .crm-actions .el-button + .el-button {
  margin-left: 0;
}
.crm-drawing-group .crm-pager {
  padding: 12px 0 0;
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
.crm-drawing-return {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  line-height: 1.6;
}
.crm-drawing-versions {
  display: grid;
  gap: 10px;
  min-width: 0;
}
.crm-drawing-version {
  min-width: 0;
  padding: 12px 14px;
  overflow-wrap: anywhere;
}
.crm-drawing-version-head {
  align-items: flex-start;
  justify-content: space-between;
}
.crm-drawing-version-title {
  flex: 1;
  color: #334155;
}
.crm-drawing-version-name {
  min-width: 0;
}
.crm-drawing-version-status {
  max-width: 100%;
  height: auto;
  line-height: 20px;
  white-space: normal;
}
.crm-drawing-version-meta {
  margin-top: 6px;
  color: #64748b;
  font-size: 13px;
}
.crm-drawing-version-return {
  margin-top: 8px;
}
.crm-drawing-version-actions {
  margin-top: 10px;
  padding-top: 6px;
  border-top: 1px solid #e2e8f0;
}
.crm-drawing-version-actions .el-button {
  min-width: 0;
  max-width: 100%;
  min-height: 32px;
  margin: 0;
  white-space: normal;
  text-align: left;
}
</style>
