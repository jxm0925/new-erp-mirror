<template>
  <main class="invoice-list-page">
    <header class="invoice-heading">
      <div><h1>发票管理</h1><p>登记与管理企业的进项发票，跟踪匹配状态，支持与采购、付款等单据匹配对账</p></div>
      <div class="heading-actions"><el-button type="success" class="green-button" @click="$router.push('/finance/invoices/create')">登记进项发票</el-button><el-button @click="toUnmatched">发票匹配</el-button><el-button @click="exportCurrent">导出</el-button></div>
    </header>

    <section class="metrics"><div><span>进项发票总额</span><b>{{ money(summary.invoice_total_amount) }}</b><em>CNY</em></div><div><span>已确认</span><b class="green">{{ money(summary.confirmed_amount) }}</b><em>CNY</em></div><div><span>待匹配</span><b class="orange">{{ money(summary.unmatched_amount) }}</b><em>CNY</em></div><div><span>待红冲</span><b class="red">{{ money(summary.pending_red_amount) }}</b><em>CNY</em></div></section>

    <section class="filter-card">
      <el-form :model="filters" class="filter-grid" label-position="top">
        <el-form-item label="发票方向"><el-select v-model="filters.invoice_direction"><el-option label="进项" value="purchase"/></el-select></el-form-item>
        <el-form-item label="供应商"><el-input v-model.trim="filters.supplier_keyword" placeholder="请输入供应商名称/编码" clearable suffix-icon="el-icon-search"/></el-form-item>
        <el-form-item label="发票号码"><el-input v-model.trim="filters.invoice_no" placeholder="请输入发票号码" clearable/></el-form-item>
        <el-form-item label="发票代码"><el-input v-model.trim="filters.invoice_code" placeholder="请输入发票代码" clearable/></el-form-item>
        <el-form-item label="开票日期"><el-date-picker v-model="invoiceDateRange" type="daterange" value-format="yyyy-MM-dd" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期"/></el-form-item>
        <el-form-item label="收票日期"><el-date-picker v-model="receivedDateRange" type="date" value-format="yyyy-MM-dd" placeholder="请选择收票日期"/></el-form-item>
        <el-form-item label="状态"><el-select v-model="filters.status" clearable placeholder="请选择状态"><el-option label="草稿" value="draft"/><el-option label="已确认" value="confirmed"/><el-option label="待红冲" value="pending_red"/><el-option label="已红冲" value="red"/></el-select></el-form-item>
        <el-form-item label="匹配状态"><el-select v-model="filters.match_status" clearable placeholder="请选择匹配状态"><el-option label="未匹配" value="unmatched"/><el-option label="部分匹配" value="partial"/><el-option label="已匹配" value="matched"/></el-select></el-form-item>
        <div class="filter-actions"><el-button type="success" class="green-button" @click="search">查询</el-button><el-button @click="reset">重置</el-button></div>
      </el-form>
    </section>

    <section class="table-card">
      <el-table v-loading="loading" :data="rows" border class="invoice-table" size="small" empty-text="暂无进项发票">
        <el-table-column prop="document_no" label="发票记录号" min-width="122"/>
        <el-table-column label="发票号码/代码" min-width="142"><template slot-scope="{row}">{{ row.invoice_no || '—' }}<span v-if="row.invoice_code">/{{ row.invoice_code }}</span></template></el-table-column>
        <el-table-column prop="invoice_type" label="发票类型" min-width="118"><template slot-scope="{row}">{{ invoiceType(row.invoice_type) }}</template></el-table-column>
        <el-table-column prop="party_name_snapshot" label="供应商" min-width="160"/>
        <el-table-column prop="invoice_date" label="开票日期" min-width="102"/>
        <el-table-column prop="received_date" label="收票日期" min-width="102"/>
        <amount-column label="未税金额 (CNY)" prop="amount_excl_tax"/>
        <amount-column label="税额 (CNY)" prop="tax_amount"/>
        <amount-column label="价税合计 (CNY)" prop="amount_incl_tax" strong/>
        <amount-column label="已匹配 (CNY)" prop="matched_amount" green/>
        <amount-column label="未匹配 (CNY)" prop="unmatched_amount"/>
        <el-table-column label="状态" width="82"><template slot-scope="{row}"><el-tag size="mini" :type="statusTone(row.status)">{{ statusText(row.status) }}</el-tag></template></el-table-column>
        <el-table-column label="匹配状态" width="92"><template slot-scope="{row}"><el-tag size="mini" :type="matchTone(row.match_status)">{{ matchText(row.match_status) }}</el-tag></template></el-table-column>
        <el-table-column label="操作" fixed="right" width="162"><template slot-scope="{row}"><el-button type="text" @click="view(row)">查看</el-button><el-button v-if="row.status==='draft'" type="text" @click="edit(row)">编辑</el-button><el-button v-if="row.status==='draft'" type="text" @click="match(row)">发票匹配</el-button></template></el-table-column>
      </el-table>
      <el-pagination background layout="total, prev, pager, next, sizes, jumper" :current-page="page" :page-size="perPage" :page-sizes="[10,20,50,100]" :total="total" @current-change="p=>{page=p;load()}" @size-change="s=>{perPage=s;page=1;load()}"/>
    </section>
  </main>
</template>

<script>
import { listFinanceInvoices } from '../../../api/erp/finance'
export default {
  components:{AmountColumn:{props:['label','prop','strong','green'],methods:{f(v){return Number(v||0).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2})}},template:'<el-table-column :label="label" min-width="106" align="right"><template slot-scope="{row}"><b :class="{\'amount-strong\':strong,\'amount-green\':green}">{{f(row[prop])}}</b></template></el-table-column>'}},
  data:()=>({loading:false,rows:[],page:1,perPage:10,total:0,summary:{},invoiceDateRange:[],receivedDateRange:'',filters:{invoice_direction:'purchase',supplier_keyword:'',invoice_no:'',invoice_code:'',status:'',match_status:''}}),
  created(){this.load()},
  methods:{
    params(){return {...this.filters,invoice_date_start:this.invoiceDateRange?.[0]||'',invoice_date_end:this.invoiceDateRange?.[1]||'',received_date_start:this.receivedDateRange||'',page:this.page,per_page:this.perPage}},
    async load(){this.loading=true;try{const r=await listFinanceInvoices(this.params());this.rows=r.data.data||[];this.total=Number(r.data.total||0);this.summary=r.data.summary||{}}catch(e){this.$message.error(e.userMessage||'发票列表加载失败')}finally{this.loading=false}},
    search(){this.page=1;this.load()},reset(){this.filters={invoice_direction:'purchase',supplier_keyword:'',invoice_no:'',invoice_code:'',status:'',match_status:''};this.invoiceDateRange=[];this.receivedDateRange='';this.search()},toUnmatched(){this.filters.match_status='unmatched';this.search()},
    view(r){this.$router.push({path:`/finance/invoices/${r.id}`,query:{mode:'view'}})},edit(r){this.$router.push(`/finance/invoices/${r.id}/edit`)},match(r){this.$router.push(`/finance/invoices/${r.id}/match`)},
    money(v){return Number(v||0).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2})},invoiceType(v){return ({vat_special:'增值税专用发票',vat_normal:'增值税普通发票',other:'其他'})[v]||v||'—'},statusText(v){return ({draft:'草稿',confirmed:'已确认',pending_red:'待红冲',red:'已红冲',voided:'已作废'})[v]||v},statusTone(v){return ({draft:'info',confirmed:'success',pending_red:'danger',red:'danger',voided:'info'})[v]||'info'},matchText(v){return ({unmatched:'未匹配',partial:'部分匹配',matched:'已匹配'})[v]||'—'},matchTone(v){return ({unmatched:'warning',partial:'primary',matched:'success'})[v]||'info'},
    exportCurrent(){const cols=[['发票记录号',r=>r.document_no],['发票号码',r=>r.invoice_no],['发票代码',r=>r.invoice_code],['供应商',r=>r.party_name_snapshot],['价税合计',r=>r.amount_incl_tax],['已匹配',r=>r.matched_amount],['未匹配',r=>r.unmatched_amount]];const csv=[cols.map(x=>x[0]).join(','),...this.rows.map(r=>cols.map(([,f])=>`"${String(f(r)||'').replace(/"/g,'""')}"`).join(','))].join('\n');const a=document.createElement('a');a.href=URL.createObjectURL(new Blob(['\ufeff'+csv],{type:'text/csv;charset=utf-8'}));a.download=`发票管理-${new Date().toISOString().slice(0,10)}-第${this.page}页.csv`;a.click();URL.revokeObjectURL(a.href);this.$message.success('已导出当前页数据')}
  }
}
</script>

<style scoped>
.invoice-list-page{min-height:calc(100vh - 64px);padding:24px 20px 30px;background:#fff;color:#17233b;box-sizing:border-box}.invoice-heading{display:flex;justify-content:space-between;align-items:flex-start;margin:0 0 22px}.invoice-heading h1{margin:0 0 8px;font-size:28px;line-height:34px;font-weight:700}.invoice-heading p{margin:0;color:#7b879b;font-size:14px}.heading-actions{display:flex;gap:12px}.green-button{background:#008b4b;border-color:#008b4b}.metrics{display:grid;grid-template-columns:repeat(4,1fr);border:1px solid #dde6ef;border-radius:4px;margin-bottom:20px;overflow:hidden}.metrics>div{min-height:95px;padding:20px 22px;box-sizing:border-box;border-right:1px solid #e3e9ef}.metrics>div:last-child{border-right:0}.metrics span{display:block;color:#526078;font-size:14px;margin-bottom:14px}.metrics b{font-size:25px;line-height:1;color:#152238}.metrics em{font-style:normal;font-size:13px;margin-left:7px}.metrics .green{color:#009b50}.metrics .orange{color:#ff5b00}.metrics .red{color:#ed2a2a}.filter-card{border:1px solid #e0e7ef;border-radius:5px;padding:18px 20px;margin-bottom:20px}.filter-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));column-gap:28px;align-items:end}.filter-grid .el-form-item{margin:0 0 17px}.filter-grid ::v-deep .el-form-item__label{font-size:14px;color:#26334a;line-height:20px;padding-bottom:8px}.filter-grid .el-select,.filter-grid .el-input,.filter-grid .el-date-editor{width:100%}.filter-grid .el-date-editor{min-width:230px}.filter-actions{display:flex;align-self:end;gap:12px;margin-bottom:17px}.table-card{border:1px solid #e4eaf0;overflow:auto}.invoice-table{min-width:1610px}.invoice-table ::v-deep .cell{white-space:normal;overflow:visible;text-overflow:clip;line-height:20px}.amount-strong{font-weight:600}.amount-green{color:#009b50}.table-card .el-pagination{padding:15px 14px;text-align:right}@media(max-width:1180px){.filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:760px){.invoice-list-page{padding:16px 12px}.invoice-heading{flex-direction:column;gap:12px}.heading-actions{flex-wrap:wrap}.metrics{grid-template-columns:repeat(2,1fr)}.metrics>div:nth-child(2){border-right:0}.metrics>div:nth-child(-n+2){border-bottom:1px solid #e3e9ef}.filter-grid{grid-template-columns:1fr}.filter-grid .el-date-editor{min-width:0}.filter-actions{margin-top:-5px}}
</style>
