<template>
  <section class="production-page routing-editor" v-loading="loading">
    <div class="page-heading">
      <div><p class="eyebrow">生产管理 / 生产基础 / 工艺路线</p><h1>{{ title }}</h1><p>维护工艺路线的工序、用料、检验要求及流转规则</p></div>
      <div class="heading-actions"><el-button @click="$router.push('/production/routings')">返回</el-button><el-button v-if="!viewOnly" type="success" plain @click="save(false)">保存草稿</el-button><el-button v-if="!viewOnly && $can('production.routing.activate')" type="success" @click="save(true)">检查并启用</el-button></div>
    </div>
    <el-form ref="form" :model="form" :rules="rules" label-position="top" size="small">
      <section class="form-card metadata"><div class="form-grid">
        <el-form-item label="路线编码"><el-input v-model="form.routing_no" disabled /></el-form-item>
        <el-form-item label="路线名称" prop="routing_name"><el-input v-model="form.routing_name" :disabled="viewOnly" /></el-form-item>
        <el-form-item label="产出物料" prop="output_item_id"><el-input :value="itemName(form.output_item_id)" readonly :disabled="viewOnly" suffix-icon="el-icon-search" @click.native="openItems('output')" /></el-form-item>
        <el-form-item label="版本（系统递增）"><el-input :value="`V${form.version}`" disabled /></el-form-item>
        <el-form-item label="关联产品（可选）"><el-input :value="form.product && form.product.product_name" readonly :disabled="viewOnly" suffix-icon="el-icon-search" @click.native="!viewOnly && $refs.productPicker.openProduct()" /></el-form-item>
        <el-form-item label="关联 SKU（可选）"><el-input :value="form.sku && (form.sku.sku_name || form.sku.sku_code)" readonly :disabled="viewOnly" suffix-icon="el-icon-search" @click.native="!viewOnly && (form.product_id ? $refs.productPicker.openSku(form.product_id) : $refs.productPicker.openGlobalSku())" /></el-form-item>
        <el-form-item label="状态"><el-input :value="statusText(form.status)" disabled /></el-form-item>
        <el-form-item label="是否默认"><el-input :value="form.is_default ? '是' : '否'" disabled /></el-form-item>
      </div></section>
      <section class="form-card operation-card">
        <div class="sub-heading"><h2>路线工序</h2><el-button v-if="!viewOnly" type="success" plain @click="addOperation">添加工序</el-button></div>
        <el-table ref="operations" :data="form.operations" border row-key="key" :expand-row-keys="expanded" @expand-change="onExpand">
          <el-table-column type="expand" width="1" class-name="native-expand"><template slot-scope="s">
            <div class="operation-settings">
              <section class="settings-group"><h3>工序信息</h3>
                <label><span>作业方式</span><el-select v-model="s.row.work_mode" :disabled="viewOnly"><el-option label="人工作业" value="manual" /><el-option label="自动作业" value="automatic" /></el-select></label>
                <label><span>产出物料</span><el-input :value="itemName(s.row.output_item_id)" readonly :disabled="viewOnly" suffix-icon="el-icon-search" @click.native="openItems('operation', s.row)" /></label>
                <label><span>完成去向</span><el-select v-model="s.row.output_mode" :disabled="viewOnly" @change="setOutputMode(s.row)"><el-option v-for="(label,value) in outputModes" :key="value" :label="label" :value="value" /></el-select></label>
                <label><span>检验要求</span><el-select v-model="s.row.quality_mode" :disabled="viewOnly"><el-option label="无" value="none" /><el-option label="必须检验" value="required" /></el-select></label>
              </section>
              <section class="settings-group"><h3>工时与参数</h3>
                <label><span>准备工时</span><el-input v-model.number="s.row.setup_standard_minutes" type="number" :disabled="viewOnly" min="0"><template slot="append">分钟</template></el-input></label>
                <label><span>单件工时</span><el-input v-model.number="s.row.unit_standard_minutes" type="number" :disabled="viewOnly" min="0"><template slot="append">分钟</template></el-input></label>
                <label><span>必要参数</span><el-input v-model="s.row.parameters_text" type="textarea" :rows="2" :disabled="viewOnly" /></label>
              </section>
              <section class="settings-group material-group"><h3>用料规则</h3>
                <el-table :data="incomingRules(s.row)" border size="mini">
                  <el-table-column label="物料名称" min-width="110"><template slot-scope="r">{{ itemName(r.row.component_item_id) }}</template></el-table-column>
                  <el-table-column label="BOM 用量占比" min-width="130"><template slot-scope="r"><el-input-number v-model="r.row.percent" :disabled="viewOnly" :controls="false" :min="0.0001" :max="100" :precision="4" size="mini" /><span>%</span></template></el-table-column>
                  <el-table-column label="供应方式" min-width="130"><template slot-scope="r"><el-select v-model="r.row.supply_mode" :disabled="viewOnly" size="mini"><el-option v-for="(label,value) in supplyModes" :key="value" :label="label" :value="value" /></el-select></template></el-table-column>
                  <el-table-column v-if="!viewOnly" label="操作" width="56"><template slot-scope="r"><el-button type="text" @click="removeMaterial(r.row)">删除</el-button></template></el-table-column>
                </el-table>
                <div v-if="!viewOnly" class="setting-actions"><el-button type="success" plain @click="openItems('materials',s.row)">添加用料</el-button><el-button type="success" @click="confirmSettings(s.row)">确认设置</el-button></div>
              </section>
            </div>
          </template></el-table-column>
          <el-table-column label="顺序" width="120"><template slot-scope="s"><div class="sequence-control"><el-button type="success" plain size="mini" :icon="expanded.includes(s.row.key)?'el-icon-minus':'el-icon-plus'" :aria-label="(expanded.includes(s.row.key)?'收起':'展开')+'第'+s.row.sequence+'道工序设置'" @click="toggleOperation(s.row)" /><el-input :value="s.row.sequence" disabled size="small" /></div></template></el-table-column>
          <el-table-column label="工序" min-width="160"><template slot-scope="s"><el-select v-model="s.row.operation_id" :disabled="viewOnly" filterable remote :remote-method="searchOperations" size="small"><el-option v-for="o in operations" :key="o.id" :label="o.operation_name" :value="o.id" /></el-select></template></el-table-column>
          <el-table-column label="用料" min-width="65"><template slot-scope="s">{{ incomingRules(s.row).length }} 种</template></el-table-column>
          <el-table-column label="产出" min-width="140"><template slot-scope="s">{{ itemName(s.row.output_item_id) || '—' }}</template></el-table-column>
          <el-table-column label="检验要求" min-width="100"><template slot-scope="s">{{ s.row.quality_mode === 'required' ? '必须检验' : '无' }}</template></el-table-column>
          <el-table-column label="完成去向" min-width="110"><template slot-scope="s">{{ outputModes[s.row.output_mode] }}</template></el-table-column>
          <el-table-column v-if="!viewOnly" label="操作" width="158"><template slot-scope="s"><div class="row-actions"><el-button type="text" :disabled="s.$index===0" @click="move(s.$index,-1)">上移</el-button><el-button type="text" :disabled="s.$index===form.operations.length-1" @click="move(s.$index,1)">下移</el-button><el-button type="text" @click="remove(s.$index)">删除</el-button></div></template></el-table-column>
        </el-table>
        <el-form-item label="备注" class="remark"><el-input v-model="form.remark" :disabled="viewOnly" type="textarea" :rows="3" /></el-form-item>
      </section>
    </el-form>
    <purchase-item-picker ref="itemPicker" @select="selectItem" @select-multiple="selectMaterials" />
    <product-sku-picker ref="productPicker" @select="selectProduct" />
  </section>
</template>
<script>
import cachedPageRoute from '@/utils/cachedPageRoute'
import { reserveProductionNumber, searchProductionOptions, getProductionRouting, createProductionRouting, updateProductionRouting, activateProductionRouting } from '../../../api/erp/production'
import PurchaseItemPicker from '@/components/purchase/PurchaseItemPicker.vue'
import ProductSkuPicker from '@/components/sales/ProductSkuPicker.vue'
const uuid = () => window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const r=Math.random()*16|0;return (c==='x'?r:(r&3|8)).toString(16)})
export default {
  mixins: [cachedPageRoute],
  name:'ProductionRoutingForm', components:{PurchaseItemPicker,ProductSkuPicker},
  data:()=>({loading:false,sessionId:'',reservationToken:'',savedId:null,operations:[],items:{},expanded:[],pickerTarget:null,pendingSave:null,pendingActivation:null,
    outputModes:{flow_only:'直接交接',warehouse_optional:'可选入库',warehouse_required:'合格后入库'},
    supplyModes:{dedicated_delivery:'专单配送',workstation_stock:'工位库存',no_per_order_delivery:'无需逐单配送'},
    form:{routing_no:'正在生成…',routing_name:'',output_item_id:null,product_id:null,sku_id:null,product:null,sku:null,version:1,status:'draft',is_default:false,remark:'',business_version:1,operations:[]},
    rules:{routing_name:[{required:true,message:'请输入路线名称',trigger:'blur'}],output_item_id:[{required:true,message:'请选择产出物料',trigger:'change'}]}}),
  computed:{id(){return this.savedId || this.pageRoute.params.id},viewOnly(){return (Boolean(this.pageRoute.params.id)&&!this.pageRoute.path.endsWith('/edit')) || this.form.status!=='draft'},title(){return this.id?(this.viewOnly?'查看工艺路线':'编辑工艺路线'):'新增工艺路线'}},
  async created(){await this.searchOperations();if(this.id)await this.load();else await this.reserve()},
  methods:{
    statusText(v){return({draft:'草稿',active:'已生效',retired:'已退役',disabled:'已退役'})[v]||v},
    itemName(id){const item=this.items[id];return item?item.item_name:(id?`物料 ${id}`:'')},
    rememberItem(item){if(item&&item.id)this.$set(this.items,item.id,item)},
    async searchOperations(keyword=''){try{const r=await searchProductionOptions('operations',{keyword,per_page:20});const current=this.operations.filter(o=>this.form.operations.some(row=>row.operation_id===o.id));this.operations=Array.from(new Map([...current,...(r.data.data||[])].map(o=>[o.id,o])).values())}catch(e){this.$message.error(e.userMessage||'工序加载失败')}},
    async reserve(){this.loading=true;this.sessionId=uuid();try{const r=await reserveProductionNumber('routing',this.sessionId,this.pageRoute.path);this.form.routing_no=r.data.data.document_no;this.reservationToken=r.data.data.reservation_token;this.addOperation()}catch(e){this.$message.error(e.userMessage||'编号生成失败')}finally{this.loading=false}},
    async load(){this.loading=true;try{const r=await getProductionRouting(this.id);this.applyData(r.data.data)}catch(e){this.$message.error(e.userMessage||'路线加载失败')}finally{this.loading=false}},
    applyData(data){
      this.rememberItem(data.output_item)
      const byId=new Map((data.operations||[]).map(x=>[Number(x.id),`op-${x.id}`]))
      const rows=(data.operations||[]).map(x=>{
        this.rememberItem(x.output_item)
        if(x.operation&&!this.operations.some(o=>o.id===x.operation.id))this.operations.push(x.operation)
        return {...x,key:byId.get(Number(x.id)),parameters_text:x.parameters&&x.parameters.text||'',setup_standard_minutes:Number(x.setup_standard_minutes||0),unit_standard_minutes:Number(x.unit_standard_minutes??x.standard_minutes??0),material_supply_rules:(x.material_supply_rules||[]).map(rule=>{this.rememberItem(rule.component_item);return {...rule,target_key:byId.get(Number(rule.target_routing_operation_id)),percent:Number(rule.required_qty_ratio)*100}})}
      })
      this.form={...this.form,...data,operations:rows};this.expanded=rows.length?[rows[0].key]:[]
    },
    addOperation(){const row={key:uuid(),operation_id:null,sequence:(this.form.operations.length+1)*10,is_key_operation:false,parameters:null,parameters_text:'',remark:'',setup_standard_minutes:0,unit_standard_minutes:0,output_item_id:this.form.output_item_id,output_mode:'flow_only',quality_mode:'none',work_mode:'manual',allow_continue_without_warehouse:true,material_supply_rules:[]};this.form.operations.push(row);this.expanded=[row.key]},
    incomingRules(row){return this.form.operations.flatMap(x=>x.material_supply_rules).filter(r=>r.target_key===row.key)},
    removeMaterial(rule){this.form.operations.forEach(row=>{row.material_supply_rules=row.material_supply_rules.filter(r=>r!==rule)})},
    remove(index){const row=this.form.operations[index];this.form.operations.splice(index,1);this.form.operations.forEach(x=>{x.material_supply_rules=x.material_supply_rules.filter(r=>r.target_key!==row.key)});this.renumber()},
    move(index,delta){const next=index+delta;if(next<0||next>=this.form.operations.length)return;const row=this.form.operations.splice(index,1)[0];this.form.operations.splice(next,0,row);this.renumber()},
    renumber(){this.form.operations.forEach((r,i)=>{r.sequence=(i+1)*10})},
    onExpand(row,expanded){this.expanded=expanded.map(x=>x.key)},
    toggleOperation(row){this.expanded=this.expanded.includes(row.key)?this.expanded.filter(key=>key!==row.key):[...this.expanded,row.key]},
    setOutputMode(row){row.allow_continue_without_warehouse=row.output_mode!=='warehouse_required'},
    confirmSettings(row){if(this.validateOperation(row))this.expanded=this.expanded.filter(key=>key!==row.key)},
    openItems(mode,row=null){if(this.viewOnly)return;this.pickerTarget={mode,row};this.$refs.itemPicker.open({currentId:mode==='output'?this.form.output_item_id:row&&row.output_item_id,multiple:mode==='materials',selected:mode==='materials'?this.incomingRules(row).map(r=>this.items[r.component_item_id]).filter(Boolean):[],params:{is_purchase_item:undefined},title:mode==='materials'?'选择工序用料':'选择产出物料',tip:'按分类、编码、名称或规格查询已启用物料。'})},
    selectItem(item){this.rememberItem(item);if(this.pickerTarget.mode==='output')this.form.output_item_id=item.id;else this.pickerTarget.row.output_item_id=item.id},
    selectMaterials(items){const row=this.pickerTarget.row;items.forEach(item=>{this.rememberItem(item);if(!this.incomingRules(row).some(r=>Number(r.component_item_id)===Number(item.id)))row.material_supply_rules.push({component_item_id:item.id,target_key:row.key,percent:100,supply_mode:'dedicated_delivery',participates_in_kitting:true,allow_partial_delivery:false,delivery_location_type:'operation_station'})})},
    selectProduct({mode,row}){if(mode==='product'){this.form.product_id=row.id;this.form.product=row;this.form.sku_id=null;this.form.sku=null}else{this.form.sku_id=row.id;this.form.sku=row;this.form.product_id=row.product_id;this.form.product=row.product||{product_name:row.product_name||''}}},
    validateOperation(row){if(!row.operation_id){this.$message.warning('请完整选择工序');return false}if(row.output_mode!=='flow_only'&&!row.output_item_id){this.$message.warning('入库工序必须选择产出物料');return false}if([row.setup_standard_minutes,row.unit_standard_minutes].some(v=>v===''||!Number.isFinite(Number(v))||Number(v)<0)){this.$message.warning('工时必须为大于或等于零的数字');return false}return true},
    payload(){return {routing_name:this.form.routing_name,output_item_id:this.form.output_item_id,product_id:this.form.product_id,sku_id:this.form.sku_id,remark:this.form.remark,operations:this.form.operations.map(row=>({operation_id:row.operation_id,sequence:row.sequence,is_key_operation:row.is_key_operation,parameters:{...(row.parameters||{}),text:row.parameters_text},remark:row.remark||null,setup_standard_minutes:Number(row.setup_standard_minutes),unit_standard_minutes:Number(row.unit_standard_minutes),standard_minutes:Number(row.unit_standard_minutes),output_item_id:row.output_item_id,output_mode:row.output_mode,quality_mode:row.quality_mode,work_mode:row.work_mode,allow_continue_without_warehouse:row.allow_continue_without_warehouse,material_supply_rules:row.material_supply_rules.map(rule=>({component_item_id:rule.component_item_id,target_sequence:(this.form.operations.find(x=>x.key===rule.target_key)||{}).sequence,required_qty_ratio:Number(rule.percent)/100,supply_mode:rule.supply_mode,requires_delivery:rule.supply_mode==='dedicated_delivery',participates_in_kitting:rule.participates_in_kitting,allow_partial_delivery:rule.allow_partial_delivery,delivery_location_type:rule.delivery_location_type}))}))}},
    async save(activate){
      if(this.loading||this.viewOnly)return
      // 启用响应丢失时只重试原启用命令，不能先重复保存已启用的版本。
      if(activate&&this.pendingActivation){this.loading=true;try{await this.finishActivation();await this.load()}catch(e){this.showSaveError(e)}finally{this.loading=false}return}
      if(!await this.$refs.form.validate().catch(()=>false))return
      if(!this.form.operations.length)return this.$message.warning('请至少添加一道工序')
      if(!this.form.operations.every(this.validateOperation))return
      const common=this.payload();const signature=JSON.stringify(common)
      // 同一表单的网络重试复用命令号，避免已提交但丢失响应时重复生成路线。
      if(!this.pendingSave||this.pendingSave.signature!==signature)this.pendingSave={signature,id:`routing-save-${uuid()}`}
      this.loading=true
      try{
        const r=this.id?await updateProductionRouting(this.id,{...common,client_command_id:this.pendingSave.id,expected_version:this.form.business_version}):await createProductionRouting({...common,client_command_id:this.pendingSave.id,creation_session_id:this.sessionId,reservation_token:this.reservationToken})
        this.savedId=r.data.data.id;this.form.business_version=r.data.data.business_version;this.pendingSave=null
        if(activate){
          this.pendingActivation={client_command_id:`routing-activate-${uuid()}`,expected_version:this.form.business_version}
          await this.finishActivation()
        }
        this.$message.success(activate?'工艺路线已启用':'工艺路线草稿已保存');const path=`/production/routings/${this.id}${activate?'':'/edit'}`;if(this.pageRoute.path!==path)await this.$router.replace(path);await this.load()
      }catch(e){this.showSaveError(e)}finally{this.loading=false}
    },
    async finishActivation(){const activated=await activateProductionRouting(this.id,this.pendingActivation);this.form.status=activated.data.data.status;this.pendingActivation=null},
    showSaveError(e){if(e.response&&e.response.status>=400&&e.response.status<500)this.pendingActivation=null;this.$message.error(e.userMessage||e.response&&e.response.data&&e.response.data.message||'保存失败')}
  }
}
</script>
<style scoped src="./production-master.css"></style>
<style scoped>
.sequence-control{display:flex;align-items:center;gap:10px}.sequence-control .el-button{width:22px;height:22px;padding:0;flex-shrink:0}.sequence-control .el-input{min-width:0}.routing-editor ::v-deep .native-expand{padding:0;border-right:0}.routing-editor ::v-deep .native-expand .cell{padding:0;width:0;overflow:hidden}.settings-group ::v-deep .el-input__inner{height:32px;line-height:32px}.settings-group ::v-deep .el-input__icon{line-height:32px}.routing-editor .operation-card{padding-top:16px}.routing-editor .page-heading{margin-bottom:10px}
.routing-editor .el-button--success.is-plain{background:#fff;color:#008b4b;border-color:#008b4b}.routing-editor .el-button--success.is-plain:hover{background:#f0faf5}.material-group .el-input-number{width:94px!important}.material-group ::v-deep .el-input-number .el-input__inner{padding:0 5px}
.routing-editor .form-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:12px 26px}.metadata{border-radius:4px 4px 0 0}.operation-card{border-top:0;border-radius:0 0 4px 4px}.heading-actions,.row-actions,.setting-actions{display:flex;align-items:center;flex-shrink:0}.sub-heading{display:flex;align-items:center;justify-content:space-between;margin:0 0 14px}.sub-heading h2{margin:0;font-size:18px}.operation-settings{display:grid;grid-template-columns:1fr .85fr 1.4fr;margin:-12px -16px;background:#fcfdff}.settings-group{padding:18px;border-right:1px solid #e5eaf0;min-width:0}.settings-group:last-child{border:0}.settings-group h3{margin:0 0 14px;font-size:14px}.settings-group>label{display:grid;grid-template-columns:78px minmax(0,1fr);align-items:center;gap:10px;margin-bottom:14px;font-size:12px}.settings-group .el-select{width:100%;min-width:0}.setting-actions{margin-top:18px}.material-group .el-input-number{width:72px}.remark{margin-top:20px;margin-bottom:0}.row-actions .el-button{padding:8px 0}.routing-editor ::v-deep .el-table th{background:#f7f9fc;color:#26384e}.routing-editor ::v-deep .el-table .cell{word-break:break-word;white-space:normal}.routing-editor ::v-deep .el-table__expanded-cell{padding:12px 16px}.routing-editor ::v-deep .el-input.is-disabled .el-input__inner{color:#5e6c7f;background:#f6f8fb}.routing-editor ::v-deep .el-input-group__append{padding:0 12px}.routing-editor ::v-deep .el-form-item__label{font-size:12px;padding-bottom:6px;line-height:20px}.routing-editor ::v-deep .el-form-item{margin-bottom:16px}
@media(max-width:1500px){.operation-settings{grid-template-columns:1fr 1fr}.material-group{grid-column:1/-1}.settings-group:nth-child(2){border-right:0}.settings-group.material-group{border-top:1px solid #e5eaf0}}
@media(max-width:1100px){.routing-editor .form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.page-heading{gap:16px;flex-wrap:wrap}}
@media(max-width:767px){.routing-editor .form-grid,.operation-settings{grid-template-columns:minmax(0,1fr)}.form-card{padding:14px}.settings-group{border-right:0;border-bottom:1px solid #e5eaf0}.heading-actions{width:100%;flex-wrap:wrap;gap:8px}.heading-actions .el-button{margin:0}.settings-group>label{grid-template-columns:72px minmax(0,1fr)}}
@media(max-width:767px){.operation-card{container-type:inline-size}.operation-settings{width:calc(100cqw - 1px)}.settings-group{padding:14px}.settings-group>label{grid-template-columns:64px minmax(0,1fr);gap:6px}.setting-actions{gap:8px;flex-wrap:wrap}.setting-actions .el-button{margin:0}.routing-editor ::v-deep .el-input-group__append{padding:0 6px}}
</style>
