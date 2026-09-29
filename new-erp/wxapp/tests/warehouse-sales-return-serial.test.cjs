const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const logic = require('../utils/warehouse-picking');
const ui = require('../utils/warehouse-page');
const plain = value => JSON.parse(JSON.stringify(value));
const flush = () => new Promise(resolve => setImmediate(resolve));
const event = dataset => ({ currentTarget: { dataset } });
const serial = (id, batch = 'LOT-A') => ({ id, serial_no: `SN-${id}`, batch_no: batch, shipment_no: 'SHP-41' });
const paged = (rows, page = 1, total = rows.length, last = 1) => ({ data: rows, current_page: page, total, last_page: last });
function mount(get, props = {}) {
  let definition;
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../components/warehouse-picker/index.js'), 'utf8'), {
    Component: value => { definition = value; }, require: name => name.includes('services/') ? { get } : name.includes('warehouse-picking') ? logic : ui,
  });
  return Object.assign({ data: plain(definition.data), properties: { open: true, mode: 'sales-return-serial', query: { return_id: 41, return_item_id: 101 }, selected: [], multiple: true, quantity: false, maxCount: 0, ...props },
    setData(value) { Object.assign(this.data, value); }, triggerEvent(name, detail) { this.emitted = { name, detail }; } }, definition.methods);
}
test('sales return mode uses closed endpoint and exact return scope, without production query leakage', async () => {
  const calls = [];
  const picker = mount(async (route, query) => { calls.push([route, plain(query)]); return paged([serial(1)]); }, {
    query: { return_id: 41, return_item_id: 101, picking_task_id: 777, warehouse_id: 3, url: '/all-serials', per_page: 9999 },
  });
  picker.initialize(); await flush();
  assert.deepEqual(calls, [['inventory/warehouse-workspace/sales-return-serials', { return_id: 41, return_item_id: 101, page: 1, per_page: 10, keyword: '' }]]);
  assert.equal(picker.data.rows[0].title, 'SN-1'); assert.equal(picker.data.rows[0].batch_no, 'LOT-A'); assert.equal(picker.data.rows[0].shipment_no, 'SHP-41');
  assert.equal(picker.data.selectedCount, 0, 'identities are never selected from quantity alone');
});
test('missing or invalid return scope is rejected before querying any candidates', async () => {
  let count = 0;
  for (const query of [{}, { return_id: 41 }, { return_id: 0, return_item_id: 101 }, { return_id: 41, return_item_id: 1.5 }]) {
    const picker = mount(async () => { count++; return paged([]); }, { query });
    picker.initialize(); await flush(); assert.match(picker.data.error, /销售退货单和退货明细/);
  }
  assert.equal(count, 0);
});
test('serial identities and real batch facts survive pagination, keyword filtering and selected removal', async () => {
  const picker = mount(async (_, query) => paged([serial(query.page)], query.page, 11, 2));
  picker.initialize(); await flush(); picker.toggle(event({ key: '1' })); await picker.turnPage(event({ delta: 1 })); picker.toggle(event({ key: '2' }));
  picker.setData({ keyword: 'SN-1' }); await picker.search(); assert.equal(picker.data.selectedCount, 2);
  picker.toggleSelected(); picker.remove(event({ key: '2' })); picker.confirm();
  assert.equal(picker.emitted.name, 'confirm'); assert.equal(picker.emitted.detail.rows.length, 1); assert.equal(picker.emitted.detail.rows[0].id, 1); assert.equal(picker.emitted.detail.rows[0].batch_no, 'LOT-A');
});
test('cancel is isolated from caller dispositions, and reopened selection starts from caller rows', async () => {
  const original = [serial(7)]; const picker = mount(async () => paged([serial(8, 'LOT-B')]), { selected: original });
  picker.initialize(); await flush(); picker.remove(event({ key: '7' })); picker.toggle(event({ key: '8' })); picker.cancel();
  assert.deepEqual(original, [serial(7)]); assert.equal(picker.emitted.name, 'cancel');
  picker.initialize(); await flush(); assert.equal(picker.data.selectedRows[0].id, 7);
});
test('superseded or closed request cannot replace the current return candidate list', async () => {
  const waiting = []; const picker = mount(() => new Promise(resolve => waiting.push(resolve)));
  picker.initialize(); picker.setData({ keyword: 'new' }); picker.search();
  waiting[1](paged([serial(2)])); await flush(); waiting[0](paged([serial(1)])); await flush(); assert.equal(picker.data.rows[0].id, 2);
  picker.search(); picker.cancel(); picker.properties.open = false; waiting[2](paged([serial(3)])); await flush(); assert.equal(picker.data.rows.length, 0);
});
test('selection maximum is enforced without auto replacement of explicit serials', async () => {
  const picker = mount(async () => paged([serial(1), serial(2)]), { maxCount: 1 });
  picker.initialize(); await flush(); picker.toggle(event({ key: '1' })); picker.toggle(event({ key: '2' }));
  assert.equal(picker.data.selectedCount, 1); assert.equal(picker.data.selectedRows[0].id, 1); assert.match(picker.data.error, /最多选择1项/);
});
