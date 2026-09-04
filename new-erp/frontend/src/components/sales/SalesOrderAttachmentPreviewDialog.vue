<template>
  <el-dialog
    :title="file ? `附件预览：${file.file_name || file.original_name || '-'}` : '附件预览'"
    :visible="visible"
    width="84vw"
    top="5vh"
    append-to-body
    :close-on-click-modal="false"
    custom-class="sales-order-attachment-preview-dialog"
    @close="close"
    @closed="releaseUrl"
  >
    <div class="preview-meta">
      <strong>{{ file && (file.file_name || file.original_name) || '-' }}</strong>
      <span>{{ file && file.mime_type || '未知文件类型' }}</span>
    </div>

    <div v-loading="loading" class="preview-body">
      <iframe v-if="!loading && !error && isPdf && objectUrl" :src="objectUrl" title="PDF附件预览" />
      <div v-else-if="!loading && !error && isImage && objectUrl" class="preview-image-scroll">
        <img :src="objectUrl" :alt="file && (file.file_name || file.original_name)">
      </div>
      <div v-else-if="!loading && error" class="preview-error">
        <i class="el-icon-warning-outline" />
        <h3>附件暂时无法预览</h3>
        <p>{{ error }}</p>
        <el-button size="small" plain @click="loadPreview">重新加载</el-button>
      </div>
    </div>

    <span slot="footer">
      <el-button size="small" @click="close">关闭</el-button>
      <el-button v-if="canDownload" size="small" type="success" :loading="downloading" @click="download">下载</el-button>
    </span>
  </el-dialog>
</template>

<script>
import { downloadSalesOrderAttachment, previewSalesOrderAttachment } from '@/api/erp/sales'

export default {
  name: 'SalesOrderAttachmentPreviewDialog',
  props: {
    visible: { type: Boolean, default: false },
    file: { type: Object, default: null }
  },
  data: () => ({
    loading: false,
    downloading: false,
    objectUrl: '',
    error: ''
  }),
  computed: {
    mimeType() {
      return String((this.file && this.file.mime_type) || '').toLowerCase()
    },
    isPdf() {
      return this.mimeType === 'application/pdf'
    },
    isImage() {
      return ['image/jpeg', 'image/png', 'image/webp'].includes(this.mimeType)
    },
    canDownload() {
      return Boolean(this.file && this.file.attachment_id && this.file.can_download !== false)
    }
  },
  watch: {
    visible(value) {
      if (value) this.loadPreview()
    },
    file() {
      if (this.visible) this.loadPreview()
    }
  },
  beforeDestroy() {
    this.releaseUrl()
  },
  methods: {
    async loadPreview() {
      this.releaseUrl()
      this.error = ''
      if (!this.file || !this.file.attachment_id || this.file.can_preview !== true) {
        this.error = '附件暂时无法预览，请下载后查看。'
        return
      }
      this.loading = true
      try {
        const { data } = await previewSalesOrderAttachment(this.file.attachment_id)
        this.objectUrl = URL.createObjectURL(new Blob([data], { type: this.mimeType || 'application/octet-stream' }))
      } catch (error) {
        this.error = '附件暂时无法预览，请重新加载或下载后查看。'
      } finally {
        this.loading = false
      }
    },
    async download() {
      if (!this.canDownload) return
      this.downloading = true
      try {
        const { data } = await downloadSalesOrderAttachment(this.file.attachment_id)
        const url = URL.createObjectURL(new Blob([data], { type: this.mimeType || 'application/octet-stream' }))
        const link = document.createElement('a')
        link.href = url
        link.download = this.file.file_name || this.file.original_name || '附件'
        link.style.display = 'none'
        document.body.appendChild(link)
        link.click()
        link.remove()
        window.setTimeout(() => URL.revokeObjectURL(url), 30000)
      } catch (error) {
        this.$message.error('附件下载失败，请稍后重试')
      } finally {
        this.downloading = false
      }
    },
    close() {
      this.$emit('update:visible', false)
      this.$emit('close')
    },
    releaseUrl() {
      if (this.objectUrl) URL.revokeObjectURL(this.objectUrl)
      this.objectUrl = ''
      this.error = ''
      this.loading = false
    }
  }
}
</script>

<style scoped>
.preview-meta{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:-4px 0 10px;padding:0 2px;color:#64748b;font-size:12px}
.preview-meta strong{min-width:0;overflow:hidden;color:#172033;text-overflow:ellipsis;white-space:nowrap}
.preview-body{height:72vh;max-height:760px;min-height:420px;overflow:auto;border:1px solid #e4e9f0;border-radius:4px;background:#f5f7fa}
.preview-body iframe{display:block;width:100%;height:100%;border:0;background:#fff}
.preview-image-scroll{display:flex;min-height:100%;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}
.preview-image-scroll img{display:block;max-width:100%;height:auto;object-fit:contain}
.preview-error{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#64748b;text-align:center}
.preview-error i{font-size:42px;color:#e6a23c}
.preview-error h3{margin:12px 0 5px;color:#334155}
.preview-error p{margin:0 0 14px}
</style>

<style>
.sales-order-attachment-preview-dialog{max-height:90vh;overflow:hidden}
.sales-order-attachment-preview-dialog .el-dialog__body{padding-top:10px;padding-bottom:10px}
</style>
