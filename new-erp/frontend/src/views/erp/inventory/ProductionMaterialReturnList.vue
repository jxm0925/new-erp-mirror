<template>
  <section class="mfg-page" v-loading="loading">
    <div class="mfg-hero">
      <div class="mfg-crumb">库存管理 / 生产退料审核</div>
      <div class="mfg-hrow">
        <div>
          <div class="mfg-title">生产退料审核</div>
          <div class="mfg-hero-sub">车间提交的退料申请，库管收到实物后审核入库：按原收料批次和单价冲回库存，默认退回原库位</div>
        </div>
      </div>
    </div>
    <div class="mfg-body">
      <div class="card">
        <div class="tabs">
          <span v-for="tab in tabs" :key="tab.value" :class="{ on: filters.status === tab.value }" @click="pickTab(tab.value)">{{ tab.label }}<em v-if="tab.value === 'pending' || tab.value === 'holding'">{{ summary[tab.value] || 0 }}</em></span>
        </div>
        <div class="flt">
          <el-input v-model="filters.keyword" class="w" size="small" clearable placeholder="搜退料单号 / 工单号 / 物料 / 编号 / 申请人" prefix-icon="el-icon-search" @keyup.enter.native="search" @clear="search" />
          <el-button size="small" icon="el-icon-refresh" @click="load">刷新</el-button>
        </div>

        <div class="desktop-only">
          <el-table :data="rows" class="return-table" empty-text="暂无退料单">
            <el-table-column label="退料单 / 工单" min-width="170">
              <template slot-scope="s">
                <div class="mono">{{ s.row.return_no }}</div>
                <div class="sub"><span class="lk" @click="openWorkOrder(s.row)">{{ s.row.work_order_no }}</span> · {{ s.row.operation_name }}</div>
              </template>
            </el-table-column>
            <el-table-column label="退什么" min-width="260">
              <template slot-scope="s">
                <div><span class="chip c-b">{{ s.row.return_type_label }}</span> {{ s.row.summary }}</div>
                <div class="sub">原因：{{ s.row.reason || '-' }}</div>
              </template>
            </el-table-column>
            <el-table-column label="申请" width="150">
              <template slot-scope="s"><div>{{ s.row.requested_by_name || '-' }}</div><div class="sub">{{ s.row.requested_at }}</div></template>
            </el-table-column>
            <el-table-column label="状态" min-width="150">
              <template slot-scope="s">
                <span :class="['chip', chip(s.row.status)]">{{ s.row.status_label }}</span>
                <div v-if="s.row.reviewed_by_name" class="sub">{{ s.row.reviewed_by_name }} {{ s.row.reviewed_at }}</div>
                <div v-if="s.row.review_remark" class="sub">{{ s.row.review_remark }}</div>
                <div v-if="s.row.cost_amount !== null" class="sub">冲回成本 {{ money(s.row.cost_amount) }}</div>
                <div v-if="s.row.held_qty > 0"><span class="chip c-o">不良冻结待处置 {{ qty(s.row.held_qty) }}</span></div>
              </template>
            </el-table-column>
            <el-table-column label="" width="150">
              <template slot-scope="s">
                <template v-if="s.row.status === 'pending'">
                  <span v-if="canApprove" class="lk" @click="openApprove(s.row)">审核</span>
                  <span v-if="canApprove" class="lk danger" @click="reject(s.row)">驳回</span>
                  <span class="lk muted" @click="cancel(s.row)">撤回</span>
                </template>
                <span v-else class="lk" @click="openApprove(s.row)">{{ s.row.held_qty > 0 && canApprove ? '处置' : '明细' }}</span>
              </template>
            </el-table-column>
          </el-table>
        </div>

        <div class="mobile-only">
          <div v-if="!rows.length" class="empty">暂无退料单</div>
          <div v-for="row in rows" :key="row.id" class="m-row">
            <div class="m-head"><span class="mono">{{ row.return_no }}</span><span :class="['chip', chip(row.status)]">{{ row.status_label }}</span></div>
            <div class="sub"><span class="lk" @click="openWorkOrder(row)">{{ row.work_order_no }}</span> · {{ row.operation_name }} · {{ row.return_type_label }}</div>
            <div>{{ row.summary }}</div>
            <div class="sub">原因：{{ row.reason || '-' }} · {{ row.requested_by_name || '' }} {{ row.requested_at }}</div>
            <div v-if="row.review_remark" class="sub">审核：{{ row.review_remark }}</div>
            <div v-if="row.held_qty > 0"><span class="chip c-o">不良冻结待处置 {{ qty(row.held_qty) }}</span></div>
            <div class="m-foot">
              <template v-if="row.status === 'pending'">
                <span v-if="canApprove" class="lk" @click="openApprove(row)">审核</span>
                <span v-if="canApprove" class="lk danger" @click="reject(row)">驳回</span>
                <span class="lk muted" @click="cancel(row)">撤回</span>
              </template>
              <span v-else class="lk" @click="openApprove(row)">{{ row.held_qty > 0 && canApprove ? '处置' : '明细' }}</span>
            </div>
          </div>
        </div>

        <div class="pg">
          <span>共 {{ total }} 条</span>
          <el-pagination background small layout="prev, pager, next" :current-page="page" :page-size="10" :total="total" @current-change="v => { page = v; load() }" />
        </div>
      </div>
    </div>

    <el-dialog :title="approve.row ? (approve.row.status === 'pending' ? '审核退料 ' : '退料单 ') + approve.row.return_no : ''" :visible.sync="approve.visible" width="720px" custom-class="master-style-dialog" :close-on-click-modal="false">
      <template v-if="approve.row">
        <p class="sub">{{ approve.row.work_order_no }} · {{ approve.row.operation_name }} · {{ approve.row.return_type_label }} · 原因：{{ approve.row.reason || '-' }}</p>
        <el-table :data="approve.row.lines" size="mini" class="line-table">
          <el-table-column label="物料" min-width="140" prop="component_item_name" />
          <el-table-column label="编号 / 拆自" min-width="140">
            <template slot-scope="s">{{ s.row.serial_no || '-' }}<div v-if="s.row.unit_serial_no" class="sub">{{ s.row.unit_serial_no }}</div></template>
          </el-table-column>
          <el-table-column label="批次" min-width="110" prop="batch_no" />
          <el-table-column label="原库位" min-width="120" prop="source_location" />
          <el-table-column v-if="approve.row.status === 'approved'" label="退入" min-width="120" prop="location" />
          <el-table-column label="数量" width="90"><template slot-scope="s">{{ qty(s.row.qty) }} {{ s.row.unit_name || '' }}</template></el-table-column>
          <el-table-column v-if="isDefect" label="不良处置" min-width="210">
            <template slot-scope="s">
              <div v-for="d in s.row.dispositions" :key="d.id" class="sub">{{ d.action_label }} {{ qty(d.qty) }}{{ d.document_no && d.action === 'supplier_return' ? '（' + d.document_no + '）' : '' }} · {{ d.created_by_name }}</div>
              <template v-if="s.row.held_qty > 0 && canApprove && approve.dispose[s.row.id]">
                <el-select v-model="approve.dispose[s.row.id].action" size="mini" clearable placeholder="待处置，选处置方式" class="disp-sel">
                  <el-option v-for="a in actions" :key="a.value" :label="a.label" :value="a.value" />
                </el-select>
                <el-input-number v-if="!s.row.serial_no && approve.dispose[s.row.id].action" v-model="approve.dispose[s.row.id].qty" size="mini" :min="0" :max="s.row.held_qty" :precision="4" controls-position="right" class="disp-qty" />
              </template>
              <span v-else-if="s.row.held_qty > 0" class="chip c-o">冻结 {{ qty(s.row.held_qty) }}</span>
            </template>
          </el-table-column>
        </el-table>
        <template v-if="isDefect && approve.row.held_qty > 0 && canApprove">
          <p class="sub">不良退回的料已冻结（不算可用库存、编号不能领用）。判定可用 = 解冻回可用库存；报废 = 按退料单价报废出库；退供应商 = 按原批次追溯采购到货，生成退供应商单。</p>
          <el-input v-model="approve.remark" size="small" maxlength="500" placeholder="处置备注（选填）" />
        </template>
        <el-form v-if="approve.row.status === 'pending' && canApprove" label-position="top" size="small" class="approve-form">
          <el-form-item label="退入库位">
            <el-radio-group v-model="approve.original">
              <el-radio :label="true">退回原库位</el-radio>
              <el-radio :label="false">选择库位</el-radio>
            </el-radio-group>
            <div v-if="!approve.original" class="picker-row">
              <el-button size="small" @click="openPicker('warehouses')">{{ approve.warehouse_name || '选仓库' }}<i class="el-icon-search" /></el-button>
              <el-button size="small" :disabled="!approve.warehouse_id" @click="openPicker('locations')">{{ approve.location_name || '选库位' }}<i class="el-icon-search" /></el-button>
            </div>
          </el-form-item>
          <el-form-item label="审核备注">
            <el-input v-model="approve.remark" maxlength="500" placeholder="选填" />
          </el-form-item>
        </el-form>
      </template>
      <span slot="footer">
        <el-button size="small" @click="approve.visible = false">关闭</el-button>
        <el-button v-if="approve.row && approve.row.status === 'pending' && canApprove" size="small" type="success" class="btn-theme-create" :loading="saving" :disabled="!approve.original && !approve.location_id" @click="submitApprove">确认实物已收到，入库</el-button>
        <el-button v-if="isDefect && approve.row.held_qty > 0 && canApprove" size="small" type="success" class="btn-theme-create" :loading="saving" :disabled="!disposeLines.length" @click="submitDispose">确认处置</el-button>
      </span>
    </el-dialog>
    <system-record-picker :visible.sync="picker.visible" :type="picker.type" :multiple="false" :value="[]" :warehouse-id="Number(approve.warehouse_id || 0)" :location-purposes="['general']" management-scope="factory" @confirm="pick" />
  </section>
</template>

<script>
import { listMaterialReturns, approveMaterialReturn, rejectMaterialReturn, cancelMaterialReturn, disposeMaterialReturn } from '../../../api/erp/production'
import SystemRecordPicker from '../../../components/system/SystemRecordPicker.vue'
import { qty, money, errorText, isCancel } from '../production/mfg-helpers'

const CHIP = { pending: 'c-o', approved: 'c-g', rejected: 'c-r', cancelled: 'c-n' }

export default {
  name: 'ProductionMaterialReturnList',
  components: { SystemRecordPicker },
  data: () => ({
    loading: false, saving: false, rows: [], total: 0, page: 1, summary: {}, canApprove: false,
    filters: { status: 'pending', keyword: '' },
    tabs: [{ value: 'pending', label: '待审核' }, { value: 'holding', label: '不良待处置' }, { value: 'approved', label: '已入库' }, { value: 'rejected', label: '已驳回' }, { value: 'cancelled', label: '已撤回' }, { value: '', label: '全部' }],
    approve: { visible: false, row: null, original: true, warehouse_id: null, warehouse_name: '', location_id: null, location_name: '', remark: '', dispose: {} },
    actions: [{ value: 'release', label: '判定可用' }, { value: 'scrap', label: '报废' }, { value: 'supplier_return', label: '退供应商' }],
    picker: { visible: false, type: 'warehouses' }
  }),
  computed: {
    isDefect() { return !!(this.approve.row && this.approve.row.return_type === 'defective' && this.approve.row.status === 'approved') },
    disposeLines() {
      const d = this.approve.dispose
      return ((this.approve.row && this.approve.row.lines) || []).filter(l => d[l.id] && d[l.id].action && (l.serial_no || Number(d[l.id].qty) > 0))
        .map(l => ({ line_id: l.id, action: d[l.id].action, qty: l.serial_no ? 1 : Number(d[l.id].qty) }))
    }
  },
  created() {
    if (this.$route.query.status !== undefined) this.filters.status = String(this.$route.query.status)
    this.load()
  },
  methods: {
    qty, money,
    chip(status) { return CHIP[status] || 'c-n' },
    async load() {
      this.loading = true
      try {
        const params = { page: this.page, per_page: 10, status: this.filters.status || undefined, keyword: this.filters.keyword || undefined }
        const { data } = await listMaterialReturns(params)
        this.rows = data.data || []
        this.total = data.total || 0
        this.summary = data.summary || {}
        this.canApprove = !!data.can_approve
      } catch (e) {
        this.$message.error(errorText(e, '退料单加载失败'))
      } finally {
        this.loading = false
      }
    },
    search() { this.page = 1; this.load() },
    pickTab(status) { this.filters.status = status; this.search() },
    openWorkOrder(row) {
      if (this.$can('production.work_order.view')) this.$router.push(`/production/work-orders/${row.work_order_id}`)
    },
    openApprove(row) {
      const dispose = {}
      for (const l of row.lines || []) if (l.held_qty > 0) dispose[l.id] = { action: '', qty: l.held_qty }
      this.approve = { visible: true, row, original: true, warehouse_id: null, warehouse_name: '', location_id: null, location_name: '', remark: '', dispose }
    },
    openPicker(type) { this.picker = { visible: true, type } },
    pick(rows) {
      const row = rows[0]
      if (!row) return
      const a = this.approve
      if (this.picker.type === 'warehouses') {
        if (Number(a.warehouse_id) !== Number(row.id)) { a.location_id = null; a.location_name = '' }
        a.warehouse_id = row.id
        a.warehouse_name = row.warehouse_name
      } else {
        a.location_id = row.id
        a.location_name = [row.location_code, row.location_name].filter(Boolean).join(' ')
      }
    },
    async submitApprove() {
      const a = this.approve
      if (await this.act(() => approveMaterialReturn(a.row.id, { location_id: a.original ? undefined : a.location_id, remark: a.remark || undefined }), '已入库')) a.visible = false
    },
    async submitDispose() {
      const a = this.approve
      if (await this.act(() => disposeMaterialReturn(a.row.id, { lines: this.disposeLines, remark: a.remark || undefined }), '已处置')) a.visible = false
    },
    async reject(row) {
      let remark
      try {
        const res = await this.$prompt(`驳回退料申请 ${row.return_no}，请写明原因（申请人能看到）。`, '驳回退料', {
          inputValidator: v => !!(v && v.trim()) || '请填写驳回原因'
        })
        remark = res.value.trim()
      } catch (e) {
        if (isCancel(e)) return
        return
      }
      await this.act(() => rejectMaterialReturn(row.id, { remark }), '已驳回')
    },
    async cancel(row) {
      try {
        await this.$confirm(`撤回退料申请 ${row.return_no}？`, '撤回退料', { type: 'warning' })
      } catch (e) {
        return
      }
      await this.act(() => cancelMaterialReturn(row.id), '已撤回')
    },
    async act(fn, okText) {
      this.saving = true
      try {
        const { data } = await fn()
        this.$message.success(data.message || okText)
        this.load()
        return true
      } catch (e) {
        this.$message.error(errorText(e, '操作失败'))
        return false
      } finally {
        this.saving = false
      }
    }
  }
}
</script>

<style scoped src="../production/mfg-v2.css"></style>
<style scoped>
.tabs { display: flex; gap: 4px; border-bottom: 1px solid #e3e8f0; margin-bottom: 12px; overflow-x: auto; }
.tabs span { padding: 8px 14px; color: #5b6780; border-bottom: 2px solid transparent; cursor: pointer; white-space: nowrap; }
.tabs span.on { color: #1f6feb; border-color: #1f6feb; font-weight: 600; }
.tabs em { font-style: normal; font-size: 11px; background: #eef1f5; border-radius: 8px; padding: 0 6px; margin-left: 4px; }
.flt { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; align-items: center; }
.flt .w { width: 320px; }
.return-table ::v-deep th { color: #8a95a8; font-weight: 500; font-size: 12px; background: #fff; }
.return-table ::v-deep td { font-size: 12px; }
.lk + .lk { margin-left: 10px; }
.lk.danger { color: #d92d20; }
.lk.muted { color: #5b6780; }
.pg { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; color: #8a95a8; font-size: 12px; flex-wrap: wrap; gap: 8px; }
.picker-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
.approve-form { margin-top: 12px; }
.line-table { width: 100%; }
.disp-sel { width: 130px; margin-right: 6px; }
.disp-qty { width: 110px; }
.mobile-only { display: none; }
.m-row { border: 1px solid #e3e8f0; border-radius: 10px; padding: 12px; margin-bottom: 10px; display: grid; gap: 6px; font-size: 12px; }
.m-head, .m-foot { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
@media (max-width: 767px) {
  .desktop-only { display: none; }
  .mobile-only { display: block; }
  .flt .w { width: 100%; }
}
</style>
