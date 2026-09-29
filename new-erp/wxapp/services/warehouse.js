const request = require('../utils/erp-request');
const { getErpApiBaseUrl } = require('../config/erp');

const inflight = new Map();
const clone = value => JSON.parse(JSON.stringify(value));
const get = (path, query) => request.request({ path, query: query || {}, loading: false });

function actor() {
  const user = wx.getStorageSync('erp_user') || {};
  const id = Number(user.legacy_id || user.id || 0);
  if (!id || !wx.getStorageSync('erp_token')) throw new Error('请先登录ERP');
  return id;
}
function key(action, id) { return `erp_warehouse_pending:${encodeURIComponent(getErpApiBaseUrl())}:${actor()}:${action}:${id}`; }
function pending(action, id) { try { return wx.getStorageSync(key(action, id)) || null; } catch (_) { return null; } }

function submit(action, id, payload) {
  let storageKey;
  try { storageKey = key(action, id); } catch (error) { return Promise.reject(error); }
  if (inflight.has(storageKey)) return inflight.get(storageKey);
  const actorId = actor();
  const old = wx.getStorageSync(storageKey);
  const job = old || { action, aggregate_id: Number(id), client_command_id: request.createClientCommandId('warehouse'), payload: clone(payload || {}) };
  // Persist the immutable request before sending. A changed form never replaces an unknown
  // request, and different accounts or API environments cannot consume each other's records.
  if (!old) wx.setStorageSync(storageKey, job);
  let posting = false;
  const sameActor = () => { if (actor() !== actorId) throw new Error('登录账号已变化，请重新打开当前单据'); };
  const finish = response => {
    sameActor();
    if (response.action !== job.action || Number(response.aggregate_id) !== Number(job.aggregate_id)) throw new Error('返回结果与当前操作不一致，请重新核对');
    wx.removeStorageSync(storageKey);
    return response.result;
  };
  const operation = Promise.resolve().then(async () => {
    if (old) {
      const queried = await get('inventory/warehouse-commands/result', { client_command_id: job.client_command_id });
      const state = queried.data || queried;
      if (state.status === 'SUCCEEDED') return finish(state.response);
      if (state.status === 'FAILED') {
        sameActor(); wx.removeStorageSync(storageKey);
        const failure = new Error(state.response.message || '原操作未成功，请调整后重新提交');
        failure.errorCode = state.response.error_code; failure.statusCode = state.response.status;
        failure.details = state.response.details || {}; failure.errors = failure.details.errors || {};
        throw failure;
      }
      if (state.status !== 'NOT_FOUND') throw new Error('操作正在处理，请稍后核对结果');
    }
    sameActor(); posting = true;
    const response = await request.request({ path: 'inventory/warehouse-commands', method: 'POST', data: clone(job), loading: false });
    return finish(response.data || response);
  }).catch(error => {
    // A failed status query cannot prove that the original write failed. Retain the job.
    // A first, definite business rejection has no ambiguous earlier attempt to recover.
    if (!old && posting && [422, 409].includes(error.statusCode)
        && !['idempotency_hash_conflict', 'command_processing'].includes(error.errorCode)) wx.removeStorageSync(storageKey);
    error.pendingCommand = !!wx.getStorageSync(storageKey);
    throw error;
  }).finally(() => inflight.delete(storageKey));
  inflight.set(storageKey, operation);
  return operation;
}

module.exports = {
  get, submit, pending,
  summary: () => get('inventory/warehouse-workspace/summary'),
  queue: query => get('inventory/warehouse-workspace', query),
  locators: query => get('inventory/warehouse-workspace/locators', query),
  document: (kind, id, query) => get(`inventory/warehouse-workspace/documents/${kind}/${id}`, query),
  remnants: (id, query) => get(`production/cutting/orders/${id}/remnants`, query),
  remnantReceipt: (id, receipt) => get(`production/cutting/orders/${id}/remnant-receipts/${receipt}`),
  cuttingOrder: id => get(`production/cutting/orders/${id}/execution`, { page: 1, per_page: 1 }),
};
