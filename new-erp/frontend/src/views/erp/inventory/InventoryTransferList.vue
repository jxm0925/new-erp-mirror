<template>
  <section class="transfer-page">
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-sort" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">库存调拨</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag total-tag">共 {{ stats.total || total }} 张调拨单</el-tag>
            <el-tag v-if="stats.draft > 0" size="small" type="warning" effect="plain" class="sub-total-tag">
              {{ stats.draft }} 张草稿待办理
            </el-tag>
            <el-tag v-if="stats.in_transit > 0" size="small" type="warning" effect="plain" class="sub-total-tag" @click="quickStatus('in_transit')">{{ stats.in_transit }} 张在途待接收</el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="load">刷新</el-button>
        <el-button v-if="$can('inventory.transfer.create')" size="small" type="success" class="btn-theme-create" icon="el-icon-plus" :disabled="saving || actionSaving || pendingCommands.length > 0" @click="openForm()">新建调拨单</el-button>
      </div>
    </header>

    <el-alert v-if="pendingCommands.length" title="有调拨操作结果尚未确定，请先核对原操作结果。" type="warning" :closable="false" show-icon />
    <el-button v-for="job in pendingCommands" :key="job.action + ':' + job.id" size="small" :loading="actionSaving" @click="recover(job)">核对上次{{ actionLabel(job.action) }}结果</el-button>

    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>同仓移库一步完成；跨仓调出后进入在途，目标仓整单接收后才可用。接收按调出时冻结成本办理，短少或破损只记录异常、保持在途。</span>
    </div>

    <div class="metric-overview-grid">
      <article class="metric-card" :class="{ active: !filters.status }" @click="quickStatus('')">
        <span class="metric-icon bg-green"><i class="el-icon-files" /></span>
        <div class="metric-body">
          <span class="metric-label">全部调拨单</span>
          <strong class="metric-value font-tabular">{{ stats.total || total }}</strong>
        </div>
      </article>
      <article class="metric-card" :class="{ active: filters.status === 'draft' }" @click="quickStatus('draft')">
        <span class="metric-icon bg-orange"><i class="el-icon-edit-outline" /></span>
        <div class="metric-body">
          <span class="metric-label">草稿待办理</span>
          <strong class="metric-value font-tabular text-orange">{{ stats.draft || 0 }}</strong>
        </div>
      </article>
      <article class="metric-card" :class="{ active: filters.status === 'posted' }" @click="quickStatus('posted')">
        <span class="metric-icon bg-teal"><i class="el-icon-circle-check" /></span>
        <div class="metric-body">
          <span class="metric-label">已过账完成</span>
          <strong class="metric-value font-tabular text-green">{{ stats.posted || 0 }}</strong>
        </div>
      </article>
      <article class="metric-card" :class="{ active: filters.status === 'cancelled' }" @click="quickStatus('cancelled')">
        <span class="metric-icon bg-gray"><i class="el-icon-remove-outline" /></span>
        <div class="metric-body">
          <span class="metric-label">已取消单据</span>
          <strong class="metric-value font-tabular text-muted">{{ stats.cancelled || 0 }}</strong>
        </div>
      </article>
    </div>

    <section class="board-card">
      <div class="filter-panel">
        <div class="filter-item">
          <span class="filter-label">关键词</span>
          <el-input v-model.trim="filters.keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="调拨单号 / 物料 / 批次 / 备注" @keyup.enter.native="query" @clear="query" />
        </div>
        <div class="filter-item">
          <span class="filter-label">关联仓库</span>
          <paged-record-select v-model="filters.warehouse_id" entity="warehouses" :params="warehouseParams" title="选择调出或调入仓库" placeholder="全部仓库" @change="query" />
        </div>
        <div class="filter-item">
          <span class="filter-label">单据状态</span>
          <el-select v-model="filters.status" size="small" clearable placeholder="全部状态" @change="query">
            <el-option v-for="(label, value) in statuses" :key="value" :label="label" :value="value" />
          </el-select>
        </div>
        <div class="filter-item">
          <span class="filter-label">接收异常</span>
          <el-select v-model="filters.has_exception" size="small" clearable placeholder="全部" @change="query"><el-option label="有接收异常" :value="1" /><el-option label="无接收异常" :value="0" /></el-select>
        </div>
        <div class="filter-actions">
          <el-button size="small" type="success" class="btn-theme-create" icon="el-icon-search" @click="query">查询</el-button>
          <el-button size="small" icon="el-icon-refresh-left" @click="resetFilters">重置</el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="rows" border stripe fit size="small" class="enterprise-table" empty-text="暂无库存调拨记录" @row-dblclick="row => openDetail(row.id)">
        <el-table-column prop="transfer_no" label="调拨单号" min-width="155" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <div class="doc-cell">
              <span class="code-chip font-tabular" @click="openDetail(row.id)">{{ row.transfer_no }}</span>
              <small v-if="row.remark" class="cell-sub">{{ row.remark }}</small>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="调出仓库 → 调入仓库" min-width="210" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <div class="route-pill">
              <span class="wh-from">{{ (row.source_warehouse && row.source_warehouse.warehouse_name) || '—' }}</span>
              <i class="el-icon-right route-arrow" />
              <span class="wh-to">{{ (row.target_warehouse && row.target_warehouse.warehouse_name) || '—' }}</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="调拨物料概览" min-width="230" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <div class="items-cell">
              <strong class="items-summary-text">{{ row.items_summary || `共 ${row.lines_count || 0} 行调拨明细` }}</strong>
              <small class="font-tabular">
                共 {{ row.lines_count || 0 }} 行
                <template v-if="row.total_quantity"> · 合计数量 {{ num(row.total_quantity) }}</template>
              </small>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="94" align="center">
          <template slot-scope="{ row }">
            <el-tag size="mini" :type="statusType(row.transfer_status)">{{ row.status_label }}</el-tag>
            <div v-if="row.receipt_exception" class="cell-sub text-orange">{{ row.receipt_exception }}</div>
          </template>
        </el-table-column>

        <el-table-column label="调拨日期 / 经办人" min-width="155" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <div class="meta-cell">
              <span class="font-tabular">{{ row.transfer_date || '—' }}</span>
              <small>
                制单：{{ row.created_by_name || '—' }}
                <template v-if="row.posted_by_name"> · 过账：{{ row.posted_by_name }}</template>
              </small>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="220" align="center" fixed="right">
          <template slot-scope="{ row }">
            <div class="row-actions">
              <el-button type="text" size="mini" class="action-link" icon="el-icon-view" @click="openDetail(row.id)">详情</el-button>
              <template v-if="row.transfer_status === 'draft'">
                <el-button v-if="row.can_edit" type="text" size="mini" class="action-link" icon="el-icon-edit" :disabled="actionSaving || rowPending(row.id)" @click="openForm(row.id)">编辑</el-button>
                <el-button v-if="row.can_post" type="text" size="mini" class="action-link" icon="el-icon-circle-check" :disabled="actionSaving || rowPending(row.id)" @click="post(row)">{{ crossWarehouse(row) ? '调出' : '移库' }}</el-button>
                <el-button v-if="row.can_cancel" type="text" size="mini" class="muted-link" :disabled="actionSaving || rowPending(row.id)" @click="cancel(row)">取消</el-button>
                <el-button v-if="row.can_delete" type="text" size="mini" class="danger-link" :disabled="actionSaving || rowPending(row.id)" @click="remove(row)">删除</el-button>
              </template>
              <template v-if="row.transfer_status === 'in_transit'"><el-button v-if="row.can_confirm" type="text" size="mini" class="action-link" :disabled="actionSaving || rowPending(row.id)" @click="openDetail(row.id, true)">整单接收</el-button><el-button v-if="row.can_record_exception" type="text" size="mini" class="muted-link" :disabled="actionSaving || rowPending(row.id)" @click="recordException(row)">记录异常</el-button></template>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager-bar">
        <span class="pager-hint">提示：双击任意调拨单可查看完整物料、库位、批次、成本与序列号迁移明细</span>
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

    <el-dialog :title="form.id ? '编辑库存调拨单' : '新建库存调拨单'" :visible.sync="formVisible" :width="dialogWidth('1120px')" append-to-body :close-on-click-modal="false">
      <el-form ref="form" :model="form" :disabled="saving || formPending" label-width="84px" size="small" class="transfer-form">
        <div class="form-top-grid">
          <el-form-item label="调出仓库" required>
            <paged-record-select v-model="form.source_warehouse_id" entity="warehouses" :params="warehouseParams" title="选择调出仓库" placeholder="选择调出仓库" @change="changeSource" @select="sourceWarehouse = $event" />
          </el-form-item>
          <el-form-item label="调入仓库" required>
            <paged-record-select v-model="form.target_warehouse_id" entity="warehouses" :params="targetWarehouseParams" :disabled="!sourceWarehouse || !sourceWarehouse.management_scope" title="选择调入仓库" placeholder="请先选择调出仓库（自动匹配同管理范围）" @change="form.lines.forEach(l => { l.target_location_id = null })" />
          </el-form-item>
          <el-form-item label="调拨备注">
            <el-input v-model="form.remark" maxlength="500" placeholder="可填写调拨事由、关联单据或领用说明" />
          </el-form-item>
        </div>
      </el-form>

      <div class="lines-toolbar">
        <span class="lines-title">调拨物料明细（共 <b>{{ form.lines.length }}</b> 行）</span>
        <el-button size="mini" type="success" class="btn-theme-create" icon="el-icon-plus" :disabled="!form.source_warehouse_id || saving || formPending" @click="addLine">添加调拨行</el-button>
      </div>

      <el-table :data="form.lines" border stripe fit size="small" class="enterprise-table">
        <el-table-column type="index" label="#" width="46" align="center" />
        <el-table-column label="来源库存（物料 / 库位 / 批次 / 可用量）" min-width="270">
          <template slot-scope="{ row }">
            <paged-record-select v-model="row.inventory_balance_id" :request="listTransferBalances" :params="{ warehouse_id: form.source_warehouse_id }" :selected-rows.sync="balances" :label="balanceLabel" :columns="balanceColumns" :category-request="listSourceCategories" :disabled="!form.source_warehouse_id || saving || formPending" size="mini" title="选择来源库存" placeholder="选择物料 / 库位 / 批次" @change="row.serial_ids = []" />
          </template>
        </el-table-column>
        <el-table-column label="调入目标库位" min-width="175">
          <template slot-scope="{ row }">
            <paged-record-select v-model="row.target_location_id" entity="locations" :params="{ warehouse_id: form.target_warehouse_id, statuses: warehouseParams.statuses, location_purposes: ['general', 'finished'] }" :disabled="!form.target_warehouse_id || saving || formPending" size="mini" title="选择调入库位" placeholder="普通或成品库位" />
          </template>
        </el-table-column>
        <el-table-column label="调拨数量" width="145">
          <template slot-scope="{ row }">
            <el-input-number v-model="row.quantity" :disabled="saving || formPending" :min="0" :max="maxQty(row)" :precision="4" :controls="false" size="mini" placeholder="输入数量" style="width: 120px" />
          </template>
        </el-table-column>
        <el-table-column label="设备编号 / 序列号" min-width="210">
          <template slot-scope="{ row }">
            <template v-if="hasSerials(row)">
              <paged-record-select v-model="row.serial_ids" :disabled="saving || formPending" :request="listSourceSerials" :params="{ inventory_balance_id: row.inventory_balance_id, warehouse_id: form.source_warehouse_id, for_transfer: 1 }" :columns="[{ prop: 'serial_no', label: '序列号' }]" :label="serialLabel" multiple size="mini" title="选择序列号" :placeholder="serialRequired(row) ? '选择序列号（必选）' : '选择序列号（可选）'" @change="changeSerials(row)" />
              <div class="serial-hint">{{ serialHint(row) }}</div>
            </template>
            <span v-else class="text-muted">—</span>
          </template>
        </el-table-column>
        <el-table-column label="行备注" min-width="130">
          <template slot-scope="{ row }">
            <el-input v-model="row.remark" :disabled="saving || formPending" size="mini" maxlength="500" placeholder="选填" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="68" align="center">
          <template slot-scope="{ $index }">
            <el-button type="text" size="mini" class="danger-link" :disabled="form.lines.length <= 1 || saving || formPending" @click="form.lines.splice($index, 1)">移除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <span slot="footer">
        <el-button size="small" @click="formVisible = false">取消</el-button>
        <el-button size="small" class="btn-green-outline" :loading="saving" :disabled="formPending" @click="submit(false)">保存草稿</el-button>
        <el-button v-if="$can('inventory.transfer.post')" size="small" type="success" class="btn-theme-create" :loading="saving" :disabled="formPending" @click="submit(true)">{{ crossWarehouse(form) ? '保存并调出' : '保存并移库' }}</el-button>
      </span>
    </el-dialog>

    <el-dialog :title="detail ? `调拨单详情 · ${detail.transfer_no}` : '调拨单详情'" :visible.sync="detailVisible" :width="dialogWidth('1020px')" append-to-body :close-on-click-modal="false">
      <div v-if="detail">
        <div class="detail-meta-strip">
          <div class="meta-kv">
            <span>调拨方向</span>
            <strong>{{ detail.source_warehouse && detail.source_warehouse.warehouse_name }} → {{ detail.target_warehouse && detail.target_warehouse.warehouse_name }}</strong>
          </div>
          <div class="meta-kv">
            <span>状态</span>
            <el-tag size="mini" :type="statusType(detail.transfer_status)">{{ detail.status_label }}</el-tag>
          </div>
          <div class="meta-kv">
            <span>制单信息</span>
            <strong>{{ detail.created_by_name || '—' }} · {{ detail.transfer_date }}</strong>
          </div>
          <div v-if="detail.posted_by_name" class="meta-kv">
            <span>过账人</span>
            <strong>{{ detail.posted_by_name }}</strong>
          </div>
          <div v-if="detail.dispatched_at" class="meta-kv"><span>调出时间</span><strong>{{ dateTime(detail.dispatched_at) }}</strong></div>
          <div v-if="detail.confirmed_at" class="meta-kv"><span>接收时间</span><strong>{{ detail.confirmed_by_name || '—' }} · {{ dateTime(detail.confirmed_at) }}</strong></div>
          <div v-if="detail.remark" class="meta-kv">
            <span>备注</span>
            <strong>{{ detail.remark }}</strong>
          </div>
        </div>
        <el-alert v-if="detail.receipt_exception" :title="'接收异常：' + detail.receipt_exception" type="warning" :closable="false" show-icon />
        <el-alert v-if="detailError" :title="detailError" type="error" :closable="false" show-icon />
        <div v-if="receiving && expectedSerials.length" class="lines-toolbar">
          <span class="lines-title">编号核对 {{ Object.keys(checkedSerials).length }} / {{ expectedSerials.length }}</span>
          <el-input v-model="serialCode" size="small" placeholder="扫描或输入序列号后回车" :disabled="actionSaving || detailPending" @keyup.enter.native="checkSerial"><el-button slot="append" icon="el-icon-full-screen" @click="checkSerial">核对</el-button></el-input>
        </div>

        <el-table :data="detail.lines" border stripe fit size="small" class="enterprise-table">
          <el-table-column type="index" label="#" width="46" align="center" />
          <el-table-column label="物料编码 / 名称" min-width="200" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <div class="doc-cell">
                <span class="code-text font-tabular">{{ row.item && row.item.item_code }}</span>
                <strong>{{ row.item && row.item.item_name }}</strong>
                <small v-if="row.item && row.item.spec" class="cell-sub">{{ row.item.spec }}</small>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="batch_no" label="批次号" min-width="160">
            <template slot-scope="{ row }">
              <span class="font-tabular">{{ row.batch_no || '—' }}</span>
            </template>
          </el-table-column>
          <el-table-column :label="receiving ? '实际接收库位' : '来源库位 → 调入库位'" min-width="190">
            <template slot-scope="{ row }">
              <paged-record-select v-if="receiving" v-model="row.received_target_location_id" entity="locations" :params="{ warehouse_id: detail.target_warehouse_id, statuses: warehouseParams.statuses, location_purposes: ['general', 'finished'] }" :disabled="actionSaving || detailPending" size="mini" title="选择实际接收库位" placeholder="请核对实际接收库位" />
              <template v-else>{{ row.source_location && row.source_location.location_name }} → {{ row.target_location && row.target_location.location_name }}</template>
            </template>
          </el-table-column>
          <el-table-column label="调拨数量" width="115" align="right">
            <template slot-scope="{ row }">
              <strong class="font-tabular text-green">{{ num(row.quantity) }}</strong>
              <small class="unit-lbl">{{ row.unit && row.unit.unit_name }}</small>
            </template>
          </el-table-column>
          <el-table-column v-if="$can('inventory.amount.view')" label="结转成本" width="115" align="right">
            <template slot-scope="{ row }">
              <span class="font-tabular">{{ row.cost_amount === null || row.cost_amount === undefined ? '—' : `¥${money(row.cost_amount)}` }}</span>
            </template>
          </el-table-column>
          <el-table-column label="迁移序列号" min-width="170" show-overflow-tooltip>
            <template slot-scope="{ row }"><template v-if="receiving"><el-tag v-for="sn in row.serial_nos || []" :key="sn" size="mini" :type="checkedSerials[sn] ? 'success' : 'info'">{{ sn }} · {{ checkedSerials[sn] ? '已核对' : '未核对' }}</el-tag><span v-if="!(row.serial_nos || []).length">—</span></template><template v-else>{{ (row.serial_nos || []).join('、') || '—' }}</template></template>
          </el-table-column>
        </el-table>
      </div>
      <span slot="footer">
        <el-button size="small" :disabled="actionSaving" @click="detailVisible = false">关闭</el-button>
        <template v-if="detail && detail.transfer_status === 'draft'">
          <el-button v-if="detail.can_edit" size="small" class="btn-green-outline" icon="el-icon-edit" :disabled="actionSaving || detailPending" @click="detailVisible = false; openForm(detail.id)">编辑草稿</el-button>
          <el-button v-if="detail.can_post" size="small" type="success" class="btn-theme-create" icon="el-icon-circle-check" :disabled="actionSaving || detailPending" @click="post(detail)">{{ crossWarehouse(detail) ? '调出' : '移库' }}</el-button>
        </template>
        <template v-if="detail && detail.transfer_status === 'in_transit'">
          <el-button v-if="detail.can_record_exception" size="small" :disabled="actionSaving || detailPending" @click="recordException(detail)">记录接收异常</el-button>
          <el-button v-if="detail.can_confirm && !receiving" size="small" type="success" :disabled="actionSaving || detailPending" @click="openDetail(detail.id, true)">核对整单接收</el-button>
          <el-button v-if="detail.can_confirm && receiving" size="small" type="success" :loading="actionSaving" :disabled="!receiptReady || detailPending" @click="receive">确认整单接收</el-button>
        </template>
      </span>
    </el-dialog>
  </section>
</template>

<script>
import { listTransfers, getTransferOptions, listTransferBalances, listInventoryBalanceSerials, getTransfer, saveTransfer, postTransfer, confirmTransfer, recordTransferException, cancelTransfer, deleteTransfer } from '@/api/erp/inventory'
import { pendingWarehouseCommands, submitWarehouseCommand } from '@/api/erp/warehouse'
import { erpDateTime } from '@/utils/dateTime'
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { listEntity } from '@/api/erp/master'

const emptyLine = () => ({ inventory_balance_id: null, target_location_id: null, quantity: undefined, serial_ids: [], remark: '' })

export default {
  name: 'InventoryTransferList',
  components: { PagedRecordSelect },
  data() {
    return {
      loading: false,
      saving: false,
      actionSaving: false,
      pendingCommands: [],
      rows: [],
      total: 0,
      page: 1,
      pageSize: 10,
      stats: { total: 0, draft: 0, in_transit: 0, posted: 0, cancelled: 0 },
      filters: { keyword: '', warehouse_id: null, status: '', has_exception: '' },
      warehouseParams: { statuses: ['enabled', 'active'], include_location_summary: 1 },
      sourceWarehouse: null,
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
      form: { id: null, business_version: null, source_warehouse_id: null, target_warehouse_id: null, remark: '', lines: [] },
      detailVisible: false,
      detail: null,
      receiving: false,
      detailError: '',
      checkedSerials: {},
      serialCode: '',
      windowWidth: window.innerWidth
    }
  },
  computed: {
    formPending() {
      return this.pendingCommands.some(job => this.form.id ? Number(job.id) === Number(this.form.id) && job.action !== 'inventory_transfer.create'
        : job.action === 'inventory_transfer.create' && Number(job.id) === Number(this.form.source_warehouse_id))
    },
    detailPending() { return Boolean(this.detail && this.rowPending(this.detail.id)) },
    expectedSerials() { return this.detail ? this.detail.lines.flatMap(line => line.serial_nos || []) : [] },
    receiptReady() {
      return Boolean(this.detail && this.receiving && this.detail.lines.length && this.detail.lines.every(line => line.received_target_location_id)
        && this.expectedSerials.every(sn => this.checkedSerials[sn]) && Object.keys(this.checkedSerials).length === this.expectedSerials.length)
    },
    targetWarehouseParams() {
      return {
        ...this.warehouseParams,
        ...(this.sourceWarehouse && this.sourceWarehouse.management_scope ? { management_scope: this.sourceWarehouse.management_scope } : {})
      }
    }
  },
  created() {
    this.loadOptions()
    this.load()
    this.refreshPending()
    if (Number(this.$route.query.id) > 0) this.openDetail(Number(this.$route.query.id))
    window.addEventListener('resize', this.onResize)
  },
  beforeDestroy() {
    window.removeEventListener('resize', this.onResize)
  },
  watch: {
    '$route.fullPath'() { if (this.$route.path === '/inventory/transfers' && Number(this.$route.query.id) > 0) this.openDetail(Number(this.$route.query.id)) }
  },
  methods: {
    dateTime: erpDateTime,
    listTransferBalances,
    listSourceCategories() {
      return listEntity('categories', { category_type: 'item', tree: 1 })
    },
    listSourceSerials({ inventory_balance_id: id, ...params }) {
      return listInventoryBalanceSerials(id, params)
    },
    serialLabel(row) {
      return row.serial_no
    },
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
    statusType(status) {
      return { draft: 'warning', in_transit: 'warning', posted: 'success', cancelled: 'info' }[status] || ''
    },
    fail(error) {
      this.refreshPending()
      this.$message.error((error && error.userMessage) || '操作失败')
    },
    refreshPending() {
      try {
        this.pendingCommands = ['create', 'update', 'post', 'confirm', 'exception', 'cancel', 'delete'].flatMap(action => pendingWarehouseCommands('inventory_transfer.' + action))
      } catch (error) { this.$message.error(error.message) }
    },
    rowPending(id) { return this.pendingCommands.some(job => job.action !== 'inventory_transfer.create' && Number(job.id) === Number(id)) },
    actionLabel(action) { return { create: '新建', update: '保存', post: '调出/移库', confirm: '接收', exception: '记录异常', cancel: '取消', delete: '删除' }[action.split('.')[1]] || '调拨' },
    crossWarehouse(row) { return Number(row.source_warehouse_id) !== Number(row.target_warehouse_id) },
    async recover(job) {
      if (this.actionSaving) return
      this.actionSaving = true
      try {
        const result = await submitWarehouseCommand(job.action, job.id, job.payload)
        this.refreshPending()
        if (job.action === 'inventory_transfer.create' && this.formVisible && !this.form.id) {
          this.form.id = result.id; this.form.business_version = result.business_version
        }
        this.$message.success('上次调拨操作结果已核对')
        await this.load()
        if (this.detailVisible && this.detail && Number(this.detail.id) === Number(job.id)) await this.openDetail(this.detail.id)
      } catch (error) { this.fail(error) }
      finally { this.actionSaving = false }
    },
    balanceOf(row) {
      return this.balances.find(b => b.id === row.inventory_balance_id)
    },
    balanceLabel(b) {
      return `${b.item_code} ${b.item_name} | ${b.location_name} | ${b.batch_no} | 可用 ${this.num(b.quantity_available)}${b.unit_name ? ' ' + b.unit_name : ''}`
    },
    maxQty(row) {
      const balance = this.balanceOf(row)
      return balance ? balance.quantity_available : Infinity
    },
    hasSerials(row) {
      const balance = this.balanceOf(row)
      return row.serial_ids.length > 0 || !!(balance && (balance.available_serials_count > 0 || balance.serial_tracking_mode !== 'none'))
    },
    serialRequired(row) {
      const balance = this.balanceOf(row)
      return !!balance && balance.serial_tracking_mode === 'required' && !(Number(balance.unnumbered_available) > 0)
    },
    serialHint(row) {
      const balance = this.balanceOf(row)
      if (!balance) return ''
      const picked = row.serial_ids.length
      const unnumbered = Math.max(0, Math.round(Number(row.quantity) || 0) - picked)
      return `可用编号 ${balance.available_serials_count} 件，无编号 ${this.num(balance.unnumbered_available)} 件；本行：编号 ${picked} 件 + 无编号 ${unnumbered} 件`
    },
    changeSerials(row) {
      const picked = row.serial_ids.length
      if (this.serialRequired(row) || !(Number(row.quantity) >= picked)) row.quantity = picked
    },
    serialLineError(row, index) {
      const balance = this.balanceOf(row)
      if (!balance || !this.hasSerials(row)) return ''
      const qty = Number(row.quantity) || 0
      const picked = row.serial_ids.length
      const prefix = `第 ${index + 1} 行（${balance.item_code}）`
      if (!Number.isInteger(qty)) return `${prefix}涉及单件编号，数量必须为整数`
      if (picked > qty) return `${prefix}所选编号数不能超过调拨数量`
      if (this.serialRequired(row) && picked !== qty) return `${prefix}必须逐件选择与数量一致的序列号`
      if (qty - picked > Number(balance.unnumbered_available)) return `${prefix}无编号可用 ${this.num(balance.unnumbered_available)} 件，本行编号 ${picked} 件 + 无编号 ${qty - picked} 件，请补选序列号`
      return ''
    },
    async loadOptions() {
      try {
        const { data } = await getTransferOptions()
        this.statuses = data.data.transfer_statuses || {}
      } catch (e) {
        this.fail(e)
      }
    },
    async load() {
      this.loading = true
      try {
        const { data } = await listTransfers({ ...this.filters, has_exception: this.filters.has_exception === '' ? undefined : this.filters.has_exception, page: this.page, per_page: this.pageSize })
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
      this.filters = { keyword: '', warehouse_id: null, status: '', has_exception: '' }
      this.query()
    },
    quickStatus(status) {
      this.filters.status = status
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
    changeSource() {
      this.form.lines = [emptyLine()]
      this.form.target_warehouse_id = null
      this.balances = []
    },
    addLine() {
      this.form.lines.push(emptyLine())
    },
    async openForm(id) {
      this.sourceWarehouse = null
      this.form = { id: null, business_version: null, source_warehouse_id: null, target_warehouse_id: null, remark: '', lines: [emptyLine()] }
      this.balances = []
      if (id) {
        try {
          const { data } = await getTransfer(id)
          const t = data.data
          this.sourceWarehouse = t.source_warehouse
          this.form = {
            id: t.id,
            business_version: t.business_version,
            source_warehouse_id: t.source_warehouse_id,
            target_warehouse_id: t.target_warehouse_id,
            remark: t.remark || '',
            lines: t.lines.map(l => ({
              inventory_balance_id: l.inventory_balance_id,
              target_location_id: l.target_location_id,
              quantity: Number(l.quantity),
              serial_ids: l.serial_ids || [],
              remark: l.remark || ''
            }))
          }
        } catch (e) {
          this.fail(e)
          return
        }
      }
      this.formVisible = true
    },
    async submit(postImmediately = false) {
      if (this.saving || this.formPending) return
      if (!this.form.source_warehouse_id || !this.form.target_warehouse_id) return this.$message.warning('请选择调出和调入仓库')
      const lines = this.form.lines.filter(l => l.inventory_balance_id)
      if (!lines.length) return this.$message.warning('请至少添加一行明细')
      if (lines.some(l => !l.target_location_id || !(l.quantity > 0))) return this.$message.warning('请填写每行的调入库位和数量')
      const serialError = lines.map(this.serialLineError).find(Boolean)
      if (serialError) return this.$message.warning(serialError)
      this.saving = true
      try {
        const saved = await saveTransfer({
          ...this.form,
          expected_version: this.form.id ? this.form.business_version : undefined,
          lines: lines.map(l => ({ ...l, serial_ids: l.serial_ids.length ? l.serial_ids : null }))
        })
        this.form.id = saved.id; this.form.business_version = saved.business_version
        if (postImmediately && saved && saved.id) {
          const posted = await postTransfer(saved.id, { expected_version: saved.business_version })
          this.$message.success(posted.transfer_status === 'in_transit' ? '调拨单已调出，待目标仓整单接收' : '同仓移库已完成')
        } else {
          this.$message.success('调拨单草稿已保存')
        }
        this.formVisible = false
        this.refreshPending()
        this.load()
      } catch (e) {
        this.fail(e)
      } finally {
        this.saving = false
      }
    },
    async openDetail(id, receiving = false) {
      const sequence = this.detailSequence = (this.detailSequence || 0) + 1
      this.detail = null
      try {
        const { data } = await getTransfer(id)
        if (sequence !== this.detailSequence) return
        this.detail = { ...data.data, lines: data.data.lines.map(line => ({ ...line, received_target_location_id: null })) }
        this.receiving = Boolean(receiving && this.detail.can_confirm)
        this.checkedSerials = {}; this.serialCode = ''; this.detailError = ''
        this.detailVisible = true
      } catch (e) {
        this.fail(e)
      }
    },
    async confirmAction(message, title, action, success) {
      if (this.actionSaving) return
      try {
        await this.$confirm(message, title, { type: 'warning' })
      } catch (e) {
        return
      }
      this.actionSaving = true
      try {
        await action()
        this.$message.success(success)
        this.refreshPending()
        await this.load()
        if (this.detailVisible && this.detail) await this.openDetail(this.detail.id)
      } catch (e) {
        this.fail(e)
      } finally {
        this.actionSaving = false
      }
    },
    post(row) {
      if (this.rowPending(row.id)) return
      const cross = this.crossWarehouse(row)
      this.confirmAction(cross ? `确认调出 ${row.transfer_no}？调出后保持在途，目标仓整单接收后才可用。` : `确认办理 ${row.transfer_no} 同仓移库？`,
        cross ? '调拨调出' : '同仓移库', () => postTransfer(row.id, { expected_version: row.business_version }), cross ? '已调出，待目标仓接收' : '同仓移库已完成')
    },
    cancel(row) {
      if (this.rowPending(row.id)) return
      this.confirmAction(`确认取消调拨单 ${row.transfer_no}？`, '取消调拨', () => cancelTransfer(row.id, { expected_version: row.business_version }), '调拨单已取消')
    },
    remove(row) {
      if (this.rowPending(row.id)) return
      this.confirmAction(`确认删除调拨草稿 ${row.transfer_no}？`, '删除草稿', () => deleteTransfer(row.id, { expected_version: row.business_version }), '已删除')
    },
    checkSerial() {
      if (this.actionSaving || this.detailPending) return
      const sn = this.serialCode.trim().replace(/^ERP:SN:/i, '')
      if (!this.expectedSerials.includes(sn)) { this.detailError = '该编号不属于当前调拨单'; return }
      if (this.checkedSerials[sn]) { this.detailError = '该编号已经核对，请扫描其他编号'; return }
      this.$set(this.checkedSerials, sn, true); this.serialCode = ''; this.detailError = ''
    },
    receive() {
      if (!this.receiptReady || this.detailPending) return
      const row = this.detail
      const payload = { expected_version: row.business_version, serial_nos: Object.keys(this.checkedSerials),
        lines: row.lines.map(line => ({ line_id: line.id, target_location_id: line.received_target_location_id })) }
      this.confirmAction(`已逐行核对 ${row.transfer_no} 全部实物和实际库位，确认整单接收？`, '整单接收', () => confirmTransfer(row.id, payload), '调拨已整单接收')
    },
    async recordException(row) {
      if (this.actionSaving || this.rowPending(row.id)) return
      let reason
      try {
        const result = await this.$prompt('记录短少、破损等情况后仍保持在途，数量与成本不变。', '记录接收异常', { inputType: 'textarea', inputValidator: value => Boolean(value?.trim()) && value.trim().length <= 500 || '请填写500字以内的异常情况' })
        reason = result.value.trim()
      } catch (error) { return }
      this.actionSaving = true
      try {
        await recordTransferException(row.id, { expected_version: row.business_version, reason })
        this.refreshPending(); this.$message.success('接收异常已记录，调拨保持在途'); await this.load()
        if (this.detailVisible && this.detail?.id === row.id) await this.openDetail(row.id)
      } catch (error) { this.fail(error) }
      finally { this.actionSaving = false }
    }
  }
}
</script>

<style scoped>
.transfer-page {
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
  padding: 16px 20px;
  margin-bottom: 14px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
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
  background: #f0fdf4;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}

.head-title-wrap {
  display: flex;
  flex-direction: column;
  min-width: 0;
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
  color: #0f172a;
}

.head-tag,
.total-tag,
.sub-total-tag {
  border-radius: 4px;
  font-weight: 500;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.btn-refresh {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-refresh:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
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
.bg-gray { background: #f1f5f9; color: #64748b; }

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
.filter-item .el-select,
.filter-item > .paged-record-select {
  width: 195px;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.doc-cell,
.items-cell,
.meta-cell {
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
.items-cell small,
.meta-cell small {
  font-size: 11px;
  color: #8490a2;
}

.items-summary-text {
  font-size: 13px;
  color: #17233b;
}

.route-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  font-size: 12px;
}

.wh-from {
  color: #475569;
  font-weight: 500;
}

.route-arrow {
  color: #008b4b;
  font-weight: 700;
}

.wh-to {
  color: #008b4b;
  font-weight: 600;
}

.row-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  flex-wrap: wrap;
}

.action-link {
  padding: 0;
  color: #008b4b !important;
  font-weight: 500;
}

.muted-link {
  padding: 0;
  color: #64748b !important;
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

.form-top-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1.3fr;
  gap: 12px;
}

.lines-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.lines-title {
  font-size: 13px;
  font-weight: 600;
  color: #17233b;
}

.lines-title b {
  color: #008b4b;
}

.serial-hint {
  margin-top: 2px;
  color: #64748b;
  font-size: 11px;
  line-height: 1.4;
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

.unit-lbl {
  margin-left: 3px;
  font-size: 11px;
  color: #8490a2;
}

@media (max-width: 1100px) {
  .metric-overview-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .form-top-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .page-head {
    flex-direction: column;
    align-items: flex-start;
  }
  .metric-overview-grid {
    grid-template-columns: 1fr;
  }
  .filter-item,
  .filter-item .el-input,
  .filter-item .el-select,
  .filter-item > .paged-record-select {
    width: 100%;
  }
}
</style>
