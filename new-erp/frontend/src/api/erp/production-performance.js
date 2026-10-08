import axios from 'axios'

const api = axios.create({ baseURL: process.env.VUE_APP_BASE_API, timeout: 30000 })
api.interceptors.request.use(config => {
  const token = localStorage.getItem('erp_token')
  if (token) config.headers.Authorization = 'Bearer ' + token
  return config
})
api.interceptors.response.use(response => response, error => Promise.reject(Object.assign(error, {
  userMessage: error.response?.data?.message || '操作失败，请重试原操作',
  errorCode: error.response?.data?.error_code
})))
const base = '/v1/erp/production/performance'
export const listPerformanceOrders = params => api.get(base + '/orders', { params })
export const getPerformanceOrder = (id, params) => api.get(base + '/orders/' + id, { params })
export const listPerformanceOperations = params => api.get(base + '/operations', { params })
export const getPerformanceScope = (type, id, params) => api.get(base + '/scopes/' + type + '/' + id, { params })
export const confirmPerformanceShares = (type, id, data) => api.put(base + '/assignments/' + type + '/' + id, data)
