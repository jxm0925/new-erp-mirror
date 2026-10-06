import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const source = await readFile(new URL('../src/views/erp/finance/FinanceDashboard.vue', import.meta.url), 'utf8')
const helperSource = await readFile(new URL('../src/utils/financeDashboard.js', import.meta.url), 'utf8')
const helpers = await import(`data:text/javascript;base64,${Buffer.from(helperSource).toString('base64')}`)
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '').replace('export default', 'return')
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
const data = (overrides = {}) => ({
  currency: 'CNY', timezone: 'Asia/Shanghai', period: { start: '2026-10-01', end: '2026-10-04', days: 4 }, as_of: '2026-10-05T05:00:00Z',
  cash: { summary: { receipt_amount: '130', payment_amount: '90', net_amount: '40' }, changes: { receipt_amount: { percent: '30.00' }, payment_amount: { percent: null }, net_amount: { percent: '-50' } },
    trend: [{ date: '2026-10-01', receipt_amount: '0', payment_amount: '90', net_amount: '-90' }],
    composition: [{ label: '客户收款', direction: 'receipt', amount: '130', count: 2 }, { label: '供应商付款', direction: 'payment', amount: '90', count: 1 }, { label: '客户退款', direction: 'payment', amount: '0', count: 0 }],
  },
  accounts: { balance_amount: '700', items: [{ account_no: 'A1', account_name: '资金账户', balance_amount: '800' }, { account_no: 'A2', account_name: '负余额账户', balance_amount: '-100' }] },
  payables: { status: 'available', summary: { paid_amount: '60', unpaid_amount: '40', current_payable_amount: '100', quality_frozen_amount: '10' } },
  suppliers: { status: 'available', summary: { prepayment_balance_amount: '20', pending_refund_amount: '5' }, payable_ranking: [{ supplier_id: 1, supplier_name: '甲供应商', unpaid_amount: '40' }], other_unpaid_amount: '12' },
  ...overrides,
})
function make(overrides = {}) {
  const calls = [], messages = []
  const dependencies = { ...helpers, FinanceChart: {}, getFinanceDashboard: async params => { calls.push(params); return { data: { data: data() } } }, listFinanceCurrencies: async () => ({ data: { data: [] } }), ...overrides }
  const options = new Function(...Object.keys(dependencies), script)(...Object.values(dependencies))
  const vm = { ...options.data(), $message: { warning: message => messages.push(message), error: message => messages.push(message) } }
  Object.entries(options.methods).forEach(([name, fn]) => { vm[name] = fn.bind(vm) })
  Object.entries(options.computed).forEach(([name, fn]) => Object.defineProperty(vm, name, { get: () => fn.call(vm) }))
  return { vm, options, calls, messages }
}

test('财务统计模板编译且不含付款安排、登记或写操作', () => {
  assert.deepEqual(compiler.compile(compiler.parseComponent(source).template.content).errors, [])
  assert.doesNotMatch(source, /付款安排|登记付款|startPayment|openPlan|\.post\(|\.put\(|\$router/)
})

test('日期快捷筛选按自然日计算，业务截至时间使用后端时区', () => {
  assert.deepEqual(helpers.dateRangeFor('7', new Date(2026, 0, 3)), ['2025-12-28', '2026-01-03'])
  assert.deepEqual(helpers.dateRangeFor('month', new Date(2026, 9, 5)), ['2026-10-01', '2026-10-05'])
  assert.deepEqual(helpers.dateRangeFor('year', new Date(2026, 9, 5)), ['2026-01-01', '2026-10-05'])
  assert.equal(helpers.businessDateTime('2026-10-04T17:00:00Z', 'Asia/Shanghai'), '2026-10-05 01:00')
  assert.equal(helpers.businessDateTime(undefined), '—')
})

test('首次查询使用服务器默认期间，其后按选定日期币种取完整聚合', async () => {
  const { vm, calls } = make()
  await vm.load(true)
  assert.equal(calls[0].period_start, undefined)
  assert.equal(vm.filters.start, '2026-10-01')
  assert.equal(vm.filters.end, '2026-10-04')
  assert.equal(vm.businessTimezone, 'Asia/Shanghai')
  vm.filters = { start: '2026-09-01', end: '2026-09-30', currency: 'USD' }
  await vm.load()
  assert.deepEqual(calls[1], { currency: 'USD', period_start: '2026-09-01', period_end: '2026-09-30' })
})

test('迟到统计响应不能替换新统计或覆盖查询中编辑的币种', async () => {
  const requests = []
  const { vm } = make({ getFinanceDashboard: () => { const request = deferred(); requests.push(request); return request.promise } })
  const old = vm.load()
  vm.filters.currency = 'USD'
  const latest = vm.load()
  requests[1].resolve({ data: { data: data({ currency: 'USD' }) } }); await latest
  requests[0].resolve({ data: { data: data({ currency: 'CNY' }) } }); await old
  assert.equal(vm.currency, 'USD'); assert.equal(vm.filters.currency, 'USD')
  const editing = vm.load()
  vm.filters.currency = 'EUR'
  requests[2].resolve({ data: { data: data({ currency: 'USD' }) } }); await editing
  assert.equal(vm.filters.currency, 'EUR'); assert.equal(vm.currency, 'USD')
})

test('错误清掉旧数据，销毁后响应不再填入，错误日期不发请求', async () => {
  const request = deferred()
  const { vm, options, messages } = make({ getFinanceDashboard: () => request.promise })
  vm.dashboard = data(); vm.detailVisible = true
  const pending = vm.load()
  assert.equal(vm.dashboard, null); assert.equal(vm.detailVisible, false)
  request.reject({ userMessage: '统计失败' }); await pending
  assert.equal(vm.error, '统计失败'); assert.equal(vm.dashboard, null); assert.equal(vm.loading, false)
  const disposed = deferred()
  const next = make({ getFinanceDashboard: () => disposed.promise })
  const afterDestroy = next.vm.load(); options.beforeDestroy.call(next.vm)
  disposed.resolve({ data: { data: data() } }); await afterDestroy
  assert.equal(next.vm.dashboard, null)
  vm.filters.start = '2026-11-01'; vm.filters.end = '2026-10-01'; await vm.load()
  assert.equal(messages.at(-1), '请选择正确的起止日期')
})

test('期间金额与当前余额分开，权限拒绝数据隐藏而非转为零', () => {
  const { vm } = make(); vm.dashboard = data()
  assert.deepEqual(vm.cashMetrics.map(item => item.value), ['130', '90', '40', '700'])
  assert.equal(vm.asOfLabel, '2026-10-05 13:00')
  assert.equal(vm.changeText('receipt_amount'), '较上期 +30%')
  assert.equal(vm.changeText('payment_amount'), '较上期：—')
  assert.equal(vm.currentMetrics.length, 3)
  vm.dashboard.payables = { status: 'forbidden', summary: null }
  vm.dashboard.suppliers = { status: 'forbidden', summary: null }
  assert.equal(vm.currentMetrics.length, 0)
  vm.showDetails('suppliers'); assert.equal(vm.detailVisible, false)
})

test('构成按收付方向切换且采用后端合计，负账户余额保留', () => {
  const { vm } = make(); vm.dashboard = data()
  assert.deepEqual(vm.compositionRows.map(row => row.label), ['供应商付款'])
  assert.equal(vm.compositionTotal, '90')
  vm.compositionDirection = 'receipt'; assert.equal(vm.compositionTotal, '130')
  assert.deepEqual(vm.compositionRows.map(row => row.label), ['客户收款'])
  assert.equal(vm.hasAccountBalances, true)
  assert.deepEqual(vm.accountsOption.series[0].data.map(row => row.value), [800, -100])
})

test('只读明细在页内分页并保留完整账户与供应商其余合计', () => {
  const { vm } = make(); vm.dashboard = data()
  vm.dashboard.accounts.items = Array.from({ length: 25 }, (_, i) => ({ account_no: String(i), account_name: `账户${i}`, balance_amount: i * 100 }))
  assert.equal(vm.accountChartRows.length, 10)
  vm.showDetails('accounts'); assert.equal(vm.detailVisible, true); assert.equal(vm.detail.rows.length, 25); assert.equal(vm.visibleDetailRows.length, 15)
  vm.detailPage = 2; assert.equal(vm.visibleDetailRows.length, 10)
  vm.showDetails('suppliers'); assert.equal(vm.detailPage, 1); assert.equal(vm.detail.rows.at(-1).unpaid_amount, '12')
  vm.showDetails('payables'); assert.equal(vm.detail.rows[0].label, '已核销应付'); assert.equal(vm.detail.rows.at(-1).label, '质量冻结')
})

test('曲线不裁掉负净额，零构成无伪造扇形，tooltip使用纯文本渲染', () => {
  const option = helpers.cashTrendOption(data().cash.trend, 'CNY')
  assert.equal(option.series[2].data[0], -90)
  assert.ok(option.series.every(series => series.triggerLineEvent === true))
  assert.equal(option.yAxis.min, undefined)
  assert.equal(option.tooltip.renderMode, 'richText')
  const empty = helpers.compositionOption([{ label: '付款', amount: '0' }], '付款构成')
  assert.equal(empty.series[0].data.length, 0)
  assert.equal(helpers.hasAmounts([{ amount: '-1' }], ['amount']), true)
})

test('币种远程搜索请求到来顺序不覆盖，允许停用币种的历史查询', async () => {
  const pending = [], calls = []
  const { vm } = make({ listFinanceCurrencies: params => { calls.push(params); const request = deferred(); pending.push(request); return request.promise } })
  const first = vm.searchCurrencies('CN'), last = vm.searchCurrencies('US')
  pending[1].resolve({ data: { data: [{ currency_code: 'USD', status: 'disabled' }] } }); await last
  pending[0].resolve({ data: { data: [{ currency_code: 'CNY' }] } }); await first
  assert.equal(vm.currencies[0].currency_code, 'USD')
  assert.equal(calls[1].per_page, 50); assert.equal(calls[1].status, undefined)
  vm.filters.currency = 'EUR'; assert.equal(vm.currencyOptions[0].currency_code, 'EUR')
})
