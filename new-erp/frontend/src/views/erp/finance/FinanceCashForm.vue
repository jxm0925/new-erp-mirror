<template>
  <div class="cash-form-page" v-loading="loading">
    <!-- 页面全局头部：图标、标题、状态标签与主要操作 (完全对齐主数据中心规范) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i :class="direction === 'receipt' ? 'el-icon-bottom-left' : 'el-icon-wallet'" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">{{ isNew ? '新增' + title + '单' : title + '单详情' }}</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">
              {{ isNew ? '录入新' + title : statusLabel }}
            </el-tag>
            <el-tag v-if="doc.document_no" size="small" type="info" effect="plain" class="doc-no-tag">
              {{ doc.document_no }}
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-back" class="btn-back" :disabled="saving" @click="back">返回列表</el-button>
        <el-button v-if="canEdit" size="small" icon="el-icon-folder" class="btn-save-draft" :loading="saving" :disabled="loading || Boolean(sourceLoadError)" @click="save">
          保存草稿
        </el-button>
        <el-button
          v-if="canConfirm"
          size="small"
          type="success"
          icon="el-icon-check"
          class="btn-theme-create"
          :loading="saving"
          :disabled="loading || Boolean(sourceLoadError)"
          @click="confirmDoc"
        >
          {{ canEdit ? '保存并确认' : '确认' }}
        </el-button>
      </div>
    </header>

    <!-- 全局统一页面提示条 (对齐主数据中心规范) -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>{{ title }}单代表企业真实资金流转事实。草稿阶段可录入收付款要素并预设核销冲抵，审核确认后金额正式过账入账，不可直接撤回修改。</span>
    </div>

    <!-- 来源异常提示 -->
    <el-alert v-if="sourceLoadError" :title="sourceLoadError" type="error" :closable="false" show-icon class="source-error-alert" />

    <!-- 顶部核心区域：基本信息 (左) + 核销明细 (右) -->
    <section class="cash-form-top">
      <!-- 模块一：核心基础要素 -->
      <article class="form-card master-card basic-card">
        <div class="card-header">
          <div class="header-main">
            <span class="card-icon-badge"><i class="el-icon-document" /></span>
            <div>
              <h2 class="card-title">{{ title }}基本信息</h2>
              <span class="card-subtitle">录入单据编码、业务日期、交易对手及资金结算要素</span>
            </div>
          </div>
        </div>

        <el-form
          ref="form"
          :model="doc"
          :rules="rules"
          :disabled="saving || loading"
          label-position="top"
          size="small"
          class="basic-form-flow"
        >
          <div class="fields-grid">
            <el-form-item :label="title + '单号'">
              <el-input v-model="doc.document_no" disabled placeholder="系统预生成" />
            </el-form-item>

            <el-form-item :label="title + '日期'" prop="business_date">
              <el-date-picker
                v-model="doc.business_date"
                value-format="yyyy-MM-dd"
                type="date"
                :disabled="!canEdit"
                placeholder="请选择日期"
                class="full-width"
              />
            </el-form-item>

            <el-form-item label="交易对手类型" prop="party_type">
              <el-radio-group v-model="doc.party_type" :disabled="!isNew || Boolean(sourceContext) || Boolean(purchaseOrderContext) || !canEdit" class="party-radio-group" @change="partyChanged">
                <el-radio label="customer">客户</el-radio>
                <el-radio label="supplier">供应商</el-radio>
              </el-radio-group>
            </el-form-item>

            <el-form-item :label="doc.party_type === 'customer' ? '客户名称' : '供应商名称'" prop="party_id">
              <el-select
                v-model="doc.party_id"
                filterable
                remote
                reserve-keyword
                :remote-method="searchParties"
                :loading="partyLoading"
                :disabled="!isNew || Boolean(sourceContext) || Boolean(purchaseOrderContext) || !canEdit"
                placeholder="输入关键字检索..."
                class="full-width"
                @change="clearPending"
              >
                <el-option
                  v-for="p in parties"
                  :key="p.id"
                  :label="p.name"
                  :value="p.id"
                />
              </el-select>
            </el-form-item>

            <el-form-item label="资金账户" prop="finance_account_id">
              <el-select v-model="doc.finance_account_id" :disabled="!canEdit" placeholder="请选择过账资金账户" class="full-width" @change="accountChanged">
                <el-option
                  v-for="a in accounts"
                  :key="a.id"
                  :label="`${a.account_name}（${a.currency}）`"
                  :value="a.id"
                  :disabled="(Boolean(doc.id) || Boolean(sourceContext) || Boolean(purchaseOrderContext)) && a.currency !== doc.currency"
                />
              </el-select>
            </el-form-item>

            <el-form-item label="结算币种" prop="currency">
              <el-input v-model="doc.currency" disabled class="font-tabular" />
            </el-form-item>

            <el-form-item :label="'实' + title + '金额'" prop="amount">
              <el-input
                v-model.trim="doc.amount"
                :disabled="!canEdit"
                placeholder="0.00"
                class="font-tabular money-input"
              >
                <template slot="append">{{ doc.currency || '—' }}</template>
              </el-input>
            </el-form-item>

            <el-form-item :label="title + '结算方式'" prop="payment_method_id">
              <el-select v-model="doc.payment_method_id" :disabled="!canEdit" placeholder="请选择结算方式" class="full-width">
                <el-option
                  v-for="m in methods"
                  :key="m.id"
                  :label="m.method_name"
                  :value="m.id"
                />
              </el-select>
            </el-form-item>

            <el-form-item label="平台/手续费">
              <el-input v-model.trim="doc.platform_fee_amount" :disabled="!canEdit" placeholder="0.00" class="font-tabular">
                <template slot="append">{{ doc.currency || '—' }}</template>
              </el-input>
            </el-form-item>

            <el-form-item label="手续费类型">
              <el-select v-model="doc.platform_fee_type" :disabled="!canEdit" class="full-width">
                <el-option label="平台手续费" value="platform" />
                <el-option label="银行手续费" value="bank" />
                <el-option label="其他费用" value="other" />
              </el-select>
            </el-form-item>

            <el-form-item label="外部参考流水号">
              <el-input
                v-model.trim="doc.external_reference_no"
                :disabled="!canEdit"
                placeholder="银行水单号 / 票据流水号..."
              />
            </el-form-item>

            <el-form-item label="经办人">
              <el-input
                :value="doc.operator_name_snapshot || '当前登录人'"
                disabled
              />
            </el-form-item>

            <el-form-item class="full-col" label="业务备注说明">
              <el-input
                v-model.trim="doc.remark"
                type="textarea"
                :rows="2"
                :disabled="!canEdit"
                placeholder="选填，补充说明资金收付原因、协议摘要等..."
              />
            </el-form-item>
          </div>
        </el-form>
      </article>

      <!-- 模块二：待核销业务 / 核销明细 -->
      <article class="form-card master-card allocation-card">
        <div class="card-header">
          <div class="header-main">
            <span class="card-icon-badge"><i class="el-icon-connection" /></span>
            <div>
              <h2 class="card-title">{{ doc.status === 'draft' ? '待核销业务' : '核销明细' }}</h2>
              <span class="card-subtitle">{{ doc.status === 'draft' ? '选择本次资金预冲抵的应付/应收单据' : '审核后已形成的对账冲减流水' }}</span>
            </div>
          </div>
          <div class="card-header-actions">
            <el-button v-if="canEdit && $can('finance.allocation.create')" size="small" class="btn-theme-outline" icon="el-icon-plus" :disabled="saving || loading || Boolean(sourceLoadError)" @click="openSourcePicker">
              选择业务来源
            </el-button>
            <el-button v-else-if="doc.id && doc.status !== 'draft'" size="small" class="btn-theme-outline" icon="el-icon-view" @click="allocationVisible = true">
              {{ doc.status === 'confirmed' && Number(doc.unallocated_amount) > 0 && $can('finance.allocation.create') ? '添加 / 查看核销' : '查看核销记录' }}
            </el-button>
          </div>
        </div>

        <div class="allocation-content">
          <template v-if="doc.status === 'draft'">
            <finance-pending-allocations :rows="pending" :editable="canEdit && !saving" @remove="removePending" />
            <el-alert v-if="pendingError" :title="pendingError" type="warning" :closable="false" show-icon class="pending-warning-alert" />
            <div class="allocation-total-bar">
              <div class="total-stat-item">
                <span class="stat-label">待核销合计</span>
                <strong class="stat-val font-tabular">{{ money(pendingTotal) }}</strong>
              </div>
              <div class="total-stat-item">
                <span class="stat-label">预留未核销</span>
                <strong :class="['stat-val', 'font-tabular', draftRemaining < 0 ? 'text-danger' : 'text-remaining']">{{ money(draftRemaining) }}</strong>
              </div>
            </div>
          </template>

          <template v-else>
            <div class="table-wrap">
              <el-table :data="doc.allocations || []" border stripe size="small" empty-text="暂无核销记录" class="enterprise-table">
                <el-table-column label="来源类型" min-width="130">
                  <template slot-scope="{ row }">{{ sourceLabel(row.source_business_type) }}</template>
                </el-table-column>
                <el-table-column prop="source_document_no" label="业务单号" min-width="150" />
                <el-table-column label="核销金额" min-width="110" align="right">
                  <template slot-scope="{ row }">
                    <span class="font-tabular font-bold">{{ money(row.allocated_amount) }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="状态" width="85" align="center">
                  <template slot-scope="{ row }">
                    <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="mini" effect="plain">
                      {{ row.status === 'active' ? '已核销' : '已撤销' }}
                    </el-tag>
                  </template>
                </el-table-column>
                <el-table-column label="核销时间" min-width="155">
                  <template slot-scope="{ row }">
                    <span class="font-tabular">{{ formatDateTime(row.allocated_at) }}</span>
                  </template>
                </el-table-column>
              </el-table>
            </div>
            <div class="allocation-total-bar">
              <div class="total-stat-item">
                <span class="stat-label">已核销金额</span>
                <strong class="stat-val font-tabular">{{ money(doc.allocated_amount) }}</strong>
              </div>
              <div class="total-stat-item">
                <span class="stat-label">未核销余额</span>
                <strong class="stat-val font-tabular text-remaining">{{ money(doc.unallocated_amount) }}</strong>
              </div>
            </div>
          </template>
        </div>
      </article>
    </section>

    <!-- 模块三：采购订单用途关联 (当交易对手为供应商时呈现) -->
    <section v-if="doc.party_type === 'supplier'" class="form-card master-card purchase-links-card">
      <div class="card-header">
        <div class="header-main">
          <span class="card-icon-badge"><i class="el-icon-goods" /></span>
          <div>
            <h2 class="card-title">采购订单用途关联</h2>
            <span class="card-subtitle">指定本次资金出账所对应的采购合同及款项期次用途</span>
          </div>
        </div>
        <div v-if="doc.id && doc.status === 'confirmed' && $can(confirmPermission)" class="card-header-actions">
          <el-button size="small" class="btn-theme-outline" icon="el-icon-edit" :disabled="saving || loading" @click="purchaseLinkDialogVisible = true">
            补充 / 调整采购关联
          </el-button>
        </div>
      </div>

      <div class="purchase-links-body">
        <purchase-payment-links
          ref="purchaseLinks"
          v-model="doc.purchase_order_allocations"
          :supplier-id="Number(doc.party_id)"
          :currency="doc.currency"
          :amount="doc.amount"
          :direction="direction"
          :readonly="!canEdit || !$can('finance.view')"
          :disabled="saving || loading"
        />

        <el-form v-if="canEdit" label-position="top" size="small" class="purchase-allocation-reason">
          <el-form-item :label="direction === 'receipt' ? '退款用途说明' : '付款用途说明'">
            <el-input
              v-model.trim="doc.purchase_order_allocation_reason"
              type="textarea"
              :rows="2"
              maxlength="1000"
              :disabled="saving || loading"
              placeholder="超出合同金额付款或特殊排期用途时请补充说明原因..."
            />
          </el-form-item>
        </el-form>
        <div v-else-if="doc.purchase_order_allocation_reason" class="purchase-allocation-note">
          <i class="el-icon-info" /> 用途说明：{{ doc.purchase_order_allocation_reason }}
        </div>
      </div>
    </section>

    <!-- 底部区域：附件 (左) + 金额守恒校验 (右) -->
    <section class="cash-form-bottom">
      <!-- 模块四：单据附件 -->
      <article class="form-card master-card attachment-card">
        <div class="card-header">
          <div class="header-main">
            <span class="card-icon-badge"><i class="el-icon-paperclip" /></span>
            <div>
              <h2 class="card-title">单据附件</h2>
              <span class="card-subtitle">付款水单、结算凭证、对账单据等支持文件</span>
            </div>
          </div>
        </div>

        <div class="attachment-body">
          <el-upload
            v-if="doc.id && canEdit"
            action="#"
            :show-file-list="false"
            :http-request="upload"
            :disabled="saving"
            class="upload-placeholder-zone"
          >
            <div class="upload-placeholder">
              <i class="el-icon-upload2" />
              <span class="upload-title">点击或拖拽上传资金回单凭证</span>
              <span class="upload-sub">单个文件不超过 50MB，支持 PDF、JPG、PNG、Excel、Word</span>
            </div>
          </el-upload>
          <div v-else class="upload-placeholder-zone muted">
            <div class="upload-placeholder muted">
              <i class="el-icon-upload2" />
              <span class="upload-title">{{ doc.id ? "当前单据已锁定，不可再变更附件" : "保存草稿后可上传附件" }}</span>
            </div>
          </div>

          <div class="table-wrap mt-8">
            <el-table :data="activeAttachments" border stripe size="mini" empty-text="暂无附件" class="enterprise-table">
              <el-table-column prop="original_name" label="文件名" min-width="160" show-overflow-tooltip />
              <el-table-column label="大小" width="85" align="center">
                <template slot-scope="{ row }">{{ fileSize(row.file_size) }}</template>
              </el-table-column>
              <el-table-column prop="uploaded_at" label="上传时间" min-width="140" align="center" />
              <el-table-column label="操作" width="110" align="center">
                <template slot-scope="{ row }">
                  <el-button type="text" size="mini" class="btn-action-view" @click="preview(row)">预览</el-button>
                  <el-button v-if="canEdit" type="text" size="mini" class="btn-action-void danger" @click="removeAttachment(row)">删除</el-button>
                </template>
              </el-table-column>
            </el-table>
          </div>
        </div>
      </article>

      <!-- 模块五：金额守恒校验 -->
      <article class="form-card master-card balance-card">
        <div class="card-header">
          <div class="header-main">
            <span class="card-icon-badge"><i class="el-icon-check" /></span>
            <div>
              <h2 class="card-title">金额守恒校验</h2>
              <span class="card-subtitle">实付事实金额、核销金额与未核销余额动态平衡</span>
            </div>
          </div>
        </div>

        <div class="balance-body">
          <div class="balance-row">
            <span class="balance-label">实{{ title }}金额</span>
            <strong class="balance-val font-tabular">{{ money(doc.amount) }} {{ doc.currency }}</strong>
          </div>
          <div class="balance-row">
            <span class="balance-label">{{ doc.status === 'draft' ? '待核销合计' : '已核销金额' }}</span>
            <strong class="balance-val font-tabular">{{ money(doc.status === 'draft' ? pendingTotal : doc.allocated_amount) }} {{ doc.currency }}</strong>
          </div>
          <div class="balance-row">
            <span class="balance-label">{{ doc.status === 'draft' ? '预留未核销' : '未核销余额' }}</span>
            <strong class="balance-val font-tabular text-remaining">{{ money(doc.status === 'draft' ? draftRemaining : doc.unallocated_amount) }} {{ doc.currency }}</strong>
          </div>

          <div :class="['balance-status-banner', balanceValid ? 'balance-pass' : 'balance-warning']">
            <i :class="balanceValid ? 'el-icon-circle-check' : 'el-icon-warning-outline'" />
            <span>{{ balanceValid ? '资金要素平衡：核对通过' : '核算提示：请核对实付金额及待核销单据' }}</span>
          </div>
        </div>
      </article>
    </section>

    <!-- 模块六：单据流转操作日志 -->
    <section class="form-card master-card logs-card">
      <div class="card-header">
        <div class="header-main">
          <span class="card-icon-badge"><i class="el-icon-time" /></span>
          <div>
            <h2 class="card-title">单据流转日志</h2>
            <span class="card-subtitle">记录制单、修改、审核与核销流水审计轨迹</span>
          </div>
        </div>
      </div>

      <div class="table-wrap">
        <el-table :data="doc.logs || []" border stripe size="mini" max-height="160" empty-text="暂无操作日志" class="enterprise-table">
          <el-table-column label="操作时间" width="165" align="center">
            <template slot-scope="{ row }">{{ formatDateTime(row.created_at) }}</template>
          </el-table-column>
          <el-table-column prop="operator_name" label="操作人" width="105" align="center">
            <template slot-scope="{ row }">{{ row.operator_name || "系统" }}</template>
          </el-table-column>
          <el-table-column label="操作类型" width="115" align="center">
            <template slot-scope="{ row }">
              <el-tag size="mini" type="info" effect="plain">{{ logLabel(row.action) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="content" label="操作内容" min-width="300" show-overflow-tooltip />
          <el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip />
        </el-table>
      </div>
    </section>

    <!-- 弹窗组件 -->
    <finance-source-picker :visible.sync="sourcePickerVisible" :direction="direction" :party-type="doc.party_type" :party-id="Number(doc.party_id)" :currency="doc.currency" :existing="pending" @confirm="addSources" />
    <purchase-payment-link-dialog :visible.sync="purchaseLinkDialogVisible" :cash-id="Number(doc.id)" @changed="allocationChanged" />
    <el-dialog title="收付款核销" :visible.sync="allocationVisible" width="1100px" top="5vh" append-to-body custom-class="cash-allocation-dialog" :close-on-click-modal="false" destroy-on-close>
      <finance-allocation v-if="allocationVisible" :cash-id="Number(doc.id)" embedded @changed="allocationChanged" />
    </el-dialog>
    <el-dialog title="附件预览" :visible.sync="previewVisible" width="80%" top="5vh" @closed="closePreview">
      <img v-if="previewImage" :src="previewUrl" class="preview-media" />
      <iframe v-else :src="previewUrl" class="preview-frame" />
    </el-dialog>
  </div>
</template>

<script>
import { listFinanceAccounts, listPaymentMethods, getCashDocument, createCashDocument, updateCashDocument, confirmCashDocument, resolveFinanceSource, uploadFinanceAttachment, previewFinanceAttachment, deleteFinanceAttachment } from '../../../api/erp/finance'
import { listEntity } from '../../../api/erp/master'
import { listSalesCustomers } from '../../../api/erp/sales'
import { getPurchasePaymentPlan } from '../../../api/erp/purchasePayments'
import FinanceAllocation from './FinanceAllocation.vue'
import FinanceSourcePicker from '../../../components/finance/FinanceSourcePicker.vue'
import FinancePendingAllocations from '../../../components/finance/FinancePendingAllocations.vue'
import { sourceKey, sourceMismatch, sourceOptionsFor, amountUnits, pendingFromSource, allocationTotal, serializeAllocationItems, validatePendingAllocations } from '../../../utils/financeCashAllocation'
import { positivePaymentId, serializePurchaseOrderAllocations, validatePurchaseOrderAllocations, purchaseOrderCanPay, paymentErrorMessage } from '../../../utils/purchasePayment'
import { reserveForCreatePage, clearCreatePageReservation } from '../../../utils/documentNumberReservation'

const today = () => {
  const d = new Date()
  const pad = (v) => String(v).padStart(2, "0")
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

const blank = () => ({
  id: null,
  document_no: "",
  party_type: "",
  party_id: null,
  business_date: today(),
  finance_account_id: null,
  currency: "CNY",
  amount: "",
  platform_fee_amount: "0",
  platform_fee_type: "platform",
  payment_method_id: null,
  payment_method: "",
  external_reference_no: "",
  remark: "",
  status: "draft",
  allocated_amount: "0",
  unallocated_amount: "0",
  allocations: [],
  draft_allocation_items: [],
  purchase_order_allocations: [],
  purchase_order_allocation_version: 0,
  purchase_order_allocation_reason: '',
  attachments: [],
  logs: [],
})

export default {
  components: {
    FinanceAllocation, FinanceSourcePicker, FinancePendingAllocations,
    PurchasePaymentLinks: () => import('../../../components/finance/PurchasePaymentLinks.vue'),
    PurchasePaymentLinkDialog: () => import('../../../components/finance/PurchasePaymentLinkDialog.vue'),
  },
  props: { direction: { type: String, required: true } },
  data: () => ({
    loading: false,
    saving: false,
    partyLoading: false,
    pending: [],
    sourcePickerVisible: false,
    allocationVisible: false,
    sourceLoadError: '',
    sourceContext: null,
    purchaseOrderContext: null,
    purchaseLinkDialogVisible: false,
    initRevision: 0,
    partyRevision: 0,
    savedNavigationId: null,
    doc: blank(),
    accounts: [],
    parties: [],
    reservation: null,
    previewVisible: false,
    previewUrl: "",
    previewImage: false,
    methods: [],
    rules: {
      business_date: [
        { required: true, message: "请选择日期", trigger: "change" },
      ],
      party_type: [
        { required: true, message: "请选择交易对手类型", trigger: "change" },
      ],
      party_id: [
        { required: true, message: "请选择交易对手", trigger: "change" },
      ],
      finance_account_id: [
        { required: true, message: "请选择资金账户", trigger: "change" },
      ],
      amount: [
        { required: true, message: "请输入金额", trigger: "blur" },
        {
          pattern: /^\d+(\.\d{1,4})?$/,
          message: "金额最多 4 位小数",
          trigger: "blur",
        },
      ],
      payment_method_id: [
        { required: true, message: "请选择方式", trigger: "change" },
      ],
    },
  }),
  computed: {
    id() {
      return Number(this.$route.params.id || 0)
    },
    isNew() {
      return !this.id
    },
    title() {
      return this.direction === "receipt" ? "收款" : "付款"
    },
    basePath() {
      return this.direction === "receipt"
        ? "/finance/receipts"
        : "/finance/payments"
    },
    createPermission() {
      return `finance.${this.direction}.create`
    },
    confirmPermission() {
      return `finance.${this.direction}.confirm`
    },
    canEdit() {
      return this.doc.status === "draft" && this.$can(this.createPermission)
    },
    canConfirm() {
      return this.doc.status === 'draft' && this.$can(this.confirmPermission) && (!this.isNew || this.canEdit)
    },
    pendingTotal() { return allocationTotal(this.pending) },
    draftRemaining() { return ((amountUnits(this.doc.amount) || 0) - Math.round(this.pendingTotal * 10000)) / 10000 },
    pendingError() { return validatePendingAllocations(this.pending, this.doc.amount) },
    purchaseLinksError() { return validatePurchaseOrderAllocations(this.doc.purchase_order_allocations || [], this.doc.amount, this.doc.party_type) },
    balanceValid() {
      if (this.doc.status === 'draft') return amountUnits(this.doc.amount) > 0 && !this.pendingError && !this.purchaseLinksError && !this.sourceLoadError
      return amountUnits(this.doc.amount) === (amountUnits(this.doc.allocated_amount) || 0) + (amountUnits(this.doc.unallocated_amount) || 0)
    },
    readonly() {
      return !this.canEdit
    },
    statusLabel() {
      return this.doc.status === "voided"
        ? "已作废"
        : this.doc.status === "draft"
        ? "草稿"
        : Number(this.doc.unallocated_amount) === 0
        ? "已全部核销"
        : Number(this.doc.allocated_amount) > 0
        ? "已部分核销"
        : "已确认"
    },
    activeAttachments() {
      return (this.doc.attachments || []).filter((x) => x.status === "active")
    },
  },
  watch: {
    direction() {
      this.init()
    },
    "$route.fullPath"() {
      if (this.savedNavigationId && this.id === this.savedNavigationId) { this.savedNavigationId = null; return }
      this.init()
    },
  },
  created() {
    this.init()
  },
  beforeDestroy() {
    this.initRevision += 1
    this.partyRevision += 1
    this.closePreview()
  },
  methods: {
    money(v) {
      return Number(v || 0).toLocaleString("zh-CN", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
      })
    },
    formatDateTime(v) {
      if (!v) return "—"
      const d = new Date(v)
      if (Number.isNaN(d.getTime())) return v
      const pad = (n) => String(n).padStart(2, "0")
      return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(
        d.getDate()
      )} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
    },
    fileSize(v) {
      const n = Number(v || 0)
      return n > 1048576
        ? (n / 1048576).toFixed(2) + " MB"
        : (n / 1024).toFixed(1) + " KB"
    },
    sourceLabel(v) {
      return (
        {
          sales_order: "销售订单",
          sales_order_refund: "销售订单退款",
          purchase_receipt: "采购到货（历史）",
          purchase_settlement_source: "采购结算来源",
          purchase_return_ap_offset: "采购退货冲应付",
          purchase_return_supplier_refund: "供应商退款",
        }[v] || v
      )
    },
    logLabel(v) {
      return (
        {
          create: "创建",
          update_draft: "修改草稿",
          confirm: "确认",
          allocate: "核销",
          reverse_allocation: "撤销核销",
          freeze_purchase_order_purposes: "确认采购用途",
          revise_purchase_order_purposes: "调整采购用途",
          void: "作废",
        }[v] || v
      )
    },
    async init() {
      const revision = ++this.initRevision
      const id = this.id
      const query = { ...this.$route.query }
      this.loading = true
      this.doc = blank()
      this.doc.party_type = this.direction === 'receipt' ? 'customer' : 'supplier'
      this.pending = []
      this.sourcePickerVisible = false
      this.allocationVisible = false
      this.sourceLoadError = ''
      this.sourceContext = null
      this.purchaseOrderContext = null
      this.purchaseLinkDialogVisible = false
      this.reservation = null
      this.parties = []
      this.closePreview()
      try {
        const [accounts, methods] = await Promise.all([
          listFinanceAccounts({ status: 'enabled', page: 1, per_page: 100 }),
          listPaymentMethods({ status: 'enabled', usage: this.direction, page: 1, per_page: 100 }),
        ])
        if (revision !== this.initRevision) return
        this.accounts = accounts.data.data || []
        this.methods = methods.data.data || []
        if (id) {
          const response = await getCashDocument(id)
          if (revision !== this.initRevision) return
          if (response.data.data.direction !== this.direction) throw new Error('资金单方向与当前页面不一致，请从对应列表打开')
          this.doc = { ...blank(), ...response.data.data }
          this.ensureParty()
          await this.restorePending(revision)
        } else {
          this.doc.payment_method_id = this.methods.length ? this.methods[0].id : null
          const reservation = await reserveForCreatePage(this.direction === 'receipt' ? 'finance_receipt' : 'finance_payment', `${this.basePath}/create`)
          if (revision !== this.initRevision) return
          this.reservation = reservation
          this.doc.document_no = reservation.document_no
          if ((query.source_type || query.source_id) && (query.purchase_order_id || query.payment_plan_id)) throw new Error('不能同时指定结算来源和采购付款期次，请重新选择付款入口')
          if (query.source_type || query.source_id) await this.loadInitialSource(query, revision)
          else if (query.purchase_order_id || query.payment_plan_id) await this.loadInitialPurchaseOrder(query, revision)
          else await this.searchParties('')
        }
      } catch (error) {
        if (revision !== this.initRevision) return
        this.sourceLoadError = error.userMessage || error.message || '页面加载失败，请返回列表重新打开'
        this.$message.error(this.sourceLoadError)
      } finally { if (revision === this.initRevision) this.loading = false }
    },
    async loadInitialSource(query, revision) {
      try {
        if (!query.source_type || !/^\d+$/.test(String(query.source_id || '')) || Number(query.source_id) <= 0) throw new Error('业务来源参数无效，请返回应付列表重新发起')
        if (!this.$can('finance.allocation.create')) throw new Error('当前账号没有核销权限，无法从业务来源发起收付款')
        const response = await resolveFinanceSource({ type: query.source_type, id: Number(query.source_id) })
        if (revision !== this.initRevision) return
        const source = response.data.data
        if (!sourceOptionsFor(this.direction, source.partyType).some(option => option.value === source.type)) throw new Error('业务来源与当前收付款方向不一致')
        if (!(amountUnits(source.remainingAmount) > 0)) throw new Error('该业务来源已无可核销余额，请返回列表重新核对')
        this.doc.party_type = source.partyType
        this.doc.party_id = Number(source.partyId)
        this.doc.party_name_snapshot = source.partyName
        this.doc.currency = source.currency
        this.doc.amount = String(source.remainingAmount)
        this.sourceContext = { type: source.type, id: source.id }
        this.ensureParty()
        this.pending = [pendingFromSource(source, this.doc.amount)]
        if (positivePaymentId(source.purchase_order_id)) {
          const orderResponse = await getPurchasePaymentPlan(Number(source.purchase_order_id))
          if (revision !== this.initRevision) return
          const order = orderResponse.data.data
          if (Number(order.supplier_id) !== Number(source.partyId) || order.currency !== source.currency) throw new Error('采购用途与结算来源的供应商或币种不一致，请重新核对')
          this.doc.purchase_order_allocations = [{ purchase_order_id: Number(source.purchase_order_id), purchase_order_no: order.purchase_order_no, payment_plan_id: null, amount: this.doc.amount }]
        }
      } catch (error) {
        if (revision === this.initRevision) this.sourceLoadError = error.userMessage || error.message || '指定业务来源加载失败，请返回列表重试'
      }
    },
    async loadInitialPurchaseOrder(query, revision) {
      try {
        const orderId = positivePaymentId(query.purchase_order_id)
        const planId = positivePaymentId(query.payment_plan_id)
        if (this.direction !== 'payment' || !orderId || (query.payment_plan_id && !planId)) throw new Error('采购付款来源无效，请返回付款安排重新发起')
        if (!this.$can('finance.view')) throw new Error('当前账号没有财务查看权限，无法读取采购付款安排')
        const response = await getPurchasePaymentPlan(orderId)
        if (revision !== this.initRevision) return
        const order = response.data.data
        if (Number(order.purchase_order_id) !== orderId || !positivePaymentId(order.supplier_id) || !order.currency) throw new Error('采购订单资料不完整，请重新查询')
        if (!purchaseOrderCanPay(order)) throw new Error('采购订单尚未审核完成或已取消，请返回采购订单核对')
        const plan = planId ? (order.items || []).find(row => Number(row.id) === planId) : null
        if (planId && !plan) throw new Error('该付款期次已失效，请返回付款安排重新选择')
        if (plan && !(amountUnits(plan.remaining_amount) > 0)) throw new Error('该付款期次已无待付金额，请返回付款安排核对')
        const suggested = plan ? plan.remaining_amount : order.contract_unpaid_amount
        const amount = amountUnits(suggested) > 0 ? String(suggested) : ''
        this.doc.party_type = 'supplier'
        this.doc.party_id = Number(order.supplier_id)
        this.doc.party_name_snapshot = order.supplier_name
        this.doc.currency = order.currency
        this.doc.amount = amount
        this.doc.purchase_order_allocations = [{ purchase_order_id: orderId, purchase_order_no: order.purchase_order_no, payment_plan_id: planId, sequence_no: plan?.sequence_no || null, trigger_type: plan?.trigger_type || null, due_date: plan?.due_date || null, amount }]
        this.purchaseOrderContext = { purchase_order_id: orderId, payment_plan_id: planId }
        this.ensureParty()
      } catch (error) {
        if (revision === this.initRevision) this.sourceLoadError = paymentErrorMessage(error, '采购付款安排加载失败，请返回重新选择')
      }
    },
    async restorePending(revision = this.initRevision) {
      if (this.doc.status !== 'draft') { this.pending = []; return }
      const intents = (this.doc.draft_allocation_items || []).map(row => ({ ...row, source_error: '正在核对业务来源' }))
      this.pending = intents
      const context = { ...this.doc, direction: this.direction }
      const restored = await Promise.all(intents.map(async row => {
        try {
          const response = await resolveFinanceSource({ type: row.source_business_type, id: row.source_document_id })
          const source = response.data.data
          const mismatch = sourceMismatch(source, context)
          if (mismatch) throw new Error(mismatch)
          return pendingFromSource(source, context.amount, row)
        } catch (error) {
          return { ...row, source_error: `业务来源 ${row.source_document_id}：${error.userMessage || error.message || '暂不可用，请移除后重新选择'}` }
        }
      }))
      if (revision === this.initRevision) this.pending = restored
    },
    ensureParty() {
      this.parties = [{ id: Number(this.doc.party_id), name: this.doc.party_name_snapshot || String(this.doc.party_id) }]
    },
    clearPending() {
      this.pending = []
      this.sourcePickerVisible = false
      this.doc.purchase_order_allocations = []
      this.purchaseOrderContext = null
    },
    partyChanged() {
      this.doc.party_id = null
      this.parties = []
      this.clearPending()
      this.searchParties('')
    },
    accountChanged(accountId) {
      const account = this.accounts.find(row => Number(row.id) === Number(accountId))
      if (account && account.currency !== this.doc.currency) {
        this.clearPending()
        this.doc.currency = account.currency
      }
    },
    async searchParties(keyword) {
      const revision = ++this.partyRevision
      const initRevision = this.initRevision
      const partyType = this.doc.party_type
      this.partyLoading = true
      try {
        const response = partyType === 'customer'
          ? await listSalesCustomers({ keyword, page: 1, per_page: 20 })
          : await listEntity('suppliers', { keyword, page: 1, per_page: 20 })
        if (revision !== this.partyRevision || initRevision !== this.initRevision) return
        this.parties = (response.data.data || []).map(row => ({ id: Number(row.id), name: partyType === 'customer' ? (row.customer_name || row.customer_short_name || row.contact_name || row.customer_code) : (row.supplier_name || row.name || row.supplier_code) }))
      } catch (error) {
        if (revision === this.partyRevision) this.$message.error(error.userMessage || '交易对手搜索失败')
      } finally { if (revision === this.partyRevision) this.partyLoading = false }
    },
    openSourcePicker() {
      if (!this.canEdit || !this.$can('finance.allocation.create') || this.sourceLoadError || this.saving) return
      if (!this.doc.party_id) return this.$message.warning('请先选择交易对手')
      this.sourcePickerVisible = true
    },
    removePending(index) { this.pending.splice(index, 1) },
    addSources(sources) {
      if (!this.canEdit || !this.$can('finance.allocation.create') || this.saving) return
      const validSources = sources.filter(source => {
        const mismatch = sourceMismatch(source, { ...this.doc, direction: this.direction })
        if (mismatch) { this.$message.warning(mismatch); return false }
        return !this.pending.some(row => sourceKey(row) === sourceKey(source)) && amountUnits(source.remainingAmount) > 0
      })
      if (!this.doc.amount && validSources.length) this.doc.amount = (validSources.reduce((sum, row) => sum + amountUnits(row.remainingAmount), 0) / 10000).toFixed(4)
      for (const source of validSources) {
        this.pending.push(pendingFromSource(source, this.draftRemaining.toFixed(4)))
      }
      if (this.pending.some(row => amountUnits(row.allocated_amount) === 0)) this.$message.warning('业务已加入，请调整各项核销金额，使合计不超过资金金额')
    },
    async validateDraft() {
      if (this.sourceLoadError) throw new Error(this.sourceLoadError)
      const valid = await new Promise(resolve => this.$refs.form.validate(resolve))
      if (!valid) return false
      if (!(amountUnits(this.doc.amount) > 0)) throw new Error('收付款金额必须大于 0')
      if (this.pendingError) throw new Error(this.pendingError)
      if (this.purchaseLinksError) throw new Error(this.purchaseLinksError)
      const purchaseError = this.$refs.purchaseLinks?.validate()
      if (purchaseError) throw new Error(purchaseError)
      return true
    },
    async persistDraft(revision, { navigate = true } = {}) {
      const creating = !this.doc.id
      const payload = {
        ...this.doc,
        draft_allocation_items: serializeAllocationItems(this.pending),
        purchase_order_allocations: serializePurchaseOrderAllocations(this.doc.purchase_order_allocations || []),
        purchase_order_allocation_reason: String(this.doc.purchase_order_allocation_reason || '').trim(),
      }
      let response
      if (creating) {
        if (!this.reservation) throw new Error('单号尚未取得，请返回列表重新打开')
        response = await createCashDocument(this.direction, { ...payload, reservation_token: this.reservation.reservation_token, creation_session_id: this.reservation.creation_session_id, idempotency_key: `${this.reservation.creation_session_id}-create` })
        clearCreatePageReservation(this.reservation)
      } else response = await updateCashDocument(this.doc.id, payload)
      if (revision !== this.initRevision) return false
      this.doc = { ...this.doc, ...response.data.data }
      if (creating && navigate) {
        this.savedNavigationId = Number(this.doc.id)
        await this.$router.replace(`${this.basePath}/${this.doc.id}`)
      }
      return true
    },
    async save() {
      if (!this.canEdit || this.saving || this.loading) return
      const revision = this.initRevision
      this.saving = true
      try {
        if (!await this.validateDraft()) return
        if (await this.persistDraft(revision)) this.$message.success('草稿已保存')
      } catch (error) { this.$message.error(error.userMessage || error.message || '保存失败') }
      finally { this.saving = false }
    },
    async confirmDoc() {
      if (!this.canConfirm || this.saving || this.loading) return
      const revision = this.initRevision
      const creating = !this.doc.id
      this.saving = true
      try {
        if (!await this.validateDraft()) return
        if (this.pending.length && !this.$can('finance.allocation.create')) throw new Error('当前账号没有核销权限，不能确认带有待核销业务的资金单')
        await this.$confirm(`确认${this.title}单后金额不可编辑${this.pending.length ? '，并同时核销所选业务' : ''}，是否继续？`, '确认资金事实', { type: 'warning' })
        if (revision !== this.initRevision) return
        if (this.canEdit && !await this.persistDraft(revision, { navigate: false })) return
        const response = await confirmCashDocument(this.doc.id, { items: serializeAllocationItems(this.pending) })
        if (revision !== this.initRevision) return
        this.doc = { ...this.doc, ...response.data.data }
        this.pending = []
        this.$message.success('已确认')
      } catch (error) {
        if (error !== 'cancel' && error !== 'close') {
          if (this.doc.id && revision === this.initRevision) {
            try {
              const current = await getCashDocument(this.doc.id)
              if (revision !== this.initRevision) return
              if (current.data.data.status === 'confirmed') {
                this.doc = { ...blank(), ...current.data.data }
                this.pending = []
                this.$message.warning('资金单已确认，已刷新实际状态，请核对核销记录')
                return
              }
            } catch (_) {}
          }
          this.$message.error(error.userMessage || error.message || '确认失败')
        }
      } finally {
        this.saving = false
        if (creating && this.doc.id && revision === this.initRevision) {
          this.savedNavigationId = Number(this.doc.id)
          await this.$router.replace(`${this.basePath}/${this.doc.id}`)
        }
      }
    },
    async allocationChanged(document) {
      if (document && Number(document.id) === Number(this.doc.id) && document.status) this.doc = { ...this.doc, ...document }
      else await this.reload()
    },
    async upload(req) {
      const id = this.doc.id
      const revision = this.initRevision
      const f = new FormData()
      f.append("file", req.file)
      try {
        await uploadFinanceAttachment(id, f)
        if (revision !== this.initRevision) return
        await this.reload()
        this.$message.success("附件已上传")
      } catch (e) {
        this.$message.error(e.userMessage || "上传失败")
      }
    },
    async preview(row) {
      const revision = this.initRevision
      try {
        const r = await previewFinanceAttachment(row.id)
        if (revision !== this.initRevision) return
        this.previewUrl = URL.createObjectURL(r.data)
        this.previewImage = String(row.mime_type || "").startsWith("image/")
        this.previewVisible = true
      } catch (e) {
        this.$message.error(e.userMessage || "预览失败")
      }
    },
    closePreview() {
      if (this.previewUrl) URL.revokeObjectURL(this.previewUrl)
      this.previewUrl = ""
      this.previewVisible = false
      this.previewImage = false
    },
    async removeAttachment(row) {
      try {
        await this.$confirm(`删除附件“${row.original_name}”？`, "删除确认", {
          type: "warning",
        })
        await deleteFinanceAttachment(row.id)
        await this.reload()
      } catch (e) {
        if (e !== "cancel") this.$message.error(e.userMessage || "删除失败")
      }
    },
    async reload() {
      const id = this.doc.id
      const revision = this.initRevision
      const r = await getCashDocument(id)
      if (revision !== this.initRevision || id !== this.doc.id) return
      const server = r.data.data
      this.doc = this.canEdit && server.status === 'draft'
        ? { ...server, ...this.doc, attachments: server.attachments || [], logs: server.logs || [] }
        : { ...blank(), ...server }
    },
    back() {
      this.$router.push(this.basePath)
    },
  },
}
</script>

<style>
.cash-allocation-dialog {
  max-width: calc(100vw - 24px);
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
  border-radius: 8px;
  overflow: hidden;
}

.cash-allocation-dialog .el-dialog__header {
  padding: 16px 20px;
  border-bottom: 1px solid #f1f5f9;
  background: #fafbfc;
}

.cash-allocation-dialog .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.cash-allocation-dialog .el-dialog__body {
  min-height: 0;
  overflow-y: auto;
  padding: 16px 20px;
}
</style>

<style scoped>
/* 容器规范：完全对齐主数据中心表单页面标准 */
.cash-form-page {
  padding: 16px 20px;
  background: #f8fafc;
  min-height: calc(100vh - 90px);
  box-sizing: border-box;
}

/* 页面全局头部：完全对齐主数据中心规范 */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  box-sizing: border-box;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.head-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  background: #f0fdf4;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}

.head-title-wrap {
  display: flex;
  flex-direction: column;
  min-width: 0;
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
  color: #0f172a;
  letter-spacing: -0.01em;
}

.head-tag {
  border-radius: 4px;
  font-weight: 500;
}

.doc-no-tag {
  border-radius: 4px;
  font-weight: 500;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  font-variant-numeric: tabular-nums;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

.btn-back,
.btn-save-draft {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-back:hover,
.btn-save-draft:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}

.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

.btn-theme-outline {
  border-color: #86efac !important;
  color: #166534 !important;
  background: #f0fdf4 !important;
  font-weight: 500;
}

.btn-theme-outline:hover {
  border-color: #008b4b !important;
  color: #00763f !important;
  background: #dcfce7 !important;
}

/* 统一页面提示条 */
.erp-page-tip {
  margin-bottom: 14px;
}

.source-error-alert {
  margin-bottom: 14px;
}

/* 主数据标准卡片架构 (form-card master-card) */
.form-card {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #ffffff;
  padding: 16px 18px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  box-sizing: border-box;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid #f1f5f9;
  flex-wrap: wrap;
  gap: 10px;
}

.header-main {
  display: flex;
  align-items: center;
  gap: 10px;
}

.card-icon-badge {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: #eaf7ef;
  color: #008b4b;
  display: grid;
  place-items: center;
  font-size: 15px;
  flex-shrink: 0;
}

.card-title {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

.card-subtitle {
  font-size: 12px;
  color: #64748b;
  margin-top: 2px;
  display: block;
}

.card-header-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* 布局栅格 */
.cash-form-top {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
  gap: 14px;
  align-items: start;
}

.cash-form-bottom {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  margin-top: 14px;
  align-items: start;
}

.purchase-links-card {
  margin-top: 14px;
}

.logs-card {
  margin-top: 14px;
}

/* 表单字段栅格 */
.fields-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px 18px;
}

.full-col {
  grid-column: 1 / -1;
}

.full-width {
  width: 100% !important;
}

.basic-form-flow >>> .el-form-item {
  margin-bottom: 10px;
}

.basic-form-flow >>> .el-form-item__label {
  font-weight: 600;
  color: #334155;
  padding-bottom: 4px !important;
  line-height: 1.3;
  font-size: 12.5px;
}

.party-radio-group {
  display: flex;
  gap: 16px;
  height: 32px;
  align-items: center;
}

.font-tabular {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  font-variant-numeric: tabular-nums;
}

.font-bold {
  font-weight: 700;
}

/* 核销总计条 */
.allocation-total-bar {
  margin-top: 12px;
  padding: 10px 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}

.total-stat-item {
  display: flex;
  align-items: center;
  gap: 8px;
}

.stat-label {
  font-size: 12.5px;
  color: #64748b;
  font-weight: 500;
}

.stat-val {
  font-size: 15px;
  color: #0f172a;
  font-weight: 700;
}

.text-danger {
  color: #dc2626 !important;
}

.text-remaining {
  color: #008b4b !important;
}

.pending-warning-alert {
  margin-top: 10px;
}

/* 采购关联卡片内部 */
.purchase-allocation-reason {
  margin-top: 14px;
}

.purchase-allocation-note {
  margin-top: 10px;
  padding: 8px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  color: #475569;
  font-size: 12.5px;
  line-height: 1.5;
  display: flex;
  align-items: center;
  gap: 6px;
}

.purchase-allocation-note i {
  color: #008b4b;
}

/* 附件区域 (对齐 Master Data Center upload placeholder) */
.upload-placeholder-zone {
  display: block;
  margin-bottom: 8px;
}

.upload-placeholder {
  width: 100%;
  padding: 16px;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  background: #f8fafc;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  cursor: pointer;
  transition: all 0.2s ease;
  box-sizing: border-box;
}

.upload-placeholder:hover {
  border-color: #008b4b;
  background: #f0fdf4;
}

.upload-placeholder i {
  font-size: 24px;
  color: #94a3b8;
  transition: color 0.2s;
}

.upload-placeholder:hover i {
  color: #008b4b;
}

.upload-title {
  font-size: 13px;
  font-weight: 600;
  color: #334155;
}

.upload-sub {
  font-size: 11px;
  color: #94a3b8;
  text-align: center;
}

.upload-placeholder.muted {
  cursor: default;
  border-style: solid;
  border-color: #e2e8f0;
  background: #f8fafc;
}

.upload-placeholder.muted:hover {
  background: #f8fafc;
  border-color: #e2e8f0;
}

.mt-8 {
  margin-top: 8px;
}

/* 金额守恒校验卡片 */
.balance-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.balance-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 12px;
  background: #f8fafc;
  border: 1px solid #f1f5f9;
  border-radius: 6px;
}

.balance-label {
  font-size: 13px;
  color: #475569;
  font-weight: 500;
}

.balance-val {
  font-size: 15px;
  color: #0f172a;
}

.balance-status-banner {
  margin-top: 6px;
  padding: 10px 14px;
  border-radius: 6px;
  font-size: 12.5px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 8px;
}

.balance-pass {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #166534;
}

.balance-pass i {
  font-size: 16px;
  color: #16a34a;
}

.balance-warning {
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #92400e;
}

.balance-warning i {
  font-size: 16px;
  color: #d97706;
}

/* 主表格标准 */
.table-wrap {
  width: 100%;
  overflow-x: auto;
}

.enterprise-table >>> th {
  background: #f8fafc;
  color: #334155;
  font-weight: 600;
  font-size: 12px;
  padding: 8px 0;
}

.enterprise-table >>> td {
  padding: 7px 0;
  font-size: 12.5px;
  color: #1e293b;
}

.btn-action-view {
  color: #008b4b !important;
}

.btn-action-void.danger {
  color: #dc2626 !important;
}

.preview-media {
  max-width: 100%;
  max-height: 75vh;
  display: block;
  margin: auto;
}

.preview-frame {
  border: 0;
  width: 100%;
  height: 75vh;
}

/* 响应式断点适配（遵照 2026-09-08 规则） */
@media (max-width: 1100px) {
  .cash-form-top {
    grid-template-columns: minmax(0, 1fr);
  }
  .cash-form-bottom {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (max-width: 768px) {
  .cash-form-page {
    padding: 12px;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
  }
  .head-actions {
    width: 100%;
    justify-content: flex-end;
    flex-wrap: wrap;
  }
  .fields-grid {
    grid-template-columns: minmax(0, 1fr);
  }
  .form-card {
    padding: 14px;
  }
}

@media (max-width: 520px) {
  .head-actions .el-button {
    flex: 1;
    min-width: 0;
  }
  .allocation-total-bar {
    flex-direction: column;
    align-items: flex-start;
  }
}

@media (max-width: 360px) {
  .cash-form-page {
    padding: 8px 6px;
  }
}
</style>
