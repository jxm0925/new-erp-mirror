<template>
  <section class="relation-page">
    <header class="page-heading">
      <div><h1>SKU–Item默认关系</h1><p>维护实物 SKU 唯一有效默认 Item，并保留变更历史</p></div>
      <div class="heading-actions"><el-button v-if="$can('sku_item_relation.audit')" type="success" plain @click="$router.push('/master/sku-item-relations/integrity-check')">关系完整性检查</el-button><el-button v-if="$can('sku_item_relation.set') || $can('sku_item_relation.change')" type="success" @click="openSelected">设置默认Item</el-button></div>
    </header>

    <section class="filters card">
      <label>Product<el-select v-model="filters.product_id" clearable filterable placeholder="请选择"><el-option v-for="p in products" :key="p.id" :label="`${p.product_code || '-'}｜${p.product_name}`" :value="p.id" /></el-select></label>
      <label>SKU关键词<el-input v-model="filters.sku_keyword" placeholder="请输入" clearable /></label>
      <label>Item关键词<el-input v-model="filters.item_keyword" placeholder="请输入" clearable /></label>
      <label>SKU订单行类型<el-select v-model="filters.line_type" clearable placeholder="请选择"><el-option label="实物" value="physical"/><el-option label="服务" value="service"/><el-option label="无需发货" value="no_delivery"/></el-select></label>
      <label>SKU状态<el-select v-model="filters.sku_status" clearable placeholder="请选择"><el-option label="启用" value="enabled"/><el-option label="停用" value="disabled"/></el-select></label>
      <label>默认Item状态<el-select v-model="filters.item_status" clearable placeholder="请选择"><el-option label="启用" value="enabled"/><el-option label="停用" value="disabled"/></el-select></label>
      <label>关系状态<el-select v-model="filters.relation_status" clearable placeholder="请选择"><el-option label="正常" value="normal"/><el-option label="缺失" value="missing"/><el-option label="异常" value="abnormal"/><el-option label="无需Item" value="not_required"/></el-select></label>
      <div class="filter-actions"><el-button type="success" @click="search">查询</el-button><el-button @click="reset">重置</el-button></div>
    </section>

    <section class="stat-grid">
      <div class="stat-card green"><i class="stat-icon el-icon-goods"></i><div><small>实物SKU</small><b>{{ summary.physical || 0 }}</b></div></div>
      <div class="stat-card blue"><i class="stat-icon el-icon-circle-check"></i><div><small>已设置默认Item</small><b>{{ summary.configured || 0 }}</b></div></div>
      <div class="stat-card orange"><i class="stat-icon el-icon-warning-outline"></i><div><small>缺少默认Item</small><b>{{ summary.missing || 0 }}</b></div></div>
      <div class="stat-card red"><i class="stat-icon el-icon-circle-close"></i><div><small>关系异常</small><b>{{ summary.abnormal || 0 }}</b></div></div>
      <div class="stat-card gray"><i class="stat-icon el-icon-remove-outline"></i><div><small>无需Item</small><b>{{ summary.not_required || 0 }}</b></div></div>
    </section>

    <section class="list-layout">
      <div class="table-card">
        <el-table v-loading="loading" :data="rows" border size="small" @row-click="selected = $event" :row-class-name="rowClass">
          <el-table-column prop="sku.sku_code" label="SKU编码" min-width="90" show-overflow-tooltip/>
          <el-table-column prop="sku.sku_name" label="SKU名称" min-width="110" show-overflow-tooltip/>
          <el-table-column label="Product" min-width="115" show-overflow-tooltip><template slot-scope="{row}">{{ productText(row) }}</template></el-table-column>
          <el-table-column label="订单行类型" width="84"><template slot-scope="{row}">{{ lineType(row.line_type) }}</template></el-table-column>
          <el-table-column label="默认Item编码" min-width="100" show-overflow-tooltip><template slot-scope="{row}">{{ row.default_item?.item_code || '—' }}</template></el-table-column>
          <el-table-column label="默认Item名称" min-width="115" show-overflow-tooltip><template slot-scope="{row}">{{ row.default_item?.item_name || '—' }}</template></el-table-column>
          <el-table-column label="Item状态" width="74"><template slot-scope="{row}"><span :class="['tag',row.default_item?.status==='enabled'?'on':row.default_item?.status==='disabled'?'off':'blank']">{{ row.default_item?.status==='enabled'?'启用':row.default_item?.status==='disabled'?'停用':'—' }}</span></template></el-table-column>
          <el-table-column label="关系状态" width="78"><template slot-scope="{row}"><span :class="['tag',{normal:row.audit.check_status==='normal',missing:row.audit.check_status==='missing',bad:['duplicate','item_disabled','wrong_binding'].includes(row.audit.check_status),none:row.audit.check_status==='not_required'}]">{{ relationLabel(row.audit.check_status) }}</span></template></el-table-column>
          <el-table-column label="生效时间" width="96"><template slot-scope="{row}">{{ date(row.default_relation?.effective_at) }}</template></el-table-column>
          <el-table-column label="更新时间" width="96"><template slot-scope="{row}">{{ date(row.default_relation?.updated_at) }}</template></el-table-column>
          <el-table-column label="操作" width="160"><template slot-scope="{row}"><el-button v-if="canSet(row)" type="text" @click.stop="openSet(row)">{{ row.default_item ? '更换默认Item' : '设置默认Item' }}</el-button><el-button type="text" @click.stop="detail(row)">查看历史</el-button></template></el-table-column>
        </el-table>
        <div class="pager"><span>共 {{ total }} 条</span><el-pagination background layout="sizes, prev, pager, next, jumper" :current-page="page" :page-size="perPage" :page-sizes="[10,20,50,100]" :total="total" @current-change="go" @size-change="resize"/></div>
      </div>
      <aside class="explain-card"><h3>默认Item关系说明</h3><ul><li>每个实物 SKU 只能有一个默认 Item。</li><li>物料类型的实物 SKU 必须设置默认 Item。</li><li>服务或无需交付的 SKU 无需设置 Item。</li><li>当默认 Item 发生变更时，系统将保留历史记录。</li><li>关系异常的 SKU 需要及时处理以确保主数据完整性。</li></ul></aside>
    </section>
  </section>
</template>

<script>
import { listDefaultSkuItemRelations, listEntity } from '../../../api/erp/master'
export default { name:'SkuItemRelationList',data(){return{loading:false,rows:[],products:[],selected:null,page:1,perPage:10,total:0,summary:{},filters:{product_id:'',sku_keyword:'',item_keyword:'',line_type:'',sku_status:'',item_status:'',relation_status:''}}},created(){this.loadProducts();this.load()},methods:{async loadProducts(){const {data}=await listEntity('products',{per_page:100});this.products=data.data||[]},async load(){this.loading=true;try{const {data}=await listDefaultSkuItemRelations({...this.filters,page:this.page,per_page:this.perPage});this.rows=data.data||[];this.total=data.total||0;this.summary=data.summary||{};if(this.selected)this.selected=this.rows.find(r=>r.sku&&this.selected.sku&&r.sku.id===this.selected.sku.id)||null;else this.selected=null}catch(e){this.$message.error(e.userMessage||'默认关系加载失败')}finally{this.loading=false}},search(){this.page=1;this.load()},reset(){Object.keys(this.filters).forEach(k=>this.filters[k]='');this.search()},go(p){this.page=p;this.load()},resize(n){this.perPage=n;this.page=1;this.load()},productText(r){return r.product?`${r.product.product_code||'-'}｜${r.product.product_name}`:'—'},lineType(v){return({physical:'实物',service:'服务',no_delivery:'无需发货'})[v]||'—'},relationLabel(v){return({normal:'正常',missing:'缺失',duplicate:'异常',item_disabled:'异常',wrong_binding:'异常',not_required:'无需Item'})[v]||'—'},date(v){return v?String(v).slice(0,16).replace('T',' '):'—'},canSet(r){return r.line_type==='physical'&&this.$can(r.default_item?'sku_item_relation.change':'sku_item_relation.set')},openSelected(){if(!this.selected)return this.$message.warning('请先选择一个实物 SKU');this.openSet(this.selected)},openSet(r){if(!this.canSet(r))return;this.$router.push(`/master/sku-item-relations/${r.sku.id}/set-primary`)},detail(r){this.$router.push(`/master/sku-item-relations/${r.sku.id}`)},rowClass({row}){return this.selected&&row.sku.id===this.selected.sku.id?'active-row':''}}}
</script>

<style scoped>
.relation-page{padding:22px 24px;background:#fff;min-height:calc(100vh - 56px);color:#172033}.page-heading{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.page-heading h1{font-size:26px;margin:0 0 7px;font-weight:700}.page-heading p{margin:0;color:#667085;font-size:14px}.heading-actions{display:flex;gap:12px}.card,.table-card,.explain-card,.stat-card{background:#fff;border:1px solid #e4e9ef;border-radius:8px}.filters{padding:17px 18px;display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:16px 32px;align-items:end}.filters label{font-size:13px;color:#4b5565;display:grid;gap:8px}.filters .el-select,.filters .el-input{width:100%}.filter-actions{justify-self:end;display:flex;gap:12px}.stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:20px;margin:14px 0}.stat-card{height:84px;display:flex;align-items:center;gap:16px;padding:0 20px}.stat-icon{width:44px;font-size:38px;line-height:1;text-align:center;flex-shrink:0}.stat-card small{color:#596579;display:block;margin-bottom:5px}.stat-card b{font-size:24px}.green .stat-icon,.green b{color:#0b9b4a}.blue .stat-icon,.blue b{color:#2563eb}.orange .stat-icon,.orange b{color:#ed7b00}.red .stat-icon,.red b{color:#e33131}.gray .stat-icon,.gray b{color:#687282}.list-layout{display:grid;grid-template-columns:minmax(0,1fr) 286px;gap:20px}.table-card{overflow:hidden}.pager{padding:16px 4px 4px;display:flex;align-items:center;justify-content:space-between;color:#4b5565}.explain-card{padding:20px;min-height:600px}.explain-card h3{margin:0 0 22px;font-size:18px}.explain-card ul{padding-left:20px;margin:0}.explain-card li{font-size:14px;line-height:1.75;margin-bottom:17px}.tag{display:inline-block;border-radius:4px;padding:2px 7px;font-size:12px;line-height:18px}.on,.normal{color:#0c8e45;background:#ecf9f0;border:1px solid #b7e4c6}.off,.bad{color:#dd3b30;background:#fff0ef;border:1px solid #ffc9c5}.missing{color:#e87c18;background:#fff7ed;border:1px solid #fde0b6}.none{color:#657084;background:#f0f2f5;border:1px solid #dce1e8}.blank{color:#8b95a4;background:#f5f6f8}::v-deep .el-table th{background:#f6f8fb;color:#374151;font-weight:600}::v-deep .active-row td{background:#f1fbf5!important}@media(max-width:1300px){.filters{grid-template-columns:repeat(3,minmax(150px,1fr))}.list-layout{grid-template-columns:min-width(880px,1fr)}}
::v-deep .table-card .el-table{min-width:1110px;color:#283548}::v-deep .table-card .el-table__cell{padding:12px 0}::v-deep .table-card .cell{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#283548}::v-deep .table-card .el-table__body tr:hover>td{background:#f4fbf7}.table-card{overflow-x:auto!important;overflow-y:hidden}.explain-card{min-width:286px;color:#263346}.explain-card li{color:#354153}.pager{min-width:1110px;padding:18px 14px}.relation-page{min-width:1220px}@media(max-width:1500px){.relation-page{overflow-x:auto}.list-layout{grid-template-columns:minmax(1110px,1fr) 286px}.filters{min-width:1110px}}
</style>
