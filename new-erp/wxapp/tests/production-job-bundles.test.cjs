const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
function service(storage = new Map()) {
  const exports = { exports: {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../services/job-bundles.js'), 'utf8'), {
    module: exports, Map, Promise, Number, Boolean, Object,
    wx: { getStorageSync: key => storage.get(key), setStorageSync: (key, value) => storage.set(key, value), removeStorageSync: key => storage.delete(key) },
    require: name => name.includes('erp-request') ? { createClientCommandId: () => 'command-1' } : { getErpApiBaseUrl: () => 'http://fixture/api/' },
  });
  return exports.exports;
}
function mount(deps = {}, wxOverrides = {}) {
  let page; const messages = []
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../pages/production/job-bundles/index.js'), 'utf8'), {
    Page: value => { page = value }, require: () => deps, wx: { showToast: value => messages.push(value.title), stopPullDownRefresh() {}, ...wxOverrides }, setInterval: () => 1, clearInterval() {}, Number, String, Object, Array, Boolean, Math, Promise,
  });
  page.data = JSON.parse(JSON.stringify(page.data)); page.messages = messages
  page.setData = function (values) { for (const [key, value] of Object.entries(values)) { const parts = key.split('.'); let target = this.data; for (const part of parts.slice(0, -1)) target = target[part]; target[parts.at(-1)] = value } }
  return page;
}
test('lost mobile report response retains exact original quantities and versions', async () => {
  const subject = service(); const seen = []
  await assert.rejects(subject.execute('report-9', { expected_version: 3, qualified_base_qty: '999999999999.0001' }, payload => { seen.push(JSON.parse(JSON.stringify(payload))); return Promise.reject(new Error('lost')) }))
  await subject.execute('report-9', { expected_version: 99, qualified_base_qty: '2' }, payload => { seen.push(JSON.parse(JSON.stringify(payload))); return Promise.resolve({}) })
  assert.deepEqual(seen[1], seen[0]); assert.equal(subject.pending('report-9'), null)
})
test('definitive mobile validation failure clears pending commands', async () => {
  const subject = service(); await assert.rejects(subject.execute('report-8', {}, () => Promise.reject({ statusCode: 422, errorCode: 'quantity_invalid' })))
  assert.equal(subject.pending('report-8'), null)
})
test('new report leaves unknown quantities blank and rejects empty quantity rather than inventing zero', async () => {
  const page = mount({ pending: () => null, execute: () => assert.fail('invalid report sent') })
  page.data.detail = { lines: [{ id: 2, allowed_actions: { report: true } }] }; page.data.id = 1
  page.openReport({ currentTarget: { dataset: { id: 2 } } }); assert.equal(page.data.reportDraft.qualified_base_qty, '')
  await page.submitReport(); assert.equal(page.messages.length, 1)
})
test('pending report reopens original payload and does not permit editing a lost-response command', () => {
  const page = mount({ pending: () => ({ payload: { qualified_base_qty: '0.1250', unqualified_base_qty: '0', scrapped_base_qty: '0' } }) })
  page.data.detail = { lines: [{ id: 2, allowed_actions: { report: true } }] }; page.data.id = 1
  page.openReport({ currentTarget: { dataset: { id: 2 } } }); page.reportInput({ currentTarget: { dataset: { field: 'qualified_base_qty' } }, detail: { value: '9' } })
  assert.equal(page.data.reportDraft.qualified_base_qty, '0.1250'); assert.equal(page.data.reportPending, true)
})
test('shared report preserves per-line target versions and exact decimal strings', async () => {
  let submitted
  const page = mount({ pending: () => null, execute: (key, payload, send) => send(payload), report: (id, line, payload) => { submitted = { id, line, payload }; return Promise.resolve({}) } })
  page.data.detail = { id: 4, business_version: 7, lines: [{ id: 2, target: { business_version: 8 }, allowed_actions: { report: true } }] }; page.data.id = 4
  page.openReport({ currentTarget: { dataset: { id: 2 } } }); page.data.reportDraft = { qualified_base_qty: '123456789012.1250', unqualified_base_qty: '0', scrapped_base_qty: '0' }; page.load = async () => {}
  await page.submitReport(); assert.equal(submitted.line, 2); assert.equal(submitted.payload.expected_target_version, 8); assert.equal(submitted.payload.qualified_base_qty, '123456789012.1250')
})
test('optional completion retains explicit warehouse choice and does not assume a direct handover', async () => {
  let submitted
  const page = mount({ pending: () => null, execute: (key, payload, send) => send(payload), complete: (id, line, payload) => { submitted = payload; return Promise.resolve({}) } }, {
    showActionSheet: options => { assert.deepEqual(Array.from(options.itemList), ['直接交接下一工序', '先入库再领用']); options.success({ tapIndex: 1 }) },
    showModal: options => options.success({ confirm: true }),
  })
  page.data.detail = { id: 1, business_version: 4, lines: [{ id: 2, item: { name: '架子' }, target: { business_version: 3, output_mode: 'warehouse_optional', allow_continue_without_warehouse: true }, allowed_actions: { complete: true } }] }; page.load = async () => {}
  await page.completeLine({ currentTarget: { dataset: { id: 2 } } }); assert.equal(submitted.disposition, 'warehouse'); assert.equal(submitted.expected_target_version, 3)
})
test('completion cancelled at the choice dialog sends nothing and unlocks the page', async () => {
  const page = mount({ pending: () => null, execute: () => assert.fail('cancelled completion sent') }, { showActionSheet: options => options.fail() })
  page.data.detail = { id: 1, lines: [{ id: 2, target: { output_mode: 'warehouse_optional' }, allowed_actions: { complete: true } }] }
  await page.completeLine({ currentTarget: { dataset: { id: 2 } } }); assert.equal(page.data.busy, false)
})
test('hidden page ignores late detail reads and does not restart the labor timer', async () => {
  let finish
  const page = mount({ detail: () => new Promise(resolve => { finish = resolve }) }); page.data.id = 1
  const read = page.load(); page.onHide(); finish({ data: { id: 1, my_labor: { status: 'ACTIVE', accumulated_seconds: 10 } } }); await read
  assert.equal(page.timer, null); assert.equal(page.data.detail, null)
})
test('returning to a paginated mobile list starts at page one instead of dropping its first page', async () => {
  const requests = []
  const page = mount({ list: async query => { requests.push({ ...query }); return { data: [{ id: query.page }], total: 3 } } })
  page.data.page = 2; page.data.rows = [{ id: 1 }, { id: 2 }]; page.data.total = 3
  await page.onShow()
  assert.equal(requests[0].page, 1); assert.equal(page.data.page, 1)
  assert.deepEqual(Array.from(page.data.rows, row => row.id), [1])
  page.data.page = 2; await page.load(true)
  assert.deepEqual(Array.from(page.data.rows, row => row.id), [1, 2])
})
test('pull-down refresh resets the mobile list page and replaces old appended rows', async () => {
  let stopped = 0
  const page = mount({ list: async query => ({ data: [{ id: query.page }], total: 4 }) }, { stopPullDownRefresh: () => { stopped += 1 } })
  await page.onShow(); page.data.page = 2; await page.load(true)
  assert.deepEqual(Array.from(page.data.rows, row => row.id), [1, 2])
  await page.onPullDownRefresh()
  assert.equal(page.data.page, 1); assert.deepEqual(Array.from(page.data.rows, row => row.id), [1]); assert.equal(stopped, 1)
})
test('a start POST completed after leaving the page cannot issue a hidden read or restart its timer', async () => {
  let finishPost; let detailReads = 0
  const page = mount({
    execute: (key, payload, send) => send(payload),
    action: () => new Promise(resolve => { finishPost = resolve }),
    detail: async () => { detailReads += 1; return { data: { id: 1, my_labor: { status: 'ACTIVE', accumulated_seconds: 10 } } } },
  })
  page.pageActive = true; page.data.id = 1
  page.data.detail = { id: 1, business_version: 2, allowed_actions: { start: true } }
  const start = page.action({ currentTarget: { dataset: { action: 'start' } } })
  page.onHide(); finishPost({}); await start
  assert.equal(detailReads, 0); assert.equal(page.timer, null); assert.equal(page.data.busy, false)
  await page.onShow()
  assert.equal(detailReads, 1); assert.equal(page.timer, 1)
})
