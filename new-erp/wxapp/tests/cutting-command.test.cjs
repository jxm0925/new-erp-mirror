const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function client(storage, request) {
  const module = { exports: {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../utils/cutting-command.js'), 'utf8'), {
    module, exports: module.exports,
    require: () => ({ request, createClientCommandId: () => `command-${Math.random()}` }),
    wx: {
      getStorageSync: key => storage[key],
      setStorageSync(key, value) { storage[key] = JSON.parse(JSON.stringify(value)); },
      removeStorageSync(key) { delete storage[key]; },
    },
  });
  return module.exports;
}

test('lost response followed by restart reuses original command and version', async () => {
  const storage = { erp_user: { legacy_id: 7 } }; const calls = [];
  const first = client(storage, async options => { calls.push(options.data); throw new Error('timeout'); });
  await assert.rejects(first.write('orders', { expected_version: 2, outputs: [{ id: 1 }] }, 'create'));
  const second = client(storage, async options => { calls.push(options.data); return { data: { id: 9 } }; });
  assert.equal((await second.write('orders', { expected_version: 3, outputs: [{ id: 1 }] }, 'create')).data.id, 9);
  assert.equal(calls[0].client_command_id, calls[1].client_command_id);
  assert.equal(calls[1].expected_version, 2);
  assert.equal(Object.keys(storage).length, 1);
});

for (const statusCode of [401, 403, 408, 429, 500]) {
  test(`unresolved command survives HTTP ${statusCode}`, async () => {
    const storage = { erp_user: { legacy_id: 7 } }; const ids = [];
    const api = client(storage, async options => { ids.push(options.data.client_command_id); throw Object.assign(new Error('unresolved'), { statusCode }); });
    await assert.rejects(api.write('orders', { expected_version: 2 }, 'create'));
    await assert.rejects(api.write('orders', { expected_version: 3 }, 'create'));
    assert.equal(ids[0], ids[1]);
    assert.equal(Object.keys(storage).length, 2);
  });
}

test('definitive validation permits corrected payload with a new command', async () => {
  const storage = { erp_user: { legacy_id: 7 } }; const ids = [];
  const api = client(storage, async options => { ids.push(options.data.client_command_id); throw Object.assign(new Error('invalid'), { statusCode: 422 }); });
  await assert.rejects(api.write('orders', { quantity: '0' }, 'create'));
  await assert.rejects(api.write('orders', { quantity: '2' }, 'create'));
  assert.notEqual(ids[0], ids[1]);
  assert.equal(Object.keys(storage).length, 1);
});

test('unresolved payload cannot silently change and is isolated by actor', async () => {
  const storage = { erp_user: { legacy_id: 7 } }; let calls = 0;
  const api = client(storage, async () => { calls += 1; throw new Error('timeout'); });
  await assert.rejects(api.write('orders', { quantity: '1' }, 'create'));
  await assert.rejects(api.write('orders', { quantity: '2' }, 'create'), /原内容重试/);
  assert.equal(calls, 1);
  storage.erp_user = { legacy_id: 8 };
  await assert.rejects(api.write('orders', { quantity: '2' }, 'create'), /timeout/);
  assert.equal(calls, 2);
});
