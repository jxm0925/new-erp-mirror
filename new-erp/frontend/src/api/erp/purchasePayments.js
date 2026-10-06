import api from './master'

export const getPurchasePaymentPlan = orderId => api.get(`/v1/erp/purchase/orders/${orderId}/payment-plan`)
export const savePurchasePaymentPlan = (orderId, data) => api.put(`/v1/erp/purchase/orders/${orderId}/payment-plan`, data)
export const listPurchasePaymentOrders = params => api.get('/v1/erp/purchase/payment-orders', { params })
export const listPurchasePaymentStatistics = params => api.get('/v1/erp/finance/purchase-payment-statistics', { params })
export const updateCashPurchaseOrders = (cashId, data) => api.put(`/v1/erp/finance/cash-documents/${cashId}/purchase-orders`, data)
