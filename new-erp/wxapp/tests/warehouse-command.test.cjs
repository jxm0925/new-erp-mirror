const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function fixture() {
  const storage = new Map([['erp_user', { legacy_id: 11 }], ['erp_token', 'token']]);
  const calls = []; const replies = []; let counter = 0;
  const wx = { getStorageSync: key => storage.get(key), setStorageSync: (key, value) => storage.set(key, value), removeStorageSync: key => storage.delete(key) };
  const request = { createClientCommandId: () => `warehouse-test-${++counter}`, request: args => { calls.push(JSON.parse(JSON.stringify(args))); return Promise.resolve().then(() => { const next = replies.shift(); if (next instanceof Error) throw next; return typeof next === 'function' ? next(args) : next; }); } };
  const module = { exports: {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../services/warehouse.js'), 'utf8'), { module, exports: module.exports, wx, require: name => name.includes('erp-request') ? request : { getErpApiBaseUrl: () => 'https://erp.test/api' } });
  return { service: module.exports, storage, replies, calls, wx };
}
const rejected = (status, errorCode) => Object.assign(new Error('request failed'), { statusCode: status, errorCode });
const success = (action = 'purchase.post', id = 7) => ({ data: { action, aggregate_id: id, result: { receipt_id: 71 } } });

test('lost response retries immutable original id and payload after NOT_FOUND', async () => {
  const f = fixture(); f.replies.push(rejected(0));
  const original = { items: [{ qty: '2', dimension: { length: 600 } }] };
  await assert.rejects(f.service.submit('purchase.post', 7, original));
  original.items[0].qty = '9';
  f.replies.push({ data: { status: 'NOT_FOUND' } }, success());
  await f.service.submit('purchase.post', 7, { items: [{ qty: '88' }] });
  assert.equal(f.calls[1].path, 'inventory/warehouse-commands/result');
  assert.equal(f.calls[1].query.client_command_id, f.calls[0].data.client_command_id);
  assert.deepEqual(f.calls[2].data, f.calls[0].data);
  assert.equal(f.calls[2].data.payload.items[0].qty, '2');
  assert.equal(f.service.pending('purchase.post', 7), null);
});

test('successful query consumes old command without another POST', async () => {
  const f = fixture(); f.replies.push(rejected(0)); await assert.rejects(f.service.submit('purchase.post', 7, {}));
  f.replies.push({ data: { status: 'SUCCEEDED', response: success().data } });
  assert.equal((await f.service.submit('purchase.post', 7, {})).receipt_id, 71);
  assert.equal(f.calls.filter(c => c.method === 'POST').length, 1);
  assert.equal(f.service.pending('purchase.post', 7), null);
});

test('failed result query retains unresolved command', async () => {
  const f = fixture(); f.replies.push(rejected(0)); await assert.rejects(f.service.submit('purchase.post', 7, {}));
  const command = f.service.pending('purchase.post', 7).client_command_id;
  f.replies.push(rejected(403)); await assert.rejects(f.service.submit('purchase.post', 7, {}), e => e.pendingCommand === true);
  assert.equal(f.service.pending('purchase.post', 7).client_command_id, command);
  assert.equal(f.calls.length, 2);
});

test('definitive FAILED query clears command for corrected submission', async () => {
  const f = fixture(); f.replies.push(rejected(0)); await assert.rejects(f.service.submit('purchase.post', 7, {}));
  f.replies.push({ data: { status: 'FAILED', response: { message: '库存不足', error_code: 'stock_short', status: 422 } } });
  await assert.rejects(f.service.submit('purchase.post', 7, {}), e => e.message === '库存不足' && !e.pendingCommand);
  assert.equal(f.service.pending('purchase.post', 7), null);
});

test('pending command is isolated by account', async () => {
  const f = fixture(); f.replies.push(rejected(0)); await assert.rejects(f.service.submit('purchase.post', 7, { qty: '2' }));
  const first = f.service.pending('purchase.post', 7).client_command_id;
  f.storage.set('erp_user', { legacy_id: 12 }); assert.equal(f.service.pending('purchase.post', 7), null);
  f.replies.push(success()); await f.service.submit('purchase.post', 7, { qty: '3' });
  assert.notEqual(f.calls[1].data.client_command_id, first);
  f.storage.set('erp_user', { legacy_id: 11 }); assert.equal(f.service.pending('purchase.post', 7).client_command_id, first);
});

test('double tap returns same promise and issues a single immutable POST', async () => {
  const f = fixture(); f.replies.push(success());
  const p = f.service.submit('purchase.post', 7, { qty: '2' });
  assert.equal(f.service.submit('purchase.post', 7, { qty: '99' }), p);
  await p; assert.equal(f.calls.length, 1); assert.equal(f.calls[0].data.payload.qty, '2');
});

for (const code of [401, 403, 408, 429]) test(`first HTTP ${code} without domain rejection retains command for recovery`, async () => {
  const f = fixture(); f.replies.push(rejected(code));
  await assert.rejects(f.service.submit('purchase.post', 7, { qty: '2' }), e => e.pendingCommand === true);
  assert.ok(f.service.pending('purchase.post', 7));
});

test('first definite domain validation rejection clears pending', async () => {
  const f = fixture(); f.replies.push(rejected(422, 'validation_error'));
  await assert.rejects(f.service.submit('purchase.post', 7, {}), e => !e.pendingCommand);
  assert.equal(f.service.pending('purchase.post', 7), null);
});

test('account switch before response cannot consume former account command', async () => {
  const f = fixture(); f.replies.push(() => { f.storage.set('erp_user', { legacy_id: 12 }); return success(); });
  await assert.rejects(f.service.submit('purchase.post', 7, {}), e => e.pendingCommand === true);
  f.storage.set('erp_user', { legacy_id: 11 }); assert.ok(f.service.pending('purchase.post', 7));
});
