export function pendingProductionDecision(key) {
  let actor = 0
  try { actor = Number(JSON.parse(localStorage.getItem('erp_user') || '{}').legacy_id || 0) } catch (_) { /* No stored profile. */ }
  try { return JSON.parse(localStorage.getItem('erp_pending_production_' + actor + '_' + key) || 'null')?.payload || null } catch (_) { return null }
}

export function executeProductionDecision(key, data, send) {
  const rawUser = localStorage.getItem('erp_user')
  let actor = 0
  try { actor = Number(JSON.parse(rawUser || '{}').legacy_id || 0) } catch (_) { /* No stored profile. */ }
  const storageKey = 'erp_pending_production_' + actor + '_' + key
  let saved
  try { saved = JSON.parse(localStorage.getItem(storageKey) || 'null') } catch (_) { saved = null }
  const payload = saved?.payload || { ...data, client_command_id: 'production-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10) }
  localStorage.setItem(storageKey, JSON.stringify({ payload }))
  return Promise.resolve().then(() => send(payload)).then(result => {
    localStorage.removeItem(storageKey)
    return result
  }).catch(error => {
    const status = Number(error.response?.status || 0)
    if (status >= 400 && status < 500 && error.errorCode !== 'command_processing') localStorage.removeItem(storageKey)
    error.pendingCommand = Boolean(localStorage.getItem(storageKey))
    throw error
  })
}
