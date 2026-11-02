<template>
  <section class="inbound-page">
    <header class="page-head"><div class="head-title"><i class="el-icon-truck" /><h1>入库确认</h1><el-tag size="small" type="success" effect="plain">共 {{ total }} 条</el-tag></div><el-button size="small" icon="el-icon-refresh" :loading="loading" @click="load">刷新</el-button></header>
    <div class="filter-card">
      <label>单号/工单/物料</label><el-input v-model="filters.keyword" size="small" clearable placeholder="请输入单号、工单号或物料名称" @keyup.enter.native="search" @clear="search" />
      <label>类型</label><el-select v-model="filters.kind" size="small" @change="changeKind"><el-option v-for="kind in kinds" :key="kind.value" :label="kind.label" :value="kind.value" /></el-select>
      <label>状态</label><el-select v-model="filters.status" size="small" @change="search"><el-option v-for="s in statuses" :key="s.value" :label="s.label" :value="s.value" /></el-select>
      <el-button size="small" @click="reset">重置</el-button><el-button size="small" type="success" @click="search">查询</el-button>
    </div>
    <div class="list-card">
      <el-table v-loading="loading" :data="rows" border size="small" empty-text="暂无入库申请" @row-click="openDetail">
        <el-table-column prop="number" label="单号" min-width="165" />
        <el-table-column label="类型" width="100"><template slot-scope="s">{{ s.row.title || '生产入库' }}</template></el-table-column>
        <el-table-column label="来源单号" min-width="165"><template slot-scope="s">{{ s.row.work_order_no || s.row.related_number || '-' }}</template></el-table-column>
        <el-table-column prop="item_name" label="物料" min-width="165" />
        <el-table-column label="数量" width="85" align="right"><template slot-scope="s">{{ quantity(s.row.quantity) }} {{ s.row.unit_name }}</template></el-table-column>
        <el-table-column label="申请库位" min-width="130"><template slot-scope="s">{{ s.row.requested_warehouse_name || s.row.target_warehouse_name || '-' }} / {{ s.row.requested_location_code || '-' }}</template></el-table-column>
        <el-table-column label="等待时间 / 申请时间" min-width="155"><template slot-scope="s"><span :class="{ 'error-text': s.row.overdue }">{{ s.row.kind === 'cutting_remnant' && s.row.status === 'pending' ? s.row.waiting_hours + ' 小时' : dateTime(s.row.sort_at) }}</span></template></el-table-column>
        <el-table-column label="状态" width="110"><template slot-scope="s"><el-tag size="mini" :type="statusType(s.row.status)">{{ s.row.status_label }}</el-tag></template></el-table-column>
        <el-table-column label="操作" width="80"><template slot-scope="s"><el-button type="text" size="mini" @click.stop="openDetail(s.row)">查看</el-button></template></el-table-column>
      </el-table>
      <div class="pager"><el-pagination background :current-page="page" :page-size="10" :total="total" layout="total, prev, pager, next" @current-change="changePage" /></div>
    </div>
    <el-dialog :title="header ? detailTitle + ' · ' + (header.receipt_no || header.transfer_no || '') : detailTitle" :visible.sync="detailVisible" width="900px" top="5vh" custom-class="inbound-dialog" :close-on-click-modal="false" :before-close="closeDetail">
      <div v-loading="detailLoading" class="inbound-dialog-body" v-if="header">
        <el-tag size="small" :type="statusType(header.status)">{{ header.status_label }}</el-tag>
        <div v-if="detailKind === 'mfg_receipt'" class="detail-grid">
          <div><span>工单号</span><b>{{ header.work_order_no }}</b></div><div><span>产品名称</span><b>{{ header.product_name || header.item_name }}</b></div><div><span>申请数量</span><b>{{ quantity(header.qty) }} {{ header.unit_name || '台' }}</b></div>
          <div><span>申请人</span><b>{{ header.operator_name || '-' }}</b></div><div><span>申请时间</span><b>{{ header.requested_at }}</b></div><div><span>申请库位</span><b>{{ header.requested_warehouse_name || '-' }} / {{ header.requested_location_code || '-' }}</b></div>
          <div v-if="$can('inventory.amount.view')"><span>成本</span><b :class="{ 'cost-pending': header.cost_status === 'pending_confirmation' }">{{ header.cost_status === 'pending_confirmation' ? '待仓库确认' : money(header.cost_amount) }}</b></div>
        </div>
        <div v-else-if="isRemnant" class="detail-grid"><div><span>来源批次</span><b>{{ header.batch_no }}</b></div><div><span>登记人</span><b>{{ header.requested_by_name }}</b></div><div><span>{{ header.status === 'pending' ? '已等待' : '申请时间' }}</span><b :class="{ 'error-text': header.overdue }">{{ header.status === 'pending' ? header.waiting_hours + ' 小时' : header.requested_at }}</b></div></div>
        <div v-else class="detail-grid"><div><span>来源仓库</span><b>{{ warehouseName(header.source_warehouse) || header.source_warehouse_name }}</b></div><div><span>目标仓库</span><b>{{ warehouseName(header.target_warehouse) || header.target_warehouse_name }}</b></div><div><span>调出时间</span><b>{{ header.dispatched_at || '-' }}</b></div></div>
        <template v-if="actions.confirm && (detailKind === 'mfg_receipt' || isPhysical)">
          <h2>实际入库库位</h2><div class="scan-row"><el-input v-model="locationCode" size="small" placeholder="扫描或输入库位码后回车" :disabled="locked" @input="locator = {}" @keyup.enter.native="scanLocation"><i slot="suffix" class="el-icon-full-screen" /></el-input><el-button size="small" plain type="success" :disabled="locked" @click="openLocator">选择库位</el-button></div>
          <div v-if="locator.location_id" class="location-caption">{{ locator.warehouse_name }} · {{ locator.location_code }} {{ locator.location_name }}</div>
        </template>
        <h2>{{ isRemnant ? '核对余料' : isTransfer ? '接收明细' : header.requires_serial_check ? '逐台核对' : '入库明细' }}<small v-if="actions.confirm && header.requires_serial_check">已核对 <b>{{ checkedCount }} / {{ (header.serial_nos || []).length }}</b></small></h2>
        <div v-if="actions.confirm && header.requires_serial_check" class="scan-row"><el-input v-model="serialCode" size="small" placeholder="扫描或输入序列号后回车" :disabled="locked" @keyup.enter.native="scanSerial"><i slot="suffix" class="el-icon-full-screen" /></el-input><el-button size="small" type="success" plain :disabled="locked" @click="scanSerial">核对</el-button></div>
        <el-table v-if="detailKind === 'mfg_receipt'" :data="lines" border size="small"><el-table-column :label="header.requires_serial_check ? '序列号' : '设备'" min-width="180"><template slot-scope="s">{{ header.requires_serial_check ? s.row.serial_no : '第 ' + s.row.unit_no + ' 台' }}</template></el-table-column><el-table-column label="产品" min-width="180"><template>{{ header.product_name || header.item_name }}</template></el-table-column><el-table-column label="核对状态" width="115"><template slot-scope="s"><el-tag size="mini" :type="checked[s.row.serial_no] ? 'success' : 'info'">{{ actions.confirm && header.requires_serial_check ? (checked[s.row.serial_no] ? '已核对' : '未核对') : s.row.status_label }}</el-tag></template></el-table-column></el-table>
        <div v-if="isRemnant" class="remnant-lines"><div v-for="line in lines" :key="line.source_key" class="remnant-line"><b>{{ line.item_name }} · {{ line.physical_no || line.source_key }}</b><div class="detail-grid"><div><span>原始投入</span><b>{{ line.input_measure }} {{ measureUnit(line) }}</b></div><div><span>实际产出</span><b>{{ line.output_measure == null ? '未逐张分配' : line.output_measure + ' ' + measureUnit(line) }}</b></div><div><span>申请余料</span><b>{{ line.measure_qty }} {{ measureUnit(line) }}</b></div></div><div class="scan-row"><label>确认{{ line.mode === 'length' ? '长度' : '面积' }}</label><el-input v-model="drafts[line.source_key].measure_qty" size="small" :disabled="locked || !remnantEditable"><span slot="append">{{ measureUnit(line) }}</span></el-input><el-button v-if="remnantEditable" size="small" type="success" plain :disabled="locked" @click="openLocator(line.source_key)">选择库位</el-button></div><div v-if="drafts[line.source_key].location_id" class="location-caption">{{ drafts[line.source_key].warehouse_name }} · {{ drafts[line.source_key].location_code }} {{ drafts[line.source_key].location_name }}</div><small>{{ line.mode === 'length' ? '管材按长度确认' : line.shape === 'AREA_ONLY' ? '异形余板，仅按实际面积确认' : '原板尺寸沿用来源' }}</small></div><el-alert v-if="header.waiting_reason" :title="header.waiting_reason" type="warning" :closable="false" /></div>
        <template v-if="isPhysical"><div v-if="actions.confirm" class="scan-row"><el-input v-model="physicalCode" size="small" placeholder="扫描或输入实物编号后回车" :disabled="locked" @keyup.enter.native="scanPhysical"/><el-button size="small" type="success" plain :disabled="locked" @click="scanPhysical">核对实物</el-button></div><el-table :data="lines" border size="small"><el-table-column prop="physical_no" label="实物编号"/><el-table-column label="物料"><template slot-scope="s">{{ (s.row.item || {}).item_name }}</template></el-table-column><el-table-column label="核对状态"><template slot-scope="s"><el-tag :type="checkedPhysicals[s.row.physical_material_id] ? 'success' : 'info'" size="mini">{{ checkedPhysicals[s.row.physical_material_id] ? '已核对' : header.status === 'POSTED' ? '已接收' : '未核对' }}</el-tag></template></el-table-column></el-table></template>
        <el-table v-if="isTransfer && !isPhysical" :data="lines" border size="small"><el-table-column label="物料" min-width="160"><template slot-scope="s">{{ s.row.item_name || (s.row.item || {}).item_name }}</template></el-table-column><el-table-column label="数量" width="100"><template slot-scope="s">{{ quantity(s.row.quantity) }} {{ s.row.unit_name }}</template></el-table-column><el-table-column label="序列号核对" width="120"><template slot-scope="s">{{ (s.row.serial_nos || []).filter(sn => checked[sn]).length }} / {{ (s.row.serial_nos || []).length }}</template></el-table-column><el-table-column label="实际接收库位" min-width="200"><template slot-scope="s"><el-button v-if="actions.confirm" size="mini" :disabled="locked" @click="openLocator(String(s.row.id))">{{ drafts[String(s.row.id)].location_code || '选择接收库位' }}</el-button><span v-else>{{ s.row.target_location_code || (s.row.target_location || {}).location_code || '-' }}</span></template></el-table-column></el-table>
        <div class="serial-pager"><el-button size="mini" :disabled="linePage <= 1 || detailLoading || saving" @click="turnLines(-1)">上一页</el-button><span>{{ linePage }} / {{ lineLastPage }}</span><el-button size="mini" :disabled="linePage >= lineLastPage || detailLoading || saving" @click="turnLines(1)">下一页</el-button></div>
        <template v-if="actions.confirm"><label class="remark-label">备注说明</label><el-input v-model="remark" type="textarea" :rows="3" maxlength="200" show-word-limit placeholder="请输入说明（选填）" :disabled="locked" /></template>
        <div v-if="header.confirmed_at" class="audit-record">{{ sourceLabel }} {{ header.confirmed_by_name || '' }} {{ header.confirmed_at }}<div>{{ header.warehouse_name }} / {{ header.location_code }}</div></div>
        <div v-if="header.reject_reason" class="error-text">驳回原因：{{ header.reject_reason }}<div>{{ header.rejected_by_name }} {{ header.rejected_at }}</div></div>
        <div v-if="header.cancelled_at" class="audit-record">撤回时间 {{ header.cancelled_at }}</div>
        <div v-if="header.remark" class="audit-record">{{ header.remark }}</div>
        <div v-if="header.confirmation_remark" class="audit-record">确认备注：{{ header.confirmation_remark }}</div>
        <el-alert v-if="header.receipt_exception" :title="'接收异常：' + header.receipt_exception + (['in_transit', 'IN_TRANSIT'].includes(header.status || header.transfer_status) ? '。单据保持在途。' : '（历史记录）')" type="warning" :closable="false" />
        <el-alert v-if="formError" :title="formError" type="error" :closable="false" show-icon />
        <el-button v-for="action in pendingActions" :key="action" size="small" :loading="saving" @click="submit(action)">核对上次操作结果</el-button>
      </div>
      <span slot="footer"><el-button size="small" :disabled="saving" @click="detailVisible = false">关闭</el-button><el-button v-if="actions.cancel" size="small" :disabled="locked" @click="requestAction('cancel')">撤回申请</el-button><el-button v-if="actions.resubmit && !resubmitting" size="small" :disabled="locked" @click="resubmitting = true">更正余料并重新提交</el-button><el-button v-if="actions.scrap" size="small" type="danger" plain :disabled="locked" @click="requestAction('scrap')">报废本批余料</el-button><el-button v-if="actions.reject" size="small" type="danger" plain :disabled="locked" @click="requestAction('reject')">驳回</el-button><el-button v-if="actions.exception" size="small" type="danger" plain :disabled="locked" @click="requestAction('exception')">登记异常</el-button><el-button v-if="actions.confirm || resubmitting" size="small" type="success" :loading="saving" :disabled="locked || !confirmReady" @click="requestAction(resubmitting ? 'resubmit' : 'confirm')">{{ resubmitting ? '更正后重新提交' : isTransfer ? '确认接收' : '确认入库' }}</el-button></span>
    </el-dialog>
    <system-record-picker :visible.sync="picker.visible" :request="requestLocators" :params="locatorParams" :multiple="false" :value="[]" :config="{ title: picker.mode === 'warehouse' ? '选择入库仓库' : '选择入库库位', placeholder: '编码 / 名称' }" :columns="[{ prop: 'code', label: '编码' }, { prop: 'name', label: '名称' }]" :label="locatorLabel" @confirm="chooseLocator" />
  </section>
</template>

<script>
import { listWarehouseQueue, getWarehouseDocument, getWarehouseLocators, resolveWarehouseScan, submitWarehouseCommand, pendingWarehouseCommand } from '@/api/erp/warehouse'
import SystemRecordPicker from '@/components/system/SystemRecordPicker.vue'
import { qty, money, errorText, isCancel } from '../production/mfg-helpers'
import { erpDateTime } from '@/utils/dateTime'

export default {
  name: 'InventoryInboundConfirmationList',
  components: { SystemRecordPicker },
  data: () => ({ loading: false, rows: [], total: 0, page: 1, filters: { keyword: '', kind: 'mfg_receipt', status: 'pending' },
    kinds: [{ value: 'mfg_receipt', label: '生产入库' }, { value: 'cutting_remnant', label: '余料入库' }, { value: 'inventory_transfer', label: '跨仓调入' }, { value: 'material_physical_transfer', label: '实物跨仓调入' }],
    detailVisible: false, detailLoading: false, saving: false, header: null, actions: {}, lines: [], linePage: 1, lineLastPage: 1,
    checked: {}, checkedPhysicals: {}, physicalCode: '', detailKind: 'mfg_receipt', drafts: {}, resubmitting: false, locator: {}, locationCode: '', serialCode: '', remark: '', formError: '', pendingActions: [], picker: { visible: false, mode: 'warehouse', warehouse: {}, lineKey: '' } }),
  computed: {
    isRemnant() { return this.detailKind === 'cutting_remnant' },
    isTransfer() { return ['inventory_transfer', 'material_physical_transfer'].includes(this.detailKind) },
    isPhysical() { return this.detailKind === 'material_physical_transfer' },
    detailTitle() { return (this.kinds.find(kind => kind.value === this.detailKind)?.label || '入库') + '确认' },
    remnantEditable() { return this.actions.confirm || this.resubmitting },
    statuses() { return ['inventory_transfer', 'material_physical_transfer'].includes(this.filters.kind) ? [{ value: 'in_transit', label: '在途待接收' }, { value: 'posted', label: '已接收' }] : [{ value: 'pending', label: '待确认' }, { value: 'confirmed', label: '已确认' }, { value: 'rejected', label: '已驳回' }, { value: 'cancelled', label: '已撤回' }] },
    confirmReady() {
      if (!this.header) return false
      const rows = this.header.confirmation_lines || []
      if (this.isRemnant) return rows.length > 0 && rows.every(row => { const draft = this.drafts[row.source_key] || {}; return /^\d+(\.\d{1,8})?$/.test(draft.measure_qty || '') && Number(draft.measure_qty) <= Number(row.max_remnant_measure) && (Number(draft.measure_qty) === 0 || draft.location_id) })
      if (this.isPhysical) return Boolean(this.locator.location_id) && (this.header.physical_ids || []).every(id => this.checkedPhysicals[id])
      if (this.isTransfer) return rows.length > 0 && rows.every(row => this.drafts[String(row.id)]?.location_id) && (!this.header.requires_serial_check || this.checkedCount === (this.header.serial_nos || []).length)
      return Boolean(this.locator.location_id) && (!this.header.requires_serial_check || this.checkedCount === (this.header.serial_nos || []).length)
    },
    checkedCount() { return Object.keys(this.checked).length },
    locked() { return this.saving || this.detailLoading || this.pendingActions.length > 0 },
    sourceLabel() { return this.header?.confirmation_source === 'auto_handoff' ? '系统交接' : this.header?.confirmation_source === 'legacy_posted' ? '历史入库' : '仓库确认' },
    locatorParams() { return { mode: this.picker.mode, warehouse_id: this.picker.mode === 'location' ? this.picker.warehouse.id : undefined,
      location_purposes: this.picker.mode === 'location' ? this.header?.allowed_location_purposes || [] : undefined } }
  },
  created() { this.filters.keyword = this.$route.query.keyword || ''; if (this.kinds.some(kind => kind.value === this.$route.query.kind)) { this.filters.kind = this.$route.query.kind; this.filters.status = this.statuses[0].value } this.load(); if (this.$route.query.id) this.openDetail({ id: Number(this.$route.query.id), kind: this.filters.kind }) },
  beforeDestroy() { this.queueSequence = (this.queueSequence || 0) + 1; this.detailSequence = (this.detailSequence || 0) + 1 },
  methods: {
    quantity: qty, money, dateTime: erpDateTime,
    measureUnit(row) { return row.measure_unit === 'm2' ? 'm²' : '米' },
    warehouseName(row) { return row?.warehouse_name || '' },
    statusType(status) { return ({ pending: 'warning', confirmed: 'success', rejected: 'danger', cancelled: 'info', in_transit: 'warning', IN_TRANSIT: 'warning', posted: 'success', POSTED: 'success' })[status] || 'info' },
    changeKind() { this.filters.status = this.statuses[0].value; this.search() },
    search() { this.page = 1; this.load() },
    reset() { this.filters = { keyword: '', kind: 'mfg_receipt', status: 'pending' }; this.search() },
    changePage(page) { this.page = page; this.load() },
    async load() {
      const sequence = this.queueSequence = (this.queueSequence || 0) + 1; this.loading = true
      try {
        const { data } = await listWarehouseQueue({ ...this.filters, direction: 'inbound', status_group: ['pending', 'in_transit'].includes(this.filters.status) ? 'pending' : 'completed', page: this.page, per_page: 10 })
        if (sequence !== this.queueSequence) return
        this.rows = data.data || []; this.total = Number(data.total || data.meta?.total || 0)
      } catch (e) { if (sequence === this.queueSequence) this.$message.error(errorText(e, '入库申请加载失败')) }
      finally { if (sequence === this.queueSequence) this.loading = false }
    },
    openDetail(row) {
      this.header = { id: row.id }; this.detailVisible = true; this.linePage = 1; this.lines = []; this.actions = {}
      this.detailKind = row.kind || this.filters.kind; this.drafts = {}; this.checkedPhysicals = {}; this.physicalCode = ''; this.resubmitting = false
      this.checked = {}; this.locator = {}; this.remark = ''; this.formError = ''; this.locationCode = ''; this.serialCode = ''
      this.loadDetail()
    },
    async loadDetail() {
      const id = this.header.id; const sequence = this.detailSequence = (this.detailSequence || 0) + 1; this.detailLoading = true
      try {
        const { data } = await getWarehouseDocument(this.detailKind, id, { page: this.linePage, per_page: 10 })
        if (sequence !== this.detailSequence || !this.detailVisible || this.header.id !== id) return
        const body = data.data || data; const header = body.header
        Object.keys(header).filter(key => key.endsWith('_at')).forEach(key => { header[key] = erpDateTime(header[key]) })
        if (this.header.business_version && this.header.business_version !== header.business_version) {
          this.checked = {}; this.drafts = {}; this.checkedPhysicals = {}; this.locator = {}; this.locationCode = ''; this.formError = '单据已变化，请重新核对库位和实物'
        }
        this.header = header; this.actions = {}; (body.actions || []).forEach(action => this.$set(this.actions, action.split('.')[1], true))
        if (header.can_resubmit) this.$set(this.actions, 'resubmit', true)
        ;(header.confirmation_lines || []).forEach(row => { const key = this.isRemnant ? row.source_key : String(row.id); if (!this.drafts[key]) this.$set(this.drafts, key, { measure_qty: String(row.measure_qty || '0'), location_id: null, location_code: '', warehouse_id: this.isTransfer ? header.target_warehouse_id : null }) })
        this.lines = body.lines?.data || []; this.lineLastPage = Number(body.lines?.last_page || 1)
        this.refreshPending()
      } catch (e) { if (sequence === this.detailSequence) this.formError = errorText(e, '入库详情加载失败') }
      finally { if (sequence === this.detailSequence) this.detailLoading = false }
    },
    turnLines(delta) { this.linePage += delta; this.loadDetail() },
    closeDetail(done) { if (!this.saving) done() },
    scanSerial() {
      if (this.locked) return
      const code = this.serialCode.trim().replace(/^ERP:SN:/i, '')
      if (!(this.header.serial_nos || []).includes(code)) { this.formError = '该序列号不属于本次入库申请'; return }
      if (this.checked[code]) { this.formError = '该序列号已核对，请扫描其他实物'; return }
      this.$set(this.checked, code, true); this.serialCode = ''; this.formError = ''
    },
    scanPhysical() { if (this.locked) return; const code = this.physicalCode.trim().replace(/^ERP:PHY:/i, ''); const row = (this.header.confirmation_lines || []).find(row => String(row.physical_material_id) === code || row.physical_no === code); if (!row) { this.formError = '该实物不属于本次调拨'; return } this.$set(this.checkedPhysicals, row.physical_material_id, true); this.physicalCode = ''; this.formError = '' },
    async scanLocation() {
      if (this.locked || !this.locationCode.trim()) return
      try {
        const { data } = await resolveWarehouseScan(this.locationCode.trim()); const result = data.data || data
        if (result.type !== 'location') throw new Error('请扫描库位码')
        if (this.isTransfer && Number(result.warehouse_id) !== Number(this.header.target_warehouse_id)) throw new Error('请在原目标仓库内选择接收库位')
        const response = await getWarehouseLocators({ mode: 'location', warehouse_id: result.warehouse_id, id: result.location_id, location_purposes: this.header.allowed_location_purposes || [], page: 1, per_page: 10 })
        const row = (response.data.data || []).find(row => Number(row.id) === Number(result.location_id))
        if (!row) throw new Error('该库位不适用于当前入库')
        this.locator = { warehouse_id: result.warehouse_id, warehouse_name: result.warehouse_name, location_id: row.id, location_code: row.code, location_name: row.name }; this.locationCode = row.code; this.formError = ''
      } catch (e) { this.formError = errorText(e, e.message || '库位核对失败') }
    },
    requestLocators: getWarehouseLocators,
    locatorLabel(row) { return [row.code, row.name].filter(Boolean).join(' · ') },
    openLocator(lineKey = '') { this.picker = { visible: true, mode: this.isTransfer ? 'location' : 'warehouse', warehouse: this.isTransfer ? { id: this.header.target_warehouse_id, name: this.warehouseName(this.header.target_warehouse) || this.header.target_warehouse_name } : {}, lineKey: typeof lineKey === 'string' ? lineKey : '' } },
    chooseLocator(rows) {
      const row = rows[0]; if (!row || this.locked) return
      if (this.picker.mode === 'warehouse') {
        this.picker.warehouse = row; this.picker.mode = 'location'; this.$nextTick(() => { this.picker.visible = true })
      } else {
        const locator = { warehouse_id: this.picker.warehouse.id, warehouse_name: this.picker.warehouse.name, location_id: row.id, location_code: row.code, location_name: row.name }
        if (this.picker.lineKey) this.$set(this.drafts, this.picker.lineKey, { ...this.drafts[this.picker.lineKey], ...locator })
        else { this.locator = locator; this.locationCode = row.code }
        this.formError = ''
      }
    },
    async requestAction(action) {
      if (this.locked || !this.actions[action]) return
      const payload = { expected_version: action === 'resubmit' ? this.header.batch_business_version : this.header.business_version }
      try {
        if (['reject', 'scrap', 'exception'].includes(action)) {
          const facts = action === 'scrap' ? (this.header.confirmation_lines || []).map(row => `${row.physical_no || row.source_key}：原始投入 ${row.input_measure} ${this.measureUnit(row)}，实际废余料 ${this.drafts[row.source_key]?.measure_qty || '0'} ${this.measureUnit(row)}`).join('；') : ''
          const result = await this.$prompt(action === 'exception' ? '登记异常后保持在途，不改变数量和成本。' : action === 'scrap' ? facts + '。报废后回收为零，成本保留在制造净耗。' : '驳回后退回登记人更正，保留本次记录。', action === 'exception' ? '登记接收异常' : action === 'scrap' ? '报废本批余料' : '驳回入库申请', { inputType: 'textarea', inputPlaceholder: '请输入原因', inputValidator: value => Boolean(value?.trim()) || '请填写原因', inputErrorMessage: '请填写原因', confirmButtonText: '确认' })
          payload.reason = result.value.trim()
        } else {
          await this.$confirm(action === 'cancel' ? '撤回后设备回到等入库，可重新提交。' : '确认本单全部实物已核对并存放于所选库位？', action === 'cancel' ? '撤回入库申请' : '确认入库', { type: 'warning' })
        }
        if (['confirm', 'scrap', 'resubmit'].includes(action)) {
          if (this.isRemnant) {
            const rows = (this.header.confirmation_lines || []).map(row => ({ source_key: row.source_key, measure_qty: String(this.drafts[row.source_key]?.measure_qty || ''), warehouse_id: this.drafts[row.source_key]?.warehouse_id || undefined, location_id: this.drafts[row.source_key]?.location_id || undefined }))
            if (action === 'resubmit') Object.assign(payload, { confirmed: true, remnants: rows }); else payload.lines = rows
          } else if (this.isPhysical) Object.assign(payload, { target_location_id: this.locator.location_id, physical_ids: Object.keys(this.checkedPhysicals).map(Number) })
          else if (this.isTransfer) Object.assign(payload, { lines: (this.header.confirmation_lines || []).map(row => ({ line_id: row.id, target_location_id: this.drafts[String(row.id)]?.location_id })), serial_nos: Object.keys(this.checked) })
          else Object.assign(payload, { location_id: this.locator.location_id, serial_nos: Object.keys(this.checked), remark: this.remark || undefined })
        }
        if (action === 'confirm' && this.remark.trim()) payload.remark = this.remark.trim()
        await this.submit(action, payload)
      } catch (e) { if (!isCancel(e)) this.formError = errorText(e, e.message || '操作失败') }
    },
    async submit(action, payload = {}) {
      if (this.saving) return
      this.saving = true; this.formError = ''
      try {
        const result = await submitWarehouseCommand(action === 'resubmit' ? 'cutting_batch.request' : this.detailKind + '.' + action, action === 'resubmit' ? this.header.cutting_batch_id : this.header.id, payload)
        if (action === 'resubmit') { this.resubmitting = false; if (result.remnant_receipt?.id) this.header.id = result.remnant_receipt.id }
        this.$message.success(action === 'confirm' ? '入库已确认' : action === 'reject' ? '已驳回' : action === 'exception' ? '异常已登记，单据保持在途' : action === 'scrap' ? '余料已报废' : action === 'resubmit' ? '更正已提交' : '申请已撤回')
        await Promise.all([this.loadDetail(), this.load()])
      } catch (e) {
        this.formError = errorText(e, '操作结果待核对')
        this.refreshPending()
        if (!e.pendingCommand && [409, 422].includes(e.response?.status)) await this.loadDetail()
      } finally { this.saving = false }
    },
    refreshPending() { this.pendingActions = ['confirm', 'reject', 'cancel', 'scrap', 'exception'].filter(action => pendingWarehouseCommand(this.detailKind + '.' + action, this.header.id)); if (this.isRemnant && pendingWarehouseCommand('cutting_batch.request', this.header.cutting_batch_id)) this.pendingActions.push('resubmit') }
  }
}
</script>

<style scoped>
.inbound-page { padding: 16px 20px 28px; background: #f8fafc; min-height: calc(100vh - 52px); color: #1e293b; font-size: 13px; }
.inbound-page * { box-sizing: border-box; min-width: 0; }
.page-head, .head-title, .filter-card, .scan-row { display: flex; align-items: center; gap: 14px; }
.page-head, .filter-card, .list-card { padding: 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 14px; }
.page-head { justify-content: space-between; }
.head-title > i { color: #008b4b; background: #f0fdf4; border-radius: 7px; padding: 10px; font-size: 22px; }
h1 { font-size: 20px; margin: 0; }
.filter-card { flex-wrap: wrap; }
.filter-card > .el-input { flex: 1; min-width: 190px; }
.filter-card > .el-select { width: 150px; }
.pager { display: flex; justify-content: flex-end; margin-top: 18px; }
.inbound-dialog-body { max-height: 66vh; overflow-y: auto; }
.detail-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); background: #f5f8fa; border-radius: 7px; gap: 14px 20px; padding: 18px; margin: 14px 0 22px; }
.detail-grid > div { display: flex; align-items: baseline; gap: 12px; }
.detail-grid span { color: #64748b; flex-shrink: 0; }
.detail-grid b { font-weight: 500; overflow-wrap: anywhere; }
.cost-pending { color: #cc741b; }
h2 { font-size: 16px; display: flex; align-items: center; gap: 10px; margin: 24px 0 14px; }
h2::before { content: ''; width: 5px; height: 19px; background: #008b4b; border-radius: 3px; flex-shrink: 0; }
h2 small { margin-left: auto; color: #64748b; font-size: 12px; font-weight: 400; }
h2 small b { color: #008b4b; margin-left: 8px; }
.scan-row { margin-bottom: 12px; }
.scan-row .el-input { flex: 1; }
.scan-row .el-button { width: 120px; }
.location-caption { background: #f3f7fa; color: #526680; padding: 10px 14px; border-radius: 6px; }
.serial-pager { display: flex; justify-content: space-between; align-items: center; margin: 12px 0 24px; }
.remark-label { display: block; margin-bottom: 10px; color: #64748b; }
.audit-record { color: #64748b; line-height: 1.8; margin-top: 18px; }
.error-text { color: #dc4545; line-height: 1.8; margin-top: 18px; }
.inbound-page ::v-deep .el-dialog { max-width: calc(100vw - 32px); }
.inbound-page ::v-deep .el-table th { background: #f8fafc; }
.inbound-page ::v-deep .el-dialog__body { padding: 18px 22px; }
.inbound-page ::v-deep .el-dialog__footer { border-top: 1px solid #e2e8f0; padding: 18px 22px; }
@media (max-width: 1180px) { .detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 780px) { .detail-grid { grid-template-columns: minmax(0, 1fr); }.filter-card > .el-select { flex: 1; }.scan-row .el-button { width: auto; } }
</style>
