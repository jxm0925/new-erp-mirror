<template>
  <section class="confirmed-drawings">
    <div class="confirmed-drawings-head">
      <strong>客户确认的方案 / 图纸 <span>（{{ versions.length }} 份）</span></strong>
      <el-button v-if="editable" size="small" type="success" plain :disabled="!customerId" @click="openPicker">选择客户确认版本</el-button>
    </div>
    <el-table v-if="versions.length" :data="versions" border size="mini">
      <el-table-column label="来源需求" min-width="150"><template slot-scope="{row}">{{ row.request_no || `需求 #${row.request_id}` }}<div>{{ row.title || '' }}</div></template></el-table-column>
      <el-table-column label="图纸 / 文件" min-width="260"><template slot-scope="{row}">
        {{ row.drawing_no }} · {{ row.drawing_name }}
        <div v-for="file in drawingFiles(row)" :key="file.file_id || 0" class="confirmed-drawing-file">
          <span>{{ [file.file_type_label, file.original_name].filter(Boolean).join(' · ') }}</span>
          <el-button v-if="canReadFiles && previewable(file)" size="mini" type="text" @click="preview(file)">预览</el-button>
          <el-button v-if="canReadFiles" size="mini" type="text" @click="download(file)">下载</el-button>
        </div>
      </template></el-table-column>
      <el-table-column label="固定版本" width="82" align="center"><template slot-scope="{row}">V{{ row.version_no }}</template></el-table-column>
      <el-table-column label="客户确认记录" min-width="165"><template slot-scope="{row}">{{ row.confirmed_by_name || '—' }}<div>{{ row.confirmed_at || '—' }}</div></template></el-table-column>
      <el-table-column v-if="editable" label="操作" width="70"><template slot-scope="{row}">
        <el-button v-if="editable" size="mini" type="text" class="remove-version" @click="remove(row)">移除</el-button>
      </template></el-table-column>
    </el-table>
    <div v-else class="confirmed-drawings-empty">本行尚未关联客户确认版本</div>
    <system-record-picker :visible.sync="pickerVisible" :request="requestCandidates" :params="pickerParams" :value="versions" value-key="request_revision_id" :columns="columns" :label="versionLabel" :selectable="selectable" :config="{title: '为本订单明细选择客户确认版本', placeholder: '图纸编号 / 名称'}" @confirm="picked" />
    <sales-order-attachment-preview-dialog :visible.sync="previewVisible" :file="previewFile" :preview-request="previewRequest" :download-request="downloadRequest" />
  </section>
</template>

<script>
import SystemRecordPicker from '@/components/system/SystemRecordPicker.vue'
import SalesOrderAttachmentPreviewDialog from './SalesOrderAttachmentPreviewDialog.vue'
import { listConfirmedDrawingVersions, readOrderConfirmedDrawing, readConfirmedDrawingCandidate } from '@/api/erp/sales'

export default {
  name: 'SalesOrderConfirmedDrawings',
  components: { SystemRecordPicker, SalesOrderAttachmentPreviewDialog },
  props: {
    line: { type: Object, required: true },
    orderId: { type: [Number, String], default: null },
    customerId: { type: [Number, String], default: null },
    opportunityId: { type: [Number, String], default: null },
    editable: Boolean,
    frozenSources: { type: Object, default: () => ({}) }
  },
  data: () => ({ pickerVisible: false, openedContext: '', previewVisible: false, previewFile: null }),
  computed: {
    versions() { return (this.line.drawing_snapshot && this.line.drawing_snapshot.confirmed_versions) || [] },
    pickerParams() { return { customer_id: this.customerId, opportunity_id: this.opportunityId || undefined } },
    context() { return JSON.stringify([this.orderId, this.customerId, this.opportunityId, this.line.id || this.line.line_uuid]) },
    canReadFiles() { return !this.$can || this.$can('sales_order.view_attachment') },
    columns() { return [
      { prop: 'request_no', label: '来源需求', width: 160 },
      { prop: 'drawing_no', label: '图纸编号', width: 150 },
      { prop: 'drawing_name', label: '图纸名称', width: 180 },
      { prop: 'version_no', label: '版本', width: 65, format: row => `V${row.version_no}` },
      { prop: 'confirmed_by_name', label: '确认记录人', width: 110 },
      { prop: 'confirmed_at', label: '客户确认时间', width: 160 }
    ] }
  },
  watch: { context() { this.pickerVisible = false; this.previewVisible = false } },
  methods: {
    requestCandidates: listConfirmedDrawingVersions,
    versionLabel(row) { return `${row.drawing_no || row.drawing_name} V${row.version_no}` },
    selectable(row) { return Boolean(row.confirmed_at) && Number(row.customer_id) === Number(this.customerId) },
    openPicker() { this.openedContext = this.context; this.pickerVisible = true },
    picked(rows) {
      if (this.openedContext !== this.context) return
      if (rows.some(row => !this.selectable(row))) return this.$message.error('请先移除其他客户的图纸，再选择本客户确认版本')
      this.$emit('change', rows.map(row => ({ ...row })).sort((a, b) => Number(a.request_revision_id) - Number(b.request_revision_id)))
    },
    remove(row) { this.$emit('change', this.versions.filter(item => Number(item.request_revision_id) !== Number(row.request_revision_id))) },
    drawingFiles(row) {
      if (!row.files || !row.files.length) return [row]
      return row.files.map(file => ({ ...file, file_id: file.id, revision_id: row.revision_id, request_revision_id: row.request_revision_id, usage_id: row.usage_id }))
    },
    previewable(row) { return ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'].includes(row.mime_type) },
    requestFile(file, preview) {
      const sourceLine = this.frozenSources[file.request_revision_id] || (!this.editable ? this.line.id : null)
      if (this.orderId && sourceLine) return readOrderConfirmedDrawing(this.orderId, sourceLine, file.request_revision_id, preview, file.file_id)
      return readConfirmedDrawingCandidate(file)
    },
    preview(row) {
      this.previewFile = { ...row, _version: { ...row }, attachment_id: row.request_revision_id, file_name: row.original_name || this.versionLabel(row), can_preview: true, can_download: true }
      this.previewVisible = true
    },
    previewRequest() { return this.requestFile(this.previewFile._version, true) },
    downloadRequest() { return this.requestFile(this.previewFile._version, false) },
    async download(row) {
      try {
        const { data } = await this.requestFile(row, false)
        const url = URL.createObjectURL(new Blob([data], { type: row.mime_type || 'application/octet-stream' }))
        const link = document.createElement('a')
        link.href = url; link.download = row.original_name || this.versionLabel(row)
        document.body.appendChild(link); link.click(); link.remove()
        window.setTimeout(() => URL.revokeObjectURL(url), 30000)
      } catch (error) { this.$message.error(error.userMessage || '图纸下载失败，请稍后重试') }
    }
  }
}
</script>

<style scoped>
.confirmed-drawings { margin-bottom: 14px; min-width: 0; }
.confirmed-drawing-file { display: flex; align-items: baseline; flex-wrap: wrap; gap: 4px 8px; overflow-wrap: anywhere; }
.confirmed-drawing-file .el-button + .el-button { margin-left: 0; }
.confirmed-drawings-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
.confirmed-drawings-head strong { color: #334155; font-size: 13px; }
.confirmed-drawings-head strong span, .confirmed-drawings-empty { color: #64748b; font-size: 12px; font-weight: 400; }
.confirmed-drawings-empty { padding: 10px 0; }
.remove-version { color: #dc2626; }
</style>
