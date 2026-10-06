<template>
  <section class="plan-detail-page" v-if="plan">
    <!-- 顶部导航与状态栏 -->
    <div class="page-head">
      <div class="head-left">
        <el-button size="small" icon="el-icon-arrow-left" circle @click="$router.push('/purchase/plans')" />
        <div class="head-text">
          <div class="title-row">
            <h1>{{ plan.plan_no }}</h1>
            <span class="page-type-tag">采购计划详情</span>
            <el-tag size="mini" :type="tagType(plan.plan_status)">{{ labelOf(plan.plan_status) }}</el-tag>
            <el-tag size="mini" :type="tagType(plan.audit_status)">{{ labelOf(plan.audit_status, 'audit_status') }}</el-tag>
            <el-tag size="mini" :type="tagType(plan.order_status)">{{ labelOf(plan.order_status) }}</el-tag>
          </div>
          <p class="head-sub">计划物料明细、供应商拆分分配及下游采购订单追溯链路全貌。</p>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-back" @click="$router.push('/purchase/plans')">返回列表</el-button>
        <el-button
          v-if="canEdit"
          size="small"
          icon="el-icon-edit"
          @click="$router.push(`/purchase/plans/${plan.id}/edit`)"
        >编辑计划</el-button>
        <el-button
          v-if="canSubmit"
          size="small"
          type="primary"
          icon="el-icon-upload2"
          :loading="submitLoading"
          @click="submitForAudit"
        >提交审核</el-button>
        <el-button
          v-if="canGenerate"
          size="small"
          type="success"
          icon="el-icon-download"
          :loading="generateLoading"
          @click="generate"
        >生成采购订单</el-button>
      </div>
    </div>

    <!-- 汇总 KPI 指标卡片 -->
    <div class="kpi-stat-grid">
      <div class="kpi-card">
        <div class="kpi-icon-wrap green-light"><i class="el-icon-goods" /></div>
        <div class="kpi-content">
          <span class="kpi-label">计划物料品种</span>
          <strong class="kpi-value">{{ (plan.items || []).length }} <small>种</small></strong>
          <span class="kpi-sub">共 {{ totalSplitsCount }} 处供应商拆分</span>
        </div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon-wrap blue-light"><i class="el-icon-shopping-cart-2" /></div>
        <div class="kpi-content">
          <span class="kpi-label">计划采购总量</span>
          <strong class="kpi-value text-green">{{ quantitySummary }}</strong>
          <span class="kpi-sub">折合库存: {{ baseQuantitySummary }}</span>
        </div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon-wrap amber-light"><i class="el-icon-money" /></div>
        <div class="kpi-content">
          <span class="kpi-label">预计采购总金额</span>
          <strong class="kpi-value text-money">¥{{ money(plan.total_amount) }}</strong>
          <span class="kpi-sub">含税参考预算</span>
        </div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon-wrap purple-light"><i class="el-icon-office-building" /></div>
        <div class="kpi-content">
          <span class="kpi-label">涉及供应商</span>
          <strong class="kpi-value">{{ uniqueSuppliersCount }} <small>家</small></strong>
          <span class="kpi-sub">{{ plan.order_status === 'order_generated' ? '已全额生成订单' : plan.order_status === 'partially_ordered' ? '部分已生成订单' : '待生成订单' }}</span>
        </div>
      </div>
    </div>

    <!-- 基础信息卡片 -->
    <section class="form-card basic-info-card">
      <div class="card-head-title">
        <div class="title-left">
          <span class="bar-accent"></span>
          <h3>基础信息</h3>
        </div>
      </div>
      <div class="spec-grid">
        <div class="spec-item">
          <span class="spec-label">计划单号</span>
          <span class="code-badge">{{ plan.plan_no }}</span>
        </div>
        <div class="spec-item">
          <span class="spec-label">计划日期</span>
          <span class="spec-value">{{ plan.plan_date || '--' }}</span>
        </div>
        <div class="spec-item">
          <span class="spec-label">单据状态</span>
          <span class="spec-value"><el-tag size="mini" :type="tagType(plan.plan_status)">{{ labelOf(plan.plan_status) }}</el-tag></span>
        </div>
        <div class="spec-item">
          <span class="spec-label">审核状态</span>
          <span class="spec-value"><el-tag size="mini" :type="tagType(plan.audit_status)">{{ labelOf(plan.audit_status, 'audit_status') }}</el-tag></span>
        </div>
        <div class="spec-item">
          <span class="spec-label">订单生成状态</span>
          <span class="spec-value"><el-tag size="mini" :type="tagType(plan.order_status)">{{ labelOf(plan.order_status) }}</el-tag></span>
        </div>
        <div class="spec-item">
          <span class="spec-label">预计总金额</span>
          <strong class="spec-value grand-total">¥{{ money(plan.total_amount) }}</strong>
        </div>
        <div class="spec-item">
          <span class="spec-label">单据来源</span>
          <span class="spec-value">{{ sourceText(plan.source_type || plan.data_source) }}</span>
        </div>
        <div class="spec-item" v-if="plan.source_no">
          <span class="spec-label">来源单号</span>
          <span class="spec-value">{{ plan.source_no }}</span>
        </div>
        <div class="spec-item spec-full">
          <span class="spec-label">备注说明</span>
          <span class="spec-value text-muted">{{ plan.remark || '无备注' }}</span>
        </div>
      </div>
    </section>

    <!-- 计划物料与供应商拆分全貌表格 -->
    <section class="form-card items-card">
      <div class="card-head-title">
        <div class="title-left">
          <span class="bar-accent"></span>
          <h3>计划物料明细及供应商拆分</h3>
          <el-tag size="mini" type="success">共 {{ (plan.items || []).length }} 种物料</el-tag>
        </div>
        <div class="table-tools">
          <el-button size="mini" plain icon="el-icon-d-caret" @click="toggleExpandAll">
            {{ isAllExpanded ? '折叠拆分明细' : '展开拆分明细' }}
          </el-button>
        </div>
      </div>

      <el-table
        ref="planItemTable"
        :data="plan.items || []"
        size="small"
        border
        stripe
        row-key="id"
        class="plan-table"
      >
        <!-- 展开列：展示供应商拆分明细 -->
        <el-table-column type="expand" width="48">
          <template slot-scope="{row}">
            <div class="expand-split-wrapper">
              <div class="expand-split-header">
                <div class="split-info-left">
                  <i class="el-icon-s-operation" />
                  <strong>【{{ row.item ? row.item.item_code : '--' }} · {{ row.item ? row.item.item_name : '--' }}】供应商拆分与报价</strong>
                  <span class="split-spec-text">规格型号：{{ lineSpec(row) }}</span>
                </div>
                <div class="split-info-balance">
                  <span>计划折合库存：<strong>{{ number(allocationState(row).target) }} {{ lineStockUnit(row) }}</strong></span>
                  <span class="dot-divider">/</span>
                  <span>已分配：<strong class="text-green">{{ number(allocationState(row).allocated) }} {{ lineStockUnit(row) }}</strong></span>
                  <span class="dot-divider">/</span>
                  <span>{{ allocationState(row).delta < 0 ? '超出计划' : '剩余未分配' }}：<strong :class="allocationState(row).delta !== 0 ? 'text-danger' : 'text-muted'">{{ number(Math.abs(allocationState(row).delta)) }} {{ lineStockUnit(row) }}</strong></span>
                  <el-tag size="mini" :type="allocationTag(row)" style="margin-left: 8px;">
                    {{ allocationLabel(row) }}
                  </el-tag>
                </div>
              </div>

              <el-table :data="row.splits || []" size="mini" border stripe class="nested-split-table" empty-text="尚未配置供应商拆分">
                <el-table-column type="index" label="#" width="40" align="center" />
                <el-table-column label="供应商" min-width="180" show-overflow-tooltip>
                  <template slot-scope="scope">
                    <strong>{{ scope.row.supplier ? scope.row.supplier.supplier_name : '-' }}</strong>
                    <span v-if="scope.row.supplier?.supplier_code" class="supplier-code-badge">{{ scope.row.supplier.supplier_code }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="采购数量" width="110" align="right">
                  <template slot-scope="scope">
                    <strong class="highlight-qty">{{ number(scope.row.purchase_conversion_snapshot?.purchase_qty ?? scope.row.purchase_qty) }}</strong> {{ scope.row.purchase_conversion_snapshot?.purchase_unit_name_snapshot || lineUnit(row) }}
                  </template>
                </el-table-column>
                <el-table-column label="采购单价" width="115" align="right">
                  <template slot-scope="scope">
                    ¥{{ money(scope.row.purchase_conversion_snapshot?.purchase_unit_price ?? scope.row.unit_price) }}
                  </template>
                </el-table-column>
                <el-table-column prop="tax_rate" label="税率" width="70" align="center">
                  <template slot-scope="scope">{{ number(scope.row.tax_rate) }}%</template>
                </el-table-column>
                <el-table-column label="预计金额" width="115" align="right">
                  <template slot-scope="scope">
                    <strong class="text-money">¥{{ money(scope.row.amount) }}</strong>
                  </template>
                </el-table-column>
                <el-table-column prop="expected_date" label="预计到货" width="105" align="center">
                  <template slot-scope="scope">{{ scope.row.expected_date || '--' }}</template>
                </el-table-column>
                <el-table-column label="关联采购订单" min-width="160">
                  <template slot-scope="scope">
                    <el-button
                      v-if="scope.row.order"
                      type="text"
                      size="mini"
                      icon="el-icon-document"
                      class="order-link-btn"
                      @click="$router.push(`/purchase/orders/${scope.row.order.id}/detail`)"
                    >{{ scope.row.order.purchase_order_no }}</el-button>
                    <el-tag v-else size="mini" type="info">未生成订单</el-tag>
                  </template>
                </el-table-column>
                <el-table-column label="拆分状态" width="95" align="center">
                  <template slot-scope="scope">
                    <el-tag size="mini" :type="scope.row.split_status === 'ordered' ? 'success' : 'info'">
                      {{ labelOf(scope.row.split_status) }}
                    </el-tag>
                  </template>
                </el-table-column>
              </el-table>
            </div>
          </template>
        </el-table-column>

        <el-table-column type="index" label="#" width="45" align="center" />
        <el-table-column prop="item.item_code" label="物料编码" width="125">
          <template slot-scope="{row}">
            <span class="code-badge">{{ row.item ? row.item.item_code : (row.item_code || '--') }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="item.item_name" label="物料名称" min-width="150" show-overflow-tooltip>
          <template slot-scope="{row}">
            <strong class="item-name-bold">{{ row.item ? row.item.item_name : (row.item_name || '--') }}</strong>
          </template>
        </el-table-column>
        <el-table-column label="规格型号" min-width="130" show-overflow-tooltip>
          <template slot-scope="{row}">
            <span class="spec-model-cell">{{ lineSpec(row) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="计划采购量" width="115" align="right">
          <template slot-scope="{row}">
            <strong class="highlight-qty">{{ row.purchase_conversion_snapshot?.purchase_qty ?? number(row.plan_qty || row.required_qty) }}</strong>
            <span class="unit-text">{{ row.purchase_conversion_snapshot?.purchase_unit_name_snapshot || lineUnit(row) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="折合库存" width="115" align="right">
          <template slot-scope="{row}">
            <span>{{ lineStockQty(row) }} {{ lineStockUnit(row) }}</span>
            <el-tooltip v-if="isLineConverted(row)" :content="lineConversionTip(row)" placement="top">
              <i class="el-icon-info" style="color: #008b4b; margin-left: 2px; cursor: pointer;" />
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column label="分配进度" width="135" align="center">
          <template slot-scope="{row}">
            <div class="alloc-progress-cell">
              <el-tag size="mini" :type="hasAllocatedSupplier(row) ? allocationTag(row) : 'danger'" effect="plain">
                <i :class="hasAllocatedSupplier(row) ? '' : 'el-icon-warning'" />
                {{ hasAllocatedSupplier(row) ? allocationLabel(row) : '未分配供应商' }}
              </el-tag>
              <small class="alloc-count">{{ allocatedSuppliersCount(row) }} 家供应商</small>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="供应商拆分及报价" min-width="240">
          <template slot-scope="{row}">
            <div v-if="hasAllocatedSupplier(row)" class="split-badges-cell">
              <div v-for="split in validSplits(row)" :key="split.id" class="split-badge-item">
                <span class="supplier-name-text">{{ split.supplier ? split.supplier.supplier_name : '-' }}</span>
                <span class="split-detail-text">
                  {{ number(split.purchase_conversion_snapshot?.purchase_qty ?? split.purchase_qty) }} {{ split.purchase_conversion_snapshot?.purchase_unit_name_snapshot || lineUnit(row) }} ·
                  ¥{{ money(split.purchase_conversion_snapshot?.purchase_unit_price ?? split.unit_price) }}
                </span>
                <el-tag v-if="split.order" size="mini" type="success">{{ split.order.purchase_order_no }}</el-tag>
              </div>
            </div>
            <el-tag v-else size="mini" type="danger" effect="plain"><i class="el-icon-warning" /> 未分配供应商</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="来源需求单" width="145" show-overflow-tooltip>
          <template slot-scope="{row}">
            <span v-if="row.request" class="code-badge-subtle">{{ row.request.request_no }}</span>
            <span v-else class="text-muted">--</span>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="行备注" min-width="100" show-overflow-tooltip />
      </el-table>
    </section>

    <!-- 采购订单生成追踪 / 预览 -->
    <section class="form-card orders-section">
      <div class="card-head-title">
        <div class="title-left">
          <span class="bar-accent"></span>
          <h3>{{ generatedOrders.length ? '已生成采购订单' : '采购订单生成预览' }}</h3>
          <el-tag size="mini" :type="generatedOrders.length ? 'success' : 'info'">
            {{ generatedOrders.length ? `已生成 ${generatedOrders.length} 张订单` : '审核通过后可一键拆单生成' }}
          </el-tag>
        </div>
      </div>
      <div class="orders-grid">
        <div v-for="g in orderCards" :key="g.key" class="order-summary-card">
          <div class="order-card-top">
            <i class="el-icon-s-order order-icon" />
            <div class="order-header-info">
              <strong class="supplier-title">{{ g.supplier }}</strong>
              <span class="order-no-text">{{ g.orderNo || '待生成订单' }}</span>
            </div>
            <el-tag size="mini" :type="g.orderNo ? 'success' : 'info'">{{ g.orderNo ? '已生成' : '待生成' }}</el-tag>
          </div>
          <div class="order-card-metrics">
            <div class="order-metric-item">
              <span>采购明细</span>
              <strong>{{ g.lines }} 行</strong>
            </div>
            <div class="order-metric-item">
              <span>采购数量</span>
              <strong class="text-green">{{ g.qty }}</strong>
            </div>
            <div class="order-metric-item">
              <span>预计金额</span>
              <strong class="text-money">¥{{ money(g.amount) }}</strong>
            </div>
          </div>
        </div>
        <div v-if="!orderCards.length" class="empty-orders-hint">
          <i class="el-icon-info" />
          <span>暂无供应商拆分信息，无法生成订单预览。</span>
        </div>
      </div>
    </section>
  </section>
</template>

<script>
import { planAllocation, planAllocationLabel, planAllocationTag } from '@/utils/purchasePlanAllocation'
import { getPurchase, previewPlanOrders, generatePlanOrders, submitPlan } from '@/api/erp/purchase'

const statusLabelMap = {
  draft: '草稿',
  confirmed: '已确认',
  submitted: '已提交',
  approved: '已审核',
  rejected: '已驳回',
  processing: '处理中',
  partially_received: '部分到货',
  received: '已到货',
  closed: '已关闭',
  cancelled: '已取消',
  not_ordered: '未生成订单',
  partially_ordered: '部分生成订单',
  order_generated: '已生成订单',
  ordered: '已下单',
  pending: '待审核',
  posted: '已库存过账'
}

export default {
  name: 'PurchasePlanDetail',
  data: () => ({
    plan: null,
    preview: [],
    generateLoading: false,
    submitLoading: false,
    isAllExpanded: false
  }),
  computed: {
    canEdit() {
      return this.plan && (this.plan.plan_status === 'draft' || this.plan.audit_status === 'rejected') && this.$can(['purchase.plan.edit', 'purchase.plan'])
    },
    canSubmit() {
      return this.plan && (this.plan.plan_status === 'draft' || this.plan.audit_status === 'rejected') && this.$can(['purchase.plan.edit', 'purchase.plan'])
    },
    canGenerate() {
      return this.plan && this.plan.audit_status === 'approved' && ['not_ordered', 'partially_ordered'].includes(this.plan.order_status) && !['closed', 'cancelled'].includes(this.plan.plan_status) && this.$can(['purchase.order.generate', 'purchase.plan'])
    },
    generatedOrders() {
      return Array.from(new Map((this.plan?.items || []).flatMap(i => i.splits || []).filter(s => s.order).map(s => [s.order.id, s.order])).values())
    },
    totalSplitsCount() {
      return (this.plan?.items || []).reduce((sum, item) => sum + (item.splits || []).length, 0)
    },
    uniqueSuppliersCount() {
      const set = new Set((this.plan?.items || []).flatMap(i => (i.splits || []).map(s => s.supplier_id)).filter(Boolean))
      return set.size
    },
    quantitySummary() {
      const groups = new Map()
      ;(this.plan?.items || []).forEach(line => {
        const snap = line.purchase_conversion_snapshot
        const unit = snap?.purchase_unit_name_snapshot || this.lineUnit(line)
        const qty = Number(snap?.purchase_qty ?? line.plan_qty ?? line.required_qty ?? 0)
        groups.set(unit, (groups.get(unit) || 0) + qty)
      })
      return Array.from(groups, ([unit, value]) => `${this.number(value)} ${unit}`).join('；') || '0'
    },
    baseQuantitySummary() {
      const groups = new Map()
      ;(this.plan?.items || []).forEach(line => {
        const snap = line.purchase_conversion_snapshot
        const unit = snap?.base_unit_name_snapshot || this.lineUnit(line)
        const qty = Number(snap?.planned_base_qty ?? line.required_qty ?? line.plan_qty ?? 0)
        groups.set(unit, (groups.get(unit) || 0) + qty)
      })
      return Array.from(groups, ([unit, value]) => `${this.number(value)} ${unit}`).join('；') || '0'
    },
    orderCards() {
      if (this.generatedOrders.length) {
        const map = {}
        ;(this.plan?.items || []).flatMap(i => i.splits || []).filter(s => s.order).forEach(s => {
          const k = s.order.id
          map[k] = map[k] || {
            key: k,
            supplier: s.supplier ? s.supplier.supplier_name : '--',
            orderNo: s.order.purchase_order_no,
            lines: 0,
            quantities: [],
            amount: 0
          }
          map[k].lines++
          map[k].quantities.push({ qty: s.purchase_conversion_snapshot?.purchase_qty ?? s.purchase_qty, unit: s.purchase_conversion_snapshot?.purchase_unit_name_snapshot || this.lineUnit(s) })
          map[k].amount += Number(s.amount || 0)
        })
        return Object.values(map).map(card => ({ ...card, qty: this.orderQuantitySummary(card.quantities) }))
      }
      return this.preview.map(p => ({
        key: p.supplier_id,
        supplier: p.supplier_name,
        lines: p.line_count,
        qty: this.orderQuantitySummary((p.items || []).map(line => ({ qty: line.conversion_snapshot?.purchase_qty ?? line.purchase_qty, unit: line.conversion_snapshot?.purchase_unit_name_snapshot || '-' }))),
        amount: p.total_amount
      }))
    }
  },
  async mounted() {
    await this.load()
  },
  methods: {
    orderQuantitySummary(lines) {
      const groups = new Map()
      lines.forEach(line => groups.set(line.unit, Number(groups.get(line.unit) || 0) + Number(line.qty || 0)))
      return Array.from(groups, ([unit, qty]) => `${this.number(qty)} ${unit}`).join('；') || '0'
    },
    allocationState: planAllocation,
    allocationLabel(row) { return planAllocationLabel(planAllocation(row)) },
    allocationTag(row) { return planAllocationTag(planAllocation(row)) },
    async load() {
      try {
        const res = await getPurchase('plans', this.$route.params.id)
        this.plan = res.data
        const pre = await previewPlanOrders(this.plan.id).catch(() => ({ data: { data: [] } }))
        this.preview = pre.data?.data || []
      } catch (e) {
        this.$message.error(e?.userMessage || e?.response?.data?.message || '加载计划详情失败')
      }
    },
    async generate() {
      try {
        await this.$confirm('确定按供应商分组生成采购订单？', '生成采购订单确认', { type: 'warning' })
        this.generateLoading = true
        await generatePlanOrders(this.plan.id)
        this.$message.success('采购订单生成成功')
        await this.load()
      } catch (e) {
        if (e === 'cancel' || e === 'close') return
        this.$message.error(e?.userMessage || e?.response?.data?.message || '生成采购订单失败')
      } finally {
        this.generateLoading = false
      }
    },
    async submitForAudit() {
      try {
        await this.$confirm(`确认提交采购计划 ${this.plan.plan_no} 进行审核？`, '提交审核确认', { type: 'warning' })
        this.submitLoading = true
        await submitPlan(this.plan.id)
        this.$message.success('采购计划已提交审核')
        await this.load()
      } catch (e) {
        if (e === 'cancel' || e === 'close') return
        this.$message.error(e?.userMessage || e?.response?.data?.message || '提交审核失败')
      } finally {
        this.submitLoading = false
      }
    },
    toggleExpandAll() {
      this.isAllExpanded = !this.isAllExpanded
      const table = this.$refs.planItemTable
      if (!table) return
      ;(this.plan?.items || []).forEach(row => {
        table.toggleRowExpansion(row, this.isAllExpanded)
      })
    },
    lineSpec(row) {
      return row.spec_model || (row.item ? (row.item.spec || row.item.spec_model || row.item.model) : '') || '--'
    },
    lineUnit(line) {
      const unit = line.unit || (line.item && line.item.unit) || null
      const canonical = unit && (unit.standard_unit || unit.standardUnit || unit)
      return (canonical && (canonical.symbol || canonical.unit_name || canonical.unit_code)) || '-'
    },
    hasAllocatedSupplier(row) {
      return (row?.splits || []).some(s => Boolean(s.supplier_id || s.supplier))
    },
    allocatedSuppliersCount(row) {
      const ids = new Set((row?.splits || []).filter(s => Boolean(s.supplier_id || s.supplier)).map(s => s.supplier_id || s.supplier?.id))
      return ids.size
    },
    validSplits(row) {
      return (row?.splits || []).filter(s => Boolean(s.supplier_id || s.supplier))
    },
    lineStockQty(row) {
      const qty = row.purchase_conversion_snapshot?.planned_base_qty ?? row.required_qty ?? row.plan_qty ?? 0
      return Number(qty).toLocaleString('zh-CN', { maximumFractionDigits: 4 })
    },
    lineStockUnit(row) {
      return row.purchase_conversion_snapshot?.base_unit_name_snapshot || this.lineUnit(row)
    },
    isLineConverted(row) {
      const snap = row.purchase_conversion_snapshot
      if (!snap) return false
      const factor = Number(snap.conversion_factor_snapshot ?? snap.conversion_factor ?? 1)
      const pUnit = snap.purchase_unit_id
      const bUnit = snap.base_unit_id
      return factor !== 1 || (pUnit && bUnit && Number(pUnit) !== Number(bUnit))
    },
    lineConversionTip(row) {
      const snap = row.purchase_conversion_snapshot
      if (!snap) return ''
      return `换算规则：1 ${snap.purchase_unit_name_snapshot} = ${this.number(snap.conversion_factor_snapshot)} ${snap.base_unit_name_snapshot}`
    },
    sourceText(v) {
      return ({ manual: '手工创建', purchase_request: '采购需求转入', system: '系统生成' })[v] || v || '手工创建'
    },
    money(v) {
      return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    number(v) {
      return Number(v || 0).toFixed(6).replace(/0+$/, '').replace(/\.$/, '')
    },
    labelOf(v, prop = '') {
      if (prop === 'audit_status' && v === 'pending') return '待审核'
      return statusLabelMap[v] || v || '--'
    },
    tagType(v) {
      return ['approved', 'received', 'confirmed', 'order_generated', 'ordered'].includes(v)
        ? 'success'
        : ['cancelled', 'rejected'].includes(v)
          ? 'danger'
          : ['submitted', 'pending', 'partially_ordered', 'processing'].includes(v)
            ? 'warning'
            : 'info'
    }
  }
}
</script>

<style scoped>
.plan-detail-page {
  box-sizing: border-box;
  min-width: 0;
  min-height: calc(100vh - 54px);
  background: #f8fafc;
  padding: 16px 20px;
}

.page-head {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 14px;
}

.head-text {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.title-row h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  letter-spacing: -0.02em;
}

.page-type-tag {
  font-size: 13px;
  color: #64748b;
  font-weight: 500;
}

.head-sub {
  margin: 0;
  font-size: 12px;
  color: #64748b;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* KPI 卡片 */
.kpi-stat-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 14px;
}

.kpi-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  min-width: 0;
}

.kpi-icon-wrap {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}

.kpi-icon-wrap.green-light { background: #f0fdf4; color: #008b4b; }
.kpi-icon-wrap.blue-light { background: #eff6ff; color: #2563eb; }
.kpi-icon-wrap.amber-light { background: #fffbeb; color: #d97706; }
.kpi-icon-wrap.purple-light { background: #faf5ff; color: #7c3aed; }

.kpi-content {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.kpi-label {
  font-size: 12px;
  color: #64748b;
}

.kpi-value {
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.kpi-value small {
  font-size: 12px;
  font-weight: normal;
  color: #64748b;
}

.kpi-value.text-green { color: #008b4b; }
.kpi-value.text-money { color: #008b4b; }

.kpi-sub {
  font-size: 11px;
  color: #94a3b8;
}

/* 表单卡片通用 */
.form-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}

.card-head-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
  padding-bottom: 8px;
  border-bottom: 1px solid #f1f5f9;
}

.title-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.bar-accent {
  width: 3px;
  height: 14px;
  background: #008b4b;
  border-radius: 2px;
}

.card-head-title h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: #1e293b;
}

/* 基础信息 Grid */
.spec-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px 20px;
}

.spec-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.spec-label {
  font-size: 12px;
  color: #64748b;
}

.spec-value {
  font-size: 13px;
  color: #1e293b;
  font-weight: 500;
}

.spec-item.spec-full {
  grid-column: 1 / -1;
}

.grand-total {
  font-size: 16px;
  color: #008b4b;
  font-weight: 700;
}

.code-badge {
  display: inline-block;
  background: #f1f5f9;
  color: #0f172a;
  padding: 2px 7px;
  border-radius: 4px;
  font-family: 'SFMono-Regular', Consolas, Menlo, monospace;
  font-size: 12px;
  font-weight: 600;
  border: 1px solid #e2e8f0;
}

.code-badge-subtle {
  display: inline-block;
  background: #f8fafc;
  color: #475569;
  padding: 1px 6px;
  border-radius: 3px;
  font-family: 'SFMono-Regular', Consolas, Menlo, monospace;
  font-size: 11px;
  border: 1px solid #e2e8f0;
}

.supplier-code-badge {
  display: inline-block;
  margin-left: 6px;
  background: #f8fafc;
  color: #64748b;
  padding: 0 4px;
  border-radius: 2px;
  font-size: 10px;
  border: 1px solid #e2e8f0;
}

.item-name-bold {
  font-weight: 600;
  color: #1e293b;
}

.spec-model-cell {
  color: #334155;
  font-size: 12px;
}

.highlight-qty {
  font-size: 13px;
  color: #008b4b;
  font-weight: 700;
}

.unit-text {
  font-size: 11px;
  color: #64748b;
  margin-left: 2px;
}

.text-green { color: #008b4b; }
.text-money { color: #008b4b; font-weight: 600; }
.text-danger { color: #dc2626; }
.text-muted { color: #94a3b8; }

/* 展开行拆分样式 */
.expand-split-wrapper {
  background: #f8fafc;
  padding: 14px 16px;
  border-radius: 6px;
  border: 1px solid #e2e8f0;
  margin: 6px 0;
}

.expand-split-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}

.split-info-left {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #008b4b;
}

.split-spec-text {
  font-size: 12px;
  color: #475569;
  background: #fff;
  padding: 1px 8px;
  border-radius: 4px;
  border: 1px solid #e2e8f0;
}

.split-info-balance {
  font-size: 12px;
  color: #475569;
  display: flex;
  align-items: center;
  gap: 4px;
}

.dot-divider {
  color: #cbd5e1;
  margin: 0 2px;
}

.nested-split-table {
  background: #fff;
}

.order-link-btn {
  color: #008b4b !important;
  font-weight: 600;
}

/* 主表格里的拆分徽章 */
.split-badges-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.split-badge-item {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 2px 7px;
  font-size: 11px;
}

.supplier-name-text {
  font-weight: 600;
  color: #1e293b;
}

.split-detail-text {
  color: #008b4b;
  font-weight: 500;
  background: #f0fdf4;
  padding: 1px 5px;
  border-radius: 2px;
}

.alloc-progress-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}

.alloc-count {
  font-size: 10px;
  color: #94a3b8;
}

/* 订单卡片 */
.orders-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 12px;
}

.order-summary-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  transition: all 0.2s ease;
}

.order-summary-card:hover {
  border-color: #bbf7d0;
  box-shadow: 0 2px 6px rgba(0, 139, 75, 0.08);
}

.order-card-top {
  display: flex;
  align-items: center;
  gap: 10px;
}

.order-icon {
  font-size: 22px;
  color: #008b4b;
}

.order-header-info {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}

.supplier-title {
  font-size: 13px;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.order-no-text {
  font-size: 11px;
  color: #64748b;
  font-family: monospace;
}

.order-card-metrics {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  padding-top: 8px;
  border-top: 1px dashed #e2e8f0;
  text-align: center;
}

.order-metric-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.order-metric-item span {
  font-size: 11px;
  color: #64748b;
}

.order-metric-item strong {
  font-size: 13px;
  color: #0f172a;
}

.empty-orders-hint {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  gap: 8px;
  color: #94a3b8;
  font-size: 13px;
  padding: 16px 0;
}

@media (max-width: 1200px) {
  .kpi-stat-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .spec-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .kpi-stat-grid {
    grid-template-columns: 1fr;
  }
  .spec-grid {
    grid-template-columns: 1fr;
  }
}
</style>
