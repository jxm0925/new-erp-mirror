import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const source = await readFile(new URL('../src/views/erp/production/WorkOrderOutputPlanPanel.vue', import.meta.url), 'utf8')
const apiSource = await readFile(new URL('../src/api/erp/production-output-plans.js', import.meta.url), 'utf8')
const reference = { is_reference:true,line_uuid:null,item_id:10,item_code:'REF',item_name:'当前中间产出',base_unit_name:'件',output_role:'product',planned_base_qty:'4.00000000' }
const other = { is_reference:false,line_uuid:'1e0a74e3-d2e4-4c62-a366-677547f79c76',item_id:20,item_code:'OUT',item_name:'第二种管件',base_unit_name:'米',output_role:'product',planned_base_qty:'0.00000001',remark:'' }
function mount (deps = {}) {
  let id = 0
  const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import[^\n]+\n/gm, '').replace('export default', 'return')
  const options = new Function('window', 'WorkOrderOperationOutputPlan', ...Object.keys(deps), script)({ crypto:{ randomUUID:() => `00000000-0000-4000-8000-${String(++id).padStart(12,'0')}` } }, {}, ...Object.values(deps))
  const messages = []
  const vm = { ...options.data(), workOrder:{ id:7,status:'DRAFT',business_version:2 }, $can:() => true,
    $message:{ success:value => messages.push(value),error:value => messages.push(value) },$emit:() => {},
    $set:(object,key,value) => { object[key] = value },$delete:(object,key) => { delete object[key] },messages }
  for (const [key,fn] of Object.entries(options.methods)) vm[key] = fn.bind(vm)
  for (const [key,fn] of Object.entries(options.computed)) Object.defineProperty(vm,key,{ get:() => fn.call(vm) })
  vm.plan = { editable:true,business_version:2,outputs:[structuredClone(reference)] }
  return vm
}
function client (api, localStorage, backend = 'server-A') {
  const script = apiSource.replace(/^import[^\n]+\n/, '').replace(/export const /g,'const ')
  return new Function('api','localStorage','process', script + '\nreturn { saveWorkOrderPlannedOutputs, pendingWorkOrderOutputPlan }')(api,localStorage,{ env:{ VUE_APP_BASE_API:backend } })
}
const storage = () => {
  const values = new Map([['erp_user','{"legacy_id":1}']])
  return { values,getItem:key => values.get(key) || null,setItem:(key,value) => values.set(key,value),removeItem:key => values.delete(key) }
}

test('the plan and existing work order detail compile with the actual Vue compiler', async () => {
  for (const content of [source,await readFile(new URL('../src/views/erp/production/WorkOrderDetail.vue',import.meta.url),'utf8')]) assert.deepEqual(compiler.compile(compiler.parseComponent(content).template.content).errors,[])
})
test('selection retains other pages, starts quantities blank and never replaces the existing production target', async () => {
  const requests = []
  const vm = mount({ searchWorkOrderOutputOptions:async (id,params) => { requests.push({ id,params }); return { data:{ data:[{ id:params.page===1?20:30,item_code:`PIPE-${params.page}`,item_name:'管件',base_unit_name:'件',base_unit_id:2 }],total:40 } } } })
  vm.draft = [structuredClone(reference)]; vm.pickerVisible = true; vm.keyword = '管件'; vm.categoryId = 5
  await vm.loadOptions(1); vm.toggleSelection(vm.optionRows[0]); await vm.loadOptions(2); vm.toggleSelection(vm.optionRows[0]); vm.applySelection()
  assert.deepEqual(requests[1],{ id:7,params:{ type:'items',keyword:'管件',category_id:5,page:2,per_page:20 } })
  assert.deepEqual(vm.draft.map(row => row.item_id),[10,20,30]); assert.deepEqual(vm.draft.slice(1).map(row => row.planned_base_qty),['',''])
  assert.deepEqual(vm.draft.slice(1).map(row => row.output_role),['product','product']); assert.notEqual(vm.draft[1].line_uuid,vm.draft[2].line_uuid)
  vm.remove(vm.draft[0]); assert.equal(vm.draft.length,3); assert.equal(vm.draft[0].planned_base_qty,reference.planned_base_qty)
})
test('cancelling and reopening clears prior draft and selection while delayed responses cannot return', async () => {
  let resolve
  const vm = mount({ pendingWorkOrderOutputPlan:() => null,searchWorkOrderOutputOptions:() => new Promise(done => { resolve = done }) })
  vm.openEditor(); vm.draft.push(structuredClone(other)); vm.pickerVisible = true
  const request = vm.loadOptions(2)
  vm.editorVisible = false; vm.resetEditor(); resolve({ data:{ data:[{ id:99 }],total:1 } }); await request
  assert.deepEqual(vm.optionRows,[]); assert.equal(vm.optionLoading,false); assert.deepEqual(vm.selected,{})
  vm.openEditor(); assert.equal(vm.draft.length,1); assert.equal(vm.editorVersion,2)
})
test('save preserves tiny decimal quantities and sends no client-authored reference, units or display facts', async () => {
  let sent
  const vm = mount({ pendingWorkOrderOutputPlan:() => null,saveWorkOrderPlannedOutputs:async (id,payload) => { sent = { id,payload } },getWorkOrderPlannedOutputs:async () => ({ data:{ data:{ editable:true,business_version:3,outputs:[reference,other] } } }) })
  vm.draft = [structuredClone(reference),structuredClone(other)]; vm.editorVersion = 2
  await vm.save()
  assert.deepEqual(sent,{ id:7,payload:{ expected_version:2,outputs:[{ line_uuid:other.line_uuid,item_id:20,output_role:'product',planned_base_qty:'0.00000001',remark:'' }] } })
  assert.equal(vm.editorVisible,false); assert.deepEqual(vm.draft,[])
})
test('blank, zero, negative, exponent and overprecision quantities cannot be sent, and read-only users cannot edit', async () => {
  let writes = 0
  const vm = mount({ saveWorkOrderPlannedOutputs:async () => { writes++ } })
  for (const value of ['', '0','0.00000000','-1','1e3','0.000000001']) {
    vm.draft = [reference,{ ...other,planned_base_qty:value }]; await vm.save()
  }
  assert.equal(writes,0); assert.equal(vm.validQuantity('0.00000001'),true)
  assert.equal(vm.validQuantity('0.00000001',4),false); assert.equal(vm.validQuantity('1.20000000',4),true)
  assert.equal(vm.validQuantity('99999999999999999999.99999999'),true); assert.equal(vm.validQuantity('100000000000000000000'),false)
  vm.$can = () => false; vm.openEditor(); assert.equal(vm.editorVisible,false)
})
test('unknown save retries the same version, UUID and quantity after reload and isolates account and server', async () => {
  const localStorage = storage(); const calls = []
  const api = { put:async (path,payload) => { calls.push({ path,payload }); if (calls.length===1) throw new Error('timeout'); return { data:{ data:{} } } } }
  let current = client(api,localStorage)
  const data = { expected_version:2,outputs:[{ ...other }] }
  const first = current.saveWorkOrderPlannedOutputs(7,data,[reference,other]); data.outputs[0].planned_base_qty = '99'
  await assert.rejects(first)
  assert.equal(current.pendingWorkOrderOutputPlan(7).payload.outputs[0].planned_base_qty,'0.00000001')
  localStorage.values.set('erp_user','{"legacy_id":2}'); assert.equal(current.pendingWorkOrderOutputPlan(7),null)
  localStorage.values.set('erp_user','{"legacy_id":1}'); assert.equal(client(api,localStorage,'server-B').pendingWorkOrderOutputPlan(7),null)
  current = client(api,localStorage); await current.saveWorkOrderPlannedOutputs(7,{ expected_version:99,outputs:[] })
  assert.deepEqual(calls[1],calls[0]); assert.equal(current.pendingWorkOrderOutputPlan(7),null)
})
test('one pending write stays single in flight, while definitive rejection permits a corrected revision', async () => {
  let resolve; let requests = 0
  const localStorage = storage()
  const current = client({ put:() => { requests++; return new Promise(done => { resolve=done }) } },localStorage)
  const first = current.saveWorkOrderPlannedOutputs(7,{ expected_version:2,outputs:[] })
  const second = current.saveWorkOrderPlannedOutputs(7,{ expected_version:3,outputs:[other] })
  await Promise.resolve(); assert.equal(first,second); assert.equal(requests,1); resolve({ data:{} }); await first
  const rejected = client({ put:async () => { throw { response:{ status:422,data:{ error_code:'invalid_output_quantity' } } } } },localStorage)
  await assert.rejects(rejected.saveWorkOrderPlannedOutputs(7,{ expected_version:2,outputs:[other] })); assert.equal(rejected.pendingWorkOrderOutputPlan(7),null)
})

test('a retry rejected before ledger lookup retains the original command until access and draft state recover', async () => {
  for (const [status, code] of [[401,'unauthenticated'],[403,'permission_denied'],[404,'not_found'],[408,'request_timeout'],[429,'rate_limited'],[409,'state_conflict']]) {
    const localStorage = storage(); const calls = []
    const api = { put:async (path,payload) => {
      calls.push({ path,payload })
      if (calls.length === 1) throw new Error('response lost after possible commit')
      if (calls.length === 2) throw { response:{ status,data:{ error_code:code } } }
      return { data:{ data:{} } }
    } }
    let current = client(api,localStorage)
    await assert.rejects(current.saveWorkOrderPlannedOutputs(7,{ expected_version:2,outputs:[other] },[reference,other]))
    current = client(api,localStorage)
    await assert.rejects(current.saveWorkOrderPlannedOutputs(7,{ expected_version:99,outputs:[] }),error => error.pendingCommand === true)
    assert.equal(current.pendingWorkOrderOutputPlan(7).payload.expected_version,2)
    current = client(api,localStorage)
    await current.saveWorkOrderPlannedOutputs(7,{ expected_version:100,outputs:[] })
    assert.deepEqual(calls[1],calls[0]); assert.deepEqual(calls[2],calls[0]); assert.equal(current.pendingWorkOrderOutputPlan(7),null)
  }
})
