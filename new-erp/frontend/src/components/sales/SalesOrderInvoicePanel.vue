<template>
  <div v-loading="loading" class="sales-invoice-panel">
    <div class="invoice-toolbar">
      <div><b>给客户开发票</b><span class="muted">销售提交申请，财务开票后在这里能看到发票号和发票文件</span></div>
      <div>
        <el-button v-if="info.can_create" size="small" type="primary" icon="el-icon-plus" :disabled="!info.can_request || availableZero" @click="openCreate">申请开票</el-button>
        <el-button size="small" @click="load">刷新</el-button>
      </div>
    </div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-alert v-else-if="info.request_blocker" :title="info.request_blocker" type="info" :closable="false" show-icon />
    <dl v-if="summary" class="invoice-facts">
      <div><dt>订单应收</dt><dd>¥{{ money(summary.receivable_amount) }}</dd></div>
      <div><dt>已开票</dt><dd class="green">¥{{ money(summary.invoiced_amount) }}</dd></div>
      <div><dt>开票中</dt><dd class="orange">¥{{ money(summary.pending_amount) }}</dd></div>
      <div><dt>还能申请</dt><dd>¥{{ money(summary.available_amount) }}</dd></div>
    </dl>
    <el-table :data="info.requests || []" border size="small" empty-text="还没有开票申请">
      <el-table-column label="申请单号" min-width="150"><template slot-scope="{row}"><span class="font-mono">{{ row.request_no }}</span><div class="muted">{{ row.requested_by_name || '-' }} · {{ date(row.created_at) }}</div></template></el-table-column>
      <el-table-column label="发票类型 / 抬头" min-width="200"><template slot-scope="{row}">{{ row.invoice_type_label }}<div class="muted">{{ row.invoice_title }}<template v-if="row.invoice_tax_no"> · {{ row.invoice_tax_no }}</template></div></template></el-table-column>
      <el-table-column label="开票金额" width="120" align="right"><template slot-scope="{row}"><b>¥{{ money(row.amount) }}</b><div class="muted">税率 {{ Number(row.tax_rate) }}%</div></template></el-table-column>
      <el-table-column label="状态" width="90"><template slot-scope="{row}"><el-tag size="mini" :type="statusTone(row.status)">{{ row.status_label }}</el-tag></template></el-table-column>
      <el-table-column label="发票号 / 财务处理" min-width="200">
        <template slot-scope="{row}">
          <template v-if="row.status === 'issued'">
            <b class="font-mono">{{ row.invoice_no }}</b> <span class="muted">{{ row.invoice_date }}</span>
            <div><el-button v-for="file in row.attachments" :key="file.id" type="text" size="mini" icon="el-icon-document" @click="openFile(row, file)">{{ file.original_name }}</el-button></div>
          </template>
          <span v-else-if="row.status === 'rejected'" class="red">驳回：{{ row.handle_remark || '-' }}</span>
          <span v-else-if="row.status === 'pending'" class="muted">等待财务开票</span>
          <span v-else class="muted">{{ row.handled_by_name || '' }} 已撤回</span>
          <div v-if="row.remark" class="muted">备注：{{ row.remark }}</div>
        </template>
      </el-table-column>
      <el-table-column v-if="info.can_create" label="操作" width="70"><template slot-scope="{row}"><el-button v-if="row.status === 'pending'" type="text" size="mini" @click="withdraw(row)">撤回</el-button></template></el-table-column>
    </el-table>

    <el-dialog title="申请开票" :visible.sync="dialogVisible" width="min(640px, calc(100vw - 32px))" append-to-body :close-on-click-modal="false">
      <el-form ref="form" :model="form" :rules="rules" label-width="110px" size="small">
        <el-form-item label="发票类型" prop="invoice_type">
          <el-radio-group v-model="form.invoice_type"><el-radio label="vat_normal">增值税普通发票</el-radio><el-radio label="vat_special">增值税专用发票</el-radio></el-radio-group>
        </el-form-item>
        <el-form-item label="发票抬头" prop="invoice_title"><el-input v-model.trim="form.invoice_title" maxlength="200" placeholder="公司全称或个人姓名" /></el-form-item>
        <el-form-item label="纳税人识别号" prop="invoice_tax_no"><el-input v-model.trim="form.invoice_tax_no" maxlength="60" :placeholder="isSpecial ? '专票必填' : '企业抬头建议填写'" /></el-form-item>
        <el-form-item label="地址、电话" prop="invoice_address_phone"><el-input v-model.trim="form.invoice_address_phone" maxlength="300" :placeholder="isSpecial ? '专票必填' : '选填'" /></el-form-item>
        <el-form-item label="开户行及账号" prop="invoice_bank_account"><el-input v-model.trim="form.invoice_bank_account" maxlength="300" :placeholder="isSpecial ? '专票必填' : '选填'" /></el-form-item>
        <el-form-item label="收票邮箱" prop="invoice_email"><el-input v-model.trim="form.invoice_email" maxlength="160" placeholder="电子发票发到这个邮箱（选填）" /></el-form-item>
        <el-form-item label="开票金额" prop="amount">
          <el-input-number v-model="form.amount" :min="0.01" :max="maxAmount" :precision="2" :step="100" controls-position="right" style="width: 200px" />
          <span class="muted amount-hint">最多 ¥{{ money(summary && summary.available_amount) }}（含税）</span>
        </el-form-item>
        <el-form-item label="税率">
          <el-select v-model="form.tax_rate" style="width: 200px"><el-option v-for="rate in taxRates" :key="rate" :label="`${rate}%`" :value="rate" /></el-select>
        </el-form-item>
        <el-form-item label="备注"><el-input v-model.trim="form.remark" type="textarea" :rows="2" maxlength="500" placeholder="例如：开票内容写“机械设备”、随货寄出纸票等" /></el-form-item>
        <el-form-item label=""><el-checkbox v-model="form.save_to_customer">同时保存为该客户的开票资料，下次自动带出</el-checkbox></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="dialogVisible = false">取消</el-button><el-button type="primary" :loading="saving" @click="submit">提交申请</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import { getSalesOrderInvoiceRequests, createSalesOrderInvoiceRequest, withdrawSalesInvoiceRequest, downloadSalesInvoiceFile } from '@/api/erp/sales'

const required = message => [{ required: true, message, trigger: 'blur' }]

export default {
  props: { orderId: { type: [Number, String], required: true } },
  data() {
    return {
      loading: false, saving: false, error: '', info: {}, dialogVisible: false,
      taxRates: [13, 9, 6, 3, 1, 0],
      form: this.emptyForm()
    }
  },
  computed: {
    summary() { return this.info.summary || null },
    isSpecial() { return this.form.invoice_type === 'vat_special' },
    maxAmount() { return Math.max(0.01, Number((this.summary && this.summary.available_amount) || 0)) },
    availableZero() { return !this.summary || Number(this.summary.available_amount) <= 0 },
    rules() {
      const special = this.isSpecial
      return {
        invoice_title: required('请填写发票抬头'),
        invoice_tax_no: special ? required('专票必须填写纳税人识别号') : [],
        invoice_address_phone: special ? required('专票必须填写地址、电话') : [],
        invoice_bank_account: special ? required('专票必须填写开户行及账号') : [],
        invoice_email: [{ type: 'email', message: '邮箱格式不对', trigger: 'blur' }],
        amount: [{ required: true, message: '请填写开票金额', trigger: 'change' }]
      }
    }
  },
  watch: { orderId: { immediate: true, handler() { this.load() } } },
  methods: {
    emptyForm() { return { invoice_type: 'vat_normal', invoice_title: '', invoice_tax_no: '', invoice_address_phone: '', invoice_bank_account: '', invoice_email: '', amount: undefined, tax_rate: 13, remark: '', save_to_customer: true } },
    money(v) { return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
    date(v) { return v ? String(v).slice(0, 10) : '-' },
    statusTone(v) { return ({ pending: 'warning', issued: 'success', rejected: 'danger', withdrawn: 'info' })[v] || 'info' },
    async load() {
      if (!this.orderId) return
      this.loading = true
      this.error = ''
      try {
        const { data } = await getSalesOrderInvoiceRequests(this.orderId)
        this.info = data.data || {}
        this.$emit('loaded', this.info)
      } catch (e) {
        this.error = e.userMessage || '开票信息加载失败，请重试。'
      } finally { this.loading = false }
    },
    openCreate() {
      const d = this.info.billing_defaults || {}
      this.form = { ...this.emptyForm(), invoice_type: d.invoice_type || 'vat_normal', invoice_title: d.invoice_title || '', invoice_tax_no: d.invoice_tax_no || '', invoice_address_phone: d.invoice_address_phone || '', invoice_bank_account: d.invoice_bank_account || '', invoice_email: d.invoice_email || '', amount: Number((this.summary && this.summary.available_amount) || 0) || undefined }
      this.dialogVisible = true
      this.$nextTick(() => this.$refs.form && this.$refs.form.clearValidate())
    },
    submit() {
      this.$refs.form.validate(async valid => {
        if (!valid) return
        this.saving = true
        try {
          const { data } = await createSalesOrderInvoiceRequest(this.orderId, { ...this.form, amount: Number(this.form.amount).toFixed(2) })
          this.$message.success(data.message || '开票申请已提交')
          this.dialogVisible = false
          this.load()
        } catch (e) {
          this.$message.error(e.userMessage || '提交失败')
        } finally { this.saving = false }
      })
    },
    async withdraw(row) {
      try { await this.$confirm(`撤回开票申请 ${row.request_no}？`, '撤回申请', { type: 'warning' }) } catch (e) { return }
      try {
        await withdrawSalesInvoiceRequest(row.id)
        this.$message.success('已撤回')
        this.load()
      } catch (e) { this.$message.error(e.userMessage || '撤回失败') }
    },
    async openFile(row, file) {
      try {
        const res = await downloadSalesInvoiceFile(row.id, file.id)
        const url = URL.createObjectURL(new Blob([res.data], { type: res.headers && res.headers['content-type'] }))
        const a = document.createElement('a')
        a.href = url
        a.target = '_blank'
        if (!/pdf|image/.test((res.headers && res.headers['content-type']) || '')) a.download = file.original_name
        a.click()
        setTimeout(() => URL.revokeObjectURL(url), 60000)
      } catch (e) { this.$message.error(e.userMessage || '文件打开失败') }
    }
  }
}
</script>

<style scoped>
.invoice-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 12px; }
.invoice-toolbar b { margin-right: 10px; }
.muted { color: #8a94a6; font-size: 12px; }
.invoice-facts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 12px 0; }
.invoice-facts div { border: 1px solid #e5eaf0; border-radius: 4px; padding: 10px 14px; }
.invoice-facts dt { color: #64748b; font-size: 12px; margin-bottom: 6px; }
.invoice-facts dd { margin: 0; font-size: 18px; font-weight: 600; color: #17233b; }
.green { color: #009b50 !important; }
.orange { color: #f97316 !important; }
.red { color: #dc2626; }
.amount-hint { margin-left: 10px; }
.font-mono { font-family: Menlo, Consolas, monospace; }
@media (max-width: 760px) { .invoice-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } .invoice-toolbar { flex-direction: column; align-items: flex-start; } }
</style>
