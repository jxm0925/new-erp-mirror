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
export const getCrmOpportunityMetadata = () => api.get('/v1/erp/crm/opportunities/metadata')
export const listCrmOpportunities = params => api.get('/v1/erp/crm/opportunities', { params })
export const listCrmOpportunityOwners = params => api.get('/v1/erp/crm/opportunities/owners', { params })
export const getCrmOpportunity = id => api.get(`/v1/erp/crm/opportunities/${id}`)
export const saveCrmOpportunity = data => data.id ? api.put(`/v1/erp/crm/opportunities/${data.id}`, data) : api.post('/v1/erp/crm/opportunities', data)
export const actCrmOpportunity = (id, action, data) => api.post(`/v1/erp/crm/opportunities/${id}/${action}`, data)
export const listCrmOpportunityFiles = (id, params) => api.get(`/v1/erp/crm/opportunities/${id}/files`, { params })
export const saveCrmOpportunityFile = (id, data) => api.post(`/v1/erp/crm/opportunities/${id}/files`, data)
export const confirmCrmOpportunityFile = (id, fileId, data) => api.post(`/v1/erp/crm/opportunities/${id}/files/${fileId}/confirm`, data)
export const listCrmOpportunityLogs = (id, params) => api.get(`/v1/erp/crm/opportunities/${id}/logs`, { params })
export const listCrmOpportunityOrders = (id, params) => api.get(`/v1/erp/crm/opportunities/${id}/orders`, { params })
export const getCrmOpportunityOrderContext = id => api.get(`/v1/erp/crm/opportunities/${id}/order-context`)
export const listCustomerOrderAmounts = (id, params) => api.get(`/v1/erp/sales/customers/${id}/order-amounts`, { params })
export const getCrmWorkbench = () => api.get('/v1/erp/crm/workbench')
export const listCrmWorkbenchTasks = params => api.get('/v1/erp/crm/workbench/tasks', { params })
export const listCrmWorkbenchPriorities = params => api.get('/v1/erp/crm/workbench/priorities', { params })
export const getCrmWorkbenchResults = () => api.get('/v1/erp/crm/workbench/results')
export const listCrmWorkbenchBusiness = params => api.get('/v1/erp/crm/workbench/business', { params })
export const listCustomerReceipts = (id, params) => api.get(`/v1/erp/sales/customers/${id}/receipts`, { params })
export const listCustomerShipments = (id, params) => api.get(`/v1/erp/sales/customers/${id}/shipments`, { params })
export const listCustomerAftersales = (id, params) => api.get(`/v1/erp/sales/customers/${id}/aftersales`, { params })
export const listCustomerOrderDelivery = (id, orderId, params) => api.get(`/v1/erp/sales/customers/${id}/orders/${orderId}/delivery`, { params })

export const crmError = (error, fallback) => {
  const data = error.response && error.response.data
  const errors = data && data.errors
  return (errors && Object.values(errors).flat()[0]) || (data && data.message) || fallback
}
export const crmDate = value => erpDateTime(value, 16) || '—'
