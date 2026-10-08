const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mount(storage, counter) {
  const module = { exports: {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../utils/production-decision-command.js'), 'utf8'), {
    module, require: () => ({ newCommandId: () => 'command-' + (++counter.value) }),
    wx: { getStorageSync: key => storage[key], setStorageSync: (key, value) => { storage[key] = value; },
      removeStorageSync: key => { delete storage[key]; } },
    Object, Number, Promise,
  });
  return module.exports;
}

test('uncertain decision reuses the original actor scoped command and payload after reopening the page', async () => {
  const storage = { erp_user: { legacy_id: 100 } }; const counter = { value: 0 };
  let first;
  await assert.rejects(mount(storage, counter).execute('reject_8', { expected_version: 1, expected_task_version: 2, reason: '已排任务' },
    async payload => { first = payload; throw Object.assign(new Error('timeout'), { errorCode: 'network_error' }); }));
  let replay;
  await mount(storage, counter).execute('reject_8', { expected_version: 3, reason: '修改后的原因' },
    async payload => { replay = payload; return {}; });
  assert.deepEqual(replay, first);
  assert.equal(counter.value, 1);
  assert.equal(Object.keys(storage).length, 1);
});

test('confirmed version conflict retires the command and a different actor has an independent key', async () => {
  const storage = { erp_user: { legacy_id: 100 } }; const counter = { value: 0 };
  await assert.rejects(mount(storage, counter).execute('accept_8', { expected_version: 1 },
    async () => { throw Object.assign(new Error('stale'), { statusCode: 409, errorCode: 'version_conflict' }); }));
  let next;
  storage.erp_user.legacy_id = 200;
  await mount(storage, counter).execute('accept_8', { expected_version: 2 }, async payload => { next = payload; });
  assert.equal(next.client_command_id, 'command-2');
  assert.equal(next.expected_version, 2);
});
