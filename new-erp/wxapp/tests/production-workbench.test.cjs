const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mount(production) {
  let page;
  const navigations = [];
  const source = fs.readFileSync(path.join(__dirname, '../pages/production/workbench/index.js'), 'utf8');
  vm.runInNewContext(source, {
    Page: value => { page = value; },
    require: moduleName => moduleName.includes('production') ? production : { BadgePopup() {} },
    wx: {
      getStorageSync: key => {
        if (key === 'erp_token') return 'real-token';
        if (key === 'erp_permissions') return ['production.task.view', 'production.work_order.view', 'production.material_picking.view'];
        return '';
      },
      navigateTo: value => navigations.push(value),
      stopPullDownRefresh() {},
      showToast() {},
      showActionSheet() {},
    },
    Number, String, Object, Array, Promise, Math,
  });
  page.data = JSON.parse(JSON.stringify(page.data));
  page.setData = function (value) { Object.assign(this.data, value); };
  page.__navigations = navigations;
  return page;
}

test('workbench renders real overview and four approved entry counts', async () => {
  const calls = [];
  const page = mount({
    workbenchSummary: async () => ({ data: {
      total: 20, running: 4, waiting: 3, completed: 12, exception: 1, completion_rate: 60,
      trend: [{ date: '2026-09-11', label: '09/11', assigned: 2, completed: 1 }]
    } }),
    masterOrders: async query => { calls.push(['orders', query]); return { total: 6 }; },
    outputs: async query => { calls.push(['warehouse', query]); return { total: 2 }; },
    pickingTasks: async query => { calls.push(['picking', query]); return { total: 5 }; },
  });

  await page.load();
  assert.equal(page.data.overview.total, 20);
  assert.equal(page.data.overview.completionRate, '60%');
  assert.equal(page.data.trend[0].activity, 2);
  assert.deepEqual(Array.from(page.data.trendTicks), [2, 1, 0]);
  assert.deepEqual(Array.from(page.data.shortcuts, item => item.count), [6, 2, 5, 20]);
  assert.equal(calls.every(([, query]) => query.page === 1 && query.per_page === 1), true);
  page.openShortcut({ currentTarget: { dataset: { key: 'orders' } } });
  page.openShortcut({ currentTarget: { dataset: { key: 'picking' } } });
  page.openShortcut({ currentTarget: { dataset: { key: 'tasks' } } });
  assert.deepEqual(page.__navigations.map(item => item.url), [
    '/pages/production/tasks/index',
    '/pages/production/queue/index?type=picking',
    '/pages/production/my-tasks/index'
  ]);
});

test('workbench trend uses dynamic integer ticks for a weekly peak of one hundred', async () => {
  const page = mount({
    workbenchSummary: async () => ({ data: {
      total: 100, running: 0, waiting: 0, completed: 100, exception: 0, completion_rate: 100,
      trend: [
        { date: '2026-09-10', label: '09/10', assigned: 100, completed: 80 },
        { date: '2026-09-11', label: '09/11', assigned: 0, completed: 20 }
      ]
    } }),
    masterOrders: async () => ({ total: 0 }),
    outputs: async () => ({ total: 0 }),
    pickingTasks: async () => ({ total: 0 }),
  });

  await page.load();
  assert.equal(page.data.trend[0].activity, 100);
  assert.equal(page.data.trend[1].activity, 20);
  assert.deepEqual(Array.from(page.data.trendTicks), [100, 75, 50, 25, 0]);
});

test('workbench source preserves approved responsive two-by-two structure', () => {
  const wxml = fs.readFileSync(path.join(__dirname, '../pages/production/workbench/index.wxml'), 'utf8');
  const wxss = fs.readFileSync(path.join(__dirname, '../pages/production/workbench/index.wxss'), 'utf8');
  assert.match(wxml, /生产概况/);
  assert.match(wxml, /近7日生产趋势/);
  assert.match(wxml, /trendChart/);
  assert.match(wxss, /repeat\(2, minmax\(0, 1fr\)\)/);
  assert.match(wxss, /@media \(max-width: 360px\)/);
  assert.match(wxss, /@media \(min-width: 431px\)/);
});
