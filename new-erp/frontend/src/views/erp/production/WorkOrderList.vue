<template>
  <section class="mfg-page" v-loading="loading">
    <div class="mfg-hero">
      <div class="mfg-crumb">生产管理 / 工单</div>
      <div class="mfg-hrow">
        <div>
          <div class="mfg-title">工单</div>
          <div class="mfg-hero-sub">订单确认后自动生成并下达；生产主管只能调整；备货工单可手动新建</div>
        </div>
        <div class="mfg-actions">
          <el-button v-if="$can('production.work_order.create')" class="primary" icon="el-icon-plus" @click="$router.push('/production/work-orders/new')">新建备货工单</el-button>
        </div>
      </div>
    </div>
    <div class="mfg-body">
      <div class="tiles">
        <div class="tile" style="border-color:#60a5fa" @click="pickTab('released')"><span>已下达待开工</span><b>{{ summary.waiting_start || 0 }}</b><small>序列号已生成，等车间开工</small></div>
        <div class="tile" style="border-color:#ffb020" @click="pickTab('in_progress')"><span>生产中</span><b>{{ summary.in_progress || 0 }}</b><small>共 {{ summary.in_progress_units || 0 }} 台在制</small></div>
        <div class="tile" style="border-color:#ff5a5f" @click="toggleStuck"><span>卡住了</span><b :class="{ warn: summary.stuck }">{{ summary.stuck || 0 }}</b><small>其中暂停 {{ summary.stuck_paused || 0 }}</small></div>
        <div class="tile" style="border-color:#4ade80"><span>本周要交</span><b>{{ summary.due_this_week || 0 }}</b><small>按计划完工日</small></div>
        <div class="tile" style="border-color:#ff5a5f"><span>已过计划完工日</span><b :class="{ warn: summary.overdue }">{{ summary.overdue || 0 }}</b><small>需要调整计划或加急</small></div>
      </div>
      <div class="card">
        <div class="tabs">
          <span v-for="tab in tabs" :key="tab.value" :class="{ on: filters.status === tab.value }" @click="pickTab(tab.value)">{{ tab.label }}<em>{{ tabCount(tab.value) }}</em></span>
        </div>
        <div class="flt">
          <el-input v-model="filters.keyword" class="w" size="small" clearable placeholder="搜工单号 / 产品 / 订单号 / 客户 / 序列号" prefix-icon="el-icon-search" @keyup.enter.native="search" @clear="search" />
          <el-date-picker v-model="dueRange" size="small" type="daterange" value-format="yyyy-MM-dd" range-separator="至" start-placeholder="交期从" end-placeholder="到" @change="search" />
          <el-select v-model="filters.owner_legacy_id" size="small" clearable filterable remote :remote-method="searchOwners" placeholder="负责人" @change="search" @focus="searchOwners('')">
            <el-option v-for="u in owners" :key="u.id" :label="u.name" :value="u.id" />
          </el-select>
          <el-select v-model="filters.source_type" size="small" clearable placeholder="来源：全部" @change="search">
            <el-option label="销售订单" value="sales_order" /><el-option label="备货" value="stock" />
          </el-select>
          <el-checkbox v-model="filters.stuck_only" border size="small" @change="search">只看卡住的</el-checkbox>
        </div>

        <div class="desktop-only">
          <el-table :data="rows" class="wo-table" empty-text="暂无工单" @row-click="open">
            <el-table-column label="工单" min-width="210">
              <template slot-scope="s">
                <div class="mono no">{{ s.row.work_order_no }}</div>
                <div class="pn">{{ productName(s.row) }} <b>× {{ qty(s.row.planned_qty) }} {{ s.row.unit_name || '台' }}</b></div>
              </template>
            </el-table-column>
            <el-table-column label="来源" min-width="140">
              <template slot-scope="s">
                <template v-if="s.row.source_type === 'sales_order'"><div>{{ s.row.sales_order_no }}</div><div class="sub">{{ s.row.customer_name }}</div></template>
                <template v-else><span class="chip c-n">备货</span><div class="sub">{{ s.row.stock_reason_label || '' }}</div></template>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="120">
              <template slot-scope="s"><span :class="['chip', chipOf(s.row.status)]">{{ s.row.status_label }}</span> <span v-if="s.row.is_urgent" class="chip c-r">加急</span></template>
            </el-table-column>
            <el-table-column label="每道工序完成几台" min-width="220">
              <template slot-scope="s">
                <div v-if="s.row.progress.length" class="steps">
                  <span v-for="(p, i) in s.row.progress" :key="i" :class="['sp', stepClass(p)]"><i>{{ qty(p.completed_qty) }}/{{ qty(p.planned_qty) }}</i>{{ p.operation_name }}</span>
                </div>
                <span v-else class="sub">未带出工艺路线</span>
              </template>
            </el-table-column>
            <el-table-column label="交期" width="130">
              <template slot-scope="s">
                <div>{{ shortDate(s.row.required_date || s.row.planned_finish_date) }}</div>
                <div :class="dueClass(s.row)">{{ dueText(s.row) }}</div>
              </template>
            </el-table-column>
            <el-table-column label="卡点" min-width="140">
              <template slot-scope="s"><span :class="stuckClass(s.row)">{{ stuckText(s.row) }}</span></template>
            </el-table-column>
            <el-table-column label="负责人" width="90"><template slot-scope="s">{{ s.row.owner_name || '-' }}</template></el-table-column>
            <el-table-column label="" width="60"><template slot-scope="s"><span class="lk" @click.stop="open(s.row)">查看</span></template></el-table-column>
          </el-table>
        </div>

        <div class="mobile-only">
          <div v-if="!rows.length" class="empty">暂无工单</div>
          <div v-for="row in rows" :key="row.id" class="m-row" @click="open(row)">
            <div class="m-head"><span class="mono no">{{ row.work_order_no }}</span><span><span :class="['chip', chipOf(row.status)]">{{ row.status_label }}</span> <span v-if="row.is_urgent" class="chip c-r">加急</span></span></div>
            <div class="pn">{{ productName(row) }} <b>× {{ qty(row.planned_qty) }} {{ row.unit_name || '台' }}</b></div>
            <div class="sub">{{ row.source_type === 'stock' ? '备货 · ' + (row.stock_reason_label || '') : row.sales_order_no + ' · ' + (row.customer_name || '') }}</div>
            <div v-if="row.progress.length" class="steps"><span v-for="(p, i) in row.progress" :key="i" :class="['sp', stepClass(p)]"><i>{{ qty(p.completed_qty) }}/{{ qty(p.planned_qty) }}</i>{{ p.operation_name }}</span></div>
            <div class="m-foot"><span>交期 {{ shortDate(row.required_date || row.planned_finish_date) }} · {{ row.owner_name || '未定负责人' }}</span><span :class="stuckClass(row)">{{ stuckText(row) }}</span></div>
          </div>
        </div>

        <div class="pg">
          <span>共 {{ total }} 条</span>
          <el-pagination background small layout="prev, pager, next" :current-page="page" :page-size="10" :total="total" @current-change="v => { page = v; load() }" />
        </div>
      </div>
    </div>
  </section>
</template>

<script>
import { listWorkOrders } from '../../../api/erp/production'
import { STATUS_CHIP, STATUS_TABS, qty, shortDate, errorText, searchProductionUsers } from './mfg-helpers'

const emptyFilters = () => ({ keyword: '', status: '', owner_legacy_id: '', source_type: '', stuck_only: false })

export default {
  name: 'WorkOrderList',
  data: () => ({ loading: false, rows: [], total: 0, page: 1, summary: {}, filters: emptyFilters(), dueRange: [], owners: [], tabs: STATUS_TABS }),
  created() {
    if (this.$route.query.status) this.filters.status = String(this.$route.query.status)
    this.load()
  },
  methods: {
    qty, shortDate,
    async load() {
      this.loading = true
      try {
        const params = { ...this.filters, page: this.page, per_page: 10 }
        params.stuck_only = this.filters.stuck_only ? 1 : undefined
        if (this.dueRange && this.dueRange.length) { params.due_from = this.dueRange[0]; params.due_to = this.dueRange[1] }
        Object.keys(params).forEach(k => { if (params[k] === '' || params[k] === null) delete params[k] })
        const { data } = await listWorkOrders(params)
        this.rows = data.data || []
        this.total = data.total || 0
        this.summary = data.summary || {}
      } catch (e) {
        this.$message.error(errorText(e, '工单加载失败'))
      } finally {
        this.loading = false
      }
    },
    search() { this.page = 1; this.load() },
    pickTab(status) { this.filters.status = status; this.search() },
    toggleStuck() { this.filters.stuck_only = !this.filters.stuck_only; this.search() },
    tabCount(status) {
      const counts = this.summary.status_counts || {}
      return status ? (counts[status] || 0) : (this.summary.all || 0)
    },
    async searchOwners(keyword) {
      try { this.owners = await searchProductionUsers(keyword) } catch (e) { this.owners = [] }
    },
    open(row) { this.$router.push(`/production/work-orders/${row.id}`) },
    productName(row) { return [row.product_name || row.item_name, row.sku_code].filter(Boolean).join(' ') },
    chipOf(status) { return STATUS_CHIP[status] || 'c-n' },
    stepClass(p) {
      if (p.planned_qty > 0 && p.completed_qty >= p.planned_qty) return 'd'
      return p.completed_qty > 0 ? 'r' : ''
    },
    dueText(row) {
      if (row.status === 'completed' || row.status === 'closed') return '已完工'
      if (row.overdue) return '已过计划完工日'
      if (!row.started) return '未开工'
      return '计划 ' + shortDate(row.planned_finish_date) + ' 完工'
    },
    dueClass(row) { return row.overdue ? 'warn' : (row.status === 'completed' ? 'ok' : 'sub') },
    stuckText(row) {
      if (row.stuck_reason) return row.stuck_reason
      if (row.status === 'completed') return row.cost_status === 'settled' ? '成本已结算' : '成本待结算'
      if (['released', 'in_progress'].includes(row.status)) return '正常'
      return '—'
    },
    stuckClass(row) {
      if (row.stuck_reason) return row.status === 'paused' ? 'amb' : 'warn'
      return ['released', 'in_progress'].includes(row.status) ? 'ok' : 'sub'
    }
  }
}
</script>

<style scoped src="./mfg-v2.css"></style>
<style scoped>
.tiles { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.tile { background: #fff; border-radius: 12px; padding: 14px 16px; box-shadow: 0 1px 2px rgba(20, 33, 61, .06); border-top: 3px solid #c9d2e0; cursor: pointer; }
.tile b { font-size: 26px; display: block; margin: 2px 0; }
.tile span { color: #5b6780; font-size: 12px; }
.tile small { color: #8a95a8; font-size: 11px; }
.tabs { display: flex; gap: 4px; border-bottom: 1px solid #e3e8f0; margin-bottom: 12px; overflow-x: auto; }
.tabs span { padding: 8px 14px; color: #5b6780; border-bottom: 2px solid transparent; cursor: pointer; white-space: nowrap; }
.tabs span.on { color: #1f6feb; border-color: #1f6feb; font-weight: 600; }
.tabs em { font-style: normal; font-size: 11px; background: #eef1f5; border-radius: 8px; padding: 0 6px; margin-left: 4px; }
.flt { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; align-items: center; }
.flt .w { width: 280px; }
.flt .el-select { width: 140px; }
.flt .el-date-editor { width: 260px; }
.no { font-size: 11px; color: #8a95a8; }
.pn { font-weight: 700; font-size: 13px; margin-top: 2px; }
.steps { display: flex; gap: 3px; flex-wrap: wrap; }
.sp { display: flex; flex-direction: column; align-items: center; min-width: 46px; padding: 4px 4px; border-radius: 6px; background: #f3f5f9; color: #8a95a8; font-size: 11px; line-height: 1.4; }
.sp i { font-style: normal; font-size: 10px; }
.sp.d { background: #e6f7ef; color: #11804a; font-weight: 600; }
.sp.r { background: #fff4dc; color: #9a5b00; font-weight: 600; }
.wo-table ::v-deep th { color: #8a95a8; font-weight: 500; font-size: 12px; background: #fff; }
.wo-table ::v-deep td { font-size: 12px; cursor: pointer; }
.pg { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; color: #8a95a8; font-size: 12px; flex-wrap: wrap; gap: 8px; }
.mobile-only { display: none; }
.m-row { border: 1px solid #e3e8f0; border-radius: 10px; padding: 12px; margin-bottom: 10px; display: grid; gap: 6px; }
.m-head, .m-foot { display: flex; justify-content: space-between; gap: 8px; font-size: 12px; flex-wrap: wrap; }
.m-foot span:first-child { color: #5b6780; }
@media (max-width: 1100px) {
  .tiles { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 767px) {
  .tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .desktop-only { display: none; }
  .mobile-only { display: block; }
  .flt .w, .flt .el-date-editor, .flt .el-select { width: 100%; }
}
</style>
