import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import * as allocation from '../src/utils/sales-production-confirmation.mjs'

test('a partial purchased batch uses its current quantity and stock, without demanding a false manual-adjustment reason', () => {
  const row = { line_type: 'physical', is_purchase_fulfilled: true, confirm_qty: 6, available_sales_qty: 2,
    inventory_qty: 2, production_qty: 0, purchase_qty: 4, service_qty: 0, no_delivery_qty: 0,
    system_suggested_purchase_qty: 8, system_suggested_inventory_qty: 2 }
  assert.equal(allocation.confirmationIsAdjusted(row), false)
  assert.equal(allocation.currentConfirmationSuggestion(row).production_qty, 0)
  assert.equal(allocation.confirmationIsAdjusted({ ...row, inventory_qty: 1, purchase_qty: 5 }), true)
})

test('made goods, service and fee retain their correct current-batch destinations', () => {
  assert.equal(allocation.currentConfirmationSuggestion({ line_type: 'physical', confirm_qty: 6, available_sales_qty: 2 }).production_qty, 4)
  assert.equal(allocation.currentConfirmationSuggestion({ line_type: 'service', confirm_qty: 3 }).service_qty, 3)
  assert.equal(allocation.currentConfirmationSuggestion({ line_type: 'fee', confirm_qty: 1 }).no_delivery_qty, 1)
})

test('payload carries both concurrency identifiers and rejects negative, over-cap and unbalanced quantities', () => {
  const row = { sales_order_line_id: 7, confirm_qty: 6, remaining_confirm_qty: 10, inventory_qty: 2, purchase_qty: 4 }
  const payload = allocation.buildSalesProductionConfirmation({ business_version: 4 }, [row], '', 'stable-command')
  assert.equal(payload.client_command_id, 'stable-command'); assert.equal(payload.expected_version, 4)
  assert.equal(payload.lines[0].purchase_qty, 4)
  assert.throws(() => allocation.buildSalesProductionConfirmation({ business_version: 4 }, [{ ...row, remaining_confirm_qty: 5 }], '', 'c'))
  assert.throws(() => allocation.buildSalesProductionConfirmation({ business_version: 4 }, [{ ...row, purchase_qty: -4 }], '', 'c'))
  assert.throws(() => allocation.buildSalesProductionConfirmation({ business_version: 4 }, [{ ...row, purchase_qty: 3 }], '', 'c'))
})

const storage = () => {
  const values = new Map([['erp_user', '{"legacy_id":1}']])
  return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) }
}
function instance(file, mocks = {}) {
  const source = fs.readFileSync(new URL('../src/views/erp/' + file, import.meta.url), 'utf8')
  const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '').replace('export default', 'module.exports =')
  const globals = { module: { exports: {} }, cachedPageRoute: {}, ReadinessTag: {}, SalesOrderAttachmentPreviewDialog: {},
    SalesOrderFinancePanel: {}, ProductionPerformanceRateDialog: {}, statusText: value => value, statusTag: () => '',
    localStorage: storage(), ...allocation, ...mocks }
  vm.runInNewContext(script, globals)
  const component = globals.module.exports
  const row = { ...component.data(), $can: () => true, $confirm: async () => true,
    $route: { params: { id: 9 }, query: {} }, pageRoute: { params: { id: 9 } }, $message: { success() {}, warning() {}, error() {} } }
  for (const [name, method] of Object.entries(component.methods)) row[name] = method.bind(row)
  for (const [name, getter] of Object.entries(component.computed || {})) Object.defineProperty(row, name, { get: getter.bind(row) })
  return row
}

test('production confirmation persists the original command through unknown outcome and component reload', async () => {
  const calls = []; let count = 0
  const preview = { order: { id: 9, business_version: 4, order_status: 'confirmed', production_confirm_status: 'pending' },
    lines: [{ sales_order_line_id: 7, line_type: 'physical', is_purchase_fulfilled: true, confirm_qty: 6, remaining_confirm_qty: 10,
      available_sales_qty: 2, inventory_qty: 2, purchase_qty: 4 }] }
  const row = instance('sales/SalesProductionConfirmation.vue', {
    getProductionPreview: async () => ({ data: structuredClone(preview) }),
    confirmProduction: async (id, payload) => { calls.push(structuredClone(payload)); if (++count === 1) throw new Error('unknown result') }
  })
  await row.load(); await row.submit(); assert.ok(row.pending)
  preview.order.business_version = 5; await row.load()
  row.lines[0].purchase_qty = 1
  await row.submit()
  assert.deepEqual(calls[1], calls[0]); assert.equal(row.pending, null)
})

test('inventory locking reuses the stored command after a newer order projection arrives', async () => {
  const calls = []; let count = 0; let version = 4
  const row = instance('sales/SalesOrderDetail.vue', {
    getSalesOrder: async () => ({ data: { id: 9, business_version: version } }),
    listSalesOrderChanges: async () => ({ data: { data: [], total: 0 } }),
    lockSalesOrderInventory: async (id, payload) => { calls.push(structuredClone(payload)); if (++count === 1) throw new Error('unknown result'); return { data: { created_fulfillment_count: 1 } } }
  })
  await row.load(); await row.confirmInventoryLock(); assert.ok(row.inventoryLockPending)
  version = 5; await row.load(); await row.confirmInventoryLock()
  assert.deepEqual(calls[1], calls[0]); assert.equal(row.inventoryLockPending, null)
})

test('work-order rate panel handles real paginated scope responses and ignores stale page completions', async () => {
  let resolveFirst
  const row = instance('production/WorkOrderPerformancePanel.vue', {
    listWorkOrderPerformanceScopes: async (id, params) => params.page === 1 ? new Promise(resolve => { resolveFirst = resolve })
      : { data: { data: [{ scope: { scope_id: 22 } }], meta: { total: 21 } } }
  })
  row.workOrderId = 9
  const first = row.load(1); await row.load(2)
  resolveFirst({ data: { data: [{ scope: { scope_id: 1 } }], meta: { total: 21 } } }); await first
  assert.equal(row.page, 2); assert.equal(row.total, 21); assert.equal(row.rows[0].scope.scope_id, 22)
})

const confirmationPreview = id => ({ order: { id, business_version: 4, order_status: 'confirmed', production_confirm_status: 'pending' },
  lines: [{ sales_order_line_id: id * 10, line_type: 'service', confirm_qty: 1, remaining_confirm_qty: 1,
    inventory_qty: 0, production_qty: 0, purchase_qty: 0, service_qty: 1, no_delivery_qty: 0 }] })

test('two submit clicks while the confirmation prompt is pending create only one prompt and command', async () => {
  let prompts = 0; let writes = 0; let accept
  const row = instance('sales/SalesProductionConfirmation.vue', {
    getProductionPreview: async id => ({ data: confirmationPreview(Number(id)) }),
    confirmProduction: async () => { writes++ }
  })
  await row.load()
  row.$confirm = () => { prompts++; return new Promise(resolve => { accept = resolve }) }
  const first = row.submit(); const second = row.submit()
  assert.equal(prompts, 1)
  accept(true); await Promise.all([first, second])
  assert.equal(writes, 1)
})

test('a confirmation prompt opened for one order cannot dispatch the order loaded on another route', async () => {
  let accept; const writes = []
  const row = instance('sales/SalesProductionConfirmation.vue', {
    getProductionPreview: async id => ({ data: confirmationPreview(Number(id)) }),
    confirmProduction: async (id, payload) => { writes.push({ id, payload: structuredClone(payload) }) }
  })
  await row.load()
  row.$confirm = () => new Promise(resolve => { accept = resolve })
  const first = row.submit()
  row.pageRoute.params.id = 10; await row.load()
  accept(true); await first
  assert.equal(writes.some(request => request.id === 10), false)
  for (const request of writes) {
    assert.equal(request.id, 9)
    assert.equal(request.payload.lines[0].sales_order_line_id, 90)
  }
})

test('a completed confirmation for a previous route clears only its own stored command', async () => {
  const local = storage(); let complete; const writes = []
  const row = instance('sales/SalesProductionConfirmation.vue', {
    localStorage: local,
    getProductionPreview: async id => ({ data: confirmationPreview(Number(id)) }),
    confirmProduction: async (id, payload) => { writes.push({ id, payload: structuredClone(payload) }); return new Promise(resolve => { complete = resolve }) }
  })
  await row.load()
  const first = row.submit()
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(writes[0].id, 9)
  const oldKey = 'erp_pending_sales_production_1_9'; const newKey = 'erp_pending_sales_production_1_10'
  const next = { client_command_id: 'confirmation-order-10', expected_version: 4, lines: [{ sales_order_line_id: 100, confirm_qty: 1, service_qty: 1 }] }
  local.setItem(newKey, JSON.stringify(next)); row.pageRoute.params.id = 10; await row.load()
  complete({ data: {} }); await first
  assert.equal(local.getItem(oldKey), null)
  assert.equal(local.getItem(newKey), JSON.stringify(next))
  assert.equal(row.order.id, 10); assert.equal(row.pending.client_command_id, next.client_command_id)
})

test('a completed inventory lock for a previous route retains the new order pending command', async () => {
  const local = storage(); let complete; const writes = []
  const row = instance('sales/SalesOrderDetail.vue', {
    localStorage: local,
    getSalesOrder: async id => ({ data: { id: Number(id), business_version: 4 } }),
    listSalesOrderChanges: async () => ({ data: { data: [], total: 0 } }),
    lockSalesOrderInventory: async (id, payload) => { writes.push({ id, payload: structuredClone(payload) }); return new Promise(resolve => { complete = resolve }) }
  })
  await row.load(); const first = row.confirmInventoryLock()
  assert.equal(writes[0].id, 9)
  const oldKey = 'erp_pending_sales_inventory_lock_1_9'; const newKey = 'erp_pending_sales_inventory_lock_1_10'
  const next = { client_command_id: 'inventory-order-10', expected_version: 4 }
  local.setItem(newKey, JSON.stringify(next)); row.$route.params.id = 10; await row.load()
  complete({ data: { created_fulfillment_count: 1 } }); await first
  assert.equal(local.getItem(oldKey), null)
  assert.equal(local.getItem(newKey), JSON.stringify(next))
  assert.equal(row.order.id, 10); assert.equal(row.inventoryLockPending.client_command_id, next.client_command_id)
})
