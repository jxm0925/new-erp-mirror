<template>
  <section class="exchange-list">
    <div class="crumb">采购管理　/　不合格品处理　/　<b>采购换货单</b></div>
    <div class="page-actions">
      <el-button size="small" icon="el-icon-back" @click="$router.push('/purchase/defects')">返回不合格品处理</el-button>
      <el-button size="small" icon="el-icon-refresh" @click="load">刷新</el-button>
    </div>

    <div class="list-grid">
      <main>
        <div class="stats">
          <div v-for="card in statCards" :key="card.key" class="stat-card">
            <i :class="card.icon" :style="{color:card.color,background:card.bg}" />
            <span>{{ card.label }}<b>{{ stats[card.key] || 0 }}</b></span>
          </div>
        </div>
        <div class="filters">
          <label>换货单号<el-input v-model="query.exchange_no" size="small" placeholder="请输入换货单号" clearable /></label>
          <label>原到货单号<el-input v-model="query.source_receipt_no" size="small" placeholder="请输入原到货单号" clearable /></label>
          <label>采购订单号<el-input v-model="query.purchase_order_no" size="small" placeholder="请输入采购订单号" clearable /></label>
          <label>供应商<el-select v-model="query.supplier_id" size="small" filterable clearable placeholder="请选择供应商"><el-option v-for="s in suppliers" :key="s.id" :label="s.supplier_name" :value="s.id" /></el-select></label>
          <label>物料/SKU<el-input v-model="query.keyword" size="small" placeholder="请输入物料名称或SKU" clearable /></label>
          <label>状态<el-select v-model="query.current_step" size="small" clearable placeholder="请选择状态"><el-option v-for="o in stepOptions" :key="o.value" :label="o.label" :value="o.value" /></el-select></label>
          <label class="date">日期<el-date-picker v-model="query.dateRange" size="small" type="daterange" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期" value-format="yyyy-MM-dd" /></label>
          <div class="filter-buttons"><el-button size="small" type="success" @click="search">查询</el-button><el-button size="small" @click="reset">重置</el-button></div>
        </div>
        <div class="table-card">
          <el-table v-loading="loading" :data="rows" border size="mini" empty-text="暂无采购换货单">
            <el-table-column prop="exchange_no" label="换货单号" min-width="150" />
            <el-table-column label="来源不合格处理单" min-width="160"><template slot-scope="{row}">{{ row.handling && row.handling.handling_no || '--' }}</template></el-table-column>
            <el-table-column label="原采购订单/到货单" min-width="185"><template slot-scope="{row}"><div>{{ row.purchase_order && row.purchase_order.purchase_order_no || '--' }}</div><div>{{ row.source_receipt && row.source_receipt.receipt_no || '--' }}</div></template></el-table-column>
            <el-table-column label="供应商" min-width="170"><template slot-scope="{row}">{{ row.supplier && row.supplier.supplier_name || '--' }}</template></el-table-column>
            <el-table-column label="物料" min-width="180"><template slot-scope="{row}">{{ row.item && row.item.item_name || '--' }}</template></el-table-column>
            <el-table-column label="换货数量/单位" min-width="110"><template slot-scope="{row}">{{ qty(row.exchange_base_qty) }} {{ row.base_unit_name_snapshot }}</template></el-table-column>
            <el-table-column label="原货退回状态" min-width="110"><template slot-scope="{row}"><span :class="tone(row.returned_at)">{{ row.returned_at ? '已退回' : '待退回' }}</span></template></el-table-column>
            <el-table-column label="补发状态" min-width="100"><template slot-scope="{row}"><span :class="tone(hasReplacementShipped(row))">{{ hasReplacementShipped(row) ? '已补发' : '待补发' }}</span></template></el-table-column>
            <el-table-column label="替换到货单" min-width="155"><template slot-scope="{row}"><a v-if="row.replacement_receipt" @click="$router.push(`/purchase/receipts/${row.replacement_receipt.id}/edit`)">{{ row.replacement_receipt.receipt_no }}</a><span v-else>--</span></template></el-table-column>
            <el-table-column label="金额口径" min-width="125"><template>替换不新增应付</template></el-table-column>
            <el-table-column label="当前节点" min-width="130"><template slot-scope="{row}">{{ stepText(row.current_step) }}</template></el-table-column>
            <el-table-column label="更新时间" min-width="150"><template slot-scope="{row}">{{ dateTime(row.updated_at) }}</template></el-table-column>
            <el-table-column label="操作" width="76" fixed="right"><template slot-scope="{row}"><el-button type="text" size="mini" @click="$router.push(`/purchase/exchanges/${row.id}`)">{{ row.current_step === 'completed' ? '查看' : '处理' }}</el-button></template></el-table-column>
          </el-table>
          <div class="pager"><span>共 {{ pagination.total }} 条</span><el-pagination small layout="sizes, prev, pager, next, jumper" :page-sizes="[10,20,50,100]" :current-page.sync="pagination.page" :page-size.sync="pagination.per_page" :total="pagination.total" @current-change="load" @size-change="sizeChange" /></div>
        </div>
      </main>
      <aside class="rules"><h3>换货金额与库存规则</h3><ul><li>原采购应付不重复增加</li><li>原不合格品不得进入可用库存</li><li>替换到货金额为0但按原采购成本入库</li><li>原品与替换品序列号完整关联</li></ul></aside>
    </div>
  </section>
</template>

<script>
import { listEntity } from '@/api/erp/master'
import { listPurchaseExchangeOrders } from '@/api/erp/purchase'
export default {
  name: 'PurchaseExchangeList',
  data() { return { loading:false, rows:[], suppliers:[], stats:{}, query:{exchange_no:'',source_receipt_no:'',purchase_order_no:'',supplier_id:'',keyword:'',current_step:'',dateRange:[]}, pagination:{page:1,per_page:20,total:0}, statCards:[{key:'pending_original_return',label:'待原货退回',icon:'el-icon-position',color:'#ee9d22',bg:'#fff1d9'},{key:'supplier_receipt_pending',label:'待供应商收货',icon:'el-icon-truck',color:'#3275e6',bg:'#e8f0ff'},{key:'pending_replacement_shipment',label:'待补发',icon:'el-icon-s-promotion',color:'#37a64a',bg:'#e7f7ea'},{key:'pending_replacement_acceptance',label:'待替换品验收',icon:'el-icon-s-claim',color:'#7255d9',bg:'#f0ebff'},{key:'completed',label:'已完成',icon:'el-icon-circle-check',color:'#2ab0aa',bg:'#e4f8f6'}], stepOptions:[{value:'pending_original_return',label:'待原货退回'},{value:'supplier_receipt_pending',label:'待供应商收货'},{value:'pending_replacement_shipment',label:'待补发'},{value:'replacement_in_transit',label:'替换品运输中'},{value:'pending_replacement_acceptance',label:'替换品待验收'},{value:'completed',label:'已完成'}] } },
  created(){ this.loadSuppliers(); this.load() },
  methods:{ async loadSuppliers(){ const r=await listEntity('suppliers',{per_page:100});this.suppliers=r.data.data||[] }, async load(){this.loading=true;try{const r=await listPurchaseExchangeOrders({...this.query,page:this.pagination.page,per_page:this.pagination.per_page,date_from:this.query.dateRange&&this.query.dateRange[0],date_to:this.query.dateRange&&this.query.dateRange[1]});this.stats=r.data.stats||{};const p=r.data.data||{};this.rows=p.data||[];this.pagination.total=Number(p.total||0);this.pagination.page=Number(p.current_page||1)}finally{this.loading=false}}, search(){this.pagination.page=1;this.load()},reset(){this.query={exchange_no:'',source_receipt_no:'',purchase_order_no:'',supplier_id:'',keyword:'',current_step:'',dateRange:[]};this.search()},sizeChange(v){this.pagination.per_page=v;this.search()},qty(v){return Number(v||0).toLocaleString('zh-CN',{maximumFractionDigits:8})},dateTime(v){return v?String(v).replace('T',' ').slice(0,19):'--'},tone(v){return v?'ok':'wait'},hasReplacementShipped(row){return Boolean(row.replacement_shipped_at||row.replacement_receipt_id||row.replacement_receipt||['replacement_in_transit','pending_replacement_acceptance','completed'].includes(row.current_step))},stepText(v){return Object.fromEntries(this.stepOptions.map(x=>[x.value,x.label]))[v]||v||'--'} }
}
</script>

<style scoped>
.exchange-list{box-sizing:border-box;width:100%;min-width:0;min-height:calc(100vh - 52px);padding:14px 18px;background:#f7f8fa;color:#27323b}.crumb{height:28px;color:#6e7a85;font-size:12px}.page-actions{height:48px;display:flex;gap:10px;align-items:center;border-bottom:1px solid #e6eaed}.list-grid{display:grid;grid-template-columns:minmax(0,1fr) 250px;gap:16px;padding-top:14px}.list-grid main{min-width:0}.stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px}.stat-card{box-sizing:border-box;height:76px;min-width:0;padding:10px 16px;display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #dde4e8;border-radius:4px}.stat-card i{width:42px;height:42px;flex:0 0 42px;border-radius:50%;display:grid;place-items:center;font-size:22px}.stat-card span{min-width:0;font-size:12px}.stat-card b{display:block;margin-top:4px;font-size:22px}.filters{margin:12px 0;padding:14px 16px;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;background:#fff;border:1px solid #dde4e8;border-radius:4px}.filters label{min-width:0;display:grid;gap:6px;font-size:12px;font-weight:600}.filters .date{grid-column:span 2}.filters .date .el-date-editor{width:100%}.filter-buttons{align-self:end;display:flex;gap:8px}.table-card{min-width:0;background:#fff;border:1px solid #dde4e8;border-radius:4px;overflow:hidden}.table-card a{color:#2473db;cursor:pointer}.pager{min-height:48px;padding:0 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.rules{box-sizing:border-box;height:max-content;padding:20px;background:#fff;border:1px solid #dde4e8;border-radius:4px}.rules h3{margin:0 0 20px;font-size:15px}.rules ul{margin:0;padding:0 0 0 20px;display:grid;gap:20px;line-height:1.7}.rules li::marker{color:#3fb648}.ok{color:#1b9d50}.wait{color:#ef8d18}@media(max-width:1400px){.list-grid{grid-template-columns:1fr}.rules{display:none}.filters{grid-template-columns:repeat(4,minmax(0,1fr))}}@media(max-width:1000px){.exchange-list{padding:12px}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.filters{grid-template-columns:repeat(2,minmax(0,1fr))}.filters .date{grid-column:span 2}}
</style>
