<template>
  <el-dialog title="选择仓库和库位" :visible.sync="visible" width="1090px" custom-class="remnant-dialog remnant-locator" append-to-body :close-on-click-modal="false" @closed="invalidate">
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <div class="rwl-columns">
      <section class="rwl-warehouses">
        <h3>仓库</h3>
        <el-input v-model="warehouseKeyword" placeholder="仓库编码 / 名称" clearable @keyup.enter.native="loadWarehouses(1)"><el-button slot="append" icon="el-icon-search" aria-label="搜索仓库" @click="loadWarehouses(1)" /></el-input>
        <div v-loading="warehouseLoading" class="rwl-list">
          <button v-for="row in warehouses" :key="row.id" type="button" :class="{ selected: warehouse.id === row.id }" @click="chooseWarehouse(row)">
            <el-radio :value="warehouse.id" :label="row.id" @change="chooseWarehouse(row)"><span class="rwl-name">{{ row.code }}<br>{{ row.name }}</span></el-radio>
          </button>
          <p v-if="!warehouseLoading && !warehouses.length" class="rwl-empty">暂无仓库</p>
        </div>
        <el-pagination small :current-page="warehousePage" :page-size="10" :total="warehouseTotal" layout="total, prev, pager, next" @current-change="loadWarehouses" />
      </section>
      <section class="rwl-locations">
        <h3>库位</h3>
        <div class="rwl-search"><el-input v-model="locationKeyword" placeholder="库位编码 / 名称" clearable @keyup.enter.native="loadLocations(1)" /><el-button type="success" @click="loadLocations(1)">查询</el-button><el-button @click="resetLocations">重置</el-button></div>
        <div class="rwl-location-table"><el-table v-loading="locationLoading" :data="locations" border :empty-text="warehouse.id ? '暂无库位' : '请先选择仓库'" @row-click="chooseLocation">
          <el-table-column width="48"><template slot-scope="{row}"><el-radio :value="location.id" :label="row.id" :aria-label="row.name" @change="chooseLocation(row)"><span class="rwl-sr">{{ row.name }}</span></el-radio></template></el-table-column>
          <el-table-column prop="code" label="库位编码" min-width="150" /><el-table-column prop="name" label="库位名称" min-width="150" />
          <el-table-column label="区域" min-width="100"><template slot-scope="{row}">{{ areaLabel(row.area) }}</template></el-table-column><el-table-column label="状态" width="85"><template><span class="rwl-enabled">启用</span></template></el-table-column>
        </el-table></div>
        <el-pagination :current-page="locationPage" :page-size="10" :total="locationTotal" layout="total, prev, pager, next" @current-change="loadLocations" />
      </section>
    </div>
    <div slot="footer" class="rwl-footer"><span>已选：{{ warehouse.name || '未选仓库' }} / {{ location.name || '未选库位' }}</span><div><el-button @click="visible=false">返回</el-button><el-button type="success" :disabled="!warehouse.id || !location.id || warehouseLoading || locationLoading" @click="confirm">确认选择</el-button></div></div>
  </el-dialog>
</template>
<script>
import { listCuttingWarehouseLocators } from '../../../api/erp/cutting'
export default {
  data: () => ({ visible: false, error: '', warehouses: [], locations: [], warehouse: {}, location: {}, warehouseKeyword: '', locationKeyword: '', warehousePage: 1, locationPage: 1, warehouseTotal: 0, locationTotal: 0, warehouseLoading: false, locationLoading: false }),
  beforeDestroy() { this.invalidate() },
  methods: {
    invalidate() { this.warehouseSequence = (this.warehouseSequence || 0) + 1; this.locationSequence = (this.locationSequence || 0) + 1 },
    open(selected = {}) { this.invalidate(); this.warehouse = { ...(selected.warehouse || {}) }; this.location = { ...(selected.location || {}) }; this.warehouseKeyword = ''; this.locationKeyword = ''; this.error = ''; this.locations = []; this.locationTotal = 0; this.visible = true; this.loadWarehouses(1); if (this.warehouse.id) this.loadLocations(1) },
    areaLabel(value) { return ({ raw: '原料区', finished: '成品区' })[value] || value || '—' },
    async loadWarehouses(page) {
      const sequence = this.warehouseSequence = (this.warehouseSequence || 0) + 1; this.warehouseLoading = true; this.error = ''; this.warehouses = []
      try { const { data } = await listCuttingWarehouseLocators({ mode: 'warehouse', keyword: this.warehouseKeyword.trim(), page, per_page: 10 }); if (sequence !== this.warehouseSequence) return; this.warehouses = data.data; this.warehousePage = page; this.warehouseTotal = Number(data.meta.total) }
      catch (e) { if (sequence === this.warehouseSequence) this.error = e.userMessage || '仓库加载失败' } finally { if (sequence === this.warehouseSequence) this.warehouseLoading = false }
    },
    chooseWarehouse(row) { if (this.warehouse.id !== row.id) { this.warehouse = { ...row }; this.location = {}; this.locationKeyword = '' } this.loadLocations(1) },
    chooseLocation(row) { this.location = { ...row } },
    resetLocations() { this.locationKeyword = ''; this.loadLocations(1) },
    async loadLocations(page) {
      const sequence = this.locationSequence = (this.locationSequence || 0) + 1; this.locations = []; this.locationTotal = 0; this.locationPage = page
      if (!this.warehouse.id) { this.locationLoading = false; return }
      this.locationLoading = true; this.error = ''
      try { const { data } = await listCuttingWarehouseLocators({ mode: 'location', warehouse_id: this.warehouse.id, keyword: this.locationKeyword.trim(), page, per_page: 10 }); if (sequence !== this.locationSequence) return; this.locations = data.data; this.locationTotal = Number(data.meta.total) }
      catch (e) { if (sequence === this.locationSequence) this.error = e.userMessage || '库位加载失败' } finally { if (sequence === this.locationSequence) this.locationLoading = false }
    },
    confirm() { if (!this.warehouse.id || !this.location.id || this.locationLoading || this.warehouseLoading) return; this.$emit('selected', { warehouse: { ...this.warehouse }, location: { ...this.location } }); this.visible = false }
  }
}
</script>
<style scoped>
.rwl-location-table ::v-deep .el-radio__label{display:none}
.rwl-columns{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2.2fr);gap:26px;min-width:0}.rwl-columns h3{font-size:19px;margin:0 0 18px}.rwl-warehouses{padding-right:26px;border-right:1px solid #e0e7f0;min-width:0}.rwl-locations{min-width:0}.rwl-list{height:318px;margin-top:16px;border:1px solid #e1e7ef;border-radius:3px;overflow:auto}.rwl-list button{width:100%;border:0;background:#fff;text-align:left;padding:16px;cursor:pointer}.rwl-list button.selected{background:#e4f4ec}.rwl-list .el-radio{display:flex;align-items:center;white-space:normal;line-height:1.7;margin:0}.rwl-name{overflow-wrap:anywhere;display:block}.rwl-search{display:flex;gap:12px}.rwl-search .el-button{margin:0}.rwl-location-table{margin-top:18px;min-height:318px;overflow:auto}.rwl-location-table ::v-deep .cell{word-break:normal;overflow-wrap:anywhere}.rwl-enabled{color:#008453}.rwl-empty{text-align:center;color:#7b8ba0;padding:30px 8px}.rwl-footer{display:flex;align-items:center;justify-content:space-between;gap:16px;text-align:left}.rwl-footer>span{min-width:0;overflow-wrap:anywhere}.rwl-footer>div{display:flex;gap:12px}.rwl-footer .el-button{margin:0}.el-pagination{margin-top:14px;max-width:100%;overflow:auto}.rwl-sr{position:absolute;clip:rect(0,0,0,0);width:1px;height:1px;overflow:hidden}
@media(max-width:700px){.rwl-columns{grid-template-columns:minmax(0,1fr);gap:16px}.rwl-warehouses{padding:0;border-right:0}.rwl-list{height:125px}.rwl-location-table{min-height:160px;max-height:260px}.rwl-search{flex-wrap:wrap;gap:8px}.rwl-footer{flex-wrap:wrap}.rwl-columns h3{font-size:16px;margin-bottom:10px}}
</style>
