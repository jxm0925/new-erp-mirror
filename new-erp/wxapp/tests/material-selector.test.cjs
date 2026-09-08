const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mount(request) {
  const exported = { exports: {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../components/material-selector/controller.js'), 'utf8'), {
    module: exported, require: () => ({ materialOptions: request }), Map, Set,
  });
  const definition = exported.exports.definition;
  const instance = { data: JSON.parse(JSON.stringify(definition.data)), properties: { mode: 'supplement', taskId: 1, targetType: 'quantity_operation', targetId: 1 },
    setData(value) { Object.assign(this.data, value); }, triggerEvent(name, detail) { this.emitted = { name, detail }; } };
  Object.assign(instance, definition.methods);
  return instance;
}
const flush = () => new Promise(resolve => setImmediate(resolve));
const row = n => ({ key: `s:${n}`, component_item_id: n, code: `RM-${n}`, name: `物料${n}` });
const tap = key => ({ currentTarget: { dataset: { key } } });

test('server pages and category search retain independent multi-selection', async () => {
  const calls = [];
  const picker = mount(async (...args) => { const q = args[3]; calls.push(q); return { data: [row(q.page)], total: 21, current_page: q.page, last_page: 2, categories: [] }; });
  picker.open([]); await flush(); picker.toggleRow(tap('s:1')); picker.next(); await flush(); picker.toggleRow(tap('s:2'));
  assert.equal(picker.data.selectedCount, 2); assert.equal(calls[1].page, 2); assert.equal(calls[1].per_page, 20);
  picker.setData({ keyword: '规格' }); picker.search(); await flush();
  assert.equal(calls[2].keyword, '规格'); assert.equal(picker.data.rows[0].checked, true);
  picker.chooseCategory({ currentTarget: { dataset: { id: 3 } } }); await flush();
  assert.equal(calls[3].category_id, 3); assert.equal(picker.data.selectedCount, 2);
  const candidateKey = picker.data.rows[0].key;
  picker.toggleSelected();
  assert.deepEqual(Array.from(picker.data.selectedRows, item => item.key), ['s:1', 's:2']);
  assert.equal(picker.data.rows[0].key, candidateKey, '展开已选明细不能替换当前候选列表');
  picker.removeSelected(tap('s:1')); assert.equal(picker.data.selectedCount, 1);
  picker.confirm(); assert.equal(picker.emitted.detail.rows[0].key, 's:2'); assert.equal(picker.data.visible, false);
});

test('late searches and closed-modal replies cannot replace current results', async () => {
  const pending = [];
  const picker = mount(() => new Promise(resolve => pending.push(resolve)));
  picker.open([]); picker.setData({ keyword: 'new' }); picker.search();
  pending[1]({ data: [row(2)], current_page: 1, last_page: 1, total: 1 }); await flush();
  pending[0]({ data: [row(1)], current_page: 1, last_page: 1, total: 1 }); await flush();
  assert.equal(picker.data.rows[0].key, 's:2');
  picker.search(); picker.close(); pending[2]({ data: [row(3)], current_page: 1, last_page: 1, total: 1 }); await flush();
  assert.equal(picker.data.visible, false); assert.equal(picker.data.rows[0], undefined);
});

test('cancel does not mutate parent selection and errors do not look like empty success', async () => {
  const original = [row(4)];
  const picker = mount(async () => { throw Error('权限不足'); });
  picker.open(original); await flush(); assert.equal(picker.data.error, '权限不足');
  picker.toggleSelected(); picker.removeSelected(tap('s:4')); picker.close();
  assert.equal(original.length, 1); assert.equal(picker.emitted, undefined);
  picker.open(original); assert.equal(picker.data.selectedCount, 1);
});

test('batch confirmation keeps quantities and distinct receipt batches in the original form', () => {
  let page;
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../pages/production/material-actions/index.js'), 'utf8'), {
    Page: value => { page = value; }, require: () => ({}), Map, Set,
  });
  page.setData = function (value) { Object.assign(this.data, value); };
  page.data.supplementLines = [{ selection: row(1), component_item_id: 1, additional_base_qty: '2.5' }];
  page.onMaterialsSelected({ detail: { mode: 'supplement', rows: [row(1), row(2)] } });
  assert.equal(page.data.supplementLines.length, 2); assert.equal(page.data.supplementLines[0].additional_base_qty, '2.5');
  const source = { ...row(1), key: 'r:1:1:1:batchA', material_requirement_id: 1, warehouse_id: 1, location_id: 1,
    warehouse_name: '一号仓库', location_name: 'A1', batch_no: 'A', received_base_qty: '5', returnable_base_qty: '3' };
  page.onMaterialsSelected({ detail: { mode: 'return', rows: [source, { ...source, key: 'r:1:1:1:batchB', batch_no: 'B' }] } });
  assert.equal(page.data.returnLines.length, 2); assert.equal(page.data.returnLines[0].warehouse_id, 1);
  assert.equal(page.data.returnLines[1].batch_no, 'B'); assert.equal(page.data.returnLines[0].returnable_base_qty, 3);
  page.onMaterialsSelected({ detail: { mode: 'return', rows: [] } });
  assert.equal(page.data.returnLines.length, 1); assert.equal(page.data.returnLines[0].material_requirement_id, 0);
});
