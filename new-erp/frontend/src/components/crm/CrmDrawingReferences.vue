<template>
  <div class="crm-drawing-references">
    <div v-if="!readonly" class="crm-inline crm-reference-actions">
      <el-button size="small" icon="el-icon-folder-opened" :disabled="disabled || !!pendingUploads || !subject.subject_id" @click="pickerVisible = true">选择已有</el-button>
      <el-upload action="#" multiple :http-request="upload" :show-file-list="false" :disabled="disabled || !!pendingUploads || pickerVisible" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.dwg,.dxf,.step,.stp,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip">
        <el-button size="small" icon="el-icon-upload2" :loading="!!pendingUploads" :disabled="disabled || !!pendingUploads || pickerVisible">直接上传</el-button>
      </el-upload>
      <span class="crm-muted" role="status">{{ rows.length ? (editing ? '本需求已选 ' : '本次已选 ') + rows.length + ' 份' : (editing ? '本需求未选资料' : '本次未选资料') }}</span>
    </div>
    <div v-for="file in rows" :key="file.id" class="crm-reference-row">
      <div class="crm-reference-heading">
        <el-button type="text" size="small" class="crm-reference-name" @click="download(file)">{{ file.name || file.original_name || '附件' }}</el-button>
        <el-button v-if="!readonly" type="text" size="mini" icon="el-icon-close" :disabled="disabled" aria-label="移除参考资料" @click="setRows(rows.filter(item => String(item.id) !== String(file.id)))">移除</el-button>
      </div>
      <div class="crm-inline crm-reference-meta">
        <span v-if="editing">{{ submittedIds.some(id => String(id) === String(file.id)) ? '已随本需求提交' : '本次新增' }}</span>
        <span v-if="file.source_label">来源：{{ file.source_label }}</span>
        <span v-if="file.version_label">版本：{{ file.version_label }}</span>
        <span v-if="file.status_label">状态：{{ file.status_label }}</span>
      </div>
    </div>
    <span v-if="readonly && !rows.length" class="crm-muted">无</span>
    <system-record-picker v-if="!readonly" :visible.sync="pickerVisible" :request="references" :params="subject" :config="{ title: '选择客户参考资料', placeholder: '搜索文件名称' }" :columns="columns" :label="fileLabel" :value="rows.filter(file => !file.pending_save)" multiple @confirm="selectExisting" />
  </div>
</template>

<script>
import SystemRecordPicker from '@/components/system/SystemRecordPicker.vue'
import { listCrmDrawingReferences, downloadCrmDrawingReference } from '@/api/erp/crmDrawingRequests'
import { uploadCrmAttachment, crmError } from '@/api/erp/crm'

export default {
  name: 'CrmDrawingReferences',
  components: { SystemRecordPicker },
  props: { value: { type: Array, default: () => [] }, subject: { type: Object, default: () => ({}) }, submittedIds: { type: Array, default: () => [] }, readonly: Boolean, disabled: Boolean },
  data: () => ({ rows: [], pickerVisible: false, pendingUploads: 0, session: 0, columns: [{ prop: 'name', label: '文件名称', width: 220 }, { prop: 'source_label', label: '来源', width: 180 }, { prop: 'version_label', label: '版本', width: 100 }, { prop: 'status_label', label: '状态', width: 120 }] }),
  computed: {
    context() { return JSON.stringify(this.subject) },
    editing() { return !this.readonly && !!this.subject.request_id }
  },
  watch: {
    value: { immediate: true, handler(value) { this.rows = value.slice() } },
    context() { this.session += 1; this.pickerVisible = false; this.pendingUploads = 0; this.$emit('busy', false) }
  },
  beforeDestroy() { this.session += 1 },
  methods: {
    references: listCrmDrawingReferences,
    fileLabel: file => file.name || file.original_name || '附件',
    setRows(rows) { this.rows = Array.from(new Map(rows.map(file => [String(file.id), file])).values()); this.$emit('input', this.rows) },
    selectExisting(rows) { this.setRows([...rows, ...this.rows.filter(file => file.pending_save)]) },
    async upload(request) {
      if (this._isDestroyed || this.readonly || this.disabled) return
      if (request.file.size > 20 * 1024 * 1024) return this.$message.warning(`${request.file.name}：大小不能超过20MB`)
      const session = this.session; const form = new FormData()
      form.append('file', request.file)
      this.pendingUploads += 1; this.$emit('busy', true)
      try {
        const { data } = await uploadCrmAttachment(form)
        if (session !== this.session) return
        // 本地先合并再通知父组件，多个文件同时完成时不覆盖其它已选资料。
        this.setRows([...this.rows, { ...(data.data || data), pending_save: true, source_label: '新上传', version_label: null, status_label: '待保存' }])
      } catch (error) { if (session === this.session) this.$message.error(`${request.file.name}：${crmError(error, '参考资料上传失败')}`) }
      finally { if (session === this.session) { this.pendingUploads -= 1; this.$emit('busy', this.pendingUploads > 0) } }
    },
    async download(file) {
      const session = this.session
      try {
        const { data } = await downloadCrmDrawingReference(file)
        if (session !== this.session) return
        const url = URL.createObjectURL(data); const link = document.createElement('a')
        link.href = url; link.download = this.fileLabel(file); link.click()
        setTimeout(() => URL.revokeObjectURL(url), 30000)
      } catch (error) { if (session === this.session) this.$message.error(crmError(error, '参考资料下载失败')) }
    }
  }
}
</script>

<style scoped>
.crm-drawing-references,
.crm-reference-row {
  min-width: 0;
  max-width: 100%;
}
.crm-reference-actions {
  margin-bottom: 8px;
}
.crm-reference-row {
  padding: 6px 0;
  line-height: 1.5;
}
.crm-reference-row + .crm-reference-row {
  border-top: 1px solid #e2e8f0;
}
.crm-reference-heading {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  min-width: 0;
}
.crm-reference-name {
  flex: 1;
  min-width: 0;
  padding: 2px 0;
  color: #008b4b;
  text-align: left;
  white-space: normal;
  overflow-wrap: anywhere;
}
.crm-reference-heading > .el-button + .el-button {
  flex-shrink: 0;
  margin: 0;
  padding: 2px 0;
}
.crm-reference-meta {
  color: #64748b;
  font-size: 12px;
  line-height: 1.5;
  overflow-wrap: anywhere;
}
</style>
