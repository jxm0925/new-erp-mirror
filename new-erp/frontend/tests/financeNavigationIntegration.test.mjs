import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const Vue = require('vue')
const VueRouter = require('vue-router')
const compiler = require('vue-template-compiler')
Vue.config.productionTip = false
Vue.config.devtools = false

// Load the application's actual route table and guards. Only the imported page
// components, HTTP boundary and browser storage are replaced; Vue Router runs
// redirects and beforeEach/beforeEnter hooks in its real abstract history mode.
async function navigation({ permissions = [], superAdmin = false, authenticated = true, getCashDocument } = {}) {
  const source = await readFile(new URL('../src/main.js', import.meta.url), 'utf8')
  const entries = new Map([
    ['erp_permissions', JSON.stringify(permissions)],
    ['erp_me', JSON.stringify({ is_super_admin: superAdmin })],
    ['erp_user', JSON.stringify({ username: 'navigation-test' })],
  ])
  if (authenticated) entries.set('erp_token', 'test-token')
  const localStorage = {
    getItem: key => entries.get(key) ?? null,
    setItem: (key, value) => entries.set(key, String(value)),
    removeItem: key => entries.delete(key),
  }
  const messages = []
  const requests = []
  const dependencies = Object.fromEntries(
    [...source.matchAll(/^import (\w+) from ['"][^'"]+['"]\s*$/gm)]
      .map(([, name]) => [name, { name }]),
  )
  Object.assign(dependencies, {
    Vue, VueRouter, localStorage,
    ElementUI: { install() {}, Message: { error: message => messages.push(message) } },
    getCashDocument: async id => {
      requests.push(id)
      if (!getCashDocument) assert.fail('this navigation must not load a cash document')
      return getCashDocument(id)
    },
  })
  const script = source
    .replace(/^import .*$/gm, '')
    .replace(/\(\) => import\(['"][^'"]+\.vue['"]\)/g, '() => Promise.resolve({ render: h => h("div") })')
    .replace(/^new Vue\(\{ router, render:[^\r\n]+/m, 'return { router, can: Vue.prototype.$can }')
  const { router, can } = new Function(...Object.keys(dependencies), script)(...Object.values(dependencies))

  async function go(location) {
    return new Promise((resolve, reject) => {
      const stop = router.afterEach(route => { stop(); resolve(route) })
      router.push(location).catch(error => {
        if (VueRouter.isNavigationFailure(error, VueRouter.NavigationFailureType.redirected)) return
        stop()
        reject(error)
      })
    })
  }

  const appSource = await readFile(new URL('../src/App.vue', import.meta.url), 'utf8')
  const appScript = appSource.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/^import .*$/gm, '')
    .replace('export default', 'return')
  const appOptions = new Function('localStorage', appScript)(localStorage)
  const app = { ...appOptions.data(), ...appOptions.methods }
  Object.entries(appOptions.computed || {}).forEach(([key, get]) => {
    Object.defineProperty(app, key, { get: () => get.call(app) })
  })
  return { router, go, app, appOptions, appSource, can, requests, messages, localStorage }
}

test('old supplier-ledger links use the integrated supplier view and preserve existing query context', async () => {
  const { go } = await navigation({ permissions: ['finance.supplier-ledger.view'] })
  const route = await go({ path: '/finance/supplier-ledgers', query: { supplier_keyword: '供方甲', view: 'documents' } })
  assert.equal(route.path, '/finance/payables')
  assert.equal(route.query.view, 'suppliers')
  assert.equal(route.query.supplier_keyword, '供方甲')
})

test('old allocation-list links select the matching pending receipt/payment list', async () => {
  for (const [direction, path] of [['payment', '/finance/payments'], ['receipt', '/finance/receipts'], [undefined, '/finance/receipts']]) {
    const { go, requests } = await navigation({ permissions: ['finance.view'] })
    const query = { keyword: 'DOC-17', allocation_status: 'all' }
    if (direction) query.direction = direction
    const route = await go({ path: '/finance/allocations', query })
    assert.equal(route.path, path)
    assert.equal(route.query.allocation_status, 'pending')
    assert.equal(route.query.keyword, 'DOC-17')
    assert.deepEqual(requests, [])
  }
})

test('old allocation detail chooses its receipt/payment route from the fetched document, not the URL hint', async () => {
  for (const [direction, hint, path] of [['receipt', 'payment', '/finance/receipts/17'], ['payment', 'receipt', '/finance/payments/17']]) {
    const { go, requests } = await navigation({
      permissions: ['finance.view'],
      getCashDocument: async () => ({ data: { data: { id: 17, direction } } }),
    })
    const route = await go({ path: '/finance/allocations/17', query: { direction: hint, from: 'allocation-list' } })
    assert.equal(route.path, path)
    assert.deepEqual(route.query, { allocation: '1' })
    assert.deepEqual(requests, ['17'])
  }
})

test('failed legacy document lookup reports the backend message and returns to the payment list', async () => {
  const { go, messages, requests } = await navigation({
    permissions: ['finance.view'],
    getCashDocument: async () => { throw { userMessage: '没有权限查看这笔资金单' } },
  })
  const route = await go('/finance/allocations/17')
  assert.equal(route.path, '/finance/payments')
  assert.deepEqual(messages, ['没有权限查看这笔资金单'])
  assert.deepEqual(requests, ['17'])
})

test('each single payable view permission passes the integrated route guard and exposes the menu', async () => {
  for (const permission of ['finance.payable.view', 'finance.supplier-ledger.view']) {
    const { go, app, can, messages } = await navigation({ permissions: [permission] })
    const route = await go('/finance/payables')
    assert.equal(route.path, '/finance/payables', permission)
    assert.equal(can(['finance.payable.view', 'finance.supplier-ledger.view']), true)
    assert.deepEqual(app.visibleItems(app.financeMenus).map(item => item.path), ['/finance/payables'])
    assert.equal(app.menuSections.find(section => section.key === 'finance')?.title, '财务管理')
    assert.deepEqual(messages, [])
  }
})

test('neither payable view permission means both direct and legacy supplier links are blocked', async () => {
  for (const permissions of [[], ['finance.view'], ['finance.payment.create']]) {
    for (const path of ['/finance/payables', '/finance/supplier-ledgers']) {
      const { go, messages, can } = await navigation({ permissions })
      const route = await go(path)
      assert.equal(route.path, '/console')
      assert.deepEqual(messages, ['无权访问该页面'])
      assert.equal(can(['finance.payable.view', 'finance.supplier-ledger.view']), false)
    }
  }
})

test('finance.view-only users can reach the original allocation data through both cash menus and legacy links', async () => {
  for (const path of ['/finance/receipts', '/finance/payments', '/finance/receipts/17', '/finance/payments/17']) {
    const { go, app, messages } = await navigation({ permissions: ['finance.view'] })
    const route = await go(path)
    assert.equal(route.path, path)
    assert.deepEqual(app.visibleItems(app.financeMenus).map(item => item.path), ['/finance/receipts', '/finance/payments', '/finance/statistics'])
    assert.equal(app.menuSections.find(section => section.key === 'finance')?.title, '财务管理')
    assert.deepEqual(messages, [])
  }
})

test('missing finance.view blocks legacy allocation navigation before any cash-document request', async () => {
  for (const path of ['/finance/allocations', '/finance/allocations/17']) {
    const { go, requests, messages } = await navigation({ permissions: ['finance.allocation.create'] })
    const route = await go(path)
    assert.equal(route.path, '/console')
    assert.deepEqual(requests, [])
    assert.deepEqual(messages, ['无权访问该页面'])
  }
})

test('the rendered finance menu contains one integrated payable entry and no duplicate supplier/allocation entry', async () => {
  const { app } = await navigation({ superAdmin: true })
  const finance = app.menuSections.find(section => section.key === 'finance')
  assert.deepEqual(app.visibleItems(finance.items).map(item => [item.name, item.path]), [
    ['收款管理', '/finance/receipts'],
    ['付款管理', '/finance/payments'],
    ['应付管理', '/finance/payables'],
    ['财务统计', '/finance/statistics'],
    ['发票管理', '/finance/invoices'],
    ['资金账户', '/finance/accounts'],
    ['资金转账 / 换汇', '/finance/transfers'],
    ['资金账户估值', '/finance/account-valuations'],
  ])
  assert.equal(app.findParentRoute('/finance/supplier-ledgers'), '/finance/payables')
  assert.equal(app.titleForPath('/finance/supplier-ledgers'), '应付管理')
})

test('App.canMenu evaluates array permissions as OR without exposing other finance menus', async () => {
  const { app } = await navigation({ permissions: ['finance.supplier-ledger.view'] })
  assert.equal(app.canMenu(['finance.payable.view', 'finance.supplier-ledger.view']), true)
  assert.equal(app.canMenu(['finance.view', 'finance.receipt.create']), false)
  assert.equal(app.canMenu('finance.supplier-ledger.view'), true)
  assert.equal(app.canMenu('finance.view'), false)
})

test('one finance.view dashboard replaces all three legacy statistics routes and keeps currency context', async () => {
  for (const path of ['/finance/statistics', '/finance/purchase-payment-statistics', '/finance/supplier-statistics', '/finance/sales-order-statistics']) {
    const { go, app, messages, requests } = await navigation({ permissions: ['finance.view'] })
    const route = await go({ path, query: { currency: 'USD' } })
    assert.equal(route.path, '/finance/statistics')
    assert.equal(route.query.currency, 'USD')
    assert.deepEqual(app.visibleItems(app.financeMenus).filter(item => item.name.includes('统计')).map(item => [item.name, item.path]), [['财务统计', '/finance/statistics']])
    assert.equal(app.titleForPath(path), '财务统计')
    assert.equal(app.findParentRoute(path), '/finance/statistics')
    assert.deepEqual(messages, [])
    assert.deepEqual(requests, [])
  }
})

test('supplier ledger and sales amount permissions alone cannot enter the dashboard through direct or legacy URLs', async () => {
  for (const permissions of [[], ['finance.supplier-ledger.view'], ['sales_order.view', 'sales_order.amount.view']]) {
    for (const path of ['/finance/statistics', '/finance/purchase-payment-statistics', '/finance/supplier-statistics', '/finance/sales-order-statistics']) {
      const { go, app, messages, requests } = await navigation({ permissions })
      assert.equal((await go(path)).path, '/console')
      assert.equal(app.visibleItems(app.financeMenus).some(item => item.path === '/finance/statistics'), false)
      assert.deepEqual(messages, ['无权访问该页面'])
      assert.deepEqual(requests, [])
    }
  }
})

test('super admin bypass and unauthenticated login redirection still run through the original global guard', async () => {
  const admin = await navigation({ superAdmin: true })
  assert.equal((await admin.go('/finance/payables')).path, '/finance/payables')
  assert.equal(admin.app.canMenu(['finance.payable.view', 'finance.supplier-ledger.view']), true)
  const guest = await navigation({ authenticated: false })
  const route = await guest.go('/finance/payables?view=suppliers')
  assert.equal(route.path, '/login')
  assert.equal(route.query.redirect, '/finance/payables?view=suppliers')
})

test('App render keeps the payable page instance when only its view query changes', async () => {
  const { appOptions, appSource } = await navigation({ permissions: ['finance.payable.view', 'finance.supplier-ledger.view'] })
  const compiled = compiler.compileToFunctions(compiler.parseComponent(appSource).template.content)
  const route = Vue.observable({ current: { path: '/finance/payables', fullPath: '/finance/payables?view=documents', query: { view: 'documents' } } })
  const instance = new Vue({
    ...appOptions, ...compiled,
    created: undefined, beforeDestroy: undefined, watch: {},
    components: { RouterView: { name: 'NavigationTestRouterView', render: h => h('div') } },
  })
  Object.defineProperty(instance, '$route', { get: () => route.current })
  function pageKey() {
    const find = node => {
      if (node?.componentOptions?.Ctor?.options?.name === 'NavigationTestRouterView') return node
      for (const child of node?.children || []) {
        const found = find(child)
        if (found) return found
      }
      return null
    }
    const view = find(instance._render())
    assert.ok(view, 'the actual App render must contain the business router-view')
    return view.key
  }
  const documentKey = pageKey()
  route.current = { path: '/finance/payables', fullPath: '/finance/payables?view=suppliers', query: { view: 'suppliers' } }
  assert.equal(pageKey(), documentKey, 'changing the payable view must not remount and discard both lists\' filter/page state')
  instance.$destroy()
})
