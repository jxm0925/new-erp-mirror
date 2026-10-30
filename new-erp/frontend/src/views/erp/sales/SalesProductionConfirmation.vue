<template>
  <div class="prod-confirm-page" v-loading="loading">
    <!-- 顶部统一标头 (.page-head) -->
    <header class="page-head" v-if="order">
      <div class="head-left">
        <el-button size="small" icon="el-icon-back" class="btn-back" @click="returnToOrder">
          返回销售订单
        </el-button>
        <span class="head-icon"><i class="el-icon-s-operation" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">订单生产确认</h1>
            <span class="mono-code order-no-tag">{{ order.sales_order_no }}</span>
            <span class="customer-tag">{{ order.customer_name || '未填写客户' }}</span>
            <el-tag size="small" :type="confirmed ? 'success' : 'warning'" effect="plain">
              {{ confirmed ? '已生产确认' : '待生产确认' }}
            </el-tag>
            <el-tag v-if="order.is_urgent" size="small" type="danger" effect="dark" class="urgent-tag">
              加急订单
            </el-tag>
          </div>
          <div class="sub-row">
            <span>订单日期：<b>{{ date(order.order_time) }}</b></span>
            <span>交付要求：<b>{{ date(order.required_delivery_date) }}</b></span>
            <span>销售人员：<b>{{ order.created_by || '-' }}</b></span>
            <span>付款条件：<b>{{ funding ? (funding.policy_name || '标准收款') : '-' }}</b></span>
            <span v-if="funding">
              开工款项：<b :class="funding.production_funds_satisfied ? 'text-success font-medium' : 'text-warning font-medium'">{{ productionFundingText }}</b>
              <el-button v-if="canEditDeposit" type="text" size="mini" :loading="depositSaving" @click="editAgreedDeposit">修改定金</el-button>
            </span>
            <span v-if="funding">
              发货款项：<b :class="funding.shipment_funds_satisfied ? 'text-success font-medium' : 'text-warning font-medium'">{{ shipmentFundingText }}</b>
            </span>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :disabled="!editable" :loading="loading" @click="recalculate">
          重新计算
        </el-button>
        <el-button
          v-if="!confirmed || pending"
          size="small"
          type="success"
          icon="el-icon-check"
          class="btn-theme-create"
          :disabled="!canSubmit"
          :loading="submitting"
          @click="submit"
        >
          {{ pending ? '重试原确认' : '提交生产确认' }}
        </el-button>
      </div>
    </header>

    <div class="page-body">
      <!-- 提示警告横条 -->
      <el-alert
        v-if="pending"
        class="custom-alert"
        type="warning"
        :closable="false"
        show-icon
        title="系统提示：上次确认结果尚未接收完成。请点击“重试原确认”，系统将按幂等性识别同一次操作；核实前暂不能修改分配数量。"
      />
      <el-alert
        v-if="funding && !funding.production_funds_satisfied && !confirmed"
        class="custom-alert"
        type="warning"
        :closable="false"
        show-icon
        :title="`财务前置提示：${funding.production_block_message || '定金尚未足额到账'}。允许先行确认备货并生成工单，但定金未达标前车间暂不可开工作业。`"
      />

      <!-- 双栏布局 -->
      <div v-if="order" class="layout-grid">
        <!-- 左侧：明细与备货策略 -->
        <div class="main-column">
          <div class="content-card">
            <div class="card-title-bar">
              <h2 class="section-title">
                销售明细备货策略配置
                <small class="text-muted">{{ editable ? '系统已根据实时可用库存智能推荐备货分配；手工调整数量须填写调整原因' : '已确认备货方案（只读模式）' }}</small>
              </h2>
            </div>

            <!-- 明细行列表 -->
            <div
              v-for="row in lines"
              :key="row.sales_order_line_id"
              :class="['line-box', { 'is-svc': isService(row) || isNoDelivery(row), 'is-blocked': lineBlocked(row) }]"
            >
              <!-- 行名称与标签 -->
              <div class="line-prod-col">
                <b class="prod-title">{{ row.product_name || row.item_name || '-' }}</b>
                <div class="text-muted prod-meta">
                  × {{ number(row.order_qty) }} {{ row.sales_unit || '' }}
                  <template v-if="row.sku_name"> · {{ row.sku_name }}</template>
                </div>
                <div class="prod-tags">
                  <span v-for="t in lineTags(row)" :key="t.text" :class="['chip', t.cls]">{{ t.text }}</span>
                </div>
              </div>

              <!-- 工艺与路线准备 -->
              <div class="line-route-col">
                <template v-if="isService(row)">
                  <span class="text-muted">虚拟服务项目，不占用车间产能与发货包装</span>
                </template>
                <template v-else-if="isNoDelivery(row)">
                  <span class="text-muted">无需物理发运，不产生制造作业</span>
                </template>
                <template v-else>
                  <div v-if="row.production_qty > 0 && row.routing_summary && row.routing_summary.production_operations.length" class="route-flow">
                    <span class="flow-label">制造工序</span>
                    <template v-for="(op, i) in row.routing_summary.production_operations">
                      <span v-if="i" :key="'a' + i" class="flow-arrow">›</span>
                      <span :key="'p' + i" class="chip chip-step">{{ op }}</span>
                    </template>
                  </div>
                  <div class="route-flow">
                    <span class="flow-label">发货包装</span>
                    <template v-if="row.routing_summary && row.routing_summary.shipment_operations.length">
                      <template v-for="(op, i) in row.routing_summary.shipment_operations">
                        <span v-if="i" :key="'s' + i" class="flow-arrow">›</span>
                        <span :key="'o' + i" class="chip chip-pack">{{ op }}</span>
                      </template>
                    </template>
                    <span v-else class="chip chip-muted">无独立发货包装</span>
                  </div>
                  <div class="route-flow readiness-flow">
                    <span v-if="row.production_qty > 0" :class="['chip', row.bom_summary ? 'chip-ready' : 'chip-missing']">
                      {{ row.bom_summary ? `BOM V${row.bom_summary.version} ✓` : '缺少 BOM' }}
                    </span>
                    <span v-if="row.routing_summary" class="chip chip-ready">
                      {{ row.routing_summary.routing_name }} V{{ row.routing_summary.version }} ✓
                    </span>
                    <template v-else>
                      <span class="chip chip-missing">未配置工艺路线</span>
                      <span v-if="$can('production.routing.create')" class="action-link-theme" @click="$router.push('/production/routings/new')">去配置路线</span>
                    </template>
                    <span v-if="row.data_readiness && row.data_readiness.drawing === 'ready'" class="chip chip-ready">图纸就绪 ✓</span>
                    <span v-if="row.data_readiness && row.data_readiness.drawing === 'missing'" class="chip chip-warning">缺少定制图纸</span>
                  </div>
                  <div v-if="!row.routing_summary" class="text-muted small route-tip">
                    {{ row.production_qty > 0 ? '缺少工艺路线的工单将保持计划状态，待路线补齐后由生产主管下达' : '无独立发货作业，交付时随主机打包发运' }}
                  </div>
                </template>
              </div>

              <!-- 备货数量分配 -->
              <div class="line-qty-col">
                <div v-if="isService(row)" class="svc-status-box">
                  <el-checkbox v-model="row.service_completed" :disabled="!editable">服务已完成</el-checkbox>
                  <span class="text-muted small">（默认勾选已履约）</span>
                </div>
                <template v-else>
                  <div class="qty-grid">
                    <label v-if="row.remaining_confirm_qty !== row.order_qty || !editable" class="qty-cell">
                      <span class="qty-cell-title">本次确认</span>
                      <el-input-number
                        v-if="editable"
                        v-model="row.confirm_qty"
                        size="mini"
                        :min="0"
                        :max="row.remaining_confirm_qty"
                        :precision="salesPrecision(row)"
                        :controls="false"
                        @change="changeConfirmQuantity(row)"
                      />
                      <b v-else class="qty-val">{{ number(row.confirm_qty) }}</b>
                    </label>
                    <template v-if="isPhysical(row)">
                      <label class="qty-cell">
                        <span class="qty-cell-title">库存调拨</span>
                        <el-input-number
                          v-if="editable"
                          v-model="row.inventory_qty"
                          size="mini"
                          :min="0"
                          :max="Math.min(Number(row.confirm_qty || 0), Number(row.available_sales_qty || 0))"
                          :precision="salesPrecision(row)"
                          :controls="false"
                        />
                        <b v-else class="qty-val">{{ number(row.inventory_qty) }}</b>
                      </label>
                      <label class="qty-cell">
                        <span class="qty-cell-title">工单生产</span>
                        <el-input-number
                          v-if="editable && !row.is_purchase_fulfilled"
                          v-model="row.production_qty"
                          size="mini"
                          :min="0"
                          :max="row.confirm_qty"
                          :precision="salesPrecision(row)"
                          :controls="false"
                        />
                        <b v-else class="qty-val">{{ row.is_purchase_fulfilled ? '—' : number(row.production_qty) }}</b>
                      </label>
                      <label class="qty-cell">
                        <span class="qty-cell-title">采购外购</span>
                        <el-input-number
                          v-if="editable && row.is_purchase_fulfilled"
                          v-model="row.purchase_qty"
                          size="mini"
                          :min="0"
                          :max="row.confirm_qty"
                          :precision="salesPrecision(row)"
                          :controls="false"
                        />
                        <b v-else class="qty-val">{{ row.is_purchase_fulfilled ? number(row.purchase_qty) : '—' }}</b>
                      </label>
                    </template>
                    <label v-else class="qty-cell">
                      <span class="qty-cell-title">无需交付</span>
                      <el-input-number
                        v-if="editable"
                        v-model="row.no_delivery_qty"
                        size="mini"
                        :min="0"
                        :max="row.confirm_qty"
                        :precision="salesPrecision(row)"
                        :controls="false"
                      />
                      <b v-else class="qty-val">{{ number(row.no_delivery_qty) }}</b>
                    </label>
                  </div>
                  <div v-if="isPhysical(row)" class="avail-stock-text">
                    当前可用库存：<b>{{ number(row.available_sales_qty) }}</b> {{ row.sales_unit || '' }}
                  </div>
                </template>
                <div :class="['result-badge', lineBlocked(row) ? 'text-danger font-medium' : 'text-success']">
                  {{ lineBlocked(row) ? blockingReason(row) : lineResult(row) }}
                </div>
                <div v-if="lineAdjusted(row)" class="text-warning small adjusted-tip">
                  已偏离系统推荐方案，提交须注明原因
                </div>
              </div>
            </div>

            <!-- 调整原因表单 -->
            <div v-if="editable && hasManualAdjustment" class="adjust-reason-box">
              <span class="font-medium text-main">业务调整原因 <em class="req-star">*</em></span>
              <el-input
                v-model.trim="adjustmentReason"
                type="textarea"
                :rows="2"
                maxlength="500"
                show-word-limit
                placeholder="例如：客户指定全新批次生产，不使用现有在库库存"
              />
            </div>
            <div class="note-box">
              说明：若手动调整系统推荐备货数量，请务必规范填写业务调整原因。
            </div>
          </div>
        </div>

        <!-- 右侧：决策汇总与流转规则 -->
        <div class="side-column">
          <!-- 卡片 1：备货决策联动汇总 -->
          <div class="content-card">
            <div class="card-title-bar">
              <h2 class="section-title">{{ confirmed ? '生产备货确认结果' : '备货决策联动汇总' }}</h2>
            </div>
            <div class="summary-item">
              <span class="text-secondary">生成工单并自动下达</span>
              <b class="sum-number text-primary">{{ confirmed ? workOrders.length : summary.production }}</b>
            </div>
            <div class="summary-item">
              <span class="text-secondary">锁定在库可用库存</span>
              <b class="sum-number">{{ summary.inventory }} <small class="text-muted">行</small></b>
            </div>
            <div class="summary-item">
              <span class="text-secondary">外购缺口转采购</span>
              <b class="sum-number text-warning">{{ summary.purchase }}</b>
            </div>
            <div class="summary-item">
              <span class="text-secondary">服务履约项</span>
              <b class="sum-number text-success">{{ serviceDone }}</b>
            </div>
          </div>

          <!-- 卡片 2：已生成的生产工单 -->
          <div v-if="workOrders.length" class="content-card">
            <div class="card-title-bar">
              <h2 class="section-title">已生成的生产工单</h2>
            </div>
            <div v-for="wo in workOrders" :key="wo.id" class="wo-item">
              <span class="mono-code action-link-theme font-medium" @click="openWorkOrder(wo)">{{ wo.work_order_no }}</span>
              <span class="text-secondary">{{ wo.item_name }} × {{ number(wo.planned_qty) }}</span>
              <el-tag size="mini" type="success" effect="plain">{{ wo.status_label }}</el-tag>
            </div>
          </div>

          <!-- 卡片 3：生产流转联动规则 -->
          <div class="content-card">
            <div class="card-title-bar">
              <h2 class="section-title">生产流转联动规则</h2>
            </div>
            <ul class="rule-bullets">
              <li>生产工单确认后自动下达并生成唯一产品序列号（SN）</li>
              <li>仓储部门依据工艺工序齐套发料，车间班组核实签收</li>
              <li>生产主管可在工单管理中调整负责人、完工交期与优先级</li>
              <li>款项前置控制：定金未足额到账暂不开工，尾款未清结暂不发运</li>
            </ul>
          </div>

          <!-- 卡片 4：前置校验与风险提醒 -->
          <div v-if="attention.length" class="content-card">
            <div class="card-title-bar">
              <h2 class="section-title">前置校验与风险提醒 <small class="text-warning">{{ attention.length }} 项</small></h2>
            </div>
            <div v-for="(a, i) in attention" :key="i" class="attention-item">
              <b :class="a.block ? 'text-danger' : 'text-warning'"><i class="el-icon-warning" /> {{ a.title }}</b>
              <div class="text-muted small" style="margin-top: 2px;">{{ a.text }}</div>
            </div>
          </div>
        </div>
      </div>

      <el-empty v-if="!order && !loading" description="未找到订单生产确认数据" />
    </div>
  </div>
</template>

<script>
import { erpDateTime } from '../../../utils/dateTime'
import cachedPageRoute from '@/utils/cachedPageRoute'
import { confirmProduction, getProductionPreview } from '@/api/erp/sales'
import { promptAgreedDeposit } from '@/utils/salesAgreedDeposit'
import { confirmationAllocationSum, currentConfirmationSuggestion, confirmationIsAdjusted, buildSalesProductionConfirmation } from '../../../utils/sales-production-confirmation.mjs'

export default {
  mixins: [cachedPageRoute],
  name: 'SalesProductionConfirmation',
  data: () => ({
    depositSaving: false,
    loading: false,
    submitting: false,
    preview: {},
    order: null,
    lines: [],
    adjustmentReason: '',
    pending: null,
    storageKey: '',
    loadSequence: 0
  }),
  computed: {
    editable () {
      return !this.pending && this.$can('sales_order.production_confirm') && this.order && this.order.order_status === 'confirmed' && ['pending', 'blocked'].includes(this.order.production_confirm_status)
    },
    blocked () {
      return this.lines.some(row => this.lineBlocked(row))
    },
    canSubmit () {
      return this.$can('sales_order.production_confirm') && !this.loading && (!!this.pending || (this.editable && !this.blocked && this.lines.length > 0 && this.lines.some(row => Number(row.confirm_qty || 0) > 0) && this.lines.every(row => this.allocationValid(row)) && (!this.hasManualAdjustment || !!this.adjustmentReason)))
    },
    hasManualAdjustment () {
      return this.lines.some(row => this.lineAdjusted(row))
    },
    baseGroups () {
      return this.preview.base_unit_groups || []
    },
    summary () {
      return {
        total: this.lines.length,
        inventory: this.lines.filter(row => row.inventory_qty > 0).length,
        production: this.lines.filter(row => row.production_qty > 0).length,
        purchase: this.lines.filter(row => row.purchase_qty > 0).length,
        service: this.lines.filter(row => row.service_qty > 0).length,
        no_delivery: this.lines.filter(row => row.no_delivery_qty > 0).length
      }
    },
    confirmed () {
      return !!this.order && this.order.production_confirm_status === 'confirmed'
    },
    funding () {
      return this.preview.funding_status || null
    },
    workOrders () {
      return this.preview.work_orders || []
    },
    serviceDone () {
      return this.lines.filter(row => this.isService(row) && row.service_completed !== false && Number(row.service_qty || 0) > 0).length
    },
    productionFundingText () {
      const f = this.funding
      const got = f.net_received_amount !== undefined ? `已收 ${this.money(f.net_received_amount)} · ` : ''
      if (f.production_funds_satisfied) return `${got}满足开工条件`
      if (f.production_required_amount === undefined) return `${got}定金未到`
      const label = f.deposit_source === 'agreed' ? '约定定金' : '需付定金'
      const short = Number(f.net_received_amount || 0) > 0 && f.deposit_outstanding_amount !== undefined ? `，还差 ${this.money(f.deposit_outstanding_amount)}` : ''
      return `${got}${label} ${this.money(f.production_required_amount)}${short}`
    },
    shipmentFundingText () {
      const f = this.funding
      if (f.shipment_funds_satisfied) return '已结清满足发运'
      if (f.outstanding_amount === undefined) return '尾款未结清'
      // 未收金额包含尚未到账的定金时拆开显示，避免把全部未收款当成尾款。
      if (Number(f.deposit_outstanding_amount || 0) > 0 && f.balance_outstanding_amount !== undefined) {
        return `未收 ${this.money(f.outstanding_amount)}（定金 ${this.money(f.deposit_outstanding_amount)} + 尾款 ${this.money(f.balance_outstanding_amount)}）`
      }
      return `待付尾款 ${this.money(f.outstanding_amount)}`
    },
    canEditDeposit () {
      const f = this.funding
      return Boolean(f && f.agreed_deposit_supported && f.production_required_amount !== undefined && this.order
        && ['draft', 'confirmed'].includes(this.order.order_status)
        && (this.$can('sales_order.edit_draft') || this.$can('sales_order.change') || this.$can('sales_order.change.submit')))
    },
    attention () {
      const list = []
      this.lines.forEach(row => {
        if (this.lineBlocked(row)) list.push({ block: true, title: `第 ${row.line_no} 行：${this.blockingReason(row)}`, text: '请核准分配数量后再行提交' })
        if (!this.isPhysical(row)) return
        if (!row.routing_summary) list.push({ title: `${row.product_name || row.item_name}未配置工艺路线`, text: row.production_qty > 0 ? '工单将保持计划状态，待工艺路线维护完毕后由生产主管下达' : '仅作前置提醒，不影响当前备货确认' })
        if (row.production_qty > 0 && !row.bom_summary) list.push({ title: `${row.product_name || row.item_name}缺失有效 BOM`, text: '工单将保持计划状态，待标准BOM补充后由生产主管下达' })
      })
      return list
    }
  },
  created () { this.load() },
  watch: {
    'pageRoute.params.id' () { this.load() }
  },
  methods: {
    async editAgreedDeposit () {
      if (this.depositSaving || !this.order) return
      this.depositSaving = true
      try {
        await promptAgreedDeposit(this, this.order.id, this.funding)
        await this.load()
      } catch (error) {
        if (!['cancel', 'close'].includes(error)) this.$message.error(error.userMessage || '约定定金保存失败')
      } finally { this.depositSaving = false }
    },
    returnToOrder () {
      if (!this.order || !this.order.id) return this.$router.push('/sales/orders')
      return this.$router.push(`/sales/orders/${this.order.id}/detail`)
    },
    async load (notify = false) {
      const sequence = ++this.loadSequence
      const orderId = this.pageRoute.params.id
      if (Number(this.order?.id) !== Number(orderId)) {
        this.order = null; this.lines = []; this.preview = {}; this.pending = null; this.storageKey = ''
      }
      this.loading = true
      try {
        const { data } = await getProductionPreview(orderId)
        if (sequence !== this.loadSequence) return
        this.preview = data || {}; this.order = data.order || null
        this.lines = (data.lines || []).map(row => ({
          ...row,
          inventory_qty: Number(row.inventory_qty || 0),
          production_qty: Number(row.production_qty || 0),
          purchase_qty: Number(row.purchase_qty || 0),
          service_qty: Number(row.service_qty || 0),
          no_delivery_qty: Number(row.no_delivery_qty || 0),
          undetermined_qty: Number(row.undetermined_qty || 0),
          confirm_qty: Number(row.confirm_qty || 0),
          remaining_confirm_qty: Number(row.remaining_confirm_qty ?? row.confirm_qty ?? 0),
          available_sales_qty: Number(row.available_sales_qty || 0),
          system_suggested_inventory_qty: Number(row.system_suggested_inventory_qty || 0),
          system_suggested_production_qty: Number(row.system_suggested_production_qty || 0),
          system_suggested_purchase_qty: Number(row.system_suggested_purchase_qty || 0),
          service_completed: row.service_completed !== false
        }))
        let actor = 0
        try { actor = JSON.parse(localStorage.getItem('erp_user') || '{}').legacy_id || 0 } catch (_) { /* Unavailable local profile. */ }
        this.storageKey = `erp_pending_sales_production_${actor}_${orderId}`
        try { this.pending = JSON.parse(localStorage.getItem(this.storageKey) || 'null') } catch (_) { this.pending = null }
        this.adjustmentReason = ''
        if (notify) this.$message.success('已按当前真实可用库存重新计算备货方案')
      } catch (error) {
        if (sequence === this.loadSequence) this.$message.error(error.userMessage || '订单生产确认数据加载失败')
      } finally {
        if (sequence === this.loadSequence) this.loading = false
      }
    },
    recalculate () { return this.load(true) },
    openWorkOrder (wo) {
      if (this.$can('production.work_order.view')) this.$router.push(`/production/work-orders/${wo.id}`)
    },
    lineTags (row) {
      const tags = []
      if (this.isService(row)) tags.push({ text: '服务', cls: 'chip-muted' })
      else if (this.isNoDelivery(row)) tags.push({ text: '无需交付', cls: 'chip-muted' })
      else tags.push(row.is_purchase_fulfilled ? { text: '采购外购', cls: 'chip-purple' } : { text: '自主制造', cls: 'chip-blue' })
      if (row.commercial_role === 'gift') tags.push({ text: '赠品', cls: 'chip-warning' })
      if (row.delivery_inspection_required) tags.push({ text: '交付前检验', cls: 'chip-warning' })
      return tags
    },
    lineResult (row) {
      if (this.isService(row)) return row.service_completed !== false ? '→ 确认服务已履约完成' : '→ 服务待完成'
      if (this.isNoDelivery(row)) return '→ 无需物理发运交付'
      const parts = []
      if (row.production_qty > 0) {
        parts.push(row.routing_summary && row.bom_summary ? '安排生产工单 1 张（自动下达）' : '安排生产工单 1 张（资料待补齐）')
      }
      if (row.inventory_qty > 0) parts.push(`调拨库存 ${this.number(row.inventory_qty)}`)
      if (row.purchase_qty > 0) parts.push(`采购外购 ${this.number(row.purchase_qty)}（到货后自动锁定）`)
      if (row.commercial_role === 'gift') parts.push('成本计入销售费用')
      if (this.remainingUndetermined(row) > 0) parts.push(`待配置 ${this.number(this.remainingUndetermined(row))}`)
      return parts.length ? '→ ' + parts.join(' · ') : '→ 本次暂不确认'
    },
    money (value) { return '¥' + Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 2 }) },
    validateAll (notify) {
      const valid = this.lines.every(row => this.allocationValid(row)) && !this.blocked && (!this.hasManualAdjustment || !!this.adjustmentReason)
      if (notify) this.$message[valid ? 'success' : 'warning'](valid ? '检查通过，可以提交确认' : (this.hasManualAdjustment && !this.adjustmentReason ? '手工修改系统建议后必须填写调整原因' : '存在分配数量不一致或库存不足的订单行'))
      return valid
    },
    async submit () {
      if (this.submitting || this.loading || !this.$can('sales_order.production_confirm') || !this.order?.id
        || Number(this.pageRoute.params.id) !== Number(this.order.id)) return
      const orderId = this.order.id
      const storageKey = this.storageKey
      const contextSequence = this.loadSequence
      this.submitting = true
      try {
        let payload = this.pending
        if (!payload) {
          if (!this.validateAll(false)) return this.$message.warning('请先完成全部订单行的备货数量配置和资料检查')
          payload = buildSalesProductionConfirmation(this.order, this.lines, this.adjustmentReason,
            `sales-production-${orderId}-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`)
          await this.$confirm(
            this.summary.production ? `提交后将自动生成 ${this.summary.production} 张生产工单并下达车间，确认提交？` : '确认保存当前销售订单的备货分配方案？',
            '提交生产备货确认',
            { type: 'warning', confirmButtonText: '确认提交', cancelButtonText: '取消' }
          )
          if (contextSequence !== this.loadSequence || Number(this.pageRoute.params.id) !== Number(orderId)) return
          localStorage.setItem(storageKey, JSON.stringify(payload))
          this.pending = payload
        }
        await confirmProduction(orderId, payload)
        localStorage.removeItem(storageKey)
        if (Number(this.pageRoute.params.id) === Number(orderId)) {
          this.pending = null
          this.$message.success('订单生产确认已成功提交')
          await this.load()
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
        if (Number(this.pageRoute.params.id) === Number(orderId)) {
          this.$message.error(error.userMessage || error.message || '订单生产确认提交失败，请重试原确认')
        }
      } finally {
        this.submitting = false
      }
    },
    normalizeAllocation (row) { row.undetermined_qty = this.remainingUndetermined(row) },
    changeConfirmQuantity (row) {
      Object.assign(row, currentConfirmationSuggestion(row))
      this.normalizeAllocation(row)
    },
    allocationSum (row) { return row ? confirmationAllocationSum(row) : 0 },
    allocationValid (row) { return !!row && Math.abs(this.allocationSum(row) - Number(row.confirm_qty || 0)) < 0.00000001 },
    lineAdjusted (row) { return this.isPhysical(row) && confirmationIsAdjusted(row) },
    currentSuggestion (row) { return currentConfirmationSuggestion(row) },
    salesPrecision (row) { return Number(row.sales_unit_precision || 0) },
    remainingUndetermined (row) {
      return Math.max(0, Number(row.remaining_confirm_qty ?? row.remaining_sales_qty ?? 0) - Number(row.confirm_qty || 0))
    },
    lineBlocked (row) {
      return !this.allocationValid(row) || Number(row.inventory_qty || 0) > Number(row.available_sales_qty || 0) + 0.00000001
    },
    blockingReason (row) {
      if (!this.allocationValid(row)) return '各项分配数量之和与本次确认数量不一致'
      if (Number(row.inventory_qty || 0) > Number(row.available_sales_qty || 0) + 0.00000001) return '调拨库存数量超过当前真实可用库存'
      return '校验通过'
    },
    isService (row) { return row.line_type === 'service' },
    isNoDelivery (row) { return ['no_delivery', 'fee', 'auxiliary', 'deduction'].includes(row.line_type) },
    isPhysical (row) { return !this.isService(row) && !this.isNoDelivery(row) },
    number (value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 6 }) },
    date (value) { return value ? erpDateTime(value, 10) : '-' }
  }
}
</script>

<style scoped>
.prod-confirm-page {
  min-height: calc(100vh - 52px);
  padding: 16px 20px 30px;
  background: #f8fafc;
  color: #1e293b;
  box-sizing: border-box;
}

/* 顶部页面标头 (.page-head) */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}
.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.btn-back {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}
.btn-back:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
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
  gap: 4px;
}
.title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
}
.mono-code {
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
}
.order-no-tag {
  font-size: 13px;
  color: #008b4b;
  font-weight: 600;
}
.customer-tag {
  font-size: 13px;
  color: #334155;
  font-weight: 500;
}
.urgent-tag {
  margin-left: 2px;
}
.sub-row {
  display: flex;
  gap: 14px;
  font-size: 12px;
  color: #64748b;
  flex-wrap: wrap;
}
.sub-row b {
  color: #1e293b;
}
.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
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
  color: #ffffff !important;
  font-weight: 500;
}
.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 提示条 */
.custom-alert {
  margin-bottom: 14px;
}

/* 布局网格 */
.page-body {
  box-sizing: border-box;
}
.layout-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 16px;
  align-items: start;
}
.main-column, .side-column {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

/* 内容卡片 */
.content-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}
.card-title-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
  gap: 8px;
}
.section-title {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 8px;
  padding-left: 10px;
  border-left: 3px solid #008b4b;
}
.section-title small {
  font-size: 12px;
  color: #64748b;
  font-weight: 400;
}

/* 明细行卡片 */
.line-box {
  display: grid;
  grid-template-columns: minmax(160px, 1fr) minmax(220px, 1.5fr) minmax(230px, 1.3fr);
  gap: 14px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px;
  margin-bottom: 12px;
  background: #ffffff;
  transition: all 0.2s;
}
.line-box:hover {
  border-color: #cbd5e1;
}
.line-box.is-svc {
  background: #f8fafc;
}
.line-box.is-blocked {
  border-color: #fecaca;
  background: #fef2f2;
}

.line-prod-col .prod-title {
  font-size: 14px;
  color: #0f172a;
}
.prod-meta {
  font-size: 12px;
  margin-top: 3px;
}
.prod-tags {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
  margin-top: 6px;
}

/* 芯片标签样式 */
.chip {
  display: inline-block;
  font-size: 11px;
  padding: 1px 7px;
  border-radius: 4px;
  font-weight: 600;
  line-height: 18px;
}
.chip-muted   { background: #f1f5f9; color: #64748b; }
.chip-blue    { background: #eff6ff; color: #1d4ed8; }
.chip-purple  { background: #faf5ff; color: #7e22ce; }
.chip-warning { background: #fffbeb; color: #b45309; }
.chip-ready   { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.chip-missing { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.chip-step    { background: #eff6ff; color: #2563eb; }
.chip-pack    { background: #ecfdf5; color: #059669; }

/* 路线流程 */
.line-route-col {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.route-flow {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.flow-label {
  font-size: 11px;
  color: #64748b;
  width: 48px;
  flex-shrink: 0;
}
.flow-arrow {
  color: #94a3b8;
  font-size: 11px;
}
.readiness-flow {
  margin-top: 2px;
}
.route-tip {
  margin-top: 2px;
}

/* 数量分配单元格 */
.line-qty-col {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.qty-grid {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: minmax(0, 1fr);
  gap: 6px;
}
.qty-cell {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 6px;
  text-align: center;
  font-size: 11px;
  color: #64748b;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.qty-cell-title {
  white-space: nowrap;
}
.qty-val {
  color: #0f172a;
  font-size: 14px;
}
.qty-cell .el-input-number {
  width: 100%;
}
.qty-cell ::v-deep .el-input__inner {
  text-align: center;
  font-weight: 600;
  padding: 0 4px;
}
.avail-stock-text {
  font-size: 11px;
  color: #64748b;
  margin-top: 4px;
}
.avail-stock-text b {
  color: #008b4b;
}
.result-badge {
  font-size: 12px;
  margin-top: 4px;
}
.adjusted-tip {
  margin-top: 2px;
}
.svc-status-box {
  display: flex;
  align-items: center;
  gap: 6px;
  min-height: 40px;
}

/* 调整原因与说明 */
.adjust-reason-box {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 10px 0;
}
.req-star {
  color: #ef4444;
  font-style: normal;
}
.note-box {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  padding: 8px 12px;
  font-size: 12px;
  color: #00763f;
}

/* 侧边汇总 */
.summary-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 0;
  border-bottom: 1px solid #f1f5f9;
  font-size: 13px;
}
.summary-item:last-child {
  border-bottom: 0;
}
.sum-number {
  font-size: 18px;
}
.sum-number small {
  font-size: 12px;
  font-weight: 400;
}

/* 规则列表 */
.rule-bullets {
  margin: 0;
  padding-left: 18px;
  color: #475569;
  font-size: 12px;
  line-height: 2;
}

/* 工单列表 */
.wo-item {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 8px;
  align-items: center;
  padding: 7px 0;
  border-bottom: 1px solid #f1f5f9;
  font-size: 12px;
}

/* 风险提醒 */
.attention-item {
  padding: 6px 0;
  border-bottom: 1px solid #f1f5f9;
  font-size: 12px;
  line-height: 1.5;
}
.attention-item:last-child {
  border-bottom: 0;
}

/* 通用文字与链接颜色 */
.text-main { color: #0f172a !important; }
.text-secondary { color: #475569 !important; }
.text-muted { color: #64748b !important; }
.text-success { color: #008b4b !important; }
.text-warning { color: #f59e0b !important; }
.text-danger { color: #ef4444 !important; }
.font-medium { font-weight: 500 !important; }
.action-link-theme {
  color: #008b4b;
  cursor: pointer;
  text-decoration: none;
}
.action-link-theme:hover {
  color: #00763f;
  text-decoration: underline;
}

@media (max-width: 1100px) {
  .layout-grid { grid-template-columns: 1fr; }
  .line-box { grid-template-columns: 1fr 1fr; }
  .line-qty-col { grid-column: 1 / -1; }
}
@media (max-width: 767px) {
  .line-box { grid-template-columns: 1fr; }
  .page-head { padding: 12px 14px; }
  .title-row { flex-direction: column; align-items: flex-start; }
}
</style>
