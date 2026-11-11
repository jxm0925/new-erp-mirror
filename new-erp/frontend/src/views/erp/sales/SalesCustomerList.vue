<template>
  <div class="customer-page">
    <header class="page-head">
      <span class="page-icon"><i class="el-icon-user" /></span>
      <h1>客户中心</h1>
      <div class="head-actions">
        <el-button v-if="!$can || $can('sales.customer.create')" size="small" type="success" icon="el-icon-plus" @click="openCreate">新增客户</el-button>
      </div>
    </header>

    <el-tabs v-model="query.tab" class="customer-tabs" @tab-click="search">
      <el-tab-pane :label="`我负责的 ${tabs.mine === undefined ? '' : tabs.mine}`" name="mine" />
      <el-tab-pane :label="`我协作的 ${tabs.collab === undefined ? '' : tabs.collab}`" name="collab" />
      <el-tab-pane :label="`全部可见 ${tabs.all === undefined ? '' : tabs.all}`" name="all" />
    </el-tabs>

    <section class="filter-card">
      <el-input v-model.trim="query.keyword" size="small" clearable placeholder="客户名称 / 编码 / 联系人 / 电话" class="kw" @keyup.enter.native="search" />
      <label>行业
        <el-select v-model="query.industry" size="small" clearable filterable allow-create placeholder="全部" @change="search">
          <el-option v-for="item in industryOptions" :key="item" :label="item" :value="item" />
        </el-select>
      </label>
      <label>客户等级
        <el-select v-model="query.customer_grade" size="small" clearable filterable allow-create placeholder="全部" @change="search">
          <el-option v-for="item in gradeOptions" :key="item" :label="item" :value="item" />
        </el-select>
      </label>
      <label>负责销售
        <el-select v-model="query.owner_name" size="small" clearable filterable placeholder="全部" @change="search">
          <el-option v-for="name in ownerOptions" :key="name" :label="name" :value="name" />
        </el-select>
      </label>
      <label>状态
        <el-select v-model="query.status" size="small" clearable placeholder="全部" @change="search">
          <el-option label="启用" value="enabled" />
          <el-option label="潜在" value="potential" />
          <el-option label="停用" value="disabled" />
          <el-option label="黑名单" value="blacklisted" />
        </el-select>
      </label>
      <el-button size="small" type="success" icon="el-icon-search" @click="search">查询</el-button>
      <el-button size="small" icon="el-icon-refresh" @click="reset">重置</el-button>
    </section>

    <section class="table-card">
      <el-table v-loading="loading" :data="rows" size="small">
        <el-table-column label="客户名称 · 背景" min-width="210">
          <template slot-scope="{row}">
            <el-button type="text" class="name-link" @click="openDetail(row)">{{ row.customer_name }}</el-button>
            <small class="cell-sub">{{ row.customer_code }} · {{ row.company_background || '未填写背景' }}</small>
          </template>
        </el-table-column>
        <el-table-column label="类型 · 等级 · 行业" min-width="130">
          <template slot-scope="{row}">
            <b>{{ row.customer_kind === 'enterprise' ? '企业' : '个人' }} · {{ row.customer_grade || '未填写' }}</b>
            <small class="cell-sub">{{ row.industry || '未填写' }}</small>
          </template>
        </el-table-column>
        <el-table-column label="年采购力 · 业务线" min-width="140">
          <template slot-scope="{row}">
            <b>{{ row.purchasing_power || '未填写' }}</b>
            <small class="cell-sub">{{ (row.business_lines || []).join('、') || '未填写' }}</small>
          </template>
        </el-table-column>
        <el-table-column label="默认联系人" min-width="140">
          <template slot-scope="{row}">
            <b>{{ defaultContact(row).contact_name || row.contact_name || '—' }}<span v-if="defaultContact(row).position" class="muted">（{{ defaultContact(row).position }}）</span></b>
            <small class="cell-sub">{{ maskPhone(defaultContact(row).mobile || defaultContact(row).phone || row.contact_phone) }}</small>
          </template>
        </el-table-column>
        <el-table-column label="负责销售" min-width="120">
          <template slot-scope="{row}">
            <b>{{ row.owner_name || '暂无' }}</b>
            <small class="cell-sub">{{ row.owner_department_name || (row.owner_name ? '' : '首次使用自动归属') }}</small>
          </template>
        </el-table-column>
        <el-table-column v-if="!$can || $can('crm.lead.view')" label="线索所在客户池" min-width="150">
          <template slot-scope="{row}"><el-tag v-for="pool in row.customer_pools || []" :key="pool" size="mini" type="success" class="pool-tag">{{ pool }}</el-tag><span v-if="!(row.customer_pools || []).length">—</span></template>
        </el-table-column>
        <el-table-column label="最近联系 · 下次计划" min-width="140">
          <template slot-scope="{row}">
            <b>{{ date(row.last_contact_at) }}</b>
            <small :class="['cell-sub', overdue(row) ? 'red' : '']">{{ row.next_contact_at ? date(row.next_contact_at) + (overdue(row) ? ' 已逾期' : '') : '—' }}</small>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template slot-scope="{row}"><span :class="['status-dot', row.status]" />{{ statusText(row.status) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="210" fixed="right">
          <template slot-scope="{row}">
            <el-button type="text" size="mini" icon="el-icon-view" @click="openDetail(row)">查看</el-button>
            <el-button v-if="row.abilities && row.abilities.follow && (!$can || $can('crm.lead.follow'))" type="text" size="mini" icon="el-icon-chat-line-round" @click="openFollowup(row)">跟进</el-button>
            <el-button v-if="row.relation && (!$can || $can('sales.customer.edit'))" type="text" size="mini" icon="el-icon-edit" @click="openEdit(row)">编辑</el-button>
          </template>
        </el-table-column>
        <template slot="empty">
          <div class="empty">
            <i class="el-icon-office-building" />
            <b>{{ query.tab === 'mine' ? '暂无你负责的客户' : query.tab === 'collab' ? '暂无协作客户' : '暂无可见客户' }}</b>
          </div>
        </template>
      </el-table>
      <footer class="pager">
        <span>共 {{ total }} 位客户</span>
        <el-pagination background layout="sizes, prev, pager, next, jumper" :current-page="query.page" :page-size="query.per_page"
                       :page-sizes="[10, 20, 50, 100]" :total="total" @size-change="changeSize" @current-change="changePage" />
      </footer>
    </section>

    <customer-drawer ref="customerDrawer" @saved="handleCustomerSaved" />
    <customer-detail-dialog ref="detail" @edit="openEdit" @changed="load" />
  </div>
</template>

<script>
import { listSalesCustomers } from '@/api/erp/sales'
import CustomerDrawer from '@/components/sales/CustomerDrawer.vue'
import CustomerDetailDialog from '@/components/sales/CustomerDetailDialog.vue'

const blankQuery = (perPage = 10, tab = 'mine') => ({ tab, keyword: '', industry: '', customer_grade: '', owner_name: '', status: '', page: 1, per_page: perPage })

export default {
  name: 'SalesCustomerList',
  components: { CustomerDrawer, CustomerDetailDialog },
  data: () => ({
    rows: [],
    total: 0,
    tabs: {},
    ownerOptions: [],
    loading: false,
    query: blankQuery(),
    gradeOptions: ['A级', 'B级', 'C级', 'D级'],
    industryOptions: ['水质处理', '连锁洗衣', '外贸出口', '企业自用', '政府单位', '农牧养殖', '商贸公司', '建筑施工', '加工配套', '未知']
  }),
  created() {
    this.load()
  },
  methods: {
    async openFollowup(row) {
      await this.$refs.detail.open(row.id, 'followups')
      await this.$nextTick()
      if (this.$refs.detail.customerId === row.id && this.$refs.detail.can('follow')) this.$refs.detail.openFollowup()
    },
    statusText(status) {
      return ({ enabled: '启用', potential: '潜在', disabled: '停用', blacklisted: '黑名单' })[status] || '—'
    },
    defaultContact(row) {
      return (row.contacts || []).find(item => item.is_default) || (row.contacts || [])[0] || {}
    },
    maskPhone(phone) {
      const value = String(phone || '').trim()
      return value.length >= 7 ? `${value.slice(0, 3)}****${value.slice(-4)}` : (value || '—')
    },
    date(value) {
      return value ? String(value).slice(0, 10) : '—'
    },
    overdue(row) {
      return !!row.next_contact_at && new Date(row.next_contact_at) < new Date(new Date().toDateString())
    },
    async load() {
      this.loading = true
      try {
        const { data } = await listSalesCustomers(this.query)
        this.rows = data.data || []
        this.total = data.total || 0
        this.tabs = data.tabs || {}
        this.ownerOptions = data.owner_options || []
      } catch (error) {
        this.$message.error((error.response && error.response.data && error.response.data.message) || '客户列表加载失败')
      } finally {
        this.loading = false
      }
    },
    search() {
      this.query.page = 1
      this.load()
    },
    reset() {
      this.query = blankQuery(this.query.per_page, this.query.tab)
      this.load()
    },
    changePage(page) {
      this.query.page = page
      this.load()
    },
    changeSize(size) {
      this.query.per_page = size
      this.query.page = 1
      this.load()
    },
    openCreate() {
      this.$refs.customerDrawer.openCreate('individual')
    },
    openEdit(row) {
      this.$refs.customerDrawer.openEdit(row.id)
    },
    openDetail(row) {
      this.$refs.detail.open(row.id)
    },
    async handleCustomerSaved(customer) {
      await this.load()
      if (customer && customer.id && this.$refs.detail.visible) this.$refs.detail.load(customer.id)
    }
  }
}
</script>

<style scoped>
.pool-tag {
  margin: 2px 4px 2px 0;
}
.customer-page { min-height: calc(100vh - 52px); padding: 16px 20px; background: #f8fafc; box-sizing: border-box; display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.page-head { display: flex; align-items: center; gap: 12px; }
.page-icon { width: 40px; height: 40px; border-radius: 10px; background: #f0fdf4; color: #008b4b; display: grid; place-items: center; font-size: 20px; }
.page-head h1 { margin: 0; font-size: 20px; color: #1e293b; }
.head-actions { margin-left: auto; display: flex; gap: 10px; }
.customer-tabs :deep(.el-tabs__header) { margin: 0; }
.customer-tabs :deep(.el-tabs__item.is-active), .customer-tabs :deep(.el-tabs__item:hover) { color: #008b4b; }
.customer-tabs :deep(.el-tabs__active-bar) { background-color: #008b4b; }
.filter-card { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 12px 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; }
.filter-card .kw { width: 260px; }
.filter-card label { display: flex; align-items: center; gap: 6px; color: #475569; font-size: 13px; }
.filter-card label .el-select { width: 130px; }
.table-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; min-width: 0; }
.table-card :deep(.el-table th.el-table__cell) { background: #f8fafc; color: #475569; font-weight: 600; }
.name-link { padding: 0; font-weight: 600; color: #008b4b; }
.cell-sub { display: block; margin-top: 2px; color: #64748b; font-size: 12px; font-weight: 400; word-break: break-word; }
.cell-sub.red { color: #dc2626; }
.muted { color: #94a3b8; font-weight: 400; }
.status-dot { display: inline-block; width: 6px; height: 6px; margin-right: 5px; border-radius: 50%; background: #9aa4af; }
.status-dot.enabled { background: #00a459; }
.status-dot.potential { background: #f19b26; }
.status-dot.blacklisted { background: #e23e45; }
.empty { padding: 40px 0; display: grid; justify-items: center; gap: 8px; color: #94a3b8; }
.empty i { font-size: 32px; }
.pager { display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; border-top: 1px solid #eef2f7; color: #475569; flex-wrap: wrap; gap: 10px; }
.pager :deep(.el-pagination.is-background .el-pager li:not(.disabled).active) { background-color: #008b4b; }
</style>
