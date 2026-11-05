import api from './master'

// 售后单：列表/详情走 aftersales 前缀；订单级列表与可售后行挂在 sales/orders 下。
export const listAftersalesCases = params => api.get('/v1/erp/aftersales/cases', { params })
export const getAftersalesCase = id => api.get(`/v1/erp/aftersales/cases/${id}`)
export const createAftersalesCase = data => api.post('/v1/erp/aftersales/cases', data)
export const updateAftersalesCase = (id, data) => api.put(`/v1/erp/aftersales/cases/${id}`, data)
export const acceptAftersalesCase = id => api.post(`/v1/erp/aftersales/cases/${id}/accept`)
export const cancelAftersalesCase = (id, data) => api.post(`/v1/erp/aftersales/cases/${id}/cancel`, data)
export const closeAftersalesCase = (id, data) => api.post(`/v1/erp/aftersales/cases/${id}/close`, data)

export const addAftersalesAction = (id, data) => api.post(`/v1/erp/aftersales/cases/${id}/actions`, data)
export const cancelAftersalesAction = (id, actionId, data) => api.post(`/v1/erp/aftersales/cases/${id}/actions/${actionId}/cancel`, data)

export const createAftersalesClaim = (id, data) => api.post(`/v1/erp/aftersales/cases/${id}/claims`, data)
export const updateAftersalesClaim = (id, claimId, data) => api.put(`/v1/erp/aftersales/cases/${id}/claims/${claimId}`, data)

export const getAftersalesEligible = orderId => api.get(`/v1/erp/aftersales/orders/${orderId}/eligible`)
export const listOrderAftersales = orderId => api.get(`/v1/erp/sales/orders/${orderId}/aftersales`)

export const uploadAftersalesAttachment = (file, params = {}) => {
  const form = new FormData()
  form.append('file', file)
  Object.entries(params).forEach(([k, v]) => { if (v != null && v !== '') form.append(k, v) })
  return api.post('/v1/erp/aftersales/attachments/upload', form, { headers: { 'Content-Type': 'multipart/form-data' } })
}
export const deleteAftersalesAttachment = id => api.delete(`/v1/erp/aftersales/attachments/${id}`)
export const previewAftersalesAttachment = id => api.get(`/v1/erp/aftersales/attachments/${id}/preview`, { responseType: 'blob' })
export const downloadAftersalesAttachment = id => api.get(`/v1/erp/aftersales/attachments/${id}/download`, { responseType: 'blob' })

export const getReshipStockOptions = (reshipId, params) => api.get(`/v1/erp/aftersales/reships/${reshipId}/stock-options`, { params })
export const shipReship = (reshipId, payload) => api.post(`/v1/erp/aftersales/reships/${reshipId}/ship`, payload)
