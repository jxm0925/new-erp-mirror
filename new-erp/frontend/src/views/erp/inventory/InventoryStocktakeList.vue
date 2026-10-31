<template>
  <section class="stocktake-page">
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-s-claim" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">库存盘点工作台</h1>
            <el-tag size="small" type="success" effect="plain" class="total-tag">共 {{ stats.total || total }} 张盘点单</el-tag>
            <el-tag v-if="stats.counting > 0" size="small" type="warning" effect="plain" class="sub-total-tag">
              {{ stats.counting }} 张盘点进行中（库位冻结）
            </el-tag>
          </div>
          <p class="page-desc">开盘即冻结所选仓库或库位的出入库；录入实盘数后一键完成盘点，系统自动生成“盘点差异”调整单并过账。</p>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" :loading="loading" @click="load">刷新</el-button>
        <el-button v-if="$can('inventory.stocktake.create')" size="small" type="success" class="btn-theme-create" icon="el-icon-plus" @click="openCreate">新建盘点</el-button>
      </div>
    </header>

    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>建议优先采用「按库位」分批盘点，仅冻结当前盘点库位，不影响其他库位正常领料、发货与入库作业；盘点完成或取消后自动解除冻结。</span>
    </div>

    <div class="metric-overview-grid">
      <article class="metric-card" :class="{ active: !filters.status && !filters.has_diff }" @click="quickFilter('', false)">
        <span class="metric-icon bg-green"><i class="el-icon-document" /></span>
        <div class="metric-body">
          <span class="metric-label">全部盘点单</span>
          <strong class="metric-value font-tabular">{{ stats.total || total }}</strong>
        </div>
      </article>
      <article class="metric-card" :class="{ active: filters.status === 'counting' }" @click="quickFilter('counting', false)">
        <span class="metric-icon bg-orange"><i class="el-icon-loading" /></span>
        <div class="metric-body">
          <span class="metric-label">盘点进行中（冻结中）</span>
          <strong class="metric-value font-tabular text-orange">{{ stats.counting || 0 }}</strong>
        </div>
      </article>
      <article class="metric-card" :class="{ active: filters.status === 'completed' && !filters.has_diff }" @click="quickFilter('completed', false)">
        <span class="metric-icon bg-teal"><i class="el-icon-circle-check" /></span>
        <div class="metric-body">
          <span class="metric-label">已完成过账</span>
          <strong class="metric-value font-tabular text-green">{{ stats.completed || 0 }}</strong>
        </div>
      </article>
      <article class="metric-card" :class="{ active: !!filters.has_diff }" @click="quickFilter('', true)">
        <span class="metric-icon bg-red"><i class="el-icon-warning-outline" /></span>
        <div class="metric-body">
          <span class="metric-label">存在盘点差异单</span>
          <strong class="metric-value font-tabular text-red">{{ stats.diff_orders || 0 }}</strong>
        </div>
      </article>
    </div>

    <section class="board-card">
      <div class="filter-panel">
        <div class="filter-item">
          <span class="filter-label">关键词</span>
          <el-input v-model.trim="filters.keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="盘点单号 / 仓库 / 备注" @keyup.enter.native="query" @clear="query" />
        </div>
        <div class="filter-item">
          <span class="filter-label">盘点仓库</span>
          <el-select v-model="filters.warehouse_id" size="small" clearable filterable placeholder="全部仓库" @change="query">
            <el-option v-for="w in warehouses" :key="w.id" :label="`${w.warehouse_code} ${w.warehouse_name}`" :value="w.id" />
          </el-select>
        </div>
        <div class="filter-item">
          <span class="filter-label">单据状态</span>
          <el-select v-model="filters.status" size="small" clearable placeholder="全部状态" @change="query">
            <el-option v-for="(label, value) in statuses" :key="value" :label="label" :value="value" />
          </el-select>
        </div>
        <div class="filter-actions">
          <el-button size="small" type="success" class="btn-theme-create" icon="el-icon-search" @click="query">查询</el-button>
          <el-button size="small" icon="el-icon-refresh-left" @click="resetFilters">重置</el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="rows" border stripe fit size="small" class="enterprise-table" empty-text="暂无盘点单记录" @row-dblclick="row => openDetail(row.id)">
        <el-table-column prop="stocktake_no" label="盘点单号" min-width="155" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <div class="doc-cell">
              <span class="code-chip font-tabular" @click="openDetail(row.id)">{{ row.stocktake_no }}</span>
              <small v-if="row.remark" class="cell-sub">{{ row.remark }}</small>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="盘点范围（仓库 / 库位）" min-width="200" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <div class="scope-cell">
              <strong>{{ (row.warehouse && row.warehouse.warehouse_name) || '—' }}</strong>
              <el-tag size="mini" :type="row.location ? 'info' : 'warning'" effect="plain">
                {{ row.location ? `${row.location.location_code || ''} ${row.location.location_name}`.trim() : '整仓盘点' }}
              </el-tag>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="实盘录入进度" min-width="175">
          <template slot-scope="{ row }">
            <div class="progress-cell">
              <div class="progress-text font-tabular">
                <span>已盘 <b>{{ countedLines(row) }}</b> / {{ row.line_count || 0 }} 行</span>
                <span>{{ progressPercent(row) }}%</span>
              </div>
              <div class="mini-progress-bar">
                <i :style="{ width: `${progressPercent(row)}%` }" :class="progressPercent(row) >= 100 ? 'is-done' : ''" />
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="差异行数 / 盘盈盘亏" min-width="175">
          <template slot-scope="{ row }">
            <div class="diff-cell font-tabular">
              <span v-if="Number(row.difference_count) > 0" class="diff-badge">差异 {{ row.difference_count }} 行</span>
              <span v-else class="text-muted">无差异</span>
              <div class="diff-nums">
                <span class="diff-gain">盈 +{{ num(row.gain_qty) }}</span>
                <span class="sep">/</span>
                <span class="diff-loss">亏 -{{ num(row.loss_qty) }}</span>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="96" align="center">
          <template slot-scope="{ row }">
            <el-tag size="mini" :type="statusType(row.stocktake_status)">{{ row.status_label }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="盘点日期 / 制单人" min-width="140" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <div class="meta-cell">
              <span class="font-tabular">{{ row.stocktake_date || '—' }}</span>
              <small>{{ row.created_by_name || '系统' }}</small>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="180" align="center" fixed="right">
          <template slot-scope="{ row }">
            <div class="row-actions">
              <el-button type="text" size="mini" class="action-link" :icon="row.stocktake_status === 'counting' ? 'el-icon-edit-outline' : 'el-icon-view'" @click="openDetail(row.id)">
                {{ row.stocktake_status === 'counting' && $can('inventory.stocktake.create') ? '录入实盘' : '查看详情' }}
              </el-button>
              <el-button
                v-if="row.stocktake_status === 'counting' && $can('inventory.stocktake.cancel')"
                type="text"
                size="mini"
                class="danger-link"
                @click="cancelFromRow(row)"
              >取消</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager-bar">
        <span class="pager-hint">提示：双击任意行可快速打开盘点明细与实盘录入工作台</span>
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :current-page="page"
          :page-size="pageSize"
          :page-sizes="[10, 20, 50, 100]"
          :total="total"
          @current-change="changePage"
          @size-change="changeSize"
        />
      </div>
    </section>

    <el-dialog title="新建库存盘点单" :visible.sync="createVisible" :width="dialogWidth('520px')" append-to-body :close-on-click-modal="false">
      <el-form ref="createForm" :model="createForm" :rules="createRules" label-width="88px" size="small">
        <el-form-item label="盘点粒度">
          <el-radio-group v-model="createForm.scope" @change="createForm.location_id = null">
            <el-radio label="location">按库位盘点（推荐）</el-radio>
            <el-radio label="warehouse">整仓盘点</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="盘点仓库" prop="warehouse_id">
          <el-select v-model="createForm.warehouse_id" filterable placeholder="请选择实体仓库" style="width: 100%" @change="createForm.location_id = null">
            <el-option v-for="w in warehouses" :key="w.id" :label="`${w.warehouse_code} ${w.warehouse_name}`" :value="w.id" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="createForm.scope === 'location'" label="盘点库位" prop="location_id" :rules="[{ required: true, message: '请选择盘点库位', trigger: 'change' }]">
          <el-select v-model="createForm.location_id" clearable filterable :disabled="!createForm.warehouse_id" placeholder="请选择要盘点的具体库位" style="width: 100%">
            <el-option v-for="l in locationsOf(createForm.warehouse_id)" :key="l.id" :label="`${l.location_code} ${l.location_name}`" :value="l.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="盘点备注">
          <el-input v-model="createForm.remark" type="textarea" :rows="2" maxlength="500" placeholder="可填写本次盘点原因或班组说明（选填）" />
        </el-form-item>
      </el-form>
      <el-alert
        :title="createForm.scope === 'warehouse' ? '整仓盘点将暂停该仓库所有库位的领料、发货和入库，完成或取消后恢复。' : '开始盘点后，仅所选库位暂停出入库，同仓库其他库位可正常作业；完成或取消后恢复。'"
        type="warning"
        show-icon
        :closable="false"
      />
      <span slot="footer">
        <el-button size="small" @click="createVisible = false">取消</el-button>
        <el-button size="small" type="success" class="btn-theme-create" :loading="saving" @click="submitCreate">开始盘点并冻结</el-button>
      </span>
    </el-dialog>

    <stocktake-entry-dialog :visible.sync="entryVisible" :stocktake="detail" :line="entryLine" :editable="editable" @saved="entrySaved" />

    <el-dialog :title="detail ? `盘点工作台 · ${detail.stocktake_no}` : '盘点单详情'" :visible.sync="detailVisible" :width="dialogWidth('1140px')" :before-close="closeDetail" append-to-body :close-on-click-modal="false">
      <div v-if="detail" v-loading="detailLoading" class="detail-dialog-body">
        <el-alert
          v-if="detail.stocktake_status === 'counting'"
          class="freeze-banner"
          :title="`${detail.warehouse ? detail.warehouse.warehouse_name : ''} / ${detail.location ? detail.location.location_name : '全部库位'} 正在盘点中，范围内暂停出入库，完成或取消后自动解除冻结。`"
          type="warning"
          show-icon
          :closable="false"
        />

        <div class="detail-meta-strip">
          <div class="meta-kv"><span>盘点范围</span><strong>{{ detail.warehouse && detail.warehouse.warehouse_name }} / {{ detail.location ? detail.location.location_name : '全部库位（整仓）' }}</strong></div>
          <div class="meta-kv"><span>单据状态</span><el-tag size="mini" :type="statusType(detail.stocktake_status)">{{ detail.status_label }}</el-tag></div>
          <div class="meta-kv"><span>制单信息</span><strong>{{ detail.created_by_name || '—' }} · {{ detail.stocktake_date }}</strong></div>
          <div v-if="adjustmentNumbers" class="meta-kv"><span>生成差异调整单</span><strong class="code-text">{{ adjustmentNumbers }}</strong></div>
          <div v-if="detail.remark" class="meta-kv"><span>备注说明</span><strong>{{ detail.remark }}</strong></div>
        </div>

        <div v-if="detail.summary" class="detail-kpi-strip">
          <div class="kpi-box">
            <span>总明细行</span>
            <strong class="font-tabular">{{ detail.summary.total_lines }}</strong>
          </div>
          <div class="kpi-box">
            <span>已盘行数</span>
            <strong class="font-tabular text-green">{{ detail.summary.counted_lines }}</strong>
          </div>
          <div class="kpi-box">
            <span>未盘行数</span>
            <strong class="font-tabular" :class="detail.summary.uncounted_lines > 0 ? 'text-orange' : ''">{{ detail.summary.uncounted_lines }}</strong>
          </div>
          <div class="kpi-box">
            <span>有差异行</span>
            <strong class="font-tabular" :class="detail.summary.diff_lines > 0 ? 'text-red' : ''">{{ detail.summary.diff_lines }}</strong>
          </div>
          <div class="kpi-box">
            <span>盘盈 / 盘亏数量</span>
            <strong class="font-tabular"><em class="diff-gain">+{{ num(detail.summary.gain_qty) }}</em> / <em class="diff-loss">-{{ num(detail.summary.loss_qty) }}</em></strong>
          </div>
          <div class="kpi-box">
            <span>差异金额合计</span>
            <strong class="font-tabular">¥{{ money(detail.summary.difference_amount) }}</strong>
          </div>
        </div>

        <div class="line-filter-bar">
          <div class="line-filter-left">
            <el-input v-model.trim="lineFilters.keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="搜索物料编码 / 名称 / 批次号" :disabled="detailLoading || saving" style="width: 240px" @keyup.enter.native="filterLines" @clear="filterLines" />
            <el-select v-model="lineFilters.location_id" size="small" clearable filterable placeholder="全部库位" :disabled="detailLoading || saving" style="width: 170px" @change="filterLines">
              <el-option v-for="l in detail.location_options || []" :key="l.id" :label="`${l.location_code} ${l.location_name}`" :value="l.id" />
            </el-select>
            <el-radio-group v-model="lineFilters.line_status" size="small" :disabled="detailLoading || saving" @change="filterLines">
              <el-radio-button label="">全部明细</el-radio-button>
              <el-radio-button label="uncounted">只看未盘 ({{ detail.summary ? detail.summary.uncounted_lines : 0 }})</el-radio-button>
              <el-radio-button label="diff">只看差异 ({{ detail.summary ? detail.summary.diff_lines : 0 }})</el-radio-button>
            </el-radio-group>
            <el-button size="small" type="success" class="btn-theme-create" icon="el-icon-search" :disabled="detailLoading || saving" @click="filterLines">筛选</el-button>
          </div>
          <div class="line-filter-right">
            <span v-if="draftCount" class="draft-pill"><i class="el-icon-edit" /> 当前有 {{ draftCount }} 行未保存修改</span>
            <el-button v-if="editable" size="small" plain icon="el-icon-check" :disabled="detailLoading || saving" @click="fillPageWithBookQty">本页未盘按账面数填充</el-button>
          </div>
        </div>

        <el-table :data="detail.lines" border stripe fit size="small" max-height="440" class="enterprise-table">
          <el-table-column label="物料编码 / 名称" min-width="210" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <div class="item-cell">
                <span class="code-text font-tabular">{{ row.item && row.item.item_code }}</span>
                <strong>{{ row.item && row.item.item_name }}</strong>
                <small v-if="row.item && row.item.spec" class="cell-sub">{{ row.item.spec }}</small>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="所在库位" min-width="115" show-overflow-tooltip>
            <template slot-scope="{ row }">{{ (row.location && row.location.location_name) || '—' }}</template>
          </el-table-column>
          <el-table-column prop="batch_no" label="批次号" min-width="125" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="font-tabular">{{ row.batch_no || '—' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="账面数量" width="110" align="right">
            <template slot-scope="{ row }">
              <strong class="font-tabular">{{ num(row.book_qty) }}</strong>
              <small class="unit-lbl">{{ row.unit && row.unit.unit_name }}</small>
            </template>
          </el-table-column>
          <el-table-column label="实盘数量" width="180">
            <template slot-scope="{ row }">
              <template v-if="row.count_mode && row.count_mode !== 'quantity'">
                <div class="serial-count-cell">
                  <strong class="font-tabular">{{ row.counted_qty == null ? '未盘' : num(row.counted_qty) }}</strong>
                  <el-button type="text" size="mini" class="action-link" :disabled="saving || detailLoading" @click="openEntries(row)">
                    {{ editable ? '逐件盘点' : '逐件明细' }}
                  </el-button>
                </div>
                <div v-if="row.entry_stats" class="entry-progress">
                  {{ row.count_mode === 'serial' ? '编号' : '实物' }}已确认 {{ row.entry_stats.present + row.entry_stats.missing }}/{{ row.entry_stats.book }}
                  <template v-if="row.entry_stats.new">，补录 {{ row.entry_stats.new }}</template>
                </div>
              </template>
              <el-input-number
                v-else-if="editable"
                v-model="row.counted_qty"
                :disabled="saving || detailLoading"
                :min="0"
                :precision="4"
                :controls="false"
                size="mini"
                placeholder="输入实盘数"
                style="width: 135px"
              />
              <span v-else class="font-tabular">{{ row.counted_qty == null ? '—' : num(row.counted_qty) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="盘点差异" width="105" align="right">
            <template slot-scope="{ row }">
              <span class="font-tabular" :class="diffClass(row)">{{ diffText(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="差异说明 / 备注" min-width="150">
            <template slot-scope="{ row }">
              <el-input v-if="editable" v-model="row.remark" :disabled="saving || detailLoading" size="mini" maxlength="500" placeholder="选填差异原因" />
              <span v-else>{{ row.remark || '—' }}</span>
            </template>
          </el-table-column>
        </el-table>

        <div class="pager-bar">
          <span class="pager-hint">翻页或切换筛选时自动暂存当前页已录入的实盘数</span>
          <el-pagination
            background
            layout="total, prev, pager, next"
            :current-page="detailPage"
            :page-size="10"
            :total="detailTotal"
            :disabled="detailLoading || saving"
            @current-change="changeDetailPage"
          />
        </div>
      </div>
      <span slot="footer">
        <el-button size="small" :disabled="saving || detailLoading" @click="closeDetail()">关闭</el-button>
        <template v-if="detail && detail.stocktake_status === 'counting'">
          <el-button v-if="$can('inventory.stocktake.cancel')" size="small" type="danger" plain @click="cancel">取消盘点</el-button>
          <el-button v-if="editable" size="small" class="btn-green-outline" :loading="saving" @click="saveCounts">保存实盘草稿</el-button>
          <el-button v-if="$can('inventory.stocktake.complete')" size="small" type="success" class="btn-theme-create" :loading="saving" @click="complete">完成盘点并过账</el-button>
        </template>
      </span>
    </el-dialog>
  </section>
</template>

<script>
import { listStocktakes, getStocktakeOptions, getStocktake, createStocktake, saveStocktakeCounts, completeStocktake, cancelStocktake } from '@/api/erp/inventory'
import StocktakeEntryDialog from './StocktakeEntryDialog.vue'

export default {
  name: 'InventoryStocktakeList',
  components: { StocktakeEntryDialog },
  data() {
    return {
      loading: false,
      saving: false,
      rows: [],
      total: 0,
      page: 1,
      pageSize: 10,
      stats: { total: 0, counting: 0, completed: 0, cancelled: 0, diff_orders: 0 },
      filters: { keyword: '', warehouse_id: null, status: '', has_diff: false },
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
      entryVisible: false,
      entryLine: null,
      windowWidth: window.innerWidth
    }
  },
  computed: {
    adjustmentNumbers() {
      return this.detail ? (this.detail.adjustments || [this.detail.adjustment]).filter(Boolean).map(row => row.adjustment_no).join('、') : ''
    },
    draftCount() {
      return Object.keys(this.draftCounts).length
    },
    editable() {
      return !!this.detail && this.detail.stocktake_status === 'counting' && this.$can('inventory.stocktake.create')
    }
  },
  created() {
    this.loadOptions()
    this.load()
    if (Number(this.$route.query.id) > 0) this.openDetail(Number(this.$route.query.id))
    window.addEventListener('resize', this.onResize)
  },
  beforeDestroy() {
    window.removeEventListener('resize', this.onResize)
  },
  methods: {
    onResize() {
      this.windowWidth = window.innerWidth
    },
    dialogWidth(width) {
      return this.windowWidth < 768 ? '96%' : width
    },
    num(value) {
      const n = Number(value || 0)
      return Number.isInteger(n) ? String(n) : String(Number(n.toFixed(4)))
    },
    money(value) {
      return Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    countedLines(row) {
      if (row.counted_lines_count !== undefined && row.counted_lines_count !== null) return Number(row.counted_lines_count)
      return row.stocktake_status === 'completed' ? Number(row.line_count || 0) : 0
    },
    progressPercent(row) {
      const total = Number(row.line_count || 0)
      if (!total) return 100
      return Math.min(100, Math.round((this.countedLines(row) / total) * 100))
    },
    statusType(status) {
      return { counting: 'warning', completed: 'success', cancelled: 'info' }[status] || ''
    },
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
    fail(error) {
      this.$message.error((error && error.userMessage) || '操作失败')
    },
    async loadOptions() {
      try {
        const { data } = await getStocktakeOptions()
        this.warehouses = data.data.warehouses || []
        this.statuses = data.data.stocktake_statuses || {}
      } catch (e) {
        this.fail(e)
      }
    },
    async load() {
      this.loading = true
      try {
        const params = {
          keyword: this.filters.keyword || undefined,
          warehouse_id: this.filters.warehouse_id || undefined,
          status: this.filters.status || undefined,
          has_diff: this.filters.has_diff ? 1 : undefined,
          page: this.page,
          per_page: this.pageSize
        }
        const { data } = await listStocktakes(params)
        this.rows = data.data || []
        this.total = data.total || 0
        if (data.stats) this.stats = data.stats
      } catch (e) {
        this.fail(e)
      } finally {
        this.loading = false
      }
    },
    query() {
      this.page = 1
      this.load()
    },
    resetFilters() {
      this.filters = { keyword: '', warehouse_id: null, status: '', has_diff: false }
      this.query()
    },
    quickFilter(status, hasDiff) {
      this.filters.status = status
      this.filters.has_diff = hasDiff
      this.query()
    },
    changePage(page) {
      this.page = page
      this.load()
    },
    changeSize(size) {
      this.pageSize = size
      this.page = 1
      this.load()
    },
    openCreate() {
      this.createForm = { scope: 'location', warehouse_id: null, location_id: null, remark: '' }
      this.createVisible = true
      this.$nextTick(() => this.$refs.createForm && this.$refs.createForm.clearValidate())
    },
    submitCreate() {
      this.$refs.createForm.validate(async valid => {
        if (!valid) return
        if (this.createForm.scope === 'warehouse') {
          try {
            await this.$confirm('整仓盘点期间，该仓库所有出入库（含生产领料、发货）都会被拦住，建议按库位分批盘点。确认开始整仓盘点？', '确认整仓盘点', { type: 'warning' })
          } catch (e) {
            return
          }
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
    fillPageWithBookQty() {
      if (!this.detail || !this.editable) return
      let filled = 0
      this.detail.lines.forEach(line => {
        if ((!line.count_mode || line.count_mode === 'quantity') && (line.counted_qty === null || line.counted_qty === undefined)) {
          this.$set(line, 'counted_qty', Number(line.book_qty || 0))
          filled++
        }
      })
      this.captureEdits()
      if (filled > 0) this.$message.success(`已将本页 ${filled} 行未盘数量按账面数填充，请核对后保存`)
      else this.$message.info('本页没有待填充的数量型未盘明细')
    },
    countInput(line) {
      if (line.count_mode && line.count_mode !== 'quantity') return { id: line.id, remark: line.remark || null }
      return { id: line.id, counted_qty: line.counted_qty == null ? null : Number(line.counted_qty), remark: line.remark || null }
    },
    openEntries(line) {
      this.entryLine = line
      this.entryVisible = true
    },
    async entrySaved(version) {
      this.detail.business_version = version
      await this.loadDetailPage(this.detail.id, this.detailPage)
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
        try {
          await this.$confirm('有尚未保存的实盘数，关闭将放弃这些修改。确认关闭？', '未保存修改', { type: 'warning' })
        } catch (e) {
          return
        }
      }
      if (typeof done === 'function') done()
      else this.detailVisible = false
    },
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
      } catch (e) {
        return
      }
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
        await this.$confirm('取消后本次实盘作废，仓库/库位立即解除冻结。确认取消？', '取消盘点', { type: 'warning' })
      } catch (e) {
        return
      }
      this.saving = true
      try {
        await cancelStocktake(this.detail.id)
        this.$message.success('盘点已取消')
        this.detailVisible = false
        this.load()
      } catch (e) {
        this.fail(e)
      } finally {
        this.saving = false
      }
    },
    async cancelFromRow(row) {
      try {
        await this.$confirm(`取消盘点单 ${row.stocktake_no} 后，本次实盘作废并立即解除库存冻结。确认取消？`, '取消盘点', { type: 'warning' })
        await cancelStocktake(row.id)
        this.$message.success('盘点已取消')
        this.load()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.fail(e)
      }
    }
  }
}
</script>

<style scoped>
.stocktake-page {
  min-height: calc(100vh - 58px);
  padding: 16px;
  background: #f4f6f8;
  color: #1f2d3d;
  box-sizing: border-box;
}

.page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 18px;
  margin-bottom: 12px;
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
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
  background: #eaf7f0;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
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
  color: #17233b;
}

.page-desc {
  margin: 4px 0 0;
  font-size: 12px;
  color: #728096;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #fff !important;
}

.btn-theme-create:hover,
.btn-theme-create:focus {
  background: #00743f !important;
  border-color: #00743f !important;
  color: #fff !important;
}

.btn-green-outline {
  color: #008b4b !important;
  border-color: #008b4b !important;
  background: #f0f9f4 !important;
}

.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  margin-bottom: 12px;
  font-size: 12px;
  line-height: 1.5;
  color: #4a5a70;
  background: #f0f9f4;
  border: 1px solid #ccebd9;
  border-radius: 6px;
}

.erp-page-tip i {
  font-size: 14px;
  color: #008b4b;
}

.metric-overview-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}

.metric-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.16s ease;
}

.metric-card:hover,
.metric-card.active {
  border-color: #008b4b;
  box-shadow: 0 2px 10px rgba(0, 139, 75, 0.08);
  background: #f9fdfb;
}

.metric-icon {
  width: 40px;
  height: 40px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}

.bg-green { background: #eaf7f0; color: #008b4b; }
.bg-orange { background: #fff7e6; color: #fa8c16; }
.bg-teal { background: #e6fffb; color: #08979c; }
.bg-red { background: #fff1f0; color: #e44d4d; }

.metric-body {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.metric-label {
  font-size: 12px;
  color: #6b778c;
}

.metric-value {
  font-size: 22px;
  font-weight: 700;
  color: #17233b;
}

.text-green { color: #008b4b !important; }
.text-orange { color: #fa8c16 !important; }
.text-red { color: #e44d4d !important; }
.text-muted { color: #94a3b8; }

.board-card {
  padding: 14px 16px;
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
}

.filter-panel {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  margin-bottom: 12px;
  background: #f8fafc;
  border: 1px solid #e9eef5;
  border-radius: 6px;
}

.filter-item {
  display: flex;
  align-items: center;
  gap: 8px;
}

.filter-label {
  font-size: 12px;
  font-weight: 500;
  color: #5b687c;
  white-space: nowrap;
}

.filter-item .el-input,
.filter-item .el-select {
  width: 195px;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.doc-cell,
.scope-cell,
.meta-cell,
.item-cell {
  display: flex;
  flex-direction: column;
  gap: 3px;
  line-height: 1.35;
}

.code-chip {
  display: inline-block;
  align-self: flex-start;
  padding: 1px 7px;
  font-size: 12px;
  font-weight: 600;
  color: #008b4b;
  background: #eaf7f0;
  border: 1px solid #ccebd9;
  border-radius: 4px;
  cursor: pointer;
}

.code-text {
  font-size: 12px;
  font-weight: 600;
  color: #008b4b;
}

.cell-sub,
.meta-cell small {
  font-size: 11px;
  color: #8490a2;
}

.progress-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.progress-text {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  color: #4a5a70;
}

.progress-text b {
  color: #008b4b;
}

.mini-progress-bar {
  height: 6px;
  background: #edf2f7;
  border-radius: 3px;
  overflow: hidden;
}

.mini-progress-bar i {
  display: block;
  height: 100%;
  background: #fa8c16;
  border-radius: 3px;
}

.mini-progress-bar i.is-done {
  background: #008b4b;
}

.diff-cell {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.diff-badge {
  display: inline-block;
  align-self: flex-start;
  padding: 0 6px;
  font-size: 11px;
  font-weight: 600;
  color: #d46b08;
  background: #fff7e6;
  border: 1px solid #ffd591;
  border-radius: 4px;
}

.diff-nums {
  font-size: 12px;
}

.diff-nums .sep {
  margin: 0 4px;
  color: #cbd5e1;
}

.diff-gain {
  color: #008b4b;
  font-weight: 600;
  font-style: normal;
}

.diff-loss {
  color: #e44d4d;
  font-weight: 600;
  font-style: normal;
}

.row-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}

.action-link {
  padding: 0;
  color: #008b4b !important;
  font-weight: 500;
}

.danger-link {
  padding: 0;
  color: #e44d4d !important;
}

.pager-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  padding-top: 12px;
}

.pager-hint {
  font-size: 12px;
  color: #8490a2;
}

.freeze-banner {
  margin-bottom: 12px;
}

.detail-meta-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 24px;
  padding: 10px 14px;
  margin-bottom: 12px;
  background: #f8fafc;
  border: 1px solid #e9eef5;
  border-radius: 6px;
  font-size: 12px;
}

.meta-kv {
  display: flex;
  align-items: center;
  gap: 6px;
}

.meta-kv span {
  color: #728096;
}

.meta-kv strong {
  color: #17233b;
}

.detail-kpi-strip {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 12px;
}

.kpi-box {
  padding: 10px 12px;
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
}

.kpi-box span {
  display: block;
  font-size: 12px;
  color: #728096;
}

.kpi-box strong {
  display: block;
  margin-top: 4px;
  font-size: 16px;
  color: #17233b;
}

.line-filter-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  padding: 10px 12px;
  margin-bottom: 10px;
  background: #f8fafc;
  border: 1px solid #e9eef5;
  border-radius: 6px;
}

.line-filter-left,
.line-filter-right {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.draft-pill {
  padding: 4px 10px;
  font-size: 12px;
  font-weight: 600;
  color: #d46b08;
  background: #fff7e6;
  border: 1px solid #ffd591;
  border-radius: 4px;
}

.unit-lbl {
  margin-left: 3px;
  font-size: 11px;
  color: #8490a2;
}

.serial-count-cell {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.entry-progress {
  margin-top: 2px;
  font-size: 11px;
  color: #64748b;
}

@media (max-width: 1100px) {
  .metric-overview-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .detail-kpi-strip {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .page-head {
    flex-direction: column;
    align-items: flex-start;
  }
  .metric-overview-grid,
  .detail-kpi-strip {
    grid-template-columns: 1fr;
  }
  .filter-item,
  .filter-item .el-input,
  .filter-item .el-select {
    width: 100%;
  }
}
</style>
