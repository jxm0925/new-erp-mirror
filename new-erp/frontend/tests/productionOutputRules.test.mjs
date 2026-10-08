import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
const require=createRequire(import.meta.url)
const compiler=require('vue-template-compiler')
const names=['RoutingOutputRulesDialog','WorkOrderOperationOutputPlan','ProductionRoutingForm']
const sources=Object.fromEntries(await Promise.all(names.map(async name=>[name,await readFile(new URL('../src/views/erp/production/'+name+'.vue',import.meta.url),'utf8')])))
function mount(name,deps={}){
  let counter=0
  const defaults={PurchaseItemPicker:{},RoutingOutputRulesDialog:{},cachedPageRoute:{},ProductSkuPicker:{},ProductionProcessCatalogPanel:{},window:{crypto:{randomUUID:()=>`00000000-0000-4000-8000-${String(++counter).padStart(12,'0')}`}}}
  const bindings={...defaults,...deps}
  const script=sources[name].match(/<script>([\s\S]*?)<\/script>/)[1].replace(/import[\s\S]*?from ['"][^'"]+['"]\s*/g,'').replace('export default','return')
  const options=new Function(...Object.keys(bindings),script)(...Object.values(bindings))
  const events=[];const warnings=[]
  const vm={...options.data(),workOrder:{id:9,business_version:3},pageRoute:{params:{},path:'/production/routings/create'},$refs:{picker:{visible:false,open(value){this.last=value}}},$emit:(...args)=>events.push(args),$message:{warning:value=>warnings.push(value)},$can:()=>true,$set:(object,key,value)=>{object[key]=value},events,warnings}
  for(const [key,fn]of Object.entries(options.methods))vm[key]=fn.bind(vm)
  for(const [key,fn]of Object.entries(options.computed||{}))Object.defineProperty(vm,key,{get:()=>fn.call(vm)})
  return vm
}
const rule={output_rule_key:'b29224a5-ae71-4106-a35c-53f3f8f30f1d',item_id:20,item_name_snapshot:'产出管件',base_unit_name_snapshot:'件',base_qty_per_reference_unit:'0.12500000',output_role:'product',quality_mode:'required',output_mode:'warehouse_required',allow_continue_without_warehouse:false,remark:''}
const operation={key:'node-1',output_item_id:10,output_rules:[rule],quality_mode:'none',output_mode:'flow_only'}

test('new route rules and preview compile with the installed Vue compiler',()=>{
  for(const content of Object.values(sources))assert.deepEqual(compiler.compile(compiler.parseComponent(content).template.content).errors,[])
})
test('cancelling rule changes keeps the source, reopening removes draft state, and ratios stay exact',()=>{
  const vm=mount('RoutingOutputRulesDialog')
  vm.open({operation,referenceItem:{item_name:'成品',unit:{unit_name:'件'}}})
  vm.draft[0].base_qty_per_reference_unit='9';vm.draft[0].quality_mode='none';vm.visible=false;vm.reset()
  assert.equal(operation.output_rules[0].base_qty_per_reference_unit,'0.12500000')
  vm.open({operation,referenceItem:{item_name:'成品',unit:{unit_name:'件'}}});vm.confirm()
  assert.equal(vm.events[0][1].rules[0].base_qty_per_reference_unit,'0.12500000')
  assert.equal(vm.events[0][1].rules[0].quality_mode,'required')
})
test('adding several selected items keeps UUIDs unique, ignores duplicates and leaves unknown quantities blank',()=>{
  const vm=mount('RoutingOutputRulesDialog');vm.open({operation,referenceItem:{}})
  vm.addItems([{id:20},{id:30,item_name:'新管件',unit:{unit_name:'米'}},{id:40,item_name:'副产物料',unit:{unit_name:'千克'}}])
  assert.deepEqual(vm.draft.map(row=>row.item_id),[20,30,40]);assert.notEqual(vm.draft[1].output_rule_key,vm.draft[2].output_rule_key)
  assert.deepEqual(vm.draft.slice(1).map(row=>row.base_qty_per_reference_unit),['',''])
  vm.confirm();assert.equal(vm.events.length,0)
  vm.visible=false;vm.addItems([{id:50}]);assert.equal(vm.draft.length,3)
  vm.open({operation,referenceItem:{},readOnly:true});vm.addItems([{id:50}]);vm.remove(vm.draft[0]);vm.confirm();assert.equal(vm.draft.length,1);assert.equal(vm.events.length,0)
})

test('saved reference labels survive master renames and each output retains its explicit continue policy',()=>{
  const vm=mount('RoutingOutputRulesDialog')
  const saved={...operation,output_rules:[{...rule,reference_item_id:10,reference_item_name_snapshot:'原产出',reference_base_unit_name_snapshot:'原单位'}]}
  vm.open({operation:saved,referenceItem:{id:10,item_name:'已改名称',unit:{unit_name:'已改单位'}}})
  assert.equal(vm.referenceName,'原产出');assert.equal(vm.referenceUnit,'原单位')
  vm.changeMode(vm.draft[0],'warehouse_optional');assert.equal(vm.draft[0].allow_continue_without_warehouse,false)
  vm.draft[0].allow_continue_without_warehouse=true;vm.changeMode(vm.draft[0],'warehouse_required')
  assert.equal(vm.draft[0].allow_continue_without_warehouse,false)
  vm.open({operation:saved,referenceItem:{id:99,item_name:'另一参考',unit:{unit_name:'米'}}})
  assert.equal(vm.referenceName,'另一参考');assert.equal(vm.referenceUnit,'米')
})
test('a fractional coefficient is allowed for a whole-piece unit, while invalid or exponent ratios cannot be confirmed',()=>{
  const vm=mount('RoutingOutputRulesDialog')
  for(const value of ['',0,-1,'1e3','0.000000001']){vm.open({operation,referenceItem:{}});vm.draft[0].base_qty_per_reference_unit=value;vm.confirm()}
  assert.equal(vm.events.length,0)
  vm.open({operation,referenceItem:{}});vm.draft[0].base_qty_per_reference_unit='0.00000001';vm.confirm()
  assert.equal(vm.events[0][1].rules[0].base_qty_per_reference_unit,'0.00000001')
})
test('route save serializes rule facts and keeps the separate production target and reference immutable until rules are cleared',()=>{
  const vm=mount('ProductionRoutingForm')
  vm.form.output_item_id=10;vm.form.operations=[{...operation,operation_id:5,sequence:10,execution_context:'production',material_supply_rules:[],packaging_materials:[],parameters_text:'',setup_standard_minutes:0,unit_standard_minutes:2}]
  const payload=vm.payload()
  assert.equal(payload.operations[0].output_item_id,10)
  assert.deepEqual(Object.keys(payload.operations[0].output_rules[0]),['output_rule_key','item_id','output_role','base_qty_per_reference_unit','quality_mode','output_mode','allow_continue_without_warehouse','remark'])
  assert.equal(payload.operations[0].output_rules[0].base_qty_per_reference_unit,'0.12500000')
  vm.pickerTarget={mode:'output'};vm.selectItem({id:99,item_name:'另一参考产出'});assert.equal(vm.form.output_item_id,10)
  vm.applyOutputRules({operationKey:'node-1',rules:[]});vm.selectItem({id:99,item_name:'另一参考产出'});assert.equal(vm.form.output_item_id,99)
})
test('preview ignores an old request after switching work order and renders the authoritative frozen response',async()=>{
  const jobs=[]
  const vm=mount('WorkOrderOperationOutputPlan',{getWorkOrderOutputPlanPreview:id=>new Promise(resolve=>jobs.push({id,resolve}))})
  const first=vm.load();vm.workOrder={id:10,business_version:4};const second=vm.load()
  const frozen={immutable:true,operations:[],issues:[],execution_blockers:[]}
  jobs[1].resolve({data:{data:frozen}});await second;jobs[0].resolve({data:{data:{immutable:false}}});await first
  assert.equal(vm.plan,frozen);assert.equal(vm.loading,false);assert.deepEqual(jobs.map(row=>row.id),[9,10])
  vm.plan={issues:[{code:'quantity',message:'产出数量不一致'}],execution_blockers:[{code:'quantity',message:'产出数量不一致'}]};assert.equal(vm.messages.length,1)
})

test('preview preserves distinct operation issues and identifies their output quantity differences',()=>{
  const vm=mount('WorkOrderOperationOutputPlan')
  const issue={code:'quantity',message:'产出数量不一致',routing_operation_id:1,output_rule_key:'a',details:{rule_base_qty:'0.12500000',planned_base_qty:'1.00000000'}}
  vm.plan={operations:[{routing_operation_id:1,sequence:10,operation_name:'切割',outputs:[{output_rule_key:'a',item_name:'管件',base_unit_name:'件'}]}],issues:[issue,{...issue,routing_operation_id:2},{...issue,output_rule_key:'b'}],execution_blockers:[issue]}
  assert.equal(vm.messages.length,3)
  assert.equal(vm.issueTitle(issue),'10 · 切割，管件：产出数量不一致（规则计算 0.125，计划 1 件）')
  vm.plan.status='legacy_snapshot';assert.equal(vm.planStatus,'历史快照')
  vm.plan.status='frozen';assert.equal(vm.planStatus,'已冻结')
  assert.equal(vm.outputRole(null),'—');assert.equal(vm.qualityMode(null),'—')
})
