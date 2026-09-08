const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mount(production, storage) {
  let page;
  const toasts = [];
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../pages/production/todos/index.js'), 'utf8'), {
    Page: value => { page = value; }, require: () => production,
    wx: {
      getStorageSync: key => storage[key],
      showToast: value => toasts.push(value),
      navigateTo() {}, stopPullDownRefresh() {},
    },
    Promise, Array, Number, Object,
  });
  page.data = JSON.parse(JSON.stringify(page.data));
  page.setData = function (value) { Object.assign(this.data, value); };
  return { page, toasts };
}

test('todo requests follow permissions and actionable backend rows', async () => {
  const calls = [];
  const ok = name => async () => { calls.push(name); return { data: [], total: 0 }; };
  const { page } = mount({
    taskPool: ok('taskPool'), myTasks: ok('myTasks'), deliveries: ok('deliveries'),
    pendingHandovers: ok('handovers'), pickingTasks: ok('picking'), outputs: ok('outputs'),
    internalIssues: ok('issues'), supplements: ok('supplements'), materialReturns: ok('returns'),
  }, { erp_token: 'token', erp_permissions: ['production.task.view'] });
  await page.load();
  assert.deepEqual(Array.from(new Set(calls)).sort(), ['issues', 'myTasks', 'outputs', 'returns', 'supplements', 'taskPool']);
  assert.equal(page.data.loading, false);
  assert.equal(page.data.groups.find(row => row.key === 'picking').count, null);
});

test('authorized request failure stays visible instead of becoming zero todos', async () => {
  const failure = Object.assign(new Error('服务不可用'), { errorCode: 'network_error' });
  const reject = async () => { throw failure; };
  const { page, toasts } = mount({
    taskPool: reject, myTasks: reject, deliveries: reject, pendingHandovers: reject,
    pickingTasks: reject, outputs: reject, internalIssues: reject, supplements: reject, materialReturns: reject,
  }, { erp_token: 'token', erp_permissions: ['production.task.view'] });
  await page.load();
  assert.equal(page.data.loading, false);
  assert.equal(toasts[0].title, '服务不可用');
  assert.equal(page.data.groups.find(row => row.key === 'pool').count, 0,
    '未完成的加载不应被覆盖为新的成功数据');
});
