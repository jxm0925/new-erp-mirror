const request = require('./erp-request');
const { getErpApiBaseUrl } = require('../config/erp');

const inflight = new Map();
function actor() {
  const user = wx.getStorageSync('erp_user') || {};
  const id = Number(user.legacy_id || user.id || 0);
  if (!id || !wx.getStorageSync('erp_token')) throw new Error('请先登录ERP');
  return `${encodeURIComponent(getErpApiBaseUrl())}:${id}`;
}
function key(path) { return `erp_material_preparation_pending:${actor()}:${path}`; }
function pending(path) { try { return wx.getStorageSync(key(path)) || null; } catch (_) { return null; } }
function pendingList(prefix) {
  try {
    const base = `erp_material_preparation_pending:${actor()}:`;
    return wx.getStorageInfoSync().keys.filter(value => value.startsWith(base + prefix)).map(value => ({ path: value.slice(base.length), body: wx.getStorageSync(value) }));
  } catch (_) { return []; }
}
function submit(path, payload) {
  let storageKey; let identity;
  try { identity = actor(); storageKey = key(path); } catch (error) { return Promise.reject(error); }
  if (inflight.has(storageKey)) return inflight.get(storageKey);
  const old = wx.getStorageSync(storageKey);
  const body = old || JSON.parse(JSON.stringify(Object.assign({}, payload, { client_command_id: request.createClientCommandId('material-preparation') })));
  if (!old) wx.setStorageSync(storageKey, body);
  const operation = request.request({ path, method: 'POST', data: body, loading: false }).then(response => {
    if (actor() !== identity) throw new Error('登录账号已变化，请重新打开当前单据');
    wx.removeStorageSync(storageKey);
    return Object.assign({}, response, { recoveredCommand: !!old });
  }).catch(error => {
    // Authentication, gateway timeouts and throttling cannot establish whether an earlier
    // submission committed. Keep its original command until the server confirms the result.
    if (error.statusCode >= 400 && error.statusCode < 500 && ![401, 403, 404, 408, 429].includes(error.statusCode)
      && !['command_processing', 'idempotency_hash_conflict'].includes(error.errorCode)) wx.removeStorageSync(storageKey);
    error.pendingCommand = !!wx.getStorageSync(storageKey);
    throw error;
  }).finally(() => inflight.delete(storageKey));
  inflight.set(storageKey, operation);
  return operation;
}
module.exports = { submit, pending, pendingList };
