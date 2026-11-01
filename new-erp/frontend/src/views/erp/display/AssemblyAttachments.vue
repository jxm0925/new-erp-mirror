<template>
  <div class="assembly-files">
    <span v-if="!files.length" class="muted">—</span>
    <button v-for="(file, index) in files" :key="file.url || file.id || index" class="assembly-file" type="button" @click="open(file)">
      <span class="file-visual" :class="fileKind(file)">
        <img v-if="fileKind(file) === 'image' && safeUrl(file) && !failedImages[file.url || file.id || index]" :src="safeUrl(file)" :alt="file.name" @error="$set(failedImages, file.url || file.id || index, true)">
        <i v-else :class="fileKind(file) === 'image' ? 'el-icon-picture-outline' : 'el-icon-document'" />
        <small v-if="fileKind(file) !== 'image'">{{ fileKind(file).toUpperCase() }}</small>
      </span>
      <span>{{ file.name || '附件' }}</span>
    </button>

    <el-dialog :title="current ? current.name : '附件预览'" :visible.sync="visible" append-to-body width="88vw" top="5vh" custom-class="assembly-preview-dialog" :close-on-click-modal="false" @closed="clear">
      <div v-loading="loading" class="assembly-preview-body">
        <div v-if="error" class="assembly-preview-error" role="alert">
          <i class="el-icon-warning-outline" />
          <p>{{ error }}</p>
          <el-button @click="open(current)">重新加载</el-button>
        </div>
        <img v-else-if="kind === 'image'" :src="url" :alt="current && current.name" class="full-image" @load="loading = false" @error="attachmentFailed">
        <iframe v-else-if="kind === 'pdf' && pdfUrl" :src="pdfUrl" title="PDF 附件预览" @load="loading = false" @error="attachmentFailed" />
      </div>
      <span slot="footer"><a v-if="url" class="el-button" :href="url" target="_blank" rel="noopener noreferrer">打开原文件</a><el-button type="success" @click="visible = false">关闭</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import { readAssemblyPdf, safeAssemblyFileUrl } from '@/api/erp/assemblyDisplay'

export default {
  name: 'AssemblyAttachments',
  props: { files: { type: Array, default: () => [] } },
  data: () => ({ failedImages: {}, current: null, visible: false, loading: false, error: '', url: '', kind: '', loadId: 0, pdfUrl: '' }),
  beforeDestroy() { this.clear() },
  methods: {
    safeUrl(file) { return safeAssemblyFileUrl(file.url) },
    fileKind(file) {
      const mime = String(file.mime_type || '').toLowerCase()
      if (/^image\/(png|jpeg|gif|webp|bmp)$/.test(mime) || /\.(png|jpe?g|gif|webp|bmp)$/i.test(file.name || '')) return 'image'
      if (mime === 'application/pdf' || /\.pdf$/i.test(file.name || '')) return 'pdf'
      if (/\.xlsx$/i.test(file.name || '') || mime.includes('spreadsheetml')) return 'xlsx'
      if (/\.xls$/i.test(file.name || '') || mime.includes('ms-excel')) return 'xls'
      return 'file'
    },
    async open(file) {
      if (!file) return
      const safeUrl = this.safeUrl(file)
      const kind = this.fileKind(file)
      if (safeUrl && !['image', 'pdf'].includes(kind)) { window.open(safeUrl, '_blank', 'noopener,noreferrer'); return }
      const request = ++this.loadId
      if (this.pdfUrl) URL.revokeObjectURL(this.pdfUrl)
      this.pdfUrl = ''
      this.current = file
      this.url = safeUrl
      this.kind = kind
      this.error = this.url ? '' : '附件地址不可用'
      this.loading = !!this.url && this.kind !== 'file'
      this.visible = true
      if (!this.url || this.kind !== 'pdf') return
      try {
        const url = await readAssemblyPdf(this.url)
        if (request !== this.loadId) { URL.revokeObjectURL(url); return }
        this.pdfUrl = url
      } catch (error) {
        if (request === this.loadId) this.error = '附件读取失败，请重试或打开原文件'
      } finally {
        if (request === this.loadId) this.loading = false
      }
    },
    attachmentFailed() { this.loading = false; this.error = '附件读取失败，请重试或打开原文件' },
    clear() { this.loadId += 1; if (this.pdfUrl) URL.revokeObjectURL(this.pdfUrl); this.pdfUrl = ''; this.current = null; this.url = ''; this.kind = ''; this.error = ''; this.loading = false }
  }
}
</script>

<style scoped>
.assembly-files {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.assembly-file {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  min-width: 0;
  max-width: 150px;
  padding: 6px;
  color: #334155;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
  overflow-wrap: anywhere;
}
.assembly-file:hover {
  border-color: #008b4b;
  color: #008b4b;
}
.file-visual {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 88px;
  height: 60px;
  color: #64748b;
}
.file-visual img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.file-visual i {
  font-size: 30px;
}
.file-visual.pdf {
  color: #dc3d43;
}
.file-visual.xls,
.file-visual.xlsx {
  color: #008b4b;
}
.file-visual small {
  font-size: 11px;
}
.muted {
  color: #94a3b8;
}
.assembly-preview-body {
  position: relative;
  height: 66vh;
  overflow: auto;
  background: #f8fafc;
}
.assembly-preview-body iframe {
  width: 100%;
  height: 100%;
  border: 0;
}
.full-image {
  display: block;
  max-width: 100%;
  height: auto;
  margin: 0 auto;
}
.assembly-preview-error {
  padding: 50px 20px;
  text-align: center;
  color: #64748b;
}
.assembly-preview-error i {
  color: #f59e0b;
  font-size: 36px;
}
</style>
