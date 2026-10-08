import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import * as scope from '../src/utils/materialManagementScope.mjs'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const source = await readFile(new URL('../src/views/erp/master/ItemList.vue', import.meta.url), 'utf8')
const script = compiler.parseComponent(source).script.content
  .replace(/import[\s\S]*?from ['"][^'"]+['"]\s*/g, '').replace('export default', 'return')
const factory = { id: 1, management_scope: 'factory', item_code: 'FACTORY-1', item_name: '工厂物料' }
const office = { id: 2, management_scope: 'office', item_code: 'OFFICE-2', item_name: '办公用品' }
const tick = () => new Promise(resolve => setImmediate(resolve))
function deferred() {
  let resolve, reject
  const promise = new Promise((done, fail) => { resolve = done; reject = fail })
  return { promise, resolve, reject }
}
function mount(overrides = {}) {
  const calls = { details: [], conversions: [] }
  const errors = []
  const bindings = {
    ...scope, cachedPageRoute: {},
    getEntity: async (entity, id, params) => {
      calls.details.push({ entity, id, params })
      if (overrides.getEntity) return overrides.getEntity(entity, id, params)
      return { data: { ...(id === 1 ? factory : office) } }
    },
    listItemPurchaseConversions: async (id, query) => {
      calls.conversions.push({ id, query: { ...query } })
      if (overrides.listItemPurchaseConversions) return overrides.listItemPurchaseConversions(id, query)
      return { data: { data: [{ id: id * 10, item_id: id }], total: 1 } }
    }
  }
  const component = new Function(...Object.keys(bindings), script)(...Object.values(bindings))
  const route = { path: '/master/items', query: {}, params: {}, meta: {} }
  const vm = { $route: route, pageRoute: route, $can: () => true, $message: { error: value => errors.push(value) } }
  Object.assign(vm, component.data.call(vm))
  for (const [key, fn] of Object.entries(component.methods)) vm[key] = fn.bind(vm)
  for (const [key, fn] of Object.entries(component.computed)) {
    Object.defineProperty(vm, key, { get: () => typeof fn === 'function' ? fn.call(vm) : fn.get.call(vm) })
  }
  vm.loadOptions = async () => {}
  vm.search = () => {}
  return { vm, calls, errors }
}

test('late factory detail cannot replace a newer office dialog or request factory conversions', async () => {
  const pending = deferred()
  const { vm, calls, errors } = mount({ getEntity: async (entity, id) => id === 1 ? pending.promise : { data: { ...office } } })
  const first = vm.openDetail(factory)
  vm.query.management_scope = 'office'
  vm.changeScope()
  await vm.openDetail(office)
  pending.resolve({ data: { ...factory, item_name: '旧工厂响应' } })
  await first
  assert.equal(vm.selected.id, 2)
  assert.equal(vm.selected.item_name, '办公用品')
  assert.equal(vm.selectedIsOffice, true)
  assert.equal(vm.selectedId, 2)
  assert.deepEqual(vm.conversions, [{ id: 20, item_id: 2 }])
  assert.deepEqual(calls.conversions.map(call => call.id), [2])
  assert.deepEqual(calls.details.map(call => call.params.management_scope), ['factory', 'office'])
  assert.deepEqual(errors, [])
})

test('late conversions for a previously opened factory item cannot overwrite office conversions', async () => {
  const pending = deferred()
  const { vm, calls } = mount({
    listItemPurchaseConversions: (id) => id === 1 ? pending.promise : Promise.resolve({ data: { data: [{ id: 20, item_id: 2 }], total: 1 } })
  })
  const first = vm.openDetail(factory)
  await tick()
  assert.deepEqual(calls.conversions.map(call => call.id), [1])
  await vm.openDetail(office)
  pending.resolve({ data: { data: [{ id: 10, item_id: 1 }], total: 99 } })
  await first
  assert.equal(vm.selected.id, 2)
  assert.deepEqual(vm.conversions, [{ id: 20, item_id: 2 }])
  assert.equal(vm.conversionTotal, 1)
})

test('scope change invalidates a pending detail even when no replacement dialog is opened', async () => {
  const pending = deferred()
  const { vm, calls } = mount({ getEntity: () => pending.promise })
  const first = vm.openDetail(factory)
  vm.query.management_scope = 'office'
  vm.changeScope()
  pending.resolve({ data: { ...factory } })
  await first
  assert.equal(vm.detailDialogVisible, false)
  assert.equal(vm.selectedId, null)
  assert.deepEqual(vm.selected, {})
  assert.equal(calls.conversions.length, 0)
})

test('an error from a closed or superseded detail does not report failure for the current dialog', async () => {
  const pending = deferred()
  const { vm, errors } = mount({ getEntity: () => pending.promise })
  const first = vm.openDetail(factory)
  vm.detailDialogVisible = false
  pending.reject({ userMessage: '旧物料详情请求失败' })
  await first
  assert.deepEqual(errors, [])
  assert.equal(vm.detailDialogVisible, false)
})

test('reopening the same item keeps the new response even when the old response has the same id', async () => {
  const pending = deferred()
  let reads = 0
  const { vm, calls } = mount({
    getEntity: () => ++reads === 1 ? pending.promise : Promise.resolve({ data: { ...factory, item_name: '最新资料' } })
  })
  const first = vm.openDetail(factory)
  await vm.openDetail(factory)
  pending.resolve({ data: { ...factory, item_name: '过期资料' } })
  await first
  assert.equal(vm.selected.item_name, '最新资料')
  assert.deepEqual(calls.conversions.map(call => call.id), [1])
})

test('pending office detail immediately has the office identity and clears the previous conversion rows', async () => {
  const pending = deferred()
  const { vm } = mount({ getEntity: () => pending.promise })
  vm.selected = { ...factory }
  vm.conversions = [{ id: 10, item_id: 1 }]
  vm.conversionTotal = 9
  const request = vm.openDetail(office)
  assert.equal(vm.selectedIsOffice, true)
  assert.equal(vm.selectedId, 2)
  assert.deepEqual(vm.conversions, [])
  assert.equal(vm.conversionTotal, 0)
  pending.resolve({ data: { ...office } })
  await request
  assert.equal(vm.selected.id, 2)
})
