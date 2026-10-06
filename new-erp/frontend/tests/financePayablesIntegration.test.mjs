import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const viewPermissions = ['finance.payable.view', 'finance.supplier-ledger.view']

// Exercise the page's own methods; only HTTP, navigation and Element UI are replaced.
async function page(file = 'FinancePayableList', { permissions = viewPermissions, query = {}, dependencies = {}, props = {} } = {}) {
  const source = await readFile(new URL(`../src/views/erp/finance/${file}.vue`, import.meta.url), 'utf8')
  const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/^import .*$/gm, '')
    .replace('export default', 'return')
  const deps = {
    Vue: { component() {} },
    FinanceSupplierLedgerList: {},
    SupplierFinanceEntriesDialog: {},
    listFinancePayables: async () => ({ data: { data: [], total: 0 } }),
    listFinanceSupplierLedgers: async () => ({ data: { data: [], total: 0 } }),
    listSupplierFinanceStatistics: async () => ({ data: { data: [], total: 0, summary_by_currency: [] } }),
    listEntity: async () => ({ data: { data: [] } }),
    ...dependencies,
  }
  const options = new Function(...Object.keys(deps), script)(...Object.values(deps))
  const routes = []
  const events = []
  const messages = []
  const vm = {
    ...options.data(), embedded: false, active: true, ...props,
    $route: { path: '/finance/payables', query },
    $refs: {}, $nextTick: fn => fn(),
    $can: permission => permissions.includes(permission),
    $router: Object.fromEntries(['push', 'replace'].map(method => [method, async route => { routes.push({ method, ...route }) }])),
    $emit: (...event) => events.push(event),
    $message: Object.fromEntries(['success', 'warning', 'error'].map(level => [level, message => messages.push({ level, message })])),
    ...options.methods,
  }
  Object.entries(options.computed || {}).forEach(([key, get]) => Object.defineProperty(vm, key, { get: () => get.call(vm) }))
  return { vm, options, routes, events, messages, source }
}

test('only supplier permission opens the supplier view without requesting document or master data', async () => {
  const { vm, options } = await page('FinancePayableList', {
    permissions: ['finance.supplier-ledger.view'],
    dependencies: {
      listFinancePayables: () => assert.fail('unauthorized document request'),
      listEntity: () => assert.fail('unauthorized supplier master request'),
    },
  })
  options.created.call(vm)
  assert.equal(vm.activeView, 'suppliers')
  assert.equal(vm.supplierVisited, true)
  await vm.load()
  await vm.loadSuppliers()
})

test('document permission falls back to documents while a user with neither permission has no active view', async () => {
  const { vm } = await page('FinancePayableList', { permissions: ['finance.payable.view'], query: { view: 'suppliers' } })
  assert.equal(vm.activeView, 'documents')
  const none = await page('FinancePayableList', { permissions: [] })
  assert.equal(none.vm.activeView, '')
  await none.vm.changeView({ name: 'documents' })
  await none.vm.changeView({ name: 'suppliers' })
  assert.deepEqual(none.routes, [])
})

test('switching views retains document filters, source context and pagination without refetching', async () => {
  const { vm, routes } = await page('FinancePayableList', { query: { view: 'documents', source_id: '8' } })
  vm.documentsLoaded = true
  vm.suppliersLoaded = true
  vm.page = 4
  vm.perPage = 50
  vm.filters.purchase_order_no = 'PO-100'
  vm.dateRange = ['2026-10-01', '2026-10-04']
  vm.load = () => assert.fail('a tab switch must not reset/reload the document list')
  await vm.changeView({ name: 'suppliers' })
  const documentsRoute = vm.$route
  vm.$route = { path: '/finance/payables', query: routes[0].query }
  vm.syncRoute(vm.$route, documentsRoute)
  const suppliersRoute = vm.$route
  vm.$route = documentsRoute
  vm.syncRoute(vm.$route, suppliersRoute)
  assert.equal(vm.page, 4)
  assert.equal(vm.perPage, 50)
  assert.equal(vm.filters.purchase_order_no, 'PO-100')
  assert.deepEqual(vm.dateRange, ['2026-10-01', '2026-10-04'])
  assert.equal(routes[0].query.source_id, '8')
})

test('supplier drilldown clears stale document filters and source restriction but keeps page size', async () => {
  const { vm, routes } = await page('FinancePayableList', { query: { view: 'suppliers', source_id: '8', supplier_id: '2' } })
  vm.filters.purchase_order_no = 'OLD-PO'
  vm.filters.payment_status = 'paid'
  vm.dateRange = ['2025-01-01', '2025-01-02']
  vm.page = 5
  vm.perPage = 100
  await vm.viewSupplier({ supplier_id: 9, supplier_code: 'S009', supplier_name: '供应商九' })
  assert.deepEqual(routes[0], { method: 'push', path: '/finance/payables', query: { view: 'documents', supplier_id: 9 } })
  assert.equal(vm.filters.supplier_id, 9)
  assert.equal(vm.filters.purchase_order_no, '')
  assert.equal(vm.filters.payment_status, '')
  assert.deepEqual(vm.dateRange, [])
  assert.equal(vm.page, 1)
  assert.equal(vm.perPage, 100)
  assert.equal(vm.suppliers[0].supplier_name, '供应商九')
})

test('payment navigation carries only the authoritative source identity and requires permission and a balance', async () => {
  const { vm, routes } = await page('FinancePayableList', { permissions: [...viewPermissions, 'finance.payment.create', 'finance.allocation.create', 'finance.view'] })
  const row = { id: 17, supplier_id: 9, unpaid_amount: '18.5000', current_payable_amount: '99.0000' }
  await vm.pay(row)
  assert.deepEqual(routes[0], { method: 'push', path: '/finance/payments/create', query: { source_type: 'purchase_settlement_source', source_id: 17 } })
  await vm.pay({ ...row, unpaid_amount: '0.0000' })
  await vm.pay({ ...row, unpaid_amount: '-1.0000' })
  vm.$can = permission => permission === 'finance.payment.create'
  await vm.pay(row)
  vm.$can = permission => permission !== 'finance.view'
  await vm.pay(row)
  vm.$can = permission => permission !== 'finance.allocation.create'
  await vm.pay(row)
  vm.$can = () => false
  await vm.pay(row)
  assert.equal(routes.length, 1)
})

test('using prepayment requests pending supplier payments and requires both allocation and finance permissions', async () => {
  const { vm, routes } = await page('FinancePayableList', { permissions: [...viewPermissions, 'finance.allocation.create', 'finance.view'] })
  const row = { id: 17, supplier_id: 9, unpaid_amount: '18.5000' }
  await vm.usePrepayment(row)
  assert.deepEqual(routes[0], { method: 'push', path: '/finance/payments', query: { party_type: 'supplier', party_id: 9, allocation_status: 'pending', source_type: 'purchase_settlement_source', source_id: 17 } })
  await vm.usePrepayment({ ...row, unpaid_amount: 0 })
  vm.$can = permission => permission === 'finance.allocation.create'
  await vm.usePrepayment(row)
  vm.$can = permission => permission === 'finance.view'
  await vm.usePrepayment(row)
  assert.equal(routes.length, 1)
})

test('documents use the original paginated API with the current filters and source restriction', async () => {
  const requests = []
  const { vm } = await page('FinancePayableList', { query: { source_id: '7' }, dependencies: {
    listFinancePayables: async params => { requests.push(params); return { data: { data: [{ id: 7 }], total: 41, summary: { unpaid_amount: '10' } } } },
  } })
  vm.filters.supplier_id = 9
  vm.filters.payment_status = 'unpaid'
  vm.page = 2
  vm.perPage = 20
  vm.dateRange = ['2026-10-01', '2026-10-04']
  await vm.load()
  assert.equal(requests[0].supplier_id, 9)
  assert.equal(requests[0].source_id, '7')
  assert.equal(requests[0].payment_status, 'unpaid')
  assert.equal(requests[0].business_date_start, '2026-10-01')
  assert.equal(requests[0].page, 2)
  assert.equal(requests[0].per_page, 20)
  assert.equal(vm.total, 41)
  assert.equal(vm.summary.unpaid_amount, '10')
})

test('a late document response cannot overwrite a newer supplier/source search', async () => {
  const requests = []
  const { vm } = await page('FinancePayableList', { dependencies: { listFinancePayables: () => new Promise(resolve => requests.push(resolve)) } })
  const first = vm.load()
  vm.filters.supplier_id = 9
  const second = vm.load()
  requests[1]({ data: { data: [{ id: 9 }], total: 1 } })
  await second
  requests[0]({ data: { data: [{ id: 2 }], total: 77 } })
  await first
  assert.deepEqual(vm.rows, [{ id: 9 }])
  assert.equal(vm.total, 1)
  assert.equal(vm.loading, false)
})

test('reset removes supplier/source query restrictions and keeps the document view', async () => {
  const { vm, routes } = await page('FinancePayableList', { query: { view: 'documents', source_id: '7', supplier_id: '9' } })
  vm.filters.payment_status = 'paid'
  vm.page = 3
  vm.dateRange = ['2026-01-01', '2026-01-02']
  await vm.reset()
  assert.deepEqual(routes[0], { method: 'replace', path: '/finance/payables', query: { view: 'documents' } })
  assert.equal(vm.filters.payment_status, '')
  assert.equal(vm.page, 1)
  assert.deepEqual(vm.dateRange, [])
})

test('changing source context while documents are hidden invalidates an in-flight document response', async () => {
  let resolve
  const { vm } = await page('FinancePayableList', { query: { view: 'documents', source_id: '7' }, dependencies: {
    listFinancePayables: () => new Promise(done => { resolve = done }),
  } })
  const pending = vm.load()
  const previous = vm.$route
  vm.$route = { path: '/finance/payables', query: { view: 'suppliers' } }
  vm.syncRoute(vm.$route, previous)
  resolve({ data: { data: [{ id: 7 }], total: 1 } })
  await pending
  assert.equal(vm.documentsLoaded, false)
  assert.equal(vm.loading, false)
  assert.deepEqual(vm.rows, [])
})

test('supplier view loads lazily and retains its own filters/page after hiding and showing', async () => {
  const { vm, options } = await page('FinanceSupplierLedgerList', { props: { embedded: true, active: false } })
  let loads = 0
  vm.load = () => { loads += 1 }
  options.created.call(vm)
  assert.equal(loads, 0)
  options.watch.active.call(vm, true)
  assert.equal(loads, 1)
  vm.loaded = true
  vm.page = 3
  vm.perPage = 50
  vm.filters.supplier_keyword = '供应商甲'
  vm.dateRange = ['2026-09-01', '2026-09-30']
  options.watch.active.call(vm, false)
  options.watch.active.call(vm, true)
  assert.equal(loads, 1)
  assert.equal(vm.page, 3)
  assert.equal(vm.perPage, 50)
  assert.equal(vm.filters.supplier_keyword, '供应商甲')
  assert.deepEqual(vm.dateRange, ['2026-09-01', '2026-09-30'])
})

test('supplier source drilldown uses the host view and does not expose documents without document permission', async () => {
  const { vm, routes, events } = await page('FinanceSupplierLedgerList', { props: { embedded: true } })
  const supplier = { supplier_id: 9 }
  await vm.viewDocuments(supplier)
  assert.deepEqual(events, [['view-documents', supplier]])
  assert.deepEqual(routes, [])
  vm.$can = () => false
  await vm.viewDocuments(supplier)
  assert.equal(events.length, 1)
})

test('supplier API pagination and filters are separate from document route parameters', async () => {
  const requests = []
  const { vm } = await page('FinanceSupplierLedgerList', { query: { supplier_id: '99', source_id: '7' }, dependencies: {
    listSupplierFinanceStatistics: async params => { requests.push(params); return { data: { data: [{ supplier_id: 9 }], total: 21, summary_by_currency: [] } } },
  } })
  vm.filters.supplier_keyword = '供应商九'
  vm.filters.has_balance = 'yes'
  vm.page = 2
  vm.perPage = 10
  await vm.load()
  assert.equal(requests[0].page, 2)
  assert.equal(requests[0].per_page, 10)
  assert.equal(requests[0].supplier_keyword, '供应商九')
  assert.equal(requests[0].has_balance, 'yes')
  assert.equal(requests[0].source_id, undefined)
  assert.equal(requests[0].supplier_id, null)
  assert.equal(vm.total, 21)
})

test('integrated Vue templates compile with their permission guards and both view panes', async () => {
  for (const file of ['FinancePayableList', 'FinanceSupplierLedgerList']) {
    const { source } = await page(file)
    const template = compiler.parseComponent(source).template.content
    const result = compiler.compile(template)
    assert.deepEqual(result.errors, [], file)
  }
})
