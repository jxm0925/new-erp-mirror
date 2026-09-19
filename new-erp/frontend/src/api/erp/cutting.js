import axios from 'axios'

const api = axios.create({ baseURL: process.env.VUE_APP_BASE_API, timeout: 30000 })
api.interceptors.request.use(config => {
  const token = localStorage.getItem('erp_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})
api.interceptors.response.use(response => response, error => {
  const errors = error.response?.data?.errors
  const first = errors && Object.values(errors)[0]
  const message = (Array.isArray(first) ? first[0] : first) || error.response?.data?.message || '请求失败，请稍后重试'
  return Promise.reject(Object.assign(error, { userMessage: message, errorCode: error.response?.data?.error_code }))
})

const cmd = (prefix) => `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`

export const listCuttingOrders = params => api.get('/v1/erp/production/cutting/orders', { params })
export const getCuttingExecution = (id, params) => api.get(`/v1/erp/production/cutting/orders/${id}/execution`, { params })
export const listCuttingDemands = params => api.get('/v1/erp/production/cutting/demands', { params })
export const getCuttingDemand = (id, params) => api.get(`/v1/erp/production/cutting/demands/${id}`, { params })
export const listCuttingTasks = params => api.get('/v1/erp/production/cutting/tasks', { params })
export const getCuttingTaskExecution = (id, params) => api.get(`/v1/erp/production/cutting/tasks/${id}`, { params })
export const listCuttingInputs = (id, params) => api.get(`/v1/erp/production/cutting/orders/${id}/input-candidates`, { params })
export const listAllowedOutputs = (id, params) => api.get(`/v1/erp/production/cutting/orders/${id}/allowed-outputs`, { params })
export const listSelectorCategories = (id, params) => api.get(`/v1/erp/production/cutting/orders/${id}/selector-categories`, { params })
export const listMaterialPhysicals = params => api.get('/v1/erp/production/cutting/material-physicals', { params })
export const publishCuttingOrder = data => api.post('/v1/erp/production/cutting/orders/publish', { ...data, client_command_id: data.client_command_id || cmd('cut-publish') })
export const closeCuttingOrder = (id, data = {}) => api.post(`/v1/erp/production/cutting/orders/${id}/close`, { ...data, client_command_id: data.client_command_id || cmd('cut-close') })
export const cancelCuttingOrder = (id, data = {}) => api.post(`/v1/erp/production/cutting/orders/${id}/cancel`, { ...data, client_command_id: data.client_command_id || cmd('cut-cancel') })
export const claimCuttingTask = (id, data = {}) => api.post(`/v1/erp/production/cutting/tasks/${id}/claim`, { ...data, client_command_id: data.client_command_id || cmd('cut-claim') })
export const startCuttingTask = (id, data = {}) => api.post(`/v1/erp/production/cutting/tasks/${id}/start`, { ...data, client_command_id: data.client_command_id || cmd('cut-start') })
export const pauseCuttingTask = (id, data = {}) => api.post(`/v1/erp/production/cutting/tasks/${id}/pause`, { ...data, client_command_id: data.client_command_id || cmd('cut-pause') })
export const resumeCuttingTask = (id, data = {}) => api.post(`/v1/erp/production/cutting/tasks/${id}/resume`, { ...data, client_command_id: data.client_command_id || cmd('cut-resume') })
export const finishCuttingTask = (id, data = {}) => api.post(`/v1/erp/production/cutting/tasks/${id}/finish`, { ...data, client_command_id: data.client_command_id || cmd('cut-finish') })
export const saveCuttingSettlement = (id, data) => api.put(`/v1/erp/production/cutting/settlements/${id}/results`, { ...data, client_command_id: data.client_command_id || cmd('cut-save') })
export const submitCuttingSettlement = (id, data = {}) => api.post(`/v1/erp/production/cutting/settlements/${id}/submit`, { ...data, client_command_id: data.client_command_id || cmd('cut-submit') })
export const splitCuttingResultRoutes = (id, data) => api.put(`/v1/erp/production/cutting/results/${id}/routes`, { ...data, client_command_id: data.client_command_id || cmd('cut-split') })
export const listPendingCuttingHandovers = params => api.get('/v1/erp/production/cutting/handovers/pending', { params })
export const dispatchCuttingHandover = (id, data) => api.post(`/v1/erp/production/cutting/routes/${id}/dispatch`, { ...data, client_command_id: data.client_command_id || cmd('cut-dispatch') })
export const acceptCuttingHandover = (id, data = {}) => api.post(`/v1/erp/production/cutting/handovers/${id}/accept`, { ...data, client_command_id: data.client_command_id || cmd('cut-accept') })
export const rejectCuttingHandover = (id, data = {}) => api.post(`/v1/erp/production/cutting/handovers/${id}/reject`, { ...data, client_command_id: data.client_command_id || cmd('cut-reject') })

export default api
