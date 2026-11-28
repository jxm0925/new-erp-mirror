<template>
  <el-dialog class="crm-dialog" :title="form.id ? '编辑商机' : '新建商机'" :visible.sync="visible" width="920px" append-to-body :close-on-click-modal="false" :before-close="close">
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-form v-loading="loading || customerLoading" label-width="110px" size="small" :disabled="saving || loading || customerLoading || !!error">
      <div class="crm-form-grid">
        <el-form-item label="客户" required><div class="crm-picker-inline"><el-input :value="customer.customer_name" readonly placeholder="请选择客户" /><el-button v-if="!form.id && !sourceLocked" @click="$refs.customerPicker.open()">选择</el-button></div></el-form-item>
        <el-form-item label="来源线索"><el-input v-if="form.id || sourceLocked" :value="sourceRows.length ? leadLabel(sourceRows[0]) : '—'" disabled /><paged-record-select v-else v-model="form.source_lead_id" :key="'source-' + session" :request="loadLeads" :params="{ customer_id: form.customer_id, convertible: 1 }" :selected-rows="sourceRows" :columns="leadColumns" :label="leadLabel" title="选择来源线索" placeholder="可选：本客户未转商机的线索" :disabled="!form.customer_id || ($can && !$can('crm.lead.view'))" @select="selectLead" /></el-form-item>
        <el-form-item label="商机名称" required><el-input v-model.trim="form.name" maxlength="160" /></el-form-item>
        <el-form-item label="对接人"><el-select v-model="form.contact_id" clearable popper-class="crm-select-popper"><el-option v-for="row in contacts" :key="row.id" :value="row.id" :label="[row.contact_name,row.position].filter(Boolean).join(' / ')" /></el-select></el-form-item>
        <el-form-item label="负责人" required><el-input v-if="ownerLocked" :value="ownerLabel(owner)" disabled /><paged-record-select v-else v-model="form.owner_legacy_id" :key="'owner-' + session" :request="loadOwners" :selected-rows="ownerRows" :columns="ownerColumns" :label="ownerLabel" title="选择负责销售" placeholder="请选择负责销售" :clearable="false" @select="selectOwner" /><small v-if="ownerLocked" class="crm-sub">代班办理，负责人保持原销售</small></el-form-item>
        <el-form-item label="部门"><el-input :value="owner.department_name || ''" disabled /><small class="crm-sub">随负责人带出</small></el-form-item>
        <el-form-item label="业务线"><el-select v-model="form.business_line" clearable filterable popper-class="crm-select-popper" @change="businessLineEdited = true"><el-option v-for="item in businessLineOptions" :key="item" :value="item" :label="item" /></el-select></el-form-item>
        <el-form-item :label="form.id ? '当前阶段' : '初始阶段'"><el-input :value="(stages.find(item => item.value === form.stage) || {}).label || form.stage_label || '第4步｜产品方案'" disabled /></el-form-item>
        <el-form-item label="客户池"><el-select v-model="form.customer_pool" clearable popper-class="crm-select-popper"><el-option v-for="item in pools" :key="item" :value="item" :label="item" /></el-select></el-form-item>
        <el-form-item label="贸易类型"><el-select v-model="form.trade_type" clearable popper-class="crm-select-popper"><el-option v-for="item in metadata.trade_types || []" :key="item" :value="item" :label="item" /></el-select></el-form-item>
        <el-form-item label="币种"><el-select v-model="form.currency" popper-class="crm-select-popper"><el-option v-for="item in metadata.currencies || []" :key="item" :value="item" :label="item" /></el-select></el-form-item>
        <el-form-item label="预计金额"><erp-money-input v-model="form.expected_amount" placeholder="未填写"><template slot="prepend">{{ form.currency }}</template></erp-money-input></el-form-item>
        <el-form-item label="预计成交日期"><el-date-picker v-model="form.expected_close_date" type="date" value-format="yyyy-MM-dd" popper-class="crm-date-popper" /></el-form-item>
        <el-form-item label="下次跟进"><el-date-picker v-model="form.next_contact_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" popper-class="crm-date-popper" /></el-form-item>
        <el-form-item label="采购需求" class="crm-span-all"><el-input v-model.trim="form.demand_summary" type="textarea" :rows="2" maxlength="5000" /></el-form-item>
        <el-form-item v-if="!form.id && (metadata.abilities || {}).file" label="客户来图 / 需求" class="crm-span-all"><crm-attachments ref="attachments" v-model="attachments" @busy="uploading = $event" /></el-form-item>
        <el-form-item v-if="form.id" label="修改说明" required class="crm-span-all"><el-input v-model.trim="form.reason" type="textarea" :rows="2" maxlength="500" /></el-form-item>
      </div>
    </el-form>
    <span slot="footer"><el-button size="small" :disabled="saving" @click="close">取消</el-button><el-button size="small" type="success" :loading="saving" :disabled="loading || customerLoading || uploading || !!error" @click="save">保存商机</el-button></span>
    <customer-picker v-if="visible" :key="session" ref="customerPicker" @select="selectCustomer" />
  </el-dialog>
</template>
<script>
import { getCrmOpportunityMetadata, getCrmOpportunity, saveCrmOpportunity, listCrmOpportunityOwners, listCrmLeads, crmError } from '@/api/erp/crm'
import { getSalesCustomer } from '@/api/erp/sales'
import { poolNames, inferTradeType, stageOptions } from '@/utils/crmProgress'
import { erpDateTime } from '@/utils/dateTime'
import CustomerPicker from '@/components/sales/CustomerPicker.vue'
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import CrmAttachments from './CrmAttachments.vue'
import '@/styles/crm.css'
const blank = () => ({ id: null, version: null, customer_id: null, source_lead_id: null, source_lead_version: null, name: '', contact_id: null, owner_legacy_id: null, business_line: '', customer_pool: '48小时推进池', stage: 'step_4_solution', trade_type: '', currency: 'CNY', expected_amount: null, expected_close_date: null, demand_summary: '', next_contact_at: null, next_plan: '', reason: '', request_key: `opportunity-${Date.now()}-${Math.random().toString(36).slice(2)}` })
export default {
  name: 'CrmOpportunityFormDialog',
  components: { CustomerPicker, PagedRecordSelect, CrmAttachments },
  data: () => ({ visible: false, loading: false, customerLoading: false, saving: false, uploading: false, session: 0, customerSequence: 0, error: '', metadata: {}, form: blank(), customer: {}, owner: {}, contacts: [], sourceLocked: false, sourceRows: [], ownerRows: [], attachments: [], businessLineEdited: false, automaticBusinessLine: '', leadColumns: [{ prop: 'lead_no', label: '线索编号' }, { prop: 'demand_summary', label: '需求' }, { prop: 'owner_name', label: '负责人' }], ownerColumns: [{ prop: 'name', label: '负责销售' }, { prop: 'department_name', label: '部门' }] }),
  computed: {
    pools() { return poolNames(this.metadata, 'opportunity', this.form.customer_pool) },
    stages() { return stageOptions(this.metadata, 'opportunity') },
    delegatedCustomer() { return this.customer.relation === 'delegate' || (this.customer.abilities || {}).relation === 'delegate' },
    ownerLocked() { return !!this.form.delegated || !!(this.sourceRows[0] && this.sourceRows[0].delegated) || this.delegatedCustomer },
    sourceBusinessLines() {
      const normalize = rows => [...new Set((Array.isArray(rows) ? rows : []).filter(item => typeof item === 'string').map(item => item.trim()).filter(Boolean))]
      const leadLines = normalize((this.sourceRows[0] || {}).customer_business_lines)
      return leadLines.length ? leadLines : normalize(this.customer.business_lines)
    },
    businessLineOptions() { return [...new Set([...(this.metadata.business_lines || []), ...this.sourceBusinessLines, this.form.business_line].filter(Boolean))] }
  },
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
      this.customerLoading = false
      this.saving = false
      this.uploading = false
      this.error = ''
      this.form = blank()
      this.businessLineEdited = false
      this.automaticBusinessLine = ''
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
          this.owner = this.form.delegated_owner || { id: this.form.owner_legacy_id, name: this.form.owner_name, department_name: this.form.department_name }
          this.ownerRows = [this.owner]
          if (this.form.source_lead) this.sourceRows = [this.form.source_lead]
          if (this.form.delegated) {
            this.customer = this.form.customer || { id: this.form.customer_id, customer_name: this.form.customer_name }
            this.contacts = this.form.contacts || []
          } else await this.selectCustomer(this.form.customer || { id: this.form.customer_id, customer_name: this.form.customer_name }, true)
        } else {
          const actor = this.metadata.current_user || {}
          this.owner = actor.is_sales === false ? {} : actor
          this.ownerRows = this.owner.id ? [this.owner] : []
          this.form.owner_legacy_id = this.owner.id || null
          this.form.trade_type = inferTradeType(this.owner, this.metadata.trade_types || [])
          if (lead && (lead.delegated || (this.$can && !this.$can('sales.customer')))) {
            this.form.customer_id = lead.customer_id
            this.customer = { ...lead.customer, id: lead.customer_id, customer_name: lead.customer_name }
            this.contacts = lead.contact_id ? [{ id: lead.contact_id, contact_name: lead.contact_name }] : []
          } else if (customer || lead) await this.selectCustomer(customer || { ...lead.customer, id: lead.customer_id, customer_name: lead.customer_name }, true)
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
      const ownerWasLocked = this.ownerLocked
      this.customerLoading = true
      this.customer = row
      this.form.customer_id = row.id
      this.contacts = []
      if (!preserve) { this.form.contact_id = null; this.form.source_lead_id = null; this.form.source_lead_version = null; this.sourceRows = [] }
      this.prefillBusinessLine()
      try {
        const { data } = await getSalesCustomer(row.id)
        if (session !== this.session || sequence !== this.customerSequence || !this.visible) return
        this.customer = data
        this.contacts = (data.contacts || []).filter(item => item.status !== 'disabled')
        this.prefillBusinessLine()
        if (this.delegatedCustomer) {
          this.owner = { id: data.owner_legacy_id, name: data.owner_name, department_name: data.owner_department_name }
          this.ownerRows = [this.owner]
          this.form.owner_legacy_id = this.owner.id
        } else if (!preserve && ownerWasLocked) {
          this.owner = this.metadata.current_user && this.metadata.current_user.is_sales !== false ? this.metadata.current_user : {}
          this.ownerRows = this.owner.id ? [this.owner] : []
          this.form.owner_legacy_id = this.owner.id || null
        }
      } catch (error) { if (session === this.session && sequence === this.customerSequence) this.$message.error(crmError(error,'客户联系人加载失败')) }
      finally { if (session === this.session && sequence === this.customerSequence) this.customerLoading = false }
    },
    selectLead(row) {
      this.form.source_lead_id = row ? row.id : null
      this.form.source_lead_version = row ? row.version : null
      this.sourceRows = row ? [row] : []
      this.prefillBusinessLine()
      if (!row) return
      this.form.name = row.demand_summary || this.form.name
      this.form.demand_summary = row.demand_summary || ''
      this.form.contact_id = row.contact_id || null
      this.form.expected_amount = row.expected_amount
      this.form.currency = row.currency || 'CNY'
      this.form.trade_type = row.trade_type || inferTradeType({ department_name: row.department_name }, this.metadata.trade_types || [])
      this.form.next_contact_at = erpDateTime(row.next_contact_at) || null
      this.form.next_plan = row.next_plan || ''
      if (row.owner_legacy_id) { this.form.owner_legacy_id = row.owner_legacy_id; this.selectOwner({ id: row.owner_legacy_id, name: row.owner_name, department_name: row.department_name, trade_type: row.trade_type }) }
    },
    prefillBusinessLine() {
      if (this.form.id || this.businessLineEdited) return
      // 切换来源只替换自动带入值，人工选择和主动清空始终保留。
      if (this.automaticBusinessLine && this.form.business_line === this.automaticBusinessLine) this.form.business_line = ''
      this.automaticBusinessLine = ''
      if (this.form.business_line) return
      if (this.sourceBusinessLines.length === 1) {
        this.form.business_line = this.sourceBusinessLines[0]
        this.automaticBusinessLine = this.sourceBusinessLines[0]
      }
    },
    selectOwner(row) { if (row) { this.owner = row; this.ownerRows = [row]; this.form.trade_type = row.trade_type || inferTradeType(row, this.metadata.trade_types || []) || this.form.trade_type } },
    close() { if (this.saving) return; this.session += 1; this.visible = false; if (this.$refs.attachments) this.$refs.attachments.reset() },
    async save() {
      if (this.saving || this.loading || this.customerLoading || this.uploading || this.error) return
      const form = { ...this.form }
      if (!form.customer_id || !form.name || !form.owner_legacy_id || (form.id && !form.reason)) return this.$message.warning('请填写客户、商机名称、负责人及修改说明')
      if (form.expected_amount !== null && form.expected_amount !== '' && (!Number.isFinite(Number(form.expected_amount)) || Number(form.expected_amount) < 0)) return this.$message.warning('预计金额须为不小于零的数字')
      form.expected_amount = form.expected_amount === '' ? null : form.expected_amount
      form.files = this.attachments.map(file => ({ attachment_id: file.id, category: 'requirement', status: 'current' }))
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

<style scoped>
.crm-picker-inline {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: nowrap;
}
.crm-picker-inline .el-input {
  flex: 1;
  min-width: 0;
}
.crm-picker-inline .el-button {
  flex-shrink: 0;
}
</style>
