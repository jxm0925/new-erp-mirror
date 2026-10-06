import api from './master'

const orderPath = id => `/v1/erp/sales/orders/${id}/finance`
export const getSalesOrderFinance = id => api.get(orderPath(id))
export const listSalesPurchaseLinks = (id, params) => api.get(`${orderPath(id)}/purchase-links`, { params })
export const listSalesPurchaseCandidates = (id, params) => api.get(`${orderPath(id)}/purchase-candidates`, { params })
export const listSalesPurchaseCategories = id => api.get(`${orderPath(id)}/purchase-categories`)
export const addSalesPurchaseLink = (id, data) => api.post(`${orderPath(id)}/purchase-links`, data)
export const reverseSalesPurchaseLink = (id, linkId, data) => api.post(`${orderPath(id)}/purchase-links/${linkId}/reverse`, data)
export const listSalesOrderFinanceStatistics = params => api.get('/v1/erp/finance/sales-order-statistics', { params })
