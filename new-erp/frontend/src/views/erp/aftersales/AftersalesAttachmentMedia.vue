<template>
  <img v-if="isImage && objectUrl" :src="objectUrl" :alt="attachment.original_name" class="media-thumb" role="button" tabindex="0" @click="preview" @keydown.enter.prevent="preview" @keydown.space.prevent="preview" />
  <div v-else class="media-thumb video-thumb" role="button" tabindex="0" :aria-label="attachment.original_name" @click="preview" @keydown.enter.prevent="preview" @keydown.space.prevent="preview"><i :class="loading ? 'el-icon-loading' : isImage ? 'el-icon-picture-outline' : 'el-icon-video-play'" />{{ isImage ? '图片' : '视频' }}</div>
</template>

<script>
import { previewAftersalesAttachment } from '@/api/erp/aftersales'

export default {
  name: 'AftersalesAttachmentMedia',
  props: { attachment: { type: Object, required: true } },
  data: () => ({ objectUrl: '', loading: false, pending: null, released: false }),
  computed: {
    isImage() { return String(this.attachment.mime_type || '').startsWith('image/') }
  },
  mounted() {
    if (this.isImage) this.load().catch(() => {})
  },
  beforeDestroy() {
    this.released = true
    if (this.objectUrl) URL.revokeObjectURL(this.objectUrl)
  },
  methods: {
    async load() {
      if (this.objectUrl) return this.objectUrl
      if (this.pending) return this.pending
      this.loading = true
      this.pending = previewAftersalesAttachment(this.attachment.id).then(({ data }) => {
        if (this.released) return ''
        this.objectUrl = URL.createObjectURL(new Blob([data], { type: this.attachment.mime_type || 'application/octet-stream' }))
        return this.objectUrl
      }).finally(() => { this.loading = false; this.pending = null })
      return this.pending
    },
    async preview() {
      const popup = window.open('', '_blank')
      if (popup) popup.opener = null
      try {
        const url = await this.load()
        if (url && popup) popup.location.href = url
        else if (popup) popup.close()
      } catch (error) {
        if (popup) popup.close()
        this.$message.error('附件加载失败，请重试')
      }
    }
  }
}
</script>
