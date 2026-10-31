<template>
  <section class="return-page" v-loading="loading">
    <div class="page-head">
      <div class="title-block">
        <span class="crumb">库存管理 / 生产退料审核</span>
        <h1>生产退料审核工作台</h1>
        <p>车间提交的退料申请，库管核对实物后审核入库：按原领料批次与单价冲回库存，不良退料自动冻结并支持判定可用、报废或退供应商</p>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" @click="load">刷新列表</el-button>
      </div>
    </div>

    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>业务说明：余料退回审核后直接回归可用库存；不良品退回入库后处于“不良冻结”状态（不计入可用库存），可在当前页弹窗直接完成判定可用、报废或生成退供应商单。</span>
    </div>

    <div class="metric-overview-grid">
      <article class="metric-card" :class="{ active: filters.status === 'pending' }" @click="pickTab('pending')">
        <div class="metric-icon amber"><i class="el-icon-Bell" /></div>
        <div class="metric-copy">
          <span>待审核退料单</span>
          <strong class="warning-num">{{ summary.pending || 0 }}</strong>
          <small>待库管核对实物入库</small>
        </div>
      </article>
      <article class="metric-card" :class="{ active: filters.status === 'holding' }" @click="pickTab('holding')">
        <div class="metric-icon danger"><i class="el-icon-warning-outline" /></div>
        <div class="metric-copy">
          <span>不良冻结待处置</span>
          <strong :class="{ 'danger-num': (summary.holding || 0) > 0 }">{{ summary.holding || 0 }}</strong>
          <small>待判定可用/报废/退供</small>
        </div>
      </article>
      <article class="metric-card" :class="{ active: filters.status === 'approved' }" @click="pickTab('approved')">
        <div class="metric-icon primary"><i class="el-icon-circle-check" /></div>
        <div class="metric-copy">
          <span>已审核入库</span>
          <strong>{{ filters.status === 'approved' ? total : (summary.approved !== undefined ? summary.approved : '--') }}</strong>
          <small>点击查看已入库退料单</small>
        </div>
      </article>
      <article class="metric-card" :class="{ active: filters.status === '' }" @click="pickTab('')">
        <div class="metric-icon blue"><i class="el-icon-document" /></div>
        <div class="metric-copy">
          <span>全部退料记录</span>
          <strong>{{ filters.status === '' ? total : '--' }}</strong>
          <small>查看全部历史退料单</small>
        </div>
      </article>
    </div>

    <div class="main-card">
      <div class="tabs-bar">
        <div class="tabs">
          <span v-for="tab in tabs" :key="tab.value" :class="{ on: filters.status === tab.value }" @click="pickTab(tab.value)">
            {{ tab.label }}
            <em v-if="tab.value === 'pending' || tab.value === 'holding'">{{ summary[tab.value] || 0 }}</em>
          </span>
        </div>
        <div class="flt">
          <el-input
            v-model="filters.keyword"
            class="w"
            size="small"
            clearable
            placeholder="搜退料单号 / 工单号 / 物料 / 编号 / 申请人"
            prefix-icon="el-icon-search"
            @keyup.enter.native="search"
            @clear="search"
          />
          <el-button size="small" type="success" class="btn-theme-create" @click="search">查询</el-button>
          <el-button v-if="filters.keyword" size="small" @click="filters.keyword = ''; search()">重置</el-button>
        </div>
      </div>

      <div class="desktop-only">
        <el-table :data="rows" border size="small" class="return-table" empty-text="当前筛选条件下暂无退料单" @row-click="openApprove">
          <el-table-column label="退料单号 / 关联工单" min-width="185">
            <template slot-scope="s">
              <div class="mono doc-code">{{ s.row.return_no }}</div>
              <div class="sub">
                <span class="lk wo-link" @click.stop="openWorkOrder(s.row)">{{ s.row.work_order_no }}</span>
                · <span class="op-pill">{{ s.row.operation_name }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="退料类型与物料明细概览" min-width="280">
            <template slot-scope="s">
              <div class="summary-line">
                <span :class="['chip', s.row.return_type === 'defective' ? 'c-r' : 'c-b']">{{ s.row.return_type_label }}</span>
                <strong class="summary-text">{{ s.row.summary }}</strong>
              </div>
              <div class="sub">退料原因：{{ s.row.reason || '-' }}</div>
            </template>
          </el-table-column>
          <el-table-column label="申请人 / 申请时间" width="165">
            <template slot-scope="s">
              <div class="applicant-name">{{ s.row.requested_by_name || '-' }}</div>
              <div class="sub">{{ s.row.requested_at }}</div>
            </template>
          </el-table-column>
          <el-table-column label="审核状态 / 成本与处置" min-width="185">
            <template slot-scope="s">
              <div class="status-wrap">
                <span :class="['chip', chip(s.row.status)]">{{ s.row.status_label }}</span>
                <span v-if="s.row.held_qty > 0" class="chip c-o">不良冻结 {{ qty(s.row.held_qty) }}</span>
              </div>
              <div v-if="s.row.reviewed_by_name" class="sub">审核：{{ s.row.reviewed_by_name }} {{ s.row.reviewed_at }}</div>
              <div v-if="s.row.review_remark" class="sub">备注：{{ s.row.review_remark }}</div>
              <div v-if="s.row.cost_amount !== null" class="sub cost-sub">冲回成本：<b>{{ money(s.row.cost_amount) }}</b></div>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="185" fixed="right">
            <template slot-scope="s">
              <div class="op-actions" @click.stop>
                <template v-if="s.row.status === 'pending'">
                  <el-button v-if="canApprove" type="text" size="mini" class="lk-green" @click="openApprove(s.row)">审核入库</el-button>
                  <el-button v-if="canApprove" type="text" size="mini" class="lk-danger" @click="reject(s.row)">驳回</el-button>
                  <el-button type="text" size="mini" class="lk-muted" @click="cancel(s.row)">撤回</el-button>
                </template>
                <el-button v-else type="text" size="mini" class="lk-green" @click="openApprove(s.row)">
                  {{ s.row.held_qty > 0 && canApprove ? '不良处置' : '查看明细' }}
                </el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <div class="mobile-only">
        <el-empty v-if="!rows.length" description="暂无退料单" :image-size="60" />
        <div v-for="row in rows" :key="row.id" class="m-row">
          <div class="m-head"><span class="mono">{{ row.return_no }}</span><span :class="['chip', chip(row.status)]">{{ row.status_label }}</span></div>
          <div class="sub"><span class="lk" @click="openWorkOrder(row)">{{ row.work_order_no }}</span> · {{ row.operation_name }} · {{ row.return_type_label }}</div>
          <div>{{ row.summary }}</div>
          <div class="sub">原因：{{ row.reason || '-' }} · {{ row.requested_by_name || '' }} {{ row.requested_at }}</div>
          <div v-if="row.review_remark" class="sub">审核：{{ row.review_remark }}</div>
          <div v-if="row.held_qty > 0"><span class="chip c-o">不良冻结待处置 {{ qty(row.held_qty) }}</span></div>
          <div class="m-foot">
            <template v-if="row.status === 'pending'">
              <span v-if="canApprove" class="lk" @click="openApprove(row)">审核入库</span>
              <span v-if="canApprove" class="lk danger" @click="reject(row)">驳回</span>
              <span class="lk muted" @click="cancel(row)">撤回</span>
            </template>
            <span v-else class="lk" @click="openApprove(row)">{{ row.held_qty > 0 && canApprove ? '不良处置' : '查看明细' }}</span>
          </div>
        </div>
      </div>

      <div class="pg">
        <span>共 {{ total }} 条记录</span>
        <el-pagination background small layout="prev, pager, next" :current-page="page" :page-size="10" :total="total" @current-change="v => { page = v; load() }" />
      </div>
    </div>

    <el-dialog
      :title="approve.row ? (approve.row.status === 'pending' ? '审核生产退料 · ' : '生产退料详情 · ') + approve.row.return_no : ''"
      :visible.sync="approve.visible"
      width="760px"
      custom-class="master-style-dialog"
      :close-on-click-modal="false"
    >
      <template v-if="approve.row">
        <div class="dialog-meta-bar">
          <span>工单：<b>{{ approve.row.work_order_no }}</b></span>
          <span>工序：<b>{{ approve.row.operation_name }}</b></span>
          <span>类型：<b>{{ approve.row.return_type_label }}</b></span>
          <span>退料原因：<b>{{ approve.row.reason || '-' }}</b></span>
        </div>
        <el-table :data="approve.row.lines" border size="mini" class="line-table">
          <el-table-column label="物料名称" min-width="145" prop="component_item_name" />
          <el-table-column label="编号 / 拆自整机" min-width="140">
            <template slot-scope="s">
              {{ s.row.serial_no || '-' }}
              <div v-if="s.row.unit_serial_no" class="sub">拆自 {{ s.row.unit_serial_no }}</div>
            </template>
          </el-table-column>
          <el-table-column label="原领用批次" min-width="115" prop="batch_no" />
          <el-table-column label="原领用库位" min-width="120" prop="source_location" />
          <el-table-column v-if="approve.row.status === 'approved'" label="退入库位" min-width="120" prop="location" />
          <el-table-column label="退料数量" width="95" align="right">
            <template slot-scope="s"><b>{{ qty(s.row.qty) }}</b> {{ s.row.unit_name || '' }}</template>
          </el-table-column>
          <el-table-column v-if="isDefect" label="不良库存处置" min-width="220">
            <template slot-scope="s">
              <div v-for="d in s.row.dispositions" :key="d.id" class="sub">
                {{ d.action_label }} {{ qty(d.qty) }}{{ d.document_no && d.action === 'supplier_return' ? '（' + d.document_no + '）' : '' }} · {{ d.created_by_name }}
              </div>
              <template v-if="s.row.held_qty > 0 && canApprove && approve.dispose[s.row.id]">
                <el-select v-model="approve.dispose[s.row.id].action" size="mini" clearable placeholder="选处置方式" class="disp-sel">
                  <el-option v-for="a in actions" :key="a.value" :label="a.label" :value="a.value" />
                </el-select>
                <el-input-number v-if="!s.row.serial_no && approve.dispose[s.row.id].action" v-model="approve.dispose[s.row.id].qty" size="mini" :min="0" :max="s.row.held_qty" :precision="4" controls-position="right" class="disp-qty" />
              </template>
              <span v-else-if="s.row.held_qty > 0" class="chip c-o">冻结 {{ qty(s.row.held_qty) }}</span>
            </template>
          </el-table-column>
        </el-table>
        <template v-if="isDefect && approve.row.held_qty > 0 && canApprove">
          <div class="defect-tip-box">
            不良退回的物料已冻结（不计入可用库存、编号不可领用）。<b>判定可用</b> = 解冻回可用库存；<b>报废</b> = 按退料单价报废出库；<b>退供应商</b> = 按原批次追溯采购到货，生成退供应商单。
          </div>
          <el-input v-model="approve.remark" size="small" maxlength="500" placeholder="填写处置备注（选填）" />
        </template>
        <el-form v-if="approve.row.status === 'pending' && canApprove" label-position="top" size="small" class="approve-form">
          <el-form-item label="退入目标库位">
            <el-radio-group v-model="approve.original">
              <el-radio :label="true">默认退回原领用库位</el-radio>
              <el-radio :label="false">指定其他退入库位</el-radio>
            </el-radio-group>
            <div v-if="!approve.original" class="picker-row">
              <el-button size="small" @click="openPicker('warehouses')">{{ approve.warehouse_name || '选择仓库' }} <i class="el-icon-search" /></el-button>
              <el-button size="small" :disabled="!approve.warehouse_id" @click="openPicker('locations')">{{ approve.location_name || '选择库位' }} <i class="el-icon-search" /></el-button>
            </div>
          </el-form-item>
          <el-form-item label="审核入库备注">
            <el-input v-model="approve.remark" maxlength="500" placeholder="选填，记录实物清点情况" />
          </el-form-item>
        </el-form>
      </template>
      <span slot="footer">
        <el-button size="small" @click="approve.visible = false">关闭</el-button>
        <el-button
          v-if="approve.row && approve.row.status === 'pending' && canApprove"
          size="small"
          type="success"
          class="btn-theme-create"
          :loading="saving"
          :disabled="!approve.original && !approve.location_id"
          @click="submitApprove"
        >确认实物已收到，审核入库</el-button>
        <el-button
          v-if="isDefect && approve.row.held_qty > 0 && canApprove"
          size="small"
          type="success"
          class="btn-theme-create"
          :loading="saving"
          :disabled="!disposeLines.length"
          @click="submitDispose"
        >确认处置</el-button>
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
    loading: false,
    saving: false,
    rows: [],
    total: 0,
    page: 1,
    summary: {},
    canApprove: false,
    filters: { status: 'pending', keyword: '' },
    tabs: [
      { value: 'pending', label: '待审核' },
      { value: 'holding', label: '不良待处置' },
      { value: 'approved', label: '已入库' },
      { value: 'rejected', label: '已驳回' },
      { value: 'cancelled', label: '已撤回' },
      { value: '', label: '全部退料单' }
    ],
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
    qty,
    money,
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
.return-page { min-height: calc(100vh - 52px); padding: 16px 20px 28px; background: #f6f8fa; color: #1f2937; box-sizing: border-box; font-size: 13px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 12px; }
.title-block .crumb { display: block; color: #6b7280; font-size: 12px; margin-bottom: 4px; }
.title-block h1 { margin: 0; font-size: 22px; line-height: 1.3; color: #111827; font-weight: 700; }
.title-block p { margin: 4px 0 0; color: #6b7280; font-size: 13px; }
.head-actions { display: flex; gap: 8px; align-items: center; }
.btn-theme-create { background: #008b4b !important; border-color: #008b4b !important; color: #fff !important; }
.btn-theme-create:hover, .btn-theme-create:focus { background: #00753f !important; border-color: #00753f !important; color: #fff !important; }
.erp-page-tip { display: flex; align-items: center; gap: 8px; padding: 10px 14px; margin-bottom: 12px; background: #f0f9f4; border: 1px solid #cce8d8; border-radius: 8px; color: #1f4d36; font-size: 13px; }
.erp-page-tip i { color: #008b4b; font-size: 16px; flex-shrink: 0; }

.metric-overview-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 12px; }
.metric-card { display: flex; align-items: center; gap: 12px; padding: 14px 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; cursor: pointer; transition: all .18s ease; }
.metric-card:hover { border-color: #86efac; box-shadow: 0 4px 12px rgba(15, 23, 42, .05); }
.metric-card.active { border-color: #008b4b; background: #f3fbf6; box-shadow: 0 0 0 1px #008b4b inset; }
.metric-icon { width: 42px; height: 42px; border-radius: 10px; display: grid; place-items: center; font-size: 20px; flex-shrink: 0; }
.metric-icon.primary { color: #008b4b; background: #e8f7ee; }
.metric-icon.amber { color: #d97706; background: #fef3c7; }
.metric-icon.blue { color: #2563eb; background: #eff6ff; }
.metric-icon.danger { color: #dc2626; background: #fee2e2; }
.metric-copy { display: flex; flex-direction: column; min-width: 0; }
.metric-copy span { color: #6b7280; font-size: 12px; }
.metric-copy strong { font-size: 22px; line-height: 1.25; color: #111827; font-weight: 700; margin: 2px 0; }
.metric-copy small { color: #9ca3af; font-size: 11px; }
.warning-num { color: #d97706 !important; }
.danger-num { color: #dc2626 !important; }

.main-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; }
.tabs-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px; margin-bottom: 14px; }
.tabs { display: flex; gap: 6px; overflow-x: auto; }
.tabs span { padding: 7px 14px; color: #4b5563; border-radius: 6px; cursor: pointer; white-space: nowrap; font-size: 13px; transition: all .15s; }
.tabs span:hover { color: #008b4b; background: #f0f9f4; }
.tabs span.on { color: #008b4b; background: #e8f7ee; font-weight: 600; }
.tabs em { font-style: normal; font-size: 11px; background: #fff; color: #008b4b; border: 1px solid #cce8d8; border-radius: 999px; padding: 0 6px; margin-left: 5px; }
.flt { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.flt .w { width: 290px; }

.return-table { cursor: pointer; }
.return-table ::v-deep th { color: #475569; font-weight: 600; font-size: 12px; background: #f8fafc; }
.return-table ::v-deep td { font-size: 12px; }
.doc-code { font-weight: 700; color: #111827; }
.wo-link { color: #008b4b !important; font-weight: 600; }
.op-pill { color: #374151; font-weight: 500; }
.summary-line { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 2px; }
.summary-text { color: #111827; font-weight: 600; }
.applicant-name { color: #111827; font-weight: 600; }
.status-wrap { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 3px; }
.cost-sub b { color: #008b4b; }
.op-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.lk-green { padding: 0; color: #008b4b; font-weight: 600; }
.lk-danger { padding: 0; color: #dc2626; }
.lk-muted { padding: 0; color: #6b7280; }

.dialog-meta-bar { display: flex; flex-wrap: wrap; gap: 8px 18px; padding: 10px 12px; margin-bottom: 10px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; color: #4b5563; }
.dialog-meta-bar b { color: #111827; }
.defect-tip-box { margin: 10px 0; padding: 9px 12px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; color: #92400e; font-size: 12px; line-height: 1.5; }
.picker-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
.approve-form { margin-top: 12px; }
.line-table { width: 100%; }
.disp-sel { width: 130px; margin-right: 6px; }
.disp-qty { width: 110px; }
.pg { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; padding-top: 10px; border-top: 1px solid #eef2f6; color: #6b7280; font-size: 12px; flex-wrap: wrap; gap: 8px; }
.mobile-only { display: none; }
.m-row { border: 1px solid #e3e8f0; border-radius: 10px; padding: 12px; margin-bottom: 10px; display: grid; gap: 6px; font-size: 12px; }
.m-head, .m-foot { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; }

@media (max-width: 1100px) {
  .metric-overview-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 767px) {
  .return-page { padding: 12px; }
  .metric-overview-grid { grid-template-columns: 1fr; }
  .desktop-only { display: none; }
  .mobile-only { display: block; }
  .flt .w { width: 100%; }
}
</style>
