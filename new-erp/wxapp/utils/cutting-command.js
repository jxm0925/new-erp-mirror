const erpRequest = require('./erp-request');

function canonical(value) {
  if (Array.isArray(value)) return value.map(canonical);
  if (!value || typeof value !== 'object') return value;
  const result = {};
  Object.keys(value).sort().forEach(key => { if (value[key] !== undefined) result[key] = canonical(value[key]); });
  return result;
}
function businessPayload(data) {
  const result = Object.assign({}, data);
  delete result.expected_version; delete result.client_command_id;
  return JSON.stringify(canonical(result));
}

// A lost response is not a failed transaction. Keep the original version and command
// across refresh/restart; an explicit retry recovers its immutable server response.
function write(path, data, prefix, method = 'POST') {
  const actor = wx.getStorageSync('erp_user') || {};
  const key = `cutting-pending:${actor.legacy_id || actor.id || 'session'}:${method}:${path}`;
  const pending = wx.getStorageSync(key);
  if (pending && pending.payload && businessPayload(pending.payload) !== businessPayload(data)) {
    return Promise.reject(new Error('上次操作结果尚未确认，请先按原内容重试，不能改成另一笔操作。'));
  }
  const payload = pending && pending.payload ? pending.payload : Object.assign({}, data, {
    client_command_id: data.client_command_id || erpRequest.createClientCommandId(prefix),
  });
  wx.setStorageSync(key, { payload });
  return erpRequest.request({ path, method, data: payload }).then(response => {
    wx.removeStorageSync(key);
    return response;
  }).catch(error => {
    // Validation/permission conflicts are definitive rollbacks. Timeouts, server
    // failures and processing conflicts have an unknown commit outcome, so retain.
    // Authentication expiry or throttling does not disprove an earlier timeout's
    // commit. Retain identity until the original business command is resolved.
    if (error.statusCode >= 400 && error.statusCode < 500 && ![401, 403, 408, 429].includes(error.statusCode) && error.errorCode !== 'command_processing') wx.removeStorageSync(key);
    throw error;
  });
}

module.exports = { write, businessPayload };
