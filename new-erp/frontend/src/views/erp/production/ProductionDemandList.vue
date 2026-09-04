<template>
  <section class="production-page demand-list-page">
    <div class="page-heading">
      <div>
        <div class="page-crumb"><span>生产管理</span><b>/</b><strong>生产需求</strong></div>
        <h1>生产需求</h1>
      </div>
      <div class="heading-actions"><el-button @click="$router.back()">返回</el-button><el-button v-if="canCreateFromCurrentPage" type="success" @click="openFirstDemand">创建工单草稿</el-button></div>
    </div>

    <section class="filter-card">
      <div class="filter-grid">
        <label><span>需求编号</span><el-input v-model="filters.keyword" placeholder="请输入需求编号" clearable /></label>
        <label><span>销售订单</span><el-input v-model="filters.sales_order_no" placeholder="请输入销售订单" clearable /></label>
        <label><span>客户</span><el-input v-model="filters.customer" placeholder="请选择客户" clearable /></label>
        <label><span>产品 / SKU</span><el-input v-model="filters.product" placeholder="请输入产品名称 / SKU" clearable /></label>
        <label><span>需求状态</span><el-select v-model="filters.status" placeholder="请选择需求状态" clearable><el-option label="待确认" value="pending" /><el-option label="已确认" value="confirmed" /><el-option label="可拆分" value="ready" /></el-select></label>
        <label><span>计划日期</span><el-date-picker v-model="dateRange" type="daterange" value-format="yyyy-MM-dd" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期" /></label>
        <label><span>计划量范围</span><div class="range-input"><el-input v-model="filters.quantity_min" placeholder="最小值" /><b>~</b><el-input v-model="filters.quantity_max" placeholder="最大值" /></div></label>
        <label><span>交期范围</span><el-date-picker v-model="deliveryDateRange" type="daterange" value-format="yyyy-MM-dd" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期" /></label>
        <label v-if="expanded"><span>生产负责人</span><el-select v-model="filters.responsible_user_legacy_id" placeholder="请选择负责人" clearable filterable><el-option v-for="user in productionUsers" :key="user.user_id" :label="displayUser(user)" :value="user.user_id" /></el-select></label>
      </div>
      <div class="filter-actions"><el-button @click="reset">重置</el-button><el-button type="success" @click="search">查询</el-button><el-button type="text" @click="expanded = !expanded">{{ expanded ? '收起' : '展开' }} <i :class="expanded ? 'el-icon-arrow-up' : 'el-icon-arrow-down'" /></el-button></div>
    </section>

    <section class="table-card">
        <el-table v-loading="loading" :data="rows" row-key="id" height="663" @row-click="openDemand">
        <el-table-column label="需求号" width="148"><template slot-scope="{ row }"><button class="link-button" @click.stop="openDemand(row)">{{ row.demand_no || `PD-${row.id}` }}</button></template></el-table-column>
        <el-table-column label="客户" width="147"><template slot-scope="{ row }"><span class="customer-name">{{ row.customer || '-' }}</span></template></el-table-column>
        <el-table-column label="产品 / SKU" width="146"><template slot-scope="{ row }"><div>{{ row.product && row.product.name || '-' }}</div><small>{{ productSpecification(row) }}</small><small>{{ row.product && row.product.sku || '-' }}</small></template></el-table-column>
        <el-table-column label="计划量" width="112" align="center"><template slot-scope="{ row }">{{ number(row.quantity && row.quantity.production_qty) }} {{ row.quantity && row.quantity.unit_name || '' }}</template></el-table-column>
        <el-table-column label="完成进度" width="217"><template slot-scope="{ row }"><div class="progress-top"><span>{{ number(row.quantity && row.quantity.consumed_qty) }} / {{ number(row.quantity && row.quantity.production_qty) }}</span><em>{{ percent(row) }}%</em></div><el-progress :percentage="percent(row)" :show-text="false" :stroke-width="6" /><small>已排产 {{ number(row.quantity && row.quantity.allocated_qty) }} ｜ 未排产 {{ number(row.quantity && row.quantity.remaining_qty) }}</small></template></el-table-column>
        <el-table-column label="计划日期" width="120"><template slot-scope="{ row }">{{ row.required_delivery_date || '-' }}</template></el-table-column>
        <el-table-column label="状态" width="127" align="center"><template slot-scope="{ row }"><el-tag :type="statusType(row.status)" size="small">{{ statusText(row.status) }}</el-tag></template></el-table-column>
        <el-table-column label="生产负责人" width="101"><template slot-scope="{ row }">{{ row.production_responsible_user && row.production_responsible_user.display_name || '待分配' }}</template></el-table-column>
        <el-table-column label="操作" width="126" align="center"><template slot-scope="{ row }"><el-button type="text" @click.stop="openDemand(row)">查看</el-button><el-button v-if="row.actions && row.actions.create_work_order" type="text" @click.stop="openDemand(row, true)">拆单</el-button></template></el-table-column>
      </el-table>
      <div class="table-footer"><span>共 {{ total }} 条</span><el-pagination background layout="sizes, prev, pager, next, jumper" :current-page="page" :page-size="perPage" :page-sizes="[10, 20, 50]" :total="total" @current-change="changePage" @size-change="changeSize" /></div>
    </section>
  </section>
</template>

<script>
import { listProductionDemands } from '../../../api/erp/production'
import { listUsers } from '../../../api/erp/rbac'

export default {
  name: 'ProductionDemandList',
  data: () => ({
    loading: false,
    expanded: false,
    rows: [],
    total: 0,
    page: 1,
    perPage: 10,
    dateRange: [],
    deliveryDateRange: [],
    productionUsers: [],
    filters: { keyword: '', sales_order_no: '', customer: '', product: '', status: '', quantity_min: '', quantity_max: '', responsible_user_legacy_id: '' }
  }),
  computed: {
    canCreateFromCurrentPage() { return this.rows.some(row => row.actions && row.actions.create_work_order === true) }
  },
  created() { this.fetchRows(); this.fetchProductionUsers() },
  methods: {
    async fetchRows() {
      this.loading = true
      try {
        const params = { ...this.filters, page: this.page, per_page: this.perPage, date_from: this.dateRange[0], date_to: this.dateRange[1], delivery_date_from: this.deliveryDateRange[0], delivery_date_to: this.deliveryDateRange[1] }
        const response = await listProductionDemands(params)
        this.rows = response.data.data || []
        this.total = response.data.total || 0
      } catch (error) { this.rows = []; this.total = 0; this.$message.error(error.userMessage || '生产需求加载失败') } finally { this.loading = false }
    },
    async fetchProductionUsers() {
      try {
        const response = await listUsers({ scope: 'production', status: 'normal', page: 1, per_page: 100 })
        this.productionUsers = response.data.data || response.data || []
      } catch (error) { this.productionUsers = [] }
    },
    search() { this.page = 1; this.fetchRows() },
    reset() { this.filters = { keyword: '', sales_order_no: '', customer: '', product: '', status: '', quantity_min: '', quantity_max: '', responsible_user_legacy_id: '' }; this.dateRange = []; this.deliveryDateRange = []; this.search() },
    changePage(page) { this.page = page; this.fetchRows() },
    changeSize(size) { this.perPage = size; this.page = 1; this.fetchRows() },
    openDemand(row, drawer = false) { this.$router.push({ path: `/production/demands/${row.id}`, query: drawer ? { draft: '1' } : undefined }) },
    openFirstDemand() { const row = this.rows.find(item => item && item.actions && item.actions.create_work_order === true); if (row) this.openDemand(row, true); else this.$message.info('暂无可拆分的生产需求') },
    number(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 4 }) },
    productSpecification(row) { const product = row.product || {}; return product.specification || product.item_name || '-' },
    displayUser(user) { return user.display_name || '未命名用户' },
    percent(row) { const q = row.quantity || {}; return q.production_qty > 0 ? Math.min(100, Math.round((q.consumed_qty || 0) / q.production_qty * 1000) / 10) : 0 },
    statusText(status) { return ({ confirmed: '资金已放行', ready: '资金已放行', pending: '待确认', blocked: '资金阻断' })[status] || status || '待确认' },
    statusType(status) { return status === 'blocked' ? 'danger' : status === 'pending' ? 'warning' : 'success' }
  }
}
</script>

<style scoped>
.production-page { padding: 14px 20px 0 28px; background: #fff; min-height: 100vh; color: #243348; }
.page-heading { display: flex; justify-content: space-between; align-items: flex-start; min-height: 101px; margin-bottom: 0; }
 .page-heading h1 { font-size: 24px; margin: 31px 0 0; color: #14253b; letter-spacing: .5px; }.page-crumb { display:flex; align-items:center; gap:14px; color:#17345b; font-size:14px; font-weight:600; }.page-crumb b { color:#9aaabd; font-weight:400; }.page-crumb strong { font-weight:600; }.heading-actions { display:flex; gap:4px; }.heading-actions .el-button { height:34px; margin-top:0; padding:0 20px; }.heading-actions .el-button--success { min-width:128px; }
    .filter-card, .table-card { background: #fff; border: 1px solid #e8ecf2; border-radius: 5px; box-shadow: none; }.filter-card { position:relative; padding: 20px 21px 21px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(25,44,78,.035); border-color: #edf0f7; }.demand-list-page .table-card { border-color: #f4f6fa; }
    .filter-grid { display: grid; grid-template-columns: 229px 218px 224px 206px 192px; justify-content:space-between; row-gap:27px; }.filter-grid label { min-width: 0; }.filter-grid label > span { display: block; color: #526276; font-size: 13px; line-height: 18px; font-weight: 600; margin: 0 0 7px; }.filter-grid .el-input, .filter-grid .el-select, .filter-grid .el-date-editor { width: 100%; }.filter-grid ::v-deep .el-input__inner { height: 36px; line-height: 36px; border-color: #eaedf4; }.filter-grid ::v-deep .el-input__icon { line-height: 36px; }.range-input { display:flex; align-items:center; gap:0; height:36px; border:1px solid #eaedf4; border-radius:4px; overflow:hidden; background:#fff; }.range-input .el-input { flex:1; min-width:0; }.range-input ::v-deep .el-input__inner { height:34px; line-height:34px; border:0; border-radius:0; padding:0 12px; }.range-input b { flex:0 0 24px; color:#9aa5b2; font-weight:400; text-align:center; }.filter-actions { position:absolute; right:26px; bottom:27px; display: flex; justify-content: flex-end; align-items: center; gap: 18px; margin:0; }.filter-actions .el-button { height:36px; min-width:84px; padding:0 18px; margin:0; }.filter-actions .el-button--text { min-width:62px; padding:0; }.filter-actions .el-button--success, .page-heading .el-button--success { background: #008b4b; border-color: #008b4b; }
    .page-heading .el-button--success { background-color: #007a3d; }
    .page-heading .el-button:not(.el-button--success) { border-color: #e4eaf2; }
    .filter-actions .el-button--success { background-color: #007a3d; }
    .filter-actions > .el-button:first-child { border-color: #e4eaf2; }
.demand-list-page .link-button { white-space: nowrap; max-width: 100%; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
 .page-heading h1 { letter-spacing: 0; }
 .page-crumb { transform: translateY(3px); font-size: 14px; }
 .demand-list-page .page-heading h1 { transform: translateY(1px); }
 .table-card ::v-deep .el-table td.el-table__cell { border-bottom-color: #f4f6fa; }
 .table-card ::v-deep .el-table th.el-table__cell { border-bottom-color: #f5f7fa; }
 .table-card ::v-deep .el-table td.el-table__cell,
 .table-card ::v-deep .el-table th.el-table__cell { border-right: 1px solid #f7f8fa; }
   .table-card ::v-deep .el-table td:last-child .el-button + .el-button { margin-left: 22px; }
  .table-card ::v-deep .el-table td:last-child .el-button { transform: translate(0, -2px); }
  .table-card ::v-deep .el-table__body tr:nth-child(8) td:last-child .el-button { transform: translate(2px, -5px); }
  .table-card ::v-deep .el-table__body tr:nth-child(2) td:last-child .el-button,
  .table-card ::v-deep .el-table__body tr:nth-child(4) td:last-child .el-button,
  .table-card ::v-deep .el-table__body tr:nth-child(6) td:last-child .el-button { transform: translate(2px, -1px); }
  .table-card ::v-deep .el-progress-bar__outer { background-color: #fefefe !important; }
  .table-card ::v-deep .el-table__body td:nth-child(7) .el-tag { padding-left: 10px; padding-right: 10px; }
  .table-card ::v-deep .el-table__body td:nth-child(7) .el-tag { transform: translateY(-1px); }
  .filter-grid ::v-deep .el-date-editor { position: relative; }
   .filter-grid ::v-deep .el-date-editor .el-range__icon { position: absolute; right: 5px; z-index: 2; }
 .filter-grid ::v-deep .el-date-editor .el-range__close-icon { display: none; }
 .filter-grid > label:nth-child(n+6) { transform: translateY(1px); }
 .filter-actions .el-button--text { transform: translateX(3px); }
 .range-input b { flex-basis: 16px; }
 .filter-card { box-shadow: 0 1px 3px rgba(25,44,78,.08); }
  .table-card ::v-deep .el-table { font-size: 14px; }
  .table-card ::v-deep .el-table th { height: 42px; padding: 0; background: #f7f8fa; color: #42536a; font-weight: 600; }
  .table-card ::v-deep .el-table th:nth-child(3) .cell { transform: translateX(-4px); }
  .table-card ::v-deep .el-table th:nth-child(7) .cell { transform: translateX(4px); }
  .table-card ::v-deep .el-table th:nth-child(8) .cell { transform: translateX(2px); }
  .table-card ::v-deep .el-table th:nth-child(2) .cell { transform: translateX(1px); }
  .table-card ::v-deep .el-table th:nth-child(6) .cell { transform: translateX(2px); }
  .table-card ::v-deep .el-table td { height: 73px; padding: 0; }
  .table-card ::v-deep .el-table .cell { padding: 0 18px; }
  .table-card ::v-deep .el-table__body tr:nth-child(1) td { height: 80px; }
  .table-card ::v-deep .el-table__body tr:nth-child(2) td { height: 79px; }
  .table-card ::v-deep .el-table__body tr:nth-child(3) td { height: 78px; }
  .table-card ::v-deep .el-table__body tr:nth-child(6) td { height: 74px; }
  .table-card ::v-deep .el-table__body-wrapper::-webkit-scrollbar { width: 0; height: 12px; }
  .table-card ::v-deep .el-table__body-wrapper::-webkit-scrollbar-track { background: #f4f6f8; }
  .table-card ::v-deep .el-table__body-wrapper::-webkit-scrollbar-thumb { background: #c7cfda; border-radius: 6px; }
  .link-button { border: 0; background: transparent; color: #1677d2; padding: 0; cursor: pointer; font-weight: 600; }
  .customer-name { font-weight: 400; }
  .table-card small { display: block; color: #8290a1; font-size: 11px; line-height: 18px; }
  .progress-top { display: flex; justify-content: space-between; margin-bottom: 5px; }
  .progress-top em { color: #008b4b; font-style: normal; }
  .table-footer { height: 66px; display: flex; justify-content: flex-end; align-items: center; gap: 24px; padding: 0 24px; color: #67768a; }
  .table-footer ::v-deep .el-pagination { padding: 0; transform: translate(-10px, -3px); }
  .table-footer ::v-deep .el-pagination .el-pagination__sizes .el-select { transform: translateX(7px); }
  .table-footer ::v-deep .el-pagination .el-pagination__sizes .el-select .el-input__inner { height: 30px; }
  .table-footer ::v-deep .el-pagination .el-select .el-input { width: 100px; }
  .table-footer ::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active { background: #008b4b; }
@media (max-width: 1200px) { .filter-grid { grid-template-columns: repeat(3, minmax(150px, 1fr)); } }
@media (max-width: 767px) {
  .production-page { padding: 12px 12px 0; }
  .page-heading { min-height: 82px; }
  .page-heading h1 { margin-top: 20px; font-size: 20px; }
  .heading-actions { gap: 8px; }
  .heading-actions .el-button { padding: 0 12px; }
  .heading-actions .el-button--success { min-width: 0; }
  .filter-card { padding: 16px 14px; }
  .filter-grid { grid-template-columns: 1fr; row-gap: 14px; }
  .filter-actions { position: static; margin-top: 16px; gap: 8px; }
  .table-card { overflow: hidden; }
  .table-footer { height: auto; min-height: 66px; padding: 12px; gap: 8px; flex-wrap: wrap; }
  .table-footer ::v-deep .el-pagination { max-width: 100%; overflow-x: auto; transform: none; }
}
</style>
