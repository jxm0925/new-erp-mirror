<template>
  <section class="mfg-page" v-loading="loading && !board">
    <div class="mfg-hero">
      <div class="mfg-crumb">生产管理 / 生产作业看板</div>
      <div class="mfg-hrow">
        <div class="mfg-title">车间现在怎么样 <span class="pill p-n">每 30 秒自动刷新</span></div>
        <div class="sub-light" v-if="board">更新于 {{ (board.refreshed_at || '').slice(11, 16) }}</div>
      </div>
      <div class="stats" v-if="board">
        <div class="stat"><span>在制</span><b>{{ board.stats.in_progress_units }} 台</b></div>
        <div class="stat"><span>今天完成工序</span><b>{{ board.stats.today_passed }} 次</b></div>
        <div class="stat"><span>没人接</span><b class="y">{{ board.stats.unclaimed }} 个任务</b></div>
        <div class="stat"><span>返工中</span><b class="r">{{ board.stats.rework_units }} 台</b></div>
        <div class="stat"><span>等入库</span><b class="g">{{ board.stats.waiting_receipt }} 台</b></div>
        <div class="stat"><span>今天工时</span><b>{{ board.stats.today_hours }} 时</b></div>
      </div>
    </div>

    <div class="mfg-body">
      <div class="toolbar">
        <el-input v-model="filters.keyword" size="small" clearable placeholder="搜工单号 / 序列号 / 产品" class="kw" @keyup.enter.native="load" @clear="load" />
        <el-select v-model="filters.owner_legacy_id" size="small" clearable placeholder="全部负责人" @change="load">
          <el-option v-for="u in (board ? board.owner_options : [])" :key="u.id" :label="u.name" :value="u.id" />
        </el-select>
        <el-checkbox v-model="filters.urgent_only" border size="small" @change="load">只看加急</el-checkbox>
        <el-checkbox v-model="filters.custom_only" border size="small" @change="load">只看定制</el-checkbox>
        <span class="legend"><i class="lg-q" />排队 <i class="lg-d" />正在做 <i class="lg-r" />返工 <i class="lg-g" />做完</span>
      </div>

      <div class="board-grid" v-if="board">
        <div class="lanes">
          <div v-for="col in board.columns" :key="col.key" class="lane">
            <div class="lane-h"><b>{{ col.title }}</b><span>{{ col.unit_count }} 台 · {{ col.people_count }} 人</span></div>
            <div v-for="c in col.cards" :key="c.operation_id" :class="['bcard', { rw: c.rework_count, paused: c.paused }]">
              <div class="bc-top"><span class="mono sub">{{ c.work_order_no }}</span>
                <span>
                  <span v-if="c.paused" class="chip c-n">暂停</span>
                  <span v-if="c.is_urgent" class="chip c-r">加急</span>
                  <span v-if="c.is_custom" class="chip c-p">定制{{ c.has_drawing ? ' · 有图纸' : '' }}</span>
                </span>
              </div>
              <div class="bc-name">{{ c.product_name }}<template v-if="c.sku_name"> {{ c.sku_name }}</template> ×{{ qtyText(c.planned_qty) }}</div>
              <div class="bc-op sub" v-if="c.operation_name !== col.title">{{ c.operation_name }}</div>
              <div class="sns">
                <span v-for="u in c.units" :key="u.id" :class="['sn', u.status === 'rework' ? 'bad' : (u.status === 'waiting' ? 'q' : 'd')]" :title="u.serial_no">{{ u.short }}<em v-if="u.pending_scrap">待报废</em><em v-else-if="u.status === 'rework'">返工</em></span>
              </div>
              <div class="bc-foot">
                <span v-if="c.paused" class="sub">{{ c.pause_reason || '工单暂停' }}</span>
                <span v-else-if="c.state === 'unclaimed'" class="warn">没人接</span>
                <span v-else>{{ c.owner_name }} · <template v-if="c.timing.length">在做{{ c.timing.length > 1 ? `（${c.timing.length} 人）` : '' }}，</template><template v-if="c.labor_minutes">已做 {{ minutesText(c.labor_minutes) }}</template><template v-else>已接，没开始</template></span>
                <span>
                  <a v-if="board.permissions.assign && !c.paused" class="lk" @click="openAssign(c)">{{ c.state === 'unclaimed' ? '派工' : '换人' }}</a>
                  <a class="lk" @click="openWo(c.work_order_id)">详情</a>
                </span>
              </div>
            </div>
            <div v-if="!col.cards.length" class="empty">没有在做的</div>
          </div>
          <div v-if="!board.columns.length" class="lane"><div class="lane-h"><b>车间</b></div><div class="empty">现在没有在做的工单</div></div>

          <div class="lane">
            <div class="lane-h"><b>等入库</b><span>{{ board.stats.waiting_receipt }} 台</span></div>
            <div v-for="w in board.waiting_receipt" :key="w.work_order_id" class="bcard">
              <div class="bc-top"><span class="mono sub">{{ w.work_order_no }}</span></div>
              <div class="bc-name">{{ w.product_name }}<template v-if="w.sku_name"> {{ w.sku_name }}</template></div>
              <div class="sns"><span v-for="u in w.units" :key="u.id" class="sn g" :title="u.serial_no">{{ u.short }}</span></div>
              <div class="bc-foot"><span class="sub">{{ w.last_operation_name || '最后一道' }}合格 {{ lastPassed(w) }}</span>
                <a v-if="board.permissions.receipt" class="lk" @click="openReceipt(w)">确认入库</a></div>
            </div>
            <div v-if="!board.waiting_receipt.length" class="empty">没有等入库的</div>
          </div>

          <div class="lane">
            <div class="lane-h"><b>今天入库</b><span>{{ todayStored }} 台</span></div>
            <div v-for="r in board.today_receipts" :key="r.id" class="bcard">
              <div class="bc-top"><span class="mono sub">{{ r.work_order_no }}</span><span class="chip c-b">待试水</span></div>
              <div class="bc-name">{{ r.product_name }}<template v-if="r.sku_name"> {{ r.sku_name }}</template></div>
              <div class="sns"><span v-for="sn in r.serial_nos" :key="sn" class="sn g" :title="sn">{{ sn.slice(-4) }}</span></div>
              <div class="bc-foot"><span class="sub">{{ r.operator_name }} · {{ r.warehouse_name }} {{ r.location_code }}</span></div>
            </div>
            <div v-if="!board.today_receipts.length" class="empty">今天还没入库</div>
          </div>
        </div>

        <div class="side">
          <div class="card" v-if="board.pending_scraps.length">
            <h2>待确认报废 <small>{{ board.pending_scraps.length }} 台</small></h2>
            <div v-for="s in board.pending_scraps" :key="s.id" class="srow">
              <div><b class="mono">{{ s.serial_no }}</b> <span class="sub">{{ s.operation_name }} · {{ s.reporter_name }}</span></div>
              <div class="sub">{{ s.product_name }} · {{ [s.reason_tags.join('/'), s.reason].filter(Boolean).join('：') }}</div>
              <div v-if="s.photos.length" class="photos"><el-image v-for="p in s.photos" :key="p" :src="p" :preview-src-list="s.photos" fit="cover" /></div>
              <div v-if="board.permissions.scrap" class="srow-act">
                <el-button size="mini" type="danger" plain @click="review(s, true)">确认报废</el-button>
                <el-button size="mini" @click="review(s, false)">驳回，继续返工</el-button>
              </div>
            </div>
          </div>
          <div class="card">
            <h2>没人接的任务 <small>{{ board.unclaimed.length }} 个</small></h2>
            <div v-for="t in board.unclaimed" :key="t.operation_id" class="urow">
              <div><b>{{ t.operation_name }} · {{ t.product_name }} ×{{ t.qty }}</b><div class="sub">{{ t.is_custom ? '定制 · ' : '' }}交期 {{ shortDate(t.planned_finish_date) }}</div></div>
              <el-button v-if="board.permissions.assign" size="mini" @click="openAssign(t)">派给…</el-button>
            </div>
            <div v-if="!board.unclaimed.length" class="sub">都有人接了</div>
            <p class="sub">工人也可以在小程序里自己接。</p>
          </div>
          <div class="card">
            <h2>最新报工</h2>
            <div v-for="r in board.recent_reports" :key="r.id" class="rrow">
              <span class="sub">{{ r.time }}</span>
              <span>{{ r.reporter_name }} {{ r.operation_name }} <span class="mono">{{ r.short }}</span>
                <b v-if="r.result === 'passed'" class="ok">合格</b>
                <template v-else-if="r.result === 'failed'"><b class="warn">不合格</b>，{{ r.return_operation_name === r.operation_name ? '本道重做' : '退回' + r.return_operation_name }}<template v-if="r.reason">：{{ r.reason }}</template></template>
                <template v-else><b class="warn">申请报废</b><template v-if="r.reason">：{{ r.reason }}</template></template>
                <span v-if="r.is_rework" class="chip c-o">返工</span>
              </span>
            </div>
            <div v-if="!board.recent_reports.length" class="sub">今天还没人报工</div>
          </div>
        </div>
      </div>
      <div class="note">列按“生产阶段”排，不同产品的工序归到对应阶段；点“详情”打开工单详情看每台进度。返工的台会回到退回的那一列，并标红。</div>
    </div>

    <el-dialog title="派工" :visible.sync="assign.visible" width="420px" custom-class="mfg-dialog" :close-on-click-modal="false">
      <p class="sub">{{ assign.title }}。只能派给这道工序“能做的人”。</p>
      <el-select v-model="assign.employee" filterable remote :remote-method="searchPeople" placeholder="搜索姓名" style="width: 100%" @focus="searchPeople('')">
        <el-option v-for="u in people" :key="u.id" :label="u.name" :value="u.id" />
      </el-select>
      <span slot="footer"><el-button size="small" @click="assign.visible = false">取消</el-button><el-button size="small" type="primary" :loading="saving" :disabled="!assign.employee" @click="saveAssign">派工</el-button></span>
    </el-dialog>

    <el-dialog title="确认入库" :visible.sync="receipt.visible" width="520px" custom-class="mfg-dialog" :close-on-click-modal="false">
      <template v-if="receipt.row">
        <p><b>{{ receipt.row.work_order_no }}</b> {{ receipt.row.product_name }}</p>
        <el-form label-position="top" size="small">
          <el-form-item label="入库的台">
            <el-checkbox-group v-model="receipt.unit_ids">
              <el-checkbox v-for="u in receipt.row.units" :key="u.id" :label="u.id">{{ u.serial_no }}</el-checkbox>
            </el-checkbox-group>
          </el-form-item>
          <el-form-item label="仓库">
            <el-select v-model="receipt.warehouse_id" filterable placeholder="选仓库" style="width: 100%" @change="receipt.location_id = null">
              <el-option v-for="w in warehouses" :key="w.id" :label="w.warehouse_name" :value="w.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="库位">
            <el-select v-model="receipt.location_id" filterable placeholder="选库位" style="width: 100%">
              <el-option v-for="l in receiptLocations" :key="l.id" :label="[l.location_code, l.location_name].filter(Boolean).join(' ')" :value="l.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="备注"><el-input v-model="receipt.remark" maxlength="500" /></el-form-item>
        </el-form>
        <p class="sub">入库后显示“待试水”；按每台暂估材料成本入账。</p>
      </template>
      <span slot="footer"><el-button size="small" @click="receipt.visible = false">取消</el-button><el-button size="small" type="primary" :loading="saving" :disabled="!receipt.unit_ids.length || !receipt.location_id" @click="saveReceipt">确认入库</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { getProductionBoard, taskAction, listTaskPeople, reviewScrapReport, receiveWorkOrderUnits } from '../../../api/erp/production'
import { listEntity } from '../../../api/erp/master'
import { qty, shortDate, errorText, isCancel } from './mfg-helpers'

export default {
  name: 'ProductionBoard',
  data: () => ({
    loading: false, saving: false, board: null, timer: null, people: [], warehouses: [], locations: [],
    filters: { keyword: '', owner_legacy_id: null, urgent_only: false, custom_only: false },
    assign: { visible: false, op: null, title: '', employee: null },
    receipt: { visible: false, row: null, unit_ids: [], warehouse_id: null, location_id: null, remark: '' }
  }),
  computed: {
    todayStored() { return this.board ? this.board.today_receipts.reduce((s, r) => s + Number(r.qty || 0), 0) : 0 },
    receiptLocations() { return this.locations.filter(l => Number(l.warehouse_id) === Number(this.receipt.warehouse_id)) }
  },
  created() {
    this.load()
    this.timer = setInterval(() => { if (!this.assign.visible && !this.receipt.visible) this.load(true) }, 30000)
  },
  beforeDestroy() { clearInterval(this.timer) },
  methods: {
    shortDate,
    qtyText: qty,
    minutesText(m) {
      const total = Math.round(Number(m || 0))
      const h = Math.floor(total / 60)
      return h ? `${h} 时 ${String(total % 60).padStart(2, '0')} 分` : `${total} 分`
    },
    lastPassed(w) {
      const t = w.units.map(u => u.passed_at).filter(Boolean).sort().pop()
      return t ? t.slice(5, 10) : ''
    },
    async load(silent) {
      if (!silent) this.loading = true
      try {
        const params = { ...this.filters, urgent_only: this.filters.urgent_only ? 1 : undefined, custom_only: this.filters.custom_only ? 1 : undefined,
          owner_legacy_id: this.filters.owner_legacy_id || undefined, keyword: this.filters.keyword || undefined }
        const { data } = await getProductionBoard(params)
        this.board = data.data
      } catch (e) {
        if (!silent) this.$message.error(errorText(e, '看板加载失败'))
      } finally {
        this.loading = false
      }
    },
    openWo(id) { this.$router.push(`/production/work-orders/${id}`) },
    openAssign(c) {
      this.assign = { visible: true, op: c.operation_id, title: `${c.operation_name} · ${c.product_name}`, employee: null }
      this.people = []
    },
    async searchPeople(keyword) {
      if (!this.assign.op) return
      try { const { data } = await listTaskPeople(this.assign.op, { keyword: keyword || undefined }); this.people = data.data || [] } catch (e) { this.people = [] }
    },
    async saveAssign() {
      this.saving = true
      try {
        const { data } = await taskAction(this.assign.op, { action: 'assign', employee_legacy_id: this.assign.employee })
        this.$message.success(data.message || '已派工')
        this.assign.visible = false
        this.load(true)
      } catch (e) {
        this.$message.error(errorText(e, '派工失败'))
      } finally {
        this.saving = false
      }
    },
    async review(s, approve) {
      let remark = ''
      try {
        const r = await this.$prompt(approve ? `确认 ${s.serial_no} 报废？报废后这台不再生产。` : `驳回后 ${s.serial_no} 继续在${s.operation_name}返工。`,
          approve ? '确认报废' : '驳回报废', { inputPlaceholder: '说明（可不填）', confirmButtonText: approve ? '确认报废' : '驳回' })
        remark = r.value || ''
      } catch (e) { if (isCancel(e)) return }
      try {
        const { data } = await reviewScrapReport(s.id, { approve, remark: remark || undefined })
        this.$message.success(data.message)
        this.load(true)
      } catch (e) {
        this.$message.error(errorText(e, '操作失败'))
      }
    },
    async openReceipt(w) {
      this.receipt = { visible: true, row: w, unit_ids: w.units.map(u => u.id), warehouse_id: w.warehouse_id || null, location_id: null, remark: '' }
      if (!this.warehouses.length) {
        try {
          const [ws, ls] = await Promise.all([listEntity('warehouses', { per_page: 200 }), listEntity('locations', { per_page: 500 })])
          this.warehouses = ws.data.data || []
          this.locations = ls.data.data || []
        } catch (e) { this.$message.error(errorText(e, '仓库库位加载失败')) }
      }
    },
    async saveReceipt() {
      const r = this.receipt
      this.saving = true
      try {
        const { data } = await receiveWorkOrderUnits(r.row.work_order_id, { unit_ids: r.unit_ids, warehouse_id: r.warehouse_id, location_id: r.location_id, remark: r.remark || undefined })
        this.$message.success(data.message || '已入库')
        r.visible = false
        this.load(true)
      } catch (e) {
        this.$message.error(errorText(e, '入库失败'))
      } finally {
        this.saving = false
      }
    }
  }
}
</script>

<style scoped src="./mfg-v2.css"></style>
<style scoped>
.sub-light { color: #b9c5dc; font-size: 12px; }
.stats { display: flex; gap: 12px; margin-top: 14px; flex-wrap: wrap; }
.stat { background: #1c2b4d; border-radius: 10px; padding: 10px 16px; min-width: 120px; }
.stat span { display: block; color: #b9c5dc; font-size: 12px; }
.stat b { display: block; font-size: 22px; margin-top: 4px; }
.stat b.y { color: #ffb020; } .stat b.r { color: #ff5a5f; } .stat b.g { color: #4ade80; }
.toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
.toolbar .kw { width: 260px; }
.legend { margin-left: auto; color: #5b6780; font-size: 12px; display: flex; align-items: center; gap: 4px; }
.legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-left: 10px; }
.lg-q { background: #eef1f5; } .lg-d { background: #fff1e0; } .lg-r { background: #ffe9e7; } .lg-g { background: #e6f7ef; }
.board-grid { display: grid; grid-template-columns: minmax(0, 1fr) 290px; gap: 16px; align-items: start; }
.lanes { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 8px; align-items: flex-start; }
.lane { background: #e3e8f0; border-radius: 12px; padding: 10px; width: 230px; flex: 0 0 230px; min-height: 200px; }
.lane-h { display: flex; justify-content: space-between; align-items: center; padding: 2px 4px 10px; }
.lane-h span { color: #5b6780; font-size: 12px; }
.bcard { background: #fff; border-radius: 10px; padding: 10px 11px; margin-bottom: 10px; box-shadow: 0 1px 2px rgba(20, 33, 61, .06); }
.bcard.rw { border-left: 3px solid #ff5a5f; }
.bcard.paused { opacity: .7; }
.bc-top { display: flex; justify-content: space-between; align-items: center; gap: 6px; }
.bc-top .chip { margin-left: 4px; }
.bc-name { font-weight: 700; margin: 6px 0 4px; }
.bc-op { margin-bottom: 4px; }
.sns { display: flex; flex-wrap: wrap; gap: 4px; margin: 6px 0; }
.sn { font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace; font-size: 11px; padding: 1px 6px; border-radius: 4px; background: #eef1f5; color: #5b6780; }
.sn.d { background: #fff1e0; color: #b25c00; } .sn.bad { background: #ffe9e7; color: #c0261b; } .sn.g { background: #e6f7ef; color: #11804a; }
.sn em { font-style: normal; margin-left: 3px; }
.bc-foot { display: flex; justify-content: space-between; gap: 6px; font-size: 12px; align-items: flex-end; }
.bc-foot .lk { margin-left: 8px; }
.side .card h2 { margin-bottom: 8px; }
.urow { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px solid #f0f2f6; }
.rrow { display: grid; grid-template-columns: 40px 1fr; gap: 6px; padding: 7px 0; border-bottom: 1px solid #f0f2f6; font-size: 12px; line-height: 1.6; }
.srow { padding: 8px 0; border-bottom: 1px solid #f0f2f6; line-height: 1.7; }
.srow-act { margin-top: 6px; }
.photos { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
.photos .el-image { width: 54px; height: 54px; border-radius: 6px; }
.note { margin-top: 6px; }
@media (max-width: 1100px) { .board-grid { grid-template-columns: 1fr; } }
@media (max-width: 767px) {
  .toolbar .kw { width: 100%; }
  .legend { margin-left: 0; }
  .lane { width: 82vw; flex-basis: 82vw; }
  ::v-deep .mfg-dialog { width: 94% !important; }
}
</style>
