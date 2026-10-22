<template>
  <el-dialog class="customer-picker-dialog" title="选择客户" :visible.sync="visible" width="920px" append-to-body>
    <div class="picker-filters">
      <el-input
        v-model="query.keyword"
        size="small"
        clearable
        placeholder="客户名称 / 联系人 / 电话 / 地址"
        @keyup.enter.native="search"
      />
      <el-select v-model="query.customer_kind" size="small" clearable placeholder="客户类型" class="kind-select" @change="search">
        <el-option label="全部类型" value="" />
        <el-option label="个人" value="individual" />
        <el-option label="企业" value="enterprise" />
      </el-select>
      <el-select v-model="query.status" size="small" clearable placeholder="客户状态" class="status-select" @change="search">
        <el-option label="全部状态" value="" />
        <el-option label="启用" value="enabled" />
        <el-option label="停用" value="disabled" />
      </el-select>
      <div class="filter-actions">
        <el-button size="small" type="success" icon="el-icon-search" @click="search">查询</el-button>
        <el-button
          v-if="!$can || $can('sales.customer.create')"
          size="small"
          type="primary"
          icon="el-icon-plus"
          class="btn-emerald-primary"
          @click="openCreateCustomer"
        >
          新增客户
        </el-button>
      </div>
    </div>

    <el-table
      v-loading="loading"
      :data="rows"
      border
      size="mini"
      height="420"
      highlight-current-row
      @row-click="current = $event"
      @row-dblclick="confirm"
    >
      <el-table-column width="48" align="center">
        <template slot-scope="{row}"><el-radio v-model="currentId" :label="row.id" :disabled="!isSelectable(row)">&nbsp;</el-radio></template>
      </el-table-column>
      <el-table-column label="客户类型" width="82" align="center">
        <template slot-scope="{row}">
          <span :class="['kind-badge', row.customer_kind === 'enterprise' ? 'kind-enterprise' : 'kind-individual']">
            {{ row.customer_kind === 'enterprise' ? '企业' : '个人' }}
          </span>
        </template>
      </el-table-column>
      <el-table-column prop="customer_name" label="客户名称" min-width="180" show-overflow-tooltip />
      <el-table-column prop="contact_name" label="联系人" width="105" show-overflow-tooltip />
      <el-table-column prop="contact_phone" label="联系电话" width="130" show-overflow-tooltip />
      <el-table-column prop="full_address" label="收货地址" min-width="240" show-overflow-tooltip />
      <el-table-column label="状态" width="80" align="center">
        <template slot-scope="{row}">
          <el-tag size="mini" :type="row.status === 'enabled' ? 'success' : 'info'">{{ statusText(row.status) }}</el-tag>
        </template>
      </el-table-column>
    </el-table>

    <div class="picker-footer">
      <el-pagination
        layout="total, sizes, prev, pager, next"
        :total="page.total"
        :page-size.sync="query.per_page"
        :current-page.sync="query.page"
        :page-sizes="[10, 20, 50]"
        @size-change="search"
        @current-change="load"
      />
      <div class="footer-buttons">
        <el-button size="small" @click="visible = false">取消</el-button>
        <el-button size="small" type="success" :disabled="!isSelectable(current)" @click="confirm">确定选择</el-button>
      </div>
    </div>

    <!-- 新增客户抽屉 -->
    <customer-drawer ref="customerDrawer" confirm-text="保存并选择" @saved="handleCustomerCreated" />
  </el-dialog>
</template>

<script>
import { listSalesCustomers } from '@/api/erp/sales'
import { statusText } from '@/utils/erpStatus'
import CustomerDrawer from '@/components/sales/CustomerDrawer.vue'

export default {
  name: 'CustomerPicker',
  components: { CustomerDrawer },
  data: () => ({
    visible: false,
    loading: false,
    rows: [],
    current: null,
    query: { keyword: '', customer_kind: '', status: 'enabled', page: 1, per_page: 10 },
    page: { total: 0 }
  }),
  computed: {
    currentId: {
      get() { return this.current && this.current.id },
      set(id) { this.current = this.rows.find(row => row.id === id) || null }
    }
  },
  methods: {
    statusText,
    async open() {
      this.visible = true
      this.current = null
      await this.search()
    },
    search() {
      this.query.page = 1
      return this.load()
    },
    async load() {
      this.loading = true
      try {
        const { data } = await listSalesCustomers(this.query)
        this.rows = data.data || []
        this.page = { total: data.total || 0 }
      } catch (err) {
        this.$message.error('客户列表加载失败')
      } finally {
        this.loading = false
      }
    },
    isSelectable(customer) {
      return !!customer && ['enabled', 'potential'].includes(customer.status)
    },
    confirm() {
      if (!this.current) return
      if (!this.isSelectable(this.current)) return this.$message.warning('仅可选择启用或潜在客户')
      this.$emit('select', this.current)
      this.visible = false
    },
    openCreateCustomer() {
      this.$refs.customerDrawer.openCreate('individual')
    },
    handleCustomerCreated(customer) {
      if (!this.isSelectable(customer)) {
        this.current = null
        this.$message.warning('客户已创建，当前状态不可选择')
        this.search()
        return
      }
      this.$message.success(`客户【${customer.customer_name}】已创建并自动选择`)
      this.$emit('select', customer)
      this.visible = false
    }
  }
}
</script>

<style scoped>
.picker-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
  align-items: center;
}
.picker-filters .el-input {
  flex: 1 1 200px;
  min-width: 160px;
}
.kind-select {
  width: 110px;
}
.status-select {
  width: 105px;
}
.filter-actions {
  display: flex;
  gap: 8px;
  margin-left: auto;
}
.btn-emerald-primary {
  background-color: #00984f;
  border-color: #00984f;
  color: #fff;
}
.btn-emerald-primary:hover {
  background-color: #008042;
  border-color: #008042;
  color: #fff;
}
.picker-footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-top: 14px;
}
.footer-buttons {
  display: flex;
  gap: 8px;
}
.customer-picker-dialog :deep(.el-dialog__header) {
  border-bottom: 1px solid #edf0f4;
  padding: 16px 20px;
}
.customer-picker-dialog :deep(.el-dialog__body) {
  padding: 16px 20px 20px;
}
.customer-picker-dialog :deep(.el-table th) {
  background: #f8fafc;
  color: #334155;
  font-weight: 600;
}
.customer-picker-dialog :deep(.el-button--success) {
  background: #00984f;
  border-color: #00984f;
}
.customer-picker-dialog :deep(.el-button--success:hover) {
  background: #008042;
  border-color: #008042;
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

@media (max-width: 768px) {
  .customer-picker-dialog :deep(.el-dialog) {
    width: 95% !important;
    margin-top: 5vh !important;
  }
  .filter-actions {
    margin-left: 0;
    width: 100%;
    justify-content: flex-end;
  }
}
</style>
