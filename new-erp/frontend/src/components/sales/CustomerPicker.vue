<template>
  <el-dialog class="customer-picker-dialog" title="选择客户" :visible.sync="visible" width="900px" append-to-body>
    <div class="picker-filters">
      <el-input
        v-model="query.keyword"
        size="small"
        clearable
        placeholder="客户名称 / 联系人 / 电话 / 地址"
        @keyup.enter.native="load"
      />
      <el-select v-model="query.status" size="small" clearable placeholder="客户状态">
        <el-option label="启用" value="enabled" />
        <el-option label="停用" value="disabled" />
      </el-select>
      <el-button size="small" type="success" @click="load">查询</el-button>
    </div>

    <el-table
      :data="rows"
      border
      size="mini"
      height="420"
      highlight-current-row
      @row-click="current = $event"
      @row-dblclick="confirm"
    >
      <el-table-column width="54" align="center">
        <template slot-scope="{row}"><el-radio v-model="currentId" :label="row.id">&nbsp;</el-radio></template>
      </el-table-column>
      <el-table-column prop="customer_name" label="客户名称" min-width="180" show-overflow-tooltip />
      <el-table-column prop="contact_name" label="联系人" width="110" />
      <el-table-column prop="contact_phone" label="联系电话" width="135" />
      <el-table-column prop="full_address" label="收货地址" min-width="260" show-overflow-tooltip />
      <el-table-column label="状态" width="86">
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
        @size-change="load"
        @current-change="load"
      />
      <div>
        <el-button size="small" @click="visible = false">取消</el-button>
        <el-button size="small" type="success" :disabled="!current" @click="confirm">确定选择</el-button>
      </div>
    </div>
  </el-dialog>
</template>

<script>
import { listSalesCustomers } from '@/api/erp/sales'
import { statusText } from '@/utils/erpStatus'

export default {
  data: () => ({
    visible: false,
    rows: [],
    current: null,
    query: { keyword: '', status: 'enabled', page: 1, per_page: 10 },
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
      this.query.page = 1
      await this.load()
    },
    async load() {
      const { data } = await listSalesCustomers(this.query)
      this.rows = data.data || []
      this.page = { total: data.total || 0 }
    },
    confirm() {
      if (!this.current) return
      this.$emit('select', this.current)
      this.visible = false
    }
  }
}
</script>

<style scoped>
.picker-filters{display:grid;grid-template-columns:minmax(0,1fr) 150px 82px;gap:10px;margin-bottom:12px;align-items:center}
.picker-footer{display:flex;justify-content:space-between;align-items:center;margin-top:12px}
.customer-picker-dialog :deep(.el-dialog__header){border-bottom:1px solid #edf0f4}
.customer-picker-dialog :deep(.el-table th){background:#f8fafc;color:#334155}
.customer-picker-dialog :deep(.el-button--success){background:#00984f;border-color:#00984f}
</style>
