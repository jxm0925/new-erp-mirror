import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import * as policy from '../src/utils/production-performance.mjs'

const storage = () => {
  const values = new Map([['erp_user', JSON.stringify({ legacy_id: 1 })]])
  return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) }
}
function loadComponent(name, mocks = {}) {
  const source = fs.readFileSync(new URL('../src/views/erp/' + name, import.meta.url), 'utf8')
  const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '').replace('export default', 'module.exports =')
  const globals = { module: { exports: {} }, ...policy, localStorage: storage(), ...mocks }
  vm.runInNewContext(script, globals)
  const component = globals.module.exports
  const events = []
  const instance = { ...component.data(), $can: () => true, $set: (obj, key, value) => { obj[key] = value }, $delete: (obj, key) => { delete obj[key] },
    $message: { error() {}, success() {} }, $emit: (...event) => events.push(event) }
  for (const [name, method] of Object.entries(component.methods)) instance[name] = method.bind(instance)
  for (const [name, getter] of Object.entries(component.computed || {})) Object.defineProperty(instance, name, { get: getter.bind(instance) })
  return { instance, globals, events }
}
const shareResponse = (page, count = 21, assignment = null) => {
  const all = Array.from({ length: count }, (_, index) => ({ employee_legacy_id: index + 1, employee_name: '人员' + (index + 1), identity_exists: true, actual_labor_minutes: '2' }))
  return { data: { data: { scope: { scope_version: 1, scope_type: 'quantity_operation', scope_id: 6, owner_legacy_id: 1, status: 'IN_PROGRESS', can_manage_shares: true },
    participants: { data: all.slice((page - 1) * 20, page * 20), meta: { total: count } }, default_shares: policy.defaultPerformanceShares(all), assignment } } }
}

test('share dialog loads paged people and retains both defaults and manual entries across pages', async () => {
  const requests = []; const saved = []
  const { instance } = loadComponent('production/ProductionPerformanceShares.vue', {
    getPerformanceScope: async (type, id, params) => { requests.push(params); return shareResponse(params.page) },
    confirmPerformanceShares: async (type, id, payload) => { saved.push(structuredClone(payload)); return { data: { data: { status: 'planned' } } } }
  })
  await instance.open('quantity_operation', 6)
  assert.equal(instance.participants.length, 20)
  assert.equal(Object.keys(instance.selections).length, 21)
  assert.equal(instance.totals.declared, 100)
  for (const selection of Object.values(instance.selections)) selection.percent = '0'
  instance.selections[1].percent = '70'; instance.selections[21].percent = '30'
  await instance.loadPage(2)
  assert.equal(instance.participants.length, 1)
  assert.equal(instance.selections[1].percent, '70')
  assert.equal(instance.totals.declared, 100)
  await instance.confirm()
  assert.equal(saved[0].shares.length, 21)
  assert.equal(saved[0].shares[0].share_ratio, '0.70000000')
  assert.equal(saved[0].shares[20].share_ratio, '0.30000000')
  assert.equal(requests.every(row => row.per_page === 20), true)
  assert.equal('noncredited_reason' in saved[0], false)
})

test('opening an old 80 percent assignment does not normalize or write it', async () => {
  const historical = { version_no: 2, credited_share_ratio: '0.8', shares: [{ employee_legacy_id: 1, eligible: true, share_ratio: '0.8' }] }
  const original = structuredClone(historical); let writes = 0
  const { instance } = loadComponent('production/ProductionPerformanceShares.vue', {
    getPerformanceScope: async (type, id, params) => shareResponse(params.page, 1, historical),
    confirmPerformanceShares: async () => { writes++; return { data: { data: { status: 'confirmed' } } } }
  })
  await instance.open('quantity_operation', 6)
  assert.equal(instance.selections[1].percent, '80')
  assert.equal(instance.legacyAssignment, true)
  assert.equal(writes, 0)
  await instance.confirm()
  assert.equal(writes, 0)
  instance.equalShares()
  assert.equal(instance.selections[1].percent, '100')
  await instance.confirm()
  assert.equal(writes, 1)
  assert.deepEqual(historical, original)
})

test('share dialog replays the same saved command after an unknown network outcome', async () => {
  let calls = 0; const payloads = []
  const { instance } = loadComponent('production/ProductionPerformanceShares.vue', {
    getPerformanceScope: async (type, id, params) => shareResponse(params.page, 1),
    confirmPerformanceShares: async (type, id, payload) => { payloads.push(structuredClone(payload)); if (++calls === 1) throw new Error('网络中断'); return { data: { data: { status: 'planned' } } } }
  })
  await instance.open('quantity_operation', 6)
  await instance.confirm()
  assert.ok(instance.pending)
  instance.visible = false; instance.reset()
  await instance.open('quantity_operation', 6)
  instance.selections[1].percent = '50'
  await instance.confirm()
  assert.deepEqual(payloads[1], payloads[0])
  assert.equal(instance.pending, null)
})

test('ratio dialog changes one percent into .01 and uses original command on an unknown-result retry', async () => {
  let calls = 0; const payloads = []
  const { instance, events } = loadComponent('production/ProductionPerformanceRateDialog.vue', {
    updatePerformanceScopeRate: async (type, id, payload) => { payloads.push(structuredClone(payload)); if (++calls === 1) throw new Error('网络中断'); return { data: {} } }
  })
  const scope = { scope_type: 'quantity_operation', scope_id: 6, scope_version: 4, rate_version: 2, performance_rate_snapshot: '0.02' }
  instance.open(scope); instance.percent = '1'; instance.reason = '按工序重新核定'
  await instance.save()
  assert.equal(payloads[0].performance_rate, '0.01000000')
  assert.ok(instance.pending)
  instance.visible = false; instance.reset(); instance.open({ ...scope, scope_version: 5, rate_version: 3 })
  instance.percent = '99'
  await instance.save()
  assert.deepEqual(payloads[1], payloads[0])
  assert.equal(instance.pending, null)
  assert.equal(events.some(event => event[0] === 'updated'), true)
})

test('known ratio version conflict clears the old command and requests current scope refresh', async () => {
  const { instance, events } = loadComponent('production/ProductionPerformanceRateDialog.vue', {
    updatePerformanceScopeRate: async () => { const error = new Error('工序比例已变化'); error.response = { status: 409 }; throw error }
  })
  instance.open({ scope_type: 'quantity_operation', scope_id: 6, scope_version: 4, rate_version: 2, performance_rate_snapshot: '0.02' })
  instance.reason = '按工序重新核定'
  await instance.save()
  assert.equal(instance.pending, null)
  assert.equal(instance.visible, false)
  assert.equal(events.some(event => event[0] === 'stale'), true)
})

test('workers can plan their own shares but cannot open the first-level rate dialog', async () => {
  const { instance } = loadComponent('production/ProductionPerformanceRateDialog.vue')
  instance.$can = code => code === 'sales_order.shipment.packing.execute'
  instance.open({ scope_type: 'shipment_packing_operation', scope_id: 6 })
  assert.equal(instance.visible, false)
  const shares = loadComponent('production/ProductionPerformanceShares.vue', { getPerformanceScope: async () => shareResponse(1, 1) }).instance
  shares.$can = code => code === 'sales_order.shipment.packing.execute'
  await shares.open('shipment_packing_operation', 6)
  assert.equal(shares.visible, true)
  assert.equal(shares.scope.can_manage_shares, true)
})

test('packing selection retains already recorded contributors and sends one atomic 70/30 allocation', () => {
  const { instance } = loadComponent('sales/ShipmentPackingPanel.vue')
  instance.activeOperation = { id: 6, participants: [
    { employee_legacy_id: 3, role: 'OWNER', name: '负责人', is_active: true, actual_labor_minutes: 2 },
    { employee_legacy_id: 9, role: 'COLLABORATOR', name: '协同人', is_active: true, has_labor: true, actual_labor_minutes: 3 }
  ] }
  instance.peopleSelected = { 9: { legacy_id: 9, nickname: '协同人' } }
  instance.rebalancePeopleShares()
  instance.peopleShares[3].percent = '70'; instance.peopleShares[9].percent = '30'
  const requests = []; instance.execute = (op, action, payload) => requests.push({ action, payload })
  instance.savePeople()
  assert.equal(requests[0].action, 'collaborators')
  assert.deepEqual(Array.from(requests[0].payload.employee_legacy_ids), [9])
  assert.equal(requests[0].payload.shares[0].share_ratio, '0.70000000')
  assert.equal(requests[0].payload.shares[1].share_ratio, '0.30000000')
  instance.togglePerson({ legacy_id: 9 }, false)
  assert.equal(instance.peopleSelected[9], undefined)
  assert.equal(instance.peopleShareRows.some(row => row.employee_legacy_id === 9 && row.pastParticipant), true)
  assert.equal(instance.peopleShareTotal, 100)
})

test('packing people search and paging retain selected collaborators without loading all people', async () => {
  const requests = []
  const { instance } = loadComponent('sales/ShipmentPackingPanel.vue', {
    listPackingPeople: async (id, params) => { requests.push(params); return { data: { data: { data: [{ legacy_id: params.page === 1 ? 9 : 21, nickname: '协同人' }], total: 41 } } } }
  })
  instance.activeOperation = { id: 6, participants: [{ employee_legacy_id: 3, role: 'OWNER', name: '负责人', actual_labor_minutes: 0 }] }
  await instance.openPeople()
  instance.togglePerson(instance.peopleRows[0], true)
  instance.peopleQuery.page = 2; await instance.loadPeople()
  instance.togglePerson(instance.peopleRows[0], true)
  assert.deepEqual(Object.keys(instance.peopleSelected).sort(), ['21', '9'])
  assert.equal(instance.peopleShareRows.length, 3)
  assert.equal(instance.peopleShareTotal, 100)
  assert.equal(requests.every(row => row.per_page === 20), true)
  instance.resetSelectors()
  assert.equal(Object.keys(instance.peopleShares).length, 0)
})
