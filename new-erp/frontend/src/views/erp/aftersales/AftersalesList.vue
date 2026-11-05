<template>
  <section class="as-page">
    <div class="page-head">
      <div>
        <p class="breadcrumb">销售管理&nbsp;&nbsp;/&nbsp;&nbsp;售后单</p>
        <h1>售后单</h1>
      </div>
      <div class="head-actions">
        <el-button v-if="$can('aftersales.create')" size="small" type="success" icon="el-icon-plus" @click="openCreate">新建售后单</el-button>
      </div>
    </div>

    <div class="filter-card">
      <label>售后单号/订单号<el-input v-model="query.keyword" clearable size="small" placeholder="售后单号 / 订单号" @keyup.enter.native="reload" /></label>
      <label>客户<el-input v-model="query.customer_keyword" clearable size="small" placeholder="客户名称 / 电话" @keyup.enter.native="reload" /></label>
      <label>序列号<el-input v-model="query.serial_no" clearable size="small" placeholder="设备序列号" @keyup.enter.native="reload" /></label>
      <label>问题类型<el-select v-model="query.issue_type" clearable size="small" placeholder="全部"><el-option v-for="t in issueTypes" :key="t.value" :label="t.label" :value="t.value" /></el-select></label>
      <label>处理方式<el-select v-model="query.action_type" clearable size="small" placeholder="全部"><el-option v-for="t in actionTypes" :key="t.value" :label="t.label" :value="t.value" /></el-select></label>
      <label>负责人<el-input v-model="query.owner" clearable size="small" placeholder="负责人" @keyup.enter.native="reload" /></label>
      <label class="date-field">创建日期<el-date-picker v-model="dateRange" size="small" type="daterange" value-format="yyyy-MM-dd" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期" /></label>
      <div class="filter-actions"><el-button size="small" type="success" @click="reload">查询</el-button><el-button size="small" @click="reset">重置</el-button></div>
    </div>

    <div class="table-card">
      <div class="stab">
        <span v-for="t in statusTabs" :key="t.value" :class="{ on: statusTab === t.value }" @click="statusTab = t.value; reload()">{{ t.label }} {{ countFor(t.value) }}</span>
      </div>
      <el-table v-loading="loading" :data="rows" border size="mini">
        <el-table-column prop="case_no" label="售后单号" width="160" />
        <el-table-column prop="sales_order_no" label="原订单" width="160" />
        <el-table-column prop="customer_name" label="客户" width="110" />
        <el-table-column label="问题类型" width="100"><template slot-scope="{ row }">{{ issueLabel(row.issue_type) }}</template></el-table-column>
        <el-table-column prop="description" label="问题描述" min-width="220"><template slot-scope="{ row }"><span class="as-description">{{ row.description || '—' }}</span></template></el-table-column>
        <el-table-column prop="items_summary" label="涉及商品" min-width="180" show-overflow-tooltip />
        <el-table-column label="处理" width="130"><template slot-scope="{ row }">
          <template v-if="row.action_types && row.action_types.length"><el-tag v-for="t in row.action_types" :key="t" size="mini" class="as-tag">{{ actionLabel(t) }}</el-tag></template>
          <span v-else class="muted">未定</span>
        </template></el-table-column>
        <el-table-column label="保修" width="90"><template slot-scope="{ row }">{{ warrantyLabel(row.warranty_status) }}</template></el-table-column>
        <el-table-column label="状态" width="90"><template slot-scope="{ row }"><el-tag size="mini" :type="statusTag(row.status)">{{ statusLabel(row.status) }}</el-tag></template></el-table-column>
        <el-table-column prop="owner_name" label="负责人" width="90" />
        <el-table-column label="创建时间" width="150"><template slot-scope="{ row }">{{ erpDateTime(row.created_at) }}</template></el-table-column>
        <el-table-column label="操作" width="140" fixed="right"><template slot-scope="{ row }">
          <el-button type="text" size="mini" @click="$router.push(`/sales/aftersales/${row.id}/detail`)">查看</el-button>
          <el-button v-if="row.status === 'pending' && $can('aftersales.handle')" type="text" size="mini" @click="accept(row)">受理</el-button>
        </template></el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination background layout="total, sizes, prev, pager, next, jumper" :total="total" :page-sizes="[10, 20, 50]" :page-size="query.per_page" :current-page.sync="query.page" @current-change="load" @size-change="size => { query.per_page = size; reload() }" />
      </div>
    </div>

    <aftersales-case-form ref="caseForm" :order="null" @saved="load" />
  </section>
</template>

<script>
import AftersalesCaseForm from './AftersalesCaseForm.vue'
import { listAftersalesCases, acceptAftersalesCase } from '@/api/erp/aftersales'
import { erpDateTime } from '@/utils/dateTime'

const ISSUE_TYPES = [
  { value: 'quality', label: '质量故障' }, { value: 'transport_damage', label: '运输破损' },
  { value: 'wrong_shipment', label: '错发漏发' }, { value: 'installation', label: '安装问题' },
  { value: 'consultation', label: '使用咨询' }, { value: 'other', label: '其他' }
]
export default {
  name: 'AftersalesList',
  components: { AftersalesCaseForm },
  data() {
    return {
      rows: [], total: 0, loading: false, statusTab: 'all', counts: {}, dateRange: [],
      query: { keyword: '', customer_keyword: '', serial_no: '', issue_type: '', action_type: '', owner: '', page: 1, per_page: 10 },
      issueTypes: ISSUE_TYPES,
      actionTypes: [{ value: 'refund', label: '退货退款' }, { value: 'exchange', label: '换货' }, { value: 'reship', label: '补发' }, { value: 'compensation', label: '赔付记录' }, { value: 'record', label: '仅记录' }],
      statusTabs: [
        { value: 'all', label: '全部' }, { value: 'pending', label: '待受理' }, { value: 'processing', label: '处理中' },
        { value: 'ready_to_close', label: '待关闭' }, { value: 'closed', label: '已关闭' }, { value: 'cancelled', label: '已取消' }
      ]
    }
  },
  created() { this.load() },
  methods: {
    erpDateTime,
    params() {
      const params = { ...this.query, status: this.statusTab }
      if (this.dateRange && this.dateRange.length === 2) { params.date_from = this.dateRange[0]; params.date_to = this.dateRange[1] }
      return params
    },
    async load() {
      this.loading = true
      try {
        const { data } = await listAftersalesCases(this.params())
        this.rows = data.data || []; this.total = data.meta?.total || 0; this.counts = data.meta?.status_counts || {}
      } finally { this.loading = false }
    },
    reload() { this.query.page = 1; this.load() },
    reset() { this.query = { keyword: '', customer_keyword: '', serial_no: '', issue_type: '', action_type: '', owner: '', page: 1, per_page: 10 }; this.dateRange = []; this.statusTab = 'all'; this.load() },
    countFor(v) { const n = v === 'all' ? this.counts.all : this.counts[v]; return n == null ? 0 : n },
    openCreate() { this.$refs.caseForm.open() },
    async accept(row) {
      try {
        await acceptAftersalesCase(row.id)
        this.$message.success('已受理'); this.load()
      } catch (e) { this.$message.error(e.response?.data?.message || '受理失败') }
    },
    issueLabel: v => (ISSUE_TYPES.find(t => t.value === v) || {}).label || v,
    actionLabel: v => ({ refund: '退货退款', exchange: '换货', repair: '返修', reship: '补发', compensation: '赔付记录', record: '仅记录' })[v] || v,
    warrantyLabel: v => ({ in_warranty: '保内', out_of_warranty: '保外', pending_inspection: '待检测' })[v] || v || '—',
    statusLabel: v => ({ pending: '待受理', processing: '处理中', ready_to_close: '待关闭', closed: '已关闭', cancelled: '已取消' })[v] || v,
    statusTag: v => ({ pending: 'info', processing: 'warning', ready_to_close: '', closed: 'info', cancelled: 'info' })[v] || 'info'
  }
}
</script>

<style scoped>
.as-page { padding: 16px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 12px; }
.breadcrumb { color: #8a94a6; font-size: 12px; margin: 0 0 4px; }
h1 { font-size: 20px; margin: 0; color: #1f2a37; }
.filter-card { background: #fff; border: 1px solid #e8ecf1; border-radius: 8px; padding: 12px; display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 12px; }
.filter-card label { display: flex; flex-direction: column; font-size: 12px; color: #5b6472; gap: 4px; }
.filter-card .el-input, .filter-card .el-select { width: 170px; }
.date-field .el-date-editor { width: 240px; }
.table-card { background: #fff; border: 1px solid #e8ecf1; border-radius: 8px; padding: 12px; }
.stab { display: flex; gap: 4px; margin-bottom: 10px; border-bottom: 1px solid #edf0f4; padding-bottom: 8px; }
.stab span { cursor: pointer; font-size: 13px; color: #5b6472; padding: 4px 10px; border-radius: 4px; }
.stab span.on { color: #008b4b; background: rgba(0, 139, 75, .08); font-weight: 600; }
.as-tag { margin-right: 4px; color: #7c3aed; border-color: #ddd6fe; background: #f5f3ff; }
.muted { color: #9aa3b2; }
.as-description {
  display: block;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.pager { display: flex; justify-content: flex-end; margin-top: 12px; }
</style>
