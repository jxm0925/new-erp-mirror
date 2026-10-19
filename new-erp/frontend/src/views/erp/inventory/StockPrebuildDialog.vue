<template>
  <el-dialog :visible="visible" width="560px" custom-class="prebuild-dialog" :show-close="false" append-to-body @close="$emit('close')">
    <div slot="title" class="pb-head">
      <span class="pb-icon"><i class="el-icon-s-order"/></span>
      <div class="pb-title-wrap">
        <div class="pb-title">生成备货工单<span class="pb-tag">库存预警驱动</span></div>
        <div class="pb-sub">驱动自备货策略，生成后需在工单页发布排产</div>
      </div>
      <button type="button" class="pb-close" aria-label="关闭" @click="$emit('close')"><i class="el-icon-close"/></button>
    </div>
    <div v-if="alert" class="pb-body">
      <div class="pb-item">
        <div class="pb-item-name"><b>{{ item.item_code }}</b> {{ item.item_name }}<span v-if="item.spec"> {{ item.spec }}</span><span class="pb-muted">{{ state.item_type_label || '自制件' }} / {{ state.source_label || '自制' }}</span></div>
        <div v-if="warehouseName" class="pb-wh">目标仓库：{{ warehouseName }}</div>
      </div>
      <div class="pb-facts">
        <div class="pb-fact"><span>当前可用库存</span><b class="danger">{{ num(alert.available_qty) }}<small>{{ unit }}</small></b></div>
        <div class="pb-fact"><span>最低库存警戒线</span><b>{{ num(alert.min_stock_snapshot) }}<small>{{ unit }}</small></b></div>
        <div class="pb-fact"><span>安全库存目标</span><b>{{ num(alert.safety_stock_snapshot) }}<small>{{ unit }}</small></b></div>
      </div>
      <div class="pb-field">
        <label>备货数量 <em>*</em></label>
        <div class="pb-row"><el-input-number v-model="qty" :min="0" :precision="precision" :controls="false" size="small" class="pb-qty"/><span class="pb-unit">{{ unit }}</span><span class="pb-hint">已默认带入建议补货量，车间可根据实际排产产能修改</span></div>
      </div>
      <div class="pb-field">
        <label>计划完成日期</label>
        <div class="pb-row"><el-date-picker v-model="plannedDate" type="date" size="small" value-format="yyyy-MM-dd" placeholder="选择日期" :picker-options="{disabledDate:d=>d<today}" class="pb-date"/><span class="pb-hint">留空则自动按工单默认工期计算</span></div>
      </div>
      <div class="pb-field">
        <label>关联工艺路线</label>
        <div class="pb-route"><span class="pb-route-tag">路线</span>{{ routing.routing_name || '-' }} {{ routing.routing_no }}<span v-if="routing.version" class="pb-muted">V{{ routing.version }}</span><span class="pb-muted">（{{ routing.is_latest === false ? '默认路线，非最新版本' : '已自动锁定最新版' }}）</span></div>
      </div>
      <div class="pb-note"><i class="el-icon-info"/> 说明：生成的备货工单为“草稿”状态，核对料单与工序并在工单页点击“发布”后，车间工序才能接单下料。</div>
    </div>
    <span slot="footer"><el-button size="small" @click="$emit('close')">取消</el-button><el-button size="small" type="success" :loading="saving" :disabled="!(qty>0)" @click="submit">生成并打开工单</el-button></span>
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
    warehouseName () { return this.state.warehouse_name || ((this.alert && this.alert.warehouse) || {}).warehouse_name || '' },
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
.pb-head { display: flex; align-items: flex-start; gap: 12px; }
.pb-icon { width: 38px; height: 38px; border-radius: 8px; background: #e8f7ee; color: #18a058; display: flex; align-items: center; justify-content: center; font-size: 20px; flex: none; }
.pb-title-wrap { flex: 1; min-width: 0; }
.pb-title { font-size: 17px; font-weight: 600; color: #1f2329; display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.pb-tag { font-size: 12px; font-weight: 400; color: #18a058; background: #e8f7ee; border: 1px solid #b7e4c7; border-radius: 4px; padding: 0 6px; line-height: 20px; }
.pb-sub { font-size: 12px; color: #8f959e; margin-top: 4px; }
.pb-close { width: 30px; height: 30px; border: 1px solid #dcdfe6; border-radius: 6px; background: #fff; color: #606266; cursor: pointer; flex: none; font-size: 14px; }
.pb-close:hover { color: #18a058; border-color: #18a058; }
.pb-item { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; padding-bottom: 12px; border-bottom: 1px dashed #ebeef5; margin-bottom: 14px; }
.pb-item-name { font-size: 14px; color: #1f2329; word-break: break-all; }
.pb-muted { color: #8f959e; font-size: 12px; margin-left: 8px; }
.pb-wh { color: #18a058; font-size: 13px; white-space: nowrap; }
.pb-facts { display: flex; gap: 10px; margin-bottom: 18px; }
.pb-fact { flex: 1; min-width: 0; background: #f7f8fa; border: 1px solid #eef0f3; border-radius: 8px; padding: 10px 12px; display: flex; flex-direction: column; gap: 4px; }
.pb-fact span { font-size: 12px; color: #8f959e; }
.pb-fact b { font-size: 20px; color: #1f2329; }
.pb-fact b.danger { color: #f54a45; font-size: 24px; }
.pb-fact small { font-size: 12px; font-weight: 400; color: #8f959e; margin-left: 4px; }
.pb-field { margin-bottom: 14px; }
.pb-field label { display: block; font-size: 13px; color: #1f2329; margin-bottom: 6px; }
.pb-field label em { color: #f54a45; font-style: normal; }
.pb-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pb-qty { width: 150px; }
.pb-date { width: 170px; }
.pb-unit { color: #1f2329; }
.pb-hint { font-size: 12px; color: #8f959e; line-height: 18px; }
.pb-route { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 6px; background: #f2f3f5; border-radius: 6px; padding: 6px 10px; font-size: 13px; color: #1f2329; }
.pb-route-tag { color: #18a058; font-weight: 600; }
.pb-route .pb-muted { margin-left: 0; }
.pb-note { background: #edf8f1; border: 1px solid #c6ebd4; color: #2b7a4b; border-radius: 6px; padding: 9px 12px; font-size: 12px; line-height: 20px; }
::v-deep .prebuild-dialog { max-width: 94vw; border-radius: 10px; }
::v-deep .prebuild-dialog .el-dialog__header { padding: 18px 20px 12px; border-bottom: 1px solid #f0f1f3; }
::v-deep .prebuild-dialog .el-dialog__body { padding: 16px 20px 6px; }
@media (max-width: 560px) { .pb-facts { flex-direction: column; } }
</style>
