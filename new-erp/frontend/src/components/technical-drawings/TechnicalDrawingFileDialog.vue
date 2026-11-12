<template><sales-order-attachment-preview-dialog :visible.sync="visible" :file="file" :preview-request="download" :download-request="download" /></template>
<script>
import SalesOrderAttachmentPreviewDialog from '@/components/sales/SalesOrderAttachmentPreviewDialog.vue'
import { downloadTechnicalDrawing } from '@/api/erp/technicalDrawings'
export default {
  name: 'TechnicalDrawingFileDialog',
  components: { SalesOrderAttachmentPreviewDialog },
  data: () => ({ visible: false, file: null }),
  methods: {
    download: downloadTechnicalDrawing,
    open(drawing) {
      const file = drawing.technical_drawing || drawing
      const mime = file.mime_type || drawing.mime_type
      this.file = { ...drawing, ...file, mime_type: mime, attachment_id: file.revision_id || file.id, file_name: drawing.original_name || file.original_name || file.drawing_name, can_download: file.can_download !== false, can_preview: ['application/pdf','image/jpeg','image/png','image/webp'].includes(mime) }
      this.visible = true
    }
  }
}
</script>
