const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const Vue = require('vue')
Vue.config.silent = true
const root = path.join(__dirname, '../src')
const mixin = vm.runInNewContext(fs.readFileSync(path.join(root, 'utils/cachedPageRoute.js'), 'utf8').replace('export default', 'result ='), { result: null })
function options(file) {
  const context = { result: null, cachedPageRoute: mixin, window: { crypto: { randomUUID: () => 'fixture-uuid' }, setTimeout: () => {} }, process }
  let script = fs.readFileSync(path.join(root, 'views/erp', file), 'utf8').split('<script>')[1].split('</script>')[0]
  script = script.replace(/^import (.+) from .+$/gm, (_, bindings) => {
    for (const name of bindings.replace(/[{}]/g, '').split(',').map(x => x.trim())) {
      if (name !== 'cachedPageRoute') context[name] = name === 'getSalesOrder'
        ? () => Promise.reject({ response: { status: 404 } })
        : () => Promise.resolve({ data: {} })
    }
    return ''
  })
  return vm.runInNewContext(script.replace('export default', 'result ='), context)
}
const route = (path, id, query = {}) => ({ path, params: id ? { id } : {}, query })
;(async () => {
  const state = Vue.observable({ current: route('/sales/orders/create') })
  const make = (file) => {
    const original = options(file)
    const calls = []
    const methods = Object.fromEntries(Object.keys(original.methods || {}).map(name => [name, function () { calls.push(name) }]))
    const page = new Vue({ ...original, created: undefined, methods, beforeCreate() { Object.defineProperty(this, '$route', { get: () => state.current }) } })
    return { page, calls, original }
  }
  const sales = make('sales/SalesOrderForm.vue')
  sales.page.form.customer_name = '未保存客户'
  const navigate = async next => { state.current = next; await Vue.nextTick(); await Vue.nextTick() }
  await navigate(route('/production/work-orders/10250', '10250'))
  const work = make('production/WorkOrderDetail.vue')
  await navigate(route('/production/cutting/3931', '3931'))
  const cutting = make('production/CuttingDetail.vue')
  await navigate(route('/production/routings/8396', '8396'))
  const routing = make('production/ProductionRoutingForm.vue')
  let finishOptions
  routing.page.searchOperations = () => new Promise(resolve => { finishOptions = resolve })
  let loadedRoutingId
  routing.page.load = async () => { loadedRoutingId = routing.page.id }
  const pendingCreated = routing.original.created.call(routing.page)
  await navigate(route('/inventory/balances'))
  const inventory = make('inventory/InventoryBoard.vue')
  inventory.calls.length = 0
  await navigate(route('/sales/orders/71/production-confirmation', '71'))
  const confirmation = make('sales/SalesProductionConfirmation.vue')
  await navigate(route('/production/work-orders/8396', '8396'))
  const secondWork = make('production/WorkOrderDetail.vue')
  finishOptions()
  await pendingCreated
  assert.equal(String(loadedRoutingId), '8396', 'async initialization must retain original routing ID')
  for (const entry of [sales, work, cutting, routing, inventory, confirmation]) assert.deepEqual(entry.calls, [], 'inactive page must not load another route')
  assert.equal(sales.page.isEdit, false)
  assert.equal(sales.page.form.customer_name, '未保存客户')
  assert.equal(work.page.pageRoute.params.id, '10250')
  assert.equal(secondWork.page.pageRoute.params.id, '8396')
  assert.equal(cutting.page.pageRoute.params.id, '3931')
  assert.equal(routing.page.viewOnly, true)
  await navigate(route('/production/work-orders/10250', '10250', { mode: 'edit' }))
  assert.equal(work.page.pageRoute.query.mode, 'edit')
  assert.equal(secondWork.page.pageRoute.query.mode, undefined)
  assert.deepEqual(work.calls, [], 'reactivation must not reload or reset cached edits')
  await navigate(route('/sales/orders/create', undefined, { focus: 'lines' }))
  assert.equal(sales.page.form.customer_name, '未保存客户')
  assert.equal(sales.page.pageRoute.query.focus, 'lines')
  // A request started on a visible sales page can fail after navigation; it must not redirect the new page.
  await navigate(route('/production/cutting/3931', '3931'))
  let redirects = 0
  sales.page.$router = { replace: () => { redirects++ } }
  sales.page.$message = { error: () => { throw new Error('inactive error toast') } }
  assert.equal(await sales.original.methods.load.call(sales.page), false)
  assert.equal(redirects, 0)
  for (const entry of [sales, work, secondWork, cutting, routing, inventory, confirmation]) entry.page.$destroy()
  console.log('PASS: six real page watchers isolated; unsaved form and per-ID tabs retained; same-path query updates; deferred routing initialization; inactive sales failure cannot redirect.')
})().catch(error => { console.error(error); process.exitCode = 1 })
