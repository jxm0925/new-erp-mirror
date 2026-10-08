import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const source = await readFile(new URL('../src/views/erp/production/ProductionInventoryContinuationPanel.vue', import.meta.url), 'utf8')
const apiSource = await readFile(new URL('../src/api/erp/production-continuations.js', import.meta.url), 'utf8')
function mount (deps = {}) {
  const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/import[^\n]+\n/g, '').replace('export default', 'return')
  const options = new Function(...Object.keys(deps), script)(...Object.values(deps))
  const vm = { ...options.data(), workOrder: { id: 7, status: 'DRAFT', business_version: 2, quantity: { target_base_qty: 10 } },
    $can: () => true, $message: { success () {}, warning () {}, error () {} }, $set: (object, key, value) => { object[key] = value }, $delete: (object, key) => { delete object[key] }, $emit () {} }
  for (const [key, fn] of Object.entries(options.methods)) vm[key] = fn.bind(vm)
  for (const [key, fn] of Object.entries(options.computed)) Object.defineProperty(vm, key, { get: () => fn.call(vm) })
  return vm
}
const stock = { inventory_balance_id: 42, source_output_record_id: 10, available_base_qty: '8', serial_tracking_mode: 'required', start_sequence: 20, start_operation_name: '焊接' }

test('continuation panel and WorkOrderDetail compile with the current Vue compiler', async () => {
  for (const content of [source, await readFile(new URL('../src/views/erp/production/WorkOrderDetail.vue', import.meta.url), 'utf8')]) assert.deepEqual(compiler.compile(compiler.parseComponent(content).template.content).errors, [])
})
test('stock identities use real server pagination and retain choices across pages', async () => {
  const requests = []
  const vm = mount({ listInventoryContinuationSerials: async (id, params) => { requests.push({ id, params }); return { data: { data: [{ id: params.page === 1 ? 101 : 121, serial_no: '真实编号' }], meta: { total: 25 } } } } })
  vm.pickerOpen = true; vm.identitySource = stock
  await vm.loadIdentities(1); vm.toggleSerial(vm.serials[0], true); await vm.loadIdentities(2); vm.toggleSerial(vm.serials[0], true)
  assert.equal(vm.draftRows.length, 2); assert.equal(vm.draftQuantity, 2)
  assert.deepEqual(requests[1], { id: 7, params: { inventory_balance_id: 42, source_output_record_id: 10, keyword: '', page: 2, per_page: 20 } })
  assert.equal(vm.draft['42:10:101'].inventory_serial_id, 101)
})
test('closing an inset selector discards delayed results and cancellation keeps saved sources', async () => {
  let resolve
  const vm = mount({ listInventoryContinuationSerials: () => new Promise(done => { resolve = done }) })
  vm.selected = [{ inventory_balance_id: 42, inventory_serial_id: 101, base_qty: 1 }]
  vm.pickerOpen = true; vm.identitySource = stock
  const request = vm.loadIdentities(1)
  vm.closePicker(); resolve({ data: { data: [{ id: 121 }], meta: { total: 25 } } }); await request
  assert.deepEqual(vm.serials, []); assert.equal(vm.serialLoading, false); assert.equal(vm.selected[0].inventory_serial_id, 101)
})
test('quantity stock requires an actual quantity and cannot exceed source or order quantities', () => {
  const vm = mount()
  vm.toggleNone({ ...stock, serial_tracking_mode: 'none' }, true)
  assert.equal(vm.noneSelection(stock).base_qty, null); assert.equal(vm.validate(vm.draftRows), false)
  vm.noneSelection(stock).base_qty = 6; assert.equal(vm.validate(vm.draftRows), true)
  vm.noneSelection(stock).base_qty = 9; assert.equal(vm.validate(vm.draftRows), false)
  assert.equal(vm.validate([{ inventory_balance_id: 80, source_output_record_id: 18, base_qty: 11 }]), false)
  assert.equal(vm.targetQuantity - 6, 4)
})
test('unknown writes replay the identical persisted command after reload and are isolated by account', async () => {
  const values = new Map([['erp_user', '{"legacy_id":1}']])
  const storage = { getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) }
  const calls = []
  const api = { post: async (path, payload) => { calls.push({ path, payload }); if (calls.length === 1) throw new Error('timeout'); return { data: { data: {} } } } }
  const make = () => new Function('api', 'localStorage', 'process', apiSource.replace(/^import[^\n]*\n/, '').replace(/export const /g, 'const ') + '\nreturn { configureInventoryContinuation, pendingInventoryContinuation }')(api, storage, { env: { VUE_APP_BASE_API: 'server-A' } })
  let client = make()
  const editableForm = { expected_version: 1, sources: [{ inventory_balance_id: 42, base_qty: 2 }] }
  const firstRequest = client.configureInventoryContinuation(7, editableForm)
  editableForm.sources[0].base_qty = 99
  await assert.rejects(firstRequest)
  assert.equal(calls[0].payload.sources[0].base_qty, 2)
  assert.equal(client.pendingInventoryContinuation(7).sources[0].base_qty, 2)
  values.set('erp_user', '{"legacy_id":2}'); assert.equal(client.pendingInventoryContinuation(7), null)
  values.set('erp_user', '{"legacy_id":1}'); client = make()
  await client.configureInventoryContinuation(7, { expected_version: 9, sources: [{ inventory_balance_id: 80, base_qty: 9 }] })
  assert.deepEqual(calls[1].payload, calls[0].payload); assert.equal(client.pendingInventoryContinuation(7), null)
})
test('a definitive domain rejection releases the saved command so a corrected selection can be sent', async () => {
  const values = new Map([['erp_user', '{"legacy_id":1}']])
  const localStorage = { getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) }
  const api = { post: async () => { throw { response: { status: 422, data: { message: '数量不足' } } } } }
  const client = new Function('api', 'localStorage', 'process', apiSource.replace(/^import[^\n]*\n/, '').replace(/export const /g, 'const ') + '\nreturn { configureInventoryContinuation, pendingInventoryContinuation }')(api, localStorage, { env: { VUE_APP_BASE_API: 'server-A' } })
  await assert.rejects(client.configureInventoryContinuation(7, { sources: [] })); assert.equal(client.pendingInventoryContinuation(7), null)
})

test('two production outputs in one balance keep their own checkpoint, quantities and saved identities', async () => {
  let sent
  const first = { ...stock, available_base_qty: 2, source_work_order_no: 'WO-A' }
  const second = { ...stock, source_output_record_id: 20, available_base_qty: 2, start_sequence: 30, start_operation_name: '试水', source_work_order_no: 'WO-B' }
  const vm = mount({ listInventoryContinuationCandidates: async () => ({ data: { data: [first, second], meta: { total: 2 } } }), configureInventoryContinuation: async (id, payload) => { sent = { id, payload } } })
  vm.pickerOpen = true; vm.identitySource = first; vm.toggleSerial({ id: 101, serial_no: 'A-101' }, true)
  vm.identitySource = second; vm.toggleSerial({ id: 202, serial_no: 'B-202' }, true)
  await vm.loadCandidates(1)
  assert.equal(vm.identityCount(first), 1); assert.equal(vm.identityCount(second), 1)
  assert.equal(vm.draft['42:10:101'].start_sequence, 20); assert.equal(vm.draft['42:20:202'].start_sequence, 30)
  assert.equal(vm.draft['42:10:101'].source_work_order_no, 'WO-A')
  assert.equal(vm.validate(vm.draftRows), true)
  vm.applyPicker(); await vm.save()
  assert.deepEqual(sent.payload.sources, [
    { inventory_balance_id: 42, source_output_record_id: 10, inventory_serial_id: 101, base_qty: '1' },
    { inventory_balance_id: 42, source_output_record_id: 20, inventory_serial_id: 202, base_qty: '1' }
  ])
  assert.equal(sent.id, 7)
})

test('an identity response cannot cross into another output of the same inventory balance', async () => {
  let finish
  const vm = mount({ listInventoryContinuationSerials: () => new Promise(resolve => { finish = resolve }) })
  vm.pickerOpen = true; vm.identitySource = stock
  const request = vm.loadIdentities(1)
  vm.identitySource = { ...stock, source_output_record_id: 20 }
  finish({ data: { data: [{ id: 101 }], meta: { total: 1 } } }); await request
  assert.deepEqual(vm.serials, [])
  assert.equal(vm.validate([{ ...stock, inventory_serial_id: 101, base_qty: 1 }, { ...stock, source_output_record_id: 20, inventory_serial_id: 101, base_qty: 1 }]), false)
})
