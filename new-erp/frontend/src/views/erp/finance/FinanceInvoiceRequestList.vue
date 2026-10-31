<template>
  <section :class="['invoice-request-page', { embedded }]">
    <!-- 独立访问时的顶部页头 -->
    <div v-if="!embedded" class="page-head">
      <div class="head-left">
        <div class="title-row">
          <h1>开票申请</h1>
          <el-tag size="mini" type="success" effect="plain">财务中心</el-tag>
        </div>
        <p class="subtitle">处理销售在订单中提交的客户开票申请，登记发票号后自动生成销项发票并回写订单</p>
      </div>
      <div class="head-actions">
        <el-button
          v-if="$can('finance.invoice.view')"
          size="small"
          icon="el-icon-tickets"
          @click="$router.push({ path: '/finance/invoices', query: { tab: 'sales' } })"
        >
          查看销项发票
        </el-button>
      </div>
    </div>

    <!-- 核心指标统计条 -->
    <div class="metrics-strip">
      <div class="metric-card">
        <div class="metric-icon icon-orange"><i class="el-icon-time" /></div>
        <div class="metric-body">
          <span class="metric-label">待开票申请</span>
          <div class="metric-val"><b class="orange">{{ summary.pending_count || 0 }}</b><em>张</em></div>
        </div>
      </div>
      <div class="metric-card">
        <div class="metric-icon icon-amber"><i class="el-icon-wallet" /></div>
        <div class="metric-body">
          <span class="metric-label">待开票金额</span>
          <div class="metric-val"><b class="orange">¥{{ money(summary.pending_amount) }}</b><em>CNY</em></div>
        </div>
      </div>
      <div class="metric-card">
        <div class="metric-icon icon-green"><i class="el-icon-circle-check" /></div>
        <div class="metric-body">
          <span class="metric-label">本月已开票金额</span>
          <div class="metric-val"><b class="green">¥{{ money(summary.issued_month_amount) }}</b><em>CNY</em></div>
        </div>
      </div>
    </div>

    <!-- 筛选栏 -->
    <div class="filter-panel">
      <el-form class="filter-form" size="small" @submit.native.prevent="search">
        <div class="filter-fields">
          <div class="filter-item filter-item-status">
            <span class="filter-label">处理状态</span>
            <el-select v-model="filters.status" clearable placeholder="全部状态" @change="search">
              <el-option label="待开票" value="pending" />
              <el-option label="已开票" value="issued" />
              <el-option label="已驳回" value="rejected" />
              <el-option label="已撤回" value="withdrawn" />
            </el-select>
          </div>
          <div class="filter-item filter-item-keyword">
            <span class="filter-label">关键字搜索</span>
            <el-input
              v-model.trim="filters.keyword"
              clearable
              prefix-icon="el-icon-search"
              placeholder="申请单号 / 订单号 / 客户 / 抬头 / 发票号"
              @keyup.enter.native="search"
              @clear="search"
            />
          </div>
          <div class="filter-item filter-item-date">
            <span class="filter-label">申请日期</span>
            <el-date-picker
              v-model="dateRange"
              type="daterange"
              value-format="yyyy-MM-dd"
              range-separator="至"
              start-placeholder="开始日期"
              end-placeholder="结束日期"
              @change="search"
            />
          </div>
          <div class="filter-actions">
            <el-button type="success" size="small" icon="el-icon-search" class="green-button" @click="search">查询</el-button>
            <el-button size="small" icon="el-icon-refresh" @click="reset">重置</el-button>
          </div>
        </div>
      </el-form>
    </div>

    <!-- 申请列表表格 -->
    <el-table
      v-loading="loading"
      :data="rows"
      border
      stripe
      fit
      size="small"
      class="request-table"
      empty-text="暂无开票申请记录"
    >
      <el-table-column label="申请单号 / 申请人" min-width="155" show-overflow-tooltip>
        <template slot-scope="{ row }">
          <span class="font-mono code-text">{{ row.request_no }}</span>
          <div class="muted">{{ row.requested_by_name || '-' }} · {{ dateTime(row.created_at) }}</div>
        </template>
      </el-table-column>

      <el-table-column label="销售订单 / 客户" min-width="175" show-overflow-tooltip>
        <template slot-scope="{ row }">
          <el-button
            type="text"
            size="mini"
            class="action-link-green font-mono"
            @click="$router.push(`/sales/orders/${row.sales_order_id}/detail`)"
          >
            {{ row.sales_order_no }}
          </el-button>
          <div class="party-name">{{ row.customer_name || '-' }}</div>
        </template>
      </el-table-column>

      <el-table-column label="客户开票资料" min-width="260">
        <template slot-scope="{ row }">
          <div class="billing-head">
            <el-tag size="mini" :type="row.invoice_type === 'vat_special' ? 'danger' : 'success'" effect="plain">
              {{ row.invoice_type === 'vat_special' ? '增值税专票' : '增值税普票' }}
            </el-tag>
            <b class="billing-title">{{ row.invoice_title || '-' }}</b>
          </div>
          <div class="billing-meta">
            <span v-if="row.invoice_tax_no"><b>税号：</b>{{ row.invoice_tax_no }}</span>
            <span v-if="row.invoice_address_phone"><b>地址电话：</b>{{ row.invoice_address_phone }}</span>
            <span v-if="row.invoice_bank_account"><b>开户行：</b>{{ row.invoice_bank_account }}</span>
            <span v-if="row.invoice_email"><b>邮箱：</b>{{ row.invoice_email }}</span>
          </div>
        </template>
      </el-table-column>

      <el-table-column label="申请金额（含税）" width="130" align="right">
        <template slot-scope="{ row }">
          <b class="amount">¥{{ money(row.amount) }}</b>
          <div class="muted">税率 {{ Number(row.tax_rate) }}%</div>
        </template>
      </el-table-column>

      <el-table-column label="申请备注" min-width="125" show-overflow-tooltip>
        <template slot-scope="{ row }">
          <span class="remark-text">{{ row.remark || '—' }}</span>
        </template>
      </el-table-column>

      <el-table-column label="状态" width="84" align="center">
        <template slot-scope="{ row }">
          <el-tag size="mini" :type="statusTone(row.status)">{{ row.status_label }}</el-tag>
        </template>
      </el-table-column>

      <el-table-column label="处理结果" min-width="180" show-overflow-tooltip>
        <template slot-scope="{ row }">
          <template v-if="row.status === 'issued'">
            <div>
              <b class="font-mono code-text">{{ row.invoice_no }}</b>
              <span class="muted"> ({{ row.invoice_date }})</span>
            </div>
            <div v-for="file in row.attachments" :key="file.id" class="file-link-row">
              <el-button type="text" size="mini" icon="el-icon-document" class="action-link-green" @click="openFile(row, file)">
                {{ file.original_name }}
              </el-button>
            </div>
          </template>
          <span v-else-if="row.status === 'rejected'" class="red">{{ row.handle_remark }}</span>
          <span v-else class="muted">—</span>
          <div v-if="row.handled_by_name" class="muted">{{ row.handled_by_name }} · {{ dateTime(row.handled_at) }}</div>
        </template>
      </el-table-column>

      <el-table-column v-if="canHandle" label="操作" width="195" fixed="right" align="center">
        <template slot-scope="{ row }">
          <div class="row-actions">
            <template v-if="row.status === 'pending'">
              <el-button type="text" size="mini" icon="el-icon-document-copy" class="action-link-green" @click="copyInfo(row)">复制</el-button>
              <el-button type="text" size="mini" icon="el-icon-edit-outline" class="action-link-green" @click="openIssue(row)">开票登记</el-button>
              <el-button type="text" size="mini" icon="el-icon-close" class="danger-link" @click="reject(row)">驳回</el-button>
            </template>
            <el-button
              v-else-if="row.status === 'issued'"
              type="text"
              size="mini"
              icon="el-icon-upload2"
              class="action-link-green"
              @click="pickUpload(row)"
            >
              传发票文件
            </el-button>
            <span v-else class="muted">—</span>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <div class="pager">
      <el-pagination
        background
        layout="total, prev, pager, next, sizes, jumper"
        :total="total"
        :current-page="page"
        :page-size="perPage"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="p => { page = p; load() }"
        @size-change="s => { perPage = s; page = 1; load() }"
      />
    </div>

    <input ref="uploadInput" type="file" accept=".pdf,.ofd,.xml,image/*" style="display: none" @change="uploadPicked">

    <!-- 开票登记居中弹窗 -->
    <el-dialog
      title="开票登记"
      :visible.sync="issue.visible"
      width="min(640px, calc(100vw - 32px))"
      top="6vh"
      append-to-body
      :close-on-click-modal="false"
    >
      <template v-if="issue.row">
        <div class="issue-info">
          <div class="issue-info-item">
            <span>客户与订单</span>
            <b>{{ issue.row.sales_order_no }} · {{ issue.row.customer_name }}</b>
          </div>
          <div class="issue-info-item">
            <span>发票类型</span>
            <b>{{ issue.row.invoice_type_label }}</b>
          </div>
          <div class="issue-info-item">
            <span>发票抬头</span>
            <b>{{ issue.row.invoice_title }}</b>
          </div>
          <div v-if="issue.row.invoice_tax_no" class="issue-info-item">
            <span>纳税人识别号</span>
            <b class="font-mono">{{ issue.row.invoice_tax_no }}</b>
          </div>
          <div class="issue-info-item">
            <span>价税合计</span>
            <b class="amount green">¥{{ money(issue.row.amount) }}</b>
          </div>
          <div class="issue-info-item">
            <span>不含税 / 税额</span>
            <b>¥{{ money(issueExcl) }} / ¥{{ money(issueTax) }}</b>
          </div>
        </div>

        <el-form ref="issueForm" :model="issue.form" :rules="issueRules" label-width="96px" size="small" class="issue-form">
          <el-form-item label="发票号码" prop="invoice_no">
            <el-input v-model.trim="issue.form.invoice_no" maxlength="120" placeholder="请输入发票号码（数电票填 20 位号码）" />
          </el-form-item>
          <el-form-item label="发票代码">
            <el-input v-model.trim="issue.form.invoice_code" maxlength="80" placeholder="选填，数电票无需填写" />
          </el-form-item>
          <div class="form-row-2">
            <el-form-item label="开票日期" prop="invoice_date">
              <el-date-picker
                v-model="issue.form.invoice_date"
                type="date"
                value-format="yyyy-MM-dd"
                placeholder="选择开票日期"
                style="width: 100%"
              />
            </el-form-item>
            <el-form-item label="适用税率">
              <el-select v-model="issue.form.tax_rate" style="width: 100%">
                <el-option v-for="rate in taxRates" :key="rate" :label="`${rate}%`" :value="rate" />
              </el-select>
            </el-form-item>
          </div>
          <el-form-item label="发票文件">
            <div class="file-picker-box">
              <el-button size="small" icon="el-icon-upload2" @click="$refs.issueFile && $refs.issueFile.click()">选择文件</el-button>
              <span v-if="issue.file" class="picked-file-name">
                <i class="el-icon-document" /> {{ issue.file.name }}
                <i class="el-icon-close remove-file" @click="clearIssueFile" />
              </span>
              <span v-else class="muted">支持电子发票 PDF / OFD / XML 或纸票照片（可稍后补传）</span>
              <input
                ref="issueFile"
                type="file"
                accept=".pdf,.ofd,.xml,image/*"
                style="display: none"
                @change="e => (issue.file = e.target.files[0] || null)"
              >
            </div>
          </el-form-item>
          <el-form-item label="登记备注">
            <el-input v-model.trim="issue.form.remark" type="textarea" :rows="2" maxlength="500" placeholder="选填，填写开票补充说明" />
          </el-form-item>
        </el-form>
      </template>
      <span slot="footer">
        <el-button size="small" @click="issue.visible = false">取消</el-button>
        <el-button size="small" type="success" class="green-button" :loading="issue.saving" @click="submitIssue">确认登记</el-button>
      </span>
    </el-dialog>
  </section>
</template>

<script>
import { listSalesInvoiceRequests, issueSalesInvoiceRequest, uploadSalesInvoiceRequestFile, rejectSalesInvoiceRequest } from '@/api/erp/finance'
import { downloadSalesInvoiceFile } from '@/api/erp/sales'

const today = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export default {
  name: 'FinanceInvoiceRequestList',
  props: { embedded: { type: Boolean, default: false } },
  data: () => ({
    loading: false,
    rows: [],
    total: 0,
    page: 1,
    perPage: 10,
    summary: {},
    filters: { status: 'pending', keyword: '' },
    dateRange: [],
    taxRates: [13, 9, 6, 3, 1, 0],
    uploadRow: null,
    issue: { visible: false, saving: false, row: null, file: null, form: {} },
    issueRules: {
      invoice_no: [{ required: true, message: '请填写发票号码', trigger: 'blur' }],
      invoice_date: [{ required: true, message: '请选择开票日期', trigger: 'change' }]
    }
  }),
  computed: {
    canHandle() {
      return this.$can('finance.invoice_request.handle')
    },
    issueExcl() {
      if (!this.issue.row) return 0
      return Math.round((Number(this.issue.row.amount) / (1 + Number(this.issue.form.tax_rate || 0) / 100)) * 100) / 100
    },
    issueTax() {
      return this.issue.row ? Math.round((Number(this.issue.row.amount) - this.issueExcl) * 100) / 100 : 0
    }
  },
  created() {
    this.load()
  },
  methods: {
    money(v) {
      return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    dateTime(v) {
      return v ? String(v).replace('T', ' ').slice(0, 16) : '-'
    },
    statusTone(v) {
      return { pending: 'warning', issued: 'success', rejected: 'danger', withdrawn: 'info' }[v] || 'info'
    },
    search() {
      this.page = 1
      this.load()
    },
    reset() {
      this.filters = { status: 'pending', keyword: '' }
      this.dateRange = []
      this.search()
    },
    clearIssueFile() {
      this.issue.file = null
      if (this.$refs.issueFile) this.$refs.issueFile.value = ''
    },
    async load() {
      this.loading = true
      try {
        const [start, end] = this.dateRange || []
        const { data } = await listSalesInvoiceRequests({
          ...this.filters,
          date_start: start || undefined,
          date_end: end || undefined,
          page: this.page,
          per_page: this.perPage
        })
        this.rows = data.data || []
        this.total = data.total || 0
        this.summary = data.summary || {}
        this.$emit('summary', this.summary)
      } catch (e) {
        this.$message.error(e.userMessage || '开票申请加载失败')
      } finally {
        this.loading = false
      }
    },
    copyInfo(row) {
      const text = [
        `发票类型：${row.invoice_type_label}`,
        `抬头：${row.invoice_title}`,
        row.invoice_tax_no && `税号：${row.invoice_tax_no}`,
        row.invoice_address_phone && `地址电话：${row.invoice_address_phone}`,
        row.invoice_bank_account && `开户行及账号：${row.invoice_bank_account}`,
        `金额（含税）：${this.money(row.amount)}`,
        `税率：${Number(row.tax_rate)}%`,
        row.invoice_email && `收票邮箱：${row.invoice_email}`,
        row.remark && `备注：${row.remark}`
      ]
        .filter(Boolean)
        .join('\n')
      const done = () => this.$message.success('开票信息已复制')
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, () => this.fallbackCopy(text, done))
      } else {
        this.fallbackCopy(text, done)
      }
    },
    fallbackCopy(text, done) {
      const area = document.createElement('textarea')
      area.value = text
      document.body.appendChild(area)
      area.select()
      try {
        document.execCommand('copy')
        done()
      } catch (e) {
        this.$message.warning('复制失败，请手动复制')
      }
      document.body.removeChild(area)
    },
    openIssue(row) {
      this.issue = {
        visible: true,
        saving: false,
        row,
        file: null,
        form: { invoice_no: '', invoice_code: '', invoice_date: today(), tax_rate: Number(row.tax_rate), remark: '' }
      }
      this.$nextTick(() => {
        if (this.$refs.issueFile) this.$refs.issueFile.value = ''
        if (this.$refs.issueForm) this.$refs.issueForm.clearValidate()
      })
    },
    submitIssue() {
      this.$refs.issueForm.validate(async valid => {
        if (!valid) return
        const form = new FormData()
        Object.entries(this.issue.form).forEach(([key, value]) => {
          if (value !== '' && value !== null && value !== undefined) form.append(key, value)
        })
        if (this.issue.file) form.append('file', this.issue.file)
        this.issue.saving = true
        try {
          const { data } = await issueSalesInvoiceRequest(this.issue.row.id, form)
          this.$message[data.data && data.data.upload_failed ? 'warning' : 'success'](data.message || '已登记开票')
          this.issue.visible = false
          this.load()
        } catch (e) {
          this.$message.error(e.userMessage || '登记失败')
        } finally {
          this.issue.saving = false
        }
      })
    },
    async reject(row) {
      let reason
      try {
        const res = await this.$prompt(`驳回 ${row.request_no}，销售会在订单里看到原因`, '驳回开票申请', {
          inputPlaceholder: '例如：抬头和税号对不上、订单还没收款',
          inputValidator: v => !!(v && v.trim()) || '请填写驳回原因'
        })
        reason = res.value.trim()
      } catch (e) {
        return
      }
      try {
        await rejectSalesInvoiceRequest(row.id, reason)
        this.$message.success('已驳回')
        this.load()
      } catch (e) {
        this.$message.error(e.userMessage || '驳回失败')
      }
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
      } catch (err) {
        this.$message.error(err.userMessage || '上传失败')
      }
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
      } catch (e) {
        this.$message.error(e.userMessage || '文件打开失败')
      }
    }
  }
}
</script>

<style scoped>
.invoice-request-page {
  min-height: calc(100vh - 58px);
  padding: 16px 20px 24px;
  background: #f8fafc;
  color: #1e293b;
  box-sizing: border-box;
}

.invoice-request-page.embedded {
  min-height: 0;
  padding: 0;
  background: transparent;
}

/* 顶部页头卡片 */
.page-head {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  margin-bottom: 12px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.head-left {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.title-row h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
}

.subtitle {
  margin: 0;
  font-size: 12px;
  color: #64748b;
}

/* 指标统计条 */
.metrics-strip {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 14px;
}

.metric-card {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #f8fafc;
  box-sizing: border-box;
}

.metric-icon {
  width: 40px;
  height: 40px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
}

.icon-orange {
  background: #ffedd5;
  color: #ea580c;
}

.icon-amber {
  background: #fef3c7;
  color: #d97706;
}

.icon-green {
  background: #dcfce7;
  color: #008b4b;
}

.metric-body {
  min-width: 0;
  flex: 1;
}

.metric-label {
  display: block;
  font-size: 12px;
  color: #64748b;
  margin-bottom: 4px;
}

.metric-val {
  display: flex;
  align-items: baseline;
  gap: 6px;
}

.metric-val b {
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

.metric-val em {
  font-style: normal;
  font-size: 12px;
  color: #94a3b8;
}

.green {
  color: #008b4b;
}

.orange {
  color: #ea580c;
}

.red {
  color: #dc2626;
}

/* 筛选面板 */
.filter-panel {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  margin-bottom: 14px;
}

.filter-fields {
  display: grid;
  grid-template-columns: 160px minmax(220px, 1.4fr) minmax(240px, 1.2fr) auto;
  gap: 12px 14px;
  align-items: end;
}

.filter-item {
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-width: 0;
}

.filter-label {
  font-size: 12px;
  font-weight: 500;
  color: #475569;
  line-height: 1.2;
}

.filter-item .el-select,
.filter-item .el-input,
.filter-item .el-date-editor {
  width: 100% !important;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.green-button {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}

.green-button:hover,
.green-button:focus {
  background-color: #00763f !important;
  border-color: #00763f !important;
}

/* 表格样式 */
.request-table {
  width: 100%;
}

.request-table ::v-deep th {
  background-color: #f8fafc !important;
  color: #475569;
  font-weight: 600;
}

.code-text {
  font-weight: 600;
  color: #0f172a;
}

.party-name {
  font-weight: 500;
  color: #334155;
  font-size: 12px;
}

.billing-head {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 4px;
}

.billing-title {
  color: #0f172a;
  font-size: 13px;
}

.billing-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 2px 12px;
  font-size: 12px;
  color: #64748b;
  line-height: 1.5;
}

.billing-meta b {
  font-weight: 500;
  color: #475569;
}

.remark-text {
  color: #475569;
}

.action-link-green {
  color: #008b4b !important;
  font-weight: 500;
}

.action-link-green:hover {
  color: #006334 !important;
}

.danger-link {
  color: #ef4444 !important;
  font-weight: 500;
}

.row-actions {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  flex-wrap: wrap;
}

.muted {
  color: #94a3b8;
  font-size: 12px;
  line-height: 18px;
}

.amount {
  font-weight: 700;
  color: #0f172a;
}

.font-mono {
  font-family: Menlo, Consolas, monospace;
}

.pager {
  margin-top: 14px;
  display: flex;
  justify-content: flex-end;
}

/* 开票登记弹窗 */
.issue-info {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 16px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 16px;
  margin-bottom: 16px;
}

.issue-info-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.issue-info-item span {
  color: #64748b;
  font-size: 12px;
}

.issue-info-item b {
  color: #0f172a;
  font-size: 13px;
  word-break: break-all;
}

.form-row-2 {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.file-picker-box {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}

.picked-file-name {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 2px 10px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 4px;
  color: #008b4b;
  font-size: 12px;
}

.remove-file {
  cursor: pointer;
  color: #64748b;
}

.remove-file:hover {
  color: #ef4444;
}

@media (max-width: 1080px) {
  .filter-fields {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 760px) {
  .metrics-strip,
  .filter-fields,
  .issue-info,
  .form-row-2 {
    grid-template-columns: 1fr;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
