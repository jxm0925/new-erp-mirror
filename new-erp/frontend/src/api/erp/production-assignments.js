import axios from 'axios'

const api = axios.create({ baseURL: process.env.VUE_APP_BASE_API, timeout: 20000 })
api.interceptors.request.use(config => {
  const token = localStorage.getItem('erp_token')
  if (token) config.headers.Authorization = 'Bearer ' + token
  return config
})
api.interceptors.response.use(response => response, error => Promise.reject(Object.assign(error, {
  userMessage: error.response?.data?.message || '操作失败，请重试原操作',
  errorCode: error.response?.data?.error_code
})))
const base = '/v1/erp/production'

export const listAssignmentTasks = params => api.get(base + '/tasks', { params })
export const listTaskAssignments = params => api.get(base + '/assignments', { params })
export const listAssignmentCandidates = (id, params) => api.get(base + '/tasks/' + id + '/assignment-candidates', { params })
export const offerFastestAssignment = (id, data) => api.post(base + '/tasks/' + id + '/auto-assign', data)
export const acceptTaskAssignment = (id, data) => api.post(base + '/assignments/' + id + '/accept', data)
export const rejectTaskAssignment = (id, data) => api.post(base + '/assignments/' + id + '/reject', data)
export const claimAssignmentTask = (id, data) => api.post(base + '/tasks/' + id + '/claim', data)
export const listTaskCollaboratorCandidates = (id, params) => api.get(base + '/tasks/' + id + '/collaborator-candidates', { params })
export const addTaskCollaborators = (id, data) => api.post(base + '/tasks/' + id + '/collaborators', data)

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
    throw error
  })
}
