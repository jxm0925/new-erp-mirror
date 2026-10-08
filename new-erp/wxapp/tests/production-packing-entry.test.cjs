const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mount(permissions, production = {}) {
  let page; const navigations = [];
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../pages/production/queue/index.js'), 'utf8'), {
    Page: value => { page = value; },
    require: () => Object.assign({ taskPool: async () => ({ data: [], total: 0 }) }, production),
    wx: { getStorageSync: key => key === 'erp_permissions' ? permissions : 'token', setNavigationBarTitle() {},
      navigateTo: value => navigations.push(value), showToast() {} },
    Number, String, Object, Array, Promise,
  });
  page.data = JSON.parse(JSON.stringify(page.data)); page.setData = data => Object.assign(page.data, data);
  return { page, navigations };
}

test('production queue exposes the packing entry only to execution or quality accounts', () => {
  for (const permissions of [[], ['production.task.view'], ['sales_order.shipment.packing.execute'], ['sales_order.shipment.packing.quality']]) {
    const { page, navigations } = mount(permissions);
    page.onLoad({ type: 'pool' }); page.openPacking();
    const allowed = permissions.some(value => value.startsWith('sales_order.shipment.packing.'));
    assert.equal(page.data.canPacking, allowed);
    assert.equal(navigations.length, allowed ? 1 : 0);
    if (allowed) assert.equal(navigations[0].url, '/pages/production/shipment-packing/index');
  }
});

test('task queues send a zero classification filter to the server and keep other queue types unchanged', async () => {
  const requests = [];
  const { page } = mount(['production.task.view'], { taskPool: async query => {
    requests.push(query);
    return { total: 40, data: [{ id: query.page, is_public_snapshot: query.is_public === '1',
      operation: { is_public: query.is_public !== '1' }, target_details: [] }] };
  } });
  page.data.type = 'pool';
  await page.onPublicFilter({ currentTarget: { dataset: { value: 0 } } });
  assert.equal(requests[0].is_public, '0');
  assert.equal(requests[0].page, 1);
  assert.equal(page.data.rows[0].showPublicBadge, false);
  await page.load(true);
  assert.equal(requests[1].is_public, '0');
  assert.equal(requests[1].page, 2);
  assert.equal(requests[1].per_page, 20);
  await page.onPublicFilter({ currentTarget: { dataset: { value: '1' } } });
  assert.equal(requests[2].page, 1);
  assert.equal(page.data.rows[0].showPublicBadge, true);

  let deliveryQuery;
  const other = mount([], { deliveries: async query => { deliveryQuery = query; return { data: [], total: 0 }; } }).page;
  other.data.type = 'deliveries'; other.data.publicFilter = '0';
  await other.load();
  assert.equal(Object.hasOwn(deliveryQuery, 'is_public'), false);
  assert.equal(other.onPublicFilter({ currentTarget: { dataset: { value: '1' } } }), undefined);
});
