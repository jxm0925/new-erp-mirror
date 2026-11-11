<template>
  <el-dialog class="crm-dialog" :title="processing ? '核实客户身份' : '客户身份需要核实'" :visible.sync="visible" :width="processing ? '1100px' : '780px'" append-to-body :close-on-click-modal="false" :before-close="close">
    <div v-loading="loading">
      <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
      <div v-if="!processing" class="crm-note warning">疑似客户需由负责部门核实后再使用；本次申请不会自动合并或覆盖客户资料。</div>
      <h3 class="crm-section-title">{{ processing ? '本次线索' : '本次录入' }}</h3>
      <el-descriptions :column="2" border size="small">
        <el-descriptions-item label="客户">{{ snapshot.customer_name || '—' }}</el-descriptions-item>
        <el-descriptions-item label="平台买家ID">{{ snapshot.platform_buyer_id || '—' }}</el-descriptions-item>
        <el-descriptions-item label="手机">{{ (snapshot.phones || []).join('、') || snapshot.mobile || '—' }}</el-descriptions-item>
        <el-descriptions-item label="申请人">{{ review.applicant_name || request.applicant_name || '当前登录人员' }}</el-descriptions-item>
        <el-descriptions-item label="核实部门">{{ review.review_department_name || request.review_department_name || '由系统按匹配客户所属部门指定' }}</el-descriptions-item>
        <el-descriptions-item v-if="processing" label="申请说明">{{ review.application_note || '—' }}</el-descriptions-item>
      </el-descriptions>
      <template v-if="matches.length">
        <h3 class="crm-section-title">匹配到的客户</h3>
        <el-table :data="matches" size="small">
          <el-table-column v-if="processing && review.can_resolve && review.purpose !== 'collaboration'" width="48"><template slot-scope="{row}"><el-radio v-model="resolvedCustomerId" :label="row.id" :disabled="row.visible === false">&nbsp;</el-radio></template></el-table-column>
          <el-table-column label="客户" min-width="180"><template slot-scope="{row}"><b>{{ row.visible === false ? '其他部门客户' : row.customer_name }}</b><small class="crm-sub">{{ row.visible === false ? '详情无权查看' : row.customer_code }}</small></template></el-table-column>
          <el-table-column label="匹配依据" min-width="150"><template slot-scope="{row}"><el-tag v-for="label in row.reason_labels" :key="label" size="mini" type="warning" class="crm-tag">{{ label }}</el-tag></template></el-table-column>
          <el-table-column label="所属部门 / 负责人" min-width="170"><template slot-scope="{row}">{{ row.owner_department_name || '—' }} / {{ row.visible === false ? '无权查看' : (row.owner_name || '—') }}</template></el-table-column>
          <el-table-column label="客户资料" min-width="200"><template slot-scope="{row}">{{ row.visible === false ? '需该部门确认，不在本次处理中' : [row.full_address, row.contact_phone].filter(Boolean).join(' · ') || '—' }}</template></el-table-column>
        </el-table>
      </template>
      <el-form label-width="96px" size="small" class="review-form" :disabled="saving || loading">
        <el-form-item v-if="processing && review.can_resolve" label="处理结果" required>
          <el-radio-group v-model="decision"><el-radio label="linked">{{ review.purpose === 'collaboration' ? '同意添加协作' : '关联到选中客户' }}</el-radio><el-radio label="returned">不是同一人，退回修改</el-radio></el-radio-group>
        </el-form-item>
        <el-form-item :label="processing ? '处理说明' : '申请说明'" :required="!processing || review.can_resolve"><el-input v-model.trim="note" type="textarea" :rows="3" maxlength="500" :readonly="processing && !review.can_resolve" /></el-form-item>
      </el-form>
      <div v-if="processing" class="crm-note">匹配客户只保留为候选，不自动合并；关联线索后进入待分配。</div>
    </div>
    <span slot="footer"><el-button size="small" :disabled="saving" @click="close">{{ processing ? '取消' : '返回修改' }}</el-button><el-button v-if="!processing || review.can_resolve" type="success" size="small" :loading="saving" :disabled="loading || !!error" @click="save">{{ processing ? '确认核实' : '提交核实申请' }}</el-button></span>
  </el-dialog>
</template>
<script>
import { createCrmDuplicateReview, getCrmDuplicateReview, resolveCrmDuplicateReview, crmError } from '@/api/erp/crm'
import '@/styles/crm.css'
export default {
  name: 'CrmIdentityReviewDialog',
  data: () => ({ visible: false, processing: false, loading: false, saving: false, session: 0, review: {}, request: {}, note: '', decision: 'linked', resolvedCustomerId: null, error: '' }),
  computed: {
    snapshot() { return (this.processing ? this.review.input_snapshot : this.request.input_snapshot) || {} },
    matches() { return (this.processing ? this.review.matches : this.request.matches) || [] }
  },
  beforeDestroy() { this.session += 1 },
  methods: {
    openRequest(request) {
      this.session += 1
      this.processing = false
      this.request = { purpose: 'duplicate', subject_type: 'customer', subject_id: null, ...request }
      this.review = {}
      this.note = request.application_note || ''
      this.saving = false
      this.loading = false
      this.error = ''
      this.visible = true
    },
    async openReview(review) {
      const session = ++this.session
      this.processing = true
      this.review = {}
      this.note = ''
      this.decision = 'linked'
      this.resolvedCustomerId = null
      this.saving = false
      this.loading = true
      this.error = ''
      this.visible = true
      try {
        const { data } = await getCrmDuplicateReview(typeof review === 'object' ? review.id : review)
        if (session !== this.session) return
        this.review = data.data || data
        const candidates = (this.review.matches || []).filter(item => item.visible !== false)
        this.resolvedCustomerId = candidates.length === 1 ? candidates[0].id : null
        if (!this.review.can_resolve) this.note = this.review.note || this.review.application_note || ''
      } catch (error) { if (session === this.session) this.error = crmError(error, '核实申请加载失败') } finally { if (session === this.session) this.loading = false }
    },
    close() { if (this.saving) return; this.session += 1; this.visible = false },
    async save() {
      if (this.saving || this.loading || this.error) return
      if (!this.note) return this.$message.warning('请填写说明')
      if (this.processing && this.decision === 'linked' && this.review.purpose !== 'collaboration' && !this.resolvedCustomerId) return this.$message.warning('请选择核实后的客户')
      const session = this.session
      this.saving = true
      try {
        const response = this.processing
          ? await resolveCrmDuplicateReview(this.review.id, { decision: this.decision, resolved_customer_id: this.resolvedCustomerId, note: this.note, expected_version: this.review.version })
          : await createCrmDuplicateReview({ ...this.request, application_note: this.note })
        if (session !== this.session) return
        this.visible = false
        this.$message.success(this.processing ? '核实已处理' : '核实申请已提交')
        this.$emit('saved', response.data.data || response.data)
      } catch (error) { if (session === this.session) this.$message.error(crmError(error, '核实申请保存失败')) } finally { if (session === this.session) this.saving = false }
    }
  }
}
</script>
<style scoped>
.review-form { margin-top: 16px; }
</style>
