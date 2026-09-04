<template>
  <main class="transfer-list-page">
    <header class="page-head">
      <div>
        <h1>资金转账 / 换汇记录</h1>
        <p>查看同币种转账和跨币种换汇的历史事实；已确认单据只读，草稿可继续编辑。</p>
      </div>
      <div class="head-actions">
        <el-button class="rate-btn" icon="el-icon-data-line" @click="$router.push('/finance/exchange-rates')">汇率历史</el-button>
        <el-button v-if="$can('finance.payment.create')" class="primary-btn" type="success" icon="el-icon-plus" @click="$router.push('/finance/transfers/create')">新建转账 / 换汇</el-button>
      </div>
    </header>

    <section class="info-band"><i class="el-icon-info" /> 本页面列表数据均来源于新系统的财务记录，不包含旧系统或外部系统的历史数据，且不会与旧系统进行数据同步。</section>

    <section class="filter-card">
      <div class="filter-grid">
        <label>业务日期
          <el-date-picker v-model="dateRange" type="daterange" value-format="yyyy-MM-dd" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期" @change="syncDateRange" />
        </label>
        <label>转出账户
          <el-select v-model="query.source_account_id" clearable filterable placeholder="请选择转出账户"><el-option v-for="a in accounts" :key="`s-${a.id}`" :label="`${a.account_name} · ${a.currency}`" :value="a.id" /></el-select>
        </label>
        <label>转入账户
          <el-select v-model="query.target_account_id" clearable filterable placeholder="请选择转入账户"><el-option v-for="a in accounts" :key="`t-${a.id}`" :label="`${a.account_name} · ${a.currency}`" :value="a.id" /></el-select>
        </label>
        <label>源币
          <el-select v-model="query.source_currency" clearable placeholder="请选择源币"><el-option v-for="c in currencies" :key="`sc-${c}`" :label="c" :value="c" /></el-select>
        </label>
        <label>目标币
          <el-select v-model="query.target_currency" clearable placeholder="请选择目标币"><el-option v-for="c in currencies" :key="`tc-${c}`" :label="c" :value="c" /></el-select>
        </label>
        <label>单据状态
          <el-select v-model="query.status" clearable placeholder="请选择状态"><el-option label="草稿" value="draft" /><el-option label="已确认" value="confirmed" /><el-option label="已作废" value="voided" /></el-select>
        </label>
        <label class="keyword-filter">关键词
          <el-input v-model.trim="query.keyword" clearable placeholder="单据编号 / 账户名称 / 备注" suffix-icon="el-icon-search" @keyup.enter.native="search" />
        </label>
        <div class="filter-actions"><el-button class="primary-btn" type="success" @click="search">查询</el-button><el-button @click="reset">重置</el-button></div>
      </div>
    </section>

    <section class="summary-row">
      <article class="summary-card all"><i class="el-icon-document-copy" /><div><span>全部记录</span><strong>{{ summary.all }}</strong></div></article>
      <article class="summary-card draft"><i class="el-icon-edit-outline" /><div><span>草稿</span><strong>{{ summary.draft }}</strong></div></article>
      <article class="summary-card confirmed"><i class="el-icon-circle-check" /><div><span>已确认</span><strong>{{ summary.confirmed }}</strong></div></article>
      <article class="summary-card voided"><i class="el-icon-circle-close" /><div><span>已作废</span><strong>{{ summary.voided }}</strong></div></article>
    </section>

    <section class="table-card">
      <el-table v-loading="loading" :data="rows" class="transfer-table" border size="small" empty-text="暂无资金转账/换汇记录">
        <el-table-column prop="transfer_no" label="单据编号" min-width="162"><template slot-scope="{row}"><el-link type="primary" @click="open(row)">{{ row.transfer_no }}</el-link></template></el-table-column>
        <el-table-column label="业务类型" width="108"><template slot-scope="{row}">{{ typeLabel(row) }}</template></el-table-column>
        <el-table-column label="业务日期" width="110"><template slot-scope="{row}">{{ row.business_date || '—' }}</template></el-table-column>
        <el-table-column label="转出账户" min-width="158"><template slot-scope="{row}">{{ accountLabel(row.source_account, row.source_currency) }}</template></el-table-column>
        <el-table-column label="转出金额" min-width="126" align="right"><template slot-scope="{row}">{{ amount(row.source_amount, row.source_currency) }}</template></el-table-column>
        <el-table-column label="转入账户" min-width="158"><template slot-scope="{row}">{{ accountLabel(row.target_account, row.target_currency) }}</template></el-table-column>
        <el-table-column label="实际到账（毛额）" min-width="142" align="right"><template slot-scope="{row}">{{ amount(row.gross_target_amount || row.target_amount, row.target_currency) }}</template></el-table-column>
        <el-table-column label="手续费" min-width="114" align="right"><template slot-scope="{row}">{{ Number(row.fee_amount || 0) ? amount(row.fee_amount, row.fee_currency) : '—' }}</template></el-table-column>
        <el-table-column label="已实现汇兑损益" min-width="138" align="right"><template slot-scope="{row}"><span :class="fxClass(row)">{{ fxAmount(row) }}</span></template></el-table-column>
        <el-table-column label="状态" width="92" align="center"><template slot-scope="{row}"><el-tag size="mini" :type="statusType(row.status)">{{ statusLabel(row.status) }}</el-tag></template></el-table-column>
        <el-table-column label="操作" width="138" fixed="right"><template slot-scope="{row}"><el-button type="text" @click="open(row)">查看详情</el-button><el-button v-if="row.status==='draft' && $can('finance.payment.create')" type="text" @click="open(row)">继续编辑</el-button></template></el-table-column>
      </el-table>
      <el-pagination background layout="total, sizes, prev, pager, next, jumper" :total="total" :current-page="page" :page-size="perPage" :page-sizes="[10,20,50,100]" @current-change="changePage" @size-change="changeSize" />
    </section>
  </main>
</template>

<script>
import { listFinanceAccounts, listFinanceTransfers } from '../../../api/erp/finance'

const blankQuery = () => ({ source_account_id: '', target_account_id: '', source_currency: '', target_currency: '', status: '', keyword: '', business_date_start: '', business_date_end: '' })

export default {
  data: () => ({ loading: false, accounts: [], rows: [], currencies: [], dateRange: [], query: blankQuery(), page: 1, perPage: 20, total: 0, summary: { all: 0, draft: 0, confirmed: 0, voided: 0 } }),
  created () { this.bootstrap() },
  methods: {
    async bootstrap () {
      try {
        const accountResult = await listFinanceAccounts({ page: 1, per_page: 100 })
        this.accounts = accountResult.data.data || []
        this.currencies = [...new Set(this.accounts.map(x => x.currency).filter(Boolean))]
      } catch (e) { this.$message.error(e.userMessage || '资金账户加载失败') }
      this.load()
    },
    async load () {
      this.loading = true
      try {
        const result = await listFinanceTransfers({ ...this.query, page: this.page, per_page: this.perPage })
        this.rows = result.data.data || []
        this.total = Number(result.data.total || 0)
        this.summary = { ...this.summary, ...(result.data.summary || {}) }
      } catch (e) { this.$message.error(e.userMessage || '资金转账/换汇记录加载失败') } finally { this.loading = false }
    },
    syncDateRange () { this.query.business_date_start = this.dateRange?.[0] || ''; this.query.business_date_end = this.dateRange?.[1] || '' },
    search () { this.page = 1; this.load() },
    reset () { this.dateRange = []; this.query = blankQuery(); this.search() },
    changePage (page) { this.page = page; this.load() },
    changeSize (size) { this.perPage = size; this.page = 1; this.load() },
    open (row) { this.$router.push(`/finance/transfers/${row.id}`) },
    typeLabel (row) { return row.source_currency === row.target_currency ? '同币种转账' : '跨币种换汇' },
    accountLabel (account, currency) { return account ? `${account.account_name} (${currency})` : `— (${currency || '—'})` },
    amount (value, currency) { return `${Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 4 })} ${currency || ''}`.trim() },
    fxAmount (row) { const value = Number(row.realized_fx_gain_loss || 0); return `${value > 0 ? '+' : ''}${this.amount(value, row.base_currency || 'CNY')}` },
    fxClass (row) { const value = Number(row.realized_fx_gain_loss || 0); return value > 0 ? 'fx-up' : value < 0 ? 'fx-down' : 'fx-flat' },
    statusLabel (status) { return ({ draft: '草稿', confirmed: '已确认', voided: '已作废' })[status] || status || '—' },
    statusType (status) { return ({ draft: 'info', confirmed: 'success', voided: 'danger' })[status] || 'info' }
  }
}
</script>

<style scoped>
.transfer-list-page{min-height:calc(100vh - 64px);padding:28px 24px 36px;background:#f6f8fb;color:#1e293b;box-sizing:border-box}.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin-bottom:18px}.page-head h1{margin:0 0 8px;font-size:27px;line-height:34px;font-weight:700;color:#111827}.page-head p{margin:0;color:#657286;font-size:14px;line-height:22px}.head-actions{display:flex;gap:14px;flex:none}.primary-btn{background:#009a55;border-color:#009a55;box-shadow:0 2px 5px rgba(0,154,85,.18)}.primary-btn:hover,.primary-btn:focus{background:#008849;border-color:#008849}.rate-btn{color:#078a50;border-color:#13a966;background:#fff}.info-band{height:48px;display:flex;align-items:center;box-sizing:border-box;margin-bottom:13px;padding:0 17px;border:1px solid #a8ccff;border-radius:5px;background:#f4f9ff;color:#42546e;font-size:14px}.info-band i{margin-right:9px;color:#1779e6;font-size:17px}.filter-card,.table-card{background:#fff;border:1px solid #e0e6ed;border-radius:6px;box-shadow:0 2px 7px rgba(25,44,78,.035)}.filter-card{padding:20px 20px 19px;margin-bottom:14px}.filter-grid{display:grid;grid-template-columns:1.2fr 1.15fr 1.15fr 1fr 1fr;gap:17px 30px}.filter-grid label{display:block;color:#344054;font-size:14px;font-weight:600;line-height:20px}.filter-grid .el-input,.filter-grid .el-select,.filter-grid .el-date-editor{display:block;width:100%;margin-top:7px}.keyword-filter{grid-column:span 2}.filter-actions{display:flex;align-items:flex-end;justify-content:flex-end;gap:14px}.filter-actions .el-button{min-width:88px}.summary-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px}.summary-card{height:102px;display:flex;align-items:center;gap:18px;padding:0 25px;background:#fff;border:1px solid #e2e7ef;border-radius:6px;box-sizing:border-box;box-shadow:0 2px 7px rgba(25,44,78,.03)}.summary-card>i{display:flex;align-items:center;justify-content:center;width:57px;height:57px;border-radius:50%;font-size:29px}.summary-card span{display:block;margin-bottom:6px;font-size:14px;color:#4d596b}.summary-card strong{display:block;font-size:27px;line-height:27px}.summary-card.all>i{color:#00a15b;background:#ddf9eb}.summary-card.all strong{color:#172033}.summary-card.draft>i{color:#667085;background:#f0f2f5}.summary-card.draft strong{color:#344054}.summary-card.confirmed>i{color:#079455;background:#ddf9eb}.summary-card.confirmed strong{color:#039855}.summary-card.voided>i{color:#f04438;background:#fee6e5}.summary-card.voided strong{color:#f04438}.table-card{padding:0 0 15px;overflow-x:auto;overflow-y:hidden}.transfer-table{width:100%;min-width:1580px}.table-card >>> .el-table th{height:47px;padding:0;background:#f7f9fc;color:#344054;font-size:13px;font-weight:600}.table-card >>> .el-table td{height:48px;padding:0;color:#344054;font-size:13px}.table-card >>> .el-table--border:after,.table-card >>> .el-table--group:after{background:#e4e9f0}.table-card >>> .el-table__fixed-right:before{background:#e6edf3}.fx-up{color:#039855}.fx-down{color:#f04438}.fx-flat{color:#475467}.table-card .el-pagination{margin:17px 22px 0;text-align:right}@media(max-width:1420px){.filter-grid{grid-template-columns:repeat(4,minmax(160px,1fr));gap:16px 20px}.keyword-filter{grid-column:span 2}.summary-card{padding:0 18px;gap:12px}}@media(max-width:1080px){.filter-grid{grid-template-columns:repeat(3,minmax(160px,1fr))}.summary-row{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:720px){.transfer-list-page{padding:18px 14px}.page-head{flex-direction:column;gap:14px}.head-actions{width:100%}.filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.keyword-filter{grid-column:span 2}.filter-actions{grid-column:span 2;justify-content:flex-start}.summary-card{height:88px;padding:0 15px}.summary-card>i{width:46px;height:46px;font-size:24px}}@media(max-width:480px){.head-actions .el-button{flex:1;margin:0}.filter-grid{grid-template-columns:1fr}.keyword-filter,.filter-actions{grid-column:span 1}.summary-row{grid-template-columns:1fr}.info-band{height:auto;min-height:52px;padding:10px 12px;line-height:20px}.table-card .el-pagination{margin:14px 10px 0}}
</style>
