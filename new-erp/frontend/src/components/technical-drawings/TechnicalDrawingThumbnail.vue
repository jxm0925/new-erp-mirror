<template>
  <img v-if="image && src" :src="src" :alt="drawing.drawing_name">
  <span v-else class="file-icon-box"><i :class="loading ? 'el-icon-loading' : 'el-icon-document'" /> {{ drawing.original_name || drawing.drawing_name }}</span>
</template>
<script>
import { downloadTechnicalDrawing } from '@/api/erp/technicalDrawings'
export default {
  name: 'TechnicalDrawingThumbnail',
  props: { drawing: { type: Object, required: true } },
  data: () => ({ src: '', objectUrl: '', loading: false, sequence: 0 }),
  computed: { image() { return /^image\//.test(this.drawing.mime_type || '') }, context() { return JSON.stringify([this.drawing.url, this.drawing.mime_type, this.drawing.technical_drawing && this.drawing.technical_drawing.revision_id]) } },
  watch: { context: { immediate: true, handler() { this.load() } } },
  beforeDestroy() { this.release(); this.sequence += 1 },
  methods: {
    release() { if (this.objectUrl) URL.revokeObjectURL(this.objectUrl); this.objectUrl = ''; this.src = '' },
    async load() {
      const sequence = ++this.sequence; this.release(); this.loading = false
      if (!this.image) return
      if (!this.drawing.technical_drawing) { this.src = this.drawing.url; return }
      this.loading = true
      try { const { data } = await downloadTechnicalDrawing(this.drawing.technical_drawing.revision_id); if (sequence !== this.sequence) return; this.objectUrl = URL.createObjectURL(data); this.src = this.objectUrl }
      catch (_) { /* 文件名仍提供进入鉴权预览和重试的入口。 */ }
      finally { if (sequence === this.sequence) this.loading = false }
    }
  }
}
</script>
