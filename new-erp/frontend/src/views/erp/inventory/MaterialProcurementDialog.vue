<template>
  <div>
    <el-dialog title="提交采购需求" :visible.sync="visible" width="980px" custom-class="production-material-dialog public-material-dialog" append-to-body :close-on-click-modal="false" :before-close="close">
      <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
      <div class="pm-inline-field"><label>来源：</label><el-radio-group v-model="mode" :disabled="!!sourceDemand" @change="changeMode"><el-radio label="order">订单缺料</el-radio><el-radio label="manual">{{ sourceDemand ? '工单缺料' : '自行选料' }}</el-radio></el-radio-group></div>
      <div v-if="mode === 'order'" class="pm-inline-field"><label>订单：</label><el-input :value="order && order.sales_order_no" readonly size="small" placeholder="选择来源订单" @click.native="openPicker('orders')" /><el-button size="small" @click="openPicker('orders')">选择订单</el-button></div>
      <div class="pm-inline-field"><label>需要日期：</label><el-date-picker v-model="expectedDate" size="small" type="date" value-format="yyyy-MM-dd" /></div>
      <el-button size="small" :disabled="!!sourceDemand" @click="openPicker('items')">选择缺料物料</el-button>
      <el-table :data="lines" border size="small" empty-text="请选择需要采购的物料">
        <el-table-column prop="item_code" label="编码" min-width="125" />
        <el-table-column prop="item_name" label="物料" min-width="150" />
        <el-table-column prop="spec" label="规格" min-width="120" />
        <el-table-column label="需求数量" width="145"><template slot-scope="{row}"><el-input v-model="row.request_qty" size="small" inputmode="decimal" placeholder="填写数量" /><small v-if="row.procureable_qty !== undefined">可申购 {{ row.procureable_qty }} {{ row.unit_name }}</small></template></el-table-column>
        <el-table-column prop="unit_name" label="库存单位" width="95" />
        <el-table-column label="操作" width="75"><template slot-scope="{row}"><el-button type="text" @click="lines = lines.filter(line => line.id !== row.id)">移除</el-button></template></el-table-column>
      </el-table>
      <div class="pm-remark"><label>用途或缺料说明：</label><el-input v-model.trim="remark" type="textarea" maxlength="2000" :rows="3" /></div>
      <p class="pm-muted">数量按库存单位填写。提交后生成正式采购需求，由采购端继续处理。</p>
      <span slot="footer"><el-button size="small" :disabled="busy" @click="visible = false">取消</el-button><el-button v-if="pending" type="success" size="small" :loading="busy" @click="submit">核对上次提交</el-button><el-button v-else type="success" size="small" :loading="busy" :disabled="!valid" @click="submit">提交采购需求</el-button></span>
    </el-dialog>
    <el-dialog :title="picker.kind === 'orders' ? '选择来源订单' : '选择采购物料'" :visible.sync="picker.visible" width="980px" custom-class="production-material-dialog public-material-dialog" append-to-body :close-on-click-modal="false" @closed="picker.sequence++">
      <div class="procurement-picker-layout">
      <aside v-if="picker.kind === 'items'" class="procurement-categories"><el-button size="small" :type="!picker.category_id ? 'success' : ''" @click="category(null)">全部分类</el-button><el-button v-for="row in categories" :key="row.id" size="small" :type="picker.category_id === row.id ? 'success' : ''" @click="category(row.id)">{{ row.category_name }}</el-button><el-pagination small :current-page.sync="categoryPage" :page-size="10" :total="categoryTotal" layout="prev, next" @current-change="loadCategories" /></aside>
      <div class="procurement-picker-main">
      <div class="pm-filters"><el-input v-model.trim="picker.keyword" size="small" clearable :placeholder="picker.kind === 'orders' ? '订单号或客户名称' : '物料编码、名称或规格'" @keyup.enter.native="searchPicker" /><el-button size="small" @click="searchPicker">查询</el-button></div>
      <el-alert v-if="picker.error" :title="picker.error" type="error" :closable="false" />
      <el-table :data="picker.rows" v-loading="picker.loading" border size="small" @row-click="select">
        <el-table-column label="选择" width="55"><template slot-scope="{row}"><el-checkbox v-if="picker.kind === 'items'" :value="!!picker.selected[row.id]" @click.native.stop @change="select(row)" /><el-radio v-else :value="picker.current && picker.current.id" :label="row.id">&nbsp;</el-radio></template></el-table-column>
        <el-table-column :label="picker.kind === 'orders' ? '订单号' : '物料编码'" min-width="170"><template slot-scope="{row}">{{ row.sales_order_no || row.item_code }}</template></el-table-column>
        <el-table-column :label="picker.kind === 'orders' ? '客户' : '物料名称'" min-width="170"><template slot-scope="{row}">{{ row.customer_name || row.item_name }}</template></el-table-column>
        <el-table-column v-if="picker.kind === 'items'" prop="spec" label="规格" min-width="130" />
        <el-table-column v-if="picker.kind === 'items'" prop="unit_name" label="库存单位" width="100" />
      </el-table>
      <el-pagination :pager-count="5" class="pm-pagination" :total="picker.total" :current-page.sync="picker.page" :page-size="20" layout="total, prev, pager, next" @current-change="loadPicker" />
      <div v-if="picker.kind === 'items'" class="pm-selected">已选 {{ Object.keys(picker.selected).length }} 种物料<el-tag v-for="row in Object.values(picker.selected)" :key="row.id" closable @close="$delete(picker.selected, row.id)">{{ row.item_name }}</el-tag></div>
      </div></div>
      <span slot="footer"><el-button size="small" @click="picker.visible = false">取消</el-button><el-button type="success" size="small" :disabled="picker.kind === 'orders' ? !picker.current : !Object.keys(picker.selected).length" @click="acceptPicker">确认选择</el-button></span>
    </el-dialog>
  </div>
</template>
<script>
import { procurementOptions, createMaterialProcurement, materialWrite, pendingMaterialWrite } from '@/api/erp/public-materials'
const blankPicker = () => ({ visible: false, kind: 'items', keyword: '', category_id: null, page: 1, total: 0, rows: [], selected: {}, current: null, loading: false, error: '', sequence: 0 })
export default {
  data: () => ({ visible: false, mode: 'manual', order: null, sourceDemand: null, lines: [], expectedDate: '', remark: '', busy: false, error: '', pending: false, categories: [], categoryPage: 1, categoryTotal: 0, categorySequence: 0, picker: blankPicker() }),
  computed: {
    valid() { return this.lines.length > 0 && this.lines.length <= 100 && this.remark && (this.mode !== 'order' || this.order) && this.lines.every(row => /^\d+(\.\d{1,4})?$/.test(row.request_qty) && Number(row.request_qty) > 0 && row.unit_name && (row.procureable_qty === undefined || Number(row.request_qty) <= Number(row.procureable_qty))) }
  },
  methods: {
    open(demand) {
      this.picker.sequence++
      Object.assign(this, { visible: true, mode: 'manual', order: null, sourceDemand: demand || null, lines: [], expectedDate: '', remark: '', error: '' })
      this.pending = !!pendingMaterialWrite('procurement-new')
      if (demand) this.lines = [{ id: demand.component_item_id, item_code: demand.item_code, item_name: demand.item_name, spec: demand.spec, unit_name: demand.unit_name, request_qty: '', procureable_qty: demand.procureable_qty,
        ...(demand.demand_stage === 'preparation'
          ? { preparation_material_requirement_id: demand.preparation_material_requirement_id, preparation_version: demand.preparation_version, work_order_version: demand.work_order_version }
          : { target_material_requirement_id: demand.id }) }]
    },
    close(done) { if (!this.busy) done() },
    changeMode() { this.order = null },
    async openPicker(kind) {
      if (this.sourceDemand) return
      const sequence = this.picker.sequence + 1
      this.picker = { ...blankPicker(), sequence, kind, visible: true, selected: Object.fromEntries(this.lines.map(row => [row.id, row])) }
      if (kind === 'items') { this.categoryPage = 1; this.loadCategories() }
      this.loadPicker()
    },
    category(id) { this.picker.category_id = id; this.searchPicker() },
    async loadCategories() {
      const sequence = ++this.categorySequence
      try { const { data } = await procurementOptions('categories', { page: this.categoryPage, per_page: 10 }); if (sequence === this.categorySequence) { this.categories = data.data; this.categoryTotal = data.total } }
      catch (e) { if (sequence === this.categorySequence) this.picker.error = e.userMessage || e.message }
    },
    searchPicker() { this.picker.page = 1; this.loadPicker() },
    async loadPicker() {
      const sequence = ++this.picker.sequence; this.picker.loading = true; this.picker.error = ''
      try {
        const { data } = await procurementOptions(this.picker.kind, { keyword: this.picker.keyword, category_id: this.picker.category_id, page: this.picker.page, per_page: 20 })
        if (sequence === this.picker.sequence) { this.picker.rows = data.data; this.picker.total = data.total }
      } catch (e) { if (sequence === this.picker.sequence) this.picker.error = e.userMessage || e.message }
      finally { if (sequence === this.picker.sequence) this.picker.loading = false }
    },
    select(row) {
      if (this.picker.kind === 'orders') this.picker.current = row
      else if (this.picker.selected[row.id]) this.$delete(this.picker.selected, row.id)
      else if (Object.keys(this.picker.selected).length < 100) this.$set(this.picker.selected, row.id, row)
    },
    acceptPicker() {
      if (this.picker.kind === 'orders') this.order = this.picker.current
      else {
        const previous = Object.fromEntries(this.lines.map(row => [row.id, row]))
        this.lines = Object.values(this.picker.selected).map(row => ({ ...row, ...previous[row.id], request_qty: previous[row.id] ? previous[row.id].request_qty : '' }))
      }
      this.picker.visible = false
    },
    async submit() {
      if ((!this.valid && !this.pending) || this.busy) return
      this.busy = true; this.error = ''
      try {
        const payload = { sales_order_id: this.mode === 'order' && this.order ? this.order.id : undefined, expected_date: this.expectedDate || undefined, remark: this.remark,
          items: this.lines.map(row => ({ item_id: row.id, request_qty: row.request_qty,
            ...(row.preparation_material_requirement_id
              ? { preparation_material_requirement_id: row.preparation_material_requirement_id, preparation_version: row.preparation_version, work_order_version: row.work_order_version }
              : { target_material_requirement_id: row.target_material_requirement_id }) })) }
        const result = await materialWrite('procurement-new', payload, body => createMaterialProcurement(body))
        this.visible = false; this.$message.success(`${result.recoveredCommand ? '已确认上次提交：' : '已提交采购需求：'}${result.data.data.request_no}`); this.$emit('submitted', result.data.data)
      } catch (e) { this.error = e.userMessage || '提交结果尚未确认，请重试确认上次提交。' }
      finally { this.busy = false; this.pending = !!pendingMaterialWrite('procurement-new') }
    }
  }
}
</script>
<style scoped>
.procurement-picker-layout { display: flex; gap: 16px; min-width: 0; }
.procurement-categories { flex: 0 0 160px; max-height: 500px; overflow: auto; }
.procurement-categories .el-button { width: 100%; margin: 0 0 8px; white-space: normal; text-align: left; }
.procurement-picker-main { flex: 1; min-width: 0; }
@media (max-width: 600px) { .procurement-picker-layout { gap: 8px; } .procurement-categories { flex-basis: 95px; } }
</style>
