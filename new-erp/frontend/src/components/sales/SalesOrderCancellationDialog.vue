<template>
  <el-dialog title="取消销售订单" :visible="visible" width="620px" append-to-body :close-on-click-modal="false" @close="$emit('update:visible', false)">
    <div v-loading="loading">
      <el-alert title="订单取消后，未投入的工单自动取消；已投入的工单暂停，交生产主管处理。" type="warning" :closable="false" show-icon />
      <el-form label-position="top" size="small" style="margin-top:16px">
        <el-form-item label="取消原因（必填）"><el-input v-model.trim="reason" type="textarea" :rows="3" maxlength="500" show-word-limit /></el-form-item>
        <template v-if="Number(funds.remaining) > 0">
          <el-alert :title="'本单有效收款 ' + funds.received + '，已退 ' + funds.refunded + '，待处理 ' + funds.remaining" type="info" :closable="false" />
          <el-form-item label="收款处理意向（由财务确认）">
            <el-radio-group v-model="method"><el-radio label="refund">全额退款</el-radio><el-radio label="partial">部分退、部分留存</el-radio></el-radio-group>
          </el-form-item>
          <el-form-item v-if="method === 'partial'" label="申请留存金额"><el-input v-model.trim="retained" placeholder="填写约定不退或扣款金额" /></el-form-item>
          <p>预计退款 {{ refundAmount }}。提交取消不会直接打款，财务确认方案并办理退款后才结清。</p>
        </template>
      </el-form>
      <el-alert v-if="error" :title="error" type="error" :closable="false" />
    </div>
    <span slot="footer"><el-button :disabled="saving" @click="$emit('update:visible', false)">返回</el-button><el-button type="danger" :loading="saving" :disabled="loading || !loaded" @click="submit">确认取消订单</el-button></span>
  </el-dialog>
</template>
<script>
import api from '@/api/erp/master'
export default {
  props: { visible: Boolean, orderId: [Number, String] },
  data: () => ({ loading: false, loaded: false, saving: false, reason: '', method: 'refund', retained: '0', funds: {}, version: 0, error: '', revision: 0 }),
  computed: { refundAmount() { return Math.max(0, Number(this.funds.remaining || 0) - (this.method === 'partial' ? Number(this.retained || 0) : 0)).toFixed(4) } },
  watch: { visible(value) { if (value) this.load(); else this.revision++ } },
  methods: {
    async load() {
      const revision = ++this.revision
      this.loading = true; this.loaded = false; this.reason = ''; this.method = 'refund'; this.retained = '0'; this.error = ''
      try { const r = await api.get(`/v1/erp/sales/orders/${this.orderId}/cancellation-preview`); if (revision !== this.revision) return; this.funds = r.data.data; this.version = r.data.business_version; this.loaded = true }
      catch (e) { if (revision === this.revision) this.error = e.userMessage || '取消预览加载失败，请重新打开' }
      finally { if (revision === this.revision) this.loading = false }
    },
    async submit() {
      if (!this.reason) return this.$message.warning('请填写取消原因')
      if (this.method === 'partial' && !/^\d+(\.\d{1,4})?$/.test(this.retained)) return this.$message.warning('留存金额最多填写四位小数')
      this.saving = true
      try { await api.post(`/v1/erp/sales/orders/${this.orderId}/cancel`, { reason: this.reason, funds_method: this.method, retained_amount: this.method === 'partial' ? this.retained : '0', expected_version: this.version }); this.$message.success('订单已取消，生产与款项进入对应处置'); this.$emit('update:visible', false); this.$emit('changed') }
      catch (e) { this.error = e.userMessage || '取消失败，请核对订单状态' }
      finally { this.saving = false }
    }
  }
}
</script>
