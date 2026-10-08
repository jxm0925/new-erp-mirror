import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const names = ['ProductionRoutingForm', 'ProductionOperationForm', 'ProductionProcessCatalogPanel']
const sources = Object.fromEntries(await Promise.all(names.map(async name => [name,
  await readFile(new URL('../src/views/erp/production/' + name + '.vue', import.meta.url), 'utf8')
])))

function mount(name, dependencies = {}, allowed = true) {
  const script = sources[name].match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/import[\s\S]*?from ['"][^'"]+['"]\s*/g, '').replace('export default', 'return')
  const defaults = { cachedPageRoute: {}, PurchaseItemPicker: {}, ProductSkuPicker: {}, ProductionProcessCatalogPanel: {}, RoutingOutputRulesDialog: {},
    window: { crypto: { randomUUID: () => 'created-node' } } }
  const bindings = { ...defaults, ...dependencies }
  const options = new Function(...Object.keys(bindings), script)(...Object.values(bindings))
  const vm = { ...options.data(), pageRoute: { params: {}, path: '/production/routings/create' },
    $route: { params: {}, path: '/production/operations/create' }, $can: () => allowed, $refs: {}, warnings: [],
    $set: (object, key, value) => { object[key] = value }, $nextTick: callback => callback(), $emit() {} }
  vm.$message = { success() {}, error() {}, warning(message) { vm.warnings.push(message) } }
  for (const [key, fn] of Object.entries(options.methods)) vm[key] = fn.bind(vm)
  for (const [key, getter] of Object.entries(options.computed || {})) Object.defineProperty(vm, key, { get: getter.bind(vm) })
  return vm
}

function node(overrides = {}) {
  return { id: 50, operation_id: 20, sequence: 10, output_item_id: 7, output_mode: 'flow_only', quality_mode: 'none',
    work_mode: 'manual', setup_standard_minutes: 0, unit_standard_minutes: 5, material_supply_rules: [], ...overrides }
}

for (const name of names) test(name + ' compiles with the current Vue template compiler', () => {
  const descriptor = compiler.parseComponent(sources[name])
  assert.deepEqual(compiler.compile(descriptor.template.content).errors, [])
})

test('ordinary route edits retain node ids and BOM targets while omitting hidden performance fields', () => {
  const vm = mount('ProductionRoutingForm', {}, false)
  vm.applyData({ output_item_id: 7, operations: [
    node({ production_stage_id: 3, stage: { id: 3, name: '焊接', code: 'welding' }, performance_rate: '0.375599',
      material_supply_rules: [{ component_item_id: 9, target_routing_operation_id: 51, required_qty_ratio: '0.290000', supply_mode: 'dedicated_delivery', participates_in_kitting: true }] }),
    node({ id: 51, sequence: 20 })
  ] })
  assert.equal(Object.hasOwn(vm.form.operations[0], 'performance_rate'), false)
  assert.equal(Object.hasOwn(vm.form.operations[0], 'performance_percent'), false)
  const rows = vm.payload().operations
  assert.equal(rows[0].id, 50)
  assert.equal(rows[0].production_stage_id, 3)
  assert.equal(Object.hasOwn(rows[0], 'performance_rate'), false)
  assert.equal(rows[0].material_supply_rules[0].target_sequence, 20)
  assert.equal(rows[0].material_supply_rules[0].required_qty_ratio, 0.29)
  assert.equal(vm.incomingRules(vm.form.operations[1]).length, 1)
  assert.equal(vm.validateOperation(vm.form.operations[0]), true)
  assert.equal(vm.catalogName('stages', 3), '焊接')
})

test('authorized performance editing preserves six-place ratios and distinguishes zero from an unconfigured rate', () => {
  const vm = mount('ProductionRoutingForm')
  vm.applyData({ operations: [node({ performance_rate: '0.123456' }), node({ id: 51, performance_rate: '0.000000' }), node({ id: 52, performance_rate: null })] })
  assert.deepEqual(vm.form.operations.map(row => row.performance_percent), ['12.3456', '0', ''])
  assert.deepEqual(vm.payload().operations.map(row => row.performance_rate), [0.123456, 0, null])
  const row = vm.form.operations[0]
  for (const value of ['100.0001', '-1', '20.00001', '1e1', 'abc']) {
    row.performance_percent = value
    assert.equal(vm.validateOperation(row), false, value)
  }
  row.performance_percent = '100'
  assert.equal(vm.validateOperation(row), true)
  assert.equal(vm.payload().operations[0].performance_rate, 1)
})

test('packaging quantities remain empty until entered and use their own schema rather than BOM percentages', () => {
  const vm = mount('ProductionRoutingForm')
  vm.applyData({ operations: [node(), node({ id: 51, sequence: 20, execution_context: 'shipment', packaging_scheme_id: 4 })] })
  const row = vm.form.operations[1]
  vm.pickerTarget = { mode: 'packaging', row }
  vm.selectMaterials([{ id: 19, item_name: '纸箱', unit: { symbol: '只' } }])
  assert.equal(row.packaging_materials[0].base_qty_per_output_unit, '')
  assert.equal(vm.validateOperation(row), false)
  for (const value of ['0', '-3', 'abc', '1000000000']) {
    row.packaging_materials[0].base_qty_per_output_unit = value
    assert.equal(vm.validateOperation(row), false)
  }
  row.packaging_materials[0].base_qty_per_output_unit = '2.5'
  assert.equal(vm.validateOperation(row), true)
  const rows = vm.payload().operations
  assert.equal(rows[1].packaging_scheme_id, 4)
  assert.deepEqual(rows[1].packaging_materials, [{ component_item_id: 19, base_qty_per_output_unit: 2.5 }])
  assert.equal(Object.hasOwn(rows[1], 'material_supply_rules'), false)
  assert.equal(Object.hasOwn(rows[0], 'packaging_materials'), false)
  assert.equal(vm.itemUnit(19), '只')
})

test('context changes protect configured materials and all additions and moves keep shipment last', () => {
  const vm = mount('ProductionRoutingForm')
  vm.applyData({ operations: [node({ material_supply_rules: [{ component_item_id: 9, target_routing_operation_id: 50, required_qty_ratio: 1 }] }),
    node({ id: 51, sequence: 20, execution_context: 'shipment', packaging_scheme_id: 4 })] })
  const production = vm.form.operations[0]
  const shipment = vm.form.operations[1]
  vm.changeExecutionContext(production, 'shipment')
  assert.equal(production.execution_context, 'production')
  assert.equal(production.material_supply_rules.length, 1)
  vm.changeExecutionContext(shipment, 'production')
  assert.equal(shipment.execution_context, 'shipment')
  vm.addOperation()
  assert.deepEqual(vm.form.operations.map(row => row.execution_context), ['production', 'production', 'shipment'])
  assert.equal(vm.canMove(1, 1), false)
  vm.move(2, -1)
  assert.equal(vm.form.operations[2], shipment)
  production.material_supply_rules = []
  vm.changeExecutionContext(production, 'shipment')
  assert.deepEqual(vm.form.operations.map(row => row.execution_context), ['production', 'shipment', 'shipment'])
  assert.deepEqual(vm.form.operations.map(row => row.sequence), [10, 20, 30])
})

test('catalog search requests server pages and retains a single selection across pages', async () => {
  const requests = []
  const vm = mount('ProductionProcessCatalogPanel', { listProcessCatalog: async (type, params) => {
    requests.push({ type, params }); return { data: { data: [{ id: params.page, name: '阶段', status: 'enabled' }], total: 22 } }
  } })
  await vm.open('stages', { mode: 'select' })
  vm.selectRow(vm.rows[0]); vm.keyword = '装配'; await vm.changePage(2)
  assert.equal(vm.selected.id, 1)
  assert.deepEqual(requests[1], { type: 'stages', params: { page: 2, per_page: 20, keyword: '装配', status: 'enabled' } })
  vm.close()
  assert.equal(vm.selected, null)
  assert.deepEqual(vm.rows, [])
  assert.equal(vm.page, 1)
})

test('catalog responses arriving after closing are ignored', async () => {
  let resolve
  const vm = mount('ProductionProcessCatalogPanel', { listProcessCatalog: () => new Promise(done => { resolve = done }) })
  const request = vm.open('packaging-schemes')
  vm.close()
  resolve({ data: { data: [{ id: 2, name: '纸箱' }], total: 1 } }); await request
  assert.deepEqual(vm.rows, [])
  assert.equal(vm.total, 0)
})

test('catalog editing submits its actual business version and persistent creation identifier', async () => {
  let sent
  const vm = mount('ProductionProcessCatalogPanel', {
    saveProcessCatalog: async (...args) => { sent = args; return { data: { data: { id: 3, business_version: 10 } } } },
    listProcessCatalog: async () => ({ data: { data: [], total: 0 } })
  })
  vm.$refs.catalogForm = { validate: async () => true, clearValidate() {} }
  await vm.open('stages', { mode: 'manage' })
  vm.edit({ id: 3, code: ' assembly ', name: ' 装配 ', business_version: 9 })
  const creationId = vm.creationId
  await vm.save()
  assert.equal(sent[0], 'stages')
  assert.equal(sent[1], 3)
  assert.equal(sent[2].expected_version, 9)
  assert.equal(sent[2].code, 'assembly')
  assert.equal(sent[2].name, '装配')
  assert.equal(sent[3], creationId)
})

test('public operation payload explicitly saves the automatic assignment switch on create and edit', () => {
  const vm = mount('ProductionOperationForm')
  vm.sessionId = 'reserve-session'; vm.reservationToken = 'reserve-token'
  assert.equal(vm.operationPayload().auto_assignment_enabled, false)
  vm.form.auto_assignment_enabled = true
  assert.equal(vm.operationPayload().auto_assignment_enabled, true)
  assert.equal(vm.operationPayload().creation_session_id, 'reserve-session')
  vm.$route.params.id = 8; vm.form.business_version = 6
  assert.equal(vm.operationPayload().expected_version, 6)
  assert.equal(Object.hasOwn(vm.operationPayload(), 'creation_session_id'), false)
})
