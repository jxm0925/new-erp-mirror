const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mount(service = {}) {
  let page; let now = 1000000;
  class Clock extends Date { static now() { return now; } }
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../pages/production/cutting-operation/index.js'), 'utf8'), {
    require: () => service, Page: value => { page = value; }, Date: Clock,
    wx: { showToast: value => { page.toast = value.title; }, navigateTo() {} },
    setInterval() { return 1; }, clearInterval() {}, console,
  });
  page.setData = values => Object.assign(page.data, values);
  page.advance = seconds => { now += seconds * 1000; };
  return page;
}

test('cutting timer adds only post-response time to server accumulation and pauses without duplication', () => {
  const page = mount();
  page.data.detail.server_now = '2026-09-24T00:10:00Z';
  page.data.target.my_labor = { status: 'ACTIVE', accumulated_seconds: 900, started_at: '2026-09-24T00:00:00Z' };
  page.updateTimer(); assert.equal(page.data.timer, '00:15:00');
  page.advance(30); page.updateTimer(); assert.equal(page.data.timer, '00:15:30');
  page.data.detail.server_now = '2026-09-24T00:10:30Z';
  page.data.target.my_labor = { status: 'PAUSED', accumulated_seconds: 930 };
  page.updateTimer(); page.advance(20); page.updateTimer(); assert.equal(page.data.timer, '00:15:30');
});

test('returning from drawings preserves unsaved cutting quantities and remnants', () => {
  const page = mount(); let loads = 0; page.load = () => { loads++; };
  page.ready = true; page.data.dirty = true; page.data.actualQty = '7'; page.onShow();
  assert.equal(loads, 0); assert.equal(page.data.actualQty, '7');
  page.data.dirty = false; page.onShow(); assert.equal(loads, 1);
});

test('editing reviewed remnants requires a new save before completing the operation', async () => {
  const page = mount(); let saves = 0; let completions = 0;
  page.data.step = 3; page.data.otherType = 'usable_remnant';
  page.data.otherRows = [{ result_type: 'usable_remnant', actual_qty: 1, measurements: { length_mm: 500, width_mm: 200 } }];
  page.confirmOthers(); assert.equal(page.data.step, 2); assert.equal(page.data.dirty, true);
  page.saveResult = () => { saves++; }; page.run = () => { completions++; };
  await page.finish(); assert.equal(saves, 1); assert.equal(completions, 0);
});

test('adding another material is blocked when saving the current results fails', async () => {
  const page = mount(); let selections = 0;
  page.saveResult = async () => false; page.openMaterials = () => { selections++; };
  await page.saveAndAdd(); assert.equal(selections, 0);
  page.saveResult = async () => true; await page.saveAndAdd(); assert.equal(selections, 1);
});

test('material selector ignores stale responses and keeps selected material across pages', async () => {
  const pending = []; const page = mount({ materials: () => new Promise(resolve => pending.push(resolve)) });
  page.data.selected = { key: '7:8' };
  const first = page.loadMaterials(); page.data.keyword = 'new'; const latest = page.loadMaterials();
  pending[1]({ data: [{ production_input_holding_id: 2, item_name: 'new', available_qty: '2.00000000' }], meta: { last_page: 2 } }); await latest;
  pending[0]({ data: [{ production_input_holding_id: 1, item_name: 'old' }], meta: { last_page: 1 } }); await first;
  assert.equal(page.data.candidates[0].item_name, 'new'); assert.equal(page.data.candidates[0].availableLabel, '2');
  assert.equal(page.data.selected.key, '7:8');
  const closing = page.loadMaterials(); page.closeMaterials(); pending[2]({ data: [], meta: { last_page: 1 } }); await closing;
  assert.equal(page.data.candidates[0].item_name, 'new');
});

test('additional category pages append while reopening starts with a clean category list', async () => {
  let id = 1; const page = mount({ materials: async () => ({ data: [{ id: id++ }], meta: { last_page: 2 } }) });
  await page.loadCategories(); await page.categoryMore(); assert.equal(page.data.categories.length, 2);
  page.data.categoryPage = 1; await page.loadCategories(); assert.equal(page.data.categories.length, 1);
});

test('pausing work preserves current unsaved results while refreshing the labor clock', async () => {
  const page = mount({ pause: async () => ({}), show: async () => ({ data: {
    target: { status: 'PAUSED', my_labor: { status: 'PAUSED', accumulated_seconds: 120 } },
    server_now: '2026-09-24T12:00:00Z', current_batch: { id: 9, input_qty: '1.00000000' }, results: [],
  } }) });
  page.data.actualQty = '7'; page.data.otherResults = [{ result_type: 'process_loss', actual_qty: '2' }];
  page.data.dirty = true; page.data.step = 2; page.data.target.my_labor = { status: 'ACTIVE' };
  await page.toggleTimer(); assert.equal(page.data.actualQty, '7'); assert.equal(page.data.otherResults.length, 1);
  assert.equal(page.data.dirty, true); assert.equal(page.data.timer, '00:02:00'); assert.equal(page.data.source.quantityLabel, '1');
});

function componentDetail() {
  const rows = [
    { target_material_requirement_id: 11, bom_item_id: 1, raw_item_id: 4, raw_item_name: '方管原料', raw_item_code: 'RAW4', dimensions: { length_mm: '600' }, required_piece_qty: 8, confirmed_piece_qty: 0, remaining_piece_qty: 8, covered_piece_qty: 4 },
    { target_material_requirement_id: 12, bom_item_id: 2, raw_item_id: 4, raw_item_name: '方管原料', raw_item_code: 'RAW4', dimensions: { length_mm: '1200' }, required_piece_qty: 6, confirmed_piece_qty: 0, remaining_piece_qty: 6, covered_piece_qty: 2 },
  ];
  return { output_kind: 'cutting_components', component_requirements: rows, target: { status: 'IN_PROGRESS', planned_base_qty: '99', business_version: 3 }, current_batch: { id: 9, input_qty: '1', business_version: 1, component_requirements: rows }, results: [] };
}
test('component mode keeps same raw Item at distinct frozen dimensions and never defaults pieces from sets', async () => {
  const page = mount({ show: async () => ({ data: componentDetail() }) }); await page.load();
  assert.equal(page.data.componentMode, true); assert.equal(page.data.components.length, 2);
  assert.equal(page.data.components[0].sizeLabel, '600 mm'); assert.equal(page.data.components[1].sizeLabel, '1200 mm');
  assert.equal(page.data.components[0].actualInput, ''); assert.equal(page.data.components[1].actualInput, '');
  assert.equal(page.data.components[0].raw_item_id, page.data.components[1].raw_item_id);
});
test('component save submits only frozen requirement IDs and actual integer piece counts', async () => {
  const calls = []; const page = mount({ show: async () => ({ data: componentDetail() }), save: async (...args) => { calls.push(args.at(-1)); return {}; } }); await page.load();
  page.componentQuantity({ currentTarget: { dataset: { id: 11 } }, detail: { value: '3' } });
  page.componentQuantity({ currentTarget: { dataset: { id: 12 } }, detail: { value: '1' } });
  await page.saveResult(); const body = calls[0];
  assert.deepEqual(JSON.parse(JSON.stringify(body.components)), [{ target_material_requirement_id: 11, actual_piece_qty: 3 }, { target_material_requirement_id: 12, actual_piece_qty: 1 }]);
  assert.equal(body.actual_qty, undefined); assert.equal(body.item_id, undefined); assert.equal(body.components[0].dimensions, undefined);
});
test('component save rejects fractions, missing inputs, excess source coverage and unknown coverage', async () => {
  let writes = 0; const page = mount({ show: async () => ({ data: componentDetail() }), save: async () => { writes++; return {}; } }); await page.load();
  for (const value of ['', '0.5', '5']) {
    page.data.components[0].actualInput = value; page.data.components[1].actualInput = '1'; await page.saveResult(); assert.equal(writes, 0); assert.match(page.toast, /整数件数/);
  }
  page.data.components[0].actualInput = '1'; page.data.components[0].maxPieceQty = NaN; await page.saveResult(); assert.equal(writes, 0);
});
test('saved component quantities are matched by requirement identity and may be reviewed without counting them twice', async () => {
  const detail = componentDetail(); detail.current_batch.component_requirements[0].confirmed_piece_qty = 3; detail.current_batch.component_requirements[0].remaining_piece_qty = 5;
  detail.results = [{ result_type: 'product', piece_qty: 3, actual_qty: '0.30000000', cutting_requirement_snapshot: JSON.stringify({ target_material_requirement_id: 11 }) }];
  const page = mount({ show: async () => ({ data: detail }) }); await page.load();
  assert.equal(page.data.components[0].actualInput, '3'); assert.equal(page.data.components[0].maxPieceQty, 4); assert.equal(page.data.components[1].actualInput, '');
});
test('explicit server batch maximum overrides the global confirmed-only remaining count', async () => {
  const detail = componentDetail(); detail.current_batch.component_requirements[0].max_piece_qty = 1;
  let writes = 0; const page = mount({ show: async () => ({ data: detail }), save: async () => { writes++; return {}; } }); await page.load();
  assert.equal(page.data.components[0].maxPieceQty, 1); page.data.components[0].actualInput = '2'; page.data.components[1].actualInput = '1';
  await page.saveResult(); assert.equal(writes, 0); assert.match(page.toast, /整数件数/);
});
test('unknown component save restores original values and can recover even after the server completed it', async () => {
  let pending = null; let recovered = 0; const detail = componentDetail();
  const page = mount({ show: async () => ({ data: detail }), pendingSaves: () => pending ? [pending] : [],
    save: async (...args) => { pending = { path: 'production/tasks/1/targets/unit_operation/2/cutting/batches/9', payload: JSON.parse(JSON.stringify(args.at(-1))) }; throw new Error('timeout'); },
    recoverSave: async () => { recovered++; pending = null; return {}; } });
  await page.load(); page.data.components.forEach(row => { row.actualInput = '1'; }); await page.saveResult(); assert.ok(page.data.pendingSave);
  detail.target.status = 'COMPLETED'; await page.load(); assert.equal(page.data.components[0].actualInput, '1');
  page.componentQuantity({ currentTarget: { dataset: { id: 11 } }, detail: { value: '99' } }); assert.equal(page.data.components[0].actualInput, '1');
  await page.recoverSave(); assert.equal(recovered, 1); assert.equal(page.data.pendingSave, null);
});
