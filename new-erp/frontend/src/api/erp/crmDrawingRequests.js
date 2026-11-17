import api from './master'

const root = '/v1/erp/crm/drawing-requests'
export const listCrmDrawingRequests = params => api.get(root, { params })
export const getCrmDrawingRequestMetadata = params => api.get(`${root}/metadata`, { params })
export const listCrmDrawingTechnicians = params => api.get(`${root}/technicians`, { params })
export const listCrmDrawingReferences = params => api.get(`${root}/reference-candidates`, { params })
export const getCrmDrawingRequest = id => api.get(`${root}/${id}`)
export const downloadCrmDrawingReference = file => {
  const prefix = '/api/v1/erp/crm/'
  const path = file.download_url?.startsWith(prefix) ? file.download_url.slice(prefix.length) : `attachments/${file.id}/download`
  return api.get(`/v1/erp/crm/${path}`, { responseType: 'blob' })
}
export const saveCrmDrawingRequest = data => data.id ? api.put(`${root}/${data.id}`, data) : api.post(root, data)
export const deleteCrmDrawingRequest = (id, data) => api.delete(`${root}/${id}`, { data })
export const confirmCrmDrawingRequestRevision = (id, revisionId, data) => api.post(`${root}/${id}/revisions/${revisionId}/confirm`, data)
export const returnCrmDrawingRequestRevision = (id, revisionId, data) => api.post(`${root}/${id}/revisions/${revisionId}/return`, data)
export const deliverCrmDrawingRequest = (id, data, files = []) => {
  if (!files.length) return api.post(`${root}/${id}/deliver`, data)
  const form = new FormData()
  Object.entries(data).forEach(([key, value]) => {
    if (value === null || value === undefined) return
    if (key === 'revision_files') form.append(key, JSON.stringify(value))
    else if (Array.isArray(value)) value.forEach(item => form.append(`${key}[]`, item))
    else form.append(key, value)
  })
  files.forEach(file => form.append('files[]', file))
  return api.post(`${root}/${id}/deliver`, form, { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 120000 })
}
