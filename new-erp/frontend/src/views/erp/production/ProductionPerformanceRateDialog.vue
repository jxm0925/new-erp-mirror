<template>
  <el-dialog title="调整工序绩效比例" :visible.sync="visible" width="560px" append-to-body top="8vh" custom-class="performance-rate-dialog" :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving" @closed="reset">
    <template v-if="scope">
      <div class="performance-rate-context"><strong>{{ scope.operation_name || scope.operation_code || '工序' }}</strong><span>{{ scope.work_order_no || ('发货单 #' + scope.shipment_id) }}</span><span>比例版本 {{ scope.rate_version || '原始记录' }}</span></div>
      <el-alert v-if="pending" title="上次保存结果未返回，请重试原操作。" type="warning" :closable="false" show-icon />
      <el-form label-position="top" class="performance-rate-form" @submit.native.prevent="save">
        <el-form-item label="工序绩效比例（%）" required><el-input v-model.trim="percent" inputmode="decimal" placeholder="例如 1 或 2" :disabled="saving || !!pending"><template slot="append">%</template></el-input></el-form-item>
        <el-form-item label="调整原因" required><el-input v-model.trim="reason" type="textarea" :rows="3" maxlength="500" show-word-limit :disabled="saving || !!pending" /></el-form-item>
      </el-form>
      <p class="performance-rate-help">各道工序比例独立设置。整单商品全部实际发运后，统计按本工序的新比例和个人份额计算。</p>
      <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    </template>
    <span slot="footer"><el-button :disabled="saving" @click="visible = false">取消</el-button><el-button type="success" :loading="saving" @click="save">{{ pending ? '重试原操作' : '保存工序比例' }}</el-button></span>
  </el-dialog>
</template>

<script>
import { updatePerformanceScopeRate } from '../../../api/erp/production-performance'
import { ratioToPercent, buildPerformanceRatePayload } from '../../../utils/production-performance.mjs'

export default {
  name: 'ProductionPerformanceRateDialog',
  data: () => ({ visible: false, saving: false, scope: null, percent: '', reason: '', error: '', pending: null, storageKey: '' }),
  methods: {
    open(scope) {
      if (!this.$can('production.performance.view') || !this.$can('production.performance.manage')) return
      this.reset()
      this.scope = { ...scope }
      let actor = 0
      try { const profile = JSON.parse(localStorage.getItem('erp_user') || '{}'); actor = profile.legacy_id || profile.id || 0 } catch (_) { /* No saved identity. */ }
      this.storageKey = 'erp_pending_performance_rate_' + actor + '_' + scope.scope_type + '_' + scope.scope_id
      try { this.pending = JSON.parse(localStorage.getItem(this.storageKey) || 'null') } catch (_) { this.pending = null }
      this.percent = ratioToPercent(this.pending ? this.pending.performance_rate : scope.performance_rate_snapshot)
      this.reason = this.pending?.reason || ''
      this.visible = true
    },
    reset() {
      this.saving = false; this.scope = null; this.percent = ''; this.reason = ''; this.error = ''; this.pending = null; this.storageKey = ''
    },
    async save() {
      if (this.saving || !this.scope || !this.$can('production.performance.view') || !this.$can('production.performance.manage')) return
      let payload = this.pending
      this.error = ''
      if (!payload) {
        try { payload = buildPerformanceRatePayload(this.scope, this.percent, this.reason, 'performance-rate-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12)) } catch (error) { this.error = error.message; return }
        localStorage.setItem(this.storageKey, JSON.stringify(payload)); this.pending = payload
      }
      this.saving = true
      try {
        await updatePerformanceScopeRate(this.scope.scope_type, this.scope.scope_id, payload)
        localStorage.removeItem(this.storageKey); this.pending = null; this.visible = false
        this.$message.success('工序绩效比例已保存'); this.$emit('updated')
      } catch (error) {
        const status = Number(error.response?.status || 0)
        if (status >= 400 && status < 500 && error.errorCode !== 'command_processing') {
          localStorage.removeItem(this.storageKey); this.pending = null
          if ([403, 404, 409].includes(status)) { this.visible = false; this.$emit('stale') }
        }
        this.error = error.userMessage || error.message || '工序绩效比例保存失败'
        this.$message.error(this.error)
      } finally { this.saving = false }
    }
  }
}
</script>

<style>
.performance-rate-dialog,
.performance-rate-dialog * {
  box-sizing: border-box;
}
.performance-rate-dialog {
  display: flex;
  flex-direction: column;
  max-width: calc(100vw - 32px);
  max-height: 85vh;
  border-radius: 8px;
}
.performance-rate-dialog .el-dialog__body {
  min-width: 0;
  min-height: 0;
  overflow: auto;
}
.performance-rate-dialog .el-dialog__header,
.performance-rate-dialog .el-dialog__footer {
  flex-shrink: 0;
}
.performance-rate-context {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 12px;
  margin-bottom: 16px;
  color: #475569;
  overflow-wrap: anywhere;
}
.performance-rate-context strong {
  color: #1e293b;
}
.performance-rate-form {
  margin-top: 14px;
}
.performance-rate-help {
  margin: 4px 0 16px;
  color: #64748b;
  font-size: 13px;
  line-height: 1.6;
}
.performance-rate-dialog .el-input__inner:focus,
.performance-rate-dialog .el-textarea__inner:focus {
  border-color: #008b4b;
}
@media (max-width: 780px) {
  .performance-rate-dialog .el-dialog__body {
    padding: 16px;
  }
  .performance-rate-dialog .el-dialog__footer {
    padding: 12px 16px;
  }
}
</style>
