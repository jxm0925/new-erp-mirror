import api from './production'

const base = id => `/v1/erp/production/work-orders/${id}/planned-outputs`
const actorKey = () => {
  try { const actor = JSON.parse(localStorage.getItem('erp_user') || '{}'); return actor.legacy_id || actor.id || 'anonymous' } catch (_) { return 'anonymous' }
}
const storageKey = id => `erp_output_plan_pending:${process.env.VUE_APP_BASE_API}:${actorKey()}:${id}`
export const pendingWorkOrderOutputPlan = id => {
  try { return JSON.parse(localStorage.getItem(storageKey(id)) || 'null') } catch (_) { return null }
}
export const getWorkOrderPlannedOutputs = id => api.get(base(id))
export const getWorkOrderOutputPlanPreview = id => api.get(`/v1/erp/production/work-orders/${id}/output-plan-preview`)
export const searchWorkOrderOutputOptions = (id, params) => api.get(`/v1/erp/production/work-orders/${id}/planned-output-options`, { params })

const inflight = new Map()
export const saveWorkOrderPlannedOutputs = (id, data, displayRows = []) => {
  const key = storageKey(id)
  if (inflight.has(key)) return inflight.get(key)
  // A lost response may follow a committed plan update. Keep the original command,
  // version and UUIDs across reloads so retry cannot create a second plan revision.
  const pending = pendingWorkOrderOutputPlan(id) || JSON.parse(JSON.stringify({
    payload: { ...data, client_command_id: `output-plan-${Date.now()}-${Math.random().toString(36).slice(2, 12)}` },
    display_rows: displayRows
  }))
  localStorage.setItem(key, JSON.stringify(pending))
  const job = Promise.resolve().then(() => api.put(base(id), pending.payload)).then(response => {
    localStorage.removeItem(key)
    return response
  }).catch(error => {
    const status = Number(error.response?.status || 0)
    const code = error.response?.data?.error_code || error.errorCode
    // Authentication, scope and draft-state checks run before ledger lookup. A
    // retry rejected there cannot disprove an earlier successful commit.
    const unresolved = [401, 403, 404, 408, 429].includes(status) || ['state_conflict', 'command_processing', 'command_recovery_required', 'recovery_required'].includes(code)
    if (status >= 400 && status < 500 && !unresolved) localStorage.removeItem(key)
    error.pendingCommand = Boolean(localStorage.getItem(key))
    if (error.pendingCommand && code === 'state_conflict') error.userMessage = '保存结果尚未确认，工单状态已变化。请先核对工单并恢复草稿，再重试原保存。'
    throw error
  }).finally(() => inflight.delete(key))
  inflight.set(key, job)
  return job
}
