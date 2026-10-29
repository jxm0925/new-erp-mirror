<template>
  <main class="invoice-list-page">
    <header class="invoice-heading">
      <div><h1>发票管理</h1><p>进项发票是供应商开给我们的，销项发票是我们开给客户的；销售申请的开票在“开票申请”里处理，开好后自动进入销项发票</p></div>
      <div class="heading-actions">
        <template v-if="tab==='purchase'"><el-button v-if="$can('finance.invoice.create')" type="success" class="green-button" @click="$router.push('/finance/invoices/create')">登记进项发票</el-button><el-button @click="toUnmatched">发票匹配</el-button><el-button @click="exportCurrent">导出</el-button></template>
        <template v-else-if="tab==='sales'"><el-button v-if="$can('finance.invoice_request.view')" @click="switchTab('requests')">去开票申请</el-button><el-button @click="exportCurrent">导出</el-button></template>
      </div>
    </header>

    <el-tabs :value="tab" class="invoice-tabs" @tab-click="t=>switchTab(t.name)">
      <el-tab-pane v-if="canInvoice" name="purchase"><span slot="label"><i class="el-icon-download"/> 进项发票<small>供应商开给我们</small></span></el-tab-pane>
      <el-tab-pane v-if="canInvoice" name="sales"><span slot="label"><i class="el-icon-upload2"/> 销项发票<small>我们开给客户</small></span></el-tab-pane>
      <el-tab-pane v-if="canRequest" name="requests"><span slot="label"><i class="el-icon-tickets"/> 开票申请<el-badge v-if="pendingRequests" class="tab-badge" :value="pendingRequests"/></span></el-tab-pane>
    </el-tabs>

    <finance-invoice-request-list v-if="tab==='requests'" embedded @summary="s=>pendingRequests=Number(s.pending_count||0)"/>
    <template v-else>
      <section v-if="isSales" class="metrics metrics-3"><div><span>销项发票总额</span><b>{{ money(summary.invoice_total_amount) }}</b><em>CNY</em></div><div><span>已确认</span><b class="green">{{ money(summary.confirmed_amount) }}</b><em>CNY</em></div><div class="clickable" @click="canRequest&&switchTab('requests')"><span>待开票申请</span><b class="orange">{{ pendingRequests }}</b><em>张</em></div></section>
      <section v-else class="metrics"><div><span>进项发票总额</span><b>{{ money(summary.invoice_total_amount) }}</b><em>CNY</em></div><div><span>已确认</span><b class="green">{{ money(summary.confirmed_amount) }}</b><em>CNY</em></div><div><span>待匹配</span><b class="orange">{{ money(summary.unmatched_amount) }}</b><em>CNY</em></div><div><span>待红冲</span><b class="red">{{ money(summary.pending_red_amount) }}</b><em>CNY</em></div></section>

      <section class="filter-card">
        <el-form :model="filters" class="filter-grid" label-position="top">
          <el-form-item :label="isSales ? '客户' : '供应商'"><el-input v-model.trim="filters.supplier_keyword" :placeholder="isSales ? '请输入客户名称' : '请输入供应商名称/编码'" clearable suffix-icon="el-icon-search"/></el-form-item>
          <el-form-item label="发票号码"><el-input v-model.trim="filters.invoice_no" placeholder="请输入发票号码" clearable/></el-form-item>
          <el-form-item label="发票代码"><el-input v-model.trim="filters.invoice_code" placeholder="请输入发票代码" clearable/></el-form-item>
          <el-form-item label="开票日期"><el-date-picker v-model="invoiceDateRange" type="daterange" value-format="yyyy-MM-dd" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期"/></el-form-item>
          <el-form-item v-if="!isSales" label="收票日期"><el-date-picker v-model="receivedDateRange" type="date" value-format="yyyy-MM-dd" placeholder="请选择收票日期"/></el-form-item>
          <el-form-item label="状态"><el-select v-model="filters.status" clearable placeholder="请选择状态"><el-option v-if="!isSales" label="草稿" value="draft"/><el-option label="已确认" value="confirmed"/><template v-if="!isSales"><el-option label="待红冲" value="pending_red"/><el-option label="已红冲" value="red"/></template></el-select></el-form-item>
          <el-form-item v-if="!isSales" label="匹配状态"><el-select v-model="filters.match_status" clearable placeholder="请选择匹配状态"><el-option label="未匹配" value="unmatched"/><el-option label="部分匹配" value="partial"/><el-option label="已匹配" value="matched"/></el-select></el-form-item>
          <div class="filter-actions"><el-button type="success" class="green-button" @click="search">查询</el-button><el-button @click="reset">重置</el-button></div>
        </el-form>
      </section>

      <section class="table-card">
        <el-table v-if="isSales" key="sales" v-loading="loading" :data="rows" border class="invoice-table sales-table" size="small" empty-text="暂无销项发票，开票申请登记后会自动出现在这里">
          <el-table-column prop="document_no" label="发票记录号" min-width="122"/>
          <el-table-column label="发票号码/代码" min-width="142"><template slot-scope="{row}">{{ row.invoice_no || '—' }}<span v-if="row.invoice_code">/{{ row.invoice_code }}</span></template></el-table-column>
          <el-table-column prop="invoice_type" label="发票类型" min-width="118"><template slot-scope="{row}">{{ invoiceType(row.invoice_type) }}</template></el-table-column>
          <el-table-column prop="party_name_snapshot" label="客户" min-width="160"/>
          <el-table-column label="关联销售订单" min-width="150"><template slot-scope="{row}"><div v-for="o in row.sales_orders||[]" :key="o.id"><el-button type="text" size="mini" @click="$router.push(`/sales/orders/${o.id}/detail`)">{{ o.no }}</el-button></div><span v-if="!(row.sales_orders||[]).length">—</span><div v-if="row.invoice_request_no" class="muted">申请 {{ row.invoice_request_no }}</div></template></el-table-column>
          <el-table-column prop="invoice_date" label="开票日期" min-width="102"/>
          <amount-column label="未税金额 (CNY)" prop="amount_excl_tax"/>
          <amount-column label="税额 (CNY)" prop="tax_amount"/>
          <amount-column label="价税合计 (CNY)" prop="amount_incl_tax" strong/>
          <el-table-column label="状态" width="82"><template slot-scope="{row}"><el-tag size="mini" :type="statusTone(row.status)">{{ statusText(row.status) }}</el-tag></template></el-table-column>
          <el-table-column label="操作" fixed="right" width="80"><template slot-scope="{row}"><el-button type="text" @click="view(row)">查看</el-button></template></el-table-column>
        </el-table>
        <el-table v-else key="purchase" v-loading="loading" :data="rows" border class="invoice-table" size="small" empty-text="暂无进项发票">
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
    </template>
  </main>
</template>

<script>
import { listFinanceInvoices, listSalesInvoiceRequests } from '../../../api/erp/finance'
import FinanceInvoiceRequestList from './FinanceInvoiceRequestList.vue'
export default {
  components:{FinanceInvoiceRequestList,AmountColumn:{props:['label','prop','strong','green'],methods:{f(v){return Number(v||0).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2})}},template:'<el-table-column :label="label" min-width="106" align="right"><template slot-scope="{row}"><b :class="{\'amount-strong\':strong,\'amount-green\':green}">{{f(row[prop])}}</b></template></el-table-column>'}},
  data:()=>({tab:'purchase',pendingRequests:0,loading:false,rows:[],page:1,perPage:10,total:0,summary:{},invoiceDateRange:[],receivedDateRange:'',filters:{invoice_direction:'purchase',supplier_keyword:'',invoice_no:'',invoice_code:'',status:'',match_status:''}}),
  computed:{isSales(){return this.tab==='sales'},canInvoice(){return this.$can('finance.invoice.view')},canRequest(){return this.$can('finance.invoice_request.view')}},
  watch:{'$route.query.tab'(){if(this.$route.path==='/finance/invoices')this.applyRouteTab()}},
  created(){this.applyRouteTab();this.loadPendingCount()},
  methods:{
    params(){return {...this.filters,invoice_date_start:this.invoiceDateRange?.[0]||'',invoice_date_end:this.invoiceDateRange?.[1]||'',received_date_start:this.receivedDateRange||'',page:this.page,per_page:this.perPage}},
    async load(){this.loading=true;try{const r=await listFinanceInvoices(this.params());this.rows=r.data.data||[];this.total=Number(r.data.total||0);this.summary=r.data.summary||{}}catch(e){this.$message.error(e.userMessage||'发票列表加载失败')}finally{this.loading=false}},
    applyRouteTab(){const q=this.$route.query.tab||(this.$route.query.invoice_direction==='sales'?'sales':'');const allowed=[...(this.canInvoice?['purchase','sales']:[]),...(this.canRequest?['requests']:[])];const next=allowed.includes(q)?q:allowed[0]||'purchase';const changed=next!==this.tab||!this.loadedOnce;this.tab=next;if(next!=='requests'&&changed){this.filters={invoice_direction:next,supplier_keyword:'',invoice_no:'',invoice_code:'',status:'',match_status:''};this.invoiceDateRange=[];this.receivedDateRange='';this.page=1;this.rows=[];this.summary={};this.loadedOnce=true;this.load()}},
    switchTab(name){if(name===this.tab)return;this.$router.replace({path:'/finance/invoices',query:{tab:name}}).catch(()=>{})},
    async loadPendingCount(){if(!this.canRequest)return;try{const r=await listSalesInvoiceRequests({status:'pending',per_page:5});this.pendingRequests=Number((r.data.summary||{}).pending_count||0)}catch(e){}},
    search(){this.page=1;this.load()},reset(){this.filters={invoice_direction:this.isSales?'sales':'purchase',supplier_keyword:'',invoice_no:'',invoice_code:'',status:'',match_status:''};this.invoiceDateRange=[];this.receivedDateRange='';this.search()},toUnmatched(){this.filters.match_status='unmatched';this.search()},
    view(r){this.$router.push({path:`/finance/invoices/${r.id}`,query:{mode:'view'}})},edit(r){this.$router.push(`/finance/invoices/${r.id}/edit`)},match(r){this.$router.push(`/finance/invoices/${r.id}/match`)},
    money(v){return Number(v||0).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2})},invoiceType(v){return ({vat_special:'增值税专用发票',vat_normal:'增值税普通发票',other:'其他'})[v]||v||'—'},statusText(v){return ({draft:'草稿',confirmed:'已确认',pending_red:'待红冲',red:'已红冲',voided:'已作废'})[v]||v},statusTone(v){return ({draft:'info',confirmed:'success',pending_red:'danger',red:'danger',voided:'info'})[v]||'info'},matchText(v){return ({unmatched:'未匹配',partial:'部分匹配',matched:'已匹配'})[v]||'—'},matchTone(v){return ({unmatched:'warning',partial:'primary',matched:'success'})[v]||'info'},
    exportCurrent(){const cols=[['发票记录号',r=>r.document_no],['发票号码',r=>r.invoice_no],['发票代码',r=>r.invoice_code],[this.isSales?'客户':'供应商',r=>r.party_name_snapshot],...(this.isSales?[['关联销售订单',r=>(r.sales_orders||[]).map(o=>o.no).join(' ')]]:[]),['价税合计',r=>r.amount_incl_tax],['已匹配',r=>r.matched_amount],['未匹配',r=>r.unmatched_amount]];const useCols=this.isSales?cols.filter(c=>!['已匹配','未匹配'].includes(c[0])):cols;const csv=[useCols.map(x=>x[0]).join(','),...this.rows.map(r=>useCols.map(([,f])=>`"${String(f(r)||'').replace(/"/g,'""')}"`).join(','))].join('\n');const a=document.createElement('a');a.href=URL.createObjectURL(new Blob(['\ufeff'+csv],{type:'text/csv;charset=utf-8'}));a.download=`${this.isSales?'销项':'进项'}发票-${new Date().toISOString().slice(0,10)}-第${this.page}页.csv`;a.click();URL.revokeObjectURL(a.href);this.$message.success('已导出当前页数据')}
  }
}
</script>

<style scoped>
.invoice-list-page{min-height:calc(100vh - 64px);padding:24px 20px 30px;background:#fff;color:#17233b;box-sizing:border-box}.invoice-heading{display:flex;justify-content:space-between;align-items:flex-start;margin:0 0 22px}.invoice-heading h1{margin:0 0 8px;font-size:28px;line-height:34px;font-weight:700}.invoice-heading p{margin:0;color:#7b879b;font-size:14px}.heading-actions{display:flex;gap:12px}.green-button{background:#008b4b;border-color:#008b4b}.metrics{display:grid;grid-template-columns:repeat(4,1fr);border:1px solid #dde6ef;border-radius:4px;margin-bottom:20px;overflow:hidden}.metrics>div{min-height:95px;padding:20px 22px;box-sizing:border-box;border-right:1px solid #e3e9ef}.metrics>div:last-child{border-right:0}.metrics span{display:block;color:#526078;font-size:14px;margin-bottom:14px}.metrics b{font-size:25px;line-height:1;color:#152238}.metrics em{font-style:normal;font-size:13px;margin-left:7px}.metrics .green{color:#009b50}.metrics .orange{color:#ff5b00}.metrics .red{color:#ed2a2a}.filter-card{border:1px solid #e0e7ef;border-radius:5px;padding:18px 20px;margin-bottom:20px}.filter-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));column-gap:28px;align-items:end}.filter-grid .el-form-item{margin:0 0 17px}.filter-grid ::v-deep .el-form-item__label{font-size:14px;color:#26334a;line-height:20px;padding-bottom:8px}.filter-grid .el-select,.filter-grid .el-input,.filter-grid .el-date-editor{width:100%}.filter-grid .el-date-editor{min-width:230px}.filter-actions{display:flex;align-self:end;gap:12px;margin-bottom:17px}.table-card{border:1px solid #e4eaf0;overflow:auto}.invoice-table{min-width:1610px}.invoice-table ::v-deep .cell{white-space:normal;overflow:visible;text-overflow:clip;line-height:20px}.amount-strong{font-weight:600}.amount-green{color:#009b50}.table-card .el-pagination{padding:15px 14px;text-align:right}@media(max-width:1180px){.filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:760px){.invoice-list-page{padding:16px 12px}.invoice-heading{flex-direction:column;gap:12px}.heading-actions{flex-wrap:wrap}.metrics{grid-template-columns:repeat(2,1fr)}.metrics>div:nth-child(2){border-right:0}.metrics>div:nth-child(-n+2){border-bottom:1px solid #e3e9ef}.filter-grid{grid-template-columns:1fr}.filter-grid .el-date-editor{min-width:0}.filter-actions{margin-top:-5px}}
.invoice-tabs{margin:-6px 0 18px}.invoice-tabs ::v-deep .el-tabs__item{font-size:15px;height:44px;line-height:44px}.invoice-tabs small{margin-left:6px;color:#8a94a6;font-size:12px;font-weight:400}.tab-badge{margin-left:6px}.tab-badge ::v-deep .el-badge__content{top:-2px}.metrics-3{grid-template-columns:repeat(3,1fr)}.clickable{cursor:pointer}.muted{color:#8a94a6;font-size:12px}.sales-table{min-width:1280px}
</style>
