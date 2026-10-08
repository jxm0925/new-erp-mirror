const request = require('../utils/erp-request');
const { getErpApiBaseUrl } = require('../config/erp');
const base = 'production/job-bundles';
const inflight = new Map();

function keyFor(action) {
  const user = wx.getStorageSync('erp_user') || {};
  return `erp_bundle_command:${getErpApiBaseUrl()}:${user.legacy_id || user.id || 'anonymous'}:${action}`;
}
function pending(action) { return wx.getStorageSync(keyFor(action)) || null; }
function execute(action, payload, send) {
  const key = keyFor(action);
  if (inflight.has(key)) return inflight.get(key);
  // Replaying the same command is essential after an acknowledged request loses
  // its response; rebuilding it could duplicate a report or a labor interval.
  const saved = pending(action) || { payload: Object.assign({}, payload, { client_command_id: request.createClientCommandId('bundle') }) };
  wx.setStorageSync(key, saved);
  const job = Promise.resolve().then(() => send(saved.payload)).then(result => {
    wx.removeStorageSync(key); return result;
  }).catch(error => {
    const status = Number(error.statusCode || 0);
    const unresolved = [401, 403, 404, 408, 429].includes(status)
      || ['state_conflict', 'command_processing', 'command_recovery_required', 'recovery_required'].includes(error.errorCode);
    if (status >= 400 && status < 500 && !unresolved) wx.removeStorageSync(key);
    error.pendingCommand = Boolean(wx.getStorageSync(key));
    throw error;
  }).finally(() => inflight.delete(key));
  inflight.set(key, job); return job;
}
module.exports = {
  pending, execute,
  list: query => request.request({ path: base, query: query || {}, loading: false }),
  detail: id => request.request({ path: `${base}/${id}`, loading: false }),
  action: (id, action, data) => request.write(`${base}/${id}/${action}`, data, { commandPrefix: `bundle-${action}`, loading: false }),
  report: (id, lineId, data) => request.write(`${base}/${id}/lines/${lineId}/report`, data, { commandPrefix: 'bundle-report', loading: false }),
  complete: (id, lineId, data) => request.write(`${base}/${id}/lines/${lineId}/complete`, data, { commandPrefix: 'bundle-complete', loading: false }),
};
