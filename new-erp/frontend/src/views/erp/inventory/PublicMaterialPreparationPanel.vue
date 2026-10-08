<template>
  <section class="public-preparation-panel">
    <div class="pm-filters"><el-radio-group v-model="view" size="small" @change="search"><el-radio-button v-if="$can('production.material_requirement.view')" label="demands">公共待配需求</el-radio-button><el-radio-button label="tasks">公共配料任务</el-radio-button></el-radio-group><el-input v-model.trim="query.keyword" size="small" clearable placeholder="输入单号、物料名称或规格" @keyup.enter.native="search" /><el-button size="small" @click="search">查询</el-button><el-button v-if="view === 'demands' && $can('production.material_picking.create')" size="small" type="success" @click="openDraft">合并配料</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-button v-if="pendingCreate" size="small" type="warning" :loading="busy" @click="recoverCreate">核对上次创建</el-button>
    <p class="pm-muted">可以合并不同工单的物料需求。板材和长料在现场领料，其他物料按接收工序配送。</p>
    <el-table :key="view" :data="rows" v-loading="loading" border size="small">
      <template v-if="view === 'demands'">
        <el-table-column prop="work_order_no" label="来源工单" min-width="175" />
        <el-table-column prop="item_name" label="物料" min-width="150" />
        <el-table-column prop="spec" label="规格" min-width="120" />
        <el-table-column label="待准备" width="110"><template slot-scope="{row}">{{ row.remaining_to_prepare }} {{ row.unit_name }}</template></el-table-column>
        <el-table-column prop="target_operation_name" label="接收工序" min-width="110" />
        <el-table-column label="领料方式" width="110"><template slot-scope="{row}">{{ mode(row.fulfillment_mode) }}</template></el-table-column>
        <el-table-column label="操作" width="125"><template slot-scope="{row}"><el-button v-if="$can('production.material_procurement.create')" type="text" @click="$emit('procurement', row)">提交采购需求</el-button></template></el-table-column>
      </template>
      <template v-else>
        <el-table-column prop="task_no" label="公共配料单号" min-width="180" />
        <el-table-column label="仓库" min-width="150"><template slot-scope="{row}">{{ row.warehouse && row.warehouse.warehouse_name }}</template></el-table-column>
        <el-table-column prop="tasks_count" label="工单数" width="90" />
        <el-table-column label="状态" width="105"><template slot-scope="{row}">{{ status(row.status) }}</template></el-table-column>
        <el-table-column label="操作" width="100"><template slot-scope="{row}"><el-button type="text" @click="openDetail(row.id)">查看任务</el-button></template></el-table-column>
      </template>
    </el-table>
    <el-pagination :pager-count="5" class="pm-pagination" :current-page.sync="query.page" :page-size="20" :total="total" layout="total, prev, pager, next" @current-change="load" />

    <el-dialog title="合并公共配料" :visible.sync="draft.visible" width="1100px" custom-class="production-material-dialog public-material-dialog" append-to-body :close-on-click-modal="false" :before-close="close">
      <el-alert v-if="draft.error" :title="draft.error" type="error" :closable="false" />
      <div class="pm-inline-field"><label>配料仓库：</label><el-input :value="draft.warehouse.warehouse_name" readonly size="small" placeholder="请选择仓库" @click.native="warehouse" /><el-button size="small" @click="warehouse">选择仓库</el-button></div>
      <div class="pm-filters"><el-input v-model.trim="draft.keyword" size="small" clearable placeholder="工单号、物料编码、名称或规格" @keyup.enter.native="searchDraft" /><el-button size="small" @click="searchDraft">查询需求</el-button></div>
      <el-table :data="draft.rows" border size="small" v-loading="draft.loading">
        <el-table-column prop="work_order_no" label="工单" min-width="175" />
        <el-table-column prop="item_name" label="物料" min-width="140" />
        <el-table-column prop="spec" label="规格" min-width="110" />
        <el-table-column label="待配数量" min-width="110"><template slot-scope="{row}">{{ row.remaining_to_prepare }} {{ row.unit_name }}</template></el-table-column>
        <el-table-column label="方式" width="100"><template slot-scope="{row}">{{ mode(row.fulfillment_mode) }}</template></el-table-column>
        <el-table-column label="选择库存" min-width="130"><template slot-scope="{row}"><el-button type="text" @click="stock(row)">{{ draft.selected[row.id] ? '已选 ' + sum(draft.selected[row.id].sources) + '，修改' : '选择库存来源' }}</el-button></template></el-table-column>
      </el-table>
      <el-pagination :pager-count="5" class="pm-pagination" :current-page.sync="draft.page" :page-size="20" :total="draft.total" layout="total, prev, pager, next" @current-change="loadDraft" />
      <div class="pm-selected">已选 {{ chosen.length }} 条需求（翻页保留）<el-tag v-for="row in chosen" :key="row.demand.id" closable @close="$delete(draft.selected, row.demand.id)">{{ row.demand.work_order_no }} · {{ row.demand.item_name }} · {{ sum(row.sources) }}</el-tag></div>
      <div class="pm-remark"><label>备注：</label><el-input v-model.trim="draft.remark" size="small" maxlength="2000" /></div>
      <span slot="footer"><el-button size="small" :disabled="busy" @click="draft.visible = false">取消</el-button><el-button size="small" type="success" :loading="busy" :disabled="!draft.warehouse.id || !chosen.length" @click="create">生成公共配料任务</el-button></span>
    </el-dialog>

    <el-dialog title="公共配料任务" :visible.sync="detail.visible" width="1100px" custom-class="production-material-dialog public-material-dialog" append-to-body :close-on-click-modal="false" :before-close="close">
      <el-alert v-if="detail.error" :title="detail.error" type="error" :closable="false" />
      <template v-if="detail.data">
        <el-alert v-if="detail.pendingAction" title="有一次提交尚未确认，请先核对该次结果。" type="warning" :closable="false" />
        <el-button v-if="detail.pendingAction" size="small" :loading="busy" @click="action(detail.pendingAction)">核对上次提交</el-button>
        <div class="pm-meta"><span>单号：{{ detail.data.task_no }}</span><span>状态：{{ status(detail.data.status) }}</span><span>拣货人：{{ detail.data.assigned_picker_name || '待领取' }}</span><span>仓库：{{ detail.data.warehouse && detail.data.warehouse.warehouse_name }}</span></div>
        <el-table :data="detail.data.lines.data" border size="small" v-loading="detail.loading">
          <el-table-column prop="work_order_no" label="来源工单" min-width="175" />
          <el-table-column prop="item_name" label="物料" min-width="135" />
          <el-table-column prop="spec" label="规格" min-width="105" />
          <el-table-column prop="planned_pick_qty" label="计划数量" width="105" />
          <el-table-column label="实拣数量" width="135"><template slot-scope="{row}"><el-input v-if="detail.data.status === 'PICKING'" v-model="detail.quantities[row.id]" size="small" inputmode="decimal" /><span v-else>{{ row.actual_pick_qty }}</span></template></el-table-column>
          <el-table-column prop="unit_name_snapshot" label="单位" width="75" />
          <el-table-column label="方式" width="100"><template slot-scope="{row}">{{ mode(row.fulfillment_mode_snapshot) }}</template></el-table-column>
          <el-table-column label="实物或序列号" width="125"><template slot-scope="{row}"><el-button v-if="detail.data.status === 'PICKING' && (row.material_management_mode === 'physical' || row.serial_control_type !== 'none')" type="text" @click="selectTracked(row)">选择 {{ (detail.tracked[row.id] || []).length }} 件</el-button><span v-else>—</span></template></el-table-column>
        </el-table>
        <el-pagination :pager-count="5" class="pm-pagination" :current-page.sync="detail.page" :page-size="20" :total="detail.data.lines.total" layout="total, prev, pager, next" @current-change="loadDetail" />
        <div class="pm-related"><b>工单配料记录</b><el-button v-for="child in detail.data.children" :key="child.id" type="text" @click="$emit('child', child.id)">{{ child.task_no }}</el-button></div>
        <div v-if="detail.data.status === 'WAIT_PICK' && $can('production.material_picking.assign')" class="pm-inline-field"><label>分配人员：</label><el-button size="small" @click="assign">选择拣货人</el-button></div>
      </template>
      <span slot="footer" v-if="detail.data" class="pm-footer">
        <span><el-button v-if="['WAIT_PICK','PICKING'].includes(detail.data.status) && $can('production.material_picking.cancel')" type="text" :disabled="busy || !!detail.pendingAction" @click="cancel">取消任务</el-button></span>
        <span>
          <el-button size="small" :disabled="busy" @click="detail.visible = false">关闭</el-button>
          <el-button v-if="canClaim" type="success" size="small" :loading="busy" :disabled="!!detail.pendingAction" @click="action('claim')">领取配料</el-button>
          <el-button v-if="canStart" type="success" size="small" :loading="busy" :disabled="!!detail.pendingAction" @click="action('start')">开始拣货</el-button>
          <el-button v-if="canConfirm" type="success" size="small" :loading="busy" :disabled="!!detail.pendingAction" @click="confirm">确认实拣出库</el-button>
        </span>
      </span>
    </el-dialog>
    <stock-selector ref="stock" @confirm="acceptStock" />
    <option-picker ref="options" />
  </section>
</template>
<script>
import { materialDemands } from '@/api/erp/production-materials'
import { publicPreparations, publicPreparation, publicPreparationCommand, materialWrite, pendingMaterialWrite } from '@/api/erp/public-materials'
import StockSelector from './ProductionStockSelector.vue'
import OptionPicker from './ProductionMaterialOptionPicker.vue'
const blankDraft = () => ({ visible: false, rows: [], selected: {}, warehouse: {}, keyword: '', page: 1, total: 0, loading: false, remark: '', error: '' })
export default {
  components: { StockSelector, OptionPicker },
  data: () => ({ view: 'demands', rows: [], total: 0, query: { keyword: '', page: 1 }, loading: false, busy: false, pendingCreate: false, error: '', listSequence: 0, draftSequence: 0, detailSequence: 0,
    draft: blankDraft(), detail: { visible: false, id: null, data: null, page: 1, quantities: {}, tracked: {}, lineMap: {}, error: '', loading: false } }),
  computed: {
    chosen() { return Object.values(this.draft.selected).filter(row => row.sources.length) },
    actorId() { try { const user = JSON.parse(localStorage.getItem('erp_user') || '{}'); return Number(user.legacy_id || user.id || 0) } catch (_) { return 0 } },
    canClaim() { const job = this.detail.data; return !!job && job.status === 'WAIT_PICK' && !job.assigned_picker_legacy_id && this.$can('production.material_picking.pick') },
    canStart() { const job = this.detail.data; return !!job && job.status === 'WAIT_PICK' && Number(job.assigned_picker_legacy_id) === this.actorId && this.$can('production.material_picking.pick') },
    canConfirm() { const job = this.detail.data; return !!job && job.status === 'PICKING' && Number(job.assigned_picker_legacy_id) === this.actorId && this.$can('production.material_picking.pick') }
  },
  mounted() { this.pendingCreate = !!pendingMaterialWrite('preparation-new-create'); if (!this.$can('production.material_requirement.view')) this.view = 'tasks'; this.load() },
  beforeDestroy() { this.listSequence++; this.draftSequence++; this.detailSequence++ },
  methods: {
    status(value) { return ({ WAIT_PICK: '待拣货', PICKING: '拣货中', PREPARED: '已配料', PARTIALLY_PREPARED: '部分配料', CANCELLED: '已取消' })[value] || value },
    mode(value) { return value === 'onsite_cutting' ? '现场领料' : '工序配送' },
    sum(sources) { return sources.reduce((total, row) => total + Number(row.quantity), 0) },
    close(done) { if (!this.busy) done() },
    search() { this.query.page = 1; this.load() },
    async load() {
      const sequence = ++this.listSequence; this.loading = true; this.error = ''
      try { const { data } = await (this.view === 'demands' ? materialDemands(this.query) : publicPreparations(this.query)); if (sequence === this.listSequence) { this.rows = data.data; this.total = data.total } }
      catch (e) { if (sequence === this.listSequence) this.error = e.userMessage || e.message }
      finally { if (sequence === this.listSequence) this.loading = false }
    },
    openDraft() { this.draftSequence++; this.draft = { ...blankDraft(), visible: true }; this.loadDraft() },
    searchDraft() { this.draft.page = 1; this.loadDraft() },
    async loadDraft() {
      const sequence = ++this.draftSequence; this.draft.loading = true
      try { const { data } = await materialDemands({ keyword: this.draft.keyword, page: this.draft.page, per_page: 20 }); if (sequence === this.draftSequence) { this.draft.rows = data.data; this.draft.total = data.total } }
      catch (e) { if (sequence === this.draftSequence) this.draft.error = e.userMessage || e.message }
      finally { if (sequence === this.draftSequence) this.draft.loading = false }
    },
    warehouse() {
      this.$refs.options.open('warehouses', '选择配料仓库', {}, async row => {
        if (this.draft.warehouse.id && this.draft.warehouse.id !== row.id && this.chosen.length) {
          try { await this.$confirm('更换仓库会清除已选库存来源。', '更换仓库') } catch (_) { return }
          this.draft.selected = {}
        }
        this.draft.warehouse = row
      })
    },
    stock(demand) {
      if (!this.draft.warehouse.id) { this.$message.warning('请先选择仓库'); return }
      this.$set(this.draft.selected, demand.id, this.draft.selected[demand.id] || { demand, sources: [] })
      this.$refs.stock.open(demand, this.draft.warehouse, this.draft.selected[demand.id].sources)
    },
    acceptStock({ demandId, sources }) { if (this.draft.selected[demandId]) this.draft.selected[demandId].sources = sources },
    async create() {
      const lines = this.chosen.flatMap(row => row.sources.map(source => ({ target_material_requirement_id: row.demand.id, inventory_balance_id: source.id, planned_pick_qty: source.quantity })))
      if (!lines.length || lines.length > 100) { this.draft.error = '单次公共配料请选择1至100条库存来源。'; return }
      const versions = Object.fromEntries(this.chosen.map(row => [row.demand.work_order_id, row.demand.work_order_version]))
      await this.write('create', { warehouse_id: this.draft.warehouse.id, work_order_versions: versions, lines, remark: this.draft.remark }, async result => {
        this.draft.visible = false; this.view = 'tasks'; await this.load(); await this.openDetail(result.id)
      }, message => { this.draft.error = message })
    },
    recoverCreate() { return this.write('create', {}, async result => { this.draft.visible = false; this.view = 'tasks'; await this.load(); await this.openDetail(result.id) }, message => { this.error = message }) },
    openDetail(id) { this.detailSequence++; this.detail = { visible: true, id, data: null, page: 1, quantities: {}, tracked: {}, lineMap: {}, error: '', loading: false }; return this.loadDetail() },
    async loadDetail() {
      const sequence = ++this.detailSequence; this.detail.loading = true; this.detail.error = ''
      try {
        const { data } = await publicPreparation(this.detail.id, { page: this.detail.page, per_page: 20 })
        if (sequence !== this.detailSequence) return
        const changed = this.detail.data && this.detail.data.business_version !== data.data.business_version
        if (changed) {
          const hadDraft = Object.values(this.detail.quantities).some(value => value !== '') || Object.keys(this.detail.tracked).length > 0
          this.detail.quantities = {}; this.detail.tracked = {}; this.detail.lineMap = {}
          if (hadDraft) this.detail.error = '任务已更新，请重新核对实拣数量和实物。'
        }
        this.detail.data = data.data
        this.$set(this.detail, 'pendingAction', ['claim', 'assign', 'start', 'confirm', 'cancel'].find(action => pendingMaterialWrite(`preparation-${this.detail.id}-${action}`)) || '')
        this.detail.data.lines.data.forEach(row => {
          this.$set(this.detail.lineMap, row.id, row)
          if (!(row.id in this.detail.quantities)) this.$set(this.detail.quantities, row.id, '')
        })
      } catch (e) { if (sequence === this.detailSequence) this.detail.error = e.userMessage || e.message }
      finally { if (sequence === this.detailSequence) this.detail.loading = false }
    },
    assign() { this.$refs.options.open('people', '分配拣货人', {}, row => this.action('assign', { assigned_picker_legacy_id: row.id })) },
    selectTracked(row) {
      const physical = row.material_management_mode === 'physical'
      this.$refs.options.open(physical ? 'physicals' : 'serials', physical ? '选择具体板材' : '选择序列号', { picking_task_id: row.task_id, picking_task_line_id: row.id }, selected => {
        this.$set(this.detail.tracked, row.id, selected); this.$set(this.detail.quantities, row.id, String(selected.length))
      }, this.detail.tracked[row.id] || [])
    },
    async action(action, extra = {}) {
      const job = this.detail.data
      await this.write(action, { expected_version: job.business_version, child_versions: Object.fromEntries(job.children.map(row => [row.id, row.business_version])), ...extra }, async () => {
        this.detail.quantities = {}; this.detail.tracked = {}; this.detail.lineMap = {}; await this.loadDetail(); this.load()
      }, message => { this.detail.error = message })
    },
    async cancel() {
      try { const { value } = await this.$prompt('请填写取消原因', '取消公共配料任务', { inputValidator: value => !!String(value || '').trim() || '请填写取消原因' }); await this.action('cancel', { reason: value.trim() }) }
      catch (_) { /* The user closed the cancellation prompt. */ }
    },
    confirm() {
      const lines = Object.entries(this.detail.quantities).filter(([, qty]) => qty !== '').map(([id, qty]) => {
        const row = this.detail.lineMap[id]; const tracked = this.detail.tracked[id] || []
        return { picking_task_line_id: Number(id), actual_pick_qty: qty, serial_ids: row.serial_control_type !== 'none' ? tracked.map(item => item.id) : undefined, physical_material_ids: row.material_management_mode === 'physical' ? tracked.map(item => item.id) : undefined }
      })
      if (lines.some(line => !/^\d+(\.\d{1,8})?$/.test(line.actual_pick_qty) || Number(line.actual_pick_qty) > Number(this.detail.lineMap[line.picking_task_line_id].planned_pick_qty))) { this.detail.error = '实拣数量必须为有效数量且不能超过计划。'; return }
      if (lines.length !== this.detail.data.lines.total || !lines.some(line => Number(line.actual_pick_qty) > 0)) { this.detail.error = '请核对各页并逐条填写实拣数量，未拣填写0；至少有一条大于0。'; return }
      return this.action('confirm', { lines })
    },
    async write(action, payload, success, fail) {
      if (this.busy) return
      this.busy = true
      try {
        const id = action === 'create' ? null : this.detail.id
        const { data } = await materialWrite(`preparation-${id || 'new'}-${action}`, payload, body => publicPreparationCommand(id, action, body))
        await success(data.data); this.$message.success(data.message)
      } catch (e) { fail(e.userMessage || '提交结果尚未确认，请重试确认上次操作。') }
      finally { this.busy = false; this.pendingCreate = !!pendingMaterialWrite('preparation-new-create') }
    }
  }
}
</script>
<style scoped>
.public-preparation-panel { min-width: 0; }
.public-preparation-panel .pm-filters { flex-wrap: wrap; }
.public-preparation-panel .pm-filters > .el-input { flex: 1 1 200px; min-width: 0; }
</style>
