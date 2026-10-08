<template>
  <el-dialog title="确认个人份额" :visible.sync="visible" width="900px" append-to-body custom-class="performance-dialog" :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving" @closed="reset">
    <div v-loading="loading" v-if="scope">
      <div class="performance-scope-meta"><strong>{{ scope.operation_name || scope.operation_code || '工序' }}</strong><span>{{ scope.work_order_no || ('发货单 #' + scope.shipment_id) }}</span><span>工序已完成</span><span>当前份额版本 {{ assignmentVersion || '未确认' }}</span></div>
      <el-alert v-if="pending" title="上次确认结果未返回。请重试原确认，系统会识别同一次操作。" type="warning" :closable="false" show-icon />
      <p class="performance-help">份额由本工序接单人确认。实际工时保留；不计个人绩效、明确为 0% 和尚未登记分别记录。</p>
      <el-table :data="participants" border size="small" row-key="employee_legacy_id">
        <el-table-column label="参与人员" min-width="130"><template slot-scope="s">{{ s.row.employee_name }}<el-tag v-if="s.row.employee_legacy_id === scope.owner_legacy_id" size="mini">接单人</el-tag><small v-if="!s.row.identity_exists" class="performance-error">账号资料待核对</small></template></el-table-column>
        <el-table-column prop="actual_labor_minutes" label="实际工时（分钟）" width="145" />
        <el-table-column label="是否计个人绩效" width="180"><template slot-scope="s"><el-select v-model="selections[s.row.employee_legacy_id].eligible" size="small" placeholder="尚未登记" clearable :disabled="saving || !!pending || !s.row.identity_exists"><el-option :value="true" label="计个人绩效" /><el-option :value="false" label="不计个人绩效" /></el-select></template></el-table-column>
        <el-table-column label="个人份额（%）" width="150"><template slot-scope="s"><el-input v-model="selections[s.row.employee_legacy_id].percent" size="small" placeholder="请明确填写" :disabled="saving || !!pending || !s.row.identity_exists" /></template></el-table-column>
        <el-table-column label="备注" min-width="170"><template slot-scope="s"><el-input v-model="selections[s.row.employee_legacy_id].remark" size="small" maxlength="500" :disabled="saving || !!pending || !s.row.identity_exists" /></template></el-table-column>
      </el-table>
      <div class="performance-pagination"><span>共 {{ total }} 人，已登记内容跨页保留</span><el-pagination background layout="prev, pager, next" :current-page="page" :page-size="20" :total="total" :disabled="loading || saving" @current-change="loadPage" /></div>
      <div class="performance-share-total"><span>已登记份额 {{ totals.declared }}%</span><span>计个人绩效 {{ totals.credited }}%</span><strong>剩余不计 {{ totals.remainder }}%</strong></div>
      <el-checkbox v-model="noncreditedConfirmed" :disabled="saving || !!pending">确认剩余份额不计个人绩效</el-checkbox>
      <el-input class="performance-reason" v-model="noncreditedReason" type="textarea" :rows="2" maxlength="500" placeholder="剩余不计个人绩效的原因；份额不足 100% 时必填" :disabled="saving || !!pending" />
    </div>
    <span slot="footer"><el-button :disabled="saving" @click="visible=false">关闭</el-button><el-button v-if="scope" type="success" :loading="saving" :disabled="loading || scope.status !== 'COMPLETED'" @click="confirm">{{ pending ? '重试原确认' : '确认个人份额' }}</el-button></span>
  </el-dialog>
</template>

<script>
import { getPerformanceScope, confirmPerformanceShares } from '../../../api/erp/production-performance'
import { ratioToPercent, buildPerformanceShares, performanceSelectionTotals } from '../../../utils/production-performance.mjs'

export default {
  data: () => ({ visible: false, loading: false, saving: false, sequence: 0, type: '', id: 0, scope: null, participants: [], selections: {}, total: 0, page: 1, assignmentVersion: 0, noncreditedConfirmed: false, noncreditedReason: '', pending: null, storageKey: '' }),
  computed: { totals() { return performanceSelectionTotals(Object.values(this.selections)) } },
  methods: {
    async open(type, id) {
      this.reset(); this.type = type; this.id = id; this.visible = true
      let actor = 0
      try { actor = JSON.parse(localStorage.getItem('erp_user') || '{}').legacy_id || 0 } catch (_) { /* No stored profile. */ }
      this.storageKey = 'erp_pending_performance_' + actor + '_' + type + '_' + id
      try { this.pending = JSON.parse(localStorage.getItem(this.storageKey) || 'null') } catch (_) { this.pending = null }
      await this.loadPage(1)
    },
    reset() {
      this.sequence++; this.loading = false; this.saving = false; this.scope = null; this.participants = []; this.selections = {}; this.total = 0; this.page = 1; this.assignmentVersion = 0; this.noncreditedConfirmed = false; this.noncreditedReason = ''; this.pending = null; this.storageKey = ''
    },
    async loadPage(page) {
      const sequence = ++this.sequence; this.loading = true
      try {
        const { data } = await getPerformanceScope(this.type, this.id, { page, per_page: 20 })
        if (sequence !== this.sequence || !this.visible) return
        const value = data.data
        if (!this.scope) {
          this.scope = value.scope; this.assignmentVersion = value.assignment?.version_no || 0
          const saved = this.pending || value.assignment
          for (const share of saved?.shares || []) this.$set(this.selections, share.employee_legacy_id, { employee_legacy_id: share.employee_legacy_id, eligible: share.eligible, percent: ratioToPercent(share.share_ratio), remark: share.remark || '' })
          this.noncreditedConfirmed = !!saved?.noncredited_confirmed; this.noncreditedReason = saved?.noncredited_reason || ''
        } else if (Number(value.scope.scope_version) !== Number(this.scope.scope_version) || Number(value.assignment?.version_no || 0) !== this.assignmentVersion) {
          throw new Error('工序或份额已变化，请关闭后重新打开')
        }
        this.participants = value.participants.data; this.total = value.participants.meta.total; this.page = page
        for (const person of this.participants) if (!this.selections[person.employee_legacy_id]) this.$set(this.selections, person.employee_legacy_id, { employee_legacy_id: person.employee_legacy_id, eligible: null, percent: '', remark: '' })
      } catch (error) { if (sequence === this.sequence) this.$message.error(error.userMessage || error.message) }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    async confirm() {
      if (!this.$can('production.performance.manage') || this.saving) return
      let payload = this.pending
      if (!payload) {
        try {
          payload = { client_command_id: 'performance-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12), expected_scope_version: this.scope.scope_version, expected_assignment_version: this.assignmentVersion,
            shares: buildPerformanceShares(Object.values(this.selections), this.noncreditedConfirmed, this.noncreditedReason), noncredited_confirmed: this.noncreditedConfirmed, noncredited_reason: this.noncreditedReason.trim() || null }
        } catch (error) { this.$message.error(error.message); return }
        localStorage.setItem(this.storageKey, JSON.stringify(payload)); this.pending = payload
      }
      this.saving = true
      try {
        await confirmPerformanceShares(this.type, this.id, payload)
        localStorage.removeItem(this.storageKey); this.pending = null; this.$message.success('个人份额已确认'); this.visible = false; this.$emit('confirmed')
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
