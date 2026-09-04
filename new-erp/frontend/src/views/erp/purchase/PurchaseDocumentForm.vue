<template>
  <section class="purchase-form-page">
    <header class="form-top">
      <div>
        <h1>{{ title }}</h1>
        <p>{{ subtitle }}</p>
      </div>
      <el-button size="small" @click="$router.back()">返回列表</el-button>
    </header>

    <el-alert v-if="type==='receipt'" class="receipt-posting-tip" title="当前确认仅生成待过账库存记录，不直接更新正式库存余额。正式库存过账由库存模块完成。" type="info" :closable="false" show-icon />

    <div class="form-layout">
        <section class="form-card basic-info-card">
          <h3>基础信息</h3>
          <el-form label-width="96px" size="small">
            <div class="grid-3" v-if="type==='request'">
              <el-form-item label="需求单号"><el-input v-model="form.request_no" disabled placeholder="正在预生成"><template slot="append">系统预生成</template></el-input></el-form-item>
              <el-form-item label="需求日期"><el-date-picker v-model="form.request_date" value-format="yyyy-MM-dd" /></el-form-item>
              <el-form-item label="来源类型"><el-input v-model="form.source_type" /></el-form-item>
              <el-form-item label="来源单号"><el-input v-model="form.source_no" /></el-form-item>
              <el-form-item label="状态"><el-input value="草稿 / 未确认" disabled /></el-form-item>
            </div>
            <div class="grid-3" v-if="type==='plan'">
              <el-form-item label="计划单号"><el-input v-model="form.plan_no" disabled placeholder="正在预生成"><template slot="append">系统预生成</template></el-input></el-form-item>
              <el-form-item label="计划日期"><el-date-picker v-model="form.plan_date" value-format="yyyy-MM-dd" /></el-form-item>
              <el-form-item label="状态"><el-input value="草稿 / 待审核" disabled /></el-form-item>
            </div>
            <div class="grid-3" v-if="type==='order'">
              <el-form-item label="采购订单号"><el-input v-model="form.purchase_order_no" disabled placeholder="正在预生成"><template slot="append">系统预生成</template></el-input></el-form-item>
              <el-form-item label="供应商"><el-select v-model="form.supplier_id" filterable><el-option v-for="s in validSuppliers" :key="s.id" :label="`${s.supplier_code} / ${s.supplier_name}`" :value="s.id" /></el-select></el-form-item>
              <el-form-item label="订单日期"><el-date-picker v-model="form.order_date" value-format="yyyy-MM-dd" /></el-form-item>
              <el-form-item label="预计到货"><el-date-picker v-model="form.expected_arrival_date" value-format="yyyy-MM-dd" /></el-form-item>
              <el-form-item label="币种"><el-input v-model="form.currency" /></el-form-item>
              <el-form-item label="税率口径"><el-select v-model="form.tax_mode"><el-option label="含税" value="tax_included" /><el-option label="未税" value="tax_excluded" /></el-select></el-form-item>
              <el-form-item label="结算方式"><el-input v-model="form.settlement_method" /></el-form-item>
              <el-form-item label="交付方式"><el-input v-model="form.delivery_method" /></el-form-item>
              <el-form-item label="运费"><el-input-number v-model="form.freight_amount" :min="0" /></el-form-item>
            </div>
            <div class="grid-3" v-if="type==='receipt'">
              <el-form-item label="到货单号"><el-input v-model="form.receipt_no" disabled placeholder="正在预生成"><template slot="append">系统预生成</template></el-input></el-form-item>
              <el-form-item label="供应商"><el-select v-model="form.supplier_id" filterable><el-option v-for="s in validSuppliers" :key="s.id" :label="`${s.supplier_code} / ${s.supplier_name}`" :value="s.id" /></el-select></el-form-item>
              <el-form-item label="到货日期"><el-date-picker v-model="form.receipt_date" value-format="yyyy-MM-dd" /></el-form-item>
              <el-form-item label="确认状态"><el-input value="草稿" disabled /></el-form-item>
              <el-form-item label="库存过账状态"><el-input value="待库存过账" disabled /></el-form-item>
            </div>
            <el-form-item label="备注"><el-input v-model="form.remark" type="textarea" :rows="3" /></el-form-item>
          </el-form>
        </section>

      <main class="detail-workspace">

        <section v-if="type==='request'" class="form-card">
          <div class="section-title"><h3>需求物料明细</h3><el-button type="text" icon="el-icon-plus" @click="addRequestLine">添加明细</el-button></div>
          <el-table :data="form.items" size="mini" border>
            <el-table-column label="物料" min-width="260"><template slot-scope="{row}"><el-input class="item-picker-input" :value="itemName(row.item_id)" readonly><el-button slot="append" icon="el-icon-search" @click.stop="openItemPicker(row)">选择</el-button></el-input></template></el-table-column>
            <el-table-column label="需求数量" width="130"><template slot-scope="{row}"><el-input v-model="row.request_qty" type="number" min="0.0001" step="0.0001" /></template></el-table-column>
            <el-table-column label="单位" width="86"><template slot-scope="{row}">{{ itemUnitName(row.item_id) }}</template></el-table-column>
            <el-table-column label="期望到货" width="150"><template slot-scope="{row}"><el-date-picker v-model="row.expected_date" value-format="yyyy-MM-dd" /></template></el-table-column>
            <el-table-column label="目标仓库" width="150"><template slot-scope="{row}"><el-select v-model="row.warehouse_id" clearable><el-option v-for="w in warehouses" :key="w.id" :label="w.warehouse_name" :value="w.id" /></el-select></template></el-table-column>
            <el-table-column label="优先级" width="110"><template slot-scope="{row}"><el-select v-model="row.priority"><el-option label="高" value="high" /><el-option label="中" value="normal" /><el-option label="低" value="low" /></el-select></template></el-table-column>
            <el-table-column label="备注" min-width="140"><template slot-scope="{row}"><el-input v-model="row.remark" /></template></el-table-column>
            <el-table-column label="操作" width="70"><template slot-scope="{$index}"><el-button type="text" class="danger-link" @click="form.items.splice($index,1)">删除</el-button></template></el-table-column>
          </el-table>
        </section>

        <section v-if="type==='plan'" class="plan-editor">
          <aside class="material-list">
            <div class="section-title"><h3>计划物料明细</h3><el-button type="text" icon="el-icon-plus" @click="addPlanItem">添加物料</el-button></div>
            <div v-for="(line,index) in form.items" :key="index" class="material-card" :class="{active:index===activeIndex}" @click="activeIndex=index">
              <b>{{ itemName(line.item_id) }}</b>
              <el-button type="text" class="danger-link" @click.stop="removePlanItem(index)">删除</el-button>
              <span>总需求 {{ line.required_qty }}，已分配 {{ allocated(line) }}，剩余 {{ remaining(line) }}</span>
            </div>
          </aside>
          <main class="split-editor" v-if="activeLine">
            <div class="section-title">
              <h3>供应商拆分明细</h3>
              <el-button type="text" icon="el-icon-plus" @click="addSplit">添加供应商</el-button>
            </div>
            <div class="grid-3 material-base">
              <el-input class="item-picker-input" :value="itemName(activeLine.item_id)" readonly><el-button slot="append" icon="el-icon-search" @click="openItemPicker(activeLine)">选择</el-button></el-input>
              <el-input-number v-model="activeLine.required_qty" :min="1" placeholder="总需求" />
              <el-date-picker v-model="activeLine.expected_date" value-format="yyyy-MM-dd" placeholder="预计日期" />
            </div>
            <div class="recommend-card">
              <div class="section-title">
                <div><h3>供应商推荐</h3><span>按统一权重综合价格、质量、交付、退货和合作表现；悬停推荐依据可查看说明</span></div>
                <el-button size="mini" :loading="recommendLoading" @click="loadRecommendations">刷新推荐</el-button>
              </div>
              <el-table :data="recommendations" size="mini" border empty-text="请选择采购物料、数量和单位后获取推荐">
                <el-table-column prop="supplier_name" label="供应商" min-width="130" show-overflow-tooltip />
                <el-table-column label="能力" width="82"><template slot-scope="{row}">{{ capabilityText(row.capability_level) }}</template></el-table-column>
                <el-table-column label="可比价" width="90"><template slot-scope="{row}">{{ row.comparable_price == null ? '-' : money(row.comparable_price) }}</template></el-table-column>
                <el-table-column label="综合评分" width="82" align="right"><template slot-scope="{row}">{{ row.recommendation_score == null ? '-' : row.recommendation_score }}</template></el-table-column>
                <el-table-column label="推荐依据" min-width="150"><template slot-scope="{row}"><el-tooltip :content="row.recommendation_explanation || basisText(row.recommendation_basis)" placement="top"><el-tag size="mini" :type="row.recommended ? 'success' : 'info'">{{ basisText(row.recommendation_basis) }}</el-tag></el-tooltip></template></el-table-column>
                <el-table-column label="操作" width="76"><template slot-scope="{row}"><el-button type="text" size="mini" :disabled="!row.auto_selectable" @click="chooseRecommendation(row)">{{ row.auto_selectable ? '选择' : '仅候选' }}</el-button></template></el-table-column>
              </el-table>
            </div>
            <el-table :data="activeLine.splits" size="mini" border>
              <el-table-column label="供应商" min-width="170">
                <template slot-scope="{row}"><el-select v-model="row.supplier_id" filterable><el-option v-for="s in validSuppliers" :key="s.id" :label="s.supplier_name" :value="s.id" /></el-select></template>
              </el-table-column>
              <el-table-column label="采购数量" width="120"><template slot-scope="{row}"><el-input-number v-model="row.purchase_qty" :min="1" controls-position="right" /></template></el-table-column>
              <el-table-column label="单价" width="110"><template slot-scope="{row}"><el-input-number v-model="row.unit_price" :min="0" controls-position="right" /></template></el-table-column>
              <el-table-column label="税率" width="90"><template slot-scope="{row}"><el-input-number v-model="row.tax_rate" :min="0" :max="100" controls-position="right" /></template></el-table-column>
              <el-table-column label="预计到货" width="140"><template slot-scope="{row}"><el-date-picker v-model="row.expected_date" value-format="yyyy-MM-dd" /></template></el-table-column>
              <el-table-column label="操作" width="70"><template slot-scope="{$index}"><el-button type="text" class="danger-link" @click="activeLine.splits.splice($index,1)">删除</el-button></template></el-table-column>
            </el-table>
          </main>
        </section>

        <section v-if="['order', 'receipt'].includes(type)" class="form-card">
          <div class="section-title"><h3>{{ type==='order' ? '采购明细' : '到货明细' }}</h3><el-button type="text" icon="el-icon-plus" @click="addLine">添加明细</el-button></div>
          <div v-if="type==='order'" class="recommend-card">
            <div class="section-title">
              <div><h3>当前行供应商推荐</h3><span>点击采购明细行后查询；按统一权重综合价格、质量、交付、退货和合作表现</span></div>
              <el-button size="mini" :loading="recommendLoading" @click="loadRecommendations">刷新推荐</el-button>
            </div>
            <el-table :data="recommendations" size="mini" border empty-text="请选择一行采购物料后获取推荐">
              <el-table-column prop="supplier_name" label="供应商" min-width="130" show-overflow-tooltip />
              <el-table-column label="能力" width="82"><template slot-scope="{row}">{{ capabilityText(row.capability_level) }}</template></el-table-column>
              <el-table-column label="可比价" width="90"><template slot-scope="{row}">{{ row.comparable_price == null ? '-' : money(row.comparable_price) }}</template></el-table-column>
              <el-table-column label="综合评分" width="82" align="right"><template slot-scope="{row}">{{ row.recommendation_score == null ? '-' : row.recommendation_score }}</template></el-table-column>
              <el-table-column label="推荐依据" min-width="150"><template slot-scope="{row}"><el-tooltip :content="row.recommendation_explanation || basisText(row.recommendation_basis)" placement="top"><el-tag size="mini" :type="row.recommended ? 'success' : 'info'">{{ basisText(row.recommendation_basis) }}</el-tag></el-tooltip></template></el-table-column>
              <el-table-column label="操作" width="76"><template slot-scope="{row}"><el-button type="text" size="mini" :disabled="!row.auto_selectable" @click="chooseRecommendation(row)">选用</el-button></template></el-table-column>
            </el-table>
          </div>
          <el-table class="purchase-lines-table" :data="form.items" size="mini" border highlight-current-row @row-click="selectLine">
            <el-table-column label="物料" width="320"><template slot-scope="{row}"><el-input class="item-picker-input" :value="itemName(row.item_id)" readonly><el-button slot="append" icon="el-icon-search" @click.stop="openItemPicker(row)">选择</el-button></el-input></template></el-table-column>
            <el-table-column :label="type==='order' ? '采购包装数量' : '到货采购数量'" width="126"><template slot-scope="{row}"><el-input v-model.number="row.qty" type="number" min="0.0001" step="0.0001" @input="syncReceiptActualQty(row)" /></template></el-table-column>
            <el-table-column width="200">
              <template slot="header"><span>采购单位 <el-tooltip content="来自当前 Item 已生效的采购换算；库存基本单位按 1:1 补充" placement="top"><i class="el-icon-question unit-source-help" /></el-tooltip></span></template>
              <template slot-scope="{row}"><el-select v-if="type==='order'" v-model="row.purchase_unit_id" placeholder="请选择采购单位" no-data-text="当前Item未维护有效采购换算" @change="changePurchaseUnit(row)"><el-option v-for="c in row._conversionOptions || []" :key="c.id || `base-${c.purchase_unit_id}`" :label="purchaseOptionLabel(c)" :value="c.purchase_unit_id" /></el-select><span v-else>{{ row.purchase_unit_name_snapshot || conversionUnitName(row) }}</span></template>
            </el-table-column>
            <el-table-column label="换算因子" width="88" align="right"><template slot-scope="{row}">{{ conversionFactor(row) }}</template></el-table-column>
            <el-table-column :label="type==='order' ? '计划基本数量' : '标准基本数量'" width="120" align="right"><template slot-scope="{row}">{{ standardBaseQty(row) }} {{ baseUnitName(row) }}</template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="实际基本数量" width="132"><template slot-scope="{row}"><el-input v-model.number="row.actual_base_qty" type="number" :disabled="!actualConversionAllowed(row)" min="0" step="0.000001" /></template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="差异数量" width="110" align="right"><template slot-scope="{row}">{{ differenceQty(row) }} {{ baseUnitName(row) }}</template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="差异原因" min-width="150"><template slot-scope="{row}"><el-input v-model="row.difference_reason" :disabled="!hasDifference(row)" :placeholder="hasDifference(row) ? '必填' : '无差异'" /></template></el-table-column>
            <el-table-column :label="type==='order' ? '包装单价' : '单价'" :width="type==='order' ? 146 : 132"><template slot-scope="{row}"><el-input-number class="line-number-input" v-model="row.unit_price" :min="0" controls-position="right" /></template></el-table-column>
            <el-table-column v-if="type==='order'" label="基本单位单价" width="120" align="right"><template slot-scope="{row}">{{ baseUnitPrice(row) }}</template></el-table-column>
            <el-table-column v-if="type==='order'" label="税率（%）" width="136"><template slot-scope="{row}"><el-input-number class="line-number-input" v-model="row.tax_rate" :min="0" :max="100" controls-position="right" /></template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="合格/不合格" width="190"><template slot-scope="{row}"><el-input v-model.number="row.qualified_qty" type="number" min="0" step="0.0001" /><el-input v-model.number="row.unqualified_qty" type="number" min="0" step="0.0001" /></template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="批次号" width="150"><template slot-scope="{row}"><el-input v-model="row.batch_no" /></template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="设备编号 / 序列号" min-width="330"><template slot-scope="{row}">
              <div v-if="isSerialManaged(row)" class="serial-entry">
                <div class="serial-entry-tools"><span>{{ serialTrackingMode(row)==='required' ? '必须逐件编号' : '按需逐件编号' }}</span><el-button size="mini" type="success" plain @click.stop="generateLineSerials(row)">{{ serialGenerationButtonText(row) }}</el-button></div>
                <el-input v-model="row.serial_text" type="textarea" :rows="3" resize="vertical" placeholder="供应商SN可直接粘贴；每台一行" @input="markSupplierSerials(row)" />
                <div v-if="serialNumberList(row.serial_text).length" class="serial-number-panel">
                  <div class="serial-number-summary"><span>已录入 {{ serialNumberList(row.serial_text).length }} 个</span><el-button type="text" size="mini" icon="el-icon-printer" @click.stop="printSerialLabels(row)">全部打印</el-button></div>
                  <div class="serial-number-list">
                    <div v-for="serialNo in serialNumberList(row.serial_text)" :key="serialNo" class="serial-number-item"><span :title="serialNo">{{ serialNo }}</span><el-button type="text" size="mini" icon="el-icon-printer" @click.stop="printSerialLabels(row, serialNo)">打印</el-button></div>
                  </div>
                </div>
              </div>
              <span v-else>无需单件编号</span>
            </template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="目标仓库" width="150"><template slot-scope="{row}"><el-select v-model="row.warehouse_id" clearable><el-option v-for="w in warehouses" :key="w.id" :label="w.warehouse_name" :value="w.id" /></el-select></template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="目标库位" width="150"><template slot-scope="{row}"><el-select v-model="row.location_id" clearable><el-option v-for="l in filteredLocations(row.warehouse_id)" :key="l.id" :label="l.location_name" :value="l.id" /></el-select></template></el-table-column>
            <el-table-column label="预计到货" width="158"><template slot-scope="{row}"><el-date-picker v-model="row.expected_arrival_date" value-format="yyyy-MM-dd" /></template></el-table-column>
            <el-table-column label="备注" width="147"><template slot-scope="{row}"><el-input v-model="row.remark" /></template></el-table-column>
            <el-table-column label="操作" width="70"><template slot-scope="{$index}"><el-button type="text" class="danger-link" @click="form.items.splice($index,1)">删除</el-button></template></el-table-column>
          </el-table>
        </section>
      </main>

      <aside>
        <section class="form-card">
          <h3>金额 / 数量合计</h3>
          <dl>
            <dt>数量汇总</dt><dd>{{ quantitySummary }}</dd>
            <dt>未税金额</dt><dd>¥{{ money(untaxedAmount) }}</dd>
            <dt>税额</dt><dd>¥{{ money(taxAmount) }}</dd>
            <dt>含税金额</dt><dd>¥{{ money(totalAmount) }}</dd>
            <dt v-if="type==='plan'">预计订单数</dt><dd v-if="type==='plan'">{{ expectedOrderCount }} 张</dd>
          </dl>
        </section>
        <section class="form-card">
          <h3>状态边界</h3>
          <el-alert :title="guardText" type="warning" :closable="false" />
        </section>
      </aside>
    </div>

    <purchase-attachment-panel
      v-if="type==='order'"
      title="采购附件"
      document-type="order"
      :document-id="$route.params.id || null"
      :draft-token="attachmentDraftToken"
      :initial-attachments="form.attachments || []"
    />

    <purchase-item-picker ref="itemPicker" @select="applyPickedItem" />

    <footer class="bottom-actions">
      <el-button @click="$router.back()">取消</el-button>
      <el-button @click="save(false)">保存草稿</el-button>
      <el-button type="success" @click="save(true)">{{ type==='request' ? '确认需求' : type==='receipt' ? '保存' : '提交审核' }}</el-button>
    </footer>
  </section>
</template>

<script>
import { listEntity, listItemPurchaseConversionOptions } from '@/api/erp/master'
import { generateReceiptSerials, getPurchase, getSupplierRecommendations, savePurchaseRequest, savePurchasePlan, savePurchaseOrder, savePurchaseReceipt, submitRequest, submitPlan, submitOrder } from '@/api/erp/purchase'
import { reserveForCreatePage, clearCreatePageReservation } from '@/utils/documentNumberReservation'
import PurchaseItemPicker from '@/components/purchase/PurchaseItemPicker.vue'
import PurchaseAttachmentPanel from '@/components/purchase/PurchaseAttachmentPanel.vue'

export default {
  components: { PurchaseItemPicker, PurchaseAttachmentPanel },
  props: { type: { type: String, required: true } },
  data: () => ({
    form: { items: [] }, items: [], suppliers: [], warehouses: [], locations: [], activeIndex: 0,
    reservation: null, recommendations: [], recommendLoading: false, pickerTarget: null, attachmentDraftToken: ''
  }),
  computed: {
    title() { return `${this.$route.params.id ? '编辑' : '新增'}${this.type === 'request' ? '采购需求' : this.type === 'plan' ? '采购计划' : this.type === 'order' ? '采购订单' : '到货单'}` },
    subtitle() { return this.type === 'request' ? '采购需求是单据头 + 多行物料明细；确认需求不代表审批。' : this.type === 'plan' ? '左侧是物料需求，中间是供应商拆分，60/40 不再混在计划物料明细里。' : this.type === 'order' ? '采购订单是正式单据，一张订单只能有一个供应商。' : '到货确认只记录到货与验收结果，暂不更新正式库存。' },
    validSuppliers() {
      return this.suppliers.filter(s => s.supplier_name && s.status === 'enabled' &&
        (s.approval_status || 'approved') === 'approved' && !s.is_blacklisted &&
        (s.cooperation_status || 'normal') === 'normal' && !s.purchase_restricted &&
        (s.quality_status || 'normal') !== 'frozen')
    },
    activeLine() { return this.form.items[this.activeIndex] },
    totalQty() { return this.type === 'request' ? this.form.items.reduce((n, i) => n + Number(i.request_qty || 0), 0) : (this.type === 'plan' ? this.form.items.flatMap(i => i.splits || []).reduce((n, s) => n + Number(s.purchase_qty || 0), 0) : this.form.items.reduce((n, i) => n + Number(i.qty || 0), 0)) },
    quantitySummary() {
      const groups = new Map()
      const rows = this.form.items || []
      rows.forEach(line => {
        const quantity = this.type === 'request' ? line.request_qty : this.type === 'plan' ? line.required_qty : line.qty
        const unit = ['order', 'receipt'].includes(this.type) ? this.conversionUnitName(line) : this.itemUnitName(line.item_id)
        if (!line.item_id || !Number(quantity)) return
        groups.set(unit, Number(groups.get(unit) || 0) + Number(quantity))
      })
      return groups.size ? [...groups.entries()].map(([unit, quantity]) => `${Number(quantity).toLocaleString('zh-CN', { maximumFractionDigits: 6 })} ${unit}`).join('；') : '0'
    },
    untaxedAmount() {
      return this.linesForAmount().reduce((sum, line) => {
        const amount = Number(line.qty || 0) * Number(line.price || 0)
        const rate = Number(line.tax || 0)
        return sum + (this.form.tax_mode === 'tax_included' && rate > 0 ? amount * 100 / (100 + rate) : amount)
      }, 0)
    },
    taxAmount() {
      return this.linesForAmount().reduce((sum, line) => {
        const amount = Number(line.qty || 0) * Number(line.price || 0)
        const rate = Number(line.tax || 0)
        return sum + (this.form.tax_mode === 'tax_included' && rate > 0
          ? amount - amount * 100 / (100 + rate)
          : amount * rate / 100)
      }, 0)
    },
    totalAmount() {
      const lineAmount = this.form.tax_mode === 'tax_included'
        ? this.linesForAmount().reduce((sum, line) => sum + Number(line.qty || 0) * Number(line.price || 0), 0)
        : this.untaxedAmount + this.taxAmount
      return lineAmount + Number(this.form.freight_amount || 0)
    },
    expectedOrderCount() { return new Set(this.form.items.flatMap(i => (i.splits || []).map(s => s.supplier_id).filter(Boolean))).size },
    guardText() {
      if (this.type === 'request') return '确认需求后状态变为“已确认”，需求明细锁定，可转采购计划；取消或关闭后只读。'
      if (this.type === 'plan') return '未审核计划不能生成采购订单；已生成订单的计划不能再编辑供应商拆分。'
      if (this.type === 'order') return '未审核订单不能生成到货单；已到货、已关闭、已取消订单只读。'
      return '当前阶段只记录到货与验收结果，暂不更新正式库存。确认后进入“待库存过账”状态。'
    }
  },
  watch: {
    type(newType, oldType) {
      if (newType !== oldType) this.initializeDocument()
    },
    '$route.fullPath'(newPath, oldPath) {
      if (newPath !== oldPath) this.initializeDocument()
    }
  },
  async mounted() {
    const [suppliers, warehouses, locations] = await Promise.all([listEntity('suppliers', { status: 'enabled', per_page: 100 }), listEntity('warehouses', { per_page: 100 }), listEntity('locations', { per_page: 100 })])
    this.suppliers = suppliers.data.data || []
    this.warehouses = (warehouses.data.data || []).filter(row => ['active', 'enabled'].includes(row.status))
    this.locations = (locations.data.data || []).filter(row => ['active', 'enabled'].includes(row.status))
    await this.initializeDocument()
  },
  methods: {
    async initializeDocument() {
      this.activeIndex = 0
      this.recommendations = []
      this.reservation = null
      if (this.$route.params.id) await this.loadExisting()
      else {
        this.initBlank()
        await this.reserveNumber()
      }
    },
    initBlank() {
      const today = new Date().toISOString().slice(0, 10)
      this.attachmentDraftToken = this.newDraftToken()
      if (this.type === 'request') this.form = { request_no: '', request_date: today, source_type: 'manual', items: [{ item_id: null, request_qty: 1, expected_date: '', priority: 'normal' }] }
      if (this.type === 'plan') this.form = { plan_no: '', plan_date: today, items: [{ item_id: null, unit_id: null, required_qty: 1, expected_date: '', splits: [] }] }
      if (this.type === 'order') this.form = { purchase_order_no: '', supplier_id: null, order_date: today, currency: 'CNY', tax_mode: 'tax_included', freight_amount: 0, items: [{ item_id: null, qty: 1, unit_price: 0, tax_rate: 13, expected_arrival_date: '' }] }
      if (this.type === 'receipt') this.form = { receipt_no: '', supplier_id: null, receipt_date: today, items: [{ item_id: null, qty: 1, qualified_qty: 1, unqualified_qty: 0, actual_base_qty: 0, unit_price: 0, batch_no: '', serial_text: '', serial_number_source: 'supplier', warehouse_id: null, location_id: null }] }
    },
    async reserveNumber() {
      const documentTypes = { request: 'purchase_request', plan: 'purchase_plan', order: 'purchase_order', receipt: 'purchase_receipt' }
      const fields = { request: 'request_no', plan: 'plan_no', order: 'purchase_order_no', receipt: 'receipt_no' }
      try {
        this.reservation = await reserveForCreatePage(documentTypes[this.type], this.$route.path)
        this.$set(this.form, fields[this.type], this.reservation.document_no)
      } catch (e) {
        this.$message.error(e.userMessage || '单据编号预生成失败，请重新打开新增页面')
      }
    },
    async loadExisting() {
      const map = { request: 'requests', plan: 'plans', order: 'orders', receipt: 'receipts' }
      const res = await getPurchase(map[this.type], this.$route.params.id)
      const data = res.data
      this.attachmentDraftToken = ''
      ;(data.items || []).forEach(line => this.rememberItem(line.item))
      if (this.type === 'request') this.form = { ...data, items: (data.items || []).map(i => ({ ...i, request_qty: Number(i.request_qty) })) }
      if (this.type === 'plan') this.form = { ...data, items: (data.items || []).map(i => ({ ...i, required_qty: Number(i.required_qty || i.plan_qty), splits: (i.splits || []).map(s => ({ ...s, purchase_qty: Number(s.purchase_qty), unit_price: Number(s.unit_price), tax_rate: Number(s.tax_rate) })) })) }
      if (this.type === 'order') this.form = { ...data, items: (data.items || []).map(i => ({ ...i, qty: Number(i.purchase_qty || i.order_qty), unit_price: Number(i.purchase_unit_price || i.unit_price), tax_rate: Number(i.tax_rate), _conversionOptions: [] })) }
      if (this.type === 'receipt') this.form = { ...data, items: (data.items || []).map(i => ({ ...i, qty: Number(i.receipt_qty), unit_price: Number(i.unit_price), qualified_qty: Number(i.qualified_qty), unqualified_qty: Number(i.unqualified_qty), actual_base_qty: i.actual_base_qty == null ? null : Number(i.actual_base_qty), _conversionOptions: [] })) }
      if (['order', 'receipt'].includes(this.type)) await Promise.all(this.form.items.map(line => this.loadLineConversions(line, false)))
    },
    addPlanItem() { this.form.items.push({ item_id: null, unit_id: null, required_qty: 1, expected_date: '', splits: [] }); this.activeIndex = this.form.items.length - 1; this.recommendations = [] },
    removePlanItem(index) {
      this.form.items.splice(index, 1)
      if (this.activeIndex >= this.form.items.length) this.activeIndex = Math.max(0, this.form.items.length - 1)
    },
    addRequestLine() { this.form.items.push({ item_id: null, request_qty: 1, expected_date: '', priority: 'normal' }) },
    addSplit() { this.activeLine.splits.push({ supplier_id: null, purchase_qty: this.remaining(this.activeLine) || 1, unit_price: 0, tax_rate: 13 }) },
    addLine() { this.form.items.push({ item_id: null, qty: 1, unit_price: 0, tax_rate: 13, qualified_qty: 1, unqualified_qty: 0, actual_base_qty: 0, serial_text: '', serial_number_source: 'supplier', warehouse_id: null, location_id: null, purchase_unit_id: null, _conversionOptions: [] }); this.activeIndex = this.form.items.length - 1; this.recommendations = [] },
    openItemPicker(line) {
      this.pickerTarget = line
      this.$refs.itemPicker.open({ currentId: line && line.item_id, params: { status: 'enabled', is_purchase_item: 1 } })
    },
    async applyPickedItem(item) {
      const line = this.pickerTarget
      if (!line || !item) return
      const changed = Number(line.item_id || 0) !== Number(item.id)
      this.rememberItem(item)
      this.$set(line, 'item_id', item.id)
      if (this.type === 'plan') this.$set(line, 'unit_id', item.unit_id || item.unit?.id || null)
      if (changed && ['order', 'receipt'].includes(this.type)) {
        this.$set(line, 'purchase_unit_id', null)
        this.$set(line, '_conversionOptions', [])
        this.$set(line, 'serial_text', '')
        this.$set(line, 'serial_number_source', 'supplier')
        await this.loadLineConversions(line, true)
      }
      this.recommendations = []
      this.pickerTarget = null
    },
    rememberItem(item) {
      if (!item || !item.id) return
      const index = this.items.findIndex(row => Number(row.id) === Number(item.id))
      if (index >= 0) this.$set(this.items, index, item)
      else this.items.push(item)
    },
    async loadLineConversions(line, chooseDefault = true) {
      if (!line.item_id) return this.$set(line, '_conversionOptions', [])
      const { data } = await listItemPurchaseConversionOptions(line.item_id, { page: 1, per_page: 100 })
      const item = this.items.find(row => Number(row.id) === Number(line.item_id))
      const itemUnit = item && (item.unit?.standard_unit || item.unit?.standardUnit || item.unit)
      const options = [...(data.data || [])]
      if (itemUnit && !options.some(row => Number(row.purchase_unit_id) === Number(itemUnit.id))) {
        options.unshift({ purchase_unit_id: itemUnit.id, base_unit_id: itemUnit.id, factor: 1, is_default: options.length === 0, allow_actual_conversion: false, purchase_unit: itemUnit, base_unit: itemUnit, identity_conversion: true })
      }
      this.$set(line, '_conversionOptions', options)
      if (chooseDefault || !line.purchase_unit_id) {
        const selected = options.find(row => row.is_default) || options[0]
        if (selected) this.$set(line, 'purchase_unit_id', selected.purchase_unit_id)
      }
      this.applyConversion(line, false)
    },
    selectedConversion(line) { return (line._conversionOptions || []).find(row => Number(row.purchase_unit_id) === Number(line.purchase_unit_id)) },
    purchaseOptionLabel(conversion) {
      const purchaseUnit = conversion && (conversion.purchase_unit?.standard_unit || conversion.purchase_unit?.standardUnit || conversion.purchase_unit)
      const baseUnit = conversion && (conversion.base_unit?.standard_unit || conversion.base_unit?.standardUnit || conversion.base_unit)
      const purchaseName = purchaseUnit && (purchaseUnit.symbol || purchaseUnit.unit_name || purchaseUnit.unit_code) || '-'
      const baseName = baseUnit && (baseUnit.symbol || baseUnit.unit_name || baseUnit.unit_code) || '-'
      const factor = Number(conversion && conversion.factor || 1).toLocaleString('zh-CN', { maximumFractionDigits: 6 })
      return `${purchaseName}（1 ${purchaseName} = ${factor} ${baseName}）${conversion && conversion.is_default ? ' · 默认' : ''}`
    },
    canonicalUnit(unit) { return unit && (unit.standard_unit || unit.standardUnit || unit) },
    applyConversion(line, preserveBaseQuantity = false) {
      const c = this.selectedConversion(line)
      if (!c) return
      const previousFactor = Number(line._effectiveConversionFactor || line.conversion_factor_preview || line.conversion_factor_snapshot || 0)
      const previousBaseQuantity = Number(line.qty || 0) * previousFactor
      const previousBaseUnitPrice = previousFactor > 0 ? Number(line.unit_price || 0) / previousFactor : 0
      const nextFactor = Number(c.factor)
      const purchaseUnit = this.canonicalUnit(c.purchase_unit)
      const baseUnit = this.canonicalUnit(c.base_unit)
      if (preserveBaseQuantity && previousFactor > 0 && nextFactor > 0) {
        this.$set(line, 'qty', previousBaseQuantity / nextFactor)
        this.$set(line, 'unit_price', previousBaseUnitPrice * nextFactor)
      }
      this.$set(line, 'conversion_factor_preview', nextFactor)
      this.$set(line, 'purchase_unit_name_preview', purchaseUnit && (purchaseUnit.unit_name || purchaseUnit.symbol || purchaseUnit.unit_code))
      this.$set(line, 'base_unit_name_preview', baseUnit && (baseUnit.unit_name || baseUnit.symbol || baseUnit.unit_code))
      this.$set(line, 'allow_actual_conversion_preview', Boolean(c.allow_actual_conversion))
      this.$set(line, '_effectiveConversionFactor', nextFactor)
      if (this.type === 'receipt' && (line.actual_base_qty == null || Number(line.actual_base_qty) === 0)) this.$set(line, 'actual_base_qty', this.standardBaseQtyNumber(line))
    },
    changePurchaseUnit(line) { this.applyConversion(line, true) },
    syncReceiptActualQty(line) {
      if (this.type === 'receipt' && !this.actualConversionAllowed(line)) this.$set(line, 'actual_base_qty', this.standardBaseQtyNumber(line))
    },
    effectiveConversionFactor(line) { return Number(this.type === 'order' ? (line.conversion_factor_preview || line.conversion_factor_snapshot || 0) : (line.conversion_factor_snapshot || line.conversion_factor_preview || 0)) },
    conversionFactor(line) { return this.effectiveConversionFactor(line).toLocaleString('zh-CN', { maximumFractionDigits: 6 }) },
    conversionUnitName(line) { const c = this.selectedConversion(line); return line.purchase_unit_name_preview || (c && c.purchase_unit && c.purchase_unit.unit_name) || '-' },
    baseUnitName(line) { const c = this.selectedConversion(line); const selectedBase = this.canonicalUnit(c && c.base_unit); return (this.type === 'order' ? line.base_unit_name_preview : line.base_unit_name_snapshot) || line.base_unit_name_preview || (selectedBase && (selectedBase.unit_name || selectedBase.symbol || selectedBase.unit_code)) || '-' },
    standardBaseQtyNumber(line) {
      const quantity = Number(line.qty || 0)
      const factor = this.effectiveConversionFactor(line)
      return quantity > 0 && factor > 0 ? quantity * factor : Number(line.standard_base_qty || 0)
    },
    standardBaseQty(line) { return this.standardBaseQtyNumber(line).toLocaleString('zh-CN', { maximumFractionDigits: 6 }) },
    actualConversionAllowed(line) { return Boolean(line.allow_actual_conversion || line.allow_actual_conversion_preview || line.allow_actual_conversion_snapshot) },
    differenceQtyNumber(line) { return Number(line.actual_base_qty == null ? this.standardBaseQtyNumber(line) : line.actual_base_qty) - this.standardBaseQtyNumber(line) },
    differenceQty(line) { return this.differenceQtyNumber(line).toLocaleString('zh-CN', { maximumFractionDigits: 6 }) },
    hasDifference(line) { return Math.abs(this.differenceQtyNumber(line)) > 0.0000001 },
    baseUnitPrice(line) { const factor = this.effectiveConversionFactor(line); return factor > 0 ? this.money(Number(line.unit_price || 0) / factor) : '-' },
    selectLine(row) {
      const index = this.form.items.indexOf(row)
      if (index >= 0) this.activeIndex = index
      if (this.type === 'order') this.loadRecommendations()
    },
    filteredLocations(warehouseId) { return warehouseId ? this.locations.filter(l => l.warehouse_id === warehouseId) : this.locations },
    allocated(line) { return (line.splits || []).reduce((n, s) => n + Number(s.purchase_qty || 0), 0) },
    remaining(line) { return Math.max(0, Number(line.required_qty || 0) - this.allocated(line)) },
    itemName(id) { const item = this.items.find(i => Number(i.id) === Number(id)); return item ? `${item.item_code} / ${item.item_name}${item.spec_model ? ` / ${item.spec_model}` : ''}` : '请选择物料' },
    serialTrackingMode(line) { const item = this.items.find(row => Number(row.id) === Number(line.item_id)); return item ? (item.serial_tracking_mode || (item.is_serial_managed ? 'required' : 'none')) : 'none' },
    isSerialManaged(line) { return this.serialTrackingMode(line) !== 'none' },
    markSupplierSerials(line) { line.serial_number_source = 'supplier' },
    serialQuantity(line) { const received = Number(line.qty || 0); const actual = Number(line.actual_base_qty == null ? this.standardBaseQtyNumber(line) : line.actual_base_qty); return received > 0 ? actual * Number(line.qualified_qty || 0) / received : 0 },
    serialNumberList(value) { return String(value || '').split(/\r?\n|,|，/).map(row => row.trim()).filter(Boolean) },
    serialGenerationButtonText(line) { const quantity = this.serialQuantity(line); return Number.isInteger(quantity) && quantity > 0 ? `一次生成 ${quantity} 个` : '一次生成全部编号' },
    async generateLineSerials(line) { const quantity = this.serialQuantity(line); if (!Number.isInteger(quantity) || quantity <= 0) return this.$message.error('合格实际入库数量必须是大于 0 的整数后才能生成序列号'); try { const response = await generateReceiptSerials({ item_id: line.item_id, quantity }); this.$set(line, 'serial_text', (response.data.data || []).join('\n')); this.$set(line, 'serial_number_source', 'system_generated'); this.$message.success(`已生成 ${quantity} 个序列号，请核对后保存`) } catch (e) { this.$message.error(e.userMessage || '序列号生成失败') } },
    printSerialLabels(line, serialNo = '') {
      const serials = serialNo ? [serialNo] : this.serialNumberList(line.serial_text)
      if (!serials.length) return this.$message.warning('请先录入或生成设备编号')
      const item = this.items.find(row => Number(row.id) === Number(line.item_id)) || {}
      const escapeHtml = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]))
      const labels = serials.map(value => `<article><div class="title">设备编号 / 序列号</div><div class="serial">${escapeHtml(value)}</div><div class="meta">物料：${escapeHtml(item.item_code || '-')} / ${escapeHtml(item.item_name || '-')}</div><div class="meta">到货单：${escapeHtml(this.form.receipt_no || '-')}</div></article>`).join('')
      const popup = window.open('', '_blank', 'width=760,height=640')
      if (!popup) return this.$message.error('打印窗口被浏览器拦截，请允许弹出窗口后重试')
      popup.document.open()
      popup.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>设备编号标签</title><style>@page{size:70mm 40mm;margin:3mm}*{box-sizing:border-box}body{margin:0;font-family:Arial,"Microsoft YaHei",sans-serif;color:#111}article{width:64mm;height:34mm;padding:4mm;border:1px solid #222;page-break-after:always;display:flex;flex-direction:column;justify-content:center}.title{font-size:10pt}.serial{margin:2.5mm 0;font-size:16pt;font-weight:700;word-break:break-all}.meta{font-size:8.5pt;line-height:1.5}article:last-child{page-break-after:auto}@media screen{body{padding:16px;background:#eee}article{margin:0 auto 16px;background:#fff}}</style></head><body>${labels}</body></html>`)
      popup.document.close()
      popup.focus()
      window.setTimeout(() => popup.print(), 250)
    },
    itemUnitName(id) {
      const item = this.items.find(row => Number(row.id) === Number(id))
      const unit = item && (item.unit || item.inventory_unit || item.base_unit)
      const canonical = unit && (unit.standard_unit || unit.standardUnit || unit)
      return canonical ? (canonical.symbol || canonical.unit_name || canonical.unit_code) : '-'
    },
    linesForAmount() { return this.type === 'plan' ? this.form.items.flatMap(i => (i.splits || []).map(s => ({ qty: s.purchase_qty, price: s.unit_price, tax: s.tax_rate }))) : this.form.items.map(i => ({ qty: i.qty, price: i.unit_price, tax: i.tax_rate || 0 })) },
    async loadRecommendations() {
      const line = this.activeLine
      if (!line || !line.item_id) return this.$message.warning('请先选择当前采购物料')
      const item = this.items.find(row => Number(row.id) === Number(line.item_id))
      const quantity = this.type === 'plan' ? Number(line.required_qty || 0) : Number(line.qty || 0)
      const unitId = Number(line.unit_id || item?.unit_id || 0)
      if (!quantity || !unitId) return this.$message.warning('请先填写数量并确认采购单位')
      this.recommendLoading = true
      try {
        const response = await getSupplierRecommendations(line.item_id, {
          quantity,
          unit_id: unitId,
          required_date: this.type === 'plan' ? line.expected_date : line.expected_arrival_date,
          currency: this.form.currency || 'CNY',
          tax_mode: this.form.tax_mode || 'tax_included'
        })
        this.recommendations = response.data.data.candidates || []
        // 供应商推荐是采购决策参考，不是采购订单的前置强约束。
        // 空候选只显示空态，避免用户调整数量或单价时被重复的警告打断。
      } catch (e) {
        this.recommendations = []
        this.$message.error(e.userMessage || '供应商推荐加载失败')
      } finally {
        this.recommendLoading = false
      }
    },
    chooseRecommendation(candidate) {
      if (!candidate.auto_selectable) return
      const snapshot = {
        recommended_supplier_id_snapshot: candidate.recommended ? candidate.supplier_id : (this.recommendations.find(row => row.recommended)?.supplier_id || null),
        recommended_price_snapshot: candidate.comparable_price,
        recommendation_basis_snapshot: candidate.recommendation_basis,
        recommendation_time: new Date().toISOString().slice(0, 19).replace('T', ' ')
      }
      if (this.type === 'plan') {
        const existing = (this.activeLine.splits || []).find(row => Number(row.supplier_id) === Number(candidate.supplier_id))
        const split = existing || { supplier_id: candidate.supplier_id, purchase_qty: this.remaining(this.activeLine) || 1, tax_rate: candidate.tax_rate || 0 }
        Object.assign(split, snapshot)
        if (candidate.comparable_price != null) split.unit_price = candidate.comparable_price
        if (!existing) this.activeLine.splits.push(split)
      } else if (this.type === 'order') {
        this.form.supplier_id = candidate.supplier_id
        Object.assign(this.activeLine, snapshot)
        if (candidate.comparable_price != null) this.activeLine.unit_price = candidate.comparable_price
      }
      this.$message.success(`已选用 ${candidate.supplier_name}，推荐依据和价格已留存快照`)
    },
    capabilityText(v) { return ({ confirmed_item: '具体物料', quotation: '有效报价', purchase_history: '采购历史', item_default: '物料默认', category_candidate: '品类候选' })[v] || v || '-' },
    basisText(v) {
      return ({
        VALID_QUOTE_BEST_PRICE: '有效报价优价', RECENT_PURCHASE_BEST_PRICE: '近期采购优价',
        DEFAULT_SUPPLIER: '默认供应商', LAST_SUCCESSFUL_SUPPLIER: '最近成功供应商',
        CATEGORY_CANDIDATE: '品类候选', CONFIRMED_CAPABILITY: '已确认能力'
      })[v] || v || '-'
    },
    async ensureRecommendationOverrides() {
      const rows = this.type === 'plan' ? this.form.items.flatMap(item => item.splits || []) : (this.type === 'order' ? this.form.items : [])
      for (const row of rows) {
        const actual = this.type === 'plan' ? row.supplier_id : this.form.supplier_id
        if (!row.recommended_supplier_id_snapshot || Number(row.recommended_supplier_id_snapshot) === Number(actual) || row.supplier_override_reason) continue
        const result = await this.$prompt('当前选择与系统推荐供应商不同，请填写调整原因。该原因会随订单留痕。', '供应商调整留痕', {
          inputPattern: /\S+/, inputErrorMessage: '必须填写调整原因', confirmButtonText: '确认调整'
        })
        this.$set(row, 'supplier_override_reason', 'manual_business_judgement')
        this.$set(row, 'supplier_override_remark', result.value)
      }
    },
    payload() {
      let payload
      if (this.type === 'request') payload = { ...this.form, items: this.form.items.map(i => ({ ...i, request_qty: i.request_qty })) }
      else if (this.type === 'plan') payload = { ...this.form, items: this.form.items.map(i => ({ ...i, required_qty: i.required_qty, splits: i.splits || [] })) }
      else if (this.type === 'order') payload = { ...this.form, items: this.form.items.map(i => ({ ...i, order_qty: i.qty })) }
      else payload = { ...this.form, items: this.form.items.map(i => ({ ...i, receipt_qty: i.qty })) }
      if (!this.$route.params.id && this.reservation) {
        payload.reservation_token = this.reservation.reservation_token
        payload.creation_session_id = this.reservation.creation_session_id
      }
      if (this.type === 'order') payload.attachment_draft_token = this.attachmentDraftToken
      return payload
    },
    newDraftToken() { return window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : `purchase-${Date.now()}-${Math.random().toString(16).slice(2)}` },
    async save(submit) {
      if (!['plan', 'request'].includes(this.type) && !this.form.supplier_id) return this.$message.error('供应商不能为空')
      if (this.type === 'plan' && this.form.items.some(i => this.remaining(i) !== 0)) return this.$message.error('存在未分配数量，不能保存采购计划')
      if (this.form.items.some(line => !line.item_id)) return this.$message.error('每一行都必须选择采购物料')
      if (this.type === 'order' && this.form.items.some(line => !line.purchase_unit_id)) return this.$message.error('每一行都必须选择有效采购单位')
      if (this.type === 'receipt' && this.form.items.some(line => this.hasDifference(line) && !String(line.difference_reason || '').trim())) return this.$message.error('实际基本数量与标准数量不同时，必须填写差异原因')
      try {
        await this.ensureRecommendationOverrides()
      } catch (e) {
        if (e === 'cancel') return
        throw e
      }
      const api = this.type === 'request' ? savePurchaseRequest : this.type === 'plan' ? savePurchasePlan : this.type === 'order' ? savePurchaseOrder : savePurchaseReceipt
      let res
      try { res = await api(this.payload()) } catch (e) { this.$message.error(e.userMessage || '保存失败'); return }
      const savedId = res.data.data.id
      const created = !this.$route.params.id
      if (created) {
        clearCreatePageReservation(this.reservation)
        this.reservation = null
        if (submit) {
          const path = this.type === 'request' ? `/purchase/requests/${savedId}/edit` : this.type === 'plan' ? `/purchase/plans/${savedId}/edit` : this.type === 'order' ? `/purchase/orders/${savedId}/edit` : `/purchase/receipts/${savedId}/edit`
          await this.$router.replace(path)
        }
      }
      try {
        if (submit && this.type === 'request') await submitRequest(savedId)
        if (submit && this.type === 'plan') await submitPlan(savedId)
        if (submit && this.type === 'order') await submitOrder(savedId)
      } catch (e) {
        this.$message.error(e.userMessage || '提交失败，请检查当前状态和必填信息')
        return
      }
      this.$message.success(submit && this.type === 'request' ? '需求已确认，已锁定需求，可转采购计划' : submit && this.type !== 'receipt' ? '已保存并提交审核' : '保存成功')
      this.$router.push(this.type === 'request' ? '/purchase/requests' : this.type === 'plan' ? '/purchase/plans' : this.type === 'order' ? '/purchase/orders' : '/purchase/receipts')
    },
    money(v) { return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
  }
}
</script>

<style scoped>
.purchase-form-page{box-sizing:border-box;max-width:100%;min-height:calc(100vh - 52px);overflow-x:hidden;background:#f7f8f9;padding:16px 16px 76px}.form-top{display:flex;justify-content:space-between;align-items:flex-start;height:54px}.form-top h1{margin:0;font-size:18px}.form-top p{margin:3px 0 0;color:#737d87}.form-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;grid-template-areas:"basic summary" "detail detail";gap:14px;max-width:100%;align-items:stretch}.basic-info-card{grid-area:basic;margin-bottom:0!important}.detail-workspace{grid-area:detail;min-width:0}.form-layout>aside{grid-area:summary;display:grid;grid-template-rows:minmax(0,1fr) minmax(0,1fr);gap:14px;min-width:0}.form-layout>aside .form-card{height:100%;margin:0}.form-card{box-sizing:border-box;min-width:0;overflow:hidden;background:#fff;border:1px solid #e2e6ea;border-radius:4px;padding:14px;margin-bottom:14px}.form-card h3,.section-title h3{margin:0 0 12px;font-size:13px}.grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.grid-3 .el-select,.grid-3 .el-date-editor{width:100%}.plan-editor{display:grid;grid-template-columns:300px minmax(0,1fr);gap:12px}.material-list,.split-editor{min-width:0;background:#fff;border:1px solid #e2e6ea;border-radius:4px;padding:12px}.section-title{display:flex;justify-content:space-between;align-items:center}.material-card{padding:10px;border-left:3px solid transparent;border-bottom:1px solid #edf0f2;cursor:pointer}.material-card.active{background:#eaf7ef;border-left-color:#07883f}.material-card b,.material-card span{display:block}.material-card span{color:#737d87;font-size:11px}.material-base{margin-bottom:10px}.form-card dl{display:grid;grid-template-columns:90px 1fr;gap:10px;margin:0}.form-card dt{color:#737d87}.form-card dd{margin:0;font-weight:600}.bottom-actions{box-sizing:border-box;position:fixed;left:192px;right:0;bottom:0;height:58px;padding:10px 18px;display:flex;justify-content:flex-end;gap:10px;background:#fff;border-top:1px solid #e2e6ea;z-index:20}
.recommend-card{margin:10px 0;padding:10px;border:1px solid #b9dfc8;border-radius:4px;background:#f8fcf9}.recommend-card .section-title{margin-bottom:8px}.recommend-card .section-title h3{margin:0 0 2px}.recommend-card .section-title span{font-size:10px;color:#75818a}
.serial-entry-tools{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;font-size:11px;color:#68737d}.serial-entry-tools .el-button{flex:0 0 auto;padding:5px 8px}.serial-number-panel{margin-top:6px;border:1px solid #dce5df;border-radius:4px;background:#f8fbf9}.serial-number-summary{display:flex;align-items:center;justify-content:space-between;padding:2px 8px;border-bottom:1px solid #e5ebe7;color:#66736b;font-size:11px}.serial-number-list{max-height:132px;overflow-y:auto}.serial-number-item{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:28px;padding:2px 8px;border-bottom:1px solid #edf1ee}.serial-number-item:last-child{border-bottom:0}.serial-number-item span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:Consolas,monospace;color:#25352c}.serial-number-item .el-button{flex:0 0 auto;padding:3px 0}
::v-deep .purchase-lines-table .el-select,::v-deep .purchase-lines-table .el-input,::v-deep .purchase-lines-table .el-input-number,::v-deep .purchase-lines-table .el-date-editor{box-sizing:border-box;width:100%!important;max-width:100%}::v-deep .purchase-lines-table .line-number-input .el-input__inner{padding-left:8px;padding-right:38px;text-align:left}.unit-source-help{margin-left:2px;color:#7b8790;cursor:help}
.item-picker-input{width:100%}.item-picker-input ::v-deep .el-input__inner{cursor:pointer;background:#fff}.item-picker-input ::v-deep .el-input-group__append{padding:0 9px;color:#07883f;background:#f3fbf6;border-color:#d7e8dd}.item-picker-input ::v-deep .el-input-group__append .el-button{margin:-8px -9px;padding:8px 9px}
@media (max-width:1180px){.form-layout{grid-template-columns:minmax(0,1fr);grid-template-areas:"basic" "summary" "detail"}.form-layout>aside{grid-template-columns:repeat(2,minmax(0,1fr));grid-template-rows:auto;gap:14px}.grid-3{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:760px){.purchase-form-page{padding:12px 10px 76px}.form-top{height:auto;min-height:54px;gap:10px}.form-top p{font-size:11px}.grid-3,.form-layout>aside{grid-template-columns:minmax(0,1fr)}.plan-editor{grid-template-columns:minmax(0,1fr)}.bottom-actions{left:0;padding:10px}.section-title{align-items:flex-start;gap:8px}}
</style>
