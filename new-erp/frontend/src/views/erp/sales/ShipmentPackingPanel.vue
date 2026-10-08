<template>
  <section class="packing-panel" v-loading="loading">
    <div class="packing-head">
      <h3>包装作业</h3>
      <div class="packing-actions">
        <el-button v-if="editable && $can('sales_order.shipment.packing.configure')" size="small" type="success" icon="el-icon-setting" :disabled="busy" @click="openPlan">{{ pendingPlan ? '查询原方案结果' : '设置包装及包裹' }}</el-button>
        <el-button size="small" icon="el-icon-refresh" :disabled="busy" @click="load">刷新</el-button>
      </div>
    </div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-table :data="operations" border size="small" class="packing-table">
      <el-table-column prop="package_no" label="包裹编号" min-width="160" />
      <el-table-column prop="operation_name" label="工序" min-width="110"><template slot-scope="{ row }"><div>{{ row.operation_name }}</div><el-tag v-if="isPublicSnapshot(row.is_public_snapshot)" size="mini" type="success">公共工序</el-tag></template></el-table-column>
      <el-table-column label="产品及数量" min-width="175"><template slot-scope="{ row }"><div v-for="content in row.contents" :key="content.id">{{ content.product_name || content.item_name }} · {{ qty(content.base_qty) }} · {{ content.packaging_scheme_name }}</div></template></el-table-column>
      <el-table-column label="接单人" min-width="100"><template slot-scope="{ row }">{{ ownerName(row) }}</template></el-table-column>
      <el-table-column label="状态" width="100"><template slot-scope="{ row }"><el-tag size="mini" :type="row.status === 'COMPLETED' ? 'success' : 'warning'">{{ statusText(row.status) }}</el-tag></template></el-table-column>
      <el-table-column label="操作" min-width="145"><template slot-scope="{ row }"><el-button type="text" icon="el-icon-view" @click="openOperation(row)">查看作业</el-button><el-button v-if="row.allowed_actions.claim && $can('sales_order.shipment.packing.execute')" type="text" :disabled="busy" @click="execute(row, 'claim')">接单</el-button></template></el-table-column>
    </el-table>
    <div class="packing-pager"><el-pagination background :current-page="page" :page-size="20" :total="total" layout="total, prev, pager, next" @current-change="changePage" /></div>

    <el-dialog title="设置包装及包裹" :visible.sync="planOpen" append-to-body width="min(1100px, calc(100vw - 32px))" top="5vh" custom-class="shipment-packing-dialog" :close-on-click-modal="false">
      <div v-if="pendingPlan" class="packing-notice">上次保存结果待确认，当前内容已锁定。</div>
      <div v-for="(parcel, parcelIndex) in planPackages" :key="parcel.uid" class="packing-parcel">
        <div class="packing-parcel-head"><b>包裹 {{ parcelIndex + 1 }}</b><el-button type="text" class="packing-danger" :disabled="busy || pendingPlan" @click="planPackages.splice(parcelIndex, 1)">移除包裹</el-button></div>
        <div class="packing-grid">
          <label>包裹编号<el-input v-model.trim="parcel.package_no" size="small" :disabled="busy || pendingPlan" /></label>
          <label>承运商<el-input v-model.trim="parcel.carrier_name" size="small" :disabled="busy || pendingPlan" /></label>
          <label>运单号<el-input v-model.trim="parcel.tracking_no" size="small" :disabled="busy || pendingPlan" /></label>
        </div>
        <div class="packing-table-scroll">
          <el-table :data="parcel.contents" border size="mini">
            <el-table-column label="本次发货产品 / 来源批次" min-width="235"><template slot-scope="{ row }"><el-select v-model="row.shipment_line_id" size="small" :disabled="busy || pendingPlan" @change="resetContent(row)"><el-option v-for="line in requirements" :key="line.shipment_line_id" :value="line.shipment_line_id" :label="`${line.product_name || line.item_name} / ${line.batch_no} / ${qty(line.base_qty)}`" /></el-select></template></el-table-column>
            <el-table-column label="包装方案" min-width="150"><template slot-scope="{ row }"><el-select v-if="requirement(row).packing_required" v-model="row.packaging_scheme_id" size="small" :disabled="busy || pendingPlan" placeholder="请选择"><el-option v-for="scheme in requirement(row).schemes" :key="scheme.id" :value="scheme.id" :label="scheme.name" /></el-select><span v-else>无需包装作业</span></template></el-table-column>
            <el-table-column label="数量" width="125"><template slot-scope="{ row }"><el-input-number v-model="row.base_qty" size="mini" :min="0" :max="Number(requirement(row).base_qty || 0)" :precision="8" :controls="false" :disabled="busy || pendingPlan || requirement(row).serial_tracking_mode === 'required'" /></template></el-table-column>
            <el-table-column label="设备编号 / 序列号" min-width="210"><template slot-scope="{ row }"><el-select v-if="(requirement(row).serial_ids || []).length" v-model="row.inventory_serial_ids" multiple size="small" :disabled="busy || pendingPlan" @change="changeContentSerials(row)"><el-option v-for="serial in requirement(row).serials" :key="serial.id" :value="Number(serial.id)" :label="serial.serial_no" /></el-select><span v-else>—</span></template></el-table-column>
            <el-table-column label="操作" width="70"><template slot-scope="{ $index }"><el-button type="text" class="packing-danger" :disabled="busy || pendingPlan" @click="parcel.contents.splice($index, 1)">移除</el-button></template></el-table-column>
          </el-table>
        </div>
        <el-button size="mini" icon="el-icon-plus" :disabled="busy || pendingPlan" @click="parcel.contents.push(newContent())">添加产品</el-button>
      </div>
      <el-button size="small" icon="el-icon-plus" :disabled="busy || pendingPlan" @click="addParcel">添加包裹</el-button>
      <div v-for="line in requirements" :key="line.shipment_line_id" class="packing-balance">{{ line.product_name || line.item_name }} / {{ line.batch_no }}：已分配 {{ allocated(line.shipment_line_id) }} / 本次发货 {{ qty(line.base_qty) }}</div>
      <el-alert v-if="planError" :title="planError" type="error" :closable="false" />
      <span slot="footer"><el-button size="small" :disabled="busy" @click="planOpen = false">关闭</el-button><el-button type="success" size="small" :loading="busy" @click="savePlan">{{ pendingPlan ? '查询原操作结果' : '保存包装方案' }}</el-button></span>
    </el-dialog>

    <el-dialog :title="activeOperation.operation_name || '包装作业'" :visible.sync="operationOpen" append-to-body width="min(850px, calc(100vw - 32px))" top="5vh" custom-class="shipment-packing-dialog" :close-on-click-modal="false">
      <el-tag v-if="isPublicSnapshot(activeOperation.is_public_snapshot)" size="mini" type="success">公共工序</el-tag>
      <div class="packing-grid"><div>包裹：{{ activeOperation.package_no }}</div><div>状态：{{ statusText(activeOperation.status) }}</div><div>接单人：{{ ownerName(activeOperation) }}</div></div>
      <div v-for="content in activeOperation.contents" :key="content.id" class="packing-balance">{{ content.product_name || content.item_name }} · {{ qty(content.base_qty) }} · {{ content.packaging_scheme_name }}<div v-if="content.serial_nos.length">{{ content.serial_nos.join('、') }}</div></div>
      <h4>参与人员及实际工时</h4>
      <el-table :data="activeOperation.participants || []" border size="small"><el-table-column prop="name" label="人员" /><el-table-column label="身份"><template slot-scope="{ row }">{{ row.role === 'OWNER' ? '接单人' : '协同人' }}{{ row.is_active ? '' : '（已移除）' }}</template></el-table-column><el-table-column prop="actual_labor_minutes" label="实际工时（分钟）" /><el-table-column label="计时状态"><template slot-scope="{ row }">{{ row.is_timing ? '计时中' : '已暂停' }}</template></el-table-column></el-table>
      <h4>实际用料</h4>
      <el-table :data="activeOperation.materials || []" border size="small"><el-table-column prop="item_name" label="物料" /><el-table-column prop="base_qty" label="实际耗用数量" /><el-table-column prop="posted_at" label="过账时间" /></el-table>
      <el-alert v-if="operationError" :title="operationError" type="error" :closable="false" />
      <div slot="footer" class="packing-actions">
        <el-button size="small" :disabled="busy" @click="operationOpen = false">关闭</el-button>
        <el-button v-if="pendingOperation" type="success" size="small" :loading="busy" @click="execute(activeOperation, 'recover')">查询原操作结果</el-button>
        <template v-else>
          <el-button v-if="allow('claim')" size="small" type="success" :loading="busy" @click="execute(activeOperation, 'claim')">接单</el-button>
          <el-button v-if="allow('collaborators')" size="small" :disabled="busy" @click="openPeople">选择协同人</el-button>
          <el-button v-if="allow('start')" size="small" type="success" :loading="busy" @click="execute(activeOperation, 'start')">开始我的作业</el-button>
          <el-button v-if="allow('pause')" size="small" :loading="busy" @click="execute(activeOperation, 'pause')">暂停我的作业</el-button>
          <el-button v-if="allow('materials')" size="small" :disabled="busy" @click="openMaterials">登记实际用料</el-button>
          <el-button v-if="allow('complete')" size="small" type="success" :loading="busy" @click="complete">完成工序</el-button>
          <el-button v-if="allow('inspect')" size="small" type="success" :disabled="busy" @click="inspectionOpen = true">包装质检</el-button>
        </template>
      </div>
    </el-dialog>

    <el-dialog title="选择协同人" :visible.sync="peopleOpen" append-to-body width="min(850px, calc(100vw - 32px))" top="8vh" custom-class="shipment-packing-dialog" :close-on-click-modal="false">
      <div class="packing-picker"><aside><el-tree :data="peopleTree" node-key="id" :props="{ label: 'name', children: 'children' }" default-expand-all @node-click="selectDepartment" /></aside><main>
        <div class="packing-search"><el-input v-model.trim="peopleQuery.keyword" size="small" clearable placeholder="姓名 / 账号" @keyup.enter.native="searchPeople" /><el-button size="small" type="success" @click="searchPeople">查询</el-button></div>
        <el-table :data="peopleRows" border size="small"><el-table-column width="60" label="选择"><template slot-scope="{ row }"><el-checkbox :value="Boolean(peopleSelected[row.legacy_id])" @change="togglePerson(row, $event)" /></template></el-table-column><el-table-column prop="nickname" label="姓名" /><el-table-column prop="username" label="账号" /></el-table>
        <el-pagination background :current-page="peopleQuery.page" :page-size="20" :total="peopleTotal" layout="total, prev, pager, next" @current-change="peoplePage" />
      </main></div>
      <div class="packing-selected"><el-tag v-for="person in Object.values(peopleSelected)" :key="person.legacy_id" closable @close="$delete(peopleSelected, person.legacy_id)">{{ person.nickname || person.username }}</el-tag></div>
      <div slot="footer"><el-button size="small" @click="peopleOpen = false">取消</el-button><el-button size="small" type="success" :loading="busy" @click="savePeople">确认协同人</el-button></div>
    </el-dialog>

    <el-dialog title="登记实际用料" :visible.sync="materialsOpen" append-to-body width="min(1050px, calc(100vw - 32px))" top="5vh" custom-class="shipment-packing-dialog" :close-on-click-modal="false">
      <div class="packing-requirements"><div v-for="material in activeOperation.material_requirements" :key="material.item_id">{{ material.item_name }} · 工艺用料数量 {{ qty(material.required_base_qty) }}</div></div>
      <div class="packing-picker"><aside><el-tree :data="materialTree" node-key="id" :props="{ label: 'name', children: 'children' }" default-expand-all @node-click="selectCategory" /></aside><main>
        <div class="packing-search"><el-input v-model.trim="materialQuery.keyword" size="small" clearable placeholder="物料编码 / 名称 / 规格" @keyup.enter.native="searchMaterials" /><el-button size="small" type="success" @click="searchMaterials">查询</el-button></div>
        <el-table :data="materialRows" border size="small"><el-table-column label="物料" min-width="165"><template slot-scope="{ row }">{{ row.item_code }} / {{ row.item_name }}<div>{{ row.spec }}</div></template></el-table-column><el-table-column label="库存来源" min-width="180"><template slot-scope="{ row }">{{ row.warehouse_name }} / {{ row.location_name }}<div>{{ row.batch_no }}</div></template></el-table-column><el-table-column prop="quantity_available" label="可用数量" width="95" /><el-table-column label="选择" width="85"><template slot-scope="{ row }"><el-button type="text" :disabled="Boolean(materialDrafts[row.id])" @click="addMaterial(row)">添加</el-button></template></el-table-column></el-table>
        <el-pagination background :current-page="materialQuery.page" :page-size="20" :total="materialTotal" layout="total, prev, pager, next" @current-change="materialPage" />
      </main></div>
      <h4>已选库存来源</h4>
      <div v-for="material in Object.values(materialDrafts)" :key="material.id" class="packing-material-draft"><div>{{ material.item_name }} / {{ material.batch_no }} / {{ material.unit_name }}</div><el-input-number v-model="material.base_qty" size="small" :controls="false" :min="0" :max="Number(material.quantity_available)" :precision="8" :disabled="material.physical || material.serial_tracking_mode === 'required'" /><el-button v-if="material.physical || material.serial_tracking_mode !== 'none'" size="small" @click="openIdentities(material)">{{ material.physical ? '选择实物板料' : '选择序列号' }}（{{ material.identities.length }}）</el-button><el-button type="text" class="packing-danger" @click="$delete(materialDrafts, material.id)">移除</el-button></div>
      <el-alert v-if="materialError" :title="materialError" type="error" :closable="false" />
      <span slot="footer"><el-button size="small" :disabled="busy" @click="materialsOpen = false">取消</el-button><el-button size="small" type="success" :loading="busy" @click="saveMaterials">确认实际耗用</el-button></span>
    </el-dialog>

    <el-dialog :title="identityMaterial.physical ? '选择实物板料' : '选择序列号'" :visible.sync="identityOpen" append-to-body width="min(800px, calc(100vw - 32px))" top="8vh" custom-class="shipment-packing-dialog" :close-on-click-modal="false">
      <div class="packing-search"><el-input v-model="identityKeyword" size="small" clearable placeholder="搜索实物编号 / 序列号" @keyup.enter.native="searchIdentities" @clear="searchIdentities" /><el-button type="success" size="small" @click="searchIdentities">查询</el-button></div>
      <el-table :data="identityRows" border size="small"><el-table-column label="选择" width="60"><template slot-scope="{ row }"><el-checkbox :value="Boolean(identitySelected[row.id])" @change="toggleIdentity(row, $event)" /></template></el-table-column><el-table-column label="实物编号 / 序列号"><template slot-scope="{ row }">{{ row.physical_no || row.serial_no }}</template></el-table-column><el-table-column v-if="identityMaterial.physical" label="真实尺寸（mm）"><template slot-scope="{ row }">{{ row.length_mm }} × {{ row.width_mm }} × {{ row.thickness_mm }}</template></el-table-column></el-table>
      <el-pagination background :current-page="identityPage" :page-size="20" :total="identityTotal" layout="total, prev, pager, next" @current-change="changeIdentityPage" />
      <div class="packing-selected"><el-tag v-for="row in Object.values(identitySelected)" :key="row.id" closable @close="$delete(identitySelected, row.id)">{{ row.physical_no || row.serial_no }}</el-tag></div>
      <span slot="footer"><el-button size="small" @click="identityOpen = false">取消</el-button><el-button size="small" type="success" @click="confirmIdentities">确认选择</el-button></span>
    </el-dialog>
    <el-dialog title="包装质检" :visible.sync="inspectionOpen" append-to-body width="min(560px, calc(100vw - 32px))" top="12vh" :close-on-click-modal="false">
      <el-radio-group v-model="inspection.result"><el-radio label="passed">合格</el-radio><el-radio label="failed">不合格，退回加工</el-radio></el-radio-group><el-input v-model.trim="inspection.reason" type="textarea" :rows="3" maxlength="500" placeholder="检验说明" class="packing-inspection-reason" />
      <span slot="footer"><el-button size="small" @click="inspectionOpen = false">取消</el-button><el-button size="small" type="success" :loading="busy" @click="saveInspection">确认检验结果</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { getShipmentPacking, configureShipmentPacking, getPackingOperation, actPackingOperation, listPackingPeople, listPackingMaterials, listPackingIdentities, pendingPackingCommand } from '@/api/erp/shipment-packing'

let localId = 0
const uid = () => `packing-${++localId}`
const tree = (rows, label) => {
  const nodes = rows.map(row => ({ ...row, name: row[label], children: [] }))
  const byId = Object.fromEntries(nodes.map(row => [row.id, row]))
  const roots = []
  nodes.forEach(row => { const parent = byId[row.parent_id]; if (parent && parent !== row) parent.children.push(row); else roots.push(row) })
  return [{ id: 0, name: '全部', children: roots }]
}
export default {
  name: 'ShipmentPackingPanel',
  props: { shipmentId: { type: Number, required: true }, shipmentStatus: String },
  data: () => ({ loading: false, busy: false, error: '', page: 1, total: 0, operations: [], requirements: [], packingVersion: 1, loadSequence: 0,
    planOpen: false, planPackages: [], planError: '', pendingPlan: false, operationOpen: false, activeOperation: {}, operationError: '', pendingOperation: false,
    peopleOpen: false, peopleRows: [], peopleSelected: {}, peopleTree: [], peopleTotal: 0, peopleQuery: { keyword: '', department_id: 0, page: 1 },
    materialsOpen: false, materialRows: [], materialTree: [], materialTotal: 0, materialDrafts: {}, materialError: '', materialQuery: { keyword: '', category_id: 0, page: 1 },
    identityOpen: false, identityRows: [], identitySelected: {}, identityMaterial: {}, identityPage: 1, identityTotal: 0, identityKeyword: '',
    inspectionOpen: false, inspection: { result: 'passed', reason: '' } }),
  computed: { editable () { return ['draft', 'pending_outbound', 'outbound_posted'].includes(this.shipmentStatus) } },
  watch: { shipmentId: { immediate: true, handler () { this.page = 1; this.operationOpen = false; this.planOpen = false; this.resetSelectors(); this.load() } } },
  beforeDestroy () { this.loadSequence++; this.resetSelectors() },
  methods: {
    isPublicSnapshot (value) { return value === true || value === 1 || value === '1' },
    resetSelectors () { this.operationSequence = (this.operationSequence || 0) + 1; this.peopleSequence = (this.peopleSequence || 0) + 1; this.materialSequence = (this.materialSequence || 0) + 1; this.identitySequence = (this.identitySequence || 0) + 1; this.peopleOpen = false; this.materialsOpen = false; this.identityOpen = false; this.inspectionOpen = false },
    qty (value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) },
    statusText (value) { return ({ READY: '待加工', WAITING: '等待前序', IN_PROGRESS: '加工中', WAIT_QUALITY: '待质检', COMPLETED: '已完成', CANCELLED: '已取消' })[value] || value || '—' },
    ownerName (op) { const owner = (op.participants || []).find(row => row.role === 'OWNER'); return owner ? owner.name : '未接单' },
    async load () {
      if (!this.shipmentId) return
      const sequence = ++this.loadSequence
      this.loading = true; this.error = ''
      try { const { data } = await getShipmentPacking(this.shipmentId, { page: this.page, per_page: 20 }); if (sequence !== this.loadSequence) return; const result = data.data; this.operations = result.operations.data; this.total = result.operations.total; this.requirements = result.requirements; this.packingVersion = result.packing_version; this.pendingPlan = Boolean(pendingPackingCommand('configure', this.shipmentId)) } catch (e) { if (sequence === this.loadSequence) this.error = e.userMessage || '包装作业读取失败' } finally { if (sequence === this.loadSequence) this.loading = false }
    },
    changePage (page) { this.page = page; this.load() },
    newContent (line = this.requirements[0]) { return { uid: uid(), shipment_line_id: line && line.shipment_line_id, base_qty: line ? Number(line.base_qty) : 0, packaging_scheme_id: line && line.schemes.length === 1 ? line.schemes[0].id : null, inventory_serial_ids: line ? [...line.serial_ids] : [] } },
    requirement (content) { return this.requirements.find(row => row.shipment_line_id === content.shipment_line_id) || { schemes: [], serial_ids: [], serials: [] } },
    resetContent (content) { const replacement = this.newContent(this.requirement(content)); Object.assign(content, replacement) },
    changeContentSerials (content) { if (this.requirement(content).serial_tracking_mode === 'required') content.base_qty = content.inventory_serial_ids.length },
    allocated (id) { return this.qty(this.planPackages.reduce((sum, parcel) => sum + parcel.contents.filter(row => row.shipment_line_id === id).reduce((total, row) => total + Number(row.base_qty || 0), 0), 0)) },
    addParcel () { this.planPackages.push({ uid: uid(), package_no: '', carrier_name: '', tracking_no: '', contents: [this.newContent()] }) },
    async openPlan () {
      this.planError = ''; this.planOpen = true
      try { const { data } = await getShipmentPacking(this.shipmentId, { plan: 1, per_page: 1 }); const result = data.data; this.packingVersion = result.packing_version; this.requirements = result.requirements; const pending = pendingPackingCommand('configure', this.shipmentId); this.pendingPlan = Boolean(pending); const parcels = pending ? pending.payload.packages : result.plan_packages; this.planPackages = parcels && parcels.some(row => row.contents.length) ? parcels.map(row => ({ ...row, uid: uid(), contents: row.contents.map(content => ({ ...content, uid: uid() })) })) : [{ uid: uid(), package_no: '', carrier_name: '', tracking_no: '', contents: this.requirements.map(line => this.newContent(line)) }] } catch (e) { this.planError = e.userMessage || '包装方案读取失败' }
    },
    async savePlan () {
      if (this.busy) return
      this.busy = true; this.planError = ''
      try { await configureShipmentPacking(this.shipmentId, { expected_version: this.packingVersion, packages: this.planPackages.map(parcel => ({ package_no: parcel.package_no, carrier_name: parcel.carrier_name, tracking_no: parcel.tracking_no, weight: parcel.weight, volume: parcel.volume, contents: parcel.contents.map(row => ({ shipment_line_id: row.shipment_line_id, packaging_scheme_id: row.packaging_scheme_id, base_qty: row.base_qty, inventory_serial_ids: row.inventory_serial_ids })) })) }); this.planOpen = false; this.$message.success('包装方案已保存'); await this.load(); this.$emit('refresh') } catch (e) { this.planError = e.userMessage || '包装方案保存失败'; this.pendingPlan = Boolean(e.pendingCommand) } finally { this.busy = false }
    },
    async openOperation (row) { this.resetSelectors(); const sequence = this.operationSequence; const shipmentId = this.shipmentId; this.activeOperation = row; this.operationOpen = true; this.operationError = ''; this.pendingOperation = Boolean(pendingPackingCommand('operation', row.id)); try { const { data } = await getPackingOperation(row.id); if (sequence === this.operationSequence && this.operationOpen && shipmentId === this.shipmentId) this.activeOperation = data.data } catch (e) { if (sequence === this.operationSequence) this.operationError = e.userMessage || '作业详情读取失败' } },
    allow (action) { return Boolean((this.activeOperation.allowed_actions || {})[action]) && this.$can(action === 'inspect' ? 'sales_order.shipment.packing.quality' : 'sales_order.shipment.packing.execute') },
    async execute (op, action, extra = {}) {
      if (this.busy) return false
      this.busy = true; this.operationError = ''; this.error = ''
      try { await actPackingOperation(op.id, { action, expected_version: op.business_version, ...extra }); this.peopleOpen = false; this.materialsOpen = false; this.inspectionOpen = false; this.pendingOperation = false; await this.load(); if (this.operationOpen) { const { data } = await getPackingOperation(op.id); this.activeOperation = data.data } this.$emit('refresh'); return true } catch (e) { const message = e.userMessage || '包装作业操作失败'; this.operationError = message; this.error = message; this.materialError = this.materialsOpen ? message : ''; this.pendingOperation = Boolean(e.pendingCommand); return false } finally { this.busy = false }
    },
    async complete () { try { await this.$confirm(`确认本包裹中 ${this.qty(this.activeOperation.planned_base_qty)} 的产品已完成本工序？`, '完成包装工序', { type: 'warning' }); await this.execute(this.activeOperation, 'complete', { completed_base_qty: this.activeOperation.planned_base_qty }) } catch (_) { /* User cancelled the confirmation. */ } },
    async openPeople () { this.peopleRows = []; this.peopleTotal = 0; this.peopleSelected = Object.fromEntries((this.activeOperation.participants || []).filter(row => row.role === 'COLLABORATOR' && row.is_active).map(row => [row.employee_legacy_id, { legacy_id: row.employee_legacy_id, nickname: row.name }])); this.peopleQuery = { keyword: '', department_id: 0, page: 1 }; this.peopleOpen = true; await this.loadPeople(true) },
    async loadPeople (include = false) { const sequence = this.peopleSequence = (this.peopleSequence || 0) + 1; const operationId = this.activeOperation.id; try { const { data } = await listPackingPeople(operationId, { ...this.peopleQuery, department_id: this.peopleQuery.department_id || undefined, per_page: 20, include_departments: include ? 1 : undefined }); if (sequence !== this.peopleSequence || !this.peopleOpen || operationId !== this.activeOperation.id) return; this.peopleRows = data.data.data; this.peopleTotal = data.data.total; if (data.departments) this.peopleTree = tree(data.departments, 'name') } catch (e) { if (sequence === this.peopleSequence && this.peopleOpen) this.$message.error(e.userMessage || '人员读取失败') } },
    togglePerson (row, selected) { if (selected) this.$set(this.peopleSelected, row.legacy_id, row); else this.$delete(this.peopleSelected, row.legacy_id) },
    searchPeople () { this.peopleQuery.page = 1; this.loadPeople() },
    selectDepartment (row) { this.peopleQuery.department_id = row.id; this.searchPeople() },
    peoplePage (page) { this.peopleQuery.page = page; this.loadPeople() },
    savePeople () { this.execute(this.activeOperation, 'collaborators', { employee_legacy_ids: Object.keys(this.peopleSelected).map(Number) }) },
    async openMaterials () { this.materialDrafts = {}; this.materialRows = []; this.materialTotal = 0; this.materialError = ''; this.materialQuery = { keyword: '', category_id: 0, page: 1 }; this.materialsOpen = true; await this.loadMaterials(true) },
    async loadMaterials (include = false) { const sequence = this.materialSequence = (this.materialSequence || 0) + 1; const operationId = this.activeOperation.id; try { const { data } = await listPackingMaterials(operationId, { ...this.materialQuery, category_id: this.materialQuery.category_id || undefined, per_page: 20, include_categories: include ? 1 : undefined }); if (sequence !== this.materialSequence || !this.materialsOpen || operationId !== this.activeOperation.id) return; this.materialRows = data.data.data; this.materialTotal = data.data.total; if (data.categories) this.materialTree = tree(data.categories, 'category_name') } catch (e) { if (sequence === this.materialSequence && this.materialsOpen) this.materialError = e.userMessage || '库存来源读取失败' } },
    searchMaterials () { this.materialQuery.page = 1; this.loadMaterials() },
    selectCategory (row) { this.materialQuery.category_id = row.id; this.searchMaterials() },
    materialPage (page) { this.materialQuery.page = page; this.loadMaterials() },
    addMaterial (row) { this.$set(this.materialDrafts, row.id, { ...row, base_qty: 0, identities: [] }) },
    async openIdentities (material) { this.identityMaterial = material; this.identitySelected = Object.fromEntries(material.identities.map(row => [row.id, row])); this.identityRows = []; this.identityTotal = 0; this.identityKeyword = ''; this.identityPage = 1; this.identityOpen = true; await this.loadIdentities() },
    async loadIdentities () { const sequence = this.identitySequence = (this.identitySequence || 0) + 1; const operationId = this.activeOperation.id; const sourceId = this.identityMaterial.id; try { const { data } = await listPackingIdentities(operationId, { inventory_balance_id: sourceId, keyword: this.identityKeyword.trim(), page: this.identityPage, per_page: 20 }); if (sequence !== this.identitySequence || !this.identityOpen || sourceId !== this.identityMaterial.id || operationId !== this.activeOperation.id) return; this.identityRows = data.data.data; this.identityTotal = data.data.total } catch (e) { if (sequence === this.identitySequence && this.identityOpen) this.$message.error(e.userMessage || '实物身份读取失败') } },
    searchIdentities () { this.identityPage = 1; this.loadIdentities() },
    changeIdentityPage (page) { this.identityPage = page; this.loadIdentities() },
    toggleIdentity (row, selected) { if (selected) this.$set(this.identitySelected, row.id, row); else this.$delete(this.identitySelected, row.id) },
    confirmIdentities () { const selected = Object.values(this.identitySelected); this.identityMaterial.identities = selected; this.identityMaterial.base_qty = this.identityMaterial.physical || this.identityMaterial.serial_tracking_mode === 'required' ? selected.length : Math.max(Number(this.identityMaterial.base_qty) || 0, selected.length); this.identityOpen = false },
    async saveMaterials () { const rows = Object.values(this.materialDrafts); if (!rows.length || rows.some(row => Number(row.base_qty) <= 0)) { this.materialError = '请填写每项实际耗用数量，实物板料须选择真实身份。'; return } await this.execute(this.activeOperation, 'materials', { materials: rows.map(row => ({ inventory_balance_id: row.id, base_qty: row.base_qty, physical_material_ids: row.physical ? row.identities.map(item => item.id) : [], inventory_serial_ids: !row.physical && row.serial_tracking_mode !== 'none' ? row.identities.map(item => item.id) : [] })) }) },
    saveInspection () { this.execute(this.activeOperation, 'inspect', this.inspection) }
  }
}
</script>

<style scoped>
.packing-panel,
.packing-panel * {
  box-sizing: border-box;
}
.packing-panel { min-width: 0; }
.packing-head,
.packing-parcel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.packing-head h3 { margin: 0; color: #1e293b; }
.packing-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
.packing-actions .el-button { margin: 0; }
.packing-panel .el-button--text { color: #008b4b; }
.packing-panel .packing-danger { color: #ef4444; }
.packing-pager { display: flex; justify-content: flex-end; padding-top: 12px; max-width: 100%; overflow-x: auto; }
.packing-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 12px 0; }
.packing-grid > * { min-width: 0; }
.packing-grid label { display: flex; flex-direction: column; gap: 7px; }
.packing-parcel { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 12px 0 16px; }
.packing-table-scroll { min-width: 0; overflow-x: auto; margin-bottom: 12px; }
.packing-table-scroll .el-select { width: 100%; }
.packing-table-scroll .el-input-number { width: 100%; }
.packing-balance,
.packing-requirements { margin: 12px 0; color: #475569; line-height: 1.7; overflow-wrap: anywhere; }
.packing-notice { color: #a16207; margin-bottom: 12px; }
.packing-picker { display: grid; grid-template-columns: 170px minmax(0, 1fr); gap: 14px; }
.packing-picker aside { min-width: 0; padding-right: 8px; border-right: 1px solid #e2e8f0; max-height: 350px; overflow: auto; }
.packing-picker main { min-width: 0; overflow-x: auto; }
.packing-search { display: flex; gap: 8px; margin-bottom: 12px; }
.packing-search .el-input { min-width: 0; }
.packing-selected { display: flex; gap: 8px; flex-wrap: wrap; padding-top: 12px; }
.packing-material-draft { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; padding: 10px 0; border-bottom: 1px solid #e2e8f0; }
.packing-material-draft > div { flex: 1; min-width: 150px; overflow-wrap: anywhere; }
.packing-inspection-reason { margin-top: 18px; }
@media (max-width: 780px) {
  .packing-grid { grid-template-columns: minmax(0, 1fr); }
  .packing-picker { grid-template-columns: minmax(0, 1fr); }
  .packing-picker aside { max-height: 140px; border-right: 0; border-bottom: 1px solid #e2e8f0; }
}
</style>
<style>
.shipment-packing-dialog { max-width: calc(100vw - 32px); }
.shipment-packing-dialog .el-dialog__body { max-height: 65vh; overflow: auto; }
.shipment-packing-dialog .el-dialog__footer { border-top: 1px solid #e2e8f0; }
.shipment-packing-dialog .el-button--text { color: #008b4b; }
.shipment-packing-dialog .packing-danger { color: #ef4444; }
</style>
