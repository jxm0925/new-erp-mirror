import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const utilitySource = await readFile(new URL('../src/utils/financeCashAllocation.js', import.meta.url), 'utf8')
const utils = await import(`data:text/javascript;base64,${Buffer.from(utilitySource).toString('base64')}`)
const purchaseUtilitySource = (await readFile(new URL('../src/utils/purchasePayment.js', import.meta.url), 'utf8'))
  .replace("'./financeCashAllocation'", `'data:text/javascript;base64,${Buffer.from(utilitySource).toString('base64')}'`)
const purchaseUtils = await import(`data:text/javascript;base64,${Buffer.from(purchaseUtilitySource).toString('base64')}`)
const source = (overrides = {}) => ({ type: 'purchase_settlement_source', id: 12, no: 'SET-12', partyType: 'supplier', partyId: 8, partyName: '供应商甲', currency: 'CNY', amount: '1000.0000', allocatedAmount: '700.0000', remainingAmount: '300.0000', ...overrides })
const cash = (overrides = {}) => ({ id: 7, document_no: 'PAY-7', direction: 'payment', party_type: 'supplier', party_id: 8, party_name_snapshot: '供应商甲', currency: 'CNY', amount: '500.0000', allocated_amount: '0.0000', unallocated_amount: '500.0000', status: 'draft', allocations: [], draft_allocation_items: [], attachments: [], logs: [], ...overrides })
const response = data => ({ data: { data } })

// 执行实际组件方法，只替换网络与交互确认；验证 API 顺序、参数和页面状态，不创建业务数据。
async function component(name, dependencies = {}, overrides = {}) {
  const path = name === 'FinanceSourcePicker' ? `../src/components/finance/${name}.vue` : `../src/views/erp/finance/${name}.vue`
  const content = await readFile(new URL(path, import.meta.url), 'utf8')
  const script = content.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/import\s+(?:\{[\s\S]*?\}|[^{};\n]+)\s+from\s+['"][^'"]+['"];?/g, '')
    .replace('export default', 'return')
  const deps = {
    ...utils,
    ...purchaseUtils,
    FinanceAllocation: {}, FinanceSourcePicker: {}, FinancePendingAllocations: {},
    listFinanceAccounts: async () => response([]), listPaymentMethods: async () => response([]),
    reserveForCreatePage: async () => ({ document_no: 'PAY-new', reservation_token: 'reserved', creation_session_id: 'session' }),
    clearCreatePageReservation: () => {}, listSalesCustomers: async () => response([]), listEntity: async () => response([]),
    ...dependencies,
  }
  const options = new Function(...Object.keys(deps), script)(...Object.values(deps))
  const messages = []
  const events = []
  const vm = { ...options.data(), direction: 'payment', cashId: 0, embedded: false, initialSource: null,
    $route: { params: { id: '7' }, query: {} }, $refs: { form: { validate: callback => callback(true) } },
    $can: () => true, $confirm: async () => true, $prompt: async () => ({ value: '金额录入有误' }),
    $emit: (...args) => events.push(args),
    $message: Object.fromEntries(['success', 'warning', 'error'].map(level => [level, message => messages.push({ level, message })])),
    ...overrides }
  Object.assign(vm, options.methods)
  Object.entries(options.computed || {}).forEach(([key, getter]) => Object.defineProperty(vm, key, { get: () => getter.call(vm) }))
  vm.$router = { replace: async path => { vm.$route.params.id = path.split('/').at(-1); options.watch?.['$route.fullPath']?.call(vm) }, push: async () => {} }
  return { vm, options, messages, events }
}

test('来源选项同时区分收付款与客户供应商，覆盖两种退款', () => {
  assert.equal(utils.sourceOptionsFor('receipt', 'customer')[0].value, 'sales_order')
  assert.equal(utils.sourceOptionsFor('receipt', 'supplier')[0].value, 'purchase_return_supplier_refund')
  assert.equal(utils.sourceOptionsFor('payment', 'supplier')[0].value, 'purchase_settlement_source')
  assert.equal(utils.sourceOptionsFor('payment', 'customer')[0].value, 'sales_order_refund')
  assert.match(utils.sourceMismatch(source({ partyId: 9 }), cash()), /交易对手/)
  assert.match(utils.sourceMismatch(source({ currency: 'USD' }), cash()), /币种/)
})

test('金额提示精确到四位小数，拒绝零值、负数、超额及缺少权威余额', () => {
  const a = utils.pendingFromSource(source({ id: 1, remainingAmount: '0.1000' }), '0.1000')
  const b = utils.pendingFromSource(source({ id: 2, remainingAmount: '0.2000' }), '0.2000')
  assert.equal(utils.validatePendingAllocations([a, b], '0.3000'), '')
  assert.match(utils.validatePendingAllocations([{ ...a, allocated_amount: '0' }], '0.3'), /大于 0/)
  assert.match(utils.validatePendingAllocations([{ ...a, allocated_amount: '-1' }], '0.3'), /大于 0/)
  assert.match(utils.validatePendingAllocations([{ ...a, allocated_amount: '0.1001' }], '0.3'), /超过当前待结算/)
  assert.match(utils.validatePendingAllocations([a, b], '0.2999'), /不得超过/)
  assert.throws(() => utils.pendingFromSource(source({ remainingAmount: undefined }), '10'), /未返回可核销余额/)
})

test('应付入口只信任权威来源，预填剩余300而非总额1000或URL金额', async () => {
  const calls = []
  const { vm } = await component('FinanceCashForm', { resolveFinanceSource: async params => { calls.push(params); return response(source()) } }, { $route: { params: {}, query: {} } })
  await vm.loadInitialSource({ source_type: 'purchase_settlement_source', source_id: '12', amount: '999999', supplier_id: '999' }, 0)
  assert.deepEqual(calls, [{ type: 'purchase_settlement_source', id: 12 }])
  assert.equal(vm.doc.party_id, 8)
  assert.equal(vm.doc.amount, '300.0000')
  assert.equal(vm.pending[0].allocated_amount, '300.0000')
  assert.equal(vm.doc.currency, 'CNY')
})

test('指定来源失败阻止误保存为无来源付款', async () => {
  let writes = 0
  const { vm, messages } = await component('FinanceCashForm', {
    resolveFinanceSource: async () => { throw { userMessage: '结算来源已冻结' } },
    createCashDocument: async () => { writes += 1 },
  }, { $route: { params: {}, query: {} } })
  await vm.loadInitialSource({ source_type: 'purchase_settlement_source', source_id: '12' }, 0)
  vm.doc.amount = '100'
  await vm.save()
  assert.equal(writes, 0)
  assert.match(vm.sourceLoadError, /已冻结/)
  assert.match(messages.at(-1).message, /已冻结/)
})

test('保存草稿持久化意向，路由替换后保留手工金额和幂等键', async () => {
  let saved
  const { vm } = await component('FinanceCashForm', { createCashDocument: async (direction, data) => { saved = data; return response(cash({ amount: data.amount, draft_allocation_items: data.draft_allocation_items })) } }, { $route: { params: {}, query: { source_type: 'purchase_settlement_source', source_id: '12' } } })
  vm.doc = cash({ id: null })
  vm.pending = [utils.pendingFromSource(source(), '80')]
  vm.reservation = { reservation_token: 'reservation', creation_session_id: 'session' }
  const key = vm.pending[0].idempotency_key
  await vm.save()
  assert.equal(vm.doc.id, 7)
  assert.equal(vm.pending[0].allocated_amount, '80.0000')
  assert.equal(vm.pending[0].idempotency_key, key)
  assert.equal(saved.draft_allocation_items[0].idempotency_key, key)
  assert.equal(saved.draft_allocation_items[0].source_document_no, undefined)
  assert.equal(saved.draft_allocation_items[0].source_amount, undefined)
})

test('保存并确认先保存当前编辑，再以同一请求确认和核销', async () => {
  const calls = []
  const { vm } = await component('FinanceCashForm', {
    updateCashDocument: async (id, data) => { calls.push(['save', id, data]); return response(data) },
    confirmCashDocument: async (id, data) => { calls.push(['confirm', id, data]); return response(cash({ status: 'confirmed', amount: '450', allocated_amount: '120', unallocated_amount: '330' })) },
  })
  vm.doc = cash({ amount: '450', remark: '本次手工修改' })
  vm.pending = [utils.pendingFromSource(source(), '120')]
  await vm.confirmDoc()
  assert.deepEqual(calls.map(call => call[0]), ['save', 'confirm'])
  assert.equal(calls[0][2].remark, '本次手工修改')
  assert.equal(calls[0][2].amount, '450')
  assert.deepEqual(calls[1][2], { items: calls[0][2].draft_allocation_items })
  assert.equal(vm.doc.unallocated_amount, '330')
  assert.equal(vm.pending.length, 0)
})

test('确认失败保留待核销金额及幂等键，禁止假显示已确认', async () => {
  const { vm, messages } = await component('FinanceCashForm', {
    updateCashDocument: async (id, data) => response(data),
    confirmCashDocument: async () => { throw { userMessage: '来源可用余额已变化' } },
  })
  vm.doc = cash()
  vm.pending = [utils.pendingFromSource(source(), '120')]
  const key = vm.pending[0].idempotency_key
  await vm.confirmDoc()
  assert.equal(vm.doc.status, 'draft')
  assert.equal(vm.pending[0].allocated_amount, '120.0000')
  assert.equal(vm.pending[0].idempotency_key, key)
  assert.match(messages.at(-1).message, /余额已变化/)
})

test('无来源预付款可以确认，明确提交空items', async () => {
  let body
  const { vm } = await component('FinanceCashForm', {
    updateCashDocument: async (id, data) => response(data),
    confirmCashDocument: async (id, data) => { body = data; return response(cash({ status: 'confirmed' })) },
  })
  vm.doc = cash()
  await vm.confirmDoc()
  assert.deepEqual(body, { items: [] })
  assert.equal(vm.doc.status, 'confirmed')
})

test('只有确认权限时可以确认已有草稿，不调用需创建权限的保存接口', async () => {
  let saves = 0
  let confirms = 0
  const { vm } = await component('FinanceCashForm', {
    updateCashDocument: async () => { saves += 1 },
    confirmCashDocument: async () => { confirms += 1; return response(cash({ status: 'confirmed' })) },
  }, { $can: permission => permission === 'finance.payment.confirm' })
  vm.doc = cash()
  await vm.confirmDoc()
  assert.equal(saves, 0)
  assert.equal(confirms, 1)
})

test('没有核销权限不能通过保存并确认产生核销', async () => {
  let writes = 0
  const { vm, messages } = await component('FinanceCashForm', {
    updateCashDocument: async () => { writes += 1 }, confirmCashDocument: async () => { writes += 1 },
  }, { $can: permission => permission !== 'finance.allocation.create' })
  vm.doc = cash()
  vm.pending = [utils.pendingFromSource(source(), '100')]
  await vm.confirmDoc()
  assert.equal(writes, 0)
  assert.match(messages.at(-1).message, /没有核销权限/)
})

test('草稿重开核对余额但保留手工金额，变化后提示用户修正', async () => {
  const { vm } = await component('FinanceCashForm', { resolveFinanceSource: async () => response(source({ remainingAmount: '60' })) })
  vm.doc = cash({ draft_allocation_items: [{ source_business_type: 'purchase_settlement_source', source_document_id: 12, allocated_amount: '120', idempotency_key: 'persisted-key' }] })
  await vm.restorePending()
  assert.equal(vm.pending[0].allocated_amount, '120')
  assert.equal(vm.pending[0].idempotency_key, 'persisted-key')
  assert.equal(vm.pending[0].source_document_no, 'SET-12')
  assert.match(vm.pendingError, /超过当前待结算余额/)
})

test('附件刷新保留尚未保存的金额、备注与待核销清单', async () => {
  const { vm } = await component('FinanceCashForm', { getCashDocument: async () => response(cash({ attachments: [{ id: 3 }], logs: [{ action: 'upload' }] })) })
  vm.doc = cash({ amount: '1200', remark: '待保存备注' })
  vm.pending = [utils.pendingFromSource(source(), '120')]
  await vm.reload()
  assert.equal(vm.doc.amount, '1200')
  assert.equal(vm.doc.remark, '待保存备注')
  assert.equal(vm.pending.length, 1)
  assert.equal(vm.doc.attachments[0].id, 3)
})

test('核销弹窗优先cashId，切单清空意向，迟到响应不能覆盖新单', async () => {
  const requests = []
  const { vm } = await component('FinanceAllocation', { getCashDocument: id => new Promise(resolve => requests.push({ id, resolve })) }, { cashId: 9 })
  vm.pending = [{ id: 'old' }]
  const first = vm.load(true)
  assert.equal(vm.pending.length, 0)
  assert.equal(requests[0].id, 9)
  vm.cashId = 10
  const second = vm.load(true)
  requests[1].resolve(response(cash({ id: 10, status: 'confirmed' })))
  await second
  requests[0].resolve(response(cash({ id: 9, status: 'confirmed' })))
  await first
  assert.equal(vm.doc.id, 10)
})

test('核销成功重新取得余额并通知父级，完整保留撤销历史', async () => {
  const calls = []
  const updated = cash({ status: 'confirmed', allocated_amount: '120', unallocated_amount: '380', allocations: [{ id: 1, status: 'reversed' }, { id: 2, status: 'active' }] })
  const { vm, events } = await component('FinanceAllocation', {
    allocateCashDocument: async (id, items) => { calls.push({ id, items }) }, getCashDocument: async () => response(updated),
  }, { cashId: 7 })
  vm.doc = cash({ status: 'confirmed' })
  vm.pending = [utils.pendingFromSource(source(), '120')]
  await vm.submit()
  assert.equal(calls.length, 1)
  assert.equal(vm.pending.length, 0)
  assert.equal(vm.doc.unallocated_amount, '380')
  assert.equal(vm.doc.allocations.length, 2)
  assert.deepEqual(events[0], ['changed', updated])
})

test('核销失败保留清单，可按同一个幂等键重试', async () => {
  const { vm } = await component('FinanceAllocation', { allocateCashDocument: async () => { throw { userMessage: '余额不足' } } }, { cashId: 7 })
  vm.doc = cash({ status: 'confirmed' })
  vm.pending = [utils.pendingFromSource(source(), '100')]
  const key = vm.pending[0].idempotency_key
  await vm.submit()
  assert.equal(vm.pending[0].idempotency_key, key)
  assert.equal(vm.doc.unallocated_amount, '500.0000')
})

test('撤销核销带原因，成功后通知父级并刷新余额', async () => {
  let reversal
  const updated = cash({ status: 'confirmed', allocated_amount: '0', unallocated_amount: '500' })
  const { vm, events } = await component('FinanceAllocation', {
    reverseFinanceAllocation: async (...args) => { reversal = args }, getCashDocument: async () => response(updated),
  }, { cashId: 7 })
  vm.doc = cash({ status: 'confirmed', allocated_amount: '120', unallocated_amount: '380' })
  await vm.reverse({ id: 42, status: 'active' })
  assert.deepEqual(reversal, [42, '金额录入有误'])
  assert.equal(vm.doc.unallocated_amount, '500')
  assert.equal(events[0][0], 'changed')
})

test('业务选择器请求服务端分页和币种过滤，跨页搜索保留已选，关闭清除暂选', async () => {
  const queries = []
  const { vm, options } = await component('FinanceSourcePicker', { listFinanceSources: async params => { queries.push(params); return { data: { data: [source()], total: 24 } } } }, { partyType: 'supplier', partyId: 8, currency: 'CNY', existing: [], visible: true })
  vm.sourceType = 'purchase_settlement_source'
  await vm.load()
  vm.toggle(source(), true)
  await vm.changePage(2)
  vm.keyword = '订单'
  await vm.search()
  assert.equal(queries[1].page, 2)
  assert.equal(queries[1].per_page, 10)
  assert.equal(queries[1].currency, 'CNY')
  assert.equal(queries[1].party_id, 8)
  assert.equal(queries[2].page, 1)
  assert.equal(vm.selected.length, 1)
  options.watch.visible.handler.call(vm, false)
  assert.equal(vm.selected.length, 0)
  assert.equal(vm.rows.length, 0)
})

test('多选来源全部带回，余额不足的行等待手工分摊且不能直接提交', async () => {
  const { vm } = await component('FinanceCashForm')
  vm.doc = cash({ amount: '100' })
  vm.addSources([source(), source({ id: 13, no: 'SET-13' })])
  assert.equal(vm.pending.length, 2)
  assert.equal(vm.pending[0].allocated_amount, '100.0000')
  assert.equal(vm.pending[1].allocated_amount, '0.0000')
  assert.match(vm.pendingError, /必须大于 0/)
  vm.pending[0].allocated_amount = '40'
  vm.pending[1].allocated_amount = '60'
  assert.equal(vm.pendingError, '')
})

test('选择器旧搜索迟到不能覆盖新搜索结果', async () => {
  const requests = []
  const { vm } = await component('FinanceSourcePicker', { listFinanceSources: params => new Promise(resolve => requests.push({ params, resolve })) }, { partyType: 'supplier', partyId: 8, currency: 'CNY', existing: [], visible: true })
  vm.sourceType = 'purchase_settlement_source'
  const first = vm.load()
  vm.keyword = 'SET-13'
  const second = vm.search()
  requests[1].resolve({ data: { data: [source({ id: 13 })], total: 1 } })
  await second
  requests[0].resolve({ data: { data: [source()], total: 24 } })
  await first
  assert.equal(vm.rows[0].id, 13)
  assert.equal(vm.total, 1)
})

test('核销后刷新失败时通知父级刷新，同时阻止继续使用旧余额', async () => {
  const { vm, events } = await component('FinanceAllocation', {
    allocateCashDocument: async () => {}, getCashDocument: async () => { throw new Error('network') },
  }, { cashId: 7 })
  vm.doc = cash({ status: 'confirmed' })
  vm.pending = [utils.pendingFromSource(source(), '120')]
  await vm.submit()
  assert.equal(vm.pending.length, 0)
  assert.equal(vm.canAllocate, false)
  assert.deepEqual(events[0], ['changed', { id: 7 }])
})

test('新建保存并确认在按路由销毁组件前完成原子确认，新详情读取已确认状态', async () => {
  const calls = []
  let server
  let remounted
  const deps = {
    createCashDocument: async (direction, data) => { calls.push('create'); server = cash({ ...data, id: 7 }); return response(server) },
    confirmCashDocument: async (id, data) => {
      calls.push('confirm')
      assert.equal(original.vm.initRevision, 0, '调用确认时原页面尚未被销毁')
      server = cash({ ...server, status: 'confirmed', draft_allocation_items: [], allocated_amount: data.items[0].allocated_amount, unallocated_amount: '380' })
      return response(server)
    },
    getCashDocument: async () => response(server),
    resolveFinanceSource: async () => response(source()),
  }
  const original = await component('FinanceCashForm', deps, { $route: { params: {}, query: {} } })
  original.vm.doc = cash({ id: null })
  original.vm.pending = [utils.pendingFromSource(source(), '120')]
  original.vm.reservation = { reservation_token: 'reservation', creation_session_id: 'session' }
  original.vm.$router.replace = async path => {
    calls.push('navigate')
    original.options.beforeDestroy.call(original.vm)
    remounted = await component('FinanceCashForm', deps, { $route: { params: { id: path.split('/').at(-1) }, query: {} } })
    await remounted.vm.init()
  }
  await original.vm.confirmDoc()
  assert.deepEqual(calls, ['create', 'confirm', 'navigate'])
  assert.equal(remounted.vm.doc.status, 'confirmed')
  assert.equal(remounted.vm.doc.unallocated_amount, '380')
  assert.equal(remounted.vm.pending.length, 0)
})

test('新建确认失败后再导航，重挂载详情恢复原意向和手工金额', async () => {
  const calls = []
  let server
  let remounted
  const deps = {
    createCashDocument: async (direction, data) => { calls.push('create'); server = cash({ ...data, id: 7 }); return response(server) },
    confirmCashDocument: async () => { calls.push('confirm'); throw { userMessage: '余额已变化' } },
    getCashDocument: async () => response(server), resolveFinanceSource: async () => response(source()),
  }
  const original = await component('FinanceCashForm', deps, { $route: { params: {}, query: {} } })
  original.vm.doc = cash({ id: null })
  original.vm.pending = [utils.pendingFromSource(source(), '123.4567')]
  original.vm.reservation = { reservation_token: 'reservation', creation_session_id: 'session' }
  const key = original.vm.pending[0].idempotency_key
  original.vm.$router.replace = async path => {
    calls.push('navigate')
    original.options.beforeDestroy.call(original.vm)
    remounted = await component('FinanceCashForm', deps, { $route: { params: { id: path.split('/').at(-1) }, query: {} } })
    await remounted.vm.init()
  }
  await original.vm.confirmDoc()
  assert.deepEqual(calls, ['create', 'confirm', 'navigate'])
  assert.equal(remounted.vm.doc.status, 'draft')
  assert.equal(remounted.vm.pending[0].allocated_amount, '123.4567')
  assert.equal(remounted.vm.pending[0].idempotency_key, key)
})

test('确认响应超时后读取实际状态，不再把已确认单保留为草稿', async () => {
  const { vm, messages } = await component('FinanceCashForm', {
    updateCashDocument: async (id, data) => response(data),
    confirmCashDocument: async () => { throw new Error('网络响应超时') },
    getCashDocument: async () => response(cash({ status: 'confirmed', allocated_amount: '120', unallocated_amount: '380' })),
  })
  vm.doc = cash()
  vm.pending = [utils.pendingFromSource(source(), '120')]
  await vm.confirmDoc()
  assert.equal(vm.doc.status, 'confirmed')
  assert.equal(vm.pending.length, 0)
  assert.match(messages.at(-1).message, /已刷新实际状态/)
})
