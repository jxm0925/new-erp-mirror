import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const source = await readFile(new URL('../src/views/erp/master/WarehouseLocationBoard.vue', import.meta.url), 'utf8')
const parsed = compiler.parseComponent(source)
const pagination = await readFile(new URL('../src/utils/pagedQuery.js', import.meta.url), 'utf8')
const { createPageState, queryPage } = new Function(pagination.replace(/export /g, '') + ';return {createPageState, queryPage}')()
const script = parsed.script.content.replace(/import[^\n]*\n/g, '').replace('export default', 'return')

function mount(listOverride) {
  const calls = []
  const bindings = {
    pagedScroll: {}, WarehouseManagerPicker: {}, createPageState, queryPage,
    listEntity: async (entity, params) => {
      calls.push({ entity, params: { ...params } })
      return listOverride ? listOverride(entity, params) : { data: { data: [], total: 0, last_page: 1 } }
    },
    listInventoryBalances: async () => ({ data: { stats: {} } }),
    getEntity: async () => ({ data: {} }), saveEntity: async () => ({ data: {} }),
    deleteEntity() {}, disableEntity() {}, enableEntity() {}
  }
  const component = new Function(...Object.keys(bindings), script)(...Object.values(bindings))
  const vm = { $nextTick: async () => {}, $message: { error() {}, success() {}, warning() {} }, $refs: {} }
  Object.assign(vm, component.data.call(vm))
  for (const [key, fn] of Object.entries(component.methods)) vm[key] = fn.bind(vm)
  for (const [key, fn] of Object.entries(component.computed)) Object.defineProperty(vm, key, { get: () => fn.call(vm) })
  return { vm, calls }
}

test('warehouse page template compiles with scope controls', () => {
  assert.deepEqual(compiler.compile(parsed.template.content).errors, [])
})

test('warehouse scope is sent before server pagination', async () => {
  const { vm, calls } = mount()
  vm.warehouseManagementScope = 'office'
  await vm.loadWarehouses()
  assert.equal(calls[0].entity, 'warehouses')
  assert.equal(calls[0].params.management_scope, 'office')
  assert.equal(calls[0].params.page, 1)
  assert.equal(calls[0].params.per_page, 50)
})

test('switching scope discards stale warehouse and inventory responses', async () => {
  let resolveFactory
  const office = { id: 2, management_scope: 'office', warehouse_name: '办公仓' }
  const { vm } = mount((entity, params) => {
    if (entity !== 'warehouses') return Promise.resolve({ data: { data: [], total: 0 } })
    if (params.management_scope === 'factory') return new Promise(resolve => { resolveFactory = resolve })
    return Promise.resolve({ data: { data: [office], total: 1, current_page: 1, last_page: 1 } })
  })
  vm.warehouseManagementScope = 'factory'
  const oldLoad = vm.loadWarehouses()
  vm.warehouseManagementScope = 'office'
  await vm.changeWarehouseScope()
  resolveFactory({ data: { data: [{ id: 1, management_scope: 'factory' }], total: 1 } })
  await oldLoad
  assert.deepEqual(vm.warehouses, [office])
  assert.equal(vm.selectedWarehouse.id, 2)
  assert.equal(vm.selectedWarehouse.management_scope, 'office')
})

test('unknown historical scope never displays as a factory warehouse', () => {
  const { vm } = mount()
  assert.equal(vm.scopeText(null), '待明确范围')
  assert.equal(vm.scopeText('office'), '办公用品仓')
})

test('scope lock depends on persisted identity rather than an unsaved choice', () => {
  const { vm } = mount()
  vm.editWarehouse({ id: 1, management_scope: null })
  vm.warehouseForm.management_scope = 'office'
  assert.equal(vm.warehouseScopeLocked, false)
  vm.editWarehouse({ id: 2, management_scope: 'factory' })
  assert.equal(vm.warehouseScopeLocked, true)
})

test('new warehouse follows the current explicit scope without inheriting an edited record', () => {
  const { vm } = mount()
  vm.editWarehouse({ id: 1, management_scope: 'factory' })
  vm.warehouseManagementScope = 'office'
  vm.openWarehouseCreate()
  assert.equal(vm.warehouseForm.id, null)
  assert.equal(vm.warehouseForm.management_scope, 'office')
  assert.equal(vm.warehouseScopeLocked, false)
})
