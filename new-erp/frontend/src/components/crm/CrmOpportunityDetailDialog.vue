<template>
  <el-dialog class="crm-dialog" :visible.sync="visible" width="1200px" append-to-body :close-on-click-modal="false" :before-close="close">
    <div slot="title" class="crm-inline"><b>{{ opportunity.name || '商机详情' }}</b><span class="crm-muted">客户 {{ opportunity.customer_name }} · 负责人 {{ opportunity.owner_name || '—' }} / {{ opportunity.department_name || '—' }}</span></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <div v-loading="loading" v-if="opportunity.id">
      <div class="crm-opportunity-stages"><div v-for="(label,key) in stages" :key="key" class="crm-opportunity-stage" :class="stageClass(key)">{{ label }}</div><div class="crm-opportunity-stage" :class="{current: opportunity.status === 'won'}">{{ opportunity.status === 'lost' ? '已丢单' : '已成交' }}</div></div>
      <div class="crm-opportunity-metrics">
        <div><span>预计金额</span><b>{{ money(opportunity.expected_amount,opportunity.currency) }}</b></div>
        <div><span>预计成交日期</span><b>{{ shortDate(opportunity.expected_close_date) }}</b></div>
        <div><span>来源线索</span><el-button v-if="opportunity.source_lead_id && canViewLead" type="text" @click="$refs.lead.open(opportunity.source_lead_id)">{{ opportunity.source_lead_no || opportunity.source_lead_id }}</el-button><b v-else>{{ opportunity.source_lead_no || '—' }}</b></div>
        <div><span>本阶段停留</span><b>{{ opportunity.stage_age_days === undefined ? '—' : opportunity.stage_age_days + ' 天' }}</b></div>
      </div>
      <el-tabs v-model="tab" @tab-click="loadTab"><el-tab-pane label="概览" name="overview" /><el-tab-pane label="方案与报价" name="files" /><el-tab-pane v-if="canViewOrder" label="关联订单" name="orders" /><el-tab-pane label="跟进记录" name="followups" /><el-tab-pane label="阶段历史" name="logs" /></el-tabs>
      <section v-if="tab === 'overview'" class="crm-box">
        <h3 class="crm-section-title">商机概览</h3>
        <el-descriptions :column="2" border size="small"><el-descriptions-item label="商机编号">{{ opportunity.opportunity_no }}</el-descriptions-item><el-descriptions-item label="业务线">{{ opportunity.business_line || '—' }}</el-descriptions-item><el-descriptions-item label="对接人">{{ opportunity.contact_name || '—' }}</el-descriptions-item><el-descriptions-item label="当前状态">{{ opportunity.status_label }} · {{ opportunity.stage_label }}</el-descriptions-item><el-descriptions-item label="采购需求" :span="2">{{ opportunity.demand_summary || '—' }}</el-descriptions-item><el-descriptions-item label="最近跟进">{{ date(opportunity.last_contact_at) }}</el-descriptions-item><el-descriptions-item label="下次跟进">{{ date(opportunity.next_contact_at) }}</el-descriptions-item><el-descriptions-item label="下次计划" :span="2">{{ opportunity.next_plan || '—' }}</el-descriptions-item><template v-if="opportunity.status === 'lost'"><el-descriptions-item label="丢单原因">{{ opportunity.lost_reason }}</el-descriptions-item><el-descriptions-item label="竞品 / 去向">{{ opportunity.lost_destination }}</el-descriptions-item><el-descriptions-item label="丢单说明" :span="2">{{ opportunity.lost_note }}</el-descriptions-item></template></el-descriptions>
      </section>
      <section v-if="tab === 'files'" v-loading="files.loading">
        <div class="crm-inline crm-opportunity-section"><h3 class="crm-section-title">方案与报价</h3><div v-if="allowed.file" class="crm-actions drawing-inline-actions"><el-button size="mini" @click="reuseDrawing">沿用已有图纸</el-button><el-button size="mini" @click="openAction('file')">上传新版本</el-button></div></div>
        <el-table :data="files.rows" size="small"><el-table-column label="文件名称" min-width="200"><template slot-scope="{row}">{{ (row.attachment || {}).name || '—' }}<div v-if="row.technical_drawing" class="drawing-identification">{{ row.technical_drawing.drawing_no }} · V{{ row.technical_drawing.version_no }} · {{ row.technical_drawing.designer_name }}</div></template></el-table-column><el-table-column prop="category_label" label="类型" min-width="90" /><el-table-column label="版本" min-width="70"><template slot-scope="{row}">V{{ row.version_no }}</template></el-table-column><el-table-column label="上传日期" min-width="110"><template slot-scope="{row}">{{ shortDate(row.created_at) }}</template></el-table-column><el-table-column prop="created_by_name" label="上传人" min-width="90" /><el-table-column label="状态" min-width="120"><template slot-scope="{row}"><el-tag size="mini" :type="row.status === 'current' ? 'success' : row.status === 'pending' ? 'warning' : 'info'">{{ row.status_label }}</el-tag></template></el-table-column><el-table-column label="操作" min-width="255"><template slot-scope="{row}"><div class="crm-inline"><el-button size="mini" type="text" @click="preview(row)">查看</el-button><el-button size="mini" type="text" @click="download(row)">下载</el-button><el-button v-if="allowed.file && row.status === 'pending'" size="mini" type="text" @click="openAction('confirm',row)">确认当前版</el-button><el-button v-if="row.technical_drawing" size="mini" type="text" @click="$refs.drawingDetail.open(row.technical_drawing.drawing_id)">图纸详情</el-button><el-button v-else-if="allowed.file && row.category === 'solution' && row.status !== 'history'" size="mini" type="text" @click="registerDrawing(row)">登记图纸</el-button></div></template></el-table-column></el-table>
        <div class="crm-pager"><el-pagination background layout="total, prev, pager, next" :total="files.total" :current-page.sync="files.page" :page-size="10" @current-change="loadFiles" /></div>
        <div v-if="allowed.order && !opportunity.orders_count" class="crm-box crm-opportunity-empty"><b>暂无关联订单</b><p class="crm-muted">从商机新建订单会带入客户和商机；订单仍走原审批，订单生效后计入成交</p><el-button size="small" type="success" @click="createOrder">新建关联订单</el-button></div>
      </section>
      <section v-if="tab === 'orders'" v-loading="orders.loading">
        <div class="crm-inline crm-opportunity-section"><h3 class="crm-section-title">关联订单</h3><el-button v-if="allowed.order" size="mini" type="success" class="crm-actions" @click="createOrder">新建关联订单</el-button></div>
        <el-table :data="orders.rows" size="small"><el-table-column prop="sales_order_no" label="订单号" min-width="175" /><el-table-column label="订单状态" min-width="100"><template slot-scope="{row}">{{ statusText(row.order_status) }}</template></el-table-column><el-table-column label="确认状态" min-width="100"><template slot-scope="{row}">{{ statusText(row.confirm_status) }}</template></el-table-column><el-table-column label="下单日期" min-width="110"><template slot-scope="{row}">{{ shortDate(row.order_date) }}</template></el-table-column><el-table-column label="订单金额" min-width="140" align="right"><template slot-scope="{row}">{{ money(row.total_amount,row.currency) }}</template></el-table-column><el-table-column label="生效时间" min-width="145"><template slot-scope="{row}">{{ date(row.first_effective_at || row.confirmed_at) }}</template></el-table-column><el-table-column v-if="canViewOrder" label="操作" width="90"><template slot-scope="{row}"><el-button type="text" size="mini" @click="viewOrder(row)">查看订单</el-button></template></el-table-column></el-table>
        <div class="crm-pager"><el-pagination background layout="total, prev, pager, next" :total="orders.total" :current-page.sync="orders.page" :page-size="10" @current-change="loadOrders" /></div>
      </section>
      <section v-if="tab === 'followups'" v-loading="followups.loading">
        <div class="crm-inline crm-opportunity-section"><h3 class="crm-section-title">跟进记录</h3><el-button v-if="allowed.follow" class="crm-actions" type="success" size="mini" @click="openFollowup()">记录跟进</el-button></div>
        <el-timeline><el-timeline-item v-for="row in followups.rows" :key="row.id" :timestamp="date(row.contacted_at) + ' · ' + (row.operator_name || row.created_by_name || '')" color="#008b4b"><div class="crm-box"><div class="crm-inline"><b>{{ row.method }} · {{ row.contact_name }}</b><el-tag size="mini" type="success">{{ row.result }}</el-tag><el-button v-if="row.can_edit" type="text" size="mini" class="crm-actions" @click="openFollowup(row)">修改</el-button></div><p class="crm-opportunity-content">{{ row.content }}</p><span class="crm-sub">下次联系 {{ date(row.next_contact_at) }} · {{ row.next_plan }}</span><crm-attachments v-if="(row.attachments || []).length" :value="row.attachments" readonly /></div></el-timeline-item></el-timeline><el-empty v-if="!followups.loading && !followups.rows.length" description="暂无跟进记录" :image-size="65" />
        <div class="crm-pager"><el-pagination background layout="total, prev, pager, next" :total="followups.total" :current-page.sync="followups.page" :page-size="10" @current-change="loadFollowups" /></div>
      </section>
      <section v-if="tab === 'logs'" v-loading="logs.loading"><el-timeline><el-timeline-item v-for="row in logs.rows" :key="row.id" :timestamp="date(row.created_at) + ' · ' + (row.operator_name || '系统')" color="#008b4b"><b>{{ row.summary || row.action }}</b><span v-if="row.reason" class="crm-sub">{{ row.reason }}</span><span v-if="row.from_stage || row.to_stage" class="crm-sub">{{ stages[row.from_stage] || row.from_stage || '—' }} → {{ stages[row.to_stage] || row.to_stage || '—' }}</span></el-timeline-item></el-timeline><el-empty v-if="!logs.loading && !logs.rows.length" description="暂无阶段历史" :image-size="65" /><div class="crm-pager"><el-pagination background layout="total, prev, pager, next" :total="logs.total" :current-page.sync="logs.page" :page-size="10" @current-change="loadLogs" /></div></section>
    </div>
    <div slot="footer" class="crm-footer"><el-button v-if="allowed.edit" size="small" :disabled="loading" @click="$refs.form.open({id: opportunity.id})">编辑</el-button><el-button v-if="allowed.lose" size="small" type="danger" plain :disabled="loading" @click="openAction('lose')">丢单</el-button><el-button v-if="allowed.follow" size="small" :disabled="loading" @click="openFollowup()">记录跟进</el-button><el-button v-if="allowed.stage" size="small" type="success" :disabled="loading" @click="openAction('stage')">推进阶段</el-button><el-button size="small" @click="close">关闭</el-button></div>
    <template v-if="visible"><technical-drawing-register-dialog ref="drawingRegister" @saved="changed" /><technical-drawing-detail-dialog ref="drawingDetail" @changed="changed" /><crm-opportunity-form-dialog :key="'form-' + session" ref="form" @saved="changed" /><crm-followup-dialog :key="'follow-' + session" ref="follow" @saved="changed" /><crm-lead-detail-dialog v-if="canViewLead" :key="'lead-' + session" ref="lead" /><sales-order-attachment-preview-dialog :key="'preview-' + session" :visible.sync="previewVisible" :file="previewFile" :preview-request="downloadAttachment" :download-request="downloadAttachment" /></template>
    <el-dialog class="crm-dialog" :title="actionTitle" :visible.sync="action.visible" :width="action.mode === 'stage' ? '660px' : '620px'" append-to-body :close-on-click-modal="false" :before-close="closeAction">
      <el-descriptions :column="1" border size="small"><el-descriptions-item label="商机">{{ action.subject.name }}</el-descriptions-item><el-descriptions-item label="当前阶段">{{ action.subject.stage_label }}</el-descriptions-item></el-descriptions>
      <el-form label-width="100px" size="small" :disabled="action.saving">
        <template v-if="action.mode === 'stage'"><el-form-item label="操作"><el-radio-group v-model="action.form.direction"><el-radio label="forward" :disabled="stageIndex(action.subject.stage) >= stageKeys.length - 1">推进到下一阶段</el-radio><el-radio label="backward" :disabled="stageIndex(action.subject.stage) <= 0">退回上一阶段</el-radio></el-radio-group></el-form-item><el-form-item label="目标阶段"><el-input :value="targetStage" disabled /></el-form-item><el-form-item label="阶段说明" required><el-input v-model.trim="action.form.reason" type="textarea" :rows="2" maxlength="2000" /></el-form-item><el-form-item label="关联附件"><paged-record-select v-if="action.visible" :key="'stage-file-' + actionSession" v-model="action.form.file_id" :request="loadActionFiles" :columns="fileColumns" :label="fileLabel" title="选择关联附件" /></el-form-item><el-form-item label="预计金额"><el-input v-model="action.form.expected_amount"><template slot="prepend">{{ action.subject.currency }}</template></el-input></el-form-item><el-form-item label="预计成交日期"><el-date-picker v-model="action.form.expected_close_date" type="date" value-format="yyyy-MM-dd" popper-class="crm-date-popper" /></el-form-item><div class="crm-note">阶段变化记入阶段历史；“已成交”只能由关联订单生效自动产生。退回上一阶段需填写原因。</div></template>
        <template v-if="action.mode === 'lose'"><el-form-item label="丢单原因" required><el-select v-model="action.form.lost_reason" filterable allow-create default-first-option popper-class="crm-select-popper"><el-option v-for="item in ['选择竞品','价格原因','需求取消','暂缓采购','其他']" :key="item" :value="item" :label="item" /></el-select></el-form-item><el-form-item label="竞品 / 去向"><el-input v-model.trim="action.form.lost_destination" maxlength="200" /></el-form-item><el-form-item label="说明" required><el-input v-model.trim="action.form.lost_note" type="textarea" :rows="3" maxlength="2000" /></el-form-item><div class="crm-note warning">丢单后进入“已丢单”，阶段历史和跟进记录保留；已有生效关联订单的商机不能丢单。</div></template>
        <template v-if="action.mode === 'file'"><el-form-item label="类型" required><el-select v-model="action.form.category" popper-class="crm-select-popper"><el-option v-for="(label,key) in metadata.file_categories || {}" :key="key" :value="key" :label="label" /></el-select></el-form-item><el-form-item label="状态"><el-radio-group v-model="action.form.status"><el-radio label="current">当前版</el-radio><el-radio label="pending">待客户确认</el-radio></el-radio-group></el-form-item><el-form-item label="附件" required><crm-attachments v-if="action.visible" :key="'upload-' + actionSession" v-model="action.attachments" @busy="action.uploading = $event" /></el-form-item><el-form-item label="说明"><el-input v-model.trim="action.form.note" type="textarea" :rows="2" maxlength="1000" /></el-form-item></template>
        <el-form-item v-if="action.mode === 'confirm'" label="确认说明" required><el-input v-model.trim="action.form.reason" type="textarea" :rows="2" maxlength="2000" /></el-form-item>
      </el-form>
      <span slot="footer"><el-button size="small" :disabled="action.saving" @click="closeAction">取消</el-button><el-button size="small" :type="action.mode === 'lose' ? 'danger' : 'success'" :loading="action.saving" :disabled="action.uploading" @click="submitAction">{{ action.mode === 'lose' ? '确认丢单' : action.mode === 'stage' ? (action.form.direction === 'backward' ? '确认退回' : '确认推进') : '确认保存' }}</el-button></span>
    </el-dialog>
  </el-dialog>
</template>
<script>
import { getCrmOpportunity, getCrmOpportunityMetadata, listCrmOpportunityFiles, listCrmOpportunityLogs, listCrmOpportunityOrders, listCrmFollowups, actCrmOpportunity, saveCrmOpportunityFile, confirmCrmOpportunityFile, downloadCrmAttachment, crmDate, crmError } from '@/api/erp/crm'
import { erpDateTime } from '@/utils/dateTime'
import { statusText } from '@/utils/erpStatus'
import TechnicalDrawingRegisterDialog from '@/components/technical-drawings/TechnicalDrawingRegisterDialog.vue'
import TechnicalDrawingDetailDialog from '@/components/technical-drawings/TechnicalDrawingDetailDialog.vue'
import CrmAttachments from './CrmAttachments.vue'
import CrmFollowupDialog from './CrmFollowupDialog.vue'
import CrmOpportunityFormDialog from './CrmOpportunityFormDialog.vue'
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import SalesOrderAttachmentPreviewDialog from '@/components/sales/SalesOrderAttachmentPreviewDialog.vue'
import '@/styles/crm.css'
const page = () => ({ rows: [], total: 0, page: 1, loading: false, sequence: 0 })
const blankAction = () => ({ visible: false, mode: '', saving: false, uploading: false, subject: {}, file: null, form: {}, attachments: [] })
export default {
  name: 'CrmOpportunityDetailDialog',
  components: { TechnicalDrawingRegisterDialog, TechnicalDrawingDetailDialog, CrmAttachments, CrmFollowupDialog, CrmOpportunityFormDialog, PagedRecordSelect, SalesOrderAttachmentPreviewDialog, CrmLeadDetailDialog: () => import('./CrmLeadDetailDialog.vue') },
  data: () => ({ visible: false, loading: false, session: 0, sequence: 0, actionSession: 0, opportunity: {}, metadata: {}, error: '', tab: 'files', previewVisible: false, previewFile: null, files: page(), orders: page(), followups: page(), logs: page(), action: blankAction(), fileColumns: [{ prop: 'name', label: '文件名称' }, { prop: 'category_label', label: '类型' }, { prop: 'version_no', label: '版本' }] }),
  computed: {
    allowed() { return this.opportunity.abilities || {} },
    stages() { return this.metadata.stages || {} },
    stageKeys() { return Object.keys(this.stages) },
    canViewLead() { return !this.$can || this.$can('crm.lead.view') },
    canViewOrder() { return !this.$can || this.$can('sales_order.view') },
    actionTitle() { return ({stage:'推进阶段',lose:'丢单',file:'上传新版本',confirm:'确认当前版本'})[this.action.mode] || '' },
    targetStage() { const index = this.stageIndex(this.action.subject.stage) + (this.action.form.direction === 'backward' ? -1 : 1); return this.stages[this.stageKeys[index]] || '关联订单生效后自动成交' }
  },
  beforeDestroy() { this.session += 1; this.actionSession += 1 },
  methods: {
    registerDrawing(row) { this.$refs.drawingRegister.open({source:{type:'opportunity',id:this.opportunity.id,file_id:row.id,version:this.opportunity.version},sourceLabel:'商机 · ' + this.opportunity.name,fileName:(row.attachment || {}).name}) },
    reuseDrawing() { this.$refs.drawingRegister.open({action:'reuse',source:{type:'opportunity',id:this.opportunity.id,version:this.opportunity.version},sourceLabel:'商机 · ' + this.opportunity.name}) },
    date: crmDate,
    statusText,
    downloadAttachment: downloadCrmAttachment,
    shortDate(value) { return erpDateTime(value,10) || '—' },
    money(value,currency) { return value === null || value === undefined || value === '' ? '—' : `${currency || 'CNY'} ${Number(value).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2})}` },
    stageIndex(key) { return this.stageKeys.indexOf(key) },
    stageClass(key) { const index = this.stageIndex(key); const current = this.stageIndex(this.opportunity.stage); return { current: this.opportunity.status === 'open' && index === current, done: index < current || this.opportunity.status === 'won' } },
    async open(id) {
      const session = ++this.session
      this.visible = true
      this.loading = true
      this.previewVisible = false
      this.opportunity = {id}
      this.metadata = {}
      this.error = ''
      this.tab = 'files'
      this.files = page(); this.orders = page(); this.followups = page(); this.logs = page()
      this.action = blankAction(); this.actionSession += 1
      try { const { data } = await getCrmOpportunityMetadata(); if (session !== this.session || !this.visible) return; this.metadata = data.data || data; await this.load(); if (session === this.session && this.visible && !this.error) this.loadTab() }
      catch (error) { if (session === this.session) this.error = crmError(error,'商机加载失败') }
      finally { if (session === this.session) this.loading = false }
    },
    close() { if (this.action.saving) return; this.session += 1; this.actionSession += 1; this.visible = false; this.action.visible = false; this.previewVisible = false },
    async load() {
      const id = this.opportunity.id; const session = this.session; const sequence = ++this.sequence
      this.loading = true
      try { const {data} = await getCrmOpportunity(id); if (session !== this.session || sequence !== this.sequence || !this.visible) return; this.opportunity = data.data || data; this.error = '' }
      catch (error) { if (session === this.session && sequence === this.sequence) this.error = crmError(error,'商机加载失败') }
      finally { if (session === this.session && sequence === this.sequence) this.loading = false }
    },
    loadTab() { if (this.tab === 'files') return this.loadFiles(); if (this.tab === 'orders') return this.loadOrders(); if (this.tab === 'followups') return this.loadFollowups(); if (this.tab === 'logs') return this.loadLogs() },
    loadFiles() { return this.loadPage('files', listCrmOpportunityFiles) },
    loadOrders() { return this.loadPage('orders', listCrmOpportunityOrders) },
    loadLogs() { return this.loadPage('logs', listCrmOpportunityLogs) },
    loadFollowups() { return this.loadPage('followups', (id,params) => listCrmFollowups({ ...params,subject_type:'opportunity',subject_id:id })) },
    async loadPage(key,request) {
      if (!this.visible || !this.opportunity.id) return
      const id = this.opportunity.id; const session = this.session; const state = this[key]; const sequence = ++state.sequence; const currentPage = state.page
      state.loading = true
      try { const {data} = await request(id,{page:currentPage,per_page:10}); if (session !== this.session || sequence !== this[key].sequence || !this.visible) return; state.rows = data.data || []; state.total = data.total || 0 }
      catch (error) { if (session === this.session && sequence === this[key].sequence) this.$message.error(crmError(error,'记录加载失败')) }
      finally { if (session === this.session && sequence === this[key].sequence) state.loading = false }
    },
    async changed() { const session = this.session; await this.load(); if (session !== this.session || !this.visible) return; this.loadTab(); this.$emit('changed',this.opportunity) },
    openFollowup(row) { this.$refs.follow.open({subject_type:'opportunity',subject_id:this.opportunity.id,customer_id:this.opportunity.customer_id,customer_name:this.opportunity.customer_name,opportunity_name:this.opportunity.name,contact_name:this.opportunity.contact_name},row) },
    createOrder() { this.$router.push({path:'/sales/orders/create',query:{opportunity_id:this.opportunity.id}}); this.close() },
    viewOrder(row) { this.$router.push(`/sales/orders/${row.id}/detail`); this.close() },
    preview(row) { const file = row.attachment || {}; this.previewFile = { ...file, attachment_id:file.id, file_name:file.name, can_preview:['application/pdf','image/jpeg','image/png','image/webp'].includes(file.mime_type), can_download:true }; this.previewVisible = true },
    async download(row) {
      const attachment = row.attachment || {}; const session = this.session
      try { const response = await downloadCrmAttachment(attachment.id); if (session !== this.session || !this.visible) return; const url = URL.createObjectURL(response.data); const link = document.createElement('a'); link.href = url; link.download = attachment.name || '附件'; link.click(); setTimeout(() => URL.revokeObjectURL(url),60000) }
      catch (error) { if (session === this.session) this.$message.error(crmError(error,'附件读取失败')) }
    },
    openAction(mode,file = null) {
      this.actionSession += 1
      const subject = { ...this.opportunity }
      this.action = {visible:true,mode,saving:false,uploading:false,subject,file,attachments:[],form:{version:subject.version,direction:this.stageIndex(subject.stage) === this.stageKeys.length - 1 ? 'backward' : 'forward',reason:'',file_id:null,expected_amount:subject.expected_amount,expected_close_date:erpDateTime(subject.expected_close_date,10) || null,category:'solution',status:'current',note:'',lost_reason:'',lost_destination:'',lost_note:''}}
    },
    closeAction() { if (this.action.saving) return; this.actionSession += 1; this.action.visible = false },
    fileLabel(row) { return `${(row.attachment || {}).name || row.name || '附件'} V${row.version_no}` },
    async loadActionFiles(params) { const {data} = await listCrmOpportunityFiles(this.action.subject.id,params); return {data:{...data,data:(data.data || []).map(row => ({...row,name:(row.attachment || {}).name}))}} },
    async submitAction() {
      const action = this.action; if (action.saving || action.uploading) return
      const form = {...action.form}; const id = action.subject.id; const session = this.session; const actionSession = this.actionSession
      if ((action.mode === 'stage' || action.mode === 'confirm') && !form.reason) return this.$message.warning('请填写说明')
      if (action.mode === 'lose' && (!form.lost_reason || !form.lost_note)) return this.$message.warning('请填写丢单原因及说明')
      if (action.mode === 'file' && action.attachments.length !== 1) return this.$message.warning('每次请选择一个新版本附件')
      if (action.mode === 'stage' && form.expected_amount !== null && form.expected_amount !== '' && (!Number.isFinite(Number(form.expected_amount)) || Number(form.expected_amount) < 0)) return this.$message.warning('预计金额须为不小于零的数字')
      if (form.expected_amount === '') form.expected_amount = null
      action.saving = true
      try {
        if (action.mode === 'file') await saveCrmOpportunityFile(id,{...form,attachment_id:action.attachments[0].id})
        else if (action.mode === 'confirm') await confirmCrmOpportunityFile(id,action.file.id,{version:form.version,reason:form.reason})
        else await actCrmOpportunity(id,action.mode,form)
        if (session !== this.session || actionSession !== this.actionSession) return
        action.visible = false; this.$message.success('商机已更新'); await this.changed()
      } catch (error) { if (session === this.session && actionSession === this.actionSession) this.$message.error(crmError(error,'操作失败')) }
      finally { if (session === this.session && actionSession === this.actionSession) action.saving = false }
    }
  }
}
</script>
