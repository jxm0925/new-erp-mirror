import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import * as scope from '../src/utils/materialManagementScope.mjs'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const source = await readFile(new URL('../src/views/erp/master/ItemForm.vue', import.meta.url), 'utf8')
const parsed = compiler.parseComponent(source)
const compiled = compiler.compile(parsed.template.content)
const script = parsed.script.content
  .replace(/import[\s\S]*?from ['"][^'"]+['"]\s*/g, '').replace('export default', 'return')
const tick = () => new Promise(resolve => setImmediate(resolve))
const routeCases = [
  { route: 'inventory', action: 'inventory_receipt', confirmation: 'none', stock: true, capitalization: false },
  { route: 'expense', action: 'issue_confirmation', confirmation: 'issue', stock: true, capitalization: false },
  { route: 'asset', action: 'asset_acceptance', confirmation: 'asset_acceptance', stock: false, capitalization: true },
  { route: 'direct_expense', action: 'expense_confirmation', confirmation: 'none', stock: false, capitalization: false }
]

function mount(managementScope = 'office', overrides = {}) {
  const calls = { saves: [], reads: [], validation: [] }
  const messages = []
  const routes = []
  const bindings = {
    ...scope, cachedPageRoute: {},
    getItemCategoryTree: async () => ({ data: { data: [] } }),
    listEntity: async () => ({ data: { data: [] } }),
    reserveForCreatePage: async () => ({ document_no: 'ITEM-POLICY-TEST' }),
    clearCreatePageReservation() {},
    getItemIntegratedForm: async (id, params) => {
      calls.reads.push({ id, params })
      return overrides.getItemIntegratedForm(id, params)
    },
    saveItemIntegratedForm: async (id, payload, params) => {
      calls.saves.push({ id, payload: structuredClone(payload), params })
      return { data: { data: { ...payload.item, id: id || 55 }, message: '保存成功' } }
    }
  }
  const component = new Function(...Object.keys(bindings), script)(...Object.values(bindings))
  const route = { path: '/master/items/55/edit', params: { id: 55 }, query: { management_scope: managementScope }, meta: {} }
  const vm = {
    $route: route, pageRoute: route, $can: () => true,
    $router: { push: value => routes.push(value) },
    $message: { error: value => messages.push(value), warning: value => messages.push(value), success() {} },
    $refs: { form: { clearValidate() {}, validate(callback) { calls.validation.push(Promise.resolve(callback(true))) } } }
  }
  Object.assign(vm, component.data.call(vm))
  for (const [key, fn] of Object.entries(component.methods)) vm[key] = fn.bind(vm)
  for (const [key, fn] of Object.entries(component.computed)) {
    Object.defineProperty(vm, key, { get: () => typeof fn === 'function' ? fn.call(vm) : fn.get.call(vm) })
  }
  Object.assign(vm.form, {
    id: 55, management_scope: managementScope,
    item_code: 'ITEM-POLICY-TEST', item_name: managementScope === 'office' ? '办公用品' : '工厂材料',
    item_type: managementScope === 'office' ? 'office_consumable' : 'raw_material', category_id: 1, unit_id: 1
  })
  Object.assign(vm.policy, { template_code: 'inventory_material', serial_tracking_mode: 'none', future_bearer_type: 'department' })
  vm.applyRoute('inventory')
  return { vm, calls, messages, routes }
}

async function save(vm, calls, activate = false) {
  vm.save(activate)
  await Promise.all(calls.validation.splice(0))
}

function nodes(root) {
  const found = []
  const seen = new Set()
  function visit(node) {
    if (!node || seen.has(node)) return
    seen.add(node)
    found.push(node)
    for (const child of node.children || []) visit(child)
    for (const condition of node.ifConditions || []) visit(condition.block)
    for (const child of Object.values(node.scopedSlots || {})) visit(child)
  }
  visit(root)
  return found
}

test('factory and office policy headings, five templates and four routes describe their own scope', () => {
  const factory = mount('factory').vm
  const office = mount('office').vm
  assert.match(factory.policyTitle, /工厂/)
  assert.match(office.policyTitle, /办公/)
  assert.notEqual(factory.policySubtitle, office.policySubtitle)
  assert.doesNotMatch(factory.policySubtitle + office.policySubtitle, /期末财务结转|自动.*资产入账/)
  const templates = ['office_consumable', 'inventory_material', 'low_value_custody', 'fixed_asset_pending', 'direct_non_stock']
  assert.deepEqual(factory.templateOptions.map(row => row.value), templates)
  assert.deepEqual(office.templateOptions.map(row => row.value), templates)
  for (const template of templates) {
    const factoryOption = factory.templateOptions.find(row => row.value === template)
    const officeOption = office.templateOptions.find(row => row.value === template)
    assert.notEqual(factoryOption.label, officeOption.label)
    assert.match(officeOption.label, /办公/)
    assert.doesNotMatch(factoryOption.label, /办公/)
  }
  for (const { route } of routeCases) {
    assert.notEqual(factory.routeOptions.find(row => row.value === route).label, office.routeOptions.find(row => row.value === route).label)
    assert.match(office.routeOptions.find(row => row.value === route).help, /办公/)
  }
})

test('bearer choices distinguish office use from factory work-order and sales-order intent', () => {
  const factory = mount('factory').vm
  const office = mount('office').vm
  assert.deepEqual(factory.bearerOptions.filter(row => !row.disabled).map(row => row.value), ['company', 'department', 'employee', 'work_order', 'sales_order'])
  assert.deepEqual(office.bearerOptions.filter(row => !row.disabled).map(row => row.value), ['company', 'department', 'employee'])
  for (const bearer of ['company', 'department', 'employee']) {
    office.policy.future_bearer_type = bearer
    assert.equal(office.bearerValid, true)
    assert.equal(office.tab2Valid, true)
  }
  for (const bearer of ['work_order', 'sales_order']) {
    office.policy.future_bearer_type = bearer
    assert.equal(office.bearerValid, false)
    assert.equal(office.policyScopeConflict, true)
    assert.equal(office.bearerOptions.find(row => row.value === bearer).disabled, true)
    factory.policy.future_bearer_type = bearer
    assert.equal(factory.bearerValid, true)
  }
})

test('each supported route gives exactly its permitted action and confirmation in both scopes', async () => {
  for (const managementScope of ['factory', 'office']) {
    for (const expected of routeCases) {
      const { vm, calls, messages } = mount(managementScope)
      vm.applyRoute(expected.route)
      assert.deepEqual(vm.actionOptions.filter(row => !row.disabled).map(row => row.value), [expected.action])
      assert.deepEqual(vm.confirmationOptions.filter(row => !row.disabled).map(row => row.value), [expected.confirmation])
      assert.equal(vm.policy.is_stock_managed, expected.stock)
      assert.equal(vm.policy.requires_capitalization, expected.capitalization)
      assert.equal(vm.actionValid, true)
      assert.equal(vm.tab2Valid, true)
      await save(vm, calls, true)
      assert.equal(calls.saves.length, 1)
      const request = calls.saves[0]
      assert.equal(request.payload.item.management_scope, managementScope)
      assert.equal(request.payload.policy.future_route, expected.route)
      assert.equal(request.payload.policy.post_purchase_action, expected.action)
      assert.equal(request.payload.policy.consumption_confirmation_mode, expected.confirmation)
      assert.equal(request.payload.policy.inventory_management_mode, expected.stock ? 'standard' : 'none')
      assert.equal(request.payload.activate, true)
      assert.deepEqual(request.params, { management_scope: managementScope })
      assert.deepEqual(messages, [])
    }
  }
})

test('switching to office preserves valid stock, asset, custody and serial-tracking policy for all four routes', async () => {
  for (const { route } of routeCases) {
    const { vm } = mount('factory')
    vm.applyRoute(route)
    Object.assign(vm.policy, {
      requires_custodian: true, is_returnable: true, future_bearer_type: 'employee',
      serial_tracking_mode: 'required', serial_generation_stage: 'before_finished_goods_posting',
      serial_generation_routing_operation_id: null, remark: '保留责任与资产配置'
    })
    const policy = structuredClone(vm.policy)
    Object.assign(vm.form, { management_scope: 'office', is_production_item: true, manufacturing_strategy: 'make', cutting_mode: 'length', standard_stock_length_mm: 6000 })
    vm.changeScope('office')
    await tick()
    assert.deepEqual(vm.policy, policy)
    assert.equal(vm.form.is_production_item, false)
    assert.equal(vm.form.manufacturing_strategy, 'unspecified')
    assert.equal(vm.form.cutting_mode, 'none')
    assert.equal(vm.form.standard_stock_length_mm, null)
    assert.equal(vm.policyScopeConflict, false)
  }
})

test('switching to office clears incompatible work-order and sales-order values without inventing a replacement', async () => {
  for (const [bearer, route] of [['work_order', 'work_order_cost'], ['sales_order', 'sales_order_direct_cost']]) {
    const { vm } = mount('factory')
    Object.assign(vm.policy, {
      future_bearer_type: bearer, future_route: route, post_purchase_action: route,
      is_stock_managed: false, inventory_management_mode: 'none', requires_custodian: true, is_returnable: true,
      serial_generation_stage: 'routing_operation_completed', serial_generation_routing_operation_id: 91
    })
    vm.form.management_scope = 'office'
    vm.changeScope('office')
    await tick()
    assert.equal(vm.policy.future_bearer_type, '')
    assert.equal(vm.policy.future_route, '')
    assert.equal(vm.policy.post_purchase_action, '')
    assert.equal(vm.policy.serial_generation_stage, 'before_finished_goods_posting')
    assert.equal(vm.policy.serial_generation_routing_operation_id, null)
    assert.equal(vm.policy.is_stock_managed, false)
    assert.equal(vm.policy.requires_custodian, true)
    assert.equal(vm.policy.is_returnable, true)
    assert.equal(vm.tab2Valid, false)
  }
})

test('an office scope correction removes only production numbering from an otherwise valid asset policy', () => {
  const { vm } = mount('office')
  vm.applyRoute('asset')
  Object.assign(vm.policy, { requires_custodian: true, is_returnable: true, serial_tracking_mode: 'required', serial_generation_stage: 'production_unit_created', serial_generation_routing_operation_id: 91 })
  const before = structuredClone(vm.policy)
  assert.equal(vm.policyScopeConflict, true)
  vm.clearIncompatibleOfficePolicy()
  assert.deepEqual(vm.policy, { ...before, serial_generation_stage: 'before_finished_goods_posting', serial_generation_routing_operation_id: null })
  assert.equal(vm.policyScopeConflict, false)
  assert.equal(vm.tab2Valid, true)
})

test('legacy office configuration remains readable and blocks saving until its bearer is explicitly corrected', async () => {
  const legacy = {
    template_code: 'fixed_asset_pending', is_stock_managed: false, inventory_management_mode: 'none',
    requires_custodian: true, is_returnable: true, requires_capitalization: true, serial_tracking_mode: 'required',
    future_bearer_type: 'work_order', future_route: 'asset', post_purchase_action: 'asset_acceptance', consumption_confirmation_mode: 'asset_acceptance'
  }
  const { vm, calls, messages } = mount('office', {
    getItemIntegratedForm: async id => ({ data: {
      item: { id, item_code: 'OFFICE-LEGACY', item_name: '旧办公设备', management_scope: 'office', item_type: 'office_consumable', category_id: 1, unit_id: 1, cutting_mode: 'none' },
      policy: { active: structuredClone(legacy) }, balance: {}, history: { data: [] }
    } })
  })
  await vm.loadEdit()
  assert.equal(vm.policy.future_bearer_type, 'work_order')
  assert.equal(vm.policy.future_route, 'asset')
  assert.equal(vm.bearerOptions.find(row => row.value === 'work_order').disabled, true)
  assert.equal(vm.policyScopeConflict, true)
  vm.currentTab = 'policy'
  await save(vm, calls)
  assert.equal(calls.saves.length, 0)
  assert.match(messages.at(-1), /办公用品不能使用工单、订单或生产归属配置/)
  assert.equal(vm.currentTab, 'policy')
  vm.clearIncompatibleOfficePolicy()
  assert.equal(vm.policy.future_bearer_type, '')
  assert.equal(vm.bearerValid, false)
  assert.equal(vm.policy.future_route, 'asset')
  await save(vm, calls)
  assert.equal(calls.saves.length, 0)
  vm.policy.future_bearer_type = 'department'
  await save(vm, calls)
  assert.equal(calls.saves.length, 1)
  assert.equal(calls.saves[0].payload.policy.future_bearer_type, 'department')
  assert.equal(calls.saves[0].payload.policy.future_route, 'asset')
  assert.equal(calls.saves[0].payload.policy.requires_custodian, true)
})

test('mismatched historical action and confirmation are visible as disabled values and cannot be saved', async () => {
  for (const managementScope of ['factory', 'office']) {
    const { vm, calls, messages } = mount(managementScope)
    vm.applyRoute('asset')
    vm.policy.post_purchase_action = 'expense_confirmation'
    vm.policy.consumption_confirmation_mode = 'service_acceptance'
    assert.equal(vm.actionOptions.find(row => row.value === 'expense_confirmation').disabled, true)
    assert.equal(vm.confirmationOptions.find(row => row.value === 'service_acceptance').disabled, true)
    assert.equal(vm.preview.purpose, '')
    assert.equal(vm.preview.next, '')
    assert.equal(vm.actionValid, false)
    assert.equal(vm.tab2Valid, false)
    await save(vm, calls)
    assert.equal(calls.saves.length, 0)
    assert.match(messages.at(-1), /采购后处理或确认方式/)
  }
})

test('confirmation preview follows the confirmation rule independently of custody', () => {
  for (const managementScope of ['factory', 'office']) {
    const { vm } = mount(managementScope)
    vm.applyRoute('expense')
    vm.policy.requires_custodian = false
    assert.match(vm.preview.next, /领用确认/)
    const issue = vm.preview.next
    vm.policy.requires_custodian = true
    assert.equal(vm.preview.next, issue)
    vm.applyRoute('inventory')
    assert.equal(vm.policy.requires_custodian, true)
    assert.equal(vm.preview.next, '无需领用确认')
    assert.notEqual(vm.preview.next, issue)
  }
})

test('clearing incompatible office policy does not rewrite factory configuration', () => {
  const { vm } = mount('factory')
  Object.assign(vm.policy, { future_bearer_type: 'work_order', serial_generation_stage: 'routing_operation_completed', serial_generation_routing_operation_id: 91 })
  const before = structuredClone(vm.policy)
  vm.clearIncompatibleOfficePolicy()
  assert.deepEqual(vm.policy, before)
  assert.equal(vm.bearerValid, true)
  assert.equal(vm.policyScopeConflict, false)
})

test('compiled policy form binds real editable fields, exposes correction and renders the actual confirmation preview', () => {
  assert.deepEqual(compiled.errors, [])
  const all = nodes(compiled.ast)
  const panel = all.find(node => node.tag === 'div' && node.attrsMap?.['v-show'] === "currentTab === 'policy'")
  assert.ok(panel)
  const policyNodes = nodes(panel)
  assert.equal(policyNodes.some(node => node.tag === 'el-form-item' && node.attrsMap?.label === '默认承担部门'), false)
  for (const node of policyNodes.filter(row => ['el-input', 'el-select', 'el-switch'].includes(row.tag))) {
    assert.match(node.attrsMap['v-model'] || '', /^policy\./, `unbound ${node.tag} in policy form`)
  }
  const scopeAlert = policyNodes.find(node => node.tag === 'el-alert' && node.attrsMap?.['v-if'] === 'policyScopeConflict')
  assert.ok(scopeAlert)
  assert.match(scopeAlert.attrsMap.title, /不适用于办公用品/)
  assert.ok(nodes(scopeAlert).some(node => node.tag === 'el-button' && node.attrsMap?.['@click'] === 'clearIncompatibleOfficePolicy'))
  for (const expression of ['bearer in bearerOptions', 'action in actionOptions', 'confirmation in confirmationOptions']) {
    const option = policyNodes.find(node => node.tag === 'el-option' && node.attrsMap?.['v-for'] === expression)
    assert.ok(option)
    assert.match(option.attrsMap[':disabled'], /\.disabled$/)
  }
  const flow = policyNodes.find(node => node.attrsMap?.class === 'flowchart-steps')
  const confirmation = nodes(flow).filter(node => node.attrsMap?.class === 'flow-step-label')[2]
  assert.ok(nodes(confirmation).some(node => node.type === 2 && node.expression.includes('preview.next')))
  assert.equal(nodes(confirmation).some(node => (node.expression || '').includes('custodian')), false)
  const visibleText = policyNodes.map(node => node.text || '').join(' ')
  assert.match(visibleText, /policyTitle/)
  assert.doesNotMatch(visibleText, /物资归属与经济结算|期末财务结转|工单\/订单成本归集意向/)
})
