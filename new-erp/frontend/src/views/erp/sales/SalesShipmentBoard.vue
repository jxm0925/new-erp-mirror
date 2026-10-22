<template>
  <section class="shipment-board">
    <shipment-packing-panel v-if="executionOnly" execution-only />
    <template v-else>
    <header class="shipment-header"><h1>销售发货</h1><el-button v-if="$can('sales_order.shipment.create')" type="success" size="small" icon="el-icon-plus" @click="openCreate">新建发货单</el-button></header>
    <form class="shipment-filter" @submit.prevent="search"><el-input v-model.trim="query.keyword" size="small" clearable placeholder="发货单号 / 运单号" /><el-select v-model="query.shipment_status" size="small" clearable placeholder="发货状态"><el-option v-for="state in states" :key="state.value" :value="state.value" :label="state.label" /></el-select><el-button type="success" size="small" native-type="submit" icon="el-icon-search">查询</el-button><el-button size="small" icon="el-icon-refresh" @click="reset">重置</el-button></form>
    <div class="shipment-card">
      <el-alert v-if="error" type="error" :title="error" :closable="false" />
      <el-table v-loading="loading" :data="rows" border size="small">
        <el-table-column prop="shipment_no" label="发货单号" min-width="185" />
        <el-table-column label="销售订单" min-width="185"><template slot-scope="{ row }">{{ row.order && row.order.sales_order_no }}</template></el-table-column>
        <el-table-column label="客户" min-width="145"><template slot-scope="{ row }">{{ row.order && row.order.customer_name }}</template></el-table-column>
        <el-table-column label="发货明细" min-width="220"><template slot-scope="{ row }"><div v-for="line in currentShipmentLines(row.lines)" :key="line.id">{{ line.order_line && (line.order_line.product_name || line.order_line.item_name) }} · {{ qty(line.base_qty) }}</div></template></el-table-column>
        <el-table-column label="状态" width="100"><template slot-scope="{ row }"><el-tag size="mini" :type="row.shipment_status === 'shipped' ? 'success' : 'warning'">{{ statusText(row.shipment_status) }}</el-tag></template></el-table-column>
        <el-table-column prop="tracking_no" label="运单号" min-width="150" />
        <el-table-column label="操作" width="95"><template slot-scope="{ row }"><el-button type="text" icon="el-icon-view" @click="openDetail(row.id)">详情</el-button></template></el-table-column>
      </el-table>
      <div class="shipment-pager"><el-pagination background :current-page="query.page" :page-size="query.per_page" :total="total" layout="total, sizes, prev, pager, next, jumper" @current-change="changePage" @size-change="changeSize" /></div>
    </div>

    <el-dialog title="发货单详情" :visible.sync="detailOpen" append-to-body width="min(1180px, calc(100vw - 32px))" top="5vh" custom-class="sales-shipment-dialog" :close-on-click-modal="false">
      <div v-loading="detailLoading">
        <div class="shipment-detail-head"><div><h2>{{ detail.shipment_no }}</h2><div>{{ detail.order && detail.order.sales_order_no }} / {{ detail.order && detail.order.customer_name }}</div></div><el-tag>{{ statusText(detail.shipment_status) }}</el-tag></div>
        <div class="shipment-kv"><div>承运商：{{ detail.carrier_name_snapshot || '—' }}</div><div>运单号：{{ detail.tracking_no || '—' }}</div><div>确认时间：{{ time(detail.confirmed_at) }}</div><div>移入待发：{{ time(detail.staged_at) }}</div><div>正式出库：{{ time(detail.outbound_posted_at) }}</div><div>发运时间：{{ time(detail.shipped_at) }}</div></div>
        <el-table :data="currentShipmentLines(detail.lines)" border size="small"><el-table-column label="产品" min-width="160"><template slot-scope="{ row }">{{ row.order_line && (row.order_line.product_name || row.order_line.item_name) }}</template></el-table-column><el-table-column prop="batch_no" label="来源批次" min-width="160" /><el-table-column prop="base_qty" label="本次发货基础数量" min-width="135" /><el-table-column prop="sales_qty" label="销售数量" min-width="100" /></el-table>
        <h4 v-if="(detail.serial_results || []).length">设备去向与作业结果</h4>
        <el-table v-if="(detail.serial_results || []).length" :data="detail.serial_results" border size="small">
          <el-table-column prop="serial_no" label="设备序列号" min-width="165" />
          <el-table-column label="状态" min-width="110"><template slot-scope="{ row }">{{ serialStatus(row.status) }}</template></el-table-column>
          <el-table-column label="试水结果" min-width="100"><template slot-scope="{ row }">{{ qualityText(row.quality_result) }}</template></el-table-column>
          <el-table-column prop="location_name" label="当前位置" min-width="130" />
          <el-table-column label="更换设备" min-width="165"><template slot-scope="{ row }">{{ replacementSerialNo(row) }}</template></el-table-column>
          <el-table-column label="操作" min-width="150"><template slot-scope="{ row }"><el-button v-if="$can('sales_order.shipment.post') && row.allowed_actions && row.allowed_actions.repair_return" type="text" :disabled="busy || !!pendingAction" @click="openSerialAction(row, 'repair-return')">返修合格返回</el-button><el-button v-if="$can('sales_order.shipment.post') && row.allowed_actions && row.allowed_actions.replace" type="text" :disabled="busy || !!pendingAction" @click="openSerialAction(row, 'replace')">换机</el-button></template></el-table-column>
        </el-table>
        <shipment-packing-panel v-if="detail.id && $can('sales_order.shipment.packing.view')" class="shipment-packing-section" :shipment-id="Number(detail.id)" :shipment-status="detail.shipment_status" @refresh="refreshDetail" />
        <el-alert v-if="detailError" :title="detailError" type="error" :closable="false" show-icon />
      </div>
      <div slot="footer" class="shipment-actions">
        <el-button size="small" :disabled="busy" @click="detailOpen = false">关闭</el-button>
        <el-button v-if="pendingAction" type="success" size="small" :loading="busy" @click="shipmentAction(pendingAction, {}, true)">重试原发货操作</el-button>
        <template v-else>
          <el-button v-if="detail.shipment_status === 'draft' && $can('sales_order.shipment.confirm')" size="small" type="success" :loading="busy" @click="shipmentAction('confirm')">确认发货单</el-button>
          <el-button v-if="detail.shipment_status === 'pending_outbound' && $can('sales_order.shipment.post')" size="small" type="success" :loading="busy" @click="openPrepare">移至待发区</el-button>
          <el-button v-if="['prepared', 'partially_shipped', 'outbound_posted'].includes(detail.shipment_status) && $can('sales_order.shipment.dispatch')" size="small" type="success" :loading="busy" @click="openDispatch">确认发运 / 提货</el-button>
          <el-button v-if="['draft', 'pending_outbound', 'prepared'].includes(detail.shipment_status) && $can('sales_order.shipment.cancel')" size="small" :loading="busy" @click="cancelShipment">取消发货单</el-button>
        </template>
      </div>
    </el-dialog>

    <el-dialog title="移至待发区" :visible.sync="prepare.visible" append-to-body top="8vh" width="min(560px, calc(100vw - 32px))" custom-class="sales-shipment-dialog" :close-on-click-modal="false">
      <el-form label-position="top" size="small">
        <el-form-item label="待发仓库"><el-button class="shipment-locator" :disabled="busy" @click="openLocator(prepare, 'warehouses', 'shipping_staging')">{{ prepare.warehouse_name || '选择仓库' }}<i class="el-icon-search" /></el-button></el-form-item>
        <el-form-item label="待发库位"><el-button class="shipment-locator" :disabled="busy || !prepare.warehouse_id" @click="openLocator(prepare, 'locations', 'shipping_staging')">{{ prepare.location_name || '选择待发库位' }}<i class="el-icon-search" /></el-button></el-form-item>
      </el-form>
      <el-alert v-if="detailError" :title="detailError" type="error" :closable="false" />
      <span slot="footer"><el-button size="small" :disabled="busy" @click="prepare.visible = false">取消</el-button><el-button type="success" size="small" :loading="busy" :disabled="!prepare.location_id" @click="shipmentAction('post-outbound', { staging_location_id: prepare.location_id })">确认移至待发区</el-button></span>
    </el-dialog>

    <el-dialog title="确认发运 / 提货" :visible.sync="dispatchForm.visible" append-to-body top="8vh" width="min(780px, calc(100vw - 32px))" custom-class="sales-shipment-dialog" :close-on-click-modal="false">
      <el-radio-group v-model="dispatchForm.delivery_mode" size="small" :disabled="busy"><el-radio label="dispatch">公司发运</el-radio><el-radio label="pickup">客户提货</el-radio></el-radio-group>
      <h4>本次发出的包裹</h4>
      <el-table :data="detail.dispatchable_packages || []" border size="small">
        <el-table-column width="55"><template slot-scope="{ row }"><el-checkbox :value="dispatchForm.package_ids.includes(row.id)" :disabled="busy || !row.ready" @change="togglePackage(row, $event)" /></template></el-table-column>
        <el-table-column prop="package_no" label="包裹编号" min-width="160" />
        <el-table-column label="发运条件" min-width="230"><template slot-scope="{ row }">{{ row.ready ? '已就绪' : (row.blockers || []).join('；') }}</template></el-table-column>
      </el-table>
      <el-alert v-if="detailError" :title="detailError" type="error" :closable="false" />
      <span slot="footer"><el-button size="small" :disabled="busy" @click="dispatchForm.visible = false">取消</el-button><el-button type="success" size="small" :loading="busy" :disabled="!dispatchForm.package_ids.length" @click="shipmentAction('dispatch', { package_ids: dispatchForm.package_ids, delivery_mode: dispatchForm.delivery_mode })">{{ dispatchForm.delivery_mode === 'pickup' ? '确认提货并出库' : '确认发运并出库' }}</el-button></span>
    </el-dialog>

    <el-dialog :title="serialAction.type === 'replace' ? '更换发货设备' : '返修合格返回'" :visible.sync="serialAction.visible" append-to-body top="8vh" width="min(850px, calc(100vw - 32px))" custom-class="sales-shipment-dialog" :close-on-click-modal="false">
      <p>原设备：{{ serialAction.row.serial_no }}</p>
      <template v-if="serialAction.type === 'replace'">
        <div class="shipment-filter"><el-input v-model.trim="replacementQuery.keyword" size="small" clearable placeholder="设备序列号" @keyup.enter.native="searchReplacements" /><el-button size="small" type="success" @click="searchReplacements">查询</el-button></div>
        <el-table v-loading="replacementLoading" :data="replacementRows" border size="small" @row-click="serialAction.replacement = $event">
          <el-table-column width="55"><template slot-scope="{ row }"><el-radio :value="serialAction.replacement && serialAction.replacement.id" :label="row.id" @change="serialAction.replacement = row">&nbsp;</el-radio></template></el-table-column>
          <el-table-column prop="serial_no" label="新设备序列号" min-width="170" />
          <el-table-column prop="batch_no" label="批次" min-width="160" />
          <el-table-column prop="location_name" label="库位" min-width="130" />
        </el-table>
        <el-pagination background :current-page="replacementQuery.page" :page-size="10" :total="replacementTotal" layout="total, prev, pager, next" @current-change="replacementPage" />
      </template>
      <el-form v-else label-position="top" size="small">
        <el-form-item label="返回成品仓库"><el-button class="shipment-locator" :disabled="busy" @click="openLocator(serialAction, 'warehouses', 'finished')">{{ serialAction.warehouse_name || '选择仓库' }}<i class="el-icon-search" /></el-button></el-form-item>
        <el-form-item label="返回成品库位"><el-button class="shipment-locator" :disabled="busy || !serialAction.warehouse_id" @click="openLocator(serialAction, 'locations', 'finished')">{{ serialAction.location_name || '选择成品库位' }}<i class="el-icon-search" /></el-button></el-form-item>
      </el-form>
      <el-input v-model.trim="serialAction.reason" type="textarea" :rows="2" maxlength="500" placeholder="操作原因" :disabled="busy" />
      <el-alert v-if="detailError" :title="detailError" type="error" :closable="false" />
      <span slot="footer"><el-button size="small" :disabled="busy" @click="serialAction.visible = false">取消</el-button><el-button size="small" type="success" :loading="busy" :disabled="!serialAction.reason || (serialAction.type === 'replace' ? !serialAction.replacement : !serialAction.location_id)" @click="saveSerialAction">确认</el-button></span>
    </el-dialog>
    <system-record-picker :visible.sync="locator.visible" :type="locator.type" :multiple="false" :value="locatorValue" :warehouse-id="locator.row ? Number(locator.row.warehouse_id || 0) : 0" :location-purpose="locator.purpose" :management-scope="detail.management_scope || ''" @confirm="selectLocator" />

    <el-dialog title="新建发货单" :visible.sync="createOpen" append-to-body width="min(1050px, calc(100vw - 32px))" top="5vh" custom-class="sales-shipment-dialog" :close-on-click-modal="false">
      <el-alert v-if="pendingCreate" title="上次发货草稿结果待确认，请重试原操作。" type="warning" :closable="false" />
      <fieldset :disabled="busy || pendingCreate" class="shipment-create-fields">
      <div class="shipment-form-grid"><label>销售订单<el-input :value="selectedOrder.sales_order_no" readonly size="small"><el-button slot="append" icon="el-icon-search" @click="openOrderPicker">选择订单</el-button></el-input></label><label>承运商<el-input v-model.trim="createForm.carrier_name" size="small" maxlength="120" /></label><label>运单号<el-input v-model.trim="createForm.tracking_no" size="small" maxlength="120" /></label></div>
      <el-table v-loading="sourceLoading" :data="sourceRows" border size="small"><el-table-column label="选择" width="55"><template slot-scope="{ row }"><el-checkbox :value="Boolean(sourceSelected[row.id])" @change="toggleSource(row, $event)" /></template></el-table-column><el-table-column label="产品 / 物料" min-width="190"><template slot-scope="{ row }">{{ row.product_name || row.item_name }}</template></el-table-column><el-table-column label="预留批次" min-width="170"><template slot-scope="{ row }">{{ row.batch_nos.join('、') }}</template></el-table-column><el-table-column prop="available_base_qty" label="可发基础数量" width="120" /><el-table-column label="本次发货基础数量" width="165"><template slot-scope="{ row }"><el-input-number v-if="sourceSelected[row.id]" v-model="sourceSelected[row.id].base_qty" size="mini" :controls="false" :min="0" :max="Number(row.available_base_qty)" :precision="8" :disabled="row.serial_tracking_mode === 'required'" /></template></el-table-column><el-table-column label="设备编号 / 序列号" min-width="175"><template slot-scope="{ row }"><el-button v-if="sourceSelected[row.id] && row.serial_tracking_mode !== 'none'" type="text" @click="openSerialPicker(row)">选择序列号（{{ sourceSelected[row.id].serials.length }}）</el-button></template></el-table-column></el-table>
      <el-pagination background :current-page="sourcePage" :page-size="10" :total="sourceTotal" layout="total, prev, pager, next" @current-change="changeSourcePage" />
      <div class="shipment-selected"><el-tag v-for="row in Object.values(sourceSelected)" :key="row.id" closable @close="$delete(sourceSelected, row.id)">{{ row.product_name || row.item_name }} · {{ qty(row.base_qty) }}</el-tag></div>
      <label class="shipment-remark">备注<el-input v-model.trim="createForm.remark" type="textarea" :rows="2" maxlength="2000" /></label>
      <el-alert v-if="createError" :title="createError" type="error" :closable="false" />
      </fieldset>
      <span slot="footer"><el-button size="small" :disabled="busy" @click="createOpen = false">取消</el-button><el-button size="small" type="success" :loading="busy" @click="saveCreate">{{ pendingCreate ? '重试原发货草稿' : '保存发货草稿' }}</el-button></span>
    </el-dialog>

    <el-dialog title="选择销售订单" :visible.sync="orderPickerOpen" append-to-body width="min(900px, calc(100vw - 32px))" top="8vh" custom-class="sales-shipment-dialog" :close-on-click-modal="false">
      <form class="shipment-filter" @submit.prevent="searchOrders"><el-input v-model.trim="orderQuery.keyword" size="small" placeholder="订单号 / 客户名称" /><el-button size="small" type="success" native-type="submit">查询</el-button></form>
      <el-table :data="orderRows" border size="small"><el-table-column prop="sales_order_no" label="订单号" min-width="190" /><el-table-column prop="customer_name" label="客户名称" min-width="170" /><el-table-column prop="order_date" label="订单日期" min-width="110" /><el-table-column label="选择" width="95"><template slot-scope="{ row }"><el-button type="text" :disabled="!['confirmed', 'in_progress'].includes(row.order_status)" @click="chooseOrder(row)">选择</el-button></template></el-table-column></el-table>
      <el-pagination background :current-page="orderQuery.page" :page-size="10" :total="orderTotal" layout="total, prev, pager, next" @current-change="changeOrderPage" />
      <span slot="footer"><el-button size="small" @click="orderPickerOpen = false">取消</el-button></span>
    </el-dialog>

    <el-dialog title="选择发货序列号" :visible.sync="serialOpen" append-to-body width="min(850px, calc(100vw - 32px))" top="8vh" custom-class="sales-shipment-dialog" :close-on-click-modal="false">
      <form class="shipment-filter" @submit.prevent="searchSerials"><el-input v-model.trim="serialKeyword" size="small" placeholder="设备编号 / 序列号" /><el-button size="small" type="success" native-type="submit">查询</el-button></form>
      <el-table :data="serialRows" border size="small"><el-table-column label="选择" width="55"><template slot-scope="{ row }"><el-checkbox :value="Boolean(serialSelected[row.id])" @change="toggleSerial(row, $event)" /></template></el-table-column><el-table-column prop="serial_no" label="设备编号 / 序列号" min-width="180" /><el-table-column prop="batch_no" label="来源批次" min-width="180" /></el-table>
      <el-pagination background :current-page="serialPage" :page-size="10" :total="serialTotal" layout="total, prev, pager, next" @current-change="changeSerialPage" />
      <div class="shipment-selected"><el-tag v-for="serial in Object.values(serialSelected)" :key="serial.id" closable @close="$delete(serialSelected, serial.id)">{{ serial.serial_no }}</el-tag></div>
      <span slot="footer"><el-button size="small" @click="serialOpen = false">取消</el-button><el-button size="small" type="success" @click="confirmSerials">确认选择</el-button></span>
    </el-dialog>
    </template>
  </section>
</template>

<script>
import { erpDateTime } from '../../../utils/dateTime'
import ShipmentPackingPanel from './ShipmentPackingPanel.vue'
import SystemRecordPicker from '@/components/system/SystemRecordPicker.vue'
import { listShipments, getShipment, createShipment, actShipment, listShipmentSources, listShipmentSourceSerials, listShipmentReplacementSerials } from '@/api/erp/shipment-packing'
import { pendingProductionDecision } from '@/api/erp/production-decisions'
import { listSalesOrders, getSalesOrder } from '@/api/erp/sales'

export default {
  name: 'SalesShipmentBoard',
  components: { ShipmentPackingPanel, SystemRecordPicker },
  data: () => ({ loading: false, busy: false, error: '', rows: [], total: 0, query: { keyword: '', shipment_status: '', sales_order_id: '', page: 1, per_page: 10 },
    states: [{ value: 'draft', label: '草稿' }, { value: 'pending_outbound', label: '待移待发区' }, { value: 'prepared', label: '待发作业' }, { value: 'partially_shipped', label: '部分已发运' }, { value: 'outbound_posted', label: '已出库待发运' }, { value: 'shipped', label: '已发运' }, { value: 'cancelled', label: '已取消' }],
    detailOpen: false, detailLoading: false, detail: {}, detailError: '', createOpen: false, createError: '', pendingCreate: false, selectedOrder: {}, createForm: { carrier_name: '', tracking_no: '', remark: '' },
    sourceLoading: false, sourceRows: [], sourceSelected: {}, sourcePage: 1, sourceTotal: 0,
    orderPickerOpen: false, orderRows: [], orderTotal: 0, orderQuery: { keyword: '', page: 1, per_page: 10 },
    serialOpen: false, serialRows: [], serialSelected: {}, serialSource: {}, serialPage: 1, serialTotal: 0, serialKeyword: '', loadSequence: 0,
    pendingAction: '', prepare: { visible: false, warehouse_id: null, warehouse_name: '', location_id: null, location_name: '' },
    dispatchForm: { visible: false, package_ids: [], delivery_mode: 'dispatch' },
    serialAction: { visible: false, type: '', row: {}, replacement: null, reason: '', warehouse_id: null, warehouse_name: '', location_id: null, location_name: '' },
    locator: { visible: false, type: 'warehouses', purpose: '', row: null },
    replacementRows: [], replacementTotal: 0, replacementLoading: false, replacementSequence: 0, replacementQuery: { keyword: '', page: 1 } }),
  computed: {
    executionOnly () { return !this.$can('sales_order.shipment.view') && this.$can(['sales_order.shipment.packing.execute', 'sales_order.shipment.packing.quality']) },
    locatorValue () {
      const row = this.locator.row
      if (!row) return []
      return this.locator.type === 'warehouses'
        ? (row.warehouse_id ? [{ id: row.warehouse_id, warehouse_name: row.warehouse_name }] : [])
        : (row.location_id ? [{ id: row.location_id, location_name: row.location_name }] : [])
    }
  },
  created () { this.query.sales_order_id = this.$route.query.sales_order_id || ''; this.load() },
  beforeDestroy () { this.loadSequence++ },
  methods: {
    qty (value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) },
    currentShipmentLines (lines) { return (lines || []).filter(line => line.line_status !== 'replaced') },
    time (value) { return value ? erpDateTime(value, 19) : '—' },
    statusText (value) { const status = this.states.find(row => row.value === value); return status ? status.label : value || '—' },
    serialStatus (value) { return ({ allocated: '已选设备', prepared: '待发区', repair: '返修中', shipped: '已发运', replaced: '已换机', cancelled: '已取消' })[value] || value || '—' },
    qualityText (value) { return ({ passed: '合格', failed: '不合格' })[value] || '待试水' },
    replacementSerialNo (row) { const replacement = (this.detail.serial_results || []).find(serial => serial.inventory_serial_id === row.replaced_by_serial_id); return replacement ? replacement.serial_no : '—' },
    async load () { if (this.executionOnly) return; const sequence = ++this.loadSequence; this.loading = true; this.error = ''; try { const { data } = await listShipments(this.query); if (sequence !== this.loadSequence) return; this.rows = data.data.data; this.total = data.data.total } catch (e) { this.error = e.userMessage || '发货单读取失败' } finally { if (sequence === this.loadSequence) this.loading = false } },
    search () { this.query.page = 1; this.load() },
    reset () { this.query = { keyword: '', shipment_status: '', sales_order_id: '', page: 1, per_page: 10 }; this.load() },
    changePage (page) { this.query.page = page; this.load() },
    changeSize (size) { this.query.per_page = size; this.search() },
    async openDetail (id) { this.detailOpen = true; this.detailLoading = true; this.detailError = ''; this.detail = {}; this.pendingAction = ''; try { const { data } = await getShipment(id); this.detail = data.data; this.readPendingAction() } catch (e) { this.detailError = e.userMessage || '发货详情读取失败' } finally { this.detailLoading = false } },
    async refreshDetail () { if (!this.detail.id) return; const { data } = await getShipment(this.detail.id); this.detail = data.data; this.readPendingAction(); await this.load() },
    readPendingAction () {
      const actions = ['confirm', 'post-outbound', 'dispatch', 'cancel']
      for (const row of this.detail.serial_results || []) actions.push('serials/' + row.shipment_line_id + '/repair-return', 'serials/' + row.shipment_line_id + '/replace')
      this.pendingAction = actions.find(action => pendingProductionDecision('shipment_' + this.detail.id + '_' + action)) || ''
    },
    openPrepare () { this.detailError = ''; this.prepare = { visible: true, warehouse_id: null, warehouse_name: '', location_id: null, location_name: '' } },
    openDispatch () { this.detailError = ''; this.dispatchForm = { visible: true, package_ids: [], delivery_mode: 'dispatch' } },
    togglePackage (row, selected) { if (selected) this.dispatchForm.package_ids.push(row.id); else this.dispatchForm.package_ids = this.dispatchForm.package_ids.filter(id => id !== row.id) },
    openLocator (row, type, purpose) { this.locator = { visible: true, row, type, purpose } },
    selectLocator (rows) {
      const selected = rows[0]; const row = this.locator.row
      if (!selected || !row) return
      if (this.locator.type === 'warehouses') { if (row.warehouse_id !== selected.id) { row.location_id = null; row.location_name = '' }; row.warehouse_id = selected.id; row.warehouse_name = selected.warehouse_name }
      else { row.location_id = selected.id; row.location_name = [selected.location_code, selected.location_name].filter(Boolean).join(' ') }
    },
    openSerialAction (row, type) {
      this.detailError = ''
      this.serialAction = { visible: true, type, row, replacement: null, reason: '', warehouse_id: row.warehouse_id, warehouse_name: row.warehouse_name, location_id: null, location_name: '' }
      if (type === 'replace') { this.replacementQuery = { keyword: '', page: 1 }; this.replacementRows = []; this.loadReplacements() }
    },
    async loadReplacements () {
      const sequence = ++this.replacementSequence; const lineId = this.serialAction.row.shipment_line_id
      this.replacementLoading = true
      try { const { data } = await listShipmentReplacementSerials(this.detail.id, lineId, { ...this.replacementQuery, per_page: 10 }); if (sequence !== this.replacementSequence || !this.serialAction.visible || lineId !== this.serialAction.row.shipment_line_id) return; this.replacementRows = data.data.data; this.replacementTotal = Number(data.data.total) }
      catch (e) { if (sequence === this.replacementSequence) this.detailError = e.userMessage || '可更换设备加载失败' }
      finally { if (sequence === this.replacementSequence) this.replacementLoading = false }
    },
    searchReplacements () { this.replacementQuery.page = 1; this.loadReplacements() },
    replacementPage (page) { this.replacementQuery.page = page; this.loadReplacements() },
    saveSerialAction () {
      const form = this.serialAction
      if (!form.reason || (form.type === 'replace' ? !form.replacement : !form.location_id)) return
      this.shipmentAction('serials/' + form.row.shipment_line_id + '/' + form.type, { reason: form.reason, ...(form.type === 'replace' ? { replacement_inventory_serial_id: form.replacement.id } : { finished_location_id: form.location_id }) })
    },
    async shipmentAction (action, payload = {}, retry = false) {
      if (this.busy || this.pendingAction && !retry) return
      const label = { confirm: '确认发货单', 'post-outbound': '移至待发区', dispatch: payload.delivery_mode === 'pickup' ? '确认提货并出库' : '确认发运并出库', cancel: '取消发货单' }[action] || (action.endsWith('/replace') ? '更换发货设备' : '返修合格返回')
      try {
        if (!retry) await this.$confirm('确认执行“' + label + '”？', label, { type: 'warning' })
        this.busy = true; this.detailError = ''
        await actShipment(this.detail.id, action, { expected_version: this.detail.business_version, ...payload })
        this.prepare.visible = false; this.dispatchForm.visible = false; this.serialAction.visible = false
        this.$message.success(label + '已完成'); await this.refreshDetail()
      } catch (e) { if (!['cancel', 'close'].includes(e)) { this.detailError = e.userMessage || '发货操作失败'; this.readPendingAction() } }
      finally { this.busy = false }
    },
    async cancelShipment () { try { const result = await this.$prompt('请填写取消原因', '取消发货单', { inputValidator: value => Boolean(value && value.trim()) || '请填写取消原因', inputType: 'textarea' }); await this.shipmentAction('cancel', { reason: result.value }) } catch (_) { /* User cancelled. */ } },
    async openCreate () { this.createOpen = true; this.createError = ''; this.pendingCreate = false; this.selectedOrder = {}; this.sourceRows = []; this.sourceSelected = {}; this.sourcePage = 1; this.sourceTotal = 0; this.createForm = { carrier_name: '', tracking_no: '', remark: '' }; if (this.query.sales_order_id) { try { const { data } = await getSalesOrder(this.query.sales_order_id); await this.chooseOrder(data) } catch (e) { this.createError = e.userMessage || '订单读取失败' } } },
    openOrderPicker () { this.orderQuery = { keyword: '', page: 1, per_page: 10 }; this.orderPickerOpen = true; this.loadOrders() },
    async loadOrders () { try { const { data } = await listSalesOrders(this.orderQuery); this.orderRows = data.data || []; this.orderTotal = data.total || 0 } catch (e) { this.$message.error(e.userMessage || '订单读取失败') } },
    searchOrders () { this.orderQuery.page = 1; this.loadOrders() },
    changeOrderPage (page) { this.orderQuery.page = page; this.loadOrders() },
    async chooseOrder (row) { this.selectedOrder = row; this.orderPickerOpen = false; this.sourceSelected = {}; this.sourcePage = 1; this.createForm.carrier_name = row.default_carrier_name_snapshot || ''; this.pendingCreate = Boolean(pendingProductionDecision('shipment_create_' + row.id)); await this.loadSources() },
    async loadSources () { if (!this.selectedOrder.id) return; this.sourceLoading = true; try { const { data } = await listShipmentSources({ sales_order_id: this.selectedOrder.id, page: this.sourcePage, per_page: 10 }); this.sourceRows = data.data.data; this.sourceTotal = data.data.total } catch (e) { this.createError = e.userMessage || '预留库存读取失败' } finally { this.sourceLoading = false } },
    changeSourcePage (page) { this.sourcePage = page; this.loadSources() },
    toggleSource (row, selected) { if (this.busy || this.pendingCreate) return; if (selected) this.$set(this.sourceSelected, row.id, { ...row, base_qty: row.serial_tracking_mode === 'required' ? 0 : Number(row.available_base_qty), serials: [] }); else this.$delete(this.sourceSelected, row.id) },
    async openSerialPicker (row) { this.serialSource = row; this.serialSelected = Object.fromEntries(this.sourceSelected[row.id].serials.map(serial => [serial.id, serial])); this.serialPage = 1; this.serialKeyword = ''; this.serialOpen = true; await this.loadSerials() },
    async loadSerials () { try { const { data } = await listShipmentSourceSerials(this.serialSource.id, { page: this.serialPage, per_page: 10, keyword: this.serialKeyword }); this.serialRows = data.data.data; this.serialTotal = data.data.total } catch (e) { this.$message.error(e.userMessage || '发货序列号读取失败') } },
    searchSerials () { this.serialPage = 1; this.loadSerials() },
    changeSerialPage (page) { this.serialPage = page; this.loadSerials() },
    toggleSerial (row, selected) { if (selected) this.$set(this.serialSelected, row.id, row); else this.$delete(this.serialSelected, row.id) },
    confirmSerials () { const row = this.sourceSelected[this.serialSource.id]; row.serials = Object.values(this.serialSelected); if (row.serial_tracking_mode === 'required') row.base_qty = row.serials.length; this.serialOpen = false },
    async saveCreate () { if (this.busy) return; const selected = Object.values(this.sourceSelected); if (!this.selectedOrder.id || !this.pendingCreate && (!selected.length || selected.some(row => Number(row.base_qty) <= 0))) { this.createError = '请选择销售订单、预留库存和大于0的本次发货数量。'; return } this.busy = true; this.createError = ''; try { const { data } = await createShipment({ sales_order_id: this.selectedOrder.id, ...this.createForm, lines: selected.map(row => ({ sales_order_fulfillment_id: row.id, base_qty: row.base_qty, inventory_serial_ids: row.serials.map(serial => serial.id) })) }); this.pendingCreate = false; this.createOpen = false; await this.load(); await this.openDetail(data.data.id) } catch (e) { this.pendingCreate = Boolean(e.pendingCommand); this.createError = e.userMessage || '发货草稿保存失败' } finally { this.busy = false } }
  }
}
</script>

<style scoped>
.shipment-board,
.shipment-board * { box-sizing: border-box; }
.shipment-board { min-width: 0; padding: 20px; background: #f8fafc; }
.shipment-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: #fff; padding: 18px 20px; border-radius: 8px; }
.shipment-header h1 { color: #1e293b; font-size: 20px; margin: 0; }
.shipment-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; background: #fff; border-radius: 8px; padding: 16px 20px; margin: 16px 0; }
.shipment-filter .el-input { width: 260px; max-width: 100%; }
.shipment-filter .el-select { width: 160px; max-width: 100%; }
.shipment-filter .el-button { margin: 0; }
.shipment-card { min-width: 0; background: #fff; padding: 16px; border-radius: 8px; }
.shipment-board .el-button--text { color: #008b4b; }
.shipment-pager { display: flex; justify-content: flex-end; padding-top: 16px; overflow-x: auto; }
.shipment-detail-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; }
.shipment-detail-head h2 { margin: 0 0 8px; font-size: 18px; }
.shipment-kv,
.shipment-form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin: 14px 0; }
.shipment-kv > *,
.shipment-form-grid > * { min-width: 0; overflow-wrap: anywhere; }
.shipment-form-grid label,
.shipment-remark { display: flex; flex-direction: column; gap: 8px; }
.shipment-selected { display: flex; flex-wrap: wrap; gap: 8px; padding: 14px 0; }
.shipment-locator {
  width: 100%;
  text-align: left;
  white-space: normal;
}
.shipment-locator i {
  margin-left: 10px;
}
.shipment-create-fields {
  border: 0;
  margin: 0;
  padding: 0;
  min-width: 0;
}
.shipment-packing-section { border-top: 1px solid #e2e8f0; margin-top: 20px; padding-top: 18px; }
.shipment-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 8px; }
.shipment-actions .el-button { margin: 0; }
@media (max-width: 780px) {
  .shipment-board { padding: 12px; }
  .shipment-kv,
  .shipment-form-grid { grid-template-columns: minmax(0, 1fr); }
  .shipment-filter .el-input,
  .shipment-filter .el-select { width: 100%; }
}
</style>
<style>
.sales-shipment-dialog { max-width: calc(100vw - 32px); }
.sales-shipment-dialog .el-dialog__body { max-height: 65vh; overflow: auto; }
.sales-shipment-dialog .el-dialog__footer { border-top: 1px solid #e2e8f0; }
.sales-shipment-dialog .el-button--text { color: #008b4b; }
</style>
