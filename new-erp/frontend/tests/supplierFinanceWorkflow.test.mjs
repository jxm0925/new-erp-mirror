import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')

async function component(file, dependencies = {}, props = {}) {
  const source = await readFile(new URL(`../src/views/erp/finance/${file}.vue`, import.meta.url), 'utf8')
  const script = compiler.parseComponent(source).script.content.replace(/^import .*$/gm, '').replace('export default', 'return')
  const deps = {
    SupplierFinanceEntriesDialog: {},
    listSupplierFinanceStatistics: async () => ({ data: { data: [], total: 0, summary_by_currency: [] } }),
    listSupplierFinanceEntries: async () => ({ data: { data: [], total: 0, cash_details_visible: false } }),
    ...dependencies,
  }
  const options = new Function(...Object.keys(deps), script)(...Object.values(deps))
  const routes = []; const events = []; const messages = []
  const vm = {
    ...(options.data?.() || {}), embedded: false, active: true, visible: true,
    supplier: { supplier_id: 9, supplier_name: '供方', currency: 'CNY' }, initialPeriod: [],
    $can: permission => ['finance.supplier-ledger.view', 'finance.view', 'finance.payable.view'].includes(permission),
    $route: { query: {} }, $refs: {}, $nextTick: fn => fn(),
    $router: { push: route => { routes.push(route) } }, $emit: (...args) => events.push(args),
    $message: { error: message => messages.push(message) }, ...props, ...options.methods,
  }
  return { vm, options, source, routes, events, messages }
}

test('supplier details open a centered dialog under supplier permission without requesting payable data', async () => {
  const { vm, routes, events } = await component('FinanceSupplierLedgerList', {}, { embedded: true, $can: permission => permission === 'finance.supplier-ledger.view' })
  const row = { supplier_id: 7, supplier_name: '纯预付款供方', currency: 'USD' }
  vm.viewDetail(row)
  assert.equal(vm.detailVisible, true)
  assert.deepEqual(vm.detailSupplier, row)
  assert.deepEqual(routes, [])
  assert.deepEqual(events, [])
  vm.viewDocuments(row)
  assert.deepEqual(events, [])
})

test('embedded supplier tabs retain their own context without inheriting document route filters', async () => {
  const embedded = await component('FinanceSupplierLedgerList', {}, { embedded: true, active: false, $route: { query: { supplier_id: '88', source_id: '12' } } })
  embedded.options.created.call(embedded.vm)
  assert.equal(embedded.vm.filters.supplier_id, null)
})

test('supplier balances keep the server currency rows separate when one supplier uses several currencies', async () => {
  const rows = [{ supplier_id: 1, currency: 'CNY', prepayment_balance_amount: '100.0000' }, { supplier_id: 1, currency: 'USD', prepayment_balance_amount: '10.0000' }]
  const { vm } = await component('FinanceSupplierLedgerList', { listSupplierFinanceStatistics: async () => ({ data: { data: rows, total: 31 } }) })
  await vm.load()
  assert.deepEqual(vm.rows, rows)
  assert.equal(vm.total, 31)
  assert.equal(vm.rowKey({ supplier_id: 1, currency: 'CNY' }), '1:CNY')
  assert.notEqual(vm.rowKey({ supplier_id: 1, currency: 'USD' }), '1:CNY')
})

test('period filters and pure prepayment flag are sent with server pagination', async () => {
  const calls = []
  const { vm } = await component('FinanceSupplierLedgerList', { listSupplierFinanceStatistics: async params => { calls.push(params); return { data: {} } } })
  vm.dateRange = ['2026-09-01', '2026-09-30']; vm.filters.only_prepayment = true; vm.filters.currency = 'USD'
  await vm.changeSize(50)
  await vm.changePage(3)
  assert.equal(calls[1].page, 3)
  assert.equal(calls[1].per_page, 50)
  assert.equal(calls[1].period_start, '2026-09-01')
  assert.equal(calls[1].only_prepayment, 1)
  assert.equal(calls[1].currency, 'USD')
  await vm.reset()
  assert.equal(calls[2].page, 1)
  assert.equal(calls[2].currency, '')
  assert.equal(calls[2].period_start, '')
  assert.equal(calls[2].only_prepayment, 0)
})

test('supplier query ignores stale responses and clears old totals when the current request fails', async () => {
  const pending = []
  const { vm, messages } = await component('FinanceSupplierLedgerList', { listSupplierFinanceStatistics: () => new Promise((resolve, reject) => pending.push({ resolve, reject })) })
  const first = vm.load(); const second = vm.load()
  pending[1].resolve({ data: { data: [{ supplier_id: 2 }], total: 1, summary_by_currency: [{ currency: 'USD' }] } }); await second
  pending[0].resolve({ data: { data: [{ supplier_id: 1 }], total: 90 } }); await first
  assert.equal(vm.rows[0].supplier_id, 2)
  const third = vm.load(); pending[2].reject({ userMessage: '查询失败' }); await third
  assert.deepEqual(vm.rows, []); assert.equal(vm.total, 0)
  assert.deepEqual(messages, ['查询失败'])
})

test('dialog reopening resets filters and pagination while preserving the requested initial period and currency', async () => {
  const calls = []
  const { vm } = await component('SupplierFinanceEntriesDialog', { listSupplierFinanceEntries: async (id, params) => { calls.push({ id, ...params }); return { data: { data: [], total: 0, cash_details_visible: true } } } }, { initialPeriod: ['2026-10-01', '2026-10-05'] })
  vm.page = 4; vm.filters = { keyword: 'OLD', event_family: 'cash' }
  await vm.open()
  assert.equal(calls[0].id, 9); assert.equal(calls[0].page, 1); assert.equal(calls[0].keyword, '')
  assert.equal(calls[0].currency, 'CNY'); assert.equal(calls[0].period_start, '2026-10-01')
  assert.equal(vm.cashDetailsVisible, true)
})

test('closing the dialog prevents delayed response from replacing the next supplier details', async () => {
  const pending = []
  const { vm, events } = await component('SupplierFinanceEntriesDialog', { listSupplierFinanceEntries: () => new Promise(resolve => pending.push(resolve)) })
  const first = vm.load(); vm.close(); vm.supplier = { supplier_id: 10, currency: 'USD' }; const second = vm.open()
  pending[1]({ data: { data: [{ event_key: 'new' }], total: 1, cash_details_visible: true } }); await second
  pending[0]({ data: { data: [{ event_key: 'old' }], total: 99, cash_details_visible: false } }); await first
  assert.deepEqual(vm.rows, [{ event_key: 'new' }]); assert.equal(vm.total, 1)
  assert.deepEqual(events, [['update:visible', false]])
})

test('cash and source navigation each enforce the destination permission', async () => {
  const { vm, routes } = await component('SupplierFinanceEntriesDialog', {}, { $can: permission => permission === 'finance.supplier-ledger.view' })
  const cash = { document_type: 'cash_document', document_id: 7, cash_direction: 'payment' }
  assert.equal(vm.documentRoute(cash), null)
  vm.openDocument(cash); assert.deepEqual(routes, [])
  vm.$can = permission => ['finance.supplier-ledger.view', 'finance.view'].includes(permission)
  vm.openDocument(cash); assert.deepEqual(routes, ['/finance/payments/7'])
  assert.equal(vm.relatedRoute({ related_document_type: 'purchase_settlement_source', related_document_id: 3 }), null)
  vm.$can = () => true
  assert.deepEqual(vm.relatedRoute({ related_document_type: 'purchase_settlement_source', related_document_id: 3 }), { path: '/finance/payables', query: { view: 'documents', source_id: 3, supplier_id: 9 } })
})

test('the supplier ledger and centered detail dialog templates compile', async () => {
  for (const file of ['FinanceSupplierLedgerList', 'SupplierFinanceEntriesDialog']) {
    const { source, options } = await component(file)
    assert.deepEqual(compiler.compile(compiler.parseComponent(source).template.content).errors, [], file)
    if (options.components?.MoneyColumn) assert.deepEqual(compiler.compile(options.components.MoneyColumn.template).errors, [])
  }
})
