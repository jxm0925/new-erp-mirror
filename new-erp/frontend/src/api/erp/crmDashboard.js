import api from './master'

const base = '/v1/erp/crm/dashboard'
// Laravel 的 boolean 查询校验接受 1/0；Axios 默认会把布尔值编码成 true/false 字符串。
const get = (path, params) => api.get(`${base}/${path}`, { params: Object.fromEntries(Object.entries(params || {}).filter(([,value]) => value !== undefined).map(([key,value]) => [key,typeof value === 'boolean' ? Number(value) : value])) })
export const getDashboardMetadata = () => api.get(`${base}/metadata`)
export const listDashboardSalesOptions = params => get('sales-options', params)
export const getDashboardOverview = params => get('overview', params)
export const listDashboardSales = params => get('sales', params)
export const listDashboardDimensions = params => get('dimensions', params)
export const listDashboardSources = params => get('sources', params)
export const getDashboardPipeline = params => get('pipeline', params)
export const getDashboardFollowups = params => get('followups', params)
export const getDashboardIncome = params => get('income', params)
export const listDashboardOrders = params => get('orders', params)
export const listDashboardDrilldown = params => get('drilldown', params)
