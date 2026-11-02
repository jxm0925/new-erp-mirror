<template>
  <section class="cancelled-order-funds">
    <div class="panel-head"><strong>已取消订单待处理款项（{{ total }}）</strong><el-button size="mini" :loading="loading" @click="load">刷新</el-button><el-checkbox v-model="includeSettled" @change="search">包含已结清</el-checkbox></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table :data="rows" v-loading="loading" size="small" border empty-text="没有待处理的取消订单款项">
      <el-table-column prop="sales_order_no" label="订单" min-width="145" /><el-table-column prop="customer_name" label="客户" min-width="120" />
      <el-table-column prop="currency" label="币种" width="70" /><el-table-column prop="received" label="有效实收" min-width="100" /><el-table-column prop="refunded" label="已退款" min-width="100" />
      <el-table-column prop="retained" label="取消收入（已确认）" min-width="135" /><el-table-column prop="remaining" label="待处理余额" min-width="110" /><el-table-column prop="refund_reserved" label="退款办理中" min-width="110" />
      <el-table-column label="进度" min-width="115"><template slot-scope="{row}">{{ row.finance_status === 'settled' ? '已结清' : row.finance_confirmed ? '待办理退款' : '待财务确认' }}</template></el-table-column>
      <el-table-column label="操作" min-width="180"><template slot-scope="{row}">
        <el-button v-if="$can('finance.payment.confirm')" type="text" size="mini" @click="review(row)">{{ row.finance_confirmed ? '调整方案' : '确认方案' }}</el-button>
        <el-button v-if="row.finance_confirmed && Number(row.remaining) > Number(row.refund_reserved) && $can('finance.payment.create') && $can('finance.allocation.create')" type="text" size="mini" @click="refund(row)">生成退款付款单</el-button>
      </template></el-table-column>
    </el-table>
    <el-pagination :current-page.sync="page" :page-size="10" :total="total" layout="total, prev, pager, next" @current-change="load" />
    <el-dialog title="财务确认取消订单款项处理" :visible.sync="dialog" width="540px" append-to-body :close-on-click-modal="false">
      <template v-if="selected"><p>{{ selected.sales_order_no }} · {{ selected.customer_name }} · {{ selected.currency }}</p>
        <p>销售申请退款：{{ selected.case ? selected.case.requested_refund : selected.remaining }}；申请留存：{{ selected.case ? selected.case.requested_retained : '0' }}</p>
        <el-form label-position="top" size="small"><el-form-item label="财务确认留存金额"><el-input v-model.trim="retained" /></el-form-item>
          <el-form-item label="确认依据与留存核算方式（必填）"><el-input v-model.trim="note" type="textarea" :rows="3" maxlength="1000" placeholder="说明退款依据；留存部分须由财务确认核算方式" /></el-form-item></el-form>
        <el-alert title="本次只确认处置方案。实际退款仍须通过付款单确认；原收款记录保留。" type="info" :closable="false" />
      </template>
      <span slot="footer"><el-button @click="dialog=false">返回</el-button><el-button type="success" :loading="saving" @click="approve">确认方案</el-button></span>
    </el-dialog>
  </section>
</template>
<script>
import api from '@/api/erp/master'
export default {
  data: () => ({ rows: [], page: 1, total: 0, loading: false, revision: 0, error: '', includeSettled: false, dialog: false, selected: null, retained: '0', note: '', saving: false }),
  created() { this.load() },
  activated() { this.load() },
  methods: {
    search() { this.page = 1; this.load() },
    async load() { const revision = ++this.revision; this.loading = true; this.error = ''; try { const r = await api.get('/v1/erp/finance/cancelled-orders', { params: { page: this.page, per_page: 10, include_settled: this.includeSettled ? 1 : 0 } }); if (revision !== this.revision) return; this.rows = r.data.data; this.total = r.data.total } catch (e) { if (revision === this.revision) this.error = e.userMessage || '款项清单加载失败' } finally { if (revision === this.revision) this.loading = false } },
    review(row) { this.selected = row; this.retained = String(row.case ? (row.finance_confirmed ? row.retained : row.case.requested_retained) : 0); this.note = row.case?.accounting_note || ''; this.dialog = true },
    async approve() { if (!this.note || !/^\d+(\.\d{1,4})?$/.test(this.retained)) return this.$message.warning('请填写有效留存金额及财务确认依据'); this.saving = true; try { await api.post(`/v1/erp/finance/cancelled-orders/${this.selected.id}/confirm`, { retained_amount: this.retained, accounting_note: this.note, expected_version: this.selected.case?.business_version || 0 }); this.dialog = false; this.$message.success('处置方案已确认'); await this.load() } catch (e) { this.$message.error(e.userMessage || '确认失败') } finally { this.saving = false } },
    refund(row) { this.$router.push({ path: '/finance/payments/create', query: { source_type: 'sales_order_refund', source_id: row.id } }) }
  }
}
</script>
<style scoped>
.cancelled-order-funds { background:#fff; border:1px solid #ebeef5; border-radius:8px; padding:16px; margin:16px 0; }
.panel-head { display:flex; align-items:center; gap:16px; margin-bottom:12px; }
</style>
