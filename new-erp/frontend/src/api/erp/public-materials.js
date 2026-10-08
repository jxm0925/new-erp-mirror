import api from './master'

const root = '/v1/erp/production'
export const publicPreparations = params => api.get(`${root}/public-material-preparations`, { params })
export const publicPreparation = (id, params) => api.get(`${root}/public-material-preparations/${id}`, { params })
export const publicPreparationCommand = (id, action, body) => api.post(`${root}/public-material-preparations${id ? `/${id}/${action}` : ''}`, body)
export const procurementOptions = (kind, params) => api.get(`${root}/material-procurement-options/${kind}`, { params })
export const createMaterialProcurement = body => api.post(`${root}/material-procurement-requests`, body)
export const onsiteCollections = params => api.get(`${root}/onsite-material-collections`, { params })
export const onsiteCollectionSources = (kind, params) => api.get(`${root}/onsite-collection-sources/${kind}`, { params })
export const receiveOnsite = (id, body) => api.post(`${root}/material-picking-tasks/${id}/onsite-receive`, body)

function actorScope() {
  const user = JSON.parse(localStorage.getItem('erp_user') || '{}')
  return `${process.env.VUE_APP_BASE_API}:${Number(user.legacy_id || user.id || 0)}`
}
export function pendingMaterialWrite(key) {
  try { return JSON.parse(sessionStorage.getItem(`erp-public-material-command:${actorScope()}:${key}`) || 'null') } catch (_) { return null }
}
export function pendingMaterialWrites(prefix) {
  const base = `erp-public-material-command:${actorScope()}:`
  return Object.keys(sessionStorage).filter(key => key.startsWith(base + prefix)).map(key => ({ key: key.slice(base.length), body: pendingMaterialWrite(key.slice(base.length)) })).filter(row => row.body)
}
export async function materialWrite(key, payload, send) {
  const actor = actorScope()
  const storageKey = `erp-public-material-command:${actor}:${key}`
  const pending = JSON.parse(sessionStorage.getItem(storageKey) || 'null')
  const body = pending || { ...payload, client_command_id: `public-${window.crypto.randomUUID ? window.crypto.randomUUID() : Date.now() + '-' + Math.random().toString(36).slice(2)}` }
  sessionStorage.setItem(storageKey, JSON.stringify(body))
  try {
    const result = await send(body)
    if (actorScope() !== actor) throw Object.assign(new Error('登录账号已变化，请重新打开当前页面。'), { userMessage: '登录账号已变化，请重新打开当前页面。' })
    sessionStorage.removeItem(storageKey)
    return Object.assign(result, { recoveredCommand: !!pending })
  } catch (error) {
    if (error.response && error.response.status >= 400 && error.response.status < 500 && ![401, 403, 404, 408, 429].includes(error.response.status)
      && !['command_processing', 'idempotency_hash_conflict'].includes((error.response.data || {}).error_code)) sessionStorage.removeItem(storageKey)
    throw error
  }
}
