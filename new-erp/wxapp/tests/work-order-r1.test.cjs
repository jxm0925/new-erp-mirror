const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const event = dataset => ({ currentTarget: { dataset } });
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { resolve, promise }; };
function mount(name, service, pendingCommand = () => null) {
  let page; const storage = { erp_token: 'fixture', erp_user: { id: 8 }, erp_permissions: ['production.work_order.create'] };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../pages/production', name, 'index.js'), 'utf8'), {
    Page: value => { page = value; }, require: name => name.includes('cutting-command') ? { pendingCommand } : service,
    wx: { getStorageSync: key => storage[key], setStorageSync: (key, value) => { storage[key] = value; }, removeStorageSync: key => { delete storage[key]; },
      navigateTo: value => { page.navigation = value.url; }, redirectTo: value => { page.navigation = value.url; }, showToast: value => { page.toast = value.title; },
      scanCode: ({ success }) => success({ result: 'WO/123' }),
      stopPullDownRefresh() {}, setNavigationBarTitle() {}, showModal() {}, getSystemInfoSync: () => ({}), getMenuButtonBoundingClientRect: () => null },
    getCurrentPages: () => [], setTimeout, clearTimeout, console,
  });
  page.setData = updates => { for (const [key, value] of Object.entries(updates)) { const fields = key.split('.'); let cursor = page.data; for (const part of fields.slice(0, -1)) cursor = cursor[part]; cursor[fields.at(-1)] = value; } };
  return page;
}
const response = (data, page = 1, last = 1) => ({ data, current_page: page, last_page: last, total: last });
test('all sources use actual WO and stale source responses cannot replace current list', async () => {
  const first = deferred(), calls = [];
  const page = mount('tasks', { workOrders: query => { calls.push(query); return calls.length === 1 ? first.promise : Promise.resolve(response([{ id: 2, work_order_no: 'WO2' }])); } });
  const old = page.load(); await page.setSource(event({ source: 'stock' })); first.resolve(response([{ id: 1 }])); await old;
  assert.equal(calls[0].source_type, 'sales_order'); assert.equal(calls[1].source_group, 'stock_prebuild'); assert.equal(page.data.rows[0].id, 2);
  page.openRow(event({ id: 2 })); assert.equal(page.navigation, '/pages/production/work-order-detail/index?id=2');
  await page.setSource(event({ source: 'other' })); assert.equal(calls[2].source_group, 'other');
});
test('detail loads quantity WO without MWO, displays output quantities and suppresses unauthorized task links', async () => {
  const calls = [];
  const page = mount('work-order-detail', { workOrder: async id => ({ data: { id, execution_mode: 'quantity', actions: { view_operations: true }, execution_summary: { quantity: { planned_qty: 20, completed_qty: 5 }, tasks: { total: 3, completed: 1 } } } }),
    workOrderOperations: async (id, query) => { calls.push([id, query]); return response([{ id: 33, status: 'IN_PROGRESS', quantity: { completed_qty: 7, unqualified_qty: 2, scrapped_qty: 1 }, task: null, actions: { view_task: false } }]); } });
  page.onLoad({ id: 7 }); await page.load(); assert.equal(calls[0][0], 7); assert.equal(page.data.masterId, 0);
  assert.equal(page.data.metrics[1].value, '5'); assert.equal(page.data.rows[0].completed, '7'); assert.equal(page.data.rows[0].unqualified, '2');
  page.openTask(event({ id: 33 })); assert.equal(page.navigation, undefined);
});
test('unit permissions do not block authorized WO information or trigger forbidden requests', async () => {
  const page = mount('work-order-detail', { workOrder: async () => ({ data: { id: 7, execution_mode: 'unit', actions: { view_units: false }, product: { item_name: '机架' } } }), workOrderUnits: () => { throw Error('must not request units'); } });
  page.onLoad({ workOrderId: 7, masterId: 8 }); await page.load(); assert.equal(page.data.workOrder.productName, '机架'); assert.equal(page.data.rowsForbidden, true); assert.equal(page.data.error, '');
});
test('PU filter is sent to server and a late append cannot pollute the new filter', async () => {
  const late = deferred(), calls = [];
  const page = mount('work-order-detail', { workOrderUnits: (id, query) => { calls.push(query); return calls.length === 1 ? late.promise : Promise.resolve(response([{ id: 3, display_status: 'WAIT_MATERIAL' }])); } });
  Object.assign(page.data, { workOrderId: 7, workOrder: {}, rows: [{ id: 1 }], rowPage: 1, rowHasMore: true });
  const append = page.loadRows(true); page.data.currentFilter = 'WAIT_MATERIAL'; await page.loadRows(); late.resolve(response([{ id: 2 }], 2, 2)); await append;
  assert.equal(calls[0].page, 2); assert.equal(calls[1].display_status, 'WAIT_MATERIAL'); assert.equal(page.data.rows.length, 1); assert.equal(page.data.rows[0].id, 3);
});
test('create selection changes clear dependent targets, saved draft target identity is immutable', () => {
  const page = mount('work-order-form', {}); page.loadRoute = () => Promise.resolve(); page.remember = () => {};
  Object.assign(page.data, { editable: true, picker: 'routing_operations', selected: { id: 8 }, operation: { id: 7 }, targetWorkOrder: { id: 90 }, targetUnit: { id: 91 }, targetOperation: { id: 92 } });
  page.confirmSelection(); assert.equal(page.data.targetWorkOrder.id, undefined); assert.equal(page.data.targetUnit.id, undefined);
  Object.assign(page.data, { id: 5, picker: 'routing_operations', selected: { id: 9 }, targetWorkOrder: { id: 90 }, targetOperation: { id: 92 } });
  page.confirmSelection(); assert.equal(page.data.targetWorkOrder.id, 90); page.setPurpose(event({ purpose: 'reserved_for_work_order' })); assert.equal(page.data.purpose, 'common_inventory');
});
test('reserved payload requires unit only for a unit target and edit omits immutable fields', async () => {
  const page = mount('work-order-form', {}); const calls = []; page.execute = async (action, data) => { calls.push(data); };
  Object.assign(page.data, { editable: true, item: { id: 1 }, routing: { id: 2 }, operation: { id: 3 }, reservation: 'r', purpose: 'reserved_for_work_order', form: { target_qty: '2', planned_date: '' }, targetWorkOrder: { id: 4, execution_mode: 'unit' }, targetOperation: { id: 5 } });
  await page.save(); assert.equal(calls.length, 0); page.data.targetUnit = { id: 6 }; await page.save(); assert.equal(calls[0].reserved_for_production_unit_id, 6);
  page.data.targetWorkOrder.execution_mode = 'quantity'; await page.save(); assert.equal(calls[1].reserved_for_production_unit_id, null);
  page.data.id = 8; page.version = 3; await page.save(); assert.equal(calls[2].expected_version, 3); assert.equal('stocking_purpose' in calls[2], false); assert.equal('reserved_for_work_order_id' in calls[2], false);
});

test('automatic sales WO edits planning only and recovers the separate pending plan command', async () => {
  const payload = { client_command_id: 'plan-original', expected_version: 7, production_location_name: '装配车间' };
  const page = mount('work-order-form', {}, path => path.endsWith('/plan') ? payload : null);
  Object.assign(page.data, { id: 8, editable: true, planOnly: true, form: { target_qty: '2', planned_date: '', production_location_name: '装配车间', production_batch: 'P1' } });
  page.version = 7; let sent;
  page.execute = async (action, body) => { sent = { action, body }; };
  page.input({ currentTarget: { dataset: { field: 'target_qty' } }, detail: { value: '100' } });
  assert.equal(page.data.form.target_qty, '2');
  await page.save(); assert.equal(sent.action, 'save-plan'); assert.equal(sent.body.expected_version, 7);
  assert.equal('production_location_name' in sent.body, false); assert.equal('production_batch' in sent.body, false);
  assert.equal('target_qty' in sent.body, false); assert.equal('production_routing_id' in sent.body, false);
  page.restorePending(); assert.equal(page.data.pending.action, 'save-plan'); assert.equal(page.data.pending.payload, payload);
  await page.retry(); assert.equal(sent.body, payload);
});
test('uncertain original command blocks edits and retries exact payload after page recreation', async () => {
  const payload = { client_command_id: 'original', target_qty: '2', expected_version: 3 }; let retained = payload; const calls = [];
  const page = mount('work-order-form', { save: async (id, body) => { calls.push(body); retained = null; return { data: { id: 8 } }; } }, () => retained);
  Object.assign(page.data, { id: 8, editable: true }); page.load = async () => {}; page.restorePending();
  page.input({ currentTarget: { dataset: { field: 'target_qty' } }, detail: { value: '999' } }); assert.equal(page.data.form.target_qty, '');
  await page.retry(); assert.equal(calls[0], payload); assert.equal(page.data.pending, null);
});
test('selector keeps selection across pages and ignores requests from a closed overlay', async () => {
  const late = deferred(); const page = mount('work-order-form', { options: () => late.promise });
  Object.assign(page.data, { picker: 'reserved_work_orders', selected: { id: 9, name: 'WO9' } });
  const loading = page.loadOptions(2); page.closePicker(); late.resolve(response([{ id: 1 }], 2, 3)); await loading;
  assert.equal(page.data.selected.id, 9); assert.equal(page.data.options.length, 0); assert.equal(page.data.picker, '');
});

test('legacy links, scan and no-stack unit return resolve to the actual WO or trace page', () => {
  const master = mount('master-detail', {});
  master.openWorkOrder(event({ id: 71 }));
  assert.equal(master.navigation, '/pages/production/work-order-detail/index?id=71');
  const unit = mount('unit-detail', {});
  unit.data.workOrderId = 71; unit.navBackToWorkOrder();
  assert.equal(unit.navigation, '/pages/production/work-order-detail/index?id=71');
  unit.data.workOrderId = 0; unit.navBackToWorkOrder();
  assert.equal(unit.navigation, '/pages/production/tasks/index');
  const list = mount('tasks', {}); list.scan();
  assert.equal(list.navigation, '/pages/production/queue/index?type=trace&keyword=WO%2F123');
});

test('detail distinguishes HTTP failures and never exposes released WO as an editable draft', async () => {
  for (const [status, code] of [[403, 'forbidden'], [404, 'not_found'], [500, 'load']]) {
    const page = mount('work-order-detail', { workOrder: async () => { throw { statusCode: status, message: 'failure' }; } });
    page.onLoad({ workOrderId: 71, masterId: 70 }); await page.load();
    assert.equal(page.data.errorCode, code); assert.equal(page.data.workOrder, null);
  }
  for (const status of ['DRAFT', 'RELEASED']) {
    const page = mount('work-order-detail', { workOrder: async () => ({ data: { id: 71, source_type: 'stock_prebuild', status, actions: { edit: status === 'DRAFT' } } }) });
    page.onLoad({ workOrderId: 71 }); await page.load();
    assert.equal(page.data.workOrder.canManage, false); assert.equal(page.editDraft, undefined);
  }
});


test('old creation and management links cannot reserve or publish; execution drawings stay read-only', async () => {
  const writes = () => { throw Error('must not manage from mini program'); };
  const page = mount('work-order-form', { reserve: writes, save: writes, transition: writes,
    detail: async () => ({ data: { id: 71, status: 'WAIT_RELEASE', source_type: 'stock_prebuild', technical_version: 1, actions: { edit: true, publish: true } } }) });
  page.onLoad({}); assert.equal(page.navigation, '/pages/production/tasks/index');
  page.onLoad({ id: '71' }); assert.equal(page.navigation, '/pages/production/work-order-detail/index?id=71');
  page.onLoad({ id: '71', tab: 'technical' }); await page.load();
  assert.equal(page.data.activeTab, 'technical'); assert.equal(page.data.editable, false);
  assert.equal(page.data.actions.publish, undefined); assert.equal(page.data.pending, null);
  const list = mount('tasks', { workOrders: async () => response([]) }); list.onShow();
  assert.equal(list.data.canCreate, false); assert.equal(list.createStock, undefined);
});

test('mini work-order service rejects management writes without sending a request', async () => {
  const calls = [], module = { exports: {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../services/work-orders.js'), 'utf8'), {
    module, require: () => ({ request: body => { calls.push(body); return Promise.resolve({ data: { id: 71 } }); } }),
  });
  for (const name of ['reserve', 'save', 'savePlan', 'transition']) {
    await assert.rejects(module.exports[name](71, 'publish', {}), error => error.code === 'work_order_pc_only');
  }
  assert.equal(calls.length, 0);
  await module.exports.detail(71); assert.equal(calls[0].path, 'production/work-orders/71');
});
