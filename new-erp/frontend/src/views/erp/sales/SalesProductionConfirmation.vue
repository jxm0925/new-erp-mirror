<!-- Locked reference: D:\codex-introduce\new_erp\docs\ui-reference\unit-conversion-system\12-order-item-requirement.png -->
<template>
  <section class="confirm-page" v-loading="loading">
    <header class="page-head">
      <div class="crumb">ERP　/　<b>销售订单</b>　/　<b>订单生产确认</b></div>
      <div class="actions">
        <el-button size="small" @click="returnToOrder">返回</el-button>
        <el-button size="small" icon="el-icon-refresh" :disabled="!editable" :loading="loading" @click="recalculate">重新计算备货方案</el-button>
        <el-button size="small" :disabled="!editable" @click="validateAll(true)">保存检查结果</el-button>
        <el-button size="small" type="success" :disabled="!canSubmit" :loading="submitting" @click="submit">{{ pending ? '重试原确认' : '提交确认' }}</el-button>
      </div>
    </header>

    <el-alert class="top-alert" type="info" :closable="false" show-icon title="生产确认后，系统记录每行的库存、生产、外购数量。新生产模块上线前，生产数量只做记录，不会自动生成工单；订单仍可直接修改（修改后需重新生产确认）。" />
    <el-alert v-if="pending" class="top-alert" type="warning" :closable="false" show-icon title="上次确认结果尚未收到。请重试原确认，系统会识别同一次操作；核实前暂不能修改本次数量。" />

    <div v-if="order" class="page-grid">
      <main>
        <section class="order-summary">
          <div><span>销售订单号</span><b>{{ order.sales_order_no }}</b></div>
          <div><span>客户</span><b>{{ order.customer_name || '-' }}</b></div>
          <div><span>订单日期</span><b>{{ date(order.order_time) }}</b></div>
          <div><span>交付要求日期</span><b>{{ date(order.required_delivery_date) }}</b></div>
          <div><span>加急标记</span><el-tag size="mini" :type="order.is_urgent ? 'danger' : 'info'">{{ order.is_urgent ? '是' : '否' }}</el-tag></div>
          <div><span>延期标记</span><el-tag size="mini" :type="order.is_delay ? 'warning' : 'info'">{{ order.is_delay ? '是' : '否' }}</el-tag></div>
          <div><span>生产确认状态</span><el-tag size="mini" type="success">{{ resultText }}</el-tag></div>
          <div><span>实际交付状态</span><el-tag size="mini" :type="fulfillmentProgressTag(order.fulfillment_status)">{{ actualFulfillmentText(order.fulfillment_status) }}</el-tag></div>
          <div><span>备货方案状态</span><el-tag size="mini" :type="planStatusTag(order.fulfillment_plan_status)">{{ order.fulfillment_plan_status_label || planStatusText(order.fulfillment_plan_status) }}</el-tag></div>
          <div><span>备货方式</span><b>{{ order.fulfillment_composition_label || '尚未安排备货' }}</b></div>
        </section>

        <section class="panel">
          <h3>订单行确认明细 <small>（共 {{ lines.length }} 行）</small></h3>
          <el-table :data="lines" border size="mini" row-key="sales_order_line_id" class="confirmation-table">
            <el-table-column prop="line_no" label="行号" width="54" align="center" />
            <el-table-column label="产品名称 / SKU名称" min-width="170" fixed="left">
              <template slot-scope="{row}"><b>{{ row.product_name || '-' }}</b><small class="sub">{{ row.sku_name || '-' }}</small></template>
            </el-table-column>
            <el-table-column label="物料名称 / 属性/规格类型" min-width="175">
              <template slot-scope="{row}"><b>{{ row.item_name || serviceItemText(row) }}</b><small class="sub"><el-tag size="mini" :type="typeTag(primaryType(row))">{{ itemRoleText(row) }}</el-tag></small></template>
            </el-table-column>
            <el-table-column label="订单数量" width="92" align="center"><template slot-scope="{row}"><b>{{ number(row.order_qty) }}</b><small class="sub">{{ row.sales_unit || '-' }}</small></template></el-table-column>
            <el-table-column label="Item基本需求量" width="118" align="center"><template slot-scope="{row}"><b v-if="needsItem(row)">{{ number(row.item_base_required_qty) }}</b><small v-if="needsItem(row)" class="sub">{{ row.base_unit }}</small><span v-else>—</span></template></el-table-column>
            <el-table-column label="已确认分配" width="100" align="center"><template slot-scope="{row}"><b>{{ number(row.confirmed_allocated_qty) }}</b><small class="sub">{{ row.sales_unit || '-' }}</small></template></el-table-column>
            <el-table-column label="本次确认数量" width="122" align="center">
              <template slot-scope="{row}">
                <el-input-number v-model="row.confirm_qty" :disabled="!editable" :min="0" :max="row.remaining_confirm_qty" :precision="salesPrecision(row)" :controls="false" @change="changeConfirmQuantity(row)" />
                <small class="sub">{{ row.sales_unit || '-' }}</small>
              </template>
            </el-table-column>
            <el-table-column label="库存可用量分析" min-width="260">
              <template slot-scope="{row}">
                <div v-if="isPhysical(row)" class="inventory-analysis">
                  <div><span>默认库存物料</span><b>{{ row.default_item_name || row.item_name || '-' }}</b></div>
                  <div><span>当前可用库存</span><b>{{ number(row.available_base_qty) }} {{ row.base_unit || '-' }}</b></div>
                  <div><span>方案状态</span><el-tag size="mini" :type="suggestionStatus(row).type">{{ suggestionStatus(row).text }}</el-tag></div>
                  <div><span>本次建议</span><b class="suggestion">库存 {{ number(currentSuggestion(row).inventory_qty) }} / {{ row.is_purchase_fulfilled ? '外购' : '生产' }} {{ number(row.is_purchase_fulfilled ? currentSuggestion(row).purchase_qty : currentSuggestion(row).production_qty) }} {{ row.sales_unit || '-' }}</b></div>
                  <div><span>判断原因</span><b>{{ row.system_suggestion_reason || '-' }}</b></div>
                  <small>计算时间：{{ row.inventory_calculated_at || '-' }}</small>
                </div>
                <span v-else class="muted">{{ row.system_suggestion_reason }}</span>
              </template>
            </el-table-column>
            <el-table-column label="备货数量分配（销售单位）" min-width="465">
              <template slot-scope="{row}">
                <div class="split-grid">
                  <label>库存<el-input-number v-model="row.inventory_qty" :disabled="!editable || !isPhysical(row)" :min="0" :max="Math.min(Number(row.confirm_qty || 0), Number(row.available_sales_qty || 0))" :precision="salesPrecision(row)" :controls="false" /></label>
                  <label v-if="row.is_purchase_fulfilled">外购<el-input-number v-model="row.purchase_qty" :disabled="!editable || !isPhysical(row)" :min="0" :max="row.confirm_qty" :precision="salesPrecision(row)" :controls="false" /></label>
                  <label v-else>生产<el-input-number v-model="row.production_qty" :disabled="!editable || !isPhysical(row)" :min="0" :max="row.confirm_qty" :precision="salesPrecision(row)" :controls="false" /></label>
                  <label>服务<el-input-number v-model="row.service_qty" :disabled="!editable || !isService(row)" :min="0" :max="row.confirm_qty" :precision="salesPrecision(row)" :controls="false" /></label>
                  <label>无需发货<el-input-number v-model="row.no_delivery_qty" :disabled="!editable || !isNoDelivery(row)" :min="0" :max="row.confirm_qty" :precision="salesPrecision(row)" :controls="false" /></label>
                  <label>未确定<span class="readonly-qty">{{ number(remainingUndetermined(row)) }}</span></label>
                </div>
                <div class="split-check" :class="allocationValid(row) ? 'pass' : 'fail'">{{ number(allocationSum(row)) }} / {{ number(row.confirm_qty) }} {{ row.sales_unit || '-' }}<span v-if="needsItem(row)">；本次 Item 基本数量 {{ number(itemBaseConfirmQty(row)) }} {{ row.base_unit }}</span></div>
                <div v-if="lineAdjusted(row)" class="manual-adjusted"><i class="el-icon-warning-outline" /> 已偏离系统建议，提交时必须填写调整原因</div>
              </template>
            </el-table-column>
            <el-table-column label="生产资料状态" min-width="190">
              <template slot-scope="{row}"><div class="readiness-list"><span>BOM <readiness-tag :value="row.data_readiness && row.data_readiness.bom" /></span><span>工艺 <readiness-tag :value="row.production_qty > 0 ? (row.data_readiness && row.data_readiness.routing) : 'not_required'" /></span><span>检验 <readiness-tag :value="row.data_readiness && row.data_readiness.inspection" /></span><span>图纸 <readiness-tag :value="row.data_readiness && row.data_readiness.drawing" /></span></div></template>
            </el-table-column>
            <el-table-column label="阻塞原因" min-width="165"><template slot-scope="{row}"><span :class="lineBlocked(row) ? 'block-text' : 'ready-text'">{{ blockingReason(row) }}</span></template></el-table-column>
            <el-table-column label="本次生成结果" min-width="155"><template slot-scope="{row}"><el-tag size="mini" :type="remainingUndetermined(row) > 0 ? 'warning' : 'success'">{{ allocationText(row) }}</el-tag></template></el-table-column>
          </el-table>
          <div v-if="editable && hasManualAdjustment" class="adjustment-reason">
            <div><b>备货方案调整原因 <em>*</em></b><small>系统会同时保存建议数量、最终数量、操作人和操作时间</small></div>
            <el-input v-model.trim="adjustmentReason" type="textarea" :rows="2" maxlength="500" show-word-limit placeholder="例如：保留库存作为售后备件、指定批次暂不发货、库存质量风险等" />
          </div>
        </section>

        <section class="panel material-panel">
          <h3>生产资料摘要 <small>（仅摘要，不展示工序明细）</small></h3>
          <el-table :data="lines" border size="mini">
            <el-table-column prop="line_no" label="行号" width="54" align="center" />
            <el-table-column prop="product_name" label="产品名称" min-width="150" />
            <el-table-column label="BOM齐备性" align="center"><template slot-scope="{row}"><readiness-tag :value="row.data_readiness && row.data_readiness.bom" /></template></el-table-column>
            <el-table-column label="工艺路线齐备性" align="center"><template slot-scope="{row}"><readiness-tag :value="row.production_qty > 0 ? (row.data_readiness && row.data_readiness.routing) : 'not_required'" /></template></el-table-column>
            <el-table-column label="检验方案齐备性" align="center"><template slot-scope="{row}"><readiness-tag :value="row.data_readiness && row.data_readiness.inspection" /></template></el-table-column>
            <el-table-column label="设计图纸齐备性" align="center"><template slot-scope="{row}"><readiness-tag :value="row.data_readiness && row.data_readiness.drawing" /></template></el-table-column>
          </el-table>
        </section>
      </main>

      <aside>
        <section class="side-card"><h3>确认汇总</h3><dl><template v-for="row in countRows"><dt :key="row.label+'l'">{{ row.label }}</dt><dd :key="row.label+'v'">{{ row.value }} 行</dd></template></dl></section>
        <section class="side-card quantity"><h3>本次数量汇总 <small>（按销售单位）</small></h3><dl><template v-for="row in quantityRows"><dt :key="row.label+'l'">{{ row.label }}</dt><dd :key="row.label+'v'">{{ row.value }}</dd></template></dl></section>
        <section class="side-card blue"><h3>Item基本需求合计 <small>（按单位分组）</small></h3><p v-for="row in baseGroups" :key="row.unit_id || row.unit_name"><b>{{ row.unit_name || '未配置单位' }}：{{ number(row.quantity) }}</b></p><p v-if="!baseGroups.length">暂无 Item 基本需求</p></section>
        <section class="side-card blue"><h3>服务项目：{{ summary.service || 0 }} 项</h3></section>
        <section class="side-card result"><h3>提交结果预览 <small>（确认通过后将执行）</small></h3><p v-for="text in submitResults" :key="text"><i class="el-icon-success" /> {{ text }}</p></section>
        <section class="side-card warn"><b><i class="el-icon-warning" /> 服务及无需发货行不进入库存备货或生产安排；</b><p>生产安排行先记录需生产数量，新生产模块上线后再安排工单。</p></section>
      </aside>
    </div>

    <el-empty v-if="!order && !loading" description="未找到订单生产确认数据" />
    <el-alert v-if="blocked" class="bottom-tip" type="warning" :closable="false" show-icon title="存在分配数量不一致或库存不足的订单行，请处理后再提交确认。" />
  </section>
</template>

<script>
import { erpDateTime } from '../../../utils/dateTime'
import cachedPageRoute from '@/utils/cachedPageRoute'
import { confirmProduction, getProductionPreview } from '@/api/erp/sales'
import { confirmationAllocationSum, currentConfirmationSuggestion, confirmationIsAdjusted, buildSalesProductionConfirmation } from '../../../utils/sales-production-confirmation.mjs'

const ReadinessTag = {
  props: ['value'],
  render (h) {
    const text = this.value === 'ready' ? '已齐全' : (this.value === 'missing' ? '资料不齐' : (this.value === 'pending_next_stage' ? '工单维护' : '不适用'))
    const type = this.value === 'ready' ? 'success' : (this.value === 'missing' ? 'danger' : 'info')
    return h('el-tag', { props: { size: 'mini', type } }, text)
  }
}

export default {
  mixins: [cachedPageRoute],
  name: 'SalesProductionConfirmation',
  components: { ReadinessTag },
  data: () => ({ loading: false, submitting: false, preview: {}, order: null, lines: [], adjustmentReason: '', pending: null, storageKey: '', loadSequence: 0 }),
  computed: {
    editable () { return !this.pending && this.$can('sales_order.production_confirm') && this.order && this.order.order_status === 'confirmed' && ['pending', 'blocked'].includes(this.order.production_confirm_status) },
    blocked () { return this.lines.some(row => this.lineBlocked(row)) },
    canSubmit () { return this.$can('sales_order.production_confirm') && !this.loading && (!!this.pending || (this.editable && !this.blocked && this.lines.length > 0 && this.lines.some(row => Number(row.confirm_qty || 0) > 0) && this.lines.every(row => this.allocationValid(row)) && (!this.hasManualAdjustment || !!this.adjustmentReason))) },
    hasManualAdjustment () { return this.lines.some(row => this.lineAdjusted(row)) },
    baseGroups () { return this.preview.base_unit_groups || [] },
    summary () { return { total: this.lines.length, inventory: this.lines.filter(row => row.inventory_qty > 0).length, production: this.lines.filter(row => row.production_qty > 0).length, purchase: this.lines.filter(row => row.purchase_qty > 0).length, service: this.lines.filter(row => row.service_qty > 0).length, no_delivery: this.lines.filter(row => row.no_delivery_qty > 0).length } },
    resultText () { return this.order.production_confirm_status === 'confirmed' ? '已确认并锁定' : (this.blocked ? '待处理' : '待确认') },
    countRows () { return [{ label: '订单总行数', value: this.summary.total }, { label: '库存备货行数', value: this.summary.inventory }, { label: '生产安排行数', value: this.summary.production }, { label: '待外购到货行数', value: this.summary.purchase }, { label: '服务项目行数', value: this.summary.service }, { label: '无需发货行数', value: this.summary.no_delivery }] },
    quantityRows () { return [{ label: '库存备货', value: this.groupQuantity('inventory_qty') }, { label: '生产安排', value: this.groupQuantity('production_qty') }, { label: '外购备货', value: this.groupQuantity('purchase_qty') }, { label: '服务项目', value: this.groupQuantity('service_qty') }, { label: '无需发货', value: this.groupQuantity('no_delivery_qty') }, { label: '尚未确定', value: this.groupQuantity('undetermined_qty') }] },
    submitResults () { const rows = ['保存订单生产确认结果']; if (this.summary.inventory) rows.push('生成库存备货需求（物料基本数量）'); if (this.summary.production) rows.push('记录需生产数量（销售/基本数量双口径）'); if (this.summary.purchase) rows.push('保存外购缺口，到货后正式锁库存'); if (this.summary.service) rows.push('生成服务项目记录'); if (this.summary.no_delivery) rows.push('保存无需发货结果'); rows.push('锁定本次确认所使用的生产资料'); return rows }
  },
  created () { this.load() },
  watch: {
    'pageRoute.params.id' () { this.load() }
  },
  methods: {
    returnToOrder () {
      if (!this.order || !this.order.id) return this.$router.push('/sales/orders')
      return this.$router.push(`/sales/orders/${this.order.id}/detail`)
    },
    async load (notify = false) {
      const sequence = ++this.loadSequence
      const orderId = this.pageRoute.params.id
      if (Number(this.order?.id) !== Number(orderId)) { this.order = null; this.lines = []; this.preview = {}; this.pending = null; this.storageKey = '' }
      this.loading = true
      try {
        const { data } = await getProductionPreview(orderId)
        if (sequence !== this.loadSequence) return
        this.preview = data || {}; this.order = data.order || null
        this.lines = (data.lines || []).map(row => ({ ...row, inventory_qty: Number(row.inventory_qty || 0), production_qty: Number(row.production_qty || 0), purchase_qty: Number(row.purchase_qty || 0), service_qty: Number(row.service_qty || 0), no_delivery_qty: Number(row.no_delivery_qty || 0), undetermined_qty: Number(row.undetermined_qty || 0), confirm_qty: Number(row.confirm_qty || 0), remaining_confirm_qty: Number(row.remaining_confirm_qty ?? row.confirm_qty ?? 0), available_sales_qty: Number(row.available_sales_qty || 0), system_suggested_inventory_qty: Number(row.system_suggested_inventory_qty || 0), system_suggested_production_qty: Number(row.system_suggested_production_qty || 0), system_suggested_purchase_qty: Number(row.system_suggested_purchase_qty || 0) }))
        let actor = 0
        try { actor = JSON.parse(localStorage.getItem('erp_user') || '{}').legacy_id || 0 } catch (_) { /* Unavailable local profile. */ }
        this.storageKey = `erp_pending_sales_production_${actor}_${orderId}`
        try { this.pending = JSON.parse(localStorage.getItem(this.storageKey) || 'null') } catch (_) { this.pending = null }
        this.adjustmentReason = ''
        if (notify) this.$message.success('已按当前真实可用库存重新计算备货方案')
      } catch (error) { if (sequence === this.loadSequence) this.$message.error(error.userMessage || '订单生产确认数据加载失败') } finally { if (sequence === this.loadSequence) this.loading = false }
    },
    recalculate () { return this.load(true) },
    validateAll (notify) { const valid = this.lines.every(row => this.allocationValid(row)) && !this.blocked && (!this.hasManualAdjustment || !!this.adjustmentReason); if (notify) this.$message[valid ? 'success' : 'warning'](valid ? '检查通过，可以提交确认' : (this.hasManualAdjustment && !this.adjustmentReason ? '手工修改系统建议后必须填写调整原因' : '存在分配数量不一致或库存不足的订单行')); return valid },
    async submit () {
      if (this.submitting || this.loading || !this.$can('sales_order.production_confirm') || !this.order?.id
        || Number(this.pageRoute.params.id) !== Number(this.order.id)) return
      const orderId = this.order.id
      const storageKey = this.storageKey
      const contextSequence = this.loadSequence
      // Lock before opening the confirmation dialog. The reviewed quantities
      // belong to this order/version, even if navigation changes while it is open.
      this.submitting = true
      try {
        let payload = this.pending
        if (!payload) {
          if (!this.validateAll(false)) return this.$message.warning('请先完成全部订单行的备货数量配置和资料检查')
          payload = buildSalesProductionConfirmation(this.order, this.lines, this.adjustmentReason,
            `sales-production-${orderId}-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`)
          await this.$confirm('确认保存本订单的库存、生产、外购、服务及无需发货结果？', '提交订单生产确认', { type: 'warning' })
          if (contextSequence !== this.loadSequence || Number(this.pageRoute.params.id) !== Number(orderId)) return
          localStorage.setItem(storageKey, JSON.stringify(payload))
          this.pending = payload
        }
        await confirmProduction(orderId, payload)
        localStorage.removeItem(storageKey)
        if (Number(this.pageRoute.params.id) === Number(orderId)) {
          this.pending = null
          this.$message.success('订单生产确认已提交'); await this.load()
        }
      } catch (error) {
        if (error === 'cancel' || error === 'close') return
        const status = Number(error.response?.status || 0)
        if (status >= 400 && status < 500 && !['command_processing', 'command_recovery_required', 'recovery_required'].includes(error.errorCode)) {
          localStorage.removeItem(storageKey)
          if (Number(this.pageRoute.params.id) === Number(orderId)) {
            this.pending = null
            if ([403, 404, 409].includes(status)) await this.load()
          }
        }
        if (Number(this.pageRoute.params.id) === Number(orderId)) this.$message.error(error.userMessage || error.message || '订单生产确认提交失败，请重试原确认')
      } finally { this.submitting = false }
    },
    normalizeAllocation (row) { row.undetermined_qty = this.remainingUndetermined(row) },
    changeConfirmQuantity (row) { Object.assign(row, currentConfirmationSuggestion(row)); this.normalizeAllocation(row) },
    allocationSum (row) { return row ? confirmationAllocationSum(row) : 0 },
    allocationValid (row) { return !!row && Math.abs(this.allocationSum(row) - Number(row.confirm_qty || 0)) < 0.00000001 },
    lineAdjusted (row) { return this.isPhysical(row) && confirmationIsAdjusted(row) },
    currentSuggestion (row) { return currentConfirmationSuggestion(row) },
    suggestionStatus (row) {
      if (Number(row.confirm_qty || 0) === 0) return { text: '本次不确认', type: 'info' }
      const { inventory_qty: inventory, production_qty: production, purchase_qty: purchase } = this.currentSuggestion(row)
      if (purchase > 0) return { text: inventory > 0 ? '库存 + 外购备货' : '外购待到货', type: 'warning' }
      if (inventory > 0 && production <= 0) return { text: '全部库存备货', type: 'success' }
      if (inventory > 0 && production > 0) return { text: '库存 + 生产', type: 'warning' }
      return { text: '全部生产安排', type: 'info' }
    },
    itemBaseConfirmQty (row) { return this.isPhysical(row) ? Number(row.confirm_qty || 0) * Number(row.fulfillment_factor || 0) : 0 },
    groupQuantity (key) { const groups = {}; this.lines.forEach(row => { const qty = key === 'undetermined_qty' ? this.remainingUndetermined(row) : Number(row[key] || 0); if (qty <= 0) return; const unit = row.sales_unit || '-'; groups[unit] = (groups[unit] || 0) + qty }); const values = Object.keys(groups).map(unit => `${this.number(groups[unit])} ${unit}`); return values.join(' / ') || '0' },
    remainingUndetermined (row) { return Math.max(0, Number(row.remaining_confirm_qty ?? row.remaining_sales_qty ?? 0) - Number(row.confirm_qty || 0)) },
    lineBlocked (row) { return !this.allocationValid(row) || Number(row.inventory_qty || 0) > Number(row.available_sales_qty || 0) + 0.00000001 },
    blockingReason (row) { if (!this.allocationValid(row)) return '各项分配数量之和与订单数量不一致'; if (Number(row.inventory_qty || 0) > Number(row.available_sales_qty || 0) + 0.00000001) return '库存备货数量超过当前真实可用库存'; return '无阻塞' },
    isService (row) { return row.line_type === 'service' },
    isNoDelivery (row) { return ['no_delivery', 'fee', 'auxiliary'].includes(row.line_type) },
    isPhysical (row) { return !this.isService(row) && !this.isNoDelivery(row) },
    needsItem (row) { return this.isPhysical(row) },
    primaryType (row) { if (row.inventory_qty > 0 && row.purchase_qty > 0) return 'mixed_purchase'; if (row.purchase_qty > 0) return 'purchase'; if (row.inventory_qty > 0 && row.production_qty > 0) return 'mixed'; if (row.production_qty > 0) return 'production'; if (row.inventory_qty > 0) return 'inventory'; if (row.service_qty > 0) return 'service'; if (row.no_delivery_qty > 0) return 'no_delivery'; return 'undetermined' },
    serviceItemText (row) { return this.isService(row) ? '服务项目' : '无需 Item' },
    itemRoleText (row) { const type = this.primaryType(row); return ({ production: '生产 Item', inventory: '库存 Item', mixed: '库存/生产 Item', purchase: '外购备货', mixed_purchase: '库存/外购备货', service: '服务项目', no_delivery: '无需发货', undetermined: '尚未确定' })[type] },
    fulfillmentText (type) { return ({ inventory: '库存备货', production: '生产安排', purchase: '外购备货', mixed_purchase: '库存 + 外购', mixed: '库存 + 生产', service: '服务项目', no_delivery: '无需发货', undetermined: '尚未确定' })[type] || '待确认' },
    allocationText (row) { const parts = []; if (row.inventory_qty > 0) parts.push(`库存 ${this.number(row.inventory_qty)}`); if (row.production_qty > 0) parts.push(`生产 ${this.number(row.production_qty)}`); if (row.purchase_qty > 0) parts.push(`外购 ${this.number(row.purchase_qty)}`); if (row.service_qty > 0) parts.push(`服务 ${this.number(row.service_qty)}`); if (row.no_delivery_qty > 0) parts.push(`无需交付 ${this.number(row.no_delivery_qty)}`); if (this.remainingUndetermined(row) > 0) parts.push(`待确认 ${this.number(this.remainingUndetermined(row))}`); return parts.join(' + ') || '待配置' },
    typeTag (type) { return ({ inventory: 'success', production: '', purchase: 'warning', mixed_purchase: 'warning', mixed: 'warning', service: 'warning', no_delivery: 'info', undetermined: 'danger' })[type] || 'info' },
    salesPrecision (row) { return Number(row.sales_unit_precision || 0) },
    actualFulfillmentText (value) { return ({ pending: '未开始', partial: '部分完成', fulfilled: '已完成', cancelled: '已取消' })[value] || value || '-' },
    planStatusText (value) { return ({ unallocated: '未分配', partially_allocated: '部分分配', allocated: '已分配完成' })[value] || '未分配' },
    planStatusTag (value) { return ({ unallocated: 'info', partially_allocated: 'warning', allocated: 'success' })[value] || 'info' },
    fulfillmentProgressTag (value) { return ({ pending: 'warning', partial: 'warning', fulfilled: 'success', cancelled: 'danger' })[value] || 'info' },
    number (value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 6 }) },
    date (value) { return value ? erpDateTime(value, 10) : '-' }
  }
}
</script>

<style scoped>
.confirm-page{padding:10px 16px 24px;min-height:calc(100vh - 52px);background:#f7f9fc;color:#18243b}.page-head{height:42px;display:flex;align-items:center;justify-content:space-between}.crumb{font-size:14px}.actions{display:flex;gap:8px}.top-alert{margin-bottom:12px}.page-grid{display:grid;grid-template-columns:minmax(0,1fr) 290px;gap:16px}.order-summary,.panel,.side-card{background:#fff;border:1px solid #dfe5ed;border-radius:5px}.order-summary{display:grid;grid-template-columns:1.1fr 1.4fr .9fr .95fr .65fr .65fr .85fr;margin-bottom:12px}.order-summary>div{padding:15px 14px}.order-summary span,.sub{display:block;color:#66758a;font-size:12px;margin-bottom:8px}.panel{margin-bottom:12px;overflow:hidden}.panel h3,.side-card h3{margin:0;padding:13px 14px;font-size:15px}.panel h3 small,.side-card h3 small{color:#607085;font-weight:400}.sub{margin:5px 0 0}.side-card{padding:0 14px 12px;margin-bottom:12px}.side-card dl{display:grid;grid-template-columns:1fr auto;gap:13px;margin:6px 0}.side-card dt{color:#415270}.side-card dd{margin:0;font-weight:700;text-align:right}.side-card.blue{border-color:#99c1ff;color:#173b84}.side-card.quantity{border-color:#b7ddc7}.side-card.result{background:#f4f9ff}.side-card.result p{color:#1e7d4f}.side-card.warn{background:#fff8ec;border-color:#ffd8a8;color:#a65b09;padding-top:14px}.bottom-tip{margin-top:10px}.split-grid{display:grid;grid-template-columns:repeat(5,minmax(78px,1fr));gap:6px}.split-grid label{font-size:11px;color:#53647a;text-align:center}.split-grid .el-input-number{width:100%;margin-top:4px}.split-check{margin-top:6px;padding:5px 7px;border-radius:3px;font-size:11px}.split-check.pass{background:#effaf3;color:#07883f}.split-check.fail{background:#fff1f0;color:#d93025}.readiness-list{display:grid;grid-template-columns:1fr 1fr;gap:5px}.readiness-list span{display:flex;align-items:center;justify-content:space-between;gap:4px;font-size:11px}.block-text{color:#d93025}.ready-text{color:#07883f}.confirmation-table :deep(.el-input-number){width:100%}.confirmation-table :deep(.el-input__inner){height:27px;line-height:27px;padding:0 6px}.confirm-page :deep(.el-table th){background:#f5f7fa;color:#24324a}.confirm-page :deep(.el-button--success){background:#00984f;border-color:#00984f}@media(max-width:1200px){.page-grid{grid-template-columns:1fr}.order-summary{grid-template-columns:repeat(4,1fr)}}
.order-summary{grid-template-columns:repeat(3,minmax(0,1fr))}
.readonly-qty{display:flex;align-items:center;justify-content:center;height:28px;margin-top:4px;border:1px solid #dfe5ed;border-radius:4px;background:#f5f7fa;color:#59697e;font-size:12px}
.readonly-confirm{display:inline-flex;min-width:58px;height:28px;align-items:center;justify-content:center;border:1px solid #dfe5ed;border-radius:4px;background:#f5f7fa;font-weight:700}.inventory-analysis{display:grid;gap:5px;font-size:11px}.inventory-analysis>div{display:flex;justify-content:space-between;gap:8px}.inventory-analysis span{color:#68778b;white-space:nowrap}.inventory-analysis b{text-align:right;font-weight:600}.inventory-analysis .suggestion{color:#07883f}.inventory-analysis small{color:#8995a6;border-top:1px dashed #e2e7ee;padding-top:5px}.manual-adjusted{margin-top:5px;color:#d46b08;font-size:11px}.adjustment-reason{display:grid;grid-template-columns:260px minmax(0,1fr);gap:16px;align-items:start;padding:12px 14px;border-top:1px solid #e4e9f0;background:#fff8ec}.adjustment-reason>div{display:flex;flex-direction:column;gap:5px}.adjustment-reason em{color:#e34848;font-style:normal}.adjustment-reason small{color:#7b8798;font-weight:400}.muted{color:#8a96a8}.inventory-analysis .prebuild-hint b{display:grid;gap:2px;color:#b45309}.inventory-analysis .prebuild-hint em{font-style:normal}.inventory-analysis .prebuild-tip{color:#b45309;border-top:0;padding-top:0}

.confirm-page, .page-grid > main, .page-grid > aside { min-width: 0; box-sizing: border-box; }
@media (max-width: 780px) {
  .page-head { height: auto; align-items: flex-start; flex-wrap: wrap; gap: 12px; padding: 8px 0 12px; }
  .actions { flex-wrap: wrap; }
  .order-summary { grid-template-columns: minmax(0, 1fr); }
  .adjustment-reason { grid-template-columns: minmax(0, 1fr); }
}
</style>
