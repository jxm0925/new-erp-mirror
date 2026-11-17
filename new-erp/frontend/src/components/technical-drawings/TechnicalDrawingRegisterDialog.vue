<template>
  <el-dialog class="drawing-dialog-shell" title="登记技术图纸" :visible.sync="visible" custom-class="drawing-dialog" width="870px" top="8vh" append-to-body :close-on-click-modal="false" :before-close="close">
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <div v-loading="loading">
      <div class="drawing-source-summary">
        <div>
          <span>关联文件</span>
          <b :title="fileSummary">
            {{ fileSummary }}
          </b>
        </div>
        <div>
          <span>来源业务</span>
          <b :title="sourceLabel">{{ sourceLabel || '—' }}</b>
        </div>
      </div>
      <el-radio-group v-model="form.action" class="drawing-modes" :disabled="saving || loading || validating || lockedDrawing" @change="changeAction">
        <el-radio label="new" :disabled="!canComplete">新画图纸</el-radio><el-radio label="modify" :disabled="!canComplete">修改已有图纸</el-radio><el-radio label="reuse">沿用已有版本</el-radio>
      </el-radio-group>
      <el-form ref="form" :model="form" :rules="rules" label-width="96px" size="small" class="drawing-register-form" :disabled="saving || loading || validating">
        <div class="drawing-form-grid">
          <template v-if="form.action === 'new'">
            <el-form-item label="图纸编号"><el-input value="整套图纸保存后自动生成一个图号" readonly /></el-form-item>
            <el-form-item label="图纸名称" prop="drawing_name"><el-input v-model.trim="form.drawing_name" maxlength="200" /></el-form-item>
          </template>
          <el-form-item v-else label="图纸编号" prop="drawing_id" class="full"><paged-record-select :key="'drawing-' + session" v-model="form.drawing_id" :request="loadCandidates" :params="candidateParams" :columns="drawingColumns" :label="drawingLabel" :selected-rows="selectedDrawings" :disabled="lockedDrawing || saving || loading || validating" title="选择图纸" placeholder="选择图号 / 图纸名称" @select="selectDrawing" /></el-form-item>
          <el-form-item v-if="form.action === 'reuse'" label="图纸版本" prop="revision_id" class="full"><paged-record-select :key="'revision-' + session" v-model="form.revision_id" :request="loadRevisions" :params="revisionParams" :disabled="!form.drawing_id || saving || loading || validating" :columns="revisionColumns" :label="revisionLabel" title="选择已有版本" placeholder="选择已完成的图纸版本" /></el-form-item>
          <template v-else>
            <el-form-item label="绘制人" prop="designer_legacy_id"><el-input v-if="isDrawingRequest" :value="designer.name || '本人'" disabled /><paged-record-select v-else v-model="form.designer_legacy_id" :request="loadEmployees" :params="candidateParams" :selected-rows="designer.id ? [designer] : []" :columns="employeeColumns" :label="employeeLabel" :disabled="saving || loading || validating" title="选择绘制人" placeholder="选择实际完成人" /></el-form-item>
            <el-form-item label="完成日期" prop="completed_date"><el-date-picker v-model="form.completed_date" type="date" value-format="yyyy-MM-dd" placeholder="选择完成日期" /></el-form-item>
            <el-form-item label="图纸版本"><el-input :value="versionLabel" disabled /></el-form-item>
            <el-form-item label="状态"><el-input value="已完成 / 待确认" disabled /></el-form-item>
            <el-form-item v-if="form.action === 'modify'" label="图纸名称" class="full"><el-input :value="selectedDrawing ? selectedDrawing.drawing_name : ''" disabled /></el-form-item>
            <el-form-item v-if="modificationOpinion" label="修改意见" class="full"><div class="drawing-modification-opinion"><b v-if="modificationVersion">V{{ modificationVersion }}：</b>{{ modificationOpinion }}</div></el-form-item>
            <el-form-item label="图纸文件" required class="full">
              <div class="drawing-file-picker">
                <input ref="fileInput" :key="session + '-' + form.action" class="drawing-file-native" type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.dwg,.dxf,.step,.stp,.zip" :disabled="filesDisabled" @change="selectFile">
                <input ref="replacementInput" :key="'replace-' + session + '-' + form.action" class="drawing-file-native" type="file" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.dwg,.dxf,.step,.stp,.zip" :disabled="filesDisabled" @change="selectReplacement">
                <el-button size="small" icon="el-icon-upload2" :disabled="filesDisabled" @click="$refs.fileInput && $refs.fileInput.click()">{{ form.action === 'modify' ? '追加文件' : '选择文件（可多选）' }}</el-button>
                <span class="drawing-file-placeholder">支持 PDF / 图片 / CAD / STEP / ZIP（每个文件≤50MB）</span>
              </div>
              <div v-if="form.action === 'modify'" class="drawing-note">{{ filesLoading ? '正在加载上一版本的整套文件…' : previousVersion ? `默认沿用 V${previousVersion} 的全部文件，可逐张更换；移除只影响本次新版本。` : '选择图号后显示上一版本的全部文件。' }}</div>
              <div v-if="filesError" class="drawing-file-load-error"><span>{{ filesError }}</span><el-button type="text" size="mini" :disabled="saving || validating" @click="loadPreviousFiles">重新加载</el-button></div>
              <el-table v-if="form.files.length" v-loading="filesLoading" :data="form.files" row-key="key" border size="small" class="drawing-registration-files">
                <el-table-column label="图纸类型" min-width="140"><template slot-scope="{row}"><el-form-item><el-select v-model="row.file_type" :disabled="filesDisabled" popper-class="drawing-select-popper" placeholder="选择类型"><el-option v-for="item in metadata.file_types || []" :key="item.value" :label="item.label" :value="item.value" /></el-select></el-form-item></template></el-table-column>
                <el-table-column label="文件名称" min-width="220"><template slot-scope="{row}"><div class="drawing-registration-file-name">{{ row.file ? row.file.name : row.original_name }}<div v-if="isPreviousFile(row) && row.file" class="drawing-note">原文件：{{ row.original_name }}</div></div></template></el-table-column>
                <el-table-column v-if="form.action === 'modify'" label="本次处理" width="80"><template slot-scope="{row}"><el-tag size="mini" :type="isPreviousFile(row) ? row.file ? 'warning' : 'info' : 'success'">{{ isPreviousFile(row) ? row.file ? '更换' : '沿用' : '新增' }}</el-tag></template></el-table-column>
                <el-table-column label="操作" :width="form.action === 'modify' ? 180 : 65"><template slot-scope="{row}"><div class="drawing-inline-actions"><el-button v-if="isPreviousFile(row)" type="text" size="mini" :disabled="filesDisabled" @click="replaceFile(row)">更换</el-button><el-button v-if="isPreviousFile(row) && row.file" type="text" size="mini" :disabled="filesDisabled" @click="restoreFile(row)">恢复沿用</el-button><el-button type="text" size="mini" :disabled="filesDisabled" @click="removeFile(row)">移除</el-button></div></template></el-table-column>
              </el-table>
            </el-form-item>
            <el-form-item :label="form.action === 'modify' ? '修改说明' : '备注'" prop="remark" class="full"><el-input v-model.trim="form.remark" type="textarea" :rows="4" maxlength="1000" :placeholder="form.action === 'modify' ? '说明本次实际修改的内容' : '请输入备注信息（如图纸说明、绘制依据或其他需要说明的内容），可选'" /></el-form-item>
          </template>
        </div>
      </el-form>
    </div>
    <div slot="footer" class="drawing-register-footer"><div class="drawing-note">{{ isDrawingRequest ? '绘制人登记为本人；整套文件共用图号和版本，由销售记录客户确认版本。' : '整套文件共用图号和版本；沿用已有版本不增加新画或修改数量。' }}</div><div class="drawing-footer-actions"><el-button size="small" :disabled="saving || validating" @click="close">取消</el-button><el-button type="success" size="small" :loading="saving" :disabled="loading || validating || sourceConflict || (form.action === 'modify' && !filesReady)" @click="save">{{ isDrawingRequest ? '提交方案图' : '保存记录' }}</el-button></div></div>
  </el-dialog>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { getTechnicalDrawingMetadata, listTechnicalDrawingCandidates, listTechnicalDrawingRevisions, listTechnicalDrawingEmployees, registerTechnicalDrawing, drawingError } from '@/api/erp/technicalDrawings'
import { systemFormCommand } from '@/utils/systemManagement'
import { deliverCrmDrawingRequest } from '@/api/erp/crmDrawingRequests'
import '@/styles/technical-drawings.css'

const blank = () => ({ action: 'new', drawing_name: '', files: [], drawing_id: null, revision_id: null, expected_revision_id: null, designer_legacy_id: null, completed_date: '', remark: '' })
export default {
  name: 'TechnicalDrawingRegisterDialog',
  components: { PagedRecordSelect },
  data: () => ({ visible: false, loading: false, saving: false, validating: false, session: 0, fileSequence: 0, fileLoadSequence: 0, filesLoading: false, filesReady: false, filesError: '', previousVersion: null, replacementKey: null, sourceConflict: false, lockedDrawing: false, modificationOpinion: '', modificationVersion: null, returnRequestRevisionId: null, source: {}, sourceLabel: '', fileName: '', metadata: {}, designer: {}, form: blank(), selectedDrawings: [], selectedDrawing: null, error: '', command: null, employeeColumns: [{ prop: 'name', label: '姓名' }, { prop: 'department_name', label: '部门' }], drawingColumns: [{ prop: 'drawing_no', label: '图纸编号' }, { prop: 'drawing_name', label: '图纸名称' }, { prop: 'current_version', label: '当前版本' }], revisionColumns: [{ prop: 'version_no', label: '版本', format: row => `V${row.version_no}` }, { prop: 'designer_name', label: '绘制人' }, { prop: 'completed_date', label: '完成日期' }, { prop: 'file_count', label: '文件数量' }] }),
  computed: {
    isDrawingRequest() { return this.source.type === 'drawing_request' },
    candidateParams() { return { source: this.reuseSource } },
    revisionParams() { return { source: this.reuseSource, drawing_id: this.form.drawing_id } },
    reuseSource() { const source = { ...this.source }; delete source.file_id; return source },
    canComplete() { return ['drawing_request', 'bom', 'work_order'].includes(this.source.type) || !!this.source.file_id },
    filesDisabled() { return this.saving || this.loading || this.validating || (this.form.action === 'modify' && !this.filesReady) },
    fileSummary() { return this.form.action === 'reuse' ? '沿用已有版本' : this.form.files.length ? `整套共 ${this.form.files.length} 个文件` : '选择图纸文件' },
    versionLabel() { return this.form.action === 'new' ? 'V1' : this.selectedDrawing ? `V${Number(this.selectedDrawing.current_version || 0) + 1}` : '选择原图号后自动生成' },
    rules() {
      const required = message => [{ required: true, message, trigger: 'change' }]
      if (this.form.action === 'reuse') return { drawing_id: required('请选择图纸'), revision_id: required('请选择已有版本') }
      return { ...(this.form.action === 'modify' ? { drawing_id: required('请选择原图号'), remark: required('请填写实际修改内容') } : { drawing_name: required('请输入图纸名称') }), designer_legacy_id: required('请选择实际绘制人'), completed_date: required('请选择完成日期') }
    }
  },
  beforeDestroy() { this.session += 1 },
  methods: {
    loadEmployees: listTechnicalDrawingEmployees,
    loadCandidates: listTechnicalDrawingCandidates,
    loadRevisions(params) { return listTechnicalDrawingRevisions(this.form.drawing_id, params) },
    employeeLabel: row => row.name,
    drawingLabel: row => `${row.drawing_no} · ${row.drawing_name}`,
    revisionLabel: row => `V${row.version_no} · ${row.designer_name} · ${row.completed_date}`,
    async open(options) {
      if (this.saving || this.validating) return
      const session = ++this.session
      this.visible = true; this.loading = true; this.error = ''; this.command = null; this.sourceConflict = false
      this.fileLoadSequence += 1; this.filesLoading = false; this.filesReady = false; this.filesError = ''; this.previousVersion = null; this.replacementKey = null
      this.lockedDrawing = !!options.lockedDrawing; this.modificationOpinion = options.modificationOpinion || ''; this.modificationVersion = options.modificationVersion || null; this.returnRequestRevisionId = options.returnRequestRevisionId || null
      this.source = { ...options.source }; this.sourceLabel = options.sourceLabel || ''; this.fileName = options.fileName || ''
      this.form = { ...blank(), action: this.lockedDrawing ? 'modify' : options.action || 'new' }; this.selectedDrawings = []; this.selectedDrawing = null; this.designer = options.designer || {}
      if (this.source.file_id) this.addFile(null, this.source.file_id)
      try {
        const { data } = await getTechnicalDrawingMetadata()
        if (session !== this.session || !this.visible) return
        this.metadata = data.data || data
        this.designer = this.metadata.current_user || this.designer
        this.form.designer_legacy_id = this.designer.id || null
        this.form.completed_date = this.metadata.today || ''
        if (options.drawing) { this.selectedDrawings = [options.drawing]; this.form.drawing_id = options.drawing.id || options.drawing.drawing_id; this.selectedDrawing = options.drawing; this.form.expected_revision_id = options.drawing.current_revision_id || options.drawing.revision_id }
        if (this.form.action === 'modify') await this.loadPreviousFiles()
        this.$nextTick(() => this.$refs.form && this.$refs.form.clearValidate())
      } catch (error) { if (session === this.session) this.error = drawingError(error, '登记资料加载失败') }
      finally { if (session === this.session) this.loading = false }
    },
    close() { if (this.saving || this.validating) return; this.visible = false; this.session += 1; if (this.sourceConflict) this.$emit('saved') },
    changeAction() {
      this.form.revision_id = null; this.error = ''
      this.loadPreviousFiles()
      this.$nextTick(() => this.$refs.form && this.$refs.form.clearValidate())
    },
    selectDrawing(row) {
      this.selectedDrawing = row; this.selectedDrawings = row ? [row] : []; this.form.revision_id = null; this.form.expected_revision_id = row ? row.current_revision_id : null
      this.loadPreviousFiles()
    },
    isPreviousFile(row) { return row.file_id !== null && row.file_id !== undefined },
    async loadPreviousFiles() {
      const sequence = ++this.fileLoadSequence; const session = this.session
      this.filesLoading = false; this.filesReady = false; this.filesError = ''; this.previousVersion = null; this.replacementKey = null
      this.form.files = this.form.files.filter(row => !this.isPreviousFile(row))
      if (this.form.action !== 'modify' || !this.form.drawing_id) return
      const revisionId = this.form.expected_revision_id
      if (!revisionId) { this.filesError = '无法取得上一版本，请重新选择图号。'; return }
      this.filesLoading = true
      try {
        const { data } = await listTechnicalDrawingRevisions(this.form.drawing_id, { source: this.reuseSource, ids: [revisionId], page: 1, per_page: 10 })
        if (session !== this.session || sequence !== this.fileLoadSequence || !this.visible) return
        const result = Array.isArray(data.data) ? data : data.data || data
        const revision = (result.data || []).find(row => String(row.id) === String(revisionId))
        if (!revision || !Array.isArray(revision.files) || !revision.files.length) { this.filesError = '上一版本文件不可用，请刷新后重新选择图号。'; return }
        // 按文件 ID 保留同类型的每一份文件，切换图号后的旧请求不能覆盖当前整套清单。
        const previousFiles = revision.files.map(file => ({ key: ++this.fileSequence, file: null, file_id: file.id, source_file_id: null, file_type: file.file_type, original_type: file.file_type, original_name: file.original_name }))
        this.form.files = this.form.files.filter(row => row.source_file_id).concat(previousFiles, this.form.files.filter(row => !row.source_file_id))
        this.previousVersion = revision.version_no; this.filesReady = true
      } catch (error) { if (session === this.session && sequence === this.fileLoadSequence) this.filesError = drawingError(error, '上一版本文件加载失败，请重试。') }
      finally { if (session === this.session && sequence === this.fileLoadSequence) this.filesLoading = false }
    },
    addFile(file, sourceFileId = null) { this.form.files.push({ key: ++this.fileSequence, file, file_id: null, source_file_id: sourceFileId, file_type: 'technical', original_name: file ? file.name : this.fileName }); if (!this.form.drawing_name) this.form.drawing_name = (file ? file.name : this.fileName).replace(/\.[^.]+$/, '').slice(0, 200) },
    selectFile(event) {
      const files = Array.from(event.target.files || [])
      event.target.value = ''
      if (this.filesDisabled) return
      if (files.some(file => file.size > 50 * 1024 * 1024)) return this.$message.error('每张图纸大小不能超过 50MB')
      files.forEach(file => this.addFile(file))
    },
    replaceFile(row) { if (this.filesDisabled) return; this.replacementKey = row.key; if (this.$refs.replacementInput) this.$refs.replacementInput.click() },
    selectReplacement(event) {
      const file = (event.target.files || [])[0]
      event.target.value = ''
      const row = this.form.files.find(item => item.key === this.replacementKey)
      this.replacementKey = null
      if (this.filesDisabled || !file || !row) return
      if (file.size > 50 * 1024 * 1024) return this.$message.error('每张图纸大小不能超过 50MB')
      row.file = file
    },
    restoreFile(row) {
      if (this.filesDisabled) return
      row.file = null
      row.file_type = row.original_type
    },
    async removeFile(row) {
      if (this.filesDisabled) return
      const session = this.session
      if (this.isPreviousFile(row)) {
        try { await this.$confirm(`确认从本次新版本移除“${row.original_name}”？上一版本仍保留该文件。`, '移除图纸文件', { type: 'warning', confirmButtonText: '移除', cancelButtonText: '取消' }) } catch (_) { return }
      }
      const index = this.form.files.indexOf(row)
      if (session !== this.session || !this.visible || this.filesDisabled || index < 0) return
      this.form.files.splice(index, 1); this.$nextTick(() => this.$refs.form && this.$refs.form.clearValidate())
    },
    async save() {
      if (this.saving || this.validating || this.loading || this.sourceConflict || (this.form.action === 'modify' && !this.filesReady)) return
      const session = this.session
      this.validating = true
      let valid
      try { valid = await this.$refs.form.validate().catch(() => false) }
      finally { this.validating = false }
      if (!valid || session !== this.session || !this.visible) return
      if (this.form.action !== 'reuse' && !this.form.files.length) return this.$message.warning('请选择整套图纸文件')
      const fileTypes = (this.metadata.file_types || []).map(type => type.value)
      if (this.form.action !== 'reuse' && this.form.files.some(row => !fileTypes.includes(row.file_type))) return this.$message.warning('请为每份图纸选择图纸类型')
      if (this.form.action === 'modify' && !this.form.expected_revision_id) return this.$message.warning('请重新选择原图号以取得当前版本')
      const { action } = this.form
      const source = { ...this.reuseSource }
      const original = this.form.files.find(row => row.source_file_id)
      if (original) source.file_id = original.source_file_id
      const files = action === 'reuse' ? [] : this.form.files.filter(row => row.file).map(row => row.file)
      let uploadIndex = 0
      const revisionFiles = this.form.files.map(row => ({ file_type: row.file_type, ...(this.isPreviousFile(row) ? { file_id: row.file_id } : row.source_file_id ? { source_file_id: row.source_file_id } : {}), ...(row.file ? { upload_index: uploadIndex++ } : {}) }))
      const payload = action === 'reuse' ? { action, source: this.reuseSource, revision_id: this.form.revision_id } : { action, source, designer_legacy_id: this.form.designer_legacy_id, completed_date: this.form.completed_date, remark: this.form.remark, ...(action === 'new' ? { drawing_name: this.form.drawing_name, file_types: this.form.files.map(row => row.file_type) } : { drawing_id: this.form.drawing_id, expected_revision_id: this.form.expected_revision_id, revision_files: revisionFiles }) }
      if (this.isDrawingRequest) { delete payload.source; delete payload.designer_legacy_id; payload.version = this.source.version; if (this.returnRequestRevisionId) payload.return_request_revision_id = this.returnRequestRevisionId }
      this.command = systemFormCommand(this.command, { ...payload, files: files.map(file => [file.name, file.size, file.lastModified]) })
      this.saving = true; this.error = ''
      try {
        const { data } = await (this.isDrawingRequest ? deliverCrmDrawingRequest(this.source.id, { ...payload, request_key: this.command.id }, files) : registerTechnicalDrawing({ ...payload, request_key: this.command.id }, files))
        const saved = data.data || data
        this.$message.success(action === 'new' && saved.drawing_no ? `技术图纸已登记，图纸编号：${saved.drawing_no}` : data.message || '技术图纸已登记'); this.visible = false; this.session += 1; this.$emit('saved', saved)
      } catch (error) { this.sourceConflict = !!(error.response && error.response.status === 409); this.error = drawingError(error, '图纸登记失败，请重试') + (this.sourceConflict ? ' 请关闭窗口并刷新来源业务后重试。' : '') }
      finally { this.saving = false }
    }
  }
}
</script>

<style scoped>
.drawing-modification-opinion {
  color: #334155;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  line-height: 1.6;
  padding-top: 7px;
}
.drawing-file-load-error {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  color: #ef4444;
  line-height: 1.5;
  margin-top: 8px;
}
</style>
