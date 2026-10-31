<template>
  <section class="production-page perf-stats-page">
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-money" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">提成与工时统计</h1>
          </div>
          <div class="sub-row">
            按订单统计工序提成和工时；用了库存里备货做的架子、电箱等半成品（或备货整机），按库存批次找到当初的工单，用了多少取多少
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="reload">刷新</el-button>
      </div>
    </header>

    <el-tabs v-model="tab" class="perf-tabs">
      <el-tab-pane label="月度结算" name="month">
        <div class="toolbar">
          <el-date-picker v-model="month" type="month" value-format="yyyy-MM" size="small" :clearable="false" placeholder="选择月份" @change="loadMonth" />
          <el-button size="small" type="primary" icon="el-icon-search" :loading="monthLoading" @click="loadMonth">查询</el-button>
        </div>
        <template v-if="monthData">
          <div class="roll">
            <div class="roll-item"><div class="roll-label">上月结转（月初未结）</div><div class="roll-value">{{ hours(monthData.totals.opening) }}<small> 小时</small></div></div>
            <div class="roll-op">+</div>
            <div class="roll-item"><div class="roll-label">本月新做</div><div class="roll-value">{{ hours(monthData.totals.new) }}<small> 小时</small></div></div>
            <div class="roll-op">−</div>
            <div class="roll-item ok"><div class="roll-label">本月出库结算</div><div class="roll-value">{{ hours(monthData.totals.settled) }}<small> 小时</small></div></div>
            <div class="roll-op">=</div>
            <div class="roll-item warn-box"><div class="roll-label">结转下月（未出库）</div><div class="roll-value">{{ hours(monthData.totals.closing) }}<small> 小时</small></div></div>
            <div class="roll-item money-box"><div class="roll-label">本月结算提成</div><div class="roll-value">{{ money(monthData.totals.commission) }}</div></div>
          </div>
          <p class="rule">{{ monthData.rule }}</p>
          <div v-if="monthData.returns && monthData.returns.length" class="return-box">
            <div class="block-title">本月客户退货（只记录，不扣工时和提成；售后按责任手动处理）</div>
            <el-table :data="monthData.returns" size="mini" border>
              <el-table-column prop="date" label="到货日期" width="110" />
              <el-table-column prop="no" label="退货单" min-width="150" />
              <el-table-column prop="order_no" label="订单" min-width="140" />
              <el-table-column prop="item_name" label="商品" min-width="160" show-overflow-tooltip />
              <el-table-column prop="qty" label="数量" width="80" align="right" />
              <el-table-column label="发货时对应工单" min-width="160">
                <template slot-scope="{ row }">
                  <span v-for="w in row.work_orders" :key="w.work_order_id" class="emp">
                    <router-link :to="`/production/work-orders/${w.work_order_id}`" class="wo-link">{{ w.work_order_no }}</router-link>
                  </span>
                </template>
              </el-table-column>
              <el-table-column label="工时 / 提成影响" min-width="170">
                <template slot-scope="{ row }"><span class="muted">{{ row.adjustment_status }}</span></template>
              </el-table-column>
            </el-table>
          </div>
          <el-table v-loading="monthLoading" :data="monthData.employees" size="small" border row-key="employee_legacy_id" empty-text="这个月没有工时">
            <el-table-column type="expand">
              <template slot-scope="{ row }">
                <el-table :data="row.rows" size="mini" border class="inner">
                  <el-table-column label="工单（工时挂在它做的产品上）" min-width="210">
                    <template slot-scope="{ row: r }">
                      <router-link v-if="r.work_order_id" :to="`/production/work-orders/${r.work_order_id}`" class="wo-link">{{ r.work_order_no }}</router-link>
                      <span v-else>{{ r.work_order_no }}</span>
                      <el-tag size="mini" effect="plain" class="tag">{{ r.source_label }}</el-tag>
                      <div class="muted">{{ r.item_name }} · {{ r.operations.join('、') }}</div>
                    </template>
                  </el-table-column>
                  <el-table-column label="产品出库情况" min-width="220">
                    <template slot-scope="{ row: r }">
                      <el-tag size="mini" :type="statusType(r.status_label)" effect="plain">{{ r.status_label }}</el-tag>
                      <span class="qty-ok">已出库 {{ r.shipped_qty }}</span> / 产出 {{ r.output_qty }}
                      <div v-if="r.pending_qty > 0" class="qty-wait">
                        未出库 {{ r.pending_qty }}<template v-if="r.consumed_pending_qty">（已装进整机待出库 {{ r.consumed_pending_qty }}）</template><template v-if="r.in_stock_qty">（在库 {{ r.in_stock_qty }}）</template><template v-if="r.other_qty">（未完工/其他 {{ r.other_qty }}）</template>
                      </div>
                    </template>
                  </el-table-column>
                  <el-table-column label="本月出库去向" min-width="160">
                    <template slot-scope="{ row: r }">
                      <div v-for="(x, i) in r.shipped_in_month" :key="i" :class="{ warn: x.type === 'return' }">{{ x.date }} {{ x.type_label }} {{ x.sales_order_no }} × {{ x.qty }}</div>
                      <span v-if="!r.shipped_in_month.length" class="muted">本月没有出库</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="月初未结" width="90" align="right"><template slot-scope="{ row: r }">{{ hours(r.opening) }}</template></el-table-column>
                  <el-table-column label="本月新做" width="90" align="right"><template slot-scope="{ row: r }">{{ hours(r.new) }}</template></el-table-column>
                  <el-table-column label="本月结算" width="90" align="right"><template slot-scope="{ row: r }"><b :class="{ neg: r.settled < 0 }">{{ hours(r.settled) }}</b></template></el-table-column>
                  <el-table-column label="结转下月" width="90" align="right"><template slot-scope="{ row: r }"><span :class="{ warn: r.closing > 0 }">{{ hours(r.closing) }}</span></template></el-table-column>
                  <el-table-column label="提成" width="100" align="right"><template slot-scope="{ row: r }"><span :class="{ neg: Number(r.commission) < 0 }">{{ money(r.commission) }}</span></template></el-table-column>
                </el-table>
              </template>
            </el-table-column>
            <el-table-column prop="employee_name" label="人员" min-width="120" />
            <el-table-column label="上月结转（小时）" width="130" align="right"><template slot-scope="{ row }">{{ hours(row.opening) }}</template></el-table-column>
            <el-table-column label="本月新做" width="110" align="right"><template slot-scope="{ row }">{{ hours(row.new) }}</template></el-table-column>
            <el-table-column label="本月出库结算" width="120" align="right"><template slot-scope="{ row }"><b :class="{ neg: row.settled < 0 }">{{ hours(row.settled) }}</b></template></el-table-column>
            <el-table-column label="结转下月" width="110" align="right"><template slot-scope="{ row }"><span :class="{ warn: row.closing > 0 }">{{ hours(row.closing) }}</span></template></el-table-column>
            <el-table-column label="本月结算提成" width="130" align="right"><template slot-scope="{ row }"><span :class="{ neg: Number(row.commission) < 0 }">{{ money(row.commission) }}</span></template></el-table-column>
          </el-table>
        </template>
      </el-tab-pane>

      <el-tab-pane label="工时去向（按工单）" name="wos">
        <div class="toolbar">
          <el-input v-model="woKeyword" size="small" clearable placeholder="工单号 / 产品 / 订单号" style="width: 240px" @keyup.enter.native="searchWos" @clear="searchWos" />
          <el-checkbox v-model="onlyUnsettled" @change="searchWos">只看还有未结算的</el-checkbox>
          <el-button size="small" type="primary" icon="el-icon-search" @click="searchWos">查询</el-button>
          <span v-if="woSummary" class="muted">合计工时 {{ hours(woSummary.labor_minutes) }} 小时，已结算 {{ hours(woSummary.settled_minutes) }}，未结算 <span class="warn">{{ hours(woSummary.unsettled_minutes) }}</span></span>
        </div>
        <el-table v-loading="woLoading" :data="wos" size="small" border stripe>
          <el-table-column label="工单" min-width="170">
            <template slot-scope="{ row }">
              <router-link :to="`/production/work-orders/${row.work_order_id}`" class="wo-link">{{ row.work_order_no }}</router-link>
              <el-tag size="mini" effect="plain" class="tag">{{ row.source_label }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="item_name" label="产品" min-width="130" show-overflow-tooltip />
          <el-table-column label="订单" width="130"><template slot-scope="{ row }">{{ row.sales_order_no || '备货' }}</template></el-table-column>
          <el-table-column label="出库情况" width="110"><template slot-scope="{ row }"><el-tag size="mini" :type="statusType(row.status_label)" effect="plain">{{ row.status_label }}</el-tag></template></el-table-column>
          <el-table-column prop="output_qty" label="产出" width="70" align="right" />
          <el-table-column label="已出库" width="80" align="right"><template slot-scope="{ row }"><span class="qty-ok">{{ row.shipped_qty }}</span></template></el-table-column>
          <el-table-column label="装进整机待出库" width="120" align="right"><template slot-scope="{ row }">{{ row.consumed_pending_qty || '-' }}</template></el-table-column>
          <el-table-column label="在库" width="70" align="right"><template slot-scope="{ row }">{{ row.in_stock_qty || '-' }}</template></el-table-column>
          <el-table-column label="未完工/其他" width="100" align="right"><template slot-scope="{ row }">{{ row.other_qty || '-' }}</template></el-table-column>
          <el-table-column label="总工时（小时）" width="115" align="right"><template slot-scope="{ row }">{{ hours(row.labor_minutes) }}</template></el-table-column>
          <el-table-column label="已结算" width="90" align="right"><template slot-scope="{ row }">{{ hours(row.settled_minutes) }}</template></el-table-column>
          <el-table-column label="未结算" width="90" align="right"><template slot-scope="{ row }"><span :class="{ warn: row.unsettled_minutes > 0 }">{{ hours(row.unsettled_minutes) }}</span></template></el-table-column>
          <el-table-column label="" width="80">
            <template slot-scope="{ row }"><el-button type="text" size="mini" @click="showBatch(row.work_order_no)">去向</el-button></template>
          </el-table-column>
        </el-table>
        <el-pagination class="pager" layout="total, prev, pager, next" :total="woTotal" :page-size="20" :current-page.sync="woPage" @current-change="loadWos" />
      </el-tab-pane>

      <el-tab-pane label="订单统计" name="orders">
        <div class="toolbar">
          <el-input v-model="keyword" size="small" clearable placeholder="订单号 / 客户" style="width: 240px" @keyup.enter.native="search" @clear="search" />
          <el-button size="small" type="primary" icon="el-icon-search" @click="search">查询</el-button>
        </div>
        <el-table v-loading="loading" :data="orders" size="small" border stripe highlight-current-row @row-click="openOrder">
          <el-table-column prop="sales_order_no" label="订单号" min-width="150" />
          <el-table-column prop="customer_name" label="客户" min-width="140" show-overflow-tooltip />
          <el-table-column label="状态" width="170">
            <template slot-scope="{ row }">
              <el-tag size="mini" :type="row.status === 'settleable' ? 'success' : 'warning'" effect="plain">{{ row.status_label }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="商品金额" width="120" align="right">
            <template slot-scope="{ row }">{{ money(row.goods_amount) }}</template>
          </el-table-column>
          <el-table-column label="提成合计" width="120" align="right">
            <template slot-scope="{ row }">{{ money(row.performance_amount) }}</template>
          </el-table-column>
          <el-table-column label="工时（小时）" width="110" align="right">
            <template slot-scope="{ row }">{{ hours(row.labor_minutes) }}</template>
          </el-table-column>
          <el-table-column prop="employee_count" label="人数" width="70" align="right" />
          <el-table-column label="待处理" width="80" align="right">
            <template slot-scope="{ row }"><span :class="{ warn: row.issue_count }">{{ row.issue_count || '-' }}</span></template>
          </el-table-column>
        </el-table>
        <el-pagination class="pager" layout="total, prev, pager, next" :total="total" :page-size="15" :current-page.sync="page" @current-change="loadOrders" />
      </el-tab-pane>

      <el-tab-pane label="备货批次去向" name="batch">
        <div class="toolbar">
          <el-input v-model="batchNo" size="small" clearable placeholder="输入工单号（= 库存批次号）" style="width: 260px" @keyup.enter.native="loadBatch" />
          <el-button size="small" type="primary" icon="el-icon-search" :loading="batchLoading" @click="loadBatch">查询</el-button>
        </div>
        <template v-if="batch">
          <div class="batch-summary">
            <span><b>{{ batch.work_order.work_order_no }}</b> {{ batch.work_order.source_label }} · {{ batch.work_order.item_name }}</span>
            <el-tag size="mini" :type="statusType(batch.work_order.status_label)" effect="plain">{{ batch.work_order.status_label }}</el-tag>
            <span>产出 {{ batch.work_order.output_qty }} {{ batch.work_order.unit_name }}，<span class="qty-ok">已出库 {{ batch.work_order.shipped_qty }}</span>，<span class="qty-wait">未出库 {{ batch.work_order.pending_qty }}</span></span>
            <span>总工时 {{ hours(batch.work_order.labor_minutes) }} 小时<template v-if="batch.work_order.labor_minutes_per_unit !== null">（每件 {{ hours(batch.work_order.labor_minutes_per_unit) }}）</template></span>
            <span>已结算 {{ hours(batch.work_order.settled_minutes) }}，未结算 <span class="warn">{{ hours(batch.work_order.unsettled_minutes) }}</span></span>
          </div>
          <el-table :data="batch.usage" size="small" border empty-text="这批还没有被领用或发货">
            <el-table-column prop="type_label" label="去向" min-width="140" />
            <el-table-column label="工单 / 发货单" min-width="150">
              <template slot-scope="{ row }">
                <router-link v-if="row.target_work_order_id" :to="`/production/work-orders/${row.target_work_order_id}`" class="wo-link">{{ row.target }}</router-link>
                <span v-else>{{ row.target }}</span>
              </template>
            </el-table-column>
            <el-table-column label="订单" min-width="130">
              <template slot-scope="{ row }">{{ row.sales_order_no || (row.type === 'consumed' ? '备货（未关联订单）' : '-') }}</template>
            </el-table-column>
            <el-table-column prop="qty" label="数量" width="80" align="right" />
            <el-table-column label="出库情况" min-width="170">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="row.shipped ? 'success' : (row.shipped_qty > 0 ? 'warning' : 'info')" effect="plain">{{ row.status_label }}</el-tag>
                <span v-if="row.date" class="muted"> {{ row.date }}</span>
                <span v-if="row.type === 'consumed' && row.shipped_qty > 0 && row.pending_qty > 0" class="muted"> 已出 {{ row.shipped_qty }}，待出 {{ row.pending_qty }}</span>
              </template>
            </el-table-column>
            <el-table-column label="对应工时（小时）" width="140" align="right">
              <template slot-scope="{ row }">{{ hours(row.labor_minutes) }}</template>
            </el-table-column>
          </el-table>
        </template>
      </el-tab-pane>
    </el-tabs>

    <el-drawer :visible.sync="drawer" size="78%" :title="detail ? `订单 ${detail.order.sales_order_no} 提成与工时` : '订单提成与工时'" append-to-body>
      <div v-loading="detailLoading" class="drawer-body">
        <template v-if="detail">
          <div class="detail-head">
            <el-tag :type="detail.status === 'settleable' ? 'success' : 'warning'" effect="plain">{{ detail.status_label }}</el-tag>
            <span>商品金额 <b>{{ money(detail.totals.goods_amount) }}</b></span>
            <span>提成合计 <b>{{ money(detail.totals.performance_amount) }}</b></span>
            <span>工时合计 <b>{{ hours(detail.totals.labor_minutes) }}</b> 小时</span>
            <span v-if="detail.order.exchange_rate">汇率 {{ detail.order.exchange_rate }}</span>
          </div>
          <p class="rule">{{ detail.rule }}</p>
          <el-alert v-for="(text, i) in detail.issues" :key="'i' + i" :title="text" type="warning" :closable="false" show-icon class="issue" />

          <div class="block-title">按人汇总</div>
          <el-table :data="detail.employees" size="small" border empty-text="还没有报工记录">
            <el-table-column prop="employee_name" label="人员" min-width="120" />
            <el-table-column prop="operations" label="参与工序数" width="100" align="right" />
            <el-table-column label="本单工时（小时）" width="140" align="right">
              <template slot-scope="{ row }">{{ hours(row.labor_minutes) }}</template>
            </el-table-column>
            <el-table-column label="提成" width="130" align="right">
              <template slot-scope="{ row }">{{ money(row.performance_amount) }}</template>
            </el-table-column>
          </el-table>

          <div v-for="line in detail.lines" :key="line.sales_order_line_id" class="line-card">
            <div class="block-title">
              {{ line.product_name || line.item_name }}<span v-if="line.sku_name" class="muted"> · {{ line.sku_name }}</span>
              <span class="muted">　数量 {{ line.qty }}　金额 {{ money(line.amount) }}</span>
              <span class="qty-ok">　已出库 {{ line.shipped_qty }}</span><span v-if="line.pending_qty > 0" class="qty-wait">　未出库 {{ line.pending_qty }}（预估）</span><span v-if="line.returned_qty > 0" class="warn">　退货 {{ line.returned_qty }}（仅记录）</span>
            </div>
            <el-table :data="flatten(line)" size="mini" border :row-class-name="rowClass">
              <el-table-column label="来源工单" min-width="230">
                <template slot-scope="{ row }">
                  <div v-if="row.kind === 'wo'" :style="{ paddingLeft: row.level * 18 + 'px' }">
                    <i :class="row.level ? 'el-icon-share' : 'el-icon-s-order'" />
                    <router-link v-if="row.work_order_id" :to="`/production/work-orders/${row.work_order_id}`" class="wo-link">{{ row.work_order_no }}</router-link>
                    <span v-else>{{ row.batch_no || '无批次' }}</span>
                    <el-tag size="mini" effect="plain" class="tag">{{ row.source_label }}</el-tag>
                    <span class="muted">{{ row.item_name }}</span>
                  </div>
                  <div v-else-if="row.kind === 'note'" :style="{ paddingLeft: row.level * 18 + 18 + 'px' }" class="note">{{ row.text }}</div>
                  <div v-else :style="{ paddingLeft: row.level * 18 + 18 + 'px' }">{{ row.operation_name }}<span v-if="row.shipment_no" class="muted">（{{ row.shipment_no }}）</span></div>
                </template>
              </el-table-column>
              <el-table-column label="用量 / 产出" width="120" align="right">
                <template slot-scope="{ row }"><span v-if="row.kind === 'wo' && row.output_qty !== undefined">{{ row.used_qty }} / {{ row.output_qty }}</span></template>
              </el-table-column>
              <el-table-column label="提成比例" width="90" align="right">
                <template slot-scope="{ row }"><span v-if="row.kind === 'op'">{{ row.performance_rate === null ? '不计' : pct(row.performance_rate, 2) }}</span></template>
              </el-table-column>
              <el-table-column label="提成" width="110" align="right">
                <template slot-scope="{ row }"><span v-if="row.kind === 'op'">{{ money(row.performance_pool_amount) }}</span></template>
              </el-table-column>
              <el-table-column label="本单工时 / 这批总工时（小时）" width="200" align="right">
                <template slot-scope="{ row }"><span v-if="row.kind === 'op'">{{ hours(row.labor_minutes) }} / {{ hours(row.labor_minutes_total) }}</span></template>
              </el-table-column>
              <el-table-column label="分到人" min-width="260">
                <template slot-scope="{ row }">
                  <span v-if="row.kind === 'op'">
                    <span v-for="e in row.employees" :key="e.employee_legacy_id" class="emp">
                      {{ e.employee_name }} {{ pct(e.share_ratio) }}<template v-if="e.performance_amount !== null"> ¥{{ money(e.performance_amount) }}</template> · {{ hours(e.labor_minutes) }}h
                    </span>
                    <span v-if="!row.employees.length" class="muted">还没有报工</span>
                    <el-tag v-if="row.employees.length" size="mini" effect="plain" class="tag">{{ row.share_mode === 'fixed' ? '固定份额' : '按工时分' }}</el-tag>
                  </span>
                </template>
              </el-table-column>
            </el-table>
          </div>
        </template>
      </div>
    </el-drawer>
  </section>
</template>

<script>
import { listPerformanceOrders, getPerformanceOrder, getPerformanceBatch, getPerformanceMonth, listPerformanceWorkOrders } from '../../../api/erp/production'

export default {
  name: 'ProductionPerformanceStats',
  data() {
    const d = new Date()
    const month = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
    return { tab: 'month', month, monthData: null, monthLoading: false, wos: [], woTotal: 0, woPage: 1, woKeyword: '', onlyUnsettled: true, woLoading: false, woSummary: null, woLoaded: false,
       keyword: '', orders: [], total: 0, page: 1, loading: false,
      drawer: false, detail: null, detailLoading: false, batchNo: '', batch: null, batchLoading: false }
  },
  created() {
    const q = this.$route.query || {}
    if (q.wo) { this.tab = 'batch'; this.batchNo = String(q.wo); this.loadBatch() }
    this.loadMonth()
    this.loadOrders()
  },
  watch: {
    tab(v) { if (v === 'wos' && !this.woLoaded) this.loadWos() }
  },
  methods: {
    reload() {
      if (this.tab === 'month') return this.loadMonth()
      if (this.tab === 'wos') return this.loadWos()
      if (this.tab === 'batch' && this.batchNo) return this.loadBatch()
      this.loadOrders()
    },
    async loadMonth() {
      if (!this.month) return
      this.monthLoading = true
      try { const r = await getPerformanceMonth({ month: this.month }); this.monthData = r.data.data } catch (e) { this.$message.error(e.userMessage || '月度结算加载失败') } finally { this.monthLoading = false }
    },
    searchWos() { this.woPage = 1; this.loadWos() },
    async loadWos() {
      this.woLoading = true; this.woLoaded = true
      try {
        const r = await listPerformanceWorkOrders({ keyword: this.woKeyword, only_unsettled: this.onlyUnsettled ? 1 : 0, page: this.woPage, per_page: 20 })
        this.wos = r.data.data || []; this.woTotal = r.data.total || 0; this.woSummary = r.data.summary || null
      } catch (e) { this.$message.error(e.userMessage || '加载失败') } finally { this.woLoading = false }
    },
    showBatch(no) { this.batchNo = no; this.tab = 'batch'; this.loadBatch() },
    statusType(label) { return label === '已全部出库' || label === '已出库' || label === '已发运' ? 'success' : (label === '部分出库' ? 'warning' : 'info') },
    search() { this.page = 1; this.loadOrders() },
    async loadOrders() {
      this.loading = true
      try { const r = await listPerformanceOrders({ keyword: this.keyword, page: this.page, per_page: 15 }); this.orders = r.data.data || []; this.total = r.data.total || 0 } catch (e) { this.$message.error(e.userMessage || '统计加载失败') } finally { this.loading = false }
    },
    async openOrder(row) {
      this.drawer = true; this.detail = null; this.detailLoading = true
      try { const r = await getPerformanceOrder(row.id); this.detail = r.data.data } catch (e) { this.$message.error(e.userMessage || '明细加载失败') } finally { this.detailLoading = false }
    },
    async loadBatch() {
      if (!this.batchNo) return
      this.batchLoading = true
      try { const r = await getPerformanceBatch({ work_order_no: this.batchNo.trim() }); this.batch = r.data.data } catch (e) { this.batch = null; this.$message.error(e.userMessage || '没有找到这张工单') } finally { this.batchLoading = false }
    },
    flatten(line) {
      const rows = []
      const walk = (node, level) => {
        const label = node.kind_label && !level ? node.kind_label + ' · ' + (node.source_label || '库存') : (node.source_label || '库存')
        rows.push(Object.assign({}, node, { kind: 'wo', level, source_label: label }))
        if (node.note) rows.push({ kind: 'note', level, text: node.note })
        ;(node.operations || []).forEach(op => rows.push(Object.assign({}, op, { kind: 'op', level })))
        ;(node.notes || []).forEach(text => rows.push({ kind: 'note', level, text }))
        ;(node.children || []).forEach(c => walk(c, level + 1))
      }
      ;(line.sources || []).forEach(s => walk(s, 0))
      if ((line.packing || []).length) {
        rows.push({ kind: 'wo', level: 0, source_label: '发货包装', work_order_no: '', batch_no: '包装作业', item_name: '' })
        line.packing.forEach(op => rows.push(Object.assign({}, op, { kind: 'op', level: 0 })))
      }
      return rows
    },
    rowClass({ row }) { return row.kind === 'wo' ? 'wo-row' : (row.kind === 'note' ? 'note-row' : '') },
    money(v) { return v === null || v === undefined ? '—' : Number(v).toFixed(2) },
    hours(m) { return m === null || m === undefined ? '—' : (Number(m) / 60).toFixed(2) },
    pct(v, d = 1) { return v === null || v === undefined ? '—' : (Number(v) * 100).toFixed(d).replace(/\.0+$/, '') + '%' }
  }
}
</script>

<style scoped src="./production-master.css"></style>
<style scoped>
.perf-tabs { background: #fff; border-radius: 10px; padding: 8px 16px 16px; }
.toolbar { display: flex; gap: 8px; margin-bottom: 12px; }
.pager { margin-top: 12px; text-align: right; }
.warn { color: #e6a23c; font-weight: 600; }
.batch-summary { display: flex; flex-wrap: wrap; gap: 18px; padding: 10px 12px; margin-bottom: 10px; background: #f5f8fb; border-radius: 6px; font-size: 13px; }
.drawer-body { padding: 0 20px 24px; }
.detail-head { display: flex; flex-wrap: wrap; align-items: center; gap: 18px; font-size: 14px; }
.rule { color: #77869a; font-size: 12px; margin: 8px 0 10px; }
.issue { margin-bottom: 6px; }
.block-title { font-weight: 600; color: #152941; margin: 16px 0 8px; }
.muted { color: #909399; font-weight: normal; }
.tag { margin-left: 6px; }
.wo-link { margin-left: 4px; font-weight: 600; }
.emp { display: inline-block; margin-right: 12px; }
.note { color: #e6a23c; }
.neg { color: #f56c6c; }
.return-box { margin: 4px 0 14px; }
.qty-ok { color: #2e9d5b; margin-left: 6px; }
.qty-wait { color: #e6a23c; font-size: 12px; }
.inner { margin: 4px 0 4px 24px; width: calc(100% - 24px); }
.roll { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 6px; }
.roll-item { min-width: 150px; padding: 10px 14px; border-radius: 8px; background: #f5f8fb; }
.roll-item.ok { background: #eef8f1; }
.roll-item.warn-box { background: #fdf6ec; }
.roll-item.money-box { background: #eef4fd; margin-left: 16px; }
.roll-label { font-size: 12px; color: #77869a; }
.roll-value { font-size: 20px; font-weight: 600; color: #152941; }
.roll-value small { font-size: 12px; font-weight: normal; color: #909399; }
.roll-op { font-size: 20px; color: #909399; }
.perf-stats-page >>> .wo-row td { background: #f5f8fb; }
</style>
