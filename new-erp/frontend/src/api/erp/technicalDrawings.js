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
export const downloadTechnicalDrawing = (id, params) => api.get(`${root}/revisions/${id}/download`, { params, responseType: 'blob' })
export const previewTechnicalDrawing = (id, params) => api.get(`${root}/revisions/${id}/preview`, { params, responseType: 'blob' })
export const registerTechnicalDrawing = (data, files = []) => {
  if (!files.length) return api.post(root, data)
  const form = new FormData()
  Object.entries(data).forEach(([key, value]) => {
    if (value === null || value === undefined) return
    if (key === 'source') Object.entries(value).forEach(([field, item]) => { if (item !== null && item !== undefined) form.append(`source[${field}]`, item) })
    else if (key === 'revision_files') form.append(key, JSON.stringify(value))
    else if (Array.isArray(value)) value.forEach(item => form.append(`${key}[]`, item))
    else form.append(key, value)
  })
  files.forEach(file => form.append('files[]', file))
  return api.post(root, form, { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 120000 })
}
export const drawingError = (error, fallback) => {
  if (error.response && error.response.status === 413) return '整套图纸文件超过服务器上传容量，请压缩文件后重试。'
  const data = error.response && error.response.data
  return (data && data.errors && Object.values(data.errors).flat()[0]) || (data && data.message) || error.userMessage || fallback
}
