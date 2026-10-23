<template>
  <div class="tech-list-page" v-loading="loading">
    <!-- 顶部统一标头 (.page-head) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-set-up" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">定制工艺管理</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">
              共 {{ total }} 项
            </el-tag>
          </div>
          <div class="sub-row">
            针对定制非标工单进行工艺编制、图纸管理与定制BOM复核确认，下发后车间方可排产投产。
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="load">
          刷新
        </el-button>
      </div>
    </header>

    <!-- 主体容器卡片 -->
    <div class="main-card">
      <!-- 状态切换选项卡 -->
      <div class="status-tabs">
        <button
          v-for="tab in tabs"
          :key="tab.value"
          type="button"
          :class="['tab-item', { active: filters.status === tab.value }]"
          @click="pickTab(tab.value)"
        >
          {{ tab.label }}
          <span class="tab-badge">{{ tabCount(tab.value) }}</span>
        </button>
      </div>

      <!-- 筛选工具栏 -->
      <div class="filter-bar">
        <el-input
          v-model="filters.keyword"
          class="search-input"
          size="small"
          clearable
          placeholder="搜索工单号 / 订单号 / 客户名称 / 产品名称"
          prefix-icon="el-icon-search"
          @keyup.enter.native="search"
          @clear="search"
        />
        <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" @click="search">
          查询
        </el-button>
        <el-button size="small" icon="el-icon-refresh-right" class="btn-theme-reset" @click="resetFilters">
          重置
        </el-button>
      </div>

      <!-- PC 端表格展示 -->
      <div class="desktop-only table-wrap">
        <el-table
          :data="rows"
          class="custom-tech-table"
          empty-text="暂无定制工艺工单"
          stripe
          @row-click="open"
        >
          <el-table-column label="工单编号 / 产品信息" min-width="220">
            <template slot-scope="s">
              <div class="mono-code">{{ s.row.work_order_no }}</div>
              <div class="prod-name">
                {{ productName(s.row) }}
                <b class="qty-badge">× {{ qty(s.row.planned_qty) }} {{ s.row.unit_name || '台' }}</b>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="销售订单 / 客户" min-width="160">
            <template slot-scope="s">
              <div class="mono-sub">{{ s.row.sales_order_no || '-' }}</div>
              <div class="customer-text">{{ s.row.customer_name || '-' }}</div>
            </template>
          </el-table-column>

          <el-table-column label="客户定制需求" min-width="260">
            <template slot-scope="s">
              <div class="custom-desc" :title="s.row.custom_description || '未填写定制需求描述'">
                {{ s.row.custom_description || '未填写定制需求描述' }}
              </div>
            </template>
          </el-table-column>

          <el-table-column label="技术资料进度" min-width="170">
            <template slot-scope="s">
              <div :class="['progress-item', s.row.drawing_count ? 'text-success' : 'text-muted']">
                <i :class="s.row.drawing_count ? 'el-icon-document-checked' : 'el-icon-document-remove'" />
                {{ s.row.drawing_count ? '设计图纸 ' + s.row.drawing_count + ' 份' : '图纸未上传' }}
              </div>
              <div :class="['progress-item', s.row.bom_confirmed ? 'text-success' : 'text-warning']">
                <i :class="s.row.bom_confirmed ? 'el-icon-circle-check' : 'el-icon-warning-outline'" />
                {{ s.row.bom_confirmed ? '定制BOM已确认' : '定制BOM待确认' }}
              </div>
            </template>
          </el-table-column>

          <el-table-column label="工艺状态" width="140">
            <template slot-scope="s">
              <el-tag size="mini" :type="s.row.status === 'pending' ? 'warning' : 'success'" effect="plain">
                {{ s.row.status === 'pending' ? '待工艺确认' : '已下发车间' }}
              </el-tag>
              <el-tag v-if="s.row.is_urgent" size="mini" type="danger" effect="dark" class="urgent-tag">
                加急
              </el-tag>
              <div v-if="s.row.status === 'released'" class="sub-label">工单{{ s.row.work_order_status_label }}</div>
            </template>
          </el-table-column>

          <el-table-column label="要求交期" width="110">
            <template slot-scope="s">
              <span class="date-text">{{ shortDate(s.row.required_date) }}</span>
            </template>
          </el-table-column>

          <el-table-column label="工艺负责人" width="120">
            <template slot-scope="s">
              <span class="owner-text">{{ s.row.tech_owner_name || '未指定' }}</span>
            </template>
          </el-table-column>

          <el-table-column label="操作" width="100" align="center">
            <template slot-scope="s">
              <el-button type="text" size="mini" icon="el-icon-edit" class="action-btn-primary" @click.stop="open(s.row)">
                {{ s.row.status === 'pending' ? '编制工艺' : '查看详情' }}
              </el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <!-- 移动端卡片流 -->
      <div class="mobile-only">
        <div v-if="!rows.length" class="empty-box">暂无定制工艺工单</div>
        <div v-for="row in rows" :key="row.work_order_id" class="m-row" @click="open(row)">
          <div class="m-head">
            <span class="mono-code">{{ row.work_order_no }}</span>
            <div class="m-tags">
              <el-tag size="mini" :type="row.status === 'pending' ? 'warning' : 'success'" effect="plain">
                {{ row.status === 'pending' ? '待工艺确认' : '已下发车间' }}
              </el-tag>
              <el-tag v-if="row.is_urgent" size="mini" type="danger" effect="dark">加急</el-tag>
            </div>
          </div>
          <div class="prod-name">{{ productName(row) }} <b>× {{ qty(row.planned_qty) }} {{ row.unit_name || '台' }}</b></div>
          <div class="mono-sub">{{ row.sales_order_no || '-' }} · {{ row.customer_name || '-' }}</div>
          <div class="custom-desc">{{ row.custom_description || '未填写定制需求描述' }}</div>
          <div class="m-foot">
            <span>交期 {{ shortDate(row.required_date) }} · {{ row.tech_owner_name || '未指定工艺员' }}</span>
            <span>
              <span :class="row.drawing_count ? 'text-success' : 'text-muted'">{{ row.drawing_count ? '图纸 ' + row.drawing_count : '未传图' }}</span> ·
              <span :class="row.bom_confirmed ? 'text-success' : 'text-warning'">{{ row.bom_confirmed ? 'BOM已确认' : 'BOM待确认' }}</span>
            </span>
          </div>
        </div>
      </div>

      <!-- 分页栏 -->
      <div class="pagination-bar">
        <span class="total-text">共 {{ total }} 条记录</span>
        <el-pagination
          background
          layout="total, prev, pager, next, jumper"
          :current-page="page"
          :page-size="10"
          :total="total"
          @current-change="v => { page = v; load() }"
        />
      </div>
    </div>
  </div>
</template>

<script>
import { listProductionTech } from '../../../api/erp/production'
import { qty, shortDate, errorText } from './mfg-helpers'

export default {
  name: 'ProductionTechList',
  data: () => ({
    loading: false,
    rows: [],
    total: 0,
    page: 1,
    summary: {},
    filters: { status: 'pending', keyword: '' },
    tabs: [
      { value: 'pending', label: '待工艺确认' },
      { value: 'released', label: '已下发车间' },
      { value: '', label: '全部工单' }
    ]
  }),
  created() {
    if (this.$route.query.status !== undefined) this.filters.status = String(this.$route.query.status)
    this.load()
  },
  methods: {
    qty,
    shortDate,
    async load() {
      this.loading = true
      try {
        const params = {
          page: this.page,
          per_page: 10,
          status: this.filters.status || undefined,
          keyword: this.filters.keyword || undefined
        }
        const { data } = await listProductionTech(params)
        this.rows = data.data || []
        this.total = data.total || 0
        this.summary = data.summary || {}
      } catch (e) {
        this.$message.error(errorText(e, '定制工艺工单加载失败'))
      } finally {
        this.loading = false
      }
    },
    search() {
      this.page = 1
      this.load()
    },
    resetFilters() {
      this.filters.keyword = ''
      this.search()
    },
    pickTab(status) {
      this.filters.status = status
      this.search()
    },
    tabCount(status) {
      const s = this.summary || {}
      return status ? (s[status] || 0) : (s.pending || 0) + (s.released || 0)
    },
    open(row) {
      this.$router.push(`/production/tech/${row.work_order_id}`)
    },
    productName(row) {
      return [row.product_name, row.sku_code].filter(Boolean).join(' ')
    }
  }
}
</script>

<style scoped>
.tech-list-page {
  min-height: calc(100vh - 52px);
  padding: 16px 20px 30px;
  background: #f8fafc;
  color: #1e293b;
  box-sizing: border-box;
}

/* 顶部页面标头 (.page-head) */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}
.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}
.head-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  background: #f0fdf4;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}
.head-title-wrap {
  display: flex;
  flex-direction: column;
  gap: 4px;
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
}
.head-tag {
  border-radius: 4px;
  font-weight: 500;
}
.sub-row {
  font-size: 13px;
  color: #64748b;
}
.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}
.btn-refresh {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}
.btn-refresh:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 主卡片容器 */
.main-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  overflow: hidden;
  box-sizing: border-box;
}

/* 状态选项卡 */
.status-tabs {
  display: flex;
  gap: 6px;
  padding: 10px 18px 0;
  border-bottom: 1px solid #edf1f5;
  flex-wrap: wrap;
}
.tab-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 38px;
  padding: 0 16px;
  border: 0;
  border-bottom: 2px solid transparent;
  background: transparent;
  color: #64748b;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
}
.tab-item:hover {
  color: #008b4b;
}
.tab-item.active {
  color: #008b4b;
  border-bottom-color: #008b4b;
  font-weight: 600;
}
.tab-badge {
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 10px;
  background: #f1f5f9;
  color: #64748b;
}
.tab-item.active .tab-badge {
  background: #f0fdf4;
  color: #008b4b;
}

/* 筛选工具栏 */
.filter-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 18px;
  border-bottom: 1px solid #f1f5f9;
  flex-wrap: wrap;
}
.search-input {
  width: 320px;
}
.btn-theme-search {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #fff !important;
}
.btn-theme-search:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}
.btn-theme-reset {
  border-color: #dcdfe6 !important;
  color: #4b5563 !important;
}
.btn-theme-reset:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 表格与字段 */
.table-wrap {
  padding: 4px 18px 0;
}
.custom-tech-table ::v-deep th {
  background: #f8fafc;
  color: #334155;
  font-weight: 600;
  font-size: 12px;
  padding: 10px 0;
}
.custom-tech-table ::v-deep td {
  padding: 10px 0;
  font-size: 13px;
  cursor: pointer;
}
.mono-code {
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
  font-size: 12px;
  color: #64748b;
}
.mono-sub {
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
  font-size: 12px;
  color: #64748b;
}
.prod-name {
  font-weight: 600;
  color: #0f172a;
  margin-top: 2px;
}
.qty-badge {
  color: #008b4b;
  margin-left: 4px;
}
.customer-text {
  font-size: 12px;
  color: #334155;
  margin-top: 2px;
}
.custom-desc {
  color: #475569;
  font-size: 12px;
  line-height: 1.6;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.progress-item {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  line-height: 1.6;
}
.text-success {
  color: #008b4b;
  font-weight: 500;
}
.text-warning {
  color: #f59e0b;
}
.text-muted {
  color: #94a3b8;
}
.urgent-tag {
  margin-left: 4px;
}
.sub-label {
  font-size: 11px;
  color: #64748b;
  margin-top: 2px;
}
.date-text {
  font-size: 12px;
  color: #334155;
}
.owner-text {
  font-size: 12px;
  color: #334155;
}
.action-btn-primary {
  color: #008b4b !important;
  font-weight: 500;
}
.action-btn-primary:hover {
  color: #00763f !important;
}

/* 分页器 */
.pagination-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 18px;
  border-top: 1px solid #edf1f5;
  flex-wrap: wrap;
  gap: 10px;
}
.total-text {
  font-size: 13px;
  color: #64748b;
}
.pagination-bar ::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #ffffff !important;
}
.pagination-bar ::v-deep .el-pagination.is-background .el-pager li:not(.disabled):hover {
  color: #008b4b !important;
}

/* 移动端卡片适配 */
.mobile-only {
  display: none;
  padding: 12px;
}
.empty-box {
  padding: 30px;
  text-align: center;
  color: #94a3b8;
}
.m-row {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 10px;
  display: grid;
  gap: 6px;
  background: #fff;
}
.m-head, .m-foot {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  flex-wrap: wrap;
}
.m-tags {
  display: flex;
  gap: 4px;
}
.m-foot {
  color: #64748b;
  border-top: 1px dashed #e2e8f0;
  padding-top: 6px;
  margin-top: 2px;
}

@media (max-width: 767px) {
  .desktop-only { display: none; }
  .mobile-only { display: block; }
  .search-input { width: 100%; }
}
</style>
