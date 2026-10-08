import axios from 'axios'
import { executeProductionDecision } from './production-assignments'

const api = axios.create({ baseURL: process.env.VUE_APP_BASE_API, timeout: 20000 })
api.interceptors.request.use(config => {
  const token = localStorage.getItem('erp_token')
  if (token) config.headers.Authorization = 'Bearer ' + token
  return config
})
api.interceptors.response.use(response => response, error => {
  const first = Object.values(error.response?.data?.errors || {})[0]
  return Promise.reject(Object.assign(error, {
    userMessage: (Array.isArray(first) ? first[0] : first) || error.response?.data?.message || '工艺档案操作失败',
    errorCode: error.response?.data?.error_code
  }))
})
const catalogPath = type => '/v1/erp/production/' + type

export const listProcessCatalog = (type, params) => api.get(catalogPath(type), { params })
export const createProcessCatalog = (type, payload) => api.post(catalogPath(type), payload)
export const updateProcessCatalog = (type, id, payload) => api.put(catalogPath(type) + '/' + id, payload)
export const saveProcessCatalog = (type, id, payload, creationId) => executeProductionDecision(
  'catalog_' + type + '_' + (id || creationId), payload,
  data => id ? updateProcessCatalog(type, id, data) : createProcessCatalog(type, data)
)
