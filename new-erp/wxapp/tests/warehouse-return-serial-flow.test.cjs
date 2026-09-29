const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const view = require('../utils/warehouse-document');
const ui = require('../utils/warehouse-page');
const plain = value => JSON.parse(JSON.stringify(value));
const tick = () => new Promise(resolve => setImmediate(resolve));
const ev = (id, disposition) => ({ currentTarget: { dataset: { id, disposition } } });
const serial = (id, batch = 'LOT-B', shipment = 'SHP-B') => ({ id, serial_no: `SN-${id}`, batch_no: batch, shipment_no: shipment, outbound_transaction_item_id: 900 + id });
const groups = (restock = [], pending = [], scrap = [], rejected = []) => ({ restock, pending, scrap, rejected });
const pageOf = (rows, page = 1, total = rows.length, last = 1) => ({ data: rows, current_page: page, total, last_page: last });
function component(get, props = {}) {
  let definition;
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../components/warehouse-return-serial/index.js'), 'utf8'), {
    Component: value => { definition = value; }, require: name => name.includes('/services/') ? { get } : name.includes('warehouse-document') ? view : ui,
  });
  const result = Object.assign({ data: plain(definition.data), observers: definition.observers, properties: { open: true, returnId: 41, line: { id: 101, itemName: '控制器', unit: '个', sourceBatch: 'FIFO-A', sourceShipments: 'FIFO-SHIP-A', remaining_receivable_qty: '10' }, value: {}, locked: false, conflict: false, excluded: [], ...props },
    setData(values) { Object.assign(this.data, values); }, triggerEvent(name, detail) { this.emitted = { name, detail }; } }, definition.methods);
  return result;
}
function candidateService(rows, calls = []) {
  return async (_, q) => {
    calls.push(plain(q)); const ids = Object.keys(q).filter(key => /^ids\[/.test(key)).map(key => Number(q[key]));
    let result = rows.filter(row => (!ids.length || ids.includes(row.id)) && (!q.batch_no || row.batch_no === q.batch_no) && (!q.keyword || row.serial_no.includes(q.keyword)));
    const total = result.length; const last = Math.max(1, Math.ceil(total / q.per_page)); result = result.slice((q.page - 1) * q.per_page, q.page * q.per_page);
    return pageOf(result, q.page, total, last);
  };
}
function documentFixture() {
  let definition; const documents = []; const submits = []; const results = []; let pending = null;
  const service = { document: async () => documents.shift(), pending: () => pending, submit: async (...args) => { submits.push(plain(args)); const next = results.shift(); if (next instanceof Error) throw next; return next; } };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../pages/warehouse/document/index.js'), 'utf8'), {
    Page: value => { definition = value; }, wx: { setNavigationBarTitle() {} }, require: name => name.includes('/services/') ? service : name.includes('warehouse-document') ? view : ui,
  });
  const page = Object.assign({}, definition, { data: plain(definition.data), drafts: {}, returnLineCache: {}, setData(values) { Object.assign(this.data, values); } });
  page.setData({ id: 41, kind: 'sales_return', stage: 'receive' });
  return { page, documents, submits, results, setPending(value) { pending = value; } };
}
const document = (rows, page = 1, total = rows.length) => ({ data: { header: { id: 41 }, actions: ['sales_return.receive'], lines: pageOf(rows, page, total, Math.max(1, Math.ceil(total / 10))) } });
const returnLine = id => ({ id, serial_tracked: true, item: { serial_tracking_mode: 'none', item_name: '控制器' }, remaining_receivable_qty: '10', source_batches: ['FIFO-A'] });
function reviewed(page, id, rows) {
  page.confirmReturnSerial({ detail: Object.assign({ line_id: id }, view.returnSerialSelection(rows, 10)) });
}

test('server serial_tracked overrides mutable item setting in both directions', () => {
  assert.equal(view.line({ serial_tracked: true, item: { serial_tracking_mode: 'none' } }).serialTracked, true);
  assert.equal(view.line({ serial_tracked: false, item: { serial_tracking_mode: 'required' } }).serialTracked, false);
});
test('unselected popup never restricts candidates to FIFO cost reservation batch or shipment', async () => {
  const calls = []; const popup = component(candidateService([serial(1)], calls)); popup.initialize(); await tick();
  assert.equal(calls[0].batch_no, undefined); assert.equal(popup.data.batchNo, ''); assert.equal(popup.data.shipmentNos, '');
  popup.choose(ev(1, 'restock')); await tick(); assert.equal(popup.data.batchNo, 'LOT-B'); assert.equal(popup.data.shipmentNos, 'SHP-B');
  popup.choose(ev(1, 'restock')); await tick(); assert.equal(popup.data.batchNo, ''); assert.equal(calls.at(-1).batch_no, undefined);
});
test('four dispositions are exclusive and clicking selected disposition removes that explicit identity', async () => {
  const popup = component(candidateService([serial(1)])); popup.initialize(); await tick();
  popup.choose(ev(1, 'restock')); await tick(); popup.choose(ev(1, 'pending'));
  assert.equal(popup.data.counts.restock, 0); assert.equal(popup.data.counts.pending, 1); assert.equal(popup.data.selectedCount, 1);
  popup.choose(ev(1, 'pending')); await tick(); assert.equal(popup.data.selectedCount, 0);
});
test('same batch supports multiple actual shipments, and mixed original batches are rejected', async () => {
  const popup = component(candidateService([serial(1), serial(2, 'LOT-B', 'SHP-C')])); popup.initialize(); await tick();
  popup.choose(ev(1, 'restock')); await tick(); popup.choose(ev(2, 'pending'));
  assert.equal(popup.data.shipmentNos, 'SHP-B、SHP-C'); assert.equal(popup.data.selectedCount, 2);
  assert.throws(() => view.returnSerialSelection(groups([serial(1)], [serial(3, 'LOT-C')]), 10), /分次收货/);
});
test('paged and searched candidates preserve selections while cancel never mutates original draft', async () => {
  const rows = Array.from({ length: 12 }, (_, index) => serial(index + 1)); const original = { serialRows: groups([serial(1)]), received_base_qty: '1', batch_no: 'LOT-B' };
  const popup = component(candidateService(rows), { value: original }); popup.initialize(); await tick(); await tick();
  await popup.turnPage({ currentTarget: { dataset: { delta: 1 } } }); popup.choose(ev(11, 'scrap'));
  popup.setData({ keyword: 'SN-2' }); await popup.search(); assert.equal(popup.data.selectedCount, 2);
  popup.cancel(); assert.equal(original.serialRows.scrap.length, 0); assert.equal(original.serialRows.restock.length, 1);
  popup.initialize(); await tick(); await tick(); assert.equal(popup.data.selectedCount, 1);
});
test('availability refresh precisely checks selected ids using bracket array keys and preserves only valid identities', async () => {
  const calls = []; const popup = component(candidateService([serial(1)], calls), { value: { serialRows: groups([serial(1)], [serial(2)]), received_base_qty: '2' } });
  popup.initialize(); await tick(); await tick();
  const verification = calls.find(q => q['ids[0]']); assert.equal(verification['ids[0]'], 1); assert.equal(verification['ids[1]'], 2); assert.equal(verification.ids, undefined);
  assert.equal(popup.data.conflictActive, true); assert.equal(popup.data.selectedCount, 1); assert.equal(popup.data.unavailableRows[0].id, 2);
  await popup.confirm(); assert.equal(popup.emitted, undefined); await popup.refresh(); assert.equal(popup.data.conflictActive, false); assert.equal(popup.data.selectedCount, 1);
  await popup.confirm(); assert.deepEqual(plain(popup.emitted.detail.serial_dispositions), groups([1]));
});
test('same serial id from a later outbound cycle invalidates earlier selected sale identity', async () => {
  const popup = component(candidateService([{ ...serial(1), outbound_transaction_item_id: 9999 }]), { value: { serialRows: groups([serial(1)]), received_base_qty: '1' } });
  popup.initialize(); await tick(); await tick(); assert.equal(popup.data.selectedCount, 0); assert.equal(popup.data.conflictActive, true);
});
test('identity becoming unavailable at confirmation is shown once as a disabled conflict row', async () => {
  const available = [serial(1)]; const popup = component(candidateService(available)); popup.initialize(); await tick();
  popup.choose(ev(1, 'restock')); await tick(); assert.equal(popup.data.rows.length, 1);
  available.splice(0); await popup.confirm();
  assert.equal(popup.data.conflictActive, true); assert.equal(popup.data.rows.length, 0); assert.equal(popup.data.unavailableRows.length, 1); assert.equal(popup.emitted, undefined);
});
test('visible modal switches line identity through its real observer, resets local selection and ignores earlier replies', async () => {
  const waiting = []; const popup = component((_, query) => new Promise(resolve => waiting.push({ query, resolve })), { value: { serialRows: groups([serial(1)]), received_base_qty: '1' } });
  const observe = popup.observers['open, returnId, line.id']; observe.call(popup, true, 41, 101);
  assert.equal(popup.data.selectedCount, 1);
  popup.properties.line = { id: 111, itemName: '另一商品', remaining_receivable_qty: '5' }; popup.properties.value = {};
  observe.call(popup, true, 41, 111); assert.equal(popup.data.selectedCount, 0); assert.equal(popup.data.batchNo, '');
  const next = waiting.find(wait => wait.query.return_item_id === 111); next.resolve(pageOf([serial(11, 'LOT-C')])); await tick();
  waiting.filter(wait => wait.query.return_item_id === 101).forEach(wait => wait.resolve(pageOf([serial(1)]))); await tick();
  assert.equal(popup.data.rows[0].id, 11); assert.equal(popup.data.selectedCount, 0); assert.equal(popup.data.conflictActive, false);
});
test('full selected-set revalidation follows server pagination and does not confuse off-page identities with invalid ones', async () => {
  const calls = []; const popup = component(async (_, q) => {
    calls.push(plain(q));
    if (!q['ids[0]']) return pageOf([serial(1)]);
    return pageOf([serial(q.page)], q.page, 2, 2);
  }, { value: { serialRows: groups([serial(1)], [serial(2)]), received_base_qty: '2' } });
  popup.initialize(); await tick(); await tick();
  assert.equal(calls.filter(q => q['ids[0]']).length, 2); assert.equal(popup.data.selectedCount, 2); assert.equal(popup.data.conflictActive, false);
});
test('closing a popup invalidates delayed selected-set checks and search replies', async () => {
  const pending = []; const popup = component(() => new Promise(resolve => pending.push(resolve)), { value: { serialRows: groups([serial(1)]) } });
  popup.initialize(); popup.cancel(); popup.properties.open = false; pending.forEach(resolve => resolve(pageOf([]))); await tick();
  assert.equal(popup.data.selectedCount, 1); assert.equal(popup.emitted.name, 'cancel');
});
test('pending command locks popup choices and confirmation, with no identity mutation', async () => {
  const popup = component(candidateService([serial(1)]), { locked: true }); popup.initialize(); await tick(); popup.choose(ev(1, 'restock')); await popup.confirm(); popup.cancel();
  assert.equal(popup.data.selectedCount, 0); assert.equal(popup.emitted, undefined);
});
test('original confirm-receipt button reviews each loaded serial line across document pages, without posting from modal confirmation', async () => {
  const f = documentFixture(); f.documents.push(document([returnLine(101)], 1, 11)); await f.page.load();
  f.page.setData({ page: 2 }); f.documents.push(document([returnLine(111)], 2, 11)); await f.page.load();
  f.page.submit(); assert.equal(f.page.data.returnSerialLine.id, 101); reviewed(f.page, 101, groups([serial(1)]));
  assert.equal(f.page.data.returnSerialLine.id, 111); reviewed(f.page, 111, groups([], [serial(2)]));
  assert.equal(f.page.data.returnSerialOpen, false); assert.equal(f.submits.length, 0);
  f.results.push({ id: 901 }); f.documents.push({ data: { header: { id: 901 }, actions: [], lines: pageOf([]) } }); await f.page.submit();
  const payload = f.submits[0][2]; assert.deepEqual(payload.items.map(row => row.sales_return_item_id), [101, 111]);
  assert.deepEqual(payload.items[0].serial_dispositions, groups([1])); assert.equal(payload.items[0].batch_no, 'LOT-B');
  for (const key of ['serialRows', 'serialReviewed', 'serialReviewFingerprint', 'serialTracked', 'remainingReceivable', 'locator']) assert.equal(key in payload.items[0], false);
});
test('read-only quantity region can reopen and modify a reviewed draft; cancel keeps the prior review', async () => {
  const f = documentFixture(); f.documents.push(document([returnLine(101)])); await f.page.load(); f.page.submit(); reviewed(f.page, 101, groups([serial(1)]));
  const original = plain(f.page.drafts[101]); f.page.openReturnSerial(ev(101)); f.page.cancelReturnSerial(); assert.deepEqual(plain(f.page.drafts[101]), original);
  f.page.openReturnSerial(ev(101)); reviewed(f.page, 101, groups([], [serial(1)])); assert.equal(f.page.drafts[101].restock_base_qty, '0'); assert.equal(f.page.drafts[101].pending_base_qty, '1');
  f.page.returnInput({ currentTarget: { dataset: { id: 101, field: 'received_base_qty' } }, detail: { value: '5' } }); assert.equal(f.page.drafts[101].received_base_qty, '1');
});
test('review is bound to identities and quantity facts; a modified draft must be reviewed again', async () => {
  const f = documentFixture(); f.documents.push(document([returnLine(101)])); await f.page.load(); f.page.submit(); reviewed(f.page, 101, groups([serial(1)]));
  f.page.drafts[101].received_base_qty = '2'; f.page.submit(); assert.equal(f.page.data.returnSerialOpen, true); assert.equal(f.submits.length, 0);
  assert.throws(() => view.returnReceiptItems(f.page.drafts), /核对全部/);
});
test('receipt payload rejects cross-line duplicate serials and tracks no identities for ordinary quantity rows', () => {
  const draft = id => { const row = { sales_return_item_id: id, serialTracked: true, serialReviewed: true, remainingReceivable: 10, locator: {}, ...view.returnSerialSelection(groups([serial(1)]), 10) }; row.serialReviewFingerprint = view.returnSerialFingerprint(row); return row; };
  assert.throws(() => view.returnReceiptItems({ 1: draft(1), 2: draft(2) }), /多个退货行/);
  const result = view.returnReceiptItems({ 1: { sales_return_item_id: 1, received_base_qty: '1.25', restock_base_qty: '1.25', pending_base_qty: '0', scrap_base_qty: '0', rejected_base_qty: '0', batch_no: 'X', locator: {} } });
  assert.equal(result[0].received_base_qty, '1.25'); assert.equal(result[0].serial_dispositions, undefined);
});
test('unknown receipt result locks draft and retries shared command without constructing a new payload', async () => {
  const f = documentFixture(); f.documents.push(document([returnLine(101)])); await f.page.load(); f.page.submit(); reviewed(f.page, 101, groups([serial(1)]));
  f.results.push(Object.assign(new Error('网络中断'), { pendingCommand: true })); await f.page.submit();
  f.page.openReturnSerial(ev(101)); assert.equal(f.page.data.returnSerialOpen, false); assert.equal(f.page.data.pendingAction, 'sales_return.receive');
  f.results.push(Object.assign(new Error('查询失败'), { pendingCommand: true })); await f.page.submit(); assert.deepEqual(f.submits[1], ['sales_return.receive', 41, {}]);
});
test('definitive serial conflict opens approved refresh state and retains draft identities for revalidation', async () => {
  const f = documentFixture(); f.documents.push(document([returnLine(101)])); await f.page.load(); f.page.submit(); reviewed(f.page, 101, groups([serial(1)]));
  f.results.push(Object.assign(new Error('序列号已被其他业务处理'), { statusCode: 422, pendingCommand: false })); await f.page.submit();
  assert.equal(f.page.data.returnSerialOpen, true); assert.equal(f.page.data.returnSerialConflict, true); assert.equal(f.page.drafts[101].serialRows.restock[0].id, 1); assert.equal(f.page.drafts[101].serialReviewed, false);
});
