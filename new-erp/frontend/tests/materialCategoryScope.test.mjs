import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import * as scope from '../src/utils/materialManagementScope.mjs'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const source = await readFile(new URL('../src/views/erp/master/ItemCategoryList.vue', import.meta.url), 'utf8')
const parsed = compiler.parseComponent(source)
const script = parsed.script.content.replace(/import[\s\S]*?from ['"][^'"]+['"]\s*/g, '').replace('export default', 'return')
const category = (id, management_scope, parent_id = null, children = []) => ({
  id, management_scope, parent_id, children, category_code: 'CAT-' + id,
  category_name: (management_scope === 'office' ? '办公分类' : '工厂分类') + id,
  full_path: '分类/' + id, status: 'enabled', sort_order: id
})
const flat = rows => rows.flatMap(row => [row, ...flat(row.children || [])])

function mount(overrides = {}, routeQuery = {}) {
  const state = {
    trees: [category(1, 'factory', null, [category(11, 'factory', 1)]), category(2, 'office', null, [category(22, 'office', 2)])],
    calls: { tree: [], list: [], get: [], save: [], disable: [], enable: [], delete: [], reserve: [], fresh: [], cleared: [] },
    routes: [], errors: [], number: 0
  }
  const response = data => ({ data: { data: structuredClone(data) } })
  const reserve = (method, type, page) => {
    state.calls[method].push({ type, page })
    const n = ++state.number
    return Promise.resolve({ document_no: 'CAT-NEW-' + n, reservation_token: 'token-' + n, creation_session_id: 'session-' + n, storage_key: page })
  }
  const bindings = {
    ...scope, cachedPageRoute: {}, localStorage: { getItem: key => key === 'erp_permissions' ? '["item_category.view","item_category.manage","master.item.view"]' : '{}' },
    getItemCategoryTree: async params => {
      state.calls.tree.push({ ...params })
      return response(params.management_scope ? state.trees.filter(row => row.management_scope === params.management_scope) : state.trees)
    },
    listItemCategories: async params => {
      state.calls.list.push({ ...params })
      const rows = flat(state.trees).filter(row => (!params.management_scope || row.management_scope === params.management_scope)
        && (params.parent_id ? Number(row.parent_id) === Number(params.parent_id) : row.parent_id === null))
      return { data: { data: structuredClone(rows), total: rows.length } }
    },
    getItemCategory: async (id, params) => {
      state.calls.get.push({ id, params: { ...params } })
      return response(flat(state.trees).find(row => Number(row.id) === Number(id)) || { id })
    },
    saveItemCategory: async (payload, params) => {
      state.calls.save.push({ payload: { ...payload }, params: { ...params } })
      const row = { ...payload, id: payload.id || 3, children: [] }
      if (!payload.id) {
        if (payload.parent_id) flat(state.trees).find(parent => parent.id === payload.parent_id).children.push(row)
        else state.trees.push(row)
      }
      return response(row)
    },
    disableItemCategory: async (id, params) => { state.calls.disable.push({ id, params: { ...params } }) },
    enableItemCategory: async (id, params) => { state.calls.enable.push({ id, params: { ...params } }) },
    deleteItemCategory: async (id, params) => {
      state.calls.delete.push({ id, params: { ...params } })
      state.trees = state.trees.filter(row => Number(row.id) !== Number(id))
    },
    reserveForCreatePage: (type, page) => reserve('reserve', type, page),
    reserveFreshDocumentNumber: (type, page) => reserve('fresh', type, page),
    clearCreatePageReservation: value => { if (value) state.calls.cleared.push(value) },
    ...overrides
  }
  const component = new Function(...Object.keys(bindings), script)(...Object.values(bindings))
  const route = { path: '/master/categories', query: { ...routeQuery }, params: {}, meta: {} }
  const vm = {
    $route: route, pageRoute: route, $router: { push: value => state.routes.push(value) }, $emit() {},
    $nextTick: fn => Promise.resolve().then(fn),
    $refs: { form: { validate: fn => fn(true), clearValidate() {} }, categoryTree: { setCurrentKey() {}, filter() {} } },
    $confirm: async () => {}, $message: { error: value => state.errors.push(value), success() {}, warning() {} },
    state, component
  }
  for (const [key, fn] of Object.entries(component.methods)) vm[key] = fn.bind(vm)
  Object.assign(vm, component.data.call(vm))
  for (const [key, fn] of Object.entries(component.computed)) Object.defineProperty(vm, key, { get: () => fn.call(vm) })
  return vm
}

test('combined category page template compiles with the installed Vue compiler', () => {
  assert.deepEqual(compiler.compile(parsed.template.content).errors, [])
})

test('default combined tree and paginated root list omit scope instead of silently selecting factory', async () => {
  const vm = mount()
  await vm.initialize()
  assert.deepEqual(vm.state.calls.tree, [{}])
  assert.equal('management_scope' in vm.state.calls.list[0], false)
  assert.equal(vm.state.calls.list[0].root_only, 1)
  assert.equal(vm.state.calls.list[0].per_page, 20)
  assert.deepEqual(vm.rows.map(row => row.management_scope), ['factory', 'office'])
  assert.equal(vm.selected.id, undefined)
  assert.equal(vm.scopeName(vm.rows[0]), '工厂物料')
  assert.equal(vm.scopeName(vm.rows[1]), '办公用品')
})

test('route query applies to the owning category instance when another global route becomes active', async () => {
  const vm = mount({}, { management_scope: 'office' })
  vm.$route = { path: '/master/items', query: { management_scope: 'factory' }, meta: {} }
  await vm.initialize()
  assert.equal(vm.query.management_scope, 'office')
  assert.deepEqual(vm.state.calls.tree[0], { management_scope: 'office' })
  assert.equal(vm.state.calls.list[0].management_scope, 'office')
  assert.deepEqual(vm.rows.map(row => row.id), [2])
})

test('changing list scope clears the selected hierarchy and ignores a delayed previous tree response', async () => {
  let releaseFactory
  const vm = mount({
    getItemCategoryTree: params => params.management_scope === 'factory'
      ? new Promise(resolve => { releaseFactory = resolve })
      : Promise.resolve({ data: { data: [category(2, 'office')] } })
  }, { management_scope: 'factory' })
  vm.selected = category(1, 'factory')
  vm.query.page = 4
  const first = vm.loadTree()
  vm.query.management_scope = 'office'
  await vm.changeManagementScope()
  releaseFactory({ data: { data: [category(1, 'factory')] } })
  await first
  assert.deepEqual(vm.tree.map(row => row.id), [2])
  assert.deepEqual(vm.rows.map(row => row.id), [2])
  assert.equal(vm.selected.id, undefined)
  assert.equal(vm.query.page, 1)
  assert.equal(vm.loading, false)
})

test('new root can select office while parent options and reservation sessions remain isolated by scope', async () => {
  const vm = mount()
  await vm.initialize()
  await vm.openCreate(null)
  vm.form.management_scope = 'office'
  await vm.changeFormScope('office')
  assert.equal(vm.form.management_scope, 'office')
  assert.equal(vm.formScopeLocked, false)
  assert.deepEqual(vm.parentOptions.map(row => row.id), [2, 22])
  assert.deepEqual(vm.state.calls.reserve.map(call => call.page), ['/master/categories#create:factory', '/master/categories#create:office'])
  assert.equal(vm.reservation.storage_key, '/master/categories#create:office')
})

test('new child inherits its actual parent scope and cannot switch to a different scope', async () => {
  const vm = mount()
  await vm.initialize()
  await vm.openCreate(category(2, 'office'))
  assert.equal(vm.form.management_scope, 'office')
  assert.equal(vm.form.parent_id, 2)
  assert.equal(vm.formScopeLocked, true)
  vm.form.management_scope = 'factory'
  await vm.changeFormScope('factory')
  assert.equal(vm.form.management_scope, 'office')
  assert.deepEqual(vm.parentOptions.map(row => row.id), [2, 22])
  vm.changeParent(null)
  assert.equal(vm.formScopeLocked, false)
  vm.form.management_scope = 'factory'
  await vm.changeFormScope('factory')
  assert.equal(vm.form.management_scope, 'factory')
})

test('editing uses the record scope and keeps it read-only even when the list filter differs', async () => {
  const vm = mount({}, { management_scope: 'factory' })
  await vm.initialize()
  await vm.openEdit(category(2, 'office', null, [category(22, 'office', 2)]))
  assert.equal(vm.form.management_scope, 'office')
  assert.equal(vm.formScopeLocked, true)
  assert.equal(vm.parentOptions.some(row => row.management_scope !== 'office'), false)
  assert.equal(vm.parentOptions.some(row => [2, 22].includes(row.id)), false)
  vm.form.management_scope = 'factory'
  await vm.changeFormScope('factory')
  assert.equal(vm.form.management_scope, 'office')
  assert.equal(vm.state.calls.reserve.length, 0)
  await vm.save()
  assert.deepEqual(vm.state.calls.save[0].params, { management_scope: 'office' })
  assert.equal(vm.state.calls.save[0].payload.management_scope, 'office')
  assert.equal(vm.query.management_scope, 'office')
})

test('late factory reservation cannot overwrite the newly selected office form number', async () => {
  let releaseFactory
  const vm = mount({
    reserveForCreatePage: (type, page) => page.endsWith(':factory')
      ? new Promise(resolve => { releaseFactory = resolve })
      : Promise.resolve({ document_no: 'OFFICE-NEW', reservation_token: 'office-token', creation_session_id: 'office-session' })
  })
  const first = vm.openCreate(null)
  vm.form.management_scope = 'office'
  await vm.changeFormScope('office')
  releaseFactory({ document_no: 'FACTORY-OLD', reservation_token: 'factory-token', creation_session_id: 'factory-session' })
  await first
  assert.equal(vm.form.category_code, 'OFFICE-NEW')
  assert.equal(vm.reservation.reservation_token, 'office-token')
  assert.equal(vm.numberLoading, false)
})

test('saving an office root refreshes only that hierarchy and preserves factory rows in the combined tree', async () => {
  const vm = mount()
  await vm.initialize()
  const factoryTree = vm.tree.find(row => row.id === 1)
  await vm.openCreate(null)
  vm.form.management_scope = 'office'
  await vm.changeFormScope('office')
  vm.form.category_name = '办公纸张'
  await vm.save()
  const request = vm.state.calls.save[0]
  assert.equal(request.payload.management_scope, 'office')
  assert.deepEqual(request.params, { management_scope: 'office' })
  assert.equal(request.payload.reservation_token, 'token-2')
  assert.deepEqual(vm.state.calls.tree.at(-1), { management_scope: 'office' })
  assert.equal(vm.tree.find(row => row.id === 1), factoryTree)
  assert.deepEqual(vm.tree.map(row => row.id).sort((a, b) => a - b), [1, 2, 3])
  assert.equal(vm.query.management_scope, '')
  assert.equal(vm.selected.id, 3)
  assert.deepEqual(vm.state.calls.get.at(-1).params, { management_scope: 'office' })
  assert.equal(vm.state.calls.list.at(-1).management_scope, 'office')
  assert.equal(vm.state.errors.length, 0)
})

test('status and deletion use actual office row scope rather than an active factory route', async () => {
  const vm = mount()
  await vm.initialize()
  vm.$route = { path: '/master/items', query: { management_scope: 'factory' }, meta: {} }
  await vm.toggleStatus(category(2, 'office'))
  assert.deepEqual(vm.state.calls.disable[0], { id: 2, params: { management_scope: 'office' } })
  assert.deepEqual(vm.state.calls.get.at(-1), { id: 2, params: { management_scope: 'office' } })
  await vm.toggleStatus({ ...category(2, 'office'), status: 'disabled' })
  assert.deepEqual(vm.state.calls.enable[0], { id: 2, params: { management_scope: 'office' } })
  await vm.deleteCategory({ ...category(2, 'office'), status: 'disabled' })
  assert.deepEqual(vm.state.calls.delete[0], { id: 2, params: { management_scope: 'office' } })
  assert.deepEqual(vm.state.calls.tree.at(-1), { management_scope: 'office' })
  assert.deepEqual(vm.tree.map(row => row.id), [1])
  assert.equal(vm.selected.id, undefined)
  assert.equal('management_scope' in vm.state.calls.list.at(-1), false)
})

test('number conflict retry stays in the office reservation namespace', async () => {
  const vm = mount({ saveItemCategory: async () => { throw { response: { data: { errors: { category_code: ['编号已被使用'] } } } } } })
  await vm.openCreate(category(2, 'office'))
  vm.form.category_name = '办公纸张'
  await vm.save()
  assert.deepEqual(vm.state.calls.fresh, [{ type: 'item_category', page: '/master/categories#create:office' }])
  assert.equal(vm.form.category_code, 'CAT-NEW-2')
  assert.equal(vm.form.management_scope, 'office')
  assert.equal(vm.drawerVisible, true)
  assert.equal(vm.saving, false)
})

test('category navigation returns to the single item list with the actual category scope', async () => {
  const vm = mount()
  vm.goItems(category(2, 'office'))
  assert.deepEqual(vm.state.routes, [{ path: '/master/items', query: { category_id: 2, management_scope: 'office' } }])
})
