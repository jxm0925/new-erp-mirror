<template>
  <el-dialog :visible="visible" title="生成备货工单" width="460px" custom-class="prebuild-dialog" append-to-body @close="$emit('close')">
    <div v-if="alert" class="prebuild-body">
      <div class="prebuild-item">{{ item.item_code }} {{ item.item_name }}<span v-if="item.spec"> / {{ item.spec }}</span></div>
      <div class="prebuild-facts">
        <div><span>可用库存</span><b class="danger">{{ num(alert.available_qty) }}</b></div>
        <div><span>最低库存</span><b>{{ num(alert.min_stock_snapshot) }}</b></div>
        <div><span>安全库存</span><b>{{ num(alert.safety_stock_snapshot) }}</b></div>
      </div>
      <el-form label-width="84px" size="small" @submit.native.prevent>
        <el-form-item label="备货数量"><el-input-number v-model="qty" :min="0" :precision="precision" :controls="false" class="prebuild-qty"/><span class="prebuild-unit">{{ unit }}</span><div class="prebuild-hint">默认取建议补货量，可按车间产能修改</div></el-form-item>
        <el-form-item label="计划完成"><el-date-picker v-model="plannedDate" type="date" value-format="yyyy-MM-dd" placeholder="可留空" :picker-options="{disabledDate:d=>d<today}" class="prebuild-date"/></el-form-item>
        <el-form-item label="工艺路线"><span>{{ routing.routing_name || '-' }}<span v-if="routing.routing_no" class="prebuild-hint"> {{ routing.routing_no }}</span></span></el-form-item>
      </el-form>
      <div class="prebuild-hint">生成的是草稿，核对后在工单页点“发布”，车间才能接单。</div>
    </div>
    <span slot="footer"><el-button size="small" @click="$emit('close')">取消</el-button><el-button size="small" type="primary" :loading="saving" :disabled="!(qty>0)" @click="submit">生成并打开工单</el-button></span>
  </el-dialog>
</template>
<script>
import { createStockPrebuildFromInventoryAlert } from '@/api/erp/inventory'
export default {
  name: 'StockPrebuildDialog',
  props: { visible: Boolean, alert: { type: Object, default: null } },
  data: () => ({ qty: 0, plannedDate: '', saving: false, today: new Date(new Date().setHours(0, 0, 0, 0)) }),
  computed: {
    item () { return (this.alert && this.alert.item) || {} },
    state () { return (this.alert && this.alert.prebuild) || {} },
    routing () { return this.state.routing || {} },
    unit () { const u = this.item.unit || {}; return u.symbol || u.unit_name || '' },
    precision () { return Math.min(4, Number((this.item.unit || {}).decimal_places || 0)) }
  },
  watch: { visible (v) { if (v) { this.qty = Number(this.state.suggested_qty || 0); this.plannedDate = '' } } },
  methods: {
    num (v) { return Number(v || 0).toLocaleString('zh-CN', { maximumFractionDigits: 4 }) },
    async submit () {
      this.saving = true
      try {
        const r = await createStockPrebuildFromInventoryAlert(this.alert.id, { target_qty: this.qty, planned_date: this.plannedDate || null })
        this.$message.success(r.data.message || '已生成备货工单草稿')
        this.$emit('close')
        this.$router.push(`/production/work-orders/${r.data.data.id}`)
      } catch (e) { this.$message.error(e.userMessage || '生成备货工单失败') } finally { this.saving = false }
    }
  }
}
</script>
<style scoped>
.prebuild-item { font-weight: 600; margin-bottom: 10px; word-break: break-all; }
.prebuild-facts { display: flex; gap: 8px; margin-bottom: 14px; }
.prebuild-facts > div { flex: 1; background: #f5f7fa; border-radius: 4px; padding: 6px 8px; display: flex; flex-direction: column; }
.prebuild-facts span { font-size: 12px; color: #909399; }
.prebuild-facts b { font-size: 16px; }
.prebuild-facts .danger { color: #f56c6c; }
.prebuild-qty { width: 140px; }
.prebuild-date { width: 160px; }
.prebuild-unit { margin-left: 6px; color: #606266; }
.prebuild-hint { font-size: 12px; color: #909399; line-height: 18px; }
::v-deep .prebuild-dialog { max-width: 94vw; }
</style>
