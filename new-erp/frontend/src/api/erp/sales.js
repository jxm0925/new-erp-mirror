import api from './master'

export const listSalesOrders = params => api.get('/v1/erp/sales/orders', { params })
export const getSalesOrder = (id, params) => api.get(`/v1/erp/sales/orders/${id}`, { params })
export const saveSalesOrder = data => data.id
  ? api.put(`/v1/erp/sales/orders/${data.id}`, data)
  : api.post('/v1/erp/sales/orders', data)
// 确认前检查未通过时服务端返回 422 + 逐项检查结果；这里按正常结果交给页面展示阻塞项。
export const confirmSalesOrder = id => api.post(`/v1/erp/sales/orders/${id}/confirm`).catch(error => {
  if (error.response && error.response.status === 422 && error.response.data && error.response.data.data && Array.isArray(error.response.data.data.checks)) return error.response
  throw error
})
export const formalConfirmSalesOrder = id => api.post(`/v1/erp/sales/orders/${id}/formal-confirm`)
export const cancelSalesOrder = (id, data) => api.post(`/v1/erp/sales/orders/${id}/cancel`, data)
// 短交关闭：剩余未发不再发，应收改为实发金额 + 运费。
export const previewShortCloseSalesOrder = id => api.get(`/v1/erp/sales/orders/${id}/short-close-preview`)
export const shortCloseSalesOrder = (id, data) => api.post(`/v1/erp/sales/orders/${id}/short-close`, data)
export const listSalesCustomers = params => api.get('/v1/erp/sales/customers', { params })
export const getSalesCustomer = id => api.get(`/v1/erp/sales/customers/${id}`)
export const createSalesCustomer = data => api.post('/v1/erp/sales/customers', data)
export const updateSalesCustomer = (id, data) => api.put(`/v1/erp/sales/customers/${id}`, data)
export const uploadSalesOrderAttachment = form => api.post('/v1/erp/sales/orders/attachments/upload', form, { headers: { 'Content-Type': 'multipart/form-data' } })
export const deleteSalesOrderAttachment = id => api.delete(`/v1/erp/sales/orders/attachments/${id}`)
export const previewSalesOrderAttachment = id => api.get(`/v1/erp/sales/orders/attachments/${id}/preview`, { responseType: 'blob' })
export const downloadSalesOrderAttachment = id => api.get(`/v1/erp/sales/orders/attachments/${id}/download`, { responseType: 'blob' })
export const deleteSalesOrderDraft = id => api.delete(`/v1/erp/sales/orders/${id}`)
export const listSalesOrderLogs = (id, params) => api.get(`/v1/erp/sales/orders/${id}/logs`, { params })
export const listSalesOrderVersions = (id, params) => api.get(`/v1/erp/sales/orders/${id}/versions`, { params })
export const listSalesOrderChanges = (id, params) => api.get(`/v1/erp/sales/orders/${id}/changes`, { params })
export const searchSalesOrderSkus = params => api.get('/v1/erp/sales/orders/skus/search', { params })
export const previewSalesOrderEditImpact = (id, data) => api.post(`/v1/erp/sales/orders/${id}/edit-impact/preview`, data)
export const submitSalesOrderEditImpact = (id, data) => api.post(`/v1/erp/sales/orders/${id}/edit-impact/submit`, data)
// 销售按客户约定修改开工定金；草稿和已确认订单均立即生效并留痕。
export const updateSalesOrderAgreedDeposit = (id, data) => api.put(`/v1/erp/sales/orders/${id}/agreed-deposit`, data)
export const listSalesOrderChangeCandidates = (id, params) => api.get(`/v1/erp/sales/orders/${id}/change-candidates`, { params })
export const decideSalesOrderChangeCandidate = (id, candidateId, data) => api.post(`/v1/erp/sales/orders/${id}/change-candidates/${candidateId}/decision`, data)
// 销售订单详情后续接入退货记录时使用；服务端按订单数据权限分页返回。
export const listSalesOrderReturns = (id, params) => api.get(`/v1/erp/sales/orders/${id}/returns`, { params })
export const getSalesOrderContract = () => api.get('/v1/erp/sales/orders/interface-contract')
export const getSalesOrderOptions = params => api.get('/v1/erp/sales/orders/options', { params })
export const getProductionPreview = id => api.get(`/v1/erp/sales/orders/${id}/production-confirmation-preview`)
export const confirmProduction = (id, data) => api.post(`/v1/erp/sales/orders/${id}/production-confirmation`, data)
export const lockSalesOrderInventory = (id, data) => api.post(`/v1/erp/sales/orders/${id}/lock-inventory`, data)

export const listSalesReturns = params => api.get('/v1/erp/sales/returns', { params })
export const listSalesReturnSources = params => api.get('/v1/erp/sales/returns/sources', { params })
export const getSalesReturn = id => api.get(`/v1/erp/sales/returns/${id}`)
export const createSalesReturn = data => api.post('/v1/erp/sales/returns', data)
export const confirmSalesReturn = id => api.post(`/v1/erp/sales/returns/${id}/confirm`)
export const receiveSalesReturn = (id, data) => api.post(`/v1/erp/sales/returns/${id}/receive`, data)
export const postSalesReturnReceipt = (id, receiptId) => api.post(`/v1/erp/sales/returns/${id}/receipts/${receiptId}/post`)
export const listSalesReturnSerialCandidates = (id, params) => api.get(`/v1/erp/sales/returns/${id}/serial-candidates`, { params })
export const listSalesReturnReceiptSerials = (id, receiptId, params) => api.get(`/v1/erp/sales/returns/${id}/receipts/${receiptId}/serials`, { params })
export const disposeSalesReturn = (id, data) => api.post(`/v1/erp/sales/returns/${id}/dispose`, data)
export const cancelSalesReturn = id => api.post(`/v1/erp/sales/returns/${id}/cancel`)
export const closeSalesReturn = id => api.post(`/v1/erp/sales/returns/${id}/close`)
