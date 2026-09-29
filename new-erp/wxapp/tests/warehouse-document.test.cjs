const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const plain = value => JSON.parse(JSON.stringify(value));
const event = (dataset, value) => ({ currentTarget: { dataset }, detail: { value } });
function fixture(kind, stage = 'post') {
  let definition; const calls = []; const submits = []; const documents = []; const results = [];
  const service = { document: (...args) => { calls.push(args); return Promise.resolve(documents.shift()); }, pending: () => null,
    submit: (...args) => { submits.push(plain(args)); return Promise.resolve(results.shift()); } };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../pages/warehouse/document/index.js'), 'utf8'), {
    Page: value => { definition = value; }, wx: { setNavigationBarTitle() {} }, require: name => name.includes('/services/') ? service : require(path.resolve(__dirname, '../pages/warehouse/document', name)),
  });
  const page = Object.assign({}, definition, { data: plain(definition.data), drafts: {}, setData(values) { Object.assign(this.data, values); } });
  page.data.kind = kind; page.data.stage = stage; page.data.id = 41;
  return { page, calls, submits, documents, results };
}
const doc = (lines, actions, page = 1, total = 1, extra = {}) => ({ data: { header: { id: 41 }, lines: { data: lines, current_page: page, total, last_page: Math.ceil(total / 10) }, actions, ...extra } });

test('sales-return draft survives server pagination and submits both pages with precise source ids', async () => {
  const f = fixture('sales_return', 'receive');
  f.documents.push(doc([{ id: 101, requested_base_qty: '5', cost_allocations: [{ shipment_line: { batch_no: 'LOT-A' } }] }], ['sales_return.receive'], 1, 11));
  await f.page.load();
  f.page.returnInput(event({ id: 101, field: 'received_base_qty' }, '2'));
  f.page.returnInput(event({ id: 101, field: 'restock_base_qty' }, '2'));
  f.page.setData({ locatorTarget: 'return', locatorLine: 101 }); f.page.chooseLocator({ detail: { warehouse_id: 3, location_id: 31 } });
  f.page.setData({ page: 2 }); f.documents.push(doc([{ id: 111, requested_base_qty: '4' }], ['sales_return.receive'], 2, 11)); await f.page.load();
  f.page.returnInput(event({ id: 111, field: 'received_base_qty' }, '1'));
  f.page.returnInput(event({ id: 111, field: 'restock_base_qty' }, '0'));
  f.page.returnInput(event({ id: 111, field: 'rejected_base_qty' }, '1'));
  f.page.setData({ page: 1 }); f.documents.push(doc([{ id: 101 }], ['sales_return.receive'], 1, 11)); await f.page.load();
  assert.equal(f.page.data.lines[0].draft.received_base_qty, '2');
  f.results.push({ id: 901 }); f.documents.push(doc([], ['sales_return.post'])); await f.page.submit();
  const [action, id, payload] = f.submits[0]; assert.equal(action, 'sales_return.receive'); assert.equal(id, 41);
  assert.deepEqual(payload.items.map(i => i.sales_return_item_id), [101, 111]);
  assert.equal(payload.items[0].location_id, 31); assert.equal(payload.items[0].batch_no, 'LOT-A');
  assert.equal('locator' in payload.items[0], false);
  assert.equal(f.page.data.id, 901); assert.equal(f.page.data.stage, 'post'); assert.equal(f.page.data.page, 1);
  assert.equal(f.calls.at(-1)[1], 901); assert.equal(f.calls.at(-1)[2].stage, 'post'); assert.deepEqual(plain(f.page.drafts), {});
});

test('purchase allocation saves only selected receipt line and exact physical dimensions', async () => {
  const f = fixture('purchase_receipt');
  f.documents.push(doc([{ id: 321, allocation_revision: 'saved-line-revision', item: { material_management_mode: 'physical' }, allocations: [{ warehouse_id: 3, location_id: 31, base_qty: '1', serial_nos: ['A-1'], physical_entries: [{ dimensions: { length_mm: '600', width_mm: '400', thickness_mm: '2' } }] }] }], ['purchase.allocate', 'purchase.post']));
  await f.page.load(); f.page.openAllocation(event({ id: 321 }));
  f.page.allocationInput(event({ index: 0, piece: 0, field: 'width_mm' }, '450'));
  f.results.push({ receipt_id: 41 }); f.documents.push(doc([], ['purchase.post'])); await f.page.saveAllocation();
  assert.deepEqual(f.submits[0], ['purchase.allocate', 41, { items: [{ receipt_item_id: 321, expected_revision: 'saved-line-revision', allocations: [{ warehouse_id: 3, location_id: 31, base_qty: '1', physical_entries: [{ dimensions: { length_mm: '600', width_mm: '450', thickness_mm: '2' } }], serial_nos: ['A-1'] }] }] }]);
  assert.equal(f.page.data.allocationOpen, false); assert.deepEqual(plain(f.page.data.allocations), []);
});

test('output warehouse posts displayed version, exact quantity and source batch then opens formal posting', async () => {
  const f = fixture('output');
  f.documents.push(doc([], ['output.warehouse'], 1, 0, { header: { id: 41, business_version: 8, batch_no: 'WO-BATCH', remaining_qty: '3.00000000' } }));
  await f.page.load(); f.page.setData({ locator: { warehouse_id: 3, location_id: 31 }, quantity: '1.25' });
  f.results.push({ posting_id: 221 }); f.documents.push(doc([], [], 1, 0, { receipt: { id: 221, receipt_no: 'FGR001' } }));
  await f.page.submit();
  assert.deepEqual(f.submits[0], ['output.warehouse', 41, { expected_version: 8, warehouse_id: 3, location_id: 31, batch_no: 'WO-BATCH', posted_base_qty: '1.25' }]);
  assert.equal(f.calls.at(-1)[2].receipt_id, 221); assert.equal(f.page.data.readonly, true);
});

test('pending command recovery ignores current edited form and preserves original action', async () => {
  const f = fixture('sales_shipment'); f.page.setData({ mainAction: 'sales_shipment.dispatch', pendingAction: 'sales_shipment.post' });
  f.results.push({ id: 41 }); f.documents.push(doc([], ['sales_shipment.dispatch'])); await f.page.submit();
  assert.deepEqual(f.submits[0], ['sales_shipment.post', 41, {}]);
});

test('stale response cannot overwrite a later document page', async () => {
  const f = fixture('purchase_receipt'); let resolveFirst;
  f.documents.push(new Promise(resolve => { resolveFirst = resolve; }), doc([{ id: 211 }], [], 2, 11));
  const old = f.page.load(); f.page.setData({ page: 2 }); await f.page.load();
  resolveFirst(doc([{ id: 201 }], [], 1, 11)); await old;
  assert.equal(f.page.data.lines[0].id, 211); assert.equal(f.page.data.page, 2);
});

test('sales-return source shipment and batch survive amount redaction without cost allocations', async () => {
  const f = fixture('sales_return', 'receive');
  f.documents.push(doc([{ id: 101, source_shipments: ['SHP-01', 'SHP-02'], source_batches: ['LOT-RETURN'] }], ['sales_return.receive']));
  await f.page.load();
  assert.equal(f.page.data.lines[0].sourceShipments, 'SHP-01、SHP-02');
  assert.equal(f.page.data.lines[0].draft.batch_no, 'LOT-RETURN');
  assert.equal('cost_allocations' in f.page.data.lines[0], false);
});
