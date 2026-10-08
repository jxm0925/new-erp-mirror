const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
function commands() {
  const storage = new Map([['erp_user', { legacy_id: 7 }], ['erp_token', 'authenticated']]);
  const calls = []; const replies = []; let sequence = 0;
  const wx = { getStorageSync: key => storage.get(key), setStorageSync: (key, data) => storage.set(key, structuredClone(data)), removeStorageSync: key => storage.delete(key), getStorageInfoSync: () => ({ keys: [...storage.keys()] }) };
  const request = { createClientCommandId: () => `command-${++sequence}`, request: args => { calls.push(structuredClone(args)); return Promise.resolve().then(() => { const next = replies.shift(); if (next instanceof Error) throw next; return typeof next === 'function' ? next() : next; }); } };
  const module = { exports: {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../utils/material-preparation.js'), 'utf8'), { module, wx, require: name => name.includes('config') ? { getErpApiBaseUrl: () => 'https://erp.test/api' } : request });
  return { service: module.exports, storage, calls, replies };
}
test('lost procurement response retains the immutable original command after editing the form', async () => {
  const f = commands(); const endpoint = 'production/material-procurement-requests';
  const input = { remark: '缺料', items: [{ item_id: 2, request_qty: '3' }] };
  f.replies.push(new Error('offline')); await assert.rejects(f.service.submit(endpoint, input));
  input.items[0].request_qty = '99'; f.replies.push({ data: { id: 11 } });
  const result = await f.service.submit(endpoint, input);
  assert.deepEqual(f.calls[1].data, f.calls[0].data); assert.equal(f.calls[1].data.items[0].request_qty, '3');
  assert.equal(result.recoveredCommand, true); assert.equal(f.service.pending(endpoint), null);
});
for (const statusCode of [401, 403, 404, 408, 429, 503]) test(`HTTP ${statusCode} retains unresolved onsite command even when no rows remain`, async () => {
  const f = commands(); const endpoint = 'production/material-picking-tasks/8/onsite-receive';
  f.replies.push(Object.assign(new Error('unconfirmed'), { statusCode }));
  await assert.rejects(f.service.submit(endpoint, { lines: [{ accepted_qty: '2' }] }));
  assert.equal(f.service.pendingList('production/material-picking-tasks/').length, 1);
  const original = f.service.pending(endpoint).client_command_id;
  f.storage.set('erp_user', { legacy_id: 9 }); assert.equal(f.service.pending(endpoint), null);
  f.storage.set('erp_user', { legacy_id: 7 }); assert.equal(f.service.pending(endpoint).client_command_id, original);
});
test('double tapping produces one POST and a definitive validation error permits correction', async () => {
  const f = commands(); f.replies.push(Object.assign(new Error('invalid quantity'), { statusCode: 422, errorCode: 'validation_error' }));
  const first = f.service.submit('production/public-material-preparations', { lines: [] });
  assert.equal(f.service.submit('production/public-material-preparations', { lines: [1] }), first);
  await assert.rejects(first); assert.equal(f.calls.length, 1); assert.equal(f.service.pending('production/public-material-preparations'), null);
});
test('changing accounts during a response cannot consume the old accounts command', async () => {
  const f = commands(); f.replies.push(() => { f.storage.set('erp_user', { legacy_id: 9 }); return { data: { id: 2 } }; });
  await assert.rejects(f.service.submit('production/material-procurement-requests', {}), /账号已变化/);
  f.storage.set('erp_user', { legacy_id: 7 }); assert.ok(f.service.pending('production/material-procurement-requests'));
});

function pageFixture(page, service = {}) {
  let definition; const writes = []; const storage = new Map([['erp_user', { legacy_id: 7 }]]);
  const wx = { getStorageSync: key => storage.get(key), setStorageSync: (key, value) => storage.set(key, structuredClone(value)), showToast() {} };
  const modules = { warehouse: service, 'warehouse-page': { ...require('../utils/warehouse-page'), permissions: () => () => true }, 'warehouse-picking': require('../utils/warehouse-picking'),
    'material-preparation': { pending: () => null, submit: async (endpoint, body) => { writes.push({ endpoint, body: structuredClone(body) }); return { data: { id: 10 } }; } } };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, `../pages/warehouse/${page}/index.js`), 'utf8'), { Page: value => { definition = value; }, wx,
    require: name => name.includes('config') ? { getErpApiBaseUrl: () => 'https://erp.test/api' } : Object.entries(modules).find(([key]) => name.endsWith('/' + key))[1] });
  const instance = { ...definition, data: structuredClone(definition.data), setData(patch) { Object.assign(this.data, patch); } };
  return { page: instance, writes };
}
test('native public task retains cross-order source quantities and requires every line including explicit zero', async () => {
  const { page } = pageFixture('public-preparation'); page.drafts = {
    1: { demand: { id: 1, work_order_id: 10, work_order_version: 2, remaining_to_prepare: 5 }, sources: [{ id: 11, selected_qty: '2' }] },
    2: { demand: { id: 2, work_order_id: 20, work_order_version: 3, remaining_to_prepare: 9 }, sources: [{ id: 22, selected_qty: '3' }] } };
  page.data.warehouse = { id: 7 }; let created;
  page.run = async (endpoint, body) => { created = body; };
  page.create(); assert.equal(created.lines.length, 2); assert.equal(created.work_order_versions[20], 3); assert.equal(created.lines[1].inventory_balance_id, 22);
  page.data.total = 2; page.lineCache = { 1: { planned_pick_qty: 5 }, 2: { planned_pick_qty: 5 } }; page.tracked = {}; page.actuals = { 1: '2' };
  let confirmation; page.action = async (_, payload) => { confirmation = payload; };
  page.confirm(); assert.equal(confirmation, undefined); assert.match(page.data.error, /各页/);
  page.actuals[2] = '0'; await page.confirm(); assert.equal(confirmation.lines[1].actual_pick_qty, '0');
});
test('native procurement rejects blank quantity and creates an order-linked formal request after explicit input', async () => {
  const { page, writes } = pageFixture('material-procurement');
  page.data.canSubmit = true; page.data.mode = 'order'; page.data.order = { id: 55 }; page.data.remark = '订单缺料'; page.data.lines = [{ id: 7, request_qty: '', unit_name: '张' }];
  await page.submit(); assert.equal(writes.length, 0);
  page.data.lines[0].request_qty = '2'; await page.submit();
  assert.equal(writes[0].body.sales_order_id, 55); assert.equal(writes[0].body.items[0].request_qty, '2'); assert.equal(page.data.result.id, 10);
});
