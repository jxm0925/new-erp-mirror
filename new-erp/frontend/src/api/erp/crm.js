import api from './master'
import { erpDateTime } from '@/utils/dateTime'

export const getCrmLeadMetadata = () => api.get('/v1/erp/crm/leads/metadata')
export const listCrmLeads = params => api.get('/v1/erp/crm/leads', { params })
export const getCrmLead = id => api.get(`/v1/erp/crm/leads/${id}`)
export const saveCrmLead = data => data.id ? api.put(`/v1/erp/crm/leads/${data.id}`, data) : api.post('/v1/erp/crm/leads', data)
export const actCrmLead = (id, action, data) => api.post(`/v1/erp/crm/leads/${id}/${action}`, data)
export const listCrmLeadLogs = (id, params) => api.get(`/v1/erp/crm/leads/${id}/logs`, { params })
export const listCrmLeadAssignees = (id, params) => api.get(`/v1/erp/crm/leads/${id}/assignees`, { params })
export const listCrmFollowups = params => api.get('/v1/erp/crm/followups', { params })
export const saveCrmFollowup = data => data.id ? api.put(`/v1/erp/crm/followups/${data.id}`, data) : api.post('/v1/erp/crm/followups', data)
export const uploadCrmAttachment = form => api.post('/v1/erp/crm/attachments', form, { headers: { 'Content-Type': 'multipart/form-data' } })
export const downloadCrmAttachment = id => api.get(`/v1/erp/crm/attachments/${id}/download`, { responseType: 'blob' })
export const getCrmTeamMetadata = () => api.get('/v1/erp/crm/team/metadata')
export const listCrmTeamStaff = params => api.get('/v1/erp/crm/team/staff', { params })
export const getCrmTeamStaff = id => api.get(`/v1/erp/crm/team/staff/${id}`)
export const saveCrmStaffCapability = (id, data) => api.put(`/v1/erp/crm/team/staff/${id}/capability`, data)
export const listCrmGrants = params => api.get('/v1/erp/crm/team/grants', { params })
export const saveCrmGrant = data => data.id ? api.put(`/v1/erp/crm/team/grants/${data.id}`, data) : api.post('/v1/erp/crm/team/grants', data)
export const listCrmTeamUsers = params => api.get('/v1/erp/crm/team/users', { params })
export const listCrmDuplicateReviews = params => api.get('/v1/erp/crm/duplicate-reviews', { params })
export const getCrmDuplicateReview = id => api.get(`/v1/erp/crm/duplicate-reviews/${id}`)
export const createCrmDuplicateReview = data => api.post('/v1/erp/crm/duplicate-reviews', data)
export const resolveCrmDuplicateReview = (id, data) => api.post(`/v1/erp/crm/duplicate-reviews/${id}/resolve`, data)

export const crmError = (error, fallback) => {
  const data = error.response && error.response.data
  const errors = data && data.errors
  return (errors && Object.values(errors).flat()[0]) || (data && data.message) || fallback
}
export const crmDate = value => erpDateTime(value, 16) || '—'
