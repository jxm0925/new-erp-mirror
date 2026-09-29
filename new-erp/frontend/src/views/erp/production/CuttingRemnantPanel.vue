<template>
  <section class="remnant-panel" v-loading="loading">
    <h3>余料入库</h3>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-alert v-if="pending" title="上次入库结果尚未确认，请继续原操作。" type="warning" :closable="false" />
    <div class="remnant-filters">
      <el-input v-model="keyword" class="remnant-keyword" prefix-icon="el-icon-search" clearable placeholder="余料编号 / 物料编码 / 名称 / 规格" @keyup.enter.native="load(1)" />
      <label class="remnant-status">状态 <el-select v-model="status" aria-label="余料入库状态"><el-option label="待入库" value="PENDING" /><el-option label="已入库" value="POSTED" /><el-option label="已领用 / 已变更" value="UNAVAILABLE" /><el-option label="全部" value="" /></el-select></label>
      <el-button type="success" @click="load(1)">查询</el-button><el-button @click="resetFilters">重置</el-button>
      <div v-if="$can('production.cutting.warehouse')" class="remnant-primary"><el-button v-if="pending" type="success" :loading="busy" @click="post">继续上次入库</el-button><el-button v-else-if="status !== 'POSTED' && status !== 'UNAVAILABLE'" type="success" :disabled="!selectedRows.length || busy" @click="openForm">办理入库（{{ selectedRows.length }}）</el-button></div>
    </div>
    <div class="remnant-table"><el-table :key="status" :data="rows" border empty-text="暂无余料记录">
      <el-table-column v-if="status !== 'POSTED' && status !== 'UNAVAILABLE' && $can('production.cutting.warehouse')" width="70" align="center">
        <template #header="scope"><el-checkbox :key="scope.column.id" :value="allPageSelected" :indeterminate="somePageSelected && !allPageSelected" :disabled="!!pending || !selectableRows.length" aria-label="选择本页可入库余料" @change="togglePage" /></template>
        <template slot-scope="{row}"><el-checkbox :value="!!selected[row.id]" :disabled="!row.receivable || !!pending || busy" :aria-label="'选择余料'+row.remnant_no" @change="value => toggleRow(row, value)" /></template>
      </el-table-column>
      <el-table-column prop="remnant_no" label="余料编号" min-width="185" />
      <el-table-column label="物料" min-width="200"><template slot-scope="{row}">{{ row.item_code }}<br>{{ row.item_name }}</template></el-table-column>
      <el-table-column label="实际尺寸（mm）" min-width="150"><template slot-scope="{row}">{{ dimensions(row.dimensions) }}</template></el-table-column>
      <el-table-column prop="source_batch_no" label="用料批次" min-width="160" />
      <el-table-column label="库存数量" min-width="105" align="center"><template slot-scope="{row}">{{ qty(row.quantity) }} {{ row.unit_name }}</template></el-table-column>
      <el-table-column prop="total_cost" label="材料金额" min-width="110" align="right" />
      <el-table-column label="状态" min-width="110" align="center"><template slot-scope="{row}"><el-tag :type="row.status === 'POSTED' ? 'success' : row.receivable ? 'warning' : 'info'">{{ row.status === 'POSTED' ? '已入库' : row.receivable ? '待入库' : row.status === 'PENDING' ? '待核对单位' : '已领用 / 已变更' }}</el-tag></template></el-table-column>
      <el-table-column v-if="status !== 'PENDING'" label="操作" width="110"><template slot-scope="{row}"><el-button v-if="row.receipt_id" type="text" @click="openDetail(row.receipt_id)">查看入库</el-button><span v-else>—</span></template></el-table-column>
    </el-table></div>
    <div class="remnant-bottom"><div v-if="status !== 'POSTED' && status !== 'UNAVAILABLE'"><span>已选 {{ selectedRows.length }} 块余料，材料金额合计 <strong>{{ selectedCost }}</strong></span><p>已被领用或已入库的余料不可重复入库。</p></div>
      <el-pagination :current-page="page" :page-size="pageSize" :page-sizes="[10,20,50]" :total="total" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="changeSize" />
    </div>

    <el-dialog title="余料入库" :visible.sync="formVisible" width="1046px" custom-class="remnant-dialog" append-to-body :close-on-click-modal="false" :close-on-press-escape="!busy" :show-close="!busy" :before-close="closeForm">
      <p class="remnant-context">下料单　{{ orderNo }}　·　已选 {{ selectedRows.length }} 块余料</p>
      <el-alert v-if="formError" :title="formError" type="error" :closable="false" />
      <div class="remnant-table"><el-table :data="selectedRows" border>
        <el-table-column prop="remnant_no" label="余料编号" min-width="190" /><el-table-column label="实际尺寸（mm）" min-width="155"><template slot-scope="{row}">{{ dimensions(row.dimensions) }}</template></el-table-column>
        <el-table-column label="入库数量" min-width="100"><template slot-scope="{row}">{{ qty(row.quantity) }} {{ row.unit_name }}</template></el-table-column><el-table-column prop="remnant_no" label="入库批次" min-width="185" /><el-table-column prop="total_cost" label="材料金额" width="120" align="right" />
        <el-table-column label="操作" width="80"><template slot-scope="{row}"><el-button type="text" :disabled="busy || !!pending" @click="$delete(selected,row.id)">移除</el-button></template></el-table-column>
      </el-table></div>
      <p class="remnant-sum">已选 {{ selectedRows.length }} 块余料　材料金额合计 <strong>{{ selectedCost }}</strong></p>
      <el-form label-width="170px" class="remnant-form" :disabled="busy || !!pending">
        <el-form-item label="仓库 / 库位" required :error="locatorError"><el-button class="remnant-locator-button" @click="$refs.locator.open(locator)">{{ locatorLabel || '选择仓库和库位' }}<i class="el-icon-s-operation" /></el-button><p class="remnant-help">批次按余料编号自动带出，数量和材料金额沿用已确认记录。</p></el-form-item>
        <el-form-item label="备注（选填）"><el-input v-model="remark" type="textarea" :rows="3" maxlength="1000" /></el-form-item>
      </el-form>
      <p class="remnant-help">入库后保留余料编号和尺寸，更新库存数量与金额。</p>
      <span slot="footer"><el-button :disabled="busy" @click="formVisible=false">返回核对</el-button><el-button type="success" :disabled="!selectedRows.length && !pending" :loading="busy" @click="submit">{{ pending ? '继续上次入库' : '确认入库' }}</el-button></span>
    </el-dialog>
    <cutting-warehouse-locator ref="locator" @selected="selectLocator" />

    <el-dialog :visible.sync="detailVisible" width="930px" custom-class="remnant-dialog remnant-record" append-to-body :close-on-click-modal="false">
      <template slot="title"><span class="remnant-record-title">余料入库记录</span><el-tag type="success" class="remnant-record-status">已入库</el-tag></template>
      <div v-loading="detailLoading"><el-alert v-if="detailError" :title="detailError" type="error" :closable="false" />
        <template v-if="receipt.id"><dl class="remnant-record-fields">
          <div><dt>入库单号</dt><dd>{{ receipt.receipt_no }}</dd></div><div><dt>下料单</dt><dd>{{ receipt.header_snapshot.cutting_order_no }}</dd></div>
          <div><dt>来源工单</dt><dd>{{ receipt.header_snapshot.work_order_nos.join('、') || '—' }}</dd></div><div><dt>入库库位</dt><dd>{{ receipt.header_snapshot.location_name }}</dd></div>
          <div><dt>入库仓库</dt><dd>{{ receipt.header_snapshot.warehouse_name }}</dd></div><div><dt>操作人</dt><dd>{{ receipt.header_snapshot.operator_name }}</dd></div>
          <div><dt>入库时间</dt><dd>{{ receipt.posted_at }}</dd></div><div><dt>备注</dt><dd>{{ receipt.remark || '—' }}</dd></div>
        </dl>
        <div class="remnant-table"><el-table :data="receipt.lines" border>
          <el-table-column label="余料编号" min-width="190"><template slot-scope="{row}">{{ row.line_snapshot.remnant_no }}</template></el-table-column><el-table-column label="实际尺寸（mm）" min-width="165"><template slot-scope="{row}">{{ dimensions(row.line_snapshot.dimensions) }}</template></el-table-column>
          <el-table-column label="入库数量" min-width="105"><template slot-scope="{row}">{{ qty(row.posted_qty) }} {{ row.line_snapshot.unit_name }}</template></el-table-column><el-table-column prop="batch_no" label="入库批次" min-width="185" /><el-table-column prop="posted_cost" label="材料金额" min-width="125" align="right" />
        </el-table></div>
        <p class="remnant-record-total"><span>合计 {{ receipt.piece_count }} 块余料</span><span>材料金额合计 <strong>{{ receipt.posted_cost }}</strong></span></p><p class="remnant-help">库存已增加，原余料编号与尺寸保持不变。</p></template>
      </div>
      <span slot="footer"><el-button type="success" @click="detailVisible=false">关闭</el-button></span>
    </el-dialog>
  </section>
</template>
<script>
import { listRemnants, getRemnantReceipt, pendingRemnantReceipt, postRemnantReceipt } from '../../../api/erp/remnant-receipts'
import CuttingWarehouseLocator from './CuttingWarehouseLocator.vue'
export default {
  components: { CuttingWarehouseLocator },
  props: { orderId: { type: [Number, String], required: true } },
  data: () => ({ loading: false, busy: false, rows: [], selected: {}, keyword: '', status: 'PENDING', page: 1, pageSize: 10, total: 0, orderNo: '', error: '', pending: null,
    formVisible: false, formError: '', locatorError: '', locator: {}, remark: '', detailVisible: false, detailLoading: false, detailError: '', receipt: {} }),
  computed: {
    selectedRows() { return Object.values(this.selected) },
    selectedCost() { const sum = this.selectedRows.reduce((n, row) => n + BigInt(String(row.total_cost || '0').replace('.', '').padEnd(String(row.total_cost || '0').split('.')[0].length + 4, '0')), BigInt(0)); const value = sum.toString().padStart(5, '0'); return `${value.slice(0, -4)}.${value.slice(-4)}` },
    selectableRows() { return this.rows.filter(row => row.receivable) },
    allPageSelected() { return this.selectableRows.length > 0 && this.selectableRows.every(row => !!this.selected[row.id]) },
    somePageSelected() { return this.selectableRows.some(row => !!this.selected[row.id]) },
    locatorLabel() { return this.locator.warehouse && this.locator.location ? `${this.locator.warehouse.name} / ${this.locator.location.name}` : '' }
  },
  created() { this.load(1) },
  watch: { orderId() { this.selected = {}; this.formVisible = false; this.detailVisible = false; this.invalidate(); this.load(1) } },
  beforeDestroy() { this.invalidate() },
  methods: {
    invalidate() { this.sequence = (this.sequence || 0) + 1; this.detailSequence = (this.detailSequence || 0) + 1 },
    qty(value) { return value == null ? '待核对' : String(value).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '') },
    dimensions(d = {}) { if (d.length_mm && d.width_mm) return [d.length_mm, d.width_mm, d.thickness_mm].filter(Boolean).map(this.qty).join(' × '); if (d.length_mm) return `${this.qty(d.length_mm)}`; return d.weight_kg ? `重量 ${this.qty(d.weight_kg)} kg` : '—' },
    resetFilters() { this.keyword = ''; this.status = 'PENDING'; this.load(1) },
    changeSize(size) { this.pageSize = size; this.load(1) },
    async load(page = this.page) {
      const sequence = this.sequence = (this.sequence || 0) + 1; this.loading = true; this.error = ''
      try {
        this.pending = pendingRemnantReceipt(this.orderId)
        const { data } = await listRemnants(this.orderId, { keyword: this.keyword.trim(), status: this.status, page, per_page: this.pageSize })
        if (sequence !== this.sequence) return
        this.rows = data.data; this.total = Number(data.meta.total); this.page = Number(data.meta.current_page); this.orderNo = data.order_no
        for (const row of this.rows) if (this.selected[row.id]) { if (row.receivable) this.$set(this.selected, row.id, row); else this.$delete(this.selected, row.id) }
      } catch (e) { if (sequence === this.sequence) this.error = e.userMessage || e.message || '余料加载失败' } finally { if (sequence === this.sequence) this.loading = false }
    },
    toggleRow(row, value) { if (!row.receivable || this.pending || this.busy) return; if (value) { if (this.selectedRows.length >= 100) return this.$message.error('每次最多选择100块余料'); this.$set(this.selected, row.id, row) } else this.$delete(this.selected, row.id) },
    togglePage(value) { this.selectableRows.forEach(row => { if (!value || !this.selected[row.id]) this.toggleRow(row, value) }) },
    openForm() { if (this.busy || this.pending) return; this.locator = {}; this.remark = ''; this.formError = ''; this.locatorError = ''; this.formVisible = true },
    closeForm(done) { if (!this.busy) done() },
    selectLocator(value) { this.locator = value; this.locatorError = '' },
    submit() {
      if (this.pending) return this.post()
      if (!this.selectedRows.length) { this.formError = '请选择待入库余料'; return }
      if (!this.locator.warehouse || !this.locator.location) { this.locatorError = '请选择仓库和库位'; return }
      return this.post({ warehouse_id: this.locator.warehouse.id, location_id: this.locator.location.id, remark: this.remark,
        lines: this.selectedRows.map(row => ({ result_id: row.id, expected_version: row.business_version, holding_version: row.holding_version, physical_version: row.physical_version })) })
    },
    async post(payload) {
      if (this.busy) return
      this.busy = true; this.formError = ''; const id = this.orderId
      try {
        await postRemnantReceipt(id, this.pending ? this.pending.payload : payload)
        if (String(id) !== String(this.orderId)) return
        this.formVisible = false; this.selected = {}; this.$message.success('余料已正式入库'); this.$emit('posted')
      } catch (e) { if (String(id) === String(this.orderId)) { this.formError = e.userMessage || e.message || '入库失败'; this.$message.error(this.formError) } }
      finally { this.busy = false; if (String(id) === String(this.orderId)) await this.load(this.page) }
    },
    async openDetail(id) {
      const sequence = this.detailSequence = (this.detailSequence || 0) + 1; this.receipt = {}; this.detailVisible = true; this.detailLoading = true; this.detailError = ''
      try { const { data } = await getRemnantReceipt(this.orderId, id); if (sequence === this.detailSequence) this.receipt = data.data }
      catch (e) { if (sequence === this.detailSequence) this.detailError = e.userMessage || '入库记录加载失败' } finally { if (sequence === this.detailSequence) this.detailLoading = false }
    }
  }
}
</script>
<style>
.remnant-dialog .el-button--success{background:#008454;border-color:#008454;color:white}.remnant-dialog .el-button--success:hover{background:#00764b;border-color:#00764b}.remnant-dialog .el-button--success.is-disabled{background:#a2cbbc;border-color:#a2cbbc}
.remnant-dialog .el-dialog__body,.remnant-dialog .el-table,.remnant-dialog .el-input__inner,.remnant-dialog .el-button,.remnant-dialog .el-form-item__label,.remnant-dialog .el-radio__label{font-size:16px}.remnant-dialog .el-input__inner{height:48px;line-height:48px}.remnant-dialog .el-table .cell{line-height:24px}.remnant-dialog .el-radio__input.is-checked .el-radio__inner{background:#008454;border-color:#008454}.remnant-dialog .el-radio__input.is-checked+.el-radio__label{color:#203753}.remnant-dialog .el-radio__inner{width:19px;height:19px}.remnant-dialog .el-pagination{min-height:34px;overflow-x:auto;overflow-y:hidden}.remnant-dialog .el-dialog__footer .el-button{padding:15px 24px}
.remnant-dialog{max-width:calc(100vw - 32px);margin:0!important;display:flex;flex-direction:column;max-height:calc(100vh - 32px);border-radius:6px;color:#203753}.el-dialog__wrapper:has(>.remnant-dialog){display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}.remnant-dialog .el-dialog__header{padding:24px 30px 10px}.remnant-dialog .el-dialog__title,.remnant-record-title{font-size:24px;font-weight:600;color:#172e49}.remnant-dialog .el-dialog__body{padding:16px 30px 22px;overflow:auto;min-height:0;color:#284569}.remnant-dialog .el-dialog__footer{margin:0 10px;padding:20px 20px 24px;border-top:1px solid #e0e7f0}.remnant-dialog .el-dialog__footer .el-button{min-width:125px}.remnant-dialog .el-table th{background:#f5f7fc;color:#526482;font-weight:500}.remnant-dialog .el-table .cell{word-break:normal;overflow-wrap:anywhere;white-space:normal}.remnant-dialog .el-table td,.remnant-dialog .el-table th{padding:15px 0}.remnant-context{margin:0 0 18px}.remnant-sum{margin:22px 0 26px;font-size:16px}.remnant-sum strong,.remnant-record-total strong{font-size:20px;color:#183e76}.remnant-help{color:#8492aa;font-size:14px;line-height:1.6;margin:12px 0 0}.remnant-locator-button{width:100%;display:flex;justify-content:space-between;align-items:center;text-align:left;white-space:normal;line-height:1.5}.remnant-locator-button>span{display:flex;align-items:center;justify-content:space-between;width:100%;gap:12px;overflow-wrap:anywhere}.remnant-locator-button i{flex-shrink:0}.remnant-form .el-form-item__label{text-align:left}.remnant-record-status{margin-left:24px;vertical-align:4px}.remnant-record-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px 34px;margin:16px 0 24px;padding:0 0 30px;border-bottom:1px solid #e0e7f0}.remnant-record-fields>div{display:grid;grid-template-columns:105px minmax(0,1fr);gap:14px}.remnant-record-fields dt{color:#5e7092}.remnant-record-fields dd{margin:0;overflow-wrap:anywhere}.remnant-record-total{display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px;margin:22px 0;font-size:16px}.remnant-table{max-width:100%;min-width:0;overflow:auto}
@media(max-width:600px){.remnant-dialog .el-dialog__header{padding:18px 16px 8px}.remnant-dialog .el-dialog__title,.remnant-record-title{font-size:19px}.remnant-dialog .el-dialog__body{padding:12px 16px}.remnant-dialog .el-dialog__footer{padding:14px 6px}.remnant-dialog .el-dialog__footer .el-button{min-width:0;max-width:100%;white-space:normal}.remnant-form .el-form-item__label{float:none;display:block;padding:0;width:auto!important}.remnant-form .el-form-item__content{margin-left:0!important}.remnant-record-fields{grid-template-columns:minmax(0,1fr);gap:16px}.remnant-record-fields>div{grid-template-columns:80px minmax(0,1fr);gap:8px}.remnant-record-status{margin-left:12px}.remnant-help{font-size:12px}}
</style>
<style scoped>
.remnant-bottom .el-pagination{min-height:34px;overflow-x:auto;overflow-y:hidden}
.remnant-panel{border:1px solid #e4eaf1;border-radius:4px;padding:18px;min-width:0}.remnant-panel h3{margin:0 0 20px;font-size:17px;color:#1c354f}.remnant-filters{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:24px}.remnant-keyword{width:385px;max-width:100%}.remnant-status{display:flex;align-items:center;gap:12px;color:#64758e}.remnant-status .el-select{width:175px}.remnant-filters .el-button{margin:0;min-width:88px}.remnant-primary{margin-left:auto}.remnant-primary .el-button{min-width:186px}.remnant-bottom{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;border-top:1px solid #e8edf3;margin-top:20px;padding-top:16px}.remnant-bottom p{margin:10px 0 4px;color:#8997ad}.remnant-bottom strong{font-size:18px;color:#173e72}.el-pagination{max-width:100%;overflow:auto;margin-left:auto}.remnant-panel ::v-deep .el-table th{background:#f5f7fc;color:#526482;font-weight:500}.remnant-panel ::v-deep .el-table .cell{word-break:normal;overflow-wrap:anywhere;white-space:normal}.remnant-panel ::v-deep .el-table td{padding:15px 0}.remnant-panel ::v-deep .el-table__empty-block{width:100%!important;min-width:0}.el-alert{margin-bottom:12px}
.remnant-panel ::v-deep .el-input__inner,.remnant-panel ::v-deep .el-button,.remnant-panel ::v-deep .el-table{font-size:15px}.remnant-panel ::v-deep .el-input__inner{height:44px;line-height:44px}.remnant-panel ::v-deep .el-table th{padding:16px 0}.remnant-panel ::v-deep .el-table .cell{line-height:25px}.remnant-panel ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner,.remnant-panel ::v-deep .el-checkbox__input.is-indeterminate .el-checkbox__inner{background-color:#008454;border-color:#008454}.remnant-panel ::v-deep .el-checkbox__inner{width:19px;height:19px}.remnant-panel ::v-deep .el-checkbox__inner:after{left:6px;top:3px}.remnant-panel ::v-deep .el-tag{font-size:14px}.remnant-panel ::v-deep .el-pagination .el-input__inner{height:32px;font-size:14px}
@media(max-width:700px){.remnant-panel{padding:12px}.remnant-filters{gap:10px;margin-bottom:16px}.remnant-keyword{width:100%}.remnant-status{width:100%}.remnant-status .el-select{flex:1;min-width:0;width:auto}.remnant-primary{margin:0;width:100%}.remnant-primary .el-button{width:100%;min-width:0}.remnant-bottom{font-size:12px}.remnant-bottom strong{font-size:15px}}
</style>
