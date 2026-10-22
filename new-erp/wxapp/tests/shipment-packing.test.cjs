const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const plain = value => JSON.parse(JSON.stringify(value));

function serviceFixture() {
  const storage = new Map([['erp_user', { legacy_id: 44 }], ['erp_token', 'test-token']]);
  const requests = []; const results = []; let counter = 0;
  const wx = { getStorageSync: key => storage.get(key), setStorageSync: (key, value) => storage.set(key, plain(value)), removeStorageSync: key => storage.delete(key) };
  const context = { module: { exports: {} }, wx, require: name => name.includes('config')
    ? { getErpApiBaseUrl: () => 'http://erp.test/api/v1/erp/' }
    : { createClientCommandId: () => `packing-${++counter}`, request: options => { requests.push(plain(options)); const result = results.shift(); return result instanceof Error ? Promise.reject(result) : Promise.resolve(result); } } };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../services/packing.js'), 'utf8'), context);
  return { packing: context.module.exports, storage, requests, results };
}

test('unknown material request is persisted and server success is recovered before any second stock write', async () => {
  const f = serviceFixture();
  f.results.push(Object.assign(new Error('network lost'), { statusCode: 0 }));
  await assert.rejects(f.packing.action(71, { action: 'materials', expected_version: 3, materials: [{ inventory_balance_id: 12, base_qty: 2 }] }), error => error.pendingCommand);
  const original = f.packing.pending(71);
  f.results.push({ data: { status: 'SUCCEEDED', response: { operation_id: 71, business_version: 4 } } });
  await f.packing.action(71, { action: 'materials', expected_version: 3, materials: [{ inventory_balance_id: 99, base_qty: 7 }] });
  assert.equal(f.requests.length, 2);
  assert.equal(f.requests[1].path, 'production/shipment-packing/commands/result');
  assert.equal(f.requests[1].query.client_command_id, original.payload.client_command_id);
  assert.equal(f.packing.pending(71), null);
});

test('not-found recovery replays the exact frozen quantity, identities and command rather than the edited form', async () => {
  const f = serviceFixture();
  const initial = { action: 'materials', expected_version: 3, materials: [{ inventory_balance_id: 12, base_qty: 1, physical_material_ids: [63] }] };
  f.results.push(Object.assign(new Error('network lost'), { statusCode: 0 }));
  await assert.rejects(f.packing.action(71, initial));
  f.results.push({ data: { status: 'NOT_FOUND' } }, { data: { operation_id: 71, business_version: 4 } });
  await f.packing.action(71, { action: 'complete', completed_base_qty: 99 });
  assert.deepEqual(f.requests[0].data, f.requests[2].data);
  assert.equal(f.requests[2].data.materials[0].physical_material_ids[0], 63);
  assert.equal(f.requests[2].data.materials[0].base_qty, 1);
});

test('another account cannot consume the previous account pending request', async () => {
  const f = serviceFixture();
  f.results.push(new Error('network lost'));
  await assert.rejects(f.packing.action(71, { action: 'start', expected_version: 3 }));
  f.storage.set('erp_user', { legacy_id: 45 });
  assert.equal(f.packing.pending(71), null);
  f.results.push({ data: { operation_id: 71, business_version: 4 } });
  await f.packing.action(71, { action: 'start', expected_version: 3 });
  assert.notEqual(f.requests[0].data.client_command_id, f.requests[1].data.client_command_id);
  f.storage.set('erp_user', { legacy_id: 44 });
  assert.ok(f.packing.pending(71));
});

function pageFixture(wxOverrides = {}) {
  let definition;
  const calls = []; const actions = []; const people = []; const materials = []; const identities = [];
  const service = { pending: () => null, operations: () => Promise.resolve({ data: { data: [], total: 0, current_page: 1, last_page: 1 } }),
    operation: () => Promise.resolve({ data: { id: 10, participants: [], contents: [], materials: [], allowed_actions: {} } }),
    people: (_id, query) => { calls.push(plain(query)); return Promise.resolve(people.shift()); },
    materials: (_id, query) => { calls.push(plain(query)); return Promise.resolve(materials.shift()); },
    identities: (_id, query) => { calls.push(plain(query)); return Promise.resolve(identities.shift()); },
    action: (_id, data) => { actions.push(plain(data)); return Promise.resolve({ operation_id: 10 }); } };
  const helpers = { permissions: () => () => true, quantity: value => String(value), errorText: e => e.message,
    rows: response => ({ rows: response.data.data, page: response.data.current_page, lastPage: response.data.last_page, total: response.data.total }), back() {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../pages/production/shipment-packing/index.js'), 'utf8'), {
    Page: value => { definition = value; }, wx: Object.assign({ showToast() {} }, wxOverrides), require: name => name.includes('services') ? service : name.includes('personal-shares') ? require('../utils/personal-shares') : name.includes('packing-material-usage') ? require('../utils/packing-material-usage') : helpers,
  });
  const page = Object.assign({}, definition, { data: plain(definition.data), peopleSelected: {}, materialSelected: {}, setData(values) { Object.assign(this.data, values); } });
  page.data.operation = { id: 10, business_version: 3, owner_legacy_id: 44, participants: [{ employee_legacy_id: 44, name: '真实接单人', role: 'OWNER', is_active: true }] };
  return { page, actions, calls, people, materials, identities, service };
}
const paged = rows => ({ data: { data: rows, current_page: 1, last_page: 2, total: 11 } });
const event = id => ({ currentTarget: { dataset: { id } } });

test('owner-selected collaborators survive search and pagination and can be removed from selected list', async () => {
  const f = pageFixture();
  f.people.push(paged([{ legacy_id: 101, nickname: '甲', username: 'a' }]));
  await f.page.openPeople(); f.page.togglePerson(event(101));
  f.people.push(paged([{ legacy_id: 102, nickname: '乙', username: 'b' }]));
  await f.page.loadPeople(); f.page.togglePerson(event(102));
  f.page.togglePerson(event(101)); await f.page.savePeople();
  assert.deepEqual(f.actions[0].employee_legacy_ids, [102]);
  assert.equal(f.actions[0].action, 'collaborators');
  assert.equal(f.actions[0].expected_version, 3);
});

test('packing owner confirms manual seventy thirty and keeps a past actual contributor in its own operation', async () => {
  const f = pageFixture();
  f.people.push(paged([{ legacy_id: 101, nickname: '协同人' }])); await f.page.openPeople(); f.page.togglePerson(event(101));
  f.page.peopleShareInput({ currentTarget: { dataset: { id: 44 } }, detail: { value: '70' } });
  f.page.peopleShareInput({ currentTarget: { dataset: { id: 101 } }, detail: { value: '30' } });
  await f.page.savePeople();
  assert.deepEqual(f.actions[0].shares.map(person => person.share_ratio), ['0.70000000', '0.30000000']);
  assert.deepEqual(f.actions[0].shares.map(person => person.employee_legacy_id), [44, 101]);

  const past = pageFixture(); past.page.data.operation.participants.push({ employee_legacy_id: 61, name: '已参与协同人', role: 'COLLABORATOR', is_active: false, has_labor: true });
  past.people.push(paged([])); await past.page.openPeople();
  assert.deepEqual(plain(past.page.data.shareRows).map(person => person.employee_legacy_id), [44, 61]);
});

test('unknown packing share result freezes edits and sends only recovery of the original action', async () => {
  const f = pageFixture(); f.people.push(paged([{ legacy_id: 101, nickname: '协同人' }])); await f.page.openPeople(); f.page.togglePerson(event(101));
  f.service.action = (_id, payload) => { f.actions.push(plain(payload)); return Promise.reject(Object.assign(new Error('lost response'), { pendingCommand: true })); };
  await f.page.savePeople(); const before = plain(f.page.data.shareRows);
  f.page.peopleShareInput({ currentTarget: { dataset: { id: 44 } }, detail: { value: '70' } }); f.page.togglePerson(event(101));
  assert.deepEqual(plain(f.page.data.shareRows), before);
  await f.page.savePeople(); assert.equal(f.actions[1].action, 'recover');
});

test('actual material dimensions and Chinese remarks survive the selected source payload without changing quantity', async () => {
  const f = pageFixture(); f.materials.push(paged([{ id: 21, item_name: '木条', physical: false, serial_tracking_mode: 'none', quantity_available: '10' }]));
  await f.page.openMaterials(); f.page.chooseMaterial(event(21));
  f.page.quantityInput({ currentTarget: { dataset: { id: 21 } }, detail: { value: '3' } });
  f.page.usageDimensionInput({ currentTarget: { dataset: { id: 21, axis: 'length_mm' } }, detail: { value: '1200.25' } });
  f.page.usageRemarkInput({ currentTarget: { dataset: { id: 21 } }, detail: { value: '木箱底托实际用料' } });
  await f.page.saveMaterials();
  assert.equal(f.actions[0].materials[0].base_qty, '3');
  assert.deepEqual(f.actions[0].materials[0].usage_dimensions, { length_mm: '1200.25' });
  assert.equal(f.actions[0].materials[0].usage_remark, '木箱底托实际用料');
});

test('material selection keeps actual source and quantity across pages without inserting default plate dimensions', async () => {
  const f = pageFixture();
  f.materials.push(paged([{ id: 21, item_name: '木板', physical: true, serial_tracking_mode: 'none', quantity_available: 2 }]));
  await f.page.openMaterials(); f.page.chooseMaterial(event(21));
  f.page.materialSelected[21].identities = [{ id: 77, physical_no: 'BOARD-77', thickness_mm: '3.5' }];
  f.page.materialSelected[21].base_qty = '1';
  f.materials.push(paged([{ id: 22, item_name: '钉子', physical: false, serial_tracking_mode: 'none', quantity_available: 20 }]));
  await f.page.loadMaterials(); f.page.chooseMaterial(event(22));
  f.page.quantityInput({ currentTarget: { dataset: { id: 22 } }, detail: { value: '2.5' } });
  await f.page.saveMaterials();
  assert.deepEqual(f.actions[0].materials, [
    { inventory_balance_id: 21, base_qty: '1', physical_material_ids: [77], inventory_serial_ids: [] },
    { inventory_balance_id: 22, base_qty: '2.5', physical_material_ids: [], inventory_serial_ids: [] },
  ]);
  assert.equal(JSON.stringify(f.actions[0]).includes('thickness'), false);
});

test('optional material quantity accepts no identities and keeps actual quantity when identities are selected', async () => {
  const f = pageFixture();
  f.materials.push(paged([{ id: 22, item_name: '可选编号包装材料', physical: false, serial_tracking_mode: 'optional', quantity_available: 20 }]));
  await f.page.openMaterials(); f.page.chooseMaterial(event(22));
  f.page.quantityInput({ currentTarget: { dataset: { id: 22 } }, detail: { value: '4' } });
  f.identities.push(paged([{ id: 82, serial_no: 'MATERIAL-82' }])); await f.page.openIdentities(event(22));
  f.page.confirmIdentities(); await f.page.saveMaterials();
  assert.deepEqual(f.actions[0].materials, [{ inventory_balance_id: 22, base_qty: '4', physical_material_ids: [], inventory_serial_ids: [] }]);

  f.identities.push(paged([{ id: 82, serial_no: 'MATERIAL-82' }])); await f.page.openIdentities(event(22));
  f.page.toggleIdentity(event(82)); f.page.confirmIdentities(); await f.page.saveMaterials();
  assert.deepEqual(f.actions[1].materials, [{ inventory_balance_id: 22, base_qty: '4', physical_material_ids: [], inventory_serial_ids: [82] }]);
});

test('required serial and physical materials continue to derive actual quantity from selected identities', () => {
  const f = pageFixture();
  for (const material of [{ id: 21, physical: true, serial_tracking_mode: 'none' }, { id: 22, physical: false, serial_tracking_mode: 'required' }]) {
    f.page.materialSelected[material.id] = Object.assign({}, material, { base_qty: '9', identities: [] });
    f.page.setData({ identityMaterial: material }); f.page.identitySelected = { 82: { id: 82 } };
    f.page.confirmIdentities();
    assert.equal(f.page.materialSelected[material.id].base_qty, '1');
  }
});

test('worker packing markup and service contain no performance amount or rate fields', () => {
  const markup = fs.readFileSync(path.join(__dirname, '../pages/production/shipment-packing/index.wxml'), 'utf8');
  const script = fs.readFileSync(path.join(__dirname, '../pages/production/shipment-packing/index.js'), 'utf8');
  assert.equal(/performance_rate|unit_price|绩效金额|计提比例/.test(markup + script), false);
});

test('a closed and reopened collaborator picker ignores the previous delayed response', async () => {
  const f = pageFixture(); let finishOld;
  f.people.push(new Promise(resolve => { finishOld = resolve; }));
  const old = f.page.openPeople(); f.page.closePeople();
  f.people.push(paged([{ legacy_id: 102, nickname: '当前查询' }])); await f.page.openPeople();
  finishOld(paged([{ legacy_id: 101, nickname: '旧查询' }])); await old;
  assert.equal(f.page.data.peopleRows[0].legacy_id, 102);
});

test('physical identity searches remain bounded, retain selection and ignore a closed source', async () => {
  const f = pageFixture(); let finishOld;
  f.page.materialSelected[21] = { id: 21, identities: [] };
  f.identities.push(paged([{ id: 71, physical_no: 'BOARD-71' }])); await f.page.openIdentities(event(21)); f.page.toggleIdentity(event(71));
  f.page.identityInput({ detail: { value: 'BOARD-82' } }); f.page.setData({ identityPage: 2 });
  f.identities.push(paged([{ id: 82, physical_no: 'BOARD-82' }])); await f.page.loadIdentities();
  assert.equal(f.calls.at(-1).keyword, 'BOARD-82'); assert.equal(f.calls.at(-1).per_page, 10); assert.equal(f.calls.at(-1).page, 2);
  assert.equal(f.page.data.selectedIdentities[0].id, 71);
  f.identities.push(new Promise(resolve => { finishOld = resolve; })); const old = f.page.loadIdentities(); f.page.closeIdentities();
  finishOld(paged([{ id: 99, physical_no: 'CLOSED' }])); await old;
  assert.equal(f.page.data.identityRows[0].id, 82);
});

test('completing selected shipment serials sends their exact count with the operation version', async () => {
  const f = pageFixture({ showModal: options => options.success({ confirm: true }) });
  f.page.data.operation.serial_results = [
    { inventory_serial_id: 81, allowed_actions: { complete: false } },
    { inventory_serial_id: 82, allowed_actions: { complete: true } },
    { inventory_serial_id: 83, allowed_actions: { complete: true } },
  ];
  f.page.data.operationSerialIds = [82, 83];
  f.page.complete();
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(f.actions[0], { action: 'complete', expected_version: 3, inventory_serial_ids: [82, 83], completed_base_qty: 2 });
});

test('shipment quality submits only selected eligible serials and keeps the current operation version', async () => {
  const f = pageFixture();
  f.page.data.operation.serial_results = [
    { inventory_serial_id: 81, allowed_actions: { inspect: true } },
    { inventory_serial_id: 82, allowed_actions: { inspect: true } },
  ];
  f.page.data.operationSerialIds = [82];
  f.page.data.inspectionResult = 'passed';
  await f.page.saveInspection();
  assert.deepEqual(f.actions[0], { action: 'inspect', expected_version: 3, result: 'passed', reason: '', inventory_serial_ids: [82] });
});

test('an ineligible selected serial is rejected before creating a shipment quality command', async () => {
  const f = pageFixture();
  f.page.data.operation.serial_results = [{ inventory_serial_id: 81, allowed_actions: { inspect: false } }];
  f.page.data.operationSerialIds = [81];
  await f.page.saveInspection();
  assert.equal(f.actions.length, 0);
  assert.match(f.page.data.operationError, /满足本次操作条件/);
});

test('failed manufactured shipment quality needs an actual repair location and return operation before sending the selected serial', async () => {
  const f = pageFixture();
  f.page.data.operation.serial_results = [{ inventory_serial_id: 81, requires_return_operation: true, allowed_actions: { inspect: true } }];
  f.page.data.operationSerialIds = [81];
  f.page.data.inspectionResult = 'failed';
  f.page.data.inspectionReason = '接口漏水';
  await f.page.saveInspection();
  assert.equal(f.actions.length, 0);
  assert.match(f.page.data.inspectionError, /返修库位和返工工序/);
  f.page.data.repairLocator = { location_id: 31 };
  f.page.data.returnOperation = { id: 52 };
  await f.page.saveInspection();
  assert.deepEqual(f.actions[0], { action: 'inspect', expected_version: 3, result: 'failed', reason: '接口漏水', inventory_serial_ids: [81], repair_location_id: 31, return_operation_id: 52 });
});

test('failed purchased serial moves to repair without inventing a manufacturing return operation', async () => {
  const f = pageFixture();
  f.page.data.operation.serial_results = [{ inventory_serial_id: 81, requires_return_operation: false, allowed_actions: { inspect: true } }];
  f.page.data.operationSerialIds = [81];
  f.page.data.inspectionResult = 'failed';
  f.page.data.inspectionReason = '接口漏水';
  f.page.data.repairLocator = { location_id: 31 };
  await f.page.saveInspection();
  assert.deepEqual(f.actions[0], { action: 'inspect', expected_version: 3, result: 'failed', reason: '接口漏水', inventory_serial_ids: [81], repair_location_id: 31 });
});
