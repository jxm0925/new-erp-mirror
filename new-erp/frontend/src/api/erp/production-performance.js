import { createErpApi } from './http'

const api = createErpApi({ timeout: 30000, fallbackMessage: '操作失败，请重试原操作' })
const base = '/v1/erp/production/performance'
export const listPerformanceOrders = params => api.get(base + '/orders', { params })
export const getPerformanceOrder = (id, params) => api.get(base + '/orders/' + id, { params })
export const listPerformanceOperations = params => api.get(base + '/operations', { params })
export const getPerformanceScope = (type, id, params) => api.get(base + '/scopes/' + type + '/' + id, { params })
export const confirmPerformanceShares = (type, id, data) => api.put(base + '/assignments/' + type + '/' + id, data)
