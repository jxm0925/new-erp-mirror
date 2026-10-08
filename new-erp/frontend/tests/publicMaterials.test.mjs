import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

function mount(source, deps = {}) {
  const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '').replace('export default', 'return')
  const options = new Function(...Object.keys(deps), script)(...Object.values(deps))
  const vm = { ...options.data(), $set: (map, key, value) => { map[key] = value }, $delete: (map, key) => { delete map[key] } }
  for (const [key, fn] of Object.entries(options.methods)) vm[key] = fn.bind(vm)
  for (const [key, fn] of Object.entries(options.computed || {})) Object.defineProperty(vm, key, { get: () => fn.call(vm) })
  return vm
}
const publicSource = await readFile(new URL('../src/views/erp/inventory/PublicMaterialPreparationPanel.vue', import.meta.url), 'utf8')
const procurementSource = await readFile(new URL('../src/views/erp/inventory/MaterialProcurementDialog.vue', import.meta.url), 'utf8')
const onsiteSource = await readFile(new URL('../src/views/erp/inventory/OnsiteMaterialCollectionPanel.vue', import.meta.url), 'utf8')
const deps = { StockSelector: {}, OptionPicker: {}, pendingMaterialWrite: () => null }

test('public draft keeps cross-page source identity and each work order version in one request', async () => {
  const vm = mount(publicSource, deps)
  vm.draft.warehouse = { id: 7 }
  vm.draft.selected = { 2: { demand: { id: 2, work_order_id: 10, work_order_version: 3 }, sources: [{ id: 22, quantity: '2.5' }] },
    3: { demand: { id: 3, work_order_id: 20, work_order_version: 8 }, sources: [{ id: 33, quantity: '1' }] } }
  let sent
  vm.write = async (action, payload) => { sent = { action, payload } }
  await vm.create()
  assert.deepEqual(sent.payload.work_order_versions, { 10: 3, 20: 8 })
  assert.deepEqual(sent.payload.lines, [{ target_material_requirement_id: 2, inventory_balance_id: 22, planned_pick_qty: '2.5' }, { target_material_requirement_id: 3, inventory_balance_id: 33, planned_pick_qty: '1' }])
})

test('unclaimed work exposes claim, and only its assigned picker sees start and confirmation', () => {
  const vm = mount(publicSource, { ...deps, localStorage: { getItem: () => '{"legacy_id":7}' } })
  vm.$can = () => true
  vm.detail.data = { status: 'WAIT_PICK', assigned_picker_legacy_id: null }
  assert.equal(vm.canClaim, true); assert.equal(vm.canStart, false)
  vm.detail.data.assigned_picker_legacy_id = 7; assert.equal(vm.canClaim, false); assert.equal(vm.canStart, true)
  vm.detail.data.status = 'PICKING'; assert.equal(vm.canConfirm, true)
  vm.detail.data.assigned_picker_legacy_id = 8; assert.equal(vm.canStart, false); assert.equal(vm.canConfirm, false)
})

test('public confirmation requires all pages and explicit zero while retaining physical identities', async () => {
  const vm = mount(publicSource, deps)
  vm.detail.data = { lines: { total: 2 } }
  vm.detail.lineMap = { 1: { planned_pick_qty: 5, material_management_mode: 'physical', serial_control_type: 'none' }, 2: { planned_pick_qty: 7, material_management_mode: 'quantity', serial_control_type: 'none' } }
  vm.detail.quantities = { 1: '1' }; vm.detail.tracked = { 1: [{ id: 42 }] }
  let sent
  vm.action = async (action, payload) => { sent = payload }
  vm.confirm(); assert.equal(sent, undefined); assert.match(vm.detail.error, /各页/)
  vm.detail.quantities[2] = '0'; await vm.confirm()
  assert.deepEqual(sent.lines[0].physical_material_ids, [42])
  assert.equal(sent.lines[1].actual_pick_qty, '0')
})

test('detail pagination retains counts at the same version and discards them when the task changes', async () => {
  let version = 1
  const vm = mount(publicSource, { ...deps, publicPreparation: async () => ({ data: { data: { business_version: version, lines: { data: [{ id: version }] } } } }) })
  vm.detail.id = 9
  await vm.loadDetail(); vm.detail.quantities[1] = '4'
  await vm.loadDetail(); assert.equal(vm.detail.quantities[1], '4')
  version = 2; await vm.loadDetail()
  assert.equal(vm.detail.quantities[1], undefined); assert.deepEqual(vm.detail.tracked, {})
  assert.match(vm.detail.error, /重新核对/)
})

test('late demand search cannot overwrite a newer page', async () => {
  const waits = []
  const vm = mount(publicSource, { ...deps, materialDemands: () => new Promise(resolve => waits.push(resolve)) })
  const old = vm.load(); const current = vm.load()
  waits[1]({ data: { data: [{ id: 2 }], total: 1 } }); await current
  waits[0]({ data: { data: [{ id: 1 }], total: 1 } }); await old
  assert.equal(vm.rows[0].id, 2)
})

test('procurement preserves quantities across pages and sends explicit stock-unit quantities with optional order', async () => {
  let sent
  const vm = mount(procurementSource, { ...deps, materialWrite: async (key, payload) => { sent = payload; return { data: { data: { request_no: 'PRQ-1' } } } }, createMaterialProcurement: () => {} })
  vm.$message = { success() {} }; vm.$emit = () => {}
  vm.lines = [{ id: 1, item_name: '方管', unit_name: '根', request_qty: '3' }]
  vm.picker.selected = { 1: vm.lines[0], 2: { id: 2, item_name: '板材', unit_name: '张' } }
  vm.acceptPicker(); assert.equal(vm.lines[0].request_qty, '3'); assert.equal(vm.lines[1].request_qty, '')
  vm.remark = '现场缺料'; assert.equal(vm.valid, false)
  vm.lines[1].request_qty = '2'; assert.equal(vm.valid, true)
  vm.mode = 'order'; assert.ok(!vm.valid)
  vm.order = { id: 55 }; assert.equal(vm.valid, true)
  await vm.submit()
  assert.equal(sent.sales_order_id, 55); assert.deepEqual(sent.items.map(row => row.request_qty), ['3', '2'])
})

test('onsite collection accepts only selected pieces and enforces the remaining quantity', () => {
  const vm = mount(onsiteSource, { OptionPicker: {} })
  const row = { accepted: '2', remaining_qty: '3', material_management_mode: 'physical', serial_control_type: 'none', physicals: [{ id: 1 }], serials: [] }
  assert.equal(vm.valid(row), false)
  row.physicals.push({ id: 2 }); assert.equal(vm.valid(row), true)
  row.accepted = '4'; assert.equal(vm.valid(row), false)
})
