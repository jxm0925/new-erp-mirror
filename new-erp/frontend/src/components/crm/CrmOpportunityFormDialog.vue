<template>
  <el-dialog class="crm-dialog" :title="form.id ? '编辑商机' : '新建商机'" :visible.sync="visible" width="920px" append-to-body :close-on-click-modal="false" :before-close="close">
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-form v-loading="loading" label-width="110px" size="small" :disabled="saving || loading || !!error">
      <div class="crm-form-grid">
        <el-form-item label="客户" required><div class="crm-inline"><el-input :value="customer.customer_name" readonly placeholder="请选择客户" /><el-button v-if="!form.id && !sourceLocked" @click="$refs.customerPicker.open()">选择</el-button></div></el-form-item>
        <el-form-item label="来源线索"><el-input v-if="form.id || sourceLocked" :value="sourceRows.length ? leadLabel(sourceRows[0]) : '—'" disabled /><paged-record-select v-else v-model="form.source_lead_id" :key="'source-' + session" :request="loadLeads" :params="{ customer_id: form.customer_id, convertible: 1 }" :selected-rows="sourceRows" :columns="leadColumns" :label="leadLabel" title="选择来源线索" placeholder="可选：本客户未转商机的线索" :disabled="!form.customer_id || ($can && !$can('crm.lead.view'))" @select="selectLead" /></el-form-item>
        <el-form-item label="商机名称" required><el-input v-model.trim="form.name" maxlength="160" /></el-form-item>
        <el-form-item label="对接人"><el-select v-model="form.contact_id" clearable popper-class="crm-select-popper"><el-option v-for="row in contacts" :key="row.id" :value="row.id" :label="[row.contact_name,row.position].filter(Boolean).join(' / ')" /></el-select></el-form-item>
        <el-form-item label="负责人" required><paged-record-select v-model="form.owner_legacy_id" :key="'owner-' + session" :request="loadOwners" :selected-rows="ownerRows" :columns="ownerColumns" :label="ownerLabel" title="选择负责销售" placeholder="请选择负责销售" :clearable="false" @select="selectOwner" /></el-form-item>
        <el-form-item label="部门"><el-input :value="owner.department_name || ''" disabled /><small class="crm-sub">随负责人带出</small></el-form-item>
        <el-form-item label="业务线"><el-select v-model="form.business_line" clearable filterable allow-create default-first-option popper-class="crm-select-popper"><el-option v-for="item in metadata.business_lines || []" :key="item" :value="item" :label="item" /></el-select></el-form-item>
        <el-form-item :label="form.id ? '当前阶段' : '初始阶段'"><el-input :value="form.stage_label || '客户需求确认'" disabled /><small v-if="!form.id" class="crm-sub">新建统一从客户需求确认开始</small></el-form-item>
        <el-form-item label="币种"><el-select v-model="form.currency" popper-class="crm-select-popper"><el-option v-for="item in metadata.currencies || []" :key="item" :value="item" :label="item" /></el-select></el-form-item>
        <el-form-item label="预计金额"><el-input v-model="form.expected_amount" placeholder="未填写"><template slot="prepend">{{ form.currency }}</template></el-input></el-form-item>
        <el-form-item label="预计成交日期"><el-date-picker v-model="form.expected_close_date" type="date" value-format="yyyy-MM-dd" popper-class="crm-date-popper" /></el-form-item>
        <el-form-item label="下次跟进"><el-date-picker v-model="form.next_contact_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" popper-class="crm-date-popper" /></el-form-item>
        <el-form-item label="采购需求" class="crm-span-all"><el-input v-model.trim="form.demand_summary" type="textarea" :rows="2" maxlength="5000" /></el-form-item>
        <el-form-item v-if="!form.id && (metadata.abilities || {}).file" label="方案附件" class="crm-span-all"><crm-attachments ref="attachments" v-model="attachments" @busy="uploading = $event" /></el-form-item>
        <el-form-item v-if="form.id" label="修改说明" required class="crm-span-all"><el-input v-model.trim="form.reason" type="textarea" :rows="2" maxlength="500" /></el-form-item>
      </div>
    </el-form>
    <span slot="footer"><el-button size="small" :disabled="saving" @click="close">取消</el-button><el-button size="small" type="success" :loading="saving" :disabled="loading || uploading || !!error" @click="save">保存商机</el-button></span>
    <customer-picker v-if="visible" :key="session" ref="customerPicker" @select="selectCustomer" />
  </el-dialog>
</template>
<script>
import { getCrmOpportunityMetadata, getCrmOpportunity, saveCrmOpportunity, listCrmOpportunityOwners, listCrmLeads, crmError } from '@/api/erp/crm'
import { getSalesCustomer } from '@/api/erp/sales'
import { erpDateTime } from '@/utils/dateTime'
import CustomerPicker from '@/components/sales/CustomerPicker.vue'
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import CrmAttachments from './CrmAttachments.vue'
import '@/styles/crm.css'
const blank = () => ({ id: null, version: null, customer_id: null, source_lead_id: null, source_lead_version: null, name: '', contact_id: null, owner_legacy_id: null, business_line: '', currency: 'CNY', expected_amount: null, expected_close_date: null, demand_summary: '', next_contact_at: null, next_plan: '', reason: '', request_key: `opportunity-${Date.now()}-${Math.random().toString(36).slice(2)}` })
export default {
  name: 'CrmOpportunityFormDialog',
  components: { CustomerPicker, PagedRecordSelect, CrmAttachments },
  data: () => ({ visible: false, loading: false, saving: false, uploading: false, session: 0, customerSequence: 0, error: '', metadata: {}, form: blank(), customer: {}, owner: {}, contacts: [], sourceLocked: false, sourceRows: [], ownerRows: [], attachments: [], leadColumns: [{ prop: 'lead_no', label: '线索编号' }, { prop: 'demand_summary', label: '需求' }, { prop: 'owner_name', label: '负责人' }], ownerColumns: [{ prop: 'name', label: '负责销售' }, { prop: 'department_name', label: '部门' }] }),
  beforeDestroy() { this.session += 1 },
  methods: {
    loadLeads: listCrmLeads,
    loadOwners: listCrmOpportunityOwners,
    leadLabel(row) { return [row.lead_no,row.demand_summary].filter(Boolean).join(' / ') },
    ownerLabel(row) { return [row.name,row.department_name].filter(Boolean).join(' / ') },
    async open({ id = null, customer = null, lead = null } = {}) {
      const session = ++this.session
      if (this.$refs.attachments) this.$refs.attachments.reset()
      this.visible = true
      this.loading = true
      this.saving = false
      this.uploading = false
      this.error = ''
      this.form = blank()
      this.customer = {}
      this.owner = {}
      this.contacts = []
      this.sourceRows = []
      this.ownerRows = []
      this.attachments = []
      this.sourceLocked = !!lead
      try {
        const [meta, detail] = await Promise.all([getCrmOpportunityMetadata(), id ? getCrmOpportunity(id) : Promise.resolve(null)])
        if (session !== this.session || !this.visible) return
        this.metadata = meta.data.data || meta.data
        if (detail) {
          this.form = { ...blank(), ...(detail.data.data || detail.data), reason: '' }
          this.owner = { id: this.form.owner_legacy_id, name: this.form.owner_name, department_name: this.form.department_name }
          this.ownerRows = [this.owner]
          if (this.form.source_lead) this.sourceRows = [this.form.source_lead]
          await this.selectCustomer(this.form.customer || { id: this.form.customer_id, customer_name: this.form.customer_name }, true)
        } else {
          const actor = this.metadata.current_user || {}
          this.owner = actor.is_sales === false ? {} : actor
          this.ownerRows = this.owner.id ? [this.owner] : []
          this.form.owner_legacy_id = this.owner.id || null
          if (customer || lead) await this.selectCustomer(customer || { id: lead.customer_id, customer_name: lead.customer_name }, true)
          if (session !== this.session || !this.visible) return
          if (lead) this.selectLead(lead)
        }
        if (session === this.session) {
          this.form.next_contact_at = erpDateTime(this.form.next_contact_at) || null
          this.form.expected_close_date = erpDateTime(this.form.expected_close_date, 10) || null
        }
      } catch (error) { if (session === this.session) this.error = crmError(error, '商机资料加载失败') }
      finally { if (session === this.session) this.loading = false }
    },
    async selectCustomer(row, preserve = false) {
      if (!row) return
      const session = this.session
      const sequence = ++this.customerSequence
      this.customer = row
      this.form.customer_id = row.id
      this.contacts = []
      if (!preserve) { this.form.contact_id = null; this.form.source_lead_id = null; this.form.source_lead_version = null; this.sourceRows = [] }
      try {
        const { data } = await getSalesCustomer(row.id)
        if (session !== this.session || sequence !== this.customerSequence || !this.visible) return
        this.customer = data
        this.contacts = (data.contacts || []).filter(item => item.status !== 'disabled')
      } catch (error) { if (session === this.session && sequence === this.customerSequence) this.$message.error(crmError(error,'客户联系人加载失败')) }
    },
    selectLead(row) {
      this.form.source_lead_id = row ? row.id : null
      this.form.source_lead_version = row ? row.version : null
      if (!row) return
      this.sourceRows = [row]
      this.form.name = row.demand_summary || this.form.name
      this.form.demand_summary = row.demand_summary || ''
      this.form.contact_id = row.contact_id || null
      this.form.business_line = (row.business_lines || [])[0] || ''
      this.form.expected_amount = row.expected_amount
      this.form.currency = row.currency || 'CNY'
      this.form.next_contact_at = erpDateTime(row.next_contact_at) || null
      this.form.next_plan = row.next_plan || ''
      if (row.owner_legacy_id) { this.form.owner_legacy_id = row.owner_legacy_id; this.selectOwner({ id: row.owner_legacy_id, name: row.owner_name, department_name: row.department_name }) }
    },
    selectOwner(row) { if (row) { this.owner = row; this.ownerRows = [row] } },
    close() { if (this.saving) return; this.session += 1; this.visible = false; if (this.$refs.attachments) this.$refs.attachments.reset() },
    async save() {
      if (this.saving || this.loading || this.uploading || this.error) return
      const form = { ...this.form }
      if (!form.customer_id || !form.name || !form.owner_legacy_id || (form.id && !form.reason)) return this.$message.warning('请填写客户、商机名称、负责人及修改说明')
      if (form.expected_amount !== null && form.expected_amount !== '' && (!Number.isFinite(Number(form.expected_amount)) || Number(form.expected_amount) < 0)) return this.$message.warning('预计金额须为不小于零的数字')
      form.expected_amount = form.expected_amount === '' ? null : form.expected_amount
      form.files = this.attachments.map(file => ({ attachment_id: file.id, category: 'solution', status: 'current' }))
      if (form.id) { delete form.source_lead_id; delete form.source_lead_version }
      const session = this.session
      this.saving = true
      try {
        const { data } = await saveCrmOpportunity(form)
        if (session !== this.session) return
        this.visible = false
        this.$message.success('商机已保存')
        this.$emit('saved', data.data || data)
      } catch (error) { if (session === this.session) this.$message.error(crmError(error, '商机保存失败')) }
      finally { if (session === this.session) this.saving = false }
    }
  }
}
</script>
