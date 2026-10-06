import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const helpersSource = await readFile(new URL('../src/utils/systemManagement.js', import.meta.url), 'utf8')
const helpers = await import(`data:text/javascript;base64,${Buffer.from(helpersSource).toString('base64')}`)
const pickerSource = await readFile(new URL('../src/components/system/SystemRecordPicker.vue', import.meta.url), 'utf8')
const roleSource = await readFile(new URL('../src/views/erp/system/SystemRolePermission.vue', import.meta.url), 'utf8')

function component(source, deps, extra) {
  const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '').replace('export default', 'return')
  const options = new Function(...Object.keys(deps), script)(...Object.values(deps))
  const vm = { ...options.data(), ...extra }
  for (const [name, fn] of Object.entries(options.methods)) vm[name] = fn.bind(vm)
  for (const [name, fn] of Object.entries(options.computed || {})) Object.defineProperty(vm, name, { get: () => fn.call(vm) })
  return { vm, options }
}

test('department parent choices exclude the edited subtree and safely retain orphan or cyclic history', () => {
  const rows = [
    { legacy_id: 1, parent_legacy_id: 0, name: '总公司' },
    { legacy_id: 2, parent_legacy_id: 1, name: '部门' },
    { legacy_id: 3, parent_legacy_id: 2, name: '下级部门' },
    { legacy_id: 4, parent_legacy_id: 999, name: '历史组织' },
    { legacy_id: 5, parent_legacy_id: 6, name: '异常组织一' },
    { legacy_id: 6, parent_legacy_id: 5, name: '异常组织二' }
  ]
  const flattened = helpers.flatDepartmentTree(rows)
  assert.equal(flattened.length, 6)
  assert.equal(flattened.find(row => row.legacy_id === 3).depth, 2)
  const choices = helpers.departmentTree(rows, 2)
  const ids = choices.flatMap(row => [row.value, ...(row.children || []).map(child => child.value)])
  assert.ok(!ids.includes(2) && !ids.includes(3))
  assert.ok(ids.includes(1) && ids.includes(4))
})

test('selecting one button includes its menu ancestry without granting sibling buttons', () => {
  const permissions = [{ id: 1, parent_id: null }, { id: 2, parent_id: 1 }, { id: 3, parent_id: 2 }, { id: 4, parent_id: 2 }]
  assert.deepEqual(helpers.permissionClosure([3], permissions), [1, 2, 3])
  assert.deepEqual(helpers.permissionClosure([2], permissions), [1, 2])
  assert.deepEqual(helpers.permissionClosure([], permissions), [])
})

test('paged selection preserves other pages, cancel leaves the input selection intact, and a late response is discarded', async () => {
  let resolve
  const input = [{ id: 99, nickname: '原已选成员' }]
  const emitted = []
  const { vm, options } = component(pickerSource, {
    ...helpers, listRoleOptions: async () => ({ data: { data: [], meta: { total: 0 } } }),
    listSystemUserOptions: () => new Promise(yes => { resolve = yes })
  }, { visible: true, type: 'users', multiple: true, value: input, departmentId: 0,
    $set: (map, key, value) => { map[key] = value }, $delete: (map, key) => { delete map[key] }, $emit: (...args) => emitted.push(args) })
  vm.open()
  resolve({ data: { data: [{ id: 1, nickname: '第一页成员' }], meta: { total: 20 } } })
  await Promise.resolve(); await Promise.resolve()
  vm.toggle(vm.rows[0])
  const next = vm.load()
  resolve({ data: { data: [{ id: 11, nickname: '第二页成员' }], meta: { total: 20 } } })
  await next
  vm.toggle(vm.rows[0])
  assert.deepEqual(vm.selectedRows.map(row => row.id).sort((a, b) => a - b), [1, 11, 99])
  const late = vm.load()
  vm.visible = false; options.watch.visible.call(vm, false)
  resolve({ data: { data: [{ id: 123, nickname: '过期查询' }], meta: { total: 1 } } })
  await late
  assert.equal(vm.rows[0].id, 11)
  assert.deepEqual(input, [{ id: 99, nickname: '原已选成员' }])
  vm.confirm()
  assert.deepEqual(emitted[0][1].map(row => row.id).sort((a, b) => a - b), [1, 11, 99])
})

test('create form retries reuse the command after a lost response and rotate it when the user edits the payload', async () => {
  const calls = []
  const { vm } = component(roleSource, { ...helpers, SystemRecordPicker: {}, saveRole: async payload => {
    calls.push(payload); throw new Error('Network response lost')
  } }, { $refs: { roleForm: { validate: async () => true } }, $message: { error() {}, success() {} }, $nextTick: fn => fn() })
  vm.roleForm = { code: 'local_test', name: '专项角色', data_scope: 'self', enabled: true, remark: '' }
  vm.formCommand = helpers.newSystemCommand()
  await vm.submitRoleForm(); await vm.submitRoleForm()
  assert.equal(calls[0].client_command_id, calls[1].client_command_id)
  vm.roleForm.name = '修改后的专项角色'
  await vm.submitRoleForm()
  assert.notEqual(calls[1].client_command_id, calls[2].client_command_id)
  assert.equal(calls[2].name, '修改后的专项角色')
})

test('status toggle preserves the saved scope and permissions when the permission editor has unsaved changes', async () => {
  const calls = []
  const { vm } = component(roleSource, { ...helpers, SystemRecordPicker: {}, saveRole: async payload => { calls.push(payload) } }, {
    $confirm: async () => true, $message: { error() {}, success() {} }
  })
  const saved = { id: 1, code: 'test-role', name: '专项角色', data_scope: 'self', enabled: true, business_version: 3, permission_ids: [7] }
  vm.roles = [saved]
  vm.currentRole = { ...saved, data_scope: 'all' }
  vm.load = async () => {}
  await vm.toggleRole()
  assert.equal(calls[0].data_scope, 'self')
  assert.equal(calls[0].enabled, false)
  assert.equal(calls[0].expected_version, 3)
  assert.equal(Object.hasOwn(calls[0], 'permission_ids'), false)
  assert.equal(vm.saving, false)
})
