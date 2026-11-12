import api from './master'

const root = '/v1/erp/production/technical-drawings'
export const getTechnicalDrawingMetadata = () => api.get(`${root}/metadata`)
export const listTechnicalDrawingEmployees = params => api.get(`${root}/employees`, { params })
export const listTechnicalDrawingCandidates = params => api.get(`${root}/candidates`, { params })
export const listTechnicalDrawingRevisions = (id, params) => api.get(`${root}/${id}/revisions`, { params })
export const listTechnicalDrawingPeople = params => api.get(`${root}/people`, { params })
export const listTechnicalDrawings = params => api.get(root, { params })
export const listTechnicalDrawingSources = params => api.get(`${root}/sources`, { params })
export const getTechnicalDrawing = (id, params) => api.get(`${root}/${id}`, { params })
export const confirmTechnicalDrawing = (id, data) => api.post(`${root}/revisions/${id}/confirm`, data)
export const downloadTechnicalDrawing = id => api.get(`${root}/revisions/${id}/download`, { responseType: 'blob' })
export const registerTechnicalDrawing = (data, file) => {
  if (!file) return api.post(root, data)
  const form = new FormData()
  Object.entries(data).forEach(([key, value]) => {
    if (value === null || value === undefined) return
    if (key === 'source') Object.entries(value).forEach(([field, item]) => { if (item !== null && item !== undefined) form.append(`source[${field}]`, item) })
    else form.append(key, value)
  })
  form.append('file', file)
  return api.post(root, form, { headers: { 'Content-Type': 'multipart/form-data' } })
}
export const drawingError = (error, fallback) => {
  const data = error.response && error.response.data
  return (data && data.errors && Object.values(data.errors).flat()[0]) || (data && data.message) || error.userMessage || fallback
}
