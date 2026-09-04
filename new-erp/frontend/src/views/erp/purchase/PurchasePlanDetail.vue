<template>
  <section class="plan-detail-page" v-if="plan">
    <header class="detail-top">
      <div><h1>{{ plan.plan_no }} 采购计划详情</h1><p>计划物料明细、供应商拆分、采购订单追溯链路集中查看。</p></div>
      <div><el-button size="small" @click="$router.push('/purchase/plans')">返回列表</el-button><el-button v-if="canGenerate" size="small" type="success" @click="generate">生成采购订单</el-button></div>
    </header>
    <div class="detail-grid">
      <aside class="panel">
        <h3>计划物料明细</h3>
        <div v-for="line in plan.items" :key="line.id" class="material-row" :class="{active:selected && selected.id===line.id}" @click="selected=line">
          <b>{{ line.item && line.item.item_code }}</b>
          <span>{{ line.item && line.item.item_name }}</span>
          <em>需求 {{ line.required_qty }} / 已分配 {{ line.allocated_qty }} / 剩余 {{ line.remaining_qty }}</em>
          <small>来源需求：{{ line.request ? line.request.request_no : '--' }} / 明细ID {{ line.request_item_id || '--' }}</small>
        </div>
      </aside>
      <main class="panel">
        <h3>选中物料的供应商拆分</h3>
        <el-table :data="selected ? selected.splits : []" size="mini" border>
          <el-table-column label="供应商" min-width="160"><template slot-scope="{row}">{{ row.supplier && row.supplier.supplier_name }}</template></el-table-column>
          <el-table-column prop="purchase_qty" label="采购数量" width="100" />
          <el-table-column prop="unit_price" label="单价" width="90" />
          <el-table-column prop="tax_rate" label="税率" width="80" />
          <el-table-column prop="expected_date" label="预计到货" width="110" />
          <el-table-column prop="amount" label="预计金额" width="110" />
          <el-table-column label="订单" min-width="160"><template slot-scope="{row}">{{ row.order ? row.order.purchase_order_no : '未生成' }}</template></el-table-column>
          <el-table-column label="拆分状态" width="100"><template slot-scope="{row}">{{ statusText(row.split_status) }}</template></el-table-column>
        </el-table>
        <section class="orders-box">
          <h3>{{ generatedOrders.length ? '已生成采购订单' : '生成采购订单预览' }}</h3>
          <div v-for="g in orderCards" :key="g.key" class="order-card">
            <b>{{ g.supplier }}</b>
            <span>明细 {{ g.lines }} 行，数量 {{ g.qty }}，金额 ¥{{ money(g.amount) }}</span>
            <em>{{ g.orderNo || '预计生成 1 张采购订单' }}</em>
          </div>
        </section>
      </main>
      <aside class="panel">
        <h3>计划基础信息</h3>
        <dl>
          <dt>计划状态</dt><dd>{{ statusText(plan.plan_status) }}</dd>
          <dt>审核状态</dt><dd>{{ statusText(plan.audit_status) }}</dd>
          <dt>订单状态</dt><dd>{{ statusText(plan.order_status) }}</dd>
          <dt>计划日期</dt><dd>{{ plan.plan_date }}</dd>
          <dt>总数量</dt><dd>{{ plan.total_qty }}</dd>
          <dt>预计金额</dt><dd>¥{{ money(plan.total_amount) }}</dd>
        </dl>
      </aside>
    </div>
  </section>
</template>

<script>
import { getPurchase, previewPlanOrders, generatePlanOrders } from '@/api/erp/purchase'
const statusMap = { draft: '草稿', confirmed: '已确认', submitted: '已提交', approved: '已审核', rejected: '已驳回', processing: '处理中', partially_received: '部分到货', received: '已到货', closed: '已关闭', cancelled: '已取消', not_ordered: '未生成订单', partially_ordered: '部分生成订单', order_generated: '已生成订单', ordered: '已下单', pending: '待库存过账', posted: '已库存过账' }
export default {
  data: () => ({ plan: null, selected: null, preview: [] }),
  computed: {
    canGenerate() { return this.plan && this.plan.audit_status === 'approved' && this.plan.order_status !== 'order_generated' },
    generatedOrders() { return (this.plan.items || []).flatMap(i => i.splits || []).filter(s => s.order).map(s => s.order) },
    orderCards() {
      if (this.generatedOrders.length) {
        const map = {}
        ;(this.plan.items || []).flatMap(i => i.splits || []).filter(s => s.order).forEach(s => { const k = s.order.id; map[k] = map[k] || { key: k, supplier: s.supplier.supplier_name, orderNo: s.order.purchase_order_no, lines: 0, qty: 0, amount: 0 }; map[k].lines++; map[k].qty += Number(s.purchase_qty); map[k].amount += Number(s.amount) })
        return Object.values(map)
      }
      return this.preview.map(p => ({ key: p.supplier_id, supplier: p.supplier_name, lines: p.line_count, qty: p.total_qty, amount: p.total_amount }))
    }
  },
  async mounted() { await this.load() },
  methods: {
    async load() {
      const res = await getPurchase('plans', this.$route.params.id)
      this.plan = res.data
      this.selected = this.plan.items[0] || null
      const pre = await previewPlanOrders(this.plan.id).catch(() => ({ data: { data: [] } }))
      this.preview = pre.data.data || []
    },
    async generate() { await this.$confirm('按供应商分组生成采购订单？', '生成确认'); await generatePlanOrders(this.plan.id); this.$message.success('采购订单已生成'); await this.load() },
    money(v) { return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
    statusText(v) { return statusMap[v] || v || '-' }
  }
}
</script>

<style scoped>
.plan-detail-page{min-height:calc(100vh - 52px);background:#f7f8f9;padding:16px}.detail-top{height:56px;display:flex;justify-content:space-between}.detail-top h1{margin:0;font-size:18px}.detail-top p{margin:3px 0;color:#737d87}.detail-grid{display:grid;grid-template-columns:300px 1fr 280px;gap:12px}.panel{background:#fff;border:1px solid #e2e6ea;border-radius:4px;padding:12px}.panel h3{margin:0 0 10px;font-size:13px}.material-row{padding:10px;border-left:3px solid transparent;border-bottom:1px solid #edf0f2;cursor:pointer}.material-row.active{background:#eaf7ef;border-left-color:#07883f}.material-row b,.material-row span,.material-row em,.material-row small{display:block}.material-row em,.material-row small{font-style:normal;color:#737d87;font-size:11px}.orders-box{margin-top:14px}.order-card{display:inline-grid;gap:4px;margin:0 8px 8px 0;padding:10px;min-width:220px;border:1px solid #bcd8f5;background:#f8fbff}.order-card em{font-style:normal;color:#07883f}.panel dl{display:grid;grid-template-columns:80px 1fr;gap:9px;margin:0}.panel dt{color:#737d87}.panel dd{margin:0}
</style>
