<template>
  <div class="allocation-page" v-loading="loading">
    <div class="allocation-crumb">财务管理　/　<b>往来核销</b></div>
    <div class="allocation-heading">
      <div>
        <h1>往来核销</h1>
        <p>通过核销资金与待结算业务，完成往来账款的对冲处理</p>
      </div>
      <div>
        <el-button @click="back">返回资金单</el-button
        ><el-button
          v-if="
            doc.status === 'confirmed' &&
            remaining > 0 &&
            $can('finance.allocation.create')
          "
          class="finance-primary"
          type="success"
          :loading="saving"
          @click="submit"
          >确认核销</el-button
        >
      </div>
    </div>
    <section class="allocation-filter">
      <div>
        <b>交易对手类型</b
        ><el-radio-group v-model="partyType" disabled
          ><el-radio label="customer">客户</el-radio
          ><el-radio label="supplier">供应商</el-radio></el-radio-group
        >
      </div>
      <div>
        <b>交易对手</b><el-input :value="doc.party_name_snapshot" disabled />
      </div>
      <div><b>币种</b><el-input :value="doc.currency" disabled /></div>
      <div>
        <b>业务日期</b
        ><el-date-picker
          v-model="dateRange"
          type="daterange"
          value-format="yyyy-MM-dd"
          disabled
        />
      </div>
      <div class="balance-toggle">
        <b>仅显示有余额的项目</b><el-switch v-model="onlyBalance" />
      </div>
      <div class="filter-buttons">
        <el-button class="finance-primary" type="success" @click="openPicker"
          >查询</el-button
        ><el-button @click="resetPicker">重置</el-button>
      </div>
    </section>
    <section class="allocation-workspace">
      <article class="allocation-card">
        <h2>待核销资金</h2>
        <el-table
          :data="[doc]"
          border
          size="small"
          :row-class-name="() => 'selected-row'"
          ><el-table-column width="48" align="center"
            ><template slot-scope="{ row }"
              ><el-checkbox
                :value="true"
                disabled /></template></el-table-column
          ><el-table-column
            prop="document_no"
            label="资金单号"
            min-width="150"
          /><el-table-column label="日期" width="104"
            ><template slot-scope="{ row }">{{
              dateText(row.business_date)
            }}</template></el-table-column
          ><el-table-column label="方向" width="78"
            ><template slot-scope="{ row }">{{
              row.direction === "receipt" ? "收款" : "付款"
            }}</template></el-table-column
          ><el-table-column label="金额" width="102" align="right"
            ><template slot-scope="{ row }">{{
              money(row.amount)
            }}</template></el-table-column
          ><el-table-column label="已核销" width="90" align="right"
            ><template slot-scope="{ row }">{{
              money(row.allocated_amount)
            }}</template></el-table-column
          ><el-table-column label="可用余额" width="102" align="right"
            ><template slot-scope="{ row }">{{
              money(row.unallocated_amount)
            }}</template></el-table-column
          ></el-table
        >
        <div class="table-pager">
          共 1 条　　<span>10条/页</span>　　‹　 <b>1</b>　 ›　　前往　1　页
        </div>
      </article>
      <article class="allocation-card">
        <h2>待结算业务</h2>
        <el-table
          :data="pending"
          border
          size="small"
          empty-text="点击查询，选择待结算业务"
          ><el-table-column label="来源类型" min-width="104"
            ><template slot-scope="{ row }">{{
              label(row.source_business_type)
            }}</template></el-table-column
          ><el-table-column
            prop="source_document_no"
            label="业务单号"
            min-width="148"
          /><el-table-column
            prop="source_amount"
            label="业务金额"
            width="98"
            align="right"
          /><el-table-column label="已结算" width="84" align="right"
            ><template slot-scope="{ row }">0.00</template></el-table-column
          ><el-table-column label="待结算" width="88" align="right"
            ><template slot-scope="{ row }">{{
              money(row.source_amount)
            }}</template></el-table-column
          ><el-table-column label="本次核销" width="116"
            ><template slot-scope="{ row }"
              ><el-input
                v-model="row.allocated_amount"
                size="mini" /></template></el-table-column
          ><el-table-column label="操作" width="58"
            ><template slot-scope="{ $index }"
              ><el-button
                class="danger"
                type="text"
                @click="pending.splice($index, 1)"
                >删除</el-button
              ></template
            ></el-table-column
          ></el-table
        >
        <div class="table-pager">
          共 {{ pending.length }} 条　　<span>10条/页</span>　　‹　 <b>1</b>　
          ›　　前往　1　页
        </div>
      </article>
    </section>
    <section class="allocation-summary">
      <span
        >选中资金余额：<b>{{ money(doc.unallocated_amount) }}</b></span
      ><span
        >本次核销合计：<b>{{ money(pendingTotal) }}</b></span
      ><span
        >核销后资金余额：<em>{{ money(remainingAfterPending) }}</em></span
      ><el-button
        v-if="
          doc.status === 'confirmed' &&
          remaining > 0 &&
          $can('finance.allocation.create')
        "
        class="finance-primary"
        type="success"
        :loading="saving"
        @click="submit"
        >确认核销</el-button
      >
    </section>
    <section class="allocation-card history-card">
      <h2>核销记录</h2>
      <el-table
        :data="doc.allocations || []"
        border
        size="small"
        empty-text="暂无核销记录"
        ><el-table-column prop="document_no" label="资金单号" min-width="150"
          ><template>{{ doc.document_no }}</template></el-table-column
        ><el-table-column label="业务来源" min-width="105"
          ><template slot-scope="{ row }">{{
            label(row.source_business_type)
          }}</template></el-table-column
        ><el-table-column
          prop="source_document_no"
          label="业务单号"
          min-width="155"
        /><el-table-column
          prop="allocated_amount"
          label="核销金额"
          width="118"
          align="right"
        /><el-table-column label="核销时间" width="155"
          ><template slot-scope="{ row }">{{ dateTimeText(row.allocated_at) }}</template></el-table-column
        ><el-table-column
          prop="operator_name"
          label="操作人"
          width="90"
        /><el-table-column label="状态" width="86"
          ><template slot-scope="{ row }">{{
            row.status === "active" ? "已核销" : "已撤销"
          }}</template></el-table-column
        ><el-table-column label="操作" width="88"
          ><template slot-scope="{ row }"
            ><el-button
              v-if="
                row.status === 'active' && $can('finance.allocation.reverse')
              "
              type="text"
              @click="reverse(row)"
              >撤销核销</el-button
            ></template
          ></el-table-column
        ></el-table
      >
      <div class="table-pager">
        共
        {{ (doc.allocations || []).length }} 条　　<span>10条/页</span>　　‹　
        <b>1</b>　 ›　　前往　1　页
      </div>
    </section>
    <el-dialog
      title="选择待结算业务"
      :visible.sync="pickerVisible"
      width="850px"
      :close-on-click-modal="false"
      ><div class="picker-filter">
        <el-select v-model="sourceType"
          ><el-option
            v-for="o in sourceOptions"
            :key="o.value"
            :label="o.label"
            :value="o.value" /></el-select
        ><el-input
          v-model.trim="sourceKeyword"
          placeholder="输入业务单号搜索"
          @keyup.enter.native="searchSources"
        /><el-button
          class="finance-primary"
          type="success"
          @click="searchSources"
          >查询</el-button
        >
      </div>
      <el-table v-loading="sourceLoading" :data="sourceRows" border size="small"
        ><el-table-column
          prop="no"
          label="业务单号"
          min-width="190"
        /><el-table-column
          prop="partyName"
          label="交易对手"
          min-width="150"
        /><el-table-column
          prop="amount"
          label="业务金额"
          width="120"
        /><el-table-column
          prop="remainingAmount"
          label="待结算"
          width="120"
        /><el-table-column label="操作" width="80"
          ><template slot-scope="{ row }"
            ><el-button
              type="text"
              :disabled="Number(row.remainingAmount) <= 0"
              @click="addSource(row)"
              >选择</el-button
            ></template
          ></el-table-column
        ></el-table
      ><el-pagination
        background
        layout="total, prev, pager, next"
        :current-page="sourcePage"
        :page-size="10"
        :total="sourceTotal"
        @current-change="
          (p) => {
            sourcePage = p;
            loadSources();
          }
        "
    /></el-dialog>
  </div>
</template>
<script>
import {
  getCashDocument,
  listFinanceSources,
  allocateCashDocument,
  reverseFinanceAllocation,
} from "../../../api/erp/finance";
const uid = () =>
  window.crypto?.randomUUID?.() ||
  `${Date.now()}-${Math.random().toString(16).slice(2)}`;
export default {
  data: () => ({
    loading: false,
    saving: false,
    doc: { allocations: [] },
    partyType: "customer",
    dateRange: [],
    onlyBalance: true,
    sourceType: "",
    pending: [],
    pickerVisible: false,
    sourceKeyword: "",
    sourceRows: [],
    sourcePage: 1,
    sourceTotal: 0,
    sourceLoading: false,
  }),
  computed: {
    remaining() {
      return Number(this.doc.unallocated_amount || 0);
    },
    sourceOptions() {
      return this.doc.direction === "receipt"
        ? [
            { label: "销售订单", value: "sales_order" },
            { label: "供应商退款", value: "purchase_return_supplier_refund" },
          ]
        : [
            { label: "采购结算来源", value: "purchase_settlement_source" },
            { label: "销售退款", value: "sales_order_refund" },
          ];
    },
    pendingTotal() {
      return this.pending.reduce(
        (s, x) => s + Number(x.allocated_amount || 0),
        0
      );
    },
    remainingAfterPending() {
      return this.remaining - this.pendingTotal;
    },
  },
  created() {
    this.load();
  },
  methods: {
    money(v) {
      return Number(v || 0).toLocaleString("zh-CN", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
      });
    },
    dateText(v) {
      return String(v || "").slice(0, 10) || "—";
    },
    dateTimeText(v) {
      return String(v || "").replace("T", " ").replace(/\.\d+Z$/, "") || "—";
    },
    label(v) {
      return (
        {
          sales_order: "销售订单",
          sales_order_refund: "销售订单退款",
          purchase_receipt: "采购到货（历史）",
          purchase_settlement_source: "采购结算来源",
          purchase_return_supplier_refund: "供应商退款",
        }[v] || v
      );
    },
    async load() {
      this.loading = true;
      try {
        const r = await getCashDocument(this.$route.params.id);
        this.doc = r.data.data;
        this.partyType = this.doc.party_type;
        this.sourceType = this.sourceOptions[0]?.value || "";
      } catch (e) {
        this.$message.error(e.userMessage || "资金单加载失败");
      } finally {
        this.loading = false;
      }
    },
    resetPicker() {
      this.sourceKeyword = "";
      this.sourceRows = [];
      this.pending = [];
    },
    openPicker() {
      if (!this.sourceType) return this.$message.warning("请选择来源类型");
      this.sourcePage = 1;
      this.pickerVisible = true;
      this.loadSources();
    },
    searchSources() {
      this.sourcePage = 1;
      this.loadSources();
    },
    async loadSources() {
      this.sourceLoading = true;
      try {
        const r = await listFinanceSources({
          type: this.sourceType,
          party_id: this.doc.party_id,
          keyword: this.sourceKeyword,
          page: this.sourcePage,
          per_page: 10,
        });
        this.sourceRows = r.data.data || [];
        this.sourceTotal = Number(r.data.total || 0);
      } catch (e) {
        this.$message.error(e.userMessage || "业务来源加载失败");
      } finally {
        this.sourceLoading = false;
      }
    },
    addSource(row) {
      if (Number(row.remainingAmount) <= 0) return;
      if (
        this.pending.some(
          (x) =>
            x.source_business_type === row.type &&
            Number(x.source_document_id) === Number(row.id)
        )
      )
        return this.$message.warning("该业务来源已在本次清单中");
      this.pending.push({
        source_business_type: row.type,
        source_document_id: row.id,
        source_document_no: row.no,
        source_amount: row.amount,
        allocated_amount: String(
          Math.min(
            Number(row.remainingAmount),
            this.remainingAfterPending
          ).toFixed(4)
        ),
        idempotency_key: uid(),
      });
      this.pickerVisible = false;
    },
    async submit() {
      if (!this.pending.length)
        return this.$message.warning("请先添加待结算业务");
      if (this.pendingTotal <= 0 || this.pendingTotal > this.remaining)
        return this.$message.error("本次核销金额必须大于 0 且不得超过资金余额");
      this.saving = true;
      try {
        await allocateCashDocument(
          this.doc.id,
          this.pending.map(({ source_document_no, source_amount, ...x }) => x)
        );
        this.$message.success("核销成功");
        this.pending = [];
        await this.load();
      } catch (e) {
        this.$message.error(e.userMessage || "核销失败");
      } finally {
        this.saving = false;
      }
    },
    async reverse(row) {
      try {
        const { value } = await this.$prompt(
          "撤销不会删除历史记录，请填写原因",
          "撤销核销",
          { inputValidator: (v) => !!String(v || "").trim() || "原因必填" }
        );
        await reverseFinanceAllocation(row.id, value);
        this.$message.success("核销已撤销");
        await this.load();
      } catch (e) {
        if (e !== "cancel") this.$message.error(e.userMessage || "撤销失败");
      }
    },
    back() {
      const listPath = this.doc.direction === "receipt" ? "/finance/receipts" : "/finance/payments";
      const origin = this.$route.query.from;
      if (origin === "cash-list") return this.$router.push(listPath);
      if (origin === "allocation-list") return this.$router.push("/finance/allocations");
      this.$router.push(`${listPath}/${this.doc.id}`);
    },
  },
};
</script>
<style scoped>
.allocation-page {
  padding: 18px 22px 32px;
  background: #f7f9fb;
  min-height: calc(100vh - 64px);
  box-sizing: border-box;
}
.allocation-crumb {
  font-size: 14px;
  color: #6b7789;
  margin-bottom: 13px;
}
.allocation-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}
.allocation-heading h1 {
  font-size: 27px;
  margin: 0 0 7px;
  color: #182231;
}
.allocation-heading p {
  font-size: 14px;
  color: #6c7889;
  margin: 0;
}
.finance-primary {
  background: #008c4a;
  border-color: #008c4a;
}
.allocation-filter,
.allocation-card,
.allocation-summary {
  background: #fff;
  border: 1px solid #dfe5ec;
  border-radius: 6px;
  box-shadow: 0 1px 4px rgba(16, 24, 40, 0.035);
}
.allocation-filter {
  display: grid;
  grid-template-columns: 1.05fr 1.3fr 0.8fr 1.2fr 1.15fr auto;
  gap: 18px;
  align-items: end;
  padding: 16px 18px;
  margin-bottom: 10px;
}
.allocation-filter b {
  font-size: 13px;
  display: block;
  margin-bottom: 9px;
  color: #3b4654;
}
.allocation-filter .el-input,
.allocation-filter .el-date-editor {
  width: 100%;
}
.balance-toggle .el-switch {
  margin-top: 8px;
}
.filter-buttons {
  display: flex;
  gap: 10px;
}
.allocation-workspace {
  display: grid;
  grid-template-columns: 1fr 1.12fr;
  gap: 10px;
}
.allocation-card {
  overflow: hidden;
}
.allocation-card h2 {
  font-size: 16px;
  margin: 0;
  padding: 13px 16px;
  border-bottom: 1px solid #dfe5ec;
}
.allocation-card >>> .el-table th {
  height: 41px;
  background: #f7f9fb;
  color: #313b4b;
}
.allocation-card >>> .el-table td {
  height: 42px;
}
.selected-row td {
  background: #eefaf4 !important;
}
.table-pager {
  padding: 13px 18px;
  color: #5c697b;
  font-size: 13px;
}
.table-pager span,
.table-pager b {
  border: 1px solid #dce4ec;
  border-radius: 3px;
  padding: 5px 10px;
  margin: 0 3px;
}
.table-pager b {
  background: #008c4a;
  color: #fff;
  border-color: #008c4a;
}
.allocation-summary {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr auto;
  gap: 24px;
  align-items: center;
  margin: 10px 0;
  padding: 12px 18px;
  font-size: 15px;
}
.allocation-summary b {
  color: #008c4a;
  font-size: 17px;
}
.allocation-summary em {
  color: #ef4444;
  font-style: normal;
  font-weight: 700;
  font-size: 17px;
}
.history-card {
  margin-top: 10px;
}
.picker-filter {
  display: grid;
  grid-template-columns: 170px 1fr auto;
  gap: 10px;
  margin-bottom: 12px;
}
.danger {
  color: #ef4444;
}
@media (max-width: 1180px) {
  .allocation-filter {
    grid-template-columns: repeat(3, 1fr);
  }
  .allocation-workspace {
    grid-template-columns: 1fr;
  }
  .allocation-summary {
    grid-template-columns: 1fr 1fr;
  }
}
@media (max-width: 720px) {
  .allocation-page {
    padding: 16px 12px;
  }
  .allocation-heading {
    align-items: flex-start;
  }
  .allocation-heading h1 {
    font-size: 23px;
  }
  .allocation-filter {
    grid-template-columns: 1fr;
  }
  .allocation-workspace,
  .allocation-card {
    overflow-x: auto;
  }
  .allocation-card >>> .el-table {
    min-width: 650px;
  }
  .allocation-summary {
    grid-template-columns: 1fr;
    gap: 10px;
  }
  .picker-filter {
    grid-template-columns: 1fr;
  }
  .allocation-heading > div:last-child {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
  }
}
</style>
