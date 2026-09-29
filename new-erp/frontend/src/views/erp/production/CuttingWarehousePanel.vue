<template>
  <section v-loading="loading" class="receipt-panel">
    <div class="receipt-heading"><h3>下料产出入库</h3><el-button size="small" @click="load(1)">刷新</el-button></div>
    <el-alert v-if="pending" title="上次入库结果尚未确认，请继续原操作。" type="warning" :closable="false" />
    <el-button v-if="pending && $can('production.cutting.warehouse')" type="success" :loading="busy" @click="retry">继续上次入库</el-button>
    <el-table :data="rows" border empty-text="暂无待入库产出">
      <el-table-column prop="item_code" label="物料编码" min-width="135" />
      <el-table-column prop="item_name" label="产出物料" min-width="165" />
      <el-table-column prop="source" label="来源材料" min-width="140" />
      <el-table-column prop="quantity" label="指定数量" width="115" />
      <el-table-column prop="warehoused_qty" label="已入库" width="115" />
      <el-table-column prop="display_status" label="状态" min-width="115" />
      <el-table-column label="操作" width="140"><template slot-scope="{row}"><el-button v-if="$can('production.cutting.warehouse') && ['WAIT_WAREHOUSE','PART_WAREHOUSED'].includes(row.status)" type="text" :disabled="busy || !!pending" @click="open(row)">办理入库</el-button><span v-else>—</span></template></el-table-column>
    </el-table>
    <el-pagination :current-page="page" :page-size="10" :total="total" layout="total, prev, pager, next" @current-change="load" />
    <el-dialog title="下料产出入库" :visible.sync="showForm" width="600px" custom-class="remnant-dialog" append-to-body :close-on-click-modal="false" :close-on-press-escape="!busy" :show-close="!busy" :before-close="closeForm">
      <el-form class="receipt-form" label-position="top" :disabled="busy || !!pending">
        <el-form-item label="产出物料">{{ selected.item_code }} / {{ selected.item_name }}</el-form-item>
        <el-form-item label="本次入库数量"><el-input v-model.trim="form.quantity" inputmode="decimal" /></el-form-item>
        <el-form-item label="仓库 / 库位"><el-button class="locator-button" @click="openLocator">{{ locatorLabel || '选择仓库和库位' }}</el-button></el-form-item>
        <el-form-item label="入库批次"><el-input v-model.trim="form.batch_no" maxlength="80" /></el-form-item>
      </el-form>
      <div class="receipt-actions"><el-button :disabled="busy" @click="showForm=false">关闭</el-button><el-button type="success" :loading="busy" @click="submit">{{ pending ? '继续上次入库' : '确认入库' }}</el-button></div>
    </el-dialog>
    <div v-if="showLocator" class="locator-mask" @click.self="showLocator=false">
      <section class="locator-dialog">
        <div class="receipt-heading"><h3>选择仓库和库位</h3><el-button type="text" @click="showLocator=false">关闭</el-button></div>
        <div class="locator-columns">
          <section class="warehouses"><el-input v-model="warehouseKeyword" placeholder="搜索仓库编码 / 名称" @keyup.enter.native="loadWarehouses(1)"><el-button slot="append" icon="el-icon-search" @click="loadWarehouses(1)" /></el-input>
            <div class="locator-scroll" v-loading="warehouseLoading"><button v-for="row in warehouses" :key="row.id" :class="{selected: warehouse.id === row.id}" @click="chooseWarehouse(row)">{{ row.code }} / {{ row.name }}</button><p v-if="!warehouses.length">暂无仓库</p></div>
            <el-pagination small :current-page="warehousePage" :page-size="10" :total="warehouseTotal" layout="prev, pager, next" @current-change="loadWarehouses" />
          </section>
          <section class="locations"><el-input v-model="locationKeyword" placeholder="搜索库位编码 / 名称" @keyup.enter.native="loadLocations(1)"><el-button slot="append" icon="el-icon-search" @click="loadLocations(1)" /></el-input>
            <div class="locator-scroll" v-loading="locationLoading"><button v-for="row in locations" :key="row.id" :class="{selected: location.id === row.id}" @click="location=row">{{ row.code }} / {{ row.name }}</button><p v-if="!locations.length">{{ warehouse.id ? '暂无库位' : '请先选择仓库' }}</p></div>
            <el-pagination small :current-page="locationPage" :page-size="10" :total="locationTotal" layout="prev, pager, next" @current-change="loadLocations" />
          </section>
        </div>
        <div class="locator-confirm"><span>已选：{{ warehouse.name || '未选仓库' }} / {{ location.name || '未选库位' }}</span><el-button type="success" :disabled="!warehouse.id || !location.id" @click="confirmLocator">确认选择</el-button></div>
      </section>
    </div>
  </section>
</template>
<script>
import { getCuttingExecution, listCuttingWarehouseLocators, pendingCuttingReceipt, postCuttingWarehouseReceipt } from '../../../api/erp/cutting'
export default {
  name: 'CuttingWarehousePanel',
  props: { orderId: { type: [Number, String], required: true } },
  data: () => ({ loading: false, busy: false, rows: [], page: 1, total: 0, pending: null, selected: {}, showForm: false, form: {}, locatorLabel: '',
    showLocator: false, warehouses: [], locations: [], warehouse: {}, location: {}, warehouseKeyword: '', locationKeyword: '', warehousePage: 1, locationPage: 1, warehouseTotal: 0, locationTotal: 0, warehouseLoading: false, locationLoading: false }),
  created() { this.load(1) },
  watch: { orderId() { this.showForm = false; this.showLocator = false; this.load(1) } },
  beforeDestroy() { this.listSequence = (this.listSequence || 0) + 1; this.warehouseSequence = (this.warehouseSequence || 0) + 1; this.locationSequence = (this.locationSequence || 0) + 1 },
  methods: {
    async load(page = this.page) {
      const sequence = this.listSequence = (this.listSequence || 0) + 1
      this.loading = true
      try {
        this.pending = pendingCuttingReceipt(this.orderId)
        const { data } = await getCuttingExecution(this.orderId, { flow: 'warehouse', page, per_page: 10 })
        if (sequence !== this.listSequence) return
        const results = (data.data || data).results || {}; const meta = results.meta || {}
        this.rows = (results.data || []).flatMap(result => (result.routes || []).filter(route => route.route_type === 'WAREHOUSE' && !['CANCELLED', 'WAREHOUSED'].includes(route.status)).map(route => ({ ...route, item_code: result.item_code, item_name: result.item_name, source: result.input_physical_no || result.batch_no || '—' })))
        this.page = Number(meta.current_page || page); this.total = Number(meta.total || 0)
      } catch (error) { if (sequence === this.listSequence) this.$message.error(error.userMessage || error.message || '待入库产出加载失败') }
      finally { if (sequence === this.listSequence) this.loading = false }
    },
    open(row) {
      if (this.pending || this.busy) return
      this.selected = row; this.form = { quantity: (Number(row.quantity) - Number(row.warehoused_qty)).toFixed(8), expected_version: row.business_version, warehouse_id: null, location_id: null, batch_no: '' }
      this.locatorLabel = ''; this.warehouse = {}; this.location = {}; this.showForm = true
    },
    closeForm(done) { if (!this.busy) done() },
    openLocator() { this.showLocator = true; this.loadWarehouses(1); if (this.warehouse.id) this.loadLocations(1) },
    async loadWarehouses(page) {
      const sequence = this.warehouseSequence = (this.warehouseSequence || 0) + 1; this.warehouseLoading = true; this.warehouses = []
      try { const { data } = await listCuttingWarehouseLocators({ mode: 'warehouse', keyword: this.warehouseKeyword.trim(), page, per_page: 10 }); if (sequence !== this.warehouseSequence) return; this.warehouses = data.data || []; this.warehousePage = page; this.warehouseTotal = Number(data.meta.total) }
      catch (error) { this.$message.error(error.userMessage || '仓库加载失败') } finally { if (sequence === this.warehouseSequence) this.warehouseLoading = false }
    },
    chooseWarehouse(row) { if (row.id !== this.warehouse.id) { this.warehouse = row; this.location = {}; this.locations = []; this.locationKeyword = '' } this.loadLocations(1) },
    async loadLocations(page) {
      if (!this.warehouse.id) return
      const sequence = this.locationSequence = (this.locationSequence || 0) + 1; this.locationLoading = true; this.locations = []
      try { const { data } = await listCuttingWarehouseLocators({ mode: 'location', warehouse_id: this.warehouse.id, keyword: this.locationKeyword.trim(), page, per_page: 10 }); if (sequence !== this.locationSequence) return; this.locations = data.data || []; this.locationPage = page; this.locationTotal = Number(data.meta.total) }
      catch (error) { this.$message.error(error.userMessage || '库位加载失败') } finally { if (sequence === this.locationSequence) this.locationLoading = false }
    },
    confirmLocator() { this.form.warehouse_id = this.warehouse.id; this.form.location_id = this.location.id; this.locatorLabel = `${this.warehouse.name} / ${this.location.name}`; this.showLocator = false },
    retry() { if (this.pending) this.post(this.pending.routeId, this.pending.payload) },
    submit() {
      if (this.pending) return this.retry()
      const quantity = Number(this.form.quantity)
      if (!/^\d+(\.\d{1,8})?$/.test(this.form.quantity) || quantity <= 0 || quantity > Number(this.selected.quantity) - Number(this.selected.warehoused_qty)) return this.$message.error('请输入有效的本次入库数量')
      if (!this.form.warehouse_id || !this.form.location_id || !this.form.batch_no) return this.$message.error('请选择仓库、库位并填写批次')
      return this.post(this.selected.id, this.form)
    },
    async post(id, payload) {
      if (this.busy) return
      this.busy = true
      try { await postCuttingWarehouseReceipt(this.orderId, id, payload); this.showForm = false; this.$message.success('下料产出已正式入库'); this.$emit('posted') }
      catch (error) { this.$message.error(error.userMessage || error.message || '入库失败，请重试') }
      finally { this.busy = false; this.pending = pendingCuttingReceipt(this.orderId); if (!this.pending) this.showForm = false; await this.load(this.page) }
    }
  }
}
</script>
<style scoped>
.receipt-panel { min-width: 0; }.receipt-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 14px; }.receipt-heading h3 { margin: 0; font-size: 16px; }.el-pagination { margin-top: 14px; max-width: 100%; overflow-x: auto; }.receipt-form { padding: 20px; }.receipt-actions { display: flex; justify-content: flex-end; gap: 12px; padding: 0 20px 24px; }.locator-button { width: 100%; white-space: normal; line-height: 1.5; }.locator-mask { position: fixed; inset: 0; padding: 24px; background: rgba(0,0,0,.35); z-index: 3000; display: flex; justify-content: center; align-items: center; }.locator-dialog { box-sizing: border-box; width: min(900px, 100%); max-height: 88vh; background: #fff; border-radius: 8px; padding: 20px; display: flex; flex-direction: column; }.locator-columns { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 2fr); gap: 16px; min-height: 0; }.warehouses,.locations { min-width: 0; }.locator-scroll { height: 45vh; overflow-y: auto; margin-top: 12px; }.locator-scroll button { display: block; width: 100%; background: #fff; border: 1px solid #e6ebf0; padding: 12px; text-align: left; cursor: pointer; overflow-wrap: anywhere; }.locator-scroll button.selected { color: #07804e; background: #eaf8f0; }.locator-confirm { display: flex; align-items: center; gap: 14px; justify-content: space-between; margin-top: 20px; }.locator-confirm span { overflow-wrap: anywhere; min-width: 0; }
@media (max-width: 600px) { .locator-mask { padding: 12px; }.locator-dialog { padding: 12px; }.locator-columns { grid-template-columns: minmax(0, 1fr) minmax(0, 1.5fr); gap: 8px; }.locator-confirm { flex-wrap: wrap; } }
</style>
