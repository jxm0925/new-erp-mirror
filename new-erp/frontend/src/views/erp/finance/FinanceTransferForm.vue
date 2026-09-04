<template>
  <main class="fx-page" v-loading="loading">
    <!-- Top Bar: Breadcrumb + Action Buttons -->
    <div class="fx-top-bar">
      <div class="fx-crumb">财务管理 <i>/</i> 资金转账与换汇 <i>/</i> <b>{{ docId ? (confirmed ? '详情' : '编辑') : '新建' }}</b></div>
      <div class="fx-actions">
        <el-button size="small" @click="$router.push('/finance/transfers')">返回列表</el-button>
        <el-button v-if="!confirmed" size="small" :loading="saving" @click="saveDraft">保存草稿</el-button>
        <el-button v-if="!confirmed" size="small" class="btn-primary-green" type="success" :loading="saving" @click="saveAndConfirm">确认并入账</el-button>
        <el-button v-if="status === 'confirmed'" size="small" type="danger" plain @click="voidDoc">作废 / 冲销</el-button>
      </div>
    </div>

    <!-- Mode Selector Cards -->
    <section class="fx-mode-row" aria-label="业务类型">
      <button class="mode-card" :class="{ active: mode === 'transfer' }" :disabled="confirmed" type="button" @click="mode='transfer'; onMode()">
        <div class="mode-icon-box transfer-icon">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="23 4 23 10 17 10"></polyline>
            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
          </svg>
        </div>
        <div class="mode-info">
          <b>同币种转账</b>
          <small>相同币种的跨账户资金转移</small>
        </div>
        <i v-if="mode === 'transfer'" class="el-icon-circle-check mode-check-badge" />
      </button>

      <button class="mode-card" :class="{ active: mode === 'exchange' }" :disabled="confirmed" type="button" @click="mode='exchange'; onMode()">
        <div class="mode-icon-box exchange-icon">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="2" y1="12" x2="22" y2="12"></line>
            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
          </svg>
        </div>
        <div class="mode-info">
          <b>跨币种换汇</b>
          <small>不同币种间的兑换并转入目标账户</small>
        </div>
        <i v-if="mode === 'exchange'" class="el-icon-circle-check mode-check-badge" />
      </button>
    </section>

    <!-- Main Workspace Grid -->
    <div class="fx-main-grid">
      <!-- Left 3 Columns + Lower Row Grid -->
      <div class="fx-left-column">
        <!-- 3 Process Cards: Source Account -> Deal Details -> Target Account -->
        <div class="fx-process-row">
          <!-- 1. 转出账户 -->
          <div class="fx-card account-card source-card">
            <div class="card-header">
              <span class="card-title">转出账户</span>
              <span class="card-badge badge-blue">转出</span>
            </div>

            <!-- Account Selector (editable state) -->
            <div v-if="!confirmed" class="account-selector-wrap">
              <el-select v-model="form.source_account_id" filterable size="small" placeholder="选择转出账户" @change="accountChanged">
                <el-option v-for="a in accounts" :key="a.id" :label="`${a.account_name} · ${a.currency}`" :value="a.id" :disabled="a.status !== 'enabled'" />
              </el-select>
            </div>

            <!-- Account Profile -->
            <div v-if="source.id" class="bank-profile">
              <div class="bank-logo-wrap">
                <!-- Red Bank Emblem / HSBC / General -->
                <svg v-if="source.bank_name && source.bank_name.includes('汇丰')" viewBox="0 0 32 32" width="32" height="32">
                  <polygon points="16,2 30,16 16,30 2,16" fill="#db0011" />
                  <polygon points="16,6 26,16 16,26 6,16" fill="#ffffff" />
                  <polygon points="16,10 22,16 16,22 10,16" fill="#db0011" />
                </svg>
                <div v-else class="bank-avatar-emblem red-emblem">
                  <i class="el-icon-office-building" />
                </div>
              </div>
              <div class="bank-text-details">
                <strong class="bank-name">{{ source.account_name || source.bank_name || '转出银行账户' }}</strong>
                <p class="bank-branch">{{ source.bank_name || '资金账户' }} {{ sourceCurrency }}</p>
                <p class="bank-no">{{ source.bank_account_no || source.account_no || '—' }}</p>
              </div>
            </div>
            <div v-else class="empty-account-placeholder">
              <i class="el-icon-wallet" />
              <span>请选择转出资金账户</span>
            </div>

            <!-- Key-Value Details List -->
            <div class="kv-list">
              <div class="kv-row">
                <span class="kv-label">账户币种</span>
                <span class="kv-val">{{ sourceCurrency || '—' }}</span>
              </div>
              <div class="kv-row">
                <span class="kv-label">可用余额</span>
                <span class="kv-val font-semibold">{{ fxPreview.source_original_balance !== undefined ? `${money(fxPreview.source_original_balance)} ${sourceCurrency}` : '—' }}</span>
              </div>
              <div class="kv-row">
                <span class="kv-label">历史账面CNY</span>
                <span class="kv-val text-blue font-bold">{{ fxPreview.source_carrying_base_amount !== undefined ? `${money(fxPreview.source_carrying_base_amount)} CNY` : '—' }}</span>
              </div>
            </div>

            <div class="card-footer-note">
              <span>截至 {{ form.business_date || '2024-05-22' }} 09:00:00</span>
            </div>
          </div>

          <!-- Arrow 1 -->
          <div class="process-arrow">
            <i class="el-icon-right" />
          </div>

          <!-- 2. 实际成交 -->
          <div class="fx-card deal-card">
            <div class="card-header">
              <span class="card-title">实际成交</span>
              <span v-if="confirmed" class="card-badge badge-green">已成交</span>
              <span v-else class="card-badge badge-gray">草稿</span>
            </div>

            <div class="deal-content">
              <div class="deal-item">
                <span class="deal-label">业务日期</span>
                <div class="deal-field">
                  <el-date-picker v-if="!confirmed" v-model="form.business_date" type="date" size="small" value-format="yyyy-MM-dd" placeholder="选择日期" @change="schedulePreview" />
                  <span v-else class="deal-val">{{ form.business_date || '—' }}</span>
                </div>
              </div>

              <div class="deal-item">
                <span class="deal-label">实际支出 (原币)</span>
                <div class="deal-field">
                  <el-input v-if="!confirmed" v-model.trim="form.source_amount" size="small" placeholder="0.00" @input="recalculate">
                    <template slot="append">{{ sourceCurrency || '—' }}</template>
                  </el-input>
                  <span v-else class="deal-val font-semibold">{{ money(form.source_amount) }} {{ sourceCurrency }}</span>
                </div>
              </div>

              <div class="deal-item">
                <span class="deal-label">参考汇率</span>
                <div class="deal-field text-right">
                  <span class="deal-val font-medium">{{ referenceRateText }}</span>
                  <small v-if="mode === 'exchange' && fxPreview.reference_exchange_rate" class="deal-sub-hint">(中间价 {{ rate(fxPreview.reference_exchange_rate) }})</small>
                </div>
              </div>

              <div class="deal-item">
                <span class="deal-label">预计可得</span>
                <div class="deal-field text-right">
                  <span class="deal-val font-semibold">{{ referenceExpected }} {{ targetCurrency || '' }}</span>
                </div>
              </div>

              <div class="deal-item highlight-gross">
                <span class="deal-label">实际到账 (毛额)</span>
                <div class="deal-field">
                  <el-input v-if="!confirmed" v-model.trim="form.target_amount" size="small" class="green-input" placeholder="0.00" @input="recalculate">
                    <template slot="append">{{ targetCurrency || '—' }}</template>
                  </el-input>
                  <span v-else class="deal-val text-green font-bold">{{ money(form.target_amount) }} {{ targetCurrency }}</span>
                </div>
              </div>

              <div class="deal-item">
                <span class="deal-label">实际成交汇率 <small class="text-gray">(自动计算)</small></span>
                <div class="deal-field text-right">
                  <span class="deal-val text-green font-bold">{{ actualRateText }}</span>
                </div>
              </div>

              <div v-if="mode === 'exchange'" class="deal-item align-top">
                <span class="deal-label">相对参考价差</span>
                <div class="deal-field text-right">
                  <span class="deal-val font-bold" :class="Number(fxPreview.reference_difference_amount || 0) < 0 ? 'text-loss' : 'text-gain'">
                    {{ fxPreview.reference_difference_amount === undefined ? '填写金额后计算' : `${signed(fxPreview.reference_difference_amount)} ${targetCurrency} ${Number(fxPreview.reference_difference_amount || 0) < 0 ? '↓' : '↑'}` }}
                  </span>
                  <small v-if="Number(fxPreview.reference_difference_amount || 0) < 0 || (!fxPreview.reference_difference_amount && form.target_amount)" class="deal-sub-hint text-loss">
                    (不利于企业)
                  </small>
                  <small v-else-if="Number(fxPreview.reference_difference_amount || 0) > 0" class="deal-sub-hint text-gain">
                    (优于参考价)
                  </small>
                </div>
              </div>
            </div>
          </div>

          <!-- Arrow 2 -->
          <div class="process-arrow">
            <i class="el-icon-right" />
          </div>

          <!-- 3. 转入账户 -->
          <div class="fx-card account-card target-card">
            <div class="card-header">
              <span class="card-title">转入账户</span>
              <span class="card-badge badge-blue">转入</span>
            </div>

            <!-- Account Selector (editable state) -->
            <div v-if="!confirmed" class="account-selector-wrap">
              <el-select v-model="form.target_account_id" filterable size="small" placeholder="选择转入账户" @change="accountChanged">
                <el-option v-for="a in accounts" :key="a.id" :label="`${a.account_name} · ${a.currency}`" :value="a.id" :disabled="a.status !== 'enabled'" />
              </el-select>
            </div>

            <!-- Account Profile -->
            <div v-if="target.id" class="bank-profile">
              <div class="bank-logo-wrap">
                <!-- Red Bank Emblem / ICBC / General -->
                <svg v-if="target.bank_name && (target.bank_name.includes('工商') || target.bank_name.includes('ICBC'))" viewBox="0 0 32 32" width="32" height="32">
                  <circle cx="16" cy="16" r="14" fill="#c7000b" />
                  <rect x="14.2" y="7" width="3.6" height="18" fill="#ffffff" />
                  <rect x="9" y="11" width="14" height="3.2" fill="#ffffff" />
                  <rect x="9" y="17.8" width="14" height="3.2" fill="#ffffff" />
                </svg>
                <div v-else class="bank-avatar-emblem red-emblem">
                  <i class="el-icon-bank-card" />
                </div>
              </div>
              <div class="bank-text-details">
                <strong class="bank-name">{{ target.account_name || target.bank_name || '中国工商银行股份有限公司' }}</strong>
                <p class="bank-branch">{{ target.bank_name || '工商银行' }} {{ targetCurrency }}</p>
                <p class="bank-no">{{ target.bank_account_no || target.account_no || '6217 8610 0000 1234 567' }}</p>
              </div>
            </div>
            <div v-else class="empty-account-placeholder">
              <i class="el-icon-bank-card" />
              <span>请选择转入资金账户</span>
            </div>

            <!-- Key-Value Details List -->
            <div class="kv-list">
              <div class="kv-row">
                <span class="kv-label">账户币种</span>
                <span class="kv-val">{{ targetCurrency || '—' }}</span>
              </div>
              <div class="kv-row">
                <span class="kv-label">可用余额</span>
                <span class="kv-val font-semibold">{{ fxPreview.target_original_balance !== undefined ? `${money(fxPreview.target_original_balance)} ${targetCurrency}` : '—' }}</span>
              </div>
              <div class="kv-row">
                <span class="kv-label">本次到账</span>
                <span class="kv-val text-green font-bold">{{ netArrival !== '—' ? `${netArrival} ${targetCurrency || ''}` : (form.target_amount ? `${money(Number(form.target_amount) - Number(form.fee_amount || 0))} ${targetCurrency || ''}` : '—') }}</span>
              </div>
              <div class="kv-row">
                <span class="kv-label">入账后余额</span>
                <span class="kv-val text-green font-bold">{{ confirmed ? '已按台账入账' : '确认后由台账计算' }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Lower 3 Cards: 费用信息 + 附件 + 备注 -->
        <div class="fx-lower-row">
          <!-- 费用信息 (独立扣收) -->
          <div class="fx-card fee-card">
            <div class="card-header simple">
              <span class="card-title">费用信息 <small class="text-muted">（独立扣收）</small></span>
            </div>
            <div class="fee-content">
              <div class="fee-line">
                <span class="fee-label">平台手续费</span>
                <div class="fee-field">
                  <el-input v-if="!confirmed" v-model.trim="form.fee_amount" size="small" placeholder="0.00" @input="recalculate">
                    <template slot="append">{{ feeCurrency || '—' }}</template>
                  </el-input>
                  <span v-else class="fee-val">{{ money(form.fee_amount) }} {{ feeCurrency }}</span>
                </div>
              </div>
              <div class="fee-line">
                <span class="fee-label">费用承担方</span>
                <div class="fee-field">
                  <el-select v-if="!confirmed" v-model="form.fee_bearer" size="small" @change="recalculate">
                    <el-option label="转入方扣除" value="target" />
                    <el-option label="转出方承担" value="source" />
                  </el-select>
                  <span v-else class="fee-val">{{ form.fee_bearer === 'target' ? '转入方扣除' : '转出方承担' }}</span>
                </div>
              </div>
              <div class="fee-line highlight-net">
                <span class="fee-label">实收净额</span>
                <span class="fee-val text-green font-bold">{{ netArrival !== '—' ? `${netArrival} ${targetCurrency || ''}` : (form.target_amount ? `${money(Number(form.target_amount) - Number(form.fee_amount || 0))} ${targetCurrency || ''}` : '—') }}</span>
              </div>
              <p class="fee-footnote">• 平台手续费不参与汇兑损益的计算</p>
            </div>
          </div>

          <!-- 附件 -->
          <div class="fx-card attachment-card">
            <div class="card-header simple">
              <span class="card-title">附件</span>
            </div>
            <div class="attachment-content">
              <el-upload v-if="!confirmed" action="#" :http-request="upload" :show-file-list="false" class="fx-upload-dropzone">
                <div class="upload-dropzone-inner">
                  <i class="el-icon-paperclip upload-clip-icon" />
                  <span class="upload-primary-text">点击上传或拖拽文件到此处</span>
                  <small class="upload-hint-text">支持 JPG、PNG、PDF、单个文件不超过 10MB</small>
                </div>
              </el-upload>
              <div v-else-if="!(detail.attachments || []).length" class="empty-attach-hint">
                暂无附件
              </div>

              <!-- Uploaded Files List -->
              <div v-if="(detail.attachments || []).length" class="attach-list">
                <div v-for="a in detail.attachments || []" :key="a.id" class="attach-item">
                  <div class="attach-icon-badge">
                    <i class="el-icon-document" />
                  </div>
                  <div class="attach-meta">
                    <span class="attach-name" :title="a.original_name">{{ a.original_name }}</span>
                    <small class="attach-ext">PDF · 342 KB</small>
                  </div>
                  <div class="attach-ops">
                    <button type="button" class="icon-btn" title="查看预览" @click="previewAttachment(a)"><i class="el-icon-view" /></button>
                    <button v-if="!confirmed" type="button" class="icon-btn danger" title="删除" @click="removeAttachment(a)"><i class="el-icon-delete" /></button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 备注 -->
          <div class="fx-card remark-card">
            <div class="card-header simple">
              <span class="card-title">备注</span>
            </div>
            <div class="remark-content">
              <el-input
                v-model.trim="form.remark"
                type="textarea"
                :rows="4"
                maxlength="200"
                show-word-limit
                :disabled="confirmed"
                placeholder="请输入备注信息（可选）"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Right Sidebar: 换汇规则与提示 -->
      <aside class="fx-card rule-card">
        <h2 class="rule-title">换汇规则与提示</h2>
        <div class="rule-sections">
          <div class="rule-block">
            <div class="rule-block-head">
              <i class="el-icon-time rule-icon" />
              <strong>定价规则</strong>
            </div>
            <p class="rule-bullet">• 参考汇率：以银行成交汇率为准</p>
          </div>

          <div class="rule-block">
            <div class="rule-block-head">
              <i class="el-icon-document rule-icon" />
              <strong>费用说明</strong>
            </div>
            <p class="rule-bullet">• 平台手续费按交易金额单独收取</p>
          </div>

          <div class="rule-block">
            <div class="rule-block-head">
              <i class="el-icon-notebook-2 rule-icon" />
              <strong>入账规则</strong>
            </div>
            <p class="rule-bullet">• 以实际到账金额入账，精确到小数点后两位</p>
          </div>

          <div class="rule-block">
            <div class="rule-block-head">
              <i class="el-icon-warning-outline rule-icon" />
              <strong>风险提示</strong>
            </div>
            <p class="rule-bullet">• 汇率波动可能导致实际到账与历史账面产生差异</p>
          </div>
        </div>

        <div class="rule-footer-link">
          <a href="javascript:void(0)" @click="$router.push('/finance/exchange-rates')">
            <span>查看资金管理制度</span>
            <i class="el-icon-top-right" />
          </a>
        </div>
      </aside>
    </div>

    <!-- Bottom Summary Banner (4-column strip) -->
    <section class="summary-banner">
      <div class="summary-col">
        <span class="summary-label">
          历史账面价值 (转出 {{ form.source_amount || '—' }} {{ sourceCurrency || '—' }})
        </span>
        <div class="summary-value-wrap">
          <strong class="summary-num">{{ fxPreview.source_base_amount !== undefined ? `${money(fxPreview.source_base_amount)} CNY` : '—' }}</strong>
          <i class="el-icon-info summary-info-icon" title="历史账面价值" />
        </div>
      </div>

      <div class="summary-col">
        <span class="summary-label">实际结算毛额</span>
        <div class="summary-value-wrap">
          <strong class="summary-num text-green">{{ form.target_amount ? `${money(form.target_amount)} ${targetCurrency || 'CNY'}` : '—' }}</strong>
          <i class="el-icon-info summary-info-icon" title="实际结算毛额" />
        </div>
      </div>

      <div class="summary-col">
        <span class="summary-label">{{ confirmed ? '已实现' : '已实现' }}汇兑损益 (实际结算−历史成本)</span>
        <div class="summary-value-wrap">
          <strong class="summary-num" :class="Number((confirmed ? detail.realized_fx_gain_loss : fxPreview.realized_fx_gain_loss) || 0) < 0 ? 'text-loss' : 'text-gain'">
            {{ confirmed ? signed(detail.realized_fx_gain_loss) + ' CNY' : (fxPreview.realized_fx_gain_loss !== undefined ? signed(fxPreview.realized_fx_gain_loss) + ' CNY' : '—') }}
          </strong>
          <i class="el-icon-info summary-info-icon" title="汇兑损益" />
        </div>
        <small v-if="confirmed || fxPreview.realized_fx_gain_loss !== undefined" class="summary-subtext" :class="Number((confirmed ? detail.realized_fx_gain_loss : fxPreview.realized_fx_gain_loss) || 0) < 0 ? 'text-loss' : 'text-gain'">
          {{ Number((confirmed ? detail.realized_fx_gain_loss : fxPreview.realized_fx_gain_loss) || 0) < 0 ? '(损失)' : '(收益)' }}
        </small>
      </div>

      <div class="summary-col summary-fee-col">
        <span class="summary-label">平台手续费 {{ money(form.fee_amount || '0') }} {{ feeCurrency || '—' }}</span>
        <small class="summary-fee-desc">由{{ form.fee_bearer === 'target' ? '转入方' : '转出方' }}承担，单独记录</small>
        <small class="summary-fee-desc">不参与汇兑损益计算</small>
      </div>
    </section>

    <!-- Operation Logs (when detail exists) -->
    <section v-if="docId" class="fx-card log-card">
      <div class="card-header simple">
        <span class="card-title">操作日志</span>
      </div>
      <el-timeline>
        <el-timeline-item v-for="log in detail.logs || []" :key="log.id" :timestamp="formatDate(log.created_at)" type="success">
          {{ log.content }}
        </el-timeline-item>
        <el-timeline-item v-if="!(detail.logs || []).length" timestamp="—">
          保存草稿后将记录操作日志
        </el-timeline-item>
      </el-timeline>
    </section>

    <el-dialog :visible.sync="previewVisible" title="附件预览" width="80%">
      <iframe v-if="previewUrl" :src="previewUrl" class="preview" />
    </el-dialog>
  </main>
</template>

<script>
import {
  listFinanceAccounts,
  getFinanceTransfer,
  createFinanceTransfer,
  updateFinanceTransfer,
  previewFinanceTransfer,
  confirmFinanceTransfer,
  voidFinanceTransfer,
  uploadTransferAttachment,
  previewFinanceAttachment,
  deleteFinanceAttachment
} from '../../../api/erp/finance'

const blank = () => ({
  source_account_id: null,
  target_account_id: null,
  source_amount: '',
  target_amount: '',
  fee_amount: '0',
  fee_bearer: 'target',
  business_date: new Date().toISOString().slice(0, 10),
  remark: ''
})

export default {
  data: () => ({
    loading: false,
    saving: false,
    previewing: false,
    previewTimer: null,
    previewRequestId: 0,
    previewData: null,
    accounts: [],
    mode: 'exchange',
    form: blank(),
    detail: { attachments: [], logs: [] },
    status: 'draft',
    previewUrl: '',
    previewVisible: false,
    rules: {
      source_account_id: [{ required: true, message: '请选择转出账户', trigger: 'change' }],
      target_account_id: [{ required: true, message: '请选择转入账户', trigger: 'change' }],
      business_date: [{ required: true, message: '请选择业务日期', trigger: 'change' }]
    }
  }),
  computed: {
    docId() {
      return this.$route.params.id
    },
    confirmed() {
      return this.status === 'confirmed' || this.status === 'voided'
    },
    source() {
      return this.accounts.find(x => Number(x.id) === Number(this.form.source_account_id)) || (this.accounts[0] || {})
    },
    target() {
      return this.accounts.find(x => Number(x.id) === Number(this.form.target_account_id)) || (this.accounts[1] || {})
    },
    sourceCurrency() {
      return this.source.currency || ''
    },
    targetCurrency() {
      return this.target.currency || ''
    },
    feeCurrency() {
      return this.form.fee_bearer === 'target' ? this.targetCurrency : this.sourceCurrency
    },
    fxPreview() {
      return this.previewData || (this.confirmed ? {
        source_base_amount: this.detail.source_base_amount,
        reference_exchange_rate: this.detail.reference_exchange_rate,
        actual_exchange_rate: this.detail.actual_exchange_rate,
        reference_difference_amount: this.detail.reference_difference_amount,
        net_target_amount: this.detail.net_target_amount,
        realized_fx_gain_loss: this.detail.realized_fx_gain_loss
      } : {})
    },
    referenceRateText() {
      if (this.mode === 'transfer') return '同币种 1:1'
      if (this.fxPreview.reference_exchange_rate) {
        return `1 ${this.sourceCurrency} = ${Number(this.fxPreview.reference_exchange_rate).toFixed(2)} ${this.targetCurrency}`
      }
      return '—'
    },
    referenceExpected() {
      if (this.fxPreview.reference_expected_amount) return this.money(this.fxPreview.reference_expected_amount)
      if (this.confirmed && this.fxPreview.reference_exchange_rate) return this.money(Number(this.form.source_amount || 0) * Number(this.fxPreview.reference_exchange_rate))
      return '—'
    },
    actualRateText() {
      if (this.fxPreview.actual_exchange_rate) {
        return `1 ${this.sourceCurrency} = ${Number(this.fxPreview.actual_exchange_rate).toFixed(4)} ${this.targetCurrency}`
      }
      if (this.form.source_amount && this.form.target_amount) {
        return `1 ${this.sourceCurrency} = ${(Number(this.form.target_amount) / Number(this.form.source_amount)).toFixed(4)} ${this.targetCurrency}`
      }
      return '—'
    },
    netArrival() {
      if (this.fxPreview.net_target_amount !== undefined) return this.money(this.fxPreview.net_target_amount)
      if (this.form.target_amount) {
        const net = this.form.fee_bearer === 'target' ? Number(this.form.target_amount) - Number(this.form.fee_amount || 0) : Number(this.form.target_amount)
        return this.money(net)
      }
      return '—'
    }
  },
  watch: {
    '$route.fullPath'() {
      this.bootstrap()
    }
  },
  created() {
    this.bootstrap()
  },
  methods: {
    async bootstrap() {
      clearTimeout(this.previewTimer)
      this.previewRequestId += 1
      this.previewData = null
      this.loading = true
      try {
        const r = await listFinanceAccounts({ per_page: 100 })
        this.accounts = r.data.data || []
        if (this.docId) {
          await this.loadDetail()
        } else {
          this.status = 'draft'
          this.detail = { attachments: [], logs: [] }
          this.form = blank()
          this.mode = 'exchange'
          if (this.accounts.length >= 2) {
            this.form.source_account_id = this.accounts[0].id
            this.form.target_account_id = this.accounts[1].id
            this.accountChanged()
          }
        }
      } catch (e) {
        this.$message.error(e.userMessage || '资金账户加载失败')
      } finally {
        this.loading = false
      }
    },
    async loadDetail() {
      const r = await getFinanceTransfer(this.docId)
      this.detail = r.data.data || {}
      this.status = this.detail.status
      this.form = { ...blank(), ...this.detail }
      this.mode = this.detail.source_currency === this.detail.target_currency ? 'transfer' : 'exchange'
      if (!this.confirmed) this.schedulePreview()
    },
    accountChanged() {
      this.previewData = null
      if (!this.sourceCurrency || !this.targetCurrency) return
      this.mode = this.sourceCurrency === this.targetCurrency ? 'transfer' : 'exchange'
      if (this.mode === 'transfer') this.form.target_amount = this.form.source_amount
      this.recalculate()
    },
    onMode() {
      const expected = this.sourceCurrency === this.targetCurrency ? 'transfer' : 'exchange'
      if (this.sourceCurrency && this.targetCurrency && this.mode !== expected) {
        this.mode = expected
        this.$message.warning(expected === 'transfer' ? '相同币种账户只能使用同币种转账' : '不同币种账户只能使用跨币种换汇')
      }
      this.recalculate()
    },
    recalculate() {
      if (this.mode === 'transfer') {
        const out = Number(this.form.source_amount || 0)
        const fee = Number(this.form.fee_amount || 0)
        this.form.target_amount = (this.form.fee_bearer === 'source' ? Math.max(0, out - fee) : out).toFixed(4)
      }
      this.schedulePreview()
    },
    schedulePreview() {
      clearTimeout(this.previewTimer)
      this.previewRequestId += 1
      this.previewData = null
      if (!this.form.source_account_id || !this.form.target_account_id || !this.form.business_date || Number(this.form.source_amount) <= 0 || Number(this.form.target_amount) <= 0) return
      this.previewTimer = setTimeout(() => this.loadPreview(), 300)
    },
    async loadPreview() {
      const requestId = ++this.previewRequestId
      const payload = this.payload()
      this.previewing = true
      try {
        const r = await previewFinanceTransfer(payload)
        if (requestId === this.previewRequestId) this.previewData = r.data.data || null
      } catch (e) {
        if (requestId === this.previewRequestId) {
          this.previewData = null
          this.$message.error(e.userMessage || '换汇预览计算失败')
        }
      } finally {
        if (requestId === this.previewRequestId) this.previewing = false
      }
    },
    payload() {
      return { ...this.form, mode: this.mode, fee_currency: this.feeCurrency || undefined }
    },
    async saveDraft() {
      if (!this.form.source_account_id || !this.form.target_account_id || !this.form.business_date) return this.$message.error('请完整选择转出账户、转入账户和业务日期')
      if (!this.sourceCurrency || !this.targetCurrency) return this.$message.error('请先选择转出和转入账户')
      if (Number(this.form.source_account_id) === Number(this.form.target_account_id)) return this.$message.error('转出和转入账户不能相同')
      if (Number(this.form.source_amount) <= 0 || Number(this.form.target_amount) <= 0) return this.$message.error('实际支付和实际到账毛额必须大于 0')
      if (Number(this.form.fee_amount || 0) < 0) return this.$message.error('手续费不能小于 0')
      if ((this.mode === 'transfer') !== (this.sourceCurrency === this.targetCurrency)) {
        return this.$message.error(this.mode === 'transfer' ? '不同币种账户必须选择跨币种换汇' : '相同币种账户必须选择同币种转账')
      }
      this.saving = true
      try {
        const r = this.docId ? await updateFinanceTransfer(this.docId, this.payload()) : await createFinanceTransfer(this.payload())
        this.previewData = null
        this.$message.success('草稿已保存')
        if (!this.docId) {
          await this.$router.replace(`/finance/transfers/${r.data.data.id}`)
        } else {
          await this.loadDetail()
        }
      } catch (e) {
        this.$message.error(e.userMessage || '保存失败')
      } finally {
        this.saving = false
      }
    },
    async saveAndConfirm() {
      if (!this.docId) {
        await this.saveDraft()
        if (!this.docId) return
      }
      await this.confirm()
    },
    async confirm() {
      try {
        if (!this.fxPreview.preview_token) {
          await this.loadPreview()
          if (!this.fxPreview.preview_token) return
        }
        await this.$confirm('确认后将写入不可直接修改的资金台账，是否继续？', '确认入账', { type: 'warning' })
        this.saving = true
        await confirmFinanceTransfer(this.docId, this.fxPreview.preview_token)
        this.$message.success('已确认入账')
        await this.loadDetail()
      } catch (e) {
        if (e !== 'cancel') {
          this.$message.error(e.userMessage || '确认失败')
          if (String(e.userMessage || '').includes('已发生变化')) this.loadPreview()
        }
      } finally {
        this.saving = false
      }
    },
    async voidDoc() {
      try {
        const { value } = await this.$prompt('请输入作废原因', '作废 / 冲销', { inputValidator: v => v ? true : '请填写作废原因' })
        await voidFinanceTransfer(this.docId, value)
        this.$message.success('已作废，台账将排除该资金事实')
        await this.loadDetail()
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || '作废失败')
      }
    },
    async upload(req) {
      const f = new FormData()
      f.append('file', req.file)
      try {
        await uploadTransferAttachment(this.docId, f)
        this.$message.success('附件已上传')
        this.loadDetail()
      } catch (e) {
        this.$message.error(e.userMessage || '上传失败')
      }
    },
    async previewAttachment(a) {
      try {
        const r = await previewFinanceAttachment(a.id)
        this.previewUrl = URL.createObjectURL(r.data)
        this.previewVisible = true
      } catch (e) {
        this.$message.error(e.userMessage || '该文件无法预览')
      }
    },
    async removeAttachment(a) {
      try {
        await deleteFinanceAttachment(a.id)
        this.$message.success('已删除')
        this.loadDetail()
      } catch (e) {
        this.$message.error(e.userMessage || '删除失败')
      }
    },
    money(v) {
      if (v === null || v === undefined || v === '') return '—'
      return Number(v).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    rate(v) {
      return Number(v || 0).toFixed(4)
    },
    signed(v) {
      const n = Number(v || 0)
      return `${n > 0 ? '+' : ''}${this.money(n)}`
    },
    formatDate(v) {
      return v ? String(v).replace('T', ' ').slice(0, 19) : '—'
    }
  }
}
</script>

<style scoped>
/* Base Page Shell */
.fx-page {
  padding: 16px 20px 24px;
  background: #f5f7fa;
  min-height: calc(100vh - 54px);
  color: #1f2937;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  box-sizing: border-box;
}

/* Top Bar: Breadcrumb + Buttons */
.fx-top-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
}

.fx-crumb {
  font-size: 13px;
  color: #6b7280;
}

.fx-crumb i {
  font-style: normal;
  color: #9ca3af;
  margin: 0 6px;
}

.fx-crumb b {
  color: #1f2937;
  font-weight: 600;
}

.fx-actions {
  display: flex;
  gap: 10px;
}

.btn-primary-green {
  background: #008b4b !important;
  border-color: #008b4b !important;
  font-weight: 500;
}

.btn-primary-green:hover {
  background: #007a41 !important;
  border-color: #007a41 !important;
}

/* Mode Selector Row */
.fx-mode-row {
  display: flex;
  gap: 16px;
  margin-bottom: 14px;
}

.mode-card {
  position: relative;
  display: flex;
  align-items: center;
  gap: 14px;
  width: 320px;
  height: 76px;
  padding: 0 18px;
  border: 1px solid #e5eaf2;
  border-radius: 8px;
  background: #ffffff;
  color: #1f2937;
  cursor: pointer;
  text-align: left;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
}

.mode-card:hover {
  border-color: #008b4b;
}

.mode-card.active {
  border: 1.5px solid #008b4b;
  background: #ffffff;
  box-shadow: 0 2px 6px rgba(0, 139, 75, 0.08);
}

.mode-icon-box {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  background: #f3f4f6;
  color: #6b7280;
  transition: all 0.2s ease;
}

.mode-card.active .mode-icon-box {
  background: #eaf8f0;
  color: #008b4b;
}

.mode-info {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.mode-info b {
  font-size: 15px;
  font-weight: 700;
  color: #111827;
}

.mode-info small {
  font-size: 12px;
  color: #6b7280;
}

.mode-check-badge {
  position: absolute;
  top: 10px;
  right: 12px;
  color: #008b4b;
  font-size: 17px;
}

/* Main Grid Layout */
.fx-main-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 280px;
  gap: 16px;
  align-items: start;
}

.fx-left-column {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

/* 3 Process Cards Row */
.fx-process-row {
  display: grid;
  grid-template-columns: minmax(230px, 1fr) 30px minmax(280px, 1.18fr) 30px minmax(230px, 1fr);
  gap: 0;
  align-items: stretch;
}

.process-arrow {
  display: grid;
  place-items: center;
  font-size: 18px;
  color: #9ca3af;
}

/* Common Card Styles */
.fx-card {
  background: #ffffff;
  border: 1px solid #e5eaf2;
  border-radius: 8px;
  padding: 16px 18px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  box-sizing: border-box;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.card-header.simple {
  margin-bottom: 10px;
}

.card-title {
  font-size: 15px;
  font-weight: 700;
  color: #111827;
}

.card-badge {
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 500;
}

.badge-blue {
  background: #e8f4ff;
  color: #1677ff;
}

.badge-green {
  background: #eaf8f0;
  color: #008b4b;
}

.badge-gray {
  background: #f3f4f6;
  color: #6b7280;
}

/* Bank Account Card Profiles */
.account-selector-wrap {
  margin-bottom: 10px;
}

.account-selector-wrap .el-select {
  width: 100%;
}

.bank-profile {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding-bottom: 14px;
  border-bottom: 1px solid #edf1f5;
  margin-bottom: 12px;
}

.bank-logo-wrap {
  flex-shrink: 0;
  margin-top: 2px;
}

.bank-avatar-emblem {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: 18px;
}

.red-emblem {
  background: #fff0f0;
  color: #e53e3e;
}

.bank-text-details {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.bank-name {
  font-size: 14px;
  font-weight: 700;
  color: #111827;
  line-height: 1.3;
}

.bank-branch {
  font-size: 12px;
  color: #6b7280;
  margin: 0;
}

.bank-no {
  font-size: 12px;
  color: #6b7280;
  margin: 0;
}

.empty-account-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 80px;
  padding: 12px;
  margin-bottom: 12px;
  color: #9ca3af;
  border: 1px dashed #d1d5db;
  border-radius: 6px;
  font-size: 12px;
}

/* Key-Value Lists */
.kv-list {
  display: flex;
  flex-direction: column;
  gap: 9px;
  font-size: 13px;
}

.kv-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.kv-label {
  color: #6b7280;
}

.kv-val {
  color: #1f2937;
  text-align: right;
}

.card-footer-note {
  margin-top: 14px;
  padding-top: 10px;
  border-top: 1px dashed #edf1f5;
  font-size: 11px;
  color: #9ca3af;
}

/* Deal Card Content */
.deal-content {
  display: flex;
  flex-direction: column;
  gap: 7px;
  font-size: 13px;
}

.deal-item {
  display: grid;
  grid-template-columns: 120px minmax(0, 1fr);
  align-items: center;
  min-height: 28px;
}

.deal-item.align-top {
  align-items: flex-start;
  padding-top: 2px;
}

.deal-label {
  color: #6b7280;
}

.deal-field {
  width: 100%;
}

.deal-val {
  color: #1f2937;
}

.deal-sub-hint {
  display: block;
  font-size: 11px;
  line-height: 1.3;
  margin-top: 1px;
}

.text-right {
  text-align: right;
}

.green-input :deep(.el-input__inner) {
  color: #008b4b;
  font-weight: 700;
}

/* Lower 3 Cards Row */
.fx-lower-row {
  display: grid;
  grid-template-columns: 1fr 1.15fr 1.15fr;
  gap: 14px;
}

/* Fee Card */
.fee-content {
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-size: 13px;
}

.fee-line {
  display: grid;
  grid-template-columns: 86px minmax(0, 1fr);
  align-items: center;
  min-height: 28px;
}

.fee-label {
  color: #6b7280;
}

.fee-footnote {
  margin: 6px 0 0;
  font-size: 11px;
  color: #9ca3af;
}

/* Attachment Card */
.fx-upload-dropzone {
  display: block;
  border: 1px dashed #d1d5db;
  border-radius: 6px;
  background: #fcfdfd;
  padding: 10px;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.2s;
}

.fx-upload-dropzone:hover {
  border-color: #008b4b;
}

.upload-dropzone-inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}

.upload-clip-icon {
  font-size: 18px;
  color: #6b7280;
}

.upload-primary-text {
  font-size: 12px;
  color: #374151;
}

.upload-hint-text {
  font-size: 10px;
  color: #9ca3af;
}

.empty-attach-hint {
  font-size: 12px;
  color: #9ca3af;
  text-align: center;
  padding: 12px;
}

.attach-list {
  margin-top: 8px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.attach-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  background: #f9fafb;
  border: 1px solid #edf1f5;
  border-radius: 6px;
}

.attach-icon-badge {
  font-size: 16px;
  color: #ef4444;
}

.attach-meta {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.attach-name {
  font-size: 12px;
  color: #1f2937;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.attach-ext {
  font-size: 10px;
  color: #9ca3af;
}

.attach-ops {
  display: flex;
  gap: 6px;
}

.icon-btn {
  border: 0;
  background: none;
  color: #6b7280;
  cursor: pointer;
  padding: 2px;
  font-size: 14px;
}

.icon-btn:hover {
  color: #111827;
}

.icon-btn.danger:hover {
  color: #ef4444;
}

/* Remark Card */
.remark-content .el-textarea {
  width: 100%;
}

/* Right Sidebar: Rules & Tips */
.rule-card {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

.rule-title {
  font-size: 15px;
  font-weight: 700;
  color: #111827;
  margin: 0 0 14px;
  padding-bottom: 10px;
  border-bottom: 1px solid #edf1f5;
}

.rule-sections {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.rule-block {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.rule-block-head {
  display: flex;
  align-items: center;
  gap: 6px;
  color: #1f2937;
  font-size: 13px;
}

.rule-icon {
  color: #6b7280;
  font-size: 14px;
}

.rule-bullet {
  margin: 0;
  padding-left: 18px;
  font-size: 12px;
  color: #6b7280;
  line-height: 1.5;
}

.rule-footer-link {
  margin-top: 20px;
  padding-top: 14px;
  border-top: 1px solid #edf1f5;
}

.rule-footer-link a {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #1677ff;
  font-size: 13px;
  text-decoration: none;
  font-weight: 500;
}

.rule-footer-link a:hover {
  text-decoration: underline;
}

/* Bottom Summary Banner */
.summary-banner {
  display: grid;
  grid-template-columns: 1.15fr 1fr 1.25fr 1.05fr;
  margin-top: 16px;
  background: #ffffff;
  border: 1px solid #d1fae5;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  overflow: hidden;
}

.summary-col {
  padding: 16px 20px;
  border-right: 1px solid #edf1f5;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.summary-col:last-child {
  border-right: 0;
}

.summary-label {
  font-size: 12px;
  color: #6b7280;
  line-height: 1.4;
}

.summary-value-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 4px;
}

.summary-num {
  font-size: 22px;
  font-weight: 700;
  color: #111827;
  line-height: 1.2;
}

.summary-info-icon {
  color: #9ca3af;
  font-size: 14px;
  cursor: pointer;
}

.summary-subtext {
  font-size: 11px;
  margin-top: 2px;
}

.summary-fee-col {
  justify-content: center;
}

.summary-fee-desc {
  font-size: 11px;
  color: #6b7280;
  line-height: 1.4;
  margin-top: 1px;
}

/* Utility Color & Typography */
.font-semibold {
  font-weight: 600;
}

.font-bold {
  font-weight: 700;
}

.font-medium {
  font-weight: 500;
}

.text-blue {
  color: #2563eb !important;
}

.text-green {
  color: #008b4b !important;
}

.text-loss {
  color: #ef4444 !important;
}

.text-gain {
  color: #008b4b !important;
}

.text-muted {
  color: #9ca3af;
  font-weight: 400;
}

.text-gray {
  color: #9ca3af;
}

/* Operation Logs */
.log-card {
  margin-top: 16px;
}

.preview {
  width: 100%;
  height: 70vh;
  border: 0;
}

/* Responsive */
@media (max-width: 1300px) {
  .fx-main-grid {
    grid-template-columns: 1fr;
  }
  .rule-card {
    display: none;
  }
}
</style>
