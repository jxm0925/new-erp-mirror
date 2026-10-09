import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const source = await readFile(new URL('../src/views/erp/inventory/InventoryBoard.vue', import.meta.url), 'utf8')

function mount() {
  const calls = { post: [], repair: [], reload: [] }
  const messages = []
  const bindings = {
    cachedPageRoute: {},
    postPostingReceipt: async (...args) => { calls.post.push(args); return { data: {} } },
    repairPostingReceiptAllocations: async (...args) => { calls.repair.push(args); return { data: {} } }
  }
  const script = compiler.parseComponent(source).script.content
    .replace(/import[\s\S]*?from ['"][^'"]+['"]\s*/g, '')
    .replace('export default', 'return')
  const options = new Function(...Object.keys(bindings), script)(...Object.values(bindings))
  const vm = {
    $route: { params: {}, query: {}, path: '/inventory/posting' },
    $refs: {},
    $can: () => true,
    $set: (row, key, value) => { row[key] = value },
    $delete: (row, key) => { delete row[key] },
    $message: Object.fromEntries(['success', 'warning', 'info', 'error'].map(level => [level, value => messages.push({ level, value })]))
  }
  Object.entries(options.methods || {}).forEach(([key, method]) => { vm[key] = method.bind(vm) })
  Object.assign(vm, options.data.call(vm))
  Object.entries(options.computed || {}).forEach(([key, value]) => {
    const descriptor = typeof value === 'function'
      ? { get: value.bind(vm) }
      : { get: value.get.bind(vm), set: value.set?.bind(vm) }
    Object.defineProperty(vm, key, descriptor)
  })
  for (const method of ['loadPostingRows', 'loadBalances', 'loadTransactions']) {
    vm[method] = async () => { calls.reload.push(method) }
  }
  return { vm, calls, messages }
}

function stockLine(id = 101, overrides = {}) {
  return {
    id, item_id: id + 1000,
    item: { item_code: `OFFICE-${id}`, item_name: '办公耗材', management_scope: 'office', is_stock_item: true, serial_tracking_mode: 'none', unit: { unit_name: '个' } },
    management_scope_snapshot: 'office', is_stock_item_snapshot: true,
    actual_base_qty: '1.00000000', qualified_base_qty: '1.00000000', unqualified_base_qty: '0.00000000',
    final_stockable_base_qty: '1.00000000', base_unit_name_snapshot: '个', batch_no: `BATCH-${id}`,
    allocations: [{ id: id + 2000, warehouse_id: 11, location_id: 21, base_qty: '1.00000000', serial_nos: [] }],
    ...overrides
  }
}

function serviceLine(id = 103, overrides = {}) {
  return stockLine(id, {
    item: { item_code: `SERVICE-${id}`, item_name: '办公服务', management_scope: 'office', is_stock_item: false, serial_tracking_mode: 'none', unit: { unit_name: '个' } },
    is_stock_item_snapshot: false, final_stockable_base_qty: '0.00000000', batch_no: '',
    warehouse_id: null, location_id: null, allocations: [],
    ...overrides
  })
}

function receipt(items, overrides = {}) {
  return {
    id: 301, receipt_no: 'RECEIPT-OFFICE-301', management_scope: 'office',
    receipt_status: 'confirmed', confirm_status: 'confirmed', stock_post_status: 'pending',
    posting_eligibility: { can_post: true, reason_text: '' },
    supplier: { supplier_name: '办公供应商' }, items,
    ...overrides
  }
}

test('混合办公到货分别显示合格三件、实际入库两件和不入库一件', () => {
  const { vm } = mount()
  const row = vm.mapReceipt(receipt([stockLine(), stockLine(102), serviceLine()]))
  assert.equal(row.qualified_qty, 3)
  assert.equal(row.qualified_display, '3 个')
  assert.equal(row.stockable_qty, 2)
  assert.equal(row.stockable_display, '2 个')
  assert.equal(row.non_stock_display, '1 个')
  assert.deepEqual(row.items.map(line => line.stockable_qty), [1, 1, 0])
  assert.deepEqual(row.items.map(line => line.non_stock_qty), [0, 0, 1])
  assert.equal(row.items[2].stockable_display, '0 个')
  assert.equal(row.items[2].non_stock_display, '1 个')
  assert.equal(row.items[0].final_stockable_base_qty, '1.00000000')
})

test('混合到货中的服务无批次和库位分配仍可确认并实际调用过账 API', async () => {
  const { vm, calls, messages } = mount()
  const row = vm.mapReceipt(receipt([stockLine(), stockLine(102), serviceLine()]))
  assert.equal(vm.postingLineNeedsAllocation(row.items[0]), true)
  assert.equal(vm.postingLineNeedsAllocation(row.items[2]), false)
  assert.equal(vm.postingBlockedReason(row), '')
  vm.openPostingConfirm(row)
  assert.equal(vm.postingDialogVisible, true)
  assert.equal(vm.postingCandidate.items.length, 3)
  await vm.confirmPosting()
  assert.deepEqual(calls.post, [[301]])
  assert.equal(vm.postingDialogVisible, false)
  assert.deepEqual(calls.reload.sort(), ['loadBalances', 'loadPostingRows', 'loadTransactions'])
  assert.equal(messages.some(message => message.level === 'warning'), false)
})

test('仅非库存服务无需分配且不会发起库存过账', async () => {
  const { vm, calls, messages } = mount()
  const row = vm.mapReceipt(receipt([serviceLine()]))
  assert.equal(row.qualified_qty, 1)
  assert.equal(row.stockable_qty, 0)
  assert.equal(vm.postingLineNeedsAllocation(row.items[0]), false)
  assert.match(vm.postingBlockedReason(row), /无需.*库存|无须.*库存/)
  vm.openPostingConfirm(row)
  assert.equal(vm.postingDialogVisible, false)
  assert.equal(messages.some(message => /分配/.test(message.value)), false)
  vm.openPostingRepair(row)
  assert.equal(vm.postingRepairVisible, false)
  vm.postingCandidate = row
  await vm.confirmPosting()
  assert.deepEqual(calls.post, [])
})

test('冻结非库存策略不被后来改为库存物料的主档覆盖', async () => {
  const { vm, calls } = mount()
  const currentMaster = { item_code: 'SERVICE-103', item_name: '现已库存管理的办公项', is_stock_item: true, serial_tracking_mode: 'none', unit: { unit_name: '个' } }
  const input = serviceLine(103, { item: currentMaster })
  const row = vm.mapReceipt(receipt([stockLine(), input]))
  assert.equal(row.items[1].is_stock_item_snapshot, false)
  assert.equal(row.items[1].stockable_qty, 0)
  assert.equal(row.items[1].non_stock_qty, 1)
  assert.equal(vm.postingPolicyIssue(row.items[1]), '')
  assert.equal(vm.postingLineNeedsAllocation(row.items[1]), false)
  assert.equal(vm.postingBlockedReason(row), '')
  vm.openPostingConfirm(row)
  await vm.confirmPosting()
  assert.deepEqual(calls.post, [[301]])
  assert.equal(currentMaster.is_stock_item, true)
  assert.equal(input.is_stock_item_snapshot, false)
})

test('缺失或未知冻结策略显示待校验并阻断过账，即使主档已是库存物料', async t => {
  for (const [label, snapshot] of [['null', null], ['undefined', undefined], ['未知值', 'unknown']]) {
    await t.test(label, async () => {
      const { vm, calls, messages } = mount()
      const row = vm.mapReceipt(receipt([stockLine(101, { is_stock_item_snapshot: snapshot })]))
      const line = row.items[0]
      assert.equal(line.is_stock_item_snapshot, null)
      assert.match(line.stockable_display, /待校验/)
      assert.match(line.non_stock_display, /待校验/)
      assert.match(row.stockable_display, /待校验/)
      assert.match(vm.postingPolicyIssue(line), /待校验/)
      assert.equal(vm.postingLineNeedsAllocation(line), false)
      assert.match(vm.postingBlockedReason(row), /待校验/)
      vm.openPostingConfirm(row)
      assert.equal(vm.postingDialogVisible, false)
      vm.openPostingRepair(row)
      assert.equal(vm.postingRepairVisible, false)
      vm.postingCandidate = row
      await vm.confirmPosting()
      assert.deepEqual(calls.post, [])
      assert.equal(messages.some(message => /待校验/.test(message.value)), true)
    })
  }
})

test('非法最终入库数量保留原始值并显示待校验，不按合格数量补成库存', async t => {
  const cases = [
    ['null', null], ['undefined', undefined], ['空值', ''], ['空白', ' '], ['非数值', 'invalid'],
    ['布尔值', true], ['数组', [1]],
    ['负数', '-1'], ['无限量', Infinity], ['NaN', NaN], ['超过实际到货', '2.00000000']
  ]
  for (const [label, finalQuantity] of cases) {
    await t.test(label, async () => {
      const { vm, calls } = mount()
      const row = vm.mapReceipt(receipt([stockLine(101, { final_stockable_base_qty: finalQuantity })]))
      const line = row.items[0]
      if (finalQuantity !== undefined) assert.equal(line.final_stockable_base_qty, finalQuantity)
      assert.match(line.stockable_display, /待校验/)
      assert.match(row.stockable_display, /待校验/)
      assert.match(vm.postingPolicyIssue(line), /待校验/)
      assert.equal(vm.postingLineNeedsAllocation(line), false)
      assert.match(vm.postingBlockedReason(row), /待校验/)
      vm.openPostingConfirm(row)
      assert.equal(vm.postingDialogVisible, false)
      vm.openPostingRepair(row)
      assert.equal(vm.postingRepairVisible, false)
      vm.postingCandidate = row
      await vm.confirmPosting()
      assert.deepEqual(calls.post, [])
    })
  }
})

test('非库存冻结快照却记录正入库量必须待校验，不能借分配绕过', async () => {
  const { vm, calls } = mount()
  const row = vm.mapReceipt(receipt([serviceLine(103, {
    final_stockable_base_qty: '1.00000000', batch_no: 'UNEXPECTED-BATCH',
    allocations: [{ warehouse_id: 11, location_id: 21, base_qty: 1 }]
  })]))
  assert.equal(row.items[0].is_stock_item_snapshot, false)
  assert.match(vm.postingPolicyIssue(row.items[0]), /待校验/)
  assert.match(row.items[0].stockable_display, /待校验/)
  assert.equal(vm.postingLineNeedsAllocation(row.items[0]), false)
  assert.match(vm.postingBlockedReason(row), /待校验/)
  vm.postingCandidate = row
  await vm.confirmPosting()
  assert.deepEqual(calls.post, [])
})

test('正库存行缺少分配时阻断混合到货，服务行不充当缺失分配对象', async () => {
  const { vm, calls } = mount()
  const row = vm.mapReceipt(receipt([stockLine(101, { allocations: [] }), serviceLine()]))
  assert.equal(vm.postingLineNeedsAllocation(row.items[0]), true)
  assert.equal(vm.postingLineNeedsAllocation(row.items[1]), false)
  const reason = vm.postingBlockedReason(row)
  assert.match(reason, /OFFICE-101/)
  assert.match(reason, /分配/)
  assert.doesNotMatch(reason, /SERVICE-103/)
  vm.openPostingConfirm(row)
  assert.equal(vm.postingDialogVisible, false)
  vm.postingCandidate = row
  await vm.confirmPosting()
  assert.deepEqual(calls.post, [])
})

test('库存冻结快照为真但最终入库量为零时不要求分配且不发库存过账', async () => {
  const { vm, calls } = mount()
  const row = vm.mapReceipt(receipt([stockLine(101, { final_stockable_base_qty: '0.00000000', allocations: [], batch_no: '' })]))
  assert.equal(vm.postingPolicyIssue(row.items[0]), '')
  assert.equal(vm.postingLineNeedsAllocation(row.items[0]), false)
  assert.equal(row.stockable_qty, 0)
  assert.match(vm.postingBlockedReason(row), /无需.*库存|无须.*库存/)
  vm.postingCandidate = row
  await vm.confirmPosting()
  assert.deepEqual(calls.post, [])
})

test('服务器禁止过账原因优先于未知政策、缺少分配和零入库', () => {
  const { vm } = mount()
  const inputs = [
    [stockLine(101, { is_stock_item_snapshot: null })],
    [stockLine(101, { allocations: [] })],
    [serviceLine()]
  ]
  for (const items of inputs) {
    const row = vm.mapReceipt(receipt(items, { posting_eligibility: { can_post: false, reason_text: '服务器阻断：来源单据需要复核' } }))
    assert.equal(vm.postingBlockedReason(row), '服务器阻断：来源单据需要复核')
  }
})

test('只检查实际正入库行的批次，库存行缺批次仍不能调用过账 API', async () => {
  const { vm, calls, messages } = mount()
  const row = vm.mapReceipt(receipt([stockLine(101, { batch_no: '' }), serviceLine()]))
  vm.openPostingConfirm(row)
  await vm.confirmPosting()
  assert.deepEqual(calls.post, [])
  assert.match(messages.at(-1).value, /批次/)
})

test('补充分配只包含实际正入库行，按冻结最终入库量初始化和校验', async () => {
  const { vm, calls } = mount()
  const row = vm.mapReceipt(receipt([
    stockLine(101, { actual_base_qty: '5.00000000', qualified_base_qty: '5.00000000', final_stockable_base_qty: '2.00000000', allocations: [] }),
    serviceLine()
  ]))
  vm.openPostingRepair(row)
  assert.equal(vm.postingRepairVisible, true)
  assert.deepEqual(vm.postingRepairCandidate.items.map(line => line.id), [101])
  const line = vm.postingRepairCandidate.items[0]
  assert.equal(line.qualified_qty, 5)
  assert.equal(line.stockable_qty, 2)
  assert.equal(line.allocations[0].base_qty, 2)
  assert.equal(vm.postingRepairLineComplete(line), false)
  Object.assign(line.allocations[0], { warehouse_id: 11, location_id: 21 })
  assert.equal(vm.postingRepairLineComplete(line), true)
  line.allocations[0].base_qty = 5
  assert.equal(vm.postingRepairLineComplete(line), false)
  line.allocations[0].base_qty = 2
  await vm.savePostingRepair()
  assert.deepEqual(calls.repair, [[301, { items: [{ receipt_item_id: 101, allocations: [{ warehouse_id: 11, location_id: 21, base_qty: 2, serial_nos: [] }] }] }]])
  assert.equal(vm.postingRepairVisible, false)
  assert.deepEqual(row.items[0].allocations, [])
  assert.deepEqual(row.items[1].allocations, [])
})
