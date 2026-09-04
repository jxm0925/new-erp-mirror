<!-- Locked reference: D:\codex-introduce\new_erp\docs\ui-reference\unit-conversion-system\12-order-item-requirement.png -->
<template>
  <section class="confirm-page" v-loading="loading">
    <header class="page-head">
      <div class="crumb">ERP　/　<b>销售订单</b>　/　<b>订单生产确认</b></div>
      <div class="actions">
        <el-button size="small" @click="returnToOrder">返回</el-button>
        <el-button size="small" icon="el-icon-refresh" :disabled="!editable" :loading="loading" @click="recalculate">重新计算履约方案</el-button>
        <el-button size="small" :disabled="!editable" @click="validateAll(true)">保存检查结果</el-button>
        <el-button size="small" type="success" :disabled="!canSubmit" :loading="submitting" @click="submit">提交确认</el-button>
      </div>
    </header>

    <el-alert class="top-alert" type="info" :closable="false" show-icon title="本页仅确认订单履约与生产资料，保存生产需求契约；不创建生产工单、工序任务或生产排程。" />

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
          <div><span>实际履约状态</span><el-tag size="mini" :type="fulfillmentProgressTag(order.fulfillment_status)">{{ actualFulfillmentText(order.fulfillment_status) }}</el-tag></div>
          <div><span>履约方案状态</span><el-tag size="mini" :type="planStatusTag(order.fulfillment_plan_status)">{{ order.fulfillment_plan_status_label || planStatusText(order.fulfillment_plan_status) }}</el-tag></div>
          <div><span>履约组成</span><b>{{ order.fulfillment_composition_label || '尚未形成履约明细' }}</b></div>
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
                <span class="readonly-confirm">{{ number(row.confirm_qty) }}</span>
                <small class="sub">{{ row.sales_unit || '-' }}</small>
              </template>
            </el-table-column>
            <el-table-column label="库存履约分析" min-width="260">
              <template slot-scope="{row}">
                <div v-if="isPhysical(row)" class="inventory-analysis">
                  <div><span>默认履约 Item</span><b>{{ row.default_item_name || row.item_name || '-' }}</b></div>
                  <div><span>当前可用库存</span><b>{{ number(row.available_base_qty) }} {{ row.base_unit || '-' }}</b></div>
                  <div><span>方案状态</span><el-tag size="mini" :type="suggestionStatus(row).type">{{ suggestionStatus(row).text }}</el-tag></div>
                  <div><span>系统建议</span><b class="suggestion">库存 {{ number(row.system_suggested_inventory_qty) }} / 生产 {{ number(row.system_suggested_production_qty) }} {{ row.sales_unit || '-' }}</b></div>
                  <div><span>判断原因</span><b>{{ row.system_suggestion_reason || '-' }}</b></div>
                  <small>计算时间：{{ row.inventory_calculated_at || '-' }}</small>
                </div>
                <span v-else class="muted">{{ row.system_suggestion_reason }}</span>
              </template>
            </el-table-column>
            <el-table-column label="逐行履约拆分（销售单位）" min-width="465">
              <template slot-scope="{row}">
                <div class="split-grid">
                  <label>库存<el-input-number v-model="row.inventory_qty" :disabled="!editable || !isPhysical(row)" :min="0" :max="Math.min(Number(row.confirm_qty || 0), Number(row.available_sales_qty || 0))" :precision="salesPrecision(row)" :controls="false" /></label>
                  <label>生产<el-input-number v-model="row.production_qty" :disabled="!editable || !isPhysical(row)" :min="0" :max="row.confirm_qty" :precision="salesPrecision(row)" :controls="false" /></label>
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
            <div><b>履约方案调整原因 <em>*</em></b><small>系统会同时保存建议数量、最终数量、操作人和操作时间</small></div>
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
        <section class="side-card blue"><h3>服务履约需求：{{ summary.service || 0 }} 项</h3></section>
        <section class="side-card result"><h3>提交结果预览 <small>（确认通过后将执行）</small></h3><p v-for="text in submitResults" :key="text"><i class="el-icon-success" /> {{ text }}</p></section>
        <section class="side-card warn"><b><i class="el-icon-warning" /> 服务及无需发货行不进入库存或生产履约；</b><p>本阶段不创建生产工单、工序任务或排程。</p></section>
      </aside>
    </div>

    <el-empty v-if="!order && !loading" description="未找到订单生产确认数据" />
    <el-alert v-if="blocked" class="bottom-tip" type="warning" :closable="false" show-icon title="存在数量不守恒或生产资料不齐的履约行，请处理后再提交确认。" />
  </section>
</template>

<script>
import { confirmProduction, getProductionPreview } from '@/api/erp/sales'

const ReadinessTag = {
  props: ['value'],
  render (h) {
    const text = this.value === 'ready' ? '已齐全' : (this.value === 'missing' ? '资料不齐' : (this.value === 'pending_next_stage' ? '下一阶段维护' : '不适用'))
    const type = this.value === 'ready' ? 'success' : (this.value === 'missing' ? 'danger' : 'info')
    return h('el-tag', { props: { size: 'mini', type } }, text)
  }
}

export default {
  name: 'SalesProductionConfirmation',
  components: { ReadinessTag },
  data: () => ({ loading: false, submitting: false, preview: {}, order: null, lines: [], adjustmentReason: '' }),
  computed: {
    editable () { return this.order && this.order.order_status === 'confirmed' && ['pending', 'blocked'].includes(this.order.production_confirm_status) },
    blocked () { return this.lines.some(row => this.lineBlocked(row)) },
    canSubmit () { return this.editable && !this.blocked && this.lines.length > 0 && this.lines.some(row => Number(row.confirm_qty || 0) > 0) && this.lines.every(row => this.allocationValid(row)) && (!this.hasManualAdjustment || !!this.adjustmentReason) },
    hasManualAdjustment () { return this.lines.some(row => this.lineAdjusted(row)) },
    baseGroups () { return this.preview.base_unit_groups || [] },
    summary () { return { total: this.lines.length, inventory: this.lines.filter(row => row.inventory_qty > 0).length, production: this.lines.filter(row => row.production_qty > 0).length, service: this.lines.filter(row => row.service_qty > 0).length, no_delivery: this.lines.filter(row => row.no_delivery_qty > 0).length } },
    resultText () { return this.order.production_confirm_status === 'confirmed' ? '已确认并锁定' : (this.blocked ? '待处理' : '待确认') },
    countRows () { return [{ label: '订单总行数', value: this.summary.total }, { label: '库存履约行数', value: this.summary.inventory }, { label: '生产履约行数', value: this.summary.production }, { label: '服务履约行数', value: this.summary.service }, { label: '无需发货行数', value: this.summary.no_delivery }] },
    quantityRows () { return [{ label: '库存履约', value: this.groupQuantity('inventory_qty') }, { label: '生产履约', value: this.groupQuantity('production_qty') }, { label: '服务履约', value: this.groupQuantity('service_qty') }, { label: '无需发货', value: this.groupQuantity('no_delivery_qty') }, { label: '尚未确定', value: this.groupQuantity('undetermined_qty') }] },
    submitResults () { const rows = ['保存订单生产确认结果']; if (this.summary.inventory) rows.push('生成库存履约需求（Item基本数量）'); if (this.summary.production) rows.push('生成生产需求契约（销售/基本数量双口径）'); if (this.summary.service) rows.push('生成服务履约需求'); if (this.summary.no_delivery) rows.push('保存无需发货结果'); rows.push('锁定本次确认所使用的生产资料'); return rows }
  },
  created () { this.load() },
  watch: {
    '$route.params.id' () { this.load() }
  },
  methods: {
    returnToOrder () {
      if (!this.order || !this.order.id) return this.$router.push('/sales/orders')
      return this.$router.push(`/sales/orders/${this.order.id}/detail`)
    },
    async load (notify = false) {
      this.loading = true
      try {
        const { data } = await getProductionPreview(this.$route.params.id)
        this.preview = data || {}; this.order = data.order || null
        this.lines = (data.lines || []).map(row => ({ ...row, inventory_qty: Number(row.inventory_qty || 0), production_qty: Number(row.production_qty || 0), service_qty: Number(row.service_qty || 0), no_delivery_qty: Number(row.no_delivery_qty || 0), undetermined_qty: Number(row.undetermined_qty || 0), confirm_qty: Number(row.confirm_qty || 0), available_sales_qty: Number(row.available_sales_qty || 0), system_suggested_inventory_qty: Number(row.system_suggested_inventory_qty || 0), system_suggested_production_qty: Number(row.system_suggested_production_qty || 0) }))
        this.adjustmentReason = ''
        if (notify) this.$message.success('已按当前真实可用库存重新计算履约方案')
      } catch (error) { this.$message.error(error.userMessage || '订单生产确认数据加载失败') } finally { this.loading = false }
    },
    recalculate () { return this.load(true) },
    validateAll (notify) { const valid = this.lines.every(row => this.allocationValid(row)) && !this.blocked && (!this.hasManualAdjustment || !!this.adjustmentReason); if (notify) this.$message[valid ? 'success' : 'warning'](valid ? '检查通过，可以提交确认' : (this.hasManualAdjustment && !this.adjustmentReason ? '手工修改系统建议后必须填写调整原因' : '存在数量不守恒、库存不足或资料不齐的订单行')); return valid },
    async submit () {
      if (!this.validateAll(false)) return this.$message.warning('请先完成全部订单行的履约数量配置和资料检查')
      if (this.hasManualAdjustment && !this.adjustmentReason) return this.$message.warning('手工修改系统履约建议时必须填写调整原因')
      await this.$confirm('确认保存本订单的库存、生产、服务及无需交付履约需求？本操作不会创建生产工单。', '提交订单生产确认', { type: 'warning' })
      this.submitting = true
      try {
        await confirmProduction(this.order.id, { adjustment_reason: this.adjustmentReason || null, lines: this.lines.map(row => ({ sales_order_line_id: row.sales_order_line_id, confirm_qty: row.confirm_qty, inventory_qty: row.inventory_qty, production_qty: row.production_qty, service_qty: row.service_qty, no_delivery_qty: row.no_delivery_qty })) })
        this.$message.success('订单生产确认已提交，未创建生产工单'); await this.load()
      } catch (error) { this.$message.error(error.userMessage || '订单生产确认提交失败') } finally { this.submitting = false }
    },
    normalizeAllocation (row) { row.undetermined_qty = this.remainingUndetermined(row) },
    allocationSum (row) { if (!row) return 0; return ['inventory_qty', 'production_qty', 'service_qty', 'no_delivery_qty'].reduce((sum, key) => sum + Number(row[key] || 0), 0) },
    allocationValid (row) { return !!row && Math.abs(this.allocationSum(row) - Number(row.confirm_qty || 0)) < 0.00000001 },
    lineAdjusted (row) { return this.isPhysical(row) && (Math.abs(Number(row.inventory_qty || 0) - Number(row.system_suggested_inventory_qty || 0)) > 0.00000001 || Math.abs(Number(row.production_qty || 0) - Number(row.system_suggested_production_qty || 0)) > 0.00000001) },
    suggestionStatus (row) {
      const inventory = Number(row.system_suggested_inventory_qty || 0)
      const production = Number(row.system_suggested_production_qty || 0)
      if (inventory > 0 && production <= 0) return { text: '全部库存履约', type: 'success' }
      if (inventory > 0 && production > 0) return { text: '库存 + 生产', type: 'warning' }
      return { text: '全部生产履约', type: 'info' }
    },
    itemBaseConfirmQty (row) { return this.isPhysical(row) ? Number(row.confirm_qty || 0) * Number(row.fulfillment_factor || 0) : 0 },
    groupQuantity (key) { const groups = {}; this.lines.forEach(row => { const qty = key === 'undetermined_qty' ? this.remainingUndetermined(row) : Number(row[key] || 0); if (qty <= 0) return; const unit = row.sales_unit || '-'; groups[unit] = (groups[unit] || 0) + qty }); const values = Object.keys(groups).map(unit => `${this.number(groups[unit])} ${unit}`); return values.join(' / ') || '0' },
    remainingUndetermined (row) { return Math.max(0, Number(row.remaining_sales_qty || 0) - Number(row.confirm_qty || 0)) },
    lineBlocked (row) { return !this.allocationValid(row) || Number(row.inventory_qty || 0) > Number(row.available_sales_qty || 0) + 0.00000001 || (Number(row.production_qty || 0) > 0 && ['bom', 'drawing'].some(key => row.data_readiness && row.data_readiness[key] === 'missing')) },
    blockingReason (row) { if (!this.allocationValid(row)) return '履约拆分数量不守恒'; if (Number(row.inventory_qty || 0) > Number(row.available_sales_qty || 0) + 0.00000001) return '库存履约数量超过当前真实可用库存'; if (Number(row.production_qty || 0) > 0 && row.data_readiness && row.data_readiness.bom === 'missing') return '未匹配到可用 BOM'; if (Number(row.production_qty || 0) > 0 && row.data_readiness && row.data_readiness.drawing === 'missing') return '特殊定制缺少设计图纸'; return '无阻塞' },
    isService (row) { return row.line_type === 'service' },
    isNoDelivery (row) { return ['no_delivery', 'fee', 'auxiliary'].includes(row.line_type) },
    isPhysical (row) { return !this.isService(row) && !this.isNoDelivery(row) },
    needsItem (row) { return this.isPhysical(row) },
    primaryType (row) { if (row.inventory_qty > 0 && row.production_qty > 0) return 'mixed'; if (row.production_qty > 0) return 'production'; if (row.inventory_qty > 0) return 'inventory'; if (row.service_qty > 0) return 'service'; if (row.no_delivery_qty > 0) return 'no_delivery'; return 'undetermined' },
    serviceItemText (row) { return this.isService(row) ? '服务项目' : '无需 Item' },
    itemRoleText (row) { const type = this.primaryType(row); return ({ production: '生产 Item', inventory: '库存 Item', mixed: '库存/生产 Item', service: '服务项目', no_delivery: '无需发货', undetermined: '尚未确定' })[type] },
    fulfillmentText (type) { return ({ inventory: '库存履约', production: '生产履约', mixed: '混合履约', service: '服务履约', no_delivery: '无需发货', undetermined: '尚未确定' })[type] || '待确认' },
    allocationText (row) { const parts = []; if (row.inventory_qty > 0) parts.push(`库存 ${this.number(row.inventory_qty)}`); if (row.production_qty > 0) parts.push(`生产 ${this.number(row.production_qty)}`); if (row.service_qty > 0) parts.push(`服务 ${this.number(row.service_qty)}`); if (row.no_delivery_qty > 0) parts.push(`无需交付 ${this.number(row.no_delivery_qty)}`); if (this.remainingUndetermined(row) > 0) parts.push(`待确认 ${this.number(this.remainingUndetermined(row))}`); return parts.join(' + ') || '待配置' },
    typeTag (type) { return ({ inventory: 'success', production: '', mixed: 'warning', service: 'warning', no_delivery: 'info', undetermined: 'danger' })[type] || 'info' },
    salesPrecision (row) { return Number(row.sales_unit_precision || 0) },
    actualFulfillmentText (value) { return ({ pending: '未开始', partial: '部分完成', fulfilled: '已完成', cancelled: '已取消' })[value] || value || '-' },
    planStatusText (value) { return ({ unallocated: '未分配', partially_allocated: '部分分配', allocated: '已分配完成' })[value] || '未分配' },
    planStatusTag (value) { return ({ unallocated: 'info', partially_allocated: 'warning', allocated: 'success' })[value] || 'info' },
    fulfillmentProgressTag (value) { return ({ pending: 'warning', partial: 'warning', fulfilled: 'success', cancelled: 'danger' })[value] || 'info' },
    number (value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 6 }) },
    date (value) { return value ? String(value).replace('T', ' ').slice(0, 10) : '-' }
  }
}
</script>

<style scoped>
.confirm-page{padding:10px 16px 24px;min-height:calc(100vh - 52px);background:#f7f9fc;color:#18243b}.page-head{height:42px;display:flex;align-items:center;justify-content:space-between}.crumb{font-size:14px}.actions{display:flex;gap:8px}.top-alert{margin-bottom:12px}.page-grid{display:grid;grid-template-columns:minmax(0,1fr) 290px;gap:16px}.order-summary,.panel,.side-card{background:#fff;border:1px solid #dfe5ed;border-radius:5px}.order-summary{display:grid;grid-template-columns:1.1fr 1.4fr .9fr .95fr .65fr .65fr .85fr;margin-bottom:12px}.order-summary>div{padding:15px 14px}.order-summary span,.sub{display:block;color:#66758a;font-size:12px;margin-bottom:8px}.panel{margin-bottom:12px;overflow:hidden}.panel h3,.side-card h3{margin:0;padding:13px 14px;font-size:15px}.panel h3 small,.side-card h3 small{color:#607085;font-weight:400}.sub{margin:5px 0 0}.side-card{padding:0 14px 12px;margin-bottom:12px}.side-card dl{display:grid;grid-template-columns:1fr auto;gap:13px;margin:6px 0}.side-card dt{color:#415270}.side-card dd{margin:0;font-weight:700;text-align:right}.side-card.blue{border-color:#99c1ff;color:#173b84}.side-card.quantity{border-color:#b7ddc7}.side-card.result{background:#f4f9ff}.side-card.result p{color:#1e7d4f}.side-card.warn{background:#fff8ec;border-color:#ffd8a8;color:#a65b09;padding-top:14px}.bottom-tip{margin-top:10px}.split-grid{display:grid;grid-template-columns:repeat(5,minmax(78px,1fr));gap:6px}.split-grid label{font-size:11px;color:#53647a;text-align:center}.split-grid .el-input-number{width:100%;margin-top:4px}.split-check{margin-top:6px;padding:5px 7px;border-radius:3px;font-size:11px}.split-check.pass{background:#effaf3;color:#07883f}.split-check.fail{background:#fff1f0;color:#d93025}.readiness-list{display:grid;grid-template-columns:1fr 1fr;gap:5px}.readiness-list span{display:flex;align-items:center;justify-content:space-between;gap:4px;font-size:11px}.block-text{color:#d93025}.ready-text{color:#07883f}.confirmation-table :deep(.el-input-number){width:100%}.confirmation-table :deep(.el-input__inner){height:27px;line-height:27px;padding:0 6px}.confirm-page :deep(.el-table th){background:#f5f7fa;color:#24324a}.confirm-page :deep(.el-button--success){background:#00984f;border-color:#00984f}@media(max-width:1200px){.page-grid{grid-template-columns:1fr}.order-summary{grid-template-columns:repeat(4,1fr)}}
.order-summary{grid-template-columns:repeat(3,minmax(0,1fr))}
.readonly-qty{display:flex;align-items:center;justify-content:center;height:28px;margin-top:4px;border:1px solid #dfe5ed;border-radius:4px;background:#f5f7fa;color:#59697e;font-size:12px}
.readonly-confirm{display:inline-flex;min-width:58px;height:28px;align-items:center;justify-content:center;border:1px solid #dfe5ed;border-radius:4px;background:#f5f7fa;font-weight:700}.inventory-analysis{display:grid;gap:5px;font-size:11px}.inventory-analysis>div{display:flex;justify-content:space-between;gap:8px}.inventory-analysis span{color:#68778b;white-space:nowrap}.inventory-analysis b{text-align:right;font-weight:600}.inventory-analysis .suggestion{color:#07883f}.inventory-analysis small{color:#8995a6;border-top:1px dashed #e2e7ee;padding-top:5px}.manual-adjusted{margin-top:5px;color:#d46b08;font-size:11px}.adjustment-reason{display:grid;grid-template-columns:260px minmax(0,1fr);gap:16px;align-items:start;padding:12px 14px;border-top:1px solid #e4e9f0;background:#fff8ec}.adjustment-reason>div{display:flex;flex-direction:column;gap:5px}.adjustment-reason em{color:#e34848;font-style:normal}.adjustment-reason small{color:#7b8798;font-weight:400}.muted{color:#8a96a8}
</style>
