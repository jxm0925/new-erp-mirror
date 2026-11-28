<template>
  <el-dialog class="crm-dialog" :title="form.id ? '修改跟进' : '新增跟进'" :visible.sync="visible" width="900px" append-to-body :close-on-click-modal="false" :before-close="close">
    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" show-icon />
    <el-form ref="form" :model="form" label-width="106px" size="small" :disabled="saving || loading || !!loadError">
      <div class="crm-form-grid">
        <el-form-item label="关联客户"><el-input :value="subject.customer_name" disabled /></el-form-item>
        <el-form-item v-if="subject.subject_type === 'lead'" label="关联线索"><el-input :value="subject.lead_no || subject.subject_id" disabled /></el-form-item>
        <el-form-item v-if="subject.subject_type === 'opportunity'" label="关联商机"><el-input :value="subject.opportunity_name || subject.subject_id" disabled /></el-form-item>
        <el-form-item label="对接人" required>
          <el-select popper-class="crm-select-popper" v-if="contacts.length" v-model="form.contact_id" @change="contactChanged"><el-option v-for="item in contacts" :key="item.id" :value="item.id" :label="`${item.contact_name} ${item.position || ''}`" /></el-select>
          <el-input v-else v-model.trim="form.contact_name" maxlength="80" />
        </el-form-item>
        <el-form-item label="动作类型" required class="crm-span-all"><el-radio-group v-model="form.method" class="crm-followup-actions"><el-radio-button v-for="item in methods" :key="item" :label="item">{{ item }}</el-radio-button></el-radio-group></el-form-item>
        <el-form-item label="实际联系时间" required><el-date-picker popper-class="crm-date-popper" v-model="form.contacted_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" :picker-options="contactTimeOptions" /><small class="crm-sub">不能晚于当前时间</small></el-form-item>
        <el-form-item label="内容" required class="crm-span-all"><el-input v-model.trim="form.content" type="textarea" :rows="3" maxlength="20000" /></el-form-item>
        <el-form-item label="沟通结果" required><el-select popper-class="crm-select-popper" v-model="form.result" filterable allow-create default-first-option><el-option v-for="item in results" :key="item" :value="item" :label="item" /></el-select></el-form-item>
        <el-form-item label="附件" class="crm-span-all"><crm-attachments ref="attachments" v-model="attachments" @busy="uploading = $event" /></el-form-item>
        <h3 class="crm-section-title crm-span-all">推进进度</h3>
        <el-form-item label="当前九步阶段"><el-input v-if="subject.subject_type === 'customer'" :value="form.nine_step_stage || '未成交'" disabled /><el-select v-else v-model="form.nine_step_stage" :disabled="!canChangeStage" popper-class="crm-select-popper" @change="stageChanged"><el-option v-for="item in stages" :key="item.value" :value="item.value" :label="item.label" /></el-select></el-form-item>
        <el-form-item label="所在客户池"><el-select v-model="form.customer_pool" :disabled="!canChangePool" clearable popper-class="crm-select-popper"><el-option v-for="item in pools" :key="item" :value="item" :label="item" /></el-select></el-form-item>
        <el-form-item v-if="subject.subject_type !== 'customer'" label="成交概率"><el-select v-model="form.win_probability" :disabled="!canChangeStage" filterable allow-create clearable popper-class="crm-select-popper"><el-option v-for="item in probabilities" :key="item" :value="item" :label="item" /></el-select></el-form-item>
        <el-form-item label="当前最大卡点"><el-select v-model="form.current_blocker" :disabled="!canChangeDetails" filterable clearable popper-class="crm-select-popper"><el-option v-for="item in blockerOptions" :key="item" :value="item" :label="item" /></el-select></el-form-item>
        <el-form-item label="客户承诺" class="crm-span-all"><el-input v-model.trim="form.customer_promise" :disabled="!canChangeDetails" maxlength="2000" placeholder="选填，如周五确认图纸后付定金" /></el-form-item>
        <el-form-item label="预计打款日期"><el-date-picker v-model="form.expected_pay_date" :disabled="!canChangeStage" type="date" value-format="yyyy-MM-dd" popper-class="crm-date-popper" /></el-form-item>
        <h3 class="crm-section-title crm-span-all">下一步安排</h3>
        <el-form-item label="排期方式" class="crm-span-all"><el-radio-group v-model="form.followup_schedule_mode" class="crm-followup-actions" @change="scheduleChanged"><el-radio-button label="scheduled">已约好下次联系</el-radio-button><el-radio-button label="nurture">暂不排期·长期培育</el-radio-button><el-radio-button label="completed">本轮已办结</el-radio-button></el-radio-group></el-form-item>
        <el-form-item v-if="form.followup_schedule_mode === 'scheduled'" label="下次联系时间" required><el-date-picker popper-class="crm-date-popper" v-model="form.next_contact_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" /></el-form-item>
        <el-form-item v-if="form.followup_schedule_mode === 'scheduled'" label="下次最小动作" required class="crm-span-all"><el-input v-model.trim="form.next_plan" type="textarea" :rows="2" maxlength="2000" /></el-form-item>
        <el-form-item v-if="form.id" label="修改原因" required class="crm-span-all"><el-input v-model.trim="form.reason" type="textarea" :rows="2" maxlength="500" /></el-form-item>
      </div>
    </el-form>
    <div class="crm-note"><i class="el-icon-info" /> 保存后同步当前进度；仅已约好下次联系的安排进入待办。</div>
    <span slot="footer"><el-button size="small" :disabled="saving" @click="close">取消</el-button><el-button size="small" type="success" :loading="saving" :disabled="loading || uploading || !!loadError" @click="save">保存跟进</el-button></span>
  </el-dialog>
</template>
<script>
import { getCrmLead, getCrmOpportunity, getCrmProgressCatalog, saveCrmFollowup, crmError } from '@/api/erp/crm'
import { getSalesCustomer } from '@/api/erp/sales'
import { erpDateTime } from '@/utils/dateTime'
import CrmAttachments from './CrmAttachments.vue'
import { poolNames, stageOptions } from '@/utils/crmProgress'
import { probabilities, applyCombatDictionaries, actions, blockers } from './combatTemplates'
import '@/styles/crm.css'
const blank = () => ({ id: null, version: null, contact_id: null, contact_name: '', method: '', contacted_at: '', content: '', result: '', next_contact_at: '', next_plan: '', reason: '', customer_pool: '', nine_step_stage: '', win_probability: '', current_blocker: '', customer_promise: '', expected_pay_date: '', followup_schedule_mode: 'scheduled' })
export default {
  name: 'CrmFollowupDialog',
  components: { CrmAttachments },
  data: () => ({ loadError: '', visible: false, saving: false, loading: false, uploading: false, session: 0, subject: {}, metadata: {}, target: {}, probabilities, contacts: [], form: blank(), attachments: [], methods: actions, results: ['已联系', '未接通', '已约见', '待回复', '继续跟进'], contactTimeOptions: { disabledDate: date => date.getTime() > Date.now() } }),
  computed: {
    targetAbilities() { return this.target.abilities || {} },
    canChangePool() { return this.subject.subject_type === 'customer' ? !!this.targetAbilities.edit : this.subject.subject_type === 'opportunity' ? !!this.targetAbilities.stage : !!this.targetAbilities.pool },
    canChangeStage() { return this.subject.subject_type === 'opportunity' ? !!this.targetAbilities.stage : this.subject.subject_type === 'lead' && !!this.targetAbilities.progress },
    canChangeDetails() { return this.subject.subject_type === 'customer' ? !!this.targetAbilities.edit : !!(this.targetAbilities.edit || this.targetAbilities.follow) },
    pools() { return poolNames(this.metadata, this.subject.subject_type, this.form.customer_pool) },
    stages() { return stageOptions(this.metadata, this.subject.subject_type) },
    blockerOptions() { return [...new Set([...blockers, this.form.current_blocker].filter(Boolean))] }
  },
  beforeDestroy() { this.session += 1 },
  methods: {
    async open(subject, row) {
      const session = ++this.session
      this.subject = { ...subject }
      this.target = { ...subject }
      this.metadata = {}
      this.form = { ...blank(), ...(row || {}), reason: '' }
      this.form.contacted_at = erpDateTime(this.form.contacted_at) || erpDateTime(new Date())
      this.form.next_contact_at = erpDateTime(this.form.next_contact_at)
      this.attachments = row ? [...(row.attachments || [])] : []
      this.contacts = [...(subject.contacts || [])]
      this.form.contact_name = this.form.contact_name || subject.contact_name || ''
      this.visible = true
      this.loadError = ''
      this.saving = false
      this.loading = true
      this.uploading = false
      this.$nextTick(() => { if (this.$refs.attachments) this.$refs.attachments.reset() })
      try {
        const request = subject.subject_type === 'lead' ? getCrmLead : subject.subject_type === 'opportunity' ? getCrmOpportunity : getSalesCustomer
        const [meta, detail] = await Promise.all([
          getCrmProgressCatalog(),
          request(subject.subject_id)
        ])
        if (session !== this.session) return
        this.metadata = meta.data.data || meta.data
        applyCombatDictionaries(this.metadata)
        this.target = detail.data.data || detail.data
        if (!this.contacts.length) this.contacts = (this.target.contacts || []).filter(item => item.status !== 'disabled')
        // 修改既有沟通也以对象当前进度预填，避免旧快照把今天的池与阶段回退。
        this.form.customer_pool = this.target.customer_pool || ''
        this.form.nine_step_stage = (subject.subject_type === 'opportunity' ? this.target.stage : subject.subject_type === 'customer' ? this.target.lifecycle_stage : this.target.nine_step_stage) || ''
        this.form.win_probability = this.target.win_probability || ''
        this.form.current_blocker = this.target.current_blocker || ''
        this.form.customer_promise = this.target.customer_promise || ''
        this.form.expected_pay_date = erpDateTime(this.target.expected_deal_date || this.target.expected_close_date, 10) || ''
        this.form.followup_schedule_mode = row ? (row.followup_schedule_mode || (row.next_contact_at ? 'scheduled' : 'nurture')) : (this.target.next_contact_at ? 'scheduled' : 'nurture')
        if (!row && !this.form.next_contact_at) { this.form.next_contact_at = erpDateTime(this.target.next_contact_at); this.form.next_plan = this.target.next_plan || '' }
        this.form.contact_name = this.form.contact_name || this.target.contact_name || ''
        this.scheduleChanged()
        const customerId = subject.customer_id || (subject.subject_type === 'customer' ? subject.subject_id : null)
        if (customerId && !subject.delegated && !this.contacts.length && (!this.$can || this.$can('sales.customer'))) {
          const { data } = await getSalesCustomer(customerId)
          if (session === this.session) this.contacts = (data.contacts || []).filter(item => item.status !== 'disabled')
        }
      } catch (error) {
        if (session === this.session) this.loadError = crmError(error, '跟进资料加载失败，请关闭后重试')
      } finally { if (session === this.session) this.loading = false }
    },
    stageChanged(value) { const stage = this.stages.find(item => item.value === value); if (stage && stage.probability) this.form.win_probability = stage.probability },
    scheduleChanged() { if (this.form.followup_schedule_mode !== 'scheduled') { this.form.next_contact_at = ''; this.form.next_plan = '' } },
    contactChanged(id) { this.form.contact_name = (this.contacts.find(item => item.id === id) || {}).contact_name || '' },
    close() { if (this.saving) return; this.session += 1; this.visible = false; if (this.$refs.attachments) this.$refs.attachments.reset() },
    async save() {
      if (this.saving || this.uploading || this.loading || this.loadError) return
      if (!this.form.contact_name || !this.form.method || !this.form.contacted_at || !this.form.content || !this.form.result || (this.form.followup_schedule_mode === 'scheduled' && (!this.form.next_contact_at || !this.form.next_plan)) || (this.form.id && !this.form.reason)) return this.$message.warning('请填写完整跟进信息')
      if (new Date(this.form.contacted_at).getTime() > Date.now()) return this.$message.warning('实际联系时间不能晚于当前时间')
      if (this.form.followup_schedule_mode === 'scheduled' && new Date(this.form.next_contact_at).getTime() <= new Date(this.form.contacted_at).getTime()) return this.$message.warning('下次联系时间必须晚于实际联系时间')
      const session = this.session
      const subject = { ...this.subject }
      this.scheduleChanged()
      const payload = { ...this.form, stage: subject.subject_type === 'opportunity' ? this.form.nine_step_stage : undefined, business_version: this.target.version || this.target.business_version || this.target.crm_version, subject_type: subject.subject_type, subject_id: subject.subject_id, attachment_ids: this.attachments.map(file => file.id) }
      const currentStage = subject.subject_type === 'opportunity' ? this.target.stage : subject.subject_type === 'customer' ? this.target.lifecycle_stage : this.target.nine_step_stage
      const currentProgress = { customer_pool: this.target.customer_pool, nine_step_stage: currentStage, win_probability: this.target.win_probability, current_blocker: this.target.current_blocker, customer_promise: this.target.customer_promise, expected_pay_date: erpDateTime(this.target.expected_deal_date || this.target.expected_close_date, 10) }
      // 只提交用户实际改动的推进字段；普通沟通不能因回显空串或旧状态取得额外写权限。
      for (const [field, value] of Object.entries(currentProgress)) {
        if (String(payload[field] || '') === String(value || '')) {
          delete payload[field]
          if (field === 'nine_step_stage') delete payload.stage
        }
      }
      if (subject.subject_type === 'customer' && !this.form.nine_step_stage) { delete payload.nine_step_stage; delete payload.stage; delete payload.win_probability }
      this.saving = true
      try {
        const { data } = await saveCrmFollowup(payload)
        if (session !== this.session) return
        this.visible = false
        this.$message.success('跟进已保存')
        this.$emit('saved', data.data || data)
      } catch (error) { if (session === this.session) this.$message.error(crmError(error, '跟进保存失败')) } finally { if (session === this.session) this.saving = false }
    }
  }
}
</script>

<style scoped>
.crm-followup-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.crm-followup-actions ::v-deep .el-radio-button__inner {
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  box-shadow: none;
}
.crm-followup-actions ::v-deep .el-radio-button__orig-radio:checked + .el-radio-button__inner {
  background-color: #008b4b;
  color: #ffffff;
  border-color: #008b4b;
}
</style>
