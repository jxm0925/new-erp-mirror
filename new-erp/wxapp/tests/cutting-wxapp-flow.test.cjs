const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function assignData(target, updates) {
  Object.entries(updates).forEach(([key, value]) => {
    const parts = key.split('.');
    let cursor = target;
    parts.slice(0, -1).forEach(part => { cursor[part] = cursor[part] || {}; cursor = cursor[part]; });
    cursor[parts[parts.length - 1]] = value;
  });
}

function mount(relativePath, cutting, storage = {}) {
  let page;
  const module = { exports: {} };
  const source = fs.readFileSync(path.join(__dirname, '..', relativePath), 'utf8');
  vm.runInNewContext(source, {
    Page(value) { page = value; }, module, exports: module.exports,
    require: name => name.includes('services/cutting') ? cutting : {},
    wx: {
      getStorageSync: key => storage[key] || '',
      setStorageSync(key, value) { storage[key] = JSON.parse(JSON.stringify(value)); },
      removeStorageSync(key) { delete storage[key]; },
      navigateTo(value) { page.__navigation = value; },
      redirectTo(value) { page.__navigation = value; if (page.__redirectError) value.fail(); else value.success(); },
      showToast(value) { page.__toast = value; }, showModal() {}, stopPullDownRefresh() {},
    },
    Promise, Number, String, Object, Array, Math, Date, JSON, console, setTimeout, clearTimeout,
  });
  page.data = JSON.parse(JSON.stringify(page.data));
  page.setData = function setData(updates) { assignData(this.data, updates); };
  page.__helpers = module.exports;
  return page;
}

test('cutting task list uses server scope and pagination and carries both ids', async () => {
  const calls = [];
  const page = mount('pages/production/cutting-tasks/index.js', {
    listTasks: async query => { calls.push(query); return { data: [{ id: 8, cutting_order_id: 3, task_no: 'CT-8', status: 'READY', display_status: '待开工' }], meta: { current_page: 1, last_page: 2, total: 21 } }; },
  });
  page.data.scope = 'mine';
  await page.fetchPage(1, false);
  assert.equal(calls[0].scope, 'mine');
  assert.equal(calls[0].per_page, 20);
  assert.equal(page.data.tasks[0].display_status, '待开工');
  assert.equal(page.data.tasks[0].output_summary, '待加工产出');
  assert.equal(page.data.lastPage, 2);
  assert.equal(page.data.total, 21);
  page.data.keyword = 'CUT2026';
  await page.clearKeyword();
  assert.equal(page.data.keyword, '');
  page.openTask({ currentTarget: { dataset: { taskId: 8, orderId: 3 } } });
  assert.equal(page.__navigation.url, '/pages/production/cutting-order-detail/index?taskId=8&orderId=3');
});

test('multi physical inputs use one atomic issue command', async () => {
  const calls = [];
  const cutting = {
    issuePhysicals: async (id, body) => { calls.push(['issuePhysicals', id, body]); return { data: { order_business_version: 13 } }; },
  };
  const page = mount('pages/production/cutting-order-detail/index.js', cutting);
  page.load = async () => {};
  Object.assign(page.data, { orderId: 7, order: { business_version: 10 }, inputType: 'physical', selectedInputs: [{ id: 31 }, { id: 32 }] });
  await page.confirmAddInput();
  assert.deepEqual(JSON.parse(JSON.stringify(calls)), [
    ['issuePhysicals', 7, { expected_version: 10, physical_material_ids: [31, 32] }],
  ]);
});

test('record payload contains only accepted result fields and route saving uses fresh result versions', async () => {
  const calls = [];
  let reads = 0;
  const cutting = {
    saveSettlement: async (id, body) => { calls.push(['save', id, body]); return { data: { business_version: 2 } }; },
    settlementExecution: async () => {
      reads += 1;
      return { data: { source: { business_version: reads + 2 }, results: { data: [{ id: 91, client_row_id: 'p-1', result_type: 'product', business_version: 4 }] } } };
    },
    splitRoutes: async (id, body) => { calls.push(['split', id, body]); return { data: {} }; },
  };
  const page = mount('pages/production/cutting-record/index.js', cutting);
  Object.assign(page.data, {
    settlementId: 5, source: { business_version: 1 },
    products: [{ client_row_id: 'p-1', result_type: 'product', allowed_output_id: 17, actual_qty: '10', measurement_status: 'NOT_RECORDED', routes: [
      { route_type: 'NEXT_OPERATION', quantity: '6', target_material_requirement_id: 88, target_label: 'display only', editable: true },
      { route_type: 'WAREHOUSE', quantity: '4', status: 'PLANNED', editable: true },
    ] }],
    others: [
      { type: 'usable_remnant', client_row_id: 'other-usable_remnant', measurement_status: 'MEASURED', actual_qty: '1', measurements: { length_mm: '620', width_mm: '210' } },
      { type: 'recyclable_scrap', client_row_id: 'other-recyclable_scrap', measurement_status: 'NOT_MEASURED', actual_qty: '', measurements: {} },
      { type: 'process_loss', client_row_id: 'other-process_loss', measurement_status: 'NOT_RECORDED', actual_qty: '', measurements: {} },
      { type: 'scrapped_output', client_row_id: 'other-scrapped_output', measurement_status: 'MEASURED', actual_qty: '0', measurements: {} },
    ],
  });
  const finalPayload = await page.saveAndRoute();
  const saved = calls[0][2];
  assert.equal(saved.expected_version, 1);
  assert.deepEqual(Object.keys(saved.results[0]).sort(), ['actual_qty', 'allowed_output_id', 'client_row_id', 'measurement_status', 'result_type']);
  assert.deepEqual(Array.from(saved.results, row => row.result_type), ['product', 'usable_remnant', 'recyclable_scrap', 'process_loss', 'scrapped_output']);
  assert.equal(Object.hasOwn(saved.results[2], 'actual_qty'), false);
  assert.match(calls[1][2].client_command_id, /^cut-job-.*-route-0$/);
  const split = JSON.parse(JSON.stringify(calls[1])); delete split[2].client_command_id;
  assert.deepEqual(split, ['split', 91, { expected_version: 4, routes: [
    { route_type: 'NEXT_OPERATION', quantity: '6', target_material_requirement_id: 88 },
    { route_type: 'WAREHOUSE', quantity: '4' },
  ] }]);
  assert.equal(finalPayload.source.business_version, 4);
});

test('result workflow survives restart at a route timeout and retains submit intent', async () => {
  const storage = {};
  const calls = []; let failRoute = true;
  const payload = { source: { business_version: 8, status: 'PROCESSING' }, results: { data: [
    { id: 91, client_row_id: 'p-1', result_type: 'product', business_version: 4 },
  ] } };
  const api = {
    settlementExecution: async () => ({ data: payload }),
    saveSettlement: async (id, body) => { calls.push(['save', body]); },
    splitRoutes: async (id, body) => { calls.push(['route', body]); if (failRoute) { failRoute = false; throw new Error('network timeout'); } },
    submitSettlement: async (id, body) => { calls.push(['submit', body]); },
  };
  const first = mount('pages/production/cutting-record/index.js', api, storage);
  await first.onLoad({ settlementId: 5 });
  first.setData({ products: [{ client_row_id: 'p-1', allowed_output_id: 17, actual_qty: '10', routes: [{ route_type: 'WAREHOUSE', quantity: '10' }] }], others: [] });
  await assert.rejects(first.runSaveJob(true, true), /timeout/);
  const second = mount('pages/production/cutting-record/index.js', api, storage);
  await second.onLoad({ settlementId: 5 });
  assert.equal(second.data.editableResults, false);
  assert.equal(second.data.pendingSave, true);
  await second.saveDraft();
  assert.deepEqual(calls.map(row => row[0]), ['save', 'route', 'route', 'submit']);
  assert.equal(calls[1][1].client_command_id, calls[2][1].client_command_id);
  assert.equal(calls[1][1].expected_version, calls[2][1].expected_version);
  assert.equal(second.pendingJob, null);
  assert.equal(storage[second.jobKey], undefined);
});

test('server validation after restoring a job unlocks correction without dropping local edits', async () => {
  const api = {
    settlementExecution: async () => ({ data: { source: { business_version: 12, status: 'PROCESSING' }, results: { data: [] } } }),
    saveSettlement: async () => { throw Object.assign(new Error('invalid quantity'), { statusCode: 422 }); },
  };
  const page = mount('pages/production/cutting-record/index.js', api);
  page.pendingJob = { commandId: 'stable-job', needsSave: true, saved: false, version: 7, results: [], products: [{ actual_qty: 'bad' }], others: [] };
  await page.load();
  assert.equal(page.data.editableResults, false);
  await assert.rejects(page.runSaveJob(false, false), /invalid quantity/);
  assert.equal(page.data.editableResults, true);
  assert.equal(page.data.editableRoutes, true);
  assert.equal(page.data.source.business_version, 12);
  assert.equal(page.data.products[0].actual_qty, 'bad');
  assert.equal(page.data.pendingSave, false);
});

test('restart after response before checkpoint retains the same save command identity', async () => {
  const storage = {}; const saves = [];
  const api = {
    settlementExecution: async () => ({ data: { source: { business_version: 4, status: 'PROCESSING' }, results: { data: [] } } }),
    saveSettlement: async (id, body) => { saves.push(body); },
  };
  const first = mount('pages/production/cutting-record/index.js', api, storage);
  await first.onLoad({ settlementId: 5 });
  first.setData({ products: [{ client_row_id: 'p-1', allowed_output_id: 17, actual_qty: '2', routes: [] }], others: [] });
  const persist = first.storeJob.bind(first);
  first.storeJob = job => { if (job && job.saved) throw new Error('app terminated before checkpoint'); persist(job); };
  await assert.rejects(first.runSaveJob(false, true), /terminated/);
  const second = mount('pages/production/cutting-record/index.js', api, storage);
  await second.onLoad({ settlementId: 5 });
  await second.runSaveJob(false, true);
  assert.equal(saves.length, 2);
  assert.equal(saves[0].client_command_id, saves[1].client_command_id);
  assert.equal(saves[0].expected_version, saves[1].expected_version);
});

test('switching back to next operation reloads targets and ignores obsolete failures', async () => {
  let rejectOld;
  const page = mount('pages/production/cutting-record/index.js', {
    handoverTargets: () => new Promise((resolve, reject) => { rejectOld = reject; }),
  });
  page.data.splitProduct = { id: 91 };
  const request = page.searchTargets();
  page.setRouteType({ currentTarget: { dataset: { type: 'WAREHOUSE' } } });
  rejectOld(new Error('obsolete request'));
  await request;
  assert.equal(page.__toast, undefined);
  let reloads = 0;
  page.searchTargets = async () => { reloads += 1; };
  await page.setRouteType({ currentTarget: { dataset: { type: 'NEXT_OPERATION' } } });
  assert.equal(reloads, 1);
});

test('created order survives redirect failure and restart without a second creation identity', async () => {
  const storage = { erp_user: { legacy_id: 71 } }; const calls = [];
  const api = { createOrder: async body => { calls.push(JSON.parse(JSON.stringify(body))); return { data: { cutting_order_id: 42, cutting_task_id: 51 } }; } };
  const first = mount('pages/production/cutting-create/index.js', api, storage);
  first.onLoad(); first.__redirectError = true;
  first.setData({ selected: [{ inventory_balance_id: 5, _key: 'quantity:5', input_qty: '6', quantity_editable: true }] });
  await first.create();
  assert.ok(storage[first.intentKey]);
  first.onQuantity({ currentTarget: { dataset: { index: 0 } }, detail: { value: '99' } });
  assert.equal(first.data.selected[0].input_qty, '6');
  const second = mount('pages/production/cutting-create/index.js', api, storage);
  second.onLoad(); await second.create();
  assert.equal(calls.length, 2);
  assert.deepEqual(calls[0], calls[1]);
  assert.deepEqual(calls[0].inputs, [{ inventory_balance_id: 5, input_qty: '6' }]);
  assert.equal(Object.hasOwn(calls[0], 'outputs'), false);
  assert.equal(storage[second.intentKey], undefined);
  assert.equal(second.__navigation.url, '/pages/production/cutting-order-detail/index?taskId=51&orderId=42');
});

test('cutting pages preserve selector and content-flow design constraints', () => {
  const detailJs = fs.readFileSync(path.join(__dirname, '../pages/production/cutting-order-detail/index.js'), 'utf8');
  const recordJs = fs.readFileSync(path.join(__dirname, '../pages/production/cutting-record/index.js'), 'utf8');
  const detailWxml = fs.readFileSync(path.join(__dirname, '../pages/production/cutting-order-detail/index.wxml'), 'utf8');
  const recordWxml = fs.readFileSync(path.join(__dirname, '../pages/production/cutting-record/index.wxml'), 'utf8');
  const recordWxss = fs.readFileSync(path.join(__dirname, '../pages/production/cutting-record/index.wxss'), 'utf8');
  assert.match(detailJs, /keyword:[\s\S]*category_id:[\s\S]*page,[\s\S]*per_page:\s*20/);
  assert.match(recordJs, /keyword:[\s\S]*category_id:[\s\S]*per_page:\s*20[\s\S]*allowedOutputs/);
  assert.match(detailWxml, /支持跨页多选/);
  assert.match(recordWxml, /来源由当前用料批次确定，不可切换/);
  assert.match(recordWxml, /提交加工结果不代表|指定去向不代表/);
  assert.doesNotMatch(recordWxss, /\.content-actions\s*\{[^}]*position:\s*fixed/s);
});

test('new order selects only materials and products are selected once on the later record page', async () => {
  const queries = [];
  const create = mount('pages/production/cutting-create/index.js', {
    workerInputs: async query => { queries.push(query); return { data: [{ id: 31, physical_no: 'PLATE31', item_name: '钢板' }], meta: { current_page: 1, last_page: 1, total: 1 } }; },
    workerInputCategories: async () => ({ data: [], meta: {} }),
    createOrder: async body => { assert.equal(Object.hasOwn(body, 'outputs'), false); return { data: { cutting_order_id: 2, cutting_task_id: 3, batches: [{ settlement_batch_id: 4 }] } }; },
  });
  create.onLoad(); await create.openPicker();
  create.toggle({ currentTarget: { dataset: { key: 'physical:31' } } }); create.confirmPicker(); await create.create();
  assert.equal(queries[0].input_type, 'physical');
  assert.equal(queries[0].per_page, 20);
  assert.equal(create.__navigation.url, '/pages/production/cutting-order-detail/index?taskId=3&orderId=2');
  const record = mount('pages/production/cutting-record/index.js', {
    workerOutputs: async query => { queries.push(query); return { data: [{ id: 81, configuration_id: 12, item_name: '侧板', configuration_dimensions: { length_mm: '200', width_mm: '100' } }], meta: {} }; },
  });
  record.setData({ order: { purpose: 'WORKER' }, source: { physical_material_id: 31 }, outputQty: '6' });
  await record.loadOutputs(1);
  record.pickOutput({ currentTarget: { dataset: { key: '81:12' } } }); record.confirmOutput();
  const row = record.buildResultsPayload()[0];
  assert.equal(row.item_id, 81);
  assert.equal(row.configuration_id, 12);
  assert.equal(row.actual_qty, '6');
  assert.equal(Object.hasOwn(row, 'allowed_output_id'), false);
  const markup = fs.readFileSync(path.join(__dirname, '../pages/production/cutting-create/index.wxml'), 'utf8');
  assert.doesNotMatch(markup, /选择产品|选择下料产品|planned_qty|要下什么/);
});
