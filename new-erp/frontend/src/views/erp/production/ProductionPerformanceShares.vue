<template>
  <el-dialog title="工序个人份额" :visible.sync="visible" width="900px" append-to-body custom-class="performance-dialog performance-shares-dialog" :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving" @closed="reset">
    <div v-loading="loading" v-if="scope">
      <div class="performance-scope-meta"><strong>{{ scope.operation_name || scope.operation_code || '工序' }}</strong><span>{{ scope.work_order_no || ('发货单 #' + scope.shipment_id) }}</span><span>{{ scope.status === 'COMPLETED' ? '工序已完成' : '完工前份额计划' }}</span><span>当前份额版本 {{ assignmentVersion || '未登记' }}</span></div>
      <el-alert v-if="pending" title="上次确认结果未返回。请重试原确认，系统会识别同一次操作。" type="warning" :closable="false" show-icon />
      <el-alert v-if="legacyAssignment" title="当前展示历史份额。保存新版本时，所有参与人员的份额合计须为 100%，原记录保留。" type="info" :closable="false" show-icon />
      <div class="performance-share-toolbar"><p class="performance-help">个人份额合计 100%。单人默认 100%，多人默认平分，可手动调整。</p><el-button v-if="scope.can_manage_shares" size="small" :disabled="saving || !!pending" @click="equalShares">按参与人员平分</el-button></div>
      <el-table :data="participants" border size="small" row-key="employee_legacy_id">
        <el-table-column label="参与人员" min-width="150"><template slot-scope="s">{{ s.row.employee_name }}<el-tag v-if="s.row.employee_legacy_id === scope.owner_legacy_id" size="mini">接单人</el-tag><small v-if="!s.row.identity_exists" class="performance-error">账号资料待核对</small><small v-if="selections[s.row.employee_legacy_id].legacyExcluded">历史记录：不计个人绩效</small></template></el-table-column>
        <el-table-column prop="actual_labor_minutes" label="实际工时（分钟）" width="145" />
        <el-table-column label="个人份额（%）" width="150"><template slot-scope="s"><el-input v-model="selections[s.row.employee_legacy_id].percent" size="small" placeholder="请明确填写" :disabled="saving || !!pending || !scope.can_manage_shares || !s.row.identity_exists" /></template></el-table-column>
        <el-table-column label="备注" min-width="170"><template slot-scope="s"><el-input v-model="selections[s.row.employee_legacy_id].remark" size="small" maxlength="500" :disabled="saving || !!pending || !scope.can_manage_shares || !s.row.identity_exists" /></template></el-table-column>
      </el-table>
      <div class="performance-pagination"><span>共 {{ total }} 人，已登记内容跨页保留</span><el-pagination background layout="prev, pager, next" :current-page="page" :page-size="10" :total="total" :disabled="loading || saving" @current-change="loadPage" /></div>
      <div class="performance-share-total"><strong>个人份额合计 {{ totals.declared }}% / 100%</strong><span v-if="totals.declared !== 100">请调整至合计 100%</span></div>
    </div>
    <span slot="footer"><el-button :disabled="saving" @click="visible=false">关闭</el-button><el-button v-if="scope && (scope.can_manage_shares || pending)" type="success" :loading="saving" :disabled="loading || (!pending && totals.declared !== 100)" @click="confirm">{{ pending ? '重试原确认' : (scope.status === 'COMPLETED' ? '确认新个人份额' : '保存份额计划') }}</el-button></span>
  </el-dialog>
</template>

<script>
import { getPerformanceScope, confirmPerformanceShares } from '../../../api/erp/production-performance'
import { ratioToPercent, buildPerformanceShares, performanceSelectionTotals } from '../../../utils/production-performance.mjs'

export default {
  data: () => ({ visible: false, loading: false, saving: false, sequence: 0, type: '', id: 0, scope: null, participants: [], selections: {}, defaults: [], total: 0, page: 1, assignmentVersion: 0, legacyAssignment: false, pending: null, storageKey: '' }),
  computed: { totals() { return performanceSelectionTotals(Object.values(this.selections)) } },
  methods: {
    async open(type, id) {
      if (!this.$can('production.performance.manage') && !this.$can(type === 'shipment_packing_operation' ? 'sales_order.shipment.packing.execute' : 'production.task.collaborate') && !this.$can('production.performance.view')) return
      this.reset(); this.type = type; this.id = id; this.visible = true
      let actor = 0
      try { actor = JSON.parse(localStorage.getItem('erp_user') || '{}').legacy_id || 0 } catch (_) { /* No stored profile. */ }
      this.storageKey = 'erp_pending_performance_' + actor + '_' + type + '_' + id
      try { this.pending = JSON.parse(localStorage.getItem(this.storageKey) || 'null') } catch (_) { this.pending = null }
      await this.loadPage(1)
    },
    reset() {
      this.sequence++; this.loading = false; this.saving = false; this.scope = null; this.participants = []; this.selections = {}; this.defaults = []; this.total = 0; this.page = 1; this.assignmentVersion = 0; this.legacyAssignment = false; this.pending = null; this.storageKey = ''
    },
    async loadPage(page) {
      const sequence = ++this.sequence; this.loading = true
      try {
        const { data } = await getPerformanceScope(this.type, this.id, { page, per_page: 10 })
        if (sequence !== this.sequence || !this.visible) return
        const value = data.data
        if (!this.scope) {
          this.scope = value.scope; this.assignmentVersion = value.assignment?.version_no || 0
          this.defaults = value.default_shares || []
          for (const share of this.defaults) this.$set(this.selections, share.employee_legacy_id, { employee_legacy_id: share.employee_legacy_id, percent: ratioToPercent(share.share_ratio), remark: '', legacyExcluded: false })
          const saved = this.pending || value.assignment
          for (const share of saved?.shares || []) this.$set(this.selections, share.employee_legacy_id, { employee_legacy_id: share.employee_legacy_id, percent: ratioToPercent(share.share_ratio), remark: share.remark || '', legacyExcluded: share.eligible === false })
          this.legacyAssignment = Boolean(value.assignment && (Number(value.assignment.credited_share_ratio) < 1 || (value.assignment.shares || []).some(share => share.eligible === false)))
        } else if (Number(value.scope.scope_version) !== Number(this.scope.scope_version) || Number(value.assignment?.version_no || 0) !== this.assignmentVersion) {
          throw new Error('工序或份额已变化，请关闭后重新打开')
        }
        this.participants = value.participants.data; this.total = value.participants.meta.total; this.page = page
        for (const person of this.participants) if (!this.selections[person.employee_legacy_id]) this.$set(this.selections, person.employee_legacy_id, { employee_legacy_id: person.employee_legacy_id, percent: '', remark: '', legacyExcluded: false })
      } catch (error) { if (sequence === this.sequence) this.$message.error(error.userMessage || error.message) }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    equalShares() {
      if (!this.scope?.can_manage_shares || this.pending) return
      for (const share of this.defaults) this.$set(this.selections, share.employee_legacy_id, { ...this.selections[share.employee_legacy_id], employee_legacy_id: share.employee_legacy_id, percent: ratioToPercent(share.share_ratio), legacyExcluded: false })
    },
    async confirm() {
      if (this.saving || !this.scope || (!this.pending && !this.scope.can_manage_shares)) return
      let payload = this.pending
      if (!payload) {
        try {
          payload = { client_command_id: 'performance-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12), expected_scope_version: this.scope.scope_version, expected_assignment_version: this.assignmentVersion,
            shares: buildPerformanceShares(Object.values(this.selections)) }
        } catch (error) { this.$message.error(error.message); return }
        localStorage.setItem(this.storageKey, JSON.stringify(payload)); this.pending = payload
      }
      this.saving = true
      try {
        const { data } = await confirmPerformanceShares(this.type, this.id, payload)
        localStorage.removeItem(this.storageKey); this.pending = null; this.$message.success(data.data?.status === 'planned' ? '个人份额计划已保存' : '个人份额已确认'); this.visible = false; this.$emit('confirmed')
      } catch (error) {
        const status = Number(error.response?.status || 0)
        if (status >= 400 && status < 500 && error.errorCode !== 'command_processing') {
          localStorage.removeItem(this.storageKey); this.pending = null
          if ([403, 404, 409].includes(status)) this.visible = false
        }
        this.$message.error(error.userMessage || error.message)
      } finally { this.saving = false }
    }
  }
}
</script>

<style>
.performance-shares-dialog,
.performance-shares-dialog * {
  box-sizing: border-box;
}
.performance-dialog.performance-shares-dialog {
  max-width: calc(100vw - 32px);
  max-height: 85vh;
  display: flex;
  flex-direction: column;
}
.performance-shares-dialog .el-dialog__body {
  min-width: 0;
  min-height: 0;
  overflow: auto;
}
.performance-shares-dialog .el-dialog__header,
.performance-shares-dialog .el-dialog__footer {
  flex-shrink: 0;
}
.performance-share-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin: 12px 0;
}
.performance-share-toolbar p {
  margin: 0;
  min-width: 0;
  overflow-wrap: anywhere;
}
.performance-shares-dialog .performance-share-total {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin: 16px 0 0;
  color: #00763f;
}
.performance-shares-dialog .performance-scope-meta,
.performance-shares-dialog .performance-pagination {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.performance-shares-dialog .performance-scope-meta {
  margin-bottom: 16px;
}
.performance-shares-dialog .performance-pagination {
  margin-top: 16px;
  color: #64748b;
  font-size: 12px;
}
.performance-shares-dialog small {
  display: block;
  color: #64748b;
}
.performance-shares-dialog .performance-error {
  color: #ef4444;
}
@media (max-width: 780px) {
  .performance-shares-dialog .el-dialog__body {
    padding: 16px;
  }
}
</style>
