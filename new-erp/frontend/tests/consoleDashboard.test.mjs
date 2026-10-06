import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const source = await readFile(new URL('../src/views/erp/console/ConsoleDashboard.vue', import.meta.url), 'utf8')
const inventory = await readFile(new URL('../src/views/erp/inventory/InventoryBoard.vue', import.meta.url), 'utf8')
const helperSource = await readFile(new URL('../src/utils/consoleDashboard.js', import.meta.url), 'utf8')
const helpers = await import(`data:text/javascript;base64,${Buffer.from(helperSource).toString('base64')}`)
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '').replace('export default', 'return')
const fixture = (extra = {}) => ({ timezone: 'Asia/Shanghai', counts: { requests: 0, plans: 0, orders: 0, receipts: 1, adjustments: 0, boms: 0 }, total_todo: 1,
  todos: { total: 1, data: [{ no: 'PRC2026100500001', summary: '1种物料等待库存过账', time: '2026-10-05T14:42:36+08:00' }] },
  warnings: [], recent: [], master: {}, purchase: {}, inventory: {}, bom: {}, trends: { purchase: [], inventory: [] }, ...extra })
function make(get = async () => ({ data: { data: fixture() } })) {
  const calls = []
  const deps = { ...helpers, getConsoleDashboard: params => { calls.push(params); return get(params) } }
  const options = new Function(...Object.keys(deps), script)(...Object.values(deps))
  const vm = { ...options.data(), $nextTick: fn => fn(), $el: { querySelector: () => null } }
  for (const [name, fn] of Object.entries(options.methods)) vm[name] = fn.bind(vm)
  for (const [name, fn] of Object.entries(options.computed)) Object.defineProperty(vm, name, { get: () => fn.call(vm) })
  return { vm, options, calls }
}
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }

test('dashboard cards and rows use the same server result without reconstructing draft approval states', async () => {
  const { vm, calls } = make()
  assert.equal(vm.dashboard, null)
  await vm.load()
  assert.equal(vm.totalTodo, 1)
  assert.equal(vm.taskCards.reduce((n, c) => n + c.count, 0), 1)
  assert.equal(vm.visibleTodos.length, 1)
  assert.equal(vm.visibleTodos[0].summary, '1种物料等待库存过账')
  assert.deepEqual(calls[0], { page: 1, per_page: 8, type: undefined, priority: undefined })
})

test('card selection, all todos and pagination request matching server filters', async () => {
  const { vm, calls } = make()
  await vm.load()
  vm.selectTodoType('receipts')
  await Promise.resolve()
  assert.equal(calls.at(-1).type, 'receipts')
  vm.changeTodoPage(2)
  await Promise.resolve()
  assert.equal(calls.at(-1).page, 2)
  vm.showAllTodos()
  await Promise.resolve()
  assert.equal(calls.at(-1).type, undefined)
  assert.equal(calls.at(-1).page, 1)
  vm.todoTab = 'soon'
  await vm.load()
  assert.equal(calls.at(-1).priority, 'high')
})

test('loading failure hides outdated metrics and recovery uses a fresh response', async () => {
  let failing = false
  const { vm } = make(async () => { if (failing) throw new Error('offline'); return { data: { data: fixture() } } })
  await vm.load()
  failing = true
  await vm.load()
  assert.equal(vm.dashboard, null)
  assert.ok(vm.loadError.includes('加载失败'))
  assert.equal(vm.loading, false)
  failing = false
  await vm.load()
  assert.equal(vm.loadError, '')
  assert.equal(vm.dashboard.total_todo, 1)
})

test('older responses and errors cannot overwrite a newer page or change its loading state', async () => {
  const a = deferred(), b = deferred()
  let n = 0
  const { vm } = make(() => (++n === 1 ? a.promise : b.promise))
  const first = vm.load(), second = vm.load()
  b.resolve({ data: { data: fixture({ total_todo: 12 }) } })
  await second
  a.reject(new Error('older failure'))
  await first
  assert.equal(vm.totalTodo, 12)
  assert.equal(vm.loadError, '')
  assert.equal(vm.loading, false)
})

test('times use the supplied business timezone and waits never invent one hour', () => {
  assert.equal(helpers.consoleTime('2026-10-04T16:10:00Z'), '2026-10-05 00:10')
  assert.equal(helpers.consoleTime('2026-10-05T14:42:36+08:00'), '2026-10-05 14:42')
  assert.equal(helpers.consoleWait('2026-10-05T14:42:36+08:00', Date.parse('2026-10-05T14:43:36+08:00')), '不足1小时')
  assert.equal(helpers.consoleTime('invalid'), '--')
})

test('zero trends stay zero and currency and unit groups remain separate', () => {
  assert.deepEqual(helpers.consoleTrend([{ date: '2026-10-05', count: 0 }]), [{ date: '2026-10-05', count: 0, height: 0 }])
  assert.equal(helpers.consoleAmounts([{ currency: 'CNY', amount: '100' }, { currency: 'USD', amount: '50' }]), 'CNY 100.00；USD 50.00')
  assert.equal(helpers.consoleQuantities([{ unit_name: '件', quantity: 2 }, { unit_name: 'kg', quantity: 3.5 }]), '2 件；3.5 kg')
  assert.equal(helpers.consoleAmounts(null), null)
  assert.equal(helpers.consoleQuantities(null), null)
})

test('missing permissions stay unavailable and do not trigger a todo query', async () => {
  const { vm, calls } = make(async () => ({ data: { data: fixture({ counts: { orders: null, receipts: 1 } }) } }))
  await vm.load()
  assert.equal(vm.taskCards.find(c => c.key === 'orders').count, null)
  const count = calls.length
  vm.selectTodoType('orders')
  assert.equal(calls.length, count)
})

test('console and linked inventory templates compile and linked targets are applied before loading', () => {
  for (const s of [source, inventory]) assert.deepEqual(compiler.compile(compiler.parseComponent(s).template.content).errors, [])
  const inventoryScript = inventory.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '').replace('export default', 'return')
  const options = new Function('cachedPageRoute', inventoryScript)({})
  const vm = { activeView: 'posting', pageRoute: { query: { keyword: 'PRC2026100500001', receipt_id: 4033 } }, postingQuery: { keyword: '' }, postingPagination: { page: 3 }, adjustmentQuery: { no: '' }, adjustmentPagination: { page: 2 } }
  options.methods.applyConsoleTarget.call(vm)
  assert.equal(vm.postingQuery.keyword, 'PRC2026100500001')
  assert.equal(vm.postingPagination.page, 1)
  vm.activeView = 'adjustments'; vm.pageRoute.query = { adjustment_no: 'ADJ123' }
  options.methods.applyConsoleTarget.call(vm)
  assert.equal(vm.adjustmentQuery.no, 'ADJ123')
  assert.equal(vm.adjustmentPagination.page, 1)
})
