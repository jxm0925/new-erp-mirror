const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const logic = require('../utils/warehouse-picking');
const ui = require('../utils/warehouse-page');
const flush = () => new Promise(resolve => setImmediate(resolve));
const tap = (key, extra = {}) => ({ currentTarget: { dataset: { key, ...extra } } });
const input = (id, value) => ({ currentTarget: { dataset: { id } }, detail: { value } });
const response = (data, page = 1, total = data.length, last = 1) => ({ data, current_page: page, last_page: last, total });
const source = (id, qty = '1') => ({ id, warehouse_id: 3, batch_no: `LOT-${id}`, picking_available_qty: '5', selected_qty: qty, unit: { unit_name: '张' }, location: { location_code: 'A1' } });
const line = (id, mode = 'quantity') => ({ id, component_item: { item_code: `ITEM${id}`, item_name: `物料${id}`, material_management_mode: mode, serial_tracking_mode: mode === 'serial' ? 'required' : 'none' }, planned_pick_qty: '2', unit_name_snapshot: '张', inventory_balance: { batch_no: `LOT-${id}` } });
function mountPicker(service, props = {}) {
  let definition;
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../components/warehouse-picker/index.js'), 'utf8'), {
    Component: d => { definition = d; }, require: name => name.includes('services/') ? service : name.includes('warehouse-picking') ? logic : ui,
  });
  const instance = { data: structuredClone(definition.data), properties: { open: true, mode: 'physical', query: { picking_task_id: 7, picking_task_line_id: 8 }, selected: [], multiple: true, maxCount: 0, quantity: false, ...props }, setData(value) { Object.assign(this.data, value); }, triggerEvent(name, detail) { this.emitted = { name, detail }; } };
  Object.assign(instance, definition.methods); return instance;
}
function mountPage(service, storage = new Map()) {
  let definition;
  const wx = { getStorageSync: key => storage.get(key), setStorageSync: (key, value) => storage.set(key, structuredClone(value)), removeStorageSync: key => storage.delete(key), setNavigationBarTitle() {}, stopPullDownRefresh() {}, navigateTo(value) { this.navigated = value.url; } };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../pages/warehouse/picking/index.js'), 'utf8'), {
    Page: d => { definition = d; }, wx, require: name => name.includes('services/') ? service : name.includes('warehouse-picking') ? logic : name.includes('config/') ? { getErpApiBaseUrl: () => 'https://example.test/erp' } : { ...ui, permissions: () => () => true },
  });
  const instance = { ...definition, data: structuredClone(definition.data), setData(value) { Object.assign(this.data, value); } };
  instance.drafts = {}; instance.pickDrafts = {}; instance.lineCache = {}; instance.wx = wx;
  return instance;
}
test('stock selection preserves quantity across server pages, searches and category changes', async () => {
  const calls = [];
  const picker = mountPicker({ get: async (route, q) => { calls.push([route, q]); return q.kind === 'locations' ? response([{ id: 4, location_code: 'A1' }]) : response([source(q.page)], q.page, 20, 2); } }, { mode: 'stock', quantity: true, query: { target_material_requirement_id: 42, warehouse_id: 3 } });
  picker.initialize(); await flush(); picker.toggle(tap('1')); picker.quantityInput({ currentTarget: { dataset: { key: '1' } }, detail: { value: '2.5' } });
  await picker.turnPage(tap('', { delta: 1 })); picker.toggle(tap('2'));
  picker.setData({ keyword: '板材' }); await picker.search(); await picker.chooseCategory(tap('', { id: 4 }));
  assert.equal(picker.data.selectedCount, 2); assert.equal(picker.data.selectedRows.find(r => r.id === 1).selected_qty, '2.5');
  assert.ok(calls.every(([, q]) => q.per_page === 10)); assert.equal(calls.at(-1)[1].location_id, 4); assert.equal(calls.at(-1)[1].keyword, '板材');
  picker.toggleSelected(); picker.remove(tap('2')); picker.confirm(); assert.equal(picker.emitted.detail.rows.length, 1); assert.equal(picker.emitted.detail.rows[0].selected_qty, '2.5');
});
test('picker rejects quantities above availability and duplicate toggle does not come from checkbox parent', async () => {
  const picker = mountPicker({ get: async () => response([source(1)]) }, { mode: 'stock', quantity: true });
  picker.initialize(); await flush(); picker.toggle(tap('1')); picker.quantityInput({ currentTarget: { dataset: { key: '1' } }, detail: { value: '9' } }); picker.confirm();
  assert.match(picker.data.error, /可用数量/); assert.equal(picker.emitted, undefined);
  const markup = fs.readFileSync(path.join(__dirname, '../components/warehouse-picker/index.wxml'), 'utf8');
  assert.match(markup, /class="picker-row">/); assert.ok(!/class="picker-row"[^>]*bindtap/.test(markup));
});
test('picker rejects late search and closed modal results', async () => {
  const waits = []; const picker = mountPicker({ get: () => new Promise(resolve => waits.push(resolve)) });
  picker.initialize(); picker.setData({ keyword: 'new' }); picker.search();
  waits[1](response([{ id: 2, physical_no: 'NEW' }])); await flush(); waits[0](response([{ id: 1, physical_no: 'OLD' }])); await flush();
  assert.equal(picker.data.rows[0].id, 2);
  picker.search(); picker.cancel(); picker.properties.open = false; waits[2](response([{ id: 3 }])); await flush(); assert.equal(picker.data.rows.length, 0);
});
test('single person replacement preserves real legacy id and cancel leaves parent selection untouched', async () => {
  const original = [{ id: 7, nickname: '原拣货员' }];
  const picker = mountPicker({ get: async (_, q) => response(q.kind === 'departments' ? [] : [{ id: 8, nickname: '新拣货员', username: 'WH8' }]) }, { mode: 'people', selected: original, multiple: false });
  picker.initialize(); await flush(); picker.toggle(tap('8')); assert.equal(picker.data.selectedCount, 1); picker.cancel(); assert.equal(original[0].id, 7);
  picker.initialize(); await flush(); picker.toggle(tap('8')); picker.confirm(); assert.equal(picker.emitted.detail.rows[0].id, 8);
});
test('target key distinguishes multiple operations within the same work order', () => {
  const target = { work_order_id: 1, production_target_type: 'unit_operation', production_target_id: 2, target_routing_operation_id: 3 };
  assert.notEqual(logic.keyOf(target, 'target'), logic.keyOf({ ...target, production_target_id: 4 }, 'target'));
});
test('physical selector count limit never auto-selects unseen pieces', async () => {
  const picker = mountPicker({ get: async () => response([{ id: 1, physical_no: 'P1' }, { id: 2, physical_no: 'P2' }]) }, { maxCount: 1 });
  picker.initialize(); await flush(); assert.equal(picker.data.selectedCount, 0); picker.toggle(tap('1')); picker.toggle(tap('2'));
  assert.equal(picker.data.selectedCount, 1); assert.match(picker.data.error, /最多选择1项/);
});
test('create payload preserves each demand and each source without mixing units or warehouses', () => {
  const target = { work_order_id: 99, work_order_version: 7 };
  const drafts = { 42: { demand: { id: 42, item_name: '钢板', remaining_to_prepare: 2 }, sources: [source(1), source(2)] }, 43: { demand: { id: 43, item_name: '螺栓', remaining_to_prepare: 3 }, sources: [source(3, '3')] } };
  const payload = logic.createPayload(target, 3, drafts);
  assert.equal(payload.lines.length, 3); assert.equal(payload.expected_version, 7); assert.equal(payload.lines[2].planned_pick_qty, '3');
  assert.throws(() => logic.createPayload(target, 4, drafts), /仓库不一致/);
  drafts[42].sources[0].selected_qty = '2'; assert.throws(() => logic.createPayload(target, 3, drafts), /超过待配数量/);
});
test('same inventory source aggregate cannot exceed availability across demands', () => {
  const drafts = { 1: { demand: { id: 1, remaining_to_prepare: 5 }, sources: [source(7, '3')] }, 2: { demand: { id: 2, remaining_to_prepare: 5 }, sources: [source(7, '3')] } };
  assert.throws(() => logic.createPayload({ work_order_id: 1, work_order_version: 1 }, 3, drafts), /累计配料超过/);
});
test('confirm requires every page and explicit zero instead of defaulting untouched rows', () => {
  const drafts = { 1: { line: line(1), actual_pick_qty: '1', physicalRows: [], serialRows: [] } };
  assert.throws(() => logic.confirmPayload({ business_version: 2 }, drafts, 2), /逐页核对/);
  drafts[2] = { line: line(2), actual_pick_qty: '', physicalRows: [], serialRows: [] };
  assert.throws(() => logic.confirmPayload({ business_version: 2 }, drafts, 2), /未拣出的填写0/);
  drafts[2].actual_pick_qty = '0'; assert.equal(logic.confirmPayload({ business_version: 2 }, drafts, 2).lines[1].actual_pick_qty, '0');
});
test('confirm keeps physical and ordinary serial namespaces separate and rejects duplicate identities', () => {
  const drafts = { 1: { line: line(1, 'physical'), actual_pick_qty: '1', physicalRows: [{ id: 51 }], serialRows: [] }, 2: { line: line(2, 'serial'), actual_pick_qty: '1', physicalRows: [], serialRows: [{ id: 51 }] } };
  const payload = logic.confirmPayload({ business_version: 3 }, drafts, 2);
  assert.deepEqual(payload.lines[0].physical_material_ids, [51]); assert.equal(payload.lines[0].serial_ids, undefined); assert.deepEqual(payload.lines[1].serial_ids, [51]);
  drafts[3] = { ...drafts[1], line: line(3, 'physical') }; assert.throws(() => logic.confirmPayload({ business_version: 3 }, drafts, 3), /重复选择/);
});
test('confirm rejects identity count mismatch, fractional pieces, excess and all zero', () => {
  const draft = { line: line(1, 'physical'), actual_pick_qty: '2', physicalRows: [{ id: 1 }], serialRows: [] };
  assert.throws(() => logic.confirmPayload({ business_version: 1 }, { 1: draft }, 1), /实物数/);
  draft.actual_pick_qty = '1.5'; assert.throws(() => logic.confirmPayload({ business_version: 1 }, { 1: draft }, 1), /实物数/);
  draft.actual_pick_qty = '3'; assert.throws(() => logic.confirmPayload({ business_version: 1 }, { 1: draft }, 1), /不能超过/);
  draft.actual_pick_qty = '0'; draft.physicalRows = []; assert.throws(() => logic.confirmPayload({ business_version: 1 }, { 1: draft }, 1), /至少填写/);
});
test('page retains picks across pages but invalidates drafts when business version changes', async () => {
  let version = 1;
  const page = mountPage({ pending: () => null, get: async (_, q) => ({ data: { id: 7, business_version: version, status: 'PICKING', allowed_actions: ['picking.confirm'], lines: [line(q.line_page)], line_meta: { current_page: q.line_page, last_page: 2, total: 2 } } }) });
  page.setData({ id: 7 }); await page.load(); page.pickInput(input(1, '1')); await page.turnPage(tap('', { delta: 1 })); page.pickInput(input(2, '0'));
  assert.equal(page.data.checkedCount, 2); assert.equal(Object.keys(page.pickDrafts).length, 2);
  version = 2; await page.load(); assert.equal(page.data.checkedCount, 0); assert.match(page.data.error, /重新核对/);
});
test('zero quantity explicitly releases local identities and unauthorized methods do not submit', () => {
  let submits = 0; const page = mountPage({ submit: () => { submits++; } });
  page.lineCache[1] = line(1, 'physical'); page.setData({ canConfirm: true, rows: [line(1, 'physical')] });
  page.pickDrafts[1] = { line: line(1, 'physical'), actual_pick_qty: '1', physicalRows: [{ id: 5 }], serialRows: [] };
  page.pickInput(input(1, '0')); assert.equal(page.pickDrafts[1].physicalRows.length, 0);
  page.setData({ canConfirm: false }); page.confirm(); page.start(); page.cancel(); assert.equal(submits, 0);
});
test('unknown result locks editing and retry enters shared command recovery with original action and id', async () => {
  const calls = []; let pending = null;
  const page = mountPage({ pending: () => pending, submit: async (action, id, payload) => { calls.push({ action, id, payload }); pending = { payload }; throw Object.assign(new Error('网络中断'), { pendingCommand: true }); } });
  page.setData({ id: 8, task: { business_version: 4 }, canStart: true });
  await page.start(); assert.equal(page.data.pendingAction, 'picking.start'); assert.equal(page.data.resultOpen, true);
  page.chooseWarehouse(); assert.equal(page.data.pickerOpen, false); await page.retry();
  assert.equal(calls.length, 2); assert.equal(calls[1].action, 'picking.start'); assert.equal(calls[1].id, 8);
});
test('create context survives reload so lost response can be recovered after demands disappear', async () => {
  const storage = new Map([['erp_user', { legacy_id: 12 }]]);
  const service = { pending: action => action === 'picking.create' ? { payload: {} } : null, get: async () => response([]) };
  const first = mountPage(service, storage); first.setData({ mode: 'create', canCreate: true, target: { work_order_id: 99, work_order_version: 3 }, warehouse: { id: 3 } }); first.saveDraft();
  const reopened = mountPage(service, storage); reopened.onLoad({ mode: 'create' }); await flush();
  assert.equal(reopened.data.target.work_order_id, 99); assert.equal(reopened.data.pendingAction, 'picking.create'); assert.equal(reopened.data.rows.length, 0);
  storage.set('erp_user', { legacy_id: 13 }); const other = mountPage(service, storage); other.onLoad({ mode: 'create' }); assert.equal(other.data.target, null);
});
test('changing warehouse clears all paged source drafts; unchanged warehouse preserves them', () => {
  const page = mountPage({}); page.setData({ mode: 'create', warehouse: { id: 3 }, pickerMode: 'warehouse', rows: [] });
  page.drafts = { 42: { demand: { id: 42 }, sources: [source(1)] } };
  page.pickerConfirm({ detail: { rows: [{ id: 3 }] } }); assert.equal(Object.keys(page.drafts).length, 1);
  page.pickerConfirm({ detail: { rows: [{ id: 4 }] } }); assert.equal(Object.keys(page.drafts).length, 0);
});
