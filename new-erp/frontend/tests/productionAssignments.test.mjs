import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const source = await readFile(new URL('../src/views/erp/production/ProductionAssignmentPanel.vue', import.meta.url), 'utf8')

function mount(deps = {}) {
  const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/import[\s\S]*?from ['"][^'"]+['"]\s*/g, '').replace('export default', 'return')
  const options = new Function(...Object.keys(deps), script)(...Object.values(deps))
  const vm = { ...options.data(), workOrder: {}, $can: () => true, $message: { success() {}, error() {} }, $emit() {} }
  for (const [key, fn] of Object.entries(options.methods)) vm[key] = fn.bind(vm)
  return vm
}

test('assignment panel compiles with the current Vue template compiler', () => {
  const descriptor = compiler.parseComponent(source)
  assert.deepEqual(compiler.compile(descriptor.template.content).errors, [])
})

test('public classification is a bounded server filter that keeps the owned task scope and resets pagination', async () => {
  const requests = []
  const vm = mount({ listAssignmentTasks: async params => {
    requests.push(params)
    return { data: { data: [{ id: 7, is_public_snapshot: true, operation: { is_public: false } }], total: 41 } }
  } })
  vm.$can = permission => permission === 'production.task.view'
  await vm.load()
  assert.equal(requests[0].view, 'owned')
  assert.equal(requests[0].is_public, '')
  vm.publicFilter = '0'; vm.page = 3
  await vm.load()
  assert.equal(requests[1].is_public, '0')
  assert.equal(requests[1].page, 3)
  assert.equal(requests[1].per_page, 20)
  vm.publicFilter = '1'
  await vm.search()
  assert.equal(requests[2].is_public, '1')
  assert.equal(requests[2].page, 1)
  assert.equal(requests[2].view, 'owned')
  assert.equal(vm.total, 41)
  assert.equal(vm.isPublicOperation(vm.tasks[0].is_public_snapshot), true)
  for (const value of [false, 0, '0', undefined]) assert.equal(vm.isPublicOperation(value), false)
})

test('an earlier classification response cannot overwrite the latest task page', async () => {
  const pending = []
  const vm = mount({ listAssignmentTasks: params => new Promise(resolve => pending.push({ params, resolve })) })
  const first = vm.load()
  vm.publicFilter = '0'
  const latest = vm.search()
  pending[1].resolve({ data: { data: [{ id: 2, is_public_snapshot: false }], total: 1 } })
  await latest
  pending[0].resolve({ data: { data: [{ id: 1, is_public_snapshot: true }], total: 70 } })
  await first
  assert.deepEqual(vm.tasks.map(row => row.id), [2])
  assert.equal(vm.total, 1)
  assert.equal(vm.publicFilter, '0')
})

test('PC accepts the offered assignment with both versions, rather than inventing a new assignee', async () => {
  let sent
  const vm = mount({ acceptTaskAssignment: async (id, payload) => { sent = { id, payload } } })
  vm.mutate = async (key, payload, send) => { sent = { key }; await send(payload); sent.key = key }
  await vm.accept({ id: 8, business_version: 6, pending_assignment: { id: 31, business_version: 2 } })
  assert.deepEqual(sent, { id: 31, key: 'accept_31', payload: { expected_version: 2, expected_task_version: 6 } })
  assert.equal(vm.allowed({ allowed_actions: { accept_assignment: false } }, 'accept_assignment'), false)
})

test('collaborator pagination sends a bounded server request and retains other-page selections', async () => {
  const requests = []
  const vm = mount({
    listTaskCollaboratorCandidates: async (id, params) => {
      requests.push({ id, params })
      return { data: { total: 21, data: [{ user_id: params.page === 1 ? 200 : 300, display_name: '成员' }] } }
    }
  })
  vm.selectedTask = { id: 9 }; vm.collaboratorsOpen = true; vm.collaboratorKeyword = '装配'
  await vm.loadCollaborators(1); vm.collaboratorIds = [200]; await vm.loadCollaborators(2)
  assert.deepEqual(vm.collaboratorIds, [200])
  assert.deepEqual(requests[1], { id: 9, params: { page: 2, per_page: 20, keyword: '装配' } })
  vm.resetCollaborators()
  assert.deepEqual(vm.collaboratorIds, [])
  assert.deepEqual(vm.collaboratorRows, [])
})

test('closed collaborator selectors ignore earlier server responses', async () => {
  let resolve
  const vm = mount({ listTaskCollaboratorCandidates: () => new Promise(done => { resolve = done }) })
  vm.selectedTask = { id: 9 }; vm.collaboratorsOpen = true
  const request = vm.loadCollaborators(1)
  vm.collaboratorsOpen = false; vm.resetCollaborators()
  resolve({ data: { data: [{ user_id: 200 }], total: 1 } }); await request
  assert.deepEqual(vm.collaboratorRows, [])
  assert.equal(vm.collaboratorTotal, 0)
})

test('PC unknown write outcome preserves its original command and payload across reloads', async () => {
  const apiSource = (await readFile(new URL('../src/api/erp/production-assignments.js', import.meta.url), 'utf8')).split('export function executeProductionDecision')[1]
  const storage = new Map([['erp_user', '{"legacy_id":100}']])
  const localStorage = { getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) }
  const execute = new Function('localStorage', 'return function executeProductionDecision' + apiSource)(localStorage)
  let first
  await assert.rejects(execute('reject_8', { expected_version: 1, reason: '已排任务' }, async payload => {
    first = payload; throw new Error('network timeout')
  }))
  let replay
  await execute('reject_8', { expected_version: 2, reason: '改了原因' }, async payload => { replay = payload; return {} })
  assert.deepEqual(replay, first)
  assert.equal(storage.size, 1)
})
