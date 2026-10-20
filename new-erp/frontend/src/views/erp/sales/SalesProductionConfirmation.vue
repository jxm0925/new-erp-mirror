<template>
  <section class="mfg-page" v-loading="loading">
    <div class="mfg-hero">
      <div class="mfg-crumb"><a @click="$router.push('/sales/orders')">销售管理 / 销售订单</a> / <a v-if="order" class="mono" @click="returnToOrder">{{ order.sales_order_no }}</a> / 订单生产确认</div>
      <div class="mfg-hrow">
        <div>
          <div class="mfg-title">{{ order ? (order.customer_name || '未填写客户') : '订单生产确认' }}
            <span v-if="order" :class="['pill', confirmed ? 'p-g' : 'p-y']">{{ confirmed ? '已生产确认' : '待生产确认' }}</span>
            <span v-if="order && order.is_urgent" class="pill p-r">加急</span>
          </div>
          <div v-if="order" class="mfg-meta">
            <span>订单日期<b>{{ date(order.order_time) }}</b></span>
            <span>交付要求<b>{{ date(order.required_delivery_date) }}</b></span>
            <span>销售人员<b>{{ order.created_by || '-' }}</b></span>
            <span>付款条件<b>{{ funding ? (funding.policy_name || '未配置') : '-' }}</b></span>
            <span v-if="funding">开工<b :class="funding.production_funds_satisfied ? 'hero-ok' : 'hero-warn'">{{ productionFundingText }}</b></span>
            <span v-if="funding">发货<b :class="funding.shipment_funds_satisfied ? 'hero-ok' : 'hero-warn'">{{ shipmentFundingText }}</b></span>
          </div>
        </div>
        <div class="mfg-actions">
          <el-button class="ghost" @click="returnToOrder">返回订单</el-button>
          <el-button class="ghost" :disabled="!editable" :loading="loading" @click="recalculate">重新计算</el-button>
          <el-button v-if="!confirmed || pending" class="primary" :disabled="!canSubmit" :loading="submitting" @click="submit">{{ pending ? '重试原确认' : '提交确认' }}</el-button>
        </div>
      </div>
    </div>

    <div class="mfg-body">
      <el-alert v-if="pending" class="top-alert" type="warning" :closable="false" show-icon title="上次确认结果尚未收到。请点“重试原确认”，系统会识别同一次操作；核实前暂不能修改本次数量。" />
      <el-alert v-if="funding && !funding.production_funds_satisfied && !confirmed" class="top-alert" type="warning" :closable="false" show-icon :title="`${funding.production_block_message || '定金未到'}：可以先确认，工单会生成并下达，但收够定金前车间不能开工。`" />

      <div v-if="order" class="mfg-grid">
        <div>
          <div class="card">
            <h2>每一行怎么备货 <small>{{ editable ? '系统已按实时库存算好，可改数量；改了要写原因' : '已确认的备货方案（只读）' }}</small></h2>
            <div v-for="row in lines" :key="row.sales_order_line_id" :class="['line', { svc: isService(row) || isNoDelivery(row), bad: lineBlocked(row) }]">
              <div class="l-name">
                <b>{{ row.product_name || row.item_name || '-' }}</b>
                <div class="sub">× {{ number(row.order_qty) }} {{ row.sales_unit || '' }}<template v-if="row.sku_name"> · {{ row.sku_name }}</template></div>
                <div class="tags">
                  <span v-for="t in lineTags(row)" :key="t.text" :class="['chip', t.cls]">{{ t.text }}</span>
                </div>
              </div>

              <div class="l-route">
                <template v-if="isService(row)"><span class="sub">不进车间、不进发货单</span></template>
                <template v-else-if="isNoDelivery(row)"><span class="sub">无需发货，不进车间</span></template>
                <template v-else>
                  <div v-if="row.production_qty > 0 && row.routing_summary && row.routing_summary.production_operations.length" class="flow"><span class="lbl">车间</span><template v-for="(op, i) in row.routing_summary.production_operations"><span v-if="i" :key="'a' + i" class="arrow">›</span><span :key="'p' + i" class="chip c-b">{{ op }}</span></template></div>
                  <div class="flow"><span class="lbl">发货</span>
                    <template v-if="row.routing_summary && row.routing_summary.shipment_operations.length"><template v-for="(op, i) in row.routing_summary.shipment_operations"><span v-if="i" :key="'s' + i" class="arrow">›</span><span :key="'o' + i" class="chip c-g">{{ op }}</span></template></template>
                    <span v-else class="chip c-n">无发货作业</span>
                  </div>
                  <div class="flow">
                    <span v-if="row.production_qty > 0" :class="['chip', row.bom_summary ? 'c-g' : 'c-r']">{{ row.bom_summary ? `BOM V${row.bom_summary.version} ✓` : '缺 BOM' }}</span>
                    <span v-if="row.routing_summary" class="chip c-g">{{ row.routing_summary.routing_name }} V{{ row.routing_summary.version }} ✓</span>
                    <template v-else><span class="chip c-r">未设工艺路线</span><span v-if="$can('production.routing.create')" class="lk" @click="$router.push('/production/routings/new')">去建路线</span></template>
                    <span v-if="row.data_readiness && row.data_readiness.drawing === 'ready'" class="chip c-g">图纸 ✓</span>
                    <span v-if="row.data_readiness && row.data_readiness.drawing === 'missing'" class="chip c-o">缺定制图纸</span>
                  </div>
                  <div v-if="!row.routing_summary" class="sub">{{ row.production_qty > 0 ? '缺路线的工单会停在计划，补齐后由生产主管下达' : '不建也能确认，发货时不打包、跟主机一起发' }}</div>
                </template>
              </div>

              <div class="l-qty">
                <div v-if="isService(row)" class="svc-check">
                  <el-checkbox v-model="row.service_completed" :disabled="!editable">已完成</el-checkbox><span class="sub">（默认勾上，没发可取消）</span>
                </div>
                <template v-else>
                  <div class="boxes">
                    <label v-if="row.remaining_confirm_qty !== row.order_qty || !editable" class="box"><span>本次确认</span>
                      <el-input-number v-if="editable" v-model="row.confirm_qty" size="mini" :min="0" :max="row.remaining_confirm_qty" :precision="salesPrecision(row)" :controls="false" @change="changeConfirmQuantity(row)" />
                      <b v-else>{{ number(row.confirm_qty) }}</b>
                    </label>
                    <template v-if="isPhysical(row)">
                      <label class="box"><span>用库存</span>
                        <el-input-number v-if="editable" v-model="row.inventory_qty" size="mini" :min="0" :max="Math.min(Number(row.confirm_qty || 0), Number(row.available_sales_qty || 0))" :precision="salesPrecision(row)" :controls="false" />
                        <b v-else>{{ number(row.inventory_qty) }}</b>
                      </label>
                      <label class="box"><span>生产</span>
                        <el-input-number v-if="editable && !row.is_purchase_fulfilled" v-model="row.production_qty" size="mini" :min="0" :max="row.confirm_qty" :precision="salesPrecision(row)" :controls="false" />
                        <b v-else>{{ row.is_purchase_fulfilled ? '—' : number(row.production_qty) }}</b>
                      </label>
                      <label class="box"><span>外购</span>
                        <el-input-number v-if="editable && row.is_purchase_fulfilled" v-model="row.purchase_qty" size="mini" :min="0" :max="row.confirm_qty" :precision="salesPrecision(row)" :controls="false" />
                        <b v-else>{{ row.is_purchase_fulfilled ? number(row.purchase_qty) : '—' }}</b>
                      </label>
                    </template>
                    <label v-else class="box"><span>无需发货</span>
                      <el-input-number v-if="editable" v-model="row.no_delivery_qty" size="mini" :min="0" :max="row.confirm_qty" :precision="salesPrecision(row)" :controls="false" />
                      <b v-else>{{ number(row.no_delivery_qty) }}</b>
                    </label>
                  </div>
                  <div v-if="isPhysical(row)" class="sub avail">可用库存 {{ number(row.available_sales_qty) }} {{ row.sales_unit || '' }}</div>
                </template>
                <div :class="['result', lineBlocked(row) ? 'warn' : 'ok']">{{ lineBlocked(row) ? blockingReason(row) : lineResult(row) }}</div>
                <div v-if="lineAdjusted(row)" class="amb small">已改系统建议，提交时要写原因</div>
              </div>
            </div>
            <div v-if="editable && hasManualAdjustment" class="reason">
              <b>调整原因 <em>*</em></b>
              <el-input v-model.trim="adjustmentReason" type="textarea" :rows="2" maxlength="500" show-word-limit placeholder="例如：客户要新货，不用库存那台" />
            </div>
            <div class="note">改了系统建议的数量时，提交前要填调整原因，例如“客户要新货，不用库存那台”。</div>
          </div>
        </div>

        <div>
          <div class="card">
            <h2>{{ confirmed ? '确认结果' : '提交后会发生什么' }}</h2>
            <div class="sum"><span>生成工单并自动下达</span><b>{{ confirmed ? workOrders.length : summary.production }}</b></div>
            <div class="sum"><span>锁定库存</span><b>{{ summary.inventory }} <small>行</small></b></div>
            <div class="sum"><span>外购缺口，转采购</span><b class="amb">{{ summary.purchase }}</b></div>
            <div class="sum"><span>服务已完成</span><b>{{ serviceDone }}</b></div>
          </div>
          <div v-if="workOrders.length" class="card">
            <h2>已生成的工单</h2>
            <div v-for="wo in workOrders" :key="wo.id" class="wo-row">
              <span class="mono lk" @click="openWorkOrder(wo)">{{ wo.work_order_no }}</span>
              <span class="sub">{{ wo.item_name }} × {{ number(wo.planned_qty) }}</span>
              <span class="chip c-g">{{ wo.status_label }}</span>
            </div>
          </div>
          <div class="card">
            <h2>工单生成后</h2>
            <ul class="bullets">
              <li>自动下达，生成序列号</li>
              <li>仓库配料员按工序配料，车间签收确认</li>
              <li>生产主管只能调整（负责人、日期、加急等）</li>
              <li>定金不够不能开工；尾款不齐不能发运</li>
            </ul>
          </div>
          <div v-if="attention.length" class="card">
            <h2>需要注意 <small>{{ attention.length }} 项</small></h2>
            <div v-for="(a, i) in attention" :key="i" class="att"><b :class="a.block ? 'warn' : 'amb'">{{ a.title }}</b><div class="sub">{{ a.text }}</div></div>
          </div>
        </div>
      </div>
      <el-empty v-if="!order && !loading" description="未找到订单生产确认数据" />
    </div>
  </section>
</template>

<script>
import { erpDateTime } from '../../../utils/dateTime'
import cachedPageRoute from '@/utils/cachedPageRoute'
import { confirmProduction, getProductionPreview } from '@/api/erp/sales'
import { confirmationAllocationSum, currentConfirmationSuggestion, confirmationIsAdjusted, buildSalesProductionConfirmation } from '../../../utils/sales-production-confirmation.mjs'

export default {
  mixins: [cachedPageRoute],
  name: 'SalesProductionConfirmation',
  data: () => ({ loading: false, submitting: false, preview: {}, order: null, lines: [], adjustmentReason: '', pending: null, storageKey: '', loadSequence: 0 }),
  computed: {
    editable () { return !this.pending && this.$can('sales_order.production_confirm') && this.order && this.order.order_status === 'confirmed' && ['pending', 'blocked'].includes(this.order.production_confirm_status) },
    blocked () { return this.lines.some(row => this.lineBlocked(row)) },
    canSubmit () { return this.$can('sales_order.production_confirm') && !this.loading && (!!this.pending || (this.editable && !this.blocked && this.lines.length > 0 && this.lines.some(row => Number(row.confirm_qty || 0) > 0) && this.lines.every(row => this.allocationValid(row)) && (!this.hasManualAdjustment || !!this.adjustmentReason))) },
    hasManualAdjustment () { return this.lines.some(row => this.lineAdjusted(row)) },
    baseGroups () { return this.preview.base_unit_groups || [] },
    summary () { return { total: this.lines.length, inventory: this.lines.filter(row => row.inventory_qty > 0).length, production: this.lines.filter(row => row.production_qty > 0).length, purchase: this.lines.filter(row => row.purchase_qty > 0).length, service: this.lines.filter(row => row.service_qty > 0).length, no_delivery: this.lines.filter(row => row.no_delivery_qty > 0).length } },
    confirmed () { return !!this.order && this.order.production_confirm_status === 'confirmed' },
    funding () { return this.preview.funding_status || null },
    workOrders () { return this.preview.work_orders || [] },
    serviceDone () { return this.lines.filter(row => this.isService(row) && row.service_completed !== false && Number(row.service_qty || 0) > 0).length },
    productionFundingText () {
      const f = this.funding
      const got = f.net_received_amount !== undefined ? `已收 ${this.money(f.net_received_amount)} · ` : ''
      return f.production_funds_satisfied ? `${got}够了` : `${got}${f.production_required_amount !== undefined ? '需 ' + this.money(f.production_required_amount) : '定金未到'}`
    },
    shipmentFundingText () {
      const f = this.funding
      if (f.shipment_funds_satisfied) return '已收齐'
      return f.outstanding_amount !== undefined ? `还差尾款 ${this.money(f.outstanding_amount)}` : '尾款未齐'
    },
    attention () {
      const list = []
      this.lines.forEach(row => {
        if (this.lineBlocked(row)) list.push({ block: true, title: `第 ${row.line_no} 行：${this.blockingReason(row)}`, text: '改好数量后才能提交' })
        if (!this.isPhysical(row)) return
        if (!row.routing_summary) list.push({ title: `${row.product_name || row.item_name}未设工艺路线`, text: row.production_qty > 0 ? '工单会停在计划，补齐路线后由生产主管下达' : '只提醒，不影响确认' })
        if (row.production_qty > 0 && !row.bom_summary) list.push({ title: `${row.product_name || row.item_name}没有可用 BOM`, text: '工单会停在计划，补齐 BOM 后由生产主管下达' })
      })
      return list
    }
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
        this.lines = (data.lines || []).map(row => ({ ...row, inventory_qty: Number(row.inventory_qty || 0), production_qty: Number(row.production_qty || 0), purchase_qty: Number(row.purchase_qty || 0), service_qty: Number(row.service_qty || 0), no_delivery_qty: Number(row.no_delivery_qty || 0), undetermined_qty: Number(row.undetermined_qty || 0), confirm_qty: Number(row.confirm_qty || 0), remaining_confirm_qty: Number(row.remaining_confirm_qty ?? row.confirm_qty ?? 0), available_sales_qty: Number(row.available_sales_qty || 0), system_suggested_inventory_qty: Number(row.system_suggested_inventory_qty || 0), system_suggested_production_qty: Number(row.system_suggested_production_qty || 0), system_suggested_purchase_qty: Number(row.system_suggested_purchase_qty || 0), service_completed: row.service_completed !== false }))
        let actor = 0
        try { actor = JSON.parse(localStorage.getItem('erp_user') || '{}').legacy_id || 0 } catch (_) { /* Unavailable local profile. */ }
        this.storageKey = `erp_pending_sales_production_${actor}_${orderId}`
        try { this.pending = JSON.parse(localStorage.getItem(this.storageKey) || 'null') } catch (_) { this.pending = null }
        this.adjustmentReason = ''
        if (notify) this.$message.success('已按当前真实可用库存重新计算备货方案')
      } catch (error) { if (sequence === this.loadSequence) this.$message.error(error.userMessage || '订单生产确认数据加载失败') } finally { if (sequence === this.loadSequence) this.loading = false }
    },
    recalculate () { return this.load(true) },
    openWorkOrder (wo) { if (this.$can('production.work_order.view')) this.$router.push(`/production/work-orders/${wo.id}`) },
    lineTags (row) {
      const tags = []
      if (this.isService(row)) tags.push({ text: '服务', cls: 'c-n' })
      else if (this.isNoDelivery(row)) tags.push({ text: '无需发货', cls: 'c-n' })
      else tags.push(row.is_purchase_fulfilled ? { text: '外购', cls: 'c-p' } : { text: '自制', cls: 'c-b' })
      if (row.commercial_role === 'gift') tags.push({ text: '赠品', cls: 'c-o' })
      if (row.delivery_inspection_required) tags.push({ text: '交付前检验', cls: 'c-o' })
      return tags
    },
    lineResult (row) {
      if (this.isService(row)) return row.service_completed !== false ? '→ 记为服务已完成' : '→ 服务待完成'
      if (this.isNoDelivery(row)) return '→ 无需发货'
      const parts = []
      if (row.production_qty > 0) parts.push(row.routing_summary && row.bom_summary ? '生成工单 1 张，自动下达' : '生成工单 1 张，资料不全停在计划')
      if (row.inventory_qty > 0) parts.push(`锁库存 ${this.number(row.inventory_qty)}`)
      if (row.purchase_qty > 0) parts.push(`外购 ${this.number(row.purchase_qty)}，到货后自动锁`)
      if (row.commercial_role === 'gift') parts.push('成本记销售费用')
      if (this.remainingUndetermined(row) > 0) parts.push(`待确认 ${this.number(this.remainingUndetermined(row))}`)
      return parts.length ? '→ ' + parts.join(' · ') : '→ 本次不确认'
    },
    money (value) { return '¥' + Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 2 }) },
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
          await this.$confirm(this.summary.production ? `提交后自动生成 ${this.summary.production} 张工单并下达，确认提交？` : '确认保存本订单的备货方案？', '提交订单生产确认', { type: 'warning' })
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

<style scoped src="../production/mfg-v2.css"></style>
<style scoped>
.top-alert { margin-bottom: 12px; }
.hero-ok { color: #4ade80 !important; }
.hero-warn { color: #ffb020 !important; }
.line { display: grid; grid-template-columns: minmax(150px, 1fr) minmax(220px, 1.6fr) minmax(230px, 1.3fr); gap: 14px; border: 1px solid #e3e8f0; border-radius: 10px; padding: 14px; margin-bottom: 10px; }
.line.svc { background: #fafbfd; }
.line.bad { border-color: #f6c3be; background: #fff8f7; }
.l-name b { font-size: 14px; }
.tags { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 6px; }
.l-route { display: grid; gap: 6px; align-content: start; }
.lbl { color: #8a95a8; font-size: 12px; width: 30px; }
.boxes { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); gap: 6px; }
.box { background: #f3f5f9; border-radius: 8px; padding: 6px; text-align: center; font-size: 12px; color: #5b6780; display: grid; gap: 4px; }
.box b { color: #172033; font-size: 14px; }
.box .el-input-number { width: 100%; }
.box ::v-deep .el-input__inner { text-align: center; font-weight: 600; }
.avail { margin-top: 4px; }
.result { margin-top: 6px; font-size: 12px; }
.small { font-size: 12px; margin-top: 4px; }
.svc-check { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; min-height: 40px; }
.reason { display: grid; gap: 6px; margin: 4px 0 10px; }
.reason em { color: #d92d20; font-style: normal; }
.sum { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f0f2f6; }
.sum:last-child { border-bottom: 0; }
.sum b { font-size: 20px; }
.sum small { font-size: 12px; color: #8a95a8; font-weight: 400; }
.bullets { margin: 0; padding-left: 16px; color: #5b6780; font-size: 12px; line-height: 2; }
.wo-row { display: grid; grid-template-columns: auto 1fr auto; gap: 8px; align-items: center; padding: 6px 0; font-size: 12px; }
.att { padding: 6px 0; }
@media (max-width: 1100px) {
  .line { grid-template-columns: 1fr 1fr; }
  .l-qty { grid-column: 1 / -1; }
}
@media (max-width: 767px) {
  .line { grid-template-columns: 1fr; }
}
</style>
