import api from './production'

const base = '/v1/erp/production'
export const getAssemblyPlan = id => api.get(`${base}/work-orders/${id}/assembly-plan-preview`)
export const prepareAssembly = (id, data) => api.post(`${base}/work-orders/${id}/prepare-assembly`, data)
export const listJobBundles = params => api.get(`${base}/job-bundles`, { params })
export const getJobBundle = id => api.get(`${base}/job-bundles/${id}`)
export const jobBundleCandidates = params => api.get(`${base}/job-bundles/candidates`, { params })
export const createJobBundle = data => api.post(`${base}/job-bundles`, data)
export const cancelJobBundle = (id, data) => api.post(`${base}/job-bundles/${id}/cancel`, data)

const keyFor = action => {
  let actor = 'anonymous'
  try { const user = JSON.parse(localStorage.getItem('erp_user') || '{}'); actor = user.legacy_id || user.id || actor } catch (_) { /* No persisted account. */ }
  return `erp_assembly_command:${process.env.VUE_APP_BASE_API}:${actor}:${action}`
}
export const pendingAssemblyCommand = action => {
  try { return JSON.parse(localStorage.getItem(keyFor(action)) || 'null') } catch (_) { return null }
}
const inflight = new Map()
export function executeAssemblyCommand (action, data, send, displayRows = []) {
  const key = keyFor(action)
  if (inflight.has(key)) return inflight.get(key)
  // A lost response can follow a committed stock reservation or job creation.
  // Retain the exact version, selected task identities and command on every retry.
  const pending = pendingAssemblyCommand(action) || JSON.parse(JSON.stringify({
    payload: { ...data, client_command_id: `assembly-${Date.now()}-${Math.random().toString(36).slice(2, 12)}` }, display_rows: displayRows
  }))
  localStorage.setItem(key, JSON.stringify(pending))
  const job = Promise.resolve().then(() => send(pending.payload)).then(result => {
    localStorage.removeItem(key)
    return result
  }).catch(error => {
    const status = Number(error.response?.status || 0)
    const code = error.response?.data?.error_code || error.errorCode
    const unresolved = [401, 403, 404, 408, 429].includes(status) || ['state_conflict', 'command_processing', 'command_recovery_required', 'recovery_required'].includes(code)
    if (status >= 400 && status < 500 && !unresolved) localStorage.removeItem(key)
    error.pendingCommand = Boolean(localStorage.getItem(key))
    throw error
  }).finally(() => inflight.delete(key))
  inflight.set(key, job)
  return job
}

export const productionQuantity = value => value === null || value === undefined || value === ''
  ? '—' : String(value).replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '')
export const bundleStatusName = value => ({ DRAFT: '待接单', WAIT_CLAIM: '待接单', CLAIMED: '已接单', READY: '待开工', IN_PROGRESS: '加工中', PAUSED: '已暂停', FINISHED: '已结束', COMPLETED: '已结束', CANCELLED: '已取消' })[value] || value || '—'
