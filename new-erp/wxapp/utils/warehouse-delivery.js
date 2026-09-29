const quantity = require('./warehouse-page').quantity;
const { getErpApiBaseUrl } = require('../config/erp');
const statuses = { READY: '待发出', IN_TRANSIT: '配送中', DELIVERED: '待签收', RECEIVED: '已完成', CANCELLED: '已取消' };
function amount(value) { const text = String(value == null ? '' : value).trim(); return /^(?:\d+)(?:\.\d{1,8})?$/.test(text) ? Number(text) : NaN; }
function lineView(line, mode) {
  const pick = mode === 'create' ? line : (line.picking_task_line || {});
  const item = pick.component_item || {};
  const balance = pick.inventory_balance || {};
  const serialIds = mode === 'create' ? line.available_delivery_serial_ids : mode === 'redelivery' ? line.available_redelivery_serial_ids : (line.serial_snapshot || {}).inventory_serial_ids;
  const remaining = mode === 'create' ? line.remaining_delivery_qty : mode === 'redelivery' ? line.remaining_redelivery_qty : Math.max(0, Number(line.delivery_qty) - Number(line.received_qty) - Number(line.rejected_qty));
  return Object.assign({}, line, { pickingId: mode === 'create' ? line.id : line.picking_task_line_id,
    name: item.name || item.item_name || '', code: item.code || item.item_code || '', specification: item.specification || item.spec || '',
    unit: line.unit_name_snapshot || pick.unit_name_snapshot || (line.requirement || {}).base_unit_name_snapshot || '',
    location: (balance.location || {}).location_name || (balance.location || {}).location_code || '',
    serial: (pick.serial_control_type && pick.serial_control_type !== 'none') || !!(serialIds && serialIds.length), availableSerialIds: (serialIds || []).map(Number),
    remaining: Number(remaining || 0), remainingText: quantity(remaining || 0), deliveryText: quantity(line.delivery_qty), pickedText: quantity(pick.actual_pick_qty),
    receivedText: quantity(line.received_qty), rejectedText: quantity(line.rejected_qty), reasons: (line.reject_reasons || []).join('；') });
}
function emptyDraft() { return { delivery_qty: '0', accepted_qty: '0', rejected_qty: '0', reject_reason: '', serialRows: [], receiptRows: [] }; }
function receiptSelection(rows) {
  const seen = new Set(), accepted = [], rejected = [], reasons = [], serialReasons = {};
  rows.forEach(row => {
    const id = Number(row.id);
    if (!Number.isInteger(id) || id <= 0 || seen.has(id)) throw new Error('序列号重复或无效，请重新核对');
    seen.add(id);
    if (row.disposition === 'accepted') accepted.push(id);
    else if (row.disposition === 'rejected') {
      if (!String(row.reason || '').trim()) throw new Error('请填写每个拒收序列号的原因');
      if (String(row.reason).trim().length > 500) throw new Error('每个序列号的拒收原因不能超过500字');
      rejected.push(id); serialReasons[id] = String(row.reason).trim(); reasons.push(`${row.serial_no}：${String(row.reason).trim()}`);
    } else throw new Error('请逐个选择签收或拒收');
  });
  const reason = reasons.join('；');
  return { accepted_qty: accepted.length, rejected_qty: rejected.length, accepted_serial_ids: accepted, rejected_serial_ids: rejected, rejected_serial_reasons: serialReasons, reject_reason: reason.length <= 500 ? reason : `共${rejected.length}个序列号拒收，详见逐件拒收原因` };
}
function payloadLines(entries, mode) {
  const result = [], serialUsed = new Set();
  Object.values(entries).forEach(({ line, draft }) => {
    const fail = message => { throw new Error(`${line.name || '物料'}：${message}`); };
    if (!line.unit) fail('计量单位缺失，请核对资料');
    if (mode !== 'detail') {
      const qty = amount(draft.delivery_qty);
      if (!Number.isFinite(qty)) fail('请输入最多8位小数的非负数量');
      if (!qty) return;
      if (qty > line.remaining + 1e-8) fail('本次数量超过剩余可配送量');
      const ids = (draft.serialRows || []).map(row => Number(row.id));
      if (line.serial && (ids.length !== qty || ids.some(id => !line.availableSerialIds.includes(id)))) fail('配送数量须与本行可配送序列选择一致');
      ids.forEach(id => { if (serialUsed.has(id)) fail('序列号不能重复配送'); serialUsed.add(id); });
      result.push({ picking_task_line_id: line.pickingId, delivery_qty: qty, serial_ids: ids });
    } else {
      let row = { accepted_qty: amount(draft.accepted_qty), rejected_qty: amount(draft.rejected_qty), reject_reason: String(draft.reject_reason || '').trim(), accepted_serial_ids: [], rejected_serial_ids: [] };
      if (line.serial) row = receiptSelection(draft.receiptRows || []);
      if (!Number.isFinite(row.accepted_qty) || !Number.isFinite(row.rejected_qty)) fail('请输入最多8位小数的非负数量');
      if (!row.accepted_qty && !row.rejected_qty) return;
      if (row.accepted_qty + row.rejected_qty > line.remaining + 1e-8) fail('签收与拒收之和超过未处理实送数');
      if (row.rejected_qty && !row.reject_reason) fail('请填写拒收原因');
      if (row.reject_reason.length > 500) fail('拒收原因不能超过500字');
      row.accepted_serial_ids.concat(row.rejected_serial_ids).forEach(id => { if (!line.availableSerialIds.includes(id) || serialUsed.has(id)) fail('序列号不属于本行或重复处理'); serialUsed.add(id); });
      result.push(Object.assign({ delivery_line_id: line.id }, row));
    }
  });
  if (!result.length) throw new Error(mode === 'detail' ? '请填写本次签收或拒收数量' : '请填写本次配送数量');
  return result;
}
function creationStore(storage, apiBase) {
  storage = storage || wx;
  const user = storage.getStorageSync('erp_user') || {};
  const actor = Number(user.legacy_id || user.id || 0);
  const key = `erp_warehouse_delivery_create_context:${encodeURIComponent(apiBase || getErpApiBaseUrl())}:${actor}`;
  const read = () => actor && storage.getStorageSync('erp_token') ? storage.getStorageSync(key) || {} : {};
  return {
    remember(context) { if (!actor) throw new Error('请先登录ERP'); const all = read(); all[context.pickingId] = context; storage.setStorageSync(key, all); },
    remove(id) { const all = read(); delete all[id]; storage.setStorageSync(key, all); },
    pending(findPending) { return Object.values(read()).filter(context => !!findPending('delivery.create', context.pickingId)); },
  };
}
module.exports = { statuses, amount, lineView, emptyDraft, receiptSelection, payloadLines, creationStore };
