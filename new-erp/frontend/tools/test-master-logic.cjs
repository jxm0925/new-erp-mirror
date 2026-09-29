const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const Vue = require('vue')
Vue.config.silent = true
const root = path.resolve(__dirname, '../src')
const read = file => fs.readFileSync(path.join(root, file), 'utf8')
const plain = value => JSON.parse(JSON.stringify(value))
const route = (pathname, query = {}, params = {}) => ({ path: pathname, query, params })
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
const paged = vm.runInNewContext(read('utils/pagedQuery.js').replace(/export /g, '') + '\n;({ createPageState, queryPage, hasNextPage, includeSelected, invalidatePage })')
const cachedPageRoute = vm.runInNewContext(read('utils/cachedPageRoute.js').replace('export default', 'result ='), { result: null })
const pages = []
let tests = 0
const response = (rows, page, perPage, stats = {}) => ({ data: { data: rows.slice((page - 1) * perPage, page * perPage), total: rows.length, current_page: page, last_page: Math.max(1, Math.ceil(rows.length / perPage)), stats } })

function options (file, overrides = {}) {
  const context = { result: null, ...paged, cachedPageRoute, pagedScroll: {}, window: { innerWidth: 1600, crypto: { randomUUID: () => 'fixture-uuid' }, addEventListener () {}, removeEventListener () {} },
    console, setTimeout, clearTimeout, FormData, process }
  let source = read(`views/erp/master/${file}.vue`).split('<script>')[1].split('</script>')[0]
  source = source.replace(/^import ([\s\S]*?) from ['"][^'"]+['"]\r?$/gm, (_, bindings) => {
    for (const name of bindings.replace(/[{}]/g, '').split(',').map(s => s.trim()).filter(Boolean)) {
      if (!(name in context)) context[name] = () => Promise.resolve({ data: { data: [], total: 0 } })
    }
    return ''
  })
  Object.assign(context, overrides)
  return vm.runInNewContext(source.replace('export default', 'result ='), context)
}

function page (file, overrides = {}, initialRoute = route('/master/items'), propsData = {}) {
  const current = Vue.observable({ route: initialRoute })
  const errors = [], pushes = []
  const source = options(file, overrides)
  const instance = new Vue({ ...source, propsData, created: undefined, mounted: undefined,
    beforeCreate () {
      Object.defineProperty(this, '$route', { get: () => current.route })
      this.$router = { push: next => pushes.push(next) }
      this.$message = { error: text => errors.push(text), success () {}, warning () {} }
      this.$can = () => true
      this.$confirm = async () => true
    }
  })
  pages.push(instance)
  return { instance, source, current, errors, pushes }
}

async function test (name, run) {
  await run()
  tests++
  console.log('PASS:', name)
}

;(async () => {
  await test('paged query loads one page at a time and retries failed pages without skipping', async () => {
    const state = paged.createPageState(50), rows = Array.from({ length: 101 }, (_, i) => ({ id: i + 1 }))
    const calls = []; let fail = true
    const request = async q => { calls.push(q.page); if (q.page === 2 && fail) { fail = false; throw new Error('network') } return response(rows, q.page, q.per_page) }
    await paged.queryPage(state, request)
    assert.equal(calls.length, 1); assert.equal(state.rows.length, 50)
    await assert.rejects(paged.queryPage(state, request, {}, true))
    assert.equal(state.page, 1)
    await paged.queryPage(state, request, {}, true)
    await paged.queryPage(state, request, {}, true)
    await paged.queryPage(state, request, {}, true)
    assert.deepEqual(calls, [1, 2, 2, 3]); assert.equal(state.rows.length, 101)
  })
  await test('late responses cannot overwrite a new search or a reset', async () => {
    const state = paged.createPageState(), old = deferred(), latest = deferred()
    const first = paged.queryPage(state, () => old.promise, { keyword: 'old' })
    const second = paged.queryPage(state, () => latest.promise, { keyword: 'new' })
    latest.resolve(response([{ id: 2 }], 1, 50)); await second
    old.resolve(response([{ id: 1 }], 1, 50)); await first
    assert.equal(state.rows[0].id, 2); assert.equal(state.params.keyword, 'new')
    const cancelled = deferred(), pending = paged.queryPage(state, () => cancelled.promise)
    paged.invalidatePage(state); cancelled.resolve(response([{ id: 3 }], 1, 50)); await pending
    assert.equal(state.rows.length, 0)
  })
  await test('scroll directive observes existing rows once and disconnects closed dropdowns', async () => {
    let callback, visibleChanged, observations = 0, disconnected = 0, removed = 0, loads = 0
    const directive = vm.runInNewContext(read('directives/pagedScroll.js').replace('export default', 'result ='), {
      result: null, IntersectionObserver: class {
        constructor (cb) { callback = cb }
        observe () { observations++ }
        disconnect () { disconnected++ }
      }
    })
    const node = { getClientRects: () => [1] }, root = { querySelectorAll: () => [node] }, el = {}
    const component = { $options: { name: 'ElSelect' }, visible: true, $refs: { popper: { $el: root } },
      $watch: (_, fn) => { visibleChanged = fn; return () => { removed++ } }, $nextTick: fn => fn() }
    const vnode = { componentInstance: component, context: { $nextTick: fn => fn() } }, binding = { value: async () => { loads++ } }
    directive.inserted(el, binding, vnode)
    await callback([{ target: node, isIntersecting: true }]); assert.equal(loads, 1)
    directive.componentUpdated(el, binding, vnode); assert.equal(observations, 1)
    component.visible = false; visibleChanged(); assert.ok(disconnected >= 2)
    component.visible = true; visibleChanged(); assert.equal(observations, 2)
    directive.unbind(el); assert.equal(removed, 1)
  })
  await test('real relation modal reaches Item 101 and keeps the selected Item across searches', async () => {
    const rows = Array.from({ length: 101 }, (_, i) => ({ id: i + 1, item_name: `物料${i + 1}`, spec: `规格${i + 1}` }))
    const calls = []
    const { instance: p } = page('SkuItemRelationList', { listEntity: async (_, q) => {
      calls.push(q); return response(q.keyword === 'empty' ? [] : rows, q.page, q.per_page)
    } })
    p.setModal.visible = true
    await p.fetchModalItems(''); assert.equal(calls.length, 1)
    await p.loadMoreModalItems(); await p.loadMoreModalItems()
    p.setModal.form.item_id = 101; p.onSelectModalItem(101)
    await p.searchModalItems('empty')
    assert.equal(p.setModal.chosen.id, 101); assert.equal(p.setModal.items[0].spec, '规格101')
    assert.deepEqual(calls.slice(0, 3).map(q => q.page), [1, 2, 3])
  })
  await test('supplier Item selector searches on the server and retains its single selection', async () => {
    const requests = [], rows = Array.from({ length: 120 }, (_, i) => ({ id: i + 1, item_name: `材料${i + 1}` }))
    const { instance: p } = page('SupplierList', { listEntity: async (_, q) => {
      requests.push(q); return response(q.keyword === 'last' ? [rows[119]] : q.keyword === 'none' ? [] : rows, q.page, q.per_page)
    } })
    p.relationDialogVisible = true
    await p.searchSupplierItems('last')
    assert.equal(p.items[0].id, 120); assert.equal(requests[0].keyword, 'last'); assert.equal(requests[0].is_purchase_item, 1)
    p.relationForm.item_id = 120
    await p.searchSupplierItems('none')
    assert.equal(p.items[0].id, 120); assert.equal(p.relationForm.item_id, 120)
  })
  await test('category drilldown works on first load and cached re-entry without reacting to other pages', async () => {
    const calls = []
    const { instance: p, current } = page('ItemList', { listEntity: async (entity, q) => { calls.push(q); return response([], 1, 20) } }, route('/master/items', { category_id: '12' }))
    assert.equal(p.query.category_id, 12)
    current.route = route('/production/work-orders/777', { category_id: '777' })
    await Vue.nextTick(); await Vue.nextTick(); assert.equal(calls.length, 0)
    current.route = route('/master/items', { category_id: '34' })
    await Vue.nextTick(); await Vue.nextTick()
    assert.equal(p.query.category_id, 34); assert.equal(calls[0].category_id, 34)
    current.route = route('/master/items')
    await Vue.nextTick(); await Vue.nextTick(); assert.equal(p.query.category_id, '')
  })
  await test('existing metric cards use server totals instead of visible row counts', async () => {
    for (const [file, metric, key] of [['ProductList', 'enabledCount', 'enabled'], ['SkuList', 'missingItemCount', 'missing_item'],
      ['ItemList', 'cuttingCount', 'cutting'], ['UnitList', 'quantityCount', 'quantity'], ['ArchiveDictionary', 'salesCount', 'sales']]) {
      const { instance: p } = page(file, {}, route('/master/test'), { kind: 'payments' })
      p.stats = { [key]: 137 }
      assert.equal(p[metric], 137, file)
    }
  })
  await test('warehouse locations are requested by selected warehouse and metrics use all rows', async () => {
    const calls = []
    const { instance: p } = page('WarehouseLocationBoard', {
      listEntity: async (entity, q) => {
        calls.push({ entity, ...q })
        if (entity === 'warehouses') return response([{ id: 1, locations_count: 101, area_count: 2 }], q.page, q.per_page)
        return { data: { ...response([{ id: 1001, warehouse_id: q.warehouse_id }], q.page, q.per_page).data,
          warehouse_stats: { total: 101, enabled: 100, disabled: 1, mixed: 3, capacity: '202', areas: [{ name: 'A', count: 101 }] } } }
      },
      listInventoryBalances: async () => ({ data: { stats: { item_count: 102, quantity_by_unit: [{ quantity: '126.2500', unit_name: '件' }, { quantity: '2.5000', unit_name: '千克' }], inventory_value: 1030 } } })
    })
    await p.fetchAll()
    assert.equal(calls.find(call => call.entity === 'locations').warehouse_id, 1)
    assert.equal(p.enabledLocationCount, 100); assert.equal(p.totalCapacity, 202)
    assert.equal(p.locationCount(1), 101); assert.equal(p.warehouseAreaCount(1), 2)
    assert.equal(p.inventoryStats.total_qty, '126.25 件 / 2.5 千克')
    await p.selectWarehouse({ id: 2 })
    assert.equal(calls[calls.length - 1].warehouse_id, 2)
  })
  await test('late warehouse inventory responses cannot replace the newly selected warehouse', async () => {
    const first = deferred(), second = deferred()
    const { instance: p } = page('WarehouseLocationBoard', { listInventoryBalances: q => q.warehouse_id === 1 ? first.promise : second.promise })
    const a = p.fetchInventoryStats(1), b = p.fetchInventoryStats(2)
    second.resolve({ data: { stats: { item_count: 2 } } }); await b
    first.resolve({ data: { stats: { item_count: 999 } } }); await a
    assert.equal(p.inventoryStats.item_count, 2)
  })
  await test('Product draft posts draft status, while matrix creation posts one atomic batch', async () => {
    const writes = [], reservations = []
    const { instance: p } = page('ProductForm', {
      saveEntity: async (entity, payload) => { writes.push({ entity, payload: plain(payload) }); return { data: { data: { id: 1 } } } },
      reserveFreshDocumentNumber: async () => { const id = reservations.push(1); return { document_no: `SKU${id}` } }
    }, route('/master/products/new'))
    let done
    p.$refs.form = { validate: callback => { done = callback(true) } }
    p.form.product_name = '商品'; p.form.unit_id = 1
    p.matrix = { dimensions: [{ name: '颜色', valuesText: '红,蓝' }], sale_price: 10 }
    await p.save('draft'); await done
    assert.equal(writes[0].payload.status, 'draft'); assert.equal(writes[0].payload.sku_matrix, undefined); assert.equal(reservations.length, 0)
    await p.save('save'); await done
    assert.equal(writes.length, 2); assert.equal(writes[1].entity, 'products'); assert.equal(writes[1].payload.sku_matrix.length, 2)
    assert.equal(reservations.length, 2)
  })
  await test('Product-origin SKU create, cancel and save retain the return route', async () => {
    const parent = page('ProductList')
    parent.instance.openSkuCreate({ id: 12 })
    assert.deepEqual(plain(parent.pushes[0]), { path: '/master/skus/new', query: { product_id: 12, from: 'product' } })
    const child = page('SkuForm', { saveEntity: async () => ({ data: { data: { id: 44 } } }) }, route('/master/skus/new', { from: 'product', product_id: '12' }))
    child.instance.form = { sku_code: 'SKU44', sku_name: '新规格', product_id: 12, spec_model: '红' }
    child.instance.back(); assert.equal(child.pushes[0], '/master/products')
    await child.instance.save('draft')
    assert.deepEqual(plain(child.pushes[1]), { path: '/master/skus/44', query: { from: 'product' } })
  })
  await test('import continuation starts at row 51, filters reset page, and download uses authenticated helper', async () => {
    const all = Array.from({ length: 105 }, (_, i) => ({ id: i + 1, row_no: i + 2 })), calls = [], downloads = []
    const { instance: p } = page('ImportWorkbench', {
      uploadImport: async () => ({ data: { data: { id: 7 } } }),
      previewImport: async () => ({ data: { data: { id: 7, batch_no: 'B7' }, rows: { ...response(all, 1, 50).data, per_page: 50 } } }),
      importRows: async (id, q) => { calls.push({ id, ...q }); return response(q.status ? all.slice(100) : all, q.page, q.per_page) },
      downloadImportErrors: async (...args) => downloads.push(args)
    }, route('/master/imports', { type: 'Supplier' }))
    assert.equal(p.importType, 'Supplier'); assert.equal(p.types.includes('Legacy Mapping'), false)
    p.file = new Blob(['CSV']); await p.upload(); assert.equal(p.rows.length, 50)
    await p.loadMoreRows(); await p.loadMoreRows()
    assert.equal(p.rows.length, 105); assert.equal(p.rows[50].id, 51)
    assert.deepEqual(calls.map(q => [q.page, q.per_page]), [[2, 50], [3, 50]])
    p.filter = 'error'; await p.loadRows()
    assert.equal(calls[2].page, 1); assert.equal(calls[2].status, 'error'); assert.equal(p.rows.length, 5)
    await p.exportErrors(); assert.deepEqual(downloads, [[7, 'B7-errors.csv']])
    p.reset(); assert.equal(p.filter, ''); assert.equal(p.rowPage.page, 0)
  })
  await test('resetting an import while its confirmation is open cannot submit the wrong batch', async () => {
    const wait = deferred(); let calls = 0
    const { instance: p } = page('ImportWorkbench', { confirmImport: async () => { calls++; return { data: {} } } }, route('/master/imports'))
    p.batch = { id: 1, valid_rows: 1, warning_rows: 0 }; p.stage = 1; p.$confirm = () => wait.promise
    const pending = p.confirm(); p.reset(); wait.resolve(); await pending
    assert.equal(calls, 0)
  })
  for (const p of pages) p.$destroy()
  console.log(`PASS: ${tests} master-data logic scenarios (original Vue components and pagination utility).`)
})().catch(error => { for (const p of pages) p.$destroy(); console.error(error); process.exitCode = 1 })
