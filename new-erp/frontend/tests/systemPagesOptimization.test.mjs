import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import compiler from 'vue-template-compiler'

const menuSource = await readFile(new URL('../src/views/erp/system/SystemMenuManagement.vue', import.meta.url), 'utf8')
const deptSource = await readFile(new URL('../src/views/erp/system/SystemDepartmentManagement.vue', import.meta.url), 'utf8')

test('SystemMenuManagement template compiles and satisfies all UI and dialog architecture mandates', () => {
  // 1. Vue compilation check using parseComponent
  const parsed = compiler.parseComponent(menuSource)
  assert.ok(parsed.template, 'SystemMenuManagement template should exist')
  const compiled = compiler.compile(parsed.template.content)
  assert.deepEqual(compiled.errors, [], `Template compile errors: ${compiled.errors.join('; ')}`)

  // 2. Absolute ban on el-drawer (Dialog architecture mandate 2026-09-24)
  assert.ok(!menuSource.includes('<el-drawer'), 'Must NOT contain el-drawer')
  assert.ok(!menuSource.includes('</el-drawer>'), 'Must NOT contain </el-drawer>')
  assert.ok(menuSource.includes('<el-dialog'), 'Must contain centered el-dialog')
  assert.ok(menuSource.includes('dialogVisible'), 'Must use dialogVisible for dialog visibility')

  // 3. Page head and tip banner
  assert.ok(menuSource.includes('class="page-head"'), 'Must have standard .page-head white card')
  assert.ok(menuSource.includes('class="head-icon"'), 'Must have green .head-icon')
  assert.ok(menuSource.includes('class="page-title"'), 'Must have .page-title')
  assert.ok(menuSource.includes('class="erp-page-tip"'), 'Must have standard .erp-page-tip banner')

  // 4. Metric overview cards
  assert.ok(menuSource.includes('class="metric-overview-grid"'), 'Must have .metric-overview-grid')
  assert.ok(menuSource.includes('metric-all'), 'Must have all metric card')
  assert.ok(menuSource.includes('metric-menu'), 'Must have menu metric card')
  assert.ok(menuSource.includes('metric-button'), 'Must have button metric card')
  assert.ok(menuSource.includes('metric-disabled'), 'Must have disabled metric card')

  // 5. Centered dialog header banner
  assert.ok(menuSource.includes('dialog-header-banner'), 'Must have .dialog-header-banner in dialog')
})

test('SystemMenuManagement script logic and metric calculations execute correctly', () => {
  const scriptContent = compiler.parseComponent(menuSource).script.content
    .replace(/^import .*$/gm, '')
    .replace('export default', 'return')
  const options = new Function(scriptContent)()
  const vm = {
    ...options.data(),
    permissions: [
      { id: 1, name: '系统管理', code: 'system', type: 'menu', parent_id: null, enabled: true },
      { id: 2, name: '用户管理', code: 'system.user', type: 'menu', parent_id: 1, enabled: true },
      { id: 3, name: '新增用户', code: 'system.user.create', type: 'button', parent_id: 2, enabled: true },
      { id: 4, name: '停用模块', code: 'legacy.module', type: 'menu', parent_id: null, enabled: false }
    ],
    menuTree: []
  }
  for (const [name, fn] of Object.entries(options.methods)) vm[name] = fn.bind(vm)
  for (const [name, fn] of Object.entries(options.computed || {})) {
    Object.defineProperty(vm, name, { get: () => fn.call(vm) })
  }

  // Verify computed stats
  assert.equal(vm.stats.all, 4)
  assert.equal(vm.stats.menu, 3)
  assert.equal(vm.stats.button, 1)
  assert.equal(vm.stats.disabled, 1)

  // Test filter toggle
  vm.switchFilter('menu')
  assert.equal(vm.filterType, 'menu')
  assert.equal(vm.activeFilterTag, '筛选: 业务菜单')

  // Test dialog openers
  vm.openAddRoot()
  assert.equal(vm.dialogVisible, true)
  assert.equal(vm.editForm.type, 'menu')
  assert.equal(vm.editForm.parent_id, null)

  vm.openAddButton({ id: 2, code: 'system.user' })
  assert.equal(vm.dialogVisible, true)
  assert.equal(vm.editForm.type, 'button')
  assert.equal(vm.editForm.parent_id, 2)
  assert.equal(vm.editForm.code, 'system.user.')

  // Test preset button application
  vm.applyPresetButton({ name: '查看详情', action: 'view' })
  assert.equal(vm.editForm.name, '查看详情')
  assert.equal(vm.editForm.code, 'system.user.view')
})

test('SystemDepartmentManagement template compiles and satisfies all UI mandates', () => {
  // 1. Vue compilation check
  const parsed = compiler.parseComponent(deptSource)
  assert.ok(parsed.template, 'SystemDepartmentManagement template should exist')
  const compiled = compiler.compile(parsed.template.content)
  assert.deepEqual(compiled.errors, [], `Template compile errors: ${compiled.errors.join('; ')}`)

  // 2. Absolute ban on el-drawer
  assert.ok(!deptSource.includes('<el-drawer'), 'Must NOT contain el-drawer')

  // 3. Page head and tip banner
  assert.ok(deptSource.includes('class="page-head"'), 'Must have standard .page-head white card')
  assert.ok(deptSource.includes('class="head-icon"'), 'Must have green .head-icon')
  assert.ok(deptSource.includes('class="page-title"'), 'Must have .page-title')
  assert.ok(deptSource.includes('class="erp-page-tip"'), 'Must have standard .erp-page-tip banner')

  // 4. Metric overview cards
  assert.ok(deptSource.includes('class="metric-overview-grid"'), 'Must have .metric-overview-grid')
  assert.ok(deptSource.includes('metric-all'), 'Must have all dept metric card')
  assert.ok(deptSource.includes('metric-enabled'), 'Must have enabled dept metric card')
  assert.ok(deptSource.includes('metric-disabled'), 'Must have disabled dept metric card')
  assert.ok(deptSource.includes('metric-members'), 'Must have members metric card')

  // 5. Default pageSize is 10
  assert.ok(deptSource.includes('pageSize: 10'), 'Default pageSize must be 10 per 2026-10-15 mandate')
})

test('SystemDepartmentManagement script logic and metrics compute accurately', () => {
  const scriptContent = compiler.parseComponent(deptSource).script.content
    .replace(/^import .*$/gm, '')
    .replace('export default', 'return')
  const options = new Function('SystemRecordPicker', 'systemTime', scriptContent)({}, () => '2026-10-15')
  const vm = {
    ...options.data(),
    departments: [
      { legacy_id: 1, name: '总经办', parent_legacy_id: 0, status: 'normal', member_count: 5 },
      { legacy_id: 2, name: '研发部', parent_legacy_id: 1, status: 'normal', member_count: 12 },
      { legacy_id: 3, name: '已停用部门', parent_legacy_id: 1, status: 'hidden', member_count: 0 }
    ],
    memberCountMap: { 1: 5, 2: 12, 3: 0 },
    principalUsers: [{ id: 10, username: 'admin', nickname: '系统管理员' }]
  }
  for (const [name, fn] of Object.entries(options.methods)) vm[name] = fn.bind(vm)
  for (const [name, fn] of Object.entries(options.computed || {})) {
    Object.defineProperty(vm, name, { get: () => fn.call(vm) })
  }

  // Verify computed metrics
  assert.equal(vm.enabledDeptCount, 2)
  assert.equal(vm.disabledDeptCount, 1)
  assert.equal(vm.totalMemberCount, 17)
  assert.equal(vm.principalNames, '系统管理员')
})
