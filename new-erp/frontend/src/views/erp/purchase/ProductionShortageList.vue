<template>
  <section class="mfg-page" v-loading="loading">
    <div class="mfg-hero">
      <div class="mfg-crumb">采购管理 / 生产缺料</div>
      <div class="mfg-hrow">
        <div>
          <div class="mfg-title">生产缺料</div>
          <div class="mfg-hero-sub">未结束工单（含计划）还没领的外购件，按优先级（特急→可延后）、同级先建的工单先分配库存和采购在途后的缺口；勾选后合并生成一张采购需求</div>
        </div>
      </div>
    </div>
    <div class="mfg-body">
      <div class="card">
        <div class="flt">
          <el-input v-model="keyword" class="w" size="small" clearable placeholder="搜物料编码 / 名称 / 工单号" prefix-icon="el-icon-search" @keyup.enter.native="search" @clear="search" />
          <el-checkbox v-model="showAll" @change="search">也显示不缺的</el-checkbox>
          <el-button size="small" icon="el-icon-refresh" @click="load">刷新</el-button>
          <span class="sum">缺料 {{ summary.short_item_count || 0 }} 种 · 涉及工单 {{ summary.work_order_count || 0 }} 张</span>
          <el-button v-if="canConvert" size="small" type="primary" class="right" :disabled="!picked.length" @click="openConvert">生成采购需求（{{ picked.length }}）</el-button>
        </div>

        <div class="desktop-only">
          <el-table ref="table" :data="rows" row-key="item_id" class="shortage-table" empty-text="暂无缺料" @selection-change="v => { picked = v }">
            <el-table-column v-if="canConvert" type="selection" width="40" :selectable="r => r.shortage_qty > 0" reserve-selection />
            <el-table-column type="expand" width="30">
              <template slot-scope="s">
                <div class="wo-list">
                  <div v-for="w in s.row.work_orders" :key="w.id" class="wo-row">
                    <span class="lk mono" @click="openWorkOrder(w)">{{ w.work_order_no }}</span>
                    <span :class="['chip', w.status === 'planned' ? 'c-n' : 'c-b']">{{ w.status_label }}</span>
                    <span v-if="w.priority === 1" class="chip c-r">特急</span><span v-else-if="w.is_urgent" class="chip c-r">加急</span><span v-else-if="w.priority === 4" class="chip">可延后</span>
                    <span class="sub">{{ w.item_name }} · 开工 {{ w.planned_start_date || '未排' }}</span>
                    <span>还要 {{ qty(w.need_qty) }} = 库存 {{ qty(w.stock_qty) }} + 在途 {{ qty(w.transit_qty) }} + <b :class="{ danger: w.shortage_qty > 0 }">缺 {{ qty(w.shortage_qty) }}</b></span>
                    <span v-if="w.shortage_qty > 0 && w.ahead && w.ahead.length" class="sub">库存先给 {{ w.ahead.map(a => a.work_order_no).join('、') }}</span>
                  </div>
                  <div v-if="s.row.in_transit_detail" class="sub">在途明细：{{ transitText(s.row) }}</div>
                  <div v-if="s.row.requests.length" class="sub">已转采购：{{ s.row.requests.map(r => `${r.request_no}（${r.request_status_label}）${qty(r.qty)}`).join('、') }}</div>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="物料" min-width="200">
              <template slot-scope="s">
                <div>{{ s.row.item_name }} <span v-if="s.row.priority === 1" class="chip c-r">特急</span><span v-else-if="s.row.is_urgent" class="chip c-r">加急</span><span v-else-if="s.row.priority === 4" class="chip">可延后</span></div>
                <div class="sub mono">{{ s.row.item_code }}<span v-if="s.row.spec"> · {{ s.row.spec }}</span></div>
              </template>
            </el-table-column>
            <el-table-column label="还要领" width="100" align="right"><template slot-scope="s">{{ qty(s.row.need_qty) }}</template></el-table-column>
            <el-table-column label="库存可用" width="100" align="right"><template slot-scope="s">{{ qty(s.row.available_qty) }}</template></el-table-column>
            <el-table-column label="采购在途" width="100" align="right">
              <template slot-scope="s">
                <el-tooltip v-if="s.row.in_transit_detail" placement="top">
                  <div slot="content">
                    <div v-for="d in s.row.in_transit_detail.documents" :key="d.stage + d.document_no">{{ d.stage_label }} {{ d.document_no }}：{{ qty(d.qty) }}</div>
                  </div>
                  <span class="lk">{{ qty(s.row.in_transit_qty) }}</span>
                </el-tooltip>
                <span v-else>{{ qty(s.row.in_transit_qty) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="缺口" width="110" align="right">
              <template slot-scope="s"><b :class="{ danger: s.row.shortage_qty > 0 }">{{ qty(s.row.shortage_qty) }}</b> {{ s.row.unit_name || '' }}</template>
            </el-table-column>
            <el-table-column label="最早开工" width="110" prop="earliest_date" />
            <el-table-column label="工单" width="70" align="center"><template slot-scope="s">{{ s.row.work_orders.length }}</template></el-table-column>
          </el-table>
        </div>

        <div class="mobile-only">
          <div v-if="!rows.length" class="empty">暂无缺料</div>
          <div v-for="row in rows" :key="row.item_id" class="m-row">
            <div class="m-head">
              <el-checkbox v-if="canConvert" :value="isPicked(row)" :disabled="!(row.shortage_qty > 0)" @change="v => togglePick(row, v)">{{ row.item_name }}</el-checkbox>
              <span v-else>{{ row.item_name }}</span>
              <b :class="{ danger: row.shortage_qty > 0 }">缺 {{ qty(row.shortage_qty) }} {{ row.unit_name || '' }}</b>
            </div>
            <div class="sub mono">{{ row.item_code }}<span v-if="row.spec"> · {{ row.spec }}</span></div>
            <div class="sub">还要 {{ qty(row.need_qty) }} · 库存 {{ qty(row.available_qty) }} · 在途 {{ qty(row.in_transit_qty) }} · 最早 {{ row.earliest_date || '未排' }}</div>
            <div v-if="row.in_transit_detail" class="sub">在途：{{ transitText(row) }}</div>
            <div class="sub">
              <span v-for="w in row.work_orders" :key="w.id" class="m-wo"><span class="lk" @click="openWorkOrder(w)">{{ w.work_order_no }}</span> 缺 {{ qty(w.shortage_qty) }}</span>
            </div>
            <div v-if="row.requests.length" class="sub">已转：{{ row.requests.map(r => r.request_no).join('、') }}</div>
          </div>
        </div>

        <div class="pg">
          <span>共 {{ total }} 条</span>
          <el-pagination background small layout="prev, pager, next" :current-page="page" :page-size="10" :total="total" @current-change="v => { page = v; load() }" />
        </div>
      </div>
    </div>
    <shortage-purchase-dialog :visible.sync="dialog" :rows="picked" @done="done" />
  </section>
</template>

<script>
import { listProductionShortages } from '../../../api/erp/purchase'
import ShortagePurchaseDialog from './ShortagePurchaseDialog.vue'
import { qty, errorText } from '../production/mfg-helpers'

export default {
  name: 'ProductionShortageList',
  components: { ShortagePurchaseDialog },
  data: () => ({ loading: false, rows: [], total: 0, page: 1, summary: {}, canConvert: false, keyword: '', showAll: false, picked: [], dialog: false }),
  created() { this.load() },
  methods: {
    qty,
    transitText(row) {
      const labels = { request: '需求', plan: '计划', order: '订单', receipt: '到货待入库', exchange: '换货待补' }
      const stages = (row.in_transit_detail && row.in_transit_detail.stages) || {}
      return Object.keys(labels).filter(k => stages[k] > 0).map(k => `${labels[k]} ${qty(stages[k])}`).join(' · ')
    },
    async load() {
      this.loading = true
      try {
        const { data } = await listProductionShortages({ page: this.page, per_page: 10, keyword: this.keyword || undefined, only_short: this.showAll ? 0 : 1 })
        this.rows = data.data || []
        this.total = data.total || 0
        this.summary = data.summary || {}
        this.canConvert = !!data.can_convert
      } catch (e) {
        this.$message.error(errorText(e, '生产缺料加载失败'))
      } finally {
        this.loading = false
      }
    },
    search() { this.page = 1; this.load() },
    isPicked(row) { return this.picked.some(p => p.item_id === row.item_id) },
    togglePick(row, on) {
      this.picked = on ? [...this.picked.filter(p => p.item_id !== row.item_id), row] : this.picked.filter(p => p.item_id !== row.item_id)
    },
    openConvert() { this.dialog = true },
    openWorkOrder(w) {
      if (this.$can('production.work_order.view')) this.$router.push(`/production/work-orders/${w.id}`)
    },
    done(res) {
      this.picked = []
      if (this.$refs.table) this.$refs.table.clearSelection()
      this.load()
      if (res && res.id && this.$can('purchase.request')) {
        this.$confirm(`采购需求 ${res.request_no} 已生成，去看看？`, '已生成', { confirmButtonText: '去查看', cancelButtonText: '留在这里', type: 'success' })
          .then(() => this.$router.push(`/purchase/requests/${res.id}/detail`)).catch(() => {})
      }
    }
  }
}
</script>

<style scoped src="../production/mfg-v2.css"></style>
<style scoped>
.flt { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; align-items: center; }
.flt .w { width: 300px; }
.flt .sum { color: #5b6780; font-size: 12px; }
.flt .right { margin-left: auto; }
.shortage-table ::v-deep th { color: #8a95a8; font-weight: 500; font-size: 12px; background: #fff; }
.shortage-table ::v-deep td { font-size: 12px; }
.danger { color: #d92d20; }
.wo-list { display: grid; gap: 6px; padding: 4px 12px; font-size: 12px; }
.wo-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.pg { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; color: #8a95a8; font-size: 12px; flex-wrap: wrap; gap: 8px; }
.mobile-only { display: none; }
.m-row { border: 1px solid #e3e8f0; border-radius: 10px; padding: 12px; margin-bottom: 10px; display: grid; gap: 6px; font-size: 12px; }
.m-head { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; align-items: center; }
.m-wo { margin-right: 10px; }
@media (max-width: 767px) {
  .desktop-only { display: none; }
  .mobile-only { display: block; }
  .flt .w { width: 100%; }
  .flt .right { margin-left: 0; width: 100%; }
}
</style>
