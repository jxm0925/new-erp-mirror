<template>
  <div class="sales-finance-panel" v-loading="loading">
    <div class="finance-panel-toolbar">
      <div><b>{{ overview.sales_order_no || '采购关联' }}</b><span class="muted">按采购需求查看归属，保留历史采购关联记录</span></div>
      <div class="panel-actions"><el-button size="small" @click="load">刷新</el-button></div>
    </div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <template v-if="overview.settlement && !error">
      <dl class="finance-facts"><div><dt>订单应收（{{ overview.currency || '币种未记录' }}）</dt><dd>{{ money(overview.settlement.contract_amount) }}</dd></div><div><dt>已核销净收款</dt><dd>{{ money(overview.settlement.net_received_amount) }}</dd></div><div><dt>未收金额</dt><dd>{{ money(overview.settlement.outstanding_amount) }}</dd></div><div><dt>关联采购订单</dt><dd>{{ overview.purchase_order_count }} 张</dd></div></dl>
      <h3 class="purchase-links-title">采购关联记录</h3>
      <el-table :data="links" border size="small" empty-text="暂无采购关联记录">
        <el-table-column label="采购来源 / 供应商" min-width="190">
          <template slot-scope="{row}">
            <b>{{ row.source_snapshot.request_no || row.source_snapshot.order_no }}</b>
            <div class="muted">{{ row.source_snapshot.supplier_name || (row.source_type === 'purchase_request' ? '采购需求' : '-') }}</div>
            <div v-for="source in row.order_sources || []" :key="source.purchase_order_id" class="muted">采购订单 · {{ source.purchase_order_no }}</div>
            <div v-for="document in row.progress?.documents || []" :key="document.stage + ':' + document.line_id" class="muted">{{ document.stage_label }} · {{ document.document_no }}：{{ qty(document.qty) }} {{ row.source_snapshot.base_unit_name }}</div>
            <div v-if="row.status === 'active'" class="stage-bar">
              <span v-for="stage in stageChips(row)" :key="stage.key" class="stage-chip" :class="stage.state">{{ stage.label }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="物料 / 规格" min-width="170"><template slot-scope="{row}">{{ row.source_snapshot.item_code }} · {{ row.source_snapshot.item_name }}<div class="muted">{{ row.source_snapshot.spec_model || '规格未记录' }}</div></template></el-table-column>
        <el-table-column label="归属数量" min-width="110"><template slot-scope="{row}">{{ qty(row.purchase_qty) }} {{ row.source_snapshot.base_unit_name || row.source_snapshot.purchase_unit_name || '单位未记录' }}</template></el-table-column>
        <el-table-column label="到货情况" min-width="170">
          <template slot-scope="{row}">
            <template v-if="row.status === 'active'">
              <b :class="arrival(row).cls">{{ arrival(row).text }}</b>
              <div v-for="doc in (row.stored && row.stored.documents) || []" :key="doc.receipt_no" class="muted">入库单 {{ doc.receipt_no }} · {{ qty(doc.qty) }} · {{ dateTime(doc.posted_at) }}</div>
              <div v-if="row.stored && row.stored.shared" class="muted">该采购需求由多张订单共用，按本单归属数量显示</div>
            </template>
            <span v-else class="muted">-</span>
          </template>
        </el-table-column>
        <el-table-column label="说明 / 操作人" min-width="180"><template slot-scope="{row}">{{ row.reason || (row.source_type === 'purchase_request' ? '采购需求物料分配' : '-') }}<div class="muted">{{ row.created_by }} · {{ date(row.created_at) }}</div><div v-if="row.status === 'reversed'" class="muted">撤销：{{ row.reverse_reason }} · {{ row.reversed_by }}</div></template></el-table-column>
        <el-table-column label="状态" width="85"><template slot-scope="{row}"><el-tag size="mini" :type="row.status === 'active' ? 'success' : 'info'">{{ row.status === 'active' ? '有效' : '已撤销' }}</el-tag></template></el-table-column>
        <el-table-column v-if="canManage" label="操作" width="75"><template slot-scope="{row}"><el-button v-if="row.status === 'active' && row.source_type !== 'purchase_request'" type="text" size="mini" :disabled="busy || loading" @click="reverse(row)">撤销</el-button></template></el-table-column>
      </el-table>
      <el-pagination class="panel-pagination" small layout="total, prev, pager, next" :current-page="linkPage" :page-size="10" :total="linkTotal" @current-change="changeLinkPage" />
    </template>
  </div>
</template>

<script>
import { getSalesOrderFinance, listSalesPurchaseLinks, reverseSalesPurchaseLink } from '@/api/erp/salesFinance'

export default {
  props: { orderId: { type: [Number, String], required: true } },
  data: () => ({ loading: false, busy: false, error: '', overview: {}, links: [], linkPage: 1, linkTotal: 0, loadRequest: 0 }),
  computed: { canManage() { return this.$can('finance.view') && this.$can('purchase.order.edit') } },
  watch: { busy(value) { this.$emit('busy-change', value) }, orderId: { immediate: true, handler() { this.linkPage = 1; this.overview = {}; this.load() } } },
  beforeDestroy() { this.loadRequest++ },
  methods: {
    money(value) { return value === null || value === undefined || value === '' ? '待确认' : Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) },
    qty(value) { return value === null || value === undefined ? '-' : String(value).replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1') },
    date(value) { return value ? String(value).slice(0, 10) : '-' },
    dateTime(value) { return value ? String(value).replace('T', ' ').slice(0, 16) : '-' },
    unit(row) { return row.source_snapshot.base_unit_name || row.source_snapshot.purchase_unit_name || '' },
    storedQty(row) { return Number((row.stored && row.stored.qty) || 0) },
    stageQty(row, key) { return Number((row.progress && row.progress.stages && row.progress.stages[key]) || 0) },
    // 到货情况只看数量事实：已入库 > 到货待入库 > 已下单 > 未下单；不涉及任何价格。
    arrival(row) {
      const need = Number(row.purchase_qty || 0)
      const stored = this.storedQty(row)
      const unit = this.unit(row)
      if (need > 0 && stored + 0.00001 >= need) return { cls: 'ok', text: `已入库 ${this.qty(stored)} / ${this.qty(need)} ${unit}` }
      const arrived = this.stageQty(row, 'receipt')
      const parts = []
      if (stored > 0) parts.push(`已入库 ${this.qty(stored)}`)
      if (arrived > 0) parts.push(`已到货待入库 ${this.qty(arrived)}`)
      if (parts.length) return { cls: 'warn', text: `${parts.join('，')} / ${this.qty(need)} ${unit}` }
      if (this.stageQty(row, 'order') > 0 || (row.order_sources || []).length || row.source_type === 'purchase_order') return { cls: 'muted-strong', text: '已下采购单，未到货' }
      return { cls: 'muted-strong', text: '未下采购单' }
    },
    stageChips(row) {
      const stored = this.storedQty(row)
      const chips = [
        { key: 'request', label: '采购需求', on: row.source_type !== 'purchase_order' },
        { key: 'order', label: '采购订单', on: this.stageQty(row, 'order') > 0 || (row.order_sources || []).length > 0 || row.source_type === 'purchase_order' || stored > 0 || this.stageQty(row, 'receipt') > 0 },
        { key: 'receipt', label: this.stageQty(row, 'receipt') > 0 ? `到货待入库 ${this.qty(this.stageQty(row, 'receipt'))}` : '到货', on: this.stageQty(row, 'receipt') > 0 || stored > 0 },
        { key: 'stored', label: `已入库 ${this.qty(stored)}`, on: stored > 0 }
      ].filter(chip => chip.key !== 'request' || row.source_type !== 'purchase_order')
      const current = chips.findIndex(chip => !chip.on)
      return chips.map((chip, index) => ({ ...chip, state: chip.key === 'stored' && stored > 0 ? 'done' : (index === current ? 'current' : (chip.on ? 'done' : '')) }))
    },
    async load() {
      if (!this.orderId) return
      const request = ++this.loadRequest; this.loading = true; this.error = ''
      try {
        const [overview, links] = await Promise.all([getSalesOrderFinance(this.orderId), listSalesPurchaseLinks(this.orderId, { page: this.linkPage, per_page: 10 })])
        if (request !== this.loadRequest) return
        this.overview = overview.data.data; this.links = links.data.data; this.linkTotal = links.data.total
      } catch (error) { if (request === this.loadRequest) this.error = error.userMessage || '采购关联加载失败，请重试。' }
      finally { if (request === this.loadRequest) this.loading = false }
    },
    changeLinkPage(page) { this.linkPage = page; return this.load() },
    async reverse(row) {
      if (this.busy) return
      try { const { value } = await this.$prompt('撤销后会释放采购归属数量，原关联和撤销原因仍保留。', '撤销采购关联', { inputPlaceholder: '填写撤销原因', inputValidator: value => !!(value || '').trim() && value.length <= 500 || '请填写500字以内的撤销原因' }); this.busy = true; await reverseSalesPurchaseLink(this.orderId, row.id, { version: this.overview.version, reason: value.trim() }); await this.load(); this.$message.success('采购关联已撤销') }
      catch (error) { if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '撤销失败，请刷新后重试。') }
      finally { this.busy = false }
    },
  }
}
</script>

<style scoped>
.stage-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 4px;
}
.stage-chip {
  padding: 0 8px;
  border-radius: 10px;
  color: #64748b;
  background: #f1f5f9;
  font-size: 11px;
  line-height: 18px;
}
.stage-chip.done {
  color: #15803d;
  background: #dcfce7;
}
.stage-chip.current {
  color: #b45309;
  background: #fef3c7;
}
.ok { color: #15803d; }
.warn { color: #b45309; }
.muted-strong { color: #64748b; }
.purchase-links-title {
  margin: 18px 0 12px;
  font-size: 14px;
  font-weight: 600;
}
.sales-finance-panel {
  min-width: 0;
  color: #303133;
}
.finance-panel-toolbar,
.panel-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.finance-panel-toolbar {
  justify-content: space-between;
  margin-bottom: 16px;
}
.finance-panel-toolbar .muted {
  display: block;
  margin-top: 5px;
}
.muted {
  font-size: 12px;
  color: #737b87;
  line-height: 1.65;
}
.finance-facts {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin: 16px 0;
}
.finance-facts > div {
  min-width: 0;
}
.finance-facts dt {
  font-size: 12px;
  color: #737b87;
}
.finance-facts dd {
  font-size: 18px;
  font-weight: 600;
  margin: 8px 0;
  overflow-wrap: anywhere;
}
.panel-pagination {
  margin: 14px 0;
  text-align: right;
  max-width: 100%;
  overflow: auto;
}
@media (max-width: 700px) {
  .finance-facts {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .finance-panel-toolbar {
    align-items: flex-start;
  }
  .panel-actions {
    width: 100%;
  }
}
</style>
