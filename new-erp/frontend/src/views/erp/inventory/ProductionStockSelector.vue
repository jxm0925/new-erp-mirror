<template>
  <el-dialog title="选择库存" :visible.sync="visible" width="1060px" custom-class="production-material-dialog public-material-dialog" append-to-body :close-on-click-modal="false" @closed="sequence++">
    <template v-if="demand">
      <h3 class="pm-stock-heading">{{ demand.item_name }} · {{ demand.item_code }} · {{ demand.spec || '—' }}</h3>
      <div class="pm-meta"><span>仓库：{{ warehouse.warehouse_name }}</span><span>待配：<b class="pm-warning">{{ number(demand.remaining_to_prepare) }} {{ demand.unit_name }}</b></span></div>
      <div v-if="cuttingTotalLength(demand)" class="pm-meta"><span>用量口径：按总用量</span><span>待覆盖总用量：{{ totalLengthMetres(demand.remaining_cut_total_length_mm) || '—' }} m</span></div>
      <div v-else-if="cuttingWholeStock(demand)" class="pm-meta"><span>下料尺寸：{{ demand.cutting_member_requirements ? '已合并 ' + demand.cutting_member_requirements.length + ' 条明细' : cuttingDimensions(demand.cutting_requirement_dimensions) }}</span><span>待加工：{{ number(demand.remaining_cut_piece_qty) }} {{ demand.cutting_unit_kind === 'root' ? '段' : '块' }}</span></div>
      <div class="pm-stock-layout">
        <aside><button type="button" class="active" @click="query.category_id = ''; search()">全部符合条件的库存</button><button v-for="category in categories" :key="category.id" type="button" :class="{ active: query.category_id === category.id }" @click="query.category_id = category.id; search()">{{ category.category_name }}</button></aside>
        <main>
          <div class="pm-filters"><el-input v-model="query.keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="编码 / 名称 / 规格 / 批次 / 库位" @keyup.enter.native="search" /><el-button size="small" type="success" @click="search">查询</el-button></div>
          <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
          <el-table v-loading="loading" :data="displayRows" size="small" border empty-text="暂无符合条件的库存">
            <el-table-column width="46"><template slot-scope="{ row }"><el-checkbox :value="!!selected[row.id]" :disabled="!selected[row.id] && Number(row.picking_available_qty) <= 0" :aria-label="'选择库存 ' + row.batch_no" @change="toggle(row, $event)" /></template></el-table-column>
            <el-table-column label="库位" min-width="85"><template slot-scope="{ row }">{{ location(row) }}</template></el-table-column>
            <el-table-column prop="batch_no" label="批次" min-width="145" />
            <el-table-column label="库存数量" width="100"><template slot-scope="{ row }">{{ number(row.quantity_on_hand) }} {{ demand.unit_name }}</template></el-table-column>
            <el-table-column label="可用数量" width="100"><template slot-scope="{ row }">{{ number(row.picking_available_qty) }} {{ demand.unit_name }}</template></el-table-column>
            <el-table-column v-if="cuttingWholeStock(demand) && !cuttingTotalLength(demand) && !demand.cutting_member_requirements" label="每根／张可加工" width="130"><template slot-scope="{row}">{{ number(row.cutting_capacity_piece_qty) }} {{ row.cutting_unit_kind === 'root' ? '段' : '块' }}</template></el-table-column>
            <el-table-column label="本次配料" width="160"><template slot-scope="{ row }"><template v-if="selected[row.id]"><template v-if="sheetPlanning"><strong>{{ (selected[row.id].physical_material_ids || []).length }} 张</strong><el-button type="text" size="small" :disabled="checking || planBusy" @click="chooseBoards(selected[row.id])">选择实际板材</el-button></template><el-input v-else v-model="selected[row.id].quantity" size="small" inputmode="decimal" :class="{ 'pm-invalid': invalid(selected[row.id]) }"><span slot="append">{{ demand.unit_name }}</span></el-input></template><span v-else>—</span></template></el-table-column>
            <el-table-column v-if="cuttingWholeStock(demand)" :label="cuttingTotalLength(demand) ? '本次覆盖总用量' : '本次加工件数'" :min-width="demand.cutting_member_requirements ? 220 : 155"><template slot-scope="{row}"><template v-if="selected[row.id]"><template v-if="sheetPlanning"><small v-for="member in selected[row.id].cutting_members || []" :key="member.target_material_requirement_id">{{ memberLabel(member.target_material_requirement_id) }}：{{ member.planned_cut_piece_qty || '0' }} 件<br></small></template><el-input v-else-if="cuttingTotalLength(demand)" v-model="selected[row.id].planned_cut_total_length_m" size="small" inputmode="decimal" :class="{ 'pm-invalid': invalid(selected[row.id]) }" @input="setTotalLength(selected[row.id], $event)"><span slot="append">m</span></el-input><div v-else-if="selected[row.id].cutting_members" class="pm-cut-members"><label v-for="member in selected[row.id].cutting_members" :key="member.target_material_requirement_id"><small>{{ memberLabel(member.target_material_requirement_id) }}</small><el-input v-model="member.planned_cut_piece_qty" size="small" inputmode="numeric" :class="{ 'pm-invalid': invalid(selected[row.id]) }"><span slot="append">{{ row.cutting_unit_kind === 'root' ? '段' : '块' }}</span></el-input></label></div><el-input v-else v-model="selected[row.id].planned_cut_piece_qty" size="small" inputmode="numeric" :class="{ 'pm-invalid': invalid(selected[row.id]) }"><span slot="append">{{ row.cutting_unit_kind === 'root' ? '段' : '块' }}</span></el-input></template><span v-else>—</span></template></el-table-column>
          </el-table>
          <el-pagination v-if="!selectedOnly" class="pm-pagination" :current-page.sync="query.page" :page-size.sync="query.per_page" :total="total" :page-sizes="[10,20,50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
          <div class="pm-selected"><div><strong>已选 {{ chosen.length }} 个库存来源 · 合计 {{ number(sum) }} {{ demand.unit_name }}</strong><el-button type="text" @click="selectedOnly = !selectedOnly">{{ selectedOnly ? '返回库存列表' : '查看已选' }}</el-button></div><el-tag v-for="row in chosen" :key="row.id" closable @close="toggle(row, false)">{{ location(row) }} · {{ row.batch_no }} · {{ number(row.quantity) }} {{ demand.unit_name }}</el-tag></div>
          <div v-if="sheetPlanning" class="pm-source-plans">
            <section v-for="source in chosen" :key="source.id" class="pm-source-plan" v-loading="source.plan_loading">
              <div class="pm-source-plan-heading"><strong>逐板下料方案 · {{ source.batch_no }}</strong><span><el-button size="mini" :disabled="checking || planBusy" @click="chooseBoards(source)">调整所选板材</el-button><el-button size="mini" :disabled="checking || planBusy || !(source.physical_material_ids || []).length" @click="suggestPlan(source)">采用系统推荐</el-button><el-button size="mini" type="success" plain :disabled="checking || planBusy || !(source.physical_material_ids || []).length" @click="previewPlan(source, false)">核对当前方案</el-button></span></div>
              <p class="pm-source-plan-note">每行是一块实际板材。各规格件数可修改，填0表示这块板不切该规格。正式配料后按本方案拣料、配送和收料。</p>
              <el-alert v-if="source.plan_error" :title="source.plan_error" type="error" :closable="false" />
              <el-table :data="source.cutting_source_plan ? source.cutting_source_plan.sources : []" border size="mini" empty-text="请先选择实际板材">
                <el-table-column prop="physical_no" label="板材实物编号" min-width="150" />
                <el-table-column label="实际尺寸 / 面积" min-width="150"><template slot-scope="{row}">{{ sourceDimensions(row) }}</template></el-table-column>
                <el-table-column v-for="requirement in planRequirements" :key="requirement.id" :label="cuttingDimensions(requirement.cutting_requirement_dimensions)" min-width="135"><template slot-scope="{row}"><el-input :value="planCount(row, requirement.id)" size="mini" inputmode="numeric" :disabled="checking || planBusy" @input="changePlanCount(source, row, requirement.id, $event)"><span slot="append">件</span></el-input></template></el-table-column>
                <el-table-column label="系统建议余料" min-width="130"><template slot-scope="{row}">{{ source.cutting_plan_checked ? (area(row.suggested_remnant_area_mm2) || '0') + ' ㎡' : '待核对方案' }}</template></el-table-column>
              </el-table>
              <p class="pm-source-plan-note" :class="{'pm-danger': !source.cutting_plan_checked}">{{ source.cutting_plan_checked ? '当前方案已由系统核对，可确认选择。' : '板材或件数已变化，请核对当前方案。人工填写的件数会保留。' }}</p>
            </section>
          </div>
        </main>
      </div>
    </template>
    <material-option-picker ref="physicals" />
    <span slot="footer"><el-button size="small" :disabled="checking || planBusy" @click="visible = false">取消</el-button><el-button size="small" type="success" :loading="checking" :disabled="!validSelection || planBusy" @click="confirm">确认选择</el-button></span>
  </el-dialog>
</template>

<script>
import { materialWorkspace, previewCuttingSourcePlan } from '@/api/erp/production-materials'
import { cuttingWholeStock, cuttingTotalLength, cuttingDimensions, cuttingSourceSuggestion, validCuttingSource, validCuttingGroup } from '@/utils/production-cutting-allocation.mjs'
import { totalLengthMetres, totalLengthMillimetres, cuttingAreaSquareMetres } from '@/utils/cutting-quantity.mjs'
import MaterialOptionPicker from './ProductionMaterialOptionPicker.vue'
export default {
  components: { MaterialOptionPicker },
  data: () => ({ visible: false, demand: null, warehouse: {}, selected: {}, categories: [], rows: [], total: 0, query: {}, loading: false, checking: false, error: '', sequence: 0, selectedOnly: false }),
  computed: {
    chosen() { return Object.values(this.selected) },
    sheetPlanning() { return this.demand?.cutting_unit_kind === 'sheet' },
    planRequirements() { return (this.demand?.cutting_member_requirements || (this.demand ? [this.demand] : [])).map(row => ({ ...row, id: Number(row.id ?? row.target_material_requirement_id) })) },
    planBusy() { return this.chosen.some(row => row.plan_loading) },
    sum() { return this.chosen.reduce((sum, row) => sum + (Number(row.quantity) || 0), 0) },
    validSelection() { return !!this.demand && validCuttingGroup(this.demand, this.chosen) },
    displayRows() { return this.selectedOnly ? this.chosen : this.rows }
  },
  methods: {
    cuttingWholeStock, cuttingTotalLength, cuttingDimensions, totalLengthMetres, area: cuttingAreaSquareMetres,
    setTotalLength(row, value) { this.$set(row, 'planned_cut_total_length_mm', totalLengthMillimetres(value, 5)) },
    memberLabel(id) { const row=this.planRequirements.find(row=>Number(row.id)===Number(id)); return cuttingDimensions(row?.cutting_requirement_dimensions) },
    number(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) },
    location(row) { return (row.location && (row.location.location_code || row.location.location_name)) || '—' },
    invalid(row) { return !validCuttingSource(row) },
    async open(demand, warehouse, sources) {
      this.sequence++; this.checking = false; this.demand = { ...demand }; this.warehouse = warehouse; this.selected = {}; this.rows = []; this.categories = []; this.error = ''; this.selectedOnly = false
      ;(sources || []).forEach(row => { this.$set(this.selected, row.id, { ...JSON.parse(JSON.stringify(row)), plan_loading: false, plan_error: '', planned_cut_total_length_m: row.planned_cut_total_length_m ?? totalLengthMetres(row.planned_cut_total_length_mm) }) })
      this.query = { keyword: '', category_id: '', page: 1, per_page: 10 }; this.visible = true; this.load()
      const sequence = this.sequence
      try { const { data } = await materialWorkspace('options', { kind: 'categories', target_material_requirement_id: demand.id }); if (sequence === this.sequence) this.categories = data.data || [] } catch (e) { if (sequence === this.sequence) this.error = e.userMessage }
    },
    search() { this.query.page = 1; this.load() },
    async load() {
      const sequence = ++this.sequence; this.loading = true; this.error = ''
      try {
        const { data } = await materialWorkspace('sources', { ...this.query, target_material_requirement_id: this.demand.id, target_material_requirement_ids:this.demand.cutting_member_requirements?.map(row=>row.id), warehouse_id: this.warehouse.id })
        if (sequence !== this.sequence) return
        this.rows = data.data; this.total = data.total
        this.rows.forEach(row => { if (this.selected[row.id]) { const previous=this.selected[row.id]; this.$set(this.selected, row.id, { ...row, ...previous, picking_available_qty:row.picking_available_qty, cutting_member_requirements:previous.cutting_member_requirements || this.demand.cutting_member_requirements || row.cutting_member_requirements }) } })
      } catch (e) { if (sequence === this.sequence) this.error = e.userMessage } finally { if (sequence === this.sequence) this.loading = false }
    },
    toggle(row, checked) {
      if (this.checking || this.planBusy) return
      if (checked) {
        const source = { ...row, ...cuttingSourceSuggestion(row, this.demand, this.chosen) }
        if (this.sheetPlanning) {
          source.cutting_member_requirements = this.planRequirements.map(value => ({ ...value }))
          if (!source.cutting_members) source.cutting_members = this.planRequirements.map(requirement => ({ target_material_requirement_id: requirement.id, planned_cut_piece_qty: String(Math.max(0, Number(requirement.remaining_cut_piece_qty) - this.chosen.reduce((sum, value) => sum + Number(value.cutting_members?.find(member => Number(member.target_material_requirement_id) === requirement.id)?.planned_cut_piece_qty || 0), 0))) }))
          Object.assign(source, { physical_material_ids: [], physicals: [], cutting_source_plan: { version: 1, source_fingerprint: '', sources: [] }, cutting_plan_checked: false, plan_loading: false, plan_error: '' })
        }
        this.$set(this.selected, row.id, source)
      }
      else this.$delete(this.selected, row.id)
    },
    sourceDimensions(source) { return source.capacity_basis === 'area' || (source.shape || source.dimensions?.shape) === 'IRREGULAR' ? '异形 · ' + (this.area(source.area_mm2 ?? source.dimensions?.area_mm2) || '—') + ' ㎡' : cuttingDimensions(source.dimensions) },
    planCount(source, id) { return source.members?.find(member => Number(member.target_material_requirement_id) === Number(id))?.planned_cut_piece_qty ?? '0' },
    changePlanCount(source, board, id, value) {
      let member = board.members.find(row => Number(row.target_material_requirement_id) === Number(id))
      if (!member) { member = { target_material_requirement_id: Number(id), planned_cut_piece_qty: value }; board.members.push(member) } else member.planned_cut_piece_qty = value
      source.cutting_plan_checked = false; source.plan_error = ''; this.updatePlanTotals(source)
    },
    updatePlanTotals(source) {
      source.cutting_members = this.planRequirements.map(requirement => ({ target_material_requirement_id: requirement.id, planned_cut_piece_qty: String((source.cutting_source_plan?.sources || []).reduce((sum, board) => sum + Number(this.planCount(board, requirement.id)), 0)) })).filter(member => Number(member.planned_cut_piece_qty) > 0)
      source.planned_cut_piece_qty = source.cutting_members.reduce((sum, member) => sum + Number(member.planned_cut_piece_qty), 0)
    },
    chooseBoards(source) {
      if (this.checking || this.planBusy) return
      const demand = this.demand
      this.$refs.physicals.open('warehouse-physicals', '选择本次下料的实际板材', { target_material_requirement_id: demand.id, target_material_requirement_ids: this.planRequirements.map(row => row.id), inventory_balance_id: source.id }, async rows => {
        if (!this.visible || demand !== this.demand || this.selected[source.id] !== source) return
        const previous = source.cutting_source_plan?.sources || []; const firstSelection = !previous.length
        source.physicals = rows; source.physical_material_ids = rows.map(row => Number(row.id)); source.quantity = ''
        source.cutting_source_plan = { version: 1, source_fingerprint: '', sources: rows.map(row => ({ ...row, source_key: 'physical:' + row.id, physical_material_id: Number(row.id), expected_physical_version: row.business_version, members: previous.find(board => Number(board.physical_material_id) === Number(row.id))?.members || [] })) }
        source.cutting_plan_checked = false; source.plan_error = ''
        if (firstSelection) await this.previewPlan(source, true); else this.updatePlanTotals(source)
      }, source.physicals || [])
    },
    async suggestPlan(source) {
      if (source.cutting_source_plan?.sources?.some(row => row.members?.length)) { try { await this.$confirm('系统推荐将替换本来源当前填写的逐板件数，是否继续？', '采用系统推荐', { type: 'warning' }) } catch (_) { return } }
      await this.previewPlan(source, true)
    },
    async previewPlan(source, suggest) {
      if (source.plan_loading || !source.physical_material_ids?.length) return false
      const demand = this.demand; source.plan_loading = true; source.plan_error = ''
      try {
        let members = source.cutting_members || []
        if (!suggest) {
          for (const board of source.cutting_source_plan?.sources || []) {
            if (!(board.members || []).some(member => Number(member.planned_cut_piece_qty) > 0)) throw new Error('每块所选板材至少安排一件实际下料规格')
            if (board.members.some(member => !/^\d+$/.test(String(member.planned_cut_piece_qty)) || !Number.isSafeInteger(Number(member.planned_cut_piece_qty)))) throw new Error('逐板加工件数须为非负整数')
          }
          this.updatePlanTotals(source); members = source.cutting_members
        }
        members = members.filter(member => Number(member.planned_cut_piece_qty) !== 0)
        if (!members.length || members.some(member => !/^\d+$/.test(String(member.planned_cut_piece_qty)) || !Number.isSafeInteger(Number(member.planned_cut_piece_qty)) || Number(member.planned_cut_piece_qty) <= 0)) throw new Error('请为本库存来源至少安排一条有效的正整数加工件数')
        const body = { target_material_requirement_id: demand.id, target_material_requirement_ids: this.planRequirements.map(row => row.id), inventory_balance_id: source.id, physical_material_ids: source.physical_material_ids, cutting_members: members.map(member => ({ target_material_requirement_id: Number(member.target_material_requirement_id), planned_cut_piece_qty: Number(member.planned_cut_piece_qty) })) }
        if (!suggest) body.cutting_source_plan = { version: 1, sources: source.cutting_source_plan.sources.map(board => ({ physical_material_id: Number(board.physical_material_id), expected_physical_version: Number(board.expected_physical_version), members: board.members.filter(member => Number(member.planned_cut_piece_qty) > 0).map(member => ({ target_material_requirement_id: Number(member.target_material_requirement_id), planned_cut_piece_qty: Number(member.planned_cut_piece_qty) })) })) }
        const { data } = await previewCuttingSourcePlan(body)
        if (!this.visible || demand !== this.demand || this.selected[source.id] !== source) return false
        const plan = data.data?.cutting_source_plan
        const allocation = data.data?.allocation
        if (!plan?.source_fingerprint || !Array.isArray(plan.sources) || !Number(data.data.owner_target_material_requirement_id) || !['sheet', 'm2'].includes(allocation?.stock_unit_kind) || !/^\d+(?:\.\d{1,8})?$/.test(String(allocation.planned_stock_qty)) || Number(allocation.planned_stock_qty) <= 0) throw new Error('下料方案返回不完整，请保留当前填写并重新核对')
        source.cutting_source_plan = JSON.parse(JSON.stringify({ ...plan, owner_target_material_requirement_id: data.data.owner_target_material_requirement_id, owner_material_requirement_id: data.data.owner_material_requirement_id, planned_stock_qty: allocation.planned_stock_qty, stock_unit_kind: allocation.stock_unit_kind })); source.quantity = String(allocation.planned_stock_qty); source.cutting_plan_checked = true; this.updatePlanTotals(source)
        return true
      } catch (e) { source.cutting_plan_checked = false; source.plan_error = e.userMessage || e.message; return false } finally { source.plan_loading = false }
    },
    async confirm() {
      if (this.checking || !this.validSelection) return
      this.checking = true; this.error = ''
      const sequence = this.sequence; const demand = this.demand; const warehouse = this.warehouse
      try {
        // Revalidate every selected page; selection remains intact when stock changed.
        const chosen = this.chosen.map(row => ({ ...row })); const updated = []
        for (let offset = 0; offset < chosen.length; offset += 100) {
          const chunk = chosen.slice(offset, offset + 100)
          const { data } = await materialWorkspace('sources', { target_material_requirement_id: demand.id, target_material_requirement_ids:demand.cutting_member_requirements?.map(row=>row.id), warehouse_id: warehouse.id, ids: chunk.map(row => row.id), per_page: 100 })
          if (sequence !== this.sequence || demand !== this.demand) return
          chunk.forEach(row => { const fresh = data.data.find(value => value.id === row.id); updated.push({ ...row, ...(fresh || { picking_available_qty: 0 }), quantity: row.quantity, cutting_members:row.cutting_members, cutting_member_requirements:row.cutting_member_requirements, physical_material_ids:row.physical_material_ids, physicals:row.physicals, cutting_source_plan:row.cutting_source_plan, cutting_plan_checked:row.cutting_plan_checked, plan_loading:false, plan_error:'' }) })
        }
        updated.forEach(row => this.$set(this.selected, row.id, row))
        if (this.sheetPlanning) { for (const source of updated) { if (!await this.previewPlan(source, false)) return } }
        if (!validCuttingGroup(demand, updated)) { this.error = cuttingTotalLength(demand) ? '库存或待覆盖总用量已变化，请调整后重试。' : '库存或待加工件数已变化，请调整后重试。'; return }
        this.$emit('confirm', { demandId: demand.id, sources: updated }); this.visible = false
      } catch (e) { if (sequence === this.sequence) this.error = e.userMessage } finally { if (sequence === this.sequence) this.checking = false }
    }
  }
}
</script>
