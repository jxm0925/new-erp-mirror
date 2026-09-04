<template>
  <section class="sku-editor" v-loading="loading">
    <product-sku-picker ref="productPicker" @select="selectProduct" />
    <header class="page-header">
      <h1>SKU管理 / {{ completionMode ? 'SKU资料补全' : (editing ? '编辑SKU' : '新增SKU') }}</h1>
      <div class="header-actions">
        <el-button size="small" @click="back">返回列表</el-button>
        <template v-if="completionMode"><el-button size="small" @click="save('draft')">保存草稿</el-button><el-button size="small" type="success" @click="save('enabled')">补全并启用</el-button></template>
        <template v-else><el-button v-if="!editing || form.status === 'draft'" size="small" @click="save('draft')">保存草稿</el-button><el-button v-if="!editing || form.status !== 'enabled'" size="small" type="success" @click="save('enabled')">{{ editing ? '重新启用' : '保存并启用' }}</el-button><el-button v-if="editing && form.status === 'enabled'" size="small" type="success" @click="save('enabled')">保存修改</el-button><el-button v-if="editing && form.status === 'enabled'" size="small" type="danger" plain @click="save('disabled')">停用</el-button></template>
      </div>
    </header>

    <el-alert v-if="completionMode" class="rule-alert" title="当前 SKU 的新系统资料尚未完整维护。补全并启用后，才能用于新的销售订单、库存、BOM 和生产业务。" type="warning" :closable="false" show-icon />
    <el-alert v-else class="rule-alert" title="订单属性仅控制销售订单行显示和必填，不参与 SKU、Item、BOM 或工艺路线匹配。" type="info" :closable="false" show-icon />

    <div class="editor-layout">
      <div class="editor-main">
        <section class="panel basic-panel">
          <h2>基础规格</h2>
          <div class="basic-grid">
            <div class="form-column">
              <div class="form-row"><label><i>*</i> SKU编码</label><el-input v-model="form.sku_code" size="small" disabled placeholder="正在预生成"><template slot="append">系统预生成</template></el-input></div>
              <div class="form-row"><label><i>*</i> SKU名称</label><el-input v-model="form.sku_name" size="small" /></div>
              <div class="form-row"><label><i>*</i> 所属Product</label><el-input :value="productText" size="small" readonly placeholder="请选择启用状态 Product"><el-button slot="append" icon="el-icon-search" @click="$refs.productPicker.openProduct()">选择</el-button></el-input></div>
              <div class="form-row"><label><i>*</i> 规格型号</label><el-input v-model="form.spec_model" size="small" /></div>
              <div class="form-row sales-unit-row"><label><i>*</i> 销售单位</label><div><el-select v-model="form.sales_unit_id" :disabled="form.sales_unit_locked" size="small" class="fill"><el-option v-for="u in salesUnitOptions" :key="u.id" :value="u.id" :label="unitLabel(u)" /></el-select><small>销售订单数量按此单位录入，小数位数 {{ selectedUnitPlaces }}</small><p v-if="form.sales_unit_locked" class="unit-lock"><i class="el-icon-lock" /> 已有已确认订单，销售单位不可修改</p></div></div>
              <div class="form-row"><label>销售价格</label><el-input-number v-model="form.sale_price" size="small" :min="0" :precision="2" controls-position="right" class="price-input" /><span class="unit-suffix">CNY</span></div>
              <div class="form-row image-row">
                <label>SKU 图片</label>
                <div class="image-editor">
                  <el-image v-if="form.image" class="sku-image-preview" :src="imageUrl" fit="cover" :preview-src-list="[imageUrl]" />
                  <div v-else class="sku-image-placeholder"><i class="el-icon-picture-outline" /><span>暂无图片</span></div>
                  <div class="image-actions">
                    <el-upload action="#" :show-file-list="false" accept="image/jpeg,image/png,image/webp,image/gif" :before-upload="validateImage" :http-request="handleImageUpload">
                      <el-button size="mini" plain icon="el-icon-upload2">{{ form.image ? '替换图片' : '上传图片' }}</el-button>
                    </el-upload>
                    <el-button v-if="form.image" size="mini" type="text" class="clear-image" @click="clearImage">清除</el-button>
                    <span>JPG / PNG / WebP / GIF，≤ 5MB</span>
                  </div>
                </div>
              </div>
            </div>
            <div class="form-column line-config">
              <div class="form-row vertical"><label><i>*</i> 订单行类型</label><el-radio-group v-model="form.line_type"><el-radio label="physical">实物</el-radio><el-radio label="service">服务</el-radio><el-radio label="no_delivery">无需发货</el-radio></el-radio-group></div>
              <div class="form-row vertical"><label><i>*</i> 是否允许销售</label><el-radio-group v-model="form.is_sellable"><el-radio :label="true">是</el-radio><el-radio :label="false">否</el-radio></el-radio-group></div>
            </div>
          </div>
        </section>

        <section class="panel attribute-panel">
          <h2>订单属性支持</h2>
          <p class="section-note">仅控制销售订单中字段的可见性与必填性，不参与SKU与Item/BOM/工艺路线的匹配。</p>
          <div class="attribute-row"><label>电压</label><el-select v-model="form.electric_mode" size="small"><el-option label="不显示" value="hidden" /><el-option label="可选" value="optional" /><el-option label="必填" value="required" /></el-select><span>订单行中“电压”字段按此设置显示；必填时未填写不可提交销售订单。</span></div>
          <div class="attribute-row"><label>原水泵控制</label><el-select v-model="form.need_pump_mode" size="small"><el-option label="不显示" value="hidden" /><el-option label="可选" value="optional" /><el-option label="必填" value="required" /></el-select><span>订单行中“原水泵控制”字段按此设置显示，客户可按需选择或留空。</span></div>
        </section>

        <section class="panel custom-panel">
          <h2>定制与交付要求</h2>
          <p class="section-note">仅控制订单行的显示、资料门禁与交付前检验要求，不参与 SKU、Item、BOM 或工艺路线匹配。</p>
          <div class="custom-grid">
            <div class="form-row vertical"><label>是否允许普通定制</label><el-radio-group v-model="form.allow_customized"><el-radio :label="true">是</el-radio><el-radio :label="false">否</el-radio></el-radio-group></div>
            <div class="form-row vertical"><label>是否允许特殊定制</label><el-radio-group v-model="form.allow_special_customized"><el-radio :label="true">是</el-radio><el-radio :label="false">否</el-radio></el-radio-group></div>
            <template v-if="form.allow_special_customized">
              <div class="form-row vertical"><label>特殊定制必须上传设计图纸</label><el-switch v-model="form.special_custom_drawing_required" /></div>
              <div class="form-row vertical"><label>特殊定制必须上传客户技术协议</label><el-switch v-model="form.special_custom_agreement_required" /></div>
              <div class="form-row vertical"><label>特殊定制必须填写配置说明</label><el-switch v-model="form.special_custom_description_required" /></div>
            </template>
            <div class="form-row vertical"><label>是否要求交付前检验</label><el-radio-group v-model="form.delivery_inspection_required"><el-radio :label="true">需要</el-radio><el-radio :label="false">不需要</el-radio></el-radio-group></div>
          </div>
        </section>

        <section v-if="form.line_type === 'physical'" class="panel item-panel">
          <h2>默认Item关系</h2>
          <p class="section-note">实物SKU保存草稿可暂不选择Item；保存并启用必须绑定有效默认Item。服务和无需发货SKU不要求绑定Item。</p>
          <div class="item-picker-line"><label>默认Item</label><el-input size="small" readonly :value="defaultItemText" placeholder="待从启用 Item 中选择" @click.native="picker=true"><el-button slot="append" icon="el-icon-search" @click="picker=true">选择</el-button></el-input></div>
          <el-table :data="defaultRelation ? [defaultRelation.item] : []" size="small" border class="item-summary-table" empty-text="暂未设置默认Item">
            <el-table-column prop="item_code" label="Item编码" min-width="150" />
            <el-table-column prop="item_name" label="Item名称" min-width="190" />
            <el-table-column prop="spec" label="规格型号" min-width="150" />
            <el-table-column prop="status" label="Item状态" min-width="110" />
          </el-table>
        </section>
        <section v-else class="panel item-not-required">当前订单行类型无需绑定Item。</section>
      </div>

      <aside class="preview-panel">
        <h2>订单行属性预览</h2>
        <p class="preview-help">以下为销售订单中该SKU订单行的字段效果预览。</p>
        <div class="preview-field"><label>Product</label><span>{{ productText }}</span></div>
        <div class="preview-field"><label>SKU</label><span>{{ form.sku_code || '—' }}</span></div>
        <div class="preview-field"><label>数量</label><div>请输入数量 <b>{{ selectedUnitName || '—' }}</b></div></div>
        <div class="preview-field"><label>单价</label><div>{{ form.sale_price === null || form.sale_price === '' ? '—' : form.sale_price }} <b>CNY</b></div></div>
        <div v-if="form.electric_mode !== 'hidden'" class="preview-field"><label>电压 <i v-if="form.electric_mode === 'required'">*</i></label><div>请选择（{{ form.electric_mode === 'required' ? '必填' : '可选' }}）</div></div>
        <div v-if="form.need_pump_mode !== 'hidden'" class="preview-field"><label>原水泵控制 <i v-if="form.need_pump_mode === 'required'">*</i></label><div>请选择（{{ form.need_pump_mode === 'required' ? '必填' : '可选' }}）</div></div>
        <div v-if="form.allow_customized" class="preview-field"><label>普通定制</label><div>订单行可选择</div></div>
        <template v-if="form.allow_special_customized">
          <div class="preview-field"><label>特殊定制</label><div>订单行可选择</div></div>
          <div v-if="form.special_custom_drawing_required" class="preview-field"><label>设计图纸 <i>*</i></label><div>特殊定制时必传</div></div>
          <div v-if="form.special_custom_agreement_required" class="preview-field"><label>客户技术协议 <i>*</i></label><div>特殊定制时必传</div></div>
          <div v-if="form.special_custom_description_required" class="preview-field"><label>配置说明 <i>*</i></label><div>特殊定制时必填</div></div>
        </template>
        <div v-if="form.delivery_inspection_required" class="preview-field"><label>交付前检验</label><div>需要</div></div>
        <div class="preview-note"><strong><i class="el-icon-info" /> 通用订单行字段说明</strong><p>除以上字段外，销售订单行还将显示系统通用字段，如交货日期、备注、客户要求等。</p></div>
      </aside>
    </div>

    <el-dialog title="选择默认Item" :visible.sync="picker" width="820px" append-to-body>
      <div class="picker-toolbar"><el-input v-model="itemQuery.keyword" size="small" placeholder="Item编码 / 名称 / 规格" @keyup.enter.native="loadItems" /><el-select v-model="itemQuery.item_type" size="small" clearable placeholder="Item类型"><el-option label="成品" value="finished_product" /><el-option label="半成品" value="semi_finished" /><el-option label="原材料" value="raw_material" /><el-option label="包装物" value="packaging" /><el-option label="服务" value="service" /></el-select><el-button size="small" type="primary" @click="searchItems">查询</el-button></div>
      <el-table :data="items" size="small" border @row-dblclick="chooseItem"><el-table-column prop="item_code" label="Item编码" /><el-table-column prop="item_name" label="Item名称" /><el-table-column prop="spec" label="规格型号" /><el-table-column label="Item类型"><template slot-scope="{ row }">{{ itemTypeText(row.item_type) }}</template></el-table-column><el-table-column label="库存单位"><template slot-scope="{ row }">{{ row.unit && row.unit.unit_name || '—' }}</template></el-table-column><el-table-column label="状态"><template slot-scope="{ row }"><el-tag type="success" size="mini">{{ row.status === 'enabled' ? '启用' : '停用' }}</el-tag></template></el-table-column><el-table-column label="操作" width="90"><template slot-scope="scope"><el-button type="text" size="small" @click="chooseItem(scope.row)">选择</el-button></template></el-table-column></el-table>
      <el-pagination small layout="total, prev, pager, next" :current-page="itemQuery.page" :page-size="itemQuery.per_page" :total="itemTotal" @current-change="changeItemPage" />
    </el-dialog>
  </section>
</template>

<script>
import { listEntity, getEntity, saveEntity, replacePrimaryRelation, uploadSkuImage } from '../../../api/erp/master'
import { legacyMediaUrl } from '../../../utils/legacyMedia'
import { reserveForCreatePage, clearCreatePageReservation } from '../../../utils/documentNumberReservation'
import ProductSkuPicker from '../../../components/sales/ProductSkuPicker.vue'
const empty = () => ({ sku_code:'', sku_name:'', product_id:null, spec_model:'', image:'', sales_unit_id:null, sale_price:null, line_type:'physical', is_sellable:true, allow_customized:false, allow_special_customized:false, special_custom_drawing_required:false, special_custom_agreement_required:false, special_custom_description_required:false, delivery_inspection_required:false, electric_mode:'hidden', need_pump_mode:'hidden', status:'draft' })
export default {
  name:'SkuForm',
  components: { ProductSkuPicker },
  data(){ return { loading:false, reservation:null, form:empty(), products:[], units:[], defaultRelation:null, picker:false, items:[], itemTotal:0, itemQuery:{ keyword:'', item_type:'', page:1, per_page:10 } } },
  computed:{
    editing(){ return !!this.$route.params.id },
    productText(){ const p=this.products.find(x=>x.id===this.form.product_id); return p ? `${p.product_code}｜${p.product_name}` : '—' },
    defaultItemText(){ return this.defaultRelation && this.defaultRelation.item ? `${this.defaultRelation.item.item_code} | ${this.defaultRelation.item.item_name}` : '' },
    currentSalesUnit(){ return this.units.find(x=>Number(x.id)===Number(this.form.sales_unit_id)) || (this.form.sales_unit && Number(this.form.sales_unit.id)===Number(this.form.sales_unit_id) ? this.form.sales_unit : null) },
    salesUnitOptions(){ return this.currentSalesUnit && this.currentSalesUnit.is_legacy ? [this.currentSalesUnit,...this.units] : this.units },
    selectedUnitName(){ const unit=this.canonicalUnit(this.currentSalesUnit); return unit ? (unit.symbol || unit.unit_name) : '—' },
    selectedUnitPlaces(){ const unit=this.canonicalUnit(this.currentSalesUnit); return Number(unit && unit.decimal_places || 0) },
    imageUrl(){ return legacyMediaUrl(this.form.image) },
    completionMode(){ return this.$route.path.endsWith('/complete') || (this.editing && this.form.status === 'draft' && !this.isOrderReady) },
    isOrderReady(){ return !!(this.form.sales_unit_id && this.form.sale_price !== null && this.form.sale_price !== '' && (this.form.line_type !== 'physical' || this.defaultRelation)) }
  },
  created(){ this.init() },
  methods:{
    back(){ this.$router.push(this.$route.query.from === 'product' ? '/master/products' : '/master/skus') },
    canonicalUnit(unit){ return unit && (unit.standard_unit || unit.standardUnit || unit) },
    unitLabel(unit){ const current=this.canonicalUnit(unit); return current ? `${current.unit_code || ''} ${current.unit_name || ''} (${current.symbol || current.unit_name || ''})`.trim() : '—' },
    async init(){
      this.loading=true
      try {
        const [products,units]=await Promise.all([listEntity('products',{status:'enabled',page:1,per_page:50}),listEntity('units',{status:'enabled',page:1,per_page:50})])
        this.products=products.data.data||[]
        this.units=units.data.data||[]
        if(this.editing){
          const r=await getEntity('skus',this.$route.params.id)
          this.form={...empty(),...r.data, spec_model:r.data.spec_model || r.data.spec_text || '', line_type:r.data.line_type || r.data.order_line_type, is_sellable:r.data.is_sellable}
          if(r.data.product&&!this.products.some(item=>item.id===r.data.product.id)) this.products.push(r.data.product)
          this.defaultRelation=(this.form.item_relations||[]).find(x=>x.status==='active'&&x.is_primary&&x.item&&x.item.status==='enabled')||null
        } else {
          const productId=Number(this.$route.query.product_id||0)
          if(productId){
            let product=this.products.find(item=>Number(item.id)===productId)
            if(!product){
              const response=await getEntity('products',productId)
              product=response.data
              if(product&&product.status==='enabled') this.products.push(product)
            }
            if(product&&product.status==='enabled'){
              this.form.product_id=product.id
              if(product.unit_id) this.form.sales_unit_id=product.unit_id
            }
          }
          this.reservation=await reserveForCreatePage('sku','/master/skus/new')
          this.form.sku_code=this.reservation.document_no
        }
      } catch(error) { this.$message.error(error.userMessage || 'SKU资料加载失败') } finally { this.loading=false }
    },
    selectProduct({ mode, row }){ if(mode !== 'product') return; this.form.product_id=row.id; if(!this.products.some(item=>item.id===row.id)) this.products.push(row) },
    async loadItems(){ const r=await listEntity('items',{...this.itemQuery,status:'enabled'}); this.items=r.data.data||[]; this.itemTotal=r.data.total||0 },
    searchItems(){ this.itemQuery.page=1; this.loadItems() },
    changeItemPage(page){ this.itemQuery.page=page; this.loadItems() },
    itemTypeText(value){ return ({finished_product:'成品',semi_finished:'半成品',raw_material:'原材料',packaging:'包装物',service:'服务'})[value] || value || '—' },
    chooseItem(row){ this.defaultRelation={item:row}; this.picker=false },
    validateImage(file){
      const allowed=['image/jpeg','image/png','image/webp','image/gif']
      if(!allowed.includes(file.type)){ this.$message.error('仅支持 JPG、PNG、WebP、GIF 图片'); return false }
      if(file.size>5*1024*1024){ this.$message.error('图片不能超过 5MB'); return false }
      return true
    },
    async handleImageUpload(option){
      try {
        const data=new FormData(); data.append('image',option.file)
        const response=await uploadSkuImage(data)
        this.form.image=response.data.data.url
        option.onSuccess(response.data)
        this.$message.success('图片上传成功，保存 SKU 后生效')
      } catch(error) { option.onError(error); this.$message.error(error.userMessage||'图片上传失败') }
    },
    clearImage(){ this.form.image='' },
    currentPrimaryItemId(){ const current=(this.form.item_relations||[]).find(x=>x.status==='active'&&x.is_primary&&x.item&&x.item.status==='enabled'); return current&&current.item_id },
    async save(status){
      if(!this.form.sku_code||!this.form.sku_name||!this.form.product_id||!this.form.spec_model) return this.$message.error('请完成 SKU 编码、名称、所属 Product 与规格型号')
      this.loading=true
      try { const selectedItemId=this.defaultRelation&&this.defaultRelation.item&&this.defaultRelation.item.id; const relationChanged=!!(selectedItemId&&Number(selectedItemId)!==Number(this.currentPrimaryItemId())); const stageBeforeEnable=status==='enabled'&&(!this.editing||relationChanged); const firstStatus=stageBeforeEnable?'draft':status; const payload={...this.form,status:firstStatus}; if(!this.editing&&this.reservation){payload.reservation_token=this.reservation.reservation_token;payload.creation_session_id=this.reservation.creation_session_id} const r=await saveEntity('skus',payload); const sku=r.data.data; if(!this.editing)clearCreatePageReservation(this.reservation); if(relationChanged) await replacePrimaryRelation({sku_id:sku.id,item_id:selectedItemId,factor:1,change_reason:this.editing?'主数据修正':'首次设置'}); if(stageBeforeEnable) await saveEntity('skus',{...sku,...this.form,id:sku.id,status:'enabled'}); this.$message.success(status==='enabled'?'SKU已启用':status==='disabled'?'SKU已停用':'保存成功'); this.$router.push('/master/skus/'+sku.id) } catch(e) { this.$message.error(e.userMessage||'保存失败') } finally { this.loading=false }
    }
  },
  watch:{ picker(v){ if(v) this.loadItems() } }
}
</script>

<style scoped>
.sku-editor{min-height:calc(100vh - 52px);padding:16px;background:#f5f7f8;color:#172b4d}.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.page-header h1{margin:0;font-size:18px;font-weight:700}.header-actions{display:flex;gap:10px}.rule-alert{margin-bottom:12px}.editor-layout{display:grid;grid-template-columns:minmax(720px,2.05fr) minmax(330px,1fr);gap:12px;align-items:start}.panel,.preview-panel{background:#fff;border:1px solid #e5eaef;border-radius:3px}.panel{padding:18px;margin-bottom:12px}.panel h2,.preview-panel h2{margin:0 0 14px;padding-left:12px;border-left:4px solid #10a866;font-size:16px;line-height:18px}.basic-grid{display:grid;grid-template-columns:1fr 1fr;gap:30px}.form-column,.custom-grid{display:grid;gap:14px}.custom-grid{grid-template-columns:1fr 1fr}.form-row{position:relative;min-height:32px;display:grid;grid-template-columns:126px 1fr;align-items:center;gap:10px}.form-row label,.attribute-row label,.item-picker-line label{font-size:14px;color:#29415f;text-align:right}.form-row label i,.preview-field i{color:#ef4b4b;font-style:normal}.form-row.vertical{grid-template-columns:126px 1fr;align-items:start}.form-row.vertical label{padding-top:7px}.fill{width:100%}.price-input{width:100%}.unit-suffix{position:absolute;right:11px;bottom:8px;z-index:1;color:#526477;pointer-events:none}.image-row{align-items:start}.image-row>label{padding-top:7px}.image-editor{display:flex;gap:10px;align-items:center;min-height:60px}.sku-image-preview,.sku-image-placeholder{width:58px;height:58px;border:1px solid #dfe6ee;border-radius:4px}.sku-image-placeholder{display:flex;flex-direction:column;align-items:center;justify-content:center;color:#9aa7b4;font-size:11px;background:#f7f9fb}.sku-image-placeholder i{font-size:20px;margin-bottom:3px}.image-actions{display:flex;flex-wrap:wrap;align-items:center;gap:8px}.image-actions>span{width:100%;color:#8894a0;font-size:11px}.clear-image{color:#e16d5b}.section-note{margin:-2px 0 15px;color:#68778c;font-size:13px}.attribute-row{display:grid;grid-template-columns:126px 235px 1fr;gap:10px;align-items:center;min-height:46px}.attribute-row span{font-size:13px;color:#7b8797}.item-picker-line{display:grid;grid-template-columns:126px 1fr;gap:10px;align-items:center;margin:10px 0 14px}.item-summary-table{width:100%}.item-not-required{color:#4d87b5;font-size:13px}.preview-panel{padding:24px;min-height:650px}.preview-panel h2{border:0;padding:0}.preview-help{margin:0 0 18px;color:#64748b;font-size:13px;line-height:1.6}.preview-field{display:grid;grid-template-columns:90px 1fr;gap:10px;align-items:center;margin-bottom:14px;font-size:14px}.preview-field>label{color:#29415f}.preview-field>span,.preview-field>div{min-height:34px;box-sizing:border-box;padding:8px 10px;border:1px solid #dfe6ee;border-radius:3px;background:#f7f9fb;color:#8b98ab}.preview-field b{float:right;margin:-8px -10px -8px 0;padding:8px 10px;border-left:1px solid #dfe6ee;color:#526477;font-weight:400}.preview-note{margin-top:30px;padding:14px;border:1px solid #b8d9ff;border-radius:3px;background:#f5faff;color:#31567d;font-size:13px;line-height:1.7}.preview-note strong{display:block;color:#215b9b}.preview-note i{color:#2387ee}.preview-note p{margin:7px 0 0}.picker-toolbar{display:flex;gap:8px;margin-bottom:12px}.picker-toolbar .el-input{flex:1}.el-pagination{margin-top:14px;text-align:right}@media(max-width:1180px){.editor-layout{grid-template-columns:1fr}.preview-panel{min-height:0}.basic-grid,.custom-grid{gap:18px}}@media(max-width:780px){.basic-grid,.custom-grid{grid-template-columns:1fr}.attribute-row{grid-template-columns:1fr}.attribute-row label,.form-row label,.item-picker-line label{text-align:left}.form-row,.item-picker-line{grid-template-columns:1fr}.form-row.vertical{grid-template-columns:1fr}}
</style>
