<template>
  <el-dialog title="选择库存" :visible.sync="visible" width="1060px" custom-class="production-material-dialog public-material-dialog" append-to-body :close-on-click-modal="false" @closed="sequence++">
    <template v-if="demand">
      <h3 class="pm-stock-heading">{{ demand.item_name }} · {{ demand.item_code }} · {{ demand.spec || '—' }}</h3>
      <div class="pm-meta"><span>仓库：{{ warehouse.warehouse_name }}</span><span>待配：<b class="pm-warning">{{ number(demand.remaining_to_prepare) }} {{ demand.unit_name }}</b></span></div>
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
            <el-table-column label="本次配料" width="160"><template slot-scope="{ row }"><el-input v-if="selected[row.id]" v-model="selected[row.id].quantity" size="small" inputmode="decimal" :class="{ 'pm-invalid': invalid(selected[row.id]) }"><span slot="append">{{ demand.unit_name }}</span></el-input><span v-else>—</span></template></el-table-column>
          </el-table>
          <el-pagination v-if="!selectedOnly" class="pm-pagination" :current-page.sync="query.page" :page-size.sync="query.per_page" :total="total" :page-sizes="[10,20,50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
          <div class="pm-selected"><div><strong>已选 {{ chosen.length }} 个库存来源 · 合计 {{ number(sum) }} {{ demand.unit_name }}</strong><el-button type="text" @click="selectedOnly = !selectedOnly">{{ selectedOnly ? '返回库存列表' : '查看已选' }}</el-button></div><el-tag v-for="row in chosen" :key="row.id" closable @close="toggle(row, false)">{{ location(row) }} · {{ row.batch_no }} · {{ number(row.quantity) }} {{ demand.unit_name }}</el-tag></div>
        </main>
      </div>
    </template>
    <span slot="footer"><el-button size="small" @click="visible = false">取消</el-button><el-button size="small" type="success" :loading="checking" :disabled="!chosen.length || chosen.some(invalid) || sum > Number(demand && demand.remaining_to_prepare)" @click="confirm">确认选择</el-button></span>
  </el-dialog>
</template>

<script>
import { materialWorkspace } from '@/api/erp/production-materials'
export default {
  data: () => ({ visible: false, demand: null, warehouse: {}, selected: {}, categories: [], rows: [], total: 0, query: {}, loading: false, checking: false, error: '', sequence: 0, selectedOnly: false }),
  computed: {
    chosen() { return Object.values(this.selected) },
    sum() { return this.chosen.reduce((sum, row) => sum + (Number(row.quantity) || 0), 0) },
    displayRows() { return this.selectedOnly ? this.chosen : this.rows }
  },
  methods: {
    number(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) },
    location(row) { return (row.location && (row.location.location_code || row.location.location_name)) || '—' },
    invalid(row) { return !/^\d+(\.\d{1,8})?$/.test(String(row.quantity)) || Number(row.quantity) <= 0 || Number(row.quantity) > Number(row.picking_available_qty) },
    async open(demand, warehouse, sources) {
      this.sequence++; this.demand = { ...demand }; this.warehouse = warehouse; this.selected = {}; this.rows = []; this.categories = []; this.error = ''; this.selectedOnly = false
      ;(sources || []).forEach(row => { this.$set(this.selected, row.id, { ...row }) })
      this.query = { keyword: '', category_id: '', page: 1, per_page: 10 }; this.visible = true; this.load()
      const sequence = this.sequence
      try { const { data } = await materialWorkspace('options', { kind: 'categories', target_material_requirement_id: demand.id }); if (sequence === this.sequence) this.categories = data.data || [] } catch (e) { if (sequence === this.sequence) this.error = e.userMessage }
    },
    search() { this.query.page = 1; this.load() },
    async load() {
      const sequence = ++this.sequence; this.loading = true; this.error = ''
      try {
        const { data } = await materialWorkspace('sources', { ...this.query, target_material_requirement_id: this.demand.id, warehouse_id: this.warehouse.id })
        if (sequence !== this.sequence) return
        this.rows = data.data; this.total = data.total
        this.rows.forEach(row => { if (this.selected[row.id]) this.$set(this.selected, row.id, { ...row, quantity: this.selected[row.id].quantity }) })
      } catch (e) { if (sequence === this.sequence) this.error = e.userMessage } finally { if (sequence === this.sequence) this.loading = false }
    },
    toggle(row, checked) {
      if (checked) this.$set(this.selected, row.id, { ...row, quantity: Math.min(Number(row.picking_available_qty), Math.max(0, Number(this.demand.remaining_to_prepare) - this.sum)) || '' })
      else this.$delete(this.selected, row.id)
    },
    async confirm() {
      this.checking = true; this.error = ''
      try {
        // Revalidate every selected page; selection remains intact when stock changed.
        const chosen = this.chosen.map(row => ({ ...row })); const updated = []
        for (let offset = 0; offset < chosen.length; offset += 100) {
          const chunk = chosen.slice(offset, offset + 100)
          const { data } = await materialWorkspace('sources', { target_material_requirement_id: this.demand.id, warehouse_id: this.warehouse.id, ids: chunk.map(row => row.id), per_page: 100 })
          chunk.forEach(row => { const fresh = data.data.find(value => value.id === row.id); updated.push({ ...row, ...(fresh || { picking_available_qty: 0 }), quantity: row.quantity }) })
        }
        updated.forEach(row => this.$set(this.selected, row.id, row))
        if (updated.some(this.invalid)) { this.error = '库存已变化，请调整后重试。'; return }
        this.$emit('confirm', { demandId: this.demand.id, sources: updated }); this.visible = false
      } catch (e) { this.error = e.userMessage } finally { this.checking = false }
    }
  }
}
</script>
