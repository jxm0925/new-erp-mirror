import api from './master'

export const listBoms = params => api.get('/v1/erp/bom/boms', { params })
export const getBom = id => api.get(`/v1/erp/bom/boms/${id}`)
export const saveBom = data => data.id ? api.put(`/v1/erp/bom/boms/${data.id}`, data) : api.post('/v1/erp/bom/boms', data)
export const submitBom = id => api.post(`/v1/erp/bom/boms/${id}/submit`)
export const approveBom = id => api.post(`/v1/erp/bom/boms/${id}/approve`)
export const rejectBom = id => api.post(`/v1/erp/bom/boms/${id}/reject`)
export const activateBom = id => api.post(`/v1/erp/bom/boms/${id}/activate`)
export const deactivateBom = id => api.post(`/v1/erp/bom/boms/${id}/deactivate`)
export const setDefaultBom = id => api.post(`/v1/erp/bom/boms/${id}/set-default`)
export const copyBomVersion = (id, data = {}) => api.post(`/v1/erp/bom/boms/${id}/copy-version`, data)
export const expandBom = data => api.post('/v1/erp/bom/expand', data)
