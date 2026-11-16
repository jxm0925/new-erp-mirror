import api from './master'

const root = '/v1/erp/crm/drawing-requests'
export const listCrmDrawingRequests = params => api.get(root, { params })
export const getCrmDrawingRequestMetadata = params => api.get(`${root}/metadata`, { params })
export const listCrmDrawingTechnicians = params => api.get(`${root}/technicians`, { params })
export const listCrmDrawingReferences = params => api.get(`${root}/reference-candidates`, { params })
export const getCrmDrawingRequest = id => api.get(`${root}/${id}`)
export const downloadCrmDrawingReference = (id, attachmentId) => api.get(`${root}/${id}/references/${attachmentId}/download`, { responseType: 'blob' })
export const saveCrmDrawingRequest = data => data.id ? api.put(`${root}/${data.id}`, data) : api.post(root, data)
export const confirmCrmDrawingRequestRevision = (id, revisionId, data) => api.post(`${root}/${id}/revisions/${revisionId}/confirm`, data)
export const deliverCrmDrawingRequest = (id, data, file) => {
  const form = new FormData()
  Object.entries(data).forEach(([key, value]) => { if (value !== null && value !== undefined) form.append(key, value) })
  if (file) form.append('file', file)
  return api.post(`${root}/${id}/deliver`, form, { headers: { 'Content-Type': 'multipart/form-data' } })
}
