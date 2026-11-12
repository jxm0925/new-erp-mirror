<template>
  <section class="drawing-source-panel">
    <div class="drawing-source-heading"><h3>技术图纸</h3><div v-if="editable" class="drawing-inline-actions"><el-button v-if="source.type === 'bom'" size="mini" @click="register('new')">上传并登记图纸</el-button><el-button size="mini" @click="register('reuse')">沿用已有版本</el-button></div></div>
    <el-alert v-if="error" :title="error" type="error" show-icon :closable="false" />
    <el-table v-loading="loading" :data="rows" size="small" border><el-table-column prop="drawing_no" label="图纸编号" min-width="150" /><el-table-column prop="drawing_name" label="图纸名称" min-width="160" /><el-table-column label="版本" width="70"><template slot-scope="{row}">V{{ row.version_no }}</template></el-table-column><el-table-column prop="designer_name" label="绘制人" min-width="90" /><el-table-column label="状态" min-width="90"><template slot-scope="{row}"><el-tag size="mini" :type="row.confirmed_at ? 'success' : 'info'">{{ row.confirmed_at ? '已确认' : '待确认' }}</el-tag></template></el-table-column><el-table-column label="操作" min-width="145"><template slot-scope="{row}"><div class="drawing-inline-actions"><el-button size="mini" type="text" @click="$refs.detail.open(row.drawing_id)">图纸详情</el-button><el-button v-if="editable && row.can_confirm && !row.confirmed_at" size="mini" type="text" :loading="confirming === row.id" @click="confirm(row)">确认图纸</el-button></div></template></el-table-column></el-table>
    <div class="drawing-pager"><el-pagination background small layout="total, prev, pager, next" :current-page.sync="page" :page-size="10" :total="total" @current-change="load" /></div>
    <technical-drawing-register-dialog ref="register" @saved="changed" /><technical-drawing-detail-dialog ref="detail" @changed="changed" />
  </section>
</template>

<script>
import { listTechnicalDrawingSources, confirmTechnicalDrawing, drawingError } from '@/api/erp/technicalDrawings'
import TechnicalDrawingRegisterDialog from './TechnicalDrawingRegisterDialog.vue'
import TechnicalDrawingDetailDialog from './TechnicalDrawingDetailDialog.vue'
import '@/styles/technical-drawings.css'
export default {
  name: 'TechnicalDrawingSourcePanel',
  components: { TechnicalDrawingRegisterDialog, TechnicalDrawingDetailDialog },
  props: { source: { type: Object, required: true }, sourceLabel: { type: String, default: '' }, editable: Boolean },
  data: () => ({ loading: false, rows: [], page: 1, total: 0, error: '', sequence: 0, confirming: null }),
  computed: { context() { return JSON.stringify(this.source) } },
  watch: { context: { immediate: true, handler() { this.page = 1; this.rows = []; this.total = 0; if (this.source.id) this.load(); else this.sequence += 1 } } },
  beforeDestroy() { this.sequence += 1 },
  methods: {
    register(action) { this.$refs.register.open({ action, source: this.source, sourceLabel: this.sourceLabel }) },
    async load() {
      const sequence = ++this.sequence; this.loading = true; this.error = ''
      try { const { data } = await listTechnicalDrawingSources({ source: this.source, page: this.page, per_page: 10 }); if (sequence !== this.sequence) return; const result = Array.isArray(data.data) ? data : data.data || data; this.rows = result.data || []; this.total = Number(result.total || (result.meta && result.meta.total) || 0) }
      catch (error) { if (sequence === this.sequence) { this.rows = []; this.total = 0; this.error = drawingError(error, '技术图纸加载失败') } }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    changed() { this.load(); this.$emit('changed') },
    async confirm(row) {
      if (this.confirming) return
      try { await this.$confirm(`确认图纸 ${row.drawing_no} V${row.version_no}？`, '确认技术图纸', { type: 'warning', confirmButtonText: '确认图纸', cancelButtonText: '取消' }) } catch (_) { return }
      this.confirming = row.id
      try { await confirmTechnicalDrawing(row.id, { source: row.confirm_source || this.source }); this.$message.success('图纸已确认'); this.changed() }
      catch (error) { this.$message.error(drawingError(error, '图纸确认失败')); if (error.response && error.response.status === 409) this.changed() }
      finally { this.confirming = null }
    }
  }
}
</script>
