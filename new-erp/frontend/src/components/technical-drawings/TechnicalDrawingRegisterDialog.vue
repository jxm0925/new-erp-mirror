<template>
  <el-dialog class="drawing-dialog-shell" title="登记技术图纸" :visible.sync="visible" custom-class="drawing-dialog" width="870px" top="8vh" append-to-body :close-on-click-modal="false" :before-close="close">
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <div v-loading="loading">
      <div class="drawing-source-summary">
        <div><span>关联文件</span><b>{{ form.action === 'reuse' ? '沿用已有版本' : fileName || (file ? file.name : '选择图纸文件') }}</b></div>
        <div><span>来源业务</span><b>{{ sourceLabel }}</b></div>
      </div>
      <el-radio-group v-model="form.action" class="drawing-modes" :disabled="saving" @change="changeAction">
        <el-radio label="new" :disabled="!canComplete">新画图纸</el-radio><el-radio label="modify" :disabled="!canComplete">修改已有图纸</el-radio><el-radio label="reuse">沿用已有版本</el-radio>
      </el-radio-group>
      <el-form ref="form" :model="form" :rules="rules" label-width="96px" size="small" class="drawing-register-form" :disabled="saving || loading">
        <div class="drawing-form-grid">
          <template v-if="form.action === 'new'">
            <el-form-item label="图纸编号" prop="drawing_no"><el-input v-model.trim="form.drawing_no" maxlength="100" /></el-form-item>
            <el-form-item label="图纸名称" prop="drawing_name"><el-input v-model.trim="form.drawing_name" maxlength="200" /></el-form-item>
          </template>
          <el-form-item v-else label="图纸编号" prop="drawing_id" class="full"><paged-record-select :key="'drawing-' + session" v-model="form.drawing_id" :request="loadCandidates" :params="candidateParams" :columns="drawingColumns" :label="drawingLabel" :selected-rows="selectedDrawings" title="选择图纸" placeholder="选择图号 / 图纸名称" @select="selectDrawing" /></el-form-item>
          <el-form-item v-if="form.action === 'reuse'" label="图纸版本" prop="revision_id" class="full"><paged-record-select :key="'revision-' + session" v-model="form.revision_id" :request="loadRevisions" :params="revisionParams" :disabled="!form.drawing_id" :columns="revisionColumns" :label="revisionLabel" title="选择已有版本" placeholder="选择已完成的图纸版本" /></el-form-item>
          <template v-else>
            <el-form-item label="绘制人" prop="designer_legacy_id"><paged-record-select v-model="form.designer_legacy_id" :request="loadEmployees" :params="candidateParams" :columns="employeeColumns" :label="employeeLabel" title="选择绘制人" placeholder="选择实际完成人" /></el-form-item>
            <el-form-item label="完成日期" prop="completed_date"><el-date-picker v-model="form.completed_date" type="date" value-format="yyyy-MM-dd" placeholder="选择完成日期" /></el-form-item>
            <el-form-item label="图纸版本"><el-input :value="versionLabel" disabled /></el-form-item>
            <el-form-item label="状态"><el-input value="已完成 / 待确认" disabled /></el-form-item>
            <el-form-item v-if="form.action === 'new'" label="图纸类型" prop="drawing_type" class="full"><el-select v-model="form.drawing_type"><el-option v-for="item in metadata.drawing_types || []" :key="item.value" :label="item.label" :value="item.value" /></el-select></el-form-item>
            <el-form-item v-if="needsFile" label="图纸文件" required class="full"><input :key="session + '-' + form.action" class="drawing-file-input" type="file" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.dwg,.dxf,.step,.stp,.zip" :disabled="saving" @change="selectFile"></el-form-item>
            <el-form-item :label="form.action === 'modify' ? '修改说明' : '备注'" prop="remark" class="full"><el-input v-model.trim="form.remark" type="textarea" :rows="4" maxlength="2000" :placeholder="form.action === 'modify' ? '说明本次实际修改的内容' : '请输入备注信息（如图纸说明、绘制依据或其他需要说明的内容），可选'" /></el-form-item>
          </template>
        </div>
      </el-form>
    </div>
    <div slot="footer" class="drawing-register-footer"><div class="drawing-note">绘制人按实际完成人登记；沿用已有版本不增加新画或修改数量。</div><div class="drawing-footer-actions"><el-button size="small" :disabled="saving" @click="close">取消</el-button><el-button type="success" size="small" :loading="saving" :disabled="loading" @click="save">保存记录</el-button></div></div>
  </el-dialog>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { getTechnicalDrawingMetadata, listTechnicalDrawingCandidates, listTechnicalDrawingRevisions, listTechnicalDrawingEmployees, registerTechnicalDrawing, drawingError } from '@/api/erp/technicalDrawings'
import { systemFormCommand } from '@/utils/systemManagement'
import '@/styles/technical-drawings.css'

const blank = () => ({ action: 'new', drawing_no: '', drawing_name: '', drawing_type: 'assembly', drawing_id: null, revision_id: null, expected_revision_id: null, designer_legacy_id: null, completed_date: '', remark: '' })
export default {
  name: 'TechnicalDrawingRegisterDialog',
  components: { PagedRecordSelect },
  data: () => ({ visible: false, loading: false, saving: false, session: 0, source: {}, sourceLabel: '', fileName: '', file: null, metadata: {}, form: blank(), selectedDrawings: [], selectedDrawing: null, error: '', command: null, employeeColumns: [{ prop: 'name', label: '姓名' }, { prop: 'department_name', label: '部门' }], drawingColumns: [{ prop: 'drawing_no', label: '图纸编号' }, { prop: 'drawing_name', label: '图纸名称' }, { prop: 'current_version', label: '当前版本' }], revisionColumns: [{ prop: 'version_no', label: '版本', format: row => `V${row.version_no}` }, { prop: 'designer_name', label: '绘制人' }, { prop: 'completed_date', label: '完成日期' }, { prop: 'original_name', label: '文件名称' }] }),
  computed: {
    candidateParams() { return { source: this.reuseSource } },
    revisionParams() { return { source: this.reuseSource, drawing_id: this.form.drawing_id } },
    reuseSource() { const source = { ...this.source }; delete source.file_id; return source },
    needsFile() { return !this.source.file_id && this.form.action !== 'reuse' },
    canComplete() { return this.source.type === 'bom' || !!this.source.file_id },
    versionLabel() { return this.form.action === 'new' ? 'V1' : this.selectedDrawing ? `V${Number(this.selectedDrawing.current_version || 0) + 1}` : '选择原图号后自动生成' },
    rules() {
      const required = message => [{ required: true, message, trigger: 'change' }]
      if (this.form.action === 'reuse') return { drawing_id: required('请选择图纸'), revision_id: required('请选择已有版本') }
      return { ...(this.form.action === 'new' ? { drawing_no: required('请输入图纸编号'), drawing_name: required('请输入图纸名称'), drawing_type: required('请选择图纸类型') } : { drawing_id: required('请选择原图号'), remark: required('请填写实际修改内容') }), designer_legacy_id: required('请选择实际绘制人'), completed_date: required('请选择完成日期') }
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
      const session = ++this.session
      this.visible = true; this.loading = true; this.error = ''; this.command = null
      this.source = { ...options.source }; this.sourceLabel = options.sourceLabel || ''; this.fileName = options.fileName || ''; this.file = null
      this.form = { ...blank(), action: options.action || 'new' }; this.selectedDrawings = []; this.selectedDrawing = null
      try {
        const { data } = await getTechnicalDrawingMetadata()
        if (session !== this.session || !this.visible) return
        this.metadata = data.data || data
        this.form.completed_date = this.metadata.today || ''
        if (options.drawing) { this.selectedDrawings = [options.drawing]; this.form.drawing_id = options.drawing.id || options.drawing.drawing_id; this.selectedDrawing = options.drawing; this.form.expected_revision_id = options.drawing.current_revision_id || options.drawing.revision_id }
        this.$nextTick(() => this.$refs.form && this.$refs.form.clearValidate())
      } catch (error) { if (session === this.session) this.error = drawingError(error, '登记资料加载失败') }
      finally { if (session === this.session) this.loading = false }
    },
    close() { if (this.saving) return; this.visible = false; this.session += 1 },
    changeAction() { this.form.revision_id = null; this.file = null; this.error = ''; this.$nextTick(() => this.$refs.form && this.$refs.form.clearValidate()) },
    selectDrawing(row) { this.selectedDrawing = row; this.form.revision_id = null; this.form.expected_revision_id = row ? row.current_revision_id : null },
    selectFile(event) { const file = event.target.files && event.target.files[0]; if (file && file.size > 50 * 1024 * 1024) { event.target.value = ''; this.file = null; return this.$message.error('图纸大小不能超过 50MB') } this.file = file || null },
    async save() {
      if (this.saving || this.loading || !await this.$refs.form.validate().catch(() => false)) return
      if (this.needsFile && !this.file) return this.$message.warning('请选择图纸文件')
      if (this.form.action === 'modify' && !this.form.expected_revision_id) return this.$message.warning('请重新选择原图号以取得当前版本')
      const { action } = this.form
      const payload = action === 'reuse' ? { action, source: this.reuseSource, revision_id: this.form.revision_id } : { action, source: this.source, designer_legacy_id: this.form.designer_legacy_id, completed_date: this.form.completed_date, remark: this.form.remark, ...(action === 'new' ? { drawing_no: this.form.drawing_no, drawing_name: this.form.drawing_name, drawing_type: this.form.drawing_type } : { drawing_id: this.form.drawing_id, expected_revision_id: this.form.expected_revision_id }) }
      this.command = systemFormCommand(this.command, { ...payload, file: this.file ? [this.file.name, this.file.size, this.file.lastModified] : null })
      this.saving = true; this.error = ''
      try {
        const { data } = await registerTechnicalDrawing({ ...payload, request_key: this.command.id }, action === 'reuse' ? null : this.file)
        this.$message.success(data.message || '技术图纸已登记'); this.visible = false; this.session += 1; this.$emit('saved', data.data)
      } catch (error) { this.error = drawingError(error, '图纸登记失败，请重试') }
      finally { this.saving = false }
    }
  }
}
</script>
