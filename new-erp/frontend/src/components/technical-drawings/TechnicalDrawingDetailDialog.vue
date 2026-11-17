<template>
  <el-dialog class="drawing-dialog-shell" :title="drawing.id ? `${drawing.drawing_no} · ${drawing.drawing_name}` : '图纸详情'" :visible.sync="visible" custom-class="drawing-dialog drawing-detail-dialog" width="990px" top="7vh" append-to-body :close-on-click-modal="false" @close="closed">
    <el-alert v-if="error" :title="error" type="error" show-icon :closable="false" />
    <div v-loading="loading" v-if="drawing.id">
      <div class="drawing-source-summary"><div><span>当前版本：</span><b>V{{ drawing.current_version }}</b></div><div><span>状态：</span><el-tag size="small" :type="statusTag(drawing.status_label)">{{ drawing.status_label }}</el-tag></div></div>
      <el-tabs v-model="tab">
        <el-tab-pane label="版本记录" name="revisions">
          <div class="drawing-table-box">
            <el-table :data="drawing.revisions || []" size="small" :span-method="revisionSpan">
              <el-table-column label="版本" width="60"><template slot-scope="{row}">V{{ row.version_no }}</template></el-table-column>
              <el-table-column label="类型" width="60"><template slot-scope="{row}">{{ row.action_label || (row.action === 'new' ? '新画' : '修改') }}</template></el-table-column>
              <el-table-column label="文件" width="65"><template slot-scope="{row}">{{ row.file_count || 1 }} 份</template></el-table-column>
              <el-table-column prop="source_context" label="来源业务" min-width="250"><template slot-scope="{row}"><technical-drawing-source-info :source="row" @changed="sourceChanged" /></template></el-table-column>
              <el-table-column prop="designer_name" label="绘制人" min-width="80" />
              <el-table-column label="完成日期" min-width="100"><template slot-scope="{row}">{{ date(row.completed_date) }}</template></el-table-column>
              <el-table-column label="状态" min-width="125"><template slot-scope="{row}">
                <el-tag size="mini" :type="statusTag(row.review_status_label || row.status_label)">{{ row.review_status_label || row.status_label }}</el-tag>
                <div v-if="confirmationDate(row)" class="drawing-note">{{ date(confirmationDate(row)) }}</div>
                <div v-if="row.return_reason" class="drawing-note">修改意见：{{ row.return_reason }}</div>
              </template></el-table-column>
              <el-table-column label="操作" width="160" fixed="right"><template slot-scope="{row}"><div class="drawing-inline-actions"><el-button v-if="row.can_download" type="text" size="mini" @click="openFiles(row)">查看文件</el-button><el-button v-if="row.can_confirm && !row.confirmed_at" type="text" size="mini" :loading="confirming === row.id" @click="confirm(row)">确认图纸</el-button><span v-if="!row.can_download && !row.can_confirm" class="drawing-note">—</span></div></template></el-table-column>
            </el-table>
            <div v-if="revisionTotal > 10" class="drawing-pager"><el-pagination background small layout="total, prev, pager, next" :current-page.sync="revisionPage" :page-size="10" :total="revisionTotal" @current-change="load" /></div>
          </div>
        </el-tab-pane>
        <el-tab-pane label="使用位置" name="usages">
          <div class="drawing-table-box">
            <el-table :data="drawing.usages || []" size="small" :span-method="usageSpan">
              <el-table-column prop="source_context" label="来源业务" min-width="350"><template slot-scope="{row}"><technical-drawing-source-info :source="row" @changed="sourceChanged" /></template></el-table-column>
              <el-table-column label="使用版本" min-width="100"><template slot-scope="{row}">V{{ row.version_no }}<el-tag v-if="row.active === false" size="mini" type="info">历史引用</el-tag></template></el-table-column>
            </el-table>
            <div v-if="usageTotal > 10" class="drawing-pager"><el-pagination background small layout="total, prev, pager, next" :current-page.sync="usagePage" :page-size="10" :total="usageTotal" @current-change="load" /></div>
          </div>
        </el-tab-pane>
      </el-tabs>
      <p class="drawing-note">此图累计：新画 {{ totals.new_count || 0 }} 张 · 修改 {{ totals.modify_count || 0 }} 次 · 首次确认 {{ totals.confirmed_count || 0 }} 张。</p>
    </div>
    <div slot="footer"><el-button size="small" @click="visible = false">关闭</el-button></div>
    <technical-drawing-file-dialog ref="files" :show-source="false" />
  </el-dialog>
</template>

<script>
import { getTechnicalDrawing, confirmTechnicalDrawing, drawingError } from '@/api/erp/technicalDrawings'
import TechnicalDrawingFileDialog from './TechnicalDrawingFileDialog.vue'
import TechnicalDrawingSourceInfo from './TechnicalDrawingSourceInfo.vue'
import { erpDateTime } from '@/utils/dateTime'
import '@/styles/technical-drawings.css'
export default {
  name: 'TechnicalDrawingDetailDialog',
  components: { TechnicalDrawingFileDialog, TechnicalDrawingSourceInfo },
  data: () => ({ visible: false, loading: false, sequence: 0, drawingId: null, drawing: {}, error: '', tab: 'revisions', revisionPage: 1, usagePage: 1, confirming: null }),
  computed: { totals() { return this.drawing.totals || {} }, revisionTotal() { return Number((this.drawing.revisions_pagination || {}).total || 0) }, usageTotal() { return Number((this.drawing.usages_pagination || {}).total || 0) } },
  beforeDestroy() { this.sequence += 1 },
  methods: {
    date: value => erpDateTime(value, 10) || '—',
    statusTag(label) { return ({ '已确认': 'success', '客户已确认': 'success', '待技术修改': 'warning' })[label] || 'info' },
    confirmationDate(row) { return row.review_status ? row.review_confirmed_at : row.confirmed_at },
    open(id) { this.drawingId = id; this.drawing = {}; this.revisionPage = 1; this.usagePage = 1; this.tab = 'revisions'; this.visible = true; this.load() },
    closed() { this.sequence += 1; if (this.$refs.files) this.$refs.files.close() },
    sourceKey(row) { return JSON.stringify([row.source_type, row.source_id, row.source_no, row.source_name, row.source_context]) },
    sourceSpan({ row, column, rowIndex }, rows) {
      if (column.property !== 'source_context') return
      const key = this.sourceKey(row)
      if (rowIndex > 0 && this.sourceKey(rows[rowIndex - 1]) === key) return { rowspan: 0, colspan: 0 }
      let rowspan = 1
      while (rowIndex + rowspan < rows.length && this.sourceKey(rows[rowIndex + rowspan]) === key) rowspan += 1
      return { rowspan, colspan: 1 }
    },
    revisionSpan(options) { return this.sourceSpan(options, this.drawing.revisions || []) },
    usageSpan(options) { return this.sourceSpan(options, this.drawing.usages || []) },
    sourceChanged() { this.load(); this.$emit('changed') },
    async load() {
      const sequence = ++this.sequence; this.loading = true; this.error = ''
      try { const { data } = await getTechnicalDrawing(this.drawingId, { revisions_page: this.revisionPage, usages_page: this.usagePage, per_page: 10 }); if (sequence === this.sequence && this.visible) this.drawing = data.data || data }
      catch (error) { if (sequence === this.sequence) this.error = drawingError(error, '图纸详情加载失败') }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    openFiles(row) { this.$refs.files.open({ ...row, drawing_no: this.drawing.drawing_no, drawing_name: this.drawing.drawing_name }) },
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
