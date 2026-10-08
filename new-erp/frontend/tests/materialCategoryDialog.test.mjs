import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import * as scope from '../src/utils/materialManagementScope.mjs'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const parser = require('@babel/parser')
const paths = {
  Dialog: 'components/master/ItemCategoryManagerDialog.vue',
  Category: 'views/erp/master/ItemCategoryList.vue',
  List: 'views/erp/master/ItemList.vue',
  Form: 'views/erp/master/ItemForm.vue'
}
const sources = new Map()
async function loadComponent(name) {
  if (!sources.has(name)) {
    const source = await readFile(new URL('../src/' + paths[name], import.meta.url), 'utf8')
    const parsed = compiler.parseComponent(source)
    sources.set(name, {
      source, template: compiler.compile(parsed.template.content),
      script: parsed.script.content.replace(/import[\s\S]*?from ['"][^'"]+['"]\s*/g, '').replace('export default', 'return')
    })
  }
  return sources.get(name)
}
const tick = () => new Promise(resolve => setImmediate(resolve))
function deferred() {
  let resolve, reject
  const promise = new Promise((done, fail) => { resolve = done; reject = fail })
  return { promise, resolve, reject }
}
const category = (id, management_scope, parent_id = null, children = []) => ({
  id, management_scope, parent_id, children, category_code: 'CAT-' + id,
  category_name: (management_scope === 'office' ? '办公分类' : '工厂分类') + id,
  full_path: '分类/' + id, status: 'enabled', sort_order: id, is_leaf: children.length === 0
})
const flat = rows => rows.flatMap(row => [row, ...flat(row.children || [])])

async function mount(name, options = {}) {
  const loaded = await loadComponent(name)
  const permissions = options.permissions || ['master.item.view', 'item_category.view', 'item_category.manage']
  const profile = options.profile || {}
  const state = {
    trees: [category(1, 'factory', null, [category(11, 'factory', 1)]), category(2, 'office', null, [category(22, 'office', 2)])],
    calls: {}, events: [], routes: [], errors: [], warnings: [], validations: [],
    validationErrors: [], validationClearCount: 0, number: 0
  }
  const response = data => ({ data: { data: structuredClone(data) } })
  const api = (name, implementation) => async (...args) => {
    ;(state.calls[name] ||= []).push(structuredClone(args))
    if (options.api?.[name]) return options.api[name](...args)
    return implementation(...args)
  }
  const reserve = (type, page) => {
    const number = ++state.number
    return { document_no: 'CAT-NEW-' + number, reservation_token: 'token-' + number, creation_session_id: 'session-' + number, storage_key: page }
  }
  const localStorage = { getItem: key => key === 'erp_permissions' ? JSON.stringify(permissions) : key === 'erp_me' ? JSON.stringify(profile) : 'test-token' }
  const bindings = {
    ...scope, cachedPageRoute: {}, ItemCategoryManagerDialog: {}, ItemCategoryList: {}, localStorage,
    getItemCategoryTree: api('tree', params => response(params?.management_scope ? state.trees.filter(row => row.management_scope === params.management_scope) : state.trees)),
    listItemCategories: api('categories', params => {
      const rows = flat(state.trees).filter(row => (!params.management_scope || row.management_scope === params.management_scope)
        && (params.parent_id ? Number(row.parent_id) === Number(params.parent_id) : row.parent_id === null))
      return { data: { data: structuredClone(rows.slice((params.page - 1) * params.per_page, params.page * params.per_page)), total: rows.length } }
    }),
    getItemCategory: api('category', id => response(flat(state.trees).find(row => Number(row.id) === Number(id)) || { id })),
    saveItemCategory: api('save', payload => {
      const row = { ...payload, id: payload.id || 3, children: [], is_leaf: true }
      delete row.reservation_token
      delete row.creation_session_id
      const old = flat(state.trees).find(value => Number(value.id) === Number(row.id))
      if (old) Object.assign(old, row)
      else if (row.parent_id) flat(state.trees).find(parent => parent.id === row.parent_id).children.push(row)
      else state.trees.push(row)
      return response(row)
    }),
    disableItemCategory: api('disable', id => { const row = flat(state.trees).find(value => value.id === id); if (row) row.status = 'disabled' }),
    enableItemCategory: api('enable', id => { const row = flat(state.trees).find(value => value.id === id); if (row) row.status = 'enabled' }),
    deleteItemCategory: api('delete', id => { state.trees = state.trees.filter(row => row.id !== id) }),
    reserveForCreatePage: api('reserve', reserve), reserveFreshDocumentNumber: api('fresh', reserve),
    clearCreatePageReservation: value => { if (value) (state.calls.cleared ||= []).push(structuredClone(value)) },
    listEntity: api('entities', (entity, params) => ({ data: { data: [], total: 0, stats: { total: 0 } } })),
    getEntity: api('item', (entity, id) => ({ data: { id, management_scope: 'factory' } })),
    listItemPurchaseConversions: api('conversions', () => ({ data: { data: [], total: 0 } })),
    getItemIntegratedForm: api('integrated', id => ({ data: { item: { id, management_scope: 'office' }, policy: {}, balance: {}, history: { data: [] } } })),
    saveItemIntegratedForm: api('itemSave', () => { throw new Error('Unexpected Item save in a category test') })
  }
  const component = new Function(...Object.keys(bindings), loaded.script)(...Object.values(bindings))
  const route = options.route || { path: name === 'Form' ? '/master/items/new' : '/master/items', query: {}, params: {}, meta: {} }
  const vm = {
    $route: route, pageRoute: route,
    $can: code => !!profile.is_super_admin || (Array.isArray(code) ? code.some(value => permissions.includes(value)) : permissions.includes(code)),
    $router: { push: value => state.routes.push(value), replace: value => state.routes.push(value) },
    $emit: (event, ...args) => state.events.push({ event, args: structuredClone(args) }),
    $confirm: async () => {}, $nextTick: fn => Promise.resolve().then(() => fn ? fn() : undefined),
    $set: (row, key, value) => { row[key] = value },
    $delete: (row, key) => { delete row[key] },
    $message: { error: value => state.errors.push(value), warning: value => state.warnings.push(value), success() {} },
    $refs: { form: {
      clearValidate() { state.validationErrors = []; state.validationClearCount++ },
      validate(fn) { const result = Promise.resolve(fn(true)); state.validations.push(result); return result }
    }, categoryTree: { setCurrentKey() {}, filter() {} } },
    component, state
  }
  for (const [key, descriptor] of Object.entries(component.props || {})) {
    vm[key] = typeof descriptor === 'object' && 'default' in descriptor ? descriptor.default : undefined
  }
  Object.assign(vm, options.props || {})
  for (const [key, fn] of Object.entries(component.methods || {})) vm[key] = fn.bind(vm)
  if (component.data) Object.assign(vm, component.data.call(vm))
  for (const [key, fn] of Object.entries(component.computed || {})) {
    Object.defineProperty(vm, key, {
      get: () => typeof fn === 'function' ? fn.call(vm) : fn.get.call(vm),
      ...(typeof fn.set === 'function' ? { set: value => fn.set.call(vm, value) } : {})
    })
  }
  return vm
}

function astNodes(root) {
  const result = [], seen = new Set()
  function visit(node) {
    if (!node || seen.has(node)) return
    seen.add(node); result.push(node)
    for (const child of node.children || []) visit(child)
    for (const condition of node.ifConditions || []) visit(condition.block)
    for (const child of Object.values(node.scopedSlots || {})) visit(child)
  }
  visit(root)
  return result
}
function callHook(vm, name) {
  for (const hook of [].concat(vm.component[name] || [])) hook.call(vm)
}
function callWatcher(vm, name, value) {
  const watcher = vm.component.watch[name]
  return (typeof watcher === 'function' ? watcher : watcher.handler).call(vm, value)
}
function managerNode(loaded) {
  return astNodes(loaded.template.ast).find(node => node.tag === 'item-category-manager-dialog' || node.tag === 'ItemCategoryManagerDialog')
}
async function emitToHost(vm, node, event, value) {
  const expression = node.attrsMap['@' + event]
  assert.ok(expression, `missing ${event} handler`)
  const method = expression.match(/^\w+$/) ? vm[expression] : undefined
  const result = method ? method(value) : new Function('$event', `with(this) { return ${expression} }`).call(vm, value)
  await result
  await tick()
}

test('compiled dialog owns a scoped disposable manager and both hosts bind its events', async () => {
  const loaded = await loadComponent('Dialog')
  assert.deepEqual(loaded.template.errors, [])
  const nodes = astNodes(loaded.template.ast)
  const dialog = nodes.find(node => node.tag === 'el-dialog')
  assert.equal(dialog.attrsMap['destroy-on-close'], '')
  assert.equal(dialog.attrsMap[':before-close'], 'close')
  assert.equal(dialog.attrsMap['@closed'], 'onClosed')
  const child = nodes.find(node => node.tag === 'ItemCategoryList')
  assert.equal(child.if, 'visible && canViewCategories')
  assert.equal(child.attrsMap.ref, 'categoryManager')
  assert.equal(child.attrsMap[':embedded'], 'true')
  assert.equal(child.attrsMap[':initial-management-scope'], 'managementScope')
  assert.equal(child.attrsMap['@changed'], 'onChanged')
  assert.equal(child.attrsMap['@select-items'], 'showItems')
  for (const name of ['List', 'Form']) {
    const host = await loadComponent(name)
    assert.deepEqual(host.template.errors, [])
    const node = managerNode(host)
    assert.ok(node)
    assert.equal(node.attrsMap['v-model'], 'categoryDialogVisible')
    assert.equal(node.attrsMap[':management-scope'], name === 'List' ? 'categoryDialogScope' : 'managementScope')
    assert.equal(node.attrsMap['@changed'], 'onCategoriesChanged')
    assert.equal(node.attrsMap['@closed'], 'onCategoriesClosed')
    assert.equal(node.attrsMap['@select-items'], 'showCategoryItems')
  }
})

test('dialog requires category view permission and blocks closing while a write is active', async () => {
  const denied = await mount('Dialog', { permissions: ['master.item.view'], props: { value: true } })
  assert.equal(denied.canViewCategories, false)
  const readOnly = await mount('Dialog', { permissions: ['item_category.view'], props: { value: true } })
  assert.equal(readOnly.canViewCategories, true)
  readOnly.$refs.categoryManager = { saving: true }
  let closed = 0
  readOnly.close(() => { closed++ })
  readOnly.showItems(category(22, 'office'))
  assert.equal(closed, 0)
  assert.deepEqual(readOnly.state.events, [])
  assert.equal(readOnly.state.warnings.length, 2)
  readOnly.$refs.categoryManager.saving = false
  readOnly.close(() => { closed++ })
  assert.equal(closed, 1)
  assert.deepEqual(readOnly.state.events, [{ event: 'input', args: [false] }])
})

test('dialog forwards changed, selected items and its completed close in order', async () => {
  const vm = await mount('Dialog', { props: { value: true } })
  const change = { management_scope: 'office', id: 22, action: 'enabled' }
  vm.onChanged(change)
  vm.showItems(category(22, 'office'))
  vm.onClosed()
  assert.deepEqual(vm.state.events.map(value => value.event), ['changed', 'select-items', 'input', 'closed'])
  assert.deepEqual(vm.state.events[0].args, [change])
  assert.equal(vm.state.events[1].args[0].management_scope, 'office')
  assert.equal(vm.state.events[2].args[0], false)
})

test('embedded manager initializes from host scope and ignores route scope changes', async () => {
  const vm = await mount('Category', {
    props: { embedded: true, initialManagementScope: 'office' },
    route: { path: '/master/items', query: { management_scope: 'factory' }, params: {} }
  })
  assert.equal(vm.query.management_scope, 'office')
  await vm.initialize()
  assert.deepEqual(vm.tree.map(row => row.id), [2])
  assert.deepEqual(vm.state.calls.tree[0], [{ management_scope: 'office' }])
  await callWatcher(vm, 'pageRoute.query.management_scope', 'factory')
  assert.equal(vm.query.management_scope, 'office')
  assert.equal(vm.state.calls.tree.length, 1)
  assert.deepEqual(vm.state.routes, [])
})

test('embedded related-items action carries the actual row scope and requires item view', async () => {
  const vm = await mount('Category', { props: { embedded: true, initialManagementScope: 'factory' } })
  vm.goItems(category(22, 'office'))
  assert.deepEqual(vm.state.routes, [])
  assert.equal(vm.state.events[0].event, 'select-items')
  assert.equal(vm.state.events[0].args[0].management_scope, 'office')
  const readOnly = await mount('Category', { permissions: ['item_category.view'], props: { embedded: true } })
  assert.equal(readOnly.canManage, false)
  readOnly.goItems(category(22, 'office'))
  assert.deepEqual(readOnly.state.events, [])
  assert.deepEqual(readOnly.state.routes, [])
})

test('category save, enable, disable and delete notify the host with the changed scope', async () => {
  const vm = await mount('Category', { props: { embedded: true, initialManagementScope: 'office' } })
  await vm.initialize()
  await vm.openCreate()
  vm.form.category_name = '新办公分类'
  await vm.save()
  assert.deepEqual(vm.state.calls.reserve[0], ['item_category', '/master/categories#create:office'])
  assert.equal(vm.state.calls.save[0][0].management_scope, 'office')
  assert.equal(vm.state.calls.save[0][0].reservation_token, 'token-1')
  assert.deepEqual(vm.state.events.at(-1), { event: 'changed', args: [{ management_scope: 'office', id: 3, action: 'saved' }] })
  const created = vm.tree.find(row => row.id === 3)
  await vm.toggleStatus(created)
  assert.equal(vm.state.events.at(-1).args[0].action, 'disabled')
  await vm.toggleStatus({ ...created, status: 'disabled' })
  assert.equal(vm.state.events.at(-1).args[0].action, 'enabled')
  await vm.deleteCategory({ ...created, status: 'disabled' })
  assert.deepEqual(vm.state.events.at(-1).args[0], { management_scope: 'office', id: 3, action: 'deleted' })
  assert.deepEqual(vm.state.calls.delete[0], [3, { management_scope: 'office' }])
  assert.deepEqual(vm.state.routes, [])
})

test('failed category save keeps the form open and does not mark host options dirty', async () => {
  const vm = await mount('Category', { props: { embedded: true }, api: { save: async () => { throw { userMessage: '保存被拒绝' } } } })
  await vm.openCreate()
  vm.form.category_name = '尚未保存'
  await vm.save()
  assert.equal(vm.drawerVisible, true)
  assert.equal(vm.saving, false)
  assert.deepEqual(vm.state.errors, ['保存被拒绝'])
  assert.deepEqual(vm.state.events, [])
})

test('new category selection excludes creation-only reservation fields', async () => {
  const vm = await mount('Category', { props: { embedded: true, initialManagementScope: 'office' } })
  await vm.openCreate()
  vm.form.category_name = '立即继续维护的新分类'
  await vm.save()
  assert.equal(vm.state.calls.save[0][0].reservation_token, 'token-1')
  assert.equal(vm.state.calls.save[0][0].creation_session_id, 'session-1')
  assert.equal(vm.selected.id, 3)
  assert.equal(vm.selected.management_scope, 'office')
  assert.equal(Object.hasOwn(vm.selected, 'reservation_token'), false)
  assert.equal(Object.hasOwn(vm.selected, 'creation_session_id'), false)
  await vm.openEdit(vm.selected)
  assert.equal(Object.hasOwn(vm.form, 'reservation_token'), false)
  assert.equal(Object.hasOwn(vm.form, 'creation_session_id'), false)
})

test('editing a category removes residual reservation fields from the update request', async () => {
  const vm = await mount('Category', { props: { embedded: true, initialManagementScope: 'office' } })
  await vm.openEdit({ ...category(22, 'office', 2), reservation_token: 'stale-create-token', creation_session_id: 'stale-create-session' })
  vm.form.category_name = '修改后的办公分类'
  await vm.save()
  const [payload, scopeParams] = vm.state.calls.save[0]
  assert.equal(payload.id, 22)
  assert.equal(payload.category_name, '修改后的办公分类')
  assert.equal(payload.management_scope, 'office')
  assert.deepEqual(scopeParams, { management_scope: 'office' })
  assert.equal(Object.hasOwn(payload, 'reservation_token'), false)
  assert.equal(Object.hasOwn(payload, 'creation_session_id'), false)
  assert.deepEqual(vm.state.errors, [])
  assert.deepEqual(vm.state.events.at(-1).args[0], { management_scope: 'office', id: 22, action: 'saved' })
})

test('reopening category create and edit forms clears validation from the previous form', async () => {
  const vm = await mount('Category', { props: { embedded: true, initialManagementScope: 'office' } })
  vm.state.validationErrors = ['category_name is required', '系统编号生成失败，请重新打开新增页']
  await vm.openCreate()
  await tick()
  assert.equal(vm.form.category_code, 'CAT-NEW-1')
  assert.equal(vm.form.management_scope, 'office')
  assert.deepEqual(vm.state.validationErrors, [])
  assert.equal(vm.state.validationClearCount, 1)
  vm.state.validationErrors = ['上次表单的分类名称错误']
  await vm.openEdit(category(22, 'office', 2))
  await tick()
  assert.equal(vm.form.id, 22)
  assert.equal(vm.form.category_code, 'CAT-22')
  assert.deepEqual(vm.state.validationErrors, [])
  assert.equal(vm.state.validationClearCount, 2)
})

test('destroying a manager invalidates pending tree, list, detail and reservation responses', async () => {
  const tree = deferred(), rows = deferred(), detail = deferred(), number = deferred()
  const vm = await mount('Category', {
    props: { embedded: true, initialManagementScope: 'office' },
    api: { tree: () => tree.promise, categories: () => rows.promise, category: () => detail.promise, reserve: () => number.promise }
  })
  vm.drawerVisible = true
  vm.form.management_scope = 'office'
  const tasks = [vm.loadTree(), vm.loadChildren(), vm.selectCategory(category(2, 'office')), vm.generateNumber()]
  const oldVersions = [vm.treeLoadVersion, vm.listLoadVersion, vm.selectionVersion, vm.numberVersion]
  callHook(vm, 'beforeDestroy')
  assert.equal(vm.isDisposed, true)
  assert.equal(vm.drawerVisible, false)
  assert.ok([vm.treeLoadVersion, vm.listLoadVersion, vm.selectionVersion, vm.numberVersion].every((value, index) => value > oldVersions[index]))
  const reservation = { document_no: 'STALE', reservation_token: 'old-token', storage_key: 'old-page' }
  tree.resolve({ data: { data: [category(99, 'office')] } })
  rows.resolve({ data: { data: [category(99, 'office')], total: 1 } })
  detail.resolve({ data: { data: category(99, 'office') } })
  number.resolve(reservation)
  await Promise.all(tasks)
  assert.deepEqual(vm.tree, [])
  assert.deepEqual(vm.rows, [])
  assert.deepEqual(vm.selected, {})
  assert.equal(vm.reservation, null)
  assert.equal(vm.form.category_code, '')
  assert.deepEqual(vm.state.calls.cleared.at(-1), reservation)
  assert.deepEqual(vm.state.events, [])
  const reopened = await mount('Category', { props: { embedded: true, initialManagementScope: 'factory' } })
  await reopened.initialize()
  assert.deepEqual(reopened.tree.map(row => row.id), [1])
  assert.equal(reopened.reservation, null)
})

test('scope change and inner close discard late category numbers from the old scope', async () => {
  const first = deferred(), second = deferred()
  let count = 0
  const vm = await mount('Category', { props: { embedded: true }, api: { reserve: () => (++count === 1 ? first : second).promise } })
  vm.loadedScopes = { factory: true, office: true }
  const opening = vm.openCreate()
  const switching = vm.changeFormScope('office')
  second.resolve({ document_no: 'OFFICE', reservation_token: 'office-token', storage_key: 'office-page' })
  await switching
  assert.equal(vm.form.category_code, 'OFFICE')
  first.resolve({ document_no: 'FACTORY', reservation_token: 'factory-token', storage_key: 'factory-page' })
  await opening
  assert.equal(vm.form.category_code, 'OFFICE')
  assert.ok(vm.state.calls.cleared.some(value => value.reservation_token === 'factory-token'))
  vm.drawerVisible = false
  vm.closeForm()
  assert.equal(vm.reservation, null)
  assert.ok(vm.state.calls.cleared.some(value => value.reservation_token === 'office-token'))
})

test('disposed category save conflicts cannot start another number reservation', async () => {
  const saving = deferred()
  const vm = await mount('Category', { props: { embedded: true }, api: { save: () => saving.promise } })
  await vm.openCreate()
  vm.form.category_name = '关闭前发起保存'
  const task = vm.save()
  callHook(vm, 'beforeDestroy')
  saving.reject({ response: { data: { errors: { reservation_token: ['编号失效'] } } } })
  await task
  assert.equal(vm.state.calls.reserve.length, 1)
  assert.equal(vm.state.calls.fresh, undefined)
  assert.equal(vm.reservation, null)
  assert.equal(vm.drawerVisible, false)
  assert.deepEqual(vm.state.events, [])
  await vm.generateNumber()
  await vm.refreshGeneratedNumber({ response: { data: { errors: { category_code: ['冲突'] } } } })
  assert.equal(vm.state.calls.reserve.length, 1)
  assert.equal(vm.state.calls.fresh, undefined)
})

test('category status and deletion keep the close lock until the host is notified', async () => {
  for (const operation of ['disabled', 'enabled', 'deleted']) {
    const pending = deferred()
    const apiKey = { disabled: 'disable', enabled: 'enable', deleted: 'delete' }[operation]
    const vm = await mount('Category', { props: { embedded: true }, api: { [apiKey]: () => pending.promise } })
    const wrapper = await mount('Dialog', { props: { value: true } })
    wrapper.$refs.categoryManager = vm
    const row = { ...category(2, 'office'), status: operation === 'disabled' ? 'enabled' : 'disabled' }
    const task = operation === 'deleted' ? vm.deleteCategory(row) : vm.toggleStatus(row)
    await tick()
    assert.equal(vm.saving, true, operation + ' must hold the close lock')
    wrapper.close()
    assert.deepEqual(wrapper.state.events, [], operation + ' must not close while pending')
    assert.equal(wrapper.state.warnings.length, 1)
    pending.resolve()
    await task
    assert.equal(vm.saving, false)
    assert.deepEqual(vm.state.events.at(-1).args[0], { management_scope: 'office', id: 2, action: operation })
    wrapper.close()
    assert.deepEqual(wrapper.state.events, [{ event: 'input', args: [false] }])
  }
})

test('category-only permission opens the manager without material or selector API calls', async () => {
  const vm = await mount('List', { permissions: ['item_category.view'] })
  callHook(vm, 'created')
  await tick()
  assert.equal(vm.canViewItems, false)
  assert.equal(vm.categoryDialogVisible, true)
  assert.deepEqual(vm.state.calls, {})
  await vm.openDetail({ id: 5 })
  await vm.loadConversions()
  vm.openCreate(); vm.openEdit({ id: 5 }); vm.openImport(); vm.showCategoryItems(category(22, 'office'))
  await tick()
  assert.deepEqual(vm.state.calls, {})
  assert.deepEqual(vm.state.routes, [])
  const template = (await loadComponent('List')).template
  const nodes = astNodes(template.ast)
  for (const className of ['metric-overview-grid', 'table-container-card']) {
    const section = nodes.find(node => node.attrsMap?.class === className)
    assert.equal(section.if, 'canViewItems')
  }
  const action = nodes.find(node => node.attrsMap?.['@click'] === 'openCategories')
  assert.equal(action.if, 'canViewCategories')
})

test('item view without category view neither opens nor queries category maintenance', async () => {
  const vm = await mount('List', { permissions: ['master.item.view'], route: { path: '/master/items', query: { manage_categories: '1' }, params: {} } })
  callHook(vm, 'created')
  await tick()
  vm.openCategories()
  assert.equal(vm.categoryDialogVisible, false)
  assert.equal(vm.state.calls.tree, undefined)
  assert.deepEqual(vm.state.calls.entities.map(args => args[0]).sort(), ['items', 'suppliers', 'units'])
})

test('manager opens from local list scope without routing and refreshes only after changes', async () => {
  const vm = await mount('List', { route: { path: '/master/items', query: { management_scope: 'factory' }, params: {} } })
  vm.query.management_scope = 'office'
  vm.query.keyword = '保留筛选'
  vm.query.category_id = 22
  vm.query.page = 4
  vm.openCategories()
  assert.equal(vm.categoryDialogScope, 'office')
  assert.deepEqual(vm.state.routes, [])
  const node = managerNode(await loadComponent('List'))
  await emitToHost(vm, node, 'closed')
  assert.deepEqual(vm.state.calls, {})
  vm.state.trees = [category(2, 'office')]
  await emitToHost(vm, node, 'changed', { management_scope: 'office', id: 22, action: 'deleted' })
  assert.equal(vm.categoriesDirty, true)
  await emitToHost(vm, node, 'closed')
  assert.equal(vm.categoriesDirty, false)
  assert.equal(vm.query.keyword, '保留筛选')
  assert.equal(vm.query.category_id, '')
  assert.equal(vm.query.page, 4)
  assert.deepEqual(vm.state.calls.tree[0], [{ management_scope: 'office' }])
  const itemParams = vm.state.calls.entities.find(args => args[0] === 'items')[1]
  assert.equal(itemParams.management_scope, 'office')
  assert.equal(itemParams.keyword, '保留筛选')
  assert.deepEqual(vm.state.routes, [])
})

test('selecting related items updates the current list from the actual category scope', async () => {
  const vm = await mount('List')
  vm.query.item_type = 'raw_material'
  vm.query.page = 8
  await emitToHost(vm, managerNode(await loadComponent('List')), 'select-items', category(22, 'office'))
  assert.equal(vm.query.management_scope, 'office')
  assert.equal(vm.query.category_id, 22)
  assert.equal(vm.query.item_type, '')
  assert.equal(vm.query.page, 1)
  assert.deepEqual(vm.state.routes, [])
  const itemParams = vm.state.calls.entities.find(args => args[0] === 'items')[1]
  assert.equal(itemParams.management_scope, 'office')
  assert.equal(itemParams.category_id, 22)
})

test('category maintenance preserves unsaved material fields, policy and item reservation', async () => {
  const vm = await mount('Form')
  Object.assign(vm.form, { management_scope: 'office', item_code: 'ITEM-UNSAVED', item_name: '待保存办公用品', spec: 'A4', remark: '尚未提交', unit_id: 8 })
  Object.assign(vm.policy, { template_code: 'office_consumable', future_route: 'expense', requires_custodian: true, change_reason: '未保存设置' })
  vm.reservation = { document_no: 'ITEM-UNSAVED', reservation_token: 'item-token', creation_session_id: 'item-session', storage_key: 'item-page' }
  vm.currentTab = 'policy'
  const original = structuredClone({ form: vm.form, policy: vm.policy, reservation: vm.reservation, currentTab: vm.currentTab })
  vm.openCategories()
  assert.equal(vm.categoryDialogVisible, true)
  assert.deepEqual(vm.state.routes, [])
  vm.state.trees.push(category(3, 'office'))
  const node = managerNode(await loadComponent('Form'))
  await emitToHost(vm, node, 'changed', { management_scope: 'office', id: 3, action: 'saved' })
  await emitToHost(vm, node, 'closed')
  assert.equal(vm.form.category_id, 3)
  assert.deepEqual({ ...vm.form, category_id: null }, original.form)
  assert.deepEqual(vm.policy, original.policy)
  assert.deepEqual(vm.reservation, original.reservation)
  assert.equal(vm.currentTab, original.currentTab)
  assert.equal(vm.state.calls.itemSave, undefined)
  assert.equal(vm.state.calls.reserve, undefined)
  assert.equal(vm.state.calls.cleared, undefined)
  assert.deepEqual(vm.state.routes, [])
})

test('form does not select a saved category from another scope or reload on a clean close', async () => {
  const vm = await mount('Form')
  vm.form.management_scope = 'office'
  vm.openCategories()
  const node = managerNode(await loadComponent('Form'))
  await emitToHost(vm, node, 'closed')
  assert.deepEqual(vm.state.calls, {})
  await emitToHost(vm, node, 'changed', { management_scope: 'factory', id: 11, action: 'saved' })
  await emitToHost(vm, node, 'closed')
  assert.equal(vm.form.category_id, null)
  assert.ok(vm.categories.every(row => row.management_scope === 'office'))
  assert.deepEqual(vm.state.routes, [])
})

test('legacy category URLs resolve through the real router to the scoped material dialog', async () => {
  const source = await readFile(new URL('../src/main.js', import.meta.url), 'utf8')
  const program = parser.parse(source, { sourceType: 'module' }).program
  const init = program.body.filter(node => node.type === 'VariableDeclaration')
    .flatMap(node => node.declarations).find(node => node.id.name === 'router').init
  const bindings = Object.fromEntries(program.body.filter(node => node.type === 'ImportDeclaration')
    .flatMap(node => node.specifiers).map(node => [node.local.name, {}]))
  bindings.VueRouter = require('vue-router')
  const router = new Function(...Object.keys(bindings), `return ${source.slice(init.start, init.end)}`)(...Object.values(bindings))
  const merged = router.resolve('/master/categories?management_scope=office&keyword=保留').route
  assert.equal(merged.path, '/master/items')
  assert.equal(merged.query.management_scope, 'office')
  assert.equal(merged.query.keyword, '保留')
  assert.equal(merged.query.manage_categories, '1')
  const office = router.resolve('/master/office-categories?management_scope=factory&category_id=22').route
  assert.equal(office.path, '/master/items')
  assert.equal(office.query.management_scope, 'office')
  assert.equal(office.query.category_id, '22')
  assert.equal(office.query.manage_categories, '1')
  assert.deepEqual(office.matched.at(-1).meta.permission, ['master.item.view', 'item_category.view'])
  const vm = await mount('List', { route: { ...office, params: {} } })
  callHook(vm, 'created')
  await tick()
  assert.equal(vm.categoryDialogVisible, true)
  assert.equal(vm.categoryDialogScope, 'office')
  assert.deepEqual(vm.state.routes, [])
})
