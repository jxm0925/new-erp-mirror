import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const apiSource = await readFile(new URL('../src/api/erp/assembly-production.js', import.meta.url), 'utf8')
const sources = Object.fromEntries(await Promise.all(['WorkOrderAssemblyPanel', 'ProductionJobBundlePanel', 'CuttingCreate'].map(async name => [name, await readFile(new URL('../src/views/erp/production/' + name + '.vue', import.meta.url), 'utf8')])))
function api(storage = new Map()) {
  const localStorage = { getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) }
  return new Function('api', 'localStorage', 'process', apiSource.replace(/import[^\n]+\n/, '').replace(/export /g, '') + '\nreturn {executeAssemblyCommand,pendingAssemblyCommand,productionQuantity};')({}, localStorage, { env: { VUE_APP_BASE_API: 'http://fixture/api' } })
}
function mount(name, dependencies = {}) {
  const exports = api()
  const bindings = { ...exports, bundleStatusName: x => x, ...dependencies }
  const script = sources[name].match(/<script>([\s\S]*?)<\/script>/)[1].replace(/import[\s\S]*?from ['"][^'"]+['"]\s*/g, '').replace('export default', 'return')
  const component = new Function(...Object.keys(bindings), script)(...Object.values(bindings))
  const messages = []
  const vm = { ...component.data(), workOrder: { id: 10, status: 'DRAFT' }, $router: { push() {} }, $can: () => true, $emit() {}, $set: (object, key, value) => { object[key] = value }, $delete: (object, key) => { delete object[key] }, $message: { success() {}, error: message => messages.push(message) }, messages }
  for (const [key, fn] of Object.entries(component.methods)) vm[key] = fn.bind(vm)
  for (const [key, fn] of Object.entries(component.computed || {})) Object.defineProperty(vm, key, { get: () => fn.call(vm) })
  return vm
}

test('assembly and shared job views compile against installed Vue 2', () => {
  for (const source of Object.values(sources)) assert.deepEqual(compiler.compile(compiler.parseComponent(source).template.content).errors, [])
})
test('lost response retries exact version, identities and decimal quantity; concurrent clicks share the command', async () => {
  const subject = api(); const seen = []
  const original = { expected_version: 3, quantity: '999999999999.0001', tasks: [{ task_id: 8, expected_task_version: 2 }] }
  let reject
  const first = subject.executeAssemblyCommand('create', original, data => { seen.push(structuredClone(data)); return new Promise((resolve, fail) => { reject = fail }) })
  assert.equal(subject.executeAssemblyCommand('create', { expected_version: 9 }, () => assert.fail('duplicate send')), first)
  await Promise.resolve(); reject(new Error('lost response')); await assert.rejects(first)
  await subject.executeAssemblyCommand('create', { expected_version: 90, quantity: '1' }, data => { seen.push(structuredClone(data)); return Promise.resolve('ok') })
  assert.deepEqual(seen[1], seen[0]); assert.equal(seen[1].quantity, original.quantity); assert.equal(subject.pendingAssemblyCommand('create'), null)
})
test('definitive version rejection clears the command so a refreshed selection may be submitted', async () => {
  const subject = api()
  await assert.rejects(subject.executeAssemblyCommand('prepare', { expected_version: 1 }, () => Promise.reject({ response: { status: 409, data: { error_code: 'version_conflict' } } })))
  assert.equal(subject.pendingAssemblyCommand('prepare'), null)
})
test('decimal display preserves large stock quantities without floating point conversion', () => {
  const subject = api()
  assert.equal(subject.productionQuantity('99999999999999999.00010000'), '99999999999999999.0001')
  assert.equal(subject.productionQuantity('0.00000000'), '0'); assert.equal(subject.productionQuantity(null), '—')
})
test('prepared or blocked assembly cannot repeat preparation and component paths use names', () => {
  const vm = mount('WorkOrderAssemblyPanel')
  vm.plan = { status: 'preview', components: [{ path_key: 'frame', parent_path: 'root', item: { name: '架子' } }, { parent_path: 'frame', item: { name: '管件' } }], issues: [] }
  assert.equal(vm.canPrepare, true); assert.equal(vm.componentPath(vm.components[1]), '架子 → 管件')
  vm.plan.immutable = true; assert.equal(vm.canPrepare, false)
  vm.plan.immutable = false; vm.plan.issues = [{ message: '单位不完整' }]; assert.equal(vm.canPrepare, false)
})
test('lost preparation response can retry its stored version even while preview is unavailable', async () => {
  let observed
  const vm = mount('WorkOrderAssemblyPanel', { executeAssemblyCommand: async (key, data, send) => { observed = data; return send(data) }, prepareAssembly: async () => ({}) })
  vm.pending = { payload: { expected_version: 7 } }; vm.plan = null; vm.load = async () => {}
  await vm.prepare(); assert.equal(observed.expected_version, 7)
})
test('cross-page task selection preserves independent items and rejects incompatible frozen parameters', async () => {
  const vm = mount('ProductionJobBundlePanel', { jobBundleCandidates: async () => ({ data: { data: [{ task_id: 2 }], total: 21 } }) })
  vm.createVisible = true
  vm.toggleCandidate({ task_id: 1, eligible: true, compatibility_key: 'A', item: { name: '架子' } }, true)
  await vm.changeCandidatePage(2)
  vm.toggleCandidate({ task_id: 2, eligible: true, compatibility_key: 'A', item: { name: '电箱' } }, true)
  vm.toggleCandidate({ task_id: 3, eligible: true, compatibility_key: 'B' }, true)
  assert.deepEqual(vm.selectedRows.map(row => row.task_id), [1, 2]); assert.equal(vm.messages.length, 1)
  vm.pending = { payload: {} }; vm.toggleCandidate(vm.selectedRows[0], false); assert.equal(vm.selectedRows.length, 2)
  vm.pending = null; vm.resetCreate(); assert.equal(vm.selectedRows.length, 0); assert.equal(vm.candidatePage, 1)
})
test('late responses from an old work order cannot replace the current assembly plan', async () => {
  let firstResolve
  const vm = mount('WorkOrderAssemblyPanel', { getAssemblyPlan: id => id === 10 ? new Promise(resolve => { firstResolve = resolve }) : Promise.resolve({ data: { data: { id: 20 } } }) })
  const first = vm.load(); vm.workOrder.id = 20; await vm.load()
  firstResolve({ data: { data: { id: 10 } } }); await first
  assert.equal(vm.plan.id, 20); assert.equal(vm.loading, false)
})
test('formal cutting keeps consumer separate from explicitly selected producer and raw input; no quantity is invented', async () => {
  let payload
  const vm = mount('CuttingCreate', { listCuttingDemands: async () => ({ data: { data: [], meta: { total: 0 } } }), publishCuttingOrder: async data => { payload = data; return { data: { data: { cutting_order_id: 7 } } } } })
  vm.selectDemand({ id: 1, remaining_demand_qty: '0.12500000', consumer_work_order: { id: 90 }, item: { item_name: '管件' } }, true)
  assert.equal(vm.selectedRows[0].planned_qty, '')
  await vm.publish(); assert.equal(payload, undefined)
  vm.selectedRows[0].producer = { work_order_id: 30, stage_id: 5, input_material_requirement_id: 12, target_material_requirement_id: 18 }
  vm.selectedRows[0].planned_qty = '0.12500000'; await vm.publish()
  assert.equal(payload.plans[0].work_order_id, 30); assert.equal(payload.plans[0].planned_qty, '0.12500000')
  assert.equal(payload.plans[0].input_material_requirement_id, 12); assert.equal(payload.plans[0].target_material_requirement_id, 18)
  assert.equal(Object.hasOwn(payload, 'selected_materials'), false)
})
test('formal cutting freezes first submission and restores the original display for a lost-response retry', async () => {
  let rejectFirst
  const seen = []
  const vm = mount('CuttingCreate', { publishCuttingOrder: data => {
    seen.push(structuredClone(data))
    return seen.length === 1 ? new Promise((resolve, reject) => { rejectFirst = reject }) : Promise.resolve({ data: { data: { cutting_order_id: 7 } } })
  } })
  vm.selectDemand({ id: 1, remaining_demand_qty: '2', item: { item_name: '管件' } }, true)
  vm.selectedRows[0].producer = { work_order_id: 30, stage_id: 5, input_material_requirement_id: 12, target_material_requirement_id: 18 }
  vm.selectedRows[0].planned_qty = '0.12500000'
  const first = vm.publish()
  assert.equal(vm.editLocked, true)
  vm.selectDemand({ id: 2, remaining_demand_qty: '1' }, true); vm.selectDemand(vm.selectedRows[0], false)
  vm.openProducer(vm.selectedRows[0]); assert.equal(vm.producerVisible, false)
  vm.producerDemandId = 1; vm.chooseProducer({ eligible: true, work_order_id: 99 })
  assert.equal(vm.selectedRows.length, 1); assert.equal(vm.selectedRows[0].producer.work_order_id, 30)
  // A model event already queued before disabling the form must not change
  // the retained command's visible quantity once its response is lost.
  vm.selectedRows[0].planned_qty = '9'
  await Promise.resolve(); rejectFirst(new Error('lost response')); await first
  assert.equal(vm.editLocked, true); assert.equal(vm.selectedRows[0].planned_qty, '0.12500000')
  assert.equal(vm.selectedRows[0].producer.work_order_id, 30)
  await vm.publish(); assert.deepEqual(seen[1], seen[0])
})
test('shared job creation locks its first request, restores its title and tasks, and reopens only that pending request', async () => {
  let rejectFirst; let candidateReads = 0
  const seen = []
  const vm = mount('ProductionJobBundlePanel', {
    jobBundleCandidates: async () => { candidateReads += 1; return { data: { data: [], total: 0 } } },
    createJobBundle: data => { seen.push(structuredClone(data)); return seen.length === 1 ? new Promise((resolve, reject) => { rejectFirst = reject }) : Promise.resolve({ data: { data: { id: 8 } } }) },
  })
  vm.createVisible = true; vm.title = '共同焊接'; vm.load = async () => {}; vm.openDetail = async () => {}
  vm.toggleCandidate({ task_id: 1, task_business_version: 3, eligible: true, compatibility_key: 'A', item: { name: '架子' } }, true)
  vm.toggleCandidate({ task_id: 2, task_business_version: 4, eligible: true, compatibility_key: 'A', item: { name: '电箱' } }, true)
  const first = vm.create()
  assert.equal(vm.editLocked, true)
  vm.toggleCandidate({ task_id: 3, eligible: true, compatibility_key: 'A' }, true); vm.toggleCandidate(vm.selectedRows[0], false)
  vm.openCreate(); await vm.searchCandidates(); await vm.changeCandidatePage(2)
  assert.equal(vm.selectedRows.length, 2); assert.equal(vm.candidatePage, 1); assert.equal(candidateReads, 0)
  vm.title = '排队事件改名'
  await Promise.resolve(); rejectFirst(new Error('lost response')); await first
  assert.equal(vm.title, '共同焊接'); assert.equal(vm.editLocked, true)
  vm.resetCreate(); vm.openCreate()
  assert.equal(vm.title, '共同焊接'); assert.deepEqual(vm.selectedRows.map(row => row.task_id), [1, 2]); assert.equal(candidateReads, 0)
  await vm.create(); assert.deepEqual(seen[1], seen[0])
})
