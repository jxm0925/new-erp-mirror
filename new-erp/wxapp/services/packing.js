const request = require('../utils/erp-request');
const { getErpApiBaseUrl } = require('../config/erp');
const base = 'production/shipment-packing';
const inflight = new Map();
const clone = value => JSON.parse(JSON.stringify(value));
const get = (path, query) => request.request({ path, query: query || {}, loading: false });
function actor() {
  const user = wx.getStorageSync('erp_user') || {};
  const id = Number(user.legacy_id || user.id || 0);
  if (!id || !wx.getStorageSync('erp_token')) throw new Error('请先登录ERP');
  return id;
}
function key(id) { return `erp_packing_pending:${encodeURIComponent(getErpApiBaseUrl())}:${actor()}:${Number(id)}`; }
function pending(id) { try { return wx.getStorageSync(key(id)) || null; } catch (_) { return null; } }
function action(id, payload) {
  let storageKey;
  try { storageKey = key(id); } catch (error) { return Promise.reject(error); }
  if (inflight.has(storageKey)) return inflight.get(storageKey);
  const actorId = actor();
  const old = wx.getStorageSync(storageKey);
  const job = old || { operation_id: Number(id), payload: Object.assign({}, clone(payload), { client_command_id: request.createClientCommandId('packing') }) };
  if (!old) wx.setStorageSync(storageKey, job);
  const sameActor = () => { if (actor() !== actorId) throw new Error('登录账号已变化，请重新打开当前包装作业'); };
  const finish = response => { sameActor(); if (Number(response.operation_id) !== Number(id)) throw new Error('返回结果与当前包装工序不一致'); wx.removeStorageSync(storageKey); return response; };
  const operation = Promise.resolve().then(async () => {
    if (old) {
      const queried = await get(`${base}/commands/result`, { client_command_id: job.payload.client_command_id });
      const result = queried.data || queried;
      if (result.status === 'SUCCEEDED') return finish(result.response);
      if (result.status !== 'NOT_FOUND') throw new Error('原操作正在处理，请稍后核对结果');
    }
    sameActor();
    const response = await request.request({ path: `${base}/operations/${Number(id)}/actions`, method: 'POST', data: clone(job.payload), loading: false });
    return finish(response.data || response);
  }).catch(error => {
    // A business rejection rolls back the entire packing command. Network and permission
    // errors cannot prove the previous attempt failed, so the exact request remains frozen.
    if (error.statusCode === 422) wx.removeStorageSync(storageKey);
    error.pendingCommand = Boolean(wx.getStorageSync(storageKey));
    throw error;
  }).finally(() => inflight.delete(storageKey));
  inflight.set(storageKey, operation);
  return operation;
}
module.exports = {
  pending, action,
  operations: query => get(`${base}/operations`, query),
  operation: id => get(`${base}/operations/${Number(id)}`),
  people: (id, query) => get(`${base}/operations/${Number(id)}/people`, query),
  materials: (id, query) => get(`${base}/operations/${Number(id)}/material-sources`, query),
  identities: (id, query) => get(`${base}/operations/${Number(id)}/material-identities`, query),
};
