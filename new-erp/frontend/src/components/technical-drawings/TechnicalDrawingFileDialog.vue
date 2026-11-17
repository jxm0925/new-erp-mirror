<template>
  <el-dialog class="drawing-dialog-shell" :title="title" :visible.sync="visible" custom-class="drawing-dialog" width="870px" append-to-body :close-on-click-modal="false" @close="closed">
    <technical-drawing-source-info v-if="showSource" :source="revision" class="drawing-file-source" />
    <el-table :data="files" border size="small" class="drawing-version-files">
      <el-table-column prop="original_name" label="文件名称" min-width="250" />
      <el-table-column prop="file_type_label" label="图纸类型" min-width="110" />
      <el-table-column label="操作" width="150"><template slot-scope="{row}"><div class="drawing-inline-actions">
        <el-button v-if="canPreview(row)" type="text" size="mini" :disabled="revision.can_download === false" @click="preview(row)">预览</el-button>
        <el-button type="text" size="mini" :disabled="revision.can_download === false" :loading="downloading === row.id" @click="download(row)">下载</el-button>
      </div></template></el-table-column>
    </el-table>
    <span slot="footer"><el-button size="small" @click="visible = false">关闭</el-button></span>
    <sales-order-attachment-preview-dialog :visible.sync="previewVisible" :file="previewFile" :preview-request="previewRequest" :download-request="downloadRequest" />
  </el-dialog>
</template>

<script>
import SalesOrderAttachmentPreviewDialog from '@/components/sales/SalesOrderAttachmentPreviewDialog.vue'
import TechnicalDrawingSourceInfo from './TechnicalDrawingSourceInfo.vue'
import { downloadTechnicalDrawing, previewTechnicalDrawing, drawingError } from '@/api/erp/technicalDrawings'
import '@/styles/technical-drawings.css'

export default {
  name: 'TechnicalDrawingFileDialog',
  components: { SalesOrderAttachmentPreviewDialog, TechnicalDrawingSourceInfo },
  props: { showSource: { type: Boolean, default: true } },
  data: () => ({ visible: false, revision: {}, previewVisible: false, previewFile: null, downloading: null, sequence: 0 }),
  computed: {
    title() { return [this.revision.drawing_no, this.revision.version_no ? `V${this.revision.version_no}` : '', this.revision.drawing_name || '图纸文件'].filter(Boolean).join(' · ') },
    revisionId() { return this.revision.revision_id || this.revision.id },
    files() { return this.revision.files && this.revision.files.length ? this.revision.files : this.revision.original_name ? [{ ...this.revision, id: 0, file_type_label: this.revision.file_type_label || '技术图' }] : [] }
  },
  methods: {
    open(drawing) {
      this.sequence += 1; this.previewVisible = false; this.downloading = null
      this.revision = { ...drawing, ...(drawing.technical_drawing || {}) }
      this.visible = true
    },
    close() { this.visible = false; this.previewVisible = false },
    closed() { this.sequence += 1; this.previewVisible = false; this.downloading = null },
    fileParams(fileId) { return { file_id: fileId, ...(this.revision.work_order_id ? { work_order_id: this.revision.work_order_id } : this.revision.usage_id ? { usage_id: this.revision.usage_id } : {}) } },
    canPreview(file) { return ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'].includes(file.mime_type) },
    previewRequest(id) { return previewTechnicalDrawing(id, this.fileParams(this.previewFile.file_id)) },
    downloadRequest(id) { return downloadTechnicalDrawing(id, this.fileParams(this.previewFile.file_id)) },
    preview(file) {
      this.previewFile = { ...file, file_id: file.id, attachment_id: this.revisionId, file_name: file.original_name, can_download: this.revision.can_download !== false, can_preview: this.canPreview(file) }
      this.previewVisible = true
    },
    async download(file) {
      if (this.downloading !== null || this.revision.can_download === false) return
      const sequence = this.sequence
      this.downloading = file.id
      try {
        const { data } = await downloadTechnicalDrawing(this.revisionId, this.fileParams(file.id))
        if (sequence !== this.sequence || !this.visible) return
        const url = URL.createObjectURL(data); const link = document.createElement('a')
        link.href = url; link.download = file.original_name || this.revision.drawing_name; link.click()
        setTimeout(() => URL.revokeObjectURL(url), 30000)
      } catch (error) { if (sequence === this.sequence) this.$message.error(drawingError(error, '图纸文件下载失败')) }
      finally { if (sequence === this.sequence) this.downloading = null }
    }
  }
}
</script>

<style scoped>
.drawing-file-source {
  margin-bottom: 16px;
}
</style>
