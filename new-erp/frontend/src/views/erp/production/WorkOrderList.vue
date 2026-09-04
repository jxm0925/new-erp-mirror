<template>
  <section class="production-page" v-loading="loading">
    <div class="page-heading">
      <div><p class="eyebrow">生产管理 / 工单管理</p><h1>工单管理</h1></div>
      <div class="heading-actions"><el-button v-if="$can('production.work_order.create')" @click="createFromDemand">销售需求建单</el-button><el-button v-if="$can('production.work_order.create')" type="success" @click="$router.push('/production/work-orders/create')">新建其他来源工单</el-button></div>
    </div>

    <section class="filter-card">
      <div class="filter-grid">
        <label><span>工单号 / 需求号</span><el-input v-model="filters.keyword" placeholder="请输入工单号 / 需求号" clearable /></label>
        <label><span>客户</span><el-input v-model="filters.customer" placeholder="请选择客户" clearable /></label>
        <label><span>产品 / SKU</span><el-input v-model="filters.product" placeholder="请输入产品名称 / SKU" clearable /></label>
        <label><span>生产地点 / 车间</span><el-input v-model="filters.production_location_name" placeholder="请输入生产地点 / 车间" clearable /></label>
        <label><span>负责人</span><el-select v-model="filters.responsible_user_legacy_id" placeholder="请选择负责人" clearable filterable><el-option v-for="user in productionUsers" :key="user.user_id" :label="displayUser(user)" :value="user.user_id" /></el-select></label>
        <label><span>计划日期</span><el-date-picker v-model="dateRange" type="daterange" value-format="yyyy-MM-dd" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期" /></label>
        <label><span>状态</span><el-select v-model="filters.status" placeholder="请选择状态" clearable><el-option v-for="item in statuses" :key="item.value" :label="item.label" :value="item.value" /></el-select></label>
        <label><span>来源类型</span><el-select v-model="filters.source_type" placeholder="全部来源" clearable><el-option v-for="item in sourceTypes" :key="item.value" :label="item.label" :value="item.value" /></el-select></label>
      </div>
      <div class="filter-actions"><el-button @click="reset">重置</el-button><el-button type="success" @click="search">查询</el-button></div>
    </section>

    <p class="result-count">共 {{ total }} 条</p>
    <section class="table-card">
      <el-table :data="rows" border>
        <el-table-column label="工单号 / 来源" min-width="190"><template slot-scope="scope"><button class="link-button" @click="open(scope.row)">{{ scope.row.work_order_no }}</button><small>{{ scope.row.source_type_label }} · {{ scope.row.source && (scope.row.source.no || scope.row.source.demand_no) || '-' }}</small></template></el-table-column>
        <el-table-column label="客户" min-width="155"><template slot-scope="scope">{{ scope.row.source && scope.row.source.customer || '-' }}</template></el-table-column>
        <el-table-column label="产品 / SKU" min-width="190"><template slot-scope="scope"><span>{{ scope.row.product && scope.row.product.name || '-' }}</span><small>{{ scope.row.product && scope.row.product.sku || '-' }}</small></template></el-table-column>
        <el-table-column label="计划量" width="105"><template slot-scope="scope">{{ number(scope.row.quantity && scope.row.quantity.target_qty) }} {{ scope.row.quantity && scope.row.quantity.unit_name || '' }}</template></el-table-column>
        <el-table-column label="计划日期" width="125"><template slot-scope="scope">{{ scope.row.plan && scope.row.plan.planned_date || '-' }}</template></el-table-column>
        <el-table-column label="状态" width="105"><template slot-scope="scope"><el-tag size="mini" :type="statusType(scope.row.status)">{{ statusText(scope.row.status) }}</el-tag></template></el-table-column>
        <el-table-column label="负责人" width="120"><template slot-scope="scope">{{ scope.row.responsible_user && scope.row.responsible_user.display_name || '待分配' }}</template></el-table-column>
        <el-table-column label="操作" min-width="250" :fixed="actionColumnFixed"><template slot-scope="scope"><el-button type="text" @click="open(scope.row)">查看</el-button><el-button v-if="scope.row.actions && scope.row.actions.edit" type="text" @click="open(scope.row, 'edit')">编辑</el-button><el-button v-if="scope.row.actions && scope.row.actions.submit" type="text" @click="submit(scope.row)">提交</el-button><el-button v-if="canRelease(scope.row)" type="text" @click="evaluateAndPublish(scope.row)">发布</el-button><el-button v-if="scope.row.actions && scope.row.actions.cancel" type="text" class="danger-link" @click="cancel(scope.row)">取消</el-button></template></el-table-column>
      </el-table>
      <div class="table-footer"><span>共 {{ total }} 条</span><el-pagination background layout="sizes, prev, pager, next, jumper" :current-page="page" :page-size="perPage" :page-sizes="[10, 20, 50]" :total="total" @current-change="changePage" @size-change="changeSize" /></div>
    </section>
  </section>
</template>

<script>
import { listWorkOrders, submitWorkOrder, getWorkOrderReleaseGate, publishWorkOrder, cancelWorkOrder } from '../../../api/erp/production'
import { listUsers } from '../../../api/erp/rbac'

export default {
  name: 'WorkOrderList',
  data: () => ({
    loading: false,
    rows: [],
    total: 0,
    page: 1,
    perPage: 20,
    dateRange: [],
    productionUsers: [],
    filters: { keyword: '', customer: '', product: '', production_location_name: '', responsible_user_legacy_id: '', status: '', source_type: '' },
    sourceTypes: [{value:'sales_order',label:'销售订单'},{value:'production_plan',label:'生产计划'},{value:'trial',label:'试制'},{value:'stock_prebuild',label:'备货'}],
    viewportWidth: window.innerWidth,
    statuses: [
      { value: 'DRAFT', label: '草稿' },
      { value: 'WAIT_RELEASE', label: '待发布' },
      { value: 'RELEASED', label: '已发布' },
      { value: 'CANCELLED', label: '已取消' }
    ]
  }),
  computed: {
    actionColumnFixed() { return this.viewportWidth > 767 ? 'right' : false }
  },
  created() { this.fetchRows(); this.fetchProductionUsers() },
  mounted() { window.addEventListener('resize', this.updateViewportWidth) },
  beforeDestroy() { window.removeEventListener('resize', this.updateViewportWidth) },
  methods: {
    updateViewportWidth() { this.viewportWidth = window.innerWidth },
    async fetchRows() {
      this.loading = true
      try {
        const response = await listWorkOrders({ ...this.filters, page: this.page, per_page: this.perPage, date_from: this.dateRange[0], date_to: this.dateRange[1] })
        this.rows = response.data.data || []
        this.total = response.data.total || 0
      } catch (error) {
        this.$message.error(error.userMessage || '工单加载失败')
      } finally { this.loading = false }
    },
    async fetchProductionUsers() {
      try {
        const response = await listUsers({ scope: 'production', status: 'normal', page: 1, per_page: 100 })
        this.productionUsers = response.data.data || response.data || []
      } catch (error) { this.productionUsers = [] }
    },
    search() { this.page = 1; this.fetchRows() },
    reset() { this.filters = { keyword: '', customer: '', product: '', production_location_name: '', responsible_user_legacy_id: '', status: '', source_type: '' }; this.dateRange = []; this.search() },
    changePage(page) { this.page = page; this.fetchRows() },
    changeSize(size) { this.perPage = size; this.page = 1; this.fetchRows() },
    open(row, mode) { this.$router.push({ path: `/production/work-orders/${row.id}`, query: mode ? { mode } : undefined }) },
    createFromDemand() { this.$router.push('/production/demands') },
    canRelease(row) { return row.status === 'WAIT_RELEASE' && this.$can('production.work_order.gate.view') && this.$can('production.work_order.publish') },
    async submit(row) {
      try {
        await submitWorkOrder(row.id, { client_command_id: `wo04-submit-${row.id}-${Date.now()}`, expected_version: row.business_version, reason: '提交工单草稿' })
        this.$message.success('已提交，等待发布')
        this.fetchRows()
      } catch (error) { this.$message.error(error.userMessage || '提交失败') }
    },
    async evaluateAndPublish(row) {
      this.loading = true
      try {
        const gateResponse = await getWorkOrderReleaseGate(row.id)
        const gate = gateResponse.data.data
        if (!gate.allowed) {
          this.$message.warning(`发布检查未通过：${(gate.blockers || []).map(item => item.message).join('；')}`)
          this.open(row)
          return
        }
        const result = await this.$prompt('请输入本次发布原因，发布后 BOM 和物料需求不可更改。', '发布工单', { confirmButtonText: '确认发布', cancelButtonText: '取消', inputPattern: /\S+/, inputErrorMessage: '发布原因不能为空' })
        await publishWorkOrder(row.id, { client_command_id: `wo04-publish-${row.id}-${Date.now()}`, expected_version: row.business_version, reason: result.value.trim() })
        this.$message.success('工单已发布，BOM 与物料需求已锁定')
        this.fetchRows()
      } catch (error) {
        if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '发布失败')
      } finally { this.loading = false }
    },
    async cancel(row) {
      try {
        await cancelWorkOrder(row.id, { client_command_id: `wo04-cancel-${row.id}-${Date.now()}`, expected_version: row.business_version })
        this.$message.success('工单已取消')
        this.fetchRows()
      } catch (error) { this.$message.error(error.userMessage || '取消失败') }
    },
    displayUser(user) { return user.display_name || '未命名用户' },
    number(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 4 }) },
    statusText(status) { return ({ DRAFT: '草稿', WAIT_RELEASE: '待发布', RELEASED: '已发布', CANCELLED: '已取消' })[status] || status },
    statusType(status) { return status === 'CANCELLED' ? 'danger' : status === 'WAIT_RELEASE' ? 'warning' : status === 'RELEASED' ? 'success' : '' }
  }
}
</script>

<style scoped>
.production-page{padding:24px 28px;background:#f7f9fb;min-height:calc(100vh - 54px);color:#27384e}.page-heading{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:18px}.page-heading h1{margin:3px 0 0;font-size:22px;color:#152941}.eyebrow{margin:0;color:#008b4b;font-weight:600}.filter-card,.table-card{background:#fff;border:1px solid #e5eaf0;border-radius:5px}.filter-card{padding:19px 20px 14px;margin-bottom:15px}.filter-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:17px 30px}.filter-grid label>span{display:block;color:#526276;font-size:12px;font-weight:600;margin-bottom:7px}.filter-grid .el-input,.filter-grid .el-select,.filter-grid .el-date-editor{width:100%}.filter-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}.filter-actions .el-button--success,.page-heading .el-button--success{background:#008b4b;border-color:#008b4b}.result-count{color:#596b81;margin:5px 0 11px}.table-card{padding:0 10px 11px}.table-card ::v-deep .el-table th{background:#f8fafc;color:#42536a}.table-card ::v-deep .el-table td{height:65px}.table-card small{display:block;color:#8390a0;font-size:11px}.link-button{display:block;border:0;padding:0;background:transparent;color:#1677d2;cursor:pointer;font-weight:600}.danger-link{color:#dc3d43}.table-footer{display:flex;justify-content:space-between;align-items:center;padding-top:13px;color:#67768a}.table-footer ::v-deep .el-pagination{padding:0}.table-footer ::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active{background:#008b4b}@media(max-width:1200px){.filter-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:767px){.production-page{padding:16px 12px}.page-heading{height:auto;min-height:112px;align-items:flex-start;flex-direction:column;justify-content:flex-start;gap:12px}.filter-card{padding:16px 14px}.filter-grid{grid-template-columns:1fr;gap:14px}.table-card{overflow:hidden}.table-footer{align-items:flex-end;flex-direction:column;gap:8px}.table-footer ::v-deep .el-pagination{max-width:100%;overflow-x:auto}}
</style>
