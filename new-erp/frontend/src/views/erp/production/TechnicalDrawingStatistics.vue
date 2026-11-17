<template>
  <section class="drawing-statistics-page">
    <header class="page-head"><div class="head-left"><div class="head-icon"><i class="el-icon-aim" /></div><h1 class="page-title">技术绘图统计</h1></div></header>
    <el-tabs v-model="tab" @tab-click="changeTab"><el-tab-pane label="按人员汇总" name="people" /><el-tab-pane label="图纸明细" name="drawings" /></el-tabs>
    <div class="drawing-filter">
      <div class="drawing-filter-field"><span>统计期间</span><el-date-picker v-model="filters.date_from" size="small" type="date" value-format="yyyy-MM-dd" placeholder="开始日期" :clearable="false" /><span>至</span><el-date-picker v-model="filters.date_to" size="small" type="date" value-format="yyyy-MM-dd" placeholder="结束日期" :clearable="false" /></div>
      <div class="drawing-filter-field"><span>绘制人</span><paged-record-select v-model="filters.designer_legacy_id" :request="employees" :columns="employeeColumns" :label="employeeLabel" :selected-rows="selectedEmployees" title="选择绘制人" placeholder="全部" /></div>
      <div class="drawing-filter-field"><span>来源</span><el-select v-model="filters.source_type" size="small" placeholder="全部"><el-option value="" label="全部" /><el-option v-for="source in metadata.source_types || []" :key="source.value" :value="source.value" :label="source.label" /></el-select></div>
      <el-input v-if="tab === 'drawings'" v-model.trim="filters.keyword" size="small" style="width:220px" clearable placeholder="图号 / 图纸名称" @keyup.enter.native="query" />
      <el-button size="small" type="success" :loading="loading" @click="query">查询</el-button><el-button size="small" @click="reset">重置</el-button>
    </div>
    <el-alert v-if="error" :title="error" type="error" show-icon :closable="false" />
    <div class="drawing-metrics"><div class="drawing-metric"><span>新画图纸</span><strong>{{ summary.new_count || 0 }}</strong><span>张</span></div><div class="drawing-metric"><span>修改</span><strong>{{ summary.modify_count || 0 }}</strong><span>次</span></div><div class="drawing-metric"><span>首次确认</span><strong>{{ summary.confirmed_count || 0 }}</strong><span>张</span></div></div>
    <div class="drawing-table-box">
      <el-table v-if="tab === 'people'" key="people" v-loading="loading" :data="rows" size="small" show-summary :summary-method="peopleSummary">
        <el-table-column prop="designer_name" label="绘制人" min-width="130" /><el-table-column prop="department_name" label="部门" min-width="145" /><el-table-column prop="new_count" label="新画图纸（张）" min-width="170" align="center" /><el-table-column prop="modify_count" label="修改（次）" min-width="155" align="center" /><el-table-column prop="confirmed_count" label="首次确认（张）" min-width="180" align="center" /><el-table-column label="操作" min-width="110" align="center"><template slot-scope="{row}"><el-button type="text" size="small" @click="personDetails(row)">查看明细</el-button></template></el-table-column>
      </el-table>
      <el-table v-else key="drawings" v-loading="loading" :data="rows" row-key="drawing_id" :expand-row-keys="expandedGroups" size="small" @expand-change="expandGroup">
        <el-table-column type="expand" width="48"><template slot-scope="{row: group}">
          <div class="drawing-revision-group">
            <el-alert v-if="group.revisions.error" :title="group.revisions.error" type="error" show-icon :closable="false" />
            <el-table v-loading="group.revisions.loading" :data="group.revisions.rows" size="small" border>
              <el-table-column label="版本 / 类型" min-width="100"><template slot-scope="{row}">V{{ row.version_no }} · {{ row.action_label || (row.action === 'new' ? '新画' : '修改') }}</template></el-table-column>
              <el-table-column prop="designer_name" label="绘制人" min-width="90" />
              <el-table-column label="完成日期" min-width="110"><template slot-scope="{row}">{{ date(row.completed_date) }}</template></el-table-column>
              <el-table-column label="来源业务" min-width="260"><template slot-scope="{row}"><technical-drawing-source-info :source="row" @changed="load" /></template></el-table-column>
              <el-table-column label="首次确认日期" min-width="120"><template slot-scope="{row}">{{ row.first_confirmation ? date(row.confirmed_at) : '—' }}</template></el-table-column>
              <el-table-column label="本期计入" min-width="135"><template slot-scope="{row}">{{ countedText(row) }}</template></el-table-column>
            </el-table>
            <div class="drawing-pager"><el-pagination background layout="total, prev, pager, next" :current-page.sync="group.revisions.page" :page-size="10" :total="group.revisions.total" @current-change="loadGroup(group)" /></div>
          </div>
        </template></el-table-column>
        <el-table-column prop="drawing_no" label="图纸编号" min-width="165" />
        <el-table-column prop="drawing_name" label="图纸名称" min-width="160" />
        <el-table-column prop="revision_count" label="版本数" min-width="100" align="center" />
        <el-table-column label="操作" width="95"><template slot-scope="{row}"><el-button type="text" size="small" @click="$refs.detail.open(row.drawing_id)">图纸详情</el-button></template></el-table-column>
      </el-table>
      <div class="drawing-pager"><el-pagination background :layout="'total, sizes, prev, pager, next'" :current-page.sync="page" :page-size.sync="perPage" :page-sizes="[10,20,50,100]" :total="total" @current-change="load" @size-change="query" /></div>
    </div>
    <p class="drawing-note">同一图纸跨商机、定制BOM和工单沿用只计一次；新画和修改按完成日期，首次确认按确认日期。</p>
    <technical-drawing-detail-dialog ref="detail" @changed="load" />
  </section>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import TechnicalDrawingDetailDialog from '@/components/technical-drawings/TechnicalDrawingDetailDialog.vue'
import TechnicalDrawingSourceInfo from '@/components/technical-drawings/TechnicalDrawingSourceInfo.vue'
import { getTechnicalDrawingMetadata, listTechnicalDrawingEmployees, listTechnicalDrawingPeople, listTechnicalDrawings, drawingError } from '@/api/erp/technicalDrawings'
import { erpDateTime } from '@/utils/dateTime'
import '@/styles/technical-drawings.css'
const emptyFilters = today => ({ date_from: today ? `${today.slice(0, 7)}-01` : '', date_to: today || '', designer_legacy_id: null, source_type: '', keyword: '' })
export default {
  name: 'TechnicalDrawingStatistics',
  components: { PagedRecordSelect, TechnicalDrawingDetailDialog, TechnicalDrawingSourceInfo },
  data: () => ({ tab: 'people', metadata: {}, filters: emptyFilters(''), selectedEmployees: [], rows: [], expandedGroups: [], summary: {}, page: 1, perPage: 10, total: 0, loading: false, error: '', sequence: 0, employeeColumns: [{ prop: 'name', label: '姓名' }, { prop: 'department_name', label: '部门' }] }),
  created() { this.initialize() },
  beforeDestroy() { this.sequence += 1 },
  methods: {
    employees: listTechnicalDrawingEmployees,
    employeeLabel: row => row.name,
    date: value => erpDateTime(value, 10) || '—',
    async initialize() {
      const sequence = ++this.sequence
      this.loading = true
      try { const { data } = await getTechnicalDrawingMetadata(); if (sequence !== this.sequence) return; this.metadata = data.data || data; this.filters = emptyFilters(this.metadata.today); await this.load() }
      catch (error) { if (sequence === this.sequence) this.error = drawingError(error, '技术绘图统计加载失败') }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    query() { this.page = 1; this.expandedGroups = []; this.load() },
    changeTab() { this.rows = []; this.total = 0; this.query() },
    reset() { this.filters = emptyFilters(this.metadata.today); this.selectedEmployees = []; this.perPage = 10; this.query() },
    personDetails(row) { this.selectedEmployees = [{ id: row.designer_legacy_id, name: row.designer_name, department_name: row.department_name }]; this.filters.designer_legacy_id = row.designer_legacy_id; this.tab = 'drawings'; this.changeTab() },
    peopleSummary() { return ['合计', '', this.summary.new_count || 0, this.summary.modify_count || 0, this.summary.confirmed_count || 0, ''] },
    countedText(row) { return [row.counted_new ? '新画' : '', row.counted_modify ? '修改' : '', row.counted_confirmed ? '首次确认' : ''].filter(Boolean).join('、') || '—' },
    async load() {
      const sequence = ++this.sequence; this.loading = false
      if (!this.filters.date_from || !this.filters.date_to) return this.$message.warning('请选择完整统计期间')
      if (this.filters.date_from > this.filters.date_to) return this.$message.warning('开始日期不能晚于结束日期')
      const tab = this.tab; const filters = { ...this.filters }
      this.loading = true; this.error = ''
      try {
        const { data } = await (tab === 'people' ? listTechnicalDrawingPeople : listTechnicalDrawings)({ ...filters, group_by: tab === 'drawings' ? 'drawing_no' : undefined, page: this.page, per_page: this.perPage })
        if (sequence !== this.sequence) return
        const result = Array.isArray(data.data) ? data : data.data || data
        this.total = Number(result.total || (result.meta && result.meta.total) || 0); this.summary = data.summary || result.summary || {}
        const lastPage = Math.max(1, Math.ceil(this.total / this.perPage))
        if (this.page > lastPage) { this.page = lastPage; await this.load(); return }
        if (tab === 'drawings') {
          const previous = new Map(this.rows.filter(group => group.revisions && JSON.stringify(group.filters) === JSON.stringify(filters)).map(group => [group.drawing_id, group.revisions]))
          this.rows = (result.data || []).map(group => ({ ...group, filters, revisions: previous.get(group.drawing_id) || { rows: [], page: 1, total: Number(group.revision_count || 0), loading: false, error: '', sequence: 0 } }))
          this.expandedGroups = this.expandedGroups.filter(id => this.rows.some(group => group.drawing_id === id))
          this.rows.filter(group => this.expandedGroups.includes(group.drawing_id)).forEach(group => this.loadGroup(group))
        } else this.rows = result.data || []
      } catch (error) { if (sequence === this.sequence) { this.rows = []; this.total = 0; this.summary = {}; this.expandedGroups = []; this.error = drawingError(error, '技术绘图统计加载失败') } }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    expandGroup(group, expandedRows) {
      this.expandedGroups = expandedRows.map(row => row.drawing_id)
      if (this.expandedGroups.includes(group.drawing_id)) this.loadGroup(group)
      else { group.revisions.sequence += 1; group.revisions.loading = false }
    },
    async loadGroup(group) {
      if (!this.expandedGroups.includes(group.drawing_id)) return
      const state = group.revisions; const sequence = ++state.sequence; const listSequence = this.sequence
      state.loading = true; state.error = ''
      try {
        // 展开版本沿用主表已提交的筛选条件，不读取尚未查询的筛选编辑值。
        const { data } = await listTechnicalDrawings({ ...group.filters, drawing_id: group.drawing_id, page: state.page, per_page: 10 })
        if (listSequence !== this.sequence || sequence !== state.sequence || !this.expandedGroups.includes(group.drawing_id)) return
        const result = Array.isArray(data.data) ? data : data.data || data
        state.rows = result.data || []; state.total = Number(result.total || (result.meta && result.meta.total) || 0); group.revision_count = state.total
        const lastPage = Math.max(1, Math.ceil(state.total / 10))
        if (state.page > lastPage) { state.page = lastPage; await this.loadGroup(group) }
      } catch (error) { if (listSequence === this.sequence && sequence === state.sequence && this.expandedGroups.includes(group.drawing_id)) { state.rows = []; state.total = 0; state.error = drawingError(error, '该图纸的版本加载失败') } }
      finally { if (listSequence === this.sequence && sequence === state.sequence) state.loading = false }
    }
  }
}
</script>

<style scoped>
.drawing-statistics-page ::v-deep .el-table__expanded-cell {
  padding: 0;
}
.drawing-revision-group {
  min-width: 0;
  padding: 12px 16px;
  box-sizing: border-box;
}
.drawing-revision-group .drawing-pager {
  padding: 12px 0 0;
}
</style>
