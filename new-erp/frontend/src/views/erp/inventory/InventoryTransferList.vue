<template>
  <section class="simple-page">
    <header class="simple-head">
      <div>
        <h1>库存调拨</h1>
        <p>库位间或仓库间调拨，过账即时成对出入库，按来源批次平均成本结转，序列号随行迁移；只调可用数量（不含锁定、不良、待检）。</p>
      </div>
      <div class="simple-actions">
        <el-button size="small" icon="el-icon-refresh" :loading="loading" @click="load">刷新</el-button>
        <el-button v-if="$can('inventory.transfer.create')" size="small" type="success" icon="el-icon-plus" @click="openForm()">新建调拨</el-button>
      </div>
    </header>

    <section class="simple-card">
      <div class="simple-filters">
        <el-input v-model.trim="filters.keyword" size="small" clearable placeholder="调拨单号" @keyup.enter.native="query" />
        <paged-record-select v-model="filters.warehouse_id" entity="warehouses" :params="warehouseParams" title="选择仓库" placeholder="全部仓库" />
        <el-select v-model="filters.status" size="small" clearable placeholder="全部状态">
          <el-option v-for="(label, value) in statuses" :key="value" :label="label" :value="value" />
        </el-select>
        <el-button size="small" type="primary" @click="query">查询</el-button>
      </div>
      <div class="simple-table">
        <el-table v-loading="loading" :data="rows" border size="small">
          <el-table-column prop="transfer_no" label="调拨单号" min-width="150" />
          <el-table-column label="调出 → 调入" min-width="200">
            <template slot-scope="{ row }">{{ row.source_warehouse && row.source_warehouse.warehouse_name }} → {{ row.target_warehouse && row.target_warehouse.warehouse_name }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template slot-scope="{ row }"><el-tag size="mini" :type="statusType(row.transfer_status)">{{ row.status_label }}</el-tag></template>
          </el-table-column>
          <el-table-column prop="lines_count" label="明细行" width="80" />
          <el-table-column prop="transfer_date" label="调拨日期" width="110" />
          <el-table-column prop="created_by_name" label="制单人" width="100" />
          <el-table-column prop="posted_by_name" label="过账人" width="100" />
          <el-table-column label="操作" width="220" fixed="right">
            <template slot-scope="{ row }">
              <el-button type="text" size="small" @click="openDetail(row.id)">详情</el-button>
              <template v-if="row.transfer_status === 'draft'">
                <el-button v-if="$can('inventory.transfer.create')" type="text" size="small" @click="openForm(row.id)">编辑</el-button>
                <el-button v-if="$can('inventory.transfer.post')" type="text" size="small" @click="post(row)">过账</el-button>
                <el-button v-if="$can('inventory.transfer.create') || $can('inventory.transfer.post')" type="text" size="small" @click="cancel(row)">取消</el-button>
                <el-button v-if="$can('inventory.transfer.delete')" type="text" size="small" class="diff-loss" @click="remove(row)">删除</el-button>
              </template>
            </template>
          </el-table-column>
        </el-table>
      </div>
      <div class="simple-pager">
        <el-pagination background layout="total, prev, pager, next" :current-page="page" :page-size="pageSize" :total="total" @current-change="changePage" />
      </div>
    </section>

    <el-dialog :title="form.id ? '编辑调拨单' : '新建调拨单'" :visible.sync="formVisible" :width="dialogWidth('1100px')" append-to-body>
      <el-form ref="form" :model="form" label-width="80px" size="small">
        <div class="simple-form-row">
          <el-form-item label="调出仓库" required>
            <paged-record-select v-model="form.source_warehouse_id" entity="warehouses" :params="warehouseParams" title="选择调出仓库" placeholder="选择调出仓库" @change="changeSource" />
          </el-form-item>
          <el-form-item label="调入仓库" required>
            <paged-record-select v-model="form.target_warehouse_id" entity="warehouses" :params="warehouseParams" title="选择调入仓库" placeholder="选择调入仓库" @change="form.lines.forEach(l => { l.target_location_id = null })" />
          </el-form-item>
        </div>
        <el-form-item label="备注">
          <el-input v-model="form.remark" maxlength="500" />
        </el-form-item>
      </el-form>
      <el-table :data="form.lines" border size="small">
        <el-table-column label="来源库存" min-width="260">
          <template slot-scope="{ row }">
            <paged-record-select v-model="row.inventory_balance_id" :request="listTransferBalances" :params="{ warehouse_id: form.source_warehouse_id }" :selected-rows.sync="balances" :label="balanceLabel" :columns="balanceColumns" :category-request="listSourceCategories" :disabled="!form.source_warehouse_id" size="mini" title="选择来源库存" placeholder="物料 / 规格 / 库位 / 批次" @change="row.serial_ids = []" />
          </template>
        </el-table-column>
        <el-table-column label="调入库位" min-width="160">
          <template slot-scope="{ row }">
            <paged-record-select v-model="row.target_location_id" entity="locations" :params="{ warehouse_id: form.target_warehouse_id, statuses: warehouseParams.statuses }" :disabled="!form.target_warehouse_id" size="mini" title="选择调入库位" placeholder="调入库位" />
          </template>
        </el-table-column>
        <el-table-column label="数量" width="140">
          <template slot-scope="{ row }">
            <el-input-number v-model="row.quantity" :min="0" :max="maxQty(row)" :precision="4" :controls="false" size="mini" style="width: 110px" />
          </template>
        </el-table-column>
        <el-table-column label="序列号" min-width="200">
          <template slot-scope="{ row }">
            <paged-record-select v-if="hasSerials(row)" v-model="row.serial_ids" :request="listSourceSerials" :params="{ inventory_balance_id: row.inventory_balance_id, warehouse_id: form.source_warehouse_id, for_transfer: 1 }" :columns="[{ prop: 'serial_no', label: '序列号' }]" :label="serialLabel" multiple size="mini" title="选择序列号" placeholder="选择序列号" @change="row.quantity = row.serial_ids.length" />
            <span v-else>—</span>
          </template>
        </el-table-column>
        <el-table-column label="备注" min-width="120">
          <template slot-scope="{ row }"><el-input v-model="row.remark" size="mini" maxlength="500" /></template>
        </el-table-column>
        <el-table-column label="" width="60">
          <template slot-scope="{ $index }"><el-button type="text" size="mini" @click="form.lines.splice($index, 1)">移除</el-button></template>
        </el-table-column>
      </el-table>
      <el-button size="small" icon="el-icon-plus" style="margin-top: 8px" :disabled="!form.source_warehouse_id" @click="addLine">添加明细</el-button>
      <span slot="footer">
        <el-button size="small" @click="formVisible = false">取消</el-button>
        <el-button size="small" type="primary" :loading="saving" @click="submit">保存草稿</el-button>
      </span>
    </el-dialog>

    <el-dialog :title="detail ? `调拨单 ${detail.transfer_no}` : '调拨单'" :visible.sync="detailVisible" :width="dialogWidth('1000px')" append-to-body>
      <div v-if="detail">
        <div class="simple-meta">
          <div><span>调出 → 调入：</span>{{ detail.source_warehouse && detail.source_warehouse.warehouse_name }} → {{ detail.target_warehouse && detail.target_warehouse.warehouse_name }}</div>
          <div><span>状态：</span>{{ detail.status_label }}</div>
          <div><span>制单：</span>{{ detail.created_by_name || '—' }} {{ detail.transfer_date }}</div>
          <div v-if="detail.posted_by_name"><span>过账：</span>{{ detail.posted_by_name }}</div>
          <div v-if="detail.remark"><span>备注：</span>{{ detail.remark }}</div>
        </div>
        <el-table :data="detail.lines" border size="small">
          <el-table-column label="物料" min-width="180">
            <template slot-scope="{ row }">{{ row.item && row.item.item_code }} {{ row.item && row.item.item_name }}</template>
          </el-table-column>
          <el-table-column prop="batch_no" label="批次" min-width="100" />
          <el-table-column label="来源库位 → 调入库位" min-width="180">
            <template slot-scope="{ row }">{{ row.source_location && row.source_location.location_name }} → {{ row.target_location && row.target_location.location_name }}</template>
          </el-table-column>
          <el-table-column label="数量" width="100">
            <template slot-scope="{ row }">{{ num(row.quantity) }} {{ row.unit && row.unit.unit_name }}</template>
          </el-table-column>
          <el-table-column label="结转成本" width="100">
            <template slot-scope="{ row }">{{ row.cost_amount === null ? '—' : row.cost_amount }}</template>
          </el-table-column>
          <el-table-column label="序列号" min-width="160">
            <template slot-scope="{ row }">{{ (row.serial_nos || []).join('、') || '—' }}</template>
          </el-table-column>
        </el-table>
      </div>
      <span slot="footer"><el-button size="small" @click="detailVisible = false">关闭</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { listTransfers, getTransferOptions, listTransferBalances, listInventoryBalanceSerials, getTransfer, saveTransfer, postTransfer, cancelTransfer, deleteTransfer } from '@/api/erp/inventory'
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { listEntity } from '@/api/erp/master'
import './inventory-simple.css'

const emptyLine = () => ({ inventory_balance_id: null, target_location_id: null, quantity: undefined, serial_ids: [], remark: '' })

export default {
  name: 'InventoryTransferList',
  components: { PagedRecordSelect },
  data() {
    return {
      loading: false,
      saving: false,
      rows: [],
      total: 0,
      page: 1,
      pageSize: 10,
      filters: { keyword: '', warehouse_id: null, status: '' },
      warehouseParams: { statuses: ['enabled', 'active'], include_location_summary: 1 },
      statuses: {},
      balances: [],
      balanceColumns: [
        { prop: 'item_code', label: '物料编码', width: 150 },
        { prop: 'item_name', label: '物料名称', width: 150 },
        { prop: 'spec', label: '规格', width: 120 },
        { prop: 'location_name', label: '来源库位', width: 120 },
        { prop: 'batch_no', label: '批次', width: 140 },
        { prop: 'quantity_available', label: '可用数量', width: 110, format: row => `${this.num(row.quantity_available)} ${row.unit_name || ''}` }
      ],
      formVisible: false,
      form: { id: null, source_warehouse_id: null, target_warehouse_id: null, remark: '', lines: [] },
      detailVisible: false,
      detail: null,
      windowWidth: window.innerWidth
    }
  },
  created() {
    this.loadOptions()
    this.load()
    window.addEventListener('resize', this.onResize)
  },
  beforeDestroy() {
    window.removeEventListener('resize', this.onResize)
  },
  methods: {
    listTransferBalances,
    listSourceCategories() { return listEntity('categories', { category_type: 'item', tree: 1 }) },
    listSourceSerials({ inventory_balance_id: id, ...params }) { return listInventoryBalanceSerials(id, params) },
    serialLabel(row) { return row.serial_no },
    onResize() { this.windowWidth = window.innerWidth },
    dialogWidth(width) { return this.windowWidth < 768 ? '96%' : width },
    num(value) {
      const n = Number(value || 0)
      return Number.isInteger(n) ? String(n) : String(Number(n.toFixed(4)))
    },
    statusType(status) { return { draft: 'warning', posted: 'success', cancelled: 'info' }[status] || '' },
    fail(error) { this.$message.error((error && error.userMessage) || '操作失败') },
    balanceOf(row) { return this.balances.find(b => b.id === row.inventory_balance_id) },
    balanceLabel(b) { return `${b.item_code} ${b.item_name} | ${b.location_name} | ${b.batch_no} | 可用 ${this.num(b.quantity_available)}${b.unit_name ? ' ' + b.unit_name : ''}` },
    maxQty(row) {
      const balance = this.balanceOf(row)
      return balance ? balance.quantity_available : Infinity
    },
    hasSerials(row) {
      const balance = this.balanceOf(row)
      return row.serial_ids.length > 0 || !!(balance && balance.available_serials_count > 0)
    },
    async loadOptions() {
      try {
        const { data } = await getTransferOptions()
        this.statuses = data.data.transfer_statuses || {}
      } catch (e) { this.fail(e) }
    },
    async load() {
      this.loading = true
      try {
        const { data } = await listTransfers({ ...this.filters, page: this.page, per_page: this.pageSize })
        this.rows = data.data || []
        this.total = data.total || 0
      } catch (e) {
        this.fail(e)
      } finally {
        this.loading = false
      }
    },
    query() { this.page = 1; this.load() },
    changePage(page) { this.page = page; this.load() },
    changeSource() {
      this.form.lines = [emptyLine()]
      this.balances = []
    },
    addLine() { this.form.lines.push(emptyLine()) },
    async openForm(id) {
      this.form = { id: null, source_warehouse_id: null, target_warehouse_id: null, remark: '', lines: [emptyLine()] }
      this.balances = []
      if (id) {
        try {
          const { data } = await getTransfer(id)
          const t = data.data
          this.form = {
            id: t.id,
            source_warehouse_id: t.source_warehouse_id,
            target_warehouse_id: t.target_warehouse_id,
            remark: t.remark || '',
            lines: t.lines.map(l => ({ inventory_balance_id: l.inventory_balance_id, target_location_id: l.target_location_id, quantity: Number(l.quantity), serial_ids: l.serial_ids || [], remark: l.remark || '' }))
          }
        } catch (e) {
          this.fail(e)
          return
        }
      }
      this.formVisible = true
    },
    async submit() {
      if (!this.form.source_warehouse_id || !this.form.target_warehouse_id) return this.$message.warning('请选择调出和调入仓库')
      const lines = this.form.lines.filter(l => l.inventory_balance_id)
      if (!lines.length) return this.$message.warning('请至少添加一行明细')
      if (lines.some(l => !l.target_location_id || !(l.quantity > 0))) return this.$message.warning('请填写每行的调入库位和数量')
      this.saving = true
      try {
        await saveTransfer({ ...this.form, lines: lines.map(l => ({ ...l, serial_ids: l.serial_ids.length ? l.serial_ids : null })) })
        this.$message.success('调拨单已保存')
        this.formVisible = false
        this.load()
      } catch (e) {
        this.fail(e)
      } finally {
        this.saving = false
      }
    },
    async openDetail(id) {
      try {
        const { data } = await getTransfer(id)
        this.detail = data.data
        this.detailVisible = true
      } catch (e) { this.fail(e) }
    },
    async confirmAction(message, title, action, success) {
      try {
        await this.$confirm(message, title, { type: 'warning' })
      } catch (e) { return }
      try {
        await action()
        this.$message.success(success)
        this.load()
      } catch (e) { this.fail(e) }
    },
    post(row) { this.confirmAction(`确认过账调拨单 ${row.transfer_no}？过账后库存立即转移。`, '调拨过账', () => postTransfer(row.id), '调拨已过账') },
    cancel(row) { this.confirmAction(`确认取消调拨单 ${row.transfer_no}？`, '取消调拨', () => cancelTransfer(row.id), '调拨单已取消') },
    remove(row) { this.confirmAction(`确认删除调拨草稿 ${row.transfer_no}？`, '删除草稿', () => deleteTransfer(row.id), '已删除') }
  }
}
</script>

<style scoped>
.simple-filters > .paged-record-select {
  width: 170px;
}
@media (max-width: 768px) {
  .simple-filters > .paged-record-select {
    width: 100%;
  }
}
</style>
