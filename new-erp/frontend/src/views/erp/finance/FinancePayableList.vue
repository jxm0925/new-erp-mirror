<template>
  <div class="payable-page">
    <div class="page-head">
      <div><h1>应付管理</h1><p>基于采购到货结算事实汇总应付、付款、退货抵扣和进项发票，不允许手工改余额。</p></div>
      <div class="head-actions"><el-button @click="$router.push('/finance/supplier-ledgers')">供应商往来</el-button><el-button type="success" @click="exportCurrent">导出</el-button></div>
    </div>
    <section class="metric-strip">
      <div><span>当前应付</span><b>{{ money(summary.current_payable_amount) }}</b></div>
      <div><span>未付款</span><b class="red">{{ money(summary.unpaid_amount) }}</b></div>
      <div><span>已付款</span><b class="green">{{ money(summary.paid_amount) }}</b></div>
      <div><span>待收发票</span><b class="orange">{{ money(summary.unreceived_invoice_amount) }}</b></div>
    </section>
    <section class="filter-card">
      <el-form :inline="true" size="small" class="filter-form">
        <el-form-item label="供应商"><el-select v-model="filters.supplier_id" clearable filterable placeholder="请选择"><el-option v-for="row in suppliers" :key="row.id" :label="`${row.supplier_code || ''} ${row.supplier_name}`" :value="row.id" /></el-select></el-form-item>
        <el-form-item label="采购订单号"><el-input v-model.trim="filters.purchase_order_no" clearable placeholder="请输入采购订单号" /></el-form-item>
        <el-form-item label="来源单据号"><el-input v-model.trim="filters.source_document_no" clearable placeholder="请输入到货单号" /></el-form-item>
        <el-form-item label="业务日期"><el-date-picker v-model="dateRange" type="daterange" value-format="yyyy-MM-dd" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" /></el-form-item>
        <el-form-item label="付款状态"><el-select v-model="filters.payment_status" clearable placeholder="全部"><el-option label="未付款" value="unpaid" /><el-option label="部分付款" value="partial" /><el-option label="已付款" value="paid" /><el-option label="质量冻结" value="frozen" /></el-select></el-form-item>
        <el-form-item label="发票状态"><el-select v-model="filters.invoice_status" clearable placeholder="全部"><el-option label="未收票" value="unreceived" /><el-option label="部分收票" value="partial" /><el-option label="已收票" value="received" /></el-select></el-form-item>
        <el-form-item label="是否有余额"><el-select v-model="filters.has_balance" clearable placeholder="全部"><el-option label="有余额" value="yes" /><el-option label="无余额" value="no" /></el-select></el-form-item>
        <el-form-item><el-button type="success" @click="search">查询</el-button><el-button @click="reset">重置</el-button></el-form-item>
      </el-form>
    </section>
    <section class="table-card">
      <el-table v-loading="loading" :data="rows" border size="small" class="payable-table">
        <el-table-column label="供应商" min-width="150"><template slot-scope="{row}"><div>{{ row.supplier_name }}</div><small>{{ row.supplier_code || '—' }}</small></template></el-table-column>
        <el-table-column prop="source_document_no" label="来源单据号" min-width="145" />
        <el-table-column prop="purchase_order_no" label="采购订单号" min-width="145" />
        <el-table-column prop="business_date" label="业务日期" width="105" />
        <money-column label="当前应付" prop="current_payable_amount" />
        <money-column label="质量冻结" prop="quality_frozen_amount" />
        <money-column label="退货抵扣" prop="ap_offset_amount" />
        <money-column label="已付款" prop="paid_amount" />
        <money-column label="未付款" prop="unpaid_amount" strong />
        <money-column label="预付款核销" prop="prepayment_applied_amount" />
        <money-column label="待退款" prop="pending_refund_amount" />
        <money-column label="已收发票" prop="received_invoice_amount" />
        <money-column label="未收发票" prop="unreceived_invoice_amount" />
        <el-table-column label="付款状态" width="90"><template slot-scope="{row}"><el-tag size="mini" :type="paymentTag(row.payment_status)">{{ paymentLabel(row.payment_status) }}</el-tag></template></el-table-column>
        <el-table-column label="发票状态" width="90"><template slot-scope="{row}"><el-tag size="mini" :type="invoiceTag(row.invoice_status)">{{ invoiceLabel(row.invoice_status) }}</el-tag></template></el-table-column>
        <el-table-column label="财务状态" width="96"><template slot-scope="{row}"><el-tag size="mini" :type="financeTag(row.finance_status)">{{ financeLabel(row.finance_status) }}</el-tag></template></el-table-column>
        <el-table-column label="操作" width="92" fixed="right"><template slot-scope="{row}"><el-button type="text" @click="viewSource(row)">查看明细</el-button></template></el-table-column>
      </el-table>
      <el-pagination background layout="total, sizes, prev, pager, next, jumper" :current-page="page" :page-size="perPage" :page-sizes="[10,20,50,100]" :total="total" @current-change="p=>{page=p;load()}" @size-change="s=>{perPage=s;page=1;load()}" />
    </section>
  </div>
</template>
<script>
import Vue from 'vue'
import { listFinancePayables } from '../../../api/erp/finance'
import { listEntity } from '../../../api/erp/master'
Vue.component('money-column', { props:['label','prop','strong'], template:'<el-table-column :label="label" min-width="112" align="right"><template slot-scope="{row}"><b v-if="strong" class="money-strong">{{ Number(row[prop] || 0).toLocaleString(\'zh-CN\',{minimumFractionDigits:2,maximumFractionDigits:2}) }}</b><span v-else>{{ Number(row[prop] || 0).toLocaleString(\'zh-CN\',{minimumFractionDigits:2,maximumFractionDigits:2}) }}</span></template></el-table-column>' })
export default {
  data:()=>({ rows:[], suppliers:[], loading:false, page:1, perPage:20, total:0, dateRange:[], filters:{supplier_id:null,purchase_order_no:'',source_document_no:'',payment_status:'',invoice_status:'',has_balance:''}, summary:{} }),
  created(){this.restoreQuery();this.load();this.loadSuppliers()},
  watch:{
    $route(){
      this.restoreQuery()
      this.page=1
      this.load()
    }
  },
  methods:{
    money(v){return '¥ '+Number(v||0).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2})},
    params(){return {...this.filters,business_date_start:this.dateRange?.[0]||'',business_date_end:this.dateRange?.[1]||'',page:this.page,per_page:this.perPage,source_id:this.$route.query.source_id||''}},
    restoreQuery(){if(this.$route.query.supplier_id)this.filters.supplier_id=Number(this.$route.query.supplier_id)},
    async loadSuppliers(){try{const r=await listEntity('suppliers',{per_page:100,status:'enabled'});this.suppliers=r.data.data||[]}catch(e){}},
    async load(){this.loading=true;try{const r=await listFinancePayables(this.params());this.rows=r.data.data||[];this.total=Number(r.data.total||0);this.summary=r.data.summary||{}}catch(e){this.$message.error(e.userMessage||'应付数据加载失败')}finally{this.loading=false}},
    search(){this.page=1;this.load()}, reset(){this.filters={supplier_id:null,purchase_order_no:'',source_document_no:'',payment_status:'',invoice_status:'',has_balance:''};this.dateRange=[];this.page=1;this.$router.replace({query:{}}).catch(()=>{});this.load()},
    viewSource(row){
      const query={source_id:row.id}
      if(String(this.$route.query.source_id||'')===String(row.id)){
        this.page=1
        this.load()
      }else{
        this.$router.push({path:'/finance/payables',query})
      }
      this.$message.success(`已定位到结算来源：${row.source_document_no}`)
    }, exportCurrent(){const columns=[['供应商',r=>r.supplier_name],['来源单据号',r=>r.source_document_no],['采购订单号',r=>r.purchase_order_no],['业务日期',r=>r.business_date],['当前应付',r=>r.current_payable_amount],['质量冻结',r=>r.quality_frozen_amount],['退货抵扣',r=>r.ap_offset_amount],['已付款',r=>r.paid_amount],['未付款',r=>r.unpaid_amount],['已收发票',r=>r.received_invoice_amount],['未收发票',r=>r.unreceived_invoice_amount]];const content=[columns.map(c=>c[0]).join(','),...this.rows.map(row=>columns.map(([,getter])=>`"${String(getter(row)||'').replace(/"/g,'""')}"`).join(','))].join('\n');const blob=new Blob(['\ufeff'+content],{type:'text/csv;charset=utf-8'});const link=document.createElement('a');link.href=URL.createObjectURL(blob);link.download=`应付管理-${new Date().toISOString().slice(0,10)}-第${this.page}页.csv`;link.click();URL.revokeObjectURL(link.href);this.$message.success('已导出当前页数据')},
    paymentLabel(v){return ({unpaid:'未付款',partial:'部分付款',paid:'已付款',frozen:'质量冻结',settled:'已结清'})[v]||'—'}, paymentTag(v){return ({unpaid:'danger',partial:'warning',paid:'success',frozen:'warning',settled:'info'})[v]||'info'},
    invoiceLabel(v){return ({unreceived:'未收票',partial:'部分收票',received:'已收票',not_required:'无需发票'})[v]||'—'}, invoiceTag(v){return ({unreceived:'danger',partial:'warning',received:'success',not_required:'info'})[v]||'info'},
    financeLabel(v){return ({quality_frozen:'质量冻结',pending_refund:'待退款',settled:'已结清',unclosed:'未结清'})[v]||'—'}, financeTag(v){return ({quality_frozen:'warning',pending_refund:'danger',settled:'success',unclosed:'info'})[v]||'info'}
  }
}
</script>
<style scoped>
.payable-page{padding:22px;min-width:0;background:#f5f7fa}.page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:15px}.page-head h1{margin:0;color:#17233d;font-size:24px;line-height:34px}.page-head p{margin:4px 0 0;color:#7b8794;font-size:13px}.head-actions{display:flex;gap:10px}.metric-strip{display:grid;grid-template-columns:repeat(4,1fr);background:#fff;border:1px solid #e5eaf1;border-radius:5px;overflow:hidden;margin-bottom:14px}.metric-strip>div{padding:15px 22px;border-right:1px solid #edf0f4}.metric-strip>div:last-child{border:0}.metric-strip span{display:block;color:#7a8798;font-size:13px}.metric-strip b{display:block;margin-top:5px;font-size:22px;color:#1f2937}.metric-strip .green{color:#059669}.metric-strip .red{color:#e34d59}.metric-strip .orange{color:#ed8b00}.filter-card,.table-card{background:#fff;border:1px solid #e5eaf1;border-radius:5px;padding:16px;margin-bottom:14px}.filter-form{display:flex;flex-wrap:wrap;align-items:center;gap:0 8px}.filter-form .el-form-item{margin:0 0 12px}.filter-form .el-input,.filter-form .el-select{width:164px}.filter-form .el-date-editor{width:230px}.table-card{padding:0;overflow:auto}.payable-table{min-width:1880px}.payable-table ::v-deep .cell{white-space:normal;overflow:visible;text-overflow:clip;line-height:20px}.payable-table small{color:#8b95a5}.money-strong{color:#e34d59}.el-pagination{padding:14px 16px;text-align:right}@media(max-width:980px){.payable-page{padding:12px}.page-head{align-items:flex-start;gap:12px;flex-direction:column}.metric-strip{grid-template-columns:repeat(2,1fr)}.metric-strip>div:nth-child(2){border-right:0}.metric-strip>div:nth-child(-n+2){border-bottom:1px solid #edf0f4}.filter-form .el-form-item,.filter-form .el-input,.filter-form .el-select,.filter-form .el-date-editor{width:100%}}
</style>
