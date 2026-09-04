<template>
  <section class="wl-page" :class="{ 'drawer-open': drawerVisible }">
    <div class="wl-workspace">
      <div class="wl-head">
        <h1>仓库与库位</h1>
        <p>左侧维护仓库，右侧维护当前仓库下的库位基础档案。</p>
      </div>
      <div class="wl-grid">
        <aside class="warehouse-card">
          <div class="card-actions">
            <el-button size="small" type="success" icon="el-icon-plus" @click="openWarehouseCreate">新增仓库</el-button>
          </div>
          <el-table :data="warehouses" size="mini" :row-class-name="warehouseRowClass" @row-click="selectWarehouse">
            <el-table-column prop="warehouse_code" label="仓库编码" min-width="82" />
            <el-table-column prop="warehouse_name" label="仓库名称" min-width="92" />
            <el-table-column label="仓库类型" min-width="82"><template slot-scope="{ row }">{{ whTypeText(row.warehouse_type) }}</template></el-table-column>
            <el-table-column label="库位数量" width="74" align="center"><template slot-scope="{ row }">{{ locationCount(row.id) }}</template></el-table-column>
            <el-table-column prop="manager" label="负责人" width="70" />
            <el-table-column label="状态" width="70"><template slot-scope="{ row }"><span class="wl-dot" :class="row.status">{{ statusText(row.status) }}</span></template></el-table-column>
            <el-table-column label="操作" width="158"><template slot-scope="{ row }"><el-button type="text" size="mini" @click.stop="editWarehouse(row)">编辑</el-button><el-button type="text" size="mini" :class="row.status==='enabled'?'danger-link':'success-link'" @click.stop="toggleWarehouse(row)">{{ row.status==='enabled'?'停用':'启用' }}</el-button><el-button v-if="row.status!=='enabled'" type="text" size="mini" class="danger-link" @click.stop="deleteWarehouse(row)">删除</el-button></template></el-table-column>
          </el-table>
          <div class="pager">共 {{ warehouses.length }} 条</div>
        </aside>

        <main class="location-card">
          <div class="current-wh">
            <h2>{{ selectedWarehouse.warehouse_name || '请选择仓库' }} · 库位</h2>
            <div class="wh-info">
              <span>仓库编码 <b>{{ selectedWarehouse.warehouse_code || '-' }}</b></span>
              <span>仓库名称 <b>{{ selectedWarehouse.warehouse_name || '-' }}</b></span>
              <span>仓库类型 <b>{{ whTypeText(selectedWarehouse.warehouse_type) }}</b></span>
              <span>负责人 <b>{{ selectedWarehouse.manager || '-' }}</b></span>
              <span>状态 <b><span class="wl-dot" :class="selectedWarehouse.status">{{ statusText(selectedWarehouse.status) }}</span></b></span>
              <span>旧系统映射 <el-tag size="mini" type="success" effect="plain">已映射</el-tag></span>
            </div>
            <div class="loc-actions">
              <el-button size="small" type="success" icon="el-icon-plus" :disabled="!selectedWarehouse.id" @click="openLocationCreate">新增库位</el-button>
            </div>
          </div>
          <div class="loc-filter">
            <el-input v-model="filters.keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="库位编码/名称" />
            <el-select v-model="filters.area" size="small" clearable placeholder="区域">
              <el-option v-for="a in areaOptions" :key="a" :label="a" :value="a" />
            </el-select>
            <el-select v-model="filters.status" size="small" clearable placeholder="状态">
              <el-option label="启用" value="enabled" />
              <el-option label="停用" value="disabled" />
            </el-select>
            <el-button size="small" @click="reset">重置</el-button>
          </div>
          <el-alert v-if="!selectedWarehouse.id" title="请先新增仓库，再维护库位" type="warning" :closable="false" show-icon class="empty-guide" />
          <el-table v-else :data="filteredLocations" size="mini" border :row-class-name="locationRowClass" @row-click="openLocationEdit">
            <el-table-column prop="location_code" label="库位编码" min-width="86" />
            <el-table-column prop="location_name" label="库位名称" min-width="110" />
            <el-table-column prop="area" label="区域" width="68" />
            <el-table-column prop="aisle" label="通道" width="68" />
            <el-table-column prop="rack" label="货架" width="68" />
            <el-table-column prop="level" label="层位" width="68" />
            <el-table-column prop="standard_capacity" label="标准容量" width="92" align="right" />
            <el-table-column label="是否混放" width="82"><template slot-scope="{ row }">{{ row.allow_mixed ? '是' : '否' }}</template></el-table-column>
            <el-table-column label="状态" width="76"><template slot-scope="{ row }"><span class="wl-dot" :class="row.status">{{ statusText(row.status) }}</span></template></el-table-column>
            <el-table-column label="操作" width="158"><template slot-scope="{ row }"><el-button type="text" size="mini" @click.stop="openLocationEdit(row)">编辑</el-button><el-button type="text" size="mini" :class="row.status==='enabled'?'danger-link':'success-link'" @click.stop="toggleLocation(row)">{{ row.status==='enabled'?'停用':'启用' }}</el-button><el-button v-if="row.status!=='enabled'" type="text" size="mini" class="danger-link" @click.stop="deleteLocation(row)">删除</el-button></template></el-table-column>
          </el-table>
          <div class="pager">共 {{ filteredLocations.length }} 条</div>
        </main>
      </div>
    </div>

    <aside v-if="drawerVisible" class="wl-drawer">
      <div class="drawer-head"><h2>{{ drawerTitle }}</h2><i class="el-icon-close" @click="drawerVisible=false" /></div>
      <el-form ref="form" :model="form" :rules="rules" label-position="top" size="small" class="drawer-body">
        <template v-if="drawerKind==='warehouse'">
          <el-form-item label="仓库编码" prop="warehouse_code"><el-input v-model="form.warehouse_code" :disabled="!!form.id" /></el-form-item>
          <el-form-item label="仓库名称" prop="warehouse_name"><el-input v-model="form.warehouse_name" /></el-form-item>
          <el-form-item label="仓库类型"><el-select v-model="form.warehouse_type" class="full"><el-option label="综合仓" value="general" /><el-option label="原材料仓" value="raw_material" /><el-option label="成品仓" value="finished_goods" /><el-option label="包材仓" value="packaging" /><el-option label="不良品仓" value="defective" /></el-select></el-form-item>
          <el-form-item label="负责人"><el-input v-model="form.manager" /></el-form-item>
        </template>
        <template v-else>
          <el-form-item label="所属仓库"><el-input :value="`${selectedWarehouse.warehouse_code || ''} ${selectedWarehouse.warehouse_name || ''}`" disabled /></el-form-item>
          <el-form-item label="库位编码" prop="location_code"><el-input v-model="form.location_code" :disabled="!!form.id" /></el-form-item>
          <el-form-item label="库位名称" prop="location_name"><el-input v-model="form.location_name" /></el-form-item>
          <el-form-item label="区域"><el-input v-model="form.area" /></el-form-item>
          <el-form-item label="通道"><el-input v-model="form.aisle" /></el-form-item>
          <el-form-item label="货架"><el-input v-model="form.rack" /></el-form-item>
          <el-form-item label="层位"><el-input v-model="form.level" /></el-form-item>
          <el-form-item label="标准容量"><el-input-number v-model="form.standard_capacity" :min="0" controls-position="right" /></el-form-item>
          <el-form-item label="是否允许混放"><el-switch v-model="form.allow_mixed" active-color="#07883f" /></el-form-item>
          <div class="save-note">保存后更新库位基础信息，不影响历史库存数据与出入库记录。</div>
        </template>
        <el-form-item label="状态"><el-radio-group v-model="form.status"><el-radio label="enabled">启用</el-radio><el-radio label="disabled">停用</el-radio></el-radio-group></el-form-item>
        <el-form-item label="备注"><el-input v-model="form.remark" type="textarea" :rows="3" /></el-form-item>
      </el-form>
      <div class="drawer-footer"><el-button size="small" @click="drawerVisible=false">取消</el-button><el-button size="small" type="success" :loading="saving" @click="save">保存</el-button></div>
    </aside>
  </section>
</template>

<script>
import { deleteEntity, disableEntity, enableEntity, listEntity, saveEntity } from '../../../api/erp/master'
const emptyWh = () => ({ id: null, warehouse_code: '', warehouse_name: '', warehouse_type: 'general', manager: '', status: 'enabled', remark: '' })
const emptyLoc = () => ({ id: null, warehouse_id: null, location_code: '', location_name: '', area: '', aisle: '', rack: '', level: '', standard_capacity: 0, allow_mixed: false, status: 'enabled', remark: '' })
export default {
  name: 'WarehouseLocationBoard',
  data() {
    return {
      saving: false,
      drawerVisible: false,
      drawerKind: 'location',
      warehouses: [],
      locations: [],
      selectedWarehouse: {},
      selectedLocation: {},
      form: emptyLoc(),
      filters: { keyword: '', area: '', status: '' },
      rules: {
        warehouse_code: [{ required: true, message: '请输入仓库编码', trigger: 'blur' }],
        warehouse_name: [{ required: true, message: '请输入仓库名称', trigger: 'blur' }],
        location_code: [{ required: true, message: '请输入库位编码', trigger: 'blur' }],
        location_name: [{ required: true, message: '请输入库位名称', trigger: 'blur' }]
      }
    }
  },
  computed: {
    drawerTitle() { return this.drawerKind === 'warehouse' ? (this.form.id ? '编辑仓库' : '新增仓库') : (this.form.id ? '编辑库位' : '新增库位') },
    currentLocations() { return this.locations.filter(l => Number(l.warehouse_id) === Number(this.selectedWarehouse.id)) },
    filteredLocations() {
      const kw = this.filters.keyword.trim().toLowerCase()
      return this.currentLocations.filter(l => (!kw || `${l.location_code || ''}${l.location_name || ''}`.toLowerCase().includes(kw)) && (!this.filters.area || l.area === this.filters.area) && (!this.filters.status || l.status === this.filters.status))
    },
    areaOptions() { return [...new Set(this.currentLocations.map(l => l.area).filter(Boolean))] }
  },
  created() { this.fetchAll() },
  methods: {
    async fetchAll() {
      try {
        const [w, l] = await Promise.all([listEntity('warehouses', { per_page: 100 }), listEntity('locations', { per_page: 100 })])
        this.warehouses = w.data.data || []
        this.locations = l.data.data || []
        if (!this.selectedWarehouse.id && this.warehouses.length) this.selectWarehouse(this.warehouses[0])
        if (!this.warehouses.length) { this.selectedWarehouse = {}; this.selectedLocation = {}; this.drawerVisible = false }
        else if (this.selectedWarehouse.id) this.selectedWarehouse = this.warehouses.find(x => x.id === this.selectedWarehouse.id) || this.selectedWarehouse
      } catch (e) { this.$message.error(e.userMessage || '仓库库位加载失败') }
    },
    selectWarehouse(row) {
      this.selectedWarehouse = { ...row }
      const first = this.locations.find(l => Number(l.warehouse_id) === Number(row.id))
      if (first) this.openLocationEdit(first)
      else { this.selectedLocation = {}; this.drawerVisible = false; this.form = { ...emptyLoc(), warehouse_id: row.id } }
    },
    reset() { this.filters = { keyword: '', area: '', status: '' } },
    openWarehouseCreate() { this.drawerKind = 'warehouse'; this.form = emptyWh(); this.drawerVisible = true },
    editWarehouse(row) { this.selectedWarehouse = { ...row }; this.drawerKind = 'warehouse'; this.form = { ...emptyWh(), ...row }; this.drawerVisible = true },
    openLocationCreate() { if (!this.selectedWarehouse.id) return this.$message.warning('请先选择仓库'); this.drawerKind = 'location'; this.form = { ...emptyLoc(), warehouse_id: this.selectedWarehouse.id }; this.drawerVisible = true },
    openLocationEdit(row) { this.selectedLocation = { ...row }; this.drawerKind = 'location'; this.form = { ...emptyLoc(), ...row, allow_mixed: !!row.allow_mixed, standard_capacity: Number(row.standard_capacity || 0) }; this.drawerVisible = true },
    save() {
      this.$refs.form.validate(async ok => {
        if (!ok) return
        this.saving = true
        try { await saveEntity(this.drawerKind === 'warehouse' ? 'warehouses' : 'locations', this.form); this.$message.success('保存成功'); this.drawerVisible = false; await this.fetchAll() } catch (e) { this.$message.error(e.userMessage || '保存失败') } finally { this.saving = false }
      })
    },
    async toggleWarehouse(row) { const enabling=row.status!=='enabled'; try { await this.$confirm(enabling?'确认启用该仓库？':'确认停用该仓库？','仓库状态',{type:'warning'}); await(enabling?enableEntity:disableEntity)('warehouses',row.id); this.$message.success(enabling?'仓库已启用':'仓库已停用'); await this.fetchAll() } catch(e){ if(e!=='cancel'&&e!=='close')this.$message.error(e.userMessage||'仓库状态更新失败') } },
    async deleteWarehouse(row) { try { await this.$confirm(`确认删除仓库 ${row.warehouse_code} / ${row.warehouse_name}？有库位、库存或业务记录时系统会阻止删除。`,'删除仓库',{type:'warning',confirmButtonText:'确认删除'}); await deleteEntity('warehouses',row.id); this.$message.success('仓库已删除'); this.selectedWarehouse={}; await this.fetchAll() } catch(e){ if(e!=='cancel'&&e!=='close')this.$message.error(e.userMessage||'仓库删除失败') } },
    async toggleLocation(row) { const enabling=row.status!=='enabled'; try { await this.$confirm(enabling?'确认启用该库位？':'确认停用该库位？','库位状态',{type:'warning'}); await(enabling?enableEntity:disableEntity)('locations',row.id); this.$message.success(enabling?'库位已启用':'库位已停用'); await this.fetchAll() } catch(e){ if(e!=='cancel'&&e!=='close')this.$message.error(e.userMessage||'库位状态更新失败') } },
    async deleteLocation(row) { try { await this.$confirm(`确认删除库位 ${row.location_code} / ${row.location_name}？有库存或业务记录时系统会阻止删除。`,'删除库位',{type:'warning',confirmButtonText:'确认删除'}); await deleteEntity('locations',row.id); this.$message.success('库位已删除'); this.selectedLocation={}; this.drawerVisible=false; await this.fetchAll() } catch(e){ if(e!=='cancel'&&e!=='close')this.$message.error(e.userMessage||'库位删除失败') } },
    locationCount(id) { return this.locations.filter(l => Number(l.warehouse_id) === Number(id)).length },
    warehouseRowClass({ row }) { return row.id === this.selectedWarehouse.id ? 'selected-row' : '' },
    locationRowClass({ row }) { return row.id === this.selectedLocation.id ? 'selected-row' : '' },
    whTypeText(v) { return ({ general: '综合仓', raw_material: '原材料仓', finished_goods: '成品仓', packaging: '包材仓', defective: '不良品仓' })[v] || v || '-' },
    statusText(v) { return v === 'disabled' ? '停用' : '启用' }
  }
}
</script>

<style scoped>
.wl-page{position:relative;min-height:calc(100vh - 52px);background:#f7f8f9}.wl-workspace{padding:16px;transition:padding-right .18s}.wl-page.drawer-open .wl-workspace{padding-right:300px}.wl-head{height:72px}.wl-head h1{margin:0 0 5px;font-size:18px}.wl-head p{margin:0;color:#7a858e;font-size:11px}.wl-grid{display:grid;grid-template-columns:350px 1fr;gap:10px}.warehouse-card,.location-card{background:#fff;border:1px solid #dfe5e9;border-radius:4px;overflow:hidden}.card-actions{height:54px;padding:12px;border-bottom:1px solid #e4e9ed}.pager{height:44px;padding:0 12px;display:flex;align-items:center;color:#69747d}.current-wh{position:relative;padding:16px 12px 10px;border-bottom:1px solid #e4e9ed}.current-wh h2{margin:0 0 12px;font-size:16px}.wh-info{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;border:1px solid #e4e9ed;padding:12px}.wh-info span{display:grid;color:#7a858e}.wh-info b{margin-top:6px;color:#2d3842}.loc-actions{position:absolute;right:12px;top:12px;display:flex;gap:10px}.loc-filter{height:58px;padding:10px 12px;display:flex;align-items:center;gap:8px}.loc-filter .el-input{width:240px}.loc-filter .el-select{width:136px}.wl-dot{display:inline-flex;align-items:center;gap:6px}.wl-dot:before{content:'';width:6px;height:6px;border-radius:50%;background:#07883f}.wl-dot.disabled:before{background:#9aa3ac}::v-deep .selected-row td{background:#edf8f1!important}::v-deep .selected-row td:first-child{box-shadow:inset 3px 0 0 #07883f}.wl-drawer{position:fixed;top:52px;right:0;bottom:0;width:280px;background:#fff;border-left:1px solid #dfe5e9;z-index:9;box-shadow:-8px 0 24px rgba(25,43,58,.08)}.drawer-head{height:58px;padding:0 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e7eb}.drawer-head h2{font-size:16px}.drawer-body{height:calc(100% - 122px);padding:14px 16px 84px;overflow:auto}.drawer-body .full{width:100%}.save-note{padding:10px;border:1px solid #b8d6ff;border-radius:4px;background:#f2f7ff;color:#42648c}.drawer-footer{position:absolute;left:0;right:0;bottom:0;height:64px;padding:12px;display:grid;grid-template-columns:1fr 1fr;gap:12px;background:#fff;border-top:1px solid #e2e7eb}.danger-link{color:#e04444!important}@media(max-width:1180px){.wl-page.drawer-open .wl-workspace{padding-right:16px}.wl-grid{grid-template-columns:1fr}.wl-drawer{width:360px}}
.empty-guide{margin:12px}
</style>
