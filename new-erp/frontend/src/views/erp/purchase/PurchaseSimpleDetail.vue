<template>
  <section class="simple-detail" v-if="doc">
    <!-- 统一标准页面顶部卡片 -->
    <div class="page-head">
      <div class="head-left">
        <el-button size="small" icon="el-icon-arrow-left" circle @click="$router.back()" />
        <div class="head-text">
          <div class="title-row">
            <el-tag size="mini" :type="doc.management_scope === 'office' ? 'info' : 'success'">{{ scopeLabel(doc.management_scope) }}</el-tag>
            <h1>{{ title }}</h1>
            <el-tag size="mini" type="success">{{ type === 'requests' ? '采购需求' : '采购订单' }}</el-tag>
            <el-tag size="mini" :type="tagType(mainStatus)">{{ statusText(mainStatus) }}</el-tag>
            <el-tag v-if="doc.audit_status" size="mini" :type="tagType(doc.audit_status)">{{ auditStatusText(doc.audit_status) }}</el-tag>
          </div>
          <p class="subtitle">查看单据基本信息、物料明细及业务执行状态</p>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-back" @click="$router.back()">返回列表</el-button>
        <el-button v-if="type === 'orders' && ($can('purchase.order.view') || $can('finance.view'))" size="small" icon="el-icon-date" @click="paymentPlanVisible = true">付款安排</el-button>
        <el-button
          v-if="canEdit"
          size="small"
          type="success"
          icon="el-icon-edit"
          @click="$router.push(`/purchase/${type}/${doc.id}/edit`)"
        >
          编辑单据
        </el-button>
      </div>
    </div>

    <el-alert v-if="scopeIssue" :title="scopeIssue" type="warning" :closable="false" show-icon />

    <!-- 基础信息卡片（全宽展示，不压缩表格横向宽度） -->
    <div class="detail-card info-card">
      <div class="card-head-title">
        <div class="title-text-group">
          <span class="bar-accent"></span>
          <h3>基本信息</h3>
        </div>
      </div>
      <el-descriptions :column="{ xl: 4, lg: 3, md: 2, sm: 1 }" border size="small" class="detail-descriptions">
        <el-descriptions-item label="单据编号">
          <span class="font-mono highlight-no">{{ no }}</span>
        </el-descriptions-item>
        <el-descriptions-item label="业务状态">
          <el-tag size="mini" :type="tagType(mainStatus)">{{ statusText(mainStatus) }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item v-if="doc.audit_status" label="审核状态">
          <el-tag size="mini" :type="tagType(doc.audit_status)">{{ auditStatusText(doc.audit_status) }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="业务日期">
          {{ doc.request_date || doc.required_date || doc.order_date || doc.receipt_date || '-' }}
        </el-descriptions-item>
        <el-descriptions-item label="申请人 / 经办人">
          {{ doc.requester || doc.creator?.name || doc.created_by_name || '-' }}
        </el-descriptions-item>
        <el-descriptions-item label="来源方式">
          {{ sourceText }}
        </el-descriptions-item>
        <el-descriptions-item v-if="doc.source_no" label="来源单号">
          {{ doc.source_no }}
        </el-descriptions-item>
        <el-descriptions-item label="物料品种数">
          {{ (doc.items || []).length }} 种
        </el-descriptions-item>
        <el-descriptions-item label="采购总数量">
          <strong class="highlight-green">{{ totalQuantitySummary }}</strong>
        </el-descriptions-item>
        <el-descriptions-item label="备注说明" :span="2">
          <span class="text-muted">{{ doc.remark || '无备注' }}</span>
        </el-descriptions-item>
      </el-descriptions>
    </div>

    <!-- 审核流进度（仅采购订单） -->
    <section v-if="type==='orders' && doc.approval_task" class="approval-card">
      <div class="card-head-title">
        <div class="title-text-group">
          <span class="bar-accent"></span>
          <h3>审核状态与进度</h3>
        </div>
        <el-button size="mini" type="text" class="link-btn" @click="$router.push(`/approvals/tasks/${doc.approval_task.id}`)">
          进入审核中心 <i class="el-icon-right" />
        </el-button>
      </div>
      <p class="approval-tip">采购订单内仅展示审核状态、结果和节点进度，审核通过或驳回操作请统一在审核中心完成。</p>
      <div class="steps-wrapper">
        <el-steps :active="approvalActive" finish-status="success" process-status="process" align-center>
          <el-step title="发起提交" />
          <el-step v-for="node in approvalNodes" :key="node.id" :title="node.node_name" :description="approvalNodeText(node)" />
          <el-step title="审核完成" :description="approvalResult" />
        </el-steps>
      </div>
    </section>

    <!-- 物料明细卡片（全宽展示，主要信息一目了然无需横向滚动） -->
    <div class="detail-card items-card">
      <div class="card-head-title">
        <div class="title-text-group">
          <span class="bar-accent"></span>
          <h3>物料明细 (共 {{ (doc.items || []).length }} 项)</h3>
        </div>
      </div>
      <div class="table-wrap">
        <!-- 需求明细表：全主要信息平铺，无须滚动即可看全 -->
        <el-table v-if="type==='requests'" :data="doc.items || []" size="small" border stripe class="responsive-detail-table">
          <el-table-column type="index" label="#" width="45" align="center" />
          <el-table-column label="物料编码" width="120">
            <template slot-scope="{row}">
              <span class="code-badge">{{ itemCode(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="物料名称" min-width="150" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span class="item-name-text">{{ itemName(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="规格型号" min-width="150" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span class="item-spec-text">{{ itemSpec(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="采购数量" width="110" align="right">
            <template slot-scope="{row}">
              <strong class="qty-num highlight-green">{{ qty(row) }}</strong> {{ purchaseUnitName(row) }}
            </template>
          </el-table-column>
          <el-table-column label="折合库存" width="125" align="right">
            <template slot-scope="{row}">
              <div class="stock-cell">
                <span class="stock-qty-text">{{ baseQty(row) }} {{ baseUnitName(row) }}</span>
                <el-tooltip v-if="hasConversion(row)" :content="conversionFormulaTip(row)" placement="top">
                  <i class="el-icon-info stock-info-icon" />
                </el-tooltip>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="期望交期" width="110" align="center">
            <template slot-scope="{row}">
              <span>{{ row.expected_date || '-' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="目标仓库" width="120">
            <template slot-scope="{row}">
              <span>{{ warehouseName(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="优先级" width="80" align="center">
            <template slot-scope="{row}">
              <el-tag size="mini" :type="priorityTagType(row)">{{ priorityText(row) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="明细状态" width="90" align="center">
            <template slot-scope="{row}">
              <el-tag size="mini" :type="tagType(row.line_status)">{{ statusText(row.line_status) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="行备注" min-width="120" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span class="text-muted">{{ row.remark || '-' }}</span>
            </template>
          </el-table-column>
        </el-table>

        <!-- 订单明细表 -->
        <el-table v-else :data="doc.items || []" size="small" border stripe class="responsive-detail-table">
          <el-table-column type="index" label="#" width="45" align="center" />
          <el-table-column label="物料编码" width="120">
            <template slot-scope="{row}">
              <span class="code-badge">{{ itemCode(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="物料名称" min-width="140" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span class="item-name-text">{{ itemName(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="规格型号" min-width="140" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span class="item-spec-text">{{ itemSpec(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="采购数量" width="100" align="right">
            <template slot-scope="{row}">
              <strong class="qty-num">{{ qty(row) }}</strong> {{ purchaseUnitName(row) }}
            </template>
          </el-table-column>
          <el-table-column label="单价" width="95" align="right">
            <template slot-scope="{row}">¥{{ Number(row.unit_price || 0).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column label="金额" width="105" align="right">
            <template slot-scope="{row}">¥{{ Number(row.amount || 0).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column label="到货数量" width="85" align="right">
            <template slot-scope="{row}">{{ row.received_qty || 0 }}</template>
          </el-table-column>
          <el-table-column label="剩余未到货" width="90" align="right">
            <template slot-scope="{row}">
              <span :class="{'danger-qty': remainingQty(row) > 0}">{{ remainingQty(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="预计到货" width="105" align="center">
            <template slot-scope="{row}">{{ row.expected_arrival_date || '-' }}</template>
          </el-table-column>
          <el-table-column label="目标仓库" width="110">
            <template slot-scope="{row}">{{ warehouseName(row) }}</template>
          </el-table-column>
          <el-table-column label="状态" width="85" align="center">
            <template slot-scope="{row}">
              <el-tag size="mini" :type="tagType(row.line_status)">{{ statusText(row.line_status) }}</el-tag>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </div>

    <!-- 追溯链路卡片（订单） -->
    <section v-if="type==='orders'" class="detail-card trace-section">
      <div class="card-head-title">
        <span class="bar-accent"></span>
        <h3>全链路追溯</h3>
      </div>
      <div class="trace-list">
        <div v-for="line in doc.items || []" :key="line.id" class="trace-card">
          <div class="trace-head">
            <i class="el-icon-link" />
            <b>{{ itemCode(line) }} - {{ itemName(line) }}</b>
          </div>
          <p class="trace-path">{{ traceChain(line) }}</p>
        </div>
      </div>
    </section>
    <purchase-payment-plan-dialog v-if="type === 'orders'" :visible.sync="paymentPlanVisible" :order-id="Number(doc.id)" />
  </section>
</template>

<script>
import { purchaseScopeLabel, purchaseScopeIssue } from '@/utils/purchaseManagementScope.mjs'
import { getPurchase } from '@/api/erp/purchase'

const statusMap = {
  draft: '草稿',
  confirmed: '已确认',
  submitted: '已提交',
  approved: '已审核',
  rejected: '已驳回',
  processing: '处理中',
  partially_received: '部分到货',
  partial: '部分到货',
  received: '已到货',
  closed: '已关闭',
  cancelled: '已取消',
  not_received: '未到货',
  not_ordered: '未生成订单',
  partially_ordered: '部分生成订单',
  order_generated: '已生成订单',
  pending: '待审核 / 待处理',
  posted: '已库存过账',
  open: '未完成',
  planned: '已转计划'
}

export default {
  name: 'PurchaseSimpleDetail',
  components: { PurchasePaymentPlanDialog: () => import('@/components/finance/PurchasePaymentPlanDialog.vue') },
  props: { type: { type: String, required: true } },
  data: () => ({ doc: null, paymentPlanVisible: false }),
  computed: {
    scopeIssue() { return purchaseScopeIssue(this.doc) },
    title() { return `${this.no} ${this.type === 'requests' ? '采购需求详情' : '采购订单详情'}` },
    no() { return this.doc?.request_no || this.doc?.purchase_order_no || '--' },
    mainStatus() { return this.doc?.request_status || this.doc?.purchase_status || this.doc?.confirm_status || this.doc?.receipt_status || '' },
    canEdit() {
      if (!this.doc) return false
      if (this.type === 'requests') return Boolean(this.doc.can_edit) && this.$can(['purchase.request.edit', 'purchase.request'])
      const status = this.mainStatus
      return status === 'draft' || status === 'rejected'
    },
    totalQuantitySummary() {
      const items = this.doc?.items || []
      const total = items.reduce((sum, line) => sum + Number(this.qty(line) || 0), 0)
      return total.toLocaleString('zh-CN', { maximumFractionDigits: 4 })
    },
    approvalNodes() { return this.doc?.approval_task?.nodes || [] },
    approvalActive() {
      const task = this.doc?.approval_task
      if (!task) return 0
      if (task.task_status === 'APPROVED') return this.approvalNodes.length + 2
      if (task.task_status === 'REJECTED') return Math.max(1, this.approvalNodes.findIndex(node => node.node_status === 'REJECTED') + 1)
      const pending = this.approvalNodes.findIndex(node => node.node_status === 'PENDING')
      return pending < 0 ? 1 : pending + 1
    },
    approvalResult() { return ({ PENDING: '审核中', APPROVED: '已通过', REJECTED: '已驳回', CANCELLED: '已取消' })[this.doc?.approval_task?.task_status] || '-' },
    sourceText() {
      if (this.type === 'orders' && !this.doc?.plan_id && !this.doc?.source_no) return '手工创建'
      return ({ purchase_plan: '采购计划', manual: '手工创建', production: '生产需求', sales: '销售订单', inventory_alert: '库存预警' })[this.doc?.source_type || this.doc?.data_source] || this.doc?.source_type || this.doc?.data_source || '手工创建'
    }
  },
  async mounted() {
    try {
      const res = await getPurchase(this.type, this.$route.params.id)
      this.doc = res.data
    } catch (e) {
      this.$message?.error?.('获取单据详情失败')
    }
  },
  methods: {
    scopeLabel(scope) { return purchaseScopeLabel(scope) },
    itemCode(row) { return row.item_code || (row.item && row.item.item_code) || '-' },
    itemName(row) { return row.item_name || (row.item && row.item.item_name) || '-' },
    itemSpec(row) {
      return row.spec_model || (row.item ? (row.item.spec || row.item.spec_model || row.item.model) : '') || '-'
    },
    qty(row) {
      return row.purchase_conversion_snapshot?.purchase_qty ?? row.purchase_quantity ?? row.request_qty ?? row.order_qty ?? row.receipt_qty ?? 0
    },
    purchaseUnitName(row) {
      return row.purchase_conversion_snapshot?.purchase_unit_name_snapshot || row.purchase_unit_name_snapshot || row.unit?.unit_name || row.item?.unit?.unit_name || '-'
    },
    baseQty(row) {
      const qty = row.purchase_conversion_snapshot?.planned_base_qty ?? row.request_qty ?? row.order_qty ?? 0
      return Number(qty).toLocaleString('zh-CN', { maximumFractionDigits: 4 })
    },
    baseUnitName(row) {
      return row.purchase_conversion_snapshot?.base_unit_name_snapshot || row.base_unit_name_snapshot || row.item?.unit?.unit_name || row.unit?.unit_name || '-'
    },
    hasConversion(row) {
      const snap = row.purchase_conversion_snapshot
      if (!snap) return false
      const factor = Number(snap.conversion_factor_snapshot ?? snap.conversion_factor ?? 1)
      const pUnit = snap.purchase_unit_id
      const bUnit = snap.base_unit_id
      return factor !== 1 || (pUnit && bUnit && Number(pUnit) !== Number(bUnit))
    },
    conversionFormulaTip(row) {
      const snap = row.purchase_conversion_snapshot
      if (!snap) return ''
      const pUnit = snap.purchase_unit_name_snapshot || this.purchaseUnitName(row)
      const bUnit = snap.base_unit_name_snapshot || this.baseUnitName(row)
      const factor = Number(snap.conversion_factor_snapshot || 1).toLocaleString('zh-CN', { maximumFractionDigits: 6 })
      return `换算规则：1 ${pUnit} = ${factor} ${bUnit}`
    },
    warehouseName(row) {
      return row.warehouse?.warehouse_name || '-'
    },
    priorityText(row) {
      return ({ high: '高', normal: '中', low: '低', urgent: '紧急' })[row.priority] || row.priority || '中'
    },
    priorityTagType(row) {
      return ({ high: 'danger', urgent: 'danger', normal: 'warning', low: 'info' })[row.priority] || 'info'
    },
    remainingQty(row) { return row.remaining_qty ?? Math.max(0, Number(row.order_qty || 0) - Number(row.received_qty || 0)) },
    requestTrace(row) {
      if (!row.request_id && !row.request_item_id) return '来源：手工创建'
      const no = row.request?.request_no || row.request_item?.request?.request_no || `需求ID ${row.request_id || '-'}`
      return `${no} / 明细 ${row.request_item_id || '-'}`
    },
    planTrace(row) {
      if (!row.plan_id && !row.plan_item_id) return '来源：手工创建'
      const no = row.plan?.plan_no || row.plan_item?.plan?.plan_no || this.doc?.plan?.plan_no || `计划ID ${row.plan_id || '-'}`
      return `${no} / 明细 ${row.plan_item_id || '-'}`
    },
    splitTrace(row) {
      const split = row.supplier_split || row.plan_split
      if (!row.supplier_split_id && !row.plan_split_id && !split) return '来源：手工创建'
      const supplier = split && split.supplier ? split.supplier.supplier_name : (this.doc?.supplier ? this.doc.supplier.supplier_name : '-')
      return `${supplier} / 拆分 ${row.supplier_split_id || row.plan_split_id || split?.id || '-'}`
    },
    traceChain(row) {
      if (!row.request_id && !row.plan_id && !row.supplier_split_id && !row.plan_split_id) return '来源：手工创建 -> 采购订单明细'
      return `${this.requestTrace(row)} -> ${this.planTrace(row)} -> ${this.splitTrace(row)} -> 采购订单明细 -> 到货 ${row.received_qty || 0} / 剩余 ${this.remainingQty(row)}`
    },
    statusText(v) { return statusMap[v] || v || '-' },
    auditStatusText(v) { return ({ pending: '待审核', approved: '已审核', rejected: '已驳回' })[v] || this.statusText(v) },
    approvalNodeText(node) { return ({ WAITING: '等待中', PENDING: '当前节点', APPROVED: '已通过', REJECTED: '已驳回', SKIPPED: '已跳过' })[node.node_status] || node.node_status || '-' },
    tagType(v) {
      return ['confirmed', 'approved', 'received', 'posted', 'planned'].includes(v) ? 'success' : ['cancelled', 'rejected'].includes(v) ? 'danger' : ['pending', 'partially_received', 'partial', 'submitted'].includes(v) ? 'warning' : 'info'
    }
  }
}
</script>

<style scoped>
.simple-detail {
  min-height: calc(100vh - 52px);
  background: #f7f9fa;
  padding: 16px 20px 40px;
  box-sizing: border-box;
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* 顶部卡片 */
.page-head {
  background: #ffffff;
  border-radius: 8px;
  padding: 16px 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
  border: 1px solid #eef2f6;
  gap: 16px;
}
.head-left {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}
.head-text {
  min-width: 0;
}
.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.title-row h1 {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
  color: #1e293b;
  line-height: 1.3;
}
.subtitle {
  margin: 4px 0 0;
  font-size: 13px;
  color: #64748b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

/* 主题按钮 */
.el-button--success {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}
.el-button--success:hover,
.el-button--success:focus {
  background-color: #00763f !important;
  border-color: #00763f !important;
}

/* 卡片样式 */
.detail-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}

.card-head-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}
.title-text-group {
  display: flex;
  align-items: center;
}
.bar-accent {
  width: 4px;
  height: 16px;
  background-color: #008b4b;
  border-radius: 2px;
  margin-right: 8px;
  display: inline-block;
}
.card-head-title h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: #1e293b;
}

/* 描述列表样式 */
.detail-descriptions ::v-deep .el-descriptions-item__label {
  width: 120px;
  background: #f8fafc;
  color: #475569;
  font-weight: 500;
}
.detail-descriptions ::v-deep .el-descriptions-item__content {
  color: #1e293b;
}

.highlight-no {
  color: #008b4b;
  font-weight: 600;
}
.highlight-green {
  color: #008b4b;
}

/* 审核流 */
.approval-card {
  background: #ffffff;
  border: 1px solid #cce5d6;
  border-radius: 8px;
  padding: 16px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}
.approval-tip {
  margin: 0 0 16px;
  font-size: 12px;
  color: #64748b;
}
.steps-wrapper {
  padding: 10px 0 6px;
}
.link-btn {
  color: #008b4b !important;
  font-size: 13px;
}
.link-btn:hover {
  color: #00763f !important;
}

/* 表格包装与元素 */
.table-wrap {
  width: 100%;
  overflow-x: auto;
}
.responsive-detail-table {
  width: 100%;
}
.responsive-detail-table ::v-deep th {
  background-color: #f8fafc;
  color: #475569;
  font-weight: 600;
}
.code-badge {
  font-family: monospace;
  font-weight: 600;
  color: #008b4b;
}
.item-name-text {
  font-weight: 600;
  color: #1e293b;
}
.item-spec-text {
  color: #475569;
}
.qty-num {
  font-weight: 600;
}
.stock-cell {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 4px;
}
.stock-info-icon {
  color: #008b4b;
  cursor: pointer;
}
.danger-qty {
  color: #e11d48;
  font-weight: 600;
}

/* 追溯链路卡片 */
.trace-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.trace-card {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 14px;
  background: #f8fafc;
}
.trace-head {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #1e293b;
  margin-bottom: 4px;
}
.trace-head i {
  color: #008b4b;
}
.trace-path {
  margin: 0;
  font-size: 12px;
  color: #64748b;
  word-break: break-all;
}

@media (max-width: 768px) {
  .simple-detail {
    padding: 12px 10px;
  }
  .page-head {
    flex-direction: column;
    align-items: stretch;
  }
  .head-actions {
    justify-content: flex-end;
  }
}
</style>
