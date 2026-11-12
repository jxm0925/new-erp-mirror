<template>
  <el-dialog class="drawing-dialog-shell" :title="drawing.id ? `${drawing.drawing_no} · ${drawing.drawing_name}` : '图纸详情'" :visible.sync="visible" custom-class="drawing-dialog drawing-detail-dialog" width="990px" top="7vh" append-to-body :close-on-click-modal="false" @close="closed">
    <el-alert v-if="error" :title="error" type="error" show-icon :closable="false" />
    <div v-loading="loading" v-if="drawing.id">
      <div class="drawing-source-summary"><div><span>图纸类型：</span><b>{{ drawing.drawing_type_label || drawing.drawing_type }}</b></div><div><span>当前版本：</span><b>V{{ drawing.current_version }}</b></div><div><span>状态：</span><el-tag size="small" :type="drawing.first_confirmed_at ? 'success' : 'info'">{{ drawing.status_label }}</el-tag></div></div>
      <el-tabs v-model="tab" @tab-click="selectTab"><el-tab-pane label="版本记录" name="revisions" /><el-tab-pane label="使用位置" name="usages" /></el-tabs>
      <div ref="revisions" class="drawing-table-box">
        <el-table :data="drawing.revisions || []" size="small">
          <el-table-column label="版本" width="70"><template slot-scope="{row}">V{{ row.version_no }}</template></el-table-column>
          <el-table-column label="类型" width="75"><template slot-scope="{row}">{{ row.action_label || (row.action === 'new' ? '新画' : '修改') }}</template></el-table-column>
          <el-table-column prop="designer_name" label="绘制人" min-width="95" />
          <el-table-column label="完成日期" min-width="110"><template slot-scope="{row}">{{ date(row.completed_date) }}</template></el-table-column>
          <el-table-column label="确认日期" min-width="110"><template slot-scope="{row}">{{ date(row.confirmed_at) }}</template></el-table-column>
          <el-table-column label="状态" min-width="90"><template slot-scope="{row}"><el-tag size="mini" :type="row.confirmed_at ? 'success' : 'info'">{{ row.status_label || (row.confirmed_at ? '已确认' : '待确认') }}</el-tag></template></el-table-column>
          <el-table-column label="操作" min-width="150"><template slot-scope="{row}"><div class="drawing-inline-actions"><el-button v-if="row.can_download" type="text" size="mini" @click="preview(row)">查看</el-button><el-button v-if="row.can_download" type="text" size="mini" @click="download(row)">下载</el-button><el-button v-if="row.can_confirm && !row.confirmed_at" type="text" size="mini" :loading="confirming === row.id" @click="confirm(row)">确认图纸</el-button><span v-if="!row.can_download && !row.can_confirm" class="drawing-note">—</span></div></template></el-table-column>
        </el-table>
        <div v-if="revisionTotal > 10" class="drawing-pager"><el-pagination background small layout="total, prev, pager, next" :current-page.sync="revisionPage" :page-size="10" :total="revisionTotal" @current-change="load" /></div>
      </div>
      <h3 ref="usages" class="drawing-section-title">使用位置</h3>
      <div class="drawing-table-box"><el-table :data="drawing.usages || []" size="small"><el-table-column prop="source_label" label="来源" min-width="130" /><el-table-column label="关联业务" min-width="300"><template slot-scope="{row}">{{ row.source_name || row.source_no || '—' }}<template v-if="row.source_name && row.source_no">（{{ row.source_no }}）</template></template></el-table-column><el-table-column label="使用版本" min-width="100"><template slot-scope="{row}">V{{ row.version_no }}<el-tag v-if="row.active === false" size="mini" type="info">历史引用</el-tag></template></el-table-column></el-table><div v-if="usageTotal > 10" class="drawing-pager"><el-pagination background small layout="total, prev, pager, next" :current-page.sync="usagePage" :page-size="10" :total="usageTotal" @current-change="load" /></div></div>
      <p class="drawing-note">此图累计：新画 {{ totals.new_count || 0 }} 张 · 修改 {{ totals.modify_count || 0 }} 次 · 首次确认 {{ totals.confirmed_count || 0 }} 张。</p>
    </div>
    <div slot="footer" class="drawing-detail-footer"><span class="drawing-note"><i class="el-icon-info" /> 已确认版本保留记录；</span><el-button size="small" @click="visible = false">关闭</el-button></div>
    <sales-order-attachment-preview-dialog :visible.sync="previewVisible" :file="previewFile" :preview-request="downloadRequest" :download-request="downloadRequest" />
  </el-dialog>
</template>

<script>
import { getTechnicalDrawing, confirmTechnicalDrawing, downloadTechnicalDrawing, drawingError } from '@/api/erp/technicalDrawings'
import SalesOrderAttachmentPreviewDialog from '@/components/sales/SalesOrderAttachmentPreviewDialog.vue'
import { erpDateTime } from '@/utils/dateTime'
import '@/styles/technical-drawings.css'
export default {
  name: 'TechnicalDrawingDetailDialog',
  components: { SalesOrderAttachmentPreviewDialog },
  data: () => ({ visible: false, loading: false, sequence: 0, drawingId: null, drawing: {}, error: '', tab: 'revisions', revisionPage: 1, usagePage: 1, confirming: null, previewVisible: false, previewFile: null }),
  computed: { totals() { return this.drawing.totals || {} }, revisionTotal() { return Number((this.drawing.revisions_pagination || {}).total || 0) }, usageTotal() { return Number((this.drawing.usages_pagination || {}).total || 0) } },
  beforeDestroy() { this.sequence += 1 },
  methods: {
    downloadRequest: downloadTechnicalDrawing,
    date: value => erpDateTime(value, 10) || '—',
    open(id) { this.drawingId = id; this.drawing = {}; this.revisionPage = 1; this.usagePage = 1; this.tab = 'revisions'; this.visible = true; this.load() },
    closed() { this.sequence += 1; this.previewVisible = false },
    selectTab() { this.$nextTick(() => { const target = this.$refs[this.tab]; if (target) target.scrollIntoView({ block: 'nearest', behavior: 'smooth' }) }) },
    async load() {
      const sequence = ++this.sequence; this.loading = true; this.error = ''
      try { const { data } = await getTechnicalDrawing(this.drawingId, { revisions_page: this.revisionPage, usages_page: this.usagePage, per_page: 10 }); if (sequence === this.sequence && this.visible) this.drawing = data.data || data }
      catch (error) { if (sequence === this.sequence) this.error = drawingError(error, '图纸详情加载失败') }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    preview(row) { this.previewFile = { ...row, attachment_id: row.id, file_name: row.original_name, can_download: row.can_download, can_preview: ['application/pdf','image/jpeg','image/png','image/webp'].includes(row.mime_type) }; this.previewVisible = true },
    async download(row) {
      try { const { data } = await downloadTechnicalDrawing(row.id); const url = URL.createObjectURL(data); const link = document.createElement('a'); link.href = url; link.download = row.original_name || this.drawing.drawing_name; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000) }
      catch (error) { this.$message.error(drawingError(error, '图纸下载失败')) }
    },
    async confirm(row) {
      if (this.confirming || !row.confirm_source) return
      try { await this.$confirm(`确认图纸 ${this.drawing.drawing_no} V${row.version_no}？`, '确认技术图纸', { type: 'warning', confirmButtonText: '确认图纸', cancelButtonText: '取消' }) } catch (_) { return }
      this.confirming = row.id
      try { await confirmTechnicalDrawing(row.id, { source: row.confirm_source }); this.$message.success('图纸已确认'); this.load(); this.$emit('changed') }
      catch (error) { this.$message.error(drawingError(error, '图纸确认失败')); if (error.response && error.response.status === 409) { this.load(); this.$emit('changed') } }
      finally { this.confirming = null }
    }
  }
}
</script>
