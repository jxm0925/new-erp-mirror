<template>
  <main v-loading="loading" class="invoice-detail-page">
    <header class="page-head">
      <div class="head-left">
        <div class="title-row">
          <h1 class="page-title">{{ invoice.invoice_direction === 'sales' ? '销项发票详情' : '进项发票详情' }}</h1>
          <span class="doc-pill">{{ invoice.document_no || '—' }}</span>
          <el-tag size="small" :type="invoice.status === 'confirmed' ? 'success' : 'info'">{{ statusText(invoice.status) }}</el-tag>
          <el-tag v-if="invoice.invoice_direction !== 'sales'" size="small" :type="matchTagType">{{ matchText }}</el-tag>
        </div>
        <p class="page-desc">
          {{ invoice.party_name_snapshot || '—' }} · {{ invoiceType(invoice.invoice_type) }} · 开票日期 {{ invoice.invoice_date || '—' }}
        </p>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-back" @click="$router.push({ path: '/finance/invoices', query: { tab: invoice.invoice_direction === 'sales' ? 'sales' : 'purchase' } })">返回发票列表</el-button>
        <el-button size="small" icon="el-icon-printer" @click="printPage">打印</el-button>
        <el-button
          v-if="invoice.status === 'draft' && $can('finance.invoice.update_draft')"
          size="small"
          icon="el-icon-edit"
          @click="$router.push(`/finance/invoices/${invoice.id}/edit`)"
        >编辑草稿</el-button>
        <el-button
          v-if="invoice.invoice_direction !== 'sales' && invoice.status === 'draft' && $can('finance.invoice.match')"
          size="small"
          type="success"
          class="green-button"
          icon="el-icon-connection"
          @click="$router.push(`/finance/invoices/${invoice.id}/match`)"
        >发票匹配</el-button>
        <el-button
          v-if="invoice.invoice_direction !== 'sales'"
          size="small"
          class="red-outline"
          icon="el-icon-refresh-left"
          :disabled="invoice.status !== 'confirmed' || !!invoice.red_invoice_of_id"
          @click="$router.push(`/finance/invoices/${invoice.id}/red`)"
        >红冲发票</el-button>
      </div>
    </header>

    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span v-if="invoice.invoice_direction === 'sales'">
        销项发票由销售订单开票申请登记生成，发票金额、税额与电子发票附件一并留档备查。
      </span>
      <span v-else-if="invoice.status === 'confirmed'">
        该进项发票已确认匹配，可用于进项税额抵扣勾选与应付结算核销；如需冲减请发起红冲发票。
      </span>
      <span v-else>
        当前发票处于「{{ statusText(invoice.status) }}」状态，支持修改草稿、关联采购结算单匹配或撤销生效中的匹配记录。
      </span>
    </div>

    <section class="top-grid">
      <article class="panel invoice-info">
        <div class="panel-title">
          <h2>发票基础信息</h2>
          <span>完整记录票面要素与往来主体快照</span>
        </div>
        <div class="info-grid">
          <div class="kv-item">
            <span class="kv-label">发票记录号</span>
            <strong class="kv-val mono">{{ invoice.document_no || '—' }}</strong>
          </div>
          <div class="kv-item">
            <span class="kv-label">发票号码</span>
            <strong class="kv-val mono">{{ invoice.invoice_no || '—' }}</strong>
          </div>
          <div class="kv-item">
            <span class="kv-label">发票代码</span>
            <span class="kv-val mono">{{ invoice.invoice_code || '—' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">发票类型</span>
            <span class="kv-val">{{ invoiceType(invoice.invoice_type) }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">{{ invoice.invoice_direction === 'sales' ? '客户名称' : '供应商名称' }}</span>
            <strong class="kv-val">{{ invoice.party_name_snapshot || '—' }}</strong>
          </div>
          <div class="kv-item">
            <span class="kv-label">发票状态</span>
            <span class="kv-val green-text">{{ statusText(invoice.status) }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">开票日期</span>
            <span class="kv-val">{{ invoice.invoice_date || '—' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">{{ invoice.invoice_direction === 'sales' ? '登记日期' : '收票日期' }}</span>
            <span class="kv-val">{{ invoice.received_date || invoice.invoice_date || '—' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">结算币种</span>
            <span class="kv-val">{{ invoice.currency || 'CNY' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">折算税率</span>
            <span class="kv-val">{{ taxRate }}%</span>
          </div>
          <div class="kv-item full">
            <span class="kv-label">发票备注</span>
            <span class="kv-val">{{ invoice.remark || '—' }}</span>
          </div>
        </div>
      </article>

      <div class="side-panels">
        <article class="panel compact-panel tax-panel">
          <div class="panel-title compact">
            <h2>金额与税额</h2>
            <span class="currency-pill">{{ invoice.currency || 'CNY' }}</span>
          </div>
          <div class="amount-row">
            <span>金额（不含税）</span>
            <b>¥{{ money(invoice.amount_excl_tax) }}</b>
          </div>
          <div class="amount-row">
            <span>税额（{{ taxRate }}%）</span>
            <b>¥{{ money(invoice.tax_amount) }}</b>
          </div>
          <div class="amount-row total">
            <span>价税合计</span>
            <b class="total-num">¥{{ money(invoice.amount_incl_tax) }}</b>
          </div>
        </article>

        <article class="panel compact-panel match-panel">
          <div class="panel-title compact">
            <h2>匹配进度</h2>
            <el-tag size="mini" :type="matchTagType">{{ matchText }}</el-tag>
          </div>
          <div class="amount-row">
            <span>已匹配金额（价税合计）</span>
            <b class="green">¥{{ money(invoice.matched_amount) }}</b>
          </div>
          <div class="amount-row">
            <span>未匹配金额（价税合计）</span>
            <b :class="Number(invoice.unmatched_amount || 0) > 0 ? 'red' : 'muted'">¥{{ money(invoice.unmatched_amount) }}</b>
          </div>
          <div class="amount-row">
            <span>可用匹配余额</span>
            <b>¥{{ money(invoice.unmatched_amount) }}</b>
          </div>
        </article>
      </div>
    </section>

    <section v-if="invoice.invoice_direction !== 'sales'" class="panel match-table-panel">
      <div class="panel-head-bar">
        <div class="panel-title">
          <h2>本票匹配明细</h2>
          <span>匹配历史完整保留，草稿状态才允许撤销有效匹配。</span>
        </div>
        <div class="match-summary-pills">
          <span class="summary-chip">来源金额合计：<b>¥{{ money(matchSourceTotal) }}</b></span>
          <span class="summary-chip active">本页匹配合计：<b>¥{{ money(matchAllocatedTotal) }}</b></span>
        </div>
      </div>

      <el-table :data="matchRows" border stripe fit size="small" class="detail-table" empty-text="暂无匹配记录">
        <el-table-column type="index" label="#" width="48" align="center" />
        <el-table-column prop="source_business_type_label" label="匹配来源" min-width="110" show-overflow-tooltip />
        <el-table-column prop="source_document_no" label="结算来源单号" min-width="140" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <span class="mono-strong">{{ row.source_document_no || '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="收货单号 / 采购订单号" min-width="175" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <div class="dual-doc">
              <span>{{ row.receipt_no || '—' }}</span>
              <small>{{ row.purchase_order_no || '—' }}</small>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="business_date" label="业务日期" min-width="102" />
        <el-table-column label="来源金额 (CNY)" min-width="124" align="right">
          <template slot-scope="{ row }">¥{{ money(row.source_amount_snapshot) }}</template>
        </el-table-column>
        <el-table-column label="本次匹配金额 (CNY)" min-width="134" align="right">
          <template slot-scope="{ row }">
            <b class="green">¥{{ money(row.allocated_amount) }}</b>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template slot-scope="{ row }">
            <el-tag size="mini" :type="allocationTone(row.status)">{{ allocationText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="匹配时间" min-width="142" show-overflow-tooltip />
        <el-table-column label="操作" width="92" align="center">
          <template slot-scope="{ row }">
            <el-button v-if="canReverse(row)" type="text" class="danger-link" @click="openReverse(row)">撤销匹配</el-button>
            <span v-else class="muted">—</span>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager-bar">
        <el-pagination
          background
          layout="total, prev, pager, next, sizes, jumper"
          :current-page="matchPage"
          :page-size="matchPerPage"
          :page-sizes="[10, 20, 50]"
          :total="matchTotal"
          @current-change="changeMatchPage"
          @size-change="changeMatchSize"
        />
      </div>
    </section>

    <section class="bottom-grid">
      <article class="panel attachments-panel">
        <div class="panel-title">
          <h2>发票附件</h2>
          <span>共 {{ attachments.length }} 份电子票据/扫描件</span>
        </div>
        <div v-if="attachments.length" class="attachment-list">
          <div v-for="file in attachments" :key="file.id" class="attachment-row">
            <div class="attachment-thumb" @click="preview(file)">
              <img v-if="file.thumnailUrl" :src="file.thumnailUrl" :alt="file.original_name">
              <i v-else class="el-icon-document" />
            </div>
            <div class="attachment-meta">
              <div class="meta-line">
                <span>文件名称</span>
                <b :title="file.original_name">{{ file.original_name }}</b>
              </div>
              <div class="meta-line">
                <span>文件大小</span>
                <b>{{ fileSize(file.file_size) }}</b>
              </div>
              <div class="meta-line">
                <span>上传时间</span>
                <b>{{ file.uploaded_at || '—' }}</b>
              </div>
              <el-button type="text" class="action-link" icon="el-icon-view" @click="preview(file)">在线预览</el-button>
            </div>
          </div>
        </div>
        <el-empty v-else :image-size="56" description="暂无发票附件" />
      </article>

      <article class="panel log-panel">
        <div class="panel-title">
          <h2>操作日志</h2>
          <span>按发生时间倒序记录</span>
        </div>
        <el-table :data="logRows" border stripe fit size="small" class="log-table" empty-text="暂无操作日志">
          <el-table-column prop="created_at" label="操作时间" min-width="148" />
          <el-table-column prop="operator_name" label="操作人" width="110" show-overflow-tooltip />
          <el-table-column label="动作" width="116">
            <template slot-scope="{ row }">
              <span class="action-badge">{{ actionText(row.action) }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="content" label="操作说明" min-width="220" show-overflow-tooltip />
        </el-table>
        <div class="pager-bar">
          <el-pagination
            background
            small
            layout="total, prev, pager, next"
            :current-page="logPage"
            :page-size="logPerPage"
            :total="logTotal"
            @current-change="p => { logPage = p; load() }"
          />
        </div>
      </article>
    </section>

    <el-dialog title="撤销发票匹配" :visible.sync="reverseVisible" width="480px" :close-on-click-modal="false">
      <p class="dialog-tip">撤销会生成反向匹配事实并恢复来源可开票余额，原记录不会被删除。</p>
      <el-form label-width="84px">
        <el-form-item label="匹配来源">
          <span class="mono-strong">{{ reverseRow && reverseRow.source_document_no }}</span>
        </el-form-item>
        <el-form-item label="撤销原因" required>
          <el-input v-model.trim="reverseReason" type="textarea" :rows="3" maxlength="255" show-word-limit placeholder="请填写撤销原因" />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button size="small" @click="reverseVisible = false">取消</el-button>
        <el-button size="small" type="danger" :loading="reversing" @click="reverse">确认撤销</el-button>
      </span>
    </el-dialog>
  </main>
</template>

<script>
import { getFinanceInvoiceDetail, previewFinanceAttachment, reverseFinanceInvoiceMatch } from '../../../api/erp/finance'

export default {
  name: 'FinanceInvoiceDetail',
  data: () => ({
    loading: false,
    invoice: {},
    attachments: [],
    matchRows: [],
    matchPage: 1,
    matchPerPage: 10,
    matchTotal: 0,
    logRows: [],
    logPage: 1,
    logPerPage: 10,
    logTotal: 0,
    reverseVisible: false,
    reverseRow: null,
    reverseReason: '',
    reversing: false
  }),
  computed: {
    taxRate() {
      const tax = Number(this.invoice.tax_amount || 0)
      const excl = Number(this.invoice.amount_excl_tax || 0)
      return excl > 0 ? (tax / excl * 100).toFixed(2).replace(/\.00$/, '') : '0'
    },
    matchText() {
      const amount = Number(this.invoice.amount_incl_tax || 0)
      const matched = Number(this.invoice.matched_amount || 0)
      return matched <= 0 ? '未匹配' : matched >= amount ? '已匹配' : '部分匹配'
    },
    matchTagType() {
      const amount = Number(this.invoice.amount_incl_tax || 0)
      const matched = Number(this.invoice.matched_amount || 0)
      return matched <= 0 ? 'info' : matched >= amount ? 'success' : 'warning'
    },
    matchSourceTotal() {
      return this.matchRows.reduce((sum, row) => sum + Number(row.source_amount_snapshot || 0), 0)
    },
    matchAllocatedTotal() {
      return this.matchRows.reduce((sum, row) => sum + Number(row.allocated_amount || 0), 0)
    }
  },
  created() {
    this.load()
  },
  beforeDestroy() {
    this.attachments.forEach(file => {
      if (file.thumnailUrl) URL.revokeObjectURL(file.thumnailUrl)
    })
  },
  methods: {
    async load() {
      this.loading = true
      try {
        const r = await getFinanceInvoiceDetail(this.$route.params.id, {
          match_page: this.matchPage,
          match_per_page: this.matchPerPage,
          log_page: this.logPage,
          log_per_page: this.logPerPage
        })
        const d = r.data.data || {}
        this.invoice = d.invoice || {}
        this.matchRows = d.match_history?.data || []
        this.matchTotal = Number(d.match_history?.total || 0)
        this.logRows = d.operation_logs?.data || []
        this.logTotal = Number(d.operation_logs?.total || 0)
        this.attachments = (d.attachments || []).map(x => ({ ...x, thumnailUrl: '' }))
        this.loadThumbnails()
      } catch (e) {
        this.$message.error(e.userMessage || '发票详情加载失败')
      } finally {
        this.loading = false
      }
    },
    async loadThumbnails() {
      for (const file of this.attachments.filter(x => String(x.mime_type || '').startsWith('image/'))) {
        try {
          const r = await previewFinanceAttachment(file.id)
          this.$set(file, 'thumnailUrl', URL.createObjectURL(r.data))
        } catch (e) {
          /* preview remains available without a thumbnail */
        }
      }
    },
    money(v) {
      return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    fileSize(v) {
      const n = Number(v || 0)
      return n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : `${(n / 1048576).toFixed(2)} MB`
    },
    statusText(v) {
      return ({ draft: '草稿', confirmed: '已确认', pending_red: '待红冲', red: '已红冲', voided: '已作废' })[v] || v || '—'
    },
    invoiceType(v) {
      return ({ vat_special: '增值税专用发票', vat_normal: '增值税普通发票', other: '其他' })[v] || v || '—'
    },
    allocationText(v) {
      return ({ active: '生效中', reversed: '已撤销', reversal: '反向事实' })[v] || v || '—'
    },
    allocationTone(v) {
      return ({ active: 'success', reversed: 'info', reversal: 'warning' })[v] || 'info'
    },
    actionText(v) {
      return ({ create: '登记发票', update_draft: '修改草稿', match: '保存匹配', clear_match: '清空匹配', reverse_match: '撤销匹配', confirm: '确认发票' })[v] || v || '—'
    },
    changeMatchPage(p) {
      this.matchPage = p
      this.load()
    },
    changeMatchSize(s) {
      this.matchPerPage = s
      this.matchPage = 1
      this.load()
    },
    canReverse(row) {
      return this.invoice.status === 'draft' && row.status === 'active' && this.$can('finance.invoice.reverse_match')
    },
    openReverse(row) {
      this.reverseRow = row
      this.reverseReason = ''
      this.reverseVisible = true
    },
    async reverse() {
      if (!this.reverseReason) {
        this.$message.warning('请填写撤销原因')
        return
      }
      this.reversing = true
      try {
        await reverseFinanceInvoiceMatch(this.reverseRow.id, this.reverseReason)
        this.$message.success('已生成撤销匹配事实')
        this.reverseVisible = false
        await this.load()
      } catch (e) {
        this.$message.error(e.userMessage || '撤销匹配失败')
      } finally {
        this.reversing = false
      }
    },
    async preview(file) {
      try {
        const r = await previewFinanceAttachment(file.id)
        const url = URL.createObjectURL(r.data)
        window.open(url, '_blank', 'noopener')
        setTimeout(() => URL.revokeObjectURL(url), 60000)
      } catch (e) {
        this.$message.error(e.userMessage || '该附件暂不支持在线预览')
      }
    },
    printPage() {
      window.print()
    }
  }
}
</script>

<style scoped>
.invoice-detail-page {
  min-height: calc(100vh - 58px);
  padding: 16px;
  background: #f4f6f8;
  color: #17233b;
  box-sizing: border-box;
}

.page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 18px;
  margin-bottom: 12px;
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #17233b;
}

.doc-pill {
  padding: 2px 8px;
  font-size: 12px;
  font-weight: 600;
  color: #008b4b;
  background: #eaf7f0;
  border: 1px solid #ccebd9;
  border-radius: 4px;
}

.page-desc {
  margin: 4px 0 0;
  font-size: 12px;
  color: #728096;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.green-button {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #fff !important;
}

.green-button:hover,
.green-button:focus {
  background: #00743f !important;
  border-color: #00743f !important;
  color: #fff !important;
}

.red-outline {
  color: #e44d4d !important;
  border-color: #efb2b2 !important;
  background: #fff !important;
}

.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  margin-bottom: 12px;
  font-size: 12px;
  line-height: 1.5;
  color: #4a5a70;
  background: #f0f9f4;
  border: 1px solid #ccebd9;
  border-radius: 6px;
}

.erp-page-tip i {
  font-size: 14px;
  color: #008b4b;
}

.top-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.55fr) minmax(340px, 0.85fr);
  gap: 12px;
}

.panel {
  border: 1px solid #e4e7ed;
  border-radius: 6px;
  background: #fff;
  box-sizing: border-box;
}

.panel-title {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.panel-title.compact {
  margin-bottom: 8px;
}

.panel-title h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #18243a;
}

.panel-title span {
  font-size: 12px;
  color: #8490a2;
}

.currency-pill {
  padding: 1px 7px;
  font-size: 11px !important;
  font-weight: 600;
  color: #5b687c !important;
  background: #f1f5f9;
  border-radius: 4px;
}

.invoice-info {
  padding: 16px 18px;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 24px;
}

.kv-item {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 6px 10px;
  background: #f8fafc;
  border: 1px solid #eef2f6;
  border-radius: 4px;
}

.kv-item.full {
  grid-column: 1 / -1;
}

.kv-label {
  flex: 0 0 84px;
  font-size: 12px;
  color: #728096;
}

.kv-val {
  flex: 1;
  min-width: 0;
  font-size: 13px;
  color: #1e2b3c;
  word-break: break-all;
}

.mono {
  font-family: Consolas, Monaco, monospace;
}

.mono-strong {
  font-weight: 600;
  color: #17233b;
}

.green-text,
.green {
  color: #008b4b !important;
}

.red {
  color: #e44d4d !important;
}

.muted {
  color: #98a2b3;
}

.side-panels {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.compact-panel {
  flex: 1;
  padding: 14px 16px;
}

.amount-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 5px 0;
  font-size: 13px;
  color: #64748b;
}

.amount-row b {
  font-size: 14px;
  color: #17233b;
  font-variant-numeric: tabular-nums;
}

.amount-row.total {
  margin-top: 6px;
  padding-top: 8px;
  border-top: 1px dashed #e2e8f0;
}

.amount-row.total .total-num {
  font-size: 18px;
  color: #008b4b;
}

.match-table-panel {
  margin-top: 12px;
  padding: 14px 16px;
}

.panel-head-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.panel-head-bar .panel-title {
  margin-bottom: 0;
}

.match-summary-pills {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.summary-chip {
  padding: 4px 10px;
  font-size: 12px;
  color: #4a5a70;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
}

.summary-chip.active {
  color: #008b4b;
  background: #f0f9f4;
  border-color: #ccebd9;
}

.detail-table {
  width: 100%;
}

.dual-doc {
  display: flex;
  flex-direction: column;
  line-height: 1.35;
}

.dual-doc small {
  font-size: 11px;
  color: #8490a2;
}

.danger-link {
  padding: 0;
  color: #e44d4d !important;
}

.action-link {
  padding: 0;
  color: #008b4b !important;
  font-weight: 500;
}

.pager-bar {
  display: flex;
  justify-content: flex-end;
  padding-top: 12px;
}

.bottom-grid {
  display: grid;
  grid-template-columns: minmax(300px, 1fr) minmax(0, 2fr);
  gap: 12px;
  margin-top: 12px;
}

.attachments-panel,
.log-panel {
  padding: 14px 16px;
}

.attachment-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.attachment-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 10px;
  background: #f8fafc;
  border: 1px solid #e9eef5;
  border-radius: 6px;
}

.attachment-thumb {
  width: 88px;
  height: 88px;
  flex: 0 0 88px;
  border: 1px solid #dae3ec;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  background: #fff;
  cursor: pointer;
}

.attachment-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.attachment-thumb i {
  font-size: 28px;
  color: #008b4b;
}

.attachment-meta {
  min-width: 0;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.meta-line {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
}

.meta-line span {
  flex: 0 0 54px;
  color: #7c8799;
}

.meta-line b {
  min-width: 0;
  flex: 1;
  color: #1d2a3f;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.action-badge {
  display: inline-block;
  padding: 1px 7px;
  font-size: 12px;
  color: #008b4b;
  background: #eaf7f0;
  border-radius: 4px;
}

.dialog-tip {
  margin: 0 0 14px;
  padding: 10px 12px;
  font-size: 12px;
  line-height: 1.5;
  color: #8a5a00;
  background: #fff8e7;
  border: 1px solid #f7d88c;
  border-radius: 4px;
}

@media (max-width: 1120px) {
  .top-grid,
  .bottom-grid {
    grid-template-columns: 1fr;
  }
  .side-panels {
    flex-direction: row;
  }
}

@media (max-width: 760px) {
  .page-head {
    flex-direction: column;
    align-items: flex-start;
  }
  .side-panels {
    flex-direction: column;
  }
  .info-grid {
    grid-template-columns: 1fr;
  }
}
</style>
