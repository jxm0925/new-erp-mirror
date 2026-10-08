<template>
  <section class="job-bundle-panel" v-loading="loading">
    <div class="bundle-heading"><h3>共同加工作业</h3><div class="bundle-actions"><el-button size="small" @click="load">刷新</el-button><el-button v-if="canCreate" size="small" type="success" :disabled="busy" @click="openCreate">安排共同加工</el-button></div></div>
    <div class="bundle-filters"><el-input v-model="keyword" size="small" clearable placeholder="搜索作业编号或名称" @keyup.enter.native="search" @clear="search" /><el-button size="small" @click="search">查询</el-button></div>
    <article v-for="row in rows" :key="row.id" class="bundle-card">
      <div class="bundle-heading"><strong>{{ row.bundle_no }}</strong><el-tag size="small" :type="['FINISHED','COMPLETED'].includes(row.status)?'success':'info'">{{ statusName(row.status) }}</el-tag></div>
      <p>{{ row.title || '共同加工' }}</p>
      <div class="bundle-card-bottom"><span>{{ row.owner && (row.owner.display_name || row.owner.name) || '待接单' }} · {{ row.line_count || (row.lines || []).length }}项任务</span><el-button type="text" @click="openDetail(row.id)">查看作业</el-button></div>
    </article>
    <el-empty v-if="!loading && !rows.length" :image-size="60" description="暂无共同加工作业" />
    <el-pagination background small layout="prev, pager, next, total" :current-page="page" :page-size="20" :total="total" @current-change="changePage" />

    <el-dialog title="安排共同加工" :visible.sync="createVisible" width="1100px" append-to-body custom-class="production-bundle-dialog" :close-on-click-modal="false" :close-on-press-escape="!busy" :show-close="!busy" @closed="resetCreate">
      <el-input v-model.trim="title" size="small" maxlength="160" placeholder="作业名称（可选）" :disabled="editLocked" />
      <div class="bundle-filters"><el-input v-model="candidateKeyword" size="small" clearable placeholder="搜索工单、任务、产品或工序" :disabled="editLocked" @keyup.enter.native="searchCandidates" @clear="searchCandidates" /><el-checkbox v-model="currentOrderOnly" :disabled="editLocked" @change="searchCandidates">仅本工单</el-checkbox><el-button size="small" :disabled="editLocked" @click="searchCandidates">查询</el-button></div>
      <el-table :data="candidates" size="small" border v-loading="candidateLoading">
        <el-table-column label="选择" width="58" align="center"><template slot-scope="scope"><el-checkbox :value="!!selected[String(scope.row.task_id)]" :disabled="editLocked || !scope.row.eligible" :aria-label="`选择${scope.row.task_no || scope.row.task_id}`" @change="toggleCandidate(scope.row,$event)" /></template></el-table-column>
        <el-table-column label="工单" min-width="160"><template slot-scope="scope">{{ scope.row.work_order && scope.row.work_order.no || scope.row.work_order_no || '—' }}</template></el-table-column>
        <el-table-column label="工序 / 任务" min-width="180"><template slot-scope="scope"><div>{{ scope.row.operation_name || scope.row.operation_name_snapshot || '—' }}</div><small>{{ scope.row.task_no || scope.row.task && scope.row.task.no }}</small></template></el-table-column>
        <el-table-column label="产出物料" min-width="180"><template slot-scope="scope"><div>{{ scope.row.item && scope.row.item.name || '—' }}</div><small>{{ scope.row.item && scope.row.item.spec }}</small></template></el-table-column>
        <el-table-column label="计划数量" min-width="120"><template slot-scope="scope">{{ quantity(scope.row.planned_base_qty !== undefined ? scope.row.planned_base_qty : scope.row.target && scope.row.target.planned_base_qty) }} {{ unitName(scope.row) }}<div v-if="scope.row.unit && scope.row.unit.no">{{ scope.row.unit.no }}</div></template></el-table-column>
        <el-table-column label="当前条件" min-width="190"><template slot-scope="scope">{{ candidateReason(scope.row) }}</template></el-table-column>
      </el-table>
      <el-pagination background small layout="prev, pager, next, total" :current-page="candidatePage" :page-size="20" :total="candidateTotal" @current-change="changeCandidatePage" />
      <div class="selected-bundle-tasks"><div class="bundle-heading"><strong>已选 {{ selectedRows.length }}项</strong></div><div v-for="row in selectedRows" :key="row.task_id" class="selected-bundle-row"><span>{{ row.work_order && row.work_order.no || row.work_order_no }} · {{ row.item && row.item.name || row.task_no }}</span><el-button type="text" :disabled="editLocked" @click="toggleCandidate(row,false)">移除</el-button></div></div>
      <span slot="footer" class="bundle-dialog-footer"><span>已选 {{ selectedRows.length }}项任务</span><div><el-button :disabled="busy" @click="createVisible=false">取消</el-button><el-button type="success" :loading="busy" :disabled="!pending && (selectedRows.length<2 || selectedRows.length>20)" @click="create">{{ pending ? '重试安排' : '确认安排' }}</el-button></div></span>
    </el-dialog>

    <el-dialog title="共同加工作业" :visible.sync="detailVisible" width="1000px" append-to-body custom-class="production-bundle-dialog" :close-on-click-modal="false">
      <div v-loading="detailLoading">
        <template v-if="detail"><div class="bundle-heading"><strong>{{ detail.bundle_no }}</strong><el-tag size="small">{{ statusName(detail.status) }}</el-tag></div><p>{{ detail.title || '共同加工' }}</p><p class="bundle-total-labor">实际工时 {{ quantity(detail.actual_labor_minutes) }}分钟</p>
          <article v-for="line in detail.lines || []" :key="line.id" class="bundle-card"><div class="bundle-heading"><strong>{{ line.item && line.item.name }}</strong><span>{{ line.work_order && line.work_order.no }}</span></div><p>{{ line.task && line.task.no }} · {{ line.item && line.item.spec }}</p><p v-if="line.unit && line.unit.no">{{ line.unit.no }}</p><dl class="bundle-line-facts"><div><dt>计划数量</dt><dd>{{ quantity(line.target && line.target.planned_base_qty) }} {{ unitName(line) }}</dd></div><div><dt>剩余报工</dt><dd>{{ quantity(line.target && line.target.remaining_base_qty) }} {{ unitName(line) }}</dd></div><div><dt>分配工时</dt><dd>{{ quantity(line.allocated_labor_minutes) }}分钟</dd></div></dl></article>
        </template>
      </div>
      <span slot="footer"><el-button @click="detailVisible=false">关闭</el-button><el-button v-if="detail && detail.allowed_actions && detail.allowed_actions.cancel" type="danger" plain :loading="busy" @click="cancel">取消作业</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { listJobBundles, getJobBundle, jobBundleCandidates, createJobBundle, cancelJobBundle, pendingAssemblyCommand, executeAssemblyCommand, productionQuantity, bundleStatusName } from '../../../api/erp/assembly-production'

export default {
  name: 'ProductionJobBundlePanel',
  props: { workOrder: { type: Object, required: true } },
  data: () => ({ loading: false, busy: false, rows: [], page: 1, total: 0, keyword: '', createVisible: false, title: '', candidates: [], candidatePage: 1, candidateTotal: 0, candidateKeyword: '', currentOrderOnly: false, candidateLoading: false, selected: {}, pending: null, detailVisible: false, detailLoading: false, detail: null }),
  computed: { selectedRows () { return Object.values(this.selected) }, editLocked () { return this.busy || Boolean(this.pending) }, createKey () { return `bundle-create-${this.workOrder.id}` }, canCreate () { return this.$can('production.work_order.edit') && this.$can('production.task.view') } },
  mounted () { this.load() },
  watch: { 'workOrder.id' () { this.page = 1; this.resetCreate(); this.detail = null; this.load() } },
  methods: {
    quantity: productionQuantity, statusName: bundleStatusName,
    unitName (row) { return row.base_unit_name || row.unit?.base_unit_name || row.item?.unit_name || '' },
    async load () {
      if (!this.workOrder.id) return
      const sequence = this.listSequence = (this.listSequence || 0) + 1
      this.loading = true
      try { const response = await listJobBundles({ view: 'all', work_order_id: this.workOrder.id, keyword: this.keyword.trim(), page: this.page, per_page: 20 }); if (sequence === this.listSequence) { this.rows = response.data.data || []; this.total = Number(response.data.total || 0) } }
      catch (error) { if (sequence === this.listSequence) this.$message.error(error.userMessage || '共同加工作业加载失败') }
      finally { if (sequence === this.listSequence) this.loading = false }
    },
    search () { this.page = 1; return this.load() },
    changePage (page) { this.page = page; return this.load() },
    openCreate () {
      if (this.busy) return
      this.resetCreate(); this.pending = pendingAssemblyCommand(this.createKey)
      if (this.pending) this.restoreRequest(this.pending)
      this.createVisible = true; if (!this.pending) this.loadCandidates()
    },
    restoreRequest (request) { this.title = request.payload.title || ''; this.selected = {}; for (const row of request.display_rows || []) this.$set(this.selected, String(row.task_id), JSON.parse(JSON.stringify(row))) },
    resetCreate () { this.candidateSequence = (this.candidateSequence || 0) + 1; this.selected = {}; this.candidates = []; this.candidateTotal = 0; this.candidatePage = 1; this.candidateKeyword = ''; this.currentOrderOnly = false; this.title = ''; this.pending = null; this.candidateLoading = false },
    candidateReason (row) { const reasons = (row.reasons || []).map(value => typeof value === 'string' ? value : value.message).filter(Boolean); return reasons.length ? reasons.join('；') : row.eligible ? '可安排' : '暂不可安排' },
    toggleCandidate (row, checked) {
      if (this.editLocked) return
      const key = String(row.task_id)
      if (!checked) return this.$delete(this.selected, key)
      if (!row.eligible) return
      if (this.selectedRows.length >= 20) return this.$message.error('一次共同加工最多选择20项任务')
      const first = this.selectedRows[0]
      if (first && row.compatibility_key && first.compatibility_key !== row.compatibility_key) return this.$message.error('所选任务的工艺或执行条件不兼容，请分别安排')
      this.$set(this.selected, key, JSON.parse(JSON.stringify(row)))
    },
    searchCandidates () { if (this.editLocked) return; this.candidatePage = 1; return this.loadCandidates() },
    changeCandidatePage (page) { if (this.editLocked) return; this.candidatePage = page; return this.loadCandidates() },
    async loadCandidates () {
      if (!this.createVisible || this.editLocked) return
      const sequence = this.candidateSequence = (this.candidateSequence || 0) + 1
      this.candidateLoading = true
      try {
        const response = await jobBundleCandidates({ page: this.candidatePage, per_page: 20, keyword: this.candidateKeyword.trim(), work_order_id: this.currentOrderOnly ? this.workOrder.id : undefined })
        if (sequence === this.candidateSequence && this.createVisible) { this.candidates = response.data.data || []; this.candidateTotal = Number(response.data.total || 0) }
      } catch (error) { if (sequence === this.candidateSequence) this.$message.error(error.userMessage || '候选任务加载失败') }
      finally { if (sequence === this.candidateSequence) this.candidateLoading = false }
    },
    async create () {
      if (this.busy || (!this.pending && this.selectedRows.length < 2)) return
      this.busy = true
      this.candidateSequence = (this.candidateSequence || 0) + 1; this.candidateLoading = false
      try {
        const request = this.pending || { payload: { title: this.title || undefined, tasks: this.selectedRows.map(row => ({ task_id: row.task_id, expected_task_version: row.task_business_version })) }, display_rows: JSON.parse(JSON.stringify(this.selectedRows)) }
        this.restoreRequest(request)
        const response = await executeAssemblyCommand(this.createKey, request.payload, createJobBundle, request.display_rows)
        this.createVisible = false; this.$message.success('共同加工已安排'); await this.load(); this.$emit('updated')
        const id = response.data.data?.id
        if (id) await this.openDetail(id)
      } catch (error) { this.pending = pendingAssemblyCommand(this.createKey); if (this.pending) this.restoreRequest(this.pending); this.$message.error(error.userMessage || '安排失败，请重试原操作') }
      finally { this.busy = false }
    },
    async openDetail (id) {
      const sequence = this.detailSequence = (this.detailSequence || 0) + 1
      this.detail = null; this.detailVisible = true; this.detailLoading = true
      try { const response = await getJobBundle(id); if (sequence === this.detailSequence && this.detailVisible) this.detail = response.data.data || null }
      catch (error) { if (sequence === this.detailSequence) this.$message.error(error.userMessage || '作业详情加载失败') }
      finally { if (sequence === this.detailSequence) this.detailLoading = false }
    },
    async cancel () {
      if (this.busy || !this.detail?.allowed_actions?.cancel) return
      this.busy = true
      try { const id = this.detail.id; await executeAssemblyCommand(`bundle-cancel-${id}`, { expected_version: this.detail.business_version }, data => cancelJobBundle(id, data)); await this.openDetail(id); await this.load(); this.$emit('updated') }
      catch (error) { this.$message.error(error.userMessage || '取消失败，请重试原操作') }
      finally { this.busy = false }
    }
  }
}
</script>

<style scoped>
.job-bundle-panel {
  padding: 18px 20px;
  border: 1px solid #e6ebf0;
  border-radius: 5px;
  background: #fff;
  color: #33455d;
  min-width: 0;
}
.bundle-heading, .bundle-actions, .bundle-filters, .bundle-card-bottom, .selected-bundle-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  min-width: 0;
}
.bundle-heading, .bundle-card-bottom, .selected-bundle-row {
  justify-content: space-between;
}
.bundle-heading h3 {
  margin: 0;
  font-size: 14px;
}
.bundle-filters {
  margin: 16px 0;
}
.bundle-filters .el-input {
  flex: 1 1 260px;
  min-width: 0;
}
.bundle-card {
  padding: 14px;
  margin: 12px 0;
  border: 1px solid #e6ebf0;
  border-radius: 5px;
  min-width: 0;
  overflow-wrap: anywhere;
  font-size: 13px;
}
.bundle-card p, .bundle-card-bottom {
  color: #728098;
  font-size: 12px;
}
.job-bundle-panel .el-pagination {
  margin-top: 14px;
  text-align: right;
}
.selected-bundle-tasks {
  margin-top: 16px;
  border-top: 1px solid #e6ebf0;
  padding-top: 12px;
}
.selected-bundle-row {
  padding: 6px 0;
  font-size: 12px;
}
.selected-bundle-row>span {
  flex: 1 1 160px;
  min-width: 0;
  overflow-wrap: anywhere;
}
.bundle-line-facts {
  display: grid;
  grid-template-columns: repeat(3,minmax(0,1fr));
  gap: 12px;
}
.bundle-line-facts>div {
  min-width: 0;
}
.bundle-line-facts dt {
  color: #728098;
  font-size: 12px;
  margin-bottom: 6px;
}
.bundle-line-facts dd {
  margin: 0;
  font-size: 13px;
  overflow-wrap: anywhere;
}
.bundle-total-labor {
  font-size: 13px;
}
@media(max-width:780px) {
  .job-bundle-panel {
    padding: 12px;
  }
  .bundle-line-facts {
    grid-template-columns: minmax(0,1fr);
  }
}
</style>
<style>
.job-bundle-panel .el-button--text,
.production-bundle-dialog .el-button--text {
  color: #008b4b;
}
.production-bundle-dialog .el-checkbox__input.is-checked .el-checkbox__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}
.production-bundle-dialog .el-checkbox__input.is-focus .el-checkbox__inner,
.production-bundle-dialog .el-input__inner:focus {
  border-color: #008b4b;
}
.production-bundle-dialog {
  max-width: calc(100vw - 32px);
  margin: 5vh auto 0 !important;
  display: flex;
  flex-direction: column;
  max-height: 85vh;
}
.production-bundle-dialog .el-dialog__body {
  overflow: auto;
  min-height: 0;
}
.production-bundle-dialog .el-dialog__footer {
  flex-shrink: 0;
}
.bundle-dialog-footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
.bundle-dialog-footer>div {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
@media(max-width:430px) {
  .production-bundle-dialog .el-dialog__body, .production-bundle-dialog .el-dialog__footer {
    padding: 12px;
  }
}
</style>
