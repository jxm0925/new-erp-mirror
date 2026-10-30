<template>
  <div v-if="hasCutting" class="cutting-panel">
    <el-button type="text" size="small" icon="el-icon-scissors" @click="open">开料</el-button>
    <el-dialog title="开料" :visible.sync="visible" append-to-body top="5vh" width="min(1080px, calc(100vw - 32px))" custom-class="mfg-cutting-dialog" :close-on-click-modal="false" :close-on-press-escape="!busy" :show-close="!busy" @close="close">
      <div v-loading="loading">
        <div class="cutting-summary">
          <div><strong>{{ workOrder.item_name || workOrder.product_name }}</strong><p>{{ workOrder.work_order_no }}</p></div>
          <el-tag size="small">{{ workOrder.status_label }}</el-tag>
        </div>
        <el-alert v-if="error" :title="error" type="error" show-icon :closable="false" />
        <template v-if="context">
          <div class="cutting-form">
            <label>加工工序<el-select v-model="operationId" size="small" :disabled="locked" @change="resetPlan"><el-option v-for="op in cuttingOperations" :key="op.id" :value="op.id" :label="op.operation_name" /></el-select></label>
            <label>本次加工套数<el-input-number v-model="unitQty" size="small" :min="1" :max="remainingUnits || 1" :step="1" step-strictly :disabled="locked" @change="resetPlan" /></label>
          </div>
          <h4>下料要求</h4>
          <el-table :data="operationRequirements" border size="small">
            <el-table-column prop="item_name" label="原材料" min-width="130" />
            <el-table-column prop="part_name" label="板件 / 管件" min-width="130" />
            <el-table-column label="尺寸 / 单套用量" min-width="190"><template slot-scope="{ row }">{{ requirementText(row) }}</template></el-table-column>
            <el-table-column label="本次加工量" min-width="110"><template slot-scope="{ row }">{{ qty(Number(row.mode === 'sheet' ? row.per_unit_piece_qty : row.per_unit_measure) * unitQty) }} {{ row.mode === 'sheet' ? '件' : 'm' }}</template></el-table-column>
          </el-table>
          <template v-if="canExecute && context.can_issue && remainingUnits > 0">
            <div class="cutting-section-head"><h4>实际投入来源</h4><el-button size="small" type="success" plain :disabled="locked" @click="openSources">选择实际原料</el-button></div>
            <el-table :data="selectedSources" border size="small">
              <el-table-column label="实物 / 批次" min-width="180"><template slot-scope="{ row }">{{ row.physical_no || row.batch_no }}</template></el-table-column>
              <el-table-column prop="item_name" label="原材料" min-width="130" />
              <el-table-column label="尺寸 / 面积 / 长度" min-width="170"><template slot-scope="{ row }"><span :class="{ 'cutting-measure': row.mode === 'length' }">{{ sourceSize(row) }}</span></template></el-table-column>
              <el-table-column label="来源" min-width="140"><template slot-scope="{ row }">{{ row.warehouse_name }} / {{ row.location_name }}</template></el-table-column>
              <el-table-column label="投入" width="120"><template slot-scope="{ row }">{{ row.qty }} {{ sourceUnit(row) }}<div v-if="row.mode === 'length' && row.unit_kind === 'root'" class="cutting-measure">= {{ meters(Number(row.qty) * Number(row.measure_qty)) }}</div></template></el-table-column>
            </el-table>
            <div class="cutting-actions"><el-button size="small" :disabled="locked || !selectedSources.length" :loading="previewLoading" @click="calculate(false)">计算建议方案</el-button></div>
            <template v-if="preview">
              <h4>逐来源加工方案</h4>
              <div v-for="summary in preview.summaries" :key="summary.source_key" class="cutting-source-plan">
                <div class="cutting-section-head"><strong>{{ sourceName(summary.source_key) }}</strong><span>投入 {{ qty(summary.input_measure) }} {{ measureUnit(summary.measure_unit) }} · 建议余料 {{ qty(summary.suggested_remnant) }} {{ measureUnit(summary.measure_unit) }}</span></div>
                <el-table :data="sourceAssignments(summary.source_key)" border size="mini">
                  <el-table-column label="板件 / 管件" min-width="160"><template slot-scope="{ row }">{{ partName(row.part_key) }}</template></el-table-column>
                  <el-table-column label="本来源加工量" width="190"><template slot-scope="{ row }"><el-input v-model="row.qty" size="small" inputmode="decimal" :disabled="locked" @input="planChecked = false"><template slot="append">{{ partUnit(row.part_key) }}</template></el-input></template></el-table-column>
                </el-table>
              </div>
              <div class="cutting-actions"><el-button size="small" :disabled="locked" :loading="previewLoading" @click="calculate(true)">校验加工方案</el-button><el-checkbox v-model="planConfirmed" :disabled="locked">已核对实际来源与各板件加工量</el-checkbox></div>
            </template>
          </template>
          <div class="cutting-section-head"><h4>实际用料批次</h4><el-button size="mini" icon="el-icon-refresh" :disabled="busy" @click="load">刷新</el-button></div>
          <el-table :data="batches.data || []" border size="small">
            <el-table-column prop="batch_no" label="批次" min-width="170" />
            <el-table-column prop="unit_qty" label="安排套数" width="95" />
            <el-table-column prop="completed_unit_qty" label="实际套数" width="95" />
            <el-table-column label="状态" width="100"><template slot-scope="{ row }"><el-tag size="mini" :type="row.status === 'settled' ? 'success' : 'warning'">{{ row.status === 'settled' ? '已确认' : '待确认结果' }}</el-tag></template></el-table-column>
            <el-table-column label="操作" width="125"><template slot-scope="{ row }"><el-button type="text" :disabled="busy" @click="openBatch(row)">{{ canExecute && row.status === 'issued' ? '登记产出与余料' : '查看用料结果' }}</el-button></template></el-table-column>
          </el-table>
          <div class="cutting-pager"><el-pagination background small :current-page="page" :page-size="10" :total="Number(batches.total || 0)" layout="total, prev, pager, next" @current-change="changePage" /></div>
        </template>
      </div>
      <span slot="footer">
        <el-button size="small" :disabled="busy" @click="visible = false">关闭</el-button>
        <el-button v-if="canExecute && (pendingIssue || context && context.can_issue && preview)" size="small" type="success" :loading="busy" :disabled="!pendingIssue && (!planConfirmed || !planChecked)" @click="issue">{{ pendingIssue ? '重试原领料操作' : '确认方案并领入加工' }}</el-button>
      </span>
    </el-dialog>

    <el-dialog title="选择实际原料" :visible.sync="sourceOpen" append-to-body top="5vh" width="min(1080px, calc(100vw - 32px))" custom-class="mfg-cutting-dialog" :close-on-click-modal="false">
      <div class="cutting-picker">
        <aside><el-tree :data="categoryTree" node-key="id" :props="{ label: 'category_name', children: 'children' }" default-expand-all @node-click="selectCategory" /></aside>
        <main>
          <div class="cutting-search">
            <el-select v-model="sourceQuery.item_id" size="small" placeholder="选择原材料" @change="searchSources"><el-option v-for="item in sourceItems" :key="item.item_id" :value="item.item_id" :label="[item.item_code, item.item_name, item.specification].filter(Boolean).join(' / ')" /></el-select>
            <el-input v-model.trim="sourceQuery.keyword" size="small" clearable placeholder="实物编号 / 批次 / 编码 / 名称 / 规格" @keyup.enter.native="searchSources" @clear="searchSources" />
            <el-button type="success" size="small" icon="el-icon-search" @click="searchSources">查询</el-button>
          </div>
          <el-alert v-if="sourceError" :title="sourceError" type="error" :closable="false" />
          <el-table v-loading="sourceLoading" :data="sourceRows" border size="small">
            <el-table-column label="选择" width="55"><template slot-scope="{ row }"><el-checkbox :value="Boolean(sourceSelected[row.source_key])" @change="toggleSource(row, $event)" /></template></el-table-column>
            <el-table-column label="物料 / 规格" min-width="170"><template slot-scope="{ row }">{{ row.item_code }} / {{ row.item_name }}<div>{{ row.specification }}</div></template></el-table-column>
            <el-table-column label="实物 / 来源" min-width="180"><template slot-scope="{ row }">{{ row.physical_no || row.batch_no }}<div>{{ row.warehouse_name }} / {{ row.location_name }}</div></template></el-table-column>
            <el-table-column label="尺寸 / 面积 / 长度" min-width="170"><template slot-scope="{ row }"><span :class="{ 'cutting-measure': row.mode === 'length' }">{{ sourceSize(row) }}</span></template></el-table-column>
            <el-table-column label="可用" width="120"><template slot-scope="{ row }">{{ qty(row.available_qty) }} {{ sourceUnit(row) }}<div v-if="row.mode === 'length' && row.unit_kind === 'root'" class="cutting-measure">共 {{ meters(Number(row.available_qty) * Number(row.measure_qty)) }}</div></template></el-table-column>
            <el-table-column label="本次投入" width="170"><template slot-scope="{ row }"><div v-if="sourceSelected[row.source_key]" class="cutting-source-quantity"><el-input-number v-model="sourceSelected[row.source_key].qty" size="mini" :min="row.unit_kind === 'm' ? 0.00001 : 1" :max="row.physical_material_id ? 1 : Number(row.available_qty)" :step="row.unit_kind === 'm' ? 0.01 : 1" :precision="row.unit_kind === 'm' ? 5 : 0" :step-strictly="row.unit_kind !== 'm'" :controls="false" /><span>{{ sourceUnit(row) }}</span></div></template></el-table-column>
          </el-table>
          <div class="cutting-pager"><el-pagination background :current-page="sourceQuery.page" :page-size="10" :total="sourceTotal" layout="total, prev, pager, next" @current-change="sourcePage" /></div>
        </main>
      </div>
      <div class="cutting-selected"><span>已选 {{ Object.keys(sourceSelected).length }} 项来源</span><el-tag v-for="row in Object.values(sourceSelected)" :key="row.source_key" closable @close="$delete(sourceSelected, row.source_key)">{{ row.physical_no || row.batch_no }} · {{ row.qty }} {{ sourceUnit(row) }}</el-tag></div>
      <span slot="footer"><el-button size="small" @click="sourceOpen = false">取消</el-button><el-button size="small" type="success" :disabled="sourceLoading || !Object.keys(sourceSelected).length" @click="confirmSources">确认选择</el-button></span>
    </el-dialog>

    <el-dialog :title="batch.status === 'issued' ? '登记产出与余料' : '用料结果'" :visible.sync="batchOpen" append-to-body top="5vh" width="min(960px, calc(100vw - 32px))" custom-class="mfg-cutting-dialog" :close-on-click-modal="false" :show-close="!busy" :close-on-press-escape="!busy">
      <div class="cutting-summary"><strong>{{ batch.batch_no }}</strong><el-tag size="small">{{ batch.status === 'settled' ? '已确认' : '待确认结果' }}</el-tag></div>
      <el-alert v-if="batchError" :title="batchError" type="error" show-icon :closable="false" />
      <div class="cutting-form"><label>实际加工套数<el-input-number v-model="actualUnits" size="small" :min="1" :max="Number(batch.unit_qty || 1)" :step="1" step-strictly :disabled="batchLocked" @change="batchConfirmed = false" /></label></div>
      <h4>本批加工结果</h4>
      <el-table :data="actualAssignments" border size="small">
        <el-table-column label="实际来源" min-width="170"><template slot-scope="{ row }">{{ batchSourceName(row.source_key) }}</template></el-table-column>
        <el-table-column label="板件 / 管件" min-width="160"><template slot-scope="{ row }">{{ partName(row.part_key) }}</template></el-table-column>
        <el-table-column label="实际产出量" width="170"><template slot-scope="{ row }"><el-input v-model="row.qty" size="small" inputmode="decimal" :disabled="batchLocked" @input="suggestActualRemnants"><template slot="append">{{ partUnit(row.part_key) }}</template></el-input></template></el-table-column>
      </el-table>
      <h4>余料人工确认</h4>
      <div v-for="row in remnants" :key="row.source_key" class="cutting-remnant">
        <strong>{{ batchSourceName(row.source_key) }}</strong>
        <div class="cutting-form">
          <label>确认余料（{{ measureUnit(row.measure_unit) }}）<el-input v-model="row.measure_qty" size="small" inputmode="decimal" :disabled="batchLocked" @input="batchConfirmed = false" /></label>
          <label v-if="Number(row.measure_qty) > 0">回库仓库<el-button size="small" class="cutting-locator" :disabled="batchLocked" @click="openLocator(row, 'warehouses')">{{ row.warehouse_name || '选择仓库' }}<i class="el-icon-search" /></el-button></label>
          <label v-if="Number(row.measure_qty) > 0">回库库位<el-button size="small" class="cutting-locator" :disabled="batchLocked || !row.warehouse_id" @click="openLocator(row, 'locations')">{{ row.location_name || '选择库位' }}<i class="el-icon-search" /></el-button></label>
        </div>
      </div>
      <el-input v-if="batch.status === 'issued'" v-model.trim="batchRemark" type="textarea" :rows="2" maxlength="500" :disabled="batchLocked" placeholder="现场说明（选填）" />
      <div v-if="batch.status === 'issued'" class="cutting-actions"><el-checkbox v-model="batchConfirmed" :disabled="batchLocked">已核对实际产出和余料；板材余料按面积确认</el-checkbox></div>
      <span slot="footer"><el-button size="small" :disabled="busy" @click="batchOpen = false">关闭</el-button><el-button v-if="canExecute && batch.status === 'issued'" type="success" size="small" :loading="busy" :disabled="!pendingSettle && !batchConfirmed" @click="settle">{{ pendingSettle ? '重试原确认操作' : '确认产出与余料入库' }}</el-button></span>
    </el-dialog>
    <system-record-picker :visible.sync="locator.visible" :type="locator.type" :multiple="false" :value="locatorValue" :warehouse-id="locator.row ? Number(locator.row.warehouse_id || 0) : 0" :location-purposes="['general']" :management-scope="workOrder.management_scope || 'factory'" @confirm="selectLocator" />
  </div>
</template>

<script>
import { getWorkOrderCutting, listWorkOrderCuttingSources, previewWorkOrderCutting, issueWorkOrderCutting, settleWorkOrderCutting } from '@/api/erp/production'
import { executeProductionDecision, pendingProductionDecision } from '@/api/erp/production-decisions'
import SystemRecordPicker from '@/components/system/SystemRecordPicker.vue'
import { errorText } from './mfg-helpers'

const uuid = () => window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
  const r = Math.random() * 16 | 0
  return (c === 'x' ? r : (r & 3 | 8)).toString(16)
})
const clone = value => JSON.parse(JSON.stringify(value))

export default {
  name: 'WorkOrderCuttingPanel',
  components: { SystemRecordPicker },
  props: { workOrder: { type: Object, required: true } },
  data: () => ({
    visible: false, loading: false, busy: false, error: '', context: null, operationId: null, unitQty: 1, page: 1,
    selectedSources: [], sourceSelected: {}, preview: null, previewLoading: false, planChecked: false, planConfirmed: false, pendingIssue: false,
    sourceOpen: false, sourceLoading: false, sourceError: '', sourceRows: [], sourceTotal: 0, categories: [],
    sourceQuery: { item_id: null, keyword: '', category_id: null, page: 1 }, sourceRequest: 0,
    batchOpen: false, batch: {}, batchError: '', actualUnits: 1, actualAssignments: [], remnants: [], batchConfirmed: false, batchRemark: '', pendingSettle: false,
    locator: { visible: false, type: 'warehouses', row: null }
  }),
  computed: {
    // 只有按实物管理的板材（issue_mode=cutting）走开料批次；按数量管理的管材按定额收料或在小程序点「已备料」
    hasCutting() { return (this.workOrder.kitting || []).some(op => (op.materials || []).some(row => row.issue_mode === 'cutting' && row.cutting_mode && row.cutting_mode !== 'none')) },
    canExecute() { return this.$can('production.work_order.edit') || this.$can('production.task.execute') },
    locked() { return this.busy || this.pendingIssue || !this.canExecute },
    batchLocked() { return this.busy || this.pendingSettle || !this.canExecute || this.batch.status !== 'issued' },
    batches() { return this.context && this.context.batches || {} },
    cuttingOperations() { return (this.context && this.context.operations || []).filter(op => this.context.requirements.some(row => Number(row.operation_id) === Number(op.id))) },
    operationRequirements() { return (this.context && this.context.requirements || []).filter(row => Number(row.operation_id) === Number(this.operationId)) },
    remainingUnits() { return Number(this.context && this.context.remaining_unit_qty_by_operation && this.context.remaining_unit_qty_by_operation[this.operationId] || 0) },
    sourceItems() { return Object.values(Object.fromEntries(this.operationRequirements.map(row => [row.item_id, row]))) },
    categoryTree() {
      const rows = this.categories.map(row => ({ ...row, children: [] }))
      const byId = Object.fromEntries(rows.map(row => [row.id, row]))
      const roots = []
      rows.forEach(row => { const parent = byId[row.parent_id]; if (parent && parent !== row) parent.children.push(row); else roots.push(row) })
      return [{ id: 0, category_name: '全部分类', children: roots }]
    },
    locatorValue() {
      const row = this.locator.row
      if (!row) return []
      return this.locator.type === 'warehouses' ? (row.warehouse_id ? [{ id: row.warehouse_id, warehouse_name: row.warehouse_name }] : []) : (row.location_id ? [{ id: row.location_id, location_name: row.location_name }] : [])
    }
  },
  watch: { 'workOrder.id'() { this.visible = false; this.close() } },
  methods: {
    qty(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) },
    measureUnit(value) { return value === 'm2' ? 'm²' : value },
    sourceUnit(row) { return row.unit_kind === 'sheet' ? '张' : (row.unit_kind === 'root' ? '根' : this.measureUnit(row.unit_kind)) },
    partUnit(key) { const part = (this.context && this.context.requirements || []).find(row => row.part_key === key); return part && part.quantity_basis === 'total_length' ? 'm' : '件' },
    async open() { this.visible = true; this.page = 1; this.pendingIssue = Boolean(pendingProductionDecision('cutting_issue_' + this.workOrder.id)); await this.load() },
    close() { this.sourceRequest++; this.sourceOpen = false; this.batchOpen = false; this.locator.visible = false; this.resetPlan() },
    async load() {
      const id = this.workOrder.id
      this.loading = true; this.error = ''
      try {
        const { data } = await getWorkOrderCutting(id, { page: this.page, per_page: 10 })
        if (!this.visible || id !== this.workOrder.id) return
        this.context = data.data
        if (!this.cuttingOperations.some(row => row.id === this.operationId)) this.operationId = this.cuttingOperations[0] && this.cuttingOperations[0].id
        if (this.remainingUnits > 0) this.unitQty = Math.min(this.unitQty, this.remainingUnits)
      } catch (e) { if (this.visible && id === this.workOrder.id) this.error = errorText(e, '开料资料加载失败') }
      finally { this.loading = false }
    },
    changePage(page) { this.page = page; this.load() },
    resetPlan() { this.preview = null; this.selectedSources = []; this.planChecked = false; this.planConfirmed = false },
    requirementText(row) { return row.mode === 'sheet' ? [row.length_mm, row.width_mm, row.thickness_mm].filter(value => value !== null && value !== undefined).join(' × ') + ' mm' : this.qty(row.per_unit_measure) + ' m / 套' },
    sourceSize(row) {
      const d = row.dimensions || {}
      if (row.mode === 'length') return (row.material_form === 'REMNANT' ? '余料 · ' : '') + (row.unit_kind === 'root' ? '每根 ' + this.meters(row.measure_qty) : '按米计')
      if (String(row.shape).toUpperCase() === 'AREA_ONLY') return '面积 ' + this.qty(d.area_m2 || row.measure_qty) + ' m²' + (d.thickness_mm ? ' · 厚 ' + d.thickness_mm + ' mm' : '')
      return [d.length_mm, d.width_mm, d.thickness_mm].filter(value => value !== null && value !== undefined).join(' × ') + ' mm'
    },
    meters(value) { return Number(Number(value || 0).toFixed(3)) + ' 米' },
    sourceName(key) { const row = (this.preview && this.preview.sources || this.selectedSources).find(row => row.source_key === key); return row ? row.physical_no || row.batch_no || key : key },
    batchSourceName(key) { const row = (this.batch.input_lines || []).find(row => row.source_key === key); return row ? row.physical_no || row.batch_no || key : key },
    partName(key) { const row = (this.context && this.context.requirements || []).find(row => row.part_key === key); return row ? row.part_name + ' · ' + this.requirementText(row) : key },
    sourceAssignments(key) { return this.preview.assignments.filter(row => row.source_key === key) },
    openSources() {
      this.sourceSelected = {}
      this.selectedSources.forEach(row => {
        const key = row.original_source_key || row.source_key
        if (this.sourceSelected[key]) this.sourceSelected[key].qty += Number(row.qty)
        else this.$set(this.sourceSelected, key, { ...row, source_key: key, qty: Number(row.qty) })
      })
      this.sourceQuery = { item_id: this.sourceItems[0] && this.sourceItems[0].item_id, keyword: '', category_id: null, page: 1 }
      this.sourceOpen = true; this.loadSources()
    },
    async loadSources() {
      const request = ++this.sourceRequest
      this.sourceLoading = true; this.sourceError = ''
      try {
        const { data } = await listWorkOrderCuttingSources(this.workOrder.id, { ...this.sourceQuery, category_id: this.sourceQuery.category_id || undefined, per_page: 10 })
        if (request !== this.sourceRequest || !this.sourceOpen) return
        this.sourceRows = data.data || []; this.sourceTotal = Number(data.total || 0); this.categories = data.categories || []
      } catch (e) { if (request === this.sourceRequest) this.sourceError = errorText(e, '库存来源加载失败') }
      finally { if (request === this.sourceRequest) this.sourceLoading = false }
    },
    searchSources() { this.sourceQuery.page = 1; this.loadSources() },
    sourcePage(page) { this.sourceQuery.page = page; this.loadSources() },
    selectCategory(row) { this.sourceQuery.category_id = row.id; this.searchSources() },
    toggleSource(row, selected) { if (selected) this.$set(this.sourceSelected, row.source_key, { ...row, qty: row.unit_kind === 'm' ? Number(row.available_qty) : 1 }); else this.$delete(this.sourceSelected, row.source_key) },
    confirmSources() {
      const sources = []
      for (const row of Object.values(this.sourceSelected)) {
        const count = Number(row.qty)
        if (!Number.isFinite(count) || count <= 0 || row.unit_kind !== 'm' && !Number.isInteger(count) || count > (row.physical_material_id ? 1 : Number(row.available_qty))) return this.$message.warning('请核对本次投入张数、根数或长度')
        if (row.unit_kind === 'root') {
          for (let i = 1; i <= count; i++) sources.push({ ...row, original_source_key: row.source_key, source_key: row.source_key + ':' + i, qty: '1' })
        } else sources.push({ ...row, original_source_key: row.source_key, qty: String(count) })
      }
      this.selectedSources = sources; this.preview = null; this.planChecked = false; this.planConfirmed = false; this.sourceOpen = false
    },
    planPayload(assignments) {
      return { operation_id: this.operationId, unit_qty: this.unitQty, sources: this.selectedSources.map(row => ({ source_key: row.source_key, item_id: row.item_id, inventory_balance_id: row.inventory_balance_id, physical_material_id: row.physical_material_id || null, source_version: row.source_version, qty: String(row.qty) })),
        ...(assignments ? { assignments: assignments.map(row => ({ source_key: row.source_key, part_key: row.part_key, qty: String(row.qty) })) } : {}) }
    },
    async calculate(retain) {
      if (this.locked || this.previewLoading) return
      const id = this.workOrder.id
      this.previewLoading = true; this.error = ''; this.planConfirmed = false; this.planChecked = false
      try {
        const { data } = await previewWorkOrderCutting(id, this.planPayload(retain && this.preview ? this.preview.assignments : null))
        if (!this.visible || id !== this.workOrder.id) return
        this.preview = data.data
        for (const source of this.preview.sources) {
          for (const part of this.operationRequirements.filter(row => Number(row.item_id) === Number(source.item_id))) {
            if (!this.preview.assignments.some(row => row.source_key === source.source_key && row.part_key === part.part_key)) this.preview.assignments.push({ source_key: source.source_key, part_key: part.part_key, qty: '0' })
          }
        }
        this.planChecked = true
      } catch (e) { this.error = errorText(e, '加工方案校验失败') }
      finally { this.previewLoading = false }
    },
    async issue() {
      if (this.busy || !this.pendingIssue && (!this.preview || !this.planChecked || !this.planConfirmed)) return
      const id = this.workOrder.id
      this.busy = true; this.error = ''
      try {
        await executeProductionDecision('cutting_issue_' + id, { ...this.planPayload(this.preview && this.preview.assignments), expected_version: this.context.business_version, idempotency_key: uuid() }, payload => issueWorkOrderCutting(id, payload))
        this.pendingIssue = false; this.resetPlan(); this.$message.success('原料已正式领入加工'); await this.load(); this.$emit('refresh')
      } catch (e) { this.pendingIssue = Boolean(e.pendingCommand); this.error = errorText(e, '开料领料失败') }
      finally { this.busy = false }
    },
    openBatch(row) {
      this.batch = clone(row); this.batchError = ''; this.batchOpen = true; this.actualUnits = Number(row.completed_unit_qty || row.unit_qty)
      this.actualAssignments = clone(row.status === 'settled' ? row.actual_outputs || [] : row.planned_outputs || [])
      this.batchConfirmed = false; this.batchRemark = ''; this.pendingSettle = Boolean(pendingProductionDecision('cutting_settle_' + row.id))
      if (row.status === 'settled') { this.remnants = clone(row.remnants || []); return }
      this.remnants = (row.summaries || row.input_lines || []).map(summary => {
        const source = row.input_lines.find(line => line.source_key === summary.source_key)
        return { source_key: summary.source_key, measure_unit: summary.measure_unit, measure_qty: String(summary.suggested_remnant), warehouse_id: source.warehouse_id, warehouse_name: source.warehouse_name, location_id: source.location_id, location_name: source.location_name }
      })
    },
    openLocator(row, type) { this.locator = { visible: true, type, row } },
    suggestActualRemnants() {
      this.batchConfirmed = false
      for (const remnant of this.remnants) {
        const source = this.batch.input_lines.find(row => row.source_key === remnant.source_key)
        let output = 0
        for (const actual of this.actualAssignments.filter(row => row.source_key === remnant.source_key)) {
          const planned = this.batch.planned_outputs.find(row => row.source_key === actual.source_key && row.part_key === actual.part_key)
          const part = this.context.requirements.find(row => row.part_key === actual.part_key)
          const measure = planned && planned.per_piece_measure !== undefined ? Number(planned.per_piece_measure) : (part && part.mode === 'sheet' ? Number(part.length_mm) * Number(part.width_mm) / 1000000 : 1)
          output += Number(actual.qty || 0) * measure
        }
        remnant.measure_qty = String(Math.max(0, Number(source.input_measure) - output).toFixed(8))
      }
    },
    selectLocator(rows) {
      const selected = rows[0]; const row = this.locator.row
      if (!selected || !row) return
      if (this.locator.type === 'warehouses') { if (row.warehouse_id !== selected.id) { row.location_id = null; row.location_name = '' }; row.warehouse_id = selected.id; row.warehouse_name = selected.warehouse_name }
      else { row.location_id = selected.id; row.location_name = [selected.location_code, selected.location_name].filter(Boolean).join(' ') }
      this.batchConfirmed = false
    },
    async settle() {
      if (this.busy || !this.pendingSettle && (!this.batchConfirmed || !this.remnants.length)) return
      const batch = this.batch
      if (!this.pendingSettle && this.remnants.some(row => !/^\d+(?:\.\d+)?$/.test(String(row.measure_qty)) || Number(row.measure_qty) > 0 && (!row.warehouse_id || !row.location_id))) return this.$message.warning('请填写非负余料数量及余料回库位置')
      this.busy = true; this.batchError = ''
      try {
        await executeProductionDecision('cutting_settle_' + batch.id, { expected_version: batch.business_version, idempotency_key: uuid(), unit_qty: this.actualUnits, assignments: this.actualAssignments.map(row => ({ source_key: row.source_key, part_key: row.part_key, qty: String(row.qty) })), remnants: this.remnants.map(row => ({ source_key: row.source_key, measure_qty: String(row.measure_qty), warehouse_id: row.warehouse_id, location_id: row.location_id })), confirmed: true, remark: this.batchRemark || undefined }, payload => settleWorkOrderCutting(batch.id, payload))
        this.pendingSettle = false; this.batchOpen = false; this.$message.success('产出与余料已确认'); await this.load(); this.$emit('refresh')
      } catch (e) { this.pendingSettle = Boolean(e.pendingCommand); this.batchError = errorText(e, '用料结果确认失败') }
      finally { this.busy = false }
    }
  }
}
</script>

<style scoped>
.cutting-measure { color: #c45a00; font-weight: 700; }
.cutting-panel,
.cutting-panel * {
  box-sizing: border-box;
  min-width: 0;
}
.cutting-summary,
.cutting-section-head,
.cutting-search,
.cutting-actions,
.cutting-selected {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.cutting-summary,
.cutting-section-head {
  justify-content: space-between;
  margin-bottom: 12px;
}
.cutting-summary p {
  margin: 6px 0 0;
  color: #64748b;
}
.cutting-form {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  margin: 14px 0;
}
.cutting-form label {
  display: flex;
  flex-direction: column;
  gap: 7px;
}
.cutting-form .el-select,
.cutting-form .el-input-number {
  width: 100%;
}
.cutting-source-plan,
.cutting-remnant {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 14px;
  margin: 12px 0;
}
.cutting-actions,
.cutting-pager {
  margin: 14px 0;
}
.cutting-pager {
  display: flex;
  justify-content: flex-end;
}
.cutting-picker {
  display: grid;
  grid-template-columns: 180px minmax(0, 1fr);
  gap: 16px;
}
.cutting-picker aside {
  border-right: 1px solid #e2e8f0;
  padding-right: 12px;
}
.cutting-search {
  margin-bottom: 14px;
}
.cutting-search .el-select {
  width: 190px;
}
.cutting-search .el-input {
  flex: 1;
}
.cutting-selected {
  border-top: 1px solid #e2e8f0;
  padding-top: 14px;
}
.cutting-locator {
  text-align: left;
  white-space: normal;
}
.cutting-locator i {
  margin-left: 8px;
}
.cutting-source-quantity {
  display: flex;
  align-items: center;
  gap: 6px;
}
.cutting-source-quantity .el-input-number {
  width: 120px;
}
@media (max-width: 780px) {
  .cutting-form,
  .cutting-picker {
    grid-template-columns: minmax(0, 1fr);
  }
  .cutting-picker aside {
    border-right: 0;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 12px;
  }
}
</style>
<style>
.mfg-cutting-dialog {
  max-width: calc(100vw - 32px);
}
.mfg-cutting-dialog .el-dialog__body {
  max-height: 70vh;
  overflow-y: auto;
}
.mfg-cutting-dialog .el-dialog__footer {
  border-top: 1px solid #e2e8f0;
}
</style>
