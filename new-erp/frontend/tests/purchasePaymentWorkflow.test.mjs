import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const cashUtilitySource = await readFile(new URL('../src/utils/financeCashAllocation.js', import.meta.url), 'utf8')
const cashUtilityUrl = `data:text/javascript;base64,${Buffer.from(cashUtilitySource).toString('base64')}`
const cashUtils = await import(cashUtilityUrl)
const paymentUtilitySource = (await readFile(new URL('../src/utils/purchasePayment.js', import.meta.url), 'utf8')).replace("'./financeCashAllocation'", JSON.stringify(cashUtilityUrl))
const utils = await import(`data:text/javascript;base64,${Buffer.from(paymentUtilitySource).toString('base64')}`)
const response = data => ({ data: { data } })
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done }); return { promise, resolve } }
const installment = (overrides = {}) => ({ id: 31, sequence_no: 1, title: '厂家订金', trigger_type: 'deposit', amount: '300.0000', due_date: null, remark: '', paid_amount: '100.0000', refund_amount: '0.0000', remaining_amount: '200.0000', ...overrides })
const order = (overrides = {}) => ({ id: 11, purchase_order_id: 11, purchase_order_no: 'PO-11', supplier_id: 8, supplier_name: '供应商甲', currency: 'CNY', audit_status: 'approved', finance_fact_status: 'frozen', purchase_status: 'confirmed', version: 2, contract_amount: '1000.0000', contract_unpaid_amount: '900.0000', net_paid_amount: '100.0000', items: [installment()], ...overrides })
const link = (overrides = {}) => ({ purchase_order_id: 11, payment_plan_id: 31, amount: '200.0000', ...overrides })
const cash = (overrides = {}) => ({ id: 7, document_no: 'PAY-7', direction: 'payment', party_type: 'supplier', party_id: 8, party_name_snapshot: '供应商甲', currency: 'CNY', amount: '200.0000', allocated_amount: '0.0000', unallocated_amount: '200.0000', status: 'draft', allocations: [], draft_allocation_items: [], purchase_order_allocations: [link()], purchase_order_allocation_version: 3, attachments: [], logs: [], ...overrides })

// 直接运行组件业务方法，用接口替身验证真实状态与请求，不写入业务数据库。
async function component(name, dependencies = {}, overrides = {}) {
  const folder = name.startsWith('Finance') ? 'views/erp/finance' : 'components/finance'
  const content = await readFile(new URL(`../src/${folder}/${name}.vue`, import.meta.url), 'utf8')
  const script = content.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/import\s+(?:\{[\s\S]*?\}|[^{};\n]+)\s+from\s+['"][^'"]+['"];?/g, '')
    .replace('export default', 'return')
  const deps = {
    ...cashUtils, ...utils,
    PurchasePaymentOrderPicker: {}, PurchasePaymentLinks: {}, PurchasePaymentPlanDialog: {},
    FinanceAllocation: {}, FinanceSourcePicker: {}, FinancePendingAllocations: {},
    listFinanceAccounts: async () => response([]), listPaymentMethods: async () => response([]),
    reserveForCreatePage: async () => ({ document_no: 'PAY-new', reservation_token: 'reserved', creation_session_id: 'session' }),
    clearCreatePageReservation: () => {}, listSalesCustomers: async () => response([]), listEntity: async () => response([]),
    ...dependencies,
  }
  const options = new Function(...Object.keys(deps), script)(...Object.values(deps))
  const messages = []; const events = []; const routes = []
  const vm = { ...options.data(), visible: true, orderId: 11, supplierId: 8, currency: 'CNY', amount: '200.0000', value: [], existing: [], readonly: false, disabled: false, cashId: 7,
    direction: 'payment', embedded: false, initialSource: null,
    $route: { params: { id: '7' }, query: {} }, $refs: { form: { validate: done => done(true) }, links: { validate: () => '' } },
    $can: () => true, $confirm: async () => true, $set: (object, key, value) => { object[key] = value }, $delete: (object, key) => { delete object[key] },
    $nextTick: callback => callback(), $emit: (...args) => events.push(args),
    $message: Object.fromEntries(['success', 'warning', 'error'].map(level => [level, message => messages.push({ level, message })])),
    ...overrides,
  }
  Object.assign(vm, options.methods)
  for (const [key, getter] of Object.entries(options.computed || {})) Object.defineProperty(vm, key, { get: () => getter.call(vm) })
  vm.$router = { push: route => routes.push(route), replace: async path => { routes.push(path); vm.$route.params.id = path.split('/').at(-1) } }
  return { vm, options, events, messages, routes }
}

test('未知付款日期保持空值，所有付款条件均可待定，金额安排不能超合同', () => {
  for (const trigger of utils.paymentTriggers) {
    const row = installment({ trigger_type: trigger.value, due_date: '' })
    assert.equal(utils.validatePaymentPlan([row], '1000'), '')
    assert.equal(utils.serializePaymentPlan([row])[0].due_date, null)
  }
  assert.equal(utils.paymentDueLabel(null, '300', '2026-10-05'), '日期待定')
  assert.equal(utils.paymentDueLabel('2026-10-01', '0', '2026-10-05'), '2026-10-01')
  assert.match(utils.paymentDueLabel('2026-10-01', '300', '2026-10-05'), /已到期/)
  assert.match(utils.validatePaymentPlan([installment({ amount: '1000.0001' })], '1000'), /不能超过/)
  assert.match(utils.validatePaymentPlan([installment({ amount: '0' })], '1000'), /大于 0/)
})

test('付款安排保存仅提交安排版本、条件、金额和空日期，保留原期次标题', async () => {
  const calls = []
  const { vm, events } = await component('PurchasePaymentPlanDialog', { savePurchasePaymentPlan: async (id, body) => { calls.push([id, body]); return response(order({ version: 3, items: body.items })) } })
  vm.applyPlan(order())
  vm.items[0].amount = '350.0000'
  await vm.save()
  assert.deepEqual(calls, [[11, { version: 2, items: [{ id: 31, title: '厂家订金', trigger_type: 'deposit', amount: '350.0000', due_date: null, remark: '' }] }]])
  assert.equal(vm.plan.version, 3)
  assert.equal(vm.dirty, false)
  assert.equal(events[0][0], 'changed')
})

test('付款安排版本冲突保留输入，重复保存被阻止，重新载入后解除冲突', async () => {
  let writes = 0
  const { vm } = await component('PurchasePaymentPlanDialog', {
    savePurchasePaymentPlan: async () => { writes += 1; throw { response: { status: 409 } } },
    getPurchasePaymentPlan: async () => response(order({ version: 4 })),
  })
  vm.applyPlan(order()); vm.items[0].amount = '450'
  await vm.save(); await vm.save()
  assert.equal(writes, 1); assert.equal(vm.items[0].amount, '450'); assert.equal(vm.conflict, true)
  await vm.load()
  assert.equal(vm.plan.version, 4); assert.equal(vm.conflict, false); assert.equal(vm.dirty, false)
})

test('采购草稿允许安排但不能付款，已取消订单不可改，已保存已审核期次才可发起', async () => {
  const { vm, routes } = await component('PurchasePaymentPlanDialog')
  vm.applyPlan(order({ audit_status: 'draft', purchase_status: 'draft', finance_fact_status: 'unfrozen' }))
  assert.equal(vm.editable, true); vm.startPayment(vm.items[0]); assert.equal(routes.length, 0)
  vm.applyPlan(order({ purchase_status: 'cancelled' })); assert.equal(vm.editable, false)
  vm.applyPlan(order()); vm.items[0].amount = '400'; vm.startPayment(vm.items[0]); assert.equal(routes.length, 0)
  vm.applyPlan(order()); vm.startPayment(vm.items[0])
  assert.deepEqual(routes, [{ path: '/finance/payments/create', query: { purchase_order_id: 11, payment_plan_id: 31 } }])
})

test('切换采购订单后旧安排请求不能覆盖新订单', async () => {
  const old = deferred()
  const { vm } = await component('PurchasePaymentPlanDialog', { getPurchasePaymentPlan: id => id === 11 ? old.promise : response(order({ purchase_order_id: 12 })) })
  const first = vm.load(); vm.orderId = 12; vm.reset(); await vm.load()
  old.resolve(response(order())); await first
  assert.equal(vm.plan.purchase_order_id, 12)
})

test('采购选择器服务端翻页并保留已选项，拒绝供应商币种不同或已关联订单', async () => {
  const calls = []
  const { vm, events } = await component('PurchasePaymentOrderPicker', { listPurchasePaymentOrders: async params => { calls.push(params); return { data: { data: [order({ id: params.page })], total: 27 } } } })
  await vm.load(); vm.toggle(order(), true); await vm.changePage(2)
  vm.toggle(order({ id: 12, purchase_order_id: 12 }), true)
  vm.toggle(order({ id: 13, purchase_order_id: 13, supplier_id: 9 }), true)
  vm.toggle(order({ id: 14, purchase_order_id: 14, currency: 'USD' }), true)
  vm.existing = [link({ purchase_order_id: 15 })]; vm.toggle(order({ purchase_order_id: 15 }), true)
  assert.deepEqual(calls.map(call => [call.supplier_id, call.currency, call.page, call.per_page]), [[8, 'CNY', 1, 10], [8, 'CNY', 2, 10]])
  assert.deepEqual(vm.selected.map(row => row.purchase_order_id), [11, 12])
  vm.confirm(); assert.equal(events[0][1].length, 2)
  vm.reset(); assert.equal(vm.selected.length, 0); assert.equal(vm.page, 1)
})

test('采购选择器不接收上次搜索的迟到响应', async () => {
  const old = deferred()
  const { vm } = await component('PurchasePaymentOrderPicker', { listPurchasePaymentOrders: params => params.keyword ? { data: { data: [order({ purchase_order_no: 'NEW' })], total: 1 } } : old.promise })
  const first = vm.load(); vm.keyword = 'NEW'; await vm.search()
  old.resolve({ data: { data: [order()], total: 100 } }); await first
  assert.equal(vm.rows[0].purchase_order_no, 'NEW'); assert.equal(vm.total, 1)
})

test('多订单多期次金额精确相等才可提交，同订单不同期次可分配，同期次拒绝重复', () => {
  const rows = [link({ amount: '0.1000' }), link({ payment_plan_id: 32, amount: '0.1000' }), link({ purchase_order_id: 12, payment_plan_id: null, amount: '0.1000' })]
  assert.equal(utils.validatePurchaseOrderAllocations(rows, '0.3000'), '')
  assert.match(utils.validatePurchaseOrderAllocations(rows, '0.3001'), /合计必须等于/)
  assert.match(utils.validatePurchaseOrderAllocations([rows[0], rows[0]], '0.2000'), /不能重复/)
  assert.match(utils.validatePurchaseOrderAllocations(rows, '0.3000', 'customer'), /供应商/)
  assert.match(utils.validatePurchaseOrderAllocations([link({ amount: '0.00001' })], '0.0001'), /最多 4/)
})

test('已选采购订单金额只分配剩余资金，额外期次保持零并要求手工分配', async () => {
  const { vm, events } = await component('PurchasePaymentLinks', {}, { amount: '500', value: [link({ amount: '300' })] })
  vm.addOrders([order({ id: 12, purchase_order_id: 12, contract_unpaid_amount: '900' }), order({ id: 13, purchase_order_id: 13, contract_unpaid_amount: '50' })])
  assert.deepEqual(events[0][1].map(row => row.amount), ['300', '200.0000', '0.0000'])
  assert.equal(vm.value.length, 1)
  vm.facts = { 11: order({ items: [installment(), installment({ id: 32, sequence_no: 2, trigger_type: 'after_receipt' })] }) }
  vm.addInstallment(0)
  assert.equal(events[1][1][1].payment_plan_id, 32); assert.equal(events[1][1][1].amount, '0.0000')
})

test('已关联订单实时校验供应商币种和审核状态，失效期次可重新选回未指定', async () => {
  const { vm, events } = await component('PurchasePaymentLinks', { getPurchasePaymentPlan: async () => response(order()) }, { value: [link({ payment_plan_id: 999 })] })
  await vm.loadFacts(); assert.match(vm.validationError, /期次已失效/)
  vm.planChanged(0, 0); vm.value = events.at(-1)[1]
  assert.equal(vm.value[0].payment_plan_id, null); assert.equal(vm.validationError, '')
  assert.equal(vm.conditionText(link()), '订金 / 日期待定')
  vm.currency = 'USD'; await vm.loadFacts(); assert.match(vm.validationError, /币种/)
  const draft = await component('PurchasePaymentLinks', { getPurchasePaymentPlan: async () => response(order({ audit_status: 'draft' })) }, { value: [link()] })
  await draft.vm.loadFacts(); assert.match(draft.vm.validationError, /尚未审核/)
})

test('关联金额编辑不会改写父数据，移除订单后旧事实请求不能回填', async () => {
  const pending = deferred()
  const { vm, events } = await component('PurchasePaymentLinks', { getPurchasePaymentPlan: () => pending.promise }, { value: [link()] })
  vm.patchRow(0, { amount: '50' }); assert.equal(vm.value[0].amount, '200.0000'); assert.equal(events[0][1][0].amount, '50')
  const first = vm.loadFacts(); vm.value = []; await vm.loadFacts(); pending.resolve(response(order())); await first
  assert.deepEqual(vm.facts, {}); assert.equal(vm.loadingFacts, false)
})

test('历史付款补充采购用途要求调整原因，并提交版本和稳定幂等键', async () => {
  const calls = []
  const { vm, messages } = await component('PurchasePaymentLinkDialog', { updateCashPurchaseOrders: async (id, body) => { calls.push([id, body]); return response(cash({ status: 'confirmed', purchase_order_allocation_version: 4 })) } })
  vm.applyDocument(cash({ status: 'confirmed' })); const key = vm.idempotencyKey
  await vm.save(); assert.equal(calls.length, 0); assert.match(messages.at(-1).message, /调整原因/)
  vm.reason = '  补充历史订单用途  '; await vm.save()
  assert.deepEqual(calls, [[7, { version: 3, idempotency_key: key, reason: '补充历史订单用途', purchase_order_allocations: [link()] }]])
  assert.equal(vm.doc.purchase_order_allocation_version, 4)
})

test('采购用途调整冲突保留金额及原因，退款按收款确认权限控制', async () => {
  let writes = 0
  const { vm } = await component('PurchasePaymentLinkDialog', { updateCashPurchaseOrders: async () => { writes += 1; throw { response: { status: 409 } } } }, { $can: permission => permission === 'finance.receipt.confirm' })
  vm.applyDocument(cash({ direction: 'receipt', status: 'confirmed' })); vm.reason = '退款用途'; vm.rows = [link({ payment_plan_id: null })]
  assert.equal(vm.editable, true); await vm.save(); await vm.save()
  assert.equal(writes, 1); assert.equal(vm.conflict, true); assert.equal(vm.reason, '退款用途'); assert.equal(vm.rows[0].payment_plan_id, null)
  vm.doc.direction = 'payment'; assert.equal(vm.editable, false)
})

test('付款期次入口只读权威余额，忽略URL金额与供应商，保留预付款不自动核销', async () => {
  const calls = []
  const { vm } = await component('FinanceCashForm', { getPurchasePaymentPlan: async id => { calls.push(id); return response(order()) } }, { $route: { params: {}, query: {} } })
  await vm.loadInitialPurchaseOrder({ purchase_order_id: '11', payment_plan_id: '31', amount: '999999', supplier_id: '999' }, 0)
  assert.deepEqual(calls, [11]); assert.equal(vm.doc.party_id, 8); assert.equal(vm.doc.amount, '200.0000'); assert.equal(vm.doc.currency, 'CNY')
  assert.deepEqual(vm.pending, []); assert.equal(vm.doc.purchase_order_allocations[0].payment_plan_id, 31); assert.equal(vm.sourceLoadError, '')
})

test('无效或未审核采购付款入口阻止保存，不能回退为普通付款', async () => {
  for (const fixture of [order({ audit_status: 'draft' }), order({ items: [] }), order({ items: [installment({ remaining_amount: '0' })] })]) {
    let writes = 0
    const { vm } = await component('FinanceCashForm', { getPurchasePaymentPlan: async () => response(fixture), createCashDocument: async () => { writes += 1 } }, { $route: { params: {}, query: {} } })
    await vm.loadInitialPurchaseOrder({ purchase_order_id: '11', payment_plan_id: '31' }, 0); vm.doc.amount = '10'; await vm.save()
    assert.ok(vm.sourceLoadError); assert.equal(writes, 0)
  }
})

test('同一入口混入结算来源与付款期次时明确阻止，不发出任一来源读取请求', async () => {
  let reads = 0
  const { vm } = await component('FinanceCashForm', { getPurchasePaymentPlan: async () => { reads += 1 }, resolveFinanceSource: async () => { reads += 1 } }, { $route: { params: {}, query: { source_type: 'purchase_settlement_source', source_id: '4', purchase_order_id: '11' } } })
  await vm.init(); assert.equal(reads, 0); assert.match(vm.sourceLoadError, /不能同时/)
})

test('采购用途保存与确认沿用原子资金流程，采购关联不转换成结算核销', async () => {
  const calls = []
  const { vm } = await component('FinanceCashForm', {
    updateCashDocument: async (id, body) => { calls.push(['save', body]); return response(cash({ ...body })) },
    confirmCashDocument: async (id, body) => { calls.push(['confirm', body]); return response(cash({ status: 'confirmed' })) },
  })
  vm.doc = cash({ purchase_order_allocations: [link({ purchase_order_no: 'PO-11', sequence_no: 1 })], purchase_order_allocation_reason: '  厂家排产订金  ' })
  await vm.confirmDoc()
  assert.equal(calls.length, 2); assert.deepEqual(calls[0][1].purchase_order_allocations, [link()]); assert.equal(calls[0][1].purchase_order_allocation_reason, '厂家排产订金')
  assert.deepEqual(calls[0][1].draft_allocation_items, []); assert.deepEqual(calls[1], ['confirm', { items: [] }]); assert.equal(vm.doc.status, 'confirmed')
})

test('更换供应商或账户币种时清理旧采购用途与待核销来源', async () => {
  const { vm } = await component('FinanceCashForm')
  vm.doc = cash(); vm.pending = [{ id: 1 }]; vm.purchaseOrderContext = { purchase_order_id: 11 }; vm.accounts = [{ id: 99, currency: 'USD' }]
  vm.accountChanged(99)
  assert.equal(vm.doc.currency, 'USD'); assert.deepEqual(vm.doc.purchase_order_allocations, []); assert.deepEqual(vm.pending, []); assert.equal(vm.purchaseOrderContext, null)
  vm.doc.purchase_order_allocations = [link()]; vm.partyChanged(); assert.deepEqual(vm.doc.purchase_order_allocations, []); assert.equal(vm.doc.party_id, null)
})

test('来源应付提供采购订单时关联相同权威订单，不猜付款期次', async () => {
  const { vm } = await component('FinanceCashForm', {
    resolveFinanceSource: async () => response({ type: 'purchase_settlement_source', id: 4, no: 'SET-4', partyType: 'supplier', partyId: 8, partyName: '供应商甲', currency: 'CNY', amount: '700', allocatedAmount: '500', remainingAmount: '200', purchase_order_id: 11 }),
    getPurchasePaymentPlan: async () => response(order()),
  })
  await vm.loadInitialSource({ source_type: 'purchase_settlement_source', source_id: 4 }, 0)
  assert.equal(vm.doc.purchase_order_allocations[0].purchase_order_id, 11); assert.equal(vm.doc.purchase_order_allocations[0].payment_plan_id, null); assert.equal(vm.doc.purchase_order_allocations[0].amount, '200'); assert.equal(vm.pending.length, 1)
})

test('资金列表含采购用途的草稿转详情核对，不执行无用途快捷确认', async () => {
  let writes = 0
  const { vm, routes } = await component('FinanceCashList', { confirmCashDocument: async () => { writes += 1 } })
  await vm.confirm(cash())
  assert.equal(writes, 0); assert.deepEqual(routes, ['/finance/payments/7'])
  assert.equal(vm.purchaseOrderSummary(cash({ purchase_order_allocations: [link({ purchase_order_no: 'PO-11' }), link({ purchase_order_no: 'PO-11', payment_plan_id: 32 })] })), 'PO-11')
})
