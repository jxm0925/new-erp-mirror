<template>
  <section class="purchase-attachment-panel">
    <header>
      <div><h3>{{ title }}</h3><span>支持 PDF、图片、Word、Excel、压缩包，单个文件不超过 50MB，文件保存至 OSS。</span></div>
      <el-tag size="mini" type="info">已上传 {{ attachments.length }} 个</el-tag>
    </header>

    <div v-if="editable" class="attachment-upload-grid">
      <el-upload v-for="entry in uploadTypes" :key="entry.value" action="#" :show-file-list="false" :http-request="request => upload(request, entry.value)" :disabled="uploading">
        <div class="attachment-upload-card">
          <i :class="entry.icon" />
          <b>{{ entry.label }}</b>
          <span>点击选择文件上传</span>
        </div>
      </el-upload>
    </div>

    <div v-if="compact" class="compact-attachment-list">
      <div v-for="row in attachments" :key="row.id" class="compact-attachment-row">
        <div><b>{{ row.original_name }}</b><span>{{ typeText(row.attachment_type) }} · {{ fileSize(row.file_size) }} · {{ row.uploaded_by || '-' }}</span></div>
        <div><el-button v-if="row.previewable || isPreviewable(row)" type="text" size="mini" @click="preview(row)">预览</el-button><el-button type="text" size="mini" @click="download(row)">下载</el-button></div>
      </div>
      <el-empty v-if="!attachments.length" description="暂无采购附件" :image-size="48" />
    </div>
    <el-table v-else :data="attachments" size="mini" empty-text="暂无采购附件">
      <el-table-column prop="original_name" label="文件名称" min-width="220" />
      <el-table-column label="附件类型" width="110"><template slot-scope="{row}">{{ typeText(row.attachment_type) }}</template></el-table-column>
      <el-table-column label="大小" width="90"><template slot-scope="{row}">{{ fileSize(row.file_size) }}</template></el-table-column>
      <el-table-column prop="uploaded_by" label="上传人" width="100" />
      <el-table-column label="上传时间" width="160"><template slot-scope="{row}">{{ timeText(row.uploaded_at) }}</template></el-table-column>
      <el-table-column label="操作" width="150" fixed="right">
        <template slot-scope="{row}">
          <el-button v-if="row.previewable || isPreviewable(row)" type="text" size="mini" @click="preview(row)">预览</el-button>
          <el-button type="text" size="mini" @click="download(row)">下载</el-button>
          <el-button v-if="editable" type="text" size="mini" class="danger-link" @click="remove(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>
  </section>
</template>

<script>
import { deletePurchaseAttachment, downloadPurchaseAttachment, previewPurchaseAttachment, uploadPurchaseAttachment } from '@/api/erp/purchase'

const labels = { contract: '采购合同', quotation: '报价资料', delivery_note: '送货单', inspection_report: '验收报告', invoice: '发票资料', technical: '技术资料', other: '其他附件' }

export default {
  props: {
    documentType: { type: String, required: true },
    documentId: { type: [Number, String], default: null },
    draftToken: { type: String, default: '' },
    initialAttachments: { type: Array, default: () => [] },
    editable: { type: Boolean, default: true },
    compact: { type: Boolean, default: false },
    title: { type: String, default: '采购附件' }
  },
  data: () => ({ attachments: [], uploading: false }),
  computed: {
    uploadTypes() {
      return this.documentType === 'receipt'
        ? [{ value: 'delivery_note', label: '送货单', icon: 'el-icon-tickets' }, { value: 'inspection_report', label: '验收报告', icon: 'el-icon-document-checked' }, { value: 'invoice', label: '发票 / 其他', icon: 'el-icon-document' }]
        : [{ value: 'contract', label: '采购合同', icon: 'el-icon-document-checked' }, { value: 'quotation', label: '报价资料', icon: 'el-icon-money' }, { value: 'technical', label: '技术资料 / 其他', icon: 'el-icon-folder-opened' }]
    }
  },
  watch: {
    initialAttachments: { immediate: true, deep: true, handler(value) { this.attachments = (value || []).filter(row => row.status !== 'deleted').map(row => ({ ...row })) } }
  },
  methods: {
    async upload(request, attachmentType) {
      if (!this.documentId && !this.draftToken) return this.$message.error('附件草稿标识尚未准备好，请稍后重试')
      const form = new FormData()
      form.append('file', request.file)
      form.append('document_type', this.documentType)
      form.append('attachment_type', attachmentType)
      if (this.documentId) form.append('document_id', this.documentId)
      else form.append('draft_token', this.draftToken)
      this.uploading = true
      try {
        const response = await uploadPurchaseAttachment(form)
        this.attachments.unshift(response.data.data)
        this.$emit('change', this.attachments)
        this.$message.success('附件已上传到 OSS')
      } catch (error) { this.$message.error(error.userMessage || '附件上传失败') } finally { this.uploading = false }
    },
    async preview(row) {
      try { this.openBlob((await previewPurchaseAttachment(row.id)).data, row.original_name, true) } catch (error) { this.$message.error(error.userMessage || '附件预览失败') }
    },
    async download(row) {
      try { this.openBlob((await downloadPurchaseAttachment(row.id)).data, row.original_name, false) } catch (error) { this.$message.error(error.userMessage || '附件下载失败') }
    },
    async remove(row) {
      try {
        await this.$confirm(`确认删除附件“${row.original_name}”？`, '删除采购附件', { type: 'warning' })
        await deletePurchaseAttachment(row.id)
        this.attachments = this.attachments.filter(item => Number(item.id) !== Number(row.id))
        this.$emit('change', this.attachments)
        this.$message.success('附件已删除')
      } catch (error) { if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '附件删除失败') }
    },
    openBlob(blob, filename, preview) {
      const url = URL.createObjectURL(blob)
      if (preview) window.open(url, '_blank', 'noopener')
      else { const link = document.createElement('a'); link.href = url; link.download = filename || '采购附件'; document.body.appendChild(link); link.click(); link.remove() }
      window.setTimeout(() => URL.revokeObjectURL(url), 60000)
    },
    isPreviewable(row) { return ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'].includes(String(row.mime_type || '').toLowerCase()) },
    typeText(value) { return labels[value] || value || '其他附件' },
    fileSize(value) { const size = Number(value || 0); return size >= 1048576 ? `${(size / 1048576).toFixed(2)} MB` : `${Math.max(1, Math.ceil(size / 1024))} KB` },
    timeText(value) { return value ? String(value).replace('T', ' ').slice(0, 19) : '-' }
  }
}
</script>

<style scoped>
.purchase-attachment-panel{margin-top:12px;padding:14px;background:#fff;border:1px solid #e2e6ea;border-radius:5px;min-width:0}.purchase-attachment-panel>header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px}.purchase-attachment-panel h3{margin:0 0 4px;font-size:13px}.purchase-attachment-panel header span{color:#78838d;font-size:11px}.attachment-upload-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:12px}.attachment-upload-grid ::v-deep .el-upload{display:block;width:100%}.attachment-upload-card{height:70px;display:grid;grid-template-columns:28px 1fr;grid-template-rows:24px 20px;align-content:center;padding:0 14px;border:1px dashed #cfd9d3;border-radius:4px;background:#fbfdfc;text-align:left}.attachment-upload-card:hover{border-color:#07883f;background:#f1faf5}.attachment-upload-card i{grid-row:1/3;align-self:center;color:#07883f;font-size:20px}.attachment-upload-card b{font-size:12px;color:#26313b}.attachment-upload-card span{font-size:10px;color:#87919a}.danger-link{color:#e34b4f!important}.purchase-attachment-panel ::v-deep .el-table td .cell,.purchase-attachment-panel ::v-deep .el-table th .cell{white-space:normal;word-break:break-word}
.compact-attachment-list{display:grid;gap:7px}.compact-attachment-row{display:flex;justify-content:space-between;gap:8px;padding:8px;border:1px solid #e4e9e6;border-radius:4px;background:#fbfdfc}.compact-attachment-row>div:first-child{min-width:0;display:grid;gap:4px}.compact-attachment-row b{font-size:11px;word-break:break-all}.compact-attachment-row span{font-size:10px;color:#7a858e}.compact-attachment-row>div:last-child{flex:0 0 auto;white-space:nowrap}
@media(max-width:900px){.attachment-upload-grid{grid-template-columns:1fr}}
</style>
