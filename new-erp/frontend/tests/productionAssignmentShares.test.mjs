import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { createRequire } from 'node:module'
import * as shares from '../src/utils/production-performance.mjs'
const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const plain = value => JSON.parse(JSON.stringify(value))
const scope = (id, person = 102) => ({ scope_type: 'unit_operation', scope_id: id, scope_version: 3, owner_legacy_id: 101, scope_name: '真实单元 ' + id, can_plan: true,
  participants: [{ employee_legacy_id: 101, employee_name: '接单人', is_active: true }, { employee_legacy_id: person, employee_name: '实际参与人', has_labor: true }] })

function fixture(pending = null) {
  const parsed = compiler.parseComponent(fs.readFileSync(new URL('../src/views/erp/production/ProductionAssignmentPanel.vue', import.meta.url), 'utf8'))
  const source = parsed.script.content.replace(/^import[\s\S]*?from\s+['"][^'"]+['"]\r?\n/gm, '').replace('export default', 'module.exports =')
  const module = { exports: {} }; const submitted = []; const errors = []
  vm.runInNewContext(source, { module, ...shares,
    pendingProductionDecision: () => pending,
    addTaskCollaborators: (id, payload) => { submitted.push({ id, payload: plain(payload) }); return Promise.resolve({ data: {} }) }
  })
  const definition = module.exports
  const page = { ...plain(definition.data()), $set: (object, key, value) => { object[key] = value }, $message: { error: value => errors.push(value) } }
  Object.entries(definition.methods).forEach(([key, method]) => { page[key] = method.bind(page) })
  page.loadCollaborators = () => {}; page.mutate = (_key, payload, send) => send(payload).then(() => true)
  return { page, submitted, errors }
}

test('PC assignment sends manual seventy thirty for the actual scope with real owner and collaborator', async () => {
  const f = fixture()
  f.page.openCollaborators({ id: 7, business_version: 8, share_scopes: [{ ...scope(11), participants: [{ employee_legacy_id: 101, employee_name: '接单人', is_active: true }] }] })
  f.page.collaboratorPeople[102] = { user_id: 102, display_name: '协同人' }; f.page.collaboratorIds = [102]; f.page.refreshCollaboratorShares()
  f.page.collaboratorShares[0].rows[0].percent = '70'; f.page.collaboratorShares[0].rows[1].percent = '30'
  await f.page.addCollaborators()
  assert.equal(f.submitted[0].id, 7)
  assert.deepEqual(f.submitted[0].payload.employee_legacy_ids, [102])
  assert.deepEqual(f.submitted[0].payload.scope_shares[0].shares.map(person => person.share_ratio), ['0.70000000', '0.30000000'])
  assert.equal(f.submitted[0].payload.scope_shares[0].scope_id, 11)
  assert.equal(f.submitted[0].payload.scope_shares[0].expected_scope_version, 3)
})

test('each scope retains its real past labor participants instead of combining different scopes', () => {
  const groups = shares.personalShareGroups([scope(11, 102), scope(12, 103)], [{ user_id: 104, display_name: '新增协同人' }])
  assert.deepEqual(groups.map(group => group.rows.map(person => person.employee_legacy_id)), [[101, 102, 104], [101, 103, 104]])
  assert.deepEqual(groups.map(group => shares.buildPerformanceShares(group.rows).map(person => person.share_ratio)), [
    ['0.33333333', '0.33333333', '0.33333334'], ['0.33333333', '0.33333333', '0.33333334']
  ])
})

test('historical eighty percent plan is displayed unchanged until explicit allocation revision', () => {
  const original = scope(11)
  original.share_plan = { version_no: 2, shares: [{ employee_legacy_id: 101, share_ratio: '0.5' }, { employee_legacy_id: 102, share_ratio: '0.3' }] }
  const group = shares.personalShareGroups([original])[0]
  assert.deepEqual(group.rows.map(person => person.percent), ['50', '30'])
  assert.throws(() => shares.buildPersonalScopeShares([group]), /100%/)
  assert.deepEqual(original.share_plan.shares.map(person => person.share_ratio), ['0.5', '0.3'])
})

test('one actual owner defaults to one hundred and completed scopes receive no editable fake group', () => {
  const ownerOnly = { ...scope(11), participants: [{ employee_legacy_id: 101, employee_name: '接单人' }] }
  assert.equal(shares.personalShareGroups([ownerOnly])[0].rows[0].percent, '100')
  assert.deepEqual(shares.personalShareGroups([{ ...ownerOnly, can_plan: false }]), [])
})

test('reopened uncertain PC selection displays and replays the original seventy thirty shares', async () => {
  const pending = { expected_version: 2, employee_legacy_ids: [102], scope_shares: [{ scope_type: 'unit_operation', scope_id: 11,
    expected_scope_version: 3, expected_assignment_version: 0, shares: [{ employee_legacy_id: 101, share_ratio: '0.7' }, { employee_legacy_id: 102, share_ratio: '0.3' }] }] }
  const f = fixture(pending)
  f.page.openCollaborators({ id: 7, assignee_user_legacy_id: 101, business_version: 99, share_scopes: [scope(11)] })
  assert.equal(f.page.collaboratorPending, true)
  assert.deepEqual(plain(f.page.collaboratorShares[0].rows).map(person => person.percent), ['70', '30'])
  f.page.removeSelectedCollaborator(102); f.page.equalShares(f.page.collaboratorShares[0])
  assert.deepEqual(plain(f.page.collaboratorIds), [102])
  assert.deepEqual(plain(f.page.collaboratorShares[0].rows).map(person => person.percent), ['70', '30'])
  await f.page.addCollaborators()
  assert.deepEqual(f.submitted[0].payload, pending)
})

test('an uncertain PC write preserves the original command and original per-scope shares on retry', async () => {
  const file = fs.readFileSync(new URL('../src/api/erp/production-assignments.js', import.meta.url), 'utf8')
  const source = file.slice(file.indexOf('export function executeProductionDecision')).replace('export function', 'function') + '\nmodule.exports = { executeProductionDecision }'
  const module = { exports: {} }; const storage = new Map([['erp_user', JSON.stringify({ legacy_id: 101 })]])
  vm.runInNewContext(source, { module, localStorage: {
    getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key)
  } })
  const sent = []
  const initial = { expected_version: 2, employee_legacy_ids: [102], scope_shares: [{ scope_id: 11, shares: [{ employee_legacy_id: 101, share_ratio: '0.7' }] }] }
  await assert.rejects(module.exports.executeProductionDecision('collaborators_7', initial, payload => { sent.push(plain(payload)); return Promise.reject(new Error('lost response')) }))
  await module.exports.executeProductionDecision('collaborators_7', { expected_version: 99, scope_shares: [] }, payload => { sent.push(plain(payload)); return Promise.resolve({}) })
  assert.deepEqual(sent[1], sent[0])
  assert.equal(sent[1].scope_shares[0].shares[0].share_ratio, '0.7')
})
