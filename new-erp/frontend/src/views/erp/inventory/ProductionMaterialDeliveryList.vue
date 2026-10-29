<template>
  <section class="mfg-page" v-loading="loading">
    <div class="mfg-hero">
      <div class="mfg-crumb">库存管理 / 生产配料</div>
      <div class="mfg-hrow">
        <div>
          <div class="mfg-title">生产配料</div>
          <div class="mfg-hero-sub">按工序给车间配“专单配送”的料：照拣货单拿料，发出时扣库存，车间核对签收后才能开工；拒收的可重新送达或整单收回入库</div>
        </div>
      </div>
    </div>
    <div class="mfg-body">
      <stocktake-freeze-alert />
      <div class="card">
        <div class="tabs">
          <span v-for="tab in tabs" :key="tab.value" :class="{ on: status === tab.value }" @click="pickTab(tab.value)">{{ tab.label }}<em v-if="tab.count !== undefined && summary[tab.count]">{{ summary[tab.count] }}</em></span>
        </div>
        <div class="flt">
          <el-input v-model="keyword" class="w" size="small" clearable :placeholder="status === 'pending' ? '搜工单号 / 产品 / 工序 / 物料' : '搜配料单号 / 工单号 / 产品'" prefix-icon="el-icon-search" @keyup.enter.native="search" @clear="search" />
          <el-button size="small" icon="el-icon-refresh" @click="load">刷新</el-button>
        </div>

        <template v-if="status === 'pending'">
          <div v-if="!rows.length" class="empty">没有要配的料</div>
          <div v-for="r in rows" :key="r.operation_id" class="m-row">
            <div class="m-head">
              <span>
                <span class="lk mono" @click="openWorkOrder(r.work_order_id)">{{ r.work_order_no }}</span>
                <span v-if="r.priority === 1" class="chip c-r">特急</span><span v-else-if="r.is_urgent" class="chip c-r">加急</span><span v-else-if="r.priority === 4" class="chip">可延后</span>
                <b>{{ r.operation_name }}</b> · {{ r.item_name }}
              </span>
              <span v-if="canManage" class="lk" @click="openPrepare(r)">配料</span>
            </div>
            <div class="sub">
              共 {{ qty(r.planned_qty) }} 台，料已够 {{ qty(r.covered_units) }} 台<span v-if="r.waiting_units > 0" class="danger"> · 车间有 {{ r.waiting_units }} 台在等</span>
              <span v-if="r.prepared"> · 已有配料单待发出</span> · 计划开工 {{ r.planned_start_date || '未排' }}
            </div>
            <div>还要配：{{ r.summary }}</div>
          </div>
        </template>

        <template v-else>
          <div v-if="!rows.length" class="empty">暂无配料单</div>
          <div v-for="d in rows" :key="d.id" class="m-row">
            <div class="m-head">
              <span>
                <b class="mono">{{ d.delivery_no }}</b>
                <span class="lk mono" @click="openWorkOrder(d.work_order_id)">{{ d.work_order_no }}</span> · {{ d.operation_name }} · {{ d.item_name }}
                <span v-if="d.unit_qty"> · {{ qty(d.unit_qty) }} 台的料</span>
              </span>
              <span :class="['chip', chip(d.status)]">{{ d.status_label }}</span>
            </div>
            <div class="picks">
              <div v-for="l in d.lines" :key="l.material_id" class="pick-line">
                <span class="name">{{ l.component_item_name }} <b>{{ qty(l.qty) }}</b> {{ l.unit_name || '' }}</span>
                <span v-for="(p, i) in l.picks" :key="i" class="sub pick">
                  {{ [p.warehouse_name, p.location_code].filter(Boolean).join(' / ') || '-' }}<template v-if="p.batch_no"> · 批次 {{ p.batch_no }}</template> · {{ qty(p.qty) }}
                  <template v-if="p.serial_nos && p.serial_nos.length"> · {{ p.serial_nos.join('、') }}</template>
                </span>
              </div>
            </div>
            <div class="sub">
              配料 {{ d.prepared_by_name || '' }} {{ d.prepared_at || '' }}
              <template v-if="d.dispatched_at"> · 发出 {{ d.dispatched_by_name || '' }} {{ d.dispatched_at }}<template v-if="d.issue_no">（{{ d.issue_no }}）</template></template>
              <template v-if="d.signed_at"> · 签收 {{ d.signed_by_name || '' }} {{ d.signed_at }}</template>
            </div>
            <div v-if="d.reject_reason" class="sub danger">拒收：{{ d.reject_reason }}（{{ d.rejected_by_name || '' }} {{ d.rejected_at || '' }}）</div>
            <div v-if="d.close_remark" class="sub">处理：{{ d.close_remark }}<template v-if="d.return_no">（退料单 {{ d.return_no }}）</template></div>
            <div v-if="d.source_problems && d.source_problems.length" class="sub danger">拣货来源已失效：{{ d.source_problems.join('；') }}。请重新拣货后再发出。</div>
            <div v-if="canManage" class="m-foot">
              <template v-if="d.status === 'prepared'">
                <span v-if="!(d.source_problems && d.source_problems.length)" class="lk" @click="act(d, 'dispatch', `确认已照单拣好 ${d.delivery_no} 的料并发出？发出后按拣货单的库位、批次扣库存。`)">发出</span>
                <span class="lk" @click="act(d, 'repick', `按先入先用给 ${d.delivery_no} 重新分配拣货库位？已拣出的料请放回原位，按新拣货单取料。`)">重新拣货</span>
                <span class="lk muted" @click="act(d, 'cancel', `取消配料单 ${d.delivery_no}？不动库存。`)">取消</span>
              </template>
              <template v-if="d.status === 'rejected'">
                <span class="lk" @click="redeliver(d)">重新送达</span>
              </template>
              <span v-if="['dispatched', 'rejected'].includes(d.status)" class="lk danger" @click="recall(d)">收回入库</span>
            </div>
          </div>
        </template>

        <div class="pg">
          <span>共 {{ total }} 条</span>
          <el-pagination background small layout="prev, pager, next" :current-page="page" :page-size="10" :total="total" @current-change="v => { page = v; load() }" />
        </div>
      </div>
    </div>

    <el-dialog :title="prep.row ? `配料 · ${prep.row.work_order_no} ${prep.row.operation_name}` : '配料'" :visible.sync="prep.visible" :width="dialogWidth" custom-class="master-style-dialog" :close-on-click-modal="false">
      <div v-loading="prep.loading">
        <div class="units-row">
          <span>这次配几台的料</span>
          <el-input-number v-model="prep.units" size="small" :min="1" :max="prep.preview ? Math.max(1, prep.preview.planned_qty) : 100000" placeholder="全部" controls-position="right" @change="loadPreview" />
          <el-button type="text" size="mini" @click="prep.units = undefined; loadPreview()">配齐剩下的</el-button>
        </div>
        <p v-if="prep.preview" class="sub">共 {{ qty(prep.preview.planned_qty) }} 台，料已够 {{ qty(prep.preview.covered_units) }} 台，这次配到第 {{ qty(prep.preview.target_units) }} 台。拣货位置按先入先用推荐。</p>
        <div v-if="prep.preview && prep.preview.short.length" class="warn">库存不够：{{ prep.preview.short.join('；') }}。可以少配几台，或找采购。</div>
        <div v-for="l in (prep.preview ? prep.preview.lines : [])" :key="l.material_id" class="pick-line">
          <span class="name">{{ l.component_item_name }} <b>{{ qty(l.qty) }}</b> {{ l.unit_name || '' }}<span v-if="l.short_qty > 0" class="danger">（缺 {{ qty(l.short_qty) }}）</span></span>
          <span v-for="(p, i) in l.picks" :key="i" class="sub pick">
            {{ [p.warehouse_name, p.location_code].filter(Boolean).join(' / ') || '-' }}<template v-if="p.batch_no"> · 批次 {{ p.batch_no }}</template> · {{ qty(p.qty) }}
            <template v-if="p.serial_nos && p.serial_nos.length"> · {{ p.serial_nos.join('、') }}</template>
          </span>
        </div>
        <el-input v-model="prep.remark" class="remark" size="small" maxlength="500" placeholder="备注（选填）" />
      </div>
      <span slot="footer">
        <el-button size="small" @click="prep.visible = false">取消</el-button>
        <el-button size="small" :loading="saving" :disabled="!canSubmitPrep" @click="submitPrep(false)">生成配料单</el-button>
        <el-button size="small" type="primary" :loading="saving" :disabled="!canSubmitPrep" @click="submitPrep(true)">配好直接发出</el-button>
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
    loading: false, saving: false, rows: [], total: 0, page: 1, summary: {}, canManage: false, keyword: '', status: 'pending',
    tabs: [
      { value: 'pending', label: '待配料' }, { value: 'prepared', label: '待发出', count: 'prepared' }, { value: 'dispatched', label: '待签收', count: 'dispatched' },
      { value: 'rejected', label: '拒收待处理', count: 'rejected' }, { value: 'signed', label: '已签收' }, { value: '', label: '全部' }
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
          this.summary = meta.summary || {}
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
.tabs { display: flex; gap: 4px; border-bottom: 1px solid #e3e8f0; margin-bottom: 12px; overflow-x: auto; }
.tabs span { padding: 8px 14px; color: #5b6780; border-bottom: 2px solid transparent; cursor: pointer; white-space: nowrap; }
.tabs span.on { color: #1f6feb; border-color: #1f6feb; font-weight: 600; }
.tabs em { font-style: normal; font-size: 11px; background: #eef1f5; border-radius: 8px; padding: 0 6px; margin-left: 4px; }
.flt { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; align-items: center; }
.flt .w { width: 320px; }
.empty { color: #8a95a8; text-align: center; padding: 32px 0; }
.danger { color: #d92d20; }
.lk + .lk { margin-left: 10px; }
.lk.danger { color: #d92d20; }
.lk.muted { color: #5b6780; }
.m-row { border: 1px solid #e3e8f0; border-radius: 10px; padding: 12px; margin-bottom: 10px; display: grid; gap: 6px; font-size: 12px; }
.m-head, .m-foot { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; align-items: center; }
.m-head .lk.mono { margin: 0 6px; }
.m-foot { justify-content: flex-end; }
.picks { display: grid; gap: 4px; }
.pick-line { display: flex; gap: 8px; flex-wrap: wrap; align-items: baseline; padding: 4px 0; border-bottom: 1px dashed #eef1f5; }
.pick-line .name { min-width: 180px; }
.pick { margin-right: 8px; }
.units-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
.warn { color: #b54708; background: #fffaeb; border-radius: 6px; padding: 8px 10px; margin: 8px 0; font-size: 12px; }
.remark { margin-top: 12px; }
.pg { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; color: #8a95a8; font-size: 12px; flex-wrap: wrap; gap: 8px; }
@media (max-width: 767px) {
  .flt .w { width: 100%; }
}
</style>
