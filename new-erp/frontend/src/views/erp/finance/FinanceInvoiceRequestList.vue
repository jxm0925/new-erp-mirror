<template>
  <section :class="['invoice-request-page', { embedded }]">
    <div v-if="!embedded" class="page-heading">
      <div>
        <h1>开票申请</h1>
        <p>销售在订单里提交的给客户开票申请。开好票后点“开票登记”填发票号，系统自动生成销项发票并记到订单上。</p>
      </div>
      <el-button v-if="$can('finance.invoice.view')" size="small" @click="$router.push({ path: '/finance/invoices', query: { tab: 'sales' } })">查看销项发票</el-button>
    </div>

    <div class="metrics">
      <div><span>待开票</span><b class="orange">{{ summary.pending_count || 0 }}<em>张</em></b></div>
      <div><span>待开票金额</span><b class="orange">¥{{ money(summary.pending_amount) }}</b></div>
      <div><span>本月已开票</span><b class="green">¥{{ money(summary.issued_month_amount) }}</b></div>
    </div>

    <el-form class="filter-bar" :inline="true" size="small" @submit.native.prevent="search">
      <el-form-item label="状态">
        <el-select v-model="filters.status" clearable placeholder="全部" style="width: 120px">
          <el-option label="待开票" value="pending" /><el-option label="已开票" value="issued" /><el-option label="已驳回" value="rejected" /><el-option label="已撤回" value="withdrawn" />
        </el-select>
      </el-form-item>
      <el-form-item label="关键字"><el-input v-model.trim="filters.keyword" clearable placeholder="申请单号 / 订单号 / 客户 / 抬头 / 发票号" style="width: 260px" @keyup.enter.native="search" /></el-form-item>
      <el-form-item label="申请日期"><el-date-picker v-model="dateRange" type="daterange" value-format="yyyy-MM-dd" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width: 240px" /></el-form-item>
      <el-form-item><el-button type="primary" @click="search">查询</el-button><el-button @click="reset">重置</el-button></el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border size="small" empty-text="没有开票申请">
      <el-table-column label="申请单号" min-width="150"><template slot-scope="{row}"><span class="font-mono">{{ row.request_no }}</span><div class="muted">{{ row.requested_by_name || '-' }} · {{ dateTime(row.created_at) }}</div></template></el-table-column>
      <el-table-column label="销售订单 / 客户" min-width="190"><template slot-scope="{row}"><el-button type="text" class="font-mono" @click="$router.push(`/sales/orders/${row.sales_order_id}/detail`)">{{ row.sales_order_no }}</el-button><div class="muted">{{ row.customer_name }}</div></template></el-table-column>
      <el-table-column label="开票信息" min-width="280">
        <template slot-scope="{row}">
          <div><el-tag size="mini" :type="row.invoice_type === 'vat_special' ? 'danger' : ''" effect="plain">{{ row.invoice_type === 'vat_special' ? '专票' : '普票' }}</el-tag> <b>{{ row.invoice_title }}</b></div>
          <div v-if="row.invoice_tax_no" class="muted">税号：{{ row.invoice_tax_no }}</div>
          <div v-if="row.invoice_address_phone" class="muted">地址电话：{{ row.invoice_address_phone }}</div>
          <div v-if="row.invoice_bank_account" class="muted">开户行：{{ row.invoice_bank_account }}</div>
          <div v-if="row.invoice_email" class="muted">收票邮箱：{{ row.invoice_email }}</div>
        </template>
      </el-table-column>
      <el-table-column label="金额（含税）" width="130" align="right"><template slot-scope="{row}"><b class="amount">¥{{ money(row.amount) }}</b><div class="muted">税率 {{ Number(row.tax_rate) }}%</div></template></el-table-column>
      <el-table-column label="备注" min-width="140"><template slot-scope="{row}">{{ row.remark || '-' }}</template></el-table-column>
      <el-table-column label="状态" width="86"><template slot-scope="{row}"><el-tag size="mini" :type="statusTone(row.status)">{{ row.status_label }}</el-tag></template></el-table-column>
      <el-table-column label="处理结果" min-width="190">
        <template slot-scope="{row}">
          <template v-if="row.status === 'issued'">
            <b class="font-mono">{{ row.invoice_no }}</b> <span class="muted">{{ row.invoice_date }}</span>
            <div v-for="file in row.attachments" :key="file.id"><el-button type="text" size="mini" icon="el-icon-document" @click="openFile(row, file)">{{ file.original_name }}</el-button></div>
          </template>
          <span v-else-if="row.status === 'rejected'" class="red">{{ row.handle_remark }}</span>
          <div v-if="row.handled_by_name" class="muted">{{ row.handled_by_name }} · {{ dateTime(row.handled_at) }}</div>
        </template>
      </el-table-column>
      <el-table-column v-if="canHandle" label="操作" width="150" fixed="right">
        <template slot-scope="{row}">
          <template v-if="row.status === 'pending'">
            <el-button type="text" size="mini" @click="copyInfo(row)">复制</el-button>
            <el-button type="text" size="mini" @click="openIssue(row)">开票登记</el-button>
            <el-button type="text" size="mini" class="danger-link" @click="reject(row)">驳回</el-button>
          </template>
          <el-button v-else-if="row.status === 'issued'" type="text" size="mini" @click="pickUpload(row)">传发票文件</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination class="pager" background layout="total, prev, pager, next, sizes" :total="total" :current-page="page" :page-size="perPage" :page-sizes="[20, 50, 100]" @current-change="p => { page = p; load() }" @size-change="s => { perPage = s; page = 1; load() }" />
    <input ref="uploadInput" type="file" accept=".pdf,.ofd,.xml,image/*" style="display: none" @change="uploadPicked">

    <el-dialog title="开票登记" :visible.sync="issue.visible" width="min(620px, calc(100vw - 32px))" append-to-body :close-on-click-modal="false">
      <template v-if="issue.row">
        <div class="issue-info">
          <div><span>客户订单</span><b>{{ issue.row.sales_order_no }} · {{ issue.row.customer_name }}</b></div>
          <div><span>发票类型</span><b>{{ issue.row.invoice_type_label }}</b></div>
          <div><span>抬头</span><b>{{ issue.row.invoice_title }}</b></div>
          <div v-if="issue.row.invoice_tax_no"><span>税号</span><b>{{ issue.row.invoice_tax_no }}</b></div>
          <div><span>价税合计</span><b class="amount">¥{{ money(issue.row.amount) }}</b></div>
          <div><span>不含税 / 税额</span><b>¥{{ money(issueExcl) }} / ¥{{ money(issueTax) }}</b></div>
        </div>
        <el-form ref="issueForm" :model="issue.form" :rules="issueRules" label-width="96px" size="small">
          <el-form-item label="发票号码" prop="invoice_no"><el-input v-model.trim="issue.form.invoice_no" maxlength="120" placeholder="数电票填 20 位发票号码" /></el-form-item>
          <el-form-item label="发票代码"><el-input v-model.trim="issue.form.invoice_code" maxlength="80" placeholder="数电票没有可不填" /></el-form-item>
          <el-form-item label="开票日期" prop="invoice_date"><el-date-picker v-model="issue.form.invoice_date" type="date" value-format="yyyy-MM-dd" style="width: 200px" /></el-form-item>
          <el-form-item label="税率"><el-select v-model="issue.form.tax_rate" style="width: 200px"><el-option v-for="rate in taxRates" :key="rate" :label="`${rate}%`" :value="rate" /></el-select></el-form-item>
          <el-form-item label="发票文件"><input ref="issueFile" type="file" accept=".pdf,.ofd,.xml,image/*" @change="e => issue.file = e.target.files[0] || null"><div class="muted">电子发票 PDF 或纸票照片，可稍后再传</div></el-form-item>
          <el-form-item label="备注"><el-input v-model.trim="issue.form.remark" maxlength="500" /></el-form-item>
        </el-form>
      </template>
      <span slot="footer"><el-button @click="issue.visible = false">取消</el-button><el-button type="primary" :loading="issue.saving" @click="submitIssue">确认登记</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { listSalesInvoiceRequests, issueSalesInvoiceRequest, uploadSalesInvoiceRequestFile, rejectSalesInvoiceRequest } from '@/api/erp/finance'
import { downloadSalesInvoiceFile } from '@/api/erp/sales'

const today = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` }

export default {
  name: 'FinanceInvoiceRequestList',
  props: { embedded: { type: Boolean, default: false } },
  data: () => ({
    loading: false, rows: [], total: 0, page: 1, perPage: 20, summary: {},
    filters: { status: 'pending', keyword: '' }, dateRange: [],
    taxRates: [13, 9, 6, 3, 1, 0],
    uploadRow: null,
    issue: { visible: false, saving: false, row: null, file: null, form: {} },
    issueRules: {
      invoice_no: [{ required: true, message: '请填写发票号码', trigger: 'blur' }],
      invoice_date: [{ required: true, message: '请选择开票日期', trigger: 'change' }]
    }
  }),
  computed: {
    canHandle() { return this.$can('finance.invoice_request.handle') },
    issueExcl() {
      if (!this.issue.row) return 0
      return Math.round(Number(this.issue.row.amount) / (1 + Number(this.issue.form.tax_rate || 0) / 100) * 100) / 100
    },
    issueTax() { return this.issue.row ? Math.round((Number(this.issue.row.amount) - this.issueExcl) * 100) / 100 : 0 }
  },
  created() { this.load() },
  methods: {
    money(v) { return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
    dateTime(v) { return v ? String(v).replace('T', ' ').slice(0, 16) : '-' },
    statusTone(v) { return ({ pending: 'warning', issued: 'success', rejected: 'danger', withdrawn: 'info' })[v] || 'info' },
    search() { this.page = 1; this.load() },
    reset() { this.filters = { status: 'pending', keyword: '' }; this.dateRange = []; this.search() },
    async load() {
      this.loading = true
      try {
        const [start, end] = this.dateRange || []
        const { data } = await listSalesInvoiceRequests({ ...this.filters, date_start: start || undefined, date_end: end || undefined, page: this.page, per_page: this.perPage })
        this.rows = data.data || []
        this.total = data.total || 0
        this.summary = data.summary || {}
        this.$emit('summary', this.summary)
      } catch (e) {
        this.$message.error(e.userMessage || '开票申请加载失败')
      } finally { this.loading = false }
    },
    copyInfo(row) {
      const text = [
        `发票类型：${row.invoice_type_label}`, `抬头：${row.invoice_title}`,
        row.invoice_tax_no && `税号：${row.invoice_tax_no}`, row.invoice_address_phone && `地址电话：${row.invoice_address_phone}`,
        row.invoice_bank_account && `开户行及账号：${row.invoice_bank_account}`, `金额（含税）：${this.money(row.amount)}`, `税率：${Number(row.tax_rate)}%`,
        row.invoice_email && `收票邮箱：${row.invoice_email}`, row.remark && `备注：${row.remark}`
      ].filter(Boolean).join('\n')
      const done = () => this.$message.success('开票信息已复制')
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, () => this.fallbackCopy(text, done)) } else { this.fallbackCopy(text, done) }
    },
    fallbackCopy(text, done) {
      const area = document.createElement('textarea')
      area.value = text
      document.body.appendChild(area)
      area.select()
      try { document.execCommand('copy'); done() } catch (e) { this.$message.warning('复制失败，请手动复制') }
      document.body.removeChild(area)
    },
    openIssue(row) {
      this.issue = { visible: true, saving: false, row, file: null, form: { invoice_no: '', invoice_code: '', invoice_date: today(), tax_rate: Number(row.tax_rate), remark: '' } }
      this.$nextTick(() => { if (this.$refs.issueFile) this.$refs.issueFile.value = ''; if (this.$refs.issueForm) this.$refs.issueForm.clearValidate() })
    },
    submitIssue() {
      this.$refs.issueForm.validate(async valid => {
        if (!valid) return
        const form = new FormData()
        Object.entries(this.issue.form).forEach(([key, value]) => { if (value !== '' && value !== null && value !== undefined) form.append(key, value) })
        if (this.issue.file) form.append('file', this.issue.file)
        this.issue.saving = true
        try {
          const { data } = await issueSalesInvoiceRequest(this.issue.row.id, form)
          this.$message[data.data && data.data.upload_failed ? 'warning' : 'success'](data.message || '已登记开票')
          this.issue.visible = false
          this.load()
        } catch (e) {
          this.$message.error(e.userMessage || '登记失败')
        } finally { this.issue.saving = false }
      })
    },
    async reject(row) {
      let reason
      try {
        const res = await this.$prompt(`驳回 ${row.request_no}，销售会在订单里看到原因`, '驳回开票申请', { inputPlaceholder: '例如：抬头和税号对不上、订单还没收款', inputValidator: v => !!(v && v.trim()) || '请填写驳回原因' })
        reason = res.value.trim()
      } catch (e) { return }
      try {
        await rejectSalesInvoiceRequest(row.id, reason)
        this.$message.success('已驳回')
        this.load()
      } catch (e) { this.$message.error(e.userMessage || '驳回失败') }
    },
    pickUpload(row) {
      this.uploadRow = row
      this.$refs.uploadInput.value = ''
      this.$refs.uploadInput.click()
    },
    async uploadPicked(e) {
      const file = e.target.files[0]
      if (!file || !this.uploadRow) return
      const form = new FormData()
      form.append('file', file)
      try {
        await uploadSalesInvoiceRequestFile(this.uploadRow.id, form)
        this.$message.success('发票文件已上传')
        this.load()
      } catch (err) { this.$message.error(err.userMessage || '上传失败') }
    },
    async openFile(row, file) {
      try {
        const res = await downloadSalesInvoiceFile(row.id, file.id)
        const type = (res.headers && res.headers['content-type']) || ''
        const url = URL.createObjectURL(new Blob([res.data], { type }))
        const a = document.createElement('a')
        a.href = url
        a.target = '_blank'
        if (!/pdf|image/.test(type)) a.download = file.original_name
        a.click()
        setTimeout(() => URL.revokeObjectURL(url), 60000)
      } catch (e) { this.$message.error(e.userMessage || '文件打开失败') }
    }
  }
}
</script>

<style scoped>
.invoice-request-page { min-height: calc(100vh - 64px); padding: 24px 20px 30px; background: #fff; color: #17233b; box-sizing: border-box; }
.page-heading { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 20px; }
.page-heading h1 { margin: 0 0 8px; font-size: 26px; }
.page-heading p { margin: 0; color: #7b879b; font-size: 14px; }
.metrics { display: grid; grid-template-columns: repeat(3, 1fr); border: 1px solid #dde6ef; border-radius: 4px; margin-bottom: 18px; overflow: hidden; }
.metrics > div { padding: 16px 20px; border-right: 1px solid #e3e9ef; }
.metrics > div:last-child { border-right: 0; }
.metrics span { display: block; color: #526078; font-size: 14px; margin-bottom: 10px; }
.metrics b { font-size: 24px; }
.metrics em { font-style: normal; font-size: 13px; margin-left: 4px; }
.green { color: #009b50; }
.orange { color: #f97316; }
.red { color: #dc2626; }
.danger-link { color: #dc2626 !important; }
.muted { color: #8a94a6; font-size: 12px; line-height: 18px; }
.amount { font-weight: 600; }
.font-mono { font-family: Menlo, Consolas, monospace; }
.filter-bar { margin-bottom: 6px; }
.pager { margin-top: 14px; text-align: right; }
.issue-info { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 16px; background: #f8fafc; border: 1px solid #e5eaf0; border-radius: 4px; padding: 12px 14px; margin-bottom: 16px; }
.issue-info span { display: block; color: #8a94a6; font-size: 12px; }
.issue-info b { word-break: break-all; }
@media (max-width: 760px) { .metrics { grid-template-columns: 1fr; } .metrics > div { border-right: 0; border-bottom: 1px solid #e3e9ef; } .page-heading { flex-direction: column; } .issue-info { grid-template-columns: 1fr; } }
.invoice-request-page.embedded { min-height: 0; padding: 0; }
</style>
