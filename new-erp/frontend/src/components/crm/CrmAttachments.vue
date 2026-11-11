<template>
  <div class="crm-attachments">
    <el-upload v-if="!readonly" action="#" :http-request="upload" :show-file-list="false" :disabled="uploading" :accept="accept">
      <el-button size="small" :loading="uploading" icon="el-icon-upload2">{{ images ? '上传图片' : '上传附件' }}</el-button>
    </el-upload>
    <span v-for="file in value" :key="file.id" class="crm-attachment">
      <el-button type="text" size="small" @click="download(file)">{{ file.name || file.original_name || '附件' }}</el-button>
      <span v-if="file.size" class="crm-muted">{{ Math.ceil(file.size / 1024) }} KB</span>
      <el-button v-if="!readonly" type="text" size="mini" icon="el-icon-close" aria-label="移除附件" @click="$emit('input', value.filter(item => item.id !== file.id))" />
    </span>
  </div>
</template>
<script>
import { uploadCrmAttachment, downloadCrmAttachment, crmError } from '@/api/erp/crm'
export default {
  name: 'CrmAttachments',
  props: { value: { type: Array, default: () => [] }, readonly: Boolean, images: Boolean },
  data: () => ({ uploading: false, session: 0 }),
  computed: { accept() { return this.images ? 'image/*' : '' } },
  beforeDestroy() { this.session += 1 },
  methods: {
    reset() { this.session += 1; this.uploading = false },
    async upload(request) {
      if (this.images && !request.file.type.startsWith('image/')) return this.$message.warning('请选择图片文件')
      const session = this.session
      const form = new FormData()
      form.append('file', request.file)
      this.uploading = true
      this.$emit('busy', true)
      try {
        const { data } = await uploadCrmAttachment(form)
        if (session !== this.session) return
        this.$emit('input', [...this.value, data.data || data])
      } catch (error) {
        if (session === this.session) this.$message.error(crmError(error, '附件上传失败'))
      } finally {
        if (session === this.session) { this.uploading = false; this.$emit('busy', false) }
      }
    },
    async download(file) {
      try {
        const response = await downloadCrmAttachment(file.id)
        const url = URL.createObjectURL(response.data)
        const link = document.createElement('a')
        link.href = url
        link.download = file.name || file.original_name || '附件'
        link.click()
        setTimeout(() => URL.revokeObjectURL(url), 1000)
      } catch (error) { this.$message.error(crmError(error, '附件下载失败')) }
    }
  }
}
</script>
<style scoped>
.crm-attachments { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.crm-attachment { display: inline-flex; align-items: center; gap: 5px; padding: 0 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 5px; max-width: 100%; word-break: break-word; }
</style>
