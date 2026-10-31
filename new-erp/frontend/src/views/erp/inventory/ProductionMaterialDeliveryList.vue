<template>
  <section class="delivery-page" v-loading="loading">
    <div class="page-head">
      <div class="title-block">
        <span class="crumb">库存管理 / 生产配料</span>
        <h1>生产配料工作台</h1>
        <p>按工序为车间配“专单配送”物料：照拣货单拣料、发出扣库存，车间核对签收后开工；拒收可重新送达或整单收回入库</p>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" @click="load">刷新数据</el-button>
      </div>
    </div>

    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>业务说明：待配料工序按先入先用自动推荐拣货库位与批次；生成配料单后可在发出前核对实物，也可“配好直接发出”。点击下方统计卡片可快速切换对应状态。</span>
    </div>

    <stocktake-freeze-alert />

    <div class="metric-overview-grid">
      <article class="metric-card" :class="{ active: status === 'pending' }" @click="pickTab('pending')">
        <div class="metric-icon primary"><i class="el-icon-box" /></div>
        <div class="metric-copy">
          <span>待配料工序</span>
          <strong>{{ status === 'pending' ? total : (summary.pending !== undefined ? summary.pending : '--') }}</strong>
          <small>点击查看需配料工序</small>
        </div>
      </article>
      <article class="metric-card" :class="{ active: status === 'prepared' }" @click="pickTab('prepared')">
        <div class="metric-icon amber"><i class="el-icon-document-checked" /></div>
        <div class="metric-copy">
          <span>待发出配料单</span>
          <strong class="warning-num">{{ summary.prepared || 0 }}</strong>
          <small>已生成拣货单待出库</small>
        </div>
      </article>
      <article class="metric-card" :class="{ active: status === 'dispatched' }" @click="pickTab('dispatched')">
        <div class="metric-icon blue"><i class="el-icon-truck" /></div>
        <div class="metric-copy">
          <span>已发出待签收</span>
          <strong>{{ summary.dispatched || 0 }}</strong>
          <small>已扣库存等待车间签收</small>
        </div>
      </article>
      <article class="metric-card" :class="{ active: status === 'rejected' }" @click="pickTab('rejected')">
        <div class="metric-icon danger"><i class="el-icon-warning-outline" /></div>
        <div class="metric-copy">
          <span>拒收待处理</span>
          <strong :class="{ 'danger-num': (summary.rejected || 0) > 0 }">{{ summary.rejected || 0 }}</strong>
          <small>需重新送达或收回入库</small>
        </div>
      </article>
    </div>

    <div class="main-card">
      <div class="tabs-bar">
        <div class="tabs">
          <span v-for="tab in tabs" :key="tab.value" :class="{ on: status === tab.value }" @click="pickTab(tab.value)">
            {{ tab.label }}
            <em v-if="tab.count !== undefined && summary[tab.count]">{{ summary[tab.count] }}</em>
          </span>
        </div>
        <div class="flt">
          <el-input
            v-model="keyword"
            class="w"
            size="small"
            clearable
            :placeholder="status === 'pending' ? '搜工单号 / 产品 / 工序 / 物料' : '搜配料单号 / 工单号 / 产品'"
            prefix-icon="el-icon-search"
            @keyup.enter.native="search"
            @clear="search"
          />
          <el-button size="small" type="success" class="btn-theme-create" @click="search">查询</el-button>
          <el-button v-if="keyword" size="small" @click="keyword = ''; search()">重置</el-button>
        </div>
      </div>

      <template v-if="status === 'pending'">
        <el-empty v-if="!rows.length" description="当前没有需要配料的工序" :image-size="68" />
        <div v-for="r in rows" :key="r.operation_id" class="m-row">
          <div class="m-head">
            <div class="m-title-wrap">
              <span class="lk mono wo-link" @click="openWorkOrder(r.work_order_id)">{{ r.work_order_no }}</span>
              <span v-if="r.priority === 1" class="chip c-r">特急</span>
              <span v-else-if="r.is_urgent" class="chip c-r">加急</span>
              <span v-else-if="r.priority === 4" class="chip">可延后</span>
              <b class="op-badge">{{ r.operation_name }}</b>
              <span class="prod-name">{{ r.item_name }}</span>
            </div>
            <el-button v-if="canManage" size="mini" type="success" class="btn-theme-create" icon="el-icon-receiving" @click="openPrepare(r)">立即配料</el-button>
          </div>
          <div class="m-metrics">
            <span>计划数量：<b>{{ qty(r.planned_qty) }} 台</b></span>
            <span>已齐套够用：<b class="safe-txt">{{ qty(r.covered_units) }} 台</b></span>
            <span v-if="r.waiting_units > 0" class="danger">车间等待：<b>{{ r.waiting_units }} 台</b></span>
            <span v-if="r.prepared" class="warn-txt">已有配料单待发出</span>
            <span>计划开工：<b>{{ r.planned_start_date || '未排期' }}</b></span>
          </div>
          <div class="need-summary-box">
            <span class="need-lbl">待配物料清单：</span>
            <span class="need-val">{{ r.summary }}</span>
          </div>
        </div>
      </template>

      <template v-else>
        <el-empty v-if="!rows.length" description="当前筛选条件下暂无配料单" :image-size="68" />
        <div v-for="d in rows" :key="d.id" class="m-row">
          <div class="m-head">
            <div class="m-title-wrap">
              <b class="mono doc-no">{{ d.delivery_no }}</b>
              <span class="lk mono wo-link" @click="openWorkOrder(d.work_order_id)">{{ d.work_order_no }}</span>
              <span class="op-badge">{{ d.operation_name }}</span>
              <span class="prod-name">{{ d.item_name }}</span>
              <span v-if="d.unit_qty" class="unit-tag-pill">本次配 {{ qty(d.unit_qty) }} 台用量</span>
            </div>
            <span :class="['chip', chip(d.status)]">{{ d.status_label }}</span>
          </div>
          <div class="picks">
            <div v-for="l in d.lines" :key="l.material_id" class="pick-line">
              <span class="name">{{ l.component_item_name }} <b>{{ qty(l.qty) }}</b> {{ l.unit_name || '' }}</span>
              <div class="pick-badges">
                <span v-for="(p, i) in l.picks" :key="i" class="pick-pill">
                  <i class="el-icon-location-outline" />
                  {{ [p.warehouse_name, p.location_code].filter(Boolean).join(' / ') || '-' }}
                  <template v-if="p.batch_no"> · 批次 {{ p.batch_no }}</template>
                  · <b>{{ qty(p.qty) }}</b>
                  <template v-if="p.serial_nos && p.serial_nos.length"> · {{ p.serial_nos.join('、') }}</template>
                </span>
              </div>
            </div>
          </div>
          <div class="sub audit-trail">
            <span>配料：{{ d.prepared_by_name || '-' }} {{ d.prepared_at || '' }}</span>
            <span v-if="d.dispatched_at">发出：{{ d.dispatched_by_name || '-' }} {{ d.dispatched_at }}<template v-if="d.issue_no">（出库单 {{ d.issue_no }}）</template></span>
            <span v-if="d.signed_at">签收：{{ d.signed_by_name || '-' }} {{ d.signed_at }}</span>
          </div>
          <div v-if="d.reject_reason" class="alert-strip danger-strip">
            <i class="el-icon-warning" /> 车间拒收原因：{{ d.reject_reason }}（{{ d.rejected_by_name || '' }} {{ d.rejected_at || '' }}）
          </div>
          <div v-if="d.close_remark" class="alert-strip info-strip">
            处理说明：{{ d.close_remark }}<template v-if="d.return_no">（退料单 {{ d.return_no }}）</template>
          </div>
          <div v-if="d.source_problems && d.source_problems.length" class="alert-strip danger-strip">
            <i class="el-icon-error" /> 拣货来源已失效：{{ d.source_problems.join('；') }}。请点击“重新拣货”后再发出。
          </div>
          <div v-if="canManage && (d.status === 'prepared' || d.status === 'rejected' || d.status === 'dispatched')" class="m-foot">
            <template v-if="d.status === 'prepared'">
              <el-button
                v-if="!(d.source_problems && d.source_problems.length)"
                size="mini"
                type="success"
                class="btn-theme-create"
                @click="act(d, 'dispatch', `确认已照单拣好 ${d.delivery_no} 的料并发出？发出后按拣货单的库位、批次扣库存。`)"
              >确认发出</el-button>
              <el-button size="mini" plain @click="act(d, 'repick', `按先入先用给 ${d.delivery_no} 重新分配拣货库位？已拣出的料请放回原位，按新拣货单取料。`)">重新拣货</el-button>
              <el-button size="mini" type="text" class="lk muted" @click="act(d, 'cancel', `取消配料单 ${d.delivery_no}？不动库存。`)">取消配料单</el-button>
            </template>
            <template v-if="d.status === 'rejected'">
              <el-button size="mini" type="success" class="btn-theme-create" @click="redeliver(d)">重新送达</el-button>
            </template>
            <el-button v-if="['dispatched', 'rejected'].includes(d.status)" size="mini" type="danger" plain @click="recall(d)">收回入库</el-button>
          </div>
        </div>
      </template>

      <div class="pg">
        <span>共 {{ total }} 条记录</span>
        <el-pagination background small layout="prev, pager, next" :current-page="page" :page-size="10" :total="total" @current-change="v => { page = v; load() }" />
      </div>
    </div>

    <el-dialog :title="prep.row ? `生产配料 · ${prep.row.work_order_no} · ${prep.row.operation_name}` : '生产配料'" :visible.sync="prep.visible" :width="dialogWidth" custom-class="master-style-dialog" :close-on-click-modal="false">
      <div v-loading="prep.loading" class="prep-dialog-body">
        <div class="units-row">
          <span class="units-label">本次配几台的料：</span>
          <el-input-number v-model="prep.units" size="small" :min="1" :max="prep.preview ? Math.max(1, prep.preview.planned_qty) : 100000" placeholder="全部" controls-position="right" @change="loadPreview" />
          <el-button type="text" size="small" class="fill-rest-btn" @click="prep.units = undefined; loadPreview()">配齐剩余全部台数</el-button>
        </div>
        <p v-if="prep.preview" class="preview-summary">
          工单共 <b>{{ qty(prep.preview.planned_qty) }}</b> 台，此前已齐套 <b>{{ qty(prep.preview.covered_units) }}</b> 台，本次配到第 <b>{{ qty(prep.preview.target_units) }}</b> 台（拣货库位与批次按先入先用自动推荐）。
        </p>
        <div v-if="prep.preview && prep.preview.short.length" class="warn">
          <i class="el-icon-warning" /> 库存不够：{{ prep.preview.short.join('；') }}。可以调小本次配料台数，或联系采购补货。
        </div>
        <div class="prep-lines-box">
          <div v-for="l in (prep.preview ? prep.preview.lines : [])" :key="l.material_id" class="pick-line">
            <span class="name">
              {{ l.component_item_name }} <b>{{ qty(l.qty) }}</b> {{ l.unit_name || '' }}
              <span v-if="l.short_qty > 0" class="danger">（缺 {{ qty(l.short_qty) }}）</span>
            </span>
            <div class="pick-badges">
              <span v-for="(p, i) in l.picks" :key="i" class="pick-pill">
                <i class="el-icon-location-outline" />
                {{ [p.warehouse_name, p.location_code].filter(Boolean).join(' / ') || '-' }}
                <template v-if="p.batch_no"> · 批次 {{ p.batch_no }}</template>
                · <b>{{ qty(p.qty) }}</b>
                <template v-if="p.serial_nos && p.serial_nos.length"> · {{ p.serial_nos.join('、') }}</template>
              </span>
            </div>
          </div>
        </div>
        <el-input v-model="prep.remark" class="remark" size="small" maxlength="500" placeholder="配料备注（选填，车间签收可见）" />
      </div>
      <span slot="footer">
        <el-button size="small" @click="prep.visible = false">取消</el-button>
        <el-button size="small" :loading="saving" :disabled="!canSubmitPrep" @click="submitPrep(false)">仅生成配料单</el-button>
        <el-button size="small" type="success" class="btn-theme-create" :loading="saving" :disabled="!canSubmitPrep" @click="submitPrep(true)">配好并直接发出</el-button>
      </span>
    </el-dialog>
  </section>
</template>

<script>
import { listPendingDeliveries, listMaterialDeliveries, previewMaterialDelivery, createMaterialDelivery, materialDeliveryAction } from '../../../api/erp/production'
import { qty, errorText } from '../production/mfg-helpers'
import StocktakeFreezeAlert from '@/components/common/StocktakeFreezeAlert.vue'

const CHIP = { prepared: 'c-o', dispatched: 'c-b', signed: 'c-g', rejected: 'c-r', recalled: 'c-n', cancelled: 'c-n' }

export default {
  name: 'ProductionMaterialDeliveryList',
  components: { StocktakeFreezeAlert },
  data: () => ({
    loading: false,
    saving: false,
    rows: [],
    total: 0,
    page: 1,
    summary: {},
    canManage: false,
    keyword: '',
    status: 'pending',
    tabs: [
      { value: 'pending', label: '待配料' },
      { value: 'prepared', label: '待发出', count: 'prepared' },
      { value: 'dispatched', label: '待签收', count: 'dispatched' },
      { value: 'rejected', label: '拒收待处理', count: 'rejected' },
      { value: 'signed', label: '已签收' },
      { value: '', label: '全部配料单' }
    ],
    prep: { visible: false, loading: false, row: null, units: undefined, preview: null, remark: '' }
  }),
  computed: {
    dialogWidth() { return window.innerWidth < 768 ? '94%' : '760px' },
    canSubmitPrep() { const p = this.prep.preview; return !!(p && p.lines.length && !p.short.length) }
  },
  created() {
    if (this.$route.query.status !== undefined) this.status = String(this.$route.query.status)
    this.load()
  },
  methods: {
    qty,
    chip(s) { return CHIP[s] || 'c-n' },
    async load() {
      this.loading = true
      try {
        const params = { page: this.page, per_page: 10, keyword: this.keyword || undefined }
        if (this.status === 'pending') {
          const [{ data }, { data: meta }] = await Promise.all([listPendingDeliveries(params), listMaterialDeliveries({ per_page: 1 })])
          this.rows = data.data || []
          this.total = data.total || 0
          this.summary = { ...(meta.summary || {}), pending: data.total || 0 }
          this.canManage = !!meta.can_manage
        } else {
          const { data } = await listMaterialDeliveries({ ...params, status: this.status || undefined })
          this.rows = data.data || []
          this.total = data.total || 0
          this.summary = data.summary || {}
          this.canManage = !!data.can_manage
        }
      } catch (e) {
        this.$message.error(errorText(e, '配料数据加载失败'))
      } finally {
        this.loading = false
      }
    },
    search() { this.page = 1; this.load() },
    pickTab(v) { this.status = v; this.rows = []; this.search() },
    openWorkOrder(id) {
      if (this.$can('production.work_order.view')) this.$router.push(`/production/work-orders/${id}`)
    },
    openPrepare(row) {
      this.prep = { visible: true, loading: false, row, units: undefined, preview: null, remark: '' }
      this.loadPreview()
    },
    async loadPreview() {
      const p = this.prep
      p.loading = true
      try {
        const { data } = await previewMaterialDelivery(p.row.work_order_id, p.row.operation_id, { units: p.units || undefined })
        p.preview = data.data
      } catch (e) {
        p.preview = null
        this.$message.error(errorText(e, '配料预览失败'))
      } finally {
        p.loading = false
      }
    },
    async submitPrep(dispatch) {
      const p = this.prep
      this.saving = true
      try {
        const { data } = await createMaterialDelivery(p.row.work_order_id, p.row.operation_id, { units: p.units || undefined, remark: p.remark || undefined, dispatch })
        this.$message.success(data.message || '已生成配料单')
        p.visible = false
        this.load()
      } catch (e) {
        this.$message.error(errorText(e, '配料失败'))
      } finally {
        this.saving = false
      }
    },
    async act(d, action, confirmText, data) {
      if (confirmText) {
        try { await this.$confirm(confirmText, '确认', { type: 'warning' }) } catch (e) { return }
      }
      this.saving = true
      try {
        const res = await materialDeliveryAction(d.id, action, data)
        this.$message.success(res.data.message || '已处理')
        this.load()
      } catch (e) {
        this.$message.error(errorText(e, '操作失败'))
      } finally {
        this.saving = false
      }
    },
    async redeliver(d) {
      let remark
      try {
        remark = (await this.$prompt(`车间拒收原因：${d.reject_reason || '-'}。补齐或换对后重新送达，写一下怎么处理的。`, '重新送达', { inputPlaceholder: '如：已补 2 个' })).value
      } catch (e) { return }
      this.act(d, 'redeliver', null, { remark: remark || undefined })
    },
    async recall(d) {
      let reason
      try {
        reason = (await this.$prompt(`把 ${d.delivery_no} 的料整单收回入库（按原库位、原批次冲回，冲减已领）。原因：`, '收回入库', {
          inputValidator: v => !!(v && v.trim()) || '请填写原因'
        })).value.trim()
      } catch (e) { return }
      this.act(d, 'recall', null, { reason })
    }
  }
}
</script>

<style scoped src="../production/mfg-v2.css"></style>
<style scoped>
.delivery-page { min-height: calc(100vh - 52px); padding: 16px 20px 28px; background: #f6f8fa; color: #1f2937; box-sizing: border-box; font-size: 13px; }
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
.flt .w { width: 280px; }

.m-row { border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; margin-bottom: 10px; display: grid; gap: 8px; background: #fff; transition: border-color .15s; }
.m-row:hover { border-color: #cbd5e1; }
.m-head, .m-foot { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; align-items: center; }
.m-title-wrap { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.doc-no { font-size: 14px; color: #111827; }
.wo-link { color: #008b4b !important; font-weight: 600; cursor: pointer; }
.op-badge { background: #f0f9f4; color: #008b4b; border: 1px solid #cce8d8; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
.prod-name { color: #374151; font-weight: 600; }
.unit-tag-pill { background: #f3f4f6; color: #4b5563; padding: 2px 8px; border-radius: 999px; font-size: 11px; }
.m-metrics { display: flex; flex-wrap: wrap; gap: 8px 18px; font-size: 12px; color: #6b7280; }
.m-metrics b { color: #111827; }
.safe-txt { color: #008b4b !important; }
.warn-txt { color: #d97706; font-weight: 600; }
.danger { color: #dc2626; }
.need-summary-box { background: #f8fafc; border: 1px solid #eef2f6; border-radius: 6px; padding: 8px 12px; font-size: 12px; line-height: 1.5; }
.need-lbl { color: #6b7280; font-weight: 600; }
.need-val { color: #1f2937; }

.picks { display: grid; gap: 6px; background: #f8fafc; border: 1px solid #eef2f6; border-radius: 8px; padding: 10px 12px; }
.pick-line { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; padding: 4px 0; border-bottom: 1px dashed #e2e8f0; }
.pick-line:last-child { border-bottom: none; }
.pick-line .name { min-width: 190px; color: #111827; font-size: 13px; }
.pick-badges { display: flex; flex-wrap: wrap; gap: 6px; }
.pick-pill { background: #fff; border: 1px solid #e2e8f0; border-radius: 4px; padding: 2px 8px; font-size: 12px; color: #475569; }
.pick-pill b { color: #008b4b; }
.audit-trail { display: flex; flex-wrap: wrap; gap: 6px 18px; color: #6b7280; font-size: 12px; }
.alert-strip { padding: 7px 10px; border-radius: 6px; font-size: 12px; }
.danger-strip { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
.info-strip { background: #f0f9ff; border: 1px solid #bae6fd; color: #0369a1; }
.m-foot { justify-content: flex-end; padding-top: 6px; border-top: 1px solid #f1f5f9; }
.lk.muted { color: #6b7280; }

.prep-dialog-body { display: flex; flex-direction: column; gap: 10px; }
.units-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.units-label { font-weight: 600; color: #374151; }
.fill-rest-btn { color: #008b4b; font-weight: 600; }
.preview-summary { margin: 0; font-size: 12px; color: #4b5563; background: #f0f9f4; border: 1px solid #cce8d8; border-radius: 6px; padding: 8px 12px; }
.preview-summary b { color: #008b4b; }
.prep-lines-box { border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 12px; background: #fbfcfd; max-height: 320px; overflow-y: auto; }
.warn { color: #b54708; background: #fffaeb; border: 1px solid #fed7aa; border-radius: 6px; padding: 8px 10px; font-size: 12px; }
.remark { margin-top: 4px; }
.pg { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; padding-top: 10px; border-top: 1px solid #eef2f6; color: #6b7280; font-size: 12px; flex-wrap: wrap; gap: 8px; }

@media (max-width: 1100px) {
  .metric-overview-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 767px) {
  .delivery-page { padding: 12px; }
  .metric-overview-grid { grid-template-columns: 1fr; }
  .flt .w { width: 100%; }
}
</style>
