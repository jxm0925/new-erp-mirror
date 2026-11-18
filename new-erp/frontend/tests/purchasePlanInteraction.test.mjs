import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import * as purchaseScope from '../src/utils/purchaseManagementScope.mjs'
import * as purchaseAmounts from '../src/utils/purchaseLineAmount.js'
import { roundInvoiceAmount, sumInvoiceAmounts } from '../src/utils/financeInvoiceAmount.js'

const allocationSource = await readFile(new URL('../src/utils/purchasePlanAllocation.js', import.meta.url), 'utf8')
const allocation = await import(`data:text/javascript;base64,${Buffer.from(allocationSource).toString('base64')}`)
const dateTimeSource = await readFile(new URL('../src/utils/dateTime.js', import.meta.url), 'utf8')
const dateTime = await import(`data:text/javascript;base64,${Buffer.from(dateTimeSource).toString('base64')}`)

// 调用页面自身的方法和计算属性；仅替换 HTTP、Element UI 提示和组件引用。
async function page(file, dependencies = {}) {
  const source = await readFile(new URL(`../src/views/erp/purchase/${file}.vue`, import.meta.url), 'utf8')
  const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/import\s+(?:\{[\s\S]*?\}|[^{};\n]+)\s+from\s+['"][^'"]+['"];?/g, '')
    .replace(/^import .*$/gm, '')
    .replace('export default', 'return')
  const deps = { ...purchaseScope, ...allocation, ...dateTime, ...purchaseAmounts, roundInvoiceAmount, sumInvoiceAmounts, PagedRecordSelect: {}, ReceiptPhysicalEntries: {}, PurchaseItemPicker: {}, PurchaseAttachmentPanel: {}, PurchaseConversionFacts: {}, listPurchaseSalesCandidates: () => {}, listPurchaseWorkOrderCandidates: () => {}, ...dependencies }
  const options = new Function(...Object.keys(deps), script)(...Object.values(deps))
  const messages = []
  const vm = { ...options.data.call({ $route: { query: {} } }), type: 'plan', $refs: {},
    $set: (row, key, value) => { row[key] = value }, $delete: (row, key) => { delete row[key] },
    $message: Object.fromEntries(['success', 'warning', 'info', 'error'].map(level => [level, message => messages.push({ level, message })])) }
  Object.assign(vm, options.methods)
  Object.entries(options.computed || {}).forEach(([key, get]) => Object.defineProperty(vm, key, { get: () => get.call(vm) }))
  return { vm, messages }
}

test('采购订单按原单价算行小计，按分拆税，成交总额可以反算单价', async () => {
  const { vm } = await page('PurchaseDocumentForm')
  vm.type = 'order'
  vm.$route = { params: {} }
  vm.form.tax_mode = 'tax_included'
  const examples = [
    { rows: [[100, 0.2808, 28.08], [750, 0.4212, 315.9], [100, 1.1, 110]], total: 453.98, net: '401.76', tax: '52.22' },
    { rows: [[216, 2.106, 454.9], [100, 0.2808, 28.08], [180, 2.07033333, 372.66], [20, 4.83, 96.60]], total: 952.24, net: '842.70', tax: '109.54' }
  ]
  for (const { rows, total, net, tax } of examples) {
    vm.form.items = rows.map(([qty, unit_price, amount]) => {
      const line = { qty, unit_price, tax_rate: 13 }
      vm.handleOrderPriceChange(line)
      assert.equal(line.amount, amount.toFixed(2))
      vm.handleOrderAmountChange(line)
      assert.equal(line.unit_price, String(unit_price))
      return line
    })
    assert.equal(Number(vm.totalAmount), total)
    assert.equal(vm.untaxedAmount, net)
    assert.equal(vm.taxAmount, tax)
    assert.equal(Number(sumInvoiceAmounts([vm.untaxedAmount, vm.taxAmount])), total)
    assert.deepEqual(vm.payload().items.map(row => [row.unit_price, row.amount]), rows.map(row => [String(row[1]), row[2].toFixed(2)]))
  }
  const line = { qty: 180, amount: 372.66 }
  vm.handleOrderAmountChange(line)
  assert.equal(line.unit_price, '2.07033333')
  assert.equal(purchaseAmounts.purchaseLineAmount(3, 0.335), '1.01')
  assert.equal(purchaseAmounts.purchaseLineAmount(3, 1.005), '3.02')
  assert.equal(purchaseAmounts.purchaseLineAmount(100, '.2808'), '28.08')
  const indivisible = { qty: 3000000, amount: 1 }
  vm.handleOrderAmountChange(indivisible)
  assert.equal(indivisible.unit_price, '0.00000033')
  assert.equal(vm.dealAmount(indivisible), '1.00')
  vm.form.items = [{ qty: 100000000, unit_price: 0.00000001, amount: 1 }]
  assert.equal(vm.payload().items[0].unit_price, '0.00000001')
  assert.equal(purchaseAmounts.purchaseShare(0.01, 1, 2), '0.01')
  vm.form.items = [{ qty: 100000, unit_price: '1234567890.12345678', tax_rate: 13 }]
  vm.handleOrderPriceChange(vm.form.items[0])
  assert.equal(vm.totalAmount, '123456789012345.68')
  assert.equal(vm.payload().items[0].amount, '123456789012345.68')
  assert.equal(purchaseAmounts.formatPurchasePrice('1234567890.12345678'), '1,234,567,890.12345678')
  assert.equal(purchaseAmounts.purchaseUnitPrice('100000000', '123456789012345678.00'), '1234567890.12345678')
})

test('独立到货草稿保留原报价，调整行金额后回填单价', async () => {
  const { vm } = await page('PurchaseReceiptForm')
  const line = vm.normalizeLine({ receipt_qty: 216, unit_price: '2.10600000', receipt_cost: '454.90', tax_rate: 13 })
  assert.equal(line.unit_price, '2.106')
  assert.equal(vm.lineAmount(line), '454.90')
  line.qty = 180
  line.receipt_cost = 372.66
  vm.updateReceiptPrice(line)
  assert.equal(line.unit_price, '2.07033333')
  assert.equal(vm.lineAmount(line), '372.66')
  line.unit_price = 0.4212
  vm.updateReceiptAmount(line)
  assert.equal(vm.lineAmount(line), '75.82')
})

test('供应商阶梯报价按输入精度展示、换算并提交', async () => {
  let submitted
  const { vm } = await page('../master/SupplierList', {
    pagedScroll: {}, ItemCategoryTreePicker: {}, createPageState: () => ({}),
    saveSupplierQuotation: async (id, payload) => { submitted = payload }
  })
  vm.selectedSupplier = { id: 7 }
  vm.quoteConversions = [{ purchase_unit_id: 1, factor: '1.00000000' }]
  vm.quoteForm = { unit_id: 1, price: '1234567890.12345678' }
  vm.quoteDates = ['2026-01-01', '2026-12-31']
  vm.loadQuotations = async () => {}
  let validation
  vm.$refs.quoteForm = { validate: callback => { validation = callback(true) } }
  assert.equal(vm.quoteBaseUnitPrice, '1,234,567,890.12345678')
  assert.equal(vm.purchasePrice('2.10600000'), '2.106')
  vm.saveQuote()
  await validation
  assert.equal(submitted.price, '1234567890.12345678')
})

test('确认原物料保留手工规格、供应商、行ID和换算快照', async () => {
  const { vm } = await page('PurchaseDocumentForm')
  const split = { supplier_id: 8, purchase_quantity: 2 }
  const snapshot = { purchase_qty: 2 }
  const line = { id: 51, item_id: 34, spec_model: '采购员确认规格', purchase_unit_id: 6, purchase_conversion_snapshot: snapshot, splits: [split] }
  vm.form.items = [line]
  vm.pickerTarget = line
  vm.initializeMissingPlanningLines = async () => {}
  await vm.applyPickedItem({ id: 34, management_scope: 'factory', spec_model: 'DN20' })
  assert.equal(line.id, 51)
  assert.equal(line.spec_model, '采购员确认规格')
  assert.equal(line.purchase_conversion_snapshot, snapshot)
  assert.equal(line.splits[0], split)
})

test('换成另一物料清除旧供应商和换算事实，新增其他物料可继续分配', async () => {
  const { vm } = await page('PurchaseDocumentForm')
  const line = { id: 51, item_id: 34, purchase_unit_id: 6, _planningRevision: 2, _planningPreview: { planned_base_qty: 12 }, purchase_conversion_snapshot: {}, splits: [{ supplier_id: 8 }] }
  vm.form.items = [line]
  vm.pickerTarget = line
  vm.initializeMissingPlanningLines = async () => {}
  vm.loadLineConversions = async () => {}
  await vm.applyPickedItem({ id: 35, management_scope: 'factory', unit_id: 2, spec_model: 'DN25' })
  await vm.applyPickedMultipleItems([{ id: 36, management_scope: 'factory', unit_id: 2 }])
  assert.equal(line.item_id, 35)
  assert.equal(line.id, undefined)
  assert.equal(line.purchase_conversion_snapshot, undefined)
  assert.equal(line._planningPreview, undefined)
  assert.equal(line._planningRevision, 3)
  assert.deepEqual(line.splits, [])
  assert.deepEqual(vm.form.items[1].splits, [])
  assert.ok(vm.form.items[1]._rowKey)
})

test('上一物料推荐迟到不能覆盖当前物料候选', async () => {
  const requests = []
  const { vm } = await page('PurchaseDocumentForm', { getSupplierRecommendations: () => new Promise(resolve => requests.push(resolve)) })
  vm.form.items = [{ item_id: 34, purchase_quantity: 2, purchase_unit_id: 6 }, { item_id: 35, purchase_quantity: 1, purchase_unit_id: 6 }]
  const first = vm.loadRecommendations()
  vm.activeIndex = 1
  const second = vm.loadRecommendations()
  requests[1]({ data: { data: { candidates: [{ supplier_id: 2 }] } } })
  await second
  requests[0]({ data: { data: { candidates: [{ supplier_id: 1 }] } } })
  await first
  assert.deepEqual(vm.recommendations, [{ supplier_id: 2 }])
  assert.equal(vm.recommendLoading, false)
  vm.activeIndex = 2
  vm.recommendLoading = true
  await vm.loadRecommendations()
  assert.equal(vm.recommendLoading, false)
  assert.deepEqual(vm.recommendations, [])
})

test('选用推荐复用空白行，新报价不被历史快照覆盖', async () => {
  const { vm } = await page('PurchaseDocumentForm')
  const split = { supplier_id: null, purchase_unit_id: 6, purchase_quantity: 2, purchase_unit_price: 0, purchase_conversion_snapshot: { purchase_unit_price: 8 } }
  const line = { item_id: 34, purchase_unit_id: 6, splits: [split] }
  let recalculated = null
  vm.refreshPlanning = (row, selected) => { recalculated = selected }
  vm.chooseRecommendationForLine({ auto_selectable: true, supplier_id: 8, supplier_name: '供方', comparable_price: 12 }, line)
  assert.equal(line.splits.length, 1)
  assert.equal(split.supplier_id, 8)
  assert.equal(split.purchase_unit_price, '12')
  assert.equal(recalculated, split)
})

test('选用同单位报价保留票面单价，库存基本单位可比价按包装换算', async () => {
  const { vm } = await page('PurchaseDocumentForm')
  const line = { purchase_unit_id: 6, _planningPreview: { conversion_factor_snapshot: '12.00000000' } }
  const candidate = { quote_unit_id: 6, currency: 'CNY', tax_mode: 'tax_included', quote_price: '2.07033333', comparable_price: '0.17252778' }
  assert.equal(vm.recommendedUnitPrice(candidate, line), '2.07033333')
  assert.equal(vm.recommendedUnitPrice({ ...candidate, quote_unit_id: 2 }, line), '2.07033336')
})

test('推荐报价单位不同不能覆盖分配行单价', async () => {
  const { vm, messages } = await page('PurchaseDocumentForm')
  const split = { supplier_id: 8, purchase_unit_id: 2, purchase_unit_price: 2 }
  vm.chooseRecommendationForLine({ auto_selectable: true, supplier_id: 8, comparable_price: 12 }, { purchase_unit_id: 6, splits: [split] })
  assert.equal(split.purchase_unit_price, 2)
  assert.equal(messages[0].level, 'warning')
})

test('空物料及未选供应商的分配在保存接口之前被拦截', async () => {
  const { vm, messages } = await page('PurchaseDocumentForm', { savePurchasePlan: () => { throw new Error('不得调用保存接口') } })
  vm.form.items = []
  await vm.save(false)
  assert.match(messages.at(-1).message, /至少添加/)
  vm.form.items = [{ item_id: 34, item: { id: 34, management_scope: 'factory' }, splits: [{ supplier_id: null }] }]
  await vm.save(false)
  assert.match(messages.at(-1).message, /选择供应商/)
})

test('物料单位未加载完不能生成小数分配，换算完成后按完整采购量初始化', async () => {
  const { vm, messages } = await page('PurchaseDocumentForm')
  const line = { item_id: 34, purchase_quantity: 2, splits: [] }
  vm.form.items = [line]
  vm.openSupplierSplitDialog(line, 0)
  assert.equal(vm.supplierDialogVisible, false)
  assert.deepEqual(line.splits, [])
  assert.match(messages.at(-1).message, /尚未计算完成/)
  line.purchase_unit_id = 6
  line._planningPreview = { planned_base_qty: 12, conversion_factor_snapshot: 6, purchase_decimal_places: 0 }
  vm.initializeSplit = () => {}
  vm.loadRecommendations = () => {}
  vm.openSupplierSplitDialog(line, 0)
  assert.equal(vm.supplierDialogVisible, true)
  assert.equal(line.splits[0].purchase_quantity, 2)
  assert.equal(line.splits[0].purchase_unit_id, 6)
})

test('两条拆分属于同一订单时只统计一张，根和米分别汇总', async () => {
  const { vm } = await page('PurchasePlanDetail')
  const order = { id: 7, purchase_order_no: 'PO7' }
  vm.plan = { items: [{ splits: [
    { order, purchase_qty: 3, amount: 24, purchase_conversion_snapshot: { purchase_qty: 2, purchase_unit_name_snapshot: '根' } },
    { order, purchase_qty: 6, amount: 12, purchase_conversion_snapshot: { purchase_qty: 6, purchase_unit_name_snapshot: '米' } }
  ] }] }
  assert.equal(vm.generatedOrders.length, 1)
  assert.equal(vm.orderCards.length, 1)
  assert.equal(vm.orderCards[0].qty, '2 根；6 米')
  assert.equal(vm.orderCards[0].amount, '36.00')
  assert.equal(vm.orderCards[0].lines, 2)
  vm.plan = { items: [] }
  vm.preview = [{ supplier_id: 8, line_count: 1, total_qty: 3, items: [{ purchase_qty: 3, conversion_snapshot: { purchase_qty: 2, purchase_unit_name_snapshot: '根' } }] }]
  assert.equal(vm.orderCards[0].qty, '2 根')
})

test('未分配供应商的行在计划主页面被准确识别，且统计未分配物料行数', async () => {
  const { vm } = await page('PurchaseDocumentForm')
  const unassignedLine = { item_id: 34, purchase_quantity: 10, splits: [{ supplier_id: null, purchase_quantity: 10 }] }
  const assignedLine = { item_id: 35, purchase_quantity: 5, splits: [{ supplier_id: 9, purchase_quantity: 5 }] }
  const emptySplitLine = { item_id: 36, purchase_quantity: 1, splits: [] }

  assert.equal(vm.hasAllocatedSupplier(unassignedLine), false)
  assert.equal(vm.hasAllocatedSupplier(emptySplitLine), false)
  assert.equal(vm.hasAllocatedSupplier(assignedLine), true)

  vm.form.items = [unassignedLine, assignedLine, emptySplitLine]
  assert.equal(vm.unallocatedSupplierItemsCount, 2)
  assert.equal(vm.uniqueAllocatedSuppliersCount, 1)
})

test('微调数量已有换算因子时避免 _planningPending 抖动', async () => {
  let previewCalled = false
  const { vm } = await page('PurchaseDocumentForm', {
    previewPurchaseConversion: async () => {
      previewCalled = true
      return { data: { data: { purchase_unit_id: 6, conversion_factor_snapshot: '6', planned_base_qty: 60 } } }
    }
  })
  const line = { item_id: 34, purchase_unit_id: 6, purchase_quantity: 10, _planningPreview: { purchase_unit_id: 6, conversion_factor_snapshot: '6', planned_base_qty: 60 } }
  const split = { purchase_unit_id: 6, purchase_quantity: 5, _planningPreview: { purchase_unit_id: 6, conversion_factor_snapshot: '6', planned_base_qty: 30 } }

  // 数量变动时，不应设置 _planningPending 为 true
  await vm.refreshPlanning(line, split)
  assert.equal(split._planningPending, false)
  assert.equal(previewCalled, true)
})

test('计划看板对于未分配、部分分配和全部分配返回正确的状态和标签', async () => {
  const { vm } = await page('PurchaseBoard')
  const unassignedPlan = { items: [{ splits: [{ supplier_id: null }] }, { splits: [] }] }
  const partialPlan = { items: [{ splits: [{ supplier_id: 1 }] }, { splits: [{ supplier_id: null }] }] }
  const fullPlan = { items: [{ splits: [{ supplier_id: 1 }] }, { splits: [{ supplier_id: 2 }] }] }

  const unassignedInfo = vm.planAllocationInfo(unassignedPlan)
  assert.equal(unassignedInfo.type, 'danger')
  assert.equal(unassignedInfo.label, '未分配供应商')

  const partialInfo = vm.planAllocationInfo(partialPlan)
  assert.equal(partialInfo.type, 'warning')
  assert.equal(partialInfo.label, '部分已配 (1/2)')

  const fullInfo = vm.planAllocationInfo(fullPlan)
  assert.equal(fullInfo.type, 'success')
  assert.equal(fullInfo.label, '已全部分配 (2家)')
})
