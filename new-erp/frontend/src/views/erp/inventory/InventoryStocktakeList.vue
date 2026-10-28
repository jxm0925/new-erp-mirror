<template>
  <section class="simple-page">
    <header class="simple-head">
      <div>
        <h1>库存盘点</h1>
        <p>开盘即冻结所选仓库/库位的出入库；录完实盘数后完成盘点，差异自动生成“盘点差异”调整单并过账。</p>
      </div>
      <div class="simple-actions">
        <el-button size="small" icon="el-icon-refresh" :loading="loading" @click="load">刷新</el-button>
        <el-button v-if="$can('inventory.stocktake.create')" size="small" type="success" icon="el-icon-plus" @click="openCreate">新建盘点</el-button>
      </div>
    </header>

    <section class="simple-card">
      <div class="simple-filters">
        <el-input v-model.trim="filters.keyword" size="small" clearable placeholder="盘点单号" @keyup.enter.native="query" />
        <el-select v-model="filters.warehouse_id" size="small" clearable placeholder="全部仓库">
          <el-option v-for="w in warehouses" :key="w.id" :label="w.warehouse_name" :value="w.id" />
        </el-select>
        <el-select v-model="filters.status" size="small" clearable placeholder="全部状态">
          <el-option v-for="(label, value) in statuses" :key="value" :label="label" :value="value" />
        </el-select>
        <el-button size="small" type="primary" @click="query">查询</el-button>
      </div>
      <div class="simple-table">
        <el-table v-loading="loading" :data="rows" border size="small">
          <el-table-column prop="stocktake_no" label="盘点单号" min-width="150" />
          <el-table-column label="范围" min-width="180">
            <template slot-scope="{ row }">{{ row.warehouse && row.warehouse.warehouse_name }} / {{ row.location ? row.location.location_name : '全部库位' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template slot-scope="{ row }"><el-tag size="mini" :type="statusType(row.stocktake_status)">{{ row.status_label }}</el-tag></template>
          </el-table-column>
          <el-table-column prop="line_count" label="明细行" width="80" />
          <el-table-column prop="difference_count" label="差异行" width="80" />
          <el-table-column label="盘盈 / 盘亏" width="130">
            <template slot-scope="{ row }"><span class="diff-gain">+{{ num(row.gain_qty) }}</span> / <span class="diff-loss">-{{ num(row.loss_qty) }}</span></template>
          </el-table-column>
          <el-table-column prop="stocktake_date" label="盘点日期" width="110" />
          <el-table-column prop="created_by_name" label="制单人" width="100" />
          <el-table-column label="操作" width="110" fixed="right">
            <template slot-scope="{ row }">
              <el-button type="text" size="small" @click="openDetail(row.id)">{{ row.stocktake_status === 'counting' && $can('inventory.stocktake.create') ? '录入实盘' : '详情' }}</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
      <div class="simple-pager">
        <el-pagination background layout="total, prev, pager, next" :current-page="page" :page-size="pageSize" :total="total" @current-change="changePage" />
      </div>
    </section>

    <el-dialog title="新建盘点" :visible.sync="createVisible" :width="dialogWidth('480px')" append-to-body>
      <el-form ref="createForm" :model="createForm" :rules="createRules" label-width="80px" size="small">
        <el-form-item label="盘点范围">
          <el-radio-group v-model="createForm.scope" @change="createForm.location_id = null">
            <el-radio label="location">按库位</el-radio><el-radio label="warehouse">整仓</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="仓库" prop="warehouse_id">
          <el-select v-model="createForm.warehouse_id" filterable placeholder="选择仓库" style="width: 100%" @change="createForm.location_id = null">
            <el-option v-for="w in warehouses" :key="w.id" :label="`${w.warehouse_code} ${w.warehouse_name}`" :value="w.id" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="createForm.scope === 'location'" label="库位" prop="location_id" :rules="[{ required: true, message: '请选择盘点库位', trigger: 'change' }]">
          <el-select v-model="createForm.location_id" clearable filterable :disabled="!createForm.warehouse_id" placeholder="请选择盘点库位" style="width: 100%">
            <el-option v-for="l in locationsOf(createForm.warehouse_id)" :key="l.id" :label="`${l.location_code} ${l.location_name}`" :value="l.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="createForm.remark" type="textarea" :rows="2" maxlength="500" />
        </el-form-item>
      </el-form>
      <el-alert :title="createForm.scope === 'warehouse' ? '整仓盘点将暂停该仓库所有库位的领料、发货和入库，完成或取消后恢复。' : '开始盘点后，仅所选库位暂停出入库，其他库位可正常作业；完成或取消后恢复。'" type="warning" show-icon :closable="false" />
      <span slot="footer">
        <el-button size="small" @click="createVisible = false">取消</el-button>
        <el-button size="small" type="primary" :loading="saving" @click="submitCreate">开始盘点</el-button>
      </span>
    </el-dialog>

    <el-dialog :title="detail ? `盘点单 ${detail.stocktake_no}` : '盘点单'" :visible.sync="detailVisible" :width="dialogWidth('1100px')" :before-close="closeDetail" append-to-body>
      <div v-if="detail" v-loading="detailLoading">
        <el-alert v-if="detail.stocktake_status === 'counting'" :title="`${detail.warehouse ? detail.warehouse.warehouse_name : ''} / ${detail.location ? detail.location.location_name : '全部库位'} 正在盘点，范围内暂停出入库，完成或取消后解除冻结。`" type="warning" show-icon :closable="false" />
        <div class="simple-meta">
          <div><span>范围：</span>{{ detail.warehouse && detail.warehouse.warehouse_name }} / {{ detail.location ? detail.location.location_name : '全部库位' }}</div>
          <div><span>状态：</span>{{ detail.status_label }}</div>
          <div><span>制单：</span>{{ detail.created_by_name || '—' }} {{ detail.stocktake_date }}</div>
          <div v-if="adjustmentNumbers"><span>差异调整单：</span>{{ adjustmentNumbers }}</div>
          <div v-if="detail.remark"><span>备注：</span>{{ detail.remark }}</div>
        </div>
        <div v-if="detail.summary" class="stocktake-progress">
          <span>已盘 <b>{{ detail.summary.counted_lines }}</b> / 共 {{ detail.summary.total_lines }} 行</span>
          <span>未盘 {{ detail.summary.uncounted_lines }} 行</span>
          <span>有差异 {{ detail.summary.diff_lines }} 行</span>
          <span>盘盈 {{ num(detail.summary.gain_qty) }}</span>
          <span>盘亏 {{ num(detail.summary.loss_qty) }}</span>
          <span>差异金额 {{ num(detail.summary.difference_amount) }}</span>
          <span v-if="draftCount" class="stocktake-draft">有 {{ draftCount }} 行修改尚未保存</span>
        </div>
        <div class="simple-filters stocktake-line-filters">
          <el-input v-model.trim="lineFilters.keyword" size="small" clearable placeholder="物料编码 / 名称 / 批次" :disabled="detailLoading || saving" @keyup.enter.native="filterLines" @clear="filterLines" />
          <el-select v-model="lineFilters.location_id" size="small" clearable filterable placeholder="全部库位" :disabled="detailLoading || saving" @change="filterLines">
            <el-option v-for="l in detail.location_options || []" :key="l.id" :label="`${l.location_code} ${l.location_name}`" :value="l.id" />
          </el-select>
          <el-radio-group v-model="lineFilters.line_status" size="small" :disabled="detailLoading || saving" @change="filterLines">
            <el-radio-button label="">全部</el-radio-button>
            <el-radio-button label="uncounted">只看未盘</el-radio-button>
            <el-radio-button label="diff">只看差异</el-radio-button>
          </el-radio-group>
          <el-button size="small" type="primary" plain :disabled="detailLoading || saving" @click="filterLines">查询</el-button>
        </div>
        <el-table :data="detail.lines" border size="small" max-height="460">
          <el-table-column label="物料" min-width="180">
            <template slot-scope="{ row }">{{ row.item && row.item.item_code }} {{ row.item && row.item.item_name }}</template>
          </el-table-column>
          <el-table-column label="库位" min-width="110">
            <template slot-scope="{ row }">{{ row.location && row.location.location_name }}</template>
          </el-table-column>
          <el-table-column prop="batch_no" label="批次" min-width="110" />
          <el-table-column label="账面数" width="100">
            <template slot-scope="{ row }">{{ num(row.book_qty) }} {{ row.unit && row.unit.unit_name }}</template>
          </el-table-column>
          <el-table-column label="实盘数" width="150">
            <template slot-scope="{ row }">
              <el-input-number v-if="editable" v-model="row.counted_qty" :disabled="saving || detailLoading" :min="0" :precision="4" :controls="false" size="mini" style="width: 120px" />
              <span v-else>{{ row.counted_qty === null ? '—' : num(row.counted_qty) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="差异" width="100">
            <template slot-scope="{ row }">
              <span :class="diffClass(row)">{{ diffText(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="备注" min-width="140">
            <template slot-scope="{ row }">
              <el-input v-if="editable" v-model="row.remark" :disabled="saving || detailLoading" size="mini" maxlength="500" />
              <span v-else>{{ row.remark || '—' }}</span>
            </template>
          </el-table-column>
        </el-table>
        <div class="simple-pager">
          <el-pagination background layout="total, prev, pager, next" :current-page="detailPage" :page-size="10" :total="detailTotal" :disabled="detailLoading || saving" @current-change="changeDetailPage" />
        </div>
      </div>
      <span slot="footer">
        <el-button size="small" :disabled="saving || detailLoading" @click="closeDetail()">关闭</el-button>
        <template v-if="detail && detail.stocktake_status === 'counting'">
          <el-button v-if="$can('inventory.stocktake.cancel')" size="small" type="danger" plain @click="cancel">取消盘点</el-button>
          <el-button v-if="editable" size="small" type="primary" plain :loading="saving" @click="saveCounts">保存实盘数</el-button>
          <el-button v-if="$can('inventory.stocktake.complete')" size="small" type="success" :loading="saving" @click="complete">完成盘点</el-button>
        </template>
      </span>
    </el-dialog>
  </section>
</template>

<script>
import { listStocktakes, getStocktakeOptions, getStocktake, createStocktake, saveStocktakeCounts, completeStocktake, cancelStocktake } from '@/api/erp/inventory'
import './inventory-simple.css'

export default {
  name: 'InventoryStocktakeList',
  data() {
    return {
      loading: false,
      saving: false,
      rows: [],
      total: 0,
      page: 1,
      pageSize: 10,
      filters: { keyword: '', warehouse_id: null, status: '' },
      warehouses: [],
      statuses: {},
      createVisible: false,
      createForm: { scope: 'location', warehouse_id: null, location_id: null, remark: '' },
      createRules: { warehouse_id: [{ required: true, message: '请选择仓库', trigger: 'change' }] },
      detailVisible: false,
      detailLoading: false,
      detail: null,
      detailPage: 1,
      detailTotal: 0,
      draftCounts: {},
      originalCounts: {},
      lineFilters: { keyword: '', location_id: null, line_status: '' },
      windowWidth: window.innerWidth
    }
  },
  computed: {
    adjustmentNumbers() {
      return this.detail ? (this.detail.adjustments || [this.detail.adjustment]).filter(Boolean).map(row => row.adjustment_no).join('、') : ''
    },
    draftCount() { return Object.keys(this.draftCounts).length },
    editable() {
      return !!this.detail && this.detail.stocktake_status === 'counting' && this.$can('inventory.stocktake.create')
    }
  },
  created() {
    this.loadOptions()
    this.load()
    // 从各页面的盘点中提示条跳转过来时直接打开对应盘点单。
    if (Number(this.$route.query.id) > 0) this.openDetail(Number(this.$route.query.id))
    window.addEventListener('resize', this.onResize)
  },
  beforeDestroy() {
    window.removeEventListener('resize', this.onResize)
  },
  methods: {
    onResize() { this.windowWidth = window.innerWidth },
    dialogWidth(width) { return this.windowWidth < 768 ? '96%' : width },
    num(value) {
      const n = Number(value || 0)
      return Number.isInteger(n) ? String(n) : String(Number(n.toFixed(4)))
    },
    statusType(status) { return { counting: 'warning', completed: 'success', cancelled: 'info' }[status] || '' },
    locationsOf(warehouseId) {
      const warehouse = this.warehouses.find(w => w.id === warehouseId)
      return warehouse ? warehouse.locations : []
    },
    diffValue(row) {
      if (row.counted_qty === null || row.counted_qty === undefined) return null
      return Number(row.counted_qty) - Number(row.book_qty)
    },
    diffText(row) {
      const diff = this.diffValue(row)
      if (diff === null) return '—'
      return diff > 0 ? `+${this.num(diff)}` : this.num(diff)
    },
    diffClass(row) {
      const diff = this.diffValue(row)
      return diff > 0 ? 'diff-gain' : diff < 0 ? 'diff-loss' : ''
    },
    fail(error) { this.$message.error((error && error.userMessage) || '操作失败') },
    async loadOptions() {
      try {
        const { data } = await getStocktakeOptions()
        this.warehouses = data.data.warehouses || []
        this.statuses = data.data.stocktake_statuses || {}
      } catch (e) { this.fail(e) }
    },
    async load() {
      this.loading = true
      try {
        const { data } = await listStocktakes({ ...this.filters, page: this.page, per_page: this.pageSize })
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
    openCreate() {
      this.createForm = { scope: 'location', warehouse_id: null, location_id: null, remark: '' }
      this.createVisible = true
      this.$nextTick(() => this.$refs.createForm && this.$refs.createForm.clearValidate())
    },
    submitCreate() {
      this.$refs.createForm.validate(async valid => {
        if (!valid) return
        if (this.createForm.scope === 'warehouse') {
          try { await this.$confirm('整仓盘点期间，该仓库所有出入库（含生产领料、发货）都会被拦住，建议按库位分批盘点。确认开始整仓盘点？', '确认整仓盘点', { type: 'warning' }) } catch (e) { return }
        }
        this.saving = true
        try {
          const { data } = await createStocktake(this.createForm)
          this.$message.success(data.message || '盘点单已创建')
          this.createVisible = false
          this.load()
          this.openDetail(data.data.id)
        } catch (e) {
          this.fail(e)
        } finally {
          this.saving = false
        }
      })
    },
    async openDetail(id) {
      this.detail = null
      this.detailPage = 1
      this.draftCounts = {}
      this.originalCounts = {}
      this.lineFilters = { keyword: '', location_id: null, line_status: '' }
      this.detailVisible = true
      await this.loadDetailPage(id, 1)
    },
    countInput(line) {
      return { id: line.id, counted_qty: line.counted_qty == null ? null : Number(line.counted_qty), remark: line.remark || null }
    },
    captureEdits() {
      if (!this.detail || !this.editable) return
      this.detail.lines.forEach(line => {
        const input = this.countInput(line)
        if (JSON.stringify(input) === JSON.stringify(this.originalCounts[line.id])) this.$delete(this.draftCounts, line.id)
        else this.$set(this.draftCounts, line.id, input)
      })
    },
    async closeDetail(done) {
      if (this.saving || this.detailLoading) return
      this.captureEdits()
      if (Object.keys(this.draftCounts).length) {
        try { await this.$confirm('有尚未保存的实盘数，关闭将放弃这些修改。确认关闭？', '未保存修改', { type: 'warning' }) } catch (e) { return }
      }
      if (typeof done === 'function') done()
      else this.detailVisible = false
    },
    // 切换筛选与翻页一样先暂存本页修改，草稿跨筛选保留，统一保存。
    async filterLines() {
      if (!this.detail || this.detailLoading || this.saving) return
      this.captureEdits()
      await this.loadDetailPage(this.detail.id, 1)
    },
    async changeDetailPage(page) {
      if (this.detailLoading || this.saving) return
      this.captureEdits()
      await this.loadDetailPage(this.detail.id, page)
    },
    async loadDetailPage(id, page) {
      this.detailLoading = true
      try {
        const filters = Object.fromEntries(Object.entries(this.lineFilters).filter(([, value]) => value !== '' && value !== null))
        const { data } = await getStocktake(id, { ...filters, page, per_page: 10 })
        // 留有跨页修改时保留原版本，服务端拒绝覆盖他人的新保存。
        if (this.detail && Object.keys(this.draftCounts).length) data.data.business_version = this.detail.business_version
        data.data.lines.forEach(line => {
          if (!this.draftCounts[line.id]) this.$set(this.originalCounts, line.id, this.countInput(line))
          if (this.draftCounts[line.id]) Object.assign(line, this.draftCounts[line.id])
          line.counted_qty = line.counted_qty === null ? undefined : Number(line.counted_qty)
        })
        this.detail = data.data
        this.detailPage = data.data.lines_pagination.current_page
        this.detailTotal = data.data.lines_pagination.total
      } catch (e) {
        this.fail(e)
      } finally {
        this.detailLoading = false
      }
    },
    async persistCounts() {
      this.captureEdits()
      const lines = Object.values(this.draftCounts)
      if (!lines.length) return
      const { data } = await saveStocktakeCounts(this.detail.id, lines, this.detail.business_version)
      this.detail.business_version = data.data.business_version
      lines.forEach(line => this.$set(this.originalCounts, line.id, { ...line }))
      this.draftCounts = {}
    },
    async saveCounts() {
      this.saving = true
      try {
        await this.persistCounts()
        this.$message.success('实盘数已保存')
        this.load()
        await this.loadDetailPage(this.detail.id, this.detailPage)
      } catch (e) {
        this.fail(e)
      } finally {
        this.saving = false
      }
    },
    async complete() {
      try {
        await this.$confirm('完成后差异将生成“盘点差异”调整单并立即过账，同时解除冻结。确认完成？', '完成盘点', { type: 'warning' })
      } catch (e) { return }
      this.saving = true
      try {
        if (this.editable) await this.persistCounts()
        await completeStocktake(this.detail.id)
        this.$message.success('盘点已完成')
        this.openDetail(this.detail.id)
        this.load()
      } catch (e) {
        this.fail(e)
      } finally {
        this.saving = false
      }
    },
    async cancel() {
      try {
        await this.$confirm('取消后不处理任何差异，并解除冻结。确认取消？', '取消盘点', { type: 'warning' })
      } catch (e) { return }
      try {
        await cancelStocktake(this.detail.id)
        this.$message.success('盘点已取消')
        this.openDetail(this.detail.id)
        this.load()
      } catch (e) { this.fail(e) }
    }
  }
}
</script>

<style scoped>
.stocktake-progress {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  margin: 8px 0;
  color: #606266;
  font-size: 13px;
}
.stocktake-draft {
  color: #e6a23c;
}
.stocktake-line-filters {
  margin-bottom: 8px;
}
</style>
