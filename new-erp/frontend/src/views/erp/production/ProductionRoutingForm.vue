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
        <div class="sub-heading"><h2>路线工序</h2><div v-if="!viewOnly" class="catalog-actions"><el-button type="success" plain @click="openCatalog('stages')">维护生产阶段</el-button><el-button type="success" plain @click="openCatalog('packaging-schemes')">维护包装方案</el-button><el-button type="success" @click="addOperation">添加工序</el-button></div></div>
        <el-table ref="operations" :data="form.operations" border row-key="key" :expand-row-keys="expanded" @expand-change="onExpand">
          <el-table-column type="expand" width="1" class-name="native-expand"><template slot-scope="s">
            <div class="operation-settings">
              <section class="settings-group"><h3>工序信息</h3>
                <label><span>所属阶段</span><div class="selector-field"><el-input :value="catalogName('stages',s.row.production_stage_id)" readonly :disabled="viewOnly" suffix-icon="el-icon-search" placeholder="选择阶段（可选）" @click.native="openCatalog('stages',s.row)" /><el-button v-if="!viewOnly && s.row.production_stage_id" type="text" @click="s.row.production_stage_id=null">清除</el-button></div></label>
                <label><span>执行场景</span><el-select :value="s.row.execution_context" :disabled="viewOnly" @change="changeExecutionContext(s.row,$event)"><el-option label="生产工序" value="production" /><el-option label="发货作业" value="shipment" /></el-select></label>
                <label><span>作业方式</span><el-select v-model="s.row.work_mode" :disabled="viewOnly"><el-option label="人工作业" value="manual" /><el-option label="自动作业" value="automatic" /></el-select></label>
                <label v-if="s.row.execution_context==='production'"><span>产出物料</span><el-input :value="itemName(s.row.output_item_id)" readonly :disabled="viewOnly" suffix-icon="el-icon-search" @click.native="openItems('operation', s.row)" /></label>
                <label v-if="s.row.execution_context==='production'"><span>完成去向</span><el-select v-model="s.row.output_mode" :disabled="viewOnly" @change="setOutputMode(s.row)"><el-option v-for="(label,value) in outputModes" :key="value" :label="label" :value="value" /></el-select></label>
                <label><span>检验要求</span><el-select v-model="s.row.quality_mode" :disabled="viewOnly"><el-option label="无" value="none" /><el-option label="必须检验" value="required" /></el-select></label>
                <div v-if="s.row.execution_context==='production'" class="output-rules-launcher"><el-button size="small" type="success" plain @click="openOutputRules(s.row)">{{ viewOnly ? '查看产出规则' : '设置产出规则' }}（{{ (s.row.output_rules || []).length }}）</el-button></div>
              </section>
              <section class="settings-group"><h3>工时与参数</h3>
                <label><span>准备工时</span><el-input v-model.number="s.row.setup_standard_minutes" type="number" :disabled="viewOnly" min="0"><template slot="append">分钟</template></el-input></label>
                <label><span>单件工时</span><el-input v-model.number="s.row.unit_standard_minutes" type="number" :disabled="viewOnly" min="0"><template slot="append">分钟</template></el-input></label>
                <label v-if="canManagePerformance"><span>绩效比例</span><el-input v-model="s.row.performance_percent" :disabled="viewOnly" placeholder="留空表示未配置"><template slot="append">%</template></el-input></label>
                <label><span>必要参数</span><el-input v-model="s.row.parameters_text" type="textarea" :rows="2" :disabled="viewOnly" /></label>
              </section>
              <section class="settings-group material-group"><h3>{{ s.row.execution_context==='shipment'?'发货包装':'用料规则' }}</h3>
                <template v-if="s.row.execution_context==='shipment'">
                  <label><span>包装方案</span><div class="selector-field"><el-input :value="catalogName('packaging-schemes',s.row.packaging_scheme_id)" readonly :disabled="viewOnly" suffix-icon="el-icon-search" placeholder="请选择包装方案" @click.native="openCatalog('packaging-schemes',s.row)" /><el-button v-if="!viewOnly && s.row.packaging_scheme_id" type="text" @click="s.row.packaging_scheme_id=null">清除</el-button></div></label>
                  <p class="setting-note">发货作业排在生产工序之后；包装用料填写每件产出的基础单位数量。</p>
                  <el-table :data="s.row.packaging_materials" border size="mini">
                    <el-table-column label="包装物料" min-width="140"><template slot-scope="r">{{ itemName(r.row.component_item_id) }}</template></el-table-column>
                    <el-table-column label="每件用量" min-width="120"><template slot-scope="r"><el-input v-model="r.row.base_qty_per_output_unit" :disabled="viewOnly" size="mini" placeholder="请填写数量" /></template></el-table-column>
                    <el-table-column label="基础单位" min-width="80"><template slot-scope="r">{{ itemUnit(r.row.component_item_id) }}</template></el-table-column>
                    <el-table-column v-if="!viewOnly" label="操作" width="56"><template slot-scope="r"><el-button type="text" @click="removePackagingMaterial(s.row,r.row)">删除</el-button></template></el-table-column>
                  </el-table>
                </template>
                <el-table v-else :data="incomingRules(s.row)" border size="mini">
                  <el-table-column label="物料名称" min-width="110"><template slot-scope="r">{{ itemName(r.row.component_item_id) }}</template></el-table-column>
                  <el-table-column label="BOM 用量占比" min-width="130"><template slot-scope="r"><el-input-number v-model="r.row.percent" :disabled="viewOnly" :controls="false" :min="0.0001" :max="100" :precision="4" size="mini" /><span>%</span></template></el-table-column>
                  <el-table-column label="供应方式" min-width="130"><template slot-scope="r"><el-select v-model="r.row.supply_mode" :disabled="viewOnly" size="mini"><el-option v-for="(label,value) in supplyModes" :key="value" :label="label" :value="value" /></el-select></template></el-table-column>
                  <el-table-column v-if="!viewOnly" label="操作" width="56"><template slot-scope="r"><el-button type="text" @click="removeMaterial(r.row)">删除</el-button></template></el-table-column>
                </el-table>
                <div v-if="!viewOnly" class="setting-actions"><el-button type="success" plain @click="openItems(s.row.execution_context==='shipment'?'packaging':'materials',s.row)">{{ s.row.execution_context==='shipment'?'添加包装用料':'添加用料' }}</el-button><el-button type="success" @click="confirmSettings(s.row)">确认设置</el-button></div>
              </section>
            </div>
          </template></el-table-column>
          <el-table-column label="顺序" width="120"><template slot-scope="s"><div class="sequence-control"><el-button type="success" plain size="mini" :icon="expanded.includes(s.row.key)?'el-icon-minus':'el-icon-plus'" :aria-label="(expanded.includes(s.row.key)?'收起':'展开')+'第'+s.row.sequence+'道工序设置'" @click="toggleOperation(s.row)" /><el-input :value="s.row.sequence" disabled size="small" /></div></template></el-table-column>
          <el-table-column label="工序" min-width="160"><template slot-scope="s"><div class="operation-select-field"><el-select v-model="s.row.operation_id" :disabled="viewOnly" filterable remote :remote-method="searchOperations" size="small"><el-option v-for="o in operations" :key="o.id" :label="o.operation_name" :value="o.id" class="operation-select-option"><div class="operation-option"><span>{{ o.operation_name }}</span><el-tag v-if="isPublic(o.is_public)" size="mini" type="success">公共工序</el-tag></div></el-option></el-select><el-tag v-if="operationIsPublic(s.row)" size="mini" type="success" class="selected-operation-tag">公共工序</el-tag></div></template></el-table-column>
          <el-table-column label="阶段 / 场景" min-width="125"><template slot-scope="s">{{ catalogName('stages',s.row.production_stage_id) || '未设置阶段' }}<div class="setting-note">{{ s.row.execution_context==='shipment'?'发货作业':'生产工序' }}</div></template></el-table-column>
          <el-table-column label="用料" min-width="65"><template slot-scope="s">{{ s.row.execution_context==='shipment'?s.row.packaging_materials.length:incomingRules(s.row).length }} 种</template></el-table-column>
          <el-table-column label="产出" min-width="140"><template slot-scope="s"><template v-if="s.row.output_rules && s.row.output_rules.length"><div v-for="rule in s.row.output_rules" :key="rule.output_rule_key">{{ rule.item_name_snapshot || rule.item_name || itemName(rule.item_id) }}</div></template><template v-else>{{ itemName(s.row.output_item_id) || '—' }}</template></template></el-table-column>
          <el-table-column label="检验要求" min-width="100"><template slot-scope="s">{{ s.row.quality_mode === 'required' ? '必须检验' : '无' }}</template></el-table-column>
          <el-table-column label="完成去向" min-width="110"><template slot-scope="s">{{ s.row.execution_context==='shipment'?'发货打包':outputModes[s.row.output_mode] }}</template></el-table-column>
          <el-table-column v-if="!viewOnly" label="操作" width="158"><template slot-scope="s"><div class="row-actions"><el-button type="text" :disabled="!canMove(s.$index,-1)" @click="move(s.$index,-1)">上移</el-button><el-button type="text" :disabled="!canMove(s.$index,1)" @click="move(s.$index,1)">下移</el-button><el-button type="text" @click="remove(s.$index)">删除</el-button></div></template></el-table-column>
        </el-table>
        <el-form-item label="备注" class="remark"><el-input v-model="form.remark" :disabled="viewOnly" type="textarea" :rows="3" /></el-form-item>
      </section>
    </el-form>
    <purchase-item-picker ref="itemPicker" @select="selectItem" @select-multiple="selectMaterials" />
    <product-sku-picker ref="productPicker" @select="selectProduct" />
    <production-process-catalog-panel ref="processCatalog" :launchers="false" @select="selectCatalog" @updated="rememberCatalog" />
    <routing-output-rules-dialog ref="outputRules" @confirm="applyOutputRules" />
  </section>
</template>
<script>
import cachedPageRoute from '@/utils/cachedPageRoute'
import { reserveProductionNumber, searchProductionOptions, getProductionRouting, createProductionRouting, updateProductionRouting, activateProductionRouting } from '../../../api/erp/production'
import PurchaseItemPicker from '@/components/purchase/PurchaseItemPicker.vue'
import ProductSkuPicker from '@/components/sales/ProductSkuPicker.vue'
import ProductionProcessCatalogPanel from './ProductionProcessCatalogPanel.vue'
import RoutingOutputRulesDialog from './RoutingOutputRulesDialog.vue'
import { executeProductionDecision } from '../../../api/erp/production-assignments'
const uuid = () => window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const r=Math.random()*16|0;return (c==='x'?r:(r&3|8)).toString(16)})
const emptyPercent = value => value === '' || value === null || value === undefined
const percentFromRate = value => emptyPercent(value) ? '' : String(Number((Number(value) * 100).toFixed(4)))
const rateFromPercent = value => emptyPercent(value) ? null : Number((Number(value) / 100).toFixed(6))
const validPercent = value => emptyPercent(value) || (/^\d{1,3}(?:\.\d{1,4})?$/.test(String(value)) && Number(value) <= 100)
const asBoolean = value => value === true || value === 1 || value === '1'
export default {
  mixins: [cachedPageRoute],
  name:'ProductionRoutingForm', components:{PurchaseItemPicker,ProductSkuPicker,ProductionProcessCatalogPanel,RoutingOutputRulesDialog},
  data:()=>({loading:false,sessionId:'',reservationToken:'',savedId:null,operations:[],items:{},catalogs:{stages:{},'packaging-schemes':{}},expanded:[],pickerTarget:null,catalogTarget:null,pendingSave:null,pendingActivation:null,
    outputModes:{flow_only:'直接交接',warehouse_optional:'可选入库',warehouse_required:'合格后入库'},
    supplyModes:{dedicated_delivery:'专单配送',workstation_stock:'工位库存',no_per_order_delivery:'无需逐单配送'},
    form:{routing_no:'正在生成…',routing_name:'',output_item_id:null,product_id:null,sku_id:null,product:null,sku:null,version:1,status:'draft',is_default:false,remark:'',business_version:1,operations:[]},
    rules:{routing_name:[{required:true,message:'请输入路线名称',trigger:'blur'}],output_item_id:[{required:true,message:'请选择产出物料',trigger:'change'}]}}),
  computed:{id(){return this.savedId || this.pageRoute.params.id},viewOnly(){return (Boolean(this.pageRoute.params.id)&&!this.pageRoute.path.endsWith('/edit')) || this.form.status!=='draft'},title(){return this.id?(this.viewOnly?'查看工艺路线':'编辑工艺路线'):'新增工艺路线'},canManagePerformance(){return this.$can('production.performance.manage')}},
  async created(){await this.searchOperations();if(this.id)await this.load();else await this.reserve()},
  methods:{
    statusText(v){return({draft:'草稿',active:'已生效',retired:'已退役',disabled:'已退役'})[v]||v},
    isPublic(value){return asBoolean(value)},
    operationIsPublic(row){const operation=this.operations.find(candidate=>Number(candidate.id)===Number(row.operation_id));return asBoolean(operation&&operation.is_public!==undefined?operation.is_public:row.is_public??row.operation?.is_public)},
    itemName(id){const item=this.items[id];return item?item.item_name:(id?`物料 ${id}`:'')},
    itemUnit(id){const item=this.items[id]||{};const unit=item.unit?.standard_unit||item.unit?.standardUnit||item.unit||item.inventory_unit;return unit?.symbol||unit?.unit_name||unit?.unit_code||item.base_unit_name||'—'},
    rememberItem(item){if(item&&item.id)this.$set(this.items,item.id,item)},
    rememberCatalog({type,row}){if(row&&row.id&&this.catalogs[type])this.$set(this.catalogs[type],row.id,row)},
    catalogName(type,id){return (this.catalogs[type][id]||{}).name||(id?`档案 ${id}`:'')},
    openCatalog(type,row=null){if(this.viewOnly)return;this.catalogTarget=row?{type,row}:null;this.$refs.processCatalog.open(type,{mode:row?'select':'manage',selected:row?(this.catalogs[type][type==='stages'?row.production_stage_id:row.packaging_scheme_id]||null):null})},
    selectCatalog({type,row}){this.rememberCatalog({type,row});if(!this.catalogTarget||this.catalogTarget.type!==type||!this.form.operations.includes(this.catalogTarget.row))return;this.$set(this.catalogTarget.row,type==='stages'?'production_stage_id':'packaging_scheme_id',row.id);this.catalogTarget=null},
    async searchOperations(keyword=''){try{const r=await searchProductionOptions('operations',{keyword,per_page:20});const current=this.operations.filter(o=>this.form.operations.some(row=>row.operation_id===o.id));this.operations=Array.from(new Map([...current,...(r.data.data||[])].map(o=>[o.id,o])).values())}catch(e){this.$message.error(e.userMessage||'工序加载失败')}},
    async reserve(){this.loading=true;this.sessionId=uuid();try{const r=await reserveProductionNumber('routing',this.sessionId,this.pageRoute.path);this.form.routing_no=r.data.data.document_no;this.reservationToken=r.data.data.reservation_token;this.addOperation()}catch(e){this.$message.error(e.userMessage||'编号生成失败')}finally{this.loading=false}},
    async load(){this.loading=true;try{const r=await getProductionRouting(this.id);this.applyData(r.data.data)}catch(e){this.$message.error(e.userMessage||'路线加载失败')}finally{this.loading=false}},
    applyData(data){
      this.rememberItem(data.output_item)
      const byId=new Map((data.operations||[]).map(x=>[Number(x.id),`op-${x.id}`]))
      const rows=(data.operations||[]).map(x=>{
        this.rememberItem(x.output_item)
        if(x.operation){const index=this.operations.findIndex(o=>Number(o.id)===Number(x.operation.id));const operation={...x.operation,is_public:asBoolean(x.is_public??x.operation.is_public)};if(index<0)this.operations.push(operation);else this.$set(this.operations,index,{...this.operations[index],...operation})}
        this.rememberCatalog({type:'stages',row:x.stage||{id:x.production_stage_id,name:x.stage_name,code:x.stage_code}})
        this.rememberCatalog({type:'packaging-schemes',row:x.packaging_scheme||{id:x.packaging_scheme_id,name:x.packaging_scheme_name,code:x.packaging_scheme_code}})
        const ordinary={...x};delete ordinary.performance_rate
        if(this.canManagePerformance)ordinary.performance_percent=percentFromRate(x.performance_rate)
        return {...ordinary,key:byId.get(Number(x.id)),output_rules:JSON.parse(JSON.stringify(x.output_rules||[])),production_stage_id:x.production_stage_id||null,execution_context:x.execution_context||'production',packaging_scheme_id:x.packaging_scheme_id||null,packaging_materials:(x.packaging_materials||[]).map(material=>{this.rememberItem(material.component_item);return {...material}}),parameters_text:x.parameters&&x.parameters.text||'',setup_standard_minutes:Number(x.setup_standard_minutes||0),unit_standard_minutes:Number(x.unit_standard_minutes??x.standard_minutes??0),material_supply_rules:(x.material_supply_rules||[]).map(rule=>{this.rememberItem(rule.component_item);return {...rule,target_key:byId.get(Number(rule.target_routing_operation_id)),percent:Number(percentFromRate(rule.required_qty_ratio))}})}
      })
      this.form={...this.form,...data,operations:rows};this.expanded=rows.length?[rows[0].key]:[]
    },
    addOperation(){const row={key:uuid(),operation_id:null,production_stage_id:null,execution_context:'production',packaging_scheme_id:null,packaging_materials:[],output_rules:[],sequence:(this.form.operations.length+1)*10,is_key_operation:false,parameters:null,parameters_text:'',remark:'',setup_standard_minutes:0,unit_standard_minutes:0,output_item_id:this.form.output_item_id,output_mode:'flow_only',quality_mode:'none',work_mode:'manual',allow_continue_without_warehouse:true,material_supply_rules:[]};if(this.canManagePerformance)row.performance_percent='';this.form.operations.push(row);this.orderContexts();this.expanded=[row.key]},
    openOutputRules(row){if(row.execution_context!=='production')return;if(!this.form.output_item_id)return this.$message.warning('请先选择路线的产出物料，明确产出数量基准。');this.$refs.outputRules.open({operation:row,referenceItem:this.items[this.form.output_item_id],readOnly:this.viewOnly})},
    applyOutputRules({operationKey,rules}){if(this.viewOnly)return;const row=this.form.operations.find(candidate=>candidate.key===operationKey);if(!row||row.execution_context!=='production')return;this.$set(row,'output_rules',rules)},
    incomingRules(row){return this.form.operations.flatMap(x=>x.material_supply_rules).filter(r=>r.target_key===row.key)},
    removeMaterial(rule){this.form.operations.forEach(row=>{row.material_supply_rules=row.material_supply_rules.filter(r=>r!==rule)})},
    removePackagingMaterial(row,material){row.packaging_materials=row.packaging_materials.filter(current=>current!==material)},
    remove(index){const row=this.form.operations[index];this.form.operations.splice(index,1);this.form.operations.forEach(x=>{x.material_supply_rules=x.material_supply_rules.filter(r=>r.target_key!==row.key)});this.renumber()},
    canMove(index,delta){const next=index+delta;return next>=0&&next<this.form.operations.length&&this.form.operations[index].execution_context===this.form.operations[next].execution_context},
    move(index,delta){if(!this.canMove(index,delta))return;const row=this.form.operations.splice(index,1)[0];this.form.operations.splice(index+delta,0,row);this.renumber()},
    orderContexts(){this.form.operations=[...this.form.operations.filter(row=>row.execution_context!=='shipment'),...this.form.operations.filter(row=>row.execution_context==='shipment')];this.renumber()},
    changeExecutionContext(row,context){if(this.viewOnly||row.execution_context===context)return;if(context==='shipment'&&(row.material_supply_rules.length||this.incomingRules(row).length||(row.output_rules||[]).length)){this.$message.warning('请先清除本工序相关的生产用料和产出规则，再改为发货作业');return}if(context==='production'&&(row.packaging_scheme_id||row.packaging_materials.length)){this.$message.warning('请先清除包装方案和包装用料，再改为生产工序');return}row.execution_context=context;if(context==='shipment'){row.output_mode='flow_only';row.allow_continue_without_warehouse=true}this.orderContexts()},
    renumber(){this.form.operations.forEach((r,i)=>{r.sequence=(i+1)*10})},
    onExpand(row,expanded){this.expanded=expanded.map(x=>x.key)},
    toggleOperation(row){this.expanded=this.expanded.includes(row.key)?this.expanded.filter(key=>key!==row.key):[...this.expanded,row.key]},
    setOutputMode(row){row.allow_continue_without_warehouse=row.output_mode!=='warehouse_required'},
    confirmSettings(row){if(this.validateOperation(row))this.expanded=this.expanded.filter(key=>key!==row.key)},
    openItems(mode,row=null){if(this.viewOnly)return;this.pickerTarget={mode,row};const multiple=['materials','packaging'].includes(mode);const materials=mode==='packaging'?row.packaging_materials:(mode==='materials'?this.incomingRules(row):[]);this.$refs.itemPicker.open({currentId:mode==='output'?this.form.output_item_id:row&&row.output_item_id,multiple,selected:materials.map(material=>this.items[material.component_item_id]||{id:material.component_item_id,item_name:this.itemName(material.component_item_id)}),params:{management_scope:'factory',is_purchase_item:undefined},title:mode==='packaging'?'选择包装用料':mode==='materials'?'选择工序用料':'选择产出物料'})},
    selectItem(item){if(!this.pickerTarget||this.viewOnly)return;if(this.pickerTarget.mode==='output'&&Number(item.id)!==Number(this.form.output_item_id)&&this.form.operations.some(row=>(row.output_rules||[]).length))return this.$message.warning('已设置工序产出规则，变更数量基准前请先核对并移除原规则。');this.rememberItem(item);if(this.pickerTarget.mode==='output')this.form.output_item_id=item.id;else if(this.form.operations.includes(this.pickerTarget.row))this.pickerTarget.row.output_item_id=item.id},
    selectMaterials(items){if(!this.pickerTarget||!this.form.operations.includes(this.pickerTarget.row))return;const {row,mode}=this.pickerTarget;items.forEach(item=>{this.rememberItem(item);if(mode==='packaging'){if(!row.packaging_materials.some(material=>Number(material.component_item_id)===Number(item.id)))row.packaging_materials.push({component_item_id:item.id,base_qty_per_output_unit:''})}else if(!this.incomingRules(row).some(r=>Number(r.component_item_id)===Number(item.id)))row.material_supply_rules.push({component_item_id:item.id,target_key:row.key,percent:100,supply_mode:'dedicated_delivery',participates_in_kitting:true,allow_partial_delivery:false,delivery_location_type:'operation_station'})})},
    selectProduct({mode,row}){if(mode==='product'){this.form.product_id=row.id;this.form.product=row;this.form.sku_id=null;this.form.sku=null}else{this.form.sku_id=row.id;this.form.sku=row;this.form.product_id=row.product_id;this.form.product=row.product||{product_name:row.product_name||''}}},
    validateOperation(row){
      if(!row.operation_id){this.$message.warning('请完整选择工序');return false}
      if(row.execution_context!=='shipment'&&row.output_mode!=='flow_only'&&!row.output_item_id){this.$message.warning('入库工序必须选择产出物料');return false}
      if([row.setup_standard_minutes,row.unit_standard_minutes].some(value=>value===''||!Number.isFinite(Number(value))||Number(value)<0)){this.$message.warning('工时必须为大于或等于零的数字');return false}
      if(this.canManagePerformance&&!validPercent(row.performance_percent)){this.$message.warning('绩效比例须为 0 到 100 之间的数字，最多四位小数；未配置请留空');return false}
      if(row.execution_context==='shipment'){
        if(!row.packaging_scheme_id){this.$message.warning('发货作业必须选择包装方案');return false}
        if(row.material_supply_rules.length||this.incomingRules(row).length){this.$message.warning('发货作业不能使用生产 BOM 用料规则');return false}
        if(row.packaging_materials.some(material=>!/^\d+(?:\.\d+)?$/.test(String(material.base_qty_per_output_unit))||Number(material.base_qty_per_output_unit)<=0||Number(material.base_qty_per_output_unit)>999999999)){this.$message.warning('请填写包装用料每件的基础单位数量，必须大于零');return false}
      }else if(row.material_supply_rules.some(rule=>!validPercent(rule.percent)||emptyPercent(rule.percent)||Number(rule.percent)<=0||!this.form.operations.some(target=>target.key===rule.target_key&&target.execution_context==='production'))){this.$message.warning('生产用料须分配到生产工序，用量占比须大于零且不超过 100%');return false}
      return true
    },
    payload(){
      return {routing_name:this.form.routing_name,output_item_id:this.form.output_item_id,product_id:this.form.product_id,sku_id:this.form.sku_id,remark:this.form.remark,
        operations:this.form.operations.map(row=>{
          const operation={operation_id:row.operation_id,production_stage_id:row.production_stage_id||null,execution_context:row.execution_context,sequence:row.sequence,is_key_operation:row.is_key_operation,
            parameters:{...(row.parameters||{}),text:row.parameters_text},remark:row.remark||null,setup_standard_minutes:Number(row.setup_standard_minutes),unit_standard_minutes:Number(row.unit_standard_minutes),standard_minutes:Number(row.unit_standard_minutes),
            output_item_id:row.output_item_id,output_mode:row.output_mode,quality_mode:row.quality_mode,work_mode:row.work_mode,allow_continue_without_warehouse:row.allow_continue_without_warehouse}
          // 保留明细标识，后端可按原节点保留当前账号不可见的绩效配置。
          if(Number(row.id)>0)operation.id=Number(row.id)
          if(Object.hasOwn(row,'output_rules'))operation.output_rules=(row.output_rules||[]).map(rule=>({output_rule_key:rule.output_rule_key,item_id:Number(rule.item_id),output_role:rule.output_role,base_qty_per_reference_unit:String(rule.base_qty_per_reference_unit),quality_mode:rule.quality_mode,output_mode:rule.output_mode,allow_continue_without_warehouse:!!rule.allow_continue_without_warehouse,remark:rule.remark||null}))
          if(this.canManagePerformance)operation.performance_rate=rateFromPercent(row.performance_percent)
          if(row.execution_context==='shipment'){
            operation.packaging_scheme_id=row.packaging_scheme_id
            operation.packaging_materials=row.packaging_materials.map(material=>({component_item_id:material.component_item_id,base_qty_per_output_unit:Number(material.base_qty_per_output_unit)}))
          }else{
            operation.material_supply_rules=row.material_supply_rules.map(rule=>({component_item_id:rule.component_item_id,target_sequence:(this.form.operations.find(target=>target.key===rule.target_key)||{}).sequence,required_qty_ratio:Number(rule.percent)/100,
              supply_mode:rule.supply_mode,requires_delivery:rule.supply_mode==='dedicated_delivery',participates_in_kitting:rule.participates_in_kitting,allow_partial_delivery:rule.allow_partial_delivery,delivery_location_type:rule.delivery_location_type}))
          }
          return operation
        })}
    },
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
        const id=this.id
        const savePayload=id?{...common,expected_version:this.form.business_version}:{...common,creation_session_id:this.sessionId,reservation_token:this.reservationToken}
        const r=await executeProductionDecision('routing_save_'+(id||this.sessionId),savePayload,payload=>id?updateProductionRouting(id,payload):createProductionRouting(payload))
        this.savedId=r.data.data.id;this.form.business_version=r.data.data.business_version;this.pendingSave=null
        if(activate){
          this.pendingActivation={client_command_id:`routing-activate-${uuid()}`,expected_version:this.form.business_version}
          await this.finishActivation()
        }
        this.$message.success(activate?'工艺路线已启用':'工艺路线草稿已保存');const path=`/production/routings/${this.id}${activate?'':'/edit'}`;if(this.pageRoute.path!==path)await this.$router.replace(path);await this.load()
      }catch(e){this.showSaveError(e)}finally{this.loading=false}
    },
    async finishActivation(){const activated=await executeProductionDecision('routing_activate_'+this.id,this.pendingActivation,payload=>activateProductionRouting(this.id,payload));this.form.status=activated.data.data.status;this.pendingActivation=null},
    showSaveError(e){if(e.response&&e.response.status>=400&&e.response.status<500)this.pendingActivation=null;this.$message.error(e.userMessage||e.response&&e.response.data&&e.response.data.message||'保存失败')}
  }
}
</script>
<style scoped src="./production-master.css"></style>
<style scoped>
.sequence-control{display:flex;align-items:center;gap:10px}.sequence-control .el-button{width:22px;height:22px;padding:0;flex-shrink:0}.sequence-control .el-input{min-width:0}.routing-editor ::v-deep .native-expand{padding:0;border-right:0}.routing-editor ::v-deep .native-expand .cell{padding:0;width:0;overflow:hidden}.settings-group ::v-deep .el-input__inner{height:32px;line-height:32px}.settings-group ::v-deep .el-input__icon{line-height:32px}.routing-editor .operation-card{padding-top:16px}.routing-editor .page-heading{margin-bottom:10px}
.routing-editor .el-button--success.is-plain{background:#fff;color:#008b4b;border-color:#008b4b}.routing-editor .el-button--success.is-plain:hover{background:#f0faf5}.material-group .el-input-number{width:94px!important}.material-group ::v-deep .el-input-number .el-input__inner{padding:0 5px}
.catalog-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.catalog-actions .el-button{margin:0}.selector-field{display:flex;gap:6px;align-items:center;min-width:0}.selector-field .el-input{min-width:0;flex:1}.setting-note{color:#77869a;font-size:12px;line-height:1.5;margin:5px 0 12px}.sub-heading{gap:12px;flex-wrap:wrap}
.operation-select-field {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  min-width: 0;
}
.output-rules-launcher {
  display: flex;
  flex-wrap: wrap;
  min-width: 0;
  margin-top: 8px;
}
.output-rules-launcher .el-button {
  max-width: 100%;
  height: auto;
  white-space: normal;
  line-height: 1.5;
}
.operation-select-field .el-select {
  width: 100%;
  min-width: 0;
}
.operation-select-option {
  height: auto;
  min-height: 34px;
  padding-top: 4px;
  padding-bottom: 4px;
  line-height: 1.5;
}
.operation-option {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.operation-option > span {
  min-width: 0;
  white-space: normal;
  overflow-wrap: anywhere;
}
.operation-option .el-tag,
.selected-operation-tag {
  flex-shrink: 0;
}
.routing-editor .form-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:12px 26px}.metadata{border-radius:4px 4px 0 0}.operation-card{border-top:0;border-radius:0 0 4px 4px}.heading-actions,.row-actions,.setting-actions{display:flex;align-items:center;flex-shrink:0}.sub-heading{display:flex;align-items:center;justify-content:space-between;margin:0 0 14px}.sub-heading h2{margin:0;font-size:18px}.operation-settings{display:grid;grid-template-columns:1fr .85fr 1.4fr;margin:-12px -16px;background:#fcfdff}.settings-group{padding:18px;border-right:1px solid #e5eaf0;min-width:0}.settings-group:last-child{border:0}.settings-group h3{margin:0 0 14px;font-size:14px}.settings-group>label{display:grid;grid-template-columns:78px minmax(0,1fr);align-items:center;gap:10px;margin-bottom:14px;font-size:12px}.settings-group .el-select{width:100%;min-width:0}.setting-actions{margin-top:18px}.material-group .el-input-number{width:72px}.remark{margin-top:20px;margin-bottom:0}.row-actions .el-button{padding:8px 0}.routing-editor ::v-deep .el-table th{background:#f7f9fc;color:#26384e}.routing-editor ::v-deep .el-table .cell{word-break:break-word;white-space:normal}.routing-editor ::v-deep .el-table__expanded-cell{padding:12px 16px}.routing-editor ::v-deep .el-input.is-disabled .el-input__inner{color:#5e6c7f;background:#f6f8fb}.routing-editor ::v-deep .el-input-group__append{padding:0 12px}.routing-editor ::v-deep .el-form-item__label{font-size:12px;padding-bottom:6px;line-height:20px}.routing-editor ::v-deep .el-form-item{margin-bottom:16px}
@media(max-width:1500px){.operation-settings{grid-template-columns:1fr 1fr}.material-group{grid-column:1/-1}.settings-group:nth-child(2){border-right:0}.settings-group.material-group{border-top:1px solid #e5eaf0}}
@media(max-width:1100px){.routing-editor .form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.page-heading{gap:16px;flex-wrap:wrap}}
@media(max-width:767px){.routing-editor .form-grid,.operation-settings{grid-template-columns:minmax(0,1fr)}.form-card{padding:14px}.settings-group{border-right:0;border-bottom:1px solid #e5eaf0}.heading-actions{width:100%;flex-wrap:wrap;gap:8px}.heading-actions .el-button{margin:0}.settings-group>label{grid-template-columns:72px minmax(0,1fr)}}
@media(max-width:767px){.operation-card{container-type:inline-size}.operation-settings{width:calc(100cqw - 1px)}.settings-group{padding:14px}.settings-group>label{grid-template-columns:64px minmax(0,1fr);gap:6px}.setting-actions{gap:8px;flex-wrap:wrap}.setting-actions .el-button{margin:0}.routing-editor ::v-deep .el-input-group__append{padding:0 6px}}
</style>
