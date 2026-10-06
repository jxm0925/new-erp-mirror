import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const panelSource = await readFile(new URL('../src/components/sales/SalesOrderFinancePanel.vue', import.meta.url), 'utf8')
const detailSource = await readFile(new URL('../src/views/erp/sales/SalesOrderDetail.vue', import.meta.url), 'utf8')
const formSource = await readFile(new URL('../src/views/erp/sales/SalesOrderForm.vue', import.meta.url), 'utf8')
const listSource = await readFile(new URL('../src/views/erp/sales/SalesOrderList.vue', import.meta.url), 'utf8')
const changeSource = await readFile(new URL('../src/views/erp/sales/SalesOrderChangeForm.vue', import.meta.url), 'utf8')
const script = source => source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '').replace('export default', 'return')

function makePanel(overrides = {}) {
  const calls = [], messages = [], routes = []
  const dependencies = {
    getSalesOrderFinance: async () => ({ data: { data: { version: 3, order_status: 'confirmed', settlement: { contract_amount: '1000.0000', net_received_amount: '300.0000', outstanding_amount: '700.0000' }, purchase_order_count: 2 } } }),
    listSalesPurchaseLinks: async () => ({ data: { data: [], total: 0 } }),
    listSalesPurchaseCandidates: async (id, params) => { calls.push({ action: 'candidates', id, params }); return { data: { data: [], total: 0 } } },
    listSalesPurchaseCategories: async () => ({ data: { data: [{ id: 1, parent_id: null, category_name: '原材料' }, { id: 2, parent_id: 1, category_name: '板材' }] } }),
    addSalesPurchaseLink: async (id, data) => { calls.push({ action: 'add', id, data }) },
    reverseSalesPurchaseLink: async (id, linkId, data) => { calls.push({ action: 'reverse', id, linkId, data }) },
    ...overrides
  }
  const options = new Function(...Object.keys(dependencies), script(panelSource))(...Object.values(dependencies))
  const vm = { ...options.data(), orderId: 11, $can: () => true, $message: Object.fromEntries(['success', 'error', 'warning'].map(name => [name, text => messages.push({ name, text })])), $router: { push: route => routes.push(route) } }
  Object.entries(options.methods).forEach(([name, method]) => { vm[name] = method.bind(vm) })
  Object.entries(options.computed).forEach(([name, get]) => Object.defineProperty(vm, name, { get: () => get.call(vm) }))
  return { vm, calls, messages, routes, options }
}

test('销售采购关联与订单详情、列表、表单、变更模板可编译', () => {
  for (const source of [panelSource, detailSource, formSource, listSource, changeSource]) assert.deepEqual(compiler.compile(compiler.parseComponent(source).template.content).errors, [])
})

test('销售订单界面没有采购金额、成本、利润、承运费用及采购资金入口', () => {
  for (const source of [panelSource, detailSource, formSource, listSource, changeSource]) {
    const template = compiler.parseComponent(source).template.content
    assert.doesNotMatch(template, /cost_facts|procurement_by_currency|source_contract_amount|carrier_fee|actual_freight|成本|利润|采购合同金额|关联采购的付款退款|预估运费|预估快递费/)
  }
  assert.doesNotMatch(panelSource, /listSalesPurchasePayments|openCash|paymentPlanText|row\.contract_amount|row\.amount/)
  assert.match(panelSource, /overview\.settlement\.contract_amount/)
  assert.match(panelSource, /overview\.settlement\.net_received_amount/)
  assert.match(panelSource, /overview\.settlement\.outstanding_amount/)
  assert.match(detailSource, /label="采购关联"/)
  const snapshotFields = [...panelSource.matchAll(/source_snapshot\.([a-z_]+)/g)].map(match => match[1])
  assert.ok(snapshotFields.length)
  assert.ok(snapshotFields.every(field => ['order_no', 'supplier_name', 'item_code', 'item_name', 'spec_model', 'purchase_unit_name'].includes(field)))
})

test('采购关联面板支持不含采购金额的新契约，只读取销售结算与数量关联', async () => {
  let paymentReads = 0
  const links = [{ id: 1, purchase_qty: '3.00000000', source_snapshot: { order_no: 'PO-1', item_name: '板材', purchase_unit_name: '张' }, status: 'active' }]
  const { vm } = makePanel({
    listSalesPurchasePayments: async () => { paymentReads++; throw new Error('销售订单禁止读取采购资金') },
    listSalesPurchaseLinks: async () => ({ data: { data: links, total: 1 } }),
  })
  await vm.load(); await vm.changeLinkPage(2)
  assert.equal(paymentReads, 0); assert.equal(vm.error, ''); assert.equal(vm.overview.settlement.contract_amount, '1000.0000')
  assert.deepEqual(vm.links, links); assert.equal(vm.linkTotal, 1); assert.equal(vm.openCash, undefined)
})

test('采购选择翻页保留选择且提交服务端分页与真实分类', async () => {
  const { vm, calls } = makePanel()
  await vm.openPicker()
  vm.selectCandidate({ id: 25, item_name: '板材', order_no: 'PO-2', available_purchase_qty: '5', purchase_unit_name: '张' })
  vm.linkForm.purchase_qty = '2'
  await vm.changeCandidatePage(3)
  assert.equal(vm.selected.id, 25)
  assert.equal(vm.linkForm.purchase_qty, '2')
  assert.equal(calls.at(-1).params.page, 3)
  assert.equal(calls.at(-1).params.per_page, 10)
  await vm.chooseCategory({ id: 2 })
  assert.equal(calls.at(-1).params.category_id, 2)
  assert.equal(calls.at(-1).params.page, 1)
  assert.equal(vm.categoryTree[0].children[0].category_name, '板材')
})

test('重新打开选择器完全重置数量说明和选择', async () => {
  const { vm } = makePanel()
  await vm.openPicker()
  vm.selected = { id: 1 }; vm.linkForm.purchase_qty = '99'; vm.linkForm.reason = '旧说明'; vm.candidatePage = 5
  await vm.openPicker()
  assert.equal(vm.selected, null)
  assert.equal(vm.linkForm.purchase_qty, '')
  assert.equal(vm.linkForm.reason, '')
  assert.equal(vm.candidatePage, 1)
})

test('关联保存校验数量原因，按真实明细ID和版本提交，重试沿用幂等键', async () => {
  const { vm, calls } = makePanel()
  await vm.load(); await vm.openPicker()
  vm.selectCandidate({ id: 25, available_purchase_qty: '5' })
  vm.linkForm.purchase_qty = '6'; vm.linkForm.reason = '用途'
  await vm.saveLink(); assert.equal(calls.filter(call => call.action === 'add').length, 0)
  vm.linkForm.purchase_qty = '2'; vm.linkForm.reason = '  '
  await vm.saveLink(); assert.equal(calls.filter(call => call.action === 'add').length, 0)
  vm.linkForm.reason = '设备A第一批'
  const key = vm.linkForm.idempotency_key
  await vm.saveLink()
  const request = calls.find(call => call.action === 'add')
  assert.equal(request.data.purchase_order_item_id, 25)
  assert.equal(request.data.version, 3)
  assert.equal(request.data.purchase_qty, '2')
  assert.equal(request.data.idempotency_key, key)
  assert.equal(vm.pickerVisible, false)
})

test('失败时保留待提交内容与幂等键，已关闭或取消订单状态不会被混同', async () => {
  const { vm } = makePanel({ addSalesPurchaseLink: async () => { throw { userMessage: '网络超时' } } })
  await vm.load(); await vm.openPicker(); vm.selectCandidate({ id: 25, available_purchase_qty: '5' })
  vm.linkForm.purchase_qty = '2'; vm.linkForm.reason = '设备A'; const key = vm.linkForm.idempotency_key
  await vm.saveLink()
  assert.equal(vm.pickerVisible, true); assert.equal(vm.linkForm.idempotency_key, key); assert.equal(vm.busy, false)
  assert.equal(vm.linkConflict, false)
  vm.overview.order_status = 'cancelled'; assert.equal(vm.canAdd, false)
  vm.overview.order_status = 'draft'; assert.equal(vm.canAdd, false)
  vm.overview.order_status = 'closed'; assert.equal(vm.canAdd, true)
})

test('版本冲突在弹窗中保留输入并阻止保存，精确刷新所选明细后使用新版本同一幂等键重试', async () => {
  const writes = [], reads = []
  let version = 3
  const { vm } = makePanel({
    getSalesOrderFinance: async () => ({ data: { data: { version, order_status: 'confirmed' } } }),
    listSalesPurchaseCandidates: async (id, params) => { reads.push({ id, params }); return { data: { data: params.purchase_order_item_id ? [{ id: 25, order_no: 'PO-2', item_name: '板材', available_purchase_qty: '3' }] : [], total: params.purchase_order_item_id ? 1 : 0 } } },
    addSalesPurchaseLink: async (id, data) => { writes.push({ id, data }); if (writes.length === 1) throw { response: { status: 422, data: { errors: { version: ['订单采购关联已被修改'] } } } } },
  })
  await vm.load(); await vm.openPicker(); vm.selectCandidate({ id: 25, available_purchase_qty: '5' })
  vm.linkForm.purchase_qty = '2'; vm.linkForm.reason = '设备A第一批'; const input = { ...vm.linkForm }
  vm.candidateFilters = { keyword: '其他物料', category_id: 3 }; vm.candidatePage = 8
  await vm.saveLink(); await vm.saveLink()
  assert.equal(writes.length, 1); assert.equal(vm.linkConflict, true); assert.deepEqual(vm.linkForm, input)
  version = 4; await vm.refreshLinkContext()
  assert.deepEqual(reads.at(-1), { id: 11, params: { purchase_order_item_id: 25, page: 1, per_page: 1 } })
  assert.equal(vm.overview.version, 4); assert.equal(vm.selected.available_purchase_qty, '3'); assert.equal(vm.candidatePage, 8)
  assert.deepEqual(vm.linkForm, input); assert.equal(vm.linkConflict, false); assert.equal(vm.linkError, '')
  await vm.saveLink()
  assert.equal(writes.length, 2); assert.equal(writes[1].data.version, 4); assert.equal(writes[1].data.idempotency_key, input.idempotency_key)
})

test('数量冲突刷新后保留原输入，并按最新可用数量阻止超额再保存', async () => {
  let writes = 0
  const { vm, messages } = makePanel({
    listSalesPurchaseCandidates: async (id, params) => ({ data: { data: params.purchase_order_item_id ? [{ id: 25, available_purchase_qty: '1' }] : [], total: 1 } }),
    addSalesPurchaseLink: async () => { writes++; if (writes === 1) throw { response: { status: 422, data: { errors: { purchase_qty: ['未归属数量已变化'] } } } } },
  })
  await vm.load(); await vm.openPicker(); vm.selectCandidate({ id: 25, available_purchase_qty: '5' })
  vm.linkForm.purchase_qty = '2'; vm.linkForm.reason = '设备A'; const key = vm.linkForm.idempotency_key
  await vm.saveLink(); assert.equal(vm.linkConflict, true)
  await vm.refreshLinkContext(); assert.equal(vm.linkForm.purchase_qty, '2'); assert.equal(vm.selected.available_purchase_qty, '1')
  await vm.saveLink(); assert.equal(writes, 1); assert.match(messages.at(-1).text, /不超过/)
  vm.linkForm.purchase_qty = '1'; await vm.saveLink(); assert.equal(writes, 2); assert.equal(vm.linkForm.idempotency_key, key)
})

test('冲突刷新失败时保留旧输入和冲突锁，不允许半更新的数据提交', async () => {
  let writes = 0
  const { vm } = makePanel({
    getSalesOrderFinance: async () => ({ data: { data: { version: 8, order_status: 'confirmed' } } }),
    listSalesPurchaseCandidates: async () => { throw { userMessage: '采购资料暂不可用' } },
    addSalesPurchaseLink: async () => { writes++ },
  })
  vm.overview = { version: 3 }; vm.pickerVisible = true; vm.selected = { id: 25, available_purchase_qty: '5' }
  vm.linkForm = { purchase_qty: '2', reason: '设备A', idempotency_key: 'same-key' }; vm.linkConflict = true
  await vm.refreshLinkContext(); await vm.saveLink()
  assert.equal(vm.overview.version, 3); assert.equal(vm.linkConflict, true); assert.equal(vm.busy, false); assert.equal(vm.refreshingLink, false)
  assert.match(vm.linkError, /暂不可用/); assert.deepEqual(vm.linkForm, { purchase_qty: '2', reason: '设备A', idempotency_key: 'same-key' }); assert.equal(writes, 0)
})

test('原采购明细失效后继续锁定保存，重新选明细并成功刷新才解锁', async () => {
  const { vm } = makePanel({
    listSalesPurchaseCandidates: async (id, params) => ({ data: { data: params.purchase_order_item_id === 26 ? [{ id: 26, available_purchase_qty: '4' }] : [], total: 0 } }),
  })
  vm.pickerVisible = true; vm.selected = { id: 25, available_purchase_qty: '5' }; vm.linkConflict = true
  vm.linkForm = { purchase_qty: '2', reason: '设备A', idempotency_key: 'same-key' }
  await vm.refreshLinkContext(); assert.equal(vm.linkConflict, true); assert.match(vm.linkError, /已不可关联/); assert.equal(vm.selected.id, 25)
  vm.selectCandidate({ id: 26, available_purchase_qty: '4' }); vm.linkForm.purchase_qty = '2'
  await vm.refreshLinkContext(); assert.equal(vm.linkConflict, false); assert.equal(vm.selected.id, 26); assert.equal(vm.linkForm.idempotency_key, 'same-key')
})

test('刷新期间阻止更改选择和分页，迟到的候选分页不能覆盖恢复后的余额', async () => {
  let resolveOld, resolveFresh
  const { vm } = makePanel({ listSalesPurchaseCandidates: async (id, params) => params.purchase_order_item_id
    ? new Promise(resolve => { resolveFresh = resolve })
    : new Promise(resolve => { resolveOld = resolve }) })
  vm.pickerVisible = true; vm.selected = { id: 25, available_purchase_qty: '5' }; vm.linkConflict = true; vm.candidates = [{ id: 25, available_purchase_qty: '5' }]
  const old = vm.loadCandidates(); const refresh = vm.refreshLinkContext()
  assert.equal(vm.busy, true); await vm.changeCandidatePage(3); vm.selectCandidate({ id: 26, available_purchase_qty: '9' })
  assert.equal(vm.candidatePage, 1); assert.equal(vm.selected.id, 25)
  resolveFresh({ data: { data: [{ id: 25, available_purchase_qty: '1' }], total: 1 } }); await refresh
  resolveOld({ data: { data: [{ id: 25, available_purchase_qty: '5' }], total: 1 } }); await old
  assert.equal(vm.selected.available_purchase_qty, '1'); assert.equal(vm.candidates[0].available_purchase_qty, '1'); assert.equal(vm.candidateLoading, false)
})

test('切换销售订单后恢复请求的迟到结果不能修改新订单状态', async () => {
  let resolveOverview
  const { vm } = makePanel({
    getSalesOrderFinance: async () => new Promise(resolve => { resolveOverview = resolve }),
    listSalesPurchaseCandidates: async () => ({ data: { data: [{ id: 25, available_purchase_qty: '1' }], total: 1 } }),
  })
  vm.pickerVisible = true; vm.selected = { id: 25, available_purchase_qty: '5' }; vm.linkConflict = true
  const refresh = vm.refreshLinkContext()
  vm.orderId = 12; vm.overview = { version: 12 }; vm.selected = { id: 26, available_purchase_qty: '4' }; vm.linkRecoveryRequest++
  resolveOverview({ data: { data: { version: 4, order_status: 'confirmed' } } }); await refresh
  assert.equal(vm.overview.version, 12); assert.equal(vm.selected.id, 26); assert.equal(vm.selected.available_purchase_qty, '4')
})

test('较慢的旧请求不能覆盖后翻页的候选列表', async () => {
  let resolveFirst
  const { vm } = makePanel({ listSalesPurchaseCandidates: async (id, params) => params.page === 1 ? new Promise(resolve => { resolveFirst = resolve }) : { data: { data: [{ id: 2 }], total: 2 } } })
  const first = vm.loadCandidates(); await vm.changeCandidatePage(2)
  resolveFirst({ data: { data: [{ id: 1 }], total: 2 } }); await first
  assert.equal(vm.candidates[0].id, 2)
})

test('撤销数量关联必须填写原因并保留版本', async () => {
  const { vm, calls } = makePanel()
  await vm.load()
  vm.$prompt = async (text, title, options) => { assert.notEqual(options.inputValidator(''), true); return { value: '用途变更' } }
  await vm.reverse({ id: 8 })
  assert.deepEqual(calls.find(call => call.action === 'reverse'), { action: 'reverse', id: 11, linkId: 8, data: { version: 3, reason: '用途变更' } })
  assert.equal(vm.money(null), '待确认')
})

function makeOrderForm(overrides = {}) {
  const requests = []
  const dependencies = {
    cachedPageRoute: {}, ProductSkuPicker: {}, CustomerPicker: {}, PurchaseItemPicker: {}, SalesOrderAttachmentPreviewDialog: {}, OrderEditImpactDialog: {}, legacyMediaUrl: value => value,
    saveSalesOrder: async payload => { requests.push(['draft', payload]); return { data: { data: { id: 17 } } } },
    previewSalesOrderEditImpact: async (id, payload) => { requests.push(['preview', payload]); return { data: { data: { change_count: 1 } } } },
    submitSalesOrderEditImpact: async (id, payload) => { requests.push(['submit', payload]); return { data: { message: '已保存' } } },
    ...overrides,
  }
  const options = new Function(...Object.keys(dependencies), script(formSource))(...Object.values(dependencies))
  const vm = { ...options.data(), pageRoute: { params: { id: 17 }, path: '/sales/orders/17/edit' }, isEdit: true, isConfirmedEdit: false, isPageRouteActive: true, contractFiles: [], $message: { success() {}, warning() {}, error(message) { throw new Error(message) }, info() {} }, $router: { push() {}, replace() {} } }
  Object.entries(options.methods).forEach(([name, method]) => { vm[name] = method.bind(vm) })
  vm.syncHeaderFlags = () => {}; vm.lineCutMessage = () => ''; vm.applyImpactPreview = () => {}
  return { vm, requests }
}

function assertNoInternalFreight(payload) {
  for (const record of [payload, payload.shipping_snapshot, payload.logistics_snapshot]) {
    assert.equal(Object.hasOwn(record, 'carrier_fee'), false)
    assert.equal(Object.hasOwn(record, 'actual_freight'), false)
    assert.equal(Object.hasOwn(record, 'actual_freight_amount'), false)
  }
}

test('订单加载不生成或保留承运成本输入，客户应收运费和物流资料继续保留', async () => {
  const legacy = { id: 17, carrier_fee: '91', freight_amount: '38', shipping_snapshot: { carrier_fee: '91', actual_freight: '82', actual_freight_amount: '82', customer_logistics_note: '客户指定送货' }, logistics_snapshot: { actual_freight: '82', carrier_fee: '91', express_no: 'EX-7' }, lines: [] }
  const { vm } = makeOrderForm({ getSalesOrder: async () => ({ data: legacy }) })
  assert.equal(Object.hasOwn(vm.form, 'carrier_fee'), false)
  vm.form.carrier_fee = 0
  await vm.load()
  assertNoInternalFreight(vm.form); assertNoInternalFreight(vm.editOrderMeta)
  assert.equal(vm.form.freight_amount, '38'); assert.equal(vm.form.shipping_snapshot.customer_logistics_note, '客户指定送货'); assert.equal(vm.form.logistics_snapshot.express_no, 'EX-7')
  assert.equal(legacy.carrier_fee, '91'); assert.equal(legacy.shipping_snapshot.actual_freight, '82')
})

test('保存草稿与正式订单变更不发送承运成本或零值，仍提交客户应收运费', async () => {
  for (const confirmed of [false, true]) {
    const { vm, requests } = makeOrderForm()
    vm.isConfirmedEdit = confirmed
    Object.assign(vm.form, { id: 17, customer_id: 1, sales_user_legacy_id: 2, platform: 'web', payment_method_id: 3, carrier_id: 5, carrier_fee: 0, actual_freight: '91', actual_freight_amount: '92', freight_amount: '38', shipping_snapshot: { carrier_fee: 0, actual_freight: '91', customer_logistics_note: '客户指定送货' }, logistics_snapshot: { carrier_fee: '92', actual_freight_amount: '93', express_no: 'EX-7' }, lines: [{ sku_id: 4, product_id: 6, order_qty: 2, unit_price: 100 }] })
    await vm.save(false)
    if (confirmed) await vm.submitImpactPreview()
    assert.equal(requests.length, confirmed ? 2 : 1)
    for (const [, payload] of requests) {
      assertNoInternalFreight(payload)
      assert.equal(payload.freight_amount, '38'); assert.equal(payload.shipping_snapshot.customer_logistics_note, '客户指定送货'); assert.equal(payload.logistics_snapshot.express_no, 'EX-7'); assert.equal(payload.lines[0].unit_price, 100)
    }
    assert.equal(vm.form.carrier_fee, 0)
  }
})
