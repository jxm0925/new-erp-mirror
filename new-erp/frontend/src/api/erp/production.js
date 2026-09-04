import axios from 'axios'

const api = axios.create({ baseURL: process.env.VUE_APP_BASE_API, timeout: 20000 })
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

export const listProductionDemands = params => api.get('/v1/erp/production/demands', { params })
export const getProductionDemand = (id, params) => api.get(`/v1/erp/production/demands/${id}`, { params })
export const listWorkOrders = params => api.get('/v1/erp/production/work-orders', { params })
export const getWorkOrder = id => api.get(`/v1/erp/production/work-orders/${id}`)
export const createWorkOrderDraft = data => api.post('/v1/erp/production/work-orders', data)
export const updateWorkOrderDraft = (id, data) => api.put(`/v1/erp/production/work-orders/${id}`, data)
export const submitWorkOrder = (id, data) => api.post(`/v1/erp/production/work-orders/${id}/submit`, data)
export const getWorkOrderReleaseGate = id => api.get(`/v1/erp/production/work-orders/${id}/release-gate`)
export const publishWorkOrder = (id, data) => api.post(`/v1/erp/production/work-orders/${id}/publish`, data)
export const listWorkOrderMaterialRequirements = (id, params) => api.get(`/v1/erp/production/work-orders/${id}/material-requirements`, { params })
export const returnWorkOrderToDraft = (id, data) => api.post(`/v1/erp/production/work-orders/${id}/return-draft`, data)
export const cancelWorkOrder = (id, data) => api.post(`/v1/erp/production/work-orders/${id}/cancel`, data)
export const rematchWorkOrderRouting = (id, data) => api.post(`/v1/erp/production/work-orders/${id}/rematch-routing`, data)
export const reserveProductionNumber = (documentType, creationSessionId, page) => api.post('/v1/erp/document-numbers/reserve', { document_type: documentType, creation_session_id: creationSessionId, page })
export const searchProductionOptions = (type, params) => api.get(`/v1/erp/production/select-options/${type}`, { params })
export const listProductionOperations = params => api.get('/v1/erp/production/operations', { params })
export const getProductionOperation = id => api.get(`/v1/erp/production/operations/${id}`)
export const createProductionOperation = data => api.post('/v1/erp/production/operations', data)
export const updateProductionOperation = (id, data) => api.put(`/v1/erp/production/operations/${id}`, data)
export const enableProductionOperation = (id, data) => api.post(`/v1/erp/production/operations/${id}/enable`, data)
export const disableProductionOperation = (id, data) => api.post(`/v1/erp/production/operations/${id}/disable`, data)
export const listProductionRoutings = params => api.get('/v1/erp/production/routings', { params })
export const getProductionRouting = id => api.get(`/v1/erp/production/routings/${id}`)
export const createProductionRouting = data => api.post('/v1/erp/production/routings', data)
export const updateProductionRouting = (id, data) => api.put(`/v1/erp/production/routings/${id}`, data)
export const activateProductionRouting = (id, data) => api.post(`/v1/erp/production/routings/${id}/activate`, data)
export const setDefaultProductionRouting = (id, data) => api.post(`/v1/erp/production/routings/${id}/set-default`, data)
export const copyProductionRouting = (id, data) => api.post(`/v1/erp/production/routings/${id}/copy-version`, data)
export const retireProductionRouting = (id, data) => api.post(`/v1/erp/production/routings/${id}/retire`, data)

export default api
