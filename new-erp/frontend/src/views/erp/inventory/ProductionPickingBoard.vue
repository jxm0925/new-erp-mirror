<template>
  <section class="production-picking-page">
    <header class="pm-page-head"><h1>生产配料</h1><p>合并生产需求进行公共配料，板材和长料在现场领用。</p><el-button v-if="$can('production.material_procurement.create')" size="small" type="success" @click="$refs.procurement.open()">提交采购需求</el-button></header>
    <el-tabs v-model="tab" class="pm-tabs" @tab-click="switchTab"><el-tab-pane v-if="$can('production.material_picking.view')" name="public" label="公共配料" /><el-tab-pane v-if="$can('production.material_requirement.view')" name="demands" label="工单待配料" /><el-tab-pane v-if="$can('production.material_picking.view')" name="picking" label="工单配料记录" /><el-tab-pane v-if="$can('production.material_receipt.view')" name="onsite" label="我的现场领料" /><el-tab-pane v-if="$can('production.material_delivery.view') || $can('production.material_receipt.view')" name="delivery" :label="recipientOnly ? '我的配送收料' : '配送单'" /></el-tabs>
    <onsite-material-collection-panel v-if="tab === 'onsite'" /><public-preparation-panel v-if="tab === 'public'" ref="publicPreparation" @child="id => openDetail('picking', id)" @procurement="row => $refs.procurement.open(row)" />
    <section v-show="!['public','onsite'].includes(tab)" class="pm-filters pm-list-filters"><label>{{ tab === 'demands' ? '工单/物料' : tab === 'delivery' ? '工单/配送单号' : '工单/配料单号' }}</label><el-input v-model="query.keyword" size="small" clearable placeholder="输入单号或名称查询" @keyup.enter.native="search" /><template v-if="tab !== 'demands'"><label>状态</label><el-select v-model="query.status" size="small" clearable placeholder="全部"><el-option v-for="(label, key) in statuses" :key="key" :label="label" :value="key" /></el-select></template><el-button type="success" size="small" @click="search">查询</el-button><el-button size="small" @click="reset">重置</el-button></section>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-alert v-if="tab === 'delivery' && receiptPending.length" title="上次收料提交结果尚未确认，请先核对原提交。" type="warning" :closable="false" show-icon><el-button v-for="job in receiptPending" :key="job.key" type="text" :disabled="busy" @click="retryReceipt(job.deliveryId)">核对配送单 {{ job.deliveryId }} 的上次收料</el-button></el-alert>
    <section v-show="!['public','onsite'].includes(tab)" class="pm-list">
      <el-table :key="tab" v-loading="loading" :data="rows" border size="small" :empty-text="tab === 'demands' ? '暂无待配料需求' : '暂无符合条件的单据'">
        <el-table-column v-if="tab !== 'demands'" :prop="tab === 'delivery' ? 'delivery_no' : 'task_no'" :label="tab === 'delivery' ? '配送单号' : '配料单号'" min-width="185" />
        <el-table-column label="工单与产品" min-width="190"><template slot-scope="{row}"><span>{{ workOrder(row).work_order_no }}</span><small>{{ product(row) }}</small><small v-if="row.production_unit_no">生产编号：{{ row.production_unit_no }}</small></template></el-table-column>
        <el-table-column label="接收工序" min-width="115"><template slot-scope="{row}">{{ row.target_operation_name || row.target_operation_name_snapshot || row.production_location_name_snapshot || '—' }}</template></el-table-column>
        <el-table-column v-if="tab === 'demands'" label="待配物料" width="100"><template slot-scope="{row}">{{ row.material_count }} 种</template></el-table-column>
        <el-table-column v-if="tab === 'picking'" label="仓库" min-width="125"><template slot-scope="{row}">{{ row.warehouse && row.warehouse.warehouse_name }}</template></el-table-column>
        <el-table-column v-if="tab === 'delivery'" label="配送人" width="100"><template slot-scope="{row}">{{ row.delivery_user_name || '未分配' }}</template></el-table-column>
        <el-table-column v-if="tab !== 'demands'" label="状态" width="115"><template slot-scope="{row}"><el-tag size="small" :type="tagType(row.status)">{{ status(row.status, tab) }}</el-tag></template></el-table-column>
        <el-table-column v-if="tab === 'delivery'" label="收料结果" min-width="120"><template slot-scope="{row}"><span v-if="row.rejected_lines_count" class="pm-danger">有拒收</span><span v-else>{{ row.status === 'RECEIVED' ? '全部接收' : row.status === 'DELIVERED' ? '待确认' : '—' }}</span></template></el-table-column>
        <el-table-column label="操作" min-width="230"><template slot-scope="{row}"><div class="pm-actions">
          <el-button v-if="tab === 'demands' && $can('production.material_picking.create')" type="text" @click="openDraft(row)">配料</el-button>
          <template v-if="tab !== 'demands'"><el-button type="text" @click="openDetail(tab, row.id)">查看</el-button><template v-if="tab === 'delivery'"><el-button v-if="row.status === 'READY' && $can('production.material_delivery.dispatch')" type="text" @click="openDeliveryAction(row, 'dispatch')">发出</el-button><el-button v-if="row.status === 'READY' && $can('production.material_delivery.cancel')" type="text" class="pm-danger" @click="cancelDocument('delivery', row)">取消</el-button><el-button v-if="row.status === 'IN_TRANSIT' && $can('production.material_delivery.confirm')" type="text" @click="openDeliveryAction(row, 'deliver')">确认送达</el-button><el-button v-if="row.rejected_lines_count && $can('production.material_delivery.create')" type="text" @click="openRedelivery(row.id)">创建补送单</el-button></template></template>
        </div></template></el-table-column>
      </el-table>
      <el-pagination class="pm-pagination" :current-page.sync="query.page" :page-size.sync="query.per_page" :page-sizes="[10,20,50]" :total="total" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
    </section>

    <el-dialog title="新建配料单" :visible.sync="draft.visible" width="1060px" custom-class="production-material-dialog" append-to-body :close-on-click-modal="false" :before-close="closeWhenIdle">
      <template v-if="draft.target">
        <el-alert v-if="draft.error" :title="draft.error" type="warning" :closable="false" show-icon />
        <div class="pm-meta"><span>工单：{{ draft.target.work_order_no }}</span><span>产品：{{ draft.target.output_item_name }}</span><span>接收工序：{{ draft.target.target_operation_name }}</span></div>
        <div class="pm-inline-field"><label>仓库：</label><el-input :value="draft.warehouse.warehouse_name" size="small" readonly placeholder="请选择仓库" suffix-icon="el-icon-arrow-down" @click.native="chooseWarehouse" /></div>
        <material-lines :groups="draftDisplayGroups" mode="plan" editable @stock="chooseStock" @combine="combineDraftGroup" />
        <el-pagination v-if="draft.total > 20" class="pm-pagination" :current-page.sync="draft.page" :page-size="10" :total="draft.total" layout="total, prev, pager, next" @current-change="loadDraft" />
      </template>
      <span slot="footer" class="pm-footer"><span>共 {{ draft.total }} 种物料</span><span><el-button size="small" :disabled="busy" @click="draft.visible = false">取消</el-button><el-button v-if="draft.error" size="small" :disabled="busy" @click="refreshDraft">刷新库存</el-button><el-button type="success" size="small" :loading="busy" :disabled="!canCreate" @click="createPicking">生成配料单</el-button></span></span>
    </el-dialog>

    <el-dialog :visible.sync="detail.visible" width="1060px" custom-class="production-material-dialog" append-to-body :close-on-click-modal="false" :before-close="closeWhenIdle">
      <span slot="title">{{ detail.kind === 'delivery' ? '配送单详情' : '配料单详情' }} <el-tag v-if="detail.data" size="small" :type="tagType(detail.data.status)">{{ status(detail.data.status, detail.kind) }}</el-tag><el-tag v-if="detailHasRejection" size="small" type="warning">有拒收</el-tag></span>
      <el-alert v-if="detail.error" :title="detail.error" type="error" :closable="false" />
      <el-alert v-if="detailReceiptPending" title="提交结果尚未确认，请核对上次收料；重试将原样发送上次提交内容。" type="warning" :closable="false" />
      <el-alert v-if="detail.pendingDocumentAction" title="上次单据提交结果尚未确认，请先核对原提交。" type="warning" :closable="false"><el-button size="small" :loading="busy" @click="retryDocumentWrite">核对上次单据提交</el-button></el-alert>
      <el-alert v-if="detail.foreignDocumentPending" title="该单据还有未确认的历史提交，请使用原提交账号核对。" type="warning" :closable="false" />
      <div v-if="detail.loading" class="pm-empty">正在加载单据…</div>
      <template v-if="detail.data">
        <div class="pm-meta"><span>{{ detail.kind === 'delivery' ? '配送单：' : '配料单：' }}{{ detail.data.delivery_no || detail.data.task_no }}</span><span>工单：{{ workOrder(detail.data).work_order_no }}</span><span>仓库：{{ detailWarehouse }}</span><span>产品：{{ product(detail.data) }}</span><span>接收工序：{{ detail.data.target_operation_name_snapshot || detail.data.production_location_name_snapshot }}</span><span v-if="detail.kind === 'picking'">拣货人：{{ detail.data.assigned_picker_name || '未分配' }}</span><template v-else><span>配料单：{{ detail.data.picking_task && detail.data.picking_task.task_no }}</span><span>配送人：{{ detail.data.delivery_user_name || '未分配' }}</span><span>接收人：{{ detail.data.expected_receiver_name || '尚未接单' }}</span></template></div>
        <div v-if="detail.kind === 'picking' && !detail.data.public_preparation_task_id && detail.data.status === 'WAIT_PICK' && $can('production.material_picking.assign')" class="pm-inline-field"><label>拣货人：</label><el-input :value="detail.picker && personLabel(detail.picker)" size="small" readonly placeholder="请选择人员" /><el-button size="small" icon="el-icon-user" :disabled="busy || !!detail.laborPendingAction" @click="selectPerson('picker')">选择人员</el-button></div>
        <el-steps v-if="detail.kind === 'picking' && ['WAIT_PICK','PICKING','PICKED'].includes(detail.data.status)" :active="detail.data.status === 'WAIT_PICK' ? 0 : detail.data.status === 'PICKING' ? 1 : 3" finish-status="success" align-center class="pm-steps"><el-step title="待拣货" /><el-step title="拣货中" /><el-step title="已拣货" /></el-steps>
        <el-steps v-if="detail.kind === 'delivery' && detail.data.status !== 'CANCELLED'" :active="deliveryStep" finish-status="success" align-center class="pm-steps"><el-step title="已创建" :description="date(detail.data.created_at)" /><el-step title="已发出" :description="date(detail.data.departed_at)" /><el-step title="已送达" :description="date(detail.data.delivered_at)" /><el-step title="已确认" /></el-steps>
        <material-lines :groups="detail.groups" :mode="detailMode" :editable="canReceive || (detail.kind === 'picking' && !detail.data.public_preparation_task_id && detail.data.status === 'PICKING' && $can('production.material_picking.pick') && !receiptLocked)" :receipt-locked="receiptLocked" @serials="chooseSerials" @physicals="choosePhysicals" @receipt-serials="chooseReceiptSerials" />
        <ProductionLaborSummaryPanel v-if="detail.data.labor" :labor="detail.data.labor" :busy="detailClockLocked" @page="laborPage">
          <template slot="actions"><el-alert v-if="detail.laborPendingAction" title="上次计时结果尚未确认，请先核对原提交。" type="warning" :closable="false" /><div class="pm-related"><el-button v-if="detail.laborPendingAction" size="small" type="success" :loading="busy" @click="laborAction(detail.laborPendingAction)">核对上次计时</el-button><template v-else><el-button v-if="detail.data.labor.can_start && !(detail.kind === 'picking' && detail.data.status === 'WAIT_PICK')" size="small" type="success" :disabled="detailClockLocked" @click="laborAction('start')">开始本人计时</el-button><el-button v-if="detail.data.labor.can_pause" size="small" :disabled="detailClockLocked" @click="laborAction('pause')">暂停本人计时</el-button><el-button v-if="detail.data.labor.can_resume" size="small" type="success" :disabled="detailClockLocked" @click="laborAction('resume')">继续本人计时</el-button></template><el-button v-if="detail.kind === 'picking' && detail.data.public_preparation_task_id && $can('production.material_picking.view')" type="text" :disabled="busy" @click="openPublicLabor">查看公共配料工时</el-button></div><p v-if="!detail.data.public_preparation_task_id" class="pm-muted">{{ detail.kind === 'delivery' ? '确认送达时结束本人的活动计时。' : '确认拣货时结束本人的活动计时。' }}</p></template>
        </ProductionLaborSummaryPanel>
        <div v-if="detail.kind === 'picking' && !detail.data.public_preparation_task_id && detail.data.status === 'PICKING'" class="pm-remark"><label>备注：</label><el-input v-model="detail.reason" size="small" maxlength="500" :disabled="receiptLocked" placeholder="请输入备注（选填）" /><p><i class="el-icon-info" /> 确认拣货后按实拣数量扣减库存。</p></div>
        <el-alert v-if="detailHasRejection" class="pm-rejection" :title="rejectionSummary" type="warning" :closable="false" show-icon />
        <div v-if="detail.kind === 'picking' && (detail.data.deliveries || []).length" class="pm-related"><b>配送记录</b><el-button v-for="delivery in detail.data.deliveries" :key="delivery.id" type="text" @click="openDetail('delivery', delivery.id)">{{ delivery.delivery_no }} · {{ status(delivery.status, 'delivery') }}</el-button></div>
        <el-collapse class="pm-events" @change="loadEvents"><el-collapse-item title="操作记录" name="events"><el-table :data="detail.events" size="small" empty-text="暂无操作记录"><el-table-column label="时间" min-width="150"><template slot-scope="{row}">{{ date(row.occurred_at) }}</template></el-table-column><el-table-column prop="operator_name" label="操作人" width="100" /><el-table-column label="操作" width="110"><template slot-scope="{row}">{{ eventName(row.action) }}</template></el-table-column><el-table-column prop="reason" label="说明" min-width="170" /></el-table><el-pagination :current-page.sync="detail.eventPage" :total="detail.eventTotal" :page-size="10" layout="total, prev, pager, next" @current-change="loadEvents" /></el-collapse-item></el-collapse>
      </template>
      <span slot="footer" class="pm-footer"><span><el-button v-if="detail.data && detail.kind === 'picking' && !detail.data.public_preparation_task_id && ['WAIT_PICK','PICKING'].includes(detail.data.status) && $can('production.material_picking.cancel')" type="text" class="pm-danger" :disabled="busy || !!detail.laborPendingAction" @click="cancelDocument('picking', detail.data)">取消配料</el-button><el-button v-if="detail.data && detail.kind === 'delivery' && detail.data.status === 'READY' && $can('production.material_delivery.cancel')" type="text" class="pm-danger" :disabled="busy || !!detail.laborPendingAction" @click="cancelDocument('delivery', detail.data)">取消配送</el-button></span><span>
        <el-button size="small" :disabled="busy" @click="detail.visible = false">关闭</el-button>
        <template v-if="detail.data && detail.kind === 'picking'"><el-button v-if="!detail.data.public_preparation_task_id && detail.data.status === 'WAIT_PICK' && $can('production.material_picking.assign')" size="small" type="success" :disabled="!detail.picker || !!detail.laborPendingAction" :loading="busy" @click="assignPicker">{{ detail.data.assigned_picker_legacy_id ? '重新分配' : '分配拣货人' }}</el-button><el-button v-if="!detail.data.public_preparation_task_id && detail.data.status === 'WAIT_PICK' && detail.data.assigned_picker_legacy_id && $can('production.material_picking.pick')" size="small" type="success" :disabled="!!detail.laborPendingAction" :loading="busy" @click="pickingAction('start')">开始拣货</el-button><el-button v-if="!detail.data.public_preparation_task_id && detail.data.status === 'PICKING' && $can('production.material_picking.pick')" type="success" size="small" :loading="busy" :disabled="!canConfirmPicking" @click="confirmPicking">确认拣货</el-button><el-button v-if="canPrepareDelivery" size="small" type="success" :loading="busy" @click="prepareDelivery">创建配送单</el-button></template>
        <template v-if="detail.data && detail.kind === 'delivery'"><el-button v-if="detail.data.status === 'READY' && $can('production.material_delivery.dispatch')" type="success" size="small" :loading="busy" :disabled="!!detail.laborPendingAction" @click="dispatchFromDetail">发出</el-button><el-button v-if="detail.data.status === 'IN_TRANSIT' && $can('production.material_delivery.confirm')" type="success" size="small" :loading="busy" :disabled="!!detail.laborPendingAction" @click="deliveryAction('deliver')">确认送达</el-button><el-button v-if="detailHasRedelivery && $can('production.material_delivery.create')" type="success" size="small" :loading="busy" :disabled="!!detail.laborPendingAction" @click="openRedelivery(detail.data.id)">创建补送单</el-button><el-button v-if="canReceive || detailReceiptPending" size="small" :disabled="busy || detail.loading" @click="openDetail('delivery', detail.data.id, true)">刷新单据</el-button><el-button v-if="detailReceiptPending" type="success" size="small" :loading="busy" :disabled="detail.loading || !!detail.laborPendingAction" @click="retryReceipt(detail.data.id)">核对上次收料</el-button><el-button v-else-if="canReceive" type="success" size="small" :loading="busy" :disabled="receiptLocked || !hasReceiptValues" @click="confirmReceipt">确认本次收料</el-button></template>
      </span></span>
    </el-dialog>

    <el-dialog :title="send.redelivery ? '创建补送单' : '创建配送单'" :visible.sync="send.visible" width="1060px" custom-class="production-material-dialog" append-to-body :close-on-click-modal="false" :before-close="closeWhenIdle">
      <template v-if="send.task">
        <el-alert v-if="send.error" :title="send.error" type="error" :closable="false" />
        <div class="pm-meta"><span v-if="send.redelivery">原配送单：{{ send.source.delivery_no }}</span><span>配料单：{{ send.task.task_no }}</span><span>工单：{{ workOrder(send.task).work_order_no }}</span><span>仓库：{{ send.task.warehouse && send.task.warehouse.warehouse_name }}</span><span>接收工序：{{ send.targetName }}</span><span>产品：{{ product(send.task) }}</span></div>
        <div v-if="send.targetOptions.length > 1" class="pm-inline-field"><label>生产目标：</label><el-select v-model="send.target" size="small" @change="selectDeliveryTarget"><el-option v-for="target in send.targetOptions" :key="target.key" :value="target.key" :label="target.name" /></el-select></div>
        <div class="pm-inline-field"><label>配送人：</label><el-input :value="send.person && personLabel(send.person)" size="small" readonly placeholder="请选择人员" /><el-button size="small" icon="el-icon-user" @click="selectPerson('delivery')">选择人员</el-button></div>
        <material-lines :groups="send.groups" mode="send" :redelivery="send.redelivery" editable @serials="chooseSerials" />
        <div class="pm-inline-field pm-remark"><label>{{ send.redelivery ? '处理说明：' : '备注：' }}</label><el-input v-model="send.remark" size="small" maxlength="2000" placeholder="请输入说明（选填）" /></div>
      </template>
      <span slot="footer" class="pm-footer"><span>本次配送 {{ send.groups.filter(group => group.sources.some(source => Number(source.quantity) > 0)).length }} 种物料</span><span><el-button size="small" :disabled="busy" @click="send.visible = false">取消</el-button><el-button size="small" type="success" :loading="busy" :disabled="!canSend" @click="createDelivery">{{ send.redelivery ? '创建补送单' : '创建配送单' }}</el-button></span></span>
    </el-dialog>
    <stock-selector ref="stock" @confirm="acceptStock" /><option-picker ref="options" /><material-procurement-dialog ref="procurement" /><cutting-group-picker ref="cuttingGroups" @confirm="acceptCombinedDraft" />
  </section>
</template>

<script>
import { erpDateTime } from '../../../utils/dateTime'
import { materialWorkspace, materialDemands, materialList, materialDetail, materialCommand, materialEvents, materialLaborCommand } from '@/api/erp/production-materials'
import { materialWrite, pendingMaterialWrite, pendingMaterialWrites } from '@/api/erp/public-materials'
import MaterialLines from './ProductionMaterialLines.vue'
import StockSelector from './ProductionStockSelector.vue'
import OptionPicker from './ProductionMaterialOptionPicker.vue'
import OnsiteMaterialCollectionPanel from './OnsiteMaterialCollectionPanel.vue'
import PublicPreparationPanel from './PublicMaterialPreparationPanel.vue'
import MaterialProcurementDialog from './MaterialProcurementDialog.vue'
import { cuttingPickingLine, validCuttingGroup, combinedCuttingDemand } from '@/utils/production-cutting-allocation.mjs'
import CuttingGroupPicker from './ProductionCuttingGroupPicker.vue'
import ProductionLaborSummaryPanel from '../production/ProductionLaborSummaryPanel.vue'
const PICK = { WAIT_PICK: '待拣货', PICKING: '拣货中', PICKED: '已拣货', WAIT_DELIVERY: '待配送', DELIVERING: '配送中', DELIVERED: '已送达', PARTIALLY_RECEIVED: '部分收料', RECEIVED: '已收齐', CANCELLED: '已取消' }
const DELIVERY = { READY: '待发出', IN_TRANSIT: '配送中', DELIVERED: '待收料', RECEIVED: '已确认', CANCELLED: '已取消' }
const blankDraft = () => ({ visible: false, target: null, warehouse: {}, groups: [], groupMap: {}, total: 0, page: 1, error: '' })
const blankDetail = () => ({ visible: false, loading: false, data: null, kind: 'picking', laborPage: 1, laborPendingAction: '', pendingDocumentAction: '', foreignDocumentPending: false, groups: [], picker: null, reason: '', error: '', events: [], eventPage: 1, eventTotal: 0 })
const blankSend = () => ({ visible: false, task: null, groups: [], allGroups: [], target: '', targetName: '', targetOptions: [], person: null, redelivery: false, source: null, remark: '', error: '' })
export default {
  components: { MaterialLines, StockSelector, OptionPicker, PublicPreparationPanel, MaterialProcurementDialog, OnsiteMaterialCollectionPanel, CuttingGroupPicker, ProductionLaborSummaryPanel },
  data: () => ({ tab: 'public', rows: [], total: 0, query: { keyword: '', status: '', page: 1, per_page: 10 }, loading: false, busy: false, error: '', receiptPending: [], listSequence: 0, detailSequence: 0, draftSequence: 0, draft: blankDraft(), detail: blankDetail(), send: blankSend() }),
  computed: {
    recipientOnly() { return !this.$can('production.material_delivery.view') && this.$can('production.material_receipt.view') },
    statuses() { return this.tab === 'delivery' ? this.recipientOnly ? { DELIVERED: DELIVERY.DELIVERED, RECEIVED: DELIVERY.RECEIVED } : DELIVERY : PICK },
    draftChosen() { return Object.values(this.draft.groupMap).filter(group => group.sources.length) },
    draftDisplayGroups() { return this.draft.groups.filter(group=>!group.combinedMemberOf) },
    canCreate() { return !!this.draft.warehouse.id && this.draftChosen.length > 0 && this.draftChosen.every(group => validCuttingGroup(group.demand, group.sources)) },
    detailMode() { return this.detail.kind === 'delivery' ? 'receipt' : this.detail.data && this.detail.data.status === 'WAIT_PICK' ? 'assign' : 'pick' },
    detailWarehouse() { const data = this.detail.data || {}; const task = this.detail.kind === 'delivery' ? data.picking_task : data; return task && task.warehouse && task.warehouse.warehouse_name },
    detailHasRejection() { return this.detail.kind === 'delivery' && this.detail.groups.some(group => group.sources.some(row => row.rejected > 0)) },
    detailHasRedelivery() { return this.detailHasRejection && (this.detail.data.lines || []).some(line => Number(line.remaining_redelivery_qty) > 0) },
    rejectionSummary() { const pending = this.detail.groups.map(group => { const qty = group.sources.reduce((sum, row) => sum + Number(row.remainingRedelivery || 0), 0); return qty > 0 ? `${group.name} ${this.number(qty)} ${group.unit}` : '' }).filter(Boolean).join('，'); return pending ? '待补送：' + pending : '拒收物料已安排补送。' },
    deliveryStep() { return ({ READY: 1, IN_TRANSIT: 2, DELIVERED: 3, RECEIVED: 4 })[(this.detail.data || {}).status] || 0 },
    canReceive() { return this.detail.kind === 'delivery' && this.detail.data?.status === 'DELIVERED' && this.$can('production.material_receipt.confirm') && (this.detail.data.allowed_actions || []).includes('delivery.receive') },
    detailReceiptPending() { return this.detail.kind === 'delivery' && this.receiptPending.some(job => job.deliveryId === Number(this.detail.data?.id)) },
    receiptLocked() { return this.busy || this.detail.loading || this.detailReceiptPending || !!this.detail.laborPendingAction || !!this.detail.pendingDocumentAction || this.detail.foreignDocumentPending },
    detailLaborKind() { return this.detail.kind === 'delivery' ? 'delivery' : 'picking_task' },
    detailClockLocked() { return this.receiptLocked || this.hasPendingDocumentWrite() },
    hasReceiptValues() { return this.detail.groups.some(group => group.sources.some(row => Number(row.receiptAccepted) > 0 || Number(row.receiptRejected) > 0)) },
    canConfirmPicking() { const rows = this.detail.groups.flatMap(group => group.sources); return !this.receiptLocked && rows.some(row => Number(row.quantity) > 0) && rows.every(row => this.validQty(row.quantity, row.planned, true) && (!row.serialRequired || Number(row.quantity) === (row.serials || []).length) && (!row.physicalRequired || Number(row.quantity) === (row.physicals || []).length) && (!row.cuttingAllocation || Number.isInteger(Number(row.quantity)))) },
    canPrepareDelivery() { return !this.receiptLocked && this.$can('production.material_delivery.create') && ['PICKED','WAIT_DELIVERY','DELIVERING','DELIVERED','PARTIALLY_RECEIVED'].includes((this.detail.data || {}).status) && (this.detail.data.lines || []).some(row => Number(row.remaining_delivery_qty) > 0) },
    canSend() { const rows = this.send.groups.flatMap(group => group.sources); return rows.some(row => Number(row.quantity) > 0) && rows.every(row => this.validQty(row.quantity, row.available, true) && (!row.serialRequired || Number(row.quantity) === (row.serials || []).length)) }
  },
  created() { this.refreshReceiptPending(); if (!this.$can('production.material_picking.view')) this.tab = this.$can('production.material_receipt.view') ? 'onsite' : this.$can('production.material_requirement.view') ? 'demands' : 'delivery'; this.load() },
  beforeDestroy() { this.listSequence++; this.detailSequence++; this.draftSequence++ },
  methods: {
    combineDraftGroup(group) { if(!this.draft.warehouse.id) return this.$message.warning('请先选择仓库'); this.$refs.cuttingGroups.open(group.demand,group.demand.cutting_member_requirements || []) },
    async acceptCombinedDraft({owner,members}) {
      const ids=members.map(row=>Number(row.id)); const entries=Object.values(this.draft.groupMap).filter(group=>ids.includes(Number(group.demand.id)) || group.demand.cutting_member_requirements?.some(row=>ids.includes(Number(row.id))))
      if(entries.some(group=>group.sources.length)) { try { await this.$confirm('合并将替换这些明细已选的库存来源，请重新选择实际原料。','合并下料明细') } catch(_) { return } }
      entries.forEach(group=>{ (group.demand.cutting_member_requirements || []).forEach(member=>{const previous=this.draft.groupMap[member.id];if(previous)this.$set(previous,'combinedMemberOf',null)});group.sources=[];group.demand=group.demand.cutting_member_requirements?.find(row=>Number(row.id)===Number(group.demand.id)) || group.demand })
      let group=this.draft.groupMap[owner.id]
      if(!group) { group={key:String(owner.id),name:owner.item_name,code:owner.item_code,spec:owner.spec,unit:owner.unit_name,sources:[],expanded:false};this.$set(this.draft.groupMap,owner.id,group) }
      group.demand=combinedCuttingDemand(owner,members);group.required=Number(group.demand.remaining_to_prepare);group.remaining=group.required;group.allocated=0;this.$set(group,'combinedMemberOf',null)
      members.forEach(member=>{ if(Number(member.id)===Number(owner.id))return;const previous=this.draft.groupMap[member.id];if(previous)this.$set(previous,'combinedMemberOf',owner.id) })
      if(!this.draft.groups.some(row=>Number(row.demand.id)===Number(owner.id)))this.draft.groups.unshift(group)
      this.$refs.stock.open(group.demand,this.draft.warehouse,[])
    },
    number(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) },
    date(value) { return value ? erpDateTime(value, 16) : '—' },
    status(value, kind) { return (kind === 'delivery' ? DELIVERY : PICK)[value] || value },
    tagType(value) { return ['RECEIVED','PICKED'].includes(value) ? 'success' : ['CANCELLED'].includes(value) ? 'info' : ['PICKING','IN_TRANSIT','DELIVERING'].includes(value) ? '' : 'warning' },
    personLabel(person) { return person.nickname || person.username || person.name || '' },
    workOrder(row) { return row.work_order || row },
    product(row) { const wo = this.workOrder(row); return wo.output_item_name || (wo.output_item && wo.output_item.item_name) || '—' },
    validQty(value, maximum, zero) { return /^\d+(\.\d{1,8})?$/.test(String(value)) && Number(value) >= (zero ? 0 : 0.00000001) && Number(value) <= Number(maximum) + 0.00000001 },
    eventName(action) { return ({ create: '创建', assign: '分配拣货人', start: '开始拣货', confirm: '确认拣货', cancel: '取消', dispatch: '发出', deliver: '确认送达', receive: '收料确认' })[action] || action },
    closeWhenIdle(done) { if (!this.busy) done() },
    switchTab() { this.query = { keyword: '', status: '', page: 1, per_page: 10 }; this.load() },
    search() { this.query.page = 1; this.load() },
    reset() { this.query.keyword = ''; this.query.status = ''; this.search() },
    async load() { if (['public','onsite'].includes(this.tab)) return; const sequence = ++this.listSequence; this.loading = true; this.error = ''; try { const { data } = await (this.tab === 'demands' ? materialWorkspace('targets', this.query) : materialList(this.tab, this.query)); if (sequence === this.listSequence) { this.rows = data.data; this.total = data.total } } catch (e) { if (sequence === this.listSequence) this.error = e.userMessage } finally { if (sequence === this.listSequence) this.loading = false } },
    async openDraft(target) { this.draft = { ...blankDraft(), visible: true, target }; await this.loadDraft() },
    async loadDraft() {
      const sequence = ++this.draftSequence; const target = this.draft.target
      try { const { data } = await materialDemands({ work_order_id: target.work_order_id, production_target_type: target.production_target_type, production_target_id: target.production_target_id, page: this.draft.page, per_page: 10 }); if (sequence !== this.draftSequence) return; this.draft.total = data.total
        this.draft.groups = data.data.map(row => { const previous = this.draft.groupMap[row.id]; const group = { key: String(row.id), demand: previous && previous.demand.cutting_member_requirements ? previous.demand : row, combinedMemberOf: previous && previous.combinedMemberOf || null, name: row.item_name, code: row.item_code, spec: row.spec, unit: row.unit_name, required: Number(row.required_qty), allocated: Number(row.required_qty) - Number(row.remaining_to_prepare), remaining: Number(row.remaining_to_prepare), sources: previous ? previous.sources : [], expanded: previous ? previous.expanded : false }; this.$set(this.draft.groupMap, row.id, group); this.draft.target.work_order_version = row.work_order_version; return group })
      } catch (e) { if (sequence === this.draftSequence) this.draft.error = e.userMessage }
    },
    chooseWarehouse() { this.$refs.options.open('warehouses', '选择仓库', {}, async warehouse => { if (this.draft.warehouse.id && this.draft.warehouse.id !== warehouse.id && this.draftChosen.length) { try { await this.$confirm('切换仓库将清除已选库存来源，是否继续？', '切换仓库', { type: 'warning' }) } catch (_) { return } Object.values(this.draft.groupMap).forEach(group => { group.sources = [] }) } this.draft.warehouse = warehouse }) },
    chooseStock(group) { if (!this.draft.warehouse.id) { this.$message.warning('请先选择仓库'); return } this.$refs.stock.open(group.demand, this.draft.warehouse, group.sources) },
    acceptStock({ demandId, sources }) { const group = this.draft.groupMap[demandId]; if (!group) return; group.sources = sources; group.expanded = sources.length > 1; this.draft.error = '' },
    async refreshDraft() { this.draft.error = ''; await this.loadDraft(); try { for (const group of this.draftChosen) { const { data } = await materialWorkspace('sources', { target_material_requirement_id: group.demand.id, target_material_requirement_ids:group.demand.cutting_member_requirements?.map(row=>row.id), warehouse_id: this.draft.warehouse.id, ids: group.sources.map(row => row.id), per_page: 100 }); group.sources = group.sources.map(row => ({ ...row, ...(data.data.find(fresh => fresh.id === row.id) || { picking_available_qty: 0 }), quantity: row.quantity, cutting_members:row.cutting_members, cutting_member_requirements:row.cutting_member_requirements, physical_material_ids:row.physical_material_ids, physicals:row.physicals, cutting_source_plan:row.cutting_source_plan, cutting_plan_checked:row.cutting_source_plan ? false : row.cutting_plan_checked })) } } catch (e) { this.draft.error = e.userMessage } },
    async createPicking() { const target = this.draft.target; const body = { work_order_id: target.work_order_id, expected_version: target.work_order_version, warehouse_id: this.draft.warehouse.id, lines: this.draftChosen.flatMap(group => group.sources.map(row => cuttingPickingLine(group.demand, row))) }; await this.execute('picking-new-' + target.work_order_id, 'picking', null, '', body, async result => { this.draft.visible = false; this.tab = 'picking'; this.reset(); await this.openDetail('picking', result.id) }, message => { this.draft.error = message }) },
    async openDetail(kind, id, preserveReceipt = false) {
      const previous = preserveReceipt && kind === this.detail.kind && Number(this.detail.data?.id) === Number(id) ? this.detail : null
      const sequence = ++this.detailSequence; this.detail = previous ? { ...previous, loading: true, error: '' } : { ...blankDetail(), kind, visible: true, loading: true }
      try {
        const { data } = await materialDetail(kind, id, { labor_page: this.detail.laborPage, labor_per_page: 10 }); if (sequence !== this.detailSequence) return
        const groups = this.groupsFor(data.data, kind)
        const sameVersion = previous && Number(previous.data.business_version) === Number(data.data.business_version)
        if (sameVersion) { const drafts = new Map(previous.groups.flatMap(group => group.sources).map(row => [row.id, row])); const keys = kind === 'picking' ? ['quantity','serials','physicals'] : ['receiptAccepted','receiptRejected','receiptReason','receiptAcceptedSerials','receiptRejectedSerials']; groups.forEach(group => group.sources.forEach(row => { const old = drafts.get(row.id); if (old) keys.forEach(key => { row[key] = old[key] }) })) }
        this.detail.data = data.data; this.detail.groups = groups
        this.refreshLaborPending()
        this.refreshDocumentPending()
        if (previous && !sameVersion) this.detail.error = '单据已变化，本次数量和序列号已清空，请重新核对。'
        if (data.data.assigned_picker_legacy_id) this.detail.picker = { id: data.data.assigned_picker_legacy_id, nickname: data.data.assigned_picker_name }
      } catch (e) { if (sequence === this.detailSequence) this.detail.error = e.userMessage }
      finally { if (sequence === this.detailSequence) this.detail.loading = false; this.refreshReceiptPending() }
    },
    refreshLaborPending() { const id = this.detail.data?.id; this.detail.laborPendingAction = id ? ['start', 'pause', 'resume'].find(action => pendingMaterialWrite(`labor-${this.detailLaborKind}-${id}-${action}`)) || '' : '' },
    materialActorScope() { try { const user = JSON.parse(localStorage.getItem('erp_user') || '{}'); return `${process.env.VUE_APP_BASE_API}:${Number(user.legacy_id || user.id || 0)}` } catch (_) { return '' } },
    refreshDocumentPending() {
      const id = this.detail.data?.id; const actor = this.materialActorScope()
      const actions = id ? ['assign', 'start', 'confirm', 'dispatch', 'deliver', 'cancel'].filter(action => sessionStorage.getItem(`erp-material-command:${this.detail.kind}-${id}-${action}`)) : []
      const owned = action => sessionStorage.getItem(`erp-material-command-actor:${this.detail.kind}-${id}-${action}`) === actor
      this.detail.pendingDocumentAction = actions.find(owned) || ''; this.detail.foreignDocumentPending = actions.some(action => !owned(action))
    },
    retryDocumentWrite() { const detail = this.detail; const id = detail.data?.id; const action = detail.pendingDocumentAction; if (!id || !action || this.busy) return; return this.execute(`${detail.kind}-${id}-${action}`, detail.kind, id, action, {}, async () => { if (this.detail === detail) await this.openDetail(detail.kind, id); this.load() }, message => { if (this.detail === detail) detail.error = message }) },
    hasPendingDocumentWrite() { const id = this.detail.data?.id; return !!id && Object.keys(sessionStorage).some(key => key.startsWith(`erp-material-command:${this.detail.kind}-${id}-`) && sessionStorage.getItem(key)) },
    laborPage(page) { if (this.detailClockLocked) return; this.detail.laborPage = page; return this.openDetail(this.detail.kind, this.detail.data.id, true) },
    async openPublicLabor() { if (this.busy) return; const id = this.detail.data?.public_preparation_task_id; if (!id) return; this.detail.visible = false; this.tab = 'public'; await this.$nextTick(); if (this.tab === 'public') this.$refs.publicPreparation.openDetail(id) },
    async laborAction(action) {
      const detail = this.detail; const document = detail.data; const kind = this.detailLaborKind
      if (this.busy || detail.loading || !document || this.detailReceiptPending || this.hasPendingDocumentWrite() || (detail.laborPendingAction && action !== detail.laborPendingAction)) return
      if (!detail.laborPendingAction && !document.labor?.[`can_${action}`]) return
      const id = document.id; const sequence = this.detailSequence
      const same = () => this.detail === detail && detail.visible && sequence === this.detailSequence
      this.busy = true; detail.error = ''
      try {
        const { data } = await materialWrite(`labor-${kind}-${id}-${action}`, { expected_version: document.business_version }, body => materialLaborCommand(kind, id, action, body))
        if (!same()) return
        const patch = data.data
        if (Number(patch.id) !== Number(id)) throw new Error('计时结果与当前单据不一致，请重新打开核对。')
        if (Number(patch.business_version) < Number(detail.data.business_version)) await this.openDetail(detail.kind, id, true)
        else { Object.assign(detail.data, { business_version: patch.business_version, labor: patch.labor }); detail.laborPage = Number(patch.labor?.sessions?.current_page || 1) }
        this.$message.success('本人计时已更新'); this.load()
      } catch (e) { if (same()) detail.error = e.userMessage || e.message || '计时结果尚未确认，请核对上次提交。' }
      finally { if (this.detail.data?.id === id && this.detailLaborKind === kind) this.refreshLaborPending(); this.busy = false }
    },
    groupsFor(document, kind) {
      const map = {}
      ;(document.lines || []).forEach(line => { const pick = kind === 'delivery' ? line.picking_task_line || {} : line; const item = pick.component_item || {}; const requirement = line.requirement || pick.requirement || {}; const key = [pick.material_requirement_id, pick.production_target_type, pick.production_target_id, pick.material_supply_rule_snapshot_id].join(':'); const inventory = pick.inventory_balance || {}
        if (!map[key]) map[key] = { key, name: item.item_name || requirement.component_item_name_snapshot, code: item.item_code || requirement.component_item_code_snapshot, spec: item.spec, unit: pick.unit_name_snapshot || line.unit_name_snapshot, targetKey: [pick.production_target_type, pick.production_target_id, pick.target_routing_operation_id_snapshot].join(':'), targetName: pick.target_operation_name_snapshot, expanded: false, sources: [] }
        const serialIds = (pick.serial_snapshot || {}).inventory_serial_ids || []; const receiptSerialIds = kind === 'delivery' ? ((line.serial_snapshot || {}).inventory_serial_ids || []).map(Number) : []; const cuttingAllocation = (pick.serial_snapshot || {}).cutting_allocation || null; const physicalIds = (pick.serial_snapshot || {}).physical_material_ids || []; const received = Number(line.received_qty || 0); const rejected = Number(line.rejected_qty || 0)
        map[key].sources.push({ id: line.id, pickLineId: pick.id, location: inventory.location, batch_no: line.batch_no, planned: Number(pick.planned_pick_qty), picked: Number(pick.actual_pick_qty), quantity: document.status === 'PICKING' ? Number(pick.actual_pick_qty) || Number(pick.planned_pick_qty) : Number(pick.actual_pick_qty), allocated: Number(pick.allocated_delivery_qty || 0), available: Number(pick.remaining_delivery_qty || 0), delivered: Number(line.delivery_qty || 0), received, rejected, pending: Math.max(0, Number(line.delivery_qty || 0) - received - rejected), rejectReason: (line.reject_reasons || []).join('；'), remainingRedelivery: Number(line.remaining_redelivery_qty || 0), cuttingAllocation, physicalRequired: !!cuttingAllocation && cuttingAllocation.unit_kind === 'sheet', physicals: physicalIds.map(id => ({ id, physical_no: String(id) })), serialRequired: (pick.serial_control_type !== 'none' && !!pick.serial_control_type) || receiptSerialIds.length > 0, serials: serialIds.map(id => ({ id, serial_no: String(id) })), availableSerialIds: pick.available_delivery_serial_ids || serialIds, availableReceiptSerialIds: receiptSerialIds, receiptAccepted: '0', receiptRejected: '0', receiptReason: '', receiptAcceptedSerials: [], receiptRejectedSerials: [], original: line })
      }); return Object.values(map).map(group => ({ ...group, expanded: group.sources.length > 1 || (kind === 'delivery' && (document.allowed_actions || []).includes('delivery.receive')) }))
    },
    refreshReceiptPending() { this.receiptPending = pendingMaterialWrites('delivery-').filter(job => /^delivery-\d+-receive$/.test(job.key)).map(job => ({ ...job, deliveryId: Number(job.key.split('-')[1]) })) },
    chooseReceiptSerials({ source, disposition }) {
      if (!this.canReceive || this.receiptLocked || !['accepted','rejected'].includes(disposition)) return
      const document = this.detail.data; const sequence = this.detailSequence; const field = disposition === 'accepted' ? 'receiptAcceptedSerials' : 'receiptRejectedSerials'
      this.$refs.options.open('receipt-serials', disposition === 'accepted' ? '选择本次实收序列号' : '选择本次拒收序列号', { delivery_id: document.id, delivery_line_id: source.id }, rows => {
        if (sequence !== this.detailSequence || !this.canReceive || this.receiptLocked || Number(this.detail.data?.id) !== Number(document.id)) return
        const used = new Set(this.detail.groups.flatMap(group => group.sources).flatMap(row => row === source ? row[disposition === 'accepted' ? 'receiptRejectedSerials' : 'receiptAcceptedSerials'] : row.receiptAcceptedSerials.concat(row.receiptRejectedSerials)).map(row => Number(row.id)))
        if (rows.some(row => !source.availableReceiptSerialIds.includes(Number(row.id)) || used.has(Number(row.id)))) return this.$message.warning('序列号不属于本行，或已用于本次实收、拒收，请重新核对。')
        const previous = new Map(source[field].map(row => [Number(row.id), row])); source[field] = rows.map(row => ({ ...row, reason: previous.get(Number(row.id))?.reason || '' }))
        source.receiptAccepted = String(source.receiptAcceptedSerials.length); source.receiptRejected = String(source.receiptRejectedSerials.length)
      }, source[field])
    },
    receiptLines() {
      const lines = []; const used = new Set()
      this.detail.groups.forEach(group => group.sources.forEach(row => {
        const fail = message => { throw new Error(`${group.name || '物料'}：${message}`) }
        if (!group.unit) fail('计量单位缺失，请核对资料。')
        if (!this.validQty(row.receiptAccepted, row.pending, true) || !this.validQty(row.receiptRejected, row.pending, true)) fail('请输入不超过待确认数量、最多8位小数的非负实收和拒收数量。')
        const accepted = Number(row.receiptAccepted); const rejected = Number(row.receiptRejected)
        if (accepted + rejected > row.pending + 0.00000001) fail('本次实收与拒收合计超过待确认数量。')
        if (!accepted && !rejected) return
        let reason = String(row.receiptReason || '').trim(); const reasons = {}; const acceptedIds = row.receiptAcceptedSerials.map(serial => Number(serial.id)); const rejectedIds = row.receiptRejectedSerials.map(serial => Number(serial.id))
        if (row.serialRequired) {
          if (acceptedIds.length !== accepted || rejectedIds.length !== rejected) fail('实收和拒收数量必须分别与真实序列号数量一致。')
          acceptedIds.concat(rejectedIds).forEach(id => { if (!Number.isInteger(id) || id <= 0 || !row.availableReceiptSerialIds.includes(id) || used.has(id)) fail('序列号不属于本行或重复处理。'); used.add(id) })
          row.receiptRejectedSerials.forEach(serial => { const text = String(serial.reason || '').trim(); if (!text || text.length > 500) fail('请为每个拒收序列号填写不超过500字的原因。'); reasons[Number(serial.id)] = text })
          reason = row.receiptRejectedSerials.map(serial => `${serial.serial_no}：${reasons[Number(serial.id)]}`).join('；'); if (reason.length > 500) reason = `共${rejected}个序列号拒收，详见逐件拒收原因`
        }
        if (rejected && (!reason || reason.length > 500)) fail('存在拒收时请填写不超过500字的拒收原因。')
        lines.push({ delivery_line_id: row.id, accepted_qty: accepted, rejected_qty: rejected, reject_reason: reason, accepted_serial_ids: acceptedIds, rejected_serial_ids: rejectedIds, ...(row.serialRequired ? { rejected_serial_reasons: reasons } : {}) })
      }))
      if (!lines.length) throw new Error('请填写本次实收或拒收数量。')
      return lines
    },
    async confirmReceipt() { if (!this.canReceive || this.receiptLocked) return; try { await this.submitReceipt(this.detail.data.id, { expected_version: this.detail.data.business_version, lines: this.receiptLines() }) } catch (e) { this.detail.error = e.message } },
    async retryReceipt(id) { if (this.busy || !this.$can('production.material_receipt.confirm')) return; if (!pendingMaterialWrite(`delivery-${id}-receive`)) { this.refreshReceiptPending(); return } await this.submitReceipt(id, {}) },
    async submitReceipt(id, payload) {
      if (this.busy) return
      const sequence = this.detailSequence; const showDetail = !this.detail.visible || Number(this.detail.data?.id) === Number(id); const sameContext = () => sequence === this.detailSequence && !this._isDestroyed
      this.busy = true; this.error = ''; if (showDetail) this.detail.error = ''
      let confirmed = false
      try {
        const { data, recoveredCommand } = await materialWrite(`delivery-${id}-receive`, payload, body => materialCommand('delivery', id, 'receive', body)); confirmed = true; this.refreshReceiptPending()
        if (!sameContext()) return
        this.$message.success(recoveredCommand ? '已核对上次收料提交，请核对单据。' : data.message || '收料已确认。')
        if (showDetail) { const refresh = this.openDetail('delivery', id); const refreshSequence = this.detailSequence; await refresh; if (refreshSequence === this.detailSequence && this.detail.error) this.detail.error = '收料已确认，详情刷新失败：' + this.detail.error }
        await this.load()
        if (this.error) this.error = '收料已确认，列表刷新失败：' + this.error
      } catch (e) { if (sameContext()) { const message = confirmed ? '收料已确认，但单据刷新失败，请重新打开同一配送单核对。' : e.userMessage || e.message || '提交结果尚未确认，请核对上次收料。'; if (showDetail) this.detail.error = message; else this.error = message } }
      finally { this.refreshReceiptPending(); this.busy = false }
    },
    selectPerson(target) { this.$refs.options.open('people', target === 'picker' ? '选择拣货人' : '选择配送人', {}, person => { if (target === 'picker') this.detail.picker = person; else this.send.person = person }) },
    async assignPicker() { await this.pickingAction('assign', { assigned_picker_legacy_id: this.detail.picker.id }) },
    async pickingAction(action, extra = {}) { const task = this.detail.data; await this.execute('picking-' + task.id + '-' + action, 'picking', task.id, action, { expected_version: task.business_version, ...extra }, async () => { await this.openDetail('picking', task.id); this.load() }, message => { this.detail.error = message }) },
    async confirmPicking() { await this.pickingAction('confirm', { reason: this.detail.reason, lines: this.detail.groups.flatMap(group => group.sources.map(row => ({ picking_task_line_id: row.id, actual_pick_qty: Number(row.quantity), serial_ids: row.serialRequired ? row.serials.map(serial => serial.id) : undefined, physical_material_ids: row.physicalRequired ? row.physicals.map(physical => physical.id) : undefined }))) }) },
    choosePhysicals(source) { const task = this.detail.data; this.$refs.options.open('physicals', '选择本次领用原材料', { picking_task_id: task.id, picking_task_line_id: source.pickLineId }, rows => { source.physicals = rows; source.quantity = String(rows.length) }, source.physicals || []) },
    chooseSerials(source) { const task = this.send.visible ? this.send.task : this.detail.data; this.$refs.options.open('serials', '选择序列号', { picking_task_id: task.id, picking_task_line_id: source.pickLineId, source_delivery_id: this.send.visible && this.send.redelivery ? this.send.source.id : undefined }, rows => { const allowed = this.send.visible ? source.availableSerialIds : null; if (allowed && rows.some(row => !allowed.includes(row.id))) { this.$message.warning('所选序列号已分配或不属于本次可配送范围'); return } source.serials = rows; source.quantity = rows.length }, source.serials || []) },
    async prepareDelivery() { const { data } = await materialDetail('picking', this.detail.data.id); this.setupDelivery(data.data) },
    setupDelivery(task, source = null) {
      this.send = { ...blankSend(), task, visible: true, redelivery: !!source, source }
      let groups = this.groupsFor(task, 'picking')
      groups.forEach(group => { group.sources.forEach(row => { if (source) { const original = source.lines.find(line => line.picking_task_line_id === row.id); row.available = original ? Number(original.remaining_redelivery_qty) : 0; row.picked = original ? Number(original.rejected_qty) : 0; row.allocated = original ? Number(original.redelivered_qty) : 0; row.availableSerialIds = original ? original.available_redelivery_serial_ids || [] : [] } row.quantity = row.available; row.serials = (row.availableSerialIds || []).map(id => ({ id, serial_no: String(id) })) }); group.sources = group.sources.filter(row => row.available > 0) }); groups = groups.filter(group => group.sources.length)
      this.send.allGroups = groups; const targets = {}; groups.forEach(group => { targets[group.targetKey] = { key: group.targetKey, name: group.targetName + (groups.some(other => other.targetName === group.targetName && other.targetKey !== group.targetKey) ? ' · ' + group.targetKey.split(':')[1] : '') } }); this.send.targetOptions = Object.values(targets); this.send.target = this.send.targetOptions[0] ? this.send.targetOptions[0].key : ''; this.selectDeliveryTarget()
    },
    selectDeliveryTarget() { this.send.groups = this.send.allGroups.filter(group => group.targetKey === this.send.target); this.send.targetName = this.send.groups[0] ? this.send.groups[0].targetName : '—' },
    async openRedelivery(id) { try { const delivery = (await materialDetail('delivery', id)).data.data; const task = (await materialDetail('picking', delivery.picking_task_id)).data.data; this.setupDelivery(task, delivery); if (!this.send.groups.length) this.send.error = '该配送单已没有可补送的拒收余额。' } catch (e) { this.$message.error(e.userMessage) } },
    async createDelivery() { const task = this.send.task; const body = { picking_task_id: task.id, expected_version: task.business_version, delivery_user_legacy_id: this.send.person && this.send.person.id, delivery_type: this.send.redelivery ? 'redelivery' : 'standard', source_delivery_id: this.send.redelivery ? this.send.source.id : undefined, remark: this.send.remark, lines: this.send.groups.flatMap(group => group.sources.filter(row => Number(row.quantity) > 0).map(row => ({ picking_task_line_id: row.id, delivery_qty: Number(row.quantity), serial_ids: row.serialRequired ? row.serials.map(serial => serial.id) : undefined, physical_material_ids: row.physicalRequired ? row.physicals.map(physical => physical.id) : undefined }))) }; await this.execute('delivery-new-' + task.id, 'delivery', null, '', body, async result => { this.send.visible = false; this.tab = 'delivery'; this.reset(); await this.openDetail('delivery', result.id) }, message => { this.send.error = message }) },
    async openDeliveryAction(row, action) { await this.openDetail('delivery', row.id); if (!this.detail.data) return; action === 'dispatch' ? this.dispatchFromDetail() : this.deliveryAction(action) },
    async dispatchFromDetail() { const document = this.detail.data; if (document.delivery_user_legacy_id) await this.deliveryAction('dispatch', { delivery_user_legacy_id: document.delivery_user_legacy_id }); else this.$refs.options.open('people', '选择配送人', {}, person => this.deliveryAction('dispatch', { delivery_user_legacy_id: person.id })) },
    async deliveryAction(action, extra = {}) { const document = this.detail.data; await this.execute('delivery-' + document.id + '-' + action, 'delivery', document.id, action, { expected_version: document.business_version, ...extra }, async () => { await this.openDetail('delivery', document.id); this.load() }, message => { this.detail.error = message }) },
    async cancelDocument(kind, document) { try { const { value } = await this.$prompt('请填写取消原因', kind === 'delivery' ? '取消配送单' : '取消配料单', { inputType: 'textarea', inputValidator: value => !!String(value || '').trim() || '取消原因不能为空', confirmButtonText: '确认取消', cancelButtonText: '返回' }); await this.execute(kind + '-' + document.id + '-cancel', kind, document.id, 'cancel', { expected_version: document.business_version, reason: value.trim() }, async () => { if (this.detail.visible) await this.openDetail(kind, document.id); this.load() }, message => this.$message.error(message)) } catch (e) { if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || e.message) } },
    async loadEvents() { if (!this.detail.data) return; const sequence = this.detailSequence; try { const { data } = await materialEvents(this.detail.kind, this.detail.data.id, { page: this.detail.eventPage, per_page: 10 }); if (sequence === this.detailSequence) { this.detail.events = data.data; this.detail.eventTotal = data.total } } catch (e) { this.detail.error = e.userMessage } },
    async execute(key, kind, id, action, payload, success, fail) {
      if (this.busy || this.detail.laborPendingAction) return
      if (this.detail.kind === kind && Number(this.detail.data?.id) === Number(id) && (this.detail.foreignDocumentPending || this.detail.pendingDocumentAction && this.detail.pendingDocumentAction !== action)) { fail('请先核对该单据的上次提交。'); return }
      // Keep an uncertain command across reloads. Retry its exact body before accepting another
      // write at this entry point, so a timeout cannot create a second picking/delivery document.
      const storageKey = 'erp-material-command:' + key
      const actorKey = 'erp-material-command-actor:' + key; const actor = this.materialActorScope()
      const pending = JSON.parse(sessionStorage.getItem(storageKey) || 'null')
      if (pending && sessionStorage.getItem(actorKey) !== actor) { fail('请使用原提交账号核对该单据的上次提交。'); return }
      const body = pending || { ...payload, client_command_id: 'pc-material-' + (window.crypto.randomUUID ? window.crypto.randomUUID() : Date.now() + '-' + Math.random().toString(36).slice(2)) }
      this.busy = true
      if (!pending) sessionStorage.setItem(actorKey, actor)
      sessionStorage.setItem(storageKey, JSON.stringify(body))
      try { const { data } = await materialCommand(kind, id, action, body); if (this.materialActorScope() !== actor) throw Object.assign(new Error('登录账号已变化，请重新打开当前页面。'), { userMessage: '登录账号已变化，请重新打开当前页面。' }); sessionStorage.removeItem(storageKey); sessionStorage.removeItem(actorKey); await success(data.data); this.$message.success(pending ? '已确认上次提交结果，请核对单据。' : data.message || '操作成功') }
      catch (e) { if (this.materialActorScope() !== actor) { fail('登录账号已变化，请回原提交账号核对。'); return } if (e.response && e.response.status >= 400 && e.response.status < 500 && ![401, 403, 404, 408, 429].includes(e.response.status) && !['command_processing', 'idempotency_hash_conflict'].includes(e.response.data.error_code)) { sessionStorage.removeItem(storageKey); sessionStorage.removeItem(actorKey) } fail(e.userMessage || '提交结果尚未确认，请重试确认上次操作。'); if (e.response && e.response.data.error_code === 'inventory_changed') { const info = e.response.data.details || {}; this.draftChosen.forEach(group => group.sources.forEach(row => { if (row.id === Number(info.inventory_balance_id) && info.available_qty !== undefined) row.picking_available_qty = info.available_qty })) } }
      finally { this.busy = false; this.refreshDocumentPending() }
    }
  }
}
</script>

<style src="./production-materials.css"></style>
