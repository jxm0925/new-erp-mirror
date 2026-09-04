<template>
  <section class="simple-detail" v-if="doc">
    <header>
      <div>
        <h1>{{ title }}</h1>
        <p>查看单据头、明细和来源追溯链路。</p>
      </div>
      <el-button size="small" @click="$router.back()">返回</el-button>
    </header>

    <div class="detail-body">
      <main class="panel">
        <section v-if="type==='orders' && doc.approval_task" class="approval-progress">
          <div class="approval-title">
            <div><h3>审核状态与进度</h3><p>采购订单内仅展示审核状态、结果和进度，审核操作统一在审核中心完成。</p></div>
            <el-button size="mini" type="text" @click="$router.push(`/approvals/tasks/${doc.approval_task.id}`)">进入审核中心 <i class="el-icon-right" /></el-button>
          </div>
          <el-steps :active="approvalActive" finish-status="success" process-status="process" align-center>
            <el-step title="发起提交" />
            <el-step v-for="node in approvalNodes" :key="node.id" :title="node.node_name" :description="approvalNodeText(node)" />
            <el-step title="审核完成" :description="approvalResult" />
          </el-steps>
        </section>
        <h3>明细与追溯</h3>
        <el-table :data="doc.items || []" size="mini" border>
          <el-table-column label="物料编码" min-width="118" fixed>
            <template slot-scope="{row}">{{ itemCode(row) }}</template>
          </el-table-column>
          <el-table-column label="物料名称" min-width="150" show-overflow-tooltip>
            <template slot-scope="{row}">{{ itemName(row) }}</template>
          </el-table-column>
          <el-table-column label="采购数量" width="88" align="right">
            <template slot-scope="{row}">{{ qty(row) }}</template>
          </el-table-column>
          <el-table-column v-if="type==='orders'" label="来源需求" min-width="170" show-overflow-tooltip>
            <template slot-scope="{row}">{{ requestTrace(row) }}</template>
          </el-table-column>
          <el-table-column v-if="type==='orders'" label="来源计划" min-width="170" show-overflow-tooltip>
            <template slot-scope="{row}">{{ planTrace(row) }}</template>
          </el-table-column>
          <el-table-column v-if="type==='orders'" label="供应商拆分" min-width="150" show-overflow-tooltip>
            <template slot-scope="{row}">{{ splitTrace(row) }}</template>
          </el-table-column>
          <el-table-column v-if="type==='orders'" label="到货数量" width="88" align="right">
            <template slot-scope="{row}">{{ row.received_qty || 0 }}</template>
          </el-table-column>
          <el-table-column v-if="type==='orders'" label="剩余未到货" width="98" align="right">
            <template slot-scope="{row}">{{ remainingQty(row) }}</template>
          </el-table-column>
          <el-table-column v-if="type!=='orders'" label="明细状态" width="92">
            <template slot-scope="{row}">{{ statusText(row.line_status) }}</template>
          </el-table-column>
        </el-table>

        <section v-if="type==='orders'" class="trace-chain">
          <h3>追溯链路</h3>
          <div v-for="line in doc.items || []" :key="line.id" class="trace-card">
            <b>{{ itemCode(line) }} / {{ itemName(line) }}</b>
            <span>{{ traceChain(line) }}</span>
          </div>
        </section>
      </main>

      <aside class="panel">
        <h3>基础信息</h3>
        <dl>
          <dt>单号</dt><dd>{{ no }}</dd>
          <dt>业务状态</dt><dd><el-tag size="mini" :type="tagType(mainStatus)">{{ statusText(mainStatus) }}</el-tag></dd>
          <dt v-if="doc.audit_status">审核状态</dt><dd v-if="doc.audit_status">{{ auditStatusText(doc.audit_status) }}</dd>
          <dt v-if="doc.receipt_status">到货状态</dt><dd v-if="doc.receipt_status">{{ statusText(doc.receipt_status) }}</dd>
          <dt>来源</dt><dd>{{ sourceText }}</dd>
          <dt v-if="doc.source_no">来源单号</dt><dd v-if="doc.source_no">{{ doc.source_no }}</dd>
          <dt>日期</dt><dd>{{ doc.required_date || doc.order_date || doc.receipt_date || '-' }}</dd>
        </dl>
      </aside>
    </div>
  </section>
</template>

<script>
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
  pending: '待库存过账',
  posted: '已库存过账',
  open: '未完成'
}

export default {
  props: { type: { type: String, required: true } },
  data: () => ({ doc: null }),
  computed: {
    title() { return `${this.no} ${this.type === 'requests' ? '采购需求详情' : '采购订单详情'}` },
    no() { return this.doc?.request_no || this.doc?.purchase_order_no || '--' },
    mainStatus() { return this.doc?.request_status || this.doc?.purchase_status || this.doc?.confirm_status || this.doc?.receipt_status || '' },
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
      return ({ purchase_plan: '采购计划', manual: '手工创建' })[this.doc?.source_type || this.doc?.data_source] || this.doc?.source_type || this.doc?.data_source || '手工创建'
    }
  },
  async mounted() {
    const res = await getPurchase(this.type, this.$route.params.id)
    this.doc = res.data
  },
  methods: {
    itemCode(row) { return row.item_code || (row.item && row.item.item_code) || '-' },
    itemName(row) { return row.item_name || (row.item && row.item.item_name) || '-' },
    qty(row) { return row.request_qty || row.order_qty || row.receipt_qty || 0 },
    remainingQty(row) { return row.remaining_qty ?? Math.max(0, Number(row.order_qty || 0) - Number(row.received_qty || 0)) },
    requestTrace(row) {
      if (!row.request_id && !row.request_item_id) return '来源：手工创建'
      const no = row.request?.request_no || row.request_item?.request?.request_no || `需求ID ${row.request_id || '-'}`
      return `${no} / 明细 ${row.request_item_id || '-'}`
    },
    planTrace(row) {
      if (!row.plan_id && !row.plan_item_id) return '来源：手工创建'
      const no = row.plan?.plan_no || row.plan_item?.plan?.plan_no || this.doc.plan?.plan_no || `计划ID ${row.plan_id || '-'}`
      return `${no} / 明细 ${row.plan_item_id || '-'}`
    },
    splitTrace(row) {
      const split = row.supplier_split || row.plan_split
      if (!row.supplier_split_id && !row.plan_split_id && !split) return '来源：手工创建'
      const supplier = split && split.supplier ? split.supplier.supplier_name : (this.doc.supplier ? this.doc.supplier.supplier_name : '-')
      return `${supplier} / 拆分 ${row.supplier_split_id || row.plan_split_id || split.id}`
    },
    traceChain(row) {
      if (!row.request_id && !row.plan_id && !row.supplier_split_id && !row.plan_split_id) return '来源：手工创建 -> 采购订单明细'
      return `${this.requestTrace(row)} -> ${this.planTrace(row)} -> ${this.splitTrace(row)} -> 采购订单明细 -> 到货 ${row.received_qty || 0} / 剩余 ${this.remainingQty(row)}`
    },
    statusText(v) { return statusMap[v] || v || '-' },
    auditStatusText(v) { return ({ pending: '待审核', approved: '已审核', rejected: '已驳回' })[v] || this.statusText(v) },
    approvalNodeText(node) { return ({ WAITING: '等待中', PENDING: '当前节点', APPROVED: '已通过', REJECTED: '已驳回', SKIPPED: '已跳过' })[node.node_status] || node.node_status || '-' },
    tagType(v) {
      return ['confirmed', 'approved', 'received', 'posted'].includes(v) ? 'success' : ['cancelled', 'rejected'].includes(v) ? 'danger' : ['pending', 'partially_received', 'partial', 'submitted'].includes(v) ? 'warning' : 'info'
    }
  }
}
</script>

<style scoped>
.simple-detail{min-height:calc(100vh - 52px);background:#f7f8f9;padding:16px;min-width:960px}.simple-detail header{height:56px;display:flex;justify-content:space-between}.simple-detail h1{margin:0;font-size:18px}.simple-detail p{margin:3px 0;color:#737d87}.detail-body{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:12px}.panel{background:#fff;border:1px solid #e2e6ea;border-radius:4px;padding:12px;min-width:0}.panel h3{margin:0 0 10px;font-size:13px}.panel dl{display:grid;grid-template-columns:82px 1fr;gap:9px}.panel dt{color:#737d87}.panel dd{margin:0}.trace-chain{margin-top:12px}.trace-card{margin-bottom:8px;padding:10px;border:1px solid #e2e6ea;background:#fbfcfc;border-radius:4px;display:grid;gap:5px}.trace-card b{color:#26313b}.trace-card span{color:#66717b;font-size:12px}
.approval-progress{margin-bottom:14px;padding:12px;border:1px solid #dbe9e1;background:#fbfefc;border-radius:4px}.approval-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.approval-title h3{margin-bottom:2px}.approval-title p{font-size:12px}.approval-progress /deep/ .el-step__title{font-size:12px}.approval-progress /deep/ .el-step__description{font-size:11px}
</style>
