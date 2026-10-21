<template>
  <div class="work-order-page" v-loading="loading">
    <!-- 页面全局头部卡片 (100% 对齐主数据中心 / SkuList.vue 风格) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-s-order" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">生产工单管理</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag total-tag">
              共 {{ total }} 张工单
            </el-tag>
            <el-tag
              v-if="filters.status"
              size="small"
              type="info"
              effect="plain"
              closable
              class="head-tag filter-tag"
              @close="pickTab('')"
            >
              状态：{{ statusLabel(filters.status) }}
            </el-tag>
            <el-tag
              v-if="filters.stuck_only"
              size="small"
              type="danger"
              effect="plain"
              closable
              class="head-tag filter-tag"
              @close="toggleStuck"
            >
              仅看阻塞 / 异常工单
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button
          size="small"
          icon="el-icon-refresh"
          class="btn-refresh"
          :loading="loading"
          @click="load"
        >
          刷新
        </el-button>
        <el-button
          v-if="$can('production.work_order.create')"
          size="small"
          type="success"
          icon="el-icon-plus"
          class="btn-theme-create"
          @click="$router.push('/production/work-orders/new')"
        >
          新建备货工单
        </el-button>
      </div>
    </header>

    <!-- 全局统一页面业务提示条 (对齐主数据中心规范) -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>销售订单确认后自动生成并下达生产工单，支持备货工单独立录入；全流程跟踪工序过站报工、物料齐套分析、交期预警与阻塞处置。</span>
    </div>

    <!-- 顶部概览指标卡片 (100% 对齐主数据中心 metric-overview-grid 风格) -->
    <section class="metric-overview-grid">
      <div
        class="metric-card metric-released"
        :class="{ active: filters.status === 'released' && !filters.stuck_only }"
        title="点击筛选已下达待开工工单"
        @click="pickTab('released')"
      >
        <div class="metric-icon-box"><i class="el-icon-time" /></div>
        <div class="metric-info">
          <span class="metric-label">已下达待开工</span>
          <strong class="metric-val code-mono">{{ summary.waiting_start || 0 }}</strong>
          <span class="metric-sub">序列号已生成待车间开工</span>
        </div>
      </div>

      <div
        class="metric-card metric-in-progress"
        :class="{ active: filters.status === 'in_progress' && !filters.stuck_only }"
        title="点击筛选生产在制工单"
        @click="pickTab('in_progress')"
      >
        <div class="metric-icon-box"><i class="el-icon-video-play" /></div>
        <div class="metric-info">
          <span class="metric-label">生产中工单</span>
          <strong class="metric-val code-mono">{{ summary.in_progress || 0 }}</strong>
          <span class="metric-sub">共 {{ summary.in_progress_units || 0 }} 台在制中</span>
        </div>
      </div>

      <div
        class="metric-card metric-stuck"
        :class="{ active: filters.stuck_only }"
        title="点击筛选异常阻塞与暂停工单"
        @click="toggleStuck"
      >
        <div class="metric-icon-box"><i class="el-icon-warning-outline" /></div>
        <div class="metric-info">
          <span class="metric-label">异常阻塞 / 暂停</span>
          <strong class="metric-val code-mono" :class="{ warn: summary.stuck }">{{ summary.stuck || 0 }}</strong>
          <span class="metric-sub">其中工单暂停 {{ summary.stuck_paused || 0 }} 张</span>
        </div>
      </div>

      <div
        class="metric-card metric-due-week"
        title="本周计划交期工单"
      >
        <div class="metric-icon-box"><i class="el-icon-date" /></div>
        <div class="metric-info">
          <span class="metric-label">本周计划交期</span>
          <strong class="metric-val code-mono">{{ summary.due_this_week || 0 }}</strong>
          <span class="metric-sub">按计划完工日统计</span>
        </div>
      </div>

      <div
        class="metric-card metric-overdue"
        title="已逾期工单预警"
      >
        <div class="metric-icon-box"><i class="el-icon-alarm-clock" /></div>
        <div class="metric-info">
          <span class="metric-label">逾期预警工单</span>
          <strong class="metric-val code-mono" :class="{ warn: summary.overdue }">{{ summary.overdue || 0 }}</strong>
          <span class="metric-sub">超出计划完工日期</span>
        </div>
      </div>
    </section>

    <!-- 列表数据卡片容器 (对齐主数据中心 table-container-card 规范) -->
    <section class="table-container-card">
      <!-- 状态筛选标签栏 -->
      <div class="status-tab-bar">
        <div
          v-for="tab in tabs"
          :key="tab.value"
          class="status-tab-item"
          :class="{ active: filters.status === tab.value && !filters.stuck_only }"
          @click="pickTab(tab.value)"
        >
          <span class="tab-text">{{ tab.label }}</span>
          <span class="tab-count code-mono">{{ tabCount(tab.value) }}</span>
        </div>
      </div>

      <!-- 搜索筛选工具栏 -->
      <div class="filter-toolbar">
        <div class="filter-fields">
          <el-input
            v-model="filters.keyword"
            size="small"
            clearable
            prefix-icon="el-icon-search"
            placeholder="搜索工单编号、产品名称、销售订单号、客户名称、序列号..."
            class="filter-input-search"
            @keyup.enter.native="search"
            @clear="search"
          />
          <el-date-picker
            v-model="dueRange"
            size="small"
            type="daterange"
            value-format="yyyy-MM-dd"
            range-separator="至"
            start-placeholder="交期起始"
            end-placeholder="交期截至"
            class="filter-date-picker"
            @change="search"
          />
          <el-select
            v-model="filters.owner_legacy_id"
            size="small"
            clearable
            filterable
            remote
            :remote-method="searchOwners"
            placeholder="生产负责人"
            class="filter-select"
            @change="search"
            @focus="searchOwners('')"
          >
            <el-option v-for="u in owners" :key="u.id" :label="u.name" :value="u.id" />
          </el-select>
          <el-select
            v-model="filters.source_type"
            size="small"
            clearable
            placeholder="需求来源：全部"
            class="filter-select-sm"
            @change="search"
          >
            <el-option label="全部来源" value="" />
            <el-option label="销售订单" value="sales_order" />
            <el-option label="常规备货" value="stock" />
            <el-option label="半成品补做" value="work_order" />
          </el-select>
          <el-checkbox
            v-model="filters.stuck_only"
            border
            size="small"
            class="filter-checkbox"
            @change="search"
          >
            仅看异常阻塞
          </el-checkbox>
        </div>
        <div class="filter-actions">
          <el-button size="small" type="primary" icon="el-icon-search" class="btn-primary-search" @click="search">查询</el-button>
          <el-button size="small" icon="el-icon-refresh-left" class="btn-reset" @click="resetFilters">重置</el-button>
        </div>
      </div>

      <!-- 桌面端表格呈现 -->
      <div class="main-table-wrap desktop-only">
        <el-table
          :data="rows"
          size="small"
          border
          class="custom-wo-table"
          empty-text="暂无匹配的生产工单数据"
          @row-click="open"
        >
          <el-table-column label="工单编号与产品" min-width="240">
            <template slot-scope="{ row }">
              <div class="wo-no-row">
                <span class="code-mono wo-no-link" @click.stop="open(row)">
                  <i class="el-icon-document" /> {{ row.work_order_no }}
                </span>
                <span v-if="row.is_urgent" class="urgent-badge">加急</span>
              </div>
              <div class="product-title-row">
                <span class="product-name font-medium">{{ productName(row) }}</span>
                <span class="qty-badge font-mono">× {{ qty(row.planned_qty) }} {{ row.unit_name || '台' }}</span>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="需求来源" min-width="160">
            <template slot-scope="{ row }">
              <template v-if="row.source_type === 'sales_order'">
                <div class="source-order-no code-mono">
                  <i class="el-icon-tickets text-primary" /> {{ row.sales_order_no }}
                </div>
                <div class="customer-name-sub text-truncate" :title="row.customer_name">
                  {{ row.customer_name }}
                </div>
              </template>
              <template v-else>
                <div class="source-stock-box">
                  <el-tag size="mini" type="info" effect="plain">{{ row.source_type === 'work_order' ? '半成品补做' : '库存备货' }}</el-tag>
                  <span class="stock-reason-text">{{ row.source_type === 'work_order' ? (row.sales_order_no || '总装缺料') : (row.stock_reason_label || '常规备货') }}</span>
                </div>
              </template>
            </template>
          </el-table-column>

          <el-table-column label="制令状态" width="110" align="center">
            <template slot-scope="{ row }">
              <el-tag size="small" :type="unitStatusType(row.status)" effect="plain">
                {{ row.status_label || row.status }}
              </el-tag>
            </template>
          </el-table-column>

          <el-table-column label="工序加工进度" min-width="250">
            <template slot-scope="{ row }">
              <div v-if="row.progress && row.progress.length" class="ops-progress-flow">
                <div
                  v-for="(p, i) in row.progress"
                  :key="i"
                  class="op-progress-item"
                  :class="stepClass(p)"
                >
                  <span class="op-counts font-mono">{{ qty(p.completed_qty) }}/{{ qty(p.planned_qty) }}</span>
                  <span class="op-name">{{ p.operation_name }}</span>
                </div>
              </div>
              <span v-else class="text-muted">未配置工艺路线</span>
            </template>
          </el-table-column>

          <el-table-column label="计划完工交期" width="150">
            <template slot-scope="{ row }">
              <div class="due-date-text code-mono">
                {{ shortDate(row.required_date || row.planned_finish_date) }}
              </div>
              <div :class="['due-status-badge', dueClass(row)]">
                {{ dueText(row) }}
              </div>
            </template>
          </el-table-column>

          <el-table-column label="异常阻塞 / 状态" min-width="150">
            <template slot-scope="{ row }">
              <span :class="['stuck-status-box', stuckClass(row)]">
                <i :class="stuckIcon(row)" />
                {{ stuckText(row) }}
              </span>
            </template>
          </el-table-column>

          <el-table-column prop="owner_name" label="负责人" width="100" align="center">
            <template slot-scope="{ row }">
              <span>{{ row.owner_name || '—' }}</span>
            </template>
          </el-table-column>

          <el-table-column label="操作" width="85" align="center">
            <template slot-scope="{ row }">
              <el-button type="text" size="small" class="action-link-theme" @click.stop="open(row)">
                查看详情
              </el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <!-- 移动端自适应卡片列表 -->
      <div class="mobile-only mobile-card-list">
        <div v-if="!rows.length" class="empty-mobile-notice">暂无工单数据</div>
        <div
          v-for="row in rows"
          :key="row.id"
          class="mobile-wo-card"
          @click="open(row)"
        >
          <div class="mobile-card-head">
            <span class="code-mono mobile-wo-no">{{ row.work_order_no }}</span>
            <div class="mobile-status-wrap">
              <el-tag size="mini" :type="unitStatusType(row.status)" effect="plain">{{ row.status_label }}</el-tag>
              <span v-if="row.is_urgent" class="urgent-badge">加急</span>
            </div>
          </div>
          <div class="mobile-product-title font-medium">
            {{ productName(row) }}
            <b class="qty-badge font-mono">× {{ qty(row.planned_qty) }} {{ row.unit_name || '台' }}</b>
          </div>
          <div class="mobile-source-sub">
            {{ row.source_type === 'stock' ? '备货 · ' + (row.stock_reason_label || '常规备货') : (row.source_type === 'work_order' ? '半成品补做 · ' : '') + (row.sales_order_no || '总装缺料') + ' · ' + (row.customer_name || '—') }}
          </div>
          <div v-if="row.progress && row.progress.length" class="ops-progress-flow mobile-ops">
            <div
              v-for="(p, i) in row.progress"
              :key="i"
              class="op-progress-item"
              :class="stepClass(p)"
            >
              <span class="op-counts font-mono">{{ qty(p.completed_qty) }}/{{ qty(p.planned_qty) }}</span>
              <span class="op-name">{{ p.operation_name }}</span>
            </div>
          </div>
          <div class="mobile-card-foot">
            <span class="mobile-foot-left">
              交期 {{ shortDate(row.required_date || row.planned_finish_date) }} ｜ {{ row.owner_name || '未指定负责人' }}
            </span>
            <span :class="['stuck-status-box', stuckClass(row)]">
              {{ stuckText(row) }}
            </span>
          </div>
        </div>
      </div>

      <!-- 分页栏 (默认每页 10 条，对齐主数据中心规范) -->
      <div class="pagination-container">
        <span class="total-text">共 {{ total }} 条记录</span>
        <el-pagination
          background
          small
          layout="total, prev, pager, next, jumper"
          :current-page="page"
          :page-size="10"
          :total="total"
          @current-change="onPageChange"
        />
      </div>
    </section>
  </div>
</template>

<script>
import { listWorkOrders } from '../../../api/erp/production'
import { STATUS_CHIP, STATUS_TABS, qty, shortDate, errorText, searchProductionUsers } from './mfg-helpers'

const emptyFilters = () => ({ keyword: '', status: '', owner_legacy_id: '', source_type: '', stuck_only: false })

export default {
  name: 'WorkOrderList',
  data: () => ({
    loading: false,
    rows: [],
    total: 0,
    page: 1,
    summary: {},
    filters: emptyFilters(),
    dueRange: [],
    owners: [],
    tabs: STATUS_TABS
  }),
  created() {
    if (this.$route.query.status) this.filters.status = String(this.$route.query.status)
    this.load()
  },
  methods: {
    qty,
    shortDate,
    async load() {
      this.loading = true
      try {
        const params = { ...this.filters, page: this.page, per_page: 10 }
        params.stuck_only = this.filters.stuck_only ? 1 : undefined
        if (this.dueRange && this.dueRange.length) {
          params.due_from = this.dueRange[0]
          params.due_to = this.dueRange[1]
        }
        Object.keys(params).forEach(k => {
          if (params[k] === '' || params[k] === null) delete params[k]
        })
        const { data } = await listWorkOrders(params)
        this.rows = data.data || []
        this.total = data.total || 0
        this.summary = data.summary || {}
      } catch (e) {
        this.$message.error(errorText(e, '工单列表加载失败'))
      } finally {
        this.loading = false
      }
    },
    search() {
      this.page = 1
      this.load()
    },
    resetFilters() {
      this.filters = emptyFilters()
      this.dueRange = []
      this.page = 1
      this.load()
    },
    onPageChange(v) {
      this.page = v
      this.load()
    },
    pickTab(status) {
      this.filters.status = status
      this.search()
    },
    toggleStuck() {
      this.filters.stuck_only = !this.filters.stuck_only
      this.search()
    },
    tabCount(status) {
      const counts = this.summary.status_counts || {}
      return status ? (counts[status] || 0) : (this.summary.all || 0)
    },
    statusLabel(status) {
      const match = this.tabs.find(t => t.value === status)
      return match ? match.label : status
    },
    unitStatusType(status) {
      const map = {
        planned: 'info',
        released: 'primary',
        in_progress: 'warning',
        paused: 'warning',
        completed: 'success',
        closed: 'info',
        cancelled: 'info'
      }
      return map[status] || 'info'
    },
    async searchOwners(keyword) {
      try {
        this.owners = await searchProductionUsers(keyword)
      } catch (e) {
        this.owners = []
      }
    },
    open(row) {
      this.$router.push(`/production/work-orders/${row.id}`)
    },
    productName(row) {
      return [row.product_name || row.item_name, row.sku_code].filter(Boolean).join(' ')
    },
    chipOf(status) {
      return STATUS_CHIP[status] || 'c-n'
    },
    stepClass(p) {
      if (p.planned_qty > 0 && p.completed_qty >= p.planned_qty) return 'step-done'
      return p.completed_qty > 0 ? 'step-running' : 'step-waiting'
    },
    dueText(row) {
      if (row.status === 'completed' || row.status === 'closed') return '已完工'
      if (row.overdue) return '超出计划完工日'
      if (!row.started) return '未开工'
      return '计划 ' + shortDate(row.planned_finish_date) + ' 完工'
    },
    dueClass(row) {
      return row.overdue ? 'badge-overdue' : (row.status === 'completed' ? 'badge-ok' : 'badge-sub')
    },
    stuckText(row) {
      if (row.stuck_reason) return row.stuck_reason
      if (row.status === 'completed') return row.cost_status === 'settled' ? '成本已结算' : '成本待结算'
      if (['released', 'in_progress'].includes(row.status)) return '运行正常'
      return '—'
    },
    stuckClass(row) {
      if (row.stuck_reason) return row.status === 'paused' ? 'stuck-paused' : 'stuck-blocked'
      return ['released', 'in_progress'].includes(row.status) ? 'stuck-normal' : 'stuck-muted'
    },
    stuckIcon(row) {
      if (row.stuck_reason) return 'el-icon-warning'
      if (['released', 'in_progress'].includes(row.status)) return 'el-icon-circle-check'
      return 'el-icon-minus'
    }
  }
}
</script>

<style scoped>
.work-order-page {
  padding: 16px 20px 24px;
  background: #f8fafc;
  min-height: calc(100vh - 54px);
  box-sizing: border-box;
}

/* 顶部标头卡片 (100% 对齐主数据中心 / SkuList.vue 风格) */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #ffffff;
  padding: 16px 20px;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  border: 1px solid #e2e8f0;
  margin-bottom: 12px;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.head-icon {
  width: 40px;
  height: 40px;
  background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
  color: #008b4b;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}

.head-title-wrap {
  display: flex;
  flex-direction: column;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

.head-tag {
  font-size: 12px;
  border-radius: 4px;
  height: 24px;
  line-height: 22px;
  padding: 0 8px;
}

.total-tag {
  background: #f0fdf4 !important;
  border-color: #bbf7d0 !important;
  color: #008b4b !important;
  font-weight: 600;
}

.filter-tag {
  background: #f1f5f9 !important;
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-refresh {
  background: #ffffff !important;
  border: 1px solid #dcdfe6 !important;
  color: #606266 !important;
}

.btn-refresh:hover {
  color: #008b4b !important;
  border-color: #bbf7d0 !important;
  background: #f0fdf4 !important;
}

.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}

.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 全局统一提示条 */
.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #00763f;
  padding: 10px 14px;
  border-radius: 6px;
  font-size: 13px;
  line-height: 1.5;
  margin-bottom: 14px;
}

.erp-page-tip i {
  font-size: 15px;
  flex-shrink: 0;
}

/* 顶部概览指标卡片组 (5 列响应式) */
.metric-overview-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 14px;
  margin-bottom: 16px;
}

.metric-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}

.metric-card:hover,
.metric-card.active {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
  border-color: #008b4b;
}

.metric-icon-box {
  width: 42px;
  height: 42px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  font-size: 20px;
  flex-shrink: 0;
}

.metric-released .metric-icon-box { background: #eff6ff; color: #2563eb; }
.metric-in-progress .metric-icon-box { background: #f0fdf4; color: #059669; }
.metric-stuck .metric-icon-box { background: #fef2f2; color: #dc2626; }
.metric-due-week .metric-icon-box { background: #eaf7ef; color: #008b4b; }
.metric-overdue .metric-icon-box { background: #fff1f2; color: #e11d48; }

.metric-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.metric-label {
  font-size: 12px;
  color: #64748b;
}

.metric-val {
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
  margin: 2px 0;
}

.metric-val.warn {
  color: #dc2626;
}

.metric-sub {
  font-size: 11px;
  color: #94a3b8;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* 列表表格主容器卡片 */
.table-container-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  overflow: hidden;
}

/* 状态标签选项栏 */
.status-tab-bar {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 10px 18px 0;
  background: #ffffff;
  border-bottom: 1px solid #f1f5f9;
  overflow-x: auto;
}

.status-tab-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  color: #64748b;
  font-size: 13px;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  transition: all 0.15s ease;
  white-space: nowrap;
}

.status-tab-item:hover {
  color: #008b4b;
}

.status-tab-item.active {
  color: #008b4b;
  font-weight: 600;
  border-bottom-color: #008b4b;
}

.tab-count {
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 10px;
  background: #f1f5f9;
  color: #64748b;
}

.status-tab-item.active .tab-count {
  background: #eaf7ef;
  color: #008b4b;
}

/* 筛选工具栏 */
.filter-toolbar {
  padding: 14px 18px;
  background: #ffffff;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.filter-fields {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  flex: 1;
}

.filter-input-search {
  width: 280px;
}

.filter-date-picker {
  width: 250px;
}

.filter-select {
  width: 140px;
}

.filter-select-sm {
  width: 140px;
}

.filter-checkbox {
  margin-left: 4px;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.btn-primary-search {
  background: #008b4b !important;
  border-color: #008b4b !important;
}

.btn-primary-search:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

.btn-reset {
  border-color: #dcdfe6 !important;
  color: #606266 !important;
}

.btn-reset:hover {
  color: #008b4b !important;
  border-color: #bbf7d0 !important;
}

/* 表格主体 */
.main-table-wrap {
  width: 100%;
  overflow-x: auto;
}

.custom-wo-table {
  width: 100%;
}

.wo-no-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.wo-no-link {
  font-size: 13px;
  font-weight: 600;
  color: #008b4b;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.wo-no-link:hover {
  text-decoration: underline;
}

.urgent-badge {
  display: inline-block;
  padding: 1px 6px;
  background: #fee2e2;
  color: #dc2626;
  border: 1px solid #fca5a5;
  border-radius: 3px;
  font-size: 11px;
  font-weight: 600;
  line-height: 1.2;
}

.product-title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 4px;
  flex-wrap: wrap;
}

.product-name {
  color: #1e293b;
  font-size: 13px;
}

.qty-badge {
  background: #f1f5f9;
  padding: 1px 6px;
  border-radius: 4px;
  color: #334155;
  font-size: 12px;
}

.source-order-no {
  font-size: 12px;
  color: #2563eb;
  display: flex;
  align-items: center;
  gap: 4px;
}

.customer-name-sub {
  font-size: 12px;
  color: #64748b;
  margin-top: 2px;
}

.source-stock-box {
  display: flex;
  align-items: center;
  gap: 6px;
}

.stock-reason-text {
  font-size: 12px;
  color: #64748b;
}

/* 工序进度流式小卡片 */
.ops-progress-flow {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
}

.op-progress-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 44px;
  padding: 3px 6px;
  border-radius: 4px;
  font-size: 11px;
  line-height: 1.3;
  border: 1px solid transparent;
}

.op-counts {
  font-size: 10px;
}

.op-name {
  white-space: nowrap;
}

.step-done {
  background: #f0fdf4;
  border-color: #bbf7d0;
  color: #00763f;
  font-weight: 600;
}

.step-running {
  background: #fffbeb;
  border-color: #fde68a;
  color: #b45309;
  font-weight: 600;
}

.step-waiting {
  background: #f8fafc;
  border-color: #e2e8f0;
  color: #94a3b8;
}

/* 交期提示 */
.due-date-text {
  font-size: 13px;
  color: #1e293b;
  font-weight: 500;
}

.due-status-badge {
  font-size: 11px;
  margin-top: 2px;
}

.badge-overdue {
  color: #dc2626;
  font-weight: 600;
}

.badge-ok {
  color: #008b4b;
}

.badge-sub {
  color: #64748b;
}

/* 阻塞状态 */
.stuck-status-box {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
}

.stuck-normal {
  color: #008b4b;
}

.stuck-paused {
  color: #d97706;
  font-weight: 500;
}

.stuck-blocked {
  color: #dc2626;
  font-weight: 600;
}

.stuck-muted {
  color: #94a3b8;
}

.action-link-theme {
  color: #008b4b;
  font-weight: 500;
}

.action-link-theme:hover {
  color: #00763f;
  text-decoration: underline;
}

/* 分页容器 (对齐主数据中心) */
.pagination-container {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 18px;
  background: #ffffff;
  border-top: 1px solid #f1f5f9;
  flex-wrap: wrap;
  gap: 10px;
}

.total-text {
  font-size: 13px;
  color: #64748b;
}

/* 移动端视图 */
.mobile-only {
  display: none;
}

.mobile-card-list {
  padding: 10px;
}

.mobile-wo-card {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 10px;
  background: #ffffff;
  display: flex;
  flex-direction: column;
  gap: 8px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.mobile-wo-card:active {
  background: #f8fafc;
}

.mobile-card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

.mobile-wo-no {
  font-weight: 700;
  color: #008b4b;
  font-size: 13px;
}

.mobile-status-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
}

.mobile-product-title {
  font-size: 14px;
  color: #0f172a;
}

.mobile-source-sub {
  font-size: 12px;
  color: #64748b;
}

.mobile-ops {
  margin: 2px 0;
}

.mobile-card-foot {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  padding-top: 6px;
  border-top: 1px solid #f1f5f9;
  font-size: 12px;
  flex-wrap: wrap;
}

.mobile-foot-left {
  color: #64748b;
}

.empty-mobile-notice {
  padding: 24px 0;
  text-align: center;
  color: #94a3b8;
  font-size: 13px;
}

/* 通用字体及响应式断点 */
.code-mono,
.font-mono {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.3px;
}

.font-medium {
  font-weight: 600;
}

.text-muted {
  color: #94a3b8;
}

.text-primary {
  color: #2563eb;
}

.text-truncate {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (max-width: 1280px) {
  .metric-overview-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

@media (max-width: 900px) {
  .metric-overview-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .work-order-page {
    padding: 12px 10px 20px;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  .head-actions {
    width: 100%;
    justify-content: flex-start;
  }
  .desktop-only {
    display: none;
  }
  .mobile-only {
    display: block;
  }
  .filter-input-search,
  .filter-date-picker,
  .filter-select,
  .filter-select-sm {
    width: 100%;
  }
}

@media (max-width: 480px) {
  .metric-overview-grid {
    grid-template-columns: 1fr;
  }
}
</style>
