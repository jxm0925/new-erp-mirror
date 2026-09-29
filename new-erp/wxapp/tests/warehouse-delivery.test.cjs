const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const logic = require('../utils/warehouse-delivery');
const pageUtils = require('../utils/warehouse-page');
const copy = value => JSON.parse(JSON.stringify(value));
const sample = (id, quantity = '2', serial = false) => ({ id, picking_task_line_id: id + 100, delivery_qty: quantity, received_qty: '0', rejected_qty: '0', remaining_redelivery_qty: '1', unit_name_snapshot: '个', serial_snapshot: serial ? { inventory_serial_ids: [11, 12] } : null,
  picking_task_line: { serial_control_type: serial ? 'serial' : 'none', component_item: { name: '控制器', code: 'ITEM-1' } } });
const entry = (line, draft, mode = 'detail') => ({ line: logic.lineView(line, mode), draft: Object.assign(logic.emptyDraft(), draft) });
function definition(file, warehouse, component = false, deliveryLogic = logic) {
  let result;
  const context = { require(name) { if (name.includes('services/warehouse')) return warehouse; if (name.includes('utils/warehouse-page')) return pageUtils; if (name.includes('utils/warehouse-delivery')) return deliveryLogic; throw new Error(name); },
    Page: value => { result = value; }, Component: value => { result = value; }, wx: { setNavigationBarTitle() {}, stopPullDownRefresh() {}, navigateTo() {} }, console };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', file), 'utf8'), context);
  const instance = Object.assign({}, component ? result.methods : result, { data: copy(result.data), setData(patch) { Object.assign(this.data, copy(patch)); } });
  instance.definition = result;
  return instance;
}
test('收料数量不得超过剩余，拒收原因必填，非法数字不被吞掉', () => {
  const row = sample(1); row.received_qty = '1';
  assert.throws(() => logic.payloadLines({ 1: entry(row, { accepted_qty: '1', rejected_qty: '1', reject_reason: '破损' }) }, 'detail'), /超过/);
  assert.throws(() => logic.payloadLines({ 1: entry(row, { rejected_qty: '1' }) }, 'detail'), /拒收原因/);
  for (const value of ['-1', 'abc', '1e2', '0.000000001']) assert.throws(() => logic.payloadLines({ 1: entry(row, { accepted_qty: value }) }, 'detail'), /非负数量/);
});
test('序列逐件结果互斥且保留独立拒收原因', () => {
  assert.throws(() => logic.receiptSelection([{ id: 11, disposition: 'accepted' }, { id: 11, disposition: 'rejected', reason: '损坏' }]), /重复/);
  assert.throws(() => logic.receiptSelection([{ id: 11, disposition: 'rejected', reason: '' }]), /原因/);
  const result = logic.receiptSelection([{ id: 11, serial_no: 'SN-11', disposition: 'accepted' }, { id: 12, serial_no: 'SN-12', disposition: 'rejected', reason: '外壳破损' }]);
  assert.deepEqual(result.accepted_serial_ids, [11]); assert.deepEqual(result.rejected_serial_ids, [12]); assert.equal(result.rejected_serial_reasons[12], '外壳破损');
  const long = logic.receiptSelection([11, 12].map(id => ({ id, serial_no: `SN-${id}`, disposition: 'rejected', reason: '损'.repeat(300) })));
  assert.ok(long.reject_reason.length <= 500); assert.equal(long.rejected_serial_reasons[11].length, 300);
});
test('配送序列数量及来源匹配，补送只能使用真实未补余额', () => {
  const row = sample(1, '2', true); row.available_redelivery_serial_ids = [12];
  assert.throws(() => logic.payloadLines({ 1: entry(row, { delivery_qty: '2', serialRows: [{ id: 12 }] }, 'redelivery') }, 'redelivery'), /超过/);
  assert.throws(() => logic.payloadLines({ 1: entry(row, { delivery_qty: '1', serialRows: [{ id: 11 }] }, 'redelivery') }, 'redelivery'), /序列选择/);
  const result = logic.payloadLines({ 1: entry(row, { delivery_qty: '1', serialRows: [{ id: 12 }] }, 'redelivery') }, 'redelivery');
  assert.deepEqual(result, [{ picking_task_line_id: 101, delivery_qty: 1, serial_ids: [12] }]);
});
test('全部签收不自动选择尚未核对的序列，也不改动已有序列核对结果', () => {
  const p = definition('pages/warehouse/delivery/index.js', {});
  p.entries = { 1: entry(sample(1), {}), 2: entry(sample(2, '2', true), { receiptRows: [{ id: 11, disposition: 'rejected', reason: '损坏' }] }) };
  p.setData({ loading: false, canReceive: true, rows: Object.values(p.entries).map(e => e.line) });
  p.acceptAll(); assert.equal(p.entries[1].draft.accepted_qty, '2'); assert.equal(p.entries[2].draft.receiptRows.length, 1); assert.equal(p.entries[2].draft.receiptRows[0].disposition, 'rejected');
});
test('明细分页保留输入，版本变化清空，当前责任人按钮完全依赖后端动作', async () => {
  let version = 2, canReceive = true;
  const warehouse = { pending: () => null, get: async (_, q) => ({ data: { id: 8, business_version: version, picking_task_id: 4, status: 'DELIVERED', allowed_actions: canReceive ? ['delivery.receive'] : [], lines: [sample(q.line_page)], line_meta: { total: 20, current_page: q.line_page, last_page: 2 } } }) };
  const p = definition('pages/warehouse/delivery/index.js', warehouse); p.entries = {}; p.setData({ id: 8 });
  await p.load(); p.input({ currentTarget: { dataset: { id: 1, field: 'accepted_qty' } }, detail: { value: '1' } });
  p.setData({ page: 2 }); await p.load(); assert.equal(p.entries[1].draft.accepted_qty, '1'); assert.equal(p.data.canReceive, true);
  version = 3; canReceive = false; await p.load(); assert.equal(p.entries[1], undefined); assert.equal(p.data.canReceive, false); assert.match(p.data.error, /已变化/);
});
test('列表并发搜索只接受最后一次响应', async () => {
  const resolvers = [];
  const p = definition('pages/warehouse/deliveries/index.js', { get: () => new Promise(resolve => resolvers.push(resolve)) });
  const first = p.load(), second = p.load();
  resolvers[1]({ data: [{ id: 2, status: 'READY' }], total: 1 }); await second;
  resolvers[0]({ data: [{ id: 1, status: 'READY' }], total: 1 }); await first;
  assert.equal(p.data.rows[0].id, 2);
});
test('序列弹窗跨页保存结果，取消后重开仅恢复已确认的选择', async () => {
  const p = definition('components/warehouse-receipt-serial/index.js', { get: async (_, q) => ({ data: [{ id: q.page, serial_no: `SN-${q.page}` }], total: 20, current_page: q.page, last_page: 2 }) }, true);
  p.properties = { open: true, deliveryId: 8, lineId: 1, selected: [] }; p.selection = {};
  await p.load(); p.choose({ currentTarget: { dataset: { id: 1, disposition: 'accepted' } } });
  p.setData({ page: 2 }); await p.load(); p.choose({ currentTarget: { dataset: { id: 2, disposition: 'rejected' } } }); p.reasonInput({ currentTarget: { dataset: { id: 2 } }, detail: { value: '规格不符' } });
  assert.equal(p.data.accepted, 1); assert.equal(p.data.rejected, 1); assert.equal(p.selection[2].reason, '规格不符');
  p.definition.observers.open.call(p, true); assert.equal(Object.keys(p.selection).length, 0);
});
test('未知结果恢复不被当前表单及按钮状态阻止', async () => {
  let action, payload;
  const p = definition('pages/warehouse/delivery/index.js', {});
  p.setData({ loading: false, pendingAction: 'delivery.dispatch', canReceive: false });
  p.execute = async (a, data) => { action = a; payload = data; };
  await p.submit(); assert.equal(action, 'delivery.dispatch'); assert.deepEqual(copy(payload), {});
});
test('配送创建恢复上下文按账号与API隔离，来源已不在待办仍可恢复原补送路径', () => {
  const values = { erp_user: { legacy_id: 7 }, erp_token: 'token' };
  const storage = { getStorageSync: key => copy(values[key] || null), setStorageSync: (key, value) => { values[key] = copy(value); } };
  const store = logic.creationStore(storage, 'https://erp-a/api/');
  store.remember({ pickingId: 21, mode: 'redelivery', sourceDeliveryId: 31, pickingNo: 'MPT-21', target: 'WO-42' });
  const pending = (action, id) => action === 'delivery.create' && id === 21 ? { client_command_id: 'old-command' } : null;
  assert.deepEqual(store.pending(pending), [{ pickingId: 21, mode: 'redelivery', sourceDeliveryId: 31, pickingNo: 'MPT-21', target: 'WO-42' }]);
  assert.deepEqual(logic.creationStore(storage, 'https://erp-b/api/').pending(pending), []);
  values.erp_user = { legacy_id: 8 }; assert.deepEqual(logic.creationStore(storage, 'https://erp-a/api/').pending(pending), []);
  values.erp_user = { legacy_id: 7 }; assert.equal(logic.creationStore(storage, 'https://erp-a/api/').pending(pending)[0].sourceDeliveryId, 31);
  assert.deepEqual(store.pending(() => null), []); store.remove(21); assert.deepEqual(store.pending(pending), []);
});
test('恢复补送在来源查询失败后仍按原配料ID重放不可变请求', async () => {
  const saved = { pickingId: 21, mode: 'redelivery', sourceDeliveryId: 31 };
  const service = { pending: (action, id) => action === 'delivery.create' && id === 21 ? { client_command_id: 'original' } : null,
    get: async () => { throw new Error('来源不再可见'); } };
  const p = definition('pages/warehouse/delivery/index.js', service, false, Object.assign({}, logic, { creationStore: () => ({ pending: () => [saved] }) }));
  const realLoad = p.load; p.load = () => {}; p.onLoad({ recover: '21' }); p.load = realLoad;
  assert.equal(p.data.mode, 'redelivery'); assert.equal(p.data.id, 31); assert.equal(p.data.pickingId, 21);
  await p.load(); assert.equal(p.data.header, null); assert.equal(p.data.pendingAction, 'delivery.create');
  let recovered = false;
  p.execute = (action, payload) => { recovered = action === 'delivery.create' && p.data.pickingId === 21 && Object.keys(payload).length === 0; };
  await p.submit(); assert.equal(recovered, true);
});
