<template>
  <section class="receipt-form-page">
    <header class="page-heading">
      <div>
        <h1>{{ isEdit ? '编辑到货单' : '新增到货单' }}</h1>
      </div>
      <div class="heading-actions">
        <el-button size="small" @click="goBack">返回列表</el-button>
        <el-button size="small" :loading="saving" @click="save(true)">保存草稿</el-button>
        <el-button size="small" type="success" :loading="saving" @click="save(false)">保存</el-button>
      </div>
    </header>

    <el-alert class="posting-alert" type="info" :closable="false" show-icon :title="isReplacement ? '本单为换货免费补发到货单：供应商、来源物料、采购数量、单位及价格已锁定；只登记验收、库位和编号，不新增应付。' : '到货确认只生成待过账记录，库存过账仅关联已登记编号。'" />

    <section class="basic-panel">
      <div class="basic-grid">
        <label class="field-block receipt-no-field">
          <span>到货单号</span>
          <el-input v-model="form.receipt_no" size="small" disabled>
            <template slot="append">系统预生成</template>
          </el-input>
        </label>
        <label class="field-block required">
          <span>供应商</span>
          <el-select v-model="form.supplier_id" size="small" filterable :disabled="isReplacement" placeholder="请选择供应商">
            <el-option v-for="supplier in validSuppliers" :key="supplier.id" :value="supplier.id" :label="supplier.supplier_name" />
          </el-select>
        </label>
        <label class="field-block required">
          <span>到货日期</span>
          <el-date-picker v-model="form.receipt_date" size="small" type="date" value-format="yyyy-MM-dd" placeholder="选择日期" />
        </label>
        <div class="status-field"><span>确认状态</span><el-tag size="mini" type="success">{{ confirmStatusText }}</el-tag></div>
        <div class="status-field"><span>库存过账状态</span><el-tag size="mini" type="warning">{{ postingStatusText }}</el-tag></div>
      </div>
      <label class="remark-field">
        <span>备注</span>
        <el-input v-model="form.remark" type="textarea" :rows="2" resize="none" maxlength="500" show-word-limit placeholder="请输入备注信息（选填）" />
      </label>
    </section>

    <section class="lines-section">
      <div class="section-heading">
        <h2>到货明细 <small>（共 {{ form.items.length }} 行）</small></h2>
        <div>
          <el-tag v-if="isReplacement" size="small" type="warning">换货来源明细已锁定</el-tag>
          <template v-else>
            <el-button size="small" type="success" icon="el-icon-plus" @click="addLine">添加明细</el-button>
            <el-button size="small" icon="el-icon-delete" :disabled="!activeLine" @click="removeActiveLine">删除明细（已选 1 行）</el-button>
          </template>
        </div>
      </div>

      <div class="line-table-shell">
        <el-table :data="form.items" size="mini" :row-class-name="lineRowClass" @row-click="selectLine">
          <el-table-column width="42" align="center">
            <template slot-scope="{$index}"><el-checkbox :value="$index===activeIndex" @change="activeIndex=$index" /></template>
          </el-table-column>
          <el-table-column label="行号" type="index" width="48" align="center" />
          <el-table-column label="物料" min-width="260">
            <template slot-scope="{row}">
              <div :class="['item-picker-cell',{locked:isReplacement}]" @click.stop="openItemPicker(row)">
                <div class="item-cell"><strong>{{ itemById(row.item_id).item_name || '请选择物料' }}</strong><span>{{ itemById(row.item_id).item_code || '点击打开分页物料库' }}</span></div>
                <el-button type="text" size="mini" :icon="isReplacement ? 'el-icon-lock' : 'el-icon-search'">{{ isReplacement ? '来源锁定' : (row.item_id ? '更换' : '选择') }}</el-button>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="到货数量 / 采购单位" min-width="135"><template slot-scope="{row}">{{ number(row.qty) }} / {{ purchaseUnitName(row) }}</template></el-table-column>
          <el-table-column label="合格 / 不合格" width="112"><template slot-scope="{row}">{{ number(row.qualified_qty) }} / {{ number(row.unqualified_qty) }}</template></el-table-column>
          <el-table-column label="批次" min-width="112"><template slot-scope="{row}">{{ isStockManaged(row) ? (row.batch_no || '系统生成') : '非库存' }}</template></el-table-column>
          <el-table-column label="仓库 / 库位" min-width="166"><template slot-scope="{row}">{{ isStockManaged(row) ? allocationSummary(row) : '无需入库' }}</template></el-table-column>
          <el-table-column label="编号完成度" width="118">
            <template slot-scope="{row}"><div v-if="isStockManaged(row)" class="serial-progress"><span>{{ serialNumberList(row).length }} / {{ serialRequiredCount(row) }}</span><i><b :style="{width:serialProgress(row)+'%'}" /></i></div><span v-else>无需编号</span></template>
          </el-table-column>
          <el-table-column label="金额（未税）" width="116" align="right"><template slot-scope="{row}">¥{{ money(lineAmount(row)) }}</template></el-table-column>
          <el-table-column label="操作" width="94" fixed="right">
            <template slot-scope="{row,$index}"><el-button type="text" size="mini" @click.stop="selectLine(row)">查看</el-button><el-button v-if="!isReplacement" class="danger-link" type="text" size="mini" @click.stop="removeLine($index)">删除</el-button></template>
          </el-table-column>
        </el-table>
      </div>
    </section>

    <div v-if="activeLine" class="detail-layout">
      <section class="line-editor">
        <header>当前行验收与入库信息 <span>（行号 {{ activeIndex + 1 }}：{{ activeItem.item_name || '请选择物料' }}）</span></header>
        <div class="editor-columns">
          <section class="editor-group">
            <h3>数量与换算</h3>
            <label class="compact-field required"><span>到货采购数量</span><el-input v-model.number="activeLine.qty" type="number" size="small" min="0.0001" :disabled="isReplacement" @input="syncReceiptActualQty(activeLine)"><template slot="append">{{ purchaseUnitName(activeLine) }}</template></el-input></label>
            <label class="compact-field required"><span>采购单位</span><el-select v-model="activeLine.purchase_unit_id" size="small" :disabled="isReplacement" placeholder="请选择采购单位" @change="applyConversion(activeLine)"><el-option v-for="option in activeLine._conversionOptions || []" :key="option.purchase_unit_id" :value="option.purchase_unit_id" :label="purchaseOptionLabel(option)" /></el-select></label>
            <label class="compact-field required"><span>换算因子</span><el-input :value="conversionFactor(activeLine)" size="small" disabled /></label>
            <label class="compact-field"><span>标准基本数量</span><el-input :value="standardBaseQty(activeLine)" size="small" disabled /></label>
            <label class="compact-field"><span>实际基本数量</span><el-input v-model.number="activeLine.actual_base_qty" type="number" size="small" min="0" :disabled="!actualConversionAllowed(activeLine)" /></label>
            <label class="compact-field"><span>差异数量</span><el-input :value="differenceQty(activeLine)" size="small" disabled /></label>
            <label class="compact-field"><span>差异原因</span><el-select v-model="activeLine.difference_reason" size="small" :disabled="!hasDifference(activeLine)" placeholder="无差异"><el-option label="包装重量偏差" value="包装重量偏差" /><el-option label="计量差异" value="计量差异" /><el-option label="验收修正" value="验收修正" /><el-option label="其他" value="其他" /></el-select></label>
            <label class="compact-field required"><span>{{ isReplacement ? '库存成本单价' : '单价（未税）' }}</span><el-input v-model.number="activeLine.unit_price" type="number" size="small" min="0" :disabled="isReplacement"><template slot="append">CNY</template></el-input></label>
          </section>

          <section class="editor-group">
            <h3>{{ isStockManaged(activeLine) ? '质量与入库' : '验收与履约' }}</h3>
            <label class="compact-field required"><span>合格数量</span><el-input v-model.number="activeLine.qualified_qty" type="number" size="small" min="0" /></label>
            <label class="compact-field required"><span>不合格数量</span><el-input v-model.number="activeLine.unqualified_qty" type="number" size="small" min="0" /></label>
            <template v-if="isStockManaged(activeLine)">
              <label class="compact-field required"><span>批次号</span><el-input :value="activeLine.batch_no || '保存时系统自动生成'" size="small" disabled /></label>
              <label class="compact-field" :class="{ required: qualifiedBaseQty(activeLine) > 0 }"><span>默认仓库</span><el-select v-model="activeLine.warehouse_id" size="small" placeholder="无需入库" :disabled="qualifiedBaseQty(activeLine) <= 0" @change="onDefaultWarehouseChange(activeLine)"><el-option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id" :label="warehouse.warehouse_name" /></el-select></label>
              <label class="compact-field" :class="{ required: qualifiedBaseQty(activeLine) > 0 }"><span>默认库位</span><el-select v-model="activeLine.location_id" size="small" placeholder="无需入库" :disabled="qualifiedBaseQty(activeLine) <= 0" @change="onDefaultLocationChange(activeLine)"><el-option v-for="location in filteredLocations(activeLine.warehouse_id)" :key="location.id" :value="location.id" :label="location.location_name" /></el-select></label>
              <div class="allocation-entry"><span>入库库位分配</span><div><el-button size="mini" type="success" plain icon="el-icon-s-grid" :disabled="qualifiedBaseQty(activeLine) <= 0" @click="openAllocationDialog(activeLine)">多库位分配</el-button><small>{{ allocationProgressText(activeLine) }}</small></div></div>
            </template>
            <el-alert v-else title="该物料按非库存方式采购：验收完成后直接形成履约与结算事实，不要求仓库、库位、批次或库存过账。" type="info" :closable="false" />
            <label class="compact-field"><span>预计到货</span><el-date-picker v-model="activeLine.expected_arrival_date" size="small" type="date" value-format="yyyy-MM-dd" /></label>
            <label class="compact-field remark-compact"><span>备注</span><el-input v-model="activeLine.remark" type="textarea" :rows="2" resize="none" maxlength="200" show-word-limit placeholder="请输入备注信息（选填）" /></label>
          </section>

          <section class="editor-group serial-group">
            <div class="serial-title"><h3>设备编号 / 序列号</h3><el-tag v-if="isSerialManaged(activeLine)" size="mini" type="danger" effect="plain">{{ serialTrackingMode(activeLine)==='required' ? '必须逐件编号' : '按需逐件编号' }}</el-tag></div>
            <template v-if="isStockManaged(activeLine) && isSerialManaged(activeLine)">
              <div class="generate-row"><label><span>合格数量</span><el-input :value="serialRequiredCount(activeLine)" size="small" disabled /></label><el-button size="small" type="success" @click="generateLineSerials(activeLine)">一次生成 {{ serialRequiredCount(activeLine) }} 个</el-button></div>
              <p v-if="allocations(activeLine).length>1" class="serial-allocation-tip">编号将按库位数量配额自动分配：{{ allocationProgressText(activeLine) }}</p>
              <label class="scan-label"><span>扫码枪扫描 / 手工输入</span><el-input v-model.trim="activeLine._scanInput" size="small" placeholder="扫描设备编号，回车后自动加入" @keyup.enter.native="addScannedSerial(activeLine)"><i slot="prefix" class="el-icon-full-screen" /><el-button slot="append" @click="addScannedSerial(activeLine)">添加</el-button></el-input></label>
              <p v-if="activeLine._serialError" class="serial-error">{{ activeLine._serialError }}</p>
              <p v-else class="serial-helper">支持扫码枪回车录入；重复编号自动拦截</p>
              <div class="serial-list-heading"><span>已录入 {{ serialNumberList(activeLine).length }} / {{ serialRequiredCount(activeLine) }}</span><el-button type="text" size="mini" @click="printSerialLabels(activeLine)">全部打印</el-button></div>
              <div class="serial-records">
                <div v-for="(entry,index) in serialNumberList(activeLine)" :key="entry.serial_no" class="serial-record"><span class="serial-index">{{ index+1 }}</span><strong :title="entry.serial_no">{{ entry.serial_no }}</strong><em>{{ entry.source==='system_generated' ? '系统生成' : '扫码' }}</em><el-button type="text" size="mini" icon="el-icon-printer" @click="printSerialLabels(activeLine,entry.serial_no)">打印</el-button><button type="button" class="serial-delete" @click="removeSerial(activeLine,index)">×</button></div>
              </div>
            </template>
            <el-alert v-else :title="isStockManaged(activeLine) ? '该物料仅按批次追溯，无需录入单件编号。' : '非库存物料不建立库存序列号档案。'" type="info" :closable="false" />
          </section>
        </div>
      </section>

      <aside class="document-summary">
        <h3>{{ isReplacement ? '换货结算与库存成本' : '单据金额汇总' }}</h3>
        <dl v-if="isReplacement"><dt>数量汇总</dt><dd>{{ quantitySummary }}</dd><dt>入库成本金额</dt><dd>¥{{ money(untaxedAmount) }}</dd><dt>本次新增应付</dt><dd class="no-payable">¥0.00</dd><dt>原采购合同金额</dt><dd>不变</dd></dl>
        <dl v-else><dt>数量汇总</dt><dd>{{ quantitySummary }}</dd><dt>未税金额</dt><dd>¥{{ money(untaxedAmount) }}</dd><dt>税额（13%）</dt><dd>¥{{ money(taxAmount) }}</dd><dt>含税金额</dt><dd>¥{{ money(totalAmount) }}</dd></dl>
        <template v-if="isEdit">
          <hr>
          <h3>验收结算口径</h3>
          <dl><dt>合格暂估应付</dt><dd>¥{{ money(form.qualified_payable_amount) }}</dd><dt>质量冻结</dt><dd>¥{{ money(form.quality_hold_amount) }}</dd><dt>拒付 / 索赔</dt><dd>¥{{ money(form.rejected_claim_amount) }}</dd></dl>
        </template>
        <hr>
        <h3>校验状态</h3>
        <p :class="['validation-state',{invalid:!activeLineValid}]"><i :class="activeLineValid?'el-icon-circle-check':'el-icon-warning-outline'" /> {{ activeLineValid ? '当前行信息完整，可保存。' : '当前行仍有必填项未完成。' }}</p>
      </aside>
    </div>

    <purchase-attachment-panel
      title="到货附件"
      document-type="receipt"
      :document-id="$route.params.id || null"
      :draft-token="attachmentDraftToken"
      :initial-attachments="form.attachments || []"
    />

    <purchase-item-picker ref="itemPicker" @select="applyPickedItem" />

    <el-dialog title="入库库位分配" :visible.sync="allocationDialog.visible" width="820px" :close-on-click-modal="false" append-to-body>
      <div v-if="allocationDialog.line" class="allocation-dialog-body">
        <el-alert :closable="false" type="info" show-icon :title="`同一批次可拆分到多个库位；已分配基本数量必须等于合格基本数量 ${number(qualifiedBaseQty(allocationDialog.line))}。`" />
        <div class="allocation-toolbar"><strong>{{ activeAllocationItemName }}</strong><el-button size="mini" type="success" icon="el-icon-plus" @click="addAllocationRow">添加库位</el-button></div>
        <el-table :data="allocationDialog.rows" size="mini" border>
          <el-table-column type="index" label="序号" width="52" align="center" />
          <el-table-column label="仓库" width="132"><template slot-scope="{row}"><el-select v-model="row.warehouse_id" size="small" placeholder="选择仓库" @change="row.location_id=null"><el-option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id" :label="warehouse.warehouse_name" /></el-select></template></el-table-column>
          <el-table-column label="库位" width="142"><template slot-scope="{row}"><el-select v-model="row.location_id" size="small" placeholder="选择库位"><el-option v-for="location in filteredLocations(row.warehouse_id)" :key="location.id" :value="location.id" :label="location.location_name" /></el-select></template></el-table-column>
          <el-table-column label="基本数量" width="105"><template slot-scope="{row}"><el-input-number v-model="row.base_qty" size="small" :min="0" :precision="allocationPrecision" :controls="false" :disabled="allocationUsesSerials" /></template></el-table-column>
          <el-table-column v-if="allocationUsesSerials" label="设备编号 / 序列号" min-width="235"><template slot-scope="{row}"><el-select v-model="row.serial_nos" size="small" multiple filterable collapse-tags placeholder="分配已录入编号" @change="row.base_qty=row.serial_nos.length"><el-option v-for="entry in allocationSerialOptions" :key="entry.serial_no" :value="entry.serial_no" :label="entry.serial_no" :disabled="serialAssignedElsewhere(entry.serial_no,row)" /></el-select></template></el-table-column>
          <el-table-column label="操作" width="55" align="center"><template slot-scope="{$index}"><el-button type="text" class="danger-link" @click="removeAllocationRow($index)">删除</el-button></template></el-table-column>
        </el-table>
        <div :class="['allocation-total',{invalid:!allocationDialogValid}]"><span>已分配 {{ number(allocationDialogTotal) }} / {{ number(qualifiedBaseQty(allocationDialog.line)) }}</span><span v-if="allocationUsesSerials">已分配编号 {{ allocationAssignedSerialCount }} / {{ allocationSerialOptions.length }}</span><span v-else-if="isSerialManaged(allocationDialog.line)">编号生成/扫码后按配额自动落位</span><strong>{{ allocationDialogValid ? '分配完整' : '分配未完成' }}</strong></div>
      </div>
      <span slot="footer"><el-button size="small" @click="allocationDialog.visible=false">取消</el-button><el-button size="small" type="success" @click="confirmAllocationDialog">确认分配</el-button></span>
    </el-dialog>

    <footer class="bottom-actions"><el-button @click="goBack">返回列表</el-button><el-button :loading="saving" @click="save(true)">保存草稿</el-button><el-button type="success" :loading="saving" @click="save(false)">保存</el-button></footer>
  </section>
</template>

<script>
import { listEntity, listItemPurchaseConversionOptions } from '@/api/erp/master'
import { generateReceiptSerials, getPurchase, savePurchaseReceipt } from '@/api/erp/purchase'
import { reserveForCreatePage, clearCreatePageReservation } from '@/utils/documentNumberReservation'
import PurchaseItemPicker from '@/components/purchase/PurchaseItemPicker.vue'
import PurchaseAttachmentPanel from '@/components/purchase/PurchaseAttachmentPanel.vue'

export default {
  components: { PurchaseItemPicker, PurchaseAttachmentPanel },
  data: () => ({
    form: { receipt_no: '', supplier_id: null, receipt_date: '', confirm_status: 'draft', stock_post_status: 'pending', remark: '', items: [] },
    items: [], suppliers: [], warehouses: [], locations: [], activeIndex: 0, reservation: null, saving: false, pickerTarget: null, attachmentDraftToken: '',
    allocationDialog: { visible: false, line: null, rows: [] }
  }),
  computed: {
    isEdit() { return Boolean(this.$route.params.id) },
    isReplacement() { return this.form.settlement_mode === 'replacement_no_charge' },
    activeLine() { return this.form.items[this.activeIndex] || null },
    activeItem() { return this.activeLine ? this.itemById(this.activeLine.item_id) : {} },
    activeAllocationItemName() { return this.allocationDialog.line ? `${this.itemById(this.allocationDialog.line.item_id).item_code || ''} ${this.itemById(this.allocationDialog.line.item_id).item_name || ''}`.trim() : '' },
    allocationUsesSerials() { return Boolean(this.allocationDialog.line && this.isSerialManaged(this.allocationDialog.line) && this.serialNumberList(this.allocationDialog.line).length) },
    allocationSerialOptions() { return this.allocationDialog.line ? this.serialNumberList(this.allocationDialog.line) : [] },
    allocationDialogTotal() { return this.allocationDialog.rows.reduce((sum, row) => sum + Number(row.base_qty || 0), 0) },
    allocationAssignedSerialCount() { return new Set(this.allocationDialog.rows.flatMap(row => row.serial_nos || [])).size },
    allocationPrecision() { return 6 },
    allocationDialogValid() {
      if (!this.allocationDialog.line || !this.allocationDialog.rows.length) return false
      if (this.allocationDialog.rows.some(row => !row.warehouse_id || !row.location_id || Number(row.base_qty || 0) <= 0)) return false
      const locators = this.allocationDialog.rows.map(row => `${row.warehouse_id}-${row.location_id}`)
      if (new Set(locators).size !== locators.length || Math.abs(this.allocationDialogTotal - this.qualifiedBaseQty(this.allocationDialog.line)) > 0.000001) return false
      return !this.allocationUsesSerials || this.allocationAssignedSerialCount === this.allocationSerialOptions.length
    },
    validSuppliers() { return this.suppliers.filter(row => row.supplier_name && row.status === 'enabled' && (row.approval_status || 'approved') === 'approved' && !row.is_blacklisted && !row.purchase_restricted) },
    confirmStatusText() { return ({ draft: '草稿', confirmed: '已确认', cancelled: '已取消' })[this.form.confirm_status || this.form.receipt_status] || '草稿' },
    postingStatusText() { return ({ pending: '待过账', posted: '已过账', reversed: '已冲销', not_required: '无需过账' })[this.form.stock_post_status] || '待过账' },
    quantitySummary() {
      const groups = new Map()
      this.form.items.forEach(line => { if (!line.item_id || !Number(line.qty)) return; const unit = this.purchaseUnitName(line); groups.set(unit, Number(groups.get(unit) || 0) + Number(line.qty)) })
      return groups.size ? [...groups.entries()].map(([unit, qty]) => `${this.number(qty)} ${unit}`).join('；') : '0'
    },
    taxMode() { return this.form.order?.tax_mode || this.form.tax_mode_snapshot || 'tax_included' },
    untaxedAmount() { return this.form.items.reduce((sum, line) => { const amount = this.lineAmount(line); const rate = Number(line.tax_rate || 0); return sum + (this.taxMode === 'tax_included' && rate > 0 ? amount * 100 / (100 + rate) : amount) }, 0) },
    taxAmount() { return this.form.items.reduce((sum, line) => { const amount = this.lineAmount(line); const rate = Number(line.tax_rate || 0); return sum + (this.taxMode === 'tax_included' && rate > 0 ? amount - amount * 100 / (100 + rate) : amount * rate / 100) }, 0) },
    totalAmount() { return this.taxMode === 'tax_included' ? this.form.items.reduce((sum, line) => sum + this.lineAmount(line), 0) : this.untaxedAmount + this.taxAmount },
    activeLineValid() {
      const line = this.activeLine
      if (!line || !line.item_id || !line.purchase_unit_id || !Number(line.qty) || !this.lineAllocationValid(line)) return false
      if (Math.abs(Number(line.qualified_qty || 0) + Number(line.unqualified_qty || 0) - Number(line.qty || 0)) > 0.000001) return false
      if (this.hasDifference(line) && !String(line.difference_reason || '').trim()) return false
      return !this.isSerialManaged(line) || this.serialTrackingMode(line) === 'optional' || this.serialNumberList(line).length === this.serialRequiredCount(line)
    }
  },
  watch: { '$route.fullPath'(next, previous) { if (next !== previous) this.initializeDocument() } },
  async mounted() {
    // A missing auxiliary lookup must never prevent an existing receipt from
    // being loaded.  The receipt itself is the primary document; warehouse
    // and location options are only required when the user edits an
    // allocation.
    const [suppliers, warehouses, locations] = await Promise.allSettled([
      listEntity('suppliers', { status: 'enabled', page: 1, per_page: 100 }),
      listEntity('warehouses', { page: 1, per_page: 100 }),
      listEntity('locations', { page: 1, per_page: 100 })
    ])
    this.suppliers = suppliers.status === 'fulfilled' ? (suppliers.value.data.data || []) : []
    this.warehouses = warehouses.status === 'fulfilled' ? (warehouses.value.data.data || []).filter(row => ['active', 'enabled'].includes(row.status)) : []
    this.locations = locations.status === 'fulfilled' ? (locations.value.data.data || []).filter(row => ['active', 'enabled'].includes(row.status)) : []
    await this.initializeDocument()
  },
  methods: {
    blankLine() { return { item_id: null, qty: 1, purchase_unit_id: null, qualified_qty: 1, unqualified_qty: 0, actual_base_qty: 0, unit_price: 0, tax_rate: 13, difference_reason: '', batch_no: '', expected_arrival_date: this.form.receipt_date, warehouse_id: null, location_id: null, remark: '', serial_text: '', serial_number_source: 'supplier', _conversionOptions: [], _serialEntries: [], _allocations: [], _scanInput: '', _serialError: '' } },
    async initializeDocument() {
      this.activeIndex = 0
      this.reservation = null
      if (this.isEdit) return this.loadExisting()
      this.attachmentDraftToken = this.newDraftToken()
      const today = new Date().toISOString().slice(0, 10)
      this.form = { receipt_no: '', supplier_id: null, receipt_date: today, confirm_status: 'draft', stock_post_status: 'pending', remark: '', items: [] }
      this.form.items.push(this.blankLine())
      try {
        this.reservation = await reserveForCreatePage('purchase_receipt', this.$route.path)
        this.form.receipt_no = this.reservation.document_no
      } catch (error) { this.$message.error(error.userMessage || '单据编号预生成失败') }
    },
    async loadExisting() {
      try {
        const response = await getPurchase('receipts', this.$route.params.id)
        const data = response.data
        this.attachmentDraftToken = ''
        ;(data.items || []).forEach(line => this.rememberItem(line.item))
        this.form = { ...data, items: (data.items || []).map(this.normalizeLine) }
        await Promise.all(this.form.items.map(line => this.loadLineConversions(line, false)))
      } catch (error) {
        this.$message.error(error.userMessage || '到货单加载失败，请返回列表后重试')
      }
    },
    normalizeLine(line) {
      const entries = Array.isArray(line.serial_entries) && line.serial_entries.length ? line.serial_entries : String(line.serial_text || '').split(/\r?\n|,|，/).map(value => value.trim()).filter(Boolean).map(serial_no => ({ serial_no, source: line.serial_number_source || 'supplier' }))
      const allocations = (line.allocations || []).map(row => ({ warehouse_id: row.warehouse_id, location_id: row.location_id, base_qty: Number(row.base_qty || 0), serial_nos: [...(row.serial_nos || [])] }))
      return { ...line, qty: Number(line.receipt_qty || line.qty || 0), qualified_qty: Number(line.qualified_qty || 0), unqualified_qty: Number(line.unqualified_qty || 0), unit_price: Number(line.unit_price || 0), tax_rate: Number(line.tax_rate == null ? 13 : line.tax_rate), actual_base_qty: line.actual_base_qty == null ? null : Number(line.actual_base_qty), _conversionOptions: [], _serialEntries: entries, _allocations: allocations, _scanInput: '', _serialError: '' }
    },
    addLine() { this.form.items.push(this.blankLine()); this.activeIndex = this.form.items.length - 1 },
    removeActiveLine() { if (this.activeLine) this.removeLine(this.activeIndex) },
    removeLine(index) { if (this.form.items.length === 1) return this.$message.warning('到货单至少保留一行明细'); this.form.items.splice(index, 1); this.activeIndex = Math.min(this.activeIndex, this.form.items.length - 1) },
    selectLine(row) { this.activeIndex = this.form.items.indexOf(row) },
    lineRowClass({ rowIndex }) { return rowIndex === this.activeIndex ? 'selected-receipt-row' : '' },
    openItemPicker(line) {
      if (this.isReplacement) return this.$message.info('换货补发到货单的来源物料已锁定')
      this.pickerTarget = line
      this.$refs.itemPicker.open({ currentId: line && line.item_id, params: { status: 'enabled', is_purchase_item: 1 } })
    },
    async applyPickedItem(item) {
      const line = this.pickerTarget
      if (!line || !item) return
      const changed = Number(line.item_id || 0) !== Number(item.id)
      this.rememberItem(item)
      this.$set(line, 'item_id', item.id)
      if (changed) {
        this.$set(line, 'purchase_unit_id', null)
        this.$set(line, '_conversionOptions', [])
        this.$set(line, '_serialEntries', [])
        this.$set(line, '_allocations', [])
        this.$set(line, '_scanInput', '')
        this.$set(line, '_serialError', '')
        this.$set(line, 'serial_text', '')
        this.$set(line, 'serial_number_source', 'supplier')
        await this.onItemChange(line)
      }
      this.pickerTarget = null
    },
    rememberItem(item) {
      if (!item || !item.id) return
      const index = this.items.findIndex(row => Number(row.id) === Number(item.id))
      if (index >= 0) this.$set(this.items, index, item)
      else this.items.push(item)
    },
    async onItemChange(line) { line._serialEntries = []; line.serial_text = ''; await this.loadLineConversions(line, true) },
    async loadLineConversions(line, chooseDefault = true) {
      if (!line.item_id) return this.$set(line, '_conversionOptions', [])
      const { data } = await listItemPurchaseConversionOptions(line.item_id, { page: 1, per_page: 100 })
      const item = this.itemById(line.item_id)
      const itemUnit = item && (item.unit?.standard_unit || item.unit?.standardUnit || item.unit)
      const options = [...(data.data || [])]
      if (itemUnit && !options.some(row => Number(row.purchase_unit_id) === Number(itemUnit.id))) options.unshift({ purchase_unit_id: itemUnit.id, base_unit_id: itemUnit.id, factor: 1, is_default: options.length === 0, allow_actual_conversion: false, purchase_unit: itemUnit, base_unit: itemUnit, identity_conversion: true })
      this.$set(line, '_conversionOptions', options)
      if (chooseDefault || !line.purchase_unit_id) { const selected = options.find(row => row.is_default) || options[0]; if (selected) this.$set(line, 'purchase_unit_id', selected.purchase_unit_id) }
      this.applyConversion(line)
    },
    selectedConversion(line) { return (line._conversionOptions || []).find(row => Number(row.purchase_unit_id) === Number(line.purchase_unit_id)) },
    applyConversion(line) {
      const conversion = this.selectedConversion(line)
      if (!conversion) return
      this.$set(line, 'conversion_factor_preview', Number(conversion.factor || 1))
      this.$set(line, 'purchase_unit_name_preview', conversion.purchase_unit && conversion.purchase_unit.unit_name)
      this.$set(line, 'base_unit_name_preview', conversion.base_unit && conversion.base_unit.unit_name)
      this.$set(line, 'allow_actual_conversion_preview', Boolean(conversion.allow_actual_conversion))
      if (!this.actualConversionAllowed(line)) this.$set(line, 'actual_base_qty', this.standardBaseQtyNumber(line))
    },
    syncReceiptActualQty(line) { if (!this.actualConversionAllowed(line)) this.$set(line, 'actual_base_qty', this.standardBaseQtyNumber(line)) },
    purchaseOptionLabel(option) { const unit = option.purchase_unit || {}; return `${unit.unit_name || unit.symbol || '-'}（1 = ${this.number(option.factor || 1)} ${option.base_unit?.unit_name || '基本单位'}）` },
    purchaseUnitName(line) { const conversion = this.selectedConversion(line); return line.purchase_unit_name_snapshot || line.purchase_unit_name_preview || conversion?.purchase_unit?.unit_name || '-' },
    conversionFactor(line) { return this.number(line.conversion_factor_snapshot || line.conversion_factor_preview || 0) },
    standardBaseQtyNumber(line) { const factor = Number(line.conversion_factor_snapshot || line.conversion_factor_preview || 0); return Number(line.qty || 0) * factor },
    standardBaseQty(line) { return `${this.number(this.standardBaseQtyNumber(line))} ${line.base_unit_name_snapshot || line.base_unit_name_preview || ''}`.trim() },
    actualConversionAllowed(line) { return Boolean(line.allow_actual_conversion || line.allow_actual_conversion_preview || line.allow_actual_conversion_snapshot) },
    differenceQtyNumber(line) { return Number(line.actual_base_qty == null ? this.standardBaseQtyNumber(line) : line.actual_base_qty) - this.standardBaseQtyNumber(line) },
    differenceQty(line) { return `${this.number(this.differenceQtyNumber(line))} ${line.base_unit_name_snapshot || line.base_unit_name_preview || ''}`.trim() },
    hasDifference(line) { return Math.abs(this.differenceQtyNumber(line)) > 0.000001 },
    filteredLocations(warehouseId) { return warehouseId ? this.locations.filter(row => Number(row.warehouse_id) === Number(warehouseId)) : [] },
    itemById(id) { return this.items.find(row => Number(row.id) === Number(id)) || {} },
    isStockManaged(line) { const item = this.itemById(line && line.item_id); return line && line.is_stock_item_snapshot != null ? Boolean(line.is_stock_item_snapshot) : Boolean(item.is_stock_item) },
    warehouseName(id) { return this.warehouses.find(row => Number(row.id) === Number(id))?.warehouse_name || '-' },
    locationName(id) { return this.locations.find(row => Number(row.id) === Number(id))?.location_name || '-' },
    qualifiedBaseQty(line) { const received = Number(line.qty || 0); const actual = Number(line.actual_base_qty == null ? this.standardBaseQtyNumber(line) : line.actual_base_qty); return received > 0 ? actual * Number(line.qualified_qty || 0) / received : 0 },
    allocations(line) { return Array.isArray(line._allocations) ? line._allocations : [] },
    allocationSummary(line) {
      const rows = this.allocations(line)
      if (!rows.length) return `${this.warehouseName(line.warehouse_id)} / ${this.locationName(line.location_id)}`
      if (rows.length === 1) return `${this.warehouseName(rows[0].warehouse_id)} / ${this.locationName(rows[0].location_id)}`
      return `${this.warehouseName(rows[0].warehouse_id)} · ${rows.length} 个库位`
    },
    allocationProgressText(line) { const rows = this.allocations(line); if (this.qualifiedBaseQty(line) <= 0) return '全不合格，无需入库'; return rows.length ? `${rows.length} 个库位，${this.number(rows.reduce((sum,row)=>sum+Number(row.base_qty||0),0))} / ${this.number(this.qualifiedBaseQty(line))}` : '默认单库位' },
    lineAllocationValid(line) {
      if (!this.isStockManaged(line)) return true
      if (this.qualifiedBaseQty(line) <= 0) return true
      const rows = this.allocations(line)
      if (!rows.length) return Boolean(line.warehouse_id && line.location_id)
      if (rows.some(row => !row.warehouse_id || !row.location_id || Number(row.base_qty || 0) <= 0)) return false
      const locators = rows.map(row => `${row.warehouse_id}-${row.location_id}`)
      if (new Set(locators).size !== locators.length || Math.abs(rows.reduce((sum,row)=>sum+Number(row.base_qty||0),0)-this.qualifiedBaseQty(line))>0.000001) return false
      const serials = rows.flatMap(row => row.serial_nos || [])
      return !this.isSerialManaged(line) || !this.serialNumberList(line).length || (new Set(serials).size === this.serialNumberList(line).length && serials.length === this.serialNumberList(line).length)
    },
    onDefaultWarehouseChange(line) { this.$set(line, 'location_id', null); if (this.allocations(line).length <= 1) this.$set(line, '_allocations', []) },
    onDefaultLocationChange(line) { if (this.allocations(line).length <= 1) this.$set(line, '_allocations', []) },
    openAllocationDialog(line) {
      if (!line.item_id) return this.$message.warning('请先选择物料')
      const existing = this.allocations(line)
      const rows = existing.length ? existing : [{ warehouse_id: line.warehouse_id || null, location_id: line.location_id || null, base_qty: this.qualifiedBaseQty(line), serial_nos: this.serialNumberList(line).map(entry => entry.serial_no) }]
      this.allocationDialog = { visible: true, line, rows: rows.map(row => ({ warehouse_id: row.warehouse_id, location_id: row.location_id, base_qty: Number(row.base_qty || 0), serial_nos: [...(row.serial_nos || [])] })) }
    },
    addAllocationRow() { this.allocationDialog.rows.push({ warehouse_id: null, location_id: null, base_qty: 0, serial_nos: [] }) },
    removeAllocationRow(index) { if (this.allocationDialog.rows.length === 1) return this.$message.warning('至少保留一个入库库位'); this.allocationDialog.rows.splice(index,1) },
    serialAssignedElsewhere(serialNo, currentRow) { return this.allocationDialog.rows.some(row => row !== currentRow && (row.serial_nos || []).includes(serialNo)) },
    confirmAllocationDialog() {
      if (!this.allocationDialogValid) return this.$message.error('库位分配数量、库位或设备编号尚未分配完整')
      const line = this.allocationDialog.line
      const rows = this.allocationDialog.rows.map(row => ({ warehouse_id: row.warehouse_id, location_id: row.location_id, base_qty: Number(row.base_qty), serial_nos: [...(row.serial_nos || [])] }))
      this.$set(line, '_allocations', rows)
      this.$set(line, 'warehouse_id', rows[0].warehouse_id)
      this.$set(line, 'location_id', rows[0].location_id)
      if (this.serialNumberList(line).length) this.autoAssignSerialsToAllocations(line)
      this.allocationDialog.visible = false
      this.$message.success(`已分配到 ${rows.length} 个库位`)
    },
    serialTrackingMode(line) { if (!this.isStockManaged(line)) return 'none'; const item = this.itemById(line.item_id); return item.serial_tracking_mode || (item.is_serial_managed ? 'required' : 'none') },
    isSerialManaged(line) { return this.serialTrackingMode(line) !== 'none' },
    serialRequiredCount(line) { const received = Number(line.qty || 0); const actual = Number(line.actual_base_qty == null ? this.standardBaseQtyNumber(line) : line.actual_base_qty); const quantity = received > 0 ? actual * Number(line.qualified_qty || 0) / received : 0; return Number.isInteger(quantity) && quantity > 0 ? quantity : 0 },
    serialNumberList(line) { return Array.isArray(line._serialEntries) ? line._serialEntries : [] },
    serialProgress(line) { const required = this.serialRequiredCount(line); return required ? Math.min(100, this.serialNumberList(line).length / required * 100) : 0 },
    syncSerialText(line) { this.$set(line, 'serial_text', this.serialNumberList(line).map(entry => entry.serial_no).join('\n')); this.$set(line, 'serial_entries', this.serialNumberList(line).map(entry => ({ serial_no: entry.serial_no, source: entry.source }))); this.$set(line, 'serial_number_source', this.serialNumberList(line).every(entry => entry.source === 'system_generated') ? 'system_generated' : 'supplier') },
    autoAssignSerialsToAllocations(line) {
      const rows = this.allocations(line)
      if (!rows.length) return
      const serials = this.serialNumberList(line).map(entry => entry.serial_no)
      let offset = 0
      rows.forEach(row => {
        const quota = Math.max(0, Math.round(Number(row.base_qty || 0)))
        this.$set(row, 'serial_nos', serials.slice(offset, offset + quota))
        offset += quota
      })
    },
    async generateLineSerials(line) {
      const quantity = this.serialRequiredCount(line)
      if (!quantity) return this.$message.error('合格实际入库数量必须是大于 0 的整数')
      try {
        const response = await generateReceiptSerials({ item_id: line.item_id, quantity })
        this.$set(line, '_serialEntries', (response.data.data || []).map(serial_no => ({ serial_no, source: 'system_generated' })))
        this.$set(line, '_scanInput', '')
        line._serialError = ''
        this.syncSerialText(line)
        this.autoAssignSerialsToAllocations(line)
        this.$message.success(`已一次生成 ${quantity} 个设备编号`)
      } catch (error) { this.$message.error(error.userMessage || '设备编号生成失败') }
    },
    addScannedSerial(line) {
      const serialNo = String(line._scanInput || '').trim()
      if (!serialNo) return
      const duplicate = this.form.items.some(item => this.serialNumberList(item).some(entry => entry.serial_no === serialNo))
      if (duplicate) { line._serialError = '该编号已录入，请勿重复扫描'; return }
      line._serialEntries.push({ serial_no: serialNo, source: 'supplier' })
      const target = this.allocations(line).find(row => (row.serial_nos || []).length < Math.round(Number(row.base_qty || 0)))
      if (target) this.$set(target, 'serial_nos', [...(target.serial_nos || []), serialNo])
      line._scanInput = ''; line._serialError = ''
      this.syncSerialText(line)
    },
    removeSerial(line, index) { const removed = line._serialEntries[index]?.serial_no; line._serialEntries.splice(index, 1); this.allocations(line).forEach(row => this.$set(row, 'serial_nos', (row.serial_nos || []).filter(no => no !== removed))); this.syncSerialText(line) },
    printSerialLabels(line, serialNo = '') {
      const serials = serialNo ? [serialNo] : this.serialNumberList(line).map(entry => entry.serial_no)
      if (!serials.length) return this.$message.warning('请先录入或生成设备编号')
      const item = this.itemById(line.item_id)
      const escapeHtml = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]))
      const labels = serials.map(value => `<article><div class="title">设备编号 / 序列号</div><div class="serial">${escapeHtml(value)}</div><div class="meta">物料：${escapeHtml(item.item_code || '-')} / ${escapeHtml(item.item_name || '-')}</div><div class="meta">到货单：${escapeHtml(this.form.receipt_no || '-')}</div></article>`).join('')
      const popup = window.open('', '_blank', 'width=760,height=640')
      if (!popup) return this.$message.error('打印窗口被浏览器拦截，请允许弹出窗口')
      popup.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>设备编号标签</title><style>@page{size:70mm 40mm;margin:3mm}*{box-sizing:border-box}body{margin:0;font-family:Arial,"Microsoft YaHei"}article{width:64mm;height:34mm;padding:4mm;border:1px solid #222;page-break-after:always}.title{font-size:10pt}.serial{margin:3mm 0;font-size:16pt;font-weight:700;word-break:break-all}.meta{font-size:8.5pt;line-height:1.5}article:last-child{page-break-after:auto}</style></head><body>${labels}</body></html>`)
      popup.document.close(); popup.focus(); window.setTimeout(() => popup.print(), 250)
    },
    lineAmount(line) { return Number(line.qty || 0) * Number(line.unit_price || 0) },
    payload() { return { ...this.form, attachment_draft_token: this.attachmentDraftToken, reservation_token: this.reservation?.reservation_token, creation_session_id: this.reservation?.creation_session_id, items: this.form.items.map(line => { const stock = this.isStockManaged(line); return { ...line, receipt_qty: line.qty, warehouse_id: stock ? line.warehouse_id : null, location_id: stock ? line.location_id : null, batch_no: stock ? line.batch_no : null, serial_text: stock ? line.serial_text : null, serial_entries: stock ? this.serialNumberList(line).map(entry => ({ serial_no: entry.serial_no, source: entry.source })) : [], allocations: stock ? (this.allocations(line).length ? this.allocations(line) : (line.warehouse_id && line.location_id ? [{ warehouse_id: line.warehouse_id, location_id: line.location_id, base_qty: this.qualifiedBaseQty(line), serial_nos: this.serialNumberList(line).map(entry => entry.serial_no) }] : [])) : [] } }) } },
    newDraftToken() { return window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : `purchase-${Date.now()}-${Math.random().toString(16).slice(2)}` },
    validate(strict) {
      if (!this.form.supplier_id) return '供应商不能为空'
      if (!this.form.items.length || this.form.items.some(line => !line.item_id)) return '每一行都必须选择物料'
      if (this.form.items.some(line => !line.purchase_unit_id)) return '每一行都必须选择有效采购单位'
      if (this.form.items.some(line => Math.abs(Number(line.qualified_qty || 0) + Number(line.unqualified_qty || 0) - Number(line.qty || 0)) > 0.000001)) return '合格数量与不合格数量之和必须等于到货数量'
      if (this.form.items.some(line => this.hasDifference(line) && !String(line.difference_reason || '').trim())) return '存在基本数量差异时必须填写差异原因'
      if (strict && this.form.items.some(line => this.serialTrackingMode(line) === 'required' && this.serialNumberList(line).length !== this.serialRequiredCount(line))) return '必须逐件编号的物料，已录入编号数量必须与合格实际入库数量一致'
      if (strict && this.form.items.some(line => !this.lineAllocationValid(line))) return '每一行合格基本数量必须完整分配到有效仓库和库位；设备编号不得漏分或重复分配'
      return ''
    },
    async save(draft) {
      const message = this.validate(!draft)
      if (message) return this.$message.error(message)
      this.saving = true
      try {
        const response = await savePurchaseReceipt(this.payload())
        if (!this.isEdit && this.reservation) clearCreatePageReservation(this.reservation)
        this.$message.success(draft ? '到货单草稿已保存' : '到货单已保存')
        this.$router.push('/purchase/receipts')
        return response
      } catch (error) { this.$message.error(error.userMessage || '保存失败') } finally { this.saving = false }
    },
    goBack() { this.$router.push('/purchase/receipts') },
    number(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 6 }) },
    money(value) { return Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
  }
}
</script>

<style scoped>
.receipt-form-page{min-height:calc(100vh - 52px);padding:14px 16px 68px;background:#f7f8fa;color:#26313b;overflow-x:hidden}.page-heading{height:48px;display:flex;align-items:flex-start;justify-content:space-between}.page-heading h1{margin:3px 0 0;font-size:18px;color:#17212b}.heading-actions{display:flex;gap:9px}.posting-alert{margin-bottom:10px}.basic-panel,.lines-section,.line-editor,.document-summary{background:#fff;border:1px solid #e4e9ed;border-radius:5px}.basic-panel{padding:14px 16px;margin-bottom:12px}.basic-grid{display:grid;grid-template-columns:1.15fr 1.15fr 1.15fr .65fr .8fr;gap:26px;align-items:end}.field-block,.compact-field,.scan-label{display:grid;gap:6px;color:#3d4852;font-size:11px}.field-block.required>span:after,.compact-field.required>span:after{content:' *';color:#e14d50}.field-block .el-select,.field-block .el-date-editor,.compact-field .el-select,.compact-field .el-date-editor{width:100%}.status-field{display:grid;align-content:center;justify-items:start;gap:9px;min-height:55px;color:#3d4852;font-size:11px}.remark-field{display:grid;grid-template-columns:72px minmax(0,1fr);align-items:start;margin-top:12px;color:#3d4852}.remark-field>span{padding-top:8px}.lines-section{margin-bottom:12px;overflow:hidden}.section-heading{height:54px;padding:0 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #edf0f2}.section-heading h2{margin:0;font-size:14px}.section-heading h2 small{color:#77818a;font-weight:400}.line-table-shell{width:100%;overflow:hidden}.line-table-shell ::v-deep .el-table th.el-table__cell{height:36px;background:#fafbfc;color:#303b45}.line-table-shell ::v-deep .el-table td.el-table__cell{height:48px;padding:5px 0}.line-table-shell ::v-deep .selected-receipt-row td{background:#edf9f2!important}.item-cell{display:grid}.item-cell strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px}.item-cell span{color:#79838d;font-size:10px}.serial-progress{display:grid;gap:3px}.serial-progress i{width:70px;height:4px;border-radius:3px;background:#e6e9ec;overflow:hidden}.serial-progress b{display:block;height:100%;background:#07883f}.danger-link{color:#e34b4f!important}.detail-layout{display:grid;grid-template-columns:minmax(0,1fr) 250px;gap:12px}.line-editor{min-width:0;overflow:hidden}.line-editor>header{height:40px;padding:0 14px;display:flex;align-items:center;border-bottom:1px solid #edf0f2;font-size:13px;font-weight:600}.line-editor>header span{margin-left:7px;color:#66727c;font-weight:400}.editor-columns{display:grid;grid-template-columns:1fr 1fr 1.18fr;min-height:350px}.editor-group{min-width:0;padding:13px 14px;border-right:1px solid #edf0f2}.editor-group:last-child{border-right:0}.editor-group h3{margin:0 0 12px;font-size:12px}.compact-field{grid-template-columns:96px minmax(0,1fr);align-items:center;margin-bottom:9px}.compact-field>span{white-space:nowrap}.remark-compact{align-items:start}.remark-compact>span{padding-top:7px}.serial-title{display:flex;align-items:center;justify-content:space-between}.serial-title h3{margin-bottom:9px}.generate-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end}.generate-row label{display:grid;grid-template-columns:68px 70px;align-items:center;gap:6px;font-size:11px}.scan-label{margin-top:10px}.serial-helper,.serial-error{height:17px;margin:4px 0 0;font-size:10px}.serial-helper{color:#8a949c}.serial-error{color:#e34b4f;font-weight:600}.serial-list-heading{height:28px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e8ecef;color:#53606a;font-size:11px}.serial-records{max-height:126px;overflow-y:auto}.serial-record{height:31px;display:grid;grid-template-columns:24px minmax(0,1fr) 54px 48px 18px;align-items:center;border-bottom:1px solid #edf0f2;font-size:10px}.serial-index{text-align:center}.serial-record strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:Consolas,monospace}.serial-record em{color:#6f7a83;font-style:normal}.serial-record .el-button{padding:0}.serial-delete{border:0;background:transparent;color:#ef4b4f;font-size:16px;cursor:pointer}.document-summary{padding:16px}.document-summary h3{margin:0 0 15px;font-size:13px}.document-summary dl{display:grid;grid-template-columns:1fr auto;gap:13px;margin:0}.document-summary dt{color:#6e7983}.document-summary dd{margin:0;font-weight:600}.document-summary hr{margin:16px 0;border:0;border-top:1px solid #e9edf0}.validation-state{color:#07883f;line-height:1.7}.validation-state.invalid{color:#dd8b1d}.validation-state i{margin-right:5px}.bottom-actions{position:fixed;left:192px;right:0;bottom:0;z-index:20;height:56px;padding:9px 16px;display:flex;justify-content:flex-end;gap:9px;background:#fff;border-top:1px solid #e2e7eb}
.item-picker-cell{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:8px;min-height:34px;padding:2px 6px;border:1px solid #d9e6dd;border-radius:4px;background:#fbfefc;cursor:pointer}.item-picker-cell:hover{border-color:#07883f;background:#f2faf5}.item-picker-cell .item-cell{min-width:0}.item-picker-cell .item-cell strong,.item-picker-cell .item-cell span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.item-picker-cell .el-button{padding:4px 0;color:#07883f}
.item-picker-cell.locked{border-color:#e2e6ea;background:#f7f8f9;cursor:default}.item-picker-cell.locked:hover{border-color:#e2e6ea;background:#f7f8f9}.item-picker-cell.locked .el-button{color:#7c8790}.document-summary .no-payable{color:#07883f}
.allocation-entry{display:grid;grid-template-columns:96px minmax(0,1fr);align-items:center;margin:-1px 0 9px;color:#3d4852;font-size:11px}.allocation-entry>div{display:flex;align-items:center;gap:8px;min-width:0}.allocation-entry small{overflow:hidden;color:#6f7a83;text-overflow:ellipsis;white-space:nowrap}.serial-allocation-tip{margin:7px 0 0;padding:5px 7px;border-radius:3px;background:#eef8f2;color:#087b3d;font-size:10px}.allocation-dialog-body{display:grid;gap:12px}.allocation-toolbar{display:flex;align-items:center;justify-content:space-between}.allocation-dialog-body ::v-deep .el-select,.allocation-dialog-body ::v-deep .el-input-number{width:100%}.allocation-total{display:flex;align-items:center;justify-content:flex-end;gap:18px;padding:10px 12px;border:1px solid #cce8d7;border-radius:4px;background:#f1faf5;color:#087b3d}.allocation-total.invalid{border-color:#f0cf9a;background:#fff8ed;color:#c4770e}.allocation-total strong{min-width:70px;text-align:right}
@media(max-width:1180px){.basic-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.detail-layout{grid-template-columns:minmax(0,1fr)}.document-summary{display:grid;grid-template-columns:150px 1fr 1px 130px 1fr;align-items:center;gap:12px}.document-summary h3,.document-summary dl,.document-summary hr,.document-summary p{margin:0}.document-summary dl{grid-template-columns:repeat(4,auto);gap:8px 16px}.document-summary hr{height:50px;border-left:1px solid #e9edf0}}
@media(max-width:900px){.editor-columns{grid-template-columns:minmax(0,1fr)}.editor-group{border-right:0;border-bottom:1px solid #edf0f2}.basic-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.document-summary{display:block}.document-summary dl{grid-template-columns:1fr auto}.document-summary hr{height:auto;border-left:0;border-top:1px solid #e9edf0}.bottom-actions{left:0}}
</style>
