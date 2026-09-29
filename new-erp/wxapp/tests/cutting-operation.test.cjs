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
