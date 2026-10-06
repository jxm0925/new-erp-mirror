import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const source = await readFile(new URL('../src/views/erp/finance/FinanceCashList.vue', import.meta.url), 'utf8')
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
  .replace(/^import .*$/gm, '').replace('export default', 'return')

function page(dependencies = {}, query = {}) {
  const calls = [], routes = [], messages = []
  const deps = {
    FinanceAllocation: {},
    listCashDocuments: async (direction, filters) => { calls.push({ direction, filters }); return { data: { data: [], total: 0 } } },
    listFinanceAccounts: async () => ({ data: { data: [] } }),
    resolveFinanceSource: async () => ({ data: { data: { type: 'purchase_settlement_source', id: 7, partyType: 'supplier', partyId: 3, partyName: '供应商甲', currency: 'CNY', amount: '800', remainingAmount: '500', no: 'PRC-7' } } }),
    confirmCashDocument: async () => {}, voidCashDocument: async () => {}, ...dependencies,
  }
  const options = new Function(...Object.keys(deps), script)(...Object.values(deps))
  const vm = {
    ...options.data(), direction: 'payment', $route: { query }, $refs: {}, $nextTick: callback => callback(),
    $can: () => true, $router: { push: route => routes.push(route) },
    $message: Object.fromEntries(['success', 'warning', 'error'].map(level => [level, message => messages.push({ level, message })])),
  }
  Object.entries(options.methods).forEach(([name, method]) => { vm[name] = method.bind(vm) })
  Object.entries(options.computed).forEach(([name, get]) => Object.defineProperty(vm, name, { get: () => get.call(vm) }))
  return { vm, calls, routes, messages, options }
}

test('收付款模板可编译，核销组件在当前页弹窗内使用', () => {
  const result = compiler.compile(compiler.parseComponent(source).template.content)
  assert.deepEqual(result.errors, [])
})

test('待核销查询在分页前交给服务端，并保留交易对手范围', async () => {
  const { vm, calls } = page({}, { allocation_status: 'pending', party_type: 'supplier', party_id: '3' })
  await vm.initializeFromRoute()
  vm.page = 2
  await vm.load()
  assert.equal(calls[1].direction, 'payment')
  assert.equal(calls[1].filters.allocation_status, 'pending')
  assert.equal(calls[1].filters.party_type, 'supplier')
  assert.equal(calls[1].filters.party_id, 3)
  assert.equal(calls[1].filters.page, 2)
  assert.equal(calls[1].filters.per_page, 10)
})

test('来源入口只信任服务端身份和余额，URL中的另一供应商不能改变范围', async () => {
  const { vm, calls } = page({}, { source_type: 'purchase_settlement_source', source_id: '7', party_type: 'supplier', party_id: '999' })
  await vm.initializeFromRoute()
  assert.deepEqual(vm.partyFilter, { party_type: 'supplier', party_id: 3, currency: 'CNY' })
  assert.equal(vm.sourceContext.remainingAmount, '500')
  assert.equal(calls[0].filters.party_id, 3)
  assert.equal(calls[0].filters.currency, 'CNY')
  await vm.reset()
  assert.equal(calls[1].filters.allocation_status, 'pending')
  assert.equal(calls[1].filters.party_id, 3)
})

test('失效来源不退化成全供应商付款列表', async () => {
  const { vm, calls } = page({ resolveFinanceSource: async () => { throw { userMessage: '来源已结清' } } }, { source_type: 'purchase_settlement_source', source_id: '7' })
  await vm.initializeFromRoute()
  assert.equal(vm.sourceError, '来源已结清')
  assert.equal(calls.length, 0)
  assert.deepEqual(vm.rows, [])
  assert.equal(vm.canUseSource({ status: 'confirmed', unallocated_amount: '100' }), false)
})

test('换来源后迟到的解析不能覆盖新来源与筛选', async () => {
  const pending = []
  const { vm, calls } = page({ resolveFinanceSource: () => new Promise(resolve => pending.push(resolve)) }, { source_type: 'purchase_settlement_source', source_id: '7' })
  const old = vm.initializeFromRoute()
  vm.$route.query = { source_type: 'purchase_settlement_source', source_id: '8' }
  const current = vm.initializeFromRoute()
  const fact = id => ({ data: { data: { type: 'purchase_settlement_source', id, partyType: 'supplier', partyId: id, currency: 'CNY', remainingAmount: '100' } } })
  pending[1](fact(8)); await current
  pending[0](fact(7)); await old
  assert.equal(vm.sourceContext.id, 8)
  assert.equal(vm.partyFilter.party_id, 8)
  assert.equal(calls.length, 1)
})

test('更改列表筛选后旧分页响应不能覆盖新列表', async () => {
  const pending = []
  const { vm } = page({ listCashDocuments: () => new Promise(resolve => pending.push(resolve)) })
  const old = vm.load()
  vm.query.allocation_status = 'pending'
  const current = vm.load()
  pending[1]({ data: { data: [{ id: 2 }], total: 1 } }); await current
  pending[0]({ data: { data: [{ id: 1 }], total: 99 } }); await old
  assert.deepEqual(vm.rows, [{ id: 2 }])
  assert.equal(vm.total, 1)
  assert.equal(vm.loading, false)
})

test('预付款入口要求同交易对手同币种、有效余额及核销权限', async () => {
  const { vm } = page({}, { source_type: 'purchase_settlement_source', source_id: '7' })
  await vm.initializeFromRoute()
  const row = { id: 4, status: 'confirmed', unallocated_amount: '100', party_type: 'supplier', party_id: 3, currency: 'CNY' }
  assert.equal(vm.canUseSource(row), true)
  for (const change of [{ party_id: 4 }, { party_type: 'customer' }, { currency: 'USD' }, { unallocated_amount: '0' }, { status: 'voided' }]) {
    assert.equal(vm.canUseSource({ ...row, ...change }), false)
  }
  vm.$can = () => false
  assert.equal(vm.canUseSource(row), false)
})

test('带待核销意向的草稿先进入详情核对，不从列表绕过清单确认', async () => {
  let confirmations = 0
  const { vm, routes } = page({ confirmCashDocument: async () => { confirmations++ } })
  await vm.confirm({ id: 6, draft_allocation_items: [{ source_document_id: 7 }] })
  assert.equal(confirmations, 0)
  assert.equal(routes[0], '/finance/payments/6')
})

test('普通列表就地打开核销，使用预付款成功返回原应付', async () => {
  const normal = page()
  normal.vm.openAllocation({ id: 8, status: 'confirmed', unallocated_amount: '0' })
  assert.equal(normal.vm.allocationVisible, true)
  assert.equal(normal.vm.allocationId, 8)
  assert.equal(normal.routes.length, 0)
  const contextual = page({}, { source_type: 'purchase_settlement_source', source_id: '7' })
  await contextual.vm.initializeFromRoute()
  contextual.vm.allocationChanged()
  assert.deepEqual(contextual.routes[0], { path: '/finance/payables', query: { view: 'documents', source_id: 7 } })
})
