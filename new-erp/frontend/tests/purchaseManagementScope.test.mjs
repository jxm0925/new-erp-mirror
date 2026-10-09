import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import * as scope from '../src/utils/purchaseManagementScope.mjs'
import * as material from '../src/utils/materialManagementScope.mjs'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const allocationSource = await readFile(new URL('../src/utils/purchasePlanAllocation.js', import.meta.url), 'utf8')
const allocation = await import(`data:text/javascript;base64,${Buffer.from(allocationSource).toString('base64')}`)
const factoryItem = { id: 1, management_scope: 'factory', is_stock_item: true }
const officeItem = { id: 2, management_scope: 'office', is_stock_item: false }
const response = rows => ({ data: { data: rows, total: rows.length } })
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done }); return { promise, resolve } }
const paths = [
  'views/erp/purchase/PurchaseBoard.vue', 'views/erp/purchase/PurchaseDocumentForm.vue',
  'views/erp/purchase/PurchaseReceiptForm.vue', 'views/erp/purchase/PurchaseSimpleDetail.vue',
  'views/erp/purchase/PurchasePlanDetail.vue', 'views/erp/purchase/PurchaseExchangeList.vue',
  'views/erp/purchase/PurchaseExchangeDetail.vue', 'views/erp/purchase/PurchaseDefectList.vue',
  'views/erp/returns/ReturnList.vue', 'views/erp/returns/ReturnForm.vue', 'views/erp/returns/ReturnDetail.vue',
  'components/purchase/PurchaseItemPicker.vue'
]
const sources = Object.fromEntries(await Promise.all(paths.map(async path => [path.split('/').at(-1).replace('.vue', ''), await readFile(new URL('../src/' + path, import.meta.url), 'utf8')])))

function mount(name, overrides = {}) {
  const bindings = { ...scope, ...material, ...allocation, PurchaseItemPicker: {}, PurchaseAttachmentPanel: {}, PurchaseConversionFacts: {}, ReceiptPhysicalEntries: {}, ...overrides }
  const script = compiler.parseComponent(sources[name]).script.content.replace(/import[\s\S]*?from ['"][^'"]+['"]\s*/g, '').replace('export default', 'return')
  const options = new Function(...Object.keys(bindings), script)(...Object.values(bindings))
  const messages = [], emitted = []
  const vm = { type: 'request', mode: 'requests', kind: 'purchase',
    $route: { params: {}, query: {}, path: '/purchase/requests/create' },
    $refs: { itemPicker: { visible: true, open(value) { this.options = value } } },
    $set: (row, key, value) => { row[key] = value }, $delete: (row, key) => { delete row[key] },
    $confirm: async () => {}, $emit: (...values) => emitted.push(values), $can: () => true,
    $message: Object.fromEntries(['success', 'warning', 'info', 'error'].map(level => [level, value => messages.push({ level, value })])) }
  Object.entries(options.methods || {}).forEach(([key, method]) => { vm[key] = method.bind(vm) })
  Object.assign(vm, options.data.call(vm))
  Object.entries(options.computed || {}).forEach(([key, value]) => {
    const descriptor = typeof value === 'function' ? { get: value.bind(vm) } : { get: value.get.bind(vm), set: value.set?.bind(vm) }
    Object.defineProperty(vm, key, descriptor)
  })
  return { vm, messages, emitted, options }
}

test('采购相关页面脚本及模板可解析', () => {
  for (const [name, source] of Object.entries(sources)) {
    const parsed = compiler.parseComponent(source)
    const compiled = compiler.compile(parsed.template.content)
    assert.deepEqual(compiled.errors, [], name)
    mount(name)
  }
})

test('历史空类型不能由当前物料推断为工厂，混合明细阻断', () => {
  assert.equal(scope.purchaseScopeLabel(null), '待拆分纠正')
  assert.match(scope.purchaseScopeIssue({ management_scope: null, items: [{ item_id: 1, item: factoryItem }] }), /历史/)
  assert.match(scope.purchaseScopeIssue({ management_scope: 'factory', items: [{ item_id: 2, item: officeItem }] }), /混有/)
  assert.equal(scope.purchaseScopeIssue({ management_scope: 'office', items: [{ item_id: 2, item: officeItem }] }), '')
})

test('到货冻结类型优先于后续主档变化，空快照不掩盖未知类型', () => {
  assert.equal(scope.purchaseScopeIssue({ management_scope: 'office', items: [{ item_id: 1, management_scope_snapshot: 'office', item: factoryItem }] }), '')
  assert.match(scope.purchaseScopeIssue({ management_scope: 'office', items: [{ item_id: 99 }] }), /不明确/)
})

test('单据来源锁定包含需求、计划、订单及换货来源', () => {
  for (const doc of [{ plan_id: 1 }, { order_id: 1 }, { items: [{ request_item_id: 1 }] }, { settlement_mode: 'replacement_no_charge' }]) assert.equal(scope.purchaseSourceLocked(doc), true)
  assert.equal(scope.purchaseSourceLocked({ items: [{ item_id: 1 }] }), false)
})

test('取消类型切换保留明细、供应商分配和仓库', async () => {
  const { vm } = mount('PurchaseDocumentForm')
  const line = { item_id: 1, warehouse_id: 7, splits: [{ supplier_id: 8 }] }
  vm.form.items = [line]
  vm.$confirm = async () => { throw 'cancel' }
  await vm.changeManagementScope('office')
  assert.equal(vm.form.management_scope, 'factory')
  assert.equal(vm.form.items[0], line)
  assert.equal(vm.scopeChanging, false)
})

test('确认类型切换明确清空全部明细和仓库但不改变物料主档', async () => {
  const { vm } = mount('PurchaseDocumentForm', { listEntity: async () => response([]) })
  vm.items = [factoryItem]
  vm.form.items = [{ item_id: 1, warehouse_id: 7, splits: [{ supplier_id: 8 }] }]
  vm.selectedRequestRows = [...vm.form.items]
  await vm.changeManagementScope('office')
  assert.equal(vm.form.management_scope, 'office')
  assert.equal(vm.form.items.length, 1)
  assert.equal(vm.form.items[0].item_id, null)
  assert.equal(vm.form.items[0].warehouse_id, undefined)
  assert.deepEqual(vm.selectedRequestRows, [])
  assert.equal(factoryItem.management_scope, 'factory')
  assert.equal(vm.$refs.itemPicker.visible, false)
})

test('来源单据类型不可切换', async () => {
  const { vm } = mount('PurchaseDocumentForm')
  vm.form.plan_id = 4
  await vm.changeManagementScope('office')
  assert.equal(vm.form.management_scope, 'factory')
})

test('采购选择器固定单据类型，跨类型带回完全拒绝', async () => {
  const { vm, messages } = mount('PurchaseDocumentForm')
  vm.form.management_scope = 'office'
  const line = { item_id: null }
  vm.form.items = [line]
  vm.openBatchItemPicker()
  assert.equal(vm.$refs.itemPicker.options.params.management_scope, 'office')
  assert.equal(vm.$refs.itemPicker.options.multiple, true)
  await vm.applyPickedMultipleItems([officeItem, factoryItem])
  assert.equal(line.item_id, null)
  assert.match(messages.at(-1).value, /一致/)
})

test('更换到货物料仍为单选，并按表头限制范围', () => {
  const { vm } = mount('PurchaseReceiptForm')
  vm.form.management_scope = 'office'
  vm.openItemPicker({ item_id: 2 })
  assert.equal(vm.$refs.itemPicker.options.multiple, undefined)
  assert.equal(vm.$refs.itemPicker.options.params.management_scope, 'office')
})

test('固定范围打开物料选择器移除跨类型已选，分页保留同类选择', async () => {
  const { vm } = mount('PurchaseItemPicker', { getItemCategoryTree: async () => response([]), listEntity: async () => response([officeItem, factoryItem]) })
  await vm.open({ multiple: true, selected: [factoryItem, officeItem], params: { management_scope: 'office' } })
  assert.deepEqual(vm.selectedRows.map(row => row.id), [2])
  assert.deepEqual(vm.rows.map(row => row.id), [2])
  vm.query.page = 2
  await vm.load()
  assert.deepEqual(vm.selectedRows.map(row => row.id), [2])
})

test('物料查询切换类型后迟到响应失效', async () => {
  const old = deferred()
  const { vm } = mount('PurchaseItemPicker', { listEntity: async (_entity, params) => params.management_scope === 'factory' ? old.promise : response([officeItem]) })
  vm.query.management_scope = 'factory'
  const pending = vm.load()
  vm.query.management_scope = 'office'
  await vm.load()
  old.resolve(response([factoryItem]))
  await pending
  assert.deepEqual(vm.rows, [officeItem])
})

test('仓库查询固定scope且拒绝迟到的上一类型仓库', async () => {
  const old = deferred(), calls = []
  const { vm } = mount('PurchaseReceiptForm', { listEntity: async (_entity, params) => { calls.push(params); return params.management_scope === 'factory' ? old.promise : response([{ id: 2, management_scope: 'office', status: 'enabled' }, { id: 1, management_scope: 'factory', status: 'enabled' }]) } })
  const pending = vm.loadScopedWarehouses()
  vm.form.management_scope = 'office'
  await vm.loadScopedWarehouses()
  old.resolve(response([{ id: 1, management_scope: 'factory', status: 'enabled' }]))
  await pending
  assert.deepEqual(vm.scopedWarehouses.map(row => row.id), [2])
  assert.equal(calls[1].management_scope, 'office')
  assert.equal(calls[1].page, 1)
})

test('到货类型切换清仓库库位、多库位、编号并关闭分配弹窗', async () => {
  const { vm } = mount('PurchaseReceiptForm', { listEntity: async () => response([]) })
  vm.form.items = [{ item_id: 1, warehouse_id: 1, _allocations: [{ warehouse_id: 1 }], _serialEntries: [{ serial_no: 'A' }] }]
  vm.allocationDialog.visible = true
  await vm.changeManagementScope('office')
  assert.equal(vm.form.items[0].warehouse_id, null)
  assert.deepEqual(vm.form.items[0]._allocations, [])
  assert.deepEqual(vm.form.items[0]._serialEntries, [])
  assert.equal(vm.allocationDialog.visible, false)
})

test('库存到货默认及多库位均拒绝跨管理类型仓库', () => {
  const { vm } = mount('PurchaseReceiptForm')
  vm.items = [factoryItem]
  vm.warehouses = [{ id: 1, management_scope: 'office', status: 'enabled' }]
  const line = { item_id: 1, qty: 1, qualified_qty: 1, actual_base_qty: 1, warehouse_id: 1, location_id: 1, _allocations: [] }
  assert.equal(vm.lineAllocationValid(line), false)
  line._allocations = [{ warehouse_id: 1, location_id: 1, base_qty: 1 }]
  assert.equal(vm.lineAllocationValid(line), false)
})

test('非库存办公到货不要求仓库且负载不伪造库存事实', () => {
  const { vm } = mount('PurchaseReceiptForm')
  vm.items = [officeItem]
  vm.form.management_scope = 'office'
  const line = { item_id: 2, qty: 1, qualified_qty: 1, warehouse_id: 1, location_id: 1, batch_no: 'OLD', _allocations: [{ warehouse_id: 1 }], _serialEntries: [] }
  vm.form.items = [line]
  assert.equal(vm.lineAllocationValid(line), true)
  const body = vm.payload()
  assert.equal(body.management_scope, 'office')
  assert.equal(body.items[0].warehouse_id, null)
  assert.deepEqual(body.items[0].allocations, [])
})

test('历史空scope及混合需求在保存接口前拦截', async () => {
  const { vm, messages } = mount('PurchaseDocumentForm', { savePurchaseRequest: () => { throw new Error('不应调用') } })
  vm.form = { management_scope: null, items: [{ item_id: 1, item: factoryItem }] }
  await vm.save(false)
  assert.match(messages.at(-1).value, /历史/)
  vm.form.management_scope = 'factory'
  vm.form.items.push({ item_id: 2, item: officeItem })
  await vm.save(true)
  assert.match(messages.at(-1).value, /混有/)
})

test('采购列表范围变化重置分页并传给服务器', async () => {
  const calls = []
  const { vm } = mount('PurchaseBoard', { listPurchase: async (_mode, params) => { calls.push(params); return response([]) } })
  vm.page = 3
  vm.filters.management_scope = 'office'
  vm.changeScopeFilter()
  await Promise.resolve()
  assert.equal(vm.page, 1)
  assert.equal(calls.at(-1).management_scope, 'office')
})

test('采购退货来源固定类型并忽略上一类型迟到响应', async () => {
  const old = deferred(), calls = []
  const { vm } = mount('ReturnForm', { listPurchaseReturnSources: async params => { calls.push(params); return params.management_scope === 'factory' ? old.promise : response([{ management_scope: 'office', source_receipt_item_id: 2 }]) } })
  const pending = vm.loadSources()
  vm.form.management_scope = 'office'
  await vm.loadSources()
  old.resolve(response([{ management_scope: 'factory', source_receipt_item_id: 1 }]))
  await pending
  assert.equal(vm.sources[0].source_receipt_item_id, 2)
  assert.equal(calls[1].management_scope, 'office')
  assert.equal(vm.purchaseSourceBlocked({ management_scope: 'factory', available_return_base_qty: 5 }), true)
})
