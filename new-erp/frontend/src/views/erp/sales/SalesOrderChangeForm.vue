<!--
Design reference: D:\codex-introduce\new_erp\docs\ui-reference\phase6\phase6-order-change-add-edit.png
Design status: Approved
Scope: real-time controlled change UI only. The backend does not expose a draft/approval state;
therefore this page intentionally offers only return and confirmed submission.
-->
<template>
  <section class="order-change-page">
    <div class="change-toolbar">
      <div><div class="crumb">ERP / 销售订单 / 订单变更</div><h1>订单变更</h1></div>
      <div class="toolbar-actions"><el-button size="small" @click="back">返回订单详情</el-button><el-button type="success" size="small" :loading="submitting" :disabled="!canSubmit" @click="confirmSubmit">提交变更</el-button></div>
    </div>

    <el-alert class="change-warning" type="warning" :closable="false" show-icon title="本次变更会生成新的订单版本；旧库存预留、旧履约投影与未执行生产需求将被废止，保存后需重新进行履约/生产确认。" />

    <section class="change-card change-basic-card">
      <h3>变更基本信息</h3>
      <div class="change-basic-grid">
        <div class="readonly-field"><label>销售订单号</label><b>{{ order.sales_order_no || '-' }}</b></div>
        <div class="readonly-field"><label>客户</label><b>{{ order.customer_name || '-' }}</b></div>
        <div class="readonly-field"><label>当前版本</label><b>V{{ currentVersion }}</b></div>
        <div class="readonly-field"><label>订单状态</label><el-tag size="mini" type="success">已正式确认</el-tag></div>
        <div class="reason-field"><label><i>*</i> 变更原因</label><el-input v-model.trim="reason" type="textarea" :rows="3" maxlength="500" show-word-limit placeholder="请填写本次订单变更的业务原因" /></div>
        <div class="readonly-field"><label>变更范围</label><b>仅修改当前订单行的数量、单价、折扣和税率</b><small>SKU、客户和交付信息保持不变。</small></div>
      </div>
    </section>

    <section class="change-card line-card">
      <div class="section-heading"><h3>订单行变更明细 <small>当前值与修改值并列展示</small></h3><span>{{ changedLineCount }} 行发生变更</span></div>
      <el-table v-loading="loading" :data="lines" border size="mini" class="change-lines-table">
        <el-table-column label="行号" width="54" align="center"><template slot-scope="{$index}">{{ $index + 1 }}</template></el-table-column>
        <el-table-column prop="product_name" label="产品名称" min-width="118" show-overflow-tooltip />
        <el-table-column prop="sku_name" label="SKU名称" min-width="128" show-overflow-tooltip />
        <el-table-column label="当前订单" min-width="248">
          <template slot-scope="{row}"><div class="current-values"><span>数量 {{ num(row.original.order_qty) }} {{ row.unit_name_snapshot || '' }}</span><span>单价 ¥{{ money(row.original.unit_price) }}</span><span>折扣 {{ percent(row.original.discount_rate) }}</span><span>税率 {{ percent(row.original.tax_rate) }}</span></div></template>
        </el-table-column>
        <el-table-column label="变更后" min-width="314">
          <template slot-scope="{row}"><div class="edit-values"><el-input-number v-model="row.order_qty" :min="0.0001" :precision="4" :controls="false" size="mini" @change="recalculate(row)" /><el-input-number v-model="row.unit_price" :min="0.0001" :precision="4" :controls="false" size="mini" @change="recalculate(row)" /><el-input-number v-model="row.discount_rate" :min="0" :max="1" :step="0.01" :precision="4" :controls="false" size="mini" @change="recalculate(row)" /><el-input-number v-model="row.tax_rate" :min="0" :max="1" :step="0.01" :precision="4" :controls="false" size="mini" @change="recalculate(row)" /></div><div class="edit-labels"><span>数量</span><span>单价</span><span>折扣</span><span>税率</span></div></template>
        </el-table-column>
        <el-table-column label="金额变化（含税）" min-width="150" align="right"><template slot-scope="{row}"><div class="amount-diff"><b>¥{{ money(row.preview.amount_incl_tax) }}</b><span :class="diffClass(row.preview.amount_incl_tax - row.original.amount_incl_tax)">{{ signedMoney(row.preview.amount_incl_tax - row.original.amount_incl_tax) }}</span></div></template></el-table-column>
        <el-table-column label="变更状态" width="96" align="center"><template slot-scope="{row}"><el-tag size="mini" :type="changed(row) ? 'warning' : 'info'">{{ changed(row) ? '已修改' : '未修改' }}</el-tag></template></el-table-column>
      </el-table>
    </section>

    <div class="change-bottom-grid">
      <section class="change-card amount-card">
        <h3>金额变化汇总 <small>最终以服务端重算为准</small></h3>
        <div class="amount-summary"><div><span>未税金额</span><b>¥{{ money(originalTotals.excl) }}</b><em>→</em><strong>¥{{ money(nextTotals.excl) }}</strong></div><div><span>税额</span><b>¥{{ money(originalTotals.tax) }}</b><em>→</em><strong>¥{{ money(nextTotals.tax) }}</strong></div><div><span>含税总额</span><b>¥{{ money(originalTotals.incl) }}</b><em>→</em><strong>¥{{ money(nextTotals.incl) }}</strong></div><div class="total-diff"><span>本次金额变化</span><strong :class="diffClass(nextTotals.incl - originalTotals.incl)">{{ signedMoney(nextTotals.incl - originalTotals.incl) }}</strong></div></div>
      </section>
      <section class="change-card impact-card">
        <h3>变更后的履约影响</h3>
        <ul><li><i class="el-icon-success" /> 旧库存预留将释放，旧履约投影将废止。</li><li><i class="el-icon-success" /> 未执行的生产需求将废止并保留历史。</li><li><i class="el-icon-warning" /> 订单将回到待履约 / 待生产确认，需重新规划。</li></ul>
        <div class="impact-facts"><span>当前有效预留：<b>{{ activeReservationQty }}</b></span><span>未执行生产需求：<b>{{ pendingProductionCount }}</b></span></div>
      </section>
    </div>

    <el-dialog title="确认提交订单变更" :visible.sync="confirmVisible" width="620px" class="change-confirm-dialog" append-to-body>
      <div class="confirm-content"><i class="el-icon-warning-outline" /><div><b>本次操作将生成新的订单版本，并释放现有履约规划。</b><p>历史版本不会被覆盖。提交后订单需要重新进行履约或生产确认，是否继续？</p><p class="confirm-diff">本次共 {{ changedLineCount }} 行变更，含税金额变化 {{ signedMoney(nextTotals.incl - originalTotals.incl) }}。</p></div></div>
      <span slot="footer"><el-button @click="confirmVisible=false">取消</el-button><el-button type="success" :loading="submitting" @click="submit">确认提交变更</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { applySalesOrderChange, getSalesOrder } from '@/api/erp/sales'

export default {
  data: () => ({ order: {}, lines: [], reason: '', loading: false, submitting: false, confirmVisible: false }),
  computed: {
    currentVersion () { return Math.max(1, ...(this.order.versions || []).map(item => Number(item.version_no || 0))) },
    changedLineCount () { return this.lines.filter(this.changed).length },
    canSubmit () { return this.changedLineCount > 0 && this.reason.length > 0 && !this.submitting },
    originalTotals () { return this.totals(this.lines.map(item => item.original)) },
    nextTotals () { return this.totals(this.lines.map(item => item.preview)) },
    activeReservationQty () { return (this.order.fulfillments || []).filter(item => ['pending', 'confirmed'].includes(item.demand_status)).reduce((sum, item) => sum + Number(item.sales_qty || item.fulfillment_qty || 0), 0) },
    pendingProductionCount () { return (this.order.production_requirements || []).filter(item => item.is_active && ['draft', 'blocked', 'ready'].includes(item.requirement_status)).length }
  },
  async created () { await this.load() },
  methods: {
    async load () {
      this.loading = true
      try {
        const { data } = await getSalesOrder(this.$route.params.id)
        this.order = data
        if (!data.allowed_actions || !data.allowed_actions.change) {
          this.$message.warning(data.change_eligibility && data.change_eligibility.reason ? data.change_eligibility.reason : '当前订单不能发起原地变更')
          this.$router.replace(`/sales/orders/${this.$route.params.id}/detail`)
          return
        }
        this.lines = (data.lines || []).map(line => {
          const original = this.normalize(line)
          return { ...line, original, order_qty: original.order_qty, unit_price: original.unit_price, discount_rate: original.discount_rate, tax_rate: original.tax_rate, price_tax_mode: original.price_tax_mode, preview: original }
        })
      } finally { this.loading = false }
    },
    normalize (line) {
      const qty = Number(line.order_qty || 0); const price = Number(line.unit_price || 0); const discount = Number(line.discount_rate == null ? 1 : line.discount_rate); const tax = Number(line.tax_rate || 0); const mode = line.price_tax_mode || 'tax_inclusive'
      return this.calculate({ order_qty: qty, unit_price: price, discount_rate: discount, tax_rate: tax, price_tax_mode: mode })
    },
    calculate (row) {
      const commercial = Number(row.order_qty || 0) * Number(row.unit_price || 0) * Number(row.discount_rate == null ? 1 : row.discount_rate)
      const tax = Number(row.tax_rate || 0); const excl = row.price_tax_mode === 'tax_exclusive' ? commercial : commercial / (1 + tax); const taxAmount = commercial - excl; const incl = row.price_tax_mode === 'tax_exclusive' ? commercial + taxAmount : commercial
      return { ...row, amount_excl_tax: excl, tax_amount: taxAmount, amount_incl_tax: incl }
    },
    recalculate (row) { row.preview = this.calculate(row) },
    changed (row) { return ['order_qty', 'unit_price', 'discount_rate', 'tax_rate'].some(key => Math.abs(Number(row[key]) - Number(row.original[key])) > 0.000001) },
    totals (rows) { return rows.reduce((sum, row) => ({ excl: sum.excl + Number(row.amount_excl_tax || 0), tax: sum.tax + Number(row.tax_amount || 0), incl: sum.incl + Number(row.amount_incl_tax || 0) }), { excl: 0, tax: 0, incl: 0 }) },
    confirmSubmit () { if (!this.reason) return this.$message.warning('请填写变更原因'); if (!this.changedLineCount) return this.$message.warning('请至少修改一条订单行'); this.confirmVisible = true },
    async submit () {
      this.submitting = true
      try {
        await applySalesOrderChange(this.order.id, { reason: this.reason, lines: this.lines.filter(this.changed).map(row => ({ sales_order_line_id: row.id, order_qty: row.order_qty, unit_price: row.unit_price, discount_rate: row.discount_rate, tax_rate: row.tax_rate, price_tax_mode: row.price_tax_mode })) })
        this.$message.success('订单已变更，请重新执行履约规划或生产确认')
        this.$router.replace(`/sales/orders/${this.order.id}/detail?changed=1`)
      } catch (error) { this.$message.error((error.response && error.response.data && (error.response.data.message || Object.values(error.response.data.errors || {}).flat()[0])) || '订单变更提交失败') } finally { this.submitting = false; this.confirmVisible = false }
    },
    back () { this.$router.push(`/sales/orders/${this.$route.params.id}/detail`) },
    money (value) { return Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
    signedMoney (value) { return `${Number(value) >= 0 ? '+' : '-'}¥${this.money(Math.abs(Number(value || 0)))}` },
    num (value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 4 }) },
    percent (value) { return `${(Number(value || 0) * 100).toFixed(2)}%` },
    diffClass (value) { return Number(value) > 0.000001 ? 'is-up' : Number(value) < -0.000001 ? 'is-down' : 'is-flat' }
  }
}
</script>

<style scoped>
.order-change-page{padding:18px 20px 28px;background:#f5f7fa;min-height:calc(100vh - 64px);color:#243247}.change-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}.crumb{font-size:13px;color:#7b8798;margin-bottom:5px}.change-toolbar h1{font-size:23px;margin:0;font-weight:700;color:#18283a}.toolbar-actions{display:flex;gap:10px}.change-warning{margin-bottom:12px;border:1px solid #f5d4a2}.change-card{background:#fff;border:1px solid #e6ebf1;border-radius:4px;margin-bottom:12px;padding:14px 16px}.change-card h3{font-size:15px;margin:0 0 13px;color:#203044}.change-card h3 small,.section-heading small{font-size:12px;color:#8793a5;font-weight:400}.change-basic-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px 18px}.readonly-field,.reason-field{min-width:0}.readonly-field label,.reason-field label{display:block;color:#617085;font-size:12px;margin-bottom:6px}.readonly-field b{display:block;min-height:32px;padding:8px 10px;border:1px solid #e4e9f0;background:#fafbfc;border-radius:3px;font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.readonly-field small{display:block;margin-top:5px;color:#909bad;font-size:11px}.reason-field{grid-column:span 2}.reason-field label i{color:#f04444;font-style:normal}.section-heading{display:flex;justify-content:space-between;align-items:center}.section-heading span{font-size:12px;color:#07883f}.current-values{display:grid;grid-template-columns:1fr 1fr;gap:4px 8px;color:#58677b;line-height:20px}.edit-values{display:grid;grid-template-columns:repeat(4,minmax(58px,1fr));gap:6px}.edit-values>>>.el-input-number{width:100%}.edit-labels{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:4px;color:#94a0b0;font-size:11px;text-align:center}.amount-diff{display:flex;flex-direction:column;gap:4px}.amount-diff span{font-size:12px}.is-up{color:#d84a4a!important}.is-down{color:#07883f!important}.is-flat{color:#8090a2!important}.change-bottom-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:12px}.amount-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border:1px solid #edf0f4}.amount-summary>div{padding:11px;border-right:1px solid #edf0f4}.amount-summary>div:last-child{border:0}.amount-summary span{display:block;font-size:12px;color:#718095;margin-bottom:7px}.amount-summary b{font-size:13px;color:#647184}.amount-summary em{font-style:normal;color:#94a0ad;padding:0 3px}.amount-summary strong{font-size:14px;color:#162a42}.impact-card ul{margin:0;padding:0;list-style:none}.impact-card li{font-size:13px;margin:0 0 9px;color:#526176}.impact-card li i{color:#079450;margin-right:6px}.impact-card li .el-icon-warning{color:#e58b00}.impact-facts{display:flex;gap:22px;margin-top:12px;padding-top:10px;border-top:1px solid #edf0f4;color:#627189;font-size:12px}.impact-facts b{color:#253b52}.confirm-content{display:flex;gap:14px;color:#516174;line-height:1.7}.confirm-content>i{font-size:28px;color:#e79a13}.confirm-content b{color:#263a50}.confirm-content p{margin:5px 0}.confirm-diff{color:#bf5b00!important}@media(max-width:1366px){.change-basic-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.change-bottom-grid{grid-template-columns:1fr}.reason-field{grid-column:span 1}.change-lines-table{font-size:12px}.order-change-page{padding:14px}}@media(max-width:900px){.change-toolbar{align-items:flex-start;gap:10px;flex-direction:column}.toolbar-actions{width:100%;justify-content:flex-end}.change-basic-grid{grid-template-columns:1fr}.reason-field{grid-column:auto}.amount-summary{grid-template-columns:repeat(2,1fr)}.change-lines-table{overflow-x:auto}.impact-facts{flex-direction:column;gap:5px}}
</style>
