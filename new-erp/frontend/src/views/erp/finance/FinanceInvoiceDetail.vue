<template>
  <main v-loading="loading" class="invoice-detail-page">
    <header class="page-heading">
      <div class="heading-title"><h1>进项发票详情</h1><el-tag size="mini" :type="invoice.status==='confirmed'?'success':'info'">{{ statusText(invoice.status) }}</el-tag></div>
      <div class="heading-actions">
        <el-button @click="$router.push('/finance/invoices')">返回发票列表</el-button>
        <el-button icon="el-icon-printer" @click="printPage">打印</el-button>
        <el-button class="red-outline" :disabled="invoice.status!=='confirmed'||!!invoice.red_invoice_of_id" @click="$router.push(`/finance/invoices/${invoice.id}/red`)">红冲发票</el-button>
      </div>
    </header>

    <el-alert class="business-alert" :closable="false" type="info" show-icon title="该发票已确认，可用于抵扣勾选和进项税额抵扣。"/>

    <section class="top-grid">
      <article class="panel invoice-info">
        <h2>发票信息</h2>
        <div class="info-grid">
          <label>发票记录号<span>{{ invoice.document_no || '—' }}</span></label>
          <label>发票号码<span>{{ invoice.invoice_no || '—' }}</span></label>
          <label>发票代码<span>{{ invoice.invoice_code || '—' }}</span></label>
          <label>发票类型<span>{{ invoiceType(invoice.invoice_type) }}</span></label>
          <label>开票日期<span>{{ invoice.invoice_date || '—' }}</span></label>
          <label>收票日期<span>{{ invoice.received_date || '—' }}</span></label>
          <label>供应商<span>{{ invoice.party_name_snapshot || '—' }}</span></label>
          <label>纳税人识别号<span>—</span></label>
          <label>地址、电话<span>—</span></label>
          <label>开户行及账号<span>—</span></label>
          <label>价税合计<span>{{ money(invoice.amount_incl_tax) }} {{ invoice.currency || 'CNY' }}</span></label>
          <label>税额<span>{{ money(invoice.tax_amount) }} {{ invoice.currency || 'CNY' }}</span></label>
          <label>金额（不含税）<span>{{ money(invoice.amount_excl_tax) }} {{ invoice.currency || 'CNY' }}</span></label>
          <label>发票状态<span class="green">{{ statusText(invoice.status) }}</span></label>
          <label class="full">发票备注<span>{{ invoice.remark || '—' }}</span></label>
        </div>
      </article>
      <div class="side-panels">
        <article class="panel compact-panel tax-panel"><h2>税额信息</h2><div class="amount-row"><span>税率</span><b>{{ taxRate }}%</b></div><div class="amount-row"><span>税额</span><b>{{ money(invoice.tax_amount) }} {{ invoice.currency || 'CNY' }}</b></div><div class="amount-row"><span>金额（不含税）</span><b>{{ money(invoice.amount_excl_tax) }} {{ invoice.currency || 'CNY' }}</b></div><div class="amount-row total"><span>价税合计</span><b>{{ money(invoice.amount_incl_tax) }} {{ invoice.currency || 'CNY' }}</b></div></article>
        <article class="panel compact-panel match-panel"><h2>匹配状态</h2><div class="match-row"><span>匹配状态</span><b>{{ matchText }}</b></div><div class="match-row"><span>已匹配金额（价税合计）</span><b class="green">{{ money(invoice.matched_amount) }} {{ invoice.currency || 'CNY' }}</b></div><div class="match-row"><span>未匹配金额（价税合计）</span><b class="red">{{ money(invoice.unmatched_amount) }} {{ invoice.currency || 'CNY' }}</b></div><div class="match-row"><span>可用余额</span><b>{{ money(invoice.unmatched_amount) }} {{ invoice.currency || 'CNY' }}</b></div><div class="match-row"><span>匹配税额</span><b>—</b></div><div class="match-row"><span>未匹配税额</span><b>—</b></div></article>
      </div>
    </section>

    <section class="panel match-table-panel">
      <div class="panel-title"><h2>本票匹配明细</h2><span>匹配历史完整保留，草稿状态才允许撤销有效匹配。</span></div>
      <el-table :data="matchRows" border size="small" class="detail-table" empty-text="暂无匹配记录">
        <el-table-column type="index" label="序号" width="50"/>
        <el-table-column prop="source_business_type_label" label="匹配来源" min-width="96"/>
        <el-table-column prop="source_document_no" label="结算来源单号" min-width="136"/>
        <el-table-column label="收货单号 / 采购订单号" min-width="180"><template slot-scope="{row}"><div>{{ row.receipt_no || '—' }}</div><small>{{ row.purchase_order_no || '—' }}</small></template></el-table-column>
        <el-table-column prop="business_date" label="业务日期" min-width="96"/>
        <el-table-column label="来源金额（CNY）" min-width="120" align="right"><template slot-scope="{row}">{{ money(row.source_amount_snapshot) }}</template></el-table-column>
        <el-table-column label="匹配金额（CNY）" min-width="120" align="right"><template slot-scope="{row}"><b>{{ money(row.allocated_amount) }}</b></template></el-table-column>
        <el-table-column label="税额" width="74" align="center"><template>—</template></el-table-column>
        <el-table-column label="状态" width="82" align="center"><template slot-scope="{row}"><el-tag size="mini" :type="allocationTone(row.status)">{{ allocationText(row.status) }}</el-tag></template></el-table-column>
        <el-table-column prop="created_at" label="匹配时间" min-width="138"/>
        <el-table-column label="操作" width="88" align="center"><template slot-scope="{row}"><el-button v-if="canReverse(row)" type="text" @click="openReverse(row)">撤销匹配</el-button><span v-else>—</span></template></el-table-column>
      </el-table>
      <div class="match-total-row"><b>合计</b><span></span><span></span><span></span><span></span><b>{{ money(matchSourceTotal) }}</b><b>{{ money(matchAllocatedTotal) }}</b><b>—</b><span></span><span></span><span></span></div>
      <el-pagination background layout="total, prev, pager, next, sizes, jumper" :current-page="matchPage" :page-size="matchPerPage" :page-sizes="[10,20,50]" :total="matchTotal" @current-change="changeMatchPage" @size-change="changeMatchSize"/>
    </section>

    <section class="bottom-grid">
      <article class="panel attachments-panel"><div class="panel-title"><h2>发票附件</h2></div><div v-if="attachments.length" class="attachment-list"><div v-for="file in attachments" :key="file.id" class="attachment-row"><div class="attachment-thumb"><img v-if="file.thumnailUrl" :src="file.thumnailUrl" :alt="file.original_name"><i v-else class="el-icon-document"></i></div><div class="attachment-meta"><label>文件名称<b>{{ file.original_name }}</b></label><label>文件大小<b>{{ fileSize(file.file_size) }}</b></label><label>上传时间<b>{{ file.uploaded_at || '—' }}</b></label><label>上传人<b>系统</b></label><el-button type="text" icon="el-icon-view" @click="preview(file)">预览</el-button></div></div></div><el-empty v-else :image-size="52" description="暂无发票附件"/></article>
      <article class="panel log-panel"><div class="panel-title"><h2>操作日志</h2><span>按发生时间倒序</span></div><el-table :data="logRows" border size="small" class="log-table" empty-text="暂无操作日志"><el-table-column prop="created_at" label="操作时间" min-width="150"/><el-table-column prop="operator_name" label="操作人" width="110"/><el-table-column label="操作" min-width="126"><template slot-scope="{row}">{{ actionText(row.action) }}</template></el-table-column><el-table-column prop="content" label="操作说明" min-width="235" show-overflow-tooltip/></el-table><el-pagination background small layout="prev, pager, next" :current-page="logPage" :page-size="logPerPage" :total="logTotal" @current-change="p=>{logPage=p;load()}"/></article>
    </section>

    <el-dialog title="撤销发票匹配" :visible.sync="reverseVisible" width="480px" :close-on-click-modal="false"><p class="dialog-tip">撤销会生成反向匹配事实并恢复来源可开票余额，原记录不会被删除。</p><el-form label-width="84px"><el-form-item label="匹配来源"><span>{{ reverseRow && reverseRow.source_document_no }}</span></el-form-item><el-form-item label="撤销原因" required><el-input v-model.trim="reverseReason" type="textarea" :rows="3" maxlength="255" show-word-limit placeholder="请填写撤销原因"/></el-form-item></el-form><span slot="footer"><el-button @click="reverseVisible=false">取消</el-button><el-button type="danger" :loading="reversing" @click="reverse">确认撤销</el-button></span></el-dialog>
  </main>
</template>

<script>
import { getFinanceInvoiceDetail, previewFinanceAttachment, reverseFinanceInvoiceMatch } from '../../../api/erp/finance'
export default {
  data:()=>({loading:false,invoice:{},attachments:[],matchRows:[],matchPage:1,matchPerPage:10,matchTotal:0,logRows:[],logPage:1,logPerPage:10,logTotal:0,reverseVisible:false,reverseRow:null,reverseReason:'',reversing:false}),
  computed:{taxRate(){const tax=Number(this.invoice.tax_amount||0),excl=Number(this.invoice.amount_excl_tax||0);return excl>0?(tax/excl*100).toFixed(2).replace(/\.00$/,''):'0'},matchText(){const amount=Number(this.invoice.amount_incl_tax||0),matched=Number(this.invoice.matched_amount||0);return matched<=0?'未匹配':matched>=amount?'已匹配':'部分匹配'},matchSourceTotal(){return this.matchRows.reduce((sum,row)=>sum+Number(row.source_amount_snapshot||0),0)},matchAllocatedTotal(){return this.matchRows.reduce((sum,row)=>sum+Number(row.allocated_amount||0),0)}},
  created(){this.load()},
  beforeDestroy(){this.attachments.forEach(file=>{if(file.thumnailUrl)URL.revokeObjectURL(file.thumnailUrl)})},
  methods:{
    async load(){this.loading=true;try{const r=await getFinanceInvoiceDetail(this.$route.params.id,{match_page:this.matchPage,match_per_page:this.matchPerPage,log_page:this.logPage,log_per_page:this.logPerPage});const d=r.data.data||{};this.invoice=d.invoice||{};this.matchRows=d.match_history?.data||[];this.matchTotal=Number(d.match_history?.total||0);this.logRows=d.operation_logs?.data||[];this.logTotal=Number(d.operation_logs?.total||0);this.attachments=(d.attachments||[]).map(x=>({...x,thumnailUrl:''}));this.loadThumbnails()}catch(e){this.$message.error(e.userMessage||'发票详情加载失败')}finally{this.loading=false}},
    async loadThumbnails(){for(const file of this.attachments.filter(x=>String(x.mime_type||'').startsWith('image/'))){try{const r=await previewFinanceAttachment(file.id);this.$set(file,'thumnailUrl',URL.createObjectURL(r.data))}catch(e){/* preview remains available without a thumbnail */}}},
    money(v){return Number(v||0).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2})},fileSize(v){const n=Number(v||0);return n<1024?`${n} B`:n<1048576?`${(n/1024).toFixed(1)} KB`:`${(n/1048576).toFixed(2)} MB`},
    statusText(v){return ({draft:'草稿',confirmed:'已确认',pending_red:'待红冲',red:'已红冲',voided:'已作废'})[v]||v||'—'},invoiceType(v){return ({vat_special:'增值税专用发票',vat_normal:'增值税普通发票',other:'其他'})[v]||v||'—'},allocationText(v){return ({active:'生效中',reversed:'已撤销',reversal:'反向事实'})[v]||v||'—'},allocationTone(v){return ({active:'success',reversed:'info',reversal:'warning'})[v]||'info'},actionText(v){return ({create:'登记发票',update_draft:'修改草稿',match:'保存匹配',clear_match:'清空匹配',reverse_match:'撤销匹配',confirm:'确认发票'})[v]||v||'—'},
    changeMatchPage(p){this.matchPage=p;this.load()},changeMatchSize(s){this.matchPerPage=s;this.matchPage=1;this.load()},
    canReverse(row){return this.invoice.status==='draft'&&row.status==='active'&&this.$can('finance.invoice.reverse_match')},openReverse(row){this.reverseRow=row;this.reverseReason='';this.reverseVisible=true},
    async reverse(){if(!this.reverseReason){this.$message.warning('请填写撤销原因');return}this.reversing=true;try{await reverseFinanceInvoiceMatch(this.reverseRow.id,this.reverseReason);this.$message.success('已生成撤销匹配事实');this.reverseVisible=false;await this.load()}catch(e){this.$message.error(e.userMessage||'撤销匹配失败')}finally{this.reversing=false}},
    async preview(file){try{const r=await previewFinanceAttachment(file.id);const url=URL.createObjectURL(r.data);window.open(url,'_blank','noopener');setTimeout(()=>URL.revokeObjectURL(url),60000)}catch(e){this.$message.error(e.userMessage||'该附件暂不支持在线预览')}},printPage(){window.print()}
  }
}
</script>

<style scoped>
.invoice-detail-page{min-height:calc(100vh - 64px);padding:22px 20px 30px;background:#fff;color:#17233b;box-sizing:border-box}.page-heading{height:48px;display:flex;align-items:center;justify-content:space-between}.heading-title,.heading-actions{display:flex;align-items:center;gap:14px}.heading-title h1{margin:0;font-size:28px;line-height:38px;font-weight:700}.heading-actions{gap:12px}.red-outline{color:#e44d4d!important;border-color:#efb2b2!important;background:#fff!important}.business-alert{margin:12px 0 17px;border:1px solid #9cc7ff;background:#f3f8ff}.top-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(365px,.8fr);gap:16px}.panel{border:1px solid #e0e7ef;border-radius:5px;background:#fff;box-sizing:border-box}.panel h2{margin:0;font-size:17px;line-height:24px;color:#18243a}.invoice-info{padding:16px 20px}.info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:42px;row-gap:10px;margin-top:15px}.info-grid label{font-size:13px;color:#7a879b}.info-grid span{display:block;color:#253349;font-size:14px;margin-top:4px;line-height:19px;word-break:break-all}.info-grid i{font-style:normal}.info-grid .full{grid-column:1/-1}.side-panels{display:grid;grid-template-rows:109px 1fr;gap:12px}.compact-panel{padding:12px 18px}.amount-row,.match-row{display:flex;align-items:center;justify-content:space-between;padding-top:4px;font-size:13px;color:#66748a;line-height:20px}.amount-row b,.match-row b{font-size:14px;color:#17233b}.amount-row.total{margin-top:3px;padding-top:5px;border-top:1px solid #e7edf3}.amount-row.total b{font-size:18px}.green{color:#009b50!important}.red{color:#f23434!important}.match-row{padding-top:4px}.match-row:first-of-type{padding-top:7px}.match-table-panel{margin-top:16px;padding:17px 16px 13px}.panel-title{display:flex;align-items:baseline;gap:12px;margin-bottom:15px}.panel-title span{font-size:13px;color:#8994a5}.detail-table ::v-deep .cell{white-space:normal;overflow:visible;text-overflow:clip;line-height:20px}.detail-table small{color:#8490a2}.match-total-row{display:grid;grid-template-columns:58px 108px 150px 205px 108px 132px 132px 90px 92px 154px 96px;align-items:center;min-width:1325px;height:39px;padding:0 12px;box-sizing:border-box;border:1px solid #e4eaf0;border-top:0;font-size:13px;text-align:right}.match-total-row>b:first-child{text-align:left}.match-table-panel>.el-pagination{padding:15px 0 0;text-align:right}.bottom-grid{display:grid;grid-template-columns:1fr 2.15fr;gap:16px;margin-top:12px}.attachments-panel,.log-panel{padding:17px 16px;min-height:226px}.attachment-list{border:0;border-radius:4px}.attachment-row{display:flex;align-items:flex-start;gap:20px;min-height:161px;padding:0;border-bottom:0}.attachment-thumb{height:145px;width:140px;flex:0 0 140px;border:1px solid #dae3ec;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#f7f9fb}.attachment-thumb img{width:100%;height:100%;object-fit:cover}.attachment-thumb i{font-size:34px;color:#409eff}.attachment-meta{min-width:0;flex:1;display:flex;flex-direction:column;gap:9px;padding-top:3px}.attachment-meta label{font-size:12px;color:#7c8799}.attachment-meta b{display:block;margin-top:2px;font-size:13px;color:#1d2a3f;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.attachment-meta .el-button{align-self:flex-start;padding-left:0;margin-top:3px}.log-table ::v-deep .cell{line-height:20px}.log-panel>.el-pagination{padding:13px 0 0;text-align:right}.dialog-tip{margin:0 0 14px;padding:10px 12px;font-size:13px;line-height:20px;color:#8a5a00;background:#fff8e7;border:1px solid #f7d88c}@media(max-width:1120px){.top-grid,.bottom-grid{grid-template-columns:1fr}.side-panels{grid-template-columns:1fr 1fr;grid-template-rows:none}.compact-panel{min-height:150px}.match-total-row{display:none}}@media(max-width:760px){.invoice-detail-page{padding:16px 12px}.page-heading{height:auto;align-items:flex-start;flex-direction:column;gap:12px}.heading-actions{flex-wrap:wrap}.top-grid,.bottom-grid{gap:12px}.side-panels{grid-template-columns:1fr}.info-grid{grid-template-columns:1fr;gap:13px}.info-grid .full{grid-column:auto}.match-table-panel{overflow:auto}.detail-table{min-width:1250px}.attachment-row{gap:10px}.attachment-thumb{width:94px;flex-basis:94px;height:116px}}
.invoice-detail-page{min-height:calc(100vh - 58px);padding:18px 20px 24px}.invoice-detail-page .page-heading{height:42px}.invoice-detail-page>.business-alert{margin:7px 0 16px}.invoice-detail-page .invoice-info{min-height:340px;padding:14px 18px}.invoice-detail-page .info-grid{grid-template-columns:repeat(2,minmax(0,1fr));column-gap:34px;row-gap:0;margin-top:13px}.invoice-detail-page .info-grid label{display:grid;grid-template-columns:104px minmax(0,1fr);align-items:start;min-height:33px;font-size:13px;line-height:20px;color:#58677d}.invoice-detail-page .info-grid span{margin-top:0;font-size:13px;line-height:20px;color:#253349}.invoice-detail-page .info-grid .full{grid-column:1/-1}.invoice-detail-page .side-panels{grid-template-rows:108px 1fr;gap:12px}.invoice-detail-page .compact-panel{padding:12px 16px}.invoice-detail-page .match-table-panel{margin-top:16px;padding:14px 16px 12px}.invoice-detail-page .panel-title{margin-bottom:12px}.invoice-detail-page .detail-table ::v-deep .el-table__empty-block{min-height:32px}.invoice-detail-page .match-total-row{grid-template-columns:50px 96px 136px 180px 96px 120px 120px 74px 82px 138px 88px;min-width:0}.invoice-detail-page .bottom-grid{margin-top:12px}.invoice-detail-page .attachments-panel,.invoice-detail-page .log-panel{min-height:243px;padding:14px 16px}.invoice-detail-page .log-table ::v-deep .el-table__body-wrapper{max-height:132px;overflow-y:auto}
</style>
