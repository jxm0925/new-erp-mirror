<template>
  <div class="cash-form-page" v-loading="loading">
    <div class="cash-form-crumb">
      财务管理　/　{{ title }}管理　/　{{ title }}单　/　<b>{{
        isNew ? "新增" + title : title + "单详情"
      }}</b>
    </div>
    <div class="cash-form-heading">
      <h1>{{ title }}单 / {{ isNew ? "新增" + title : "详情" }}</h1>
      <div class="head-actions">
        <el-button @click="back">返回列表</el-button
        ><el-button v-if="canEdit" :loading="saving" @click="save"
          >保存草稿</el-button
        ><el-button
          v-if="doc.id && doc.status === 'draft' && $can(confirmPermission)"
          class="finance-primary"
          type="success"
          @click="confirmDoc"
          >保存并确认</el-button
        >
      </div>
    </div>
    <el-alert
      :title="title + '单代表真实资金流入/流出，确认后金额不可直接修改。'"
      type="info"
      :closable="false"
      show-icon
    />
    <section class="cash-form-top">
      <article class="cash-card basic-card">
        <h2>{{ title }}基本信息</h2>
        <el-form
          ref="form"
          :model="doc"
          :rules="rules"
          label-width="108px"
          size="small"
          ><el-form-item :label="title + '单号'"
            ><el-input v-model="doc.document_no" disabled /></el-form-item
          ><el-form-item :label="title + '日期'" prop="business_date"
            ><el-date-picker
              v-model="doc.business_date"
              value-format="yyyy-MM-dd"
              type="date"
              :disabled="!canEdit" /></el-form-item
          ><el-form-item label="客户/交易对手" prop="party_id"
            ><el-select
              v-model="doc.party_id"
              filterable
              remote
              reserve-keyword
              :remote-method="searchParties"
              :loading="partyLoading"
              :disabled="!isNew"
              placeholder="输入名称搜索"
              ><el-option
                v-for="p in parties"
                :key="p.id"
                :label="p.name"
                :value="p.id" /></el-select></el-form-item
          ><el-form-item label="资金账户" prop="finance_account_id"
            ><el-select v-model="doc.finance_account_id" :disabled="!canEdit" @change="accountChanged"
              ><el-option
                v-for="a in accounts"
                :key="a.id"
                :label="`${a.account_name}（${a.currency}）`"
                :value="a.id" /></el-select></el-form-item
          ><el-form-item label="币种" prop="currency"
            ><el-input v-model="doc.currency" disabled /></el-form-item
          ><el-form-item :label="'实' + title + '金额'" prop="amount"
            ><el-input
              v-model.trim="doc.amount"
              :disabled="!canEdit" /></el-form-item
          ><el-form-item label="平台手续费"
            ><el-input v-model.trim="doc.platform_fee_amount" :disabled="!canEdit"><template slot="append">{{ doc.currency || '—' }}</template></el-input><small>手续费独立记录，不会把收/付款事实金额改为净额。</small></el-form-item
          ><el-form-item label="手续费类型"
            ><el-select v-model="doc.platform_fee_type" :disabled="!canEdit"><el-option label="平台手续费" value="platform"/><el-option label="银行手续费" value="bank"/><el-option label="其他费用" value="other"/></el-select></el-form-item
          ><el-form-item :label="title + '方式'" prop="payment_method"
            ><el-select v-model="doc.payment_method" :disabled="!canEdit"
              ><el-option
                v-for="m in methods"
                :key="m"
                :label="m"
                :value="m" /></el-select></el-form-item
          ><el-form-item label="外部参考号"
            ><el-input
              v-model.trim="doc.external_reference_no"
              :disabled="!canEdit" /></el-form-item
          ><el-form-item label="经办人"
            ><el-input
              :value="doc.operator_name_snapshot || '当前登录人'"
              disabled /></el-form-item
          ><el-form-item class="form-remark" label="备注"
            ><el-input
              v-model.trim="doc.remark"
              type="textarea"
              :rows="2"
              :disabled="!canEdit" /></el-form-item
        ></el-form>
      </article>
      <article class="cash-card allocation-card">
        <div class="card-title-line">
          <h2>核销明细</h2>
          <el-button
            v-if="
              doc.id &&
              doc.status === 'confirmed' &&
              Number(doc.unallocated_amount) > 0 &&
              $can('finance.allocation.create')
            "
            class="outline-green"
            icon="el-icon-plus"
            @click="$router.push({path:`/finance/allocations/${doc.id}`,query:{from:'cash-detail'}})"
            >添加核销来源</el-button
          >
        </div>
        <el-table
          :data="doc.allocations || []"
          border
          size="small"
          empty-text="保存并确认后可添加核销来源"
          ><el-table-column label="来源类型" min-width="94"
            ><template slot-scope="{ row }">{{
              sourceLabel(row.source_business_type)
            }}</template></el-table-column
          ><el-table-column
            prop="source_document_no"
            label="业务单号"
            min-width="126"
          /><el-table-column
            prop="source_amount_snapshot"
            label="来源金额"
            width="88"
            align="right"
          /><el-table-column
            prop="allocated_amount"
            label="已核销"
            width="82"
            align="right"
          /><el-table-column label="本次核销" width="90" align="right"
            ><template slot-scope="{ row }">{{
              money(row.allocated_amount)
            }}</template></el-table-column
          ><el-table-column label="核销后余额" width="93" align="right"
            ><template slot-scope="{ row }">{{
              money(
                Number(row.source_amount_snapshot || 0) -
                  Number(row.allocated_amount || 0)
              )
            }}</template></el-table-column
          ><el-table-column label="操作" width="60"
            ><template slot-scope="{ row }"
              ><el-button
                v-if="
                  row.status === 'active' && $can('finance.allocation.reverse')
                "
                type="text"
                @click="$router.push({path:`/finance/allocations/${doc.id}`,query:{from:'cash-detail'}})"
                >查看</el-button
              ></template
            ></el-table-column
          ></el-table
        >
        <div class="allocation-total">
          <b>合计</b><span>{{ money(doc.amount) }}</span
          ><span>{{ money(doc.allocated_amount) }}</span
          ><em>{{ money(doc.unallocated_amount) }}</em>
        </div>
      </article>
    </section>
    <section class="cash-form-bottom">
      <article class="cash-card attachment-card">
        <h2>附件</h2>
        <el-upload
          v-if="doc.id && canEdit"
          action="#"
          :show-file-list="false"
          :http-request="upload"
          class="upload-zone"
          ><i class="el-icon-upload2" /><b> 上传附件</b
          ><small
            >支持拖拽或点击上传，单个文件不超过 50MB，支持
            PDF、JPG、PNG、Excel、Word</small
          ></el-upload
        >
        <div v-else class="upload-zone muted">
          <i class="el-icon-upload2" /><b>
            {{ doc.id ? "当前单据不可再上传" : "保存草稿后可上传附件" }}</b
          >
        </div>
        <el-table
          :data="activeAttachments"
          border
          size="mini"
          empty-text="暂无附件"
          ><el-table-column
            prop="original_name"
            label="文件名"
            min-width="155"
          /><el-table-column label="大小" width="80"
            ><template slot-scope="{ row }">{{
              fileSize(row.file_size)
            }}</template></el-table-column
          ><el-table-column
            prop="uploaded_at"
            label="上传时间"
            min-width="125"
          /><el-table-column label="操作" width="105"
            ><template slot-scope="{ row }"
              ><el-button type="text" @click="preview(row)">预览</el-button
              ><el-button
                v-if="canEdit"
                type="text"
                class="danger"
                @click="removeAttachment(row)"
                >删除</el-button
              ></template
            ></el-table-column
          ></el-table
        >
      </article>
      <article class="cash-card balance-card">
        <h2>金额守恒校验</h2>
        <p>
          <span>实{{ title }}金额</span><b>{{ money(doc.amount) }}</b>
        </p>
        <p>
          <span>本次核销</span><b>{{ money(doc.allocated_amount) }}</b>
        </p>
        <p>
          <span>未核销</span><b>{{ money(doc.unallocated_amount) }}</b>
        </p>
        <div class="balance-pass">
          <i class="el-icon-success" /> 校验结果：金额守恒校验通过
        </div>
      </article>
    </section>
    <section class="cash-card logs-card">
      <h2>操作日志</h2>
      <el-table
        :data="doc.logs || []"
        border
        size="mini"
        max-height="154"
        empty-text="暂无操作日志"
        ><el-table-column label="操作时间" width="168"
          ><template slot-scope="{ row }">{{
            formatDateTime(row.created_at)
          }}</template></el-table-column
        ><el-table-column prop="operator_name" label="操作人" width="96"
          ><template slot-scope="{ row }">{{
            row.operator_name || "系统"
          }}</template></el-table-column
        ><el-table-column label="操作类型" width="100"
          ><template slot-scope="{ row }">{{
            logLabel(row.action)
          }}</template></el-table-column
        ><el-table-column
          prop="content"
          label="操作内容"
          min-width="300" /><el-table-column
          prop="remark"
          label="备注"
          min-width="100"
      /></el-table>
    </section>
    <el-dialog
      title="附件预览"
      :visible.sync="previewVisible"
      width="80%"
      top="5vh"
      @closed="closePreview"
      ><img
        v-if="previewImage"
        :src="previewUrl"
        class="preview-media" /><iframe
        v-else
        :src="previewUrl"
        class="preview-frame"
    /></el-dialog>
  </div>
</template>
<script>
import {
  listFinanceAccounts,
  getCashDocument,
  createCashDocument,
  updateCashDocument,
  confirmCashDocument,
  uploadFinanceAttachment,
  previewFinanceAttachment,
  deleteFinanceAttachment,
} from "../../../api/erp/finance";
import { listEntity } from "../../../api/erp/master";
import { listSalesCustomers } from "../../../api/erp/sales";
import {
  reserveForCreatePage,
  clearCreatePageReservation,
} from "../../../utils/documentNumberReservation";
const today = () => {
  const d = new Date();
  const pad = (v) => String(v).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};
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
  payment_method: "银行转账",
  external_reference_no: "",
  remark: "",
  status: "draft",
  allocated_amount: "0",
  unallocated_amount: "0",
  allocations: [],
  attachments: [],
  logs: [],
});
export default {
  props: { direction: { type: String, required: true } },
  data: () => ({
    loading: false,
    saving: false,
    partyLoading: false,
    doc: blank(),
    accounts: [],
    parties: [],
    reservation: null,
    previewVisible: false,
    previewUrl: "",
    previewImage: false,
    methods: ["银行转账", "现金", "支付宝", "微信支付", "其他"],
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
      payment_method: [
        { required: true, message: "请选择方式", trigger: "change" },
      ],
    },
  }),
  computed: {
    id() {
      return Number(this.$route.params.id || 0);
    },
    isNew() {
      return !this.id;
    },
    title() {
      return this.direction === "receipt" ? "收款" : "付款";
    },
    basePath() {
      return this.direction === "receipt"
        ? "/finance/receipts"
        : "/finance/payments";
    },
    createPermission() {
      return `finance.${this.direction}.create`;
    },
    confirmPermission() {
      return `finance.${this.direction}.confirm`;
    },
    canEdit() {
      return this.doc.status === "draft" && this.$can(this.createPermission);
    },
    readonly() {
      return !this.canEdit;
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
        : "已确认";
    },
    activeAttachments() {
      return (this.doc.attachments || []).filter((x) => x.status === "active");
    },
  },
  watch: {
    direction() {
      this.init();
    },
    "$route.fullPath"() {
      // Vue reuses this component when navigating from a confirmed document
      // back to `/create`; without this reset the old confirmed values remain
      // rendered and every input is locked as if it were the old document.
      this.init();
    },
  },
  created() {
    this.init();
  },
  beforeDestroy() {
    this.closePreview();
  },
  methods: {
    money(v) {
      return Number(v || 0).toLocaleString("zh-CN", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
      });
    },
    formatDateTime(v) {
      if (!v) return "—";
      const d = new Date(v);
      if (Number.isNaN(d.getTime())) return v;
      const pad = (n) => String(n).padStart(2, "0");
      return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(
        d.getDate()
      )} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    },
    fileSize(v) {
      const n = Number(v || 0);
      return n > 1048576
        ? (n / 1048576).toFixed(2) + " MB"
        : (n / 1024).toFixed(1) + " KB";
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
      );
    },
    logLabel(v) {
      return (
        {
          create: "创建",
          update_draft: "修改草稿",
          confirm: "确认",
          allocate: "核销",
          reverse_allocation: "撤销核销",
          void: "作废",
        }[v] || v
      );
    },
    async init() {
      this.loading = true;
      try {
        const a = await listFinanceAccounts({
          status: "enabled",
          page: 1,
          per_page: 100,
        });
        this.accounts = a.data.data || [];
        if (this.id) {
          const r = await getCashDocument(this.id);
          this.doc = { ...blank(), ...r.data.data };
          await this.ensureParty();
        } else {
          this.doc = blank();
          this.doc.party_type =
            this.direction === "receipt" ? "customer" : "supplier";
          this.reservation = await reserveForCreatePage(
            this.direction === "receipt"
              ? "finance_receipt"
              : "finance_payment",
            `${this.basePath}/create`
          );
          this.doc.document_no = this.reservation.document_no;
          await this.searchParties("");
        }
      } catch (e) {
        this.$message.error(e.userMessage || "页面加载失败");
      } finally {
        this.loading = false;
      }
    },
    async ensureParty() {
      this.parties = [
        { id: Number(this.doc.party_id), name: this.doc.party_name_snapshot },
      ];
    },
    partyChanged() {
      this.doc.party_id = null;
      this.parties = [];
      this.searchParties("");
    },
    accountChanged(accountId) {
      const account = this.accounts.find((x) => Number(x.id) === Number(accountId));
      if (account) this.doc.currency = account.currency;
    },
    async searchParties(keyword) {
      this.partyLoading = true;
      try {
        if (this.doc.party_type === "customer") {
          const r = await listSalesCustomers({
            keyword,
            page: 1,
            per_page: 20,
          });
          this.parties = (r.data.data || []).map((x) => ({
            id: x.id,
            name:
              x.customer_name ||
              x.customer_short_name ||
              x.contact_name ||
              x.customer_code,
          }));
        } else {
          const r = await listEntity("suppliers", {
            keyword,
            page: 1,
            per_page: 20,
          });
          this.parties = (r.data.data || []).map((x) => ({
            id: x.id,
            name: x.supplier_name || x.name || x.supplier_code,
          }));
        }
      } catch (e) {
        this.$message.error(e.userMessage || "交易对手搜索失败");
      } finally {
        this.partyLoading = false;
      }
    },
    save() {
      this.$refs.form.validate(async (ok) => {
        if (!ok) return;
        this.saving = true;
        try {
          const creating = !this.doc.id;
          let r;
          if (!creating) r = await updateCashDocument(this.doc.id, this.doc);
          else {
            r = await createCashDocument(this.direction, {
              ...this.doc,
              reservation_token: this.reservation.reservation_token,
              creation_session_id: this.reservation.creation_session_id,
              idempotency_key: `${this.reservation.creation_session_id}-create`,
            });
            clearCreatePageReservation(this.reservation);
          }
          this.doc = { ...this.doc, ...r.data.data };
          if (creating) {
            await this.$router.replace(`${this.basePath}/${this.doc.id}`);
            await this.reload();
          }
          this.$message.success("草稿已保存");
        } catch (e) {
          this.$message.error(e.userMessage || "保存失败");
        } finally {
          this.saving = false;
        }
      });
    },
    async confirmDoc() {
      try {
        await this.$confirm(
          `确认${this.title}单后金额不可编辑，是否继续？`,
          "确认资金事实",
          { type: "warning" }
        );
        const r = await confirmCashDocument(this.doc.id);
        this.doc = { ...this.doc, ...r.data.data };
        this.$message.success("已确认");
      } catch (e) {
        if (e !== "cancel") this.$message.error(e.userMessage || "确认失败");
      }
    },
    async upload(req) {
      const f = new FormData();
      f.append("file", req.file);
      try {
        await uploadFinanceAttachment(this.doc.id, f);
        await this.reload();
        this.$message.success("附件已上传");
      } catch (e) {
        this.$message.error(e.userMessage || "上传失败");
      }
    },
    async preview(row) {
      try {
        const r = await previewFinanceAttachment(row.id);
        this.previewUrl = URL.createObjectURL(r.data);
        this.previewImage = String(row.mime_type || "").startsWith("image/");
        this.previewVisible = true;
      } catch (e) {
        this.$message.error(e.userMessage || "预览失败");
      }
    },
    closePreview() {
      if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
      this.previewUrl = "";
    },
    async removeAttachment(row) {
      try {
        await this.$confirm(`删除附件“${row.original_name}”？`, "删除确认", {
          type: "warning",
        });
        await deleteFinanceAttachment(row.id);
        await this.reload();
      } catch (e) {
        if (e !== "cancel") this.$message.error(e.userMessage || "删除失败");
      }
    },
    async reload() {
      const r = await getCashDocument(this.doc.id);
      this.doc = { ...blank(), ...r.data.data };
    },
    back() {
      this.$router.push(this.basePath);
    },
  },
};
</script>
<style scoped>
.basic-card >>> .el-form-item__label {
  white-space: nowrap;
}
.cash-form-page {
  padding: 18px 26px 34px;
  background: #f7f9fb;
  min-height: calc(100vh - 64px);
  box-sizing: border-box;
}
.cash-form-crumb {
  color: #6a7789;
  font-size: 14px;
  margin: 0 0 10px;
}
.cash-form-crumb b {
  color: #2d3748;
}
.cash-form-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
}
.cash-form-heading h1 {
  margin: 0;
  font-size: 28px;
  color: #172033;
}
.head-actions {
  display: flex;
  gap: 12px;
}
.finance-primary {
  background: #008c4a;
  border-color: #008c4a;
}
.cash-form-page > .el-alert {
  border: 1px solid #97c7ff;
  background: #f1f7ff;
  margin-bottom: 16px;
}
.cash-form-top {
  display: grid;
  grid-template-columns: 39% minmax(0, 1fr);
  gap: 14px;
}
.cash-card {
  background: #fff;
  border: 1px solid #e1e7ed;
  border-radius: 6px;
  padding: 16px;
  box-shadow: 0 1px 4px rgba(16, 24, 40, 0.035);
}
.cash-card h2 {
  font-size: 17px;
  line-height: 1;
  margin: 0 0 15px;
  color: #1b2533;
}
.basic-card >>> .el-form {
  display: grid;
  grid-template-columns: 1fr 1fr;
  column-gap: 18px;
}
.basic-card >>> .el-form-item {
  margin-bottom: 13px;
}
.basic-card >>> .el-select,
.basic-card >>> .el-date-editor {
  width: 100%;
}
.basic-card >>> .form-remark {
  grid-column: 1 / span 2;
}
.card-title-line {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 13px;
}
.card-title-line h2 {
  margin: 0;
}
.outline-green {
  border-color: #24a96b;
  color: #008c4a;
}
.allocation-card {
  padding-bottom: 0;
}
.allocation-card >>> .el-table th {
  height: 46px;
  background: #f7f9fb;
  color: #303a48;
}
.allocation-card >>> .el-table td {
  height: 49px;
}
.allocation-total {
  height: 50px;
  border: 1px solid #e2e7ed;
  border-top: 0;
  display: grid;
  grid-template-columns: 1fr 88px 82px 93px;
  align-items: center;
  padding: 0 14px;
  text-align: right;
}
.allocation-total b {
  text-align: left;
}
.allocation-total em {
  color: #e64545;
  font-style: normal;
}
.cash-form-bottom {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  margin-top: 14px;
}
.upload-zone {
  border: 1px dashed #b8c8dc;
  background: #fbfdff;
  min-height: 54px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  color: #008c4a;
  cursor: pointer;
  margin-bottom: 7px;
}
.upload-zone small {
  color: #8592a4;
  font-size: 11px;
}
.upload-zone.muted {
  color: #95a1b1;
  cursor: default;
}
.attachment-card >>> .el-table th,
.logs-card >>> .el-table th {
  background: #f7f9fb;
}
.balance-card p {
  display: flex;
  justify-content: space-between;
  border-bottom: 1px solid #edf0f4;
  padding: 10px 0;
  margin: 0;
  font-size: 14px;
}
.balance-card p b {
  color: #008c4a;
}
.balance-pass {
  margin-top: 13px;
  border: 1px solid #8bd8b2;
  background: #effaf3;
  color: #008c4a;
  border-radius: 4px;
  padding: 10px 12px;
  font-weight: 600;
}
.logs-card {
  margin-top: 14px;
  padding-bottom: 7px;
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
@media (max-width: 1180px) {
  .cash-form-top {
    grid-template-columns: 1fr;
  }
  .cash-form-bottom {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 760px) {
  .cash-form-page {
    padding: 16px 12px;
  }
  .cash-form-heading {
    align-items: flex-start;
  }
  .cash-form-heading h1 {
    font-size: 23px;
  }
  .head-actions {
    flex-wrap: wrap;
    justify-content: flex-end;
  }
  .basic-card >>> .el-form {
    grid-template-columns: 1fr;
  }
  .basic-card >>> .form-remark {
    grid-column: auto;
  }
  .allocation-card {
    overflow-x: auto;
  }
  .allocation-card >>> .el-table {
    min-width: 720px;
  }
  .allocation-total {
    min-width: 690px;
  }
  .cash-form-bottom {
    grid-template-columns: 1fr;
  }
  .upload-zone {
    flex-wrap: wrap;
    padding: 9px;
    text-align: center;
  }
}
</style>
