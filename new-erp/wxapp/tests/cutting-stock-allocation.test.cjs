const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const logic = require('../utils/warehouse-picking');
const target = { work_order_id: 1, work_order_version: 2 };
const source = (id, extras = {}) => ({ id, warehouse_id: 3, selected_qty: '1', picking_available_qty: '10', cutting_unit_kind: 'root', cutting_capacity_piece_qty: 10, remaining_cut_piece_qty: 4, ...extras });
const draft = sources => ({ 1: { demand: { id: 1, item_name: '方管原料', remaining_to_prepare: '0.40000000', cutting_unit_kind: 'root', remaining_cut_piece_qty: 4 }, sources } });

test('fractional theoretical need can use one whole root without changing inventory quantity', () => {
  const result = logic.createPayload(target, 3, draft([source(11)]));
  assert.equal(result.lines[0].planned_pick_qty, '1'); assert.equal(result.lines[0].planned_cut_piece_qty, 4);
});
test('multiple batches require an explicit piece allocation and preserve actual quantities', () => {
  assert.throws(() => logic.createPayload(target, 3, draft([source(11), source(12)])), /逐批填写/);
  const result = logic.createPayload(target, 3, draft([source(11, { planned_cut_piece_qty: '3' }), source(12, { planned_cut_piece_qty: '1' })]));
  assert.deepEqual(result.lines.map(row => [row.planned_pick_qty, row.planned_cut_piece_qty]), [['1', 3], ['1', 1]]);
});
test('actual stock, capacity and frozen remaining pieces each bound the allocation', () => {
  assert.throws(() => logic.createPayload(target, 3, draft([source(11, { selected_qty: '0.4' })])), /整数/);
  assert.throws(() => logic.createPayload(target, 3, draft([source(11, { cutting_capacity_piece_qty: 2 })])), /不足以覆盖/);
  assert.throws(() => logic.createPayload(target, 3, draft([source(11, { planned_cut_piece_qty: '5' })])), /超过剩余/);
  assert.throws(() => logic.createPayload(target, 3, draft([source(11, { selected_qty: '11' })])), /可用数量/);
});
test('frozen planned physical identities are preserved and picker shows per-piece coverage', () => {
  const result = logic.createPayload(target, 3, draft([source(11, { cutting_unit_kind: 'sheet', physical_material_ids: [50] })]));
  assert.deepEqual(result.lines[0].physical_material_ids, [50]);
  const view = logic.optionView({ id: 50, physical_no: '原料板50', dimensions: { length_mm: 1000, width_mm: 2000 }, covered_piece_qty: 3 }, 'physical');
  assert.match(view.subtitle, /本张覆盖3件/);
});
test('root actual picking rejects fractional quantities even without serial tracking', () => {
  const line = { id: 1, planned_pick_qty: '1', component_item: {}, serial_snapshot: { cutting_allocation: { unit_kind: 'root' } } };
  assert.throws(() => logic.confirmPayload({ business_version: 2 }, { 1: { line, actual_pick_qty: '0.4', physicalRows: [], serialRows: [] } }, 1), /必须为整数/);
});

function mountOnsite() {
  let definition; let pending = []; const calls = []; const rows = [{ id: 10, task_id: 7, task_version: 2, remaining_qty: '1', remaining_cut_piece_qty: 4, cutting_unit_kind: 'root', serial_control_type: 'none' }];
  const modules = { production: { onsiteCollections: async () => ({ data: rows, last_page: 2, total: 2 }), receiveOnsite: async (_, body) => { calls.push(structuredClone(body)); pending = [{ path: 'production/material-picking-tasks/7/onsite-receive' }]; throw new Error('offline'); } }, controller: {}, 'warehouse-page': require('../utils/warehouse-page'), 'material-preparation': { pendingList: () => pending } };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../pages/production/material-actions/index.js'), 'utf8'), { Page: value => { definition = value; }, wx: { showToast() {} }, require: name => Object.entries(modules).find(([key]) => name.endsWith('/' + key))[1] });
  const page = { ...definition, data: { ...structuredClone(definition.data), canReceiveOnsite: true }, setData(patch) { for (const [key, value] of Object.entries(patch)) { const match = key.match(/^onsiteRows\[(\d+)\]\.(\w+)$/); if (match) this.data.onsiteRows[Number(match[1])][match[2]] = value; else this.data[key] = value; } } };
  return { page, calls, rows };
}
const input = value => ({ currentTarget: { dataset: { id: 10 } }, detail: { value } });
const tap = () => ({ currentTarget: { dataset: { id: 10 } } });
test('onsite drafts survive paging and integer receipt sends no client coverage', async () => {
  const { page, calls } = mountOnsite(); await page.loadOnsite(); page.onsiteQuantity(input('1')); await page.loadOnsite();
  assert.equal(page.data.onsiteRows[0].accepted, '1'); await page.receiveOnsite(tap());
  assert.equal(calls[0].lines[0].accepted_qty, '1'); assert.equal(calls[0].lines[0].covered_piece_qty, undefined);
  assert.equal(page.data.onsiteRows[0].pending, true); page.onsiteQuantity(input('99')); assert.equal(page.data.onsiteRows[0].accepted, '1');
});
test('onsite whole root rejects fractions and changed task version clears draft identities', async () => {
  const { page, calls, rows } = mountOnsite(); await page.loadOnsite(); page.onsiteQuantity(input('0.4')); await page.receiveOnsite(tap()); assert.equal(calls.length, 0);
  rows[0].task_version = 3; await page.loadOnsite(); assert.equal(page.data.onsiteRows[0].accepted, '');
});
