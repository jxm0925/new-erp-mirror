<template>
  <el-dialog class="crm-dialog" :title="form.id ? '修改跟进' : '新增跟进'" :visible.sync="visible" width="900px" append-to-body :close-on-click-modal="false" :before-close="close">
    <el-form ref="form" :model="form" label-width="106px" size="small" :disabled="saving || loading">
      <div class="crm-form-grid">
        <el-form-item label="关联客户"><el-input :value="subject.customer_name" disabled /></el-form-item>
        <el-form-item v-if="subject.subject_type === 'lead'" label="关联线索"><el-input :value="subject.lead_no || subject.subject_id" disabled /></el-form-item>
        <el-form-item label="对接人" required>
          <el-select popper-class="crm-select-popper" v-if="contacts.length" v-model="form.contact_id" @change="contactChanged"><el-option v-for="item in contacts" :key="item.id" :value="item.id" :label="`${item.contact_name} ${item.position || ''}`" /></el-select>
          <el-input v-else v-model.trim="form.contact_name" maxlength="80" />
        </el-form-item>
        <el-form-item label="联系方式" required><el-select popper-class="crm-select-popper" v-model="form.method"><el-option v-for="item in methods" :key="item" :value="item" :label="item" /></el-select></el-form-item>
        <el-form-item label="实际联系时间" required><el-date-picker popper-class="crm-date-popper" v-model="form.contacted_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" :picker-options="contactTimeOptions" /><small class="crm-sub">不能晚于当前时间</small></el-form-item>
        <el-form-item label="内容" required class="crm-span-all"><el-input v-model.trim="form.content" type="textarea" :rows="3" maxlength="20000" /></el-form-item>
        <el-form-item label="沟通结果" required><el-select popper-class="crm-select-popper" v-model="form.result" filterable allow-create default-first-option><el-option v-for="item in results" :key="item" :value="item" :label="item" /></el-select></el-form-item>
        <el-form-item label="下次联系时间" required><el-date-picker popper-class="crm-date-popper" v-model="form.next_contact_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" /></el-form-item>
        <el-form-item label="下次计划" required class="crm-span-all"><el-input v-model.trim="form.next_plan" type="textarea" :rows="2" maxlength="2000" /></el-form-item>
        <el-form-item label="附件" class="crm-span-all"><crm-attachments ref="attachments" v-model="attachments" @busy="uploading = $event" /></el-form-item>
        <el-form-item v-if="form.id" label="修改原因" required class="crm-span-all"><el-input v-model.trim="form.reason" type="textarea" :rows="2" maxlength="500" /></el-form-item>
      </div>
    </el-form>
    <div class="crm-note"><i class="el-icon-info" /> 保存后更新最近联系时间，并按下次联系时间生成待办；修改客户资料不会刷新最近联系。</div>
    <span slot="footer"><el-button size="small" :disabled="saving" @click="close">取消</el-button><el-button size="small" type="success" :loading="saving" :disabled="loading || uploading" @click="save">保存跟进</el-button></span>
  </el-dialog>
</template>
<script>
import { saveCrmFollowup, crmError } from '@/api/erp/crm'
import { getSalesCustomer } from '@/api/erp/sales'
import { erpDateTime } from '@/utils/dateTime'
import CrmAttachments from './CrmAttachments.vue'
import '@/styles/crm.css'
const blank = () => ({ id: null, version: null, contact_id: null, contact_name: '', method: '', contacted_at: '', content: '', result: '', next_contact_at: '', next_plan: '', reason: '' })
export default {
  name: 'CrmFollowupDialog',
  components: { CrmAttachments },
  data: () => ({ visible: false, saving: false, loading: false, uploading: false, session: 0, subject: {}, contacts: [], form: blank(), attachments: [], methods: ['电话', '微信', '面谈', '邮件', '平台沟通', '其他'], results: ['已联系', '未接通', '已约见', '待回复', '继续跟进'], contactTimeOptions: { disabledDate: date => date.getTime() > Date.now() } }),
  beforeDestroy() { this.session += 1 },
  methods: {
    async open(subject, row) {
      const session = ++this.session
      this.subject = { ...subject }
      this.form = { ...blank(), ...(row || {}), reason: '' }
      this.form.contacted_at = erpDateTime(this.form.contacted_at)
      this.form.next_contact_at = erpDateTime(this.form.next_contact_at)
      this.attachments = row ? [...(row.attachments || [])] : []
      this.contacts = [...(subject.contacts || [])]
      this.form.contact_name = this.form.contact_name || subject.contact_name || ''
      this.visible = true
      this.saving = false
      this.loading = false
      this.uploading = false
      this.$nextTick(() => { if (this.$refs.attachments) this.$refs.attachments.reset() })
      const customerId = subject.customer_id || (subject.subject_type === 'customer' ? subject.subject_id : null)
      if (!customerId || this.contacts.length || (this.$can && !this.$can('sales.customer'))) return
      this.loading = true
      try {
        const { data } = await getSalesCustomer(customerId)
        if (session !== this.session) return
        this.contacts = (data.contacts || []).filter(item => item.status !== 'disabled')
      } catch (error) {
        if (session === this.session) this.$message.error(crmError(error, '联系人加载失败'))
      } finally { if (session === this.session) this.loading = false }
    },
    contactChanged(id) { this.form.contact_name = (this.contacts.find(item => item.id === id) || {}).contact_name || '' },
    close() { if (this.saving) return; this.session += 1; this.visible = false; if (this.$refs.attachments) this.$refs.attachments.reset() },
    async save() {
      if (this.saving || this.uploading || this.loading) return
      if (!this.form.contact_name || !this.form.method || !this.form.contacted_at || !this.form.content || !this.form.result || !this.form.next_contact_at || !this.form.next_plan || (this.form.id && !this.form.reason)) return this.$message.warning('请填写完整跟进信息')
      if (new Date(this.form.contacted_at).getTime() > Date.now()) return this.$message.warning('实际联系时间不能晚于当前时间')
      if (new Date(this.form.next_contact_at).getTime() <= new Date(this.form.contacted_at).getTime()) return this.$message.warning('下次联系时间必须晚于实际联系时间')
      const session = this.session
      this.saving = true
      try {
        const { data } = await saveCrmFollowup({ ...this.form, subject_type: this.subject.subject_type, subject_id: this.subject.subject_id, attachment_ids: this.attachments.map(file => file.id) })
        if (session !== this.session) return
        this.visible = false
        this.$message.success('跟进已保存')
        this.$emit('saved', data.data || data)
      } catch (error) { if (session === this.session) this.$message.error(crmError(error, '跟进保存失败')) } finally { if (session === this.session) this.saving = false }
    }
  }
}
</script>
