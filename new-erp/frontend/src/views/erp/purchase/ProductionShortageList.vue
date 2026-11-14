<template>
  <section class="purchase-shortage-page" v-loading="loading">
    <div class="shortage-main">
      <!-- 顶部页头卡片 -->
      <div class="page-head">
        <div class="head-left">
          <div class="title-row">
            <span class="title-icon"><i class="el-icon-warning-outline" /></span>
            <h1>生产缺料</h1>
            <el-tag size="mini" type="success">缺料看板</el-tag>
          </div>
          <p class="subtitle">未结束工单（含计划）待领外购件按优先级与时间顺序扣减可用库存及采购在途，实时核算净缺口并支持合并转采购需求</p>
        </div>
        <div class="head-actions">
          <el-button size="small" plain icon="el-icon-refresh" @click="load">刷新数据</el-button>
          <el-button
            v-if="canConvert"
            size="small"
            type="success"
            icon="el-icon-shopping-cart-2"
            :disabled="!picked.length"
            @click="openConvert"
          >
            生成采购需求（{{ picked.length }}）
          </el-button>
        </div>
      </div>

      <!-- 统一业务说明条 -->
      <div class="erp-page-tip">
        <i class="el-icon-info" />
        <span>
          <strong>分配规则：</strong>按工单优先级（<strong>特急 → 加急 → 普通 → 可延后</strong>）及同级创建时间先后分配可用库存与采购在途；勾选存在净缺口的物料后，可一次性合并生成一张采购需求草稿。
        </span>
      </div>

      <!-- KPI 统计概览卡 -->
      <div class="stat-row">
        <div class="stat-card">
          <div class="stat-icon-wrapper is-danger">
            <i class="el-icon-box" />
          </div>
          <div class="stat-info">
            <span class="stat-label">缺料物料种类</span>
            <strong class="stat-val">{{ summary.short_item_count || 0 }} <small>种</small></strong>
            <small class="stat-sub">存在净缺口待采购补齐</small>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon-wrapper is-warning">
            <i class="el-icon-tickets" />
          </div>
          <div class="stat-info">
            <span class="stat-label">受影响工单数</span>
            <strong class="stat-val">{{ summary.work_order_count || 0 }} <small>张</small></strong>
            <small class="stat-sub">涉及缺料物料的未结工单</small>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon-wrapper is-green">
            <i class="el-icon-s-data" />
          </div>
          <div class="stat-info">
            <span class="stat-label">本页缺料 / 待领合计</span>
            <strong class="stat-val">{{ pageStats.shortCount }} <small>项缺口</small></strong>
            <small class="stat-sub">本页待领量合计 {{ qty(pageStats.needSum) }} · 缺口 {{ qty(pageStats.shortSum) }}</small>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon-wrapper is-brand">
            <i class="el-icon-document-checked" />
          </div>
          <div class="stat-info">
            <span class="stat-label">已勾选待转需求</span>
            <strong class="stat-val">{{ picked.length }} <small>种</small></strong>
            <small class="stat-sub">{{ picked.length ? '可点击右上角合并生成采购需求' : '勾选缺料行即可批量转单' }}</small>
          </div>
        </div>
      </div>

      <!-- 筛选与视图切换卡片 -->
      <div class="filter-card">
        <div class="filter-inputs">
          <el-input
            v-model="keyword"
            size="small"
            clearable
            placeholder="搜物料编码 / 名称 / 规格 / 工单号"
            prefix-icon="el-icon-search"
            @keyup.enter.native="search"
            @clear="search"
          />
          <div class="scope-switch" role="group" aria-label="物料范围筛选">
            <button
              type="button"
              :class="['scope-btn', { 'is-active': !showAll }]"
              @click="setShowAll(false)"
            >
              仅看缺料物料
            </button>
            <button
              type="button"
              :class="['scope-btn', { 'is-active': showAll }]"
              @click="setShowAll(true)"
            >
              全部物料（含齐套）
            </button>
          </div>
        </div>
        <div class="filter-actions">
          <el-button
            size="small"
            plain
            :icon="allExpanded ? 'el-icon-arrow-up' : 'el-icon-arrow-down'"
            :disabled="!rows.length"
            @click="toggleExpandAll"
          >
            {{ allExpanded ? '收起本页拆解' : '展开本页拆解' }}
          </el-button>
          <el-button size="small" icon="el-icon-refresh-left" @click="resetSearch">重置</el-button>
          <el-button size="small" type="success" icon="el-icon-search" @click="search">查询</el-button>
        </div>
      </div>

      <!-- 列表主体面板 -->
      <div class="table-panel">
        <!-- 批量选择操作条 -->
        <div v-if="canConvert" :class="['batch-bar', { 'has-picked': picked.length > 0 }]">
          <div class="batch-left">
            <i :class="picked.length ? 'el-icon-success batch-icon active' : 'el-icon-info batch-icon'" />
            <span v-if="picked.length">
              已选择 <strong>{{ picked.length }}</strong> 种缺料物料，跨页翻看将保留已勾选项
            </span>
            <span v-else>
              勾选表格中存在净缺口的物料行可批量合并生成采购需求，或直接点击行内「转需求」快捷转单
            </span>
          </div>
          <div class="batch-right">
            <el-button
              v-if="shortRowsInPage.length"
              size="mini"
              plain
              @click="selectPageShortages"
            >
              全选本页缺料 ({{ shortRowsInPage.length }})
            </el-button>
            <el-button
              v-if="picked.length"
              size="mini"
              plain
              @click="clearSelection"
            >
              清空勾选
            </el-button>
            <el-button
              v-if="picked.length"
              size="mini"
              type="success"
              icon="el-icon-plus"
              @click="openConvert"
            >
              合并生成采购需求 ({{ picked.length }})
            </el-button>
          </div>
        </div>

        <!-- 桌面端表格 -->
        <div class="desktop-only">
          <el-table
            ref="table"
            :data="rows"
            row-key="item_id"
            border
            stripe
            size="mini"
            class="shortage-table"
            empty-text="当前筛选条件下暂无生产缺料记录"
            @selection-change="onSelectionChange"
            @expand-change="onExpandChange"
          >
            <el-table-column
              v-if="canConvert"
              type="selection"
              width="44"
              align="center"
              :selectable="r => r.shortage_qty > 0"
              reserve-selection
            />
            <el-table-column type="expand" width="38">
              <template slot-scope="s">
                <div class="expand-panel">
                  <!-- 工单需求与分配拆解 -->
                  <div class="expand-section">
                    <div class="expand-section-head">
                      <span class="expand-title">
                        <i class="el-icon-tickets" />
                        关联工单拆解（共 {{ s.row.work_orders.length }} 张）
                      </span>
                      <span class="expand-legend">公式：还要领 = 分得库存 + 分得在途 + 净缺口</span>
                    </div>
                    <div class="wo-cards">
                      <div v-for="w in s.row.work_orders" :key="w.id" class="wo-card">
                        <div class="wo-card-main">
                          <div class="wo-card-left">
                            <span
                              :class="['wo-link mono', { 'is-clickable': $can('production.work_order.view') }]"
                              @click="openWorkOrder(w)"
                            >
                              {{ w.work_order_no }}
                              <i v-if="$can('production.work_order.view')" class="el-icon-top-right" />
                            </span>
                            <span :class="['status-pill', workOrderStatusClass(w.status)]">{{ w.status_label }}</span>
                            <span v-if="w.priority === 1" class="priority-pill is-super-urgent">特急</span>
                            <span v-else-if="w.is_urgent" class="priority-pill is-urgent">加急</span>
                            <span v-else-if="w.priority === 4" class="priority-pill is-defer">可延后</span>
                            <span class="wo-product-name">{{ w.item_name }}</span>
                            <span class="wo-start-date">开工 {{ w.planned_start_date || '未排期' }}</span>
                          </div>
                          <div class="wo-card-formula">
                            <span>还要 <b>{{ qty(w.need_qty) }}</b></span>
                            <span class="op">=</span>
                            <span>库存 <b>{{ qty(w.stock_qty) }}</b></span>
                            <span class="op">+</span>
                            <span>在途 <b>{{ qty(w.transit_qty) }}</b></span>
                            <span class="op">+</span>
                            <span v-if="w.shortage_qty > 0" class="formula-short">缺 {{ qty(w.shortage_qty) }}</span>
                            <span v-else class="formula-ok">齐套</span>
                          </div>
                        </div>
                        <div v-if="w.shortage_qty > 0 && w.ahead && w.ahead.length" class="ahead-tip">
                          <i class="el-icon-sort" />
                          <span>可用库存优先分配给：{{ w.ahead.map(a => `${a.work_order_no}${a.stock_qty > 0 ? `(${qty(a.stock_qty)})` : ''}`).join('、') }}</span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- 在途明细与已转采购需求 -->
                  <div v-if="s.row.in_transit_detail || (s.row.requests && s.row.requests.length)" class="expand-extras">
                    <div v-if="s.row.in_transit_detail" class="extra-box">
                      <div class="extra-title">
                        <i class="el-icon-truck" />
                        采购在途明细：{{ transitText(s.row) }}
                      </div>
                      <div v-if="s.row.in_transit_detail.documents && s.row.in_transit_detail.documents.length" class="doc-chip-list">
                        <span
                          v-for="d in s.row.in_transit_detail.documents"
                          :key="`${d.stage}-${d.document_no}-${d.line_id}`"
                          class="doc-chip"
                        >
                          <span class="doc-stage">{{ d.stage_label }}</span>
                          <span class="mono">{{ d.document_no }}</span>
                          <b>{{ qty(d.qty) }}</b>
                        </span>
                      </div>
                    </div>

                    <div v-if="s.row.requests && s.row.requests.length" class="extra-box is-request-box">
                      <div class="extra-title">
                        <i class="el-icon-document-copy" />
                        已转采购需求单（{{ s.row.requests.length }} 笔，供防重参考）
                      </div>
                      <div class="doc-chip-list">
                        <span
                          v-for="r in s.row.requests"
                          :key="r.purchase_request_id || r.request_no"
                          :class="['doc-chip is-req', { 'is-clickable': r.purchase_request_id && $can('purchase.request') }]"
                          @click="openRequest(r)"
                        >
                          <span class="mono">{{ r.request_no }}</span>
                          <span class="doc-stage">{{ r.request_status_label }}</span>
                          <b>{{ qty(r.qty) }}</b>
                          <i v-if="r.purchase_request_id && $can('purchase.request')" class="el-icon-top-right" />
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
              </template>
            </el-table-column>

            <el-table-column label="物料信息" min-width="250">
              <template slot-scope="s">
                <div class="item-cell">
                  <div class="item-top">
                    <span class="code-badge mono">{{ s.row.item_code }}</span>
                    <span class="item-name">{{ s.row.item_name }}</span>
                    <span v-if="s.row.priority === 1" class="priority-pill is-super-urgent">特急</span>
                    <span v-else-if="s.row.is_urgent" class="priority-pill is-urgent">加急</span>
                    <span v-else-if="s.row.priority === 4" class="priority-pill is-defer">可延后</span>
                  </div>
                  <div class="item-spec">{{ s.row.spec || '无规格型号说明' }}</div>
                </div>
              </template>
            </el-table-column>

            <el-table-column label="还要领" width="115" align="right">
              <template slot-scope="s">
                <span class="num-main">{{ qty(s.row.need_qty) }}</span>
                <small class="unit-txt">{{ s.row.unit_name || '' }}</small>
              </template>
            </el-table-column>

            <el-table-column label="库存可用" width="115" align="right">
              <template slot-scope="s">
                <span :class="['num-main', s.row.available_qty > 0 ? 'qty-ok' : 'qty-zero']">{{ qty(s.row.available_qty) }}</span>
                <small class="unit-txt">{{ s.row.unit_name || '' }}</small>
              </template>
            </el-table-column>

            <el-table-column label="采购在途" width="135" align="right">
              <template slot-scope="s">
                <el-tooltip v-if="s.row.in_transit_detail" placement="top">
                  <div slot="content" class="transit-tooltip">
                    <div class="tt-head">{{ transitText(s.row) }}</div>
                    <div
                      v-for="d in s.row.in_transit_detail.documents"
                      :key="`${d.stage}-${d.document_no}-${d.line_id}`"
                    >
                      {{ d.stage_label }} {{ d.document_no }}：{{ qty(d.qty) }}
                    </div>
                  </div>
                  <div class="transit-trigger">
                    <span class="num-main qty-brand">{{ qty(s.row.in_transit_qty) }}</span>
                    <small class="unit-txt">{{ s.row.unit_name || '' }}</small>
                    <div class="transit-sub">{{ transitText(s.row) }}</div>
                  </div>
                </el-tooltip>
                <span v-else class="qty-zero">{{ qty(s.row.in_transit_qty) }} <small class="unit-txt">{{ s.row.unit_name || '' }}</small></span>
              </template>
            </el-table-column>

            <el-table-column label="净缺口" width="140" align="right">
              <template slot-scope="s">
                <span v-if="s.row.shortage_qty > 0" class="shortage-pill">
                  缺 {{ qty(s.row.shortage_qty) }} {{ s.row.unit_name || '' }}
                </span>
                <span v-else class="kitted-pill">
                  <i class="el-icon-circle-check" /> 已齐套
                </span>
              </template>
            </el-table-column>

            <el-table-column label="最早开工" width="116" align="center">
              <template slot-scope="s">
                <span :class="['date-pill', { 'is-muted': !s.row.earliest_date }]">
                  <i v-if="s.row.earliest_date" class="el-icon-date" />
                  {{ s.row.earliest_date || '未排期' }}
                </span>
              </template>
            </el-table-column>

            <el-table-column label="工单 / 已转需求" width="135" align="center">
              <template slot-scope="s">
                <div class="rel-badges">
                  <span class="rel-wo-tag">{{ s.row.work_orders.length }} 张工单</span>
                  <span v-if="s.row.requests && s.row.requests.length" class="rel-req-tag">
                    已转 {{ s.row.requests.length }} 单
                  </span>
                </div>
              </template>
            </el-table-column>

            <el-table-column label="操作" width="165" fixed="right" align="left">
              <template slot-scope="s">
                <div class="row-op-group">
                  <el-button
                    type="text"
                    size="mini"
                    class="action-link-theme"
                    :icon="isExpanded(s.row) ? 'el-icon-arrow-up' : 'el-icon-tickets'"
                    @click="toggleRowExpand(s.row)"
                  >
                    {{ isExpanded(s.row) ? '收起' : '拆解' }}
                  </el-button>
                  <el-button
                    v-if="canConvert && s.row.shortage_qty > 0"
                    type="text"
                    size="mini"
                    class="action-link-theme is-strong"
                    icon="el-icon-shopping-cart-2"
                    @click="openSingleConvert(s.row)"
                  >
                    转采购需求
                  </el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>
        </div>

        <!-- 移动端卡片视图 -->
        <div class="mobile-only">
          <div v-if="!rows.length" class="empty-mobile">当前筛选条件下暂无生产缺料记录</div>
          <div v-for="row in rows" :key="row.item_id" class="m-card">
            <div class="m-head">
              <div class="m-title-wrap">
                <el-checkbox
                  v-if="canConvert"
                  :value="isPicked(row)"
                  :disabled="!(row.shortage_qty > 0)"
                  @change="v => togglePick(row, v)"
                >
                  <span class="m-item-name">{{ row.item_name }}</span>
                </el-checkbox>
                <span v-else class="m-item-name">{{ row.item_name }}</span>
                <span v-if="row.priority === 1" class="priority-pill is-super-urgent">特急</span>
                <span v-else-if="row.is_urgent" class="priority-pill is-urgent">加急</span>
                <span v-else-if="row.priority === 4" class="priority-pill is-defer">可延后</span>
              </div>
              <span v-if="row.shortage_qty > 0" class="shortage-pill">
                缺 {{ qty(row.shortage_qty) }} {{ row.unit_name || '' }}
              </span>
              <span v-else class="kitted-pill">
                <i class="el-icon-circle-check" /> 已齐套
              </span>
            </div>

            <div class="m-meta">
              <span class="code-badge mono">{{ row.item_code }}</span>
              <span v-if="row.spec" class="item-spec">{{ row.spec }}</span>
            </div>

            <div class="m-metrics">
              <div><small>还要领</small><b>{{ qty(row.need_qty) }}</b></div>
              <div><small>库存可用</small><b>{{ qty(row.available_qty) }}</b></div>
              <div><small>采购在途</small><b class="qty-brand">{{ qty(row.in_transit_qty) }}</b></div>
              <div><small>最早开工</small><b>{{ row.earliest_date || '未排期' }}</b></div>
            </div>

            <div v-if="row.in_transit_detail" class="m-transit">
              在途拆解：{{ transitText(row) }}
            </div>

            <div class="m-wo-list">
              <span v-for="w in row.work_orders" :key="w.id" class="m-wo-chip">
                <span class="wo-link mono" @click="openWorkOrder(w)">{{ w.work_order_no }}</span>
                <span>{{ w.shortage_qty > 0 ? `缺 ${qty(w.shortage_qty)}` : '齐套' }}</span>
              </span>
            </div>

            <div v-if="row.requests && row.requests.length" class="m-req-list">
              <span>已转采购：</span>
              <span
                v-for="r in row.requests"
                :key="r.purchase_request_id || r.request_no"
                class="m-req-chip"
                @click="openRequest(r)"
              >
                {{ r.request_no }}（{{ r.request_status_label }}）
              </span>
            </div>

            <div v-if="canConvert && row.shortage_qty > 0" class="m-actions">
              <el-button size="mini" type="success" plain icon="el-icon-shopping-cart-2" @click="openSingleConvert(row)">
                直接转采购需求
              </el-button>
            </div>
          </div>
        </div>

        <!-- 分页栏 -->
        <div class="pager-bar">
          <span class="pager-info">共 {{ total }} 条物料记录 · 第 {{ page }} 页 · 每页 10 条</span>
          <el-pagination
            background
            small
            layout="prev, pager, next"
            :current-page="page"
            :page-size="10"
            :total="total"
            @current-change="onPageChange"
          />
        </div>
      </div>
    </div>

    <shortage-purchase-dialog
      :visible="dialog"
      :rows="dialogRows"
      @update:visible="onDialogVisibleChange"
      @done="done"
    />
  </section>
</template>

<script>
import { listProductionShortages } from '../../../api/erp/purchase'
import ShortagePurchaseDialog from './ShortagePurchaseDialog.vue'
import { qty, errorText } from '../production/mfg-helpers'

export default {
  name: 'ProductionShortageList',
  components: { ShortagePurchaseDialog },
  data: () => ({
    loading: false,
    rows: [],
    total: 0,
    page: 1,
    summary: {},
    canConvert: false,
    keyword: '',
    showAll: false,
    picked: [],
    singleRow: null,
    expandedIds: [],
    dialog: false
  }),
  computed: {
    dialogRows() {
      return this.singleRow ? [this.singleRow] : this.picked
    },
    shortRowsInPage() {
      return this.rows.filter(r => Number(r.shortage_qty) > 0)
    },
    allExpanded() {
      return this.rows.length > 0 && this.rows.every(r => this.expandedIds.includes(r.item_id))
    },
    pageStats() {
      return this.rows.reduce((acc, r) => {
        const shortQty = Number(r.shortage_qty || 0)
        acc.needSum += Number(r.need_qty || 0)
        if (shortQty > 0) {
          acc.shortCount += 1
          acc.shortSum += shortQty
        }
        return acc
      }, { shortCount: 0, shortSum: 0, needSum: 0 })
    }
  },
  created() {
    this.load()
  },
  methods: {
    qty,
    workOrderStatusClass(status) {
      if (status === 'planned') return 'is-planned'
      if (status === 'in_progress' || status === 'released') return 'is-active'
      return 'is-neutral'
    },
    transitText(row) {
      const labels = { request: '需求', plan: '计划', order: '订单', receipt: '到货待入库', exchange: '换货待补' }
      const stages = (row.in_transit_detail && row.in_transit_detail.stages) || {}
      return Object.keys(labels).filter(k => stages[k] > 0).map(k => `${labels[k]} ${qty(stages[k])}`).join(' · ')
    },
    async load() {
      this.loading = true
      try {
        const { data } = await listProductionShortages({
          page: this.page,
          per_page: 10,
          keyword: this.keyword || undefined,
          only_short: this.showAll ? 0 : 1
        })
        this.rows = data.data || []
        this.total = data.total || 0
        this.summary = data.summary || {}
        this.canConvert = !!data.can_convert
        this.expandedIds = []
      } catch (e) {
        this.$message.error(errorText(e, '生产缺料加载失败'))
      } finally {
        this.loading = false
      }
    },
    search() {
      this.page = 1
      this.load()
    },
    setShowAll(val) {
      if (this.showAll === val) return
      this.showAll = val
      this.search()
    },
    resetSearch() {
      this.keyword = ''
      this.showAll = false
      this.search()
    },
    onPageChange(v) {
      this.page = v
      this.load()
    },
    onSelectionChange(v) {
      this.picked = v
    },
    onExpandChange(row, expandedRows) {
      this.expandedIds = (expandedRows || []).map(r => r.item_id)
    },
    isExpanded(row) {
      return this.expandedIds.includes(row.item_id)
    },
    toggleRowExpand(row) {
      if (!this.$refs.table) return
      this.$refs.table.toggleRowExpansion(row)
    },
    toggleExpandAll() {
      if (!this.$refs.table || !this.rows.length) return
      const expand = !this.allExpanded
      this.rows.forEach(row => {
        this.$refs.table.toggleRowExpansion(row, expand)
      })
    },
    selectPageShortages() {
      if (!this.$refs.table) return
      this.shortRowsInPage.forEach(row => {
        if (!this.isPicked(row)) {
          this.$refs.table.toggleRowSelection(row, true)
        }
      })
    },
    clearSelection() {
      this.picked = []
      if (this.$refs.table) this.$refs.table.clearSelection()
    },
    isPicked(row) {
      return this.picked.some(p => p.item_id === row.item_id)
    },
    togglePick(row, on) {
      this.picked = on
        ? [...this.picked.filter(p => p.item_id !== row.item_id), row]
        : this.picked.filter(p => p.item_id !== row.item_id)
      if (this.$refs.table) {
        this.$refs.table.toggleRowSelection(row, !!on)
      }
    },
    openConvert() {
      this.singleRow = null
      this.dialog = true
    },
    openSingleConvert(row) {
      this.singleRow = row
      this.dialog = true
    },
    onDialogVisibleChange(val) {
      this.dialog = val
      if (!val) this.singleRow = null
    },
    openWorkOrder(w) {
      if (this.$can('production.work_order.view')) {
        this.$router.push(`/production/work-orders/${w.id}`)
      }
    },
    openRequest(r) {
      if (r && r.purchase_request_id && this.$can('purchase.request')) {
        this.$router.push(`/purchase/requests/${r.purchase_request_id}/detail`)
      }
    },
    done(res) {
      if (this.singleRow) {
        const target = this.singleRow
        this.singleRow = null
        this.togglePick(target, false)
      } else {
        this.clearSelection()
      }
      this.load()
      if (res && res.id && this.$can('purchase.request')) {
        this.$confirm(`采购需求 ${res.request_no} 已生成，去看看？`, '已生成', {
          confirmButtonText: '去查看',
          cancelButtonText: '留在这里',
          type: 'success'
        }).then(() => this.$router.push(`/purchase/requests/${res.id}/detail`)).catch(() => {})
      }
    }
  }
}
</script>

<style scoped>
.purchase-shortage-page {
  padding: 16px 20px 24px;
  background: #f5f7f9;
  min-height: calc(100vh - 52px);
  box-sizing: border-box;
}

.shortage-main {
  min-width: 0;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

/* 顶部页头卡片 */
.page-head {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.head-left {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.title-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.title-icon {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
}

.title-row h1 {
  margin: 0;
  flex-shrink: 0;
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
}

.subtitle {
  margin: 0;
  font-size: 12px;
  color: #64748b;
}

.head-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

/* 4 卡 KPI 统计概览 */
.stat-row {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 14px;
}

.stat-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}

.stat-icon-wrapper {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}

.stat-icon-wrapper.is-danger {
  background: #fef2f2;
  color: #dc2626;
}

.stat-icon-wrapper.is-warning {
  background: #fffbeb;
  color: #d97706;
}

.stat-icon-wrapper.is-green {
  background: #ecfdf5;
  color: #059669;
}

.stat-icon-wrapper.is-brand {
  background: #f0fdf4;
  color: #008b4b;
}

.stat-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.stat-label {
  font-size: 12px;
  color: #64748b;
}

.stat-val {
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.25;
}

.stat-val small {
  font-size: 12px;
  font-weight: 500;
  color: #64748b;
  margin-left: 2px;
}

.stat-sub {
  font-size: 11px;
  color: #94a3b8;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* 筛选工具栏 */
.filter-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 16px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.filter-inputs {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  flex: 1;
}

.filter-inputs .el-input {
  width: 280px;
  max-width: 100%;
}

.scope-switch {
  display: inline-flex;
  background: #f1f5f9;
  padding: 3px;
  border-radius: 6px;
  border: 1px solid #e2e8f0;
}

.scope-btn {
  border: 0;
  background: transparent;
  padding: 5px 12px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 500;
  color: #64748b;
  cursor: pointer;
  transition: all 0.18s;
}

.scope-btn:hover {
  color: #0f172a;
}

.scope-btn.is-active {
  background: #ffffff;
  color: #008b4b;
  font-weight: 600;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
  flex-wrap: wrap;
}

/* 列表主体面板 */
.table-panel {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  box-sizing: border-box;
}

.batch-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  padding: 9px 12px;
  margin-bottom: 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  font-size: 12px;
  color: #475569;
  transition: background 0.2s, border-color 0.2s;
}

.batch-bar.has-picked {
  background: #f0fdf4;
  border-color: #bbf7d0;
  color: #166534;
}

.batch-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.batch-left strong {
  color: #008b4b;
  font-size: 14px;
}

.batch-icon {
  font-size: 15px;
  color: #94a3b8;
}

.batch-icon.active {
  color: #008b4b;
}

.batch-right {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

/* 表格细节样式 */
.shortage-table ::v-deep th {
  background: #f8fafc !important;
  color: #475569;
  font-weight: 600;
  font-size: 12px;
}

.shortage-table ::v-deep td {
  font-size: 12px;
  padding-top: 9px;
  padding-bottom: 9px;
}

.shortage-table ::v-deep .el-table__expanded-cell {
  background: #f8fafc !important;
  padding: 12px 16px !important;
}

.item-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.item-top {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}

.code-badge {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 4px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  color: #334155;
  font-size: 11px;
  font-weight: 600;
}

.item-name {
  font-weight: 600;
  color: #0f172a;
  font-size: 13px;
}

.item-spec {
  font-size: 11px;
  color: #64748b;
}

.num-main {
  font-weight: 600;
  color: #0f172a;
  font-variant-numeric: tabular-nums;
}

.qty-ok {
  color: #059669;
}

.qty-brand {
  color: #008b4b;
}

.qty-zero {
  color: #94a3b8;
  font-weight: 400;
}

.unit-txt {
  color: #94a3b8;
  font-size: 11px;
  margin-left: 2px;
}

.transit-trigger {
  cursor: pointer;
  display: inline-flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 1px;
}

.transit-sub {
  font-size: 10px;
  color: #64748b;
  max-width: 118px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.transit-tooltip {
  line-height: 1.6;
  font-size: 12px;
}

.transit-tooltip .tt-head {
  font-weight: 600;
  padding-bottom: 4px;
  margin-bottom: 4px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.shortage-pill {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 999px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #dc2626;
  font-weight: 700;
  font-size: 12px;
  font-variant-numeric: tabular-nums;
}

.kitted-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: 999px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
  font-weight: 600;
  font-size: 12px;
}

.date-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: 4px;
  background: #f8fafc;
  color: #334155;
  font-size: 11px;
}

.date-pill.is-muted {
  color: #94a3b8;
  background: transparent;
}

.rel-badges {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  gap: 3px;
}

.rel-wo-tag {
  display: inline-block;
  padding: 1px 7px;
  border-radius: 4px;
  background: #f1f5f9;
  color: #334155;
  font-size: 11px;
  font-weight: 500;
}

.rel-req-tag {
  display: inline-block;
  padding: 1px 7px;
  border-radius: 4px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #b45309;
  font-size: 11px;
  font-weight: 500;
}

.priority-pill {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  line-height: 1.4;
}

.priority-pill.is-super-urgent {
  background: #fef2f2;
  color: #dc2626;
  border: 1px solid #fecaca;
}

.priority-pill.is-urgent {
  background: #fff7ed;
  color: #ea580c;
  border: 1px solid #fed7aa;
}

.priority-pill.is-defer {
  background: #f1f5f9;
  color: #64748b;
  border: 1px solid #e2e8f0;
}

.row-op-group {
  display: flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
}

.action-link-theme {
  color: #008b4b !important;
  font-weight: 500;
  font-size: 12px;
  padding: 2px 0;
}

.action-link-theme:hover {
  color: #00763f !important;
  text-decoration: underline;
}

.action-link-theme.is-strong {
  font-weight: 600;
}

/* 展开行拆解容器 */
.expand-panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.expand-section {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
}

.expand-section-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  padding-bottom: 8px;
  margin-bottom: 8px;
  border-bottom: 1px solid #f1f5f9;
}

.expand-title {
  font-size: 12px;
  font-weight: 600;
  color: #0f172a;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.expand-title i {
  color: #008b4b;
}

.expand-legend {
  font-size: 11px;
  color: #64748b;
}

.wo-cards {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.wo-card {
  border: 1px solid #eef2f6;
  background: #fbfcfd;
  border-radius: 6px;
  padding: 8px 10px;
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.wo-card-main {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.wo-card-left {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.wo-link {
  color: #008b4b;
  font-weight: 600;
  font-size: 12px;
  display: inline-flex;
  align-items: center;
  gap: 2px;
}

.wo-link.is-clickable {
  cursor: pointer;
}

.wo-link.is-clickable:hover {
  color: #00763f;
  text-decoration: underline;
}

.status-pill {
  padding: 1px 7px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 500;
}

.status-pill.is-active {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}

.status-pill.is-planned {
  background: #fffbeb;
  color: #b45309;
  border: 1px solid #fde68a;
}

.status-pill.is-neutral {
  background: #f1f5f9;
  color: #475569;
  border: 1px solid #e2e8f0;
}

.wo-product-name {
  font-size: 12px;
  color: #334155;
  font-weight: 500;
}

.wo-start-date {
  font-size: 11px;
  color: #64748b;
}

.wo-card-formula {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
  font-size: 12px;
  color: #475569;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 3px 8px;
}

.wo-card-formula .op {
  color: #94a3b8;
}

.formula-short {
  color: #dc2626;
  font-weight: 700;
}

.formula-ok {
  color: #008b4b;
  font-weight: 600;
}

.ahead-tip {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: #b45309;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 4px;
  padding: 3px 8px;
}

.expand-extras {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 10px;
}

.extra-box {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 9px 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.extra-box.is-request-box {
  background: #fffdf7;
  border-color: #fde68a;
}

.extra-title {
  font-size: 12px;
  font-weight: 600;
  color: #334155;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.extra-title i {
  color: #008b4b;
}

.extra-box.is-request-box .extra-title i {
  color: #d97706;
}

.doc-chip-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.doc-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 8px;
  border-radius: 4px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  font-size: 11px;
  color: #334155;
}

.doc-chip.is-req {
  background: #ffffff;
  border-color: #fcd34d;
  color: #92400e;
}

.doc-chip.is-clickable {
  cursor: pointer;
}

.doc-chip.is-clickable:hover {
  border-color: #008b4b;
  color: #008b4b;
}

.doc-stage {
  padding: 0 4px;
  border-radius: 3px;
  background: #f0fdf4;
  color: #008b4b;
  font-weight: 600;
}

/* 移动端卡片 */
.mobile-only {
  display: none;
}

.empty-mobile {
  text-align: center;
  color: #94a3b8;
  font-size: 12px;
  padding: 28px 0;
}

.m-card {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 10px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-size: 12px;
}

.m-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.m-title-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.m-item-name {
  font-weight: 600;
  color: #0f172a;
  font-size: 13px;
}

.m-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.m-metrics {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px;
  background: #f8fafc;
  border-radius: 6px;
  padding: 8px;
}

.m-metrics > div {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
}

.m-metrics small {
  color: #64748b;
}

.m-transit {
  font-size: 11px;
  color: #475569;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 4px;
  padding: 4px 8px;
}

.m-wo-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.m-wo-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 7px;
  border-radius: 4px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  font-size: 11px;
}

.m-req-list {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: #92400e;
}

.m-req-chip {
  padding: 1px 6px;
  border-radius: 4px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  cursor: pointer;
}

.m-actions {
  display: flex;
  justify-content: flex-end;
  padding-top: 4px;
  border-top: 1px dashed #e2e8f0;
}

/* 分页栏 */
.pager-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid #f1f5f9;
  flex-wrap: wrap;
  gap: 8px;
}

.pager-info {
  color: #64748b;
  font-size: 12px;
}

/* 主题绿控件覆盖（消除原生 Element UI 蓝色） */
.purchase-shortage-page ::v-deep .el-button--success {
  background-color: #008b4b;
  border-color: #008b4b;
}

.purchase-shortage-page ::v-deep .el-button--success:hover,
.purchase-shortage-page ::v-deep .el-button--success:focus {
  background-color: #00763f;
  border-color: #00763f;
}

.purchase-shortage-page ::v-deep .el-button--success.is-plain {
  background-color: #f0fdf4;
  border-color: #bbf7d0;
  color: #008b4b;
}

.purchase-shortage-page ::v-deep .el-button--success.is-plain:hover,
.purchase-shortage-page ::v-deep .el-button--success.is-plain:focus {
  background-color: #008b4b;
  border-color: #008b4b;
  color: #ffffff;
}

.purchase-shortage-page ::v-deep .el-button--default:hover,
.purchase-shortage-page ::v-deep .el-button--default:focus {
  color: #008b4b;
  border-color: #bbf7d0;
  background-color: #f0fdf4;
}

.purchase-shortage-page ::v-deep .el-input__inner:focus {
  border-color: #008b4b;
}

.purchase-shortage-page ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner,
.purchase-shortage-page ::v-deep .el-checkbox__input.is-indeterminate .el-checkbox__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}

.purchase-shortage-page ::v-deep .el-checkbox__inner:hover,
.purchase-shortage-page ::v-deep .el-checkbox__input.is-focus .el-checkbox__inner {
  border-color: #008b4b;
}

.purchase-shortage-page ::v-deep .el-checkbox__input.is-checked + .el-checkbox__label {
  color: #0f172a;
}

.purchase-shortage-page ::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #ffffff !important;
  border-color: #008b4b !important;
  border-radius: 4px;
}

@media (max-width: 1180px) {
  .stat-row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 767px) {
  .purchase-shortage-page {
    padding: 12px 10px 20px;
  }
  .desktop-only {
    display: none;
  }
  .mobile-only {
    display: block;
  }
  .stat-row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }
  .filter-card {
    flex-direction: column;
    align-items: stretch;
  }
  .filter-inputs {
    flex-direction: column;
    align-items: stretch;
  }
  .filter-inputs .el-input {
    width: 100%;
  }
  .scope-switch {
    width: 100%;
    display: grid;
    grid-template-columns: 1fr 1fr;
  }
  .filter-actions {
    margin-left: 0;
    justify-content: flex-end;
  }
  .batch-bar {
    flex-direction: column;
    align-items: stretch;
  }
  .batch-right {
    justify-content: flex-end;
  }
}
</style>
