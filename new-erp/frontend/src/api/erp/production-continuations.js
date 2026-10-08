import api from './master'

const base = id => `/v1/erp/production/work-orders/${id}/inventory-continuation`
const actorKey = () => {
  try { const actor = JSON.parse(localStorage.getItem('erp_user') || '{}'); return actor.legacy_id || actor.id || 'anonymous' } catch (_) { return 'anonymous' }
}
const storageKey = id => `erp_inventory_continuation_pending:${process.env.VUE_APP_BASE_API}:${actorKey()}:${id}`
export const pendingInventoryContinuation = id => {
  try { return JSON.parse(localStorage.getItem(storageKey(id)) || 'null') } catch (_) { return null }
}
export const listInventoryContinuationCandidates = (id, params) => api.get(base(id) + '/candidates', { params })
export const listInventoryContinuationSerials = (id, params) => api.get(base(id) + '/serials', { params })
const inflight = new Map()
export const configureInventoryContinuation = (id, data) => {
  const key = storageKey(id)
  if (inflight.has(key)) return inflight.get(key)
  const payload = pendingInventoryContinuation(id) || JSON.parse(JSON.stringify({ ...data, client_command_id: `inventory-continuation-${Date.now()}-${Math.random().toString(36).slice(2, 12)}` }))
  localStorage.setItem(key, JSON.stringify(payload))
  const job = Promise.resolve().then(() => api.post(base(id), payload)).then(response => {
    localStorage.removeItem(key)
    return response
  }).catch(error => {
    const status = Number(error.response?.status || 0)
    if (status >= 400 && status < 500 && error.response?.data?.error_code !== 'command_processing') localStorage.removeItem(key)
    error.pendingCommand = Boolean(localStorage.getItem(key))
    throw error
  }).finally(() => inflight.delete(key))
  inflight.set(key, job)
  return job
}
