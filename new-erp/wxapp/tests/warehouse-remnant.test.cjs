const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const plain = value => JSON.parse(JSON.stringify(value));
const event = dataset => ({ currentTarget: { dataset } });
function fixture(override) {
  let definition; const listCalls = []; const posts = []; const receiptCalls = [];
  const lists = []; const results = []; const receipts = [];
  const wx = { setNavigationBarTitle() {}, stopPullDownRefresh() {} };
  const reply = values => Promise.resolve().then(() => { const value = values.shift(); if (value instanceof Error) throw value; return value; });
  const service = override || {
    pending: () => null,
    remnants: (...args) => { listCalls.push(plain(args)); return reply(lists); },
    submit: (...args) => { posts.push(plain(args)); return reply(results); },
    remnantReceipt: (...args) => { receiptCalls.push(args); return reply(receipts); },
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../pages/warehouse/remnant/index.js'), 'utf8'), {
    Page: value => { definition = value; }, wx,
    require: name => name.includes('/services/') ? service : require(path.resolve(__dirname, '../pages/warehouse/remnant', name)),
  });
  const page = Object.assign({}, definition, { data: plain(definition.data), selected: {}, sourcePage: 1,
    setData(values) { Object.assign(this.data, values); } });
  page.data.orderId = 41; page.data.canPost = true;
  return { page, lists, results, receipts, listCalls, posts, receiptCalls, service };
}
const row = (id, values = {}) => ({ id, receivable: true, business_version: 2, holding_version: 3, physical_version: 4,
  quantity: '1', total_cost: '0.1001', source_batch_no: 'LOT-1', dimensions: { length_mm: '600', width_mm: '400', thickness_mm: '2' }, ...values });
const listing = (rows, current = 1, extra = {}) => ({ data: rows, meta: { current_page: current, per_page: 10, total: 21, last_page: 3 },
  order_no: 'CUT-41', source_work_orders: { data: [{ work_order_no: 'WO-1' }], current_page: 1, last_page: 1 }, ...extra });
const receipt = id => ({ data: { id, receipt_no: `RWR-${id}`, header_snapshot: { work_order_nos: ['WO-1'] },
  lines: [{ id: 501, posted_qty: '1', posted_cost: '0.1001', batch_no: 'RM-501', line_snapshot: { dimensions: { length_mm: '600', width_mm: '400', thickness_mm: '2' } } }] } });

test('selection survives pagination and current-page select-all neither selects nor removes unseen pages', async () => {
  const f = fixture(); f.lists.push(listing([row(1), row(2), row(3, { receivable: false })])); await f.page.load();
  f.page.toggleRow(event({ id: 1 })); assert.equal(f.page.data.selectedCount, 1);
  f.page.setData({ page: 2 }); f.lists.push(listing([row(11), row(12)], 2)); await f.page.load();
  assert.equal(f.page.data.selectedCount, 1); assert.equal(f.page.data.allPageSelected, false);
  f.page.togglePage(); assert.deepEqual(plain(f.page.data.selectedRows.map(r => r.id)), [1, 11, 12]);
  assert.equal(f.page.data.selectedAmount, '0.3003');
  f.page.togglePage(); assert.deepEqual(plain(f.page.data.selectedRows.map(r => r.id)), [1]);
  f.page.setData({ page: 1 }); f.lists.push(listing([row(1), row(2), row(3, { receivable: false })])); await f.page.load();
  assert.equal(f.page.data.rows[0].checked, true); f.page.togglePage();
  assert.deepEqual(plain(f.page.data.selectedRows.map(r => r.id)), [1, 2]);
  assert.equal(f.page.data.allPageSelected, true);
});

for (const [field, changed] of [['business_version', 9], ['holding_version', 9], ['physical_version', 9], ['receivable', false]]) {
  test(`${field} change invalidates stale selection after reload`, async () => {
    const f = fixture(); f.lists.push(listing([row(1), row(2)])); await f.page.load(); f.page.togglePage();
    f.lists.push(listing([row(1, { [field]: changed }), row(2)])); await f.page.load();
    assert.deepEqual(plain(f.page.data.selectedRows.map(r => r.id)), [2]);
    assert.equal(f.page.data.selectedAmount, '0.1001'); assert.match(f.page.data.error, /已变化/);
  });
}

test('unchanged optional physical version does not invalidate quantity-managed remnant selection', async () => {
  const f = fixture();
  f.lists.push(listing([row(1, { physical_version: null }), row(2, { physical_version: undefined })]));
  await f.page.load(); f.page.togglePage();
  f.lists.push(listing([row(1, { physical_version: null }), row(2, { physical_version: undefined })])); await f.page.load();
  assert.deepEqual(plain(f.page.data.selectedRows.map(r => r.id)), [1, 2]);
  assert.equal(f.page.data.error, '');
});

test('source work order pagination updates header and retains material page and selection', async () => {
  const f = fixture(); f.lists.push(listing([row(11)], 2, { source_work_orders: { data: [{ work_order_no: 'WO-A' }, { work_order_no: 'WO-B' }], current_page: 1, last_page: 2 } }));
  f.page.setData({ page: 2 }); await f.page.load(); f.page.toggleRow(event({ id: 11 }));
  assert.equal(f.page.data.orderNo, 'CUT-41'); assert.equal(f.page.data.workOrderNos, 'WO-A、WO-B');
  f.lists.push(listing([row(11)], 2, { source_work_orders: { data: [{ work_order_no: 'WO-C' }], current_page: 2, last_page: 2 } }));
  await f.page.turnSource(event({ delta: 1 }));
  assert.equal(f.listCalls.at(-1)[1].source_page, 2); assert.equal(f.listCalls.at(-1)[1].page, 2);
  assert.equal(f.page.data.workOrderNos, 'WO-C'); assert.equal(f.page.data.sourcePage, 2); assert.equal(f.page.data.sourceLast, 2);
  assert.equal(f.page.data.selectedCount, 1);
});

test('post success followed by receipt read failure keeps receipt mode and retry only reads same receipt', async () => {
  const f = fixture(); f.lists.push(listing([row(1)])); await f.page.load(); f.page.toggleRow(event({ id: 1 }));
  f.page.openConfirm(); f.page.chooseLocator({ detail: { warehouse_id: 3, location_id: 31 } });
  f.results.push({ receipt_id: 901 }); f.receipts.push(new Error('network offline')); await f.page.submit();
  assert.equal(f.posts.length, 1); assert.equal(f.page.receiptId, 901); assert.equal(f.page.data.mode, 'receipt');
  assert.equal(f.page.data.receipt, null); assert.equal(f.page.data.pending, false); assert.equal(f.page.data.selectedCount, 0);
  assert.match(f.page.data.error, /network offline/); assert.equal(f.page.data.busy, false);
  f.receipts.push(receipt(901)); await f.page.retryReceipt();
  assert.equal(f.posts.length, 1); assert.deepEqual(f.receiptCalls, [[41, 901], [41, 901]]);
  assert.equal(f.page.data.receipt.id, 901); assert.equal(f.page.data.receipt.workOrderNos, 'WO-1');
});

test('pending page retry uses stored original warehouse command despite empty current form', async () => {
  const storage = new Map([['erp_user', { legacy_id: 17 }], ['erp_token', 'token']]);
  const calls = []; const replies = []; let created = 0;
  const module = { exports: {} };
  const wx = { getStorageSync: key => storage.get(key), setStorageSync: (key, value) => storage.set(key, value), removeStorageSync: key => storage.delete(key) };
  const request = { createClientCommandId: () => `remnant-original-${++created}`, request: args => { calls.push(plain(args));
    return Promise.resolve().then(() => { const next = replies.shift(); if (next instanceof Error) throw next; return next; }); } };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../services/warehouse.js'), 'utf8'), { module, wx,
    require: name => name.includes('erp-request') ? request : { getErpApiBaseUrl: () => 'https://erp.test' } });
  const service = module.exports;
  replies.push(new Error('lost response'));
  await assert.rejects(service.submit('remnant.warehouse', 41, { warehouse_id: 3, location_id: 31, lines: [{ result_id: 1, expected_version: 2 }] }));
  const first = calls[0].data;
  const f = fixture(service); f.page.setData({ pending: true, selectedRows: [], locator: {} });
  replies.push({ data: { status: 'NOT_FOUND' } }, { data: { action: 'remnant.warehouse', aggregate_id: 41, result: { receipt_id: 901 } } }, receipt(901));
  await f.page.retry();
  assert.equal(calls[1].path, 'inventory/warehouse-commands/result');
  assert.deepEqual(calls[2].data, first); assert.equal(created, 1);
  assert.equal(calls[3].path, 'production/cutting/orders/41/remnant-receipts/901');
  assert.equal(f.page.data.mode, 'receipt'); assert.equal(f.page.data.pending, false);
});

test('shared rows parser reads Laravel paginator, metadata envelope, and wrapped envelope consistently', () => {
  const helpers = require('../utils/warehouse-page'); const expected = { rows: [{ id: 11 }], page: 2, total: 11, lastPage: 2 };
  const laravel = { data: [{ id: 11 }], current_page: 2, total: 11, per_page: 10, last_page: 2 };
  const envelope = { data: [{ id: 11 }], meta: { current_page: 2, total: 11, per_page: 10, last_page: 2 } };
  assert.deepEqual(helpers.rows(laravel), expected); assert.deepEqual(helpers.rows(envelope), expected);
  assert.deepEqual(helpers.rows({ data: laravel }), expected); assert.deepEqual(helpers.rows({ data: envelope }), expected);
  assert.deepEqual(helpers.rows({ data: [], total: 0, current_page: 1, per_page: 10, last_page: 1 }), { rows: [], page: 1, total: 0, lastPage: 1 });
});
