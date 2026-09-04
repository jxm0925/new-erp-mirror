<template>
  <main v-loading="loading" class="red-invoice-page">
    <!-- 顶部操作栏 -->
    <header class="page-heading">
      <h1>开具红字发票</h1>
      <div class="heading-actions">
        <el-button class="btn-default" @click="back">返回发票详情</el-button>
        <el-button class="btn-default" :loading="draftSaving" @click="saveDraft">保存草稿</el-button>
        <el-button type="danger" class="btn-red" :loading="submitting" @click="confirmRed">确认红冲</el-button>
      </div>
    </header>

    <!-- 顶部提示条 -->
    <div class="business-alert">
      <svg class="alert-info-icon" viewBox="0 0 20 20" width="16" height="16" fill="currentColor">
        <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .247.25v3.25a.75.75 0 0 0 1.5 0v-3.5A1.75 1.75 0 0 0 9.25 9H9Z" clip-rule="evenodd" />
      </svg>
      <span>红字发票将生成一条新的会计事实，不会修改原蓝字发票。</span>
    </div>

    <!-- 上方栅格：原蓝票信息与红冲信息 -->
    <section class="top-grid">
      <!-- 左侧：原蓝票信息 -->
      <article class="panel original-panel">
        <h2>原蓝票信息</h2>
        <dl class="original-grid">
          <div class="info-row"><dt>发票记录号</dt><dd>{{ original.document_no || '—' }}</dd></div>
          <div class="info-row"><dt>发票号码</dt><dd>{{ original.invoice_no || '—' }}</dd></div>
          <div class="info-row"><dt>发票代码</dt><dd>{{ original.invoice_code || '—' }}</dd></div>
          <div class="info-row"><dt>发票类型</dt><dd>{{ invoiceType(original.invoice_type) }}</dd></div>
          <div class="info-row"><dt>供应商</dt><dd>{{ original.party_name_snapshot || '—' }}</dd></div>
          <div class="info-row"><dt>开票日期</dt><dd>{{ original.invoice_date || '—' }}</dd></div>
          <div class="info-row"><dt>币种</dt><dd>CNY 人民币</dd></div>
          <div class="info-row"><dt>原价税合计</dt><dd>{{ money(original.amount_incl_tax) }} CNY</dd></div>
          <div class="info-row"><dt>原未税金额</dt><dd>{{ money(original.amount_excl_tax) }} CNY</dd></div>
          <div class="info-row"><dt>原税额</dt><dd>{{ money(original.tax_amount) }} CNY</dd></div>
          <div class="info-row"><dt>已匹配金额（不含税）</dt><dd>{{ money(original.matched_amount) }} CNY</dd></div>
          <div class="info-row red-highlight"><dt>可红冲剩余金额（不含税）</dt><dd>{{ money(preview.red_remaining_excl_tax) }} CNY</dd></div>
        </dl>
      </article>

      <!-- 右侧：红冲信息 -->
      <article class="panel red-info">
        <h2>红冲信息</h2>
        <el-form label-position="left" label-width="110px" size="small" class="red-form">
          <el-form-item label="红冲方式">
            <el-radio-group v-model="form.red_scope" :disabled="!!draftId" class="red-radio-group" @change="changeScope">
              <el-radio label="full">全额红冲</el-radio>
              <el-radio label="partial">部分红冲</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="选择原蓝票" required>
            <div class="invoice-source">
              <el-input :value="blueLabel" disabled class="disabled-input" />
              <el-button class="btn-default" @click="selectOriginal">选择蓝票</el-button>
            </div>
          </el-form-item>

          <el-form-item label="红字发票日期" required>
            <el-date-picker
              v-model="form.red_date"
              :disabled="!!draftId"
              type="date"
              value-format="yyyy-MM-dd"
              placeholder="选择日期"
              style="width: 100%;"
            />
          </el-form-item>

          <el-form-item label="红冲原因" required>
            <el-select v-model="form.red_reason" :disabled="!!draftId" placeholder="请选择红冲原因" style="width: 100%;">
              <el-option label="开票有误" value="开票有误" />
              <el-option label="采购退货" value="采购退货" />
              <el-option label="折让或金额调整" value="折让或金额调整" />
              <el-option label="其他" value="其他" />
            </el-select>
          </el-form-item>

          <el-form-item label="备注" class="remark-item">
            <el-input
              v-model.trim="form.remark"
              :disabled="!!draftId"
              type="textarea"
              :rows="3"
              maxlength="200"
              show-word-limit
              placeholder="请输入备注（选填）"
            />
          </el-form-item>
        </el-form>
      </article>
    </section>

    <!-- 中部：红冲金额与税额 -->
    <section class="panel amount-panel">
      <div class="section-title">
        <h2>红冲金额与税额</h2>
        <svg class="title-info-icon" viewBox="0 0 20 20" width="15" height="15" fill="currentColor">
          <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .247.25v3.25a.75.75 0 0 0 1.5 0v-3.5A1.75 1.75 0 0 0 9.25 9H9Z" clip-rule="evenodd" />
        </svg>
      </div>

      <div class="tax-wrap">
        <table class="red-amount-table">
          <thead>
            <tr>
              <th style="width: 80px;">税率</th>
              <th style="width: 130px;">原未税金额（CNY）</th>
              <th style="width: 130px;">原税额（CNY）</th>
              <th style="width: 140px;">已红冲未税金额（CNY）</th>
              <th style="width: 170px;">本次红冲未税金额（CNY）<span class="required-star">*</span></th>
              <th style="width: 150px;">本次红冲税额（CNY）<span class="required-star">*</span></th>
              <th style="width: 150px;">本次红冲价税合计（CNY）</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td class="col-center">{{ taxRate }}%</td>
              <td class="col-center">{{ money(original.amount_excl_tax) }}</td>
              <td class="col-center">{{ money(original.tax_amount) }}</td>
              <td class="col-center">{{ money(alreadyRedExcl) }}</td>
              <td class="col-center">
                <el-input
                  v-model.trim="form.amount_excl_tax"
                  :disabled="form.red_scope === 'full' || !!draftId"
                  size="small"
                  class="table-cell-input"
                  @input="syncGross"
                />
              </td>
              <td class="col-center">
                <el-input
                  v-model.trim="form.tax_amount"
                  :disabled="form.red_scope === 'full' || !!draftId"
                  size="small"
                  class="table-cell-input"
                  @input="syncGross"
                />
              </td>
              <td class="col-center cell-bold">{{ money(form.amount_incl_tax) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <th class="col-center">合计</th>
              <th class="col-center">{{ money(original.amount_excl_tax) }}</th>
              <th class="col-center">{{ money(original.tax_amount) }}</th>
              <th class="col-center">{{ money(alreadyRedExcl) }}</th>
              <th class="col-center cell-bold">{{ money(form.amount_excl_tax) }}</th>
              <th class="col-center cell-bold">{{ money(form.tax_amount) }}</th>
              <th class="col-center cell-bold">{{ money(form.amount_incl_tax) }}</th>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- 提示与校验并列框 -->
      <div class="amount-bottom">
        <div class="warning-box">
          <svg class="warning-icon" viewBox="0 0 20 20" width="16" height="16" fill="currentColor">
            <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
          </svg>
          <span>红字发票会生成新的会计凭证并冲减原蓝票的影响，原蓝票及其已生成的凭证将保持不变，系统将自动生成红冲凭证。</span>
        </div>

        <div class="check-box">
          <h3>保存校验</h3>
          <p :class="amountValid ? 'pass' : 'fail'">
            <svg class="chk-svg" viewBox="0 0 20 20" width="14" height="14" fill="currentColor">
              <path v-if="amountValid" fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
              <path v-else fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.28 7.22a.75.75 0 0 0-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 1 0 1.06 1.06L10 11.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L11.06 10l1.72-1.72a.75.75 0 0 0-1.06-1.06L10 8.94 8.28 7.22Z" clip-rule="evenodd" />
            </svg>
            <span>红冲金额未超过可红冲余额：{{ money(form.amount_incl_tax) }} ≤ {{ money(preview.red_remaining_amount) }}</span>
          </p>
          <p :class="taxValid ? 'pass' : 'fail'">
            <svg class="chk-svg" viewBox="0 0 20 20" width="14" height="14" fill="currentColor">
              <path v-if="taxValid" fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
              <path v-else fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.28 7.22a.75.75 0 0 0-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 1 0 1.06 1.06L10 11.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L11.06 10l1.72-1.72a.75.75 0 0 0-1.06-1.06L10 8.94 8.28 7.22Z" clip-rule="evenodd" />
            </svg>
            <span>税额按税率自动计算：{{ money(form.amount_excl_tax) }} × {{ taxRate }}% = {{ money(form.tax_amount) }}</span>
          </p>
          <p :class="scopeValid ? 'pass' : 'fail'">
            <svg class="chk-svg" viewBox="0 0 20 20" width="14" height="14" fill="currentColor">
              <path v-if="scopeValid" fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
              <path v-else fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.28 7.22a.75.75 0 0 0-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 1 0 1.06 1.06L10 11.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L11.06 10l1.72-1.72a.75.75 0 0 0-1.06-1.06L10 8.94 8.28 7.22Z" clip-rule="evenodd" />
            </svg>
            <span>价税合计校验通过：{{ money(form.amount_incl_tax) }} = {{ money(form.amount_excl_tax) }} + {{ money(form.tax_amount) }}</span>
          </p>
        </div>
      </div>
    </section>

    <!-- 下方栅格：关联匹配处理与操作日志 -->
    <section class="bottom-grid">
      <!-- 左侧：原蓝票关联匹配处理 -->
      <article class="panel handling-panel">
        <h2>原蓝票关联匹配处理</h2>
        <div class="handling-options">
          <div class="handling-option active">
            <span class="custom-radio-icon checked"></span>
            <div class="option-content">
              <b>保持已匹配不变</b>
              <small>红冲仅生成凭证消除冲减，不对原匹配事实做任何处理。</small>
              <span class="scene-tag">适用场景：税务调整、开票信息修改等。</span>
            </div>
          </div>

          <div class="handling-option disabled" @click="unavailable">
            <span class="custom-radio-icon"></span>
            <div class="option-content">
              <b>冲销未使用的匹配</b>
              <small>将原蓝票中未生成付款数/核销的匹配记录一并冲销。</small>
              <span class="scene-tag">适用场景：发票作废且未发生付款。</span>
            </div>
          </div>

          <div class="handling-option disabled" @click="unavailable">
            <span class="custom-radio-icon"></span>
            <div class="option-content">
              <b>生成供应商退款应收</b>
              <small>将红冲金额生成结转商品退款应收单，后续现场收。</small>
              <span class="scene-tag">适用场景：需要向供应商追索退款。</span>
            </div>
          </div>
        </div>

        <h3>当前状态概览</h3>
        <div class="state-table">
          <div class="state-col">
            <span>已匹配金额（不含税）</span>
            <b>{{ money(original.matched_amount) }} CNY</b>
          </div>
          <div class="state-col">
            <span>未匹配金额（不含税）</span>
            <b>{{ money(unmatchedExcl) }} CNY</b>
          </div>
          <div class="state-col">
            <span>本次红冲金额（不含税）</span>
            <b>{{ money(form.amount_excl_tax) }} CNY</b>
          </div>
          <div class="state-col">
            <span>红冲后未匹配余额（不含税）</span>
            <b>{{ money(redUnmatchedExcl) }} CNY</b>
          </div>
          <div class="state-col highlight-col">
            <span>推荐处理方式</span>
            <b>保持已匹配不变</b>
          </div>
        </div>
      </article>

      <!-- 右侧：操作日志（预览） -->
      <article class="panel log-panel">
        <h2>操作日志（预览）</h2>
        <el-table :data="logRows" size="small" border class="log-table">
          <el-table-column prop="time" label="操作时间" width="150" align="center" />
          <el-table-column prop="operator" label="操作人" width="80" align="center" />
          <el-table-column prop="action" label="操作内容" min-width="210" />
        </el-table>
      </article>
    </section>

    <!-- 底部操作按钮 -->
    <footer class="footer-actions">
      <el-button class="btn-default" @click="back">返回发票详情</el-button>
      <el-button class="btn-default" :loading="draftSaving" @click="saveDraft">保存草稿</el-button>
      <el-button type="danger" class="btn-red" :loading="submitting" @click="confirmRed">确认红冲</el-button>
    </footer>
  </main>
</template>

<script>
import { reserveForCreatePage, clearCreatePageReservation } from '../../../utils/documentNumberReservation'
import {
  getFinanceInvoice,
  getFinanceInvoiceRedPreview,
  createFinanceRedInvoice,
  createFinanceRedInvoiceDraft,
  confirmFinanceRedInvoiceDraft
} from '../../../api/erp/finance'

const today = () => new Date().toISOString().slice(0, 10)

export default {
  name: 'FinanceInvoiceRed',
  data: () => ({
    loading: false,
    submitting: false,
    draftSaving: false,
    reservation: null,
    draftId: null,
    original: {},
    preview: {
      red_remaining_amount: '0',
      red_remaining_excl_tax: '0',
      red_remaining_tax_amount: '0'
    },
    form: {
      red_scope: 'full',
      red_date: today(),
      red_reason: '开票有误',
      invoice_type: 'vat_special',
      amount_excl_tax: '0.0000',
      tax_amount: '0.0000',
      amount_incl_tax: '0.0000',
      remark: '供应商申报不需要此发票，予以红冲。'
    }
  }),
  computed: {
    blueLabel() {
      return [this.original.document_no, this.original.invoice_no || this.original.invoice_code].filter(Boolean).join(' / ')
    },
    taxRate() {
      const x = Number(this.original.amount_excl_tax || 0)
      return x ? (Number(this.original.tax_amount || 0) / x * 100).toFixed(2).replace(/\.00$/, '') : '13'
    },
    alreadyRedExcl() {
      return Math.max(0, Number(this.original.amount_excl_tax || 0) - Number(this.preview.red_remaining_excl_tax || 0))
    },
    amountValid() {
      const a = Number(this.form.amount_incl_tax || 0)
      return a > 0 && a <= Number(this.preview.red_remaining_amount || 0) + 0.0001
    },
    taxValid() {
      return Math.abs(Number(this.form.amount_incl_tax || 0) - Number(this.form.amount_excl_tax || 0) - Number(this.form.tax_amount || 0)) < 0.0001
    },
    scopeValid() {
      return this.form.red_scope === 'partial' || Math.abs(Number(this.form.amount_incl_tax || 0) - Number(this.preview.red_remaining_amount || 0)) < 0.0001
    },
    unmatchedExcl() {
      return Math.max(0, Number(this.original.amount_excl_tax || 0) - Number(this.original.matched_amount || 0))
    },
    redUnmatchedExcl() {
      return Math.max(0, this.unmatchedExcl - Number(this.form.amount_excl_tax || 0))
    },
    logRows() {
      const todayDate = today()
      return [
        { time: `${todayDate} 10:20:31`, operator: '张三', action: `创建红冲申请，原蓝票：${this.original.document_no || 'INV202508180001'}` },
        { time: `${todayDate} 10:20:31`, operator: '张三', action: `选择红冲方式：${this.form.red_scope === 'full' ? '全额红冲' : '部分红冲'}` },
        { time: `${todayDate} 10:21:08`, operator: '张三', action: `编辑红冲金额：未税 ${this.money(this.form.amount_excl_tax)}，税额 ${this.money(this.form.tax_amount)}` },
        { time: `${todayDate} 10:21:08`, operator: '张三', action: '保存草稿' }
      ]
    }
  },
  created() {
    this.init()
  },
  methods: {
    async init() {
      this.loading = true
      try {
        const originalId = this.$route.params.id
        const preview = await getFinanceInvoiceRedPreview(originalId)
        this.original = preview.data.data.invoice || {}
        this.preview = preview.data.data || {}
        this.form.invoice_type = this.original.invoice_type || 'vat_special'
        const draftId = Number(this.$route.query.draft_id || 0)
        if (draftId) {
          const draft = await getFinanceInvoice(draftId)
          const invoice = draft.data.data || {}
          if (Number(invoice.red_invoice_of_id) !== Number(originalId) || invoice.status !== 'draft') {
            throw new Error('草稿不存在或不属于当前蓝票')
          }
          this.draftId = draftId
          Object.assign(this.form, {
            red_scope: invoice.red_scope || 'full',
            red_date: invoice.invoice_date || today(),
            red_reason: invoice.red_reason || '开票有误',
            invoice_type: invoice.invoice_type || this.form.invoice_type,
            amount_excl_tax: String(invoice.amount_excl_tax || 0),
            tax_amount: String(invoice.tax_amount || 0),
            amount_incl_tax: String(invoice.amount_incl_tax || 0),
            remark: invoice.remark || ''
          })
        } else {
          this.reservation = await reserveForCreatePage('finance_invoice', '/finance/invoices/' + originalId + '/red')
          this.changeScope()
        }
      } catch (e) {
        this.$message.error(e.userMessage || e.message || '红冲页面加载失败')
        this.back()
      } finally {
        this.loading = false
      }
    },
    money(v) {
      return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    invoiceType(v) {
      return { vat_special: '增值税专用发票', vat_normal: '增值税普通发票', other: '其他' }[v] || v || '—'
    },
    changeScope() {
      if (this.form.red_scope === 'full') {
        this.form.amount_excl_tax = Number(this.preview.red_remaining_excl_tax || 0).toFixed(2)
        this.form.tax_amount = Number(this.preview.red_remaining_tax_amount || 0).toFixed(2)
        this.syncGross()
      }
    },
    syncGross() {
      this.form.amount_incl_tax = (Number(this.form.amount_excl_tax || 0) + Number(this.form.tax_amount || 0)).toFixed(2)
    },
    selectOriginal() {
      this.$message.info('红冲必须从已确认蓝票详情发起；当前已锁定原蓝票。')
    },
    unavailable() {
      this.$message.warning('该动作需由对应采购退货或原业务事实触发，红冲页不会制造退款或篡改原匹配。')
    },
    validForm() {
      if (!this.form.red_reason || !this.amountValid || !this.taxValid || !this.scopeValid) {
        this.$message.error('请完成必填信息与金额校验')
        return false
      }
      return true
    },
    payload() {
      return {
        ...this.form,
        tax_detail: [
          {
            tax_rate: Number(this.taxRate),
            amount_excl_tax: Number(this.form.amount_excl_tax || 0),
            tax_amount: Number(this.form.tax_amount || 0)
          }
        ],
        reservation_token: this.reservation && this.reservation.reservation_token,
        creation_session_id: this.reservation && this.reservation.creation_session_id
      }
    },
    async saveDraft() {
      if (!this.validForm()) return
      if (this.draftId) {
        this.$message.info('当前草稿已持久化；请确认红冲，或返回详情后重新发起。')
        return
      }
      try {
        this.draftSaving = true
        const response = await createFinanceRedInvoiceDraft(this.original.id, this.payload())
        this.draftId = response.data.data.id
        clearCreatePageReservation(this.reservation)
        this.reservation = null
        await this.$router.replace({ path: this.$route.path, query: { draft_id: this.draftId } })
        this.$message.success('红冲草稿已保存，原蓝票和已匹配事实未被修改')
      } catch (e) {
        this.$message.error(e.userMessage || '保存红冲草稿失败')
      } finally {
        this.draftSaving = false
      }
    },
    async confirmRed() {
      if (!this.validForm()) return
      try {
        await this.$confirm(
          '确认以 ' + this.money(this.form.amount_incl_tax) + ' CNY 开具红字发票？该动作会生成不可修改的红冲事实。',
          '确认红冲',
          { type: 'warning' }
        )
        this.submitting = true
        const response = this.draftId
          ? await confirmFinanceRedInvoiceDraft(this.draftId)
          : await createFinanceRedInvoice(this.original.id, this.payload())
        if (this.reservation) clearCreatePageReservation(this.reservation)
        this.$message.success('红字发票已确认并完成结算来源净额重算')
        this.$router.replace('/finance/invoices/' + response.data.data.id)
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || '确认红冲失败')
      } finally {
        this.submitting = false
      }
    },
    back() {
      this.$router.push('/finance/invoices/' + this.$route.params.id)
    }
  }
}
</script>

<style scoped>
/* 页面容器与全局字体优化 */
.red-invoice-page {
  min-height: calc(100vh - 58px);
  padding: 16px 20px 76px;
  background: #f5f7fa;
  color: #1f2937;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  box-sizing: border-box;
}

/* 顶部操作 */
.page-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}
.page-heading h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #111827;
  line-height: 28px;
}
.heading-actions {
  display: flex;
  gap: 10px;
}

/* 按钮通用 */
.btn-default {
  height: 32px !important;
  line-height: 30px !important;
  padding: 0 16px !important;
  background: #ffffff !important;
  border: 1px solid #d9d9d9 !important;
  border-radius: 4px !important;
  color: #1f2937 !important;
  font-size: 13px !important;
  font-weight: 400 !important;
  transition: all 0.2s ease;
}
.btn-default:hover {
  color: #008b4b !important;
  border-color: #008b4b !important;
}
.btn-red {
  height: 32px !important;
  line-height: 30px !important;
  padding: 0 16px !important;
  background: #ff1f1f !important;
  border-color: #ff1f1f !important;
  border-radius: 4px !important;
  color: #ffffff !important;
  font-size: 13px !important;
  font-weight: 500 !important;
  transition: all 0.2s ease;
}
.btn-red:hover {
  background: #e61010 !important;
  border-color: #e61010 !important;
}

/* 顶部业务提示条 */
.business-alert {
  background: #e6f4ff;
  border: 1px solid #91caff;
  border-radius: 4px;
  padding: 8px 14px;
  margin-bottom: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
  color: #374151;
  font-size: 13px;
  line-height: 20px;
}
.alert-info-icon {
  width: 16px;
  height: 16px;
  color: #1677ff;
  flex-shrink: 0;
}

/* 上方左右分栏 */
.top-grid {
  display: grid;
  grid-template-columns: 1fr 1.08fr;
  gap: 14px;
}
.panel {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 4px;
  padding: 16px 20px;
  box-sizing: border-box;
}
.panel h2 {
  margin: 0 0 14px 0;
  font-size: 15px;
  font-weight: 700;
  color: #111827;
  line-height: 20px;
}

/* 原蓝票信息 */
.original-panel {
  padding: 16px 20px;
}
.original-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px 32px;
  margin: 0;
  padding: 0;
}
.info-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13px;
  line-height: 22px;
}
.info-row dt {
  color: #6b7280;
  font-weight: 400;
  white-space: nowrap;
}
.info-row dd {
  margin: 0;
  color: #1f2937;
  font-weight: 400;
  text-align: right;
  word-break: break-all;
}
.info-row.red-highlight dt {
  color: #ff1f1f;
  font-weight: 600;
}
.info-row.red-highlight dd {
  color: #ff1f1f;
  font-weight: 700;
}

/* 红冲信息表单 */
.red-form ::v-deep .el-form-item {
  margin-bottom: 12px;
}
.red-form ::v-deep .el-form-item__label {
  color: #374151;
  font-size: 13px;
  font-weight: 400;
  line-height: 32px;
  padding-right: 10px;
}
.red-form ::v-deep .el-form-item__label:before {
  color: #ff4d4f;
  margin-right: 3px;
}
.red-form ::v-deep .el-input__inner {
  height: 32px;
  line-height: 32px;
  border: 1px solid #d9d9d9;
  border-radius: 4px;
  font-size: 13px;
  color: #1f2937;
  padding: 0 10px;
}
.red-form ::v-deep .el-input__inner:focus {
  border-color: #008b4b;
}

/* 红色单选钮 */
.red-radio-group ::v-deep .el-radio {
  margin-right: 24px;
}
.red-radio-group ::v-deep .el-radio__input.is-checked .el-radio__inner {
  border-color: #ff1f1f;
  background: #ff1f1f;
}
.red-radio-group ::v-deep .el-radio__input.is-checked + .el-radio__label {
  color: #ff1f1f;
  font-weight: 500;
}

/* 禁用输入框 */
.red-form ::v-deep .disabled-input .el-input__inner,
.red-form ::v-deep .el-input.is-disabled .el-input__inner {
  background-color: #f9fafb !important;
  color: #6b7280 !important;
  border-color: #e5e7eb !important;
}

/* 日历图标靠右 */
.red-form ::v-deep .el-date-editor--date {
  width: 100%;
}
.red-form ::v-deep .el-date-editor--date .el-input__prefix {
  left: auto;
  right: 10px;
  color: #9ca3af;
}
.red-form ::v-deep .el-date-editor--date .el-input__inner {
  padding-left: 10px;
  padding-right: 30px;
}

.invoice-source {
  display: flex;
  gap: 8px;
}
.invoice-source .el-input {
  flex: 1;
}

.remark-item {
  margin-top: 10px !important;
}
.remark-item ::v-deep .el-textarea__inner {
  font-family: inherit;
  border-radius: 4px;
  border: 1px solid #d9d9d9;
  padding: 8px 10px;
  font-size: 13px;
  color: #1f2937;
  resize: vertical;
}
.remark-item ::v-deep .el-textarea__inner:focus {
  border-color: #008b4b;
}
.remark-item ::v-deep .el-input__count {
  bottom: 4px;
  right: 10px;
  font-size: 12px;
  color: #9ca3af;
  background: transparent;
}

/* 中部金额卡片 */
.amount-panel {
  margin-top: 14px;
  padding: 16px 20px;
}
.section-title {
  display: flex;
  align-items: center;
  margin-bottom: 12px;
}
.section-title h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #111827;
}
.title-info-icon {
  margin-left: 6px;
  color: #9ca3af;
}

/* 红冲金额表格 */
.tax-wrap {
  border: 1px solid #e5e7eb;
  border-radius: 4px;
  overflow-x: auto;
  background: #ffffff;
}
.red-amount-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.red-amount-table th {
  background: #f8fafc;
  font-weight: 600;
  color: #1f2937;
  height: 36px;
  padding: 0 8px;
  border-bottom: 1px solid #e5e7eb;
  border-right: 1px solid #e5e7eb;
  text-align: center;
}
.red-amount-table th:last-child {
  border-right: none;
}
.red-amount-table td {
  height: 40px;
  padding: 4px 8px;
  border-bottom: 1px solid #e5e7eb;
  border-right: 1px solid #e5e7eb;
}
.red-amount-table td:last-child {
  border-right: none;
}
.red-amount-table tfoot th {
  background: #fafbfc;
  font-weight: 700;
  height: 38px;
  color: #111827;
}
.col-center {
  text-align: center;
}
.cell-bold {
  font-weight: 700;
  color: #111827;
}
.required-star {
  color: #ff1f1f;
  margin-left: 2px;
}
.table-cell-input {
  width: 130px !important;
  display: inline-block;
}
.table-cell-input ::v-deep .el-input__inner {
  height: 28px;
  line-height: 28px;
  text-align: center;
  padding: 0 6px;
}

/* 提示与校验并列 */
.amount-bottom {
  display: grid;
  grid-template-columns: 1.15fr 1fr;
  gap: 14px;
  margin-top: 14px;
}
.warning-box {
  background: #fffbe6;
  border: 1px solid #ffe58f;
  border-radius: 4px;
  padding: 10px 14px;
  display: flex;
  align-items: flex-start;
  gap: 8px;
  color: #d97706;
  font-size: 12px;
  line-height: 18px;
}
.warning-icon {
  width: 16px;
  height: 16px;
  color: #f59e0b;
  flex-shrink: 0;
  margin-top: 1px;
}
.check-box {
  background: #f6ffed;
  border: 1px solid #b7eb8f;
  border-radius: 4px;
  padding: 8px 14px;
}
.check-box h3 {
  margin: 0 0 6px 0;
  font-size: 13px;
  font-weight: 700;
  color: #15803d;
}
.check-box p {
  margin: 0;
  padding: 3px 0;
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  line-height: 16px;
}
.check-box .pass {
  color: #166534;
}
.check-box .fail {
  color: #dc2626;
}
.chk-svg {
  flex-shrink: 0;
}

/* 下方栅格 */
.bottom-grid {
  display: grid;
  grid-template-columns: 1.35fr 0.95fr;
  gap: 14px;
  margin-top: 14px;
}

/* 关联匹配处理 */
.handling-options {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin-bottom: 12px;
}
.handling-option {
  border: 1px solid #e5e7eb;
  border-radius: 4px;
  background: #ffffff;
  padding: 10px 12px;
  display: flex;
  align-items: flex-start;
  gap: 8px;
  cursor: pointer;
  transition: all 0.2s ease;
}
.handling-option.active {
  border-color: #ff1f1f;
  box-shadow: 0 0 0 1px #ff1f1f;
}
.handling-option.disabled {
  background: #fafbfc;
  opacity: 0.85;
}
.custom-radio-icon {
  width: 14px;
  height: 14px;
  border: 1px solid #d1d5db;
  border-radius: 50%;
  display: inline-block;
  flex-shrink: 0;
  margin-top: 2px;
  background: #ffffff;
}
.custom-radio-icon.checked {
  border-color: #ff1f1f;
  background: #ff1f1f;
  box-shadow: inset 0 0 0 3px #ffffff;
}
.option-content {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.option-content b {
  font-size: 13px;
  color: #1f2937;
  line-height: 18px;
}
.option-content small {
  font-size: 11px;
  color: #6b7280;
  line-height: 15px;
}
.scene-tag {
  font-size: 11px;
  color: #9ca3af;
  line-height: 15px;
  margin-top: 2px;
}

.handling-panel h3 {
  font-size: 13px;
  font-weight: 700;
  color: #111827;
  margin: 12px 0 6px 0;
}
.state-table {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  border: 1px solid #e5e7eb;
  border-radius: 4px;
  background: #ffffff;
}
.state-col {
  padding: 8px 10px;
  border-right: 1px solid #e5e7eb;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.state-col:last-child {
  border-right: none;
}
.state-col span {
  font-size: 11px;
  color: #6b7280;
  line-height: 16px;
}
.state-col b {
  font-size: 12px;
  color: #1f2937;
  font-weight: 500;
  line-height: 18px;
}
.highlight-col b {
  font-weight: 700;
}

/* 操作日志表格 */
.log-table ::v-deep th {
  background: #f8fafc;
  color: #1f2937;
  font-size: 13px;
  font-weight: 600;
}
.log-table ::v-deep td {
  font-size: 12px;
  color: #374151;
}

/* 底部操作栏 */
.footer-actions {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 10px;
  margin-top: 20px;
  padding: 12px 0;
}

/* 响应式 */
@media (max-width: 1200px) {
  .top-grid,
  .amount-bottom,
  .bottom-grid {
    grid-template-columns: 1fr;
  }
  .handling-options {
    grid-template-columns: 1fr;
  }
}
</style>
