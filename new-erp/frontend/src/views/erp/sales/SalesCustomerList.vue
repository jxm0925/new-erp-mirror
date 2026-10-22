<template>
  <div class="customer-page">
    <main class="customer-workspace">
      <header class="customer-heading">
        <div>
          <div class="customer-crumb">销售管理 <span>/</span> 客户管理</div>
          <h1>客户管理</h1>
          <p>维护客户资料与合作信息，管理联系人与收货地址，支撑销售业务全流程使用。</p>
        </div>
        <div class="customer-actions">
          <el-button v-if="!$can || $can('sales.customer.create')" size="small" type="success" icon="el-icon-plus" @click="openCreate">新增客户</el-button>
          <el-button size="small" icon="el-icon-download" @click="exportPage">导出当前页</el-button>
          <el-button size="small" icon="el-icon-printer" @click="printPage">打印</el-button>
        </div>
      </header>

      <section class="customer-kpis" aria-label="客户状态统计">
        <article v-for="item in metrics" :key="item.key" class="customer-kpi">
          <span :class="['kpi-icon', item.key]"><i :class="item.icon" /></span>
          <div><small>{{ item.label }}</small><strong>{{ summary[item.key] || 0 }}</strong></div>
        </article>
      </section>

      <section class="customer-filter" aria-label="客户筛选">
        <label>客户名称/编码
          <el-input v-model.trim="query.keyword" size="small" clearable placeholder="请输入客户名称或客户编码" @keyup.enter.native="search" />
        </label>
        <label>客户类型
          <el-select v-model="query.customer_kind" size="small" clearable placeholder="全部类型" @change="search">
            <el-option label="全部类型" value="" />
            <el-option label="个人" value="individual" />
            <el-option label="企业" value="enterprise" />
          </el-select>
        </label>
        <label>电话
          <el-input v-model.trim="query.phone" size="small" clearable placeholder="请输入联系电话" @keyup.enter.native="search" />
        </label>
        <label>状态
          <el-select v-model="query.status" size="small" @change="search">
            <el-option label="全部状态" value="" />
            <el-option label="启用" value="enabled" />
            <el-option label="潜在" value="potential" />
            <el-option label="停用" value="disabled" />
            <el-option label="黑名单" value="blacklisted" />
          </el-select>
        </label>
        <label>负责人
          <el-select v-model="query.owner_name" size="small" clearable placeholder="全部" @change="search">
            <el-option v-for="name in ownerOptions" :key="name" :label="name" :value="name" />
          </el-select>
        </label>
        <div class="filter-buttons">
          <el-button size="small" type="success" icon="el-icon-search" @click="search">查询</el-button>
          <el-button size="small" icon="el-icon-refresh-right" @click="reset">重置</el-button>
        </div>
      </section>

      <section class="customer-table-card">
        <el-table v-loading="loading" :data="rows" size="small" highlight-current-row :row-class-name="rowClass" @row-click="select">
          <el-table-column width="44" align="center">
            <template slot-scope="{row}">
              <el-checkbox :value="selectedIds.includes(row.id)" @change="toggleSelection(row)" />
            </template>
          </el-table-column>
          <el-table-column prop="customer_code" label="客户编码" width="112" show-overflow-tooltip />
          <el-table-column prop="customer_name" label="客户名称" min-width="170" show-overflow-tooltip />
          <el-table-column label="客户类型" width="96">
            <template slot-scope="{row}">
              <span :class="['kind-badge', row.customer_kind === 'enterprise' ? 'kind-enterprise' : 'kind-individual']">
                {{ row.customer_kind === 'enterprise' ? '企业' : '个人' }}
              </span>
            </template>
          </el-table-column>
          <el-table-column prop="customer_type" label="所属行业" width="105" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span class="type-tag">{{ row.customer_type || '—' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="默认联系人" width="130">
            <template slot-scope="{row}">
              <b>{{ defaultContact(row).contact_name || '—' }}</b>
              <small class="cell-sub">{{ defaultContact(row).mobile || defaultContact(row).phone || row.contact_phone || '—' }}</small>
            </template>
          </el-table-column>
          <el-table-column label="默认收货地址" min-width="210" show-overflow-tooltip>
            <template slot-scope="{row}">
              {{ defaultAddress(row).full_address || row.full_address || '—' }}
            </template>
          </el-table-column>
          <el-table-column prop="owner_name" label="负责人" width="82">
            <template slot-scope="{row}">{{ row.owner_name || '—' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="76">
            <template slot-scope="{row}">
              <span :class="['status-dot', row.status]" />{{ statusText(row.status) }}
            </template>
          </el-table-column>
          <el-table-column label="更新时间" width="116">
            <template slot-scope="{row}">{{ dateTime(row.updated_at) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="118" fixed="right">
            <template slot-scope="{row}">
              <el-button type="text" size="mini" @click.stop="select(row)">查看</el-button>
              <el-button v-if="!$can || $can('sales.customer.edit')" type="text" size="mini" @click.stop="openEdit(row)">编辑</el-button>
            </template>
          </el-table-column>
          <template slot="empty">
            <div class="customer-empty">
              <i class="el-icon-office-building" />
              <b>暂无客户资料</b>
              <span>新增客户后，将在这里维护联系人和默认收货地址。</span>
              <el-button v-if="!$can || $can('sales.customer.create')" type="success" size="small" @click="openCreate">新增客户</el-button>
            </div>
          </template>
        </el-table>
        <footer class="customer-pagination">
          <span>共 {{ total }} 条</span>
          <el-pagination
            background
            layout="sizes, prev, pager, next, jumper"
            :current-page="query.page"
            :page-size="query.per_page"
            :page-sizes="[10, 20, 50, 100]"
            :total="total"
            @size-change="changeSize"
            @current-change="changePage"
          />
        </footer>
      </section>
    </main>

    <aside class="customer-inspector" aria-label="客户详情">
      <div v-if="selected" class="inspector-content">
        <header class="inspector-title">
          <b>客户详情</b>
          <el-button v-if="!$can || $can('sales.customer.edit')" type="text" size="small" icon="el-icon-edit" @click="openEdit(selected)">编辑</el-button>
        </header>
        <section class="inspector-overview">
          <div class="overview-tags">
            <el-tag size="mini" :type="statusType(selected.status)">{{ statusText(selected.status) }}</el-tag>
            <span :class="['kind-badge', selected.customer_kind === 'enterprise' ? 'kind-enterprise' : 'kind-individual']">
              {{ selected.customer_kind === 'enterprise' ? '企业' : '个人' }}
            </span>
          </div>
          <h2>{{ selected.customer_name }}</h2>
          <p>{{ selected.customer_code }}</p>
          <dl>
            <dt>客户性质</dt>
            <dd>{{ selected.customer_kind === 'enterprise' ? '企业客户' : '个人客户' }}</dd>
            <dt>所属行业</dt>
            <dd>{{ selected.customer_type || '—' }}</dd>
            <dt>客户简称</dt>
            <dd>{{ selected.customer_short_name || '—' }}</dd>
            <dt>负责人</dt>
            <dd>{{ selected.owner_name || '—' }}</dd>
            <dt>联系电话</dt>
            <dd>{{ defaultContact(selected).mobile || defaultContact(selected).phone || selected.contact_phone || '—' }}</dd>
            <dt>平台买家ID</dt>
            <dd>{{ selected.platform_buyer_id || '—' }}</dd>
            <dt>客户备注</dt>
            <dd>{{ selected.remark || '—' }}</dd>
          </dl>
        </section>
        <section class="inspector-block">
          <h3>默认联系人</h3>
          <template v-if="defaultContact(selected).id">
            <b>{{ defaultContact(selected).contact_name }}<small>{{ defaultContact(selected).position || '联系人' }}</small></b>
            <p>手机：{{ defaultContact(selected).mobile || '—' }}</p>
            <p>电话：{{ defaultContact(selected).phone || '—' }}</p>
            <p>邮箱：{{ defaultContact(selected).email || '—' }}</p>
          </template>
          <p v-else class="muted">尚未设置默认联系人</p>
        </section>
        <section class="inspector-block">
          <h3>默认收货地址</h3>
          <template v-if="defaultAddress(selected).id">
            <p class="address-main">{{ defaultAddress(selected).full_address }}</p>
            <p>收货人：{{ defaultAddress(selected).receiver_name || '—' }}　{{ defaultAddress(selected).receiver_phone || '—' }}</p>
          </template>
          <p v-else class="muted">尚未设置默认收货地址</p>
        </section>
        <footer class="inspector-actions">
          <el-button size="small" @click="openEdit(selected)">编辑档案</el-button>
          <el-button size="small" type="success" @click="createOrder">新增订单</el-button>
        </footer>
      </div>
      <div v-else class="inspector-empty">
        <i class="el-icon-s-custom" />
        <b>选择一位客户</b>
        <span>点击列表行可查看客户档案与默认收货信息。</span>
      </div>
    </aside>

    <!-- 通用客户档案抽屉（支持新增与编辑，含个人/企业选项） -->
    <customer-drawer ref="customerDrawer" @saved="handleCustomerSaved" />
  </div>
</template>

<script>
import { erpDateTime } from '../../../utils/dateTime'
import { listSalesCustomers } from '@/api/erp/sales'
import CustomerDrawer from '@/components/sales/CustomerDrawer.vue'

export default {
  name: 'SalesCustomerList',
  components: { CustomerDrawer },
  data: () => ({
    rows: [],
    total: 0,
    summary: {},
    ownerOptions: [],
    selected: null,
    selectedIds: [],
    loading: false,
    query: {
      keyword: '',
      customer_kind: '',
      phone: '',
      status: '',
      owner_name: '',
      page: 1,
      per_page: 10
    }
  }),
  computed: {
    metrics() {
      return [
        { key: 'total', label: '全部客户', icon: 'el-icon-office-building' },
        { key: 'enabled', label: '启用客户', icon: 'el-icon-circle-check' },
        { key: 'potential', label: '潜在客户', icon: 'el-icon-star-off' },
        { key: 'disabled', label: '停用客户', icon: 'el-icon-remove-outline' },
        { key: 'blacklisted', label: '黑名单客户', icon: 'el-icon-warning-outline' }
      ]
    }
  },
  created() {
    this.load()
  },
  methods: {
    statusText(status) {
      return ({ enabled: '启用', potential: '潜在', disabled: '停用', blacklisted: '黑名单' })[status] || '—'
    },
    statusType(status) {
      return ({ enabled: 'success', potential: 'warning', disabled: 'info', blacklisted: 'danger' })[status] || 'info'
    },
    defaultContact(row) {
      return (row.contacts || []).find(item => item.is_default) || (row.contacts || [])[0] || {}
    },
    defaultAddress(row) {
      return (row.addresses || []).find(item => item.is_default) || (row.addresses || [])[0] || {}
    },
    dateTime(value) {
      return value ? erpDateTime(value, 16) : '—'
    },
    rowClass({ row }) {
      return this.selected && row.id === this.selected.id ? 'customer-selected-row' : ''
    },
    select(row) {
      this.selected = row
    },
    toggleSelection(row) {
      const idx = this.selectedIds.indexOf(row.id)
      if (idx > -1) {
        this.selectedIds.splice(idx, 1)
      } else {
        this.selectedIds.push(row.id)
      }
    },
    async load() {
      this.loading = true
      try {
        const { data } = await listSalesCustomers(this.query)
        this.rows = data.data || []
        this.total = data.total || 0
        this.summary = data.summary || {}
        this.ownerOptions = data.owner_options || []
        if (this.selected) {
          this.selected = this.rows.find(item => item.id === this.selected.id) || null
        } else {
          this.selected = null
        }
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
      this.query = {
        keyword: '',
        customer_kind: '',
        phone: '',
        status: '',
        owner_name: '',
        page: 1,
        per_page: this.query.per_page
      }
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
    async handleCustomerSaved(customer) {
      await this.load()
      if (customer && customer.id) {
        this.selected = this.rows.find(item => item.id === customer.id) || customer
      }
    },
    createOrder() {
      if (!this.selected) return
      this.$router.push({ path: '/sales/orders/create', query: { customer_id: this.selected.id } })
    },
    exportPage() {
      const lines = [
        ['客户编码', '客户名称', '客户类型', '所属行业', '默认联系人', '联系电话', '默认收货地址', '负责人', '状态'],
        ...this.rows.map(row => [
          row.customer_code,
          row.customer_name,
          row.customer_kind === 'enterprise' ? '企业' : '个人',
          row.customer_type || '',
          this.defaultContact(row).contact_name || '',
          this.defaultContact(row).mobile || this.defaultContact(row).phone || row.contact_phone || '',
          this.defaultAddress(row).full_address || row.full_address || '',
          row.owner_name || '',
          this.statusText(row.status)
        ])
      ]
      const blob = new Blob(['\uFEFF' + lines.map(line => line.map(value => `"${String(value || '').replace(/"/g, '""')}"`).join(',')).join('\n')], { type: 'text/csv;charset=utf-8;' })
      const link = document.createElement('a')
      link.href = URL.createObjectURL(blob)
      link.download = `客户管理-${new Date().toISOString().slice(0, 10)}.csv`
      link.click()
      URL.revokeObjectURL(link.href)
    },
    printPage() {
      window.print()
    }
  }
}
</script>

<style scoped>
.customer-page {
  min-height: calc(100vh - 52px);
  padding: 16px 18px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 288px;
  gap: 14px;
  background: #f7f8fa;
}
.customer-workspace {
  min-width: 0;
}
.customer-heading {
  min-height: 76px;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}
.customer-crumb {
  color: #6b7280;
  font-size: 12px;
}
.customer-crumb span {
  padding: 0 8px;
  color: #b4bdc7;
}
.customer-heading h1 {
  margin: 8px 0 2px;
  font-size: 21px;
  line-height: 1.1;
  color: #15212b;
}
.customer-heading p {
  margin: 0;
  color: #76818d;
  font-size: 11px;
}
.customer-actions {
  display: flex;
  gap: 9px;
  padding-top: 2px;
}
.customer-kpis {
  display: grid;
  grid-template-columns: repeat(5, minmax(126px, 1fr));
  gap: 9px;
  margin-bottom: 14px;
}
.customer-kpi {
  height: 80px;
  padding: 14px;
  display: flex;
  align-items: center;
  gap: 11px;
  background: #fff;
  border: 1px solid #edf0f3;
  border-radius: 7px;
  box-shadow: 0 2px 7px rgba(17, 24, 39, .02);
}
.kpi-icon {
  width: 32px;
  height: 32px;
  display: grid;
  place-items: center;
  border-radius: 50%;
  font-size: 16px;
}
.kpi-icon.total {
  color: #18a563;
  background: #e9fbf1;
}
.kpi-icon.enabled {
  color: #18a563;
  background: #e9fbf1;
}
.kpi-icon.potential {
  color: #2b9ed3;
  background: #ebf8ff;
}
.kpi-icon.disabled {
  color: #f28a27;
  background: #fff3e7;
}
.kpi-icon.blacklisted {
  color: #eb4b54;
  background: #fff0f0;
}
.customer-kpi small {
  display: block;
  color: #67717c;
  font-size: 11px;
}
.customer-kpi strong {
  font-size: 24px;
  line-height: 1.2;
  color: #171e26;
}
.customer-filter {
  display: grid;
  grid-template-columns: minmax(160px, 1.2fr) 110px minmax(120px, 1fr) 110px 110px auto;
  gap: 10px;
  align-items: end;
  margin-bottom: 12px;
}
.customer-filter label {
  display: grid;
  gap: 5px;
  color: #3e4954;
  font-size: 11px;
}
.filter-buttons {
  display: flex;
  gap: 8px;
}
.customer-table-card {
  overflow: hidden;
  background: #fff;
  border: 1px solid #e9edf1;
  border-radius: 7px;
}
.customer-table-card :deep(.el-table th.el-table__cell) {
  height: 44px;
  background: #fbfcfd;
  color: #29323b;
  font-weight: 600;
}
.customer-table-card :deep(.el-table td.el-table__cell) {
  padding: 9px 0;
}
.customer-table-card :deep(.customer-selected-row td) {
  background: #f1faf5 !important;
}
.customer-table-card :deep(.el-table__fixed-right) {
  height: 100% !important;
}
.type-tag {
  display: inline-block;
  padding: 2px 6px;
  border-radius: 3px;
  color: #475569;
  background: #f1f5f9;
  font-size: 11px;
}
.kind-badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 500;
  line-height: 1.2;
}
.kind-enterprise {
  color: #d97706;
  background-color: #fef3c7;
  border: 1px solid #fde68a;
}
.kind-individual {
  color: #2563eb;
  background-color: #eff6ff;
  border: 1px solid #dbeafe;
}
.cell-sub {
  display: block;
  margin-top: 2px;
  color: #6f7984;
  font-weight: 400;
  font-size: 10px;
}
.status-dot {
  display: inline-block;
  width: 6px;
  height: 6px;
  margin-right: 5px;
  border-radius: 50%;
  background: #9aa4af;
}
.status-dot.enabled {
  background: #00a459;
}
.status-dot.potential {
  background: #f19b26;
}
.status-dot.disabled {
  background: #9aa4af;
}
.status-dot.blacklisted {
  background: #e23e45;
}
.muted {
  color: #9aa4af;
}
.customer-pagination {
  min-height: 56px;
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-top: 1px solid #eef1f4;
  color: #66717b;
}
.customer-pagination :deep(.el-pagination) {
  padding: 0;
}
.customer-empty {
  height: 330px;
  display: grid;
  place-content: center;
  justify-items: center;
  gap: 9px;
  color: #98a2ad;
}
.customer-empty i {
  font-size: 34px;
  color: #b3bdc7;
}
.customer-empty b {
  color: #4b5560;
  font-size: 13px;
}
.customer-empty span {
  font-size: 11px;
}
.customer-inspector {
  min-width: 0;
  padding: 0;
  background: #fff;
  border: 1px solid #e7ebef;
  border-radius: 7px;
  box-shadow: 0 2px 8px rgba(17, 24, 39, .025);
}
.inspector-content {
  min-height: 100%;
  display: flex;
  flex-direction: column;
}
.inspector-title {
  height: 52px;
  padding: 0 14px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid #edf0f2;
  font-size: 15px;
}
.inspector-overview,
.inspector-block {
  padding: 15px 14px;
  border-bottom: 1px solid #edf0f2;
}
.overview-tags {
  display: flex;
  gap: 6px;
  align-items: center;
  margin-bottom: 6px;
}
.inspector-overview h2 {
  margin: 4px 0 2px;
  font-size: 17px;
  line-height: 1.35;
  color: #202830;
}
.inspector-overview > p {
  margin: 0 0 14px;
  color: #75808c;
  font-size: 11px;
}
.inspector-overview dl {
  display: grid;
  grid-template-columns: 68px minmax(0, 1fr);
  gap: 8px;
  margin: 0;
  font-size: 11px;
}
.inspector-overview dt {
  color: #7d8792;
}
.inspector-overview dd {
  margin: 0;
  word-break: break-word;
}
.inspector-block h3 {
  margin: 0 0 12px;
  font-size: 13px;
}
.inspector-block b {
  display: block;
  font-size: 12px;
}
.inspector-block b small {
  margin-left: 6px;
  color: #8a949d;
  font-weight: 400;
}
.inspector-block p {
  margin: 5px 0;
  color: #59636e;
  font-size: 11px;
  line-height: 1.55;
}
.address-main {
  color: #2e3740 !important;
  font-weight: 600;
}
.inspector-actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-top: auto;
  padding: 14px;
}
.inspector-actions .el-button {
  margin: 0;
}
.inspector-empty {
  height: 100%;
  min-height: 410px;
  display: grid;
  place-content: center;
  justify-items: center;
  gap: 8px;
  color: #9aa4af;
  text-align: center;
}
.inspector-empty i {
  font-size: 32px;
  color: #b9c2ca;
}
.inspector-empty b {
  color: #56616b;
}
.inspector-empty span {
  max-width: 180px;
  font-size: 11px;
}

@media (max-width: 1280px) {
  .customer-page {
    grid-template-columns: minmax(0, 1fr);
  }
  .customer-inspector {
    display: none;
  }
}
@media (max-width: 980px) {
  .customer-page {
    padding: 12px;
    min-width: 0;
  }
  .customer-kpis {
    grid-template-columns: repeat(5, 130px);
    overflow: auto;
  }
  .customer-filter {
    grid-template-columns: 1fr 1fr 110px 110px auto;
  }
  .customer-heading p {
    display: none;
  }
}
@media print {
  .erp-sidebar,
  .erp-topbar,
  .customer-actions,
  .customer-filter,
  .customer-inspector,
  .customer-pagination {
    display: none !important;
  }
  .customer-page {
    display: block;
    padding: 0;
    background: #fff;
  }
  .customer-kpis {
    margin: 10px 0;
  }
  .customer-table-card {
    border: 0;
  }
}
</style>
